<?php
// Copies under a dataset, here or in a trusted server's area: dest, a dataset and '/', holds each covered dataset's copy (backup/ and
// cache/appdata: backup/cache/appdata). Dataset '' is Replicate Every Dataset; None is dest '/'. Coverage: zfsmScheduleFollowed.

// A start while the run before still goes is only logged for a day (zfsmScheduleLockedSkip): a first run sends each dataset in full.
define('ZFSM_SCHEDULE_COPIES_GRACE', 86400);

function zfsmScheduleCopiesUnder($job) {
	return is_string($job['dest'] ?? null) && substr($job['dest'], -1) === '/';
}

// As a snapshot schedule's dataset and Repeat (zfsmScheduleSnapshotValid, which takes no server), with a dataset to copy under, here
// or on a server; None has neither.
function zfsmScheduleCopiesValid($job) {
	$under = substr($job['dest'], 0, -1);
	$server = $job['server'] ?? null;
	if (!zfsmScheduleSnapshotValid(array('server' => '') + $job)) return false;
	return $job['every'] === 'none' ? $under === '' && $server === '' : zfsmScheduleCopiesUnderValid($under, $server) && ($server === '' || zfsmReplicaSenderName($server));
}

// Whether $under names a dataset to copy under: here one in a pool; on $server a path inside its area, whose first part is no pool.
function zfsmScheduleCopiesUnderValid($under, $server) {
	return zfsmValidDatasetName($server === '' ? $under : 'p/'.$under);
}

function zfsmScheduleCopiesDest($job, $zdataset) {
	return $job['dest'].$zdataset;
}

// Whether $other (null: none) puts its copies where $job does: under the same Copy Under, on the same server.
function zfsmScheduleCopiesSame($job, $other) {
	return ($other['dest'] ?? null) === $job['dest'] && ($other['server'] ?? null) === $job['server'];
}

// Whether $zdataset is in the pool here $job copies into, which a run of it leaves out (zfsmScheduleCopiesNot).
function zfsmScheduleCopiesInPool($job, $zdataset) {
	return $job['server'] === '' && explode('/', $zdataset)[0] === explode('/', $job['dest'])[0];
}

// Where $job puts its copies, as its texts name it: Copy Under, and the server.
function zfsmScheduleCopiesUnderText($job) {
	return zfsmReplicaTargetWhere(array('server' => $job['server']), substr($job['dest'], 0, -1));
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

// The record Save makes of copies under $under, here or on $server: Settings' Replicate Every Dataset ($zdataset ''), or a dataset's
// own; None has neither, nor Copy Keeps ($copy_keeps: zfsmCopyKeepsParse's).
function zfsmScheduleCopiesRecord($zdataset, $every, $under, $time, $days, $keep, $copy_keeps = null, $server = '') {
	$job = zfsmScheduleSnapshotRecord($zdataset, $every, $time, $days, $keep);
	$job['dest'] = ($every === 'none' ? '' : $under).'/';
	$job['server'] = $every === 'none' ? '' : $server;
	return zfsmScheduleCopyKeepsSet($job, $every === 'none' ? null : $copy_keeps);
}

// What the user fixes for Save to make $job, copies under a dataset (or $remove it), as zfsmScheduleSnapshotWrong says it.
function zfsmScheduleCopiesWrong($job, $remove) {
	$where = $job['server'] === '' ? 'on this server' : 'to hold them inside the area '.$job['server'].' gives this server';
	if (!$remove && zfsmScheduleRepeats($job['every']) && !zfsmScheduleCopiesUnderValid(substr($job['dest'], 0, -1), $job['server'])) return 'under Copy Under, name a dataset '.$where.', such as backup';
	return zfsmScheduleSnapshotWrong($job, $remove);
}

// What following $job, copies under a dataset, means, for the dialog's Default and Save's answer.
function zfsmScheduleCopiesFollowedText($job) {
	if ($job === null) return 'Replicate Every Dataset, which is off: no scheduled copies';
	$whose = zfsmScheduleWhat($job);
	return $job['every'] === 'none' ? $whose.', None: no scheduled copies' : $whose.', under '.zfsmScheduleCopiesUnderText($job).' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job);
}

// Settings' row of copies under a dataset.
function zfsmScheduleCopiesText($job) {
	if ($job['every'] === 'none') return 'No scheduled copies of '.$job['dataset'].' and below';
	$whose = $job['dataset'] === '' ? 'Replicate Every Dataset' : 'Copies of '.$job['dataset'].' and below';
	return $whose.' under '.zfsmScheduleCopiesUnderText($job).', '.zfsmScheduleWhen($job).', '.zfsmScheduleKeepText($job);
}

// Why $job's copies can't go under its Copy Under here, from $listed (zfsmScheduleCoverRead): it is no dataset or a volume, or wasn't
// read; '' when they can, and on a server, where a run makes it.
function zfsmScheduleCopiesUnderNot($job, $listed) {
	if ($job['server'] !== '') return '';
	$under = substr($job['dest'], 0, -1);
	list($rows, $errors) = zfsmScheduleListed($listed);
	if (!isset($rows[$under])) return ($listed['code'] ?? 1) === 0 ? $under.' does not exist on this server' : $under.' could not be read: '.zfsmScheduleUnreadWhy($errors, $listed);
	return $rows[$under][1] === '-' ? $under.' is a volume, which holds no datasets' : '';
}

// Saves Replicate Every Dataset in place (zfsmScheduleSnapshotPut), copying under $under here or on $server, one in Replicate To
// Other Servers, which is not asked now; 'off' removes it. Nothing is copied now. As zfsmScheduleChange answers, and 'said'.
function zfsmScheduleCopiesSave($under, $every, $time, $days, $keep, $cfg, $copy_keeps = null, $server = '', $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	$remove = $every === 'off';
	$job = zfsmScheduleCopiesRecord('', $every, $under, $time, $days, $keep, $copy_keeps, $server);
	$remote = $remove || $server === '' ? null : zfsmReplicaRemote($server);
	if (is_string($remote)) return array('saved' => false, 'why' => 'Nothing was saved: '.$remote, 'said' => '');
	$wrong = zfsmScheduleCopiesWrong($job, $remove);
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	// One zfsmScheduleCoverRead: Copy Under must be there, and the answer counts what a run copies.
	$listed = $remove ? null : zfsmScheduleCoverRead($cfg, $wait);
	$not = $remove ? '' : zfsmScheduleCopiesUnderNot($job, $listed);
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
	return 'Saved: a copy of '.$count.($job['dataset'] !== '' ? ' ('.$job['dataset'].' and below)' : '').' under '.zfsmScheduleCopiesUnderText($job).' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job)
		.($unread !== '' ? '; not every dataset could be read: '.$unread : '');
}

// Why $job, a dataset's own copies under a dataset, can't copy its dataset, from $listed (zfsmScheduleCoverRead) and $jobs: Copy
// Under or the dataset not there or not read, or one it leaves out (zfsmScheduleSkipped, zfsmScheduleCopiesNot); '' when it can.
function zfsmScheduleCopiesOwnNot($job, $jobs, $listed) {
	$not = zfsmScheduleCopiesUnderNot($job, $listed);
	if ($not === '') $not = zfsmScheduleSnapshotNot($job['dataset'], $listed);
	return $not !== '' ? $not : zfsmScheduleCopiesNot($job, $jobs, $job['dataset'], zfsmScheduleListed($listed)[0][$job['dataset']], $listed['name']);
}

// The dialog Save's answer for $job, a dataset's own copies under a dataset ($remove: gone), with $jobs as saved; one that runs is
// counted in $listed.
function zfsmScheduleCopiesOwnSaid($job, $remove, $jobs, $listed) {
	if ($remove) return 'Saved: the copies of '.$job['dataset'].' follow '.zfsmScheduleCopiesFollowedText(zfsmScheduleFollowed($job['dataset'], $jobs, 'zfsmScheduleCopiesUnder'));
	if ($job['every'] === 'none') return 'Saved: no scheduled copies of '.$job['dataset'].' and below, but for datasets with their own and repeats set in Replicate; the copies made stay';
	$down = zfsmScheduleCopiesUnderNot($job, $listed);
	return zfsmScheduleCopiesSaved($job, $listed, $jobs).($down !== '' ? '; no run copies while '.$down : '');
}

// Cron's run of copies under a dataset: each dataset it covers, parents first, sent to its copy as a copy's schedule sends it
// (zfsmRunReplicate, with its Keep as saved when that copy starts); one notification for the run. 0, or 1 after a problem.
function zfsmScheduleCopiesRun($job, $jobs, $cfg, $now, $notify, $wait = ZFSM_SCHEDULE_WAIT) {
	return zfsmScheduleLockedRun($job, 'ZFS Replication', 'Scheduled copies not made: ', $notify, function() use ($job, $jobs, $cfg, $now, $wait) {
		return zfsmScheduleCopiesMade($job, $jobs, $cfg, $now, $wait);
	}, ZFSM_SCHEDULE_COPIES_GRACE);
}

// What a run copied, and whether it had a problem: null when zfs read every pool and none had a dataset to copy, or it only left out
// (zfsmScheduleTakenBeforeProblem). Its waits for other replications of a dataset share $wait, so it waits that long at most in all.
function zfsmScheduleCopiesMade($job, $jobs, $cfg, $now, $wait) {
	// Its own connection, for the levels above each copy (zfsmScheduleCopiesPlace); each copy's run opens another.
	$target = zfsmReplicaTargetOpen($job['server']);
	try {
		return zfsmScheduleCopiesMadeOn($target, $job, $jobs, $cfg, $now, $wait);
	} finally {
		zfsmReplicaDisconnect($target);
	}
}

// zfsmScheduleCopiesMade with $target open.
function zfsmScheduleCopiesMadeOn($target, $job, $jobs, $cfg, $now, $wait) {
	$name = zfsmNewSnapshotName($cfg, $now);
	$untaken = zfsmNewSnapshotNameWhy($name);
	if ($untaken !== '') return array('Scheduled copies not made: '.$untaken, true);
	$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT);
	list($pools, $unread) = zfsmScheduleCovered($listed, $job, $jobs);
	$none = zfsmScheduleCopiesNone($job, $listed, $pools, $unread);
	if ($none !== null) return $none;
	$cannot = zfsmReplicaTargetHolders($target);
	if ($cannot !== '') return array('Scheduled copies not made: '.$cannot, true);
	$datasets = array_merge(...array_values($pools));
	// By name: a dataset's parents come before it.
	sort($datasets, SORT_STRING);
	$again = zfsmScheduleTakenBefore($cfg, $now, $datasets, $listed);
	$datasets = array_values(array_diff($datasets, $again));
	$long = zfsmScheduleCopiesTooLong($target, $job, $datasets, $name);
	$datasets = array_values(array_diff($datasets, $long));
	list($made, $due, $waiting, $problems) = zfsmScheduleCopiesEach($target, $job, $jobs, $cfg, $datasets, $listed, $name, $wait);
	return array(zfsmScheduleCopiesSaid(zfsmScheduleCopiesUnderText($job), $made.' of '.$due, $again, $long, $waiting, $unread, $problems), zfsmScheduleTakenBeforeProblem($again, $made, $waiting || $unread !== '' || $problems));
}

// The copies of $datasets, parents first, for zfsmScheduleCopiesMadeOn: array(how many made, of how many still due, those waiting
// for their parent's copy, the problems).
function zfsmScheduleCopiesEach($target, $job, $jobs, $cfg, $datasets, $listed, $name, $wait) {
	$rows = zfsmScheduleListed($listed)[0];
	list($here, $coming) = zfsmScheduleCopiesKnown($job, $jobs, $listed, $rows);
	$made = $left = 0;
	$problems = $waiting = array();
	$stopped = function($zdataset, $why) use (&$problems) { $problems[] = $zdataset.' and the datasets after it were not copied: '.$why; };
	foreach ($datasets as $zdataset) {
		$saved = zfsmScheduleCopiesStill($job, $zdataset, $rows[$zdataset], $listed['name']);
		if ($saved === null) {
			$stopped($zdataset, zfsmScheduleUnreadable());
			break;
		}
		if ($saved === false) {
			$left++;
			continue;
		}
		// Left alone since ssh to it failed, so asked nothing more; none here, as only zfsmReplicaAsk writes one.
		$down = zfsmReplicaDown($job['server']);
		if ($down !== null) {
			$stopped($zdataset, $down);
			break;
		}
		$zdest = zfsmScheduleCopiesDest($job, $zdataset);
		$place = zfsmScheduleCopiesPlace($job['dest'], $zdest, $coming, $here, $cfg, $target);
		if ($place === null) $waiting[] = $zdataset;
		if ($place !== '') {
			if ($place !== null) $problems[] = $place;
			continue;
		}
		list($code, $said) = zfsmScheduleCopiesOne($saved, $zdataset, $zdest, $name, $wait);
		if ($code === 0) {
			$made++;
			$here[$zdest] = true;
		}
		if ($code !== 0 || $said[1] !== 'normal') $problems[] = $said[0];
	}
	return array($made, count($datasets) - $left, $waiting, $problems);
}

// What a run knows is there and coming before its first copy: here the datasets listed and the copies due (zfsmScheduleCopiesComing);
// on a server neither, as zfsmScheduleCopiesPlace asks each level there and waits on one only while it is due.
function zfsmScheduleCopiesKnown($job, $jobs, $listed, $rows) {
	if ($job['server'] !== '') return array(array(), array());
	return array(array_fill_keys(array_keys($rows), true), zfsmScheduleCopiesComing($job, $jobs, $listed));
}

// The datasets whose bookmark (zfsmReplicaBookmarkFits) has no room once the copy's name holds a server's area, which
// zfsmScheduleCopiesNot can't know: a run leaves them out and says so.
function zfsmScheduleCopiesTooLong($target, $job, $datasets, $name) {
	return array_values(array_filter($datasets, function($zdataset) use ($target, $job, $name) {
		return !zfsmReplicaBookmarkFits($zdataset, zfsmReplicaTargetMark($target, zfsmReplicaTargetName($target, zfsmScheduleCopiesDest($job, $zdataset))), $name);
	}));
}

// What a run says when it copies none, as zfsmScheduleCopiesMade answers; null when it can copy.
function zfsmScheduleCopiesNone($job, $listed, $pools, $unread) {
	$what = zfsmScheduleNamesUnread($listed) ? 'the snapshot names' : 'the datasets';
	if (!$pools) return array('Scheduled copies not made: '.($unread !== '' ? $what.' could not be read: '.$unread : 'no pool has a dataset it copies'), $unread !== '' ? true : null);
	$not = zfsmScheduleCopiesUnderNot($job, $listed);
	return $not !== '' ? array('Scheduled copies not made: '.$not, true) : null;
}

// $job as saved now, while it still has $job's run take $zdataset where it copied (zfsmScheduleTakes), as a run takes hours; else
// false, and null when the schedules can't be read. One set to None copies under none.
function zfsmScheduleCopiesStill($job, $zdataset, $row, $name) {
	$read = zfsmScheduleRead();
	if ($read === null) return null;
	$now = $read['valid'][$job['id']] ?? null;
	return zfsmScheduleCopiesSame($job, $now) && zfsmScheduleTakes($now, $read['valid'], $zdataset, $row, $name) ? $now : false;
}

// Where a copy is due here: each a schedule of copies under $job's dest makes, $job or another, and each a copy's own schedule
// makes; a holder there would refuse it.
function zfsmScheduleCopiesComing($job, $jobs, $listed) {
	$coming = array();
	foreach (zfsmScheduleListed($listed)[0] as $zdataset => $row) {
		$by = zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder');
		if (zfsmScheduleCopiesSame($job, $by) && zfsmScheduleLeftOut($by, $jobs, $zdataset, $row, $listed['name']) === '') $coming[zfsmScheduleCopiesDest($by, $zdataset)] = true;
	}
	foreach (zfsmScheduleCopiesRepeatsTo($jobs) as $zdest => $from) $coming[$zdest] = true;
	return $coming;
}

// The datasets each repeat set in Replicate copies here or on $server, by the copy it makes.
function zfsmScheduleCopiesRepeatsTo($jobs, $server = '') {
	$to = array();
	foreach ($jobs as $job) if (!zfsmScheduleCovers($job) && $job['server'] === $server) $to[$job['dest']][] = $job['dataset'];
	return $to;
}

// One dataset's copy (zfsmRunReplicate): array(its code, array(its text, type)); an error ends only this one. $wait: what the run has
// left to wait, less what this one waits.
function zfsmScheduleCopiesOne($job, $zdataset, $zdest, $name, &$wait) {
	$said = array('', 'normal');
	$tell = function($title, $text, $type) use (&$said) { $said = array(rtrim($text, '.'), $type); };
	$waited = function($seconds) use (&$wait) { $wait = max(0, $wait - $seconds); };
	try {
		$code = zfsmRunReplicate($zdataset, $name, $zdest, null, true, $job['server'], array('notify' => $tell, 'wait' => $wait, 'waited' => $waited,
			'tag' => zfsmKeepTag($job['server'], $job['dest']), 'remake' => true, 'under' => true, 'copy_keeps' => $job['copy_keeps'] ?? null), $job['keep']);
	} catch (Throwable $error) {
		$failed = 'Replication of '.$zdataset.' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $zdest).' failed: '.zfsmOneLine($error->getMessage());
		zfsmLog($failed);
		return array(1, array($failed, 'warning'));
	}
	return array($code, $said);
}

// A run's line: how many it copied and left out, those waiting for their parent's copy, the datasets not read, the first problems.
function zfsmScheduleCopiesSaid($under, $made, $again, $long, $waiting, $unread, $problems) {
	return 'Scheduled copies under '.$under.': '.$made.' dataset(s) copied'.zfsmScheduleTakenBeforeSaid($again)
		.($long ? '. Left out, as the names of each and its copy there are too long for the bookmark a copy keeps '.zfsmScheduleNamesSaid($long) : '')
		// Before the problems, which the log's cut can drop (zfsmSafeText).
		.($waiting ? '. Not copied, as the copy of a dataset above it is missing '.zfsmScheduleNamesSaid($waiting) : '')
		.($unread !== '' ? '. Not every dataset could be read: '.$unread : '').($problems ? '. '.zfsmKeepFirst($problems) : '');
}

// The datasets above $zdest, a copy under $dest, top first: those below $dest's dataset, or with $whole (on a server, where a run
// makes Copy Under too) all of them.
function zfsmScheduleCopiesLevels($dest, $zdest, $whole = false) {
	$parts = explode('/', $zdest);
	$levels = array();
	for ($i = 1; $i < count($parts); $i++) $levels[] = implode('/', array_slice($parts, 0, $i));
	return $whole ? $levels : array_slice($levels, substr_count($dest, '/'));
}

// The levels above $zdest, a copy under $dest on $target: '' when each is there or made a holder, null to wait where a copy is $coming
// or (given $cfg) due by the schedules saved now, else why not, such as a dataset gone since. $here gains each.
function zfsmScheduleCopiesPlace($dest, $zdest, $coming, &$here, $cfg = null, $target = array('server' => '')) {
	$zdataset = substr($zdest, strlen($dest));
	$missing = null;
	foreach (zfsmScheduleCopiesLevels($dest, $zdest, $target['server'] !== '') as $level) {
		if (isset($here[$level])) continue;
		if (isset($coming[$level])) return null;
		$missing = $missing ?? zfsmDatasetMissing($zdataset);
		if ($missing !== false) return ($missing ? $zdataset.' is gone' : 'whether '.$zdataset.' is still there could not be read').', so no dataset was made to hold its copy';
		// Read again, as a run takes hours: another may have made it since, or a schedule been saved to copy there.
		$there = zfsmReplicaTargetName($target, $level);
		list($held, $unread) = zfsmReplicaTargetFilesystem($target, $there);
		if ($held === null) return 'whether '.zfsmReplicaTargetWhere($target, $there).' is there could not be read: '.$unread;
		if (!$held) {
			if ($cfg !== null && zfsmScheduleCopiesDueNow($dest, $level, $cfg, $target['server'])) return null;
			$why = zfsmReplicaHolderMake($target, $there);
			if ($why !== '') return $why;
		}
		$here[$level] = true;
	}
	return '';
}

// Whether the schedules saved now copy a dataset to $level under $dest here or on $server (zfsmScheduleCopiesTo), or that could not be read.
function zfsmScheduleCopiesDueNow($dest, $level, $cfg, $server = '') {
	$read = zfsmScheduleRead();
	return $read === null || zfsmScheduleCopiesTo($dest, $level, $read['valid'], $cfg, $server) !== array();
}

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

// The datasets $jobs copy to $level under $dest here or on $server, as zfsmScheduleCopiesComing reads a run's: its own where a run
// takes it there, and each a repeat set in Replicate sends there; null when whether a run takes it could not be read.
function zfsmScheduleCopiesTo($dest, $level, $jobs, $cfg, $server = '') {
	$repeats = zfsmScheduleCopiesRepeatsTo($jobs, $server)[$level] ?? array();
	// Copy Under and above, made on a server: no copy under it lands there.
	if (strncmp($level, $dest, strlen($dest)) !== 0) return $repeats;
	$zdataset = substr($level, strlen($dest));
	$job = zfsmScheduleFollowed($zdataset, $jobs, 'zfsmScheduleCopiesUnder');
	if (!zfsmScheduleCopiesSame(array('dest' => $dest, 'server' => $server), $job)) return $repeats;
	$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT, array('-d', '1', $zdataset));
	$row = zfsmScheduleListed($listed)[0][$zdataset] ?? null;
	if ($row === null || $row[2] === null) return null;
	return zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $listed['name']) === '' ? array_merge(array($zdataset), $repeats) : $repeats;
}
