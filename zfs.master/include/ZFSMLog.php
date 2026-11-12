<?php
// The plugin's own log: every line it sends to the system log, kept in RAM (/var/log is a 128 MB tmpfs) under a fixed ceiling.

define('ZFSM_LOG_BYTES', 524288);

function zfsmLogFile() {
	return zfsmStatePath('/var/log/zfs.master.log');
}

function zfsmLog($message, $priority = LOG_ERR) {
	$text = zfsmSafeText($message);
	openlog('zfs.master', LOG_PID, LOG_USER);
	syslog($priority, $text);
	closelog();
	zfsmLogKeep($text, $priority);
}

// A debug line: in the plugin's own log only, and only while Debug Log is on in the settings, read once for each run.
function zfsmDebug($message) {
	if (!isset($GLOBALS['zfsm_debug'])) $GLOBALS['zfsm_debug'] = function_exists('parse_plugin_cfg') && (int)(parse_plugin_cfg('zfs.master', true)['general']['debug_log'] ?? 0) === 1;
	if ($GLOBALS['zfsm_debug']) zfsmLogKeep(zfsmSafeText($message), LOG_DEBUG);
}

// A command the plugin ran, for the debug log: what it was, how it ended and how long it took. Where a key is kept is left out.
function zfsmDebugCommand($command, $args, $result, $began) {
	$secret = basename((string)$command) === 'zfs' && (($args[0] ?? '') === 'load-key' || preg_grep('/keylocation=/', $args));
	zfsmDebug(basename((string)$command).' '.($secret ? ($args[0] ?? '').' (its arguments are left out)' : implode(' ', $args)).': '.$result.' in '.number_format(microtime(true) - $began, 3, '.', '').' s');
}

// At ZFSM_LOG_BYTES the file becomes .1, replacing the last one, so the two never pass about 1 MB. A line that can't be written
// is dropped: syslog has it.
function zfsmLogKeep($text, $priority) {
	$file = zfsmLogFile();
	$levels = array(LOG_EMERG => 'emergency', LOG_ALERT => 'alert', LOG_CRIT => 'critical', LOG_ERR => 'error', LOG_WARNING => 'warning', LOG_NOTICE => 'notice', LOG_INFO => 'info', LOG_DEBUG => 'debug');
	$line = date('Y-m-d H:i:s').' '.($levels[$priority] ?? 'level '.$priority).' ['.getmypid().'] '.$text."\n";
	for ($try = 0; $try < 3; $try++) {
		$handle = @fopen($file, 'a');
		if ($handle === false || !flock($handle, LOCK_EX)) return;
		try {
			clearstatcache(true, $file);
			$stat = fstat($handle);
			// A writer that waited out a turn-over holds .1: it starts again on the new file, and must not turn that one over too.
			if (@fileinode($file) !== $stat['ino']) continue;
			if ($stat['size'] < ZFSM_LOG_BYTES) {
				@fwrite($handle, $line);
				return;
			}
			if (!@rename($file, $file.'.1')) return;
		} finally {
			fclose($handle);
		}
	}
}
