<?php
// Keep: after a schedule's run lands, its snapshots past the newest N go; a run holds what it sends until a run to the copy lands
// or none is left to carry on.
// zfsmRunReplicate (ZFSMReplicate.php) prunes; zfsmReplicateSent holds and releases; zfsmServeReplica prunes what it received.

define('ZFSM_KEEP_TAG', 'zfs.master:schedule');
define('ZFSM_KEEP_MAX', 9999);
define('ZFSM_KEEP_HOLD', 'zfsm-resume-');
// A run or receive removes at most this many of a dataset's snapshots, oldest first, and their twins in a copy here; the next
// one removes more.
define('ZFSM_KEEP_PER_RUN', 50);

function zfsmKeepValid($keep) {
	return is_int($keep) && $keep >= 0 && $keep <= ZFSM_KEEP_MAX;
}

// A copy's tag on its schedule's snapshots and in its holds: 16 hex of the copy's mark as the schedule names it, known before a
// server is asked. No name shows in diagnostics, and a schedule added again for the copy finds its snapshots.
function zfsmKeepTag($server, $zdest) {
	return substr(hash('sha256', zfsmReplicaTargetMark(array('server' => $server), $zdest)), 0, 16);
}

// The properties a run's snapshot is taken with: a schedule's run ($keep not null) tags it for the copy.
function zfsmKeepProps($keep, $tag) {
	return $keep === null ? array() : array(ZFSM_KEEP_TAG => $tag);
}

function zfsmKeepHoldTag($tag) {
	return ZFSM_KEEP_HOLD.$tag;
}

// $zdataset's snapshots (zfsmReplicaMarks), oldest first, with held, cloned and Keep's tag; null when they can't be read. A row
// that doesn't parse is left out, so Keep neither removes nor counts it and keeps more, never fewer.
function zfsmKeepSnapshots($zdataset) {
	$marks = zfsmReplicaMarks($zdataset, 'snapshot', array('userrefs', 'clones', ZFSM_KEEP_TAG));
	if ($marks === null) return null;
	$snapshots = array();
	foreach ($marks as $mark) {
		if (!preg_match('/^[0-9]+$/D', $mark['userrefs'])) continue;
		$snapshots[] = array('name' => $mark['name'], 'guid' => $mark['guid'], 'txg' => $mark['txg'], 'held' => $mark['userrefs'] !== '0',
			'cloned' => $mark['clones'] !== '' && $mark['clones'] !== '-', 'tag' => $mark[ZFSM_KEEP_TAG]);
	}
	return $snapshots;
}

// The snapshots $tag marks ($tag null: every one) past the newest $keep of them (0: none), oldest first, never one held or
// cloned; null when they can't be read. The caller takes ZFSM_KEEP_PER_RUN of those it can remove.
function zfsmKeepSurplus($zdataset, $keep, $tag) {
	if ($keep <= 0) return array();
	$snapshots = zfsmKeepSnapshots($zdataset);
	if ($snapshots === null) return null;
	$counted = array_values(array_filter($snapshots, function($snapshot) use ($tag) { return $tag === null || $snapshot['tag'] === $tag; }));
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
	zfsmLog('Removed '.$snapshot.' ('.$why.')', LOG_NOTICE);
	return '';
}

// The snapshots of $plan's copy by guid; none for a copy on another server, which keeps what that server decides; null when
// they can't be read.
function zfsmKeepCopies($plan) {
	if ($plan['server'] !== '') return array();
	$listed = zfsmKeepSnapshots($plan['dest']);
	return $listed === null ? null : array_column($listed, null, 'guid');
}

// After a run to $plan's copy landed: $zdataset's snapshots $tag marks past the newest $keep go, a copy's here first (by guid). A
// source stays while its copy's is held, cloned or fails to go: Keep finds those only through it. '' or what stayed and why.
function zfsmKeepPrune($zdataset, $plan, $keep, $tag) {
	$surplus = zfsmKeepSurplus($zdataset, $keep, $tag);
	if ($surplus === null) return 'Keep could not read the snapshots of '.$zdataset;
	$copies = $surplus ? zfsmKeepCopies($plan) : array();
	if ($copies === null) return 'Keep could not read the snapshots of '.$plan['dest'];
	// Not the one this run sent (the next one sends the changes since), nor one whose twin on the copy is held or cloned.
	$removable = array_filter($surplus, function($snapshot) use ($plan, $copies) {
		$copy = $copies[$snapshot['guid']] ?? null;
		return $snapshot['name'] !== $plan['snapshot'] && ($copy === null || (!$copy['held'] && !$copy['cloned']));
	});
	$why = 'past the newest '.$keep.' its schedule keeps';
	$stayed = array();
	foreach (array_slice($removable, 0, ZFSM_KEEP_PER_RUN) as $snapshot) {
		$copy = $copies[$snapshot['guid']] ?? null;
		$failed = $copy === null ? '' : zfsmKeepDestroy($copy['name'], $copy['guid'], $why);
		if ($failed !== '') {
			$stayed[] = $copy['name'].' ('.$failed.'), so its source stays too: '.$snapshot['name'];
			continue;
		}
		$failed = zfsmKeepDestroy($snapshot['name'], $snapshot['guid'], $why);
		if ($failed !== '') $stayed[] = $snapshot['name'].' ('.$failed.')';
	}
	return zfsmKeepStayed('Keep', $stayed, 'run');
}

// What $stayed (each a snapshot and why) says in a log line or notification, '' when none; the next $then tries again.
function zfsmKeepStayed($who, $stayed, $then) {
	// The log and notifications keep 500 characters of a line: the first few, and how many more.
	$more = count($stayed) > 3 ? '; and '.(count($stayed) - 3).' more' : '';
	return $stayed ? $who.' could not remove '.implode('; ', array_slice($stayed, 0, 3)).$more.'; the next '.$then.' tries again' : '';
}

// After a receive into $zdataset here landed: its snapshots past the newest $keep (Settings, Keep Replicas; 0 keeps all) go, every
// one counting. A failure is logged and notified on this server; the receive's answer stays as it was.
function zfsmKeepReplica($from, $zdataset, $keep) {
	$surplus = zfsmKeepSurplus($zdataset, $keep, null);
	$stayed = array();
	foreach (array_slice($surplus ?? array(), 0, ZFSM_KEEP_PER_RUN) as $snapshot) {
		$failed = zfsmKeepDestroy($snapshot['name'], $snapshot['guid'], 'past the newest '.$keep.' Keep Replicas keeps');
		if ($failed !== '') $stayed[] = $snapshot['name'].' ('.$failed.')';
	}
	$kept = $surplus === null ? 'Keep Replicas could not read the snapshots of '.$zdataset : zfsmKeepStayed('Keep Replicas', $stayed, 'receive');
	if ($kept === '') return;
	zfsmLog('Replication from '.$from.' into '.$zdataset.' received: '.$kept, LOG_WARNING);
	zfsnotify('ZFS Replication', 'Replication from '.$from.' into '.$zdataset.' received. '.zfsmSafeText($kept).'.', '', 'warning');
}

// Before a send: its snapshot and the snapshot it sends the changes since are held for the copy, so nothing removes them before a
// run carries on one that stops (zfsmKeepRelease). One the copy's hold is on already counts. '' or why not.
function zfsmKeepHold($plan, $tag) {
	$hold = zfsmKeepHoldTag($tag);
	$failed = array();
	foreach (array($plan['snapshot'], $plan['base']) as $snapshot) {
		if ($snapshot === null || strpos($snapshot, '@') === false) continue;
		$held = runProcess('zfs', array('hold', $hold, $snapshot));
		if (($held['code'] ?? 1) !== 0 && strpos((string)($held['output'] ?? ''), 'tag already exists') === false) $failed[] = $snapshot.' ('.zfsmCommandError($held, 'zfs hold failed').')';
	}
	return implode('; ', $failed);
}

// The holds on $snapshots (zfs holds -H) as array(snapshot, tag) pairs; a string when they can't be read.
function zfsmKeepHolds($snapshots) {
	$holds = runProcess('zfs', array_merge(array('holds', '-H'), $snapshots));
	if (($holds['code'] ?? 1) !== 0) return zfsmCommandError($holds, 'zfs holds failed');
	$pairs = array();
	foreach (zfsmLines($holds['output']) as $line) {
		$parts = explode("\t", $line, 3);
		if (count($parts) === 3 && in_array($parts[0], $snapshots, true)) $pairs[] = array($parts[0], $parts[1]);
	}
	return $pairs;
}

// After a run to the copy landed, or stopped with nothing to carry on: the copy's holds (zfsmKeepHold) come off every snapshot of
// $zdataset. '' or why not.
function zfsmKeepRelease($zdataset, $tag) {
	$hold = zfsmKeepHoldTag($tag);
	$snapshots = zfsmKeepSnapshots($zdataset);
	if ($snapshots === null) return 'the snapshots of '.$zdataset.' could not be read';
	$held = array_column(array_filter($snapshots, function($snapshot) { return $snapshot['held']; }), 'name');
	$holds = $held ? zfsmKeepHolds($held) : array();
	if (is_string($holds)) return $holds;
	$failed = array();
	foreach ($holds as list($snapshot, $on)) {
		if ($on !== $hold) continue;
		$released = runProcess('zfs', array('release', $hold, $snapshot));
		if (($released['code'] ?? 1) !== 0) $failed[] = $snapshot.' ('.zfsmCommandError($released, 'zfs release failed').')';
	}
	return implode('; ', $failed);
}

function zfsmKeepReleaseNote($released) {
	return $released === '' ? '' : 'A hold for this copy may still be on its snapshots, so they stay until a run that lands takes it off: '.$released;
}

// A run that stopped: what it carries on from stays held ($held: zfsmKeepHold's answer); with nothing to carry on ($token ''), the
// copy's holds come off. '' or what its log line and notification add.
function zfsmKeepStopped($zdataset, $tag, $token, $held) {
	$unheld = $held === '' ? '' : 'What it carries on from could not be held, so a Keep or a removal by hand can take it first: '.$held;
	$note = $token === '' ? zfsmKeepReleaseNote(zfsmKeepRelease($zdataset, $tag)) : $unheld;
	return $note === '' ? '' : ' '.$note.'.';
}

// After a run ends ($code 0: it landed), its Keep ($keep null: a run that is no schedule's), logged and added to its notification's
// $text and $type.
function zfsmKeepAfter($code, $zdataset, $plan, $keep, $tag, $to, $text, $type) {
	$kept = $code !== 0 || $keep === null ? '' : zfsmKeepPrune($zdataset, $plan, $keep, $tag);
	if ($kept === '') return array($text, $type);
	zfsmLog('Replication of '.$zdataset.' to '.$to.': '.$kept);
	return array(rtrim($text, '.').'. '.zfsmSafeText($kept).'.', 'warning');
}
