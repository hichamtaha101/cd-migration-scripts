<?php 
include_once( dirname( __FILE__ ) . '/Convertus_Data_API.php' );
include_once( dirname( __FILE__ ) . '/Kraken.php' );
$kraken = new Kraken("3a229550bd763f7848f362ef48cfa8d9", "0ec10e979cdde48de4f9dd6e2c0de9e9c412ee44");
$obj = new Convertus_DB_Updater('CA');


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

function update_db_images_style( $style_id ) {
	global $obj;
	$sql = "SELECT * FROM media WHERE url LIKE '%media.chromedata.com%' AND style_id LIKE '{$style_id}'";
	$results = $obj->db->get_results( $sql, ARRAY_A );
	var_dump( $results );
}

function update_db_images() {

	return array();
}




/************************* IMAGES FUNCTIONS *************************/

//update_all_images();
function update_all_images() {
	global $obj;

	// urls with media.chromedata.com need to be updated
	$sql = 'SELECT * FROM media WHERE url LIKE "%media.chromedata.com%"';
	$results = $obj->db->get_results( $sql, ARRAY_A );
	if ( $results ) {
		if ( count( $results ) > 0 ) {
			foreach ( $results as $media ) {
				//				kraken_s3_url( $media ); 
				//				break;
				//								$start = microtime(true);
				if ( $media['shot_code'] === '1' ) {
					update_colorized_images( $media );
				}

				//								$time_elapsed_secs = microtime(true) - $start;
				//								var_dump( $time_elapsed_secs );
				break;
			}
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
	 * @param array $media, object containing the media's style_id, type, and url
	 *
	 * @return
	 */
function update_colorized_images( $media ) {

	// Make dir if not exist
	// Reverse id so its stored at random on the s3 bucket ( faster access :o )
	$style_id = strrev( $media['style_id'] );
	if ( ! file_exists( './media/' . $style_id ) ) {
		mkdir( './media/' . $style_id, 0777, true );
		mkdir( './media/' . $style_id . '/01', 0777, true );
	}
	// fname is the format used to reference the colorized folder in the ftp
	$fname = explode( '/', $media['url'] );
	$fname = end( $fname );
	$fname = 'cc_' . str_replace( '_1280_01', '_01_1280', $fname );
	$fname = str_replace( '.png', '', $fname );
	$media['fname'] = $fname;
	$media['type']	= 'colorized';

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

	$contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $fname . '/' );
	$local_path = './media/' . strrev( $media['style_id'] ) . '/01/';
	foreach ( $contents as $image ) {
		$image_info = pathinfo( $image );
		if ( ftp_get( $conn_id, $local_path . $image_info['basename'], $image, FTP_BINARY ) ) {
			$color_code = explode( '_', $image_info['filename'] );
			$color_code = end( $color_code );
			$media['color_option_code'] = $color_code;
			kraken_s3_upload( $media );
			break;
		}
	} // End of colorized image loop
}

/**
	 * This function uploads all remotely stored media images into s3
	 * after optimization via Kraken
	 *
	 * @param array $media
	 * @return 
	 */
function kraken_s3_url( $media ) {
	global $kraken;
	$fname = explode( '/', $media['url'] );
	$fname = end( $fname );
	$fname = str_replace( '.png', '', $fname );
	$storage_path = 'media/' . strrev( $media['style_id'] ) . '/view/' . $fname;

	//	 JPEG images
	$params = get_params( array(
		'url' 		=> $media['url'], 
		'lossy' 	=> true,
		'wait'		=> true,
		'convert'	=> array(
			'format'	=> 'jpeg',
			'background'	=> '#ffffff'
		),
	), $storage_path, '.jpg' );
	$data = $kraken->url( $params );
	update_media_db( $data, $media );

	// PNG Images
	$params = get_params( array(
		'url' => $media['url']
	), $storage_path, '.png' );
	$data = $kraken->url( $params );
	update_media_db( $data, $media );

}

/**
	 * This function uploads all remotely stored media images into s3
	 * after optimization via Kraken
	 *
	 * @param array $media
	 * @return 
	 */
function kraken_s3_upload( $media ) {
	global $kraken, $obj;
	$storage_path = 'media/' . strrev( $media['style_id'] ) . '/01/' . $media['fname'] . '_' . $media['color_option_code'];
	$local_path = './' . $storage_path . '.png';

	$params = get_params( array(
		'file'				=> realpath( $local_path ),
		'lossy' 			=> true,
		'convert'			=> array(
			'format'			=> 'jpeg',
			'background'	=> '#ffffff'
		)
	), $storage_path, '.jpg' );
	$data = $kraken->upload( $params );
	update_media_db( $data, $media );

	$params = get_params( array(
		'file'				=> realpath( $local_path ),
	), $storage_path, '.png' );
	$data = $kraken->upload( $params );
	update_media_db( $data, $media );

}

/**
	 * This function 
	 *
	 *
	 * @param 
	 * @return 
	 */
function update_media_db( $data, $media ) {
	global $obj;
	//echo '<pre>'; var_dump( $data ); echo '</pre>';

	if ( $data["success"] ) {
		// Delete old entries
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

/**
	 * This function 
	 *
	 *
	 * @param 
	 * @return 
	 */
function get_params( $args, $storage_path, $type ) {
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
	foreach ( $args as $key => $value ) {
		$params[$key] = $value;
	}
	return $params;
}

/**
	 * This function 
	 *
	 *
	 * @param 
	 * @return 
	 */
function is_dir_empty($dir) {
	if ( ! is_readable( $dir) ) return NULL; 
	return ( count( scandir( $dir ) ) == 2);
}

?>