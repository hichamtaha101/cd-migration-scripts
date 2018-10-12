jQuery(document).ready(function ($) {

	// Might be able to grab this dynamically based on funtions.php
	var functions = [
		{
			title: '1) Update All Makes',
			fname: 'update_all_makes',
			desc: 'Updates all makes/divisions ( replaces all divison entries in DB ).',
		},
		{
			title: '2) Update All Models',
			fname: 'update_all_models',
			desc: 'Updates all models ( replaces all model entries in DB ).',
		},
		{
			title: '3) Update All Styles ( may take a long time )',
			fname: 'update_styles',
			type: 'styles',
			desc: 'Updates styles table by each non-updated model, progress is reported in the output section.',
			updateAll: true,
			removeMedia: true
		},
		{
			title: '3.1) Update Styles By Model',
			fname: 'update_styles',
			desc: 'Grabs styles by model and updates all records in DB for the styles selected.',
			hasModel: true,
			removeMedia: true
		},
		{
			title: '4) Update All Database Views ( may take a long time )',
			fname: 'update_model_images',
			type: 'view',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			updateAll: true,
		},
		{
			title: '4.1) Update Views Media By Model ( may take some time )',
			fname: 'update_model_images',
			type: 'view',
			desc: 'Grabs all styles for model, optimizes and formats images based on url/localfiles, stores on s3 and updates Database Media table for styles.',
			hasModel: true,
		},
		{
			title: '5) Update All FTP to S3 Colorized ( may take a long time )',
			fname: 'update_ftps3',
			type: 'ftps3',
			desc: 'Downloads each model\'s colorized images from the chromedata ftp and stores it into the S3 Bucket under /original/{styleid}/01.',
			updateAll: true,
		},
		{
			title: '5.1) Update FTP to S3 Colorized Media By Model ( may take some time )',
			fname: 'update_ftps3',
			desc: 'Downloads all the model\'s style colorized images from the chromedata ftp and stores it into the S3 Bucket under /original/{styleid}/01.',
			hasModel: true,
		},
		{
			title: '6) Update All Database Colorized ( may take a long time )',
			fname: 'update_model_images',
			type: 'colorized',
			desc: 'Optimizes images from new styles, stores on s3, and updates DB with the new media.',
			updateAll: true,
		},
		{
			title: '6.1) Update Colorized Media By Model ( may take some time )',
			fname: 'update_model_images',
			type: 'colorized',
			desc: 'Grabs all styles for model, optimizes and formats images based on url/localfiles, stores on s3 and updates Database Media table for styles.',
			hasModel: true,
		}
	];

	var ajaxPath = window.location.href + 'includes/php/ajax.php';
	Vue.component('updating-table', {
		template: '#updating-table',
		props: ['updated', 'updating', 'name']
	});
	var vMain = new Vue({
		el: '#wrapper',
		data: {
			functions: functions,
			outputs: [],
			models: [],
			modelValue: '',
			removeMedia: 'false',
			inputMessage: '',
			inputClass: '',
			updating: {
				'styles': [],
				'view': [],
				'ftps3': [],
				'colorized': []
			},
			updated: {
				'styles': [],
				'view': [],
				'ftps3': [],
				'colorized': []
			},
			run: true,
		},
		methods: {
			runFunction: function (event, item) {
				this.inputClass = '';
				this.inputMessage = '';
				var args = [];

				// No args provided when needed check
				if ( 'hasModel' in item && this.modelValue == '') {
					this.inputClass = 'error';
					this.inputMessage = 'Model value should not be empty';
					return;
				}

				// If specific model being updated
				if ( 'hasModel' in item ) {
					args.push(this.modelValue);
				}

				// Pass what type of image to update ( view || colorized )
				if ( item.fname === 'update_model_images' ) {
					args.push(item.type);
				}

				// For updating styles, whether to remove all media or not
				if ( 'removeMedia' in item ) {
					args.push(this.removeMedia);
				}

				// If running function for all models
				if ( 'updateAll' in item ) {
					update_all(event, item, args);
					return;
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
			'styles': vMain.models.slice(),
			'view': vMain.updated['styles'].slice(),
			'ftps3': vMain.updated['styles'].slice(),
			'colorized': vMain.updated['styles'].slice()
		};
		// Can only update images on models that have been updated

		for (var key in vMain.updated) {
			// Removed already updated models from updating
			for (var i = 0; i < vMain.updated[key].length; i += 1) {
				var index = updating[key].indexOf(vMain.updated[key][i]);
				updating[key].splice(index, 1);
			}
		}

		vMain.updating = updating;
		$('.menu .item').tab();
	});

	function run_php_function(fname, args, callback) {
		$.ajax({
			url: ajaxPath,
			method: 'POST',
			data: {
				fname: fname,
				args: JSON.stringify(args)
			},
			success: function (data) {
				try {
					console.log(data);
					data = JSON.parse(data);
					console.log(data);
				} catch (e) {
					console.log( 'Error caught' + e + ' for model ' + args[0] );
					console.log(data);
					callback();
					return;
				}

				// Set vue data
				// vMain.run = false; // Stop recursive script if running
				if ('outputs' in data) { vMain.outputs = vMain.outputs.concat(data.outputs); }
				if ('models' in data) { vMain.models = data.models; }
				if ('updated' in data) { vMain.updated = data.updated; }
				if ('update' in data) { update(data.update.key, data.update.data); }

				callback();
			}
		});
	}

	// For single model udpates
	function update(key, model) {
		var index = vMain.updating[key].indexOf(model);
		if (index == -1) { return; }
		var model = vMain.updating[key].splice(index, 1);
		vMain.updated[key].push(model[0]);
	}

	function update_all(event, item, args) {
		vMain.run = true;

		// Start Loading
		console.time();
		$(event.target).parent().addClass('active');
		$(event.target).parent().find('.loader').addClass('active');

		// Recursive Function
		var callback = function () {
			if (vMain.updating[item.type].length != 0) {
				var model = vMain.updating[item.type].splice(0, 1)[0];
				vMain.updated[item.type].push(model);
				args.unshift(model);
				console.log(model);
				if (vMain.run) {
					run_php_function(item.fname, args, callback);
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