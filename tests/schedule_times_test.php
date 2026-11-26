<?php
// Run times (ZFSMScheduleTimes.php): what a cron line parses to and the line the cron file gets, each refusal, the next run in three
// time zones, and when two schedules start in the same minute.

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/include/ZFSMScheduleTimes.php';

function same($expected, $actual, $message) {
	if ($expected === $actual) return;
	fwrite(STDERR, "FAILED: $message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
	exit(1);
}

$cron = function($line) { return array('every' => 'cron', 'cron' => $line); };
$fields = function($line) use ($cron) {
	$sets = zfsmScheduleCronParse($line);
	return is_string($sets) ? $sets : zfsmScheduleCronFields($cron($line));
};

same('*/15 * * * *', zfsmScheduleCronLine("  */15\t*  * *\n* "), 'a typed line keeps one space between its fields');
same('', zfsmScheduleCronLine(null), 'no line is an empty one');
same(array('0 3 * * *', ''), array(zfsmScheduleCronLine("0\u{a0}3\u{202f}* * *"), zfsmScheduleCronLine("0 3 * * \xff")), 'no-break spaces part fields too; text not UTF-8 is no line');
$evens = implode(',', range(2, 30, 2));
foreach (array(
	'*/15 * * * *' => '0,15,30,45 * * * *',
	'0 3 1 * *' => '0 3 1 * *',
	'30 2 * * 1-5' => '30 2 * * 1,2,3,4,5',
	'0 */6 * * *' => '0 0,6,12,18 * * *',
	'0 4 1 1,7 *' => '0 4 1 1,7 *',
	'0-30/10 * * * *' => '0,10,20,30 * * * *',
	'* * * * *' => '* * * * *',
	'0 0 * JAN-mar Mon,wed' => '0 0 * 1,2,3 1,3',
	'0 0 * * 5-7' => '0 0 * * 0,5,6',
	'0 0 * * 0-7' => '0 0 * * *',
	'0 3 */1 * 3' => '0 3 * * 3',
	'0 3 1 * */1' => '0 3 1 * *',
	'0 0 */2 * *' => '0 0 '.$evens.' * *',
	'0 0 */7 * *' => '0 0 7,14,21,28 * *',
	'0 0 1-31/2 * *' => '0 0 '.implode(',', range(1, 31, 2)).' * *',
	'*/90 * * * *' => '0 * * * *',
	'5,5,1 * * * *' => '1,5 * * * *',
	'0 0 29 2 *' => '0 0 29 2 *',
	'0'.str_repeat(',0', 123).' * * * *' => '0 * * * *',
) as $line => $written) same($written, $fields($line), 'the cron file line for '.substr($line, 0, 40));

foreach (array(
	'' => 'give five fields: minute, hour, day of the month, month and day of the week, such as */15 * * * *',
	'* * * *' => 'give five fields: minute, hour, day of the month, month and day of the week, such as */15 * * * *',
	'* * * * * *' => 'give five fields: minute, hour, day of the month, month and day of the week, such as */15 * * * *',
	'@daily' => '@hourly, @daily and the like don\'t run at a set time on Unraid: give the five fields, such as 0 0 * * * for midnight every day',
	'60 * * * *' => 'the minute 60 is not 0 to 59',
	'5,60 * * * *' => 'the minute 60 is not 0 to 59',
	'* 24 * * *' => 'the hour 24 is not 0 to 23',
	'* * 0 * *' => 'the day of the month 0 is not 1 to 31',
	'* * * 13 *' => 'the month 13 is not 1 to 12 or jan to dec',
	'* * * * 8' => 'the day of the week 8 is not 0 to 7 or sun to sat',
	'x * * * *' => 'the minute x is not 0 to 59',
	'*/x * * * *' => 'the minute */x is not 0 to 59',
	'1,,2 * * * *' => 'the minute 1,,2 is not 0 to 59',
	'* * * * fri-' => 'the day of the week fri- is not 0 to 7 or sun to sat',
	'50-10 * * * *' => 'in the minute, 50-10 goes from high to low: write the low number first',
	'* * * * fri-mon' => 'in the day of the week, fri-mon goes from high to low: write the low number first',
	'5/15 * * * *' => 'in the minute, 5/15 needs * or a range before the /, such as 5-59/15',
	'*/0 * * * *' => 'in the minute, */0 steps by 0: give 1 or more',
	'0 0 1 * 1' => 'set the day of the month or the day of the week, and leave the other *: Unraid\'s cron runs a line that sets both at other times, or never',
	'0 0 1-31 * 1' => 'set the day of the month or the day of the week, and leave the other *: Unraid\'s cron runs a line that sets both at other times, or never',
	'0 0 1 * 0-7' => 'set the day of the month or the day of the week, and leave the other *: Unraid\'s cron runs a line that sets both at other times, or never',
	'0 0 30 2 *' => 'no month in it has that day, so it never runs',
	'0 0 31 4,6,9,11 *' => 'no month in it has that day, so it never runs',
	'0 0 */40 * *' => 'no month in it has that day, so it never runs',
	'00'.str_repeat(',0', 123).' * * * *' => 'keep it to 255 characters or fewer',
) as $line => $why) same($why, zfsmScheduleCronParse($line), 'the refusal of '.substr($line, 0, 40));

same(array(true, false, false, false, false, true, false, false), array_map('zfsmScheduleCronValid', array($cron('*/15 * * * *'), $cron('*/15  * * * *'), $cron('5/15 * * * *'),
	array('every' => 'hourly', 'cron' => '* * * * *'), array('every' => 'cron'), array('every' => 'hourly'), $cron(5), $cron(array()))), 'only a cron schedule has a line, kept with its spaces collapsed and one Save takes');

$next = function($line, $from) use ($cron) {
	$at = zfsmScheduleNext($cron($line), strtotime($from));
	return $at === null ? null : date('D Y-m-d H:i', $at);
};
date_default_timezone_set('UTC');
foreach (array(
	array('*/15 * * * *', '2026-11-25 08:07:30', 'Wed 2026-11-25 08:15'),
	array('*/15 * * * *', '2026-11-25 08:15:00', 'Wed 2026-11-25 08:30'),
	array('* * * * *', '2026-11-25 08:07:30', 'Wed 2026-11-25 08:08'),
	array('0 3 1 * *', '2026-11-25 08:00', 'Tue 2026-12-01 03:00'),
	array('0 3 1 * *', '2026-12-31 23:59', 'Fri 2027-01-01 03:00'),
	array('0 0 29 2 *', '2026-11-25 08:00', 'Tue 2028-02-29 00:00'),
	array('0 0 29 2 *', '2096-03-01 00:00', 'Fri 2104-02-29 00:00'),
	array('30 2 * * 1-5', '2026-11-27 03:00', 'Mon 2026-11-30 02:30'),
	array('0 0 */2 * *', '2026-11-30 01:00', 'Wed 2026-12-02 00:00'),
	array('0 0 */2 * *', '2026-12-30 00:00:30', 'Sat 2027-01-02 00:00'),
	array('0 4 1 1,7 *', '2026-11-25 08:00', 'Fri 2027-01-01 04:00'),
) as list($line, $from, $at)) same($at, $next($line, $from), $line.' next after '.$from.' (UTC)');
same(null, zfsmScheduleNextAt(array(array(0), array(0), array(30), array(2), range(0, 6)), 0), 'sets with no run give none');

// Sydney: summer time starts on 4 Oct 2026 (02:00 goes to 03:00) and ends on 5 Apr 2026 (03:00 goes back to 02:00).
date_default_timezone_set('Australia/Sydney');
same('Mon 2026-10-05 02:30', $next('30 2 * * *', '2026-10-04 01:00'), 'a time in the summer-time gap waits for the next day');
$first = zfsmScheduleNext($cron('30 2 * * *'), strtotime('2026-04-05 01:00'));
$again = zfsmScheduleNext($cron('30 2 * * *'), $first);
same(array('Sun 2026-04-05 02:30 +1100', 'Sun 2026-04-05 02:30 +1000'), array(date('D Y-m-d H:i O', $first), date('D Y-m-d H:i O', $again)), 'a time in the hour that repeats runs twice');
same('Sun 2026-10-04 03:00', $next('0 * * * *', '2026-10-04 01:30'), 'every hour goes on past the gap');
date_default_timezone_set('Asia/Kolkata');
same('Wed 2026-11-25 09:00', $next('0 * * * *', '2026-11-25 08:10'), 'a half-hour offset keeps local hours');
same('2026-11-25 03:30', gmdate('Y-m-d H:i', zfsmScheduleNext($cron('0 * * * *'), strtotime('2026-11-25 08:10'))), 'its hour starts at :30 UTC');
date_default_timezone_set('UTC');

$hourly = function($minute) { return array('every' => 'hourly', 'minute' => $minute, 'hour' => 0, 'days' => range(0, 6)); };
$days = function($hour, $days) { return array('every' => 'days', 'minute' => 0, 'hour' => $hour, 'days' => $days); };
$start = array('every' => 'start', 'minute' => 0, 'hour' => 0, 'days' => range(0, 6));
same(array(array(), array(), array(), array(), array()), zfsmScheduleSets($start), 'when the array starts has no times');
foreach (array(
	array(true, $cron('0 0 * * 1'), $cron('0 0 * * 1,3'), 'two lines that share a weekday'),
	array(false, $cron('0 0 * * 1'), $cron('0 0 * * 2'), 'two lines on other weekdays'),
	array(true, $cron('0 0 31 * *'), $cron('0 0 31 * *'), 'two lines on one date'),
	array(false, $cron('0 0 1 * *'), $cron('0 0 2 * *'), 'two lines on other dates'),
	array(false, $cron('0 0 30 1 *'), $cron('0 0 30 3 *'), 'one date in months apart'),
	array(false, $cron('0 0 31 1,3 *'), $cron('0 0 * 4,6 *'), 'months apart'),
	array(true, $cron('0 0 29 2 *'), $cron('0 0 29 * *'), 'the 29th of February'),
	array(false, $cron('0 0 31 * *'), $cron('0 0 * 2 *'), 'a date its shared month never has'),
	array(true, $cron('0 0 13 * *'), $cron('0 0 * * 5'), 'a date and a weekday: Friday the 13th comes'),
	array(true, $hourly(15), $cron('*/15 3 * * *'), 'an hourly minute among a line\'s'),
	array(false, $hourly(7), $cron('*/15 * * * *'), 'an hourly minute that is not'),
	array(true, $days(3, array(1)), $cron('0 3 * * 1-5'), 'days of the week inside a line\'s'),
	array(false, $days(3, array(1)), $cron('0 3 * * 0,6'), 'days of the week outside a line\'s'),
	array(false, $cron('0 1 * * *'), $cron('0 2 * * *'), 'one minute in other hours'),
	array(true, $hourly(0), $days(3, array(2)), 'an hourly one and days at its minute'),
	array(false, $days(3, array(2)), $days(4, array(2)), 'days at other hours'),
	array(true, $start, $start, 'two when the array starts'),
	array(false, $start, $hourly(0), 'when the array starts and every hour'),
	array(false, $start, $cron('* * * * *'), 'when the array starts and every minute'),
) as list($meet, $a, $b, $what)) same(array($meet, $meet), array(zfsmScheduleSameTime($a, $b), zfsmScheduleSameTime($b, $a)), 'same time: '.$what);

echo "schedule times tests passed\n";
