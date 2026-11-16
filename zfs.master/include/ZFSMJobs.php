<?php
// Background jobs (scripts/rewrite.php, scripts/replicate.php): one per dataset at a time, each in a session of its own.

// One lock file per dataset and job, named by the dataset's guid: the lock follows a renamed dataset, and a long name can't make the file name too long.
// The file stays after a run, so two runs never lock different files for one dataset; a crashed run's lock goes with its process.
function zfsmJobLockFile($zdataset, $job) {
	$guid = getDatasetProperty(explode('/', $zdataset, 2)[0], $zdataset, 'guid');
	return is_string($guid) && preg_match('/^[0-9]+$/D', $guid) ? zfsmJobDir($job).'/'.$guid.'.lock' : null;
}

// The folder of a job's locks. Tests set it in the environment, so the background script they start uses it too.
function zfsmJobDir($job) {
	$test_dir = getenv('ZFSM_TEST_JOB_DIR');
	return $test_dir ? rtrim($test_dir, '/').'/'.$job : '/var/run/zfs.master.'.$job;
}

function zfsmJobRunning($lock_file) {
	$handle = @fopen($lock_file, 'r');
	if ($handle === false) return false;
	$free = flock($handle, LOCK_SH | LOCK_NB);
	if ($free) flock($handle, LOCK_UN);
	fclose($handle);
	return !$free;
}

// The lock for this run: false when another run holds it, null when it can't be opened. Commands the run starts hold it too,
// so a dataset stays locked while its zfs runs, unless $children is false: then one left behind can't hold it.
function zfsmJobLock($lock_file, $children = true) {
	if (!is_dir(dirname($lock_file))) @mkdir(dirname($lock_file), 0700, true);
	$lock = @fopen($lock_file, $children ? 'c' : 'ce');
	if ($lock === false) return null;
	if (flock($lock, LOCK_EX | LOCK_NB)) return $lock;
	fclose($lock);
	return false;
}

function zfsmJobUnlock($lock) {
	flock($lock, LOCK_UN);
	fclose($lock);
}

// Starts scripts/<script> in a session of its own, so the job outlives the page that asked for it.
// Returns 'started' once it said so (it holds its lock by then, zfsmJobStarted), 'running' when another run holds the lock,
// or 'failed' with the first of what it printed.
function zfsmStartJob($script, $args, $lock_file) {
	$path = ($GLOBALS['docroot'] ?? '/usr/local/emhttp').'/plugins/zfs.master/scripts/'.$script;
	$php = $GLOBALS['zfsm_php'] ?? '/usr/bin/php';
	$launch = runProcess('setsid', array_merge(array('-f', $php, '-q', $path), $args));
	if (in_array('started', preg_split('/\R/', (string)($launch['output'] ?? '')), true)) return array('started', '');
	if ($lock_file !== null && zfsmJobRunning($lock_file)) return array('running', '');
	return array('failed', substr(zfsmOneLine($launch['output'] ?? ''), 0, 300));
}

// The job's side of zfsmStartJob: say "started", then let go of the pipe so the page is answered at once.
function zfsmJobStarted() {
	fwrite(STDOUT, "started\n");
	fflush(STDOUT);
	fclose(STDIN);
	fclose(STDOUT);
	fclose(STDERR);
	// Reopened on /dev/null they take fds 0-2 again, so nothing opened later is written to as stdout or stderr.
	$GLOBALS['zfsm_stdio'] = array(fopen('/dev/null', 'r'), fopen('/dev/null', 'w'), fopen('/dev/null', 'w'));
}
