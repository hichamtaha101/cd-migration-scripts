<?php

class Kraken {
	protected $auth = array();
	private $timeout;
	private $proxyParams;

	public function __construct($key = '', $secret = '', $timeout = 30, $proxyParams = array()) {
		$this->auth = array(
			"auth" => array(
				"api_key" => $key,
				"api_secret" => $secret
			)
		);
		$this->timeout = $timeout;
		$this->proxyParams = $proxyParams;
	}

	public function url($opts = array()) {
		$data = json_encode(array_merge($this->auth, $opts));
		return $data;
	}

	public function upload($opts = array()) {
		if (!isset($opts['file'])) {
			return array(
				"success" => false,
				"error" => "File parameter was not provided"
			);
		}

		if (!file_exists($opts['file'])) {
			return array(
				"success" => false,
				"error" => 'File `' . $opts['file'] . '` does not exist'
			);
		}

		if (class_exists('CURLFile')) {
			$file = new CURLFile($opts['file']);
		} else {
			$file = '@' . $opts['file'];
		}

		unset($opts['file']);

		$data = array_merge(array(
			"file" => $file,
			"data" => json_encode(array_merge($this->auth, $opts))
		));

		return $data;
	}

	public function status() {
		$data = array('auth' => array(
			'api_key' => $this->auth['auth']['api_key'],
			'api_secret' => $this->auth['auth']['api_secret']
		));

		$response = self::request(json_encode($data), 'https://api.kraken.io/user_status', 'url');

		return $response;
	}

	public function multiple_requests( $requests ) {

		// Divide requests by batch_size
		$responses = array();
		$chunks = array_chunk( $requests, 100 );
		foreach ( $chunks as $chunk ) {
			$response = $this->recursive_batch_request( $chunk );
			$responses = array_merge( $response, $responses );
		}
		return $responses;
	}

	private function recursive_batch_request( $batch_requests, $attempt = 0 ) {

		if ( $attempt == 3 ) {
			// display_var( count( $batch_requests ) . ' requests have failed.' );
			return array();
		}

		$success = $redo = $curls = array();
		$mh = curl_multi_init();

		// Grab post fields from each param and store into curls
		foreach ( $batch_requests as $request ) {
			$curl = curl_init();
			$req = $request;
			unset( $request['media'] );

			if ( array_key_exists( 'url', $request ) ) {
				$request = self::url( $request );
				curl_setopt( $curl, CURLOPT_URL, 'https://api.kraken.io/v1/url' );
				curl_setopt($curl, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
				));
			// Else, there's something wrong with the media entry
			} else {
				echo 'Request does not have a url or file param <pre>'; var_dump( $request ); echo '</pre>';
				exit();
			}
			// Force continue-100 from server
			curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/40.0.2214.85 Safari/537.36");
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
			curl_setopt($curl, CURLOPT_FAILONERROR, 0);
			curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . "/cacert.pem");
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
			if (isset($this->proxyParams['proxy'])) {
				curl_setopt($curl, CURLOPT_PROXY, $this->proxyParams['proxy']);
			}
			
			// need this to remove curls afterwards, and recall failed reqeusts
			$curls[] = array(
				'curl' => $curl, 
				'request'	=> $req
			); 
			curl_multi_add_handle( $mh, $curl );
		}

		$running = null;
		// execute the handlers
		do {
			curl_multi_exec( $mh, $running );
		} while ( $running );

		// close the handlers
		foreach ( $curls as $ch ) { curl_multi_remove_handle( $mh, $ch['curl'] ); }
		curl_multi_close( $mh );

		// handle responses
		foreach ( $curls as $ch ) {
			$response = json_decode( curl_multi_getcontent( $ch['curl'] ), true );
			
			// successful response
			if ( $response['success'] ) {
				$success[] = array(
					'response' 	=> $response,
					'media'		=> $ch['request']['media']
				);
				continue; 
			} else { // unsuccessful response
				$redo[] = $ch['request'];
			}
		}

		// recursive function to ensure no more errors
		if ( count( $redo ) > 0 ) {
			return array_merge( $success, self::recursive_batch_request( $redo, $attempt + 1 ) );
		} else {
			return $success;
		}
	}

}
?>
