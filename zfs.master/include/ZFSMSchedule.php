<?php
// Schedules of copies, of snapshots (ZFSMScheduleSnapshot.php) and of copies under a dataset (ZFSMScheduleCopies.php): the store
// (schedules.json) and its cron file (schedules.cron). Each run cron starts (scripts/schedule.php) begins in ZFSMScheduleRun.php.
require_once __DIR__.'/ZFSMJobs.php';
require_once __DIR__.'/ZFSMReplicaTarget.php';
require_once __DIR__.'/ZFSMKeep.php';
require_once __DIR__.'/ZFSMScheduleSnapshot.php';
require_once __DIR__.'/ZFSMScheduleCover.php';
require_once __DIR__.'/ZFSMScheduleCopies.php';
require_once __DIR__.'/ZFSMScheduleCopiesRename.php';
require_once __DIR__.'/ZFSMScheduleDialog.php';
require_once __DIR__.'/ZFSMScheduleKeep.php';
require_once __DIR__.'/ZFSMScheduleHtml.php';
require_once __DIR__.'/ZFSMScheduleRun.php';

define('ZFSM_SCHEDULE_SCRIPT', '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php');
define('ZFSM_SCHEDULE_DAYS', array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'));
// Each kind's shortest time between two runs, less by up to an hour across a summer-time change: every hour, or days of the week.
define('ZFSM_SCHEDULE_PERIODS', array('hourly' => 3600, 'days' => 86400));

// Whether $every is a Repeat that runs; a dataset's None (ZFSMScheduleSnapshot.php) never does.
function zfsmScheduleRepeats($every) {
	return is_string($every) && isset(ZFSM_SCHEDULE_PERIODS[$every]);
}

// How long a run waits out another replication of its dataset: less than any schedule's period, so never its own next run.
define('ZFSM_SCHEDULE_WAIT', 3300);

// On the flash drive beside the settings, so both survive a restart: Unraid builds its crontab from the .cron file at boot.
function zfsmScheduleFile() {
	return zfsmStatePath('/boot/config/plugins/zfs.master/schedules.json');
}

function zfsmScheduleCronFile() {
	return zfsmStatePath('/boot/config/plugins/zfs.master/schedules.cron');
}

function zfsmScheduleId($id) {
	return is_string($id) && preg_match('/^[0-9a-f]{8}$/D', $id) === 1;
}

// A copy's schedule, a snapshot schedule (dest and server ''; zfsmScheduleSnapshotValid) or copies under a dataset (dest ends in /;
// zfsmScheduleCopiesValid). One saved before Keep has no keep: it keeps all; one with no copy_keeps: its copy keeps what it keeps.
function zfsmScheduleValid($job) {
	if (!is_array($job) || !zfsmScheduleId($job['id'] ?? null)) return false;
	$what = ($job['dest'] ?? null) === '' ? zfsmScheduleSnapshotValid($job) : (zfsmScheduleCopiesUnder($job) ? zfsmScheduleCopiesValid($job) : zfsmScheduleCopyValid($job) && zfsmScheduleRepeats($job['every'] ?? null));
	$number = function($value, $top) { return is_int($value) && $value >= 0 && $value <= $top; };
	return $what && $number($job['minute'] ?? null, 59) && $number($job['hour'] ?? null, 23) && zfsmScheduleDaysValid($job['days'] ?? null) && zfsmKeepValid($job['keep'] ?? 0)
		&& zfsmScheduleCopyKeepsValid($job) && zfsmScheduleTickValid($job, 'decrypted') && zfsmScheduleTickValid($job, 'remake') && zfsmScheduleTickValid($job, 'replace', true)
		&& zfsmScheduleLeaveOutValid($job);
}

// A copy's choice saved as $key (Send's Decrypted; remake, If the copy is gone; replace, Replace a copy): only on a copy's schedule,
// only as true, and with $here only for a copy on this server.
function zfsmScheduleTickValid($job, $key, $here = false) {
	return !array_key_exists($key, $job) || ($job[$key] === true && $job['dest'] !== '' && !zfsmScheduleCopiesUnder($job) && (!$here || $job['server'] === ''));
}

// The copy is a dataset in a pool here, or one name inside the area on a trusted server (zfsmReplicaTargetDest).
function zfsmScheduleCopyValid($job) {
	if (!zfsmValidDatasetName($job['dataset'] ?? null)) return false;
	$dest = $job['dest'] ?? null;
	$server = $job['server'] ?? null;
	return $server === '' ? zfsmValidDatasetName($dest) && strpos($dest, '/') !== false : zfsmReplicaSenderName($server) && zfsmValidNameComponent($dest);
}

// Days of the week, 0 (Sunday) to 6: at least one, in order, each once.
function zfsmScheduleDaysValid($days) {
	if (!is_array($days) || $days === array() || array_keys($days) !== range(0, count($days) - 1)) return false;
	$last = -1;
	foreach ($days as $day) {
		if (!is_int($day) || $day <= $last || $day > 6) return false;
		$last = $day;
	}
	return true;
}

// A record saved before start times and days of the week, with the day 0 to 6 that version needed of each: its minute came from
// its id, and a daily one ran each day, a weekly one on its day.
function zfsmScheduleUpgrade($job) {
	if (!is_array($job) || array_key_exists('days', $job) || !zfsmScheduleId($job['id'] ?? null) || !in_array($job['every'] ?? null, array('hourly', 'daily', 'weekly'), true)
		|| !in_array($job['day'] ?? null, range(0, 6), true)) return $job;
	$days = $job['every'] === 'weekly' ? array($job['day']) : range(0, 6);
	unset($job['day']);
	return array('every' => $job['every'] === 'hourly' ? 'hourly' : 'days', 'minute' => hexdec(substr($job['id'], 0, 4)) % 60, 'days' => $days) + $job;
}

function zfsmScheduleUnreadable() {
	return zfsmScheduleFile().' could not be read as a list of schedules; fix or remove it first';
}

// schedules.json, or $text read from it, as 'valid' schedules by id and 'other' entries kept for a write; null when unread or no list.
function zfsmScheduleRead($text = null) {
	$file = zfsmScheduleFile();
	if ($text === null && !file_exists($file)) return array('valid' => array(), 'other' => array());
	if ($text === null) $text = (string)@file_get_contents($file);
	// Read as objects first: as arrays, {} is an empty list and one schedule's record a list of its fields.
	if (!is_array(json_decode($text))) return null;
	$records = json_decode($text, true);
	$kept = zfsmScheduleCopyKeepsKept($records);
	$read = array('valid' => array(), 'other' => array());
	foreach ($records as $record) {
		if (zfsmScheduleCopyKeepsShadowOf($record) !== null) continue;
		$job = zfsmScheduleCopyKeepsBack(zfsmScheduleUpgrade($record), $kept);
		if (!zfsmScheduleValid($job) || isset($read['valid'][$job['id']])) {
			$read['other'][] = $record;
			continue;
		}
		// Fields this version doesn't know stay, for the later version that wrote them: the install's heal writes every schedule.
		$read['valid'][$job['id']] = zfsmScheduleCopyKeepsSet(array('id' => $job['id'], 'dataset' => $job['dataset'], 'dest' => $job['dest'], 'server' => $job['server'],
			'every' => $job['every'], 'minute' => $job['minute'], 'hour' => $job['hour'], 'days' => $job['days'], 'keep' => $job['keep'] ?? 0), $job['copy_keeps'] ?? null)
			+ array_diff_key($job, array('copy_keeps' => 0));
	}
	return $read;
}

// $change gets the schedules and returns them changed, or a string saying why not; under the lock, written whole with the entries
// in other shapes as they were, then the cron file to match. A file that can't be read is never written over.
// Answers 'saved' (schedules.json was written) and 'why': '', why it was not, or what failed after.
function zfsmScheduleChange($change) {
	$unsaved = function($why) { return array('saved' => false, 'why' => $why); };
	$dir = zfsmJobDir('schedule');
	$lock = zfsmJobLock($dir.'/settings.lock', false);
	if ($lock === null) return $unsaved('Could not open the schedule lock in '.$dir);
	if ($lock === false) return $unsaved('Another change to the schedules is being saved; try again');
	try {
		$read = zfsmScheduleRead();
		if ($read === null) return $unsaved(zfsmScheduleUnreadable());
		$jobs = $change($read['valid']);
		if (is_string($jobs)) return $unsaved($jobs);
		$file = zfsmScheduleFile();
		if (!zfsmWriteFileAtomic($file, zfsmScheduleText($jobs, $read['other']))) return $unsaved('Could not save '.$file);
		return array('saved' => true, 'why' => zfsmScheduleCron($jobs));
	} finally {
		zfsmJobUnlock($lock);
	}
}

// schedules.json as written: the schedules, a shadow of each Copy Keeps they hold (zfsmScheduleCopyKeepsShadows), then $other as read.
function zfsmScheduleText($jobs, $other) {
	return zfsmJson(array_merge(array_values($jobs), zfsmScheduleCopyKeepsShadows($jobs), $other), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

// At each install and boot (zfs.master.plg): both files again as this version writes them, after an older version's change left out what
// it can't read. As zfsmScheduleChange answers; null with no schedules.json, or when both already are as written.
function zfsmScheduleHeal() {
	$file = zfsmScheduleFile();
	if (!file_exists($file)) return null;
	$read = zfsmScheduleRead();
	if ($read === null) return array('saved' => false, 'why' => zfsmScheduleUnreadable());
	if ((string)@file_get_contents($file) === zfsmScheduleText($read['valid'], $read['other'])
		&& (string)@file_get_contents(zfsmScheduleCronFile()) === zfsmScheduleCronText($read['valid'])) return null;
	return zfsmScheduleChange(function($jobs) { return $jobs; });
}

function zfsmScheduleNewId($jobs) {
	do $id = bin2hex(random_bytes(4)); while (isset($jobs[$id]));
	return $id;
}

// An hourly $job's minute: $prefer, or another, that no schedule sharing a dataset with it (zfsmScheduleShare) starts at; any with
// none free.
function zfsmScheduleFreeMinute($jobs, $job, $prefer = null) {
	$free = array_diff(range(0, 59), array_column(array_filter($jobs, function($other) use ($job, $jobs) { return zfsmScheduleShare($job, $other, $jobs); }), 'minute'));
	if (in_array($prefer, $free, true)) return $prefer;
	return $free ? $free[array_rand($free)] : random_int(0, 59);
}

// Whether $a and $b can snapshot one dataset (so not in one minute or name): both of it, or one that covers (zfsmScheduleCovers) of
// one above it or the default that it follows among $jobs (zfsmScheduleFollowed): a nearer one of its kind, None too, takes it.
function zfsmScheduleShare($a, $b, $jobs) {
	if (!zfsmScheduleRepeats($a['every']) || !zfsmScheduleRepeats($b['every'])) return false;
	if (zfsmScheduleCovers($a) && zfsmScheduleCovers($b) && zfsmScheduleKindOf($a) === zfsmScheduleKindOf($b)) return false;
	$in = function($job, $other) use ($jobs) {
		if (!zfsmScheduleCovers($job) || ($job['dataset'] !== '' && !zfsmUnderAny($other['dataset'], array($job['dataset'])))) return false;
		$near = zfsmScheduleFollowed($other['dataset'], array_diff_key($jobs, array($job['id'] => 0)) + array($job['id'] => $job), zfsmScheduleKindOf($job));
		return $near['id'] === $job['id'];
	};
	// A run of copies leaves out the one dataset a repeat set in Replicate copies there (zfsmScheduleCopiesNot).
	if (zfsmScheduleCopiesOwnRepeat($a, $b, $b['dataset']) || zfsmScheduleCopiesOwnRepeat($b, $a, $a['dataset'])) return false;
	return $a['dataset'] === $b['dataset'] || $in($a, $b) || $in($b, $a);
}

// What a schedule does, inside a sentence.
function zfsmScheduleWhat($job) {
	if (zfsmScheduleCopiesUnder($job)) return $job['dataset'] === '' ? 'Replicate Every Dataset' : 'the copies of '.$job['dataset'].' and below';
	if (!zfsmScheduleSnapshotsOnly($job)) return 'the copy to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']);
	return $job['dataset'] === '' ? 'Snapshot Every Dataset' : 'the snapshot schedule of '.$job['dataset'];
}

// Whether $a and $b ever start in the same minute.
function zfsmScheduleSameTime($a, $b) {
	if ($a['minute'] !== $b['minute']) return false;
	return $a['every'] === 'hourly' || $b['every'] === 'hourly' || ($a['hour'] === $b['hour'] && array_intersect($a['days'], $b['days']) !== array());
}

// When cron next runs $job after $now, in this server's time zone as cron reads it: the minute first, then each hour.
function zfsmScheduleNext($job, $now) {
	$at = $now - $now % 60 + 60;
	$at += ($job['minute'] - (int)date('i', $at) + 60) % 60 * 60;
	while ($job['every'] !== 'hourly' && ((int)date('G', $at) !== $job['hour'] || !in_array((int)date('w', $at), $job['days'], true))) $at += 3600;
	return $at;
}

// Cron's runs of $job after $from and before $until; the next is looked for from 2 hours short of a period on (hourly: a minute
// on), so a change of summer time skips none.
function zfsmScheduleRuns($job, $from, $until) {
	$runs = array();
	if (!zfsmScheduleRepeats($job['every'])) return $runs;
	for ($at = zfsmScheduleNext($job, $from); $at < $until; $at = zfsmScheduleNext($job, $at + max(60, ZFSM_SCHEDULE_PERIODS[$job['every']] - 7200))) $runs[] = $at;
	return $runs;
}

// Why Snapshots Pattern gives one of $job's runs in the next two weeks the name of another snapshot it or a schedule in $jobs
// sharing a dataset with it takes, so that run can't take its own; $first: Repeat's run at $now is one. '' when none does.
function zfsmScheduleClash($job, $jobs, $cfg, $now, $first) {
	$until = $now + 14 * 86400 + 3600;
	$named = $first ? array(zfsmNewSnapshotName($cfg, $now) => 'this run') : array();
	foreach ($jobs as $other) {
		if ($other['id'] === $job['id'] || !zfsmScheduleShare($job, $other, $jobs)) continue;
		foreach (zfsmScheduleRuns($other, $now, $until) as $at) $named[zfsmNewSnapshotName($cfg, $at)] = 'the run at '.date('D j M H:i', $at).' of '.zfsmScheduleWhat($other);
	}
	foreach (zfsmScheduleRuns($job, $now, $until) as $at) {
		// Cron starts it again where the clock went back: that run leaves out what the first took (zfsmScheduleTakenBefore).
		if (zfsmNewSnapshotNameRepeats($cfg, $at)) continue;
		$name = zfsmNewSnapshotName($cfg, $at);
		if (isset($named[$name])) return 'Snapshots Pattern would give the run at '.date('D j M H:i', $at).' the same snapshot name as '.$named[$name];
		$named[$name] = 'the run at '.date('D j M H:i', $at);
	}
	return '';
}

// Each line names only its schedule's id; None has none. '' with no schedules.
function zfsmScheduleCronText($jobs) {
	$lines = array();
	foreach ($jobs as $job) {
		if (!zfsmScheduleRepeats($job['every'])) continue;
		$hour = $job['every'] === 'hourly' ? '*' : $job['hour'];
		$days = $job['every'] === 'hourly' || count($job['days']) === 7 ? '*' : implode(',', $job['days']);
		$lines[] = $job['minute'].' '.$hour.' * * '.$days.' '.ZFSM_SCHEDULE_SCRIPT.' run '.$job['id'];
	}
	return zfsmCronText('schedules', $lines);
}

// Writes the cron file for $jobs (with none, removes it) and has Unraid rebuild its crontab from every plugin's; '' or why not.
function zfsmScheduleCron($jobs) {
	$file = zfsmScheduleCronFile();
	$text = zfsmScheduleCronText($jobs);
	$written = $text === '' ? !file_exists($file) || @unlink($file) : zfsmWriteFileAtomic($file, $text);
	if (!$written) return 'The schedules were saved, but '.$file.' could not be written, so cron still runs the ones it had';
	// Unraid's update_cron begins "#/bin/bash", with no "!": PHP 8.3 and later can't start it on its own (Exec format error).
	$built = runProcess('/bin/bash', array(zfsmStatePath('/usr/local/sbin/update_cron')));
	if (($built['code'] ?? 1) === 0) return '';
	return 'The schedules were saved, but Unraid\'s crontab could not be rebuilt ('.zfsmCommandError($built, 'update_cron failed').'), so it runs the ones it had until the server restarts';
}

// Its days Monday first, as the dialog shows them.
function zfsmScheduleWhen($job) {
	if (!zfsmScheduleRepeats($job['every'])) return 'never';
	$minute = sprintf('%02d', $job['minute']);
	if ($job['every'] === 'hourly') return 'every hour at :'.$minute;
	$at = sprintf('%02d', $job['hour']).':'.$minute;
	if (count($job['days']) === 7) return 'every day at '.$at;
	$days = $job['days'];
	if ($days[0] === 0) $days[] = array_shift($days);
	$names = array_map(function($day) { return ZFSM_SCHEDULE_DAYS[$day]; }, $days);
	$last = array_pop($names);
	return 'every '.($names ? implode(', ', $names).' and ' : '').$last.' at '.$at;
}

function zfsmScheduleKeepText($job) {
	$kept = zfsmKeepWords($job['keep']);
	if (zfsmScheduleSnapshotsOnly($job)) return $kept !== '' ? 'keeps '.$kept.' scheduled snapshots of each dataset' : 'keeps all it takes';
	$of = zfsmScheduleCopiesUnder($job) ? ' of the snapshots of each dataset it copies' : ' of its snapshots';
	return ($kept !== '' ? 'keeps '.$kept.$of : 'keeps all its snapshots').zfsmScheduleCopyKeepsText($job);
}

// The page's $time ('13:30') and $days ('1,3,5'; 0 is Sunday) as a schedule's minute, hour and days: -1 and none when unread.
function zfsmScheduleAt($every, $time, $days) {
	if ($every === 'hourly') return array('minute' => 0, 'hour' => 0, 'days' => range(0, 6));
	$at = preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $time, $parts) === 1 ? array((int)$parts[2], (int)$parts[1]) : array(-1, -1);
	$days = preg_match('/^[0-6](,[0-6])*$/D', $days) === 1 ? array_values(array_unique(array_map('intval', explode(',', $days)))) : array();
	sort($days);
	return array('minute' => $at[0], 'hour' => $at[1], 'days' => $days);
}

// What the user fixes for $job to make a schedule: a Repeat the page no longer offers, else each wrong time, days, Keep, Copy Keeps or
// Leave Out, else the copy's name; '' when it makes one.
function zfsmScheduleWrong($job) {
	if (!zfsmScheduleRepeats($job['every'])) return 'Repeat has no such choice now; reload the page and pick again';
	$fix = array();
	if ($job['minute'] < 0) $fix[] = 'set the time it starts at';
	if ($job['days'] === array()) $fix[] = 'tick at least one day';
	if (!zfsmKeepValid($job['keep'])) $fix[] = 'under Snapshots to keep, '.zfsmKeepFix();
	$copy = zfsmScheduleCopyKeepsWrong($job);
	if ($copy !== '') $fix[] = $copy;
	$leave = zfsmScheduleLeaveOutWrong($job);
	if ($leave !== '') $fix[] = $leave;
	if (isset($job['replace']) && $job['server'] !== '') $fix[] = 'Replace a copy that no longer matches is only for a copy on this server';
	if ($fix === array() && !zfsmScheduleValid($job)) $fix[] = 'give the copy a name as Replicate does';
	return implode('; ', $fix);
}

// Saves a schedule for a copy and starts its first run now with Keep 0, as it may never be saved: one whose first run can't start
// isn't. $keep: zfsmKeepParse's (-1: what the page sent is no Keep; empty is 0, all); $copy_keeps: zfsmCopyKeepsParse's; $decrypt: Send's;
// $remake: If the copy is gone's; $replace: Replace a copy's.
function zfsmScheduleAdd($zdataset, $snapshot, $zdest, $server, $every, $time, $days, $keep, $cfg, $copy_keeps = null, $decrypt = false, $remake = false, $replace = false) {
	$job = zfsmScheduleCopyKeepsSet(array('id' => '00000000', 'dataset' => $zdataset, 'dest' => $zdest, 'server' => $server, 'every' => $every) + zfsmScheduleAt($every, $time, $days)
		+ array('keep' => $keep), $copy_keeps) + array_filter(array('decrypted' => $decrypt === true, 'remake' => $remake === true, 'replace' => $replace === true));
	$wrong = zfsmScheduleWrong($job);
	if ($wrong !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$wrong);
	if ($snapshot !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now');
	$named = zfsmNewSnapshotNamesWhy($cfg, time());
	if ($named !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$named);
	$repeat = zfsmScheduleRepeatRead($zdataset, $cfg, ZFSM_SCHEDULE_COUNT_WAIT);
	$not = zfsmScheduleSkippedText($repeat['warning']) ?: (string)$repeat['unread'];
	if ($not !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$not);
	$read = zfsmScheduleRead();
	if ($read === null) return zfsmFailure($zdataset, 'Nothing was scheduled: '.zfsmScheduleUnreadable());
	if ($every === 'hourly') $job['minute'] = zfsmScheduleFreeMinute($read['valid'], $job);
	$same = zfsmScheduleSame($read['valid'], $job);
	if ($same !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$same);
	$job['id'] = zfsmScheduleNewId($read['valid']);
	$clash = zfsmScheduleClash($job, $read['valid'], $cfg, time(), true);
	if ($clash !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$clash.'; add the time to it in Settings');
	$started = zfsmStartReplicate($zdataset, '', $zdest, $cfg, $server, 0, $decrypt, $replace);
	if (!isset($started['succeeded'][$zdataset])) return zfsmFailure($zdataset, 'Nothing was scheduled: '.($started['failed'][$zdataset] ?? 'the first run did not start'));
	$changed = zfsmScheduleChange(function($jobs) use ($job) {
		$same = zfsmScheduleSame($jobs, $job);
		if ($same !== '') return $same;
		$jobs[$job['id']] = $job;
		return $jobs;
	});
	$said = $started['succeeded'][$zdataset];
	if ($changed['why'] === '') return array('succeeded' => array($zdataset => $said.'. It runs again '.zfsmScheduleWhen($job)), 'failed' => array());
	return array('succeeded' => array($zdataset => $said), 'failed' => array($zdataset => ($changed['saved'] ? '' : 'It was not scheduled: ').$changed['why']));
}

// Why $job can't be added beside $jobs: one for the same copy already runs it, or one sharing a dataset with it starts in the same
// minute (two at once can get one snapshot name). '' when none does.
function zfsmScheduleSame($jobs, $job) {
	foreach ($jobs as $other) {
		if ($other['dataset'] === $job['dataset'] && $other['dest'] === $job['dest'] && $other['server'] === $job['server']) return 'this copy already has a schedule ('.zfsmScheduleWhen($other).'); remove it in Settings first';
	}
	foreach ($jobs as $other) {
		if (zfsmScheduleShare($job, $other, $jobs) && zfsmScheduleSameTime($job, $other)) return zfsmScheduleWhat($other).' ('.zfsmScheduleWhen($other).') starts then too; pick another time';
	}
	return '';
}

// As zfsmScheduleChange answers: removed once 'saved', even if the crontab could not be rebuilt; not when the schedule its datasets then
// follow clashes with one saved (zfsmScheduleNewClash, Snapshots Pattern from $cfg), unless its dataset is gone (zfsmDatasetGone).
function zfsmScheduleRemove($id, $cfg) {
	if (!zfsmScheduleId($id)) return array('saved' => false, 'why' => 'No such schedule');
	$changed = zfsmScheduleChange(function($jobs) use ($id, $cfg) {
		if (!isset($jobs[$id])) return 'No such schedule; it may have been removed already';
		$after = array_diff_key($jobs, array($id => 0));
		$clash = zfsmScheduleNewClash($jobs, $after, $cfg);
		if ($clash !== '' && $jobs[$id]['dataset'] !== '' && zfsmDatasetGone($jobs[$id]['dataset'])) $clash = '';
		return $clash === '' ? $after : 'Not removed: '.$clash;
	});
	if ($changed['saved']) @unlink(zfsmScheduleStateFile($id));
	return $changed;
}
