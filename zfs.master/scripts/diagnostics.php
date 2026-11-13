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
if ($job) {
	// One run for the page at a time: the lock is held to the run's end, and the page is answered once it is held.
	// The run only reads: a command it started and left behind must not keep the page saying "running".
	$lock = zfsmJobLock(zfsmDiagJobFile('run.lock'), false);
	if (!$lock) exit(1);
	zfsmDiagJobSet(array('state' => 'running'));
	zfsmJobStarted();
}
// How a run ends: a terminal is told, the page reads the state. A job has no terminal to write to. A zip that was made can
// still come with something to say.
$end = function($zip, $why) use ($job) {
	if ($job) zfsmDiagJobSet($zip !== null ? array('state' => 'done', 'zip' => basename($zip), 'why' => $why) : array('state' => 'failed', 'why' => $why));
	elseif ($zip !== null) echo "\nDiagnostics zip: $zip\n";
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
