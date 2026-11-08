<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

// Everything this run makes lives in its own folder. On any exit, a failed check too, its tmpfs mounts come off and the folder goes.
$work = sys_get_temp_dir().'/zfsm-real-fs-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work, 0700);
$GLOBALS['zfsm_mounted'] = array();
register_shutdown_function(function() use ($work) {
	foreach (array_reverse($GLOBALS['zfsm_mounted']) as $at) exec('umount '.escapeshellarg($at).' 2>/dev/null');
	exec('rm -rf --one-file-system '.escapeshellarg($work));
});
$root = $work.'/test-mount';
$mount = $work.'/new-dataset';

// The progress feed, each message with the number of entries the new dataset held when it was sent.
$GLOBALS['zfsm_feed'] = array();
function publish($channel, $message) {
	global $mount;
	$held = is_dir($mount) ? count(scandir($mount)) - 2 : -1;
	$GLOBALS['zfsm_feed'][] = array(json_decode($message, true)['op'], $held);
}

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

function mount_tmpfs($path, $options = '') {
	exec('mount -t tmpfs '.$options.' tmpfs '.escapeshellarg($path), $out, $status);
	if ($status === 0) $GLOBALS['zfsm_mounted'][] = $path;
	return $status;
}

function shim($body, $name = 'rsync') {
	global $work;
	$bin = $work.'/shim';
	if (!is_dir($bin)) mkdir($bin);
	file_put_contents($bin.'/'.$name, "#!/bin/sh\n".$body."\n");
	chmod($bin.'/'.$name, 0755);
	putenv('PATH='.$bin.':'.getenv('PATH'));
}

mkdir($root);
$target = $root.'/My Share';
putenv('ZFSM_TEST_FILESYSTEM_MOUNT='.$root);
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
$mounted = mount_tmpfs($root.'/parent/inner');
check($mounted === 0, 'test needs a tmpfs mount');
file_put_contents($root.'/parent/inner/data', 'keep');
$result = deleteDirectory($root.'/parent', 1);
check(strpos(reset($result['failed']), 'mounted inside') !== false, 'delete around a mount must say why it refused');
check(is_file($root.'/parent/inner/data'), 'forced delete crossed a mount boundary');
check(is_file($root.'/parent/own'), 'refused delete still removed files beside the mount');
exec('umount '.escapeshellarg($root.'/parent/inner'));
exec('rm -rf '.escapeshellarg($root.'/parent'));

// A full filesystem must not cost the settings already saved.
$small = $work.'/small-fs';
mkdir($small);
$mounted = mount_tmpfs($small, '-o size=16k');
check($mounted === 0, 'test needs a tmpfs mount');
check(zfsmWriteFileAtomic($small.'/cfg', 'first'), 'a write that fits failed');
check(zfsmWriteFileAtomic($small.'/cfg', 'second') && file_get_contents($small.'/cfg') === 'second', 'a write did not replace the old file');
check(!zfsmWriteFileAtomic($small.'/cfg', str_repeat('x', 1024 * 1024)), 'a short write reported success');
check(file_get_contents($small.'/cfg') === 'second', 'a short write damaged the saved file');
check(glob($small.'/cfg.tmp*') === array(), 'a short write left its temp file');
exec('umount '.escapeshellarg($small));
rmdir($small);

// A child dataset's own mountpoint resolves to its parent: a forced delete would empty the child.
mkdir($root.'/mounted', 0777, true);
$mounted = mount_tmpfs($root.'/mounted');
check($mounted === 0, 'test needs a tmpfs mount');
file_put_contents($root.'/mounted/data', 'keep');
$result = deleteDirectory($root.'/mounted', 1);
check(empty($result['succeeded']), 'forced delete of a mount point reported success');
check(strpos(reset($result['failed']), 'itself a mount point') !== false, 'forced delete of a mount point must say why it refused');
check(is_file($root.'/mounted/data'), 'forced delete emptied a mounted filesystem');
putenv('ZFSM_TEST_MISSING_DATASET=tank/mounted');
$result = convertDirectory($root.'/mounted', 'tank');
putenv('ZFSM_TEST_MISSING_DATASET=tank/My Share');
check(empty($result['succeeded']), 'converting a mount point reported success');
check(strpos(reset($result['failed']), 'itself a mount point') !== false, 'converting a mount point must say why it refused');
check(is_file($root.'/mounted/data'), 'converting a mount point lost its data');
exec('umount '.escapeshellarg($root.'/mounted'));
rmdir($root.'/mounted');

// A symlink to outside must not let delete reach the outside tree.
$outside = $work.'/outside';
mkdir($outside);
file_put_contents($outside.'/data', 'keep');
symlink($outside, $root.'/escape');
$result = deleteDirectory($root.'/escape', 1);
check(!empty($result['failed']), 'symlink to outside must be refused');
check(is_file($outside.'/data'), 'delete followed a symlink out of the dataset');
unlink($root.'/escape');

// rm prints a line for each file it can't remove: 150 MB of them must not exhaust PHP's 128 MB, nor reach the answer.
mkdir($root.'/stuck');
shim('yes "rm: cannot remove: Operation not permitted" 2>/dev/null | head -c 150000000; exit 1', 'rm');
$result = deleteDirectory($root.'/stuck', 1);
unlink($work.'/shim/rm');
check(strlen((string)reset($result['failed'])) < 2 * ZFSM_KEEP_OUTPUT, 'a failed rm must give its first errors only: '.strlen((string)reset($result['failed'])).' bytes');
rmdir($root.'/stuck');

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

// A real .zfs folder in the source can't live in a dataset: refuse before moving anything.
reset_tree($target, $mount, $root);
mkdir($target.'/.zfs');
file_put_contents($target.'/.zfs/mine', 'keep');
$result = convertDirectory($target, 'tank');
check(empty($result['succeeded']), 'a source .zfs folder must refuse the conversion');
check(is_file($target.'/.zfs/mine'), 'refused conversion lost the .zfs folder');
check(glob($root.'/*_zfsm_tmp_*') === array(), 'refused conversion moved the source');
exec('rm -rf '.escapeshellarg($target));

// A filesystem mounted inside the source: refuse before moving anything.
reset_tree($target, $mount, $root);
mkdir($target.'/inner');
$mounted = mount_tmpfs($target.'/inner');
check($mounted === 0, 'test needs a tmpfs mount');
file_put_contents($target.'/inner/data', 'keep');
$result = convertDirectory($target, 'tank');
check(strpos(reset($result['failed']) ?: '', 'mounted inside') !== false, 'a nested mount must refuse the conversion');
check(is_file($target.'/inner/data') && is_file($target.'/sub dir/file'), 'refused conversion changed the source');
check(glob($root.'/*_zfsm_tmp_*') === array(), 'refused conversion moved the source');
exec('umount '.escapeshellarg($target.'/inner'));

// Cloning only where the pool can: OpenZFS 2.2+ with zfs_bclone_enabled=1 and the feature enabled on that pool.
$bclone = $work.'/bclone';
file_put_contents($bclone, "1\n");
$GLOBALS['zfsm_bclone_param'] = $bclone;
putenv('ZFSM_TEST_BCLONE=active');
check(zfsmBlockCloning('tank/data'), 'an active block_cloning feature must allow cloning');
check(!zfsmBlockCloning('other/data'), 'another pool must be asked about its own feature');
putenv('ZFSM_TEST_BCLONE=enabled');
check(zfsmBlockCloning('tank/data'), 'an enabled block_cloning feature must allow cloning');
putenv('ZFSM_TEST_BCLONE=disabled');
check(!zfsmBlockCloning('tank/data'), 'a disabled block_cloning feature must not clone');
putenv('ZFSM_TEST_BCLONE');
check(!zfsmBlockCloning('tank/data'), 'a pool without the feature must not clone');
putenv('ZFSM_TEST_BCLONE=active');
// A new encrypted dataset gets its own key, so ZFS would copy every file in full.
putenv('ZFSM_TEST_ENCRYPTION=aes-256-gcm');
check(!zfsmBlockCloning('tank/data'), 'an encrypted dataset must not clone');
putenv('ZFSM_TEST_ENCRYPTION');
putenv('ZFSM_TEST_GET_FAIL=tank/data');
check(!zfsmBlockCloning('tank/data'), 'an unreadable encryption setting must not clone');
putenv('ZFSM_TEST_GET_FAIL');
file_put_contents($bclone, "0\n");
check(!zfsmBlockCloning('tank/data'), 'zfs_bclone_enabled=0 must not clone');
unlink($bclone);
check(!zfsmBlockCloning('tank/data'), 'OpenZFS without zfs_bclone_enabled must not clone');

// The review: whether the files are cloned, and the first Unraid that still writes to the pool after its first clone (module/zfs/brt.c).
file_put_contents($bclone, "1\n");
reset_tree($target, $mount, $root);
$turns_on = function($bclone_state, $endian) {
	putenv('ZFSM_TEST_BCLONE='.$bclone_state);
	putenv($endian === null ? 'ZFSM_TEST_BCLONE_ENDIAN' : 'ZFSM_TEST_BCLONE_ENDIAN='.$endian);
	return zfsmCloneTurnsOn('tank');
};
$states = array($turns_on('enabled', 'enabled'), $turns_on('enabled', null), $turns_on('enabled', 'disabled'), $turns_on('enabled', 'fail'), $turns_on('active', 'enabled'), $turns_on('disabled', 'enabled'), $turns_on('fail', 'enabled'));
check($states === array('7.3', '7.0', '7.0', '7.3', '', '', null), 'a first clone needs 7.3 with the endian feature or when it can\'t be read, 7.0 without; active or missing turns nothing on; an unread feature is unknown: '.json_encode($states));
putenv('ZFSM_TEST_BCLONE=enabled');
putenv('ZFSM_TEST_BCLONE_ENDIAN=enabled');
// The new dataset can't be read before it exists, as real zfs answers, so the review must ask its parent.
putenv('ZFSM_TEST_GET_FAIL=tank/My Share');
$preview = zfsmConvertPreview($target, 'tank');
putenv('ZFSM_TEST_GET_FAIL');
check($preview === array('can_proceed' => true, 'clones' => true, 'blocker' => '', 'turns_on' => '7.3'), 'a pool cloning for the first time says what that turns on: '.json_encode($preview));
// Each reason the files are copied instead: encrypted, switched off, or no block cloning at all.
$blockers = array();
putenv('ZFSM_TEST_ENCRYPTION=aes-256-gcm');
$blockers[] = zfsmConvertPreview($target, 'tank');
putenv('ZFSM_TEST_ENCRYPTION');
file_put_contents($bclone, "0\n");
$blockers[] = zfsmConvertPreview($target, 'tank');
unlink($bclone);
$blockers[] = zfsmConvertPreview($target, 'tank');
file_put_contents($bclone, "1\n");
check(array_map(function($answer) { return array($answer['clones'] ?? null, $answer['blocker'] ?? null, $answer['turns_on'] ?? null); }, $blockers) === array(array(false, 'encrypted', ''), array(false, 'switched off', ''), array(false, 'no feature', '')), 'each copy says why: '.json_encode($blockers));
// A review that can't tell refuses, rather than saying "copied" while the convert would clone.
$unread = array();
putenv('ZFSM_TEST_GET_FAIL=tank');
$unread[] = zfsmConvertPreview($target, 'tank');
putenv('ZFSM_TEST_GET_FAIL');
putenv('ZFSM_TEST_BCLONE=fail');
$unread[] = zfsmConvertPreview($target, 'tank');
putenv('ZFSM_TEST_BCLONE=enabled');
check(array_column($unread, 'can_proceed') === array(false, false) && array_column($unread, 'error') === array('Whether the files can be cloned could not be read', 'Whether the files can be cloned could not be read'), 'an unread encryption or feature refuses the review: '.json_encode($unread));
$preview = zfsmConvertPreview($root.'/no such folder', 'tank');
check(($preview['can_proceed'] ?? true) === false && ($preview['error'] ?? '') !== '', 'a folder that can\'t be converted is refused in the review: '.json_encode($preview));
putenv('ZFSM_TEST_BCLONE_ENDIAN');
putenv('ZFSM_TEST_BCLONE=active');
unlink($bclone);

// With cloning off, cp never runs and rsync copies as before.
$real_rsync = trim((string)shell_exec('command -v rsync'));
$real_cp = trim((string)shell_exec('command -v cp'));
$cp_log = $work.'/cp-log';
@unlink($cp_log);
reset_tree($target, $mount, $root);
shim('echo ran >> '.escapeshellarg($cp_log).'; exit 1', 'cp');
$GLOBALS['zfsm_feed'] = array();
$result = convertDirectory($target, 'tank');
check(!empty($result['succeeded']) && !file_exists($cp_log), 'cp ran without block cloning: '.json_encode($result));
check(!in_array('clone_directory', array_column($GLOBALS['zfsm_feed'], 0), true), 'the row said cloning without block cloning: '.json_encode($GLOBALS['zfsm_feed']));

// With cloning on, the clone alone must give a copy that verifies: this rsync copies nothing, it only checks.
file_put_contents($bclone, "1\n");
unlink($work.'/shim/cp');
reset_tree($target, $mount, $root);
shim('case "$*" in *--version*|*--dry-run*) exec '.escapeshellarg($real_rsync).' "$@";; esac; exit 0');
$GLOBALS['zfsm_feed'] = array();
$result = convertDirectory($target, 'tank');
check(!empty($result['succeeded']), 'a cloned copy did not verify: '.json_encode($result));
// The copy dialog and the row's "Cloning files" come before cp copies anything.
check($GLOBALS['zfsm_feed'] === array(array('start_directory_copy', 0), array('clone_directory', 0), array('stop_directory_copy', 3)), 'progress came after the clone: '.json_encode($GLOBALS['zfsm_feed']));

// A convert its review said would copy never clones, even where it now could.
reset_tree($target, $mount, $root);
shim('exec '.escapeshellarg($real_rsync).' "$@"');
$GLOBALS['zfsm_feed'] = array();
$result = convertDirectory($target, 'tank', false);
check(!empty($result['succeeded']) && !in_array('clone_directory', array_column($GLOBALS['zfsm_feed'], 0), true), 'a convert reviewed as a copy still cloned: '.json_encode(array($result, $GLOBALS['zfsm_feed'])));

// cp prints a line for each file it can't copy: 150 MB of them must not stop rsync from finishing the job.
reset_tree($target, $mount, $root);
shim('exec '.escapeshellarg($real_rsync).' "$@"');
shim('yes "cp: cannot create regular file: No space left on device" 2>/dev/null | head -c 150000000; exit 1', 'cp');
$result = convertDirectory($target, 'tank');
check(!empty($result['succeeded']) && file_get_contents($mount.'/sub dir/file') === 'payload', 'a cp that printed 150 MB of errors stopped the copy: '.json_encode($result));

// A real cp that fails partway through a file, here on a file-size limit, leaves it short; rsync must finish it.
reset_tree($target, $mount, $root);
$big = str_repeat('z', 65536);
file_put_contents($target.'/big', $big);
@unlink($cp_log);
shim('exec '.escapeshellarg($real_rsync).' "$@"');
shim("trap '' XFSZ; ulimit -f 1; ".escapeshellarg($real_cp).' "$@"; status=$?; for last; do :; done; echo "$status $(stat -c %s "${last}big")" > '.escapeshellarg($cp_log).'; exit $status', 'cp');
$result = convertDirectory($target, 'tank');
$cp_left = explode(' ', trim((string)@file_get_contents($cp_log)));
check(count($cp_left) === 2 && $cp_left[0] !== '0' && (int)$cp_left[1] < strlen($big), 'cp must have failed partway through the file: '.implode(' ', $cp_left));
check(!empty($result['succeeded']) && file_get_contents($mount.'/big') === $big && file_get_contents($mount.'/sub dir/file') === 'payload', 'rsync did not finish a failed clone: '.json_encode($result));
unlink($work.'/shim/cp');
unlink($bclone);
putenv('ZFSM_TEST_BCLONE');

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
// A copy that differs in every one of 3 million files: counted within PHP's 128 MB, and the source kept.
reset_tree($target, $mount, $root);
shim('case "$*" in *--version*) echo "rsync  version 3.4.1 protocol version 32"; exit 0;; *--dry-run*) yes ">f..T...... sub dir/file" 2>/dev/null | head -n 3000000; exit 0;; esac; exit 0');
$result = convertDirectory($target, 'tank');
check(strpos((string)reset($result['failed']), '3000000 differing, first: >f..T...... sub dir/file') !== false, 'a copy differing in every file must be counted: '.json_encode($result));

// rsync that cannot run keeps the source too.
reset_tree($target, $mount, $root);
shim('exit 127');
$result = convertDirectory($target, 'tank');
check(empty($result['succeeded']), 'missing rsync reported success');
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'missing rsync lost the source');

// A new dataset without a usable mountpoint is removed again and the directory put back.
reset_tree($target, $mount, $root);
putenv('ZFSM_TEST_DATASET_MOUNT=none');
$result = convertDirectory($target, 'tank');
check(strpos(reset($result['failed']) ?: '', 'original directory was restored') !== false, 'an unusable mountpoint must undo the conversion: '.json_encode($result));
check(is_file($target.'/sub dir/file') && glob($root.'/*_zfsm_tmp_*') === array(), 'an undone conversion must put the directory back');
// When that dataset can't be removed, the data stays where the message says.
putenv('ZFSM_TEST_DESTROY_BUSY=1');
$result = convertDirectory($target, 'tank');
putenv('ZFSM_TEST_DESTROY_BUSY');
putenv('ZFSM_TEST_DATASET_MOUNT='.$mount);
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'a failed undo must keep the data aside: '.json_encode($result));
$said = reset($result['failed']) ?: '';
check(strpos($said, 'manual recovery is required') !== false && strpos($said, $kept[0]) !== false, 'an undo that could not remove the dataset must say where the data is: '.json_encode($result));

// A failed create puts the directory back.
reset_tree($target, $mount, $root);
putenv('ZFSM_TEST_CREATE_FAIL=tank/My Share');
$result = convertDirectory($target, 'tank');
putenv('ZFSM_TEST_CREATE_FAIL');
check(strpos(reset($result['failed']) ?: '', 'dataset already exists') !== false, 'a failed create must give its own reason: '.json_encode($result));
check(is_file($target.'/sub dir/file') && glob($root.'/*_zfsm_tmp_*') === array(), 'a failed create must put the directory back');
// Made but unfinished (no share owner) and mounted where the directory was: the data stays where the message says.
reset_tree($target, $mount, $root);
putenv('ZFSM_TEST_GET_FAIL=tank/My Share');
putenv('ZFSM_TEST_CREATE_MOUNTS_AT='.$target);
$result = convertDirectory($target, 'tank');
putenv('ZFSM_TEST_CREATE_MOUNTS_AT');
putenv('ZFSM_TEST_GET_FAIL');
$kept = glob($root.'/*_zfsm_tmp_*');
check(count($kept) === 1 && is_file($kept[0].'/sub dir/file'), 'a failed move back must keep the data aside: '.json_encode($result));
$said = reset($result['failed']) ?: '';
check(strpos($said, 'Created, but the share owner could not be set') === 0, 'this case must fail inside createDataset: '.json_encode($result));
check(strpos($said, 'manual recovery is required') !== false && strpos($said, $kept[0]) !== false, 'a failed move back must say where the data is: '.json_encode($result));

echo "real filesystem directory tests passed\n";
