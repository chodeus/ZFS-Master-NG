<?php
// A schedule's Copy Keeps: ZFSMKeepRules.php reads it, zfsmKeepCopyPrune applies it.

// Copy Keeps: none (its copy keeps what its source keeps), or a Keep of a copy here a schedule makes: a copy's, or copies under one.
function zfsmScheduleCopyKeepsValid($job) {
	$copy_keeps = $job['copy_keeps'] ?? null;
	return $copy_keeps === null || ($job['server'] === '' && !zfsmScheduleSnapshotsOnly($job) && zfsmScheduleRepeats($job['every']) && zfsmKeepValid($copy_keeps));
}

// $job, which has none, with $copy_keeps (zfsmCopyKeepsParse's), saved only when there is one.
function zfsmScheduleCopyKeepsSet($job, $copy_keeps) {
	return $copy_keeps === null ? $job : $job + array('copy_keeps' => $copy_keeps);
}

// What the user fixes in $job's Copy Keeps, as zfsmScheduleWrong says it; '' when nothing.
function zfsmScheduleCopyKeepsWrong($job) {
	$copy_keeps = $job['copy_keeps'] ?? null;
	if ($copy_keeps === null || (zfsmKeepValid($copy_keeps) && $job['server'] === '')) return '';
	return $job['server'] !== '' ? 'leave Copy Keeps empty: what a copy on another server keeps, its Keep Replicas decides'
		: 'under Copy Keeps, give all to keep every snapshot, or '.zfsmKeepFix('keep what its source keeps');
}
