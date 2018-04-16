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
			<b>Update Value:</b>
			<div class="ui input" id="value">
				<input type="text" placeholder="Model Name">
			</div>
			<div class="notification"></div>
			<div class="functions">
				<div class="item ui segment" v-for="f in functions">
					<div class="item__wrap">
						<div class="item__title">- {{ f.title }}</div>
						<div class="item__desc">{{ f.desc }}</div>
						<div class="notification"></div>
					</div>
					<div class="item__button" @click="runFunction( $event, f )">Run Script</div>
					<div class="ui loader text">Loading</div>
				</div>
			</div>
			<h2>Output:</h2>
			<div class="section ui segment">
				<div class="empty" v-if="outputs.length == 0">nothing 😒</div>
				<div 
						 :class="output.type"
						 v-for="output in outputs" 
						 v-else>
					{{ output.msg }}
				</div>
			</div>
			<h2>Models:</h2>
			<div class="section ui segment">
				<div class="row">
					<div class="col-md-6 col-sm-6 col-xs-12">
						<h3>Needs Updating</h3>
						<!-- All models have been updated -->
						<div class="note" v-if="updating.length == 0">No Models Need Updating</div>
						<!-- If model not found in updated, needs to be updated -->
						<ul v-if="updating.length > 0">
							<li v-for="model in updating">{{ model }}</li>
						</ul>
					</div>
					<div class="col-md-6 col-sm-6 col-xs-12">
						<h3>Updated</h3>
						<div class="note" v-if="updated.length == 0">No Models Have Been Updated</div>
						<ul v-if="updated.length > 0">
							<li v-for="model in updated">{{ model }}</li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</body>
</html>