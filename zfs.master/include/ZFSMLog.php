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

// Debug Log's lines have a file of their own under the same ceiling, so they never push the plugin's other lines out.
function zfsmDebugFile() {
	return zfsmStatePath('/var/log/zfs.master.debug.log');
}

// Whether Debug Log is on, from the saved settings by loadConfig's own rule, read once for each run.
function zfsmDebugOn() {
	if (!isset($GLOBALS['zfsm_debug'])) {
		$saved = @parse_ini_file(zfsmStatePath('/boot/config/plugins/zfs.master/zfs.master.cfg'), true);
		$GLOBALS['zfsm_debug'] = loadConfig(is_array($saved) ? $saved : array())['debug_log'] === 1;
	}
	return $GLOBALS['zfsm_debug'];
}

function zfsmDebug($message) {
	if (zfsmDebugOn()) zfsmLogKeep(zfsmSafeText($message), LOG_DEBUG, zfsmDebugFile());
}

// A command the plugin ran, for the debug log: how it ended and how long it took first, the result cut to 200 bytes so a long one
// leaves room for the command (zfsmSafeText cuts a line at 500).
// The time is taken before the setting is read: the first command of a run would carry that read.
function zfsmDebugCommand($command, $args, $result, $began) {
	$seconds = number_format(microtime(true) - $began, 3, '.', '');
	if (!zfsmDebugOn()) return;
	$line = substr($result, 0, 200).' in '.$seconds.' s: '.zfsmCommandShown(basename((string)$command).' '.implode(' ', (array)$args));
	zfsmLogKeep(zfsmSafeText($line), LOG_DEBUG, zfsmDebugFile());
}

// A command line as the plugin shows it anywhere: rsync's arguments, and those of any command that loads a key or names where one
// is kept (keylocation=prompt names none), are left out, whatever wraps it and across line breaks.
function zfsmCommandShown($line) {
	if (preg_match('#^(\S*/)?rsync(\s|$)#', $line) === 1) return 'rsync (its arguments are left out)';
	if (preg_match('#\bload-key\b|(?i:keylocation)=(?!prompt(?:\s|$))#', $line) !== 1) return $line;
	// Only zfs and zpool keep their subcommand: any other command's next word is an argument.
	return preg_replace('#^(?:\S*/)?((?:zfs|zpool)\s+[a-z][a-z-]*|\S+).*#s', '$1', $line).' (its arguments are left out)';
}

// At ZFSM_LOG_BYTES the file becomes .1, replacing the last one, so the two never pass about 1 MB. A line that can't be written
// is dropped: syslog has the plugin's own (zfsmLog), not a debug line.
function zfsmLogKeep($text, $priority, $file = null) {
	$file = $file ?? zfsmLogFile();
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
