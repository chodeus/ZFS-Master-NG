<?php
// Receiving copies from another server (include/ZFSMReplicaServe.php): which requests a sender's key may run, a stream through the real
// proc_open into the fake zfs's small pool (ZFSM_TEST_REPL_DIR), the key lines the settings check, and scripts/replica-serve.php itself.

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

// Each sender gets its own area inside the accepting dataset, named in its key's line; a pool's top dataset is never accepted.
same('backup/replicas/tower', zfsmReplicaArea('backup/replicas', 'tower'), 'a sender\'s area is inside the accepting dataset');
foreach (array('' => 'tower', 'backup' => 'tower', 'bad;name/x' => 'tower') as $into => $sender) same(null, zfsmReplicaArea($into, $sender), 'nothing is accepted into '.var_export($into, true));
foreach (array(null, '', 'a b', 'a/b', '-x', '.hidden', 'x;y', str_repeat('n', 65)) as $sender) same(null, zfsmReplicaArea('backup/replicas', $sender), 'no area for the sender name '.var_export($sender, true));

$area = 'backup/replicas/tower';
$req = function($verb, $name = null) { return 'zfsm-replica 1 '.$verb.($name === null ? '' : ' '.bin2hex($name)); };
$check = function($request, $in = 'backup/replicas/tower') { $checked = zfsmReplicaServeCheck($request, $in); return $checked['error'] ?? $checked['args']; };

// Each request runs one fixed zfs command.
same(null, $check('zfsm-replica 1 hello'), 'hello runs no zfs command of its own');
same(array('list', '-H', '-o', 'name,type', 'backup/replicas/tower'), $check($req('exists', 'backup/replicas/tower')), 'exists may ask about the area, the parent of a first copy');
same(array('list', '-H', '-o', 'name,type', 'backup/replicas/tower/data'), $check($req('exists', 'backup/replicas/tower/data')), 'and about a copy inside it');
same(array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', 'backup/replicas/tower/data'), $check($req('snapshots', 'backup/replicas/tower/data')), 'snapshots lists a copy\'s snapshots');
same(array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', 'backup/replicas/tower/data'), $check($req('token', 'backup/replicas/tower/data')), 'token reads a copy\'s resume token');
same(array('receive', '-s', '-u', '-o', 'readonly=on', '-x', 'mountpoint', '-x', 'sharenfs', '-x', 'sharesmb', 'backup/replicas/tower/my data'), $check($req('receive', 'backup/replicas/tower/my data')), 'receive takes a stream read-only, never -F, inheriting the area\'s mountpoint=none whatever the stream holds');

// Everything else is refused.
$data = bin2hex('backup/replicas/tower/data');
$refused = array(
	'an empty request' => array('', 'Not a replication request'),
	'a shell command' => array('zfs destroy -r backup', 'Not a replication request'),
	'destroy' => array('zfsm-replica 1 destroy '.$data, 'Not a replication request'),
	'rollback' => array('zfsm-replica 1 rollback '.$data, 'Not a replication request'),
	'another version' => array('zfsm-replica 2 hello', 'Not a replication request'),
	'a trailing newline' => array("zfsm-replica 1 hello\n", 'Not a replication request'),
	'a second command' => array('zfsm-replica 1 hello; rm -rf /', 'Not a replication request'),
	'a tab' => array("zfsm-replica 1\thello", 'Not a replication request'),
	'two spaces' => array('zfsm-replica 1  hello', 'Not a replication request'),
	'an extra word' => array('zfsm-replica 1 receive '.$data.' filesystem', 'Not a replication request'),
	'uppercase hex' => array('zfsm-replica 1 exists '.strtoupper($data), 'Not a replication request'),
	'a name longer than ZFS allows' => array($req('exists', 'backup/replicas/tower/'.str_repeat('x', 240)), 'Not a replication request'),
	'hello with a name' => array('zfsm-replica 1 hello '.$data, 'Not a replication request'),
	'receive with no name' => array('zfsm-replica 1 receive', 'Not a dataset name'),
	'odd-length hex' => array('zfsm-replica 1 exists '.substr($data, 0, -1), 'Not a dataset name'),
	'a snapshot' => array($req('exists', 'backup/replicas/tower/data@s'), 'Not a dataset name'),
	'a bookmark' => array($req('exists', 'backup/replicas/tower/data#b'), 'Not a dataset name'),
	'.. in the name' => array($req('receive', 'backup/replicas/tower/../other'), 'Not a dataset name'),
	'an empty part' => array($req('receive', 'backup/replicas/tower//data'), 'Not a dataset name'),
	'a quote' => array($req('receive', "backup/replicas/tower/a'b"), 'Not a dataset name'),
	'a newline in the name' => array($req('receive', "backup/replicas/tower/a\nb"), 'Not a dataset name'),
	'a semicolon in the name' => array($req('receive', 'backup/replicas/tower/a;b'), 'Not a dataset name'),
	'receive into the area itself' => array($req('receive', 'backup/replicas/tower'), 'backup/replicas/tower is not inside backup/replicas/tower'),
	'snapshots of the area' => array($req('snapshots', 'backup/replicas/tower'), 'backup/replicas/tower is not inside backup/replicas/tower'),
	'another sender\'s copy' => array($req('snapshots', 'backup/replicas/other/data'), 'backup/replicas/other/data is not inside backup/replicas/tower'),
	'the accepting dataset' => array($req('exists', 'backup/replicas'), 'backup/replicas is not inside backup/replicas/tower'),
	'a name that only starts the same' => array($req('receive', 'backup/replicas/towerx/data'), 'backup/replicas/towerx/data is not inside backup/replicas/tower'),
	'another pool' => array($req('token', 'tank/replicas/tower/data'), 'tank/replicas/tower/data is not inside backup/replicas/tower'),
);
foreach ($refused as $what => $case) same($case[1], $check($case[0]), 'refused: '.$what);
same('This server does not accept replicas from this key', $check($req('receive', 'backup/replicas/tower/data'), null), 'a key with no area is refused everything');

// Through the real run: hello makes the area unmounted, a stream goes straight into zfs receive, and a refusal runs nothing.
$work = sys_get_temp_dir().'/zfsm-serve-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/pool', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
putenv('ZFSM_TEST_REPL_DIR='.$work.'/pool');
putenv('ZFSM_TEST_CALL_LOG='.$work.'/calls.log');
file_put_contents($work.'/pool/datasets', "tank\tfilesystem\ntank/data\tfilesystem\nbackup\tfilesystem\nbackup/replicas\tfilesystem\n");
file_put_contents($work.'/pool/marks', "tank/data@a\t11\t10\n");
exec(escapeshellarg(zfsmCommandPath('zfs')).' send tank/data@a > '.escapeshellarg($work.'/stream').' 2>/dev/null');
$serve = function($request, $input = '/dev/null', $in = 'backup/replicas/tower') use ($work) {
	$io = array(fopen($input, 'r'), fopen($work.'/out', 'w'), fopen($work.'/err', 'w'));
	$code = zfsmServeReplica($request, $in, $io);
	foreach ($io as $handle) fclose($handle);
	return array($code, file_get_contents($work.'/out'), file_get_contents($work.'/err'));
};
$calls = function($verb) use ($work) { return array_values(array_filter(is_file($work.'/calls.log') ? file($work.'/calls.log', FILE_IGNORE_NEW_LINES) : array(), function($line) use ($verb) { return strpos($line, $verb.' ') === 0; })); };
same(array(1, '', "backup/replicas/tower doesn't exist yet\n"), $serve($req('exists', 'backup/replicas/tower')), 'before hello, the area isn\'t there');
same(array(0, "zfsm-replica 1\narea backup/replicas/tower\n", ''), $serve('zfsm-replica 1 hello'), 'hello answers with the area');
same(array('create -o mountpoint=none -o canmount=off backup/replicas/tower'), $calls('create'), 'having made it unmounted');
same(array(0, "zfsm-replica 1\narea backup/replicas/tower\n", ''), $serve('zfsm-replica 1 hello'), 'a second hello finds it ready');
same(1, count($calls('create')), 'and makes nothing more');
same(array(0, "backup/replicas/tower\tfilesystem\n", ''), $serve($req('exists', 'backup/replicas/tower')), 'exists answers with the name and type');
same(1, $serve($req('exists', 'backup/replicas/tower/data'))[0], 'and fails for a copy not there yet');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/tower/data'), $work.'/stream'), 'a stream is received');
same(array('receive -s -u -o readonly=on -x mountpoint -x sharenfs -x sharesmb backup/replicas/tower/data'), $calls('receive'), 'with the fixed receive');
same(array(0, "backup/replicas/tower/data@a\t11\t101\n", ''), $serve($req('snapshots', 'backup/replicas/tower/data')), 'snapshots lists what the copy now holds');
same(array(0, "-\n", ''), $serve($req('token', 'backup/replicas/tower/data')), 'token reads no stopped run');
@unlink($work.'/calls.log');
same(array(1, '', "backup/replicas/other/data is not inside backup/replicas/tower\n"), $serve($req('receive', 'backup/replicas/other/data'), $work.'/stream'), 'a refused receive says why');
same(array(), $calls('receive'), 'and runs no zfs receive');
same(false, strpos(file_get_contents($work.'/pool/datasets'), 'backup/replicas/other') !== false, 'so nothing appears outside the area');
same(array(1, '', "backup/replicas/new doesn't exist yet\n"), $serve($req('receive', 'backup/replicas/new/data'), $work.'/stream', 'backup/replicas/new'), 'a receive never makes the area: only hello does');
// Once the area could mount, nothing more goes in.
file_put_contents($work.'/pool/props', "backup/replicas/tower\tmountpoint\t/mnt/backup/replicas/tower\n", FILE_APPEND);
same(array(1, '', "backup/replicas/tower must keep mountpoint=none, so nothing replicated into it mounts\n"), $serve($req('receive', 'backup/replicas/tower/data2'), $work.'/stream'), 'a receive is refused once the area has a mountpoint');
same(1, $serve('zfsm-replica 1 hello')[0], 'and so is hello');

// The settings list each key that runs the checker, and whether restrict and from= hold it.
$checker = '/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php';
file_put_contents($work.'/authorized_keys', implode("\n", array(
	'# a comment',
	'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA admin@laptop',
	'restrict,from="10.0.20.11,100.85.194.118",command="'.$checker.' tower" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB zfs.master@tower',
	'from="10.0.20.12",command="'.$checker.' nas" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC zfs.master@nas',
	'restrict,command="'.$checker.'" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAID zfs.master@noname',
	'restrict,command="/usr/bin/rrsync /backup" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIE rsync@other',
)));
same(array(
	array('sender' => 'tower', 'comment' => 'zfs.master@tower', 'restrict' => true, 'from' => true),
	array('sender' => 'nas', 'comment' => 'zfs.master@nas', 'restrict' => false, 'from' => true),
	array('sender' => '', 'comment' => 'zfs.master@noname', 'restrict' => true, 'from' => false),
), zfsmReplicaKeys($work.'/authorized_keys'), 'only the checker\'s keys are listed, with what holds each one');
same(array(), zfsmReplicaKeys($work.'/missing'), 'no file, no keys');

// scripts/replica-serve.php drops the environment's test hooks and fixes PATH before it reads anything, so neither the fake zfs nor a
// zfs first on the caller's PATH runs; its area comes from its one argument and the accepting dataset from a file the Helpers fixture reads.
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/replica-serve.php')) {
	$script = $docroot.'/plugins/zfs.master/scripts/replica-serve.php';
	same(true, is_executable($script), 'the script can run as an authorized_keys command');
	mkdir($work.'/decoy');
	file_put_contents($work.'/decoy/zfs', "#!/bin/sh\ntouch ".escapeshellarg($work.'/decoy-ran')."\necho none\n");
	chmod($work.'/decoy/zfs', 0755);
	@unlink($work.'/calls.log');
	$accept_file = sys_get_temp_dir().'/zfsm-replica-accept';
	file_put_contents($accept_file, 'backup/replicas');
	$run = function($request, $args) use ($script, $work) {
		$env = array_merge(getenv(), array('SSH_ORIGINAL_COMMAND' => $request, 'PATH' => $work.'/decoy:'.getenv('PATH')));
		$process = proc_open(array_merge(array(PHP_BINARY, '-n', $script), $args), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array(proc_close($process), $out, $err);
	};
	$hello = $run('zfsm-replica 1 hello', array('tower'));
	same(array(false, array(), true), array(is_file($work.'/decoy-ran'), $calls('get'), $hello[0] !== 0), 'it runs neither a zfs first on the caller\'s PATH nor the test one: '.var_export($hello, true));
	same(array(1, '', "Not a replication request\n"), $run('zfs list', array('tower')), 'a plain zfs command is refused');
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array()), 'a key line naming no sender is refused');
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array('tower', 'extra')), 'and one naming more than one');
	unlink($accept_file);
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array('tower')), 'with no accepting dataset set, nothing is accepted');
}

// The host key a sender compares, as ssh-keygen -l prints it (a throwaway key made for this test).
file_put_contents($work.'/host.pub', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHwdIausNEPnAJgtomzVsSoe8NyNhe7L/745s7iIuKjF zfsm-test-host\n");
same('SHA256:sIZmz5UcVySQ1/8ZeQt0INFbwd5xsWkKLTFPrJenk3w', zfsmHostKeyFingerprint($work.'/host.pub'), 'the fingerprint matches ssh-keygen -l');
file_put_contents($work.'/rsa.pub', "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ zfsm-test-host\n");
same(null, zfsmHostKeyFingerprint($work.'/rsa.pub'), 'only an ed25519 key is read');
same(null, zfsmHostKeyFingerprint($work.'/missing.pub'), 'a missing key reads as unknown');

echo "replica serve tests passed\n";
