<?php
// Pool features: their state, and what an action of this plugin turns on (zpool-features(7)).

// 'active', 'enabled' or 'disabled'; '' where this OpenZFS lacks the feature; null when the read failed otherwise.
function zfsmPoolFeature($pool, $feature) {
	$result = runProcess('zpool', array('get', '-H', '-o', 'value', 'feature@'.$feature, $pool));
	if (($result['code'] ?? 1) === 0) return trim((string)($result['output'] ?? ''));
	return strpos((string)($result['output'] ?? ''), 'invalid property') !== false ? '' : null;
}

// The first Unraid that still imports the pool read-write once it has cloned; '' when cloning turns nothing on, null when unread.
// The first clone turns on block_cloning (OpenZFS 2.2, Unraid 7.0), and block_cloning_endian (2.4, Unraid 7.3) with it: module/zfs/brt.c.
function zfsmCloneTurnsOn($pool) {
	$cloning = zfsmPoolFeature($pool, 'block_cloning');
	if ($cloning !== 'enabled') return $cloning === null ? null : '';
	// An endian state that can't be read counts as enabled: the stricter answer.
	return in_array(zfsmPoolFeature($pool, 'block_cloning_endian'), array('enabled', null), true) ? '7.3' : '7.0';
}
