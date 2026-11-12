#!/usr/bin/php -q
<?php
// Collects the diagnostics files (include/ZFSMDiagnostics.php), replaces the names in them, prints what they show and packs them
// into a zip in /boot/logs (include/ZFSMDiagBundle.php). --no-unraid leaves Unraid's own diagnostics out. Must stay executable: pkg_build.sh.

// From a terminal only: as a page, any request to its address would start a run.
if (PHP_SAPI !== 'cli') exit(1);
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
require_once "$docroot/plugins/zfs.master/include/ZFSMDiagnostics.php";

$work = zfsmDiagWorkDir();
if ($work === null) {
	fwrite(STDERR, "Unable to make a work folder in /tmp\n");
	exit(1);
}
// The folder holds real names and is root's alone: the run's end tries to remove it, and a later run tries again.
register_shutdown_function(function() use ($work) {
	if (is_dir($work) && !zfsmDiagRemove($work)) fwrite(STDERR, "The work folder could not be removed and still holds the files: $work\n");
});
// Ctrl-C, a dropped session or a kill ends PHP without its shutdown functions: where it has pcntl, they end it with one.
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGHUP, SIGTERM) as $signal) pcntl_signal($signal, function() { exit(130); });
}
$written = zfsmDiagRun($work, zfsmDiagContext(parse_plugin_cfg('zfs.master', true), !in_array('--no-unraid', $argv, true)));
// Nothing is shown or packed with a real name in it.
if (!zfsmDiagAnonymizeDir($work)) {
	fwrite(STDERR, "The names in the files could not all be replaced, so the files are not shown and no zip was made\n");
	exit(1);
}
echo zfsmDiagFile($work.'/collectors.txt'), "\n", zfsmDiagFile($work.'/summary.txt');
list($zip, $why) = $written ? zfsmDiagPack($work) : array(null, "some files could not be written to $work");
if ($zip === null) fwrite(STDERR, "No zip was made: $why\n");
else echo "\nDiagnostics zip: $zip\n";
exit($zip === null ? 1 : 0);
