<?php
require './vendor/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

class FTP_S3 {
  private $db;
  public $outputs;
  function __construct( $db ) {
    $this->db = $db;
    $this->outputs = [];
  }

  /**
   * This function grabs all contents within the specified folder in the FTP.
   *
   * @param string $conn_id The FTP Connection.
   * @param string $folder  The folder to grab the images from in the FTP.
   * @return array          Returns all image objects if folder exists, otherwise returns false.
   */
  private function get_contents( $conn_id, $folder ) {
    $contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $folder . '/' );
    if ( false !== $contents ) {
      return $contents;
    }
    $temp = explode( '000', $folder );
    $contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $temp[0] . '_01_1280/' );
    if ( false !== $contents ) {
      return $contents;
    }
    $temp = explode( '00', $folder );
    $contents = ftp_nlist( $conn_id, '/media/ChromeImageGallery/ColorMatched_01/Transparent/1280/' . $temp[0] . '_01_1280/' );
    if ( false !== $contents ) {
      return $contents;
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

		// 1) Connect to ftp or die
		$ftp_server = 'ftp.chromedata.com';
		$ftp_user = 'u311191';
		$ftp_pass = 'con191';

		$conn_id = ftp_connect($ftp_server) or set_error( 'FTP', "Couldn't connect to $ftp_server" ); 
		if ( ! @ftp_login( $conn_id, $ftp_user, $ftp_pass ) ) {
			set_error( 'FTP', "Couldn't connect as $ftp_user" );
			echo 'Caught an error connecting to ftp';
			return;
    }
    ftp_pasv( $conn_id, true );
    
		// 2) Foreach 01 media in this model
		foreach ( $media as $m ) {
			$style_id = strrev( $m['style_id'] );
			$directory = '../../temp/media/' . $style_id;
			if ( ! file_exists( '../../temp/media/' . $style_id ) ) {
				mkdir( $directory, 0777, true );
				mkdir( $directory . '/01', 0777, true );
			} else {
        $files = scandir($directory . '/01');
        
				// Skip if all already downloaded
				if ( $m['colorized_count'] == ( count( $files )-2 ) && $m['colorized_count'] != 0 ) {
					continue;
				}
      }
      $folder = 'cc_' . str_replace( '_1280_01', '_01_1280', $m['file_name'] );

      // If folder has all styles combined, this grabs that folder.
      $contents = $this->get_contents( $conn_id, $folder );
      if ( false === $contents ) {
        var_dump('Couldn\'t find ' . $folder . ' in ftp. Please add a fix for this.' );
        exit();
      }

			// 3) Download foreach color variation for this media
			foreach ( $contents as $image ) {
        $image_info = pathinfo( $image );
        // Sometimes folder has all the styles combined, so grab only the images for the current media
        if ( strpos( $folder, $image_info['filename'] ) !== FALSE ) { continue; }
				$color_code = explode( '_', $image_info['filename'] );
				$color_code = end( $color_code );
        $local_path = $directory . '/01/' . $m['file_name'] . '_' . $color_code . '.png';
        
				// If file already exists, skip ( this should only happen during re-runs after error caught )
				if ( file_exists($local_path) ) { continue; }
				if ( ! ftp_get( $conn_id, $local_path, $image, FTP_BINARY ) ) {
          echo 'Something went wrong when downloading images for style id ' . $m['style_id'];
					exit(); // Error caught, exit script
				}
      }
      
			// Update style's colorized_count
      $colorized_count = count( $contents );
      update_colorized_count( $m['style_id'], $colorized_count );
    }
		
		// 5) Insert each image as an original s3 media
		foreach ( $media as $m ) {
			$style_id = strrev( $m['style_id'] );
			$m['storage_path'] = 'original/colorized/' . $style_id . '/01/';
      $directory = '../../temp/media/' . $style_id;
      // Weird bug, go to next folder
      if ( ! file_exists( $directory ) ) { continue; }
			$dir = new DirectoryIterator( $directory . '/01' );
      $values = array();

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
					$values[] = "( '{$copy['style_id']}', 'colorized', '{$copy['url']}', 1280, {$copy['shot_code']}, 960, 'Transparent', '', '$color_code', '', '{$copy['file_name']}', '{$copy['model_name']}', '{$copy['model_name_cd']}', '{$copy['model_year']}')";
				} else {
          var_dump( 'Did not successfully download all local ' . $model . ' images onto s3' . ' specifically for ' . $style_id );
          exit();
        }
      }

      // Close any operations with the folder in use before deleting tree
      unset( $image );
			unset( $dir );
			garbage();

			// Remove old entries if exists and insert S3 entries
			$sql = "INSERT media ( style_id, type, url, height, shot_code, width, background, rgb_hex_code, color_option_code, color_name, file_name, model_name, model_name_cd, model_year ) VALUES ";
      $query = $this->db->query( $sql . implode( ',', $values ) );
      
      //  Remove 01 entry for style
			if ( $query !== FALSE ) {
				remove_cd_media( $m );
				del_tree( $directory );
			} else {
        var_dump('Cancer happened when running aws->s3 db query.' . $query );
        exit();
			}
    }

    // Everything went successful
    $this->outputs[] = array(
      'type'  => 'success',
      'msg'   =>'Successfully downloaded all colorized ' . $model . ' ftp images onto S3'
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