<?php
// The diagnostics files: one collector each (scripts/diagnostics.php runs them), so a hung pool or a broken collector never loses
// the rest. A time limit covers the commands a collector runs; a file read that stalls, as on a failing flash drive, has none.
require_once __DIR__.'/ZFSMDiagPlugin.php';
require_once __DIR__.'/ZFSMDiagZfs.php';

// [file, time limit in seconds, collector, the paths it covers]. A path under /boot, /var, /etc or /tmp named anywhere in the plugin
// must be here or in zfsmDiagNotCollected (tests/diagnostics_test.php), so a feature that keeps new state adds its line.
function zfsmDiagCollectors() {
	return array(
		array('plugin/settings-saved.cfg.txt', 10, 'zfsmDiagSettingsSaved', array('/boot/config/plugins/', '/boot/config/plugins/zfs.master/zfs.master.cfg')),
		array('plugin/settings-effective.txt', 90, 'zfsmDiagSettingsEffective', array()),
		array('plugin/install.txt', 30, 'zfsmDiagInstall', array('/var/log/plugins/zfs.master.plg', '/boot/config/plugins/zfs.master.plg', '/var/log/packages/zfs.master-*', '/boot/config/plugins/zfs.master', '/tmp/zfsm_reload')),
		array('plugin/schedule.txt', 10, 'zfsmDiagSchedule', array('/boot/config/plugins/zfs.master/alerts.cron', '/etc/cron.d/root')),
		array('plugin/alerts-state.json.txt', 10, 'zfsmDiagAlertsState', array('/var/run/zfs.master.alerts.json')),
		array('plugin/jobs.txt', 20, 'zfsmDiagJobs', array('/var/run/zfs.master.lock', '/var/run/zfs.master.')),
		array('plugin/replication-receive.txt', 30, 'zfsmDiagReplicaReceive', array('/boot/config/ssh/root/authorized_keys', '/etc/ssh/ssh_host_ed25519_key.pub')),
		array('plugin/replication-send.txt', 10, 'zfsmDiagReplicaSend', array('/boot/config/plugins/zfs.master/ssh', '/boot/config/ident.cfg')),
		array('plugin/inventory.json.txt', 60, 'zfsmDiagInventory', array()),
		array('plugin/log.txt', 30, 'zfsmDiagLog', array('/var/log/zfs.master.log', '/var/log/syslog')),
		array('plugin/php-errors.txt', 30, 'zfsmDiagPhpErrors', array('/var/log/phplog', '/var/log/nginx/error.log')),
		array('unraid/context.txt', 20, 'zfsmDiagUnraid', array('/etc/unraid-version', '/var/local/emhttp', '/var/local/emhttp/var.ini', '/var/local/emhttp/disks.ini', '/tmp/publishPaused', '/var/log/plugins/*.plg')),
		array('zfs/versions.txt', 20, 'zfsmDiagZfsVersions', array()),
		array('zfs/zpool-list.txt', 30, 'zfsmDiagZpoolList', array()),
		array('zfs/zpool-status.txt', 60, 'zfsmDiagZpoolStatus', array()),
		array('zfs/zpool-get-all.txt', 60, 'zfsmDiagZpoolGetAll', array()),
		array('zfs/datasets.txt', 90, 'zfsmDiagDatasets', array()),
		array('zfs/bookmarks.txt', 90, 'zfsmDiagBookmarks', array()),
		array('zfs/user-properties.txt', 90, 'zfsmDiagUserProperties', array()),
		array('zfs/mounts.txt', 10, 'zfsmDiagMounts', array('/boot')),
		array('zfs/processes.txt', 20, 'zfsmDiagZfsProcesses', array()),
		// The slowest read, so last of the pool reads: a pool it times out on costs no other file.
		array('zfs/snapshots-summary.txt', 150, 'zfsmDiagSnapshots', array()),
		array('zfs/timings.txt', 5, 'zfsmDiagTimings', array()),
	);
}

// State the files leave out, and why; README.txt prints it.
function zfsmDiagNotCollected() {
	return array(
		'/tmp/zfs.master-diag.' => 'the work folder of a diagnostics run itself',
		'/etc/zfs/compatibility.d' => 'OpenZFS\'s own feature lists, the same on every server with that OpenZFS (zfs/versions.txt)',
	);
}

// What every collector gets: the settings as saved and as the plugin uses them, and the installed plugin's folder.
function zfsmDiagContext($config) {
	$saved = is_array($config) && is_array($config['general'] ?? null) ? $config['general'] : array();
	return array('saved' => $saved, 'cfg' => loadConfig($config), 'dir' => dirname(__DIR__));
}

// What the collectors note as they go: summary.txt's facts and findings, each read's time, and the pools a read timed out on.
function zfsmDiagStart() {
	$facts = array_fill_keys(array('plugin', 'Unraid', 'PHP', 'OpenZFS', 'pools', 'datasets', 'snapshots', 'settings changed from default'), 'not read');
	$GLOBALS['zfsm_diag'] = array('facts' => $facts, 'findings' => array(), 'timings' => array(), 'pools' => null, 'hung' => array(), 'timed_out' => false, 'unread' => false);
}

function zfsmDiagFact($name, $value) {
	$GLOBALS['zfsm_diag']['facts'][$name] = $value;
}

function zfsmDiagFinding($text) {
	$GLOBALS['zfsm_diag']['findings'][] = $text;
}

// Marks the file being collected as one with a read that failed, and gives back the text that says so in it:
// its result is then "read failed", not ok, and the summary names it.
function zfsmDiagUnread($text = '') {
	$GLOBALS['zfsm_diag']['unread'] = true;
	return $text;
}

// A time limit as it is used: tests shrink them all by one factor, so each keeps its place among the others.
function zfsmDiagSeconds($seconds) {
	return $seconds * ($GLOBALS['zfsm_diag_scale'] ?? 1);
}

// Writes every file into $dir; false when one could not be written. A collector that throws, runs out of time or could not
// read something is noted in collectors.txt and the summary, and the rest still run.
function zfsmDiagRun($dir, $ctx, $collectors = null) {
	zfsmDiagStart();
	$report = "file\tresult\tseconds\n";
	$unfinished = array('timed out' => array(), 'read failed' => array(), 'failed' => array());
	$written = true;
	foreach ($collectors ?? zfsmDiagCollectors() as $collector) {
		list($file, $seconds, $collect) = $collector;
		$seconds = zfsmDiagSeconds($seconds);
		$GLOBALS['zfsm_diag']['timed_out'] = false;
		$GLOBALS['zfsm_diag']['unread'] = false;
		$began = microtime(true);
		try {
			$text = zfsmWithDeadline($seconds, function() use ($collect, $ctx) { return $collect($ctx); });
			$result = $GLOBALS['zfsm_diag']['timed_out'] ? 'timed out' : ($GLOBALS['zfsm_diag']['unread'] ? 'read failed' : 'ok');
		} catch (Throwable $error) {
			$result = $error instanceof ZFSMTimeout ? 'timed out' : 'failed';
			$text = $result.': '.$error->getMessage();
		}
		if ($result !== 'ok') $unfinished[$result][] = $file;
		$written = zfsmDiagWrite($dir.'/'.$file, $text) && $written;
		$report .= $file."\t".$result."\t".number_format(microtime(true) - $began, 1, '.', '')."\n";
	}
	if ($unfinished['timed out']) zfsmDiagFinding('a read timed out, so these files are incomplete: '.implode(', ', $unfinished['timed out']));
	if ($unfinished['read failed']) zfsmDiagFinding('a read failed, so these files are incomplete: '.implode(', ', $unfinished['read failed']));
	if ($unfinished['failed']) zfsmDiagFinding('a collector failed, so these files hold only its error: '.implode(', ', $unfinished['failed']));
	$written = zfsmDiagWrite($dir.'/collectors.txt', $report) && $written;
	$written = zfsmDiagWrite($dir.'/summary.txt', zfsmDiagSummary()) && $written;
	return zfsmDiagWrite($dir.'/README.txt', zfsmDiagReadme()) && $written;
}

// One screen: the versions and counts the collectors noted, then what they found wrong.
function zfsmDiagSummary() {
	$text = 'ZFS Master NG diagnostics, '.date('Y-m-d H:i:s T')."\n\n";
	foreach ($GLOBALS['zfsm_diag']['facts'] as $name => $value) $text .= $name.': '.$value."\n";
	$findings = $GLOBALS['zfsm_diag']['findings'];
	$text .= "\nFINDINGS (".count($findings).")\n";
	foreach ($findings as $finding) $text .= '- '.zfsmOneLine($finding)."\n";
	return $text;
}

function zfsmDiagReadme() {
	$text = "ZFS Master NG diagnostics\n\n"
		."summary.txt     versions, counts and the FINDINGS: what looks wrong with the plugin, its settings, the pools or this run's own reads\n"
		."collectors.txt  each file: ok, read failed, timed out or failed, and the seconds it took\n"
		."plugin/         settings, install, alert schedule, jobs, replication and the plugin's log\n"
		."zfs/            pools, datasets, snapshot counts, bookmarks, mounts and running commands\n"
		."unraid/         the Unraid version, array state, display settings and installed plugins\n\n"
		."Left out, and why:\n";
	foreach (zfsmDiagNotCollected() as $path => $why) $text .= $path.': '.$why."\n";
	return $text;
}

function zfsmDiagWrite($file, $text) {
	if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true)) return false;
	$text = rtrim((string)$text, "\n")."\n";
	return @file_put_contents($file, $text) === strlen($text);
}

// A new folder only root can read, for the files while they hold real names; null when it can't be made. Its run holds a lock on it
// to its end. A killed run's folder has a free lock a minute after it was made: a new run first tries to remove those (zfsmDiagRemove).
function zfsmDiagWorkDir() {
	static $holds = array();
	$prefix = zfsmStatePath('/tmp/zfs.master-diag.');
	foreach (glob($prefix.'*') ?: array() as $old) {
		// Kept in a variable: the lock must last through the removal.
		$mine = time() - (int)@filemtime($old) > 60 ? zfsmDiagHold($old, LOCK_NB) : false;
		if ($mine !== false) zfsmDiagRemove($old);
	}
	$dir = $prefix.bin2hex(random_bytes(8));
	$hold = @mkdir($dir, 0700) ? zfsmDiagHold($dir) : false;
	if ($hold === false) return null;
	$holds[] = $hold;
	return $dir;
}

// The lock on a work folder, for the run that made it or the one removing it; false when another holds it ($wait is LOCK_NB),
// and when the path is not, or is no longer, that folder and root's own: a link or another user's folder can be swapped under a removal.
function zfsmDiagHold($dir, $wait = 0) {
	// 'e': a command the run starts must not carry the lock on past the run's end.
	$hold = is_dir($dir) ? @fopen($dir, 're') : false;
	if ($hold === false || !flock($hold, LOCK_EX | $wait)) return false;
	clearstatcache(true, $dir);
	// lstat: the path itself, never what a link at it points to.
	$now = @lstat($dir);
	$held = fstat($hold);
	return $now !== false && $now['dev'] === $held['dev'] && $now['ino'] === $held['ino'] && $held['uid'] === 0 ? $hold : false;
}

// Removes a work folder and says whether it did: only one zfsmDiagWorkDir made, never through a link, and never one that is
// or holds a mount point (zfsmNestedMountProblem): what is mounted there is not the run's to remove.
function zfsmDiagRemove($dir) {
	$name = substr((string)$dir, -16);
	if ($dir !== zfsmStatePath('/tmp/zfs.master-diag.').$name || !ctype_xdigit($name) || is_link($dir) || realpath($dir) !== $dir || zfsmNestedMountProblem($dir) !== '') return false;
	$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($entries as $entry) {
		if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
		else @unlink($entry->getPathname());
	}
	@rmdir($dir);
	return !is_dir($dir);
}

// What a read of a whole file gives ($read is file_get_contents, md5_file or parse_ini_file); false when the file can't be opened,
// and when its read failed part-way: PHP then answers with the part it had.
function zfsmDiagWhole($read, ...$args) {
	error_clear_last();
	$got = @$read(...$args);
	return error_get_last() === null ? $got : false;
}

// A file's text; one that isn't there or can't be read says so instead of reading as empty.
function zfsmDiagFile($file) {
	if (!file_exists($file)) return '(not there)';
	$text = zfsmDiagWhole('file_get_contents', $file);
	return $text === false ? zfsmDiagUnread('(unreadable)') : $text;
}

function zfsmDiagSection($title, $text) {
	return '== '.$title."\n".rtrim((string)$text, "\n")."\n\n";
}

// [the last $keep lines of $files that hold $needle, the files that are there but can't be read to their end], a line at a time:
// a syslog can fill /var/log's 128 MB.
function zfsmDiagMatchingLines($files, $needle, $keep) {
	$lines = array();
	$unread = array();
	foreach ($files as $file) {
		$handle = @fopen($file, 'r');
		if ($handle === false) {
			if (file_exists($file)) $unread[] = zfsmDiagUnread($file);
			continue;
		}
		error_clear_last();
		while (($line = @fgets($handle)) !== false) {
			if (strpos($line, $needle) === false) continue;
			$lines[] = rtrim($line, "\r\n");
			if (count($lines) >= 2 * $keep) $lines = array_slice($lines, -$keep);
		}
		// A read that failed ends the loop as the end of the file does: only the error tells them apart.
		if (error_get_last() !== null) $unread[] = zfsmDiagUnread($file);
		fclose($handle);
	}
	return array(array_slice($lines, -$keep), $unread);
}

// The last $keep lines of $files that name the plugin; a log that is not there or can't be read says so instead of reading as quiet.
function zfsmDiagPluginLines($files, $keep) {
	if (!array_filter($files, 'file_exists')) return '(not there)';
	list($lines, $unread) = zfsmDiagMatchingLines($files, 'zfs.master', $keep);
	foreach ($unread as $file) $lines[] = '(unreadable: '.$file.')';
	return $lines ? implode("\n", $lines) : '(no lines)';
}

// A read for a file: runProcess's answer, its time kept for zfs/timings.txt. A read past its limit comes back with code null,
// and one that failed marks its file.
function zfsmDiagRead($command, $args, $seconds = 30) {
	$began = microtime(true);
	$result = zfsmReadWithin(zfsmDiagSeconds($seconds), $command, $args);
	if ($result['code'] === null) $GLOBALS['zfsm_diag']['timed_out'] = true;
	elseif ($result['code'] !== 0) zfsmDiagUnread();
	$GLOBALS['zfsm_diag']['timings'][] = number_format(microtime(true) - $began, 2, '.', '')."\t".($result['code'] === null ? 'timed out' : 'exit '.$result['code'])."\t".$command.' '.implode(' ', $args);
	return $result;
}

// A read's output under its title; a failed or timed-out read says so instead of looking empty.
function zfsmDiagShow($title, $result) {
	$failed = $result['code'] === null ? 'TIMED OUT: ' : 'FAILED (exit '.$result['code'].'): ';
	return zfsmDiagSection($title, ($result['code'] === 0 ? '' : $failed).$result['output']);
}

// Whether a lock file is held, read from the kernel's lock table: taking a lock to find out could cost its owner a turn.
// Null when the table can't be read.
function zfsmDiagLockHeld($lock) {
	$stat = @stat($lock);
	$table = zfsmDiagWhole('file_get_contents', '/proc/locks');
	if ($stat === false || !is_string($table)) return null;
	$device = sprintf('%02x:%02x', (($stat['dev'] >> 8) & 0xfff) | (($stat['dev'] >> 32) & ~0xfff), ($stat['dev'] & 0xff) | (($stat['dev'] >> 12) & ~0xff));
	return preg_match('/^\d+: FLOCK\s+\S+\s+\S+\s+-?\d+\s+'.$device.':'.$stat['ino'].'\s/m', $table) === 1;
}

// One of the plugin's scripts as php runs it ($script names one, '' is any): never another command whose arguments name one.
function zfsmDiagScriptPattern($script = '') {
	return '^(\S*/)?php\S*(\s+-\S+)*\s+\S*plugins/zfs\.master/scripts/'.preg_quote($script, '#');
}

// The processes whose command line matches, as [pid, seconds running, command line], this one left out; null when ps can't be read.
function zfsmDiagProcesses($pattern) {
	$result = zfsmDiagRead('ps', array('-eo', 'pid=,etimes=,args='));
	if ($result['code'] !== 0) return null;
	$found = array();
	foreach (zfsmImpactRows($result['output']) as $line) {
		if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $match) !== 1 || (int)$match[1] === getmypid() || preg_match($pattern, $match[3]) !== 1) continue;
		$found[] = array((int)$match[1], (int)$match[2], $match[3]);
	}
	return $found;
}
