<?php
// A schedule's Copy Keeps (ZFSMKeepRules.php reads it, zfsmKeepCopyPrune applies it), and Settings' Change Keep of a repeat set in
// Replicate, which changes its Keep and Copy Keeps in place.

// Copy Keeps: none (its copy keeps what its source keeps), or a Keep of a copy here a schedule makes: a copy's, or copies under one.
function zfsmScheduleCopyKeepsValid($job) {
	$copy_keeps = $job['copy_keeps'] ?? null;
	return $copy_keeps === null || ($job['server'] === '' && !zfsmScheduleSnapshotsOnly($job) && zfsmScheduleRepeats($job['every']) && zfsmKeepValid($copy_keeps));
}

// $job with $copy_keeps (zfsmCopyKeepsParse's) in place of any it had, saved only when there is one.
function zfsmScheduleCopyKeepsSet($job, $copy_keeps) {
	unset($job['copy_keeps']);
	return $copy_keeps === null ? $job : $job + array('copy_keeps' => $copy_keeps);
}

// What $job's copy keeps by its Copy Keeps, after what Settings says it keeps; '' with none.
function zfsmScheduleCopyKeepsText($job) {
	$kept = zfsmCopyKeepsWords($job['copy_keeps'] ?? null);
	return $kept === '' ? '' : '; '.(zfsmScheduleCopiesUnder($job) ? 'each copy' : 'its copy').' keeps '.$kept;
}

// What the user fixes in $job's Copy Keeps, as zfsmScheduleWrong says it; '' when nothing.
function zfsmScheduleCopyKeepsWrong($job) {
	$copy_keeps = $job['copy_keeps'] ?? null;
	if ($copy_keeps === null || (zfsmKeepValid($copy_keeps) && $job['server'] === '')) return '';
	return $job['server'] !== '' ? 'leave Copy Keeps empty: what a copy on another server keeps, its Keep Replicas decides'
		: 'under Copy Keeps, give all to keep every snapshot, or '.zfsmKeepFix('keep what its source keeps');
}

// Changes the Keep and Copy Keeps of $id, a repeat set in Replicate, to $keep and $copy_keeps (zfsmKeepParse's, zfsmCopyKeepsParse's;
// -1 for one that is neither); its times and runs stay. As zfsmScheduleChange answers.
function zfsmScheduleKeepChange($id, $keep, $copy_keeps) {
	if (!zfsmScheduleId($id)) return array('saved' => false, 'why' => 'No such schedule');
	return zfsmScheduleChange(function($jobs) use ($id, $keep, $copy_keeps) {
		if (!isset($jobs[$id]) || zfsmScheduleCovers($jobs[$id])) return 'No such repeat set in Replicate; it may have been removed';
		$job = $jobs[$id];
		$job['keep'] = $keep;
		$job = zfsmScheduleCopyKeepsSet($job, $copy_keeps);
		$wrong = zfsmScheduleWrong($job);
		if ($wrong !== '') return 'Nothing was saved: '.$wrong;
		$jobs[$id] = $job;
		return $jobs;
	});
}

// Change Keep under a repeat set in Replicate in Settings' list: Snapshots To Keep, and Copy Keeps for a copy here.
function zfsmScheduleKeepHtml($job) {
	$id = $job['id'];
	$field = function($name, $label, $value, $empty) use ($id) {
		return ' <label style="white-space:nowrap">'.$label.' <input type="text" id="zfsm-keep-'.$name.'-'.$id.'" maxlength="40" placeholder="'.$empty.'" value="'.zfsmHtml($value).'"></label>';
	};
	return '<details style="margin:0 0 .5em"><summary>Change Keep</summary>'.$field('own', 'Snapshots To Keep:', zfsmKeepField($job['keep']), 'all')
		.($job['server'] === '' ? $field('copy', 'Copy Keeps:', zfsmCopyKeepsField($job['copy_keeps'] ?? null), 'same as source') : '')
		.' <input type="button" value="Save" onclick="zfsmScheduleKeepSave(\''.$id.'\')"></details>';
}
