<?php
// Pool and snapshot alerts through the real runProcess, with fake zpool, zfs and notify.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$bin_dir = getenv('ZFSM_TEST_BIN_DIR');
if (!is_string($bin_dir) || !is_executable($bin_dir.'/zpool')) {
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold the fake zpool\n");
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

same(array('cache', 'disk1'), zfsmExpectedPools(array('cache' => array('name' => 'cache', 'fsType' => 'zfs'), 'cache2' => array('name' => 'cache2', 'fsType' => ''), 'disk1' => array('name' => 'disk1', 'fsType' => 'zfs'), 'disk2' => array('name' => 'disk2', 'fsType' => 'xfs'))), 'ZFS pools and ZFS array disks, not pool members or other filesystems');
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

// The real check, run as cron runs it.
$tmp = sys_get_temp_dir().'/zfsm-alerts';
@mkdir($tmp);
$GLOBALS['zfsm_emhttp'] = $tmp;
$GLOBALS['zfsm_alert_state'] = $tmp.'/state.json';
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
$array = function($state) use ($tmp) { file_put_contents($tmp.'/var.ini', "mdState=\"$state\"\n"); };
$sent = function() use ($log) {
	$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : array();
	@unlink($log);
	return $lines;
};
file_put_contents($tmp.'/disks.ini', "[\"tank\"]\nname=\"tank\"\nfsType=\"zfs\"\n");
file_put_contents($status_file, $degraded);

$array('STARTED');
zfsmRunAlerts(array('alert_interval' => 0) + $cfg, $now);
same(array(), $sent(), 'a job left after alerts were turned off sends nothing');
same(false, is_file($GLOBALS['zfsm_alert_state']), 'and reads nothing');

$array('STOPPED');
zfsmRunAlerts($cfg, $now);
same(array(), $sent(), 'nothing until the array has started');

$array('STARTED');
zfsmRunAlerts($cfg, $now);
same(array('alert: ZFS pool tank is DEGRADED'), $sent(), 'a degraded pool alerts');
zfsmRunAlerts($cfg, $now + 60);
same(array(), $sent(), 'once, not every run');
file_put_contents($status_file, $errors);
zfsmRunAlerts($cfg, $now + 120);
same(array('alert: ZFS pool tank has errors'), $sent(), 'a different problem alerts again');
file_put_contents($status_file, str_replace('12', '40', $errors));
zfsmRunAlerts($cfg, $now + 180);
same(array(), $sent(), 'rising counts on the same problem do not');
file_put_contents($status_file, $healthy);
zfsmRunAlerts($cfg, $now + 240);
same(array('normal: Resolved: ZFS pool tank has errors'), $sent(), 'recovery sends a normal notice');

// A pool Unraid lists but ZFS hasn't imported: the grace starts when the check first sees the array started.
file_put_contents($tmp.'/disks.ini', "[\"tank\"]\nname=\"tank\"\nfsType=\"zfs\"\n[\"cache2\"]\nname=\"cache2\"\nfsType=\"zfs\"\n");
$array('STOPPED');
zfsmRunAlerts($cfg, $now + 300);
$array('STARTED');
zfsmRunAlerts($cfg, $now + 400);
zfsmRunAlerts($cfg, $now + 400 + ZFSM_ALERT_GRACE - 1);
same(array(), $sent(), 'no missing-pool alert while pools may still be importing');
zfsmRunAlerts($cfg, $now + 400 + ZFSM_ALERT_GRACE);
same(array('alert: ZFS pool cache2 is not imported'), $sent(), 'still missing after the grace');
file_put_contents($tmp.'/disks.ini', "[\"tank\"]\nname=\"tank\"\nfsType=\"zfs\"\n");
zfsmRunAlerts($cfg, $now + 1100);
same(array('normal: Resolved: ZFS pool cache2 is not imported'), $sent(), 'and resolved');

// A failed or hung read is a warning, not "all healthy".
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL=1');
zfsmRunAlerts($cfg, $now + 1200);
same(array('warning: ZFS Master NG could not read pool health'), $sent(), 'a failed zpool status');
zfsmRunAlerts($cfg, $now + 1300);
same(array(), $sent(), 'once');
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL');
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
$GLOBALS['zfsm_alert_read_seconds'] = 0.3;
$started = microtime(true);
zfsmRunAlerts($cfg, $now + 1400);
same(true, microtime(true) - $started < 4, 'a hung zpool is stopped at the read limit');
same(array(), $sent(), 'and stays the same read problem');
same('zpool timed out', json_decode(file_get_contents($GLOBALS['zfsm_alert_state']), true)['problems']['read:pools']['detail'], 'naming the command');
unset($GLOBALS['zfsm_alert_read_seconds']);
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
zfsmRunAlerts($cfg, $now + 1500);
same(array('normal: Resolved: ZFS Master NG could not read pool health'), $sent(), 'reading again resolves it');

// Stale snapshots, only when that setting is on too.
$snapshots = $tmp.'/snapshots.txt';
putenv('ZFSM_TEST_SNAPSHOT_LIST='.$snapshots);
file_put_contents($snapshots, $listing);
zfsmRunAlerts($cfg, $now);
same(array(), $sent(), 'stale snapshot alerts stay off unless turned on');
$cfg['alert_stale'] = 1;
$cfg['dataset_exclusion'] = 'docker';
zfsmRunAlerts($cfg, $now);
same(array('warning: Snapshots are out of date'), $sent(), 'stale snapshots warn');
zfsmRunAlerts($cfg, $now + 60);
same(array(), $sent(), 'once');
file_put_contents($snapshots, $listing."tank/e@old\t".($now - 60 * $day)."\n");
zfsmRunAlerts($cfg, $now + 120);
same(array('warning: Snapshots are out of date'), $sent(), 'again when another dataset goes stale');
file_put_contents($snapshots, "tank/b@new\t".($now - $day)."\n");
zfsmRunAlerts($cfg, $now + 180);
same(array('normal: Resolved: Snapshots are out of date'), $sent(), 'resolved');
putenv('ZFSM_TEST_SNAPSHOT_LIST_FAIL=1');
zfsmRunAlerts($cfg, $now + 240);
same(array('warning: ZFS Master NG could not read snapshots'), $sent(), 'a failed snapshot list');
putenv('ZFSM_TEST_SNAPSHOT_LIST_FAIL');

// Two runs never overlap.
$held = fopen($GLOBALS['zfsm_alert_state'].'.lock', 'c');
flock($held, LOCK_EX);
file_put_contents($status_file, $degraded);
zfsmRunAlerts($cfg, $now + 300);
same(array(), $sent(), 'a run that finds another still going does nothing');
flock($held, LOCK_UN);
zfsmRunAlerts($cfg, $now + 360);
same(array('alert: ZFS pool tank is DEGRADED', 'normal: Resolved: ZFS Master NG could not read snapshots'), $sent(), 'the next run catches up');

echo "alerts: pool health, missing pools, stale snapshots and their notices passed\n";
