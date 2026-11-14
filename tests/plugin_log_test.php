<?php
// The plugin's own log through the real zfsmLog: each line lands in the file, which turns over at its cap and never grows a third file.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/include/ZFSMHelpers.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$root = sys_get_temp_dir().'/zfsm-log-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($root.'/var/log', 0700, true);
register_shutdown_function(function() use ($root) { exec('rm -rf '.escapeshellarg($root)); });
$GLOBALS['zfsm_root'] = $root;
$log = $root.'/var/log/zfs.master.log';
$files = function() use ($root) { return array_values(array_diff(scandir($root.'/var/log'), array('.', '..'))); };

same($log, zfsmLogFile(), 'the log sits in /var/log');
zfsmLog("Snapshot created: tank/data@a\nsecond line", LOG_NOTICE);
zfsmLog('zfs list failed');
$lines = file($log, FILE_IGNORE_NEW_LINES);
same(1, preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d notice \['.getmypid().'\] Snapshot created: tank\/data@a; second line$/D', $lines[0]), 'a line has its time, level and process, on one line: '.$lines[0]);
same(1, preg_match('/ error \[\d+\] zfs list failed$/D', $lines[1]), 'a message with no level is an error: '.$lines[1]);
same(2, count($lines), 'one line per message');
zfsmLogKeep('pool lost', LOG_CRIT);
zfsmLogKeep('odd level', 42);
same(array(1, 1), array(preg_match('/ critical \[\d+\] pool lost$/D', file($log, FILE_IGNORE_NEW_LINES)[2]), preg_match('/ level 42 \[\d+\] odd level$/D', file($log, FILE_IGNORE_NEW_LINES)[3])), 'a level worse than error keeps its name, and one with no name its number');

// Debug Log, as the saved settings have it: off, a command leaves no line; on, each command the plugin runs is a line of the debug
// log, how it ended first. zfs and zpool are the test's fakes, so nothing touches a real pool.
mkdir($root.'/bin');
foreach (array('zfs', 'zpool') as $fake) {
	copy(__DIR__.'/fixtures/bin/'.$fake, $root.'/bin/'.$fake);
	chmod($root.'/bin/'.$fake, 0755);
}
putenv('ZFSM_TEST_BIN_DIR='.$root.'/bin');
mkdir($root.'/boot/config/plugins/zfs.master', 0700, true);
$debug_log = $root.'/var/log/zfs.master.debug.log';
$debug_lines = function($setting, $run) use ($root, $log, $debug_log) {
	file_put_contents($root.'/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\ndebug_log=\"$setting\"\n");
	unset($GLOBALS['zfsm_debug']);
	file_put_contents($log, '');
	@unlink($debug_log);
	$run();
	return array(is_file($debug_log) ? file($debug_log, FILE_IGNORE_NEW_LINES) : array(), file($log, FILE_IGNORE_NEW_LINES));
};
same(array(array(), array()), $debug_lines('0', function() { runProcess('echo', array('hello')); }), 'with Debug Log off a command leaves no line');
list($lines, $plugin_lines) = $debug_lines('1', function() {
	runProcess('echo', array('hello', 'tank/data'));
	runProcess('sh', array('-c', 'exit 3'));
	try { zfsmWithDeadline(0.3, function() { runProcess('sleep', array('5')); }); } catch (ZFSMTimeout $timeout) {}
	runProcess('zfs', array('load-key', '-L', 'file:///boot/secret.key', 'tank'));
	runProcess('zfs', array('set', 'keylocation=file:///boot/secret.key', 'tank/data'));
	runProcess('zpool', array('create', '-O', 'KEYLOCATION=https://user:secret.key@keys.example/p', 'tank2', 'sdc'));
	runProcess('rsync', array('--version'));
	runProcess('sh', array('-c', 'true # zfs set keylocation=file:///boot/secret.key tank'));
	runProcess('echo', array("two\nlines", 'keylocation=file:///boot/secret.key'));
	runProcess('zfs', array('create', '-o', 'encryption=on', '-o', 'keylocation=prompt', 'tank/new'));
	try { runProcess('echo', 'not a list'); } catch (Throwable $error) {}
	runProcess('echo', array(str_repeat('a', 600)));
	zfsmDebug("request snapshotdataset\nsecond line");
});
same(array(13, array()), array(count($lines), $plugin_lines), 'with it on, each command is a line of the debug log and none goes to the plugin\'s own log');
same(array(1, 1, 1), array(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d debug \[\d+\] exit 0 in \d+\.\d{3} s: echo hello tank\/data$/D', $lines[0]), preg_match('/ debug \[\d+\] exit 3 in \d+\.\d{3} s: sh -c exit 3$/D', $lines[1]), preg_match('/ debug \[\d+\] timed out in 0\.\d{3} s: sleep 5$/D', $lines[2])),
	'with it on a command is a line of the debug log with its exit code or its timeout, its time, then its arguments: '.implode(' | ', $lines));
same(array(1, 1, 1, 1, 1, 1, 1, false), array(preg_match('/ s: zfs load-key \(its arguments are left out\)$/D', $lines[3]), preg_match('/ s: zfs set \(its arguments are left out\)$/D', $lines[4]), preg_match('/ s: zpool create \(its arguments are left out\)$/D', $lines[5]), preg_match('/ s: rsync \(its arguments are left out\)$/D', $lines[6]),
	preg_match('/ s: sh \(its arguments are left out\)$/D', $lines[7]), preg_match('/ s: echo \(its arguments are left out\)$/D', $lines[8]), preg_match('/ s: zfs create -o encryption=on -o keylocation=prompt tank\/new$/D', $lines[9]), strpos(implode('', $lines), 'secret.key')),
	'rsync\'s arguments and those of any command that names where a key is kept are left out, wrapped or across a line break; keylocation=prompt names none');
same(array(1, 1), array(preg_match('/ debug \[\d+\] failed: .+ in \d+\.\d{3} s: echo not a list$/D', $lines[10]), preg_match('/ debug \[\d+\] exit 0 in \d+\.\d{3} s: echo a{100,}$/D', $lines[11])), 'a command that could not run says why, and a long one keeps how it ended: '.$lines[10].' | '.$lines[11]);
same(1, preg_match('/ debug \[\d+\] request snapshotdataset; second line$/D', $lines[12]), 'a debug line is one line, like any other');
file_put_contents($root.'/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\ndebug_log=\"0\"\n");
runProcess('echo', array('again'));
same(14, count(file($debug_log)), 'the setting is read once for each run');
// A long result is cut so the command still fits the line.
zfsmDebugCommand('zfs', array('destroy', 'tank/end'), 'failed: '.str_repeat('m', 600), microtime(true));
same(1, preg_match('/ failed: m{100,} in \d+\.\d{3} s: zfs destroy tank\/end$/D', file($debug_log, FILE_IGNORE_NEW_LINES)[14]), 'a long failure keeps the command it is about');
// The setting is read from the saved file alone: Unraid would merge a default.cfg first, and the plugin ships none.
same(false, file_exists(__DIR__.'/../zfs.master/default.cfg'), 'the plugin ships no default.cfg, so the saved file is the whole setting');
unset($GLOBALS['zfsm_debug']);
putenv('ZFSM_TEST_BIN_DIR');
unlink($debug_log);

// At the cap the file becomes .1 and a new one starts; the next turn replaces .1, so there are never three.
file_put_contents($log, str_repeat('x', ZFSM_LOG_BYTES - 1));
zfsmLog('below the cap');
same(array('zfs.master.log'), $files(), 'a log under its cap stays one file');
zfsmLog('at the cap');
same(array(true, 'x'), array(filesize($log) < 200, file_get_contents($log.'.1')[0]), 'a full log becomes .1 and a new one starts');
file_put_contents($log, str_repeat('y', ZFSM_LOG_BYTES));
zfsmLog('at the cap again');
same(array('y', array('zfs.master.log', 'zfs.master.log.1')), array(file_get_contents($log.'.1')[0], $files()), 'the next full log replaces .1');

// However much is logged, the two files stay near 1 MB of RAM. The file half only: 4,000 lines have no place in a system log.
$largest = 0;
for ($line = 0; $line < 4000; $line++) {
	zfsmLogKeep(str_repeat('z', 480), LOG_ERR);
	if ($line % 100 === 0) { clearstatcache(); $largest = max($largest, filesize($log) + filesize($log.'.1')); }
}
same(array(1, true), array(preg_match('/^\d{4}-\d\d-\d\d /', file_get_contents($log.'.1', false, null, 0, 20)), $largest <= 2 * ZFSM_LOG_BYTES + 1100), 'the log turned over, and never passed two files of the cap: '.$largest);
same(array('zfs.master.log', 'zfs.master.log.1'), $files(), 'and still has two files');

// Two writers at the cap: the one that waited for the other's turn-over must not turn the new file over too, or the full one is lost.
file_put_contents($log, str_repeat('w', ZFSM_LOG_BYTES));
$turn = '$h = fopen($argv[1], "a"); flock($h, LOCK_EX); touch($argv[2]); usleep(600000); rename($argv[1], $argv[1].".1"); file_put_contents($argv[1], "first after the turn-over\n"); flock($h, LOCK_UN);';
$holder = proc_open(array(PHP_BINARY, '-n', '-r', $turn, '--', $log, $root.'/held'), array(), $pipes);
for ($wait = 0; $wait < 100 && !file_exists($root.'/held'); $wait++) usleep(50000);
same(true, file_exists($root.'/held'), 'another writer holds the log at its cap');
zfsmLog('second writer');
proc_close($holder);
clearstatcache();
same(array('w', "first after the turn-over\n", 1), array(file_get_contents($log.'.1', false, null, 0, 1), file($log)[0], preg_match('/ second writer$/D', trim(file_get_contents($log)))), 'the writer that waited writes to the new file and leaves .1 alone');

// A log that opens but takes no more, as on a full /var/log, drops the line as quietly.
mkdir($root.'/full/var/log', 0700, true);
symlink('/dev/full', $root.'/full/var/log/zfs.master.log');
$GLOBALS['zfsm_root'] = $root.'/full';
zfsmLog('no room');
same(array('zfs.master.log'), array_values(array_diff(scandir($root.'/full/var/log'), array('.', '..'))), 'a line that can\'t be written is dropped, with no other file made');

// A log that can't be written never stops the action that logged.
$GLOBALS['zfsm_root'] = $root.'/missing';
zfsmLog('nowhere to write');
same(false, file_exists($root.'/missing'), 'a missing folder is not made');

echo "plugin log tests passed\n";
