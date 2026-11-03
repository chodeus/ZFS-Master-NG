<?php

define('__ROOT__', dirname(dirname(__FILE__)));

require_once __ROOT__."/include/ZFSMBase.php";
require_once __ROOT__."/include/ZFSMError.php";
require_once __ROOT__."/include/ZFSMHelpers.php";
$unraid_helpers = '/usr/local/emhttp/webGui/include/Helpers.php';
$unraid_publish = '/usr/local/emhttp/webGui/include/publish.php';
if (is_file($unraid_helpers)) require_once $unraid_helpers;
if (is_file($unraid_publish)) require_once $unraid_publish;

// Unraid before 6.12 runs PHP 7.4, which has no str_starts_with.
function zfsmStartsWith($text, $prefix) {
	return strncmp($text, $prefix, strlen($prefix)) === 0;
}

function buildArrayRet() {
	return array('succeeded' => array(), 'failed' => array());
}

function zfsmFailure($subject, $message) {
	$ret = buildArrayRet();
	$ret['failed'][$subject] = $message;
	return $ret;
}

function zfsmValidPoolName($name) {
	return is_string($name) && strlen($name) > 0 && strlen($name) <= 255
		&& preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $name) === 1;
}

// Mirrors valid_char() in OpenZFS zfs_namecheck.c; Unraid shares with spaces become datasets.
function zfsmValidNameComponent($part) {
	return is_string($part) && $part !== '.' && $part !== '..' && preg_match('/^[A-Za-z0-9_.: -]+$/D', $part) === 1;
}

function zfsmValidDatasetName($name) {
	if (!is_string($name) || strlen($name) === 0 || strlen($name) > 1024) return false;
	$parts = explode('/', $name);
	foreach ($parts as $part) {
		if (!zfsmValidNameComponent($part)) return false;
	}
	return zfsmValidPoolName($parts[0]);
}

function zfsmValidSnapshotName($name) {
	if (!is_string($name) || substr_count($name, '@') !== 1) return false;
	list($dataset, $snapshot) = explode('@', $name, 2);
	return zfsmValidDatasetName($dataset) && strlen($snapshot) <= 255 && zfsmValidNameComponent($snapshot);
}

function zfsmDatasetExists($name, $types = 'filesystem,volume') {
	if (!zfsmValidDatasetName($name)) return false;
	$result = runProcess('zfs', array('list', '-H', '-o', 'name', '-t', $types, $name));
	return ($result['code'] ?? 1) === 0 && trim($result['output'] ?? '') === $name;
}

// True when ZFS says it does not exist, false when it does, null when the read failed.
function zfsmDatasetMissing($name) {
	if (!zfsmValidDatasetName($name)) return null;
	$result = runProcess('zfs', array('list', '-H', '-o', 'name', $name));
	if (($result['code'] ?? 1) === 0) return false;
	return stripos($result['output'] ?? '', 'dataset does not exist') !== false ? true : null;
}

function zfsmSnapshotExists($name) {
	if (!zfsmValidSnapshotName($name)) return false;
	$result = runProcess('zfs', array('list', '-H', '-o', 'name', '-t', 'snapshot', $name));
	return ($result['code'] ?? 1) === 0 && trim($result['output'] ?? '') === $name;
}

function zfsmDatasetColumns() {
	return array('name', 'type', 'used', 'available', 'referenced', 'encryption', 'keystatus', 'mountpoint', 'compression', 'compressratio', 'usedbysnapshots', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync', 'creation', 'origin', 'volblocksize');
}

function zfsmSnapshotColumns() {
	return array('name', 'used', 'referenced', 'written', 'defer_destroy', 'userrefs', 'creation');
}

function zfsmNormalizeProperty($property, $value) {
	if ($value === '-') {
		if ($property === 'keystatus' || $property === 'mountpoint') return 'none';
		return null;
	}
	if ($property === 'quota' && $value === 'none') return 0;
	if ($property === 'compressratio') {
		if (preg_match('/^([0-9.]+)x$/', $value, $match)) return (int)round(((float)$match[1]) * 100);
		return is_numeric($value) ? (int)$value : 0;
	}
	$numeric = array('used', 'available', 'referenced', 'usedbysnapshots', 'quota', 'recordsize', 'creation', 'volblocksize', 'written', 'userrefs');
	return in_array($property, $numeric, true) && is_numeric($value) ? (int)$value : $value;
}

function zfsmParseTable($output, $columns) {
	$rows = array();
	foreach (preg_split('/\r\n|\r|\n/', trim((string)$output), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line);
		if (count($parts) !== count($columns)) continue;
		$row = array();
		foreach ($columns as $index => $property) {
			$value = zfsmNormalizeProperty($property, $parts[$index]);
			if ($value !== null) $row[$property] = $value;
		}
		$rows[] = $row;
	}
	return $rows;
}

// A saved pattern with '%' is Lua, the original format ('%' is not valid in a ZFS name). Anything else is a regex.
function zfsmExclusionToRegex($pattern) {
	if (strpos($pattern, '%') === false) return "\x01".$pattern."\x01i";
	$classes = array('a' => 'alpha', 'c' => 'cntrl', 'd' => 'digit', 'l' => 'lower', 'p' => 'punct', 's' => 'space', 'u' => 'upper', 'w' => 'alnum', 'x' => 'xdigit');
	$regex = '';
	$in_set = false;
	$repeatable = false;
	$length = strlen($pattern);
	for ($i = 0; $i < $length; $i++) {
		$char = $pattern[$i];
		if ($char === '%') {
			if (++$i >= $length) return null;
			$next = $pattern[$i];
			$class = $classes[strtolower($next)] ?? null;
			if ($class === null && ctype_alnum($next)) return null; // %b, %f and %1 have no regex equivalent
			$posix = '[:'.(ctype_upper($next) ? '^' : '').$class.':]';
			$regex .= $class === null ? preg_quote($next, "\x01") : ($in_set ? $posix : '['.$posix.']');
			$repeatable = !$in_set;
		} elseif ($in_set) {
			$regex .= $char === '\\' ? '\\\\' : $char;
			if ($char === ']') {
				$in_set = false;
				$repeatable = true;
			}
		} elseif ($char === '[') {
			$regex .= $char;
			$in_set = true;
		} elseif ($char === '-') {
			$regex .= $repeatable ? '*?' : '\\-'; // Lua's lazy repeat
			$repeatable = false;
		} elseif (strpos('()^$*+?', $char) !== false) {
			$regex .= $char;
			$repeatable = false;
		} else {
			$regex .= strpos('\\|{}', $char) !== false ? '\\'.$char : $char;
			$repeatable = true;
		}
	}
	return "\x01".$regex."\x01";
}

function zfsmExclusionIsValid($pattern) {
	if (!is_string($pattern) || trim($pattern) === '') return true;
	$regex = zfsmExclusionToRegex($pattern);
	return $regex !== null && @preg_match($regex, '') !== false;
}

function zfsmMatchesExclusion($name, $pattern) {
	if (!is_string($pattern) || trim($pattern) === '' || !zfsmExclusionIsValid($pattern)) return false;
	return preg_match(zfsmExclusionToRegex($pattern), $name) === 1;
}

function zfsmLoadUserProperties($dataset_root, &$datasets) {
	$result = runProcess('zfs', array('get', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', 'name,property,value,source', 'all', $dataset_root));
	if (($result['code'] ?? 1) !== 0) return;
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line, 4);
		if (count($parts) < 3 || strpos($parts[1], ':') === false || !isset($datasets[$parts[0]])) continue;
		$datasets[$parts[0]][$parts[1]] = $parts[2];
	}
}

function zfsmBuildDatasetNode($name, $datasets, $children) {
	$node = $datasets[$name];
	$node['child'] = array();
	foreach ($children[$name] ?? array() as $child_name) $node['child'][$child_name] = zfsmBuildDatasetNode($child_name, $datasets, $children);
	ksort($node['child']);
	return $node;
}

function zfsmEmptyDatasetTree($pool, $error = '') {
	$tree = array('name' => $pool, 'type' => 'filesystem', 'mountpoint' => 'none', 'child' => array(), 'directories' => array(), 'snapshots' => array(), 'total_snapshots' => 0);
	if ($error !== '') $tree['_error'] = $error;
	return $tree;
}

function zfsmLoadPoolTree($pool, $include_snapshots, $exclusion_pattern, $extended) {
	if (!zfsmValidPoolName($pool)) return zfsmEmptyDatasetTree($pool, 'Invalid pool name');
	$columns = zfsmDatasetColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', implode(',', $columns), $pool));
	if (($result['code'] ?? 1) !== 0) {
		$error = trim($result['output'] ?? 'Unable to list datasets');
		error_log('ZFS Master NG dataset inventory failed for '.$pool.': '.$error);
		return zfsmEmptyDatasetTree($pool, $error);
	}

	$datasets = array();
	$excluded_roots = array();
	foreach (zfsmParseTable($result['output'], $columns) as $row) {
		$name = $row['name'] ?? '';
		$excluded = zfsmMatchesExclusion($name, $exclusion_pattern);
		foreach ($excluded_roots as $root) if ($name === $root || zfsmStartsWith($name, $root.'/')) $excluded = true;
		if ($excluded) {
			$excluded_roots[] = $name;
			continue;
		}
		$row['child'] = array();
		$row['directories'] = array();
		$row['snapshots'] = array();
		$datasets[$name] = $row;
	}

	if (!isset($datasets[$pool])) return zfsmEmptyDatasetTree($pool, 'Pool root dataset was not returned by zfs list');
	if ((int)$extended === 1) zfsmLoadUserProperties($pool, $datasets);

	$total_snapshots = 0;
	if ($include_snapshots) {
		$snapshot_columns = zfsmSnapshotColumns();
		$snap_result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'snapshot', '-o', implode(',', $snapshot_columns), $pool));
		if (($snap_result['code'] ?? 1) !== 0) {
			$error = trim($snap_result['output'] ?? 'Unable to list snapshots');
			error_log('ZFS Master NG snapshot inventory failed for '.$pool.': '.$error);
			$datasets[$pool]['_snapshot_error'] = $error;
		} else {
			foreach (zfsmParseTable($snap_result['output'], $snapshot_columns) as $snapshot) {
				$dataset_name = explode('@', $snapshot['name'] ?? '', 2)[0];
				if (!isset($datasets[$dataset_name])) continue;
				$datasets[$dataset_name]['snapshots'][] = $snapshot;
				$total_snapshots++;
			}
		}
	}

	$children = array();
	foreach (array_keys($datasets) as $name) {
		if ($name === $pool || strpos($name, '/') === false) continue;
		$parent = substr($name, 0, strrpos($name, '/'));
		if (isset($datasets[$parent])) $children[$parent][] = $name;
	}
	$tree = zfsmBuildDatasetNode($pool, $datasets, $children);
	$tree['total_snapshots'] = $total_snapshots;
	return sortDatasetArray($tree);
}

function listDirectories($path, $childs, $zexc_pattern) {
	if (!is_string($path) || $path === '' || $path === 'none' || $path === 'legacy' || $path[0] !== '/' || !is_dir($path)) return array();
	$real_path = realpath($path);
	if ($real_path === false || $real_path === '/') return array();
	$remove = array($real_path.'/..', $real_path.'/.');
	foreach (is_array($childs) ? $childs : array() as $child) if (isset($child['mountpoint']) && is_string($child['mountpoint'])) $remove[] = rtrim($child['mountpoint'], '/');
	$dirs = glob(rtrim($real_path, '/').'/{,.}*', GLOB_ONLYDIR | GLOB_BRACE);
	if (!is_array($dirs)) return array();
	$dirs = array_values(array_unique(array_diff($dirs, $remove)));
	if ($zexc_pattern !== '') $dirs = array_values(array_filter($dirs, function($dir) use ($zexc_pattern) { return !zfsmMatchesExclusion($dir, $zexc_pattern); }));
	sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
	return $dirs;
}

function saveConfig($array) {
	$content = '';
	foreach ($array as $key => $elem) {
		if (!is_array($elem)) continue;
		$content .= '['.$key."]\n";
		foreach ($elem as $key2 => $elem2) {
			$values = is_array($elem2) ? $elem2 : array($elem2);
			foreach ($values as $value) {
				$value = str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '', ''), (string)$value);
				$content .= $key2.(is_array($elem2) ? '[]' : '').' = "'.$value."\"\n";
			}
		}
	}
	return file_put_contents('/boot/config/plugins/zfs.master/zfs.master.cfg', $content, LOCK_EX) !== false;
}

function addToDirectoryListing($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return zfsmFailure($zdataset, 'Dataset does not exist or is not a filesystem');
	$config = parse_plugin_cfg('zfs.master', true);
	$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
	if (in_array($zdataset, $current, true)) return zfsmFailure($zdataset, ZFSM_ERR_ALREADY_SET_IN_CONFIG);
	$current[] = $zdataset;
	$config['general']['directory_listing'] = implode(PHP_EOL, array_values(array_unique($current)));
	$saved = saveConfig($config);
	$ret = buildArrayRet();
	$ret[$saved ? 'succeeded' : 'failed'][$zdataset] = $saved ? 0 : ZFSM_ERR_UNABLE_TO_SAVE;
	return $ret;
}

function removeFromDirectoryListing($zdataset) {
	$config = parse_plugin_cfg('zfs.master', true);
	$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
	$key = array_search($zdataset, $current, true);
	if ($key === false) return zfsmFailure($zdataset, ZFSM_ERR_NOT_IN_CONFIG);
	unset($current[$key]);
	$config['general']['directory_listing'] = implode(PHP_EOL, $current);
	$saved = saveConfig($config);
	$ret = buildArrayRet();
	$ret[$saved ? 'succeeded' : 'failed'][$zdataset] = $saved ? 0 : ZFSM_ERR_UNABLE_TO_SAVE;
	return $ret;
}

function getZFSPools($strict = false) {
	$ret_pools = array();
	$result = runProcess('zpool', array('list', '-H', '-o', 'name,size,alloc,free,health'));
	if (($result['code'] ?? 1) !== 0) {
		if (stripos($result['output'] ?? '', 'no pools available') === false) {
			$error = 'ZFS Master NG pool inventory failed: '.($result['output'] ?? 'unknown error');
			if ($strict) throw new RuntimeException($error);
			error_log($error);
		}
		return $ret_pools;
	}
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line);
		if (count($parts) < 5 || !zfsmValidPoolName($parts[0])) continue;
		$ret_pools[$parts[0]] = array('Pool' => $parts[0], 'Health' => $parts[4], 'Name' => '', 'Size' => $parts[1], 'MountPoint' => '', 'Refer' => '', 'Used' => $parts[2], 'Free' => $parts[3], 'Snapshots' => 0, 'Origin' => '');
	}
	return $ret_pools;
}

// HTTP inventory must not depend on a running daemon or Unraid's publish flag.
// The optional pool limits the second, progressive snapshot-loading phase.
function zfsmReadInventory($config, $include_snapshots = true, $pool = '') {
	$previous_deadline = $GLOBALS['zfsm_inventory_deadline'] ?? null;
	$GLOBALS['zfsm_inventory_deadline'] = microtime(true) + 45;
	try {
		return zfsmCollectInventory($config, $include_snapshots, $pool);
	} finally {
		if ($previous_deadline === null) unset($GLOBALS['zfsm_inventory_deadline']);
		else $GLOBALS['zfsm_inventory_deadline'] = $previous_deadline;
	}
}

function zfsmCollectInventory($config, $include_snapshots, $pool) {
	if ($pool !== '' && !zfsmValidPoolName($pool)) throw new InvalidArgumentException('Invalid pool name');
	$pools = getZFSPools(true);
	if ($pool !== '') {
		if (!isset($pools[$pool])) throw new RuntimeException('Pool is no longer imported: '.$pool);
		$pools = array($pool => $pools[$pool]);
	}
	$data = array('pools' => $pools, 'devices' => array(), 'datasets' => array(), 'errors' => array());
	if (!zfsmExclusionIsValid($config['dataset_exclusion'])) $data['warning'] = 'The dataset exclusion pattern is invalid and was ignored. Check ZFS Master NG settings.';
	foreach ($pools as $name => $info) {
		$data['devices'][$name] = getZFSPoolDevices($name);
		$loader = $include_snapshots ? 'getZFSPoolDatasetsAndSnapshots' : 'getZFSPoolDatasets';
		$tree = $loader($name, $config['dataset_exclusion'], $config['znapzend_data'], $config['directory_listing']);
		$data['datasets'][$name] = $tree;
		$data['pools'][$name]['MountPoint'] = $tree['mountpoint'] ?? '';
		$data['pools'][$name]['Snapshots'] = $include_snapshots ? ($tree['total_snapshots'] ?? 0) : null;
		foreach (array('_error', '_snapshot_error') as $key) {
			if (!empty($tree[$key])) $data['errors'][$name] = ($data['errors'][$name] ?? '').$tree[$key].' ';
		}
	}
	return array('op' => $include_snapshots ? 'getAll' : 'getDatasets', 'data' => $data);
}

function getZFSPoolDevices($zpool) {
	if (!zfsmValidPoolName($zpool)) return '';
	$result = runProcess('zpool', array('status', '-v', $zpool));
	if (($result['code'] ?? 1) !== 0) return '';
	$devices = array();
	$in_config = false;
	$skipped_pool = false;
	foreach (preg_split('/\r\n|\r|\n/', $result['output'] ?? '') ?: array() as $line) {
		if (trim($line) === 'config:') { $in_config = true; continue; }
		if (!$in_config) continue;
		if (zfsmStartsWith(trim($line), 'errors:')) break;
		$parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
		if (!$parts || $parts[0] === 'NAME' || count($parts) < 2) continue;
		if (!$skipped_pool && $parts[0] === $zpool) { $skipped_pool = true; continue; }
		$devices[] = $parts[0];
	}
	return implode("\n", $devices);
}

function getZFSPoolDatasets($zpool, $zexc_pattern, $ext, $directory_listing = array()) {
	$result = zfsmLoadPoolTree($zpool, false, $zexc_pattern, $ext);
	$result['directories'] = listDirectories($result['mountpoint'] ?? '', $result['child'] ?? array(), $zexc_pattern);
	if ($directory_listing) $result['child'] = getDatasetDirectories($result['child'] ?? array(), $directory_listing, $zexc_pattern, $ext);
	return $result;
}

function getZFSPoolDatasetsAndSnapshots($zpool, $zexc_pattern, $ext, $directory_listing = array()) {
	$result = zfsmLoadPoolTree($zpool, true, $zexc_pattern, $ext);
	$result['directories'] = listDirectories($result['mountpoint'] ?? '', $result['child'] ?? array(), $zexc_pattern);
	if ($directory_listing) $result['child'] = getDatasetDirectories($result['child'] ?? array(), $directory_listing, $zexc_pattern, $ext);
	return $result;
}

function getDatasetDirectories($dataset_tree, $directory_listing, $zexc_pattern, $ext) {
	foreach ($dataset_tree as &$dataset) {
		if (in_array($dataset['name'] ?? '', $directory_listing, true)) $dataset['directories'] = listDirectories($dataset['mountpoint'] ?? '', $dataset['child'] ?? array(), $zexc_pattern);
		if (!empty($dataset['child'])) $dataset['child'] = getDatasetDirectories($dataset['child'], $directory_listing, $zexc_pattern, $ext);
	}
	return $dataset_tree;
}

function getDatasetProperty($zpool, $zdataset, $zproperty) {
	if (!zfsmValidDatasetName($zdataset) || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $zproperty)) return null;
	$result = runProcess('zfs', array('get', '-H', '-p', '-o', 'value', $zproperty, $zdataset));
	return ($result['code'] ?? 1) === 0 ? zfsmNormalizeProperty($zproperty, trim($result['output'] ?? '')) : null;
}

function getAllDatasetProperties($zdataset, $ext) {
	if (!zfsmDatasetExists($zdataset)) return array('_error' => 'Dataset does not exist');
	$columns = zfsmDatasetColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-o', implode(',', $columns), $zdataset));
	if (($result['code'] ?? 1) !== 0) return array('_error' => trim($result['output'] ?? 'Unable to read dataset properties'));
	$rows = zfsmParseTable($result['output'], $columns);
	$properties = $rows[0] ?? array();
	if (!$properties) return array('_error' => 'ZFS returned no dataset properties');
	if ((int)$ext === 1) {
		$map = array($zdataset => $properties);
		zfsmLoadUserProperties($zdataset, $map);
		$properties = $map[$zdataset];
	}
	return $properties;
}

function getDatasetSnapshotsResult($zpool, $zdataset) {
	if (!zfsmValidPoolName($zpool) || !zfsmValidDatasetName($zdataset) || explode('/', $zdataset, 2)[0] !== $zpool) return array('ok' => false, 'snapshots' => array(), 'error' => 'Invalid pool or dataset name');
	$columns = zfsmSnapshotColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', implode(',', $columns), $zdataset));
	if (($result['code'] ?? 1) !== 0) return array('ok' => false, 'snapshots' => array(), 'error' => trim($result['output'] ?? 'Unable to list snapshots'));
	$snapshots = zfsmParseTable($result['output'], $columns);
	usort($snapshots, function($a, $b) { return ($a['creation'] ?? 0) <=> ($b['creation'] ?? 0); });
	return array('ok' => true, 'snapshots' => $snapshots, 'error' => '');
}

function getDatasetSnapshots($zpool, $zdataset) {
	return getDatasetSnapshotsResult($zpool, $zdataset)['snapshots'];
}

function zfsmAllowedDatasetProperties() {
	return array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync');
}

function zfsmSafePropertyValue($property, $value) {
	if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) return false;
	if ($property === 'mountpoint') return in_array($value, array('none', 'legacy'), true) || ($value !== '' && $value[0] === '/');
	return $value !== '';
}

function createDataset($zdataset, $zoptions) {
	if (!zfsmValidDatasetName($zdataset)) return zfsmFailure($zdataset, 'Invalid dataset name');
	$pool = explode('/', $zdataset, 2)[0];
	$passphrase = (string)($zoptions['passphrase'] ?? '');
	unset($zoptions['passphrase']);
	$allowed = array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync', 'encryption', 'keylocation', 'keyformat');
	// Read-only is set last, or the share owner below could not be applied.
	$readonly = ($zoptions['readonly'] ?? '') === 'on';
	if ($readonly) unset($zoptions['readonly']);
	$args = array('create');
	foreach ($zoptions as $property => $value) {
		if (!in_array($property, $allowed, true) || !zfsmSafePropertyValue($property, (string)$value)) return zfsmFailure($zdataset, 'Invalid dataset property: '.$property);
		$args[] = '-o';
		$args[] = $property.'='.$value;
	}
	$args[] = $zdataset;
	$encrypted = ($zoptions['encryption'] ?? 'off') === 'on';
	if ($encrypted && strlen($passphrase) < 8) return zfsmFailure($zdataset, 'Enter a passphrase of at least 8 characters, or set Encryption to No.');
	$parts = explode('/', $zdataset);
	$created = array();
	for ($depth = 2; $depth <= count($parts); $depth++) {
		$name = implode('/', array_slice($parts, 0, $depth));
		$missing = zfsmDatasetMissing($name);
		if ($missing === null) return zfsmFailure($zdataset, 'Could not check whether '.$name.' exists, so nothing was created.');
		if ($missing) $created[] = $name;
	}
	// zfs create -p succeeds on an existing target, so the target is created without it.
	if (end($created) !== $zdataset) return zfsmFailure($zdataset, 'A dataset with this name already exists.');
	$parents = array_slice($created, 0, -1);
	$result = $parents ? runProcess('zfs', array('create', '-p', end($parents))) : array('code' => 0);
	if (($result['code'] ?? 1) === 0) $result = runProcess('zfs', $args, $encrypted ? $passphrase."\n".$passphrase."\n" : null);
	$answer = commandAnswer($zdataset, $result);
	// New parents get Unraid's share owner even when the target fails; the target only once it is ours.
	foreach (($result['code'] ?? 1) === 0 ? $created : $parents as $name) {
		$mountpoint = getDatasetProperty($pool, $name, 'mountpoint');
		if (is_string($mountpoint) && $mountpoint !== '' && $mountpoint[0] === '/' && is_dir($mountpoint)) {
			@chown($mountpoint, 'nobody');
			@chgrp($mountpoint, 'users');
		}
	}
	if (($result['code'] ?? 1) !== 0 || !$readonly) return $answer;
	$set = runProcess('zfs', array('set', 'readonly=on', $zdataset));
	return ($set['code'] ?? 1) === 0 ? $answer : zfsmFailure($zdataset, 'Created, but it could not be made read-only: '.trim($set['output'] ?? ''));
}

function renameDataset($zdataset, $new_name, $force) {
	if (!zfsmDatasetExists($zdataset) || !zfsmValidDatasetName($new_name)) return zfsmFailure($new_name, 'Invalid source or destination dataset');
	$args = array('rename');
	if ((string)$force === '1') $args[] = '-f';
	$args[] = $zdataset;
	$args[] = $new_name;
	return commandAnswer($new_name, runProcess('zfs', $args));
}

function setDatasetProperty($zdataset, $property, $value) {
	if (!zfsmDatasetExists($zdataset) || !in_array($property, zfsmAllowedDatasetProperties(), true) || !zfsmSafePropertyValue($property, (string)$value)) return zfsmFailure($property, 'Invalid dataset, property, or value');
	$result = $value === 'inherit' ? runProcess('zfs', array('inherit', $property, $zdataset)) : runProcess('zfs', array('set', $property.'='.$value, $zdataset));
	return commandAnswer($property, $result);
}

function lockDataset($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return zfsmFailure($zdataset, 'Dataset does not exist');
	$unmount = runProcess('zfs', array('unmount', '-f', $zdataset));
	if (($unmount['code'] ?? 1) !== 0) return commandAnswer($zdataset, $unmount);
	return commandAnswer($zdataset, runProcess('zfs', array('unload-key', '-r', $zdataset)));
}

function unlockDataset($zdataset, $passphrase) {
	if (!zfsmDatasetExists($zdataset, 'filesystem') || !is_string($passphrase) || $passphrase === '') return zfsmFailure($zdataset, 'Invalid dataset or empty passphrase');
	$load = runProcess('zfs', array('load-key', $zdataset), $passphrase."\n");
	if (($load['code'] ?? 1) !== 0) return commandAnswer($zdataset, $load);
	return commandAnswer($zdataset, runProcess('zfs', array('mount', $zdataset)));
}

function promoteDataset($zdataset, $zforce = 0) {
	if (!zfsmDatasetExists($zdataset)) return zfsmFailure($zdataset, 'Dataset does not exist');
	return commandAnswer($zdataset, runProcess('zfs', array('promote', $zdataset)));
}

function destroyDataset($zdataset, $force) {
	if (!zfsmDatasetExists($zdataset)) return zfsmFailure($zdataset, 'Dataset does not exist');
	$args = array('destroy', '-v');
	if ((string)$force === '1') array_push($args, '-r', '-f');
	$args[] = $zdataset;
	return commandAnswer($zdataset, runProcess('zfs', $args));
}

function zfsmFilesystemMounts() {
	$result = runProcess('zfs', array('list', '-H', '-o', 'name,mountpoint', '-t', 'filesystem'));
	$mounts = array();
	if (($result['code'] ?? 1) !== 0) return $mounts;
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line, 2);
		if (count($parts) !== 2 || $parts[1] === 'none' || $parts[1] === 'legacy' || $parts[1] === '/' || !is_dir($parts[1])) continue;
		$real = realpath($parts[1]);
		if ($real !== false) $mounts[$parts[0]] = rtrim($real, '/');
	}
	uksort($mounts, function($a, $b) use ($mounts) { return strlen($mounts[$b]) <=> strlen($mounts[$a]); });
	return $mounts;
}

function zfsmResolveDirectory($path, $must_exist = true) {
	if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path)) return null;
	$resolved = $must_exist ? realpath($path) : realpath(dirname($path));
	if ($resolved === false) return null;
	if (!$must_exist) $resolved = rtrim($resolved, '/').'/'.basename($path);
	foreach (zfsmFilesystemMounts() as $dataset => $mountpoint) {
		if ($resolved !== $mountpoint && zfsmStartsWith($resolved, $mountpoint.'/')) return array('path' => $resolved, 'dataset' => $dataset, 'mountpoint' => $mountpoint);
	}
	return null;
}

function moveDirectory($directory, $new_name) {
	$source = zfsmResolveDirectory($directory, true);
	$destination = zfsmResolveDirectory($new_name, false);
	if (!$source || !$destination || $source['dataset'] !== $destination['dataset'] || file_exists($destination['path'])) return zfsmFailure($new_name, 'Directory move is outside its dataset or destination exists');
	if (@rename($source['path'], $destination['path'])) {
		$ret = buildArrayRet();
		$ret['succeeded'][$destination['path']] = 0;
		return $ret;
	}
	$error = error_get_last();
	return zfsmFailure($new_name, $error['message'] ?? 'Unable to move directory');
}

// The reason to refuse work on a directory that is a mount point or holds one; '' when there is none.
function zfsmNestedMountProblem($path) {
	$table = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
	if ($table === false) return 'Unable to read the mount table';
	foreach ($table as $line) {
		$mount = preg_replace_callback('/\\\\([0-7]{3})/', function($match) { return chr(octdec($match[1])); }, explode(' ', $line)[4] ?? '');
		if ($mount === rtrim($path, '/')) return 'This directory is itself a mount point, so its contents belong to another filesystem; destroy or unmount that instead.';
		if (zfsmStartsWith($mount, rtrim($path, '/').'/')) return 'Another filesystem is mounted inside this directory at '.$mount.'; unmount or move it first';
	}
	return '';
}

function deleteDirectory($directory, $force) {
	$resolved = zfsmResolveDirectory($directory, true);
	if (!$resolved) return zfsmFailure($directory, 'Directory is outside a mounted ZFS filesystem');
	$mounted = (string)$force === '1' ? zfsmNestedMountProblem($resolved['path']) : '';
	if ($mounted !== '') return zfsmFailure($directory, $mounted);
	if ((string)$force === '1') return commandAnswer($resolved['path'], runProcess('rm', array('-rf', '--one-file-system', '--', $resolved['path'])));
	return commandAnswer($resolved['path'], runProcess('rmdir', array('--', $resolved['path'])));
}

// null when rsync cannot run. Unraid builds may lack ACL support, and -A would then abort the copy.
function zfsmRsyncArgs() {
	$version = runProcess('rsync', array('--version'));
	if (($version['code'] ?? 1) !== 0) return null;
	$args = array('-aHX', '--numeric-ids', '--one-file-system', '--filter=P /.zfs');
	if (preg_match('/(?<!no )\bACLs\b/', $version['output'] ?? '') === 1) $args[] = '-A';
	return $args;
}

// Progress is optional: a missing feed must not abort a copy already under way.
function zfsmCopyProgress($op, $data) {
	if (function_exists('publish')) publish('zfs_master', json_encode(array('op' => $op, 'data' => $data)));
}

function zfsmCopyWithProgress($rsync_args, $from, $to, $dataset_name) {
	$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1));
	$pipes = array();
	$command = array_merge(array('rsync'), $rsync_args, array('--stats', '--info=progress2', rtrim($from, '/').'/', rtrim($to, '/').'/'));
	$process = @proc_open($command, $spec, $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) return -1;
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	zfsmCopyProgress('start_directory_copy', array('dataset' => $dataset_name));
	do {
		$output = stream_get_contents($pipes[1]);
		if ($output !== '') zfsmCopyProgress('directory_copy', array('line' => $output, 'dataset' => $dataset_name));
		$status = proc_get_status($process);
		if ($status['running']) usleep(100000);
	} while ($status['running']);
	$remaining = stream_get_contents($pipes[1]);
	if ($remaining !== '') zfsmCopyProgress('directory_copy', array('line' => $remaining, 'dataset' => $dataset_name));
	fclose($pipes[1]);
	$closed_code = proc_close($process);
	zfsmCopyProgress('stop_directory_copy', array('dataset' => $dataset_name));
	return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed_code;
}

// Empty only when a checksum comparison of the two trees finds nothing to change.
function zfsmCopyDifferences($rsync_args, $from, $to) {
	$args = array_merge($rsync_args, array('--checksum', '--dry-run', '--delete', '--itemize-changes', rtrim($from, '/').'/', rtrim($to, '/').'/'));
	$result = runProcess('rsync', $args);
	if (($result['code'] ?? 1) !== 0) return 'rsync exit code '.($result['code'] ?? 1);
	$changes = preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array();
	return $changes ? count($changes).' differing, first: '.$changes[0] : '';
}

function convertDirectory($directory, $pool) {
	if (!zfsmValidPoolName($pool)) return zfsmFailure($directory, 'Invalid pool name');
	$source = zfsmResolveDirectory($directory, true);
	if (!$source || explode('/', $source['dataset'], 2)[0] !== $pool) return zfsmFailure($directory, 'Directory is not inside the selected pool');
	if (dirname($source['path']) !== $source['mountpoint']) return zfsmFailure($directory, 'Only a direct child directory of a dataset mountpoint can be converted');
	$relative = ltrim(substr($source['path'], strlen($source['mountpoint'])), '/');
	$dataset_name = $source['dataset'].($relative !== '' ? '/'.$relative : '');
	if (!zfsmValidDatasetName($dataset_name) || zfsmDatasetExists($dataset_name)) return zfsmFailure($directory, 'Target dataset name is invalid or already exists');
	$mounted = zfsmNestedMountProblem($source['path']);
	if ($mounted !== '') return zfsmFailure($directory, $mounted);
	if (file_exists($source['path'].'/.zfs')) return zfsmFailure($directory, 'A top-level .zfs entry cannot exist in a dataset; rename it first');
	$temp = $source['path'].'_zfsm_tmp_'.date('YmdHis');
	$moved = moveDirectory($source['path'], $temp);
	if (!$moved['succeeded']) return $moved;
	$created = createDataset($dataset_name, array());
	if (!$created['succeeded']) {
		moveDirectory($temp, $source['path']);
		return $created;
	}
	$mountpoint = getDatasetProperty($pool, $dataset_name, 'mountpoint');
	if (!is_string($mountpoint) || !is_dir($mountpoint)) {
		$cleanup = runProcess('zfs', array('destroy', $dataset_name));
		if (($cleanup['code'] ?? 1) === 0 && moveDirectory($temp, $source['path'])['succeeded']) return zfsmFailure($directory, 'Dataset mountpoint was unavailable; the new dataset was removed and the original directory was restored');
		return zfsmFailure($directory, 'Dataset mountpoint is unavailable; original data remains at '.$temp.' and manual recovery is required');
	}
	$retained = 'original data remains at '.$temp.' and the partial dataset was retained for inspection';
	$rsync_args = zfsmRsyncArgs();
	if ($rsync_args === null) return zfsmFailure($directory, 'Unable to run rsync; '.$retained);
	$exit_code = zfsmCopyWithProgress($rsync_args, $temp, $mountpoint, $dataset_name);
	if ($exit_code !== 0) return zfsmFailure($directory, 'rsync failed with exit code '.$exit_code.'; '.$retained);
	$differences = zfsmCopyDifferences($rsync_args, $temp, $mountpoint);
	if ($differences !== '') return zfsmFailure($directory, 'The copy did not verify ('.$differences.'); '.$retained);
	$deleted = deleteDirectory($temp, 1);
	if (!$deleted['succeeded']) return zfsmFailure($directory, 'Copy completed, but temporary source could not be removed: '.$temp);
	$ret = buildArrayRet();
	$ret['succeeded'][$directory] = 0;
	return $ret;
}

function createDatasetSnapshot($zdataset, $snapshot_name, $recursive) {
	if (!zfsmDatasetExists($zdataset) || !zfsmValidNameComponent($snapshot_name)) return zfsmFailure($zdataset, 'Invalid dataset or snapshot name');
	$args = array('snapshot');
	if ((string)$recursive === '1') $args[] = '-r';
	$full_name = $zdataset.'@'.$snapshot_name;
	$args[] = $full_name;
	return commandAnswer($full_name, runProcess('zfs', $args));
}

function rollbackDatasetSnapshot($snapshot, $destroy_newer = 0) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	$args = array('rollback');
	if ((string)$destroy_newer === '1') $args[] = '-r';
	$args[] = $snapshot;
	return commandAnswer($snapshot, runProcess('zfs', $args));
}

function renameDatasetSnapshot($zpool, $snapshot, $new_name) {
	if (!zfsmValidPoolName($zpool) || !zfsmSnapshotExists($snapshot) || !zfsmValidNameComponent($new_name)) return zfsmFailure($snapshot, 'Invalid snapshot rename request');
	$destination = explode('@', $snapshot, 2)[0].'@'.$new_name;
	return commandAnswer($destination, runProcess('zfs', array('rename', $snapshot, $destination)));
}

function holdDatasetSnapshot($snapshot) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	return commandAnswer($snapshot, runProcess('zfs', array('hold', 'zfsmaster', $snapshot)));
}

function releaseDatasetSnapshot($snapshot) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	return commandAnswer($snapshot, runProcess('zfs', array('release', 'zfsmaster', $snapshot)));
}

function cloneDatasetSnapshot($snapshot, $clone) {
	if (!zfsmSnapshotExists($snapshot) || !zfsmValidDatasetName($clone)) return zfsmFailure($snapshot, 'Invalid snapshot or clone dataset name');
	return commandAnswer($clone, runProcess('zfs', array('clone', $snapshot, $clone)));
}

function deleteDatasetSnapshot($snapshot, $recursive = 0) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	$args = array('destroy');
	if ((string)$recursive === '1') $args[] = '-r';
	$args[] = $snapshot;
	return commandAnswer($snapshot, runProcess('zfs', $args));
}

?>
