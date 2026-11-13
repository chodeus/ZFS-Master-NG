<?php
// The Settings page's diagnostics through the real routes (backend/ZFSMAdmin.php), the real job (scripts/diagnostics.php --job)
// and the real download (backend/ZFSMDiagDownload.php, served by php-cgi), with the fake zfs, zpool, ps and Unraid diagnostics.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
// This test starts jobs that write zips into /boot/logs and removes them: only on the tmpfs the runner mounts there
// (tests/run_http_inventory_tests.sh), and only while it is empty, so nothing there is another's.
$own_logs = preg_match('~^\S+ \S+ \S+ \S+ /boot/logs \S+(?: \S+)* - tmpfs ~m', (string)@file_get_contents('/proc/self/mountinfo')) === 1;
if (is_file('/etc/unraid-version') || !$own_logs || array_diff(scandir('/boot/logs'), array('.', '..'))) {
	fwrite(STDERR, "FAILED: /boot/logs must be an empty tmpfs mounted for this test\n");
	exit(1);
}

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$docroot = getenv('ZFSM_TEST_DOCROOT');
$work = sys_get_temp_dir().'/zfsm-page-test-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work.'/bin', 0700, true);
$zips = function() { return glob('/boot/logs/zfs.master-diagnostics-*.zip') ?: array(); };
// The page's runs and what the fake Unraid diagnostics left behind, found by their command lines: these images have no pkill.
$kill = function() {
	foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $file) {
		$command = (string)@file_get_contents($file);
		if (strpos($command, 'scripts/diagnostics.php') !== false && strpos($command, '--job') !== false || $command === "sleep\x0077\x00") exec('kill -KILL '.(int)basename(dirname($file)));
	}
};
$clear = function() use ($zips, $kill) {
	$kill();
	array_map('unlink', array_merge($zips(), glob('/boot/logs/canarytower-diagnostics-*.zip') ?: array()));
	exec('rm -rf /tmp/zfs.master-diag.*');
};
register_shutdown_function(function() use ($work, $clear) { $clear(); exec('rm -rf '.escapeshellarg($work)); });
foreach (array(getenv('ZFSM_TEST_BIN_DIR').'/zfs', getenv('ZFSM_TEST_BIN_DIR').'/zpool', __DIR__.'/fixtures/bin/ps', __DIR__.'/fixtures/bin/diagnostics') as $fake) {
	copy($fake, $work.'/bin/'.basename($fake));
	chmod($work.'/bin/'.basename($fake), 0755);
}
putenv('ZFSM_TEST_BIN_DIR='.$work.'/bin');
putenv('ZFSM_TEST_JOB_DIR='.$work.'/jobs');
putenv('ZFSM_TEST_DIAG_LOGS=/boot/logs');
$clear();

// One request to the admin route, in a process of its own as a page's is: its answer, decoded.
$ask = function($post, $get = array()) use ($docroot) {
	$run = 'error_reporting(E_ALL); $_POST = json_decode(getenv("ZFSM_TEST_POST"), true); $_GET = json_decode(getenv("ZFSM_TEST_GET"), true); $docroot = getenv("ZFSM_TEST_DOCROOT"); '
		.'$_SERVER["DOCUMENT_ROOT"] = $docroot; $GLOBALS["zfsm_php"] = PHP_BINARY; require $docroot."/plugins/zfs.master/backend/ZFSMAdmin.php";';
	exec('ZFSM_TEST_POST='.escapeshellarg(json_encode($post)).' ZFSM_TEST_GET='.escapeshellarg(json_encode($get)).' '.escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($run).' 2>&1', $said, $code);
	return json_decode(implode("\n", $said), true) ?? implode("\n", $said);
};
$state = function() use ($ask) { return $ask(array('cmd' => 'diagnosticsstatus')); };
$finished = function() use ($state) {
	for ($waited = 0; $waited < 240 && ($now = $state()) === array('state' => 'running'); $waited++) usleep(500000);
	return $now;
};

same(array('state' => 'none'), $state(), 'no run yet');
// The page's request is a POST: the same words in an address start nothing.
$got = $ask(array(), array('cmd' => 'diagnosticsstart', 'unraid' => '0'));
same(array('Unknown or missing command', array('state' => 'none'), array()), array($got['failed']['request'] ?? $got, $state(), $zips()), 'a GET starts no run');

// A run: the page is answered at once, a second start while it runs is told so, and its state follows it to the zip.
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
$report = json_encode(array('userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0 Safari/537.36',
	'kept' => array('zfsm-datasetsHidden' => '["tank/data"]', 'zfsm-diagnostics' => '{"status":{"text":"Loading CanaryShare failed"}}')));
same(array('state' => 'started', 'why' => ''), $ask(array('cmd' => 'diagnosticsstart', 'unraid' => '0', 'report' => $report)), 'the first start');
same(array(array('state' => 'running', 'why' => ''), array('state' => 'running')), array($ask(array('cmd' => 'diagnosticsstart', 'unraid' => '0', 'report' => 'another')), $state()), 'a second start while it runs starts nothing, and the state says running');
// The job itself, started beside the one that runs: it ends at once, whoever started it.
exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($docroot.'/plugins/zfs.master/scripts/diagnostics.php').' --job --no-unraid 2>&1', $beside, $code);
same(array(1, '', array('state' => 'running')), array($code, implode("\n", $beside), $state()), 'a second job ends at once and leaves the first one\'s state');
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
$done = $finished();
same(array('done', 1, 1), array($done['state'] ?? $done, preg_match('/^zfs\.master-diagnostics-\d{8}-\d{6}\.zip$/D', $done['zip'] ?? ''), count($zips())), 'the run ends with its zip\'s name, and one zip is made: '.json_encode($done));
exec('unzip -q '.escapeshellarg('/boot/logs/'.$done['zip']).' -d '.escapeshellarg($work.'/first'));
$browser = (string)@file_get_contents($work.'/first/plugin/browser.json.txt');
same(array(true, true, false, false, array()), array(strpos($browser, 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0 Safari/537.36') !== false, preg_match('~tank/name-[0-9a-f]{8}~', $browser) === 1, strpos($browser, 'tank/data'), stripos($browser, 'canary'), glob($work.'/first/unraid/*.zip')),
	'the zip holds the first request\'s report, a browser\'s version left as it is, a dataset and a word of a status text as tokens, and no Unraid zip when the box was off: '.$browser);
same(array(), glob('/tmp/zfs.master-diag.*'), 'the job removes its work folder');

// With the box on, Unraid's zip is in it; a report longer than 64 KB is kept to that.
same('started', $ask(array('cmd' => 'diagnosticsstart', 'unraid' => '1', 'report' => str_repeat('x', 100000)))['state'] ?? null, 'a start with the box on');
$second = $finished();
exec('unzip -q '.escapeshellarg('/boot/logs/'.($second['zip'] ?? '')).' -d '.escapeshellarg($work.'/second'));
same(array('done', 1, 65536), array($second['state'] ?? $second, count(glob($work.'/second/unraid/*.zip')), filesize($work.'/jobs/diagnostics/browser.json')), 'Unraid\'s zip is in, and the report is cut at 64 KB');

// The download: the plugin's zips by their own names, byte for byte, and nothing else.
$cgi = dirname(PHP_BINARY).'/php-cgi';
$fetch = function($query, $method = 'GET') use ($cgi, $docroot, $work) {
	$script = $docroot.'/plugins/zfs.master/backend/ZFSMDiagDownload.php';
	exec('REDIRECT_STATUS=1 REQUEST_METHOD='.$method.' QUERY_STRING='.escapeshellarg($query).' SCRIPT_FILENAME='.escapeshellarg($script).' DOCUMENT_ROOT='.escapeshellarg($docroot).' '.escapeshellarg($cgi).' -n > '.escapeshellarg($work.'/answer').' 2>&1');
	return explode("\r\n\r\n", file_get_contents($work.'/answer'), 2) + array('', '');
};
$zip = '/boot/logs/'.$second['zip'];
list($headers, $body) = $fetch('file='.$second['zip']);
same(array(true, false, true, true), array($body === file_get_contents($zip), strpos($headers, 'Status: 404'), strpos($headers, 'Content-Type: application/zip') !== false, strpos($headers, 'Content-Disposition: attachment; filename="'.$second['zip'].'"') !== false),
	'a zip of the plugin\'s is sent whole, as a file to save: '.$headers);
file_put_contents('/boot/logs/zfs.master-diagnostics-20260101-000000.zip.part', 'part');
file_put_contents('/boot/logs/other-diagnostics-20260101-000000.zip', 'not ours');
symlink('/etc/hostname', '/boot/logs/zfs.master-diagnostics-20260101-000001.zip');
$refused = array('' => 'no name', 'file=canarytower-diagnostics-20260102-1000.zip' => 'Unraid\'s zip', 'file=../../etc/hostname' => 'a path', 'file=/boot/logs/'.$second['zip'] => 'a whole path',
	'file=zfs.master-diagnostics-20260101-000000.zip.part' => 'a part', 'file=other-diagnostics-20260101-000000.zip' => 'another zip in the folder', 'file=zfs.master-diagnostics-20260101-000001.zip' => 'a link', 'file=zfs.master-diagnostics-20260101-000002.zip' => 'a zip that is not there', 'file[]='.$second['zip'] => 'a list');
foreach ($refused as $query => $what) {
	list($headers, $body) = $fetch($query);
	same(array(true, ''), array(strpos($headers, 'Status: 404') !== false, $body), 'the download refuses '.$what.': '.$headers);
}
list($headers, $body) = $fetch('file='.$second['zip'], 'POST');
same(array(true, ''), array(strpos($headers, 'Status: 404') !== false, $body), 'and anything but a GET');
unlink('/boot/logs/zfs.master-diagnostics-20260101-000000.zip.part');
unlink('/boot/logs/other-diagnostics-20260101-000000.zip');
unlink('/boot/logs/zfs.master-diagnostics-20260101-000001.zip');

// A zip removed since is not offered; a run that was killed is a failure, not a run still going.
array_map('unlink', $zips());
same(array('state' => 'none'), $state(), 'a zip that is gone is not offered');
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
same('started', $ask(array('cmd' => 'diagnosticsstart', 'unraid' => '0', 'report' => '{}'))['state'] ?? null, 'a run to kill');
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');
$kill();
for ($waited = 0; $waited < 40 && $state() === array('state' => 'running'); $waited++) usleep(250000);
same(array('state' => 'failed', 'why' => 'The run ended without a result'), $state(), 'a killed run is a failed one');

// A command the run started and left behind holds none of its locks: once the run is done, the page says so and starts another.
$clear();
putenv('ZFSM_TEST_DIAG=leave');
same('started', $ask(array('cmd' => 'diagnosticsstart', 'unraid' => '1', 'report' => '{}'))['state'] ?? null, 'a run whose Unraid diagnostics leave a command behind');
putenv('ZFSM_TEST_DIAG');
$left = $finished();
$behind = array_filter(glob('/proc/[0-9]*/cmdline') ?: array(), function($file) { return (string)@file_get_contents($file) === "sleep\x0077\x00"; });
same(array('done', true, 'started'), array($left['state'] ?? $left, count($behind) === 1, $ask(array('cmd' => 'diagnosticsstart', 'unraid' => '0', 'report' => '{}'))['state'] ?? null),
	'with the command still running, the run reads done and a new one starts: '.json_encode($left));
$finished();
$clear();

// Two starts at once: one starts, the other is told a run is going, and the run keeps the report of the request that started it.
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW=1');
$admin = 'error_reporting(E_ALL); $_POST = json_decode(getenv("ZFSM_TEST_POST"), true); $_GET = array(); $docroot = getenv("ZFSM_TEST_DOCROOT"); '
	.'$_SERVER["DOCUMENT_ROOT"] = $docroot; $GLOBALS["zfsm_php"] = PHP_BINARY; require $docroot."/plugins/zfs.master/backend/ZFSMAdmin.php";';
for ($trial = 0; $trial < 6; $trial++) {
	$procs = $pipes = $answers = array();
	foreach (array('A', 'B') as $who) {
		$post = json_encode(array('cmd' => 'diagnosticsstart', 'unraid' => '0', 'report' => 'REPORT-'.$who));
		$procs[$who] = proc_open('ZFSM_TEST_POST='.escapeshellarg($post).' '.escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($admin).' 2>&1', array(1 => array('pipe', 'w')), $pipes[$who]);
	}
	foreach ($procs as $who => $proc) {
		$answers[$who] = json_decode((string)stream_get_contents($pipes[$who][1]), true)['state'] ?? null;
		proc_close($proc);
	}
	$winner = array_search('started', $answers, true);
	$both = array_values($answers);
	sort($both);
	same(array(array('running', 'started'), 'REPORT-'.$winner), array($both, (string)@file_get_contents($work.'/jobs/diagnostics/browser.json')),
		'two starts at once, trial '.($trial + 1).': '.json_encode($answers));
	$clear();
}
putenv('ZFSM_TEST_ZPOOL_STATUS_SLOW');

echo "diagnostics page tests passed\n";
