<?php
// Where a copy lives: this server's zfs, or a trusted server's checker (include/ZFSMReplicaServe.php) on one ssh connection.
// A plan or run opens one target and asks it everything about the copy; zfs send and the source's own reads stay on this server.

// '' is this server; a trusted server's name gives its connection (zfsmReplicaConnect) and its name, or array('error' => why).
// zfsmReplicaDisconnect ends it.
function zfsmReplicaTargetOpen($server) {
	if ($server === '') return array('server' => '');
	$remote = zfsmReplicaRemote($server);
	return is_string($remote) ? array('error' => $remote) : zfsmReplicaConnect($remote) + array('server' => $server);
}

// $zdataset's copy named $name: array(its dataset, ''), or array(null, why not). Here a dataset in a pool, apart from $zdataset's own
// line, inside one that exists; on a trusted server one name inside its area, the only place the checker takes a copy.
function zfsmReplicaTargetDest($target, $zdataset, $name) {
	if ($target['server'] !== '') {
		$dest = $target['area'].'/'.$name;
		if ($name !== '' && strpos($name, '/') === false && zfsmValidDatasetName($dest)) return array($dest, '');
		return array(null, 'On '.$target['server'].' the copy goes inside '.$target['area'].', so give it one name with no slash, such as '.basename($zdataset));
	}
	if (!zfsmValidDatasetName($name) || strpos($name, '/') === false) return array(null, 'The copy needs a dataset name inside a pool, such as backup/'.basename($zdataset));
	if ($name === $zdataset || strncmp($name, $zdataset.'/', strlen($zdataset) + 1) === 0 || strncmp($zdataset, $name.'/', strlen($name) + 1) === 0) {
		return array(null, 'The copy can\'t be the dataset itself, go inside it, or replace one of its parents');
	}
	$parent = substr($name, 0, strrpos($name, '/'));
	return zfsmDatasetExists($parent, 'filesystem') ? array($name, '') : array(null, 'The copy goes inside '.$parent.', which doesn\'t exist');
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

// A request to the checker: its verb, and the dataset as hex.
function zfsmReplicaRequest($verb, $zdataset = '') {
	return 'zfsm-replica 1 '.$verb.($zdataset !== '' ? ' '.bin2hex($zdataset) : '');
}

// After one of ssh's own failures (exit 255) a server is left alone this long: Unraid's sshd shuts out a sender after three quick failed logins.
define('ZFSM_REPLICA_RETRY', 30);

function zfsmReplicaDownFile($name) {
	return zfsmJobDir('replica-send').'/down-'.bin2hex($name);
}

// Why ssh to $name failed less than ZFSM_REPLICA_RETRY seconds ago, and when it is tried again; null when it may be tried now.
function zfsmReplicaDown($name) {
	$file = zfsmReplicaDownFile($name);
	$left = (int)@filemtime($file) + ZFSM_REPLICA_RETRY - time();
	$why = $left > 0 ? @file_get_contents($file) : false;
	return is_string($why) && $why !== '' ? $why.'. It is tried again in '.$left.' seconds' : null;
}

// One request on a connection, keeping no more than $keep_bytes of the answer, which the other server decides the size of.
// A failure's 'error' says why: what to do about ssh's own failures, which zfsmReplicaDown then holds, or the checker's or zfs's line.
function zfsmReplicaAsk($link, $request, $keep_bytes = ZFSM_KEEP_OUTPUT) {
	$answer = runProcess('ssh', zfsmReplicaSshArgs($link['remote'], $link['files'], $request), null, 0, $keep_bytes);
	$name = $link['remote']['name'];
	// runProcess trims the answer: a whole one has as many lines as were read.
	if (($answer['code'] ?? 1) === 0 && ($answer['lines'] ?? 0) > substr_count((string)$answer['output'], "\n") + 1) return array('code' => 1, 'output' => '', 'error' => 'The answer from '.$name.' is longer than any it gives');
	if (($answer['code'] ?? 1) === 0) return $answer;
	$answer['error'] = zfsmCommandError($answer, 'No answer from '.$name);
	if ($answer['code'] === 255) {
		$answer['error'] = zfsmReplicaSshAdvice($link['remote'], $answer) ?? $answer['error'];
		@file_put_contents(zfsmReplicaDownFile($name), $answer['error']);
	}
	return $answer;
}

// hello on the other server, with the connection kept for more requests: array('remote', 'files', 'area' its copies go into),
// or array('error' => why: the wait after a failure, this server's name, ssh's own failure or the checker's refusal). zfsmReplicaDisconnect ends it.
function zfsmReplicaConnect($remote) {
	$down = zfsmReplicaDown($remote['name']);
	if ($down !== null) return array('error' => $down);
	// With no valid name this server has no line to give, so nothing on the other server is asked.
	$this_name = zfsmReplicaThisName();
	if ($this_name === null) return array('error' => 'This server\'s name must be '.ZFSM_REPLICA_NAME_RULE.'; rename it under Settings, Identification, then give '.$remote['name'].' its new line');
	$files = zfsmReplicaSshFiles($remote);
	if ($files === null) return array('error' => 'This server has no key yet, or its RAM copy could not be made');
	$link = array('remote' => $remote, 'files' => $files + array('control' => $files['dir'].'/cm'));
	try {
		$hello = zfsmReplicaAsk($link, zfsmReplicaRequest('hello'));
	} catch (ZFSMTimeout $timeout) {
		// Past the dialog's read limit: the key's RAM copy goes before the limit is reported.
		zfsmReplicaDisconnect($link);
		throw $timeout;
	}
	// The answer ends at the area's name, which may hold spaces.
	$area = preg_match('/\Azfsm-replica 1\narea (.+)\z/', (string)$hello['output'], $match) === 1 && zfsmValidDatasetName($match[1]) ? $match[1] : null;
	// The area is named for the sender the key line there names: another name is a line made for another name, as before a rename.
	$named = $area === null ? null : zfsmReplicaAreaSender($area);
	if ($named === $this_name) {
		@unlink(zfsmReplicaDownFile($remote['name']));
		return $link + array('area' => $area);
	}
	zfsmReplicaDisconnect($link);
	if ($named === null) return array('error' => $hello['error'] ?? zfsmCommandError($hello, 'No answer from '.$remote['name']));
	return array('error' => 'The key line on '.$remote['name'].' is for '.$named.', but this server is '.$this_name.': '.$remote['name'].' needs this server\'s own line in its place. '
		.'Copies already in '.$named.'\'s area stay there, and this server\'s area starts with full copies');
}

// Ends a connection and removes its key files; a target of this server, or one that failed, has none.
function zfsmReplicaDisconnect($link) {
	if (!isset($link['files'])) return;
	try {
		if (file_exists($link['files']['control'])) runProcess('ssh', array('-F', 'none', '-o', 'ControlPath='.$link['files']['control'], '-O', 'exit', '--', $link['remote']['host']));
	} catch (ZFSMTimeout $timeout) {
		// Past the dialog's read limit ssh can't start: the connection ends itself (ControlPersist), and what was read still holds.
	} finally {
		zfsmReplicaSshCleanup($link['files']);
	}
}
