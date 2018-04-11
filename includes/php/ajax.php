<?php
include_once('functions.php');

$fname = $_POST['fname'];
$args = json_decode( stripslashes( $_POST['args'] ), ARRAY_A );
$results = call_user_func_array( $fname, $args );
echo json_encode( $results );
?>