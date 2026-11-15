#!/usr/bin/php -q
<?php
// Cron runs "run <id>" from schedules.cron (include/ZFSMSchedule.php writes it). Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

if (($argv[1] ?? '') !== 'run' || !zfsmScheduleId($argv[2] ?? null)) {
	fwrite(STDERR, "Usage: schedule.php run <schedule id>\n");
	exit(2);
}
exit(zfsmScheduleRun($argv[2], loadConfig(parse_plugin_cfg('zfs.master', true)), time()));
