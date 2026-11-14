<?php
// The diagnostics zip through the real collectors, rewrite, zip and scripts/diagnostics.php, on a made-up server whose every
// private name holds "canary" in some case: one search of the unpacked zip finds a leak.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
// This test's script runs write zips into /boot/logs, and only while it is empty, so nothing there is another's. The test removes the
// zips it made, by their names.
zfsmTestScratch('/boot/logs');
if (array_diff(scandir('/boot/logs'), array('.', '..'))) {
	fwrite(STDERR, "FAILED: /boot/logs must be empty\n");
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

// Words and digits only: the second net keeps the plugin's own paths under it as they are on a server.
$work = sys_get_temp_dir().'/zfsm-test-'.getmypid().'-'.random_int(100000, 999999);
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
file_put_contents($work.'/ps.txt', "4242 30 zfs send -R tank/CanaryDs One@Canary Daily failed copy\n4243 5 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/CanaryDs One\n4244 9 rsync -a /mnt/tank/CanaryDs One/CanaryRsync/ /mnt/x\n4245 3 zfs send -P -v -t $resume\n4246 3 zfs send -vt $resume\n4247 3 zfs send -t$resume\n4248 3 zfs send -Pvt$resume\n");

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
	$put('/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\nrefresh_interval=\"60\"\nexclusion=\"^.*CanaryPattern.*$\"\nmystery=\"CanaryValue one\n\nCanaryValue two\"\nsnap_prefix=\"CanaryPrefix_\"\ndirectory_listing=\"tank/CanaryDs One\ntank/CanaryGone Two\nghostpool/CanaryGhost\nCanaryBare\nCanaryClient/photos\"\nsize_units=\"Canary Customer\"\nCanary stray note\nCanaryOwnSetting=\"1\"\n[CanarySection]\n");
	$put('/boot/config/ssh/root/authorized_keys', 'restrict,from="203.0.113.77",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php canarysender" '.$public_key." Canary Laptop\n");
	$put('/var/log/syslog', "Jan  2 10:00:00 CanaryTower zfs.master[41]: ".$log_lines[0]."\n");
	// Through the plugin's own writer, so each line is as the plugin's log holds it: one line, its quotes replaced, cut at its limit.
	foreach ($log_lines as $line) zfsmLog($line, LOG_NOTICE);
	$put('/var/log/phplog', "[02-Jan-2026] PHP Warning: scandir(/mnt/tank/CanaryDs One/Canary's Stuff): Failed to open directory in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9\n"
		."[02-Jan-2026] PHP Warning: scandir(/mnt/tank/CanaryDs One/Canary\tprivate): Failed to open directory in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9\n");
	$put('/var/log/nginx/error.log', "2026/01/02 10:00:00 [error] 1#1: *1 FastCGI sent in stderr: \"PHP message: x\" while reading, client: 203.0.113.77, server: , request: \"POST /plugins/zfs.master/backend/ZFSMAdmin.php?zdataset=tank%2FCanaryQuery&csrf_token=CANARYTOKEN HTTP/1.1\", host: \"canary.example.com\", referrer: \"http://canary.example.com/Main\"\n");
	$put('/etc/ssh/ssh_host_ed25519_key.pub', 'ssh-ed25519 '.base64_encode(str_repeat('k', 51))." root@CanaryTower\n");
	$put('/etc/unraid-version', "version=\"7.4.0\"\n");
	$put('/proc/self/mounts', "tank /mnt/tank zfs rw 0 0\n");
	$put('/proc/self/mountinfo', "102 1 0:45 / /mnt/tank rw shared:50 - zfs tank rw\n106 1 0:49 / /var/run/zfs.master.rewrite\\040CanaryRunMount rw shared:54 - zfs tank/CanaryRunMount rw\n104 102 0:47 / /mnt/tank/Canary\\040Mount rw shared:52 - zfs tank/Canary\\040Mount rw\n105 1 0:48 / /mnt/mountedpool/CanaryMounted rw shared:53 - zfs mountedpool/CanaryMounted rw\n"
		// The kernel writes a carriage return in a path as it is.
		."107 102 0:50 / /mnt/tank/CanaryCR\rCanaryTail rw shared:56 - zfs tank/CanaryCR rw\n");
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
same(array(array(), ''), array(glob($root.'/boot/logs/*.part'), $why), 'and nothing of its packing is left beside it, with nothing left undone to say');
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
has("refresh_interval=\"60\"\nexclusion = ".$token('^.*CanaryPattern.*$')."\n".$token('mystery')." = ".$token('CanaryValue one')."\nsnap_prefix = ".$token('CanaryPrefix_')."\ndirectory_listing = ".$token('tank/CanaryDs One')."\nsize_units = ".$token('Canary Customer')."\n".$token('Canary stray note')."\n".$token('CanaryOwnSetting')." = ".$token('1')."\n".$token('[CanarySection]')."\n", $files['plugin/settings-saved.cfg.txt'],
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
has('scandir(/mnt/tank/'.$ds.'/'.$token("Canary\tprivate): Failed to open directory").' in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9'."\n", $files['plugin/php-errors.txt'], 'in running text a tab is part of a folder\'s name: only the line\'s end ends its path');
has('scandir(/mnt/tank/'.$ds.'/'.$token('Canary\'s Stuff): Failed to open directory').' in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9'."\n", $files['plugin/php-errors.txt'], 'a folder in a PHP error is replaced, and where PHP raised it stays');
has("4242\t30\tzfs send -R tank/$ds@".$token('Canary Daily failed copy')."\n", $files['zfs/processes.txt'], 'a running command\'s dataset and snapshot are tokens, a known snapshot whole');
same(array(), preg_grep('~'.explode('-', $resume)[3].'~', explode("\n", $files['zfs/processes.txt'])), 'no resume token is in the process list');
has("4245\t3\tzfs send -P -v -t (a resume token, left out)\n4246\t3\tzfs send -".$token('vt')." (a resume token, left out)\n4247\t3\tzfs send -t(a resume token, left out)\n"
	."4248\t3\tzfs send -".$token('Pvt')."(a resume token, left out)\n", $files['zfs/processes.txt'], 'and its resume token is left out, after -t in every form');
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
$plain = "feature@async_destroy\tenabled\nscan: scrub repaired 0B in 00:01:02 on 2026.11.11.3; ZFSMOperations::run v1.2.3.4 300.1.2.3\n/usr/local/emhttp/plugins/zfs.master/scripts/alerts.php\nhttps://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta/zfs.master.plg\n";
same($plain, zfsmDiagAnonText($plain), 'a pool feature, a time, a version, a class, a system path and a link are left alone');
$cron = "7 * * * * /usr/local/emhttp/plugins/zfs.master/scripts/alerts.php check &> /dev/null\n";
same($cron, zfsmDiagAnonRunning($cron), 'and a command line in running text keeps its system paths: in a field, a system path takes the whole field');
same('/dev/zvol/zfs/'.zfsmDiagToken('vm')."\n/dev/null", zfsmDiagAnonText("/dev/zvol/zfs/vm\n/dev/null"), 'a volume\'s device names its dataset; /dev/null does not');
// Under a system folder the system's and the plugin's own paths stay; any other name is a token, a word or a number too, as a dataset can be mounted there.
same(array('/usr/local/'.zfsmDiagToken('ClientA')."\t/usr/bin/php\t/usr/local/emhttp/plugins/zfs.master/scripts/alerts.php\t/etc/".zfsmDiagToken('ClientB').'/'.zfsmDiagToken('root').'@'.zfsmDiagToken('10.0.0.5')."\t/dev/".zfsmDiagToken('ClientC')."\t/usr/local/".zfsmDiagToken('SHA256:ClientE'),
		'tank/'.zfsmDiagToken('a')."\tmountpoint\t/usr/local/".zfsmDiagToken('client')."\tlocal\ntank/".zfsmDiagToken('b')."\tmountpoint\t/usr/local/".zfsmDiagToken('12345')."\tlocal\n/usr/bin/".zfsmDiagToken('client')."\t/proc/self/mountinfo\t/usr/local/emhttp/plugins/zfs.master/".zfsmDiagToken('client'),
		'{"m":"/usr/local/'.zfsmDiagToken('Client A').'","n":"/usr/local/'.zfsmDiagToken('12345').'"}', '/usr/local/'.zfsmDiagToken('ClientA').' failed, /usr/local/'.zfsmDiagToken('client').' too, zpool import -d /dev/disk/by-id/'.zfsmDiagToken('ata-ClientF').' tank',
		'include(/usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php): failed', '/plugins/zfs.master/backend/ZFSMAdmin.php?(left out)'),
	array(zfsmDiagAnonText("/usr/local/ClientA\t/usr/bin/php\t/usr/local/emhttp/plugins/zfs.master/scripts/alerts.php\t/etc/ClientB/root@10.0.0.5\t/dev/ClientC\t/usr/local/SHA256:ClientE"),
		zfsmDiagAnonText("tank/a\tmountpoint\t/usr/local/client\tlocal\ntank/b\tmountpoint\t/usr/local/12345\tlocal\n/usr/bin/client\t/proc/self/mountinfo\t/usr/local/emhttp/plugins/zfs.master/client"),
		zfsmDiagAnonJson('{"m":"/usr/local/Client A","n":"/usr/local/12345"}'), zfsmDiagAnonRunning('/usr/local/ClientA failed, /usr/local/client too, zpool import -d /dev/disk/by-id/ata-ClientF tank'),
		zfsmDiagAnonRunning('include(/usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php): failed'), zfsmDiagAnonText('/plugins/zfs.master/backend/ZFSMAdmin.php?csrf_token=ClientD')),
	'a folder under a system folder is a token, a word or a number too, in a row, a JSON value and running text; the system\'s and the plugin\'s own paths stay, a page\'s query is left out');
// A name can hold a carriage return: in a row it is no line's end, so the name after it is in the token too.
same(array('tank/'.zfsmDiagToken('a')."\tmountpoint\t/mnt/tank/".zfsmDiagToken("Client\rSecret")."\tlocal\n", 'tank/'.zfsmDiagToken('a')."\t/mnt/tank/".zfsmDiagToken("Client\rSecret")."\tlz4", 1),
	array(zfsmDiagAnonText(zfsmDiagFreeRows("tank/a\tmountpoint\t/mnt/tank/Client\rSecret\tlocal\n", "tank/a\tmountpoint\n")), zfsmDiagAnonText("tank/a\t/mnt/tank/Client\rSecret\tlz4"),
		count(zfsmDiagAllRows(array('tank' => array('code' => 0, 'output' => "tank/a\t/mnt/tank/Client\rSecret\tlz4\n"))))),
	'a carriage return in a property\'s value or a dataset\'s mountpoint is part of its name, and its row is one row');
// Every system path the plugin's own code names stays whole: zfsmDiagSystemKnown has them all.
$named = array();
foreach (zfsmDiagFilesUnder(__DIR__.'/../zfs.master') as $file) {
	if (preg_match('~^(?!assets/).*\.(?:php|page|js)$~D', $file) !== 1) continue;
	preg_match_all('~[\'"](/(?:'.ZFSM_DIAG_SYSTEM.')/[A-Za-z0-9_./+-]*)~', file_get_contents(__DIR__.'/../zfs.master/'.$file), $found);
	$named = array_merge($named, $found[1]);
}
$named = array_values(array_unique($named));
same(array(true, $named), array(count($named) > 20, array_map('zfsmDiagAnonText', $named)), 'every system path the plugin names stays whole');
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
same("tank/c\tkeylocation\tnone\tlocal\ntank/d\tkeylocation\t-\t-\ntank/e\tkeylocation\t(set)\treceived\n",
	zfsmDiagFreeRows("tank/c\tkeylocation\tnone\tlocal\ntank/d\tkeylocation\t-\t-\ntank/e\tkeylocation\tfile:///root/ClientA.key\treceived\n", "tank/c\tkeylocation\ntank/d\tkeylocation\ntank/e\tkeylocation\n"),
	'a key\'s place: OpenZFS\'s own none and - stay, any other is only "set"');
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
same("compatibility\toff\tdefault\ncompatibility\tlegacy\tlocal\ncompatibility\t".zfsmDiagToken('ClientA/profile')."\tlocal\ncompatibility\t".zfsmDiagToken('openzfs-2.1-linux,ClientB')."\tlocal\n"
	."tank/a\tcontext\t".zfsmDiagToken('system_u:object_r:clienta_t:s0')."\tlocal\ntank/a\tmlslabel\tnone\tlocal\n",
	zfsmDiagFreeRows("compatibility\toff\tdefault\ncompatibility\tlegacy\tlocal\ncompatibility\tClientA/profile\tlocal\ncompatibility\topenzfs-2.1-linux,ClientB\tlocal\ntank/a\tcontext\tsystem_u:object_r:clienta_t:s0\tlocal\ntank/a\tmlslabel\tnone\tlocal\n",
		"compatibility\ncompatibility\ncompatibility\ncompatibility\ntank/a\tcontext\ntank/a\tmlslabel\n"),
	'a pool\'s compatibility files, a relative path among them, and an SELinux label are tokens; OpenZFS\'s own off, legacy and none stay');
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
	'/tmp/zfs.master-diag.ClientA/notes', '/var/run/zfs.master.ClientA', '/var/log/packages/zfs.master-ClientA',
	'/var/run/zfs.master.rewrite/ClientA', '/tmp/zfs.master-diag.0123456789abcdef/ClientA', '/boot/config/plugins/zfs.master/ClientA');
$tokens = function($path) { return preg_replace_callback('~[^/]+~', function($part) { return zfsmDiagToken($part[0]); }, $path); };
same(array('/boot'.$tokens('/config/plugins/ClientA/notes'), '/var'.$tokens('/log/syslog-private'), '/boot'.$tokens('/logs/tower-diagnostics-20260102-1000.zip'), '/boot'.$tokens('/config/plugins/zfs.masterClientA/notes'), '/boot'.$tokens('/config/plugins/zfs.master.plgClient'),
	'/tmp'.$tokens('/zfs.master-diag.ClientA/notes'), '/var'.$tokens('/run/zfs.master.ClientA'), '/var'.$tokens('/log/packages/zfs.master-ClientA'),
	'/var'.$tokens('/run/zfs.master.rewrite/ClientA'), '/tmp'.$tokens('/zfs.master-diag.0123456789abcdef/ClientA'), '/boot'.$tokens('/config/plugins/zfs.master/ClientA')),
	array_map('zfsmDiagAnonText', $private), 'only the paths the plugin makes are its own, whole: not a name that only starts as one of them, nor another name inside one of its folders');
$plugins_own = '/boot/config/plugins/ /boot/config/plugins/zfs.master/zfs.master-2026.11.11.3-x86_64-1.txz /var/log/syslog.2 /var/run/zfs.master.rewrite/123.lock /tmp/zfs.master-diag.0123456789abcdef/plugin/log.txt /boot/logs'
	.' /var/log/packages/zfs.master-2026.11.11.3-x86_64-1 /var/run/zfs.master.alerts.json.lock /var/run/zfs.master.diagnostics/pack.lock';
same(explode(' ', $plugins_own), array_map('zfsmDiagAnonText', explode(' ', $plugins_own)), 'the plugin\'s own paths stay, each a field of its own');
// In a row a field is the whole path: one of the plugin's names with a space and more after it is a folder's, not the plugin's.
same('/var/'.zfsmDiagToken('run').'/'.zfsmDiagToken('zfs.master.rewrite ClientA')."\tlocal", zfsmDiagAnonText("/var/run/zfs.master.rewrite ClientA\tlocal"), 'a field that only starts with one of the plugin\'s names is a folder\'s');
// Every job folder the source names (zfsmJobDir, zfsmJobLockFile) is one of the names the plugin makes: a new job is added there too.
preg_match_all("~zfsmJob(?:Dir\\('|LockFile\\(\\$\\w+, ')([a-z-]+)'~", implode('', array_map('file_get_contents', glob(dirname(__DIR__).'/zfs.master/{include,backend,scripts}/*.php', GLOB_BRACE))), $jobs);
$job_dirs = array_map(function($job) { return '/var/run/zfs.master.'.$job.'/123.lock'; }, array_unique($jobs[1]));
same(array(true, $job_dirs), array(count($job_dirs) >= 4, array_map('zfsmDiagAnonText', $job_dirs)), 'each job\'s folder stays: '.implode(' ', $job_dirs));
// And each file the Settings page's run keeps in its folder (zfsmDiagJobFile).
preg_match_all("~zfsmDiagJobFile\\('([a-z.]+)'\\)~", implode('', array_map('file_get_contents', glob(dirname(__DIR__).'/zfs.master/{include,backend,scripts}/*.php', GLOB_BRACE))), $job_files);
$job_files = array_map(function($file) { return '/var/run/zfs.master.diagnostics/'.$file; }, array_unique($job_files[1]));
same(array(true, $job_files), array(count($job_files) >= 4, array_map('zfsmDiagAnonText', $job_files)), 'each file of the page\'s run stays: '.implode(' ', $job_files));
$GLOBALS['zfsm_root'] = $test_root;
zfsmDiagStart();
array_map('zfsmDiagKnowPool', array('zfs', 'tank'));
$kept = $root.'/var/log/syslog.1 '.$root.'/boot/config/plugins/zfs.master/zfs.master.cfg '.$root.'/var/run/zfs.master.alerts.json.lock /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php /dev/null';
same(explode(' ', $kept), array_map('zfsmDiagAnonText', explode(' ', $kept)), 'a path the collectors list, a log of it that was turned over and a system path stay as they are, each a field of its own');
same($kept, zfsmDiagAnonText($kept, "\r\n"), 'and so in running text, where a space ends one');
same('{"tank/'.zfsmDiagToken('a').'":["/mnt/zfs/'.zfsmDiagToken('b c').'",5,true]}', zfsmDiagAnonJson('{"tank\/a":["\/mnt\/zfs\/b c",5,true]}'), 'JSON is read, so a name written with \\/ is found, in a key too');
same('{"tank/'.zfsmDiagToken('a').'": broken', zfsmDiagAnonJson('{"tank\/a": broken'), 'and text that is no JSON is still searched, its \\/ read as /');
same('{"org.example:host":"'.zfsmDiagToken('10.0.20.11').'","org.example:keep":"5","org.example:size":"10G","org.example:who":"'.zfsmDiagToken('123Bob').'"}', zfsmDiagAnonJson('{"org.example:host":"10.0.20.11","org.example:keep":"5","org.example:size":"10G","org.example:who":"123Bob"}'),
	'of a user property\'s values only a number, alone or with a unit of size or time, stays: an address and a word that starts with digits are tokens');
// One decoded value is one field: a folder's name in it can hold a tab or a line break, and its path runs to the value's end.
// In a file's rows a tab still ends a field.
same(array('{"d":"/mnt/tank/'.zfsmDiagToken("Client\tSecret").'","e":"/mnt/tank/'.zfsmDiagToken("Two\nLines").'"}', '/mnt/tank/'.zfsmDiagToken('a')."\tlocal"),
	array(zfsmDiagAnonJson(json_encode(array('d' => "/mnt/tank/Client\tSecret", 'e' => "/mnt/tank/Two\nLines"))), zfsmDiagAnonText("/mnt/tank/a\tlocal")),
	'a folder\'s path in one JSON value takes its tabs and line breaks; in a row a tab ends it');
// A message in one value is lines: a path in it ends at its line's end, and what the next line names is read as itself.
$message = "cannot open '/mnt/tank/a': gone\nPHP Warning in /usr/local/emhttp/webGui/include/Helpers.php on line 5\n".zfsmStatePath('/var/run/zfs.master.lock').' held';
same(json_encode(array('e' => "cannot open '/mnt/tank/".zfsmDiagToken("a': gone")."\nPHP Warning in /usr/local/emhttp/webGui/include/Helpers.php on line 5\n".zfsmStatePath('/var/run/zfs.master.lock').' held'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	zfsmDiagAnonJson(json_encode(array('e' => $message), JSON_UNESCAPED_SLASHES)),
	'a message of many lines in one value keeps its later lines: the plugin\'s own path and a system path stay');
// A message is running text in any JSON file: the second net takes the words after the plugin's own path, as in an inventory's error.
$own = zfsmStatePath('/var/run/zfs.master.rewrite');
same('{"data":{"errors":{"tank":"failed '.$own.' '.zfsmDiagToken('CanaryClient').' busy"}}}',
	zfsmDiagAnonJson(json_encode(array('data' => array('errors' => array('tank' => 'failed '.$own.' CanaryClient busy'))), JSON_UNESCAPED_SLASHES)),
	'in a JSON file of no running text, a message\'s word after the plugin\'s own path is a token');
same(json_encode(array('e' => "failed\t/tmp/".implode('/', array_map('zfsmDiagToken', explode('/', substr($own, 5)."\tCanaryClient")))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	zfsmDiagAnonJson(json_encode(array('e' => "failed\t$own\tCanaryClient"), JSON_UNESCAPED_SLASHES)), 'and one with a tab is too: the path runs to the line\'s end, a tab and all');
same('{"encryption":"aes-256-gcm","devices":"raidz1-0\nsda","'.$own.'":1}', zfsmDiagAnonJson(json_encode(array('encryption' => 'aes-256-gcm', 'devices' => "raidz1-0\nsda", $own => 1), JSON_UNESCAPED_SLASHES)),
	'a value with no space is one field: zfs\'s own words in it stay, and the plugin\'s own path, whole');
// A path after a colon or in a file URI is a path; a link's // and an IPv6 prefix start none.
same(array('{"errors":["failed:/mnt/tank/'.zfsmDiagToken('ClientA').'"],"u":"file:///mnt/tank/'.zfsmDiagToken('ClientB').'"}',
		"failed:/mnt/tank/".zfsmDiagToken('ClientA')."\tfile:///usr/local/emhttp/webGui/include/Helpers.php\thttps://example.org/a/b\t".zfsmDiagToken('fe80::', 'ip')."/10"),
	array(zfsmDiagAnonJson('{"errors":["failed:/mnt/tank/ClientA"],"u":"file:///mnt/tank/ClientB"}'), zfsmDiagAnonText("failed:/mnt/tank/ClientA\tfile:///usr/local/emhttp/webGui/include/Helpers.php\thttps://example.org/a/b\tfe80::/10")),
	'a folder after a colon or file:// is a token; a system path in a file URI, a link and an IPv6 prefix stay');
mkdir($work.'/colon');
zfsmDiagWrite($work.'/colon', 'e.json.txt', '{"errors":["failed:/mnt/tank/ClientA"]}');
same(array(true, '{"errors":["failed:/mnt/tank/'.zfsmDiagToken('ClientA').'"]}'."\n"), array(zfsmDiagAnonymizeDir($work.'/colon'), file_get_contents($work.'/colon/e.json.txt')), 'and so in a run\'s JSON file');
same(null, zfsmDiagFilesUnder($work.'/not-there'), 'a folder that can\'t be listed has no files: it is no empty folder');
// The word list is read once a run, so a failed read is tried in a PHP of its own, on a copy of the plugin with one file unreadable.
$words_in = function($plugin, $as = '') {
	$php = 'require "'.$plugin.'/backend/ZFSMOperations.php"; require "'.$plugin.'/include/ZFSMDiagnostics.php"; zfsmDiagStart();'
		.' echo json_encode(array(zfsmDiagVocabulary() === null, zfsmDiagKnownWords("failed busy"), zfsmDiagAnonJson("{\"e\":\"failed busy\"}"), zfsmDiagAnonText("/usr/bin/php")), JSON_UNESCAPED_SLASHES);';
	exec($as.escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($php).' 2>&1', $said, $code);
	return array($code, implode("\n", $said));
};
exec('cp -a '.escapeshellarg(__DIR__.'/../zfs.master').' '.escapeshellarg($work.'/vocab').' && chmod -R a+rX '.escapeshellarg($work.'/vocab'), $unused, $copied);
file_put_contents($work.'/vocab/include/ZZEmpty.php', '');
same(array(0, array(0, '[false,"failed busy","{\"e\":\"failed busy\"}","/usr/bin/php"]')), array($copied, $words_in($work.'/vocab')), 'a copy of the plugin reads its words, an empty file of it too, and its system paths');
symlink('/proc/self/mem', $work.'/vocab/include/ZZUnreadable.php');
same(array(0, '[true,null,null,"/usr/bin/php"]'), $words_in($work.'/vocab'), 'a file of the plugin\'s that can\'t be read leaves no word list, so no running text can be searched; its system paths stay, as its files are listed');
$php = 'require "'.$work.'/vocab/backend/ZFSMOperations.php"; require "'.$work.'/vocab/include/ZFSMDiagnostics.php"; zfsmDiagStart();'
	.' $first = zfsmDiagVocabulary() === null; unlink("'.$work.'/vocab/include/ZZUnreadable.php"); echo json_encode(array($first, zfsmDiagVocabulary() === null));';
exec(escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($php).' 2>&1', $said, $code);
same(array(0, '[true,true]'), array($code, implode("\n", $said)), 'a failed read of the word list stays failed for the run, once the file can be read again too');
// Root lists any folder: as nobody, a folder of mode 000 can't be. Each case first shows nobody gets in while nothing is locked.
chmod($work, 0711);
$nobody = 'setpriv --reuid=65534 --regid=65534 --clear-groups ';
same(array(0, '[false,"failed busy","{\"e\":\"failed busy\"}","/usr/bin/php"]'), $words_in($work.'/vocab', $nobody), 'nobody reads the copy\'s words');
mkdir($work.'/vocab/include/ZZLocked', 0000);
same(array(0, '[true,null,null,null]'), $words_in($work.'/vocab', $nobody), 'a folder of the plugin\'s that can\'t be listed leaves no word list either, and no list of system paths, so no path can be searched');
$rewrite = function() use ($work, $nobody) {
	$php = 'require "'.__DIR__.'/../zfs.master/backend/ZFSMOperations.php"; require "'.__DIR__.'/../zfs.master/include/ZFSMDiagnostics.php"; zfsmDiagStart(); var_export(zfsmDiagAnonymizeDir("'.$work.'/unlisted"));';
	exec($nobody.escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($php).' 2>&1', $said, $code);
	return array($code, implode("\n", $said), is_file($work.'/unlisted/names.txt'));
};
mkdir($work.'/unlisted/plugin', 0700, true);
file_put_contents($work.'/unlisted/summary.txt', "kept\n");
exec('chown -R 65534:65534 '.escapeshellarg($work.'/unlisted'));
same(array(0, 'true', true), $rewrite(), 'nobody rewrites a work folder it can list');
unlink($work.'/unlisted/names.txt');
chmod($work.'/unlisted/plugin', 0000);
same(array(0, 'false', false), $rewrite(), 'a work folder that can\'t be listed to its end is not rewritten, and no names.txt is written');
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
// A user agent is read by its own grammar, never by the shared rules: browsers' agents stay whole, other text is tokens.
$agents = array('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7; rv:121.0) Gecko/20100101 Firefox/121.0',
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Vivaldi/6.5.3206.48');
same(array_merge($agents, array('Mozilla/5.0 (Linux; Android 14; '.zfsmDiagToken('Pixel 8').') Mobile', zfsmDiagToken('ClientAcme').' '.zfsmDiagToken('tank/data'))),
	array_map('zfsmDiagUserAgent', array_merge($agents, array('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile', 'ClientAcme tank/data'))), 'a browser\'s user agent stays whole; any other piece of one is a token');
// Outside a user agent no browser's name or version shape is let through: an address is a token wherever it stands.
same(array(1, 'id '.zfsmDiagToken('192_168_1_20')), array(preg_match('~^via host/ip-[0-9a-f]{8}, Version/ip-[0-9a-f]{8}$~D', (string)zfsmDiagAnonText('via host/192.168.1.20, Version/10.0.0.5')), zfsmDiagKnownWords('id 192_168_1_20')),
	'an address after any name is a token, and digits joined by _ are no number');
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
same('', zfsmDiagKeepNewest($logs, 3, 'zfs.master-diagnostics-20250101-000004.zip'), 'every zip that had to go went');
$left = array_values(array_diff(scandir($logs), array('.', '..')));
same(array('tower-diagnostics-20250101-0000.zip', 'zfs.master-diag.00000000000000bb.part', 'zfs.master-diag.other.part', 'zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-0000.zip', 'zfs.master-diagnostics-20250101-000002.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip'), $left, 'the newest three stay, with every file that is not the plugin\'s zip, a link, and a part less than an hour old');
same('kept', file_get_contents($work.'/linked'), 'and what a link points to is not touched');
list($zip, $why) = $bundle('fourth', false, false);
same(array('zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip', basename((string)$zip)), array_values(preg_grep('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/', scandir($logs))), 'a new zip takes the oldest one\'s place: '.$why);
// A zip or a killed run's part that can't be removed is named, not passed over: here another file is mounted over each, the part's
// an old one so it is due. Through a link, nothing is removed.
$stuck = array('zfs.master-diag.00000000000000cc.part' => $work.'/old', 'zfs.master-diagnostics-20230101-000000.zip' => $work.'/linked');
file_put_contents($work.'/old', 'old');
touch($work.'/old', time() - 7200);
foreach ($stuck as $name => $over) {
	file_put_contents($logs.'/'.$name, 'stuck');
	exec('mount --bind '.escapeshellarg($over).' '.escapeshellarg($logs.'/'.$name));
}
same('older zips could not be removed from '.$logs.': zfs.master-diag.00000000000000cc.part, zfs.master-diagnostics-20230101-000000.zip', zfsmDiagKeepNewest($logs, 3, basename((string)$zip)), 'a zip or a part that could not be removed is named');
foreach ($stuck as $name => $over) exec('umount '.escapeshellarg($logs.'/'.$name));
unlink($logs.'/zfs.master-diag.00000000000000cc.part');
// A folder that can't be listed (here a file in its place) is said to be, not taken for one with nothing to remove.
same(array('older zips could not be looked for in '.$work.'/linked, so none were removed', 'older zips could not be looked for in '.$work.'/missing, so none were removed'),
	array(zfsmDiagKeepNewest($work.'/linked', 3, basename((string)$zip)), zfsmDiagKeepNewest($work.'/missing', 3, basename((string)$zip))), 'a failed look for older zips is said, a folder that is not there too');
symlink($logs, $work.'/logs-link');
same(array('older zips were not removed: '.$work.'/logs-link is reached through a link', true), array(zfsmDiagKeepNewest($work.'/logs-link', 3, basename((string)$zip)), is_file($logs.'/zfs.master-diagnostics-20230101-000000.zip')),
	'a folder reached through a link is not this one: nothing is removed there, and that is said');
unlink($logs.'/zfs.master-diagnostics-20230101-000000.zip');
// Another process swaps the folder for a link after this one resolved it (PHP keeps that): retention and each removal check again.
mkdir($work.'/swap-target');
foreach (array('000001', '000002', '000003', '000004') as $at) file_put_contents($work.'/swap-target/zfs.master-diagnostics-20220101-'.$at.'.zip', 'elsewhere');
$swapped = function ($run) use ($logs, $work) {
	clearstatcache(true, $logs);
	realpath($logs);
	exec('mv '.escapeshellarg($logs).' '.escapeshellarg($work.'/logs-real').' && ln -s '.escapeshellarg($work.'/swap-target').' '.escapeshellarg($logs));
	$said = $run();
	exec('rm '.escapeshellarg($logs).' && mv '.escapeshellarg($work.'/logs-real').' '.escapeshellarg($logs));
	clearstatcache(true);
	return $said;
};
same(array('older zips were not removed: '.$logs.' is reached through a link', null, 4),
	array($swapped(fn() => zfsmDiagKeepNewest($logs, 3, 'none')), $swapped(fn() => zfsmDiagRemoveLog($logs, 'zfs.master-diagnostics-20220101-000001.zip')), count(glob($work.'/swap-target/*.zip'))),
	'a folder swapped for a link by another process after this one resolved it: nothing there is removed');
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
// Unraid's zip that can't be removed once it is packed (here a file is mounted over it), or in a /boot/logs reached through a link: the zip is made, and that Unraid's is still there is said.
$unraid_zip = $logs.'/canarytower-diagnostics-20260102-1000.zip';
file_put_contents($work.'/stuck', '');
exec('mount --bind '.escapeshellarg($work.'/stuck').' '.escapeshellarg($unraid_zip));
list($zip, $why) = $bundle('unraid-stuck', true, false);
exec('umount '.escapeshellarg($unraid_zip));
same(array(true, 'Unraid\'s own zip was not removed from '.$logs.', so it is there as well', true), array(is_file((string)$zip), $why, is_file($unraid_zip)), 'Unraid\'s zip that could not be removed is said');
rename($logs, $work.'/real-logs');
symlink($work.'/real-logs', $logs);
list($zip, $why) = $bundle('logs-linked', true, false);
same(array(true, 'Unraid\'s own zip was not removed from '.$logs.', so it is there as well'."\n".'older zips were not removed: '.$logs.' is reached through a link', true),
	array(is_file((string)$zip), $why, is_file($work.'/real-logs/canarytower-diagnostics-20260102-1000.zip')), 'in a /boot/logs reached through a link nothing is removed, and that is said');
unlink($logs);
rename($work.'/real-logs', $logs);
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
// Unraid's display settings: a time and a theme written by hand, and a setting the plugin does not use.
putenv('ZFSM_TEST_DISPLAY='.json_encode(array('date' => '%A, %d.%m.%Y', 'time' => 'CanaryClock %R', 'number' => ', ', 'theme' => 'canarytheme', 'background' => 'CanaryBackground.png')));
$clear_logs();
// Debug Log on, with a line of its own that names a dataset of a pool since exported: the run learns that pool from it, and adds
// no debug line of its own. In this test's container only, like /var/log/syslog above.
@mkdir('/boot/config/plugins/zfs.master', 0700, true);
file_put_contents('/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\ndebug_log=\"1\"\n");
$debug_line = "2026-01-02 10:00:02 debug [41] exit 1 in 0.200 s: zfs destroy -r gonepool/private\n";
file_put_contents('/var/log/zfs.master.debug.log', $debug_line);
register_shutdown_function(function() { @unlink('/var/log/zfs.master.debug.log'); @unlink('/boot/config/plugins/zfs.master/zfs.master.cfg'); });
$run = function($options, $arguments = '', $at = null) use ($docroot) {
	$at = $at ?? $docroot;
	exec('ZFSM_TEST_DOCROOT='.escapeshellarg($at).' ZFSM_TEST_DIAG_LOGS=/boot/logs '.escapeshellarg(PHP_BINARY).' -n '.$options.' '.escapeshellarg($at.'/plugins/zfs.master/scripts/diagnostics.php').' '.$arguments.' 2>&1', $printed, $code);
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
same(array(1, $debug_line), array(preg_match('~ s: zfs destroy -r gonepool/name-[0-9a-f]{8}$~m', file_get_contents($work.'/from-script/plugin/log.txt')), file_get_contents('/var/log/zfs.master.debug.log')),
	'a pool a debug line names is learned, so all its dataset\'s name is a token; and the run wrote no debug line of its own');
has("== display settings the plugin uses\ndate=%A, %d.%m.%Y\ntime=(not shown: not in the shape of Unraid's choices)\nnumber=, \nscale=(not set)\ntheme=(not shown: not in the shape of Unraid's choices)\n\n",
	file_get_contents($work.'/from-script/unraid/context.txt'), 'only the display settings the plugin uses, and none written by hand');
same(array(), glob('/tmp/zfs.master-diag.*'), 'the work folder is removed');
$clear_logs();
list($code, $printed) = $run('', '--no-unraid');
same(array(0, 1), array($code, preg_match('~\nDiagnostics zip: (\S+)$~D', $printed, $said)), 'the script ends well with --no-unraid: '.$printed);
$made[] = $said[1];
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/without-unraid'));
same(array("Unraid's own diagnostics were not asked for.\n", array()), array(file_get_contents($work.'/without-unraid/unraid/diagnostics.txt'), glob($work.'/without-unraid/unraid/*.zip')), '--no-unraid leaves Unraid\'s script unrun');
$clear_logs();
// A file the owner added to an installed release, and a PHP error in it: the file's words are not the plugin's, so its name is a token.
$added = $work.'/added';
mkdir($added.'/webGui/include', 0700, true);
mkdir($added.'/plugins');
foreach (array('Helpers.php', 'publish.php') as $file) symlink(realpath($docroot.'/webGui/include/'.$file), $added.'/webGui/include/'.$file);
exec('cp -R '.escapeshellarg($docroot.'/plugins/zfs.master/.').' '.escapeshellarg($added.'/plugins/zfs.master'), $copied, $code);
same(0, $code, 'the plugin is copied: '.implode(' ', $copied));
$sums = '';
foreach (zfsmDiagFilesUnder($added.'/plugins/zfs.master') as $file) $sums .= md5_file($added.'/plugins/zfs.master/'.$file).'  '.$file."\n";
file_put_contents($added.'/plugins/zfs.master/manifest.md5', $sums);
file_put_contents($added.'/plugins/zfs.master/include/CanaryAdded.php', "<?php\nclass CanaryAdded {}\n");
file_put_contents('/var/log/phplog', "[02-Jan-2026] PHP Warning: x in /usr/local/emhttp/plugins/zfs.master/include/CanaryAdded.php on line 2\n");
list($code, $printed) = $run('', '--no-unraid', $added);
unlink('/var/log/phplog');
same(array(0, false, 1), array($code, stripos($printed, 'canary'), preg_match('~\nDiagnostics zip: (\S+)$~D', $printed, $said)), 'the script ends well and prints no name of an added file: '.$printed);
$made[] = $said[1];
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/from-added'));
$from_added = $ours($work.'/from-added');
same(array(array(), 1, 1), array($leaks($from_added), preg_match('~^not in the release: include/name-[0-9a-f]{8,}\.php$~m', $from_added['plugin/install.txt']),
	preg_match('~^\[02-Jan-2026\] PHP Warning: x in /usr/local/emhttp/plugins/zfs\.master/include/name-[0-9a-f]{8,} on line 2$~m', $from_added['plugin/php-errors.txt'])),
	'an added file is a token in the file check and in a PHP error, and is nowhere else: '.$from_added['plugin/install.txt'].$from_added['plugin/php-errors.txt']);
$clear_logs();
// Text that can't be searched (PCRE gives up at once): the script shows no file and makes no zip, and says why.
list($code, $printed) = $run('-d pcre.jit=0 -d pcre.backtrack_limit=1', '--no-unraid');
same(array(1, false, array()), array($code, strpos($printed, "file\tresult\tseconds"), glob('/boot/logs/zfs.master-*')), 'a rewrite that fails shows no file and makes no zip: '.substr($printed, 0, 400));
has('The names in the files could not all be replaced', $printed, 'and says so');
$clear_logs();
// A run held in its slow zpool status: by then its work folder is made and locked, which the folder alone does not show.
$slow_run = function($name) use ($script, $work) {
	putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
	putenv('ZFSM_TEST_ZPOOL_STATUS_STARTED='.$work.'/'.$name.'.started');
	$going = proc_open(array(PHP_BINARY, '-n', $script, '--no-unraid'), array(1 => array('file', $work.'/'.$name.'.out', 'w'), 2 => array('file', $work.'/'.$name.'.out', 'a')), $pipes);
	putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
	putenv('ZFSM_TEST_ZPOOL_STATUS_STARTED');
	for ($waited = 0; $waited < 200 && !is_file($work.'/'.$name.'.started'); $waited++) usleep(50000);
	same(true, is_file($work.'/'.$name.'.started'), 'the run reached its collectors before its work folder is touched');
	return $going;
};
// The work folder goes under the run: the script rewrites, shows and packs nothing.
$going = $slow_run('vanished');
exec('rm -rf /tmp/zfs.master-diag.*');
do {
	usleep(100000);
	$status = proc_get_status($going);
} while ($status['running']);
proc_close($going);
$printed = file_get_contents($work.'/vanished.out');
same(array(1, false, array(), array()), array($status['exitcode'], strpos($printed, "file\tresult\tseconds"), glob('/boot/logs/zfs.master-*'), glob('/tmp/zfs.master-diag.*')), 'a run whose work folder went under it rewrites, shows and packs nothing, and leaves no work folder: '.substr($printed, 0, 600));
has('The work folder changed under the run, so nothing was rewritten, shown or packed', $printed, 'and says so');
// A folder put in place of the run's under it: the run leaves it as it is, and says so.
$going = $slow_run('swapped');
$swapped = (string)(glob('/tmp/zfs.master-diag.*')[0] ?? '');
same(true, $swapped !== '', 'the run made its work folder');
exec('rm -rf '.escapeshellarg($swapped));
mkdir($swapped, 0700);
file_put_contents($swapped.'/theirs', 'not the run\'s');
while (proc_get_status($going)['running']) usleep(100000);
proc_close($going);
same(array(true, array('.', '..', 'theirs')), array(strpos((string)file_get_contents($work.'/swapped.out'), "The work folder is not this run's any more, so it was left as it is: $swapped") !== false, scandir($swapped)),
	'a folder put in place of the run\'s gets nothing of the run\'s, is left as it is, and the run says so: '.substr((string)file_get_contents($work.'/swapped.out'), 0, 300));
exec('rm -rf '.escapeshellarg($swapped));
unlink('/var/log/syslog');
$clear_logs();

echo "diagnostics bundle tests passed\n";
