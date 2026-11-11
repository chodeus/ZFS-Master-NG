<?php
// Sending to a real sshd (tests/replica_sshd_test.sh): the key line Settings shows, ssh's options on real OpenSSH, sshd's forced command
// running the real checker on the fake zfs, one connection for a whole replication, and a server refusing the key tried once, not per question.

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

$work = getenv('ZFSM_TEST_WORK');
mkdir($work.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $work.'/docroot/plugins/dynamix/scripts/notify');
chmod($work.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $work.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$work.'/notify.log');
putenv('ZFSM_TEST_NOTIFY_DETAIL_LOG='.$work.'/notify-detail.log');
file_put_contents($work.'/pool/datasets', "tank\tfilesystem\ntank/data\tfilesystem\nbackup\tfilesystem\nbackup/replicas\tfilesystem\n");
file_put_contents($work.'/pool/marks', "tank/data@a\t11\t10\ntank/data@b\t12\t20\n");
file_put_contents($work.'/pool/props', '');
$cfg = array('snap_prefix' => 'repl-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
// sshd logs each TCP connection it accepts (LogLevel VERBOSE).
$connections = function() use ($work) { return preg_match_all('/Connection from 127\.0\.0\.1 port [0-9]+ on 127\.0\.0\.1 port 2222/', (string)@file_get_contents($work.'/sshd.log')); };

// Settings: this server's key, the real host key read and pinned, and the line to add there.
file_put_contents(getenv('ZFSM_TEST_IDENT_FILE'), "NAME=\"tower\"\n");
same(true, isset(zfsmReplicaKeyMake()['key']), 'this server makes its key');
$scan = zfsmReplicaScan('127.0.0.1', 2222);
$listed = preg_split('/\s+/', trim((string)shell_exec('ssh-keygen -l -E sha256 -f '.escapeshellarg($work.'/host_key.pub'))));
same($listed[1] ?? 'unread', $scan['fingerprint'] ?? json_encode($scan), 'the real ssh-keyscan reads the host key sshd serves');
same('', zfsmReplicaRemoteAdd('self', '127.0.0.1', 2222, $scan['key'], $scan['fingerprint']), 'it is trusted');
$line = (string)zfsmReplicaKeyLine(zfsmReplicaRemotes()['self'], zfsmReplicaSender());
same(1, preg_match('#^restrict,from="127\.0\.0\.1",command="/usr/local/emhttp/plugins/zfs\.master/scripts/replica-serve\.php tower" ssh-ed25519 \S+ zfs\.master@tower$#D', $line), 'the line Settings shows: '.$line);
file_put_contents($work.'/authorized_keys', $line."\n");

// Test, the dialog's plan, two runs and their streams: all through sshd's forced command, on one connection.
$before = $connections();
same(array('area' => 'backup/replicas/tower'), zfsmReplicaHello('self'), 'Test runs the real checker through sshd');
$there = 'backup/replicas/tower/data';
$preview = zfsmReplicatePreview('tank/data', 'a', '', $cfg, 'self');
same(array(true, 'full', $there), array($preview['can_proceed'], $preview['mode'] ?? $preview['error'], $preview['dest']), 'the dialog plans <its area>/data there');
same(0, zfsmRunReplicate('tank/data', 'a', $there, null, false, 'self'), 'a first copy');
same(0, zfsmRunReplicate('tank/data', 'b', $there, null, false, 'self'), 'and the next, incremental');
same(array($there.'@a', $there.'@b'), array_column(zfsmReplicaMarks($there, 'snapshot') ?? array(), 'name'), 'the copy there holds both');
same(1, $connections() - $before, 'one connection for all of it: every call rode the first');

// A server that refuses the key: each failed login counts against this server's address there, so the next questions wait.
$control = zfsmJobDir('replica-send').'/cm-'.zfsmReplicaRemoteId(zfsmReplicaRemotes()['self']);
exec('ssh -O exit -o ControlPath='.escapeshellarg($control).' 127.0.0.1 2>&1');
file_put_contents($work.'/authorized_keys', '');
$before = $connections();
$refused = zfsmReplicaHello('self')['error'] ?? '';
same(1, preg_match('/Permission denied \(publickey[,)]/', $refused), 'a key not in authorized_keys: '.$refused);
same(1, preg_match('/^Asking self again in [0-9]+ s/', zfsmReplicaHello('self')['error'] ?? ''), 'the next Test waits');
same(1, preg_match('/^Asking self again in [0-9]+ s/', zfsmReplicatePreview('tank/data', 'b', $there, $cfg, 'self')['error'] ?? ''), 'and so does the dialog');
same(1, $connections() - $before, 'one failed login, not one per question');

echo "replica sshd tests passed on ".trim((string)shell_exec('ssh -V 2>&1'))."\n";
