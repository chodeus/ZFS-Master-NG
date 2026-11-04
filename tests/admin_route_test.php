<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$routes = array(
	'createdataset' => array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'new', 'mount' => 'yes', 'encryption' => 'no', 'quota' => '', 'quotaunit' => 'G')),
	'editdatasetproperty' => array('cmd' => 'editdatasetproperty', 'zdataset' => 'tank/data', 'property' => 'compression', 'value' => 'zstd'),
	'snapshotdataset' => array('cmd' => 'snapshotdataset', 'zdataset' => 'tank/data', 'recursive' => '1'),
	'rollbacksnapshot' => array('cmd' => 'rollbacksnapshot', 'snapshot' => 'tank/data@daily', 'destroy_newer' => '0'),
	'renamesnapshot' => array('cmd' => 'renamesnapshot', 'snapshot' => 'tank/data@daily', 'newname' => 'renamed'),
	'destroysnapshot' => array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data@daily', 'recursive' => '0'),
	'destroydataset' => array('cmd' => 'destroydataset', 'zdataset' => 'tank/data', 'force' => '1')
);

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

// These need a token from the review the dialogs show first.
require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
if ($route === 'rollbacksnapshot') $_POST['token'] = zfsmRollbackPreview($_POST['snapshot'])['token'];
if ($route === 'destroydataset') $_POST['token'] = zfsmDestroyPreview($_POST['zdataset'], $_POST['force'])['token'];
if ($route === 'destroysnapshot') $_POST['token'] = zfsmDestroyPreview($_POST['snapshot'], $_POST['recursive'])['token'];

ob_start();
require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
$output = ob_get_clean();
$answer = json_decode($output, true);
if (!is_array($answer) || !isset($answer['succeeded'], $answer['failed']) || count($answer['failed']) !== 0 || count($answer['succeeded']) === 0) {
	fwrite(STDERR, $route.' failed: '.$output."\n");
	exit(1);
}
echo $route." route passed\n";
