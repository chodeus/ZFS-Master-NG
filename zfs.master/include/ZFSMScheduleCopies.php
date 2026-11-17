<?php
// Copies under a dataset: records whose dest, a dataset here and '/', holds each covered dataset's copy (backup/ and cache/appdata:
// backup/cache/appdata). Dataset '' is Settings' Replicate Every Dataset; None is dest '/'. Coverage: zfsmScheduleFollowed.

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

// Why $job never copies $zdataset, listed as $row (zfsmScheduleListed), besides zfsmScheduleSkipped's reasons; $name: a new
// snapshot's. '' when it copies it.
function zfsmScheduleCopiesNot($job, $jobs, $zdataset, $row, $name) {
	$pool = explode('/', $job['dest'])[0];
	if (explode('/', $zdataset)[0] === $pool) return $zdataset.' is in pool '.$pool.', where its copy would go';
	if ($row[2] === null) return 'the snapshot names of '.$zdataset.' could not be read, so it may be left to Sanoid or Syncoid';
	$dest = zfsmScheduleCopiesDest($job, $zdataset);
	if (!zfsmReplicaBookmarkFits($zdataset, zfsmReplicaTargetMark(array('server' => $job['server']), $dest), $name)) return 'the names of '.$zdataset.' and its copy are too long for the bookmark a copy keeps';
	foreach ($jobs as $other) {
		if ($other['dataset'] === $zdataset && $other['dest'] === $dest && $other['server'] === $job['server']) return $zdataset.' has a schedule of its own for that copy';
	}
	return '';
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
	$job = zfsmScheduleSnapshotRecord('', $every, $time, $days, $keep);
	$job['dest'] = $under.'/';
	$wrong = !$remove && zfsmScheduleRepeats($every) && !zfsmValidDatasetName($under) ? 'under Copy Under, name a dataset on this server, such as backup' : zfsmScheduleSnapshotWrong($job, $remove);
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
	return 'Saved: a copy of '.$count.' under '.substr($job['dest'], 0, -1).' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job)
		.($unread !== '' ? '; not every dataset could be read: '.$unread : '');
}

// Cron's run of copies under a dataset: each dataset it covers, parents first, sent to its copy as a copy's schedule sends it
// (zfsmRunReplicate, with its Keep); one notification for the run. 0, or 1 after a problem.
function zfsmScheduleCopiesRun($job, $jobs, $cfg, $now, $notify, $wait = ZFSM_SCHEDULE_WAIT) {
	return zfsmScheduleLockedRun($job, 'ZFS Replication', 'Scheduled copies not made: ', $notify, function() use ($job, $jobs, $cfg, $now, $wait) {
		return zfsmScheduleCopiesMade($job, $jobs, $cfg, $now, $wait);
	});
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
	$coming = zfsmScheduleCopiesComing($job, $jobs, $datasets);
	$name = zfsmNewSnapshotName($cfg, $now);
	$made = 0;
	$problems = $waiting = array();
	foreach ($datasets as $zdataset) {
		$zdest = zfsmScheduleCopiesDest($job, $zdataset);
		$place = zfsmScheduleCopiesPlace($job, $zdest, $coming, $here);
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
	if (!$pools) return array('Scheduled copies not made: '.($unread !== '' ? 'the datasets could not be read: '.$unread : 'no pool has a dataset it copies'), $unread !== '' ? true : null);
	$not = zfsmScheduleCopiesUnderNot($under, $listed);
	return $not !== '' ? array('Scheduled copies not made: '.$not, true) : null;
}

// Where a copy is due here: each this run makes, and each a copy's own schedule makes, which a holder there would refuse.
function zfsmScheduleCopiesComing($job, $jobs, $datasets) {
	$coming = array_fill_keys(array_map(function($zdataset) use ($job) { return zfsmScheduleCopiesDest($job, $zdataset); }, $datasets), true);
	foreach ($jobs as $other) {
		if (!zfsmScheduleCovers($other) && $other['server'] === '') $coming[$other['dest']] = true;
	}
	return $coming;
}

// One dataset's copy (zfsmRunReplicate): array(its code, array(its text, type)); an error ends only this one. $wait: what the run has
// left to wait, less what this one waits.
function zfsmScheduleCopiesOne($job, $zdataset, $zdest, $name, &$wait) {
	$said = array('', 'normal');
	$tell = function($title, $text, $type) use (&$said) { $said = array(rtrim($text, '.'), $type); };
	$waited = function($seconds) use (&$wait) { $wait = max(0, $wait - $seconds); };
	try {
		$code = zfsmRunReplicate($zdataset, $name, $zdest, null, true, '', array('notify' => $tell, 'wait' => $wait, 'waited' => $waited, 'tag' => zfsmKeepTag('', $job['dest'])), $job['keep']);
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

// The datasets above $zdest, below $job's dest: '' when each is there or made a holder, null to wait where a copy is $coming,
// else why a holder could not be made. $here gains each holder.
function zfsmScheduleCopiesPlace($job, $zdest, $coming, &$here) {
	$level = substr($job['dest'], 0, -1);
	foreach (array_slice(explode('/', substr($zdest, strlen($job['dest']))), 0, -1) as $part) {
		$level .= '/'.$part;
		if (isset($here[$level])) continue;
		if (isset($coming[$level])) return null;
		$why = zfsmReplicaHolderMake($level);
		if ($why !== '') return $why;
		$here[$level] = true;
	}
	return '';
}
