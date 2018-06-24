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
		'Current branch' => gitCurrentBranch(),
		'Current commit' => gitCurrentCommit(),
		'Remote origin' => gitRemoteOriginUrl(),
		'PHP version' => phpversion(),
		'Git version' => runGit('--version', true)[0],
	]);
}

/**
 * Get an array
 * @return JSON array of modified and untracked files
 */
function actionGitStatus() {
	runGitAndStreamOutput('status', true);
	runGitAndStreamOutput('status');
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

/**
 * Runs git and streams the output line by line to browser
 * (Useful for long-running commands)
 * 
 * @param $command (minus the "git")
 * @param boolean $humanFriendly
 * @return null
 */
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

/**
 * Returns the branch that the repository is currently tracking
 * @return string
 */
function gitCurrentBranch() {
	$branches = runGit('branch', true);
	$branch = array_filter($branches, function($item){
		return substr($item, 0, 1) == '*';
	});
	return trim($branch[0], " \t*");
}

/**
 * Return the hash ID of the commit currently deployed in repository
 * @return string
 */
function gitCurrentCommit() {
	return runGit('rev-parse HEAD', true)[0];
}

/**
 * Returns the URL of the remote origin repository
 * @return string
 */
function gitRemoteOriginUrl() {
	// Or 'git remote show origin' may also work
	$remote = runGit('config --get remote.origin.url', true);
	if (count($remote))
		return $remote[0];
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
	        <li class="nav-item" :class="{active: screen == ''}">
	          <a class="nav-link" href="#">Home <span class="sr-only">(current)</span></a>
	        </li>
	        <li class="nav-item" :class="{active: screen == 'status'}">
	          <a class="nav-link" href="#status">Status</a>
	        </li>
	        <!-- <li class="nav-item">
	          <a class="nav-link disabled" href="#">Disabled</a>
	        </li> -->
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

		<!-- Default Home Screen -->
	    <div class="starter-template" v-if="screen == ''">
	      <h1>Bootstrap starter template</h1>
	      <p class="lead">Use this document as a way to quickly start any new project.<br> All you get is this text and a mostly barebones HTML document.</p>
	    </div>
	    
	    <!-- Status Screen -->
		<div v-if="screen=='status'">
			<table class="table table-striped">
				<tr v-for="(item, key) in status">
					<th>{{key}}</th>
					<td>{{item}}</td>
				</tr>
			</table>
		</div>

	  </main>
	
	</div>
	
	<script>
		// We use VueJS to manage the UI
		vueApp = new Vue({
			el: '#app',
			data: {
				screen: location.hash.substr(1), // Check the URL for which screen to begin on, ensuring we strip the initial hash
				status: {}, // populated via AJAX
			},
			methods: {},
		});
		
		window.addEventListener("hashchange", function() {
			vueApp.screen = location.hash.substr(1);
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
