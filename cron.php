<?php
require_once( getcwd() . '/includes/php/functions.php' );

global $db;

date_default_timezone_set('America/Los_Angeles');
$now = date( 'Y-m-d H:i:s' );

// ------------------------------------ LOG ------------------------------------------
$cronlog = fopen("cron.txt", "a");
$text = 'Last run started at: ' . $now . ' for:';
fwrite($cronlog, "\n" . $text);
fclose($cronlog);
// ------------------------------------ TIME OUT -------------------------------------

// Run another process if current running process has been running too long 
// Set 'running' back to 0 if running=1 and run_time > x-time past the current time
// For makes and models -- x-time = 5 mins
// For styles ------------ x-time = 4 hours

$db->query( "UPDATE cron_scheduler SET run_time = '{$now}', running = 0 WHERE TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 5 AND cron_type = 'makes' AND frequency != '' AND running = 1" );

$db->query( "UPDATE cron_scheduler SET run_time = '{$now}', running = 0 WHERE TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 5 AND cron_type = 'models' AND frequency != '' AND running = 1" );

$db->query( "UPDATE cron_scheduler SET run_time = '{$now}', running = 0 WHERE TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 360 AND cron_type = 'styles' AND frequency != '' AND running = 1" );

// -------------------------------- UPDATING ALL MAKES --------------------------------

$sql_statement = "SELECT cron_id, cron_type, run_time, frequency, start_time, last_run, running FROM `cron_scheduler` WHERE ( cron_type = 'makes' AND running = 0 AND frequency != '' AND TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 0)";
$result = $db->get_results( $sql_statement );

if ( sizeOf( $result ) > 0 ) {
    if ( is_array( $result ) ) {
        $cron = $result[0];
    } else {
        $cron = $result;
    }    
    $db->query( "UPDATE cron_scheduler SET running = 1 WHERE cron_id =".$cron->cron_id );
    if ( update_all_makes()[0]['outputs'][0]['type'] == 'success' ) {
        update_cron_scheduler( $cron->cron_id, $cron->frequency );
        $cronlog = fopen("cron.txt", "a");
        $text = ' makes;';
        fwrite($cronlog, $text);
        fclose($cronlog);
    }
} else {
    echo '<pre>Makes don\'t need updating yet.</pre>';
}

// -------------------------------- UPDATING ALL MODELS --------------------------------

$sql_statement = "SELECT cron_id, cron_type, run_time, frequency, start_time, last_run, running FROM cron_scheduler WHERE ( cron_type = 'models' AND frequency != '' AND running = 0 AND TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 0) LIMIT 1";
$result = $db->get_results( $sql_statement );

if ( sizeOf( $result ) > 0 ) {
    if ( is_array( $result ) ) {
        $cron = $result[0];
    } else {
        $cron = $result;
    }
    $db->query( "UPDATE cron_scheduler SET running = 1 WHERE cron_id =".$cron->cron_id );
    if ( update_all_models()[0]['outputs'][0]['type'] == 'success' ) {
        update_cron_scheduler( $cron->cron_id, $cron->frequency );
        $cronlog = fopen("cron.txt", "a");
        $text = ' models;';
        fwrite($cronlog, $text);
        fclose($cronlog);
    }
} else {
    echo '<pre>Models don\'t need updating yet.</pre>';
}

// -------------------------------- UPDATING STYLES OF MODELS --------------------------------

$sql_statement = "SELECT cron_id, cron_type, model_name, run_time, frequency, start_time, last_run, running FROM cron_scheduler WHERE ( cron_type = 'styles' AND frequency != '' AND running = 0 AND TIMESTAMPDIFF(MINUTE, run_time, '{$now}') > 0) LIMIT 1";
$result = $db->get_results( $sql_statement );

if ( sizeOf( $result ) > 0 ) {
    if ( is_array( $result ) ) {
        $cron = $result[0];
    } else {
        $cron = $result;
    }
    $start_time = microtime(true); 
    $db->query( "UPDATE cron_scheduler SET running = 1 WHERE cron_id =".$cron->cron_id );
    update_everything_for_model( $cron->model_name );
    update_cron_scheduler( $cron->cron_id, $cron->frequency );
    $end_time = microtime(true);
    echo '<pre>Updated ', $cron->model_name, ' in <strong>', strval( ( $end_time - $start_time )/60 ), ' minutes</strong>.</pre>';
    $cronlog = fopen("cron.txt", "a");
    $text = ' ' . $cron->model_name . ' in ' . strval( ( $end_time - $start_time )/60 ) . ' minutes.';
    fwrite($cronlog, $text);
    fclose($cronlog);
} else {
    echo '<pre>Styles don\'t need updating yet.</pre>';
}

if ( $db->error ) {
	set_error( 'DATABASE', $db->error );
}

//////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////// HELPER FUNCTIONS //////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////

// UPDATE CRON SCHEDULER ---------------------------------------------------------------------
// Used to update next run time, last completed run, and running flag in cron_scheduler table
// upon completion of the task. 

function update_cron_scheduler( $cron_id, $freq ) {
    global $db;
    $now = date( 'Y-m-d H:i:s' );
    $next_run = date('Y-m-d H:i:s', strtotime('+' . strtolower($freq)));

    $sql_statements = array(
        'UPDATE cron_scheduler SET run_time = "' . $next_run . '" WHERE cron_id = ' . $cron_id . ';',
        'UPDATE cron_scheduler SET last_run = "' . $now . '" WHERE cron_id = ' . $cron_id . ';',
        'UPDATE cron_scheduler SET running = 0 WHERE cron_id = ' . $cron_id . ';'
    );
    foreach( $sql_statements as $s ) {
        $db->query( $s );
    }
}

?>