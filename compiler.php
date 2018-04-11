<?php
//http://leafo.net/scssphp/docs/
require "scssphp/scss.inc.php";

$file_name		= 	'chromedata.scss';
$child_path 	=	"includes/css/";
$parent_path 	=	"includes/css/";
$output_path 	=	$child_path;

$scss = new scssc();

$scss->setImportPaths($parent_path);
$scss->addImportPath($child_path);
$scss->setFormatter('scss_formatter_compressed');
$css =	$scss->compile('@import "' . $file_name . '"');

// will search for `assets/stylesheets/mixins.scss'
//$css = $scss->compile('@import "' . $file_name . '"');
$out = preg_replace("/.scss$/", ".css", $file_name);
file_put_contents( $output_path . $out, $css);
echo $css;
?>