<?php 
include_once( dirname( __FILE__ ) . '/Kraken.php' );
include_once( dirname( dirname( dirname( __FILE__ ) ) ) . '/config.php' );

class Convertus_Kraken_S3 {
	
	public $output;
	private $media_entries;
	private $db;
	private $kraken;

	function __construct( $db ) {
		$this->db = $db;
		$this->media_entries = array();
		$this->output = array();
		$this->kraken = new Kraken( KRAKEN_ACCESS_KEY_ID, KRAKEN_SECRET_ACCESS_KEY );
		
	}

	/** This function goes through all the media objects, and
	 *	via kraken optimizes the images, then stores them in the 
	 * 	database
	 * @param none
	 * 
	 * @return null
	 */
	public function update_images( $media, $type ) {
		foreach ( $media as $m ) {
			$m['storage_path'] = 'media/' . strrev( $m['style_id'] );
			if ( $type == 'view' ) {
				$m['storage_path'] .= '/view/' . $m['file_name'];
			} elseif ( $type == 'colorized' ) {
				$m['storage_path'] .= '/01/' . $m['file_name'];
			}
			$this->media_entries[] = $m;
		}
		$requests = $this->get_kraken_requests();
		if ( empty( $requests ) ) { return FALSE; }
		$responses = $this->kraken->multiple_requests( $requests );
		$this->update_media_db( $responses );
		return TRUSE;
	}

	/** This function goes through all the media objects, and
	 * grabs the paramaters needed to pass to the kraken api.
	 *
	 * @param none
	 * 
	 * @return null
	 */
	private function get_kraken_requests() {
		$requests = array();

		// Still working on this, reduce making a bunch of sql calls in the foreach below to be one/two calls
		// // Get all data in one call
		// $queries = array(
		// 	'styles'		=> array(),
		// 	'codes'			=> array(),
		// 	'files'			=> array(),
		// 	'color_codes'	=> array()
		// );
		// foreach ( $this->media_entries as $media ) {
		// 	$queries['styles'][] = $media['style_id'];
		// 	$queries['codes'][] = $media['shot_code'];
		// 	$queries['files'][] = $media['file_name'];
		// 	$queries['color_codes'][] = $media['color_option_code'];
		// }
		// var_dump( count( array_unique( $queries['files'] ) ) );
		// foreach( $queries as $key => $value ) {
		// 	$queries[$key] = "'" . implode( "','", array_unique( $value ) ) . "'";
		// }

		// $sql = "SELECT style_id, COUNT(*) FROM media WHERE
		// style_id IN ({$queries['styles']}) AND
		// type IN ('view', 'colorized') AND
		// shot_code IN ({$queries['codes']}) AND
		// file_name IN ({$queries['files']}) AND
		// color_option_code IN ({$queries['color_codes']}) AND
		// url LIKE '%amazonaws%' AND
		// url LIKE '%jpg%' GROUP BY
		// style_id, type, shot_code, file_name, color_option_code";
		// var_dump( $sql );
		// exit();

		foreach ( $this->media_entries as $media ) {
			
			$sql = "SELECT COUNT(*) FROM media WHERE 
			style_id LIKE '{$media['style_id']}' AND
			type LIKE '{$media['type']}' AND
			shot_code LIKE '{$media['shot_code']}' AND
			file_name LIKE '{$media['file_name']}' AND
			color_option_code LIKE '{$media['color_option_code']}' AND
			url LIKE '%amazonaws%' AND 
			url LIKE '%.jpg%'";

			$jpg = $this->db->get_var( $sql );
			if ( $jpg != IMAGES_PER_REQUEST ) {
				// jpg Images
				$params = $this->get_params( $media, '.jpg' );
				$requests = array_merge( $requests, $params );
			}
			
			$png = $this->db->get_var( str_replace( '.jpg', '.png', $sql ) );
			if ( $png == IMAGES_PER_REQUEST ) { continue; }
			// png images
			$params = $this->get_params( $media, '.png' );
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
			"url"			=> $media['url'],
			"media"			=> $media,
			"wait"		 	=> true,    
			"auto_orient"	=> true,
			"s3_store" 		=> array(
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
				'format'		=> 'jpeg',
				'background'	=> '#ffffff'
			);
		}

		$lgmd = array(
			array(
				"id"				=> "lg",
				"width" 			=> 1280,
				"height" 			=> 960,
				"strategy" 			=> "auto",
				"storage_path" 	=> $storage_path . "_lg" . $type,
			),
			array(
				"id"				=> "md",
				"width" 			=> 1024,
				"height" 			=> 768,
				"strategy" 			=> "auto",
				"storage_path"	=> $storage_path . "_md" . $type,
			)
		);
		$smxs = array(
			array(
				"id"				=> "sm",
				"width" 			=> 640,
				"height" 			=> 480,
				"strategy" 			=> "auto",
				"enhance" 			=> true,
				"storage_path" 	=> $storage_path . "_sm" . $type,
			),
			array(
				"id"				=> "xs", 
				"width" 			=> 320,
				"height" 			=> 240,
				"strategy" 			=> "auto",
				"enhance"			=> true,
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

			// 1) Remove all amazonaws media entries for corresponding request
			$this->db->query( $remove_sql . " AND url LIKE '%amazonaws.com/media%'" );

			// 2) SQL Insert
			if ( $media['type'] == 'view' ) {
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, file_name, model_name, model_name_cd, model_year ) VALUES ";
			} elseif ( $media['type'] == 'colorized' )  { 
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, file_name, model_name, model_name_cd, model_year ) VALUES ";
			}

			// 3) SQL Values. Figure out color name?
			$values = array();
			foreach ( $results as $result ) {
				$background = 'Transparent';
				if ( strpos( $result['kraked_url'], '.jpg' ) !== false ) { $background = 'White'; }
				if ( $media['type'] == 'view' )  {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '{$media['file_name']}', '{$media['model_name']}', '{$media['model_name_cd']}', '{$media['model_year']}' )";
				} elseif ( $media['type'] == 'colorized' ) {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '', '{$media['color_option_code']}', '', '{$media['file_name']}', '{$media['model_name']}', '{$media['model_name_cd']}', '{$media['model_year']}')";
				}
			}
			
			// 4) SQL query
			// display_var( $values );
			$this->db->query( $sql . implode( ',', $values ) );
		}


		// Remove 03, 04, 12 CD images after grabbing all views
		foreach( $this->media_entries as $m ) {
			if ( cd_media_is_updated( $m ) ) {
				remove_cd_media($m);
			} else {
				$results[] = $m;
			}
		}
	}
}