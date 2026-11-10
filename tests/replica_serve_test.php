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
same(array(true, false, false, false, false), array_map('zfsmReplicaAcceptValid', array('backup/replicas', '', 'backup', 'bad;name/x', 'backup/')), 'the Settings page warns about the same values');
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
same(array('receive', '-s', '-u', '-o', 'readonly=on', '-x', 'mountpoint', '-x', 'volmode', '-x', 'setuid', '-x', 'devices', '-x', 'exec', '-x', 'sharenfs', '-x', 'sharesmb', '-x', 'snapdev', 'backup/replicas/tower/my data'), $check($req('receive', 'backup/replicas/tower/my data')), 'receive takes a stream read-only, never -F, and the stream can\'t set what the area holds off');

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
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
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
same(array('create -o canmount=off -o mountpoint=none -o volmode=none -o setuid=off -o devices=off -o exec=off backup/replicas/tower'), $calls('create'), 'having made it never to mount, show as a block device or run anything');
same(array(0, "zfsm-replica 1\narea backup/replicas/tower\n", ''), $serve('zfsm-replica 1 hello'), 'a second hello finds it ready');
same(1, count($calls('create')), 'and makes nothing more');
// Two hellos at once: the one whose create loses still finds the area the other made.
$fake_zfs = zfsmCommandPath('zfs');
mkdir($work.'/racebin');
file_put_contents($work.'/racebin/zfs', "#!/bin/sh\nif [ \"\$1\" = create ]; then ".escapeshellarg($fake_zfs)." \"\$@\" >/dev/null 2>&1; fi\nexec ".escapeshellarg($fake_zfs)." \"\$@\"\n");
chmod($work.'/racebin/zfs', 0755);
putenv('ZFSM_TEST_BIN_DIR='.$work.'/racebin');
same(array(0, "zfsm-replica 1\narea backup/replicas/raced\n", ''), $serve('zfsm-replica 1 hello', '/dev/null', 'backup/replicas/raced'), 'a hello whose create loses to another one\'s still answers');
// And the other order: the other hello's create lands between this one's failed read and its check that the area is missing.
mkdir($work.'/racebin2');
file_put_contents($work.'/racebin2/zfs', "#!/bin/sh\nif [ \"\$1\" = get ] && [ ! -e ".escapeshellarg($work.'/raced')." ]; then touch ".escapeshellarg($work.'/raced')."; ".escapeshellarg($fake_zfs)." \"\$@\"; s=\$?; ".escapeshellarg($fake_zfs)." create -o canmount=off -o mountpoint=none -o volmode=none -o setuid=off -o devices=off -o exec=off backup/replicas/raced2 >/dev/null 2>&1; exit \$s; fi\nexec ".escapeshellarg($fake_zfs)." \"\$@\"\n");
chmod($work.'/racebin2/zfs', 0755);
putenv('ZFSM_TEST_BIN_DIR='.$work.'/racebin2');
same(array(0, "zfsm-replica 1\narea backup/replicas/raced2\n", ''), $serve('zfsm-replica 1 hello', '/dev/null', 'backup/replicas/raced2'), 'and one whose read misses an area made just after it');
putenv('ZFSM_TEST_BIN_DIR='.dirname($fake_zfs));
same(array(0, "backup/replicas/tower\tfilesystem\n", ''), $serve($req('exists', 'backup/replicas/tower')), 'exists answers with the name and type');
same(1, $serve($req('exists', 'backup/replicas/tower/data'))[0], 'and fails for a copy not there yet');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/tower/data'), $work.'/stream'), 'a stream is received');
same(array('receive -s -u -o readonly=on -x mountpoint -x volmode -x setuid -x devices -x exec -x sharenfs -x sharesmb -x snapdev backup/replicas/tower/data'), $calls('receive'), 'with the fixed receive');
same(array(0, "backup/replicas/tower/data@a\t11\t101\n", ''), $serve($req('snapshots', 'backup/replicas/tower/data')), 'snapshots lists what the copy now holds');
same(array(0, "-\n", ''), $serve($req('token', 'backup/replicas/tower/data')), 'token reads no stopped run');
@unlink($work.'/calls.log');
same(array(1, '', "backup/replicas/other/data is not inside backup/replicas/tower\n"), $serve($req('receive', 'backup/replicas/other/data'), $work.'/stream'), 'a refused receive says why');
same(array(), $calls('receive'), 'and runs no zfs receive');
same(false, strpos(file_get_contents($work.'/pool/datasets'), 'backup/replicas/other') !== false, 'so nothing appears outside the area');
same(array(1, '', "backup/replicas/new doesn't exist yet\n"), $serve($req('receive', 'backup/replicas/new/data'), $work.'/stream', 'backup/replicas/new'), 'a receive never makes the area: only hello does');
// Only a stream of one dataset goes in, read before zfs sees it: never a clone of another's snapshot, nor a -R or -p package.
$record = function($flags = 0, $version = 1, $objset = 2, $magic = 0x2F5BACBAC, $type = 0) { return pack('VVPPPVV', $type, 0, $magic, $version, 0, $objset, $flags).str_repeat("\0", 272); };
same(312, strlen($record()), 'a first record is 312 bytes');
same('', zfsmReplicaStreamCheck($record()), 'a filesystem\'s stream');
same('', zfsmReplicaStreamCheck($record(0xc, 0x11, 3)), 'a volume\'s, with the flags and feature bits real streams carry');
same('A clone\'s stream is not accepted', zfsmReplicaStreamCheck($record(0xd, 0x11)), 'a clone\'s stream, which would hold a snapshot outside the area as its origin');
same('A stream of several datasets (zfs send -R or -p) is not accepted', zfsmReplicaStreamCheck($record(0, 2)), 'a package of streams');
same('Not a send stream', zfsmReplicaStreamCheck(substr($record(), 0, 311)), 'a short one');
same('Not a send stream', zfsmReplicaStreamCheck($record(0, 1, 2, 0x2F5BACBAD)), 'a wrong magic');
same('Not a send stream', zfsmReplicaStreamCheck($record(0, 1, 2, 0x2F5BACBAC, 1)), 'a first record that isn\'t BEGIN');
same('Only a filesystem or volume stream is accepted', zfsmReplicaStreamCheck($record(0, 1, 1)), 'another kind of dataset');
file_put_contents($work.'/clone', $record(0xd, 0x11)."ZFSMSTREAM\ttank/data@a\t-\t11\t0\n");
@unlink($work.'/calls.log');
same(array(1, '', "A clone's stream is not accepted\n"), $serve($req('receive', 'backup/replicas/tower/evil'), $work.'/clone'), 'a clone\'s stream is refused');
same(array(), $calls('receive'), 'before zfs receive runs');
same(array(1, '', "Not a send stream\n"), $serve($req('receive', 'backup/replicas/tower/evil'), '/dev/null'), 'and so is no stream at all');
// At most four receives into an area run at once.
$slots = array();
for ($slot = 0; $slot < 4; $slot++) $slots[] = zfsmReplicaReceiveSlot('backup/replicas/tower');
same(4, count(array_filter($slots)), 'four slots');
same(array(1, '', "No receive into backup/replicas/tower can start while four are running\n"), $serve($req('receive', 'backup/replicas/tower/data5'), $work.'/stream'), 'a fifth receive is refused');
foreach ($slots as $held) zfsmJobUnlock($held);
same(true, zfsmReplicaReceiveSlot('backup/replicas/other') !== null, 'and another area has its own');
// Once the area could mount or run anything, nothing more goes in.
foreach (array('mountpoint' => '/mnt/backup/replicas/tower', 'exec' => 'on') as $property => $value) {
	file_put_contents($work.'/pool/props', "backup/replicas/tower\t$property\t$value\n", FILE_APPEND);
	same(array(1, '', "backup/replicas/tower must keep mountpoint=none, volmode=none, setuid=off, devices=off, exec=off, so nothing replicated into it mounts or runs\n"), $serve($req('receive', 'backup/replicas/tower/data2'), $work.'/stream'), 'a receive is refused once the area\'s '.$property.' changed');
	same(1, $serve('zfsm-replica 1 hello')[0], 'and so is hello');
	file_put_contents($work.'/pool/props', "backup/replicas/tower\t$property\t".zfsmReplicaAreaProps()[$property]."\n", FILE_APPEND);
}

// The settings list each key that runs the checker, and whether restrict and from= hold it.
$checker = '/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php';
file_put_contents($work.'/authorized_keys', implode("\n", array(
	'# a comment',
	'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA admin@laptop',
	'restrict,from="10.0.20.11,100.85.194.118",command="'.$checker.' tower" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB zfs.master@tower',
	'from="10.0.20.12",command="'.$checker.' nas" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC zfs.master@nas',
	'restrict,command="'.$checker.'" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAID zfs.master@noname',
	'restrict,command="/usr/bin/rrsync /backup" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIE rsync@other',
	'restrict,pty,port-forwarding,from="10.0.20.13",command="'.$checker.' reopened" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIF zfs.master@reopened',
	'restrict,from="*",command="'.$checker.' anywhere" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG zfs.master@anywhere',
	'restrict,from="!10.0.20.14",command="'.$checker.' negated" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH zfs.master@negated',
	'restrict,PTY,from="10.0.20.15",command="'.$checker.' upper" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAII zfs.master@upper',
	'restrict,Port-Forwarding,from="10.0.20.16",command="'.$checker.' upper2" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ zfs.master@upper2',
	'restrict,from="0.0.0.0/0,::/0",command="'.$checker.' range" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIK zfs.master@range',
	'restrict,from="10.0.20.17",command="/bin/sh -c id; '.$checker.' first" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL zfs.master@first',
	'restrict,from="10.0.20.18",command="/tmp/x/plugins/zfs.master/scripts/replica-serve.php copy" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIM zfs.master@copy',
	'restrict,from="10.0.20.19,fd7a:115c:a1e0::e501:c277",command="'.$checker.' six" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIN zfs.master@six',
)));
same(array(
	array('sender' => 'tower', 'comment' => 'zfs.master@tower', 'restrict' => true, 'from' => true),
	array('sender' => 'nas', 'comment' => 'zfs.master@nas', 'restrict' => false, 'from' => true),
	array('sender' => '', 'comment' => 'zfs.master@noname', 'restrict' => true, 'from' => false),
	array('sender' => 'reopened', 'comment' => 'zfs.master@reopened', 'restrict' => false, 'from' => true),
	array('sender' => 'anywhere', 'comment' => 'zfs.master@anywhere', 'restrict' => true, 'from' => false),
	array('sender' => 'negated', 'comment' => 'zfs.master@negated', 'restrict' => true, 'from' => false),
	array('sender' => 'upper', 'comment' => 'zfs.master@upper', 'restrict' => false, 'from' => true),
	array('sender' => 'upper2', 'comment' => 'zfs.master@upper2', 'restrict' => false, 'from' => true),
	array('sender' => 'range', 'comment' => 'zfs.master@range', 'restrict' => true, 'from' => false),
	array('sender' => '', 'comment' => 'zfs.master@first', 'restrict' => true, 'from' => true),
	array('sender' => '', 'comment' => 'zfs.master@copy', 'restrict' => true, 'from' => true),
	array('sender' => 'six', 'comment' => 'zfs.master@six', 'restrict' => true, 'from' => true),
), zfsmReplicaKeys($work.'/authorized_keys'), 'every key naming the checker is listed with what holds it: a limit turned back on after restrict (in any case), a from= that isn\'t exact IP addresses, or a command that isn\'t exactly the installed checker and a name, doesn\'t hold');
$listed = zfsmReplicaKeysHtml(array(
	array('sender' => 'tower', 'comment' => 'zfs.master@tower', 'restrict' => true, 'from' => true),
	array('sender' => 'odd', 'comment' => "zfs.master@\xff<b>", 'restrict' => false, 'from' => true),
));
same(true, strpos($listed, '<span style="display:block">tower (zfs.master@tower): limited to replication</span>') !== false, 'a held key reads as limited');
same(true, strpos($listed, '<span style="display:block;color:#e22828">odd (zfs.master@'."\u{FFFD}".'&lt;b&gt;): its line needs restrict') !== false, 'one that isn\'t shows in red, escaped, even when its comment isn\'t valid UTF-8: '.$listed);
same('None yet', zfsmReplicaKeysHtml(array()), 'no keys yet');
same(array(), zfsmReplicaKeys($work.'/missing'), 'no file, no keys');

// scripts/replica-serve.php keeps only the request and the client's address from the environment and fixes PATH before it reads anything,
// so the zfs it runs is the first on that PATH; the accepting dataset comes from a file the Helpers fixture reads.
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/replica-serve.php')) {
	$script = $docroot.'/plugins/zfs.master/scripts/replica-serve.php';
	same(true, is_executable($script), 'the script can run as an authorized_keys command');
	$fixed_path = '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin';
	$fixed_zfs = null;
	foreach (explode(':', $fixed_path) as $dir) if ($fixed_zfs === null && is_file($dir.'/zfs') && is_executable($dir.'/zfs')) $fixed_zfs = $dir.'/zfs';
	same(true, $fixed_zfs !== null && hash_file('sha256', $fixed_zfs) === hash_file('sha256', __DIR__.'/fixtures/fixed_path/zfs'), 'the fixed PATH finds tests/fixtures/fixed_path/zfs first (run_http_inventory_tests.sh puts it there), so no real zfs runs: '.var_export($fixed_zfs, true));
	$fixed_log = '/tmp/zfsm-fixed-path-zfs.log';
	@unlink($fixed_log);
	mkdir($work.'/decoy');
	file_put_contents($work.'/decoy/zfs', "#!/bin/sh\ntouch ".escapeshellarg($work.'/decoy-ran')."\necho none\n");
	chmod($work.'/decoy/zfs', 0755);
	@unlink($work.'/calls.log');
	// The environment the script's own commands would get, read as it exits.
	file_put_contents($work.'/env-dump.php', '<?php register_shutdown_function(function() { file_put_contents('.var_export($work.'/env.json', true).', json_encode(getenv())); });');
	$accept_file = $docroot.'/zfsm-replica-accept';
	file_put_contents($accept_file, 'zfsm-no-such-pool/replicas');
	$run = function($request, $args) use ($script, $work) {
		$env = array_merge(getenv(), array('SSH_ORIGINAL_COMMAND' => $request, 'SSH_CLIENT' => '192.0.2.7 50000 22', 'LANG' => 'C.UTF-8', 'ZFS_COLOR' => '1', 'PATH' => $work.'/decoy:'.getenv('PATH')));
		$process = proc_open(array_merge(array(PHP_BINARY, '-n', '-d', 'auto_prepend_file='.$work.'/env-dump.php', $script), $args), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array(proc_close($process), $out, $err);
	};
	$hello = $run('zfsm-replica 1 hello', array('tower'));
	$fixed_calls = is_file($fixed_log) ? file($fixed_log, FILE_IGNORE_NEW_LINES) : array();
	same(array('get', false, array(), true), array(strtok($fixed_calls[0] ?? '', ' '), is_file($work.'/decoy-ran'), $calls('get'), $hello[0] !== 0), 'it runs the zfs the fixed PATH finds, not one first on the caller\'s PATH nor the test one: '.var_export(array($hello, $fixed_calls), true));
	$kept = json_decode((string)@file_get_contents($work.'/env.json'), true) ?: array();
	ksort($kept);
	same(array('PATH' => $fixed_path, 'SSH_CLIENT' => '192.0.2.7 50000 22', 'SSH_ORIGINAL_COMMAND' => 'zfsm-replica 1 hello'), $kept, 'and its commands get only the request, the client\'s address and the fixed PATH');
	same(array(1, '', "Not a replication request\n"), $run('zfs list', array('tower')), 'a plain zfs command is refused');
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array()), 'a key line naming no sender is refused');
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array('tower', 'extra')), 'and one naming more than one');
	unlink($accept_file);
	same(array(1, '', "This server does not accept replicas from this key\n"), $run('zfsm-replica 1 hello', array('tower')), 'with no accepting dataset set, nothing is accepted');
} else {
	fwrite(STDERR, "FAILED: the entry-point checks need ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}

// The host key a sender compares, as ssh-keygen -l prints it (a throwaway key made for this test).
file_put_contents($work.'/host.pub', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHwdIausNEPnAJgtomzVsSoe8NyNhe7L/745s7iIuKjF zfsm-test-host\n");
same('SHA256:sIZmz5UcVySQ1/8ZeQt0INFbwd5xsWkKLTFPrJenk3w', zfsmHostKeyFingerprint($work.'/host.pub'), 'the fingerprint matches ssh-keygen -l');
file_put_contents($work.'/rsa.pub', "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ zfsm-test-host\n");
same(null, zfsmHostKeyFingerprint($work.'/rsa.pub'), 'only an ed25519 key is read');
same(null, zfsmHostKeyFingerprint($work.'/missing.pub'), 'a missing key reads as unknown');

echo "replica serve tests passed\n";
