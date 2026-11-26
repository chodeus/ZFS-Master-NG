<?php
// Leave Out of Snapshot Every Dataset and Replicate Every Dataset (include/ZFSMScheduleCover.php), through the real store, Save, runs
// and holders, with the fake zfs's pools (ZFSM_TEST_REPL_DIR) and Unraid's crontab rebuild faked under $GLOBALS['zfsm_root'].

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

$work = sys_get_temp_dir().'/zfsm-leaveout-'.getmypid().'-'.bin2hex(random_bytes(4));
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
require_once __DIR__.'/fixtures/schedules_stored.php';
require_once __DIR__.'/fixtures/schedule_rows.php';
$stored = 'zfsmTestStored';
$datasets = function() use ($read) { return array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/datasets')); };
$takes = function() use ($read) { return array_values(preg_grep('/^snapshot /', $read('calls.log'))); };
$logged = function($pattern) { return preg_match($pattern.'m', (string)@file_get_contents(zfsmLogFile())); };
$cfg = array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$name = function($now) use ($cfg) { return zfsmNewSnapshotName($cfg, $now); };
$wait = ZFSM_SCHEDULE_COUNT_WAIT;
// Three pools, one named appdata; appdata and backup_* at the top of tank and cache, and appdata deeper in tank/media.
$pool('datasets', array("tank\tfilesystem", "tank/appdata\tfilesystem", "tank/appdata/plex\tfilesystem", "tank/backup_a\tfilesystem", "tank/backup_a/x\tfilesystem",
	"tank/media\tfilesystem", "tank/media/appdata\tfilesystem", "cache\tfilesystem", "cache/appdata\tfilesystem", "cache/backup_b\tfilesystem", "cache/vm1\tfilesystem",
	"appdata\tfilesystem", "appdata/data\tfilesystem", "backup\tfilesystem"));
foreach (array('marks', 'props', 'holds') as $file) $pool($file, array());

// The record: a list of 1 to 100 names, each a name part with or without a * at its end, on a default that repeats.
$every = array('id' => '0a1b2c3d', 'dataset' => '', 'dest' => '', 'server' => '', 'every' => 'hourly', 'minute' => 30, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);
$with = function($names, $job = array()) use ($every) { return array('leave_out' => $names) + $job + $every; };
same(array(true, true, true, true, false, false, false, false, false, false, false, false, false, false, false),
	array_map('zfsmScheduleValid', array($every, $with(array('appdata', 'backup_*', 'my share')), $with(array('appdata'), array('dest' => 'backup/')), $with(array_fill(0, 100, 'a')),
		$with(array('appdata'), array('dataset' => 'tank')), $with(array('appdata'), array('dataset' => 'tank', 'every' => 'none')), $with(array('appdata'), array('dataset' => 'tank/data', 'dest' => 'backup/data')),
		$with(array('a' => 'appdata')), $with(array()), $with('appdata'), $with(array(7)), $with(array('cache/appdata')), $with(array('*')), $with(array('app*data')), $with(array_fill(0, 101, 'a')))),
	'which Leave Out lists a record can hold');
same(array('appdata', 'backup_*', 'my share'), zfsmLeaveOutParse(' appdata , backup_*,, appdata,my share '), 'parsed: trimmed, each once, in order');

// Save refuses a wrong name, saying how to fix it, and saves nothing.
$refused = function($text) use ($cfg, $wait) { return zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg, $wait, zfsmLeaveOutParse($text))['why']; };
same(array('Nothing was saved: under Leave Out, give a name without its pool, such as appdata, not cache/appdata',
	'Nothing was saved: under Leave Out, x?y is no dataset name; give names such as appdata or backup_*, split by commas',
	'Nothing was saved: under Leave Out, * is no dataset name; give names such as appdata or backup_*, split by commas',
	'Nothing was saved: under Leave Out, app*data is no dataset name; give names such as appdata or backup_*, split by commas',
	'Nothing was saved: under Leave Out, give 100 names at most'),
	array_map($refused, array('appdata, cache/appdata', 'x?y', '*', 'app*data', implode(',', array_map(function($i) { return 'a'.$i; }, range(0, 100))))), 'what is wrong is said');
same(array(null, false), array($stored(), is_file(zfsmScheduleCronFile())), 'and nothing is saved');

// Saved: the list as typed, and the answer counts what it leaves out: the top-level matches and all below them, not the pool appdata
// or tank/media/appdata.
$saved = zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg, $wait, zfsmLeaveOutParse('appdata, backup_*, appdata'));
$job = $stored()[0];
$id = $job['id'];
$at = ':'.sprintf('%02d', $job['minute']);
same(array('appdata', 'backup_*'), $job['leave_out'], 'saved, each once');
same(1, preg_match('/"leave_out": \[\s*"appdata",\s*"backup_\*"\s*\]/', (string)file_get_contents(zfsmScheduleFile())), 'as a list in schedules.json');
same("Saved: a snapshot of each of 4 dataset(s) every hour at $at; it keeps all it takes. Leave Out leaves out 6 dataset(s)", $saved['said'], 'Save counts what it leaves out');
same(array(array('Snapshots', 'Every dataset | Leaves out appdata, backup_*', '', 'every hour at '.$at, 'all', '')), zfsmTestScheduleRows(zfsmSchedulesHtml($cfg)), 'the Settings row says it, under Every dataset');
same('appdata, backup_*', zfsmLeaveOutField(zfsmScheduleShown()), 'and the field shows it');
// A name no dataset at the top of a pool matches is said (plex is deeper), but only when zfs read every dataset.
$save = function($text) use ($cfg, $wait) { return zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg, $wait, zfsmLeaveOutParse($text))['said']; };
same("Saved: a snapshot of each of 7 dataset(s) every hour at $at; it keeps all it takes. Leave Out leaves out 3 dataset(s); nothing matches bkup_*, plex", $save('appdata, bkup_*, plex'),
	'Save names each that matches none');
putenv('ZFSM_TEST_COVER_FAIL=cache/vm1');
$unread = $save('appdata, bkup_*, plex');
putenv('ZFSM_TEST_COVER_FAIL');
same(array(1, 0), array(preg_match('/; not every dataset could be read: /', $unread), substr_count($unread, 'Leave Out')), 'not with a dataset unread');
same(array($id, $job['minute']), array($stored()[0]['id'], $stored()[0]['minute']), 'its id and minute stay');
$save('appdata, backup_*');

// A run takes neither those nor any below them, in every pool, but tank/media/appdata, and no pool's own dataset; the log says how many.
$reset();
$t = 1800000000;
same(0, zfsmScheduleRun($id, $cfg, $t), 'a run works');
$n = $name($t);
$snap = 'snapshot -o zfs.master:schedule=snapshots ';
same(array("{$snap}tank/media@$n tank/media/appdata@$n", "{$snap}cache/vm1@$n", "{$snap}appdata/data@$n"), $takes(), 'what a run takes');
same(1, $logged('/ Schedule '.$id.': Scheduled snapshots of 4 of 4 dataset\(s\) taken, 6 left out by Leave Out$/'), 'logged with the count');
// One below with a schedule of its own: that one takes it, and the default no longer counts it.
same(true, zfsmScheduleSnapshotSave('tank/appdata/plex', 'hourly', '', '', 0, $cfg)['saved'], 'its own saved');
$own = array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/appdata/plex'; }))[0]['id'];
$reset();
same(array(0, 0), array(zfsmScheduleRun($id, $cfg, $t + 3600), zfsmScheduleRun($own, $cfg, $t + 3600)), 'both run');
$n = $name($t + 3600);
same(array("{$snap}tank/media@$n tank/media/appdata@$n", "{$snap}cache/vm1@$n", "{$snap}appdata/data@$n", "{$snap}tank/appdata/plex@$n"), $takes(),
	'its own takes it');
same(1, $logged('/ Schedule '.$id.': Scheduled snapshots of 4 of 4 dataset\(s\) taken, 5 left out by Leave Out$/'), 'one fewer left out');

// Replicate Every Dataset: its own list; a run copies none it leaves out, nor their children.
$saved = zfsmScheduleCopiesSave('backup', 'hourly', '', '', 0, $cfg, null, '', $wait, zfsmLeaveOutParse('appdata, backup_*'));
$copies = array_values(array_filter($stored(), 'zfsmScheduleCopiesUnder'))[0];
same(array('appdata', 'backup_*'), $copies['leave_out'] ?? null, 'saved on its record');
same('Saved: a copy of each of 4 dataset(s) under backup every hour at :'.sprintf('%02d', $copies['minute']).'; it keeps all its snapshots. Leave Out leaves out 6 dataset(s)', $saved['said'], 'Save counts');
same(array(array('Snapshots', 'Every dataset | Leaves out appdata, backup_*', '', 'every hour at '.$at, 'all', ''),
	array('Copies', 'Every dataset | Leaves out appdata, backup_*', 'under backup', 'every hour at :'.sprintf('%02d', $copies['minute']), 'all', '')),
	zfsmTestScheduleRowsOf(zfsmSchedulesHtml($cfg), 'Every dataset'), 'the Settings row, after Snapshot Every Dataset\'s');
$reset();
same(0, zfsmScheduleRun($copies['id'], $cfg, $t + 7200), 'a run of copies works');
same(array('backup/appdata', 'backup/appdata/data', 'backup/cache', 'backup/cache/vm1', 'backup/tank', 'backup/tank/media', 'backup/tank/media/appdata'),
	array_values(preg_grep('~^backup/~', $datasets())), 'what it copies');
same(array(true, true, true, false), array_map(function($zdest) { return zfsmReplicaHolder(array('server' => ''), $zdest); }, array('backup/appdata', 'backup/cache', 'backup/tank', 'backup/tank/media')),
	'each pool\'s own dataset held, not copied');
same(1, $logged('/ Schedule '.$copies['id'].': Scheduled copies under backup: 4 of 4 dataset\(s\) copied, 6 left out by Leave Out$/'), 'logged with the count');
// A dataset below one left out with copies of its own under backup: a holder takes the place of the one left out, as no copy is due there.
$plex = array('id' => 'cccc0000', 'dataset' => 'tank/appdata/plex') + $copies;
unset($plex['leave_out']);
file_put_contents(zfsmScheduleFile(), json_encode(array_merge($stored(), array($plex))));
$reset();
same(0, zfsmScheduleRun('cccc0000', $cfg, $t + 10800), 'its own run works');
same(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/tank/appdata'), array_values(preg_grep('/^create /', $read('calls.log'))), 'a holder in place of the one left out');
same(array(true, true), array(in_array('backup/tank/appdata/plex', $datasets(), true), zfsmReplicaHolder(array('server' => ''), 'backup/tank/appdata')), 'and the copy under it');
// The count leaves out what a run skips anyway: backup/appdata, the read-only holder of the copy of appdata/data, matches but is not counted.
$reset();
same(array(0, 0), array(zfsmScheduleRun($id, $cfg, $t + 14400), zfsmScheduleRun($copies['id'], $cfg, $t + 14460)), 'both defaults run again, a minute apart');
same(array(1, 1), array($logged('/ Schedule '.$id.': Scheduled snapshots of 4 of 4 dataset\(s\) taken, 5 left out by Leave Out$/'),
	$logged('/ Schedule '.$copies['id'].': Scheduled copies under backup: 4 of 4 dataset\(s\) copied, 5 left out by Leave Out$/')), 'counted: only those it would take');

// A rename the Leave Out of a default it follows matches on one side only says so; one below a dataset at the top, or with its own, doesn't.
$renamed = function($from, $to) use ($cfg) { return renameDataset($from, $to, 0, $cfg)['failed']; };
same(array('Snapshot Every Dataset' => 'its Leave Out (appdata) matched only the old name, so it no longer leaves out cache/apps',
	'Replicate Every Dataset' => 'its Leave Out (appdata) matched only the old name, so it no longer leaves out cache/apps'), $renamed('cache/appdata', 'cache/apps'), 'renamed out of Leave Out');
same(array('Snapshot Every Dataset' => 'its Leave Out (backup_*) matches the new name, so it now leaves out cache/backup_vm',
	'Replicate Every Dataset' => 'its Leave Out (backup_*) matches the new name, so it now leaves out cache/backup_vm'), $renamed('cache/vm1', 'cache/backup_vm'), 'renamed into it');
same(array(array(), array(), array()), array($renamed('tank/backup_a', 'tank/backup_b'), $renamed('tank/backup_b/x', 'tank/backup_b/y'), $renamed('tank/appdata/plex', 'tank/plex')),
	'no change, below the top, or its own');
// A dataset's own schedule takes no Leave Out: one sent with it is saved without it.
same(true, zfsmScheduleSnapshotSave('tank/media', 'hourly', '', '', 0, $cfg, $wait, array('appdata'))['saved'], 'a dataset\'s own saved');
same(false, array_key_exists('leave_out', array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/media'; }))[0]), 'without a Leave Out');
same(array('Replicate Every Dataset' => 'its Leave Out (backup_*) matches the new name, so it now leaves out tank/backup_c'), $renamed('tank/media/appdata', 'tank/backup_c'),
	'only a default it followed before');
// A dataset left out of copies needs no room for a copy's bookmark.
$long = 'tank/backup_b/'.str_repeat('x', 220);
same(array('succeeded' => array($long => 0), 'failed' => array()), renameDataset('tank/backup_b/y', $long, 0, $cfg), 'one left out takes a long name');

// Off removes the record and its list; on again with none saves none.
zfsmScheduleSnapshotSave('', 'off', '', '', 0, $cfg, $wait, zfsmLeaveOutParse('appdata'));
same(array(), array_values(array_filter($stored(), function($job) { return $job['dataset'] === '' && $job['dest'] === ''; })), 'Off removes it');
zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg, $wait, array());
$again = array_values(array_filter($stored(), function($job) { return $job['dataset'] === '' && $job['dest'] === ''; }))[0];
same(array(false, array('Snapshots', 'Every dataset', '', 'every hour at :'.sprintf('%02d', $again['minute']), 'all', ''), 'Copies'),
	array(array_key_exists('leave_out', $again), zfsmTestScheduleRows(zfsmSchedulesHtml($cfg))[0] ?? null, zfsmTestScheduleRows(zfsmSchedulesHtml($cfg))[1][0] ?? null), 'on again with none: no list, nor a line under its row');

echo "schedule leave out tests passed\n";
