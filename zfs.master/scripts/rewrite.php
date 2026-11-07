#!/usr/bin/php -q
<?php
// Rewrites one dataset's files in the background; zfsmStartRewrite (include/ZFSMRewrite.php) starts it. Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

if (($argv[1] ?? '') === '') {
	fwrite(STDERR, "Usage: rewrite.php <dataset> [physical]\n");
	exit(2);
}
// zfsmStartRewrite reads this pipe until it closes: say "started", then let go of it so the page is answered at once.
exit(zfsmRunRewrite($argv[1], ($argv[2] ?? '') === 'physical', function() {
	fwrite(STDOUT, "started\n");
	fflush(STDOUT);
	fclose(STDIN);
	fclose(STDOUT);
	fclose(STDERR);
	// Reopened on /dev/null they take fds 0-2 again, so nothing opened later is written to as stdout or stderr.
	$GLOBALS['zfsm_stdio'] = array(fopen('/dev/null', 'r'), fopen('/dev/null', 'w'), fopen('/dev/null', 'w'));
}));
