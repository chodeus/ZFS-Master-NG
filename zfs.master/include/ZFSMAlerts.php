<?php
// Pool and snapshot alerts, run by Unraid's cron while the setting is on (scripts/alerts.php). Reads only, apart from its state file.

define('ZFSM_ALERT_GRACE', 600);

// The cron file for the setting: Unraid's own format (dynamix monitor.cron); '' when alerts are off.
function zfsmAlertCron($interval, $script) {
	$when = array(15 => '*/15 * * * *', 60 => '7 * * * *', 1440 => '17 4 * * *');
	if (!isset($when[(int)$interval])) return '';
	return "# Generated ZFS Master NG alert schedule:\n".$when[(int)$interval].' '.$script." check &> /dev/null\n\n";
}

// Each pool in `zpool status -p` output: its state, devices that are not ONLINE or have errors, and its errors: line.
function zfsmParsePoolStatus($output) {
	$pools = array();
	$name = null;
	$in_config = false;
	foreach (zfsmImpactRows($output) as $line) {
		if (preg_match('/^\s*pool:\s*(\S+)/', $line, $match)) {
			$name = $match[1];
			$in_config = false;
			$pools[$name] = array('state' => '', 'devices' => array(), 'errors' => '', 'text' => '');
		}
		if ($name === null) continue;
		$pools[$name]['text'] .= $line."\n";
		if (preg_match('/^\s*state:\s*(\S+)/', $line, $match)) $pools[$name]['state'] = $match[1];
		if (preg_match('/^\s*errors:\s*(.+)$/', $line, $match)) $pools[$name]['errors'] = trim($match[1]);
		// Wrapped status: and action: text is tab-indented too, so device rows count only under the table header.
		if (preg_match('/^\s*NAME\s+STATE\s+READ\s+WRITE\s+CKSUM/', $line)) $in_config = true;
		elseif ($in_config && ($issue = zfsmDeviceIssue($line, $name))) $pools[$name]['devices'][] = $issue;
	}
	return $pools;
}

// "sdb: UNAVAIL corrupted data" or "sda: 0 read, 0 write, 12 checksum errors" for a config row; null when the device is fine.
function zfsmDeviceIssue($line, $pool) {
	if (!preg_match('/^\t\s*(\S+)\s+(\S+)(?:\s+(\d+)\s+(\d+)\s+(\d+))?\s*(.*)$/', $line, $match) || $match[1] === $pool) return null;
	$counts = isset($match[5]) && $match[5] !== '' ? $match[3] + $match[4] + $match[5] : 0;
	// Spares wait AVAIL or serve INUSE; the logs, cache and spares headings have no state.
	$healthy = in_array($match[2], array('ONLINE', 'AVAIL', 'INUSE'), true) || !preg_match('/^[A-Z]+$/', $match[2]);
	if ($counts > 0) return $match[1].': '.$match[3].' read, '.$match[4].' write, '.$match[5].' checksum errors';
	return $healthy ? null : trim($match[1].': '.$match[2].' '.$match[6]);
}

// A pool's problem as a notice, or null when it is ONLINE with no errors.
function zfsmPoolProblem($name, $pool) {
	$details = $pool['devices'];
	if ($pool['errors'] !== 'No known data errors') $details[] = 'errors: '.$pool['errors'];
	if ($pool['state'] === 'ONLINE' && !$details) return null;
	$title = $pool['state'] === 'ONLINE' ? 'ZFS pool '.$name.' has errors' : 'ZFS pool '.$name.' is '.$pool['state'];
	return array('title' => $title, 'detail' => $details ? implode('; ', $details) : 'zpool status shows the pool as '.$pool['state'], 'level' => 'alert', 'message' => $pool['text']);
}

// ZFS pools Unraid lists in disks.ini, encrypted ones (luks:zfs) too; array disks formatted ZFS are pools named after the disk.
function zfsmExpectedPools($disks) {
	$names = array();
	foreach (is_array($disks) ? $disks : array() as $disk) {
		if (preg_match('/(^|:)zfs$/', $disk['fsType'] ?? '') && ($disk['name'] ?? '') !== '') $names[] = $disk['name'];
	}
	return $names;
}

// The problems under these key prefixes from the last run: what a failed read or the boot grace couldn't recheck stays as it was.
function zfsmCarriedProblems($previous, $prefixes) {
	return array_filter($previous, function($key) use ($prefixes) {
		foreach ($prefixes as $prefix) if (strncmp($key, $prefix, strlen($prefix)) === 0) return true;
		return false;
	}, ARRAY_FILTER_USE_KEY);
}

// One notice for the datasets whose newest snapshot is older than $max_days; null when none is.
function zfsmStaleProblem($output, $now, $max_days, $exclusion) {
	$newest = array();
	foreach (zfsmImpactRows($output) as $line) {
		$fields = explode("\t", $line);
		if (count($fields) < 2 || strpos($fields[0], '@') === false) continue;
		$dataset = explode('@', $fields[0], 2)[0];
		$newest[$dataset] = max($newest[$dataset] ?? 0, (int)$fields[1]);
	}
	$stale = array();
	// The Main tab's rule (datasetSnapInfo in ZFSMFrontEnd.js): whole days since the newest snapshot, more than the setting.
	foreach ($newest as $dataset => $creation) {
		if (intdiv($now - $creation, 86400) > $max_days && !zfsmMatchesExclusion($dataset, $exclusion)) $stale[] = $dataset;
	}
	if (!$stale) return null;
	sort($stale, SORT_STRING);
	$shown = implode(', ', array_slice($stale, 0, 10)).(count($stale) > 10 ? ' and '.(count($stale) - 10).' more' : '');
	return array('title' => 'Snapshots are out of date', 'detail' => count($stale).' dataset(s) have no snapshot in the last '.$max_days.' days: '.$shown, 'level' => 'warning', 'items' => $stale);
}

// A read for the check, under a time limit so a hung pool can't stack up cron runs; a timeout is a failed read.
function zfsmAlertRead($command, $args) {
	$GLOBALS['zfsm_inventory_deadline'] = microtime(true) + ($GLOBALS['zfsm_alert_read_seconds'] ?? 120);
	try {
		return runProcess($command, $args);
	} catch (RuntimeException $error) {
		return array('code' => 1, 'output' => $error->getMessage());
	} finally {
		unset($GLOBALS['zfsm_inventory_deadline']);
	}
}

// What is wrong now, keyed so the next run can tell what is new and what has cleared; $missing_due once pools must be imported.
function zfsmAlertProblems($cfg, $now, $previous, $disks, $missing_due) {
	$problems = array();
	$status = zfsmAlertRead('zpool', array('status', '-p'));
	if (($status['code'] ?? 1) !== 0) {
		$problems = zfsmCarriedProblems($previous, array('pool:', 'missing:'));
		$problems['read:pools'] = array('title' => 'ZFS Master NG could not read pool health', 'detail' => zfsmCommandError($status, 'zpool status failed'), 'level' => 'warning');
	} else {
		$pools = zfsmParsePoolStatus($status['output']);
		foreach ($pools as $name => $pool) if ($problem = zfsmPoolProblem($name, $pool)) $problems['pool:'.$name] = $problem;
		if (!$missing_due) {
			$problems += zfsmCarriedProblems($previous, array('missing:'));
		} else {
			foreach (array_diff(zfsmExpectedPools($disks), array_keys($pools)) as $name) {
				$problems['missing:'.$name] = array('title' => 'ZFS pool '.$name.' is not imported', 'detail' => 'Unraid lists '.$name.' as a ZFS pool, but ZFS has not imported it since the array started.', 'level' => 'alert');
			}
		}
	}
	if ((int)$cfg['alert_stale'] !== 1) return $problems;
	$snapshots = zfsmAlertRead('zfs', array('list', '-H', '-p', '-t', 'snapshot', '-o', 'name,creation'));
	if (($snapshots['code'] ?? 1) !== 0) {
		$problems += zfsmCarriedProblems($previous, array('stale'));
		$problems['read:snapshots'] = array('title' => 'ZFS Master NG could not read snapshots', 'detail' => zfsmCommandError($snapshots, 'zfs list failed'), 'level' => 'warning');
	} elseif ($problem = zfsmStaleProblem($snapshots['output'], $now, (int)$cfg['snap_max_days_alert'], $cfg['dataset_exclusion'])) {
		$problems['stale'] = $problem;
	}
	return $problems;
}

// Notices, keyed like the problems, for those that are new or worse (a new title or newly listed items) and for those that cleared.
function zfsmAlertNotices($previous, $current) {
	$notices = array();
	foreach ($current as $key => $problem) {
		$before = $previous[$key] ?? null;
		if ($before === null || $before['title'] !== $problem['title'] || array_diff($problem['items'] ?? array(), $before['items'] ?? array())) $notices[$key] = $problem;
	}
	foreach (array_diff_key($previous, $current) as $key => $problem) {
		$notices[$key] = array('title' => 'Resolved: '.$problem['title'], 'detail' => 'No longer found at the last check.', 'level' => 'normal');
	}
	return $notices;
}

// Whether pools must be imported by now: Unraid has mounted everything, and 10 minutes have passed since the boot or the last check that saw the array stopped.
function zfsmMissingDue($array, $state, $now) {
	$uptime = (int)(float)@file_get_contents($GLOBALS['zfsm_uptime'] ?? '/proc/uptime');
	$since = max($now - $uptime, (int)($state['stopped_at'] ?? 0));
	return ($array['fsState'] ?? '') === 'Started' && $uptime > 0 && $now - $since >= ZFSM_ALERT_GRACE;
}

// One check: nothing until the array has started, then a notice for each change since the last run.
function zfsmRunAlerts($cfg, $now) {
	// A job left behind after alerts were turned off does nothing.
	if ((int)$cfg['alert_interval'] === 0) return;
	$state_file = $GLOBALS['zfsm_alert_state'] ?? '/var/run/zfs.master.alerts.json';
	$lock = @fopen($state_file.'.lock', 'c');
	if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return;
	$state = json_decode((string)@file_get_contents($state_file), true);
	$state = is_array($state) ? $state : array();
	$emhttp = $GLOBALS['zfsm_emhttp'] ?? '/var/local/emhttp';
	$array = @parse_ini_file($emhttp.'/var.ini');
	if (($array['mdState'] ?? '') !== 'STARTED') {
		$state['stopped_at'] = $now;
	} else {
		$previous = $state['problems'] ?? array();
		$problems = zfsmAlertProblems($cfg, $now, $previous, @parse_ini_file($emhttp.'/disks.ini', true), zfsmMissingDue($array, $state, $now));
		foreach (zfsmAlertNotices($previous, $problems) as $key => $notice) {
			if (zfsnotify($notice['title'], $notice['detail'], substr($notice['message'] ?? '', 0, 4000), $notice['level'])) continue;
			// Not sent: leave this one as it was, so the next run sends it again.
			if (isset($previous[$key])) $problems[$key] = $previous[$key];
			else unset($problems[$key]);
		}
		$state['problems'] = array_map(function($problem) { unset($problem['message']); return $problem; }, $problems);
	}
	file_put_contents($state_file.'.tmp', json_encode($state));
	rename($state_file.'.tmp', $state_file);
	flock($lock, LOCK_UN);
}
