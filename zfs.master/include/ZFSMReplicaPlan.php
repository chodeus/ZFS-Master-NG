<?php
// Replication's decisions: what a destination holds, what a run would send, and the zfs send and receive for it. The run: ZFSMReplicate.php.

// What a destination already holds is marked on the source by a bookmark for that destination, so the next run stays incremental after the snapshot is gone.
// The destination is hex in the name, because dataset names may hold every character a bookmark name can; one on another server is <server>@<dataset>.
function zfsmReplicaBookmarkPrefix($zdest, $server = '') {
	return 'zfsm_'.bin2hex($server === '' ? $zdest : $server.'@'.$zdest).'_';
}

// The dataset's snapshots (and bookmarks), oldest first, with their guid and createtxg; null when they can't be read.
function zfsmReplicaMarks($zdataset, $types = 'snapshot,bookmark') {
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', $types, '-o', 'name,guid,createtxg', '-s', 'createtxg', $zdataset));
	return ($result['code'] ?? 1) === 0 ? zfsmReplicaMarksRead($zdataset, $result['output']) : null;
}

// zfs list's name, guid and createtxg lines, the dataset's own only.
function zfsmReplicaMarksRead($zdataset, $output) {
	$marks = array();
	foreach (preg_split('/\R/', trim((string)$output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
		$parts = explode("\t", $line);
		if (count($parts) !== 3 || !preg_match('/^[0-9]+$/D', $parts[1]) || !preg_match('/^[0-9]+$/D', $parts[2])) continue;
		$own = strncmp($parts[0], $zdataset.'@', strlen($zdataset) + 1) === 0 || strncmp($parts[0], $zdataset.'#', strlen($zdataset) + 1) === 0;
		// guids are 64-bit, past PHP's integers: compared as text.
		if ($own) $marks[] = array('name' => $parts[0], 'guid' => $parts[1], 'txg' => (int)$parts[2]);
	}
	return $marks;
}

// The newest destination this dataset was replicated to, from its newest replication bookmark: its server ('' for this one) and dataset.
function zfsmReplicaLastDestination($marks) {
	foreach (array_reverse($marks) as $mark) {
		if (!preg_match('/#zfsm_((?:[0-9a-f]{2})+)_/', $mark['name'], $match)) continue;
		// Only a name that decodes to a dataset is one of these bookmarks; another tool's zfsm_ab_daily isn't.
		$named = explode('@', hex2bin($match[1]), 2);
		$where = count($named) === 2 ? array('server' => $named[0], 'dest' => $named[1]) : array('server' => '', 'dest' => $named[0]);
		if (zfsmValidDatasetName($where['dest']) && ($where['server'] === '' || zfsmReplicaSenderName($where['server']))) return $where;
	}
	return array('server' => '', 'dest' => '');
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

// -s: a stopped run resumes. -u and canmount=noauto keep a filesystem's copy unmounted, even after a reboot; volmode=none keeps a volume's
// copy from being a second block device of the same data. Resumed and incremental receives apply them too.
function zfsmReplicateReceiveArgs($plan) {
	$args = array('receive', '-s', '-u', '-o', 'readonly=on');
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

function zfsmReplicaBookmarkFits($zdataset, $plan, $snapshot) {
	return strlen($zdataset.'#'.zfsmReplicaBookmarkPrefix($plan['dest'], $plan['server']).$snapshot) <= 255;
}

// What a run from $snapshot to $zdest would do, or why it can't: mode 'full', 'incremental' (from 'base') or 'resume' (from 'token'), with an estimated size.
// A null $snapshot is one the run takes first, named $with['new_name']: newer than all the others, its size unknown until then.
// $with['marks'] passes the dataset's marks already read; $with['estimate'] false skips the zfs send -n; $with['target'] is where the copy lives
// (zfsmReplicaTargetOpen), this server when not given.
function zfsmReplicatePlan($zdataset, $snapshot, $zdest, $with = array()) {
	$refuse = function($why) { return array('can_proceed' => false, 'error' => $why); };
	$too_long = 'The names are too long for the bookmark that keeps the next replication incremental';
	$target = $with['target'] ?? array('server' => '');
	if (isset($target['error'])) return $refuse($target['error']);
	$where = function($name) use ($target) { return zfsmReplicaWhere($name, $target['server']); };
	if (!isset($with['marks']) && !zfsmDatasetExists($zdataset)) return $refuse('Only an existing dataset can be replicated');
	$from = $snapshot === null ? null : $zdataset.'@'.$snapshot;
	if ($from !== null && !zfsmValidSnapshotName($from)) return $refuse('Pick one of the dataset\'s snapshots');
	$why = zfsmReplicaTargetDestError($target, $zdataset, $zdest);
	if ($why !== '') return $refuse($why);
	$marks = $with['marks'] ?? zfsmReplicaMarks($zdataset);
	if ($marks === null) return $refuse('The dataset\'s snapshots could not be read');
	$pool = explode('/', $zdataset, 2)[0];
	$encryption = getDatasetProperty($pool, $zdataset, 'encryption');
	$type = getDatasetProperty($pool, $zdataset, 'type');
	if (!is_string($encryption) || !in_array($type, array('filesystem', 'volume'), true)) return $refuse('The dataset\'s properties could not be read');
	$parent = substr($zdest, 0, strrpos($zdest, '/'));
	$parent_type = zfsmReplicaTargetType($target, $parent);
	if ($parent_type === null) return $refuse('Could not check whether '.$where($parent).' exists');
	if ($parent_type !== 'filesystem') return $refuse('The copy goes inside '.$where($parent).', which doesn\'t exist');
	$dest_type = zfsmReplicaTargetType($target, $zdest);
	if ($dest_type === null) return $refuse('Could not check whether '.$where($zdest).' exists');
	$missing = $dest_type === '';
	$plan = array('can_proceed' => true, 'mode' => 'full', 'snapshot' => $from, 'dest' => $zdest, 'server' => $target['server'], 'raw' => $encryption !== 'off', 'type' => $type, 'base' => null, 'token' => null);
	$token = $missing ? '' : zfsmReplicaTargetToken($target, $zdest);
	if ($token === null) return $refuse('Could not check whether '.$where($zdest).' holds a part-received replication');
	if ($token !== '') {
		// A run that stopped part-way left a token on the copy: zfs send -t carries on from there, whatever snapshot is picked now.
		$info = runProcess('zfs', array('send', '-P', '-v', '-n', '-t', $token));
		if (($info['code'] ?? 1) !== 0 || !preg_match('/^\s*toname = (.+?)\s*$/m', (string)$info['output'], $match)) {
			return $refuse($where($zdest).' holds a part-received replication that can\'t be resumed ('.zfsmOneLine($info['output'] ?? '').'); zfs receive -A '.$zdest.' discards it');
		}
		if (strncmp($match[1], $zdataset.'@', strlen($zdataset) + 1) !== 0) return $refuse($where($zdest).' holds a part-received replication of '.$match[1].', not of this dataset');
		if (!zfsmReplicaBookmarkFits($zdataset, $plan, substr($match[1], strlen($zdataset) + 1))) return $refuse($too_long);
		return array_merge($plan, array('mode' => 'resume', 'snapshot' => $match[1], 'token' => $token, 'size' => zfsmReplicateSize($info['output'])));
	}
	$snap = $from === null ? array('name' => null, 'guid' => '', 'txg' => PHP_INT_MAX) : null;
	foreach ($marks as $mark) if ($from !== null && $mark['name'] === $from) $snap = $mark;
	if ($snap === null) return $refuse('The snapshot '.$from.' no longer exists');
	if (!zfsmReplicaBookmarkFits($zdataset, $plan, $from !== null ? $snapshot : ($with['new_name'] ?? ''))) return $refuse($too_long);
	if (!$missing) {
		$held = zfsmReplicaTargetSnapshots($target, $zdest);
		if ($held === null) return $refuse('The snapshots of '.$where($zdest).' could not be read');
		if (!$held) return $refuse($where($zdest).' already exists and holds no snapshot, so it can\'t be updated without destroying it');
		$newest = end($held);
		$base = null;
		// The snapshot or bookmark the copy's newest snapshot came from; a snapshot first, as older OpenZFS can't send raw from some bookmarks.
		foreach ($marks as $mark) if ($mark['guid'] === $newest['guid'] && ($base === null || strpos($mark['name'], '@') !== false)) $base = $mark;
		if ($base === null) return $refuse($newest['name'].' is not on this dataset, so '.$where($zdest).' can\'t be updated without destroying it');
		if ($base['guid'] === $snap['guid']) return $refuse($where($zdest).' already holds '.$from);
		if ($base['txg'] > $snap['txg']) return $refuse($where($zdest).' already holds '.$base['name'].', which is newer than '.$from);
		$plan = array_merge($plan, array('mode' => 'incremental', 'base' => $base['name'], 'since' => zfsmReplicaShortName($zdataset, $base['name'])));
	}
	return array_merge($plan, array('size' => $from === null || ($with['estimate'] ?? true) === false ? null : zfsmReplicateEstimate($plan)));
}

// What the Replicate dialog shows: the snapshots to pick from, newest first, the trusted servers, the last destination, and the plan when a
// destination is given. A trusted server with no name given yet plans <its area>/<the dataset's last part>, which 'dest' gives back.
function zfsmReplicatePreview($zdataset, $snapshot, $zdest, $cfg, $server = '') {
	if (!zfsmDatasetExists($zdataset)) return array('can_proceed' => false, 'error' => 'Only an existing dataset can be replicated');
	$marks = zfsmReplicaMarks($zdataset);
	if ($marks === null) return array('can_proceed' => false, 'error' => 'The dataset\'s snapshots could not be read');
	$snapshots = array();
	foreach (array_reverse($marks) as $mark) if (strpos($mark['name'], '@') !== false) $snapshots[] = substr($mark['name'], strlen($zdataset) + 1);
	$last = zfsmReplicaLastDestination($marks);
	$options = array('snapshots' => $snapshots, 'servers' => array_column(zfsmReplicaRemotes(), 'name'), 'last_server' => $last['server'], 'last_destination' => $last['dest']);
	$lock_file = zfsmJobLockFile($zdataset, 'replicate');
	if ($lock_file !== null && zfsmJobRunning($lock_file)) return array_merge($options, array('can_proceed' => false, 'error' => 'A replication of this dataset is already running'));
	if ($zdest === '' && $server === '') return array_merge($options, array('can_proceed' => false, 'error' => ''));
	$target = zfsmReplicaTargetOpen($server);
	try {
		if ($zdest === '' && isset($target['area'])) $zdest = $target['area'].'/'.basename($zdataset);
		// No snapshot picked means the run takes one first (zfsmStartReplicate).
		$with = array('marks' => $marks, 'new_name' => zfsmNewSnapshotName($cfg, time()), 'target' => $target);
		return array_merge($options, array('dest' => $zdest), zfsmReplicatePlan($zdataset, $snapshot !== '' ? $snapshot : null, $zdest, $with));
	} finally {
		zfsmReplicaTargetClose($target);
	}
}
