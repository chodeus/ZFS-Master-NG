<?php
// Keep's rules (Snapshots to keep, Copy Keeps, Keep Replicas): as saved, shown and said. ZFSMKeep.php applies them; loadConfig reads Keep Replicas.
require_once __DIR__.'/ZFSMHelpers.php';

define('ZFSM_KEEP_MAX', 9999);
// Keep's periods by letter: how date() names one in this server's time zone (ISO weeks, Monday first), and its word.
define('ZFSM_KEEP_PERIODS', array('h' => array('Y-m-d H', 'hourly'), 'd' => array('Y-m-d', 'daily'), 'w' => array('o-W', 'weekly'),
	'm' => array('Y-m', 'monthly'), 'y' => array('Y', 'yearly')));

function zfsmKeepValid($keep) {
	return zfsmKeepRules($keep) !== null;
}

// A Keep's rules, a count by letter: '' the newest that many, a ZFSM_KEEP_PERIODS letter the newest of each of that many last periods
// with one. From an int ('' alone; 0: none) or words such as "10 7d 4w 12m", each letter once; null from anything else.
function zfsmKeepRules($keep) {
	if (is_int($keep)) return $keep < 0 || $keep > ZFSM_KEEP_MAX ? null : array_filter(array('' => $keep));
	if (!is_string($keep) || strlen($keep) > 64) return null;
	$rules = array();
	foreach (preg_split('/[\s,]+/', strtolower($keep), -1, PREG_SPLIT_NO_EMPTY) as $word) {
		if (preg_match('/^([0-9]+)([a-z]?)$/D', $word, $parts) !== 1) return null;
		list(, $count, $unit) = $parts;
		if (isset($rules[$unit]) || ($unit !== '' && !array_key_exists($unit, ZFSM_KEEP_PERIODS)) || (int)$count < 1 || (int)$count > ZFSM_KEEP_MAX) return null;
		$rules[$unit] = (int)$count;
	}
	return $rules ? array_replace(array_intersect_key(array('' => 0) + ZFSM_KEEP_PERIODS, $rules), $rules) : null;
}

// What Snapshots to keep holds, as Keep is saved: empty keeps all (0), and a number alone stays an int, which versions before rules
// read; else its rules in one order. Null when it is none of those.
function zfsmKeepParse($text) {
	$text = trim($text);
	$number = $text === '' ? 0 : zfsmDigits($text, strlen((string)ZFSM_KEEP_MAX), null);
	if ($number !== null) return $number;
	$rules = zfsmKeepRules($text);
	if ($rules === null) return null;
	return array_keys($rules) === array('') ? $rules[''] : zfsmKeepField($text);
}

// $keep as Snapshots to keep shows it: '' when all stay, else its rules in one order ("10 7d 4w 12m").
function zfsmKeepField($keep) {
	$words = array();
	foreach (zfsmKeepRules($keep) ?? array() as $unit => $count) $words[] = $count.$unit;
	return implode(' ', $words);
}

// How to give a Keep it reads, for the end of a message that says what is wrong; $empty: what nothing does.
function zfsmKeepFix($empty = 'keep all') {
	return 'give a number up to '.ZFSM_KEEP_MAX.', periods such as 7d 4w 12m (h, d, w, m or y, each once), both, or nothing to '.$empty;
}

// What Copy Keeps holds, as saved: null for nothing (a copy here keeps what its source keeps), 0 for "all", else a Keep as
// zfsmKeepParse reads it; -1, which no check accepts, when it is none of those.
function zfsmCopyKeepsParse($text) {
	$text = trim($text);
	if ($text === '') return null;
	return strtolower($text) === 'all' ? 0 : (zfsmKeepParse($text) ?? -1);
}

// $copy_keeps as Copy Keeps shows it: '' for none, "all" when every snapshot stays, else its rules.
function zfsmCopyKeepsField($copy_keeps) {
	return $copy_keeps === null ? '' : (zfsmKeepRules($copy_keeps) ? zfsmKeepField($copy_keeps) : 'all');
}

// What a copy with $copy_keeps keeps, in words; '' for none.
function zfsmCopyKeepsWords($copy_keeps) {
	return $copy_keeps === null ? '' : (zfsmKeepRules($copy_keeps) ? zfsmKeepWords($copy_keeps) : 'all its snapshots');
}

// Keep Replicas as saved in $general. v2026.11.16's field shows rules with periods empty, and its Apply saves that over them, so
// Settings saves them twice; replica_keep_rules, which no older version writes, counts only when it has periods and replica_keep is empty.
function zfsmKeepReplicaText($general) {
	$text = (string)($general['replica_keep'] ?? '');
	$rules = $general['replica_keep_rules'] ?? null;
	return trim($text) === '' && is_string($rules) && is_string(zfsmKeepParse($rules)) ? $rules : $text;
}

// What Keep Replicas' field says of $text: '' when Keep reads it, else that all stay and how to fix it.
function zfsmKeepReplicaWarning($text) {
	return zfsmKeepParse($text) === null ? '"'.trim($text).'" is not read, so all stay: '.zfsmKeepFix() : '';
}

// $keep in words ("the newest 10, 7 daily and 4 weekly"); '' when all stay.
function zfsmKeepWords($keep) {
	$words = array();
	foreach (zfsmKeepRules($keep) ?? array() as $unit => $count) $words[] = $unit === '' ? 'the newest '.$count : $count.' '.ZFSM_KEEP_PERIODS[$unit][1];
	$last = array_pop($words);
	return $words ? implode(', ', $words).' and '.$last : (string)$last;
}
