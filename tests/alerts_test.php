<?php
// Pool and snapshot alerts through the real runProcess, with fake zpool, zfs and notify.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$bin_dir = getenv('ZFSM_TEST_BIN_DIR');
if (!is_string($bin_dir) || !is_executable($bin_dir.'/zpool') || !is_executable($bin_dir.'/zfs')) {
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold the fake zpool and zfs\n");
	exit(1);
}

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$script = '/usr/local/emhttp/plugins/zfs.master/scripts/alerts.php';
same('', zfsmAlertCron(0, $script), 'off writes no schedule');
same('', zfsmAlertCron(30, $script), 'a value the page never offers writes no schedule');
same("# Generated ZFS Master NG alert schedule:\n*/15 * * * * $script check &> /dev/null\n\n", zfsmAlertCron(15, $script), 'every 15 minutes');
same("# Generated ZFS Master NG alert schedule:\n7 * * * * $script check &> /dev/null\n\n", zfsmAlertCron(60, $script), 'hourly');
same("# Generated ZFS Master NG alert schedule:\n17 4 * * * $script check &> /dev/null\n\n", zfsmAlertCron(1440, $script), 'daily');

// zpool status -p as OpenZFS writes it: wrapped status: and action: lines are tab-indented like device rows.
$healthy = "  pool: tank\n state: ONLINE\nstatus: Some supported and requested features are not enabled on the pool.\n\tThe pool can still be used, but some features are unavailable.\nconfig:\n\n\tNAME        STATE     READ WRITE CKSUM\n\ttank        ONLINE       0     0     0\n\t  mirror-0  ONLINE       0     0     0\n\t    sda     ONLINE       0     0     0\n\t    sdb     ONLINE       0     0     0\n\nerrors: No known data errors\n";
$degraded = "  pool: tank\n state: DEGRADED\nstatus: One or more devices could not be used because the label is missing or\n\tinvalid.  Sufficient replicas exist for the pool to continue\n\tfunctioning in a degraded state.\n\tA DEGRADED pool still serves data.\naction: Replace the device using 'zpool replace'.\nconfig:\n\n\tNAME        STATE     READ WRITE CKSUM\n\ttank        DEGRADED     0     0     0\n\t  mirror-0  DEGRADED     0     0     0\n\t    sda     ONLINE       0     0     0\n\t    sdb     UNAVAIL      0     0     0  corrupted data\n\tspares\n\t  sdc       AVAIL\n\nerrors: No known data errors\n";
$errors = "  pool: tank\n state: ONLINE\nstatus: One or more devices has experienced an error resulting in data\n\tcorruption.  Applications may be affected.\nconfig:\n\n\tNAME        STATE     READ WRITE CKSUM\n\ttank        ONLINE       0     0     0\n\t  mirror-0  ONLINE       0     0     0\n\t    sda     ONLINE       0     0    12\n\t    sdb     ONLINE       0     0     0\n\nerrors: 2 data errors, use '-v' for a list\n";

$pools = zfsmParsePoolStatus($healthy."\n".str_replace('tank', 'cache', $errors));
same(array('tank', 'cache'), array_keys($pools), 'every pool in the output');
same(null, zfsmPoolProblem('tank', $pools['tank']), 'ONLINE with no errors, and a feature notice, is fine');
same(array('title' => 'ZFS pool cache has errors', 'detail' => "sda: 0 read, 0 write, 12 checksum errors; errors: 2 data errors, use '-v' for a list", 'level' => 'alert'), array_diff_key(zfsmPoolProblem('cache', $pools['cache']), array('message' => 1)), 'checksum errors and damaged files on an ONLINE pool');
$problem = zfsmPoolProblem('tank', zfsmParsePoolStatus($degraded)['tank']);
same('ZFS pool tank is DEGRADED', $problem['title'], 'a degraded pool');
same('mirror-0: DEGRADED; sdb: UNAVAIL corrupted data', $problem['detail'], 'only the devices that are not fine, not the wrapped status text or the spare');
same(true, strpos($problem['message'], 'zpool replace') !== false, 'the notice carries the pool\'s status text');

same(array('cache', 'secure', 'disk1'), zfsmExpectedPools(array('cache' => array('name' => 'cache', 'fsType' => 'zfs'), 'secure' => array('name' => 'secure', 'fsType' => 'luks:zfs'), 'cache2' => array('name' => 'cache2', 'fsType' => ''), 'disk1' => array('name' => 'disk1', 'fsType' => 'zfs'), 'disk2' => array('name' => 'disk2', 'fsType' => 'xfs'))), 'ZFS pools, encrypted ones and ZFS array disks, not pool members or other filesystems');
same(array(), zfsmExpectedPools(false), 'no disks.ini, no pools expected');

$now = 1793700000;
$day = 86400;
$listing = "tank/a@old\t".($now - 40 * $day)."\ntank/a@older\t".($now - 50 * $day)."\ntank/b@new\t".($now - 5 * $day)."\ntank/c@edge\t".($now - 31 * $day)."\ntank/d@exact\t".($now - 30 * $day - 3600)."\ntank/docker/x@old\t".($now - 90 * $day)."\ntank/f@old\t".($now - 60 * $day)."\ntank/f@new\t".($now - $day)."\n";
$stale = zfsmStaleProblem($listing, $now, 30, 'docker');
same(array('tank/a', 'tank/c'), $stale['items'], 'newest snapshot more than 30 whole days old, as the Main tab counts; an old snapshot beside a new one and excluded datasets left out');
same('2 dataset(s) have no snapshot in the last 30 days: tank/a, tank/c', $stale['detail'], 'one notice listing them');
same(null, zfsmStaleProblem("tank/b@new\t".($now - $day)."\n", $now, 30, ''), 'all recent');
$many = '';
for ($i = 0; $i < 12; $i++) $many .= "tank/d$i@old\t".($now - 40 * $day)."\n";
same(1, preg_match('/: tank\/d0, [^ ]+(, [^ ]+){8} and 2 more$/', zfsmStaleProblem($many, $now, 30, '')['detail']), 'a long list shows 10 and counts the rest');

// The real check, run as cron runs it; the boot time comes from a fake /proc/uptime.
// Its own folder per run, so state from an earlier run can't leak in; removed again on any exit.
$tmp = sys_get_temp_dir().'/zfsm-alerts-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function() use ($tmp) { exec('rm -rf '.escapeshellarg($tmp)); });
$GLOBALS['zfsm_emhttp'] = $tmp;
$GLOBALS['zfsm_alert_state'] = $tmp.'/state.json';
$GLOBALS['zfsm_uptime'] = $tmp.'/uptime';
$log = $tmp.'/notify.log';
// zfsnotify runs <docroot>/plugins/dynamix/scripts/notify: point it at the fake.
@mkdir($tmp.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $tmp.'/docroot/plugins/dynamix/scripts/notify');
chmod($tmp.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $tmp.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$log);
$status_file = $tmp.'/status.txt';
putenv('ZFSM_TEST_ZPOOL_STATUS='.$status_file);
$cfg = array('alert_interval' => 60, 'alert_stale' => 0, 'snap_max_days_alert' => 30, 'dataset_exclusion' => '');
$boot = $now - 3600;
$run = function($config, $at) use ($tmp, &$boot) {
	file_put_contents($tmp.'/uptime', ($at - $boot).".25 100.00\n");
	zfsmRunAlerts($config, $at);
};
$array = function($md, $fs = null) use ($tmp) {
	file_put_contents($tmp.'/var.ini', 'mdState="'.$md.'"'."\n".'fsState="'.($fs ?? ($md === 'STARTED' ? 'Started' : 'Stopped')).'"'."\n");
};
$disks = function(...$pools) use ($tmp) {
	$ini = '';
	foreach ($pools as $pool) {
		list($name, $fs) = explode('=', $pool);
		$ini .= "[\"$name\"]\nname=\"$name\"\nfsType=\"$fs\"\n";
	}
	file_put_contents($tmp.'/disks.ini', $ini);
};
$sent = function() use ($log) {
	$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : array();
	@unlink($log);
	return $lines;
};
$disks('tank=zfs');
file_put_contents($status_file, $degraded);

$array('STARTED');
$run(array('alert_interval' => 0) + $cfg, $now);
same(array(), $sent(), 'a job left after alerts were turned off sends nothing');
same(false, is_file($GLOBALS['zfsm_alert_state']), 'and reads nothing');

$array('STOPPED');
$run($cfg, $now);
same(array(), $sent(), 'nothing until the array has started');

$array('STARTED');
$run($cfg, $now);
same(array('alert: ZFS pool tank is DEGRADED'), $sent(), 'a degraded pool alerts');
$run($cfg, $now + 60);
same(array(), $sent(), 'once, not every run');
file_put_contents($status_file, $errors);
$run($cfg, $now + 120);
same(array('alert: ZFS pool tank has errors'), $sent(), 'a different problem alerts again');
file_put_contents($status_file, str_replace('12', '40', $errors));
$run($cfg, $now + 180);
same(array(), $sent(), 'rising counts on the same problem do not');
file_put_contents($status_file, $healthy);
$run($cfg, $now + 240);
same(array('normal: Resolved: ZFS pool tank has errors'), $sent(), 'recovery sends a normal notice');

// A failed or hung read is a warning, and clears nothing it could not recheck.
file_put_contents($status_file, $degraded);
$run($cfg, $now + 300);
same(array('alert: ZFS pool tank is DEGRADED'), $sent(), 'degraded again');
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL=1');
$run($cfg, $now + 360);
same(array('warning: ZFS Master NG could not read pool health'), $sent(), 'a failed zpool status warns, without calling the pool resolved');
$run($cfg, $now + 420);
same(array(), $sent(), 'once');
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL');
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
$GLOBALS['zfsm_alert_read_seconds'] = 0.3;
$started = microtime(true);
$run($cfg, $now + 480);
same(true, microtime(true) - $started < 4, 'a hung zpool is stopped at the read limit');
same(array(), $sent(), 'and stays the same read problem');
same('zpool timed out', json_decode(file_get_contents($GLOBALS['zfsm_alert_state']), true)['problems']['read:pools']['detail'], 'naming the command');
unset($GLOBALS['zfsm_alert_read_seconds']);
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
$run($cfg, $now + 540);
same(array('normal: Resolved: ZFS Master NG could not read pool health'), $sent(), 'reading again resolves the read problem, and the still-degraded pool is not alerted twice');
file_put_contents($status_file, $healthy);
$run($cfg, $now + 600);
same(array('normal: Resolved: ZFS pool tank is DEGRADED'), $sent(), 'resolved once it really is');

// A notice that fails to send goes out with the next run.
file_put_contents($status_file, $degraded);
putenv('ZFSM_TEST_NOTIFY_FAIL=1');
$run($cfg, $now + 660);
putenv('ZFSM_TEST_NOTIFY_FAIL');
$run($cfg, $now + 720);
same(array('alert: ZFS pool tank is DEGRADED'), $sent(), 'an alert the notify script refused is sent by the next run');
file_put_contents($status_file, $healthy);
putenv('ZFSM_TEST_NOTIFY_FAIL=1');
$run($cfg, $now + 780);
putenv('ZFSM_TEST_NOTIFY_FAIL');
$run($cfg, $now + 840);
same(array('normal: Resolved: ZFS pool tank is DEGRADED'), $sent(), 'and so is a recovery notice');

// A pool Unraid lists, encrypted here, that ZFS hasn't imported: due once Unraid has mounted everything and 10 minutes have passed since boot.
$disks('tank=zfs', 'secure=luks:zfs');
$t = $now + 10000;
$boot = $t - 60;
$run($cfg, $t);
$run($cfg, $t + ZFSM_ALERT_GRACE - 61);
same(array(), $sent(), 'no missing-pool alert while pools may still be importing after boot');
$run($cfg, $t + ZFSM_ALERT_GRACE - 60);
same(array('alert: ZFS pool secure is not imported'), $sent(), 'still missing 10 minutes after boot');
rename($tmp.'/disks.ini', $tmp.'/disks.ini.away');
$run($cfg, $t + ZFSM_ALERT_GRACE);
same(array(), $sent(), 'an unreadable disks.ini does not call the missing pool resolved');
rename($tmp.'/disks.ini.away', $tmp.'/disks.ini');

// Stopping and starting the array: the missing pool stays reported through the new grace, with no false resolve.
$array('STOPPED');
$run($cfg, $t + 1000);
$array('STARTED');
$run($cfg, $t + 1100);
same(array(), $sent(), 'no resolve while the grace holds the check back');
$run($cfg, $t + 1000 + ZFSM_ALERT_GRACE);
same(array(), $sent(), 'and no second alert after it');
$disks('tank=zfs');
$run($cfg, $t + 1700);
same(array('normal: Resolved: ZFS pool secure is not imported'), $sent(), 'resolved when it no longer is missing');

// Restarting the array long after boot: pools importing after the start get the same 10 minutes.
$disks('tank=zfs', 'secure=luks:zfs');
$array('STOPPED');
$run($cfg, $t + 3000);
$array('STARTED');
$run($cfg, $t + 3060);
same(array(), $sent(), 'a pool still importing a minute after the array starts is not called missing');
file_put_contents($status_file, $healthy."\n".str_replace('tank', 'secure', $healthy));
$run($cfg, $t + 3120);
same(array(), $sent(), 'and nothing once it has imported');
file_put_contents($status_file, $healthy);

// While Unraid is still mounting, no missing-pool check, however long after boot.
$disks('tank=zfs', 'secure=luks:zfs');
$array('STARTED', 'Starting');
$run($cfg, $t + 5000);
same(array(), $sent(), 'not while Unraid is still mounting');
$array('STARTED');
$run($cfg, $t + 5060);
same(array('alert: ZFS pool secure is not imported'), $sent(), 'once it has mounted everything');

// The first check on a long schedule such as Daily reports a pool missing since boot, not a day later.
unlink($GLOBALS['zfsm_alert_state']);
$run($cfg, $t + 86400);
same(array('alert: ZFS pool secure is not imported'), $sent(), 'reported at the first check after boot');
$disks('tank=zfs');
$run($cfg, $t + 86460);
same(array('normal: Resolved: ZFS pool secure is not imported'), $sent(), 'resolved');

// Stale snapshots, only when that setting is on too.
$boot = $now - 3600;
$snapshots = $tmp.'/snapshots.txt';
putenv('ZFSM_TEST_SNAPSHOT_LIST='.$snapshots);
file_put_contents($snapshots, $listing);
$run($cfg, $now);
same(array(), $sent(), 'stale snapshot alerts stay off unless turned on');
$cfg['alert_stale'] = 1;
$cfg['dataset_exclusion'] = 'docker';
$run($cfg, $now);
same(array('warning: Snapshots are out of date'), $sent(), 'stale snapshots warn');
$run($cfg, $now + 60);
same(array(), $sent(), 'once');
file_put_contents($snapshots, $listing."tank/e@old\t".($now - 60 * $day)."\n");
$run($cfg, $now + 120);
same(array('warning: Snapshots are out of date'), $sent(), 'again when another dataset goes stale');
putenv('ZFSM_TEST_SNAPSHOT_LIST_FAIL=1');
$run($cfg, $now + 180);
same(array('warning: ZFS Master NG could not read snapshots'), $sent(), 'a failed snapshot list warns, without calling the snapshots up to date');
putenv('ZFSM_TEST_SNAPSHOT_LIST_FAIL');
$run($cfg, $now + 240);
same(array('normal: Resolved: ZFS Master NG could not read snapshots'), $sent(), 'reading again resolves only the read problem');
file_put_contents($snapshots, "tank/b@new\t".($now - $day)."\n");
$run($cfg, $now + 300);
same(array('normal: Resolved: Snapshots are out of date'), $sent(), 'resolved');

// Two runs never overlap.
$held = fopen($GLOBALS['zfsm_alert_state'].'.lock', 'c');
flock($held, LOCK_EX);
file_put_contents($status_file, $degraded);
$run($cfg, $now + 360);
same(array(), $sent(), 'a run that finds another still going does nothing');
flock($held, LOCK_UN);
$run($cfg, $now + 420);
same(array('alert: ZFS pool tank is DEGRADED'), $sent(), 'the next run catches up');

echo "alerts: pool health, missing pools, stale snapshots and their notices passed\n";
