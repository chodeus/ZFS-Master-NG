<?php
// The diagnostics files through the real runner, collectors and scripts/diagnostics.php, with the fake zfs, zpool and ps
// and a made-up server's state under one folder ($GLOBALS['zfsm_root']).

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
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

$work = sys_get_temp_dir().'/zfsm-diag-test-'.getmypid().'-'.bin2hex(random_bytes(4));
$root = $work.'/root';
$plugin_dir = $work.'/plugin';
mkdir($work.'/bin', 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
// The guard has checked zfs and zpool; ps and Unraid's diagnostics join them in a folder of this test's own.
foreach (array(getenv('ZFSM_TEST_BIN_DIR').'/zfs', getenv('ZFSM_TEST_BIN_DIR').'/zpool', __DIR__.'/fixtures/bin/ps', __DIR__.'/fixtures/bin/diagnostics') as $fake) {
	copy($fake, $work.'/bin/'.basename($fake));
	chmod($work.'/bin/'.basename($fake), 0755);
}
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/jobs');
putenv('ZFSM_TEST_DIAG_LOGS='.$root.'/boot/logs');
putenv('ZFSM_TEST_PS='.$work.'/ps.txt');
$GLOBALS['zfsm_root'] = $root;
// Up for ten days, unless a case says the server has just started.
$GLOBALS['zfsm_uptime'] = $work.'/uptime';
file_put_contents($work.'/uptime', "864000.00 800000.00\n");

$put = function($path, $text) use ($root) {
	if (!is_dir(dirname($root.$path))) mkdir(dirname($root.$path), 0755, true);
	file_put_contents($root.$path, $text);
};
$manifest = function($url, $md5) {
	return "<?xml version='1.0' standalone='yes'?>\n<!DOCTYPE PLUGIN [\n<!ENTITY name \"zfs.master\">\n<!ENTITY version \"2026.01.02\">\n<!ENTITY md5 \"$md5\">\n"
		."<!ENTITY pluginURL \"$url/&name;.plg\">\n]>\n<PLUGIN name=\"&name;\" version=\"&version;\" pluginURL=\"&pluginURL;\"></PLUGIN>\n";
};
$cron_line = "7 * * * * /usr/local/emhttp/plugins/zfs.master/scripts/alerts.php check &> /dev/null\n";
$good_key = 'restrict,from="10.0.0.5",command="/usr/local/emhttp/plugins/zfs.master/scripts/replica-serve.php nas2" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGoodKeyGoodKeyGoodKeyGoodKeyGoodKeyGoodKeyGo root@nas2';

// A server with nothing wrong: every file a collector reads, as Unraid 7.4 lays them out.
$build = function() use ($work, $root, $plugin_dir, $put, $manifest, $cron_line, $good_key) {
	exec('rm -rf '.escapeshellarg($root).' '.escapeshellarg($plugin_dir).' '.escapeshellarg($work.'/jobs').' 2>&1', $said, $code);
	// With the realpath cache too: it still resolves the last server's manifest link, and symlink() would then name its target.
	clearstatcache(true);
	same(array(0, false), array($code, file_exists($root)), 'the last server is cleared away: '.implode(' ', $said));
	$put('/boot/config/plugins/zfs.master/zfs.master.cfg', "[general]\nrefresh_interval = \"60\"\n");
	$put('/boot/config/plugins/zfs.master/zfs.master-2026.01.02-x86_64-1.txz', 'package');
	$put('/boot/config/plugins/zfs.master/alerts.cron', "# Generated ZFS Master NG alert schedule:\n".$cron_line);
	$put('/boot/config/plugins/zfs.master.plg', $manifest('https://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta', md5('package')));
	$put('/boot/config/ssh/root/authorized_keys', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAdminKeyAdminKeyAdminKeyAdminKeyAdminKeyAdmi admin@laptop\n".$good_key."\n");
	$put('/var/log/plugins/other.plg', str_replace('2026.01.02', '2025.05.05', $manifest('https://example.invalid/other', md5('other'))));
	symlink($root.'/boot/config/plugins/zfs.master.plg', $root.'/var/log/plugins/zfs.master.plg');
	$put('/var/log/packages/zfs.master-2026.01.02-x86_64-1', 'record');
	$put('/var/log/zfs.master.log', "2026-01-02 10:00:00 notice [41] Snapshot created: tank/data@plugin-log\n");
	$put('/var/log/syslog.1', "Jan  1 09:00:00 Tower zfs.master[40]: older line\nJan  1 09:00:01 Tower kernel: not ours\n");
	$put('/var/log/syslog', "Jan  2 10:00:00 Tower zfs.master[41]: newer line\nJan  2 10:00:01 Tower nginx: not ours either\n");
	$put('/var/log/phplog', "[02-Jan-2026] PHP Warning: x in /usr/local/emhttp/plugins/zfs.master/include/ZFSMHelpers.php on line 1\n[02-Jan-2026] PHP Warning: y in /usr/local/emhttp/plugins/dynamix/x.php\n");
	$put('/var/run/zfs.master.lock', '');
	$put('/var/run/zfs.master.alerts.json', '{"problems":[]}');
	$put('/etc/cron.d/root', "# Generated system monitoring schedule:\n*/1 * * * * /usr/local/emhttp/plugins/dynamix/scripts/monitor &> /dev/null\n".$cron_line);
	$put('/etc/ssh/ssh_host_ed25519_key.pub', 'ssh-ed25519 '.base64_encode(str_repeat('k', 51))." root@Tower\n");
	$put('/etc/unraid-version', "version=\"7.4.0\"\n");
	$put('/proc/self/mounts', "tank /mnt/tank zfs rw,xattr,posixacl 0 0\ntmpfs /tmp tmpfs rw 0 0\n");
	$put('/proc/self/mountinfo', "102 1 0:45 / /mnt/tank rw,noatime shared:50 - zfs tank rw,xattr,posixacl\n103 1 0:46 / /tmp rw shared:51 - tmpfs tmpfs rw\n");
	$put('/var/local/emhttp/var.ini', "mdState=\"STARTED\"\nfsState=\"Started\"\nNAME=\"Tower\"\n");
	$put('/var/local/emhttp/disks.ini', "[\"cache\"]\nname=\"cache\"\ntype=\"Cache\"\nfsType=\"zfs\"\nfsStatus=\"Mounted\"\nstatus=\"DISK_OK\"\nluksState=\"0\"\n[\"disk1\"]\nname=\"disk1\"\ntype=\"Data\"\nfsType=\"xfs\"\n");
	mkdir($root.'/tmp');
	mkdir($work.'/jobs/rewrite', 0700, true);
	touch($work.'/jobs/rewrite/4711.lock');
	file_put_contents($work.'/ps.txt', '');
	mkdir($plugin_dir.'/scripts', 0755, true);
	file_put_contents($plugin_dir.'/page.php', 'page');
	file_put_contents($plugin_dir.'/scripts/alerts.php', 'script');
	chmod($plugin_dir.'/scripts/alerts.php', 0755);
	file_put_contents($plugin_dir.'/manifest.md5', md5('page')."  page.php\n".md5('script')."  scripts/alerts.php\n");
};
$settings = array('refresh_interval' => '60', 'directory_listing' => 'tank/data', 'alert_interval' => '60', 'replica_accept' => 'tank/data');
$context = function($changed = array()) use ($settings, $plugin_dir) {
	// Unraid's own diagnostics left out: they run its real script, which the leak test (diagnostics_bundle_test.php) stands in for.
	return array('dir' => $plugin_dir) + zfsmDiagContext(array('general' => $changed + $settings), false);
};
// A file that is there and that even root can't open: a socket in its place.
$unopenable = function($path) {
	unlink($path);
	$GLOBALS['zfsm_sockets'][] = stream_socket_server('unix://'.$path);
};
// A file that opens and whose read then fails: the kernel answers a read of this process's memory at address 0 with an I/O error.
$unreadable = function($path) {
	unlink($path);
	symlink('/proc/self/mem', $path);
};
// What one collector finds on that server after $change, as [findings, its file's text].
$found = function($collector, $change = null, $changed = array()) use ($build, $context, $root) {
	$build();
	if ($change !== null) $change($root);
	zfsmDiagStart();
	$text = $collector($context($changed));
	return array($GLOBALS['zfsm_diag']['findings'], $text);
};

// Every file is made, each collector says ok, and a server with nothing wrong has no findings.
$build();
$dir = $work.'/out';
same(true, zfsmDiagRun($dir, $context()), 'every file is written');
$report = file($dir.'/collectors.txt', FILE_IGNORE_NEW_LINES);
same(count(zfsmDiagCollectors()) + 1, count($report), 'collectors.txt has a line for each file');
foreach (zfsmDiagCollectors() as $index => $collector) {
	same(1, preg_match('/^'.preg_quote($collector[0], '/').'\tok\t\d+\.\d$/D', $report[$index + 1]), $collector[0].' is ok with its seconds: '.$report[$index + 1]);
	same(true, is_file($dir.'/'.$collector[0]) && filesize($dir.'/'.$collector[0]) > 0, $collector[0].' is there');
}
same(0700, fileperms($dir.'/plugin') & 0777, 'a folder made for the files is its owner\'s alone');
$summary = file_get_contents($dir.'/summary.txt');
foreach (array('plugin: 2026.01.02', 'Unraid: 7.4.0', 'PHP: '.PHP_VERSION, 'OpenZFS: zfs-2.4.4-1; zfs-kmod-2.4.4-1', 'pools: 1, all ONLINE', 'datasets: 3', 'snapshots: 3', 'settings changed from default: refresh_interval, alert_interval, replica_accept, directory_listing', 'FINDINGS (0)') as $line) {
	has($line."\n", $summary, 'summary.txt has its versions, counts and findings');
}
$readme = file_get_contents($dir.'/README.txt');
foreach (zfsmDiagNotCollected() as $path => $why) has($path.': '.$why, $readme, 'README.txt says what is left out and why');
$read = function($file) use ($dir) { return file_get_contents($dir.'/'.$file); };
same("[general]\nrefresh_interval = \"60\"\n", $read('plugin/settings-saved.cfg.txt'), 'the settings file as saved');
has("refresh_interval\t60\t30\tsaved\n", $read('plugin/settings-effective.txt'), 'a saved setting with its default');
has("lazy_load\t1\t1\tdefault\n", $read('plugin/settings-effective.txt'), 'a setting left at its default');
same(1, preg_match('/^directory_listing\t\["tank\/name-[0-9a-f]{8}"\]\t\[\]\tsaved$/m', $read('plugin/settings-effective.txt')), 'a list setting, each dataset in it by its pool and a token');
has('installed manifest: '.$root.'/var/log/plugins/zfs.master.plg -> '.$root."/boot/config/plugins/zfs.master.plg\nversion: 2026.01.02\nupdates from: https://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta/zfs.master.plg\npackage records: zfs.master-2026.01.02-x86_64-1\n", $read('plugin/install.txt'), 'Unraid\'s records of the install');
has('zfs.master-2026.01.02-x86_64-1.txz, md5 '.md5('package').' (the manifest wants '.md5('package').")\nflash folder writable: yes\n", $read('plugin/install.txt'), 'the package the next boot installs');
has("files in the release: 2\nchanged: none\nmissing: none\nnot in the release: none\nnot executable: none\n", $read('plugin/install.txt'), 'the installed files match the release');
same(1, preg_match('/^alerts: every 60 minutes\nlast check: (\d+) seconds ago\n/', $read('plugin/schedule.txt'), $age), 'the schedule and its last check: '.$read('plugin/schedule.txt'));
same(true, (int)$age[1] <= time() - filemtime($root.'/var/run/zfs.master.alerts.json'), 'the last check\'s age is the state file\'s: '.$age[1]);
has("== its lines in the live crontab\n".$cron_line, $read('plugin/schedule.txt'), 'only the plugin\'s crontab lines');
same(false, strpos($read('plugin/schedule.txt'), 'dynamix/scripts/monitor'), 'no other plugin\'s crontab line');
same("{\"problems\":[]}\n", $read('plugin/alerts-state.json.txt'), 'the alerts state as written');
has($root."/var/run/zfs.master.lock: free\n".$root."/var/run/zfs.master.alerts.json.lock: not there\n".$work."/jobs/rewrite/4711.lock: free\n", $read('plugin/jobs.txt'), 'each lock and whether it is held');
same(1, preg_match('/^accept replicas into: tank\/name-[0-9a-f]{8}\nhost key: SHA256:/', $read('plugin/replication-receive.txt')), 'the accepting dataset, by its pool and a token, and the host key');
has("nas2 (root@nas2): restrict: ok, from: ok, name: ok\n", $read('plugin/replication-receive.txt'), 'each sender key\'s checks');
same(false, strpos($read('plugin/replication-receive.txt'), 'AAAAC3'), 'no key text');
same(false, strpos($read('plugin/replication-receive.txt'), 'admin@laptop'), 'nor a key that is not a sender\'s');
same('getDatasets', json_decode($read('plugin/inventory.json.txt'), true)['op'], 'the Main tab\'s first read');
has("Snapshot created: tank/data@plugin-log\n", $read('plugin/log.txt'), 'the plugin\'s own log');
has("zfs.master[40]: older line\nJan  2 10:00:00 Tower zfs.master[41]: newer line\n", $read('plugin/log.txt'), 'the system log\'s lines, older file first');
same(false, strpos($read('plugin/log.txt'), 'not ours'), 'no other line of the system log');
has("ZFSMHelpers.php on line 1\n", $read('plugin/php-errors.txt'), 'the PHP log\'s lines that name the plugin');
same(false, strpos($read('plugin/php-errors.txt'), 'dynamix/x.php'), 'and no others');
has('== zfs.master lines in '.$root."/var/log/nginx/error.log\n(not there)\n", $read('plugin/php-errors.txt'), 'a log that is not there says so');
same(array('(not there)', '(no lines)'), array(zfsmDiagPluginLines(array($root.'/var/log/missing'), 5), zfsmDiagPluginLines(array($root.'/etc/unraid-version'), 5)), 'a log that is not there is not a quiet one');
$unopenable($root.'/var/log/syslog.1');
same(array('(unreadable: '.$root.'/var/log/syslog.1)', "Jan  2 10:00:00 Tower zfs.master[41]: newer line\n(unreadable: ".$root.'/var/log/syslog.1)'), array(zfsmDiagPluginLines(array($root.'/var/log/syslog.1'), 5), zfsmDiagPluginLines(array($root.'/var/log/syslog.1', $root.'/var/log/syslog'), 5)), 'nor is one that can\'t be opened, and the logs that can are still read');
$unreadable($root.'/var/log/syslog.1');
same("Jan  2 10:00:00 Tower zfs.master[41]: newer line\n(unreadable: ".$root.'/var/log/syslog.1)', zfsmDiagPluginLines(array($root.'/var/log/syslog.1', $root.'/var/log/syslog'), 5), 'nor is one that opens and can\'t be read');
has("Unraid: 7.4.0\nPHP: ".PHP_VERSION."\narray: mdState=STARTED, fsState=Started\npublishing paused: no\n", $read('unraid/context.txt'), 'Unraid\'s version and array state');
has("== ZFS pools and disks Unraid lists\nname=cache type=Cache fsType=zfs fsStatus=Mounted status=DISK_OK luksState=0\n\n", $read('unraid/context.txt'), 'only the ZFS entries of disks.ini');
has("== installed plugins\nother 2025.05.05\nzfs.master 2026.01.02\n", $read('unraid/context.txt'), 'the installed plugins');
same(false, strpos($read('unraid/context.txt'), 'Tower'), 'not the server\'s name');
has("== zfs version\nzfs-2.4.4-1\nzfs-kmod-2.4.4-1\n", $read('zfs/versions.txt'), 'the OpenZFS version');
has("== zpool status -p tank\npool: tank\n", $read('zfs/zpool-status.txt'), 'each pool\'s status');
has("== zpool get -H -o property,value,source all tank\nsize\t1.00T\t-\n", $read('zfs/zpool-get-all.txt'), 'each pool\'s properties');
has("volblocksize\tguid\tmounted\tcanmount\tvolmode\tencryptionroot\n", $read('zfs/datasets.txt'), 'the dataset columns');
has("tank/vol\tvolume\t", $read('zfs/datasets.txt'), 'each dataset');
same("dataset\tsnapshots\toldest\tnewest\theld\ntank\t1\t".date('Y-m-d H:i', 1700000300)."\t".date('Y-m-d H:i', 1700000300)."\t0\ntank/data\t2\t".date('Y-m-d H:i', 1700000400)."\t".date('Y-m-d H:i', 1700000500)."\t1\n", $read('zfs/snapshots-summary.txt'), 'snapshots are counted per dataset, never named');
has("== datasets holding part of a received copy\n(none)\n", $read('zfs/bookmarks.txt'), 'no resume token');
has("== zfs mounts in /proc/self/mountinfo\n102\t1\t0:45\t/\t/mnt/tank\trw,noatime\tshared:50\t-\tzfs\ttank\trw,xattr,posixacl\n", $read('zfs/mounts.txt'), 'the ZFS mounts, a tab between their fields');
same(false, strpos($read('zfs/mounts.txt'), 'tmpfs'), 'and no other mount');
same(1, preg_match('/^tank\/data\torg\.example:role\tname-[0-9a-f]{8}\tlocal$/m', $read('zfs/user-properties.txt')), 'properties set on a dataset, a value its owner wrote as a token: '.$read('zfs/user-properties.txt'));
has("== pool mounted at /boot\n(none)\n", $read('zfs/mounts.txt'), 'the boot pool');
has("\texit 0\tzfs list -H -p -r -t filesystem,volume -o ", $read('zfs/timings.txt'), 'each read with its time and result');
same(1, preg_match('/^\d+\.\d\d\texit 0\tzpool status -p tank$/m', $read('zfs/timings.txt')), 'a pool read\'s line in the timings');

// Completeness: every setting loadConfig returns and every field of the Settings page is in settings-effective.txt.
preg_match_all('/<(?:select|textarea|input)[^>]*\sname="([a-z_]+)"/', file_get_contents(__DIR__.'/../zfs.master/ZFSMasterSettings.page'), $fields);
$fields = array_values(array_diff($fields[1], array('csrf_token')));
same(true, count($fields) >= 14, 'the Settings page\'s fields were found: '.implode(', ', $fields));
foreach (array_unique(array_merge($fields, array_map('zfsmDiagSavedName', array_keys(loadConfig(array()))))) as $name) {
	same(1, preg_match('/^'.preg_quote($name, '/').'\t/m', $read('plugin/settings-effective.txt')), 'the setting '.$name.' is in settings-effective.txt');
}

// Completeness: a path under /boot, /var, /etc or /tmp named anywhere in the plugin is one a collector covers or one left out on purpose,
// so a feature that keeps new state fails here until it is listed. Each listed path is also named by code, not only by the list.
$listed = array_keys(zfsmDiagNotCollected());
foreach (zfsmDiagCollectors() as $collector) $listed = array_merge($listed, $collector[3]);
$named = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../zfs.master', FilesystemIterator::SKIP_DOTS)) as $file) {
	if (!preg_match('/\.(php|page|js)$/', $file->getFilename()) || strpos($file->getPathname(), '/assets/') !== false) continue;
	preg_match_all('#[\'"](/(?:boot|var|etc|tmp)(?:/[^\'"]*)?)[\'"]#', str_replace('$plugin', 'zfs.master', file_get_contents($file->getPathname())), $paths);
	foreach ($paths[1] as $path) $named[$path] = ($named[$path] ?? 0) + 1;
}
same(true, count($named) >= 25, 'the plugin\'s paths were found: '.count($named));
same(array(), array_values(array_diff(array_keys($named), $listed)), 'every path the plugin names is collected or left out on purpose');
same(array(), array_values(array_filter(array_unique($listed), function($path) use ($named) { return ($named[$path] ?? 0) < 2; })), 'every listed path is one the code names');
foreach (zfsmDiagCollectors() as $collector) same(true, function_exists($collector[2]) && $collector[1] > 0, $collector[0].' has its collector and a time limit');

// What each collector finds wrong. Settings first.
$problems = function($changed, $change = null) use ($found) { return $found('zfsmDiagSettingsEffective', $change, $changed)[0]; };
same(array(), $problems(array()), 'good settings have no findings');
same(1, preg_match('/^the settings file has a setting this version does not know: name-[0-9a-f]{8}$/D', implode('|', $problems(array('colour' => 'red')))), 'a setting this version does not know, by a token');
same(array('size_units holds text that a save would refuse'), $problems(array('size_units' => '${units}')), 'a value a save would refuse');
same(1, preg_match('/^the snapshot prefix and pattern give a name ZFS refuses: name-[0-9a-f]{8}$/D', implode('|', $problems(array('snap_prefix' => 'a@b')))), 'a snapshot name ZFS refuses, as a token: its owner wrote the prefix');
same(array('the exclusion pattern is invalid, so it is ignored'), $problems(array('exclusion' => '(')), 'an invalid exclusion pattern');
same(array('the exclusion pattern is a Lua pattern, the format older versions used'), $problems(array('exclussion' => '/docker%-old')), 'a Lua pattern, saved under the old name');
same(array('the exclusion pattern hides 2 of 3 datasets'), $problems(array('exclusion' => '/(data|vol)')), 'what the pattern hides');
same(1, preg_match('/^Directory Listing names a dataset that does not exist: tank\/name-[0-9a-f]{8}$/D', implode('|', $problems(array('directory_listing' => "tank/data\ntank/gone")))), 'a listed dataset that is not there, by its pool and a token');
putenv('ZFSM_TEST_CHILD_LIST_FAIL=1');
same(array('the datasets could not be listed, so the exclusion pattern and Directory Listing were not checked against them'), $problems(array('exclusion' => '/data', 'directory_listing' => 'tank/gone')), 'a failed list is not "no datasets"');
putenv('ZFSM_TEST_CHILD_LIST_FAIL');
same(1, preg_match('/^exclusion\t"name-[0-9a-f]{8}"\t""\tsaved as exclussion$/m', $found('zfsmDiagSettingsEffective', null, array('exclussion' => '/docker%-old'))[1]), 'the old name is shown as the source, and the pattern, which its owner wrote, as a token');

// The install.
$install = function($change) use ($found) { return $found('zfsmDiagInstall', $change)[0]; };
same(array(), $install(null), 'a good install has no findings');
same(array('the installed manifest is not the one on the flash drive'), $install(function($root) { rename($root.'/boot/config/plugins/zfs.master.plg', $root.'/boot/config/plugins/moved.plg'); unlink($root.'/var/log/plugins/zfs.master.plg'); symlink($root.'/boot/config/plugins/moved.plg', $root.'/var/log/plugins/zfs.master.plg'); }), 'a manifest installed from somewhere else');
same(array('updates do not come from this plugin\'s main or beta channel: https://raw.githubusercontent.com/someone/ZFS-Master-Unraid/main/zfs.master.plg'), $install(function($root) use ($manifest) { file_put_contents($root.'/boot/config/plugins/zfs.master.plg', $manifest('https://raw.githubusercontent.com/someone/ZFS-Master-Unraid/main', md5('package'))); }), 'another fork\'s update channel');
same(array('the installed package is not the manifest\'s version'), $install(function($root) { rename($root.'/var/log/packages/zfs.master-2026.01.02-x86_64-1', $root.'/var/log/packages/zfs.master-2025.12.01-x86_64-1'); }), 'a package record of another version');
same(array('the package on the flash drive is missing or not the manifest\'s, so the next boot can\'t install it'), $install(function($root) { file_put_contents($root.'/boot/config/plugins/zfs.master/zfs.master-2026.01.02-x86_64-1.txz', 'cut short'); }), 'a damaged package on the flash drive');
same(array('files of an older version are left behind'), $install(function($root) { touch($root.'/boot/config/plugins/zfs.master/zfs.master-2024.12.09.tgz'); }), 'an old package on the flash drive');
same(array('files of an older version are left behind'), $install(function($root) { touch($root.'/tmp/zfsm_reload'); }), 'the old daemon\'s reload flag');
$gone = $install(function($root) { exec('rm -rf '.escapeshellarg($root.'/boot/config/plugins/zfs.master')); });
same(true, in_array('the plugin\'s folder on the flash drive can\'t be written, so settings can\'t be saved', $gone, true), 'a flash folder that can\'t be written: '.implode('; ', $gone));
$build();
zfsmDiagStart();
file_put_contents($plugin_dir.'/page.php', 'edited');
unlink($plugin_dir.'/scripts/alerts.php');
file_put_contents($plugin_dir.'/scripts/extra.php', 'extra');
has("changed: page.php\nmissing: scripts/alerts.php\nnot in the release: scripts/extra.php\nnot executable: scripts/extra.php\n", zfsmDiagFileCheck($plugin_dir), 'each file that differs from the release');
same(array('1 installed file(s) changed: page.php', '1 installed file(s) missing: scripts/alerts.php', '1 installed file(s) not in the release: scripts/extra.php', '1 installed file(s) not executable: scripts/extra.php'), $GLOBALS['zfsm_diag']['findings'], 'and a finding for each kind');
zfsmDiagStart();
unlink($plugin_dir.'/manifest.md5');
same("manifest.md5: not there\n", zfsmDiagFileCheck($plugin_dir), 'a package without its file list');
same(array('manifest.md5 is missing, so the installed files could not be checked against the release'), $GLOBALS['zfsm_diag']['findings'], 'is a finding, not a pass');
// What can't be read is not judged: an unreadable manifest, package or file is a read that failed, never a difference.
list($findings, $text) = $found('zfsmDiagInstall', function($root) use ($unopenable) { $unopenable($root.'/boot/config/plugins/zfs.master.plg'); });
same(array(), $findings, 'a manifest that can\'t be read gives no finding about the version, the channel or the package');
same('not read', $GLOBALS['zfsm_diag']['facts']['plugin'], 'and its version is not read');
has("zfs.master.plg\n(the manifest could not be read)\nflash folder writable: yes\n", $text, 'and the file says so');
list($findings, $text) = $found('zfsmDiagInstall', function($root) { unlink($root.'/var/log/plugins/zfs.master.plg'); });
same('no version found', $GLOBALS['zfsm_diag']['facts']['plugin'], 'a manifest that is not there gives no version');
same(array('the installed manifest gives no version, so the channel, the package records and the package were not checked', 'the installed manifest is not the one on the flash drive'), $findings, 'and nothing is judged against a version it does not give: the package on the flash drive is not an older one');
has("-> nothing\nversion: (none in the manifest)\nflash folder writable: yes\n\n== files of an older version\n(none)\n", $text, 'and the file says so');
list($findings, $text) = $found('zfsmDiagInstall', function($root) use ($unreadable) { $unreadable($root.'/boot/config/plugins/zfs.master/zfs.master-2026.01.02-x86_64-1.txz'); });
same(array(), $findings, 'a package whose read fails is not a package that differs');
has('zfs.master-2026.01.02-x86_64-1.txz, md5 could not be read (the manifest wants '.md5('package').")\n", $text, 'and the file says so');
list($findings, $text) = $found('zfsmDiagInstall', function() use ($unopenable, $plugin_dir) { $unopenable($plugin_dir.'/manifest.md5'); });
same(array('manifest.md5 could not be read, so the installed files could not be checked against the release'), $findings, 'a file list that can\'t be read is not one that is missing');
has("manifest.md5: unreadable\n", $text, 'and the file says so');
list($findings, $text) = $found('zfsmDiagInstall', function() use ($unreadable, $plugin_dir) { $unreadable($plugin_dir.'/page.php'); });
same(array('1 installed file(s) unreadable: page.php'), $findings, 'an installed file whose read fails is not a changed one');
has("changed: none\nmissing: none\nnot in the release: none\nnot executable: none\nunreadable: page.php\n", $text, 'and the file lists it apart');

// The alert schedule.
$schedule = function($change, $changed = array()) use ($found) { return $found('zfsmDiagSchedule', $change, $changed)[0]; };
$old = time() - 3 * 3600;
same(array('alerts are on, but the crontab has no line for them'), $schedule(function($root) { file_put_contents($root.'/etc/cron.d/root', "# nothing of ours\n"); }), 'alerts on with no crontab line');
has($cron_line, zfsmAlertCron(60, '/usr/local/emhttp/plugins/zfs.master/scripts/alerts.php'), 'the crontab line of these cases is the one the plugin writes');
$other_job = "30 2 * * * /usr/local/emhttp/plugins/zfs.master/scripts/replicate.php tank/data &> /dev/null\n";
list($findings, $text) = $found('zfsmDiagSchedule', function($root) use ($other_job) { file_put_contents($root.'/etc/cron.d/root', $other_job); });
same(array('alerts are on, but the crontab has no line for them'), $findings, 'another of the plugin\'s jobs in the crontab is not the alert check');
has("== its lines in the live crontab\n".$other_job, $text, 'and that job\'s line is still shown');
same(array('alerts are on, but the crontab has no line for them'), $schedule(function($root) use ($cron_line) { file_put_contents($root.'/etc/cron.d/root', '#'.$cron_line.' # '.$cron_line); }), 'nor is an alert line that is commented out');
list($findings, $text) = $found('zfsmDiagSchedule', function($root) use ($unopenable) { $unopenable($root.'/etc/cron.d/root'); });
same(array('alerts are on, but the crontab could not be read'), $findings, 'a crontab that can\'t be read is not one with no line');
has("== its lines in the live crontab\n(unreadable)\n", $text, 'and the file says so');
same(array('alerts are on every 60 minutes, but the last check was 180 minutes ago'), $schedule(function($root) use ($old) { touch($root.'/var/run/zfs.master.alerts.json', $old); touch($root.'/boot/config/plugins/zfs.master/alerts.cron', $old); }), 'checks that stopped');
same(array(), $schedule(function($root) use ($old) { touch($root.'/var/run/zfs.master.alerts.json', $old); }), 'a schedule saved since the last check is waiting for its first one');
same(array(), $schedule(function($root) use ($old) { unlink($root.'/etc/cron.d/root'); touch($root.'/var/run/zfs.master.alerts.json', $old); }, array('alert_interval' => '0')), 'alerts that are off need no crontab line');
// The flash drive's file is what a restart builds the crontab from: without the alert check's line there, the checks stop then.
$not_kept = array('alerts are on, but the schedule file on the flash drive has no line for them, so they stop at the next restart');
same($not_kept, $schedule(function($root) { unlink($root.'/boot/config/plugins/zfs.master/alerts.cron'); }), 'alerts on with their schedule missing from the flash drive, while the live crontab still has it');
same($not_kept, $schedule(function($root) use ($cron_line) { file_put_contents($root.'/boot/config/plugins/zfs.master/alerts.cron', '#'.$cron_line); }), 'or with its line commented out there');
same(array(), $schedule(function($root) use ($unopenable) { $unopenable($root.'/boot/config/plugins/zfs.master/alerts.cron'); }), 'a schedule file that can\'t be read is a read that failed, not a missing line');
same(array(), $schedule(function($root) { unlink($root.'/boot/config/plugins/zfs.master/alerts.cron'); }, array('alert_interval' => '0')), 'alerts that are off need no schedule file');
// After a start the state file is gone and the schedule on the flash drive keeps its old time.
$no_state = function($root) use ($old) { unlink($root.'/var/run/zfs.master.alerts.json'); touch($root.'/boot/config/plugins/zfs.master/alerts.cron', $old); };
file_put_contents($work.'/uptime', "600.00 500.00\n");
same(array(), $schedule($no_state), 'a server that has just started is waiting for its first check');
file_put_contents($work.'/uptime', "864000.00 800000.00\n");
same(array('alerts are on every 60 minutes, but the last check was 180 minutes ago'), $schedule($no_state), 'one up for days with no check since is not');

// Jobs: a held lock is fine while one of the plugin's scripts runs.
$hold = function() use ($work) { $GLOBALS['zfsm_held'] = fopen($work.'/jobs/rewrite/4711.lock', 'c'); flock($GLOBALS['zfsm_held'], LOCK_EX); };
// Never this test's own process: the collector leaves that one out.
$script_pid = getmypid() + 1;
$running = "  $script_pid   40 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/data\n  900    2 nginx: worker process\n";
list($findings, $text) = $found('zfsmDiagJobs', $hold);
same(array('a job\'s lock is held, but its script is not running: '.$work.'/jobs/rewrite/4711.lock'), $findings, 'a lock held with no script running');
has($work."/jobs/rewrite/4711.lock: held\n", $text, 'the held lock is shown');
list($findings, $text) = $found('zfsmDiagJobs', function() use ($hold, $work, $running) { $hold(); file_put_contents($work.'/ps.txt', $running); });
same(array(), $findings, 'a lock held by a running script');
$others = "  813    5 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/alerts.php check\n  814    3 timeout 600 /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/data\n";
list($findings) = $found('zfsmDiagJobs', function() use ($hold, $work, $others) { $hold(); file_put_contents($work.'/ps.txt', $others); });
same(array('a job\'s lock is held, but its script is not running: '.$work.'/jobs/rewrite/4711.lock'), $findings, 'another of the plugin\'s scripts, or a command that only names the job\'s, does not hold its lock');
list(, $with_alerts_lock) = $found('zfsmDiagJobs', function($root) { $GLOBALS['zfsm_alerts_held'] = fopen($root.'/var/run/zfs.master.alerts.json.lock', 'c'); flock($GLOBALS['zfsm_alerts_held'], LOCK_EX); });
has($root."/var/run/zfs.master.lock: free\n".$root."/var/run/zfs.master.alerts.json.lock: held\n", $with_alerts_lock, 'the alert check\'s lock, held, beside one that is free');
fclose($GLOBALS['zfsm_alerts_held']);
// Asking never takes a lock: the alert check and a job's start take theirs without waiting, and would lose their turn.
foreach (array('ZFSMDiagnostics.php', 'ZFSMDiagPlugin.php', 'ZFSMDiagZfs.php') as $file) {
	same(0, preg_match('/\bflock\((?!\$hold, LOCK_EX \| \$wait\))|\bzfsmJob(Running|Lock)\(/', file_get_contents(__DIR__.'/../zfs.master/include/'.$file)), $file.' reads the lock table and takes no lock but a work folder\'s');
}
has("== running plugin scripts (pid, seconds, command)\n$script_pid\t40\t/usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/rewrite.php tank/data\n\n", $text, 'only the plugin\'s scripts are listed');
putenv('ZFSM_TEST_PS=fail');
list($findings, $text) = $found('zfsmDiagJobs', $hold);
putenv('ZFSM_TEST_PS='.$work.'/ps.txt');
same(array(), $findings, 'a process list that can\'t be read is not "no script running"');
has('(the process list could not be read)', $text, 'and the file says so');
fclose($GLOBALS['zfsm_held']);

// Replication's receiving side.
$receive = function($change, $changed = array()) use ($found) { return $found('zfsmDiagReplicaReceive', $change, $changed)[0]; };
same(array(), $receive(null), 'a good receiving side has no findings');
list($findings, $text) = $found('zfsmDiagReplicaReceive', function($root) { unlink($root.'/boot/config/ssh/root/authorized_keys'); }, array('replica_accept' => ''));
same(array(), $findings, 'nor one that is off');
has("accept replicas into: (off)\n", $text, 'which says it is off');
has("== sender keys in root's authorized_keys\n(the file is not there)\n", $text, 'and a key file that is not there is not "no keys"');
list(, $text) = $found('zfsmDiagReplicaReceive', function($root) use ($unopenable) { $unopenable($root.'/boot/config/ssh/root/authorized_keys'); });
has("== sender keys in root's authorized_keys\n(the file could not be read)\n", $text, 'nor is one that can\'t be read');
same(array('Accept Replicas Into is not a dataset inside a pool, so nothing is accepted'), $receive(null, array('replica_accept' => 'tank')), 'a pool\'s top dataset is not accepted');
putenv('ZFSM_TEST_PROPERTIES_FAIL=1');
same(array('the dataset Accept Replicas Into names could not be read'), $receive(null), 'an accepting dataset that can\'t be read');
putenv('ZFSM_TEST_PROPERTIES_FAIL');
// A sender's area that lost one of its properties: the receiver refuses every request into it, so it is a finding.
// A dataset under the accepting one that no sender key names is no area, whatever its properties.
$area_rows = "tank/data\tmountpoint\t/mnt/tank/data\tdefault\ntank/data/media\texec\ton\tinherited from tank/data\n";
foreach (zfsmReplicaAreaProps() as $property => $value) $area_rows .= "tank/data/nas2\t$property\t$value\tlocal\n";
same(array(array(), array('tank/data/media')), array(zfsmDiagAreasRefused($area_rows, 'tank/data', array('nas2', '')), zfsmDiagAreasRefused($area_rows, 'tank/data', array('nas2', 'media'))), 'only a dataset named for a sender is judged as its area');
has("tank/data/nas2\texec\toff\tlocal\n", $found('zfsmDiagReplicaReceive')[1], 'each sender\'s area is shown with its properties');
putenv('ZFSM_TEST_AREA_EXEC=on');
list($findings, $text) = $found('zfsmDiagReplicaReceive');
putenv('ZFSM_TEST_AREA_EXEC');
same(array('a sender\'s area under Accept Replicas Into does not keep mountpoint=none, volmode=none, setuid=off, devices=off, exec=off, so requests into it are refused: tank/data/nas2'), $findings, 'an area whose properties the receiver refuses');
has("tank/data/nas2\texec\ton\tlocal\n", $text, 'and the file shows which');
list($findings, $text) = $found('zfsmDiagReplicaReceive', function($root) use ($good_key) { file_put_contents($root.'/boot/config/ssh/root/authorized_keys', str_replace('restrict,', '', $good_key)."\n".str_replace(array('10.0.0.5', 'nas2'), array('10.0.0.0/24', 'nas3'), $good_key)."\n"); });
same(array('a sender key\'s line does not hold it to replication: nas2', 'a sender key\'s line does not hold it to replication: nas3'), $findings, 'a key its line does not hold');
has("nas2 (root@nas2): restrict: NOT OK, from: ok, name: ok\nnas3 (root@nas3): restrict: ok, from: NOT OK, name: ok\n", $text, 'and which check it fails');

// The pools and the commands running on them.
putenv('ZFSM_TEST_POOL_HEALTH=DEGRADED');
list($findings) = $found('zfsmDiagZpoolList');
putenv('ZFSM_TEST_POOL_HEALTH');
same(array(array('a pool is not ONLINE: tank is DEGRADED'), '1 (tank is DEGRADED)'), array($findings, $GLOBALS['zfsm_diag']['facts']['pools']), 'a pool that is not ONLINE');
$commands = "  700  900 zfs list -H -t snapshot\n  701 7200 zfs send -w tank/data@a\n  702   30 /usr/sbin/zpool status -p tank\n  703 5000 /usr/bin/rsync -a rsync://user:hunter2@nas/a/ /mnt/b/\n  704  100 nginx: worker process\n"
	."  705   10 mysqldump --password=hunter2 zfs\n  706   50 /usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/replicate.php tank/data a tank/copy\n  707 9000 /usr/sbin/zfs get -H guid tank\n"
	."  708   20 zfs receive -o keylocation=https://user:pw@keys.example/k tank/copy\n  709    5 /usr/sbin/zfs load-key -L file:///mnt/keys/x tank/enc\n"
	."  710    3 curl -u user:hunter2 https://tower/plugins/zfs.master/scripts/x\n  711    9 sh -c /usr/local/emhttp/plugins/zfs.master/scripts/alerts.php check\n"
	."  712    4 /usr/sbin/zfs create -o encryption=on -okeylocation=https://user:hunter2@keys.example/k tank/enc\n  713    4 zfs change-key -o keylocation=file:///mnt/my keys/tank.key tank/enc\n"
	."  714    6 /usr/sbin/zpool create -O encryption=on -O keylocation=https://user:hunter2@keys.example/p tank2 sdc\n  715    7 zpool import -d /dev/disk/by-id tank3\n";
list($findings, $text) = $found('zfsmDiagZfsProcesses', function() use ($work, $commands) { file_put_contents($work.'/ps.txt', $commands); });
same(array('2 zfs or zpool read(s) have been running for more than 10 minutes'), $findings, 'a list or get still running after 10 minutes is stuck; a long send or copy is not');
same("pid\tseconds\tcommand\n700\t900\tzfs list -H -t snapshot\n701\t7200\tzfs send -w tank/data@a\n702\t30\t/usr/sbin/zpool status -p tank\n703\t5000\trsync (its arguments are left out)\n"
	."706\t50\t/usr/bin/php -q /usr/local/emhttp/plugins/zfs.master/scripts/replicate.php tank/data a tank/copy\n707\t9000\t/usr/sbin/zfs get -H guid tank\n"
	."708\t20\tzfs receive (its arguments are left out)\n709\t5\tzfs load-key (its arguments are left out)\n712\t4\tzfs create (its arguments are left out)\n713\t4\tzfs change-key (its arguments are left out)\n714\t6\tzpool create (its arguments are left out)\n715\t7\tzpool import -d /dev/disk/by-id tank3\n", $text, 'zfs, zpool, rsync and the plugin\'s scripts by their own name: never where a key is kept, rsync\'s arguments, or a command that only names the plugin');
same("tank/a\tkeylocation\t(set)\tlocal\ntank/b\tkeylocation\tprompt\tlocal\n", zfsmDiagFreeRows("tank/a\tkeylocation\thttps://user:pw@keys.example/a\tlocal\ntank/b\tkeylocation\tprompt\tlocal\n", "tank/a\tkeylocation\ntank/b\tkeylocation\n"), 'where a key is kept stays out of the file: only that it is set');
// Part of a received copy: its dataset is named, its token is not, and a read that failed is not "none".
putenv('ZFSM_TEST_RESUME_TOKEN=1-e604ea4bf-e0-789c636064000310a500c4ec50360710e72765a5269740f80cd8');
list(, $text) = $found('zfsmDiagBookmarks');
putenv('ZFSM_TEST_RESUME_TOKEN');
has("== datasets holding part of a received copy\ntank/data\n\n", $text, 'a dataset holding part of a copy');
same(false, strpos($text, '1-e604ea4bf'), 'without its token');
putenv('ZFSM_TEST_GET_FAIL=tank');
list(, $text) = $found('zfsmDiagBookmarks');
putenv('ZFSM_TEST_GET_FAIL');
has("receive_resume_token tank\nFAILED (exit 1): cannot open 'tank': permission denied\n\n== datasets holding part of a received copy\n(not read on every pool)\n", $text, 'a token read that failed is not "none"');
list(, $text) = $found('zfsmDiagMounts', function($root) { unlink($root.'/proc/self/mountinfo'); });
has("== zfs mounts in /proc/self/mountinfo\n(the mount list could not be read)\n", $text, 'a mount list that can\'t be read is not "no mounts"');
list(, $text) = $found('zfsmDiagMounts', function($root) { file_put_contents($root.'/proc/self/mountinfo', "103 1 0:46 / /tmp rw shared:51 - tmpfs tmpfs rw\n"); });
has("== zfs mounts in /proc/self/mountinfo\n(none)\n", $text, 'one with no ZFS mount says none');
file_put_contents($work.'/counts.txt', "tank/a@1\t100\t0\ntank/a@2\t50\t2\ntank/a@3\t75\t1\ntank/b@1\t60\t0\nnot a snapshot\n");
same(array('tank/a' => array(3, 50, 100, 2), 'tank/b' => array(1, 60, 60, 0)), zfsmDiagSnapshotCounts(file_get_contents($work.'/counts.txt')), 'the oldest, newest and held snapshots of each dataset');

// A zfs that hangs: each read gives up at its limit, zfs is not asked about that pool again, and every file is still written.
// Every limit runs at a fifteenth here, so each keeps its place among the others: a read's 30 seconds is 2.
$GLOBALS['zfsm_diag_scale'] = 1 / 15;
$states_of = function($dir) {
	$states = array('ok' => array(), 'read failed' => array(), 'timed out' => array(), 'failed' => array());
	foreach (array_slice(file($dir.'/collectors.txt', FILE_IGNORE_NEW_LINES), 1) as $line) $states[explode("\t", $line)[1]][] = explode("\t", $line)[0];
	return $states;
};
$build();
putenv('ZFSM_TEST_LIST_HANG=1');
$began = microtime(true);
same(true, zfsmDiagRun($work.'/hung', $context()), 'a hung zfs still gets every file written');
$took = microtime(true) - $began;
putenv('ZFSM_TEST_LIST_HANG');
$states = $states_of($work.'/hung');
$slow = array('plugin/settings-effective.txt', 'plugin/replication-receive.txt', 'plugin/inventory.json.txt', 'zfs/datasets.txt', 'zfs/bookmarks.txt', 'zfs/user-properties.txt', 'zfs/snapshots-summary.txt');
same(array('ok' => count(zfsmDiagCollectors()) - count($slow), 'timed out' => $slow, 'failed' => array()), array('ok' => count($states['ok']), 'timed out' => $states['timed out'], 'failed' => $states['failed']), 'the files that read datasets time out, the rest are ok');
has("TIMED OUT: skipped, because zfs list timed out on this pool\n", file_get_contents($work.'/hung/plugin/replication-receive.txt'), 'the accepting dataset is on that pool, so its read is skipped too');
same(true, $took < 25, 'no read waits for the hung zfs to end, 30 seconds on: '.round($took, 1).' s');
has('- a read timed out, so these files are incomplete: '.implode(', ', $slow)."\n", file_get_contents($work.'/hung/summary.txt'), 'the summary names the incomplete files');
has("== zfs list -H -p -r -t filesystem,volume -o ", file_get_contents($work.'/hung/zfs/datasets.txt'), 'a skipped read keeps its title');
has("TIMED OUT: skipped, because zfs list timed out on this pool\n", file_get_contents($work.'/hung/zfs/datasets.txt'), 'and says why it was skipped');
has("== zpool status -p tank\npool: tank\n", file_get_contents($work.'/hung/zfs/zpool-status.txt'), 'zpool is still asked about a pool zfs hangs on');
has("\ttimed out\tzfs list -H -o name -t filesystem,volume -r tank\n", file_get_contents($work.'/hung/zfs/timings.txt'), 'the read that timed out is in the timings');
same("skipped, because zfs list timed out on a pool\n", file_get_contents($work.'/hung/plugin/inventory.json.txt'), 'the Main tab\'s read asks about every pool, so it is skipped too');
same('datasets: not read on every pool', explode("\n", strstr(file_get_contents($work.'/hung/summary.txt'), 'datasets: '))[0], 'a count that could not be read is not 0');

// A read its collector's limit cut short says nothing about the pool; one that ran its whole limit does.
$build();
zfsmDiagStart();
$names = array('list', '-H', '-o', 'name', '-t', 'filesystem,volume', '-r', 'tank');
putenv('ZFSM_TEST_LIST_HANG=1');
$cut = zfsmWithDeadline(0.3, function() use ($names) { return array(zfsmDiagPoolRead('tank', 'zfs', $names), zfsmDiagPoolRead('tank', 'zfs', $names)); });
putenv('ZFSM_TEST_LIST_HANG');
same(array(null, 'zfs timed out', null, 'zfs timed out before it started'), array($cut[0]['code'], $cut[0]['output'], $cut[1]['code'], $cut[1]['output']), 'reads past their collector\'s limit time out, the next one before it starts');
same(0, zfsmDiagPoolRead('tank', 'zfs', $names)['code'], 'and the pool is still read afterwards');
putenv('ZFSM_TEST_LIST_HANG=1');
$whole = zfsmDiagPoolRead('tank', 'zfs', $names);
putenv('ZFSM_TEST_LIST_HANG');
same(array(null, 'skipped, because zfs list timed out on this pool'), array($whole['code'], zfsmDiagPoolRead('tank', 'zfs', $names)['output']), 'a read that had its whole limit marks the pool, and zfs is not asked about it again');
// A read given a moment less than its limit is cut short too, though its command's end takes it past the limit on the clock.
zfsmDiagStart();
putenv('ZFSM_TEST_LIST_HANG=1');
$began = microtime(true);
$nearly = zfsmWithDeadline(zfsmDiagSeconds(30) - 0.0001, function() use ($names) { return zfsmDiagPoolRead('tank', 'zfs', $names); });
$took = microtime(true) - $began;
putenv('ZFSM_TEST_LIST_HANG');
same(array(null, true, 0), array($nearly['code'], $took >= zfsmDiagSeconds(30), zfsmDiagPoolRead('tank', 'zfs', $names)['code']), 'a read given less than its limit does not mark the pool: it took '.round($took, 3).' s');

// A read that could not be started is a failure, not a timeout: the timeout command is hidden, in this test's own mount namespace.
$timeouts = array_unique(array_filter(array_map('realpath', array('/usr/bin/timeout', '/bin/timeout'))));
$mount = function($command) use ($timeouts) {
	foreach ($timeouts as $path) {
		exec($command.' '.escapeshellarg($path).' 2>&1', $said, $code);
		same(0, $code, $command.' '.$path.': '.implode(' ', $said));
	}
	clearstatcache();
};
$build();
zfsmDiagStart();
$mount('mount --bind /dev/null');
$unstarted = zfsmDiagRead('zfs', array('version'));
$mount('umount');
same(array(ZFSM_ERR_UNABLE_TO_CREATE_PROC, 'The system timeout utility is unavailable', false), array($unstarted['code'], $unstarted['output'], $GLOBALS['zfsm_diag']['timed_out']), 'a read that could not be started is not a timeout');
has("\texit ".ZFSM_ERR_UNABLE_TO_CREATE_PROC."\tzfs version", implode("\n", $GLOBALS['zfsm_diag']['timings']), 'nor in the timings');
same(0, zfsmDiagRead('zfs', array('version'))['code'], 'and the timeout command is back');

// A pool list that hangs or fails: each file that needs it says which, and the list's own read is in the timings.
$build();
putenv('ZFSM_TEST_POOL_HANG=1');
same(true, zfsmDiagRun($work.'/nolist', $context()), 'a hung zpool list still gets every file written');
putenv('ZFSM_TEST_POOL_HANG');
$states = $states_of($work.'/nolist');
$needs = array('plugin/settings-effective.txt', 'plugin/inventory.json.txt', 'zfs/zpool-list.txt', 'zfs/zpool-status.txt', 'zfs/zpool-get-all.txt', 'zfs/datasets.txt', 'zfs/bookmarks.txt', 'zfs/user-properties.txt', 'zfs/snapshots-summary.txt');
same(array('timed out' => $needs, 'failed' => array()), array('timed out' => $states['timed out'], 'failed' => $states['failed']), 'a pool list that timed out is a timeout in every file that needs it');
same(1, preg_match('/^2\.\d\d\ttimed out\tzpool list -H -o name,size,alloc,free,health$/m', file_get_contents($work.'/nolist/zfs/timings.txt')), 'and its read, given up at its own limit, is in the timings: '.file_get_contents($work.'/nolist/zfs/timings.txt'));
has('- a read timed out, so these files are incomplete: '.implode(', ', $needs)."\n", file_get_contents($work.'/nolist/summary.txt'), 'the summary names them');
$build();
putenv('ZFSM_TEST_POOL_FAIL=1');
zfsmDiagRun($work.'/badlist', $context(), array(array('zfs/zpool-status.txt', 60, 'zfsmDiagZpoolStatus', array())));
putenv('ZFSM_TEST_POOL_FAIL');
same(array('zfs/zpool-status.txt'), $states_of($work.'/badlist')['failed'], 'a pool list that failed is a failure, not a timeout');
has("FINDINGS (1)\n- a collector failed, so these files hold only its error: zfs/zpool-status.txt\n", file_get_contents($work.'/badlist/summary.txt'), 'and the summary names the file');

// A read that failed is in its file, and also in what the run prints: the file's result is "read failed" and the summary names it.
// Each case is one collector through the real runner; a change that sets a switch gives back what unsets it.
$result_of = function($file, $change, $changed = array()) use ($build, $context, $root, $work) {
	$build();
	$undo = $change($root);
	$collector = array_values(array_filter(zfsmDiagCollectors(), function($collector) use ($file) { return $collector[0] === $file; }));
	zfsmDiagRun($work.'/unread', $context($changed), $collector);
	if ($undo !== null) $undo();
	$named = strpos(file_get_contents($work.'/unread/summary.txt'), '- a read failed, so these files are incomplete: '.$file."\n") !== false;
	return array(explode("\t", file($work.'/unread/collectors.txt', FILE_IGNORE_NEW_LINES)[1])[1], $named);
};
$with = function($switch, $value, $after = false) {
	return function() use ($switch, $value, $after) {
		putenv($switch.'='.$value);
		return function() use ($switch, $after) { putenv($after === false ? $switch : $switch.'='.$after); };
	};
};
// The kernel's lock table can't be hidden by a path of this test's: a socket is mounted over it, in the test's own mount namespace.
$no_lock_table = function() use ($work) {
	$GLOBALS['zfsm_sockets'][] = stream_socket_server('unix://'.$work.'/no-locks');
	exec('mount --bind '.escapeshellarg($work.'/no-locks').' /proc/locks 2>&1', $said, $code);
	same(0, $code, 'the lock table is hidden: '.implode(' ', $said));
	return function() {
		exec('umount /proc/locks 2>&1', $said, $code);
		same(0, $code, 'the lock table is back: '.implode(' ', $said));
	};
};
$failed_reads = array(
	'the settings file can\'t be opened' => array('plugin/settings-saved.cfg.txt', function($root) use ($unopenable) { $unopenable($root.'/boot/config/plugins/zfs.master/zfs.master.cfg'); }),
	'a system log can\'t be opened' => array('plugin/log.txt', function($root) use ($unopenable) { $unopenable($root.'/var/log/syslog.1'); }),
	'the settings file opens and its read fails' => array('plugin/settings-saved.cfg.txt', function($root) use ($unreadable) { $unreadable($root.'/boot/config/plugins/zfs.master/zfs.master.cfg'); }),
	'a system log opens and its read fails' => array('plugin/log.txt', function($root) use ($unreadable) { $unreadable($root.'/var/log/syslog.1'); }),
	'the installed manifest can\'t be opened' => array('plugin/install.txt', function($root) use ($unopenable) { $unopenable($root.'/boot/config/plugins/zfs.master.plg'); }),
	'the package\'s read fails' => array('plugin/install.txt', function($root) use ($unreadable) { $unreadable($root.'/boot/config/plugins/zfs.master/zfs.master-2026.01.02-x86_64-1.txz'); }),
	'manifest.md5 can\'t be opened' => array('plugin/install.txt', function() use ($unopenable, $plugin_dir) { $unopenable($plugin_dir.'/manifest.md5'); }),
	'an installed file\'s read fails' => array('plugin/install.txt', function() use ($unreadable, $plugin_dir) { $unreadable($plugin_dir.'/page.php'); }),
	'a command ends with an error' => array('zfs/zpool-status.txt', $with('ZFSM_TEST_ZPOOL_STATUS_FAIL', '1')),
	'the process list can\'t be read' => array('zfs/processes.txt', $with('ZFSM_TEST_PS', 'fail', $work.'/ps.txt')),
	'the pool list fails and the collector goes on' => array('plugin/settings-effective.txt', $with('ZFSM_TEST_POOL_FAIL', '1')),
	'the mount list is gone' => array('zfs/mounts.txt', function($root) { unlink($root.'/proc/self/mountinfo'); }),
	'the list the boot pool is read from is gone' => array('zfs/mounts.txt', function($root) { unlink($root.'/proc/self/mounts'); }),
	'the lock table can\'t be read' => array('plugin/jobs.txt', $no_lock_table),
	'authorized_keys can\'t be opened' => array('plugin/replication-receive.txt', function($root) use ($unopenable) { $unopenable($root.'/boot/config/ssh/root/authorized_keys'); }),
	'the host key file can\'t be opened' => array('plugin/replication-receive.txt', function($root) use ($unopenable) { $unopenable($root.'/etc/ssh/ssh_host_ed25519_key.pub'); }),
	'the Main tab\'s read fails on a pool' => array('plugin/inventory.json.txt', $with('ZFSM_TEST_CHILD_LIST_FAIL', '1')),
	'Unraid\'s version file is gone' => array('unraid/context.txt', function($root) { unlink($root.'/etc/unraid-version'); }),
	'var.ini is gone' => array('unraid/context.txt', function($root) { unlink($root.'/var/local/emhttp/var.ini'); }),
	'another plugin\'s manifest can\'t be opened' => array('unraid/context.txt', function($root) use ($unopenable) { $unopenable($root.'/var/log/plugins/other.plg'); }),
	'disks.ini is gone' => array('unraid/context.txt', function($root) { unlink($root.'/var/local/emhttp/disks.ini'); }),
);
foreach ($failed_reads as $what => $case) same(array('read failed', true), $result_of($case[0], $case[1]), 'a failed read is its file\'s result and in the summary when '.$what);
$result_of('plugin/settings-saved.cfg.txt', $failed_reads['the settings file can\'t be opened'][1]);
same("(unreadable)\n", file_get_contents($work.'/unread/plugin/settings-saved.cfg.txt'), 'a settings file that can\'t be read says so in the file, not as a token');
same(true, zfsmDiagLockHeld($root.'/var/run/zfs.master.lock') !== null, 'the lock table is read again');
// A file that need not be there is no failed read.
same(array('ok', false), $result_of('plugin/settings-saved.cfg.txt', function($root) { unlink($root.'/boot/config/plugins/zfs.master/zfs.master.cfg'); }), 'a settings file that is not there is not a failed read');
same("(not there)\n", file_get_contents($work.'/unread/plugin/settings-saved.cfg.txt'), 'and the file says it is not there, not a token for those words');
same(array('ok', false), $result_of('plugin/replication-receive.txt', function($root) { unlink($root.'/etc/ssh/ssh_host_ed25519_key.pub'); }), 'nor is a host key that is not there');
has("host key: not there\n", file_get_contents($work.'/unread/plugin/replication-receive.txt'), 'and the file says it is not there');
// What was read and holds no usable value is no failed read either: the file says what is wrong with it.
same(array('ok', false), $result_of('plugin/replication-receive.txt', function($root) { file_put_contents($root.'/etc/ssh/ssh_host_ed25519_key.pub', "not a key\n"); }), 'nor is a host key file that holds no key');
has("host key: not an ed25519 key\n", file_get_contents($work.'/unread/plugin/replication-receive.txt'), 'and the file says it is no key');
same(array('ok', false), $result_of('unraid/context.txt', function($root) { file_put_contents($root.'/etc/unraid-version', "branch=\"stable\"\n"); file_put_contents($root.'/var/local/emhttp/var.ini', "NAME=\"Tower\"\n"); }), 'nor are Unraid\'s files without the values the plugin reads');
has("Unraid: not in the file\nPHP: ".PHP_VERSION."\narray: mdState=not in the file, fsState=not in the file\n", file_get_contents($work.'/unread/unraid/context.txt'), 'and the file says they are not in them');
// The file after one with a failed read starts clean.
$build();
$unopenable($root.'/boot/config/plugins/zfs.master/zfs.master.cfg');
zfsmDiagRun($work.'/next', $context(), array_slice(zfsmDiagCollectors(), 0, 2));
same(array(array('plugin/settings-saved.cfg.txt'), array('plugin/settings-effective.txt')), array($states_of($work.'/next')['read failed'], $states_of($work.'/next')['ok']), 'a failed read marks its own file and not the next');
// A timeout stays one where a read also failed, and README.txt names every result collectors.txt can hold.
$build();
putenv('ZFSM_TEST_LIST_HANG=1');
zfsmDiagRun($work.'/both', $context(), array(array('plugin/both.txt', 60, function($ctx) { return zfsmDiagUnread('(unreadable)').zfsmDiagShowAll(zfsmDiagPerPool('zfs', array('list', '-H', '-o', 'name', '-t', 'filesystem,volume', '-r'))); }, array())));
putenv('ZFSM_TEST_LIST_HANG');
same(array('plugin/both.txt'), $states_of($work.'/both')['timed out'], 'a file with a read that timed out and one that failed is timed out');
foreach (array_keys($states_of($work.'/both')) as $state) has($state, implode('', preg_grep('/^collectors\.txt /', file($work.'/both/README.txt'))), 'README.txt names the result "'.$state.'"');

// A collector that throws is noted and the rest still run.
function zfsmTestThrows($ctx) { throw new LogicException('boom'); }
function zfsmTestWrongType($ctx) { return strlen(array()); }
// As the Main tab's read does: a limit of its own, well inside its collector's.
function zfsmTestInnerLimit($ctx) { return zfsmWithDeadline(0.3, function() { return runProcess('zfs', array('list', '-H', '-o', 'name', 'tank')); }); }
$build();
$mixed = array(array('plugin/broken.txt', 5, 'zfsmTestThrows', array()), array('plugin/worse.txt', 5, 'zfsmTestWrongType', array()), array('plugin/settings-saved.cfg.txt', 5, 'zfsmDiagSettingsSaved', array()), array('plugin/inner.txt', 60, 'zfsmTestInnerLimit', array()));
putenv('ZFSM_TEST_LIST_HANG=1');
same(true, zfsmDiagRun($work.'/thrown', $context(), $mixed), 'a throwing collector does not stop the run');
putenv('ZFSM_TEST_LIST_HANG');
same(array("plugin/broken.txt\tfailed", "plugin/worse.txt\tfailed", "plugin/settings-saved.cfg.txt\tok", "plugin/inner.txt\ttimed out"), array_map(function($line) { return substr($line, 0, strrpos($line, "\t")); }, array_slice(file($work.'/thrown/collectors.txt', FILE_IGNORE_NEW_LINES), 1)), 'each collector\'s result');
same(array("failed: boom\n", "timed out: zfs timed out\n"), array(file_get_contents($work.'/thrown/plugin/broken.txt'), file_get_contents($work.'/thrown/plugin/inner.txt')), 'a file whose collector failed or timed out says why');
same(true, is_file($work.'/thrown/summary.txt') && is_file($work.'/thrown/README.txt'), 'the summary and README are still written');
has("- a read timed out, so these files are incomplete: plugin/inner.txt\n- a collector failed, so these files hold only its error: plugin/broken.txt, plugin/worse.txt\n", file_get_contents($work.'/thrown/summary.txt'), 'the summary names the files that timed out and those that failed');
// A folder that can't be written says so instead of passing.
file_put_contents($work.'/a-file', '');
same(false, zfsmDiagRun($work.'/a-file/inside', $context(), $mixed), 'files that could not be written are reported');
// A run whose folder is removed under it ends there: one made again in its place would be under no lock of the run's.
function zfsmTestLosesFolder($ctx) { exec('rm -rf '.escapeshellarg($GLOBALS['zfsm_test_lost'])); return 'first'; }
function zfsmTestSwapsFolder($ctx) { zfsmTestLosesFolder($ctx); mkdir($GLOBALS['zfsm_test_lost'], 0700); return 'first'; }
$GLOBALS['zfsm_test_lost'] = $work.'/lost';
foreach (array('zfsmTestLosesFolder' => array(), 'zfsmTestSwapsFolder' => array('.', '..')) as $loses => $left) {
	$lost_run = zfsmDiagRun($work.'/lost', $context(), array(array('plugin/first.txt', 5, $loses, array()), array('zfs/second.txt', 5, 'zfsmDiagSettingsSaved', array())));
	same(array(null, $left), array($lost_run, is_dir($work.'/lost') ? scandir($work.'/lost') : array()), 'a run whose folder went under it ends, writes no more and says so apart from a failed write: '.$loses);
}
// Only the folder zfsmDiagWorkDir made and still locks is the run's: not one made in its place, nor any other.
$ours = zfsmDiagWorkDir();
$was_ours = zfsmDiagStillOurs($ours);
exec('rm -rf '.escapeshellarg($ours));
mkdir($ours, 0700);
same(array(true, false, false), array($was_ours, zfsmDiagStillOurs($ours), zfsmDiagStillOurs($work)), 'a work folder is the run\'s only while it is the one it locked; a folder it never made is not');
rmdir($ours);
same(array(false, false, false), array(zfsmDiagWrite($work.'/never', 'plugin/first.txt', 'first'), zfsmDiagWrite($work.'/never', 'summary.txt', 'first'), file_exists($work.'/never')), 'a file\'s write makes its own folder, never the run\'s');
// The Main tab's read gives a pool whose devices could not be read an empty list: in the file that is a read that failed.
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL=1');
zfsmDiagRun($work.'/no-devices', $context(), array(array('plugin/inventory.json.txt', 60, 'zfsmDiagInventory', array())));
putenv('ZFSM_TEST_ZPOOL_STATUS_FAIL');
has("plugin/inventory.json.txt\tread failed\t", file_get_contents($work.'/no-devices/collectors.txt'), 'a pool whose devices could not be read is not an ok inventory with none');

// The work folder: root's alone, removed with all in it, and nothing else is ever removed.
$build();
$first = zfsmDiagWorkDir();
same(array(1, 0700), array(preg_match('#^'.preg_quote($root, '#').'/tmp/zfs\.master-diag\.[0-9a-f]{16}$#D', (string)$first), fileperms($first) & 0777), 'a work folder with a name of its own, for its owner alone');
mkdir($work.'/outside');
file_put_contents($work.'/outside/keep', 'keep');
mkdir($first.'/plugin');
file_put_contents($first.'/plugin/log.txt', 'names');
symlink($work.'/outside', $first.'/link');
same(true, zfsmDiagRemove($first), 'a removed work folder is reported as removed');
same(array(false, 'keep'), array(file_exists($first), file_get_contents($work.'/outside/keep')), 'the folder goes, and a link in it is removed, not followed');
foreach (array($work.'/outside', $root.'/tmp', $root.'/tmp/zfs.master-diag.zzzzzzzzzzzzzzzz') as $other) {
	if (!is_dir($other)) mkdir($other);
	same(array(false, true), array(zfsmDiagRemove($other), is_dir($other)), 'only a work folder is removed: '.$other);
}
mkdir($work.'/elsewhere');
file_put_contents($work.'/elsewhere/keep', 'keep');
symlink($work.'/elsewhere', $root.'/tmp/zfs.master-diag.0123456789abcdef');
zfsmDiagRemove($root.'/tmp/zfs.master-diag.0123456789abcdef');
same(true, is_file($work.'/elsewhere/keep'), 'a link named like a work folder is not followed');
unlink($root.'/tmp/zfs.master-diag.0123456789abcdef');
// A killed run leaves its folder: the next run removes it once no run holds it. One a run still holds stays, however old,
// and so does one just made, whose run may not hold it yet. A file of that name is no work folder.
$stale = $root.'/tmp/zfs.master-diag.00000000000000aa';
$busy = $root.'/tmp/zfs.master-diag.00000000000000bb';
$fresh = $root.'/tmp/zfs.master-diag.00000000000000cc';
$plain = $root.'/tmp/zfs.master-diag.00000000000000dd';
$foreign = $root.'/tmp/zfs.master-diag.00000000000000ff';
foreach (array($stale, $busy, $fresh, $foreign) as $folder) {
	mkdir($folder);
	file_put_contents($folder.'/summary.txt', 'names');
}
file_put_contents($plain, 'a file');
touch($stale, time() - 120);
touch($busy, time() - 7200);
touch($plain, time() - 120);
// Not root's: its owner could swap it for a link while it is removed.
chown($foreign, 1000);
touch($foreign, time() - 120);
// Another user's link to a folder of root's: the folder passes every check, the path does not.
$linked = $root.'/tmp/zfs.master-diag.00000000000000ab';
symlink($work.'/elsewhere', $linked);
lchown($linked, 1000);
touch($work.'/elsewhere', time() - 120);
same(false, zfsmDiagHold($linked, LOCK_NB), 'a link to a folder of root\'s is never held, so never removed');
$GLOBALS['zfsm_busy'] = fopen($busy, 'r');
flock($GLOBALS['zfsm_busy'], LOCK_EX);
try {
	$second = zfsmDiagWorkDir();
} catch (Throwable $error) {
	same(null, get_class($error).': '.$error->getMessage(), 'a file named like a work folder is left alone');
}
same(array(false, true, true, true, true), array(file_exists($stale), is_file($busy.'/summary.txt'), is_dir($fresh), is_file($plain), is_dir($second)), 'a folder no run holds goes; one a run holds stays however old, and so do one just made and a file of that name');
same(true, is_file($foreign.'/summary.txt'), 'a folder that is not root\'s is left alone, however old');
same(array(true, true), array(is_link($linked), is_file($work.'/elsewhere/keep')), 'and so are a link and the folder it points to');
unlink($linked);
unlink($plain);
same(true, zfsmDiagLockHeld($second), 'a run holds its own work folder');
// The lock ends with the run, whatever the run leaves running: a command it starts gets no handle on the folder.
exec('ls -l /proc/self/fd', $handles);
same(array(), preg_grep('~'.preg_quote(basename($second), '~').'~', $handles), 'a command the run starts does not hold the run\'s lock');
fclose($GLOBALS['zfsm_busy']);
zfsmDiagWorkDir();
same(array(false, true), array(file_exists($busy), is_dir($second)), 'once its run has ended that folder goes too, and this run\'s stays');
// A run stopped between making its folder and holding it can lose the folder to a later run: it must end there, not write on.
$late = function($made_again) use ($root) {
	$dir = $root.'/tmp/zfs.master-diag.00000000000000ee';
	mkdir($dir, 0700);
	$path = realpath($dir);
	$remover = fopen($dir, 're');
	flock($remover, LOCK_EX);
	$load = 'require '.var_export(dirname(__DIR__).'/zfs.master/backend/ZFSMOperations.php', true).'; require '.var_export(dirname(__DIR__).'/zfs.master/include/ZFSMDiagnostics.php', true).';';
	$run = proc_open(array(PHP_BINARY, '-n', '-r', $load.' echo "loaded\n"; echo zfsmDiagHold($argv[1]) === false ? "ended" : "held";', '--', $dir), array(1 => array('pipe', 'w')), $pipes);
	$pid = proc_get_status($run)['pid'];
	// Until it has loaded, the new process may still show this one's handles.
	same("loaded\n", fgets($pipes[1]), 'the stopped run has started');
	$opened = function() use ($pid, $path) {
		foreach (glob('/proc/'.$pid.'/fd/*') ?: array() as $fd) if (@readlink($fd) === $path) return true;
		return false;
	};
	for ($waited = 0; !$opened() && $waited < 100; $waited++) usleep(100000);
	same(true, $opened(), 'the stopped run has its folder open, and waits for the lock');
	rmdir($dir);
	if ($made_again) mkdir($dir, 0700);
	fclose($remover);
	$said = stream_get_contents($pipes[1]);
	proc_close($run);
	if ($made_again) rmdir($dir);
	return $said;
};
same(array('ended', 'ended'), array($late(false), $late(true)), 'a run whose folder went before it held it ends, also when a folder of that name is there again');

// A filesystem mounted in a work folder is not the run's: the folder is left whole, and also while the mount table can't be read.
$mount_in = function($folder) use ($work) {
	exec('mount --bind '.escapeshellarg($work.'/outside').' '.escapeshellarg($folder.'/zfs').' 2>&1', $said, $code);
	same(0, $code, 'a folder is mounted inside '.$folder.': '.implode(' ', $said));
};
$unmount = function($point) {
	exec('umount '.escapeshellarg($point).' 2>&1', $said, $code);
	same(0, $code, $point.' is unmounted: '.implode(' ', $said));
};
$with_mount = zfsmDiagWorkDir();
mkdir($with_mount.'/zfs');
file_put_contents($with_mount.'/summary.txt', 'names');
$mount_in($with_mount);
same(array(false, true, true), array(zfsmDiagRemove($with_mount), is_file($with_mount.'/summary.txt'), is_file($work.'/outside/keep')), 'a work folder with a mount in it is left whole, and so is what is mounted there');
$unmount($with_mount.'/zfs');
// The table this process reads, with $over mounted in its place: whether the folder was then removed, and whether it is still there.
$with_table = function($over) use ($with_mount, $unmount) {
	exec('mount --bind '.escapeshellarg($over).' /proc/'.getmypid().'/mountinfo 2>&1', $said, $code);
	same(0, $code, 'the mount table is hidden: '.implode(' ', $said));
	$refused = array(zfsmDiagRemove($with_mount), is_file($with_mount.'/summary.txt'));
	$unmount('/proc/'.getmypid().'/mountinfo');
	return $refused;
};
$GLOBALS['zfsm_sockets'][] = stream_socket_server('unix://'.$work.'/no-mounts');
same(array(false, true), $with_table($work.'/no-mounts'), 'nor is a work folder removed while the mount table can\'t be read');
same(array(false, true), $with_table('/dev/null'), 'nor while the table reads as empty: an empty one was not read');
same(array(true, false), array(zfsmDiagRemove($with_mount), file_exists($with_mount)), 'with the table read and no mount in it, the folder goes');
// The plugin's removal has the same rule in the shell: its lines of zfs.master.plg, run as they are.
same(1, preg_match('/^# A diagnostics work folder goes.*?^done$/ms', file_get_contents(__DIR__.'/../zfs.master.plg'), $step), 'the removal step\'s lines are in zfs.master.plg');
$step = str_replace('&name;', 'zfs.master', $step[0]);
$kept = '/tmp/zfs.master-diag.00000000000000a1';
$swept = '/tmp/zfs.master-diag.00000000000000a2';
foreach (array($kept, $swept) as $folder) {
	mkdir($folder.'/zfs', 0700, true);
	file_put_contents($folder.'/summary.txt', 'names');
}
$mount_in($kept);
// Another user's folder of that name, and a link of that name to a folder of root's: neither is the plugin's to remove.
// The link is root's own: the kernel already stops root from following another user's link in /tmp.
$theirs = '/tmp/zfs.master-diag.00000000000000a3';
$a_link = '/tmp/zfs.master-diag.00000000000000a4';
mkdir($theirs, 0700);
file_put_contents($theirs.'/summary.txt', 'theirs');
chown($theirs, 1000);
symlink($work.'/elsewhere', $a_link);
exec('bash -c '.escapeshellarg($step).' 2>&1', $said, $code);
// A folder whose run still holds its lock is that run's: it writes there until it ends, and removes the folder itself.
$running = '/tmp/zfs.master-diag.00000000000000a5';
mkdir($running, 0700);
file_put_contents($running.'/summary.txt', 'names');
$running_hold = zfsmDiagHold($running);
exec('bash -c '.escapeshellarg($step).' 2>&1', $said, $code);
same(array(0, '', true, true, false), array($code, implode("\n", $said), is_file($kept.'/summary.txt'), is_file($work.'/outside/keep'), file_exists($swept)), 'the plugin\'s removal leaves a work folder with a mount in it whole, and removes one without');
same(array(true, true), array(is_resource($running_hold), is_file($running.'/summary.txt')), 'and it leaves a folder whose run still holds its lock');
fclose($running_hold);
same(array(true, true, true), array(is_file($theirs.'/summary.txt'), is_link($a_link), is_file($work.'/elsewhere/keep')), 'and it leaves another user\'s folder of that name, a link of that name, and what the link points to');
unlink($a_link);
unlink($theirs.'/summary.txt');
rmdir($theirs);
$unmount($kept.'/zfs');
// With no table to read, or an empty one, the step removes nothing; nor does it touch a name no run makes, whatever is mounted in it.
file_put_contents($work.'/empty-table', '');
foreach (array('a table that is not there' => $work.'/no-table', 'an empty table' => $work.'/empty-table') as $what => $table) {
	exec('bash -c '.escapeshellarg(str_replace('/proc/self/mountinfo', $table, $step)).' 2>/dev/null', $said, $code);
	same(true, is_file($kept.'/summary.txt'), 'the plugin\'s removal leaves a work folder alone with '.$what);
}
$spaced = '/tmp/zfs.master-diag.not a run';
mkdir($spaced.'/zfs', 0700, true);
$mount_in($spaced);
exec('bash -c '.escapeshellarg($step).' 2>&1', $said, $code);
same(array(0, false, true, true), array($code, file_exists($kept), is_dir($spaced.'/zfs'), is_file($work.'/outside/keep')), 'that folder goes once the mount is gone; a folder with a name no run makes is not touched, nor what is mounted in it');
same(false, file_exists($running), 'and so does the folder whose run has ended');
$unmount($spaced.'/zfs');
rmdir($spaced.'/zfs');
rmdir($spaced);

// From a terminal: the script prints each file's result and the summary, and leaves no folder behind.
// It writes its zip into /boot/logs: only on the empty tmpfs the runner mounts there (tests/run_http_inventory_tests.sh).
if (preg_match('~^\S+ \S+ \S+ \S+ /boot/logs \S+(?: \S+)* - tmpfs ~m', (string)@file_get_contents('/proc/self/mountinfo')) !== 1 || array_diff(scandir('/boot/logs'), array('.', '..'))) {
	fwrite(STDERR, "FAILED: /boot/logs must be an empty tmpfs mounted for this test\n");
	exit(1);
}
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (!is_string($docroot) || !is_file($docroot.'/plugins/zfs.master/scripts/diagnostics.php')) {
	fwrite(STDERR, "FAILED: the script needs ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}
exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($docroot.'/plugins/zfs.master/scripts/diagnostics.php').' 2>&1', $printed, $code);
$printed = implode("\n", $printed);
same(0, $code, 'the script ends well: '.$printed);
has("file\tresult\tseconds\nplugin/settings-saved.cfg.txt\tok\t", $printed, 'it prints each file\'s result');
has("zfs/timings.txt\tok\t", $printed, 'to the last one');
has("\nOpenZFS: zfs-2.4.4-1; zfs-kmod-2.4.4-1\n", $printed, 'and the summary');
has("\nFINDINGS (", $printed, 'with its findings');
// The zip the run made, and no other file, is removed again.
same(1, preg_match('~\nDiagnostics zip: (/boot/logs/zfs\.master-diagnostics-\d{8}-\d{6}\.zip)$~D', $printed, $made), 'and last the zip\'s path');
unlink($made[1]);
same(array(), glob('/tmp/zfs.master-diag.*'), 'and removes its work folder');
// As a page the script does nothing: a request to its address starts no run.
$cgi = dirname(PHP_BINARY).'/php-cgi';
same(true, is_executable($cgi), 'php-cgi is beside the test\'s PHP: '.$cgi);
exec(escapeshellarg($cgi).' -n -q '.escapeshellarg($docroot.'/plugins/zfs.master/scripts/diagnostics.php').' 2>&1', $served, $code);
same(array(1, '', array()), array($code, implode("\n", $served), glob('/tmp/zfs.master-diag.*')), 'the script served as a page ends at once, with no output and no work folder');

// Ctrl-C, a dropped session or a kill: where PHP has pcntl the folder goes then too. ZFSM_TEST_PCNTL names the extension to load.
$pcntl = getenv('ZFSM_TEST_PCNTL');
foreach ($pcntl ? array('INT' => 2, 'HUP' => 1, 'TERM' => 15) : array() as $name => $signal) {
	$stopped = proc_open(array(PHP_BINARY, '-n', '-d', 'extension='.$pcntl, $docroot.'/plugins/zfs.master/scripts/diagnostics.php'), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, array('ZFSM_TEST_LIST_HANG' => '1') + getenv());
	for ($wait = 0; $wait < 100 && !glob('/tmp/zfs.master-diag.*'); $wait++) usleep(50000);
	same(1, count(glob('/tmp/zfs.master-diag.*')), 'the run has its work folder before SIG'.$name);
	usleep(300000);
	proc_terminate($stopped, $signal);
	for ($wait = 0; $wait < 100 && proc_get_status($stopped)['running']; $wait++) usleep(50000);
	proc_close($stopped);
	same(array(array(), array('state' => 'failed', 'why' => 'The run was stopped before it ended')), array(glob('/tmp/zfs.master-diag.*'), zfsmDiagJobState()), 'a run ended by SIG'.$name.' removes its work folder, and the page reads it as stopped');
}
if (!$pcntl) echo "diagnostics: the three signal cases were not run (no ZFSM_TEST_PCNTL)\n";

echo "diagnostics tests passed\n";
