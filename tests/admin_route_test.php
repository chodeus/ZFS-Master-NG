<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

$routes = array(
	'createdataset' => array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'new', 'mount' => 'yes', 'encryption' => 'no', 'quota' => '', 'quotaunit' => 'G')),
	'editdatasetproperty' => array('cmd' => 'editdatasetproperty', 'zdataset' => 'tank/data', 'property' => 'compression', 'value' => 'zstd'),
	'snapshotdataset' => array('cmd' => 'snapshotdataset', 'zdataset' => 'tank/data', 'recursive' => '1'),
	'snapshotdataset_utc' => array('cmd' => 'snapshotdataset', 'zdataset' => 'tank/data', 'recursive' => '0'),
	'rollbacksnapshot' => array('cmd' => 'rollbacksnapshot', 'snapshot' => 'tank/data@daily', 'destroy_newer' => '0'),
	'renamesnapshot' => array('cmd' => 'renamesnapshot', 'snapshot' => 'tank/data@daily', 'newname' => 'renamed'),
	'destroysnapshot' => array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data@daily', 'recursive' => '0'),
	'destroydataset' => array('cmd' => 'destroydataset', 'zdataset' => 'tank/data', 'force' => '1'),
	'previewrewrite' => array('cmd' => 'previewrewrite', 'zdataset' => 'tank/vol'),
	'rewritedataset' => array('cmd' => 'rewritedataset', 'zdataset' => 'tank/vol'),
	'previewreplicate' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data'),
	'replicatedataset' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'nowhere/data'),
	'previewreplicate_server' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'server' => 'nas', 'destination' => ''),
	'replicatedataset_server' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'server' => 'nas', 'destination' => 'backup/data'),
	'replicatest' => array('cmd' => 'replicatest', 'name' => 'nas')
);
$replication = array('previewreplicate', 'replicatedataset', 'previewreplicate_server', 'replicatedataset_server', 'replicatest');

$route = $argv[1] ?? '';
if (!isset($routes[$route])) exit(2);
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_POST = $routes[$route];
if ($route === 'createdataset') {
	putenv('ZFSM_TEST_MISSING_DATASET=tank/new');
	$mount = sys_get_temp_dir().'/zfsm-route-new';
	if (!is_dir($mount)) mkdir($mount);
	putenv('ZFSM_TEST_DATASET_MOUNT='.$mount);
}

// The replication routes run on the fake's small pool, with no trusted server (tests/replicate_test.php covers the runs themselves).
if (in_array($route, $replication, true)) {
	$pool = sys_get_temp_dir().'/zfsm-route-repl-'.getmypid();
	@mkdir($pool);
	file_put_contents($pool.'/datasets', "tank/data\tfilesystem\nbackup\tfilesystem\n");
	file_put_contents($pool.'/marks', "tank/data@a\t11\t10\ntank/data@b\t12\t20\n");
	file_put_contents($pool.'/props', '');
	putenv('ZFSM_TEST_REPL_DIR='.$pool);
	putenv('ZFSM_TEST_SSH_DIR='.$pool.'/ssh');
}

// These need a token from the review the dialogs show first.
require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
if ($route === 'rollbacksnapshot') $_POST['token'] = zfsmRollbackPreview($_POST['snapshot'])['token'];
if ($route === 'destroydataset') $_POST['token'] = zfsmDestroyPreview($_POST['zdataset'], $_POST['force'])['token'];
if ($route === 'destroysnapshot') $_POST['token'] = zfsmDestroyPreview($_POST['snapshot'], $_POST['recursive'])['token'];

// Perth is UTC+8 all year, so a name on the wrong clock is 8 hours off.
if ($route === 'snapshotdataset_utc') {
	putenv('ZFSM_TEST_SNAP_UTC=1');
	date_default_timezone_set('Australia/Perth');
}
$started = time();
ob_start();
require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
$output = ob_get_clean();
$answer = json_decode($output, true);
if ($route === 'snapshotdataset_utc') {
	$utc = array_map(function($second) { return 'tank/data@'.gmdate('Y-m-d-His', $second); }, range($started, time()));
	if (!in_array(array_keys($answer['succeeded'] ?? array())[0] ?? '', $utc, true)) {
		fwrite(STDERR, 'a snapshot taken with UTC names set was not named in UTC: '.$output."\n");
		exit(1);
	}
}
// Replication: the preview plans a first, full copy; a start whose copy has nowhere to go says why and starts nothing; the server
// reaches each route, which asks for it by name.
if (in_array($route, $replication, true)) {
	$untrusted = 'No server here is named nas';
	$said = array(
		'previewreplicate' => ($answer['snapshots'] ?? null) === array('b', 'a') && ($answer['can_proceed'] ?? null) === true && ($answer['mode'] ?? '') === 'full',
		'replicatedataset' => ($answer['failed']['tank/data'] ?? '') === 'The copy goes inside nowhere, which doesn\'t exist',
		'previewreplicate_server' => ($answer['error'] ?? '') === $untrusted,
		'replicatedataset_server' => ($answer['failed']['tank/data'] ?? '') === $untrusted,
		'replicatest' => ($answer['error'] ?? '') === $untrusted);
	$ok = $said[$route];
	if (!$ok) {
		fwrite(STDERR, $route.' answered: '.$output."\n");
		exit(1);
	}
	echo $route." route passed\n";
	exit(0);
}
// The rewrite routes on the fixture's volume, which has no files: each answers why instead of starting anything.
if ($route === 'previewrewrite' || $route === 'rewritedataset') {
	$said = $route === 'previewrewrite' ? ($answer['error'] ?? '') : ($answer['failed']['tank/vol'] ?? '');
	if ($said !== 'Only an existing filesystem dataset has files to rewrite') {
		fwrite(STDERR, $route.' answered: '.$output."\n");
		exit(1);
	}
	echo $route." route passed\n";
	exit(0);
}
if (!is_array($answer) || !isset($answer['succeeded'], $answer['failed']) || count($answer['failed']) !== 0 || count($answer['succeeded']) === 0) {
	fwrite(STDERR, $route.' failed: '.$output."\n");
	exit(1);
}
echo $route." route passed\n";
