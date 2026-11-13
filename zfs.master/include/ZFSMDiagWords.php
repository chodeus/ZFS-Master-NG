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

// The words of a user agent's platform parts (zfsmDiagUserAgent), and of Unraid's themes in a browser's report. This file alone
// names them: the vocabulary reads every other file of the plugin.
define('ZFSM_DIAG_BROWSER_WORDS', 'mozilla windows nt win64 x64 x86_64 x11 wow64 applewebkit khtml like gecko chrome chromium safari edg edge firefox opr'
	.' macintosh intel mac os linux ubuntu android iphone ipad cpu version mobile headlesschrome crios fxios rv trident msie wv');
define('ZFSM_DIAG_THEME_WORDS', 'theme black white azure gray page');

// Every word in the plugin's own PHP, pages and scripts, lower case, with ZFSM_DIAG_WORDS: read once for a run.
function zfsmDiagVocabulary() {
	static $words = null;
	if ($words !== null) return $words;
	$words = array_flip(explode(' ', ZFSM_DIAG_WORDS));
	foreach (zfsmDiagFilesUnder(dirname(__DIR__)) as $file) {
		// The plugin's own text only: an asset it bundles holds every word there is, and this file's lists are added as lists.
		if (preg_match('~^(?!assets/).*\.(?:php|page|js)$~D', $file) !== 1 || $file === 'include/ZFSMDiagWords.php') continue;
		preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', (string)@file_get_contents(dirname(__DIR__).'/'.$file), $found);
		foreach ($found[0] as $word) $words[strtolower($word)] = true;
	}
	return $words;
}

// $text, already through the rules, with each word that is no number, no token and no word of the plugin's or of $more a token itself.
// A pool's name is matched whole, '-', '.' and ':' and all. null when it could not be searched.
function zfsmDiagKnownWords($text, $more = '') {
	$words = zfsmDiagVocabulary() + array_flip(array_filter(explode(' ', $more), 'strlen'));
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

// A user agent by the grammar browsers write it in: a name/version token stays, as does a bare word or a platform part whose
// words are all a browser's words, versions or single letters. Any other piece is a token. It never meets the shared rules.
function zfsmDiagUserAgent($agent) {
	return preg_replace_callback('~\(([^()]*)\)|[^\s()]+~', function($piece) {
		if ($piece[0][0] === '(') return '('.implode('; ', array_map('zfsmDiagAgentPart', explode(';', $piece[1]))).')';
		return preg_match('~^[A-Za-z][A-Za-z0-9.+_-]*/\d[\w.]*$~D', $piece[0]) === 1 ? $piece[0] : zfsmDiagAgentPart($piece[0]);
	}, $agent);
}

function zfsmDiagAgentPart($part) {
	$part = trim($part);
	$words = array_flip(explode(' ', ZFSM_DIAG_BROWSER_WORDS));
	foreach (preg_split('~[\s,]+~', $part, -1, PREG_SPLIT_NO_EMPTY) as $word) {
		if (!isset($words[strtolower($word)]) && preg_match('~^(?:[A-Za-z]{1,3}:)?\d[\d._]*$|^[A-Z]$~D', $word) !== 1) return zfsmDiagToken($part);
	}
	return $part;
}
