<?php
// A diagnostics run started from the Settings page (backend/ZFSMAdmin.php): one at a time, in a session of its own
// (include/ZFSMJobs.php). scripts/diagnostics.php --job is the run; the page asks for its state.

// The run's files in RAM: its lock (run.lock), the lock of a start (start.lock), its state (state.json) and what the browser
// sent with the request (browser.json).
function zfsmDiagJobFile($name) {
	return zfsmJobDir('diagnostics').'/'.$name;
}

// What the page shows: array('state' => 'none', 'running', 'done' with 'zip', its name, or 'failed' with 'why').
function zfsmDiagJobState() {
	if (zfsmJobRunning(zfsmDiagJobFile('run.lock'))) return array('state' => 'running');
	$state = json_decode((string)@file_get_contents(zfsmDiagJobFile('state.json')), true);
	if (!is_array($state) || !is_string($state['state'] ?? null)) return array('state' => 'none');
	// Still "running" with no run holding the lock: the run was killed.
	if ($state['state'] === 'running') return array('state' => 'failed', 'why' => 'The run ended without a result');
	// A zip that is gone, as the fourth newest goes, is no longer there to download.
	if ($state['state'] === 'done' && !is_file(zfsmStatePath('/boot/logs').'/'.basename((string)($state['zip'] ?? '')))) return array('state' => 'none');
	return array_intersect_key($state, array_flip(array('state', 'zip', 'why')));
}

function zfsmDiagJobSet($state) {
	$file = zfsmDiagJobFile('state.json');
	if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
	return @file_put_contents($file.'.tmp', zfsmJson($state)) !== false && @rename($file.'.tmp', $file);
}

// Starts a run for the page: ['started', ''], ['running', ''] when one already runs, or ['failed', why]. $report is what the
// browser says of itself, kept as text for the run to put among its files: no more than 64 KB of it.
function zfsmStartDiagnostics($unraid, $report) {
	$lock = zfsmDiagJobFile('run.lock');
	// Check, keep the report and start as one step: a start meanwhile is told a run is going, and never replaces this report.
	// Not passed to the job: a job whose start went wrong must not hold it for every later start.
	$start = zfsmJobLock(zfsmDiagJobFile('start.lock'), false);
	if ($start === null) return array('failed', 'The diagnostics lock could not be opened in '.dirname($lock));
	if ($start === false) return array('running', '');
	if (zfsmJobRunning($lock)) $started = array('running', '');
	elseif (@file_put_contents(zfsmDiagJobFile('browser.json'), substr((string)$report, 0, 65536)) === false) $started = array('failed', 'The browser\'s report could not be kept');
	else $started = zfsmStartJob('diagnostics.php', array_merge(array('--job'), $unraid ? array() : array('--no-unraid')), $lock);
	zfsmJobUnlock($start);
	return $started;
}

// The lock one run holds to its end, from the page or a terminal, or why it can't be had. Its commands do not hold it, and it is
// asked for again for two seconds: a status read holds it shared for a moment (zfsmJobRunning).
function zfsmDiagRunLock() {
	for ($try = 0; $try < 20; $try++) {
		$lock = zfsmJobLock(zfsmDiagJobFile('run.lock'), false);
		if ($lock === null) return 'The diagnostics lock could not be opened in '.dirname(zfsmDiagJobFile('run.lock'));
		if ($lock !== false) return $lock;
		usleep(100000);
	}
	return 'Another diagnostics run is going: wait for it to end, then try again';
}

// What the browser said of itself when it asked for the run; a run from a terminal has none.
function zfsmDiagBrowser($ctx) {
	return empty($ctx['job']) ? 'This run was started from a terminal, so no browser sent a report.' : zfsmDiagFile(zfsmDiagJobFile('browser.json'));
}
