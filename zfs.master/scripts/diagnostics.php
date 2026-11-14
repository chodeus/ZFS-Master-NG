#!/usr/bin/php -q
<?php
// Collects the diagnostics files (include/ZFSMDiagnostics.php), replaces the names in them and packs them into a zip in /boot/logs
// (include/ZFSMDiagBundle.php). --no-unraid leaves Unraid's own diagnostics out; --job is the Settings page's run. Must stay executable: pkg_build.sh.

// From a terminal or as the page's job only: as a page itself, any request to its address would start a run.
if (PHP_SAPI !== 'cli') exit(1);
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
require_once "$docroot/plugins/zfs.master/include/ZFSMDiagnostics.php";

$job = in_array('--job', $argv, true);
// One run at a time, from the page or a terminal, held to its end (zfsmDiagRunLock). A job says why it did not start on its output,
// which the page's start reads (zfsmStartJob); the page is answered once the job holds the lock and has said so.
$lock = zfsmDiagRunLock();
// A run from a terminal keeps its state too, so the page follows whichever run is last; only the page's own job needs it kept.
$kept = is_resource($lock) && zfsmDiagJobSet(array('state' => 'running'));
if (!is_resource($lock) || $job && !$kept) {
	$why = is_resource($lock) ? 'The run\'s state could not be kept in '.dirname(zfsmDiagJobFile('state.json')) : $lock;
	if ($job) echo $why, "\n";
	else fwrite(STDERR, $why."\n");
	exit(1);
}
if ($job) zfsmJobStarted();
// How a run ends: a terminal is told, the page reads the state. A job has no terminal to write to. A zip that was made can
// still come with something to say.
$ended = false;
$end = function($zip, $why) use ($job, &$ended) {
	$ended = true;
	$state = $zip !== null ? array('state' => 'done', 'zip' => basename($zip), 'why' => $why) : array('state' => 'failed', 'why' => $why);
	// The page reads the run's state: a result that can't be kept is not shown there, so the log says so.
	if (!zfsmDiagJobSet($state)) zfsmLog('Diagnostics: the result of a run could not be kept in '.dirname(zfsmDiagJobFile('state.json')).', so Settings does not show it');
	if (!$job && $zip !== null) echo "\nDiagnostics zip: $zip\n";
	if (!$job && $why !== '') fwrite(STDERR, $why."\n");
	exit($zip === null ? 1 : 0);
};

// The folder holds real names and is root's alone: the run's end tries to remove it, and a later run tries again. Registered
// before the folder is made, so a run that can't make one still removes the browser's report.
$work = null;
register_shutdown_function(function() use (&$work, $job, &$ended) {
	$made = $work !== null && is_dir($work);
	if ($made && !zfsmDiagStillOurs($work)) {
		if (!$job) fwrite(STDERR, "The work folder is not this run's any more, so it was left as it is: $work\n");
	} elseif ($made && !zfsmDiagRemove($work) && !$job) {
		fwrite(STDERR, "The work folder could not be removed and still holds the files: $work\n");
	}
	// A run stopped before its end (Ctrl-C, a signal, a fatal error) says so on the page; the browser's report goes on every exit.
	if (!$ended) zfsmDiagJobSet(array('state' => 'failed', 'why' => 'The run was stopped before it ended'));
	if ($job && file_exists(zfsmDiagJobFile('browser.json')) && !@unlink(zfsmDiagJobFile('browser.json'))) zfsmLog('Diagnostics: the browser\'s report could not be removed from '.dirname(zfsmDiagJobFile('browser.json')));
});
$work = zfsmDiagWorkDir();
if ($work === null) $end(null, 'Unable to make a work folder in /tmp');
// Ctrl-C, a dropped session or a kill ends PHP without its shutdown functions: where it has pcntl, they end it with one.
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGHUP, SIGTERM) as $signal) pcntl_signal($signal, function() { exit(130); });
}
$written = zfsmDiagRun($work, zfsmDiagContext(parse_plugin_cfg('zfs.master', true), !in_array('--no-unraid', $argv, true), $job));
if ($written === null || !zfsmDiagStillOurs($work)) $end(null, 'The work folder changed under the run, so nothing was rewritten, shown or packed');
// Nothing is shown or packed with a real name in it.
if (!zfsmDiagAnonymizeDir($work)) $end(null, 'The names in the files could not all be replaced, so the files are not shown and no zip was made');
if (!$job) echo zfsmDiagFile($work.'/collectors.txt'), "\n", zfsmDiagFile($work.'/summary.txt');
list($zip, $why) = !zfsmDiagStillOurs($work) ? array(null, 'the work folder changed under the run') : ($written ? zfsmDiagPack($work) : array(null, "some files could not be written to $work"));
$end($zip, $zip === null ? 'No zip was made: '.$why : $why);
