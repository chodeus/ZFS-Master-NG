<?php
// Pool features: their state, and what an action of this plugin turns on (zpool-features(7)).

// 'active', 'enabled' or 'disabled'; '' where the pool's OpenZFS lacks the feature or the read failed.
function zfsmPoolFeature($pool, $feature) {
	$result = runProcess('zpool', array('get', '-H', '-o', 'value', 'feature@'.$feature, $pool));
	return ($result['code'] ?? 1) === 0 ? trim((string)($result['output'] ?? '')) : '';
}

// The first Unraid that still imports the pool read-write after its first clone; '' when cloning turns nothing on.
// The first clone activates block_cloning (OpenZFS 2.2, Unraid 7.0), and block_cloning_endian (2.4, Unraid 7.3) with it: module/zfs/brt.c.
function zfsmCloneTurnsOn($pool) {
	if (zfsmPoolFeature($pool, 'block_cloning') !== 'enabled') return '';
	return zfsmPoolFeature($pool, 'block_cloning_endian') === 'enabled' ? '7.3' : '7.0';
}
