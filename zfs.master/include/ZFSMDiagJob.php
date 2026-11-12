<?php
// A diagnostics run started from the Settings page (backend/ZFSMAdmin.php): one at a time, in a session of its own
// (include/ZFSMJobs.php). scripts/diagnostics.php --job is the run; the page asks for its state.

// The run's files in RAM: its lock (run.lock), its state (state.json) and what the browser sent with the request (browser.json).
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
	if (zfsmJobRunning($lock)) return array('running', '');
	if (!is_dir(dirname($lock))) @mkdir(dirname($lock), 0700, true);
	if (@file_put_contents(zfsmDiagJobFile('browser.json'), substr((string)$report, 0, 65536)) === false) return array('failed', 'The browser\'s report could not be kept');
	return zfsmStartJob('diagnostics.php', array_merge(array('--job'), $unraid ? array() : array('--no-unraid')), $lock);
}

// What the browser said of itself when it asked for the run; a run from a terminal has none.
function zfsmDiagBrowser($ctx) {
	return empty($ctx['job']) ? 'This run was started from a terminal, so no browser sent a report.' : zfsmDiagFile(zfsmDiagJobFile('browser.json'));
}
