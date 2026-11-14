<?php
// Where a copy lives: this server's zfs, or a trusted server's checker (include/ZFSMReplicaServe.php) on one ssh connection.
// A plan or run opens one target and asks it everything about the copy; zfs send and the source's own reads stay on this server.

// '' is this server; a trusted server's name gives its connection (zfsmReplicaConnect) and its name, or array('error' => why).
// zfsmReplicaDisconnect ends it.
function zfsmReplicaTargetOpen($server) {
	if ($server === '') return array('server' => '');
	$remote = zfsmReplicaRemote($server);
	if (is_string($remote)) return array('error' => $remote);
	$down = zfsmReplicaDown($server);
	return $down !== null ? array('error' => $down) : zfsmReplicaConnect($remote) + array('server' => $server);
}

// The copy's dataset: $name itself here, inside a pool; on a trusted server one name inside its area, the only place the checker
// takes a copy. null when $name is neither.
function zfsmReplicaTargetDest($target, $name) {
	if ($target['server'] === '') return zfsmValidDatasetName($name) && strpos($name, '/') !== false ? $name : null;
	return is_string($name) && $name !== '' && strpos($name, '/') === false && zfsmValidDatasetName($target['area'].'/'.$name) ? $target['area'].'/'.$name : null;
}

// The copy as messages name it. "on server <name>" is how diagnostics learn a server's name (zfsmDiagLearnPools).
function zfsmReplicaTargetWhere($target, $zdest) {
	return $target['server'] === '' ? $zdest : $zdest.' on server '.$target['server'];
}

// What the copy's bookmarks are named for (zfsmReplicaBookmarkPrefix): its dataset, or "<server>@<dataset>", as '@' is in neither name.
function zfsmReplicaTargetMark($target, $zdest) {
	return $target['server'] === '' ? $zdest : $target['server'].'@'.$zdest;
}

// Whether the copy is missing: array(true or false, ''), or array(null, why it could not be told).
function zfsmReplicaTargetMissing($target, $zdest) {
	if ($target['server'] === '') return array(zfsmDatasetMissing($zdest), '');
	$answer = zfsmReplicaAsk($target, zfsmReplicaRequest('exists', $zdest));
	if ($answer['code'] === 0) return array(false, '');
	return zfsmDatasetMissingSaid($answer['output']) ? array(true, '') : array(null, $answer['error']);
}

// The token a stopped run left on the copy: array('' for none or no copy, or the token, ''), or array(null, why it could not be read).
function zfsmReplicaTargetToken($target, $zdest) {
	$here = $target['server'] === '';
	$answer = $here ? runProcess('zfs', array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', $zdest)) : zfsmReplicaAsk($target, zfsmReplicaRequest('token', $zdest));
	if (($answer['code'] ?? 1) !== 0) {
		$missing = $here ? zfsmDatasetMissing($zdest) === true : zfsmDatasetMissingSaid($answer['output']);
		return $missing ? array('', '') : array(null, $answer['error'] ?? '');
	}
	$token = trim((string)($answer['output'] ?? ''));
	return array($token === '-' ? '' : $token, '');
}

// The copy's snapshots, oldest first (zfsmReplicaMarks): array(the list, ''), or array(null, why they could not be read).
function zfsmReplicaTargetSnapshots($target, $zdest) {
	if ($target['server'] === '') return array(zfsmReplicaMarks($zdest, 'snapshot'), '');
	// A copy's list may be long; an answer past this much is no list.
	$answer = zfsmReplicaAsk($target, zfsmReplicaRequest('snapshots', $zdest), 16 << 20);
	return $answer['code'] === 0 ? array(zfsmReplicaMarksIn($zdest, $answer['output']), '') : array(null, $answer['error']);
}

// The receive a plan's stream goes into (zfsmReplicateStream): zfs here, or the checker's receive on the connection.
function zfsmReplicaTargetReceive($target, $plan) {
	if ($target['server'] === '') return array_merge(array(zfsmCommandPath('zfs')), zfsmReplicateReceiveArgs($plan));
	return array_merge(array(zfsmCommandPath('ssh')), zfsmReplicaSshArgs($target['remote'], $target['files'], zfsmReplicaRequest('receive', $plan['dest'])));
}

// Why ssh, not the checker, ended that receive (exit 255): what ssh said, or that the connection dropped, as ssh riding it says
// nothing then. null here, or when the checker answered.
function zfsmReplicaTargetLost($target, $code, $error) {
	if ($target['server'] === '' || $code !== 255) return null;
	return trim($error) !== '' ? $error : 'The connection to '.$target['server'].' dropped';
}

// That receive as the log shows it.
function zfsmReplicaTargetReceiveText($target, $plan) {
	if ($target['server'] === '') return 'zfs '.implode(' ', zfsmReplicateReceiveArgs($plan));
	return 'ssh '.$target['remote']['host'].' '.zfsmReplicaRequest('receive', $plan['dest']);
}
