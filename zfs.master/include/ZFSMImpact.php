<?php
// What a destroy or rollback would remove, read with dry runs, and who is keeping a dataset busy. Reads only.

function zfsmImpactRows($output) {
	return preg_split('/\r\n|\r|\n/', trim((string)$output), -1, PREG_SPLIT_NO_EMPTY) ?: array();
}

// Binds a confirmation to the target, the mode and what the dialog showed: a change to any refuses the action.
function zfsmImpactToken($target, $mode, $objects, $others) {
	sort($objects, SORT_STRING);
	sort($others, SORT_STRING);
	return hash('sha256', json_encode(array($target, $mode, $objects, $others)));
}

function zfsmValidDestroyTarget($target) {
	if (!is_string($target) || substr_count($target, '@') > 1) return false;
	if (strpos($target, '@') === false) return zfsmValidDatasetName($target);
	list($dataset, $names) = explode('@', $target, 2);
	if (!zfsmValidDatasetName($dataset)) return false;
	foreach (explode(',', $names) as $name) if (!zfsmValidNameComponent($name) || strlen($name) > 255) return false;
	return true;
}

// Datasets outside $objects cloned from something in it; null when that can't be read.
function zfsmClonesOf($objects, $pool) {
	if (!$objects) return array();
	$result = runProcess('zfs', array('list', '-H', '-r', '-t', 'filesystem,volume', '-o', 'name,origin', $pool));
	if (($result['code'] ?? 1) !== 0) return null;
	$known = array_flip($objects);
	$clones = array();
	foreach (zfsmImpactRows($result['output'] ?? '') as $row) {
		$parts = explode("\t", $row);
		if (count($parts) === 2 && isset($known[$parts[1]]) && !isset($known[$parts[0]])) $clones[] = $parts[0].' (from '.$parts[1].')';
	}
	return $clones;
}

// Snapshots in $objects with a hold; null when that can't be read.
function zfsmHeldOf($objects, $dataset) {
	if (!array_filter($objects, function($name) { return strpos($name, '@') !== false; })) return array();
	$result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'snapshot', '-o', 'name,userrefs', $dataset));
	if (($result['code'] ?? 1) !== 0) return null;
	$known = array_flip($objects);
	$held = array();
	foreach (zfsmImpactRows($result['output'] ?? '') as $row) {
		$parts = explode("\t", $row);
		if (count($parts) === 2 && (int)$parts[1] > 0 && isset($known[$parts[0]])) $held[] = $parts[0];
	}
	return $held;
}

// ZFS only prints a reclaim figure for snapshots; null when this can't be read either.
function zfsmDatasetUsed($dataset) {
	$result = runProcess('zfs', array('list', '-H', '-p', '-o', 'used', $dataset));
	$used = trim($result['output'] ?? '');
	return ($result['code'] ?? 1) === 0 && ctype_digit($used) ? (float)$used : null;
}

// Why a destroy target can't be previewed; '' when it can.
function zfsmDestroyTargetRefusal($target) {
	if (!zfsmValidDestroyTarget($target)) return 'Invalid dataset or snapshot name';
	if (strpos($target, '@') === false && strpos($target, '/') === false) return 'A pool\'s root dataset cannot be destroyed here';
	return '';
}

// [what it would destroy, what it would reclaim or null] from `zfs destroy -n -v -p` output.
function zfsmDestroyDryRun($output) {
	$objects = array();
	$reclaim = null;
	foreach (zfsmImpactRows($output) as $row) {
		$parts = explode("\t", $row);
		if (count($parts) !== 2) continue;
		if ($parts[0] === 'destroy') $objects[] = $parts[1];
		if ($parts[0] === 'reclaim') $reclaim = (float)$parts[1];
	}
	return array($objects, $reclaim);
}

// The preview with its clones, holds and token; its error set instead when clones and holds can't be read.
function zfsmPreviewFinish($preview, $target, $mode, $objects, $dataset) {
	$clones = zfsmClonesOf($objects, explode('/', $dataset, 2)[0]);
	$held = zfsmHeldOf($objects, $dataset);
	if ($clones === null || $held === null) {
		$preview['error'] = 'Unable to check for clones and holds';
		return $preview;
	}
	$preview['clones'] = $clones;
	$preview['held'] = $held;
	$preview['can_proceed'] = !$clones && !$held;
	$preview['token'] = zfsmImpactToken($target, $mode, $objects, array_merge($clones, $held));
	return $preview;
}

function zfsmDestroyPreview($target, $recursive) {
	$preview = array('objects' => array(), 'reclaim' => null, 'clones' => array(), 'held' => array(), 'error' => '', 'can_proceed' => false, 'token' => '');
	$preview['error'] = zfsmDestroyTargetRefusal($target);
	if ($preview['error'] !== '') return $preview;
	$force = (string)$recursive === '1';
	$args = array('destroy', '-n', '-v', '-p');
	if ($force) $args[] = '-r';
	$args[] = $target;
	$result = runProcess('zfs', $args);
	list($preview['objects'], $preview['reclaim']) = zfsmDestroyDryRun($result['output'] ?? '');
	if (($result['code'] ?? 1) !== 0) {
		$preview['error'] = zfsmCommandError($result, 'ZFS refused the dry run');
		return $preview;
	}
	if (!$preview['objects']) {
		$preview['error'] = 'ZFS reported nothing to destroy';
		return $preview;
	}
	if ($preview['reclaim'] === null) $preview['reclaim'] = zfsmDatasetUsed($target);
	// A leaf lists the same with or without force, but force also unmounts: the mode is part of what was reviewed.
	return zfsmPreviewFinish($preview, $target, $force ? 'force' : 'plain', $preview['objects'], explode('@', $target, 2)[0]);
}

// createtxg is 64-bit, so compare it as a decimal string.
function zfsmTxgIsNewer($txg, $than) {
	return strlen($txg) === strlen($than) ? strcmp($txg, $than) > 0 : strlen($txg) > strlen($than);
}

function zfsmRollbackPreview($snapshot) {
	$preview = array('newer' => array(), 'clones' => array(), 'held' => array(), 'error' => '', 'can_proceed' => false, 'token' => '');
	if (!zfsmValidSnapshotName($snapshot)) {
		$preview['error'] = 'Invalid snapshot name';
		return $preview;
	}
	$dataset = explode('@', $snapshot, 2)[0];
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', 'snapshot,bookmark', '-o', 'name,createtxg', $dataset));
	if (($result['code'] ?? 1) !== 0) {
		$preview['error'] = zfsmCommandError($result, 'Unable to list snapshots');
		return $preview;
	}
	$rows = array();
	foreach (zfsmImpactRows($result['output'] ?? '') as $row) {
		$parts = explode("\t", $row);
		if (count($parts) === 2 && ctype_digit($parts[1])) $rows[$parts[0]] = $parts[1];
	}
	if (!isset($rows[$snapshot])) {
		$preview['error'] = 'Snapshot no longer exists';
		return $preview;
	}
	foreach ($rows as $name => $txg) if (zfsmTxgIsNewer($txg, $rows[$snapshot])) $preview['newer'][] = $name;
	return zfsmPreviewFinish($preview, $snapshot, 'rollback', $preview['newer'], $dataset);
}

// Why a confirmation can't go ahead; '' when it matches a review that still stands.
function zfsmReviewRefusal($preview, $token, $changed) {
	if ($preview['error'] !== '') return $preview['error'];
	if (!$preview['can_proceed']) return 'Clones or holds block this. Nothing was changed; please review it again.';
	return is_string($token) && hash_equals($preview['token'], $token) ? '' : $changed;
}

function zfsmDestroyReviewed($dataset, $force, $token) {
	$refusal = zfsmReviewRefusal(zfsmDestroyPreview($dataset, $force), $token, 'What this would destroy has changed since you reviewed it. Nothing was destroyed; please review it again.');
	if ($refusal !== '') return zfsmFailure($dataset, $refusal);
	return zfsmExplainBusy(destroyDataset($dataset, $force), $dataset);
}

// Snapshots only (ds@a or ds@a,b): the route's gate assumes no dataset can be destroyed through it.
function zfsmDeleteSnapshotsReviewed($snapshots, $recursive, $token) {
	$refusal = zfsmReviewRefusal(zfsmDestroyPreview($snapshots, $recursive), $token, 'The snapshots this would delete have changed since you reviewed them. Nothing was deleted; please review them again.');
	if ($refusal !== '') return zfsmFailure($snapshots, $refusal);
	return zfsmExplainBusy(deleteDatasetSnapshot($snapshots, $recursive), explode('@', $snapshots, 2)[0]);
}

function zfsmRollbackReviewed($snapshot, $destroy_newer, $token) {
	$refusal = zfsmReviewRefusal(zfsmRollbackPreview($snapshot), $token, 'The snapshots this would remove have changed since you reviewed them. Nothing was rolled back; please review it again.');
	if ($refusal !== '') return zfsmFailure($snapshot, $refusal);
	return zfsmExplainBusy(rollbackDatasetSnapshot($snapshot, $destroy_newer), explode('@', $snapshot, 2)[0]);
}

function zfsmUnescapeMount($field) {
	return preg_replace_callback('/\\\\([0-7]{3})/', function($match) { return chr(octdec($match[1])); }, $field);
}

// [filesystem type, source, root inside that filesystem] for each mountinfo line.
function zfsmMountinfoRows($mountinfo) {
	$rows = array();
	foreach (zfsmImpactRows($mountinfo) as $line) {
		$sides = explode(' - ', $line, 2);
		$after = explode(' ', $sides[1] ?? '');
		if (isset($after[1])) $rows[] = array($after[0], zfsmUnescapeMount($after[1]), zfsmUnescapeMount(explode(' ', $sides[0])[3] ?? ''));
	}
	return $rows;
}

function zfsmMountinfoHasDataset($mountinfo, $dataset) {
	foreach (zfsmMountinfoRows($mountinfo) as $row) {
		if ($row[0] === 'zfs' && ($row[1] === $dataset || zfsmStartsWith($row[1], $dataset.'/') || zfsmStartsWith($row[1], $dataset.'@'))) return true;
	}
	return false;
}

// One direction only: a container binding all of /mnt/user is not a user of every share.
function zfsmMountinfoHasShare($mountinfo, $shares) {
	foreach (zfsmMountinfoRows($mountinfo) as $row) if ($row[0] === 'fuse.shfs' && zfsmUnderAny($row[2], $shares)) return true;
	return false;
}

// /mnt/<pool>/<share> as Unraid's user shares see it: '/<share>'.
function zfsmShareViews($paths) {
	$shares = array();
	foreach ($paths as $path) if (preg_match('~^/mnt/[^/]+(/.+)$~', $path, $match) === 1) $shares[] = $match[1];
	return $shares;
}

function zfsmContainerNames($ps_output) {
	$names = array();
	foreach (zfsmImpactRows($ps_output) as $row) {
		$parts = explode(' ', $row, 2);
		if (count($parts) === 2) $names[$parts[0]] = $parts[1];
	}
	return $names;
}

function zfsmReadContainerNames() {
	$previous = $GLOBALS['zfsm_inventory_deadline'] ?? null;
	$GLOBALS['zfsm_inventory_deadline'] = microtime(true) + 3;
	try {
		$result = runProcess('docker', array('ps', '--no-trunc', '--format', '{{.ID}} {{.Names}}'));
		return ($result['code'] ?? 1) === 0 ? zfsmContainerNames($result['output'] ?? '') : array();
	} catch (RuntimeException $error) {
		return array();
	} finally {
		if ($previous === null) unset($GLOBALS['zfsm_inventory_deadline']);
		else $GLOBALS['zfsm_inventory_deadline'] = $previous;
	}
}

// '' when the namespace does not use the dataset; null when its mountinfo can't be read, as when the process just exited.
function zfsmNamespaceUser($base, $pid, $command, $dataset, $shares) {
	static $containers = null;
	$mountinfo = @file_get_contents($base.'/mountinfo');
	if ($mountinfo === false) return null;
	$how = zfsmMountinfoHasDataset($mountinfo, $dataset) ? 'has it mounted' : (zfsmMountinfoHasShare($mountinfo, $shares) ? 'may be using it through /mnt/user' : '');
	if ($how === '') return '';
	if (preg_match('~(?:/docker/|docker-)([a-f0-9]{64})~', (string)@file_get_contents($base.'/cgroup'), $match) !== 1) return 'process '.$command.' (pid '.$pid.') '.$how;
	if ($containers === null) $containers = zfsmReadContainerNames();
	return 'container '.($containers[$match[1]] ?? substr($match[1], 0, 12)).' '.$how;
}

// The mountpoints under a dataset plus their /mnt/user views, and the share views alone: [paths, shares]; null when zfs can't list them.
function zfsmDatasetPaths($dataset) {
	$mounts = runProcess('zfs', array('list', '-H', '-r', '-t', 'filesystem', '-o', 'name,mountpoint', $dataset));
	if (($mounts['code'] ?? 1) !== 0) return null;
	$paths = array();
	foreach (zfsmImpactRows($mounts['output'] ?? '') as $row) {
		$parts = explode("\t", $row);
		if (count($parts) === 2 && ($parts[1][0] ?? '') === '/' && $parts[1] !== '/') $paths[] = rtrim($parts[1], '/');
	}
	$shares = zfsmShareViews($paths);
	foreach ($shares as $share) $paths[] = '/mnt/user'.$share;
	return array($paths, $shares);
}

// A host process whose working directory or an open file is under $paths; '' when it has none.
function zfsmProcessUser($base, $pid, $command, $paths) {
	$links = array(@readlink($base.'/cwd'));
	foreach (array_slice(glob($base.'/fd/*', GLOB_NOSORT) ?: array(), 0, 4096) as $fd) $links[] = @readlink($fd);
	foreach ($links as $link) {
		if (is_string($link) && zfsmUnderAny($link, $paths)) return 'process '.$command.' (pid '.$pid.') is using '.zfsmSafeText($link);
	}
	return '';
}

// Who is using a dataset: containers holding it, and host processes with files open under it. Best effort.
function zfsmDatasetUsers($dataset, $proc = '/proc') {
	$listed = zfsmDatasetPaths($dataset);
	list($paths, $shares) = $listed ?? array(array(), array());
	$self = @readlink('/proc/self/ns/mnt');
	$deadline = microtime(true) + 3;
	$seen_namespaces = array();
	$users = $listed === null ? array('(zfs could not list its mountpoints, so open files were not checked)') : array();
	foreach (glob($proc.'/[0-9]*', GLOB_ONLYDIR) ?: array() as $base) {
		if (microtime(true) > $deadline) {
			$users[] = '(stopped looking after 3 seconds, there may be more)';
			break;
		}
		$pid = (int)basename($base);
		$namespace = @readlink($base.'/ns/mnt');
		if ($pid === getmypid() || $namespace === false || isset($seen_namespaces[$namespace])) continue;
		$command = zfsmSafeText(trim((string)@file_get_contents($base.'/comm')));
		if ($namespace === $self) {
			$users[] = zfsmProcessUser($base, $pid, $command, $paths);
			continue;
		}
		$user = zfsmNamespaceUser($base, $pid, $command, $dataset, $shares);
		// Marked seen only once read, so a process that just exited leaves its namespace to the next one in it.
		if ($user === null) continue;
		$seen_namespaces[$namespace] = true;
		$users[] = $user;
	}
	return array_values(array_unique(array_filter($users, function($user) { return $user !== ''; })));
}

function zfsmActionLogLines($title, $answer) {
	$lines = array();
	foreach ($answer['succeeded'] ?? array() as $subject => $value) $lines[] = $title.': '.$subject.' succeeded';
	foreach ($answer['failed'] ?? array() as $subject => $message) $lines[] = $title.': '.$subject.' failed: '.(is_scalar($message) ? $message : 'see the dialog');
	return $lines;
}

function zfsmExplainBusy($answer, $dataset) {
	foreach ($answer['failed'] ?? array() as $subject => $message) {
		if (!is_string($message) || stripos($message, 'busy') === false) continue;
		$users = zfsmDatasetUsers($dataset);
		$answer['failed'][$subject] = rtrim($message, '. ').'. '.($users ? 'Using it now: '.implode('; ', array_slice($users, 0, 10)) : 'No process using it could be found; a reboot may clear a stale mount.');
	}
	return $answer;
}

?>
