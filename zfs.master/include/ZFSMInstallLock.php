<?php
// An update holds this lock while upgradepkg replaces the plugin's files (zfs.master.plg's Run). Each script in scripts/ opens and
// locks it shared itself, before its first load (a file it loaded first could be one being replaced), then calls zfsmInstallDone.

function zfsmInstallLockFile() {
	return '/var/run/zfs.master.install.lock';
}

// Once a script has loaded the plugin: lets go of the lock or, when the script could not wait, says so in the plugin's log.
function zfsmInstallDone($hold, $waited) {
	if ($hold !== false) fclose($hold);
	if (!$waited) zfsmLog('This run did not wait for a plugin update to finish installing, as it could not lock '.zfsmInstallLockFile());
}
