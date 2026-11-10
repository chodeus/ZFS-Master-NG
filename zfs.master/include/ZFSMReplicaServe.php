<?php
// The receiving side of a replication from another server. The sender's key runs only scripts/replica-serve.php (authorized_keys
// command=), which runs one fixed zfs command per request, for datasets inside the one this server accepts copies into.
// A request is "zfsm-replica 1 <verb> [<dataset as lowercase hex> [filesystem|volume]]": hex, so a name never meets a shell or a split.

// The one zfs command each request runs.
function zfsmReplicaVerbArgs($verb, $name, $type = null) {
	switch ($verb) {
		case 'exists': return array('list', '-H', '-o', 'name,type', $name);
		case 'snapshots': return array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', $name);
		case 'token': return array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', $name);
		case 'receive': return zfsmReplicateReceiveArgs(array('type' => $type, 'dest' => $name));
	}
	return null;
}

// What a request may run: array('args' => zfs arguments), array('reply' => text) for hello, or array('error' => why it is refused).
// $accept is the dataset copies go inside; '' or a name that isn't valid refuses everything.
function zfsmReplicaServeCheck($request, $accept) {
	if (!zfsmValidDatasetName($accept)) return array('error' => 'This server does not accept replicas');
	if (!is_string($request) || !preg_match('/^zfsm-replica 1 (hello|exists|snapshots|token|receive)(?: ([0-9a-f]{2,510}))?(?: (filesystem|volume))?$/D', $request, $match)) {
		return array('error' => 'Not a replication request');
	}
	$verb = $match[1];
	$hex = $match[2] ?? '';
	$type = $match[3] ?? '';
	if ($verb === 'hello') return $hex === '' && $type === '' ? array('reply' => "zfsm-replica 1\naccept ".$accept."\n") : array('error' => 'Not a replication request');
	$name = strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
	if (!is_string($name) || !zfsmValidDatasetName($name)) return array('error' => 'Not a dataset name');
	// Only exists may ask about the accepting dataset itself, as the parent of a first copy.
	$inside = strncmp($name, $accept.'/', strlen($accept) + 1) === 0;
	if (!$inside && !($verb === 'exists' && $name === $accept)) return array('error' => $name.' is not inside '.$accept);
	if (($verb === 'receive') !== ($type !== '')) return array('error' => 'Not a replication request');
	return array('args' => zfsmReplicaVerbArgs($verb, $name, $type === '' ? null : $type));
}

// Runs one request on this process's input and output, so a stream goes straight into zfs receive; refusals and receives are logged.
function zfsmServeReplica($request, $accept, $io = null) {
	$io = $io ?? array(STDIN, STDOUT, STDERR);
	$from = explode(' ', (string)getenv('SSH_CLIENT'))[0] ?: 'an unknown address';
	$checked = zfsmReplicaServeCheck($request, $accept);
	if (isset($checked['error'])) {
		zfsmLog('Replication request from '.$from.' refused: '.$checked['error'].' ('.substr((string)$request, 0, 120).')');
		fwrite($io[2], $checked['error']."\n");
		return 1;
	}
	if (isset($checked['reply'])) {
		fwrite($io[1], $checked['reply']);
		return 0;
	}
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) {
		fwrite($io[2], "zfs must come from ZFSM_TEST_BIN_DIR in a test\n");
		return 127;
	}
	$process = @proc_open(array_merge(array($zfs), $checked['args']), array(0 => $io[0], 1 => $io[1], 2 => $io[2]), $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) {
		fwrite($io[2], "Unable to start zfs\n");
		return 1;
	}
	$code = proc_close($process);
	if ($checked['args'][0] === 'receive') zfsmLog('Replication from '.$from.' into '.end($checked['args']).($code === 0 ? ' received' : ' failed with exit code '.$code), $code === 0 ? LOG_NOTICE : LOG_ERR);
	return $code;
}

// This server's ed25519 SSH host key as ssh-keygen -l prints it, for a sender to compare before it trusts the key; null when unread.
function zfsmHostKeyFingerprint($file = '/etc/ssh/ssh_host_ed25519_key.pub') {
	$fields = preg_split('/\s+/', trim((string)@file_get_contents($file)));
	$blob = count($fields) >= 2 && $fields[0] === 'ssh-ed25519' ? base64_decode($fields[1], true) : false;
	return !is_string($blob) || $blob === '' ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}
