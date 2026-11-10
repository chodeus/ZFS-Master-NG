<?php
// zfs rewrite (OpenZFS 2.4+) writes a dataset's files again in place, so changed compression, checksum, dedup and copies apply to them.

// Older OpenZFS answers "unrecognized command" instead of the usage line.
function zfsmRewriteAvailable() {
	return strpos(runProcess('zfs', array('rewrite'))['output'] ?? '', 'rewrite [') !== false;
}

// The mountpoint whose files are rewritten, or a string saying why this dataset can't be.
function zfsmRewriteTarget($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return 'Only an existing filesystem dataset has files to rewrite';
	$pool = explode('/', $zdataset, 2)[0];
	if (getDatasetProperty($pool, $zdataset, 'mounted') !== 'yes') return 'The dataset is not mounted, so its files can\'t be reached';
	$mountpoint = getDatasetProperty($pool, $zdataset, 'mountpoint');
	if (!is_string($mountpoint) || strncmp($mountpoint, '/', 1) !== 0 || !is_dir($mountpoint)) return 'The dataset has no mountpoint to reach its files';
	return array($mountpoint);
}

// The pool's physical_rewrite feature, which -P needs: 'active' once a -P rewrite has run, 'enabled' before, '' where -P can't be used.
// A first -P makes it active for good, and OpenZFS before 2.4 (Unraid before 7.3) can then import the pool read-only only.
function zfsmRewriteFeature($pool) {
	$state = zfsmPoolFeature($pool, 'physical_rewrite');
	return in_array($state, array('enabled', 'active'), true) ? $state : '';
}

// What the confirm dialog says before a rewrite starts.
function zfsmRewritePreview($zdataset) {
	$target = zfsmRewriteTarget($zdataset);
	if (!is_array($target)) return array('can_proceed' => false, 'error' => $target);
	if (!zfsmRewriteAvailable()) return array('can_proceed' => false, 'error' => 'zfs rewrite needs OpenZFS 2.4 or later');
	$lock_file = zfsmJobLockFile($zdataset, 'rewrite');
	if ($lock_file === null) return array('can_proceed' => false, 'error' => 'The dataset\'s guid could not be read');
	if (zfsmJobRunning($lock_file)) return array('can_proceed' => false, 'error' => 'A rewrite of this dataset is already running');
	$pool = explode('/', $zdataset, 2)[0];
	$held = getDatasetProperty($pool, $zdataset, 'usedbysnapshots');
	return array('can_proceed' => true, 'physical_feature' => zfsmRewriteFeature($pool), 'snapshot_space' => is_numeric($held) ? (int)$held : null);
}

// Starts scripts/rewrite.php in the background (zfsmStartJob); -P only when asked.
function zfsmStartRewrite($zdataset, $physical) {
	$preview = zfsmRewritePreview($zdataset);
	if (!$preview['can_proceed']) return zfsmFailure($zdataset, $preview['error']);
	if ($physical && $preview['physical_feature'] === '') return zfsmFailure($zdataset, 'The pool\'s physical_rewrite feature is not enabled, so birth times can\'t be kept');
	list($started, $why) = zfsmStartJob('rewrite.php', array_merge(array($zdataset), $physical ? array('physical') : array()));
	if ($started) return array('succeeded' => array($zdataset => 'Rewrite started; a notification says when it is done'), 'failed' => array());
	$lock_file = zfsmJobLockFile($zdataset, 'rewrite');
	if ($lock_file !== null && zfsmJobRunning($lock_file)) return zfsmFailure($zdataset, 'A rewrite of this dataset is already running');
	return zfsmFailure($zdataset, 'The rewrite could not be started'.($why !== '' ? ': '.$why : ''));
}

// Runs zfs rewrite -v: each line on stdout is a file done, so the row can count them; keeps the first of its errors.
function zfsmRewriteFiles($args, $zdataset) {
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) return array('code' => 127, 'files' => 0, 'errors' => 'zfs must come from ZFSM_TEST_BIN_DIR in a test');
	$pipes = array();
	$process = @proc_open(array_merge(array($zfs), $args), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) return array('code' => -1, 'files' => 0, 'errors' => 'Unable to start zfs');
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$files = 0;
	$errors = '';
	$reported = microtime(true);
	while (true) {
		$open = array_values(array_filter(array($pipes[1], $pipes[2]), function($pipe) { return !feof($pipe); }));
		if (!$open) break;
		$write = $except = null;
		if (@stream_select($open, $write, $except, 1) === false) break;
		foreach ($open as $pipe) {
			$chunk = (string)fread($pipe, 65536);
			if ($pipe === $pipes[1]) $files += substr_count($chunk, "\n");
			elseif (strlen($errors) < ZFSM_KEEP_OUTPUT) $errors .= substr($chunk, 0, ZFSM_KEEP_OUTPUT - strlen($errors));
		}
		if (microtime(true) - $reported >= 2) {
			zfsmCopyProgress('rewrite_progress', array('dataset' => $zdataset, 'files' => $files));
			$reported = microtime(true);
		}
	}
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array('code' => proc_close($process), 'files' => $files, 'errors' => trim($errors));
}

// The background run: one per dataset at a time, its progress on the row, a notification at the end, or saying why it didn't start.
// $started runs once the lock is held, before zfs rewrite.
function zfsmRunRewrite($zdataset, $physical, $started = null) {
	$not_started = function($why) use ($zdataset) {
		zfsmLog('Rewrite of '.$zdataset.' not started: '.$why);
		zfsnotify('ZFS Dataset Rewrite', 'Rewrite of '.$zdataset.' not started: '.zfsmSafeText($why), '', 'warning');
		return 1;
	};
	$target = zfsmRewriteTarget($zdataset);
	if (!is_array($target)) return $not_started($target);
	$pool = explode('/', $zdataset, 2)[0];
	if ($physical && zfsmRewriteFeature($pool) === '') return $not_started('the pool\'s physical_rewrite feature is not enabled');
	$lock_file = zfsmJobLockFile($zdataset, 'rewrite');
	if ($lock_file === null) return $not_started('its guid could not be read');
	$lock = zfsmJobLock($lock_file);
	if ($lock === null) return $not_started('its lock file could not be opened');
	// Another run holds it: two starts at once leave one rewrite, and that one sends the notification.
	if ($lock === false) return 0;
	$args = array_merge(array('rewrite', '-r', '-x', '-v'), $physical ? array('-P') : array(), array($target[0]));
	if ($started !== null) $started();
	zfsmLog('Rewrite of '.$zdataset.' started: zfs '.implode(' ', $args), LOG_NOTICE);
	zfsmCopyProgress('rewrite_start', array('dataset' => $zdataset));
	try {
		$result = zfsmRewriteFiles($args, $zdataset);
	} finally {
		zfsmCopyProgress('rewrite_stop', array('dataset' => $zdataset));
		zfsmJobUnlock($lock);
	}
	$done = $result['files'].' file'.($result['files'] === 1 ? '' : 's').' rewritten';
	if ($result['code'] === 0) {
		zfsmLog('Rewrite of '.$zdataset.' finished: '.$done, LOG_NOTICE);
		zfsnotify('ZFS Dataset Rewrite', 'Rewrite of '.$zdataset.' finished: '.$done, '', 'normal');
		return 0;
	}
	$why = zfsmOneLine($result['errors'] !== '' ? $result['errors'] : 'zfs rewrite exit code '.$result['code']);
	zfsmLog('Rewrite of '.$zdataset.' failed after '.$done.': '.$why);
	zfsnotify('ZFS Dataset Rewrite', 'Rewrite of '.$zdataset.' failed after '.$done.': '.zfsmSafeText($why), '', 'warning');
	return 1;
}
