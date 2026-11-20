// Everything a dialog shows from ZFS is escaped: dataset, snapshot and folder names are user-controlled.
function zfsmEscape(value) {
	return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}

// The review shown before a destroy or rollback. Names are user-controlled, so everything is escaped here.
function zfsmImpactHtml(preview, heading) {
	const esc = zfsmEscape;
	const list = items => {
		const shown = items.slice(0, 50).map(item => '<li style="overflow-wrap:anywhere">'+esc(item)+'</li>').join('');
		const more = items.length > 50 ? '<li>…and '+(items.length - 50)+' more</li>' : '';
		return '<ul style="text-align:left;max-height:12em;overflow:auto;margin:.5em 0;padding-left:1.5em">'+shown+more+'</ul>';
	};
	const warn = text => '<p style="color:#e22828;margin:.5em 0">'+text+'</p>';
	if (preview.error) return warn(esc(preview.error));
	let html = preview.boot_pool ? warn('This is on '+esc(preview.boot_pool)+', the pool Unraid boots from. A mistake here can stop the server from starting.') : '';
	html += heading ? '<p style="margin:.5em 0">'+esc(heading)+'</p>' : '';
	const items = preview.objects || preview.newer || [];
	if (items.length) html += list(items);
	if (preview.automatic > 0) html += warn(esc(preview.automatic)+' of these '+(preview.automatic === 1 ? 'was' : 'were')+' made by Sanoid or Syncoid. Replication may need them.');
	if (preview.clones && preview.clones.length) html += warn('These clones depend on it. Promote or remove them first:')+list(preview.clones);
	if (preview.held && preview.held.length) html += warn('These snapshots have a hold. Release them first:')+list(preview.held);
	return html;
}

// Every way a review can fail ends in show({error}), so a dialog always says why it can't go ahead.
// A read-only caller passes '' for after: "Nothing was changed" would read as its answer.
function zfsmFetchReview(url, data, show, after = ' Nothing was changed.') {
	const failed = reason => show({error: after === '' || /[.!?]$/.test(reason) ? reason+after : reason+'.'+after});
	$.post(url, data, function(answer) {
		let preview;
		try {
			preview = typeof answer === 'string' ? JSON.parse(answer) : answer;
		} catch (error) {
			return failed('The answer could not be read.');
		}
		if (!preview || preview.failed) return failed(Object.values((preview || {}).failed || {}).join(' ') || 'The answer could not be read.');
		show(preview);
	}).fail(() => failed('The request failed.'));
}

// The review shown before a rewrite, from zfsmRewritePreview in include/ZFSMRewrite.php.
function zfsmRewriteHtml(preview) {
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	const warn = (text, id) => '<p'+(id ? ' id="'+id+'"' : '')+' style="color:#e22828;margin:.5em 0">'+text+'</p>';
	if (preview.error) return warn(zfsmEscape(preview.error));
	const held = preview.snapshot_space === null || preview.snapshot_space === undefined ? 'an amount that could not be read' : zfsmSize(preview.snapshot_space);
	let html = say('This writes every file in the dataset again, in place, so a changed compression, checksum, dedup or copies setting applies to the data already there. A changed record size is not applied.');
	html += warn('Snapshots and clones keep the old blocks, so the pool can use up to the size of these files again until those snapshots are deleted. This dataset\'s snapshots hold '+held+' now.');
	// -P is the user's choice: its first use turns the pool's feature on for good (zfsmRewriteFeature).
	if (preview.physical_feature) {
		html += say('<label><input type="checkbox" id="zfsm-rewrite-physical"'+(preview.physical_feature === 'active' ? ' checked' : '')+'> Keep blocks\' birth times (-P), so the next incremental send stays small</label>');
		if (preview.physical_feature === 'enabled') html += warn('This turns the pool\'s physical_rewrite feature on for good: Unraid before 7.3 (OpenZFS before 2.4) could then import this pool read-only only.', 'zfsm-rewrite-activate');
	}
	html += warn('Without kept birth times, the next incremental send of this dataset carries all of its data again.', 'zfsm-rewrite-resend');
	return html+say('It runs in the background. The row shows how many files are done, and a notification says when it finishes.');
}

// Shows the warning that fits the box: the feature turned on for good when ticked, a full next send when not.
function zfsmRewriteSync(root) {
	const box = root.querySelector('#zfsm-rewrite-physical');
	const keep = !!(box && box.checked);
	const activate = root.querySelector('#zfsm-rewrite-activate');
	if (activate) activate.style.display = keep ? '' : 'none';
	const resend = root.querySelector('#zfsm-rewrite-resend');
	if (resend) resend.style.display = keep ? 'none' : '';
	return keep;
}

// The Replicate dialog, from zfsmReplicatePreview in include/ZFSMReplicaPlan.php; zfsmReplicatePlanHtml fills its plan as the choices change.
function zfsmReplicateHtml(options, zdataset) {
	const esc = zfsmEscape;
	// The control inside its label names it for screen readers.
	const row = (label, field) => '<p style="margin:.5em 0"><label style="display:block">'+label+field+'</label></p>';
	const snapshots = (options.snapshots || []).map(name => '<option value="'+esc(name)+'">'+esc(name)+'</option>').join('');
	let html = row('Snapshot:', '<select id="zfsm-replicate-snapshot" style="width:100%">'+snapshots+'<option value="">Take one now</option></select>');
	const servers = options.servers || [];
	const last = options.last_destination || null;
	// The last copy's server, while this one still trusts it.
	const server = last && servers.includes(last.server) ? last.server : '';
	if (servers.length) {
		const choices = servers.map(name => '<option value="'+esc(name)+'"'+(name === server ? ' selected' : '')+'>'+esc(name)+'</option>').join('');
		html += row('Server:', '<select id="zfsm-replicate-server" style="width:100%"><option value="">This server</option>'+choices+'</select>');
	}
	if (options.servers_error) html += '<p style="color:#e22828;margin:.5em 0">'+esc(options.servers_error)+'</p>';
	html += row('Copy goes to:', '<input id="zfsm-replicate-dest" type="text" style="width:100%" placeholder="'+(server === '' ? 'pool/dataset' : 'name')+'" value="'+esc(zfsmReplicateName(last, server, zdataset))+'">');
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	html += zfsmRepeatHtml('zfsm-replicate', 'Repeat:', [['', 'Once'], ['hourly', 'Every hour'], ['days', 'On days of the week']], {}, true);
	html += '<div id="zfsm-replicate-plan" aria-live="polite"></div>';
	return '<div style="text-align:left">'+html+say('It runs in the background: the row shows how far it got, and a notification says when it finishes.')
		+'<p id="zfsm-replicate-repeat-help" style="margin:.5em 0;display:none">A repeat runs now, then at its time, and takes a new snapshot each run; Settings lists it. On another server, that server\'s Keep Replicas decides what the copy keeps. '+zfsmGuideLink('replicate-repeats')+'</p></div>';
}

// A link to a section of the wiki's Schedules guide, opened in a new tab; frontend_impact_test lists the sections these link to.
function zfsmGuideLink(section) {
	return '<a href="https://github.com/chodeus/ZFS-Master-NG/wiki/Schedules#'+section+'" target="_blank" rel="noopener noreferrer">Guide</a>';
}

// A field's one-line hint, with the guide's section on it; the field names it in aria-describedby.
function zfsmHint(id, text, section) {
	return '<span id="'+id+'" style="display:block;font-size:.9em;opacity:.8;margin-top:.2em">'+text+' '+zfsmGuideLink(section)+'</span>';
}

// The Schedules and Replicate forms: wider than a message, for their fields and hints; a phone's screen still caps it.
function zfsmFormDialog(options) {
	return Swal2.fire(Object.assign({width: '44em'}, options));
}

// Repeat's select of choices ([value, text]) and rows (zfsmRepeatSync), ids starting with prefix, with copies Copy Keeps' too; start:
// what they show first (zfsmScheduleDialogFields, zfsmScheduleDialogCopies' copy_keeps too), else the first choice at 00:00 daily.
function zfsmRepeatHtml(prefix, label, choices, start = {}, copies = false) {
	const esc = zfsmEscape;
	const ticked = start.days || [0, 1, 2, 3, 4, 5, 6];
	// Monday first; all seven ticked runs every day.
	const days = [1, 2, 3, 4, 5, 6, 0].map(day => '<label style="display:inline-block;margin-right:.8em;white-space:nowrap"><input type="checkbox" class="'+prefix+'-day" value="'+day+'"'+(ticked.includes(day) ? ' checked' : '')+'> '
		+['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][day]+'</label>').join('');
	const options = choices.map(([value, text]) => '<option value="'+value+'"'+(value === start.every ? ' selected' : '')+'>'+text+'</option>').join('');
	return '<p style="margin:.5em 0"><label style="display:block">'+label+'<select id="'+prefix+'-every" style="width:100%">'+options+'</select></label></p>'
		+'<div id="'+prefix+'-days-row" role="group" aria-label="Days" style="margin:.5em 0;display:none">Days:<br>'+days+'</div>'
		+'<p id="'+prefix+'-time-row" style="margin:.5em 0;display:none"><label style="display:block">Starts at (this server\'s time):<input id="'+prefix+'-time" type="time" value="'+esc(start.time || '00:00')+'" style="width:100%"></label></p>'
		+'<p id="'+prefix+'-keep-row" style="margin:.5em 0;display:none"><label style="display:block">Snapshots to keep:<input id="'+prefix+'-keep" type="text" maxlength="40" style="width:100%" placeholder="all" value="'+esc(start.keep)+'" aria-describedby="'+prefix+'-keep-hint"></label>'
		+zfsmHint(prefix+'-keep-hint', 'A number keeps the newest that many; 7d 4w 12m keep the newest of each of the last 7 days, 4 weeks and 12 months that have one. Empty keeps all.', 'snapshots-to-keep')+'</p>'
		+(copies ? '<p id="'+prefix+'-copy-keeps-row" style="margin:.5em 0;display:none"><label style="display:block">Copy Keeps:<input id="'+prefix+'-copy-keeps" type="text" maxlength="40" style="width:100%" placeholder="same as source" value="'+esc(start.copy_keeps)+'" aria-describedby="'+prefix+'-copy-keeps-hint"></label>'
			+zfsmHint(prefix+'-copy-keeps-hint', 'Empty: the copy loses what Snapshots to keep removes here. Type all to keep every snapshot in it, or rules such as 30d 12m, which count every snapshot in it.', 'copy-keeps')+'</p>' : '');
}

// Shows the rows that fit Repeat's choice: Days and Starts at for days of the week, Snapshots to keep for either repeat, and Copy Keeps
// with it while the copy is here (local). Its value.
function zfsmRepeatSync(root, prefix, local = true) {
	const every = root.querySelector('#'+prefix+'-every').value;
	const show = (id, shown) => { root.querySelector('#'+prefix+id).style.display = shown ? '' : 'none'; };
	const repeats = every === 'hourly' || every === 'days';
	show('-keep-row', repeats);
	show('-days-row', every === 'days');
	show('-time-row', every === 'days');
	// Only Replicate and Replication have a Copy Keeps row.
	const copy = root.querySelector('#'+prefix+'-copy-keeps-row');
	if (copy) copy.style.display = local && repeats ? '' : 'none';
	return every;
}

// What Repeat's rows hold, as the server reads them; Copy Keeps only while it shows, else '' (none).
function zfsmRepeatChoices(root, prefix) {
	const copy = root.querySelector('#'+prefix+'-copy-keeps-row');
	return {every: root.querySelector('#'+prefix+'-every').value, time: root.querySelector('#'+prefix+'-time').value,
		days: Array.from(root.querySelectorAll('.'+prefix+'-day:checked'), box => box.value).join(','), keep: root.querySelector('#'+prefix+'-keep').value.trim(),
		copy_keeps: copy && copy.style.display !== 'none' ? root.querySelector('#'+prefix+'-copy-keeps').value.trim() : ''};
}

// Repeat's rows, Copy Keeps only for This server, and its help for any repeat. A repeat takes its own snapshot, so Snapshot shows Take one now.
function zfsmReplicateRepeatSync(root) {
	const server = root.querySelector('#zfsm-replicate-server');
	const every = zfsmRepeatSync(root, 'zfsm-replicate', !server || server.value === '');
	root.querySelector('#zfsm-replicate-repeat-help').style.display = every !== '' ? '' : 'none';
	const snapshot = root.querySelector('#zfsm-replicate-snapshot');
	if (every !== '') snapshot.value = '';
	snapshot.disabled = every !== '';
	return every;
}

// The Schedules dialog, from zfsmScheduleDialogPreview in include/ZFSMScheduleDialog.php: why no run takes the dataset, if so; for its
// snapshots and its copies (preview.copies) Default (what it follows now), None, or its own; zfsmScheduleDialogSync shows the rows that fit.
function zfsmScheduleDialogHtml(preview) {
	const esc = zfsmEscape;
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	const copies = preview.copies;
	const choices = [['default', 'Default'], ['none', 'None'], ['hourly', 'Every hour'], ['days', 'On days of the week']];
	const skipped = preview.skipped ? '<p id="zfsm-schedules-skipped" style="margin:.5em 0"><b>'+esc(preview.skipped)+'.</b></p>' : '';
	const unsent = copies.skipped ? '<p id="zfsm-copies-skipped" style="margin:.5em 0"><b>Replication as saved: '+esc(copies.skipped)+'.</b></p>' : '';
	// Each part's line for None, then for Default: what it follows now (zfsmScheduleDialogSync shows the one picked).
	const choice = (part, none, inherited) => '<p id="zfsm-'+part+'-none" style="margin:.5em 0;display:none">None: '+none+' A repeat set in Replicate still runs.</p>'
		+'<p id="zfsm-'+part+'-default" style="margin:.5em 0;display:none">Default follows '+esc(inherited)+'.</p>';
	const section = (name, html) => '<div role="group" aria-label="'+name+'" style="margin:0 0 1em">'+html+'</div>';
	return '<div style="text-align:left">'+skipped
		+section('Snapshots', zfsmRepeatHtml('zfsm-schedules', '<b>Snapshots:</b>', choices, preview)+choice('schedules', 'no scheduled snapshots of this dataset, or of those below it that follow it.', preview.inherited))
		+section('Replication', zfsmRepeatHtml('zfsm-copies', '<b>Replication:</b>', choices, copies, true)
			+'<p id="zfsm-copies-under-row" style="margin:.5em 0;display:none"><label style="display:block">Copy Under:<input id="zfsm-copies-under" type="text" maxlength="255" style="width:100%" placeholder="backup" value="'+esc(copies.under)+'" aria-describedby="zfsm-copies-under-hint"></label>'
			+zfsmHint('zfsm-copies-under-hint', 'A dataset that already exists on this server, in another pool: tank/data is copied to backup/tank/data.', 'replication')+'</p>'
			+choice('copies', 'no scheduled copies of this dataset, or of those below it that follow it.', copies.inherited)+unsent)
		+say('Datasets below this one follow its choice, unless they have their own. Read-only datasets, those mounted legacy and those with Sanoid or Syncoid snapshots are left out. '+zfsmGuideLink('datasets-left-out'))+'</div>';
}

// Shows the rows that fit each choice; Snapshots' value.
function zfsmScheduleDialogSync(root) {
	const show = (id, shown) => { root.querySelector(id).style.display = shown ? '' : 'none'; };
	const every = zfsmRepeatSync(root, 'zfsm-schedules');
	show('#zfsm-schedules-none', every === 'none');
	show('#zfsm-schedules-default', every === 'default');
	const copies = zfsmRepeatSync(root, 'zfsm-copies');
	show('#zfsm-copies-none', copies === 'none');
	show('#zfsm-copies-default', copies === 'default');
	show('#zfsm-copies-under-row', copies === 'hourly' || copies === 'days');
	return every;
}

// What the dialog holds, as the scheduledataset route reads it: Snapshots' rows, and Replication's as copy_* and under.
function zfsmScheduleDialogChoices(root) {
	const copies = zfsmRepeatChoices(root, 'zfsm-copies');
	return Object.assign(zfsmRepeatChoices(root, 'zfsm-schedules'), {copy_every: copies.every, copy_time: copies.time, copy_days: copies.days, copy_keep: copies.keep,
		copy_keeps: copies.copy_keeps, under: root.querySelector('#zfsm-copies-under').value});
}

// What Copy goes to holds for a server: the last copy's dataset here, or its name inside the area there; another server's copy
// is named for the dataset's last part.
function zfsmReplicateName(last, server, zdataset) {
	const dest = last && last.server === server ? last.dest : (server === '' ? '' : String(zdataset || ''));
	return server === '' ? dest : dest.slice(dest.lastIndexOf('/') + 1);
}

function zfsmReplicatePlanHtml(plan) {
	const esc = zfsmEscape;
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	if (plan.error) return '<p style="color:#e22828;margin:.5em 0">'+esc(plan.error)+'</p>';
	// A trusted server's area, from its hello: the copy is one name inside it.
	const area = plan.area ? say('On '+esc(plan.server)+', copies go inside '+esc(plan.area)+'.') : '';
	if (plan.can_proceed !== true) return area;
	const size = plan.size === null || plan.size === undefined ? '' : ', about '+zfsmSize(plan.size);
	let html = say(plan.mode === 'resume' ? 'Carries on from where the last run to this copy stopped'+size+'.'
		: plan.mode === 'incremental' ? 'Sends only the changes since '+esc(plan.since)+size+'.'
		: 'The first replication sends everything'+size+'.');
	if (plan.raw) html += say('Sent raw: the copy stays locked with this dataset\'s key.');
	if (plan.holder) html += say('It replaces the empty dataset there, made to hold the copies below it, which stay.');
	html = area+html;
	// An incremental or resumed receive into a copy here that has a /dev/zvol device removes it (volmode=none, zfsmReplicateReceiveArgs);
	// one on another server never had one (zfsmReplicaAreaProps).
	return html+say(plan.type === 'volume' ? 'The copy is read-only and has no /dev/zvol device'+(plan.mode !== 'full' && !plan.server ? ' (one it has now is removed)' : '')+', so nothing changes it between replications.'
		: 'The copy is unmounted and read-only, so nothing changes it between replications.');
}

function zfsmImpactSummary(preview, verb) {
	if (!preview.objects || !preview.objects.length) return '';
	const frees = preview.reclaim === null || preview.reclaim === undefined ? '' : ' and frees about '+zfsmSize(preview.reclaim);
	return 'This permanently '+verb+' '+preview.objects.length+' item(s)'+frees+':';
}

// The Convert dialog: what happens to the folder, whether the files are cloned, and what a first clone turns on (zfsmConvertPreview).
function zfsmConvertHtml(preview) {
	var html = 'The folder is renamed to &lt;name&gt;_zfsm_tmp_&lt;date&gt;, a dataset is created in its place, and the files are copied into it. <b>The renamed folder is removed only after the copy is checked, so no data is lost.</b> ';
	const why = {'encrypted': 'the dataset will be encrypted', 'switched off': 'block cloning is switched off on this server (zfs_bclone_enabled)'};
	html += preview.clones
		? 'The files are cloned, which usually needs almost no extra space; a file that can\'t be cloned is copied, and needs free space for its copy.'
		: 'The files are copied, not cloned, because '+(why[preview.blocker] || 'this pool has no block cloning')+', so the pool needs free space for a second copy while this runs.';
	if (preview.turns_on) {
		html += '<p id="zfsm-convert-turns-on">This pool hasn\'t cloned files before, so this can turn on its block cloning. While cloned blocks remain, for example while a snapshot holds the original files, Unraid before '+escapeHtml(preview.turns_on)+' can import this pool read-only only. Copying files within the pool, such as with cp, does the same.</p>';
	}
	return html;
}
