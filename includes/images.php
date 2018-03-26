<?php

require_once( dirname( __FILE__ ) . "/../libraries/kraken/Kraken.php");

function upload_image_and_crop( $image_url, $vehicle_id, $company_id, $sizes = 'all', $exploded_image = 0, $position = null ) {
		
	$kraken = new Kraken("3a229550bd763f7848f362ef48cfa8d9", "0ec10e979cdde48de4f9dd6e2c0de9e9c412ee44");

	$image_parts = pathinfo( $image_url );
	
//	var_dump( $image_url );
	$image_hash = hash_file( 'md5', $image_url );
//	var_dump( $image_hash );
	
	global $database;
	
	// Check to make sure that the image has not been uploaded already
	$result = $database->query( "SELECT COUNT( * ), image_id AS count FROM image_data WHERE vehicle_id = {$vehicle_id} AND image_hash LIKE '{$image_hash}'" );
	$data = $result->fetch_all( MYSQLI_ASSOC );
	
	// Reorder image id if it exists, if it doesn't than keep as is
	reorder_image_order( $vehicle_id, $position, $data[0]['image_id'] );
	
//	var_dump( $data[0]['count'] );
	if ( $data[0]['count'] > 0 ) {
		unlink( $image_url );
		return array( 'image' => false, 'hash' => $image_hash );
	}
	
	$params = array(
		"file" => $image_url,
		"wait" => true,
		"auto_orient" => true,
		"resize" => array(
			array(
				"id" => "original",
				"width" => 3840,
				"height" => 2160,
				"strategy" => "auto",
				"storage_path" => "vehicles/$company_id/$vehicle_id/" . $image_parts['filename'] . "_original" . "." . 'jpg',
				"convert" => array(
					"format" => "jpeg",
					"background" => "#ffffff"
				)
			),
			array(
				"id" => "lg",
				"width" => 1440,
				"height" => 1080,
				"strategy" => "auto",
				"storage_path" => "vehicles/$company_id/$vehicle_id/" . $image_parts['filename'] . "_lg" . "." . 'jpg',//$image_parts['extension']
				"convert" => array(
					"format" => "jpeg",
					"background" => "#ffffff"
				)
			),
			array(
				"id" => "md",
				"width" => 1024,
				"height" => 768,
				"strategy" => "auto",
				"storage_path" => "vehicles/$company_id/$vehicle_id/" . $image_parts['filename'] . "_md" . "." . 'jpg',
				"convert" => array(
					"format" => "jpeg",
					"background" => "#ffffff"
				)
			),
			array(
				"id" => "sm",
				"width" => 640,
				"height" => 480,
				"strategy" => "auto",
				"enhance" => true,
				"storage_path" => "vehicles/$company_id/$vehicle_id/" . $image_parts['filename'] . "_sm" . "." . 'jpg',
				"convert" => array(
					"format" => "jpeg",
					"background" => "#ffffff"
				)
			),
			array(
				"id" => "xs",
				"width" => 320,
				"height" => 240,
				"strategy" => "auto",
				"enhance" => true,
				"storage_path" => "vehicles/$company_id/$vehicle_id/" . $image_parts['filename'] . "_xs" . "." . 'jpg',
				"convert" => array(
					"format" => "jpeg",
					"background" => "#ffffff"
				)
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

	$data = $kraken->upload($params);

	if (!empty($data["success"])) {

		global $database;
		
		$query_gap = "SELECT (t1.image_order + 1) as gap_starts_at
			FROM image_data t1
			WHERE vehicle_id = '$vehicle_id' AND NOT EXISTS (SELECT t2.image_order FROM image_data t2 WHERE t2.image_order = t1.image_order + 1 AND t2.vehicle_id = '$vehicle_id')";
		$result = $database->query( $query_gap );
		$result_array = $result->fetch_all( MYSQLI_ASSOC );

		$gap = ( ! empty( $result_array[0] ) ) ? $result_array[0]['gap_starts_at'] : '1';

		$sql_statement = "INSERT INTO image_data ( vehicle_id, image_order, image_original, image_lg, image_md, image_sm, image_xs, image_hash, exploded_image, date_added ) VALUES";

		$sql_statement .= " ( '" . $vehicle_id . "', '" . $database->real_escape_string( $gap ) . "', '" . $database->real_escape_string( $data['results']['original']["kraked_url"] ) . "', '" . $database->real_escape_string( $data['results']['lg']["kraked_url"] ) . "', '" . $database->real_escape_string( $data['results']['md']["kraked_url"] ) . "', '" . $database->real_escape_string( $data['results']['sm']["kraked_url"] ) . "', '" . $database->real_escape_string( $data['results']['xs']["kraked_url"] ) . "', '" . $image_hash . "', " . $exploded_image . ", '" . date("Y-m-d H:i:s") . "' )";

		$sql_statement .= ' ON DUPLICATE KEY UPDATE image_hash = "' . $image_hash . '", date_added = "' . date("Y-m-d H:i:s") . '"';
		$database->query( $sql_statement );

		
//		var_dump( $database );
		
		unlink( $image_url );
		
		// optimization succeeded
		return array( 'image_id' => $database->insert_id, 'image' => $data['results']['sm']["kraked_url"], 'hash' => $image_hash );

	} elseif (isset($data["message"])) {
//		var_dump( $data['message'] );
		unlink( $image_url );
		// something went wrong with the optimization
//		echo "Optimization failed. Error message from Kraken.io: " . $data["message"];
	} else {

		unlink( $image_url );
		// something went wrong with the request
//		echo "cURL request failed. Error message: " . $data["error"];
	}
	
}



/**
 * Note from the Author: taken from Console Metrics with the express permission of the author Gabriel Kung January 4th, 2018 | originally developed Dec 29th, 2017 by Gabriel Kung
 * Reorder the image order - run before adding a image  
 *
 * @param int vehicle_id - id of the website we are making modifications
 * @param int position - position of the image null if we are deleting
 * @param int image_id - if updating a image
 *
 * @return nothing
 */
function reorder_image_order( $vehicle_id, $position = null, $image_id = null ) {
	global $database;

//		$result = $database->select( 'image_data', array( 'image_id', 'image_order' ), array( 'vehicle_id' => $vehicle_id ), 'ORDER BY image_order ASC' );
	$result = $database->query( "SELECT image_id, image_order FROM image_data WHERE vehicle_id = {$vehicle_id} ORDER BY image_order ASC" );
	$image_ordering = $result->fetch_all( MYSQLI_ASSOC );

	$order_array = array();
	foreach ( $image_ordering as $image_order ) {
		array_push( $order_array, $image_order['image_id'] );
	}			


	if ( $position != null ) {
		if ( $image_id == null ) {

			array_splice( $order_array, $position - 1, 0, array( null ) );
			unset( $order_array[ $position - 1 ] );

		} else {

			foreach ( $order_array as $key => $value ) {
				if ( $value == $image_id ) {
					unset( $order_array[ $key ] );
				}
			}
			$order_array = array_values( $order_array );
			array_splice( $order_array, $position - 1, 0, array( $image_id ) );

		}
	}
//		var_dump( $order_array );
	if ( ! empty( $order_array ) ) {

		$sql = "UPDATE image_data SET image_order = CASE ";

		foreach ( $order_array as $order => $id ) {
			$image_order = $order + 1;
			$sql .= " WHEN image_id = '{$id}' THEN '{$image_order}'";				
		}

		$image_id_list = implode( ', ', $order_array );
		$sql .= " ELSE image_order END WHERE image_id IN ( {$image_id_list} )";

		$database->query( $sql );

//		var_dump( $sql );

	}

}