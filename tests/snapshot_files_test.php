<?php
// Browse paths and zfs diff through the real runProcess, with the fake zfs.

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

same('/mnt/cache/appdata/.zfs/snapshot', zfsmSnapshotBrowseRoot('/mnt/cache/appdata'), 'a pool dataset opens in the file browser');
same('/boot/.zfs/snapshot', zfsmSnapshotBrowseRoot('/boot'), 'the boot pool dataset opens too');
same('/mnt/cache/a b/.zfs/snapshot', zfsmSnapshotBrowseRoot('/mnt/cache/a b/'), 'a trailing slash is dropped');
foreach (array('none', 'legacy', '/srv/data', '/mntx/a', '/bootstrap', '', null) as $elsewhere) same('', zfsmSnapshotBrowseRoot($elsewhere), 'no browse path for '.var_export($elsewhere, true));

same('/mnt/a b\\c', zfsmDiffName('/mnt/a\\0040b\\0134c'), 'escaped space and backslash');
same('/mnt/café', zfsmDiffName('/mnt/caf\\0303\\0251'), 'escaped UTF-8 bytes');
same('/mnt/\\0040', zfsmDiffName('/mnt/\\01340040'), 'an escaped backslash before digits decodes once');
same('/mnt/\\0400', zfsmDiffName('/mnt/\\0400'), 'zfs never writes past \\0377, so that is not an escape');
same(null, zfsmDiffChange('warning: something else'), 'a line not in diff form is not a change');
same(null, zfsmDiffChange("R\tF\t/mnt/a"), 'a rename without its new name is not a change');
same(null, zfsmDiffChange("M\tFile\t/mnt/a"), 'the type is one character');
same(null, zfsmDiffChange("X\tF\t/mnt/a"), 'only + - M R are changes');

// /mnt/user is a tmpfs here (run_http_inventory_tests.sh); only the dataset with a .zfs folder can be browsed.
$with_zfs = '/mnt/user/zfsm-browse/data';
$without_zfs = '/mnt/user/zfsm-browse/plain';
mkdir($with_zfs.'/.zfs/snapshot', 0755, true);
mkdir($without_zfs, 0755, true);
// /tmp is a tmpfs too: a .zfs folder Unraid 7.2+ would still refuse to browse.
$outside = sys_get_temp_dir().'/zfsm-elsewhere/data';
mkdir($outside.'/.zfs/snapshot', 0755, true);
$cases = array(
	'a mounted dataset with a .zfs folder offers both' => array(array('ZFSM_TEST_DATASET_MOUNT='.$with_zfs), array('diff' => true, 'browse' => $with_zfs.'/.zfs/snapshot')),
	'no .zfs folder (snapdir=disabled, or mounted over): neither, since zfs diff stats .zfs/shares' => array(array('ZFSM_TEST_DATASET_MOUNT='.$without_zfs), array('diff' => false, 'browse' => '')),
	'unmounted: neither, even with a folder there' => array(array('ZFSM_TEST_DATASET_MOUNT='.$with_zfs, 'ZFSM_TEST_MOUNTED=no'), array('diff' => false, 'browse' => '')),
	'outside /mnt and /boot: no browse, even with a .zfs folder' => array(array('ZFSM_TEST_DATASET_MOUNT='.$outside), array('diff' => true, 'browse' => '')),
	'a failed read: neither' => array(array('ZFSM_TEST_DATASET_MOUNT='.$with_zfs, 'ZFSM_TEST_GET_FAIL=tank/data'), array('diff' => false, 'browse' => '')),
);
foreach ($cases as $message => list($settings, $expected)) {
	foreach ($settings as $setting) putenv($setting);
	same($expected, zfsmSnapshotAccess('tank', 'tank/data'), $message);
	foreach ($settings as $setting) putenv(explode('=', $setting)[0]);
}

$diff = zfsmSnapshotDiff('tank/data@daily');
same(false, $diff['truncated'] ?? null, 'a short diff is complete');
same(array(
	array('change' => 'R', 'type' => 'F', 'path' => '/mnt/tank/data/a', 'to' => '/mnt/tank/data/b'),
	array('change' => 'M', 'type' => 'F', 'path' => '/mnt/tank/data/café'),
	array('change' => 'M', 'type' => 'F', 'path' => '/mnt/tank/data/linked'),
	array('change' => '+', 'type' => 'F', 'path' => '/mnt/tank/data/new file.txt'),
	array('change' => '-', 'type' => '/', 'path' => '/mnt/tank/data/old'),
), $diff['changes'] ?? null, 'every line shape, decoded and sorted by path');

same(array('error' => 'Invalid snapshot name'), zfsmSnapshotDiff('tank/data'), 'a dataset is not a snapshot');
putenv('ZFSM_TEST_DIFF_FAIL=1');
same(array('error' => 'Cannot diff an unmounted snapshot'), zfsmSnapshotDiff('tank/data@daily'), 'zfs says why it could not compare');
putenv('ZFSM_TEST_DIFF_FAIL');
putenv('ZFSM_TEST_DIFF_GARBLED=1');
same(array('error' => 'zfs diff: something else'), zfsmSnapshotDiff('tank/data@daily'), 'output zfs diff never prints is an error, not "nothing changed"');
putenv('ZFSM_TEST_DIFF_GARBLED');

// stderr shares the pipe: zfs failing right after the limit must not read as more changes.
$failure = 'Unable to determine path or stats for object 99 in tank/data@daily: Invalid argument';
foreach (array(ZFSM_DIFF_LIMIT - 1, ZFSM_DIFF_LIMIT, 5) as $lines) {
	putenv('ZFSM_TEST_DIFF_FAIL_AFTER='.$lines);
	same(array('error' => $failure), zfsmSnapshotDiff('tank/data@daily'), "zfs failing after $lines lines says so");
}
putenv('ZFSM_TEST_DIFF_FAIL_AFTER='.(ZFSM_DIFF_LIMIT + 1));
same(true, zfsmSnapshotDiff('tank/data@daily')['truncated'] ?? null, 'a change after the limit means there are more');
// zfs already gone when the reader is past the limit: its error is the line after it, not "exit 1".
putenv('ZFSM_TEST_DIFF_FAIL_AFTER');
putenv('ZFSM_TEST_DIFF_FAIL_AFTER_FAST='.ZFSM_DIFF_LIMIT);
same(array('error' => $failure), zfsmSnapshotDiff('tank/data@daily'), 'zfs that already failed right at the limit says why');
putenv('ZFSM_TEST_DIFF_FAIL_AFTER_FAST');
putenv('ZFSM_TEST_DIFF_FAIL_AFTER');
putenv('ZFSM_TEST_DIFF_FAILED_EARLY=1');
same(array('error' => 'Unable to compare the snapshot (exit 1)'), zfsmSnapshotDiff('tank/data@daily'), 'a zfs diff that already failed is not a cut-short success');
putenv('ZFSM_TEST_DIFF_FAILED_EARLY');

putenv('ZFSM_TEST_DIFF_SLOW=1');
$GLOBALS['zfsm_inventory_deadline'] = microtime(true) + 0.3;
try {
	zfsmSnapshotDiff('tank/data@daily');
	same('timed out', 'finished', 'a slow zfs diff stops at the read time limit');
} catch (RuntimeException $error) {
	same('zfs timed out', $error->getMessage(), 'a slow diff names zfs, not the inventory');
}
unset($GLOBALS['zfsm_inventory_deadline']);
putenv('ZFSM_TEST_DIFF_SLOW');

// The fake writes 100000 lines and then this marker, unless it is stopped.
$marker = sys_get_temp_dir().'/zfsm-diff-finished';
@unlink($marker);
putenv('ZFSM_TEST_DIFF_ENDLESS='.$marker);
foreach (array('plain' => null, 'under the read time limit' => microtime(true) + 45) as $how => $deadline) {
	$GLOBALS['zfsm_inventory_deadline'] = $deadline;
	$diff = zfsmSnapshotDiff('tank/data@daily');
	same(true, $diff['truncated'] ?? null, "a long diff is cut short ($how)");
	same(ZFSM_DIFF_LIMIT, count($diff['changes'] ?? array()), "it keeps the first ZFSM_DIFF_LIMIT changes ($how)");
	same(false, is_file($marker), "zfs diff was stopped, not left to finish ($how)");
}
unset($GLOBALS['zfsm_inventory_deadline']);
putenv('ZFSM_TEST_DIFF_ENDLESS');

echo "snapshot files: browse paths and zfs diff passed\n";
