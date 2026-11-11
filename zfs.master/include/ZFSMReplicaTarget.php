<?php
// Where a copy lives: this server's zfs, or the checker on a trusted server over ssh (include/ZFSMReplicaServe.php).
// Both run the checker's verbs as the same zfs commands (zfsmReplicaVerbArgs), so each answer is read one way.

// '' is this server. A trusted server is asked hello first: its answer is the area every copy there goes inside.
// One that can't be used holds 'error', which the plan refuses with; zfsmReplicaTargetClose removes the RAM key copy.
function zfsmReplicaTargetOpen($server) {
	if ($server === '') return array('server' => '');
	$remote = zfsmReplicaRemotes()[$server] ?? null;
	if ($remote === null) return array('server' => $server, 'error' => 'No server here is named '.$server);
	$files = zfsmReplicaSshFiles($remote);
	if ($files === null) return array('server' => $server, 'error' => 'This server has no key yet, or its RAM copy could not be made');
	$target = array('server' => $server, 'remote' => $remote, 'files' => $files);
	try {
		$hello = zfsmReplicaTargetAsk($target, 'hello', '');
	} catch (Throwable $timed_out) {
		zfsmReplicaSshCleanup($files);
		throw $timed_out;
	}
	// runProcess trims the answer, so it ends at the area's name, which may hold spaces.
	if (($hello['code'] ?? 1) === 0 && preg_match('/\Azfsm-replica 1\narea (.+)\z/', (string)$hello['output'], $match) === 1 && zfsmValidDatasetName($match[1])) {
		return array_merge($target, array('area' => $match[1]));
	}
	zfsmReplicaSshCleanup($files);
	return array('server' => $server, 'error' => zfsmCommandError($hello, 'No answer from '.$server));
}

function zfsmReplicaTargetClose($target) {
	if (isset($target['files'])) zfsmReplicaSshCleanup($target['files']);
}

// Settings' Test: the area on that server, or why there is none.
function zfsmReplicaHello($server) {
	$target = zfsmReplicaTargetOpen($server);
	zfsmReplicaTargetClose($target);
	return isset($target['error']) ? array('error' => $target['error']) : array('area' => $target['area']);
}

// One verb where the copy lives, as runProcess answers it.
function zfsmReplicaTargetAsk($target, $verb, $name) {
	if ($target['server'] === '') return runProcess('zfs', zfsmReplicaVerbArgs($verb, $name));
	return runProcess('ssh', zfsmReplicaSshArgs($target['remote'], $target['files'], 'zfsm-replica 1 '.$verb.($name !== '' ? ' '.bin2hex($name) : '')));
}

// A copy as messages name it: one on another server says which.
function zfsmReplicaWhere($zdest, $server) {
	return $server === '' ? $zdest : $zdest.' on '.$server;
}

// '' when $zdest can hold the copy, else why not. A trusted server's checker takes names inside its area only.
function zfsmReplicaTargetDestError($target, $zdataset, $zdest) {
	if ($target['server'] !== '') {
		$area = $target['area'];
		return zfsmValidDatasetName($zdest) && zfsmStartsWith($zdest, $area.'/') ? '' : 'On '.$target['server'].', the copy goes inside '.$area.', such as '.$area.'/'.basename($zdataset);
	}
	if (!zfsmValidDatasetName($zdest) || strpos($zdest, '/') === false) return 'The copy needs a dataset name inside a pool, such as backup/'.basename($zdataset);
	if ($zdest === $zdataset || zfsmStartsWith($zdest, $zdataset.'/') || zfsmStartsWith($zdataset, $zdest.'/')) {
		return 'The copy can\'t be the dataset itself, go inside it, or replace one of its parents';
	}
	return '';
}

// 'filesystem' or 'volume', '' when it doesn't exist, null when that can't be read.
function zfsmReplicaTargetType($target, $name) {
	$result = zfsmReplicaTargetAsk($target, 'exists', $name);
	if (($result['code'] ?? 1) !== 0) return zfsmNoSuchDataset($result) ? '' : null;
	$field = explode("\t", trim((string)$result['output']));
	return count($field) === 2 && $field[0] === $name ? $field[1] : null;
}

// The token a stopped run left on the copy: '' for none or no copy, null when the read failed.
function zfsmReplicaTargetToken($target, $zdest) {
	$result = zfsmReplicaTargetAsk($target, 'token', $zdest);
	if (($result['code'] ?? 1) !== 0) return zfsmNoSuchDataset($result) ? '' : null;
	$token = trim((string)$result['output']);
	return $token === '-' ? '' : $token;
}

// The copy's snapshots, oldest first; null when they can't be read.
function zfsmReplicaTargetSnapshots($target, $zdest) {
	$result = zfsmReplicaTargetAsk($target, 'snapshots', $zdest);
	return ($result['code'] ?? 1) === 0 ? zfsmReplicaMarksRead($zdest, $result['output']) : null;
}

// The command the stream goes into; null in a test without the fake. The checker there sets the copy's properties from its area.
function zfsmReplicaTargetReceive($target, $plan) {
	$remote = $target['server'] !== '';
	$command = zfsmCommandPath($remote ? 'ssh' : 'zfs');
	if ($command === null) return null;
	return array_merge(array($command), $remote ? zfsmReplicaSshArgs($target['remote'], $target['files'], 'zfsm-replica 1 receive '.bin2hex($plan['dest'])) : zfsmReplicateReceiveArgs($plan));
}

// The receive as the log names it.
function zfsmReplicaTargetReceiveText($target, $plan) {
	if ($target['server'] === '') return 'zfs '.implode(' ', zfsmReplicateReceiveArgs($plan));
	return 'ssh root@'.$target['remote']['host'].' zfsm-replica 1 receive '.$plan['dest'];
}
