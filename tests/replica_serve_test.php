<?php
// The receiving side of a replication from another server: which requests a sender's key may run (include/ZFSMReplicaServe.php),
// a stream through the real proc_open into the fake zfs's small pool (ZFSM_TEST_REPL_DIR), and scripts/replica-serve.php's environment.

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

$accept = 'backup/replicas';
$req = function($verb, $name = null, $type = null) { return 'zfsm-replica 1 '.$verb.($name === null ? '' : ' '.bin2hex($name)).($type === null ? '' : ' '.$type); };
$check = function($request, $into = 'backup/replicas') { $checked = zfsmReplicaServeCheck($request, $into); return $checked['error'] ?? $checked['reply'] ?? $checked['args']; };

// Each request runs one fixed zfs command.
same("zfsm-replica 1\naccept backup/replicas\n", $check('zfsm-replica 1 hello'), 'hello names the accepting dataset');
same(array('list', '-H', '-o', 'name,type', 'backup/replicas'), $check($req('exists', 'backup/replicas')), 'exists may ask about the accepting dataset, the parent of a first copy');
same(array('list', '-H', '-o', 'name,type', 'backup/replicas/data'), $check($req('exists', 'backup/replicas/data')), 'and about a copy inside it');
same(array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,createtxg', '-s', 'createtxg', 'backup/replicas/data'), $check($req('snapshots', 'backup/replicas/data')), 'snapshots lists a copy\'s snapshots');
same(array('get', '-H', '-p', '-o', 'value', 'receive_resume_token', 'backup/replicas/data'), $check($req('token', 'backup/replicas/data')), 'token reads a copy\'s resume token');
same(array('receive', '-s', '-u', '-o', 'readonly=on', '-o', 'canmount=noauto', '-x', 'mountpoint', '-x', 'sharenfs', '-x', 'sharesmb', 'backup/replicas/my data'), $check($req('receive', 'backup/replicas/my data', 'filesystem')), 'receive takes a filesystem unmounted and read-only, never -F, ignoring the stream\'s mountpoint and shares');
same(array('receive', '-s', '-u', '-o', 'readonly=on', 'backup/replicas/vol'), $check($req('receive', 'backup/replicas/vol', 'volume')), 'and a volume read-only');

// Everything else is refused.
$data = bin2hex('backup/replicas/data');
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
	'uppercase hex' => array('zfsm-replica 1 exists '.strtoupper($data), 'Not a replication request'),
	'a name longer than ZFS allows' => array($req('exists', 'backup/replicas/'.str_repeat('x', 240)), 'Not a replication request'),
	'hello with a name' => array('zfsm-replica 1 hello '.$data, 'Not a replication request'),
	'exists with a type' => array($req('exists', 'backup/replicas/data', 'filesystem'), 'Not a replication request'),
	'receive without a type' => array($req('receive', 'backup/replicas/data'), 'Not a replication request'),
	'a type with no name' => array('zfsm-replica 1 receive filesystem', 'Not a dataset name'),
	'odd-length hex' => array('zfsm-replica 1 exists '.substr($data, 0, -1), 'Not a dataset name'),
	'a snapshot' => array($req('exists', 'backup/replicas/data@s'), 'Not a dataset name'),
	'a bookmark' => array($req('exists', 'backup/replicas/data#b'), 'Not a dataset name'),
	'.. in the name' => array($req('receive', 'backup/replicas/../other', 'filesystem'), 'Not a dataset name'),
	'an empty part' => array($req('receive', 'backup/replicas//data', 'filesystem'), 'Not a dataset name'),
	'a quote' => array($req('receive', "backup/replicas/a'b", 'filesystem'), 'Not a dataset name'),
	'a newline in the name' => array($req('receive', "backup/replicas/a\nb", 'filesystem'), 'Not a dataset name'),
	'a semicolon in the name' => array($req('receive', 'backup/replicas/a;b', 'filesystem'), 'Not a dataset name'),
	'receive into the accepting dataset itself' => array($req('receive', 'backup/replicas', 'filesystem'), 'backup/replicas is not inside backup/replicas'),
	'snapshots of the accepting dataset' => array($req('snapshots', 'backup/replicas'), 'backup/replicas is not inside backup/replicas'),
	'a dataset beside it' => array($req('receive', 'backup/other', 'filesystem'), 'backup/other is not inside backup/replicas'),
	'its parent' => array($req('exists', 'backup'), 'backup is not inside backup/replicas'),
	'a name that only starts the same' => array($req('receive', 'backup/replicasx/data', 'filesystem'), 'backup/replicasx/data is not inside backup/replicas'),
	'another pool' => array($req('token', 'tank/replicas/data'), 'tank/replicas/data is not inside backup/replicas'),
);
foreach ($refused as $what => $case) same($case[1], $check($case[0]), 'refused: '.$what);
foreach (array('', 'backup/replicas/', 'bad;name', 'backup/../x') as $into) {
	same('This server does not accept replicas', $check($req('receive', 'backup/replicas/data', 'filesystem'), $into), 'nothing is accepted into '.var_export($into, true));
}

// Through the real run: the request's stream goes straight into zfs receive, and a refused one runs no zfs at all.
$work = sys_get_temp_dir().'/zfsm-serve-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/pool', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
putenv('ZFSM_TEST_REPL_DIR='.$work.'/pool');
putenv('ZFSM_TEST_CALL_LOG='.$work.'/calls.log');
file_put_contents($work.'/pool/datasets', "tank\tfilesystem\ntank/data\tfilesystem\nbackup\tfilesystem\nbackup/replicas\tfilesystem\n");
file_put_contents($work.'/pool/marks', "tank/data@a\t11\t10\n");
$zfs = zfsmCommandPath('zfs');
exec(escapeshellarg($zfs).' send tank/data@a > '.escapeshellarg($work.'/stream').' 2>/dev/null');
$serve = function($request, $input = '/dev/null') use ($work, $accept) {
	$io = array(fopen($input, 'r'), fopen($work.'/out', 'w'), fopen($work.'/err', 'w'));
	$code = zfsmServeReplica($request, $accept, $io);
	foreach ($io as $handle) fclose($handle);
	return array($code, file_get_contents($work.'/out'), file_get_contents($work.'/err'));
};
$calls = function($verb) use ($work) { return array_values(array_filter(is_file($work.'/calls.log') ? file($work.'/calls.log', FILE_IGNORE_NEW_LINES) : array(), function($line) use ($verb) { return strpos($line, $verb.' ') === 0; })); };
same(array(0, "backup/replicas\tfilesystem\n", ''), $serve($req('exists', 'backup/replicas')), 'exists answers with the name and type');
same(1, $serve($req('exists', 'backup/replicas/data'))[0], 'and fails for a copy not there yet');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/data', 'filesystem'), $work.'/stream'), 'a stream is received');
same(array('receive -s -u -o readonly=on -o canmount=noauto -x mountpoint -x sharenfs -x sharesmb backup/replicas/data'), $calls('receive'), 'with the fixed receive');
same(array(0, "backup/replicas/data@a\t11\t101\n", ''), $serve($req('snapshots', 'backup/replicas/data')), 'snapshots lists what the copy now holds');
same(array(0, "-\n", ''), $serve($req('token', 'backup/replicas/data')), 'token reads no stopped run');
@unlink($work.'/calls.log');
same(array(1, '', "backup/other is not inside backup/replicas\n"), $serve($req('receive', 'backup/other', 'filesystem'), $work.'/stream'), 'a refused receive says why');
same(array(), $calls('receive'), 'and runs no zfs');
same(false, strpos(file_get_contents($work.'/pool/datasets'), 'backup/other') !== false, 'so nothing appears outside the accepting dataset');

// scripts/replica-serve.php drops the environment's test hooks and fixes PATH before it reads anything, so the fake zfs and a zfs
// first on the caller's PATH are both out of reach. The accepting dataset comes from a file the Helpers fixture reads.
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/replica-serve.php')) {
	mkdir($work.'/decoy');
	file_put_contents($work.'/decoy/zfs', "#!/bin/sh\necho DECOY\n");
	chmod($work.'/decoy/zfs', 0755);
	$accept_file = sys_get_temp_dir().'/zfsm-replica-accept';
	file_put_contents($accept_file, $accept);
	$run = function($request) use ($docroot, $work) {
		$env = array_merge(getenv(), array('SSH_ORIGINAL_COMMAND' => $request, 'PATH' => $work.'/decoy:'.getenv('PATH')));
		$process = proc_open(array(PHP_BINARY, '-n', $docroot.'/plugins/zfs.master/scripts/replica-serve.php'), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array(proc_close($process), $out, $err);
	};
	same(array(0, "zfsm-replica 1\naccept backup/replicas\n", ''), $run('zfsm-replica 1 hello'), 'the script answers hello with the accepting dataset from its settings');
	$exists = $run($req('exists', 'backup/replicas'));
	same(array(false, false, true), array(strpos($exists[1].$exists[2], 'DECOY') !== false, strpos($exists[1], "backup/replicas\tfilesystem") !== false, $exists[0] !== 0), 'and runs neither a zfs first on the caller\'s PATH nor the test one: '.var_export($exists, true));
	same(array(1, '', "Not a replication request\n"), $run('zfs list'), 'a plain zfs command is refused');
	unlink($accept_file);
	same(array(1, '', "This server does not accept replicas\n"), $run('zfsm-replica 1 hello'), 'with no accepting dataset set, nothing is accepted');
}

// The host key a sender compares, as ssh-keygen -l prints it (a throwaway key made for this test).
file_put_contents($work.'/host.pub', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHwdIausNEPnAJgtomzVsSoe8NyNhe7L/745s7iIuKjF zfsm-test-host\n");
same('SHA256:sIZmz5UcVySQ1/8ZeQt0INFbwd5xsWkKLTFPrJenk3w', zfsmHostKeyFingerprint($work.'/host.pub'), 'the fingerprint matches ssh-keygen -l');
file_put_contents($work.'/rsa.pub', "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ zfsm-test-host\n");
same(null, zfsmHostKeyFingerprint($work.'/rsa.pub'), 'only an ed25519 key is read');
same(null, zfsmHostKeyFingerprint($work.'/missing.pub'), 'a missing key reads as unknown');

echo "replica serve tests passed\n";
