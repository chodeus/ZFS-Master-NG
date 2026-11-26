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
same(array('create', '-o', 'canmount=off', '-o', 'readonly=on', '-o', 'zfs.master:holder=on', 'backup/replicas/tower/backup'), $check($req('holder', 'backup/replicas/tower/backup')), 'holder makes one empty dataset, unmounted and read-only, to hold copies');
same(array(array('get', '-H', '-p', '-o', 'property,value,source', 'encryption,keystatus,zfs.master:raw', 'backup/replicas/tower'), array('get', '-H', '-p', '-o', 'property,value,source', 'encryption,keystatus,zfs.master:raw', 'backup/replicas/tower/data')),
	array($check($req('crypt', 'backup/replicas/tower')), $check($req('crypt', 'backup/replicas/tower/data'))), 'crypt reads the encryption and the mark of the area, which a new copy takes, or of a copy');
same(array('receive', '-s', '-u', '-o', 'readonly=on', '-x', 'mountpoint', '-x', 'volmode', '-x', 'setuid', '-x', 'devices', '-x', 'exec', '-x', 'sharenfs', '-x', 'sharesmb', '-x', 'snapdev', 'backup/replicas/tower/my data'), $check($req('receive', 'backup/replicas/tower/my data')), 'receive takes a stream read-only, never -F, and the stream can\'t set what the area holds off');
same(array('receive', '-s', '-u', '-o', 'readonly=on', '-x', 'mountpoint', '-x', 'volmode', '-x', 'setuid', '-x', 'devices', '-x', 'exec', '-x', 'sharenfs', '-x', 'sharesmb', '-x', 'snapdev', 'backup/replicas/tower/z'), $check($req('receive-zstd', 'backup/replicas/tower/z')), 'receive-zstd runs the same receive, behind zstd -d');

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
	'receive-zstd with no name' => array('zfsm-replica 1 receive-zstd', 'Not a dataset name'),
	'another compression' => array('zfsm-replica 1 receive-gzip '.$data, 'Not a replication request'),
	'receive-zstd into the area itself' => array($req('receive-zstd', 'backup/replicas/tower'), 'backup/replicas/tower is not inside backup/replicas/tower'),
	'odd-length hex' => array('zfsm-replica 1 exists '.substr($data, 0, -1), 'Not a dataset name'),
	'a snapshot' => array($req('exists', 'backup/replicas/tower/data@s'), 'Not a dataset name'),
	'a bookmark' => array($req('exists', 'backup/replicas/tower/data#b'), 'Not a dataset name'),
	'.. in the name' => array($req('receive', 'backup/replicas/tower/../other'), 'Not a dataset name'),
	'an empty part' => array($req('receive', 'backup/replicas/tower//data'), 'Not a dataset name'),
	'a quote' => array($req('receive', "backup/replicas/tower/a'b"), 'Not a dataset name'),
	'a newline in the name' => array($req('receive', "backup/replicas/tower/a\nb"), 'Not a dataset name'),
	'a semicolon in the name' => array($req('receive', 'backup/replicas/tower/a;b'), 'Not a dataset name'),
	'receive into the area itself' => array($req('receive', 'backup/replicas/tower'), 'backup/replicas/tower is not inside backup/replicas/tower'),
	'holder with no name' => array('zfsm-replica 1 holder', 'Not a dataset name'),
	'holder of the area itself' => array($req('holder', 'backup/replicas/tower'), 'backup/replicas/tower is not inside backup/replicas/tower'),
	'holder in another sender\'s area' => array($req('holder', 'backup/replicas/other/backup'), 'backup/replicas/other/backup is not inside backup/replicas/tower'),
	'crypt with no name' => array('zfsm-replica 1 crypt', 'Not a dataset name'),
	'crypt of the accepting dataset' => array($req('crypt', 'backup/replicas'), 'backup/replicas is not inside backup/replicas/tower'),
	'crypt in another sender\'s area' => array($req('crypt', 'backup/replicas/other/data'), 'backup/replicas/other/data is not inside backup/replicas/tower'),
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
$serve = function($request, $input = '/dev/null', $in = 'backup/replicas/tower', $keep = 0) use ($work) {
	$io = array(fopen($input, 'r'), fopen($work.'/out', 'w'), fopen($work.'/err', 'w'));
	$code = zfsmServeReplica($request, $in, $io, $keep);
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
same(array(0, "backup/replicas/tower\tfilesystem\nholder\ncrypt\n", ''), $serve($req('exists', 'backup/replicas/tower')), 'exists of the area answers with its name and type, and that this server makes holders and reads encryption');
same(array(0, "encryption\toff\t-\nkeystatus\t-\t-\nzfs.master:raw\t-\t-\n", ''), $serve($req('crypt', 'backup/replicas/tower')), 'crypt answers with zfs\'s own lines');
same(1, $serve($req('exists', 'backup/replicas/tower/data'))[0], 'and fails for a copy not there yet');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/tower/data'), $work.'/stream'), 'a stream is received');
same(array('receive -s -u -o readonly=on -x mountpoint -x volmode -x setuid -x devices -x exec -x sharenfs -x sharesmb -x snapdev -o zfs.master:raw=off backup/replicas/tower/data'), $calls('receive'), 'with the fixed receive, marked: its stream isn\'t raw');
same(array(0, "encryption\toff\t-\nkeystatus\t-\t-\nzfs.master:raw\toff\tlocal\n", ''), $serve($req('crypt', 'backup/replicas/tower/data')), 'crypt reads the mark on the copy');
same(array(0, "backup/replicas/tower/data@a\t11\t101\n", ''), $serve($req('snapshots', 'backup/replicas/tower/data')), 'snapshots lists what the copy now holds');
same(array(0, "-\n", ''), $serve($req('token', 'backup/replicas/tower/data')), 'token reads no stopped run');
@unlink($work.'/calls.log');
same(array(0, '', ''), $serve($req('holder', 'backup/replicas/tower/backup')), 'holder makes a dataset inside the area');
same(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/backup'), $calls('create'), 'with the fixed create');
same(array(0, "backup/replicas/tower/backup\tfilesystem\n", ''), $serve($req('exists', 'backup/replicas/tower/backup')), 'exists of it says nothing more');
same(array(1, '', "cannot create 'backup/replicas/tower/backup': dataset already exists\n"), $serve($req('holder', 'backup/replicas/tower/backup')), 'one already there is not made again');
same(array(1, '', "cannot create 'backup/replicas/tower/x/y': parent does not exist\n"), $serve($req('holder', 'backup/replicas/tower/x/y')), 'nor one whose parent is missing: one level at a time');
same(array(0, '', ''), $serve($req('holder', 'backup/replicas/tower/backup/tank')), 'a holder goes inside a holder');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/tower/backup/tank/data'), $work.'/stream'), 'and a stream is received inside it');
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
// The stream decides the mark, never the request: a raw one's copy has none.
file_put_contents($work.'/raw', $record(0xc, 0x11 | 1 << 26)."ZFSMSTREAM\ttank/data@a\t-\t11\t1\nZFSMEND\n");
@unlink($work.'/calls.log');
same(array(0, '', ''), $serve($req('receive', 'backup/replicas/tower/raw'), $work.'/raw'), 'a raw stream is received');
same(array('receive -s -u -o readonly=on -x mountpoint -x volmode -x setuid -x devices -x exec -x sharenfs -x sharesmb -x snapdev backup/replicas/tower/raw'), $calls('receive'), 'unmarked');
file_put_contents($work.'/clone', $record(0xd, 0x11)."ZFSMSTREAM\ttank/data@a\t-\t11\t0\n");
@unlink($work.'/calls.log');
same(array(1, '', "A clone's stream is not accepted\n"), $serve($req('receive', 'backup/replicas/tower/evil'), $work.'/clone'), 'a clone\'s stream is refused');
same(array(), $calls('receive'), 'before zfs receive runs');
same(array(1, '', "Not a send stream\n"), $serve($req('receive', 'backup/replicas/tower/evil'), '/dev/null'), 'and so is no stream at all');
// At most four receives into an area run at once.
$slots = array();
for ($slot = 0; $slot < 4; $slot++) $slots[] = zfsmReplicaReceiveSlot('backup/replicas/tower');
same(4, count(array_filter($slots, 'is_resource')), 'four slots');
$guid = getDatasetProperty('backup', 'backup/replicas/tower', 'guid');
same(array($guid.'.0.lock', $guid.'.1.lock', $guid.'.2.lock', $guid.'.3.lock'), array_map('basename', glob(zfsmJobDir('replica-serve').'/*.lock')), 'each named by the area\'s guid, which names nothing');
same(array(1, '', "No receive into backup/replicas/tower can start while four are running\n"), $serve($req('receive', 'backup/replicas/tower/data5'), $work.'/stream'), 'a fifth receive is refused');
foreach ($slots as $held) zfsmJobUnlock($held);
same('No receive into backup/replicas/other can start: its guid could not be read', zfsmReplicaReceiveSlot('backup/replicas/other'), 'an area whose guid can\'t be read has none');
file_put_contents($work.'/pool/datasets', "backup/replicas/other\tfilesystem\n", FILE_APPEND);
same(true, is_resource(zfsmReplicaReceiveSlot('backup/replicas/other')), 'and another area has its own');
// Once the area could mount or run anything, nothing more goes in.
foreach (array('mountpoint' => '/mnt/backup/replicas/tower', 'exec' => 'on') as $property => $value) {
	file_put_contents($work.'/pool/props', "backup/replicas/tower\t$property\t$value\n", FILE_APPEND);
	same(array(1, '', "backup/replicas/tower must keep mountpoint=none, volmode=none, setuid=off, devices=off, exec=off, so nothing replicated into it mounts or runs\n"), $serve($req('receive', 'backup/replicas/tower/data2'), $work.'/stream'), 'a receive is refused once the area\'s '.$property.' changed');
	same(1, $serve('zfsm-replica 1 hello')[0], 'and so is hello');
	file_put_contents($work.'/pool/props', "backup/replicas/tower\t$property\t".zfsmReplicaAreaProps()[$property]."\n", FILE_APPEND);
}

// A sender that has zstd asks exists of the area itself: the answer adds zstd once this server reads it too, and never for a copy.
// receive-zstd reads the stream through the real zstd -d under the real prlimit, its first record checked as receive's is.
$zstd = trim((string)shell_exec('command -v zstd'));
$prlimit = trim((string)shell_exec('command -v prlimit'));
same(array(true, true), array($zstd !== '', $prlimit !== ''), 'zstd and prlimit are installed, as tests/ci_php.sh and util-linux have them');
mkdir($work.'/zbin');
foreach (array('zfs', 'zpool') as $fake) copy(dirname($fake_zfs).'/'.$fake, $work.'/zbin/'.$fake) && chmod($work.'/zbin/'.$fake, 0755);
symlink($zstd, $work.'/zbin/zstd');
foreach (array('stream', 'clone') as $name) exec(escapeshellarg($zstd).' -q -c < '.escapeshellarg($work.'/'.$name).' > '.escapeshellarg($work.'/'.$name.'.zst'));
@unlink($work.'/calls.log');
same(array(1, '', "This server needs zstd and prlimit to read a compressed stream\n"), $serve($req('receive-zstd', 'backup/replicas/tower/z'), $work.'/stream.zst'), 'without zstd here, receive-zstd is refused');
putenv('ZFSM_TEST_BIN_DIR='.$work.'/zbin');
same(array(0, "backup/replicas/tower\tfilesystem\nholder\ncrypt\n", ''), $serve($req('exists', 'backup/replicas/tower')), 'with zstd but no prlimit to cap it, exists of the area says nothing of zstd');
same(array(1, '', "This server needs zstd and prlimit to read a compressed stream\n"), $serve($req('receive-zstd', 'backup/replicas/tower/z'), $work.'/stream.zst'), 'and receive-zstd is refused');
symlink($prlimit, $work.'/zbin/prlimit');
same(array(0, "backup/replicas/tower\tfilesystem\nzstd\nholder\ncrypt\n", ''), $serve($req('exists', 'backup/replicas/tower')), 'with it, exists of the area says so');
same(array(0, "backup/replicas/tower/data\tfilesystem\n", ''), $serve($req('exists', 'backup/replicas/tower/data')), 'and exists of a copy says nothing more');
same(array(1, '', "A clone's stream is not accepted\n"), $serve($req('receive-zstd', 'backup/replicas/tower/evil'), $work.'/clone.zst'), 'a clone\'s stream is refused compressed too');
$plain = $serve($req('receive-zstd', 'backup/replicas/tower/evil'), $work.'/stream');
same(array(1, 1), array($plain[0], preg_match('/\Azstd: .*unsupported format *\nNot a send stream\n\z/', $plain[2])), 'and a stream that isn\'t compressed, with zstd\'s own reason first: '.$plain[2]);
// zstd -d reads lzma too: one whose header claims a 1.5 GiB dictionary gets no more than the cap.
exec('xz --format=lzma -c < '.escapeshellarg($work.'/stream').' > '.escapeshellarg($work.'/stream.lzma'));
$lzma = file_get_contents($work.'/stream.lzma');
file_put_contents($work.'/stream.lzma', $lzma[0].pack('V', 0x60000000).substr($lzma, 5));
$big = $serve($req('receive-zstd', 'backup/replicas/tower/z'), $work.'/stream.lzma');
same(array(1, 1), array($big[0], preg_match('/\Azstd: .*lzma.*\nNot a send stream\n\z/', $big[2])), 'and an lzma stream claiming 1.5 GiB stops at the cap: '.$big[2]);
same(array(), $calls('receive'), 'none of them reaching zfs receive');
same(array(0, '', ''), $serve($req('receive-zstd', 'backup/replicas/tower/z'), $work.'/stream.zst'), 'a compressed stream is received');
same(array('receive -s -u -o readonly=on -x mountpoint -x volmode -x setuid -x devices -x exec -x sharenfs -x sharesmb -x snapdev -o zfs.master:raw=off backup/replicas/tower/z'), $calls('receive'), 'by the fixed receive, marked as the stream read through zstd says');
same(1, preg_match('/\Abackup\/replicas\/tower\/z@a\t11\t[0-9]+\n\z/', $serve($req('snapshots', 'backup/replicas/tower/z'))[1]), 'and the copy holds a, with the guid tank/data@a sent');
// A refusal while the sender still sends: zstd -d, blocked writing what nothing reads now, stops without a word of its own.
file_put_contents($work.'/clone-big', file_get_contents($work.'/clone').random_bytes(1 << 20));
exec(escapeshellarg($zstd).' -q -c < '.escapeshellarg($work.'/clone-big').' > '.escapeshellarg($work.'/clone-big.zst'));
$feeder = proc_open(array('sh', '-c', 'cat "$0" 2>/dev/null; exec sleep 20', $work.'/clone-big.zst'), array(1 => array('pipe', 'w')), $feed);
$io = array($feed[1], fopen($work.'/out', 'w'), fopen($work.'/err', 'w'));
$code = zfsmServeReplica($req('receive-zstd', 'backup/replicas/tower/evil'), 'backup/replicas/tower', $io);
proc_terminate($feeder);
foreach ($io as $handle) fclose($handle);
proc_close($feeder);
same(array(1, "A clone's stream is not accepted\n"), array($code, file_get_contents($work.'/err')), 'a refusal while the stream goes on says only why');
putenv('ZFSM_TEST_BIN_DIR='.dirname($fake_zfs));
// A run started from a page has no PATH (php-fpm's clear_env): zstd is still found where execvp looks.
$path = getenv('PATH');
putenv('ZFSM_TEST_BIN_DIR');
putenv('PATH');
$found = zfsmToolPath('zstd');
putenv('PATH='.$path);
putenv('ZFSM_TEST_BIN_DIR='.dirname($fake_zfs));
same(realpath($zstd), is_string($found) ? realpath($found) : $found, 'zstd is found with no PATH');

// Keep Replicas: once a receive lands, only the copy's newest $keep snapshots stay, whichever run sent each; held or cloned ones stay.
mkdir($work.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $work.'/docroot/plugins/dynamix/scripts/notify');
chmod($work.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $work.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$work.'/notify.log');
putenv('ZFSM_TEST_NOTIFY_DETAIL_LOG='.$work.'/notify-detail.log');
putenv('SSH_CLIENT=192.0.2.7 50000 22');
touch($work.'/pool/holds');
file_put_contents($work.'/pool/datasets', "tank/kept\tfilesystem\n", FILE_APPEND);
$copy = 'backup/replicas/tower/kept';
$prev = null;
$land = function($snapshot, $keep, $taken = null) use ($serve, $req, $work, $copy, &$prev) {
	foreach (array('notify-detail.log', 'notify.log') as $name) @unlink($work.'/'.$name);
	@unlink(zfsmLogFile());
	runProcess('zfs', array('snapshot', 'tank/kept@'.$snapshot));
	if ($taken !== null) file_put_contents($work.'/pool/props', "tank/kept@$snapshot\tcreation\t$taken\n", FILE_APPEND);
	exec(escapeshellarg(zfsmCommandPath('zfs')).' send '.($prev === null ? '' : '-i tank/kept@'.$prev.' ').'tank/kept@'.$snapshot.' > '.escapeshellarg($work.'/kept').' 2>/dev/null');
	$answer = $serve($req('receive', $copy), $work.'/kept', 'backup/replicas/tower', $keep);
	if ($answer[0] === 0) $prev = $snapshot;
	return $answer;
};
$on = function() use ($copy) { return array_map(function($snapshot) { return explode('@', $snapshot['name'])[1]; }, zfsmKeepSnapshots($copy)); };
$said = function() use ($work) {
	$removed = preg_grep('/Removed |Keep Replicas/', file(zfsmLogFile(), FILE_IGNORE_NEW_LINES) ?: array());
	return array(array_values(array_map(function($line) { return preg_replace('/^\S+ \S+ (\S+) \[[0-9]+\] /', '$1: ', $line); }, $removed)), is_file($work.'/notify-detail.log') ? file($work.'/notify-detail.log', FILE_IGNORE_NEW_LINES) : array());
};
foreach (array('a', 'b', 'c') as $snapshot) same(array(0, '', ''), $land($snapshot, 0), 'a receive with Keep Replicas empty lands');
same(array(array('a', 'b', 'c'), array(array(), array())), array($on(), $said()), 'and keeps all');
same(array(0, '', ''), $land('d', 2), 'one with Keep Replicas 2 answers as before');
same(array('c', 'd'), $on(), 'and the copy keeps its newest 2');
same(array(array('notice: Removed backup/replicas/tower/kept@a (not among the newest 2 Keep Replicas keeps)', 'notice: Removed backup/replicas/tower/kept@b (not among the newest 2 Keep Replicas keeps)'), array()), $said(), 'each removal logged, with no notification');
file_put_contents($work.'/pool/holds', "$copy@c\tmine\n");
same(array(0, '', ''), $land('e', 1), 'with Keep Replicas 1');
same(array(array('c', 'e'), array(array('notice: Removed '.$copy.'@d (not among the newest 1 Keep Replicas keeps)'), array())), array($on(), $said()), 'one held here stays, with no warning');
file_put_contents($work.'/pool/props', "$copy@e\tclones\tbackup/clone\n", FILE_APPEND);
$land('f', 1);
same(array(array('c', 'e', 'f'), array(array(), array())), array($on(), $said()), 'and one cloned here');
file_put_contents($work.'/pool/holds', '');
putenv('ZFSM_TEST_DESTROY_FAIL='.$copy.'@c');
same(array(0, '', ''), $land('g', 2), 'a removal that fails leaves the receive\'s answer as it was');
putenv('ZFSM_TEST_DESTROY_FAIL');
$stayed = zfsmSafeText("Keep Replicas could not remove $copy@c (cannot destroy '$copy@c': Input/output error); the next receive tries again");
same(array(array('c', 'e', 'f', 'g'), array(array('warning: Replication from 192.0.2.7 into '.$copy.' received: '.$stayed), array('warning: Replication from 192.0.2.7 into '.$copy.' received. '.$stayed.'.'))),
	array($on(), $said()), 'the log and a notification here say which stayed and why');
same(array(0, '', ''), $land('h', 2), 'the next receive');
same(array('e', 'g', 'h'), $on(), 'removes it');
putenv('ZFSM_TEST_KEEP_LIST_FAIL='.$copy);
same(array(0, '', ''), $land('i', 1), 'a read of the copy\'s snapshots that fails');
putenv('ZFSM_TEST_KEEP_LIST_FAIL');
$unread = 'Keep Replicas could not read the snapshots of '.$copy;
same(array(array('e', 'g', 'h', 'i'), array(array('warning: Replication from 192.0.2.7 into '.$copy.' received: '.$unread), array('warning: Replication from 192.0.2.7 into '.$copy.' received. '.$unread.'.'))),
	array($on(), $said()), 'removes nothing and says so');
putenv('ZFSM_TEST_RECV_FAIL=changes');
same(1, $land('j', 1)[0], 'a receive that fails');
putenv('ZFSM_TEST_RECV_FAIL');
same(array(array('e', 'g', 'h', 'i'), array(array(), array())), array($on(), $said()), 'removes nothing');
same(array(0, '', ''), $land('k', 1), 'the next one carries on from the newest');
same(array('e', 'k'), $on(), 'and keeps only it, past the one cloned');
// Periods count by when the sender took each snapshot, which the copy keeps: 1 2d, as Settings saves it, keeps the newest and the
// day before's newest. k was taken 22 December, l and m on 1 January, n on the 2nd (UTC).
file_put_contents($work.'/pool/props', "$copy@k\tcreation\t1766404800\n", FILE_APPEND);
$land('l', 0, 1767268800);
$land('m', 0, 1767272400);
same(array(0, '', ''), $land('n', loadConfig(array('general' => array('replica_keep' => '1 2D')))['replica_keep'], 1767355200), 'a receive with Keep Replicas 1 2d');
same(array(array('e', 'm', 'n'), array(array('notice: Removed '.$copy.'@k (not among the newest 1 and 2 daily Keep Replicas keeps)', 'notice: Removed '.$copy.'@l (not among the newest 1 and 2 daily Keep Replicas keeps)'), array())),
	array($on(), $said()), 'keeps the 2nd\'s and the 1st\'s newest, past the one cloned');
putenv('SSH_CLIENT');
unset($GLOBALS['docroot']);

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
), zfsmReplicaKeys($work.'/authorized_keys'), 'every key naming the checker is listed with what holds it: an option after restrict that gives back what it took away (in any case), a from= that isn\'t exact IP addresses, or a command that isn\'t exactly the installed checker and a name, doesn\'t hold');
$listed = zfsmReplicaKeysHtml(array(
	array('sender' => 'tower', 'comment' => 'zfs.master@tower', 'restrict' => true, 'from' => true),
	array('sender' => 'odd', 'comment' => "zfs.master@\xff<b>", 'restrict' => false, 'from' => true),
));
same(true, strpos($listed, '<span style="display:block">tower (zfs.master@tower): limited to replication</span>') !== false, 'a held key reads as limited');
same(true, strpos($listed, '<span style="display:block;color:#e22828">odd (zfs.master@'."\u{FFFD}".'&lt;b&gt;): its line needs restrict') !== false, 'one that isn\'t shows in red, escaped, even when its comment isn\'t valid UTF-8: '.$listed);
same(true, strpos($listed, 'command= exactly /usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php and a name</span>') !== false, 'the red line names the full path the parser accepts: '.$listed);
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
