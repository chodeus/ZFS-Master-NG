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
	'encrypt_other_value' => array(array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'secure', 'mount' => 'yes', 'encryption' => 'on', 'passphrase' => '')), '0', '', 'refused'),
	'encrypt_inherit_passphrase' => array(array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'secure', 'mount' => 'yes', 'encryption' => 'yes', 'passphrase' => 'inherit')), '0', '', 'refused'),
	'encrypt_no_passphrase' => array(array('cmd' => 'createdataset', 'data' => array('zpool' => 'tank', 'name' => 'secure', 'mount' => 'yes', 'encryption' => 'yes', 'passphrase' => '')), '0', '', 'refused'),
	'snapshot_route_dataset' => array(array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data', 'recursive' => '0'), '0', '', 'not_snapshot'),
	'destroy_unreviewed' => array(array('cmd' => 'destroysnapshot', 'snapshot' => 'tank/data@daily', 'recursive' => '0', 'token' => 'stale'), '0', '', 'unreviewed'),
	'preview_destroy' => array(array('cmd' => 'previewdestroy', 'target' => 'tank/data', 'force' => '0'), '0', '', 'previewed'),
	'preview_rollback' => array(array('cmd' => 'previewrollback', 'snapshot' => 'tank/data@daily'), '0', '', 'previewed'),
	'diff_snapshot' => array(array('cmd' => 'diffsnapshot', 'snapshot' => 'tank/data@daily'), '0', '', 'diffed'),
);

// ZFSMAdmin.php sets its own $name, so the case name needs another variable.
$case = $argv[1] ?? '';
if (!isset($cases[$case])) exit(2);
list($post, $destructive, $exclusion, $expected) = $cases[$case];
putenv('ZFSM_TEST_DESTRUCTIVE='.$destructive);
putenv('ZFSM_TEST_EXCLUSION='.$exclusion);
putenv('ZFSM_TEST_LAZY=0');
putenv('ZFSM_TEST_MISSING_DATASET=tank/secure');
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_POST = $post;

require_once $docroot.'/plugins/zfs.master/backend/ZFSMOperations.php';
if (($_POST['cmd'] ?? '') === 'rollbacksnapshot') $_POST['token'] = zfsmRollbackPreview($_POST['snapshot'])['token'];
if (($_POST['cmd'] ?? '') === 'destroysnapshot' && !isset($_POST['token'])) $_POST['token'] = zfsmDestroyPreview($_POST['snapshot'], $_POST['recursive'])['token'];

ob_start();
require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
$answer = json_decode(ob_get_clean(), true);

$passed = false;
if ($expected === 'blocked') $passed = empty($answer['succeeded']) && strpos(implode(' ', $answer['failed'] ?? array()), 'Destructive Mode') !== false;
if ($expected === 'refused') $passed = empty($answer['succeeded']) && preg_match('/passphrase|Yes or No/', implode(' ', $answer['failed'] ?? array())) === 1;
if ($expected === 'previewed') $passed = !empty($answer['can_proceed']) && !empty($answer['token']);
if ($expected === 'diffed') $passed = count($answer['changes'] ?? array()) === 5 && ($answer['truncated'] ?? null) === false && ($answer['changes'][1]['path'] ?? '') === '/mnt/tank/data/café';
if ($expected === 'not_snapshot') $passed = empty($answer['succeeded']) && strpos(implode(' ', $answer['failed'] ?? array()), 'Snapshot does not exist') !== false;
if ($expected === 'unreviewed') $passed = empty($answer['succeeded']) && strpos(implode(' ', $answer['failed'] ?? array()), 'please review') !== false;
if ($expected === 'allowed') $passed = !empty($answer['succeeded']) && empty($answer['failed']);
if ($expected === 'warned') $passed = !empty($answer['data']['warning']) && isset($answer['data']['datasets']['tank']['child']['tank/data']);
if ($expected === 'filtered') $passed = !isset($answer['data']['datasets']['tank']['child']['tank/data']) && isset($answer['data']['datasets']['tank']['child']['tank/vol']);
if (!$passed) {
	fwrite(STDERR, $case.' failed: '.json_encode($answer)."\n");
	exit(1);
}
echo $case." gate passed\n";
