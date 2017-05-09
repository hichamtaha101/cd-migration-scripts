<?php // Silence is golden

include_once( dirname(__FILE__) . '/includes/wpdb.php' );
include_once( dirname(__FILE__) . '/includes/formatting.php' );


class Chrome_Data_API {

	private $account_login;
	private $number;
	private $secret;

	public $country_code;
	public $language;

	private $soap;

	function __construct( $country_code ) {

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

	protected function soap_call( $function, $args = array(), $all_years = false ) {

		$params = $args;
		$params['accountInfo'] = $this->account_info;

		$soap_response = $this->soap->__soapCall( $function, array( $params ) );

		$response = new stdClass();
		$response->response = $soap_response;
		$response->parameters = $args;

		return $response;

	}

	protected function soap_call_loop( $function, $args = array() ) {

		$args = $this->append_with_year_parameter( $args );

		$response = array();

		foreach ( $args as $parameters ) {

			$api_call = $this->soap_call(
				$function,
				$parameters
			);

			$response[] = $api_call;

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
	private function get_years( $range = 2 ) {

		$soap_response = $this->soap_call( 'getModelYears' );
		$response = $soap_response->response;

		return array_slice(
			$response->modelYear,
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
			$parameters[] = array_merge( $year_parameter, $args );

		}

		return $parameters;

	}

	protected function truncate_response_parameters( $data, $args ) {

		$truncated_data = array();
		$response_data = array_column( $data, 'response' );

		foreach( $response_data as $response ) {
			$truncated_data[] = $response->{$args['property']};
		}

		foreach ( $truncated_data as $key => $value ) {
			if ( is_object( $value ) ) {
				$truncated_data[ $key ] = array( $value );
			}
		}

		if ( array_key_exists( 'unique', $args ) ) {
			$truncated_data = array_values( 
				array_unique( 
					call_user_func_array( 'array_merge', $truncated_data ),
					SORT_REGULAR 
				) 
			);
		}

		return $truncated_data;

	}

}

Class Convertus_API {

}

class Convertus_DB_Updater extends Chrome_Data_API {

	private $db;

	function __construct( $country_code ) {

		parent::__construct( $country_code );
		$this->db = new WPDB();

		$this->update_divisions();

	}

	public function get_divisions() {

		$soap_response = $this->soap_call_loop( 'getDivisions' );

		$divisions = array();

		foreach( $soap_response as $response ) {

			if ( is_array( $response->response->division ) ) {

				foreach( $response->response->division as $division ) {

					$division->name = $division->_;
					$division->image = 'http://api.convertus.com/assets/logos/' . sanitize_title_with_dashes( $division->_ ) . '.png';
					unset( $division->_ );

					$divisions[] = $division;

				}

			} else if ( is_object( $response->response->division ) ) {

				$obj = new stdClass();

				$division = $response->response->division;
				$division->name = $division->_;
				$division->image = 'http://api.convertus.com/assets/logos/' . sanitize_title_with_dashes( $division->_ ) . '.png';
				unset( $division->_ );
				$divisions[] = $response->response->division;

			}

		}

		return array_values( array_intersect_key( $divisions, array_unique( array_column( $divisions, 'id' ) ) ) );

	}

	private function update_divisions() {

		$divisions = $this->get_divisions();

		$query = 'INSERT divisions ( division_name, division_id, image ) VALUES ';
		$sql_values = array();

		foreach( $divisions as $division ) {
			$sql_values[] = "('{$division->name}', {$division->id}, '{$division->image}')";
		}
		$query .= implode( ',', $sql_values );

		$this->db->query( 'TRUNCATE divisions' );
		$this->db->query( $query );

	}

	public function get_models( $division_id = -1 ) {

		if ( $division_id === -1 ) {
			$divisions = $this->db->get_results( 'SELECT * FROM divisions' );
		}

		if ( $divisions ) {
			$soap_response = array();
			foreach( $divisions as $division ) {
				$soap_response[] = $this->soap_call_loop( 'getModels', array( 'divisionId' => $division->division_id ) );
			}
		}

		$models = array();

		foreach( $soap_response as $first_response ) {

			foreach( $first_response as $response ) {

				if ( $response->response->responseStatus->responseCode === 'Successful' ) {

					if ( is_array( $response->response->model ) ) {

						foreach( $response->response->model as $model ) {

							$model->name = $model->_;
							$model->year = $response->parameters['modelYear'];
							$model->division_id = $response->parameters['divisionId'];
							unset( $model->_ );

							$models[] = $model;

						}

					} else if ( is_object( $response->response->model ) ) {

						$model = $response->response->model;
						$model->name = $model->_;
						$model->year = $response->parameters['modelYear'];
						$model->division_id = $response->parameters['divisionId'];
						unset( $model->_ );
						$models[] = $model;

					}

				}

			}

		}

		return $models;

	}

}

$obj = new Convertus_DB_Updater( 'CA' );
print_r($obj->get_models());