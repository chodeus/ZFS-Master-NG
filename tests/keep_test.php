<?php
// A schedule's Keep (include/ZFSMKeep.php) through the real store, runs and scripts/replicate.php, with the fake zfs's small pool
// (ZFSM_TEST_REPL_DIR): what its runs remove, what they never do, and the holds a stopped run leaves.

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

$work = sys_get_temp_dir().'/zfsm-keep-'.getmypid().'-'.bin2hex(random_bytes(4));
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
file_put_contents($root.'/usr/local/sbin/update_cron', "#!/bin/sh\nexit 0\n");
chmod($root.'/usr/local/sbin/update_cron', 0755);

$pool = function($file, $lines) use ($work) { file_put_contents($work.'/pool/'.$file, $lines ? implode("\n", $lines)."\n" : ''); };
$append = function($file, $line) use ($work) { file_put_contents($work.'/pool/'.$file, $line."\n", FILE_APPEND); };
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'calls.log') as $name) @unlink($work.'/'.$name); };
$short = function($dataset) { return array_map(function($mark) { return substr($mark['name'], strpos($mark['name'], '@') + 1); }, zfsmReplicaMarks($dataset, 'snapshot') ?? array()); };
$destroys = function() use ($read) { return array_values(array_filter($read('calls.log'), function($line) { return strpos($line, 'destroy ') === 0 && strpos($line, '#') === false; })); };
$log = function() { return (string)@file_get_contents(zfsmLogFile()); };
$stored = function() { return json_decode((string)@file_get_contents(zfsmScheduleFile()), true); };
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
$cfg = array('snap_prefix' => 'keep-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$tag = zfsmKeepTag('', 'backup/data');
$hold = zfsmKeepHoldTag($tag);
// Made by hand (a, b) and by another copy's schedule (c): none of them is ever counted or removed.
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "backup\tfilesystem"));
$pool('marks', array("tank/data@a\t11\t10", "tank/data@b\t12\t20", "tank/data@c\t13\t30"));
$pool('props', array("tank/data@c\tzfs.master:schedule\t".zfsmKeepTag('nas', 'data')));
$pool('holds', array());
same(true, preg_match('/^[0-9a-f]{16}$/D', $tag) === 1 && $tag !== zfsmKeepTag('', 'backup/other') && $tag !== zfsmKeepTag('nas', 'backup/data'), 'a copy\'s tag is 16 hex, its own');

// A schedule saved before Keep keeps all; a keep that is no number up to 9999 is no schedule, and Repeat refuses it.
$job = array('id' => '0a1b2c3d', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'daily', 'hour' => 3, 'day' => 0);
$listed = zfsmScheduleRead(json_encode(array($job, array('id' => '0a1b2c3e', 'keep' => '3') + $job, array('id' => '0a1b2c3f', 'keep' => 10000) + $job, array('id' => '0a1b2c40', 'keep' => 9999) + $job)));
same(array(array(0, 9999), 'keeps all its snapshots', 2), array(array_column($listed['valid'], 'keep'), zfsmScheduleKeepText($listed['valid']['0a1b2c3d']), count($listed['other'])), 'an old record keeps all; "3" and 10000 are not schedules');
$refused = 'Nothing was scheduled: under Snapshots to keep, give a number up to 9999, or nothing to keep all';
same(array(array('tank/data' => $refused), array('tank/data' => $refused), null, array()), array(zfsmScheduleAdd('tank/data', '', 'backup/data', '', 'hourly', '', '', -1, $cfg)['failed'],
	zfsmScheduleAdd('tank/data', '', 'backup/data', '', 'hourly', '', '', 10000, $cfg)['failed'], $stored(), $read('calls.log')), 'Repeat refuses one, saving and running nothing');

// Repeat with Keep 2: the first run, in the background, takes its snapshot with the copy's tag, as each scheduled run does.
$reset();
$add('tank/data', '', 'backup/data', '', 'hourly', '', '', 2, $cfg);
$jobs = $stored();
$id = $jobs[0]['id'];
same(array(2, true), array($jobs[0]['keep'], strpos(zfsmSchedulesHtml($cfg), 'every hour at :'.sprintf('%02d', $jobs[0]['minute']).', keeps the newest 2 of its snapshots') !== false), 'Keep is saved, and Settings says it');
$first = array_slice($short('tank/data'), -1)[0];
same(array(array('snapshot -o zfs.master:schedule='.$tag.' tank/data@'.$first), array($first)), array(array_values(preg_grep('/^snapshot /', $read('calls.log'))), $short('backup/data')), 'the first run tags its snapshot and makes the copy');
$t0 = time();
$run = function($k) use ($id, $cfg, $t0) { return zfsmScheduleRun($id, $cfg, (int)($t0 + 3600 * $k)); };
$n = function($k) use ($cfg, $t0) { return zfsmNewSnapshotName($cfg, (int)($t0 + 3600 * $k)); };
$notified = function() use ($read) { return $read('notify-detail.log'); };

// Past the newest 2 it tagged, a snapshot goes: from the copy first, then here. Nothing else counts or goes.
$reset();
same(array(0, array()), array($run(1), $destroys()), 'with 2 tagged, nothing goes');
$reset();
same(0, $run(2), 'the third run');
same(array(array('destroy backup/data@'.$first, 'destroy tank/data@'.$first), array('a', 'b', 'c', $n(1), $n(2)), array($n(1), $n(2)), array()), array($destroys(), $short('tank/data'), $short('backup/data'), $notified()), 'the oldest it tagged goes, the copy\'s first; a, b and c stay; nothing notified');
same(array(1, 1), array(substr_count($log(), 'Removed tank/data@'.$first.' (past the newest 2 its schedule keeps)'), substr_count($log(), 'Removed backup/data@'.$first.' (past the newest 2 its schedule keeps)')), 'each removal is logged');

// One held or cloned here stays, and so does its copy's; it still counts, so Keep keeps more, never fewer.
$append('holds', "tank/data@".$n(1)."\tmine");
$reset();
same(array(0, array()), array($run(3), $destroys()), 'a held one past the newest 2 stays');
$pool('holds', array());
$append('props', "tank/data@".$n(1)."\tclones\ttank/clone");
$reset();
$run(4);
same(array(array('destroy backup/data@'.$n(2), 'destroy tank/data@'.$n(2)), array('a', 'b', 'c', $n(1), $n(3), $n(4)), array($n(1), $n(3), $n(4))), array($destroys(), $short('tank/data'), $short('backup/data')), 'a cloned one stays on both sides; the next one past goes');
$pool('props', array("tank/data@c\tzfs.master:schedule\t".zfsmKeepTag('nas', 'data')));
foreach (array(1, 3, 4) as $k) $append('props', "tank/data@".$n($k)."\tzfs.master:schedule\t".$tag);

// One held on the copy stays there, and so does its twin here, with no warning: Keep finds the copy's only through it.
$append('holds', "backup/data@".$n(1)."\tmine");
$reset();
$run(5);
same(array(array('destroy backup/data@'.$n(3), 'destroy tank/data@'.$n(3)), array('a', 'b', 'c', $n(1), $n(4), $n(5)), array($n(1), $n(4), $n(5)), array()),
	array($destroys(), $short('tank/data'), $short('backup/data'), $notified()), 'the copy\'s held one and its twin here stay, unwarned; the next goes');
$pool('holds', array());

// A copy's snapshot that can't go keeps its twin here for the next run; the run is a problem, notified once and counted.
putenv('ZFSM_TEST_DESTROY_FAIL=backup/data@'.$n(4));
$reset();
same(0, $run(6), 'a run whose Keep fails still landed');
putenv('ZFSM_TEST_DESTROY_FAIL');
$kept = 'Keep could not remove backup/data@'.$n(4).' (cannot destroy \'backup/data@'.$n(4).'\': Input/output error), so its source stays too: tank/data@'.$n(4).'; the next run tries again';
same(array(array('warning: Replication of tank/data@'.$n(6).' to backup/data finished (changes since '.$n(5).'). '.zfsmSafeText($kept).'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'),
	array($n(4), $n(5), $n(6)), 1), array($notified(), array_slice($short('tank/data'), -3), zfsmScheduleFailures($id)), 'says which stays and why; both stay; counted');
same(1, substr_count($log(), zfsmSafeText('Replication of tank/data to backup/data: '.$kept)), 'and logs it');
same(array(array('a', 'b', 'c', $n(4), $n(5), $n(6)), array($n(4), $n(5), $n(6))), array($short('tank/data'), $short('backup/data')), 'with its hold off, the copy\'s one went with its twin here');
$reset();
$run(7);
same(array(array('destroy backup/data@'.$n(4), 'destroy tank/data@'.$n(4), 'destroy backup/data@'.$n(5), 'destroy tank/data@'.$n(5)), 0, 1),
	array($destroys(), zfsmScheduleFailures($id), preg_match('/^normal: .+ It works again after 1 run\(s\) with a problem\.$/D', $notified()[0] ?? '')), 'the next run removes both, and says it works again');

// Read again just before each destroy: a copy's snapshot that is another one now stays, and so does its twin.
putenv('ZFSM_TEST_GUID_SWAP=backup/data@'.$n(6));
$reset();
$run(8);
putenv('ZFSM_TEST_GUID_SWAP');
same(array(array(), 1), array($destroys(), preg_match('/Keep could not remove backup\/data@'.$n(6).' \(another snapshot has its name now\), so its source stays too: tank\/data@'.$n(6).'; the next run tries again\./', $notified()[0] ?? '')), 'nothing is destroyed, and it says why');
// A list that can't be read removes nothing.
putenv('ZFSM_TEST_KEEP_LIST_FAIL=backup/data');
$reset();
$run(9);
putenv('ZFSM_TEST_KEEP_LIST_FAIL');
same(array(array(), 1), array($destroys(), substr_count($log(), 'Replication of tank/data to backup/data: Keep could not read the snapshots of backup/data')), 'nor does a copy whose snapshots can\'t be read');
putenv('ZFSM_TEST_KEEP_LIST_FAIL=tank/data');
@unlink(zfsmScheduleStateFile($id));
$reset();
$run(10);
putenv('ZFSM_TEST_KEEP_LIST_FAIL');
same(array(array(), 1), array($destroys(), preg_match('/finished \(changes since [^)]+\)\. A hold for this copy may still be on its snapshots, so they stay until a run that lands takes it off: the snapshots of tank\/data could not be read\. Keep could not read the snapshots of tank\/data\./', $notified()[0] ?? '')),
	'nor does a dataset whose own can\'t, and the run says its holds may be on');
@unlink(zfsmScheduleStateFile($id));
$reset();
$run(11);
same(array(array($n(10), $n(11)), array($n(10), $n(11))), array(array_slice($short('tank/data'), 3), $short('backup/data')), 'the next run catches up');

// A run that stops part-way holds its snapshot and the one it sent the changes since; nothing removes them, and the run that
// carries it on takes the hold off.
putenv('ZFSM_TEST_SEND_CUT=1');
$reset();
same(1, $run(12), 'a run cut off part-way');
putenv('ZFSM_TEST_SEND_CUT');
same(array(array('tank/data@'.$n(12)."\t".$hold, 'tank/data@'.$n(11)."\t".$hold), 1), array($read('pool/holds'), preg_match('/^warning: Replication of tank\/data@'.$n(12).' to backup\/data failed: .+\. Replicate it again to carry on from where it stopped\. Later/', $notified()[0] ?? '')), 'both are held, and the notification asks for nothing else');
same(1, preg_match('/dataset is busy/', runProcess('zfs', array('destroy', 'tank/data@'.$n(11)))['output']), 'a removal by hand is refused');
$order = array_values(preg_grep('/^(hold |send (?!.* -n ))/', $read('calls.log')));
same(array('hold '.$hold.' tank/data@'.$n(12), 'hold '.$hold.' tank/data@'.$n(11)), array_slice($order, 0, 2), 'they are held before the send starts, so a run killed part-way leaves them held too: '.implode(' | ', $order));
// Another hold on one of them is its owner's: it stays, and so does that snapshot.
$append('holds', "tank/data@".$n(11)."\tmine");
$reset();
same(0, $run(13), 'the next run');
same(array(array('tank/data@'.$n(11)."\tmine"), array($n(11), $n(12), $n(13)), array($n(11), $n(12), $n(13)), 1), array($read('pool/holds'), array_slice($short('tank/data'), 3), $short('backup/data'), preg_match('/^normal: .+ It works again after 1 run\(s\) with a problem\.$/D', $notified()[0] ?? '')),
	'carries it on and takes its own holds off; the one held by another stays; it works again');
$pool('holds', array());
// A run whose copy has nothing to carry on (zfs receive -A cleared its token) that fails takes the copy's holds off, so the snapshot
// it took goes.
putenv('ZFSM_TEST_SEND_CUT=1');
$run(13.5);
putenv('ZFSM_TEST_SEND_CUT');
$pool('props', array_values(array_filter($read('pool/props'), function($line) { return strpos($line, "\treceive_resume_token\t") === false; })));
putenv('ZFSM_TEST_SEND_FAIL=1');
$reset();
same(1, $run(13.75), 'a run that fails with nothing to carry on');
putenv('ZFSM_TEST_SEND_FAIL');
same(array(array(), array('destroy tank/data@'.$n(13.75)), array($n(13), $n(13.5))), array($read('pool/holds'), $destroys(), array_slice($short('tank/data'), -2)), 'no hold stays, and the snapshot it took goes');
$reset();
$run(13.9);
// A hold that can't be put on says so.
putenv('ZFSM_TEST_SEND_CUT=1');
putenv('ZFSM_TEST_HOLD_FAIL=1');
$reset();
$run(14);
putenv('ZFSM_TEST_HOLD_FAIL');
putenv('ZFSM_TEST_SEND_CUT');
$unheld = ' What it carries on from could not be held, so a Keep or a removal by hand can take it first: tank/data@'.$n(14).' (cannot hold snapshot \'tank/data@'.$n(14).'\': permission denied); tank/data@'.$n(13.9).' (cannot hold snapshot \'tank/data@'.$n(13.9).'\': permission denied).';
same(array(array(), 1, 1, array()), array($read('pool/holds'), substr_count($notified()[0] ?? '', zfsmSafeText($unheld)), substr_count($log(), zfsmSafeText($unheld)), $destroys()),
	'the notification and the log say which, and Keep removes nothing after a run that did not land, held or not');
$reset();
$run(15);
// A hold that can't come off: the run says its snapshots stay held, and they do, its own with them.
putenv('ZFSM_TEST_SEND_CUT=1');
$run(16);
putenv('ZFSM_TEST_SEND_CUT');
putenv('ZFSM_TEST_RELEASE_FAIL=1');
@unlink(zfsmScheduleStateFile($id));
$reset();
$run(17);
putenv('ZFSM_TEST_RELEASE_FAIL');
same(array(3, 1), array(count($read('pool/holds')), preg_match('/A hold for this copy may still be on its snapshots, so they stay until a run that lands takes it off: tank\/data@'.$n(15).' \(cannot release hold from snapshot \?tank\/data@'.$n(15).'\?: permission denied\); tank\/data@'.$n(16).' \(/', $notified()[0] ?? '')), 'says so');
$reset();
$run(18);
same(array(array(), array($n(17), $n(18))), array($read('pool/holds'), array_slice($short('tank/data'), 3)), 'the next run takes them off, and Keep catches up');

// Keep 0 tags and removes nothing; the snapshot a run just sent stays, past the newest or not; a copy on a server: only here.
$reset();
same(array(0, array(), array()), array(zfsmRunReplicate('tank/data', 'z0', 'backup/data', null, true, '', null, 0), $destroys(), zfsmKeepSurplus('tank/data', 0, $tag)), 'keep 0 removes nothing');
same(array('snapshot -o zfs.master:schedule='.$tag.' tank/data@z0'), array_values(preg_grep('/^snapshot /', $read('calls.log'))), 'and tags its snapshot');
$reset();
zfsmRunReplicate('tank/data', 'h1', 'backup/data', null, true);
same(array('snapshot tank/data@h1'), array_values(preg_grep('/^snapshot /', $read('calls.log'))), 'a run that is no schedule\'s tags nothing');
$two = zfsmKeepTag('', 'backup/two');
$pool('props', array("tank/data@a\tzfs.master:schedule\t".$two, "tank/data@b\tzfs.master:schedule\t".$two));
$reset();
same(array(0, array(), array('a')), array(zfsmRunReplicate('tank/data', 'a', 'backup/two', null, false, '', null, 1), $destroys(), $short('backup/two')), 'the one just sent stays');
$far = zfsmKeepTag('nas', 'copy');
foreach (array('r1', 'r2') as $name) createDatasetSnapshot('tank/data', $name, 0, array(ZFSM_KEEP_TAG => $far));
$reset();
same(array('', array('destroy tank/data@r1'), array()), array(zfsmKeepPrune('tank/data', array('server' => 'nas', 'dest' => 'copy', 'snapshot' => 'tank/data@r2'), 1, $far), $destroys(), preg_grep('/ copy$/', $read('calls.log'))), 'to a server, only here, and the copy is not asked');
// A run removes at most 50, oldest first, of those it can: the copy's held or cloned ones and their twins take no place.
$many = zfsmKeepTag('', 'backup/many');
$append('datasets', "tank/many\tfilesystem");
$append('datasets', "backup/many\tfilesystem");
for ($k = 1; $k <= 60; $k++) {
	$append('marks', "tank/many@m$k\t".(9000 + $k)."\t".(9000 + $k));
	$append('props', "tank/many@m$k\tzfs.master:schedule\t".$many);
	$append('marks', "backup/many@m$k\t".(9000 + $k)."\t".(9100 + $k));
}
foreach (array(1, 2) as $k) $append('holds', "backup/many@m$k\tmine");
$append('props', "backup/many@m3\tclones\tbackup/clone");
$reset();
$pruned = zfsmKeepPrune('tank/many', array('server' => '', 'dest' => 'backup/many', 'snapshot' => 'tank/many@m60'), 1, $many);
$left = array('m1', 'm2', 'm3', 'm54', 'm55', 'm56', 'm57', 'm58', 'm59', 'm60');
same(array('', 100, $left, $left), array($pruned, count($destroys()), $short('tank/many'), $short('backup/many')), 'at most 50 a run, oldest first, past the copy\'s held and cloned ones');
$pool('holds', array());
// Keep Replicas too, and its held ones take no place either.
$append('datasets', "tank/recv\tfilesystem");
for ($k = 1; $k <= 60; $k++) $append('marks', "tank/recv@m$k\t".(9200 + $k)."\t".(9200 + $k));
foreach (array(1, 2) as $k) $append('holds', "tank/recv@m$k\tmine");
$reset();
zfsmKeepReplica('10.0.0.9', 'tank/recv', 1);
same(array(50, array('m1', 'm2', 'm53', 'm54', 'm55', 'm56', 'm57', 'm58', 'm59', 'm60'), array()), array(count($destroys()), $short('tank/recv'), $notified()),
	'a receive removes at most 50 too, past the held ones, with no warning');
$pool('holds', array());

// Release in Snapshots takes the plugin's holds off, Hold's and a stopped run's; another tool's stay, and alone are refused.
$pool('holds', array("tank/data@a\tzfsmaster", "tank/data@a\t".$hold, "tank/data@a\tmine", "tank/data@b\tmine"));
same(array(array('tank/data@a' => 0), array("tank/data@a\tmine", "tank/data@b\tmine")), array(releaseDatasetSnapshot('tank/data@a')['succeeded'], $read('pool/holds')), 'Release takes off its own holds, not another\'s');
same(array(array('tank/data@b' => 'Its holds are another tool\'s (mine), so they stay'), array('tank/data@c' => 'It has no hold')), array(releaseDatasetSnapshot('tank/data@b')['failed'], releaseDatasetSnapshot('tank/data@c')['failed']), 'and says why it takes none off');
$pool('holds', array());

// A schedule added again for the copy counts the snapshots of the one before; its first run, saved or not, removes none of them.
$tagged = function() use ($tag) { return count(array_filter(zfsmKeepSnapshots('tank/data'), function($snapshot) use ($tag) { return $snapshot['tag'] === $tag; })); };
$run(19);
$run(20);
same(array(2, true), array($tagged(), zfsmScheduleRemove($id)['saved']), 'two of the schedule\'s snapshots, and it is removed');
$reset();
$add('tank/data', '', 'backup/data', '', 'hourly', '', '', 1, $cfg);
$again = $stored()[0]['id'];
same(array(array(), 3), array($destroys(), $tagged()), 'the first run of one added again removes nothing');
$reset();
same(array(0, 6, 1), array(zfsmScheduleRun($again, $cfg, (int)($t0 + 3600 * 21)), count($destroys()), $tagged()), 'the next keeps the newest 1, removing the rest here and in the copy');
$reset();
foreach (array('1x', '10000', '') as $keep) {
	$said = array();
	exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(getenv('ZFSM_TEST_DOCROOT').'/plugins/zfs.master/scripts/replicate.php').' tank/data x backup/data new "" '.escapeshellarg($keep).' 2>&1', $said, $code);
	same(array(2, 'Usage: replicate.php <dataset> <snapshot> <destination> [new|existing [server [keep]]]', array()), array($code, implode("\n", $said), $read('calls.log')), 'replicate.php refuses keep "'.$keep.'"');
}

echo "keep tests passed\n";
