<?php
// Scripts wait while an update replaces the plugin's files: the plg's Run holds the install lock, and the real schedule.php loads inside it.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/include/ZFSMInstallLock.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$repo = dirname(__DIR__);
$lock = zfsmInstallLockFile();
same('/var/run/zfs.master.install.lock', $lock, 'the install lock sits in /var/run, beside the settings lock');

preg_match_all('/ Run="[^"]*upgradepkg[^"]*"/', file_get_contents($repo.'/zfs.master.plg'), $runs);
same(array(' Run="flock /var/run/zfs.master.install.lock upgradepkg --install-new --reinstall"'), $runs[0], 'the plg runs upgradepkg under the install lock');

$scripts = glob($repo.'/zfs.master/scripts/*.php');
same(true, count($scripts) >= 6, 'scripts/ holds its six scripts, or more');
// Inline in each script, as nothing may load before it: the lines that open and lock zfsmInstallLockFile().
$wait = array(
	'// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.',
	'$zfsm_install_hold = @fopen('.var_export($lock, true).", 'ce');",
	'$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);',
);
foreach ($scripts as $script) {
	$name = basename($script);
	$lines = explode("\n", file_get_contents($script));
	$loads = array_keys(array_filter($lines, function($line) { return preg_match('/^\s*(require|include)(_once)?\b/', $line) === 1; }));
	same($wait, array_slice($lines, $loads[0] - 3, 3), $name.' waits while an update installs, right before its first load');
	same(range($loads[0], end($loads)), $loads, $name.' loads the plugin in one block');
	same('zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);', $lines[end($loads) + 1], $name.' lets go right after its last load');
}

// A docroot whose Helpers.php says which version of it a run loaded; the plugin is the repo's.
$docroot = sys_get_temp_dir().'/zfsm-install-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($docroot.'/webGui/include', 0700, true);
mkdir($docroot.'/plugins');
symlink($repo.'/zfs.master', $docroot.'/plugins/zfs.master');
register_shutdown_function(function() use ($docroot) { exec('rm -rf '.escapeshellarg($docroot)); });
$helpers = function($version) use ($docroot, $repo) {
	file_put_contents($docroot.'/webGui/include/Helpers.php', "<?php\necho 'Helpers.php $version', \"\\n\";\nrequire ".var_export($repo.'/tests/fixtures/unraid/Helpers.php', true).";\n");
};
$start = function() use ($docroot, $repo) {
	$proc = proc_open(array(PHP_BINARY, '-n', $repo.'/zfs.master/scripts/schedule.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array('ZFSM_TEST_DOCROOT' => $docroot) + getenv());
	return array($proc, $pipes);
};
// The run ends as schedule.php with no arguments does, having loaded the Helpers.php the update left; one still going after 20 s
// fails the test rather than hang it.
$runs = function($run, $message) {
	list($proc, $pipes) = $run;
	$until = microtime(true) + 20;
	while (($status = proc_get_status($proc))['running'] && microtime(true) < $until) usleep(20000);
	if ($status['running']) proc_terminate($proc, 9);
	$said = $status['running'] ? 'still running after 20 s' : array($status['exitcode'], stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
	proc_close($proc);
	same(array(2, "Helpers.php new\n", "Usage: schedule.php run <schedule id> | schedule.php start | schedule.php heal\n"), $said, $message);
};
// Another process holding the lock as $mode: flock(1) in the plg's Run takes it exclusively; a script loading takes it shared.
$hold = function($mode) use ($lock) {
	$proc = proc_open(array(PHP_BINARY, '-n', '-r', '$f = fopen($argv[1], "c"); flock($f, '.$mode.'); echo "held\n"; fgets(STDIN);', $lock), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes);
	same("held\n", fgets($pipes[1]), 'another process holds the install lock');
	return function() use ($proc, $pipes) { fclose($pipes[0]); proc_close($proc); };
};

$helpers('old');
$release = $hold('LOCK_EX');
$run = $start();
sleep(1);
same(true, proc_get_status($run[0])['running'], 'schedule.php waits while an update holds the install lock');
$helpers('new');
$release();
$runs($run, 'then loads the files the update left, and runs');

$release = $hold('LOCK_SH');
$runs($start(), 'a script loading at the same moment does not hold it up');
$release();

$runs($start(), 'with no update installing, it runs');

// A lock that can't be opened (a folder in its place): the run goes as before, and the log says it did not wait.
@unlink($lock);
mkdir($lock);
// same() exits, which skips a finally: the folder goes at shutdown, so later tests find the lock as usual.
register_shutdown_function(function() use ($lock) { if (is_dir($lock)) rmdir($lock); });
$runs($start(), 'with no lock to take, it runs as before');
$log = (string)@file_get_contents('/var/log/zfs.master.log');
same(1, preg_match('~ \[\d+\] This run did not wait for a plugin update to finish installing, as it could not lock /var/run/zfs\.master\.install\.lock\n$~D', $log), 'and the plugin log says it did not wait');

echo "install lock tests passed\n";
