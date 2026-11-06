<?php
// Required first by every PHP test that reaches zfs or zpool: those destroy and roll back, so stop unless both fakes are there.
$zfsm_fake_bin = getenv('ZFSM_TEST_BIN_DIR');
if (!is_string($zfsm_fake_bin) || $zfsm_fake_bin === '' || !is_executable($zfsm_fake_bin.'/zfs') || !is_executable($zfsm_fake_bin.'/zpool')) {
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold the fake zfs and zpool\n");
	exit(1);
}
