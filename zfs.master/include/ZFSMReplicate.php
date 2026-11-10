<?php
// Replication: zfs send piped to zfs receive in the background, so a dataset keeps a copy on another pool that each run brings up to date.
// The copy is received unmounted and read-only, and never with -F: a run only adds to it, so nothing there is rolled back or destroyed.

// What a destination already holds is marked on the source by a bookmark for that destination, so the next run stays incremental after the snapshot is gone.
// The destination is hex in the name, because dataset names may hold every character a bookmark name can.
function zfsmReplicaBookmarkPrefix($zdest) {
	return 'zfsm_'.bin2hex($zdest).'_';
}

// The dataset's snapshots (and bookmarks), oldest first, with their guid and createtxg; null when they can't be read.
function zfsmReplicaMarks($zdataset, $types = 'snapshot,bookmark') {
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', $types, '-o', 'name,guid,createtxg', '-s', 'createtxg', $zdataset));
	if (($result['code'] ?? 1) !== 0) return null;
	$marks = array();
	foreach (preg_split('/\R/', trim((string)$result['output']), -1, PREG_SPLIT_NO_EMPTY) as $line) {
		$parts = explode("\t", $line);
		if (count($parts) !== 3 || !preg_match('/^[0-9]+$/D', $parts[1]) || !preg_match('/^[0-9]+$/D', $parts[2])) continue;
		$own = strncmp($parts[0], $zdataset.'@', strlen($zdataset) + 1) === 0 || strncmp($parts[0], $zdataset.'#', strlen($zdataset) + 1) === 0;
		// guids are 64-bit, past PHP's integers: compared as text.
		if ($own) $marks[] = array('name' => $parts[0], 'guid' => $parts[1], 'txg' => (int)$parts[2]);
	}
	return $marks;
}

// The newest destination this dataset was replicated to, from its newest replication bookmark; '' when none.
function zfsmReplicaLastDestination($marks) {
	foreach (array_reverse($marks) as $mark) {
		// Only a name that decodes to a dataset is one of these bookmarks; another tool's zfsm_ab_daily isn't.
		if (preg_match('/#zfsm_((?:[0-9a-f]{2})+)_/', $mark['name'], $match) && zfsmValidDatasetName(hex2bin($match[1]))) return hex2bin($match[1]);
	}
	return '';
}

// A snapshot or bookmark as a notification names it: the snapshot's own name, also for this plugin's bookmarks.
function zfsmReplicaShortName($zdataset, $mark) {
	if (preg_match('/#zfsm_(?:[0-9a-f]{2})+_(.+)$/', $mark, $match)) return $match[1];
	return substr($mark, strlen($zdataset) + 1);
}

// The send for a plan: progress on stderr (-v -P), raw for an encrypted dataset, incremental from the plan's base, or resumed from its token.
function zfsmReplicateSendArgs($plan, $dry_run = false) {
	$args = array_merge(array('send', '-P', '-v'), $dry_run ? array('-n') : array());
	if ($plan['mode'] === 'resume') return array_merge($args, array('-t', $plan['token']));
	if ($plan['raw']) $args[] = '-w';
	if ($plan['base'] !== null) array_push($args, '-i', $plan['base']);
	$args[] = $plan['snapshot'];
	return $args;
}

// -s keeps a stopped run resumable; -u and canmount=noauto keep the copy unmounted, even after a reboot; readonly=on stops writes to it.
// A resumed receive takes the same options (OpenZFS 2.4.4, checked live), so a first copy stopped part-way still gets them.
function zfsmReplicateReceiveArgs($plan) {
	$args = array('receive', '-s', '-u', '-o', 'readonly=on');
	if ($plan['type'] === 'filesystem') array_push($args, '-o', 'canmount=noauto');
	$args[] = $plan['dest'];
	return $args;
}

function zfsmReplicateSize($output) {
	return preg_match('/^size\s+([0-9]+)\s*$/m', (string)$output, $match) ? (int)$match[1] : null;
}

// What a plan sends, from zfs send -n; null when it can't be estimated.
function zfsmReplicateEstimate($plan) {
	$estimate = runProcess('zfs', zfsmReplicateSendArgs($plan, true));
	return ($estimate['code'] ?? 1) === 0 ? zfsmReplicateSize($estimate['output']) : null;
}

function zfsmReplicaBookmarkFits($zdataset, $zdest, $snapshot) {
	return strlen($zdataset.'#'.zfsmReplicaBookmarkPrefix($zdest).$snapshot) <= 255;
}

// What a run from $snapshot to $zdest would do, or why it can't: mode 'full', 'incremental' (from 'base') or 'resume' (from 'token'), with an estimated size.
// A null $snapshot is one the run takes first, named $with['new_name']: newer than all the others, its size unknown until then.
// $with['marks'] passes the dataset's marks already read; $with['estimate'] false skips the zfs send -n.
function zfsmReplicatePlan($zdataset, $snapshot, $zdest, $with = array()) {
	$refuse = function($why) { return array('can_proceed' => false, 'error' => $why); };
	$too_long = 'The names are too long for the bookmark that keeps the next replication incremental';
	if (!isset($with['marks']) && !zfsmDatasetExists($zdataset)) return $refuse('Only an existing dataset can be replicated');
	$from = $snapshot === null ? null : $zdataset.'@'.$snapshot;
	if ($from !== null && !zfsmValidSnapshotName($from)) return $refuse('Pick one of the dataset\'s snapshots');
	if (!zfsmValidDatasetName($zdest) || strpos($zdest, '/') === false) return $refuse('The copy needs a dataset name inside a pool, such as backup/'.basename($zdataset));
	if ($zdest === $zdataset || strncmp($zdest, $zdataset.'/', strlen($zdataset) + 1) === 0 || strncmp($zdataset, $zdest.'/', strlen($zdest) + 1) === 0) {
		return $refuse('The copy can\'t be the dataset itself, go inside it, or replace one of its parents');
	}
	$marks = $with['marks'] ?? zfsmReplicaMarks($zdataset);
	if ($marks === null) return $refuse('The dataset\'s snapshots could not be read');
	$pool = explode('/', $zdataset, 2)[0];
	$encryption = getDatasetProperty($pool, $zdataset, 'encryption');
	$type = getDatasetProperty($pool, $zdataset, 'type');
	if (!is_string($encryption) || !in_array($type, array('filesystem', 'volume'), true)) return $refuse('The dataset\'s properties could not be read');
	$parent = substr($zdest, 0, strrpos($zdest, '/'));
	if (!zfsmDatasetExists($parent, 'filesystem')) return $refuse('The copy goes inside '.$parent.', which doesn\'t exist');
	$missing = zfsmDatasetMissing($zdest);
	if ($missing === null) return $refuse('Could not check whether '.$zdest.' exists');
	$plan = array('can_proceed' => true, 'mode' => 'full', 'snapshot' => $from, 'dest' => $zdest, 'raw' => $encryption !== 'off', 'type' => $type, 'base' => null, 'token' => null);
	$token = $missing ? null : getDatasetProperty(explode('/', $zdest, 2)[0], $zdest, 'receive_resume_token');
	if (is_string($token) && $token !== '' && $token !== '-') {
		// A run that stopped part-way left a token on the copy: zfs send -t carries on from there, whatever snapshot is picked now.
		$info = runProcess('zfs', array('send', '-P', '-v', '-n', '-t', $token));
		if (($info['code'] ?? 1) !== 0 || !preg_match('/^\s*toname = (.+?)\s*$/m', (string)$info['output'], $match)) {
			return $refuse($zdest.' holds a part-received replication that can\'t be resumed ('.zfsmOneLine($info['output'] ?? '').'); zfs receive -A '.$zdest.' discards it');
		}
		if (strncmp($match[1], $zdataset.'@', strlen($zdataset) + 1) !== 0) return $refuse($zdest.' holds a part-received replication of '.$match[1].', not of this dataset');
		if (!zfsmReplicaBookmarkFits($zdataset, $zdest, substr($match[1], strlen($zdataset) + 1))) return $refuse($too_long);
		return array_merge($plan, array('mode' => 'resume', 'snapshot' => $match[1], 'token' => $token, 'size' => zfsmReplicateSize($info['output'])));
	}
	$snap = $from === null ? array('name' => null, 'guid' => '', 'txg' => PHP_INT_MAX) : null;
	foreach ($marks as $mark) if ($from !== null && $mark['name'] === $from) $snap = $mark;
	if ($snap === null) return $refuse('The snapshot '.$from.' no longer exists');
	if (!zfsmReplicaBookmarkFits($zdataset, $zdest, $from !== null ? $snapshot : ($with['new_name'] ?? ''))) return $refuse($too_long);
	if (!$missing) {
		$held = zfsmReplicaMarks($zdest, 'snapshot');
		if ($held === null) return $refuse('The snapshots of '.$zdest.' could not be read');
		if (!$held) return $refuse($zdest.' already exists and holds no snapshot, so it can\'t be updated without destroying it');
		$newest = end($held);
		$base = null;
		// The snapshot or bookmark the copy's newest snapshot came from; a snapshot first, as older OpenZFS can't send raw from some bookmarks.
		foreach ($marks as $mark) if ($mark['guid'] === $newest['guid'] && ($base === null || strpos($mark['name'], '@') !== false)) $base = $mark;
		if ($base === null) return $refuse($newest['name'].' is not on this dataset, so '.$zdest.' can\'t be updated without destroying it');
		if ($base['guid'] === $snap['guid']) return $refuse($zdest.' already holds '.$from);
		if ($base['txg'] > $snap['txg']) return $refuse($zdest.' already holds '.$base['name'].', which is newer than '.$from);
		$plan = array_merge($plan, array('mode' => 'incremental', 'base' => $base['name'], 'since' => zfsmReplicaShortName($zdataset, $base['name'])));
	}
	return array_merge($plan, array('size' => $from === null || ($with['estimate'] ?? true) === false ? null : zfsmReplicateEstimate($plan)));
}

// What the Replicate dialog shows: the snapshots to pick from, newest first, the last destination, and the plan when a destination is given.
function zfsmReplicatePreview($zdataset, $snapshot, $zdest, $cfg) {
	if (!zfsmDatasetExists($zdataset)) return array('can_proceed' => false, 'error' => 'Only an existing dataset can be replicated');
	$marks = zfsmReplicaMarks($zdataset);
	if ($marks === null) return array('can_proceed' => false, 'error' => 'The dataset\'s snapshots could not be read');
	$snapshots = array();
	foreach (array_reverse($marks) as $mark) if (strpos($mark['name'], '@') !== false) $snapshots[] = substr($mark['name'], strlen($zdataset) + 1);
	$options = array('snapshots' => $snapshots, 'last_destination' => zfsmReplicaLastDestination($marks));
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file !== null && zfsmJobRunning($lock_file)) return array_merge($options, array('can_proceed' => false, 'error' => 'A replication of this dataset is already running'));
	if ($zdest === '') return array_merge($options, array('can_proceed' => false, 'error' => ''));
	// No snapshot picked means the run takes one first (zfsmStartReplicate).
	return array_merge($options, zfsmReplicatePlan($zdataset, $snapshot !== '' ? $snapshot : null, $zdest, array('marks' => $marks, 'new_name' => zfsmNewSnapshotName($cfg, time()))));
}

// Starts scripts/replicate.php in the background (zfsmStartJob). With no snapshot picked, the job takes one under its lock;
// a copy with a stopped run carries that run on instead of the snapshot picked.
function zfsmStartReplicate($zdataset, $snapshot, $zdest, $cfg) {
	$new_name = $snapshot === '' ? zfsmNewSnapshotName($cfg, time()) : '';
	$plan = zfsmReplicatePlan($zdataset, $snapshot !== '' ? $snapshot : null, $zdest, array('new_name' => $new_name, 'estimate' => false));
	if (!$plan['can_proceed']) return zfsmFailure($zdataset, $plan['error']);
	$resume = $plan['mode'] === 'resume';
	$args = $resume ? array($zdataset, substr($plan['snapshot'], strlen($zdataset) + 1), $zdest) : ($new_name !== '' ? array($zdataset, $new_name, $zdest, 'new') : array($zdataset, $snapshot, $zdest));
	list($state, $why) = zfsmStartJob('replicate.php', $args, zfsmJobLockFile($zdataset, 'replicate'));
	if ($state === 'running') return zfsmFailure($zdataset, 'A replication of this dataset is already running');
	if ($state !== 'started') return zfsmFailure($zdataset, 'The replication could not be started'.($why !== '' ? ': '.$why : ''));
	$said = $resume ? 'Replication of '.$plan['snapshot'].' to '.$zdest.' carries on from where it stopped; replicate again afterwards for newer snapshots' : 'Replication to '.$zdest.' started';
	return array('succeeded' => array($zdataset => $said.'; a notification says when it is done'), 'failed' => array());
}

// Runs zfs send into zfs receive, the stream passing straight between them; reports the bytes sent and keeps each side's first errors.
function zfsmReplicateStream($plan, $zdataset) {
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) return array('code' => 127, 'bytes' => 0, 'errors' => 'zfs must come from ZFSM_TEST_BIN_DIR in a test');
	$options = array('bypass_shell' => true);
	$send_pipes = $receive_pipes = array();
	$send = @proc_open(array_merge(array($zfs), zfsmReplicateSendArgs($plan)), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $send_pipes, null, null, $options);
	if (!is_resource($send)) return array('code' => -1, 'bytes' => 0, 'errors' => 'Unable to start zfs send');
	$receive = @proc_open(array_merge(array($zfs), zfsmReplicateReceiveArgs($plan)), array(0 => $send_pipes[1], 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $receive_pipes, null, null, $options);
	// Only the two zfs processes may hold the stream: a copy left here would keep receive waiting after send ends.
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
	// When one side fails the other fails with it: send on a broken pipe, receive on a short stream. The side that failed first says why,
	// in its first line: receive goes on with how to resume by hand, which the next run does by itself.
	$why = $receive_code !== 0 && ($send_code === 0 || stripos($errors['receive'], 'failed to read from stream') === false) ? $errors['receive'] : $errors['send'];
	return array('code' => $send_code !== 0 ? $send_code : $receive_code, 'bytes' => $bytes, 'errors' => trim(strtok(trim($why)."\n", "\n")));
}

// After a run: this destination's bookmark at the snapshot it now holds, and its older ones removed. Returns what went wrong, or ''.
function zfsmReplicaMark($zdataset, $plan) {
	$snapshot = substr($plan['snapshot'], strlen($zdataset) + 1);
	$prefix = $zdataset.'#'.zfsmReplicaBookmarkPrefix($plan['dest']);
	$result = runProcess('zfs', array('bookmark', $plan['snapshot'], $prefix.$snapshot));
	if (($result['code'] ?? 1) !== 0 && stripos((string)$result['output'], 'already exists') === false) return zfsmOneLine($result['output'] ?? '');
	$marks = zfsmReplicaMarks($zdataset, 'bookmark');
	foreach ($marks ?? array() as $mark) {
		if (strncmp($mark['name'], $prefix, strlen($prefix)) === 0 && $mark['name'] !== $prefix.$snapshot) runProcess('zfs', array('destroy', $mark['name']));
	}
	return '';
}

// The background run: one per dataset at a time, its progress on the row, a notification at the end, or saying why it didn't start.
// $take: the run takes $snapshot first, under its lock, and removes it again if the run can't go ahead.
// $started runs once the plan holds, before zfs send; until then the reason a run doesn't start also goes to the starting page.
function zfsmRunReplicate($zdataset, $snapshot, $zdest, $started = null, $take = false) {
	$title = 'ZFS Replication';
	$not_started = function($why) use ($zdataset, $zdest, $title, $started) {
		zfsmLog('Replication of '.$zdataset.' to '.$zdest.' not started: '.$why);
		zfsnotify($title, 'Replication of '.$zdataset.' to '.$zdest.' not started: '.zfsmSafeText($why), '', 'warning');
		if ($started !== null) fwrite(STDOUT, zfsmOneLine($why)."\n");
		return 1;
	};
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file === null) return $not_started('its guid could not be read');
	$lock = zfsmJobLock($lock_file);
	if ($lock === null) return $not_started('its lock file could not be opened');
	// Another run holds it: two starts at once leave one replication, and that one sends the notification.
	if ($lock === false) return 0;
	try {
		if ($take) {
			$taken = createDatasetSnapshot($zdataset, $snapshot, 0);
			if ($taken['failed']) return $not_started(implode('; ', $taken['failed']));
		}
		$plan = zfsmReplicatePlan($zdataset, $snapshot, $zdest, array('estimate' => false));
		if (!$plan['can_proceed']) {
			// Only the snapshot this run just took, which nothing has used.
			if ($take) runProcess('zfs', array('destroy', $zdataset.'@'.$snapshot));
			return $not_started($plan['error']);
		}
		if ($started !== null) $started();
		$plan['size'] = zfsmReplicateEstimate($plan);
		$what = $plan['snapshot'].' to '.$zdest;
		$how = $plan['mode'] === 'resume' ? ' (carried on from where it stopped)' : ($plan['base'] !== null ? ' (changes since '.zfsmReplicaShortName($zdataset, $plan['base']).')' : ' (in full)');
		zfsmLog('Replication of '.$what.$how.' started: zfs '.implode(' ', zfsmReplicateSendArgs($plan)).' | zfs '.implode(' ', zfsmReplicateReceiveArgs($plan)), LOG_NOTICE);
		zfsmCopyProgress('replicate_start', array('dataset' => $zdataset, 'total' => $plan['size']));
		try {
			$result = zfsmReplicateStream($plan, $zdataset);
		} finally {
			zfsmCopyProgress('replicate_stop', array('dataset' => $zdataset));
		}
		if ($result['code'] !== 0) {
			$why = rtrim($result['errors'] !== '' ? $result['errors'] : 'exit code '.$result['code'], '.');
			$resumable = getDatasetProperty(explode('/', $zdest, 2)[0], $zdest, 'receive_resume_token');
			$then = is_string($resumable) && $resumable !== '' && $resumable !== '-' ? '. Replicate it again to carry on from where it stopped.' : '.';
			zfsmLog('Replication of '.$what.$how.' failed: '.$why);
			zfsnotify($title, 'Replication of '.$what.' failed: '.zfsmSafeText($why).$then, '', 'warning');
			return 1;
		}
		$marked = zfsmReplicaMark($zdataset, $plan);
		$note = $marked === '' ? '' : ' The bookmark that keeps the next run incremental could not be made: '.$marked;
		zfsmLog('Replication of '.$what.' finished'.$how.'.'.$note, LOG_NOTICE);
		zfsnotify($title, 'Replication of '.$what.' finished'.$how.'.'.zfsmSafeText($note), '', $note === '' ? 'normal' : 'warning');
		return 0;
	} finally {
		zfsmJobUnlock($lock);
	}
}
