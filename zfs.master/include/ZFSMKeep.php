<?php
// Keep: after a schedule's run lands, its snapshots that its Keep (zfsmKeepRules) doesn't keep go, and a copy's here by its Copy Keeps;
// a run holds what it sends until a run to the copy lands or none is left to carry on.
// zfsmRunReplicate (ZFSMReplicate.php) prunes; zfsmReplicateSent holds and releases; zfsmServeReplica and zfsmScheduleSnapshotRun prune.
require_once __DIR__.'/ZFSMKeepRules.php';

define('ZFSM_KEEP_TAG', 'zfs.master:schedule');
// The tag on every snapshot a snapshot schedule takes: no copy's (16 hex), and one value for all, so a rename keeps counting them.
define('ZFSM_KEEP_SNAPSHOTS', 'snapshots');
define('ZFSM_KEEP_HOLD', 'zfsm-resume-');
// A run or receive removes at most this many of a dataset's snapshots, oldest first, and their twins in a copy here; the next
// one removes more.
define('ZFSM_KEEP_PER_RUN', 50);

// Why Keep removed a snapshot, as its log line says: $who keeps the others ("its schedule").
function zfsmKeepWhy($keep, $who) {
	return 'not among '.zfsmKeepWords($keep).' '.$who.' keeps';
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

// $zdataset's snapshots, oldest first, with when each was taken, held, cloned and Keep's tag; null when they can't be read. A row
// that doesn't parse is left out, so Keep neither removes nor counts it and keeps more, never fewer.
function zfsmKeepSnapshots($zdataset) {
	$by = zfsmKeepSnapshotsOf(array($zdataset));
	return is_string($by) ? null : $by[$zdataset];
}

// zfsmKeepSnapshots of each of $datasets, in one zfs list; why not when they can't be read.
function zfsmKeepSnapshotsOf($datasets) {
	$extra = array('creation', 'userrefs', 'clones', ZFSM_KEEP_TAG);
	$columns = implode(',', array_merge(array('name', 'guid', 'createtxg'), $extra));
	$listed = runProcess('zfs', array_merge(array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', $columns, '-s', 'createtxg'), $datasets));
	if (($listed['code'] ?? 1) !== 0) return zfsmCommandError($listed, 'zfs list failed');
	$lines = array();
	foreach (zfsmLines($listed['output']) as $line) $lines[(string)strstr($line, '@', true)][] = $line;
	$by = array();
	foreach ($datasets as $zdataset) $by[$zdataset] = array_values(array_filter(array_map('zfsmKeepRow', zfsmReplicaMarksIn($zdataset, implode("\n", $lines[$zdataset] ?? array()), $extra))));
	return $by;
}

function zfsmKeepRow($mark) {
	if (!preg_match('/^[0-9]+$/D', $mark['userrefs']) || !preg_match('/^[0-9]+$/D', $mark['creation'])) return null;
	return array('name' => $mark['name'], 'guid' => $mark['guid'], 'txg' => $mark['txg'], 'creation' => (int)$mark['creation'], 'held' => $mark['userrefs'] !== '0',
		'cloned' => $mark['clones'] !== '' && $mark['clones'] !== '-', 'tag' => $mark[ZFSM_KEEP_TAG]);
}

// The snapshots $tag marks ($tag null: every one) that $keep's rules don't keep (none without rules), oldest first, never one
// held or cloned; null when they can't be read. The caller takes ZFSM_KEEP_PER_RUN of those it can remove.
function zfsmKeepSurplus($zdataset, $keep, $tag) {
	if (!zfsmKeepRules($keep)) return array();
	$snapshots = zfsmKeepSnapshots($zdataset);
	return $snapshots === null ? null : zfsmKeepPast($snapshots, $keep, $tag);
}

// zfsmKeepSurplus of snapshots already read.
function zfsmKeepPast($snapshots, $keep, $tag) {
	$rules = zfsmKeepRules($keep);
	if (!$rules) return array();
	$counted = array_values(array_filter($snapshots, function($snapshot) use ($tag) { return $tag === null || $snapshot['tag'] === $tag; }));
	$kept = zfsmKeepKept(array_reverse($counted), $rules);
	return array_values(array_filter($counted, function($snapshot) use ($kept) { return !isset($kept[$snapshot['name']]) && !$snapshot['held'] && !$snapshot['cloned']; }));
}

// The names (keys) of $newest, snapshots newest first (by txg), that $rules keep: any one rule keeps it. Each period, named by when
// a snapshot was taken, keeps the first of it met.
function zfsmKeepKept($newest, $rules) {
	$kept = array_fill_keys(array_column(array_slice($newest, 0, $rules[''] ?? 0), 'name'), true);
	foreach (array_diff_key($rules, array('' => 0)) as $unit => $count) {
		$periods = array();
		foreach ($newest as $snapshot) {
			$period = date(ZFSM_KEEP_PERIODS[$unit][0], $snapshot['creation']);
			if (isset($periods[$period])) continue;
			if (count($periods) === $count) break;
			$periods[$period] = $kept[$snapshot['name']] = true;
		}
	}
	return $kept;
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

// After a run to $plan's copy landed: Keep (zfsmKeepPruneSource), then Copy Keeps (zfsmKeepCopyPrune), each on its own. '' or what
// stayed and why.
function zfsmKeepPrune($zdataset, $plan, $keep, $tag, $copy_keeps = null) {
	return implode('. ', array_filter(array(zfsmKeepPruneSource($zdataset, $plan, $keep, $tag, $copy_keeps === null), zfsmKeepCopyPrune($plan, $copy_keeps)), 'strlen'));
}

// $zdataset's snapshots $tag marks that $keep doesn't keep go; with $twins, a copy's here first (by guid), and a source stays while
// its copy's is held, cloned or fails to go: Keep finds those only through it. '' or what stayed and why.
function zfsmKeepPruneSource($zdataset, $plan, $keep, $tag, $twins) {
	$surplus = zfsmKeepSurplus($zdataset, $keep, $tag);
	if ($surplus === null) return 'Keep could not read the snapshots of '.$zdataset;
	$copies = $surplus && $twins ? zfsmKeepCopies($plan) : array();
	if ($copies === null) return 'Keep could not read the snapshots of '.$plan['dest'];
	// Not the one this run sent (the next one sends the changes since), nor one whose twin on the copy is held or cloned.
	$removable = array_filter($surplus, function($snapshot) use ($plan, $copies) {
		$copy = $copies[$snapshot['guid']] ?? null;
		return $snapshot['name'] !== $plan['snapshot'] && ($copy === null || (!$copy['held'] && !$copy['cloned']));
	});
	return zfsmKeepStayed('Keep', zfsmKeepRemove($removable, $copies, zfsmKeepWhy($keep, 'its schedule')), 'run');
}

// Copy Keeps ($copy_keeps; null: none, 0: all) of a copy here, as Keep Replicas keeps on a server that receives copies. '' or what
// stayed and why.
function zfsmKeepCopyPrune($plan, $copy_keeps) {
	// Never a copy on a server: a dataset here can have its name.
	if ($copy_keeps === null || $plan['server'] !== '') return '';
	// Not the one this run sent, whatever is newer there: the next run sends the changes since.
	return zfsmKeepEvery($plan['dest'], $copy_keeps, 'Copy Keeps', 'run', $plan['dest'].strstr($plan['snapshot'], '@'));
}

// $zdataset's snapshots $keep doesn't keep go, every one counting, but $spare (a snapshot's name); $who keeps them. '' or what stayed
// and why.
function zfsmKeepEvery($zdataset, $keep, $who, $then, $spare = '') {
	$surplus = zfsmKeepSurplus($zdataset, $keep, null);
	if ($surplus === null) return $who.' could not read the snapshots of '.$zdataset;
	$removable = array_filter($surplus, function($snapshot) use ($spare) { return $snapshot['name'] !== $spare; });
	return zfsmKeepStayed($who, zfsmKeepRemove($removable, array(), zfsmKeepWhy($keep, $who)), $then);
}

// Destroys the first ZFSM_KEEP_PER_RUN of $removable, each one's twin in $copies (by guid) first: a source stays while its twin
// does. Each that stayed, and why.
function zfsmKeepRemove($removable, $copies, $why) {
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
	return $stayed;
}

// The log and notifications keep 500 characters of a line: the first few of $items, and how many more.
function zfsmKeepFirst($items, $join = '; ') {
	return implode($join, array_slice($items, 0, 3)).(count($items) > 3 ? $join.'and '.(count($items) - 3).' more' : '');
}

// What $stayed (each a snapshot and why) says in a log line or notification, '' when none; the next $then tries again.
function zfsmKeepStayed($who, $stayed, $then) {
	return $stayed ? $who.' could not remove '.zfsmKeepFirst($stayed).'; the next '.$then.' tries again' : '';
}

// After a receive into $zdataset here landed: its snapshots $keep doesn't keep (Settings, Keep Replicas; 0 keeps all) go, every
// one counting. A failure is logged and notified on this server; the receive's answer stays as it was.
function zfsmKeepReplica($from, $zdataset, $keep) {
	$kept = zfsmKeepEvery($zdataset, $keep, 'Keep Replicas', 'receive');
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
		$why = zfsmKeepHoldOne($hold, $snapshot);
		if ($why !== '') $failed[] = $snapshot.' ('.$why.')';
	}
	return implode('; ', $failed);
}

// Puts hold $hold on $snapshot: '' when it is on, as it already was if zfs's closing diagnostic says the tag exists; else why not.
function zfsmKeepHoldOne($hold, $snapshot) {
	$held = runProcess('zfs', array('hold', $hold, $snapshot));
	return ($held['code'] ?? 1) === 0 || preg_match('/: tag already exists on this dataset\s*\z/', (string)($held['output'] ?? '')) === 1 ? '' : zfsmCommandError($held, 'zfs hold failed');
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

// After a run ends ($code 0: it landed), its Keep ($keep null: a run that is no schedule's) and Copy Keeps, logged and added to its
// notification's $text and $type.
function zfsmKeepAfter($code, $zdataset, $plan, $keep, $tag, $to, $text, $type, $copy_keeps = null) {
	$kept = $code !== 0 || $keep === null ? '' : zfsmKeepPrune($zdataset, $plan, $keep, $tag, $copy_keeps);
	if ($kept === '') return array($text, $type);
	zfsmLog('Replication of '.$zdataset.' to '.$to.': '.$kept);
	return array(rtrim($text, '.').'. '.zfsmSafeText($kept).'.', 'warning');
}
