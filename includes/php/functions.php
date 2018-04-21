<?php 
include_once( dirname( __FILE__ ) . '/Convertus_Data_API.php' );
include_once( dirname( __FILE__ ) . '/Kraken.php' );
$kraken = new Kraken("3a229550bd763f7848f362ef48cfa8d9", "0ec10e979cdde48de4f9dd6e2c0de9e9c412ee44");
$obj = new Convertus_DB_Updater('CA');
$media_entries = array();


/************************* CHROME DATA FUNCTIONS *************************/

function get_models() {
	global $obj;
	$updated = $obj->db->get_col('SELECT DISTINCT model.model_name FROM model INNER JOIN style ON style.model_name = model.model_name');
	$models = $obj->db->get_col('SELECT DISTINCT model_name FROM model');

	return array( 'models' => $models, 'updated' => $updated );
}

function update_all_makes() {
	global $obj;
	$obj->update_divisions();
	$results['outputs'] = $obj->outputs;
	return $results;
}

function update_all_models() {
	global $obj;
	$obj->update_models();
	$results = get_models();
	$results['outputs'] = $obj->outputs;  
	return $results;
}

function update_styles_by_model( $model ) {
	global $obj;
	$outputs = array();

	$styles = $obj->get_model_details( "model_name LIKE '{$model}'" );
	$obj->update_styles( $styles );
	$results['outputs'] = $obj->outputs;

	return $results;
}

function update_db_images_model( $model ) {
	global $obj;
	$sql = "SELECT style_id FROM style WHERE model_name LIKE '{$model}'";
	$results = $obj->db->get_results( $sql, ARRAY_A );
	// Check if model exists
	if ( ! $results ) { 
		return array( 'outputs' => array( 
			'type'=>'error', 
			'msg'=>'Could not find model ' . $model . ' in database.' 
		)); 
	}
	
	$sql = "SELECT * FROM media WHERE style_id LIKE ";
	foreach ( $results as $result ) {
		$sql .= $result['style_id'] . ' OR style_id LIKE ';
	}
	$sql = substr( $sql, 0 , -18 );
	$results = $obj->db->get_results( $sql, ARRAY_A );
	if ( ! $results ) { 
		return array( 'outputs' => array( 
			'type'=>'error', 
			'msg'=>'Could not find model ' . $model . ' in database.' 
		)); 
	}
	
	// Update all $results images here
}

// Idk bout this yet
function update_db_images() {
	$sql = "SELECT * FROM media WHERE url LIKE '%media.chromedata.com'";
}

//$start = microtime(true);
//
//$time_elapsed_secs = microtime(true) - $start;
//var_dump( $time_elapsed_secs );



/************************* IMAGES FUNCTIONS *************************/

function update_all_images() {
	global $obj, $media_entries;

	// urls with media.chromedata.com need to be updated
	$sql = 'SELECT * FROM media WHERE url LIKE "%media.chromedata.com%"';
	$results = $obj->db->get_results( $sql, ARRAY_A );
	if ( $results ) {
		if ( count( $results ) > 0 ) {
			$start = microtime(true);
			foreach ( $results as $media ) {

				$fname = explode( '/', $media['url'] );
				$fname = end( $fname );
				$fname = str_replace( '.png', '', $fname );
				$media['fname'] = $fname;
				$media['storage_path'] = 'media/' . strrev( $media['style_id'] ) . '/view/';
				array_push( $media_entries, $media );
				// Test three
				if ( count( $media_entries ) == 24 ) { kraken_s3(); break; } else { continue; }
				if ( $media['shot_code'] === '1' ) {
					$media['storage_path'] = 'media/' . strrev( $media['style_id'] ) . '/01/';
					update_colorized_images( $media );
				}
				break;
			}
			$time_elapsed_secs = microtime(true) - $start;
			var_dump( $time_elapsed_secs );
		} else {
			echo 'All chrome data images have already been formatted and uploaded to S3';
		}
	} else {
		echo 'Could not grab images from DB';
	}
}


// Grab and download all images
/**
	 * This function grabs and download all 1280 images from the url field in the media table.
	 * Additionally, each image is cropped and uploaded to s3 via the kraken API.
	 *
	 * @param array $media
	 *
	 * @return nothing
	 */
function update_colorized_images( $media ) {
	global $media_entries;

	// Make dir if not exist
	// Reverse id so its stored at random on the s3 bucket ( faster access :o )
	$style_id = strrev( $media['style_id'] );
	if ( ! file_exists( './media/' . $style_id ) ) {
		mkdir( './media/' . $style_id, 0777, true );
		mkdir( './media/' . $style_id . '/01', 0777, true );
	}

	$ftp_server = 'ftp.chromedata.com';
	$ftp_user = 'u311191';
	$ftp_pass = 'con191';

	// Connect to ftp or die
	$conn_id = ftp_connect($ftp_server) or set_error( 'FTP', "Couldn't connect to $ftp_server" ); 

	// Try to connect to ftp with the credentials above
	if ( ! @ftp_login( $conn_id, $ftp_user, $ftp_pass ) ) {
		set_error( 'FTP', "Couldn't connect as $ftp_user" );
		echo 'Caught an error connecting to ftp';
		return;
	}
	// Passive mode to iterate directory and download images
	ftp_pasv( $conn_id, true );

	$media['type'] = 'colorized';
	$media['local_path'] = './media/' . strrev( $media['style_id'] ) . '/01/';
	$folder = 'cc_' . str_replace( '_1280_01', '_01_1280', $media['fname'] );
	$contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $folder . '/' );

	// Foreach color variation
	foreach ( $contents as $image ) {
		$image_info = pathinfo( $image );
		$color_code = explode( '_', $image_info['filename'] );
		$color_code = end( $color_code );
		$copy = $media;
		$copy['color_option_code'] = $color_code;
		$copy['fname'] .= '_' . $color_code;
		$copy['local_path'] .= $copy['fname'] . '.png';
		if ( ftp_get( $conn_id, $copy['local_path'], $image, FTP_BINARY ) ) {
			array_push( $media_entries, $copy );
			// Add one
			break;
		}
	} // End of colorized image loop
}

function kraken_s3() {
	global $kraken, $media_entries;
	$params = array();

	foreach ( $media_entries as $media ) {
		// jpg Images
		$media['storage_path'] .= $media['fname'];
		$param = get_params( array(
			'lossy' 			=> true,
			'convert'			=> array(
				'format'			=> 'jpeg',
				'background'	=> '#ffffff'
			),
			'media'				=> $media
		), '.jpg' );
		array_push( $params, $param );

		// png images
		$param = get_params( array( 'media' => $media ), '.png' );
		array_push( $params, $param );
	}

	$responses = $kraken->multiple_request( $params );
	display_var( $responses );
//	update_media_db( $responses );
}

/**
	 * This function checks the response data from kraken and if successful,
	 * stores the media entries in the database
	 *
	 * @param arrays $data, array $media
	 * @return nothing
	 */
function update_media_db( $responses ) {
	global $obj;

	foreach ( $responses as $data ) {
		if ( $data["success"] ) {
			// Delete old entries, keeps reference to the chromedata entires
			$extension = substr( $data['results']['lg']['kraked_url'], -4 );
			$sql = "DELETE FROM media WHERE 
		style_id LIKE '{$media['style_id']}'
		AND type LIKE '{$media['type']}'
		AND shot_code LIKE {$media['shot_code']}
		AND color_option_code LIKE '{$media['color_option_code']}'
		AND url LIKE '%amazonaws%'
		AND url LIKE '%{$extension}%'";
			$obj->db->query( $sql );

			$sql = '';
			$values = array();
			// SQL Insert
			if ( $media['type'] == 'view' ) {
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, created ) VALUES ";
			} elseif ( $media['type'] == 'colorized' )  { 
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, created ) VALUES ";
			}

			// SQL Values
			foreach ( $data['results'] as $result ) {
				$background = 'Transparent';
				if ( strpos( $result['kraked_url'], '.jpg' ) !== false ) { $background = 'White'; }
				if ( $media['type'] == 'view' )  {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', now() )";
				} elseif ( $media['type'] == 'colorized' )  {
					$values[] = "( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '', '{$media['color_option_code']}', '', now())";
				}
			}

			echo '<pre>'; var_dump( $values ); echo '</pre>';
			echo '<br>';

			// SQL query
			$obj->db->query( $sql . implode( ',', $values ) );
		} else {
			echo "Fail. Error message: " . $data["message"];
		}
	}
}

/**
	 * This function generates the arguments necessary for the kraken api
	 * based on the media object passed in and the type of file to be converted
	 *
	 * @param array $args, array $media, string $type
	 * @return 
	 */
function get_params( $args, $type ) {
	$storage_path = $args['media']['storage_path'];
	$params = array(
		"wait"		 		=> true,
		"auto_orient"	=> true,
		"resize"	=> array(
			array(
				"id"						=> "lg",
				"width" 				=> 1280,
				"height" 				=> 960,
				"strategy" 			=> "auto",
				"storage_path" 	=> $storage_path . '_lg' . $type,
			),
			array(
				"id"						=> "md",
				"width" 				=> 1024,
				"height" 				=> 768,
				"strategy" 			=> "auto",
				"storage_path"	=> $storage_path . '_md' . $type,
			),
			array(
				"id"						=> "sm",
				"width" 				=> 640,
				"height" 				=> 480,
				"strategy" 			=> "auto",
				"enhance" 			=> true,
				"storage_path" 	=> $storage_path . '_sm' . $type,
			),
			array(
				"id"						=> "xs", 
				"width" 				=> 320,
				"height" 				=> 240,
				"strategy" 			=> "auto",
				"enhance"			 	=> true,
				"storage_path" 	=> $storage_path . '_xs' . $type,
			)
		),
		"s3_store" 	=> array(
			"key" 		=> "AKIAJXTPCC6NXPSTYFOQ",
			"secret" 	=> "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",
			"bucket" 	=> "convertus-vms",
			"region" 	=> "ca-central-1",
			"headers" => array(
				"Cache-Control" => "max-age=2592000000"
			)
		)
	);
	// Check if file is remote or local
	if ( array_key_exists( 'local_path', $args['media'] ) ) {
		$params['file'] = realpath( $args['media']['local_path'] );
	} else {
		$params['url'] = $args['media']['url'];
	}

	foreach ( $args as $key => $value ) {
		$params[$key] = $value;
	}
	return $params;
}

/**
	 * This function uses the kraken object based on the paramaters
	 * passed in.
	 *
	 * @param array $params
	 * @return array $data
	 */
function get_kraken_data( $params ) {
	global $kraken;
	$data = array();
	if ( array_key_exists( 'file', $params ) ) {
		$data = $kraken->upload( $params );
	} elseif ( array_key_exists( 'url', $params ) ) {
		$data = $kraken->url( $params );
	}
	return $data;
}

function is_dir_empty($dir) {
	if ( ! is_readable( $dir) ) return NULL; 
	return ( count( scandir( $dir ) ) == 2);
}

function display_var( $var ) {
	echo '<pre>';
	var_dump( $var );
	echo '</pre>';
}

?>