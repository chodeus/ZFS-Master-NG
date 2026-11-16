<?php
// sshd's forced command for tests/replica_remote_test.php's fake ssh: the checker's real request handling, on this repo's fake zfs.
require __DIR__.'/fake_bin_guard.php';
require_once __DIR__.'/../../zfs.master/backend/ZFSMOperations.php';
$cfg = loadConfig(array('general' => array('replica_accept' => (string)getenv('ZFSM_TEST_ACCEPT'), 'replica_keep' => (string)getenv('ZFSM_TEST_REPLICA_KEEP'))));
exit(zfsmServeReplicaSettings((string)getenv('SSH_ORIGINAL_COMMAND'), $cfg, (string)getenv('ZFSM_TEST_SENDER')));
