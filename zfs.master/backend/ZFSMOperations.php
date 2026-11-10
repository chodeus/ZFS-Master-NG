<?php

define('__ROOT__', dirname(dirname(__FILE__)));

require_once __ROOT__."/include/ZFSMBase.php";
require_once __ROOT__."/include/ZFSMError.php";
require_once __ROOT__."/include/ZFSMHelpers.php";
require_once __ROOT__."/include/ZFSMImpact.php";
require_once __ROOT__."/include/ZFSMSnapshotFiles.php";
require_once __ROOT__."/include/ZFSMAlerts.php";
require_once __ROOT__."/include/ZFSMJobs.php";
require_once __ROOT__."/include/ZFSMRewrite.php";
require_once __ROOT__."/include/ZFSMReplicaPlan.php";
require_once __ROOT__."/include/ZFSMReplicate.php";
require_once __ROOT__."/include/ZFSMReplicaServe.php";
require_once __ROOT__."/include/ZFSMFeatures.php";
$unraid_helpers = '/usr/local/emhttp/webGui/include/Helpers.php';
$unraid_publish = '/usr/local/emhttp/webGui/include/publish.php';
if (is_file($unraid_helpers)) require_once $unraid_helpers;
if (is_file($unraid_publish)) require_once $unraid_publish;

// Unraid before 6.12 runs PHP 7.4, which has no str_starts_with.
function zfsmStartsWith($text, $prefix) {
	return strncmp($text, $prefix, strlen($prefix)) === 0;
}

// Whether $name is one of $roots or under one, with / between levels.
function zfsmUnderAny($name, $roots) {
	foreach ($roots as $root) if ($name === $root || zfsmStartsWith($name, $root.'/')) return true;
	return false;
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
	// Only the closing diagnostic counts: a name may itself contain the words.
	return preg_match('/: dataset does not exist\s*\z/', $result['output'] ?? '') === 1 ? true : null;
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
	// `zfs list -p` prints 1.16, without the x of the human output.
	if ($property === 'compressratio') return preg_match('/^([0-9]+(?:\.[0-9]+)?)x?$/D', $value, $match) ? (int)round(((float)$match[1]) * 100) : 0;
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

// A Lua %-escape as regex: a class such as %d, or the character itself; null for %b, %f and %1, which have no equivalent.
function zfsmLuaEscape($next, $in_set) {
	$classes = array('a' => 'alpha', 'c' => 'cntrl', 'd' => 'digit', 'l' => 'lower', 'p' => 'punct', 's' => 'space', 'u' => 'upper', 'w' => 'alnum', 'x' => 'xdigit');
	$class = $classes[strtolower($next)] ?? null;
	if ($class === null) return ctype_alnum($next) ? null : preg_quote($next, "\x01");
	$posix = '[:'.(ctype_upper($next) ? '^' : '').$class.':]';
	return $in_set ? $posix : '['.$posix.']';
}

// One Lua pattern character outside a %-escape as regex, tracking whether it is in a [set] and whether a - after it repeats it.
function zfsmLuaChar($char, &$in_set, &$repeatable) {
	if ($in_set) {
		if ($char === ']') {
			$in_set = false;
			$repeatable = true;
		}
		return $char === '\\' ? '\\\\' : $char;
	}
	if ($char === '[') {
		$in_set = true;
		return $char;
	}
	if ($char === '-') {
		$piece = $repeatable ? '*?' : '\\-'; // Lua's lazy repeat
		$repeatable = false;
		return $piece;
	}
	$repeatable = strpos('()^$*+?', $char) === false;
	return $repeatable && strpos('\\|{}', $char) !== false ? '\\'.$char : $char;
}

// A saved pattern with '%' is Lua, the original format ('%' is not valid in a ZFS name). Anything else is a regex.
function zfsmExclusionToRegex($pattern) {
	if (strpos($pattern, '%') === false) return "\x01".$pattern."\x01i";
	$regex = '';
	$in_set = false;
	$repeatable = false;
	$length = strlen($pattern);
	for ($i = 0; $i < $length; $i++) {
		if ($pattern[$i] !== '%') {
			$regex .= zfsmLuaChar($pattern[$i], $in_set, $repeatable);
			continue;
		}
		if (++$i >= $length) return null;
		$piece = zfsmLuaEscape($pattern[$i], $in_set);
		if ($piece === null) return null;
		$regex .= $piece;
		$repeatable = !$in_set;
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

// Dates in the user's Unraid display format, through Unraid's own my_time().
function zfsmTime($epoch) {
	global $display;
	if (!is_array($display ?? null) && function_exists('parse_plugin_cfg')) $display = parse_plugin_cfg('dynamix', true)['display'] ?? array();
	$text = function_exists('my_time') && !empty($display['date']) ? my_time((int)$epoch) : '';
	return is_string($text) && $text !== '' ? $text : date('Y-m-d H:i', (int)$epoch);
}

function zfsmAddTimeText(&$dataset) {
	if (isset($dataset['creation'])) $dataset['creation_text'] = zfsmTime($dataset['creation']);
	foreach ($dataset['snapshots'] ?? array() as $index => $snapshot) {
		if (isset($snapshot['creation'])) $dataset['snapshots'][$index]['creation_text'] = zfsmTime($snapshot['creation']);
	}
	foreach ($dataset['child'] ?? array() as $name => $child) {
		zfsmAddTimeText($child);
		$dataset['child'][$name] = $child;
	}
}

// Whether the exclusion pattern hides a dataset: it matches the dataset or one above it.
function zfsmDatasetExcluded($name, $exclusion_pattern) {
	$parts = explode('/', (string)$name);
	for ($depth = 1; $depth <= count($parts); $depth++) {
		if (zfsmMatchesExclusion(implode('/', array_slice($parts, 0, $depth)), $exclusion_pattern)) return true;
	}
	return false;
}

// The datasets of a zfs list, less any the exclusion pattern hides.
function zfsmIncludedDatasets($rows, $exclusion_pattern) {
	$datasets = array();
	foreach ($rows as $row) {
		$name = $row['name'] ?? '';
		if (zfsmDatasetExcluded($name, $exclusion_pattern)) continue;
		$row['child'] = array();
		$row['directories'] = array();
		$row['snapshots'] = array();
		$datasets[$name] = $row;
	}
	return $datasets;
}

// Files each snapshot under its dataset and returns how many there were; a failed list is noted on the pool root.
function zfsmAttachSnapshots($pool, &$datasets) {
	$columns = zfsmSnapshotColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'snapshot', '-o', implode(',', $columns), $pool));
	if (($result['code'] ?? 1) !== 0) {
		$error = zfsmCommandError($result, 'Unable to list snapshots');
		zfsmLog('ZFS Master NG snapshot inventory failed for '.$pool.': '.$error);
		$datasets[$pool]['_snapshot_error'] = $error;
		return 0;
	}
	$total = 0;
	foreach (zfsmParseTable($result['output'], $columns) as $snapshot) {
		$dataset_name = explode('@', $snapshot['name'] ?? '', 2)[0];
		if (!isset($datasets[$dataset_name])) continue;
		$datasets[$dataset_name]['snapshots'][] = $snapshot;
		$total++;
	}
	return $total;
}

function zfsmDatasetChildren($datasets, $pool) {
	$children = array();
	foreach (array_keys($datasets) as $name) {
		if ($name === $pool || strpos($name, '/') === false) continue;
		$parent = substr($name, 0, strrpos($name, '/'));
		if (isset($datasets[$parent])) $children[$parent][] = $name;
	}
	return $children;
}

function zfsmLoadPoolTree($pool, $include_snapshots, $exclusion_pattern, $extended) {
	if (!zfsmValidPoolName($pool)) return zfsmEmptyDatasetTree($pool, 'Invalid pool name');
	$columns = zfsmDatasetColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', implode(',', $columns), $pool));
	if (($result['code'] ?? 1) !== 0) {
		$error = zfsmCommandError($result, 'Unable to list datasets');
		zfsmLog('ZFS Master NG dataset inventory failed for '.$pool.': '.$error);
		return zfsmEmptyDatasetTree($pool, $error);
	}

	$datasets = zfsmIncludedDatasets(zfsmParseTable($result['output'], $columns), $exclusion_pattern);
	if (!isset($datasets[$pool])) return zfsmEmptyDatasetTree($pool, 'Pool root dataset was not returned by zfs list');
	if ((int)$extended === 1) zfsmLoadUserProperties($pool, $datasets);

	$total_snapshots = $include_snapshots ? zfsmAttachSnapshots($pool, $datasets) : 0;
	$tree = zfsmBuildDatasetNode($pool, $datasets, zfsmDatasetChildren($datasets, $pool));
	$tree['total_snapshots'] = $total_snapshots;
	return sortDatasetArray($tree);
}

// The real path of a dataset's mountpoint to list; null for none, legacy, / and anything that isn't a directory.
function zfsmListableMountpoint($path) {
	if (!is_string($path) || $path === '' || $path === 'none' || $path === 'legacy' || $path[0] !== '/' || !is_dir($path)) return null;
	$real_path = realpath($path);
	return $real_path === false || $real_path === '/' ? null : $real_path;
}

function listDirectories($path, $childs, $zexc_pattern) {
	$real_path = zfsmListableMountpoint($path);
	if ($real_path === null) return array();
	$remove = array($real_path.'/..', $real_path.'/.');
	foreach (is_array($childs) ? $childs : array() as $child) if (isset($child['mountpoint']) && is_string($child['mountpoint'])) $remove[] = rtrim($child['mountpoint'], '/');
	$dirs = glob(rtrim($real_path, '/').'/{,.}*', GLOB_ONLYDIR | GLOB_BRACE);
	if (!is_array($dirs)) return array();
	$dirs = array_values(array_unique(array_diff($dirs, $remove)));
	if ($zexc_pattern !== '') $dirs = array_values(array_filter($dirs, function($dir) use ($zexc_pattern) { return !zfsmMatchesExclusion($dir, $zexc_pattern); }));
	sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
	return $dirs;
}

// A value the settings reader would not give back as written: Unraid 6.12+ decodes entities and strips tags first,
// PHP expands ${NAME}, a carriage return may not survive, and a quote or leading # in a value with line breaks splits it.
function zfsmConfigValueUnsafe($value) {
	if (strpos($value, '${') !== false || strpbrk($value, "<\r") !== false) return true;
	if (preg_match('/&(#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $value)) return true;
	return strpos($value, "\n") !== false && (strpos($value, '"') !== false || preg_match('/^#/m', $value));
}

// Newlines stay: directory_listing holds one dataset per line inside its quotes. Null when a value can't be saved safely.
function zfsmConfigText($array) {
	$content = '';
	foreach ($array as $key => $elem) {
		if (!is_array($elem)) continue;
		$content .= '['.$key."]\n";
		foreach ($elem as $key2 => $elem2) {
			$values = is_array($elem2) ? $elem2 : array($elem2);
			foreach ($values as $value) {
				$value = (string)$value;
				if (zfsmConfigValueUnsafe($value)) return null;
				$value = str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
				$content .= $key2.(is_array($elem2) ? '[]' : '').' = "'.$value."\"\n";
			}
		}
	}
	return $content;
}

// A short write on a full flash drive must leave the old file in place.
function zfsmWriteFileAtomic($path, $content) {
	$temp = $path.'.tmp'.getmypid();
	$handle = @fopen($temp, 'wb');
	if ($handle === false) return false;
	$written = @fwrite($handle, $content);
	$flushed = @fflush($handle);
	$closed = @fclose($handle);
	if ($written === strlen($content) && $flushed && $closed && @rename($temp, $path)) return true;
	@unlink($temp);
	return false;
}

function saveConfig($array) {
	$text = zfsmConfigText($array);
	return $text !== null && zfsmWriteFileAtomic('/boot/config/plugins/zfs.master/zfs.master.cfg', $text);
}

function zfsmSaveDirectoryListing($config, $zdataset) {
	if (zfsmConfigText($config) === null) return zfsmFailure($zdataset, 'A ZFS Master setting holds text that cannot be saved safely: <, an HTML entity, ${...}, or a quote in the directory listing. Fix it on the Settings page.');
	$saved = saveConfig($config);
	$ret = buildArrayRet();
	$ret[$saved ? 'succeeded' : 'failed'][$zdataset] = $saved ? 0 : ZFSM_ERR_UNABLE_TO_SAVE;
	return $ret;
}

// Read, change and save the settings one request at a time, or an overlapping change is lost. The lock file is
// separate because a save replaces the settings file.
function zfsmWithConfigLock($zdataset, $change) {
	$lock = @fopen('/var/run/zfs.master.lock', 'c');
	if ($lock === false || !flock($lock, LOCK_EX)) {
		if ($lock !== false) fclose($lock);
		return zfsmFailure($zdataset, 'Unable to lock the ZFS Master settings');
	}
	try {
		return $change();
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function addToDirectoryListing($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return zfsmFailure($zdataset, 'Dataset does not exist or is not a filesystem');
	return zfsmWithConfigLock($zdataset, function() use ($zdataset) {
		$config = parse_plugin_cfg('zfs.master', true);
		$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
		if (in_array($zdataset, $current, true)) return zfsmFailure($zdataset, ZFSM_ERR_ALREADY_SET_IN_CONFIG);
		$current[] = $zdataset;
		$config['general']['directory_listing'] = implode(PHP_EOL, array_values(array_unique($current)));
		return zfsmSaveDirectoryListing($config, $zdataset);
	});
}

function removeFromDirectoryListing($zdataset) {
	return zfsmWithConfigLock($zdataset, function() use ($zdataset) {
		$config = parse_plugin_cfg('zfs.master', true);
		$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
		$key = array_search($zdataset, $current, true);
		if ($key === false) return zfsmFailure($zdataset, ZFSM_ERR_NOT_IN_CONFIG);
		unset($current[$key]);
		$config['general']['directory_listing'] = implode(PHP_EOL, $current);
		return zfsmSaveDirectoryListing($config, $zdataset);
	});
}

function getZFSPools($strict = false) {
	$ret_pools = array();
	$result = runProcess('zpool', array('list', '-H', '-o', 'name,size,alloc,free,health'));
	if (($result['code'] ?? 1) !== 0) {
		if (stripos($result['output'] ?? '', 'no pools available') === false) {
			$error = 'ZFS Master NG pool inventory failed: '.zfsmCommandError($result, 'unknown error');
			if ($strict) throw new RuntimeException($error);
			zfsmLog($error);
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
	return zfsmWithDeadline(45, function() use ($config, $include_snapshots, $pool) { return zfsmCollectInventory($config, $include_snapshots, $pool); });
}

function zfsmCollectInventory($config, $include_snapshots, $pool) {
	if ($pool !== '' && !zfsmValidPoolName($pool)) throw new InvalidArgumentException('Invalid pool name');
	$pools = getZFSPools(true);
	if ($pool !== '') {
		if (!isset($pools[$pool])) throw new RuntimeException('Pool is no longer imported: '.$pool);
		$pools = array($pool => $pools[$pool]);
	}
	$data = array('pools' => $pools, 'devices' => array(), 'datasets' => array(), 'errors' => array(), 'generated_text' => zfsmTime(time()));
	if (!zfsmExclusionIsValid($config['dataset_exclusion'])) $data['warning'] = 'The dataset exclusion pattern is invalid and was ignored. Check ZFS Master NG settings.';
	foreach ($pools as $name => $info) {
		$data['devices'][$name] = getZFSPoolDevices($name);
		$loader = $include_snapshots ? 'getZFSPoolDatasetsAndSnapshots' : 'getZFSPoolDatasets';
		$tree = $loader($name, $config['dataset_exclusion'], $config['znapzend_data'], $config['directory_listing']);
		zfsmAddTimeText($tree);
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
	if (($result['code'] ?? 1) !== 0) return array('_error' => zfsmCommandError($result, 'Unable to read dataset properties'));
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

// The dataset's snapshots, oldest first; with $children, those of every dataset under it that the exclusion pattern doesn't hide.
function getDatasetSnapshotsResult($zpool, $zdataset, $children = false, $exclusion_pattern = '') {
	if (!zfsmValidPoolName($zpool) || !zfsmValidDatasetName($zdataset) || explode('/', $zdataset, 2)[0] !== $zpool) return array('ok' => false, 'snapshots' => array(), 'error' => 'Invalid pool or dataset name');
	$columns = zfsmSnapshotColumns();
	$depth = $children ? array('-r') : array('-d', '1');
	$result = runProcess('zfs', array_merge(array('list', '-H', '-p'), $depth, array('-t', 'snapshot', '-o', implode(',', $columns), $zdataset)));
	if (($result['code'] ?? 1) !== 0) return array('ok' => false, 'snapshots' => array(), 'error' => zfsmCommandError($result, 'Unable to list snapshots'));
	$snapshots = zfsmParseTable($result['output'], $columns);
	if ($children) $snapshots = array_values(array_filter($snapshots, function($snap) use ($zdataset, $exclusion_pattern) {
		$dataset = explode('@', (string)($snap['name'] ?? ''), 2)[0];
		return $dataset === $zdataset || !zfsmDatasetExcluded($dataset, $exclusion_pattern);
	}));
	usort($snapshots, function($a, $b) { return ($a['creation'] ?? 0) <=> ($b['creation'] ?? 0); });
	return array('ok' => true, 'snapshots' => $snapshots, 'error' => '');
}

// Whether a filesystem or volume the exclusion pattern doesn't hide sits under the dataset. A failed read says no, which only hides the option.
function zfsmHasChildDatasets($zdataset, $exclusion_pattern = '') {
	if (!zfsmValidDatasetName($zdataset)) return false;
	$result = runProcess('zfs', array('list', '-H', '-r', '-d', '1', '-t', 'filesystem,volume', '-o', 'name', $zdataset));
	if (($result['code'] ?? 1) !== 0) return false;
	foreach (preg_split('/\R/', (string)($result['output'] ?? '')) ?: array() as $line) {
		$child = explode("\t", $line, 2)[0];
		if (zfsmStartsWith($child, $zdataset.'/') && !zfsmDatasetExcluded($child, $exclusion_pattern)) return true;
	}
	return false;
}

function zfsmAllowedDatasetProperties() {
	return array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync');
}

function zfsmSafePropertyValue($property, $value) {
	if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) return false;
	if ($property === 'mountpoint') return in_array($value, array('none', 'legacy'), true) || ($value !== '' && $value[0] === '/');
	return $value !== '';
}

// `zfs create` arguments for the dataset and its options; a string naming the refused option instead.
function zfsmCreateArgs($zdataset, $zoptions) {
	$allowed = array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync', 'encryption', 'keylocation', 'keyformat');
	$args = array('create');
	foreach ($zoptions as $property => $value) {
		if (!in_array($property, $allowed, true) || !zfsmSafePropertyValue($property, (string)$value)) return 'Invalid dataset property: '.$property;
		$args[] = '-o';
		$args[] = $property.'='.$value;
	}
	$args[] = $zdataset;
	return $args;
}

// The datasets on the way to $zdataset that don't exist yet, top first; a string saying which one couldn't be checked instead.
function zfsmDatasetsToCreate($zdataset) {
	$parts = explode('/', $zdataset);
	$missing_names = array();
	for ($depth = 2; $depth <= count($parts); $depth++) {
		$name = implode('/', array_slice($parts, 0, $depth));
		$missing = zfsmDatasetMissing($name);
		if ($missing === null) return 'Could not check whether '.$name.' exists, so nothing was created.';
		if ($missing) $missing_names[] = $name;
	}
	return $missing_names;
}

// Creates each name in turn, $zdataset with its options, and stops at the first failure: [the last zfs result, the names created]
function zfsmCreateInTurn($names, $zdataset, $args, $stdin) {
	$result = array();
	$owned = array();
	foreach ($names as $name) {
		$result = $name === $zdataset ? runProcess('zfs', $args, $stdin) : runProcess('zfs', array('create', $name));
		if (($result['code'] ?? 1) !== 0) break;
		$owned[] = $name;
	}
	return array($result, $owned);
}

// What could not be finished once the dataset existed: the share owner, then read-only.
function zfsmCreateProblems($zdataset, $unowned, $readonly) {
	$problems = $unowned ? array('the share owner could not be set on '.implode(', ', $unowned)) : array();
	if ($readonly) {
		$set = runProcess('zfs', array('set', 'readonly=on', $zdataset));
		if (($set['code'] ?? 1) !== 0) $problems[] = 'it could not be made read-only: '.zfsmCommandError($set, 'zfs set failed');
	}
	return $problems;
}

function createDataset($zdataset, $zoptions) {
	if (!zfsmValidDatasetName($zdataset)) return zfsmFailure($zdataset, 'Invalid dataset name');
	$pool = explode('/', $zdataset, 2)[0];
	$passphrase = (string)($zoptions['passphrase'] ?? '');
	unset($zoptions['passphrase']);
	// Read-only is set last, or the share owner below could not be applied.
	$readonly = ($zoptions['readonly'] ?? '') === 'on';
	if ($readonly) unset($zoptions['readonly']);
	$args = zfsmCreateArgs($zdataset, $zoptions);
	if (is_string($args)) return zfsmFailure($zdataset, $args);
	$encrypted = ($zoptions['encryption'] ?? 'off') === 'on';
	if ($encrypted && strlen($passphrase) < 8) return zfsmFailure($zdataset, 'Enter a passphrase of at least 8 characters, or set Encryption to No.');
	$created = zfsmDatasetsToCreate($zdataset);
	if (is_string($created)) return zfsmFailure($zdataset, $created);
	if (end($created) !== $zdataset) return zfsmFailure($zdataset, 'A dataset with this name already exists.');
	// No -p: it succeeds on a dataset someone else just made, and only ours may get the share owner.
	list($result, $owned) = zfsmCreateInTurn($created, $zdataset, $args, $encrypted ? $passphrase."\n".$passphrase."\n" : null);
	$answer = commandAnswer($zdataset, $result);
	$unowned = zfsmSetShareOwner($pool, $owned);
	if (($result['code'] ?? 1) !== 0) return $answer;
	$problems = zfsmCreateProblems($zdataset, $unowned, $readonly);
	return $problems ? zfsmFailure($zdataset, 'Created, but '.implode('; ', $problems)) : $answer;
}

// Returns the datasets whose mountpoint could not be given to nobody:users.
function zfsmSetShareOwner($pool, $names) {
	$failed = array();
	foreach ($names as $name) {
		$mountpoint = getDatasetProperty($pool, $name, 'mountpoint');
		if ($mountpoint === 'none' || $mountpoint === 'legacy') continue;
		if (!is_string($mountpoint) || $mountpoint === '' || $mountpoint[0] !== '/' || !@chown($mountpoint, 'nobody') || !@chgrp($mountpoint, 'users')) $failed[] = $name;
	}
	return $failed;
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
	$args = array('destroy');
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
		$mount = zfsmUnescapeMount(explode(' ', $line)[4] ?? '');
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
	if ((string)$force === '1') return commandAnswer($resolved['path'], runProcess('rm', array('-rf', '--one-file-system', '--', $resolved['path']), null, 0, ZFSM_KEEP_OUTPUT));
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
	return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed_code;
}

// Empty only when a checksum comparison of the two trees finds nothing to change.
function zfsmCopyDifferences($rsync_args, $from, $to) {
	$args = array_merge($rsync_args, array('--checksum', '--dry-run', '--delete', '--itemize-changes', rtrim($from, '/').'/', rtrim($to, '/').'/'));
	// A copy that went badly wrong differs in every file: count them, keep the first.
	$result = runProcess('rsync', $args, null, 0, ZFSM_KEEP_OUTPUT);
	if (($result['code'] ?? 1) !== 0) return 'rsync exit code '.($result['code'] ?? 1);
	$first = preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), 2)[0];
	return ($result['lines'] ?? 0) > 0 ? $result['lines'].' differing, first: '.$first : '';
}

// [the directory, the dataset it becomes] when it can be converted within $pool; a string saying why not otherwise.
function zfsmConvertTarget($directory, $pool) {
	if (!zfsmValidPoolName($pool)) return 'Invalid pool name';
	$source = zfsmResolveDirectory($directory, true);
	if (!$source || explode('/', $source['dataset'], 2)[0] !== $pool) return 'Directory is not inside the selected pool';
	if (dirname($source['path']) !== $source['mountpoint']) return 'Only a direct child directory of a dataset mountpoint can be converted';
	$relative = ltrim(substr($source['path'], strlen($source['mountpoint'])), '/');
	$dataset_name = $source['dataset'].($relative !== '' ? '/'.$relative : '');
	if (!zfsmValidDatasetName($dataset_name) || zfsmDatasetExists($dataset_name)) return 'Target dataset name is invalid or already exists';
	$mounted = zfsmNestedMountProblem($source['path']);
	if ($mounted !== '') return $mounted;
	if (file_exists($source['path'].'/.zfs')) return 'A top-level .zfs entry cannot exist in a dataset; rename it first';
	return array($source['path'], $dataset_name);
}

// Undoes a conversion whose new dataset has no usable mountpoint, and says whether the directory is back.
function zfsmConvertUndo($dataset_name, $temp, $path) {
	$cleanup = runProcess('zfs', array('destroy', $dataset_name));
	if (($cleanup['code'] ?? 1) === 0 && moveDirectory($temp, $path)['succeeded']) return 'Dataset mountpoint was unavailable; the new dataset was removed and the original directory was restored';
	return 'Dataset mountpoint is unavailable; original data remains at '.$temp.' and manual recovery is required';
}

// Why files can't be cloned into $dataset: '' when they can, null when that can't be read. OpenZFS 2.2+ with cloning
// switched on and the pool's feature enabled; never encrypted, as each encrypted dataset has its own key and ZFS won't clone between keys.
function zfsmCloneBlocker($dataset) {
	$pool = explode('/', $dataset, 2)[0];
	$switch = @file_get_contents($GLOBALS['zfsm_bclone_param'] ?? '/sys/module/zfs/parameters/zfs_bclone_enabled');
	if ($switch === false) return 'no feature';
	if (trim($switch) !== '1') return 'switched off';
	$feature = zfsmPoolFeature($pool, 'block_cloning');
	if ($feature === null) return null;
	if (!in_array($feature, array('enabled', 'active'), true)) return 'no feature';
	$encryption = getDatasetProperty($pool, $dataset, 'encryption');
	if ($encryption === null) return null;
	return $encryption === 'off' ? '' : 'encrypted';
}

function zfsmBlockCloning($dataset) {
	return zfsmCloneBlocker($dataset) === '';
}

// What the Convert dialog says first: whether the files will be cloned, and the first Unraid that still imports the pool read-write after.
function zfsmConvertPreview($directory, $pool) {
	$target = zfsmConvertTarget($directory, $pool);
	if (!is_array($target)) return array('can_proceed' => false, 'error' => $target);
	$unread = array('can_proceed' => false, 'error' => 'Whether the files can be cloned could not be read');
	// The dataset doesn't exist yet; it takes its parent's encryption, which decides whether its files can be cloned.
	$blocker = zfsmCloneBlocker(substr($target[1], 0, strrpos($target[1], '/')));
	if ($blocker === null) return $unread;
	$turns_on = $blocker === '' ? zfsmCloneTurnsOn($pool) : '';
	if ($turns_on === null) return $unread;
	return array('can_proceed' => true, 'clones' => $blocker === '', 'blocker' => $blocker, 'turns_on' => $turns_on);
}

// Clones the files into the dataset, sharing their blocks; rsync then copies whatever this missed, so a failure here only costs space.
function zfsmCloneTree($from, $to, $dataset_name) {
	zfsmCopyProgress('clone_directory', array('dataset' => $dataset_name));
	$result = runProcess('cp', array('-a', '--reflink=auto', '--one-file-system', '--', rtrim($from, '/').'/.', rtrim($to, '/').'/'), null, 0, ZFSM_KEEP_OUTPUT);
	if (($result['code'] ?? 1) !== 0) zfsmLog('Convert: cloning '.$from.' failed, so rsync copies it: '.zfsmCommandError($result, 'cp failed'), LOG_WARNING);
}

// Copies the moved-aside directory into the dataset, verifies the copy, then removes the original; '' when all of it worked.
// $clone is the review's answer: the convert never clones where its review said the files would be copied.
function zfsmConvertCopy($temp, $mountpoint, $dataset_name, $clone = true) {
	$retained = 'original data remains at '.$temp.' and the partial dataset was retained for inspection';
	$rsync_args = zfsmRsyncArgs();
	if ($rsync_args === null) return 'Unable to run rsync; '.$retained;
	zfsmCopyProgress('start_directory_copy', array('dataset' => $dataset_name));
	if ($clone && zfsmBlockCloning($dataset_name)) zfsmCloneTree($temp, $mountpoint, $dataset_name);
	$exit_code = zfsmCopyWithProgress($rsync_args, $temp, $mountpoint, $dataset_name);
	zfsmCopyProgress('stop_directory_copy', array('dataset' => $dataset_name));
	if ($exit_code !== 0) return 'rsync failed with exit code '.$exit_code.'; '.$retained;
	$differences = zfsmCopyDifferences($rsync_args, $temp, $mountpoint);
	if ($differences !== '') return 'The copy did not verify ('.$differences.'); '.$retained;
	$deleted = deleteDirectory($temp, 1);
	if (!$deleted['succeeded']) return 'Copy completed, but temporary source could not be removed: '.$temp;
	return '';
}

function convertDirectory($directory, $pool, $clone = true) {
	$target = zfsmConvertTarget($directory, $pool);
	if (is_string($target)) return zfsmFailure($directory, $target);
	list($path, $dataset_name) = $target;
	$temp = $path.'_zfsm_tmp_'.date('YmdHis');
	$moved = moveDirectory($path, $temp);
	if (!$moved['succeeded']) return $moved;
	$created = createDataset($dataset_name, array());
	if (!$created['succeeded']) {
		if (moveDirectory($temp, $path)['succeeded']) return $created;
		return zfsmFailure($directory, implode('; ', $created['failed']).'; the directory could not be moved back, so its data remains at '.$temp.' and manual recovery is required');
	}
	$mountpoint = getDatasetProperty($pool, $dataset_name, 'mountpoint');
	if (!is_string($mountpoint) || !is_dir($mountpoint)) return zfsmFailure($directory, zfsmConvertUndo($dataset_name, $temp, $path));
	$copy = zfsmConvertCopy($temp, $mountpoint, $dataset_name, $clone);
	if ($copy !== '') return zfsmFailure($directory, $copy);
	$ret = buildArrayRet();
	$ret['succeeded'][$directory] = 0;
	return $ret;
}

// A new snapshot's name: the prefix, then the date in local time or, when set, UTC.
function zfsmNewSnapshotName($cfg, $now) {
	$pattern = $cfg['snap_pattern'] ?? 'Y-m-d-His';
	return ($cfg['snap_prefix'] ?? '').((int)($cfg['snap_utc'] ?? 0) === 1 ? gmdate($pattern, $now) : date($pattern, $now));
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

// One snapshot, or several of one dataset as ds@a,b, which ZFS destroys all or nothing.
function deleteDatasetSnapshot($snapshot, $recursive = 0) {
	$list = strpos((string)$snapshot, ',') !== false;
	if ($list ? strpos($snapshot, '@') === false || !zfsmValidDestroyTarget($snapshot) : !zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	$args = array('destroy');
	if ((string)$recursive === '1') $args[] = '-r';
	$args[] = $snapshot;
	return commandAnswer($snapshot, runProcess('zfs', $args));
}

?>
