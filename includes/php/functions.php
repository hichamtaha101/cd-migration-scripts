<?php 
include_once( dirname( __FILE__ ) . '/wpdb.php' );
include_once( dirname( __FILE__ ) . '/class-convertus-chrome-data-api.php' );
include_once( dirname( __FILE__ ) . '/class-convertus-kraken-s3.php' );
include_once( dirname( __FILE__ ) . '/class-ftp-s3.php' );
$db = new WPDB();
$obj = new Convertus_DB_Updater('CA', 'en');
$obj_fr = new Convertus_DB_Updater('CA', 'fr');

/************************* CHROME DATA FUNCTIONS *************************/

/**
 * This function queries the database for values needed to determine how far a model
 * is in the migration process from Chrome Data to our database.
 *
 * @return array	Array of models and their state in the migration.
 */
function get_updated_models() {
	global $db;
	$ftp_s3 = new FTP_S3($db);

	// Declare udpated variables
	$models = $models_updated = $views_updated = $ftp_s3_updated = $colorized_updated = array();

	// Get all models
	$models = $db->get_col("SELECT DISTINCT model_name_cd FROM model");

	$sql = "SELECT 
	s.model_name_cd, 
	IFNULL(s.view_count, 0) as view_count,
	IFNULL(m.view_images, 0) as view_images,
	IFNULL(mm.colorized_original, 0) as colorized_original,
	IFNULL(mmm.colorized_images, 0) as colorized_images
	FROM (SELECT model_name_cd, SUM(view_count) as view_count FROM style WHERE has_media = 1 GROUP BY model_name_cd ) s
	LEFT JOIN (SELECT model_name_cd, COUNT(*) as view_images FROM media WHERE url LIKE '%amazonaws.com%' AND type = 'view' GROUP BY model_name_cd ) m ON m.model_name_cd = s.model_name_cd
	LEFT JOIN (SELECT model_name_cd, COUNT(*) as colorized_original FROM media WHERE url LIKE '%amazonaws.com/original%' GROUP BY model_name_cd ) mm ON mm.model_name_cd = s.model_name_cd
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
		if ( intval($model['colorized_original']) !== 0 ) {
			$ftp_s3_updated[] = $model['model_name_cd'];
		}

		// These models have optimized and stored all colorized images
		if ( ( $model['colorized_original'] * 8 ) === intval($model['colorized_images']) ) {
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
	$start_time = microtime(true); 
	$results = array();
	$obj->update_divisions();
	$results['outputs'] = $obj->outputs;
	$end_time = microtime(true);
	return array( $results, '<pre>Updating all makes took: <strong>' . strval($end_time - $start_time) . ' seconds</strong>.</pre>' );
}

/**
 * Calls the chrome-data-api instance to update all models in the database.
 *
 * @return array	Output array for the front-end to display.
 */
function update_all_models() {
	global $obj;
	global $obj_fr;
	$start_time = microtime(true); 
	$obj->update_models();
	$obj_fr->update_models();
	$results = get_updated_models();
	$results_fr = get_updated_models();
	$results['outputs'] = $obj->outputs;  
	$results_fr['outputs'] = $obj_fr->outputs;  
	$end_time = microtime(true);
	return [$results,$results_fr,'<pre>Updating all models took: <strong>' . strval($end_time - $start_time) . ' seconds</strong>.</pre>'];
}

function update_database_structure() {
	global $db;

	$queries = array(
		'CREATE TABLE `style_fr` LIKE `style`;',
		'CREATE TABLE `standard_fr` LIKE `standard`',
		'CREATE TABLE `option_fr` LIKE `option`',
		'CREATE TABLE `engine_fr` LIKE `engine`',
		'CREATE TABLE `exterior_color_fr` LIKE `exterior_color`',
		'ALTER TABLE `model` ADD `model_name_cd_fr` VARCHAR(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL AFTER `model_name_cd`;',
		'ALTER TABLE `region` ADD `name_fr` VARCHAR(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL AFTER `postal_code`;',
		'UPDATE `region` SET `name_fr` = \'Alberta\' WHERE `region`.`id` = 1;',
		'UPDATE `region` SET `name_fr` = \'Colombie-Britannique\' WHERE `region`.`id` = 2;',
		'UPDATE `region` SET `name_fr` = \'Manitoba\' WHERE `region`.`id` = 3;',
		'UPDATE `region` SET `name_fr` = \'Nouveau-Brunswick\' WHERE `region`.`id` = 4;',
		'UPDATE `region` SET `name_fr` = \'Terre-Neuve-et-Labrador\' WHERE `region`.`id` = 5;',
		'UPDATE `region` SET `name_fr` = \'Territoires du Nord-Ouest\' WHERE `region`.`id` = 6;',
		'UPDATE `region` SET `name_fr` = \'Nouvelle-Écosse\' WHERE `region`.`id` = 7;',
		'UPDATE `region` SET `name_fr` = \'Nunavut\' WHERE `region`.`id` = 8;',
		'UPDATE `region` SET `name_fr` = \'Ontario\' WHERE `region`.`id` = 9;',
		'UPDATE `region` SET `name_fr` = \'Île-du-Prince-Édouard\' WHERE `region`.`id` = 10;',
		'UPDATE `region` SET `name_fr` = \'Québec\' WHERE `region`.`id` = 11;',
		'UPDATE `region` SET `name_fr` = \'Saskatchewan\' WHERE `region`.`id` = 12;',
		'UPDATE `region` SET `name_fr` = \'Yukon\' WHERE `region`.`id` = 13;',
	);

	foreach ( $queries as $query ) {
		$db->query( $query );
	}

	return 'Finished updating database structure.';
}

function update_everything_for_model($model, $year) {

	// Script breaks if something goes wrong in the following functions
	$response1 = update_styles( $model, 'true', $year );
	echo '<pre>' , var_dump($response1), '</pre>';
	$testlog = fopen("test.txt", "a");
    $text = 'step 1 finished for ' . $model;
    fwrite($testlog, "\n" . $text);
	fclose($testlog);
	
	$response2 = update_model_images( $model, 'view' );
	echo '<pre>' , var_dump($response2), '</pre>';
    $testlog = fopen("test.txt", "a");
    $text = 'step 2 finished at for ' . $model;
    fwrite($testlog, "\n" . $text);
	fclose($testlog);
	
	$response3 = update_ftps3( $model );
	echo '<pre>' , var_dump($response3), '</pre>';
    $testlog = fopen("test.txt", "a");
    $text = 'step 3 finished at for ' . $model;
    fwrite($testlog, "\n" . $text);
	fclose($testlog);

	
	$response4 = update_model_images( $model, 'colorized' );
	echo '<pre>' , var_dump($response4), '</pre>';
	$testlog = fopen("test.txt", "a");
    $text = 'step 4 finished at for ' . $model . ' ---------------------- DONE ---------------------- ';
    fwrite($testlog, "\n" . $text);
	fclose($testlog);

	$outputs = array( array(
		'type'	=> 'success',
		'msg'	=> 'Beep boop ' . $model
	) );
	
	return array(
		'update'	=> array(
			'key' 		=> 'models',
			'data' 		=> $model,
		),
		'outputs'	=> $outputs
	);
}

/**
 * Calls the chrome-data-api instance to update all styles for the model passed in.
 *
 * @param string $model				The Chrome Data model name the styles are being updated for.
 * @param boolean $remove_media		Whether to remove ALL media entries when updating. Default removes Chrome Data ones only.
 * @return object					The updated object used for front-end display.
 */
function update_styles( $model, $remove_media, $year ) {
	global $obj;
	global $obj_fr;

	$filter = "model_name_cd = '{$model}'";
	$filter .= ! empty( $year ) ? ' AND model_year = ' . $year : '';
	
	$styles = $obj->get_model_details( $filter );
	$styles_fr = $obj_fr->get_model_details( $filter );
	if ( $styles !== false ) {
		$obj->update_styles( $styles, $remove_media );
		if ( $styles_fr !== false ) {
			$obj_fr->update_styles( $styles_fr, $remove_media );
			return array(
				'update'	=> array(
					'key' 		=> 'styles',
					'data' 		=> $model,
				),
				'outputs'	=> $obj->outputs,
				'outputs_fr'	=> $obj_fr->outputs
			);
		}
		return array(
			'update'	=> array(
				'key' 		=> 'styles',
				'data' 		=> $model,
			),
			'outputs'	=> $obj->outputs
		);
	} else {
		return array(
			'outputs'	=> $obj->outputs
		);
	}
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
	global $db;
	$k_s3 = new Convertus_Kraken_S3($db);
	
	// Grab media that need updating
	if ( $type === 'view' ) {
		$media = get_chromedata_media_by_model( $model );
	} elseif ( $type === 'colorized' ) {
		$media = get_colorized_media_by_model( $model );
	}

	if ( ! $media['pass'] ) {
		return $media['outputs'];
	} 
	$media = $media['media'];

	// Optimize and store all media images here
	$result = $k_s3->update_images( $media, $type );
	$outputs = array();
	$outputs[0]['type'] = 'success';
	$outputs[0]['msg'] = 'Updated all ' . $model . ' ' . $type . ' images in s3 and database';
	if ( $result === FALSE ) {
		$outputs[0]['msg'] = 'Already updated all ' . $model . ' ' . $type . ' images in s3 and database';
	}
	return array(
		'update'	=> array(
			'key' 		=> $type,
			'data' 		=> $model,
		),
		'outputs'	=> $outputs
	);
}

/**
 * This function grabs all 01 shotcode media entries in our database that still needs colorized
 * images pulled from the Chrome Data FTP, stored on S3, then referenced in our database.
 *
 * @param string $model	The Chrome Data model name the script is grabbing the colorized images for.
 * @return object		The updated object used for front-end display.
 */
function update_ftps3( $model ) {
	global $db;
	$ftp_s3 = new FTP_S3($db);

	$media = get_chromedata_media_by_model( $model );
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
				'key'	=> 'ftps3',
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

/**
 * This function grabs all media records from the database that needs to be updated in regards to view images, or colorized images.
 * If a media entry is already updated for the type specified, that entry is removed from the database via the remove_cd_media function.
 *
 * @param string $model	The Chrome Data model name that is being queried for in the database.
 * @return array		An array of media objects that need to be optimized, stored on S3, and re-referenced in our database.
 * 						This function returns false if there are no media objects, or the model does not exist.
 */
function get_chromedata_media_by_model( $model ) {
	global $db;

	$results = array();
	$outputs = array( array(
		'type'	=> 'error', 
		'msg'	=> 'Could not find any chromedata media entries for ' . $model . ' in database.' 
	));

	$sql = "SELECT
	m.*,
	IFNULL(b.view_images, 0) as view_images,
	IFNULL(c.colorized_original, 0) as colorized_original
	FROM ( SELECT * FROM media WHERE url LIKE '%media.chromedata%' AND model_name_cd LIKE '{$model}') m
	LEFT JOIN ( SELECT style_id, file_name, COUNT(*) as view_images FROM media WHERE url LIKE '%amazonaws.com/media%' AND type = 'view' GROUP BY style_id, file_name ) b ON ( b.style_id = m.style_id AND b.file_name = m.file_name )
	LEFT JOIN ( SELECT style_id, REPLACE(file_name, CONCAT('_',color_option_code), '') as file_name_original, COUNT(*) as colorized_original FROM media WHERE url LIKE '%amazonaws.com/original%' AND type = 'colorized' GROUP BY style_id, file_name_original ) c ON ( c.style_id = m.style_id AND c.file_name_original = m.file_name )";
	$media = $db->get_results($sql, ARRAY_A);

	// Check if Model exists
	if ( ! $media ) {
		return array(
			'pass'		=> FALSE,
			'outputs' 	=> $outputs 
		);
	}

	// Remove chromedata images if updated views and ftp to s3
	$delete_sql = "DELETE FROM showroom.media WHERE ";
	$delete_values = array();

	foreach( $media as $m ) {
		if ( cd_media_is_updated( $m ) ) {
			$delete_values[] = remove_cd_media_sql($m);
		} else {
			$results[] = $m;
		}
	}

	// Delete any updated cd media entries
	if ( count($delete_values) > 0 ) {
		$db->query($delete_sql . implode( ' OR ', $delete_values ) );
	}

	// No media entries found
	if ( count( $results ) === 0 ) {
		$outputs['msg'] = 'Already updated ' . $model . ' in database for view images.';
		return array(
			'pass'		=> FALSE, 
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
 * This function checks to see if the media object has been fully updated in terms of view images and colorized images.
 *
 * @param object $media The media object being checked.
 * @return boolean		Whether the media object has been fully optimized, updated on s3, and updated on db.
 */
function cd_media_is_updated($media){
	$pass = TRUSE;

	// Each media should have 8 view images ( lg, md, sm, xs ) x ( jpg, png ) when migrated
	if ( intval( $media['view_images'] ) !== IMAGES_PER_REQUEST * 2 ) { $pass = FALSE; }

	// Return if shotcode is not 1, otherwise check if it has the correct colorized images migrated
	if ( $media['shot_code'] !== '1' || ! $pass ) { 
		return $pass; 
	}

	// Remove CD shotcode 1 media if colorized_original not equal to 0
	if ( intval( $media['colorized_original'] ) === 0 ) {
		$pass = FALSE;
	}

	return $pass;
}


/**
 * Undocumented function
 *
 * @param object $model
 * @return object
 */
function get_colorized_media_by_model( $model ) {
	global $db;

	$results = array();
	$outputs = array( array(
		'type'	=> 'error', 
		'msg'	=> 'Could not find any original media entries for ' . $model . ' in database.' 
	));

	$sql = "SELECT
	m.*,
	IFNULL(a.colorized_images, 0) as colorized_images
	FROM ( SELECT * FROM media WHERE url LIKE '%amazonaws.com/original%' AND model_name_cd LIKE '{$model}') m
	LEFT JOIN ( SELECT style_id, file_name, COUNT(*) as colorized_images FROM media WHERE url LIKE '%amazonaws.com/media%' AND type = 'colorized' GROUP BY style_id, file_name ) a ON ( a.style_id = m.style_id AND a.file_name = m.file_name )";
	$media = $db->get_results($sql, ARRAY_A);

	// Check if Model exists
	if ( ! $media ) {
		return array(
			'pass'		=> FALSE,
			'outputs' 	=> $outputs 
		);
	}

	// Ignore not updated entries, but don't delete
	foreach ( $media as $m ) {
		if ( intval($m['colorized_images']) !== 8 ) {
			$results[] = $m;
		}
	}

	// No original images need optimizing
	if ( count( $results ) === 0 ) {
		$outputs['msg'] = 'Already updated ' . $model . ' in database for colorized images.';
		return array(
			'pass'		=> FALSE, 
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
 * Removes the media record for the media table.
 *
 * @param object $media	The media object being removed.
 * @return void
 */
function remove_cd_media_sql( $media ) {
	return "( style_id = {$media['style_id']}
	AND type = 'view'
	AND shot_code = {$media['shot_code']}
	AND file_name = '{$media['file_name']}'
	AND url LIKE '%media.chromedata.com%' )";
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
 * Collect em garbos. Memory cleanup n stuff.
 *
 * @return void
 */
function garbage(){
	gc_enable();
  	gc_collect_cycles();
  	gc_disable();
}

function display_var( $var ) {
	echo '<pre>'; var_dump( $var ); echo '</pre>';
	exit();
}