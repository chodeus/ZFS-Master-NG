<?php
// Replication through the real runProcess, zfs send piped to zfs receive and scripts/replicate.php, with the fake zfs's small pool (ZFSM_TEST_REPL_DIR).

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

$GLOBALS['zfsm_feed'] = array();
function publish($channel, $message) {
	$GLOBALS['zfsm_feed'][] = json_decode($message, true)['op'];
}

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-replicate-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/pool', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
putenv('ZFSM_TEST_REPL_DIR='.$work.'/pool');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
putenv('ZFSM_TEST_CALL_LOG='.$work.'/calls.log');
mkdir($work.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $work.'/docroot/plugins/dynamix/scripts/notify');
chmod($work.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $work.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$work.'/notify.log');
putenv('ZFSM_TEST_NOTIFY_DETAIL_LOG='.$work.'/notify-detail.log');

$pool = function($file, $lines) use ($work) { file_put_contents($work.'/pool/'.$file, $lines ? implode("\n", $lines)."\n" : ''); };
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$names = function($dataset, $types) { return array_column(zfsmReplicaMarks($dataset, $types) ?? array(), 'name'); };
// Real sends and receives, without the dry runs (-n) each plan makes for its estimate.
$calls = function($verb) use ($read) { return array_values(array_filter($read('calls.log'), function($line) use ($verb) { return strpos($line, $verb.' ') === 0 && strpos($line, ' -n ') === false; })); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'calls.log') as $name) @unlink($work.'/'.$name); $GLOBALS['zfsm_feed'] = array(); };
$fresh = function() use ($pool, $reset) {
	$reset();
	$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/vol\tvolume", "tank/enc\tfilesystem", "backup\tfilesystem", "backup/empty\tfilesystem"));
	$pool('marks', array("tank/data@a\t11\t10", "tank/data@b\t12\t20", "tank/vol@v\t31\t30", "tank/enc@e\t41\t40"));
	$pool('props', array("tank/enc\tencryption\taes-256-gcm"));
	@unlink(getenv('ZFSM_TEST_REPL_DIR').'/txg');
};
$bookmark = function($dest, $snapshot) { return 'tank/data#'.zfsmReplicaBookmarkPrefix($dest).$snapshot; };
$cfg = array('snap_prefix' => 'repl-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);

// A first run sends in full, unmounted and read-only, and marks what the copy holds with a bookmark for that destination.
$fresh();
$plan = zfsmReplicatePlan('tank/data', 'b', 'backup/data');
same(array(true, 'full', null, false, 1000), array($plan['can_proceed'], $plan['mode'], $plan['base'], $plan['raw'], $plan['size']), 'a first run is a full send of its size');
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'it runs');
same(array('send -P -v tank/data@b'), $calls('send'), 'send streams the snapshot itself, with progress');
same(array('receive -s -u -o readonly=on -o canmount=noauto backup/data'), $calls('receive'), 'receive keeps it resumable, unmounted and read-only, never -F');
same(array('backup/data@b'), $names('backup/data', 'snapshot'), 'the copy holds b');
same(array($bookmark('backup/data', 'b')), $names('tank/data', 'bookmark'), 'and the dataset a bookmark for this destination at b');
same(array('replicate_start', 'replicate_stop'), array_values(array_unique($GLOBALS['zfsm_feed'])), 'the row shows the run');
same(array('normal: Replication of tank/data@b to backup/data finished (in full).'), $read('notify-detail.log'), 'and a notification says it finished');

// The next run sends only the changes, from the snapshot the copy last got; the bookmark moves on and the old one goes.
$reset();
runProcess('zfs', array('snapshot', 'tank/data@c'));
$plan = zfsmReplicatePlan('tank/data', 'c', 'backup/data');
same(array('incremental', 'tank/data@b'), array($plan['mode'], $plan['base']), 'a snapshot is the base while it exists');
same(0, zfsmRunReplicate('tank/data', 'c', 'backup/data'), 'it runs');
same(array('send -P -v -i tank/data@b tank/data@c'), $calls('send'), 'send streams the changes since b');
same(array($bookmark('backup/data', 'c')), $names('tank/data', 'bookmark'), 'the bookmark moves to c and the old one is removed');
same(array('normal: Replication of tank/data@c to backup/data finished (changes since b).'), $read('notify-detail.log'), 'the notification names the base by its snapshot');

// With c deleted, the bookmark keeps it incremental.
$reset();
runProcess('zfs', array('destroy', 'tank/data@c'));
runProcess('zfs', array('snapshot', 'tank/data@d'));
$plan = zfsmReplicatePlan('tank/data', 'd', 'backup/data');
same(array('incremental', $bookmark('backup/data', 'c')), array($plan['mode'], $plan['base']), 'after c is deleted, d goes from its bookmark');
same(0, zfsmRunReplicate('tank/data', 'd', 'backup/data'), 'it runs');
same(array('normal: Replication of tank/data@d to backup/data finished (changes since c).'), $read('notify-detail.log'), 'named by the snapshot the bookmark marks');

// Another destination has its own bookmark, so one never removes the other's base.
$reset();
same(0, zfsmRunReplicate('tank/data', 'd', 'backup/other'), 'a second destination runs');
same(array($bookmark('backup/data', 'd'), $bookmark('backup/other', 'd')), $names('tank/data', 'bookmark'), 'each destination keeps its own bookmark');
same(array('server' => '', 'dest' => 'backup/other'), zfsmReplicaLastDestination(zfsmReplicaMarks('tank/data')), 'the dialog offers the newest destination, on this server');

// Nothing is ever destroyed at the copy: what it can't take is refused, with why.
$refuse = function($snapshot, $dest) { $plan = zfsmReplicatePlan('tank/data', $snapshot, $dest); return $plan['can_proceed'] ? 'proceeds' : $plan['error']; };
same('backup/data already holds tank/data@d', $refuse('d', 'backup/data'), 'a copy that holds the snapshot');
same('backup/data already holds tank/data@d, which is newer than tank/data@b', $refuse('b', 'backup/data'), 'a copy ahead of the snapshot');
same('backup/empty already exists and holds no snapshot, so it can\'t be updated without destroying it', $refuse('d', 'backup/empty'), 'an existing dataset without snapshots');
file_put_contents($work.'/pool/marks', "backup/data@stray\t999\t500\n", FILE_APPEND);
same('backup/data@stray is not on this dataset, so backup/data can\'t be updated without destroying it', $refuse('d', 'backup/data'), 'a copy that went its own way');
same("The copy can't be the dataset itself, go inside it, or replace one of its parents", $refuse('d', 'tank/data/copy'), 'inside the dataset');
file_put_contents($work.'/pool/datasets', "tank/data/sub\tfilesystem\n", FILE_APPEND);
file_put_contents($work.'/pool/marks', "tank/data/sub@s\t51\t600\n", FILE_APPEND);
$parent = zfsmReplicatePlan('tank/data/sub', 's', 'tank/data');
same("The copy can't be the dataset itself, go inside it, or replace one of its parents", $parent['error'] ?? 'proceeds', 'a parent of it');
same('The copy goes inside nowhere, which doesn\'t exist', $refuse('d', 'nowhere/copy'), 'a missing parent');
same('The copy needs a dataset name inside a pool, such as backup/data', $refuse('d', 'backup'), 'a bare pool');
same('The snapshot tank/data@gone no longer exists', $refuse('gone', 'backup/new'), 'a snapshot that is gone');

// A stopped run leaves a token on the copy; the next one carries on from it, whatever snapshot is picked.
$fresh();
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a run cut off part-way fails');
putenv('ZFSM_TEST_SEND_CUT');
same(true, strpos($read('notify-detail.log')[0] ?? '', 'failed: cannot receive incremental stream: checksum mismatch or incomplete stream. Replicate it again to carry on from where it stopped.') !== false, 'and says another run carries on: '.($read('notify-detail.log')[0] ?? ''));
same(array(), $names('tank/data', 'bookmark'), 'with no bookmark for what the copy lacks');
$reset();
$plan = zfsmReplicatePlan('tank/data', 'a', 'backup/data');
same(array('resume', 'tank/data@b', 'tok:tank/data@b', 1000), array($plan['mode'], $plan['snapshot'], $plan['token'], $plan['size']), 'the next plan resumes the stopped one');
same(0, zfsmRunReplicate('tank/data', 'a', 'backup/data'), 'and runs');
same(array('send -P -v -t tok:tank/data@b'), $calls('send'), 'from the token');
same(array('receive -s -u -o readonly=on -o canmount=noauto backup/data'), $calls('receive'), 'with the same receive options');
same(array(array('backup/data@b'), array($bookmark('backup/data', 'b'))), array($names('backup/data', 'snapshot'), $names('tank/data', 'bookmark')), 'the copy and the bookmark end at b');
same(array('normal: Replication of tank/data@b to backup/data finished (carried on from where it stopped).'), $read('notify-detail.log'), 'the notification says it carried on');
file_put_contents($work.'/pool/props', "backup/data\treceive_resume_token\ttok:tank/vol@v\n", FILE_APPEND);
same('backup/data holds a part-received replication of tank/vol@v, not of this dataset', $refuse('b', 'backup/data'), 'another dataset\'s token is not resumed');

// The same snapshot again, after its copy was destroyed: the bookmark already there is this one, not a failure.
$fresh();
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a first run');
file_put_contents($work.'/pool/datasets', implode('', array_filter(file($work.'/pool/datasets'), function($line) { return strpos($line, "backup/data\t") !== 0; })));
file_put_contents($work.'/pool/marks', implode('', array_filter(file($work.'/pool/marks'), function($line) { return strpos($line, 'backup/data@') !== 0; })));
$reset();
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'runs again into a new copy');
same(array('normal: Replication of tank/data@b to backup/data finished (in full).'), $read('notify-detail.log'), 'and finishes without a bookmark warning');
same(array($bookmark('backup/data', 'b')), $names('tank/data', 'bookmark'), 'with its one bookmark');

// When one side fails the other does too; the notification gives the side that failed first.
$fresh();
putenv('ZFSM_TEST_SEND_FAIL=1');
same(1, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a send that fails fails the run');
putenv('ZFSM_TEST_SEND_FAIL');
same(true, strpos($read('notify-detail.log')[0] ?? '', "failed: warning: cannot send ?tank/data@b?: Input/output error.") !== false, 'with send\'s error, not receive\'s short stream: '.($read('notify-detail.log')[0] ?? ''));
$reset();
putenv('ZFSM_TEST_RECV_FAIL=1');
same(1, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a receive that fails fails the run');
putenv('ZFSM_TEST_RECV_FAIL');
same(true, strpos($read('notify-detail.log')[0] ?? '', 'failed: cannot receive new filesystem stream: out of space') !== false, 'with receive\'s error: '.($read('notify-detail.log')[0] ?? ''));
$fresh();
putenv('ZFSM_TEST_SEND_FAIL_PART=1');
same(1, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a send that fails part-way fails the run');
putenv('ZFSM_TEST_SEND_FAIL_PART');
same(true, strpos($read('notify-detail.log')[0] ?? '', "failed: warning: cannot send ?tank/data@b?: Input/output error.") !== false, 'with send\'s error, not receive\'s incomplete stream: '.($read('notify-detail.log')[0] ?? ''));
// A snapshot the run took for itself goes when nothing can carry the run on, and stays while a stopped run needs it.
$fresh();
putenv('ZFSM_TEST_RECV_FAIL=1');
same(1, zfsmRunReplicate('tank/data', 'repl-9', 'backup/data', null, true), 'a run that took its snapshot and failed');
putenv('ZFSM_TEST_RECV_FAIL');
same(false, in_array('tank/data@repl-9', $names('tank/data', 'snapshot'), true), 'leaves no snapshot behind');
$fresh();
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/data', 'repl-10', 'backup/data', null, true), 'a run that took its snapshot and was cut off');
putenv('ZFSM_TEST_SEND_CUT');
same(true, in_array('tank/data@repl-10', $names('tank/data', 'snapshot'), true), 'keeps it for the run that carries on');
// A token that can't be read may still be there: the snapshot stays, and the plan refuses rather than guess.
$fresh();
putenv('ZFSM_TEST_SEND_CUT=1');
putenv('ZFSM_TEST_TOKEN_FAIL=1');
same(1, zfsmRunReplicate('tank/data', 'repl-11', 'backup/data', null, true), 'a cut-off run whose token can\'t be read');
putenv('ZFSM_TEST_SEND_CUT');
same(true, in_array('tank/data@repl-11', $names('tank/data', 'snapshot'), true), 'keeps the snapshot it took');
same(true, strpos($read('notify-detail.log')[0] ?? '', 'incomplete stream. Whether it can carry on could not be read; the snapshot it took is kept.') !== false, 'and says so: '.($read('notify-detail.log')[0] ?? ''));
same('Could not check whether backup/data holds a part-received replication', $refuse('repl-11', 'backup/data'), 'the next plan refuses while the token can\'t be read');
putenv('ZFSM_TEST_TOKEN_FAIL');

// An encrypted dataset goes raw, so the copy stays locked with its key; a volume's copy takes volmode=none, not canmount, on every run.
$reset();
same(0, zfsmRunReplicate('tank/enc', 'e', 'backup/enc'), 'an encrypted dataset runs');
same(array('send -P -v -w tank/enc@e'), $calls('send'), 'sent raw');
$reset();
same(0, zfsmRunReplicate('tank/vol', 'v', 'backup/vol'), 'a volume runs');
same(array('receive -s -u -o readonly=on -o volmode=none backup/vol'), $calls('receive'), 'received read-only, without canmount, and not as a block device');
file_put_contents($work.'/pool/marks', "tank/vol@w\t32\t35\n", FILE_APPEND);
$reset();
same('incremental', zfsmReplicatePlan('tank/vol', 'w', 'backup/vol')['mode'], 'a volume\'s next run is incremental');
same(0, zfsmRunReplicate('tank/vol', 'w', 'backup/vol'), 'and runs');
same(array('receive -s -u -o readonly=on -o volmode=none backup/vol'), $calls('receive'), 'an incremental takes volmode=none too');
$reset();
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/vol', 'v', 'backup/vol2'), 'a volume\'s first run cut off part-way');
putenv('ZFSM_TEST_SEND_CUT');
$reset();
same('resume', zfsmReplicatePlan('tank/vol', 'v', 'backup/vol2')['mode'], 'the next plan resumes it');
same(0, zfsmRunReplicate('tank/vol', 'v', 'backup/vol2'), 'and runs');
same(array('receive -s -u -o readonly=on -o volmode=none backup/vol2'), $calls('receive'), 'a resumed run takes volmode=none too');

// One run per dataset: while another holds the lock, a second neither plans nor runs.
$fresh();
$lock_file = zfsmJobLockFile('tank/data', 'replicate');
@mkdir(dirname($lock_file), 0700, true);
$held = fopen($lock_file, 'c');
flock($held, LOCK_EX);
same(0, zfsmRunReplicate('tank/data', 'b', 'backup/data'), 'a second run steps aside');
same(array(), $calls('send'), 'and sends nothing');
same('A replication of this dataset is already running', zfsmReplicatePreview('tank/data', 'b', 'backup/data', $cfg)['error'], 'the dialog says one is running');
flock($held, LOCK_UN);
fclose($held);

// The dialog: snapshots newest first; with a snapshot taken now the size isn't known yet.
$preview = zfsmReplicatePreview('tank/data', '', '', $cfg);
same(array(array('b', 'a'), null, array(), false), array($preview['snapshots'], $preview['last_destination'], $preview['servers'], $preview['can_proceed']), 'snapshots newest first, no servers to send to, and nothing to plan without a destination');
$preview = zfsmReplicatePreview('tank/data', '', 'backup/data', $cfg);
same(array(true, 'full', null), array($preview['can_proceed'], $preview['mode'], $preview['size']), 'a snapshot taken now: planned, size unknown');

// The dialog reads the dataset's snapshots once per change, and a start plans without the size estimate, which the job makes.
$reset();
zfsmReplicatePreview('tank/data', 'b', 'backup/data', $cfg);
same(1, count(array_filter($read('calls.log'), function($line) { return strpos($line, 'list -H -p -d 1 -t snapshot,bookmark') === 0 && substr($line, -10) === ' tank/data'; })), 'one listing of the dataset\'s snapshots per preview');
$reset();
same(null, zfsmReplicatePlan('tank/data', 'b', 'backup/data', array('estimate' => false))['size'], 'a plan without its estimate');
same(array(), array_values(array_filter($read('calls.log'), function($line) { return strpos($line, 'send ') === 0; })), 'runs no zfs send at all');

// A snapshot taken now is named before the run, so the bookmark it needs is checked as well; and only the plugin's own bookmarks name a destination.
// tank/data#zfsm_ plus the hex of this name (2 x 117) plus _ is 250, which fits; the new snapshot's repl-<14 digits> takes it past ZFS's 255.
$long = 'backup/'.str_repeat('x', 110);
same('The names are too long for the bookmark that keeps the next replication incremental', zfsmReplicatePreview('tank/data', '', $long, $cfg)['error'], 'a new snapshot whose bookmark would be too long');
$other = function($named) { return array('name' => 'tank/data#zfsm_'.bin2hex($named).'_daily', 'guid' => '1', 'txg' => 1); };
same(array(null, null, null), array_map('zfsmReplicaLastDestination', array(array(array('name' => 'tank/data#zfsm_ab_daily', 'guid' => '1', 'txg' => 1)), array($other('a@b@c/d')), array($other('bad name@pool/copy')))), 'another tool\'s zfsm_ bookmark names no destination');

// The job takes a new snapshot under its lock: none while another run holds it, and none left behind when the run can't go ahead.
$lock_file = zfsmJobLockFile('tank/data', 'replicate');
$held = fopen($lock_file, 'c');
flock($held, LOCK_EX);
same(0, zfsmRunReplicate('tank/data', 'repl-1', 'backup/data', null, true), 'with another run holding the lock, a second steps aside');
same(false, in_array('tank/data@repl-1', $names('tank/data', 'snapshot'), true), 'without taking its snapshot');
flock($held, LOCK_UN);
fclose($held);
$reset();
same(1, zfsmRunReplicate('tank/data', 'repl-2', 'nowhere/data', null, true), 'a run whose copy has nowhere to go does not start');
same(false, in_array('tank/data@repl-2', $names('tank/data', 'snapshot'), true), 'and removes the snapshot it took');
same(array('warning: Replication of tank/data to nowhere/data not started: The copy goes inside nowhere, which doesn?t exist'), $read('notify-detail.log'), 'saying why, made safe for notification agents');

// A start takes the snapshot, runs scripts/replicate.php in a session of its own and answers once it holds the lock.
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/replicate.php')) {
	$reset();
	$GLOBALS['docroot'] = $docroot;
	$GLOBALS['zfsm_php'] = PHP_BINARY;
	$answer = zfsmStartReplicate('tank/data', '', 'backup/data', array('snap_prefix' => 'repl-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1));
	same(array('tank/data' => 'Replication to backup/data started; a notification says when it is done'), $answer['succeeded'], 'a start with a snapshot taken now says it started');
	$background_lock = zfsmJobLockFile('tank/data', 'replicate');
	for ($wait = 0; $wait < 100 && zfsmJobRunning($background_lock); $wait++) usleep(50000);
	same(false, zfsmJobRunning($background_lock), 'and finishes, letting go of its lock');
	same(1, preg_match('/^backup\/data@repl-[0-9]{14}$/', $names('backup/data', 'snapshot')[0] ?? ''), 'the copy holds the snapshot it took');
	// A copy with a stopped run carries that run on, and the start says so instead of naming the snapshot picked.
	file_put_contents($work.'/pool/props', "backup/data\treceive_resume_token\ttok:tank/data@b\n", FILE_APPEND);
	$answer = zfsmStartReplicate('tank/data', 'a', 'backup/data', $cfg);
	same(array('tank/data' => 'Replication of tank/data@b to backup/data carries on from where it stopped; replicate again afterwards for newer snapshots; a notification says when it is done'), $answer['succeeded'], 'a resumed start says what it carries on');
	for ($wait = 0; $wait < 100 && zfsmJobRunning($background_lock); $wait++) usleep(50000);
	// The job's reason not to start reaches the page too: here its new snapshot's name is taken already.
	runProcess('zfs', array('snapshot', 'tank/data@repl-fixed'));
	$failed = zfsmStartReplicate('tank/data', '', 'backup/fresh', array('snap_prefix' => 'repl-', 'snap_pattern' => '\\f\\i\\x\\e\\d'))['failed']['tank/data'] ?? '';
	same("The replication could not be started: cannot create snapshot 'tank/data@repl-fixed': dataset already exists", $failed, 'a run refused in the job says why on the page');
	$GLOBALS['docroot'] = $work.'/docroot';
} else {
	fwrite(STDERR, "FAILED: the background start needs ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}

// To a trusted server: the fake ssh (fixtures/fake_ssh) runs the real checker on the same fake pool, as a server sending to itself does.
mkdir($work.'/bin', 0700);
foreach (array('zfs', 'zpool') as $fake) copy(getenv('ZFSM_TEST_BIN_DIR').'/'.$fake, $work.'/bin/'.$fake) && chmod($work.'/bin/'.$fake, 0755);
copy(__DIR__.'/fixtures/fake_ssh', $work.'/bin/ssh') && chmod($work.'/bin/ssh', 0755);
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_SSH_LOG='.$work.'/ssh.log');
putenv('ZFSM_TEST_PHP='.PHP_BINARY);
putenv('ZFSM_TEST_SERVE='.__DIR__.'/fixtures/fake_ssh_serve.php');
putenv('ZFSM_TEST_ACCEPT=backup/replicas');
putenv('ZFSM_TEST_SENDER=tower');
putenv('ZFSM_TEST_FLASH_KEY='.$work.'/flash/ssh/id_ed25519');
$GLOBALS['zfsm_ssh_dir'] = $work.'/flash/ssh';
$GLOBALS['zfsm_ident_file'] = $work.'/ident.cfg';
file_put_contents($work.'/ident.cfg', "NAME=\"tower\"\n");
same(true, isset(zfsmReplicaKeyMake()['key']), 'this server has its key, made by the real ssh-keygen');
exec('ssh-keygen -q -t ed25519 -N "" -C host -f '.escapeshellarg($work.'/host'));
$host_key = implode(' ', array_slice(explode(' ', trim(file_get_contents($work.'/host.pub'))), 0, 2));
same('', zfsmReplicaRemoteAdd('nas', '10.0.20.11', 22, $host_key, zfsmKeyFingerprint($host_key)), 'and trusts nas');
$ssh = function() use ($read, $work) { $lines = $read('ssh.log'); @unlink($work.'/ssh.log'); return $lines; };
$logins = function($lines) { return array(count(array_keys($lines, 'login', true)), count(array_keys($lines, 'on the connection', true)), count(array_keys($lines, 'connection ends', true))); };
$left = function() use ($work) { return glob($work.'/state/replica-send/ssh-*') ?: array(); };
$fresh();
file_put_contents($work.'/pool/datasets', "backup/replicas\tfilesystem\n", FILE_APPEND);
$ssh();
$remote_mark = function($snapshot) { return 'tank/data#'.zfsmReplicaBookmarkPrefix('nas@backup/replicas/tower/data').$snapshot; };

// The dialog lists the servers; picking one shows its area, and a name with a / is refused there.
$preview = zfsmReplicatePreview('tank/data', '', '', $cfg);
same(array(array('nas'), null), array($preview['servers'], $preview['area']), 'the dialog offers nas, and asks it nothing until it is picked');
same(array(), $ssh(), 'so no ssh runs');
same('', zfsmReplicaRemoteAdd('2024', '10.0.20.12', 22, $host_key, zfsmKeyFingerprint($host_key)), 'a server named with digits only');
same('["nas","2024"]', json_encode(zfsmReplicatePreview('tank/data', '', '', $cfg)['servers']), 'is offered by its name, as the dialog compares names');
same('', zfsmReplicaRemoteRemove('2024'), 'and removed again');
$preview = zfsmReplicatePreview('tank/data', '', '', $cfg, 'nas');
same(array('backup/replicas/tower', '', false), array($preview['area'], $preview['error'], $preview['can_proceed']), 'picking nas shows its area');
$preview = zfsmReplicatePreview('tank/data', 'b', 'copies/data', $cfg, 'nas');
same('On nas the copy goes inside backup/replicas/tower, so give it one name with no slash, such as data', $preview['error'] ?? 'proceeds', 'a name with a / is refused there');
same(array(2, 0, 2), $logins($ssh()), 'one login each preview, ended after it');
same('No server here is named gone', zfsmReplicatePreview('tank/data', 'b', 'data', $cfg, 'gone')['error'], 'a server no longer trusted is refused');

// A first run sends in full into the area; later runs send the changes; the bookmark names the server, so a copy here keeps its own.
$target = zfsmReplicaTargetOpen('nas');
$plan = zfsmReplicatePlan('tank/data', 'b', 'data', array('target' => $target));
zfsmReplicaDisconnect($target);
same(array(true, 'full', 'backup/replicas/tower/data', 'nas'), array($plan['can_proceed'], $plan['mode'] ?? $plan['error'], $plan['dest'] ?? null, $plan['server'] ?? null), 'a first copy there is a full send: the checker says it doesn\'t exist');
$ssh();
same(0, zfsmRunReplicate('tank/data', 'b', 'data', null, false, 'nas'), 'it runs');
same(array('backup/replicas/tower/data@b'), $names('backup/replicas/tower/data', 'snapshot'), 'the copy there holds b');
same(array('receive -s -u -o readonly=on -x mountpoint -x volmode -x setuid -x devices -x exec -x sharenfs -x sharesmb -x snapdev backup/replicas/tower/data'), $calls('receive'), 'by the checker\'s receive: read-only, keeping the area\'s properties');
same(array($remote_mark('b')), $names('tank/data', 'bookmark'), 'and the dataset a bookmark for nas\'s copy');
same(array('normal: Replication of tank/data@b to backup/replicas/tower/data on server nas finished (in full).'), $read('notify-detail.log'), 'the notification names the server');
same(array(1, 2, 1), $logins($ssh()), 'one login for the run: hello, then exists and the stream on that connection, which it ends');
same(array(), $left(), 'and the key\'s RAM copy is gone');
same(array('server' => 'nas', 'dest' => 'backup/replicas/tower/data'), zfsmReplicaLastDestination(zfsmReplicaMarks('tank/data')), 'the dialog offers nas\'s copy next');
$reset();
runProcess('zfs', array('snapshot', 'tank/data@c'));
same(0, zfsmRunReplicate('tank/data', 'c', 'data', null, false, 'nas'), 'the next run');
same(array('send -P -v -i tank/data@b tank/data@c'), $calls('send'), 'sends the changes since b');
same(array($remote_mark('c')), $names('tank/data', 'bookmark'), 'and the bookmark moves to c');
same(array(1, 4, 1), $logins($ssh()), 'still one login: exists, token, snapshots and the stream ride it');
same(array('backup/replicas/tower/data on server nas already holds tank/data@c'), array(zfsmReplicatePreview('tank/data', 'c', 'data', $cfg, 'nas')['error']), 'the copy there is read back through the checker');
$ssh();

// A cut-off stream leaves a token there; the next run carries on from it.
$reset();
runProcess('zfs', array('snapshot', 'tank/data@d'));
putenv('ZFSM_TEST_SEND_CUT=1');
same(1, zfsmRunReplicate('tank/data', 'd', 'data', null, false, 'nas'), 'a run cut off part-way fails');
putenv('ZFSM_TEST_SEND_CUT');
same(array('warning: Replication of tank/data@d to backup/replicas/tower/data on server nas failed: cannot receive incremental stream: checksum mismatch or incomplete stream. Replicate it again to carry on from where it stopped.'),
	$read('notify-detail.log'), 'with receive\'s first line only, and the token read there');
$reset();
same(0, zfsmRunReplicate('tank/data', 'b', 'data', null, false, 'nas'), 'the next run');
same(array('send -P -v -t tok:tank/data@d'), $calls('send'), 'carries on from the token there');
same(array($remote_mark('d')), $names('tank/data', 'bookmark'), 'and ends at d');
$ssh();

// Why a run fails: the checker's refusal, even after send's own error; send's error when the checker only saw no stream.
$reset();
$slots = array();
for ($slot = 0; $slot < 4; $slot++) $slots[] = zfsmJobLock(zfsmJobDir('replica-serve').'/'.hash('sha256', 'backup/replicas/tower').'.'.$slot.'.lock', false);
runProcess('zfs', array('snapshot', 'tank/data@e'));
putenv('ZFSM_TEST_SEND_FAIL_PART=1');
same(1, zfsmRunReplicate('tank/data', 'e', 'data', null, false, 'nas'), 'a receive the checker refuses fails the run');
putenv('ZFSM_TEST_SEND_FAIL_PART');
array_map('fclose', $slots);
same(true, strpos($read('notify-detail.log')[0] ?? '', 'failed: No receive into backup/replicas/tower can start while four are running.') !== false, 'with the checker\'s reason: '.($read('notify-detail.log')[0] ?? ''));
$reset();
putenv('ZFSM_TEST_SEND_FAIL=1');
same(1, zfsmRunReplicate('tank/data', 'e', 'data', null, false, 'nas'), 'a send that fails at once');
putenv('ZFSM_TEST_SEND_FAIL');
same(true, strpos($read('notify-detail.log')[0] ?? '', "failed: warning: cannot send ?tank/data@e?: Input/output error.") !== false, 'says send\'s error, not that the checker got no stream: '.($read('notify-detail.log')[0] ?? ''));
$reset();
putenv('ZFSM_TEST_SSH_DROP=100');
same(1, zfsmRunReplicate('tank/data', 'e', 'data', null, false, 'nas'), 'a connection that drops part-way');
putenv('ZFSM_TEST_SSH_DROP');
same(true, strpos($read('notify-detail.log')[0] ?? '', 'failed: The connection to nas dropped') !== false, 'says so: '.($read('notify-detail.log')[0] ?? ''));
$ssh();

// A server ssh can't reach is not asked again for a while, by any run or dialog; then it is.
$reset();
putenv('ZFSM_TEST_SSH_FAIL=ssh: connect to host 10.0.20.11 port 22: Connection refused');
same(1, zfsmRunReplicate('tank/data', 'e', 'data', null, false, 'nas'), 'a server that refuses the connection');
$refused = 'Nothing answers SSH at 10.0.20.11 port 22: turn SSH on there (Settings, Management Access, Use SSH), or check the port. ssh said: ssh: connect to host 10.0.20.11 port 22: Connection refused';
same(array('warning: Replication of tank/data to data on server nas not started: '.$refused), $read('notify-detail.log'), 'says what to do');
same(array(1, 0, 0), $logins(array_map(function($line) { return strpos($line, 'known ') === 0 ? 'login' : $line; }, $ssh())), 'after one try');
same(1, preg_match('/^'.preg_quote($refused, '/').'\. It is tried again in (?:29|30) seconds$/D', zfsmReplicatePreview('tank/data', 'e', 'data', $cfg, 'nas')['error']), 'the dialog says when it is tried again');
same(1, preg_match('/^'.preg_quote($refused, '/').'\. It is tried again in (?:29|30) seconds$/D', zfsmReplicaHello(zfsmReplicaRemote('nas'))['error'] ?? ''), 'and so does Settings\' Test');
same(array(), $ssh(), 'without trying');
putenv('ZFSM_TEST_SSH_FAIL');
touch(zfsmReplicaDownFile('nas'), time() - ZFSM_REPLICA_RETRY);
same(0, zfsmRunReplicate('tank/data', 'e', 'data', null, false, 'nas'), 'later it is tried again');
same(false, file_exists(zfsmReplicaDownFile('nas')), 'and the wait is over once it answers');
putenv('ZFSM_TEST_SSH_FAIL=ssh: connect to host 10.0.20.11 port 22: Connection refused');
same(true, isset(zfsmReplicaHello(zfsmReplicaRemote('nas'))['error']), 'a Test that fails starts the wait again');
putenv('ZFSM_TEST_SSH_FAIL');
$nas = zfsmReplicaRemote('nas');
same(array('', ''), array(zfsmReplicaRemoteRemove('nas'), zfsmReplicaRemoteAdd('nas', $nas['host'], $nas['port'], $nas['key'], zfsmKeyFingerprint($nas['key']))), 'nas removed and added again');
same(array('area' => 'backup/replicas/tower'), zfsmReplicaHello(zfsmReplicaRemote('nas')), 'is tried at once');
putenv('ZFSM_TEST_SSH_LOST=hello');
same(array('error' => 'The connection to nas dropped'), zfsmReplicaHello(zfsmReplicaRemote('nas')), 'a hello whose end there is lost after its answer is no answer');
putenv('ZFSM_TEST_SSH_LOST');
same(1, preg_match('/^The connection to nas dropped\. It is tried again in (?:29|30) seconds$/D', zfsmReplicaHello(zfsmReplicaRemote('nas'))['error'] ?? ''), 'and the wait says so');
touch(zfsmReplicaDownFile('nas'), time() - ZFSM_REPLICA_RETRY);
// Past the dialog's read limit: a connection being made or ended leaves no key file behind, and ending one doesn't throw.
$timed_out = function($read) { try { zfsmWithDeadline(0, $read); return 'no throw'; } catch (ZFSMTimeout $timeout) { return $timeout->getMessage(); } };
$ssh();
same('ssh timed out before it started', $timed_out(function() { zfsmReplicaTargetOpen('nas'); }), 'the hello past the limit throws');
same(array(), $left(), 'with the key\'s RAM copy already gone');
$target = zfsmReplicaTargetOpen('nas');
same(array('no throw', array()), array($timed_out(function() use ($target) { zfsmReplicaDisconnect($target); }), $left()), 'ending one past the limit keeps what was read, and its key files go');
same(true, in_array('connection ends', $ssh(), true), 'and the connection ends all the same, as one whose command hangs there would outlive ControlPersist');
same(array(), $left(), 'every connection\'s key files are gone');
same(array('tank/data' => 'Replication to data on server gone not started: No server here is named gone'), zfsmStartReplicate('tank/data', 'e', 'data', $cfg, 'gone')['failed'], 'a start that fails names its server as a run does');
same(array('tank/data' => 'Replication on server gone not started: No server here is named gone'), zfsmStartReplicate('tank/data', 'e', "<b>\n", $cfg, 'gone')['failed'], 'with only the names that are valid');
same(array('tank/data' => 'A server\'s name is '.ZFSM_REPLICA_NAME_RULE), zfsmStartReplicate('tank/data', 'e', 'data', $cfg, "x\ny")['failed'], 'and none it was given that is not');

// A connection lost just as the receive ends there: the copy may hold the snapshot the run took, so the run keeps it as the next one's base.
$reset();
putenv('ZFSM_TEST_SSH_LOST=receive');
same(1, zfsmRunReplicate('tank/data', 'f', 'data', null, true, 'nas'), 'a run whose end there is lost');
putenv('ZFSM_TEST_SSH_LOST');
same(array(true, true), array(in_array('backup/replicas/tower/data@f', $names('backup/replicas/tower/data', 'snapshot'), true), in_array('tank/data@f', $names('tank/data', 'snapshot'), true)), 'the copy holds the snapshot it took, and so does the dataset');
same(true, strpos($read('notify-detail.log')[0] ?? '', 'failed: The connection to nas dropped; the copy there may hold it already, so the snapshot it took is kept.') !== false, 'says so: '.($read('notify-detail.log')[0] ?? ''));
runProcess('zfs', array('snapshot', 'tank/data@g'));
$target = zfsmReplicaTargetOpen('nas');
$plan = zfsmReplicatePlan('tank/data', 'g', 'data', array('target' => $target, 'estimate' => false));
zfsmReplicaDisconnect($target);
same(array('incremental', 'tank/data@f'), array($plan['mode'] ?? $plan['error'], $plan['base'] ?? null), 'and the next run there is incremental from it');
$ssh();

// A start in the background passes the server to the job, with a snapshot it takes or one picked. The job finds the key, the
// servers and this server's name where Unraid keeps them: here the tmpfs run_http_inventory_tests.sh mounts on /boot/config.
zfsmTestScratch('/boot/config');
@mkdir('/boot/config/plugins/zfs.master', 0700, true);
exec('cp -R '.escapeshellarg($work.'/flash/ssh').' /boot/config/plugins/zfs.master/ssh && cp '.escapeshellarg($work.'/ident.cfg').' /boot/config/ident.cfg');
register_shutdown_function(function() { exec('rm -rf /boot/config/plugins/zfs.master/ssh /boot/config/ident.cfg'); });
$GLOBALS['docroot'] = getenv('ZFSM_TEST_DOCROOT');
foreach (array('' => 'fresh', 'e' => 'picked') as $snapshot => $name) {
	$reset();
	$answer = zfsmStartReplicate('tank/data', (string)$snapshot, $name, $cfg, 'nas');
	same(array('tank/data' => 'Replication to backup/replicas/tower/'.$name.' on server nas started; a notification says when it is done'), $answer['succeeded'], 'a start to nas says where the copy goes');
	for ($wait = 0; $wait < 100 && zfsmJobRunning($background_lock); $wait++) usleep(50000);
	same(1, preg_match('/^backup\/replicas\/tower\/'.$name.'@'.($snapshot === '' ? 'repl-[0-9]{14}' : 'e').'$/D', $names('backup/replicas/tower/'.$name, 'snapshot')[0] ?? ''), 'and the copy there holds the snapshot: '.$name);
}
$GLOBALS['docroot'] = $work.'/docroot';

echo "replication tests passed\n";
