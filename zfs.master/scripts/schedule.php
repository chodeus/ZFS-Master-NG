#!/usr/bin/php -q
<?php
// Cron runs "run <id>" from schedules.cron (include/ZFSMSchedule.php writes it), event/disks_mounted "start", and zfs.master.plg "heal".
// Must stay executable: pkg_build.sh.
// PHP's command line leaves DOCUMENT_ROOT empty; only the test harness sets ZFSM_TEST_DOCROOT.
$docroot = getenv('ZFSM_TEST_DOCROOT') ?: '/usr/local/emhttp';
// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.
$zfsm_install_hold = @fopen('/var/run/zfs.master.install.lock', 'ce');
$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);

if (($argv[1] ?? '') === 'heal' && !isset($argv[2])) {
	$healed = zfsmScheduleHeal();
	if ($healed === null) exit(0);
	$said = $healed['why'] === '' ? 'Schedules and their cron file were saved again as this version reads them' : 'Schedules: '.$healed['why'];
	echo $said, "\n";
	zfsmLog($said, $healed['why'] === '' ? LOG_NOTICE : LOG_ERR);
	exit($healed['why'] === '' ? 0 : 1);
}
if (($argv[1] ?? '') === 'start' && !isset($argv[2])) exit(zfsmScheduleStartRuns());
if (($argv[1] ?? '') !== 'run' || !zfsmScheduleId($argv[2] ?? null)) {
	fwrite(STDERR, "Usage: schedule.php run <schedule id> | schedule.php start | schedule.php heal\n");
	exit(2);
}
// zfsmScheduleStartRuns waits for it (zfsmStartJob); cron drops what a run prints.
zfsmJobStarted();
exit(zfsmScheduleRun($argv[2], loadConfig(parse_plugin_cfg('zfs.master', true)), time()));
