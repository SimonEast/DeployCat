<?php
$config = require('config.php');

//-----------------------------------------------------------
// You shouldn't need to modify anything below here
// Most things can be configured inside config.php
//-----------------------------------------------------------

// Set some defaults, in case items are missing from config.php
$config = $config + [
	'environmentLabel' => 'Production',
	'git' => [
		'deployFromBranch' => 'master',
		'remote' => 'origin',
	],
	'allowedIPs' => [],
];

define('DEPLOYCAT_VERSION', '0.1.3');

// Block robots from indexing this tool
header('X-Robots-Tag: noindex,nofollow');

// Authentication
// For now we only have IP-based auth (IPv4 only). 
// Later we can implement password/session-based auth
$accessGranted = false;
$userIP = $_SERVER['REMOTE_ADDR'];
foreach ($config['allowedIPs'] as $ip) {
	if ($ip === $userIP) {
		$accessGranted = true;
		break;
	}
}
if (!$accessGranted) {
	http_response_code(401);
	die('<h1>401 Unauthorized</h1> No access permitted from IP ' . $userIP 
		. ' (' . gethostbyaddr($userIP) . ')');
}


// Route AJAX actions to the appropriate actionXXX() function
if (!empty($_GET['action'])) {
	
	// Axios AJAX library sends POST data as JSON, so we decode it before
	// running any action below
	$_POST = json_decode(file_get_contents('php://input'), true);
	
	// Some of these actions could open severe security vulnerabilities
	// if someone can run their own (unfiltered) console commands
	// so we strip out all non-word characters
	$function = 'action' . preg_replace('/\\W/', '', $_GET['action']);
	if (function_exists($function)) {
		try {
			$function();			
		} catch (\Exception $e) {
			http_response_code(500);
			if (empty($e->commandOutput))
				$e->commandOutput = null;
			elseif (is_array($e->commandOutput))
				$e->commandOutput = implode("\n", $e->commandOutput);
			echo json_encode([
				'errorMessage' => $e->getMessage(),
				'commandInput' => !empty($e->commandInput) ? $e->commandInput : null,
				'commandOutput' => $e->commandOutput,
			]);
			die();
		}
	} else {
		http_response_code(404);
		echo json_encode(['errorMessage' => "Action '$function' doesn't exist."]);
	}
	die();
} 

/**
 * Get the current status of the system
 * @return JSON array of items
 */
function actionGetStatus() {
	echo json_encode([
		'Deployment folder' => runGit('rev-parse --show-toplevel')[0],
		'Current branch' => gitCurrentBranch(),
		'Current commit' => gitCurrentCommit(),
		'Remote origin' => gitRemoteOriginUrl(),
		'DeployCat version' => DEPLOYCAT_VERSION,
		'PHP version' => phpversion(),
		'Git version' => runGit('--version')[0],
		'Operating System' => php_uname(),
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
 * Deploy a specific commit (using git reset)
 * @return string
 */
function actionDeploy() {
	if (empty($_POST['commit'])) {
		die(json_encode(['error' => 'No commit hash detected']));
	}
	
	$commit = sanitizeCommitHash($_POST['commit']);
	
	// Or 'git remote show origin' may also work
	$stash = runGit('stash');
	$deploy = runGit("reset $commit --hard");
	if (count($deploy))
		return $deploy[0];
}

/**
 * Run a console command and return the output as an array
 * (we redirect stderr to stdout so that any error messages are also included in output)
 */
function run($command) {
	$output = [];
	exec($command . ' 2>&1', $output, $exitCode);
	
	// Throw an exception if command returns an error code
	if ($exitCode != 0) {
		$e = new \Exception('Console command failed');
		$e->commandInput = $command;
		$e->commandOutput = $output;
		throw $e;
	}
	
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
	try {
		return run(prepareGitCommand($command));		
	} catch (\Exception $e) {
		$new = new \Exception('The following git command did not complete successfully:');
		$new->commandInput = $e->commandInput;
		$new->commandOutput = $e->commandOutput;
		throw $new;
	}
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
	<style>
		/* Hide Vue elements until it's initialised */
		[v-cloak] {
		  display: none;
		}
		#changes-tab .untracked {
			opacity: 0.3;
		}
		h1, h2, h3, h4 {
			font-weight: 300;
		}
		/* Neater alignment for left column of tables - doesn't work with 'table-striped' though */
		table td:first-child {
		    padding-left: 0;
		}
		.navbar {
		    background-image: url(img/Claw_Marks_SVG_White.svg);
		    background-repeat: no-repeat;
		    padding-left: 60px;
		    background-size: auto 135%;
		    background-position: 0px -10px;
		}
	</style>
	<script src="js/vue.js"></script>
</head>
<body>
	
  <!-- VueJS is mounted onto this root DIV -->
  <div id="app" v-cloak>
	
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
	    	
	      <h4 style="font-weight: 300">You are on <?= $_SERVER['SERVER_NAME'] ?> 
	      	<div class="badge badge-secondary" style="font-size: 75%; font-weight: inherit; margin-left: 10px">
	      		<?= $config['environmentLabel'] ?>
	      	</div>
	      </h4>
	      
	      <div class="jumbotron" style="padding: 1.5rem">
	      	
	      	<div class="form-group">
	      		<select v-show="commitLog.length == 0" class="form-control" style="color: #aaa; font-style: italic;" disabled>
	      			<option selected="selected">Fetching latest commits...</option>
	      		</select>
	      		<select v-show="commitLog.length" v-model="selectedCommit" class="form-control">
	      			<optgroup v-for="group in commitLogGrouped" v-if="group.commits.length" :label="group.label">
		      			<option v-for="commit in group.commits" :value="commit.hash">
		      				{{ commit.message }} - {{ commit.hash }}
		      			</option>
	      			</optgroup>
	      		</select>
	      	</div>
	      	
	      	<div class="row">
	      		<div class="col-sm" style="line-height: 24px">
	      			You will be deploying:
	      			<div>
	      				<strong style="font-size: 140%">{{ commitsToDeploy }}</strong> 
	      				commits from <?= $config['git']['deployFromBranch'] ?> branch
	      			</div>
	      			<div>
	      				<strong style="font-size: 140%">{{ filesToDeploy.length }}</strong> 
	      				changed files
	      			</div>
	      			<a href="#" @click="refresh">Refresh</a>
	      		</div>
	      		<div class="col-sm">
	      			<button 
	      				v-if="commitLog.length && currentCommit == selectedCommit" 
	      				class="btn btn-lg btn-light" 
	      				disabled>Nothing to deploy</button>
	      			<button 
	      				v-else-if="deployInProgress" 
	      				class="btn btn-lg btn-light" 
	      				disabled>Deployment in progress...</button>
	      			<button 
	      				v-else-if="commitLog.length" 
	      				class="btn btn-lg btn-danger" 
	      				@click="deploy()">Deploy</button>
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
	    
	    <!-- Deployment Success Screen -->
		<div id="deployment-success-tab" v-if="screen=='deploymentSuccess'">
	    	<div class="jumbotron" style="padding: 1.5rem">
	    		<h2 class="display-4" style="margin-bottom: 0.5em;">Deployment complete.</h2>
	    		<h2>Total time: {{ deployment.time }} seconds</h2>
	    	</div>
	    </div>
	    
	    <!-- Changes Screen -->
		<div id="changes-tab" v-if="screen=='changes'">
			<p>The following files have been modified since last deployment. They will be 'stashed' when the next deploy or revert occurs and the changes will only be recoverable via the command line (not explained here).</p>
			<p>Untracked files will be left as is.</p>
			<table class="table">
				<tr v-for="file in filesChanged" :class="{untracked: file.status == '??'}">
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
		
		<!-- Error Screen -->
		<div v-if="screen=='error'">
			<h1>An error occurred</h1>
			<h2>{{ error.errorMessage }}</h2>
			<code>{{ error.commandInput }}</code>
			<pre><code>{{ error.commandOutput }}</code></pre>
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
				deployInProgress: false,
				deployment: {
					time: null,
				},
				
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
				
				// The details of any AJAX error messages
				error: {
					errorMessage: null,
					commandOutput: null,
				},
			},
			computed: {
				/**
				 * Group the this.commitLog into past/current/future commits
				 */
				commitLogGrouped: function() {
					var groups = [
						{label: 'Awaiting deployment', commits: []},
						{label: 'Current commit', commits: []},
						{label: 'Previous commits', commits: []},
					];
					var currentCommitSeen = false;
					this.commitLog.forEach(function(item){
						if (item.hash == vueApp.currentCommit) {
							currentCommitSeen = true;
							groups[1].commits.push(item);
						} else if (!currentCommitSeen) {
							groups[0].commits.push(item);
						} else {
							groups[2].commits.push(item);
						}
					});
					return groups;
				},
				
				/**
				 * How many commits are there between currentCommit and selectedCommit?
				 */
				commitsToDeploy: function() {
					var currentCommitIndex = -1, selectedCommitIndex = -1;
					for (var i = 0; i < this.commitLog.length; i++) {
						if (this.commitLog[i].hash == this.currentCommit)
							currentCommitIndex = i;
						if (this.commitLog[i].hash == this.selectedCommit)
							selectedCommitIndex = i;
						if (currentCommitIndex > -1 && selectedCommitIndex > -1)
							return currentCommitIndex - selectedCommitIndex;
					}
					return '-';
				},
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
				 * Change between views/tabs within the UI
				 */
				changeScreen: function(screen) {
					// If we 'seem' to already be on that screen, enforce it
					if (window.location.hash == screen)
						this.screen = screen;
					// Otherwise, update the URL hash and our handler will update this.screen
					window.location.hash = screen;					
				},
				
				/**
				 * Perform a 'git fetch' and populate 'commitLog' with latest 
				 * list of commits
				 */
				refresh: function() {
					if (!window.axios) {
						console.warn('Cannot perform AJAX, axios library has not yet loaded.');
						return;
					}
					
					// Populate data in 'Status' tab
					axios.get('?action=GetStatus')
					.then(function(response){
						vueApp.status = response.data;
						vueApp.status.HTTPS = location.protocol == 'https:' ? 'Yes' : 'NO';
						vueApp.currentCommit = response.data['Current commit'].substr(0, 7);
					})
					.catch(this.handleAjaxError);
					
					// Perform a 'git fetch' and then get a list of last 200 commits
					axios.get('?action=FetchAndGetLog')
					.then(function(response){
						vueApp.commitLog = response.data;
						
						// Automatically select latest commit
						// (Do we *always* want to do this? What if user has previously chosen another?)
						if (vueApp.commitLog.length) {
							vueApp.selectedCommit = vueApp.commitLog[0].hash;
						}
					})
					.catch(this.handleAjaxError);
					
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
					})
					.catch(this.handleAjaxError);
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
					})
					.catch(this.handleAjaxError);
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
				},
				
				/**
				 * Run a deployment, sends a POST request to server
				 */
				deploy: function() {
					this.deployInProgress = true;
					var startTime = new Date();
					axios.post('?action=Deploy', {commit: this.selectedCommit})
					.then(function(response){
						// Yay, it appears to be a success!
						console.log('Deployment complete, response: ', response);
						vueApp.deployInProgress = false;
						vueApp.changeScreen('deploymentSuccess');
						vueApp.currentCommit = vueApp.selectedCommit;
						vueApp.filesToDeploy = [];
						vueApp.getFilesChanged();
						vueApp.deployment.time = Math.round(((new Date()) - startTime) / 10, 2) / 100;
					})
					.catch(this.handleAjaxError);
				},
				
				/** 
				 * Generic error handler for all AJAX requests
				 * See https://github.com/axios/axios#handling-errors
				 */
				handleAjaxError: function(error) {
					this.changeScreen('error');
					if (error.response) {
						console.error('DeployCat detected the following AJAX error: ', error.response.data);
						this.error = error.response.data;
					} else {
						this.error = {errorMessage: 'An unknown error occurred when making an AJAX request to the server. No response was received.'};
					}
				},
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
