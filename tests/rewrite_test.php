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

// -P only where the pool has physical_rewrite; a failed read means without.
foreach (array('enabled' => true, 'active' => true, 'disabled' => false, '' => false) as $feature => $physical) {
	putenv('ZFSM_TEST_PHYSICAL'.($feature === '' ? '' : '='.$feature));
	same($physical, zfsmRewritePhysical('tank'), 'physical_rewrite '.($feature === '' ? 'unknown' : $feature));
}
putenv('ZFSM_TEST_USEDBYSNAPSHOTS=1073741824');
putenv('ZFSM_TEST_PHYSICAL=enabled');
same(array('can_proceed' => true, 'physical' => true, 'snapshot_space' => 1073741824), zfsmRewritePreview('tank/data'), 'the dialog shows the snapshot space and whether incremental sends stay small');

// A run: the dataset's own files (-r -x), each counted (-v), -P where it can; progress on the row and a notification at the end.
$reset();
putenv('ZFSM_TEST_REWRITE_FILES=5');
same(0, zfsmRunRewrite('tank/data'), 'a rewrite that worked returns 0');
same(array('rewrite -r -x -v -P '.$work.'/data'), $read('rewrite.log'), 'zfs rewrite runs once, on the mountpoint, with -P');
same(array('rewrite_start', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'the row hears when it starts and stops');
same(array('normal: Rewrite of tank/data finished: 5 files rewritten'), $read('notify-detail.log'), 'the notification says how many files');
same(false, is_file(zfsmRewriteStateFile('tank/data')), 'a finished run leaves no lock behind');
// A long run tells the row every 2 seconds how many files are done.
$reset();
putenv('ZFSM_TEST_REWRITE_PAUSE=3');
zfsmRunRewrite('tank/data');
putenv('ZFSM_TEST_REWRITE_PAUSE');
same(array('rewrite_start', 'rewrite_progress 1', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'a run longer than 2 seconds reports the files done so far');
putenv('ZFSM_TEST_PHYSICAL=disabled');
$reset();
zfsmRunRewrite('tank/data');
same(array('rewrite -r -x -v '.$work.'/data'), $read('rewrite.log'), 'without physical_rewrite, no -P');

// A failure says how far it got and why, as a warning.
$reset();
putenv('ZFSM_TEST_REWRITE_FAIL=1');
same(1, zfsmRunRewrite('tank/data'), 'a failed rewrite returns 1');
putenv('ZFSM_TEST_REWRITE_FAIL');
// zfsmSafeText turns quotes into ?, as for every name a notification carries.
same(array('warning: Rewrite of tank/data failed after 5 files rewritten: failed to rewrite ?'.$work.'/data/locked?: Device or resource busy'), $read('notify-detail.log'), 'the warning names the failure');
same(array('rewrite_start', 'rewrite_stop'), $GLOBALS['zfsm_feed'], 'a failed run still clears the row');

// One run per dataset: while another holds the lock, a second neither runs nor starts.
$reset();
@mkdir($GLOBALS['zfsm_rewrite_dir'], 0700, true);
$held = fopen(zfsmRewriteStateFile('tank/data'), 'c');
flock($held, LOCK_EX);
same(true, zfsmRewriteRunning('tank/data'), 'a held lock is a running rewrite');
same(0, zfsmRunRewrite('tank/data'), 'a second run steps aside');
same(array(), $read('rewrite.log'), 'and never runs zfs rewrite');
same(array('can_proceed' => false, 'error' => 'A rewrite of this dataset is already running'), zfsmRewritePreview('tank/data'), 'the dialog says one is running');
same(array('succeeded' => array(), 'failed' => array('tank/data' => 'A rewrite of this dataset is already running')), zfsmStartRewrite('tank/data'), 'and none starts');
flock($held, LOCK_UN);
fclose($held);
same(false, zfsmRewriteRunning('tank/data'), 'a released lock is not a running rewrite');

// A start runs scripts/rewrite.php in the background and answers at once.
$reset();
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (is_string($docroot) && is_file($docroot.'/plugins/zfs.master/scripts/rewrite.php')) {
	$GLOBALS['docroot'] = $docroot;
	$GLOBALS['zfsm_php'] = PHP_BINARY;
	same(array('succeeded' => array('tank/data' => 'Rewrite started; a notification says when it is done'), 'failed' => array()), zfsmStartRewrite('tank/data'), 'a start answers at once');
	for ($wait = 0; $wait < 100 && count($read('rewrite.log')) === 0; $wait++) usleep(50000);
	same(array('rewrite -r -x -v '.$work.'/data'), $read('rewrite.log'), 'the background script ran the rewrite');
} else {
	fwrite(STDERR, "FAILED: the background start needs ZFSM_TEST_DOCROOT with the plugin, as run_http_inventory_tests.sh sets it\n");
	exit(1);
}

echo "rewrite tests passed\n";
