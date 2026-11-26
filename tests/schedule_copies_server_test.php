<?php
// Copies under a dataset on a trusted server (include/ZFSMScheduleCopies.php): Settings' and the Schedules dialog's Save, runs through the fake ssh (fixtures/fake_ssh),
// which runs the real checker on the same fake pool, as a server sending to itself does; an older or unreachable server; a rename.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

function publish($channel, $message) {
}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-copyserver-'.getmypid().'-'.bin2hex(random_bytes(4));
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
$root = $work.'/root';
$GLOBALS['zfsm_root'] = $root;
mkdir($root.'/boot/config/plugins/zfs.master', 0700, true);
mkdir($root.'/usr/local/sbin', 0700, true);
mkdir($root.'/var/log', 0700, true);
file_put_contents($root.'/usr/local/sbin/update_cron', "#/bin/bash\necho ran >> ".escapeshellarg($work.'/cron.log')."\n");

// nas: the fake ssh, its checker accepting into backup/replicas, where this server, tower, has backup/replicas/tower.
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

$pool = function($file, $lines) use ($work) { file_put_contents($work.'/pool/'.$file, $lines ? implode("\n", $lines)."\n" : ''); };
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'calls.log', 'cron.log', 'ssh.log') as $name) @unlink($work.'/'.$name); };
require_once __DIR__.'/fixtures/schedules_stored.php';
$stored = 'zfsmTestStored';
$area = function() use ($read) { return array_values(preg_grep('~^backup/replicas/tower~', array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/datasets')))); };
$calls = function($verb) use ($read) { return array_values(preg_grep('/^'.$verb.' /', $read('calls.log'))); };
$snaps = function($dataset) { return array_column(zfsmKeepSnapshots($dataset) ?? array(), 'tag', 'name'); };
$logged = function($pattern) { return preg_match($pattern.'m', (string)@file_get_contents(zfsmLogFile())); };
$holds = function($zdataset) use ($read) { return array_values(preg_grep('~^'.preg_quote($zdataset, '~').'@~', $read('pool/holds'))); };
$cfg = array('snap_prefix' => 'auto-', 'snap_pattern' => 'YmdHis', 'snap_utc' => 1);
$name = function($now) use ($cfg) { return zfsmNewSnapshotName($cfg, $now); };
// nas is this server: its pool backup, read-only as a pool of copies is, is left out. tank/san is left to Sanoid, its child copied.
$pool('datasets', array("tank\tfilesystem", "tank/data\tfilesystem", "tank/san\tfilesystem", "tank/san/kid\tfilesystem", "backup\tfilesystem", "backup/replicas\tfilesystem"));
$pool('marks', array("tank/san@autosnap_2026-11-01_00:00:00_daily\t11\t10"));
$pool('props', array("backup\treadonly\ton"));
$pool('holds', array());

// Settings' Server offers This server and each trusted one; one no longer trusted stays while saved.
same(array('<option value="nas" selected>nas</option>', '<option value="nas">nas</option><option value="gone" selected>gone</option>'),
	array(zfsmReplicaServerOptions('nas'), zfsmReplicaServerOptions('gone')), 'the Server options');
same(array(array('123', 'nas'), array('nas', 'gone'), array()), array(zfsmReplicaServerNames('123', array(123 => array(), 'nas' => array())),
	zfsmReplicaServerNames('gone', array('nas' => array())), zfsmReplicaServerNames('', null)), 'the Server names: one of digits is still a name');

// A copy's path inside the area that ZFS would not take is refused before nas is asked.
$deep = 'copies/'.implode('/', array_fill(0, 120, 'abcdefgh'));
same(array(null, 'On nas the copy would be backup/replicas/tower/'.$deep.', which is no dataset name ZFS takes'),
	zfsmReplicaTargetDest(array('server' => 'nas', 'area' => 'backup/replicas/tower'), 'tank/data', $deep, true), 'a path too long there');

// Save: one trusted here, asked nothing; Copy Under needs no dataset here, as the run makes it on nas. Copy Keeps is nas's to decide.
same('', zfsmScheduleShown('zfsmScheduleCopiesUnder')['server'], 'Settings shows This server while it is off');
$reset();
$wrong = array(
	array('copies', null, 'gone', 'Nothing was saved: No server here is named gone'),
	array('copies', null, 'bad name!', 'Nothing was saved: A server\'s name is '.ZFSM_REPLICA_NAME_RULE),
	array('', null, 'nas', 'Nothing was saved: under Copy Under, name a dataset to hold them inside the area nas gives this server, such as backup'),
	array('copies', 0, 'nas', 'Nothing was saved: leave Copy Keeps empty: what a copy on another server keeps, its Keep Replicas decides'),
);
same(array_column($wrong, 3), array_map(function($try) use ($cfg) { return zfsmScheduleCopiesSave($try[0], 'hourly', '', '', 1, $cfg, $try[1], $try[2])['why']; }, $wrong), 'what is wrong is said');
same(null, $stored(), 'and nothing is saved');
$saved = zfsmScheduleCopiesSave('copies', 'hourly', '', '', 1, $cfg, null, 'nas');
same('', $saved['why'], 'saved');
$job = array_values($stored())[0];
same(array('saved' => true, 'why' => '', 'said' => 'Saved: a copy of each of 2 dataset(s) under copies on server nas every hour at :'.sprintf('%02d', $job['minute']).'; it keeps the newest 1 of the snapshots of each dataset it copies'),
	$saved, 'saved, counted: tank/data and tank/san/kid, as no pool\'s own dataset is and backup/replicas is read-only');
same(array('dataset' => '', 'dest' => 'copies/', 'server' => 'nas', 'every' => 'hourly', 'keep' => 1), array_intersect_key($job, array_flip(array('dataset', 'dest', 'server', 'every', 'keep'))), 'as the record');
same(array('copies/', 'nas'), array(zfsmScheduleShown('zfsmScheduleCopiesUnder')['dest'], zfsmScheduleShown('zfsmScheduleCopiesUnder')['server']), 'Settings shows it');
same('', zfsmScheduleCopiesNot(array('dest' => 'tank/') + $job, array(), 'tank/data', array('off', '/mnt/tank/data', false), $name(1800000000)), 'a pool here named as its Copy Under is copied: the copies go on nas');
same(1, preg_match('/>Replicate Every Dataset under copies on server nas, every hour at :\d\d, keeps the newest 1 /', zfsmSchedulesHtml($cfg)), 'the list names the server');
same(array(array(), array()), array($read('ssh.log'), $calls('(snapshot|send|receive|create)')), 'no ssh, and nothing copied on saving');
// Inside nas's area Copy Under's first part is no pool: it takes the names a repeat to nas takes (zfsmReplicaTargetDest).
same(array('', '', ''), array_map(function($under) use ($cfg) { return zfsmScheduleCopiesSave($under, 'hourly', '', '', 1, $cfg, null, 'nas')['why']; }, array('2026', 'my copies', '_copies')), 'Copy Under on nas named as no pool is');
same(array('_copies/'), array_column(zfsmScheduleRead()['valid'], 'dest'), 'and read back');
same('', zfsmScheduleCopiesSave('', 'off', '', '', 0, $cfg)['why'], 'Off');
same(array(), $stored(), 'removes it');
same(true, zfsmScheduleCopiesSave('copies', 'hourly', '', '', 1, $cfg, null, 'nas')['saved'], 'saved again');
$id = array_values($stored())[0]['id'];

// The first run: in nas's area, Copy Under and tank's and tank/san's levels are made as holders there, the copies parents first, none with -F.
// Each copy gets its own connection, the run one for the levels; each ends, its key with it.
$t = 1800000000;
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t), 'a run to nas works');
$n = $name($t);
same(array('backup/replicas/tower', 'backup/replicas/tower/copies', 'backup/replicas/tower/copies/tank', 'backup/replicas/tower/copies/tank/data', 'backup/replicas/tower/copies/tank/san', 'backup/replicas/tower/copies/tank/san/kid'),
	$area(), 'in order, parents first');
same(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/copies', 'create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/copies/tank',
	'create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/copies/tank/san'), array_values(preg_grep('/holder=on/', $calls('create'))), 'three holders');
same(array(true, false), array(zfsmReplicaHolder(array('server' => ''), 'backup/replicas/tower/copies/tank/san'), zfsmReplicaHolder(array('server' => ''), 'backup/replicas/tower/copies/tank/san/kid')), 'a holder marked, the copy below it not');
same(array(2, 0), array(count($calls('receive')), count(preg_grep('/ -F /', $calls('receive')))), 'two received, none with -F');
same(array("tank/data@$n" => zfsmKeepTag('nas', 'copies/')), $snaps('tank/data'), 'its snapshot is tagged for the record and nas');
same(array(3, 3), array(count(array_keys($read('ssh.log'), 'login', true)), count(array_keys($read('ssh.log'), 'connection ends', true))), 'three connections, each ended');
same(array(), glob($work.'/state/replica-send/ssh-*') ?: array(), 'with no key left');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under copies on server nas: 2 of 2 dataset\(s\) copied$/'), 'logged');
same(array(), $read('notify.log'), 'only logged');

// The next sends changes; Keep leaves the newest 1 on each dataset. Nothing is made.
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 3600), 'a run of changes');
same(array(array(), 2), array(preg_grep('/holder=on/', $calls('create')), count(preg_grep('/^send -P -v -L -i /', $read('calls.log')))), 'each sends its changes, no holder made');
same(array("tank/data@".$name($t + 3600) => zfsmKeepTag('nas', 'copies/')), $snaps('tank/data'), 'Keep leaves the newest');

// tank/san no longer Sanoid's: its copy would go where the holder stands on nas, over which nothing is received, so it is not copied
// and the run says why; the copy below it goes on. Sanoid's again after.
$marks = $read('pool/marks');
$pool('marks', array_values(preg_grep('~^tank/san@autosnap_~', $marks, PREG_GREP_INVERT)));
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 5400), 'a run with tank/san to copy');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under copies on server nas: 2 of 3 dataset\(s\) copied\. Replication of tank\/san to copies\/tank\/san on server nas not started: backup\/replicas\/tower\/copies\/tank\/san on server nas already exists and holds no snapshot, such as an empty dataset made to hold the copies below it; nothing is received over a dataset on another server$/'), 'says why');
same(array(0, 1), array(count(preg_grep('/ -F /', $calls('receive'))), count(preg_grep('~ backup/replicas/tower/copies/tank/san/kid$~', $calls('receive')))), 'none with -F, the one below copied');
$pool('marks', array_merge($read('pool/marks'), array_values(preg_grep('~^tank/san@autosnap_~', $marks))));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 6300), 'works again once Sanoid\'s');

// nas runs an older version, whose answer to exists of the area has no holder line: none copied, no snapshot taken, said once.
file_put_contents($work.'/old_serve.php', '<?php
$command = (string)getenv("SSH_ORIGINAL_COMMAND");
if (strpos($command, "zfsm-replica 1 holder ") === 0) { fwrite(STDERR, "Not a replication request\n"); exit(1); }
exec(escapeshellarg(PHP_BINARY)." -n ".escapeshellarg('.var_export(__DIR__.'/fixtures/fake_ssh_serve.php', true).'), $out, $code);
foreach ($out as $line) if ($line !== "holder") echo $line, "\n";
exit($code);
');
putenv('ZFSM_TEST_SERVE='.$work.'/old_serve.php');
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 7200), 'a run to an older nas');
same(array('warning: Scheduled copies not made: nas runs an older ZFS Master NG, which takes no copies under a dataset: update it there. Later problems of this schedule are logged in /var/log/zfs.master.log, not notified, until a run works again.'),
	$read('notify-detail.log'), 'says to update it');
same(array(), $calls('(snapshot|send|receive|create)'), 'and copies nothing');
same(array(1, 1), array(count(array_keys($read('ssh.log'), 'login', true)), count(array_keys($read('ssh.log'), 'connection ends', true))), 'one connection, ended');
putenv('ZFSM_TEST_SERVE='.__DIR__.'/fixtures/fake_ssh_serve.php');

// nas not reached: said as ssh says it, nothing copied.
$reset();
putenv('ZFSM_TEST_SSH_FAIL=ssh: connect to host 10.0.20.11 port 22: Connection refused');
same(1, zfsmScheduleRun($id, $cfg, $t + 10800), 'a run with nas down');
putenv('ZFSM_TEST_SSH_FAIL');
same(1, $logged('/ Schedule '.$id.': Scheduled copies not made: Nothing answers SSH at 10\.0\.20\.11 port 22: .*Connection refused$/'), 'says why');
same(array(), $calls('(snapshot|send|receive|create)'), 'and copies nothing');
// Left alone a while (ZFSM_REPLICA_RETRY), as Unraid's sshd shuts out quick failed logins.
touch(zfsmReplicaDownFile('nas'), time() - ZFSM_REPLICA_RETRY);

// nas lost once tank/da's copy lands: tank/data's own connection fails, and the run asks nas nothing more, as it is left alone now.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/da\tfilesystem")));
file_put_contents($work.'/lost_serve.php', '<?php
$command = (string)getenv("SSH_ORIGINAL_COMMAND");
$lost = '.var_export($work.'/lost', true).';
if (file_exists($lost)) { file_put_contents($lost, $command."\n", FILE_APPEND); exit(255); }
passthru(escapeshellarg(PHP_BINARY)." -n ".escapeshellarg('.var_export(__DIR__.'/fixtures/fake_ssh_serve.php', true).'), $code);
if ($code === 0 && strpos($command, "zfsm-replica 1 receive") === 0) touch($lost);
exit($code);
');
putenv('ZFSM_TEST_SERVE='.$work.'/lost_serve.php');
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 12600), 'a run that loses nas');
same(array('zfsm-replica 1 hello'), $read('lost'), 'asks nas once after');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under copies on server nas: 1 of 3 dataset\(s\) copied\. .*tank\/san\/kid and the datasets after it were not copied: .*It is tried again in \d+ seconds/'), 'and says the rest wait');
putenv('ZFSM_TEST_SERVE='.__DIR__.'/fixtures/fake_ssh_serve.php');
@unlink($work.'/lost');
$pool('datasets', array_values(preg_grep('~^(backup/replicas/tower/copies/)?tank/da\t~', $read('pool/datasets'), PREG_GREP_INVERT)));
touch(zfsmReplicaDownFile('nas'), time() - ZFSM_REPLICA_RETRY);

// A repeat set in Replicate here, to the path a copy on nas has, is not due there: tank/new, left to Sanoid, gets a holder on nas
// and the copy below it goes.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/new\tfilesystem", "tank/new/kid\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/new@autosnap_2026-11-01_00:00:00_daily\t12\t11")));
$repeat = array('id' => '1a2b3c4d', 'dataset' => 'tank/new', 'dest' => 'copies/tank/new', 'server' => '', 'every' => 'hourly') + zfsmScheduleAt('hourly', '', '') + array('keep' => 0);
file_put_contents(zfsmScheduleFile(), json_encode(array_merge(array_values($stored()), array($repeat))));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 14400), 'the next run works');
same(array('backup/replicas/tower/copies/tank/new', 'backup/replicas/tower/copies/tank/new/kid'), array_values(preg_grep('~/tank/new~', $area())), 'tank/new held there, its child copied');
same(array('normal: Scheduled copies under copies on server nas: 3 of 3 dataset(s) copied. It works again after 3 run(s) with a problem.'), $read('notify-detail.log'), 'none waits');
file_put_contents(zfsmScheduleFile(), json_encode(array_values(array_filter($stored(), function($job) { return $job['id'] !== '1a2b3c4d'; }))));

// A dataset whose bookmark fits with its copy's name but not once nas's area is in it: left out, and said.
$long = 'tank/'.str_repeat('l', 55);
$pool('datasets', array_merge($read('pool/datasets'), array("$long\tfilesystem")));
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 14700), 'a run with a name too long there');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under copies on server nas: 3 of 3 dataset\(s\) copied\. Left out, as the names of each and its copy there are too long for the bookmark a copy keeps \('.preg_quote($long, '/').'\)$/'), 'leaves it out, and says so');
same(array(array(), array()), array(preg_grep('~/'.$long.'$~', $area()), preg_grep('~^'.$long.'@~', array_keys($snaps($long)))), 'nothing of it copied or taken');
$pool('datasets', array_values(preg_grep('~^'.$long.'~', $read('pool/datasets'), PREG_GREP_INVERT)));

// Whether a level is there on nas could not be read: said, and the copy below it not made.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/zz\tfilesystem", "tank/zz/kid\tfilesystem")));
$pool('marks', array_merge($read('pool/marks'), array("tank/zz@autosnap_2026-11-01_00:00:00_daily\t13\t12")));
putenv('ZFSM_TEST_LIST_FAIL=backup/replicas/tower/copies/tank/zz');
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 15000), 'a run that can\'t read a level there');
putenv('ZFSM_TEST_LIST_FAIL');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under copies on server nas: 3 of 4 dataset\(s\) copied\. whether backup\/replicas\/tower\/copies\/tank\/zz on server nas is there could not be read: cannot open .backup\/replicas\/tower\/copies\/tank\/zz.: Input\/output error$/'), 'says which');
same(array(), preg_grep('~/tank/zz~', $area()), 'and makes nothing there');
$pool('datasets', array_values(preg_grep('~^tank/zz~', $read('pool/datasets'), PREG_GREP_INVERT)));

// A rename: the copy on nas keeps its name, and no dataset here at the same path moves. A stopped run's hold for it moves to the
// copy due now, which the next run sends in full, taking the hold off once it lands; another hold stays.
$tag = function($zdest) { return zfsmKeepHoldTag(zfsmKeepTag('nas', $zdest)); };
$pool('datasets', array_merge($read('pool/datasets'), array("copies\tfilesystem", "copies/tank\tfilesystem", "copies/tank/data\tfilesystem")));
$pool('props', array_merge($read('pool/props'), array("copies\treadonly\ton")));
$last = 'tank/data@'.$name($t + 15000);
$pool('holds', array("$last\t".$tag('copies/tank/data'), "$last\tkeep"));
$reset();
same(array('succeeded' => array('tank/moved' => 0), 'failed' => array('Copy copies/tank/data on server nas' => 'any copy there keeps its name, with those below it, as no copy on another server is renamed; a run that copies tank/moved there sends it in full')),
	renameDataset('tank/data', 'tank/moved', 0, $cfg), 'the rename says the copy on nas keeps its name');
same(array(true, false), array(in_array('copies/tank/data', array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/datasets')), true), in_array('copies/tank/moved', array_map(function($line) { return explode("\t", $line)[0]; }, $read('pool/datasets')), true)),
	'the dataset here at that path stays');
$moved = 'tank/moved@'.$name($t + 15000);
same(array("$moved\tkeep", "$moved\t".$tag('copies/tank/moved')), $holds('tank/moved'), 'the hold moves to the copy due now');
same(array(), $read('ssh.log'), 'no ssh');
$reset();
same(0, zfsmScheduleRun($id, $cfg, $t + 18000), 'the next run works');
same(array('backup/replicas/tower/copies/tank/data', 'backup/replicas/tower/copies/tank/moved'), array_values(preg_grep('~/tank/(data|moved)$~', $area())), 'the old copy stays, and the new one is made');
same(array("$moved\tkeep"), $holds('tank/moved'), 'which takes the hold off');
$held = 'tank/moved@'.$name($t + 18000);
$pool('holds', array_merge($read('pool/holds'), array("$held\t".$tag('copies/tank/moved'))));
putenv('ZFSM_TEST_HOLD_FAIL=1');
same(array('Copy copies/tank/moved on server nas' => 'any copy there keeps its name, with those below it, as no copy on another server is renamed; a run that copies tank/again there sends it in full, and a stopped run\'s holds on its snapshots move to that copy, but not all its holds were: tank/again@'.$name($t + 18000).' (cannot hold snapshot \'tank/again@'.$name($t + 18000).'\': permission denied)'),
	renameDataset('tank/moved', 'tank/again', 0, $cfg)['failed'], 'a hold not moved is said');
putenv('ZFSM_TEST_HOLD_FAIL');

// tank/again's own copies here under copies, as Replicate Every Dataset's go on nas: the copy of tank on nas is not due here, so
// tank's level here is made a holder and tank/again copied into it.
$pool('datasets', array_values(preg_grep('~^copies/~', $read('pool/datasets'), PREG_GREP_INVERT)));
$own = array('id' => '5e6f7a8b') + zfsmScheduleCopiesRecord('tank/again', 'hourly', 'copies', '', '', 1);
file_put_contents(zfsmScheduleFile(), json_encode(array_merge(array_values($stored()), array($own))));
$reset();
same(0, zfsmScheduleRun('5e6f7a8b', $cfg, $t + 21600), 'a run of copies here');
same(array(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on copies/tank'), 1, array()),
	array(array_values(preg_grep('/holder=on/', $calls('create'))), count(preg_grep('~ copies/tank/again$~', $calls('receive'))), $read('ssh.log')), 'tank held here, tank/again copied, nas not asked');

// A run whose record is saved since to copy here copies no more; the dialog starts at the Copy Under and Server Default follows, or its own.
$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT);
$valid = zfsmScheduleRead()['valid'];
$still = function($job) use ($listed) { return zfsmScheduleCopiesStill($job, 'tank/san/kid', zfsmScheduleListed($listed)[0]['tank/san/kid'], $listed['name']); };
same(array('nas', false), array($still($valid[$id])['server'] ?? null, $still(array('server' => '') + $valid[$id])), 'a run whose server was changed since');
same(array(array('default', 'copies', 'nas'), array('hourly', 'copies', '')), array_map(function($zdataset) use ($valid, $listed) {
	return array_values(array_intersect_key(zfsmScheduleDialogCopies($zdataset, $valid, $listed), array('every' => 1, 'under' => 1, 'server' => 1)));
}, array('tank', 'tank/again')), 'the dialog\'s Copy Under and Server');

// A stale copy here of tank/zz2, whose copies go on nas: as no schedule copies it here, a rename leaves it.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/zz2\tfilesystem", "copies/tank/zz2\tfilesystem")));
$reset();
same(array('Copy copies/tank/zz2 on server nas' => 'any copy there keeps its name, with those below it, as no copy on another server is renamed; a run that copies tank/zz3 there sends it in full',
	'Copy copies/tank/zz2' => 'left where it is, as no schedule copies the renamed dataset or one below it there'), renameDataset('tank/zz2', 'tank/zz3', 0, $cfg)['failed'], 'a copy here of a dataset whose copies go on nas stays');

// tank/san/kid's own copies on nas under other: renaming tank/san says each copy on nas keeps its name.
$kid = array('id' => '6f7a8b9c') + zfsmScheduleCopiesRecord('tank/san/kid', 'hourly', 'other', '', '', 1, null, 'nas');
file_put_contents(zfsmScheduleFile(), json_encode(array_merge(array_values($stored()), array($kid))));
$reset();
same(array('Copy copies/tank/san on server nas', 'Copy other/tank/san on server nas'), array_keys(renameDataset('tank/san', 'tank/sanx', 0, $cfg)['failed']), 'a rename names each copy on nas');

// A repeat set in Replicate to nas as fresh, not landed yet: Copy Under fresh there is where it lands, so no holder is made there and
// the copies wait for it.
same('', zfsmScheduleCopiesSave('fresh', 'hourly', '', '', 1, $cfg, null, 'nas')['why'], 'Copy Under fresh');
$fresh = array('id' => '7a8b9c0d', 'dataset' => 'tank/again', 'dest' => 'fresh', 'server' => 'nas', 'every' => 'hourly') + zfsmScheduleAt('hourly', '', '') + array('keep' => 0);
file_put_contents(zfsmScheduleFile(), json_encode(array_merge(array_values($stored()), array($fresh))));
$reset();
same(1, zfsmScheduleRun($id, $cfg, $t + 25200), 'a run while it is due');
same(array(array(), array()), array(preg_grep('~/fresh~', $area()), $calls('(create|receive)')), 'makes and copies nothing there');
same(1, $logged('/ Schedule '.$id.': Scheduled copies under fresh on server nas: 0 of \d+ dataset\(s\) copied\. Not copied, as the copy of a dataset above it is missing /'), 'and says they wait');

// The Schedules dialog: tank/solo's own copies on nas. What is wrong is said by part, and nothing saved; Save asks nas nothing.
$pool('datasets', array_merge($read('pool/datasets'), array("tank/solo\tfilesystem")));
$dialog = function($copies, $snapshots = 'default') use ($cfg) {
	return zfsmScheduleDialogSave('tank/solo', array('every' => $snapshots, 'time' => '', 'days' => '', 'keep' => 0),
		$copies + array('every' => 'hourly', 'time' => '', 'days' => '', 'keep' => 1, 'copy_keeps' => null, 'under' => 'mine', 'server' => 'nas'), $cfg);
};
$solo = function() use ($stored) { return array_values(array_filter($stored(), function($job) { return $job['dataset'] === 'tank/solo' && zfsmScheduleCopiesUnder($job); })); };
$before = $stored();
$reset();
same(array('Nothing was saved: Replication: No server here is named gone', 'Nothing was saved: Replication: leave Copy Keeps empty: what a copy on another server keeps, its Keep Replicas decides',
	'Nothing was saved: Replication: under Copy Under, name a dataset to hold them inside the area nas gives this server, such as backup'),
	array($dialog(array('server' => 'gone'))['why'], $dialog(array('copy_keeps' => 0))['why'], $dialog(array('under' => ''))['why']), 'what is wrong is said');
same($before, $stored(), 'and nothing is saved');
$saved = $dialog(array());
same(array(array('dest' => 'mine/', 'server' => 'nas', 'every' => 'hourly', 'keep' => 1)),
	array_map(function($job) { return array_intersect_key($job, array_flip(array('dest', 'server', 'every', 'keep', 'copy_keeps'))); }, $solo()), 'saved on nas, with no Copy Keeps: '.$saved['why']);
same(1, preg_match('/^Saved: tank\/solo follows Snapshot Every Dataset, which is off: no scheduled snapshots\. Saved: a copy of each of 1 dataset\(s\) \(tank\/solo and below\) under mine on server nas every hour at :\d\d; it keeps the newest 1 of the snapshots of each dataset it copies$/D', $saved['said']), 'and said: '.$saved['said']);
same(array(), $read('ssh.log'), 'Save asks nas nothing');
$listed = zfsmScheduleCoverRead($cfg, ZFSM_SCHEDULE_COVER_WAIT);
$shown = function() use ($listed) { return zfsmScheduleDialogCopies('tank/solo', zfsmScheduleRead()['valid'], $listed); };
same(array('hourly', 'mine', 'nas', array('nas'), null, ''), array($shown()['every'], $shown()['under'], $shown()['server'], $shown()['servers'], $shown()['servers_error'] ?? null, $shown()['skipped']), 'the dialog shows it');
$reset();
same(0, zfsmScheduleRun($solo()[0]['id'], $cfg, $t + 28800), 'a run to nas');
same(array('backup/replicas/tower/mine', 'backup/replicas/tower/mine/tank', 'backup/replicas/tower/mine/tank/solo'), array_values(preg_grep('~/mine~', $area())), 'copies tank/solo inside nas\'s area');
same(array('create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/mine', 'create -o canmount=off -o readonly=on -o zfs.master:holder=on backup/replicas/tower/mine/tank'),
	array_values(preg_grep('/holder=on/', $calls('create'))), 'holders made for Copy Under and tank');

// nas removed since: it stays picked, and Snapshots saves beside the copies as saved; changed copies are refused.
same('', zfsmReplicaRemoteRemove('nas'), 'nas removed');
same(array('nas', array('nas'), 'No server here is named nas'), array($shown()['server'], $shown()['servers'], $shown()['skipped']), 'nas stays picked, and the dialog says it is gone');
same('No server here is named nas', zfsmScheduleDialogCopies('tank', zfsmScheduleRead()['valid'], $listed)['skipped'], 'as for Default, following Replicate Every Dataset on nas');
$kept = $dialog(array(), 'none');
same(array(true, array('nas'), 1), array($kept['saved'], array_column($solo(), 'server'), preg_match('/; no run copies while no server here is named nas$/', $kept['said'])),
	'Snapshots None saves beside the copies as saved, and says no run copies them: '.$kept['said']);
same('Nothing was saved: Replication: No server here is named nas', $dialog(array('keep' => 2), 'none')['why'], 'changed copies are refused');
// The list of servers unreadable: said in the dialog, and changed copies refused.
file_put_contents(zfsmReplicaSshDir().'/remotes.json', 'not a list');
same(array(array('nas'), zfsmReplicaRemotesUnreadable(), zfsmReplicaRemotesUnreadable()), array($shown()['servers'], $shown()['servers_error'] ?? null, $shown()['skipped']), 'the dialog says the servers can\'t be read, and so the copies as saved stop');
same('Nothing was saved: Replication: '.zfsmReplicaRemotesUnreadable(), $dialog(array('keep' => 2), 'none')['why'], 'and refuses changed copies');

echo "schedule copies server tests passed\n";
