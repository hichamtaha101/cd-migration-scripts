<?php
// require './vendor/aws-autoloader.php';
require_once( dirname( __FILE__ ) . '/vendor/aws-autoloader.php' );

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

class FTP_S3 {
  private $db;
  public $outputs;
  function __construct( $db ) {
    $this->db = $db;
    $this->outputs = [];

    // Connect to FTP
    $this->conn_id = ftp_connect('ftp.chromedata.com') or set_error( 'FTP', "Couldn't connect to $ftp_server" ); 
		if ( ! @ftp_login( $this->conn_id, 'u311191', 'con191' ) ) {
			set_error( 'FTP', "Couldn't connect as u311191" );
      echo 'Caught an error while trying to connect to ftp';
      $errorlog = fopen("error_log.txt", "a");
      $text = date( 'Y-m-d H:i:s' ) . ': Caught an error while trying to connect to ftp';
      fwrite($errorlog, "\n" . $text);
      fclose($errorlog);
			return;
    }
    ftp_pasv( $this->conn_id, true );
  }

  /**
   * This function grabs all contents within the specified folder in the FTP.
   *
   * @param string $folder  The folder to grab the images from in the FTP.
   * @return array          Returns all image objects if folder exists, otherwise returns false.
   */
  private function get_contents($folder ) {
    $contents = ftp_nlist( $this->conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $folder . '/' );
    if ( false !== $contents ) {
      return $contents;
    }
    $temp = explode( '000', $folder );
    $contents = ftp_nlist( $this->conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $temp[0] . '_01_1280/' );
    if ( false !== $contents ) {
      return $contents;
    }
    $temp = explode( '00', $folder );
    $contents = ftp_nlist( $this->conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $temp[0] . '_01_1280/' );
    if ( false !== $contents ) {
      return $contents;
    }
    $index = stripos($folder, '00');
    if ( $index !== false ) {
      $temp = substr( $folder, 0, $index + 1);
      $contents = ftp_nlist( $this->conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $temp . '_01_1280' );
      if ( false !== $contents ) {
        return $contents;
      }
    }
    return false;
  }

  /**
   * This function grabs all colorized images for the media provided, and stores it on S3 and our Database.
   * Additionally, the function breaks if sometihing goes wrong and echos an appropriate log.
   *
   * @param array $media  List of media objects to grab corresponding colorized images from FTP for.
   * @return boolean      True if the whole migration went well.
   */
	public function copy_colorized_media_to_s3( $media ) {
    $model = $media[0]['model_name_cd'];

		foreach ( $media as $m ) {
			$style_id = strrev( $m['style_id'] );
      $directory = '../../temp/media/' . $style_id;
      
      // 1) Create local directory to store images / continue downloading
			if ( ! file_exists( $directory ) ) {
				mkdir( $directory, 0777, true );
				mkdir( $directory . '/01', 0777, true );
			}
      
      // 2) Try to grab folder containing the media's colorized images.
      $num_tries = 0;
      $folder = 'cc_' . str_replace( '_1280_01', '_01_1280', $m['file_name'] );
      while( $num_tries < 3 ) {
        $contents = $this->get_contents( $folder );
        if ( false === $contents ) {
          $num_tries++; 
          continue;
        } else { break; }
      }
      if ( false === $contents ) {
        var_dump('Couldn\'t find ' . $folder . ' in ftp. Please add a fix for this.' );
        return('Couldn\'t find ' . $folder . ' in ftp. Please add a fix for this.');
      }

			// 3) Download each color variation for this media
			foreach ( $contents as $image ) {
        $image_info = pathinfo( $image );
        // Sometimes folder has all the styles combined, so grab only the images for the current media
        if ( strpos( $folder, $image_info['filename'] ) !== FALSE ) { continue; }
				$color_code = explode( '_', $image_info['filename'] );
				$color_code = end( $color_code );
        $local_path = $directory . '/01/' . $m['file_name'] . '_' . $color_code . '.png';
        
				// If file already exists, skip ( this should only happen during re-runs of incomplete scripts )
				if ( file_exists($local_path) ) { continue; }
				if ( ! ftp_get( $this->conn_id, $local_path, $image, FTP_BINARY ) ) {
          echo "Something went wrong when downloading images for style id {$m['style_id']} at {$local_path}";
          $errorlog = fopen("error_log.txt", "a");
          $text = date( 'Y-m-d H:i:s' ) . ": Something went wrong when downloading images for style id {$m['style_id']} at {$local_path}";
          fwrite($errorlog, "\n" . $text);
          fclose($errorlog);
          return array(
            'success' => false,
            'error' =>  "Something went wrong when downloading images for style id {$m['style_id']} at {$local_path}",
          );
				}
      }

      // 4) Insert each image as an original s3 media
      $m['storage_path'] = 'original/colorized/' . $style_id . '/01/';
      $dir = new DirectoryIterator( $directory . '/01' );
      $sql_values = array();
       // Send all images to s3 for this style
			foreach ( $dir as $image ) {
				if ( $image->isDot() ) { continue; }
				$image_info = pathinfo( $image );
				$color_code = explode( '_', $image_info['filename'] );
				$color_code = end( $color_code );
				$copy = $m;
				$copy['file_name'] .= '_' . $color_code;
				$copy['storage_path'] .= $copy['file_name'] . '.png';
				$results = $this->send_media($copy);
				if ( $results !== FALSE ) {
					$copy['url'] = $results->get('ObjectURL');
					$sql_values[] = "( '{$copy['style_id']}', 'colorized', '{$copy['url']}', 1280, {$copy['shot_code']}, 960, 'Transparent', '', '$color_code', '', '{$copy['file_name']}', '{$copy['model_name']}', '{$copy['model_name_cd']}', '{$copy['model_year']}')";
				} else {
          var_dump( 'Did not successfully download all local ' . $model . ' images onto s3' . ' specifically for ' . $style_id );
          $errorlog = fopen("error_log.txt", "a");
          $text = date( 'Y-m-d H:i:s' ) . ': Did not successfully download all local ' . $model . ' images onto s3' . ' specifically for ' . $style_id;
          fwrite($errorlog, "\n" . $text);
          fclose($errorlog);
          return array(
            'success' => false,
            'error' => 'Did not successfully download all local ' . $model . ' images onto s3' . ' specifically for ' . $style_id ,
          );
        }
      }

      // Close any operations with the folder in use before deleting tree
      unset( $image );
			unset( $dir );
      garbage();

      //  Delete Tree 
      del_tree( $directory );
      
      // Remove old entries if exists and insert S3 entries
      $sql_delete = "DELETE FROM media WHERE style_id LIKE '{$m['style_id']}' AND file_name LIKE '%{$m['file_name']}%' AND (url LIKE '%amazonaws.com/original%' OR url LIKE '%chromedata%') AND shot_code LIKE {$m['shot_code']}";
      $sql_insert = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, file_name, model_name, model_name_cd, model_year ) VALUES ";
      $this->db->query( $sql_delete );
      // $this->db->query( "DELETE FROM media WHERE style_id LIKE '{$m['style_id']}' AND file_name LIKE '%{$m['file_name']}%' AND shot_code LIKE {$m['shot_code']} AND url LIKE '%chromedata%' " );
      $this->db->query( $sql_insert . implode( ',', $sql_values ) );
    }

    // Everything went successful
    $this->outputs[] = array(
      'type'  => 'success',
      'msg'   => 'Successfully downloaded all colorized ' . $model . ' ftp images onto S3'
    );
    return true;
  }
  
  /**
   * This function uses the S3 API to upload an image to the Bucket.
   *
   * @param object $media The media object being uploaded to the s3 bucket.
   * @return boolean      Whether the upload went well or not.
   */
  private function send_media($media) {

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

    $source_file = '../../temp/media/' . strrev($media['style_id']) . '/01/' . $media['file_name'] . '.png';

    try {
      $result = $s3->putObject([
        'Bucket'        => AWS_BUCKET,
        'Key'           => $media['storage_path'],
        'SourceFile'    => $source_file,
        'ACL'           => 'public-read'
      ]);
      unset($s3);
      garbage();
      return $result;
    } catch ( S3Exception $e ) {
      echo $e->getMessage();
      return FALSE;
    } catch ( Exception $e ) {    
      echo $e->getMessage();
      return FALSE;
    }
  }
}