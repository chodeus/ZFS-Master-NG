<?php
// Diagnostics collectors for the pools (include/ZFSMDiagnostics.php lists them): reads only, one pool at a time,
// so a hung pool costs its own part of a file and no more.

// The imported pools as getZFSPools gives them, read once per run under a limit of its own. When the list can't be read this
// throws what the read threw, at once from then on: a timeout stays one, so each file that needed the list says timed out.
function zfsmDiagPools() {
	$diag = &$GLOBALS['zfsm_diag'];
	if (!isset($diag['pools'])) {
		$began = microtime(true);
		try {
			$diag['pools'] = zfsmWithDeadline(zfsmDiagSeconds(30), function() { return getZFSPools(true); });
		} catch (RuntimeException $error) {
			$diag['pools'] = $error;
		}
		$result = is_array($diag['pools']) ? 'exit 0' : ($diag['pools'] instanceof ZFSMTimeout ? 'timed out' : 'failed');
		$diag['timings'][] = number_format(microtime(true) - $began, 2, '.', '')."\t".$result."\tzpool list -H -o name,size,alloc,free,health";
	}
	if (is_array($diag['pools'])) return $diag['pools'];
	if ($diag['pools'] instanceof ZFSMTimeout) $diag['timed_out'] = true;
	else zfsmDiagUnread();
	throw $diag['pools'];
}

// One pool's read. Once zfs (or zpool) timed out on a pool, its later reads of that pool are skipped: each would wait its whole limit again.
// The other command still runs: zpool status is what explains a pool that zfs hangs on.
function zfsmDiagPoolRead($pool, $command, $args, $seconds = 30) {
	$hung = &$GLOBALS['zfsm_diag']['hung'][$command.' '.$pool];
	if (isset($hung)) {
		$GLOBALS['zfsm_diag']['timed_out'] = true;
		return array('code' => null, 'output' => 'skipped, because '.$hung.' timed out on this pool');
	}
	// Only a read that was given its whole limit says the pool hangs: one its collector's limit cuts short was not given the time,
	// however long its command then takes to end.
	$whole = zfsmDeadlineLeft() >= zfsmDiagSeconds($seconds);
	$result = zfsmDiagRead($command, $args, $seconds);
	if ($result['code'] === null && $whole) $hung = $command.' '.$args[0];
	return $result;
}

// The same read of every pool, the pool's name last, as title => result.
function zfsmDiagPerPool($command, $args, $seconds = 30) {
	$results = array();
	foreach (array_keys(zfsmDiagPools()) as $pool) $results[$command.' '.implode(' ', $args).' '.$pool] = zfsmDiagPoolRead($pool, $command, array_merge($args, array($pool)), $seconds);
	return $results;
}

function zfsmDiagShowAll($results) {
	$text = '';
	foreach ($results as $title => $result) $text .= zfsmDiagShow($title, $result);
	return $text !== '' ? $text : "(no pools)\n";
}

// Every line the pools' reads gave; null when one failed, because a failed read is not an empty pool.
function zfsmDiagAllRows($results) {
	$rows = array();
	foreach ($results as $result) {
		if ($result['code'] !== 0) return null;
		$rows = array_merge($rows, zfsmDiagLines($result['output']));
	}
	foreach ($rows as $row) zfsmDiagKnow(explode("\t", $row, 2)[0]);
	return $rows;
}

// A read's lines, split only at a line feed: a value in zfs's rows and a path in mountinfo can hold a carriage return.
function zfsmDiagLines($text) {
	return preg_split('/\n/', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
}

// Every dataset's name, those the exclusion pattern hides too; null when they can't all be listed.
function zfsmDiagDatasetNames() {
	try {
		$rows = zfsmDiagAllRows(zfsmDiagPerPool('zfs', array('list', '-H', '-o', 'name', '-t', 'filesystem,volume', '-r')));
	} catch (RuntimeException $error) {
		return null;
	}
	return $rows === null ? null : array_map(function($row) { return explode("\t", $row, 2)[0]; }, $rows);
}

function zfsmDiagZfsVersions($ctx) {
	$version = zfsmDiagRead('zfs', array('version'));
	zfsmDiagFact('OpenZFS', $version['code'] === 0 ? zfsmOneLine($version['output']) : 'not read');
	return zfsmDiagShow('zfs version', $version)
		.zfsmDiagSection('loaded module', zfsmDiagFile(zfsmStatePath('/sys/module/zfs/version')))
		.zfsmDiagSection('kernel', php_uname('r'));
}

function zfsmDiagZpoolList($ctx) {
	$unhealthy = array();
	foreach (zfsmDiagPools() as $name => $pool) if ($pool['Health'] !== 'ONLINE') $unhealthy[] = $name.' is '.$pool['Health'];
	zfsmDiagFact('pools', count(zfsmDiagPools()).($unhealthy ? ' ('.implode(', ', $unhealthy).')' : ', all ONLINE'));
	if ($unhealthy) zfsmDiagFinding('a pool is not ONLINE: '.implode(', ', $unhealthy));
	return zfsmDiagShow('zpool list -v', zfsmDiagRead('zpool', array('list', '-v')));
}

// Without -v: that lists the damaged files by name.
function zfsmDiagZpoolStatus($ctx) {
	return zfsmDiagShowAll(zfsmDiagPerPool('zpool', array('status', '-p')));
}

// A pool's comment, compatibility files and user properties are what its owner wrote (zfsmDiagPropertyRows).
function zfsmDiagZpoolGetAll($ctx) {
	return zfsmDiagPropertyRows('zpool', array('get', '-H', '-o'), 'property');
}

// Every property the Main tab reads, and the ones that say why a dataset is not where it should be.
function zfsmDiagDatasets($ctx) {
	$columns = array_merge(zfsmDatasetColumns(), array('guid', 'mounted', 'canmount', 'volmode', 'encryptionroot'));
	$results = zfsmDiagPerPool('zfs', array('list', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', implode(',', $columns)));
	$rows = zfsmDiagAllRows($results);
	zfsmDiagFact('datasets', $rows === null ? 'not read on every pool' : count($rows));
	return implode("\t", $columns)."\n\n".zfsmDiagShowAll($results);
}

// Per dataset: how many snapshots, the oldest and the newest, and how many are held.
function zfsmDiagSnapshots($ctx) {
	$text = "dataset\tsnapshots\toldest\tnewest\theld\n";
	$total = 0;
	$unread = false;
	foreach (zfsmDiagPerPool('zfs', array('list', '-H', '-p', '-r', '-t', 'snapshot', '-o', 'name,creation,userrefs'), 120) as $title => $result) {
		if ($result['code'] !== 0) {
			$text .= zfsmDiagShow($title, $result);
			$unread = true;
			continue;
		}
		foreach (zfsmDiagSnapshotCounts($result['output']) as $dataset => $count) {
			$text .= $dataset."\t".$count[0]."\t".date('Y-m-d H:i', $count[1])."\t".date('Y-m-d H:i', $count[2])."\t".$count[3]."\n";
			$total += $count[0];
		}
	}
	zfsmDiagFact('snapshots', $unread ? 'not read on every pool' : $total);
	return $text;
}

// dataset => [snapshots, oldest creation, newest creation, held], a line at a time: a pool can hold 100,000 snapshots.
function zfsmDiagSnapshotCounts($output) {
	$counts = array();
	for ($line = strtok($output, "\n"); $line !== false; $line = strtok("\n")) {
		$field = explode("\t", $line);
		if (count($field) < 3 || strpos($field[0], '@') === false) continue;
		if (strpos($field[0], ' ') !== false) zfsmDiagKnow($field[0]);
		$dataset = explode('@', $field[0], 2)[0];
		$count = $counts[$dataset] ?? array(0, PHP_INT_MAX, 0, 0);
		$counts[$dataset] = array($count[0] + 1, min($count[1], (int)$field[1]), max($count[2], (int)$field[1]), $count[3] + ((int)$field[2] > 0 ? 1 : 0));
	}
	return $counts;
}

// Replication's marks: its bookmarks, and the datasets holding part of a copy. A resume token names its snapshot, so only its dataset goes in.
function zfsmDiagBookmarks($ctx) {
	$text = zfsmDiagShowAll(zfsmDiagPerPool('zfs', array('list', '-H', '-p', '-r', '-t', 'bookmark', '-o', 'name,guid,createtxg,creation')));
	$partial = array();
	$unread = false;
	foreach (zfsmDiagPerPool('zfs', array('get', '-H', '-r', '-t', 'filesystem,volume', '-o', 'name,value', 'receive_resume_token')) as $title => $result) {
		if ($result['code'] !== 0) {
			$text .= zfsmDiagShow($title, $result);
			$unread = true;
			continue;
		}
		foreach (zfsmImpactRows($result['output']) as $row) {
			$field = explode("\t", $row);
			if (count($field) === 2 && $field[1] !== '-') $partial[] = $field[0];
		}
	}
	if ($unread) $partial[] = '(not read on every pool)';
	return $text.zfsmDiagSection('datasets holding part of a received copy', $partial ? implode("\n", $partial) : '(none)');
}

// Properties set on a dataset itself or received with it: ZnapZend's plans and other tools' marks are among them.
function zfsmDiagUserProperties($ctx) {
	return zfsmDiagPropertyRows('zfs', array('get', '-H', '-p', '-r', '-t', 'filesystem,volume', '-s', 'local,received', '-o'), 'name,property');
}

// zfs get or zpool get on each pool, each value its owner wrote hidden. A value can hold tabs and line breaks, so the same read
// with only the $head columns says where each row starts (zfsmDiagFreeRows). A read that failed holds its error, not rows.
function zfsmDiagPropertyRows($command, $args, $head) {
	$results = zfsmDiagPerPool($command, array_merge($args, array($head.',value,source', 'all')));
	$heads = array_values(zfsmDiagPerPool($command, array_merge($args, array($head, 'all'))));
	foreach (array_keys($results) as $at => $title) {
		if ($results[$title]['code'] !== 0) continue;
		$results[$title] = $heads[$at]['code'] === 0 ? array('code' => 0, 'output' => zfsmDiagFreeRows($results[$title]['output'], $heads[$at]['output'])) : $heads[$at];
	}
	return zfsmDiagShowAll($results);
}

// The rows of $output: the $heads, each with its value and source after it. Only when every line of one is a row of the other is it
// known which text is a value. If not, a value holds a line break or a property changed between the reads, and no value is shown.
function zfsmDiagFreeRows($output, $heads) {
	$lines = zfsmDiagLines($output);
	$heads = zfsmDiagLines($heads);
	$exact = count($lines) === count($heads);
	$rows = array();
	foreach ($exact ? $heads : array() as $at => $head) {
		$rest = strncmp($lines[$at], $head."\t", strlen($head) + 1) === 0 ? substr($lines[$at], strlen($head) + 1) : '';
		// The source is the last field: it holds no tab, and the value before it may.
		$cut = strrpos($rest, "\t");
		if ($cut === false) $exact = false;
		else $rows[] = zfsmDiagFreeRow($head, substr($rest, 0, $cut), substr($rest, $cut + 1));
	}
	if (!$exact) {
		$rows = array('(a value here holds a line break, or a property changed while they were read: the values are left out)');
		foreach ($heads as $head) $rows[] = zfsmDiagRowHead($head)[0];
	}
	return $rows ? implode("\n", $rows)."\n" : '';
}

// [a row's head as it is shown, its property, whether that is a user property: its name holds a colon (zfsmDiagPropertyName)].
// A pool's feature@... and unsupported@... are OpenZFS's own, colon or not.
function zfsmDiagRowHead($head) {
	$at = strrpos("\t".$head, "\t");
	$property = substr($head, $at);
	$user = strpos($property, ':') !== false && strpos($property, '@') === false;
	return array(substr($head, 0, $at).($user ? zfsmDiagPropertyName($property) : $property), $property, $user);
}

// The properties of OpenZFS's whose value is text its owner writes: a share's options, a pool's comment and compatibility files, SELinux labels.
function zfsmDiagOwnersProperties() {
	return array('sharenfs', 'sharesmb', 'comment', 'compatibility', 'context', 'fscontext', 'defcontext', 'rootcontext', 'mlslabel');
}

// One row with its value hidden when it is its owner's (zfsmDiagFreeValue): a user property's, one of zfsmDiagOwnersProperties() but for
// OpenZFS's own none and legacy, or a value with a space or a tab that is no path (a path goes to the path rules). keylocation: OpenZFS's
// own prompt, none and -, any other only as set.
function zfsmDiagFreeRow($head, $value, $source) {
	list($shown, $property, $user) = zfsmDiagRowHead($head);
	$owners = in_array($property, zfsmDiagOwnersProperties(), true) && !in_array($value, array('none', 'legacy'), true);
	if ($property === 'keylocation') $value = in_array($value, array('prompt', 'none', '-'), true) ? $value : '(set)';
	elseif ($user || $owners || preg_match('~^(?:\S+|/[^\t]*)$~D', $value) !== 1) $value = zfsmDiagFreeValue($value);
	return $shown."\t".$value."\t".$source;
}

// The table's fields end at a space, and it writes a space inside one as \040: here a tab ends each field and the space is itself,
// so a name is whole in its field.
function zfsmDiagMounts($ctx) {
	$list = zfsmDiagWhole('file_get_contents', zfsmStatePath('/proc/self/mountinfo'));
	$mounts = array();
	foreach (zfsmDiagLines($list) as $line) {
		if (preg_match('/ - zfs (\S+)/', $line, $source) !== 1) continue;
		zfsmDiagKnow(str_replace('\\040', ' ', $source[1]));
		$mounts[] = str_replace(array(' ', '\\040'), array("\t", ' '), $line);
	}
	$unread = '(the mount list could not be read)';
	$boot = zfsmBootPool(zfsmStatePath('/proc/self/mounts'));
	return zfsmDiagSection('pool mounted at /boot', $boot === null ? zfsmDiagUnread($unread) : ($boot !== '' ? $boot : '(none)'))
		.zfsmDiagSection('zfs mounts in /proc/self/mountinfo', $list === false ? zfsmDiagUnread($unread) : ($mounts ? implode("\n", $mounts) : '(none)'));
}

// zfs, zpool and rsync commands and the plugin's scripts, by the command's own name: another command's arguments can hold a password.
// rsync's arguments, and a zfs or zpool command's that say where a key is kept, are left out. A list or get still running after 10 minutes is stuck.
function zfsmDiagZfsProcesses($ctx) {
	$processes = zfsmDiagProcesses('#^(\S*/)?(zfs|zpool|rsync)(\s|$)|'.zfsmDiagScriptPattern().'#');
	if ($processes === null) return '(the process list could not be read)';
	$text = "pid\tseconds\tcommand\n";
	$stuck = 0;
	foreach ($processes as $process) {
		if (preg_match('#^(\S*/)?(rsync|zfs\s+load-key)(\s|$)|^(\S*/)?((?:zfs|zpool)\s+\S+)\s.*keylocation=#', $process[2], $match) === 1) $process[2] = ($match[5] ?? $match[2]).' (its arguments are left out)';
		$text .= implode("\t", $process)."\n";
		if ($process[1] > 600 && preg_match('#^(\S*/)?(zfs (list|get)|zpool (list|status|get))(\s|$)#', $process[2]) === 1) $stuck++;
	}
	if ($stuck > 0) zfsmDiagFinding($stuck.' zfs or zpool read(s) have been running for more than 10 minutes');
	return $text;
}

function zfsmDiagTimings($ctx) {
	return "seconds\tresult\tcommand\n".implode("\n", $GLOBALS['zfsm_diag']['timings']);
}
