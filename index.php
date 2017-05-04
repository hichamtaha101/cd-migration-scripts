<?php // Silence is golden

include_once( dirname(__FILE__) . '/includes/wpdb.php' );

class Chrome_Data_API {

	private $account_login;
	private $number;
	private $secret;

	public $country_code;
	public $language;

	private $soap;

	private $api_call_configurables;

	public function __construct( $country_code ) {

		$this->number   = '308652';
		$this->secret   = '8343ac07bb984bcb';
		$this->api_url  = 'http://services.chromedata.com/Description/7b?wsdl';
		$this->language = 'en';

		$this->country_code = $country_code;

		$this->account_info = array(
			'number'		=>	$this->number,
			'secret'		=>	$this->secret,
			'country'		=>	$this->country_code,
			'language'	=>	$this->language
		);

		$this->soap_args = array(
			'accountInfo' => $this->account_info
		);

		$this->soap  = new SoapClient( $this->api_url );
		$this->years = $this->get_years( 3 );

	}

	public function soap_call( $function, $args = array(), $all_years = false ) {

		$args['accountInfo'] = $this->account_info;

		$soap_response = $this->soap->__soapCall( $function, array( $args ) );

		if ( $soap_response->responseStatus->responseCode === 'Successful' ) {
			return $soap_response;
		}

		return false;

	}

	public function soap_call_loop( $function, $args = array() ) {

		$args = $this->append_with_year_parameter( $args );

		$response = array();

		foreach ( $args as $parameters ) {

			$api_call = $this->soap_call(
				$function,
				$parameters
			);

			array_push( $response, $api_call );

		}

		return $response;

	}

	/**
	 * This function retrieves the last three years for which data is available
	 * in the chrome data feed in descending order.
	 * 
	 * This function can also be used to create dynamic arrays to retrieve
	 * data from chrome API using other filters for every year as set by $range
	 * 
	 * @param int $range The number of years 
	 *
	 * @return array The last x years set by $range
	 */
	private function get_years( $range = 3 ) {

		$soap_response = $this->soap_call( 'getModelYears' );

		return array_slice(
			$soap_response->modelYear,
			-$range
		);

	}

	/**
	 * This function creates a new array with the parameters provided in $args
	 * along with a year parameter for which we need the data from the chrome API
	 * as set by $this->years variable.
	 * 
	 * @param array $args The parameters to increment with the year parameter
	 *
	 * @return array An array with all the parameters along with the year field
	 */
	private function append_with_year_parameter( $args = array() ) {

		$parameters = array();

		foreach( $this->years as $year ) {

			$year_parameter = array( 'modelYear' => $year );

			array_push( 
				$parameters,
				array_merge( 
					$year_parameter, 
					$args 
				)
			);

		}

		return $parameters;

	}

}