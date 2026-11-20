<?php
// Main's Schedules dialog (Actions, Schedules): a dataset's own snapshot schedule (ZFSMScheduleSnapshot.php) and its own copies
// under a dataset (ZFSMScheduleCopies.php), here or on a trusted server, each Default (what it follows), None or a repeat, saved in one change.

// $job, a dataset's own schedule of either kind, as zfsmRepeatHtml shows it: 'default' with none.
function zfsmScheduleDialogFields($job) {
	$job = $job ?: array('every' => 'default', 'minute' => 0, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);
	return array('every' => $job['every'], 'time' => $job['every'] === 'days' ? sprintf('%02d:%02d', $job['hour'], $job['minute']) : '00:00',
		'days' => $job['days'], 'keep' => zfsmKeepField($job['keep']));
}

// The dialog's start: $zdataset's own snapshot schedule, what Default follows, its warning (zfsmScheduleSkippedWarning) or why no run takes
// it ('' when one can), and its copies (zfsmScheduleDialogCopies); from a read of the schedules, it, its children and their snapshot names.
function zfsmScheduleDialogPreview($zdataset, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	if (!zfsmValidDatasetName($zdataset)) return zfsmFailure('request', 'Invalid dataset name');
	$read = zfsmScheduleRead();
	if ($read === null) return zfsmFailure($zdataset, zfsmScheduleUnreadable());
	$own = zfsmScheduleOwn($read['valid'], $zdataset);
	$listed = zfsmScheduleCoverRead($cfg, $wait, array('-d', '1', $zdataset));
	$row = zfsmScheduleListed($listed)[0][$zdataset] ?? null;
	return zfsmScheduleDialogFields(reset($own)) + array('inherited' => zfsmScheduleFollowedText(zfsmScheduleFollowed($zdataset, array_diff_key($read['valid'], $own))),
		'warning' => $row === null ? null : zfsmScheduleSkippedWarning($zdataset, $row), 'skipped' => $row === null ? zfsmScheduleSnapshotNot($zdataset, $listed) : '',
		'copies' => zfsmScheduleDialogCopies($zdataset, $read['valid'], $listed));
}

// The dialog's Replication: $zdataset's own copies, their Copy Keeps, Copy Under and Server (its own, else what Default follows), the servers
// (zfsmReplicaServerNames; why unread), what Default follows, and why what runs leaves it out past Snapshots' reasons, its server too.
function zfsmScheduleDialogCopies($zdataset, $jobs, $listed) {
	$own = zfsmScheduleOwn($jobs, $zdataset, 'zfsmScheduleCopiesUnder');
	$job = reset($own) ?: null;
	$followed = zfsmScheduleFollowed($zdataset, array_diff_key($jobs, $own), 'zfsmScheduleCopiesUnder');
	// A None record's dest and server are '/' and '' too.
	$where = ($job !== null && $job['every'] !== 'none' ? $job : $followed) ?? array('dest' => '/', 'server' => '');
	$runs = $job ?? $followed;
	$row = zfsmScheduleListed($listed)[0][$zdataset] ?? null;
	$skipped = $runs !== null && $runs['every'] !== 'none' && $row !== null ? (zfsmScheduleCopiesServerNot($runs) ?: zfsmScheduleCopiesNot($runs, $jobs, $zdataset, $row, $listed['name'])) : '';
	$remotes = zfsmReplicaRemotes();
	return zfsmScheduleDialogFields($job) + array('copy_keeps' => zfsmCopyKeepsField($job['copy_keeps'] ?? null), 'under' => substr($where['dest'], 0, -1),
		'server' => $where['server'], 'servers' => zfsmReplicaServerNames($where['server'], $remotes), 'inherited' => zfsmScheduleCopiesFollowedText($followed), 'skipped' => $skipped)
		+ ($remotes === null ? array('servers_error' => zfsmReplicaRemotesUnreadable()) : array());
}

// Saves $zdataset's own snapshot schedule ($snapshots: every, time, days, keep) and its own copies ($copies: those, copy_keeps, under and server) in one
// change, 'default' removing either. Nothing is taken or copied now. As zfsmScheduleChange answers, and 'said' of both.
function zfsmScheduleDialogSave($zdataset, $snapshots, $copies, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	if (!zfsmValidDatasetName($zdataset)) return array('saved' => false, 'why' => 'Nothing was saved: that is no dataset name', 'said' => '');
	$snap = zfsmScheduleSnapshotRecord($zdataset, $snapshots['every'], $snapshots['time'], $snapshots['days'], $snapshots['keep']);
	$copy = zfsmScheduleCopiesRecord($zdataset, $copies['every'], $copies['under'], $copies['time'], $copies['days'], $copies['keep'], $copies['copy_keeps'], $copies['server']);
	$drop = array($snapshots['every'] === 'default', $copies['every'] === 'default');
	$wrong = zfsmScheduleDialogWhy(zfsmScheduleSnapshotWrong($snap, $drop[0]), zfsmScheduleCopiesWrong($copy, $drop[1]));
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	// One zfsmScheduleCoverRead: each that repeats must be one a run can take, and the answer counts what it takes.
	$repeats = array(zfsmScheduleRepeats($snap['every']), zfsmScheduleRepeats($copy['every']));
	$listed = $repeats[0] || $repeats[1] ? zfsmScheduleCoverRead($cfg, $wait) : null;
	$after = array();
	$changed = zfsmScheduleSnapshotChange(function($all) use (&$snap, &$copy, $drop, $repeats, $listed, $cfg) {
		// A part as saved isn't checked again, so what changed since (a Copy Under offline, Sanoid snapshots, Snapshots Pattern) doesn't
		// stop the other's change.
		$check = array(!$drop[0] && !zfsmScheduleAsSaved($snap, $all), !$drop[1] && !zfsmScheduleAsSaved($copy, $all));
		$named = zfsmNewSnapshotNamesWhy($cfg, time());
		$not = zfsmScheduleDialogWhy($repeats[0] && $check[0] ? (zfsmScheduleSnapshotNot($snap['dataset'], $listed) ?: $named) : '',
			$repeats[1] && $check[1] ? (zfsmScheduleCopiesOwnNot($copy, $all, $listed) ?: $named) : '');
		if ($not !== '') return 'Nothing was saved: '.$not;
		$was = $all;
		// An hourly part goes in second, so its minute is picked clear of the other's time.
		if ($snap['every'] === 'hourly') $all = zfsmScheduleSnapshotPlace(zfsmScheduleSnapshotPlace($all, $copy, $drop[1]), $snap, $drop[0]);
		else $all = zfsmScheduleSnapshotPlace(zfsmScheduleSnapshotPlace($all, $snap, $drop[0]), $copy, $drop[1]);
		// Both placed, so the pair is checked as saved: by the copies when they are checked.
		$why = zfsmScheduleDialogWhy($check[0] ? zfsmScheduleSnapshotCheck($all, $snap, $cfg, $check[1] ? array($copy['id'] => 0) : array()) : '',
			$check[1] ? zfsmScheduleSnapshotCheck($all, $copy, $cfg) : '');
		// A part set to Default hands its datasets to the one above, which may then clash with one as saved.
		if ($why === '' && ($drop[0] || $drop[1])) $why = zfsmScheduleNewClash($was, $all, $cfg);
		return $why === '' ? $all : 'Nothing was saved: '.$why;
	}, $after);
	if (!$changed['saved']) return $changed + array('said' => '');
	return $changed + array('said' => zfsmScheduleSnapshotSaid($snap, $drop[0], $after, $listed).'. '.zfsmScheduleCopiesOwnSaid($copy, $drop[1], $after, $listed));
}

// What stops Save, by part, a reason both give said once; '' when nothing does.
function zfsmScheduleDialogWhy($snapshots, $copies) {
	if ($snapshots !== '' && $snapshots === $copies) return $snapshots;
	return implode('. ', array_filter(array($snapshots !== '' ? 'Snapshots: '.$snapshots : '', $copies !== '' ? 'Replication: '.$copies : ''), 'strlen'));
}
