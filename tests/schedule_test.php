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
// Unraid's crontab rebuild: logs each run, and fails while cron-fail is there.
file_put_contents($root.'/usr/local/sbin/update_cron', "#!/bin/sh\necho ran >> ".escapeshellarg($work.'/cron.log')."\n[ ! -e ".escapeshellarg($work.'/cron-fail')." ] || { echo 'crontab: cannot write' >&2; exit 1; }\n");
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
$stored = function() { return json_decode((string)@file_get_contents(zfsmScheduleFile()), true); };
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

// Each schedule is one cron line at its own minute past the hour, from its id, naming only that id.
$hourly = array('id' => 'ffff0000', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'hourly', 'hour' => 0, 'day' => 0);
$daily = array('id' => '0a1b2c3d', 'every' => 'daily', 'hour' => 3) + $hourly;
$weekly = array('id' => '00000000', 'every' => 'weekly', 'hour' => 23, 'day' => 2) + $hourly;
same("# Generated ZFS Master NG replication schedules:\n15 * * * * $script run ffff0000 &> /dev/null\n7 3 * * * $script run 0a1b2c3d &> /dev/null\n0 23 * * 2 $script run 00000000 &> /dev/null\n\n",
	zfsmScheduleCronText(array($hourly, $daily, $weekly)), 'hourly, daily at an hour and weekly on a day at an hour');
same('', zfsmScheduleCronText(array()), 'no schedules, no cron file');
same(array('every hour at :15', 'every day at 03:07', 'every Tuesday at 23:00'), array_map('zfsmScheduleWhen', array($hourly, $daily, $weekly)), 'each says when it runs');
same(array(true, true, false, false, false, false, false), array_map('zfsmScheduleValid', array($hourly, array('server' => 'nas', 'dest' => 'data') + $hourly,
	array('server' => 'nas', 'dest' => 'a/b') + $hourly, array('dest' => 'data') + $hourly, array('every' => 'minutely') + $hourly, array('hour' => 24) + $daily, array('hour' => '3') + $daily)),
	'a copy here is a dataset in a pool, one on a server a name in its area, and nothing runs more often than hourly');

// A new schedule's minute is one no other schedule of its dataset runs at, as two at once would skip one; others may share it.
$taken = array();
foreach (range(0, 58) as $minute) $taken[sprintf('%04x', $minute).'0000'] = array('id' => sprintf('%04x', $minute).'0000') + $hourly;
foreach (range(1, 20) as $try) same(59, zfsmScheduleMinute(zfsmScheduleNewId($taken, 'tank/data')), 'the one minute left for tank/data');
same(true, count(array_unique(array_map(function() use ($taken) { return zfsmScheduleMinute(zfsmScheduleNewId($taken, 'tank/other')); }, range(1, 40)))) > 1, 'any minute for another dataset');

// Cron's next run after a time, here in UTC, and the snapshot names it gives against Repeat's own run and the run after it.
$at = function($text) { return (new DateTime($text, new DateTimeZone('UTC')))->getTimestamp(); };
date_default_timezone_set('UTC');
same(array($at('2026-11-15 10:15'), $at('2026-11-15 11:15'), $at('2026-11-16 03:07'), $at('2026-11-17 23:00'), $at('2026-11-24 23:00')),
	array(zfsmScheduleNext($hourly, $at('2026-11-15 10:05')), zfsmScheduleNext($hourly, $at('2026-11-15 10:15')), zfsmScheduleNext($daily, $at('2026-11-15 10:05')), zfsmScheduleNext($weekly, $at('2026-11-15 10:05')), zfsmScheduleNext($weekly, $at('2026-11-17 23:00'))),
	'the next minute it names, past the hour, day or weekday it names');
$hours = array('snap_pattern' => 'Y-m-d-H') + $cfg;
same(array('Snapshots Pattern would give this run and the next, at 10:15, the same snapshot name', '', 'Snapshots Pattern would give two runs an hour apart the same snapshot name', 'Snapshots Pattern would give two runs a week apart the same snapshot name', ''),
	array(zfsmScheduleClash($hourly, $hours, $at('2026-11-15 10:05'), true), zfsmScheduleClash($hourly, $hours, $at('2026-11-15 10:20'), true), zfsmScheduleClash($hourly, array('snap_pattern' => 'Y-m-d') + $cfg, $at('2026-11-15 10:20'), false),
		zfsmScheduleClash($weekly, array('snap_pattern' => 'D-H') + $cfg, $at('2026-11-15 10:05'), true), zfsmScheduleClash($daily, array('snap_pattern' => 'Y-m-d') + $cfg, $at('2026-11-15 10:05'), true)),
	'an hourly one at :15 made at 10:05 would name its 10:15 run as the first, and made at 10:20 would not; a day\'s name repeats each hour and a weekday\'s each week, but a daily one\'s does not');
same('Snapshots Pattern would give this run and the next, at 03:07, the same snapshot name', zfsmScheduleClash($daily, array('snap_pattern' => 'Y-m-d') + $cfg, $at('2026-11-15 01:00'), true), 'a daily one at 03:07 made at 01:00 runs again that day');

// Repeat saves the schedule and runs it now: the first run is the dialog's own, so it may send in full.
$reset();
$answer = $add('tank/data', '', 'backup/data', '', 'hourly', -1, -1, $cfg);
$jobs = $stored();
same(array(1, 'tank/data', 'backup/data', '', 'hourly', 0, 0), array(count($jobs), $jobs[0]['dataset'], $jobs[0]['dest'], $jobs[0]['server'], $jobs[0]['every'], $jobs[0]['hour'], $jobs[0]['day']), 'the schedule is saved');
$id = $jobs[0]['id'];
same(array('succeeded' => array('tank/data' => 'Replication to backup/data started; a notification says when it is done. It runs again '.zfsmScheduleWhen($jobs[0])), 'failed' => array()), $answer, 'and the answer says it started, and when it runs again');
same(array(zfsmScheduleCronText(array($jobs[0])), array('ran')), array($cron(), $read('cron.log')), 'its cron line is written and Unraid rebuilds its crontab');
same(1, preg_match('/^backup\/data@repl-[0-9]{14}$/D', $names('backup/data', 'snapshot')[0] ?? ''), 'the first run made the copy');
$taken = count($names('tank/data', 'snapshot'));

// Refused before anything runs: a second schedule for that copy, one that runs no copy, and one whose snapshot names would repeat.
$reset();
same(array('tank/data' => 'Nothing was scheduled: this copy already has a schedule ('.zfsmScheduleWhen($jobs[0]).'); remove it in Settings first'), zfsmScheduleAdd('tank/data', '', 'backup/data', '', 'daily', 4, 0, $cfg)['failed'], 'a second schedule for one copy');
same(array('tank/data' => 'Nothing was scheduled: pick how often and when, and give the copy a name as Replicate does'), zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'daily', -1, 0, $cfg)['failed'], 'a daily one with no hour');
same(array('tank/data' => 'Nothing was scheduled: pick how often and when, and give the copy a name as Replicate does'), zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'minutely', 0, 0, $cfg)['failed'], 'one more often than hourly');
same(1, preg_match('/^Nothing was scheduled: Snapshots Pattern would give (?:this run and the next, at [0-2][0-9]:[0-5][0-9],|two runs an hour apart) the same snapshot name; add the time to it in Settings$/D', zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'hourly', 0, 0, array('snap_pattern' => 'Ymd') + $cfg)['failed']['tank/data'] ?? ''), 'an hourly one whose pattern has no hour');
same(array('tank/data' => 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now'), zfsmScheduleAdd('tank/data', 'b', 'backup/two', '', 'daily', 3, 0, $cfg)['failed'], 'one with a snapshot picked');
same(array('tank/data' => 'Nothing was scheduled: pick how often and when, and give the copy a name as Replicate does'), zfsmScheduleAdd('tank/data', 'b', 'backup/two', '', 'often', 3, 0, $cfg)['failed'], 'and one that repeats no way it knows, with a snapshot picked too');
same(array('tank/data' => "Nothing was scheduled: The copy goes inside nowhere, which doesn't exist"), zfsmScheduleAdd('tank/data', '', 'nowhere/data', '', 'weekly', 3, 2, $cfg)['failed'], 'one whose first run can\'t start says why');
same(array($jobs, array(), array(), $taken), array($stored(), $read('cron.log'), $sends(), count($names('tank/data', 'snapshot'))), 'and none of them saves, runs or takes a snapshot');

// Cron's runs take a snapshot and send the changes; one that works sends no notification.
$reset();
same(0, zfsmScheduleRun($id, $cfg, time() + 3600), 'a scheduled run');
same(1, preg_match('/^send -P -v -i tank\/data@repl-[0-9]{14} tank\/data@repl-[0-9]{14}$/D', implode("\n", $sends())), 'sends only the changes: '.implode(' | ', $sends()));
same(array($taken + 1, 2, array()), array(count($names('tank/data', 'snapshot')), count($names('backup/data', 'snapshot')), $read('notify-detail.log')), 'each side keeps the new snapshot, and nothing is notified');

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
same(array(false, true), array(strpos(zfsmSchedulesHtml($cfg), 'Snapshots Pattern'), strpos(zfsmSchedulesHtml(array('snap_pattern' => 'Y-m-d') + $cfg), 'Snapshots Pattern would give two runs an hour apart the same snapshot name, so that run fails: add the time to Snapshots Pattern') !== false), 'and, with Snapshots Pattern changed since, that its runs\' names repeat');
same(1, preg_match('/Scheduled replication '.$id.': 2 run\(s\) in a row with a problem$/m', (string)@file_get_contents(zfsmLogFile())), 'each problem is logged with its count');
// Replicated once by hand, the next scheduled run works and says so.
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a run by hand makes a new copy');
$reset();
same(0, zfsmScheduleRun($id, $cfg, time() + 14400), 'and the next scheduled run works');
same(1, preg_match('/^normal: Replication of tank\/data@repl-[0-9]{14} to backup\/data finished \(changes since b\)\. It works again after 2 run\(s\) with a problem\.$/D', $read('notify-detail.log')[0] ?? ''), 'its notification says it works again: '.implode(' | ', $read('notify-detail.log')));
same(array(0, false), array(zfsmScheduleFailures($id), strpos(zfsmSchedulesHtml($cfg), 'with a problem')), 'the count is cleared');
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
same(array(2, 'Usage: schedule.php run <schedule id>'), $run('run ../x'), 'anything but an id is refused');

// A list that can't be read is never written over; records the plugin can't use are kept as they are.
$saved = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), '{"id":"'.$id.'"}');
$unreadable = zfsmScheduleFile().' could not be read as a list of schedules; fix or remove it first';
same(array(array('tank/data' => 'Nothing was scheduled: '.$unreadable), $unreadable, '{"id":"'.$id.'"}'), array(zfsmScheduleAdd('tank/data', '', 'backup/two', '', 'daily', 1, 0, $cfg)['failed'], zfsmScheduleRemove($id)['why'], file_get_contents(zfsmScheduleFile())), 'an object is no list: nothing is added or removed');
same(true, strpos(zfsmSchedulesHtml($cfg), zfsmHtml($unreadable)) !== false, 'Settings says so');
$reset();
same(1, zfsmScheduleRun($id, $cfg, time()), 'and a run can\'t start');
same(array('warning: Scheduled replication not started: '.zfsmSafeText($unreadable).'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'), $read('notify-detail.log'), 'which it notifies');
@unlink(zfsmScheduleStateFile($id));
file_put_contents(zfsmScheduleFile(), '[{"id":"zz"},'.substr($saved, 1));
same(true, strpos(zfsmSchedulesHtml($cfg), '1 record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use') !== false, 'a record it can\'t use is counted in Settings');

// Remove takes the schedule out of the list and the cron file, and Unraid rebuilds its crontab; Settings is refused while another change is saved.
$lock = zfsmJobLock(zfsmJobDir('schedule').'/settings.lock', false);
same(array('saved' => false, 'why' => 'Another change to the schedules is being saved; try again'), zfsmScheduleRemove($id), 'a change while another is being saved');
zfsmJobUnlock($lock);
$reset();
same(array('saved' => true, 'why' => ''), zfsmScheduleRemove($id), 'removed');
same(array(array(array('id' => 'zz')), null, array('ran')), array($stored(), $cron(), $read('cron.log')), 'the record it can\'t use stays; the cron file goes, and the crontab is rebuilt');
same(array('No such schedule; it may have been removed already', 'No such schedule'), array(zfsmScheduleRemove($id)['why'], zfsmScheduleRemove('../x')['why']), 'removing it again, or something that is no id');

// A crontab that can't be rebuilt: the schedule is saved and its first run started, and the answer says cron runs the ones it had.
$reset();
touch($work.'/cron-fail');
// Its own prefix: the test's first run took a snapshot named for this same second.
$answer = $add('tank/data', '', 'backup/data', '', 'weekly', 5, 6, array('snap_prefix' => 'weekly-') + $cfg);
unlink($work.'/cron-fail');
same(array('succeeded' => array('tank/data' => 'Replication to backup/data started; a notification says when it is done'), 'failed' => array('tank/data' => 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (crontab: cannot write), so it runs the ones it had until the server restarts')),
	$answer, 'the answer says both');
same(array(2, 1), array(count($stored()), preg_match('/^every Saturday at 05:[0-5][0-9]$/D', zfsmScheduleWhen($stored()[0]))), 'and the schedule is saved, beside the record kept as it was');
// And one removed while it can't be rebuilt: removed all the same, with its count, and the answer says cron runs it until a restart.
$weekly_id = $stored()[0]['id'];
file_put_contents(zfsmScheduleStateFile($weekly_id), '{"failures":1}');
touch($work.'/cron-fail');
same(array('saved' => true, 'why' => 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (crontab: cannot write), so it runs the ones it had until the server restarts'), zfsmScheduleRemove($weekly_id), 'a removal whose crontab can\'t be rebuilt');
unlink($work.'/cron-fail');
same(array(array(array('id' => 'zz')), false, 0), array($stored(), strpos(zfsmSchedulesHtml($cfg), 'tank/data'), zfsmScheduleFailures($weekly_id)), 'is removed from the list and Settings, with its count');

echo "schedule tests passed\n";
