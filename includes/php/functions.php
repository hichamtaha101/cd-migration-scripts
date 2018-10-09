<?php 
include_once( dirname( __FILE__ ) . '/wpdb.php' );
include_once( dirname( __FILE__ ) . '/class-convertus-chrome-data-api.php' );
include_once( dirname( __FILE__ ) . '/class-convertus-kraken-s3.php' );
include_once( dirname( __FILE__ ) . '/class-ftp-s3.php' );
$db = new WPDB();
$obj = new Convertus_DB_Updater('CA');
$k_s3 = new Convertus_Kraken_S3($db);
$ftp_s3 = new FTP_S3($db);

/************************* CHROME DATA FUNCTIONS *************************/

/**
 * This function queries the database for values needed to determine how far a model
 * is in the migration process from Chrome Data to our database.
 *
 * @return array	Array of models and their state in the migration.
 */
function get_updated_models() {
	global $db;

	// Declare udpated variables
	$models = $models_updated = $views_updated = $ftp_s3_updated = $colorized_updated = array();

	// Get all models
	$models = $db->get_col("SELECT DISTINCT model_name_cd FROM model");

	$sql = "SELECT 
	s.model_name_cd, 
	IFNULL(s.view_count, 0) as view_count,
	IFNULL(s.colorized_count, 0) as colorized_count,
	IFNULL(m.view_images, 0) as view_images,
	IFNULL(mm.ftp_images_one, 0) as ftp_images_one,
	IFNULL(mmm.colorized_images, 0) as colorized_images
	FROM (SELECT model_name_cd, SUM(view_count) as view_count, SUM(colorized_count) as colorized_count FROM style WHERE has_media = 1 GROUP BY model_name_cd ) s
	LEFT JOIN (SELECT model_name_cd, COUNT(*) as view_images FROM media WHERE url LIKE '%amazonaws.com%' AND type = 'view' GROUP BY model_name_cd ) m ON m.model_name_cd = s.model_name_cd
	LEFT JOIN (SELECT model_name_cd, COUNT(*) as ftp_images_one FROM media WHERE url LIKE '%amazonaws.com/original%' GROUP BY model_name_cd ) mm ON mm.model_name_cd = s.model_name_cd
	LEFT JOIN (SELECT model_name_cd, COUNT(*) as colorized_images FROM media WHERE url LIKE '%amazonaws.com%' AND url NOT LIKE '%/original/%' AND type = 'colorized' GROUP BY model_name_cd) mmm ON mmm.model_name_cd = s.model_name_cd";
	$results = $db->get_results($sql, ARRAY_A);

	foreach ( $results as $model ) {
		// These models have at least one style ( therefore have been migrated from chromedata )
		$models_updated[] = $model['model_name_cd'];

		// Get models have optimized and stored all view images
		if ( ( $model['view_count'] * 8 ) === intval($model['view_images']) ) {
			$views_updated[] = $model['model_name_cd'];
		}

		// These models have grabbed all colorized shot code image ftp images
		if ( $model['ftp_images_one'] === $model['colorized_count'] ) {
			$ftp_s3_updated[] = $model['model_name_cd'];
		}

		// These models have optimized and stored all colorized images
		if ( ( $model['colorized_count'] * 8 ) === intval($model['colorized_images']) ) {
			$colorized_updated[] = $model['model_name_cd'];
		}
	}

	return array(
		'models' 	=> $models,
		'updated'	=> array(
			'styles' 		=> $models_updated,
			'view'			=> $views_updated,
			'ftps3'			=> $ftp_s3_updated,
			'colorized'		=> $colorized_updated,
		),
	);
}

/**
 * Calls the chrome-data-api instance to update all makes in the database.
 *
 * @return array	Output array for the front-end to display.
 */
function update_all_makes() {
	global $obj;
	$obj->update_divisions();
	$results['outputs'] = $obj->outputs;
	return $results;
}

/**
 * Calls the chrome-data-api instance to update all models in the database.
 *
 * @return array	Output array for the front-end to display.
 */
function update_all_models() {
	global $obj;
	$obj->update_models();
	$results = get_updated_models();
	$results['outputs'] = $obj->outputs;  
	return $results;
}

/**
 * Calls the chrome-data-api instance to update all styles for the model passed in.
 *
 * @param string $model				The Chrome Data model name the styles are being updated for.
 * @param boolean $remove_media		Whether to remove ALL media entries when updating. Default removes Chrome Data ones only.
 * @return object					The updated object used for front-end display.
 */
function update_styles( $model, $remove_media ) {
	global $obj;
	
	$styles = $obj->get_model_details( "model_name_cd LIKE '{$model}'" );
	$obj->update_styles( $styles, $remove_media );
	$results['update'] = array( 
		'key' => 'styles', 
		'data' => $model 
	);
	$results['outputs'] = $obj->outputs;

	return $results;
}

/**
 * This function grabs all media entries in the database that needs either view or colorized
 * images optimized, stored into our S3 bucket, then re-referenced in our database.
 *
 * @param string $model	The Chrome Data model name the images are being updated for.
 * @param string $type	What type of images are being updated ( view || colorized ).
 * @return object		The updated item used for front-end display.	
 */
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
			'key' 		=> $type,
			'data' 		=> $model,
		),
		'outputs'	=> $outputs
	);
	return $results;
}

/**
 * This function grabs all 01 shotcode media entries in our database that still needs colorized
 * images pulled from the Chrome Data FTP, stored on S3, then referenced in our database.
 *
 * @param string $model	The Chrome Data model name the script is grabbing the colorized images for.
 * @return object		The updated object used for front-end display.
 */
function update_ftps3( $model ) {
	global $ftp_s3;
	$media = get_chromedata_media_by_model( $model, 'view' );
	if ( ! $media['pass'] ) {
		return array(
			'outputs'	=> $media['outputs']
		);
	}

	$media = $media['media'];
	$test = $ftp_s3->copy_colorized_media_to_s3( $media );
	if ( $test ) {
		return array(
			'update'	=> array(
				'key'		=> 'ftps3',
				'data'	=> $model
			),
			'outputs'	=> $ftp_s3->outputs
		);
	} else {
		return array(
			'outputs'	=> $ftp_s3->outputs
		);
	}
	
}

function get_unique_media( $media ) {
	$new = $unique = array();
	foreach ( $media as $m ) {
		if ( ! in_array( $m['url'], $unique ) ) {
			$unique[] = $m['url'];
			$new[] = $m;
		}
	}
	return $new;
}

/**
 * This function grabs all media records from the database that needs to be updated in regards to view images, or colorized images.
 * If a media entry is already updated for the type specified, that entry is removed from the database via the remove_cd_media function.
 *
 * @param string $model	The Chrome Data model name that is being queried for in the database.
 * @param string $type	The media type being queried ( view || coloried ) for update.
 * @return array		An array of media objects that need to be optimized, stored on S3, and re-referenced in our database.
 * 						This function returns false if there are no media objects, or the model does not exist.
 */
function get_chromedata_media_by_model( $model, $type ) {
	global $db;

	$results = array();
	$outputs = array( array(
		'type'=>'error', 
		'msg'=>'Could not find any media entries for ' . $model . ' in database.' 
	));

	$sql = "SELECT 
	a.model_name_cd, a.model_name, a.style_id, a.type, a.url, a.shot_code, a.file_name, a.color_option_code, b.model_year,
	IFNULL(b.colorized_count, 0) as colorized_count,
	IFNULL(c.colorized_original, 0) as colorized_original
	FROM media a 
	LEFT JOIN (SELECT style_id, COUNT(url) as colorized_original FROM media WHERE url LIKE '%amazonaws.com/original/colorized%' GROUP BY style_id ) c
	ON a.style_id = c.style_id
	LEFT JOIN (SELECT style_id, colorized_count, model_year FROM style GROUP BY style_id) b
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
			'outputs' 	=> $outputs 
		);
	}

	// Remove duplicate media entries
	$media = get_unique_media( $media );

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

/**
 * This function checks to see whether the media object has the correct number of colorized images in the DB.
 * If it does, that means this object has already been updated.
 *
 * @param object $media	The media entry to check for.
 * @return boolean		Whether the media object has been updated or not.
 */
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

/**
 * This function checks to see if the media object has been fully updated in terms of view images and colorized images.
 *
 * @param object $media The media object being checked.
 * @return boolean		Whether the media object has been fully optimized, updated on s3, and updated on db.
 */
function cd_media_is_updated($media){
	global $db;
	$pass = TRUSE;

	// Each media is unique by style_id, shot_code, file_name ( possibly color_option_code )
	$sql = "SELECT count(style_id) FROM media WHERE
	style_id = '{$media['style_id']}' AND
	shot_code = '{$media['shot_code']}' AND
	file_name = '{$media['file_name']}' AND
	url LIKE '%amazonaws.com/media%' AND
	type = 'view'";
	$updated = $db->get_var( $sql );

	// Each media should have 8 images ( lg, md, sm, xs ) x ( jpg, png )
	if ( $updated != IMAGES_PER_REQUEST * 2 ) { $pass = FALSE; }

	// Return if shotcode is not 1 or failed first check for view images
	if ( $media['shot_code'] !== '1' || ! $pass ) { 
		return $pass; 
	}

	// This checks to see if ftp images were grabbed ( by referencing 01 images ) and removes them if they were
	// Colorized original equals 0 after ftp images were put in  downloads and before ftp to s3
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

/**
 * Removes the media record for the media table.
 *
 * @param object $media	The media object being removed.
 * @return void
 */
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

/**
 * Deletes directory and all contents in it.
 *
 * @param string $dirPath Path to the directory being deleted.
 * @return void
 */
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

/**
 * Update's the colorized_count column in the media table for a specific style.
 *
 * @param string $style_id	ID of style being modified.
 * @param integer $count	The new value.
 * @return void
 */
function update_colorized_count( $style_id, $count ) {
	global $db;
	$sql = "UPDATE style SET colorized_count = {$count} WHERE style_id LIKE '{$style_id}'";
	$db->query($sql);
}

/**
 * Collect em garbos. Memory cleanup n stuff.
 *
 * @return void
 */
function garbage(){
	gc_enable();
  	gc_collect_cycles();
  	gc_disable();
}