<?php 
error_reporting(E_ALL);
include_once( 'functions.php' );

// Some SQL queries are pretty big
$max = 100 * 1024 * 1024;
$current = $obj->db->get_var('SELECT @@global.max_allowed_packet');
if ( $current < $max ) { $obj->db->query('SET @@global.max_allowed_packet = ' . $max ); }
// $obj->db->query('TRUNCATE style');

// get_updated_models();
// $makes = $obj->get_models();
// var_dump( $makes );
// exit();

$models = get_updated_models();
echo '<pre>'; var_dump( $models ); echo '</pre>';
exit();
// $models = $obj->update_models();

// var_dump($obj->test());
// $styles = $obj->get_model_details("model_name_cd LIKE 'Super Duty F-350 SRW'");
// // echo '<pre>'; var_dump( $styles ); echo '</pre>';
// $obj->update_styles( $styles, 'true' );
// exit();

// $results = update_styles_by_model( 'M4', 'false' );

// update_model_images('A4 allroad', 'view');
// exit();

// $test = update_styles_by_model( 'CTS Sedan', 'false' );
// var_dump( $test );
// exit();

// var_dump( get_updated_models() );
// exit();

get_chromedata_media_by_model('Super Duty F-350 SRW', 'view');

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
