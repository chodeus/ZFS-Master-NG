#!/usr/bin/php -q
<?php
// Rewrites one dataset's files in the background; zfsmStartRewrite (include/ZFSMRewrite.php) starts it. Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.
$zfsm_install_hold = @fopen('/var/run/zfs.master.install.lock', 'ce');
$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);

if (($argv[1] ?? '') === '') {
	fwrite(STDERR, "Usage: rewrite.php <dataset> [physical]\n");
	exit(2);
}
exit(zfsmRunRewrite($argv[1], ($argv[2] ?? '') === 'physical', 'zfsmJobStarted'));
