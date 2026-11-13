<?php
// The second net for running text. Logs, command lines and findings are sentences, and no rule knows every shape a name takes
// in one: after the rules of ZFSMDiagNames.php, a word there stays only when it is a number, a pool's name, a token, a fingerprint or a word the plugin uses.

// Words of the system's own messages that the plugin's files may not hold: the calendar, zfs's, PHP's and nginx's errors.
define('ZFSM_DIAG_WORDS', 'jan feb mar apr may jun jul aug sep oct nov dec mon tue wed thu fri sat sun utc gmt notice info debug warning error critical'
	.' cannot open destroy create mount unmount receive send rollback rename promote hold release inherit snapshot bookmark clone dataset filesystem volume'
	.' pool does not exist already exists is are was busy has have children dependent clones use the following and or of in on at by for from into with'
	.' without no such file directory permission denied operation permitted invalid argument input output device resource space left out quota exceeded'
	.' read only write system incremental source most recent match stream checksum mismatch incomplete partially received saved property value must be'
	.' key loaded encryption passphrase mountpoint legacy none failed unable currently suspended newer older target destination name too long required'
	.' bad missing unknown usage unsupported feature enabled disabled active inactive readonly off yes true false connection refused timed reset peer'
	.' broken pipe interrupted call supported memory php fatal parse deprecated uncaught exception stack trace thrown undefined variable array offset'
	.' index constant function method class object string int float bool null given expects parameter type line main opening include require once'
	.' fastcgi sent stderr message while reading response header upstream client server request host referrer get post http unix sock fpm'
	.' kmod rc online degraded faulted offline unavail removed ssh connect port');

// Every word in the plugin's own PHP, pages and scripts, lower case, with ZFSM_DIAG_WORDS: read once for a run, a failure too.
// null when its files could not all be listed and read: a list with words missing is not kept.
function zfsmDiagVocabulary() {
	static $words = null;
	if ($words !== null) return $words ?: null;
	$words = false;
	$files = zfsmDiagFilesUnder(dirname(__DIR__));
	if ($files === null) return null;
	$read = array_flip(explode(' ', ZFSM_DIAG_WORDS));
	foreach ($files as $file) {
		// The plugin's own text only: an asset it bundles holds every word there is.
		if (preg_match('~^(?!assets/).*\.(?:php|page|js)$~D', $file) !== 1) continue;
		$text = zfsmDiagWhole('file_get_contents', dirname(__DIR__).'/'.$file);
		if ($text === false) return null;
		preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', $text, $found);
		foreach ($found[0] as $word) $read[strtolower($word)] = true;
	}
	return $words = $read;
}

// Running text, a file of it or a JSON value with a space or a tab: the rules, a folder's path ending at its line's end, then the second net for
// the words after a path's first space and the rest. null when it could not be searched.
function zfsmDiagAnonRunning($text) {
	$clean = zfsmDiagAnonText($text, "\r\n");
	return $clean === null ? null : zfsmDiagKnownWords($clean);
}

// $text, already through the rules, with each word that is no number, no token and no word of the plugin's a token itself.
// A pool's name is matched whole, '-', '.' and ':' and all. null when it could not be searched.
function zfsmDiagKnownWords($text) {
	$words = zfsmDiagVocabulary();
	if ($words === null) return null;
	$pools = zfsmDiagKnownPools();
	$pool = zfsmDiagAnyOf(array_map('strval', array_keys($pools)));
	// A token of this run and a key's fingerprint are matched whole: zfsmDiagToken gives a token of its own back as it is.
	return preg_replace_callback('~(?:name|host|ip)-[0-9a-f]{8,}|SHA256:[A-Za-z0-9+/]{43}(?![A-Za-z0-9+/=])'
		.'|(?<![A-Za-z0-9_\x80-\xff.:-])(?:'.$pool.')(?![A-Za-z0-9_\x80-\xff:-]|\.[A-Za-z0-9_])|[A-Za-z0-9_\x80-\xff]+~', function($word) use ($words, $pools) {
		$word = $word[0];
		return isset($pools[$word]) || strncmp($word, 'SHA256:', 7) === 0 || isset($words[strtolower($word)]) || zfsmDiagIsNumber($word) ? $word : zfsmDiagToken($word);
	}, $text);
}

// A user property's name is its owner's to write: each word of it the plugin does not use is a token (org.znapzend:src_plan stays whole).
function zfsmDiagPropertyName($property) {
	$shown = preg_replace_callback('/[^_]+/', function($part) { return zfsmDiagKnownWords($part[0]) ?? zfsmDiagToken($part[0]); }, $property);
	return $shown ?? zfsmDiagToken($property);
}

// A number, alone, with a unit of size, time or ratio, or as an ordinal; or a checksum: 12, 10G, 512KiB, 30s, 2x, 2nd, an md5.
function zfsmDiagIsNumber($word) {
	return preg_match('/^(?:\d+(?:[KMGTPE]i?B?|B|[smhdwy]|x|st|nd|rd|th)?|[0-9a-f]{32,})$/D', $word) === 1;
}
