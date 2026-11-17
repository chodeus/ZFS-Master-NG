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
	'schedulepicked' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => 'b', 'destination' => 'backup/data', 'every' => 'days', 'time' => '03:00', 'days' => '0,1,2,3,4,5,6'),
	'schedulenowhere' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'nowhere/data', 'every' => 'days', 'time' => '13:00', 'days' => '2'),
	'schedulekeep' => array('cmd' => 'replicatedataset', 'zdataset' => 'tank/data', 'snapshot' => '', 'destination' => 'nowhere/data', 'every' => 'days', 'time' => '03:00', 'days' => '0,1,2,3,4,5,6', 'keep' => 'x5'),
	'scheduleremove' => array('cmd' => 'scheduleremove', 'id' => '../x'),
	'scheduleremovesaved' => array('cmd' => 'scheduleremove', 'id' => '0a1b2c3d'),
	'schedulesnapshots' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '3'),
	'schedulesnapshotstiers' => array('cmd' => 'schedulesnapshots', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => ' 24 7D, 4w '),
	'schedulesnapshotswrong' => array('cmd' => 'schedulesnapshots', 'every' => 'days', 'time' => '', 'days' => '9', 'keep' => 'x5'),
	'schedulesnapshotsown' => array('cmd' => 'schedulesnapshots', 'zdataset' => 'tank/data', 'every' => 'days', 'time' => '04:00', 'days' => '1', 'keep' => ''),
	'schedulecopies' => array('cmd' => 'schedulecopies', 'under' => 'backup', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => '3'),
	'schedulecopiesnowhere' => array('cmd' => 'schedulecopies', 'under' => 'nowhere', 'every' => 'hourly', 'time' => '', 'days' => '', 'keep' => ''),
	'previewschedules' => array('cmd' => 'previewschedules', 'zdataset' => 'tank/data'),
	'previewschedulesbad' => array('cmd' => 'previewschedules', 'zdataset' => 'tank/bad name!'),
);
$replication = array('previewreplicate', 'replicatedataset', 'schedulepicked', 'schedulenowhere', 'schedulekeep', 'schedulesnapshots', 'schedulesnapshotstiers', 'schedulesnapshotswrong', 'schedulesnapshotsown', 'schedulecopies', 'schedulecopiesnowhere', 'previewschedules', 'previewschedulesbad');

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
	file_put_contents($pool.'/datasets', "tank/data\tfilesystem\nbackup\tfilesystem\n");
	file_put_contents($pool.'/marks', "tank/data@a\t11\t10\ntank/data@b\t12\t20\n");
	file_put_contents($pool.'/props', '');
	putenv('ZFSM_TEST_REPL_DIR='.$pool);
}

// A schedule to remove, or a snapshot schedule saved (the default or a dataset's own), on the tmpfs the runner mounts on /boot/config. No update_cron here, so
// Unraid's crontab is not rebuilt.
if ($route === 'scheduleremovesaved' || $route === 'schedulesnapshots' || $route === 'schedulesnapshotstiers' || $route === 'schedulesnapshotsown' || $route === 'schedulecopies') {
	zfsmTestScratch('/boot/config');
	putenv('ZFSM_TEST_JOB_DIR='.sys_get_temp_dir().'/zfsm-route-jobs-'.getmypid());
	@mkdir('/boot/config/plugins/zfs.master', 0700, true);
	if ($route === 'scheduleremovesaved') file_put_contents('/boot/config/plugins/zfs.master/schedules.json', json_encode(array(array('id' => '0a1b2c3d', 'dataset' => 'tank/data', 'dest' => 'backup/data', 'server' => '', 'every' => 'daily', 'hour' => 3, 'day' => 0))));
	register_shutdown_function(function() { @unlink('/boot/config/plugins/zfs.master/schedules.json'); });
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
// Replication: the preview plans a first, full copy; a start or schedule whose copy has nowhere to go says why and starts nothing,
// and a schedule names no snapshot of its own, as each run takes one. A remove of something that is no schedule's id says so.
// Snapshot schedules say what they saved, and the list; one that can't be saved says why and saves nothing. The dialog's start, or why not.
if (in_array($route, $replication, true) || strpos($route, 'scheduleremove') === 0) {
	$failed = $answer['failed']['tank/data'] ?? '';
	$ok = array(
		'previewreplicate' => ($answer['snapshots'] ?? null) === array('b', 'a') && ($answer['can_proceed'] ?? null) === true && ($answer['mode'] ?? '') === 'full',
		'replicatedataset' => $failed === 'The copy goes inside nowhere, which doesn\'t exist',
		'schedulepicked' => $failed === 'Nothing was scheduled: a schedule takes a new snapshot each run, so pick Take one now',
		'schedulenowhere' => $failed === 'Nothing was scheduled: The copy goes inside nowhere, which doesn\'t exist' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'schedulekeep' => $failed === 'Nothing was scheduled: under Snapshots to keep, give a number up to 9999, periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to keep all' && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'scheduleremove' => $answer === array('error' => 'No such schedule'),
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
		'schedulecopies' => preg_match('/^Saved: a copy of each of 1 dataset\(s\) under backup every hour at :[0-5][0-9]; it keeps the newest 3 of the snapshots of each dataset it copies$/D', $answer['said'] ?? '') === 1
			&& strpos($answer['schedules_html'] ?? '', '>Replicate Every Dataset under backup, every hour at :') !== false
			&& array_column(json_decode(file_get_contents('/boot/config/plugins/zfs.master/schedules.json'), true), 'dest') === array('backup/'),
		'schedulecopiesnowhere' => $answer === array('error' => 'Nothing was saved: nowhere does not exist on this server') && !is_file('/boot/config/plugins/zfs.master/schedules.json'),
		'previewschedules' => $answer === array('every' => 'default', 'time' => '00:00', 'days' => range(0, 6), 'keep' => '', 'inherited' => 'Snapshot Every Dataset, which is off: no scheduled snapshots', 'skipped' => ''),
		'previewschedulesbad' => ($answer['failed'] ?? null) === array('request' => 'Invalid dataset name'),
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
