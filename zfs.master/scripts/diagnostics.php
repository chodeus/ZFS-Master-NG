#!/usr/bin/php -q
<?php
// Collects the diagnostics files (include/ZFSMDiagnostics.php) and prints what they show. Must stay executable: pkg_build.sh.
// Their folder holds real names and is root's alone. The end of the run tries to remove it and says so when it can't; a later run tries again.

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
register_shutdown_function(function() use ($work) {
	if (!zfsmDiagRemove($work)) fwrite(STDERR, "The work folder could not be removed and still holds the files: $work\n");
});
// Ctrl-C, a dropped session or a kill ends PHP without its shutdown functions: where it has pcntl, they end it with one.
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGHUP, SIGTERM) as $signal) pcntl_signal($signal, function() { exit(130); });
}
$written = zfsmDiagRun($work, zfsmDiagContext(parse_plugin_cfg('zfs.master', true)));
echo zfsmDiagFile($work.'/collectors.txt'), "\n", zfsmDiagFile($work.'/summary.txt');
if (!$written) fwrite(STDERR, "Some files could not be written to $work\n");
exit($written ? 0 : 1);
