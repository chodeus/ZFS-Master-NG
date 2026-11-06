<?php
// zfs rewrite (OpenZFS 2.4+) writes a dataset's files again in place, so changed compression, checksum, dedup and copies apply to them.

// Older OpenZFS answers "unrecognized command" instead of the usage line.
function zfsmRewriteAvailable() {
	return strpos(runProcess('zfs', array('rewrite'))['output'] ?? '', 'rewrite [') !== false;
}

// One file per dataset, locked while its rewrite runs: a crashed run leaves no lock behind.
function zfsmRewriteStateFile($zdataset) {
	return rtrim($GLOBALS['zfsm_rewrite_dir'] ?? '/var/run/zfs.master.rewrite', '/').'/'.rawurlencode($zdataset).'.lock';
}

function zfsmRewriteRunning($zdataset) {
	$handle = @fopen(zfsmRewriteStateFile($zdataset), 'r');
	if ($handle === false) return false;
	$free = flock($handle, LOCK_SH | LOCK_NB);
	if ($free) flock($handle, LOCK_UN);
	fclose($handle);
	return !$free;
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

// -P keeps blocks' birth times, so incremental sends don't carry every file again; it needs the pool's physical_rewrite feature.
function zfsmRewritePhysical($pool) {
	$feature = runProcess('zpool', array('get', '-H', '-o', 'value', 'feature@physical_rewrite', $pool));
	return ($feature['code'] ?? 1) === 0 && in_array($feature['output'], array('enabled', 'active'), true);
}

// What the confirm dialog says before a rewrite starts.
function zfsmRewritePreview($zdataset) {
	$target = zfsmRewriteTarget($zdataset);
	if (!is_array($target)) return array('can_proceed' => false, 'error' => $target);
	if (!zfsmRewriteAvailable()) return array('can_proceed' => false, 'error' => 'zfs rewrite needs OpenZFS 2.4 or later');
	if (zfsmRewriteRunning($zdataset)) return array('can_proceed' => false, 'error' => 'A rewrite of this dataset is already running');
	$pool = explode('/', $zdataset, 2)[0];
	return array('can_proceed' => true, 'physical' => zfsmRewritePhysical($pool), 'snapshot_space' => (int)getDatasetProperty($pool, $zdataset, 'usedbysnapshots'));
}

// Starts scripts/rewrite.php in the background, so the rewrite outlives the page that asked for it.
function zfsmStartRewrite($zdataset) {
	$preview = zfsmRewritePreview($zdataset);
	if (!$preview['can_proceed']) return zfsmFailure($zdataset, $preview['error']);
	$script = ($GLOBALS['docroot'] ?? '/usr/local/emhttp').'/plugins/zfs.master/scripts/rewrite.php';
	$php = $GLOBALS['zfsm_php'] ?? '/usr/bin/php';
	exec('nohup '.escapeshellarg($php).' -q '.escapeshellarg($script).' '.escapeshellarg($zdataset).' > /dev/null 2>&1 &', $output, $code);
	if ($code !== 0) return zfsmFailure($zdataset, 'The rewrite could not be started');
	return array('succeeded' => array($zdataset => 'Rewrite started; a notification says when it is done'), 'failed' => array());
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

// The background run: one per dataset at a time, its progress on the row, a notification at the end.
function zfsmRunRewrite($zdataset) {
	$target = zfsmRewriteTarget($zdataset);
	if (!is_array($target)) {
		zfsmLog('Rewrite of '.$zdataset.' not started: '.$target);
		return 1;
	}
	$state_file = zfsmRewriteStateFile($zdataset);
	if (!is_dir(dirname($state_file))) @mkdir(dirname($state_file), 0700, true);
	$state = @fopen($state_file, 'c');
	// Another run holds the lock: two starts at once leave one rewrite.
	if ($state === false || !flock($state, LOCK_EX | LOCK_NB)) return 0;
	$pool = explode('/', $zdataset, 2)[0];
	$args = array_merge(array('rewrite', '-r', '-x', '-v'), zfsmRewritePhysical($pool) ? array('-P') : array(), array($target[0]));
	zfsmLog('Rewrite of '.$zdataset.' started: zfs '.implode(' ', $args), LOG_NOTICE);
	zfsmCopyProgress('rewrite_start', array('dataset' => $zdataset));
	try {
		$result = zfsmRewriteFiles($args, $zdataset);
	} finally {
		zfsmCopyProgress('rewrite_stop', array('dataset' => $zdataset));
		@unlink($state_file);
		flock($state, LOCK_UN);
		fclose($state);
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
