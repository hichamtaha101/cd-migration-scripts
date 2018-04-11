<?php // Silence is golden?>
<html>
	<head>
		<link rel="stylesheet" href="includes/css/chromedata.css">
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/semantic-ui/2.2.10/semantic.min.css">
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
		<script type="text/javascript" src="https://unpkg.com/vue@2.1.3/dist/vue.js"></script>
		<script type="text/javascript" src="https://cdn.jsdelivr.net/semantic-ui/2.2.10/semantic.min.js"></script>
		<script type="text/javascript" src="includes/js/chromedata.js"></script>
	</head>
	<body>
		<div class="content-wrapper" id="wrapper">
			<h1>🛠️ ChromeData Tool</h1>
			<b>Update Value: </b>
			<div class="ui input" id="value">
				<input type="text" placeholder="Make/Model/StyleID">
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
			<div class="output ui segment">
				<div class="empty" v-if="results.length == 0">nothing 😒</div>
				<div class="result" v-for="result in results" v-else>
					{{ result }}
				</div>
			</div>
		</div>
	</body>
</html>