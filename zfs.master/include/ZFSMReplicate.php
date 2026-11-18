<?php
// Replication: zfs send piped to zfs receive in the background, so a dataset keeps a copy on another pool that each run brings up to date.
// The copy is received read-only (a filesystem's unmounted, a volume's with no device), and with -F only onto a holder, which has no snapshot (zfsmReplicaPlanHolder): a run only adds to a copy, so nothing there is rolled back; only a schedule's Keep removes its old snapshots (ZFSMKeep.php).

// Starts scripts/replicate.php in the background (zfsmStartJob). With no snapshot picked, the job takes one under its lock;
// a copy with a stopped run carries that run on instead of the snapshot picked. $server: zfsmReplicaTargetOpen's. $keep: a
// schedule's first run (zfsmScheduleAdd), as zfsmRunReplicate takes it.
function zfsmStartReplicate($zdataset, $snapshot, $zdest, $cfg, $server = '', $keep = null) {
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
	$args = array_merge(array($zdataset, $from, $zdest, !$resume && $new_name !== '' ? 'new' : 'existing', $server), array_map('strval', (array)$keep));
	list($state, $why) = zfsmStartJob('replicate.php', $args, zfsmJobLockFile($zdataset, 'replicate'));
	if ($state === 'running') return zfsmFailure($zdataset, 'A replication of this dataset is already running');
	if ($state !== 'started') return zfsmFailure($zdataset, 'The replication could not be started'.($why !== '' ? ': '.$why : ''));
	$where = zfsmReplicaTargetWhere($target, $plan['dest']);
	$said = $resume ? 'Replication of '.$plan['snapshot'].' to '.$where.' carries on from where it stopped; replicate again afterwards for newer snapshots' : 'Replication to '.$where.' started';
	return array('succeeded' => array($zdataset => $said.'; a notification says when it is done'), 'failed' => array());
}

// Starts $steps in order, each reading the one before: array(the processes, the output to read from each), or the step that didn't start.
function zfsmReplicateStart($steps) {
	$processes = $streams = array();
	$input = array('file', '/dev/null', 'r');
	foreach ($steps as $side => $command) {
		$last = $side === 'receive';
		$pipes = array();
		$process = @proc_open($command, array(0 => $input, 1 => array('pipe', 'w'), 2 => $last ? array('redirect', 1) : array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true));
		// Only the two steps a pipe joins may hold it: with a copy here, a step whose reader stopped would wait, not fail.
		if (is_resource($input)) fclose($input);
		if (!is_resource($process)) {
			foreach ($processes as $started) proc_terminate($started);
			foreach ($streams as $stream) fclose($stream);
			foreach ($processes as $started) proc_close($started);
			return $side;
		}
		$processes[$side] = $process;
		$streams[$side] = $last ? $pipes[1] : $pipes[2];
		if (!$last) $input = $pipes[1];
	}
	return array($processes, $streams);
}

// Why a stream failed, its first line: ssh's own (zfsmReplicaTargetLost), else receive's unless only a short stream, else send's, else
// zstd's, else receive's when it failed, else send's; send and zstd fail on a broken pipe once another step fails. array(it, whether ssh's).
function zfsmReplicateReason($target, $codes, $errors) {
	$receive_failed = $codes['receive'] !== 0 && trim($errors['receive']) !== '' && preg_match('/failed to read from stream|incomplete stream|^Not a send stream$/im', $errors['receive']) !== 1;
	$failed = function($side) use ($codes, $errors) {
		return ($codes[$side] ?? 0) !== 0 && trim($errors[$side]) !== '' && stripos($errors[$side], 'broken pipe') === false;
	};
	$lost = zfsmReplicaTargetLost($target, $codes['receive'], $errors['receive']);
	$side = $receive_failed ? 'receive' : ($failed('send') ? 'send' : ($failed('zstd') ? 'zstd' : ($codes['receive'] !== 0 ? 'receive' : 'send')));
	return array(trim(strtok(trim($lost ?? $errors[$side])."\n", "\n")), $lost !== null);
}

// Runs zfs send into the target's receive (zfsmReplicaTargetReceive), through any zstd step, the stream passing straight between them;
// reports the bytes sent, the first error that says why, and 'lost' when the connection there ended it (zfsmReplicaTargetLost).
function zfsmReplicateStream($plan, $zdataset, $target) {
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) return array('code' => 127, 'bytes' => 0, 'errors' => 'zfs must come from ZFSM_TEST_BIN_DIR in a test');
	$steps = array('send' => array_merge(array($zfs), zfsmReplicateSendArgs($plan)));
	$zstd = zfsmReplicaTargetZstdStep($target, $plan);
	if ($zstd !== null) $steps['zstd'] = $zstd;
	$steps['receive'] = zfsmReplicaTargetReceive($target, $plan);
	$started = zfsmReplicateStart($steps);
	if (is_string($started)) return array('code' => -1, 'bytes' => 0, 'errors' => 'Unable to start '.array('send' => 'zfs send', 'zstd' => 'zstd', 'receive' => 'zfs receive')[$started]);
	list($processes, $streams) = $started;
	foreach ($streams as $stream) stream_set_blocking($stream, false);
	$bytes = 0;
	$errors = $partial = array_fill_keys(array_keys($streams), '');
	$reported = microtime(true);
	$select_failures = 0;
	while (true) {
		$open = array_values(array_filter($streams, function($stream) { return !feof($stream); }));
		if (!$open) break;
		$write = $except = null;
		// A signal can interrupt the wait: the steps go on, and so does the reading, unless it keeps failing.
		if (@stream_select($open, $write, $except, 1) === false) {
			if (++$select_failures > 50) break;
			usleep(100000);
			continue;
		}
		$select_failures = 0;
		foreach ($open as $stream) {
			$side = array_search($stream, $streams, true);
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
	$codes = array();
	foreach ($processes as $side => $process) $codes[$side] = proc_close($process);
	list($why, $lost) = zfsmReplicateReason($target, $codes, $errors);
	return array('code' => $codes['send'] !== 0 ? $codes['send'] : $codes['receive'], 'bytes' => $bytes, 'errors' => $why, 'lost' => $lost);
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
// $schedule, for a scheduled run (zfsmScheduleRun): its 'notify'(title, text, importance), the seconds to 'wait' out another run, and no full send to a copy that is gone;
// optional: 'waited'(seconds) told the wait, 'gone' what to do when the dataset is, a 'tag' (zfsmKeepTag) for Keep, 'remake' a gone copy in full.
// $keep, for any run of a schedule: the snapshot it takes is tagged for the copy (zfsmKeepTag), and once it lands only those of
// them $keep keeps stay (0: all, zfsmKeepPrune).
function zfsmRunReplicate($zdataset, $snapshot, $zdest, $started = null, $take = false, $server = '', $schedule = null, $keep = null) {
	$title = 'ZFS Replication';
	$tag = $schedule['tag'] ?? zfsmKeepTag($server, $zdest);
	$notify = $schedule['notify'] ?? function($title, $text, $type) { zfsnotify($title, $text, '', $type); };
	$to = zfsmReplicaTargetWhere(array('server' => $server), $zdest);
	$not_started = function($why) use ($zdataset, $to, $title, $started, $notify) {
		zfsmLog('Replication of '.$zdataset.' to '.$to.' not started: '.$why);
		$notify($title, 'Replication of '.$zdataset.' to '.$to.' not started: '.zfsmSafeText($why), 'warning');
		if ($started !== null) fwrite(STDOUT, zfsmOneLine($why)."\n");
		return 1;
	};
	// Only the snapshot this run just took, which nothing has used.
	$refused = function($why) use ($zdataset, $snapshot, $take, $not_started) {
		if ($take) runProcess('zfs', array('destroy', $zdataset.'@'.$snapshot));
		return $not_started($why);
	};
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file === null) return $not_started(zfsmDatasetMissing($zdataset) === true ? 'it no longer exists'.(isset($schedule['gone']) ? '; '.$schedule['gone'] : '') : 'its guid could not be read');
	$lock = zfsmReplicateLock($lock_file, $schedule);
	if ($lock === null) return $not_started('its lock file could not be opened');
	// No page starts a scheduled run again: one that waited in vain is a problem.
	$waited = (int)ceil(($schedule['wait'] ?? 0) / 60);
	if ($lock === false && $schedule !== null) return $not_started('another replication of this dataset was still running'.($waited > 0 ? ' after this run waited '.$waited.' minute(s) for it' : ', and the run had no time left to wait for it'));
	// Another run holds it: two starts at once leave one replication, and that one sends the notification.
	if ($lock === false) {
		zfsmLog('Replication of '.$zdataset.' to '.$to.' not started: another replication of this dataset is running', LOG_NOTICE);
		return 0;
	}
	try {
		if ($take) {
			$taken = createDatasetSnapshot($zdataset, $snapshot, 0, zfsmKeepProps($keep, $tag));
			if ($taken['failed']) return $not_started(implode('; ', $taken['failed']));
		}
		$target = zfsmReplicaTargetOpen($server);
		$target['zstd'] = zfsmReplicaTargetZstd($target);
		for ($pass = 1; ; $pass++) {
			$plan = zfsmReplicatePlan($zdataset, $snapshot, $zdest, array('estimate' => false, 'target' => $target, 'scheduled' => $schedule !== null && !($schedule['remake'] ?? false)));
			if (!$plan['can_proceed']) return $refused($plan['error']);
			if ($started !== null) $started();
			// Holds go by the copy, so a run by hand carries on a scheduled one and takes its holds off.
			list($code, $text, $type) = zfsmReplicateSent($plan, $zdataset, $snapshot, $take, $target, zfsmKeepTag($server, $zdest));
			// A scheduled run that carried on a stopped one then sends the snapshot it took (help: Schedules); only its last step notifies.
			if ($code === 0 && $schedule !== null && $plan['mode'] === 'resume' && $pass === 1) continue;
			list($text, $type) = zfsmKeepAfter($code, $zdataset, $plan, $keep, $tag, $to, $text, $type);
			$notify($title, $text, $type);
			return $code;
		}
	} finally {
		zfsmReplicaDisconnect($target ?? array());
		zfsmJobUnlock($lock);
	}
}

// zfsmJobLock of $lock_file, waiting out another run up to $schedule's 'wait' seconds; its 'waited' is told how long.
function zfsmReplicateLock($lock_file, $schedule) {
	$lock = zfsmJobLock($lock_file);
	$since = time();
	for ($until = $since + ($schedule['wait'] ?? 0); $lock === false && time() < $until; $lock = zfsmJobLock($lock_file)) sleep(max(1, min(10, $until - time())));
	if (isset($schedule['waited'])) $schedule['waited'](time() - $since);
	return $lock;
}

// One plan of zfsmRunReplicate's sent, with its log lines and the row's progress: array(0 when it landed, its notification's text and type).
// $tag: the copy's (zfsmKeepTag), whose holds each run puts on before its send and a landed one takes off.
function zfsmReplicateSent($plan, $zdataset, $snapshot, $take, $target, $tag) {
	$plan['size'] = zfsmReplicateEstimate($plan);
	$what = $plan['snapshot'].' to '.zfsmReplicaTargetWhere($target, $plan['dest']);
	$how = $plan['mode'] === 'resume' ? ' (carried on from where it stopped)' : ($plan['base'] !== null ? ' (changes since '.zfsmReplicaShortName($zdataset, $plan['base']).')' : ' (in full)');
	zfsmLog('Replication of '.$what.$how.' started: zfs '.implode(' ', zfsmReplicateSendArgs($plan)).' | '.zfsmReplicaTargetReceiveText($target, $plan), LOG_NOTICE);
	zfsmCopyProgress('replicate_start', array('dataset' => $zdataset, 'total' => $plan['size']));
	$held = zfsmKeepHold($plan, $tag);
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
		// Before the snapshot it took goes, which its hold would refuse.
		$unheld = zfsmKeepStopped($zdataset, $tag, $token, $held);
		if ($take && $token === '' && !$lost) runProcess('zfs', array('destroy', $zdataset.'@'.$snapshot));
		zfsmLog('Replication of '.$what.$how.' failed: '.$why.$unheld);
		return array(1, 'Replication of '.$what.' failed: '.zfsmSafeText($why).$then.zfsmSafeText($unheld), 'warning');
	}
	$marked = zfsmReplicaMark($zdataset, $plan);
	$note = implode('. ', array_filter(array($marked === '' ? '' : 'The bookmark that keeps the next run incremental could not be made: '.$marked,
		$plan['holder'] ? zfsmReplicaHolderClear($plan['dest']) : '', zfsmKeepReleaseNote(zfsmKeepRelease($zdataset, $tag))), 'strlen'));
	zfsmLog('Replication of '.$what.' finished'.$how.'.'.($note !== '' ? ' '.$note : ''), LOG_NOTICE);
	return array(0, 'Replication of '.$what.' finished'.$how.'.'.($note !== '' ? ' '.zfsmSafeText($note) : ''), $note === '' ? 'normal' : 'warning');
}
