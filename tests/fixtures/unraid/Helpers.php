<?php

function parse_plugin_cfg($plugin, $sections = false) {
	return array('general' => array(
		'refresh_interval' => '0',
		'lazy_load' => getenv('ZFSM_TEST_LAZY') === false ? '1' : getenv('ZFSM_TEST_LAZY'),
		'znapzend_data' => '1',
		'destructive_mode' => getenv('ZFSM_TEST_DESTRUCTIVE') === false ? '1' : getenv('ZFSM_TEST_DESTRUCTIVE'),
		'exclusion' => getenv('ZFSM_TEST_EXCLUSION') ?: '',
		'directory_listing' => '',
		'snap_max_days_alert' => '30',
		'snap_prefix' => '',
		'snap_pattern' => 'Y-m-d-His'
	));
}
