#!/usr/bin/php -q
<?php
// The only command a replication key may run (authorized_keys command=); include/ZFSMReplicaServe.php checks each request. Must stay executable: pkg_build.sh.
// Nothing from the environment is trusted: test hooks are dropped, PATH is fixed, and the docroot comes from this script's own path.
foreach (array_keys(getenv()) as $name) if (strpos($name, 'ZFSM_TEST_') === 0) putenv($name);
putenv('PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin');
$docroot = dirname($_SERVER['SCRIPT_FILENAME'], 4);
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/zfs.master/backend/ZFSMOperations.php";

exit(zfsmServeReplica((string)getenv('SSH_ORIGINAL_COMMAND'), loadConfig(parse_plugin_cfg('zfs.master', true))['replica_accept']));
