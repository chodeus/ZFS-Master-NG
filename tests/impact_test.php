<?php
// Destroy and rollback reviews through the real code and real /proc, with fake zfs output.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

// This test destroys and rolls back, so never let it reach the host's real zfs.
$bin_dir = getenv('ZFSM_TEST_BIN_DIR');
if (!is_string($bin_dir) || !is_executable($bin_dir.'/zfs')) {
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold the fake zfs\n");
	exit(1);
}

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}
function env($settings) { foreach ($settings as $key => $value) putenv($key.'='.$value); }

// Saved settings
$saved = parse_ini_string(zfsmConfigText(array('general' => array('directory_listing' => "tank/a\ntank/b", 'snap_prefix' => 'say "hi"'))), true);
same(array('tank/a', 'tank/b'), preg_split('/\r\n|\r|\n/', $saved['general']['directory_listing']), 'a second directory listing entry keeps its own line');
same('say "hi"', $saved['general']['snap_prefix'], 'a quote survives a save');
// Unraid 6 reads the file as written; Unraid 7 strips tags and decodes entities first.
foreach (array("a\"\nevil = \"1", "a&quot;\nevil = &quot;1") as $hostile) {
	$text = zfsmConfigText(array('general' => array('snap_prefix' => $hostile, 'after' => 'kept')));
	foreach (array($text, strip_tags(html_entity_decode($text))) as $read) {
		$saved = @parse_ini_string($read, true); // Unraid 7 cannot read a decoded &quot; even on one line
		same(false, isset($saved['general']['evil']), 'a quote before a line break let a value start its own setting');
	}
}

// Dates without Unraid's helpers fall back to one plain format, and every dataset and snapshot gets one.
same(date('Y-m-d H:i', 1793700000), zfsmTime(1793700000), 'fallback date format');
$tree = array('creation' => 1, 'snapshots' => array(array('creation' => 2)), 'child' => array('tank/a' => array('creation' => 3, 'snapshots' => array(array('creation' => 4)), 'child' => array())));
zfsmAddTimeText($tree);
same(array(zfsmTime(1), zfsmTime(2), zfsmTime(3), zfsmTime(4)), array($tree['creation_text'], $tree['snapshots'][0]['creation_text'], $tree['child']['tank/a']['creation_text'], $tree['child']['tank/a']['snapshots'][0]['creation_text']), 'every level gets its date text');

// Destroy preview
$preview = zfsmDestroyPreview('tank/data', 0);
same(array('tank/data'), $preview['objects'], 'plain destroy lists the dataset');
same(4096.0, $preview['reclaim'], 'ZFS gives no reclaim figure for a dataset, so its used space stands in');
same(2048.0, zfsmDestroyPreview('tank/data@a,b', 0)['reclaim'], 'reclaim is parsed for snapshots');
env(array('ZFSM_TEST_USED_FAIL' => '1'));
same(null, zfsmDestroyPreview('tank/data', 0)['reclaim'], 'an unknown size is not shown as zero');
env(array('ZFSM_TEST_USED_FAIL' => '0'));
same(false, zfsmDestroyPreview('tank', 1)['can_proceed'], 'a pool root is refused');
same(true, zfsmDestroyPreview('tank@daily', 0)['can_proceed'], 'a snapshot of a pool root is fine');
same(true, $preview['can_proceed'], 'nothing blocks a plain destroy');
$recursive = zfsmDestroyPreview('tank/data', 1);
same(array('tank/data', 'tank/data/child', 'tank/data@daily'), $recursive['objects'], 'recursive lists children and snapshots');
same(false, $preview['token'] === $recursive['token'], 'a different scope is a different token');

same(true, zfsmValidDestroyTarget('tank/a b@x,y z'), 'a snapshot list with spaces is valid');
foreach (array('tank/a;b', 'tank@x@y', 'tank@x,,y', 'tank@', '../tank', '-r', '') as $bad) {
	same(false, zfsmDestroyPreview($bad, 0)['can_proceed'], 'invalid target refused: '.$bad);
}

env(array('ZFSM_TEST_DESTROY_REFUSED' => '1'));
$refused = zfsmDestroyPreview('tank/data', 0);
same(false, $refused['can_proceed'], 'a refused dry run blocks');
same(true, strpos($refused['error'], 'has children') !== false, 'the reason ZFS gave is shown');
env(array('ZFSM_TEST_DESTROY_REFUSED' => '0'));

env(array('ZFSM_TEST_CLONE' => 'tank/data@daily'));
$cloned = zfsmDestroyPreview('tank/data', 1);
same(false, $cloned['can_proceed'], 'a dependent clone blocks');
same(array('tank/clone (from tank/data@daily)'), $cloned['clones'], 'the clone is named');
env(array('ZFSM_TEST_CLONE' => ''));

env(array('ZFSM_TEST_HOLDS' => '1'));
$held = zfsmDestroyPreview('tank/data', 1);
same(false, $held['can_proceed'], 'a hold blocks');
same(array('tank/data@daily'), $held['held'], 'the held snapshot is named');
same(true, empty(zfsmDestroyReviewed('tank/data', 1, $held['token'])['succeeded']), 'a blocked review cannot be confirmed with its own token');
env(array('ZFSM_TEST_HOLDS' => '0'));

env(array('ZFSM_TEST_ORIGIN_FAIL' => '1'));
$unreadable = zfsmDestroyPreview('tank/data', 0);
same(false, $unreadable['can_proceed'], 'an unreadable clone list blocks: a failed read is not an empty result');
same('', $unreadable['token'], 'and gives no token to confirm with');
env(array('ZFSM_TEST_ORIGIN_FAIL' => '0'));

// A confirmation only counts for what was reviewed
$log = sys_get_temp_dir().'/zfsm-impact-log';
@unlink($log);
env(array('ZFSM_TEST_LOG' => $log));
$destroys = function() use ($log) { return is_file($log) ? substr_count((string)file_get_contents($log), 'destroy -v') : 0; };
$token = zfsmDestroyPreview('tank/data', 1)['token'];
env(array('ZFSM_TEST_DESTROY_EXTRA' => 'tank/data/newchild'));
same(true, empty(zfsmDestroyReviewed('tank/data', 1, $token)['succeeded']), 'a stale review is refused');
same(0, $destroys(), 'and nothing is destroyed');
env(array('ZFSM_TEST_DESTROY_EXTRA' => ''));
same(true, empty(zfsmDestroyReviewed('tank/data', 1, '')['succeeded']), 'no token is refused');
same(true, empty(zfsmDestroyReviewed('tank/data', 0, $token)['succeeded']), 'a token for a different scope is refused');
same(0, $destroys(), 'still nothing destroyed');
same(false, empty(zfsmDestroyReviewed('tank/data', 1, $token)['succeeded']), 'the matching review destroys');
same(1, $destroys(), 'exactly once');

// A bulk snapshot delete is one reviewed list, deleted in one command.
$bulk = zfsmDestroyPreview('tank/data@a,daily', 0);
same(array('tank/data@a', 'tank/data@daily'), $bulk['objects'], 'a snapshot list is reviewed one snapshot at a time');
env(array('ZFSM_TEST_HOLDS' => '1'));
same(array('tank/data@daily'), zfsmDestroyPreview('tank/data@a,daily', 0)['held'], 'a held snapshot in the list is named, which blocks it');
env(array('ZFSM_TEST_HOLDS' => '0'));
@unlink($log);
same(true, empty(zfsmDeleteSnapshotsReviewed('tank/data@a,daily', 0, '')['succeeded']), 'a list without its token is refused');
same('', (string)@file_get_contents($log), 'and nothing is deleted');
same(false, empty(zfsmDeleteSnapshotsReviewed('tank/data@a,daily', 0, $bulk['token'])['succeeded']), 'the reviewed list is deleted');
same("destroy tank/data@a,daily\n", (string)@file_get_contents($log), 'in one command, so ZFS deletes all of it or none');
foreach (array('tank/data,x', 'tank/a@b,c@d') as $bad) same(true, empty(deleteDatasetSnapshot($bad)['succeeded']), 'not a snapshot list: '.$bad);
// The snapshot route skips Destructive Mode, so a dataset's own valid token must not destroy it there.
@unlink($log);
$dataset_token = zfsmDestroyPreview('tank/data', 0)['token'];
same(true, empty(zfsmDeleteSnapshotsReviewed('tank/data', 0, $dataset_token)['succeeded']), 'a dataset is refused by the snapshot path');
same('', (string)@file_get_contents($log), 'and nothing is destroyed');

// Two newest snapshots both review as "nothing newer", so only the target tells their confirmations apart.
same(false, zfsmImpactToken('tank/a@x', 'plain', array(), array()) === zfsmImpactToken('tank/b@x', 'plain', array(), array()), 'a token confirms only the target that was reviewed');
// A leaf dataset lists the same with or without force, but force also unmounts: its token must not confirm a forced destroy.
env(array('ZFSM_TEST_LEAF' => '1'));
$plain = zfsmDestroyPreview('tank/data', 0);
same(zfsmDestroyPreview('tank/data', 1)['objects'], $plain['objects'], 'a leaf lists the same either way');
same(true, empty(zfsmDestroyReviewed('tank/data', 1, $plain['token'])['succeeded']), 'a review without force cannot confirm a forced destroy');
env(array('ZFSM_TEST_LEAF' => '0'));

// Rollback preview
$rollback = zfsmRollbackPreview('tank/data@daily');
same(array('tank/data@new', 'tank/data#mark'), $rollback['newer'], 'newer snapshots and bookmarks, compared as numbers: 1000 and 101 are after 100, 99 is not');
same(true, $rollback['can_proceed'], 'nothing blocks');
same(array(), zfsmRollbackPreview('tank/data#x@y')['newer'], 'invalid name lists nothing');
same(false, zfsmRollbackPreview('tank/data@gone')['can_proceed'], 'a snapshot that vanished is refused');
env(array('ZFSM_TEST_CLONE' => 'tank/data@new'));
$blocked = zfsmRollbackPreview('tank/data@daily');
same(false, $blocked['can_proceed'], 'a clone of a newer snapshot blocks');
same(true, empty(zfsmRollbackReviewed('tank/data@daily', 1, $blocked['token'])['succeeded']), 'a blocked rollback cannot be confirmed with its own token');
env(array('ZFSM_TEST_CLONE' => ''));
env(array('ZFSM_TEST_TXG_FAIL' => '1'));
same(false, zfsmRollbackPreview('tank/data@daily')['can_proceed'], 'an unreadable list blocks');
env(array('ZFSM_TEST_TXG_FAIL' => '0'));
$token = zfsmRollbackPreview('tank/data@daily')['token'];
env(array('ZFSM_TEST_TXG_EXTRA' => 'justnow'));
same(true, empty(zfsmRollbackReviewed('tank/data@daily', 1, $token)['succeeded']), 'a snapshot taken after the review refuses the rollback');
env(array('ZFSM_TEST_TXG_EXTRA' => ''));
same(false, empty(zfsmRollbackReviewed('tank/data@daily', 1, $token)['succeeded']), 'the matching review rolls back');
env(array('ZFSM_TEST_HOLDS' => '1'));
$held_newer = zfsmRollbackPreview('tank/data@old');
same(false, $held_newer['can_proceed'], 'zfs rollback -r would stop partway at a held newer snapshot, so the review blocks');
same(array('tank/data@daily'), $held_newer['held'], 'the held snapshot is named');
same(true, zfsmRollbackPreview('tank/data@daily')['can_proceed'], 'a hold on the target itself is not in the way');
$refused = zfsmRollbackReviewed('tank/data@old', 1, $held_newer['token']);
same(true, strpos(reset($refused['failed']), 'holds block') !== false, 'a blocked rollback says why: '.json_encode($refused));
env(array('ZFSM_TEST_HOLDS' => '0'));
env(array('ZFSM_TEST_ORIGIN_FAIL' => '1'));
same(false, zfsmRollbackPreview('tank/data@daily')['can_proceed'], 'an unreadable clone list blocks a rollback');
env(array('ZFSM_TEST_TXG_EXTRA' => 'latest'));
same(true, zfsmRollbackPreview('tank/data@latest')['can_proceed'], 'a rollback with nothing newer needs no clone list');
env(array('ZFSM_TEST_TXG_EXTRA' => ''));
$refused = zfsmDestroyReviewed('tank/data', 0, 'x');
same('Unable to check for clones and holds', reset($refused['failed']), 'a failed read is not reported as a changed review');
env(array('ZFSM_TEST_ORIGIN_FAIL' => '0'));
$refused = zfsmDestroyReviewed('tank/data', 0, 'x');
same(true, strpos(reset($refused['failed']), 'has changed') !== false, 'a stale token still says the list changed');
same(true, zfsmTxgIsNewer('18446744073709551615', '18446744073709551614'), 'near the top of 64 bits');

// Mount table parsing
$mountinfo = "36 35 0:30 / /mnt/x rw - zfs tank/data2 rw\n37 35 0:31 / /mnt/y rw - tmpfs tank/data rw\n";
same(false, zfsmMountinfoHasDataset($mountinfo, 'tank/data'), 'a similar name and another filesystem type do not match');
same(true, zfsmMountinfoHasDataset("36 35 0:30 / /x rw shared:1 - zfs tank/data/child rw\n", 'tank/data'), 'a child counts');
same(true, zfsmMountinfoHasDataset("36 35 0:30 / /x rw - zfs tank/data@snap rw\n", 'tank/data'), 'a mounted snapshot counts');
same(true, zfsmMountinfoHasDataset("36 35 0:30 / /x rw - zfs tank/My\\040Share rw\n", 'tank/My Share'), 'a space in the name is decoded');
$shfs = "97 80 0:44 /data /media rw,relatime - fuse.shfs shfs rw,user_id=0\n";
same(true, zfsmMountinfoHasShare($shfs, array('/data')), 'a bind of the share');
same(true, zfsmMountinfoHasShare("97 80 0:44 /data/tv /tv rw - fuse.shfs shfs rw\n", array('/data')), 'a bind inside the share');
same(false, zfsmMountinfoHasShare($shfs, array('/dat')), 'a similar share name');
same(false, zfsmMountinfoHasShare("97 80 0:44 / /mnt/user rw - fuse.shfs shfs rw\n", array('/data')), 'binding all of /mnt/user is not a user of every share');
same(false, zfsmMountinfoHasShare("36 35 0:30 /data /x rw - zfs tank/data rw\n", array('/data')), 'only shfs counts here');
same(array('/data', '/data/tv'), zfsmShareViews(array('/mnt/cache/data', '/mnt/cache/data/tv', '/mnt/cache', '/elsewhere/data')), 'share views: a pool root is no share');
same('a?b??c?d', zfsmSafeText("a<b>\nc'd"), 'unsafe characters');
same(500, strlen(zfsmSafeText(str_repeat('x', 900))), 'length cap');
same(array('T: tank succeeded', 'T: tank/x failed: nope', 'T: tank/y failed: see the dialog'), zfsmActionLogLines('T', array('succeeded' => array('tank' => 0), 'failed' => array('tank/x' => 'nope', 'tank/y' => array('a')))), 'one log line per subject');
same(array('abc' => 'plex', 'def' => 'my app'), zfsmContainerNames("abc plex\ndef my app\n"), 'container names');

// Who is using it: a real process with its working directory inside the mount
$children = array();
// A failed check exits at once; its sleepers must not outlive the test.
register_shutdown_function(function() use (&$children) { foreach ($children as $child) if (is_resource($child)) proc_terminate($child); });
$mount = sys_get_temp_dir().'/zfsm-users-'.getmypid();
mkdir($mount.'/in<b>ner', 0777, true);
env(array('ZFSM_TEST_USERS_MOUNT' => $mount));
$sleeper = proc_open(array('sleep', '20'), array(), $pipes, $mount.'/in<b>ner');
same(true, is_resource($sleeper), 'the test process started');
$children[] = $sleeper;
usleep(200000);
$status = proc_get_status($sleeper);
$users = zfsmDatasetUsers('tank/data');
$found = array_filter($users, function($user) use ($status) { return strpos($user, '(pid '.$status['pid'].')') !== false; });
same(1, count($found), 'the process using the mount is named: '.json_encode($users));
same(false, strpos(implode(' ', $users), '<'), 'a file name cannot put markup in the report: '.json_encode($users));

// Writes under /mnt/user, so only on the empty tmpfs the runner mounts there.
same(1, preg_match('~^\S+ \S+ \S+ \S+ /mnt/user \S+.* - tmpfs ~m', (string)@file_get_contents('/proc/self/mountinfo')), '/mnt/user must be a scratch tmpfs before this test writes to it');
mkdir('/mnt/user/zfsm-share/inner', 0777, true);
env(array('ZFSM_TEST_USERS_MOUNT' => '/mnt/zfsm-pool/zfsm-share'));
$through = proc_open(array('sleep', '20'), array(), $pipes, '/mnt/user/zfsm-share/inner');
same(true, is_resource($through), 'the share test process started');
$children[] = $through;
usleep(200000);
$through_pid = proc_get_status($through)['pid'];
$found = array_filter(zfsmDatasetUsers('tank/data'), function($user) use ($through_pid) { return strpos($user, '(pid '.$through_pid.')') !== false; });
same(1, count($found), 'a process using the dataset through /mnt/user is named');
proc_terminate($through);
proc_close($through);
exec('rm -rf /mnt/user/zfsm-share');
env(array('ZFSM_TEST_USERS_MOUNT' => $mount));

env(array('ZFSM_TEST_DESTROY_BUSY' => '1'));
$busy = zfsmExplainBusy(destroyDataset('tank/data', 0), 'tank/data');
same(true, strpos(reset($busy['failed']), 'Using it now') !== false && strpos(reset($busy['failed']), 'sleep') !== false, 'a busy failure says who: '.json_encode($busy));
proc_terminate($sleeper);
proc_close($sleeper);
usleep(100000);
$quiet = zfsmExplainBusy(destroyDataset('tank/data', 0), 'tank/data');
same(true, strpos(reset($quiet['failed']), 'No process') !== false, 'and says so when it finds nobody: '.json_encode($quiet));
env(array('ZFSM_TEST_DESTROY_BUSY' => '0', 'ZFSM_TEST_USERS_MOUNT' => ''));
$other = zfsmExplainBusy(array('succeeded' => array(), 'failed' => array('x' => 'permission denied')), 'tank/data');
same('permission denied', $other['failed']['x'], 'other failures are left alone');

echo "destroy and rollback review tests passed\n";
