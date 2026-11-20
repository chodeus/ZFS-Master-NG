<?php
// schedules.json's records as saved, but Copy Keeps' shadows, which schedule_downgrade_test.php checks; as json_decode gives it when no list.
function zfsmTestStored() {
	$records = json_decode((string)@file_get_contents(zfsmScheduleFile()), true);
	if (!is_array($records)) return $records;
	return array_values(array_filter($records, function($record) { return !is_array($record) || array_keys($record) !== array('copy_keeps_of', 'value'); }));
}
