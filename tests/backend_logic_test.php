<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

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
$values = array('tank/data', 'filesystem', '1024', '2048', '512', 'off', '-', '/mnt/tank/data', 'lz4', '1.25', '256', 'none', '131072', 'on', 'sa', 'all', 'off', 'sensitive', 'standard', '1700000000', '-', '-');
$rows = zfsmParseTable(implode("\t", $values), $columns);
assertSameValue(1, count($rows), 'Dataset TSV row was not parsed');
assertSameValue(125, $rows[0]['compressratio'], 'Compression ratio normalization failed');
assertSameValue(100, zfsmNormalizeProperty('compressratio', '1.00'), 'A parsable 1.00 ratio must not truncate');
assertSameValue(125, zfsmNormalizeProperty('compressratio', '1.25x'), 'The human 1.25x ratio form');
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
$listed = array(array('name' => 'tank'), array('name' => 'tank/skip'), array('name' => 'tank/skip/child'), array('name' => 'tank/skipper'), array('name' => 'tank/keep'));
assertSameValue(array('tank', 'tank/skipper', 'tank/keep'), array_keys(zfsmIncludedDatasets($listed, 'tank/skip$')), 'A dataset under an excluded one was kept, or a sibling sharing its prefix was dropped');

// Settings: the first, misspelt name still counts; blank values fall back; numbers are read as numbers.
$config = loadConfig(array('general' => array('exclussion' => '^old$', 'snap_pattern' => '', 'refresh_interval' => '45')));
assertSameValue('^old$', $config['dataset_exclusion'], 'The misspelt exclusion setting was ignored');
assertSameValue('Y-m-d-His', $config['snap_pattern'], 'A blank snapshot pattern did not fall back');
assertSameValue(45, $config['refresh_interval'], 'A saved refresh interval was ignored');
assertSameValue(0, $config['snap_utc'], 'Snapshot names did not default to local time');
assertSameValue(0, $config['alert_interval'], 'Pool health alerts did not default to off');
assertSameValue(0, $config['alert_stale'], 'Stale snapshot alerts did not default to off');

// New snapshot names: the prefix, then the date on the clock the settings name. Perth is UTC+8 all year.
$zone = date_default_timezone_get();
date_default_timezone_set('Australia/Perth');
$naming = loadConfig(array('general' => array('snap_prefix' => 'auto-', 'snap_pattern' => 'Y-m-d-His')));
assertSameValue('auto-2026-11-03-180000', zfsmNewSnapshotName($naming, 1793700000), 'A local-time snapshot name used another clock');
$naming = loadConfig(array('general' => array('snap_prefix' => 'auto-', 'snap_pattern' => 'Y-m-d-His', 'snap_utc' => '1')));
assertSameValue('auto-2026-11-03-100000', zfsmNewSnapshotName($naming, 1793700000), 'A UTC snapshot name used local time');
date_default_timezone_set($zone);
assertSameValue('', loadConfig(array('general' => array('exclusion' => ' ')))['dataset_exclusion'], 'A blank exclusion was kept');
assertSameValue('^new$', loadConfig(array('general' => array('exclussion' => '^old$', 'exclusion' => '^new$')))['dataset_exclusion'], 'The misspelt name overrode the current one');
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
// The page shows a name that isn't UTF-8 with U+FFFD in its place, so a path holding U+FFFD may be another folder's: refused.
$twin = $directory_test_root.'/a'."\u{FFFD}";
mkdir($twin);
assertSameValue(array(true, true), array(!empty(deleteDirectory($twin, 1)['failed']), is_dir($twin)), 'A folder whose path holds U+FFFD is not deleted');
rmdir($twin);
$nested_parent = $directory_test_root.'/nested';
$nested_child = $nested_parent.'/child';
mkdir($nested_child, 0777, true);
$nested_conversion = convertDirectory($nested_child, 'tank');
assertSameValue('Only a direct child directory of a dataset mountpoint can be converted', $nested_conversion['failed'][$nested_child] ?? '', 'Nested directory conversion was not rejected safely');
rmdir($nested_child);
rmdir($nested_parent);
assertSameValue(null, zfsmResolveDirectory($directory_test_root, true), 'A dataset mountpoint must never be accepted as a deletable directory');

// A dataset's own snapshots, or its child datasets' too. The child check is only a read: a failed one offers no children.
assertSameValue(array('tank@root'), array_column(getDatasetSnapshotsResult('tank', 'tank')['snapshots'], 'name'), 'Without children only the dataset\'s own snapshots are listed');
assertSameValue(array('tank@root', 'tank/data@daily'), array_column(getDatasetSnapshotsResult('tank', 'tank', true)['snapshots'], 'name'), 'With children their snapshots are listed too, oldest first');
assertSameValue(array(true, false, false), array(zfsmHasChildDatasets('tank'), zfsmHasChildDatasets('tank/data'), zfsmHasChildDatasets('../tank')), 'Only a dataset with a filesystem or volume under it has children');
putenv('ZFSM_TEST_CHILD_LIST_FAIL=1');
assertSameValue(false, zfsmHasChildDatasets('tank'), 'A failed child list offers no children');
putenv('ZFSM_TEST_CHILD_LIST_FAIL');
// The exclusion pattern hides a child's snapshots as it hides the child: a match on it or on a dataset above it.
assertSameValue(array(true, true, false), array(zfsmDatasetExcluded('cache/docker', 'cache/docker$'), zfsmDatasetExcluded('cache/docker/layer1', 'cache/docker$'), zfsmDatasetExcluded('cache/dockerx', 'cache/docker$')), 'A dataset under an excluded one is excluded too');
assertSameValue(array('tank@root'), array_column(getDatasetSnapshotsResult('tank', 'tank', true, 'tank/data')['snapshots'], 'name'), 'An excluded child\'s snapshots are not listed');
assertSameValue(array('tank/data@daily'), array_column(getDatasetSnapshotsResult('tank', 'tank/data', false, 'tank/data')['snapshots'], 'name'), 'A dataset opened directly still lists its own');
assertSameValue(array('tank/data@daily'), array_column(getDatasetSnapshotsResult('tank', 'tank/data', true, 'tank/data')['snapshots'], 'name'), 'An excluded dataset opened with its children still lists its own');
assertSameValue(array(true, false), array(zfsmHasChildDatasets('tank', 'tank/data'), zfsmHasChildDatasets('tank', 'tank/(data|vol)')), 'Children the pattern hides are not offered');

// A command that prints a line per file runs to its end, keeping only the first of its output and counting its lines.
$kept = runProcess('sh', array('-c', 'seq 300000; exit 3'), null, 0, 100);
assertSameValue(array(3, true, 300000), array($kept['code'], strlen($kept['output']) <= 100, $kept['lines']), 'Kept output must be cut and counted, with the exit code');
$kept = runProcess('printf', array('a\nb'), null, 0, 100);
assertSameValue(array("a\nb", 2), array($kept['output'], $kept['lines']), 'A last line without a newline still counts');
$kept = runProcess('true', array(), null, 0, 100);
assertSameValue(array('', 0), array($kept['output'], $kept['lines']), 'No output is no lines');

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
	$refused = createDataset('tank/newset', array('canmount' => 'off'));
	assertSameValue(true, empty($refused['succeeded']) && strpos((string)reset($refused['failed']), 'Invalid dataset property: canmount') !== false, 'An option outside the allowed list was not refused');
	assertSameValue(false, strpos((string)@file_get_contents($test_log), 'canmount'), 'A refused option reached zfs');
	assertSameValue(true, !empty(renameDataset('tank/data', 'tank/data-renamed', 1)['succeeded']), 'Dataset rename failed');
	assertSameValue(true, !empty(setDatasetProperty('tank/data', 'compression', 'zstd')['succeeded']), 'Dataset property update failed');
	assertSameValue(true, !empty(destroyDataset('tank/data', 1)['succeeded']), 'Recursive dataset destroy failed');
	assertSameValue(true, !empty(createDatasetSnapshot('tank/data', 'manual', 1)['succeeded']), 'Recursive snapshot create failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Safe snapshot rollback failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 1)['succeeded']), 'Snapshot rollback with newer-snapshot removal failed');
	assertSameValue(true, !empty(cloneDatasetSnapshot('tank/data@daily', 'tank/clone')['succeeded']), 'Snapshot clone failed');
	assertSameValue(true, !empty(deleteDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Snapshot delete failed');
	putenv('ZFSM_TEST_MISSING_DATASET=tank/secure');
	$secure = sys_get_temp_dir().'/zfsm-secure';
	if (!is_dir($secure)) mkdir($secure);
	putenv('ZFSM_TEST_DATASET_MOUNT='.$secure);
	assertSameValue(true, !empty(createDataset('tank/secure', array('encryption' => 'on', 'keyformat' => 'passphrase', 'keylocation' => 'prompt', 'passphrase' => 'secret123'))['succeeded']), 'Encrypted dataset create failed');
	putenv('ZFSM_TEST_DATASET_MOUNT');
	putenv('ZFSM_TEST_MISSING_DATASET');
	$commands = file_get_contents($test_log);
	foreach (array('rename -f tank/data tank/data-renamed', 'set compression=zstd tank/data', 'destroy -r -f tank/data', 'snapshot -r tank/data@manual', 'rollback tank/data@daily', 'rollback -r tank/data@daily', 'clone tank/data@daily tank/clone', 'destroy tank/data@daily', 'create -o encryption=on -o keyformat=passphrase -o keylocation=prompt tank/secure') as $expected_command) {
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

	// A new dataset left without the share owner is reported, and read-only is still tried.
	@unlink($test_log);
	rmdir("$mounts/tank/newparent/child");
	putenv('ZFSM_TEST_GET_FAIL=tank/newparent');
	putenv('ZFSM_TEST_SET_FAIL=1');
	$answer = createDataset('tank/newparent/child', array('readonly' => 'on'));
	putenv('ZFSM_TEST_GET_FAIL');
	putenv('ZFSM_TEST_SET_FAIL');
	mkdir("$mounts/tank/newparent/child");
	assertSameValue(array('succeeded' => array(), 'failed' => array('tank/newparent/child' => "Created, but the share owner could not be set on tank/newparent, tank/newparent/child; it could not be made read-only: cannot set property for 'tank/newparent/child': permission denied")), $answer, 'an unset owner or read-only was not reported');

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

$multi = commandAnswer('tank/a', array('code' => 1, 'output' => "cannot unmount 'a': busy\n  cannot unmount 'b': busy\n"));
assertSameValue("cannot unmount 'a': busy; cannot unmount 'b': busy", $multi['failed']['tank/a'], 'A multi-line failure must read as one line');
assertSameValue("cannot remove '/mnt/Åsa à': busy; try again", zfsmOneLine("cannot remove '/mnt/Åsa à': busy\r\ntry again"), 'Joining lines keeps UTF-8 characters whole');
// A zfs killed by the inventory deadline, or failing silently, must still be reported. The fake answers name checks and fails -p reads.
$silent_bin = sys_get_temp_dir().'/zfsm-silent-'.getmypid();
mkdir($silent_bin);
file_put_contents($silent_bin.'/zfs', "#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" = -p ] && exit 1; last=\"\$arg\"; done\nprintf '%s\\n' \"\$last\"\n");
chmod($silent_bin.'/zfs', 0755);
$saved_bin = getenv('ZFSM_TEST_BIN_DIR');
putenv('ZFSM_TEST_BIN_DIR='.$silent_bin);
$tree = zfsmLoadPoolTree('tank', false, '', 0);
assertSameValue('Unable to list datasets (exit 1)', $tree['_error'] ?? null, 'A silent dataset list failure is reported');
$snap_tree = array('tank' => array());
zfsmAttachSnapshots('tank', $snap_tree);
assertSameValue('Unable to list snapshots (exit 1)', $snap_tree['tank']['_snapshot_error'] ?? null, 'A silent snapshot list failure is reported');
assertSameValue('Unable to read dataset properties (exit 1)', getAllDatasetProperties('tank/data', 0)['_error'] ?? null, 'A silent property read failure is reported');
assertSameValue('Unable to list snapshots (exit 1)', getDatasetSnapshotsResult('tank', 'tank/data')['error'] ?? null, 'A silent dataset snapshot failure is reported');
putenv('ZFSM_TEST_BIN_DIR'.($saved_bin === false ? '' : '='.$saved_bin));
exec('rm -rf '.escapeshellarg($silent_bin));

// Through the real zfsnotify and runProcess, with Unraid's notify script replaced by one that records its arguments.
$notify_root = sys_get_temp_dir().'/zfsm-notify-'.getmypid();
$notify_log = $notify_root.'/args';
mkdir($notify_root.'/plugins/dynamix/scripts', 0777, true);
file_put_contents($notify_root.'/plugins/dynamix/scripts/notify', "#!/bin/sh\nfor arg in \"\$@\"; do printf '%s\\n' \"\$arg\"; done >> '".$notify_log."'\n");
chmod($notify_root.'/plugins/dynamix/scripts/notify', 0755);
$saved_docroot = $GLOBALS['docroot'] ?? null;
$GLOBALS['docroot'] = $notify_root;
zfsmNotifyAnswer('ZFS Directory Convert', array('succeeded' => array('/mnt/tank/a' => 'Success', '/mnt/tank/b' => 'Success'), 'failed' => array('/mnt/tank/c' => 'no space')), 'Directory converted successfully', 'Unable to convert directory');
$GLOBALS['docroot'] = $saved_docroot;
$sent = (string)@file_get_contents($notify_log);
assertSameValue(true, strpos($sent, "\nDirectory converted successfully: /mnt/tank/a, /mnt/tank/b\n") !== false, 'Success notification lists the paths: '.$sent);
assertSameValue(true, strpos($sent, "\nUnable to convert directory: /mnt/tank/c: no space\n") !== false, 'Failure notification gives the reason: '.$sent);
assertSameValue(false, strpos($sent, '<') !== false, 'Notifications carry no HTML: '.$sent);
exec('rm -rf '.escapeshellarg($notify_root));

echo "backend logic tests passed\n";
