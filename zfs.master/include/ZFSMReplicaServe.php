<?php
// Receiving copies from another server: its key runs only scripts/replica-serve.php <sender> (authorized_keys command=), one fixed
// zfs command per request, inside that sender's own area, <accepting dataset>/<sender>, which stays unmounted (mountpoint=none).

// A request is "zfsm-replica 1 <verb> [<dataset as lowercase hex>]": hex, so a name never meets a shell or a split.
// receive: -x mountpoint makes every dataset in the stream inherit the area's none, so nothing it holds can mount or be shared.
function zfsmReplicaVerbArgs($verb, $name) {
	switch ($verb) {
		case 'exists': return array('list', '-H', '-o', 'name,type', $name);
		case 'snapshots': return array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', $name);
		case 'token': return array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', $name);
		case 'receive': return array('receive', '-s', '-u', '-o', 'readonly=on', '-x', 'mountpoint', '-x', 'sharenfs', '-x', 'sharesmb', $name);
	}
	return null;
}

// A sender's area, <accept>/<sender>; null when either name isn't allowed, and a pool's top dataset is never accepted.
function zfsmReplicaArea($accept, $sender) {
	if (!zfsmValidDatasetName($accept) || strpos($accept, '/') === false) return null;
	return is_string($sender) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $sender) ? $accept.'/'.$sender : null;
}

// What a request may run: array('verb', 'name', 'args'), or array('error' => why it is refused).
function zfsmReplicaServeCheck($request, $area) {
	if ($area === null) return array('error' => 'This server does not accept replicas from this key');
	if (!is_string($request) || !preg_match('/^zfsm-replica 1 (hello|exists|snapshots|token|receive)(?: ([0-9a-f]{2,510}))?$/D', $request, $match)) {
		return array('error' => 'Not a replication request');
	}
	$verb = $match[1];
	$hex = $match[2] ?? '';
	if ($verb === 'hello') return $hex === '' ? array('verb' => 'hello', 'name' => $area, 'args' => null) : array('error' => 'Not a replication request');
	$name = strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
	if (!is_string($name) || !zfsmValidDatasetName($name)) return array('error' => 'Not a dataset name');
	// Only exists may ask about the area itself, as the parent of a first copy.
	if (!zfsmStartsWith($name, $area.'/') && !($verb === 'exists' && $name === $area)) return array('error' => $name.' is not inside '.$area);
	return array('verb' => $verb, 'name' => $name, 'args' => zfsmReplicaVerbArgs($verb, $name));
}

// '' once the area is ready for copies: hello makes it, unmounted, when missing; any request is refused once its mountpoint isn't none.
function zfsmReplicaAreaReady($area, $create) {
	$read = runProcess('zfs', array('get', '-H', '-p', '-o', 'value', 'mountpoint', $area));
	if (($read['code'] ?? 1) === 0) return trim((string)$read['output']) === 'none' ? '' : $area.' must keep mountpoint=none, so nothing replicated into it mounts';
	$missing = zfsmDatasetMissing($area);
	if ($missing !== true) return 'The copies\' dataset '.$area.' could not be read';
	if (!$create) return $area.' doesn\'t exist yet';
	$made = runProcess('zfs', array('create', '-o', 'mountpoint=none', '-o', 'canmount=off', $area));
	return ($made['code'] ?? 1) === 0 ? '' : zfsmCommandError($made, 'The copies\' dataset '.$area.' could not be made');
}

// Runs one request on this process's input and output, so a stream goes straight into zfs receive; refusals and receives are logged.
function zfsmServeReplica($request, $area, $io = null) {
	$io = $io ?? array(STDIN, STDOUT, STDERR);
	$from = explode(' ', (string)getenv('SSH_CLIENT'))[0] ?: 'an unknown address';
	$checked = zfsmReplicaServeCheck($request, $area);
	if (!isset($checked['error'])) {
		$why = zfsmReplicaAreaReady($area, $checked['verb'] === 'hello');
		if ($why !== '') $checked = array('error' => $why);
	}
	if (isset($checked['error'])) {
		zfsmLog('Replication request from '.$from.' refused: '.$checked['error'].' ('.substr((string)$request, 0, 120).')');
		fwrite($io[2], $checked['error']."\n");
		return 1;
	}
	if ($checked['verb'] === 'hello') {
		fwrite($io[1], "zfsm-replica 1\narea ".$area."\n");
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
	if ($checked['verb'] === 'receive') zfsmLog('Replication from '.$from.' into '.$checked['name'].($code === 0 ? ' received' : ' failed with exit code '.$code), $code === 0 ? LOG_NOTICE : LOG_ERR);
	return $code;
}

// The keys in root's authorized_keys that run the checker: each one's sender, key comment, and whether restrict and from= hold it.
function zfsmReplicaKeys($file = '/boot/config/ssh/root/authorized_keys') {
	$keys = array();
	foreach (preg_split('/\R/', (string)@file_get_contents($file)) as $line) {
		if (!preg_match('/^((?:[^\s"]|"[^"]*")+)\s+(?:ssh-|ecdsa-|sk-)\S*\s+\S+\s*(.*)$/', trim($line), $match)) continue;
		preg_match_all('/(?:^|,)([A-Za-z0-9-]+)(?:="([^"]*)")?/', $match[1], $options, PREG_SET_ORDER);
		$named = array();
		foreach ($options as $option) $named[$option[1]] = $option[2] ?? '';
		if (!preg_match('#/plugins/zfs\.master/scripts/replica-serve\.php(?:\s+(\S+))?\s*$#', $named['command'] ?? '', $command)) continue;
		$keys[] = array('sender' => $command[1] ?? '', 'comment' => $match[2], 'restrict' => array_key_exists('restrict', $named), 'from' => ($named['from'] ?? '') !== '');
	}
	return $keys;
}

// This server's ed25519 SSH host key as ssh-keygen -l prints it, for a sender to compare before it trusts the key; null when unread.
function zfsmHostKeyFingerprint($file = '/etc/ssh/ssh_host_ed25519_key.pub') {
	$fields = preg_split('/\s+/', trim((string)@file_get_contents($file)));
	$blob = count($fields) >= 2 && $fields[0] === 'ssh-ed25519' ? base64_decode($fields[1], true) : false;
	return !is_string($blob) || $blob === '' ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}
