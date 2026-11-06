<?php
// A snapshot's files: where Unraid's file browser opens them, and what changed since. Reads only.

define('ZFSM_DIFF_LIMIT', 1000);

// Where Unraid's file browser lists a dataset's snapshots; '' unless mounted under /mnt or /boot, the only places it opens.
function zfsmSnapshotBrowseRoot($mountpoint) {
	return is_string($mountpoint) && preg_match('#^/(?:mnt|boot)(?:/|$)#', $mountpoint) ? rtrim($mountpoint, '/').'/.zfs/snapshot' : '';
}

// zfs diff writes spaces, backslashes and every non-ASCII byte of a name as \0ooo.
function zfsmDiffName($text) {
	return preg_replace_callback('/\\\\(0[0-7]{3})/', function($match) { return chr(octdec($match[1])); }, $text);
}

// One change per `zfs diff -H -F` line, sorted by path; null when a line is not in that form.
function zfsmParseDiff($output) {
	$changes = array();
	foreach (zfsmImpactRows($output) as $line) {
		$fields = explode("\t", $line);
		if (count($fields) < 3 || !in_array($fields[0], array('+', '-', 'M', 'R'), true) || strlen($fields[1]) !== 1) return null;
		if ($fields[0] === 'R' && count($fields) !== 4) return null;
		$change = array('change' => $fields[0], 'type' => $fields[1], 'path' => zfsmDiffName($fields[2]));
		if ($fields[0] === 'R') $change['to'] = zfsmDiffName($fields[3]);
		$changes[] = $change;
	}
	usort($changes, function($a, $b) { return strcmp($a['path'], $b['path']); });
	return $changes;
}

// What changed from the snapshot to its live dataset, at most ZFSM_DIFF_LIMIT entries; 'error' instead when zfs can't say.
function zfsmSnapshotDiff($snapshot) {
	if (!zfsmValidSnapshotName($snapshot)) return array('error' => 'Invalid snapshot name');
	$result = runProcess('zfs', array('diff', '-H', '-F', $snapshot), null, ZFSM_DIFF_LIMIT);
	if (($result['code'] ?? 1) !== 0) return array('error' => zfsmCommandError($result, 'Unable to compare the snapshot'));
	$changes = zfsmParseDiff($result['output']);
	if ($changes === null) return array('error' => 'Unable to read what zfs diff printed');
	return array('changes' => $changes, 'truncated' => !empty($result['truncated']));
}
