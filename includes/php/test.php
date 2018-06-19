<?php 
error_reporting(E_ALL);
include_once( 'functions.php' );

// Some SQL queries are pretty big
$max = 100 * 1024 * 1024;
$current = $obj->db->get_var('SELECT @@global.max_allowed_packet');
if ( $current < $max ) { $obj->db->query('SET @@global.max_allowed_packet = ' . $max ); }
// $obj->db->query('TRUNCATE style');

//$results = $obj->get_model_details("model_name LIKE 'M4s'");
//$results = update_styles_by_model('M4');

// $styles = $obj->get_model_details( "model_name = '1500'" );
// foreach ( $styles as $style ) {
//   display_var( $style['style']['has_media'] );
//   display_var( $style['style']['view'] );
// }
// exit();
// $obj->update_styles( $styles );

// update_views_by_model('Super Duty F-350 SRW');
// update_ftps3_by_model('Sierra 3500HD');
// display_var(update_colorized_by_model('124 Spider'));

// $models = $obj->get_models(5);
// display_var( $models );
exit();

function update_all_body_styles() {
  global $obj;
  $sql = "SELECT DISTINCT body_type FROM style";
  $body_types = $obj->db->get_col( $sql );

  foreach ( $body_types as $bs ) {
    $sql = "UPDATE style SET body_type_standard = '{$obj->body_types[$bs]}' WHERE body_style = '{$bs}'";
    echo $sql . '<br>';
    continue;
    $obj->db->query($sql);
  }
}

// $all_models = update_all_model_names();
function update_all_model_names() {
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

  // Correct model names in each table
  foreach ( array( 'model', 'style', 'media' ) as $table ) {
    foreach ( $diff as $d ) {
      if ( array_key_exists( $d, $obj->standard_models ) ) {
        $sql = "UPDATE {$table} SET model_name = '{$obj->standard_models[$d]}' WHERE model_name = '{$d}'";
        $obj->db->query($sql);
        echo 'updated ' . $d . ' to ' . $obj->standard_models[$d] . '<br>';
      } else {
        // echo 'New model to standardize: ' . $d . '<br>';
      }
    }
  }

  return array(
    'dealertrend' => $dmodels,
    'chromedata'  => $cmodels,
    'diff'        => $diff
  );
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
