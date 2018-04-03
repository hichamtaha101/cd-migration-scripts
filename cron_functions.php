<?php 

include_once( dirname( __FILE__ ) . '/functions.php' );
include_once( dirname( __FILE__ ) . '/includes/Kraken.php' );

$kraken = new Kraken("3a229550bd763f7848f362ef48cfa8d9", "0ec10e979cdde48de4f9dd6e2c0de9e9c412ee44");

//update_all_images();

function update_all_images() {
	global $obj;

	// urls with media.chromedata.com need to be updated
	$sql = 'SELECT * FROM media WHERE url LIKE "%media.chromedata.com%"';
	$results = $obj->db->get_results( $sql, ARRAY_A );
	if ( $results ) {
		if ( count( $results ) > 0 ) {
			foreach ( $results as $media ) {

				//				kraken_s3_view( $media ); 
				//				break;
				$start = microtime(true);
				udpate_colorized_images( $media );
				$time_elapsed_secs = microtime(true) - $start;
				var_dump( $time_elapsed_secs );
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
function udpate_colorized_images( $media ) {
	
	if ( strpos( $media['url'], '_1280_01.png' ) ) {
		// Make dir if not exist
		// Reverse id so its stored at random on the s3 bucket ( faster access :o )
		$style_id = strrev( $media['style_id'] );
		if ( ! file_exists( './media/' . $style_id ) ) {
			mkdir( './media/' . $style_id, 0777, true );
			mkdir( './media/' . $style_id . '/01', 0777, true );
		} 
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
				kraken_s3_colorized( $media );
				break;
			}
		} // End of colorized image loop
	} // End of colorized image check
}

/**
	 * This function uploads all remotely stored media images into s3
	 * after optimization via Kraken
	 *
	 * @param array $media
	 * @return 
	 */
function kraken_s3_view( $media ) {
	global $kraken;
	$fname = explode( '/', $media['url'] );
	$fname = end( $fname );
	$fname = str_replace( '.png', '', $fname );
	$storage_path = 'media/' . strrev( $media['style_id'] ) . '/view/' . $fname;

	//	 JPEG images
	$params = get_params( array(
		'url' => $media['url'], 
		'lossy' => true ,
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
function kraken_s3_colorized( $media ) {
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
		//		var_dump( $sql ); echo '<br>';

		// Insert Updated Kraken Entries
		foreach ( $data['results'] as $result ) {
			$background = 'Transparent';
			if ( strpos( $result['kraked_url'], '.jpg' ) !== false ) {
				$background = 'White';
			}

			if ( $media['type'] == 'view' )  {
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, created ) VALUES ( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', now())";

			} elseif ( $media['type'] == 'colorized' )  {
				$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, created ) VALUES ( '{$media['style_id']}', '{$media['type']}', '{$result['kraked_url']}', {$result['kraked_height']}, {$media['shot_code']}, {$result['kraked_width']}, '{$background}', '', '{$media['color_option_code']}', '', now())";
			}

			var_dump( $sql ); echo '<br>';
			$obj->db->query( $sql );
		}
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