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
				fwrite($handle, $line);
				return;
			}
			if (!@rename($file, $file.'.1')) return;
		} finally {
			fclose($handle);
		}
	}
}
