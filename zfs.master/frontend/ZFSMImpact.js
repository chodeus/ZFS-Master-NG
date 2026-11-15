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
	const failed = reason => show({error: reason+after});
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
	const options_of = (names, label) => names.map((name, value) => '<option value="'+value+'">'+label(name, value)+'</option>').join('');
	const hours = options_of(Array.from({length: 24}), (name, hour) => String(hour).padStart(2, '0')+':00 to '+String(hour).padStart(2, '0')+':59');
	const days = options_of(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], name => name);
	html += row('Repeat:', '<select id="zfsm-replicate-every" style="width:100%"><option value="">Once</option><option value="hourly">Every hour</option><option value="daily">Every day</option><option value="weekly">Every week</option></select>');
	html += '<p id="zfsm-replicate-day-row" style="margin:.5em 0;display:none"><label style="display:block">On:<select id="zfsm-replicate-day" style="width:100%">'+days+'</select></label></p>';
	html += '<p id="zfsm-replicate-hour-row" style="margin:.5em 0;display:none"><label style="display:block">Starts between:<select id="zfsm-replicate-hour" style="width:100%">'+hours+'</select></label></p>';
	html += '<div id="zfsm-replicate-plan" aria-live="polite"></div>';
	return '<div style="text-align:left">'+html+'<p style="margin:.5em 0">It runs in the background: the row shows how far it got, and a notification says when it finishes. A bookmark keeps the next one incremental, even after this snapshot is deleted.</p>'
		+'<p style="margin:.5em 0">A repeat runs now, then each hour, day or week at a minute of its own, in this server\'s time zone (the answer and Settings show when), taking a new snapshot each run. Settings lists the schedules. After this run, a notification comes only for the first problem in a row, and for the run that works again after it.</p></div>';
}

// Repeat's rows: the day for a weekly run, the hour for a daily or weekly one. A repeat takes its own snapshot, so Snapshot shows Take one now.
function zfsmReplicateRepeatSync(root) {
	const every = root.querySelector('#zfsm-replicate-every').value;
	root.querySelector('#zfsm-replicate-day-row').style.display = every === 'weekly' ? '' : 'none';
	root.querySelector('#zfsm-replicate-hour-row').style.display = every === 'daily' || every === 'weekly' ? '' : 'none';
	const snapshot = root.querySelector('#zfsm-replicate-snapshot');
	if (every !== '') snapshot.value = '';
	snapshot.disabled = every !== '';
	return every;
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
