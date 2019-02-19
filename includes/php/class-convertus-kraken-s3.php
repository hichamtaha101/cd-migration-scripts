<?php 
include_once( dirname( __FILE__ ) . '/class-kraken.php' );
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
		$this->test = array();
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

	/** This function goes through all the media objects, and grabs the paramaters 
	 * needed to pass to the kraken api. Typically media records x 2 requests ( 4jpgs/request, 4pngs/request )
	 *
	 * @return null
	 */
	private function get_kraken_requests() {
		$requests = array();
		foreach ( $this->media_entries as $media ) {
			// jpg Images
			$requests[] =  $this->get_params( $media, '.jpg' );
			// png images
			$requests[] = $this->get_params( $media, '.png' );
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
			"resize"		=> array(
				array(
					"id"				=> "lg",
					"width" 			=> 1280,
					"height" 			=> 960,
					"strategy" 			=> "auto",
					"storage_path" 		=> $storage_path . "_lg" . $type,
				),
				array(
					"id"				=> "md",
					"width" 			=> 1024,
					"height" 			=> 768,
					"strategy" 			=> "auto",
					"storage_path"		=> $storage_path . "_md" . $type,
				),
				array(
					"id"				=> "sm",
					"width" 			=> 640,
					"height" 			=> 480,
					"strategy" 			=> "auto",
					"enhance" 			=> true,
					"storage_path" 		=> $storage_path . "_sm" . $type,
				),
				array(
					"id"				=> "xs", 
					"width" 			=> 320,
					"height" 			=> 240,
					"strategy" 			=> "auto",
					"enhance"			=> true,
					"storage_path" 		=> $storage_path . "_xs" . $type,
				)
			),
			"s3_store" 		=> array(
				"key" 		=> AWS_ACCESS_KEY_ID,
				"secret" 	=> AWS_SECRET_ACCESS_KEY,
				"bucket" 	=> AWS_BUCKET,
				"region" 	=> AWS_REGION,
				"headers" => array(
					"Cache-Control" => "max-age=2592000000",
				),
			)
		);

		// Add arguments specific to jpg
		if ( $type == '.jpg' ) {
			$params['lossy'] = true;
			$params['convert'] = array(
				'format'		=> 'jpeg',
				'background'	=> '#ffffff'
			);
		}

		return $params;
	}

	/**
	 * This function takes all the successfully optimized and stored media objects, and updates
	 * the database media table with the s3 references
	 *
	 * @param array $responses	The array of responses retrieved from Kraken after optimizing images.
	 * @param string $type		View or Colorized to determine format of insert sql.
	 * 
	 * @return null
	 */
	private function update_media_db( $responses ) {

		// Generic data
		$model_name_cd = $responses[0]['media']['model_name_cd'];
		$type = $responses[0]['media']['type'];

		// 1. Define sql statements
		$insert_format = "( style_id, type, url, height, shot_code, width, background, file_name, model_name, model_name_cd, model_year )";
		if ( $type === 'colorized' ) {
			$insert_format = "( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, file_name, model_name, model_name_cd, model_year )";
		}
		$insert_sql = "INSERT INTO showroom.media " . $insert_format . " VALUES ";
		$delete_sql = "DELETE FROM showroom.media WHERE ";
		$insert_values = array();
		$delete_values = array();

		// 2. Grab sql values
		foreach ( $responses as $response ) {
			$media = $response['media'];
			$results = $response['response']['results'];
			
			// Delete old entries
			$extension = substr( $results['xs']['kraked_url'], -4 );
			$delete_values[] = "( style_id LIKE '{$media['style_id']}' 
			AND type LIKE '{$media['type']}' 
			AND shot_code LIKE '{$media['shot_code']}' 
			AND file_name LIKE '{$media['file_name']}' 
			AND color_option_code LIKE '{$media['color_option_code']}' 
			AND url LIKE '%amazonaws.com/media%'
			AND url LIKE '%{$extension}%' )";

			// Insert new entries
			foreach ( $results as $result ) {
				$background = 'Transparent';
				if ( $extension === '.jpg' ) { $background = 'White'; }
				if ( $media['type'] == 'view' && $type === 'view' )  {
					$insert_values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '{$media['file_name']}', '{$media['model_name']}', '{$model_name_cd}', '{$media['model_year']}' )";
				} elseif ( $media['type'] == 'colorized' && $type === 'colorized' ) {
					$insert_values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '', '{$media['color_option_code']}', '', '{$media['file_name']}', '{$media['model_name']}', '{$model_name_cd}', '{$media['model_year']}')";
				}
			}
		}

		// 3. Run SQL statements
		if ( sizeOf( $delete_values ) > 0 ) {
			$this->db->query( $delete_sql . implode( ' OR ', $delete_values ) );
		} 
		if ( sizeOf( $insert_values ) > 0 ) {
			$this->db->query( $insert_sql . implode( ',', $insert_values ) );
		}

		// 4. Check to remove chromedata  02, 03, 12
		if ( $type === 'view' ) {
			get_chromedata_media_by_model( $model_name_cd );
		}
	}
}