<?php
$config = require('config.php');

// Route AJAX actions to the appropriate actionXXX() function
if (!empty($_GET['action'])) {
	
	// TODO: Check user is authenticated
	// Some of these actions could open severe security vulnerabilities
	// if someone can run their own (unfiltered) console commands
	
	$function = 'action' . preg_replace('/\\W/', '', $_GET['action']);
	$function();
	die();
} 

/**
 * Get the current status of the system
 * @return JSON array of items
 */
function actionGetStatus() {
	echo json_encode([
		'test' => 1
	]);
}

/**
 * Get an array
 * @return JSON array of modified and untracked files
 */
function actionGitStatus() {
	system('git status');
	system('git status --porcelain');
}

/**
 * Run a console command and return the output as an array
 */
function run($command) {
	$output = [];
	exec($command, $output);
	return $output;
}

/**
 * Run a console command and stream the output line by 
 * line to the browser
 * (Useful for long-running commands)
 */
function runAndStreamOutput($command) {
	system($command);
}

/**
 * Run a git command
 * 
 * @param string $command - 'git ...' command and any other parameters to include
 * @return array of lines from output 
 */
function runGit($command, $humanFriendly = false) {
	return run(prepareGitCommand($command, $humanFriendly));
}

function runGitAndStreamOutput($command, $humanFriendly = false) {
	runAndStreamOutput(prepareGitCommand($command, $humanFriendly));
}

/**
 * Provide a git command string and prepare it for execution
 * 
 * @param string $command
 * @param bool $humanFriendly
 * @return string
 */
function prepareGitCommand($command, $humanFriendly) {
	$command = ['git', $command];
	if (!$humanFriendly)
		$command[] = '--porcelain';	// this produces a more machine-parseable output	
	return implode(' ', $command);
}

/********************************************
 * Everything below here is the front end UI
 ********************************************/
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title>DeployCat</title>
	<link rel="stylesheet" href="node_modules/bootstrap/dist/css/bootstrap.min.css">
	<script src="vue/dist/vue.js"></script>
</head>
<body>
	
  <!-- VueJS is mounted onto this root DIV -->
  <div id="app">
	
	<!-- Top Navigation Bar -->
	<nav class="navbar navbar-expand-md navbar-dark bg-dark" style="margin-bottom: 24px">
		
		<!-- Heading -->
	    <a class="navbar-brand" href="#">DeployCat</a>
	    
	    <!-- Mobile Toggle -->
	    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarsExampleDefault" aria-controls="navbarsExampleDefault" aria-expanded="false" aria-label="Toggle navigation">
	      <span class="navbar-toggler-icon"></span>
	    </button>

		<!-- Nav -->
	    <div class="collapse navbar-collapse" id="navbarsExampleDefault">
	      <ul class="navbar-nav mr-auto">
	        <li class="nav-item active">
	          <a class="nav-link" href="#" @click="screen = 'home'">Home <span class="sr-only">(current)</span></a>
	        </li>
	        <li class="nav-item">
	          <a class="nav-link" href="#" @click="screen = 'status'">Status</a>
	        </li>
	        <li class="nav-item">
	          <a class="nav-link disabled" href="#">Disabled</a>
	        </li>
	        <!-- <li class="nav-item dropdown">
	          <a class="nav-link dropdown-toggle" href="https://example.com" id="dropdown01" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Dropdown</a>
	          <div class="dropdown-menu" aria-labelledby="dropdown01">
	            <a class="dropdown-item" href="#">Action</a>
	            <a class="dropdown-item" href="#">Another action</a>
	            <a class="dropdown-item" href="#">Something else here</a>
	          </div>
	        </li> -->
	      </ul>
	    </div>
	  </nav>

	  <main role="main" class="container">

		<!-- Home Screen -->
	    <div class="starter-template" v-if="screen == 'home'">
	      <h1>Bootstrap starter template</h1>
	      <p class="lead">Use this document as a way to quickly start any new project.<br> All you get is this text and a mostly barebones HTML document.</p>
	    </div>
	    
	    <!-- Status Screen -->
		<div v-if="screen=='status'">
			<table class="table table-striped">
				<tr>
					<td></td>
					<td></td>
				</tr>
			</table>
		</div>

	  </main><!-- /.container -->
	
	</div>
	
	<script>
		// We use VueJS to manage the UI
		vueApp = new Vue({
			el: '#app',
			data: {
				screen: 'home',
				status: {},
			},
			methods: {},
		});		
	</script>
	
	<!-- Using Axios for AJAX functionality -->
	<script src="node_modules/axios/dist/axios.min.js"></script>
	<script>
		// Load in data via AJAX
		axios.get('?action=GetStatus')
		.then(function(response){
			vueApp.status = response.data;
		});
	</script>
	
</body>
</html>
