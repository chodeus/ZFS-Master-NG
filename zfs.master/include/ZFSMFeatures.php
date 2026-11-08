<?php
// Pool features: their state, the Unraid each needs, and what turns it on (zpool-features(7)).

// Every feature on the pool as name => 'active', 'enabled' or 'disabled'; one this OpenZFS lacks is unsupported@guid,
// 'readonly' when in use (the pool is open read-only) or 'inactive' when enabled (zpool-features(7)). null when unread.
function zfsmPoolFeatures($pool) {
	$result = runProcess('zpool', array('get', '-H', '-o', 'property,value', 'all', $pool));
	if (($result['code'] ?? 1) !== 0) return null;
	$features = array();
	foreach (explode("\n", (string)($result['output'] ?? '')) as $line) {
		$field = explode("\t", trim($line));
		if (count($field) !== 2) continue;
		if (strpos($field[0], 'feature@') === 0) $features[substr($field[0], 8)] = $field[1];
		elseif (strpos($field[0], 'unsupported@') === 0) $features[preg_replace('/^unsupported@(.*:)?/', '', $field[0])] = $field[1];
	}
	return $features;
}

// One feature's state; '' where this OpenZFS lacks it, null when the read failed.
function zfsmPoolFeature($pool, $feature) {
	$features = zfsmPoolFeatures($pool);
	return $features === null ? null : ($features[$feature] ?? '');
}

// The first Unraid that still imports the pool read-write once it has cloned; '' when cloning turns nothing on, null when unread.
// The first clone turns on block_cloning (OpenZFS 2.2, Unraid 7.0), and block_cloning_endian (2.4, Unraid 7.3) with it: module/zfs/brt.c.
function zfsmCloneTurnsOn($pool) {
	$features = zfsmPoolFeatures($pool);
	if ($features === null) return null;
	if (($features['block_cloning'] ?? '') !== 'enabled') return '';
	return ($features['block_cloning_endian'] ?? '') === 'enabled' ? '7.3' : '7.0';
}

// OpenZFS 2.1.0's features: every Unraid with built-in ZFS (6.12 and later) reads them.
function zfsmBaseFeatures() {
	return array('allocation_classes', 'async_destroy', 'bookmark_v2', 'bookmark_written', 'bookmarks', 'device_rebuild', 'device_removal',
		'draid', 'edonr', 'embedded_data', 'empty_bpobj', 'enabled_txg', 'encryption', 'extensible_dataset', 'filesystem_limits', 'hole_birth',
		'large_blocks', 'large_dnode', 'livelist', 'log_spacemap', 'lz4_compress', 'multi_vdev_crash_dump', 'obsolete_counts', 'project_quota',
		'redacted_datasets', 'redaction_bookmarks', 'resilver_defer', 'sha512', 'skein', 'spacemap_histogram', 'spacemap_v2',
		'userobj_accounting', 'zpool_checkpoint', 'zstd_compress');
}

// Newer features: the first Unraid that writes to a pool with it in use, whether older Unraid can still read it, what turns it on,
// and what Upgrade Pool does to it: 'at once' in use, in use at the 'next import', 'enabled', or '' when it never enables it.
// Unraid 7.0 ships OpenZFS 2.2, 7.1 and 7.2 ship 2.3, 7.3 ships 2.4; each release's zpool-features(7) and zfeature_common.c give the rest.
function zfsmFeatureCatalog() {
	return array(
		'blake3' => array('7.0', false, 'Setting a dataset\'s checksum to blake3', 'enabled'),
		'block_cloning' => array('7.0', true, 'Convert\'s clone, or any copy within the pool, such as with cp', 'enabled'),
		'head_errlog' => array('7.0', false, 'Upgrade Pool, at once and for good', 'at once'),
		'vdev_zaps_v2' => array('7.0', false, 'The first import after Upgrade Pool', 'next import'),
		'zilsaxattr' => array('7.0', true, 'A dataset\'s first synchronous write', 'enabled'),
		'fast_dedup' => array('7.1', true, 'Deduplicated writes to a new dedup table', 'enabled'),
		'large_microzap' => array('7.1', true, 'A directory\'s names passing 128 KiB, with zap_micro_max_size raised', 'enabled'),
		'longname' => array('7.1', false, 'A file name longer than 255 bytes', 'enabled'),
		'raidz_expansion' => array('7.1', false, 'Adding a disk to a RAID-Z group', 'enabled'),
		'redaction_list_spill' => array('7.1', false, 'zfs redact with more than 36 redaction snapshots', 'enabled'),
		'block_cloning_endian' => array('7.3', true, 'The first clone while block_cloning is Enabled', 'enabled'),
		'dynamic_gang_header' => array('7.3', false, 'A gang header over 512 bytes, once enabled by hand', ''),
		'physical_rewrite' => array('7.3', true, 'Rewrite Files with "Keep blocks\' birth times" ticked', 'enabled'),
	);
}

// The oldest Unraid that imports the pool read-write and read-only, now and after Upgrade Pool, from the features in use, and
// what would raise it; null when one in use is newer than the catalog. Rows grouped in use, enabled, off (those Upgrade Pool enables).
function zfsmPoolCompatibility($pool) {
	$features = zfsmPoolFeatures($pool);
	if ($features === null) return null;
	$catalog = zfsmFeatureCatalog();
	$groups = array('in_use' => array(), 'enabled' => array(), 'off' => array());
	$now = $after = array('6.12', '6.12');
	$unknown = array();
	foreach ($features as $name => $state) {
		$group = array('active' => 'in_use', 'readonly' => 'in_use', 'enabled' => 'enabled', 'inactive' => 'enabled', 'disabled' => 'off')[$state] ?? null;
		$entry = $catalog[$name] ?? null;
		if ($group === null || in_array($name, zfsmBaseFeatures(), true) || ($group === 'off' && ($entry[3] ?? '') === '')) continue;
		$groups[$group][] = array('name' => $name, 'unraid' => $entry[0] ?? null, 'readable' => $entry[1] ?? null, 'turned_on_by' => $entry[2] ?? null, 'on_upgrade' => $entry[3] ?? null);
		if ($entry === null) {
			if ($group === 'in_use') $unknown[] = $name;
			continue;
		}
		if ($group === 'in_use') $now = zfsmRaiseFloor($now, $entry);
		if ($group === 'in_use' || ($group === 'off' && $entry[3] !== 'enabled')) $after = zfsmRaiseFloor($after, $entry);
	}
	foreach ($groups as $key => $rows) {
		// Off: what Upgrade Pool puts in use comes first, as only that changes the answer.
		usort($rows, function($a, $b) use ($key) {
			return ($key === 'off' ? ($a['on_upgrade'] === 'enabled') <=> ($b['on_upgrade'] === 'enabled') : 0) ?: zfsmNewestFirst($a, $b);
		});
		$groups[$key] = $rows;
	}
	// Enabled features this OpenZFS can turn on that would raise the answer, newest Unraid first.
	$would_raise = array();
	foreach ($groups['enabled'] as $row) {
		if ($row['unraid'] === null || $features[$row['name']] !== 'enabled') continue;
		$raised = zfsmRaiseFloor($now, array($row['unraid'], $row['readable']));
		if ($raised !== $now) $would_raise[] = $row + array('raises_read_write' => $raised[0] !== $now[0]);
	}
	$known = !$unknown;
	return array('read_write' => $known ? $now[0] : null, 'read_only' => $known ? $now[1] : null,
		'after_upgrade' => $known && $after !== $now ? $after : null, 'would_raise' => $known ? $would_raise : array(), 'unknown' => $unknown) + $groups;
}

// A read-write and read-only floor, raised by one feature in use.
function zfsmRaiseFloor($floor, $entry) {
	if (version_compare($entry[0], $floor[0], '>')) $floor[0] = $entry[0];
	if (!$entry[1] && version_compare($entry[0], $floor[1], '>')) $floor[1] = $entry[0];
	return $floor;
}

// Rows the plugin can't place first, then the newest Unraid, then those older Unraid can't import at all.
function zfsmNewestFirst($a, $b) {
	if (($a['unraid'] === null) !== ($b['unraid'] === null)) return $a['unraid'] === null ? -1 : 1;
	$newer = version_compare((string)$b['unraid'], (string)$a['unraid']);
	if ($newer !== 0) return $newer;
	if ($a['readable'] !== $b['readable']) return $a['readable'] ? 1 : -1;
	return strcmp($a['name'], $b['name']);
}
