<?php
// sshd's forced command for tests/replica_remote_test.php's fake ssh: the checker's real request handling, on this repo's fake zfs.
require __DIR__.'/fake_bin_guard.php';
require_once __DIR__.'/../../zfs.master/backend/ZFSMOperations.php';
exit(zfsmServeReplica((string)getenv('SSH_ORIGINAL_COMMAND'), zfsmReplicaArea((string)getenv('ZFSM_TEST_ACCEPT'), (string)getenv('ZFSM_TEST_SENDER'))));
