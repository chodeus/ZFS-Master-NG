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
// Its Copy Under offline: Snapshots set to None saves beside the copies as saved; copies changed are checked.
$all = $read('pool/datasets');
$pool('datasets', array_values(preg_grep('~^usb[\t/]~', $all, PREG_GREP_INVERT)));
$down = $save('tank/data', $part('none'), $part('hourly', '', '', 2, 'usb'));
$offline = array($down['saved'], preg_match('/ under usb every hour at :\d\d; it keeps the newest 2 of the snapshots of each dataset it copies; no run copies while usb does not exist on this server$/', $down['said']));
$offline[] = array_column(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data' && $job['dest'] === ''; }), 'every');
$offline[] = $save('tank/data', $part('none'), $part('hourly', '', '', 3, 'usb'))['why'];
$pool('datasets', $all);
same(array(true, 1, array('none'), 'Nothing was saved: Replication: usb does not exist on this server'), $offline,
	'with its Copy Under offline, Snapshots None saves and says no run copies: '.$down['said'].'; changed copies are refused');
// Sanoid snapshots on it since: Replication set to None saves beside the snapshots as saved; snapshots changed are checked.
$late = array($save('tank/data', $part('days', $free, '1', 1), $part('hourly', '', '', 2, 'usb'))['saved']);
$marks = $read('pool/marks');
$pool('marks', array_merge($marks, array("tank/data@autosnap_2027-01-15_00:00:00_daily\t9101\t900")));
$late[] = $save('tank/data', $part('days', $free, '1', 1), $part('none', '', '', 0, 'usb'))['saved'];
$late[] = array_column(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data' && $job['dest'] !== ''; }), 'every');
$late[] = $save('tank/data', $part('days', $free, '1', 2), $part('none', '', '', 0, 'usb'))['why'];
$pool('marks', $marks);
same(array(true, true, array('none'), 'Nothing was saved: Snapshots: tank/data has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool'), $late,
	'with Sanoid snapshots on it since, Replication None saves; changed snapshots are refused');

// Snapshots Pattern since names a day's runs alike: a part as saved isn't refused for it, either way round; a changed one is.
$daily = function($snapshots, $copies) use ($cfg) { return zfsmScheduleDialogSave('tank/data', $snapshots, $copies, array('snap_pattern' => 'Ymd') + $cfg); };
$named = array($save('tank/data', $part('days', $free, '1', 1), $part('hourly', '', '', 2, 'usb'))['saved']);
$named[] = $daily($part('none'), $part('hourly', '', '', 2, 'usb'))['saved'];
$named[] = preg_match('/^Nothing was saved: Replication: Snapshots Pattern would give the run at .* the same snapshot name as .*; add the time to Snapshots Pattern$/',
	$daily($part('none'), $part('hourly', '', '', 3, 'usb'))['why']);
$named[] = $save('tank/data', $part('hourly', '', '', 1), $part('hourly', '', '', 2, 'usb'))['saved'];
$named[] = $daily($part('hourly', '', '', 1), $part('none', '', '', 0, 'usb'))['saved'];
same(array(true, true, 1, true, true), $named, 'Snapshots None saves beside copies as saved, and Replication None beside snapshots; changed copies are refused');
// Copies at a time the hourly snapshots start in: the snapshots move to another minute. Snapshots set to the copies' time as saved
// are checked against them.
$minute = function() use ($stored) { return array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data' && $job['dest'] === ''; }))[0]['minute']; };
$was = $minute();
$beside = array($save('tank/data', $part('hourly', '', '', 1), $part('days', sprintf('04:%02d', $was), '1', 2, 'usb'))['saved']);
$beside[] = $minute() !== $was;
$beside[] = $save('tank/data', $part('none'), $part('days', $free, '1', 2, 'usb'))['saved'];
$beside[] = $save('tank/data', $part('days', $free, '1', 1), $part('days', $free, '1', 2, 'usb'))['why'];
$beside[] = $save('tank/data', $part('days', $free, '1', 1), $part('none', '', '', 0, 'usb'))['saved'];
same(array(true, true, true, 'Nothing was saved: Snapshots: the copies of tank/data and below (every Monday at '.$free.') starts then too; pick another time', true), $beside,
	'hourly snapshots clear the copies\' time; snapshots at the time of copies as saved are refused');
// A dataset's own of either kind, None too, takes it and those below from the one above: no clash with that one.
$nearer = array($save('tank/data/sub', $part('none'), $part('days', $free, '1', 2, 'usb'))['why']);
$nearer[] = zfsmScheduleDialogSave('tank/data/sub', $part('none'), $part('days', '06:00', '1', 2, 'usb'), array('snap_pattern' => 'Ymd') + $cfg)['why'];
$nearer[] = $save('tank/docker', $part('days', $clash, '1'), $part('days', $free, '1', 2, 'usb'))['why'];
$save('tank/data/sub', $part('default'), $part('default'));
$save('tank/docker', $part('default'), $part('default'));
same(array('', '', ''), $nearer, 'copies below snapshots set to None there, and snapshots of one with its own copies, clash with neither above');

// None: no copies of it and below; Copy Under starts at what Default would follow.
same('Saved: tank/data follows Snapshot Every Dataset, which is off: no scheduled snapshots. Saved: no scheduled copies of tank/data and below, but for datasets with their own and repeats set in Replicate; the copies made stay',
	$save('tank/data', $part('default'), $part('none', '', '', 0, 'usb'))['said'], 'None, with the Copy Under the page still sends');
same(array(array('/'), 'none', 'backup', 'the copies of tank/data and below, None: no scheduled copies'),
	array(array_column(array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; })), 'dest'), $copies('tank/data')['every'], $copies('tank/data')['under'], $copies('tank/data/sub')['inherited']), 'stored as None');
same('Saved: tank/data follows Snapshot Every Dataset, which is off: no scheduled snapshots. Saved: the copies of tank/data follow '.$every,
	$save('tank/data', $part('default'), $part('default'))['said'], 'Default removes it');
same(array(), array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; })), 'none of its own stay');
// Each part is checked against the other as saved: a time the copies leave is free for the snapshots, and the two can swap.
$free2 = sprintf('05:%02d', ($m + 20) % 60);
$pair = function($snapshots, $copies) use ($save, $part) {
	return $save('tank/data', $part('days', $snapshots, '1'), $copies === '' ? $part('none', '', '', 0, 'usb') : $part('days', $copies, '1', 0, 'usb'))['why'];
};
$times = function() use ($stored) {
	$at = array();
	foreach ($stored() as $job) if ($job['dataset'] === 'tank/data') $at[$job['dest']] = sprintf('%02d:%02d', $job['hour'], $job['minute']);
	ksort($at);
	return $at;
};
same(array(array('', '', '', ''), array('' => $free2, 'usb/' => $free)), array(array($pair($free, $free2), $pair($free2, ''), $pair($free, $free2), $pair($free2, $free)), $times()),
	'each pair saved, none refused, and the swap stands');
// Names too: under a pattern of hours, a copies record set aside does not clash with a snapshot time in its hour, and it stays.
$old = array('c0ffee00' => array('id' => 'c0ffee00') + zfsmScheduleCopiesRecord('tank/data', 'days', 'usb', '07:20', '1', 0));
$check = function($aside) use ($old) {
	$new = zfsmScheduleSnapshotRecord('tank/data', 'days', '07:10', '1', 0);
	$placed = zfsmScheduleSnapshotPlace($old, $new, false);
	return array(array_keys($placed)[0], zfsmScheduleSnapshotCheck($placed, $new, array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdH', 'snap_utc' => 1), $aside));
};
same(array(array('c0ffee00', ''), 1), array($check($old), preg_match('/^Snapshots Pattern would give /', $check(array())[1])), 'set aside, no clash and kept; not set aside, a clash');
same(array('', array()), array($save('tank/data', $part('default'), $part('default'))['why'], array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data'; }))),
	'back to Default');

// Rename: the copy under each dataset copies go under takes the new name, the datasets below it with it, and stays incremental.
$id = array_values($stored())[0]['id'];
$t = 1800000000;
$sends = function($pattern) use ($read) { return count(preg_grep($pattern, $read('calls.log'))); };
same(0, zfsmScheduleRun($id, $cfg, $t), 'the first copies');
same(array('succeeded' => array('tank/new' => 0), 'failed' => array()), renameDataset('tank/data', 'tank/new', 0), 'a rename');
same(array('backup/tank/new', 'backup/tank/new/sub'), array_values(preg_grep('~^backup/tank/(data|new)~', $datasets())), 'its copy and the one below follow it');
$bookmarks = function($under) use ($read) { return array_values(preg_grep('~^'.preg_quote($under, '~').'[/#]~', preg_grep('/#/', array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/marks'))))); };
same(array('tank/new#'.zfsmReplicaBookmarkPrefix('backup/tank/new').'auto-20270115080000', 'tank/new/sub#'.zfsmReplicaBookmarkPrefix('backup/tank/new/sub').'auto-20270115080000'),
	$bookmarks('tank/new'), 'their bookmarks take the copies\' new names');
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 3600), 'the next run');
same(array(1, 0), array($sends('~^send -P -v -L -i \S+ tank/new@~'), $sends('~^send -P -v -L tank/new@~')), 'sends only the changes to the renamed copy');
// A run cut off part-way, then a rename: zfs finds the snapshot by guid under its new name (live, 2.4.4), so the next run carries it on.
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmScheduleRun($id, $cfg, $t + 7200);
putenv('ZFSM_TEST_SEND_CUT');
$tokens = function($copy) use ($read) { return array_values(preg_grep('~^'.preg_quote($copy, '~').'(/sub)?\treceive_resume_token\ttok:~', $read('pool/props'))); };
same(array(2, array('succeeded' => array('tank/cut' => 0), 'failed' => array())), array(count($tokens('backup/tank/new')), renameDataset('tank/new', 'tank/cut', 0)),
	'a rename after a run cut off part-way');
$reset();
$marks = function() use ($read) { return array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/marks')); };
same(array(0, 2, array(), array()), array(zfsmScheduleRun($id, $cfg, $t + 10800), $sends('~^send -P -v -t tok:tank/new(/sub)?@auto-20270115100000$~'), $tokens('backup/tank/cut'),
	array_values(array_diff(array('backup/tank/cut@auto-20270115100000', 'backup/tank/cut/sub@auto-20270115100000'), $marks()))), 'the next run carries each on from its token, and both copies land');
// Into a dataset whose copy is not made yet: an empty holder takes its place first, as zfs rename needs the parent.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/deep\tfilesystem")));
$reset();
same(array('succeeded' => array('tank/deep/data' => 0), 'failed' => array()), renameDataset('tank/cut', 'tank/deep/data', 0), 'a rename into a new dataset');
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
same(array('succeeded' => array('tank/again' => 0), 'failed' => array()), renameDataset('tank/again', 'tank/again', 0), 'a rename to its own name, which zfs does nothing for');
// Its copy in a pool not imported now: zfs says the copy does not exist, so it is told as left behind.
$all = $read('pool/datasets');
$pool('datasets', array_values(preg_grep('~^backup[\t/]~', $all, PREG_GREP_INVERT)));
$gone = renameDataset('tank/again', 'tank/away', 0)['failed'];
$pool('datasets', array_merge($read('pool/datasets'), preg_grep('~^backup[\t/]~', $all)));
same(array($copy('tank/again') => 'not renamed with it, as its pool is not imported now. It stays under its old name, so the next copy of the renamed dataset is a full one'), $gone,
	'a copy in a pool not imported is told');
// A copy of the renamed dataset: its newest snapshot is on it.
$copied = function($zdataset, $guid) use ($pool, $read) { $pool('marks', array_merge($read('pool/marks'), array("$zdataset@s\t$guid\t500", "backup/$zdataset@s\t$guid\t500"))); };
$add(array('tank/r', 'backup/tank/r', 'tank/q', 'backup/tank/q'));
$copied('tank/q', 81);
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/r');
same(array($copy('tank/r') => 'not renamed with it, as whether it is there could not be read'), renameDataset('tank/r', 'tank/r2', 0)['failed'], 'a copy that could not be read');
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/q2');
same(array($copy('tank/q') => 'not renamed with it, as whether its new name is free could not be read'), renameDataset('tank/q', 'tank/q2', 0)['failed'], 'a new name that could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
$add(array('tank/up', 'tank/u', 'backup/tank/u'));
$copied('tank/u', 82);
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/up');
same(array($copy('tank/u') => 'not renamed with it: the datasets above its new name could not be read. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	renameDataset('tank/u', 'tank/up/u', 0)['failed'], 'one whose new parent could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
// One whose own Replication is None: nothing copies it there, so its copy stays.
$add(array('tank/n', 'backup/tank/n'));
same(true, $save('tank/n', $part('default'), $part('none'))['saved'], 'None');
same(array($copy('tank/n') => 'left where it is, as no schedule copies the renamed dataset or one below it there'), renameDataset('tank/n', 'tank/final', 0)['failed'], 'nothing copies it there');
same(array(true, false), array(in_array('backup/tank/n', $datasets(), true), in_array('backup/tank/final', $datasets(), true)), 'it stays');

// One below it still copied there: the holder over that copy follows the rename too.
$add(array('tank/p', 'tank/p/kid', 'backup/tank/p', 'backup/tank/p/kid'));
$pool('props', array_merge($read('pool/props'), array("backup/tank/p\tzfs.master:holder\ton")));
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
// Not a copy of the renamed dataset, as one left by a destroy before a dataset of that name was made again: it stays, and why.
$add(array('tank/a', 'backup/tank/a', 'tank/e', 'backup/tank/e', 'tank/f', 'backup/tank/f', 'tank/g', 'backup/tank/g'));
$pool('marks', array_merge($read('pool/marks'), array("tank/a@new\t96\t501", "backup/tank/a@old\t95\t400")));
same(array($copy('tank/a') => 'left where it is, as its newest snapshot is not on the renamed dataset'), renameDataset('tank/a', 'tank/a2', 0)['failed'], 'a copy of another dataset');
same(array($copy('tank/e') => 'left where it is, as it holds no snapshot of the renamed dataset'), renameDataset('tank/e', 'tank/e2', 0)['failed'], 'one with no snapshot that is no holder');
$copied('tank/f', 83);
$copied('tank/g', 87);
putenv('ZFSM_TEST_MARKS_FAIL=backup/tank/f');
same(array($copy('tank/f') => 'not renamed with it, as its snapshots could not be read'), renameDataset('tank/f', 'tank/f2', 0)['failed'], 'one whose snapshots could not be read');
putenv('ZFSM_TEST_MARKS_FAIL=tank/g2');
same(array($copy('tank/g') => 'not renamed with it, as the snapshots of the renamed dataset could not be read'), renameDataset('tank/g', 'tank/g2', 0)['failed'], 'nor those of the renamed one');
putenv('ZFSM_TEST_MARKS_FAIL');
same(array('backup/tank/a', 'backup/tank/e', 'backup/tank/f', 'backup/tank/g'), array_values(preg_grep('~^backup/tank/[aefg]2?$~', $datasets())), 'they stay');

// Its bookmarks not all carried to the copy's new name: the copy is renamed, and what went wrong said.
$marked = function($zdataset, $guid) use ($pool, $read, $add, $copied) {
	$add(array($zdataset, 'backup/'.$zdataset));
	$copied($zdataset, $guid);
	$pool('marks', array_merge($read('pool/marks'), array($zdataset.'#'.zfsmReplicaBookmarkPrefix('backup/'.$zdataset)."s\t$guid\t500")));
};
$marked('tank/b1', 84);
putenv('ZFSM_TEST_BOOKMARK_FAIL=1');
same(array($copy('tank/b1') => 'renamed with it, but not all its bookmarks were: cannot create bookmark \'tank/b1x#'.zfsmReplicaBookmarkPrefix('backup/tank/b1x').'s\': permission denied'),
	renameDataset('tank/b1', 'tank/b1x', 0)['failed'], 'a bookmark not made');
putenv('ZFSM_TEST_BOOKMARK_FAIL');
$marked('tank/b2', 85);
putenv('ZFSM_TEST_BOOKMARKS_FAIL=1');
same(array($copy('tank/b2') => 'renamed with it, but its bookmarks could not be read: cannot open \'tank/b2x\': Input/output error'), renameDataset('tank/b2', 'tank/b2x', 0)['failed'], 'its bookmarks not read');
putenv('ZFSM_TEST_BOOKMARKS_FAIL');
$marked('tank/b3', 86);
putenv('ZFSM_TEST_DESTROY_FAIL=tank/b3x#'.zfsmReplicaBookmarkPrefix('backup/tank/b3').'s');
same(array($copy('tank/b3') => 'renamed with it, but not all its bookmarks were: cannot destroy \'tank/b3x#'.zfsmReplicaBookmarkPrefix('backup/tank/b3').'s\': Input/output error'),
	renameDataset('tank/b3', 'tank/b3x', 0)['failed'], 'the old one not removed');
putenv('ZFSM_TEST_DESTROY_FAIL');
same(array('backup/tank/b1x', 'backup/tank/b2x', 'backup/tank/b3x'), array_values(preg_grep('~^backup/tank/b\d~', $datasets())), 'each copy renamed');
// A bookmark name can end in a space (OpenZFS), the last one listed too.
$marked('tank/b4', 91);
$pool('marks', preg_replace('~^(tank/b4#[^\t]*s)\t~', "$1 \t", $read('pool/marks')));
same(array(array(), array('tank/b4x#'.zfsmReplicaBookmarkPrefix('backup/tank/b4x').'s ')), array(renameDataset('tank/b4', 'tank/b4x', 0)['failed'], $bookmarks('tank/b4x')),
	'a bookmark whose name ends in a space takes the copy\'s new name');
// A stopped run's holds take the copy's new name, so the run that carries it on takes them off; another hold stays.
$tag = function($zdest) { return zfsmKeepHoldTag(zfsmKeepTag('', $zdest)); };
$held = function($zdataset, $guid) use ($pool, $read, $marked, $tag) {
	$marked($zdataset, $guid);
	$pool('holds', array_merge($read('pool/holds'), array("$zdataset@s\t".$tag('backup/'.$zdataset), "$zdataset@s\tkeep")));
};
$holds = function($zdataset) use ($read) { return array_values(preg_grep('~^'.preg_quote($zdataset, '~').'@~', $read('pool/holds'))); };
$held('tank/h1', 88);
$held('tank/h1/k', 89);
same(array('succeeded' => array('tank/h1x' => 0), 'failed' => array()), renameDataset('tank/h1', 'tank/h1x', 0), 'a copy a stopped run holds');
same(array(array("tank/h1x@s\tkeep", "tank/h1x@s\t".$tag('backup/tank/h1x')), array("tank/h1x/k@s\tkeep", "tank/h1x/k@s\t".$tag('backup/tank/h1x/k'))),
	array($holds('tank/h1x'), $holds('tank/h1x/k')), 'its hold and the one below take the new names');
foreach (array('h2' => array('ZFSM_TEST_HELD_FAIL', 'its holds could not be read: cannot open \'tank/h2x\': Input/output error'),
	'h3' => array('ZFSM_TEST_HOLDS_READ_FAIL', 'its holds could not be read: cannot get holds for \'tank/h3x@s\': permission denied'),
	'h4' => array('ZFSM_TEST_HOLD_FAIL', 'not all its holds were: tank/h4x@s (cannot hold snapshot \'tank/h4x@s\': permission denied)'),
	'h5' => array('ZFSM_TEST_RELEASE_FAIL', 'not all its holds were: tank/h5x@s (cannot release hold from snapshot \'tank/h5x@s\': permission denied)')) as $name => list($knob, $why)) {
	$held('tank/'.$name, 90 + (int)substr($name, 1));
	putenv($knob.'=1');
	same(array($copy('tank/'.$name) => 'renamed with it, but '.$why), renameDataset('tank/'.$name, 'tank/'.$name.'x', 0)['failed'], $knob.': said');
	putenv($knob);
}
same(array("tank/h4x@s\t".$tag('backup/tank/h4'), "tank/h4x@s\tkeep"), $holds('tank/h4x'), 'a hold not moved stays as it was');
// A snapshot name can end in a space (OpenZFS).
$held('tank/h6', 96);
foreach (array('marks', 'holds') as $file) $pool($file, preg_replace('~^tank/h6@s\t~', "tank/h6@s \t", $read('pool/'.$file)));
same(array(array('tank/h6x' => 0), array("tank/h6x@s \tkeep", "tank/h6x@s \t".$tag('backup/tank/h6x'))), array(renameDataset('tank/h6', 'tank/h6x', 0)['succeeded'], $holds('tank/h6x')),
	'the hold on a snapshot whose name ends in a space takes the new name');
same(array('saved' => false, 'why' => 'Nothing was saved: that is no dataset name', 'said' => ''), $save('', $part('hourly'), $part('hourly', '', '', 0, 'usb')), 'the dialog saves no Settings schedule');

// The schedules not read: where its copies go is not known, so none is renamed, and that is said.
$add(array('tank/w', 'backup/tank/w'));
$copied('tank/w', 88);
$text = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), '{');
$answer = renameDataset('tank/w', 'tank/w2', 0);
file_put_contents(zfsmScheduleFile(), $text);
same(array(array('Schedules of tank/w', 'Copies of tank/w'), 'not renamed with it, as the schedules that say where they go could not be read', array('backup/tank/w')),
	array(array_keys($answer['failed']), $answer['failed']['Copies of tank/w'] ?? '', array_values(preg_grep('~^backup/tank/w~', $datasets()))), 'schedules not read: copies not renamed, and said');

// As zfs: a destroy preview changes nothing, and without -r one with a snapshot stays.
$marked('tank/s1', 77);
$now = array($read('pool/datasets'), $read('pool/marks'));
$preview = zfsmDestroyPreview('tank/s1', 1);
same(array('', true, $now), array($preview['error'], in_array('tank/s1', $preview['objects'], true), array($read('pool/datasets'), $read('pool/marks'))),
	'a destroy preview runs, and changes nothing');
same(array(array('tank/s1'), true), array(array_keys(destroyDataset('tank/s1', 0)['failed']), in_array('tank/s1', $datasets(), true)), 'one with a snapshot is not destroyed without -r');
foreach (array('holds' => array("tank/s1@s\tkeep", 'dataset is busy'), 'props' => array("tank/s1@s\tclones\ttank/c1", 'snapshot has dependent clones')) as $file => list($line, $why)) {
	$pool($file, array_merge($read('pool/'.$file), array($line)));
	same(array(1, true), array(substr_count(implode(' ', destroyDataset('tank/s1', 1)['failed']), $why), in_array('tank/s1', $datasets(), true)), 'nor with -r while: '.$why);
	$pool($file, array_values(array_diff($read('pool/'.$file), array($line))));
}
$pool('datasets', array_merge($read('pool/datasets'), array("tank/s1/c\tfilesystem")));
$pool('props', array_merge($read('pool/props'), array("tank/s1\treceive_resume_token\ttok:tank/s1@s", "tank/s1@s\tzfs.master:schedule\tx", "tank/s1@s\tclones\ttank/s1/c")));
same(array(array('succeeded' => array('tank/s1' => 0), 'failed' => array()), array(), false), array(destroyDataset('tank/s1', 1), preg_grep('~^tank/s1[\t@/]~', $read('pool/props')),
	in_array('tank/s1/c', $datasets(), true)), 'with -r and neither (a clone below it is no dependent outside), it goes, with its properties, its snapshots\' and the clone');

// Destroy removes what it and below had under Schedules and leaves its copies.
$under = function($root) use ($stored) { return array_values(preg_grep('~^'.preg_quote($root, '~').'(/|$)~', array_column($stored() ?? array(), 'dataset'))); };
same(array(true, array('tank/p3/kid')), array($save('tank/p3/kid', $part('default'), $part('hourly', '', '', 0, 'backup'))['saved'], $under('tank/p3')), 'its own copies below it');
$others = function() use ($stored) {
	$all = $stored();
	return is_array($all) ? array_values(array_filter($all, function($job) { return !preg_match('~^tank/p3(/|$)~', $job['dataset']); })) : null;
};
$kept = $others();
same(array('succeeded' => array('tank/p3' => 0), 'failed' => array()), destroyDataset('tank/p3', 1), 'a destroy');
same(array(array(), array(), array('backup/tank/p3', 'backup/tank/p3/kid'), true, $kept), array($under('tank/p3'), array_values(preg_grep('~^tank/p3~', $datasets())),
	array_values(preg_grep('~^backup/tank/p~', $datasets())), $kept !== array(), $others()), 'it goes with the schedule below it, its copies stay, and the other schedules stay as they were');

echo "schedule dialog tests passed\n";
