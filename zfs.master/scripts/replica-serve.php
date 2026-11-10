#!/usr/bin/php -q
<?php
// The only command a replication key may run: authorized_keys command="<this script> <sender>"; include/ZFSMReplicaServe.php checks each request.
// Must stay executable (pkg_build.sh). Nothing from the environment is trusted: test hooks go, PATH is fixed, the docroot is this script's path.
foreach (array_keys(getenv()) as $name) if (strpos($name, 'ZFSM_TEST_') === 0) putenv($name);
putenv('PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin');
$docroot = dirname($_SERVER['SCRIPT_FILENAME'], 4);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

$sender = $argc === 2 ? $argv[1] : null;
exit(zfsmServeReplica((string)getenv('SSH_ORIGINAL_COMMAND'), zfsmReplicaArea(loadConfig(parse_plugin_cfg('zfs.master', true))['replica_accept'], $sender)));
