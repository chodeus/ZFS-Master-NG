<?php
// Snapshot Every Dataset (include/ZFSMScheduleSnapshot.php) through the real store, cron file, runs and Keep, with the fake zfs's
// small pool (ZFSM_TEST_REPL_DIR) and Unraid's crontab rebuild faked under $GLOBALS['zfsm_root'].

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

// The record: no dataset, copy or server; one with a dataset or a server is no schedule this version runs.
$every = array('id' => '0a1b2c3d', 'dataset' => '', 'dest' => '', 'server' => '', 'every' => 'hourly', 'minute' => 30, 'hour' => 0, 'days' => range(0, 6), 'keep' => 2);
same(array(true, false, false, true), array_map('zfsmScheduleValid', array($every, array('dataset' => 'tank/data') + $every, array('server' => 'nas') + $every, $copy)), 'the snapshot schedule of every dataset is one');
same(array(array('tank' => array('tank', 'tank/data', 'tank/docker', 'tank/vm'), 'backup' => array('backup')), ''), zfsmScheduleCovered(runProcess('zfs', ZFSM_SCHEDULE_COVERED)), 'it covers every dataset but read-only and legacy ones, by pool');

// Off with none saves none; Settings shows Off.
same(array('saved' => true, 'why' => '', 'said' => 'Snapshot Every Dataset is off; the snapshots it took stay'), zfsmScheduleSnapshotSave('off', '', '', 0, $cfg), 'off with none');
same(array(array(), 'off'), array($stored(), zfsmScheduleSnapshotShown()['every']), 'saves no record');
// Saved again every hour: its id and minute stay, though another minute is free.
zfsmScheduleSnapshotSave('hourly', '', '', 0, $cfg);
$first = array_values($stored())[0];
$again = array_map(function() use ($cfg, $stored) { zfsmScheduleSnapshotSave('hourly', '', '', 0, $cfg); return array_values($stored()); }, range(1, 3));
same(array_fill(0, 3, array($first)), $again, 'its id and minute stay');

// Every hour: at the one minute no copy schedule takes, as the copy's would clash; nothing is taken on saving.
$copies = array_map(function($minute) use ($copy) { return array('id' => sprintf('%08x', $minute + 1), 'minute' => $minute, 'dest' => 'backup/c'.$minute) + $copy; }, array_diff(range(0, 59), array(30)));
file_put_contents(zfsmScheduleFile(), json_encode(array_merge($copies, array(array('id' => 'zz')))));
$reset();
$saved = zfsmScheduleSnapshotSave('hourly', '', '', 2, $cfg);
$id = array_values(array_filter(array_keys(zfsmScheduleRead()['valid']), function($id) use ($copies) { return !in_array($id, array_column($copies, 'id'), true); }))[0];
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each of 5 dataset(s) every hour at :30; it keeps the newest 2 it took of each dataset'), $saved, 'saved at the minute left free');
same(array_replace($every, array('id' => $id)), zfsmScheduleRead()['valid'][$id], 'as the record');
same(array(array('id' => 'zz'), array('ran'), array()), array(array_slice($stored(), -1)[0], $read('cron.log'), $takes()), 'a record it can\'t use stays; the crontab is rebuilt; nothing is taken');
same(1, substr_count((string)file_get_contents(zfsmScheduleCronFile()), "30 * * * * $script run $id &> /dev/null\n"), 'its cron line');
same(true, strpos(zfsmSchedulesHtml($cfg), '<span style="display:block">Snapshot Every Dataset, every hour at :30, keeps the newest 2 it took of each dataset</span>') !== false, 'Settings lists it');
same(false, strpos(zfsmSchedulesHtml($cfg), "zfsmScheduleRemove('$id')"), 'with no Remove: its own row turns it off');
// A copy schedule can't take its minute now, nor start then.
$but31 = array_filter(zfsmScheduleRead()['valid'], function($job) { return $job['minute'] !== 31; });
same(array(31), array_values(array_unique(array_map(function() use ($but31, $copy) { return zfsmScheduleFreeMinute($but31, array('dest' => 'backup/new') + $copy); }, range(1, 20)))), 'an hourly copy\'s minute is never the snapshot schedule\'s');
same('Snapshot Every Dataset (every hour at :30) starts then too; pick another time', zfsmScheduleSame(zfsmScheduleRead()['valid'], array('every' => 'days', 'minute' => 30, 'hour' => 4, 'dest' => 'backup/new') + $copy), 'nor a copy at that minute');

// Saved again, in place: its id and minute stay, and Keep changes.
same('Saved: a snapshot of each of 5 dataset(s) every hour at :30; it keeps all it takes', zfsmScheduleSnapshotSave('hourly', '', '', 0, $cfg)['said'], 'Keep changes in place');
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
same(array_column($wrong, 4), array_map(function($try) use ($cfg) { return zfsmScheduleSnapshotSave($try[0], $try[1], $try[2], $try[3], $cfg)['why']; }, $wrong), 'what is wrong is said');
same(array_replace($every, array('id' => $id)), zfsmScheduleRead()['valid'][$id], 'and it stays as it was');
same(1, preg_match('/^Nothing was saved: Snapshots Pattern would give the run at .+ the same snapshot name as the run at .+; add the time to Snapshots Pattern$/D', zfsmScheduleSnapshotSave('hourly', '', '', 2, array('snap_pattern' => 'Ymd') + $cfg)['why']), 'a pattern that names two runs alike');
// On days of the week: the days in order, its own time.
same('Saved: a snapshot of each of 5 dataset(s) every Monday and Saturday at 04:00; it keeps the newest 3 it took of each dataset', zfsmScheduleSnapshotSave('days', '04:00', '6,1', 3, $cfg)['said'], 'on days');
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
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each dataset every hour at :30; it keeps the newest 2 it took of each dataset; not every dataset could be read: cannot iterate filesystems: Input/output error'),
	(function() use ($cfg) { putenv('ZFSM_TEST_COVER_FAIL=1'); $saved = zfsmScheduleSnapshotSave('hourly', '', '', 2, $cfg); putenv('ZFSM_TEST_COVER_FAIL'); return $saved; })(), 'a save says it could not count them');
putenv('ZFSM_TEST_COVER_FAIL=hang');
$began = microtime(true);
$saved = zfsmScheduleSnapshotSave('hourly', '', '', 2, $cfg, 1);
putenv('ZFSM_TEST_COVER_FAIL');
same(array(true, 'Saved: a snapshot of each dataset every hour at :30; it keeps the newest 2 it took of each dataset; not every dataset could be read: zfs timed out', true),
	array($saved['saved'], $saved['said'], microtime(true) - $began < 10), 'a count that hangs stops at its limit');
// One dataset zfs can't read: the rest are taken and counted, and it is named.
$reset();
@unlink(zfsmScheduleStateFile($id));
putenv('ZFSM_TEST_COVER_FAIL=tank/vm');
same(1, zfsmScheduleRun($id, $cfg, $t + 21600), 'a run that can\'t read one dataset');
same('Saved: a snapshot of each of 4 dataset(s) every hour at :30; it keeps the newest 2 it took of each dataset; not every dataset could be read: cannot open \'tank/vm\': Input/output error',
	zfsmScheduleSnapshotSave('hourly', '', '', 2, $cfg)['said'], 'and a save counts the rest and names it');
putenv('ZFSM_TEST_COVER_FAIL');
$n = $name($t + 21600);
same(array("snapshot -o zfs.master:schedule=snapshots tank@$n tank/data@$n tank/docker@$n", "snapshot -o zfs.master:schedule=snapshots backup@$n"), $takes(), 'takes the rest, every pool');
same(array("warning: Scheduled snapshots of 4 of 4 dataset(s) taken. Not every dataset could be read: cannot open ?tank/vm?: Input/output error. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again."),
	$read('notify-detail.log'), 'and says which it could not read');
// A read that hangs stops at its limit; a run with no dataset to take is logged only, so the last problem is not "worked again".
$job = zfsmScheduleRead()['valid'][$id];
putenv('ZFSM_TEST_COVER_FAIL=hang');
$began = microtime(true);
$ran = zfsmScheduleSnapshotRun($job, $cfg, $t + 25200, zfsmScheduleNotifier($id), 1);
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
same(array('saved' => true, 'why' => '', 'said' => 'Snapshot Every Dataset is off; the snapshots it took stay'), zfsmScheduleSnapshotSave('off', '', '', 0, $cfg), 'off');
same(array(array(), null, false, $kept, 2), array($stored(), is_file(zfsmScheduleCronFile()) ? file_get_contents(zfsmScheduleCronFile()) : null, is_file(zfsmScheduleStateFile($id)), $tagged('backup'), count($kept)), 'nothing left to run, the snapshots stay');

echo "snapshot schedule tests passed\n";
