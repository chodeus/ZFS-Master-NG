<?php
// Replication's decisions: what a destination holds, what a run would send, and the zfs send and receive for it. The run: ZFSMReplicate.php.

// What a destination already holds is marked on the source by a bookmark for that destination, so the next run stays incremental after the snapshot is gone.
// The destination (zfsmReplicaTargetMark) is hex in the name, because dataset names may hold every character a bookmark name can.
function zfsmReplicaBookmarkPrefix($mark) {
	return 'zfsm_'.bin2hex($mark).'_';
}

// The dataset's snapshots (and bookmarks), oldest first, with their guid, createtxg and the $extra properties by name; null when
// they can't be read.
function zfsmReplicaMarks($zdataset, $types = 'snapshot,bookmark', $extra = array()) {
	$columns = implode(',', array_merge(array('name', 'guid', 'createtxg'), $extra));
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', $types, '-o', $columns, '-s', 'createtxg', $zdataset));
	return ($result['code'] ?? 1) === 0 ? zfsmReplicaMarksIn($zdataset, $result['output'], $extra) : null;
}

// That list's lines, as this server's zfs or the checker's (zfsmReplicaTargetSnapshots) prints them.
function zfsmReplicaMarksIn($zdataset, $output, $extra = array()) {
	$marks = array();
	$count = 3 + count($extra);
	foreach (preg_split('/\R/', trim((string)$output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
		$parts = explode("\t", $line, $count);
		if (count($parts) !== $count || !preg_match('/^[0-9]+$/D', $parts[1]) || !preg_match('/^[0-9]+$/D', $parts[2])) continue;
		$own = strncmp($parts[0], $zdataset.'@', strlen($zdataset) + 1) === 0 || strncmp($parts[0], $zdataset.'#', strlen($zdataset) + 1) === 0;
		// guids are 64-bit, past PHP's integers: compared as text.
		if ($own) $marks[] = array('name' => $parts[0], 'guid' => $parts[1], 'txg' => (int)$parts[2]) + ($extra ? array_combine($extra, array_slice($parts, 3)) : array());
	}
	return $marks;
}

// The newest destination this dataset was replicated to, from its newest replication bookmark: array('server' => '' for this one,
// 'dest'); null when none.
function zfsmReplicaLastDestination($marks) {
	foreach (array_reverse($marks) as $mark) {
		if (!preg_match('/#zfsm_((?:[0-9a-f]{2})+)_/', $mark['name'], $match)) continue;
		$named = explode('@', hex2bin($match[1]));
		$last = count($named) === 2 ? array('server' => $named[0], 'dest' => $named[1]) : array('server' => '', 'dest' => $named[0]);
		// Only a name that decodes to a dataset (on a server) is one of these bookmarks; another tool's zfsm_ab_daily isn't.
		if (count($named) <= 2 && zfsmValidDatasetName($last['dest']) && ($last['server'] === '' || zfsmReplicaSenderName($last['server']))) return $last;
	}
	return null;
}

// A snapshot or bookmark as a notification names it: the snapshot's own name, also for this plugin's bookmarks.
function zfsmReplicaShortName($zdataset, $mark) {
	if (preg_match('/#zfsm_(?:[0-9a-f]{2})+_(.+)$/', $mark, $match)) return $match[1];
	return substr($mark, strlen($zdataset) + 1);
}

// The send for a plan: progress on stderr (-v -P), raw for an encrypted dataset, incremental from the plan's base, or resumed from its token.
// Records over 128K go whole (-L; raw keeps them too). Keep it on: a copy holding one refuses a later stream without -L.
function zfsmReplicateSendArgs($plan, $dry_run = false) {
	$args = array_merge(array('send', '-P', '-v'), $dry_run ? array('-n') : array());
	if ($plan['mode'] === 'resume') return array_merge($args, array('-t', $plan['token']));
	if ($plan['raw']) $args[] = '-w';
	else $args[] = '-L';
	if ($plan['base'] !== null) array_push($args, '-i', $plan['base']);
	$args[] = $plan['snapshot'];
	return $args;
}

// -s: a stopped run resumes. -u and canmount=noauto keep a filesystem's copy unmounted, even after a reboot; volmode=none keeps a volume's
// copy from being a second block device of the same data. Resumed and incremental receives apply them too. -F only onto a holder.
function zfsmReplicateReceiveArgs($plan) {
	$args = array('receive', '-s', '-u', '-o', 'readonly=on');
	if ($plan['holder']) $args[] = '-F';
	array_push($args, '-o', $plan['type'] === 'filesystem' ? 'canmount=noauto' : 'volmode=none');
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

define('ZFSM_REPLICA_TOO_LONG', 'The names are too long for the bookmark that keeps the next replication incremental');

function zfsmReplicaBookmarkFits($zdataset, $mark, $snapshot) {
	return strlen($zdataset.'#'.zfsmReplicaBookmarkPrefix($mark).$snapshot) <= 255;
}

// What a run from $snapshot to $zdest would do, or why it can't: mode 'full', 'incremental' (from 'base') or 'resume' (from 'token'), with an estimated size.
// A null $snapshot is one the run takes first, named $with['new_name']: newer than all the others, its size unknown until then.
// $with['marks'] passes the dataset's marks already read; $with['estimate'] false skips the zfs send -n; $with['target'] is where the copy lives (zfsmReplicaTargetDest).
function zfsmReplicatePlan($zdataset, $snapshot, $zdest, $with = array()) {
	$refuse = function($why) { return array('can_proceed' => false, 'error' => $why); };
	$target = $with['target'] ?? array('server' => '');
	if (isset($target['error'])) return $refuse($target['error']);
	if (!isset($with['marks']) && !zfsmDatasetExists($zdataset)) return $refuse('Only an existing dataset can be replicated');
	$from = $snapshot === null ? null : $zdataset.'@'.$snapshot;
	if ($from !== null && !zfsmValidSnapshotName($from)) return $refuse('Pick one of the dataset\'s snapshots');
	list($named, $why) = zfsmReplicaTargetDest($target, $zdataset, $zdest);
	if ($named === null) return $refuse($why);
	$zdest = $named;
	$marks = $with['marks'] ?? zfsmReplicaMarks($zdataset);
	if ($marks === null) return $refuse('The dataset\'s snapshots could not be read');
	$pool = explode('/', $zdataset, 2)[0];
	$encryption = getDatasetProperty($pool, $zdataset, 'encryption');
	$type = getDatasetProperty($pool, $zdataset, 'type');
	if (!is_string($encryption) || !in_array($type, array('filesystem', 'volume'), true)) return $refuse('The dataset\'s properties could not be read');
	$where = zfsmReplicaTargetWhere($target, $zdest);
	$because = function($why) { return $why !== '' ? ': '.$why : ''; };
	list($missing, $why) = zfsmReplicaTargetMissing($target, $zdest);
	if ($missing === null) return $refuse('Could not check whether '.$where.' exists'.$because($why));
	$key = zfsmReplicaTargetMark($target, $zdest);
	$plan = array('can_proceed' => true, 'mode' => 'full', 'snapshot' => $from, 'dest' => $zdest, 'server' => $target['server'], 'mark' => $key, 'raw' => $encryption !== 'off', 'type' => $type, 'base' => null, 'token' => null, 'holder' => false);
	list($token, $why) = $missing ? array('', '') : zfsmReplicaTargetToken($target, $zdest);
	if ($token === null) return $refuse('Could not check whether '.$where.' holds a part-received replication'.$because($why));
	if ($token !== '') {
		$plan = zfsmReplicaPlanResume($plan, $target, $zdataset, $token);
		return is_string($plan) ? $refuse($plan) : $plan;
	}
	$snap = $from === null ? array('name' => null, 'guid' => '', 'txg' => PHP_INT_MAX) : null;
	foreach ($marks as $mark) if ($from !== null && $mark['name'] === $from) $snap = $mark;
	if ($snap === null) return $refuse('The snapshot '.$from.' no longer exists');
	$untaken = $from === null ? zfsmNewSnapshotNameWhy($with['new_name'] ?? '') : '';
	if ($untaken !== '') return $refuse($untaken);
	if (!zfsmReplicaBookmarkFits($zdataset, $key, $from !== null ? $snapshot : ($with['new_name'] ?? ''))) return $refuse(ZFSM_REPLICA_TOO_LONG);
	if (!$missing) $plan = zfsmReplicaPlanOnto($plan, $target, $zdataset, $marks, $snap);
	if (is_string($plan)) return $refuse($plan);
	// A bookmark for this copy means a run made it before: a schedule's full send there, or onto a holder made there since, would
	// refill what someone removed.
	if (($missing || $plan['holder']) && ($with['scheduled'] ?? false)) {
		$prefix = $zdataset.'#'.zfsmReplicaBookmarkPrefix($key);
		$now = $missing ? ' is gone' : ' only holds the copies of datasets below this one';
		foreach ($marks as $mark) if (strncmp($mark['name'], $prefix, strlen($prefix)) === 0) return $refuse($where.$now.', though this dataset made a copy there before; a scheduled run sends only changes, so replicate it once by hand to make a new copy');
	}
	return array_merge($plan, array('size' => $from === null || ($with['estimate'] ?? true) === false ? null : zfsmReplicateEstimate($plan)));
}

// $plan carrying on the run that stopped part-way and left $token on the copy (zfs send -t), whatever snapshot is picked now; onto
// a holder with -F again, which zfs asks for there. Else why not.
function zfsmReplicaPlanResume($plan, $target, $zdataset, $token) {
	$zdest = $plan['dest'];
	$where = zfsmReplicaTargetWhere($target, $zdest);
	$info = runProcess('zfs', array('send', '-P', '-v', '-n', '-t', $token));
	$snapshot = ($info['code'] ?? 1) === 0 ? zfsmReplicaResumeSnapshot($info['output']) : null;
	if ($snapshot === null) {
		return $where.' holds a part-received replication that can\'t be resumed ('.zfsmOneLine($info['output'] ?? '').'); zfs receive -A on that dataset discards it';
	}
	if (strncmp($snapshot, $zdataset.'@', strlen($zdataset) + 1) !== 0) return $where.' holds a part-received replication of '.$snapshot.', not of this dataset';
	if (!zfsmReplicaBookmarkFits($zdataset, $plan['mark'], substr($snapshot, strlen($zdataset) + 1))) return ZFSM_REPLICA_TOO_LONG;
	$holder = false;
	if (zfsmReplicaHolder($target, $zdest)) {
		list($held, $why) = zfsmReplicaTargetSnapshots($target, $zdest);
		if ($held === null) return zfsmReplicaUnread($where, $why);
		$holder = $held === array();
		$mounted = $holder ? zfsmReplicaHolderMounted($zdest) : '';
		if ($mounted !== '') return $where.' only holds the copies of datasets below this one, and '.$mounted;
	}
	return array_merge($plan, array('mode' => 'resume', 'snapshot' => $snapshot, 'token' => $token, 'size' => zfsmReplicateSize($info['output']), 'holder' => $holder));
}

// The snapshot zfs send -P -v -n -t $token sends, by the name zfs finds it under by guid; the token's toname keeps the one it had
// before a rename. Null when $output names none.
function zfsmReplicaResumeSnapshot($output) {
	return preg_match('/^(?:full|incremental\t[^\t\n]+)\t([^\t\n]+)\t[0-9]+\s*$/m', (string)$output, $match) ? $match[1] : null;
}

// $plan into a copy that is there: the changes since the snapshot or bookmark its newest snapshot came from, else in full onto a holder
// (zfsmReplicaPlanHolder); or why not. $snap: the mark it sends.
function zfsmReplicaPlanOnto($plan, $target, $zdataset, $marks, $snap) {
	$where = zfsmReplicaTargetWhere($target, $plan['dest']);
	list($held, $why) = zfsmReplicaTargetSnapshots($target, $plan['dest']);
	if ($held === null) return zfsmReplicaUnread($where, $why);
	if (!$held) return zfsmReplicaPlanHolder($plan, $target);
	$newest = end($held);
	$base = null;
	// The snapshot or bookmark the copy's newest snapshot came from; a snapshot first, as older OpenZFS can't send raw from some bookmarks.
	foreach ($marks as $mark) if ($mark['guid'] === $newest['guid'] && ($base === null || strpos($mark['name'], '@') !== false)) $base = $mark;
	if ($base === null) return $newest['name'].' is not on this dataset, so '.$where.' can\'t be updated without destroying it';
	if ($base['guid'] === $snap['guid']) return $where.' already holds '.$snap['name'];
	if ($base['txg'] > $snap['txg']) return $where.' already holds '.$base['name'].', which is newer than '.$snap['name'];
	return array_merge($plan, array('mode' => 'incremental', 'base' => $base['name'], 'since' => zfsmReplicaShortName($zdataset, $base['name'])));
}

// Why the snapshots of the copy at $where (zfsmReplicaTargetSnapshots) could not be read.
function zfsmReplicaUnread($where, $why) {
	return 'The snapshots of '.$where.' could not be read'.($why !== '' ? ': '.$why : '');
}

// $plan into a copy that holds no snapshot: in full with -F when it is a holder, which keeps the copies below it; zfs
// refuses a raw stream there, and any stream onto an encrypted holder. Else why not.
function zfsmReplicaPlanHolder($plan, $target) {
	$where = zfsmReplicaTargetWhere($target, $plan['dest']);
	if (!zfsmReplicaHolder($target, $plan['dest'])) return $where.' already exists and holds no snapshot, so it can\'t be updated without destroying it';
	$held = $where.' only holds the copies of datasets below this one, and ';
	$fix = ': copy it elsewhere instead, or, as a copy destroyed loses the snapshots only it had, move aside (zfs rename) each copy below it you want to keep, such as one of a dataset since destroyed, then destroy that dataset and the rest below it for the next run to send them all (one a repeat set in Replicate made asks for one Replicate by hand)';
	if ($plan['raw']) return $held.'zfs cannot receive the raw copy of this encrypted dataset over it'.$fix;
	$crypt = getDatasetProperty('', $plan['dest'], 'encryption');
	if ($crypt === null) return $held.'whether it is encrypted could not be read';
	if ($crypt !== 'off') return $held.'zfs cannot receive a copy over it, as it is encrypted'.$fix;
	$mounted = zfsmReplicaHolderMounted($plan['dest']);
	return $mounted === '' ? array_merge($plan, array('holder' => true)) : $held.$mounted;
}

// What the Replicate dialog shows: the snapshots to pick from, newest first, the last destination, the trusted servers, and the plan
// when a destination is given; for a trusted server ($server) also its area, or why it can't be reached.
function zfsmReplicatePreview($zdataset, $snapshot, $zdest, $cfg, $server = '') {
	if (!zfsmDatasetExists($zdataset)) return array('can_proceed' => false, 'error' => 'Only an existing dataset can be replicated');
	$marks = zfsmReplicaMarks($zdataset);
	if ($marks === null) return array('can_proceed' => false, 'error' => 'The dataset\'s snapshots could not be read');
	$snapshots = array();
	foreach (array_reverse($marks) as $mark) if (strpos($mark['name'], '@') !== false) $snapshots[] = substr($mark['name'], strlen($zdataset) + 1);
	$remotes = zfsmReplicaRemotes();
	$options = array('snapshots' => $snapshots, 'last_destination' => zfsmReplicaLastDestination($marks), 'servers' => array_map('strval', array_keys($remotes ?? array())), 'server' => $server, 'area' => null);
	if ($remotes === null) $options['servers_error'] = zfsmReplicaRemotesUnreadable();
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file !== null && zfsmJobRunning($lock_file)) return array_merge($options, array('can_proceed' => false, 'error' => 'A replication of this dataset is already running'));
	if ($zdest === '' && $server === '') return array_merge($options, array('can_proceed' => false, 'error' => ''));
	$target = zfsmReplicaTargetOpen($server);
	try {
		$options['area'] = $target['area'] ?? null;
		if ($zdest === '' && !isset($target['error'])) return array_merge($options, array('can_proceed' => false, 'error' => ''));
		// No snapshot picked means the run takes one first (zfsmStartReplicate).
		return array_merge($options, zfsmReplicatePlan($zdataset, $snapshot !== '' ? $snapshot : null, $zdest, array('marks' => $marks, 'new_name' => zfsmNewSnapshotName($cfg, time()), 'target' => $target)));
	} finally {
		zfsmReplicaDisconnect($target);
	}
}
