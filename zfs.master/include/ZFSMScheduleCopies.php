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

// Why no run copies $job to its server: one not in Replicate To Other Servers, or the list of them unread; '' here or for one there.
function zfsmScheduleCopiesServerNot($job) {
	$remote = $job['server'] === '' ? null : zfsmReplicaRemote($job['server']);
	return is_string($remote) ? $remote : '';
}

// Saves Replicate Every Dataset in place (zfsmScheduleSnapshotPut), copying under $under here or on $server, one in Replicate To
// Other Servers, which is not asked now; 'off' removes it. Nothing is copied now. As zfsmScheduleChange answers, and 'said'.
function zfsmScheduleCopiesSave($under, $every, $time, $days, $keep, $cfg, $copy_keeps = null, $server = '', $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	$remove = $every === 'off';
	$job = zfsmScheduleCopiesRecord('', $every, $under, $time, $days, $keep, $copy_keeps, $server);
	$untrusted = $remove ? '' : zfsmScheduleCopiesServerNot($job);
	if ($untrusted !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$untrusted, 'said' => '');
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

// Why $job, a dataset's own copies under a dataset, can't copy its dataset, from $listed (zfsmScheduleCoverRead) and $jobs: its server
// (zfsmScheduleCopiesServerNot), Copy Under or the dataset not there or not read, or one it leaves out (zfsmScheduleSkipped, zfsmScheduleCopiesNot); '' when it can.
function zfsmScheduleCopiesOwnNot($job, $jobs, $listed) {
	$not = zfsmScheduleCopiesServerNot($job);
	if ($not === '') $not = zfsmScheduleCopiesUnderNot($job, $listed);
	if ($not === '') $not = zfsmScheduleSnapshotNot($job['dataset'], $listed);
	return $not !== '' ? $not : zfsmScheduleCopiesNot($job, $jobs, $job['dataset'], zfsmScheduleListed($listed)[0][$job['dataset']], $listed['name']);
}

// The dialog Save's answer for $job, a dataset's own copies under a dataset ($remove: gone), with $jobs as saved; one that runs is
// counted in $listed.
function zfsmScheduleCopiesOwnSaid($job, $remove, $jobs, $listed) {
	if ($remove) return 'Saved: the copies of '.$job['dataset'].' follow '.zfsmScheduleCopiesFollowedText(zfsmScheduleFollowed($job['dataset'], $jobs, 'zfsmScheduleCopiesUnder'));
	if ($job['every'] === 'none') return 'Saved: no scheduled copies of '.$job['dataset'].' and below, but for datasets with their own and repeats set in Replicate; the copies made stay';
	// Copies as saved aren't checked again (zfsmScheduleDialogSave), so a server removed since is said here.
	$server = zfsmScheduleCopiesServerNot($job);
	$down = $server !== '' ? lcfirst($server) : zfsmScheduleCopiesUnderNot($job, $listed);
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
