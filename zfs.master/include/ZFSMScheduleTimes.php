<?php
// When schedules run, as cron's five sets (minute, hour, day of the month, month, day of the week; each value once, in order, Sunday 0).
// The cron file gets the sets as numbers or * (zfsmScheduleCronFields), so Unraid's cron (dcron 4.5) runs what Next and SameTime say.

// Each field's name in a refusal, its lowest and highest value, the values a refusal names, and its names from the lowest value on.
define('ZFSM_SCHEDULE_CRON_FIELDS', array(
	array('the minute', 0, 59, '0 to 59', array()),
	array('the hour', 0, 23, '0 to 23', array()),
	array('the day of the month', 1, 31, '1 to 31', array()),
	array('the month', 1, 12, '1 to 12 or jan to dec', array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec')),
	array('the day of the week', 0, 7, '0 to 7 or sun to sat', array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat')),
));

// A typed line as the record keeps it and the texts show it: spaces collapsed, no-break ones too (u); '' for anything not UTF-8 text.
function zfsmScheduleCronLine($text) {
	return is_string($text) ? trim((string)preg_replace('/\s+/u', ' ', $text)) : '';
}

// $line's five sets, or why Save refuses it.
function zfsmScheduleCronParse($line) {
	if (strlen($line) > 255) return 'keep it to 255 characters or fewer';
	if (substr($line, 0, 1) === '@') return '@hourly, @daily and the like don\'t run at a set time on Unraid: give the five fields, such as 0 0 * * * for midnight every day';
	$fields = explode(' ', $line);
	if (count($fields) !== 5) return 'give five fields: minute, hour, day of the month, month and day of the week, such as */15 * * * *';
	$sets = array();
	foreach (ZFSM_SCHEDULE_CRON_FIELDS as $i => $field) {
		$set = zfsmScheduleCronField($fields[$i], $field, $i === 2);
		if (is_string($set)) return $set;
		$sets[] = $set;
	}
	// 7 is Sunday too.
	$sets[4] = array_values(array_unique(array_map(function($day) { return $day % 7; }, $sets[4])));
	sort($sets[4]);
	// One of the two typed as * (*/1 is * in both crons), as the refusal says: 1-31 with a weekday is refused too (dcron never runs it;
	// vixie daily).
	if (!in_array($fields[2], array('*', '*/1'), true) && !in_array($fields[4], array('*', '*/1'), true)) return 'set the day of the month or the day of the week, and leave the other *: Unraid\'s cron runs a line that sets both at other times, or never';
	return zfsmScheduleCronDays($sets[2], $sets[3]) ? $sets : 'no month in it has that day, so it never runs';
}

// One field's set, or why not: *, values and ranges split by commas, each with a step after a / (only on * or a range). With
// $from_zero (the day of the month), * covers 0 to 31 as in dcron, so a step on it counts from 0.
function zfsmScheduleCronField($text, $field, $from_zero) {
	$set = array();
	foreach (explode(',', $text) as $item) {
		$values = zfsmScheduleCronItem($item, $text, $field, $from_zero);
		if (is_string($values)) return $values;
		$set = array_merge($set, $values);
	}
	$set = array_values(array_unique($set));
	sort($set);
	return $set;
}

// One comma item of the field $text: its values, or why not.
function zfsmScheduleCronItem($item, $text, $field, $from_zero) {
	list($name, $low, $high, $span) = $field;
	list($range, $step) = array_pad(explode('/', $item, 2), 2, '1');
	$ends = zfsmScheduleCronEnds($range, $text, $field, $from_zero);
	if (is_string($ends)) return $ends;
	if (preg_match('/^[0-9]+$/D', $step) !== 1) return $name.' '.$item.' is not '.$span;
	if (count($ends) === 1 && strpos($item, '/') !== false) return 'in '.$name.', '.$item.' needs * or a range before the /, such as '.$range.'-'.$high.'/'.$step;
	if ($ends[0] > end($ends)) return 'in '.$name.', '.$range.' goes from high to low: write the low number first';
	if ((int)$step === 0) return 'in '.$name.', '.$item.' steps by 0: give 1 or more';
	$values = array();
	for ($at = $ends[0]; $at <= end($ends); $at += (int)$step) {
		if ($at >= $low) $values[] = $at;
	}
	return $values;
}

// $range's one value, or its first and last (* gives the field's, the day of the month's from 0), as numbers; or why not.
function zfsmScheduleCronEnds($range, $text, $field, $from_zero) {
	list($name, $low, $high, $span) = $field;
	if ($range === '*') return array($from_zero ? 0 : $low, $high);
	$ends = array();
	foreach (explode('-', $range, 2) as $end) {
		$ends[] = zfsmScheduleCronValue($end, $field);
		if (end($ends) === null) return $name.' '.($end !== '' ? $end : $text).' is not '.$span;
	}
	return $ends;
}

// $end, a number or name in $field, as its number; null when it is neither.
function zfsmScheduleCronValue($end, $field) {
	list(, $low, $high, , $names) = $field;
	$at = array_search(strtolower($end), $names, true);
	if ($at !== false) return $at + $low;
	return preg_match('/^[0-9]+$/D', $end) === 1 && (int)$end >= $low && (int)$end <= $high ? (int)$end : null;
}

// Whether a month in $months has a day in $dates (February: up to the 29th).
function zfsmScheduleCronDays($dates, $months) {
	foreach ($months as $month) {
		if ($dates && min($dates) <= array(31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)[$month - 1]) return true;
	}
	return false;
}

// A Custom (cron) schedule has its line, as zfsmScheduleCronLine keeps it, and one Save takes; no other kind has one.
function zfsmScheduleCronValid($job) {
	if (!array_key_exists('cron', $job)) return $job['every'] !== 'cron';
	return $job['every'] === 'cron' && $job['cron'] === zfsmScheduleCronLine($job['cron']) && is_array(zfsmScheduleCronParse($job['cron']));
}

// When $job runs: a minute, an hour and days, and a cron line only on a cron one. Only after its kind's check, which checks its Repeat.
function zfsmScheduleTimeValid($job) {
	$number = function($value, $top) { return is_int($value) && $value >= 0 && $value <= $top; };
	return $number($job['minute'] ?? null, 59) && $number($job['hour'] ?? null, 23) && zfsmScheduleDaysValid($job['days'] ?? null) && zfsmScheduleCronValid($job);
}

// $job's run times as the five sets: an hourly one's minute every hour, a days one's time on its days, its cron line's (one Save took),
// or none for When the array starts.
function zfsmScheduleSets($job) {
	if ($job['every'] === 'start') return array(array(), array(), array(), array(), array());
	if ($job['every'] === 'cron') return zfsmScheduleCronParse($job['cron']);
	$hourly = $job['every'] === 'hourly';
	return array(array($job['minute']), $hourly ? range(0, 23) : array($job['hour']), range(1, 31), range(1, 12), $hourly ? range(0, 6) : $job['days']);
}

// $job's five fields for the cron file: * for a full set, else its values, so the line runs where its sets say and no typed text goes in.
function zfsmScheduleCronFields($job) {
	return implode(' ', array_map(function($set, $full) { return count($set) === $full ? '*' : implode(',', $set); }, zfsmScheduleSets($job), array(60, 24, 31, 12, 7)));
}

// When cron next runs $job after $now (zfsmScheduleNextAt).
function zfsmScheduleNext($job, $now) {
	return zfsmScheduleNextAt(zfsmScheduleSets($job), $now);
}

// What Save's answer counts a cron line's next run from: now, once cron runs what $changed saved; null while it runs the ones it had.
function zfsmScheduleSavedNow($changed) {
	return $changed['why'] === '' ? time() : null;
}

// zfsmScheduleWhen as Save's answers say it: for a cron line, with its next run after $now (none when null, zfsmScheduleSavedNow).
function zfsmScheduleWhenNext($job, $now) {
	$next = $job['every'] === 'cron' && $now !== null ? zfsmScheduleNext($job, $now) : null;
	return zfsmScheduleWhen($job).($next !== null ? ', next at '.date(date('Y', $next) === date('Y', $now) ? 'D j M H:i' : 'D j M Y H:i', $next) : '');
}

// When cron next runs at $sets after $now, in this server's time zone as cron reads it, so it skips a summer-time gap and runs a repeated
// hour twice. Each pass moves on to the next month, day, hour or minute; null after 5000 passes, which a line Save takes never needs.
function zfsmScheduleNextAt($sets, $now) {
	list($minutes, $hours, $dates, $months, $days) = array_map('array_flip', $sets);
	$at = $now - $now % 60 + 60;
	for ($pass = 0; $pass < 5000; $pass++) {
		list($minute, $hour, $date, $month, $day, $year) = array_map('intval', explode(' ', date('i G j n w Y', $at)));
		if (!isset($months[$month])) $at = mktime(0, 0, 0, $month + 1, 1, $year);
		elseif (!isset($dates[$date], $days[$day])) $at = mktime(0, 0, 0, $month, $date + 1, $year);
		elseif (!isset($hours[$hour])) $at += (60 - $minute) * 60;
		elseif (!isset($minutes[$minute])) {
			$later = array_filter($sets[0], function($next) use ($minute) { return $next > $minute; });
			$at += (($later ? min($later) : 60) - $minute) * 60;
		} else return $at;
	}
	return null;
}

// Whether $a and $b start in one minute on cron's times, or both when the array starts (one then beside one on cron: zfsmScheduleStartClash).
// Over the years each date falls on every weekday, so with a day of the month on one side only that day in a shared month counts.
function zfsmScheduleSameTime($a, $b) {
	if ($a['every'] === 'start' || $b['every'] === 'start') return $a['every'] === $b['every'];
	list($minutes, $hours, $dates, $months, $days) = zfsmScheduleSets($a);
	list($minutes_b, $hours_b, $dates_b, $months_b, $days_b) = zfsmScheduleSets($b);
	return array_intersect($minutes, $minutes_b) && array_intersect($hours, $hours_b) && array_intersect($days, $days_b)
		&& zfsmScheduleCronDays(array_intersect($dates, $dates_b), array_intersect($months, $months_b));
}
