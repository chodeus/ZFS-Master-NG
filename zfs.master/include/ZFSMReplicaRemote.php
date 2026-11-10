<?php
// Sending copies to other servers: this server's own key, the servers it trusts (each one's host key pinned when added), and ssh
// to the checker there (scripts/replica-serve.php, include/ZFSMReplicaServe.php), which runs one fixed zfs command per request.
require_once __DIR__.'/ZFSMJobs.php';

// On the flash drive, so the key and the pinned host keys survive a reboot; ssh only ever reads RAM copies (zfsmReplicaSshFiles).
function zfsmReplicaSshDir() {
	return $GLOBALS['zfsm_ssh_dir'] ?? '/boot/config/plugins/zfs.master/ssh';
}

// This server's name in the key line on the servers it sends to; null when Unraid's server name breaks the sender-name rules there.
function zfsmReplicaThisName() {
	$ident = @parse_ini_file($GLOBALS['zfsm_ident_file'] ?? '/boot/config/ident.cfg');
	$name = is_array($ident) ? (string)($ident['NAME'] ?? '') : '';
	return zfsmReplicaSenderName($name) ? $name : null;
}

// "ssh-ed25519 <key> <comment>" when this server has made its key, else null.
function zfsmReplicaPublicKey() {
	$fields = preg_split('/\s+/', trim((string)@file_get_contents(zfsmReplicaSshDir().'/id_ed25519.pub')));
	if (count($fields) < 2 || zfsmKeyFingerprint($fields[0].' '.$fields[1]) === null) return null;
	return implode(' ', array_slice($fields, 0, 3));
}

// Made once and never replaced here: a new key would leave every server's line naming the old one. The private half stays on this server.
function zfsmReplicaKeyMake() {
	$name = zfsmReplicaThisName();
	if ($name === null) return array('error' => 'This server\'s name must be 1 to 64 letters, digits, _ . : or -, starting with a letter or digit');
	$lock = zfsmJobLock(zfsmJobDir('replica-send').'/settings.lock');
	if (!$lock) return array('error' => 'Another change to these settings is being saved; try again');
	try {
		$dir = zfsmReplicaSshDir();
		if (is_file($dir.'/id_ed25519')) return array('error' => 'This server already has its key');
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return array('error' => 'Could not make '.$dir);
		$new = $dir.'/new-'.bin2hex(random_bytes(4));
		$made = runProcess('ssh-keygen', array('-q', '-t', 'ed25519', '-N', '', '-C', 'zfs.master@'.$name, '-f', $new));
		// The public half first: the private key in place is what "has its key" means.
		$placed = ($made['code'] ?? 1) === 0 && is_file($new) && @rename($new.'.pub', $dir.'/id_ed25519.pub') && @rename($new, $dir.'/id_ed25519');
		@unlink($new);
		@unlink($new.'.pub');
		if (!$placed) return array('error' => zfsmCommandError($made, 'The key could not be made'));
		return array('key' => zfsmReplicaPublicKey());
	} finally {
		zfsmJobUnlock($lock);
	}
}

function zfsmReplicaHostValid($host) {
	if (!is_string($host)) return false;
	if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
	return strlen($host) <= 253 && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}))*$/D', $host) === 1;
}

function zfsmReplicaPortValid($port) {
	return is_int($port) && $port >= 1 && $port <= 65535;
}

// What remotes.json may hold: name (the label here), host, port and the host key pinned when it was added.
function zfsmReplicaRemoteValid($remote) {
	return is_array($remote) && zfsmReplicaSenderName($remote['name'] ?? null) && zfsmReplicaHostValid($remote['host'] ?? null)
		&& zfsmReplicaPortValid($remote['port'] ?? null) && zfsmKeyFingerprint($remote['key'] ?? null) !== null;
}

// The trusted servers by name; an entry the file holds in any other shape is left out, never half-used.
function zfsmReplicaRemotes() {
	$list = json_decode((string)@file_get_contents(zfsmReplicaSshDir().'/remotes.json'), true);
	$remotes = array();
	foreach (is_array($list) ? $list : array() as $remote) {
		if (zfsmReplicaRemoteValid($remote)) $remotes[$remote['name']] = array('name' => $remote['name'], 'host' => $remote['host'], 'port' => $remote['port'], 'key' => $remote['key']);
	}
	return $remotes;
}

// $change gets the servers and returns them changed, or a string saying why not; under the settings lock, written whole.
function zfsmReplicaRemotesChange($change) {
	$lock = zfsmJobLock(zfsmJobDir('replica-send').'/settings.lock');
	if (!$lock) return 'Another change to these settings is being saved; try again';
	try {
		$remotes = $change(zfsmReplicaRemotes());
		if (is_string($remotes)) return $remotes;
		$dir = zfsmReplicaSshDir();
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return 'Could not make '.$dir;
		$new = $dir.'/remotes.json.new';
		if (@file_put_contents($new, zfsmJson(array_values($remotes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false || !@rename($new, $dir.'/remotes.json')) {
			@unlink($new);
			return 'Could not save '.$dir.'/remotes.json';
		}
		return '';
	} finally {
		zfsmJobUnlock($lock);
	}
}

// The host key a server offers now, to compare with the one its own Settings page shows before it is added.
function zfsmReplicaScan($host, $port) {
	if (!zfsmReplicaHostValid($host) || !zfsmReplicaPortValid($port)) return array('error' => 'Give the server\'s IP address or DNS name and its SSH port');
	$scan = runProcess('ssh-keyscan', array('-T', '10', '-t', 'ed25519', '-p', (string)$port, $host));
	foreach (preg_split('/\R/', (string)($scan['output'] ?? '')) as $line) {
		$fields = preg_split('/\s+/', trim($line));
		if (count($fields) < 3 || $fields[0][0] === '#') continue;
		$key = $fields[1].' '.$fields[2];
		$fingerprint = zfsmKeyFingerprint($key);
		if ($fingerprint !== null) return array('key' => $key, 'fingerprint' => $fingerprint);
	}
	return array('error' => 'No ed25519 host key came back from '.$host.' port '.$port);
}

// Added only with the fingerprint the page showed, so the key saved is the one compared; a name already used must be removed first.
function zfsmReplicaRemoteAdd($name, $host, $port, $key, $fingerprint) {
	$remote = array('name' => $name, 'host' => $host, 'port' => $port, 'key' => $key);
	if (!zfsmReplicaSenderName($name)) return 'Name it with 1 to 64 letters, digits, _ . : or -, starting with a letter or digit';
	if (!zfsmReplicaRemoteValid($remote)) return 'Give the server\'s address, SSH port and the host key read from it';
	if (!is_string($fingerprint) || zfsmKeyFingerprint($key) !== $fingerprint) return 'The host key is not the one shown; read it again';
	return zfsmReplicaRemotesChange(function($remotes) use ($remote) {
		if (isset($remotes[$remote['name']])) return $remote['name'].' is already a server here; remove it first to change it';
		$remotes[$remote['name']] = $remote;
		return $remotes;
	});
}

function zfsmReplicaRemoteRemove($name) {
	return zfsmReplicaRemotesChange(function($remotes) use ($name) {
		if (!isset($remotes[$name])) return 'No server here is named '.$name;
		unset($remotes[$name]);
		return $remotes;
	});
}

// The address this server sends from to reach $host, as the kernel picks it; null when it can't be read.
function zfsmReplicaSourceAddress($host) {
	$address = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
	if (filter_var($address, FILTER_VALIDATE_IP) === false) return null;
	$route = runProcess('ip', array('route', 'get', $address));
	return preg_match('/\bsrc (\S+)/', (string)($route['output'] ?? ''), $match) === 1 && filter_var($match[1], FILTER_VALIDATE_IP) !== false ? $match[1] : null;
}

// The one line the other server's root adds to authorized_keys; Settings there shows it as held (zfsmReplicaKeys).
function zfsmReplicaKeyLine($remote) {
	$public = zfsmReplicaPublicKey();
	$name = zfsmReplicaThisName();
	if ($public === null || $name === null) return null;
	$from = zfsmReplicaSourceAddress($remote['host']) ?? 'this server\'s address as that server sees it';
	return 'restrict,from="'.$from.'",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php '.$name.'" '.$public;
}

// RAM copies at 0600 of the key and a known_hosts holding only this server's pinned key: ssh refuses a key on the flash drive's modes.
function zfsmReplicaSshFiles($remote) {
	$private = @file_get_contents(zfsmReplicaSshDir().'/id_ed25519');
	if (!is_string($private) || $private === '') return null;
	$dir = zfsmJobDir('replica-send').'/ssh-'.bin2hex(random_bytes(6));
	if (!@mkdir($dir, 0700, true)) return null;
	$files = array('dir' => $dir, 'key' => $dir.'/id_ed25519', 'known_hosts' => $dir.'/known_hosts');
	$host = $remote['port'] === 22 ? $remote['host'] : '['.$remote['host'].']:'.$remote['port'];
	$written = @file_put_contents($files['key'], $private) !== false && @chmod($files['key'], 0600) && @file_put_contents($files['known_hosts'], $host.' '.$remote['key']."\n") !== false;
	if ($written) return $files;
	zfsmReplicaSshCleanup($files);
	return null;
}

function zfsmReplicaSshCleanup($files) {
	foreach (array('key', 'known_hosts') as $file) @unlink($files[$file]);
	@rmdir($files['dir']);
}

// Only the pinned host key is trusted and nothing is forwarded; no config file can add a ProxyCommand or another key.
function zfsmReplicaSshArgs($remote, $files, $request) {
	$options = array('IdentitiesOnly=yes', 'IdentityAgent=none', 'BatchMode=yes', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile='.$files['known_hosts'],
		'GlobalKnownHostsFile=/dev/null', 'CheckHostIP=no', 'HostKeyAlgorithms=ssh-ed25519', 'UpdateHostKeys=no', 'ForwardAgent=no', 'ForwardX11=no',
		'ClearAllForwardings=yes', 'PermitLocalCommand=no', 'ConnectTimeout=10', 'ServerAliveInterval=15', 'ServerAliveCountMax=4');
	$args = array('-F', 'none', '-T', '-i', $files['key']);
	foreach ($options as $option) array_push($args, '-o', $option);
	return array_merge($args, array('-p', (string)$remote['port'], '-l', 'root', '--', $remote['host'], $request));
}

// hello on the other server: the area its copies go into, or why there is none (ssh's own error or the checker's refusal).
// runProcess trims the answer, so it ends at the area's name.
function zfsmReplicaHello($remote) {
	$files = zfsmReplicaSshFiles($remote);
	if ($files === null) return array('error' => 'This server has no key yet, or its RAM copy could not be made');
	try {
		$hello = runProcess('ssh', zfsmReplicaSshArgs($remote, $files, 'zfsm-replica 1 hello'));
	} finally {
		zfsmReplicaSshCleanup($files);
	}
	if (($hello['code'] ?? 1) === 0 && preg_match('/\Azfsm-replica 1\narea (\S+)\z/', (string)$hello['output'], $match) === 1 && zfsmValidDatasetName($match[1])) {
		return array('area' => $match[1]);
	}
	return array('error' => zfsmCommandError($hello, 'No answer from '.$remote['name']));
}

// Settings' key row: the public half once made, or the button that makes it.
function zfsmReplicaKeyHtml() {
	$public = zfsmReplicaPublicKey();
	if ($public === null) return '<input type="button" value="Make This Server\'s Key" onclick="zfsmSendKey()">';
	return '<input type="text" readonly style="width:100%" aria-label="This server\'s public key" value="'.zfsmHtml($public).'">';
}

// Settings' list of trusted servers, each with the line to add on it.
function zfsmReplicaRemotesHtml($remotes) {
	if (!$remotes) return 'None yet';
	$html = '';
	foreach ($remotes as $remote) {
		$line = zfsmReplicaKeyLine($remote);
		$id = 'zfsm-send-'.bin2hex($remote['name']);
		$html .= '<span style="display:block">'.zfsmHtml($remote['name'].' ('.$remote['host'].' port '.$remote['port'].'), host key '.zfsmKeyFingerprint($remote['key']))
			.' <input type="button" value="Test" onclick="zfsmSendTest('.zfsmHtml(zfsmJson($remote['name'])).', \''.$id.'\')">'
			.' <input type="button" value="Remove" onclick="zfsmSendRemove('.zfsmHtml(zfsmJson($remote['name'])).')">'
			.' <span id="'.$id.'"></span></span>';
		if ($line !== null) $html .= '<input type="text" readonly style="width:100%" aria-label="'.zfsmHtml('The line to add on '.$remote['name']).'" value="'.zfsmHtml($line).'">';
	}
	return $html;
}
