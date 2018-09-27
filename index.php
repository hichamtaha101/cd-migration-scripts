<?php // Silence is golden?>
<html>
	<head>
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/semantic-ui/2.2.10/semantic.min.css">
		<link rel="stylesheet" href="includes/css/chromedata.css">
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
		<script type="text/javascript" src="https://unpkg.com/vue@2.1.3/dist/vue.js"></script>
		<script type="text/javascript" src="https://cdn.jsdelivr.net/semantic-ui/2.2.10/semantic.min.js"></script>
		<script type="text/javascript" src="includes/js/chromedata.js"></script>
	</head>
	<body>
		<div class="content-wrapper" id="wrapper">
			<h1>🛠️ ChromeData Tool</h1>
			<b>Update Model</b>
			<div class="ui input">
				<input type="text" placeholder="Model Name" v-model="modelValue">
			</div>
			<div class="notification" :class="inputClass">{{ inputMessage }}</div>
			<div class="functions">
				<div class="item ui segment" v-for="f in functions">
					<div class="item__wrap">
						<div class="item__title">- {{ f.title }}</div>
						<div class="item__desc">{{ f.desc }}</div>
						<div class="item__option" v-if="'removeMedia' in f">
							Remove All Media? 
							<input type="radio" v-model="removeMedia" value="true">True
							<input type="radio" v-model="removeMedia" value="false">False
						</div>
						<div class="notification"></div>
					</div>
					<div class="item__button" @click="runFunction( $event, f )">Run Script</div>
					<div class="ui loader text">Loading</div>
				</div>
			</div>
			<h2>Output:</h2>
			<div class="section ui segment">
				<div 
						 :class="output.type"
						 v-for="output in outputs" 
						 v-html="output.msg"
						 v-if ="outputs.length !== 0">
				</div>
				<div class="empty" v-else>N/A</div>
			</div>
			<h2>Status:</h2>
			<div class="ui top attached tabular menu">
				<div class="item active" data-tab="styles">Styles</div>
				<div class="item" data-tab="media-view">Media View</div>
				<div class="item" data-tab="media-ftps3">FTP to S3</div>
				<div class="item" data-tab="media-colorized">Media Colorized</div>
			</div>
			<div class="ui bottom attached tab segment active section" data-tab="styles">
				<updating-table 
				:updated="updated.styles" 
				:updating="updating.styles"
				name="Models"></updating-table>
			</div>
			<div class="ui bottom attached tab segment section" data-tab="media-view">
				<updating-table 
				:updated="updated.views" 
				:updating="updating.views"
				name="Views Media"></updating-table>
			</div>
			<div class="ui bottom attached tab segment section" data-tab="media-ftps3">
				<updating-table 
				:updated="updated.ftps3" 
				:updating="updating.ftps3"
				name="Models"></updating-table>
			</div>
			<div class="ui bottom attached tab segment section" data-tab="media-colorized">
				<updating-table 
				:updated="updated.colorized" 
				:updating="updating.colorized"
				name="Colorized Media"></updating-table>
			</div>
		</div>
	</body>

	<!-- Vue Template -->
	<script type="text/x-template" id="updating-table">
	<div>
		<div class="row">
			<div class="col-md-6 col-sm-6 col-xs-12">
				<h3>Models That Needs Updating</h3>
				<!-- All models have been updated -->
				<div class="note" v-if="updating.length == 0">No {{ name }} Need Updating</div>
				<!-- If model not found in updated, needs to be updated -->
				<ul v-if="updating.length > 0">
					<li v-for="model in updating">{{ model }}</li>
				</ul>
			</div>
			<div class="col-md-6 col-sm-6 col-xs-12">
				<h3>Models Updated</h3>
				<div class="note" v-if="updated.length == 0">No {{ name }} Have Been Updated</div>
				<ul v-if="updated.length > 0">
					<li v-for="model in updated">{{ model }}</li>
				</ul>
			</div>
		</div>
	</div>
	</script>
</html>