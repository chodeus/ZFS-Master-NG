<?php
// Required first by every PHP test that reaches zfs or zpool: those destroy and roll back, so stop unless both are this repo's fakes.
$zfsm_fake_bin = getenv('ZFSM_TEST_BIN_DIR');
foreach (array('zfs', 'zpool') as $zfsm_fake) {
	$zfsm_fake_at = is_string($zfsm_fake_bin) && $zfsm_fake_bin !== '' ? $zfsm_fake_bin.'/'.$zfsm_fake : '';
	if ($zfsm_fake_at !== '' && is_file($zfsm_fake_at) && is_executable($zfsm_fake_at) && hash_file('sha256', $zfsm_fake_at) === hash_file('sha256', __DIR__.'/bin/'.$zfsm_fake)) continue;
	fwrite(STDERR, "FAILED: ZFSM_TEST_BIN_DIR must hold this repo's fake zfs and zpool\n");
	exit(1);
}

// Each host folder a test writes in must be a tmpfs mounted for it, as tests/run_http_inventory_tests.sh mounts them, and never on an
// Unraid server, whose /var/log is a tmpfs of its own.
function zfsmTestScratch(...$folders) {
	$mounts = (string)@file_get_contents('/proc/self/mountinfo');
	foreach ($folders as $folder) {
		if (!is_file('/etc/unraid-version') && preg_match('~^\S+ \S+ \S+ \S+ '.preg_quote($folder, '~').' \S+(?: \S+)* - tmpfs ~m', $mounts) === 1) continue;
		fwrite(STDERR, "FAILED: $folder must be a tmpfs mounted for this test, and not on an Unraid server\n");
		exit(1);
	}
}
// zfsmLog, and every script a test starts, appends to /var/log/zfs.master.log; the bundle test writes the system log there.
zfsmTestScratch('/var/log');
