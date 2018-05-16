<?php
require './vendor/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

class AWS_S3 {
  function __construct() {
  }

  public function send_noods() {
    $filePath = "./2018BMC640001_1280_01_300.png";

    $s3 = S3Client::factory(
      array(
        'credentials' => array(
          'key'     => AWS_ACCESS_KEY_ID,
          'secret'  => AWS_SECRET_ACCESS_KEY,
        ),
        'version' => 'latest',
        'region'  => AWS_REGION
      )
    );

    try {
      $result = $s3->putObject([
        'Bucket'        => AWS_BUCKET,
        'Key'           => 'original/colorized/test.png',
        'SourceFile'    => $filePath
      ]);
      var_dump( $result );
    } catch ( S3Exception $e ) {
      echo $e->getMessage();
    } catch ( Exception $e ) {
      echo $e->getMessage();
    }
  }
}