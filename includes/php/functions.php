<?php 
include_once( dirname( __FILE__ ) . '/wpdb.php' );
include_once( dirname( __FILE__ ) . '/Convertus_Data_API.php' );
include_once( dirname( __FILE__ ) . '/Convertus_Kraken_S3.php' );
include_once( dirname( __FILE__ ) . '/AWS_S3.php' );
$db = new WPDB();
$obj = new Convertus_DB_Updater('CA');
$k_s3 = new Convertus_Kraken_S3($db);
$aws_s3 = new AWS_S3($db);

/************************* CHROME DATA FUNCTIONS *************************/

function get_updated_models() {
	global $db;
	
	$models = $db->get_col('SELECT DISTINCT model_name_cd FROM model');
	$sql = "
	SELECT 
    DISTINCT(a.model_name_cd),
    IFNULL(b.view_images, 0) as view_images,
    IFNULL(c.colorized_images, 0) as colorized_images,
    IFNULL(d.ftp_original_images, 0) as ftp_original_images,
    IFNULL(e.styles, 0) as styles,
    IFNULL(e.view_count, 0) as view_count,
    IFNULL(e.colorized_count, 0) as colorized_count
	FROM 
			style a
	LEFT JOIN 
			(SELECT model_name_cd, COUNT(*) as view_images FROM media mm WHERE mm.url LIKE '%amazonaws.com/media%' AND mm.type = 'view' GROUP BY model_name_cd) b
	ON
			a.model_name_cd = b.model_name_cd
	LEFT JOIN 
			(SELECT model_name_cd, COUNT(*) as colorized_images FROM media mm WHERE mm.url LIKE '%amazonaws.com/media%' AND mm.type = 'colorized' GROUP BY model_name_cd) c
	ON
			a.model_name_cd = c.model_name_cd
	LEFT JOIN
			(SELECT model_name_cd, COUNT(*) as ftp_original_images FROM media mm WHERE mm.url LIKE '%amazonaws.com/original/colorized%' GROUP BY model_name_cd) d
	ON
			a.model_name_cd = d.model_name_cd
	LEFT JOIN 
		(SELECT model_name_cd, COUNT(*) as styles, SUM(ss.view_count) as view_count, SUM(ss.colorized_count) as colorized_count FROM style ss WHERE ss.has_media LIKE 1 GROUP BY model_name_cd ) e
	ON
			a.model_name_cd = e.model_name_cd
	";
	$results = $db->get_results( $sql, ARRAY_A );
	$models_updated = $views_updated = $ftp_s3_updated = $colorized_updated = array();
	foreach ( $results as $result ) {
		$models_updated[] = $result['model_name_cd'];

		// View images = style total views * 2 types(jpg,png) * 4 sizes(lg,md,sm,xs)
		// Greater than incase you add old models, ask Hicham about this odd scenario
		if ( $result['view_images'] >= ( $result['view_count'] * 2 * 4 ) ) {
			$views_updated[] = $result['model_name_cd'];
		}
		
		// Don't check if colorized_count !== 0, these models can be considered done for both ftp-s3 and colorized images
		// Ftp to S3 images
		if ( $result['ftp_original_images'] >= $result['colorized_count'] ) {
			$ftp_s3_updated[] = $result['model_name_cd'];
		}
		
		// Colorized Images = style total ftp images * 2 types * 4 sizes
		if ( $result['colorized_images'] >= $result['colorized_count'] * 2 * 4 ) {
			$colorized_updated[] = $result['model_name_cd'];
		}
	}

	return array(
		'models' 	=> $models,
		'updated'	=> array(
			'styles' 		=> $models_updated,
			'views'			=> $views_updated,
			'ftps3'			=> $ftp_s3_updated,
			'colorized'		=> $colorized_updated,
		),
	);
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

	$styles = $obj->get_model_details( "model_name_cd LIKE '{$model}'" );
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
	global $k_s3;
	
	$media = get_chromedata_media_by_model( $model, $type );
	if ( ! $media['pass'] ) {
		return $media['outputs'];
	} 
	$media = $media['media'];

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

function update_ftps3_by_model( $model ) {
	global $aws_s3;
	$media = get_chromedata_media_by_model( $model, 'view' );
	if ( ! $media['pass'] ) {
		return array(
			'outputs'	=> $media['outputs']
		);
	}
	$media = $media['media'];
	$test = $aws_s3->copy_colorized_media_to_s3( $media );
	if ( $test ) {
		return array(
			'update'	=> array(
				'key'		=> 'ftps3',
				'data'	=> $model
			),
			'outputs'	=> $aws_s3->outputs
		);
	} else {
		return array(
			'outputs'	=> $aws_s3->outputs
		);
	}
	
}

/************************* OTHER FUNCTIONS *************************/

function display_var( $var ) {
	echo '<pre>';
	var_dump( $var );
	echo '</pre>';
}

function get_chromedata_media_by_model( $model, $type ){
	global $db;

	$results = array();
	$outputs = array( array(
		'type'=>'error', 
		'msg'=>'Could not find any media entries for ' . $model . ' in database.' 
	));

	$sql = "SELECT 
	a.model_name_cd, a.model_name, a.style_id, a.type, a.url, a.shot_code, a.file_name, a.color_option_code, 
  IFNULL(b.colorized_count, 0) as colorized_count,
  IFNULL(c.colorized_original, 0) as colorized_original
	FROM media a 
	LEFT JOIN (SELECT style_id, COUNT(url) as colorized_original FROM media WHERE url LIKE '%amazonaws.com/original/colorized%' GROUP BY style_id ) c
	ON a.style_id = c.style_id
	LEFT JOIN (SELECT style_id, colorized_count FROM style GROUP BY style_id) b
	ON a.style_id = b.style_id 
	WHERE a.model_name_cd = '{$model}' 
	AND a.url LIKE '%chromedata%'";
	if ( $type == 'colorized' ) { 
		$sql = str_replace("%chromedata%", "%amazonaws.com/original/colorized%", $sql );
	}
	
	$media = $db->get_results( $sql, ARRAY_A );

	// Check if Model exists
	if ( ! $media ) {
		return array(
			'pass'		=> FALSE,
			'outputs' => $outputs 
		);
	}

	// Remove chromedata images if updated views and ftp to s3
	if ( $type != 'colorized' ) {
		foreach( $media as $m ) {
			if ( cd_media_is_updated( $m ) ) {
				remove_cd_media($m);
			} else {
				$results[] = $m;
			}
		}
	} else {
		$results = array();

		// We want to keep original colorized images
		if ( ! colorized_media_is_updated( $media ) ) {
			$results = $media;
		}
	}

	// No media entries found
	if ( empty( $results ) ) {
		return array(
			'pass'	=> FALSE, 
			'outputs'	=> $outputs
		);
	}
	
	// These media entries need to be updated
	return array(
		'pass'	=> TRUE, 
		'media'	=> $results
	);
}

function colorized_media_is_updated( $media ) {
	global $db;
	$model_name = $media[0]['model_name_cd'];
	$sql = "SELECT COUNT(url) FROM media WHERE model_name_cd = '{$model_name}' AND url LIKE '%amazonaws.com/media%' AND type = 'colorized'";
	$result = $db->get_var( $sql );
	if ( count($media) * 2 * IMAGES_PER_REQUEST === intval( $result ) ) {
		return true;
	}
	return false;
}

function cd_media_is_updated($media){
	global $db;
	$pass = TRUSE;

	$sql = "SELECT count(style_id) FROM media WHERE
	style_id = '{$media['style_id']}' AND
	shot_code = '{$media['shot_code']}' AND
	url LIKE '%amazonaws.com/media%' AND
	type = 'view'";
	$updated = $db->get_var( $sql );
	
	// Check if media has 8 view images
	if ( $updated != IMAGES_PER_REQUEST * 2 ) { $pass = FALSE; }

	// Return if shotcode is not 1 or failed check
	if ( $media['shot_code'] !== '1' || ! $pass ) { 
		return $pass; 
	}

	// Check ftps3 images to remove 01
	if ( $media['colorized_original'] !== '0' ) {
		if ( $media['colorized_count'] === '0' ) {
			update_colorized_count($media['style_id'], $media['colorized_original']);
			return true;
		}
		else if ( $media['colorized_count'] == $media['colorized_original'] ) {
			return true;
		} else {
			return false;
		}
	}

	return false;
}

function remove_cd_media( $media ) {
	global $db;
	$sql = "DELETE FROM media WHERE 
	style_id LIKE '{$media['style_id']}' AND
	type LIKE 'view' AND
	shot_code LIKE '{$media['shot_code']}' AND
	url LIKE '%media.chromedata%'
	";
	$db->query( $sql );
}

function del_tree($dirPath) {
	if (! is_dir($dirPath)) {
			throw new InvalidArgumentException("$dirPath must be a directory");
	}
	if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
	}
	$files = glob($dirPath . '*', GLOB_MARK);
	foreach ($files as $file) {
			if (is_dir($file)) {
					del_tree($file);
			} else {
					unlink($file);
			}
	}
	rmdir($dirPath);
}

function update_colorized_count( $style_id, $count ) {
	global $db;
	$sql = "UPDATE style SET colorized_count = {$count} WHERE style_id LIKE '{$style_id}'";
	$db->query($sql);
}

function garbage(){
	gc_enable();
  	gc_collect_cycles();
  	gc_disable();
}