<?php
// Sending copies to other servers: this server's own key, the servers it trusts (each one's host key pinned when added), and ssh
// to the checker there (scripts/replica-serve.php, include/ZFSMReplicaServe.php), which runs one fixed zfs command per request.
require_once __DIR__.'/ZFSMJobs.php';

// On the flash drive, so the key and the pinned host keys survive a reboot; ssh only ever reads RAM copies (zfsmReplicaSshFiles).
function zfsmReplicaSshDir() {
	return $GLOBALS['zfsm_ssh_dir'] ?? '/boot/config/plugins/zfs.master/ssh';
}

// The sender name in the key line on the servers it sends to; null when Unraid's server name breaks the sender-name rules there.
function zfsmReplicaThisName() {
	$ident = @parse_ini_file($GLOBALS['zfsm_ident_file'] ?? '/boot/config/ident.cfg');
	$name = is_array($ident) ? (string)($ident['NAME'] ?? '') : '';
	return zfsmReplicaSenderName($name) ? $name : null;
}

// A public half without the private one is a make that stopped part-way: no key, so Settings offers to make it again.
function zfsmReplicaPublicKey() {
	$dir = zfsmReplicaSshDir();
	if (!is_file($dir.'/id_ed25519')) return null;
	$fields = preg_split('/\s+/', trim((string)@file_get_contents($dir.'/id_ed25519.pub')));
	if (count($fields) < 2 || zfsmKeyFingerprint($fields[0].' '.$fields[1]) === null) return null;
	return implode(' ', array_slice($fields, 0, 3));
}

// This server's public key and name for the line each server adds, read once; null until both are there.
function zfsmReplicaSender() {
	$public = zfsmReplicaPublicKey();
	$name = zfsmReplicaThisName();
	return $public === null || $name === null ? null : array('public' => $public, 'name' => $name);
}

// The lock every change to these settings holds, or why there is none.
function zfsmReplicaSettingsLock() {
	$dir = zfsmJobDir('replica-send');
	$lock = zfsmJobLock($dir.'/settings.lock');
	if ($lock === null) return 'Could not open the settings lock in '.$dir;
	return $lock === false ? 'Another change to these settings is being saved; try again' : $lock;
}

// Made once and never replaced here: a new key would leave every server's line naming the old one. The private half stays on this server.
function zfsmReplicaKeyMake() {
	$name = zfsmReplicaThisName();
	if ($name === null) return array('error' => 'This server\'s name must be 1 to 64 letters, digits, _ . : or -, starting with a letter or digit');
	$lock = zfsmReplicaSettingsLock();
	if (is_string($lock)) return array('error' => $lock);
	try {
		$dir = zfsmReplicaSshDir();
		if (is_file($dir.'/id_ed25519')) return zfsmReplicaPublicKey() !== null ? array('error' => 'This server already has its key') : zfsmReplicaPublicRestore($name);
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

// A lost or broken public half, read back from the private key: the key stays the one every server's line names.
function zfsmReplicaPublicRestore($name) {
	$files = zfsmReplicaKeyCopy();
	if ($files === null) return array('error' => 'The private key could not be copied to read its public half');
	try {
		$read = runProcess('ssh-keygen', array('-y', '-f', $files['key']));
	} finally {
		zfsmReplicaSshCleanup($files);
	}
	$fields = preg_split('/\s+/', trim((string)($read['output'] ?? '')));
	$public = count($fields) >= 2 ? $fields[0].' '.$fields[1] : '';
	if (($read['code'] ?? 1) !== 0 || zfsmKeyFingerprint($public) === null) return array('error' => zfsmCommandError($read, 'The public half could not be read back'));
	if (!zfsmWriteFileAtomic(zfsmReplicaSshDir().'/id_ed25519.pub', $public.' zfs.master@'.$name."\n")) return array('error' => 'The public half could not be saved');
	return array('key' => zfsmReplicaPublicKey());
}

// An IP address only: the from= in that server's key line must be an exact address (zfsmReplicaKeyFrom), so the route to it decides it.
function zfsmReplicaHostValid($host) {
	return is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
}

function zfsmReplicaPortValid($port) {
	return is_int($port) && $port >= 1 && $port <= 65535;
}

// Exactly "ssh-ed25519 <base64>": anything after it would reach known_hosts as more trusted keys.
function zfsmReplicaHostKeyValid($key) {
	return is_string($key) && preg_match('#^ssh-ed25519 [A-Za-z0-9+/]+={0,2}$#D', $key) === 1 && zfsmKeyFingerprint($key) !== null;
}

function zfsmReplicaRemoteValid($remote) {
	return is_array($remote) && zfsmReplicaSenderName($remote['name'] ?? null) && zfsmReplicaHostValid($remote['host'] ?? null)
		&& zfsmReplicaPortValid($remote['port'] ?? null) && zfsmReplicaHostKeyValid($remote['key'] ?? null);
}

// The trusted servers by name; an entry the file holds in any other shape is left out, never half-used.
function zfsmReplicaRemotes() {
	return zfsmReplicaRemotesRead()['valid'] ?? array();
}

// remotes.json as 'valid' servers by name and 'other' entries, kept as they are for a write; null when it exists but isn't a list.
function zfsmReplicaRemotesRead() {
	$file = zfsmReplicaSshDir().'/remotes.json';
	if (!file_exists($file)) return array('valid' => array(), 'other' => array());
	$list = json_decode((string)@file_get_contents($file), true);
	if (!is_array($list)) return null;
	$read = array('valid' => array(), 'other' => array());
	foreach ($list as $remote) {
		if (zfsmReplicaRemoteValid($remote) && !isset($read['valid'][$remote['name']])) $read['valid'][$remote['name']] = array('name' => $remote['name'], 'host' => $remote['host'], 'port' => $remote['port'], 'key' => $remote['key']);
		else $read['other'][] = $remote;
	}
	return $read;
}

// $change gets the servers and returns them changed, or a string saying why not; under the settings lock, written whole with the
// entries in other shapes as they were. A file that can't be read is never written over: it holds the pinned host keys.
function zfsmReplicaRemotesChange($change) {
	$lock = zfsmReplicaSettingsLock();
	if (is_string($lock)) return $lock;
	try {
		$dir = zfsmReplicaSshDir();
		$read = zfsmReplicaRemotesRead();
		if ($read === null) return $dir.'/remotes.json could not be read as a list of servers; fix or remove it first';
		$remotes = $change($read['valid']);
		if (is_string($remotes)) return $remotes;
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return 'Could not make '.$dir;
		$saved = zfsmWriteFileAtomic($dir.'/remotes.json', zfsmJson(array_merge(array_values($remotes), $read['other']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		return $saved ? '' : 'Could not save '.$dir.'/remotes.json';
	} finally {
		zfsmJobUnlock($lock);
	}
}

// The host key a server offers now, to compare with the one its own Settings page shows before it is added.
function zfsmReplicaScan($host, $port) {
	if (!zfsmReplicaHostValid($host) || !zfsmReplicaPortValid($port)) return array('error' => 'Give the server\'s IP address and its SSH port');
	$scan = runProcess('ssh-keyscan', array('-T', '10', '-t', 'ed25519', '-p', (string)$port, $host));
	foreach (preg_split('/\R/', (string)($scan['output'] ?? '')) as $line) {
		$fields = preg_split('/\s+/', trim($line));
		if (count($fields) < 3 || $fields[0][0] === '#') continue;
		$key = $fields[1].' '.$fields[2];
		if (zfsmReplicaHostKeyValid($key)) return array('key' => $key, 'fingerprint' => zfsmKeyFingerprint($key));
	}
	return array('error' => 'No ed25519 host key came back from '.$host.' port '.$port);
}

// Added only with the fingerprint the page showed, so the key saved is the one compared; a name already used must be removed first.
function zfsmReplicaRemoteAdd($name, $host, $port, $key, $fingerprint) {
	$remote = array('name' => $name, 'host' => $host, 'port' => $port, 'key' => $key);
	if (!zfsmReplicaSenderName($name)) return 'Name it with 1 to 64 letters, digits, _ . : or -, starting with a letter or digit';
	if (!zfsmReplicaRemoteValid($remote)) return 'Give the server\'s IP address, SSH port and the host key read from it';
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
	$route = runProcess('ip', array('route', 'get', $host));
	return preg_match('/\bsrc (\S+)/', (string)($route['output'] ?? ''), $match) === 1 && filter_var($match[1], FILTER_VALIDATE_IP) !== false ? $match[1] : null;
}

// The one line the other server's root adds to authorized_keys; Settings there shows it as held (zfsmReplicaKeys). null without a from= address.
function zfsmReplicaKeyLine($remote, $sender) {
	$from = $sender === null ? null : zfsmReplicaSourceAddress($remote['host']);
	if ($from === null) return null;
	return 'restrict,from="'.$from.'",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php '.$sender['name'].'" '.$sender['public'];
}

// A RAM copy at 0600 of the private key, in a folder of its own: ssh and ssh-keygen refuse a key on the flash drive's modes.
function zfsmReplicaKeyCopy() {
	$private = @file_get_contents(zfsmReplicaSshDir().'/id_ed25519');
	if (!is_string($private) || $private === '') return null;
	$dir = zfsmJobDir('replica-send').'/ssh-'.bin2hex(random_bytes(6));
	if (!@mkdir($dir, 0700, true)) return null;
	$files = array('dir' => $dir, 'key' => $dir.'/id_ed25519', 'known_hosts' => $dir.'/known_hosts');
	if (@file_put_contents($files['key'], $private) !== false && @chmod($files['key'], 0600)) return $files;
	zfsmReplicaSshCleanup($files);
	return null;
}

// The key copy and a known_hosts holding only this server's pinned key.
function zfsmReplicaSshFiles($remote) {
	$files = zfsmReplicaKeyCopy();
	if ($files === null) return null;
	$host = $remote['port'] === 22 ? $remote['host'] : '['.$remote['host'].']:'.$remote['port'];
	if (@file_put_contents($files['known_hosts'], $host.' '.$remote['key']."\n") !== false) return $files;
	zfsmReplicaSshCleanup($files);
	return null;
}

function zfsmReplicaSshCleanup($files) {
	foreach (array('key', 'known_hosts') as $file) @unlink($files[$file]);
	@rmdir($files['dir']);
}

// Only the pinned host key is trusted and nothing is forwarded; no config file can add a ProxyCommand or another key.
// LogLevel=ERROR keeps a banner or warning out of the answer, which runProcess reads with ssh's errors.
function zfsmReplicaSshArgs($remote, $files, $request) {
	$options = array('IdentitiesOnly=yes', 'IdentityAgent=none', 'BatchMode=yes', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile='.$files['known_hosts'],
		'GlobalKnownHostsFile=/dev/null', 'CheckHostIP=no', 'HostKeyAlgorithms=ssh-ed25519', 'UpdateHostKeys=no', 'ForwardAgent=no', 'ForwardX11=no',
		'ClearAllForwardings=yes', 'PermitLocalCommand=no', 'LogLevel=ERROR', 'ConnectTimeout=10', 'ServerAliveInterval=15', 'ServerAliveCountMax=2');
	$args = array('-F', 'none', '-T', '-i', $files['key']);
	foreach ($options as $option) array_push($args, '-o', $option);
	return array_merge($args, array('-p', (string)$remote['port'], '-l', 'root', '--', $remote['host'], $request));
}

// hello on the other server: the area its copies go into, or why there is none (ssh's own error or the checker's refusal).
// runProcess trims the answer, so it ends at the area's name, which may hold spaces.
function zfsmReplicaHello($remote) {
	$files = zfsmReplicaSshFiles($remote);
	if ($files === null) return array('error' => 'This server has no key yet, or its RAM copy could not be made');
	try {
		$hello = runProcess('ssh', zfsmReplicaSshArgs($remote, $files, 'zfsm-replica 1 hello'));
	} finally {
		zfsmReplicaSshCleanup($files);
	}
	if (($hello['code'] ?? 1) === 0 && preg_match('/\Azfsm-replica 1\narea (.+)\z/', (string)$hello['output'], $match) === 1 && zfsmValidDatasetName($match[1])) {
		return array('area' => $match[1]);
	}
	return array('error' => zfsmCommandError($hello, 'No answer from '.$remote['name']));
}

function zfsmReplicaKeyHtml() {
	$public = zfsmReplicaPublicKey();
	if ($public === null) return '<input type="button" value="Make This Server\'s Key" onclick="zfsmSendKey()">';
	return '<input type="text" readonly style="width:100%" aria-label="This server\'s public key" value="'.zfsmHtml($public).'">';
}

function zfsmReplicaRemotesHtml($remotes) {
	if (!$remotes) return 'None yet';
	$sender = zfsmReplicaSender();
	$html = '';
	foreach ($remotes as $remote) {
		$id = 'zfsm-send-'.bin2hex($remote['name']);
		$name = zfsmHtml(zfsmJson($remote['name']));
		$html .= '<span style="display:block">'.zfsmHtml($remote['name'].' ('.$remote['host'].' port '.$remote['port'].'), host key '.zfsmKeyFingerprint($remote['key']))
			.' <input type="button" value="Test" onclick="zfsmSendTest('.$name.', \''.$id.'\')">'
			.' <input type="button" value="Remove" onclick="zfsmSendRemove('.$name.')">'
			.' <span id="'.$id.'"></span></span>';
		$line = zfsmReplicaKeyLine($remote, $sender);
		if ($line !== null) {
			$html .= '<input type="text" readonly style="width:100%" aria-label="'.zfsmHtml('The line to add on '.$remote['name']).'" value="'.zfsmHtml($line).'">';
		} else {
			$html .= '<span style="display:block">'.($sender === null ? 'Make this server\'s key to see the line to add there.' : 'The address this server sends from to it could not be read.').'</span>';
		}
	}
	return $html;
}
