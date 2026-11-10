#!/usr/bin/php -q
<?php
// Replicates one dataset's snapshot to a copy in the background; zfsmStartReplicate (include/ZFSMReplicate.php) starts it. Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

if (($argv[1] ?? '') === '' || ($argv[2] ?? '') === '' || ($argv[3] ?? '') === '') {
	fwrite(STDERR, "Usage: replicate.php <dataset> <snapshot> <destination>\n");
	exit(2);
}
exit(zfsmRunReplicate($argv[1], $argv[2], $argv[3], 'zfsmJobStarted'));
