<?php
// Snapshot schedules (include/ZFSMScheduleSnapshot.php), Snapshot Every Dataset and a dataset's own, through the real store, cron
// file, runs and Keep, with the fake zfs's small pool (ZFSM_TEST_REPL_DIR) and Unraid's crontab rebuild faked under $GLOBALS['zfsm_root'].

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-snapsched-'.getmypid().'-'.bin2hex(random_bytes(4));
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
file_put_contents($root.'/usr/local/sbin/update_cron', "#/bin/bash\necho ran >> ".escapeshellarg($work.'/cron.log')."\n");

$pool = function($file, $lines) use ($work) { file_put_contents($work.'/pool/'.$file, $lines ? implode("\n", $lines)."\n" : ''); };
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'calls.log', 'cron.log') as $name) @unlink($work.'/'.$name); };
$stored = function() { return json_decode((string)@file_get_contents(zfsmScheduleFile()), true); };
$snaps = function($dataset) { return array_column(zfsmKeepSnapshots($dataset) ?? array(), 'tag', 'name'); };
$takes = function() use ($read) { return array_values(preg_grep('/^snapshot /', $read('calls.log'))); };
$cfg = array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$name = function($now) use ($cfg) { return zfsmNewSnapshotName($cfg, $now); };
$script = '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php';
// Two pools: a received copy (read-only), Docker's layer (legacy) and a volume beside plain filesystems.
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/docker\tfilesystem", "tank/docker/0a1b\tfilesystem", "tank/vm\tvolume", "backup\tfilesystem", "backup/copy\tfilesystem"));
$pool('marks', array("tank/data@a\t11\t10", "tank/data@b\t12\t20"));
$pool('props', array("tank/docker/0a1b\tmountpoint\tlegacy", "backup/copy\treadonly\ton"));
$pool('holds', array());
$copy = array('id' => 'ffff0000', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'hourly', 'minute' => 15, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);

// The record: no copy or server; dataset '' is the default, else a dataset's own, which alone can be None. A Repeat that is no text
// is no record, and None no copy's.
$every = array('id' => '0a1b2c3d', 'dataset' => '', 'dest' => '', 'server' => '', 'every' => 'hourly', 'minute' => 30, 'hour' => 0, 'days' => range(0, 6), 'keep' => 2);
$records = array($every, array('dataset' => 'tank/data') + $every, array('dataset' => 'tank/data', 'every' => 'none') + $every, array('server' => 'nas') + $every,
	array('every' => 'none') + $every, array('dataset' => 'tank/bad name!') + $every, array('every' => array('hourly')) + $every, $copy, array('every' => 'none') + $copy);
same(array(true, true, true, false, false, false, false, true, false), array_map('zfsmScheduleValid', $records), 'which records are snapshot schedules');
same(array(array('tank' => array('tank', 'tank/data', 'tank/docker', 'tank/vm'), 'backup' => array('backup')), ''), zfsmScheduleCovered(runProcess('zfs', ZFSM_SCHEDULE_COVERED), $every, array($every)), 'it covers every dataset but read-only and legacy ones, by pool');

// Off with none saves none; Settings shows Off.
same(array('saved' => true, 'why' => '', 'said' => 'Snapshot Every Dataset is off; the snapshots it took stay'), zfsmScheduleSnapshotSave('', 'off', '', '', 0, $cfg), 'off with none');
same(array(array(), 'off'), array($stored(), zfsmScheduleSnapshotShown()['every']), 'saves no record');
// Saved again every hour: its id and minute stay, though another minute is free.
zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg);
$first = array_values($stored())[0];
$again = array_map(function() use ($cfg, $stored) { zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg); return array_values($stored()); }, range(1, 3));
same(array_fill(0, 3, array($first)), $again, 'its id and minute stay');

// Every hour: at the one minute no copy schedule takes, as the copy's would clash; nothing is taken on saving.
$copies = array_map(function($minute) use ($copy) { return array('id' => sprintf('%08x', $minute + 1), 'minute' => $minute, 'dest' => 'backup/c'.$minute) + $copy; }, array_diff(range(0, 59), array(30)));
file_put_contents(zfsmScheduleFile(), json_encode(array_merge($copies, array(array('id' => 'zz')))));
$reset();
$saved = zfsmScheduleSnapshotSave('', 'hourly', '', '', 2, $cfg);
// From the records, not the keys: PHP makes an all-digit id such as 12345678 an int key.
$id = array_values(array_filter(array_column(zfsmScheduleRead()['valid'], 'id'), function($id) use ($copies) { return !in_array($id, array_column($copies, 'id'), true); }))[0];
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each of 5 dataset(s) every hour at :30; it keeps the newest 2 scheduled snapshots of each dataset'), $saved, 'saved at the minute left free');
same(array_replace($every, array('id' => $id)), zfsmScheduleRead()['valid'][$id], 'as the record');
same(array(array('id' => 'zz'), array('ran'), array()), array(array_slice($stored(), -1)[0], $read('cron.log'), $takes()), 'a record it can\'t use stays; the crontab is rebuilt; nothing is taken');
same(1, substr_count((string)file_get_contents(zfsmScheduleCronFile()), "30 * * * * $script run $id &> /dev/null\n"), 'its cron line');
same(true, strpos(zfsmSchedulesHtml($cfg), '<span style="display:block">Snapshot Every Dataset, every hour at :30, keeps the newest 2 scheduled snapshots of each dataset</span>') !== false, 'Settings lists it');
same(false, strpos(zfsmSchedulesHtml($cfg), "zfsmScheduleRemove('$id')"), 'with no Remove: its own row turns it off');
// A copy schedule can't take its minute now, nor start then.
$but31 = array_filter(zfsmScheduleRead()['valid'], function($job) { return $job['minute'] !== 31; });
same(array(31), array_values(array_unique(array_map(function() use ($but31, $copy) { return zfsmScheduleFreeMinute($but31, array('dest' => 'backup/new') + $copy); }, range(1, 20)))), 'an hourly copy\'s minute is never the snapshot schedule\'s');
same('Snapshot Every Dataset (every hour at :30) starts then too; pick another time', zfsmScheduleSame(zfsmScheduleRead()['valid'], array('every' => 'days', 'minute' => 30, 'hour' => 4, 'dest' => 'backup/new') + $copy), 'nor a copy at that minute');

// Saved again, in place: its id and minute stay, and Keep changes.
same('Saved: a snapshot of each of 5 dataset(s) every hour at :30; it keeps all it takes', zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg)['said'], 'Keep changes in place');
same(array_replace($every, array('id' => $id, 'keep' => 0)), zfsmScheduleRead()['valid'][$id], 'the same record');
same(array(60, 'hourly'), array(count(zfsmScheduleRead()['valid']), zfsmScheduleSnapshotShown()['every']), 'no second one, and Settings shows it');
same(array(3, 0), array(zfsmScheduleSnapshotShown()['hour'], zfsmScheduleSnapshotShown()['minute']), 'with Starts At at Off\'s 03:00, not the :30 it runs at');
// At a time a copy starts: refused, the copy named; the record stays as it was.
file_put_contents(zfsmScheduleFile(), json_encode(array(array_replace($copy, array('id' => '0b0b0b0b', 'every' => 'days', 'minute' => 7, 'hour' => 3)), array_replace($every, array('id' => $id)))));
$wrong = array(
	array('days', '03:07', '1', 2, 'Nothing was saved: the copy to backup/data (every day at 03:07) starts then too; pick another time'),
	array('weekly', '03:00', '1', 2, 'Nothing was saved: Repeat has no such choice now; reload the page and pick again'),
	array('days', '', '', -1, 'Nothing was saved: set the time it starts at; tick at least one day; under Snapshots to keep, give a number up to 9999, or nothing to keep all'),
);
same(array_column($wrong, 4), array_map(function($try) use ($cfg) { return zfsmScheduleSnapshotSave('', $try[0], $try[1], $try[2], $try[3], $cfg)['why']; }, $wrong), 'what is wrong is said');
same(array_replace($every, array('id' => $id)), zfsmScheduleRead()['valid'][$id], 'and it stays as it was');
same(1, preg_match('/^Nothing was saved: Snapshots Pattern would give the run at .+ the same snapshot name as the run at .+; add the time to Snapshots Pattern$/D', zfsmScheduleSnapshotSave('', 'hourly', '', '', 2, array('snap_pattern' => 'Ymd') + $cfg)['why']), 'a pattern that names two runs alike');
// On days of the week: the days in order, its own time.
same('Saved: a snapshot of each of 5 dataset(s) every Monday and Saturday at 04:00; it keeps the newest 3 scheduled snapshots of each dataset', zfsmScheduleSnapshotSave('', 'days', '04:00', '6,1', 3, $cfg)['said'], 'on days');
same(array_replace($every, array('id' => $id, 'every' => 'days', 'minute' => 0, 'hour' => 4, 'days' => array(1, 6), 'keep' => 3)), zfsmScheduleRead()['valid'][$id], 'saved so');
same(1, substr_count((string)file_get_contents(zfsmScheduleCronFile()), "0 4 * * 1,6 $script run $id &> /dev/null\n"), 'its cron line');
$shown = zfsmScheduleSnapshotShown();
same(array('days', 4, 0, array(1, 6)), array($shown['every'], $shown['hour'], $shown['minute'], $shown['days']), 'and Settings shows its time and days');
file_put_contents(zfsmScheduleFile(), json_encode(array(array_replace($every, array('id' => $id)))));

// A run: one command per pool, every snapshot tagged, and only logged.
$reset();
$t = 1800000000;
same(0, zfsmScheduleRun($id, $cfg, $t), 'a run works');
$n = $name($t);
same(array("snapshot -o zfs.master:schedule=snapshots tank@$n tank/data@$n tank/docker@$n tank/vm@$n", "snapshot -o zfs.master:schedule=snapshots backup@$n"), $takes(), 'one command per pool, none for read-only or legacy');
same(array('tank/data@a' => '-', 'tank/data@b' => '-', "tank/data@$n" => 'snapshots'), $snaps('tank/data'), 'tagged');
same(array(array(), 1), array($read('notify-detail.log'), preg_match('/ Schedule '.$id.': Scheduled snapshots of 5 of 5 dataset\(s\) taken$/m', (string)file_get_contents(zfsmLogFile()))), 'logged, not notified');

// A pool whose command fails: one at a time, the rest taken, and the one that can't be named; notified once.
$reset();
putenv('ZFSM_TEST_SNAP_FAIL=tank/data');
same(1, zfsmScheduleRun($id, $cfg, $t + 3600), 'a run with a problem');
putenv('ZFSM_TEST_SNAP_FAIL');
$n = $name($t + 3600);
same(6, count($takes()), 'the pool\'s command, then each of its datasets, and the other pool\'s');
$all = function() { return array_merge(...array_map(function($dataset) { return array_keys(zfsmKeepSnapshots($dataset) ? array_column(zfsmKeepSnapshots($dataset), 'tag', 'name') : array()); }, array('tank', 'tank/data', 'tank/docker', 'tank/vm', 'backup'))); };
same(array("tank@$n", "tank/docker@$n", "tank/vm@$n", "backup@$n"), array_values(preg_grep('/@'.preg_quote($n, '/').'$/', $all())), 'the rest are taken');
same(array(array('warning: ZFS Snapshot'), array("warning: Scheduled snapshots of 4 of 5 dataset(s) taken. Could not take tank/data@$n (cannot create snapshot ?tank/data@$n?: out of space). Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.")),
	array($read('notify.log'), $read('notify-detail.log')), 'notified, naming it and why');
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 7200), 'the next run works');
same(array('normal: Scheduled snapshots of 5 of 5 dataset(s) taken. It works again after 1 run(s) with a problem.'), $read('notify-detail.log'), 'and says so');

// A run while the one before still goes: none taken, notified. One whose lock can't be opened says so.
$reset();
$held = zfsmJobLock(zfsmJobDir('schedule').'/'.$id.'.lock');
same(true, is_resource($held), 'a run lets go of its lock');
same(1, zfsmScheduleRun($id, $cfg, $t + 9000), 'a run while the one before still goes');
zfsmJobUnlock($held);
same(array(array(), array('warning: Scheduled snapshots not taken: the run before is still going. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array($takes(), $read('notify-detail.log')), 'takes none, and says why');
$reset();
touch($work.'/plain-file');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/plain-file');
same(1, zfsmScheduleRun($id, $cfg, $t + 9000), 'a run whose lock file can\'t be opened');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
same(array(array(), array('warning: Scheduled snapshots not taken: the lock file could not be opened.')), array($takes(), $read('notify-detail.log')), 'takes none, and says why');

// Keep 2: only the snapshots it tagged count, the newest 2 of each stay; one held or cloned stays; a copy's tag never counts.
$copy_tag = zfsmKeepTag('', 'backup/data');
file_put_contents($work.'/pool/marks', "tank/data@c\t13\t30\n", FILE_APPEND);
file_put_contents($work.'/pool/props', "tank/data@c\tzfs.master:schedule\t$copy_tag\n", FILE_APPEND);
file_put_contents($work.'/pool/holds', "tank@".$name($t + 3600)."\tkeep-me\n");
file_put_contents($work.'/pool/props', "tank/docker@".$name($t + 3600)."\tclones\ttank/clone\n", FILE_APPEND);
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 10800), 'a run with Keep');
$keep_read = 'list -H -p -d 1 -t snapshot -o name,guid,createtxg,userrefs,clones,zfs.master:schedule -s createtxg ';
same(array($keep_read.'tank tank/data tank/docker tank/vm', $keep_read.'backup'), array_values(preg_grep('/^list .*-t snapshot /', $read('calls.log'))), 'Keep reads only the datasets it took, one command per pool');
$tagged = function($dataset) use ($snaps) { return array_keys(array_filter($snaps($dataset), function($tag) { return $tag === 'snapshots'; })); };
same(array('tank/data@a', 'tank/data@b', 'tank/data@'.$name($t + 7200), 'tank/data@c', 'tank/data@'.$name($t + 10800)), array_keys($snaps('tank/data')), 'past the newest 2 go; by hand and the copy\'s stay');
same(array('tank@'.$name($t + 3600), 'tank@'.$name($t + 7200), 'tank@'.$name($t + 10800)), $tagged('tank'), 'a held one stays');
same(array('tank/docker@'.$name($t + 3600), 'tank/docker@'.$name($t + 7200), 'tank/docker@'.$name($t + 10800)), $tagged('tank/docker'), 'a cloned one stays');
same(array('backup@'.$name($t + 7200), 'backup@'.$name($t + 10800)), $tagged('backup'), 'and in the other pool');
same(array(), array_column(zfsmKeepSurplus('tank/data', 1, $copy_tag) ?? array(null), 'name'), 'a copy\'s Keep counts only its own');
// A pool whose snapshots can't be read keeps them: said and notified, the next run tries again.
$reset();
putenv('ZFSM_TEST_KEEP_LIST_FAIL=backup');
same(1, zfsmScheduleRun($id, $cfg, $t + 14400), 'a Keep that can\'t read a pool');
putenv('ZFSM_TEST_KEEP_LIST_FAIL');
same(1, preg_match('/^warning: Scheduled snapshots of 5 of 5 dataset\(s\) taken\. Keep could not remove the snapshots of backup \(they could not be read: cannot open \?backup\?: Input\/output error\); the next run tries again\./', $read('notify-detail.log')[0] ?? ''), 'says so');
same(3, count($tagged('backup')), 'and removes none there');

// Datasets that can't be read: nothing taken, notified.
@unlink(zfsmScheduleStateFile($id));
$reset();
putenv('ZFSM_TEST_COVER_FAIL=1');
same(1, zfsmScheduleRun($id, $cfg, $t + 18000), 'a run that can\'t read the datasets');
putenv('ZFSM_TEST_COVER_FAIL');
same(array(array(), array('warning: Scheduled snapshots not taken: the datasets could not be read: cannot iterate filesystems: Input/output error. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array($takes(), $read('notify-detail.log')), 'takes none, and says why');
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each dataset every hour at :30; it keeps the newest 2 scheduled snapshots of each dataset; not every dataset could be read: cannot iterate filesystems: Input/output error'),
	(function() use ($cfg) { putenv('ZFSM_TEST_COVER_FAIL=1'); $saved = zfsmScheduleSnapshotSave('', 'hourly', '', '', 2, $cfg); putenv('ZFSM_TEST_COVER_FAIL'); return $saved; })(), 'a save says it could not count them');
putenv('ZFSM_TEST_COVER_FAIL=hang');
$began = microtime(true);
$saved = zfsmScheduleSnapshotSave('', 'hourly', '', '', 2, $cfg, 1);
putenv('ZFSM_TEST_COVER_FAIL');
same(array(true, 'Saved: a snapshot of each dataset every hour at :30; it keeps the newest 2 scheduled snapshots of each dataset; not every dataset could be read: zfs timed out', true),
	array($saved['saved'], $saved['said'], microtime(true) - $began < 10), 'a count that hangs stops at its limit');
// One dataset zfs can't read: the rest are taken and counted, and it is named.
$reset();
@unlink(zfsmScheduleStateFile($id));
putenv('ZFSM_TEST_COVER_FAIL=tank/vm');
same(1, zfsmScheduleRun($id, $cfg, $t + 21600), 'a run that can\'t read one dataset');
same('Saved: a snapshot of each of 4 dataset(s) every hour at :30; it keeps the newest 2 scheduled snapshots of each dataset; not every dataset could be read: cannot open \'tank/vm\': Input/output error',
	zfsmScheduleSnapshotSave('', 'hourly', '', '', 2, $cfg)['said'], 'and a save counts the rest and names it');
putenv('ZFSM_TEST_COVER_FAIL');
$n = $name($t + 21600);
same(array("snapshot -o zfs.master:schedule=snapshots tank@$n tank/data@$n tank/docker@$n", "snapshot -o zfs.master:schedule=snapshots backup@$n"), $takes(), 'takes the rest, every pool');
same(array("warning: Scheduled snapshots of 4 of 4 dataset(s) taken. Not every dataset could be read: cannot open ?tank/vm?: Input/output error. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again."),
	$read('notify-detail.log'), 'and says which it could not read');
// A read that hangs stops at its limit; a run with no dataset to take is logged only, so the last problem is not "worked again".
$job = zfsmScheduleRead()['valid'][$id];
putenv('ZFSM_TEST_COVER_FAIL=hang');
$began = microtime(true);
$ran = zfsmScheduleSnapshotRun($job, array($id => $job), $cfg, $t + 25200, zfsmScheduleNotifier($id), 1);
putenv('ZFSM_TEST_COVER_FAIL');
$logged = function($text) use ($id) { return preg_match('/ Schedule '.$id.': '.preg_quote($text, '/').'$/m', (string)file_get_contents(zfsmLogFile())); };
same(array(1, true, 1, 2), array($ran, microtime(true) - $began < 10, $logged('Scheduled snapshots not taken: the datasets could not be read: zfs timed out'), zfsmScheduleFailures($id)), 'a run whose read hangs');
$reset();
$listed = file_get_contents($work.'/pool/datasets');
$pool('datasets', array());
same(array(0, 1, array(), 2), array(zfsmScheduleRun($id, $cfg, $t + 28800), $logged('Scheduled snapshots not taken: no pool has a dataset it takes'), $read('notify-detail.log'), zfsmScheduleFailures($id)), 'a run with nothing to take');
file_put_contents($work.'/pool/datasets', $listed);

// Off: the record and its count go, the cron line too; the snapshots stay.
$reset();
$kept = $tagged('backup');
same(array('saved' => true, 'why' => '', 'said' => 'Snapshot Every Dataset is off; the snapshots it took stay'), zfsmScheduleSnapshotSave('', 'off', '', '', 0, $cfg), 'off');
same(array(array(), null, false, $kept, 2), array($stored(), is_file(zfsmScheduleCronFile()) ? file_get_contents(zfsmScheduleCronFile()) : null, is_file(zfsmScheduleStateFile($id)), $tagged('backup'), count($kept)), 'nothing left to run, the snapshots stay');

// A dataset's own (Main, Actions, Schedules): it and the datasets below it with none of their own follow it instead of the default.
$ran_as = function($id, $text) { return preg_match('/ Schedule '.$id.': '.preg_quote($text, '/').'$/m', (string)file_get_contents(zfsmLogFile())); };
$cron = function() { return (string)@file_get_contents(zfsmScheduleCronFile()); };
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each of 1 dataset(s) (tank/data and below) every Monday at 05:00; it keeps the newest 1 scheduled snapshots of each dataset'),
	zfsmScheduleSnapshotSave('tank/data', 'days', '05:00', '1', 1, $cfg), 'a dataset\'s own, counted');
$ids = array_column($stored(), 'id', 'dataset');
same(array_replace($every, array('id' => $ids['tank/data'], 'dataset' => 'tank/data', 'every' => 'days', 'minute' => 0, 'hour' => 5, 'days' => array(1), 'keep' => 1)), zfsmScheduleRead()['valid'][$ids['tank/data']], 'as its record');
same(array(1, 'off'), array(substr_count($cron(), "0 5 * * 1 $script run {$ids['tank/data']} &> /dev/null\n"), zfsmScheduleSnapshotShown()['every']), 'its cron line; Snapshot Every Dataset stays Off');
// The default at the same time: two snapshot schedules never take one dataset's, so may share a minute.
same('Saved: a snapshot of each of 4 dataset(s) every Monday at 05:00; it keeps all it takes', zfsmScheduleSnapshotSave('', 'days', '05:00', '1', 0, $cfg)['said'], 'the default counts all but tank/data');
$ids = array_column($stored(), 'id', 'dataset');
$reset();
same(0, zfsmScheduleRun($ids[''], $cfg, $t + 32400), 'the default runs');
$n = $name($t + 32400);
same(array("snapshot -o zfs.master:schedule=snapshots tank@$n tank/docker@$n tank/vm@$n", "snapshot -o zfs.master:schedule=snapshots backup@$n"), $takes(), 'and leaves out tank/data');
$reset();
$by_default = count($tagged('tank/data'));
same(0, zfsmScheduleRun($ids['tank/data'], $cfg, $t + 36000), 'the dataset\'s own runs');
$n = $name($t + 36000);
same(array(array("snapshot -o zfs.master:schedule=snapshots tank/data@$n"), true, array("tank/data@$n"), 1, array()),
	array($takes(), $by_default > 0, $tagged('tank/data'), $ran_as($ids['tank/data'], 'Scheduled snapshots of 1 of 1 dataset(s) (tank/data and below) taken'), $read('notify-detail.log')),
	'takes only it, keeps the newest 1 scheduled snapshot of it (those the default took too), logs it');

// None: tank/docker and below take none, and it has no cron line. One above with its own takes the rest under it.
same(array('saved' => true, 'why' => '', 'said' => 'Saved: no scheduled snapshots of tank/docker and below, but for datasets with their own; the snapshots taken stay'),
	zfsmScheduleSnapshotSave('tank/docker', 'none', '', '', 0, $cfg), 'None');
same('Saved: a snapshot of each of 2 dataset(s) (tank and below) every Tuesday at 06:00; it keeps all it takes', zfsmScheduleSnapshotSave('tank', 'days', '06:00', '2', 0, $cfg)['said'], 'one above, counting what follows it');
$ids = array_column($stored(), 'id', 'dataset');
same(array_replace($every, array('id' => $ids['tank/docker'], 'dataset' => 'tank/docker', 'every' => 'none', 'minute' => 0, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0)), zfsmScheduleRead()['valid'][$ids['tank/docker']], 'None\'s record');
same(array(3, 0), array(substr_count($cron(), "$script run "), substr_count($cron(), $ids['tank/docker'])), 'no cron line for None');
$reset();
same(array(0, array()), array(zfsmScheduleRun($ids['tank/docker'], $cfg, $t + 39600), $takes()), 'a None run left in the crontab takes none');
same(1, preg_match('/ Schedule '.$ids['tank/docker'].' not started: it is None now$/m', (string)file_get_contents(zfsmLogFile())), 'and logs why');
$reset();
zfsmScheduleRun($ids['tank'], $cfg, $t + 43200);
zfsmScheduleRun($ids[''], $cfg, $t + 46800);
same(array('snapshot -o zfs.master:schedule=snapshots tank@'.$name($t + 43200).' tank/vm@'.$name($t + 43200), 'snapshot -o zfs.master:schedule=snapshots backup@'.$name($t + 46800)), $takes(), 'each dataset by the schedule it follows');

// Settings lists each with Remove, but the default; the dialog starts at the dataset's own and says what Default follows.
$html = zfsmSchedulesHtml($cfg);
$row = function($id, $text) use ($html) { return strpos($html, '<span style="display:block">'.$text.' <input type="button" value="Remove" onclick="zfsmScheduleRemove(\''.$id.'\')"></span>') !== false; };
same(array(true, true, true, true, false), array($row($ids['tank/data'], 'Snapshots of tank/data and below, every Monday at 05:00, keeps the newest 1 scheduled snapshots of each dataset'),
	$row($ids['tank/docker'], 'No scheduled snapshots of tank/docker and below'), $row($ids['tank'], 'Snapshots of tank and below, every Tuesday at 06:00, keeps all it takes'),
	strpos($html, '<span style="display:block">Snapshot Every Dataset, every Monday at 05:00, keeps all it takes</span>') !== false, strpos($html, "zfsmScheduleRemove('{$ids['']}')")), 'Settings\' rows');
$preview = function($zdataset) use ($cfg) { return zfsmScheduleSnapshotPreview($zdataset, $cfg); };
$previews = array_map($preview, array('tank/data', 'tank/docker', 'tank/docker/0a1b', 'tank', 'backup'));
same(array(
	array('every' => 'days', 'time' => '05:00', 'days' => array(1), 'keep' => '1', 'inherited' => 'the snapshot schedule of tank, every Tuesday at 06:00; it keeps all it takes', 'skipped' => ''),
	array('every' => 'none', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'inherited' => 'the snapshot schedule of tank, every Tuesday at 06:00; it keeps all it takes', 'skipped' => ''),
	array('every' => 'default', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'inherited' => 'the snapshot schedule of tank/docker, None: no scheduled snapshots', 'skipped' => 'tank/docker/0a1b is mounted legacy, as Docker image layers are'),
	array('every' => 'days', 'time' => '06:00', 'days' => array(2), 'keep' => '', 'inherited' => 'Snapshot Every Dataset, every Monday at 05:00; it keeps all it takes', 'skipped' => ''),
	array('every' => 'default', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'inherited' => 'Snapshot Every Dataset, every Monday at 05:00; it keeps all it takes', 'skipped' => ''),
), $previews, 'the dialog\'s start');
same(array('Invalid dataset name'), array_values($preview('tank/bad name!')['failed']), 'a name that is none');

// A copy of a dataset can't start with a snapshot schedule of it or of one above it; None and other datasets' don't count.
$at = function($dataset, $hour, $days) use ($copy) { return array('id' => 'eeee0000', 'dataset' => $dataset, 'dest' => 'backup/c', 'every' => 'days', 'minute' => 0, 'hour' => $hour, 'days' => $days) + $copy; };
same(array('the snapshot schedule of tank (every Tuesday at 06:00) starts then too; pick another time', 'the snapshot schedule of tank/data (every Monday at 05:00) starts then too; pick another time', 'Snapshot Every Dataset (every Monday at 05:00) starts then too; pick another time', ''),
	array_map(function($try) { return zfsmScheduleSame(zfsmScheduleRead()['valid'], $try); }, array($at('tank/vm', 6, array(2)), $at('tank/data', 5, array(1)), $at('backup', 5, array(1)), $at('tank/docker', 0, range(0, 6)))), 'which a copy shares with');

// Refused, the store unchanged: a dataset no run can take, a name that is none, and a choice the other kind has.
$before = $stored();
$refused = array(
	array('backup/copy', 'days', 'Nothing was saved: backup/copy is read-only, as a copy received here is: a snapshot in it stops the next replication into it'),
	array('tank/docker/0a1b', 'days', 'Nothing was saved: tank/docker/0a1b is mounted legacy, as Docker image layers are'),
	array('tank/gone', 'hourly', 'Nothing was saved: tank/gone is gone'),
	array('tank/bad name!', 'days', 'Nothing was saved: that is no dataset name'),
	array('tank/bad name!', 'default', 'Nothing was saved: that is no dataset name'),
	array('', 'none', 'Nothing was saved: Repeat has no such choice now; reload the page and pick again'),
	array('tank/vm', 'off', 'Nothing was saved: Repeat has no such choice now; reload the page and pick again'),
);
same(array_column($refused, 2), array_map(function($try) use ($cfg) { return zfsmScheduleSnapshotSave($try[0], $try[1], '07:00', '3', 0, $cfg)['why']; }, $refused), 'what is wrong is said');
putenv('ZFSM_TEST_COVER_FAIL=1');
same('Nothing was saved: tank/vm could not be read: cannot iterate filesystems: Input/output error', zfsmScheduleSnapshotSave('tank/vm', 'days', '07:00', '3', 0, $cfg)['why'], 'and one that could not be read');
putenv('ZFSM_TEST_COVER_FAIL');
same($before, $stored(), 'none saved');

// Its dataset gone: the run says to remove it. Default removes it, its count too; Off removes only the default.
same('Saved: a snapshot of each of 1 dataset(s) (tank/vm and below) every Wednesday at 07:00; it keeps all it takes', zfsmScheduleSnapshotSave('tank/vm', 'days', '07:00', '3', 0, $cfg)['said'], 'tank/vm\'s own');
$ids = array_column($stored(), 'id', 'dataset');
$reset();
$pool('datasets', array_values(array_diff(explode("\n", trim($listed)), array("tank/vm\tvolume"))));
same(1, zfsmScheduleRun($ids['tank/vm'], $cfg, $t + 50400), 'a run whose dataset is gone');
file_put_contents($work.'/pool/datasets', $listed);
same(array(array(), array('warning: Scheduled snapshots of tank/vm not taken: tank/vm is gone; remove its schedule in Settings. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array($takes(), $read('notify-detail.log')), 'says to remove it');
// Its pool not imported (a stopped array exports it): only logged, as for the default.
$reset();
$pool('datasets', array("backup\tfilesystem", "backup/copy\tfilesystem"));
same(array(0, 1, array()), array(zfsmScheduleRun($ids['tank/data'], $cfg, $t + 54000), $ran_as($ids['tank/data'], 'Scheduled snapshots of tank/data not taken: its pool is not imported'),
	$read('notify-detail.log')), 'a run whose pool is not imported');
file_put_contents($work.'/pool/datasets', $listed);
// A dataset in another pool that can't be read is no problem of tank/data's; one above it is.
$reset();
putenv('ZFSM_TEST_COVER_FAIL=backup/copy');
same(array(0, array()), array(zfsmScheduleRun($ids['tank/data'], $cfg, $t + 57600), $read('notify-detail.log')), 'a run, with a dataset in another pool unread');
putenv('ZFSM_TEST_COVER_FAIL=tank');
same(array(1, array('warning: Scheduled snapshots of 1 of 1 dataset(s) (tank/data and below) taken. Not every dataset could be read: cannot open ?tank?: Input/output error. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array(zfsmScheduleRun($ids['tank/data'], $cfg, $t + 61200), $read('notify-detail.log')), 'and with the dataset above it unread');
putenv('ZFSM_TEST_COVER_FAIL');
@unlink(zfsmScheduleStateFile($ids['tank/data']));
// None keeps its id, not its count of runs with a problem: it never runs to clear it.
$problem = function() use ($cfg) { return strpos(zfsmSchedulesHtml($cfg), 'run(s) in a row with a problem') !== false; };
same(array(1, true), array(zfsmScheduleFailures($ids['tank/vm']), $problem()), 'the gone run is counted, and Settings says so');
zfsmScheduleSnapshotSave('tank/vm', 'none', '', '', 0, $cfg);
same(array($ids['tank/vm'], false, false), array(array_column($stored(), 'id', 'dataset')['tank/vm'], is_file(zfsmScheduleStateFile($ids['tank/vm'])), $problem()), 'None keeps its id, not its count');
// A run going as None is saved records its problem after: still none shown.
zfsmScheduleSnapshotSave('tank/vm', 'hourly', '', '', 0, $cfg);
$notify = zfsmScheduleNotifier($ids['tank/vm']);
zfsmScheduleSnapshotSave('tank/vm', 'none', '', '', 0, $cfg);
$notify('ZFS Snapshot', 'Scheduled snapshots of tank/vm not taken: tank/vm is gone.', 'warning');
same(array(1, false), array(zfsmScheduleFailures($ids['tank/vm']), $problem()), 'a problem a run records after None is saved is not shown');
touch(zfsmScheduleStateFile($ids['tank/vm']));
same(array('saved' => true, 'why' => '', 'said' => 'Saved: tank/vm follows the snapshot schedule of tank, every Tuesday at 06:00; it keeps all it takes'), zfsmScheduleSnapshotSave('tank/vm', 'default', '', '', 0, $cfg), 'Default');
same(array(false, false, 0), array(isset(array_column($stored(), 'id', 'dataset')['tank/vm']), is_file(zfsmScheduleStateFile($ids['tank/vm'])), substr_count($cron(), $ids['tank/vm'])), 'removes it, its count and its cron line');
same('Snapshot Every Dataset is off; the snapshots it took stay', zfsmScheduleSnapshotSave('', 'off', '', '', 0, $cfg)['said'], 'Off');
$own = array_column($stored(), 'dataset');
sort($own);
same(array(array('tank', 'tank/data', 'tank/docker'), 'off', 'Snapshot Every Dataset, which is off: no scheduled snapshots'),
	array($own, zfsmScheduleSnapshotShown()['every'], $preview('backup')['inherited']), 'leaves the datasets\' own');
same('Saved: tank follows Snapshot Every Dataset, which is off: no scheduled snapshots', zfsmScheduleSnapshotSave('tank', 'default', '', '', 0, $cfg)['said'], 'Default with nothing above');

// Rename: the own schedules of it and below follow it, ids kept, and one left at the new name gives way, its count too.
$pool('datasets', array_merge(explode("\n", trim($listed)), array("tank/data/sub\tfilesystem")));
zfsmScheduleSnapshotSave('tank/data/sub', 'hourly', '', '', 0, $cfg);
zfsmScheduleChange(function($jobs) use ($every) {
	return $jobs + array('5a5a5a5a' => array('id' => '5a5a5a5a', 'dataset' => 'tank/new') + $every, '5b5b5b5b' => array('id' => '5b5b5b5b', 'dataset' => 'tank/new/sub', 'every' => 'none') + $every);
});
touch(zfsmScheduleStateFile('5a5a5a5a'));
touch(zfsmScheduleStateFile('5b5b5b5b'));
$ids = array_column($stored(), 'id', 'dataset');
$own = function() use ($stored) { $by = array_column($stored(), 'id', 'dataset'); ksort($by); return $by; };
same(array('succeeded' => array('tank/new' => 0), 'failed' => array()), renameDataset('tank/data', 'tank/new', 0), 'a rename');
same(array(array('tank/docker' => $ids['tank/docker'], 'tank/new' => $ids['tank/data'], 'tank/new/sub' => $ids['tank/data/sub']), 3, false, false),
	array($own(), count($stored()), is_file(zfsmScheduleStateFile('5a5a5a5a')), is_file(zfsmScheduleStateFile('5b5b5b5b'))), 'its own schedules and those below follow it');
$inode = fileinode(zfsmScheduleFile());
same(array(array('succeeded' => array('tank/vm2' => 0), 'failed' => array()), $inode), array(renameDataset('tank/vm', 'tank/vm2', 0), fileinode(zfsmScheduleFile())), 'one with none writes nothing');
$pool('datasets', array("tank\tfilesystem", "tank/new\tfilesystem", "tank/new/sub\tfilesystem", "tank/docker\tfilesystem"));
$lock = zfsmJobLock(zfsmJobDir('schedule').'/settings.lock', false);
same(array('succeeded' => array('tank/newer' => 0), 'failed' => array('Snapshot schedules of tank/new' => 'not moved to tank/newer (Another change to the schedules is being saved; try again): Remove them in Settings and set them again under Actions, Schedules')),
	renameDataset('tank/new', 'tank/newer', 0), 'one whose schedules could not be moved says so');
zfsmJobUnlock($lock);
// Destroy: they go, counts too.
touch(zfsmScheduleStateFile($ids['tank/data']));
same(array('succeeded' => array('tank/new' => 0), 'failed' => array()), destroyDataset('tank/new', 1), 'a destroy');
same(array(array('tank/docker' => $ids['tank/docker']), false), array($own(), is_file(zfsmScheduleStateFile($ids['tank/data']))), 'its own schedules and those below go');

// Sanoid's or Syncoid's snapshots leave a dataset to that tool: no run takes it, its own can't be saved, a run of one it has says so,
// the dialog says why. One below it without them is still taken; a Snapshots Prefix that starts as theirs do names the plugin's own.
$pool('datasets', array("tank\tfilesystem", "tank/sanoid\tfilesystem", "tank/sanoid/kid\tfilesystem", "tank/synced\tvolume", "tank/plain\tfilesystem"));
$pool('marks', array("tank/sanoid@autosnap_2026-11-17_00:00:01_hourly\t21\t30", "tank/sanoid@by-hand\t26\t35", "tank/synced@syncoid_nas_2026-11-17:00:00:01-GMT00:00\t22\t31", "tank/plain@by-hand\t24\t33"));
file_put_contents(zfsmScheduleFile(), '[]');
$covered = function($prefix) use ($cfg, $every) { return zfsmScheduleCovered(zfsmScheduleCoverRead(array('snap_prefix' => $prefix) + $cfg, 30), $every, array($every)); };
same(array(array(array('tank' => array('tank', 'tank/sanoid/kid', 'tank/plain')), ''), array(array('tank' => array('tank', 'tank/sanoid', 'tank/sanoid/kid', 'tank/plain')), '')),
	array($covered('auto'), $covered('autosnap_')), 'Snapshot Every Dataset leaves them out, not the one below; with a Snapshots Prefix of autosnap_ those names are its own');
putenv('ZFSM_TEST_NAMES_FAIL=1');
same(array(array('tank' => array('tank', 'tank/sanoid', 'tank/sanoid/kid', 'tank/synced', 'tank/plain')), 'cannot iterate filesystems: Input/output error'), $covered('auto'),
	'names it can\'t read: each dataset listed is taken, and why is given');
putenv('ZFSM_TEST_NAMES_FAIL');
same('Saved: a snapshot of each of 3 dataset(s) every Wednesday at 08:00; it keeps all it takes', zfsmScheduleSnapshotSave('', 'days', '08:00', '3', 0, $cfg)['said'], 'and counts the rest');
$sanoid = 'tank/sanoid has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool';
same(array('Nothing was saved: '.$sanoid, 'Nothing was saved: tank/synced has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool', ''),
	array(zfsmScheduleSnapshotSave('tank/sanoid', 'hourly', '', '', 0, $cfg)['why'], zfsmScheduleSnapshotSave('tank/synced', 'days', '08:00', '1', 0, $cfg)['why'], zfsmScheduleSnapshotSave('tank/sanoid', 'none', '', '', 0, $cfg)['why']),
	'its own is refused, None is not');
zfsmScheduleSnapshotSave('tank/sanoid', 'default', '', '', 0, $cfg);
$reset();
same(array($sanoid, '', 'tank/gone could not be read: cannot open \'tank/gone\': dataset does not exist'), array_column(array_map($preview, array('tank/sanoid', 'tank/sanoid/kid', 'tank/gone')), 'skipped'), 'the dialog says why');
$reads = function($dataset) { return array('list -H -o name,readonly,mountpoint -t filesystem,volume -d 1 '.$dataset, 'list -H -o name -s name -t snapshot -d 1 '.$dataset); };
same(array_merge($reads('tank/sanoid'), $reads('tank/sanoid/kid'), array($reads('tank/gone')[0])), $read('calls.log'), 'from reads of it and those just below, its names alone, and no read of names for one gone');
putenv('ZFSM_TEST_COVER_FAIL=tank/sanoid');
same('tank/sanoid could not be read: cannot open \'tank/sanoid\': Input/output error', $preview('tank/sanoid')['skipped'], 'one it can\'t read, with one below it can, says only why');
putenv('ZFSM_TEST_COVER_FAIL');
$ids = array_column($stored(), 'id', 'dataset');
$reset();
same(0, zfsmScheduleRun($ids[''], $cfg, $t + 64800), 'the default runs');
$n = $name($t + 64800);
same(array("snapshot -o zfs.master:schedule=snapshots tank@$n tank/sanoid/kid@$n tank/plain@$n"), $takes(), 'and takes none of theirs');
// One whose own was saved before it had them: the run says so.
$pool('marks', array("tank/synced@syncoid_nas_2026-11-17:00:00:01-GMT00:00\t22\t31"));
same('', zfsmScheduleSnapshotSave('tank/plain', 'days', '09:00', '4', 0, $cfg)['why'], 'tank/plain\'s own');
file_put_contents($work.'/pool/marks', "tank/plain@autosnap_2026-11-17_00:00:01_daily\t25\t34\n", FILE_APPEND);
$ids = array_column($stored(), 'id', 'dataset');
$reset();
same(array(1, array(), array('warning: Scheduled snapshots of tank/plain not taken: tank/plain has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool; remove its schedule in Settings. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array(zfsmScheduleRun($ids['tank/plain'], $cfg, $t + 68400), $takes(), $read('notify-detail.log')), 'a run of its own says so');
// One with datasets below takes them, and says why not its own.
$pool('marks', array());
same('', zfsmScheduleSnapshotSave('tank/sanoid', 'days', '10:00', '5', 0, $cfg)['why'], 'tank/sanoid\'s own');
$pool('marks', array("tank/sanoid@autosnap_2026-11-17_00:00:01_hourly\t21\t30"));
$ids = array_column($stored(), 'id', 'dataset');
$reset();
$n = $name($t + 72000);
same(array(1, array("snapshot -o zfs.master:schedule=snapshots tank/sanoid/kid@$n"), array('warning: Scheduled snapshots of 1 of 1 dataset(s) (tank/sanoid and below) taken. Not taken: '.$sanoid.'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.')),
	array(zfsmScheduleRun($ids['tank/sanoid'], $cfg, $t + 72000), $takes(), $read('notify-detail.log')), 'one with datasets below takes them, and says why not its own');

echo "snapshot schedule tests passed\n";
