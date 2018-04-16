<?php 
error_reporting(E_ALL);
include_once( dirname( __FILE__ ) . '/includes/php/functions.php' );
echo '<h2>Test</h2>';

// Some SQL queries are pretty big
$max = 100 * 1024 * 1024;
$current = $obj->db->get_var('SELECT @@global.max_allowed_packet');
if ( $current < $max ) { $obj->db->query('SET @@global.max_allowed_packet = ' . $max ); }

//$results = $obj->get_model_details("model_name LIKE 'M4s'");
//$results = update_styles_by_model('M4');

//$styles = $obj->get_model_details( "model_name LIKE 'Traverse'" );
//echo '<pre>'; var_dump( $styles ); echo '</pre>';
//$obj->update_styles( $styles );

$test = update_styles_by_model( 'Soul EV' );
echo '<pre>'; var_dump( $test ); echo '</pre>';

//$styles = $obj->get_model_details( "model_name LIKE 'Transit Connect Van'" );

?>