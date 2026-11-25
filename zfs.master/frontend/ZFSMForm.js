// What the Schedules and Replicate forms share: the Server select, the Guide link, a warning, a hint, their wider dialog and Repeat's rows.

// A Server select: This server, then each of servers (names), selected picked; error: the id of the line saying why the list is short.
function zfsmServerSelect(id, servers, selected, error = '') {
	const esc = zfsmEscape;
	return '<select id="'+id+'"'+(error ? ' aria-describedby="'+error+'"' : '')+' style="width:100%"><option value="">This server</option>'
		+servers.map(name => '<option value="'+esc(name)+'"'+(name === selected ? ' selected' : '')+'>'+esc(name)+'</option>').join('')+'</select>';
}

// A link to a section of a wiki page, opened in a new tab; frontend_impact_test lists Schedules' sections, schedule_snapshot_test Warnings'.
function zfsmGuideLink(section, page = 'Schedules') {
	return '<a href="https://github.com/chodeus/ZFS-Master-NG/wiki/'+page+'#'+section+'" target="_blank" rel="noopener noreferrer">Guide</a>';
}

// A state a dialog warns of, amber as only errors are red: what it is, what that does, and its section of the wiki's Warnings page.
function zfsmWarningHtml(warning, id) {
	return '<div id="'+id+'" style="border-left:4px solid #f0a30a;padding:.2em .6em;margin:.5em 0"><b><i class="fa fa-exclamation-triangle" style="color:#f0a30a" aria-hidden="true"></i> '
		+zfsmEscape(warning.fact)+'</b><br>'+zfsmEscape(warning.impact)+'. '+zfsmGuideLink(zfsmEscape(warning.guide), 'Warnings')+'</div>';
}

// A field's one-line hint, with the guide's section on it; the field names it in aria-describedby.
function zfsmHint(id, text, section) {
	return '<span id="'+id+'" style="display:block;font-size:.9em;opacity:.8;margin-top:.2em">'+text+' '+zfsmGuideLink(section)+'</span>';
}

// The Schedules and Replicate forms: wider than a message, for their fields and hints; a phone's screen still caps it.
function zfsmFormDialog(options) {
	return Swal2.fire(Object.assign({width: '44em'}, options));
}

// Repeat's select of choices ([value, text]), then after, then its rows (zfsmRepeatSync), ids starting with prefix, with copies Copy Keeps'
// too; start: what they show first (zfsmScheduleDialogFields, a cron line as time; zfsmScheduleDialogCopies' copy_keeps), else the first choice at 00:00 daily.
function zfsmRepeatHtml(prefix, label, choices, start = {}, copies = false, after = '') {
	const esc = zfsmEscape;
	const cron = start.every === 'cron';
	const ticked = start.days || [0, 1, 2, 3, 4, 5, 6];
	// Monday first; all seven ticked runs every day.
	const days = [1, 2, 3, 4, 5, 6, 0].map(day => '<label style="display:inline-block;margin-right:.8em;white-space:nowrap"><input type="checkbox" class="'+prefix+'-day" value="'+day+'"'+(ticked.includes(day) ? ' checked' : '')+'> '
		+['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][day]+'</label>').join('');
	const options = choices.map(([value, text]) => '<option value="'+value+'"'+(value === start.every ? ' selected' : '')+'>'+text+'</option>').join('');
	return '<p style="margin:.5em 0"><label style="display:block">'+label+'<select id="'+prefix+'-every" style="width:100%">'+options+'</select></label></p>'+after
		+'<div id="'+prefix+'-days-row" role="group" aria-label="Days" style="margin:.5em 0;display:none">Days:<br>'+days+'</div>'
		+'<p id="'+prefix+'-time-row" style="margin:.5em 0;display:none"><label style="display:block">Starts at (this server\'s time):<input id="'+prefix+'-time" type="time" value="'+esc(cron ? '00:00' : start.time || '00:00')+'" style="width:100%"></label></p>'
		+'<p id="'+prefix+'-cron-row" style="margin:.5em 0;display:none"><label style="display:block">Cron line:<input id="'+prefix+'-cron" type="text" maxlength="255" style="width:100%" placeholder="*/15 * * * *" value="'+esc(cron ? start.time : '')+'" aria-describedby="'+prefix+'-cron-hint"></label>'
		+zfsmHint(prefix+'-cron-hint', 'Minute, hour, day of the month, month and day of the week, as in the User Scripts plugin\'s Custom schedule: <code>*/15 * * * *</code> runs every 15 minutes.', 'custom-times')+'</p>'
		+'<p id="'+prefix+'-keep-row" style="margin:.5em 0;display:none"><label style="display:block">Snapshots to keep:<input id="'+prefix+'-keep" type="text" maxlength="40" style="width:100%" placeholder="all" value="'+esc(start.keep)+'" aria-describedby="'+prefix+'-keep-hint"></label>'
		+zfsmHint(prefix+'-keep-hint', 'A number keeps the newest that many; 7d 4w 12m keep the newest of each of the last 7 days, 4 weeks and 12 months that have one. Empty keeps all.', 'snapshots-to-keep')+'</p>'
		+(copies ? '<p id="'+prefix+'-copy-keeps-row" style="margin:.5em 0;display:none"><label style="display:block">Copy Keeps:<input id="'+prefix+'-copy-keeps" type="text" maxlength="40" style="width:100%" placeholder="same as source" value="'+esc(start.copy_keeps)+'" aria-describedby="'+prefix+'-copy-keeps-hint"></label>'
			+zfsmHint(prefix+'-copy-keeps-hint', 'Empty: the copy loses what Snapshots to keep removes here. Type all to keep every snapshot in it, or rules such as 30d 12m, which count every snapshot in it.', 'copy-keeps')+'</p>' : '');
}

// Repeat's choices that run on a schedule: ZFSM_SCHEDULE_PERIODS' keys (ZFSMSchedule.php).
function zfsmRepeats(every) {
	return every === 'hourly' || every === 'days' || every === 'cron';
}

// Shows the rows that fit Repeat's choice: Days and Starts at for days of the week, Cron line for Custom (cron), Snapshots to keep for
// any repeat, and Copy Keeps with it while the copy is here (local). Its value.
function zfsmRepeatSync(root, prefix, local = true) {
	const every = root.querySelector('#'+prefix+'-every').value;
	const show = (id, shown) => { root.querySelector('#'+prefix+id).style.display = shown ? '' : 'none'; };
	const repeats = zfsmRepeats(every);
	show('-cron-row', every === 'cron');
	show('-keep-row', repeats);
	show('-days-row', every === 'days');
	show('-time-row', every === 'days');
	// Only Replicate and Replication have a Copy Keeps row.
	const copy = root.querySelector('#'+prefix+'-copy-keeps-row');
	if (copy) copy.style.display = local && repeats ? '' : 'none';
	return every;
}

// What Repeat's rows hold, as the server reads them: a cron line as time; Copy Keeps only while it shows, else '' (none).
function zfsmRepeatChoices(root, prefix) {
	const copy = root.querySelector('#'+prefix+'-copy-keeps-row');
	const every = root.querySelector('#'+prefix+'-every').value;
	return {every: every, time: root.querySelector('#'+prefix+(every === 'cron' ? '-cron' : '-time')).value,
		days: Array.from(root.querySelectorAll('.'+prefix+'-day:checked'), box => box.value).join(','), keep: root.querySelector('#'+prefix+'-keep').value.trim(),
		copy_keeps: copy && copy.style.display !== 'none' ? root.querySelector('#'+prefix+'-copy-keeps').value.trim() : ''};
}
