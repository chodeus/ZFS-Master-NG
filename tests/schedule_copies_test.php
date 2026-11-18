<?php
// Copies under a dataset (include/ZFSMScheduleCopies.php), Settings' Replicate Every Dataset, through the real store, runs, holders and
// Keep, with the fake zfs's small pool (ZFSM_TEST_REPL_DIR) and Unraid's crontab rebuild faked under $GLOBALS['zfsm_root'].

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

// Unraid's nchan publish, which a run calls as it sends: 'zfsm_publish' 'throw' throws once, a number sleeps that long once.
function publish($channel, $message) {
	$then = $GLOBALS['zfsm_publish'] ?? null;
	$GLOBALS['zfsm_publish'] = null;
	if ($then === 'throw') throw new RuntimeException('nchan is down');
	if (is_int($then)) sleep($then);
}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-copysched-'.getmypid().'-'.bin2hex(random_bytes(4));
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
$snaps = function($dataset) { return array_column(zfsmKeepSnapshots($dataset) ?? array(), 'tag', 'name'); };
$logged = function($pattern) { return preg_match($pattern.'m', (string)@file_get_contents(zfsmLogFile())); };
$cfg = array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$name = function($now) use ($cfg) { return zfsmNewSnapshotName($cfg, $now); };
$tag = zfsmKeepTag('', 'backup/');
// Three pools. Left out: the pool the copies go into, a Docker layer (legacy) and a dataset Sanoid snapshots, whose child is copied.
$fresh = function() use ($pool) {
	$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/docker\tfilesystem", "tank/docker/0a1b\tfilesystem", "tank/san\tfilesystem",
		"tank/san/kid\tfilesystem", "tank/vm\tvolume", "usb\tfilesystem", "backup\tfilesystem", "backup/old\tfilesystem"));
	$pool('marks', array("tank/san@autosnap_2026-11-01_00:00:00_daily\t11\t10"));
	$pool('props', array("tank/docker/0a1b\tmountpoint\tlegacy"));
	$pool('holds', array());
};
$fresh();
$covered = array('tank', 'tank/data', 'tank/docker', 'tank/san/kid', 'tank/vm', 'usb');

// The record: Replicate Every Dataset, or a dataset's own, copies under a dataset here (dest ends in /); None (dest /) only a dataset's.
$every = array('id' => '0a1b2c3d', 'dataset' => '', 'dest' => 'backup/', 'server' => '', 'every' => 'hourly', 'minute' => 30, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);
$records = array($every, array('dataset' => 'tank/data', 'every' => 'days') + $every, array('dataset' => 'tank/data', 'every' => 'none', 'dest' => '/') + $every,
	array('every' => 'none', 'dest' => '/') + $every, array('dest' => '/') + $every, array('dest' => 'bad name!/') + $every, array('server' => 'nas') + $every,
	array('dataset' => 'tank/data', 'every' => 'none') + $every);
same(array(true, true, true, false, false, false, false, false), array_map('zfsmScheduleValid', $records), 'which records copy under a dataset');
// A version before this one reads any dest but '' as one copy's, whose dataset and name these never are: it runs none of them.
same(array(false, false, false), array_map('zfsmScheduleCopyValid', array_slice($records, 0, 3)), 'an older version runs none of them');
same(array('zfsmScheduleCopiesUnder', 'zfsmScheduleSnapshotsOnly'), array(zfsmScheduleKindOf($every), zfsmScheduleKindOf(array('dest' => '') + $every)), 'each kind');

// It shares datasets with a snapshot schedule or a copy's that it covers, never with another of its kind.
$snapshots = array('id' => 'aaaa0000', 'dest' => '') + $every;
$copy = array('id' => 'bbbb0000', 'dataset' => 'tank/data', 'dest' => 'backup/data') + $every;
$own = function($dataset) use ($every) { return array('id' => 'cccc0000', 'dataset' => $dataset) + $every; };
same(array(true, false, true, false, true, false, false),
	array(zfsmScheduleShare($every, $snapshots), zfsmScheduleShare($every, $own('tank')), zfsmScheduleShare($own('tank'), $copy), zfsmScheduleShare($own('usb'), $copy),
		zfsmScheduleShare($own('tank/data'), array('dataset' => 'tank') + $snapshots), zfsmScheduleShare($own('usb'), array('dataset' => 'tank') + $snapshots),
		zfsmScheduleShare(array('every' => 'none', 'dest' => '/') + $own('tank'), $snapshots)), 'which schedules share a dataset');

// Covered: each dataset but those left out, by pool; not one whose own copy's schedule makes that copy.
$listed = function() use ($cfg) { return zfsmScheduleCoverRead($cfg, 10); };
same(array(array('tank' => array('tank', 'tank/data', 'tank/docker', 'tank/san/kid', 'tank/vm'), 'usb' => array('usb')), ''), zfsmScheduleCovered($listed(), $every, array($every)), 'it covers each dataset but those left out');
same(array('tank', 'tank/docker', 'tank/san/kid', 'tank/vm'), zfsmScheduleCovered($listed(), $every, array($every, array('dest' => 'backup/tank/data') + $copy))[0]['tank'], 'not one whose own copy is that copy');
same(array('tank', 'tank/data', 'tank/docker', 'tank/san/kid', 'tank/vm'), zfsmScheduleCovered($listed(), $every, array($every, array('dest' => 'backup/data') + $copy))[0]['tank'], 'but one whose own copy is elsewhere');
$not = function($zdataset, $jobs = array(), $row = array('off', '/mnt/x', false)) use ($every, $name) { return zfsmScheduleCopiesNot($every, $jobs, $zdataset, $row, $name(1800000000)); };
same(array('backup/old is in pool backup, where its copy would go', 'tank/data has a schedule of its own for that copy', '', 'the snapshot names of tank/data could not be read, so it may be left to Sanoid or Syncoid'),
	array($not('backup/old'), $not('tank/data', array(array('dest' => 'backup/tank/data') + $copy)), $not('tank/data'), $not('tank/data', array(), array('off', '/mnt/tank/data', null))), 'why one is left out');
// The bookmark that keeps a copy incremental names the dataset and its copy (zfsmReplicaBookmarkFits): under backup, 71 fit.
same(array('', 'the names of tank/'.str_repeat('a', 67).' and its copy are too long for the bookmark a copy keeps'), array($not('tank/'.str_repeat('a', 66)), $not('tank/'.str_repeat('a', 67))), 'one too long');

// Save: Copy Under must be a filesystem here; what is wrong is said, and nothing saved.
$wrong = array(
	array('nowhere', 'hourly', '', '', 'Nothing was saved: nowhere does not exist on this server'),
	array('tank/vm', 'hourly', '', '', 'Nothing was saved: tank/vm is a volume, which holds no datasets'),
	array('bad name!', 'hourly', '', '', 'Nothing was saved: under Copy Under, name a dataset on this server, such as backup'),
	array('', 'hourly', '', '', 'Nothing was saved: under Copy Under, name a dataset on this server, such as backup'),
	array('backup', 'days', '', '9', 'Nothing was saved: set the time it starts at; tick at least one day'),
);
same(array_column($wrong, 4), array_map(function($try) use ($cfg) { return zfsmScheduleCopiesSave($try[0], $try[1], $try[2], $try[3], 0, $cfg)['why']; }, $wrong), 'what is wrong is said');
same(null, $stored(), 'and nothing is saved');
same(array('off', ''), array(zfsmScheduleShown('zfsmScheduleCopiesUnder')['every'], zfsmScheduleShown('zfsmScheduleCopiesUnder')['dest']), 'Settings shows Off');

// Every hour: at a minute Snapshot Every Dataset doesn't start at; the answer counts what it copies, and nothing is copied on saving.
zfsmScheduleSnapshotSave('', 'hourly', '', '', 0, $cfg);
$taken = array_values($stored())[0]['minute'];
$reset();
$saved = zfsmScheduleCopiesSave('backup', 'hourly', '', '', 0, $cfg);
$job = array_values(array_filter($stored(), 'zfsmScheduleCopiesUnder'))[0];
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a copy of each of 6 dataset(s) under backup every hour at :'.sprintf('%02d', $job['minute']).'; it keeps all its snapshots'), $saved, 'saved, counted');
same(array_replace($every, array('id' => $job['id'], 'minute' => $job['minute'])), $job, 'as the record');
same(true, $job['minute'] !== $taken, 'at a minute of its own');
same(array(), preg_grep('/^(snapshot|send|receive|create) /', $read('calls.log')), 'nothing copied on saving');
same(array('hourly', 'backup/'), array(zfsmScheduleShown('zfsmScheduleCopiesUnder')['every'], zfsmScheduleShown('zfsmScheduleCopiesUnder')['dest']), 'Settings shows it');
same(1, preg_match('/>Replicate Every Dataset under backup, every hour at :\d\d, keeps all its snapshots</', zfsmSchedulesHtml($cfg)), 'the list names it');
// On days at the time Snapshot Every Dataset runs then: refused; saved again it keeps its id.
$at = sprintf('04:%02d', ($job['minute'] + 1) % 60);
same(true, zfsmScheduleSnapshotSave('', 'days', $at, '1', 0, $cfg)['saved'], 'Snapshot Every Dataset moved');
same('Nothing was saved: Snapshot Every Dataset (every Monday at '.$at.') starts then too; pick another time', zfsmScheduleCopiesSave('backup', 'days', $at, '1,2', 0, $cfg)['why'], 'not at the same minute');
same('Saved: a copy of each of 6 dataset(s) under backup every Monday at 05:00; it keeps the newest 1 of the snapshots of each dataset it copies', zfsmScheduleCopiesSave('backup', 'days', '05:00', '1', 1, $cfg)['said'], 'another time');
same(array($job['id'], 'days', 1), array(array_values(array_filter($stored(), 'zfsmScheduleCopiesUnder'))[0]['id'], array_values(array_filter($stored(), 'zfsmScheduleCopiesUnder'))[0]['every'], array_values(array_filter($stored(), 'zfsmScheduleCopiesUnder'))[0]['keep']), 'in place');
// Off: removed, the snapshot schedule left.
same(array('saved' => true, 'why' => '', 'said' => 'Replicate Every Dataset is off; the copies it made and their snapshots stay'), zfsmScheduleCopiesSave('', 'off', '', '', 0, $cfg), 'off');
same(array(''), array_column($stored(), 'dest'), 'only the snapshot schedule stays');

// Runs: Replicate Every Dataset alone, keeping the newest 1.
$id = '0a1b2c3d';
file_put_contents(zfsmScheduleFile(), json_encode(array(array('keep' => 1) + $every)));
$t = 1800000000;

// The first, with tank's snapshot refused: the datasets below it wait, as their copies go inside its; usb is copied. One notification.
$reset();
putenv('ZFSM_TEST_SNAP_FAIL=tank');
same(1, zfsmScheduleRun($id, $cfg, $t), 'a run with a problem');
putenv('ZFSM_TEST_SNAP_FAIL');
same(array('backup/usb'), array_values(preg_grep('~^backup/(?!old$)~', $datasets())), 'only usb is copied');
same(array('warning: ZFS Replication'), $read('notify.log'), 'one notification');
same(1, preg_match('/^warning: Scheduled copies under backup: 1 of 6 dataset\(s\) copied\. Not copied, as the copy of a dataset above it is missing \(tank\/data, tank\/docker, tank\/san\/kid, and 1 more\)\. Replication of tank to backup\/tank not started: .*out of space\. Later problems/', $read('notify-detail.log')[0] ?? ''), 'naming what waits and why');

// The next: parents first, a holder in place of tank/san (left to Sanoid), each copy read-only and unmounted; tagged for the record.
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 3600), 'the next run works');
$n = $name($t + 3600);
same(array('backup/usb', 'backup/tank', 'backup/tank/data', 'backup/tank/docker', 'backup/tank/san', 'backup/tank/san/kid', 'backup/tank/vm'), array_values(preg_grep('~^backup/(?!old$)~', $datasets())), 'in order, parents first');
same(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/tank/san'), array_values(preg_grep('/^create /', $read('calls.log'))), 'one holder');
same(array(true, false, false), array(zfsmReplicaHolder(array('server' => ''), 'backup/tank/san'), zfsmReplicaHolder(array('server' => ''), 'backup/tank/san/kid'), zfsmReplicaHolder(array('server' => ''), 'backup/tank')), 'a holder is one by its own property, not one inherited');
same(array('normal: Scheduled copies under backup: 6 of 6 dataset(s) copied. It works again after 1 run(s) with a problem.'), $read('notify-detail.log'), 'and says so');
same(array("tank/data@$n" => $tag), $snaps('tank/data'), 'its snapshot is tagged for the record');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under backup: 6 of 6 dataset\(s\) copied$/'), 'logged');
same(array(), preg_grep('/receive .*-F/', $read('calls.log')), 'no -F, as no holder is replaced');

// The one after sends changes; Keep leaves the newest 1 of the record's snapshots on each dataset.
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 7200), 'a run of changes');
$m = $name($t + 7200);
same(array("tank/data@$m" => $tag), $snaps('tank/data'), 'Keep leaves the newest');
same(array("backup/tank/san/kid@$m"), array_keys($snaps('backup/tank/san/kid')), 'in the copy too');
same(6, count(preg_grep('/^send -P -v -L -i /', $read('calls.log'))), 'each sends its changes');
same(array(), $read('notify.log'), 'only logged');

// A copy gone, as when the pool it goes into was made again: the next run makes it again in full.
$pool('datasets', array_values(array_filter($read('pool/datasets'), function($line) { return strpos($line, "backup/usb\t") !== 0; })));
$pool('marks', array_values(array_filter($read('pool/marks'), function($line) { return strpos($line, 'backup/usb@') !== 0; })));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 9000), 'a run with a copy gone');
same(array(true, 1), array(in_array('backup/usb', $datasets(), true), count(preg_grep('/^send -P -v -L usb@/', $read('calls.log')))), 'makes it again in full');

// tank/san no longer Sanoid's: its first copy replaces the holder in its place (receive -F), and the copy below stays; the copy loses
// the holder's mark, which the one below inherited.
$pool('marks', array_values(array_filter($read('pool/marks'), function($line) { return strpos($line, 'tank/san@autosnap_') !== 0; })));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 10800), 'a run with a holder in a copy\'s place');
$o = $name($t + 10800);
same(array(1, array("backup/tank/san@$o")), array(count(preg_grep('/^receive -s -u -o readonly=on -F -o canmount=noauto backup\/tank\/san$/', $read('calls.log'))), array_keys($snaps('backup/tank/san'))), 'its copy replaces the holder');
same(array(false, false), array(zfsmReplicaHolder(array('server' => ''), 'backup/tank/san'), zfsmReplicaHolder(array('server' => ''), 'backup/tank/san/kid')), 'the mark is cleared');
same(array(1, array("backup/tank/san/kid@$o")), array(count(preg_grep('/^send -P -v -L -i \S+ tank\/san\/kid@/', $read('calls.log'))), array_keys($snaps('backup/tank/san/kid'))), 'the one below stays: it gets only the changes');
same(1, count(preg_grep('/^receive .*-F/', $read('calls.log'))), '-F only onto the holder');

// An encrypted dataset's raw copy can't replace a holder: zfs refuses it there, so the plan says what to do instead.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/enc\tfilesystem", "backup/tank/enc\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/enc@a\t4242\t9000")));
$pool('props', array_merge($read('pool/props'), array("tank/enc\tencryption\taes-256-gcm", "backup/tank/enc\tzfs.master:holder\ton")));
same(array('can_proceed' => false, 'error' => 'backup/tank/enc only holds the copies of datasets below this one, and zfs cannot receive the raw copy of this encrypted dataset over it: destroy that dataset and the copies below it for the next run to send them all (a copy a repeat set in Replicate made asks for one Replicate by hand)'),
	zfsmReplicatePlan('tank/enc', 'a', 'backup/tank/enc', array('estimate' => false)), 'a raw stream onto a holder');
// Nor any copy onto an encrypted holder, as one under an encrypted dataset is (live, 2.4.4); one not read is not sent onto.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/sealed\tfilesystem", "backup/tank/sealed\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/sealed@a\t4646\t9004")));
$pool('props', array_merge($read('pool/props'), array("backup/tank/sealed\tzfs.master:holder\ton", "backup/tank/sealed\tencryption\taes-256-gcm")));
$plan = function() { return zfsmReplicatePlan('tank/sealed', 'a', 'backup/tank/sealed', array('estimate' => false)); };
same(array('can_proceed' => false, 'error' => 'backup/tank/sealed only holds the copies of datasets below this one, and zfs cannot receive a copy over it, as it is encrypted: destroy that dataset and the copies below it for the next run to send them all (a copy a repeat set in Replicate made asks for one Replicate by hand)'),
	$plan(), 'a stream onto an encrypted holder');
putenv('ZFSM_TEST_CRYPT_FAIL=backup/tank/sealed');
same(array('can_proceed' => false, 'error' => 'backup/tank/sealed only holds the copies of datasets below this one, and whether it is encrypted could not be read'), $plan(), 'one whose encryption could not be read');
putenv('ZFSM_TEST_CRYPT_FAIL');

// A first copy onto a holder that stops part-way carries on only with -F again (OpenZFS 2.4.4); the mark goes once it lands.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/res\tfilesystem", "backup/tank/res\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/res@a\t4343\t9001")));
$pool('props', array_merge($read('pool/props'), array("backup/tank/res\tzfs.master:holder\ton")));
$reset();
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/res', 'a', 'backup/tank/res'), 'a first copy onto a holder, cut off');
putenv('ZFSM_TEST_SEND_CUT');
same(array(true, 'resume'), array(zfsmReplicaHolder(array('server' => ''), 'backup/tank/res'), zfsmReplicatePlan('tank/res', 'a', 'backup/tank/res', array('estimate' => false))['mode']), 'still a holder, part-received');
putenv('ZFSM_TEST_MARKS_FAIL=backup/tank/res');
same(array('can_proceed' => false, 'error' => 'The snapshots of backup/tank/res could not be read'), zfsmReplicatePlan('tank/res', 'a', 'backup/tank/res', array('estimate' => false)), 'not carried on when its snapshots could not be read');
putenv('ZFSM_TEST_MARKS_FAIL');
same(0, zfsmRunReplicate('tank/res', 'a', 'backup/tank/res'), 'carried on');
same(array(2, array('backup/tank/res@a'), false), array(count(preg_grep('/^receive -s -u -o readonly=on -F /', $read('calls.log'))), array_keys($snaps('backup/tank/res')), zfsmReplicaHolder(array('server' => ''), 'backup/tank/res')), 'with -F both times, landed, the mark gone');

// A mark that could not be cleared is told; the copy holds a snapshot then, so a later run of changes cut off carries on without -F.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/mark\tfilesystem", "backup/tank/mark\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/mark@a\t4444\t9002", "tank/mark@b\t4445\t9003")));
$pool('props', array_merge($read('pool/props'), array("backup/tank/mark\tzfs.master:holder\ton")));
$reset();
putenv('ZFSM_TEST_INHERIT_FAIL=1');
same(0, zfsmRunReplicate('tank/mark', 'a', 'backup/tank/mark'), 'a first copy onto a holder whose mark stays');
putenv('ZFSM_TEST_INHERIT_FAIL');
same(array('warning: Replication of tank/mark@a to backup/tank/mark finished (in full). The copy still carries the mark of the empty dataset it replaced: cannot inherit zfs.master:holder for ?backup/tank/mark?: permission denied'), $read('notify-detail.log'), 'says so');
$reset();
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/mark', 'b', 'backup/tank/mark'), 'its changes, cut off');
putenv('ZFSM_TEST_SEND_CUT');
same(array('resume', false), array_values(array_intersect_key(zfsmReplicatePlan('tank/mark', 'b', 'backup/tank/mark', array('estimate' => false)), array('mode' => 0, 'holder' => 0))), 'carried on as changes, not onto a holder');
same(array(0, array(), array('backup/tank/mark@a', 'backup/tank/mark@b')), array(zfsmRunReplicate('tank/mark', 'b', 'backup/tank/mark'), preg_grep('/^receive .*-F/', $read('calls.log')), array_keys($snaps('backup/tank/mark'))), 'without -F');

// One cut off part-way holds for each copy, as a run by hand does: carried on by hand, its holds come off.
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmScheduleRun($id, $cfg, $t + 12600);
putenv('ZFSM_TEST_SEND_CUT');
$p = $name($t + 12600);
$hold = zfsmKeepHoldTag(zfsmKeepTag('', 'backup/tank/data'));
same(array("tank/data@$p\t$hold", "tank/data@$o\t$hold"), array_values(preg_grep('~^tank/data@~', $read('pool/holds'))), 'a run cut off holds for the copy');
same(0, zfsmRunReplicate('tank/data', $p, 'backup/tank/data'), 'carried on by hand');
same(array(), preg_grep('~^tank/data@~', $read('pool/holds')), 'which takes its holds off');
// The next run carries the others on, then sends what it took: a warning of the first step is told with the second.
$reset();
@unlink(zfsmScheduleStateFile($id));
putenv('ZFSM_TEST_BOOKMARK_FAIL=1');
zfsmScheduleRun($id, $cfg, $t + 13500);
putenv('ZFSM_TEST_BOOKMARK_FAIL');
same(1, preg_match('/^warning: .*'.preg_quote('tank@'.$p, '/').' to backup\/tank finished \(carried on from where it stopped\)\. The bookmark that keeps the next run incremental could not be made: .*?\. Replication of '.preg_quote('tank@'.$name($t + 13500), '/').' to backup\/tank finished \(changes since /',
	implode("\n", $read('notify-detail.log'))), 'the first step\'s warning, then the second\'s: '.implode("\n", $read('notify-detail.log')));
// A first copy onto a holder, cut off, then carried on: a mark that stays is a problem of the run, though the next step lands cleanly
// (the notification's cut ends the line before that step).
$pool('datasets', array_merge($read('pool/datasets'), array("tank/ahres\tfilesystem", "backup/tank/ahres\tfilesystem")));
$pool('props', array_merge($read('pool/props'), array("backup/tank/ahres\tzfs.master:holder\ton")));
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmScheduleRun($id, $cfg, $t + 13800);
putenv('ZFSM_TEST_SEND_CUT');
$reset();
@unlink(zfsmScheduleStateFile($id));
putenv('ZFSM_TEST_INHERIT_FAIL=1');
zfsmScheduleRun($id, $cfg, $t + 14100);
putenv('ZFSM_TEST_INHERIT_FAIL');
same(array(1, 1), array(preg_match('/^warning: Scheduled copies under backup: .*'.preg_quote('tank/ahres@'.$name($t + 13800), '/').' to backup\/tank\/ahres finished \(carried on from where it stopped\)\. The copy still carries the mark/m',
	implode("\n", $read('notify-detail.log'))), count(preg_grep('~^'.preg_quote('backup/tank/ahres@'.$name($t + 14100), '~').'\t~', $read('pool/marks')))),
	'the mark told, and the next step landed: '.implode("\n", $read('notify-detail.log')));
// Carried on with a warning, then the next step can't be planned: the first step's warning is told with why.
$told = array();
$by = array('notify' => function($title, $text, $type) use (&$told) { $told[] = $type.': '.$text; });
putenv('ZFSM_TEST_SEND_CUT=1');
zfsmRunReplicate('tank/data', 'cut1', 'backup/tank/data', null, true);
putenv('ZFSM_TEST_SEND_CUT');
putenv('ZFSM_TEST_BOOKMARK_FAIL=1');
putenv('ZFSM_TEST_MARKS_FAIL=backup/tank/data');
$code = zfsmRunReplicate('tank/data', 'cut2', 'backup/tank/data', null, true, '', $by);
putenv('ZFSM_TEST_BOOKMARK_FAIL');
putenv('ZFSM_TEST_MARKS_FAIL');
same(array(1, 1), array($code, preg_match('/^warning: Replication of tank\/data@cut1 to backup\/tank\/data finished \(carried on from where it stopped\)\. The bookmark that keeps the next run incremental could not be made: .*\. Replication of tank\/data to backup\/tank\/data not started: The snapshots of backup\/tank\/data could not be read$/', implode("\n", $told))),
	'the first step\'s warning, then why the next was not started: '.implode("\n", $told));

// Other replications of tank/data and tank/docker still running, after tank's send outlasted the budget: only waits use it, and
// they share it, so the second has none left.
$fresh();
$reset();
@unlink(zfsmScheduleStateFile($id));
$locks = array_map(function($dataset) { return zfsmJobLock(zfsmJobLockFile($dataset, 'replicate')); }, array('tank/data', 'tank/docker'));
$started = time();
$GLOBALS['zfsm_publish'] = 3;
same(1, zfsmScheduleRun($id, $cfg, $t + 14400, 2), 'a run that waits');
array_map('zfsmJobUnlock', $locks);
same(true, time() - $started < 8, 'in all, its budget and the slow send');
same(1, preg_match('/^warning: Scheduled copies under backup: 4 of 6 dataset\(s\) copied\. Replication of tank\/data to backup\/tank\/data not started: another replication of this dataset was still running after this run waited 1 minute\(s\) for it; Replication of tank\/docker to backup\/tank\/docker not started: another replication of this dataset was still running, and the run had no time left to wait for it/', $read('notify-detail.log')[0] ?? ''), 'each says so');

// A run marks its start on its lock file, which the next start reads.
$file = zfsmJobDir('schedule').'/'.$id.'.lock';
touch($file, time() - 90000);
$before = time();
zfsmScheduleRun($id, $cfg, $t + 17400);
clearstatcache();
same(true, filemtime($file) >= $before, 'a run marks its start');
// A start while the run before still goes: none made, only logged until that one has gone on a day, then notified.
$reset();
@unlink(zfsmScheduleStateFile($id));
$held = zfsmJobLock($file);
same(0, zfsmScheduleRun($id, $cfg, $t + 18000), 'a start while the run before goes');
same(array(array(), 1, 0), array($read('notify.log'), $logged('/ Schedule '.$id.': Scheduled copies not made: the run before is still going$/'), zfsmScheduleFailures($id)), 'only logged');
touch($file, time() - 90000);
same(1, zfsmScheduleRun($id, $cfg, $t + 18060), 'a start a day after it');
zfsmJobUnlock($held);
same(array(array('warning: ZFS Replication'), 1), array($read('notify.log'), preg_match('/^warning: Scheduled copies not made: the run before is still going, 25 hour\(s\) after it started/', $read('notify-detail.log')[0] ?? '')), 'notified');

// An error in one dataset's replication ends only that one: the datasets below it wait, usb is copied.
$fresh();
$reset();
@unlink(zfsmScheduleStateFile($id));
$GLOBALS['zfsm_publish'] = 'throw';
same(1, zfsmScheduleRun($id, $cfg, $t + 19800), 'a run that an error ends for one dataset');
same(array('backup/usb'), array_values(preg_grep('~^backup/(?!old$)~', $datasets())), 'the others go on');
same(1, preg_match('/^warning: Scheduled copies under backup: 1 of 6 dataset\(s\) copied\. Not copied, as the copy of a dataset above it is missing \(tank\/data, tank\/docker, tank\/san\/kid, and 1 more\)\. Replication of tank to backup\/tank failed: nchan is down\. Later problems/', $read('notify-detail.log')[0] ?? ''), 'naming it');

// A dataset gone before its turn: said, with no schedule of its own to remove.
$left = 2;
same(array(1, array('Replication of tank/gone to backup/tank/gone not started: it no longer exists', 'warning')), zfsmScheduleCopiesOne($every, 'tank/gone', 'backup/tank/gone', $name($t), $left), 'one gone before its turn');

// The snapshot names not read: which datasets are Sanoid's isn't known, so none is copied, and no snapshot taken.
$fresh();
$reset();
@unlink(zfsmScheduleStateFile($id));
putenv('ZFSM_TEST_NAMES_FAIL=1');
same(1, zfsmScheduleRun($id, $cfg, $t + 20400), 'a run without the names');
putenv('ZFSM_TEST_NAMES_FAIL');
same(array(array('backup', 'backup/old'), array()), array(array_values(preg_grep('~^backup~', $datasets())), preg_grep('/^(snapshot|send|create) /', $read('calls.log'))), 'nothing taken or copied');
same(1, preg_match('/^warning: Scheduled copies not made: the snapshot names could not be read: cannot iterate filesystems: Input\/output error/', $read('notify-detail.log')[0] ?? ''), 'saying why');
// One that timed out (zfsmReadWithin's null code) is not read either.
$timed = zfsmScheduleCoverRead($cfg, 5, array(), function($wait, $command, $args) { return in_array('snapshot', $args, true) ? array('code' => null, 'output' => 'zfs did not answer in 5 seconds') : zfsmReadWithin($wait, $command, $args); });
same(array(array(), null), array(zfsmScheduleCovered($timed, $every, array($every))[0], zfsmScheduleListed($timed)[0]['tank'][2]), 'none copied after a names read that timed out');
same(array('Scheduled copies not made: the snapshot names could not be read: zfs did not answer in 5 seconds', true), zfsmScheduleCopiesNone('backup', $timed, array(), zfsmScheduleCovered($timed, $every, array($every))[1]), 'saying why, not that the datasets were unread');

// Copy Under gone: none made; a later problem, so only logged.
$pool('datasets', array_values(array_filter($read('pool/datasets'), function($line) { return strpos($line, 'backup') !== 0; })));
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 21600), 'a run with Copy Under gone');
same(array(), $read('notify.log'), 'a later problem is only logged');
same(1, $logged('/ Schedule '.$id.': Scheduled copies not made: backup does not exist on this server$/'), 'saying why');

// A copy's own schedule makes tank/data's copy, not made yet: no holder goes there, which would refuse it; the one below waits.
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/data/sub\tfilesystem", "backup\tfilesystem"));
$pool('marks', array());
$pool('props', array());
$own = array('id' => 'bbbb0000', 'dataset' => 'tank/data', 'dest' => 'backup/tank/data', 'minute' => 0) + $every;
file_put_contents(zfsmScheduleFile(), json_encode(array(array('keep' => 1) + $every, $own)));
$reset();
@unlink(zfsmScheduleStateFile($id));
same(1, zfsmScheduleRun($id, $cfg, $t + 25200), 'a run with a copy to come');
same(array(array('backup/tank'), array()), array(array_values(preg_grep('~^backup/~', $datasets())), preg_grep('/^create /', $read('calls.log'))), 'no holder where it goes');
same(1, preg_match('/^warning: Scheduled copies under backup: 1 of 2 dataset\(s\) copied\. Not copied, as the copy of a dataset above it is missing \(tank\/data\/sub\)\. Later problems/', $read('notify-detail.log')[0] ?? ''), 'it waits');
same(array(0, true), array(zfsmScheduleRun('bbbb0000', $cfg, $t + 25260), in_array('backup/tank/data', $datasets(), true)), 'the copy lands');
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 28800), 'the next run works');
same(true, in_array('backup/tank/data/sub', $datasets(), true), 'and copies the one below');

// Nor where another schedule of copies under backup makes one: tank's comes from Replicate Every Dataset, so tank/data/sub's own waits.
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/data/sub\tfilesystem", "backup\tfilesystem"));
$pool('marks', array());
$under = array('id' => 'cccc0000', 'dataset' => 'tank/data/sub', 'minute' => 0) + $every;
file_put_contents(zfsmScheduleFile(), json_encode(array(array('keep' => 1) + $every, array('id' => 'dddd0000', 'dataset' => 'tank/data', 'dest' => '/', 'every' => 'none') + $every, $under)));
$reset();
same(array(1, array(), array()), array(zfsmScheduleRun('cccc0000', $cfg, $t + 32400), preg_grep('~^backup/~', $datasets()), preg_grep('/^create /', $read('calls.log'))), 'no holder where Replicate Every Dataset copies');
same(array(0, 0, array('backup/tank', 'backup/tank/data', 'backup/tank/data/sub')), array(zfsmScheduleRun($id, $cfg, $t + 36000), zfsmScheduleRun('cccc0000', $cfg, $t + 36060), array_values(preg_grep('~^backup/~', $datasets()))),
	'its copy, then a holder for tank/data, which nothing copies, and the copy below');

echo "schedule copies tests passed\n";
