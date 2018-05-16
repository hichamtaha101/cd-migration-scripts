<?php 
include_once( dirname( __FILE__ ) . '/Convertus_Data_API.php' );
include_once( dirname( __FILE__ ) . '/Convertus_Kraken_S3.php' );
include_once( dirname( __FILE__ ) . '/AWS_S3.php' );
$obj = new Convertus_DB_Updater('CA');
$k_s3 = new Convertus_Kraken_S3( $obj );
$aws_s3 = new AWS_S3();

/************************* CHROME DATA FUNCTIONS *************************/

function get_updated_models() {
	global $obj;
	
	$models = $obj->db->get_col('SELECT DISTINCT model_name FROM model');
	$models_updated = $obj->db->get_col('SELECT DISTINCT model.model_name FROM model INNER JOIN style ON style.model_name = model.model_name');
	// Update sql to check for shotcode 1 images
	$sql = 'SELECT s.model_name, 
	COUNT(m.url) AS images,
	(SELECT SUM(ss.media_count) FROM style ss WHERE ss.model_name LIKE s.model_name) AS shot_codes,
	COUNT(DISTINCT(s.style_id)) AS styles
	FROM style s INNER JOIN media m ON s.style_id = m.style_id 
	WHERE m.url LIKE "%amazonaws%" AND 
	m.type LIKE "%view%" AND 
	s.has_media LIKE 1
  GROUP BY s.model_name';
	$results = $obj->db->get_results( $sql, ARRAY_A );
	$views_updated = array();
	foreach ( $results as $result ) {
		// Images = style total shotcodes * 2 types(jpg,png) * 4 sizes(lg,md,sm,xs)
		if ( $result['images'] == ( $result['shot_codes'] * 2 * 4 ) ) {
			$views_updated[] = $result['model_name'];
		}
	}

	$sql = str_replace( 'view', 'colorized', $sql );
	$results = $obj->db->get_results( $sql, ARRAY_A );
	// Check for colorized images per model
	$colorized_updated = array();
	foreach ( $results as $result ) {
		// Images = styles * 22 colors * 2 types(jpg,png) * 4sizes(lg,md,sm,xs)
		if ( $result['images'] == ( $result['styles'] * COLORIZED_IMAGES * 2 * 4 ) ) {
			$colorized_updated[] = $result['model_name'];
		}
	}

	return array(
		'models' 	=> $models,
		'updated'	=> array(
			'styles' 		=> $models_updated,
			'views'			=> $views_updated,
			'colorized'	=> $colorized_updated,
		),
	);
	// Check if all has colorized
	// sql = "SLECT s.style_id, ( SELECT COUNT(me.style_id) FROM media me WHERE me.shot_code LIKE 1 AND me.style_id LIKE s.style_id ) as shotcode FROM style s"
}

function upload_original_s3() {

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
	$results = get_updated_models();
	$results['outputs'] = $obj->outputs;  
	return $results;
}

function update_styles_by_model( $model ) {
	global $obj;
	$outputs = array();

	$styles = $obj->get_model_details( "model_name LIKE '{$model}'" );
	if ( $obj->valid === TRUE ) {
		$obj->update_styles( $styles );
		$results['update'] = array( 
			'key' => 'styles', 
			'data' => $model 
		);
	}
	$results['outputs'] = $obj->outputs;
	$results['valid']	= $obj->valid;

	return $results;
}

function update_views_by_model( $model ) {
	return update_model_images( $model, 'view' );
}
function update_colorized_by_model( $model ) {
	return update_model_images( $model, 'colorized' );
}

function update_model_images( $model, $type ) {
	global $obj, $k_s3;
	
	$outputs = array( array(
		'type'=>'error', 
		'msg'=>'Could not find model ' . $model . ' in database.' 
	));
	$sql = "SELECT style_id FROM style WHERE model_name LIKE '{$model}' AND has_media LIKE 1";
	$styles = $obj->db->get_results( $sql, ARRAY_A );
	// Check if model exists
	if ( ! $styles ) {
		return array( 'outputs' => $outputs );
	}

	$sql = "SELECT style_id, type, url, shot_code, file_name, color_option_code FROM media WHERE url LIKE '%media.chromedata%' AND shot_code LIKE '1' AND ( style_id LIKE ";
	if ( $type == 'view' ) { $sql = str_replace( " shot_code LIKE '1' AND", "", $sql ); }
	foreach ( $styles as $style ) {
		$sql .= $style['style_id'] . ' OR style_id LIKE ';
	}
	$sql = substr( $sql, 0 , -18 );
	$sql .= ' )';
	$media = $obj->db->get_results( $sql, ARRAY_A );

	// Optimize and store all media images here
	$result = $k_s3->update_images( $media, $type );
	$outputs[0]['type'] = 'success';
	$outputs[0]['msg'] = 'Updated all ' . $model . ' ' . $type . ' images in s3 and database';
	if ( $result === FALSE ) {
		$outputs[0]['msg'] = 'Already updated all ' . $model . ' ' . $type . ' images in s3 and database';
	}
	$results = array(
		'update'	=> array(
			'key' 		=> ( $type == 'view' ) ? 'views' : 'colorized', 
			'data' 		=> $model,
		),
		'outputs'	=> $outputs
	);
	return $results;
}

/************************* OTHER FUNCTIONS *************************/

function display_var( $var ) {
	echo '<pre>';
	var_dump( $var );
	echo '</pre>';
}