<?php
// Snapshot schedules: records in schedules.json with no copy (dest and server ''). Dataset '' is the default, Settings' Snapshot
// Every Dataset: which datasets it covers, saving it, and cron's run of it (zfsmScheduleRun).

function zfsmScheduleSnapshotsOnly($job) {
	return $job['dest'] === '';
}

// What Settings' Snapshot Every Dataset row shows: the default (an hourly one with Off's Starts At, which it doesn't use), or Off
// when there is none or schedules.json can't be read (the list under it says so).
function zfsmScheduleSnapshotShown() {
	$off = array('every' => 'off', 'minute' => 0, 'hour' => 3, 'days' => range(0, 6), 'keep' => 0);
	foreach (zfsmScheduleRead()['valid'] ?? array() as $job) {
		if (zfsmScheduleSnapshotsOnly($job)) return $job['every'] === 'hourly' ? array('minute' => $off['minute'], 'hour' => $off['hour']) + $job : $job;
	}
	return $off;
}

define('ZFSM_SCHEDULE_COVERED', array('list', '-H', '-o', 'name,readonly,mountpoint', '-t', 'filesystem,volume'));
// Save's and a run's reads of ZFSM_SCHEDULE_COVERED stop here: a hung pool would hold the request, or the run and its lock, for good.
define('ZFSM_SCHEDULE_COUNT_WAIT', 10);
define('ZFSM_SCHEDULE_COVER_WAIT', 30);

// From $listed, zfs's answer to ZFSM_SCHEDULE_COVERED: by pool, each dataset but read-only ones (a copy received here: a snapshot
// stops the next receive) and those mounted legacy (Docker's layers); and why some weren't read ('' when all were).
function zfsmScheduleCovered($listed) {
	$pools = $errors = array();
	foreach (zfsmLines($listed['output'] ?? '') as $line) {
		$parts = explode("\t", $line, 3);
		if (count($parts) !== 3 || !zfsmValidDatasetName($parts[0])) $errors[] = $line;
		elseif ($parts[1] !== 'on' && $parts[2] !== 'legacy') $pools[explode('/', $parts[0])[0]][] = $parts[0];
	}
	// zfs lists what it can read, and fails for the rest.
	if (($listed['code'] ?? 1) === 0) return array($pools, '');
	return array($pools, $errors ? zfsmKeepFirst($errors) : zfsmCommandError($listed, 'zfs list failed'));
}

// Settings' Snapshot Every Dataset ($every 'off' turns it off), changed in place: its id, so its count of runs with a problem,
// and an hourly one's minute stay. Nothing is taken now: the first run is at its time. As zfsmScheduleChange answers, and 'said'.
function zfsmScheduleSnapshotSave($every, $time, $days, $keep, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	$job = array('id' => '00000000', 'dataset' => '', 'dest' => '', 'server' => '', 'every' => $every) + zfsmScheduleAt($every, $time, $days) + array('keep' => $keep);
	$wrong = $every === 'off' ? '' : zfsmScheduleWrong($job);
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	$removed = array();
	$changed = zfsmScheduleChange(function($jobs) use (&$job, &$removed, $cfg) {
		$old = array_filter($jobs, 'zfsmScheduleSnapshotsOnly');
		$jobs = array_diff_key($jobs, $old);
		$was = reset($old);
		$job['id'] = $was ? $was['id'] : zfsmScheduleNewId($old + $jobs);
		$removed = array_diff(array_keys($old), $job['every'] === 'off' ? array() : array($job['id']));
		if ($job['every'] === 'off') return $jobs;
		if ($job['every'] === 'hourly') $job['minute'] = zfsmScheduleFreeMinute($jobs, $job, $was && $was['every'] === 'hourly' ? $was['minute'] : null);
		$same = zfsmScheduleSame($jobs, $job);
		if ($same !== '') return 'Nothing was saved: '.$same;
		$clash = zfsmScheduleClash($job, $jobs, $cfg, time(), false);
		if ($clash !== '') return 'Nothing was saved: '.$clash.'; add the time to Snapshots Pattern';
		return $jobs + array($job['id'] => $job);
	});
	if (!$changed['saved']) return $changed + array('said' => '');
	foreach ($removed as $id) @unlink(zfsmScheduleStateFile($id));
	if ($job['every'] === 'off') return $changed + array('said' => 'Snapshot Every Dataset is off; the snapshots it took stay');
	list($pools, $unread) = zfsmScheduleCovered(zfsmReadWithin($wait, 'zfs', ZFSM_SCHEDULE_COVERED));
	$count = $unread !== '' && !$pools ? 'each dataset' : 'each of '.array_sum(array_map('count', $pools)).' dataset(s)';
	return $changed + array('said' => 'Saved: a snapshot of '.$count.' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job)
		.($unread !== '' ? '; not every dataset could be read: '.$unread : ''));
}

// Takes $name of each of $datasets (one pool's) with the snapshots tag in one command, all at one moment; when that fails (one name
// already taken fails all), one at a time. The datasets taken, and each snapshot not taken with why.
function zfsmScheduleSnapshotTake($datasets, $name) {
	$take = function($snapshots) { return runProcess('zfs', array_merge(array('snapshot', '-o', ZFSM_KEEP_TAG.'='.ZFSM_KEEP_SNAPSHOTS), $snapshots)); };
	$all = $take(array_map(function($zdataset) use ($name) { return $zdataset.'@'.$name; }, $datasets));
	if (($all['code'] ?? 1) === 0) return array($datasets, array());
	$taken = $failed = array();
	foreach ($datasets as $zdataset) {
		$one = $take(array($zdataset.'@'.$name));
		if (($one['code'] ?? 1) === 0) $taken[] = $zdataset;
		else $failed[] = $zdataset.'@'.$name.' ('.zfsmCommandError($one, 'zfs snapshot failed').')';
	}
	return array($taken, $failed);
}

// After a run took a snapshot of $taken (datasets of $pool): of each one's snapshots with the snapshots tag past the newest $keep,
// up to ZFSM_KEEP_PER_RUN go (zfsmKeepPast, zfsmKeepRemove). Each that stayed, and why.
function zfsmScheduleSnapshotKeep($pool, $taken, $keep) {
	if ($keep <= 0 || !$taken) return array();
	$by = zfsmKeepSnapshotsOf($taken);
	if (is_string($by)) return array('the snapshots of '.$pool.' (they could not be read: '.$by.')');
	$stayed = array();
	foreach ($taken as $zdataset) $stayed = array_merge($stayed, zfsmKeepRemove(zfsmKeepPast($by[$zdataset] ?? array(), $keep, ZFSM_KEEP_SNAPSHOTS), array(), 'past the newest '.$keep.' its schedule keeps'));
	return $stayed;
}

// Cron's run of the default: a snapshot of every dataset it covers, then its Keep; logged, and given to $notify. 0, or 1 after a
// problem.
function zfsmScheduleSnapshotRun($job, $cfg, $now, $notify, $wait = ZFSM_SCHEDULE_COVER_WAIT) {
	// One at a time: a run still going at the next one's time (a long Keep, a hung pool) stops that one, so runs don't pile up.
	$lock = zfsmJobLock(zfsmJobDir('schedule').'/'.$job['id'].'.lock');
	if (!is_resource($lock)) return zfsmScheduleSnapshotTell($job, 'Scheduled snapshots not taken: '.($lock === null ? 'the lock file could not be opened' : 'the run before is still going'), true, $notify);
	try {
		list($text, $problem) = zfsmScheduleSnapshotsTaken($job, $cfg, $now, $wait);
	} finally {
		zfsmJobUnlock($lock);
	}
	return zfsmScheduleSnapshotTell($job, $text, $problem, $notify);
}

// What a run took and kept, and whether it had a problem: null when zfs read every pool and none had a dataset to take.
function zfsmScheduleSnapshotsTaken($job, $cfg, $now, $wait) {
	list($pools, $unread) = zfsmScheduleCovered(zfsmReadWithin($wait, 'zfs', ZFSM_SCHEDULE_COVERED));
	if (!$pools) return $unread !== '' ? array('Scheduled snapshots not taken: the datasets could not be read: '.$unread, true) : array('Scheduled snapshots not taken: no pool has a dataset it takes', null);
	$name = zfsmNewSnapshotName($cfg, $now);
	$taken = 0;
	$failed = $stayed = array();
	foreach ($pools as $pool => $datasets) {
		list($took, $not) = zfsmScheduleSnapshotTake($datasets, $name);
		$taken += count($took);
		$failed = array_merge($failed, $not);
		$stayed = array_merge($stayed, zfsmScheduleSnapshotKeep($pool, $took, $job['keep']));
	}
	return array('Scheduled snapshots of '.$taken.' of '.($taken + count($failed)).' dataset(s) taken'.($unread !== '' ? '. Not every dataset could be read: '.$unread : '')
		.($failed ? '. Could not take '.zfsmKeepFirst($failed) : '').($stayed ? '. '.zfsmKeepStayed('Keep', $stayed, 'run') : ''), $unread !== '' || $failed || $stayed);
}

// Logs a run's $text and has $notify tell it; 1 for a problem. A null $problem (nothing to take) is only logged: no run that works again.
function zfsmScheduleSnapshotTell($job, $text, $problem, $notify) {
	zfsmLog('Schedule '.$job['id'].': '.$text, $problem ? LOG_WARNING : LOG_NOTICE);
	if ($problem !== null) $notify('ZFS Snapshot', zfsmSafeText($text).'.', $problem ? 'warning' : 'normal');
	return $problem ? 1 : 0;
}
