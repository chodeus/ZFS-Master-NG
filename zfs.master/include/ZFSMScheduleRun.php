<?php
// Cron's run of one schedule (zfsmScheduleRun, from scripts/schedule.php) and its count of runs in a row with a problem.

// A run's failures in a row, in RAM: a restart counts again from none.
function zfsmScheduleStateFile($id) {
	return zfsmJobDir('schedule').'/'.$id.'.json';
}

// The runs in a row with a problem, and whether Unraid took the notification of the first; a record with no 'told' was told.
function zfsmScheduleState($id) {
	$state = json_decode((string)@file_get_contents(zfsmScheduleStateFile($id)), true);
	$failures = is_array($state) && is_int($state['failures'] ?? null) && $state['failures'] > 0 ? $state['failures'] : 0;
	return array('failures' => $failures, 'told' => ($state['told'] ?? true) !== false);
}

// As Settings and diagnostics show it: None never runs to clear a count that a run going as it was saved left.
function zfsmScheduleProblems($job) {
	return zfsmScheduleRepeats($job['every']) ? zfsmScheduleFailures($job['id']) : 0;
}

function zfsmScheduleFailures($id) {
	return zfsmScheduleState($id)['failures'];
}

// A scheduled run notifies of its first problem (a warning; the next one does if Unraid didn't take it), of the run that works
// again, and of one with $news; each problem is logged with its count. A count that can't be kept notifies of every problem, rather than of none.
function zfsmScheduleNotifier($id) {
	return function($title, $text, $type, $news = false) use ($id) {
		$state = zfsmScheduleState($id);
		$file = zfsmScheduleStateFile($id);
		if ($type === 'normal') {
			if ($state['failures'] === 0 && $news) zfsnotify($title, $text, '', 'normal');
			if ($state['failures'] === 0) return;
			@unlink($file);
			zfsnotify($title, $text.' It works again after '.$state['failures'].' run(s) with a problem.', '', 'normal');
			return;
		}
		$failures = $state['failures'] + 1;
		$told = $failures > 1 && $state['told'];
		if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
		$save = function($told) use ($file, $failures) { return zfsmWriteFileAtomic($file, zfsmJson(array('failures' => $failures, 'told' => $told))."\n"); };
		$kept = $save($told);
		zfsmLog('Schedule '.$id.': '.$failures.' run(s) in a row with a problem');
		if (!$kept) zfsnotify($title, $text, '', $type);
		elseif (!$told && zfsnotify($title, rtrim($text, '.').'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.', '', $type)) $save(true);
	};
}

// Of $datasets, each $listed (zfsmScheduleCoverRead) shows has the name of a run cron starts at $now again, as the clock went back.
function zfsmScheduleTakenBefore($cfg, $now, $datasets, $listed) {
	if (!zfsmNewSnapshotNameRepeats($cfg, $now)) return array();
	$names = array_fill_keys(zfsmLines($listed['output']), true);
	$snapshot = '@'.zfsmNewSnapshotName($cfg, $now);
	return array_values(array_filter($datasets, function($zdataset) use ($names, $snapshot) { return isset($names[$zdataset.$snapshot]); }));
}

// Datasets in a run's line: in brackets and commas, where diagnostics end a name.
function zfsmScheduleNamesSaid($datasets) {
	return '('.zfsmKeepFirst($datasets, ', ').')';
}

// A run's $problem, but null (only logged: no run that works again) when it did nothing but leave out $again.
function zfsmScheduleTakenBeforeProblem($again, $done, $problem) {
	return $again && !$done && !$problem ? null : $problem;
}

// What a run's line says of $again (zfsmScheduleTakenBefore); '' for none.
function zfsmScheduleTakenBeforeSaid($again) {
	return $again ? '. Left out, as each has a snapshot of the name this run gives, from before the clock went back '.zfsmScheduleNamesSaid($again) : '';
}

// Cron's run of one schedule: the job read by its id; a copy's run takes its snapshot first, as Replicate's "Take one now" does.
function zfsmScheduleRun($id, $cfg, $now, $wait = ZFSM_SCHEDULE_WAIT) {
	$notify = zfsmScheduleNotifier($id);
	$read = zfsmScheduleRead();
	if ($read === null) {
		zfsmLog('Schedule '.$id.' not started: '.zfsmScheduleUnreadable());
		$notify('ZFS Schedule', 'Scheduled run not started: '.zfsmSafeText(zfsmScheduleUnreadable()), 'warning');
		return 1;
	}
	$job = $read['valid'][$id] ?? null;
	// Its line outlived the schedule, or its Repeat is None now: say, the list was saved and Unraid's crontab was not rebuilt.
	if ($job === null || !zfsmScheduleRepeats($job['every'])) {
		zfsmLog('Schedule '.$id.' not started: '.($job === null ? 'Settings has no such schedule' : 'it is None now'));
		return 0;
	}
	$copy = !zfsmScheduleSnapshotsOnly($job) && !zfsmScheduleCopiesUnder($job);
	$what = $copy ? 'Replication of '.$job['dataset'].' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']) : (zfsmScheduleSnapshotsOnly($job) ? 'Scheduled snapshots' : 'Scheduled copies');
	try {
		// As zfsmScheduleTakenBefore, for the one dataset a copy's run takes.
		if ($copy && zfsmNewSnapshotNameRepeats($cfg, $now) && zfsmSnapshotExists($job['dataset'].'@'.zfsmNewSnapshotName($cfg, $now))) {
			zfsmLog('Schedule '.$id.' not started: its dataset has a snapshot of the name this run gives, from before the clock went back', LOG_NOTICE);
			return 0;
		}
		if (zfsmScheduleCopiesUnder($job)) return zfsmScheduleCopiesRun($job, $read['valid'], $cfg, $now, $notify, $wait);
		if (!$copy) return zfsmScheduleSnapshotRun($job, $read['valid'], $cfg, $now, $notify);
		$repeat = zfsmScheduleRepeatRead($job['dataset'], $cfg, ZFSM_SCHEDULE_COVER_WAIT);
		$not = $repeat['warning'] !== null ? zfsmScheduleSkippedText($repeat['warning']).'. Remove its schedule in Settings' : $repeat['unread'];
		if ($not !== null) return zfsmScheduleSnapshotTell($job, $what.' not started: '.$not, true, $notify, 'ZFS Replication');
		return zfsmScheduleLockedRun($job, 'ZFS Replication', $what.' not started: ', $notify, function() use ($job, $cfg, $now, $notify, $wait) {
			return zfsmRunReplicate($job['dataset'], zfsmNewSnapshotName($cfg, $now), $job['dest'], null, true, $job['server'], array('notify' => $notify, 'wait' => $wait, 'gone' => 'remove its schedule in Settings', 'copy_keeps' => $job['copy_keeps'] ?? null, 'remake' => ($job['remake'] ?? false) === true), $job['keep'],
				array('decrypt' => ($job['decrypted'] ?? false) === true, 'replace' => ($job['replace'] ?? false) === true));
		}, ZFSM_SCHEDULE_COPIES_GRACE, $now);
	} catch (Throwable $error) {
		// Cron drops what PHP prints: an error that ends a run is a problem like any other.
		zfsmLog($what.' failed: '.zfsmOneLine($error->getMessage()));
		$notify(zfsmScheduleSnapshotsOnly($job) ? 'ZFS Snapshot' : 'ZFS Replication', $what.' failed: '.zfsmSafeText(zfsmOneLine($error->getMessage())), 'warning');
		return 1;
	}
}

// Unraid runs it at each array start (emhttp_event disks_mounted); it starts zfsmScheduleStartRuns. Must stay executable: pkg_build.sh.
function zfsmScheduleStartHook() {
	return ($GLOBALS['docroot'] ?? '/usr/local/emhttp').'/plugins/zfs.master/event/disks_mounted';
}

// At each array start (scripts/schedule.php start): each When the array starts schedule's run, in a session of its own as cron starts
// one; none in Maintenance mode (var.ini unread: as Normal). A run that can't start is a problem of its schedule; schedules.json
// unread is logged and notified. 0, else 1.
function zfsmScheduleStartRuns() {
	$var = @parse_ini_file(zfsmStatePath('/var/local/emhttp/var.ini'));
	if (is_array($var) && ($var['startMode'] ?? '') === 'Maintenance') {
		zfsmLog('Schedules not started when the array started: it started in Maintenance mode', LOG_NOTICE);
		return 0;
	}
	$read = zfsmScheduleRead();
	if ($read === null) {
		$not = 'Schedules not read when the array started, so no run for When the array starts started: ';
		zfsmLog($not.zfsmScheduleUnreadable());
		zfsnotify('ZFS Schedule', $not.zfsmSafeText(zfsmScheduleUnreadable()), '', 'warning');
		return 1;
	}
	$code = 0;
	foreach ($read['valid'] as $id => $job) {
		if ($job['every'] !== 'start') continue;
		list($state, $printed) = zfsmStartJob('schedule.php', array('run', $id), null);
		if ($state === 'started') continue;
		$code = 1;
		zfsmLog('Schedule '.$id.' not started when the array started: could not start schedule.php'.($printed !== '' ? ' ('.$printed.')' : ''));
		zfsmScheduleNotifier($id)('ZFS Schedule', 'Scheduled run not started when the array started: could not start schedule.php', 'warning');
	}
	return $code;
}
