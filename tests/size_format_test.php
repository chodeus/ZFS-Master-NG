<?php
// zfsmSize (include/ZFSMHelpers.php): Unraid's own my_scale answers in Unraid's units, the setting's default, and the binary form kept as it was.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/include/ZFSMHelpers.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

// tests/fixtures/unraid_sizes.json; frontend_size_format_test.cjs checks the browser's copy against the same answers.
$answers = json_decode(file_get_contents(__DIR__.'/fixtures/unraid_sizes.json'), true);
same(true, count($answers) > 400, 'the fixture holds Unraid\'s answers');
foreach ($answers as $c) {
	same($c['text'], zfsmSize($c['bytes'], 'decimal', array('scale' => $c['scale'], 'number' => $c['number']), $c['decimals']), "{$c['bytes']} B, decimals ".var_export($c['decimals'], true).", scale {$c['scale']}, number \"{$c['number']}\"");
}

// ZFS's units unless Settings picks Unraid's; Snapshots Admin's binary form stays as it was (two decimals).
same('2.82 GiB', zfsmSize(3027000000, 'binary'), 'ZFS units, as Snapshots Admin always showed them');
same('2.82 GiB', zfsmSize(3027000000, 'GB'), 'an unknown value keeps ZFS units');
same('3.03 GB', zfsmSize(3027000000, 'decimal', array('scale' => '-1', 'number' => '.,')), 'Unraid units, with the display settings as Unraid stores them (strings)');
same('3.03 GB', zfsmSize(3027000000, 'decimal'), 'and with none stored, Unraid\'s defaults');
same('binary', loadConfig(array())['size_units'], 'a fresh install keeps ZFS units');
same('decimal', loadConfig(array('general' => array('size_units' => 'decimal')))['size_units'], 'Unraid units when picked');
same('binary', loadConfig(array('general' => array('size_units' => 'GB')))['size_units'], 'a hand-edited value keeps ZFS units');

echo "size format tests passed: ".count($answers)." of Unraid's answers\n";
