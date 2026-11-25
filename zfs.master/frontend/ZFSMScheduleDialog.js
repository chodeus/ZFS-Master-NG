// The Schedules dialog: its form, the rows each choice shows, and what it sends.

// The Schedules dialog, from zfsmScheduleDialogPreview in include/ZFSMScheduleDialog.php: its warning or why no run takes it, if so; for its
// snapshots and copies (preview.copies, here or on a server) Default (what it follows), None or its own; zfsmScheduleDialogSync shows those that fit.
function zfsmScheduleDialogHtml(preview) {
	const esc = zfsmEscape;
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	const copies = preview.copies;
	const choices = [['default', 'Default'], ['none', 'None'], ['hourly', 'Every hour'], ['days', 'On days of the week'], ['cron', 'Custom (cron)'], ['start', 'When the array starts']];
	const skipped = preview.warning ? zfsmWarningHtml(preview.warning, 'zfsm-schedules-skipped')
		: preview.skipped ? '<p id="zfsm-schedules-skipped" style="margin:.5em 0"><b>'+esc(preview.skipped)+'.</b></p>' : '';
	const unsent = copies.skipped ? '<p id="zfsm-copies-skipped" style="margin:.5em 0"><b>Replication as saved: '+esc(copies.skipped)+'.</b></p>' : '';
	// Each part's line for None, then for Default: what it follows now (zfsmScheduleDialogSync shows the one picked).
	const choice = (part, none, inherited) => '<p id="zfsm-'+part+'-none" style="margin:.5em 0;display:none">None: '+none+' A repeat set in Replicate still runs.</p>'
		+'<p id="zfsm-'+part+'-default" style="margin:.5em 0;display:none">Default follows '+esc(inherited)+'.</p>';
	const section = (name, html) => '<div role="group" aria-label="'+name+'" style="margin:0 0 1em">'+html+'</div>';
	const error = copies.servers_error ? 'zfsm-copies-server-error' : '';
	const server = (copies.servers.length ? '<label style="display:block">Server:'+zfsmServerSelect('zfsm-copies-server', copies.servers, copies.server, error)+'</label>' : '')
		+(error ? '<span id="'+error+'" style="display:block;color:#e22828">'+esc(copies.servers_error)+'</span>' : '');
	// Where, above when: a server picked hides Copy Keeps below it.
	const where = (server ? '<p id="zfsm-copies-server-row" style="margin:.5em 0;display:none">'+server+'</p>' : '')
		+'<p id="zfsm-copies-under-row" style="margin:.5em 0;display:none"><label style="display:block">Copy Under:<input id="zfsm-copies-under" type="text" maxlength="255" style="width:100%" placeholder="backup" value="'+esc(copies.under)+'" aria-describedby="zfsm-copies-under-hint"></label>'
		// Sync shows the one hint for the server picked; only a shown one is read out.
		+'<span id="zfsm-copies-under-hint">'+zfsmHint('zfsm-copies-under-here', 'A dataset that already exists on this server, in another pool: tank/data is copied to backup/tank/data.', 'replication')
		+zfsmHint('zfsm-copies-under-there', 'Inside the dataset that server keeps for this server\'s copies; a run makes it if it is missing.', 'copies-on-another-server')+'</span></p>';
	return '<div style="text-align:left">'+skipped
		+section('Snapshots', zfsmRepeatHtml('zfsm-schedules', '<b>Snapshots:</b>', choices, preview)+choice('schedules', 'no scheduled snapshots of this dataset, or of those below it that follow it.', preview.inherited))
		+section('Replication', zfsmRepeatHtml('zfsm-copies', '<b>Replication:</b>', choices, copies, true, where)
			+choice('copies', 'no scheduled copies of this dataset, or of those below it that follow it.', copies.inherited)+unsent)
		+say('Datasets below this one follow its choice, unless they have their own. Read-only datasets, those mounted legacy and those with Sanoid or Syncoid snapshots are left out. '+zfsmGuideLink('datasets-left-out'))+'</div>';
}

// Shows the rows that fit each choice; Snapshots' value.
function zfsmScheduleDialogSync(root) {
	const show = (id, shown) => { root.querySelector(id).style.display = shown ? '' : 'none'; };
	const every = zfsmRepeatSync(root, 'zfsm-schedules');
	show('#zfsm-schedules-none', every === 'none');
	show('#zfsm-schedules-default', every === 'default');
	const server = root.querySelector('#zfsm-copies-server');
	const here = !server || server.value === '';
	const copies = zfsmRepeatSync(root, 'zfsm-copies', here);
	const repeats = zfsmRepeats(copies);
	show('#zfsm-copies-none', copies === 'none');
	show('#zfsm-copies-default', copies === 'default');
	show('#zfsm-copies-under-row', repeats);
	show('#zfsm-copies-under-here', here);
	show('#zfsm-copies-under-there', !here);
	const row = root.querySelector('#zfsm-copies-server-row');
	if (row) row.style.display = repeats ? '' : 'none';
	return every;
}

// What the dialog holds, as the scheduledataset route reads it: Snapshots' rows, and Replication's as copy_*, under and server.
function zfsmScheduleDialogChoices(root) {
	const copies = zfsmRepeatChoices(root, 'zfsm-copies');
	const server = root.querySelector('#zfsm-copies-server');
	return Object.assign(zfsmRepeatChoices(root, 'zfsm-schedules'), {copy_every: copies.every, copy_time: copies.time, copy_days: copies.days, copy_keep: copies.keep,
		copy_keeps: copies.copy_keeps, under: root.querySelector('#zfsm-copies-under').value, server: server ? server.value : ''});
}
