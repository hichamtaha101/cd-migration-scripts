<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include_once('functions.php');

$fname = $_POST['fname'];
$args = json_decode( stripslashes( $_POST['args'] ), ARRAY_A );
$results = call_user_func_array( $fname, $args );
echo json_encode( $results );
?>