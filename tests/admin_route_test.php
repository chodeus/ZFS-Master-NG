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
	'previewreplicateopen' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => '', 'opening' => '1'),
	'replicatedataset' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'nowhere/data'),
	'previewcryptopen' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => '', 'opening' => '1'),
	'previewdecrypt' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'decrypt' => '1'),
	'replicatedecrypt' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'decrypt' => '1'),
	'scheduledecrypt' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'decrypt' => '1'),
	'previewsendwrong' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'decrypt' => 'yes'),
	'replicatesendwrong' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'decrypt' => ' 1'),
	'schedulesendwrong' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'decrypt' => '2'),
	'scheduleremake' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'remake' => '1'),
	'previewreplace' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'replace' => '1'),
	'previewremakewrong' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'remake' => 'on'),
	'previewreplacewrong' => array('cmd' => 'previewreplicate', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'replace' => 'yes'),
	'replicatereplacewrong' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'replace' => 'yes'),
	'schedulereplace' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'replace' => '1'),
	'scheduletickswrong' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'decrypt' => 'yes', 'remake' => 'on'),
	'schedulepicked' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'every' => 'days', 'time' => '03:00', 'days' => '0,1,2,3,4,5,6'),
	'schedulenowhere' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'nowhere/data', 'every' => 'days', 'time' => '13:00', 'days' => '2'),
	'schedulekeep' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'nowhere/data', 'every' => 'days', 'time' => '03:00', 'days' => '0,1,2,3,4,5,6', 'keep' => 'x5'),
	'scheduleremove' => array('cmd' => 'scheduleremove', 'id' => '../x'),
	'scheduleremovesaved' => array('cmd' => 'scheduleremove', 'id' => '0a1b2c3d'),
	'schedulecopykeeps' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'backup/data', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'copy_keeps' => 'x5'),
	'schedulekeepchange' => array('cmd' => 'schedulekeepchange', 'id' => '0a1b2c3d', 'keep' => ' 7D ', 'copy_keeps' => ' ALL '),
	'schedulekeepchangewrong' => array('cmd' => 'schedulekeepchange', 'id' => '0a1b2c3d', 'keep' => '', 'copy_keeps' => 'x5'),
	'schedulesnapshots' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '3'),
	'schedulesnapshotstiers' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => ' 24 7D, 4w '),
	'schedulesnapshotswrong' => array('cmd' => 'schedulesnapshots', 'every' => 'days', 'time' => '', 'days' => '9', 'keep' => 'x5'),
	'schedulesnapshotsown' => array('cmd' => 'schedulesnapshots', 'zdataset' => 'tank/data', 'every' => 'days', 'time' => '04:00', 'days' => '1', 'keep' => ''),
	'schedulecopies' => array('cmd' => 'schedulecopies', 'under' => 'backup', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '3', 'copy_keeps' => ' 30D '),
	'schedulesnapshotsleaveout' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'leave_out' => ' data , x* , data'),
	'schedulesnapshotsleaveoutshape' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'leave_out' => array('data')),
	'schedulecopiesleaveoutwrong' => array('cmd' => 'schedulecopies', 'under' => 'backup', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'leave_out' => 'tank/data'),
	'schedulecopiesnowhere' => array('cmd' => 'schedulecopies', 'under' => 'nowhere', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => ''),
	'schedulecopiesserver' => array('cmd' => 'schedulecopies', 'under' => 'copies', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '', 'server' => 'gone'),
	'scheduledataset' => array('cmd' => 'scheduledataset', 'zdataset' => 'tank/data', 'every' => 'days', 'time' => '04:00', 'days' => '1', 'keep' => '',
		'copy_every' => 'hourly', 'copy_time' => '', 'copy_days' => '', 'copy_keep' => ' 24 7D ', 'copy_keeps' => 'all', 'under' => 'backup'),
	'scheduledatasetwrong' => array('cmd' => 'scheduledataset', 'zdataset' => 'tank/data', 'every' => 'days', 'time' => '', 'days' => '', 'keep' => '',
		'copy_every' => 'hourly', 'copy_time' => '', 'copy_days' => '', 'copy_keep' => 'x5', 'under' => 'backup'),
	'scheduledatasetserver' => array('cmd' => 'scheduledataset', 'zdataset' => 'tank/data', 'every' => 'default', 'time' => '', 'days' => '', 'keep' => '',
		'copy_every' => 'hourly', 'copy_time' => '', 'copy_days' => '', 'copy_keep' => '', 'under' => 'copies', 'server' => 'gone'),
	'previewschedules' => array('cmd' => 'previewschedules', 'zdataset' => 'tank/data'),
	'previewschedulesbad' => array('cmd' => 'previewschedules', 'zdataset' => 'tank/bad name!'),
	'replicaremove' => array('cmd' => 'replicaremove', 'name' => 'old', 'server' => 'old'),
);
$replication = array('previewreplicate', 'previewreplicateopen', 'replicatedataset', 'previewcryptopen', 'previewdecrypt', 'replicatedecrypt', 'scheduledecrypt', 'previewsendwrong', 'replicatesendwrong', 'schedulesendwrong', 'scheduleremake', 'previewreplace', 'previewremakewrong', 'previewreplacewrong', 'replicatereplacewrong', 'schedulereplace', 'scheduletickswrong', 'schedulepicked', 'schedulenowhere', 'schedulekeep', 'schedulecopykeeps', 'schedulekeepchange', 'schedulekeepchangewrong', 'schedulesnapshots', 'schedulesnapshotstiers', 'schedulesnapshotsleaveout', 'schedulesnapshotsleaveoutshape', 'schedulecopiesleaveoutwrong', 'schedulesnapshotswrong', 'schedulesnapshotsown', 'schedulecopies', 'schedulecopiesnowhere', 'schedulecopiesserver', 'scheduledataset', 'scheduledatasetwrong', 'scheduledatasetserver', 'previewschedules', 'previewschedulesbad', 'replicaremove');

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

// The replication routes run on the fake's small pool (tests/replicate_test.php and tests/schedule_test.php cover the runs themselves).
if (in_array($route, $replication, true)) {
	$pool = sys_get_temp_dir().'/zfsm-route-repl-'.getmypid();
	@mkdir($pool);
	// For Replace, a read-only copy that went its own way.
	$replaced = $route === 'previewreplace';
	file_put_contents($pool.'/datasets', "tank/data\tfilesystem\nbackup\tfilesystem\n".($replaced ? "backup/data\tfilesystem\n" : ''));
	file_put_contents($pool.'/marks', "tank/data@a\t11\t10\ntank/data@b\t12\t20\n".($replaced ? "backup/data@x\t99\t30\n" : ''));
	// Encrypted for Send's Decrypted; its key not loaded where only Decrypted is then refused, which shows the choice got there.
	$crypt = array('previewcryptopen' => "tank/data\tencryption\taes-256-gcm\n", 'previewdecrypt' => "tank/data\tencryption\taes-256-gcm\n", 'replicatedecrypt' => "tank/data\tencryption\taes-256-gcm\ntank/data\tkeystatus\tunavailable\n");
	file_put_contents($pool.'/props', $crypt[$route] ?? ($route === 'scheduledecrypt' ? $crypt['replicatedecrypt'] : ($replaced ? "backup/data\treadonly\ton\n" : '')));
	putenv('ZFSM_TEST_REPL_DIR='.$pool);
	// scheduleremake's and schedulereplace's first run starts in the background, on this PHP.
	if ($route === 'scheduleremake' || $route === 'schedulereplace') $GLOBALS['zfsm_php'] = PHP_BINARY;
}

// A schedule to remove, or a snapshot schedule saved (the default or a dataset's own), on the tmpfs the runner mounts on /boot/config. No update_cron here, so
// Unraid's crontab is not rebuilt.
if (in_array($route, array('scheduleremake', 'schedulereplace', 'scheduleremovesaved', 'schedulekeepchange', 'schedulekeepchangewrong', 'schedulesnapshots', 'schedulesnapshotstiers', 'schedulesnapshotsleaveout', 'schedulesnapshotsleaveoutshape', 'schedulesnapshotsown', 'schedulecopies', 'scheduledataset', 'scheduledatasetserver', 'replicaremove'), true)) {
	zfsmTestScratch('/boot/config');
	putenv('ZFSM_TEST_JOB_DIR='.sys_get_temp_dir().'/zfsm-route-jobs-'.getmypid());
	@mkdir('/boot/config/plugins/zfs.master', 0700, true);
	if (in_array($route, array('scheduleremovesaved', 'schedulekeepchange', 'schedulekeepchangewrong'), true)) file_put_contents('/boot/config/plugins/zfs.master/schedules.json', json_encode(array(array('id' => '0a1b2c3d', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'daily', 'hour' => 3, 'day' => 0))));
	register_shutdown_function(function() { @unlink('/boot/config/plugins/zfs.master/schedules.json'); });
}
if ($route === 'replicaremove') {
	@mkdir('/boot/config/plugins/zfs.master/ssh', 0700);
	$key = 'ssh-ed25519 '.base64_encode("\0\0\0\x0bssh-ed25519\0\0\0\x20".str_repeat("\x01", 32));
	file_put_contents('/boot/config/plugins/zfs.master/ssh/remotes.json', json_encode(array_map(function($name) use ($key) { return array('name' => $name, 'host' => '10.0.20.11', 'port' => 22, 'key' => $key); }, array('nas', 'old'))));
	register_shutdown_function(function() { @unlink('/boot/config/plugins/zfs.master/ssh/remotes.json'); });
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
// scheduleremake's and schedulereplace's first run, in the background, lets go of its lock before the pool is read or removed.
for ($waited = 0; in_array($route, array('scheduleremake', 'schedulereplace'), true) && $waited < 100 && zfsmJobRunning(zfsmJobLockFile('tank/data', 'replicate')); $waited++) usleep(50000);
// Replication: the preview plans a first, full copy; a start or schedule whose copy has nowhere to go says why and starts nothing,
// and a schedule names no snapshot of its own, as each run takes one. A remove of something that is no schedule's id says so.
// Snapshot schedules say what they saved, and the list; one that can't be saved says why and saves nothing. The dialog's start, or why not.
if (in_array($route, $replication, true) || strpos($route, 'scheduleremove') === 0) {
	$failed = $answer['failed']['tank/data'] ?? '';
	$ok = array(
		'previewreplicate' => ($answer['snapshots'] ?? null) === array('b', 'a') && ($answer['can_proceed'] ?? null) === true && ($answer['mode'] ?? '') === 'full'
			&& !array_key_exists('warning', $answer) && !array_key_exists('encrypted', $answer) && ($answer['how'] ?? null) === '',
		'previewdecrypt' => ($answer['can_proceed'] ?? null) === true && ($answer['raw'] ?? null) === false && ($answer['how'] ?? '') === 'choice',
		'replicatedecrypt' => $failed === 'The key of tank/data is not loaded, so only Raw can send it: unlock it first',
		'scheduledecrypt' => $failed === 'Nothing was scheduled: The key of tank/data is not loaded, so only Raw can send it: unlock it first' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'previewsendwrong' => $answer === array('can_proceed' => false, 'error' => 'Send has no such choice now; reload the page'),
		'replicatesendwrong' => $failed === 'Send has no such choice now; reload the page' && ($answer['succeeded'] ?? null) === array(),
		'schedulesendwrong' => $failed === 'Nothing was scheduled: Send has no such choice now; reload the page' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'scheduleremake' => isset($answer['succeeded']['tank/data']) && array_column(zfsmScheduleRead()['valid'] ?? array(), 'remake') === array(true),
		'previewreplace' => ($answer['can_proceed'] ?? null) === true && preg_match('/^backup\/data_replaced_[0-9]{8}_[0-9]{4}$/D', $answer['replace'] ?? '') === 1,
		'previewremakewrong' => $answer === array('can_proceed' => false, 'error' => 'If the copy is gone, make it again has no such choice now; reload the page'),
		'previewreplacewrong' => $answer === array('can_proceed' => false, 'error' => 'Replace a copy that no longer matches has no such choice now; reload the page'),
		'replicatereplacewrong' => $failed === 'Replace a copy that no longer matches has no such choice now; reload the page' && ($answer['succeeded'] ?? null) === array(),
		'schedulereplace' => isset($answer['succeeded']['tank/data']) && array_column(zfsmScheduleRead()['valid'] ?? array(), 'replace') === array(true),
		'scheduletickswrong' => $failed === 'Nothing was scheduled: Send has no such choice now; reload the page; If the copy is gone, make it again has no such choice now; reload the page'
			&& !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'previewreplicateopen' => ($answer['snapshots'] ?? null) === array('b', 'a') && array_key_exists('warning', $answer) && $answer['warning'] === null
			&& ($answer['encrypted'] ?? null) === false,
		'previewcryptopen' => ($answer['encrypted'] ?? null) === true && ($answer['can_proceed'] ?? null) === false,
		'replicatedataset' => $failed === 'The copy goes inside nowhere, which doesn\'t exist',
		'schedulepicked' => $failed === 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now',
		'schedulenowhere' => $failed === 'Nothing was scheduled: The copy goes inside nowhere, which doesn\'t exist' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulekeep' => $failed === 'Nothing was scheduled: under Snapshots to keep, give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep all' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'scheduleremove' => $answer === array('error' => 'No such schedule'),
		'schedulecopykeeps' => $failed === 'Nothing was scheduled: under Copy Keeps, give all to keep every snapshot, or give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep what its source keeps' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulekeepchange' => strpos($answer['error'] ?? '', 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (') === 0
			&& strpos($answer['schedules_html'] ?? '', 'keeps 7 daily of its snapshots; its copy keeps all its snapshots') !== false
			&& array_map(null, ...array_map(function($key) { return array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), $key); }, array('id', 'keep', 'copy_keeps'))) === array(array('0a1b2c3d', '7d', 0)),
		'schedulekeepchangewrong' => $answer === array('error' => 'Nothing was saved: under Copy Keeps, give all to keep every snapshot, or give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep what its source keeps')
			&& array_keys(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true)[0]) === array('id', 'dataset', 'dest', 'server', 'every', 'hour', 'day'),
		'scheduleremovesaved' => strpos($answer['error'] ?? '', 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (') === 0 && ($answer['schedules_html'] ?? null) === 'None yet'
			&& file_get_contents('/boot/config/plugins/zfs.master/schedules.json') === "[]\n",
		'schedulesnapshots' => preg_match('/^Saved: a snapshot of each of 2 dataset\(s\) every hour at :[0-5][0-9]; it keeps the newest 3 scheduled snapshots of each dataset$/D', $answer['said'] ?? '') === 1
			&& strpos($answer['error'] ?? '', 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (') === 0 && strpos($answer['schedules_html'] ?? '', '>Snapshot Every Dataset, every hour at :') !== false
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'dest') === array(''),
		'schedulesnapshotstiers' => preg_match('/^Saved: a snapshot of each of 2 dataset\(s\) every hour at :[0-5][0-9]; it keeps the newest 24, 7 daily and 4 weekly scheduled snapshots of each dataset$/D', $answer['said'] ?? '') === 1
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'keep') === array('24 7d 4w'),
		'schedulesnapshotswrong' => $answer === array('error' => 'Nothing was saved: set the time it starts at; tick at least one day; under Snapshots to keep, give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep all')
			&& !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulesnapshotsown' => ($answer['said'] ?? '') === 'Saved: a snapshot of each of 1 dataset(s) (tank/data and below) every Monday at 04:00; it keeps all it takes'
			&& strpos($answer['schedules_html'] ?? '', '>Snapshots of tank/data and below, every Monday at 04:00, keeps all it takes <input type="button" value="Remove"') !== false
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'dataset') === array('tank/data'),
		'schedulecopies' => preg_match('/^Saved: a copy of each of 1 dataset\(s\) under backup every hour at :[0-5][0-9]; it keeps the newest 3 of the snapshots of each dataset it copies; each copy keeps 30 daily$/D', $answer['said'] ?? '') === 1
			&& strpos($answer['schedules_html'] ?? '', '>Replicate Every Dataset under backup, every hour at :') !== false
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'dest') === array('backup/')
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'copy_keeps') === array('30d'),
		'schedulesnapshotsleaveout' => preg_match('/^Saved: a snapshot of each of 1 dataset\(s\) every hour at :[0-5][0-9]; it keeps all it takes\. Leave Out leaves out 1 dataset\(s\); nothing matches x\*$/D', $answer['said'] ?? '') === 1
			&& strpos($answer['schedules_html'] ?? '', ', keeps all it takes; leaves out data, x*</span>') !== false
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'leave_out') === array(array('data', 'x*')),
		'schedulesnapshotsleaveoutshape' => preg_match('/^Saved: a snapshot of each of 2 dataset\(s\) every hour at :[0-5][0-9]; it keeps all it takes$/D', $answer['said'] ?? '') === 1
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'leave_out') === array(),
		'schedulecopiesleaveoutwrong' => $answer === array('error' => 'Nothing was saved: under Leave Out, give a name without its pool, such as appdata, not tank/data')
			&& !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulecopiesnowhere' => $answer === array('error' => 'Nothing was saved: nowhere does not exist on this server') && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulecopiesserver' => $answer === array('error' => 'Nothing was saved: No server here is named gone') && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'scheduledataset' => preg_match('/^Saved: a snapshot of each of 1 dataset\(s\) \(tank\/data and below\) every Monday at 04:00; it keeps all it takes\. Saved: a copy of each of 1 dataset\(s\) \(tank\/data and below\) under backup every hour at :[0-5][0-9]; it keeps the newest 24 and 7 daily of the snapshots of each dataset it copies; each copy keeps all its snapshots$/D', $answer['said'] ?? '') === 1
			&& strpos($answer['schedules_html'] ?? '', '>Copies of tank/data and below under backup, every hour at :') !== false
			&& array_map(null, ...array_map(function($key) { return array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), $key); }, array('dest', 'keep'))) === array(array('', 0), array('backup/', '24 7d'))
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'copy_keeps') === array(0),
		'scheduledatasetwrong' => $answer === array('error' => 'Nothing was saved: Snapshots: set the time it starts at; tick at least one day. Replication: under Snapshots to keep, give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep all')
			&& !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'scheduledatasetserver' => $answer === array('error' => 'Nothing was saved: Replication: No server here is named gone') && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'previewschedules' => $answer === array('every' => 'default', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'inherited' => 'Snapshot Every Dataset, which is off: no scheduled snapshots', 'warning' => null, 'skipped' => '',
			'copies' => array('every' => 'default', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'copy_keeps' => '', 'under' => '', 'server' => '', 'servers' => array(), 'inherited' => 'Replicate Every Dataset, which is off: no scheduled copies', 'skipped' => '')),
		'previewschedulesbad' => ($answer['failed'] ?? null) === array('request' => 'Invalid dataset name'),
		// A server removed while Server has it chosen stays there, chosen: Save says why not.
		'replicaremove' => array_keys($answer) === array('servers_html', 'server_options') && $answer['server_options'] === '<option value="nas">nas</option><option value="old" selected>old</option>'
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/ssh/remotes.json'), true), 'name') === array('nas'),
	)[$route];
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
