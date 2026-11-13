<?php
// The diagnostics zip through the real collectors, rewrite, zip and scripts/diagnostics.php, on a made-up server whose every
// private name holds "canary" in some case: one search of the unpacked zip finds a leak.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
// This test's script runs write zips into /boot/logs: only on the tmpfs the runner mounts there (tests/run_http_inventory_tests.sh),
// and only while it is empty, so nothing there is another's. The test removes the zips it made, by their names.
$own_logs = preg_match('~^\S+ \S+ \S+ \S+ /boot/logs \S+(?: \S+)* - tmpfs ~m', (string)@file_get_contents('/proc/self/mountinfo')) === 1;
if (is_file('/etc/unraid-version') || !$own_logs || array_diff(scandir('/boot/logs'), array('.', '..'))) {
	fwrite(STDERR, "FAILED: /boot/logs must be an empty tmpfs mounted for this test\n");
	exit(1);
}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';
require_once __DIR__.'/../zfs.master/include/ZFSMDiagnostics.php';
// The log lines below are made by the plugin's own function for them.
require_once __DIR__.'/../zfs.master/include/ZFSMImpact.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

function has($needle, $text, $message) {
	if (strpos($text, $needle) !== false) return;
	fwrite(STDERR, "FAILED: $message\nExpected to find: $needle\nIn: $text\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-bundle-test-'.getmypid().'-'.bin2hex(random_bytes(4));
$root = $work.'/root';
mkdir($work.'/bin', 0700, true);
$made = array('/boot/logs/canarytower-diagnostics-20260102-1000.zip');
// The script's own pruning may have removed one already: PHP remembers the file as it last saw it.
$clear_logs = function() use (&$made) { clearstatcache(); foreach ($made as $zip) if (is_file($zip)) @unlink($zip); };
register_shutdown_function(function() use ($work, $clear_logs) { exec('rm -rf '.escapeshellarg($work)); $clear_logs(); });
// The fixture's zfs and zpool behind wrappers that give its datasets, snapshots, senders and values names a leak would show.
$real = getenv('ZFSM_TEST_BIN_DIR');
file_put_contents($work.'/bin/zfs', "#!/bin/sh\nn=\$#\nwhile [ \"\$n\" -gt 0 ]; do a=\$(printf '%s' \"\$1\" | sed 's#tank/CanaryDs One#tank/data#g; s#tank/Canary failed Vol#tank/vol#g'); shift; set -- \"\$@\" \"\$a\"; n=\$((n - 1)); done\n"
	."out=\$(".escapeshellarg($real.'/zfs')." \"\$@\" 2>&1); code=\$?\n"
	."[ -n \"\$out\" ] && printf '%s\\n' \"\$out\" | sed 's#tank/data#tank/CanaryDs One#g; s#tank/vol#tank/Canary failed Vol#g; s#/nas2#/canarysender#g; s#@daily#@Canary Daily failed copy#g; s#\\tmedia\\t#\\tCanary Role\\t#g; s#\\tstorage\\t#\\troot@canaryhost:backup/CanaryRemote\\t#g; s#^tank\\torg.example:role#tank\\tcom.canaryclient:canary_plan#'\nexit \$code\n");
file_put_contents($work.'/bin/zpool', "#!/bin/sh\n".escapeshellarg($real.'/zpool')." \"\$@\"; code=\$?\n[ \"\$1\" = get ] && [ \$code -eq 0 ] && if [ \"\$4\" = property ]; then printf 'comment\\n'; else printf 'comment\\tCanary Comment\\tlocal\\n'; fi\nexit \$code\n");
foreach (array('ps', 'diagnostics') as $fake) copy(__DIR__.'/fixtures/bin/'.$fake, $work.'/bin/'.$fake);
foreach (glob($work.'/bin/*') as $fake) chmod($fake, 0755);
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/jobs');
putenv('ZFSM_TEST_PS='.$work.'/ps.txt');
putenv('ZFSM_TEST_DIAG_LOGS='.$root.'/boot/logs');
$GLOBALS['zfsm_root'] = $root;
$GLOBALS['zfsm_uptime'] = $work.'/uptime';
file_put_contents($work.'/uptime', "864000.00 800000.00\n");
// A resume token as zfs writes one: anyone can ask zfs which snapshot it names.
$resume = '1-c740b4779-118-789c636064000310a500c4ec50360710e72765a52697303030419460caa7a515a79680647ce0f26c48f2499525a9c5405ac3c9fabfe92fcf4d2cc140cbbd9a5c0e4b1ca4e';
file_put_contents($work.'/ps.txt', "4242 30 zfs send -R tank/CanaryDs One@Canary Daily failed copy\n4243 5 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/CanaryDs One\n4244 9 rsync -a /mnt/tank/CanaryDs One/CanaryRsync/ /mnt/x\n4245 3 zfs send -P -v -t $resume\n");

$put = function($path, $text) use ($root) {
	if (!is_dir(dirname($root.$path))) mkdir(dirname($root.$path), 0755, true);
	file_put_contents($root.$path, $text);
};
$private_key = "-----BEGIN OPENSSH PRIVATE KEY-----\nCanaryPrivateKeyBytesCanaryPrivateKeyBytes\n-----END OPENSSH PRIVATE KEY-----";
$public_key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICanaryKeyCanaryKeyCanaryKeyCanaryKeyCanaryKeyCana';
// The lines the plugin writes, in its own formats: the action lines through zfsmActionLogLines, the job lines of
// include/ZFSMRewrite.php, ZFSMReplicate.php and ZFSMReplicaServe.php, the refusals of ZFSMReplicaPlan.php and ZFSMImpact.php's busy text.
$action = function($title, $subject, $failed = null) {
	return zfsmActionLogLines($title, $failed === null ? array('succeeded' => array($subject => 0)) : array('failed' => array($subject => $failed)))[0];
};
$log_lines = array(
	$action('ZFS Snapshot Destroy', 'tank/CanaryGone@CanarySnap', "cannot destroy 'tank/CanaryGone@CanarySnap': dataset is busy"),
	'Rewrite of tank/CanaryDs One started: zfs rewrite -r tank/CanaryDs One',
	$action('ZFS Dataset Destroy', 'tank/Canary Old Stuff'),
	'Replication of tank/CanaryDs One@CanarySnapA to tank/CanaryCopy (changes since CanaryBase) started: zfs send -i tank/CanaryDs One@CanaryBase tank/CanaryDs One@CanarySnapA | zfs receive -s tank/CanaryCopy',
	'Replication request from 203.0.113.77 refused: tank/CanaryElse/x is not inside tank/CanaryDs One/canarysender (zfsm-replica 1 exists '.bin2hex('tank/CanaryDs One/CanaryHex').')',
	'Convert: cloning /mnt/tank/CanaryDs One/Canary Artist - Album (2019) failed, so rsync copies it: cp failed',
	'Replication from 2001:db8::77 into tank/CanaryDs One/canarysender/CanaryIn received',
	$action('ZFS Replication', 'tank/CanaryDs One', 'ssh: connect to host canarynas port 22: Connection refused, as root@canaryhost:backup/CanaryRemote'),
	$action('ZFS Dataset Destroy', 'tank/Canary failed Vol'),
	'ZFS Master NG dataset inventory failed for otherpool: zfs timed out',
	$action('ZFS Snapshot Destroy', 'tank/CanaryDs One@CanaryA,CanaryB,CanaryC'),
	$action('ZFS Dataset Destroy', 'tank/Canary Gone finished movies'),
	'Replication of exportedpool/CanaryVol@CanaryA to exportedpool/CanaryCopy (in full) started: zfs send -P -v exportedpool/CanaryVol@CanaryA | zfs receive -s -u -o volmode=none exportedpool/CanaryCopy',
	'Replication of tank/CanaryDs One@CanarySnapA to tank/CanaryCopy (carried on from where it stopped) started: zfs send -P -v -t '.$resume.' | zfs receive -s tank/CanaryCopy',
	// Names with nothing to mark them: a container and a process in the busy text, a refusal's example, a name that was refused.
	$action('ZFS Dataset Destroy', 'tank/CanaryDs One', "cannot destroy 'tank/CanaryDs One': dataset is busy. Using it now: container canarycontainer has it mounted; process canary_backup.sh (pid 9) may be using it through /mnt/user"),
	$action('ZFS Replication', 'tank/CanaryDs One', 'The copy needs a dataset name inside a pool, such as backup/CanaryLeaf'),
	$action('ZFS Dataset Destroy', 'tank/CanaryDs One', "cannot destroy 'tank/CanaryDs One': dataset is busy. Using it now: container chrome has it mounted"),
	$action('ZFS Replication', 'tank/CanaryDs One', "The copy goes inside bakup/CanaryTyped, which doesn't exist"),
	$action('ZFS Dataset Creation', "tank/Canary's CanaryFiles (CanarySmith 2024)", 'Invalid dataset name'),
	'ZFS Master NG backend exception: '.$private_key,
);
$build = function() use ($work, $root, $put, $private_key, $public_key, $log_lines) {
	exec('rm -rf '.escapeshellarg($root).' '.escapeshellarg($work.'/jobs'));
	clearstatcache(true);
	$put('/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\nrefresh_interval=\"60\"\nexclusion=\"^.*CanaryPattern.*$\"\nmystery=\"CanaryValue one\n\nCanaryValue two\"\nsnap_prefix=\"CanaryPrefix_\"\ndirectory_listing=\"tank/CanaryDs One\ntank/CanaryGone Two\nghostpool/CanaryGhost\nCanaryBare\nCanaryClient/photos\"\nsize_units=\"Canary Customer\"\nCanary stray note\n");
	$put('/boot/config/ssh/root/authorized_keys', 'restrict,from="203.0.113.77",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php canarysender" '.$public_key." Canary Laptop\n");
	$put('/var/log/syslog', "Jan  2 10:00:00 CanaryTower zfs.master[41]: ".$log_lines[0]."\n");
	// Through the plugin's own writer, so each line is as the plugin's log holds it: one line, its quotes replaced, cut at its limit.
	foreach ($log_lines as $line) zfsmLog($line, LOG_NOTICE);
	$put('/var/log/phplog', "[02-Jan-2026] PHP Warning: scandir(/mnt/tank/CanaryDs One/Canary's Stuff): Failed to open directory in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9\n");
	$put('/var/log/nginx/error.log', "2026/01/02 10:00:00 [error] 1#1: *1 FastCGI sent in stderr: \"PHP message: x\" while reading, client: 203.0.113.77, server: , request: \"POST /plugins/zfs.master/backend/ZFSMAdmin.php?zdataset=tank%2FCanaryQuery&csrf_token=CANARYTOKEN HTTP/1.1\", host: \"canary.example.com\", referrer: \"http://canary.example.com/Main\"\n");
	$put('/etc/ssh/ssh_host_ed25519_key.pub', 'ssh-ed25519 '.base64_encode(str_repeat('k', 51))." root@CanaryTower\n");
	$put('/etc/unraid-version', "version=\"7.4.0\"\n");
	$put('/proc/self/mounts', "tank /mnt/tank zfs rw 0 0\n");
	$put('/proc/self/mountinfo', "102 1 0:45 / /mnt/tank rw shared:50 - zfs tank rw\n104 102 0:47 / /mnt/tank/Canary\\040Mount rw shared:52 - zfs tank/Canary\\040Mount rw\n105 1 0:48 / /mnt/mountedpool/CanaryMounted rw shared:53 - zfs mountedpool/CanaryMounted rw\n");
	$put('/var/local/emhttp/var.ini', "mdState=\"STARTED\"\nfsState=\"Started\"\nNAME=\"CanaryNas\"\n");
	$put('/var/local/emhttp/disks.ini', "[\"tank\"]\nname=\"tank\"\ntype=\"Cache\"\nfsType=\"zfs\"\n[\"otherpool\"]\nname=\"otherpool\"\ntype=\"Cache\"\nfsType=\"zfs\"\n");
	mkdir($root.'/tmp');
};
$settings = array('refresh_interval' => '60', 'znapzend_data' => '1', 'exclusion' => '^.*CanaryPattern.*$', 'mystery' => 'CanaryValue one', 'snap_prefix' => 'CanaryPrefix_', 'directory_listing' => "tank/CanaryDs One\ntank/CanaryGone Two\nghostpool/CanaryGhost\nCanaryBare\nCanaryClient/photos", 'replica_accept' => 'tank/CanaryDs One', 'size_units' => 'Canary Customer');
// A run on that server, rewritten and packed: [the zip's path or null, why not, the folder its zip was unpacked into].
$bundle = function($name, $unraid = true, $fresh = true) use ($work, $build, $settings) {
	if ($fresh) $build();
	$dir = $work.'/'.$name;
	same(true, zfsmDiagRun($dir, zfsmDiagContext(array('general' => $settings), $unraid)), 'every file of '.$name.' is written');
	same(true, zfsmDiagAnonymizeDir($dir), 'and rewritten');
	list($zip, $why) = zfsmDiagPack($dir);
	if ($zip !== null) {
		exec('unzip -q '.escapeshellarg($zip).' -d '.escapeshellarg($dir.'-unpacked').' 2>&1', $said, $code);
		same(0, $code, 'the zip of '.$name.' unpacks: '.implode(' ', $said));
	}
	return array($zip, $why, $dir.'-unpacked');
};
// Every file under $dir but Unraid's own zip, as path => text.
$ours = function($dir) {
	$files = array();
	foreach (zfsmDiagFilesUnder($dir) as $file) if (preg_match('~^unraid/[^/]+\.zip$~D', $file) !== 1) $files[$file] = file_get_contents($dir.'/'.$file);
	return $files;
};
$leaks = function($files) {
	$found = array();
	foreach ($files as $file => $text) if (stripos($file.$text, 'canary') !== false) $found[] = $file.': '.implode(' | ', preg_grep('/canary/i', explode("\n", $text)));
	return $found;
};

// The zip: no private thing in the plugin's files, and each kind of thing stands as its token.
list($zip, $why, $out) = $bundle('full');
same(1, preg_match('~^'.preg_quote($root, '~').'/boot/logs/zfs\.master-diagnostics-\d{8}-\d{6}\.zip$~D', (string)$zip), 'the zip is in /boot/logs under the plugin\'s name: '.$zip.$why);
same(array(), glob($root.'/boot/logs/*.part'), 'and nothing of its packing is left beside it');
$files = $ours($out);
same(array(), $leaks($files), 'no name, address, host or key is in the plugin\'s files');
foreach (array_merge(array_column(zfsmDiagCollectors(), 0), array('names.txt', 'README.txt', 'summary.txt', 'collectors.txt')) as $file) same(true, isset($files[$file]), $file.' is in the zip');
// The token the run gave $name: it must have met the name, and names.txt must have the token with the name's shape.
$token = function($name) use ($files) {
	same(true, isset($GLOBALS['zfsm_diag_names']['tokens'][$name]), 'the run gave a token to '.$name);
	has(zfsmDiagToken($name)."\t".zfsmDiagShape($name)."\n", $files['names.txt'], 'names.txt has that token and the name\'s shape');
	return zfsmDiagToken($name);
};
$ds = $token('CanaryDs One');
same(true, strpos($files['names.txt'], $ds."\t12 characters, 12 bytes, 1 space(s), mixed case\n") !== false, 'a name\'s shape is its length, its spaces and its case');
has("tank/$ds\tfilesystem\t", $files['zfs/datasets.txt'], 'a dataset is its pool and a token');
has("\t/mnt/tank/$ds\t", $files['zfs/datasets.txt'], 'the same token in its mount point');
has('["tank/'.$ds.'","tank/'.$token('CanaryGone Two').'","'.$token('ghostpool').'/'.$token('CanaryGhost').'","'.$token('CanaryBare').'","'.$token('CanaryClient').'/'.$token('photos').'"]', $files['plugin/settings-effective.txt'],
	'and in the settings that name it; a first word that is no pool this run knows is a token, alone or before a slash');
has('Directory Listing names a dataset that does not exist: '.zfsmDiagToken('CanaryBare')."\n", $files['summary.txt'], 'as it is in its finding');
has('Directory Listing names a dataset that does not exist: '.$token('ghostpool').'/'.$token('CanaryGhost')."\n", $files['summary.txt'], 'a pool only a setting names is no pool: its name is a token too');
has("\t-\tzfs\tmountedpool/".$token('CanaryMounted')."\trw\n", $files['zfs/mounts.txt'], 'and so does a pool only a mount names');
has("4243\t5\t/usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/$ds\n", $files['plugin/jobs.txt'], 'a system path stays as it is, and the dataset after it is a token');
has('Directory Listing names a dataset that does not exist: tank/'.zfsmDiagToken('CanaryGone Two')."\n", $files['summary.txt'], 'a finding names a dataset by its token');
has('"name":"tank/'.$ds.'"', $files['plugin/inventory.json.txt'], 'and so does the Main tab\'s read');
has('"org.example:role":"'.zfsmDiagToken('Canary Role').'"', $files['plugin/inventory.json.txt'], 'where a user property\'s value is a token too');
has("tank/$ds\torg.example:role\t".$token('Canary Role')."\tlocal\n", $files['zfs/user-properties.txt'], 'a value its owner wrote is a token');
has("tank\tcom.".$token('canaryclient').':'.$token('canary')."_plan\t".$token('root@canaryhost:backup/CanaryRemote')."\tlocal\n", $files['zfs/user-properties.txt'], 'and so is one that names another server and its dataset; the words of a property\'s name that are its owner\'s are tokens');
has('"com.'.zfsmDiagToken('canaryclient').':'.zfsmDiagToken('canary').'_plan":', $files['plugin/inventory.json.txt'], 'as they are where the Main tab\'s read names the property');
has("comment\t".$token('Canary Comment')."\tlocal\n", $files['zfs/zpool-get-all.txt'], 'a pool\'s comment is a token');
$mount = $token('Canary Mount');
has("\t/mnt/tank/$mount\trw\tshared:52\t-\tzfs\ttank/$mount\trw\n", $files['zfs/mounts.txt'], 'a mount whose name holds a space is one token, in its folder and its dataset');
has("refresh_interval=\"60\"\nexclusion = ".$token('^.*CanaryPattern.*$')."\nmystery = ".$token('CanaryValue one')."\nsnap_prefix = ".$token('CanaryPrefix_')."\ndirectory_listing = ".$token('tank/CanaryDs One')."\nsize_units = ".$token('Canary Customer')."\n".$token('Canary stray note')."\n", $files['plugin/settings-saved.cfg.txt'],
	'the saved settings keep a known setting at the value the plugin uses; a pattern, a prefix, a list of datasets, an unknown setting, a value the plugin corrects and a line that sets nothing are each one token, with the lines a value runs on to left out');
has("snap_prefix\t\"".zfsmDiagToken('CanaryPrefix_')."\"\t\"\"\tsaved\n", $files['plugin/settings-effective.txt'], 'the prefix its owner wrote is a token where the settings are listed as used');
has($token('canarysender').' ('.$token('Canary Laptop').'): restrict: ok, from: ok, name: ok', $files['plugin/replication-receive.txt'], 'a sender and its key\'s comment are tokens');
has("tank/$ds/".zfsmDiagToken('canarysender')."\texec\toff\tlocal\n", $files['plugin/replication-receive.txt'], 'the same token in the sender\'s area');
$log = $files['plugin/log.txt'];
$gone = 'tank/'.$token('CanaryGone').'@'.$token('CanarySnap');
has("ZFS Snapshot Destroy: $gone failed: cannot destroy ?$gone?: dataset is busy\n", $log, 'a name that is gone is a token too, and the words after it stay');
has('Jan  2 10:00:00 '.$token('CanaryTower').' zfs.master[41]: ZFS Snapshot Destroy: '.$gone, $log, 'the server\'s own name, as the system log has it, is a token');
has("Rewrite of tank/$ds started: zfs rewrite -r tank/$ds\n", $log, 'a known name with a space ends where it ends');
has("ZFS Dataset Destroy: tank/".$token('Canary failed Vol')." succeeded\n", $log, 'a known name is whole though one of the plugin\'s own words is in it');
has("ZFS Dataset Destroy: tank/".$token('Canary Old Stuff')." succeeded\n", $log, 'a name with a space that is gone ends at the plugin\'s own word');
has("ZFS Dataset Destroy: tank/".$token('Canary Gone finished movies')." succeeded\n", $log, 'and one of the plugin\'s words in it ends it only with what follows that word in a log line');
has("ZFS Master NG dataset inventory failed for otherpool: zfs timed out\n", $log, 'a pool Unraid lists keeps its name where it stands alone, imported or not');
has('ssh: connect to host '.$token('canarynas').' port 22: Connection refused, as '.$token('root@canaryhost:backup/CanaryRemote')."\n", $log, 'this server\'s name as Unraid has it is a token; another server, its user and its dataset are one token, the one they have as a property\'s value');
has("ZFS Snapshot Destroy: tank/$ds@".$token('CanaryA').','.$token('CanaryB').','.$token('CanaryC')." succeeded\n", $log, 'each snapshot of a list is a token');
$exported = 'exportedpool/'.$token('CanaryVol').'@'.zfsmDiagToken('CanaryA');
has("Replication of $exported to exportedpool/".$token('CanaryCopy')." (in full) started: zfs send -P -v $exported | zfs receive -s -u -o volmode=none exportedpool/".zfsmDiagToken('CanaryCopy')."\n", $log, 'a pool nothing lists is known once a log line names its dataset, in the command the line repeats too');
has(' started: zfs send -P -v -t (a resume token, left out) | zfs receive -s tank/'.zfsmDiagToken('CanaryCopy')."\n", $log, 'a resume token is left out');
has(' to tank/'.zfsmDiagToken('CanaryCopy').' (changes since '.$token('CanaryBase').') started: zfs send -i tank/'.$ds.'@'.zfsmDiagToken('CanaryBase'), $log, 'a snapshot the plugin names by its short name alone is a token, the one it has after its dataset');
has(' refused: tank/'.$token('CanaryElse').'/'.$token('x').' is not inside tank/'.$ds.'/'.zfsmDiagToken('canarysender').' (zfsm-replica 1 exists (as hex) tank/'.$ds.'/'.$token('CanaryHex').')', $log, 'a dataset a request names as hex is read and replaced');
has('Convert: cloning /mnt/tank/'.$ds.'/'.$token('Canary Artist - Album (2019) failed').','.$token(' so rsync copies it: cp failed')."\n", $log, 'a folder\'s path takes the rest of its line');
same(array(1, 1), array(preg_match('/Replication request from ip-[0-9a-f]{8} refused: /', $log), preg_match('~Replication from ip-[0-9a-f]{8} into tank/'.$ds.'/'.zfsmDiagToken('canarysender').'/'.$token('CanaryIn').' received\n~', $log)), 'an address is a token: '.$log);
// No rule marks these names: they are tokens because they are no words of the plugin's.
has('Using it now: container '.$token('canarycontainer').' has it mounted; process '.$token('canary_backup').'.sh (pid 9) may be using it through /mnt/user'."\n", $log, 'a container and a process in the busy text are tokens');
has('such as backup/'.$token('CanaryLeaf')."\n", $log, 'the name in a refusal\'s example is a token');
has('Using it now: container '.$token('chrome')." has it mounted\n", $log, 'a word of a browser\'s report is a name in the log: only that report takes those words');
has('The copy goes inside '.$token('bakup').'/'.$token('CanaryTyped').', which doesn?t exist'."\n", $log, 'a dataset the user typed, of a pool there is not, is tokens');
has('ZFS Dataset Creation: tank/'.$token('Canary').'?s '.$token('CanaryFiles').' ('.$token('CanarySmith').' 2024) failed: Invalid dataset name'."\n", $log, 'a name that was refused is tokens past the character that ended it for the rules');
has("ZFS Master NG backend exception: (a private key, left out)\n", $log, 'a private key is left out');
has('request: "POST /plugins/zfs.master/backend/ZFSMAdmin.php?(left out) HTTP/1.1", host: (left out), referrer: (left out)', $files['plugin/php-errors.txt'], 'a request\'s query and the site\'s name are left out');
has('scandir(/mnt/tank/'.$ds.'/'.$token('Canary\'s Stuff): Failed to open directory').' in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9'."\n", $files['plugin/php-errors.txt'], 'a folder in a PHP error is replaced, and where PHP raised it stays');
has("4242\t30\tzfs send -R tank/$ds@".$token('Canary Daily failed copy')."\n", $files['zfs/processes.txt'], 'a running command\'s dataset and snapshot are tokens, a known snapshot whole');
has("4245\t3\tzfs send -P -v -t (a resume token, left out)\n", $files['zfs/processes.txt'], 'and its resume token is left out');
has("zfs/timings.txt\tok\t", $files['collectors.txt'], 'the files\' own names stay');
has("names.txt       each token in these files", $files['README.txt'], 'README.txt says what names.txt is');
// Unraid's own zip: as Unraid made it, and moved, so it is not left on the flash drive as well.
same("Unraid made this, and its syslog names tank/CanaryDs One\n", @file_get_contents($out.'/unraid/canarytower-diagnostics-20260102-1000.zip'), 'Unraid\'s zip is in the zip byte for byte');
same(array(basename($zip)), array_values(array_diff(scandir($root.'/boot/logs'), array('.', '..'))), 'and no longer in /boot/logs, where only the plugin\'s zip is');
has('Unraid\'s own diagnostics are the zip in this folder, 57 bytes, as Unraid made them.', $files['unraid/diagnostics.txt'], 'its file says so');

// A pool named as one of the folders: the files' own names are not taken for its datasets, and its datasets still are.
zfsmDiagStart();
array_map('zfsmDiagKnowPool', array('zfs', 'tank'));
same("zfs/timings.txt\tok\nzfs/".zfsmDiagToken('secret')."\n", zfsmDiagAnonText("zfs/timings.txt\tok\nzfs/secret\n"), 'a pool named zfs');
mkdir($work.'/readme');
zfsmDiagWrite($work.'/readme', 'README.txt', zfsmDiagReadme());
same(array(true, zfsmDiagReadme()), array(zfsmDiagAnonymizeDir($work.'/readme'), file_get_contents($work.'/readme/README.txt')), 'README.txt, which holds no name, is written again after the rewrite: its "zfs/" is not that pool');
// What is no address, host or path stays as it is.
$plain = "feature@async_destroy\tenabled\nscan: scrub repaired 0B in 00:01:02 on 2026.11.11.3; ZFSMOperations::run v1.2.3.4 300.1.2.3\n7 * * * * /usr/local/emhttp/plugins/zfs.master/scripts/alerts.php check &> /dev/null\nhttps://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta/zfs.master.plg\n";
same($plain, zfsmDiagAnonText($plain), 'a pool feature, a time, a version, a class, a system path and a link are left alone');
same('/dev/zvol/zfs/'.zfsmDiagToken('vm')."\n/dev/sdb1", zfsmDiagAnonText("/dev/zvol/zfs/vm\n/dev/sdb1"), 'a volume\'s device names its dataset; a disk\'s does not');
zfsmDiagStart();
$said = 'Snapshot: gonepool/a@b failed: I/O error on read/write; cannot open \'otherpool/a\'; zfs list gonepool/a';
zfsmDiagLearnPools($said);
same('Snapshot: gonepool/'.zfsmDiagToken('a').'@'.zfsmDiagToken('b').' failed: I/O error on read/write; cannot open \'otherpool/'.zfsmDiagToken('a').'\'; zfs list gonepool/'.zfsmDiagToken('a'), zfsmDiagAnonText($said),
	'a pool nothing lists is learned from a line of the plugin\'s and from a name zfs quotes, then found anywhere; no other word with a slash is taken for one');
// Property rows: a value can hold tabs and line breaks, so the rows are read against the same read without its values.
$heads = "tank/a\torg.znapzend:src_plan\ntank/a\tcom.clientacme:owner_tab\ntank/a\tcompression\ntank/a\tmountpoint\ntank/a\tkeylocation\ntank/b\tkeylocation\ntank/b\tsharenfs\ntank/b\tcompression\n";
$values = "tank/a\torg.znapzend:src_plan\t1d=>1h,1w=>1d\tlocal\ntank/a\tcom.clientacme:owner_tab\tfirst\tSecret After\tlocal\ntank/a\tcompression\tlz4\tlocal\ntank/a\tmountpoint\t/mnt/tank/My Data\tlocal\n"
	."tank/a\tkeylocation\thttps://user:pw@keys.example/a\tlocal\ntank/b\tkeylocation\tprompt\treceived\ntank/b\tsharenfs\trw=@10.0.0.0/24\tlocal\ntank/b\tcompression\tPrivate Owner\tlocal\n";
same("tank/a\torg.znapzend:src_plan\t1d=>1h,1w=>1d\tlocal\ntank/a\tcom.".zfsmDiagToken('clientacme').":owner_tab\t".zfsmDiagToken("first\tSecret After")."\tlocal\ntank/a\tcompression\tlz4\tlocal\ntank/a\tmountpoint\t/mnt/tank/My Data\tlocal\n"
	."tank/a\tkeylocation\t(set)\tlocal\ntank/b\tkeylocation\tprompt\treceived\ntank/b\tsharenfs\t".zfsmDiagToken('rw=@10.0.0.0/24')."\tlocal\ntank/b\tcompression\t".zfsmDiagToken('Private Owner')."\tlocal\n", zfsmDiagFreeRows($values, $heads),
	'a user property\'s value and a share\'s options are tokens, a plan and OpenZFS\'s own values stay, a value with a tab is hidden whole, a key\'s place is only "set"; a word of a user property\'s name that is not the plugin\'s is a token; a value with a space is no value of OpenZFS\'s');
$left_out = "(a value here holds a line break, or a property changed while they were read: the values are left out)\ntank/a\tcom.".zfsmDiagToken('clientacme').":owner_tab\ntank/a\tcompression\n";
$two = "tank/a\tcom.clientacme:owner_tab\ntank/a\tcompression\n";
same(array($left_out, $left_out, $left_out, $left_out, $left_out), array(
	zfsmDiagFreeRows("tank/a\tcom.clientacme:owner_tab\tline one\nSecret Two\tlocal\ntank/a\tcompression\tlz4\tlocal\n", $two),
	zfsmDiagFreeRows("tank/a\tcom.clientacme:owner_tab\tfirst\tlocal\ntank/a\tcompression\tPrivate\tlocal\ntank/a\tcompression\tlz4\tlocal\n", $two),
	zfsmDiagFreeRows("tank/a\tcom.clientacme:owner_tab\tfirst\ntank/a\tcompression\tPrivate\tlocal\n", $two),
	zfsmDiagFreeRows("tank/a\tcom.clientacme:owner_tab\tfirst\tlocal\ntank/a\tcompressratio\t1.00x\t-\n", $two),
	zfsmDiagFreeRows("tank/a\tcom.clientacme:owner_tab\tfirst\tlocal\n", $two),
), 'no value is shown when a line more than the rows is there (a value with a line break, though each of its lines looks like a row), a line with no source, another row than the one read, or a row less');
same(array('', "(a value here holds a line break, or a property changed while they were read: the values are left out)\n"), array(zfsmDiagFreeRows('', ''), zfsmDiagFreeRows("tank/a\tcompression\tlz4\tlocal\n", '')), 'no rows is no text; rows where the read of their names gave none are not shown');
same("size\t1.00T\t-\ncomment\t".zfsmDiagToken('Private pool')."\tlocal\norg:".zfsmDiagToken('contact')."\t".zfsmDiagToken('Alice Smith')."\tlocal\nfeature@lz4_compress\tactive\tlocal\nunsupported@com.example:x\tinactive\tlocal\n",
	zfsmDiagFreeRows("size\t1.00T\t-\ncomment\tPrivate pool\tlocal\norg:contact\tAlice Smith\tlocal\nfeature@lz4_compress\tactive\tlocal\nunsupported@com.example:x\tinactive\tlocal\n", "size\ncomment\norg:contact\nfeature@lz4_compress\nunsupported@com.example:x\n"),
	'a pool\'s comment and user properties are hidden as a dataset\'s are; its features are OpenZFS\'s own');
zfsmDiagStart();
array_map('zfsmDiagKnowPool', array('zfs', 'tank'));
same('/mnt/user/'.zfsmDiagToken('Share').'/'.zfsmDiagToken('x'), zfsmDiagAnonText('/mnt/user/Share/x'), 'Unraid\'s own folder under /mnt stays, a share does not');
$shared = array('/tmp/ClientA/notes', '/boot/config/shares/Private.cfg', '/var/lib/Secret Thing', '/root/ClientB');
same(array('/tmp/'.zfsmDiagToken('ClientA').'/'.zfsmDiagToken('notes'), '/boot/'.zfsmDiagToken('config').'/'.zfsmDiagToken('shares').'/'.zfsmDiagToken('Private.cfg'), '/var/'.zfsmDiagToken('lib').'/'.zfsmDiagToken('Secret Thing'), '/root/'.zfsmDiagToken('ClientB')),
	array_map('zfsmDiagAnonText', $shared), 'under /tmp, /boot, /var and /root anyone keeps files: a path there that is not the plugin\'s own is a folder\'s');
$test_root = $GLOBALS['zfsm_root'];
$GLOBALS['zfsm_root'] = '';
zfsmDiagStart();
$private = array('/boot/config/plugins/ClientA/notes', '/var/log/syslog-private', '/boot/logs/tower-diagnostics-20260102-1000.zip', '/boot/config/plugins/zfs.masterClientA/notes', '/boot/config/plugins/zfs.master.plgClient',
	'/tmp/zfs.master-diag.ClientA/notes', '/var/run/zfs.master.ClientA', '/var/log/packages/zfs.master-ClientA');
$tokens = function($path) { return preg_replace_callback('~[^/]+~', function($part) { return zfsmDiagToken($part[0]); }, $path); };
same(array('/boot'.$tokens('/config/plugins/ClientA/notes'), '/var'.$tokens('/log/syslog-private'), '/boot'.$tokens('/logs/tower-diagnostics-20260102-1000.zip'), '/boot'.$tokens('/config/plugins/zfs.masterClientA/notes'), '/boot'.$tokens('/config/plugins/zfs.master.plgClient'),
	'/tmp'.$tokens('/zfs.master-diag.ClientA/notes'), '/var'.$tokens('/run/zfs.master.ClientA'), '/var'.$tokens('/log/packages/zfs.master-ClientA')),
	array_map('zfsmDiagAnonText', $private), 'a path under a folder the collectors list is not the plugin\'s own for that: only the listed path, a log that was turned over, and what is inside the plugin\'s own; a name that only starts as the plugin\'s does is not');
$plugins_own = '/boot/config/plugins/ /boot/config/plugins/zfs.master/zfs.master-2026.11.11.3-x86_64-1.txz /var/log/syslog.2 /var/run/zfs.master.rewrite/123.lock /tmp/zfs.master-diag.0123456789abcdef/plugin/log.txt /boot/logs'
	.' /var/log/packages/zfs.master-2026.11.11.3-x86_64-1 /var/run/zfs.master.alerts.json.lock /var/run/zfs.master.diagnostics/pack.lock';
same($plugins_own, zfsmDiagAnonText($plugins_own), 'the plugin\'s own paths stay');
// Every job folder the source names (zfsmJobDir, zfsmJobLockFile) is one of the names the plugin makes: a new job is added there too.
preg_match_all("~zfsmJob(?:Dir\\('|LockFile\\(\\$\\w+, ')([a-z-]+)'~", implode('', array_map('file_get_contents', glob(dirname(__DIR__).'/zfs.master/{include,backend,scripts}/*.php', GLOB_BRACE))), $jobs);
$job_dirs = array_map(function($job) { return '/var/run/zfs.master.'.$job.'/x.lock'; }, array_unique($jobs[1]));
same(array(true, $job_dirs), array(count($job_dirs) >= 4, array_map('zfsmDiagAnonText', $job_dirs)), 'each job\'s folder stays: '.implode(' ', $job_dirs));
$GLOBALS['zfsm_root'] = $test_root;
zfsmDiagStart();
array_map('zfsmDiagKnowPool', array('zfs', 'tank'));
$kept = $root.'/var/log/syslog.1 '.$root.'/boot/config/plugins/zfs.master/zfs.master.cfg '.$root.'/var/run/zfs.master.alerts.json.lock /usr/local/emhttp/plugins/zfs.master/x.php /dev/null';
same($kept, zfsmDiagAnonText($kept), 'a path the collectors list, and what its name starts, stays as it is');
same('{"tank/'.zfsmDiagToken('a').'":["/mnt/zfs/'.zfsmDiagToken('b c').'",5,true]}', zfsmDiagAnonJson('{"tank\/a":["\/mnt\/zfs\/b c",5,true]}'), 'JSON is read, so a name written with \\/ is found, in a key too');
same('{"tank/'.zfsmDiagToken('a').'": broken', zfsmDiagAnonJson('{"tank\/a": broken'), 'and text that is no JSON is still searched, its \\/ read as /');
same('{"org.example:host":"'.zfsmDiagToken('10.0.20.11').'","org.example:keep":"5","org.example:size":"10G","org.example:who":"'.zfsmDiagToken('123Bob').'"}', zfsmDiagAnonJson('{"org.example:host":"10.0.20.11","org.example:keep":"5","org.example:size":"10G","org.example:who":"123Bob"}'),
	'of a user property\'s values only a number, alone or with a unit of size or time, stays: an address and a word that starts with digits are tokens');
same('(zfsm-replica 1 exists (as hex) gonepool/'.zfsmDiagToken('Secret Share').'/'.zfsmDiagToken('x').')', zfsmDiagAnonText('(zfsm-replica 1 exists '.bin2hex('gonepool/Secret Share/x').')'), 'a request for a dataset of a pool nothing knows is not written out as text');
same(array('on', '1d=>1h,1w=>1d', '5', '-', '10G', '1.5x', zfsmDiagToken('my media'), zfsmDiagToken('123Bob'), zfsmDiagToken('2024Tax')), array_map('zfsmDiagFreeValue', array('on', '1d=>1h,1w=>1d', '5', '-', '10G', '1.5x', 'my media', '123Bob', '2024Tax')),
	'of the values an owner can write, a switch, a plan and a number with a unit stay; a word that starts with digits is no number');
// A setting or a finding that names a dataset with nothing around it: its pool stays, and one word alone only when it is a pool.
same(array('tank/'.zfsmDiagToken('a b').'/'.zfsmDiagToken('c'), 'tank', zfsmDiagToken('Media'), zfsmDiagToken('Secret Team').'/'.zfsmDiagToken('photos'), zfsmDiagToken('ghostpool').'/'.zfsmDiagToken('x')),
	array_map('zfsmDiagNamed', array('tank/a b/c', 'tank', 'Media', 'Secret Team/photos', 'ghostpool/x')), 'a dataset named alone keeps its pool; a first word that is no pool this run knows is a token, alone or before a slash');
zfsmDiagKnowPool('zzvault-zzbackup');
$pooled = 'imported zzvault-zzbackup, then zzvault-zzbackup/'.zfsmDiagToken('x').' and zzvault-zzbackup.';
same(array($pooled, zfsmDiagToken('xzzvault').'-'.zfsmDiagToken('zzbackup')), array(zfsmDiagKnownWords($pooled), zfsmDiagKnownWords('xzzvault-zzbackup')), 'a pool\'s name passes the second net whole, - and . and all, and only whole');
// The second net, for running text: a word the plugin itself does not use is a token; numbers, tokens and fingerprints pass.
$sentence = 'Using it now: container plexserver42 has it mounted (pid 9), 10G in 30s, the 2nd of 3; '.zfsmDiagToken('kept').' SHA256:'.str_repeat('Ab+/', 10).'xyz d41d8cd98f00b204e9800998ecf8427e';
same(str_replace('plexserver42', zfsmDiagToken('plexserver42'), $sentence), zfsmDiagKnownWords($sentence), 'a word that is no word of the plugin\'s is a token; its own words, numbers, tokens and checksums stay');
same(array('container '.zfsmDiagToken('safari').' has it mounted', 'Mozilla/5.0 Safari/537.36'), array(zfsmDiagKnownWords('container safari has it mounted'), zfsmDiagKnownWords('Mozilla/5.0 Safari/537.36', ZFSM_DIAG_BROWSER_WORDS)),
	'a browser\'s words pass only in the browser\'s report: a container named for one is a token elsewhere');
same(zfsmDiagToken('name-0123abcd').' SHA256:'.zfsmDiagToken('Zebra77'), zfsmDiagKnownWords('name-0123abcd SHA256:Zebra77'), 'text that only looks like a token or a fingerprint is not one: a token is one this run made, a fingerprint has its length');
same(array('key (a key, SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode(substr($public_key, 12)), true)), '=').') '.zfsmDiagToken('canaryuser@canaryhost'), "cut short (a private key, left out)\nthe next line", 'at backup:tank/'.zfsmDiagToken('Colon')),
	array(zfsmDiagAnonText('key '.$public_key.' canaryuser@canaryhost'), zfsmDiagAnonText("cut short -----BEGIN OPENSSH PRIVATE KEY-----; CutKeyBody\nthe next line"), zfsmDiagAnonText('at backup:tank/Colon')),
	'a key is its fingerprint and its comment a token; a private key whose end was cut off is left out, and the next line stays; a dataset right after a colon is found');
same(1, preg_match('/^from (ip-[0-9a-f]{8}) and \1, then ip-[0-9a-f]{8}$/D', (string)zfsmDiagAnonText('from 203.0.113.77 and 203.0.113.77, then 2001:db8::77')), 'an address is a token, the same one each time');
same(array('2 characters, 3 bytes, non-ASCII, lower case', '2 bytes, not valid UTF-8, no letters', '3 characters, 3 bytes, upper case'), array_map('zfsmDiagShape', array("\xc3\xa9a", "\xff1", 'A-B')), 'a shape says what is not ASCII or not UTF-8, and the case');
// Two names whose tokens would start alike get different ones.
zfsmDiagStart();
$first = zfsmDiagToken('one');
$GLOBALS['zfsm_diag_names']['shapes']['name-'.substr(hash_hmac('sha256', 'two', $GLOBALS['zfsm_diag_names']['salt']), 0, 8)] = 'taken';
same(array(15, $first, $first), array(strlen(zfsmDiagToken('two')), zfsmDiagToken('one'), zfsmDiagToken($first)), 'a token that is taken grows; a name keeps its token, and a token stands for itself');
// Another run's tokens are not this one's.
zfsmDiagStart();
same(false, zfsmDiagToken('one') === $first, 'each run has its own salt');

// Unraid's diagnostics not asked for, failing, hanging, or naming no zip of its own: the plugin's zip is still made and says so.
list($zip, $why, $out) = $bundle('without', false);
same(array(true, array(), "Unraid's own diagnostics were not asked for.\n"), array($zip !== null, glob($out.'/unraid/*.zip'), file_get_contents($out.'/unraid/diagnostics.txt')), 'Unraid\'s diagnostics left out when not asked for');
has("unraid/diagnostics.txt\tok\t", file_get_contents($out.'/collectors.txt'), 'which is no failure');
foreach (array('fail' => array('read failed', 'its script ended with exit code 1'), 'silent' => array('read failed', 'its script named no zip of its own in '), 'elsewhere' => array('read failed', 'its script named no zip of its own in '), 'link' => array('read failed', 'its script named no zip of its own in '), 'hang' => array('timed out', 'its script ran out of time')) as $mode => $expected) {
	putenv('ZFSM_TEST_DIAG='.$mode);
	// Its five minutes are two seconds here; the other limits shrink with them, so their reads time out too.
	if ($mode === 'hang') $GLOBALS['zfsm_diag_scale'] = 1 / 150;
	list($zip, $why, $out) = $bundle('unraid-'.$mode);
	unset($GLOBALS['zfsm_diag_scale']);
	putenv('ZFSM_TEST_DIAG');
	if ($mode === 'elsewhere') same(true, is_file($root.'/boot/canarytower-diagnostics-20260102-1000.zip'), 'a zip outside /boot/logs is not taken for Unraid\'s, nor moved');
	same(array(true, array()), array($zip !== null, glob($out.'/unraid/*.zip')), 'the zip is made without Unraid\'s when its script is '.$mode.': '.$why);
	has("unraid/diagnostics.txt\t".$expected[0]."\t", file_get_contents($out.'/collectors.txt'), 'the file\'s result when Unraid\'s script is '.$mode);
	has('Unraid\'s own diagnostics could not be made: '.$expected[1], file_get_contents($out.'/unraid/diagnostics.txt'), 'the file says why');
	has("- Unraid's own diagnostics could not be made, so they are not with these files\n", file_get_contents($out.'/summary.txt'), 'and the summary has the finding');
	same(array(), $leaks($ours($out)), 'no name of the server leaks through what Unraid\'s script printed');
}

// A dataset a setting names on a pool that does not exist, where a collector writes it as zfs answers: its first word is a token
// there too, the one the settings list gives it.
$build();
$elsewhere = array_values(array_filter(zfsmDiagCollectors(), function($collector) { return in_array($collector[0], array('plugin/settings-effective.txt', 'plugin/replication-receive.txt'), true); }));
zfsmDiagRun($work.'/elsewhere', zfsmDiagContext(array('general' => array('replica_accept' => 'CanaryClient/photos') + $settings), false), $elsewhere);
same(true, zfsmDiagAnonymizeDir($work.'/elsewhere'), 'the files of a setting on no pool are rewritten');
$received = file_get_contents($work.'/elsewhere/plugin/replication-receive.txt');
same(array(false, true, true), array(stripos($received, 'canary'), strpos($received, 'accept replicas into: '.zfsmDiagToken('CanaryClient').'/'.zfsmDiagToken('photos')."\n") !== false,
	strpos($received, zfsmDiagToken('CanaryClient').'/'.zfsmDiagToken('photos')."\tmountpoint\t") !== false), 'it is tokens in the settings and in zfs\'s rows alike: '.$received);

// A file that can't be rewritten: nothing is packed. One whose read fails, one that can't be written, text that can't be searched.
$unpacked = function($break) use ($work, $build, $settings) {
	$build();
	$dir = $work.'/broken-'.bin2hex(random_bytes(3));
	zfsmDiagRun($dir, zfsmDiagContext(array('general' => $settings), false));
	$break($dir);
	try {
		return zfsmDiagAnonymizeDir($dir);
	} catch (Throwable $error) {
		return get_class($error).': '.$error->getMessage();
	}
};
same(false, $unpacked(function($dir) { unlink($dir.'/plugin/log.txt'); symlink('/proc/self/mem', $dir.'/plugin/log.txt'); }), 'a file that can\'t be read to its end stops the rewrite');
// Read-only in this test's own mount namespace: the file reads, and its write fails.
$read_only = '';
same(false, $unpacked(function($dir) use (&$read_only) {
	$read_only = $dir.'/zfs';
	exec('mount --bind '.escapeshellarg($read_only).' '.escapeshellarg($read_only).' && mount -o remount,ro,bind '.escapeshellarg($read_only).' 2>&1', $said, $code);
	same(0, $code, 'a folder of the run is made read-only: '.implode(' ', $said));
}), 'so does one that can\'t be written');
exec('umount '.escapeshellarg($read_only));
same(false, $unpacked(function($dir) { exec('rm -rf '.escapeshellarg($dir)); }), 'and a folder that is gone');
// A search that fails, here on a pattern that can't be read: its warning is not this test's to stop at.
zfsmDiagStart();
zfsmDiagAnonText('');
$GLOBALS['zfsm_diag_names']['pattern'] = '~(~';
$reported = error_reporting(0);
$unsearched = array(zfsmDiagAnonText('Rewrite of tank/CanaryDs One started'), zfsmDiagAnonJson('{"name":"tank/CanaryDs One"}'));
error_reporting($reported);
same(array(null, null), $unsearched, 'text that could not be searched is not kept as it was');

// The newest three zips are kept. Only names the plugin makes are removed, never a link, and what a killed run left goes after an hour.
$logs = $root.'/boot/logs';
exec('rm -rf '.escapeshellarg($logs));
mkdir($logs, 0755, true);
foreach (array('zfs.master-diagnostics-20250101-000001.zip', 'zfs.master-diagnostics-20250101-000002.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip', 'zfs.master-diagnostics-20250101-0000.zip', 'tower-diagnostics-20250101-0000.zip', 'zfs.master-diag.00000000000000aa.part', 'zfs.master-diag.00000000000000bb.part', 'zfs.master-diag.other.part') as $name) file_put_contents($logs.'/'.$name, $name);
file_put_contents($work.'/linked', 'kept');
symlink($work.'/linked', $logs.'/zfs.master-diagnostics-20240101-000000.zip');
touch($logs.'/zfs.master-diag.00000000000000aa.part', time() - 7200);
touch($logs.'/zfs.master-diag.other.part', time() - 7200);
same(array(), zfsmDiagKeepNewest($logs, 3, 'zfs.master-diagnostics-20250101-000004.zip'), 'every zip that had to go went');
$left = array_values(array_diff(scandir($logs), array('.', '..')));
same(array('tower-diagnostics-20250101-0000.zip', 'zfs.master-diag.00000000000000bb.part', 'zfs.master-diag.other.part', 'zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-0000.zip', 'zfs.master-diagnostics-20250101-000002.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip'), $left, 'the newest three stay, with every file that is not the plugin\'s zip, a link, and a part less than an hour old');
same('kept', file_get_contents($work.'/linked'), 'and what a link points to is not touched');
list($zip, $why) = $bundle('fourth', false, false);
same(array('zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip', basename((string)$zip)), array_values(preg_grep('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/', scandir($logs))), 'a new zip takes the oldest one\'s place: '.$why);
// A zip that can't be removed is named, not passed over: here another file is mounted over it. Through a link, nothing is removed.
file_put_contents($logs.'/zfs.master-diagnostics-20230101-000000.zip', 'stuck');
exec('mount --bind '.escapeshellarg($work.'/linked').' '.escapeshellarg($logs.'/zfs.master-diagnostics-20230101-000000.zip'));
same(array('zfs.master-diagnostics-20230101-000000.zip'), zfsmDiagKeepNewest($logs, 3, basename((string)$zip)), 'a zip that could not be removed is named');
exec('umount '.escapeshellarg($logs.'/zfs.master-diagnostics-20230101-000000.zip'));
symlink($logs, $work.'/logs-link');
same(array(array(), true), array(zfsmDiagKeepNewest($work.'/logs-link', 3, basename((string)$zip)), is_file($logs.'/zfs.master-diagnostics-20230101-000000.zip')), 'a folder reached through a link is not this one: nothing is removed there');
unlink($logs.'/zfs.master-diagnostics-20230101-000000.zip');
// Naming is one run at a time: while another run holds the lock, a zip waits as its .part, and takes its name once it is let go.
$pack_lock = zfsmJobDir('diagnostics').'/pack.lock';
if (!is_dir(dirname($pack_lock))) mkdir(dirname($pack_lock), 0700, true);
$holder = fopen($pack_lock, 'c');
flock($holder, LOCK_EX);
file_put_contents($logs.'/waiting.part', 'packed');
$child = 'require '.var_export(dirname(__DIR__).'/zfs.master/backend/ZFSMOperations.php', true).'; require '.var_export(dirname(__DIR__).'/zfs.master/include/ZFSMDiagnostics.php', true).'; echo json_encode(zfsmDiagPublish($argv[1], $argv[2]));';
$naming = proc_open(array(PHP_BINARY, '-n', '-r', $child, $logs.'/waiting.part', $logs), array(1 => array('pipe', 'w')), $pipes);
usleep(1500000);
$waited = array(proc_get_status($naming)['running'], is_file($logs.'/waiting.part'));
flock($holder, LOCK_UN);
$named = json_decode((string)stream_get_contents($pipes[1]), true);
proc_close($naming);
same(array(array(true, true), true, ''), array($waited, is_file((string)($named[0] ?? '')), $named[1] ?? null), 'a zip waits for its name while another run names one, then takes its own: '.json_encode($named));
unlink($named[0]);
// A clock that went back: the zip just written is kept though three have later names. A name another run took is not taken again.
foreach (array('20990101-000001', '20990101-000002', '20990101-000003') as $later) file_put_contents($logs.'/zfs.master-diagnostics-'.$later.'.zip', 'later');
list($zip, $why) = $bundle('clock', false, false);
same(array(true, array('zfs.master-diagnostics-20990101-000002.zip', 'zfs.master-diagnostics-20990101-000003.zip')), array(is_file((string)$zip), array_values(preg_grep('/-2099/', scandir($logs)))), 'the zip just written stays, with the two newest others: '.$why);
for ($second = time(); $second < time() + 30; $second++) touch($logs.'/zfs.master-diagnostics-'.date('Ymd-His', $second).'.zip');
$taken = scandir($logs);
list($zip, $why) = $bundle('taken', false, false);
same(array(true, false, 1), array(is_file((string)$zip) && filesize((string)$zip) > 0, in_array(basename((string)$zip), $taken, true), preg_match('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/D', basename((string)$zip))), 'a run takes a name no zip has yet: '.$zip.$why);
// A zip that can't be written: none is left, nor the part of one, and the run says why.
mkdir($work.'/nozip');
file_put_contents($work.'/nozip/zip', "#!/bin/sh\necho part > \"\$2\"\necho 'zip I/O error: No space left on device' >&2\nexit 14\n");
chmod($work.'/nozip/zip', 0755);
$path = getenv('PATH');
putenv('PATH='.$work.'/nozip:'.$path);
list($zip, $why) = $bundle('full-drive');
putenv('PATH='.$path);
same(array(null, 'zip I/O error: No space left on device', array('canarytower-diagnostics-20260102-1000.zip')), array($zip, $why, array_values(array_diff(scandir($logs), array('.', '..')))), 'a zip that can\'t be written is reported, nothing of it is left, and Unraid\'s zip stays where Unraid put it');
// A place for the zips that can't be made.
exec('rm -rf '.escapeshellarg($logs));
symlink('/dev/null', $logs);
list($zip, $why) = $bundle('no-logs', false, false);
unlink($logs);
same(array(null, $logs.' could not be made'), array($zip, $why), 'no folder for the zip: no zip, and why');

// From a terminal: the script prints the rewritten files and the zip's path, and the zip holds no name either.
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (!is_string($docroot) || !is_file($docroot.'/plugins/zfs.master/scripts/diagnostics.php')) {
	fwrite(STDERR, "FAILED: the script needs ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}
$script = $docroot.'/plugins/zfs.master/scripts/diagnostics.php';
file_put_contents('/var/log/syslog', "Jan  2 10:00:00 CanaryTower zfs.master[41]: ".implode("\nJan  2 10:00:01 CanaryTower zfs.master[41]: ", array_map('zfsmSafeText', $log_lines))."\n");
$clear_logs();
$run = function($options, $arguments = '') use ($script) {
	exec('ZFSM_TEST_DIAG_LOGS=/boot/logs '.escapeshellarg(PHP_BINARY).' -n '.$options.' '.escapeshellarg($script).' '.$arguments.' 2>&1', $printed, $code);
	return array($code, implode("\n", $printed));
};
list($code, $printed) = $run('');
same(array(0, false), array($code, stripos($printed, 'canary')), 'the script ends well and prints no name: '.$printed);
has("zfs/timings.txt\tok\t", $printed, 'it prints each file\'s result');
has("\nFINDINGS (", $printed, 'the summary');
same(1, preg_match('~\n\nDiagnostics zip: (/boot/logs/zfs\.master-diagnostics-\d{8}-\d{6}\.zip)$~D', $printed, $said), 'and last the zip\'s path: '.$printed);
$made[] = $said[1];
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/from-script').' 2>&1', $unzipped, $code);
same(array(0, array(), true), array($code, $leaks($ours($work.'/from-script')), is_file($work.'/from-script/unraid/canarytower-diagnostics-20260102-1000.zip')), 'the script\'s zip unpacks, holds no name, and has Unraid\'s zip: '.implode(' ', $unzipped));
has('Rewrite of tank/name-', file_get_contents($work.'/from-script/plugin/log.txt'), 'the system log\'s lines are in it, rewritten');
same(array(), glob('/tmp/zfs.master-diag.*'), 'the work folder is removed');
$clear_logs();
list($code, $printed) = $run('', '--no-unraid');
same(array(0, 1), array($code, preg_match('~\nDiagnostics zip: (\S+)$~D', $printed, $said)), 'the script ends well with --no-unraid: '.$printed);
$made[] = $said[1];
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/without-unraid'));
same(array("Unraid's own diagnostics were not asked for.\n", array()), array(file_get_contents($work.'/without-unraid/unraid/diagnostics.txt'), glob($work.'/without-unraid/unraid/*.zip')), '--no-unraid leaves Unraid\'s script unrun');
$clear_logs();
// Files that can't be rewritten, here because the work folder went under the run: the script shows no file and makes no zip.
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
$going = proc_open(array(PHP_BINARY, '-n', $script, '--no-unraid'), array(1 => array('file', $work.'/vanished.out', 'w'), 2 => array('file', $work.'/vanished.out', 'a')), $pipes);
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
for ($waited = 0; $waited < 200 && !glob('/tmp/zfs.master-diag.*'); $waited++) usleep(50000);
exec('rm -rf /tmp/zfs.master-diag.*');
do {
	usleep(100000);
	$status = proc_get_status($going);
} while ($status['running']);
proc_close($going);
$printed = file_get_contents($work.'/vanished.out');
same(array(1, false, array(), array()), array($status['exitcode'], strpos($printed, "file\tresult\tseconds"), glob('/boot/logs/zfs.master-*'), glob('/tmp/zfs.master-diag.*')), 'a rewrite that fails shows no file, makes no zip and leaves no work folder: '.substr($printed, 0, 600));
has('The names in the files could not all be replaced', $printed, 'and says so');
unlink('/var/log/syslog');
$clear_logs();

echo "diagnostics bundle tests passed\n";
