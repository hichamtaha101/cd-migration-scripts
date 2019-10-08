<?php 
error_reporting(E_ALL);
include_once '../functions.php';

class UpdateShowroom {

	private $url    = 'https://marcelo-showroom.achilles.ninja/wp-admin/admin-ajax.php?action=showroom_get_data&endpoint=models&imageSize=xs&language=en';
	private $models;
	private $years;
	private $times = [];

	function __construct() {
		$this->models = isset( $_GET['models'] ) ? explode( ',', $_GET['models'] ) : array();
		$this->years  = isset( $_GET['years'] ) ? explode( ',', $_GET['years'] ) : array();
		$this->run();
	}

	function run() {

		ob_implicit_flush(true);
		ob_start();
		
		if ( empty ( $this->years ) ) {
			array_push( $this->years, (int) date('Y') );
		}
		$modelsyears = array();

		foreach ( $this->models as $m ) {
			foreach ( $this->years as $y ) {
				array_push( $modelsyears, (object) array( 'year' => $y, 'model' => $m ) );
			}
		}

		if ( empty( $modelsyears ) ) {
			$modelsyears = $this->get_empty_models();
		}

		$this->update_models( $modelsyears );
		
		echo count( $modelsyears ) . '/' . count( $modelsyears );
		ob_end_flush(); 
	}

	function update_models( $modelsyears ) {
		foreach( $modelsyears as $key => $m ) {
			$remaining = count( $modelsyears ) - $key;
			if ( $key >= 0 ) {
				$time = time();
				echo "{$key}/". count( $modelsyears ) . ": $m->year $m->model<br>";
			
				update_everything_for_model( $m->model, $m->year );
				
				array_push( $this->times, time(true) - $time );
				$average         = array_sum( $this->times ) / count( $this->times );
				$total_remaining = ( $average * $remaining );
				$hours           = floor( $total_remaining / 3600 );
				$minutes         = floor( ( $total_remaining % 3600 ) / 60 );
				$seconds         = ( $total_remaining % 3600 ) % 60;
			
				echo "ETA: $hours h : $minutes m : $seconds<br>";
				flush();
				ob_flush();
			}
		}
	}

	function get_empty_models() {
		$models = is_json( file_get_contents( $this->url ), true );

		$data = $models->success ? $models->data : array();
		// print_r( $data );

		// $models = array_filter( $models, function( $m ) {
		// 	// print_r( $m );
		// 	return true;
		// });

		return $data;
	}
}

echo '<pre>';
$update = new UpdateShowroom();