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
// Snapshots Pattern since gives names ZFS refuses: parts as saved, and None, save; a changed part is refused.
$slashed = function($snapshots, $copies) use ($cfg) { return zfsmScheduleDialogSave('tank/data', $snapshots, $copies, array('snap_pattern' => 'Y/m/d-His') + $cfg); };
$refused = array($slashed($part('hourly', '', '', 1), $part('none', '', '', 0, 'usb'))['saved'], $slashed($part('hourly', '', '', 2), $part('none', '', '', 0, 'usb'))['why']);
$refused[] = $slashed($part('none'), $part('none', '', '', 0, 'usb'))['saved'];
$refused[] = $save('tank/data', $part('hourly', '', '', 1), $part('none', '', '', 0, 'usb'))['saved'];
same(array(true, 'Nothing was saved: Snapshots: '.'Snapshots Pattern and Prefix in Settings give new snapshots a name ZFS refuses: a name may be up to 255 long and hold only letters, digits, spaces and _ . : -', true, true), $refused, 'with names ZFS refuses, parts as saved and None save; a changed part is refused');
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
// A part set to Default hands its datasets to the one above: one as saved is checked against that, in its minute or names, but a
// clash there before stays the owner's. Either part.
$apps = $read('pool/datasets');
$pool('datasets', array_merge($apps, array("tank/apps\tfilesystem", "tank/apps/db\tfilesystem", "tank/apps/log\tfilesystem", "tank/mv\tfilesystem")));
$ymd = function($zdataset, $snapshots, $copies) use ($cfg) { return zfsmScheduleDialogSave($zdataset, $snapshots, $copies, array('snap_pattern' => 'Ymd') + $cfg); };
$handed = array($save('tank/apps', $part('days', '03:00', '1'), $part('none'))['saved'], $save('tank/apps/db', $part('days', '05:00', '1'), $part('days', '04:00', '1', 0, 'usb'))['saved'],
	$save('tank/apps/log', $part('days', '06:00', '1'), $part('default'))['saved']);
$handed[] = $ymd('tank/apps/log', $part('default'), $part('default'))['saved'];
$handed[] = preg_match('/^Nothing was saved: for (the copies of tank\/apps\/db and below|the snapshot schedule of tank\/apps), Snapshots Pattern would give the run at .* the same snapshot name as .*; add the time to Snapshots Pattern$/',
	$ymd('tank/apps/db', $part('default'), $part('days', '04:00', '1', 0, 'usb'))['why']);
$handed[] = $save('tank/apps/db', $part('default'), $part('days', '04:00', '1', 0, 'usb'))['saved'];
$handed[] = $save('tank/apps', $part('days', '03:00', '1'), $part('days', '07:00', '1', 0, 'usb'))['saved'];
$handed[] = $save('tank/apps/db', $part('days', '07:00', '1'), $part('days', '04:00', '1', 0, 'usb'))['saved'];
$handed[] = preg_match('/^Nothing was saved: for (the copies of tank\/apps and below|the snapshot schedule of tank\/apps\/db), the .* starts then too; pick another time$/',
	$save('tank/apps/db', $part('days', '07:00', '1'), $part('default'))['why']);
same(array(true, true, true, true, 1, true, true, true, 1), $handed, 'Default is checked against the one it hands its datasets to, as saved, but a clash there before is left');
// Copies of a dataset on the day the snapshots of another run: renamed below that one, the rename says the clash it makes, by its
// Snapshots Pattern.
same(true, $save('tank/mv', $part('default'), $part('days', '04:00', '1', 0, 'usb'))['saved'], 'copies of one apart');
$moved = renameDataset('tank/mv', 'tank/apps/mv', 0, array('snap_pattern' => 'Ymd') + $cfg);
same(array(array('tank/apps/mv' => 0), array('Schedules of tank/apps/mv'), 1, array('tank/apps/mv')), array($moved['succeeded'], array_keys($moved['failed']),
	preg_match('/^moved with it, but for (the copies of tank\/apps\/mv and below|the snapshot schedule of tank\/apps), Snapshots Pattern would give the run at .* the same snapshot name as .*; add the time to Snapshots Pattern$/',
	$moved['failed']['Schedules of tank/apps/mv'] ?? ''),
	array_values(preg_grep('~^tank/(apps/)?mv$~', array_column($stored(), 'dataset')))), 'a rename into a clash says it, and its schedule moves');
// Of a dataset gone, as one destroyed outside the plugin, Remove goes though Default would clash, as no dataset follows it; not of one in
// a pool not imported, which may be there. Each a copy of the schedules of tank/apps and tank/apps/db.
$clone = function($root) { return zfsmScheduleChange(function($jobs) use ($root) {
	foreach ($jobs as $job) if (in_array($job['dataset'], array('tank/apps', 'tank/apps/db'), true)) {
		$job['id'] = substr(hash('sha256', $root.$job['id']), 0, 8);
		$job['dataset'] = $root.substr($job['dataset'], strlen('tank/apps'));
		$jobs[$job['id']] = $job;
	}
	return $jobs;
})['saved']; };
$own_snap = function($zdataset) use ($stored) { return array_values(array_filter($stored(), function($job) use ($zdataset) { return $job['dataset'] === $zdataset && zfsmScheduleSnapshotsOnly($job); }))[0]['id']; };
same(array(true, true, true, 1), array($clone('tank/gone'), $clone('usb2/apps'), zfsmScheduleRemove($own_snap('tank/gone/db'), array('snap_pattern' => 'Ymd') + $cfg)['saved'],
	preg_match('/^Not removed: for /', zfsmScheduleRemove($own_snap('usb2/apps/db'), array('snap_pattern' => 'Ymd') + $cfg)['why'])), 'a gone one goes; one in a pool not imported stays');
zfsmScheduleChange(function($jobs) { return array_filter($jobs, function($job) { return !zfsmUnderAny($job['dataset'], array('tank/gone', 'usb2/apps')); }); });
// Remove in Settings sets a dataset's own back to Default too: checked as that is.
$own = array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/apps/db' && zfsmScheduleSnapshotsOnly($job); }))[0]['id'];
same(1, preg_match('/^Not removed: for .*, Snapshots Pattern would give the run at .* the same snapshot name as .*; add the time to Snapshots Pattern$/', zfsmScheduleRemove($own, array('snap_pattern' => 'Y/m/d') + $cfg)['why']),
	'a clash Default would make is told, though ZFS refuses the names too');
$removed = array(zfsmScheduleRemove($own, array('snap_pattern' => 'Ymd') + $cfg));
$removed[] = in_array($own, array_column($stored(), 'id'), true);
$removed[] = zfsmScheduleRemove($own, array('snap_pattern' => 'Y/m/d-His') + $cfg)['saved'];
same(array(array('why' => 1, 'saved' => false), true, true), array(array('why' => preg_match('/^Not removed: for (the copies of tank\/apps\/db and below|the snapshot schedule of tank\/apps), Snapshots Pattern would give the run at .* the same snapshot name as .*; add the time to Snapshots Pattern$/',
	$removed[0]['why'])) + $removed[0], $removed[1], $removed[2]), 'Remove in Settings is refused where Default would clash, and allowed where not, though ZFS refuses the names');
// Three repeats set in Replicate of one dataset on Mondays, a pattern of days naming them alike: Remove of one clashes nothing new.
$repeats = array();
foreach (array('03:00', '04:00', '05:00') as $i => $at) $repeats['5e0c1a0'.$i] = array('id' => '5e0c1a0'.$i, 'dataset' => 'tank/docker', 'dest' => 'backup/rx'.$i, 'server' => '', 'every' => 'days') + zfsmScheduleAt('days', $at, '1') + array('keep' => 0);
zfsmScheduleChange(function($jobs) use ($repeats) { return $jobs + $repeats; });
same(array(true, true, array('5e0c1a00')), array(zfsmScheduleRemove('5e0c1a02', array('snap_pattern' => 'Y-m-d') + $cfg)['saved'], zfsmScheduleRemove('5e0c1a01', array('snap_pattern' => 'Y-m-d') + $cfg)['saved'],
	array_values(array_intersect(array_keys($repeats), array_column($stored(), 'id')))), 'Remove of a repeat that clashes with others goes where the rest clashed already');
zfsmScheduleRemove('5e0c1a00', $cfg);
// A clash of each schedule there before (a pattern of months, so each run of the week names alike) does not hide a new one.
$ym = function($zdataset, $snapshots, $copies) use ($cfg) { return zfsmScheduleDialogSave($zdataset, $snapshots, $copies, array('snap_pattern' => 'Ym') + $cfg); };
same(array(true, 1), array($save('tank/apps/log', $part('none'), $part('days', '03:00', '1', 0, 'usb'))['saved'],
	preg_match('/^Nothing was saved: for (the copies of tank\/apps\/log and below|the snapshot schedule of tank\/apps), the .* starts then too; pick another time$/', $ym('tank/apps/log', $part('default'), $part('days', '03:00', '1', 0, 'usb'))['why'])),
	'a clash in its minute, new where each had a clash of names');
same(true, $save('tank/apps/log', $part('default'), $part('default'))['saved'], 'and back');
same(array(true, true, true), array_map(function($zdataset) use ($save, $part) { return $save($zdataset, $part('default'), $part('default'))['saved']; }, array('tank/apps/db', 'tank/apps/mv', 'tank/apps')),
	'back to Default');
$pool('datasets', $apps);

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
// And one below it with its own copies under usb, in place of those under backup: that copy follows too.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/data/u\tfilesystem")));
same(true, $save('tank/data/u', $part('default'), $part('days', '05:00', '1', 0, 'usb'))['saved'], 'its own copies under usb');
$usb = array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/data/u'; }))[0]['id'];
same(0, zfsmScheduleRun($usb, $cfg, $t + 60), 'their first copy');
putenv('ZFSM_TEST_PHP='.PHP_BINARY);
$probe = zfsmJobLockFile('tank/data', 'replicate');
putenv('ZFSM_TEST_LOCK_PROBE='.$probe);
same(array('succeeded' => array('tank/new' => 0), 'failed' => array()), renameDataset('tank/data', 'tank/new', 0, $cfg), 'a rename');
putenv('ZFSM_TEST_LOCK_PROBE');
same(array('held', 'held', 'held'), $read('pool/probe.log'), 'its replication lock is held while it and its copies are renamed');
same($probe, zfsmJobLockFile('tank/new', 'replicate'), 'and a run under its new name takes that same lock');
$pool('datasets', array_merge($read('pool/datasets'), array("tank/data\tfilesystem")));
$own = zfsmJobLockFile('tank/data', 'replicate');
same(array(true, true), array(is_string($own) && $own !== '', $own !== $probe), 'one made since under its old name has a lock of its own');
$pool('datasets', array_values(array_diff($read('pool/datasets'), array("tank/data\tfilesystem"))));
same(array(array('backup/tank/new', 'backup/tank/new/sub'), array('usb/tank/new', 'usb/tank/new/u')), array(array_values(preg_grep('~^backup/tank/(data|new)~', $datasets())),
	array_values(preg_grep('~^usb/tank/(data|new)~', $datasets()))), 'its copy and the one below follow it, each under its Copy Under');
$bookmarks = function($under) use ($read) { return array_values(preg_grep('~^'.preg_quote($under, '~').'[/#]~', preg_grep('/#/', array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/marks'))))); };
same(array('tank/new#'.zfsmReplicaBookmarkPrefix('backup/tank/new').'auto-20270115080000', 'tank/new/sub#'.zfsmReplicaBookmarkPrefix('backup/tank/new/sub').'auto-20270115080000',
	'tank/new/u#'.zfsmReplicaBookmarkPrefix('usb/tank/new/u').'auto-20270115080100'), $bookmarks('tank/new'), 'their bookmarks take the copies\' new names');
$reset();
// Its snapshots gone from the source, only the bookmarks that took the new names keep the next run incremental.
$pool('marks', array_values(preg_grep('~^tank/new(/sub)?@auto-20270115080000\t~', $read('pool/marks'), PREG_GREP_INVERT)));
same(0, zfsmScheduleRun($id, $cfg, $t + 3600), 'the next run');
$landed = zfsmScheduleListed(zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT, array('-d', '1', 'backup/tank')))[0]['backup/tank'] ?? null;
same('backup/tank is read-only, as a copy received here is: a snapshot in it stops the next replication into it', zfsmScheduleSkipped('backup/tank', $landed),
	'a copy lands read-only, so no snapshot schedule takes it');
$from = function($zdataset) { return preg_quote($zdataset.'#'.zfsmReplicaBookmarkPrefix('backup/'.$zdataset).'auto-20270115080000 '.$zdataset.'@', '~'); };
same(array(1, 1, 0), array($sends('~^send -P -v -L -i '.$from('tank/new').'~'), $sends('~^send -P -v -L -i '.$from('tank/new/sub').'~'), $sends('~^send -P -v -L tank/new@~')),
	'sends from the moved bookmarks only the changes to the renamed copies');
$reset();
$pool('marks', array_values(preg_grep('~^tank/new/u@auto-20270115080100\t~', $read('pool/marks'), PREG_GREP_INVERT)));
$under = preg_quote('tank/new/u#'.zfsmReplicaBookmarkPrefix('usb/tank/new/u').'auto-20270115080100 tank/new/u@', '~');
same(array(0, 1, 0), array(zfsmScheduleRun($usb, $cfg, $t + 3660), $sends('~^send -P -v -L -i '.$under.'~'), $sends('~^send -P -v -L tank/new/u@~')), 'and the one under usb, from its own');
same(true, zfsmScheduleRemove($usb, $cfg)['saved'], 'its copies under usb removed, which later checks leave out');
// A run cut off part-way, then a rename: zfs finds the snapshot by guid under its new name, so the next run carries it on.
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmScheduleRun($id, $cfg, $t + 7200);
putenv('ZFSM_TEST_SEND_CUT');
$pinned = function($zdataset) use ($read) { return array_values(preg_grep('~^'.preg_quote($zdataset, '~').'(/sub)?@auto-20270115100000\t'.ZFSM_KEEP_HOLD.'~', $read('pool/holds'))); };
same(2, count($pinned('tank/new')), 'the cut-off run holds the snapshots it sends');
$tokens = function($copy) use ($read) { return array_values(preg_grep('~^'.preg_quote($copy, '~').'(/sub)?\treceive_resume_token\ttok:~', $read('pool/props'))); };
same(array(2, array('succeeded' => array('tank/cut' => 0), 'failed' => array())), array(count($tokens('backup/tank/new')), renameDataset('tank/new', 'tank/cut', 0, $cfg)),
	'a rename after a run cut off part-way');
$reset();
$marks = function() use ($read) { return array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/marks')); };
same(array(0, 2, array(), array()), array(zfsmScheduleRun($id, $cfg, $t + 10800), $sends('~^send -P -v -t tok:tank/new(/sub)?@auto-20270115100000$~'), $tokens('backup/tank/cut'),
	array_values(array_diff(array('backup/tank/cut@auto-20270115100000', 'backup/tank/cut/sub@auto-20270115100000'), $marks()))), 'the next run carries each on from its token, and both copies land');
same(array(), $pinned('tank/cut'), 'and lets go of their holds');
// Into a dataset whose copy is not made yet: an empty holder takes its place first, as zfs rename needs the parent.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/deep\tfilesystem")));
$reset();
same(array('succeeded' => array('tank/deep/data' => 0), 'failed' => array()), renameDataset('tank/cut', 'tank/deep/data', 0, $cfg), 'a rename into a new dataset');
same(array(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/tank/deep'), true, true),
	array(array_values(preg_grep('/^create /', $read('calls.log'))), zfsmReplicaHolder(array('server' => ''), 'backup/tank/deep'), in_array('backup/tank/deep/data/sub', $datasets(), true)), 'a holder, then the copy');
// Into an encrypted one whose first copy is due: a holder there would stop that copy, so the copy stays, and why.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/vault\tfilesystem", "tank/vault/data\tfilesystem", "tank/vault/in\tfilesystem", "backup/tank/vault\tfilesystem", "backup/tank/vault/data\tfilesystem")));
$pool('props', array_merge($read('pool/props'), array_map(function($zdataset) { return $zdataset."\tencryption\taes-256-gcm"; }, array('tank/vault', 'tank/vault/data', 'tank/vault/in'))));
$pool('marks', array_merge($read('pool/marks'), array("tank/vault/data@s\t97\t502", "backup/tank/vault/data@s\t97\t502")));
same(array('Copy backup/tank/vault/data' => 'not renamed with it: zfs could not receive the copy due at backup/tank/vault/in over the dataset made there to hold this one, as one of the two is encrypted. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	renameDataset('tank/vault/data', 'tank/vault/in/data', 0, $cfg)['failed'], 'a rename into an encrypted dataset whose first copy is due');
same(array(false, true), array(in_array('backup/tank/vault/in', $datasets(), true), in_array('backup/tank/vault/data', $datasets(), true)), 'no holder made, and the copy stays');
// Whether either is encrypted not read: the same, and why.
renameDataset('tank/vault/in/data', 'tank/vault/data', 0, $cfg);
putenv('ZFSM_TEST_CRYPT_FAIL=tank/vault/in');
same(array('Copy backup/tank/vault/data' => 'not renamed with it: whether a dataset made at backup/tank/vault/in to hold this one would stop a copy due there could not be read. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	renameDataset('tank/vault/data', 'tank/vault/in/data', 0, $cfg)['failed'], 'one whose encryption could not be read');
putenv('ZFSM_TEST_CRYPT_FAIL');
same(array(false, true), array(in_array('backup/tank/vault/in', $datasets(), true), in_array('backup/tank/vault/data', $datasets(), true)), 'no holder made there either');

// When it can't, the rename still stands and each copy left behind says why, by its name.
$copy = function($zdataset) { return 'Copy backup/'.$zdataset; };
putenv('ZFSM_TEST_RENAME_FAIL=backup/tank/deep/data');
same(array('succeeded' => array('tank/moved' => 0), 'failed' => array($copy('tank/deep/data') => 'not renamed with it: cannot rename \'backup/tank/deep/data\': dataset is busy. It stays under its old name, so the next copy of the renamed dataset is a full one')),
	renameDataset('tank/deep/data', 'tank/moved', 0, $cfg), 'a copy that is busy');
putenv('ZFSM_TEST_RENAME_FAIL');
$add = function($names) use ($pool, $read) { $pool('datasets', array_merge($read('pool/datasets'), array_map(function($name) { return $name."\tfilesystem"; }, $names))); };
$add(array('backup/tank/again'));
same(array($copy('tank/docker') => 'left where it is, as a dataset already has its new name'), renameDataset('tank/docker', 'tank/again', 0, $cfg)['failed'], 'a name already taken there');
same(array('succeeded' => array('tank/again' => 0), 'failed' => array()), renameDataset('tank/again', 'tank/again', 0, $cfg), 'a rename to its own name, which zfs does nothing for');
// A rename that lands but can't be mounted there, which zfs 2.0 and 2.1 fail: its schedules follow it all the same, and the error stays.
$add(array('tank/um', 'tank/um-taken'));
same(true, $save('tank/um', $part('days', $free, '1'), $part('default'))['saved'], 'its own snapshots');
$under_um = function() use ($stored) { return array_values(array_column(array_filter($stored(), function($job) { return zfsmUnderAny($job['dataset'], array('tank/um', 'tank/um-taken')); }), 'dataset')); };
same(array(array('tank/um-taken' => 'cannot rename \'tank/um\': dataset already exists'), array('tank/um')), array(renameDataset('tank/um', 'tank/um-taken', 0, $cfg)['failed'], $under_um()),
	'onto a name taken it fails where it is, and its schedule stays');
putenv('ZFSM_TEST_RENAME_UNMOUNTED=tank/um');
$unmounted = renameDataset('tank/um', 'tank/um2', 0, $cfg)['failed'];
putenv('ZFSM_TEST_RENAME_UNMOUNTED');
$um = array_filter($stored(), function($job) { return zfsmUnderAny($job['dataset'], array('tank/um', 'tank/um2')); });
same(array(array('tank/um2' => 'cannot mount \'/mnt/tank/um2\': mountpoint is not a directory'), array('tank/um2')), array($unmounted, array_values(array_column($um, 'dataset'))),
	'its schedule follows, and the mount error stays');
foreach ($um as $job) zfsmScheduleRemove($job['id'], $cfg);
// Its copy in a pool not imported now: zfs says the copy does not exist, so it is told as not checked; where no schedule copies it,
// nothing is said.
$add(array('tank/o'));
same(true, $save('tank/o', $part('default'), $part('none'))['saved'], 'None');
$all = $read('pool/datasets');
$pool('datasets', array_values(preg_grep('~^backup[\t/]~', $all, PREG_GREP_INVERT)));
$gone = array(renameDataset('tank/again', 'tank/away', 0, $cfg)['failed'], renameDataset('tank/o', 'tank/o2', 0, $cfg)['failed']);
$pool('datasets', array_merge($read('pool/datasets'), preg_grep('~^backup[\t/]~', $all)));
same(array(array($copy('tank/again') => 'not checked, as its pool is not imported now: if it is there, it keeps its old name, and the next copy of the renamed dataset is a full one'), array()),
	$gone, 'a copy in a pool not imported is told as not checked, and one nothing copies there not at all');
// A copy of the renamed dataset: its newest snapshot is on it.
$copied = function($zdataset, $guid) use ($pool, $read) { $pool('marks', array_merge($read('pool/marks'), array("$zdataset@s\t$guid\t500", "backup/$zdataset@s\t$guid\t500"))); };
// Under a copy that is encrypted, as in an encrypted pool, a holder is too; and a repeat set in Replicate due where no schedule
// copies. Each stops the copy due there, so the copy stays.
$add(array('tank/sealed', 'tank/sealed/data', 'tank/sealed/in', 'backup/tank/sealed', 'backup/tank/sealed/data', 'tank/vault/by', 'tank/vault/x', 'backup/tank/vault/x'));
$copied('tank/sealed/data', 71);
$copied('tank/vault/x', 72);
$pool('props', array_merge($read('pool/props'), array("backup/tank/sealed\tencryption\taes-256-gcm", "backup/tank/sealed/data\tencryption\taes-256-gcm", "tank/vault/by\tencryption\taes-256-gcm",
	"tank/vault/x\tencryption\taes-256-gcm")));
same(array(true, true), array($save('tank/vault/by', $part('default'), $part('none'))['saved'], $save('tank/vault/x', $part('default'), $part('days', '05:00', '1', 0, 'backup'))['saved']), 'None, and own copies');
same(true, zfsmScheduleChange(function($jobs) { return $jobs + array('5e7a1b2c' => array('id' => '5e7a1b2c', 'dataset' => 'tank/vault/by', 'dest' => 'backup/tank/vault/by', 'server' => '', 'every' => 'daily', 'hour' => 3, 'day' => 0)); })['saved'],
	'a repeat set in Replicate');
$blocked = function($level) { return 'not renamed with it: zfs could not receive the copy due at '.$level.' over the dataset made there to hold this one, as one of the two is encrypted. It stays under its old name, so the next copy of the renamed dataset is a full one'; };
same(array(array($copy('tank/sealed/data') => $blocked('backup/tank/sealed/in')), array($copy('tank/vault/x') => $blocked('backup/tank/vault/by')), array(false, false, true, true)),
	array(renameDataset('tank/sealed/data', 'tank/sealed/in/data', 0, $cfg)['failed'], renameDataset('tank/vault/x', 'tank/vault/by/x', 0, $cfg)['failed'],
	array_map(function($zdataset) use ($datasets) { return in_array($zdataset, $datasets(), true); }, array('backup/tank/sealed/in', 'backup/tank/vault/by', 'backup/tank/sealed/data', 'backup/tank/vault/x'))),
	'under an encrypted copy, and where a repeat set in Replicate is due: no holder made, and each copy stays');
// Under one there that no run copies, as one left to Sanoid: no copy is due there, so the holder is made and the copy moves; where
// whether a run copies it could not be read, it stays.
$add(array('tank/sealed/sn', 'tank/sealed/d2', 'backup/tank/sealed/d2', 'tank/sealed/d3', 'backup/tank/sealed/d3'));
$copied('tank/sealed/d2', 73);
$copied('tank/sealed/d3', 74);
$pool('marks', array_merge($read('pool/marks'), array("tank/sealed/sn@autosnap_2027-01-15_00:00:00_daily\t9201\t900")));
putenv('ZFSM_TEST_COVER_FAIL=tank/sealed/in');
$unsure = renameDataset('tank/sealed/d2', 'tank/sealed/in/d2', 0, $cfg)['failed'];
putenv('ZFSM_TEST_COVER_FAIL');
same(array(array($copy('tank/sealed/d2') => 'not renamed with it: whether a dataset made at backup/tank/sealed/in to hold this one would stop a copy due there could not be read. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	array(), array(true, false)), array($unsure, renameDataset('tank/sealed/d3', 'tank/sealed/sn/d3', 0, $cfg)['failed'], array(in_array('backup/tank/sealed/sn/d3', $datasets(), true), in_array('backup/tank/sealed/d3', $datasets(), true))),
	'not read: the copy stays, and said; under one no run copies, it moves');
// Nor under one whose copies go under another Copy Under: none is due here, so the holder is made and the copy moves.
$add(array('tank/sealed/u2', 'tank/sealed/d5', 'backup/tank/sealed/d5'));
$copied('tank/sealed/d5', 75);
same(array(true, true), array($save('tank/sealed/u2', $part('default'), $part('days', '05:20', '1', 0, 'usb'))['saved'], $save('tank/sealed/d5', $part('default'), $part('days', '06:20', '1', 0, 'backup'))['saved']), 'copies under usb above, and under backup below');
same(array(array(), true), array(renameDataset('tank/sealed/d5', 'tank/sealed/u2/d5', 0, $cfg)['failed'], in_array('backup/tank/sealed/u2/d5', $datasets(), true)), 'under one copied elsewhere, it moves');
foreach (array_filter($stored(), function($job) { return in_array($job['dataset'], array('tank/sealed/u2', 'tank/sealed/u2/d5'), true); }) as $job) zfsmScheduleRemove($job['id'], $cfg);
// A copy another schedule sends copies into stays where it is, so that one still finds them; a repeat set in Replicate of the renamed
// dataset itself, which keeps its old name, doesn't hold it.
$add(array('tank/nest', 'tank/nest/sub', 'backup/tank/nest', 'tank/nest3', 'backup/tank/nest3'));
$copied('tank/nest', 76);
$copied('tank/nest3', 77);
same(array(true, true), array($save('tank/nest/sub', $part('default'), $part('days', '06:40', '1', 0, 'backup/tank/nest'))['saved'],
	zfsmScheduleChange(function($jobs) { return $jobs + array('3e5a7c9b' => array('id' => '3e5a7c9b', 'dataset' => 'tank/nest3', 'dest' => 'backup/tank/nest3', 'server' => '', 'every' => 'days') + zfsmScheduleAt('days', '03:30', '1') + array('keep' => 0)); })['saved']),
	'copies of the one below under its copy, and a repeat set in Replicate of another to its copy');
same(array(array($copy('tank/nest') => 'left where it is, as a schedule sends copies into it (the copies of tank/nest2/sub and below), so the next copy of the renamed dataset is a full one'), true, array(), true),
	array(renameDataset('tank/nest', 'tank/nest2', 0, $cfg)['failed'], in_array('backup/tank/nest', $datasets(), true), renameDataset('tank/nest3', 'tank/nest4', 0, $cfg)['failed'], in_array('backup/tank/nest4', $datasets(), true)),
	'a copy another Copy Under is in stays; one only its own stale repeat goes to moves');
// Where a dataset has its new name already, that is why it stays: the next copy may go there as changes alone.
$add(array('tank/nest6', 'tank/nest6/sub', 'backup/tank/nest6', 'backup/tank/nest7'));
$copied('tank/nest6', 79);
same(true, $save('tank/nest6/sub', $part('default'), $part('days', '06:50', '1', 0, 'backup/tank/nest6'))['saved'], 'copies of the one below under its copy');
same(array($copy('tank/nest6') => 'left where it is, as a dataset already has its new name'), renameDataset('tank/nest6', 'tank/nest7', 0, $cfg)['failed'], 'its new name taken');
foreach (array_filter($stored(), function($job) { return in_array($job['dataset'], array('tank/nest2/sub', 'tank/nest3', 'tank/nest7/sub'), true); }) as $job) zfsmScheduleRemove($job['id'], $cfg);
// Its copy that moves but can't be mounted there, which zfs 2.0 and 2.1 fail, is renamed with it all the same: its bookmark too.
$add(array('tank/uc', 'backup/tank/uc'));
$copied('tank/uc', 78);
$pool('marks', array_merge($read('pool/marks'), array('tank/uc#'.zfsmReplicaBookmarkPrefix('backup/tank/uc')."s\t78\t500")));
putenv('ZFSM_TEST_RENAME_UNMOUNTED=backup/tank/uc');
$unmounted = renameDataset('tank/uc', 'tank/uc2', 0, $cfg)['failed'];
putenv('ZFSM_TEST_RENAME_UNMOUNTED');
same(array(array($copy('tank/uc') => 'renamed with it, but cannot mount \'/mnt/backup/tank/uc2\': mountpoint is not a directory'), true, array('tank/uc2#'.zfsmReplicaBookmarkPrefix('backup/tank/uc2').'s')),
	array($unmounted, in_array('backup/tank/uc2', $datasets(), true), array_values(preg_grep('~^tank/uc2#~', $marks()))), 'a copy that moves but is not mounted there is renamed, and says so');
$add(array('tank/r', 'backup/tank/r', 'tank/q', 'backup/tank/q'));
$copied('tank/q', 81);
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/r');
same(array($copy('tank/r') => 'not renamed with it, as whether it is there could not be read'), renameDataset('tank/r', 'tank/r2', 0, $cfg)['failed'], 'a copy that could not be read');
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/q2');
same(array($copy('tank/q') => 'not renamed with it, as whether its new name is free could not be read'), renameDataset('tank/q', 'tank/q2', 0, $cfg)['failed'], 'a new name that could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
$add(array('tank/up', 'tank/u', 'backup/tank/u'));
$copied('tank/u', 82);
putenv('ZFSM_TEST_LIST_FAIL=backup/tank/up');
same(array($copy('tank/u') => 'not renamed with it: the datasets above its new name could not be read. It stays under its old name, so the next copy of the renamed dataset is a full one'),
	renameDataset('tank/u', 'tank/up/u', 0, $cfg)['failed'], 'one whose new parent could not be read');
putenv('ZFSM_TEST_LIST_FAIL');
// A new name that leaves the copy of it or one below it no room for the bookmark a copy keeps: nothing is renamed. One with no room
// already may still be renamed.
$long = 'tank/'.str_repeat('l', 80);
$kid = str_repeat('m', 64);
$add(array('tank/k', 'tank/k/'.$kid, $long));
same(array(array('succeeded' => array(), 'failed' => array('tank/kk' => 'Nothing was renamed: the names of tank/kk/'.$kid.' and its copy would be too long for the bookmark a copy keeps; pick a shorter name')), true),
	array(renameDataset('tank/k', 'tank/kk', 0, $cfg), in_array('tank/k/'.$kid, $datasets(), true)), 'a new name that leaves no room for a copy\'s bookmark');
same(array($long.'2' => 0), renameDataset($long, $long.'2', 0, $cfg)['succeeded'], 'one with no room already');
$add(array('backup/z'));
same(array('backup/'.str_repeat('z', 200) => 0), renameDataset('backup/z', 'backup/'.str_repeat('z', 200), 0, $cfg)['succeeded'], 'one in the pool its copy would go to, which no run copies');
// Nor one left to Sanoid, read-only or legacy-mounted, which no run copies either; one whose kind could not be read is checked.
$add(array('tank/sz', 'tank/sz/a', 'tank/sz/a/b', 'tank/ro', 'tank/lg', 'tank/un'));
$pool('marks', array_merge($read('pool/marks'), array("tank/sz@autosnap_2027-01-15_00:00:00_daily\t9301\t901", "tank/sz/a@autosnap_2027-01-15_00:00:00_daily\t9302\t902",
	"tank/sz/a/b@autosnap_2027-01-15_00:00:00_daily\t9303\t903")));
$pool('props', array_merge($read('pool/props'), array("tank/ro\treadonly\ton", "tank/lg\tmountpoint\tlegacy")));
$longer = function($zdataset) { return $zdataset.str_repeat('w', 200); };
$renamed = array();
foreach (array('tank/sz', 'tank/ro', 'tank/lg') as $zdataset) $renamed += renameDataset($zdataset, $longer($zdataset), 0, $cfg)['succeeded'];
putenv('ZFSM_TEST_COVER_FAIL=tank/un');
$unread = renameDataset('tank/un', $longer('tank/un'), 0, $cfg)['failed'];
putenv('ZFSM_TEST_COVER_FAIL');
same(array(array($longer('tank/sz') => 0, $longer('tank/ro') => 0, $longer('tank/lg') => 0), array($longer('tank/un') => 'Nothing was renamed: the names of '.$longer('tank/un').' and its copy would be too long for the bookmark a copy keeps; pick a shorter name')),
	array($renamed, $unread), 'one a run leaves out may take a long name; one not read may not');
// One that inherits a read-only or legacy mount from above it is checked under its new parent; one that sets its own is not.
$add(array('tank/rp', 'tank/rp/c', 'tank/lp', 'tank/lp/c', 'tank/rq', 'tank/rq/c'));
$pool('props', array_merge($read('pool/props'), array("tank/rp\treadonly\ton", "tank/lp\tmountpoint\tlegacy", "tank/rq/c\treadonly\ton")));
$moved = array();
foreach (array('tank/rp/c' => 'tank/rpc', 'tank/lp/c' => 'tank/lpc', 'tank/rq/c' => 'tank/rqc') as $zdataset => $to) {
	$answer = renameDataset($zdataset, $longer($to), 0, $cfg);
	$moved += $answer['failed'] + $answer['succeeded'];
}
$too = function($zdataset) { return 'Nothing was renamed: the names of '.$zdataset.' and its copy would be too long for the bookmark a copy keeps; pick a shorter name'; };
same(array($longer('tank/rpc') => $too($longer('tank/rpc')), $longer('tank/lpc') => $too($longer('tank/lpc')), $longer('tank/rqc') => 0), $moved,
	'one moved out from under a read-only or legacy parent is checked; one read-only itself is not');
// Under a legacy or read-only parent, or inheriting that from a dataset that moves with it, no run copies it; with the parent or the
// sources not read, it is checked.
$add(array('tank/arch', 'tank/frz', 'tank/d1', 'tank/d2', 'tank/d3', 'tank/lx', 'tank/lx/c', 'tank/lq', 'tank/lq/c'));
$pool('props', array_merge($read('pool/props'), array("tank/arch\tmountpoint\tlegacy", "tank/frz\treadonly\ton", "tank/lx\tmountpoint\tlegacy", "tank/lq\tmountpoint\tlegacy")));
$into = array(renameDataset('tank/d1', $longer('tank/arch/d1'), 0, $cfg)['succeeded'], renameDataset('tank/d2', $longer('tank/frz/d2'), 0, $cfg)['succeeded'],
	renameDataset('tank/lx', $longer('tank/lxx'), 0, $cfg)['succeeded']);
putenv('ZFSM_TEST_COVER_FAIL=tank/arch');
$into[] = renameDataset('tank/d3', $longer('tank/arch/d3'), 0, $cfg)['failed'];
putenv('ZFSM_TEST_COVER_FAIL');
putenv('ZFSM_TEST_SOURCE_FAIL=1');
$into[] = renameDataset('tank/lq/c', $longer('tank/lqc'), 0, $cfg)['failed'];
putenv('ZFSM_TEST_SOURCE_FAIL');
same(array(array($longer('tank/arch/d1') => 0), array($longer('tank/frz/d2') => 0), array($longer('tank/lxx') => 0), array($longer('tank/arch/d3') => $too($longer('tank/arch/d3'))),
	array($longer('tank/lqc') => $too($longer('tank/lqc')))), $into, 'one left out once moved may take a long name; with the parent or the sources not read it is checked');
// Its own copies go with it, so the room is that under their Copy Under.
$far = 'backup/'.str_repeat('c', 20);
$kin = str_repeat('n', 50);
$add(array($far, 'tank/j', 'tank/j/'.$kin));
same(true, $save('tank/j', $part('default'), $part('days', '05:00', '1', 0, $far))['saved'], 'own copies under a long name');
same(array(array('tank/jj' => 'Nothing was renamed: the names of tank/jj/'.$kin.' and its copy would be too long for the bookmark a copy keeps; pick a shorter name'), true),
	array(renameDataset('tank/j', 'tank/jj', 0, $cfg)['failed'], $save('tank/j', $part('default'), $part('default'))['saved']), 'the room under its own Copy Under');
same(array(true, array('tank/k'.str_repeat('x', 10) => 0)), array($save('tank/k', $part('default'), $part('none'))['saved'], renameDataset('tank/k', 'tank/k'.str_repeat('x', 10), 0, $cfg)['succeeded']),
	'none needed where no copy is made');
// A replication of it or one below it running: nothing is renamed, and the locks it took are let go.
$add(array('tank/lk', 'tank/lk/kid'));
$lock = zfsmJobLock(zfsmJobLockFile('tank/lk/kid', 'replicate'), false);
$busy = renameDataset('tank/lk', 'tank/lk2', 0, $cfg);
zfsmJobUnlock($lock);
$loose = zfsmJobLock(zfsmJobLockFile('tank/lk', 'replicate'), false);
same(array(array('succeeded' => array(), 'failed' => array('tank/lk2' => 'Nothing was renamed: a replication of tank/lk/kid is running')), true, true),
	array($busy, is_resource($loose), in_array('tank/lk/kid', $datasets(), true)), 'nothing renamed while one below it copies, and its lock let go');
zfsmJobUnlock($loose);
// Docker's legacy-mounted image layers, which no run copies: one is checked, where a run by hand made its lock, but not held. A
// read-only one and a legacy one a repeat set in Replicate copies are held.
$add(array('tank/lk/layer', 'tank/lk/fresh', 'tank/lk/ro', 'tank/lk/leg'));
$pool('props', array_merge($read('pool/props'), array("tank/lk/layer\tmountpoint\tlegacy", "tank/lk/fresh\tmountpoint\tlegacy", "tank/lk/leg\tmountpoint\tlegacy", "tank/lk/ro\treadonly\ton")));
same(true, zfsmScheduleChange(function($jobs) { return $jobs + array('6f8b2c3d' => array('id' => '6f8b2c3d', 'dataset' => 'tank/lk/leg', 'dest' => 'backup/leg', 'server' => '', 'every' => 'daily', 'hour' => 3, 'day' => 0)); })['saved'],
	'a repeat set in Replicate of a legacy-mounted one');
$past = array();
foreach (array('tank/lk/layer', 'tank/lk/ro', 'tank/lk/leg') as $below) {
	$held = zfsmJobLock(zfsmJobLockFile($below, 'replicate'), false);
	$past[] = renameDataset('tank/lk', 'tank/lk3', 0, $cfg);
	zfsmJobUnlock($held);
	if ($past[count($past) - 1]['succeeded']) renameDataset('tank/lk3', 'tank/lk', 0, $cfg);
}
$running = function($zdataset) { return array('succeeded' => array(), 'failed' => array('tank/lk3' => 'Nothing was renamed: a replication of '.$zdataset.' is running')); };
same(array($running('tank/lk/layer'), $running('tank/lk/ro'), $running('tank/lk/leg')), $past, 'a replication of one below it, a legacy-mounted one by hand too, is waited for');
$fresh = zfsmJobLockFile('tank/lk/fresh', 'replicate');
putenv('ZFSM_TEST_LOCK_PROBE='.zfsmJobLockFile('tank/lk/layer', 'replicate'));
$past = renameDataset('tank/lk', 'tank/lk3', 0, $cfg)['succeeded'];
putenv('ZFSM_TEST_LOCK_PROBE');
$probed = $read('pool/probe.log');
renameDataset('tank/lk3', 'tank/lk', 0, $cfg);
same(array(array('tank/lk3' => 0), 'free', false), array($past, end($probed), file_exists($fresh)), 'a legacy-mounted one no repeat copies is not held through the rename, nor its lock made');
putenv('ZFSM_TEST_LOCK_PROBE='.zfsmJobLockFile('tank/lk/leg', 'replicate'));
$past = renameDataset('tank/lk', 'tank/lk3', 0, $cfg)['succeeded'];
putenv('ZFSM_TEST_LOCK_PROBE');
$probed = $read('pool/probe.log');
renameDataset('tank/lk3', 'tank/lk', 0, $cfg);
same(array(array('tank/lk3' => 0), 'held'), array($past, end($probed)), 'one a repeat copies is');
same(true, zfsmScheduleChange(function($jobs) { unset($jobs['6f8b2c3d']); return $jobs; })['saved'], 'the repeat removed');
putenv('ZFSM_TEST_TREE_FAIL=1');
$unread = renameDataset('tank/lk', 'tank/lk2', 0, $cfg)['failed'];
putenv('ZFSM_TEST_TREE_FAIL');
file_put_contents($work.'/plain', '');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/plain');
$unopened = renameDataset('tank/lk', 'tank/lk2', 0, $cfg)['failed'];
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
same(array(array('tank/lk2' => 'Nothing was renamed: the datasets at and below it could not be read: cannot open \'tank/lk\': Input/output error'),
	array('tank/lk2' => 'Nothing was renamed: the replication lock of tank/lk could not be opened'), true), array($unread, $unopened, in_array('tank/lk/kid', $datasets(), true)),
	'nor when they could not be read or locked');
// One whose own Replication is None: nothing copies it there, so its copy stays.
$add(array('tank/n', 'backup/tank/n'));
same(true, $save('tank/n', $part('default'), $part('none'))['saved'], 'None');
same(array($copy('tank/n') => 'left where it is, as no schedule copies the renamed dataset or one below it there'), renameDataset('tank/n', 'tank/final', 0, $cfg)['failed'], 'nothing copies it there');
same(array(true, false), array(in_array('backup/tank/n', $datasets(), true), in_array('backup/tank/final', $datasets(), true)), 'it stays');

// One below it still copied there: the holder over that copy follows the rename too.
$add(array('tank/p', 'tank/p/kid', 'backup/tank/p', 'backup/tank/p/kid'));
$pool('props', array_merge($read('pool/props'), array("backup/tank/p\tzfs.master:holder\ton")));
same(array(true, true), array($save('tank/p', $part('default'), $part('none'))['saved'], $save('tank/p/kid', $part('default'), $part('hourly', '', '', 0, 'backup'))['saved']), 'None above, its own below');
$logged = count($read('calls.log'));
same(array('succeeded' => array('tank/p2' => 0), 'failed' => array()), renameDataset('tank/p', 'tank/p2', 0, $cfg), 'a rename of the one above');
same(array(), preg_grep('/^list -H -o name,readonly,mountpoint -t filesystem,volume -r /', array_slice($read('calls.log'), $logged)), 'whose names fit: the datasets below it are not listed');
same(array('backup/tank/p2', 'backup/tank/p2/kid'), array_values(preg_grep('~^backup/tank/p~', $datasets())), 'its copy and the one below follow it');
// Its schedules not moved, as another change holds them: its copies follow as they would have, ready for its schedules set again.
$lock = zfsmJobLock(zfsmJobDir('schedule').'/settings.lock', false);
$kept = $stored();
same(array('Schedules of tank/p2'), array_keys(renameDataset('tank/p2', 'tank/p3', 0, $cfg)['failed']), 'only the schedules are said');
zfsmJobUnlock($lock);
same($kept, $stored(), 'its schedules stay as they were');
same(array('backup/tank/p3', 'backup/tank/p3/kid'), array_values(preg_grep('~^backup/tank/p~', $datasets())), 'its copies follow it');
// One with no copy there: nothing to say.
same(array('succeeded' => array('tank/r3' => 0), 'failed' => array()), renameDataset('tank/r2', 'tank/r3', 0, $cfg), 'one with no copy');
// Not a copy of the renamed dataset, as one left by a destroy before a dataset of that name was made again: it stays, and why.
$add(array('tank/a', 'backup/tank/a', 'tank/e', 'backup/tank/e', 'tank/f', 'backup/tank/f', 'tank/g', 'backup/tank/g'));
$pool('marks', array_merge($read('pool/marks'), array("tank/a@new\t96\t501", "backup/tank/a@old\t95\t400")));
same(array($copy('tank/a') => 'left where it is, as its newest snapshot is not on the renamed dataset'), renameDataset('tank/a', 'tank/a2', 0, $cfg)['failed'], 'a copy of another dataset');
same(array($copy('tank/e') => 'left where it is, as it holds no snapshot of the renamed dataset'), renameDataset('tank/e', 'tank/e2', 0, $cfg)['failed'], 'one with no snapshot that is no holder');
$copied('tank/f', 83);
$copied('tank/g', 87);
putenv('ZFSM_TEST_MARKS_FAIL=backup/tank/f');
same(array($copy('tank/f') => 'not renamed with it, as its snapshots could not be read'), renameDataset('tank/f', 'tank/f2', 0, $cfg)['failed'], 'one whose snapshots could not be read');
putenv('ZFSM_TEST_MARKS_FAIL=tank/g2');
same(array($copy('tank/g') => 'not renamed with it, as the snapshots of the renamed dataset could not be read'), renameDataset('tank/g', 'tank/g2', 0, $cfg)['failed'], 'nor those of the renamed one');
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
	renameDataset('tank/b1', 'tank/b1x', 0, $cfg)['failed'], 'a bookmark not made');
putenv('ZFSM_TEST_BOOKMARK_FAIL');
$marked('tank/b2', 85);
putenv('ZFSM_TEST_BOOKMARKS_FAIL=1');
same(array($copy('tank/b2') => 'renamed with it, but its bookmarks could not be read: cannot open \'tank/b2x\': Input/output error'), renameDataset('tank/b2', 'tank/b2x', 0, $cfg)['failed'], 'its bookmarks not read');
putenv('ZFSM_TEST_BOOKMARKS_FAIL');
$marked('tank/b3', 86);
putenv('ZFSM_TEST_DESTROY_FAIL=tank/b3x#'.zfsmReplicaBookmarkPrefix('backup/tank/b3').'s');
same(array($copy('tank/b3') => 'renamed with it, but not all its bookmarks were: cannot destroy \'tank/b3x#'.zfsmReplicaBookmarkPrefix('backup/tank/b3').'s\': Input/output error'),
	renameDataset('tank/b3', 'tank/b3x', 0, $cfg)['failed'], 'the old one not removed');
putenv('ZFSM_TEST_DESTROY_FAIL');
same(array('backup/tank/b1x', 'backup/tank/b2x', 'backup/tank/b3x'), array_values(preg_grep('~^backup/tank/b\d~', $datasets())), 'each copy renamed');
// A bookmark name can end in a space (OpenZFS), the last one listed too.
$marked('tank/b4', 91);
$pool('marks', preg_replace('~^(tank/b4#[^\t]*s)\t~', "$1 \t", $read('pool/marks')));
same(array(array(), array('tank/b4x#'.zfsmReplicaBookmarkPrefix('backup/tank/b4x').'s ')), array(renameDataset('tank/b4', 'tank/b4x', 0, $cfg)['failed'], $bookmarks('tank/b4x')),
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
same(array('succeeded' => array('tank/h1x' => 0), 'failed' => array()), renameDataset('tank/h1', 'tank/h1x', 0, $cfg), 'a copy a stopped run holds');
same(array(array("tank/h1x@s\tkeep", "tank/h1x@s\t".$tag('backup/tank/h1x')), array("tank/h1x/k@s\tkeep", "tank/h1x/k@s\t".$tag('backup/tank/h1x/k'))),
	array($holds('tank/h1x'), $holds('tank/h1x/k')), 'its hold and the one below take the new names');
foreach (array('h2' => array('ZFSM_TEST_HELD_FAIL', 'its holds could not be read: cannot open \'tank/h2x\': Input/output error'),
	'h3' => array('ZFSM_TEST_HOLDS_READ_FAIL', 'its holds could not be read: cannot get holds for \'tank/h3x@s\': permission denied'),
	'h4' => array('ZFSM_TEST_HOLD_FAIL', 'not all its holds were: tank/h4x@s (cannot hold snapshot \'tank/h4x@s\': permission denied)'),
	'h5' => array('ZFSM_TEST_RELEASE_FAIL', 'not all its holds were: tank/h5x@s (cannot release hold from snapshot \'tank/h5x@s\': permission denied)')) as $name => list($knob, $why)) {
	$held('tank/'.$name, 90 + (int)substr($name, 1));
	putenv($knob.'=1');
	same(array($copy('tank/'.$name) => 'renamed with it, but '.$why), renameDataset('tank/'.$name, 'tank/'.$name.'x', 0, $cfg)['failed'], $knob.': said');
	putenv($knob);
}
same(array("tank/h4x@s\t".$tag('backup/tank/h4'), "tank/h4x@s\tkeep"), $holds('tank/h4x'), 'a hold not moved stays as it was');
// A snapshot name can end in a space (OpenZFS).
$held('tank/h6', 96);
foreach (array('marks', 'holds') as $file) $pool($file, preg_replace('~^tank/h6@s\t~', "tank/h6@s \t", $read('pool/'.$file)));
same(array(array('tank/h6x' => 0), array("tank/h6x@s \tkeep", "tank/h6x@s \t".$tag('backup/tank/h6x'))), array(renameDataset('tank/h6', 'tank/h6x', 0, $cfg)['succeeded'], $holds('tank/h6x')),
	'the hold on a snapshot whose name ends in a space takes the new name');
same(array('saved' => false, 'why' => 'Nothing was saved: that is no dataset name', 'said' => ''), $save('', $part('hourly'), $part('hourly', '', '', 0, 'usb')), 'the dialog saves no Settings schedule');

// The schedules not read: where its copies go is not known, so none is renamed, and that is said.
$add(array('tank/w', 'backup/tank/w'));
$copied('tank/w', 88);
$text = file_get_contents(zfsmScheduleFile());
file_put_contents(zfsmScheduleFile(), '{');
$answer = renameDataset('tank/w', 'tank/w2', 0, $cfg);
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
same(array(array('succeeded' => array('tank/s1' => 0), 'failed' => array()), array(), array()), array(destroyDataset('tank/s1', 1), preg_grep('~^tank/s1[\t@/]~', $read('pool/props')),
	preg_grep('~^tank/s1[\t@#/]~', array_merge($read('pool/datasets'), $read('pool/marks')))),
	'with -r and neither (a clone below it is no dependent outside), it goes with its snapshot, its bookmark, the clone below it and their properties');

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
