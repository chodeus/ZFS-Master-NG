// Snapshots Admin list: filter, sort and what is ticked. A row the filter hides is unticked, so Delete never acts on one you can't see.
function zfsmSnapshotsShown() {
	return $('.snapl-check').filter(function() { return !$(this).closest('tr').hasClass('zfsm-hidden'); });
}

function updateStatus(text) {
	$('#zfs_status').text(text);
}

// Counts what is ticked now, so Delete and Select all stay right with no rows, after a change or under a filter.
function syncSelection() {
	const shown = zfsmSnapshotsShown();
	const ticked = $('.snapl-check:checked').length;
	$('#delete-snaps').prop('disabled', ticked === 0).text(ticked ? 'Delete selected ('+ticked+')' : 'Delete selected');
	$('#checkAll').prop({checked: shown.length > 0 && ticked === shown.length, disabled: shown.length === 0});
	updateStatus(ticked ? ticked+' selected' : (shown.length ? 'Tick snapshots to delete them together.' : ''));
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
	if ($.fn.tablesorter && $('#zpools tr[data-snapshot]').length) {
		$('.zfsm-snap-table').tablesorter({sortList: [[6, 0]], headers: {0: {sorter: false}, 7: {sorter: false}}});
	}
	zfsmFilterSnapshots();
}
