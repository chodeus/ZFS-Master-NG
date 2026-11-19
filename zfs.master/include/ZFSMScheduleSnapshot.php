<?php
// Snapshot schedules: schedules.json records with no copy (dest, server ''). Dataset '' is Settings' Snapshot Every Dataset; a
// dataset's own (Main, Actions, Schedules) or None covers it and those below with none of their own (zfsmScheduleFollowed).
// Own, Followed, Covered, Put, the rename move and the locked run serve copies under a dataset (ZFSMScheduleCopies.php) too, by kind.

function zfsmScheduleSnapshotsOnly($job) {
	return $job['dest'] === '';
}

// The default runs every hour or on days; a dataset's own can also be None.
function zfsmScheduleSnapshotValid($job) {
	$every = $job['every'] ?? null;
	$dataset = $job['dataset'] ?? null;
	if (($job['server'] ?? null) !== '') return false;
	return $dataset === '' ? zfsmScheduleRepeats($every) : zfsmValidDatasetName($dataset) && (zfsmScheduleRepeats($every) || $every === 'none');
}

// Whether $job covers datasets below its own: a snapshot schedule or copies under a dataset, not one copy's.
function zfsmScheduleCovers($job) {
	return zfsmScheduleSnapshotsOnly($job) || zfsmScheduleCopiesUnder($job);
}

// The test of the kind of $job, one that covers (zfsmScheduleCovers): each dataset follows one of each kind.
function zfsmScheduleKindOf($job) {
	return zfsmScheduleCopiesUnder($job) ? 'zfsmScheduleCopiesUnder' : 'zfsmScheduleSnapshotsOnly';
}

// $jobs' schedule of $kind of $zdataset itself, by id: one or none.
function zfsmScheduleOwn($jobs, $zdataset, $kind = 'zfsmScheduleSnapshotsOnly') {
	return array_filter($jobs, function($job) use ($zdataset, $kind) { return $kind($job) && $job['dataset'] === $zdataset; });
}

// Whether $job, a dataset's own schedule, is the one of its kind $jobs hold, but for what Save sets: the id, an hourly one's minute.
function zfsmScheduleAsSaved($job, $jobs) {
	$set = array('id' => 0) + ($job['every'] === 'hourly' ? array('minute' => 0) : array());
	foreach (zfsmScheduleOwn($jobs, $job['dataset'], zfsmScheduleKindOf($job)) as $was) return array_diff_key($was, $set) == array_diff_key($job, $set);
	return false;
}

// The schedule of $kind $zdataset follows: its own, else the one of the nearest dataset above it with one, else the default; null
// when none of these is there.
function zfsmScheduleFollowed($zdataset, $jobs, $kind = 'zfsmScheduleSnapshotsOnly') {
	$by = array_column(array_filter($jobs, $kind), null, 'dataset');
	for ($name = $zdataset; $name !== ''; $name = (string)substr($name, 0, (int)strrpos($name, '/'))) {
		if (isset($by[$name])) return $by[$name];
	}
	return $by[''] ?? null;
}

// What following $job means, for the dialog's Default and Save's answer.
function zfsmScheduleFollowedText($job) {
	if ($job === null) return 'Snapshot Every Dataset, which is off: no scheduled snapshots';
	$whose = zfsmScheduleWhat($job);
	return $job['every'] === 'none' ? $whose.', None: no scheduled snapshots' : $whose.', '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job);
}

// Settings' row of a snapshot schedule.
function zfsmScheduleSnapshotText($job) {
	if ($job['every'] === 'none') return 'No scheduled snapshots of '.$job['dataset'].' and below';
	return ($job['dataset'] === '' ? 'Snapshot Every Dataset' : 'Snapshots of '.$job['dataset'].' and below').', '.zfsmScheduleWhen($job).', '.zfsmScheduleKeepText($job);
}

// What Settings' row of the default of $kind shows: it (an hourly one with Off's Starts At, which it doesn't use), or Off when there
// is none or schedules.json can't be read (the list under it says so).
function zfsmScheduleShown($kind = 'zfsmScheduleSnapshotsOnly') {
	$off = array('every' => 'off', 'dest' => '', 'minute' => 0, 'hour' => 3, 'days' => range(0, 6), 'keep' => 0);
	$job = zfsmScheduleFollowed('', zfsmScheduleRead()['valid'] ?? array(), $kind);
	if ($job === null) return $off;
	return $job['every'] === 'hourly' ? array('minute' => $off['minute'], 'hour' => $off['hour']) + $job : $job;
}

define('ZFSM_SCHEDULE_COVERED', array('list', '-H', '-o', 'name,readonly,mountpoint', '-t', 'filesystem,volume'));
// Names alone, sorted by name: zfs then lists snapshots without reading their properties, fast with thousands of them.
define('ZFSM_SCHEDULE_SNAPSHOTS', array('list', '-H', '-o', 'name', '-s', 'name', '-t', 'snapshot'));
// Each read of zfsmScheduleCoverRead for Save, the dialog and a run stops here: a hung pool would hold the request, or the run and its
// lock, for good.
define('ZFSM_SCHEDULE_COUNT_WAIT', 10);
define('ZFSM_SCHEDULE_COVER_WAIT', 30);

// What a schedule covers, as one answer for zfsmScheduleListed: the datasets, their snapshots' names (both read with $more), the names
// read's exit code, and $cfg's Snapshots Prefix and a new snapshot's name. $read runs as zfsmReadWithin does.
function zfsmScheduleCoverRead($cfg, $wait, $more = array(), $read = 'zfsmReadWithin') {
	$listed = $read($wait, 'zfs', array_merge(ZFSM_SCHEDULE_COVERED, $more)) + array('prefix' => (string)($cfg['snap_prefix'] ?? ''), 'name' => zfsmNewSnapshotName($cfg, time()));
	// None listed (a hung pool waits its whole limit): no dataset for the names to mark, so no second wait.
	if (!zfsmScheduleListed($listed)[0]) return $listed;
	// Names it can't read mark none: a snapshot schedule still takes those datasets, copies leave them out (zfsmScheduleCopiesNot).
	$names = $read($wait, 'zfs', array_merge(ZFSM_SCHEDULE_SNAPSHOTS, $more));
	return array('code' => $listed['code'] !== 0 ? $listed['code'] : $names['code'], 'output' => $listed['output']."\n".$names['output'], 'names' => $names['code']) + $listed;
}

// $listed (zfsmScheduleCoverRead) as array(readonly, mountpoint, has Sanoid's or Syncoid's snapshots: null when its names weren't read)
// by dataset, and the lines that don't parse. With a Snapshots Prefix that starts as theirs do, a name that starts with it is ours.
function zfsmScheduleListed($listed) {
	$rows = $errors = $automatic = array();
	$own = zfsmIsAutomaticSnapshot('@'.($listed['prefix'] ?? '')) ? '@'.$listed['prefix'] : '';
	foreach (zfsmLines($listed['output'] ?? '') as $line) {
		$parts = explode("\t", $line, 3);
		$of = (string)strstr($line, '@', true);
		if (count($parts) === 3 && zfsmValidDatasetName($parts[0])) $rows[$parts[0]] = array($parts[1], $parts[2]);
		elseif (count($parts) === 1 && zfsmValidDatasetName($of)) $automatic[$of] = ($automatic[$of] ?? false) || zfsmScheduleTheirs($line, $of, $own);
		else $errors[] = $line;
	}
	$unknown = zfsmScheduleNamesUnread($listed) ? null : false;
	foreach ($rows as $zdataset => $row) $rows[$zdataset][] = $automatic[$zdataset] ?? $unknown;
	// Both reads fail a dataset they can't open: say so once.
	return array($rows, array_values(array_unique($errors)));
}

// Whether $listed (zfsmScheduleCoverRead) read datasets but not their snapshots' names: only a read that exited 0 did, and one that
// timed out has a null code.
function zfsmScheduleNamesUnread($listed) {
	return array_key_exists('names', $listed) && $listed['names'] !== 0;
}

// Whether snapshot $line of $of is Sanoid's or Syncoid's: not ours, under a Snapshots Prefix that starts as theirs do ($own).
function zfsmScheduleTheirs($line, $of, $own) {
	return zfsmIsAutomaticSnapshot($line) && ($own === '' || strpos($line, $of.$own) !== 0);
}

// Why no run takes $zdataset, listed as $row (zfsmScheduleListed); '' when one can.
function zfsmScheduleSkipped($zdataset, $row) {
	if ($row[0] === 'on') return $zdataset.' is read-only, as a copy received here is: a snapshot in it stops the next replication into it';
	// Syncoid sends every snapshot since its last by default (-I): ours would pile up in its copies, where Sanoid removes only its own.
	if ($row[2]) return $zdataset.' has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool';
	return $row[1] === 'legacy' ? $zdataset.' is mounted legacy, as Docker image layers are' : '';
}

// Why no run of $job takes $zdataset, listed as $row: zfsmScheduleSkipped's reasons, and for copies under a dataset
// zfsmScheduleCopiesNot's; '' when one can.
function zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $name) {
	$why = zfsmScheduleSkipped($zdataset, $row);
	return $why === '' && zfsmScheduleCopiesUnder($job) ? zfsmScheduleCopiesNot($job, $jobs, $zdataset, $row, $name) : $why;
}

// Whether a run of $job takes $zdataset, listed as $row: it follows $job in $jobs, and nothing leaves it out (zfsmScheduleLeftOut).
function zfsmScheduleTakes($job, $jobs, $zdataset, $row, $name) {
	return zfsmScheduleLeftOut($job, $jobs, $zdataset, $row, $name) === '' && (zfsmScheduleFollowed($zdataset, $jobs, zfsmScheduleKindOf($job))['id'] ?? null) === $job['id'];
}

// From $listed: by pool, each dataset that follows $job (zfsmScheduleFollowed of its kind in $jobs) and a run can take
// (zfsmScheduleLeftOut); and why some weren't read ('' when all were).
function zfsmScheduleCovered($listed, $job, $jobs) {
	list($rows, $errors) = zfsmScheduleListed($listed);
	$pools = array();
	foreach ($rows as $zdataset => $row) {
		if (zfsmScheduleTakes($job, $jobs, $zdataset, $row, $listed['name'])) $pools[explode('/', $zdataset)[0]][] = $zdataset;
	}
	// zfs lists what it can read, and fails for the rest: a dataset's own minds errors naming none, its datasets or one above.
	if (($listed['code'] ?? 1) === 0) return array($pools, '');
	$mine = array_filter($errors, function($line) use ($job) {
		return $job['dataset'] === '' || !preg_match("/'([^']+)'/", $line, $name) || zfsmUnderAny($name[1], array($job['dataset'])) || zfsmUnderAny($job['dataset'], array($name[1]));
	});
	if ($errors && !$mine) return array($pools, '');
	return array($pools, zfsmScheduleUnreadWhy($mine, $listed));
}

// Why $listed failed: its first $errors, else its exit code (the rest of its output is datasets and names).
function zfsmScheduleUnreadWhy($errors, $listed) {
	return $errors ? zfsmKeepFirst($errors) : zfsmCommandError(array('code' => $listed['code'] ?? 1), 'zfs list failed');
}

// Why no run takes $zdataset, from $listed: gone, not read, or as zfsmScheduleSkipped says; '' when one can.
function zfsmScheduleSnapshotNot($zdataset, $listed) {
	list($rows, $errors) = zfsmScheduleListed($listed);
	$row = $rows[$zdataset] ?? null;
	if ($row === null) return ($listed['code'] ?? 1) === 0 ? $zdataset.' is gone' : $zdataset.' could not be read: '.zfsmScheduleUnreadWhy($errors, $listed);
	return zfsmScheduleSkipped($zdataset, $row);
}

// The record Save makes of what the page sent: None runs at no time and keeps all.
function zfsmScheduleSnapshotRecord($zdataset, $every, $time, $days, $keep) {
	$at = $every === 'none' ? array('minute' => 0, 'hour' => 0, 'days' => range(0, 6), 'keep' => 0) : zfsmScheduleAt($every, $time, $days) + array('keep' => $keep);
	return array('id' => '00000000', 'dataset' => $zdataset, 'dest' => '', 'server' => '', 'every' => $every) + $at;
}

// What the user fixes for Save to make $job (or $remove it), as zfsmScheduleWrong says it; None only for a dataset.
function zfsmScheduleSnapshotWrong($job, $remove) {
	if ($job['dataset'] !== '' && !zfsmValidDatasetName($job['dataset'])) return 'that is no dataset name';
	return $remove || ($job['every'] === 'none' && $job['dataset'] !== '') ? '' : zfsmScheduleWrong($job);
}

// Saves Snapshot Every Dataset ($zdataset '') or a dataset's own in place (zfsmScheduleSnapshotPut); 'off' or 'default' removes it.
// Nothing is taken now. As zfsmScheduleChange answers, and 'said'.
function zfsmScheduleSnapshotSave($zdataset, $every, $time, $days, $keep, $cfg, $wait = ZFSM_SCHEDULE_COUNT_WAIT) {
	$remove = $every === ($zdataset === '' ? 'off' : 'default');
	$job = zfsmScheduleSnapshotRecord($zdataset, $every, $time, $days, $keep);
	$wrong = zfsmScheduleSnapshotWrong($job, $remove);
	if ($wrong !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$wrong, 'said' => '');
	// One zfsmScheduleCoverRead: a dataset's own must be one a run can take, and the answer counts what a schedule that runs takes.
	$listed = $remove || $every === 'none' ? null : zfsmScheduleCoverRead($cfg, $wait);
	$not = $zdataset === '' || $listed === null ? '' : zfsmScheduleSnapshotNot($zdataset, $listed);
	if ($not !== '') return array('saved' => false, 'why' => 'Nothing was saved: '.$not, 'said' => '');
	$after = array();
	$changed = zfsmScheduleSnapshotChange(function($all) use (&$job, $remove, $cfg) { return zfsmScheduleSnapshotPut($all, $job, $remove, $cfg); }, $after);
	if (!$changed['saved']) return $changed + array('said' => '');
	return $changed + array('said' => zfsmScheduleSnapshotSaid($job, $remove, $after, $listed));
}

// zfsmScheduleChange for snapshot records and copies under a dataset, $after as saved: one that goes or is None (it never runs)
// loses its count of runs with a problem.
function zfsmScheduleSnapshotChange($change, &$after = array()) {
	$stale = array();
	$changed = zfsmScheduleChange(function($before) use ($change, &$after, &$stale) {
		$after = $change($before);
		if (is_array($after)) $stale = array_keys(array_diff_key($before, $after) + array_filter($after, function($job) { return $job['every'] === 'none'; }));
		return $after;
	});
	if ($changed['saved']) foreach ($stale as $id) @unlink(zfsmScheduleStateFile($id));
	return $changed;
}

// $jobs once zfs renamed $from to $to, or destroyed it ($to ''): the schedules of it and below, Replicate repeats too, follow it, or go.
function zfsmScheduleOwnMoved($jobs, $from, $to) {
	$under = function($root) use ($jobs) { return array_filter($jobs, function($job) use ($root) { return zfsmUnderAny($job['dataset'], array($root)); }); };
	$moved = $under($from);
	// zfs made $to new: one at or under it is left by a dataset destroyed before, and gives way.
	$jobs = array_diff_key($jobs, $moved, $to === '' ? array() : $under($to));
	foreach ($to === '' ? array() : $moved as $id => $job) {
		$job['dataset'] = $to.substr($job['dataset'], strlen($from));
		$jobs[$id] = $job;
	}
	return $jobs;
}

// zfsmScheduleOwnMoved, saved. As zfsmScheduleChange answers, with 'before' and 'after' it (as it would leave them unsaved, null
// unread); 'why' '' when it has none.
function zfsmScheduleOwnMove($from, $to) {
	$move = function($jobs) use ($from, $to) { return zfsmScheduleOwnMoved($jobs, $from, $to); };
	$read = zfsmScheduleRead();
	if ($read !== null && $move($read['valid']) === $read['valid']) return array('saved' => false, 'why' => '', 'before' => $read['valid'], 'after' => $read['valid']);
	$before = $after = array();
	$changed = zfsmScheduleSnapshotChange(function($jobs) use ($move, &$before) { $before = $jobs; return $move($jobs); }, $after);
	if (!$changed['saved'] && $read === null) return $changed + array('before' => null, 'after' => null);
	if (!$changed['saved']) $after = $move($before = $read['valid']);
	return $changed + array('before' => $before, 'after' => $after);
}

// Once zfs renamed $from to $to, or destroyed it ($to ''), as $answer says: its own schedules follow (zfsmScheduleOwnMove), and on a
// rename a clash they meet ($cfg names the snapshots) and its copies (zfsmScheduleCopiesMove). $answer gains each that did not.
function zfsmScheduleOwnFollow($answer, $from, $to, $cfg = array()) {
	// zfs renames a dataset to its own name by doing nothing.
	if ($from === $to || ($answer['failed'] && !($to !== '' && zfsmRenameLanded($from, $to)))) return $answer;
	$moved = zfsmScheduleOwnMove($from, $to);
	$left = $to === '' ? 'not removed ('.$moved['why'].'): Remove them in Settings'
		: 'not moved to '.$to.' ('.$moved['why'].'): Remove them in Settings and set them again under Actions, Schedules or Replicate';
	if ($moved['why'] !== '') $answer['failed']['Schedules of '.$from] = $moved['saved'] ? $moved['why'] : $left;
	if ($to === '') return $answer;
	if ($moved['before'] === null) {
		$answer['failed']['Copies of '.$from] = 'not renamed with it, as the schedules that say where they go could not be read';
		return $answer;
	}
	// Read once the repeats followed their copies: until then a Copy Under no longer leaves $to out (zfsmScheduleCopiesOwnRepeat).
	$after = $moved['after'];
	$copies = zfsmScheduleCopiesMove($from, $to, $moved['before'], $after, $cfg);
	$clash = $moved['saved'] ? zfsmScheduleNewClash($moved['before'], $after, $cfg) : '';
	if ($clash !== '') $answer['failed']['Schedules of '.$to] = 'moved with it, but '.$clash;
	foreach ($copies as $copy => $why) $answer['failed']['Copy '.$copy] = $why;
	return $answer;
}

// zfsmScheduleSnapshotPlace, or why it can't be (zfsmScheduleSnapshotCheck).
function zfsmScheduleSnapshotPut($jobs, &$job, $remove, $cfg) {
	$jobs = zfsmScheduleSnapshotPlace($jobs, $job, $remove);
	$why = $remove ? '' : zfsmScheduleSnapshotCheck($jobs, $job, $cfg);
	if ($why === '' && !$remove && zfsmScheduleRepeats($job['every'])) $why = zfsmNewSnapshotNamesWhy($cfg, time());
	return $why === '' ? $jobs : 'Nothing was saved: '.$why;
}

// $jobs with $job in place of its dataset's schedule of its kind (none with $remove), keeping that one's id and an hourly one's
// minute while no other there starts in it.
function zfsmScheduleSnapshotPlace($jobs, &$job, $remove) {
	$old = zfsmScheduleOwn($jobs, $job['dataset'], zfsmScheduleKindOf($job));
	$jobs = array_diff_key($jobs, $old);
	if ($remove) return $jobs;
	$was = reset($old);
	$job['id'] = $was ? $was['id'] : zfsmScheduleNewId($old + $jobs);
	if ($job['every'] === 'hourly') $job['minute'] = zfsmScheduleFreeMinute($jobs, $job, $was && $was['every'] === 'hourly' ? $was['minute'] : null);
	return $jobs + array($job['id'] => $job);
}

// Why $job, placed in $jobs, can't run beside the others there but $aside (still nearer, as zfsmScheduleShare reads them): one starts
// in its minute, or Snapshots Pattern names two runs alike. '' when it can.
function zfsmScheduleSnapshotCheck($jobs, $job, $cfg, $aside = array()) {
	$others = array_diff_key($jobs, array($job['id'] => 0));
	foreach (array_keys(array_intersect_key($others, $aside)) as $id) $others[$id]['every'] = 'none';
	$same = zfsmScheduleSame($others, $job);
	if ($same !== '') return $same;
	$clash = zfsmScheduleClash($job, $others, $cfg, time(), false);
	return $clash === '' ? '' : $clash.'; add the time to Snapshots Pattern';
}

// The first clash (zfsmScheduleSnapshotCheck) a schedule in $after has with one it did not clash with in $before (the same schedules by
// id before a change), with the schedule it stops; '' when none. One there before stays the owner's to change.
function zfsmScheduleNewClash($before, $after, $cfg) {
	foreach (array_intersect_key($after, $before) as $id => $job) {
		if (zfsmScheduleSnapshotCheck($after, $job, $cfg) === '') continue;
		// One other at a time, the rest set aside (None, still nearer): with all aside ('') it finds its own, which no caller changes.
		$check = function($jobs, $with) use ($id, $cfg) { return zfsmScheduleSnapshotCheck($jobs, $jobs[$id], $cfg, array_diff_key($jobs, array($id => 0, $with => 0))); };
		$own = array($check($after, ''), $check($before, ''));
		foreach (array_keys(array_diff_key($after, array($id => 0))) as $with) {
			$why = $check($after, $with);
			if (!in_array($why, array('', $own[0]), true) && in_array($check($before, $with), array('', $own[1]), true)) return 'for '.zfsmScheduleWhat($job).', '.$why;
		}
	}
	return '';
}

// Save's answer: what $job does now, with $jobs as saved; one that runs is counted in $listed.
function zfsmScheduleSnapshotSaid($job, $remove, $jobs, $listed) {
	if ($remove && $job['dataset'] === '') return 'Snapshot Every Dataset is off; the snapshots it took stay';
	if ($remove) return 'Saved: '.$job['dataset'].' follows '.zfsmScheduleFollowedText(zfsmScheduleFollowed($job['dataset'], $jobs));
	if ($job['every'] === 'none') return 'Saved: no scheduled snapshots of '.$job['dataset'].' and below, but for datasets with their own; the snapshots taken stay';
	list($pools, $unread) = zfsmScheduleCovered($listed, $job, $jobs);
	$count = ($unread !== '' && !$pools ? 'each dataset' : 'each of '.array_sum(array_map('count', $pools)).' dataset(s)').($job['dataset'] !== '' ? ' ('.$job['dataset'].' and below)' : '');
	return 'Saved: a snapshot of '.$count.' '.zfsmScheduleWhen($job).'; it '.zfsmScheduleKeepText($job).($unread !== '' ? '; not every dataset could be read: '.$unread : '');
}

// Takes $name of each of $datasets (one pool's) with the snapshots tag in one command, all at one moment; when that fails (one name
// already taken fails all), one at a time. One that $name is too long after (zfsmValidSnapshotPart) is left out of both. The datasets
// taken, and each snapshot not taken with why.
function zfsmScheduleSnapshotTake($datasets, $name) {
	$long = array_values(array_filter($datasets, function($zdataset) use ($name) { return !zfsmValidSnapshotPart($name, $zdataset); }));
	$failed = array_map(function($zdataset) use ($name) { return $zdataset.'@'.$name.' (with its dataset and @ a name may be up to 255 long)'; }, $long);
	$datasets = array_values(array_diff($datasets, $long));
	if (!$datasets) return array(array(), $failed);
	$take = function($snapshots) { return runProcess('zfs', array_merge(array('snapshot', '-o', ZFSM_KEEP_TAG.'='.ZFSM_KEEP_SNAPSHOTS), $snapshots)); };
	$all = $take(array_map(function($zdataset) use ($name) { return $zdataset.'@'.$name; }, $datasets));
	if (($all['code'] ?? 1) === 0) return array($datasets, $failed);
	$taken = array();
	foreach ($datasets as $zdataset) {
		$one = $take(array($zdataset.'@'.$name));
		if (($one['code'] ?? 1) === 0) $taken[] = $zdataset;
		else $failed[] = $zdataset.'@'.$name.' ('.zfsmCommandError($one, 'zfs snapshot failed').')';
	}
	return array($taken, $failed);
}

// After a run took a snapshot of $taken (datasets of $pool): of each one's snapshots with the snapshots tag, up to ZFSM_KEEP_PER_RUN
// that $keep doesn't keep go (zfsmKeepPast, zfsmKeepRemove). Each that stayed, and why.
function zfsmScheduleSnapshotKeep($pool, $taken, $keep) {
	if (!zfsmKeepRules($keep) || !$taken) return array();
	$by = zfsmKeepSnapshotsOf($taken);
	if (is_string($by)) return array('the snapshots of '.$pool.' (they could not be read: '.$by.')');
	$stayed = array();
	foreach ($taken as $zdataset) $stayed = array_merge($stayed, zfsmKeepRemove(zfsmKeepPast($by[$zdataset] ?? array(), $keep, ZFSM_KEEP_SNAPSHOTS), array(), zfsmKeepWhy($keep, 'its schedule')));
	return $stayed;
}

// Cron's run of a snapshot schedule ($jobs: all of them, which share the datasets out): a snapshot of every dataset it covers, then its
// Keep; logged, and given to $notify. 0, or 1 after a problem.
function zfsmScheduleSnapshotRun($job, $jobs, $cfg, $now, $notify, $wait = ZFSM_SCHEDULE_COVER_WAIT) {
	return zfsmScheduleLockedRun($job, 'ZFS Snapshot', 'Scheduled snapshots not taken: ', $notify, function() use ($job, $jobs, $cfg, $now, $wait) {
		return zfsmScheduleSnapshotsTaken($job, $jobs, $cfg, $now, $wait);
	});
}

// $run of $job (array(text, problem) as zfsmScheduleSnapshotTell takes them) under the job's lock; $not starts what a run that can't
// start says. One at a time: a run still going at the next one's time (a long send, a hung pool) stops that one (zfsmScheduleLockedSkip).
function zfsmScheduleLockedRun($job, $title, $not, $notify, $run, $grace = 0) {
	$file = zfsmJobDir('schedule').'/'.$job['id'].'.lock';
	$lock = zfsmJobLock($file);
	if ($lock === null) return zfsmScheduleSnapshotTell($job, $not.'the lock file could not be opened', true, $notify, $title);
	if ($lock === false) return zfsmScheduleLockedSkip($job, $title, $not, $notify, $file, $grace);
	// Its time is this run's start, which the next start reads.
	@touch($file);
	try {
		list($text, $problem) = $run();
	} finally {
		zfsmJobUnlock($lock);
	}
	return zfsmScheduleSnapshotTell($job, $text, $problem, $notify, $title);
}

// A start while the run before still goes: only logged until that run, which started at $file's time, has gone on $grace seconds.
function zfsmScheduleLockedSkip($job, $title, $not, $notify, $file, $grace) {
	$age = time() - (int)@filemtime($file);
	if ($age < $grace) {
		zfsmLog('Schedule '.$job['id'].': '.$not.'the run before is still going', LOG_NOTICE);
		return 0;
	}
	return zfsmScheduleSnapshotTell($job, $not.'the run before is still going'.($grace > 0 ? ', '.intdiv($age, 3600).' hour(s) after it started' : ''), true, $notify, $title);
}

// What a run took and kept, and its problem: null when zfs read every pool and the default had none to take, or it only left out.
function zfsmScheduleSnapshotsTaken($job, $jobs, $cfg, $now, $wait) {
	$name = zfsmNewSnapshotName($cfg, $now);
	$untaken = zfsmNewSnapshotNameWhy($name);
	if ($untaken !== '') return array('Scheduled snapshots not taken: '.$untaken, true);
	$listed = zfsmScheduleCoverRead($cfg, $wait);
	list($pools, $unread) = zfsmScheduleCovered($listed, $job, $jobs);
	if (!$pools) return zfsmScheduleSnapshotNone($job, $listed, $unread);
	$self = zfsmScheduleSnapshotSelf($job, $listed);
	$taken = 0;
	$failed = $stayed = array();
	$again = zfsmScheduleTakenBefore($cfg, $now, array_merge(...array_values($pools)), $listed);
	foreach ($pools as $pool => $datasets) {
		list($took, $not) = zfsmScheduleSnapshotTake(array_values(array_diff($datasets, $again)), $name);
		$taken += count($took);
		$failed = array_merge($failed, $not);
		$stayed = array_merge($stayed, zfsmScheduleSnapshotKeep($pool, $took, $job['keep']));
	}
	$of = $job['dataset'] === '' ? '' : ' ('.$job['dataset'].' and below)';
	return array('Scheduled snapshots of '.$taken.' of '.($taken + count($failed)).' dataset(s)'.$of.' taken'.zfsmScheduleTakenBeforeSaid($again).($self !== '' ? '. Not taken: '.$self : '')
		.($unread !== '' ? '. Not every dataset could be read: '.$unread : '').($failed ? '. Could not take '.zfsmKeepFirst($failed) : '')
		.($stayed ? '. '.zfsmKeepStayed('Keep', $stayed, 'run') : ''), zfsmScheduleTakenBeforeProblem($again, $taken, $self !== '' || $unread !== '' || $failed || $stayed));
}

// Why a dataset's own run, which took datasets below its dataset, did not take that one; '' when it did, was not read, or is the default.
function zfsmScheduleSnapshotSelf($job, $listed) {
	$row = zfsmScheduleListed($listed)[0][$job['dataset']] ?? null;
	return $row === null ? '' : zfsmScheduleSkipped($job['dataset'], $row);
}

// A run that took nothing: why, and whether that is a problem. The default with nothing to take is only logged; a dataset's own
// schedule whose dataset no run can take asks for it to go.
function zfsmScheduleSnapshotNone($job, $listed, $unread) {
	if ($unread !== '') return array('Scheduled snapshots not taken: the datasets could not be read: '.$unread, true);
	if ($job['dataset'] === '') return array('Scheduled snapshots not taken: no pool has a dataset it takes', null);
	// A stopped array exports its pools: only logged, as for the default.
	if (!isset(zfsmScheduleListed($listed)[0][explode('/', $job['dataset'])[0]])) return array('Scheduled snapshots of '.$job['dataset'].' not taken: its pool is not imported', null);
	return array('Scheduled snapshots of '.$job['dataset'].' not taken: '.zfsmScheduleSnapshotNot($job['dataset'], $listed).'; remove its schedule in Settings', true);
}

// Logs a run's $text and has $notify tell it under $title; 1 for a problem. A null $problem (nothing to take) is only logged: no run
// that works again.
function zfsmScheduleSnapshotTell($job, $text, $problem, $notify, $title = 'ZFS Snapshot') {
	zfsmLog('Schedule '.$job['id'].': '.$text, $problem ? LOG_WARNING : LOG_NOTICE);
	if ($problem !== null) $notify($title, zfsmSafeText($text).'.', $problem ? 'warning' : 'normal');
	return $problem ? 1 : 0;
}
