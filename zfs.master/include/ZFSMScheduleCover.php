<?php
// What a schedule covers: the datasets and snapshot names zfs lists (zfsmScheduleCoverRead), and why a run leaves a dataset out.

define('ZFSM_SCHEDULE_COVERED', array('list', '-H', '-o', 'name,readonly,mountpoint', '-t', 'filesystem,volume'));
// Names alone, sorted by name: zfs then lists snapshots without reading their properties, fast with thousands of them.
define('ZFSM_SCHEDULE_SNAPSHOTS', array('list', '-H', '-o', 'name', '-s', 'name', '-t', 'snapshot'));
// Each read of zfsmScheduleCoverRead for Save, the dialog and a run stops here: a hung pool would hold the request, or the run and its
// lock, for good.
define('ZFSM_SCHEDULE_COUNT_WAIT', 10);
define('ZFSM_SCHEDULE_COVER_WAIT', 30);

// What a schedule covers, as one answer for zfsmScheduleListed: the datasets, their snapshots' names (both read with $more), the names
// read's exit code, and $cfg's Snapshots Prefix and a new snapshot's name. $read runs as zfsmReadWithin does.
function zfsmScheduleCoverRead($cfg, $wait, $more = array(), $read = 'zfsmReadWithin') {
	$listed = $read($wait, 'zfs', array_merge(ZFSM_SCHEDULE_COVERED, $more)) + array('prefix' => (string)($cfg['snap_prefix'] ?? ''), 'name' => zfsmNewSnapshotName($cfg, time()));
	// None listed (a hung pool waits its whole limit): no dataset for the names to mark, so no second wait.
	if (!zfsmScheduleListed($listed)[0]) return $listed;
	// Names it can't read mark none: a snapshot schedule still takes those datasets, copies leave them out (zfsmScheduleCopiesNot).
	$names = $read($wait, 'zfs', array_merge(ZFSM_SCHEDULE_SNAPSHOTS, $more));
	return array('code' => $listed['code'] !== 0 ? $listed['code'] : $names['code'], 'output' => $listed['output']."\n".$names['output'], 'names' => $names['code']) + $listed;
}

// $listed (zfsmScheduleCoverRead) as array(readonly, mountpoint, has Sanoid's or Syncoid's snapshots: null when its names weren't read)
// by dataset, and the lines that don't parse. With a Snapshots Prefix that starts as theirs do, a name that starts with it is ours.
function zfsmScheduleListed($listed) {
	$rows = $errors = $automatic = array();
	$own = zfsmIsAutomaticSnapshot('@'.($listed['prefix'] ?? '')) ? '@'.$listed['prefix'] : '';
	foreach (zfsmLines($listed['output'] ?? '') as $line) {
		$parts = explode("\t", $line, 3);
		$of = (string)strstr($line, '@', true);
		if (count($parts) === 3 && zfsmValidDatasetName($parts[0])) $rows[$parts[0]] = array($parts[1], $parts[2]);
		elseif (count($parts) === 1 && zfsmValidDatasetName($of)) $automatic[$of] = ($automatic[$of] ?? false) || zfsmScheduleTheirs($line, $of, $own);
		else $errors[] = $line;
	}
	$unknown = zfsmScheduleNamesUnread($listed) ? null : false;
	foreach ($rows as $zdataset => $row) $rows[$zdataset][] = $automatic[$zdataset] ?? $unknown;
	// Both reads fail a dataset they can't open: say so once.
	return array($rows, array_values(array_unique($errors)));
}

// Whether $listed (zfsmScheduleCoverRead) read datasets but not their snapshots' names: only a read that exited 0 did, and one that
// timed out has a null code.
function zfsmScheduleNamesUnread($listed) {
	return array_key_exists('names', $listed) && $listed['names'] !== 0;
}

// Whether snapshot $line of $of is Sanoid's or Syncoid's: not ours, under a Snapshots Prefix that starts as theirs do ($own).
function zfsmScheduleTheirs($line, $of, $own) {
	return zfsmIsAutomaticSnapshot($line) && ($own === '' || strpos($line, $of.$own) !== 0);
}

// The warning for $zdataset, listed as $row (zfsmScheduleListed), when no run takes it: what it is, what that does, and its section
// of the wiki's Warnings page; null when one can.
function zfsmScheduleSkippedWarning($zdataset, $row) {
	$impact = 'Scheduled snapshots and copies skip it';
	if ($row[0] === 'on') return array('fact' => $zdataset.' is Read Only', 'impact' => $impact, 'guide' => 'read-only');
	// Syncoid sends every snapshot since its last by default (-I): ours would pile up in its copies, where Sanoid removes only its own.
	if ($row[2]) return array('fact' => $zdataset.' has Sanoid or Syncoid snapshots', 'impact' => $impact, 'guide' => 'sanoid-or-syncoid-snapshots');
	return $row[1] === 'legacy' ? array('fact' => $zdataset.' is mounted legacy', 'impact' => $impact, 'guide' => 'mounted-legacy') : null;
}

// A zfsmScheduleSkippedWarning as one line; '' for none.
function zfsmScheduleSkippedText($warning) {
	return $warning === null ? '' : $warning['fact'].'. '.$warning['impact'];
}

// Why a run leaves out $zdataset when its snapshot names could not be read (zfsmScheduleListed's null).
function zfsmScheduleNamesUnreadText($zdataset) {
	return 'the snapshot names of '.$zdataset.' could not be read, so it may be left to Sanoid or Syncoid';
}

// Why no run takes $zdataset, listed as $row, as one line; '' when one can.
function zfsmScheduleSkipped($zdataset, $row) {
	return zfsmScheduleSkippedText(zfsmScheduleSkippedWarning($zdataset, $row));
}

// Why no run of $job takes $zdataset, listed as $row: zfsmScheduleSkipped's reasons, and for copies under a dataset
// zfsmScheduleCopiesNot's; '' when one can.
function zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $name) {
	$why = zfsmScheduleSkipped($zdataset, $row);
	return $why === '' && zfsmScheduleCopiesUnder($job) ? zfsmScheduleCopiesNot($job, $jobs, $zdataset, $row, $name) : $why;
}

// Whether a run of $job takes $zdataset, listed as $row: it follows $job in $jobs, and nothing leaves it out (zfsmScheduleLeftOut).
function zfsmScheduleTakes($job, $jobs, $zdataset, $row, $name) {
	return zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $name) === '' && (zfsmScheduleFollowed($zdataset, $jobs, zfsmScheduleKindOf($job))['id'] ?? null) === $job['id'];
}

// From $listed: by pool, each dataset that follows $job (zfsmScheduleFollowed of its kind in $jobs) and a run can take
// (zfsmScheduleLeftOut); and why some weren't read ('' when all were).
function zfsmScheduleCovered($listed, $job, $jobs) {
	list($rows, $errors) = zfsmScheduleListed($listed);
	$pools = array();
	foreach ($rows as $zdataset => $row) {
		if (zfsmScheduleTakes($job, $jobs, $zdataset, $row, $listed['name'])) $pools[explode('/', $zdataset)[0]][] = $zdataset;
	}
	// zfs lists what it can read, and fails for the rest: a dataset's own minds errors naming none, its datasets or one above.
	if (($listed['code'] ?? 1) === 0) return array($pools, '');
	$mine = array_filter($errors, function($line) use ($job) {
		return $job['dataset'] === '' || !preg_match("/'([^']+)'/", $line, $name) || zfsmUnderAny($name[1], array($job['dataset'])) || zfsmUnderAny($job['dataset'], array($name[1]));
	});
	if ($errors && !$mine) return array($pools, '');
	return array($pools, zfsmScheduleUnreadWhy($mine, $listed));
}

// Why $listed failed: its first $errors, else its exit code (the rest of its output is datasets and names).
function zfsmScheduleUnreadWhy($errors, $listed) {
	return $errors ? zfsmKeepFirst($errors) : zfsmCommandError(array('code' => $listed['code'] ?? 1), 'zfs list failed');
}

// Why no run takes $zdataset, from $listed: gone, not read, or as zfsmScheduleSkipped says; '' when one can.
function zfsmScheduleSnapshotNot($zdataset, $listed) {
	list($rows, $errors) = zfsmScheduleListed($listed);
	$row = $rows[$zdataset] ?? null;
	if ($row === null) return ($listed['code'] ?? 1) === 0 ? $zdataset.' is gone' : $zdataset.' could not be read: '.zfsmScheduleUnreadWhy($errors, $listed);
	return zfsmScheduleSkipped($zdataset, $row);
}

// What stops a repeat set in Replicate from taking $zdataset, from a read of it: 'warning' when it is left out (zfsmScheduleSkippedWarning),
// else 'unread', what could not be read; both null when it can, or when it is gone, which the replication says.
function zfsmScheduleRepeatRead($zdataset, $cfg, $wait) {
	$listed = zfsmScheduleCoverRead($cfg, $wait, array('-d', '1', $zdataset));
	list($rows, $errors) = zfsmScheduleListed($listed);
	$row = $rows[$zdataset] ?? null;
	$gone = ($listed['code'] ?? 1) === 0 || zfsmDatasetMissingSaid(implode("\n", $errors));
	if ($row === null) return array('warning' => null, 'unread' => $gone ? null : zfsmScheduleSnapshotNot($zdataset, $listed));
	$warning = zfsmScheduleSkippedWarning($zdataset, $row);
	return array('warning' => $warning, 'unread' => $warning === null && $row[2] === null ? zfsmScheduleNamesUnreadText($zdataset) : null);
}
