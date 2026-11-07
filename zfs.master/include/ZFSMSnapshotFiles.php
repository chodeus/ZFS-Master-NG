<?php
// A snapshot's files: where Unraid's file browser opens them, and what changed since. Reads only.

define('ZFSM_DIFF_LIMIT', 1000);

// Where Unraid's file browser lists a dataset's snapshots; '' unless mounted under /mnt or /boot, all that 7.2+ opens.
function zfsmSnapshotBrowseRoot($mountpoint) {
	return is_string($mountpoint) && preg_match('#^/(?:mnt|boot)(?:/|$)#', $mountpoint) ? rtrim($mountpoint, '/').'/.zfs/snapshot' : '';
}

// What a dataset's snapshot rows offer; both need it mounted with its .zfs folder. A failed read offers neither.
function zfsmSnapshotAccess($zpool, $zdataset) {
	$mountpoint = getDatasetProperty($zpool, $zdataset, 'mounted') === 'yes' ? getDatasetProperty($zpool, $zdataset, 'mountpoint') : null;
	// zfs diff stats .zfs/shares first; snapdir=disabled, or a filesystem mounted over this one, leaves no .zfs.
	$has_zfs = is_string($mountpoint) && strncmp($mountpoint, '/', 1) === 0 && is_dir(rtrim($mountpoint, '/').'/.zfs/snapshot');
	return array('diff' => $has_zfs, 'browse' => $has_zfs ? zfsmSnapshotBrowseRoot($mountpoint) : '');
}

// zfs diff writes spaces, backslashes and every non-ASCII byte of a name as \0ooo, from \0000 to \0377.
function zfsmDiffName($text) {
	return preg_replace_callback('/\\\\(0[0-3][0-7]{2})/', function($match) { return chr((int)octdec($match[1])); }, $text);
}

// One `zfs diff -H -F` line as a change; null for anything else, such as an error zfs wrote to the same pipe.
function zfsmDiffChange($line) {
	$fields = explode("\t", (string)$line);
	if (count($fields) < 3 || !in_array($fields[0], array('+', '-', 'M', 'R'), true) || strlen($fields[1]) !== 1) return null;
	if ($fields[0] !== 'R') return array('change' => $fields[0], 'type' => $fields[1], 'path' => zfsmDiffName($fields[2]));
	return count($fields) === 4 ? array('change' => 'R', 'type' => $fields[1], 'path' => zfsmDiffName($fields[2]), 'to' => zfsmDiffName($fields[3])) : null;
}

// What changed from the snapshot to its live dataset, at most ZFSM_DIFF_LIMIT entries by path; 'error' instead when zfs can't say.
function zfsmSnapshotDiff($snapshot) {
	if (!zfsmValidSnapshotName($snapshot)) return array('error' => 'Invalid snapshot name');
	$result = runProcess('zfs', array('diff', '-H', '-F', $snapshot), null, ZFSM_DIFF_LIMIT);
	if (($result['code'] ?? 1) !== 0) {
		$lines = array_merge(zfsmImpactRows($result['output']), isset($result['next_line']) ? array($result['next_line']) : array());
		$errors = array_filter($lines, function($line) { return zfsmDiffChange($line) === null; });
		return array('error' => zfsmCommandError(array('code' => $result['code'], 'output' => implode("\n", $errors)), 'Unable to compare the snapshot'));
	}
	$truncated = !empty($result['truncated']);
	// The line after the limit tells a longer diff from zfs failing right there.
	if ($truncated && zfsmDiffChange($result['next_line']) === null) return array('error' => zfsmOneLine($result['next_line']));
	$changes = array();
	foreach (zfsmImpactRows($result['output']) as $line) {
		$change = zfsmDiffChange($line);
		if ($change === null) return array('error' => zfsmOneLine($line));
		$changes[] = $change;
	}
	usort($changes, function($a, $b) { return strcmp($a['path'], $b['path']); });
	return array('changes' => $changes, 'truncated' => $truncated);
}
