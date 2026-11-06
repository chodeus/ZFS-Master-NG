<?php
// Browse paths and zfs diff through the real runProcess, with the fake zfs.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

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

same('/mnt/cache/appdata/.zfs/snapshot', zfsmSnapshotBrowseRoot('/mnt/cache/appdata'), 'a pool dataset opens in the file browser');
same('/boot/.zfs/snapshot', zfsmSnapshotBrowseRoot('/boot'), 'the boot pool dataset opens too');
same('/mnt/cache/a b/.zfs/snapshot', zfsmSnapshotBrowseRoot('/mnt/cache/a b/'), 'a trailing slash is dropped');
foreach (array('none', 'legacy', '/srv/data', '/mntx/a', '/bootstrap', '', null) as $elsewhere) same('', zfsmSnapshotBrowseRoot($elsewhere), 'no browse path for '.var_export($elsewhere, true));

same('/mnt/a b\\c', zfsmDiffName('/mnt/a\\0040b\\0134c'), 'escaped space and backslash');
same('/mnt/café', zfsmDiffName('/mnt/caf\\0303\\0251'), 'escaped UTF-8 bytes');
same(null, zfsmParseDiff("M\tF\t/mnt/a\nwarning: something else"), 'a line not in diff form fails the read');
same(null, zfsmParseDiff("R\tF\t/mnt/a"), 'a rename without its new name fails the read');
same(array(), zfsmParseDiff(''), 'no output, no changes');

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
same(array('error' => 'Unable to read what zfs diff printed'), zfsmSnapshotDiff('tank/data@daily'), 'output zfs diff never prints is an error, not "nothing changed"');
putenv('ZFSM_TEST_DIFF_GARBLED');

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
