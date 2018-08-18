jQuery(document).ready(function ($) {

	// Might be able to grab this dynamically based on funtions.php
	var functions = [
		{
			title: '1) Update All Makes',
			fname: 'update_all_makes',
			desc: 'Updates all makes/divisions ( replaces all divison entries in DB ).',
			args: false
		},
		{
			title: '2) Update All Models',
			fname: 'update_all_models',
			desc: 'Updates all models ( replaces all model entries in DB ).',
			args: false
		},
		{
			title: '3) Update All Styles ( may take a long time )',
			fname: 'r_update_all_styles',
			desc: 'Updates styles table by each non-updated model, progress is reported in the output section.',
			args: false
		},
		{
			title: '3.1) Update Styles By Model',
			fname: 'update_styles_by_model',
			desc: 'Grabs styles by model and updates all records in DB for the styles selected.',
			args: true
		},
		{
			title: '4) Update All Database Views ( may take a long time )',
			fname: 'r_update_all_views',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			args: false
		},
		{
			title: '4.1) Update Views Media By Model ( may take some time )',
			fname: 'update_views_by_model',
			desc: 'Grabs all styles for model, optimizes and formats images based on url/localfiles, stores on s3 and updates Database Media table for styles.',
			args: true
		},
		{
			title: '5) Update All S3 to FTP Colorized ( may take a long time )',
			fname: 'r_update_all_ftps3',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			args: false
		},
		{
			title: '5.1) Update FTP to S3 Colorized Media By Model ( may take some time )',
			fname: 'update_ftps3_by_model',
			desc: 'Downloads all the model\'s style colorized images from the chromedata ftp and stores it into the S3 Bucket under /original/{styleid}/01.',
			args: true
		},
		{
			title: '6) Update All Database Colorized ( may take a long time )',
			fname: 'r_update_all_colorized',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			args: false
		},
		{
			title: '6.1) Update Colorized Media By Model ( may take some time )',
			fname: 'update_colorized_by_model',
			desc: 'Grabs all styles for model, optimizes and formats images based on url/localfiles, stores on s3 and updates Database Media table for styles.',
			args: true
		},
	];

	var ajax_path = window.location.href + 'includes/php/ajax.php';
	Vue.component('updating-table', {
		template: '#updating-table',
		props: ['updated', 'updating', 'name']
	});
	var _v = new Vue({
		el: '#wrapper',
		data: {
			functions: functions,
			outputs: [],
			models: [],
			updating: {
				'styles': [],
				'views': [],
				'ftps3': [],
				'colorized': []
			},
			updated: {
				'styles': [],
				'views': [],
				'ftps3': [],
				'colorized': []
			},
			run: true,
		},
		methods: {
			runFunction: function (event, item) {
				$('.notification').removeClass('error');
				$('.notification').removeClass('success');
				$('.notification').html('');
				var val = $('#value input').val();

				// Updating all models
				if (item.fname.indexOf('r_update_all_') !== -1) {
					var type = item.fname.replace('r_update_all_', '');
					update_all(event, type);
					return;
				}

				// No args provided if needed check
				if (item.args && val == '') {
					$('#value + .notification').addClass('error');
					$('#value + .notification').html('Pelase enter a value!');
					return;
				}

				// Get args
				var args = [];
				if ( item.fname.indexOf('_by_model') !== -1 ) {
					args.push(val);
				}

				// Start loading
				$(event.target).parent().addClass('active');
				$(event.target).parent().find('.loader').addClass('active');
				var callback = function (data) {
					// Stop Loading
					$(event.target).parent().removeClass('active');
					$(event.target).parent().find('.loader').removeClass('active');
				};
				run_php_function(item.fname, args, callback);
			}
		}
	});

	// First run
	run_php_function('get_updated_models', [], function () {

		var updating = {
			'styles': _v.models.slice(),
			'views': _v.updated['styles'].slice(),
			'ftps3': _v.updated['styles'].slice(),
			'colorized': _v.updated['styles'].slice()
		};
		// Can only update images on models that have been updated

		for (var key in _v.updated) {
			// Removed already updated models from updating
			for (var i = 0; i < _v.updated[key].length; i++) {
				var index = updating[key].indexOf(_v.updated[key][i]);
				updating[key].splice(index, 1);
			}
		}

		_v.updating = updating;
		$('.menu .item').tab();
	});

	function run_php_function(fname, args, callback) {
		$.ajax({
			url: ajax_path,
			method: 'POST',
			data: {
				fname: fname,
				args: JSON.stringify(args)
			},
			success: function (data) {
				try {
					data = JSON.parse(data);
				} catch (e) {
					console.log( 'Error caught' + e + ' for model ' + args[0] );
					console.log(data);
					callback();
					return;
				}

				// Set vue data
				if ('outputs' in data) { _v.outputs = _v.outputs.concat(data.outputs); }
				if ('valid' in data) {
					if (data.valid !== true) {
						_v.outputs = _v.outputs.concat(data.valid);
						_v.run = false; // Stop recursive script if running
					}
				}
				if ('models' in data) { _v.models = data.models; }
				if ('updated' in data) { _v.updated = data.updated; }
				if ('update' in data) { update(data.update.key, data.update.data); }

				callback();
			}
		});
	}

	// For single model udpates
	function update(key, model) {
		var index = _v.updating[key].indexOf(model);
		if (index == -1) { return; }
		var model = _v.updating[key].splice(index, 1);
		_v.updated[key].push(model[0]);
	}

	function update_all(event, type) {
		_v.run = true;
		var fn = 'update_' + type + '_by_model';

		// Start Loading
		console.time();
		$(event.target).parent().addClass('active');
		$(event.target).parent().find('.loader').addClass('active');

		// Recursive Function
		var callback = function () {
			if (_v.updating[type].length != 0) {
				var model = _v.updating[type].splice(0, 1)[0];
				_v.updated[type].push(model);
				if (_v.run) {
					run_php_function(fn, [model], callback);
				} else {
					$(event.target).parent().removeClass('active');
					$(event.target).parent().find('.loader').removeClass('active');
				}
			} else {
				// Finished updating all models
				console.timeEnd();
				$(event.target).parent().removeClass('active');
				$(event.target).parent().find('.loader').removeClass('active');
			}
		}

		callback();
	}


});