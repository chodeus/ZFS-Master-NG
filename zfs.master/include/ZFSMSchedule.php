<?php
// Scheduled replication: the schedules (schedules.json), their cron file (schedules.cron) and each run cron starts (scripts/schedule.php).
require_once __DIR__.'/ZFSMJobs.php';
require_once __DIR__.'/ZFSMReplicaTarget.php';

define('ZFSM_SCHEDULE_SCRIPT', '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php');
define('ZFSM_SCHEDULE_DAYS', array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'));
define('ZFSM_SCHEDULE_PERIODS', array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800));
define('ZFSM_SCHEDULE_APART', array('hourly' => 'an hour', 'daily' => 'a day', 'weekly' => 'a week'));

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

// schedules.json as 'valid' schedules by id and 'other' entries, kept as they are for a write; null when it can't be read or isn't a list.
function zfsmScheduleRead() {
	$file = zfsmScheduleFile();
	if (!file_exists($file)) return array('valid' => array(), 'other' => array());
	// Read as objects first: as arrays, {} is an empty list and one schedule's record a list of its fields.
	$text = (string)@file_get_contents($file);
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
// in other shapes as they were, then the cron file to match. A file that can't be read is never written over. '' or why not.
function zfsmScheduleChange($change) {
	$dir = zfsmJobDir('schedule');
	$lock = zfsmJobLock($dir.'/settings.lock', false);
	if ($lock === null) return 'Could not open the schedule lock in '.$dir;
	if ($lock === false) return 'Another change to the schedules is being saved; try again';
	try {
		$read = zfsmScheduleRead();
		if ($read === null) return zfsmScheduleUnreadable();
		$jobs = $change($read['valid']);
		if (is_string($jobs)) return $jobs;
		$file = zfsmScheduleFile();
		if (!zfsmWriteFileAtomic($file, zfsmJson(array_merge(array_values($jobs), $read['other']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) return 'Could not save '.$file;
		return zfsmScheduleCron($jobs);
	} finally {
		zfsmJobUnlock($lock);
	}
}

// The minute past the hour a schedule runs at, from its id, so schedules made together don't all start at once.
function zfsmScheduleMinute($id) {
	return hexdec(substr($id, 0, 4)) % 60;
}

// Unraid's cron format, as zfsmAlertCron writes alerts.cron; each line names only its schedule's id. '' with no schedules.
function zfsmScheduleCronText($jobs) {
	$lines = '';
	foreach ($jobs as $job) {
		$hour = $job['every'] === 'hourly' ? '*' : $job['hour'];
		$day = $job['every'] === 'weekly' ? $job['day'] : '*';
		$lines .= zfsmScheduleMinute($job['id']).' '.$hour.' * * '.$day.' '.ZFSM_SCHEDULE_SCRIPT.' run '.$job['id']." &> /dev/null\n";
	}
	return $lines === '' ? '' : "# Generated ZFS Master NG replication schedules:\n".$lines."\n";
}

// Whether a change zfsmScheduleChange answered $why for was saved: only its cron step can fail after that, and the next change redoes it.
function zfsmScheduleSaved($why) {
	return $why === '' || strpos($why, 'The schedules were saved') === 0;
}

// Writes the cron file for $jobs (with none, removes it) and has Unraid rebuild its crontab from every plugin's; '' or why not.
function zfsmScheduleCron($jobs) {
	$file = zfsmScheduleCronFile();
	$text = zfsmScheduleCronText($jobs);
	$written = $text === '' ? !file_exists($file) || @unlink($file) : zfsmWriteFileAtomic($file, $text);
	if (!$written) return 'The schedules were saved, but '.$file.' could not be written, so cron still runs the ones it had';
	$built = runProcess(zfsmStatePath('/usr/local/sbin/update_cron'));
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
function zfsmScheduleAdd($zdataset, $zdest, $server, $every, $hour, $day, $cfg) {
	$job = array('id' => bin2hex(random_bytes(4)), 'dataset' => $zdataset, 'dest' => $zdest, 'server' => $server, 'every' => $every,
		'hour' => $every === 'hourly' ? 0 : $hour, 'day' => $every === 'weekly' ? $day : 0);
	if (!zfsmScheduleValid($job)) return zfsmFailure($zdataset, 'Nothing was scheduled: pick how often and when, and give the copy a name as Replicate does');
	$now = time();
	// The snapshot a run takes is named by Snapshots Pattern: one with no hour, say, names the next run's the same, and that run fails.
	if (zfsmNewSnapshotName($cfg, $now) === zfsmNewSnapshotName($cfg, $now + ZFSM_SCHEDULE_PERIODS[$every])) {
		return zfsmFailure($zdataset, 'Nothing was scheduled: Snapshots Pattern would give two runs '.ZFSM_SCHEDULE_APART[$every].' apart the same snapshot name; add the time to it in Settings');
	}
	$read = zfsmScheduleRead();
	if ($read === null) return zfsmFailure($zdataset, 'Nothing was scheduled: '.zfsmScheduleUnreadable());
	$same = zfsmScheduleSame($read['valid'], $job);
	if ($same !== '') return zfsmFailure($zdataset, 'Nothing was scheduled: '.$same);
	$started = zfsmStartReplicate($zdataset, '', $zdest, $cfg, $server);
	if (!isset($started['succeeded'][$zdataset])) return zfsmFailure($zdataset, 'Nothing was scheduled: '.($started['failed'][$zdataset] ?? 'the first run did not start'));
	$why = zfsmScheduleChange(function($jobs) use ($job) {
		$same = zfsmScheduleSame($jobs, $job);
		if ($same !== '') return $same;
		$jobs[$job['id']] = $job;
		return $jobs;
	});
	$said = $started['succeeded'][$zdataset];
	if ($why === '') return array('succeeded' => array($zdataset => $said.'. It runs again '.zfsmScheduleWhen($job)), 'failed' => array());
	return array('succeeded' => array($zdataset => $said), 'failed' => array($zdataset => (zfsmScheduleSaved($why) ? '' : 'It was not scheduled: ').$why));
}

// Why $job can't be added beside $jobs: one for the same copy already runs it. '' when none does.
function zfsmScheduleSame($jobs, $job) {
	foreach ($jobs as $other) {
		if ($other['dataset'] === $job['dataset'] && $other['dest'] === $job['dest'] && $other['server'] === $job['server']) return 'this copy already has a schedule ('.zfsmScheduleWhen($other).'); remove it in Settings first';
	}
	return '';
}

function zfsmScheduleRemove($id) {
	if (!zfsmScheduleId($id)) return 'No such schedule';
	$why = zfsmScheduleChange(function($jobs) use ($id) {
		if (!isset($jobs[$id])) return 'No such schedule; it may have been removed already';
		unset($jobs[$id]);
		return $jobs;
	});
	if (zfsmScheduleSaved($why)) @unlink(zfsmScheduleStateFile($id));
	return $why;
}

function zfsmScheduleFailures($id) {
	$state = json_decode((string)@file_get_contents(zfsmScheduleStateFile($id)), true);
	return is_array($state) && is_int($state['failures'] ?? null) && $state['failures'] > 0 ? $state['failures'] : 0;
}

// A scheduled run notifies of its first problem (a warning) and of the run that works again; each problem is logged with its count,
// as the schedule runs again within the hour, day or week. A count that can't be kept notifies of every problem, rather than of none.
function zfsmScheduleNotifier($id) {
	return function($title, $text, $type) use ($id) {
		$failures = zfsmScheduleFailures($id);
		$file = zfsmScheduleStateFile($id);
		if ($type === 'normal') {
			if ($failures === 0) return;
			@unlink($file);
			zfsnotify($title, $text.' It works again after '.$failures.' run(s) with a problem.', '', 'normal');
			return;
		}
		$failures++;
		if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
		$kept = zfsmWriteFileAtomic($file, zfsmJson(array('failures' => $failures))."\n");
		zfsmLog('Scheduled replication '.$id.': '.$failures.' run(s) in a row with a problem');
		if (!$kept) zfsnotify($title, $text, '', $type);
		elseif ($failures === 1) zfsnotify($title, rtrim($text, '.').'. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.', '', $type);
	};
}

// Cron's run of one schedule: the job read by its id, and a run that takes its snapshot first, as Replicate's "Take one now" does.
function zfsmScheduleRun($id, $cfg, $now) {
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
	return zfsmRunReplicate($job['dataset'], zfsmNewSnapshotName($cfg, $now), $job['dest'], null, true, $job['server'], array('notify' => $notify));
}

function zfsmSchedulesHtml() {
	$read = zfsmScheduleRead();
	if ($read === null) return '<span style="color:#e22828">'.zfsmHtml(zfsmScheduleUnreadable()).'</span>';
	$html = $read['valid'] ? '' : 'None yet';
	foreach ($read['valid'] as $job) {
		$failures = zfsmScheduleFailures($job['id']);
		$html .= '<span style="display:block">'.zfsmHtml($job['dataset'].' to '.zfsmReplicaTargetWhere(array('server' => $job['server']), $job['dest']).', '.zfsmScheduleWhen($job))
			.' <input type="button" value="Remove" onclick="zfsmScheduleRemove(\''.$job['id'].'\')">'
			.($failures > 0 ? ' <span style="color:#e22828">'.$failures.' run(s) in a row with a problem: the first one\'s notification and /var/log/zfs.master.log say why</span>' : '').'</span>';
	}
	if ($read['other']) $html .= '<span style="display:block;color:#e22828">'.count($read['other']).' record(s) in '.zfsmHtml(zfsmScheduleFile()).' are not schedules the plugin can use</span>';
	return $html;
}
