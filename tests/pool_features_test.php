<?php
// Pool features and which Unraid imports the pool, through the real runProcess and the fake zpool's `zpool get all`.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';
require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

// Each case sets the fake's features, then reads pool tank.
function with_features($features, $bclone, $read) {
	putenv('ZFSM_TEST_FEATURES='.$features);
	putenv($bclone === null ? 'ZFSM_TEST_BCLONE' : 'ZFSM_TEST_BCLONE='.$bclone);
	return $read();
}
// Each answer as: read-write, read-only, after Upgrade Pool, unknown features, then the in use, enabled and off rows by name.
$compat = function($features, $bclone = null) {
	$answer = with_features($features, $bclone, function() { return zfsmPoolCompatibility('tank'); });
	return $answer === null ? null : array($answer['read_write'], $answer['read_only'], $answer['after_upgrade'], $answer['unknown'],
		array_column($answer['in_use'], 'name'), array_column($answer['enabled'], 'name'), array_column($answer['off'], 'name'));
};

// Only features show, by name; a feature this OpenZFS lacks reads as '', and a failed read as null for every one.
$features = with_features('longname=enabled', 'active', function() { return zfsmPoolFeatures('tank'); });
same(array('async_destroy' => 'enabled', 'empty_bpobj' => 'active', 'lz4_compress' => 'active', 'draid' => 'disabled', 'block_cloning' => 'active', 'longname' => 'enabled'), $features, 'the features of zpool get all, and nothing else');
same(array('active', 'enabled', ''), with_features('longname=enabled', 'active', function() {
	return array(zfsmPoolFeature('tank', 'block_cloning'), zfsmPoolFeature('tank', 'longname'), zfsmPoolFeature('tank', 'physical_rewrite'));
}), 'one feature\'s state, and \'\' for a feature this OpenZFS lacks');
same(array(null, null, null), with_features('', 'fail', function() {
	return array(zfsmPoolFeatures('tank'), zfsmPoolFeature('tank', 'block_cloning'), zfsmPoolCompatibility('tank'));
}), 'a failed read is unknown, not a pool without features');
// A feature older OpenZFS lacks keeps its name and OpenZFS's own word, so Rewrite Files and Convert never take it as usable.
same(array('readonly', 'inactive', ''), with_features('unsupported@com.truenas:physical_rewrite=readonly unsupported@com.fudosecurity:block_cloning=inactive', null, function() {
	return array(zfsmPoolFeature('tank', 'physical_rewrite'), zfsmPoolFeature('tank', 'block_cloning'), zfsmRewriteFeature('tank'));
}), 'a feature this OpenZFS lacks, by its name');

// Only OpenZFS 2.1's features: every Unraid with ZFS reads it.
same(array('6.12', '6.12', null, array(), array(), array(), array()), $compat(''), 'a pool with only 2.1 features');
// In use features that older OpenZFS can't read at all set both answers; each group lists those first, newest Unraid first.
same(array('7.0', '7.0', null, array(), array('head_errlog', 'vdev_zaps_v2', 'block_cloning', 'zilsaxattr'), array('blake3'), array()),
	$compat('head_errlog=active vdev_zaps_v2=active zilsaxattr=active blake3=enabled', 'active'), 'an upgraded 7.0 pool: 6.12 can\'t import it');
// Read-only compatible ones leave older Unraid a read-only import.
same(array('7.0', '6.12', null, array(), array('zilsaxattr'), array('block_cloning'), array()), $compat('zilsaxattr=active', 'enabled'), 'a pool with only a read-only compatible feature in use');
same(array('7.3', '7.0', null, array(), array('physical_rewrite', 'head_errlog'), array(), array()), $compat('head_errlog=active physical_rewrite=active'), 'a -P rewrite on a 7.0 pool: 7.0 to 7.2 read-only');
same(array('7.1', '7.1', null, array(), array('longname'), array(), array()), $compat('longname=active'), 'a long file name needs 7.1 even to read');
// Enabled features never stop an import; off ones are listed when Upgrade Pool enables them.
same(array('6.12', '6.12', null, array(), array(), array('block_cloning_endian', 'fast_dedup'), array('raidz_expansion')),
	$compat('block_cloning_endian=enabled fast_dedup=enabled raidz_expansion=disabled'), 'enabled features don\'t raise the answer, nor do off ones Upgrade Pool only enables');
// Upgrade Pool puts head_errlog in use at once and vdev_zaps_v2 at the next import, listed first, and never enables dynamic_gang_header.
same(array('6.12', '6.12', array('7.0', '7.0'), array(), array(), array(), array('vdev_zaps_v2')), $compat('vdev_zaps_v2=disabled'), 'a feature in use from the next import counts after Upgrade Pool');
same(array('7.0', '6.12', array('7.0', '7.0'), array(), array('zilsaxattr'), array(), array('head_errlog', 'vdev_zaps_v2', 'blake3')),
	$compat('zilsaxattr=active head_errlog=disabled vdev_zaps_v2=disabled blake3=disabled dynamic_gang_header=disabled'), 'Upgrade Pool on a pool 6.12 reads read-only locks 6.12 out');
// A feature newer than the catalog can't be placed: in use, the answer is unknown; enabled, it is only listed; off, the answer after Upgrade Pool is.
same(array(null, null, null, array('future_feature'), array('future_feature', 'zilsaxattr'), array(), array('head_errlog')), $compat('zilsaxattr=active future_feature=active head_errlog=disabled'), 'an in use feature the plugin doesn\'t know, now or after Upgrade Pool');
same(array('7.0', '6.12', null, array(), array('zilsaxattr'), array('future_feature'), array('future_off')), $compat('zilsaxattr=active future_feature=enabled future_off=disabled'), 'an enabled or off feature the plugin doesn\'t know');
same(array(null, array('future_off'), array('head_errlog')), with_features('zilsaxattr=active head_errlog=disabled future_off=disabled', null, function() {
	$answer = zfsmPoolCompatibility('tank');
	return array($answer['after_upgrade'], $answer['upgrade_unknown'], $answer['upgrade_in_use']);
}), 'Upgrade Pool turning on a feature the plugin doesn\'t know: no claim about after it, though head_errlog is known');
$row = with_features('future_feature=enabled', null, function() { return zfsmPoolCompatibility('tank')['enabled'][0]; });
same(array('name' => 'future_feature', 'state' => 'enabled', 'unraid' => null, 'readable' => null, 'turned_on_by' => null, 'on_upgrade' => null), $row, 'an unknown feature\'s row says nothing it can\'t know');
// On older Unraid: a feature it lacks still counts, in use (the pool open read-only) or enabled.
same(array('7.3', '6.12', null, array(), array('physical_rewrite'), array('blake3'), array()), $compat('unsupported@com.truenas:physical_rewrite=readonly unsupported@org.openzfs:blake3=inactive'), 'a 7.3 pool on older Unraid needs 7.3 to write');
same(array(null, null, null, array('newer_feature'), array('newer_feature'), array(), array()), $compat('unsupported@com.example:newer_feature=readonly'), 'an unsupported feature the plugin doesn\'t know');

// What would raise the answer: enabled features this Unraid can turn on, needing a newer Unraid to write or older Unraid to read.
$raise = function($features, $bclone = null) {
	$answer = with_features($features, $bclone, function() { return zfsmPoolCompatibility('tank'); });
	return array_map(function($row) { return array($row['name'], $row['raises_read_write']); }, $answer['would_raise']);
};
same(array(), $raise('head_errlog=active vdev_zaps_v2=active zilsaxattr=active blake3=enabled', 'active'), 'an enabled feature no newer than the answer changes nothing');
same(array(array('block_cloning_endian', true), array('fast_dedup', true)), $raise('block_cloning_endian=enabled fast_dedup=enabled'), 'enabled features that need a newer Unraid, newest first');
same(array(array('blake3', false)), $raise('zilsaxattr=active blake3=enabled'), 'a feature that would stop older Unraid reading the pool, though the same Unraid still writes it');
same(array(), $raise('zilsaxattr=active unsupported@org.openzfs:blake3=inactive'), 'not a feature this Unraid lacks, as it can\'t turn it on');
same(array(), $raise('future_feature=active fast_dedup=enabled'), 'nothing when the answer is unknown');
// Once block_cloning is in use, no clone turns block_cloning_endian on (module/zfs/brt.c), so it raises nothing and says so.
same(array(), $raise('block_cloning_endian=enabled', 'active'), 'block_cloning_endian can\'t turn on after block_cloning is in use');
same('Nothing now: block_cloning was in use first', with_features('block_cloning_endian=enabled', 'active', function() { return zfsmPoolCompatibility('tank')['enabled'][0]['turned_on_by']; }), 'and its row says nothing turns it on');

// Upgrade Pool enables only features in every set the compatibility property lists, /etc/zfs/compatibility.d first (zpool-features(7)).
$sets = sys_get_temp_dir().'/zfsm-compat-'.getmypid();
mkdir($sets.'/etc', 0700, true);
mkdir($sets.'/usr', 0700, true);
register_shutdown_function(function() use ($sets) { exec('rm -rf '.escapeshellarg($sets)); });
file_put_contents($sets.'/usr/grub2', "# head_errlog vdev_zaps_v2: not for GRUB\nasync_destroy block_cloning\nzilsaxattr\n");
file_put_contents($sets.'/usr/narrow', "head_errlog\n");
file_put_contents($sets.'/usr/wide', "head_errlog,vdev_zaps_v2 blake3\n");
file_put_contents($sets.'/etc/wide', "blake3\n");
$GLOBALS['zfsm_compatibility_dirs'] = array($sets.'/etc', $sets.'/usr');
$upgrade = function($compatibility) {
	$answer = with_features('compatibility='.$compatibility.' zilsaxattr=active head_errlog=disabled vdev_zaps_v2=disabled blake3=disabled', null, function() { return zfsmPoolCompatibility('tank'); });
	return array($answer['compatibility'], $answer['after_upgrade'], array_column($answer['off'], 'name'), $answer['upgrade_unread'], $answer['upgrade_limited']);
};
same(array(null, array('7.0', '7.0'), array('head_errlog', 'vdev_zaps_v2', 'blake3'), false, false), $upgrade('off'), 'compatibility off: Upgrade Pool enables any feature');
same(array('grub2', null, array(), false, true), $upgrade('grub2'), 'a grub2 pool: Upgrade Pool turns on none of the newer features grub2 leaves out');
same(array('legacy', null, array(), false, true), $upgrade('legacy'), 'legacy: Upgrade Pool turns on nothing');
same(array('narrow', array('7.0', '7.0'), array('head_errlog'), false, true), $upgrade('narrow'), 'a set with head_errlog: Upgrade Pool puts it in use');
same(array('wide', null, array('blake3'), false, true), $upgrade('wide'), 'a set in /etc/zfs/compatibility.d wins over the same name in /usr/share');
same(array('narrow,'.$sets.'/usr/wide', array('7.0', '7.0'), array('head_errlog'), false, true), $upgrade('narrow,'.$sets.'/usr/wide'), 'two sets, one by path: only the features in both');
// A set PCRE fails to parse (its backtrack limit, JIT off) reads as unread, not a TypeError in the popup.
$limits = array(ini_get('pcre.backtrack_limit'), ini_get('pcre.jit'));
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '0');
$parsed = zfsmUpgradeAllows('grub2');
ini_set('pcre.backtrack_limit', $limits[0]);
ini_set('pcre.jit', $limits[1]);
same(false, $parsed, 'a set that fails to parse is unread');
same(array('missing', null, array(), true, false), $upgrade('missing'), 'a set that can\'t be read: no claim about Upgrade Pool, and it says so');
// A set that allows everything Upgrade Pool would turn on limits nothing, though the answer doesn't change.
file_put_contents($sets.'/usr/all', "head_errlog vdev_zaps_v2 blake3\n");
same(false, with_features('compatibility=all zilsaxattr=active head_errlog=disabled', null, function() { return zfsmPoolCompatibility('tank')['upgrade_limited']; }), 'a set that keeps nothing off limits nothing');
unset($GLOBALS['zfsm_compatibility_dirs']);

// The catalog: every entry is newer than 2.1, on an Unraid that shipped its OpenZFS, with a reason it turns on.
foreach (zfsmFeatureCatalog() as $name => $entry) {
	same(false, in_array($name, zfsmBaseFeatures(), true), "$name is both a 2.1 feature and a newer one");
	same(true, in_array($entry[0], array('7.0', '7.1', '7.3'), true) && is_bool($entry[1]) && $entry[2] !== '' && in_array($entry[3], array('at once', 'next import', 'enabled', ''), true), "$name has an Unraid, a read-only answer, a reason and an Upgrade Pool effect");
}
same(34, count(zfsmBaseFeatures()), 'OpenZFS 2.1.0 has 34 features');
same(13, count(zfsmFeatureCatalog()), 'OpenZFS 2.2 to 2.4 add 13');

putenv('ZFSM_TEST_FEATURES');
putenv('ZFSM_TEST_BCLONE');
echo "Pool features: passed\n";
