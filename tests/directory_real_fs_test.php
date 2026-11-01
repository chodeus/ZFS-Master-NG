<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function check($condition, $message) {
	if ($condition) return;
	fwrite(STDERR, "FAILED: $message\n");
	exit(1);
}

function fill($dir) {
	mkdir($dir.'/sub dir', 0750, true);
	file_put_contents($dir.'/sub dir/file', 'payload');
	link($dir.'/sub dir/file', $dir.'/hardlink');
	symlink('sub dir/file', $dir.'/symlink');
	chmod($dir.'/sub dir/file', 0640);
}

function shim($body) {
	$bin = '/tmp/zfsm-rsync-shim';
	if (!is_dir($bin)) mkdir($bin);
	file_put_contents($bin.'/rsync', "#!/bin/sh\n".$body."\n");
	chmod($bin.'/rsync', 0755);
	putenv('PATH='.$bin.':'.getenv('PATH'));
}

$root = '/tmp/zfsm-test-mount';
if (!is_dir($root)) mkdir($root, 0777, true);
$target = $root.'/My Share';
$mount = '/tmp/zfsm-new-dataset';
putenv('ZFSM_TEST_DATASET_MOUNT='.$mount);
putenv('ZFSM_TEST_MISSING_DATASET=tank/My Share');

function reset_tree($target, $mount, $root) {
	foreach (glob($root.'/*') ?: array() as $old) exec('rm -rf '.escapeshellarg($old));
	exec('rm -rf '.escapeshellarg($mount));
	mkdir($mount);
	fill($target);
}

// A dataset mounted inside a deleted directory must survive a forced delete.
mkdir($root.'/parent/inner', 0777, true);
file_put_contents($root.'/parent/own', 'x');
exec('mount -t tmpfs tmpfs '.escapeshellarg($root.'/parent/inner'), $out, $mounted);
check($mounted === 0, 'test needs a tmpfs mount');
file_put_contents($root.'/parent/inner/data', 'keep');
$result = deleteDirectory($root.'/parent', 1);
check(!empty($result['failed']), 'delete across a mount must report failure');
check(is_file($root.'/parent/inner/data'), 'forced delete crossed a mount boundary');
exec('umount '.escapeshellarg($root.'/parent/inner'));
exec('rm -rf '.escapeshellarg($root.'/parent'));

// A symlink to outside must not let delete reach the outside tree.
mkdir('/tmp/zfsm-outside');
file_put_contents('/tmp/zfsm-outside/data', 'keep');
symlink('/tmp/zfsm-outside', $root.'/escape');
$result = deleteDirectory($root.'/escape', 1);
check(!empty($result['failed']), 'symlink to outside must be refused');
check(is_file('/tmp/zfsm-outside/data'), 'delete followed a symlink out of the dataset');
unlink($root.'/escape');

// Clean conversion: name with a space, identical tree, source gone.
reset_tree($target, $mount, $root);
$result = convertDirectory($target, 'tank');
check(!empty($result['succeeded']), 'conversion failed: '.json_encode($result));
check(file_get_contents($mount.'/sub dir/file') === 'payload', 'file not copied');
check(fileinode($mount.'/sub dir/file') === fileinode($mount.'/hardlink'), 'hardlink not preserved');
check(is_link($mount.'/symlink'), 'symlink not preserved');
check((fileperms($mount.'/sub dir/file') & 0777) === 0640, 'mode not preserved');
check(glob($root.'/*_zfsm_tmp_*') === array(), 'temporary source left behind after success');

// A snapdir=visible .zfs in the new dataset is not a difference and must not be deleted.
reset_tree($target, $mount, $root);
mkdir($mount.'/.zfs');
$result = convertDirectory($target, 'tank');
check(!empty($result['succeeded']), '.zfs broke verification: '.json_encode($result));
check(is_dir($mount.'/.zfs'), 'verification or copy touched .zfs');

// A failed copy keeps the source.
reset_tree($target, $mount, $root);
shim('case "$*" in *--version*) echo "rsync  version 3.4.1 protocol version 32"; exit 0;; esac; exit 23');
$result = convertDirectory($target, 'tank');
check(empty($result['succeeded']), 'failed rsync reported success');
check(strpos(reset($result['failed']), 'rsync failed with exit code 23') !== false, 'failed rsync gave the wrong reason');
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'failed copy lost the source');

// A copy that exits 0 but differs keeps the source.
reset_tree($target, $mount, $root);
shim('case "$*" in *--version*) echo "rsync  version 3.4.1 protocol version 32"; exit 0;; *--dry-run*) echo ">f..T...... sub dir/file"; exit 0;; esac; exit 0');
$result = convertDirectory($target, 'tank');
check(empty($result['succeeded']), 'unverified copy reported success');
check(strpos(reset($result['failed']), 'did not verify') !== false, 'unverified copy gave the wrong reason');
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'unverified copy lost the source');

// rsync that cannot run keeps the source too.
reset_tree($target, $mount, $root);
shim('exit 127');
$result = convertDirectory($target, 'tank');
check(empty($result['succeeded']), 'missing rsync reported success');
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'missing rsync lost the source');

echo "real filesystem directory tests passed\n";
