<?php
$config = require('config.php');

// Route AJAX actions to the appropriate actionXXX() function
if (!empty($_GET['action'])) {
	
	// TODO: Check user is authenticated
	// Some of these actions could open severe security vulnerabilities
	// if someone can run their own (unfiltered) console commands
	// so we strip out all non-word characters
	$function = 'action' . preg_replace('/\\W/', '', $_GET['action']);
	if (function_exists($function)) {
		$function();
	} else {
		http_response_code(404);
		echo '{"error": "Action \'' . $function . '\' doesn\'t exist."}';
	}
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
		'Git version' => runGit('--version')[0],
	]);
}

/**
 * Get an array of changed-but-uncommited files in current 
 * folder since last deployment
 * @return JSON array of modified and untracked files
 */
function actionFilesChanged() {
	// runGitAndStreamOutput('status');
	$changedFiles = runGit('status --porcelain');
	
	foreach ($changedFiles as $i => $string) {
		$changedFiles[$i] = [
			'status' => trim(substr($string, 0, 2)),
			'filename' => substr($string, 3),
		];
	}
	
	echo json_encode($changedFiles);
}

/**
 * Fetches latest commits from remote repository, then
 * displays latest 200 log entries
 */
function actionFetchAndGetLog() {
	global $config;
	
	// Git fetch
	runGit("fetch {$config['git']['remote']}");
	
	// If that was successful (or even if not?)
	// return latest 200 log entries
	// Outputs the short hash, a space and then single-line commit-message
	// e.g. git log origin/master --max-count=200 --format="%h %s"
	$log = runGit("log {$config['git']['remote']}/{$config['git']['deployFromBranch']} --max-count=200 --format=\"%h %s\"");
	foreach ($log as $i => $string) {
		$string = explode(' ', $string, 2);
		$log[$i] = ['hash' => $string[0], 'message' => $string[1]];
	}
	echo json_encode($log);
}

/**
 * Perform a diff of changed files between two commits
 * then output an array of the changed files
 */
function actionDiff() {
	$a = sanitizeCommitHash($_GET['a']);
	$b = sanitizeCommitHash($_GET['b']);
	
	$changedFiles = runGit("diff $a $b --name-status");
	// print_r($changedFiles);
	
	foreach ($changedFiles as $i => $string) {
		$string = explode("\t", $string, 2);
		$changedFiles[$i] = [
			'status' => $string[0],
			'filename' => $string[1],
		];
	}
	
	echo json_encode($changedFiles);
}

function sanitizeCommitHash($hash) {
	return substr(preg_replace('/[^0-9a-f]/', '', $hash), 0, 10);
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
function runGit($command) {
	return run(prepareGitCommand($command));
}

/**
 * Runs git and streams the output line by line to browser
 * (Useful for long-running commands)
 * 
 * @param $command (minus the "git")
 * @return null
 */
function runGitAndStreamOutput($command) {
	runAndStreamOutput(prepareGitCommand($command));
}

/**
 * Provide a git command string and prepare it for execution
 * (we may use this for adding extra flags to all git commands)
 * 
 * @param string $command
 * @param bool $porcelain - adds the "--porcelain" flag to 
 * @return string
 */
function prepareGitCommand($command) {
	$command = ['git', $command];
	return implode(' ', $command);
}

/**
 * Returns the branch that the repository is currently tracking
 * @return string
 */
function gitCurrentBranch() {
	$branches = runGit('branch');
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
	return runGit('rev-parse HEAD')[0];
}

/**
 * Returns the URL of the remote origin repository
 * @return string
 */
function gitRemoteOriginUrl() {
	// Or 'git remote show origin' may also work
	$remote = runGit('config --get remote.origin.url');
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
	<link rel="stylesheet" href="css/bootstrap.min.css">
	<script src="js/vue.js"></script>
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
	          <a class="nav-link" href="#">Deploy</a>
	        </li>
	        <li class="nav-item" :class="{active: screen == 'changes'}">
	          <a class="nav-link" href="#changes">Changes</a>
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
	    	
	      <h4>You are on <?= $_SERVER['SERVER_NAME'] ?> 
	      	<div class="badge badge-secondary" style="font-size: 75%; float: right">
	      		<?= $config['environmentLabel'] ?>
	      	</div>
	      </h4>
	      
	      <div class="jumbotron" style="padding: 1.5rem">
	      	
	      	<div class="form-group">
	      		<select v-model="selectedCommit" class="form-control">
	      			<option v-for="commit in commitLog" :value="commit.hash">
	      				{{ commit.message }} - {{ commit.hash }}
	      			</option>
	      		</select>
	      	</div>
	      	
	      	<div class="row">
	      		<div class="col-sm" style="line-height: 24px">
	      			You will be deploying:
	      			<div>
	      				<strong style="font-size: 140%">3</strong> 
	      				commits to <?= $config['git']['deployFromBranch'] ?> branch
	      			</div>
	      			<div>
	      				<strong style="font-size: 140%">{{ filesToDeploy.length }}</strong> 
	      				changed files
	      			</div>
	      			<a href="#" @click="refresh">Refresh</a>
	      		</div>
	      		<div class="col-sm">
	      			<button class="btn btn-lg btn-danger">Deploy</button>
	      		</div>
	      	</div>
	      	
	      </div>
	      
	      <div v-if="filesToDeploy.length">
		      <h4>Files to be deployed</h4>
		      
		      <table class="table">
		      	<tr v-for="file in filesToDeploy">
		      		<td>{{ fileStatusLong(file.status) }}</td>
		      		<td>{{ file.filename }}</td>
		      	</tr>
		      </table>
	      </div>
	      
	    </div>
	    
	    <!-- Changes Screen -->
		<div v-if="screen=='changes'">
			<p>The following files have been modified since last deployment. They will be 'stashed' when the next deploy or revert occurs and the changes will only be recoverable via the command line (not explained here).</p>
			<p>Untracked files will be left as is.</p>
			<table class="table table-striped">
				<tr v-for="file in filesChanged">
					<td>{{ fileStatusLong(file.status) }}</td>
		      		<td>{{ file.filename }}</td>
				</tr>
			</table>
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
		///////////////////////////////////
		// We use VueJS to manage the UI //
		///////////////////////////////////
		vueApp = new Vue({
			el: '#app',
			data: {
				// Which screen of the UI is the user currently on
				// We check the URL for which screen to begin on, ensuring we strip the leading '#'
				screen: location.hash.substr(1), 
				
				// The following items are all populated via AJAX...
				
				// Various key-value pairs returned from server
				status: {},
				
				// Array of log entries (each has a 'hash' and 'message')
				commitLog: [],
				
				// Hash string representing the currently-deployed commit
				currentCommit: null,
				
				// Hash string representing the commit currently selected in UI drop-down
				selectedCommit: null,
				
				// Array of files changed between currentCommit and selectedCommit
				filesToDeploy: [],
				
				// Array of files changed between currentCOmmit and working copy
				filesChanged: [],
			},
			watch: {
				/**
				 * When selectedCommit changes, we need to retrieve
				 * a list of files that differ between currentCommit
				 * and selectedCommit, for display in UI
				 */
				selectedCommit: function(commitHash) {
					this.getFilesToDeploy(this.currentCommit, commitHash);
				}
			},
			methods: {
				/**
				 * Perform a 'git fetch' and populate 'commitLog' with latest 
				 * list of commits
				 */
				refresh: function() {
					if (!window.axios) {
						console.warn('Cannot perform AJAX, axios library has not yet loaded.');
						return;
					}
					
					// TODO: Handle errors if they occur
					axios.get('?action=GetStatus')
					.then(function(response){
						vueApp.status = response.data;
						vueApp.currentCommit = response.data['Current commit'];
					});
					
					axios.get('?action=FetchAndGetLog')
					.then(function(response){
						vueApp.commitLog = response.data;
					});
					
					this.getFilesChanged();
				},
				
				/**
				 * Retrieve a list of files that differ between commitHashA
				 * and commitHashB, for display in UI
				 */
				getFilesToDeploy: function(commitHashA, commitHashB) {
					this.filesToDeploy = [];
					
					// TODO: Do we need to do any sanitizing of params here?
					// It's done on server, so should be OK for now.
					axios.get('?action=Diff&a=' + commitHashA + '&b=' + commitHashB)
					.then(function(response){
						vueApp.filesToDeploy = response.data;
					});
				},
				
				/**
				 * Retrieve a list of files that differ between current commit
				 * and working copy, for display in UI ('Changes' tab)
				 * 
				 * Only called on 'Refresh' at the current time
				 */
				getFilesChanged: function(commitHashA, commitHashB) {
					this.filesChanged = [];					
					axios.get('?action=FilesChanged')
					.then(function(response){
						vueApp.filesChanged = response.data;
					});
				},				
				
				/**
				 * Convert git's short status codes into a longer textual description
				 */
				fileStatusLong: function(statusCode) {
					if (statusCode == 'A')
						return 'Added';
					if (statusCode == 'M')
						return 'Modified';
					if (statusCode == 'D')
						return 'Deleted';
					if (statusCode == 'R')
						return 'Renamed';
					if (statusCode == '??')
						return 'Untracked';
					return statusCode;
				}
			},
		});
		
		// When URL hash changes, change the screen we're viewing
		window.addEventListener("hashchange", function() {
			vueApp.screen = location.hash.substr(1);
		});
		
	</script>
	
	<!-- Using Axios for AJAX functionality -->
	<script src="js/axios.min.js"></script>
	<script>
		// Once Axios has been loaded, let's load in our data
		vueApp.refresh();		
	</script>
	
</body>
</html>
