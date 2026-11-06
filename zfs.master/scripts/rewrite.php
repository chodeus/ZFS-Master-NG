#!/usr/bin/php -q
<?php
// Rewrites one dataset's files in the background; zfsmStartRewrite (include/ZFSMRewrite.php) starts it. Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

if (($argv[1] ?? '') === '') {
	fwrite(STDERR, "Usage: rewrite.php <dataset>\n");
	exit(2);
}
exit(zfsmRunRewrite($argv[1]));
