<?php

function parse_plugin_cfg($plugin, $sections = false) {
	// Unraid's display settings, as JSON: none unless a test sets them.
	if ($plugin === 'dynamix') return array('display' => json_decode((string)getenv('ZFSM_TEST_DISPLAY'), true) ?: array());
	return array('general' => array(
		'refresh_interval' => '0',
		'lazy_load' => getenv('ZFSM_TEST_LAZY') === false ? '1' : getenv('ZFSM_TEST_LAZY'),
		'znapzend_data' => '1',
		'destructive_mode' => getenv('ZFSM_TEST_DESTRUCTIVE') === false ? '1' : getenv('ZFSM_TEST_DESTRUCTIVE'),
		'exclusion' => getenv('ZFSM_TEST_EXCLUSION') ?: '',
		'directory_listing' => '',
		'snap_max_days_alert' => '30',
		'snap_prefix' => '',
		'snap_pattern' => 'Y-m-d-His',
		'snap_utc' => getenv('ZFSM_TEST_SNAP_UTC') ?: '0',
		// A file in the docroot, not the environment or TMPDIR: scripts/replica-serve.php keeps neither.
		'replica_accept' => (string)@file_get_contents(($GLOBALS['docroot'] ?? '').'/zfsm-replica-accept')
	));
}
