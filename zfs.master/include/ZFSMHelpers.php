<?php
require_once __DIR__.'/ZFSMLog.php';
require_once __DIR__.'/ZFSMKeepRules.php';

// A general setting as an integer, or $default when it isn't there.
function zfsmSettingInt($general, $key, $default) {
	return isset($general[$key]) ? intval($general[$key]) : $default;
}

// $value as a number of up to $digits digits, else $default: zfsmPostDigits (ZFSMAdmin.php) and zfsmKeepParse read counts so.
function zfsmDigits($value, $digits, $default) {
	return is_string($value) && preg_match('/^[0-9]{1,'.$digits.'}$/D', $value) === 1 ? (int)$value : $default;
}

// A general setting as saved, or $default when it isn't there or equals (==) one of the $blank values.
function zfsmSettingText($general, $key, $default, $blank = array('')) {
	if (!isset($general[$key])) return $default;
	foreach ($blank as $value) if ($general[$key] == $value) return $default;
	return $general[$key];
}

function loadConfig($config) {
	$general = is_array($config) && is_array($config['general'] ?? null) ? $config['general'] : array();
	// The setting was first saved under a misspelt name; that one still counts.
	if (isset($general['exclussion']) && $general['exclussion'] != '' && !isset($general['exclusion'])) $general['exclusion'] = $general['exclussion'];
	return array(
		'refresh_interval' => zfsmSettingInt($general, 'refresh_interval', 30),
		'lazy_load' => zfsmSettingInt($general, 'lazy_load', 1),
		'znapzend_data' => zfsmSettingInt($general, 'znapzend_data', "0"),
		'destructive_mode' => zfsmSettingInt($general, 'destructive_mode', 0),
		'dataset_exclusion' => zfsmSettingText($general, 'exclusion', '', array('', ' ')),
		'snap_max_days_alert' => zfsmSettingInt($general, 'snap_max_days_alert', 30),
		'snap_prefix' => $general['snap_prefix'] ?? '',
		'snap_pattern' => zfsmSettingText($general, 'snap_pattern', 'Y-m-d-His'),
		'snap_utc' => zfsmSettingInt($general, 'snap_utc', 0),
		'alert_interval' => zfsmSettingInt($general, 'alert_interval', 0),
		'alert_stale' => zfsmSettingInt($general, 'alert_stale', 0),
		'size_units' => ($general['size_units'] ?? '') === 'decimal' ? 'decimal' : 'binary',
		'debug_log' => zfsmSettingInt($general, 'debug_log', 0),
		'replica_accept' => trim((string)zfsmSettingText($general, 'replica_accept', '')),
		// As Snapshots to keep reads it; anything else keeps all (0), as versions before rules read rules too.
		'replica_keep' => zfsmKeepParse((string)($general['replica_keep'] ?? '')) ?? 0,
		'directory_listing' => isset($general['directory_listing']) && $general['directory_listing'] != '' ? preg_split('/\r\n|\r|\n/', $general['directory_listing']) : array(),
	);
}

// Whether Unraid's notify script took the notification.
function zfsnotify( $subject, $description, $message, $type="normal") {
	$notify = ($GLOBALS["docroot"] ?? '/usr/local/emhttp').'/plugins/dynamix/scripts/notify';
	return (runProcess($notify, array('-e', 'ZFS Master NG', '-s', $subject, '-d', $description, '-m', (string)$message, '-i', $type))['code'] ?? 1) === 0;
}

// Plain text: notification agents such as Discord and email show HTML tags as typed.
function zfsmNotifyAnswer($title, $answer, $success_text, $failed_text) {
	if ($answer['succeeded']) zfsnotify($title, $success_text.': '.implode(', ', array_keys($answer['succeeded'])), '', 'normal');
	if ($answer['failed']) zfsnotify($title, $failed_text.': '.implodeWithKeys('; ', $answer['failed']), '', 'warning');
}

function fromBytesToString($bytes) {
	$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'); 

	$bytes = is_numeric($bytes) ? max((float)$bytes, 0) : 0;
   	$pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
   	$pow = min($pow, count($units) - 1); 

   	$bytes /= pow(1024, $pow);

   return round($bytes, 1) . ' ' . $units[$pow];
}

// Sizes in the units Settings picked: ZFS's (powers of 1024) or Unraid's my_scale at base 1000; frontend/ZFSMSize.js mirrors it.
function zfsmSize($bytes, $units, $display = array(), $decimals = null) {
	if ($units !== 'decimal') return fromBytesToString($bytes);
	$prefixes = array('', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y');
	$scale = (int)($display['scale'] ?? -1);
	$number = (string)($display['number'] ?? '.,');
	$value = is_numeric($bytes) ? max((float)$bytes, 0) : 0;
	if ($scale == 0 && ($decimals === null || $decimals < 0)) return zfsmNumberFormat($value, 0, $number);
	$base = $value ? (int)floor(log($value, 1000)) : 0;
	if ($scale > 0 && $base > $scale) $base = $scale;
	$base = min($base, count($prefixes) - 1);
	$value /= pow(1000, $base);
	$places = zfsmUnraidDecimals($value, $decimals);
	if ($scale < 0 && round($value, -1) == 1000) { $value = 1; $base++; }
	return zfsmNumberFormat($value, $places, $number).' '.$prefixes[$base].'B';
}

// my_scale's decimals: none from 100 up, one from 10, else two unless whole; a negative count means at most that many.
function zfsmUnraidDecimals($value, $decimals) {
	if ($decimals === null) return $value >= 100 ? 0 : ($value >= 10 ? 1 : ((int)round($value * 100) % 100 === 0 ? 0 : 2));
	if ($decimals < 0) return $value >= 100 || (int)round($value * 10) % 10 === 0 ? 0 : -$decimals;
	return $decimals;
}

// number_format with Unraid's separators: the thousands one only from 10,000 up, as my_scale passes it.
function zfsmNumberFormat($value, $decimals, $number) {
	return number_format($value, $decimals, substr($number, 0, 1), $value > 9999 ? substr($number, 1, 1) : '');
}
	  
function implodeWithKeys($glue, $array, $symbol = ': ') {
	return implode( $glue, array_map( function($k, $v) use($symbol) {
			return $k . $symbol . $v;
		},
		array_keys($array),
		array_values($array))
	);
}
	
// The command to run: a test's own copy from ZFSM_TEST_BIN_DIR when it has one. Null for a zfs or zpool
// the test lacks, so a test never reaches the host's.
function zfsmCommandPath($command) {
	$test_bin_dir = getenv('ZFSM_TEST_BIN_DIR');
	if ($test_bin_dir === false || $test_bin_dir === '') return $command;
	$candidate = rtrim($test_bin_dir, '/').'/'.basename($command);
	if (is_file($candidate)) return $candidate;
	return in_array(basename($command), array('zfs', 'zpool'), true) ? null : $command;
}

// $tool (zstd, prlimit) on this server, or null; in a test, only ZFSM_TEST_BIN_DIR's. A run started from a page has no PATH
// (php-fpm's clear_env), so that falls back to execvp's own.
function zfsmToolPath($tool) {
	$test_bin_dir = (string)getenv('ZFSM_TEST_BIN_DIR');
	$path = (string)getenv('PATH');
	foreach (explode(':', $test_bin_dir !== '' ? $test_bin_dir : ($path !== '' ? $path : '/bin:/usr/bin')) as $dir) {
		$found = rtrim($dir, '/').'/'.$tool;
		if ($dir !== '' && is_file($found) && is_executable($found)) return $found;
	}
	return null;
}

// A read that ran out of its time limit: still a RuntimeException to every catch that knows it as one.
class ZFSMTimeout extends RuntimeException {}

// Runs $read with every runProcess in it under one time limit; a sooner limit already set stays, and the old one comes back after.
function zfsmWithDeadline($seconds, $read) {
	$previous = $GLOBALS['zfsm_inventory_deadline'] ?? null;
	$GLOBALS['zfsm_inventory_deadline'] = min($previous ?? INF, microtime(true) + $seconds);
	try {
		return $read();
	} finally {
		if ($previous === null) unset($GLOBALS['zfsm_inventory_deadline']);
		else $GLOBALS['zfsm_inventory_deadline'] = $previous;
	}
}

// Runs $run with no deadline: a quick local step that ends what a read past its deadline left running.
function zfsmWithoutDeadline($run) {
	$previous = $GLOBALS['zfsm_inventory_deadline'] ?? null;
	unset($GLOBALS['zfsm_inventory_deadline']);
	try {
		return $run();
	} finally {
		if ($previous !== null) $GLOBALS['zfsm_inventory_deadline'] = $previous;
	}
}

// The seconds left of the limit a read now runs under; INF when it runs under none.
function zfsmDeadlineLeft() {
	return ($GLOBALS['zfsm_inventory_deadline'] ?? INF) - microtime(true);
}

// runProcess under a time limit of its own, never throwing: a read past it comes back with code null and why,
// one that could not be started with runProcess's code for that.
function zfsmReadWithin($seconds, $command, $args) {
	try {
		return zfsmWithDeadline($seconds, function() use ($command, $args) { return runProcess($command, $args); });
	} catch (RuntimeException $error) {
		return array('code' => $error instanceof ZFSMTimeout ? null : ZFSM_ERR_UNABLE_TO_CREATE_PROC, 'output' => $error->getMessage());
	}
}

// How much a command that can print a line per file keeps of its output: enough for its first errors.
define('ZFSM_KEEP_OUTPUT', 4096);

// With Debug Log on, each command run through here is a line in the debug log (zfsmDebugCommand). The long jobs stream
// their commands outside it and log their own lines.
function runProcess($command, $args = array(), $stdin = null, $max_lines = 0, $keep_bytes = 0) {
	$began = microtime(true);
	$result = 'not run';
	try {
		$answer = zfsmRunCommand($command, $args, $stdin, $max_lines, $keep_bytes);
		$result = 'exit '.$answer['code'];
		return $answer;
	} catch (ZFSMTimeout $timeout) {
		$result = 'timed out';
		throw $timeout;
	} catch (Throwable $error) {
		$result = 'failed: '.$error->getMessage();
		throw $error;
	} finally {
		zfsmDebugCommand($command, $args, $result, $began);
	}
}

// With $max_lines, stops the command after that many lines; when it had more, sets 'truncated' and the line after them, 'next_line'.
// With $keep_bytes, lets the command run to its end but keeps only that much of its output, and counts its lines in 'lines'.
function zfsmRunCommand($command, $args, $stdin, $max_lines, $keep_bytes) {
	$command = zfsmCommandPath($command);
	if ($command === null) return array('code' => 127, 'output' => 'zfs and zpool must come from ZFSM_TEST_BIN_DIR in a test');
	$descriptor_spec = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('redirect', 1)
	);
	$cmd = array_merge(array($command), array_values($args));
	// HTTP inventory gets a shared wall-clock budget, including blocked ZFS CLI
	// calls. Never apply a timeout to administrative/mutating commands.
	$inventory_deadline = $GLOBALS['zfsm_inventory_deadline'] ?? null;
	if ($inventory_deadline !== null) {
		$remaining = $inventory_deadline - microtime(true);
		if ($remaining <= 0) throw new ZFSMTimeout(basename($command).' timed out before it started');
		$timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout' : '/bin/timeout';
		if (!is_executable($timeout)) throw new RuntimeException('The system timeout utility is unavailable');
		$duration = number_format(max(0.001, $remaining), 3, '.', '').'s';
		$cmd = array_merge(array($timeout, '--signal=TERM', '--kill-after=2s', $duration), $cmd);
	}
	$pipes = array();
	$process = @proc_open($cmd, $descriptor_spec, $pipes, null, null, array('bypass_shell' => true));

	if (!is_resource($process)) {
		return array('code' => ZFSM_ERR_UNABLE_TO_CREATE_PROC, 'output' => 'Unable to start '.basename($command));
	}

	if ($stdin !== null && $stdin !== '') {
		// A command that ends without reading it closes its end: the write fails, and its exit code and output say why.
		@fwrite($pipes[0], $stdin);
	}
	fclose($pipes[0]);
	// A zfs stuck in the kernel ignores even KILL and holds its output open: past the limit and timeout's own grace, let it go.
	$stop_at = $inventory_deadline === null ? null : $inventory_deadline + 3;
	$lines = null;
	if ($keep_bytes > 0) list($output, $next_line, $lines, $ended) = zfsmReadOutputHead($pipes[1], $keep_bytes, $stop_at);
	else list($output, $next_line, $ended) = zfsmReadOutput($pipes[1], $max_lines, $stop_at);
	$limit_code = $ended && $next_line !== null ? zfsmStopAtLimit($process) : null;
	fclose($pipes[1]);
	$code = $ended ? zfsmProcessExit($process, $stop_at) : null;
	if ($code === null) proc_terminate($process, 9);
	// proc_close can't report an exit that proc_get_status already collected.
	if ($limit_code !== null) $code = $limit_code;
	if ($limit_code === 0) return array('code' => 0, 'output' => trim($output, "\r\n"), 'truncated' => true, 'next_line' => rtrim($next_line, "\r\n"));
	if ($code === null) throw new ZFSMTimeout(basename($command).' timed out');
	// A command that ignored TERM is killed with KILL and comes back as 9, so any failure past the limit is the limit.
	if ($inventory_deadline !== null && $code !== 0 && (in_array($code, array(124, 137), true) || microtime(true) >= $inventory_deadline)) throw new ZFSMTimeout(basename($command).' timed out');

	// Line breaks only: a dataset's name may end in a space.
	$answer = array('code' => $code, 'output' => trim((string)$output, "\r\n"));
	if ($lines !== null) $answer['lines'] = $lines;
	// Exited before the reader got past the limit: nothing more is coming, but the line after it may be the error.
	if ($next_line !== null) $answer['next_line'] = rtrim($next_line, "\r\n");
	return $answer;
}

// Hands the pipe's output to $take a chunk at a time, until it ends or $take returns false: true. With $stop_at, false once that passes.
function zfsmReadChunks($pipe, $stop_at, $take) {
	if ($stop_at !== null) stream_set_blocking($pipe, false);
	while (true) {
		if ($stop_at !== null) {
			$wait = $stop_at - microtime(true);
			if ($wait <= 0) return false;
			$ready = array($pipe);
			$none = null;
			if (@stream_select($ready, $none, $none, (int)$wait, (int)(fmod($wait, 1) * 1000000)) === 0) continue;
		}
		$chunk = fread($pipe, 65536);
		if ($chunk === false || ($chunk === '' && feof($pipe))) return true;
		if ($chunk !== '' && $take($chunk) === false) return true;
	}
}

// The exit code once the command has ended; null when it is still running at $stop_at.
function zfsmProcessExit($process, $stop_at) {
	if ($stop_at === null) return proc_close($process);
	while (($status = proc_get_status($process))['running']) {
		if (microtime(true) >= $stop_at) return null;
		usleep(20000);
	}
	proc_close($process);
	return $status['signaled'] ? (int)$status['termsig'] : (int)$status['exitcode'];
}

// Reads to the end, keeping the first $keep_bytes; also returns how many lines there were in all, and whether it ended.
function zfsmReadOutputHead($pipe, $keep_bytes, $stop_at = null) {
	$head = '';
	$lines = 0;
	$last = "\n";
	$ended = zfsmReadChunks($pipe, $stop_at, function($chunk) use (&$head, &$lines, &$last, $keep_bytes) {
		if (strlen($head) < $keep_bytes) $head .= substr($chunk, 0, $keep_bytes - strlen($head));
		$lines += substr_count($chunk, "\n");
		$last = substr($chunk, -1);
	});
	return array($head, null, $lines + ($last === "\n" ? 0 : 1), $ended);
}

// Past the line limit: stops a command still running and returns 0; one that already exited keeps its exit code.
function zfsmStopAtLimit($process) {
	$status = proc_get_status($process);
	// Unraid's PHP workers ignore SIGPIPE and pass that on, so closing the pipe alone may not stop the command.
	if ($status['running']) proc_terminate($process);
	return $status['running'] ? 0 : (int)$status['exitcode'];
}

// The whole output, or its first $max_lines lines and the line after them; that line is null when none followed.
function zfsmReadOutput($pipe, $max_lines, $stop_at = null) {
	$buffer = '';
	// With $max_lines, enough is that many lines and the one after them.
	$ended = zfsmReadChunks($pipe, $stop_at, function($chunk) use (&$buffer, $max_lines) {
		$buffer .= $chunk;
		return $max_lines <= 0 || substr_count($buffer, "\n") <= $max_lines;
	});
	if ($max_lines <= 0) return array($buffer, null, $ended);
	$lines = preg_split('/(?<=\n)/', $buffer, -1, PREG_SPLIT_NO_EMPTY);
	return array(implode('', array_slice($lines, 0, $max_lines)), $lines[$max_lines] ?? null, $ended);
}

// Unraid 7.1 dropped default-popup.css; from then on a dialog styles itself like Unraid's own pages.
function zfsmPopupStylesheets($docroot) {
	if (is_file($docroot.'/webGui/styles/default-popup.css')) return array('/webGui/styles/default-popup.css');
	$display = function_exists('parse_plugin_cfg') ? (parse_plugin_cfg('dynamix', true)['display'] ?? array()) : array();
	$theme = preg_match('/^[a-z]+$/', (string)($display['theme'] ?? '')) === 1 ? $display['theme'] : 'black';
	$sheets = array('/webGui/styles/default-color-palette.css', '/webGui/styles/default-base.css', '/webGui/styles/themes/'.$theme.'.css');
	return array_values(array_filter($sheets, function($sheet) use ($docroot) { return is_file($docroot.$sheet); }));
}

// Every HTML escape and JSON encode goes through these (tests/encoding_test.php): a non-UTF-8 byte becomes U+FFFD.
// A value JSON can't hold (NAN) becomes 0 or null, unless the caller asks for JSON_THROW_ON_ERROR.
function zfsmHtml($text) {
	return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zfsmJson($value, $flags = 0) {
	return json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE | ($flags & JSON_THROW_ON_ERROR ? 0 : JSON_PARTIAL_OUTPUT_ON_ERROR));
}

// A plugin's version as its manifest's PLUGIN element gives it, entities expanded as Unraid's plugin('version') reads it; '' when unreadable.
function zfsmPluginVersion($manifest) {
	$plugin = is_file($manifest) ? @simplexml_load_file($manifest, 'SimpleXMLElement', LIBXML_NOCDATA) : false;
	$version = $plugin ? (string)$plugin['version'] : '';
	return preg_match('/^[\w.+-]+$/D', $version) === 1 ? $version : '';
}

// File and process names are anyone's to choose, and these strings reach notifications and the log.
function zfsmSafeText($text) {
	return substr(preg_replace('/[^\x20-\x7E]|[<>&"\']/', '?', zfsmOneLine($text)), 0, 500);
}

// A read's lines, empty ones left out, split only at a line feed: a mountpoint or a property's value in zfs's rows can hold a carriage return.
function zfsmLines($text) {
	return preg_split('/\n/', (string)$text, -1, PREG_SPLIT_NO_EMPTY) ?: array();
}

// Command output as one line: dialogs run lines together and syslog prints a newline as ?.
// CR and LF by name: without /u, \R also matches the 0x85 byte inside UTF-8 such as Å.
function zfsmOneLine($text) {
	return preg_replace(array('/:\s*(?:\r\n|\r|\n)\s*/', '/\s*(?:\r\n|\r|\n)\s*/'), array(': ', '; '), trim((string)$text));
}

// runProcess output is always a string, so a command killed or failing silently needs this fallback.
function zfsmCommandError($result, $fallback) {
	$text = zfsmOneLine($result['output'] ?? '');
	return $text !== '' ? $text : $fallback.' (exit '.(int)($result['code'] ?? 1).')';
}

function zfsmValidPoolName($name) {
	return is_string($name) && strlen($name) > 0 && strlen($name) <= 255
		&& preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $name) === 1;
}

// Mirrors valid_char() in OpenZFS zfs_namecheck.c; Unraid shares with spaces become datasets.
function zfsmValidNameComponent($part) {
	return is_string($part) && $part !== '.' && $part !== '..' && preg_match('/^[A-Za-z0-9_.: -]+$/D', $part) === 1;
}

// Whether ZFS takes $name after $zdataset's @ (255 in all); with none, after the shortest a dataset can be (p).
function zfsmValidSnapshotPart($name, $zdataset = 'p') {
	return is_string($name) && strlen($zdataset.'@'.$name) <= 255 && zfsmValidNameComponent($name);
}

function zfsmValidDatasetName($name) {
	if (!is_string($name) || strlen($name) === 0 || strlen($name) > 1024) return false;
	$parts = explode('/', $name);
	foreach ($parts as $part) {
		if (!zfsmValidNameComponent($part)) return false;
	}
	return zfsmValidPoolName($parts[0]);
}

// Unraid before 6.12 runs PHP 7.4, which has no str_starts_with.
function zfsmStartsWith($text, $prefix) {
	return strncmp($text, $prefix, strlen($prefix)) === 0;
}

// Whether $name is one of $roots or under one, with / between levels.
function zfsmUnderAny($name, $roots) {
	foreach ($roots as $root) if ($name === $root || zfsmStartsWith($name, $root.'/')) return true;
	return false;
}

// Unraid's cron file format (dynamix monitor.cron), each line's output dropped; '' with no lines.
function zfsmCronText($what, $lines) {
	return $lines ? '# Generated ZFS Master NG '.$what.":\n".implode('', array_map(function($line) { return $line." &> /dev/null\n"; }, $lines))."\n" : '';
}

function zfsmNewSnapshotName($cfg, $now) {
	$pattern = $cfg['snap_pattern'] ?? 'Y-m-d-His';
	return ($cfg['snap_prefix'] ?? '').((int)($cfg['snap_utc'] ?? 0) === 1 ? gmdate($pattern, $now) : date($pattern, $now));
}

// The moment before $at whose local time $at shows again, as the clock went back (by up to 2 hours); null when none.
function zfsmClockShownBefore($at) {
	$before = $at - ((int)date('Z', $at - 7200) - (int)date('Z', $at));
	return $before < $at && date('Y-m-d H:i:s', $before) === date('Y-m-d H:i:s', $at) ? $before : null;
}

// Whether zfsmNewSnapshotName names $at as it named the moment the clock showed its time before (zfsmClockShownBefore).
function zfsmNewSnapshotNameRepeats($cfg, $at) {
	$before = zfsmClockShownBefore($at);
	return $before !== null && zfsmNewSnapshotName($cfg, $before) === zfsmNewSnapshotName($cfg, $at);
}

// A name zfsmNewSnapshotName gives that ZFS refuses after $zdataset's @ (as zfsmValidSnapshotPart), now or half a year on (T and P
// change with summer time); null when it takes both.
function zfsmNewSnapshotNameRefused($cfg, $now, $zdataset = 'p') {
	foreach (array($now, $now + 182 * 86400) as $at) {
		$name = zfsmNewSnapshotName($cfg, $at);
		if (!zfsmValidSnapshotPart($name, $zdataset)) return $name;
	}
	return null;
}

// Why ZFS refuses $name, a new snapshot's from Snapshots Pattern and Prefix, after $zdataset's @ (as zfsmValidSnapshotPart); '' when it takes it.
// $show names it: only Settings does, as a bare @name in a log line escapes the diagnostics' redaction.
function zfsmNewSnapshotNameWhy($name, $show = false, $zdataset = 'p') {
	if (zfsmValidSnapshotPart($name, $zdataset)) return '';
	return 'Snapshots Pattern and Prefix in Settings give new snapshots a name ZFS refuses'.($show ? ', such as @'.$name : '')
		.': a name may hold only letters, digits, spaces and _ . : -, and with its dataset and @ be up to 255 long';
}

// Why ZFS refuses a name Snapshots Pattern and Prefix in $cfg give now or half a year on; '' when it takes both.
function zfsmNewSnapshotNamesWhy($cfg, $now, $show = false) {
	$refused = zfsmNewSnapshotNameRefused($cfg, $now);
	return $refused === null ? '' : zfsmNewSnapshotNameWhy($refused, $show);
}

// A path outside the plugin's folder. A test that sets $GLOBALS['zfsm_root'] reads and writes it under that folder instead.
function zfsmStatePath($path) {
	return ($GLOBALS['zfsm_root'] ?? '').$path;
}

function commandAnswer($subject, $result) {
	$answer = array('succeeded' => array(), 'failed' => array());
	if (($result['code'] ?? 1) === 0) {
		$answer['succeeded'][$subject] = 0;
	} else {
		$message = zfsmOneLine($result['output'] ?? '');
		$answer['failed'][$subject] = $message !== '' ? $message : (int)($result['code'] ?? 1);
	}
	return $answer;
}
	
function cleanZFSCreateDatasetParams($params) {
	$retParams = is_array($params) ? $params : array();

	unset($retParams['zpool']);
	unset($retParams['name']);
		
	foreach ($retParams as $key => $value):
		if ($value == 'inherit'):
			unset($retParams[$key]);
		endif;
	endforeach;
		
	if (($retParams['mount'] ?? 'yes') == 'no'):
		$retParams['mountpoint'] = 'none';
	else:
		if (!isset($retParams['mountpoint']) || $retParams['mountpoint'] == ''):
			unset($retParams['mountpoint']);
		endif;
	endif;

	if (($retParams['encryption'] ?? 'no') == 'no'):
		unset($retParams['encryption']);
		unset($retParams['passphrase']);
	else:
		// createDataset refuses a short or missing passphrase; dropping encryption here would create it unencrypted.
		$retParams['encryption'] = 'on';
		$retParams['keylocation'] = 'prompt';
		$retParams['keyformat'] = 'passphrase';
	endif;
		
	unset($retParams['mount']);
	
	if (!isset($retParams['quota']) || $retParams['quota'] == '' || $retParams['quota'] == '0'):
		unset($retParams['quota']);
	else:
		$retParams['quota'] = $retParams['quota'].$retParams['quotaunit'];
	endif;

	unset($retParams['quotaunit']);
		
	return $retParams;
}
	
function sortDatasetArray($datasetArray) {
	if (!is_array($datasetArray)) {
		return array();
	}

	if (isset($datasetArray['snapshots']) && is_array($datasetArray['snapshots']) && count($datasetArray['snapshots']) > 0):
		usort($datasetArray['snapshots'], function($item1, $item2) { 
			return ($item1['creation'] ?? 0) <=> ($item2['creation'] ?? 0);
		});
	endif;

	if (!isset($datasetArray['child']) || is_null($datasetArray['child']) || !is_array($datasetArray['child']) || count($datasetArray['child']) <= 0):
		return $datasetArray;
	endif;

	ksort($datasetArray['child']);
	
	foreach ($datasetArray['child'] as $dataset):
		if (is_array($dataset) && isset($dataset['name'])) {
			$datasetArray['child'][$dataset['name']] = sortDatasetArray($dataset);
		}
	endforeach;
	
	return $datasetArray;
}

function generatePoolDatasetOptions($dataset_array) {
	if (!isset($dataset_array['child']) || !is_array($dataset_array['child']) || count($dataset_array['child']) <= 0):
		return;
	endif;
	
	foreach ($dataset_array['child'] as $zdataset):
		$option = ltrim(stristr($zdataset['name'], '/'), '/')."/";
		echo '<option value="'.zfsmHtml($option).'">';

		if (count($zdataset['child']) > 0):
			generatePoolDatasetOptions($zdataset);
		endif;
	endforeach;
}

?>
