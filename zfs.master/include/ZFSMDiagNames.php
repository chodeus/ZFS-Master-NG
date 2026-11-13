<?php
// Diagnostics privacy: include/ZFSMDiagBundle.php rewrites every file with these before it packs them. Dataset, snapshot, bookmark
// and folder names, addresses and hosts become tokens, a key its fingerprint. A pool's own name stays, as in Unraid's diagnostics.

// Top folders that are the system's alone. Under ZFSM_DIAG_SHARED anyone keeps files beside the plugin's and Unraid's own.
define('ZFSM_DIAG_SYSTEM', 'bin|etc|lib|lib64|proc|run|sbin|sys|usr|plugins|webGui|dev(?!/zvol/)');
define('ZFSM_DIAG_SHARED', 'boot|tmp|var|root');
// What Unraid itself mounts under /mnt beside the pools.
define('ZFSM_DIAG_MOUNTS', 'user0?|disks|remotes|addons|rootshare|disk\d+');
// What the plugin's log lines put after a dataset's name, each word with what follows it there: a name can hold the word alone.
define('ZFSM_DIAG_AFTER', '(?:succeeded|received)$|failed(?:: | after | with exit code |$)|(?:not )?started: |finished(?:: |\.| \(|$)|is not inside |\((?:in full|changes since|carried on)|\| ');

// A run's map: its salt, each name's token, each token's shape, and what the collectors told it of this server.
function zfsmDiagNamesStart() {
	$GLOBALS['zfsm_diag_names'] = array('salt' => random_bytes(16), 'tokens' => array(), 'shapes' => array(), 'pools' => array(), 'roots' => array(), 'spaced' => array(), 'words' => array());
}

// The token that stands for $name in this run's files, the same wherever the name is; a token stands for itself.
function zfsmDiagToken($name, $kind = 'name') {
	$names = &$GLOBALS['zfsm_diag_names'];
	$name = (string)$name;
	if ($name === '' || isset($names['shapes'][$name])) return $name;
	if (!isset($names['tokens'][$name])) {
		$hash = hash_hmac('sha256', $name, $names['salt']);
		// Longer when two names share their first digits.
		for ($length = 8; isset($names['shapes'][$kind.'-'.substr($hash, 0, $length)]); $length += 2);
		$names['tokens'][$name] = $kind.'-'.substr($hash, 0, $length);
		$names['shapes'][$names['tokens'][$name]] = zfsmDiagShape($name);
	}
	return $names['tokens'][$name];
}

// What names.txt keeps of a name: the things about it that break code, never the name.
function zfsmDiagShape($name) {
	$valid = preg_match('//u', $name) === 1;
	$shape = array(($valid ? preg_match_all('/./su', $name).' characters, ' : '').strlen($name).' bytes');
	if (!$valid) $shape[] = 'not valid UTF-8';
	elseif (preg_match('/[^\x00-\x7F]/', $name) === 1) $shape[] = 'non-ASCII';
	if (strpos($name, ' ') !== false) $shape[] = substr_count($name, ' ').' space(s)';
	$upper = preg_match('/[A-Z]/', $name) === 1;
	$lower = preg_match('/[a-z]/', $name) === 1;
	$shape[] = $upper && $lower ? 'mixed case' : ($upper ? 'upper case' : ($lower ? 'lower case' : 'no letters'));
	return implode(', ', $shape);
}

function zfsmDiagKnowPool($pool) {
	if (zfsmValidPoolName((string)$pool)) $GLOBALS['zfsm_diag_names']['pools'][(string)$pool] = true;
}

// Every pool this run knows: the ones zpool lists and the ones learned from what the collectors read (zfsmDiagKnowPool).
function zfsmDiagKnownPools() {
	$read = $GLOBALS['zfsm_diag']['pools'] ?? null;
	return $GLOBALS['zfsm_diag_names']['pools'] + (is_array($read) ? $read : array());
}

// A dataset or snapshot a collector read or a setting names: its pool marks where names start in running text, and one with
// a space is known whole, so the words after it are not taken for more of it.
function zfsmDiagKnow($name) {
	$name = (string)$name;
	$parts = preg_split('~[/@#]~', $name);
	// A name with nothing after it is not known to be a pool: a setting can hold any word.
	if (count($parts) > 1) zfsmDiagKnowPool($parts[0]);
	if (strpos($name, ' ') !== false) $GLOBALS['zfsm_diag_names']['spaced'][strstr($name, ' ', true)][$name] = true;
}

// A pool nothing here knows, such as one exported since, is known once the plugin's own log lines or zfs's messages name one
// of its datasets: after ": ", "of ", "to ", "into " or a quote. Then it marks its datasets wherever they are.
function zfsmDiagLearnPools($text) {
	preg_match_all('~(?<=: |of |to |into |\')(?!I/O\b|Input/output\b)[A-Za-z][A-Za-z0-9_.:-]*(?=/[A-Za-z0-9])~', $text, $found);
	array_map('zfsmDiagKnowPool', array_unique($found[0]));
}

// A value its owner or another tool wrote freely, such as a user property's, can name anything, and no path marks it:
// a number, a switch or a ZnapZend plan stays, and anything else becomes a token.
function zfsmDiagFreeValue($value) {
	return preg_match('/^(?:-|on|off|yes|no|true|false|\d+(?:\.\d+)?(?:[KMGTPE]i?B?|B|[smhdwy]|x|%)?|[0-9]+[smhdwy](?:=>[0-9]+[smhdwy])+(?:,[0-9]+[smhdwy](?:=>[0-9]+[smhdwy])+)*)$/D', (string)$value) === 1 ? $value : zfsmDiagToken($value);
}

// A dataset a setting or a finding names, with nothing around it to mark it: each name in it is a token, but for its first
// when that is a pool this run knows (a setting's first word is no proof of a pool).
function zfsmDiagNamed($name) {
	$parts = explode('/', (string)$name, 2);
	$pool = isset(zfsmDiagKnownPools()[$parts[0]]) ? $parts[0] : zfsmDiagToken($parts[0]);
	$rest = isset($parts[1]) ? zfsmDiagNameTokens('/'.$parts[1]) : '';
	return $rest === null ? zfsmDiagToken($name) : $pool.$rest;
}

// The first word of a dataset a setting names on no pool this run knows: found as a pool is, and a token (zfsmDiagNamed).
function zfsmDiagKnowRoot($root) {
	if ((string)$root !== '') $GLOBALS['zfsm_diag_names']['roots'][(string)$root] = true;
}

// A name with no path to mark it, such as another server's: it becomes a token wherever it stands as a word.
function zfsmDiagKnowWord($word) {
	if (trim((string)$word) !== '') $GLOBALS['zfsm_diag_names']['words'][trim((string)$word)] = true;
}

// This server's name, as it is set and as Unraid writes it in file names.
function zfsmDiagKnowServer($name) {
	array_map('zfsmDiagKnowWord', array((string)$name, strtolower((string)$name)));
}

// An alternation of $list's strings for a pattern, the longest first; one that matches nothing when the list is empty.
function zfsmDiagAnyOf($list) {
	usort($list, function($left, $right) { return strlen($right) <=> strlen($left); });
	return $list ? implode('|', array_map(function($one) { return preg_quote($one, '~'); }, $list)) : '(?!)';
}

// Everything the rewrite looks for, in one pattern, so that what one rule wrote is never read by another.
function zfsmDiagNamePattern() {
	$names = &$GLOBALS['zfsm_diag_names'];
	if (!isset($names['pattern'])) {
		$names['pool'] = zfsmDiagAnyOf(array_map('strval', array_keys(zfsmDiagKnownPools())));
		$names['root'] = zfsmDiagAnyOf(array_map('strval', array_keys(array_diff_key($names['roots'], zfsmDiagKnownPools()))));
		$names['files'] = array_flip(array_column(zfsmDiagCollectors(), 0));
		// The paths the collectors list are the plugin's own: path => whether what its name starts, and what is under it, are too.
		// That holds for a path with the plugin's name in it; another folder Unraid shares with everyone covers only itself.
		$names['own'] = array();
		foreach (array_merge(array_keys(zfsmDiagNotCollected()), ...array_column(zfsmDiagCollectors(), 3)) as $path) $names['own'][] = zfsmStatePath(strstr($path.'*', '*', true));
		// A key runs to its END line; one whose end was cut off is the rest of its line and the lines of key text after it, no more.
		$names['pattern'] = '~(?<secret>-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----(?:[A-Za-z0-9+/=\s;]*-----END [A-Z0-9 ]*PRIVATE KEY-----|[^\r\n]*(?:\r?\n[A-Za-z0-9+/=]{16,}(?=\r?\n|\z))*))'
			// zfs send -t: the token of a copy that stopped names its snapshot to anyone who asks zfs.
			.'|(?<resume>(?<= -t )\d+-[0-9a-f]+-[0-9a-f]+-[0-9a-f]{16,})'
			.'|(?<key>\b(?:ssh-(?:ed25519|rsa|dss)|ecdsa-sha2-[\w-]+|sk-[\w-]+@openssh\.com)[ \t]+(?<blob>AAAA[0-9A-Za-z+/]+=*))'
			.'|(?<query>(?<=[\w/.])\?[\w%.-]+=[^\s"\'<>]*|\b(?:host|referrer|server): ?(?:"[^"]*"|[^\s,]*))'
			.'|(?<request>zfsm-replica 1 [a-z]+ )(?<hex>[0-9a-f]{2,})'
			.'|(?<since>(?<=changes since )[^)\t\r\n]+)'
			.'|(?<path>(?<![A-Za-z0-9_.:/-])/(?=[^/\s])|(?<![A-Za-z0-9_./-])(?:'.$names['pool'].'|'.$names['root'].')(?=[/@#]))'
			.'|(?<word>(?<![A-Za-z0-9_.-])(?:'.zfsmDiagAnyOf(array_map('strval', array_keys($names['words']))).')(?![A-Za-z0-9_-]))'
			.'|(?<host>(?<![\w.+-])(?!feature@|unsupported@)[\w.+-]+@[\w-]+(?:\.[\w-]+)*(?::[^\s"\',]+)?)'
			.'|(?<ip>(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?![\w]|\.\d)|(?<![\w:.])(?:(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|[0-9A-Fa-f:]*::[0-9A-Fa-f:.]*)(?![\w:]))~s';
	}
	return $names['pattern'];
}

// $text with every name, address, host and key in it replaced; null when it could not be searched, and then it must not be kept.
// $ends: what ends a folder's path in $text: a tab or a line break in a file's tab-separated rows, only a line break in running text
// (zfsmDiagRunningText) and in a message, nothing in a value that is a path itself (zfsmDiagAnonValue).
function zfsmDiagAnonText($text, $ends = "\t\r\n") {
	$pattern = zfsmDiagNamePattern();
	$clean = '';
	for ($at = 0; ($found = preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $at)) === 1; $at = $end) {
		list($put, $end) = zfsmDiagAnonMatch($text, $match, $ends);
		// A name whose tokens could not be made is not written at all.
		if ($put === null) return null;
		$clean .= substr($text, $at, $match[0][1] - $at).$put;
	}
	return $found === 0 ? $clean.substr($text, $at) : null;
}

// What one find becomes, and where the search goes on: [its replacement, the offset after what it stands for].
function zfsmDiagAnonMatch($text, $match, $ends = "\t\r\n") {
	list($found, $at) = $match[0];
	$end = $at + strlen($found);
	if ($match['path'][0] !== null) return zfsmDiagAnonPath($text, $at, $ends);
	if ($match['secret'][0] !== null) return array('(a private key, left out)', $end);
	if ($match['resume'][0] !== null) return array('(a resume token, left out)', $end);
	if ($match['key'][0] !== null) return array('(a key, SHA256:'.rtrim(base64_encode(hash('sha256', (string)base64_decode($match['blob'][0]), true)), '=').')', $end);
	if ($match['query'][0] !== null) return array(($found[0] === '?' ? '?' : explode(':', $found, 2)[0].': ').'(left out)', $end);
	// A request names its dataset as hex (include/ZFSMReplicaServe.php), of any pool; a log line may hold only the first of it.
	if ($match['hex'][0] !== null) {
		$named = explode('/', (string)hex2bin(substr($match['hex'][0], 0, strlen($match['hex'][0]) & ~1)), 2);
		return array($match['request'][0].'(as hex) '.$named[0].zfsmDiagNameTokens(isset($named[1]) ? '/'.$named[1] : ''), $end);
	}
	// The plugin writes the snapshot's short name there, with nothing to mark it (zfsmReplicaShortName).
	if ($match['since'][0] !== null) return array(zfsmDiagToken($found), $end);
	if ($match['word'][0] !== null) return array(zfsmDiagToken($found), $end);
	if ($match['host'][0] !== null) return array(zfsmDiagToken($found, 'host'), $end);
	// Sentence punctuation after an address is not part of it; what is no address stays as it is.
	for ($ip = $found; $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false && strpos('.:', substr($ip, -1)) !== false; $ip = substr($ip, 0, -1));
	return filter_var($ip, FILTER_VALIDATE_IP) === false ? array($found, $end) : array(zfsmDiagToken($ip, 'ip').substr($found, strlen($ip)), $end);
}

// A path at $at, each name in it a token. A system path stays. A dataset's pool stays, and its names end where ZFS's few characters do.
// A folder's name may hold any character: its path runs to the first of $ends (zfsmDiagAnonText) or the text's end.
function zfsmDiagAnonPath($text, $at, $ends = "\t\r\n") {
	$names = &$GLOBALS['zfsm_diag_names'];
	$run = (string)substr($text, $at, strcspn($text, $ends, $at));
	if (preg_match('~^/(?:'.ZFSM_DIAG_SYSTEM.')(?=[/\s]|$)~', $run, $system) === 1) return array($system[0], $at + strlen($system[0]));
	// One of the plugin's own paths, whole: in a row's field or a value the whole field, in running text up to its first space (the second
	// net takes the words after it). Any other path under a shared top folder is a folder's, names and all.
	$whole = rtrim(strpos($ends, "\t") !== false || $ends === '' ? $run : substr($run, 0, strcspn($run, ' ')), ':,;');
	if (zfsmDiagOwnName($whole)) return array($whole, $at + strlen($whole));
	foreach ($names['own'] as $own) {
		// A listed path itself, or a log of it that was turned over (syslog.1): what is in or beside it is not, unless zfsmDiagOwnName says so.
		if ($whole === $own || preg_match('/^'.preg_quote($own, '/').'\.\d+$/D', $whole) === 1) {
			return array($whole, $at + strlen($whole));
		}
	}
	$kept = array('');
	if ($run[0] === '/') {
		preg_match('~^/(?:mnt|dev/zvol)(?=[/\s]|$)(?:/(?:'.$names['pool'].'|'.ZFSM_DIAG_MOUNTS.')(?=[/\s]|$))?|^/(?:'.ZFSM_DIAG_SHARED.')(?=[/\s]|$)|^~', $run, $kept);
		// PHP ends an error with where it was raised: that is no part of the folder's name.
		$name = preg_replace('~ in /usr/local/emhttp/\S+ on line \d+$~', '', $run);
	} else {
		// A pool's name stays; a setting's first word on no pool is a token in its place: [what it is, what is written].
		if (preg_match('~^(?:'.$names['pool'].')(?=[/@#])~', $run, $kept) !== 1 && preg_match('~^(?:'.$names['root'].')(?=[/@#])~', $run, $root) === 1) $kept = array($root[0], zfsmDiagToken($root[0]));
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.:%- /@#';
		$length = strspn($run, $chars);
		// zfs and the plugin write a dataset's snapshots as one list, tank/data@a,b,c.
		while (strpos(substr($run, 0, $length), '@') !== false && preg_match('~\G,[A-Za-z0-9_.:%-]~', $run, $more, 0, $length) === 1) $length += 1 + strspn($run, $chars, $length + 1);
		$run = substr($run, 0, $length);
		// Punctuation and spaces that end a sentence, not a name.
		$name = rtrim(substr($run, 0, zfsmDiagNameEnd($run)), '.: ');
		if (strlen($name) < strlen($kept[0])) $name = $kept[0];
		// One of this run's own files, where a pool is named as their folder is.
		if (isset($names['files'][$name])) return array($name, $at + strlen($name));
	}
	$tokens = zfsmDiagNameTokens((string)substr($name, strlen($kept[0])));
	return array($tokens === null ? null : ($kept[1] ?? $kept[0]).$tokens, $at + strlen($name));
}

// Whether $path is one the plugin makes in its own places, whole: a run's work folder and its files, a job's folder and its locks,
// the plugin's lock and alert state, a package record, its files on the flash drive. Nothing else in or beside them is its own.
function zfsmDiagOwnName($path) {
	$jobs = '(?:rewrite|replicate|replica-serve|diagnostics)(?:/(?:(?:\d+|[0-9a-f]{64}\.\d+|pack|run|start)\.lock|state\.json(?:\.tmp)?|browser\.json))?';
	// zfsmJobDir('') is /var/run/zfs.master. but in a test: listed after it, the fuller entry wins where they are one.
	$names = array(
		zfsmStatePath('/tmp/zfs.master-diag.') => '[0-9a-f]{16}(?:/(?:(?:plugin|zfs)/[a-z0-9._-]+|unraid/(?:context|diagnostics)\.txt|(?:names|README|collectors|summary)\.txt))?',
		zfsmJobDir('') => $jobs,
		zfsmStatePath('/var/run/zfs.master.') => '(?:lock|alerts\.json(?:\.lock)?|'.$jobs.')',
		zfsmStatePath(rtrim('/var/log/packages/zfs.master-*', '*')) => '\d[\d.]*-[a-z0-9_]+-\d\w*',
		zfsmStatePath('/boot/config/plugins/zfs.master') => '/(?:zfs\.master\.cfg|alerts\.cron|zfs\.master-\d[\w.-]*\.t[gx]z)');
	foreach ($names as $prefix => $name) {
		if (preg_match('~^'.preg_quote($prefix, '~').$name.'$~D', $path) === 1) return true;
	}
	return false;
}

// The part of a path after what stays, each name in it a token.
function zfsmDiagNameTokens($rest) {
	return preg_replace_callback('~[^/@#,]+~', function($part) { return $part[0] === '.' || $part[0] === '..' ? $part[0] : zfsmDiagToken($part[0]); }, $rest);
}

// Where a dataset's name ends in $run: after a known name with spaces; else at the first space that one of the plugin's own words,
// another path or a user@host follows; else at the run's end, because a name can hold spaces and the words after it may be part of it.
function zfsmDiagNameEnd($run) {
	$names = &$GLOBALS['zfsm_diag_names'];
	$first = strstr($run, ' ', true);
	if ($first === false) return strlen($run);
	$from = 0;
	// The longest known name with spaces that $run starts with: they are kept by the text before their first space.
	foreach ($names['spaced'][$first] ?? array() as $known => $is) {
		$known = (string)$known;
		$next = (string)substr($run, strlen($known), 1);
		if (strlen($known) > $from && strncmp($run, $known, strlen($known)) === 0 && ($next === '' || strpos(' /@#,', $next) !== false)) $from = strlen($known);
	}
	$after = '~\G (?:'.ZFSM_DIAG_AFTER.'|(?:(?:to|into|from) )?(?:/[^/\s]|(?:'.$names['pool'].')[/@#]|[\w.+-]+@[\w-])| *$)~';
	for ($space = strpos($run, ' ', $from); $space !== false; $space = strpos($run, ' ', $space + 1)) {
		if (preg_match($after, $run, $unused, 0, $space) === 1) return $space;
	}
	return strlen($run);
}

// A JSON file's text with the names in its keys and values replaced; text that is no JSON is rewritten as text, its \/ read as /.
// null when a string in it could not be searched.
function zfsmDiagAnonJson($text) {
	$data = json_decode($text, true);
	if ($data === null && trim($text) !== 'null') return zfsmDiagAnonText(str_replace('\\/', '/', $text));
	$failed = false;
	$clean = zfsmDiagAnonValue($data, $failed);
	return $failed ? null : zfsmJson($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// A decoded JSON value with the strings in it rewritten, keys too. A key with a colon in it is a user property's name, as the Main
// tab's read gives them (zfsmDiagPropertyName), and the text under it that property's value (zfsmDiagFreeValue).
function zfsmDiagAnonValue($value, &$failed, $free = false) {
	if (is_string($value)) {
		// A value that stays as a number can still be an address.
		// One value is one field: a path that is the whole value runs to its end, and a message's path to its line's end.
		$clean = zfsmDiagAnonText($free ? zfsmDiagFreeValue($value) : $value, $value !== '' && $value[0] === '/' ? '' : "\r\n");
		$failed = $failed || $clean === null;
		return $clean;
	}
	if (!is_array($value)) return $value;
	$clean = array();
	foreach ($value as $key => $inner) {
		$user = is_string($key) && strpos($key, ':') !== false;
		// A dataset's name can hold a colon too: that one is a path, for the rules.
		$named = $user && strpbrk($key, '/@') === false ? zfsmDiagPropertyName($key) : $key;
		$clean[is_string($named) ? zfsmDiagAnonValue($named, $failed) : $named] = zfsmDiagAnonValue($inner, $failed, $user);
	}
	return $clean;
}

// names.txt: each token and the shape of the name it stands for.
function zfsmDiagNamesTable() {
	$text = "token\tthe shape of the name it stands for\n";
	foreach ($GLOBALS['zfsm_diag_names']['shapes'] as $token => $shape) $text .= $token."\t".$shape."\n";
	return $text;
}
