<?php
// The install's heal (zfsmScheduleHeal) and Copy Keeps' shadows, on what v2026.11.16 left after removing a repeat from schedules
// this version saved (tests/fixtures/downgrade: with Copy Keeps on the other repeat, and without).

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
function publish($channel, $message) {}
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$work = sys_get_temp_dir().'/zfsm-downgrade-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($work, 0700, true);
register_shutdown_function(function() use ($work) { exec('rm -rf '.escapeshellarg($work)); });
putenv('ZFSM_TEST_JOB_DIR='.$work.'/state');
$root = $work.'/root';
$GLOBALS['zfsm_root'] = $root;
mkdir($root.'/boot/config/plugins/zfs.master', 0700, true);
mkdir($root.'/usr/local/sbin', 0700, true);
// Unraid's crontab rebuild, as schedule_test.php fakes it: logs each run.
file_put_contents($root.'/usr/local/sbin/update_cron', "#/bin/bash\necho ran >> ".escapeshellarg($work.'/cron.log')."\n");
chmod($root.'/usr/local/sbin/update_cron', 0755);
$builds = function() use ($work) { return count(is_file($work.'/cron.log') ? file($work.'/cron.log') : array()); };
$fixture = __DIR__.'/fixtures/downgrade/copy-keeps';
$put = function($json, $cron) {
	file_put_contents(zfsmScheduleFile(), $json);
	file_put_contents(zfsmScheduleCronFile(), $cron);
};
$script = '/usr/local/emhttp/plugins/zfs.master/scripts/schedule.php';
$old = json_decode(file_get_contents($fixture.'/schedules.json'), true);
same(array('aaaa0001', '5e1f0001', '5e1f0002', null), array_column($old, 'id') + array(3 => null), 'the fixture: the repeat that version kept, Snapshot and Replicate Every Dataset, then the shadow');
same(array(false, array('copy_keeps_of' => 'aaaa0001', 'value' => 0)), array(array_key_exists('copy_keeps', $old[0]), $old[3]), 'that version dropped Copy Keeps from the repeat and kept its shadow');
same("# Generated ZFS Master NG replication schedules:\n10 * * * * $script run aaaa0001 &> /dev/null\n\n", file_get_contents($fixture.'/schedules.cron'), 'and wrote only its repeat to the cron file');

// What that version left reads with Copy Keeps back from the shadow, and the shadow is no record the plugin can't use.
$put(file_get_contents($fixture.'/schedules.json'), file_get_contents($fixture.'/schedules.cron'));
$read = zfsmScheduleRead();
same(array(array('aaaa0001' => $old[0] + array('copy_keeps' => 0), '5e1f0001' => $old[1], '5e1f0002' => $old[2]), array()), array($read['valid'], $read['other']), 'Copy Keeps comes back from its shadow');

// Updating again: the heal writes both files as this version does, and Unraid's crontab is rebuilt from them.
same(array('saved' => true, 'why' => ''), zfsmScheduleHeal(), 'the heal saves both');
same("# Generated ZFS Master NG schedules:\n10 * * * * $script run aaaa0001 &> /dev/null\n5 * * * * $script run 5e1f0001 &> /dev/null\n15 3 * * * $script run 5e1f0002 &> /dev/null\n\n",
	file_get_contents(zfsmScheduleCronFile()), 'cron runs Snapshot Every Dataset, Replicate Every Dataset and the repeat again');
same(array($old[0] + array('copy_keeps' => 0), $old[1], $old[2], $old[3]), json_decode(file_get_contents(zfsmScheduleFile()), true), 'the repeat has its Copy Keeps again, the shadow after the schedules');
same(1, $builds(), 'Unraid rebuilt its crontab once');

$healed = file_get_contents(zfsmScheduleCronFile());

// The next boot runs it again: nothing is written (same file, same inode) and the crontab is not rebuilt.
$inodes = array(fileinode(zfsmScheduleFile()), fileinode(zfsmScheduleCronFile()));
same(array(null, $inodes, 1), array(zfsmScheduleHeal(), array(fileinode(zfsmScheduleFile()), fileinode(zfsmScheduleCronFile())), $builds()), 'a heal with nothing to put back writes nothing');

// With no Copy Keeps, that version writes schedules.json as this one does: only the cron file says what it stopped.
$plain = __DIR__.'/fixtures/downgrade/no-copy-keeps';
$put(file_get_contents($plain.'/schedules.json'), file_get_contents($plain.'/schedules.cron'));
$read = zfsmScheduleRead();
same(array(file_get_contents($plain.'/schedules.json'), file_get_contents($fixture.'/schedules.cron')), array(zfsmScheduleText($read['valid'], $read['other']), file_get_contents(zfsmScheduleCronFile())),
	'that version\'s schedules.json is as this one writes it, and its cron file has only the repeat');
same(array(array('saved' => true, 'why' => ''), $healed, file_get_contents($plain.'/schedules.json'), 2), array(zfsmScheduleHeal(), file_get_contents(zfsmScheduleCronFile()), file_get_contents(zfsmScheduleFile()), $builds()),
	'cron runs them again when only the cron file is stale');

// A file v2026.11.19.1 saved (Copy Keeps only in its schedule) gets its shadows at the install, so a later go-back keeps Copy Keeps;
// a field this version doesn't know, a later version's, stays.
$later = $old[0] + array('copy_keeps' => 0, 'later' => array('a' => 1));
$put(json_encode(array($later, $old[1], $old[2])), $healed);
same(array('saved' => true, 'why' => ''), zfsmScheduleHeal(), 'a file with no shadows is saved again');
same(array($later, array('copy_keeps_of' => 'aaaa0001', 'value' => 0)), array_values(array_intersect_key(json_decode(file_get_contents(zfsmScheduleFile()), true), array(0 => 1, 3 => 1))),
	'with the shadow of the Copy Keeps it holds, and the field this version does not know');

// From v2026.11.19.1 on, the cron file's header is this one's: a schedule it saved without Copy Keeps is one the user cleared there,
// and the heal drops its shadow. A cron file gone counts as v2026.11.16's, which removes it when it runs no schedule.
$put(file_get_contents($fixture.'/schedules.json'), $healed);
same(array($old[0], array()), array(zfsmScheduleRead()['valid']['aaaa0001'] ?? null, zfsmScheduleRead()['other']), 'Copy Keeps cleared in v2026.11.19.1 stays cleared');
same(array(array('saved' => true, 'why' => ''), array($old[0], $old[1], $old[2])), array(zfsmScheduleHeal(), json_decode(file_get_contents(zfsmScheduleFile()), true)), 'and its shadow is gone');
$put(file_get_contents($fixture.'/schedules.json'), '');
unlink(zfsmScheduleCronFile());
same($old[0] + array('copy_keeps' => 0), zfsmScheduleRead()['valid']['aaaa0001'] ?? null, 'with no cron file, Copy Keeps comes back from its shadow');

// The schedule's own Copy Keeps wins over its shadow. A shadow of no schedule is dropped at the next save; one that would make its
// schedule unusable (a copy on a server has no Copy Keeps) is not taken; a record shaped otherwise is one the plugin can't use.
$repeat = array_diff_key($old[0], array('copy_keeps' => 0));
$server = array_replace($repeat, array('id' => 'bbbb0001', 'dest' => 'data', 'server' => 'nas'));
$odd = array(array('copy_keeps_of' => 'aaaa000x', 'value' => 0), array('copy_keeps_of' => 'aaaa0001'), array('copy_keeps_of' => 'aaaa0001', 'value' => 0, 'more' => 1), array('aaaa0001', 0), array('id' => array(1)), 'x');
$put(json_encode(array_merge(array($repeat + array('copy_keeps' => '30d'), $server, array('copy_keeps_of' => 'aaaa0001', 'value' => 0), array('copy_keeps_of' => 'cccc0001', 'value' => 3),
	array('copy_keeps_of' => 'bbbb0001', 'value' => 3)), $odd)), file_get_contents($fixture.'/schedules.cron'));
$read = zfsmScheduleRead();
same(array('30d', $server, $odd), array($read['valid']['aaaa0001']['copy_keeps'] ?? null, $read['valid']['bbbb0001'] ?? null, $read['other']),
	'its own Copy Keeps wins; a server copy takes none; odd records are kept as records the plugin cannot use');
same(array('saved' => true, 'why' => ''), zfsmScheduleChange(function($jobs) { return $jobs; }), 'a save');
same(array(array('copy_keeps_of' => 'aaaa0001', 'value' => '30d')), array_values(array_filter(json_decode(file_get_contents(zfsmScheduleFile()), true), 'zfsmScheduleCopyKeepsShadowOf')),
	'the save leaves one shadow, of the Copy Keeps the schedule holds');

// No schedules.json: nothing is made. One that can't be read: the heal says so and leaves both files as they were.
unlink(zfsmScheduleFile());
unlink(zfsmScheduleCronFile());
same(array(null, false, false), array(zfsmScheduleHeal(), file_exists(zfsmScheduleFile()), file_exists(zfsmScheduleCronFile())), 'with no schedules, nothing is made');
$put('{', "kept\n");
same(array(array('saved' => false, 'why' => zfsmScheduleUnreadable()), '{', "kept\n"), array(zfsmScheduleHeal(), file_get_contents(zfsmScheduleFile()), file_get_contents(zfsmScheduleCronFile())),
	'an unread schedules.json is said and written over by nothing');

// The plg's command, through the real script on a scratch /boot/config: silent with nothing to do; says what failed, exit 1.
zfsmTestScratch('/boot/config');
@mkdir('/boot/config/plugins/zfs.master', 0700, true);
register_shutdown_function(function() { @unlink('/boot/config/plugins/zfs.master/schedules.json'); @unlink('/boot/config/plugins/zfs.master/schedules.cron'); });
$run = function($args) {
	exec(escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(getenv('ZFSM_TEST_DOCROOT').'/plugins/zfs.master/scripts/schedule.php').' '.$args.' 2>&1', $said, $code);
	return array($code, implode("\n", $said));
};
// The suite's tests share this log: only what this test adds counts.
$mark = strlen((string)@file_get_contents('/var/log/zfs.master.log'));
$logged = function() use ($mark) { return substr((string)@file_get_contents('/var/log/zfs.master.log'), $mark); };
same(array(array(0, ''), ''), array($run('heal'), $logged()), 'no schedules: nothing said or logged');
file_put_contents('/boot/config/plugins/zfs.master/schedules.json', '{');
$unread = 'Schedules: /boot/config/plugins/zfs.master/schedules.json could not be read as a list of schedules; fix or remove it first';
same(array(array(1, $unread), 1), array($run('heal'), substr_count($logged(), $unread)), 'an unread file is said and logged, exit 1');
copy($fixture.'/schedules.json', '/boot/config/plugins/zfs.master/schedules.json');
$said = $run('heal');
same(array(1, 1, 1), array($said[0], preg_match('~^Schedules: The schedules were saved, but Unraid\'s crontab could not be rebuilt \(~', $said[1]),
	substr_count((string)@file_get_contents('/boot/config/plugins/zfs.master/schedules.cron'), ' run 5e1f0001 ')), 'with no update_cron here, the rebuild failing is said, exit 1, and the files are written');
same(array(array(0, ''), 2), array($run('heal'), substr_count($logged(), 'Schedules: ')), 'the next heal has nothing to do');
same(array(2, 'Usage: schedule.php run <schedule id> | schedule.php start | schedule.php heal'), $run('heal x'), 'heal takes nothing more');

echo "schedule_downgrade_test passed\n";
