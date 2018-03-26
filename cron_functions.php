<?php 

include_once( dirname( __FILE__ ) . '/functions.php' );
include_once( dirname( __FILE__ ) . '/includes/Kraken.php' );

$obj = new Convertus_DB_Updater( 'CA' );
$kraken = new Kraken("3a229550bd763f7848f362ef48cfa8d9", "0ec10e979cdde48de4f9dd6e2c0de9e9c412ee44");

//$response = $obj->soap_call(
//	'describeVehicle',
//	array(
//		'styleId' => 390597,
//		'includeMediaGallery' => 'Both',
//		'switch' => array(
//			'ShowAvailableEquipment',
//			'ShowConsumerInformation',
//			'ShowExtendedTechnicalSpecifications',
//			'IncludeDefinitions',
//		),
//	)
//);
//echo '<pre>'; var_dump( $response ); echo '</pre>';
//exit();
//
//$styles = $obj->get_model_details( "model_name LIKE 'M4'" );
//$obj->update_styles( $styles );

download_all_1280_images();
//update_media_db_s3();

function download_all_1280_images() {
	global $obj;

	// urls with media.chromedata.com need to be updated
	$sql = 'SELECT style_id, type, url FROM media WHERE url LIKE "%media.chromedata.com%"';
	$results = $obj->db->get_results( $sql, ARRAY_A );
	if ( $results ) {
		if ( count( $results ) > 0 ) {

			foreach ( $results as $media ) {
				download_1280_images( $media );
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
	 * This function grabs and download all 1280 images from the url field in the media table
	 * 
	 * 
	 *
	 * @param none
	 *
	 * @return 
	 */
function download_1280_images( $media ) {
	global $obj;

	$colorized_files = array();

	$fname = explode( '/', $media['url'] );
	$fname = end( $fname );

	// Make dir if not exist
	// Reverse id so its stored at random in on the s3 bucket ( apparently faster :o )
	$style_id = strrev( $media['style_id'] );
	if ( ! file_exists( './media/' . $style_id ) ) {
		mkdir( './media/' . $style_id, 0777, true );
		mkdir( './media/' . $style_id . '/view', 0777, true );
		mkdir( './media/' . $style_id . '/01', 0777, true );
	}

	// If media is snapshot 01, download colorized images
	if ( strpos( $fname, '_1280_01.png' ) ) {
		$file = 'cc_' . str_replace( '_1280_01', '_01_1280', $fname );
		$file = str_replace( '.png', '', $file );
		$media['fname'] = $file;
		download_colorized_variations( $media );
	}

	// Download view image from chrome data
	$save_location = './media/' . $style_id . '/view/' . $fname;
	if ( ! file_exists( $save_location ) ) {
		copy( $media['url'], $save_location );
		echo 'Successfully copied over ' . $save_location . '<br>';
	} else {
		echo 'view already exists for ' . $save_location . '<br>';
	}
}

/**
	 * This function downloads all the colorized
	 * images onto your local colorized media folder
	 * for each file ( style ) in files
	 *
	 * @param array $files The reference file(s) to grab the colorized images
	 *
	 * @return none
	 */
function download_colorized_variations( $media ) {

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

	// For each style, grab all colorized snapshot 01 images
	$contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $media['fname'] . '/' );
	// Store in the reverse id dir
	$tmp_directory = './media/' . strrev( $media['style_id'] ) . '/01/';

	foreach ( $contents as $image ) {
		$image_info = pathinfo( $image );
		// Skip files that already exists
		if ( file_exists( $tmp_directory . $image_info['basename'] ) ) { 
			echo 'File already exists: ' . $tmp_directory . $image_info['basename'] . '<br>';
			continue; 
		}
		// Otherwise copy them over
		if ( ftp_get( $conn_id, $tmp_directory . $image_info['basename'], $image, FTP_BINARY ) ) {
			echo 'Successfully copied over ' . $image . ' to directory ' . $tmp_directory . '<br>';
		}
	}
	echo '<br>';
}

	/**
	 * This function stores all images on s3
	 * and updates their references in the media
	 * table
	 *
	 * @param none
	 *
	 * @return none
	 */
	function update_media_db_s3() {
	global $wpdb;

	foreach ( new DirectoryIterator('./media' ) as $folder ) {
		// Foreach style folder
		if ( ! $folder->isDot() && $folder->isDir() ) {

			// Foreach view image
			$dir = './media/' . $folder->getFileName() . '/view/';
			foreach ( new DirectoryIterator( $dir ) as $file ) {
				if ( $file->isDot() ) { continue; }
				//				echo $file->getFileName() . '<br>';
				upload_image_and_crop( $dir, $file->getBasename() );
				break;
			}
			break;

			// Foreach colorized image
			$dir = './media/' . $folder->getFileName() . '/01/';
			foreach ( new DirectoryIterator( $dir ) as $file ) {
				if ( $file->isDot() ) { continue; }
				echo $file->getFileName() . '<br>';
			}

		}
	}

}

/**
	 * This function uploads all locally stored media images into s3
	 * after optimization via Kraken
	 *
	 * @param array $files The parameters to increment with the year parameter
	 *
	 * @return 
	 */

function upload_image_and_crop( $dir, $file ) {
	global $kraken;
	$fileInfo = pathinfo( $file );
	$storage_dir = str_replace( './', '', $dir );

	$params = array(
		"file" => realpath( $dir . $file ),
		"wait" => true,
		"auto_orient" => true,
		"resize" => array(
			array(
				"id" => "lg",
				"width" => 1280,
				"height" => 960,
				"strategy" => "auto",
				"storage_path" => $storage_dir . $fileInfo['filename'] . '_lg.' . $fileInfo['extension'],
			),
			array(
				"id" => "md",
				"width" => 1024,
				"height" => 768,
				"strategy" => "auto",
				"storage_path" => $storage_dir . $fileInfo['filename'] . '_md.' . $fileInfo['extension'],
			),
			array(
				"id" => "sm",
				"width" => 640,
				"height" => 480,
				"strategy" => "auto",
				"enhance" => true,
				"storage_path" => $storage_dir . $fileInfo['filename'] . '_sm.' . $fileInfo['extension'],
			),
			array(
				"id" => "xs",
				"width" => 320,
				"height" => 240,
				"strategy" => "auto",
				"enhance" => true,
				"storage_path" => $storage_dir . $fileInfo['filename'] . '_xs.' . $fileInfo['extension'],
			),
		),
		"s3_store" => array(
			"key" => "AKIAJXTPCC6NXPSTYFOQ",
			"secret" => "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",
			"bucket" => "convertus-vms",
			"region" => "ca-central-1",
			"headers" => array(
				"Cache-Control" => "max-age=2592000000"
			)
		)
	);

	$data = $kraken->upload( $params );
	var_dump( $data );
	if ( ! empty( $data["success"] ) ) {

		// Replace URL in DB
	}

	//				"convert" => array(
	//					"format" => "jpeg",
	//					"background" => "#ffffff"
	//				)

	//			array(
	//				"id" => "original",
	//				"width" => 2100,
	//				"height" => 1575,
	//				"strategy" => "auto",
	//				"storage_path" => $storage_path . '_original.' . $image_parts['extension'],
	//			),
}

?>