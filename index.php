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
		$this->years = $this->get_years( 2 );

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

	// The properties for style property sanitiziation into convertus api from chrome
	private $style_properties;
	private $engine_properties;

	function __construct( $country_code ) {

		parent::__construct( $country_code );
		$this->db = new WPDB();

		$this->style_properties = array(
			array(
				'prop' => 'id',
				'field' => 'style_id'
			),
			array(
				'prop' => 'acode',
				'field' => 'acode',
				'value' => '_'
			),
			array(
				'prop' => 'mfrModelCode',
				'field' => 'model_code'
			),
			array(
				'prop' => 'modelYear',
				'field' => 'model_year'
			),
			array(
				'prop' => 'division',
				'field' => 'division',
				'value' => '_'
			),
			array(
				'prop' => 'subdivision',
				'field' => 'subdivision',
				'value' => '_'
			),
			array(
				'prop' => 'model',
				'field' => 'model_name',
				'value' => '_'
			),
			array(
				'prop' => 'trim',
				'field' => 'trim',
			),
			array(
				'prop' => 'bodyType',
				'field' => 'body_type',
				'value' => '_'
			),
			array(
				'prop' => 'marketClass',
				'field' => 'market_class',
				'value' => '_'
			),
			array(
				'prop' => 'basePrice',
				'field' => 'msrp',
				'value' => 'msrp'
			),
			array(
				'prop' => 'drivetrain',
				'field' => 'drivetrain',
			),
			array(
				'prop' => 'passDoors',
				'field' => 'doors'
			)
		);

		$this->engine_properties = array(
			array(
				'prop' => 'engineType',
				'field' => 'engine_type',
				'value' => '_'
			),
			array(
				'prop' => 'fuelType',
				'field' => 'fuel_type',
				'value' => '_'
			),
			array(
				'prop' => 'horsepower',
				'field' => 'horsepower'
			),
			array(
				'prop' => 'netTorque',
				'field' => 'net_torque',
			),
			array(
				'prop' => 'cylinders',
				'field' => 'cylinders',
			),
			array(
				'prop' => 'fuelEconomy',
				'field' => 'fuel_economy',
			),
			array(
				'prop' => 'fuelCapacity',
				'field' => 'fuel_capacity'
			),
			array(
				'prop' => 'forcedInduction',
				'field' => 'forced_induction',
				'value' => '_'
			)
		);

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
				$soap_response[] = $this->soap_call_loop( 'getModels', array( 'divisionName' => $division->division_name, 'divisionId' => $division->division_id, 'includeMediaGallery' => 'Multi-View' ) );
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
							$model->division_name = $response->parameters['divisionName'];
							$model->division_id = $response->parameters['divisionId'];
							unset( $model->_ );

							$models[] = $model;

						}

					} else if ( is_object( $response->response->model ) ) {

						$model = $response->response->model;
						$model->name = $model->_;
						$model->year = $response->parameters['modelYear'];
						$model->division_name = $response->parameters['divisionName'];
						$model->division_id = $response->parameters['divisionId'];
						unset( $model->_ );
						$models[] = $model;

					}

				}

			}

		}

		return $models;

	}

	private function update_models() {

		$models = $this->get_models();

		$query = 'INSERT models ( model_year, model_name, model_id, division_name, division_id ) VALUES ';
		$sql_values = array();

		foreach( $models as $model ) {
			$sql_values[] = "({$model->year}, '{$model->name}', {$model->id}, '{$model->division_name}', {$model->division_id})";
		}
		$query .= implode( ',', $sql_values );

		$this->db->query( 'TRUNCATE models' );
		$this->db->query( $query );

	}

	public function get_model_details() {

		$model = $this->db->get_row( "SELECT * FROM models ORDER BY RAND() LIMIT 1" );
		//$model = $this->db->get_row( "SELECT * FROM models WHERE model_id = '29478' " );
		print_r($model);

		$soap_call = $this->soap_call( 
			'describeVehicle', 
			array( 'modelYear' => $model->model_year, 
				'modelName' => $model->model_name, 
				'makeName' => $model->division_name,
				'switch' => array(
					'ShowAvailableEquipment'
				)
			) 
		);

		if ( $soap_call->response->responseStatus->responseCode === 'Unsuccessful' ) {
			return $soap_call;
		}

		$soap_response = $soap_call->response->style;

		$styles = array();
		$calls = array();

		switch( gettype( $soap_response ) ) {
			case 'object':
				$styles = $this->set_style( $soap_call );
				$calls = $soap_call;
				break;
			case 'array':
				foreach( $soap_response as $i => $response_item ) {
					$soap_call_internal = $this->soap_call( 
						'describeVehicle', 
						array( 
							'styleId' => $response_item->id, 
							'switch' => array(
								'ShowAvailableEquipment'
							)
						) 
					);
					if ( $soap_call->response->responseStatus->responseCode === 'Unsuccessful' ) {
						break;
					}
					$calls[] = $soap_call_internal;
					$styles[] = $this->set_style( $soap_call_internal );
				}
				break;
			default:
		}

		return array(
			'truncated_styles' => $styles,
			'actual_api_calls' => $calls
		);

	}

	private function set_style( $call ) {

		$style = array();

		// style properties as defined at the top of the class in an array of objects
		if ( $data = $call->response->style ) {
			$style['style']  = $this->set_properties( $data, $this->style_properties );
		}
		// ^ engine
		if ( $data = $call->response->engine ) {
			if ( is_object( $data ) ) {
				$style['multiple'] = 'false';
				$style['engine'] = $this->set_properties( $data, $this->engine_properties );
			} else if ( is_array( $data ) ) {
				$style['multiple'] = 'true';
				foreach( $data as $engine ) {
					$style['engine'][] = $this->set_properties( $engine, $this->engine_properties );
				}
			}
		}
		// ^ standard equipment
		if ( $data = $call->response->standard ) {
			foreach( $data as $item ) {
				$style['standard'][ strtolower( $item->header->_ ) ][] = $item->description;
			}
		}

		// if ( $data = $soap_call_internal->response->factoryOption ) {

		// 	foreach( $data as $item ) {

		// 		$available = array();

		// 		if ( property_exists( $item, 'description' ) ) {
		// 			$available['description'] = $item->description;
		// 		}

		// 		if ( property_exists( $item, 'chromeCode' ) ) {
		// 			$available['chrome_code'] = $item->chromeCode;
		// 		}

		// 		if ( property_exists( $item, 'oemCode' ) ) {
		// 			$available['oem_code'] = $item->oemCode;
		// 		}

		// 		if ( property_exists( $item, 'standard' ) ) {
		// 			$available['standard'] = $item->standard;
		// 		}

		// 		if ( property_exists( $item, 'price' ) ) {
		// 			$available['price'] = $item->price;
		// 		}

		// 		$styles[ $i ]['option'][ strtolower( $item->header->_ ) ][] = $available;
		// 	}

		// }

		return $style;

	}

	private function set_properties( $style, $properties ) {

		$returned_properties = array();

		// Loop through all properties we need for the showroom
		foreach( $properties as $prop ) {

			// Check if a particular property exists in the chrome object returned that we want
			if ( property_exists( $style, $prop['prop'] ) ) {

				$style_prop = $style->{$prop['prop']};
				$value = array();

				switch( gettype( $style_prop ) ) {
					case 'object':
						$value = ( array_key_exists( 'value', $prop ) ) ? $style_prop->{$prop['value']} : $style_prop;
						break;
					case 'array':
						foreach( $style_prop as $single_prop ) {
							$value[] = $single_prop->{$prop['value']};
						}
						break;
					case 'string':
					default:
						$value = $style_prop;
				}

				$returned_properties[ $prop['field'] ] = $value;

			}

		}

		return $returned_properties;

	}


	private function set_engine_properties( $style, $properties ) {

		$returned_properties = array();

		// Loop through all properties we need for the showroom
		foreach( $properties as $prop ) {

			// Check if a particular property exists in the chrome object returned that we want
			if ( property_exists( $style, $prop['prop'] ) ) {

				$style_prop = $style->{$prop['prop']};
				$value = array();

				switch( gettype( $style_prop ) ) {
					case 'object':
						$value = ( array_key_exists( 'value', $prop ) ) ? $style_prop->{$prop['value']} : $style_prop;
						break;
					case 'array':
						foreach( $style_prop as $single_prop ) {
							$value[] = $single_prop->{$prop['value']};
						}
						break;
					case 'string':
					default:
						$value = $style_prop;
				}

				$returned_properties[ $prop['field'] ] = $value;

			}

		}

		return $returned_properties;

	}

}

$obj = new Convertus_DB_Updater( 'CA' );
var_dump($obj->get_model_details());