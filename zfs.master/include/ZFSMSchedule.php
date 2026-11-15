<?php
// Scheduled replication: the schedules (schedules.json), their cron file (schedules.cron) and each run cron starts (scripts/schedule.php).
require_once __DIR__.'/ZFSMJobs.php';
require_once __DIR__.'/ZFSMReplicaTarget.php';

define('ZFSM_SCHEDULE_SCRIPT', '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php');
define('ZFSM_SCHEDULE_DAYS', array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'));
define('ZFSM_SCHEDULE_PERIODS', array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800));
// How long a run waits out another replication of its dataset: less than any schedule's period, so never its own next run.
define('ZFSM_SCHEDULE_WAIT', 3300);

// On the flash drive beside the settings, so both survive a restart: Unraid builds its crontab from the .cron file at boot.
function zfsmScheduleFile() {
	return zfsmStatePath('/boot/config/plugins/zfs.master/schedules.json');
}

function zfsmScheduleCronFile() {
	return zfsmStatePath('/boot/config/plugins/zfs.master/schedules.cron');
}

// A run's failures in a row, in RAM: a restart counts again from none.
function zfsmScheduleStateFile($id) {
	return zfsmJobDir('schedule').'/'.$id.'.json';
}

function zfsmScheduleId($id) {
	return is_string($id) && preg_match('/^[0-9a-f]{8}$/D', $id) === 1;
}

// The copy is a dataset in a pool here, or one name inside the area on a trusted server (zfsmReplicaTargetDest).
function zfsmScheduleValid($job) {
	if (!is_array($job) || !zfsmScheduleId($job['id'] ?? null) || !zfsmValidDatasetName($job['dataset'] ?? null)) return false;
	$dest = $job['dest'] ?? null;
	$server = $job['server'] ?? null;
	$copy = $server === '' ? zfsmValidDatasetName($dest) && strpos($dest, '/') !== false : zfsmReplicaSenderName($server) && zfsmValidNameComponent($dest);
	$hour = $job['hour'] ?? null;
	$day = $job['day'] ?? null;
	return $copy && isset(ZFSM_SCHEDULE_PERIODS[$job['every'] ?? '']) && is_int($hour) && $hour >= 0 && $hour <= 23 && is_int($day) && $day >= 0 && $day <= 6;
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
	$read = array('valid' => array(), 'other' => array());
	foreach (json_decode($text, true) as $job) {
		if (!zfsmScheduleValid($job) || isset($read['valid'][$job['id']])) {
			$read['other'][] = $job;
			continue;
		}
		$read['valid'][$job['id']] = array('id' => $job['id'], 'dataset' => $job['dataset'], 'dest' => $job['dest'], 'server' => $job['server'], 'every' => $job['every'], 'hour' => $job['hour'], 'day' => $job['day']);
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
		if (!zfsmWriteFileAtomic($file, zfsmJson(array_merge(array_values($jobs), $read['other']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) return $unsaved('Could not save '.$file);
		return array('saved' => true, 'why' => zfsmScheduleCron($jobs));
	} finally {
		zfsmJobUnlock($lock);
	}
}

// The minute past the hour a schedule runs at, from its id, so schedules made together don't all start at once.
function zfsmScheduleMinute($id) {
	return hexdec(substr($id, 0, 4)) % 60;
}

// A new id at a minute no other schedule of $dataset runs at: two at once wait on one lock and can get one snapshot name (zfsmRunReplicate).
function zfsmScheduleNewId($jobs, $dataset) {
	$free = range(0, 59);
	foreach ($jobs as $job) if ($job['dataset'] === $dataset) unset($free[zfsmScheduleMinute($job['id'])]);
	do {
		$id = bin2hex(random_bytes(4));
		if ($free) $id = sprintf('%04x', 60 * random_int(0, 1091) + $free[array_rand($free)]).substr($id, 4);
	} while (isset($jobs[$id]));
	return $id;
}

// When cron next runs $job after $now, in this server's time zone as cron reads it: the minute first, then each hour.
function zfsmScheduleNext($job, $now) {
	$at = $now - $now % 60 + 60;
	$at += (zfsmScheduleMinute($job['id']) - (int)date('i', $at) + 60) % 60 * 60;
	while (($job['every'] !== 'hourly' && (int)date('G', $at) !== $job['hour']) || ($job['every'] === 'weekly' && (int)date('w', $at) !== $job['day'])) $at += 3600;
	return $at;
}

// Cron's runs of $job after $from and before $until; the next is looked for from 2 hours short of a period on (hourly: a minute
// on), so a change of summer time skips none.
function zfsmScheduleRuns($job, $from, $until) {
	$runs = array();
	for ($at = zfsmScheduleNext($job, $from); $at < $until; $at = zfsmScheduleNext($job, $at + max(60, ZFSM_SCHEDULE_PERIODS[$job['every']] - 7200))) $runs[] = $at;
	return $runs;
}

// Why Snapshots Pattern gives one of $job's runs in the next two weeks the name of another snapshot it or its dataset's other
// schedules in $jobs take, so that run can't take its own; $first: Repeat's run at $now is one. '' when none does.
function zfsmScheduleClash($job, $jobs, $cfg, $now, $first) {
	$until = $now + 2 * ZFSM_SCHEDULE_PERIODS['weekly'] + 3600;
	$named = $first ? array(zfsmNewSnapshotName($cfg, $now) => 'this run') : array();
	foreach ($jobs as $other) {
		if ($other['id'] === $job['id'] || $other['dataset'] !== $job['dataset']) continue;
		foreach (zfsmScheduleRuns($other, $now, $until) as $at) $named[zfsmNewSnapshotName($cfg, $at)] = 'the run at '.date('D j M H:i', $at).' of the copy to '.zfsmReplicaTargetWhere(array('server' => $other['server']), $other['dest']);
	}
	foreach (zfsmScheduleRuns($job, $now, $until) as $at) {
		$name = zfsmNewSnapshotName($cfg, $at);
		if (isset($named[$name])) return 'Snapshots Pattern would give the run at '.date('D j M H:i', $at).' the same snapshot name as '.$named[$name];
		$named[$name] = 'the run at '.date('D j M H:i', $at);
	}
	return '';
}

// Each line names only its schedule's id. '' with no schedules.
function zfsmScheduleCronText($jobs) {
	$lines = array();
	foreach ($jobs as $job) {
		$hour = $job['every'] === 'hourly' ? '*' : $job['hour'];
		$day = $job['every'] === 'weekly' ? $job['day'] : '*';
		$lines[] = zfsmScheduleMinute($job['id']).' '.$hour.' * * '.$day.' '.ZFSM_SCHEDULE_SCRIPT.' run '.$job['id'];
	}
	return zfsmCronText('replication schedules', $lines);
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

function zfsmScheduleWhen($job) {
	$minute = sprintf('%02d', zfsmScheduleMinute($job['id']));
	if ($job['every'] === 'hourly') return 'every hour at :'.$minute;
	$at = sprintf('%02d', $job['hour']).':'.$minute;
	return $job['every'] === 'daily' ? 'every day at '.$at : 'every '.ZFSM_SCHEDULE_DAYS[$job['day']].' at '.$at;
}

// Saves a schedule for a copy and starts its first run now, through zfsmStartReplicate: a schedule whose first run can't start
// is never saved. $hour and $day are -1 when the page sent no number.
function zfsmScheduleAdd($zdataset, $snapshot, $zdest, $server, $every, $hour, $day, $cfg) {
	$job = array('id' => '00000000', 'dataset' => $zdataset, 'dest' => $zdest, 'server' => $server, 'every' => $every,
		'hour' => $every === 'hourly' ? 0 : $hour, 'day' => $every === 'weekly' ? $day : 0);
	if (!zfsmScheduleValid($job)) return zfsmFailure($zdataset, 'Nothing was scheduled: pick how often and when, and give the copy a name as Replicate does');
	if ($snapshot !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now');
	$read = zfsmScheduleRead();
	if ($read === null) return zfsmFailure($zdataset, 'Nothing was scheduled: '.zfsmScheduleUnreadable());
	$same = zfsmScheduleSame($read['valid'], $job);
	if ($same !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$same);
	$job['id'] = zfsmScheduleNewId($read['valid'], $zdataset);
	$clash = zfsmScheduleClash($job, $read['valid'], $cfg, time(), true);
	if ($clash !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$clash.'; add the time to it in Settings');
	$started = zfsmStartReplicate($zdataset, '', $zdest, $cfg, $server);
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

// Why $job can't be added beside $jobs: one for the same copy already runs it. '' when none does.
function zfsmScheduleSame($jobs, $job) {
	foreach ($jobs as $other) {
		if ($other['dataset'] === $job['dataset'] && $other['dest'] === $job['dest'] && $other['server'] === $job['server']) return 'this copy already has a schedule ('.zfsmScheduleWhen($other).'); remove it in Settings first';
	}
	return '';
}

// As zfsmScheduleChange answers: removed once 'saved', even if the crontab could not be rebuilt after.
function zfsmScheduleRemove($id) {
	if (!zfsmScheduleId($id)) return array('saved' => false, 'why' => 'No such schedule');
	$changed = zfsmScheduleChange(function($jobs) use ($id) {
		if (!isset($jobs[$id])) return 'No such schedule; it may have been removed already';
		unset($jobs[$id]);
		return $jobs;
	});
	if ($changed['saved']) @unlink(zfsmScheduleStateFile($id));
	return $changed;
}

// The runs in a row with a problem, and whether Unraid took the notification of the first; a record with no 'told' was told.
function zfsmScheduleState($id) {
	$state = json_decode((string)@file_get_contents(zfsmScheduleStateFile($id)), true);
	$failures = is_array($state) && is_int($state['failures'] ?? null) && $state['failures'] > 0 ? $state['failures'] : 0;
	return array('failures' => $failures, 'told' => ($state['told'] ?? true) !== false);
}

function zfsmScheduleFailures($id) {
	return zfsmScheduleState($id)['failures'];
}

// A scheduled run notifies of its first problem (a warning; the next one does if Unraid didn't take it) and of the run that works
// again; each problem is logged with its count. A count that can't be kept notifies of every problem, rather than of none.
function zfsmScheduleNotifier($id) {
	return function($title, $text, $type) use ($id) {
		$state = zfsmScheduleState($id);
		$file = zfsmScheduleStateFile($id);
		if ($type === 'normal') {
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
		zfsmLog('Scheduled replication '.$id.': '.$failures.' run(s) in a row with a problem');
		if (!$kept) zfsnotify($title, $text, '', $type);
		elseif (!$told && zfsnotify($title, rtrim($text, '.').'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.', '', $type)) $save(true);
	};
}

// Cron's run of one schedule: the job read by its id, and a run that takes its snapshot first, as Replicate's "Take one now" does.
function zfsmScheduleRun($id, $cfg, $now, $wait = ZFSM_SCHEDULE_WAIT) {
	$notify = zfsmScheduleNotifier($id);
	$read = zfsmScheduleRead();
	if ($read === null) {
		zfsmLog('Scheduled replication '.$id.' not started: '.zfsmScheduleUnreadable());
		$notify('ZFS Replication', 'Scheduled replication not started: '.zfsmSafeText(zfsmScheduleUnreadable()), 'warning');
		return 1;
	}
	$job = $read['valid'][$id] ?? null;
	// Its line outlived the schedule: say, Remove saved the list, and Unraid's crontab was not rebuilt.
	if ($job === null) {
		zfsmLog('Scheduled replication '.$id.' not started: Settings has no such schedule');
		return 0;
	}
	try {
		return zfsmRunReplicate($job['dataset'], zfsmNewSnapshotName($cfg, $now), $job['dest'], null, true, $job['server'], array('notify' => $notify, 'wait' => $wait));
	} catch (Throwable $error) {
		// Cron drops what PHP prints: an error that ends a run is a problem like any other.
		$what = $job['dataset'].' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']);
		zfsmLog('Replication of '.$what.' failed: '.zfsmOneLine($error->getMessage()));
		$notify('ZFS Replication', 'Replication of '.$what.' failed: '.zfsmSafeText(zfsmOneLine($error->getMessage())), 'warning');
		return 1;
	}
}

// Settings' list; $cfg for Snapshots Pattern, which can change after a schedule was saved.
function zfsmSchedulesHtml($cfg) {
	$read = zfsmScheduleRead();
	if ($read === null) return '<span style="color:#e22828">'.zfsmHtml(zfsmScheduleUnreadable()).'</span>';
	$html = $read['valid'] ? '' : 'None yet';
	foreach ($read['valid'] as $job) {
		$failures = zfsmScheduleFailures($job['id']);
		$clash = zfsmScheduleClash($job, $read['valid'], $cfg, time(), false);
		$html .= '<span style="display:block">'.zfsmHtml($job['dataset'].' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']).', '.zfsmScheduleWhen($job))
			.' <input type="button" value="Remove" onclick="zfsmScheduleRemove(\''.$job['id'].'\')">'
			.($clash !== '' ? ' <span style="color:#e22828">'.zfsmHtml($clash).', so that run fails: add the time to Snapshots Pattern</span>' : '')
			.($failures > 0 ? ' <span style="color:#e22828">'.$failures.' run(s) in a row with a problem: the first one\'s notification and /var/log/zfs.master.log say why</span>' : '').'</span>';
	}
	if ($read['other']) $html .= '<span style="display:block;color:#e22828">'.count($read['other']).' record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use</span>';
	return $html;
}
