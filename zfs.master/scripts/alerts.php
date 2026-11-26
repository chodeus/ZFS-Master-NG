#!/usr/bin/php -q
<?php
// Unraid's cron runs "check"; saving the settings runs "schedule" (#command in ZFSMasterSettings.page). Must stay executable: pkg_build.sh.
$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '/usr/local/emhttp';
// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.
$zfsm_install_hold = @fopen('/var/run/zfs.master.install.lock', 'ce');
$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);

$zfsm_cfg = loadConfig(parse_plugin_cfg('zfs.master', true));
switch ($argv[1] ?? '') {
	case 'check':
		zfsmRunAlerts($zfsm_cfg, time());
		break;
	case 'schedule':
		parse_cron_cfg('zfs.master', 'alerts', zfsmAlertCron($zfsm_cfg['alert_interval'], "$docroot/plugins/zfs.master/scripts/alerts.php"));
		break;
	default:
		fwrite(STDERR, "Usage: alerts.php check|schedule\n");
		exit(2);
}
