<?php
// Settings' schedule HTML (ZFSMasterSettings.page): a row's Days boxes, and the list of schedules.

// Settings' Days boxes of the row whose ids start $prefix, $days ticked.
function zfsmScheduleDaysHtml($prefix, $days) {
	$html = '';
	foreach (array(1, 2, 3, 4, 5, 6, 0) as $day) {
		$html .= '<label style="display:inline-block;margin-right:.8em;white-space:nowrap"><input type="checkbox" class="'.$prefix.'-day" value="'.$day.'"'.(in_array($day, $days, true) ? ' checked' : '').'> '.substr(ZFSM_SCHEDULE_DAYS[$day], 0, 3).'</label>';
	}
	return '<span id="'.$prefix.'-days" role="group" aria-label="Days">'.$html.'</span>';
}

// Settings' list; $cfg for Snapshots Pattern, which can change after a schedule was saved.
function zfsmSchedulesHtml($cfg) {
	$read = zfsmScheduleRead();
	if ($read === null) return '<span style="color:#e22828">'.zfsmHtml(zfsmScheduleUnreadable()).'</span>';
	$html = $read['valid'] ? '' : 'None yet';
	foreach ($read['valid'] as $job) {
		$failures = zfsmScheduleProblems($job);
		$clash = zfsmScheduleClash($job, $read['valid'], $cfg, time(), false);
		$text = zfsmScheduleSnapshotsOnly($job) ? zfsmScheduleSnapshotText($job) : (zfsmScheduleCopiesUnder($job) ? zfsmScheduleCopiesText($job)
			: $job['dataset'].' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']).', '.zfsmScheduleWhen($job).', '.zfsmScheduleKeepText($job));
		// The default changes and turns off in its own row above; a dataset's goes here too, as one whose dataset is gone can't be opened.
		$html .= '<span style="display:block">'.zfsmHtml($text)
			.($job['dataset'] !== '' ? ' <input type="button" value="Remove" onclick="zfsmScheduleRemove(\''.$job['id'].'\')">' : '')
			.($clash !== '' ? ' <span style="color:#e22828">'.zfsmHtml($clash).', so that run fails: add the time to Snapshots Pattern</span>' : '')
			.($failures > 0 ? ' <span style="color:#e22828">'.$failures.' run(s) in a row with a problem: the first one\'s notification and /var/log/zfs.master.log say why</span>' : '').'</span>'.zfsmScheduleKeepHtml($job);
	}
	if ($read['other']) $html .= '<span style="display:block;color:#e22828">'.count($read['other']).' record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use</span>';
	return $html;
}
