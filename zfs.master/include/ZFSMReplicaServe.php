<?php
// Receiving copies from another server: its key runs only scripts/replica-serve.php <sender> (authorized_keys command=), one fixed
// zfs command per request, inside that sender's own area, <accepting dataset>/<sender>, which stays unmounted (mountpoint=none).

// The area's own properties, which every copy in it inherits: nothing mounts, appears as a block device, or runs from it.
function zfsmReplicaAreaProps() {
	return array('mountpoint' => 'none', 'volmode' => 'none', 'setuid' => 'off', 'devices' => 'off', 'exec' => 'off');
}

// A request is "zfsm-replica 1 <verb> [<dataset as lowercase hex>]": hex, so a name never meets a shell or a split.
// receive: -x keeps the stream from setting the area's properties or shares; holder: no -p, so only where its parent exists.
function zfsmReplicaVerbArgs($verb, $name) {
	switch ($verb) {
		case 'exists': return array('list', '-H', '-o', 'name,type', $name);
		case 'snapshots': return array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', $name);
		case 'token': return array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', $name);
		case 'holder': return zfsmReplicaHolderArgs($name);
		case 'crypt': return zfsmReplicaCryptArgs($name);
		case 'receive':
		case 'receive-zstd':
			$args = array('receive', '-s', '-u', '-o', 'readonly=on');
			foreach (array_merge(array_keys(zfsmReplicaAreaProps()), array('sharenfs', 'sharesmb', 'snapdev')) as $property) array_push($args, '-x', $property);
			$args[] = $name;
			return $args;
	}
	return null;
}

// A stream's first record (OpenZFS include/sys/zfs_ioctl.h, dmu_replay_record) as DRR_BEGIN's fields; null when it is no record.
function zfsmReplicaStreamBegin($record) {
	return strlen($record) === 312 ? unpack('Vrecord/Vpayload/Pmagic/Pversion/Pcreated/Vobjset/Vflags', $record) : null;
}

// '' when a stream's first record may go to zfs receive, else why not.
// One dataset's stream only: never a clone of another's snapshot, nor a -R or -p package, which this plugin's sender never makes.
function zfsmReplicaStreamCheck($record) {
	$begin = zfsmReplicaStreamBegin($record);
	if ($begin === null || $begin['record'] !== 0 || $begin['magic'] !== 0x2F5BACBAC) return 'Not a send stream';
	if (($begin['version'] & 3) !== 1) return 'A stream of several datasets (zfs send -R or -p) is not accepted';
	if (($begin['flags'] & 1) !== 0) return 'A clone\'s stream is not accepted';
	return in_array($begin['objset'], array(2, 3), true) ? '' : 'Only a filesystem or volume stream is accepted';
}

// $args for the receive of a checked stream: one without the raw feature (featureflags bit 24, above the 2 header bits) marks the copy
// ZFSM_REPLICA_RAW=off. The stream decides, never the request; an unencrypted dataset's copy is marked too.
function zfsmReplicaReceiveMarked($args, $record) {
	if ((zfsmReplicaStreamBegin($record)['version'] & (1 << 26)) !== 0) return $args;
	array_splice($args, -1, 0, array('-o', ZFSM_REPLICA_RAW.'=off'));
	return $args;
}

// The Accept Replicas Into setting: a dataset inside a pool, never a pool's top dataset (the Settings page asks validateaccept).
function zfsmReplicaAcceptValid($accept) {
	return zfsmValidDatasetName($accept) && strpos($accept, '/') !== false;
}

// A sender's area, <accept>/<sender>; null when either name isn't allowed.
function zfsmReplicaArea($accept, $sender) {
	return zfsmReplicaAcceptValid($accept) && zfsmReplicaSenderName($sender) ? $accept.'/'.$sender : null;
}

// The sender an area is for (zfsmReplicaArea), as the sending side reads it back from a hello.
function zfsmReplicaAreaSender($area) {
	$at = strrpos((string)$area, '/');
	return $at === false ? (string)$area : substr($area, $at + 1);
}

// The command= a sender's key runs: zfsmReplicaKeyLine writes it, and zfsmReplicaKeys reads a sender's name only after it.
define('ZFSM_REPLICA_SERVE', '/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php');

// zfsmReplicaSenderName's rule in words, for each message that refuses a name.
define('ZFSM_REPLICA_NAME_RULE', '1 to 64 letters, digits, _ . : or -, starting with a letter or digit');
function zfsmReplicaSenderName($sender) {
	return is_string($sender) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $sender) === 1;
}

// What a request may run: array('verb', 'name', 'args'), or array('error' => why it is refused).
function zfsmReplicaServeCheck($request, $area) {
	if ($area === null) return array('error' => 'This server does not accept replicas from this key');
	if (!is_string($request) || !preg_match('/^zfsm-replica 1 (hello|exists|snapshots|token|holder|crypt|receive|receive-zstd)(?: ([0-9a-f]{2,510}))?$/D', $request, $match)) {
		return array('error' => 'Not a replication request');
	}
	$verb = $match[1];
	$hex = $match[2] ?? '';
	if ($verb === 'hello') return $hex === '' ? array('verb' => 'hello', 'name' => $area, 'args' => null) : array('error' => 'Not a replication request');
	$name = strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
	if (!is_string($name) || !zfsmValidDatasetName($name)) return array('error' => 'Not a dataset name');
	// Only exists and crypt may ask about the area itself, as the parent of a first copy.
	if (!zfsmStartsWith($name, $area.'/') && !(in_array($verb, array('exists', 'crypt'), true) && $name === $area)) return array('error' => $name.' is not inside '.$area);
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
	foreach (zfsmLines($read['output'] ?? '') as $line) {
		$field = explode("\t", $line);
		if (count($field) === 2) $found[$field[0]] = $field[1];
	}
	$wanted = implode(', ', array_map(function($property) use ($props) { return $property.'='.$props[$property]; }, array_keys($props)));
	return array_diff_assoc($props, $found) === array() ? '' : $area.' must keep '.$wanted.', so nothing replicated into it mounts or runs';
}

// One of four receive slots per area, held until the receive ends, or why none is: named by the area's guid, as no name may be
// read back from a lock (zfsmDiagOwnName).
function zfsmReplicaReceiveSlot($area) {
	$file = zfsmJobLockFile($area, 'replica-serve');
	if ($file === null) return 'No receive into '.$area.' can start: its guid could not be read';
	for ($slot = 0; $slot < 4; $slot++) {
		$lock = zfsmJobLock(substr($file, 0, -strlen('.lock')).'.'.$slot.'.lock');
		if ($lock === null) return 'No receive into '.$area.' can start: its lock could not be opened in '.dirname($file);
		if ($lock) return $lock;
	}
	return 'No receive into '.$area.' can start while four are running';
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

// receive-zstd's fixed zstd -d, or null without zstd or prlimit. Capped at 256 MiB: it reads xz and lzma too, whose headers may claim gigabytes.
function zfsmReplicaUnzipCommand() {
	$prlimit = zfsmToolPath('prlimit');
	$zstd = zfsmToolPath('zstd');
	return $prlimit === null || $zstd === null ? null : array($prlimit, '--as='.(256 << 20), '--', $zstd, '-d', '-q', '-c');
}

// That zstd -d, reading the sender's stream from $io: array(it, its output), or why it can't run.
function zfsmReplicaUnzip($io) {
	$command = zfsmReplicaUnzipCommand();
	if ($command === null) return 'This server needs zstd and prlimit to read a compressed stream';
	$process = @proc_open($command, array(0 => $io[0], 1 => array('pipe', 'w'), 2 => $io[2]), $pipes, null, null, array('bypass_shell' => true));
	return is_resource($process) ? array($process, $pipes[1]) : 'Unable to start zstd';
}

// exists, snapshots, token, holder or crypt. A sender asks exists of the area itself, as no version before zstd does (zfsmReplicaTargetCan):
// the answer adds whether this one reads zstd, and that it makes holders and reads encryption.
function zfsmReplicaServeAsk($checked, $area, $io) {
	$code = zfsmReplicaRun($checked['args'], $io);
	if ($code !== 0 || $checked['verb'] !== 'exists' || $checked['name'] !== $area) return $code;
	fwrite($io[1], (zfsmReplicaUnzipCommand() !== null ? "zstd\n" : '')."holder\ncrypt\n");
	return $code;
}

// A stream's first record (zfsmReplicaStreamCheck), or what came before it ended.
function zfsmReplicaFirstRecord($input) {
	$record = '';
	while (strlen($record) < 312) {
		$chunk = fread($input, 312 - strlen($record));
		if ($chunk === false || $chunk === '') break;
		$record .= $chunk;
	}
	return $record;
}

// Stops zfsmReplicaUnzip's zstd before its output closes: it ignores SIGPIPE, as PHP does, so a write after that would print a broken
// pipe to the sender.
function zfsmReplicaUnzipStop($unzip) {
	if (!is_array($unzip)) return;
	proc_terminate($unzip[0]);
	fclose($unzip[1]);
	proc_close($unzip[0]);
}

// sshd's forced command (scripts/replica-serve.php): $sender's area and Keep Replicas, from $cfg (loadConfig's).
function zfsmServeReplicaSettings($request, $cfg, $sender) {
	return zfsmServeReplica($request, zfsmReplicaArea($cfg['replica_accept'], $sender), null, $cfg['replica_keep']);
}

// Runs one request on this process's input and output; a stream's first record is checked before zfs receive sees any of it, and
// $keep (Keep Replicas) applies once it lands.
function zfsmServeReplica($request, $area, $io = null, $keep = 0) {
	$io = $io ?? array(STDIN, STDOUT, STDERR);
	$from = explode(' ', (string)getenv('SSH_CLIENT'))[0] ?: 'an unknown address';
	$checked = zfsmReplicaServeCheck($request, $area);
	$why = $checked['error'] ?? zfsmReplicaAreaReady($area, $checked['verb'] === 'hello');
	if ($why !== '') return zfsmReplicaRefuse($why, $request, $from, $io);
	if ($checked['verb'] === 'hello') {
		fwrite($io[1], "zfsm-replica 1\narea ".$area."\n");
		return 0;
	}
	if ($checked['verb'] !== 'receive' && $checked['verb'] !== 'receive-zstd') return zfsmReplicaServeAsk($checked, $area, $io);
	$slot = zfsmReplicaReceiveSlot($area);
	if (is_string($slot)) return zfsmReplicaRefuse($slot, $request, $from, $io);
	$unzip = null;
	try {
		if ($checked['verb'] === 'receive-zstd') {
			$unzip = zfsmReplicaUnzip($io);
			if (is_string($unzip)) return zfsmReplicaRefuse($unzip, $request, $from, $io);
			$io[0] = $unzip[1];
		}
		$record = zfsmReplicaFirstRecord($io[0]);
		$why = zfsmReplicaStreamCheck($record);
		if ($why !== '') return zfsmReplicaRefuse($why, $request, $from, $io);
		$code = zfsmReplicaRun(zfsmReplicaReceiveMarked($checked['args'], $record), $io, $record);
	} finally {
		zfsmReplicaUnzipStop($unzip);
		zfsmJobUnlock($slot);
	}
	zfsmLog('Replication from '.$from.' into '.$checked['name'].($code === 0 ? ' received' : ' failed with exit code '.$code), $code === 0 ? LOG_NOTICE : LOG_ERR);
	if ($code === 0) zfsmKeepReplica($from, $checked['name'], $keep);
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
		$exact = preg_match('#^'.preg_quote(ZFSM_REPLICA_SERVE, '#').' (\S+)$#D', $command, $sender) === 1;
		$keys[] = array('sender' => $exact ? $sender[1] : '', 'comment' => $match[2], 'restrict' => zfsmReplicaKeyRestricted($named), 'from' => zfsmReplicaKeyFrom($named['from'] ?? ''));
	}
	return $keys;
}

// sshd reads options left to right, so a pty or forwarding option after restrict gives back what restrict took away.
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
		$text = ($key['sender'] !== '' ? $key['sender'] : '?').' ('.$key['comment'].'): '.($held ? 'limited to replication' : 'its line needs restrict and no pty, forwarding or user-rc option, a from= of exact IP addresses, and command= exactly '.ZFSM_REPLICA_SERVE.' and a name');
		$html .= '<span style="display:block'.($held ? '' : ';color:#e22828').'">'.zfsmHtml($text).'</span>';
	}
	return $html;
}

// This server's ed25519 SSH host key as ssh-keygen -l prints it, for a sender to compare before it trusts the key; null when unread.
function zfsmHostKeyFingerprint($file = '/etc/ssh/ssh_host_ed25519_key.pub') {
	return zfsmKeyFingerprint((string)@file_get_contents($file));
}

// The fingerprint of a public-key line, "ssh-ed25519 <base64> [comment]", as ssh-keygen -l prints one; null for any other type,
// or a blob that isn't an ed25519 public key.
function zfsmKeyFingerprint($key) {
	$fields = is_string($key) ? preg_split('/\s+/', trim($key)) : array();
	$blob = count($fields) >= 2 && $fields[0] === 'ssh-ed25519' ? base64_decode($fields[1], true) : false;
	if (!is_string($blob) || strlen($blob) !== 51 || strncmp($blob, "\0\0\0\x0bssh-ed25519\0\0\0\x20", 19) !== 0) return null;
	return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}
