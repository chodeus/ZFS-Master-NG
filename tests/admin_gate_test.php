<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

// name => [POST, Destructive Mode, exclusion pattern, expected]
$cases = array(
	'rollback_newer' => array(array('cmd' => 'rollbacksnapshot', 'snapshot' => 'tank/data@daily', 'destroy_newer' => '1'), '0', '', 'blocked'),
	'destroy_recursive' => array(array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data@daily', 'recursive' => '1'), '0', '', 'blocked'),
	'destroy_dataset' => array(array('cmd' => 'destroydataset', 'zdataset' => 'tank/data', 'force' => '0'), '0', '', 'blocked'),
	'rollback_plain' => array(array('cmd' => 'rollbacksnapshot', 'snapshot' => 'tank/data@daily', 'destroy_newer' => '0'), '0', '', 'allowed'),
	'destroy_plain' => array(array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data@daily', 'recursive' => '0'), '0', '', 'allowed'),
	'newer_with_mode' => array(array('cmd' => 'rollbacksnapshot', 'snapshot' => 'tank/data@daily', 'destroy_newer' => '1'), '1', '', 'allowed'),
	'invalid_exclusion' => array(array('cmd' => 'inventory'), '1', 'tank/(', 'warned'),
	'lua_exclusion' => array(array('cmd' => 'inventory'), '1', 'tank/d%a+$', 'filtered'),
	'encrypt_no_passphrase' => array(array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'secure', 'mount' => 'yes', 'encryption' => 'yes', 'passphrase' => '')), '0', '', 'refused'),
);

$name = $argv[1] ?? '';
if (!isset($cases[$name])) exit(2);
list($post, $destructive, $exclusion, $expected) = $cases[$name];
putenv('ZFSM_TEST_DESTRUCTIVE='.$destructive);
putenv('ZFSM_TEST_EXCLUSION='.$exclusion);
putenv('ZFSM_TEST_LAZY=0');
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_POST = $post;

ob_start();
require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
$answer = json_decode(ob_get_clean(), true);

$passed = false;
if ($expected === 'blocked') $passed = empty($answer['succeeded']) && strpos(implode(' ', $answer['failed'] ?? array()), 'Destructive Mode') !== false;
if ($expected === 'refused') $passed = empty($answer['succeeded']) && strpos(implode(' ', $answer['failed'] ?? array()), 'passphrase') !== false;
if ($expected === 'allowed') $passed = !empty($answer['succeeded']) && empty($answer['failed']);
if ($expected === 'warned') $passed = !empty($answer['data']['warning']) && isset($answer['data']['datasets']['tank']['child']['tank/data']);
if ($expected === 'filtered') $passed = !isset($answer['data']['datasets']['tank']['child']['tank/data']) && isset($answer['data']['datasets']['tank']['child']['tank/vol']);
if (!$passed) {
	fwrite(STDERR, $name.' failed: '.json_encode($answer)."\n");
	exit(1);
}
echo $name." gate passed\n";
