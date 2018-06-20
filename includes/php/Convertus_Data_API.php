<?php
include_once( dirname( __FILE__ ) . '/wpdb.php' );
include_once( dirname( __FILE__ ) . '/formatting.php' );
include_once( dirname( dirname( dirname( __FILE__ ) ) ) . '/config.php' );

class Chrome_Data_API {

	private $account_login;
	private $number;
	private $secret;
	
	public $body_types;
	public $standard_models;
	public $outputs;
	public $country_code;
	public $language;

	private $soap;

	function __construct( $country_code ) {

		$this->number   = CHROME_DATA_ACCOUNT_ID;
		$this->secret   = CHROME_DATA_SECRET_ACCESS_KEY;
		$this->api_url  = 'http://services.chromedata.com/Description/7b?wsdl';
		$this->language = 'en';
		$this->outputs = array();
		$this->valid = TRUE;

		$this->country_code = $country_code;

		$this->account_info = array(
			'number'        => $this->number,
			'secret'        => $this->secret,
			'country'       => $this->country_code,
			'language'  		=> $this->language,
		);

		$this->soap_args = array(
			'accountInfo' => $this->account_info,
		);

		$this->soap  = new SoapClient( $this->api_url );
		$this->years = $this->get_years( 2 );

		// Standardize body types
		$this->body_types = array(
			'4dr Car'																	=> 'Sedan',
			'Sport Utility'														=> 'SUV',
			'2dr Car'																	=> 'Coupe',
			'["Convertible","2dr Car"]'								=> 'Convertible',
			'["Hatchback","4dr Car"]'									=> 'Hatchback',
			'Specialty Vehicle'												=> 'Other',
			'Mini-van, Cargo'													=> 'Van',
			'Mini-van, Passenger'											=> 'Van',
			'["Long Bed","Regular Cab Pickup"]'				=> 'Truck',
			'["Standard Bed","Extended Cab Pickup"]'	=> 'Truck',
			'["Long Bed","Extended Cab Pickup"]'			=> 'Truck',
			'["Long Bed","Crew Cab Pickup"]'					=> 'Truck',
			'["Standard Bed","Crew Cab Pickup"]'			=> 'Truck',
			'["Standard Bed","Regular Cab Pickup"]'		=> 'Truck',
			'["Short Bed","Crew Cab Pickup"]'					=> 'Truck',
			'["Station Wagon","4dr Car"]'							=> 'Wagon',
			'["Hatchback","2dr Car"]'									=> 'Hatchback',
			'Full-size Passenger Van'									=> 'Van',
			'Full-size Cargo Van'											=> 'Van',
			'["Short Bed","Extended Cab Pickup"]'			=> 'Truck',
			'["Sport Utility","Convertible"]'					=> 'SUV',
			'Regular Cab Chassis-Cab'									=> 'Truck',
			'Crew Cab Chassis-Cab'										=> 'Truck',
			'Extended Cab Chassis-Cab'								=> 'Truck',
			'["3dr Car","Hatchback"]'									=> 'Hatchback',
		);

		// Standardize model names
		$this->standard_models = array(
			'3500 Chassis' 				              => '3500',
			'370Z Coupe' 				                => '370Z',
			'370Z Roadster' 				            => '370Z',
			'4500 Chassis'				              => '4500',
			'4C Coupe' 				                  => '4C',
			'5500 Chassis'				              => '5500',
			'A3 Cabriolet'				              => 'A3',
			'A3 Sedan'				                  => 'A3',
			'A3 Sportback e-tron'			          => 'A3 e-tron',
			'A4 Sedan'				                  => 'A4',
			'A5 Cabriolet'				              => 'A5',
			'A5 Coupe'				                  => 'A5',
			'A5 Sportback' 				              => 'A5',
			'A7 Sportback' 				              => 'A7',
			'A8 L'					                    => 'A8',
			'ATS Coupe'				                  => 'ATS',
			'ATS Sedan'				                  => 'ATS',
			'ATS-V Coupe'				                => 'ATS-V',
			'ATS-V Sedan'				                => 'ATS-V',
			'Accord Sedan'				              => 'Accord',
			'Beetle'					                  => 'Convertible',
			'CT6 Sedan'				                  => 'CT6',
			'CTS Sedan'				                  => 'CTS',
			'CTS-V Sedan'				                => 'CTS-V',
			'City Express Cargo Van'			      => 'City Express',
			'Civic Coupe'				                => 'Civic',
			'Civic Hatchback'				            => 'Civic',
			'Civic Sedan'				                => 'Civic',
			'Express Cargo Van'			            => 'Express',
			'E-Series Stripped Chassis'		      => 'Stripped Chassis',
			'Express Commercial Cutaway'		    => 'Express',
			'Express Passenger'			            => 'Express',
			'F-53 Motorhome Stripped Chassis'		=> 'Stripped Chassis',
			'F-59 Commercial Stripped Chassis'	=> 'Stripped Chassis',
			'Mazda3 Sport'				              => 'Mazda3',
			'Metris Cargo Van'			            => 'Metris',
			'Metris Passenger Van'			        => 'Metris',
			'NV200 Compact Cargo'			          => 'NV200',
			'ProMaster City Cargo Van'		      => 'Promaster City',
			'ProMaster City Wagon'			        => 'Promaster City',
			'Q60 Coupe'				                  => 'Q60',
			'R8 Coupe'				                  => 'R8',
			'RS 3 Sedan'				                => 'RS 3',
			'RS 5 Coupe'				                => 'RS 5',
			'RS 7 Sportback'				            => 'RS 7',
			'RS 7 Sportback Performance'		    => 'RS 7',
			'S3 Sedan'				                  => 'S3',
			'S4 Sedan'				                  => 'S4',
			'S5 Cabriolet'				              => 'S5',
			'S5 Coupe'				                  => 'S5',
			'S5 Sportback'				              => 'S5',
			'S7 Sportback'				              => 'S7',
			'S8 plus'					                  => 'S8',
			'Silverado 1500 LD'			            => 'Silverado 1500',
			'TT Coupe'				                  => 'TT',
			'TT RS Coupe'				                => 'TT RS',
			'TT Roadster'				                => 'TT',
			'TTS Coupe'				                  => 'TTS',
			'Transit Chassis Cab'			          => 'Transit',
			'Transit Connect Van'			          => 'Transit Connect',
			'Transit Connect Wagon'			        => 'Transit Connect',
			'Transit Cutaway'				            => 'Transit',
			'Transit Passenger Wagon'	          => 'Transit',
			'Transit Van'				                => 'Transit',
			'Yaris Hatchback'				            => 'Yaris',
			'Yaris Sedan'				                => 'Yaris',
		);

	}

	public function soap_call( $function, $args = array(), $all_years = false ) {

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
	private function get_years( $range = 4 ) {

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

		foreach ( $this->years as $year ) {

			$year_parameter = array( 'modelYear' => $year );
			$parameters[] = array_merge( $year_parameter, $args );

		}

		return $parameters;

	}

	protected function truncate_response_parameters( $data, $args ) {

		$truncated_data = array();
		$response_data = array_column( $data, 'response' );

		foreach ( $response_data as $response ) {
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

class Convertus_DB_Updater extends Chrome_Data_API {

	public $db;

	// The properties for style property sanitiziation into convertus api from chrome
	private $style_properties;
	private $engine_properties;

	function __construct( $country_code ) {

		parent::__construct( $country_code );
		$this->db = new WPDB();

		$this->style_properties = array(
			array(
				'property' 	=> 'id',
				'field' 		=> 'style_id',
			),
			array(
				'property' 	=> 'acode',
				'field' 		=> 'acode',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'mfrModelCode',
				'field' 		=> 'model_code',
			),
			array(
				'property' 	=> 'modelYear',
				'field' 		=> 'model_year',
			),
			array(
				'property' 	=> 'division',
				'field' 		=> 'division',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'subdivision',
				'field' 		=> 'subdivision',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'model',
				'field' 		=> 'model_name',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'trim',
				'field' 		=> 'trim',
			),
			array(
				'property' 	=> 'bodyType',
				'field' 		=> 'body_type',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'marketClass',
				'field' 		=> 'market_class',
				'value' 		=> '_',
			),
			array(
				'property' 	=> 'basePrice',
				'field' 		=> 'msrp',
				'value' 		=> 'msrp',
			),
			array(
				'property' 	=> 'drivetrain',
				'field' 		=> 'drivetrain',
			),
			array(
				'property' 	=> 'passDoors',
				'field' 		=> 'doors',
			),
		);

		$this->engine_properties = array(
			array(
				'property' => 'engineType',
				'field' => 'engine_type',
				'value' => '_',
			),
			array(
				'property' => 'fuelType',
				'field' => 'fuel_type',
				'value' => '_',
			),
			array(
				'property' => 'horsepower',
				'field' => 'horsepower',
			),
			array(
				'property' => 'netTorque',
				'field' => 'net_torque',
			),
			array(
				'property' => 'cylinders',
				'field' => 'cylinders',
			),
			array(
				'property' => 'fuelEconomy',
				'field' => 'fuel_economy',
			),
			array(
				'property' => 'fuelEconomy',
				'field' => 'fuel_economy_city_low',
			),
			array(
				'property' => 'fuelEconomy',
				'field' => 'fuel_economy_hwy_low',
			),
			array(
				'property' => 'fuelEconomy',
				'field' => 'fuel_economy_city_high',
			),
			array(
				'property' => 'fuelEconomy',
				'field' => 'fuel_economy_hwy_high',
			),
			array(
				'property' => 'fuelCapacity',
				'field' => 'fuel_capacity',
			),
			array(
				'property' => 'forcedInduction',
				'field' => 'forced_induction',
				'value' => '_',
			),
			array(
				'property' => 'displacement',
				'field' => 'displacement',
				'value' => array( '_', 'unit' ),
			),
		);

		$this->image_gallery_properties = array(
			array(
				'property' => 'url',
				'field' => 'url',
			),
			array(
				'property' => 'width',
				'field' => 'width',
			),
			array(
				'property' => 'height',
				'field' => 'height',
			),
			array(
				'property' => 'shotCode',
				'field' => 'shot_code',
			),
			array(
				'property' => 'backgroundDescription',
				'field' => 'background_description',
			),
			array(
				'property' => 'styleId',
				'field' => 'style_id',
			),
			array (
				'property'	=> 'fileName',
				'field'			=> 'file_name',
			),
		);

	}

	public function get_divisions() {

		$soap_response = $this->soap_call_loop( 'getDivisions' );

		$divisions = array();

		foreach ( $soap_response as $response ) {

			if ( is_array( $response->response->division ) ) {

				foreach ( $response->response->division as $division ) {

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

	public function update_divisions() {

		$divisions = $this->get_divisions();

		$query = 'INSERT division ( division_name, division_id, oem_logo, last_updated ) VALUES ';
		$sql_values = array();

		foreach ( $divisions as $division ) {
			$sql_values[] = "('{$division->name}', {$division->id}, '{$division->image}', now())";
		}

		$query .= implode( ',', $sql_values );

		$this->db->query( 'TRUNCATE division' );
		$result = $this->db->query( $query );
		if ( $result ) {
			$this->outputs[] = array( 'type' => 'success', 'msg' => 'Successfully updated all makes' );
		} else {
			$this->outputs[] = array( 'type' => 'error', 'msg' => 'There was an error updating all makes' );
		}

	}

	public function get_models( $division_id = -1 ) {

		if ( $division_id === -1 ) {    
			$divisions = $this->db->get_results( 'SELECT * FROM division' );
		} else {
			$divisions = $this->db->get_results( "SELECT * FROM division where division_id = {$division_id}" );
		}

		if ( $divisions ) {
			$soap_response = array();
			foreach ( $divisions as $division ) {
				$soap_response[] = $this->soap_call_loop(
					'getModels',
					array(
						'divisionName' => $division->division_name,
						'divisionId' => $division->division_id,
					)
				);
			}
		}

		$models = array();

		foreach ( $soap_response as $first_response ) {

			foreach ( $first_response as $response ) {

				if ( $response->response->responseStatus->responseCode === 'Successful' ) {

					if ( is_array( $response->response->model ) ) {

						foreach ( $response->response->model as $model ) {

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

	public function update_models() {

		$models = $this->get_models();

		$query = 'INSERT model ( model_year, model_name, model_id, division_name, division_id, last_updated ) VALUES ';
		$sql_values = array();

		foreach ( $models as $model ) {
			$sql_values[] = "({$model->year}, '{$model->name}', {$model->id}, '{$model->division_name}', {$model->division_id}, now())";
		}
		$query .= implode( ',', $sql_values );

		$this->db->query( 'TRUNCATE model' );
		$result = $this->db->query( $query );
		if ( $result ) {
			$this->outputs[] = array( 'type' => 'success', 'msg' => 'Successfully updated all models' );
		} else {
			$this->outputs[] = array( 'type' => 'error', 'msg' => 'There was an error updating all models' );
		}

	}

	private function remove_duplicate_models( $models ) {
		$results = $duplicates = array();
		foreach ( $models as $model ) {
			$id = $model->model_year . $model->model_name;
			if ( in_array( $id, $duplicates ) ) { continue; }
			$results[] = $model;
			$duplicates[] = $id;
		}
		return $results;
	}

	public function get_model_details( $filter ) {

		$models = $this->db->get_results( "SELECT * FROM model WHERE {$filter}" );
		if ( empty( $models ) ) {
			$this->outputs[] = array( 
				'type' => 'error', 
				'msg' => 'Couldn\'t find model(s) with query ' . $filter . ' in the DB' 
			);
			return FALSE;
		}
		$models = $this->remove_duplicate_models($models);

		$styles = array();
		foreach ( $models as $model ) {

			// Grab all trim variations per model
			$soap_call = $this->soap_call(
				'describeVehicle',
				array(
					'modelYear' => $model->model_year,
					'modelName' => $model->model_name,
					'makeName' 	=> $model->division_name,
				)
			);
			if ( $this->error_caught( $soap_call->response->responseStatus ) ) { continue; } // Skip these

			// All model year for this model
			$soap_response = $soap_call->response->style;
			switch ( gettype( $soap_response ) ) {
					// Model only has one year ( object )
				case 'object':
					$soap_call = $this->get_style_details( $soap_response->id );
					if ( $soap_call === FALSE ) { break; } // Skip this
					$styles[] = $this->set_style( $soap_call, $soap_response->id );
					break;
					// Model has multiple years ( array )
				case 'array':
					foreach ( $soap_response as $i => $response_item ) {
						$soap_call = $this->get_style_details( $response_item->id );
						if ( $soap_call === FALSE ) { continue; } // Skip this
						$styles[] = $this->set_style( $soap_call, $response_item->id );
					}
					break;
				default:
					echo 'This should not happen';
					exit();
			}
		}


		// Test to see if all models styles pass 
		$results = self::meets_requirements( $styles );
		if ( $results === TRUE ) {
			$this->outputs[] = array(
				'type' => 'success', 
				'msg' => 'Successfully updated styles for ' . $model->division_name . ' ' . $model->model_name 
			);
		} else {
			$this->valid = $results;
		}

		return $styles;
	}

	private function error_caught( $response_status ) {
		if ( $response_status->responseCode === 'Unsuccessful' ) {
			$output = array( 'type' => 'error' );
			$error = $response_status->status->_ . ' : ' . $response_status->status->code;
			if ( strpos( $error, 'NameMatchNotFound' ) !== FALSE ) {
				$output['type'] = 'warning';
			}
			$output['msg'] = $error;
			$this->outputs[] = $output;
			return true;
		}
		return false;
	}

	private function get_style_details( $id ) {
		$soap_response = $this->soap_call(
			'describeVehicle',
			array(
				'styleId' => $id,
				'includeMediaGallery' => 'Both',
				'switch' => array(
					'ShowAvailableEquipment',
					'ShowConsumerInformation',
					'ShowExtendedTechnicalSpecifications',
					'IncludeDefinitions',
				),
			)
		);

		// Skip error responses
		if ( $this->error_caught( $soap_response->response->responseStatus ) ) { return false; }
		return $soap_response;
	}

	private function set_option( $item, $child = 'false' ) {
		// Set constant fields and defaults
		$option = array(
			'id'					=> $item->header->id,
			'header'			=> $item->header->_,
			'styleId'			=> $item->styleId,
			'description'	=> addslashes( $item->description ),
			'isChild'			=> $child,
			'oemCode'			=> null,
			'chromeCode'	=> null,
			'msrpMin'			=> null,
			'msrpMax'			=> null,
			'categories'	=> null
		);

		if ( isset( $item->oemCode ) ) {
			$option['oemCode'] = $item->oemCode;
		}
		if ( isset( $item->chromeCode ) ) {
			$option['chromeCode'] = $item->chromeCode;
		}
		if ( isset( $item->price ) ) {
			$option['msrpMin'] = $item->price->msrpMin;
			$option['msrpMax'] = $item->price->msrpMax;
		}
		if ( isset( $item->category ) ) {
			if ( is_object( $item->category ) ) {
				$option['categories'] = [ $item->category->id ];
			} elseif ( is_array( $item->category ) ) {
				foreach ( $item->category as $category ) {
					$option['categories'][] = $category->id;
				}
			}
		}
		$option['categories'] = json_encode( $option['categories'] );
		return $option;
	}

	private function get_transmission( $desc ) {
		if ( stripos($desc, 'Transmission,' ) !== FALSE ) {
			$desc = explode( ', ', $desc );
			return $desc[1];
		} elseif ( stripos( $desc, 'Transmission:' ) !== FALSE ) {
			$desc = explode( ' ', $desc );
			$values = array();
			foreach( $desc as $value ) {
				if ( strpos( $value, ':' ) !== FALSE ) {
					$key = str_replace( ':', '', $value );
					$values[$key] = array();
				} else {
					$values[$key][] = $value;
				}
			}
			return implode( ' ', $values['Transmission'] );
		} elseif ( stripos( $desc, ' transmission' ) !== FALSE ) {
			$desc = str_replace( ' transmission', '', $desc );
			return $desc;
		} elseif ( strpos( $desc, 'Transmission' ) === 0 ) {
			return $desc;
		} else {
			return NULL;
		}
	}

	private function set_style( $soap_call, $style_id ) {

		$style = array();
		$response = $soap_call->response;

		// Store all custom manufacture options
		if ( isset( $response->factoryOption ) ) {
			$style['options'] = array();
			$data = $response->factoryOption;

			foreach( $data as $item ) {
				$option = $this->set_option( $item );
				$style['options'][] = $option;
				// Recursive options
				if ( isset( $item->ambiguousOption ) ) {
					if ( is_object( $item->ambiguousOption ) ) {
						$option = $this->set_option( $item->ambiguousOption, 'true' );
						$style['options'][] = $option;
					} elseif( is_array( $item->ambiguousOption ) ) {
						foreach ( $item->ambiguousOption as $option ) {
							$option = $this->set_option( $option, 'true' );
							$style['options'][] = $option;
						}
					}
				}
			}
		}

		// style properties as defined at the top of the class in an array of objects
		if ( $data = $response->style ) {
			$style['style'] = $this->set_properties( $data, $this->style_properties );
			$style['style']['body_type_standard'] = $this->get_standard_bt($style['style']['body_type']);
			// Standardize model names
			if ( array_key_exists( $style['style']['model_name'], $this->standard_models ) ) {
				$style['style']['model_name'] = $this->standard_models[$style['style']['model_name']];
			}
		}

		// ^ engine
		// if ( $style['style']['style_id'] == 396211 ) {
		// 	display_var($response->engine);
		// 	exit();
		// }
		if ( isset( $response->engine ) ) {
			$data = $response->engine;
			if ( is_object( $data ) ) {
				$style['engine'] = $this->set_engine_properties( $data );
			} else if ( is_array( $data ) ) {
				foreach ( $data as $engine ) {
					$style['engine'][] = $this->set_engine_properties( $engine );
				}
			}
		}

		// ^ standard equipment
		if ( isset( $response->standard ) ) {
			$data = $response->standard;
			foreach ( $data as $item ) {

				$style['standard'][] = array(
					'type'				=> $item->header->_,
					'description'	=> $item->description,
					'categories'	=> $this->get_standard_categories( $item )
				);
				// If transmission was not grabbed before, grab from equipment
				if ( ! array_key_exists( 'transmission', $style['style'] ) ) {
					if ( strcasecmp( $item->header->_, 'mechanical' ) === 0 && stripos( $item->description, 'Transmission' ) !== false ) {
						$style['style']['transmission'] = self::get_transmission($item->description);
					}
				}
			}

			// transmission in style from standard equipment
			if ( ! array_key_exists( 'transmission', $style['style'] ) ) {
				$style['style']['transmission'] = null;
			}
		}

		// If transmission still not grabbed, check if car is electric
		if ( ! isset( $style['style']['transmission'] ) ) {
			if ( strpos( $style['engine']->fuel_type, 'Electric' ) !== FALSE ) {
				$style['style']['transmission'] = 'Electric';
			}
		}

		// ^ exterior colors
		if ( isset( $response->exteriorColor ) ) {
			$data = $response->exteriorColor;
			foreach ( $data as $item ) {
				$color = array();
				
				// Skip if these fields are empty
				if ( empty( $item->colorCode ) || empty( $item->rgbValue) ) { continue; }

				if ( isset( $item->genericColor ) ) {
					// Grab generic/primary name from object/array
					if ( is_object( $item->genericColor ) ) {
						$color['generic_name'] = $item->genericColor->name;
						$color['primary'] = $item->genericColor->primary;
					} else if ( is_array( $item->genericColor ) ) {
						foreach ( $item->genericColor as $c ) {
							// Store if primary color
							if ( $c->primary ) {
								$color['generic_name'] = $c->name;
								$color['primary'] = $c->primary;
								break;
							}
						}
					}
				}
				if ( isset( $item->colorName ) ) {
					$color['name'] = $item->colorName;
				}
				if ( isset( $item->colorCode ) ) {
					$color['code'] = $item->colorCode;
				}
				if ( isset( $item->rgbValue ) ) {
					$color['rgb_value'] = $item->rgbValue;
				}
				if ( isset( $item->styleId ) ) {
					$color['style_id'] = $item->styleId;
				} else {
					$color['style_id'] = $style['style']['style_id'];
				}
				
				$style['style_colors'][$item->colorCode] = $color;
				$style['style']['exterior_colors'][] = $item->colorName;
			}
		}

		if ( isset( $style['style']['exterior_colors'] ) ) {
			$style['style']['exterior_colors'] = json_encode( array_unique( $style['style']['exterior_colors'] ) );
		} else {
			$style['style']['exterior_colors'] = json_encode( array() );
		}

		// ^ media gallery
		$style['style']['has_media'] = false;
		$style['style']['view_count'] = 0;
		if ( property_exists( $response->style, 'mediaGallery' ) ) {
			if ( $data = $response->style->mediaGallery->view ) {
				$style_id = $response->style->mediaGallery->styleId;
				foreach ( $data as $image ) {
					if ( property_exists( $image, 'url' ) ) {
						// Only need these images, the rest is grabbed via ftp and the sizes are optimized via Kraken 
						if ( $image->width == 1280 && $image->height == 960 && $image->backgroundDescription == 'Transparent' ) {
							$image->styleId = $style_id;
							$fname = explode( '/', $image->url );
							$fname = end( $fname );
							$fname = str_replace( '.png', '', $fname );	
							$image->fileName = $fname;
							$style['view'][] = $this->set_properties( $image, $this->image_gallery_properties );
						}
					}
				}
				$style['style']['view_count'] = count( $style['view'] );
				$style['style']['has_media'] = TRUSE;
			}
		}

		return $style;
	}

	public function get_standard_bt( $cd_body_types ) {
		if ( !array_key_exists ($cd_body_types, $this->body_types ) ) {
			echo 'Please add ' . $cd_body_types . ' to the standardized list of body types';
			exit();
		}
		return $this->body_types[$cd_body_types];
	}

	private function meets_requirements( $styles ) {
		$pass = TRUE;
		$msg = '<b>Styles do not meet all requirements:</b><br>';
		$duplicates = array();
		foreach ( $styles as $style ) {
			$model = $style['style']['model_name'];
			$style_id = $style['style']['style_id'];

			if ( count( $style['options'] ) < 1 ) {
				$msg .= 'No options were pulled for model ' . $model . ' with style_id ' . $style_id . '<br>';
				$pass = FALSE;
			}
			if ( ! isset( $style['style']['msrp'] ) ) {
				$msg .= 'No MSRP was found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;	
			}
			if ( empty( $style['style']['transmission'] ) ) {
				$msg .= 'No transmission was found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;	
			}
			if ( empty( $style['style']['drivetrain'] ) ) {
				$msg .= 'No drivetrain was found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;	
			}
			if ( empty( $style['style']['body_type'] ) ) {
				$msg .= 'No body type was found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;	
			}
			if ( empty( $style['style']['exterior_colors'] ) ) {
				$msg .= 'No exterior colors were found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;
			}
			if ( empty( $style['engine'] ) ) {
				$msg .= 'No engine(s) were found for model ' . $model . ' with style_id ' . $style_id . '<br>'.
				$pass = FALSE;
			}
			if ( $style['style']['has_media'] && $style['style']['view_count'] === 0 ) {
				$msg .= 'Style has images but none were pulled for model ' . $model . ' with style_id ' . $style_id . '<br>';
				$pass = FALSE;
			}
		}
		if ( ! $pass ) {
			$msg .= 'Fix Errors Then Re-Run The Script!';
			return array(
				'type'	=> 'error',
				'msg'		=> $msg
			);
		}
		return $pass;
	}

	private function get_standard_categories( $item ) {
		$categories = '';
		if ( isset( $item->category ) ) {
			if ( is_array( $item->category ) ) {
				foreach ( $item->category as $category ) {
					$categories .= $category->id . ',';
				}
				$categories = substr( $categories, 0, -1 );
			} else {
				$categories = (string)$item->category->id;
			}
		}
		return $categories;
	}

	private function set_properties( $style, $properties ) {

		$returned_properties = array();
		// Loop through all properties we need for this particular part of the api call
		foreach ( $properties as $property ) {
			// Check if a particular property exists in the chrome object returned that we want
			if ( property_exists( $style, $property['property'] ) ) {
				// Assign value of the property to $property_value
				$property_value = $style->{$property['property']};
				$value = array();
				switch ( gettype( $property_value ) ) {
						// If property value is an object
					case 'object':
						// if the value attribute exists for the property array, use this to get the value needed
						if ( array_key_exists( 'value', $property ) ) {
							$value = $this->set_value( $property_value, $property['value'] );
						} else {
							$value = $property_value;
						}
						break;
						// If property is an array
					case 'array':
						// Loop through each object in the array and assign it the value in the value attribute
						foreach ( $property_value as $single_property ) {
							if ( array_key_exists( 'value', $property ) ) {
								$value[] = $this->set_value( $single_property, $property['value'] );
							} else {
								$value[] = $single_property;
							}
						}
						break;
						// In all other cases, you just want to assign it the value the api call gives us
					case 'string':
					default:
						$value = $property_value;
				}
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = json_encode( $value );
				}
				$returned_properties[ $property['field'] ] = $value;
				// if property doesn't exist
			} else {
				$returned_properties[ $property['field'] ] = null;
			}
		}

		return $returned_properties;

	}

	private function set_value( $object, $property ) {

		$value = array();

		switch ( gettype( $property ) ) {
			case 'array':
				foreach ( $property as $item ) {
					if ( property_exists( $object, $item ) ) {
						$value[] = $object->{$item};
					} else {
						$value = null;
					}
				}
				break;
			default:
				$value = $object->{$property};
		}

		return $value;

	}

	private function set_engine_properties( $data ) {

		$engine = new stdClass;
		$engine->engine = '';

		if ( isset( $data->engineType ) && isset( $data->engineType->_ ) ) {
			$engine->engine_type = $data->engineType->_;
		} else {
			$engine->engine_type = 'null';
		}

		if ( isset( $data->fuelType ) && isset( $data->fuelType->_ ) ) {
			$engine->fuel_type = $data->fuelType->_;
		} elseif ( isset( $data->fuel_type ) ) {
			$engine->fuel_type = $data->fuel_type;
		} else {
			$engine->fuel_type = 'null';
		}

		if ( isset( $data->cylinders ) ) {
			$engine->cylinders = $data->cylinders;
		} else {
			$engine->cylinders = 'null';
		}

		$engine->fuel_economy_city_low = ( isset( $data->fuel_economy_city_low ) ? $data->fuel_economy_city_low : 'null' );
		$engine->fuel_economy_hwy_low = ( isset( $data->fuel_economy_hwy_low ) ? $data->fuel_economy_hwy_low : 'null' );
		$engine->fuel_economy_city_high = ( isset( $data->fuel_economy_city_high ) ? $data->fuel_economy_city_high : 'null' );
		$engine->fuel_economy_hwy_high = ( isset( $data->fuel_economy_hwy_high ) ? $data->fuel_economy_hwy_high : 'null' );

		// This will override the above null values if property exists
		if ( isset( $data->fuelEconomy ) ) {
			$this->set_fuel_economy( $engine, $data->fuelEconomy );
		}

		// Might need to rework
		$engine->fuel_capacity_low = ( isset( $data->fuelCapacity->low ) ? $data->fuelCapacity->low : 'null' );
		$engine->fuel_capacity_high = ( isset( $data->fuelCapacity->high ) ? $data->fuelCapacity->high : 'null' );
		$engine->fuel_capacity_unit = ( isset( $data->fuelCapacity->unit ) ? $data->fuelCapacity->unit : 'null' );
		// Remove later
		if ( isset( $data->fuel_capacity_low ) ) { var_dump('wuddahell'); }

		if ( isset( $data->horsepower->value ) ) {
			$engine->horsepower = $data->horsepower->value;
		} else {
			$engine->horsepower = 'null';
		}

		if ( isset( $data->horsepower->rpm ) ) {
			$engine->horsepower_rpm = $data->horsepower->rpm;
		} else {
			$engine->horsepower_rpm = 'null';
		}

		if ( isset( $data->netTorque->value ) ) {
			$engine->net_torque = $data->netTorque->value;
		} else {
			$engine->net_torque = 'null';
		}

		if ( isset( $data->netTorque->rpm ) ) {
			$engine->net_torque_rpm = $data->netTorque->rpm;
		} else {
			$engine->net_torque_rpm = 'null';
		}
		
		
		if ( isset( $data->displacement->value ) ) {
			if ( isset( $data->displacement->value->_ ) ) {
				$engine->displacement = $data->displacement->value->_;
				$engine->engine = $data->displacement->value->_;
			} else {
				$engine->displacement = 'null';
			}
			if ( isset( $data->displacement->value->unit ) ) {
				$engine->displacement_unit = $data->displacement->value->unit;
				if ( $data->displacement->value->unit === 'liters' ) {
					$engine->engine .= 'L';
				}
			} else {
				$engine->displacement_unit = 'null';
			}
		}	else {
			$engine->displacement = 'null';
			$engine->displacement_unit = 'null';
		}

		if ( isset( $data->engineType ) ) {
			$engine_type = str_ireplace( ' Cylinder Engine', '', $data->engineType->_ );
			$engine->engine .= ' ' . $engine_type;
		} else {
			$engine->engine = 'null';
		}

		return $engine;
	}

	private function set_fuel_economy( &$engine, $data ) {

		if ( isset( $data->unit ) ) {

			switch ( $data->unit ) {
				case 'L/100 km':
					$engine->fuel_economy_city_low = ( isset( $data->city->low ) ) ? $data->city->low : 'null';
					$engine->fuel_economy_hwy_low = ( isset( $data->hwy->low ) ) ? $data->hwy->low : 'null';
					$engine->fuel_economy_city_high = ( isset( $data->city->high ) ) ? $data->city->high : 'null';
					$engine->fuel_economy_hwy_high = ( isset( $data->hwy->high ) ) ? $data->hwy->high : 'null';
					break;
				case 'MPG':
					$engine->fuel_economy_city_low = ( isset( $data->city->low ) ) ? round( ( 100 * 4.54609 ) / ( 1.609344 * $data->city->low ), 1 ) : 'null';
					$engine->fuel_economy_hwy_low = ( isset( $data->hwy->low ) ) ? round( ( 100 * 4.54609 ) / ( 1.609344 * $data->hwy->low ), 1 ) : 'null';
					$engine->fuel_economy_city_high = ( isset( $data->city->high ) ) ? round( ( 100 * 4.54609 ) / ( 1.609344 * $data->city->high ), 1 ) : 'null';
					$engine->fuel_economy_hwy_high = ( isset( $data->hwy->high ) ) ? round( ( 100 * 4.54609 ) / ( 1.609344 * $data->hwy->high ), 1 ) : 'null';
					break;
				default:
					$engine->fuel_economy_city_low = 'null';
					$engine->fuel_economy_hwy_low = 'null';
					$engine->fuel_economy_city_high = 'null';
					$engine->fuel_economy_hwy_high = 'null';
			}
		}
	}

	public function update_styles( $styles ) {
		
		// Defaults needed for queries
		$queries = array(
			'styles' => array(
				'values' => array()
			),
			'engines' => array(
				'values' => array()
			),
			'colors' => array(
				'values' => array()
			),
			'media' => array(
				'values' => array()
			),
			'options' => array(
				'values' => array()
			),
			'standard' => array(
				'values' => array()
			),
		);

		foreach ( $styles as $style ) {

			if ( array_key_exists( 'style', $style ) ) {

				$value = $style['style'];
				$style_id = $value['style_id'];

				// Delete all currently existing entries
				$this->db->delete( 'style', array( 'style_id' => $style_id ) );
				$this->db->query( "DELETE FROM media WHERE style_id LIKE '{$style_id}' AND url LIKE '%chromedata%'"); // Delete only chromedata media
				$this->db->delete( 'engine', array( 'style_id' => $style_id ) );
				$this->db->delete( 'standard', array( 'style_id' => $style_id ) );
				$this->db->delete( 'exterior_color', array( 'style_id' => $style_id ) );
				$this->db->delete( 'option', array( 'style_id' => $style_id ) );
				
				$queries['styles']['query'] = 'INSERT style ( style_id, model_code, model_year, model_name, division, subdivision, trim, body_type, body_type_standard, market_class, msrp, drivetrain, transmission, doors, acode, exterior_colors, has_media, view_count ) VALUES ';
				$queries['styles']['prepare'][] = "('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d')";
				array_push( $queries['styles']['values'], $value['style_id'], $value['model_code'], $value['model_year'], $value['model_name'], $value['division'], $value['subdivision'], $value['trim'], $value['body_type'], $value['body_type_standard'], $value['market_class'], $value['msrp'], $value['drivetrain'], $value['transmission'], $value['doors'], $value['acode'], $value['exterior_colors'], $value['has_media'], $value['view_count'] );

				$value = $style['engine'];
				$queries['engines']['query'] = 'INSERT engine ( style_id, engine, engine_type, fuel_type, cylinders, fuel_capacity_high, fuel_capacity_low, fuel_capacity_unit, fuel_economy_hwy_high, fuel_economy_hwy_low, fuel_economy_city_high, fuel_economy_city_low, horsepower, horsepower_rpm, net_torque, net_torque_rpm, displacement, displacement_unit ) VALUES ';

				if ( is_object( $value ) ) {
					$queries['engines']['prepare'][] = "('%d', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s')";
					array_push( $queries['engines']['values'], $style_id, $value->engine, $value->engine_type, $value->fuel_type, $value->cylinders, $value->fuel_capacity_high, $value->fuel_capacity_low, $value->fuel_capacity_unit, $value->fuel_economy_hwy_high, $value->fuel_economy_hwy_low, $value->fuel_economy_city_high, $value->fuel_economy_city_low, $value->horsepower, $value->horsepower_rpm, $value->net_torque, $value->net_torque_rpm, $value->displacement, $value->displacement_unit );
				} else if ( is_array( $value ) ) {
					foreach ( $value as $single_value ) {
						$queries['engines']['prepare'][] = "('%d', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s')";
						array_push( $queries['engines']['values'], $style_id, $single_value->engine, $single_value->engine_type, $single_value->fuel_type, $single_value->cylinders, $single_value->fuel_capacity_high, $single_value->fuel_capacity_low, $single_value->fuel_capacity_unit, $single_value->fuel_economy_hwy_high, $single_value->fuel_economy_hwy_low, $single_value->fuel_economy_city_high, $single_value->fuel_economy_city_low, $single_value->horsepower, $single_value->horsepower_rpm, $single_value->net_torque, $single_value->net_torque_rpm, $single_value->displacement, $single_value->displacement_unit );
					}
				}
			}
			
			if ( array_key_exists('style_colors', $style ) ) {
				$colors = $style['style_colors'];
				$queries['colors']['query'] = 'INSERT exterior_color( style_id, generic_name, name, code, rgb_value ) VALUES ';
				foreach ( $colors as $color ) {
					$queries['colors']['prepare'][] = "('%d', '%s', '%s', '%s', '%s')";
					array_push( $queries['colors']['values'], $color['style_id'], $color['generic_name'], $color['name'], $color['code'], $color['rgb_value'] );
				}
			}

			if ( array_key_exists( 'options', $style ) ) {
				$options = $style['options'];
				
				$queries['options']['query'] = 'INSERT option( option_id, header, style_id, description, is_child, oem_code, chrome_code, msrp_min, msrp_max, categories ) VALUES ';
				foreach ( $options as $option ) {
					$queries['options']['prepare'][] = "('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s')";
					array_push( $queries['options']['values'], $option['id'], $option['header'], $option['styleId'], $option['description'], $option['isChild'], $option['oemCode'], $option['chromeCode'], $option['msrpMin'], $option['msrpMax'], $option['categories'] );
				}
			}

			// Adjust this
			if ( array_key_exists( 'view', $style ) ) {
				$queries['media']['query'] = 'INSERT media ( style_id, type, url, width, height, shot_code, background, file_name, model_name ) VALUES ';
				foreach ( $style['view'] as $image ) {
					$queries['media']['prepare'][] = "('%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s')";
					array_push( $queries['media']['values'], $image['style_id'], 'view', $image['url'], $image['width'], $image['height'], $image['shot_code'], $image['background_description'], $image['file_name'], $style['style']['model_name'] );
				}
			}

			if ( array_key_exists( 'standard', $style ) ) {
				$queries['standard']['query'] = 'INSERT standard ( style_id, type, description, categories ) VALUES ';
				foreach ( $style['standard'] as $item ) {
					$queries['standard']['prepare'][] = "('%d', '%s', '%s', '%s')";
					array_push( $queries['standard']['values'], $style_id, $item['type'], $item['description'], $item['categories'] );
				}
			}
		}
		
		foreach( $queries as $values ) {
			// Incase there are no entries to update for a particular table
			if ( ! isset( $values['query']) || ! isset($values['prepare']) ) { continue; }
			$query = $values['query'] . implode(',', $values['prepare'] );
			$this->db->query( $this->db->prepare( "$query ", $values['values'] ) );
		}
	}

	private function define_equipment_group( $equipment_group_raw ) {

		if ( check_string_for( $equipment_group_raw, array( 'mechanical', 'chassis', 'window', 'mirrors' ) ) ) {
			$equipment_group = 'Performance';
		} elseif ( check_string_for( $equipment_group_raw, array( 'exterior' ) ) ) {
			$equipment_group = 'Appearance';
		} elseif ( check_string_for( $equipment_group_raw, array( 'entertainment', 'audio' ) ) ) {
			$equipment_group = 'Entertainment';
		} elseif ( check_string_for( $equipment_group_raw, array( 'interior', 'convenience', 'air', 'floor mats', 'locks', 'seating' ) ) ) {
			$equipment_group = 'Comfort';
		} elseif ( check_string_for( $equipment_group_raw, array( 'safety' ) ) ) {
			$equipment_group = 'Safety';
		} elseif ( check_string_for( $equipment_group_raw, array( 'engine', 'powertrain', 'transmission' ) ) ) {
			$equipment_group = 'Engine';
		} elseif ( check_string_for( $equipment_group_raw, array( 'accessories' ) ) ) {
			$equipment_group = 'Accessories';
		} elseif ( check_string_for( $equipment_group_raw, array( 'warranty' ) ) ) {
			$equipment_group = 'Warranty';
		} else {
			$equipment_group = 'Other';
		}
		$equipment_details_section = 0;
		return array(
			'equipment_group' => $equipment_group,
			'equipment_details_section' => $equipment_details_section,
		);

	}

	//	Function:	Checks an array for the existence of any of the needles
	private function check_string_for( $haystack, $needles ) {
		foreach ( $needles as $needle ) {
			if ( stripos( $haystack, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}


	private function find_engine_type( $body_text ) {

		if ( check_string_for( $body_text, array( 'i-', 'straight', 'i3', 'i4', 'i5', 'i6', 'i7', 'i8' ) ) ) {
			$engine_type = 'I';
		} elseif ( check_string_for( $body_text, array( 'v6', 'v8', 'v10', 'v12', 'v16', 'v-' ) ) ) {
			$engine_type = 'V';
		} elseif ( check_string_for( $body_text, array( 'boxer', 'h-', 'h4', 'h6' ) ) ) {
			$engine_type = 'H';
		} elseif ( check_string_for( $body_text, array( 'rotary' ) ) ) {
			$engine_type = 'Rotary';
		}

		return $engine_type;
	}

	private function find_drive_train( $body_text ) {

		if ( check_string_for( $body_text, array( 'rear', 'rwd' ) ) ) {
			$drive_train = 'RWD';
		} elseif ( check_string_for( $body_text, array( 'front', 'fwd' ) ) ) {
			$drive_train = 'FWD';
		} elseif ( check_string_for( $body_text, array( 'all', 'awd' ) ) ) {
			$drive_train = 'AWD';
		} elseif ( check_string_for( $body_text, array( '4', 'four', '4wd' ) ) ) {
			$drive_train = '4WD';
		}

		return $drive_train;
	}

	private function transmission_vehicle_filter( $text, $special = '' ) {
		if ( check_string_for( $text, array( 'automatic', 'A4', 'A5', 'A6', 'A7', 'A8' ) ) ) {
			$text = 'Automatic';
		} elseif ( check_string_for( $text, array( 'manual', 'M4', 'M5', 'M6', 'M7', 'M8' ) ) ) {
			$text = 'Manual';
		}
		return $text;
	}

	public function truncate_all( $tables ) {
		foreach ( $tables as $table ) {
			$this->db->query('TRUNCATE ' . $table );
		}
	}
}
