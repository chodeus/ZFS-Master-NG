<?php
// Main's Schedules dialog (include/ZFSMScheduleDialog.php): a dataset's own snapshots and copies under a dataset, saved together, and
// a rename that takes its copies along; through the real store, runs and the fake zfs's small pool (ZFSM_TEST_REPL_DIR).

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

// Unraid's nchan publish, which a run calls as it sends.
function publish($channel, $message) {
}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-dialog-'.getmypid().'-'.bin2hex(random_bytes(4));
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
$datasets = function() use ($read) { return array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/datasets')); };
$cfg = array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/data/sub\tfilesystem", "tank/docker\tfilesystem", "tank/docker/0a1b\tfilesystem",
	"usb\tfilesystem", "backup\tfilesystem"));
$pool('marks', array());
$pool('props', array("tank/docker/0a1b\tmountpoint\tlegacy"));
$pool('holds', array());
$copies = function($zdataset) use ($cfg) { return zfsmScheduleDialogPreview($zdataset, $cfg)['copies']; };
$fields = function($every, $time = '00:00', $days = null, $keep = '') { return array('every' => $every, 'time' => $time, 'days' => $days ?? range(0, 6), 'keep' => $keep); };

// The dialog's start: Replication follows Replicate Every Dataset, off at first.
same($fields('default') + array('under' => '', 'inherited' => 'Replicate Every Dataset, which is off: no scheduled copies', 'skipped' => ''), $copies('tank/data'), 'nothing to follow');
same(true, zfsmScheduleCopiesSave('backup', 'hourly', '', '', 0, $cfg)['saved'], 'Replicate Every Dataset saved');
$m = array_values($stored())[0]['minute'];
$minute = sprintf(':%02d', $m);
// A time Replicate Every Dataset starts at, as it copies tank/data each hour, and one it doesn't.
$clash = sprintf('05:%02d', $m);
$free = sprintf('05:%02d', ($m + 10) % 60);
$every = 'Replicate Every Dataset, under backup every hour at '.$minute.'; it keeps all its snapshots';
same($fields('default') + array('under' => 'backup', 'inherited' => $every, 'skipped' => ''), $copies('tank/data'), 'Default shows what it follows, and Copy Under starts at its');
same(array('backup is in pool backup, where its copy would go', ''), array($copies('backup')['skipped'], $copies('tank/docker/0a1b')['skipped']),
	'why what it follows leaves it out, past what Snapshots says');

// Save: what is wrong in either part is said, by part, and nothing saved.
$save = function($zdataset, $snapshots, $copies) use ($cfg) { return zfsmScheduleDialogSave($zdataset, $snapshots, $copies, $cfg); };
$part = function($every, $time = '', $days = '', $keep = 0, $under = '') { return array('every' => $every, 'time' => $time, 'days' => $days, 'keep' => $keep, 'under' => $under); };
$before = $stored();
$refused = array(
	array('tank/data', $part('days'), $part('hourly'), 'Nothing was saved: Snapshots: set the time it starts at; tick at least one day; Replication: under Copy Under, name a dataset on this server, such as backup'),
	array('tank/data', $part('default'), $part('hourly', '', '', 0, 'nowhere'), 'Nothing was saved: Replication: nowhere does not exist on this server'),
	array('usb', $part('default'), $part('hourly', '', '', 0, 'usb'), 'Nothing was saved: Replication: usb is in pool usb, where its copy would go'),
	array('tank/docker/0a1b', $part('hourly'), $part('hourly', '', '', 0, 'usb'), 'Nothing was saved: Snapshots: tank/docker/0a1b is mounted legacy, as Docker image layers are; Replication: tank/docker/0a1b is mounted legacy, as Docker image layers are'),
	array('tank/data', $part('days', $clash, '1'), $part('default'), 'Nothing was saved: Snapshots: Replicate Every Dataset (every hour at '.$minute.') starts then too; pick another time'),
	array('tank/data', $part('days', $free, '1'), $part('days', $free, '1,2', 0, 'usb'), 'Nothing was saved: Replication: the snapshot schedule of tank/data (every Monday at '.$free.') starts then too; pick another time'),
	array('tank/data', $part('off'), $part('default'), 'Nothing was saved: Snapshots: Repeat has no such choice now; reload the page and pick again'),
);
same(array_column($refused, 3), array_map(function($try) use ($save) { return $save($try[0], $try[1], $try[2])['why']; }, $refused), 'what is wrong is said');
same($before, $stored(), 'none saved');

// Both parts saved in one change, each said; the copies' own record goes under its Copy Under.
$reset();
$saved = $save('tank/data', $part('days', $free, '1', 1), $part('hourly', '', '', 2, 'usb'));
$own = array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; }));
same(array('', 'usb/'), array_column($own, 'dest'), 'two records of tank/data');
$at = sprintf(':%02d', $own[1]['minute']);
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a snapshot of each of 2 dataset(s) (tank/data and below) every Monday at '.$free.'; it keeps the newest 1 scheduled snapshots of each dataset. '
	.'Saved: a copy of each of 2 dataset(s) (tank/data and below) under usb every hour at '.$at.'; it keeps the newest 2 of the snapshots of each dataset it copies'), $saved, 'each part said');
same(array(1, array()), array(count($read('cron.log')), preg_grep('/^(snapshot|send|receive|create) /', $read('calls.log'))), 'one crontab rebuild, nothing taken or copied');
same($fields('hourly', '00:00', null, '2') + array('under' => 'usb', 'inherited' => $every, 'skipped' => ''), $copies('tank/data'), 'the dialog starts at its own');
same(array('default', 'usb', 'the copies of tank/data and below, under usb every hour at '.$at.'; it keeps the newest 2 of the snapshots of each dataset it copies'),
	array($copies('tank/data/sub')['every'], $copies('tank/data/sub')['under'], $copies('tank/data/sub')['inherited']), 'the one below follows it');
same(1, preg_match('/>Copies of tank\/data and below under usb, every hour at '.$at.', keeps the newest 2 of the snapshots of each dataset it copies </', zfsmSchedulesHtml($cfg)), 'Settings lists it');

// None: no copies of it and below; Copy Under starts at what Default would follow.
same('Saved: tank/data follows Snapshot Every Dataset, which is off: no scheduled snapshots. Saved: no scheduled copies of tank/data and below, but for datasets with their own; the copies made stay',
	$save('tank/data', $part('default'), $part('none', '', '', 0, 'usb'))['said'], 'None, with the Copy Under the page still sends');
same(array(array('/'), 'none', 'backup', 'the copies of tank/data and below, None: no scheduled copies'),
	array(array_column(array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; })), 'dest'), $copies('tank/data')['every'], $copies('tank/data')['under'], $copies('tank/data/sub')['inherited']), 'stored as None');
same('Saved: tank/data follows Snapshot Every Dataset, which is off: no scheduled snapshots. Saved: the copies of tank/data follow '.$every,
	$save('tank/data', $part('default'), $part('default'))['said'], 'Default removes it');
same(array(), array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; })), 'none of its own stay');

// Rename: the copy under each dataset copies go under takes the new name, the datasets below it with it, and stays incremental.
$id = array_values($stored())[0]['id'];
$t = 1800000000;
$sends = function($pattern) use ($read) { return count(preg_grep($pattern, $read('calls.log'))); };
same(0, zfsmScheduleRun($id, $cfg, $t), 'the first copies');
same(array('succeeded' => array('tank/new' => 0), 'failed' => array()), renameDataset('tank/data', 'tank/new', 0), 'a rename');
same(array('backup/tank/new', 'backup/tank/new/sub'), array_values(preg_grep('~^backup/tank/(data|new)~', $datasets())), 'its copy and the one below follow it');
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 3600), 'the next run');
same(array(1, 0), array($sends('~^send -P -v -L -i \S+ tank/new@~'), $sends('~^send -P -v -L tank/new@~')), 'sends only the changes to the renamed copy');
// Into a dataset whose copy is not made yet: an empty holder takes its place first, as zfs rename needs the parent.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/deep\tfilesystem")));
$reset();
same(array('succeeded' => array('tank/deep/data' => 0), 'failed' => array()), renameDataset('tank/new', 'tank/deep/data', 0), 'a rename into a new dataset');
same(array(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/tank/deep'), true, true),
	array(array_values(preg_grep('/^create /', $read('calls.log'))), zfsmReplicaHolder(array('server' => ''), 'backup/tank/deep'), in_array('backup/tank/deep/data/sub', $datasets(), true)), 'a holder, then the copy');

// When it can't, the rename still stands and each copy left behind says why, by its name.
$copy = function($zdataset) { return 'Copy backup/'.$zdataset; };
putenv('ZFSM_TEST_RENAME_FAIL=backup/tank/deep/data');
same(array('succeeded' => array('tank/moved' => 0), 'failed' => array($copy('tank/deep/data') => 'not renamed with it: cannot rename \'backup/tank/deep/data\': dataset is busy. It stays under its old name, so the next copy of the renamed dataset is a full one')),
	renameDataset('tank/deep/data', 'tank/moved', 0), 'a copy that is busy');
putenv('ZFSM_TEST_RENAME_FAIL');
$add = function($names) use ($pool, $read) { $pool('datasets', array_merge($read('pool/datasets'), array_map(function($name) { return $name."\tfilesystem"; }, $names))); };
$add(array('backup/tank/again'));
same(array($copy('tank/docker') => 'left where it is, as a dataset already has its new name'), renameDataset('tank/docker', 'tank/again', 0)['failed'], 'a name already taken there');
$add(array('tank/r', 'backup/tank/r', 'tank/q', 'backup/tank/q'));
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/r');
same(array($copy('tank/r') => 'not renamed with it, as whether it is there could not be read'), renameDataset('tank/r', 'tank/r2', 0)['failed'], 'a copy that could not be read');
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/q2');
same(array($copy('tank/q') => 'not renamed with it, as whether its new name is free could not be read'), renameDataset('tank/q', 'tank/q2', 0)['failed'], 'a new name that could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
$add(array('tank/up', 'tank/u', 'backup/tank/u'));
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/up');
same(array($copy('tank/u') => 'not renamed with it: the datasets above its new name could not be read. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	renameDataset('tank/u', 'tank/up/u', 0)['failed'], 'one whose new parent could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
// One whose own Replication is None: nothing copies it there, so its copy stays.
$add(array('tank/n', 'backup/tank/n'));
same(true, $save('tank/n', $part('default'), $part('none'))['saved'], 'None');
same(array($copy('tank/n') => 'left where it is, as no schedule copies the renamed dataset there'), renameDataset('tank/n', 'tank/final', 0)['failed'], 'nothing copies it there');
same(array(true, false), array(in_array('backup/tank/n', $datasets(), true), in_array('backup/tank/final', $datasets(), true)), 'it stays');

// One below it still copied there: the holder over that copy follows the rename too.
$add(array('tank/p', 'tank/p/kid', 'backup/tank/p', 'backup/tank/p/kid'));
same(array(true, true), array($save('tank/p', $part('default'), $part('none'))['saved'], $save('tank/p/kid', $part('default'), $part('hourly', '', '', 0, 'backup'))['saved']), 'None above, its own below');
same(array('succeeded' => array('tank/p2' => 0), 'failed' => array()), renameDataset('tank/p', 'tank/p2', 0), 'a rename of the one above');
same(array('backup/tank/p2', 'backup/tank/p2/kid'), array_values(preg_grep('~^backup/tank/p~', $datasets())), 'its copy and the one below follow it');
// Its schedules not moved, as another change holds them: its copies follow as they would have, ready for its schedules set again.
$lock = zfsmJobLock(zfsmJobDir('schedule').'/settings.lock', false);
same(array('Schedules of tank/p2'), array_keys(renameDataset('tank/p2', 'tank/p3', 0)['failed']), 'only the schedules are said');
zfsmJobUnlock($lock);
same(array('backup/tank/p3', 'backup/tank/p3/kid'), array_values(preg_grep('~^backup/tank/p~', $datasets())), 'its copies follow it');
// One with no copy there: nothing to say.
same(array('succeeded' => array('tank/r3' => 0), 'failed' => array()), renameDataset('tank/r2', 'tank/r3', 0), 'one with no copy');
same(array('saved' => false, 'why' => 'Nothing was saved: that is no dataset name', 'said' => ''), $save('', $part('hourly'), $part('hourly', '', '', 0, 'usb')), 'the dialog saves no Settings schedule');

// Destroy leaves its copies.
same(array('succeeded' => array('tank/p3' => 0), 'failed' => array()), destroyDataset('tank/p3', 1), 'a destroy');
same(array('backup/tank/p3', 'backup/tank/p3/kid'), array_values(preg_grep('~^backup/tank/p~', $datasets())), 'its copies stay');

echo "schedule dialog tests passed\n";
