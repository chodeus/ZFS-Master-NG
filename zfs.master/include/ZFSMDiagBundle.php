<?php
// A diagnostics run's files made ready to send: every name in them replaced (include/ZFSMDiagNames.php), Unraid's own diagnostics
// beside them as Unraid made them, all in one zip in /boot/logs.

// Unraid's own diagnostics, made by its script under a limit of its own. The zip stays where Unraid put it until the files are
// packed (zfsmDiagPack), so nothing of this plugin's ever rewrites it.
function zfsmDiagUnraidZip($ctx) {
	if (empty($ctx['unraid'])) return 'Unraid\'s own diagnostics were not asked for.';
	$made = zfsmDiagRead(($GLOBALS['docroot'] ?? '/usr/local/emhttp').'/webGui/scripts/diagnostics', array(), 300);
	$zip = preg_match('~^ZIP file \'([^\']+\.zip)\' created\.~m', $made['output'], $said) === 1 ? $said[1] : '';
	// Only a file of its own, in the folder Unraid's script writes to, is taken for its zip.
	if ($made['code'] !== 0 || dirname($zip) !== zfsmStatePath('/boot/logs') || is_link($zip) || !is_file($zip)) {
		if ($made['code'] === 0) zfsmDiagUnread();
		zfsmDiagFinding('Unraid\'s own diagnostics could not be made, so they are not with these files');
		$why = $made['code'] === null ? 'its script ran out of time' : ($made['code'] !== 0 ? 'its script ended with exit code '.$made['code'] : 'its script named no zip of its own in '.zfsmStatePath('/boot/logs'));
		// The line that names a zip names the server too.
		return zfsmDiagSection('Unraid\'s own diagnostics could not be made: '.$why, preg_replace('/^ZIP file .*$/m', '', $made['output']));
	}
	$GLOBALS['zfsm_diag']['unraid_zip'] = $zip;
	return 'Unraid\'s own diagnostics are the zip in this folder, '.filesize($zip).' bytes, as Unraid made them.';
}

// The files that are running text: sentences and command lines, where a name can stand in any shape (include/ZFSMDiagWords.php).
function zfsmDiagRunningText() {
	return array('summary.txt', 'collectors.txt', 'plugin/log.txt', 'plugin/php-errors.txt', 'plugin/jobs.txt', 'plugin/install.txt', 'plugin/schedule.txt',
		'plugin/alerts-state.json.txt', 'zfs/processes.txt', 'zfs/timings.txt');
}

// Rewrites every file of a run with its names replaced, then adds names.txt and writes README.txt again, which holds no name.
// False when a file could not be read, searched or written: its names are still in it, so nothing may be shown or packed.
function zfsmDiagAnonymizeDir($dir) {
	// PHP keeps the last stat it read: from before the folder went, on PHP 8.2 (tests/diagnostics_bundle_test.php, 'a folder that is gone').
	clearstatcache(true, $dir);
	if (!is_dir($dir)) return false;
	unset($GLOBALS['zfsm_diag_names']['pattern']);
	$files = zfsmDiagFilesUnder($dir);
	if ($files === null) return false;
	foreach ($files as $file) {
		$text = zfsmDiagWhole('file_get_contents', $dir.'/'.$file);
		if ($text === false) return false;
		$running = in_array($file, zfsmDiagRunningText(), true);
		$json = substr($file, -9) === '.json.txt';
		$clean = $json ? zfsmDiagAnonJson($text) : ($running ? zfsmDiagAnonRunning($text) : zfsmDiagAnonText($text));
		// A JSON file's values with a space or a tab passed the second net already; in a file of running text all of it passes.
		if ($clean !== null && $json && $running) $clean = zfsmDiagKnownWords($clean);
		if ($clean === null || !zfsmDiagWrite($dir, $file, $clean)) return false;
	}
	return zfsmDiagWrite($dir, 'names.txt', zfsmDiagNamesTable()) && zfsmDiagWrite($dir, 'README.txt', zfsmDiagReadme());
}

// Packs a run's rewritten files, with a copy of Unraid's zip beside them, as [the zip's path in /boot/logs, what was left undone or ''], or
// [null, why not]: then no zip is left, and Unraid's stays where Unraid put it. A zip is written under another name first, so one with the final name is whole.
function zfsmDiagPack($dir) {
	$logs = zfsmStatePath('/boot/logs');
	if (!is_dir($logs) && !@mkdir($logs, 0755, true)) return array(null, $logs.' could not be made');
	$unraid = $GLOBALS['zfsm_diag']['unraid_zip'] ?? null;
	if ($unraid !== null && !@copy($unraid, $dir.'/unraid/'.basename($unraid))) return array(null, 'Unraid\'s own diagnostics could not be copied in from '.$unraid);
	$part = $logs.'/'.basename($dir).'.part';
	// zip names its entries from where it runs: in the run's folder they are plugin/..., zfs/... and unraid/...
	$from = getcwd();
	if (!@chdir($dir)) return array(null, 'the run\'s folder could not be entered');
	try {
		$packed = runProcess('zip', array('-qr', $part, '.'));
	} finally {
		if ($from !== false) @chdir($from);
	}
	list($zip, $failed) = ($packed['code'] ?? 1) !== 0 ? array(null, zfsmCommandError($packed, 'the zip could not be written to '.$logs)) : zfsmDiagPublish($part, $logs);
	if ($zip === null) {
		zfsmDiagRemoveLog($logs, basename($part));
		return array(null, $failed);
	}
	// Unraid's zip is in the plugin's now: one copy of it on the flash drive is enough.
	if ($unraid !== null && dirname($unraid) === $logs) zfsmDiagRemoveLog($logs, basename($unraid));
	$twice = $unraid !== null && (is_link($unraid) || file_exists($unraid)) ? 'Unraid\'s own zip was not removed from '.$logs.', so it is there as well' : '';
	return array($zip, implode("\n", array_filter(array($twice, zfsmDiagKeepNewest($logs, 3, basename($zip))))));
}

// Gives the packed zip its name, [the zip, ''] or [null, why]. One run at a time from picking the name to the rename, so two never
// take one: the lock is in RAM (zfsmJobDir), not on the flash drive.
function zfsmDiagPublish($part, $logs) {
	$file = zfsmJobDir('diagnostics').'/pack.lock';
	$lock = is_dir(dirname($file)) || @mkdir(dirname($file), 0700, true) ? @fopen($file, 'ce') : false;
	if ($lock === false || !flock($lock, LOCK_EX)) return array(null, 'the zip could not be named: no lock could be taken in '.dirname($file));
	// Another run's zip of this second keeps its name: this one takes the next second's.
	for ($second = time(); file_exists($zip = $logs.'/zfs.master-diagnostics-'.date('Ymd-His', $second).'.zip'); $second++);
	$named = @rename($part, $zip);
	zfsmJobUnlock($lock);
	return $named ? array($zip, '') : array(null, 'the zip could not be given its name in '.$logs);
}

// Removes the plugin's own zips in $logs but for $made, the one just written, and the newest of the others, $keep in all; and what
// a run that was killed while packing left there. Only names this file makes are touched, never a link. Says what it could not do, '' when nothing.
function zfsmDiagKeepNewest($logs, $keep, $made) {
	// A folder reached through a link is another folder: nothing is removed there.
	$real = zfsmDiagRealNow($logs);
	if ($real !== $logs) return $real === false ? 'older zips could not be looked for in '.$logs.', so none were removed' : 'older zips were not removed: '.$logs.' is reached through a link';
	$listed = @scandir($logs);
	if ($listed === false) return 'older zips could not be looked for in '.$logs.', so none were removed';
	$zips = $parts = array();
	foreach ($listed as $name) {
		if (is_link($logs.'/'.$name) || !is_file($logs.'/'.$name)) continue;
		if (preg_match('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/D', $name) === 1 && $name !== $made) $zips[] = $name;
		elseif (preg_match('/^zfs\.master-diag\.[0-9a-f]{16}\.part$/D', $name) === 1 && time() - (int)filemtime($logs.'/'.$name) > 3600) $parts[] = $name;
	}
	// The name holds the time, so the names sort by age; a clock that went back must not cost the zip just written.
	sort($zips);
	$left = array();
	foreach (array_merge($parts, array_slice($zips, 0, max(0, count($zips) - $keep + 1))) as $name) {
		$removed = zfsmDiagRemoveLog($logs, $name);
		if ($removed === null) return 'older zips were not all removed: '.$logs.' is gone or reached through a link now';
		if (!$removed) $left[] = $name;
	}
	return $left ? 'older zips could not be removed from '.$logs.': '.implode(', ', $left) : '';
}

// Removes $name from $logs, checked right before: true, false when it could not, null when $logs is no longer the folder itself
// (gone, or reached through a link). Never through a link of its own.
function zfsmDiagRemoveLog($logs, $name) {
	if (zfsmDiagRealNow($logs) !== $logs) return null;
	return !is_link($logs.'/'.$name) && is_file($logs.'/'.$name) && @unlink($logs.'/'.$name);
}

// $path resolved now: PHP keeps a resolution for minutes, and another process can swap a folder for a link in that time.
function zfsmDiagRealNow($path) {
	clearstatcache(true, $path);
	return realpath($path);
}
