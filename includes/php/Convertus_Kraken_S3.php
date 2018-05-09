<?php 
include_once( dirname( __FILE__ ) . '/Kraken.php' );
include_once( '../../config.php' );

class Convertus_Kraken_S3 {
	
	public $output;
	private $media_entries;
	private $obj;
	private $kraken;

	function __construct( $obj ) {
		$this->obj = $obj;
		$this->media_entries = array();
		$this->output = array();
		$this->kraken = new Kraken( KRAKEN_ACCESS_KEY_ID, KRAKEN_SECRET_ACCESS_KEY );
	}

	public function update_images( $results, $type ) {
		if ( $type == 'view' ) {
			foreach ( $results as $media ) {
				$media['storage_path'] = 'media/' . strrev( $media['style_id'] ) . '/view/' . $media['file_name'];
				$this->media_entries[] = $media;
			}
		} else {
			foreach ( $results as $media ) {
				self::update_colorized_images( $media );
			}
		}

		$requests = self::get_kraken_requests();
		if ( empty( $requests ) ) { return FALSE; }
		$responses = $this->kraken->multiple_requests( $requests );
		self::update_media_db( $responses );
		return TRUSE;
	}

	/**
	 * This function grabs and download all 1280 images from the url field in the media table.
	 * Additionally, each image is cropped and uploaded to s3 via the kraken API.
	 *
	 * @param array $media
	 * 
	 * @return null
	 */
	private function update_colorized_images( $media ) {

		$style_id = strrev( $media['style_id'] );
		if ( ! file_exists( '../../temp/media/' . $style_id ) ) {
			self::copy_media_style_folder( $media );
		}
		$media['storage_path'] = 'media/' . strrev( $media['style_id'] ) . '/01/';
		$media['type'] = 'colorized';
		// Iterate the directory and add to media_entries for optimization and storage
		$dir = new DirectoryIterator('../../temp/media/' . $style_id . '/01' );
		foreach ( $dir as $image ) {
			if ( $image->isDot() ) { continue; }
			$image_info = pathinfo( $image );
			$color_code = explode( '_', $image_info['filename'] );
			$color_code = end( $color_code );
			$copy = $media;
			$copy['color_option_code'] = $color_code;
			$copy['file_name'] .= '_' . $color_code;
			$copy['storage_path'] .= $copy['file_name'];
			$copy['url'] = SITE_URL . '/test/media/' . $style_id . '/01/' . $copy['file_name']  . '.png';
			$this->media_entries[] = $copy;
		}
	}

	/** This function is meant for media records with a shot_code value of 1 and 
	 * downloads all the colorized images via the chromedata ftp
	 *
	 * @param array $media The array containing the shot_code 1 media object
	 * 
	 * @return null
	 */
	private function copy_media_style_folder( $media ) {
		$style_id = strrev( $media['style_id'] );
		mkdir( '../../temp/media/' . $style_id, 0777, true );
		mkdir( '../../temp/media/' . $style_id . '/01', 0777, true );

		$ftp_server = 'ftp.chromedata.com';
		$ftp_user = 'u311191';
		$ftp_pass = 'con191';

		// 1) Connect to ftp or die
		$conn_id = ftp_connect($ftp_server) or set_error( 'FTP', "Couldn't connect to $ftp_server" ); 

		// 2) Try to connect to ftp with the credentials above
		if ( ! @ftp_login( $conn_id, $ftp_user, $ftp_pass ) ) {
			set_error( 'FTP', "Couldn't connect as $ftp_user" );
			echo 'Caught an error connecting to ftp';
			return;
		}
		// 3) Passive mode to iterate directory and download images
		ftp_pasv( $conn_id, true );

		$folder = 'cc_' . str_replace( '_1280_01', '_01_1280', $media['file_name'] );
		$contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $folder . '/' );

		// 4) Foreach color variation
		foreach ( $contents as $image ) {
			$image_info = pathinfo( $image );
			$color_code = explode( '_', $image_info['filename'] );
			$color_code = end( $color_code );
			$local_path = '../../temp/media/' . $style_id . '/01/' . $media['file_name'] . '_' . $color_code . '.png';
			if ( ! ftp_get( $conn_id, $local_path, $image, FTP_BINARY ) ) {
				var_dump( 'Something went wrong when downloading images for style id ' . $media['style_id'] ); exit(); // Exit if error caught
			}
		}
	}

	/** This function goes through all the object's media entries, and
	 * grabs the paramaters needed to pass to the kraken api.
	 *
	 * @param none
	 * 
	 * @return null
	 */
	private function get_kraken_requests( $big = true ) {
		$requests = array();
		foreach ( $this->media_entries as $media ) {
			
			$sql = "SELECT COUNT(color_option_code) FROM media WHERE 
			style_id LIKE '{$media['style_id']}' AND
			type LIKE '{$media['type']}' AND
			shot_code LIKE '{$media['shot_code']}' AND
			file_name LIKE '{$media['file_name']}' AND
			color_option_code LIKE '{$media['color_option_code']}' AND
			url LIKE '%amazonaws%' AND 
			url LIKE '%.jpg%'";

			$jpg = $this->obj->db->get_var( $sql );
			if ( $jpg != IMAGES_PER_REQUEST ) {
				// jpg Images
				$params = self::get_params( $media, '.jpg' );
				$requests = array_merge( $requests, $params );
			}
			
			$png = $this->obj->db->get_var( str_replace( '.jpg', '.png', $sql ) );
			if ( $png == IMAGES_PER_REQUEST ) { continue; }
			// png images
			$params = self::get_params( $media, '.png' );
			$requests = array_merge( $requests, $params );
		}
		return $requests;
	}

	/**
	 * This function generates the arguments necessary for the kraken api
	 * based on the media object passed in and the type of file to be converted
	 *
	 * @param array, string $media is the media attached to the request and $params is used to build the params 
	 * helps determine the arguments needed for the kraken api
	 * 
	 * @return array An array of paramaters corresponding to the media passed in
	 */
	function get_params( $media, $type ) {
		$storage_path = $media['storage_path'];

		$params = array(
			"url"					=> $media['url'],
			"media"				=> $media,
			"wait"		 		=> true,
			"auto_orient"	=> true,
			"s3_store" 	=> array(
				"key" 		=> AWS_ACCESS_KEY_ID,
				"secret" 	=> AWS_SECRET_ACCESS_KEY,
				"bucket" 	=> AWS_BUCKET,
				"region" 	=> AWS_REGION,
				"headers" => array(
					"Cache-Control" => "max-age=2592000000",
				),
		));
		// Add arguments specific to jpg
		if ( $type == '.jpg' ) {
			$params['lossy'] = true;
			$params['convert'] = array(
				'format'			=> 'jpeg',
				'background'	=> '#ffffff'
			);
		}

		$lgmd = array(
			array(
				"id"						=> "lg",
				"width" 				=> 1280,
				"height" 				=> 960,
				"strategy" 			=> "auto",
				"storage_path" 	=> $storage_path . "_lg" . $type,
			),
			array(
				"id"						=> "md",
				"width" 				=> 1024,
				"height" 				=> 768,
				"strategy" 			=> "auto",
				"storage_path"	=> $storage_path . "_md" . $type,
			)
		);
		$smxs = array(
			array(
				"id"						=> "sm",
				"width" 				=> 640,
				"height" 				=> 480,
				"strategy" 			=> "auto",
				"enhance" 			=> true,
				"storage_path" 	=> $storage_path . "_sm" . $type,
			),
			array(
				"id"						=> "xs", 
				"width" 				=> 320,
				"height" 				=> 240,
				"strategy" 			=> "auto",
				"enhance"			 	=> true,
				"storage_path" 	=> $storage_path . "_xs" . $type,
			)
		);

		if ( array_key_exists( 'breakdown', $params['media'] ) ) {
			unset( $params['media']['breakdown'] );
			$copy = $params;
			$copy["resize"] = $lgmd;
			$params["resize"] = $smxs;
			
			return array( $copy, $params );
		}
		
		$params["resize"]	= array_merge( $lgmd, $smxs );
		return array( $params );
	}

		/**
	 * This function takes all the successfully optimized and stored media objects, and updates
	 * the database media table with the s3 references
	 *
	 * @param none
	 * 
	 * @return null
	 */
	private function update_media_db( $responses ) {

		foreach ( $responses as $data ) {
			$media = $data['media'];
			$results = $data['response']['results'];
			
			// Grab first key ( lg, md, sm, xs ) for extension
			foreach ( $results as $key => $value ) {
				$extension = substr( $results[$key]['kraked_url'], -4 );
				break;
			}
			$remove_sql = "DELETE FROM media WHERE 
			style_id LIKE '{$media['style_id']}' AND
			type LIKE '{$media['type']}' AND
			shot_code LIKE '{$media['shot_code']}' AND
			file_name LIKE '{$media['file_name']}' AND
			color_option_code LIKE '{$media['color_option_code']}' AND
			url LIKE '%{$extension}%'
			";

			// 1) Remove all amazonaws entries for corresponding request
			$this->obj->db->query( $remove_sql . " AND url LIKE '%amazonaws%'" );

			// 2) SQL Insert
			if ( $media['type'] == 'view' ) {
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, file_name, created ) VALUES ";
			} elseif ( $media['type'] == 'colorized' )  { 
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, file_name, created ) VALUES ";
			}

			// 3) SQL Values. Figure out color name?
			$values = array();
			foreach ( $results as $result ) {
				$background = 'Transparent';
				if ( strpos( $result['kraked_url'], '.jpg' ) !== false ) { $background = 'White'; }
				if ( $media['type'] == 'view' )  {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '{$media['file_name']}', now() )";
				} elseif ( $media['type'] == 'colorized' ) {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '', '{$media['color_option_code']}', '', '{$media['file_name']}', now())";
				}
			}
			// 4) SQL query
			// display_var( $values );
			$this->obj->db->query( $sql . implode( ',', $values ) );

			// 5) Check to remove chrome data entries
			if ( self::is_updated( $media ) ) {
				$remove_sql = "DELETE FROM media WHERE 
				style_id LIKE '{$media['style_id']}' AND
				type LIKE 'view' AND
				shot_code LIKE '{$media['shot_code']}' AND
				url LIKE '%media.chromedata%'
				";
				$this->obj->db->query( $remove_sql );
			}
		}
	}

	/**
	 * This function checks to see if the media passed in has the correct amount of
	 * db media entries before removing the referenced chromedata entry
	 * 
	 * @param array $media is the media object being tested
	 * 
	 * @return boolean true or false based on the media entry having the correct amount
	 * of s3 records in the db
	 */
	private function is_updated( $media ) {
		$pass = TRUSE;

		$sql = "SELECT count(style_id) FROM media WHERE
		style_id LIKE '{$media['style_id']}' AND
		shot_code LIKE '{$media['shot_code']}' AND
		url LIKE '%amazonaws%' AND
		type LIKE 'view'";
		$updated = $this->obj->db->get_var( $sql );
		// 4sizes ( lg md sm xs ) x 2 types( jpg png ) = 8 images per view
		if ( $updated != IMAGES_PER_REQUEST * 2 ) { $pass = FALSE; }
		
		// Only shot code 1 has to do the next check
		if ( $media['shot_code'] !== '1' ) { return $pass; }
		$sql = "SELECT count(style_id) FROM media WHERE 
		style_id LIKE '{$media['style_id']}' AND
		shot_code LIKE '{$media['shot_code']}' AND
		url LIKE '%amazonaws%' AND
		type LIKE 'colorized'";
		$updated = $this->obj->db->get_var( $sql );
		// 22colors * 4sizes * 2types
		$total_colorized = COLORIZED_IMAGES * IMAGES_PER_REQUEST * 2;
		if ( $updated != $total_colorized ) { $pass = FALSE; }

		return $pass;
	}

}