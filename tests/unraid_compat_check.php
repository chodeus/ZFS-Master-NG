<?php
// One request through real Unraid code. Warnings from this plugin's files fail the check; Unraid's own do not.
error_reporting(E_ALL); // PHP 7.4 hides notices by default
$check = $argv[1];
$docroot = '/usr/local/emhttp';
$plugin_dir = $docroot.'/plugins/zfs.master/';
$token = 'compat-test-token';

$GLOBALS['zfsm_problems'] = array();
set_error_handler(function($severity, $message, $file, $line) use ($plugin_dir) {
	if (!(error_reporting() & $severity)) return true;
	if (strpos($file, $plugin_dir) === 0 || strpos($file, 'eval') !== false) $GLOBALS['zfsm_problems'][] = "$message in $file:$line";
	return true;
});
register_shutdown_function(function() {
	$fatal = error_get_last();
	if ($fatal && in_array($fatal['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR), true)) {
		fwrite(STDERR, 'fatal: '.$fatal['message'].' in '.$fatal['file'].':'.$fatal['line']."\n");
		exit(1);
	}
});

$var = array('csrf_token' => $token);
$display = array('text' => 0, 'critical' => 90, 'warning' => 70, 'theme' => 'black', 'date' => '%c', 'time' => '%R');
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_SERVER['SCRIPT_NAME'] = '/plugins/zfs.master/backend/ZFSMAdmin.php';
$_SERVER['HTTP_HOST'] = 'tower';

function run_page($path, $strip_header) {
	global $docroot, $var, $display;
	$source = file_get_contents($path);
	if ($strip_header) $source = substr($source, strpos($source, "\n---\n") + 5);
	if (!function_exists('_')) { function _($text) { return $text; } }
	ob_start();
	eval('?>'.$source);
	return ob_get_clean();
}

switch ($check) {
	case 'body_token':
	case 'no_token':
	case 'wrong_token':
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('cmd' => 'inventory');
		if ($check === 'body_token') $_POST['csrf_token'] = $token;
		if ($check === 'wrong_token') $_POST['csrf_token'] = 'not-the-token';
		ob_start();
		register_shutdown_function(function() use ($check) {
			$output = ob_get_clean();
			$answer = json_decode((string)$output, true);
			$served = is_array($answer) && isset($answer['data']['datasets']['tank']['child']['tank/data']);
			if ($check === 'body_token' ? !$served : $served) {
				fwrite(STDERR, $check.' got: '.substr((string)$output, 0, 300)."\n");
				exit(1);
			}
			if ($GLOBALS['zfsm_problems']) {
				fwrite(STDERR, implode("\n", $GLOBALS['zfsm_problems'])."\n");
				exit(1);
			}
		});
		require $docroot.'/webGui/include/local_prepend.php';
		require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
		break;

	case 'main_page':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$html = run_page($docroot.'/plugins/zfs.master/ZFSMaster.page', true);
		if (strpos($html, 'zfs_master_body') === false) { fwrite(STDERR, "main page did not render\n"); exit(1); }
		break;

	case 'settings_page':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		require_once $docroot.'/webGui/include/Helpers.php';
		$html = run_page($docroot.'/plugins/zfs.master/ZFSMasterSettings.page', true);
		if (strpos($html, 'name="exclusion"') === false) { fwrite(STDERR, "settings page did not render\n"); exit(1); }
		break;

	case 'create_popup':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMCreateDataset.php';
		$html = ob_get_clean();
		if (strpos($html, 'zfsm_csrf_token') === false) { fwrite(STDERR, "create popup did not render\n"); exit(1); }
		break;

	case 'snapshot_popup':
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = array('zpool' => 'tank', 'zdataset' => 'tank/data');
		ob_start();
		require $docroot.'/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php';
		$html = ob_get_clean();
		if (strpos($html, 'tank/data@daily') === false) { fwrite(STDERR, "snapshot popup did not list the snapshot\n"); exit(1); }
		break;

	default:
		exit(2);
}

if ($GLOBALS['zfsm_problems']) {
	fwrite(STDERR, implode("\n", $GLOBALS['zfsm_problems'])."\n");
	exit(1);
}
