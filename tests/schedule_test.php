<?php
// Scheduled replication through the real store, cron file, runs and scripts/schedule.php, with the fake zfs's small pool
// (ZFSM_TEST_REPL_DIR) and Unraid's crontab rebuild faked under $GLOBALS['zfsm_root'].

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

$GLOBALS['zfsm_feed'] = array();
function publish($channel, $message) {
	if ($GLOBALS['zfsm_publish_throws'] ?? false) {
		$GLOBALS['zfsm_publish_throws'] = false;
		throw new RuntimeException('nchan is down');
	}
	$GLOBALS['zfsm_feed'][] = json_decode($message, true)['op'];
}

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-schedule-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/pool', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
putenv('ZFSM_TEST_REPL_DIR='.$work.'/pool');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
putenv('ZFSM_TEST_CALL_LOG='.$work.'/calls.log');
mkdir($work.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $work.'/docroot/plugins/dynamix/scripts/notify');
chmod($work.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $work.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$work.'/notify.log');
putenv('ZFSM_TEST_NOTIFY_DETAIL_LOG='.$work.'/notify-detail.log');
$root = $work.'/root';
$GLOBALS['zfsm_root'] = $root;
mkdir($root.'/boot/config/plugins/zfs.master', 0700, true);
mkdir($root.'/usr/local/sbin', 0700, true);
mkdir($root.'/var/log', 0700, true);
// Unraid's crontab rebuild, which begins and tests as Unraid's does (no "!", bash's [[): logs each run, and fails while cron-fail is there.
file_put_contents($root.'/usr/local/sbin/update_cron', "#/bin/bash\n[[ -d / ]] && echo ran >> ".escapeshellarg($work.'/cron.log')."\n[ ! -e ".escapeshellarg($work.'/cron-fail')." ] || { echo 'crontab: cannot write' >&2; exit 1; }\n");
chmod($root.'/usr/local/sbin/update_cron', 0755);

$pool = function($file, $lines) use ($work) { file_put_contents($work.'/pool/'.$file, $lines ? implode("\n", $lines)."\n" : ''); };
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$names = function($dataset, $types) { return array_column(zfsmReplicaMarks($dataset, $types) ?? array(), 'name'); };
$sends = function() use ($read) { return array_values(array_filter($read('calls.log'), function($line) { return strpos($line, 'send ') === 0 && strpos($line, ' -n ') === false; })); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'calls.log', 'cron.log') as $name) @unlink($work.'/'.$name); $GLOBALS['zfsm_feed'] = array(); };
// A copy that someone destroyed: gone from the pool with its snapshots.
$drop = function($dataset) use ($work) {
	foreach (array('datasets', 'marks') as $file) {
		$kept = array_filter(file($work.'/pool/'.$file, FILE_IGNORE_NEW_LINES), function($line) use ($dataset) { return preg_match('~^'.preg_quote($dataset, '~').'[@\t]~', $line) !== 1; });
		file_put_contents($work.'/pool/'.$file, $kept ? implode("\n", $kept)."\n" : '');
	}
};
require_once __DIR__.'/fixtures/schedules_stored.php';
$stored = 'zfsmTestStored';
$cron = function() { return is_file(zfsmScheduleCronFile()) ? file_get_contents(zfsmScheduleCronFile()) : null; };
// An add starts its first run as Replicate does, from the plugin in ZFSM_TEST_DOCROOT; it answers once that run is done.
$add = function(...$args) use ($work) {
	$GLOBALS['docroot'] = getenv('ZFSM_TEST_DOCROOT');
	$GLOBALS['zfsm_php'] = PHP_BINARY;
	$answer = zfsmScheduleAdd(...$args);
	$lock = zfsmJobLockFile('tank/data', 'replicate');
	for ($waited = 0; $waited < 100 && zfsmJobRunning($lock); $waited++) usleep(50000);
	same(false, zfsmJobRunning($lock), 'the background run finishes, letting go of its lock');
	$GLOBALS['docroot'] = $work.'/docroot';
	return $answer;
};
$cfg = array('snap_prefix' => 'repl-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$script = '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php';
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "backup\tfilesystem"));
$pool('marks', array("tank/data@a\t11\t10", "tank/data@b\t12\t20"));
$pool('props', array());
$pool('holds', array());

// Each schedule is one cron line at its minute, naming only its id: every hour, or at an hour on its days (all seven: every day).
$hourly = array('id' => 'ffff0000', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'hourly', 'minute' => 15, 'hour' => 0, 'days' => range(0, 6));
$daily = array('id' => '0a1b2c3d', 'every' => 'days', 'minute' => 7, 'hour' => 3) + $hourly;
$weekly = array('id' => '00000000', 'every' => 'days', 'minute' => 0, 'hour' => 23, 'days' => array(2)) + $hourly;
$some = array('id' => '12345678', 'every' => 'days', 'minute' => 30, 'hour' => 13, 'days' => array(0, 1, 3, 5)) + $hourly;
same("# Generated ZFS Master NG schedules:\n15 * * * * $script run ffff0000 &> /dev/null\n7 3 * * * $script run 0a1b2c3d &> /dev/null\n0 23 * * 2 $script run 00000000 &> /dev/null\n30 13 * * 0,1,3,5 $script run 12345678 &> /dev/null\n\n",
	zfsmScheduleCronText(array($hourly, $daily, $weekly, $some)), 'hourly, and at an hour every day, on one day and on some days');
same('', zfsmScheduleCronText(array()), 'no schedules, no cron file');
same(array('every hour at :15', 'every day at 03:07', 'every Tuesday at 23:00', 'every Monday, Wednesday, Friday and Sunday at 13:30'), array_map('zfsmScheduleWhen', array($hourly, $daily, $weekly, $some)), 'each says when it runs, Monday first');
same(array(true, true, false, false, false, false, false, false, false, false, false, false, false, false, false), array_map('zfsmScheduleValid', array($hourly, array('server' => 'nas', 'dest' => 'data') + $hourly,
	array('server' => 'nas', 'dest' => 'a/b') + $hourly, array('dest' => 'data') + $hourly, array('every' => 'minutely') + $hourly, array('every' => 'weekly') + $weekly, array('hour' => 24) + $daily, array('hour' => '3') + $daily,
	array('minute' => 60) + $daily, array('days' => array()) + $daily, array('days' => array(3, 1)) + $daily, array('days' => array(1, 1)) + $daily, array('days' => array('1')) + $daily, array('days' => array(7)) + $daily, array('days' => array('x' => 1)) + $daily)),
	'a copy here is a dataset in a pool, one on a server a name in its area; nothing runs more often than hourly; a time is numbers, and days 0 to 6, in order, each once');
$snapshots_job = array('dataset' => '', 'dest' => '') + $hourly;
$under_job = array('dest' => 'backup/') + $hourly;
same(array(true, true, true, false, false, false, false), array_map('zfsmScheduleValid', array(array('decrypted' => true) + $hourly, $snapshots_job, $under_job,
	array('decrypted' => false) + $hourly, array('decrypted' => '1') + $hourly, array('decrypted' => true) + $snapshots_job, array('decrypted' => true) + $under_job)), 'only a copy\'s schedule saves decrypted, and only as true');

// One saved before start times and days of the week reads as one: its minute from its id, a daily one on each day and a weekly one
// on its day, with its cron line as before; the next change saves it so, and keeps one it can't use as it was.
$before = function($job, $old) { return $old + array_diff_key($job, array('minute' => 0, 'days' => 0)); };
$old = array($before($hourly, array('day' => 0)), $before($daily, array('every' => 'daily', 'day' => 0, 'keep' => 2)), $before($weekly, array('every' => 'weekly', 'day' => 2)),
	$before($weekly, array('id' => '00000001', 'every' => 'weekly', 'day' => 7)), $before($daily, array('id' => '00000002', 'every' => 'daily', 'day' => 9)),
	$before($daily, array('id' => '00000003', 'every' => 'daily')), $before($hourly, array('id' => '00000004', 'day' => 'x')));
$upgraded = zfsmScheduleRead(json_encode($old));
same(array(array('every hour at :15', 'every day at 03:07', 'every Tuesday at 23:00'), array(0, 2, 0), array_slice($old, 3), zfsmScheduleCronText(array($hourly, $daily, $weekly))),
	array(array_values(array_map('zfsmScheduleWhen', $upgraded['valid'])), array_values(array_column($upgraded['valid'], 'keep')), $upgraded['other'], zfsmScheduleCronText($upgraded['valid'])),
	'an hourly, a daily and a weekly one read with their old times; one with no day 0 to 6, which that version could not use either, is no schedule');
file_put_contents(zfsmScheduleFile(), json_encode($old));
same(array('saved' => true, 'why' => ''), zfsmScheduleChange(function($jobs) { return $jobs; }), 'a change');
same(array(array(15, 'hourly', range(0, 6)), array(7, 'days', range(0, 6)), array(0, 'days', array(2)), $old[3], $old[4], $old[5], $old[6]),
	array_map(function($job) { return isset($job['days']) ? array($job['minute'], $job['every'], $job['days']) : $job; }, $stored()), 'saves each in the new shape');
unlink(zfsmScheduleFile());
unlink(zfsmScheduleCronFile());

// An hourly one's minute is one no other schedule of its dataset starts at; others may share it.
$taken = array();
foreach (range(0, 58) as $minute) $taken[] = array('minute' => $minute) + $daily;
foreach (range(1, 20) as $try) same(59, zfsmScheduleFreeMinute($taken, $hourly), 'the one minute left for tank/data');
same(true, count(array_unique(array_map(function() use ($taken, $hourly) { return zfsmScheduleFreeMinute($taken, array('dataset' => 'tank/other') + $hourly); }, range(1, 40)))) > 1, 'any minute for another dataset');
// Two of one dataset that start in one minute wait on one lock and can get one snapshot name, so Repeat refuses the second.
same(array(true, true, false, false, true, false), array(zfsmScheduleSameTime($hourly, array('minute' => 15) + $daily), zfsmScheduleSameTime($hourly, $hourly),
	zfsmScheduleSameTime($hourly, array('minute' => 16) + $hourly), zfsmScheduleSameTime($daily, array('hour' => 15) + $daily), zfsmScheduleSameTime($weekly, array('minute' => 0, 'hour' => 23) + $daily),
	zfsmScheduleSameTime($weekly, array('minute' => 0, 'hour' => 23) + $some)), 'every hour meets any one at its minute; two at one time meet on a day both run, not at another hour or on no shared day');

// Cron's next run after a time, here in UTC, and the snapshot names it gives against Repeat's own run and the run after it.
$at = function($text) { return (new DateTime($text, new DateTimeZone('UTC')))->getTimestamp(); };
date_default_timezone_set('UTC');
same(array($at('2026-11-15 10:15'), $at('2026-11-15 11:15'), $at('2026-11-16 03:07'), $at('2026-11-17 23:00'), $at('2026-11-24 23:00'), $at('2026-11-15 13:30'), $at('2026-11-18 13:30')),
	array(zfsmScheduleNext($hourly, $at('2026-11-15 10:05')), zfsmScheduleNext($hourly, $at('2026-11-15 10:15')), zfsmScheduleNext($daily, $at('2026-11-15 10:05')), zfsmScheduleNext($weekly, $at('2026-11-15 10:05')), zfsmScheduleNext($weekly, $at('2026-11-17 23:00')),
		zfsmScheduleNext($some, $at('2026-11-15 10:05')), zfsmScheduleNext($some, $at('2026-11-16 13:30'))),
	'the next minute it names, past the hour and on a day it names');
same(array($at('2026-11-15 10:15'), $at('2026-11-16 03:07'), $at('2026-11-17 03:07'), $at('2026-11-17 23:00'), $at('2026-11-24 23:00'), $at('2026-11-15 13:30'), $at('2026-11-16 13:30'), $at('2026-11-18 13:30'), $at('2026-11-20 13:30')),
	array_merge(zfsmScheduleRuns($hourly, $at('2026-11-15 10:05'), $at('2026-11-15 11:15')), zfsmScheduleRuns($daily, $at('2026-11-15 10:05'), $at('2026-11-17 03:08')), zfsmScheduleRuns($weekly, $at('2026-11-15 10:05'), $at('2026-11-25 00:00')),
		zfsmScheduleRuns($some, $at('2026-11-15 10:05'), $at('2026-11-21 00:00'))),
	'its runs from one time to another');
$hours = array('snap_pattern' => 'Y-m-d-H') + $cfg;
$days = array('snap_pattern' => 'Y-m-d') + $cfg;
same(array('Snapshots Pattern would give the run at Sun 15 Nov 10:15 the same snapshot name as this run', '', 'Snapshots Pattern would give the run at Sun 15 Nov 12:15 the same snapshot name as the run at Sun 15 Nov 11:15',
		'Snapshots Pattern would give the run at Tue 24 Nov 23:00 the same snapshot name as the run at Tue 17 Nov 23:00', '', 'Snapshots Pattern would give the run at Sun 15 Nov 03:07 the same snapshot name as this run'),
	array(zfsmScheduleClash($hourly, array(), $hours, $at('2026-11-15 10:05'), true), zfsmScheduleClash($hourly, array(), $hours, $at('2026-11-15 10:20'), true), zfsmScheduleClash($hourly, array(), $days, $at('2026-11-15 10:20'), false),
		zfsmScheduleClash($weekly, array(), array('snap_pattern' => 'D-H') + $cfg, $at('2026-11-15 10:05'), true), zfsmScheduleClash($daily, array(), $days, $at('2026-11-15 10:05'), true), zfsmScheduleClash($daily, array(), $days, $at('2026-11-15 01:00'), true)),
	'an hourly one at :15 made at 10:05 names its 10:15 run as the first, made at 10:20 it does not; a day\'s name repeats each hour, a weekday\'s each week, and a daily one at 03:07 made at 01:00 runs again that day');
$other = array('id' => '001e0000', 'dest' => 'backup/two', 'minute' => 30, 'hour' => 5) + $daily;
same(array('Snapshots Pattern would give the run at Mon 16 Nov 03:07 the same snapshot name as the run at Mon 16 Nov 05:30 of the copy to backup/two', '', '', ''),
	array(zfsmScheduleClash($daily, array($daily, $other), $days, $at('2026-11-15 10:05'), false), zfsmScheduleClash($daily, array($daily, array('dataset' => 'tank/other') + $other), $days, $at('2026-11-15 10:05'), false),
		zfsmScheduleClash($daily, array($daily, $other), $hours, $at('2026-11-15 10:05'), false), zfsmScheduleClash($daily, array($daily), $days, $at('2026-11-15 10:05'), false)),
	'two daily ones of one dataset on a day\'s name; not of two datasets, with the hour in it, or on its own');
// Sydney's clock goes back at 16:00 UTC on 4 April 2026: the run cron starts again at 02:15 there leaves out what the first took
// (zfsmScheduleTakenBefore), so an hourly one made a week before clashes only where its pattern repeats a name in any case.
date_default_timezone_set('Australia/Sydney');
$local = array('snap_utc' => 0) + $cfg;
same(array('', 'Snapshots Pattern would give the run at Sun 29 Mar 12:15 the same snapshot name as the run at Sun 29 Mar 11:15'),
	array(zfsmScheduleClash($hourly, array(), $local, $at('2026-03-29 00:05'), false), zfsmScheduleClash($hourly, array(), array('snap_pattern' => 'Ymd') + $local, $at('2026-03-29 00:05'), false)),
	'an hourly one with the time in its names across the change, and one with the day only');
date_default_timezone_set('UTC');

// Repeat saves the schedule and runs it now: the first run is the dialog's own, so it may send in full.
$reset();
$answer = $add('tank/data', '', 'backup/data', '', 'hourly', '', '', 0, $cfg);
$jobs = $stored();
same(array(1, 'tank/data', 'backup/data', '', 'hourly', true, 0, range(0, 6), 0), array(count($jobs), $jobs[0]['dataset'], $jobs[0]['dest'], $jobs[0]['server'], $jobs[0]['every'], is_int($jobs[0]['minute']),
	$jobs[0]['hour'], $jobs[0]['days'], $jobs[0]['keep']), 'the schedule is saved, at a minute of its own');
$id = $jobs[0]['id'];
same(array('succeeded' => array('tank/data' => 'Replication to backup/data started; a notification says when it is done. It runs again '.zfsmScheduleWhen($jobs[0])), 'failed' => array()), $answer, 'and the answer says it started, and when it runs again');
same(array(zfsmScheduleCronText(array($jobs[0])), array('ran')), array($cron(), $read('cron.log')), 'its cron line is written and Unraid rebuilds its crontab');
same(1, preg_match('/^backup\/data@repl-[0-9]{14}$/D', $names('backup/data', 'snapshot')[0] ?? ''), 'the first run made the copy');
$taken = count($names('tank/data', 'snapshot'));

// Refused before anything runs: a second schedule for that copy, one that runs no copy, and one whose snapshot names would repeat.
$reset();
$every = '0,1,2,3,4,5,6';
same(array('tank/data' => 'Nothing was scheduled: this copy already has a schedule ('.zfsmScheduleWhen($jobs[0]).'); remove it in Settings first'), zfsmScheduleAdd('tank/data', '', 'backup/data', '', 'days', '04:00', $every, 0, $cfg)['failed'], 'a second schedule for one copy');
same(array('tank/data' => 'Nothing was scheduled: this copy already has a schedule ('.zfsmScheduleWhen($jobs[0]).'); remove it in Settings first'), zfsmScheduleAdd('tank/data', '', 'backup/data', '', 'days', '04:00', $every, 0, $days)['failed'], 'which says so even when their snapshot names would clash too');
$no_time = 'set the time it starts at';
$no_day = 'tick at least one day';
$no_repeat = 'Repeat has no such choice now; reload the page and pick again';
foreach (array(array('', '1', $no_time), array('24:00', '1', $no_time), array('4:00', '1', $no_time), array('04:60', '1', $no_time), array('', '', $no_time.'; '.$no_day),
	array('04:00', '', $no_day), array('04:00', '7', $no_day), array('04:00', '1,,2', $no_day)) as list($time, $on, $why)) {
	same(array('tank/data' => 'Nothing was scheduled: '.$why), zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'days', $time, $on, 0, $cfg)['failed'], 'one at "'.$time.'" on days "'.$on.'" says what to fix');
}
same(array(array('tank/data' => 'Nothing was scheduled: '.$no_repeat), array('tank/data' => 'Nothing was scheduled: '.$no_repeat)),
	array(zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'minutely', '', '', 0, $cfg)['failed'], zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'daily', '', '', 0, $cfg)['failed']), 'one more often than hourly, and one from a page opened before the update');
same(array('tank/data' => 'Nothing was scheduled: give the copy a name as Replicate does'), zfsmScheduleAdd('tank/data', '', 'backup', '', 'days', '04:00', '1', 0, $cfg)['failed'], 'one here whose copy is no dataset in a pool');
same(array('tank/data' => 'Nothing was scheduled: '.$no_day.'; under Snapshots to keep, give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep all'), zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'days', '04:00', '', -1, $cfg)['failed'],
	'one with no day ticked and no number to keep says both');
same(array(array(null, 0, 0, 0, '30d 12m', 5, -1), array('', 'all', 'all', '5', '30d 12m')),
	array(array_map('zfsmCopyKeepsParse', array(' ', ' ALL ', 'all', '0', ' 12M 30d ', '5', 'x')), array_map('zfsmCopyKeepsField', array(null, 0, '0', 5, '30d 12m'))),
	'Copy Keeps: nothing, all (0 too), rules, and what it can\'t read; shown back as given');
same(array(array('tank/data' => 'Nothing was scheduled: leave Copy Keeps empty: what a copy on another server keeps, its Keep Replicas decides'),
	array('tank/data' => 'Nothing was scheduled: under Copy Keeps, give all to keep every snapshot, or give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep what its source keeps')),
	array(zfsmScheduleAdd('tank/data', '', 'copy', 'nas', 'hourly', '', '', 0, $cfg, 0)['failed'], zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'hourly', '', '', 0, $cfg, -1)['failed']),
	'Copy Keeps on a copy on another server, and one it can\'t read');
$listed = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), json_encode(array(array('minute' => 0) + $jobs[0])));
same(array('tank/data' => "Nothing was scheduled: The copy goes inside nowhere, which doesn't exist"), zfsmScheduleAdd('tank/data', '', 'nowhere/data', '', 'hourly', '', '', 0, $cfg)['failed'],
	'an hourly one beside one of the dataset at :00 takes a minute of its own, so only its first run stops it');
file_put_contents(zfsmScheduleFile(), $listed);
same(array('tank/data' => 'Nothing was scheduled: the copy to backup/data ('.zfsmScheduleWhen($jobs[0]).') starts then too; pick another time'),
	zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'days', sprintf('05:%02d', $jobs[0]['minute']), '3', 0, $cfg)['failed'], 'one that starts in the minute of another of the dataset');
same(1, preg_match('/^Nothing was scheduled: Snapshots Pattern would give the run at .+ the same snapshot name as .+; add the time to it in Settings$/D', zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'hourly', '', '', 0, array('snap_pattern' => 'Ymd') + $cfg)['failed']['tank/data'] ?? ''), 'an hourly one whose pattern has no hour');
// Twelve hours off: the hourly schedule's run in that hour is the only snapshot it could share a name with.
same(1, preg_match('/^Nothing was scheduled: Snapshots Pattern would give the run at .+ the same snapshot name as the run at .+ of the copy to backup\/data; add the time to it in Settings$/D',
	zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'days', sprintf('%02d:%02d', ((int)date('G') + 12) % 24, ($jobs[0]['minute'] + 30) % 60), $every, 0, $hours)['failed']['tank/data'] ?? ''), 'a daily one whose names an hourly one of the dataset takes too');
$untaken = 'Snapshots Pattern and Prefix in Settings give new snapshots a name ZFS refuses: a name may hold only letters, digits, spaces and _ . : -, and with its dataset and @ be up to 255 long';
same(array(array('tank/data' => 'Nothing was scheduled: '.$untaken), $listed), array(zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'hourly', '', '', 0, array('snap_pattern' => 'Y/m/d-His') + $cfg)['failed'],
	file_get_contents(zfsmScheduleFile())), 'one whose new snapshots ZFS refuses the names of: none saved, no run started');
// Azores' T is -01 in winter time and +00 in summer time, which ZFS refuses: refused in both, in winter time (late October to late March) by the half-year look only.
$zone = date_default_timezone_get();
date_default_timezone_set('Atlantic/Azores');
same(array('tank/data' => 'Nothing was scheduled: '.$untaken), zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'hourly', '', '', 0, array('snap_pattern' => 'Y-m-d-His-T', 'snap_utc' => 0) + $cfg)['failed'],
	'and one whose names ZFS refuses in summer time only');
date_default_timezone_set($zone);
same(array('tank/data' => 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now'), zfsmScheduleAdd('tank/data', 'b', 'backup/two', '', 'days', '03:00', '1', 0, $cfg)['failed'], 'one with a snapshot picked');
same(array('tank/data' => 'Nothing was scheduled: '.$no_repeat), zfsmScheduleAdd('tank/data', 'b', 'backup/two', '', 'often', '03:00', '1', 0, $cfg)['failed'], 'and one that repeats no way it knows, with a snapshot picked too');
same(array('tank/data' => "Nothing was scheduled: The copy goes inside nowhere, which doesn't exist"), zfsmScheduleAdd('tank/data', '', 'nowhere/data', '', 'days', sprintf('03:%02d', ($jobs[0]['minute'] + 30) % 60), '2', 0, $cfg)['failed'],
	'one whose first run can\'t start says why');
same(array($jobs, array(), array(), $taken), array($stored(), $read('cron.log'), $sends(), count($names('tank/data', 'snapshot'))), 'and none of them saves, runs or takes a snapshot');

// Cron's runs take a snapshot and send the changes; one that works sends no notification.
$reset();
same(0, zfsmScheduleRun($id, $cfg, time() + 3600), 'a scheduled run');
same(1, preg_match('/^send -P -v -L -i tank\/data@repl-[0-9]{14} tank\/data@repl-[0-9]{14}$/D', implode("\n", $sends())), 'sends only the changes: '.implode(' | ', $sends()));
same(array($taken + 1, 2, array()), array(count($names('tank/data', 'snapshot')), count($names('backup/data', 'snapshot')), $read('notify-detail.log')), 'each side keeps the new snapshot, and nothing is notified');
// Sydney's clock goes back at 16:00 UTC on 4 April 2026, so it shows 02:30:01 twice: before the change a taken name is a problem
// as ever; after it, cron's run then does nothing, its dataset holding the name, and one a second later sends.
$zone = date_default_timezone_get();
date_default_timezone_set('Australia/Sydney');
$reset();
same(array(0, 1, array('warning: ZFS Replication')), array(zfsmScheduleRun($id, $local, 1775316601), zfsmScheduleRun($id, $local, 1775316601), $read('notify.log')),
	'the run at 02:30:01, and one then again: its name taken, a problem as ever');
$count = count($names('tank/data', 'snapshot'));
$reset();
same(array(0, $count, array(), array(), 1), array(zfsmScheduleRun($id, $local, 1775320201), count($names('tank/data', 'snapshot')), $sends(), $read('notify-detail.log'),
	preg_match('/ Schedule '.$id.' not started: its dataset has a snapshot of the name this run gives, from before the clock went back$/m', (string)file_get_contents(zfsmLogFile()))), 'the one cron starts at 02:30:01 after the change takes no snapshot, sends nothing, notifies nothing, and logs why');
$reset();
same(array(0, 1), array(zfsmScheduleRun($id, $local, 1775320202), count($sends())), 'one cron starts a second later sends');
date_default_timezone_set($zone);
// tank/data keeps the two these took.
$taken += 2;

// With the copy gone, a run doesn't send it in full again: it notifies once, then logs, and Settings counts the runs.
$reset();
$drop('backup/data');
same(1, zfsmScheduleRun($id, $cfg, time() + 7200), 'a run whose copy is gone');
$gone = 'warning: Replication of tank/data to backup/data not started: backup/data is gone, though this dataset made a copy there before; a scheduled run sends only changes, so replicate it once by hand to make a new copy';
same(array($gone.'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'), $read('notify-detail.log'), 'says why, once');
same(array(array(), $taken + 1), array($sends(), count($names('tank/data', 'snapshot'))), 'sends nothing and removes the snapshot it took');
same(1, zfsmScheduleRun($id, $cfg, time() + 10800), 'the next one fails too');
same(array(1, 2), array(count($read('notify-detail.log')), zfsmScheduleFailures($id)), 'without another notification; the problems are counted');
same(true, strpos(zfsmSchedulesHtml($cfg), '2 run(s) in a row with a problem') !== false, 'and Settings shows the count: '.zfsmSchedulesHtml($cfg));
same(array(false, 1), array(strpos(zfsmSchedulesHtml($cfg), 'Snapshots Pattern'), preg_match('/Snapshots Pattern would give the run at [^<]+ the same snapshot name as the run at [^<]+, so that run fails: add the time to Snapshots Pattern/', zfsmSchedulesHtml($days))), 'and, with Snapshots Pattern changed since, that its runs\' names repeat');
// And with another schedule of the dataset whose runs share an hour's names with it, on both rows.
$listed = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), json_encode(array_merge(json_decode($listed, true), array(array('id' => '001e0000', 'dataset' => 'tank/data', 'dest' => 'backup/two', 'server' => '', 'every' => 'daily', 'hour' => 5, 'day' => 0)))));
same(array(1, 1), array(preg_match('/of the copy to backup\/two, so that run fails/', zfsmSchedulesHtml($hours)), preg_match('/of the copy to backup\/data, so that run fails/', zfsmSchedulesHtml($hours))), 'Settings shows a clash of two schedules on each');
file_put_contents(zfsmScheduleFile(), $listed);
same(1, preg_match('/Schedule '.$id.': 2 run\(s\) in a row with a problem$/m', (string)@file_get_contents(zfsmLogFile())), 'each problem is logged with its count');
// Replicated once by hand, the next scheduled run works and says so.
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a run by hand makes a new copy');
$reset();
same(0, zfsmScheduleRun($id, $cfg, time() + 14400), 'and the next scheduled run works');
same(1, preg_match('/^normal: Replication of tank\/data@repl-[0-9]{14} to backup\/data finished \(changes since b\)\. It works again after 2 run\(s\) with a problem\.$/D', $read('notify-detail.log')[0] ?? ''), 'its notification says it works again: '.implode(' | ', $read('notify-detail.log')));
same(array(0, false), array(zfsmScheduleFailures($id), strpos(zfsmSchedulesHtml($cfg), 'with a problem')), 'the count is cleared');
// A run that finds another replication of its dataset running waits for it, and starts once it is done.
$lock_file = zfsmJobLockFile('tank/data', 'replicate');
$holder = proc_open(array(PHP_BINARY, '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "held\n"; sleep(2);', $lock_file), array(1 => array('pipe', 'w')), $pipes);
same("held\n", fgets($pipes[1]), 'another run holds tank/data');
$reset();
$began = time();
same(0, zfsmScheduleRun($id, $cfg, time() + 15000, 20), 'a scheduled run meanwhile');
same(array(true, 1, array()), array(time() - $began >= 1, count($sends()), $read('notify-detail.log')), 'waited for it, then sent the changes, with nothing to notify');
proc_close($holder);
// One that waited in vain is a problem; a notification Unraid didn't take is sent by the next problem.
$held = zfsmJobLock($lock_file);
$reset();
putenv('ZFSM_TEST_NOTIFY_FAIL=1');
same(1, zfsmScheduleRun($id, $cfg, time() + 15500, 1), 'a run that waited a second for it');
putenv('ZFSM_TEST_NOTIFY_FAIL=0');
same(array(array(), array(1, false)), array($sends(), array_values(zfsmScheduleState($id))), 'sent nothing; counted, and the user not told yet');
same(1, zfsmScheduleRun($id, $cfg, time() + 15600, 1), 'and the next one');
$busy = 'warning: Replication of tank/data to backup/data not started: another replication of this dataset was still running after this run waited 1 minute(s) for it';
same(array($busy.'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'), $read('notify-detail.log'), 'which notifies, as the first notification was not sent');
zfsmScheduleRun($id, $cfg, time() + 15700, 1);
same(array(1, 3), array(count($read('notify-detail.log')), zfsmScheduleFailures($id)), 'and a third one only logs');
zfsmJobUnlock($held);
@unlink(zfsmScheduleStateFile($id));
// A dataset renamed or destroyed since: its run says so.
$kept = array_map(function($file) use ($work) { return file_get_contents($work.'/pool/'.$file); }, array('datasets' => 'datasets', 'marks' => 'marks'));
$drop('tank/data');
$reset();
same(1, zfsmScheduleRun($id, $cfg, time() + 15800), 'a run of a dataset that is gone');
same(array('warning: Replication of tank/data to backup/data not started: it no longer exists; remove its schedule in Settings. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'), $read('notify-detail.log'), 'says it is gone');
foreach ($kept as $file => $text) file_put_contents($work.'/pool/'.$file, $text);
@unlink(zfsmScheduleStateFile($id));
// One made Read Only since its repeat was saved: a run takes no snapshot in it and says why; Save refuses another, as for one mounted legacy.
$props = file_get_contents($work.'/pool/props');
file_put_contents($work.'/pool/props', "tank/data\treadonly\ton\n", FILE_APPEND);
$reset();
same(1, zfsmScheduleRun($id, $cfg, time() + 15850), 'a run of a dataset made Read Only');
same(array(array(), array('warning: Replication of tank/data to backup/data not started: tank/data is Read Only. Scheduled snapshots and copies skip it. Remove its schedule in Settings. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array(array_values(preg_grep('/^(snapshot|send) /', $read('calls.log'))), $read('notify-detail.log')), 'takes and sends nothing, and says why');
$before = $stored();
$refused = zfsmScheduleAdd('tank/data', '', 'backup/three', '', 'days', '04:00', '1', 0, $cfg)['failed'];
file_put_contents($work.'/pool/props', $props."tank/data\tmountpoint\tlegacy\n");
same(array(array('tank/data' => 'Nothing was scheduled: tank/data is Read Only. Scheduled snapshots and copies skip it'),
	array('tank/data' => 'Nothing was scheduled: tank/data is mounted legacy. Scheduled snapshots and copies skip it'), $before),
	array($refused, zfsmScheduleAdd('tank/data', '', 'backup/three', '', 'days', '04:00', '1', 0, $cfg)['failed'], $stored()), 'Save refuses both, and nothing is stored');
file_put_contents($work.'/pool/props', $props);
@unlink(zfsmScheduleStateFile($id));
// One whose dataset, or its snapshot names, could not be read: a run sends nothing and says why, and Save refuses. The pool goes back after.
$kept = array();
foreach (glob($work.'/pool/*') as $file) $kept[$file] = file_get_contents($file);
$before = $stored();
$unread = array();
foreach (array('ZFSM_TEST_COVER_FAIL', 'ZFSM_TEST_NAMES_FAIL') as $fail) {
	$reset();
	@unlink(zfsmScheduleStateFile($id));
	putenv($fail.'=1');
	$unread[] = array(zfsmScheduleRun($id, $cfg, time() + 15900), count($sends()), $read('notify-detail.log'), zfsmScheduleAdd('tank/data', '', 'backup/three', '', 'days', '04:00', '1', 0, $cfg)['failed']);
	putenv($fail);
}
$later = '. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.';
$names_unread = 'the snapshot names of tank/data could not be read, so it may be left to Sanoid or Syncoid';
same(array(array(1, 0, array('warning: Replication of tank/data to backup/data not started: tank/data could not be read: cannot iterate filesystems: Input/output error'.$later),
		array('tank/data' => 'Nothing was scheduled: tank/data could not be read: cannot iterate filesystems: Input/output error')),
	array(1, 0, array('warning: Replication of tank/data to backup/data not started: '.$names_unread.$later), array('tank/data' => 'Nothing was scheduled: '.$names_unread)), $before),
	array_merge($unread, array($stored())), 'a repeat whose dataset or snapshot names could not be read sends nothing and says why; Save refuses one');
foreach (glob($work.'/pool/*') as $file) if (!isset($kept[$file])) unlink($file);
foreach ($kept as $file => $text) file_put_contents($file, $text);
@unlink(zfsmScheduleStateFile($id));
// A run that finds the one before it stopped part-way carries that on, then sends its own snapshot.
$reset();
$at = time() + 16000;
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmScheduleRun($id, $cfg, $at), 'a scheduled run cut off part-way');
putenv('ZFSM_TEST_SEND_CUT');
list($stopped, $own) = array('tank/data@'.zfsmNewSnapshotName($cfg, $at), 'tank/data@'.zfsmNewSnapshotName($cfg, $at + 3600));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $at + 3600), 'the next run');
same(array('send -P -v -t tok:'.$stopped, 'send -P -v -L -i '.$stopped.' '.$own), $sends(), 'carries the stopped one on, then sends its own changes');
same(array('backup/data@'.zfsmNewSnapshotName($cfg, $at + 3600)), array_slice($names('backup/data', 'snapshot'), -1), 'so the copy holds this run\'s snapshot');
same(array('normal: Replication of '.$own.' to backup/data finished (changes since '.substr($stopped, strlen('tank/data@')).'). It works again after 1 run(s) with a problem.'), $read('notify-detail.log'), 'and says once, for its own snapshot, that it works again');
// Until its own snapshot lands too, the run is a problem, and nothing says it works again.
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmScheduleRun($id, $cfg, $at + 7200);
putenv('ZFSM_TEST_SEND_CUT');
$reset();
putenv('ZFSM_TEST_RECV_FAIL=changes');
same(1, zfsmScheduleRun($id, $cfg, $at + 10800), 'a run that carries the stopped one on, then can\'t send its own');
putenv('ZFSM_TEST_RECV_FAIL');
same(array(2, array(), 2), array(count($sends()), $read('notify-detail.log'), zfsmScheduleFailures($id)), 'sent both, notified nothing, and counted a second problem in a row');
@unlink(zfsmScheduleStateFile($id));
// An error that ends a run is a problem like any other, and the dataset is free for the next.
$reset();
$GLOBALS['zfsm_publish_throws'] = true;
same(1, zfsmScheduleRun($id, $cfg, $at + 14400), 'a run that an error ends');
$error = 'warning: Replication of tank/data to backup/data failed: nchan is down. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.';
same(array(array($error), 1, false), array($read('notify-detail.log'), zfsmScheduleFailures($id), zfsmJobRunning($lock_file)), 'says why, counts it and lets go of the lock');
@unlink(zfsmScheduleStateFile($id));

// Where the count can't be kept, each problem notifies, without saying later ones won't.
$state = zfsmJobDir('schedule');
rename($state, $state.'.away');
touch($state);
$reset();
$drop('backup/data');
zfsmScheduleRun($id, $cfg, time() + 18000);
zfsmScheduleRun($id, $cfg, time() + 21600);
same(array($gone, $gone), $read('notify-detail.log'), 'a count that can\'t be kept notifies each time');
unlink($state);
rename($state.'.away', $state);
zfsmRunReplicate('tank/data', 'b', 'backup/data');

// A cron line that outlived its schedule runs nothing; cron's script runs a schedule by its id.
$reset();
same(array(0, array(), array()), array(zfsmScheduleRun('abcdef01', $cfg, time()), $sends(), $read('notify-detail.log')), 'a schedule removed since runs nothing');
zfsmTestScratch('/boot/config');
@mkdir('/boot/config/plugins/zfs.master', 0700, true);
copy(zfsmScheduleFile(), '/boot/config/plugins/zfs.master/schedules.json');
register_shutdown_function(function() { @unlink('/boot/config/plugins/zfs.master/schedules.json'); });
$run = function($args) {
	exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(getenv('ZFSM_TEST_DOCROOT').'/plugins/zfs.master/scripts/schedule.php').' '.$args.' 2>&1', $said, $code);
	return array($code, implode("\n", $said));
};
$before = count($names('backup/data', 'snapshot'));
same(array(0, ''), $run('run '.$id), 'cron\'s script runs it');
same(array($before + 1, 1), array(count($names('backup/data', 'snapshot')), count($sends())), 'and the copy gets its new snapshot');
same(array(2, 'Usage: schedule.php run <schedule id> | schedule.php heal'), $run('run ../x'), 'anything but an id is refused');

// A list that can't be read is never written over; records the plugin can't use are kept as they are.
$saved = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), '{"id":"'.$id.'"}');
$unreadable = zfsmScheduleFile().' could not be read as a list of schedules; fix or remove it first';
same(array(array('tank/data' => 'Nothing was scheduled: '.$unreadable), $unreadable, '{"id":"'.$id.'"}'), array(zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'days', '01:00', $every, 0, $cfg)['failed'], zfsmScheduleRemove($id, $cfg)['why'], file_get_contents(zfsmScheduleFile())), 'an object is no list: nothing is added or removed');
same(true, strpos(zfsmSchedulesHtml($cfg), zfsmHtml($unreadable)) !== false, 'Settings says so');
$reset();
same(1, zfsmScheduleRun($id, $cfg, time()), 'and a run can\'t start');
same(array('warning: Scheduled run not started: '.zfsmSafeText($unreadable).'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'), $read('notify-detail.log'), 'which it notifies');
@unlink(zfsmScheduleStateFile($id));
file_put_contents(zfsmScheduleFile(), '[{"id":"zz"},'.substr($saved, 1));
same(true, strpos(zfsmSchedulesHtml($cfg), '1 record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use') !== false, 'a record it can\'t use is counted in Settings');

// A record whose Copy Keeps can't apply is no schedule; Settings says what a copy keeps.
$job = array_values(array_filter($stored(), function($job) use ($id) { return ($job['id'] ?? '') === $id; }))[0];
same(false, array_key_exists('copy_keeps', $job), 'a schedule added with no Copy Keeps saves none');
$kinds = zfsmScheduleRead(json_encode(array($job + array('copy_keeps' => '30d'), array('id' => '0badc0de', 'server' => 'nas', 'dest' => 'copy', 'copy_keeps' => 0) + $job,
	array('id' => '0badc0df', 'dest' => 'backup/three', 'copy_keeps' => -1) + $job, array('id' => '0badc0e0', 'dataset' => '', 'dest' => '', 'copy_keeps' => 0) + $job,
	array('id' => '0badc0e1', 'dataset' => 'tank', 'dest' => '/', 'every' => 'none', 'copy_keeps' => 0) + $job, array('id' => '0badc0e2', 'dataset' => 'tank', 'dest' => '/', 'every' => 'none') + $job)));
same(array(array($id => $job + array('copy_keeps' => '30d'), '0badc0e2' => array('id' => '0badc0e2', 'dataset' => 'tank', 'dest' => '/', 'server' => '', 'every' => 'none') + $job), 4), array($kinds['valid'], count($kinds['other'])),
	'kept on a copy here; a record with it on a server\'s copy, unread, with no copy, or on None is no schedule');
same(array('keeps all its snapshots; its copy keeps 30 daily', 'keeps the newest 2 of the snapshots of each dataset it copies; each copy keeps all its snapshots', 'keeps all its snapshots'),
	array(zfsmScheduleKeepText($job + array('copy_keeps' => '30d')), zfsmScheduleKeepText(array('dataset' => '', 'dest' => 'backup/', 'keep' => 2, 'copy_keeps' => 0) + $job), zfsmScheduleKeepText($job)),
	'Settings says what a copy keeps, and nothing of one with no Copy Keeps');

// Change Keep sets Keep and Copy Keeps in place: its id, time and cron line as they were.
$mine = function() use ($stored, $id) { return array_values(array_filter($stored(), function($job) use ($id) { return ($job['id'] ?? '') === $id; }))[0] ?? null; };
$cron_before = $cron();
$reset();
same(array('saved' => true, 'why' => ''), zfsmScheduleKeepChange($id, '7d', '30d 12m'), 'Change Keep saved');
same(array(array_merge($job, array('keep' => '7d', 'copy_keeps' => '30d 12m')), true, $cron_before, array()),
	array($mine(), in_array(array('id' => 'zz'), $stored(), true), $cron(), preg_grep('/^(snapshot|send) /', $read('calls.log'))),
	'only Keep and Copy Keeps change: its id, time and cron line stay, the record it can\'t use too, and no run starts');
same(true, strpos(zfsmSchedulesHtml($cfg), 'keeps 7 daily of its snapshots; its copy keeps 30 daily and 12 monthly') !== false, 'Settings says it');
same(array(1, 0), array(preg_match('~<details[^>]*><summary>Change Keep</summary> <label[^>]*>Snapshots To Keep: <input type="text" id="zfsm-keep-own-'.$id.'" maxlength="40" placeholder="all" value="7d"></label> '
	.'<label[^>]*>Copy Keeps: <input type="text" id="zfsm-keep-copy-'.$id.'" maxlength="40" placeholder="same as source" value="30d 12m"></label> <input type="button" value="Save" onclick="zfsmScheduleKeepSave\(\''.$id.'\'\)"></details>~', zfsmSchedulesHtml($cfg)),
	substr_count(zfsmScheduleKeepHtml(array('server' => 'nas', 'dest' => 'copy') + $mine()), 'zfsm-keep-copy-')), 'its row has Change Keep as saved; one on a server has no Copy Keeps');
same(array(array('saved' => true, 'why' => ''), $job), array(zfsmScheduleKeepChange($id, 0, null), $mine()), 'Copy Keeps emptied is saved as none');
$unchanged = $stored();
$snapshots = array('id' => '0badc0e1', 'dataset' => '', 'dest' => '', 'server' => '', 'every' => 'hourly', 'minute' => 5, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);
file_put_contents(zfsmScheduleFile(), json_encode(array_merge($unchanged, array($snapshots))));
same(1, substr_count(zfsmSchedulesHtml($cfg), '<summary>Change Keep</summary>'), 'Snapshot Every Dataset, which Settings sets, has no Change Keep');
same(array('No such repeat set in Replicate; it may have been removed', 'No such repeat set in Replicate; it may have been removed', 'No such schedule', 'Nothing was saved: under Snapshots to keep, '.zfsmKeepFix(),
	'Nothing was saved: under Copy Keeps, give all to keep every snapshot, or '.zfsmKeepFix('keep what its source keeps')),
	array(zfsmScheduleKeepChange('0badc0de', 1, null)['why'], zfsmScheduleKeepChange('0badc0e1', 1, null)['why'], zfsmScheduleKeepChange('../x', 1, null)['why'], zfsmScheduleKeepChange($id, -1, null)['why'],
		zfsmScheduleKeepChange($id, 1, -1)['why']),
	'Change Keep of no such schedule, of Snapshot Every Dataset (Settings sets it), of no id, and with what it can\'t read');
same(array_merge($unchanged, array($snapshots)), $stored(), 'none saved');
file_put_contents(zfsmScheduleFile(), json_encode($unchanged));

// Remove takes the schedule out of the list and the cron file, and Unraid rebuilds its crontab; Settings is refused while another change is saved.
$lock = zfsmJobLock(zfsmJobDir('schedule').'/settings.lock', false);
same(array('saved' => false, 'why' => 'Another change to the schedules is being saved; try again'), zfsmScheduleRemove($id, $cfg), 'a change while another is being saved');
zfsmJobUnlock($lock);
$reset();
same(array('saved' => true, 'why' => ''), zfsmScheduleRemove($id, $cfg), 'removed');
same(array(array(array('id' => 'zz')), null, array('ran')), array($stored(), $cron(), $read('cron.log')), 'the record it can\'t use stays; the cron file goes, and the crontab is rebuilt');
same(array('No such schedule; it may have been removed already', 'No such schedule'), array(zfsmScheduleRemove($id, $cfg)['why'], zfsmScheduleRemove('../x', $cfg)['why']), 'removing it again, or something that is no id');

// A crontab that can't be rebuilt: the schedule is saved and its first run started, and the answer says cron runs the ones it had.
$reset();
touch($work.'/cron-fail');
// Its own prefix: the test's first run took a snapshot named for this same second.
$answer = $add('tank/data', '', 'backup/data', '', 'days', '05:00', '6,1,6', 0, array('snap_prefix' => 'weekly-') + $cfg);
unlink($work.'/cron-fail');
same(array('succeeded' => array('tank/data' => 'Replication to backup/data started; a notification says when it is done'), 'failed' => array('tank/data' => 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (crontab: cannot write), so it runs the ones it had until the server restarts')),
	$answer, 'the answer says both');
same(array(2, array(1, 6), 'every Monday and Saturday at 05:00'), array(count($stored()), $stored()[0]['days'], zfsmScheduleWhen($stored()[0])), 'and the schedule is saved at its time, its days in order and once each, beside the record kept as it was');
// And one removed while it can't be rebuilt: removed all the same, with its count, and the answer says cron runs it until a restart.
$weekly_id = $stored()[0]['id'];
file_put_contents(zfsmScheduleStateFile($weekly_id), '{"failures":1}');
touch($work.'/cron-fail');
same(array('saved' => true, 'why' => 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (crontab: cannot write), so it runs the ones it had until the server restarts'), zfsmScheduleRemove($weekly_id, $cfg), 'a removal whose crontab can\'t be rebuilt');
unlink($work.'/cron-fail');
same(array(array(array('id' => 'zz')), false, 0), array($stored(), strpos(zfsmSchedulesHtml($cfg), 'tank/data'), zfsmScheduleFailures($weekly_id)), 'is removed from the list and Settings, with its count');

// Send's Decrypted is saved with a copy's schedule: the first run sends so (scripts/replicate.php), and so does a scheduled one that
// makes the copy, as when that first run never landed. One without it sends raw.
$pool('datasets', array("tank\tfilesystem", "tank/enc\tfilesystem", "backup\tfilesystem"));
$pool('marks', array("tank/enc@a\t11\t10"));
$pool('props', array("tank/enc\tencryption\taes-256-gcm"));
file_put_contents(zfsmScheduleFile(), '[]');
$reset();
$GLOBALS['docroot'] = getenv('ZFSM_TEST_DOCROOT');
$answer = zfsmScheduleAdd('tank/enc', '', 'backup/dec', '', 'hourly', '', '', 0, $cfg, null, true);
for ($waited = 0; $waited < 100 && zfsmJobRunning(zfsmJobLockFile('tank/enc', 'replicate')); $waited++) usleep(50000);
$GLOBALS['docroot'] = $work.'/docroot';
$job = $stored()[0] ?? array();
same(array(array(), true, 1), array($answer['failed'] ?? null, $job['decrypted'] ?? null, preg_match('/^send -P -v -L tank\/enc@repl-[0-9]{14}$/D', implode("\n", $sends()))), 'saved with the schedule, and its first run sends decrypted: '.implode(' | ', $sends()));
$unsent = array('id' => '0badc0f0', 'dest' => 'backup/fresh') + $job;
$raw = array('id' => '0badc0f1', 'dest' => 'backup/fresh-raw') + array_diff_key($job, array('decrypted' => 0));
file_put_contents(zfsmScheduleFile(), json_encode(array($unsent, $raw)));
$reset();
same(array(0, 0), array(zfsmScheduleRun('0badc0f0', $cfg, time() + 3600), zfsmScheduleRun('0badc0f1', $cfg, time() + 7200)), 'scheduled runs whose copies were never made');
same(array(1, 1), array(preg_match('/^send -P -v -L tank\/enc@repl-[0-9]{14}$/D', $sends()[0] ?? ''), preg_match('/^send -P -v -w tank\/enc@repl-[0-9]{14}$/D', $sends()[1] ?? '')), 'send decrypted as saved, else raw: '.implode(' | ', $sends()));

echo "schedule tests passed\n";
