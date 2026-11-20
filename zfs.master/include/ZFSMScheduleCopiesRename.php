<?php
// A dataset rename's side of copies under a dataset: its checks (ZFSMOperations.php), and what its copies do after it.

// Why renaming $from to $to ($names: it and those below it) would leave a copy a schedule makes of one no room for the bookmark it
// keeps (zfsmReplicaBookmarkFits), where there was; '' when none.
function zfsmScheduleCopiesFit($names, $from, $to, $cfg) {
	$read = zfsmScheduleRead();
	// The rename's follow-up says so.
	if ($read === null) return '';
	$snapshot = zfsmNewSnapshotName($cfg, time());
	// One a run leaves out (zfsmScheduleSkipped), there and once moved, needs no room; one whose row could not be read is checked. Read
	// once a name is too long.
	$rows = $moving = null;
	$skipped = function($zdataset, $name) use (&$rows, &$moving, $cfg, $from, $to) {
		if ($rows === null) {
			$rows = zfsmScheduleListed(zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT, array('-r', $from)))[0];
			$moving = zfsmScheduleCopiesMoving($from, $to);
		}
		if (!isset($rows[$name])) return false;
		return zfsmScheduleSkipped($zdataset, $zdataset === $name ? $rows[$name] : zfsmScheduleCopiesMovedRow($rows[$name], $name, $from, ...$moving)) !== '';
	};
	$fits = function($zdataset, $jobs, $name) use ($snapshot, $skipped) {
		$job = zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder');
		return $job === null || !zfsmScheduleRepeats($job['every']) || zfsmScheduleCopiesInPool($job, $zdataset)
			|| zfsmReplicaBookmarkFits($zdataset, zfsmReplicaTargetMark(array('server' => $job['server']), zfsmScheduleCopiesDest($job, $zdataset)), $snapshot)
			|| $skipped($zdataset, $name);
	};
	$after = zfsmScheduleOwnMoved($read['valid'], $from, $to);
	foreach ($names as $name) {
		$renamed = $to.substr($name, strlen($from));
		if (!$fits($renamed, $after, $name) && $fits($name, $read['valid'], $name)) return 'the names of '.$renamed.' and its copy would be too long for the bookmark a copy keeps; pick a shorter name';
	}
	return '';
}

// The row (zfsmScheduleListed) of $to's parent, and where readonly and mountpoint of $from and each below it come from, by name; each
// null when not read.
function zfsmScheduleCopiesMoving($from, $to) {
	$parent = substr($to, 0, (int)strrpos($to, '/'));
	$listed = runProcess('zfs', array_merge(ZFSM_SCHEDULE_COVERED, array($parent)));
	$row = ($listed['code'] ?? 1) === 0 ? zfsmScheduleListed(array('output' => $listed['output']))[0][$parent] ?? null : null;
	$read = runProcess('zfs', array('get', '-H', '-o', 'name,property,source', '-t', 'filesystem,volume', '-r', 'readonly,mountpoint', $from));
	if (($read['code'] ?? 1) !== 0) return array($row, null);
	$sources = array();
	foreach (zfsmLines($read['output']) as $line) {
		list($name, $property, $source) = explode("\t", $line, 3) + array('', '', '');
		$sources[$name][$property === 'readonly' ? 0 : 1] = $source;
	}
	return array($row, $sources);
}

// $row of $name, at or below $from, once moved under $parent (zfsmScheduleCopiesMoving): a value it inherits from above $from, or
// has by default, takes $parent's; one whose source or parent was not read gives no reason to leave it out.
function zfsmScheduleCopiesMovedRow($row, $name, $from, $parent, $sources) {
	foreach (array(0, 1) as $i) {
		$source = $sources[$name][$i] ?? null;
		$above = $source === 'default' || (strpos((string)$source, 'inherited from ') === 0 && !zfsmUnderAny(substr($source, 15), array($from)));
		if ($source === null || $above) $row[$i] = $source === null ? '' : ($parent[$i] ?? '');
	}
	return $row;
}

// The replication locks (zfsmJobLock, no command started holds them) of $zdataset and each dataset below it a run can copy, by
// name, and the names of all of them; why not when one is held or can't be had.
function zfsmScheduleCopiesHold($zdataset) {
	$listed = runProcess('zfs', array('list', '-H', '-o', 'name,guid,mountpoint', '-t', 'filesystem,volume', '-r', $zdataset));
	if (($listed['code'] ?? 1) !== 0) return 'the datasets at and below it could not be read: '.zfsmCommandError($listed, 'zfs list failed');
	$read = zfsmScheduleRead();
	$repeats = array();
	foreach ($read === null ? array() : $read['valid'] as $job) if (!zfsmScheduleCovers($job)) $repeats[$job['dataset']] = true;
	$locks = $names = array();
	foreach (zfsmLines($listed['output']) as $line) {
		list($name, $guid, $mountpoint) = explode("\t", $line, 3) + array('', '', '');
		$names[] = $name;
		$file = zfsmJobGuidLock($guid, 'replicate');
		// Docker's legacy-mounted image layers, often thousands, which Copy Under skips: a lock each held could run out of open files, so
		// one no repeat copies is only checked, where a run made its lock.
		$check = $mountpoint === 'legacy' && !isset($repeats[$name]);
		if ($check && ($file === null || !file_exists($file))) continue;
		$lock = $file === null ? null : zfsmJobLock($file, false);
		if (!is_resource($lock)) {
			array_map('zfsmJobUnlock', $locks);
			return $lock === false ? 'a replication of '.$name.' is running' : 'the replication lock of '.$name.' could not be opened';
		}
		if ($check) zfsmJobUnlock($lock);
		else $locks[$name] = $lock;
	}
	return array($locks, $names);
}

// After zfs renamed $from to $to and its own schedules followed ($before, $after, which repeats that follow a copy change): its copy
// under each dataset that copies go under takes $to's name there (zfsmScheduleCopiesMoveOne). Each with a problem, with what.
function zfsmScheduleCopiesMove($from, $to, $before, &$after, $cfg) {
	$stayed = zfsmScheduleCopiesServerStays($from, $to, $before);
	$here = array_filter($before, function($job) { return zfsmScheduleCopiesUnder($job) && $job['server'] === ''; });
	foreach (array_column($here, null, 'dest') as $dest => $job) {
		$named = false;
		$old = zfsmScheduleCopiesDest($job, $from);
		$why = $dest === '/' ? '' : zfsmScheduleCopiesMoveOne($job, $from, $to, $after, $cfg, $named);
		$still = $why !== '' && array_filter($after, function($other) use ($to, $old) { return zfsmScheduleCopiesFollows($other, $to, $old); });
		$repeats = $named ? zfsmScheduleCopiesRepeatsFollow($to, $old, zfsmScheduleCopiesDest($job, $to), $after) : ($still ? 'the repeats set in Replicate that send into it still do' : '');
		$why = implode('; ', array_filter(array($why, $repeats), 'strlen'));
		if ($why !== '') $stayed[$old] = $why;
	}
	return $stayed;
}

// $from's copy on each server whose copies it or one below it follows ($before): no checker there renames one, so each keeps its name,
// said as zfsmScheduleCopiesMove says each. A stopped run's holds move to the copies due now, whose run takes them off once it lands.
function zfsmScheduleCopiesServerStays($from, $to, $before) {
	$followed = zfsmScheduleFollowed($from, $before, 'zfsmScheduleCopiesUnder')['id'] ?? null;
	$stayed = array();
	foreach ($before as $job) {
		$covers = $job['id'] === $followed || zfsmUnderAny($job['dataset'], array($from));
		if (!zfsmScheduleCopiesUnder($job) || $job['server'] === '' || $job['every'] === 'none' || !$covers) continue;
		$old = zfsmScheduleCopiesDest($job, $from);
		$held = zfsmScheduleCopiesHolds($old, zfsmScheduleCopiesDest($job, $to), $to, $job['server']);
		$stayed[zfsmReplicaTargetWhere(array('server' => $job['server']), $old)] = 'any copy there keeps its name, with those below it, as no copy on another server is renamed; a run that copies '
			.$to.' there sends it in full'.($held !== '' ? ', and a stopped run\'s holds on its snapshots move to that copy, but '.$held : '');
	}
	return $stayed;
}

// Whether $job, a repeat set in Replicate of $to or below, sends into the copy at $old or below (one to a server names no path here).
function zfsmScheduleCopiesFollows($job, $to, $old) {
	return !zfsmScheduleCovers($job) && zfsmUnderAny($job['dataset'], array($to)) && zfsmUnderAny($job['dest'], array($old));
}

// Once $to's copy is due at $new, not $old: each repeat that follows it (zfsmScheduleCopiesFollows) sends there, in $after too once
// saved; what failed, '' for none.
function zfsmScheduleCopiesRepeatsFollow($to, $old, $new, &$after) {
	$follow = function($jobs) use ($to, $old, $new) {
		foreach ($jobs as $id => $job) if (zfsmScheduleCopiesFollows($job, $to, $old)) $jobs[$id]['dest'] = $new.substr($job['dest'], strlen($old));
		return $jobs;
	};
	$read = zfsmScheduleRead();
	if ($read !== null && $follow($read['valid']) === $read['valid']) return '';
	$changed = zfsmScheduleChange($follow);
	if ($changed['saved']) $after = $follow($after);
	if ($changed['why'] === '') return '';
	return $changed['saved'] ? 'the repeats set in Replicate that send into it send to '.$new.' now, but '.$changed['why']
		: 'the repeats set in Replicate that send into it still send to '.$old.' ('.$changed['why'].'): set them again under Actions, Replicate';
}

// The copy of $from under $job's dest, with its bookmarks, renamed to $to's when it is $to's or a holder and $after still copies there;
// '' when it moved or is not there (or not read where none copies it), else what went wrong. $named: whether $to's copy is due there now.
function zfsmScheduleCopiesMoveOne($job, $from, $to, $after, $cfg, &$named) {
	$old = zfsmScheduleCopiesDest($job, $from);
	$new = zfsmScheduleCopiesDest($job, $to);
	$missing = zfsmDatasetMissing($old);
	if (!zfsmScheduleCopiesKept($to, $job, $after)) return $missing === false ? 'left where it is, as no schedule copies the renamed dataset or one below it there' : '';
	// zfs says the same of a copy in a pool not imported now.
	$pool = $missing === true ? zfsmDatasetMissing(strtok($old, '/')) : false;
	if ($pool !== false) return 'not checked, as '.($pool ? 'its pool is not imported now' : 'whether its pool is imported could not be read').': if it is there, it keeps its old name, and the next copy of the renamed dataset is a full one';
	$named = $missing === true;
	if ($named) return '';
	if ($missing === null) return 'not renamed with it, as whether it is there could not be read';
	$whose = zfsmScheduleCopiesWhose($old, $to);
	if ($whose !== '') return $whose;
	$free = zfsmDatasetMissing($new);
	if ($free !== true) return $free === null ? 'not renamed with it, as whether its new name is free could not be read' : 'left where it is, as a dataset already has its new name';
	$into = zfsmScheduleCopiesInto($to, $old, $after);
	if ($into !== '') return 'left where it is, as a schedule sends copies into it ('.$into.'), so the next copy of the renamed dataset is a full one';
	$why = zfsmScheduleCopiesRename($job['dest'], $old, $new, $after, $cfg);
	if ($why !== '' && !zfsmRenameLanded($old, $new)) return 'not renamed with it: '.$why.'. It stays under its old name, so the next copy of the renamed dataset is a full one';
	$named = true;
	$left = implode('; ', array_filter(array($why, zfsmScheduleCopiesMarks($old, $new, $to), zfsmScheduleCopiesHolds($old, $new, $to)), 'strlen'));
	return $left === '' ? '' : 'renamed with it, but '.$left;
}

// What of $jobs sends a copy here to $old or below it, which it would not find after a move; '' when none. A repeat set in Replicate
// of $to or below follows the copy (zfsmScheduleCopiesFollows).
function zfsmScheduleCopiesInto($to, $old, $jobs) {
	foreach ($jobs as $job) {
		$dest = zfsmScheduleCopiesUnder($job) ? substr($job['dest'], 0, -1) : $job['dest'];
		if ($job['server'] === '' && $dest !== '' && zfsmUnderAny($dest, array($old)) && !zfsmScheduleCopiesFollows($job, $to, $old)) return zfsmScheduleWhat($job);
	}
	return '';
}

// '' when the copy at $old is $to's: its newest snapshot is on $to (as zfsmReplicaPlanOnto finds it), or it is a holder; else why
// it stays.
function zfsmScheduleCopiesWhose($old, $to) {
	$here = array('server' => '');
	list($held) = zfsmReplicaTargetSnapshots($here, $old);
	if ($held === null) return 'not renamed with it, as its snapshots could not be read';
	if (!$held) return zfsmReplicaHolder($here, $old) ? '' : 'left where it is, as it holds no snapshot of the renamed dataset';
	$marks = zfsmReplicaMarks($to);
	if ($marks === null) return 'not renamed with it, as the snapshots of the renamed dataset could not be read';
	return in_array(end($held)['guid'], array_column($marks, 'guid'), true) ? '' : 'left where it is, as its newest snapshot is not on the renamed dataset';
}

// The bookmarks on $to and below, named for their copies (zfsmReplicaTargetMark): those of $old and below take $new's. '' or what
// went wrong.
function zfsmScheduleCopiesMarks($old, $new, $to) {
	$listed = runProcess('zfs', array('list', '-H', '-o', 'name,guid', '-t', 'bookmark', '-r', $to));
	if (($listed['code'] ?? 1) !== 0) return 'its bookmarks could not be read: '.zfsmCommandError($listed, 'zfs list failed');
	$failed = array();
	foreach (zfsmLines($listed['output']) as $line) {
		$mark = explode("\t", $line, 2)[0];
		list($zdataset, $name) = explode('#', $mark, 2) + array(1 => '');
		$below = (string)substr($zdataset, strlen($to));
		$was = zfsmReplicaBookmarkPrefix($old.$below);
		if (strncmp($name, $was, strlen($was)) !== 0) continue;
		$why = zfsmScheduleCopiesMarkMove($mark, $zdataset.'#'.zfsmReplicaBookmarkPrefix($new.$below).substr($name, strlen($was)));
		if ($why !== '') $failed[] = $why;
	}
	return $failed ? 'not all its bookmarks were: '.zfsmKeepFirst($failed) : '';
}

// Bookmark $mark again as $name, then remove it; '' or what went wrong.
function zfsmScheduleCopiesMarkMove($mark, $name) {
	$made = runProcess('zfs', array('bookmark', $mark, $name));
	if (($made['code'] ?? 1) !== 0) return zfsmCommandError($made, 'zfs bookmark failed');
	$gone = runProcess('zfs', array('destroy', $mark));
	return ($gone['code'] ?? 1) === 0 ? '' : zfsmCommandError($gone, 'zfs destroy failed');
}

// The holds a stopped run put on $to's snapshots and below for the copies at $old and below, here or on $server (zfsmKeepHold), take
// $new's, so the run that carries it on, or lands there, takes them off. '' or what went wrong.
function zfsmScheduleCopiesHolds($old, $new, $to, $server = '') {
	$listed = runProcess('zfs', array('list', '-H', '-o', 'name,userrefs', '-t', 'snapshot', '-r', $to));
	if (($listed['code'] ?? 1) !== 0) return 'its holds could not be read: '.zfsmCommandError($listed, 'zfs list failed');
	$held = array();
	foreach (zfsmLines($listed['output']) as $line) {
		list($snapshot, $refs) = explode("\t", $line, 2) + array(1 => '0');
		if ($refs !== '0') $held[] = $snapshot;
	}
	$holds = $held ? zfsmKeepHolds($held) : array();
	if (is_string($holds)) return 'its holds could not be read: '.$holds;
	$failed = array();
	foreach ($holds as list($snapshot, $tag)) {
		$below = (string)substr(strstr($snapshot, '@', true), strlen($to));
		if ($tag !== zfsmKeepHoldTag(zfsmKeepTag($server, $old.$below))) continue;
		$why = zfsmScheduleCopiesHoldMove($snapshot, $tag, zfsmKeepHoldTag(zfsmKeepTag($server, $new.$below)));
		if ($why !== '') $failed[] = $snapshot.' ('.$why.')';
	}
	return $failed ? 'not all its holds were: '.zfsmKeepFirst($failed) : '';
}

// Hold $snapshot as $to too, then release $from; '' or what went wrong.
function zfsmScheduleCopiesHoldMove($snapshot, $from, $to) {
	$why = zfsmKeepHoldOne($to, $snapshot);
	if ($why !== '') return $why;
	$released = runProcess('zfs', array('release', $from, $snapshot));
	return ($released['code'] ?? 1) === 0 ? '' : zfsmCommandError($released, 'zfs release failed');
}

// Whether a schedule in $jobs copies $zdataset, or one below it, where $job does.
function zfsmScheduleCopiesKept($zdataset, $job, $jobs) {
	if (zfsmScheduleCopiesSame($job, zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder'))) return true;
	foreach ($jobs as $other) {
		if (zfsmScheduleCopiesUnder($other) && zfsmScheduleCopiesSame($job, $other) && zfsmUnderAny($other['dataset'], array($zdataset))) return true;
	}
	return false;
}

// zfs rename of copy $old to $new under $dest, after a holder is made where a level above $new is missing, as zfs rename needs its
// parent, unless that holder would stop a copy $jobs make there (zfsmScheduleCopiesBlocked). '' or why not.
function zfsmScheduleCopiesRename($dest, $old, $new, $jobs, $cfg) {
	$here = array();
	foreach (zfsmScheduleCopiesLevels($dest, $new) as $level) {
		$missing = zfsmDatasetMissing($level);
		if ($missing === null) return 'the datasets above its new name could not be read';
		if (!$missing) $here[$level] = true;
	}
	$blocked = zfsmScheduleCopiesBlocked($dest, $new, $here, $jobs, $cfg);
	if ($blocked !== '') return $blocked;
	$why = zfsmScheduleCopiesPlace($dest, $new, array(), $here);
	if ($why !== '') return $why;
	$renamed = runProcess('zfs', array('rename', $old, $new));
	return ($renamed['code'] ?? 1) === 0 ? '' : zfsmCommandError($renamed, 'zfs rename failed');
}

// Of the missing levels above $zdest, a copy under $dest ($here: those there), why a holder at the first would stop a copy $jobs make
// there: zfs receives no raw copy over it, nor any over an encrypted one (zfsmReplicaPlanHolder). '' when none.
function zfsmScheduleCopiesBlocked($dest, $zdest, $here, $jobs, $cfg) {
	$above = substr($dest, 0, -1);
	foreach (zfsmScheduleCopiesLevels($dest, $zdest) as $level) {
		if (isset($here[$level])) {
			$above = $level;
			continue;
		}
		$due = zfsmScheduleCopiesTo($dest, $level, $jobs, $cfg);
		$unread = 'whether a dataset made at '.$level.' to hold this one would stop a copy due there could not be read';
		if ($due === null) return $unread;
		foreach ($due as $zdataset) {
			$crypt = array(getDatasetProperty('', $zdataset, 'encryption'), getDatasetProperty('', $above, 'encryption'));
			if (in_array(null, $crypt, true)) return $unread;
			if ($crypt !== array('off', 'off')) return 'zfs could not receive the copy due at '.$level.' over the dataset made there to hold this one, as one of the two is encrypted';
		}
	}
	return '';
}
