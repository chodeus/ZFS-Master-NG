<?php
// Keep: after a schedule's run lands, its snapshots past the newest N go; a run that stops part-way holds what the next one carries on from.
// zfsmRunReplicate (ZFSMReplicate.php) calls both.

define('ZFSM_KEEP_TAG', 'zfs.master:schedule');
define('ZFSM_KEEP_MAX', 9999);

function zfsmKeepValid($keep) {
	return is_int($keep) && $keep >= 0 && $keep <= ZFSM_KEEP_MAX;
}

// A copy's tag on its schedule's snapshots and in its holds: 16 hex of the copy's mark as the schedule names it, known before a
// server is asked. No name shows in diagnostics, and a schedule added again for the copy finds its snapshots.
function zfsmKeepTag($server, $zdest) {
	return substr(hash('sha256', zfsmReplicaTargetMark(array('server' => $server), $zdest)), 0, 16);
}

function zfsmKeepHoldTag($tag) {
	return 'zfsm-resume-'.$tag;
}

// $zdataset's snapshots, oldest first, with guid, createtxg, held, cloned and Keep's tag; null when they can't be read. A row that
// doesn't parse is left out, so Keep neither removes nor counts it and keeps more, never fewer.
function zfsmKeepSnapshots($zdataset) {
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg,userrefs,clones,'.ZFSM_KEEP_TAG, '-s', 'createtxg', $zdataset));
	if (($result['code'] ?? 1) !== 0) return null;
	$snapshots = array();
	foreach (zfsmLines($result['output']) as $line) {
		$parts = explode("\t", $line, 6);
		if (count($parts) !== 6 || strncmp($parts[0], $zdataset.'@', strlen($zdataset) + 1) !== 0 || !preg_match('/^[0-9]+\t[0-9]+\t[0-9]+$/D', $parts[1]."\t".$parts[2]."\t".$parts[3])) continue;
		$snapshots[] = array('name' => $parts[0], 'guid' => $parts[1], 'txg' => (int)$parts[2], 'held' => $parts[3] !== '0', 'cloned' => $parts[4] !== '' && $parts[4] !== '-', 'tag' => $parts[5]);
	}
	return $snapshots;
}

// The snapshots $tag marks past the newest $keep of them (0: none), oldest first, never one held or cloned; null when they can't be read.
function zfsmKeepSurplus($zdataset, $keep, $tag) {
	if ($keep <= 0) return array();
	$snapshots = zfsmKeepSnapshots($zdataset);
	if ($snapshots === null) return null;
	$counted = array_values(array_filter($snapshots, function($snapshot) use ($tag) { return $snapshot['tag'] === $tag; }));
	$past = array_slice($counted, 0, max(0, count($counted) - $keep));
	return array_values(array_filter($past, function($snapshot) { return !$snapshot['held'] && !$snapshot['cloned']; }));
}

// Destroys $snapshot only while it is still the one read ($guid), and logs it with $why: '' when it is gone, else why it stays.
function zfsmKeepDestroy($snapshot, $guid, $why) {
	if (!zfsmValidSnapshotName($snapshot)) return 'it is no snapshot';
	$now = runProcess('zfs', array('get', '-H', '-p', '-o', 'value', 'guid', $snapshot));
	if (($now['code'] ?? 1) !== 0) return zfsmDatasetMissingSaid($now['output'] ?? '') ? '' : zfsmCommandError($now, 'its guid could not be read');
	if (trim((string)$now['output']) !== $guid) return 'another snapshot has its name now';
	$destroyed = runProcess('zfs', array('destroy', $snapshot));
	if (($destroyed['code'] ?? 1) !== 0) return zfsmCommandError($destroyed, 'zfs destroy failed');
	zfsmLog('Removed '.$snapshot.': '.$why, LOG_NOTICE);
	return '';
}

// After a run to $plan's copy landed: $zdataset's snapshots $tag marks, past the newest $keep, go; from a copy on this server the
// same snapshots (by guid) go first, and a source snapshot stays when removing its copy's fails. '' or what stayed and why.
function zfsmKeepPrune($zdataset, $plan, $keep, $tag) {
	$surplus = zfsmKeepSurplus($zdataset, $keep, $tag);
	if ($surplus === null) return 'Keep could not read the snapshots of '.$zdataset;
	// A copy on another server keeps what that server decides.
	$copies = array();
	if ($surplus && $plan['server'] === '') {
		$listed = zfsmKeepSnapshots($plan['dest']);
		if ($listed === null) return 'Keep could not read the snapshots of '.$plan['dest'];
		foreach ($listed as $copy) $copies[$copy['guid']] = $copy;
	}
	$why = 'past the newest '.$keep.' its schedule keeps';
	$stayed = array();
	foreach ($surplus as $snapshot) {
		// The one this run sent, which the next one sends the changes since.
		if ($snapshot['name'] === $plan['snapshot']) continue;
		$copy = $copies[$snapshot['guid']] ?? null;
		// One held or cloned on the copy is its owner's to keep.
		$failed = $copy === null || $copy['held'] || $copy['cloned'] ? '' : zfsmKeepDestroy($copy['name'], $copy['guid'], $why);
		if ($failed !== '') {
			$stayed[] = $copy['name'].' ('.$failed.'), so '.$snapshot['name'].' stays too';
			continue;
		}
		$failed = zfsmKeepDestroy($snapshot['name'], $snapshot['guid'], $why);
		if ($failed !== '') $stayed[] = $snapshot['name'].' ('.$failed.')';
	}
	// The log and notifications keep 500 characters of a line: the first few, and how many more.
	$more = count($stayed) > 3 ? '; and '.(count($stayed) - 3).' more' : '';
	return $stayed ? 'Keep could not remove '.implode('; ', array_slice($stayed, 0, 3)).$more.'; the next run tries again' : '';
}

// A run that stopped part-way, or may have: its snapshot and the snapshot it sent the changes since are held for the copy, so
// nothing removes them before a run carries it on (zfsmKeepRelease). One the copy's hold is on already counts. '' or why not.
function zfsmKeepHold($plan, $tag) {
	$hold = zfsmKeepHoldTag($tag);
	$failed = array();
	foreach (array($plan['snapshot'], $plan['base']) as $snapshot) {
		if ($snapshot === null || strpos($snapshot, '@') === false) continue;
		$held = runProcess('zfs', array('hold', $hold, $snapshot));
		if (($held['code'] ?? 1) !== 0 && strpos((string)($held['output'] ?? ''), 'tag already exists') === false) $failed[] = $snapshot.': '.zfsmCommandError($held, 'zfs hold failed');
	}
	return implode('; ', $failed);
}

// After a run to the copy landed: the copy's holds (zfsmKeepHold) come off every snapshot of $zdataset. '' or why not.
function zfsmKeepRelease($zdataset, $tag) {
	$hold = zfsmKeepHoldTag($tag);
	$snapshots = zfsmKeepSnapshots($zdataset);
	if ($snapshots === null) return 'the snapshots of '.$zdataset.' could not be read';
	$held = array_column(array_filter($snapshots, function($snapshot) { return $snapshot['held']; }), 'name');
	if (!$held) return '';
	$holds = runProcess('zfs', array_merge(array('holds', '-H'), $held));
	if (($holds['code'] ?? 1) !== 0) return zfsmCommandError($holds, 'zfs holds failed');
	$failed = array();
	foreach (zfsmLines($holds['output']) as $line) {
		$parts = explode("\t", $line, 3);
		if (count($parts) !== 3 || $parts[1] !== $hold || !in_array($parts[0], $held, true)) continue;
		$released = runProcess('zfs', array('release', $hold, $parts[0]));
		if (($released['code'] ?? 1) !== 0) $failed[] = $parts[0].': '.zfsmCommandError($released, 'zfs release failed');
	}
	return implode('; ', $failed);
}
