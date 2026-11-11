<?php
// Sending to other servers (include/ZFSMReplicaRemote.php), with the real ssh-keygen and ssh -G; the fake ssh runs the checker's
// real request handling (fixtures/fake_ssh_serve.php), so hello goes through it.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

// The real OpenSSH client tools (tests/ci_php.sh installs them); without them this test would check nothing.
exec('command -v ssh-keygen; command -v ssh', $found);
same(2, count($found), 'ssh-keygen and ssh are installed');

$work = sys_get_temp_dir().'/zfsm-send-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/bin', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
foreach (array('zfs', 'zpool') as $fake) copy(getenv('ZFSM_TEST_BIN_DIR').'/'.$fake, $work.'/bin/'.$fake) && chmod($work.'/bin/'.$fake, 0755);
$script = function($name, $body) use ($work) {
	file_put_contents($work.'/bin/'.$name, "#!/bin/sh\n".$body);
	chmod($work.'/bin/'.$name, 0755);
};
// ssh-keyscan and ip answer from the test; ssh checks what it was given, then runs the checker as sshd's forced command would.
$script('ssh-keyscan', "printf '%s\\n' \"ssh-keyscan \$*\" >> \"\$ZFSM_TEST_SSH_LOG\"\necho \"# \$*: SSH-2.0-OpenSSH_10.0\" >&2\n[ -n \"\${ZFSM_TEST_SCAN:-}\" ] && printf '%s\\n' \"\$ZFSM_TEST_SCAN\"\nexit 0\n");
$script('ip', "printf '%s\\n' \"ip \$*\" >> \"\$ZFSM_TEST_SSH_LOG\"\n[ -n \"\${ZFSM_TEST_NO_ROUTE:-}\" ] && exit 2\nprintf '%s dev br0 src 10.0.20.5 uid 0 \\n    cache \\n' \"\$3\"\n");
$script('ssh', implode("\n", array(
	'printf "%s\n" "ssh $*" >> "$ZFSM_TEST_SSH_LOG"',
	"key=''; known=''; prev=''",
	'for a in "$@"; do',
	'	[ "$prev" = -i ] && key=$a',
	'	case "$prev:$a" in -o:UserKnownHostsFile=*) known=${a#UserKnownHostsFile=} ;; esac',
	'	prev=$a',
	'done',
	'for last in "$@"; do :; done',
	'printf "key-mode %s\n" "$(stat -c %a "$key")" >> "$ZFSM_TEST_SSH_LOG"',
	'cmp -s "$key" "$ZFSM_TEST_FLASH_KEY" && echo key-same >> "$ZFSM_TEST_SSH_LOG"',
	'printf "known %s\n" "$(cat "$known")" >> "$ZFSM_TEST_SSH_LOG"',
	'[ -n "${ZFSM_TEST_SSH_FAIL:-}" ] && { echo "$ZFSM_TEST_SSH_FAIL" >&2; exit 255; }',
	'SSH_ORIGINAL_COMMAND=$last SSH_CLIENT="10.0.20.5 50000 22" exec "$ZFSM_TEST_PHP" -n "$ZFSM_TEST_SERVE"',
	'')));
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
putenv('ZFSM_TEST_SSH_LOG='.$work.'/ssh.log');
putenv('ZFSM_TEST_PHP='.PHP_BINARY);
putenv('ZFSM_TEST_SERVE='.__DIR__.'/fixtures/fake_ssh_serve.php');
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash/ssh';
$GLOBALS['zfsm_ident_file'] = $work.'/ident.cfg';
putenv('ZFSM_TEST_FLASH_KEY='.$work.'/flash/ssh/id_ed25519');
$log = function() use ($work) {
	$lines = is_file($work.'/ssh.log') ? file($work.'/ssh.log', FILE_IGNORE_NEW_LINES) : array();
	@unlink($work.'/ssh.log');
	return $lines;
};

// The name the other server knows this one by is Unraid's server name, within its sender-name rules.
file_put_contents($work.'/ident.cfg', "NAME=\"bad name\"\n");
same(null, zfsmReplicaThisName(), 'a server name with a space can\'t name a sender');
same(array('error' => 'This server\'s name must be 1 to 64 letters, digits, _ . : or -, starting with a letter or digit'), zfsmReplicaKeyMake(), 'so no key is made for it');
same(false, is_dir($work.'/flash/ssh'), 'and nothing is written');
file_put_contents($work.'/ident.cfg', "NAME=\"tower\"\n");
same('tower', zfsmReplicaThisName(), 'Unraid\'s server name');

// The key: made once by the real ssh-keygen, its public half fingerprinted as ssh-keygen -l does, never replaced.
same(null, zfsmReplicaPublicKey(), 'no key yet');
same(true, strpos(zfsmReplicaKeyHtml(), 'onclick="zfsmSendKey()"') !== false, 'so Settings offers to make it');
$made = zfsmReplicaKeyMake();
$public = trim(file_get_contents($work.'/flash/ssh/id_ed25519.pub'));
same(array('key' => $public), $made, 'the key is made');
same(1, preg_match('/^ssh-ed25519 \S+ zfs\.master@tower$/D', $public), 'an ed25519 key named for this server: '.$public);
exec('ssh-keygen -l -E sha256 -f '.escapeshellarg($work.'/flash/ssh/id_ed25519.pub'), $listed);
same(explode(' ', $listed[0])[1] ?? '', zfsmKeyFingerprint($public), 'its fingerprint is the one ssh-keygen -l prints');
same(array('id_ed25519', 'id_ed25519.pub'), array_values(array_diff(scandir($work.'/flash/ssh'), array('.', '..'))), 'nothing else is left beside it');
$private = file_get_contents($work.'/flash/ssh/id_ed25519');
same(array('error' => 'This server already has its key'), zfsmReplicaKeyMake(), 'a second make is refused');
same($private, file_get_contents($work.'/flash/ssh/id_ed25519'), 'and the key stays the same');
same(true, strpos(zfsmReplicaKeyHtml(), 'value="'.zfsmHtml($public).'"') !== false, 'Settings shows the public half');
// A lost or emptied public half is read back from the private key by the real ssh-keygen -y, never replaced by a new key.
foreach (array('lost' => null, 'emptied' => '') as $how => $content) {
	if ($content === null) unlink($work.'/flash/ssh/id_ed25519.pub');
	else file_put_contents($work.'/flash/ssh/id_ed25519.pub', $content);
	same(array(null, true), array(zfsmReplicaPublicKey(), strpos(zfsmReplicaKeyHtml(), 'onclick="zfsmSendKey()"') !== false), 'a '.$how.' public half offers Make again');
	same(array('key' => $public), zfsmReplicaKeyMake(), 'which reads the same key back');
	same($private, file_get_contents($work.'/flash/ssh/id_ed25519'), 'and leaves the private half as it was');
}

// A failed ssh-keygen leaves no key and says why.
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash2/ssh';
$script('ssh-keygen', "echo 'ssh-keygen: out of entropy' >&2\nexit 1\n");
same(array('error' => 'ssh-keygen: out of entropy'), zfsmReplicaKeyMake(), 'a failed make says why');
same(array(), array_values(array_diff(scandir($work.'/flash2/ssh'), array('.', '..'))), 'and leaves nothing');
unlink($work.'/bin/ssh-keygen');
// A make that placed only the public half has no key, so it can be made again.
copy($work.'/flash/ssh/id_ed25519.pub', $work.'/flash2/ssh/id_ed25519.pub');
same(array(null, true), array(zfsmReplicaPublicKey(), strpos(zfsmReplicaKeyHtml(), 'onclick="zfsmSendKey()"') !== false), 'a public half alone is no key');
$remade = zfsmReplicaKeyMake();
same(array(true, true), array(is_file($work.'/flash2/ssh/id_ed25519'), ($remade['key'] ?? '') === trim(file_get_contents($work.'/flash2/ssh/id_ed25519.pub'))), 'and is made again, in full');
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash/ssh';

// Addresses and ports: an IP address only, so nothing that ssh could read as an option and no DNS name.
same(array(true, true), array_map('zfsmReplicaHostValid', array('10.0.20.11', 'fd7a:115c:a1e0::1')), 'IP addresses are taken, as the key line\'s from= must be one');
same(array(false, false, false, false, false, false), array_map('zfsmReplicaHostValid', array('nas', 'tower.tail1234.ts.net', '', '-oProxyCommand=x', '[::1]', 'root@10.0.20.11')), 'nothing else, DNS names included');
same(array(true, false, false, false), array_map('zfsmReplicaPortValid', array(22, 0, 65536, '22')), 'a port is a number from 1 to 65535');

// The host key a server offers, read with ssh-keyscan; its comment lines and other key types are skipped.
exec('ssh-keygen -q -t ed25519 -N "" -C host -f '.escapeshellarg($work.'/host'));
$host_key = implode(' ', array_slice(explode(' ', trim(file_get_contents($work.'/host.pub'))), 0, 2));
exec('ssh-keygen -l -E sha256 -f '.escapeshellarg($work.'/host.pub'), $host_listed);
$host_print = explode(' ', $host_listed[0])[1] ?? '';
putenv('ZFSM_TEST_SCAN=10.0.20.11 ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ'."\n".'10.0.20.11 '.$host_key);
same(array('key' => $host_key, 'fingerprint' => $host_print), zfsmReplicaScan('10.0.20.11', 2222), 'the ed25519 host key, with the fingerprint ssh-keygen -l gives');
same(array('ssh-keyscan -T 10 -t ed25519 -p 2222 10.0.20.11'), $log(), 'read once, with a time limit');
putenv('ZFSM_TEST_SCAN=10.0.20.11 ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ');
same(array('error' => 'No ed25519 host key came back from 10.0.20.11 port 22'), zfsmReplicaScan('10.0.20.11', 22), 'no ed25519 key, no answer');
$log();
same(array('error' => 'Give the server\'s IP address and its SSH port'), zfsmReplicaScan('-oProxyCommand=x', 22), 'an address that isn\'t one is refused');
same(array(), $log(), 'without running ssh-keyscan');
putenv('ZFSM_TEST_SCAN');

// Adding pins the host key the page showed; the name stays until it is removed.
same('The host key is not the one shown; read it again', zfsmReplicaRemoteAdd('nas', '10.0.20.11', 2222, $host_key, 'SHA256:other'), 'a key other than the one compared is refused');
same('Give the server\'s IP address, SSH port and the host key read from it', zfsmReplicaRemoteAdd('nas', '10.0.20.11', 2222, 'ssh-ed25519 AAAA', $host_print), 'so is a key that isn\'t one');
same('Give the server\'s IP address, SSH port and the host key read from it', zfsmReplicaRemoteAdd('nas', '10.0.20.11', 2222, $host_key."\n* ".$host_key, $host_print), 'and one carrying a second known_hosts line after it');
same('Name it with 1 to 64 letters, digits, _ . : or -, starting with a letter or digit', zfsmReplicaRemoteAdd('my nas', '10.0.20.11', 2222, $host_key, $host_print), 'and a name with a space');
same('', zfsmReplicaRemoteAdd('nas', '10.0.20.11', 2222, $host_key, $host_print), 'a server is added');
same('nas is already a server here; remove it first to change it', zfsmReplicaRemoteAdd('nas', '10.0.20.12', 22, $host_key, $host_print), 'its name isn\'t taken over');
same(array('nas' => array('name' => 'nas', 'host' => '10.0.20.11', 'port' => 2222, 'key' => $host_key)), zfsmReplicaRemotes(), 'it is kept with its pinned key');
$saved = json_decode(file_get_contents($work.'/flash/ssh/remotes.json'), true);
$saved[] = array('name' => 'broken', 'host' => '10.0.20.13', 'port' => '22', 'key' => $host_key);
$saved[] = array('name' => 'smuggled', 'host' => '10.0.20.14', 'port' => 22, 'key' => $host_key."\n@cert-authority * ".$host_key);
file_put_contents($work.'/flash/ssh/remotes.json', json_encode($saved));
same(array('nas'), array_keys(zfsmReplicaRemotes()), 'an entry the file holds in another shape is left out');
$kept = file_get_contents($work.'/flash/ssh/remotes.json');
file_put_contents($work.'/flash/ssh/remotes.json', '{"name": "nas", "host"');
$unreadable = $work.'/flash/ssh/remotes.json could not be read as a list of servers; fix or remove it first';
same($unreadable, zfsmReplicaRemoteAdd('other', '10.0.20.12', 22, $host_key, $host_print), 'a file that can\'t be read is never written over');
same('{"name": "nas", "host"', file_get_contents($work.'/flash/ssh/remotes.json'), 'it stays as it was');
same(array(null, $unreadable), array(zfsmReplicaRemotes(), zfsmReplicaRemote('nas')), 'a failed read is not an empty list, and Test says why');
same('<span style="color:#e22828">'.zfsmHtml($unreadable).'</span>', zfsmReplicaRemotesHtml(zfsmReplicaRemotes()), 'so does Settings, where "None yet" would hide the pinned servers');
file_put_contents($work.'/flash/ssh/remotes.json', $kept);
$nas = zfsmReplicaRemotes()['nas'];
same(array($nas, 'No server here is named other'), array(zfsmReplicaRemote('nas'), zfsmReplicaRemote('other')), 'one server by its name, or that none has it');

// The line the other server adds: from= the address this server sends from, the exact checker and this server's name.
$line = zfsmReplicaKeyLine($nas, zfsmReplicaSender());
same('restrict,from="10.0.20.5",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php tower" '.$public, $line, 'one line to add there');
same(array('ip route get 10.0.20.11'), $log(), 'from the kernel\'s route to it');
file_put_contents($work.'/authorized_keys', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHwdIausNEPnAJgtomzVsSoe8NyNhe7L/745s7iIuKjF someone\n".$line."\n");
same(array(array('sender' => 'tower', 'comment' => 'zfs.master@tower', 'restrict' => true, 'from' => true)), zfsmReplicaKeys($work.'/authorized_keys'), 'the other server\'s Settings read it as held, for tower');
same(true, strpos(zfsmReplicaKeysHtml(zfsmReplicaKeys($work.'/authorized_keys')), 'tower (zfs.master@tower): limited to replication') !== false, 'and say so');
$listed_html = zfsmReplicaRemotesHtml(zfsmReplicaRemotes());
same(true, strpos($listed_html, 'nas (10.0.20.11 port 2222), host key '.$host_print) !== false && strpos($listed_html, 'value="'.zfsmHtml($line).'"') !== false, 'Settings lists the server with its line');
putenv('ZFSM_TEST_NO_ROUTE=1');
same(null, zfsmReplicaKeyLine($nas, zfsmReplicaSender()), 'no route to it, no line: a from= that can\'t match is never offered');
same(true, strpos(zfsmReplicaRemotesHtml(zfsmReplicaRemotes()), 'The address this server sends from to it could not be read.') !== false, 'Settings says why');
putenv('ZFSM_TEST_NO_ROUTE');
same(null, zfsmReplicaKeyLine($nas, null), 'nor before this server has its key');
// No sender, no line: Settings asks for the key only when that is what is missing.
file_put_contents($work.'/ident.cfg', "NAME=\"bad name\"\n");
same(true, strpos(zfsmReplicaRemotesHtml(zfsmReplicaRemotes()), 'This server\'s name must be 1 to 64 letters, digits, _ . : or -, starting with a letter or digit; rename it under Settings, Identification to see the line to add there.') !== false, 'a key under a server name no sender can have asks for the name');
file_put_contents($work.'/ident.cfg', "NAME=\"tower\"\n");
rename($work.'/flash/ssh/id_ed25519', $work.'/flash/ssh/id_ed25519.away');
same(true, strpos(zfsmReplicaRemotesHtml(zfsmReplicaRemotes()), 'Make this server\'s key to see the line to add there.') !== false, 'no key asks for the key');
rename($work.'/flash/ssh/id_ed25519.away', $work.'/flash/ssh/id_ed25519');
$log();

// ssh's options, checked by the real ssh -G: only the pinned host key, this key alone, no config file, nothing forwarded.
$files = zfsmReplicaSshFiles($nas);
$args = zfsmReplicaSshArgs($nas, $files, 'zfsm-replica 1 hello');
same(array('--', '10.0.20.11', 'zfsm-replica 1 hello'), array_slice($args, -3), 'the address can\'t be read as an option, and the request comes last');
exec('ssh -G '.implode(' ', array_map('escapeshellarg', array_slice($args, 0, -2))).' 10.0.20.11 2>&1', $resolved, $resolved_code);
$want = array('user root', 'hostname 10.0.20.11', 'port 2222', 'batchmode yes', 'stricthostkeychecking true', 'identitiesonly yes', 'identityagent none',
	'userknownhostsfile '.$files['known_hosts'], 'globalknownhostsfile /dev/null', 'hostkeyalgorithms ssh-ed25519', 'forwardagent no', 'forwardx11 no',
	'clearallforwardings yes', 'permitlocalcommand no', 'loglevel ERROR', 'connecttimeout 10', 'serveraliveinterval 15', 'serveralivecountmax 2', 'identityfile '.$files['key']);
same(array(0, array()), array($resolved_code, array_values(array_diff($want, $resolved))), 'ssh takes every option and holds them: '.implode(' | ', $resolved));
same(array('identityfile '.$files['key']), array_values(preg_grep('/^identityfile /', $resolved)), 'and offers this key alone, none of root\'s own');
same(array("[10.0.20.11]:2222 ".$host_key), file($files['known_hosts'], FILE_IGNORE_NEW_LINES), 'known_hosts holds only the pinned key, at its port');
same('600', substr(sprintf('%o', fileperms($files['key'])), -3), 'the RAM copy of the key is 0600');
zfsmReplicaSshCleanup($files);
same(false, is_dir($files['dir']), 'and goes when the call ends');

// hello through ssh into the checker: the area there, or why not, in its own words.
mkdir($work.'/pool');
file_put_contents($work.'/pool/datasets', "backup\tfilesystem\nbackup/replicas\tfilesystem\n");
putenv('ZFSM_TEST_REPL_DIR='.$work.'/pool');
putenv('ZFSM_TEST_ACCEPT=backup/replicas');
putenv('ZFSM_TEST_SENDER=tower');
same(array('area' => 'backup/replicas/tower'), zfsmReplicaHello($nas), 'hello answers with this server\'s area there');
$seen = $log();
file_put_contents($work.'/pool/datasets', "backup/My Replicas\tfilesystem\n", FILE_APPEND);
putenv('ZFSM_TEST_ACCEPT=backup/My Replicas');
same(array('area' => 'backup/My Replicas/tower'), zfsmReplicaHello($nas), 'an area whose name holds a space');
putenv('ZFSM_TEST_ACCEPT=backup/replicas');
$log();
same(array('key-mode 600', 'key-same', 'known [10.0.20.11]:2222 '.$host_key), array_slice($seen, 1), 'ssh got a 0600 copy of the key and only the pinned host key');
same(array(), glob(zfsmJobDir('replica-send').'/ssh-*'), 'and the copies are gone after');
putenv('ZFSM_TEST_ACCEPT=');
same(array('error' => 'This server does not accept replicas from this key'), zfsmReplicaHello($nas), 'a server that accepts nothing says so');
putenv('ZFSM_TEST_SSH_FAIL=Host key verification failed.');
same(array('error' => 'Host key verification failed.'), zfsmReplicaHello($nas), 'ssh\'s own error comes through');
putenv('ZFSM_TEST_SSH_FAIL');
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash3/ssh';
same(array('error' => 'This server has no key yet, or its RAM copy could not be made'), zfsmReplicaHello($nas), 'no key, no ssh');
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash/ssh';
$log();

// A settings lock that can't be opened says so, rather than that another save is running.
file_put_contents($work.'/not-a-folder', '');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/not-a-folder');
same('Could not open the settings lock in '.$work.'/not-a-folder/replica-send', zfsmReplicaRemoteRemove('nas'), 'an unusable lock folder is named');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');

// Removing forgets the server and its pinned key, with any second record the file holds under its name.
$names = function() use ($work) { return array_column(json_decode(file_get_contents($work.'/flash/ssh/remotes.json'), true), 'name'); };
$saved = json_decode(file_get_contents($work.'/flash/ssh/remotes.json'), true);
$saved[] = array('name' => 'nas', 'host' => '10.0.20.99', 'port' => 22, 'key' => $host_key);
file_put_contents($work.'/flash/ssh/remotes.json', json_encode($saved));
same('', zfsmReplicaRemoteAdd('spare', '10.0.20.12', 22, $host_key, $host_print), 'another server is added');
same(array(array('nas', 'spare', 'broken', 'smuggled', 'nas'), '10.0.20.11'), array($names(), zfsmReplicaRemote('nas')['host']), 'a second record under a listed name is written back, and the first stays the one in use');
same('No server here is named other', zfsmReplicaRemoteRemove('other'), 'only a listed server can be removed');
same('', zfsmReplicaRemoteRemove('nas'), 'a server is removed');
same(array('spare'), array_keys(zfsmReplicaRemotes()), 'and is gone: its second record does not bring it back');
same(array('spare', 'broken', 'smuggled'), $names(), 'the entries in other shapes are written back as they were');
same('', zfsmReplicaRemoteRemove('spare'), 'the last server is removed');
same('None yet', zfsmReplicaRemotesHtml(zfsmReplicaRemotes()), 'Settings says when there are none');

echo "replica remote tests passed\n";
