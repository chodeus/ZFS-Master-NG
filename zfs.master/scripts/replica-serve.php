#!/usr/bin/php -q
<?php
// The only command a replication key may run: authorized_keys command="<this script> <sender>"; include/ZFSMReplicaServe.php checks each request.
// Must stay executable (pkg_build.sh). Only the request and the client's address are kept, PATH is fixed, the docroot is this script's path.
// What PHP reads as it starts is sshd's to refuse: PermitUserEnvironment no, its default.
foreach (array_keys(getenv()) as $name) if (!in_array($name, array('SSH_ORIGINAL_COMMAND', 'SSH_CLIENT'), true)) putenv($name);
putenv('PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin');
$docroot = dirname($_SERVER['SCRIPT_FILENAME'], 4);
// Waits while an update installs (include/ZFSMInstallLock.php); here, as any file loaded first could be one it is replacing.
$zfsm_install_hold = @fopen('/var/run/zfs.master.install.lock', 'ce');
$zfsm_install_waited = $zfsm_install_hold !== false && flock($zfsm_install_hold, LOCK_SH);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";
zfsmInstallDone($zfsm_install_hold, $zfsm_install_waited);

$sender = $argc === 2 ? $argv[1] : null;
exit(zfsmServeReplicaSettings((string)getenv('SSH_ORIGINAL_COMMAND'), loadConfig(parse_plugin_cfg('zfs.master', true)), $sender));
