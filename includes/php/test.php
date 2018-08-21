<?php 
error_reporting(E_ALL);
include_once( 'functions.php' );

// Some SQL queries are pretty big
$max = 100 * 1024 * 1024;
$current = $obj->db->get_var('SELECT @@global.max_allowed_packet');
if ( $current < $max ) { $obj->db->query('SET @@global.max_allowed_packet = ' . $max ); }
// $obj->db->query('TRUNCATE style');

// $makes = $obj->get_divisions();
// display_var( $makes );
// exit();

// $models = $obj->update_models();

// $results = $obj->get_model_details("model_name LIKE 'MDX' AND model_year = 2018");
// display_var( $results );
// exit();
// $results = update_styles_by_model('M4');

// $styles = $obj->get_model_details( "model_name = 'ILX' AND model_year = 2017" );
// foreach ( $styles as $style ) {
//   display_var( $style['style']['has_media'] );
//   display_var( $style['style']['view'] );
// }
// exit();
// $obj->update_styles( $styles );

// update_views_by_model('Super Duty F-350 SRW');
// update_ftps3_by_model('Sierra 3500HD');
// display_var(update_colorized_by_model('124 Spider'));
// exit();

// $test = update_styles_by_model( 'CTS Sedan' );
// display_var( $test );
// exit();

// display_var( get_updated_models() );
// exit();

display_var( update_ftps3_by_model( 'Q7' ) );
exit();

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

// update_model_name_cd();
function update_model_name_cd() {
  global $obj;

  $sql = "SELECT style_id, model_name_cd FROM style";
  $styles = $obj->db->get_results( $sql, ARRAY_A );

  foreach ( $styles as $style ) {
    $sql = "UPDATE media SET model_name_cd = '{$style['model_name_cd']}' WHERE style_id = '{$style['style_id']}'";
    $obj->db->query($sql);
  }
  echo ' updated media table records to have correct model_name_cd values';
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
    <?php //display_var($all_models['diff']); ?>
  </div>
  <div class="col-md-6 col-sm-6 col-xs-12">
    <?php //display_var($all_models['dealertrend']); ?>
  </div>
</div>
