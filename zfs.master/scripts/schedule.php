#!/usr/bin/php -q
<?php
// Cron runs "run <id>" from schedules.cron (include/ZFSMSchedule.php writes it), and zfs.master.plg "heal". Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

if (($argv[1] ?? '') === 'heal' && !isset($argv[2])) {
	$healed = zfsmScheduleHeal();
	if ($healed === null) exit(0);
	$said = $healed['why'] === '' ? 'Schedules and their cron file were saved again as this version reads them' : 'Schedules: '.$healed['why'];
	echo $said, "\n";
	zfsmLog($said, $healed['why'] === '' ? LOG_NOTICE : LOG_ERR);
	exit($healed['why'] === '' ? 0 : 1);
}
if (($argv[1] ?? '') !== 'run' || !zfsmScheduleId($argv[2] ?? null)) {
	fwrite(STDERR, "Usage: schedule.php run <schedule id> | schedule.php heal\n");
	exit(2);
}
exit(zfsmScheduleRun($argv[2], loadConfig(parse_plugin_cfg('zfs.master', true)), time()));
