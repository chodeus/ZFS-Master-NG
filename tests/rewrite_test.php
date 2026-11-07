<?php
// zfs rewrite through the real runProcess and scripts/rewrite.php, with the fake zfs, zpool and notify.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

// The progress feed the row reads.
$GLOBALS['zfsm_feed'] = array();
function publish($channel, $message) {
	$sent = json_decode($message, true);
	$GLOBALS['zfsm_feed'][] = $sent['op'].(isset($sent['data']['files']) ? ' '.$sent['data']['files'] : '');
}

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-rewrite-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
mkdir($work.'/data');
putenv('ZFSM_TEST_DATASET_MOUNT='.$work.'/data');
$GLOBALS['zfsm_rewrite_dir'] = $work.'/state';
// zfsnotify runs <docroot>/plugins/dynamix/scripts/notify: point it at the fake.
mkdir($work.'/docroot/plugins/dynamix/scripts', 0755, true);
copy(__DIR__.'/fixtures/unraid/notify', $work.'/docroot/plugins/dynamix/scripts/notify');
chmod($work.'/docroot/plugins/dynamix/scripts/notify', 0755);
$GLOBALS['docroot'] = $work.'/docroot';
putenv('ZFSM_TEST_NOTIFY_LOG='.$work.'/notify.log');
putenv('ZFSM_TEST_NOTIFY_DETAIL_LOG='.$work.'/notify-detail.log');
putenv('ZFSM_TEST_REWRITE_LOG='.$work.'/rewrite.log');
$read = function($name) use ($work) { return is_file($work.'/'.$name) ? file($work.'/'.$name, FILE_IGNORE_NEW_LINES) : array(); };
$reset = function() use ($work) { foreach (array('notify.log', 'notify-detail.log', 'rewrite.log') as $name) @unlink($work.'/'.$name); $GLOBALS['zfsm_feed'] = array(); };

// Only OpenZFS 2.4+ has the command; older ones answer "unrecognized command".
same(true, zfsmRewriteAvailable(), 'OpenZFS 2.4 has zfs rewrite');
putenv('ZFSM_TEST_NO_REWRITE=1');
same(false, zfsmRewriteAvailable(), 'an older OpenZFS has no zfs rewrite');
same(array('can_proceed' => false, 'error' => 'zfs rewrite needs OpenZFS 2.4 or later'), zfsmRewritePreview('tank/data'), 'the dialog says why it can\'t run');
putenv('ZFSM_TEST_NO_REWRITE');

// Files are reached through the mountpoint: a volume, an unmounted dataset or a missing folder has none to rewrite.
same(array($work.'/data'), zfsmRewriteTarget('tank/data'), 'a mounted filesystem rewrites its mountpoint');
same('Only an existing filesystem dataset has files to rewrite', zfsmRewriteTarget('tank/vol'), 'a volume has no files');
same('Only an existing filesystem dataset has files to rewrite', zfsmRewriteTarget('../tank'), 'an invalid name is refused');
putenv('ZFSM_TEST_MOUNTED=no');
same("The dataset is not mounted, so its files can't be reached", zfsmRewriteTarget('tank/data'), 'an unmounted dataset is refused');
putenv('ZFSM_TEST_MOUNTED');
putenv('ZFSM_TEST_DATASET_MOUNT='.$work.'/gone');
same('The dataset has no mountpoint to reach its files', zfsmRewriteTarget('tank/data'), 'a mountpoint that is not there is refused');
putenv('ZFSM_TEST_DATASET_MOUNT='.$work.'/data');

// -P needs the pool's physical_rewrite feature; its state tells the dialog whether a first -P would turn it on for good.
foreach (array('enabled' => 'enabled', 'active' => 'active', 'disabled' => '', '' => '') as $feature => $state) {
	putenv('ZFSM_TEST_PHYSICAL'.($feature === '' ? '' : '='.$feature));
	same($state, zfsmRewriteFeature('tank'), 'physical_rewrite '.($feature === '' ? 'unknown' : $feature));
}
putenv('ZFSM_TEST_USEDBYSNAPSHOTS=1073741824');
putenv('ZFSM_TEST_PHYSICAL=enabled');
same(array('can_proceed' => true, 'physical_feature' => 'enabled', 'snapshot_space' => 1073741824), zfsmRewritePreview('tank/data'), 'the dialog gets the snapshot space and the feature\'s state');
putenv('ZFSM_TEST_USEDBYSNAPSHOTS=fail');
same(null, zfsmRewritePreview('tank/data')['snapshot_space'], 'a snapshot space that could not be read is not shown as 0 B');
putenv('ZFSM_TEST_USEDBYSNAPSHOTS=1073741824');

// A run: the dataset's own files (-r -x), each counted (-v), -P only when asked; progress on the row and a notification at the end.
$reset();
putenv('ZFSM_TEST_REWRITE_FILES=5');
same(0, zfsmRunRewrite('tank/data', true), 'a rewrite that worked returns 0');
same(array('rewrite -r -x -v -P '.$work.'/data'), $read('rewrite.log'), 'asked for, -P is used where the pool has the feature');
same(array('rewrite_start', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'the row hears when it starts and stops');
same(array('normal: Rewrite of tank/data finished: 5 files rewritten'), $read('notify-detail.log'), 'the notification says how many files');
same(false, zfsmRewriteRunning(zfsmRewriteLockFile('tank/data')), 'a finished run releases its lock');
same(true, is_file(zfsmRewriteLockFile('tank/data')), 'and keeps its lock file, so a later run locks the same one');
$reset();
zfsmRunRewrite('tank/data', false);
same(array('rewrite -r -x -v '.$work.'/data'), $read('rewrite.log'), 'not asked for, no -P even where the pool has the feature');

// A long run tells the row every 2 seconds how many files are done.
$reset();
putenv('ZFSM_TEST_REWRITE_PAUSE=3');
zfsmRunRewrite('tank/data', false);
putenv('ZFSM_TEST_REWRITE_PAUSE');
same(array('rewrite_start', 'rewrite_progress 1', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'a run longer than 2 seconds reports the files done so far');

// A run that can't start says why, as the start promised a notification.
$reset();
putenv('ZFSM_TEST_PHYSICAL=disabled');
same(1, zfsmRunRewrite('tank/data', true), '-P on a pool without the feature does not run');
same(array(), $read('rewrite.log'), 'and never runs zfs rewrite');
same(array("warning: Rewrite of tank/data not started: the pool?s physical_rewrite feature is not enabled"), $read('notify-detail.log'), 'and says why');
$reset();
putenv('ZFSM_TEST_MOUNTED=no');
same(1, zfsmRunRewrite('tank/data', false), 'a dataset unmounted since the start does not run');
putenv('ZFSM_TEST_MOUNTED');
same(array("warning: Rewrite of tank/data not started: The dataset is not mounted, so its files can?t be reached"), $read('notify-detail.log'), 'and says why');
$reset();
putenv('ZFSM_TEST_GUID=fail');
same(array('can_proceed' => false, 'error' => "The dataset's guid could not be read"), zfsmRewritePreview('tank/data'), 'without a guid there is no lock, so no rewrite');
same(1, zfsmRunRewrite('tank/data', false), 'nor a run');
putenv('ZFSM_TEST_GUID');

// A failure says how far it got and why, as a warning.
$reset();
putenv('ZFSM_TEST_REWRITE_FAIL=1');
same(1, zfsmRunRewrite('tank/data', false), 'a failed rewrite returns 1');
putenv('ZFSM_TEST_REWRITE_FAIL');
// zfsmSafeText turns quotes into ?, as for every name a notification carries.
same(array('warning: Rewrite of tank/data failed after 5 files rewritten: failed to rewrite ?'.$work.'/data/locked?: Device or resource busy'), $read('notify-detail.log'), 'the warning names the failure');
same(array('rewrite_start', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'a failed run still clears the row');

// A long name still runs: the lock file is named by the guid.
$reset();
$long = 'tank/'.trim(str_repeat('long name ', 24));
same(0, zfsmRunRewrite($long, false), 'a long dataset name still rewrites');
same(1, count($read('rewrite.log')), 'and runs zfs rewrite once');

// One run per dataset, whatever its name: while another holds the lock, a second neither runs nor starts.
$reset();
putenv('ZFSM_TEST_PHYSICAL=enabled');
putenv('ZFSM_TEST_GUID=4711');
$lock_file = zfsmRewriteLockFile('tank/data');
@mkdir(dirname($lock_file), 0700, true);
$held = fopen($lock_file, 'c');
flock($held, LOCK_EX);
same(true, zfsmRewriteRunning($lock_file), 'a held lock is a running rewrite');
same(0, zfsmRunRewrite('tank/data', false), 'a second run steps aside');
same(array(), $read('rewrite.log'), 'and never runs zfs rewrite');
same(array('can_proceed' => false, 'error' => 'A rewrite of this dataset is already running'), zfsmRewritePreview('tank/data'), 'the dialog says one is running');
same(array('can_proceed' => false, 'error' => 'A rewrite of this dataset is already running'), zfsmRewritePreview('tank/renamed'), 'the same dataset under a new name is the same rewrite');
same(array('succeeded' => array(), 'failed' => array('tank/data' => 'A rewrite of this dataset is already running')), zfsmStartRewrite('tank/data', false), 'and none starts');
flock($held, LOCK_UN);
fclose($held);
putenv('ZFSM_TEST_GUID');
putenv('ZFSM_TEST_PHYSICAL=disabled');
same(array('succeeded' => array(), 'failed' => array('tank/data' => "The pool's physical_rewrite feature is not enabled, so birth times can't be kept")), zfsmStartRewrite('tank/data', true), '-P can\'t be asked for where the pool lacks the feature');
putenv('ZFSM_TEST_PHYSICAL=enabled');

// A start runs scripts/rewrite.php in a session of its own and answers once it holds the lock, while the rewrite goes on; -P goes through when asked for.
$reset();
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/rewrite.php')) {
	$GLOBALS['docroot'] = $docroot;
	$GLOBALS['zfsm_php'] = PHP_BINARY;
	putenv('ZFSM_TEST_REWRITE_PAUSE=2');
	$began = microtime(true);
	same(array('succeeded' => array('tank/data' => 'Rewrite started; a notification says when it is done'), 'failed' => array()), zfsmStartRewrite('tank/data', true), 'a start says it started');
	same(true, microtime(true) - $began < 1.5, 'and answers while the rewrite is still running');
	// The script has no zfsm_rewrite_dir, so it locks in the default folder (this runs in a disposable container).
	$test_dir = $GLOBALS['zfsm_rewrite_dir'];
	unset($GLOBALS['zfsm_rewrite_dir']);
	$background_lock = zfsmRewriteLockFile('tank/data');
	$GLOBALS['zfsm_rewrite_dir'] = $test_dir;
	same(true, zfsmRewriteRunning($background_lock), 'which holds the dataset\'s lock by then');
	for ($wait = 0; $wait < 100 && zfsmRewriteRunning($background_lock); $wait++) usleep(50000);
	putenv('ZFSM_TEST_REWRITE_PAUSE');
	same(false, zfsmRewriteRunning($background_lock), 'and finishes, letting go of it');
	same(array('rewrite -r -x -v -P '.$work.'/data'), $read('rewrite.log'), 'the background script ran the rewrite, with -P as asked');
	// A start that never got going says so, with what it printed, instead of promising a notification.
	$reset();
	$GLOBALS['zfsm_php'] = $work.'/no-such-php';
	$failed = zfsmStartRewrite('tank/data', false)['failed']['tank/data'] ?? '';
	same(true, strpos($failed, 'The rewrite could not be started: ') === 0 && strpos($failed, 'no-such-php') !== false, 'a PHP that is not there fails the start: '.$failed);
	$GLOBALS['zfsm_php'] = PHP_BINARY;
	putenv('ZFSM_TEST_DOCROOT='.$work.'/missing');
	$failed = zfsmStartRewrite('tank/data', false)['failed']['tank/data'] ?? '';
	putenv('ZFSM_TEST_DOCROOT='.$docroot);
	same(true, strpos($failed, 'The rewrite could not be started: ') === 0 && strpos($failed, 'Helpers.php') !== false, 'a script that dies as it loads fails the start, saying why: '.$failed);
	same(array(), $read('rewrite.log'), 'and neither ran zfs rewrite');
} else {
	fwrite(STDERR, "FAILED: the background start needs ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}

echo "rewrite tests passed\n";
