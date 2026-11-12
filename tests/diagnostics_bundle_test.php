<?php
// The diagnostics zip through the real collectors, rewrite, zip and scripts/diagnostics.php: no name, address, host or key of a
// made-up server is in it, Unraid's own zip is in it as Unraid made it, and nothing is packed when a file could not be rewritten.
// Every private thing on that server holds "canary" in some case, so one search of the unpacked files finds a leak.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
// This test writes the system log and removes the plugin's zips in /boot/logs: in a test's container only.
if (is_file('/etc/unraid-version') || is_dir('/boot/config')) {
	fwrite(STDERR, "FAILED: refusing to run on an Unraid system\n");
	exit(1);
}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';
require_once __DIR__.'/../zfs.master/include/ZFSMDiagnostics.php';

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
$clear_logs = function() { array_map('unlink', array_merge(glob('/boot/logs/zfs.master-diagnostics-*.zip') ?: array(), glob('/boot/logs/canarytower-diagnostics-*.zip') ?: array())); };
register_shutdown_function(function() use ($work, $clear_logs) { exec('rm -rf '.escapeshellarg($work)); $clear_logs(); });
// The fixture's zfs and zpool behind wrappers that give its datasets, snapshots, senders and values names a leak would show.
$real = getenv('ZFSM_TEST_BIN_DIR');
file_put_contents($work.'/bin/zfs', "#!/bin/sh\nn=\$#\nwhile [ \"\$n\" -gt 0 ]; do a=\$(printf '%s' \"\$1\" | sed 's#tank/CanaryDs One#tank/data#g; s#tank/Canary failed Vol#tank/vol#g'); shift; set -- \"\$@\" \"\$a\"; n=\$((n - 1)); done\n"
	."out=\$(".escapeshellarg($real.'/zfs')." \"\$@\" 2>&1); code=\$?\n"
	."[ -n \"\$out\" ] && printf '%s\\n' \"\$out\" | sed 's#tank/data#tank/CanaryDs One#g; s#tank/vol#tank/Canary failed Vol#g; s#/nas2#/canarysender#g; s#@daily#@Canary Daily failed copy#g; s#\\tmedia\\t#\\tCanary Role\\t#g; s#\\tstorage\\t#\\troot@canaryhost:backup/CanaryRemote\\t#g'\nexit \$code\n");
file_put_contents($work.'/bin/zpool', "#!/bin/sh\n".escapeshellarg($real.'/zpool')." \"\$@\"; code=\$?\n[ \"\$1\" = get ] && [ \$code -eq 0 ] && printf 'comment\\tCanary Comment\\tlocal\\n'\nexit \$code\n");
foreach (array('ps', 'diagnostics') as $fake) copy(__DIR__.'/fixtures/bin/'.$fake, $work.'/bin/'.$fake);
foreach (glob($work.'/bin/*') as $fake) chmod($fake, 0755);
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/jobs');
putenv('ZFSM_TEST_PS='.$work.'/ps.txt');
putenv('ZFSM_TEST_DIAG_LOGS='.$root.'/boot/logs');
$GLOBALS['zfsm_root'] = $root;
$GLOBALS['zfsm_uptime'] = $work.'/uptime';
file_put_contents($work.'/uptime', "864000.00 800000.00\n");
file_put_contents($work.'/ps.txt', "4242 30 zfs send -R tank/CanaryDs One@Canary Daily failed copy\n4243 5 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/CanaryDs One\n4244 9 rsync -a /mnt/tank/CanaryDs One/CanaryRsync/ /mnt/x\n");

$put = function($path, $text) use ($root) {
	if (!is_dir(dirname($root.$path))) mkdir(dirname($root.$path), 0755, true);
	file_put_contents($root.$path, $text);
};
$private_key = "-----BEGIN OPENSSH PRIVATE KEY-----\nCanaryPrivateKeyBytesCanaryPrivateKeyBytes\n-----END OPENSSH PRIVATE KEY-----";
$public_key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICanaryKeyCanaryKeyCanaryKeyCanaryKeyCanaryKeyCana';
$log_lines = array(
	'Destroy Snapshot: tank/CanaryGone@CanarySnap failed: cannot destroy \'tank/CanaryGone@CanarySnap\': dataset is busy',
	'Rewrite of tank/CanaryDs One started: zfs rewrite -r tank/CanaryDs One',
	'Destroy: tank/Canary Old Stuff was removed by hand',
	'Replication of tank/CanaryDs One@CanarySnapA to tank/CanaryCopy (changes since @CanaryBase) started: zfs send -i tank/CanaryDs One@CanaryBase tank/CanaryDs One@CanarySnapA | zfs receive -s tank/CanaryCopy',
	'Replication request from 203.0.113.77 refused: tank/CanaryElse/x is not inside tank/CanaryDs One/canarysender (zfsm-replica 1 exists '.bin2hex('tank/CanaryDs One/CanaryHex').')',
	'Convert: cloning /mnt/tank/CanaryDs One/Canary Artist - Album (2019) failed, so rsync copies it: cp failed',
	'a request from 203.0.113.77 and again from 203.0.113.77, then from 2001:db8::77',
	'key '.$public_key.' canaryuser@canaryhost',
	'Replication of tank/CanaryDs One@CanarySnapA to root@canaryhost:backup/CanaryRemote failed: no route',
	'Destroy: tank/Canary failed Vol succeeded',
	'now on otherpool/CanaryOther',
	'the share on CanaryNas and canarynas',
	'a line that names CanaryTower and canarytower by themselves',
	'kept at backup:tank/CanaryColon',
);
$build = function() use ($work, $root, $put, $private_key, $public_key, $log_lines) {
	exec('rm -rf '.escapeshellarg($root).' '.escapeshellarg($work.'/jobs'));
	clearstatcache(true);
	$put('/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\nrefresh_interval=\"60\"\nexclusion=\"^.*CanaryPattern.*$\"\nmystery=\"CanaryValue one\nCanaryValue two\"\ndirectory_listing=\"tank/CanaryDs One\ntank/CanaryGone Two\nghostpool/CanaryGhost\"\n");
	$put('/boot/config/ssh/root/authorized_keys', 'restrict,from="203.0.113.77",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php canarysender" '.$public_key." Canary Laptop\n");
	$put('/var/log/zfs.master.log', "2026-01-02 10:00:00 notice [41] ".implode("\n2026-01-02 10:00:01 notice [41] ", $log_lines)."\n".$private_key."\n");
	$put('/var/log/syslog', "Jan  2 10:00:00 CanaryTower zfs.master[41]: ".$log_lines[0]."\n");
	$put('/var/log/phplog', "[02-Jan-2026] PHP Warning: scandir(/mnt/tank/CanaryDs One/Canary's Stuff): Failed to open directory in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 9\n");
	$put('/var/log/nginx/error.log', "2026/01/02 10:00:00 [error] 1#1: *1 FastCGI sent in stderr: \"PHP message: x\" while reading, client: 203.0.113.77, server: , request: \"GET /plugins/zfs.master/include/ZFSMSnapshotAdmin.php?zdataset=tank%2FCanaryQuery&csrf_token=CANARYTOKEN HTTP/1.1\", host: \"canary.example.com\", referrer: \"http://canary.example.com/Main\"\n");
	$put('/etc/ssh/ssh_host_ed25519_key.pub', 'ssh-ed25519 '.base64_encode(str_repeat('k', 51))." root@CanaryTower\n");
	$put('/etc/unraid-version', "version=\"7.4.0\"\n");
	$put('/proc/self/mounts', "tank /mnt/tank zfs rw 0 0\n");
	$put('/proc/self/mountinfo', "102 1 0:45 / /mnt/tank rw shared:50 - zfs tank rw\n104 102 0:47 / /mnt/tank/Canary\\040Mount rw shared:52 - zfs tank/Canary\\040Mount rw\n105 1 0:48 / /mnt/mountedpool/CanaryMounted rw shared:53 - zfs mountedpool/CanaryMounted rw\n");
	$put('/var/local/emhttp/var.ini', "mdState=\"STARTED\"\nfsState=\"Started\"\nNAME=\"CanaryNas\"\n");
	$put('/var/local/emhttp/disks.ini', "[\"tank\"]\nname=\"tank\"\ntype=\"Cache\"\nfsType=\"zfs\"\n[\"otherpool\"]\nname=\"otherpool\"\ntype=\"Cache\"\nfsType=\"zfs\"\n");
	mkdir($root.'/tmp');
};
$settings = array('refresh_interval' => '60', 'znapzend_data' => '1', 'exclusion' => '^.*CanaryPattern.*$', 'mystery' => 'CanaryValue one', 'directory_listing' => "tank/CanaryDs One\ntank/CanaryGone Two\nghostpool/CanaryGhost", 'replica_accept' => 'tank/CanaryDs One');
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
has('["tank/'.$ds.'","tank/'.$token('CanaryGone Two').'","ghostpool/'.$token('CanaryGhost').'"]', $files['plugin/settings-effective.txt'], 'and in the settings that name it');
has('Directory Listing names a dataset that does not exist: ghostpool/'.zfsmDiagToken('CanaryGhost')."\n", $files['summary.txt'], 'a pool only a setting names marks its datasets');
has("\t-\tzfs\tmountedpool/".$token('CanaryMounted')."\trw\n", $files['zfs/mounts.txt'], 'and so does a pool only a mount names');
has("4243\t5\t/usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/$ds\n", $files['plugin/jobs.txt'], 'a system path stays as it is, and the dataset after it is a token');
has('Directory Listing names a dataset that does not exist: tank/'.zfsmDiagToken('CanaryGone Two')."\n", $files['summary.txt'], 'a finding names a dataset by its token');
has('"name":"tank/'.$ds.'"', $files['plugin/inventory.json.txt'], 'and so does the Main tab\'s read');
has('"org.example:role":"'.zfsmDiagToken('Canary Role').'"', $files['plugin/inventory.json.txt'], 'where a user property\'s value is a token too');
has("tank/$ds\torg.example:role\t".$token('Canary Role')."\tlocal\n", $files['zfs/user-properties.txt'], 'a value its owner wrote is a token');
has("tank\torg.example:role\t".$token('root@canaryhost:backup/CanaryRemote')."\tlocal\n", $files['zfs/user-properties.txt'], 'and so is one that names another server and its dataset');
has("comment\t".$token('Canary Comment')."\tlocal\n", $files['zfs/zpool-get-all.txt'], 'a pool\'s comment is a token');
$mount = $token('Canary Mount');
has("\t/mnt/tank/$mount\trw\tshared:52\t-\tzfs\ttank/$mount\trw\n", $files['zfs/mounts.txt'], 'a mount whose name holds a space is one token, in its folder and its dataset');
has("refresh_interval=\"60\"\nexclusion = ".$token('^.*CanaryPattern.*$')."\nmystery = ".$token('CanaryValue one')."\ndirectory_listing=\"tank/$ds\n", $files['plugin/settings-saved.cfg.txt'], 'the saved settings keep a known setting, and give a pattern and an unknown setting\'s whole value a token');
has($token('canarysender').' ('.$token('Canary Laptop').'): restrict: ok, from: ok, name: ok', $files['plugin/replication-receive.txt'], 'a sender and its key\'s comment are tokens');
has("tank/$ds/".zfsmDiagToken('canarysender')."\texec\toff\tlocal\n", $files['plugin/replication-receive.txt'], 'the same token in the sender\'s area');
$log = $files['plugin/log.txt'];
$gone = 'tank/'.$token('CanaryGone').'@'.$token('CanarySnap');
has("Destroy Snapshot: $gone failed: cannot destroy '$gone': dataset is busy\n", $log, 'a name that is gone is a token too, and the words after it stay');
has('Jan  2 10:00:00 '.$token('CanaryTower').' zfs.master[41]: Destroy Snapshot: '.$gone, $log, 'the server\'s own name is a token');
has("Rewrite of tank/$ds started: zfs rewrite -r tank/$ds\n", $log, 'a known name with a space ends where it ends');
has("Destroy: tank/".$token('Canary failed Vol')." succeeded\n", $log, 'a known name is whole though one of the plugin\'s own words is in it');
has("now on otherpool/".$token('CanaryOther')."\n", $log, 'a pool only Unraid lists marks its datasets');
has('the share on '.$token('CanaryNas').' and '.$token('canarynas')."\n", $log, 'and so is the name Unraid has for it, where no system log line says it');
has('a line that names '.zfsmDiagToken('CanaryTower').' and '.$token('canarytower').' by themselves', $log, 'the server\'s name is a token wherever it stands, also as Unraid writes it in file names');
has('kept at backup:tank/'.$token('CanaryColon')."\n", $log, 'a dataset right after a colon is found');
has("Destroy: tank/".$token('Canary Old Stuff was removed by hand')."\n", $log, 'a name with a space that nothing known follows takes the line\'s end with it');
has(" to tank/".$token('CanaryCopy').' (changes since @'.$token('CanaryBase').') started: zfs send -i tank/'.$ds.'@'.zfsmDiagToken('CanaryBase'), $log, 'a snapshot named without its dataset is a token');
has(' refused: tank/'.$token('CanaryElse').'/'.$token('x').' is not inside tank/'.$ds.'/'.zfsmDiagToken('canarysender').' (zfsm-replica 1 exists (as hex) tank/'.$ds.'/'.$token('CanaryHex').')', $log, 'a dataset a request names as hex is read and replaced');
has('Convert: cloning /mnt/tank/'.$ds.'/'.$token('Canary Artist - Album (2019) failed, so rsync copies it: cp failed')."\n", $log, 'a folder\'s path takes the rest of its line');
same(1, preg_match('/a request from (ip-[0-9a-f]{8}) and again from \1, then from ip-[0-9a-f]{8}\n/', $log), 'an address is a token, the same one each time: '.$log);
has('key (a key, SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode(substr($public_key, 12)), true)), '=').') host-', $log, 'a key is its fingerprint and its comment a token');
has("(a private key, left out)\n", $log, 'a private key is left out');
has('Replication of tank/'.$ds.'@'.zfsmDiagToken('CanarySnapA').' to '.zfsmDiagToken('root@canaryhost:backup/CanaryRemote')." failed: no route\n", $log, 'another server, its user and its dataset are one token, the one they have as a property\'s value');
has('request: "GET /plugins/zfs.master/include/ZFSMSnapshotAdmin.php?(left out) HTTP/1.1", host: (left out), referrer: (left out)', $files['plugin/php-errors.txt'], 'a request\'s query and the site\'s name are left out');
has('scandir(/mnt/tank/'.$ds.'/', $files['plugin/php-errors.txt'], 'a folder in a PHP error is replaced');
has("4242\t30\tzfs send -R tank/$ds@".$token('Canary Daily failed copy')."\n", $files['zfs/processes.txt'], 'a running command\'s dataset and snapshot are tokens, a known snapshot whole');
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
same('Snapshot: gonepool/'.zfsmDiagToken('a').'@'.zfsmDiagToken('b').' failed: I/O error on read/write; cannot open \'gonepool/'.zfsmDiagToken('a').'\'', zfsmDiagAnonText('Snapshot: gonepool/a@b failed: I/O error on read/write; cannot open \'gonepool/a\''), 'a pool nothing knows: its dataset is found where the plugin\'s own line or zfs names it, and no other word with a slash is taken for one');
same('/mnt/user/'.zfsmDiagToken('Share').'/'.zfsmDiagToken('x'), zfsmDiagAnonText('/mnt/user/Share/x'), 'Unraid\'s own folder under /mnt stays, a share does not');
same('{"tank/'.zfsmDiagToken('a').'":["/mnt/zfs/'.zfsmDiagToken('b c').'",5,true]}', zfsmDiagAnonJson('{"tank\/a":["\/mnt\/zfs\/b c",5,true]}'), 'JSON is read, so a name written with \\/ is found, in a key too');
same('{"tank/'.zfsmDiagToken('a').'": broken', zfsmDiagAnonJson('{"tank\/a": broken'), 'and text that is no JSON is still searched, its \\/ read as /');
same(array('on', '1d=>1h,1w=>1d', '5', '-', zfsmDiagToken('my media')), array_map('zfsmDiagFreeValue', array('on', '1d=>1h,1w=>1d', '5', '-', 'my media')), 'of the values an owner can write, a switch, a plan and a number stay');
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
zfsmDiagKeepNewest($logs, 3);
$left = array_values(array_diff(scandir($logs), array('.', '..')));
same(array('tower-diagnostics-20250101-0000.zip', 'zfs.master-diag.00000000000000bb.part', 'zfs.master-diag.other.part', 'zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-0000.zip', 'zfs.master-diagnostics-20250101-000002.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip'), $left, 'the newest three stay, with every file that is not the plugin\'s zip, a link, and a part less than an hour old');
same('kept', file_get_contents($work.'/linked'), 'and what a link points to is not touched');
list($zip, $why) = $bundle('fourth', false, false);
same(array('zfs.master-diagnostics-20240101-000000.zip', 'zfs.master-diagnostics-20250101-000003.zip', 'zfs.master-diagnostics-20250101-000004.zip', basename((string)$zip)), array_values(preg_grep('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/', scandir($logs))), 'a new zip takes the oldest one\'s place: '.$why);
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
// This container has no disks.ini and no var.ini: the lines that need what only those say are left out.
$script_lines = preg_grep('/otherpool|CanaryNas/', $log_lines, PREG_GREP_INVERT);
file_put_contents('/var/log/syslog', "Jan  2 10:00:00 CanaryTower zfs.master[41]: ".implode("\nJan  2 10:00:01 CanaryTower zfs.master[41]: ", $script_lines)."\n");
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
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/from-script').' 2>&1', $unzipped, $code);
same(array(0, array(), true), array($code, $leaks($ours($work.'/from-script')), is_file($work.'/from-script/unraid/canarytower-diagnostics-20260102-1000.zip')), 'the script\'s zip unpacks, holds no name, and has Unraid\'s zip: '.implode(' ', $unzipped));
has('Rewrite of tank/name-', file_get_contents($work.'/from-script/plugin/log.txt'), 'the system log\'s lines are in it, rewritten');
same(array(), glob('/tmp/zfs.master-diag.*'), 'the work folder is removed');
$clear_logs();
list($code, $printed) = $run('', '--no-unraid');
same(array(0, 1), array($code, preg_match('~\nDiagnostics zip: (\S+)$~D', $printed, $said)), 'the script ends well with --no-unraid: '.$printed);
exec('unzip -q '.escapeshellarg($said[1]).' -d '.escapeshellarg($work.'/without-unraid'));
same(array("Unraid's own diagnostics were not asked for.\n", array()), array(file_get_contents($work.'/without-unraid/unraid/diagnostics.txt'), glob($work.'/without-unraid/unraid/*.zip')), '--no-unraid leaves Unraid\'s script unrun');
$clear_logs();
// Text the rewrite can't search, here a line too long for the search's own limit: the script shows no file and makes no zip.
file_put_contents('/var/log/syslog', 'Jan  2 10:00:02 CanaryTower zfs.master[41]: -----BEGIN OPENSSH PRIVATE KEY-----'.str_repeat('CanaryKey ', 300000)."\n", FILE_APPEND);
list($code, $printed) = $run('-d pcre.jit=0');
same(array(1, false, false, array(), array()), array($code, strpos($printed, "file\tresult\tseconds"), stripos($printed, 'canary'), glob('/boot/logs/zfs.master-*'), glob('/tmp/zfs.master-diag.*')), 'a rewrite that fails shows no file, makes no zip and leaves no work folder: '.substr($printed, 0, 600));
has('The names in the files could not all be replaced', $printed, 'and says so');
unlink('/var/log/syslog');
$clear_logs();

echo "diagnostics bundle tests passed\n";
