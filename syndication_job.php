<?php

require_once( 'images.php' );

//ini_set('xdebug.var_display_max_depth', '10');
//ini_set('xdebug.var_display_max_data', -1);
//ini_set('xdebug.var_display_max_children', -1);
set_time_limit( 3600 );

class Syndication extends CronJob {
	
	private $our_result_array = array();
	private $vehicle_dealer_options = array();
	
	public function __construct() {
	
	
	}

	public function run_syndication( $company_id, $syndication_id ) {
		
		global $database;
		
		$sql_statement = $database->prepare("SELECT * FROM syndication_data WHERE company_id = ? AND syndication_id = ?");
		$sql_statement->bind_param('ss', $company_id, $syndication_id );
		
		$sql_statement->execute();
		$result = $sql_statement->get_result();
		$run_data = $result->fetch_all( MYSQLI_ASSOC );
		
//		var_dump( $run_data );

		foreach ( $run_data as $run ) {
			if ( $run['syndication_special_parser'] != 'chrome' ) {
				$this->import_feed( $run['syndication_id'] );
			} elseif ( $run['syndication_special_parser'] == 'chrome' ) {
				
				switch( $run['syndication_source'] ) {
					case 'serti':
						$vehicle_arg = array( 'strip' => true );
						break;
					default:
						$vehicle_arg = array();
						break;
				}
				
//				var_dump( $run );
				$write_array = array();
				$overwrite_array = array();
				foreach ( $run as $key => $value ) {
					if ( strpos( $key, '_overwrite') !== false && $value == true ) {
						$overwrite_array[] = str_replace( '_overwrite', '', $key );						
					}	
					elseif ( strpos( $key, '_column' ) !== false && $value == true ) {
						$write_array[] = str_replace( '_column', '', $key );						
					}	
				}	
				$vehicle_arg['write_array'] = $write_array;
				$vehicle_arg['overwrite_array'] = $overwrite_array;
				
				
				$result = $database->query( "SELECT vehicle_id FROM vehicle_data WHERE company_id = '" . $run['company_id'] . "' AND date_sold IS NULL" );
				$result_array = $result->fetch_all( MYSQLI_ASSOC );
				
				foreach ( $result_array as $data_array ) {
					$vehicle_arg['vehicle_id'] = $data_array['vehicle_id'];
//					var_dump( $vehicle_arg );
					$this->chrome_get_vehicle_data( $vehicle_arg );
				}

			}
		}

		$sql_statement->close();
		
	}

	//	Function:	Get vehicle data from chrome vin exploder
	//	Info:			vin is primarily used to check, acode is used to filter the options further, by default it will overwrite certain fields
	//	Input:		Args:	vehicle_id [ required ] {int}, 
	//									acode [ optional ] {string}, 
	//									write_array ( 'doors', 'drive_train', 'transmission', 'exterior_color', 'etc' ) [ optional ] {array}
	//									overwrite [ optional ] default false {boolean}
	//									rewrite [ optional ] default false {boolean}
	public function import_feed ( $syndication_id ) {

		global $database;
		global $vms_vehicle_query;

		// Fetch all the option associated with the feed for the import
		$result = $database->query( "SELECT * FROM syndication_data WHERE syndication_id = '$syndication_id'" );
		$result_array = $result->fetch_all( MYSQLI_ASSOC );
		$result_array = $result_array[0];
		$company_id = $result_array['company_id'];
		$syndication_source = $result_array['syndication_source'];
		$primary_feed = $result_array['primary_feed'];
		$syndication_special_parser = $result_array['syndication_special_parser'];
		$syndication_vehicles = $result_array['syndication_vehicles'];
		$syndication_filter_column = $result_array['syndication_filter_column'];
		$syndication_filter_value = $result_array['syndication_filter_value'];

		$vehicle_array = array();

		if ( pathinfo( $syndication_source, PATHINFO_EXTENSION ) == 'csv' ) {
			$data = file_get_contents( $syndication_source );
			$rows = explode( "\n", $data);
			$full_data = array();
			foreach( $rows as $row ) {
				$full_data[] = str_getcsv( $row );
			}

			if ( empty ( $full_data ) ) {
				return false;
			}


			// Loop through the entire data variable
			$inventory_array = array();
			foreach( $full_data as $vehicle_feed ) {

				$vehicle_array = array();

				// Check if filter column exists and if it does, then check if the value is the one we are looking for
				if ( ! empty( $syndication_filter_column ) && ! empty( $syndication_filter_value ) ) {
					if ( $vehicle_feed[ intval( $syndication_filter_column ) - 1 ] != $syndication_filter_value ) {
//						var_dump( $vehicle_feed[ $syndication_filter_column - 1 ], $syndication_filter_value );
						continue;
					}					
				}
				
				if ( $result_array['vin_column'] && $vehicle_feed[ $result_array['vin_column'] - 1 ] ) {
					$vehicle_array['vin'] = $this->filter_vehicle_text( $vehicle_feed[ $result_array['vin_column'] - 1 ], 'vin', $result_array['vin_parse'], $syndication_special_parser );
				} else {
					continue;
				}

				// Loop through all single variables and filter if the value of the vehicle field exists and there is a column
				foreach ( $this->single_feed_variable as $single ) {
					if ( $result_array[ $single . '_column' ] && $vehicle_feed[ $result_array[ $single . '_column' ] - 1 ] ) {
						$vehicle_array[ $single ] = $this->filter_vehicle_text( $vehicle_feed[ $result_array[ $single . '_column' ] - 1 ], $single, $result_array[ $single . '_parse' ], $syndication_special_parser );
					}
				}

				// Loop through all multi variables and filter if the value of the vehicle field exists and there is a column
				foreach ( $this->multi_feed_variable as $multi ) {
					if ( $result_array[ $multi . '_column' ] && $vehicle_feed[ $result_array[ $multi . '_column' ] - 1 ] ) {
						$vehicle_array[ $multi ] = $this->filter_vehicle_text( $vehicle_feed[ $result_array[ $multi . '_column' ] - 1 ], $multi, $result_array[ $multi . '_parse' ], $syndication_special_parser );
					}
				}

				// Skip adding it to the inventory if we do not want to get that saleclass from that source
				if ( ( $syndication_vehicles == 'new' && $vehicle_array['sale_class'] == 'Used' ) || ( $syndication_vehicles == 'used' && $vehicle_array['sale_class'] == 'New' ) ) {
					continue;
				}

				array_push( $inventory_array, $vehicle_array );

			}
			
		} elseif ( pathinfo( $syndication_source, PATHINFO_EXTENSION ) == 'xml' ) {	// XML filter must return an inventory array
			
			$data = file_get_contents( $syndication_source );
			$object = simplexml_load_string( $data, null, LIBXML_NOCDATA );
			$json  = json_encode( $object );
			$full_data = json_decode( $json, true );
			
			$inventory_array = $this->import_read_xml( $syndication_special_parser, $full_data, $result_array );
			
		}

		foreach ( $inventory_array as $vehicle_key => $vehicle ) {

			$update_string = '';
			$vin = $vehicle['vin'];
			$stock_number = $vehicle['stock_number'];
			foreach ( $vehicle as $key => $value ) {

				$vehicle_multi_array = array();

				// Exclude locked vehicle data	
				if ( $vms_vehicle_query->check_lock( '', $key, $vin ) ) {
					unset( $vehicle[ $key ] );
				} elseif ( is_array( $value ) ) { 
					// Create a multi field array
					$vehicle_multi_array[ $key ] = $value;
					unset( $vehicle[ $key ] );
				} elseif ( $result_array[ $key . '_overwrite' ] ) {					
					$vehicle[ $key ] = $database->real_escape_string( $value );
					$value = $database->real_escape_string( $value );
					$update_string .= "$key = '$value', ";
					// Clear batch states related to that field
					$delete_batch_job = new Batch();
					$delete_batch_job->delete_batch_state( array( 'company_id' => $company_id, 'vin' => $vin, 'field_name' => $key ) );
				}

			}

			$fields = implode( ', ', array_keys( $vehicle ) );
			$values = implode( "', '", array_values( $vehicle ) );

			$now = date("Y-m-d H:i:s");
			$vehicle_id = null;

			if ( $primary_feed ) {
				$result = $database->query( "SELECT vehicle_id FROM vehicle_data WHERE company_id = '$company_id' AND stock_number = '$stock_number' AND vin = '$vin'" );
				$result_array = $result->fetch_all( MYSQLI_ASSOC );
				if ( ! empty( $result_array ) ) {
					$sql_statement = "UPDATE vehicle_data SET $update_string date_updated = '$now', date_sold = NULL WHERE company_id = '$company_id' AND stock_number = '$stock_number' AND vin = '$vin';";
					$vehicle_id = $result_array[0]['vehicle_id'];
				} else {
					$sql_statement = "INSERT INTO vehicle_data ( $fields , company_id,  date_added ) SELECT '$values', '$company_id', '$now' FROM user_data WHERE NOT EXISTS ( SELECT * FROM vehicle_data Ve WHERE Ve.company_id = '$company_id' AND Ve.stock_number = '$stock_number' AND Ve.vin = '$vin' ) LIMIT 1;";
				}				
			} else {
				$sql_statement = "UPDATE vehicle_data SET $update_string date_updated = '$now', date_sold = NULL WHERE vin = '$vin' AND company_id = '$company_id'";
			}

			$database->query( $sql_statement );

			if ( $database->error ) {
				var_dump( $database );
				set_error( 'DATABASE', $database->error );
			}

			// Get vehicle id of last updated vehicle
			if ( $primary_feed ) {
				if ( $vehicle_id == null ) {
					$vehicle_id = $database->insert_id;
				}
				$additional_statement = "INSERT INTO additional_vehicle_data ( vehicle_id, syndication_id ) VALUES ( '$vehicle_id', '$syndication_id' ) ON DUPLICATE KEY UPDATE syndication_id = '$syndication_id'";
				array_push( $vehicle_array, $vehicle_id );
			}

			$database->query( $additional_statement );

			// Update the additional/supplemental tables
			foreach ( $vehicle_multi_array as $multi_key => $multi_array ) {
				switch( $multi_key ) {
					case 'image':
						$image_list = array();
						foreach ( $multi_array as $image_index => $image_url ) {
							$image_directory = dirname( dirname( __FILE__ ) ) . '/tmp/';
							$image_name = substr( $image_url, strrpos( $image_url, '/' ) + 1 );
							$image_destination = $image_directory . $image_name;
							$file_size = file_put_contents( $image_destination, file_get_contents( $image_object->url ) );
							if ( $file_size > 100 ) {
								$image_list[] = upload_image_and_crop ( realpath( $image_destination ), $vehicle_id, $company_id, 'all', 1, $image_index + 1 );
							}
						}

						$image_list = implode( "', '", $image_list );

						$database->query( "DELETE FROM image_data WHERE vehicle_id = {$vehicle_id} AND image_hash NOT IN ( '{$image_list}' ) ");

						break;
					case 'option':

						break;
					case 'equipment':
							foreach ( $multi_array as $equipment_description ) {
								$vehicle_arg = array( 'vehicle_id' => $vehicle_id, 'table' => 'equipment_data', 'field_array' => array( 'equipment_description' => $equipment_description ) );
								$vms_vehicle_query->insert_vehicle( array( $vehicle_arg ) );
							}
						break;
					default:


						break;
				}

				// STOPPED 
				$vms_vehicle_query;

			}


		}

	//	var_dump( $inventory_array );

		// Put vehicles on sold
		$sold_query = "SELECT AV.vehicle_id
										FROM additional_vehicle_data AV
										INNER JOIN vehicle_data V
										ON V.vehicle_id = AV.vehicle_id
										WHERE V.source_mode = 'feed' AND V.date_sold IS NULL AND company_id = {$company_id} AND AV.vehicle_id NOT IN ( " . implode( ',', $vehicle_array ) . " ) AND AV.syndication_id = {$syndication_id}";

		var_dump( $sold_query );
//		echo '<br>';

		$result = $database->query( $sold_query );

		$sold_vehicles = $result->fetch_all();

		$sold_vehicle_array = array();
		foreach ( $sold_vehicles as $sold_vehicle ) {
			array_push( $sold_vehicle_array, $sold_vehicle[0] );
		}

		$sold_statement = "UPDATE vehicle_data SET date_sold = '$now' WHERE vehicle_id IN ( " . implode( ',', $sold_vehicle_array ) . " )";

		$database->query( $sold_statement );
		

	}


	//chrome_get_vehicle_data( array( 'vin' => 'KM8SNDHF3HU193058', 'vehicle_id' => '17283' ) );

	//	Function:	Get vehicle data from chrome vin exploder
	//	Info:			vin is primarily used to check, acode is used to filter the options further, by default it will overwrite certain fields
	//	Input:		Args:	vehicle_id [ required ] {int}, 
	//									acode [ optional ] {string}, 
	//									write_array ( 'doors', 'drive_train', 'transmission', 'exterior_color', 'etc' ) [ optional ] {array}
	//									overwrite_array ( 'doors', 'drive_train', 'transmission', 'exterior_color', 'etc' ) [ optional ] {array}
	//									rewrite [ optional ] default false {boolean}
	//									strip [ optional ] default false {boolean} 	// used for serti feeds to strip models and trims 
	public function chrome_get_vehicle_data( $args ) {

		global $database;
		global $vms_vehicle_query;
		extract( $args );

		// Don't want to keep calling the database unless we are doing a repull of chrome data
		if ( ! $rewrite ) {
			$result = $database->query( "SELECT COUNT(*) AS number FROM chrome_explode_data WHERE vehicle_id = '$vehicle_id'" );
			$result_array = $result->fetch_all( MYSQLI_ASSOC );
			if ( $result_array[0]['number'] > 0 ) {
				return false;
			}
			
			$exploded_flag = $vms_vehicle_query->get_vehicle_data( array( 'tables' => array( 'vehicle_data' ), 'vehicle_id' => $vehicle_id, 'advanced_custom_vehicle' => 'V.exploded' ) );

			// Redundant two exploded flags.
			// If vehicle has already been exploded and they don't just want images then don't run again
//			if ( $exploded_flag[0]['exploded'] == 1 ) {
//				return false;
//			}
			
		}
		
		$sale_class = $sale_class[0]['sale_class'];

		if ( ! isset( $vehicle_id ) ) {
			set_error( 'INPUT', 'Missing input information: vehicle_id' );
			return false;
		}

		// Fetch all the option codes associated with this vehicle
		$result = $database->query( "SELECT vin, acode, year, make, model, model_code, trim, body_style, doors, drive_train, exterior_color, interior_color, engine, transmission, sale_class, company_id FROM vehicle_data WHERE vehicle_id = '$vehicle_id'" );
		$result_array = $result->fetch_all( MYSQLI_ASSOC );
		$vin = $result_array[0]['vin'];
		$reducing_acode = $result_array[0]['acode'];
		$reducing_model_code = $result_array[0]['model_code'];
		$sale_class = $result_array[0]['sale_class'];
		$company_id = $result_array[0]['company_id'];
		$exterior_color = $result_array[0]['exterior_color'];
		$interior_color = $result_array[0]['interior_color'];
		$vehicle = $result_array[0];

		// Fetch all the option codes associated with this vehicle
		$sql_option_codes = "SELECT option_code FROM option_data WHERE vehicle_id = '" . $vehicle_id . "'";
		$result = $database->query( $sql_option_codes );
		$option_codes = $result->fetch_all();
		foreach ( $option_codes as $key => $value ) {
			$option_codes[ $key ] = $value[0];
		}

		$url = 'http://services.chromedata.com/Description/7b?wsdl';
		$sc = new SoapClient( $url );

		$account_info = array(
			'number'		=>	'308652',
			'secret'		=>	'8343ac07bb984bcb',
			'country'		=>	'CA',
			'language'	=>	'en'
		);

		$request_data = array( 'accountInfo' => $account_info, 'vin' => $vin, 'includeMediaGallery' => 'Multi-View', 'switch' => array( 'ShowExtendedTechnicalSpecifications', 'ShowConsumerInformation', 'IncludeDefinitions' ) );
		if ( ! empty( $reducing_acode ) ) {
			$request_data['reducingAcode'] = $reducing_acode;
		}
		if ( ! empty( $reducing_model_code ) ) {
			$request_data['manufacturerModelCode'] = $reducing_model_code;
		}	
		if ( ! empty( $option_codes ) ) {
			$request_data['OEMOptionCode'] = $option_codes;
		}
		if ( ! empty( $exterior_color ) ) {
			$request_data['exteriorColorName'] = $exterior_color;
		}
		if ( ! empty( $interior_color ) ) {
			$request_data['interiorColorName'] = $interior_color;
		}

		$result = $sc->__soapCall('describeVehicle', array( $request_data ));

		// If serti we want to strip the trim of naming conventions
		if ( $strip ) {		

			$reducing_trim = $this->reduce_trim( $result_array[0]['trim'], $result->vinDescription->modelName );

			if ( ! empty( $reducing_trim ) ) {
				$request_data['trimName'] = $reducing_trim;
			}

		}
		
//		var_dump( $result );

		if ( ( $result->responseStatus->responseCode == 'Successful' && $result->responseStatus->description == 'Successful' ) || ( $result->responseStatus->responseCode == 'ConditionallySuccessful' && $result->responseStatus->description == 'ConditionallySuccessful' ) ) {

			$equipment_array = array();

			// Parse standard for equipment	
			if ( is_array( $result->standard ) || is_object( $result->standard ) ) {
				foreach ( $result->standard as $equipment_object ) {
					$equipment_information = $this->define_equipment_group( $equipment_object->header->_ );
					$equipment_information['equipment_description'] = $equipment_object->description;
					array_push( $equipment_array, $equipment_information );
				}
			}

			// Parse genericEquipment for equipment
			if ( is_array( $result->genericEquipment ) || is_object( $result->genericEquipment ) ) {
				foreach ( $result->genericEquipment as $equipment_object ) {

					$equipment_information = $this->define_equipment_group( $equipment_object->definition->header->_ . ' ' . $equipment_object->definition->group->_ );
					$equipment_information['equipment_description'] = $equipment_object->definition->category->_;
					array_push( $equipment_array, $equipment_information );

					if ( $equipment_object->definition->header->_ == 'Transmission' && $equipment_object->definition->group->_ == 'Powertrain' && $this->check_string_for( $equipment_object->definition->category->_, array( 'A/T', 'M/T' ) ) ) {
						$transmission = $equipment_object->definition->category->_;
						$transmission = str_replace( array( 'A/T', 'M/T' ), array( 'Automatic', 'Manual' ), $transmission );
	//					var_dump( $transmission );
					}
					if ( $equipment_object->definition->header->_ == 'Drivetrain' && $equipment_object->definition->group->_ == 'Powertrain' ) {
						$drive_train_string .= $equipment_object->definition->category->_;
	//					var_dump( $drive_train_text );
					}

				}
			}

			// Parse consumerInformation for equipment warranty
			if ( is_array( $result->consumerInformation ) || is_object( $result->consumerInformation ) ) {
				foreach ( $result->consumerInformation as $equipment_object ) {
					if ( $equipment_object->type->_ != 'Warranty' ) {
						continue;
					}
					foreach ( $equipment_object->item as $equipment_object_item ) {
						$equipment_information = $this->define_equipment_group( 'warranty' );
						$equipment_information['equipment_description'] = $equipment_object_item->name . ': ' . $equipment_object_item->value;
						array_push( $equipment_array, $equipment_information );
					}
				}
			}

			// Parse factoryOption for equipment
			if ( is_array( $result->factoryOption ) || is_object( $result->factoryOption ) ) {
				foreach ( $result->factoryOption as $equipment_object ) {
					$equipment_information = $this->define_equipment_group( $equipment_object->header->_ );
					$equipment_information['equipment_description'] = $equipment_object->description;
					array_push( $equipment_array, $equipment_information );
				}
			}

			$engine_cylinder_string = '';
			// Parse technical specifications for equipment
			if ( is_array( $result->technicalSpecification ) || is_object( $result->technicalSpecification ) ) {
				foreach ( $result->technicalSpecification as $equipment_object ) {
					if ( $equipment_object->definition->group->_ == 'Powertrain' && $equipment_object->definition->header->_ == 'Engine' && $equipment_object->definition->title->_ == 'Engine Type' ) {
						$engine_cylinder_string .= $equipment_object->value->value;
					} elseif ( $equipment_object->definition->group->_ == 'Powertrain' && $equipment_object->definition->header->_ == 'Transmission' && $equipment_object->definition->title->_ == 'Drivetrain' ) {
					$drive_train_string .= $equipment_object->value->value;
					}
				}
			}

			// Parse engine
			$engine_cylinder_string .= $result->engine->engineType->_;
			$engine_displacement = ( is_array( $result->engine->displacement->value ) ) ? $result->engine->displacement->value[0]->_ . $result->engine->displacement->value[0]->unit : $result->engine->displacement->value->_ . $result->engine->displacement->value->unit;
			$engine_displacement = str_replace( array( 'liters' ), array( 'L' ), $engine_displacement );
			$engine_cylinder = ( isset ( $result->engine->cylinders ) ) ? $this->find_engine_type( $engine_cylinder_string ) . '-' . $result->engine->cylinders : $this->find_engine_type( $engine_cylinder_string );
			$engine = ( ! empty( $engine_cylinder ) && ! empty( $engine_displacement ) ) ? $engine_displacement . ' ' . $engine_cylinder : '';
	//		var_dump( $engine );

			if ( $result->engine->fuelEconomy->unit == 'L/100 km' ) {
				$fuel_economy_city_km = $result->engine->fuelEconomy->city->low;
				$fuel_economy_highway_km = $result->engine->fuelEconomy->hwy->low;
			} elseif ( $result->engine->fuelEconomy->unit == 'MPG' ) {	// convert from MPG to L/100km
				$fuel_economy_city_km = round( ( 100 * 4.54609 ) / ( 1.609344 * $result->engine->fuelEconomy->city->low ), 1 );
				$fuel_economy_highway_km = round( ( 100 * 4.54609 ) / ( 1.609344 * $result->engine->fuelEconomy->hwy->low ), 1 );			
			}

			if ( isset ( $result->engine->fuelCapacity ) ) {
				$equipment_information = $this->define_equipment_group( 'engine' );
				$equipment_information['equipment_description'] = 'Fuel Capacity: ' . $result->engine->fuelCapacity->low . $result->engine->fuelCapacity->unit;
				array_push( $equipment_array, $equipment_information );
			}


			// Insert Equipment Data
			if ( ( empty( $write_array ) || in_array( 'equipment', $write_array ) ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'equipment' ) ) {
				$sql_statement = "INSERT INTO equipment_data ( vehicle_id, equipment_group, equipment_description, equipment_details_section, exploded_equipment, date_added ) VALUES";

				$equipment_count = count( $equipment_array );
				foreach ( $equipment_array as $equipment ) {
					$sql_statement .= " ( '" . $vehicle_id . "', '" . $database->real_escape_string( $equipment['equipment_group'] ) . "', '" . $database->real_escape_string( $equipment['equipment_description'] ) . "', " . $database->real_escape_string( $equipment['equipment_details_section'] ) . ", " . true . ", '" . date("Y-m-d H:i:s") . "' )";
					$equipment_count--;
					if ( $equipment_count ) {
						$sql_statement .= ', ';
					}
				}
				$sql_statement .= ' ON DUPLICATE KEY UPDATE date_added = "' . date("Y-m-d H:i:s") . '"';
				$equipment_loaded_bool = true;
				$database->query( $sql_statement );

				if ( $database->error ) {
					set_error( 'DATABASE', $database->error );
				}
			}		

			// Insert Image Data
			if ( ( empty( $write_array ) || in_array( 'image', $write_array ) ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'image' ) && $sale_class != "Used" ) {

				
				// Check if images already exist for that vehicle
				$sql_statement = "SELECT COUNT(*) FROM image_data WHERE vehicle_id = '$vehicle_id'";
				$image_result = $database->query( $sql_statement );
				$row = $image_result->fetch_row();
				if ( $row[0] == 0 ) {
					$this->download_chrome_image( $vehicle_id );
					
					
//					var_dump( $raw_image_object );
				}
			}

			$field_array = array();

			if ( ( empty( $write_array ) || in_array( 'year', $write_array ) ) && ( in_array( 'year', $overwrite_array ) || $vehicle['year'] == '' || $vehicle['year'] == 0  ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'year' ) && ( $result->vinDescription->modelYear != '' && $result->vinDescription->modelYear != NULL ) ) {
				$field_array['year'] = $result->vinDescription->modelYear;
	//			var_dump( 'update year: ' . $field_array['year'] );
			}
			if ( ( empty( $write_array ) || in_array( 'make', $write_array ) ) && ( in_array( 'make', $overwrite_array ) || $vehicle['make'] == '' || $vehicle['make'] == NULL  ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'make' ) && ( $result->vinDescription->division != '' && $result->vinDescription->division != NULL ) ) {
				$field_array['make'] = $result->vinDescription->division;
	//			var_dump( 'update make: ' . $field_array['make'] );
			}
			if ( ( empty( $write_array ) || in_array( 'model', $write_array ) ) && ( in_array( 'model', $overwrite_array ) || $vehicle['model'] == '' || $vehicle['model'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'model' ) && ( $result->vinDescription->modelName != '' && $result->vinDescription->modelName != NULL ) ) {
				$field_array['model'] = $result->vinDescription->modelName;
	//			var_dump( 'update model: ' . $field_array['model'] );
			}
			if ( ( empty( $write_array ) || in_array( 'acode', $write_array ) ) && ( in_array( 'acode', $overwrite_array ) || $vehicle['acode'] == '' || $vehicle['acode'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'acode' ) && ( $result->vinDescription->acode_ != '' && $result->vinDescription->acode_ != NULL ) ) {
				$field_array['acode'] = ( is_array( $result->style->acode ) ) ? $result->style->acode[0]->_ : $result->style->acode->_;
	//			var_dump( 'update acode: ' . $field_array['acode'] );
			}
			if ( ( empty( $write_array ) || in_array( 'trim', $write_array ) ) && ( in_array( 'trim', $overwrite_array ) || $vehicle['trim'] == '' || $vehicle['trim'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'trim' ) && ( ( $result->style->trim != '' && $result->style->trim != NULL ) || $strip ) ) {
				$field_array['trim'] = $result->style->trim;
				if ( $strip && empty( $field_array['trim'] ) ) {
					$field_array['trim'] = $this->reduce_trim( $result_array[0]['trim'], $result->vinDescription->modelName );
				}
//				var_dump( 'update trim: ' . $field_array['trim'] );
			}
			if ( ( empty( $write_array ) || in_array( 'doors', $write_array ) ) && ( in_array( 'doors', $overwrite_array ) || $vehicle['doors'] == '' || $vehicle['doors'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'doors' ) && ( $result->style->passDoors != '' && $result->style->passDoors != NULL ) ) {
				$field_array['doors'] = $result->style->passDoors;
	//			var_dump( 'update doors: ' . $field_array['doors'] );
			}
			if ( ( empty( $write_array ) || in_array( 'body_style', $write_array ) ) && ( in_array( 'body_style', $overwrite_array ) || $vehicle['body_style'] == '' || $vehicle['body_style'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'body_style' ) ) {
				$body_style = $this->find_body_style( $result->vinDescription->bodyType . ' ' . $result->style->bodyType->_ . ' '. $result->style->marketClass->_ );
				$field_array['body_style'] = $body_style;
//				var_dump( 'update body_style: ' . $field_array['body_style'] );
			}
			if ( ( empty( $write_array ) || in_array( 'engine', $write_array ) ) && ( in_array( 'engine', $overwrite_array ) || $vehicle['engine'] == '' || $vehicle['engine'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'engine' ) && ! empty( $engine ) ) {
				$field_array['engine'] = $engine;
	//			var_dump( 'update engine: ' . $field_array['engine'] );
			}
			if ( ( empty( $write_array ) || in_array( 'drive_train', $write_array ) ) && ( in_array( 'drive_train', $overwrite_array ) || $vehicle['drive_train'] == '' || $vehicle['drive_train'] == NULL ) && ! $vms_vehicle_query->check_lock( $vehicle_id, 'drive_train' ) ) {
				$drive_train = $this->find_drive_train( $result->style->drivetrain . ' ' . $drive_train_string );
				$field_array['drive_train'] = $drive_train;
	//			var_dump( 'update drive_train: ' . $field_array['drive_train'] );
			}

			// Don't update with empty values that it can't find
			foreach ( $field_array as $key => $value ) {
				if ( $strip && $key == 'trim' ) {
					continue;
				}
				if ( $value == '' || $value == NULL ) {
					unset( $field_array[ $key ] );
				}
			}

			$args = array( 
				'table' => 'vehicle_data',
				'vehicle_id' => $vehicle_id,
				'field_array' => $field_array
			);

			$vehicle_updated = $vms_vehicle_query->update_vehicle( $args );
			if ( ! $vehicle_updated ) {
				return false;
			}

			// Add images and equipment to the array to check off that they have been loaded into the database
			if ( $images_loaded_bool ) {
				$field_array['images'] = true;
			}
			if ( $equipment_loaded_bool ) {
				$field_array['equipment'] = true;
			}

			$field_count = count( $field_array );

			$sql_statement = "INSERT INTO chrome_explode_data ( vehicle_id, chrome_field, date_added ) VALUES";
			foreach( $field_array as $field_updated => $value ) {

				$sql_statement .= " ( '" . $vehicle_id . "', '" . $database->real_escape_string( $field_updated ) . "', '" . date("Y-m-d H:i:s") . "' )";
				$field_count--;
				if ( $field_count ) {
					$sql_statement .= ', ';
				}
			}
			$sql_statement .= ' ON DUPLICATE KEY UPDATE date_added = "' . date("Y-m-d H:i:s") . '"';

			$database->query( $sql_statement );
	//		var_dump( $database );
	//		var_dump( $sql_statement );

			return true;
		} else {
			return false;
		}
	}


	// Supporting Functions //

	//	Function: Define the equipment group based on the input string, and whether it will be placed in the equipment details section
	//	Input:		String equipment_group_raw string that we will parse
	//	Output:		appearance, capabaility, comfort, convenience, entertainment, performance, safety, option package, other
	private function define_equipment_group( $equipment_group_raw ) {

		if ( $this->check_string_for( $equipment_group_raw, array( 'mechanical', 'chassis', 'window', 'mirrors' ) ) ) {
			$equipment_group = 'Performance';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'exterior' ) ) ) {
			$equipment_group = 'Appearance';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'entertainment', 'audio' ) ) ) {
			$equipment_group = 'Entertainment';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'interior', 'convenience', 'air', 'floor mats', 'locks', 'seating' ) ) ) {
			$equipment_group = 'Comfort';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'safety' ) ) ) {
			$equipment_group = 'Safety';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'engine', 'powertrain', 'transmission' ) ) ) {
			$equipment_group = 'Engine';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'accessories' ) ) ) {
			$equipment_group = 'Accessories';
		} elseif ( $this->check_string_for( $equipment_group_raw, array( 'warranty' ) ) ) {
			$equipment_group = 'Warranty';
		} else {
			$equipment_group = 'Other';
		}
		$equipment_details_section = 0;
		return array( 'equipment_group' => $equipment_group, 'equipment_details_section' => $equipment_details_section );

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

	//	Function:	Looks through the input to parse for body style
	private function find_body_style( $body_text ) {

		if ( $this->check_string_for( $body_text, array( 'sedan' ) ) ) {
			$body_style = 'Sedan';
		} elseif ( $this->check_string_for( $body_text, array( 'coupe' ) ) ) {
			$body_style = 'Coupe';
		} elseif ( $this->check_string_for( $body_text, array( 'hatchback' ) ) ) {
			$body_style = 'Hatchback';
		} elseif ( $this->check_string_for( $body_text, array( 'convertible' ) ) ) {
			$body_style = 'Convertible';
		} elseif ( $this->check_string_for( $body_text, array( 'truck', 'pickup' ) ) ) {
			$body_style = 'Truck';
		} elseif ( $this->check_string_for( $body_text, array( 'sport utility', 'utility' ) ) ) {
			$body_style = 'SUV';
		} elseif ( $this->check_string_for( $body_text, array( 'van' ) ) ) {
			$body_style = 'Van';
		} elseif ( $this->check_string_for( $body_text, array( 'wagon' ) ) ) {
			$body_style = 'Wagon';
		} else {
//			var_dump( $body_text );
			$body_style = 'Unclassified';
		}

		return $body_style;

	}

	private function find_engine_type( $body_text ) {

		if ( $this->check_string_for( $body_text, array( 'i-', 'straight', 'i3', 'i4', 'i5', 'i6', 'i7', 'i8' ) ) ) {
			$engine_type = 'I';
		} elseif ( $this->check_string_for( $body_text, array( 'v6', 'v8', 'v10', 'v12', 'v16', 'v-' ) ) ) {
			$engine_type = 'V';
		} elseif ( $this->check_string_for( $body_text, array( 'boxer', 'h-', 'h4', 'h6' ) ) ) {
			$engine_type = 'H';
		} elseif ( $this->check_string_for( $body_text, array( 'rotary' ) ) ) {
			$engine_type = 'Rotary';
		}

		return $engine_type;
	}

	private function find_drive_train( $body_text ) {

		if ( $this->check_string_for( $body_text, array( 'rear', 'rwd' ) ) ) {
			$drive_train = 'RWD';
		} elseif ( $this->check_string_for( $body_text, array( 'front', 'fwd' ) ) ) {
			$drive_train = 'FWD';
		} elseif ( $this->check_string_for( $body_text, array( 'all', 'awd' ) ) ) {
			$drive_train = 'AWD';
		} elseif ( $this->check_string_for( $body_text, array( '4', 'four', '4wd' ) ) ) {
			$drive_train = '4WD';
		}

		return $drive_train;
	}


	private function filter_vehicle_text( $text, $filter, $flag, $special ) {

		if ( $flag ) {

			$function_name = $filter . '_vehicle_filter';
			if ( method_exists ( $this, $function_name ) ) {
				$text = $this->$function_name( $text, $special );
			}

		} 

		return $text;
	}

	private function sale_class_vehicle_filter( $text, $special = '' ) {

		if ( $this->check_string_for( $text, array( 'demo', 'new' ) )  ) {
			$text = 'New';
		} else {
			$text = 'Used';
		}

		return $text;

	}



	private function make_vehicle_filter( $text, $special = '' ) {

		if ( $special == 'serti' ) {

		}

		return $text;

	}

	private function model_vehicle_filter( $text, $special = '' ) {

		return $text;
	}

	private function transmission_vehicle_filter( $text, $special = '' ) {

		if( $this->check_string_for( $text, array( 'automatic', 'A4', 'A5', 'A6', 'A7', 'A8' ) ) ) {
			$text = 'Automatic';
		} elseif ( $this->check_string_for( $text, array( 'manual', 'M4', 'M5', 'M6', 'M7', 'M8' ) ) ) {
			$text = 'Manual';
		}

		return $text;

	}
	
	private function demo_vehicle_filter( $text, $special = '' ) {

		if ( $this->check_string_for( $text, array( 'demo' ) ) || $text === true || $text === 1 ) {
			$text = true;
		} else {
			$text = false;
		}

		return $text;

	}
	
	private function inactive_vehicle_filter( $text, $special = '' ) {

		if ( $this->check_string_for( $text, array( 'inactive' ) ) || $text === true || $text === 1 ) {
			$text = true;
		} else {
			$text = false;
		}

		return $text;

	}
	
	private function category_vehicle_filter( $text, $special = '' ) {

		$text = array_map( 'trim', explode( '|', $text ) );
		
		return $text;

	}
	
	private function icon_vehicle_filter( $text, $special = '' ) {
		
		$text = array_map( 'trim', explode( '|', $text ) );
		
		return $text;

	}
	
	private function option_vehicle_filter( $text, $special = '' ) {

		$text = array_map( 'trim', explode( '|', $text ) );

		return $text;

	}
	
	private function video_vehicle_filter( $text, $special = '' ) {

		$text = array_map( 'trim', explode( '|', $text ) );

		return $text;

	}
	
	private function image_vehicle_filter( $text, $special = '' ) {

		$text = array_map( 'trim', explode( '|', $text ) );

		return $text;

	}
	
	private function equipment_vehicle_filter( $text, $special = '' ) {
		
		$text = array_map( 'trim', explode( '|', $text ) );

		return $text;
		
	}

	
	private function reduce_trim( $trim, $model ) {

		if ( empty( $trim ) || empty( $model ) ) {
			return NULL;
		}

		$model = str_replace( '-', ' ', $model );
		$model_array = explode( ' ', $model );
	//	var_dump( $model_array );

		foreach ( $model_array as $model ) {
			$trim = $this->str_replace_once( $model, '', $trim );
		}
		$trim = trim( $trim );

	//	var_dump( $trim );

		return $trim;

	}
	
	
	// Function: import read thee xml file and format it into an array to be inserted into the database
	private function import_read_xml( $syndication_special_parser, $full_data, $result_array ) {
		
		$inventory_array = array();
		
		switch( $syndication_special_parser ) {
			case 'boast': 
				
//				var_dump( $full_data );
				
				$boast_single_mapping = array( 'VIN' => 'vin', 'Stock_Number' => 'stock_number', 'Year' => 'year', 'Make' => 'make', 'Model' => 'model', 'SubModel_Trim' => 'trim', 'Body_Style' => 'body_style', 'Exterior_Colour' => 'exterior_color', 'Interior_Colour' => 'interior_color', 'Mileage' => 'odometer', 'Doors' => 'doors', 'Transmission' => 'transmission', 'Cylinders' => 'engine', 'Price' => 'asking_price', 'Description' => 'description', 'Certified' => 'certified', 'Sale_Class' => 'sale_class' );
								
				foreach( $full_data as $inventory_feed ) {
					
					
					if ( ! empty( $syndication_filter_column ) && ! empty( $syndication_filter_value ) ) {
						if ( $inventory_feed[ $syndication_filter_column ] != $syndication_filter_value ) {
							continue;
						}					
					}
					
					foreach( $inventory_feed['Inventory'] as $vehicle_feed ) {
						
						$vehicle_array = array();
						
						if ( $vehicle_feed['VIN'] ) {
							$vehicle_array['vin'] = $this->filter_vehicle_text( $vehicle_feed['VIN'], 'vin', $result_array['vin_parse'], $syndication_special_parser );
						} else {
							continue;
						}
						
						
						// Loop through all single variables and filter if the value of the vehicle field exists and there is a column
						foreach ( $boast_single_mapping as $key => $single ) {
							if ( $vehicle_feed[ $key ] ) {
								$vehicle_array[ $single ] = $this->filter_vehicle_text( $vehicle_feed[ $key ], $single, $result_array[ $single . '_parse' ], $syndication_special_parser );
							}
						}
						
						// Loop through all multi variables and filter if the value of the vehicle field exists and there is a column
						$vehicle_array['image'] = $vehicle_feed['Images']['Photo'];
						$vehicle_array['equipment'] = $vehicle_feed['Features']['Feature'];
						$vehicle_array['video'] = array( $vehicle_feed['YouTube'] );
						
						array_push( $inventory_array, $vehicle_array );
						
					}
					
				}
						
				
				break;
			default:
				
				echo 'Parser not found';
				
		}
		
		
		var_dump( $inventory_array );
		return $inventory_array;
		
		
	}

	private function str_replace_once( $str_pattern, $str_replacement, $string ) {

			if (stripos($string, $str_pattern) !== false){
					$occurrence = stripos($string, $str_pattern);
					return substr_replace($string, $str_replacement, stripos($string, $str_pattern), strlen($str_pattern));
			}

			return $string;
	}
	
	// Function: Download chrome color matched images for the vehicle
	// 
	public function download_chrome_image( $vehicle_id ) {
		
		global $database;
		
		// Fetch all the option codes associated with this vehicle
		$result = $database->query( "SELECT vin, acode, year, make, model, model_code, trim, body_style, doors, drive_train, exterior_color, interior_color, engine, transmission, sale_class, company_id FROM vehicle_data WHERE vehicle_id = '$vehicle_id'" );
		$result_array = $result->fetch_all( MYSQLI_ASSOC );
		$vin = $result_array[0]['vin'];
		$reducing_acode = $result_array[0]['acode'];
		$reducing_model_code = $result_array[0]['model_code'];
		$sale_class = $result_array[0]['sale_class'];
		$company_id = $result_array[0]['company_id'];
		$exterior_color = $result_array[0]['exterior_color'];
		$interior_color = $result_array[0]['interior_color'];
		$vehicle = $result_array[0];

		// Fetch all the option codes associated with this vehicle
		$sql_option_codes = "SELECT option_code FROM option_data WHERE vehicle_id = '" . $vehicle_id . "'";
		$result = $database->query( $sql_option_codes );
		$option_codes = $result->fetch_all();
		foreach ( $option_codes as $key => $value ) {
			$option_codes[ $key ] = $value[0];
		}

		$url = 'http://services.chromedata.com/Description/7b?wsdl';
		$sc = new SoapClient( $url );

		$account_info = array(
			'number'		=>	'308652',
			'secret'		=>	'8343ac07bb984bcb',
			'country'		=>	'CA',
			'language'	=>	'en'
		);

		$request_data = array( 'accountInfo' => $account_info, 'vin' => $vin );
		if ( ! empty( $reducing_acode ) ) {
			$request_data['reducingAcode'] = $reducing_acode;
		}
		if ( ! empty( $reducing_model_code ) ) {
			$request_data['manufacturerModelCode'] = $reducing_model_code;
		}	
		if ( ! empty( $option_codes ) ) {
			$request_data['OEMOptionCode'] = $option_codes;
		}
		if ( ! empty( $exterior_color ) ) {
			$request_data['exteriorColorName'] = $exterior_color;
		}
		if ( ! empty( $interior_color ) ) {
			$request_data['interiorColorName'] = $interior_color;
		}

		$result = $sc->__soapCall( 'describeVehicle', array( $request_data ) );

		$style_id = $result->style->id;
//		var_dump( $request_data, $result );
		if ( empty( $style_id ) ) {
			return false;
		}
		$color_string = $exterior_color . $result->factoryOption->chromeCode ;
		
		$ftp_server = 'ftp.chromedata.com';
		$ftp_user = 'u311191';
		$ftp_pass = 'con191';

		// set up a connection or die
		$conn_id = ftp_connect($ftp_server) or set_error( 'FTP', "Couldn't connect to $ftp_server" ); 

		// try to login

		ftp_pasv( $conn_id, true );
		if ( ! @ftp_login( $conn_id, $ftp_user, $ftp_pass ) ) {
			set_error( 'FTP', "Couldn't connect as $ftp_user" );
		}

		$tmp_directory = dirname( dirname( __FILE__ ) ) . '/tmp/';


		// 3 Colour Mapped Images
		$image_map_array = array( 'color_match_0' => array( "source" => "/media/ChromeImageGallery/CAmapping/ColorMatched/White/1280/colormap.txt", 
																											 "destination" => $tmp_directory . "colormap0.txt",
																											 "type" => "color",
																											 'image_directory' => "/media/ChromeImageGallery/ColorMatched/White/1280/" ),
														 'color_match_1' => array( "source" => "/media/ChromeImageGallery/CAmapping/ColorMatched_01/White/1280/colormap_01.txt",
																											"destination" => $tmp_directory . "colormap1.txt",
																											 "type" => "color",
																											'image_directory' => "/media/ChromeImageGallery/ColorMatched_01/White/1280/" ),
														 'color_match_2' => array( "source" => "/media/ChromeImageGallery/CAmapping/ColorMatched_02/White/1280/colormap_02.txt",
																											"destination" => $tmp_directory . "colormap2.txt",
																											 "type" => "color",
																											'image_directory' => "/media/ChromeImageGallery/ColorMatched_02/White/1280/" ),
														 'expanded_match' => array( "source" => "/media/ChromeImageGallery/CAmapping/Expanded/White/1280/vehiclemap.txt",
																											 "destination" => $tmp_directory . "vehiclemap.txt",
																											 "type" => "expanded",
																											 "image_directory" => "/media/ChromeImageGallery/Expanded/White/1280/" )
														);

		//var_dump( $color_matched_map_array );


		ftp_pasv( $conn_id, true );
		foreach( $image_map_array as $image_map ) {
			if ( file_exists( $image_map['destination'] ) ) {
				$mod_date = date( "Ymd", filemtime( $image_map['destination'] ) );
				$date = date( "Ymd" );
				if ( $mod_date >= $date ) {
					continue;
				}
			}

			if ( ftp_get( $conn_id, $image_map['destination'], $image_map['source'], FTP_ASCII ) ) {
		//		var_dump( $image_map['source'] );
			}
		}

		foreach( $image_map_array as $image_map ) {

			// Pull Images
			$images_array = array();

			$file = fopen( $image_map['destination'], 'rt' );
			$flag_started = false;

			if ( $image_map['type'] == 'color' ) {
				while( ! feof( $file ) ) {
					$line = rtrim( fgets( $file ), "\r\n" );
					$info = explode( ',', $line );

					if ( $info[0] == $style_id ) {
						// clean the information of the ~
						$line = str_replace( '~', '', $line );
						$cleanse_info = explode( ',', $line );

						array_push( $images_array, $cleanse_info );
						$flag_started = true;
					} elseif ( $flag_started ) {
						$flag_started = false;
						break;
					}

				}

				$file_name = '';
				foreach( $images_array as $image_info ) {
					if ( stripos( $color_string, $image_info[3] ) !== false ) {
						$file_directory = $image_info[1];
						$file_name = $image_info[2];
					}
				}

				if ( empty( $file_name ) ) {
					$file_directory = $images_array[0][1];
					$file_name = $images_array[0][2];
				}

				if ( ftp_get( $conn_id, $tmp_directory . $file_name, $image_map['image_directory'] . $file_directory . '/' . $file_name, FTP_BINARY ) ) {
					upload_image_and_crop( realpath( $tmp_directory . $file_name ), $vehicle_id, $company_id, 'all', 1 );
				} else {
					return false;
				}
			} elseif ( $image_map['type'] == 'expanded' ) {

				while( ! feof( $file ) ) {
					$line = rtrim( fgets( $file ), "\r\n" );
					$info = explode( ',', $line );

					if ( $info[0] == $style_id ) {
						// clean the information of the ~
						$image_id = str_replace( '~', '', $info[1] );
						$image_list = ftp_nlist( $conn_id, $image_map['image_directory'] . $image_id );
						sort( $image_list );				
						foreach ( $image_list as $image_file ) {
							$image_exploded = explode( '/', $image_file );
							$file_name = end( $image_exploded );
		//					var_dump( $file_name );
							$image_code = explode( '_', $file_name );
							$image_code = end( $image_code );

							// Only download interior shots
							if ( ! $this->check_string_for( $image_code, array( '01', '02', '03', '04', '05', '06', '07', '08', '09' ) ) ) {

								if ( ftp_get( $conn_id, $tmp_directory . $file_name, $image_file, FTP_BINARY ) ) {
									upload_image_and_crop( realpath( $tmp_directory . $file_name ), $vehicle_id, $company_id, 'all', 1 );
									
								} else {
									return false;
								}
							}
						}

						break;

					}
				}

			}
			
		}
		
			
		return true;


	}
	
}


$vms_syndication = new Syndication();


?>