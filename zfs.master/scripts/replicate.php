#!/usr/bin/php -q
<?php
// Replicates one dataset's snapshot to a copy in the background; zfsmStartReplicate (include/ZFSMReplicate.php) starts it. Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.
$zfsm_install_hold = @fopen('/var/run/zfs.master.install.lock', 'ce');
$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);

// "new": take the snapshot first (zfsmStartReplicate names it). A trusted server's name sends the copy there; keep is a schedule's
// first run's, empty for none; "decrypted" is Send's Decrypted, "replace" Replace a copy that no longer matches.
$keep = $argv[6] ?? '';
if (($argv[1] ?? '') === '' || ($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($keep !== '' && !(ctype_digit($keep) && zfsmKeepValid((int)$keep))) || !in_array($argv[7] ?? '', array('', 'decrypted'), true) || !in_array($argv[8] ?? '', array('', 'replace'), true)) {
	fwrite(STDERR, "Usage: replicate.php <dataset> <snapshot> <destination> [new|existing [server [keep [decrypted [replace]]]]]\n");
	exit(2);
}
exit(zfsmRunReplicate($argv[1], $argv[2], $argv[3], 'zfsmJobStarted', ($argv[4] ?? '') === 'new', $argv[5] ?? '', null, $keep !== '' ? (int)$keep : null, array('decrypt' => ($argv[7] ?? '') === 'decrypted', 'replace' => ($argv[8] ?? '') === 'replace')));
