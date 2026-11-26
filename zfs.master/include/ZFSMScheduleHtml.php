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
	$jobs = array_values($read['valid']);
	// Snapshot and Replicate Every Dataset first (dataset ''), then by dataset, its snapshots before its copies.
	usort($jobs, function($a, $b) {
		return strcmp($a['dataset'], $b['dataset']) ?: (zfsmScheduleSnapshotsOnly($b) <=> zfsmScheduleSnapshotsOnly($a)) ?: strcmp($a['server'], $b['server'])
			?: strcmp($a['dest'], $b['dest']) ?: strcmp($a['id'], $b['id']);
	});
	$rows = '';
	foreach ($jobs as $job) $rows .= zfsmScheduleRowHtml($job, $read['valid'], $cfg);
	if ($rows !== '') $html = '<div class="zfsm-table-scroll"><table class="zfsm-sched-table"><thead><tr><th>What</th><th>Dataset</th><th>Copy To</th><th>When</th><th>Keep</th><th></th></tr></thead>'
		.$rows.'</table></div>';
	if ($read['other']) $html .= '<span style="display:block;color:#e22828">'.count($read['other']).' record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use</span>';
	return $html;
}

// What Settings' row of $job shows under What, Dataset, Copy To and When, and its Keep as lines.
function zfsmScheduleCells($job) {
	$snapshots = zfsmScheduleSnapshotsOnly($job);
	$covers = zfsmScheduleCovers($job);
	$dataset = $job['dataset'] === '' ? 'Every dataset' : $job['dataset'].($covers ? ' and below' : '');
	if ($job['every'] === 'none') return array($snapshots ? 'No snapshots' : 'No copies', $dataset, '', '', array());
	$keep = array(zfsmKeepWords($job['keep']) ?: 'all');
	$copy_keeps = zfsmCopyKeepsWords($job['copy_keeps'] ?? null);
	if ($copy_keeps !== '') $keep[] = 'Copy Keeps: '.$copy_keeps;
	$to = $snapshots ? '' : ($covers ? 'under '.zfsmScheduleCopiesUnderText($job) : zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']));
	return array($snapshots ? 'Snapshots' : ($covers ? 'Copies' : 'Copy'), $dataset, $to, zfsmScheduleWhen($job), $keep);
}

// Settings' rows of $job, one of $jobs, in a tbody of its own: its cells, with what else it does as notes under Dataset and Copy To,
// and its buttons; then a line across for each warning.
function zfsmScheduleRowHtml($job, $jobs, $cfg) {
	$id = $job['id'];
	list($what, $dataset, $to, $when, $keep) = zfsmScheduleCells($job);
	// The default changes and turns off in its own row above; a dataset's goes here too, as one whose dataset is gone can't be opened.
	$buttons = zfsmScheduleKeepButton($job, $to, $when).($job['dataset'] !== '' ? ' <input type="button" value="Remove" onclick="zfsmScheduleRemove(\''.$id.'\')">' : '');
	$lines = function($lines, $class = '') {
		return implode('', array_map(function($line) use ($class) { return '<div'.$class.'>'.zfsmHtml($line).'</div>'; }, array_filter($lines, 'strlen')));
	};
	$note = ' class="zfsm-sched-note"';
	// A name may break after each / rather than widen its column past Keep's.
	$name = function($name) { return str_replace('/', '/<wbr>', zfsmHtml($name)); };
	$cells = '<td><span class="zfsm-sched-what">'.zfsmHtml($what).'</span></td><td>'.$name($dataset).$lines(array(empty($job['leave_out']) ? '' : 'Leaves out '.zfsmLeaveOutField($job)), $note).'</td>'
		.'<td>'.$name($to).$lines(array(($job['remake'] ?? false) ? 'Makes its copy again if it is gone' : '', ($job['replace'] ?? false) ? 'Replaces its copy if it no longer matches' : ''), $note).'</td>'
		.'<td>'.zfsmHtml($when).'</td><td>'.$lines($keep).'</td><td class="zfsm-sched-buttons">'.$buttons.'</td>';
	$warnings = array();
	$clash = zfsmScheduleClash($job, $jobs, $cfg, time(), false);
	// Each clash starts with the plugin's own words ("the copy to", "this schedule"), never a dataset's name.
	if ($clash !== '') $warnings[] = ucfirst($clash).', so that run fails: add the time to Snapshots Pattern';
	$failures = zfsmScheduleProblems($job);
	if ($failures > 0) $warnings[] = $failures.' run(s) in a row with a problem: the first one\'s notification and /var/log/zfs.master.log say why';
	$warnings = implode('', array_map(function($warning) { return '<tr><td colspan="6" style="color:#e22828">'.zfsmHtml($warning).'</td></tr>'; }, $warnings));
	return '<tbody'.($job['every'] === 'none' ? ' class="zfsm-sched-off"' : '').'><tr>'.$cells.'</tr>'.$warnings.'</tbody>';
}
