<?php
// Required first by every PHP test that reaches zfs or zpool: those destroy and roll back, so stop unless both are this repo's fakes.
$zfsm_fake_bin = getenv('ZFSM_TEST_BIN_DIR');
foreach (array('zfs', 'zpool') as $zfsm_fake) {
	$zfsm_fake_at = is_string($zfsm_fake_bin) && $zfsm_fake_bin !== '' ? $zfsm_fake_bin.'/'.$zfsm_fake : '';
	if ($zfsm_fake_at !== '' && is_file($zfsm_fake_at) && is_executable($zfsm_fake_at) && hash_file('sha256', $zfsm_fake_at) === hash_file('sha256', __DIR__.'/bin/'.$zfsm_fake)) continue;
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold this repo's fake zfs and zpool\n");
	exit(1);
}
