<?php
// Copies under a dataset: records whose dest, a dataset here and '/', holds each covered dataset's copy (backup/ and cache/appdata:
// backup/cache/appdata). Dataset '' is Settings' Replicate Every Dataset; None is dest '/'. Coverage: zfsmScheduleFollowed.

// A start while the run before still goes is only logged for a day (zfsmScheduleLockedSkip): a first run sends each dataset in full.
define('ZFSM_SCHEDULE_COPIES_GRACE', 86400);

function zfsmScheduleCopiesUnder($job) {
	return is_string($job['dest'] ?? null) && substr($job['dest'], -1) === '/';
}

// As a snapshot schedule's dataset, Repeat and server (zfsmScheduleSnapshotValid), with a dataset here to copy under; None has none.
function zfsmScheduleCopiesValid($job) {
	$under = substr($job['dest'], 0, -1);
	return zfsmScheduleSnapshotValid($job) && ($job['every'] === 'none' ? $under === '' : zfsmValidDatasetName($under));
}

function zfsmScheduleCopiesDest($job, $zdataset) {
	return $job['dest'].$zdataset;
}

// Whether $zdataset is in the pool $job copies into, which a run of it leaves out (zfsmScheduleCopiesNot).
function zfsmScheduleCopiesInPool($job, $zdataset) {
	return explode('/', $zdataset)[0] === explode('/', $job['dest'])[0];
}

// Why $job never copies $zdataset, listed as $row (zfsmScheduleListed), besides zfsmScheduleSkipped's reasons; $name: a new
// snapshot's. '' when it copies it.
function zfsmScheduleCopiesNot($job, $jobs, $zdataset, $row, $name) {
	if (zfsmScheduleCopiesInPool($job, $zdataset)) return $zdataset.' is in pool '.explode('/', $job['dest'])[0].', where its copy would go';
	if ($row[2] === null) return 'the snapshot names of '.$zdataset.' could not be read, so it may be left to Sanoid or Syncoid';
	$dest = zfsmScheduleCopiesDest($job, $zdataset);
	if (!zfsmReplicaBookmarkFits($zdataset, zfsmReplicaTargetMark(array('server' => $job['server']), $dest), $name)) return 'the names of '.$zdataset.' and its copy are too long for the bookmark a copy keeps';
	foreach ($jobs as $other) {
		if (zfsmScheduleCopiesOwnRepeat($job, $other, $zdataset)) return $zdataset.' has a schedule of its own for that copy';
	}
	return '';
}

// Whether $other, a repeat set in Replicate, makes the copy of $zdataset that $job, copies under a dataset, would make.
function zfsmScheduleCopiesOwnRepeat($job, $other, $zdataset) {
	return zfsmScheduleCopiesUnder($job) && $other['dataset'] === $zdataset && $other['dest'] === zfsmScheduleCopiesDest($job, $zdataset) && $other['server'] === $job['server'];
}

// The record Save makes of copies under $under here: Settings' Replicate Every Dataset ($zdataset ''), or a dataset's own; None
// has none.
function zfsmScheduleCopiesRecord($zdataset, $every, $under, $time, $days, $keep) {
	$job = zfsmScheduleSnapshotRecord($zdataset, $every, $time, $days, $keep);
	$job['dest'] = ($every === 'none' ? '' : $under).'/';
	return $job;
}

// What the user fixes for Save to make $job, copies under a dataset (or $remove it), as zfsmScheduleSnapshotWrong says it.
function zfsmScheduleCopiesWrong($job, $remove) {
	if (!$remove && zfsmScheduleRepeats($job['every']) && !zfsmValidDatasetName(substr($job['dest'], 0, -1))) return 'under Copy Under, name a dataset on this server, such as backup';
	return zfsmScheduleSnapshotWrong($job, $remove);
}

// What following $job, copies under a dataset, means, for the dialog's Default and Save's answer.
function zfsmScheduleCopiesFollowedText($job) {
	if ($job === null) return 'Replicate Every Dataset, which is off: no scheduled copies';
	$whose = zfsmScheduleWhat($job);
	return $job['every'] === 'none' ? $whose.', None: no scheduled copies' : $whose.', under '.substr($job['dest'], 0, -1).' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job);
}

// Settings' row of copies under a dataset.
function zfsmScheduleCopiesText($job) {
	if ($job['every'] === 'none') return 'No scheduled copies of '.$job['dataset'].' and below';
	$whose = $job['dataset'] === '' ? 'Replicate Every Dataset' : 'Copies of '.$job['dataset'].' and below';
	return $whose.' under '.substr($job['dest'], 0, -1).', '.zfsmScheduleWhen($job).', '.zfsmScheduleKeepText($job);
}

// Why the copies can't go under $under, from $listed (zfsmScheduleCoverRead): it is no dataset here or a volume, or wasn't read; ''
// when they can.
function zfsmScheduleCopiesUnderNot($under, $listed) {
	list($rows, $errors) = zfsmScheduleListed($listed);
	if (!isset($rows[$under])) return ($listed['code'] ?? 1) === 0 ? $under.' does not exist on this server' : $under.' could not be read: '.zfsmScheduleUnreadWhy($errors, $listed);
	return $rows[$under][1] === '-' ? $under.' is a volume, which holds no datasets' : '';
}

// Saves Replicate Every Dataset in place (zfsmScheduleSnapshotPut), copying under $under here; 'off' removes it. Nothing is copied
// now. As zfsmScheduleChange answers, and 'said'.
function zfsmScheduleCopiesSave($under, $every, $time, $days, $keep, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	$remove = $every === 'off';
	$job = zfsmScheduleCopiesRecord('', $every, $under, $time, $days, $keep);
	$wrong = zfsmScheduleCopiesWrong($job, $remove);
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	// One zfsmScheduleCoverRead: Copy Under must be there, and the answer counts what a run copies.
	$listed = $remove ? null : zfsmScheduleCoverRead($cfg, $wait);
	$not = $remove ? '' : zfsmScheduleCopiesUnderNot($under, $listed);
	if ($not !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$not, 'said' => '');
	$after = array();
	$changed = zfsmScheduleSnapshotChange(function($all) use (&$job, $remove, $cfg) { return zfsmScheduleSnapshotPut($all, $job, $remove, $cfg); }, $after);
	if (!$changed['saved']) return $changed + array('said' => '');
	return $changed + array('said' => $remove ? 'Replicate Every Dataset is off; the copies it made and their snapshots stay' : zfsmScheduleCopiesSaved($job, $listed, $after));
}

// Save's answer for $job: what a run copies, counted from $listed (zfsmScheduleCoverRead) with the schedules saved, $after.
function zfsmScheduleCopiesSaved($job, $listed, $after) {
	list($pools, $unread) = zfsmScheduleCovered($listed, $job, $after);
	$count = $unread !== '' && !$pools ? 'each dataset' : 'each of '.array_sum(array_map('count', $pools)).' dataset(s)';
	return 'Saved: a copy of '.$count.($job['dataset'] !== '' ? ' ('.$job['dataset'].' and below)' : '').' under '.substr($job['dest'], 0, -1).' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job)
		.($unread !== '' ? '; not every dataset could be read: '.$unread : '');
}

// Why $job, a dataset's own copies under a dataset, can't copy its dataset, from $listed (zfsmScheduleCoverRead) and $jobs: Copy
// Under or the dataset not there or not read, or one it leaves out (zfsmScheduleSkipped, zfsmScheduleCopiesNot); '' when it can.
function zfsmScheduleCopiesOwnNot($job, $jobs, $listed) {
	$not = zfsmScheduleCopiesUnderNot(substr($job['dest'], 0, -1), $listed);
	if ($not === '') $not = zfsmScheduleSnapshotNot($job['dataset'], $listed);
	return $not !== '' ? $not : zfsmScheduleCopiesNot($job, $jobs, $job['dataset'], zfsmScheduleListed($listed)[0][$job['dataset']], $listed['name']);
}

// The dialog Save's answer for $job, a dataset's own copies under a dataset ($remove: gone), with $jobs as saved; one that runs is
// counted in $listed.
function zfsmScheduleCopiesOwnSaid($job, $remove, $jobs, $listed) {
	if ($remove) return 'Saved: the copies of '.$job['dataset'].' follow '.zfsmScheduleCopiesFollowedText(zfsmScheduleFollowed($job['dataset'], $jobs, 'zfsmScheduleCopiesUnder'));
	if ($job['every'] === 'none') return 'Saved: no scheduled copies of '.$job['dataset'].' and below, but for datasets with their own and repeats set in Replicate; the copies made stay';
	$down = zfsmScheduleCopiesUnderNot(substr($job['dest'], 0, -1), $listed);
	return zfsmScheduleCopiesSaved($job, $listed, $jobs).($down !== '' ? '; no run copies while '.$down : '');
}

// Cron's run of copies under a dataset: each dataset it covers, parents first, sent to its copy as a copy's schedule sends it
// (zfsmRunReplicate, with its Keep); one notification for the run. 0, or 1 after a problem.
function zfsmScheduleCopiesRun($job, $jobs, $cfg, $now, $notify, $wait = ZFSM_SCHEDULE_WAIT) {
	return zfsmScheduleLockedRun($job, 'ZFS Replication', 'Scheduled copies not made: ', $notify, function() use ($job, $jobs, $cfg, $now, $wait) {
		return zfsmScheduleCopiesMade($job, $jobs, $cfg, $now, $wait);
	}, ZFSM_SCHEDULE_COPIES_GRACE);
}

// What a run copied, and whether it had a problem: null when zfs read every pool and none had a dataset to copy. Its waits for other
// replications of a dataset share $wait, so the run waits that long at most in all.
function zfsmScheduleCopiesMade($job, $jobs, $cfg, $now, $wait) {
	$under = substr($job['dest'], 0, -1);
	$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT);
	list($pools, $unread) = zfsmScheduleCovered($listed, $job, $jobs);
	$none = zfsmScheduleCopiesNone($under, $listed, $pools, $unread);
	if ($none !== null) return $none;
	$datasets = array_merge(...array_values($pools));
	// By name: a dataset's parents come before it.
	sort($datasets, SORT_STRING);
	$here = array_fill_keys(array_keys(zfsmScheduleListed($listed)[0]), true);
	$coming = zfsmScheduleCopiesComing($job, $jobs, $listed);
	$name = zfsmNewSnapshotName($cfg, $now);
	$made = 0;
	$problems = $waiting = array();
	foreach ($datasets as $zdataset) {
		$zdest = zfsmScheduleCopiesDest($job, $zdataset);
		$place = zfsmScheduleCopiesPlace($job['dest'], $zdest, $coming, $here);
		if ($place === null) $waiting[] = $zdataset;
		if ($place !== '') {
			if ($place !== null) $problems[] = $place;
			continue;
		}
		list($code, $said) = zfsmScheduleCopiesOne($job, $zdataset, $zdest, $name, $wait);
		if ($code === 0) {
			$made++;
			$here[$zdest] = true;
		}
		if ($code !== 0 || $said[1] !== 'normal') $problems[] = $said[0];
	}
	return array(zfsmScheduleCopiesSaid($under, $made.' of '.count($datasets), $waiting, $unread, $problems), $waiting || $unread !== '' || $problems);
}

// What a run says when it copies none, as zfsmScheduleCopiesMade answers; null when it can copy.
function zfsmScheduleCopiesNone($under, $listed, $pools, $unread) {
	$what = zfsmScheduleNamesUnread($listed) ? 'the snapshot names' : 'the datasets';
	if (!$pools) return array('Scheduled copies not made: '.($unread !== '' ? $what.' could not be read: '.$unread : 'no pool has a dataset it copies'), $unread !== '' ? true : null);
	$not = zfsmScheduleCopiesUnderNot($under, $listed);
	return $not !== '' ? array('Scheduled copies not made: '.$not, true) : null;
}

// Where a copy is due here: each a schedule of copies under $job's dest makes, $job or another, and each a copy's own schedule
// makes; a holder there would refuse it.
function zfsmScheduleCopiesComing($job, $jobs, $listed) {
	$coming = array();
	foreach (zfsmScheduleListed($listed)[0] as $zdataset => $row) {
		$by = zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder');
		if (($by['dest'] ?? '') === $job['dest'] && zfsmScheduleLeftOut($by, $jobs, $zdataset, $row, $listed['name']) === '') $coming[zfsmScheduleCopiesDest($by, $zdataset)] = true;
	}
	foreach (zfsmScheduleCopiesRepeatsTo($jobs) as $zdest => $from) $coming[$zdest] = true;
	return $coming;
}

// The datasets each repeat set in Replicate copies on this server, by the copy it makes.
function zfsmScheduleCopiesRepeatsTo($jobs) {
	$to = array();
	foreach ($jobs as $job) if (!zfsmScheduleCovers($job) && $job['server'] === '') $to[$job['dest']][] = $job['dataset'];
	return $to;
}

// One dataset's copy (zfsmRunReplicate): array(its code, array(its text, type)); an error ends only this one. $wait: what the run has
// left to wait, less what this one waits.
function zfsmScheduleCopiesOne($job, $zdataset, $zdest, $name, &$wait) {
	$said = array('', 'normal');
	$tell = function($title, $text, $type) use (&$said) { $said = array(rtrim($text, '.'), $type); };
	$waited = function($seconds) use (&$wait) { $wait = max(0, $wait - $seconds); };
	try {
		$code = zfsmRunReplicate($zdataset, $name, $zdest, null, true, '', array('notify' => $tell, 'wait' => $wait, 'waited' => $waited, 'tag' => zfsmKeepTag('', $job['dest']), 'remake' => true), $job['keep']);
	} catch (Throwable $error) {
		zfsmLog('Replication of '.$zdataset.' to '.$zdest.' failed: '.zfsmOneLine($error->getMessage()));
		return array(1, array('Replication of '.$zdataset.' to '.$zdest.' failed: '.zfsmOneLine($error->getMessage()), 'warning'));
	}
	return array($code, $said);
}

// A run's line: how many it copied, those waiting for their parent's copy, the datasets not read, the first problems.
function zfsmScheduleCopiesSaid($under, $made, $waiting, $unread, $problems) {
	return 'Scheduled copies under '.$under.': '.$made.' dataset(s) copied'
		// Before the problems, which the log's cut can drop (zfsmSafeText); in brackets and commas, where diagnostics end a name.
		.($waiting ? '. Not copied, as the copy of a dataset above it is missing ('.zfsmKeepFirst($waiting, ', ').')' : '')
		.($unread !== '' ? '. Not every dataset could be read: '.$unread : '').($problems ? '. '.zfsmKeepFirst($problems) : '');
}

// The datasets above $zdest, a copy under $dest, below $dest's dataset; top first.
function zfsmScheduleCopiesLevels($dest, $zdest) {
	$level = substr($dest, 0, -1);
	$levels = array();
	foreach (array_slice(explode('/', substr($zdest, strlen($dest))), 0, -1) as $part) $levels[] = $level .= '/'.$part;
	return $levels;
}

// The datasets above $zdest, a copy under $dest: '' when each is there or made a holder, null to wait where a copy is $coming, else
// why a holder could not be made, as for a dataset renamed or destroyed since a run listed it. $here gains each holder.
function zfsmScheduleCopiesPlace($dest, $zdest, $coming, &$here) {
	$zdataset = substr($zdest, strlen($dest));
	$missing = null;
	foreach (zfsmScheduleCopiesLevels($dest, $zdest) as $level) {
		if (isset($here[$level])) continue;
		if (isset($coming[$level])) return null;
		$missing = $missing ?? zfsmDatasetMissing($zdataset);
		if ($missing !== false) return ($missing ? $zdataset.' is gone' : 'whether '.$zdataset.' is still there could not be read').', so no dataset was made to hold its copy';
		$why = zfsmReplicaHolderMake($level);
		if ($why !== '') return $why;
		$here[$level] = true;
	}
	return '';
}

// Why renaming $from to $to ($names: it and those below it) would leave a copy a schedule makes of one no room for the bookmark it
// keeps (zfsmReplicaBookmarkFits), where there was; '' when none.
function zfsmScheduleCopiesFit($names, $from, $to, $cfg) {
	$read = zfsmScheduleRead();
	// The rename's follow-up says so.
	if ($read === null) return '';
	$snapshot = zfsmNewSnapshotName($cfg, time());
	// One a run leaves out (zfsmScheduleSkipped) needs no room; one whose row could not be read is checked. Read once a name is too long.
	$rows = null;
	$skipped = function($zdataset, $name) use (&$rows, $cfg, $from) {
		if ($rows === null) $rows = zfsmScheduleListed(zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT, array('-r', $from)))[0];
		return isset($rows[$name]) && zfsmScheduleSkipped($zdataset, $rows[$name]) !== '';
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

// The replication locks (zfsmJobLock, no command started holds them) of $zdataset and each dataset below it a run can copy, by
// name; why not when one is held or can't be had.
function zfsmScheduleCopiesHold($zdataset) {
	$listed = runProcess('zfs', array('list', '-H', '-o', 'name,guid,mountpoint', '-t', 'filesystem,volume', '-r', $zdataset));
	if (($listed['code'] ?? 1) !== 0) return 'the datasets at and below it could not be read: '.zfsmCommandError($listed, 'zfs list failed');
	$read = zfsmScheduleRead();
	$repeats = array();
	foreach ($read === null ? array() : $read['valid'] as $job) if (!zfsmScheduleCovers($job)) $repeats[$job['dataset']] = true;
	$locks = array();
	foreach (zfsmLines($listed['output']) as $line) {
		list($name, $guid, $mountpoint) = explode("\t", $line, 3) + array('', '', '');
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
	return $locks;
}

// After zfs renamed $from to $to and its own schedules followed ($before, $after): its copy under each dataset that copies go under
// takes $to's name there (zfsmScheduleCopiesMoveOne). Each with a problem, with what.
function zfsmScheduleCopiesMove($from, $to, $before, $after, $cfg) {
	$stayed = array();
	foreach (array_column(array_filter($before, 'zfsmScheduleCopiesUnder'), null, 'dest') as $dest => $job) {
		$why = $dest === '/' ? '' : zfsmScheduleCopiesMoveOne($job, $from, $to, $after, $cfg);
		if ($why !== '') $stayed[zfsmScheduleCopiesDest($job, $from)] = $why;
	}
	return $stayed;
}

// The copy of $from under $job's dest, with its bookmarks, renamed to $to's when it is a copy of $to or a holder and a schedule in
// $after still copies $to or one below it there; '' when it moved, is not there, or is not read where none copies it, else what
// went wrong.
function zfsmScheduleCopiesMoveOne($job, $from, $to, $after, $cfg) {
	$old = zfsmScheduleCopiesDest($job, $from);
	$new = zfsmScheduleCopiesDest($job, $to);
	$missing = zfsmDatasetMissing($old);
	if (!zfsmScheduleCopiesKept($to, $job['dest'], $after)) return $missing === false ? 'left where it is, as no schedule copies the renamed dataset or one below it there' : '';
	// zfs says the same of a copy in a pool not imported now.
	$pool = $missing === true ? zfsmDatasetMissing(strtok($old, '/')) : false;
	if ($pool !== false) return 'not checked, as '.($pool ? 'its pool is not imported now' : 'whether its pool is imported could not be read').': if it is there, it keeps its old name, and the next copy of the renamed dataset is a full one';
	if ($missing === true) return '';
	if ($missing === null) return 'not renamed with it, as whether it is there could not be read';
	$into = zfsmScheduleCopiesInto($from, $old, $after);
	if ($into !== '') return 'left where it is, as a schedule sends copies into it ('.$into.'), so the next copy of the renamed dataset is a full one';
	$whose = zfsmScheduleCopiesWhose($old, $to);
	if ($whose !== '') return $whose;
	$free = zfsmDatasetMissing($new);
	if ($free !== true) return $free === null ? 'not renamed with it, as whether its new name is free could not be read' : 'left where it is, as a dataset already has its new name';
	$why = zfsmScheduleCopiesRename($job['dest'], $old, $new, $after, $cfg);
	if ($why !== '' && !zfsmRenameLanded($old, $new)) return 'not renamed with it: '.$why.'. It stays under its old name, so the next copy of the renamed dataset is a full one';
	$left = implode('; ', array_filter(array($why, zfsmScheduleCopiesMarks($old, $new, $to), zfsmScheduleCopiesHolds($old, $new, $to)), 'strlen'));
	return $left === '' ? '' : 'renamed with it, but '.$left;
}

// What of $jobs sends a copy here to $old or below it, which it would not find after a move; '' when none. A repeat set in Replicate
// of $from or below, which kept that name, finds no dataset to send anyway.
function zfsmScheduleCopiesInto($from, $old, $jobs) {
	foreach ($jobs as $job) {
		$dest = zfsmScheduleCopiesUnder($job) ? substr($job['dest'], 0, -1) : $job['dest'];
		if ($job['server'] === '' && $dest !== '' && zfsmUnderAny($dest, array($old)) && !zfsmUnderAny($job['dataset'], array($from))) return zfsmScheduleWhat($job);
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
	// A second field keeps the last name's trailing spaces from runProcess's trim, as zfsmScheduleCopiesHolds does.
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

// The holds a stopped run put on $to's snapshots and below for the copies at $old and below (zfsmKeepHold) take $new's, so the
// run that carries it on takes them off. '' or what went wrong.
function zfsmScheduleCopiesHolds($old, $new, $to) {
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
		if ($tag !== zfsmKeepHoldTag(zfsmKeepTag('', $old.$below))) continue;
		$why = zfsmScheduleCopiesHoldMove($snapshot, $tag, zfsmKeepHoldTag(zfsmKeepTag('', $new.$below)));
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

// Whether a schedule in $jobs copies $zdataset, or one below it, under $dest.
function zfsmScheduleCopiesKept($zdataset, $dest, $jobs) {
	if ((zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder')['dest'] ?? null) === $dest) return true;
	foreach ($jobs as $job) {
		if (zfsmScheduleCopiesUnder($job) && $job['dest'] === $dest && zfsmUnderAny($job['dataset'], array($zdataset))) return true;
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

// The datasets $jobs copy to $level under $dest, as zfsmScheduleCopiesComing reads a run's: the one of that name (above the renamed
// one, so there) when a schedule's run takes it there (zfsmScheduleLeftOut), and each a repeat set in Replicate sends there; null
// when whether a run takes it could not be read.
function zfsmScheduleCopiesTo($dest, $level, $jobs, $cfg) {
	$zdataset = substr($level, strlen($dest));
	$repeats = zfsmScheduleCopiesRepeatsTo($jobs)[$level] ?? array();
	$job = zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder');
	if (($job['dest'] ?? '') !== $dest) return $repeats;
	$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT, array('-d', '1', $zdataset));
	$row = zfsmScheduleListed($listed)[0][$zdataset] ?? null;
	if ($row === null || $row[2] === null) return null;
	return zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $listed['name']) === '' ? array_merge(array($zdataset), $repeats) : $repeats;
}
