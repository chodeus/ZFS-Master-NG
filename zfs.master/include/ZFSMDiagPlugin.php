<?php
// Diagnostics collectors for the plugin's own state and Unraid's (include/ZFSMDiagnostics.php lists them).
// Each returns its file's text and notes what looks wrong with zfsmDiagFinding.

// The file as saved, but for what its owner wrote freely and no path marks as a name: the exclusion pattern and the value of
// a setting this version does not know become tokens, with any further lines such a value runs on to.
function zfsmDiagSettingsSaved($ctx) {
	$shown = array_diff(array_map('zfsmDiagSavedName', array_keys($ctx['cfg'])), array('exclusion'));
	$hidden = false;
	$lines = array();
	foreach (explode("\n", zfsmDiagFile(zfsmStatePath('/boot/config/plugins/zfs.master/zfs.master.cfg'))) as $line) {
		if (preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=(.*)$/s', $line, $set) === 1) {
			$hidden = !in_array($set[1], $shown, true);
			$lines[] = $hidden ? $set[1].' = '.zfsmDiagToken(trim($set[2], " \t\r\"")) : $line;
		} elseif (!$hidden || trim($line) === '' || $line[0] === '[') {
			$hidden = false;
			$lines[] = $line;
		}
	}
	return implode("\n", $lines);
}

// The name a setting is saved under: loadConfig renames the exclusion pattern.
function zfsmDiagSavedName($key) {
	return $key === 'dataset_exclusion' ? 'exclusion' : $key;
}

// Each setting as the plugin uses it, its default, and whether the file set it; then what is wrong with them.
function zfsmDiagSettingsEffective($ctx) {
	$defaults = loadConfig(array());
	$text = "setting\tvalue\tdefault\tsource\n";
	$changed = array();
	foreach (array_merge($ctx['cfg']['directory_listing'], array($ctx['cfg']['replica_accept'])) as $named) zfsmDiagKnow($named);
	foreach ($ctx['cfg'] as $key => $value) {
		$name = zfsmDiagSavedName($key);
		$source = isset($ctx['saved'][$name]) ? 'saved' : ($name === 'exclusion' && isset($ctx['saved']['exclussion']) ? 'saved as exclussion' : 'default');
		$differs = zfsmJson($value) !== zfsmJson(is_int($value) ? (int)$defaults[$key] : $defaults[$key]);
		if ($differs) $changed[] = $name;
		// A pattern its owner wrote can name anything: only a token for it goes in.
		if ($differs && $key === 'dataset_exclusion') $value = zfsmDiagToken($value);
		$text .= $name."\t".zfsmJson($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\t".zfsmJson($defaults[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\t".$source."\n";
	}
	zfsmDiagFact('settings changed from default', $changed ? implode(', ', $changed) : 'none');
	$problems = zfsmDiagSettingProblems($ctx);
	array_map('zfsmDiagFinding', $problems);
	return $text."\n".zfsmDiagSection('problems', $problems ? implode("\n", $problems) : '(none)');
}

// What is wrong with the saved settings, a line each.
function zfsmDiagSettingProblems($ctx) {
	$cfg = $ctx['cfg'];
	$problems = array();
	$known = array_merge(array_map('zfsmDiagSavedName', array_keys($cfg)), array('exclussion'));
	foreach ($ctx['saved'] as $name => $value) {
		if (!in_array($name, $known, true)) $problems[] = 'the settings file has a setting this version does not know: '.$name;
		elseif (is_string($value) && zfsmConfigValueUnsafe($value)) $problems[] = $name.' holds text that a save would refuse';
	}
	$snapshot = zfsmNewSnapshotName($cfg, time());
	if (!zfsmValidSnapshotName('tank@'.$snapshot)) $problems[] = 'the snapshot prefix and pattern give a name ZFS refuses: '.$snapshot;
	$pattern = $cfg['dataset_exclusion'];
	if (!zfsmExclusionIsValid($pattern)) $problems[] = 'the exclusion pattern is invalid, so it is ignored';
	elseif (strpos($pattern, '%') !== false) $problems[] = 'the exclusion pattern is a Lua pattern, the format older versions used';
	return array_merge($problems, zfsmDiagDatasetSettingProblems($cfg));
}

// The settings that name datasets, against the datasets there are.
function zfsmDiagDatasetSettingProblems($cfg) {
	$names = zfsmDiagDatasetNames();
	if ($names === null) return array('the datasets could not be listed, so the exclusion pattern and Directory Listing were not checked against them');
	$problems = array();
	$hidden = count(array_filter($names, function($name) use ($cfg) { return zfsmDatasetExcluded($name, $cfg['dataset_exclusion']); }));
	if ($hidden > 0) $problems[] = 'the exclusion pattern hides '.$hidden.' of '.count($names).' datasets';
	foreach (array_diff(array_filter($cfg['directory_listing'], 'strlen'), $names) as $missing) $problems[] = 'Directory Listing names a dataset that does not exist: '.$missing;
	return $problems;
}

// The manifest's version and update URL as Unraid reads them, and the md5 it wants of its package ('' for one it does not give);
// null when the file is there and can't be read.
function zfsmDiagManifest($file) {
	$text = zfsmDiagWhole('file_get_contents', $file);
	if ($text === false && file_exists($file)) return null;
	$plugin = is_file($file) ? @simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA) : false;
	preg_match('/<!ENTITY\s+md5\s+"([0-9a-f]{32})">/', (string)$text, $md5);
	return array('version' => zfsmPluginVersion($file), 'url' => $plugin ? (string)$plugin['pluginURL'] : '', 'md5' => $md5[1] ?? '');
}

// Whether what runs is the release: Unraid's records of the install, the package the next boot installs, and the files themselves.
function zfsmDiagInstall($ctx) {
	$link = zfsmStatePath('/var/log/plugins/zfs.master.plg');
	$flash = zfsmStatePath('/boot/config/plugins/zfs.master');
	$manifest = zfsmDiagManifest($link);
	// A manifest that can't be read says nothing of the version, the channel or the package: none of them is judged then.
	list($release, $package) = $manifest === null ? array(zfsmDiagUnread("(the manifest could not be read)\n"), null) : zfsmDiagRelease($manifest, $flash);
	$old = array_merge(glob($ctx['dir'].'/nchan') ?: array(), glob($ctx['dir'].'/scripts/zfs_*.lua') ?: array(), glob(zfsmStatePath('/tmp/zfsm_reload')) ?: array());
	if ($package !== null) $old = array_merge($old, array_diff(glob($flash.'/zfs.master-*.t?z') ?: array(), array($package)));
	$wrong = array(
		'the installed manifest is not the one on the flash drive' => realpath($link) === false || realpath($link) !== realpath(zfsmStatePath('/boot/config/plugins/zfs.master.plg')),
		'the plugin\'s folder on the flash drive can\'t be written, so settings can\'t be saved' => !is_writable($flash),
		'files of an older version are left behind' => (bool)$old,
	);
	array_map('zfsmDiagFinding', array_keys(array_filter($wrong)));
	return 'installed manifest: '.$link.' -> '.(realpath($link) ?: 'nothing')."\n".$release
		.'flash folder writable: '.(is_writable($flash) ? 'yes' : 'no')."\n\n"
		.zfsmDiagSection('files of an older version', $old ? implode("\n", $old) : '(none)')
		.zfsmDiagFileCheck($ctx['dir']);
}

// The manifest against Unraid's package records and the package on the flash drive: [its lines of install.txt, that package's path,
// null when the manifest names none].
function zfsmDiagRelease($manifest, $flash) {
	// A manifest with no version names no package: there is nothing to hold the records or the flash drive against.
	if ($manifest['version'] === '') {
		zfsmDiagFact('plugin', 'no version found');
		zfsmDiagFinding('the installed manifest gives no version, so the channel, the package records and the package were not checked');
		return array("version: (none in the manifest)\n", null);
	}
	$package = 'zfs.master-'.$manifest['version'].'-x86_64-1';
	$records = array_map('basename', glob(zfsmStatePath('/var/log/packages/zfs.master-*')) ?: array());
	// False for a package that can't be read: that is a read that failed, not a package that differs.
	$md5 = is_file($flash.'/'.$package.'.txz') ? zfsmDiagWhole('md5_file', $flash.'/'.$package.'.txz') : 'not there';
	zfsmDiagFact('plugin', $manifest['version']);
	$wrong = array(
		'updates do not come from this plugin\'s main or beta channel: '.$manifest['url'] => preg_match('#^https://raw\.githubusercontent\.com/chodeus/ZFS-Master-NG/(main|beta)/zfs\.master\.plg$#D', $manifest['url']) !== 1,
		'the installed package is not the manifest\'s version' => $records !== array($package),
		'the package on the flash drive is missing or not the manifest\'s, so the next boot can\'t install it' => $md5 !== false && $md5 !== $manifest['md5'],
	);
	array_map('zfsmDiagFinding', array_keys(array_filter($wrong)));
	return array('version: '.$manifest['version']."\n"
		.'updates from: '.$manifest['url']."\n"
		.'package records: '.implode(', ', $records)."\n"
		.'package on the flash drive: '.$package.'.txz, md5 '.($md5 === false ? zfsmDiagUnread('could not be read') : $md5).' (the manifest wants '.$manifest['md5'].")\n", $flash.'/'.$package.'.txz');
}

// The installed files against the package's manifest.md5 (pkg_build.sh writes it), and whether the scripts can still run.
function zfsmDiagFileCheck($dir) {
	$listed = zfsmDiagWhole('file_get_contents', $dir.'/manifest.md5');
	if ($listed === false) {
		$there = file_exists($dir.'/manifest.md5');
		zfsmDiagFinding('manifest.md5 '.($there ? 'could not be read' : 'is missing').', so the installed files could not be checked against the release');
		return 'manifest.md5: '.($there ? zfsmDiagUnread('unreadable') : 'not there')."\n";
	}
	$wrong = array('changed' => array(), 'missing' => array(), 'not in the release' => array(), 'not executable' => array(), 'unreadable' => array());
	$expected = array();
	foreach (preg_split('/\R/', $listed, -1, PREG_SPLIT_NO_EMPTY) as $line) {
		list($md5, $file) = explode('  ', $line, 2) + array('', '');
		$expected[$file] = true;
		// A file that can't be read is not one that differs.
		if (!is_file($dir.'/'.$file)) $wrong['missing'][] = $file;
		elseif (($sum = zfsmDiagWhole('md5_file', $dir.'/'.$file)) === false) $wrong['unreadable'][] = zfsmDiagUnread($file);
		elseif ($sum !== $md5) $wrong['changed'][] = $file;
	}
	foreach (zfsmDiagFilesUnder($dir) as $file) if (!isset($expected[$file]) && $file !== 'manifest.md5') $wrong['not in the release'][] = $file;
	foreach (glob($dir.'/scripts/*') ?: array() as $script) if (!is_executable($script)) $wrong['not executable'][] = 'scripts/'.basename($script);
	$text = 'files in the release: '.count($expected)."\n";
	foreach ($wrong as $what => $files) {
		$text .= $what.': '.($files ? implode(', ', $files) : 'none')."\n";
		if ($files) zfsmDiagFinding(count($files).' installed file(s) '.$what.': '.implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ', ...' : ''));
	}
	return $text;
}

// Every file under $dir, as a path from it.
function zfsmDiagFilesUnder($dir) {
	$files = array();
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $entry) $files[] = substr($entry->getPathname(), strlen($dir) + 1);
	sort($files);
	return $files;
}

// Whether a crontab's lines hold the alert check as zfsmAlertCron writes it, not commented out: another of the plugin's jobs
// is not the alert check.
function zfsmDiagAlertLine($lines) {
	return (bool)preg_grep('~^\s*[^#\s].*plugins/zfs\.master/scripts/alerts\.php check(\s|$)~', $lines);
}

// The alert schedule: Unraid builds the live crontab from the cron file, and each check rewrites the state file.
function zfsmDiagSchedule($ctx) {
	$cron = zfsmStatePath('/boot/config/plugins/zfs.master/alerts.cron');
	$state = zfsmStatePath('/var/run/zfs.master.alerts.json');
	list($live, $unread) = zfsmDiagMatchingLines(array(zfsmStatePath('/etc/cron.d/root')), 'zfs.master', 20);
	list($kept, $unkept) = zfsmDiagMatchingLines(array($cron), 'zfs.master', 20);
	$interval = (int)$ctx['cfg']['alert_interval'];
	$checked = is_file($state) ? time() - filemtime($state) : null;
	// A schedule just saved, or a server just started, has not had its first check yet: the cron file's time and the start count as one.
	$uptime = zfsmUptime();
	$quiet = time() - max((int)@filemtime($state), (int)@filemtime($cron), $uptime > 0 ? time() - $uptime : 0);
	if ($interval > 0 && $unread) zfsmDiagFinding('alerts are on, but the crontab could not be read');
	elseif ($interval > 0 && !zfsmDiagAlertLine($live)) zfsmDiagFinding('alerts are on, but the crontab has no line for them');
	elseif ($interval > 0 && $quiet > 120 * $interval) zfsmDiagFinding('alerts are on every '.$interval.' minutes, but the last check was '.(int)($quiet / 60).' minutes ago');
	// A restart builds the crontab from the flash drive's file: without the line there, the checks stop then.
	if ($interval > 0 && !$unkept && !zfsmDiagAlertLine($kept)) zfsmDiagFinding('alerts are on, but the schedule file on the flash drive has no line for them, so they stop at the next restart');
	return 'alerts: '.($interval > 0 ? 'every '.$interval.' minutes' : 'off')."\n"
		.'last check: '.($checked === null ? 'never' : $checked.' seconds ago')."\n\n"
		.zfsmDiagSection($cron, zfsmDiagFile($cron))
		.zfsmDiagSection('its lines in the live crontab', $unread ? '(unreadable)' : ($live ? implode("\n", $live) : '(none)'));
}

function zfsmDiagAlertsState($ctx) {
	return zfsmDiagFile(zfsmStatePath('/var/run/zfs.master.alerts.json'));
}

function zfsmDiagLockState($lock) {
	if (!file_exists($lock)) return 'not there';
	$held = zfsmDiagLockHeld($lock);
	return $held === null ? zfsmDiagUnread('there (the lock table could not be read)') : ($held ? 'held' : 'free');
}

// The locks and who could hold them: a job's lock held while its script is not running is a job that died badly.
function zfsmDiagJobs($ctx) {
	$text = '';
	foreach (array(zfsmStatePath('/var/run/zfs.master.lock'), zfsmStatePath('/var/run/zfs.master.alerts.json').'.lock') as $lock) $text .= $lock.': '.zfsmDiagLockState($lock)."\n";
	$scripts = zfsmDiagProcesses('#'.zfsmDiagScriptPattern().'#');
	$orphaned = array();
	foreach (glob(zfsmJobDir('').'*/*.lock') ?: array() as $lock) {
		$state = zfsmDiagLockState($lock);
		$text .= $lock.': '.$state."\n";
		// A job's folder is named for its script: scripts/rewrite.php holds the locks in the rewrite folder.
		$script = substr(dirname($lock), strlen(zfsmJobDir(''))).'.php';
		if ($state !== 'held' || $scripts === null || !is_file(dirname(__DIR__).'/scripts/'.$script)) continue;
		$runs = array_filter($scripts, function($process) use ($script) { return preg_match('#'.zfsmDiagScriptPattern($script).'(\s|$)#', $process[2]) === 1; });
		if (!$runs) $orphaned[] = $lock;
	}
	if ($orphaned) zfsmDiagFinding('a job\'s lock is held, but its script is not running: '.implode(', ', $orphaned));
	$running = $scripts === null ? '(the process list could not be read)' : implode("\n", array_map(function($process) { return implode("\t", $process); }, $scripts));
	return $text."\n".zfsmDiagSection('running plugin scripts (pid, seconds, command)', $running !== '' ? $running : '(none)');
}

// The host key by its fingerprint, or why there is none: a file that can't be read is a read that failed, one that holds no key is not.
function zfsmDiagHostKey($file) {
	if (!file_exists($file)) return 'not there';
	if (zfsmDiagWhole('file_get_contents', $file) === false) return zfsmDiagUnread('unreadable');
	return zfsmHostKeyFingerprint($file) ?? 'not an ed25519 key';
}

// The senders' areas the receiver refuses requests into, by its own rule (zfsmReplicaAreaHolds): <accept>/<sender> must hold every one
// of zfsmReplicaAreaProps. Another dataset under the accepting one is no area. $output is the read of them, name, property, value.
function zfsmDiagAreasRefused($output, $accept, $senders) {
	$areas = array_map(function($sender) use ($accept) { return zfsmReplicaArea($accept, $sender); }, $senders);
	$found = array();
	foreach (zfsmImpactRows($output) as $row) {
		$field = explode("\t", $row);
		if (count($field) >= 3 && in_array($field[0], $areas, true)) $found[$field[0]][$field[1]] = $field[2];
	}
	return array_keys(array_filter($found, function($props) { return array_diff_assoc(zfsmReplicaAreaProps(), $props) !== array(); }));
}

// The receiving side: the dataset copies go into, its safety properties, and whether each sender's key is held to replication.
// Each key's own text stays out.
function zfsmDiagReplicaReceive($ctx) {
	$accept = $ctx['cfg']['replica_accept'];
	$keys = zfsmStatePath('/boot/config/ssh/root/authorized_keys');
	$sender_keys = zfsmReplicaKeys($keys);
	$text = 'accept replicas into: '.($accept !== '' ? $accept : '(off)')."\n".'host key: '.zfsmDiagHostKey(zfsmStatePath('/etc/ssh/ssh_host_ed25519_key.pub'))."\n\n";
	if ($accept !== '' && !zfsmReplicaAcceptValid($accept)) zfsmDiagFinding('Accept Replicas Into is not a dataset inside a pool, so nothing is accepted');
	elseif ($accept !== '') {
		$wanted = zfsmReplicaAreaProps();
		$read = zfsmDiagPoolRead(explode('/', $accept)[0], 'zfs', array('get', '-H', '-d', '1', '-t', 'filesystem,volume', '-o', 'name,property,value,source', implode(',', array_keys($wanted)), $accept));
		if ($read['code'] !== 0) zfsmDiagFinding('the dataset Accept Replicas Into names could not be read');
		foreach (zfsmDiagAreasRefused($read['output'], $accept, array_column($sender_keys, 'sender')) as $area) zfsmDiagFinding('a sender\'s area under Accept Replicas Into does not keep '.implodeWithKeys(', ', $wanted, '=').', so requests into it are refused: '.$area);
		$text .= zfsmDiagShow('the accepting dataset and the datasets under it (a sender\'s area, named for the sender, needs '.implodeWithKeys(', ', $wanted, '=').')', $read);
	}
	$senders = !file_exists($keys) ? '(the file is not there)' : (zfsmDiagWhole('file_get_contents', $keys) === false ? zfsmDiagUnread('(the file could not be read)') : '');
	foreach ($sender_keys as $key) {
		array_map('zfsmDiagKnowWord', array($key['sender'], $key['comment']));
		$checks = array('restrict' => $key['restrict'], 'from' => $key['from'], 'name' => zfsmReplicaSenderName($key['sender']));
		$senders .= ($key['sender'] !== '' ? $key['sender'] : '?').' ('.$key['comment'].'): '.implodeWithKeys(', ', array_map(function($held) { return $held ? 'ok' : 'NOT OK'; }, $checks))."\n";
		if (!zfsmReplicaKeyHeld($key)) zfsmDiagFinding('a sender key\'s line does not hold it to replication: '.($key['sender'] !== '' ? $key['sender'] : '?'));
	}
	return $text.zfsmDiagSection('sender keys in root\'s authorized_keys', $senders !== '' ? $senders : '(none)');
}

// What the Main tab's first read returns, logging a failure as that read does. It asks about every pool, so it is skipped once
// a read has timed out on one: it would wait its whole limit there again.
function zfsmDiagInventory($ctx) {
	zfsmDiagPools();
	$hung = array_unique(array_filter($GLOBALS['zfsm_diag']['hung']));
	if ($hung) {
		$GLOBALS['zfsm_diag']['timed_out'] = true;
		return 'skipped, because '.implode(', ', $hung).' timed out on a pool';
	}
	$inventory = zfsmReadInventory($ctx['cfg'], false);
	// A pool that read failed on is in its answer, not thrown; one whose devices could not be read has none (getZFSPoolDevices).
	if (!empty($inventory['data']['errors']) || in_array('', $inventory['data']['devices'] ?? array(), true)) zfsmDiagUnread();
	return zfsmJson($inventory, JSON_UNESCAPED_SLASHES);
}

// The plugin's own log, then every line of the system log and its older files that names the plugin.
function zfsmDiagLog($ctx) {
	$syslogs = glob(zfsmStatePath('/var/log/syslog').'.*') ?: array();
	rsort($syslogs, SORT_NATURAL);
	$syslogs[] = zfsmStatePath('/var/log/syslog');
	$lines = zfsmDiagPluginLines($syslogs, 5000);
	// A system log's line names the server after its time: "Jan  2 10:00:00 Tower ident[pid]: ...".
	preg_match_all('/^[A-Z][a-z]{2} [ \d]\d \d\d:\d\d:\d\d (\S+) /m', $lines, $hosts);
	array_map('zfsmDiagKnowServer', array_unique($hosts[1]));
	return zfsmDiagSection(zfsmLogFile().'.1', zfsmDiagFile(zfsmLogFile().'.1'))
		.zfsmDiagSection(zfsmLogFile(), zfsmDiagFile(zfsmLogFile()))
		.zfsmDiagSection('zfs.master lines in '.implode(', ', $syslogs), $lines);
}

function zfsmDiagPhpErrors($ctx) {
	$text = '';
	foreach (array(zfsmStatePath('/var/log/phplog'), zfsmStatePath('/var/log/nginx/error.log')) as $file) $text .= zfsmDiagSection('zfs.master lines in '.$file, zfsmDiagPluginLines(array($file), 500));
	return $text;
}

// One value of an ini file a collector read: a file that could not be read is a read that failed, a value it does not hold is not.
function zfsmDiagIniValue($ini, $key) {
	return is_array($ini) ? (string)($ini[$key] ?? 'not in the file') : zfsmDiagUnread('not read');
}

// What the plugin reads of Unraid: its version, the array's state, the display settings, the ZFS pools Unraid expects, the other plugins.
function zfsmDiagUnraid($ctx) {
	$version = zfsmDiagIniValue(zfsmDiagWhole('parse_ini_file', zfsmStatePath('/etc/unraid-version')), 'version');
	$var = zfsmDiagWhole('parse_ini_file', zfsmStatePath('/var/local/emhttp/var.ini'));
	$disks = zfsmDiagWhole('parse_ini_file', zfsmStatePath('/var/local/emhttp/disks.ini'), true);
	$display = function_exists('parse_plugin_cfg') ? (parse_plugin_cfg('dynamix', true)['display'] ?? array()) : array();
	if (is_array($var)) zfsmDiagKnowServer($var['NAME'] ?? '');
	zfsmDiagFact('Unraid', $version);
	zfsmDiagFact('PHP', PHP_VERSION);
	$pools = array();
	foreach (is_array($disks) ? $disks : array() as $disk) {
		if (zfsmExpectedPools(array($disk))) zfsmDiagKnowPool($disk['name'] ?? '');
		if (zfsmExpectedPools(array($disk))) $pools[] = implodeWithKeys(' ', array_intersect_key($disk, array_flip(array('name', 'type', 'fsType', 'fsStatus', 'status', 'luksState'))), '=');
	}
	$plugins = array();
	foreach (glob(zfsmStatePath('/var/log/plugins/*.plg')) ?: array() as $manifest) {
		$plugins[] = basename($manifest, '.plg').' '.(zfsmDiagWhole('file_get_contents', $manifest) === false ? zfsmDiagUnread('(unreadable)') : zfsmPluginVersion($manifest));
	}
	return 'Unraid: '.$version."\n".'PHP: '.PHP_VERSION."\n"
		.'array: mdState='.zfsmDiagIniValue($var, 'mdState').', fsState='.zfsmDiagIniValue($var, 'fsState')."\n"
		.'publishing paused: '.(is_file(zfsmStatePath('/tmp/publishPaused')) ? 'yes' : 'no')."\n\n"
		.zfsmDiagSection('display settings', implodeWithKeys("\n", array_filter($display, 'is_scalar'), '='))
		.zfsmDiagSection('ZFS pools and disks Unraid lists', is_array($disks) ? implode("\n", $pools) : zfsmDiagUnread('(disks.ini could not be read)'))
		.zfsmDiagSection('installed plugins', implode("\n", $plugins));
}
