<?php 
error_reporting(E_ALL);
include_once( 'functions.php' );

// Some SQL queries are pretty big
// $max = 100 * 1024 * 1024;
// $current = $obj->db->get_var('SELECT @@global.max_allowed_packet');
// if ( $current < $max ) { $obj->db->query('SET @@global.max_allowed_packet = ' . $max ); }

/*
1. SELECT * FROM `style` WHERE model_name LIKE 'Explorer' : Styles updated
2. SELECT * FROM `media` WHERE model_name = 'Explorer' AND url LIKE '%amazonaws.com/media%' : Optimized view images
3. SELECT * FROM `media` WHERE model_name = 'Explorer' AND url LIKE '%chromedata%' : One images
4. SELECT * FROM `media` WHERE model_name = 'Explorer' AND url LIKE '%amazonaws.com/original%' : Colorized Original
5. SELECT * FROM `media` WHERE model_name = 'Explorer' AND url LIKE '%amazonaws.com/media%' AND type = 'colorized' : Colorized Images
*/

// update_everything_for_model('TT RS Coupe', 2019);

/////////////////// ---------- for updating french data on live db ---------- //////////////////

// 1. ----- ADD THE NEW FRENCH TABLES AND UPDATE MODEL/REGION 
// update_database_structure();

// 2. ----- UPDATE MODEL TO HAVE FRENCH MODEL_NAME_CD
// update_all_models();

// 3. ----- UPDATE THE FRENCH STYLES/STANDARD EQUIPMENT/OPTIONS/EXT COLORS/ENGINES
// $models = array();
// $models = $db->get_col("SELECT DISTINCT model_name_cd FROM model WHERE division_name LIKE 'Honda'");
// echo '<pre>' , var_dump($models), '</pre>';

// $models = $db->get_col("SELECT DISTINCT model.model_name_cd FROM model LEFT JOIN media ON model.model_name_cd = media.model_name_cd AND media.model_year > 2018 WHERE media.model_name_cd is null");
// $models = $db->get_col("SELECT DISTINCT model_name_cd FROM model WHERE division_name LIKE 'Volkswagen' AND model_name LIKE 'Beetle'");
// $models = $db->get_col("SELECT DISTINCT model_name_cd FROM model WHERE model_year = 2020");
$models = array(
  'Range Rover',
  'Range Rover Velar'

);

foreach( $models as $index=>$model ) {
  if ( $index >= 0 ) {
    echo '<pre>' , var_dump($index), ': ', var_dump($model) , '</pre>';
    // update_styles( $model, false );
    update_everything_for_model( $model, 2020 );
  }
}
	// Script breaks if something goes wrong in the following functions
	// var_dump( update_styles( 'Silverado 1500', false ));
	// var_dump( update_model_images( 'Silverado 1500', 'view' ));
	// var_dump( update_ftps3( '1500' ));
	// var_dump( update_model_images( '1500', 'colorized' ));



// update_all_makes();
// update_all_models();
// $models = array(

//   'Accord',
//   'CR-V',
//   'Civic',

// );

// foreach( $models as $index=>$model ) {
//   if ( $index >= 0 ) {
//     update_everything_for_model( $model );
//   }
// }
// var_dump(update_all_makes());
// update_everything_for_model('Q5');
// var_dump( update_ftps3( 'Expedition' ));
// var_dump( update_model_images( 'Expedition', 'view' ));
/////////////////// ---------- end updating french data on live db ---------- //////////////////

//--------------- UPDATE CRON_SCHEDULER TABLE

// $db->query( "ALTER TABLE `cron_scheduler` ADD `model_name` VARCHAR(25) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL AFTER `division_name`;" );
// update_all_makes();
// update_all_models();
// $today = date( 'Y-m-d' );
// $db->query( "INSERT INTO `cron_scheduler` VALUES ( '', null, '', 'makes', '{$today}', '1 Week', '12:00:00', '', 0 ) " );
// $db->query( "INSERT INTO `cron_scheduler` VALUES ( '', null, '', 'models', '{$today}', '1 Week', '12:00:00', '', 0 ) " );

// $models = array();
// $models = $db->get_col("SELECT DISTINCT model_name_cd FROM model");
// var_dump($models);

// foreach( $models as $index=>$model ) {
//   $sql = "INSERT INTO `cron_scheduler` VALUES ( '',null,'{$model}', 'styles', '{$today}','1 Week','12:00:00', '', 0 )";
//   $db->query($sql);
//   echo '<pre>', var_dump('inserted ', $model) , '</pre>';
  
// }

//--------------- end UPDATE CRON_SCHEDULER TABLE

function update_all_body_styles() {
  global $obj;
  $sql = "SELECT DISTINCT body_type FROM style";
  $body_types = $obj->db->get_col( $sql );

  foreach ( $body_types as $bs ) {
    $sql = "UPDATE style SET body_type_standard = '{$obj->body_types[$bs]}' WHERE body_style = '{$bs}'";
    echo $sql . '<br>';
    $obj->db->query($sql);
  }
}

// $all_models = compare_models();
function compare_models() {
  global $obj;
  $sql = "SELECT DISTINCT model_name FROM model";
  $cmodels = $obj->db->get_col($sql);

  $response = get_dealertrend_models();
  foreach ( $response as $model ) {
    $dmodels[] = $model['name'];
  }

  // Get cdmodels that aren't in dmodels
  $diff = array_diff($cmodels, $dmodels);
  sort($diff);

  return array(
    'dealertrend' => $dmodels,
    'chromedata'  => $cmodels,
    'diff'        => $diff
  );
}

// update_model_names();
function update_model_names() {
  global $obj;

  $sql = "SELECT DISTINCT model_name_cd FROM model";
  $models = $obj->db->get_col($sql);

  // Correct model names in each table
  foreach ( array( 'model', 'style', 'media' ) as $table ) {
    foreach ( $models as $name ) {
      if ( array_key_exists( $name, $obj->standard_models ) ) {
        $sql = "UPDATE {$table} SET model_name = '{$obj->standard_models[$name]}' WHERE model_name = '{$name}'";
        $obj->db->query($sql);
        echo 'updated ' . $name . ' to ' . $obj->standard_models[$name] . '<br>';
      }
    }
  }
}

// update_crap();
function update_crap() {
  global $obj;

  $sql = "SELECT style_id, model_year FROM style";
  $styles = $obj->db->get_results( $sql, ARRAY_A );

  foreach ( $styles as $style ) {
    $sql = "UPDATE media SET model_year = '{$style['model_year']}' WHERE style_id = '{$style['style_id']}'";
    $obj->db->query($sql);
  }
  echo 'Updated media table records to have correct modeL_year values';
}


function get_dealertrend_models() {
  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => "http://vrs.dealertrend.com/models.json?country_code=CA&year=2018",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => array(
      "cache-control: no-cache",
      "postman-token: 4b17e1fa-b786-a015-a577-eb67ad0d42c2"
    ),
  ));

  $response = curl_exec($curl);
  $err = curl_error($curl);

  curl_close($curl);

  if ($err) {
    echo "cURL Error #:" . $err;
  } else {
    return json_decode( $response, TRUE );
  }
}

?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css">
<div class="row">
  <div class="col-md-6 col-sm-6 col-xs-12">
    <?php //var_dump($all_models['diff']); ?>
  </div>
  <div class="col-md-6 col-sm-6 col-xs-12">
    <?php //var_dump($all_models['dealertrend']); ?>
  </div>
</div>
