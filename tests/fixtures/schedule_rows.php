<?php
// Settings' Schedules table (zfsmSchedulesHtml) as a user sees it: each row of every tbody, its cells' text, a cell's lines joined by
// ' | ', a button as [its value]. $part 'thead' reads the head. null when there is no table.
function zfsmTestScheduleRows($html, $part = 'tbody') {
	$doc = new DOMDocument();
	$errors = libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>');
	libxml_clear_errors();
	libxml_use_internal_errors($errors);
	$tables = (new DOMXPath($doc))->query('//table[@class="zfsm-sched-table"]');
	if ($tables->length !== 1) return null;
	$rows = array();
	foreach ((new DOMXPath($doc))->query($part.'/tr', $tables->item(0)) as $tr) {
		$cells = array();
		foreach ($tr->childNodes as $cell) {
			if (in_array($cell->nodeName, array('td', 'th'), true)) $cells[] = zfsmTestScheduleCell($cell);
		}
		$rows[] = $cells;
	}
	return $rows;
}

// zfsmTestScheduleRows' rows whose Dataset cell's first line is $dataset, each with its warnings (rows of one cell) after it.
function zfsmTestScheduleRowsOf($html, $dataset) {
	$found = array();
	$mine = false;
	foreach (zfsmTestScheduleRows($html) ?? array() as $row) {
		if (count($row) > 1) $mine = explode(' | ', $row[1])[0] === $dataset;
		if ($mine) $found[] = $row;
	}
	return $found;
}

function zfsmTestScheduleCell($node) {
	$lines = array('');
	foreach ($node->childNodes as $child) {
		if ($child->nodeName === 'div') {
			$lines[] = zfsmTestScheduleCell($child);
			$lines[] = '';
		} elseif ($child->nodeName === 'input') {
			$lines[count($lines) - 1] .= $child->getAttribute('type') === 'button' ? ' ['.$child->getAttribute('value').'] ' : '';
		} else {
			$lines[count($lines) - 1] .= $child->nodeName === '#text' ? $child->nodeValue : zfsmTestScheduleCell($child);
		}
	}
	$lines = array_map(function($line) { return trim(preg_replace('/\s+/u', ' ', $line)); }, $lines);
	return implode(' | ', array_values(array_filter($lines, 'strlen')));
}
