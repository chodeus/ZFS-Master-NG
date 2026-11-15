<?php
// Replication: zfs send piped to zfs receive in the background, so a dataset keeps a copy on another pool that each run brings up to date.
// The copy is received read-only (a filesystem's unmounted, a volume's with no device), and never with -F: a run only adds to it, so nothing there is rolled back or destroyed.

// Starts scripts/replicate.php in the background (zfsmStartJob). With no snapshot picked, the job takes one under its lock;
// a copy with a stopped run carries that run on instead of the snapshot picked. $server: zfsmReplicaTargetOpen's.
function zfsmStartReplicate($zdataset, $snapshot, $zdest, $cfg, $server = '') {
	$new_name = $snapshot === '' ? zfsmNewSnapshotName($cfg, time()) : '';
	$target = zfsmReplicaTargetOpen($server);
	try {
		$plan = zfsmReplicatePlan($zdataset, $snapshot !== '' ? $snapshot : null, $zdest, array('new_name' => $new_name, 'estimate' => false, 'target' => $target));
	} finally {
		zfsmReplicaDisconnect($target);
	}
	// A server's failure names it as a run's does (zfsmDiagLearnPools): by the time diagnostics are made it may be gone from remotes.json.
	if (!$plan['can_proceed'] && zfsmReplicaSenderName($server)) {
		return zfsmFailure($zdataset, 'Replication'.(zfsmValidDatasetName($zdest) ? ' to '.$zdest : '').' on server '.$server.' not started: '.$plan['error']);
	}
	if (!$plan['can_proceed']) return zfsmFailure($zdataset, $plan['error']);
	$resume = $plan['mode'] === 'resume';
	$from = $resume ? substr($plan['snapshot'], strlen($zdataset) + 1) : ($new_name !== '' ? $new_name : $snapshot);
	$args = array($zdataset, $from, $zdest, !$resume && $new_name !== '' ? 'new' : 'existing', $server);
	list($state, $why) = zfsmStartJob('replicate.php', $args, zfsmJobLockFile($zdataset, 'replicate'));
	if ($state === 'running') return zfsmFailure($zdataset, 'A replication of this dataset is already running');
	if ($state !== 'started') return zfsmFailure($zdataset, 'The replication could not be started'.($why !== '' ? ': '.$why : ''));
	$where = zfsmReplicaTargetWhere($target, $plan['dest']);
	$said = $resume ? 'Replication of '.$plan['snapshot'].' to '.$where.' carries on from where it stopped; replicate again afterwards for newer snapshots' : 'Replication to '.$where.' started';
	return array('succeeded' => array($zdataset => $said.'; a notification says when it is done'), 'failed' => array());
}

// Runs zfs send into the target's receive (zfsmReplicaTargetReceive), the stream passing straight between them; reports the bytes
// sent, the first error that says why, and 'lost' when the connection there ended it (zfsmReplicaTargetLost).
function zfsmReplicateStream($plan, $zdataset, $target) {
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) return array('code' => 127, 'bytes' => 0, 'errors' => 'zfs must come from ZFSM_TEST_BIN_DIR in a test');
	$options = array('bypass_shell' => true);
	$send_pipes = $receive_pipes = array();
	$send = @proc_open(array_merge(array($zfs), zfsmReplicateSendArgs($plan)), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $send_pipes, null, null, $options);
	if (!is_resource($send)) return array('code' => -1, 'bytes' => 0, 'errors' => 'Unable to start zfs send');
	$receive = @proc_open(zfsmReplicaTargetReceive($target, $plan), array(0 => $send_pipes[1], 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $receive_pipes, null, null, $options);
	// Only send and receive may hold the stream: a copy left here would keep receive waiting after send ends.
	fclose($send_pipes[1]);
	if (!is_resource($receive)) {
		proc_terminate($send);
		fclose($send_pipes[2]);
		proc_close($send);
		return array('code' => -1, 'bytes' => 0, 'errors' => 'Unable to start zfs receive');
	}
	$streams = array('send' => $send_pipes[2], 'receive' => $receive_pipes[1]);
	foreach ($streams as $stream) stream_set_blocking($stream, false);
	$bytes = 0;
	$errors = array('send' => '', 'receive' => '');
	$partial = array('send' => '', 'receive' => '');
	$reported = microtime(true);
	$select_failures = 0;
	while (true) {
		$open = array_values(array_filter($streams, function($stream) { return !feof($stream); }));
		if (!$open) break;
		$write = $except = null;
		// A signal can interrupt the wait: the two zfs go on, and so does the reading, unless it keeps failing.
		if (@stream_select($open, $write, $except, 1) === false) {
			if (++$select_failures > 50) break;
			usleep(100000);
			continue;
		}
		$select_failures = 0;
		foreach ($open as $stream) {
			$side = $stream === $streams['send'] ? 'send' : 'receive';
			$lines = explode("\n", $partial[$side].(string)fread($stream, 65536));
			$partial[$side] = array_pop($lines);
			foreach ($lines as $line) {
				// -P prints a header with the estimate, then a progress line a second (time, bytes sent, snapshot); -t first prints the token's contents.
				if ($side === 'send' && preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}\t([0-9]+)\t/', $line, $match)) $bytes = (int)$match[1];
				elseif ($side === 'send' && preg_match('/^(full\t|incremental\t|size\t|resume token contents:|nvlist version:|\t[a-z_]+ = )/', $line)) continue;
				elseif (trim($line) !== '' && strlen($errors[$side]) < ZFSM_KEEP_OUTPUT) $errors[$side] .= $line."\n";
			}
		}
		if (microtime(true) - $reported >= 2) {
			zfsmCopyProgress('replicate_progress', array('dataset' => $zdataset, 'bytes' => $bytes, 'total' => $plan['size'] ?? null));
			$reported = microtime(true);
		}
	}
	foreach ($streams as $side => $stream) {
		if (trim($partial[$side]) !== '' && !preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}\t/', $partial[$side])) $errors[$side] .= $partial[$side];
		fclose($stream);
	}
	$send_code = proc_close($send);
	$receive_code = proc_close($receive);
	// One side failing fails the other: send on a broken pipe or a signal, receive on a short stream ("Not a send stream" if empty).
	// The reason: ssh's own failure (zfsmReplicaTargetLost), else receive's unless only that, else send's; its first line only.
	$receive_failed = $receive_code !== 0 && trim($errors['receive']) !== '' && preg_match('/failed to read from stream|incomplete stream|^Not a send stream$/im', $errors['receive']) !== 1;
	$send_failed = $send_code !== 0 && trim($errors['send']) !== '' && stripos($errors['send'], 'broken pipe') === false;
	$lost = zfsmReplicaTargetLost($target, $receive_code, $errors['receive']);
	$why = $lost ?? ($receive_failed || (!$send_failed && $receive_code !== 0) ? $errors['receive'] : $errors['send']);
	return array('code' => $send_code !== 0 ? $send_code : $receive_code, 'bytes' => $bytes, 'errors' => trim(strtok(trim($why)."\n", "\n")), 'lost' => $lost !== null);
}

// After a run: this destination's bookmark at the snapshot it now holds, and its older ones removed. Returns what went wrong, or ''.
function zfsmReplicaMark($zdataset, $plan) {
	$snapshot = substr($plan['snapshot'], strlen($zdataset) + 1);
	$prefix = $zdataset.'#'.zfsmReplicaBookmarkPrefix($plan['mark']);
	$result = runProcess('zfs', array('bookmark', $plan['snapshot'], $prefix.$snapshot));
	$marks = zfsmReplicaMarks($zdataset);
	// One already there from an earlier run of this snapshot (its copy since destroyed) is the same mark: the same guid.
	$guids = array_column($marks ?? array(), 'guid', 'name');
	$held = isset($guids[$plan['snapshot']], $guids[$prefix.$snapshot]) && $guids[$plan['snapshot']] === $guids[$prefix.$snapshot];
	if (($result['code'] ?? 1) !== 0 && !$held) return zfsmOneLine($result['output'] ?? '');
	foreach ($marks ?? array() as $mark) {
		if (strncmp($mark['name'], $prefix, strlen($prefix)) === 0 && $mark['name'] !== $prefix.$snapshot) runProcess('zfs', array('destroy', $mark['name']));
	}
	return '';
}

// The background run, here or to $server (opened under the lock): one per dataset at a time, its progress on the row, a notification at the end, or why it didn't start.
// $take: the run takes $snapshot first, under its lock, and removes it again unless a copy holds it or a stopped run may need it.
// $started runs once the plan holds, before zfs send; until then the reason a run doesn't start also goes to the starting page.
// $schedule, for a scheduled run (zfsmScheduleRun): its 'notify'(title, text, importance), and no full send to a copy that is gone.
function zfsmRunReplicate($zdataset, $snapshot, $zdest, $started = null, $take = false, $server = '', $schedule = null) {
	$title = 'ZFS Replication';
	$notify = $schedule['notify'] ?? function($title, $text, $type) { zfsnotify($title, $text, '', $type); };
	$to = zfsmReplicaTargetWhere(array('server' => $server), $zdest);
	$not_started = function($why) use ($zdataset, $to, $title, $started, $notify) {
		zfsmLog('Replication of '.$zdataset.' to '.$to.' not started: '.$why);
		$notify($title, 'Replication of '.$zdataset.' to '.$to.' not started: '.zfsmSafeText($why), 'warning');
		if ($started !== null) fwrite(STDOUT, zfsmOneLine($why)."\n");
		return 1;
	};
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file === null) return $not_started('its guid could not be read');
	$lock = zfsmJobLock($lock_file);
	if ($lock === null) return $not_started('its lock file could not be opened');
	// Another run holds it: two starts at once leave one replication, and that one sends the notification.
	if ($lock === false) {
		zfsmLog('Replication of '.$zdataset.' to '.$to.' not started: another replication of this dataset is running', LOG_NOTICE);
		return 0;
	}
	try {
		if ($take) {
			$taken = createDatasetSnapshot($zdataset, $snapshot, 0);
			if ($taken['failed']) return $not_started(implode('; ', $taken['failed']));
		}
		$target = zfsmReplicaTargetOpen($server);
		$plan = zfsmReplicatePlan($zdataset, $snapshot, $zdest, array('estimate' => false, 'target' => $target, 'scheduled' => $schedule !== null));
		if (!$plan['can_proceed']) {
			// Only the snapshot this run just took, which nothing has used.
			if ($take) runProcess('zfs', array('destroy', $zdataset.'@'.$snapshot));
			return $not_started($plan['error']);
		}
		if ($started !== null) $started();
		$plan['size'] = zfsmReplicateEstimate($plan);
		$what = $plan['snapshot'].' to '.zfsmReplicaTargetWhere($target, $plan['dest']);
		$how = $plan['mode'] === 'resume' ? ' (carried on from where it stopped)' : ($plan['base'] !== null ? ' (changes since '.zfsmReplicaShortName($zdataset, $plan['base']).')' : ' (in full)');
		zfsmLog('Replication of '.$what.$how.' started: zfs '.implode(' ', zfsmReplicateSendArgs($plan)).' | '.zfsmReplicaTargetReceiveText($target, $plan), LOG_NOTICE);
		zfsmCopyProgress('replicate_start', array('dataset' => $zdataset, 'total' => $plan['size']));
		try {
			$result = zfsmReplicateStream($plan, $zdataset, $target);
		} finally {
			zfsmCopyProgress('replicate_stop', array('dataset' => $zdataset));
		}
		if ($result['code'] !== 0) {
			$why = rtrim($result['errors'] !== '' ? $result['errors'] : 'exit code '.$result['code'], '.');
			list($token) = zfsmReplicaTargetToken($target, $plan['dest']);
			$then = $token === '' ? '.' : '. Replicate it again to carry on from where it stopped.';
			if ($token === null) $then = '. Whether it can carry on could not be read'.($take ? '; the snapshot it took is kept.' : '.');
			// Only a copy read as holding no token, after a failure the other end saw, frees the snapshot this run took.
			$lost = $result['lost'] ?? false;
			if ($take && $token === '' && $lost) $then = '; the copy there may hold it already, so the snapshot it took is kept.';
			if ($take && $token === '' && !$lost) runProcess('zfs', array('destroy', $zdataset.'@'.$snapshot));
			zfsmLog('Replication of '.$what.$how.' failed: '.$why);
			$notify($title, 'Replication of '.$what.' failed: '.zfsmSafeText($why).$then, 'warning');
			return 1;
		}
		$marked = zfsmReplicaMark($zdataset, $plan);
		$note = $marked === '' ? '' : 'The bookmark that keeps the next run incremental could not be made: '.$marked;
		zfsmLog('Replication of '.$what.' finished'.$how.'.'.($note !== '' ? ' '.$note : ''), LOG_NOTICE);
		$notify($title, 'Replication of '.$what.' finished'.$how.'.'.($note !== '' ? ' '.zfsmSafeText($note) : ''), $note === '' ? 'normal' : 'warning');
		return 0;
	} finally {
		zfsmReplicaDisconnect($target ?? array());
		zfsmJobUnlock($lock);
	}
}
