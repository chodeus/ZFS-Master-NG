<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function assertSameValue($expected, $actual, $message) {
	if ($expected !== $actual) {
		fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
		exit(1);
	}
}

assertSameValue(true, zfsmValidPoolName('tank-1'), 'Valid pool name rejected');
assertSameValue(false, zfsmValidPoolName('../tank'), 'Unsafe pool name accepted');
assertSameValue(true, zfsmValidDatasetName('tank/appdata/plex'), 'Valid dataset name rejected');
foreach (array('tank/app data', 'tank/_tmp', 'tank/.system', 'tank/a:b', 'tank/-dash') as $valid_dataset) assertSameValue(true, zfsmValidDatasetName($valid_dataset), 'OpenZFS-valid dataset rejected: '.$valid_dataset);
foreach (array('tank/..', 'tank/.', 'tank//a', 'tank/a@b', 'tank/a#b', 'tank/a%b', "tank/a\nb", 'tank/a;b', '../tank') as $bad_dataset) assertSameValue(false, zfsmValidDatasetName($bad_dataset), 'Invalid dataset accepted: '.$bad_dataset);
assertSameValue(true, zfsmValidSnapshotName('tank/app data@my snap'), 'Snapshot with spaces rejected');
assertSameValue(true, zfsmValidSnapshotName('tank/a@_auto'), 'Snapshot with leading underscore rejected');
assertSameValue(false, zfsmValidSnapshotName('tank/a@'), 'Empty snapshot name accepted');
assertSameValue(true, zfsmValidSnapshotName('tank/appdata@daily-1'), 'Valid snapshot name rejected');
assertSameValue(false, zfsmValidSnapshotName('tank/appdata@bad/name'), 'Invalid snapshot name accepted');

$columns = zfsmDatasetColumns();
$values = array('tank/data', 'filesystem', '1024', '2048', '512', 'off', '-', '/mnt/tank/data', 'lz4', '1.25x', '256', 'none', '131072', 'on', 'sa', 'all', 'off', 'sensitive', 'standard', '1700000000', '-', '-');
$rows = zfsmParseTable(implode("\t", $values), $columns);
assertSameValue(1, count($rows), 'Dataset TSV row was not parsed');
assertSameValue(125, $rows[0]['compressratio'], 'Compression ratio normalization failed');
assertSameValue(0, $rows[0]['quota'], 'Quota normalization failed');
assertSameValue('none', $rows[0]['keystatus'], 'Unavailable keystatus normalization failed');
assertSameValue(false, isset($rows[0]['origin']), 'Inapplicable origin should be omitted');

$datasets = array(
	'tank' => array('name' => 'tank'),
	'tank/a' => array('name' => 'tank/a'),
	'tank/a/b' => array('name' => 'tank/a/b')
);
$children = array('tank' => array('tank/a'), 'tank/a' => array('tank/a/b'));
$tree = zfsmBuildDatasetNode('tank', $datasets, $children);
assertSameValue('tank/a/b', $tree['child']['tank/a']['child']['tank/a/b']['name'], 'Dataset tree construction failed');

assertSameValue(true, zfsmMatchesExclusion('tank/dockerfiles/abc', '/dockerfiles/.*'), 'Dataset exclusion did not match');
assertSameValue(false, zfsmMatchesExclusion('tank/media', '/dockerfiles/.*'), 'Dataset exclusion matched the wrong dataset');
assertSameValue(true, zfsmMatchesExclusion('cache/docker-old/abc', '/docker%-old/.*'), 'Saved Lua escape stopped matching');
assertSameValue(false, zfsmMatchesExclusion('cache/dockerXold/abc', '/docker%-old/.*'), 'Lua escape must be literal');
assertSameValue(true, zfsmMatchesExclusion('cache/docker-old/abc', '/docker-old/.*'), 'Unescaped hyphen must stay literal');
assertSameValue(true, zfsmMatchesExclusion('tank/layer7', 'layer%d$'), 'Lua digit class failed');
assertSameValue(true, zfsmMatchesExclusion('tank/a_b', 'tank/[%w_]+$'), 'Lua class inside a set failed');
assertSameValue(false, zfsmMatchesExclusion('tank/a-b', 'tank/[%w_]+$'), 'Lua set matched too much');
assertSameValue(true, zfsmMatchesExclusion('tank/ab', 'tank/%D+$'), 'Negated Lua class failed');
assertSameValue(true, zfsmMatchesExclusion('tank/layer7', 'layer\\d$'), 'PCRE escape stopped matching');
assertSameValue(true, zfsmMatchesExclusion('tank/a#b', 'a#b'), 'A hash in the pattern must not end the regex');
assertSameValue(true, zfsmMatchesExclusion('tank/docker-old/x', '/docker%-.-/'), 'Lua lazy repeat failed');
assertSameValue(false, zfsmMatchesExclusion('tank/abc', 'tank/%u'), 'Lua upper class matched lowercase');
assertSameValue(false, zfsmMatchesExclusion('tank/Docker-old', 'tank/docker%-old'), 'Lua patterns are case sensitive');
assertSameValue(true, zfsmMatchesExclusion('tank/Docker-old', 'tank/docker-old'), 'Regex patterns stay case insensitive');
assertSameValue(false, zfsmMatchesExclusion('tank/a', 'b|a%d'), 'A Lua pipe is literal');
foreach (array('tank/(', '%', '%bxy', 'a%1', '[') as $invalid_pattern) {
	assertSameValue(false, zfsmExclusionIsValid($invalid_pattern), 'Invalid pattern accepted: '.$invalid_pattern);
	assertSameValue(false, zfsmMatchesExclusion('tank/data', $invalid_pattern), 'Invalid pattern must exclude nothing: '.$invalid_pattern);
}
assertSameValue(true, zfsmExclusionIsValid(''), 'An empty pattern is valid');
assertSameValue(array(), listDirectories('', array(), ''), 'Empty mountpoint must never be enumerated');
assertSameValue(array(), listDirectories('/', array(), ''), 'Filesystem root must never be enumerated');

$directory_test_root = '/tmp/zfsm-test-mount';
if (!is_dir($directory_test_root)) mkdir($directory_test_root, 0777, true);
$empty_directory = $directory_test_root.'/empty';
if (!is_dir($empty_directory)) mkdir($empty_directory, 0777, true);
assertSameValue(true, !empty(deleteDirectory($empty_directory, 0)['succeeded']), 'Non-force directory removal failed for an empty directory');
assertSameValue(false, file_exists($empty_directory), 'Non-force directory removal did not remove the empty directory');
$nonempty_directory = $directory_test_root.'/nonempty';
if (!is_dir($nonempty_directory)) mkdir($nonempty_directory, 0777, true);
file_put_contents($nonempty_directory.'/data', 'test');
$nonforce_result = deleteDirectory($nonempty_directory, 0);
assertSameValue(true, !empty($nonforce_result['failed']), 'Non-force directory removal must reject a non-empty directory');
assertSameValue(true, is_dir($nonempty_directory), 'Non-force directory removal deleted a non-empty directory');
assertSameValue(true, !empty(deleteDirectory($nonempty_directory, 1)['succeeded']), 'Forced recursive directory removal failed');
assertSameValue(false, file_exists($nonempty_directory), 'Forced recursive directory removal left the directory behind');
$nested_parent = $directory_test_root.'/nested';
$nested_child = $nested_parent.'/child';
mkdir($nested_child, 0777, true);
$nested_conversion = convertDirectory($nested_child, 'tank');
assertSameValue('Only a direct child directory of a dataset mountpoint can be converted', $nested_conversion['failed'][$nested_child] ?? '', 'Nested directory conversion was not rejected safely');
rmdir($nested_child);
rmdir($nested_parent);
assertSameValue(null, zfsmResolveDirectory($directory_test_root, true), 'A dataset mountpoint must never be accepted as a deletable directory');

$tree = zfsmLoadPoolTree('tank', true, '', 1);
assertSameValue('tank', $tree['name'], 'Pool root dataset was not returned');
assertSameValue('tank/data', $tree['child']['tank/data']['name'], 'Child filesystem missing from inventory');
assertSameValue('volume', $tree['child']['tank/vol']['type'], 'ZFS volume type was not retained');
assertSameValue('storage', $tree['org.example:role'], 'Root user property missing');
assertSameValue('media', $tree['child']['tank/data']['org.example:role'], 'Child user property missing');
assertSameValue(2, $tree['total_snapshots'], 'Snapshot total is incorrect');
assertSameValue('tank@root', $tree['snapshots'][0]['name'], 'Root-dataset snapshot missing');
assertSameValue('tank/data@daily', $tree['child']['tank/data']['snapshots'][0]['name'], 'Child snapshot missing');

$pools = getZFSPools();
assertSameValue('ONLINE', $pools['tank']['Health'], 'Pool inventory parsing failed');
assertSameValue("mirror-0\nsda\nsdb", getZFSPoolDevices('tank'), 'Pool device parsing failed');

$snapshot_result = getDatasetSnapshotsResult('tank', 'tank/data');
assertSameValue(true, $snapshot_result['ok'], 'Direct snapshot inventory failed');
assertSameValue('tank/data@daily', $snapshot_result['snapshots'][0]['name'], 'Direct snapshot name is incorrect');

$test_log = getenv('ZFSM_TEST_LOG');
if ($test_log) {
	@unlink($test_log);
	assertSameValue(true, !empty(renameDataset('tank/data', 'tank/data-renamed', 1)['succeeded']), 'Dataset rename failed');
	assertSameValue(true, !empty(setDatasetProperty('tank/data', 'compression', 'zstd')['succeeded']), 'Dataset property update failed');
	assertSameValue(true, !empty(destroyDataset('tank/data', 1)['succeeded']), 'Recursive dataset destroy failed');
	assertSameValue(true, !empty(createDatasetSnapshot('tank/data', 'manual', 1)['succeeded']), 'Recursive snapshot create failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Safe snapshot rollback failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 1)['succeeded']), 'Snapshot rollback with newer-snapshot removal failed');
	assertSameValue(true, !empty(cloneDatasetSnapshot('tank/data@daily', 'tank/clone')['succeeded']), 'Snapshot clone failed');
	assertSameValue(true, !empty(deleteDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Snapshot delete failed');
	putenv('ZFSM_TEST_MISSING_DATASET=tank/secure');
	assertSameValue(true, !empty(createDataset('tank/secure', array('encryption' => 'on', 'keyformat' => 'passphrase', 'keylocation' => 'prompt', 'passphrase' => 'secret123'))['succeeded']), 'Encrypted dataset create failed');
	putenv('ZFSM_TEST_MISSING_DATASET');
	$commands = file_get_contents($test_log);
	foreach (array('rename -f tank/data tank/data-renamed', 'set compression=zstd tank/data', 'destroy -v -r -f tank/data', 'snapshot -r tank/data@manual', 'rollback tank/data@daily', 'rollback -r tank/data@daily', 'clone tank/data@daily tank/clone', 'destroy tank/data@daily', 'create -o encryption=on -o keyformat=passphrase -o keylocation=prompt tank/secure') as $expected_command) {
		assertSameValue(true, strpos($commands, $expected_command) !== false, 'Expected command was not issued: '.$expected_command);
	}
	assertSameValue(false, strpos($commands, 'secret123') !== false, 'Encryption passphrase leaked into command arguments');

	// Read-only goes on after the share owner, or the owner cannot be set.
	@unlink($test_log);
	$mounts = sys_get_temp_dir().'/zfsm-create-owner';
	$nobody = trim((string)shell_exec('id -u nobody'));
	foreach (array('tank', 'tank/newparent', 'tank/newparent/child') as $dir) {
		if (!is_dir("$mounts/$dir")) mkdir("$mounts/$dir", 0777, true);
		chown("$mounts/$dir", 'root');
	}
	putenv('ZFSM_TEST_MOUNT_ROOT='.$mounts);
	putenv('ZFSM_TEST_MISSING_DATASET=tank/newparent,tank/newparent/child');
	assertSameValue(true, !empty(createDataset('tank/newparent/child', array('readonly' => 'on', 'sync' => 'always'))['succeeded']), 'Read-only create failed');
	$commands = file_get_contents($test_log);
	$parent = strpos($commands, "create tank/newparent\n");
	$create = strpos($commands, "create -o sync=always tank/newparent/child\n");
	$readonly = strpos($commands, 'set readonly=on tank/newparent/child');
	assertSameValue(true, $parent !== false && $create !== false && $create > $parent, 'each parent is made first, then the target, none with -p: '.$commands);
	assertSameValue(false, strpos($commands, 'create -p') !== false, 'create -p succeeds on a dataset someone else made');
	assertSameValue(true, $readonly !== false && $readonly > $create, 'readonly must be set after the create: '.$commands);
	assertSameValue(false, strpos($commands, '-o readonly=on') !== false, 'readonly must not be set at create time');
	assertSameValue($nobody, (string)fileowner("$mounts/tank/newparent"), 'a new parent gets the share owner');
	assertSameValue($nobody, (string)fileowner("$mounts/tank/newparent/child"), 'the new dataset gets the share owner');
	assertSameValue('0', (string)fileowner("$mounts/tank"), 'an existing dataset keeps its owner');

	// zfs create -p succeeds on an existing target; it must not be changed or claimed.
	@unlink($test_log);
	putenv('ZFSM_TEST_MISSING_DATASET');
	$answer = createDataset('tank/newparent/child', array('readonly' => 'on'));
	assertSameValue(true, empty($answer['succeeded']) && strpos(implode(' ', $answer['failed']), 'already exists') !== false, 'an existing target is refused: '.json_encode($answer));
	assertSameValue('', (string)@file_get_contents($test_log), 'nothing is created or set on an existing target');

	// A parent someone else made in the meantime stops the create, and is not given the share owner.
	foreach (array('tank/newparent', 'tank/newparent/child') as $dir) chown("$mounts/$dir", 'root');
	@unlink($test_log);
	putenv('ZFSM_TEST_MISSING_DATASET=tank/newparent,tank/newparent/child');
	putenv('ZFSM_TEST_CREATE_FAIL=tank/newparent');
	$answer = createDataset('tank/newparent/child', array());
	assertSameValue(true, empty($answer['succeeded']) && strpos(implode(' ', $answer['failed']), 'already exists') !== false, 'a parent that could not be made fails the create: '.json_encode($answer));
	assertSameValue(false, strpos((string)@file_get_contents($test_log), 'tank/newparent/child') !== false, 'the target is not created under a parent this request did not make');
	assertSameValue('0', (string)fileowner("$mounts/tank/newparent"), 'a parent this request did not make keeps its owner');
	putenv('ZFSM_TEST_CREATE_FAIL');
	@unlink($test_log);

	// A failed read of a parent stops the create; it must not run with ownership unknown.
	putenv('ZFSM_TEST_MISSING_DATASET=tank/newparent/child');
	putenv('ZFSM_TEST_LIST_FAIL=tank/newparent');
	$answer = createDataset('tank/newparent/child', array());
	assertSameValue(true, empty($answer['succeeded']) && strpos(implode(' ', $answer['failed']), 'Could not check') !== false, 'a failed read is refused: '.json_encode($answer));
	assertSameValue('', (string)@file_get_contents($test_log), 'nothing is created when a read fails');
	putenv('ZFSM_TEST_LIST_FAIL');
	putenv('ZFSM_TEST_MISSING_DATASET');
	putenv('ZFSM_TEST_MOUNT_ROOT');
}

// Only a confirmed absence counts as new: a failed read must not hand an existing dataset to nobody:users.
putenv('ZFSM_TEST_MISSING_DATASET=tank/newparent');
putenv('ZFSM_TEST_LIST_FAIL=tank/broken');
assertSameValue(true, zfsmDatasetMissing('tank/newparent'), 'a missing dataset is reported missing');
assertSameValue(null, zfsmDatasetMissing('tank/broken'), 'a failed read is reported as unknown');
assertSameValue(false, zfsmDatasetMissing('tank/data'), 'an existing dataset is not reported missing');
putenv('ZFSM_TEST_LIST_FAIL=tank/dataset does not exist');
assertSameValue(null, zfsmDatasetMissing('tank/dataset does not exist'), 'a failed read of a dataset named like the error is not an absence');
putenv('ZFSM_TEST_MISSING_DATASET=tank/dataset does not exist');
putenv('ZFSM_TEST_LIST_FAIL');
assertSameValue(true, zfsmDatasetMissing('tank/dataset does not exist'), 'a dataset named like the error can still be missing');
putenv('ZFSM_TEST_MISSING_DATASET');
putenv('ZFSM_TEST_LIST_FAIL');

echo "backend logic tests passed\n";
