<?php
// Receiving copies from another server: its key runs only scripts/replica-serve.php <sender> (authorized_keys command=), one fixed
// zfs command per request, inside that sender's own area, <accepting dataset>/<sender>, which stays unmounted (mountpoint=none).

// The area's own properties, which every copy in it inherits: nothing mounts, appears as a block device, or runs from it.
function zfsmReplicaAreaProps() {
	return array('mountpoint' => 'none', 'volmode' => 'none', 'setuid' => 'off', 'devices' => 'off', 'exec' => 'off');
}

// A request is "zfsm-replica 1 <verb> [<dataset as lowercase hex>]": hex, so a name never meets a shell or a split.
// receive: -x keeps the stream from setting the area's properties or shares, whatever it holds.
function zfsmReplicaVerbArgs($verb, $name) {
	switch ($verb) {
		case 'exists': return array('list', '-H', '-o', 'name,type', $name);
		case 'snapshots': return array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', $name);
		case 'token': return array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', $name);
		case 'receive':
			$args = array('receive', '-s', '-u', '-o', 'readonly=on');
			foreach (array_merge(array_keys(zfsmReplicaAreaProps()), array('sharenfs', 'sharesmb', 'snapdev')) as $property) array_push($args, '-x', $property);
			$args[] = $name;
			return $args;
	}
	return null;
}

// '' when a stream's first record (OpenZFS include/sys/zfs_ioctl.h, dmu_replay_record) may go to zfs receive, else why not.
// One dataset's stream only: never a clone of another's snapshot, nor a -R or -p package, which this plugin's sender never makes.
function zfsmReplicaStreamCheck($record) {
	if (strlen($record) !== 312) return 'Not a send stream';
	$begin = unpack('Vrecord/Vpayload/Pmagic/Pversion/Pcreated/Vobjset/Vflags', $record);
	if ($begin['record'] !== 0 || $begin['magic'] !== 0x2F5BACBAC) return 'Not a send stream';
	if (($begin['version'] & 3) !== 1) return 'A stream of several datasets (zfs send -R or -p) is not accepted';
	if (($begin['flags'] & 1) !== 0) return 'A clone\'s stream is not accepted';
	return in_array($begin['objset'], array(2, 3), true) ? '' : 'Only a filesystem or volume stream is accepted';
}

// The Accept Replicas Into setting: a dataset inside a pool, never a pool's top dataset (the Settings page asks validateaccept).
function zfsmReplicaAcceptValid($accept) {
	return zfsmValidDatasetName($accept) && strpos($accept, '/') !== false;
}

// A sender's area, <accept>/<sender>; null when either name isn't allowed.
function zfsmReplicaArea($accept, $sender) {
	return zfsmReplicaAcceptValid($accept) && zfsmReplicaSenderName($sender) ? $accept.'/'.$sender : null;
}

function zfsmReplicaSenderName($sender) {
	return is_string($sender) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $sender) === 1;
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

// '' once the area is ready for copies: hello makes it when missing; any request is refused once one of its properties changed.
function zfsmReplicaAreaReady($area, $create) {
	$why = zfsmReplicaAreaHolds($area);
	if ($why !== null) return $why;
	if (!$create) return $area.' doesn\'t exist yet';
	$args = array('create', '-o', 'canmount=off');
	foreach (zfsmReplicaAreaProps() as $property => $value) array_push($args, '-o', $property.'='.$value);
	$made = runProcess('zfs', array_merge($args, array($area)));
	if (($made['code'] ?? 1) === 0) return '';
	// Another hello may have made it in between.
	return zfsmReplicaAreaHolds($area) ?? zfsmCommandError($made, 'The copies\' dataset '.$area.' could not be made');
}

// '' when the area holds every property it must, why not when it doesn't or can't be read, null when it doesn't exist.
function zfsmReplicaAreaHolds($area, $again = true) {
	$props = zfsmReplicaAreaProps();
	$read = runProcess('zfs', array('get', '-H', '-p', '-o', 'property,value', implode(',', array_keys($props)), $area));
	if (($read['code'] ?? 1) !== 0) {
		$missing = zfsmDatasetMissing($area);
		if ($missing === true) return null;
		// Made by another hello after this read failed: read it once more.
		return $missing === false && $again ? zfsmReplicaAreaHolds($area, false) : 'The copies\' dataset '.$area.' could not be read';
	}
	$found = array();
	foreach (preg_split('/\R/', trim((string)$read['output'])) as $line) {
		$field = explode("\t", $line);
		if (count($field) === 2) $found[$field[0]] = $field[1];
	}
	$wanted = implode(', ', array_map(function($property) use ($props) { return $property.'='.$props[$property]; }, array_keys($props)));
	return array_diff_assoc($props, $found) === array() ? '' : $area.' must keep '.$wanted.', so nothing replicated into it mounts or runs';
}

// One of four receive slots per area, held until the receive ends; null when all are taken or none can be opened.
function zfsmReplicaReceiveSlot($area) {
	for ($slot = 0; $slot < 4; $slot++) {
		$lock = zfsmJobLock(zfsmJobDir('replica-serve').'/'.hash('sha256', $area).'.'.$slot.'.lock');
		if ($lock) return $lock;
	}
	return null;
}

function zfsmReplicaRefuse($why, $request, $from, $io) {
	zfsmLog('Replication request from '.$from.' refused: '.$why.' ('.substr((string)$request, 0, 120).')');
	fwrite($io[2], $why."\n");
	return 1;
}

// Runs zfs with the sender's output and errors; $first, when given, goes to its input ahead of the rest of the sender's.
function zfsmReplicaRun($args, $io, $first = null) {
	$zfs = zfsmCommandPath('zfs');
	if ($zfs === null) {
		fwrite($io[2], "zfs must come from ZFSM_TEST_BIN_DIR in a test\n");
		return 127;
	}
	$input = $first === null ? $io[0] : array('pipe', 'r');
	$process = @proc_open(array_merge(array($zfs), $args), array(0 => $input, 1 => $io[1], 2 => $io[2]), $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) {
		fwrite($io[2], "Unable to start zfs\n");
		return 1;
	}
	if ($first !== null) {
		// A receive that stops early closes its end: the writes fail (PHP's CLI ignores SIGPIPE) and its exit code says why.
		if (@fwrite($pipes[0], $first) === strlen($first)) @stream_copy_to_stream($io[0], $pipes[0]);
		fclose($pipes[0]);
	}
	return proc_close($process);
}

// Runs one request on this process's input and output; a stream's first record is checked before zfs receive sees any of it.
function zfsmServeReplica($request, $area, $io = null) {
	$io = $io ?? array(STDIN, STDOUT, STDERR);
	$from = explode(' ', (string)getenv('SSH_CLIENT'))[0] ?: 'an unknown address';
	$checked = zfsmReplicaServeCheck($request, $area);
	$why = $checked['error'] ?? zfsmReplicaAreaReady($area, $checked['verb'] === 'hello');
	if ($why !== '') return zfsmReplicaRefuse($why, $request, $from, $io);
	if ($checked['verb'] === 'hello') {
		fwrite($io[1], "zfsm-replica 1\narea ".$area."\n");
		return 0;
	}
	if ($checked['verb'] !== 'receive') return zfsmReplicaRun($checked['args'], $io);
	$slot = zfsmReplicaReceiveSlot($area);
	if ($slot === null) return zfsmReplicaRefuse('No receive into '.$area.' can start while four are running', $request, $from, $io);
	try {
		$record = '';
		while (strlen($record) < 312) {
			$chunk = fread($io[0], 312 - strlen($record));
			if ($chunk === false || $chunk === '') break;
			$record .= $chunk;
		}
		$why = zfsmReplicaStreamCheck($record);
		if ($why !== '') return zfsmReplicaRefuse($why, $request, $from, $io);
		$code = zfsmReplicaRun($checked['args'], $io, $record);
	} finally {
		zfsmJobUnlock($slot);
	}
	zfsmLog('Replication from '.$from.' into '.$checked['name'].($code === 0 ? ' received' : ' failed with exit code '.$code), $code === 0 ? LOG_NOTICE : LOG_ERR);
	return $code;
}

// The keys in root's authorized_keys whose command names the checker: each one's sender (when the command is exactly the installed
// checker and a name), key comment, and whether restrict and from= hold it. sshd matches option names whatever their case.
function zfsmReplicaKeys($file = '/boot/config/ssh/root/authorized_keys') {
	$keys = array();
	foreach (preg_split('/\R/', (string)@file_get_contents($file)) as $line) {
		if (!preg_match('/^((?:[^\s"]|"[^"]*")+)\s+(?:ssh-|ecdsa-|sk-)\S*\s+\S+\s*(.*)$/', trim($line), $match)) continue;
		preg_match_all('/(?:^|,)([A-Za-z0-9-]+)(?:="([^"]*)")?/', $match[1], $options, PREG_SET_ORDER);
		$named = array();
		foreach ($options as $option) $named[strtolower($option[1])] = $option[2] ?? '';
		$command = $named['command'] ?? '';
		if (strpos($command, 'replica-serve.php') === false) continue;
		$exact = preg_match('#^/usr/local/emhttp/plugins/zfs\.master/scripts/replica-serve\.php (\S+)$#D', $command, $sender) === 1;
		$keys[] = array('sender' => $exact ? $sender[1] : '', 'comment' => $match[2], 'restrict' => zfsmReplicaKeyRestricted($named), 'from' => zfsmReplicaKeyFrom($named['from'] ?? ''));
	}
	return $keys;
}

// sshd reads options left to right, so a pty or forwarding option anywhere after restrict turns that limit back on.
function zfsmReplicaKeyRestricted($named) {
	return array_key_exists('restrict', $named) && !array_intersect(array_keys($named), array('pty', 'port-forwarding', 'agent-forwarding', 'x11-forwarding', 'user-rc'));
}

// This plugin's policy: a replication key's from= lists exact IP addresses, never a range, wildcard, ! or host name.
function zfsmReplicaKeyFrom($from) {
	foreach (explode(',', $from) as $address) if (filter_var($address, FILTER_VALIDATE_IP) === false) return false;
	return true;
}

// Whether a key's line holds it to replication: restrict, a from= of exact addresses, and command= the checker with a sender's name.
function zfsmReplicaKeyHeld($key) {
	return $key['restrict'] && $key['from'] && zfsmReplicaSenderName($key['sender']);
}

// The settings' list of those keys, a key its line doesn't hold in red. The sender wrote the comment, valid UTF-8 or not.
function zfsmReplicaKeysHtml($keys) {
	if (!$keys) return 'None yet';
	$html = '';
	foreach ($keys as $key) {
		$held = zfsmReplicaKeyHeld($key);
		$text = ($key['sender'] !== '' ? $key['sender'] : '?').' ('.$key['comment'].'): '.($held ? 'limited to replication' : 'its line needs restrict with no limit turned back on after it, a from= of exact IP addresses, and command= exactly replica-serve.php and a name');
		$html .= '<span style="display:block'.($held ? '' : ';color:#e22828').'">'.zfsmHtml($text).'</span>';
	}
	return $html;
}

// This server's ed25519 SSH host key as ssh-keygen -l prints it, for a sender to compare before it trusts the key; null when unread.
function zfsmHostKeyFingerprint($file = '/etc/ssh/ssh_host_ed25519_key.pub') {
	$fields = preg_split('/\s+/', trim((string)@file_get_contents($file)));
	$blob = count($fields) >= 2 && $fields[0] === 'ssh-ed25519' ? base64_decode($fields[1], true) : false;
	return !is_string($blob) || $blob === '' ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}
