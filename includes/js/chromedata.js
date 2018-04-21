jQuery(document).ready(function($){

	// Might be able to grab this dynamically based on funtions.php
	var functions = [
		{
			title: 'Update All Makes',
			fname: 'update_all_makes',
			desc: 'Updates all makes/divisions ( replaces all divison entries in DB ).',
			args: false
		},
		{
			title: 'Update All Models',
			fname: 'update_all_models',
			desc: 'Updates all models ( replaces all model entries in DB ).',
			args: false
		},
		{
			title: 'Update Styles By Model',
			fname: 'update_styles_by_model',
			desc: 'Grabs styles by model and updates all records in DB for the styles selected.',
			args: true
		},
		{
			title: 'Update Database Images By Model ( may take some time )',
			fname: 'update_db_images_model',
			desc: 'Grabs all styles for model, optimizes and formats images based on url/localfiles, stores on s3 and updates Database Media table for styles.',
			args: true
		},
		{
			title: 'Update All Styles ( may take some time )',
			fname: 'update_all_styles',
			desc: 'Updates styles table by each non-updated model, progress is reported in the output section.',
			args: false
		},
		{
			title: 'Update Database Images ( may take some time )',
			fname: 'update_db_images',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			args: false
		}
	];

	var ajax_path = window.location.href + 'includes/php/ajax.php';

	var _v = new Vue({
		el: '#wrapper',
		data: {
			functions: functions,
			outputs: [],
			models: [],
			updating: [],
			updated: [],
		},
		methods: {
			runFunction: function( event, item ) {
				$('.notification').removeClass('error');
				$('.notification').removeClass('success');
				$('.notification').html('');
				var val = $('#value input').val();

				// Special case
				if ( item.fname == 'update_all_styles' ) {
					update_all_styles(event);
					return;
				}
				// No args provided if needed check
				if ( item.args && val == '' ) {
					$('#value + .notification').addClass('error');
					$('#value + .notification').html('Pelase enter a value!');
					return;
				}

				// Get args
				var args = [];
				switch( item.fname ) {
					case 'update_styles_by_model':
						args.push(val);
						break;
					
					case 'update_db_images_model':
						args.push(val);
						break;
				}

				// Start loading
				$(event.target).parent().addClass('active');
				$(event.target).parent().find('.loader').addClass('active');
				var callback = function( data ) {
					// Stop Loading
					$(event.target).parent().removeClass('active');
					$(event.target).parent().find('.loader').removeClass('active');
				};
				run_php_function( item.fname, args, callback );

			}
		}
	});

	// First run
	run_php_function( 'get_models', [], function(){
		var updating = _v.models.slice();
		for ( var i = 0; i <  _v.updated.length; i++ ) {
			var index = updating.indexOf( _v.updated[i] );
			updating.splice(index, 1);
		}
		_v.updating = updating;
	});

	function run_php_function( fname, args, callback ) {
		$.ajax({
			url: ajax_path,
			method: 'POST',
			data: {
				fname: fname,
				args: JSON.stringify(args)
			},
			success: function( data ) {
				data = JSON.parse( data );

				// Set vue data
				if ( 'outputs' in data ) { _v.outputs = _v.outputs.concat( data.outputs ); }
				if ( 'models' in data ) { _v.models = data.models; }
				if ( 'updated' in data ) { _v.updated = data.updated; }

				callback();											
			}
		});
	}

	// Recursive function to update all model styles one model at a time
	function update_all_styles(event) {
		// Start Loading
		console.time();
		$(event.target).parent().addClass('active');
		$(event.target).parent().find('.loader').addClass('active');

		var callback = function(){
			if ( _v.updating.length != 0 ) {
				var model = _v.updating.splice(0, 1)[0];
				_v.updated.push( model );
				console.log(model);
				run_php_function('update_styles_by_model', [model], callback);
			}else {
				// Finished updating all models
				console.timeEnd();
				$(event.target).parent().removeClass('active');
				$(event.target).parent().find('.loader').removeClass('active');
			}
		};

		callback();
	}

});