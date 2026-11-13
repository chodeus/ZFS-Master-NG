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
// This run's own commands are no debug lines: they would bury what Debug Log was turned on to catch (timings.txt has them).
$GLOBALS['zfsm_debug'] = false;

$job = in_array('--job', $argv, true);
// One run at a time, from the page or a terminal, held to its end (zfsmDiagRunLock). A job says why it did not start on its output,
// which the page's start reads (zfsmStartJob); the page is answered once the job holds the lock and has said so.
$lock = zfsmDiagRunLock();
$kept = $job && is_resource($lock) && zfsmDiagJobSet(array('state' => 'running'));
if (!is_resource($lock) || $job && !$kept) {
	$why = is_resource($lock) ? 'The run\'s state could not be kept in '.dirname(zfsmDiagJobFile('state.json')) : $lock;
	if ($job) echo $why, "\n";
	else fwrite(STDERR, $why."\n");
	exit(1);
}
if ($job) zfsmJobStarted();
// How a run ends: a terminal is told, the page reads the state. A job has no terminal to write to. A zip that was made can
// still come with something to say.
$end = function($zip, $why) use ($job) {
	$state = $zip !== null ? array('state' => 'done', 'zip' => basename($zip), 'why' => $why) : array('state' => 'failed', 'why' => $why);
	// The page reads the run's state: one that can't be kept leaves it on the last one, so the log says so.
	if ($job && !zfsmDiagJobSet($state)) zfsmLog('Diagnostics: the result of the run started from Settings could not be kept in '.dirname(zfsmDiagJobFile('state.json')));
	elseif (!$job && $zip !== null) echo "\nDiagnostics zip: $zip\n";
	if (!$job && $why !== '') fwrite(STDERR, $why."\n");
	exit($zip === null ? 1 : 0);
};

$work = zfsmDiagWorkDir();
if ($work === null) $end(null, 'Unable to make a work folder in /tmp');
// The folder holds real names and is root's alone: the run's end tries to remove it, and a later run tries again.
register_shutdown_function(function() use ($work, $job) {
	if (is_dir($work) && !zfsmDiagRemove($work) && !$job) fwrite(STDERR, "The work folder could not be removed and still holds the files: $work\n");
});
// Ctrl-C, a dropped session or a kill ends PHP without its shutdown functions: where it has pcntl, they end it with one.
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGHUP, SIGTERM) as $signal) pcntl_signal($signal, function() { exit(130); });
}
$written = zfsmDiagRun($work, zfsmDiagContext(parse_plugin_cfg('zfs.master', true), !in_array('--no-unraid', $argv, true), $job));
// Nothing is shown or packed with a real name in it.
if (!zfsmDiagAnonymizeDir($work)) $end(null, 'The names in the files could not all be replaced, so the files are not shown and no zip was made');
if (!$job) echo zfsmDiagFile($work.'/collectors.txt'), "\n", zfsmDiagFile($work.'/summary.txt');
list($zip, $why) = $written ? zfsmDiagPack($work) : array(null, "some files could not be written to $work");
$end($zip, $zip === null ? 'No zip was made: '.$why : $why);
