<?php
// Main's Schedules dialog (Actions, Schedules): a dataset's own snapshot schedule (ZFSMScheduleSnapshot.php) and its own copies
// under a dataset (ZFSMScheduleCopies.php), each Default (what it follows), None or a repeat, saved in one change.

// $job, a dataset's own schedule of either kind, as zfsmRepeatHtml shows it: 'default' with none.
function zfsmScheduleDialogFields($job) {
	$job = $job ?: array('every' => 'default', 'minute' => 0, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0);
	return array('every' => $job['every'], 'time' => $job['every'] === 'days' ? sprintf('%02d:%02d', $job['hour'], $job['minute']) : '00:00',
		'days' => $job['days'], 'keep' => zfsmKeepField($job['keep']));
}

// The dialog's start: $zdataset's own snapshot schedule, what Default follows, why no run takes it ('' when one can), and its copies
// (zfsmScheduleDialogCopies); from a read of the schedules, and of it, the datasets just below it and their snapshot names.
function zfsmScheduleDialogPreview($zdataset, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	if (!zfsmValidDatasetName($zdataset)) return zfsmFailure('request', 'Invalid dataset name');
	$read = zfsmScheduleRead();
	if ($read === null) return zfsmFailure($zdataset, zfsmScheduleUnreadable());
	$own = zfsmScheduleOwn($read['valid'], $zdataset);
	$listed = zfsmScheduleCoverRead($cfg, $wait, array('-d', '1', $zdataset));
	return zfsmScheduleDialogFields(reset($own)) + array('inherited' => zfsmScheduleFollowedText(zfsmScheduleFollowed($zdataset, array_diff_key($read['valid'], $own))),
		'skipped' => zfsmScheduleSnapshotNot($zdataset, $listed), 'copies' => zfsmScheduleDialogCopies($zdataset, $read['valid'], $listed));
}

// The dialog's Replication: $zdataset's own copies, Copy Under (its own, else what Default follows), what Default follows, and why
// what it follows leaves it out besides the reasons Snapshots shows (zfsmScheduleCopiesNot); '' when it copies it or nothing does.
function zfsmScheduleDialogCopies($zdataset, $jobs, $listed) {
	$own = zfsmScheduleOwn($jobs, $zdataset, 'zfsmScheduleCopiesUnder');
	$job = reset($own) ?: null;
	$followed = zfsmScheduleFollowed($zdataset, array_diff_key($jobs, $own), 'zfsmScheduleCopiesUnder');
	$under = $job !== null && $job['every'] !== 'none' ? $job : $followed;
	$runs = $job ?? $followed;
	$row = zfsmScheduleListed($listed)[0][$zdataset] ?? null;
	$skipped = $runs !== null && $runs['every'] !== 'none' && $row !== null ? zfsmScheduleCopiesNot($runs, $jobs, $zdataset, $row, $listed['name']) : '';
	return zfsmScheduleDialogFields($job) + array('under' => $under !== null && $under['every'] !== 'none' ? substr($under['dest'], 0, -1) : '',
		'inherited' => zfsmScheduleCopiesFollowedText($followed), 'skipped' => $skipped);
}

// Saves $zdataset's own snapshot schedule ($snapshots: every, time, days, keep) and its own copies ($copies: those and under) in one
// change, 'default' removing either. Nothing is taken or copied now. As zfsmScheduleChange answers, and 'said' of both.
function zfsmScheduleDialogSave($zdataset, $snapshots, $copies, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	if (!zfsmValidDatasetName($zdataset)) return array('saved' => false, 'why' => 'Nothing was saved: that is no dataset name', 'said' => '');
	$snap = zfsmScheduleSnapshotRecord($zdataset, $snapshots['every'], $snapshots['time'], $snapshots['days'], $snapshots['keep']);
	$copy = zfsmScheduleCopiesRecord($zdataset, $copies['every'], $copies['under'], $copies['time'], $copies['days'], $copies['keep']);
	$drop = array($snapshots['every'] === 'default', $copies['every'] === 'default');
	$wrong = zfsmScheduleDialogWhy(zfsmScheduleSnapshotWrong($snap, $drop[0]), zfsmScheduleCopiesWrong($copy, $drop[1]));
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	// One zfsmScheduleCoverRead: each that repeats must be one a run can take, and the answer counts what it takes.
	$repeats = array(zfsmScheduleRepeats($snap['every']), zfsmScheduleRepeats($copy['every']));
	$listed = $repeats[0] || $repeats[1] ? zfsmScheduleCoverRead($cfg, $wait) : null;
	$after = array();
	$changed = zfsmScheduleSnapshotChange(function($all) use (&$snap, &$copy, $drop, $repeats, $listed, $cfg) {
		$not = zfsmScheduleDialogWhy($repeats[0] ? zfsmScheduleSnapshotNot($snap['dataset'], $listed) : '', $repeats[1] ? zfsmScheduleCopiesOwnNot($copy, $all, $listed) : '');
		if ($not !== '') return 'Nothing was saved: '.$not;
		// The copies record this replaces sits out the snapshots' checks, so the pair is checked as saved.
		$all = zfsmScheduleSnapshotPut($all, $snap, $drop[0], $cfg, 'Snapshots: ', zfsmScheduleOwn($all, $copy['dataset'], zfsmScheduleKindOf($copy)));
		return is_array($all) ? zfsmScheduleSnapshotPut($all, $copy, $drop[1], $cfg, 'Replication: ') : $all;
	}, $after);
	if (!$changed['saved']) return $changed + array('said' => '');
	return $changed + array('said' => zfsmScheduleSnapshotSaid($snap, $drop[0], $after, $listed).'. '.zfsmScheduleCopiesOwnSaid($copy, $drop[1], $after, $listed));
}

// What stops Save, by part; '' when nothing does.
function zfsmScheduleDialogWhy($snapshots, $copies) {
	return implode('; ', array_filter(array($snapshots !== '' ? 'Snapshots: '.$snapshots : '', $copies !== '' ? 'Replication: '.$copies : ''), 'strlen'));
}
