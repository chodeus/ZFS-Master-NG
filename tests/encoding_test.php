<?php
// A byte that isn't UTF-8 (a hand-edited setting, a property, a path) must not empty a whole value: every HTML escape and
// JSON encode goes through zfsmHtml and zfsmJson, which put U+FFFD in its place.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__.'/fixtures/fake_bin_guard.php';

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

same("auto-\u{FFFD}&lt;b&gt; &quot;x&quot; &#039;y&#039;", zfsmHtml("auto-\xff<b> \"x\" 'y'"), 'an escape keeps the rest of the text');
same('', zfsmHtml(null), 'nothing escapes to nothing');
same(array('prefix' => "auto-\u{FFFD}"), json_decode(zfsmJson(array('prefix' => "auto-\xff")), true), 'an encode keeps the rest of the value');
same(false, strpos(zfsmJson(array('tag' => '</script>'), JSON_HEX_TAG), '<'), 'and the caller\'s flags');
// Names that differ only in bytes that aren't UTF-8 must not share a confirmation.
same(false, zfsmImpactToken('tank/a', 'plain', array("tank/a@\xff"), array()) === zfsmImpactToken('tank/a', 'plain', array("tank/a@\xfe"), array()), 'a token tells those names apart');

// Nothing else in the plugin calls htmlspecialchars or json_encode; the two helpers are their only callers.
function zfsmHelperLine($name) {
	return (new ReflectionFunction($name))->getStartLine();
}
$found = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../zfs.master', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	if (!preg_match('/\.(php|page)$/', $file->getFilename())) continue;
	$tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())), function($token) {
		return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
	}));
	foreach ($tokens as $i => $token) {
		if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), array('htmlspecialchars', 'json_encode'), true)) continue;
		if (($tokens[$i + 1] ?? null) !== '(') continue;
		$found[] = basename($file->getPathname()).':'.$token[2].' '.strtolower($token[1]);
	}
}
sort($found);
same(array('ZFSMHelpers.php:'.(zfsmHelperLine('zfsmHtml') + 1).' htmlspecialchars', 'ZFSMHelpers.php:'.(zfsmHelperLine('zfsmJson') + 1).' json_encode'), $found, 'only zfsmHtml and zfsmJson call them');

echo "encoding tests passed\n";
