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
		$batch_size = 100;
		// Can only handle 10 colorized requests because of the local file size in the request
		if ( $requests[0]['media']['type'] == 'colorized' ) {
			$batch_size = 11;
		}

		// Divide requests by batch_size
		$chunks = array_chunk( $requests, $batch_size );
		$results = array(
			'errors'				=> array(),
			'invalid_json'	=> array(),
			'responses'			=> array()
		);
		foreach ( $chunks as $chunk ) {
			$start = microtime(true);
			
			$response = self::batch_request( $chunk );
			foreach ( $response as $key => $value ) {
				$results[$key] = array_merge( $results[$key], $response[$key] );
			}
			
			$end = microtime(true) - $start;
			echo $end . '<br>';
		}

		return $results;
	}

	public function batch_request( $requests ) {
		
		$curls = array();
		$mh = curl_multi_init();

		// Grab post fields from each param and store into curls
		foreach ( $requests as &$request ) {
			$curl = curl_init();
			$media = $request['media'];
			unset( $request['media'] );

			// Set options for file postdata based curls
			if ( array_key_exists( 'file', $request ) ) {
				$request = self::upload( $request );
				curl_setopt( $curl, CURLOPT_URL, 'https://api.kraken.io/v1/upload' );
			// Set options for url postdata based curls
			} elseif ( array_key_exists( 'url', $request ) ) {
				$request = self::url( $request );
				curl_setopt( $curl, CURLOPT_URL, 'https://api.kraken.io/v1/url' );
				curl_setopt($curl, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
				));
			// Else, there's something wrong with the media entry
			} else {
				echo 'Request does not have a url or file param :/ <pre>'; var_dump( $request ); echo '</pre>';
				continue;
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
			
			// need this to remove curls afterwards, and refer to correct media for db
			$curls[] = array(
				'curl' => $curl, 
				'media' => $media 
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

		// all of our requests are done, we can now access the results
		// $responses = array( 'responses' => array(), 'invalid_json' => array() );
		foreach ( $curls as $ch ) {
			$response = json_decode( curl_multi_getcontent( $ch['curl'] ), true );
			if ( $response['success'] ) { 
				$responses['responses'][] = array(
					'response' 	=> json_decode( curl_multi_getcontent( $ch['curl'] ), true ),
					'media'			=> $ch['media']
				);
				continue; 
			}
			if ( $response['message'] == 'Incoming request body does not contain a valid JSON object' ) {
				$responses['invalid_json'][] = $ch['media'];
			} else {
				$responses['errors'][] = $ch['media'];
			}
		}
		return $responses;
	}

}
?>
