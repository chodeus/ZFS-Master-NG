// Snapshots Admin list: filter, sort and what is ticked. A row the filter hides is unticked, so Delete never acts on one you can't see.
function zfsmSnapshotsShown() {
	return $('.snapl-check').filter(function() { return !$(this).closest('tr').hasClass('zfsm-hidden'); });
}

function updateStatus(text) {
	$('#zfs_status').text(text);
}

// The one dataset every ticked snapshot belongs to, or null: zfs destroys one dataset's snapshots per call, all or none.
function zfsmTickedDataset() {
	const datasets = new Set($('.snapl-check:checked').map(function() { return this.value.split('@')[0]; }).get());
	return datasets.size === 1 ? datasets.values().next().value : null;
}

// Counts what is ticked now, so Delete and Select all stay right with no rows, after a change or under a filter.
function syncSelection() {
	const shown = zfsmSnapshotsShown();
	const ticked = $('.snapl-check:checked').length;
	const mixed = ticked > 0 && zfsmTickedDataset() === null;
	$('#delete-snaps').prop('disabled', ticked === 0 || mixed).text(ticked ? 'Delete selected ('+ticked+')' : 'Delete selected');
	$('#checkAll').prop({checked: shown.length > 0 && ticked === shown.length, disabled: shown.length === 0});
	if (mixed) updateStatus('Delete works on one dataset at a time. Tick snapshots of one dataset, or filter by its name.');
	else updateStatus(ticked ? ticked+' selected' : (shown.length ? 'Tick snapshots to delete them together.' : ''));
}

// The same list with or without the child datasets' snapshots.
function zfsmChildrenUrl(href, children) {
	const url = new URL(href);
	if (children) url.searchParams.set('children', '1');
	else url.searchParams.delete('children');
	return url.href;
}

function zfsmShowChildren(children) {
	window.location.replace(zfsmChildrenUrl(window.location.href, children));
}

function zfsmFilterSnapshots() {
	const text = String($('#zfsm-snap-filter').val() || '').toLowerCase();
	const hideAutomatic = $('#zfsm-hide-auto').prop('checked') === true;
	const rows = $('#zpools tr[data-snapshot]');
	let shown = 0;
	rows.each(function() {
		const row = $(this);
		const hide = (text !== '' && String(row.attr('data-snapshot')).toLowerCase().indexOf(text) === -1) || (hideAutomatic && row.attr('data-automatic') === '1');
		row.toggleClass('zfsm-hidden', hide);
		if (hide) row.find('.snapl-check').prop('checked', false);
		else shown++;
	});
	$('#zfsm-snap-shown').text(shown === rows.length ? '' : shown+' of '+rows.length+' shown');
	syncSelection();
}

// Unraid's own tablesorter (in dynamix.js since 6.9); cells carry raw values in data-text. Starts oldest first, as listed.
// Delegated: tablesorter rebuilds each header cell, which replaces the Select all box.
function zfsmInitSnapshotList() {
	$(document).on('click', '#checkAll', function() {
		zfsmSnapshotsShown().prop('checked', this.checked);
		syncSelection();
	});
	$(document).on('change', '.snapl-check', syncSelection);
	$('#zfsm-snap-filter').on('input', zfsmFilterSnapshots);
	$('#zfsm-hide-auto').on('change', zfsmFilterSnapshots);
	$('#zfsm-children').on('change', function() { zfsmShowChildren(this.checked); });
	if ($.fn.tablesorter && $('#zpools tr[data-snapshot]').length) {
		$('.zfsm-snap-table').tablesorter({sortList: [[6, 0]], headers: {0: {sorter: false}, 7: {sorter: false}}});
	}
	zfsmFilterSnapshots();
}
