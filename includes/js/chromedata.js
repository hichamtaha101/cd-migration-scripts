jQuery(document).ready(function($){

	// Might be able to grab this dynamically based on funtions.php
	var functions = [
		{
			title: 'Update All Makes',
			fname: 'update_all_makes',
			desc: 'Updates all makes/divisions ( replaces all divison entries in DB )',
			args: false
		},
		{
			title: 'Update Make By Name',
			fname: 'update_make_by_name',
			desc: 'Grabs and updates the latest models for the make specified in the input above.',
			args: true
		},
		{
			title: 'Update Model By Name',
			fname: 'update_model_by_name',
			desc: 'Grabs and updates the latest styles for the model specified in the input above.',
			args: true
		},
		{
			title: 'Updated Style By ID',
			fname: 'update_style',
			desc: 'Grabs style by id and updates all records in the DB for the style ( media, engine, standard, option, exterior_color )',
			args: true
		},
		{
			title: 'Update Database Images',
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
			results: []
		},
		methods: {
			runFunction: function( event, item ) {
				$('.notification').removeClass('error');
				$('.notification').removeClass('success');
				$('.notification').html('');
				
				var args = [];
				var fname = item.fname;
				var val = $('#value input').val();
				switch( fname ) {
					case 'update_make_by_name':
						args.push(val);
						break;

					case 'update_model_by_name':
						args.push(val);
						break;

					case 'update_style':
						args.push(val);
						break;
				}

				// Args needed but none provided
				if ( item.args  && val == '' ) {
					$('#value + .notification').addClass('error');
					$('#value + .notification').html('Pelase enter a value!');
					return;
				}

//				$.ajax({
//					url: ajax_path,
//					method: 'POST',
//					data: {
//						fname: fname,
//						args: JSON.stringify(args)
//					},
//					beforeSend: function() {
//						$(event.target).parent().addClass('active');
//						$(event.target).parent().find('.loader').addClass('active');
//					},
//					success: function( data ) {
//						data = JSON.parse( data );
//						_v.results = _v.results.concat( data );
//
//						$(event.target).parent().removeClass('active');
//						$(event.target).parent().find('.loader').removeClass('active');
//					}
//				});
			}
		}
	});

});