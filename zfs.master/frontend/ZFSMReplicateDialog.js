// The Replicate dialog: its form, its Repeat rows, what Copy goes to holds, and its plan.

// The Replicate dialog, from zfsmReplicatePreview in include/ZFSMReplicaPlan.php; zfsmReplicatePlanHtml fills its plan as the choices change.
function zfsmReplicateHtml(options, zdataset) {
	const esc = zfsmEscape;
	// The control inside its label names it for screen readers.
	const row = (label, field) => '<p style="margin:.5em 0"><label style="display:block">'+label+field+'</label></p>';
	const snapshots = (options.snapshots || []).map(name => '<option value="'+esc(name)+'">'+esc(name)+'</option>').join('');
	let html = (options.warning ? zfsmWarningHtml(options.warning, 'zfsm-replicate-warning') : '')
		+row('Snapshot:', '<select id="zfsm-replicate-snapshot" style="width:100%">'+snapshots+'<option value="">Take one now</option></select>');
	const servers = options.servers || [];
	const last = options.last_destination || null;
	// The last copy's server, while this one still trusts it.
	const server = last && servers.includes(last.server) ? last.server : '';
	if (servers.length) html += row('Server:', zfsmServerSelect('zfsm-replicate-server', servers, server, options.servers_error ? 'zfsm-replicate-server-error' : ''));
	if (options.servers_error) html += '<p id="zfsm-replicate-server-error" style="color:#e22828;margin:.5em 0">'+esc(options.servers_error)+'</p>';
	html += row('Copy goes to:', '<input id="zfsm-replicate-dest" type="text" style="width:100%" placeholder="'+(server === '' ? 'pool/dataset' : 'name')+'" value="'+esc(zfsmReplicateName(last, server, zdataset))+'">');
	// Only an encrypted dataset's copy can go either way (zfsmReplicaPlanCrypt).
	if (options.encrypted) html += '<p style="margin:.5em 0"><label style="display:block">Send:<select id="zfsm-replicate-send" aria-describedby="zfsm-replicate-send-hint" style="width:100%">'
		+'<option value="">Raw: the copy stays encrypted with this dataset\'s key</option><option value="1">Decrypted: the copy is plain, or takes the key of the dataset it goes into</option></select></label>'
		+zfsmHint('zfsm-replicate-send-hint', 'Decrypted needs this dataset\'s key loaded.', 'encrypted-datasets')+'</p>';
	const say = text => '<p style="margin:.5em 0">'+text+'</p>';
	html += zfsmRepeatHtml('zfsm-replicate', 'Repeat:', [['', 'Once'], ['hourly', 'Every hour'], ['days', 'On days of the week']], {}, true);
	html += '<p id="zfsm-replicate-remake-row" style="margin:.5em 0;display:none"><label><input id="zfsm-replicate-remake" type="checkbox"> If the copy is gone, make it again (sends everything)</label></p>';
	html += '<p id="zfsm-replicate-replace-row" style="margin:.5em 0'+(server === '' ? '' : ';display:none')+'"><label><input id="zfsm-replicate-replace" type="checkbox"> Replace a copy that no longer matches (it is renamed, not destroyed)</label></p>';
	html += '<div id="zfsm-replicate-plan" aria-live="polite"></div>';
	return '<div style="text-align:left">'+html+say('It runs in the background: the row shows how far it got, and a notification says when it finishes.')
		+'<p id="zfsm-replicate-repeat-help" style="margin:.5em 0;display:none">A repeat runs now, then at its time, and takes a new snapshot each run; Settings lists it. On another server, that server\'s Keep Replicas decides what the copy keeps. '+zfsmGuideLink('replicate-repeats')+'</p></div>';
}

// Repeat's rows, Copy Keeps only for This server, and its help and If the copy is gone for any repeat; Replace a copy for This server.
// A repeat takes its own snapshot, so Snapshot shows Take one now.
function zfsmReplicateRepeatSync(root) {
	const server = root.querySelector('#zfsm-replicate-server');
	const here = !server || server.value === '';
	const every = zfsmRepeatSync(root, 'zfsm-replicate', here);
	root.querySelector('#zfsm-replicate-replace-row').style.display = here ? '' : 'none';
	root.querySelector('#zfsm-replicate-repeat-help').style.display = every !== '' ? '' : 'none';
	root.querySelector('#zfsm-replicate-remake-row').style.display = every !== '' ? '' : 'none';
	const snapshot = root.querySelector('#zfsm-replicate-snapshot');
	if (every !== '') snapshot.value = '';
	snapshot.disabled = every !== '';
	return every;
}

// If the copy is gone, sent only ticked on a repeat (zfsmPostYes).
function zfsmReplicateRemake(root) {
	return root.querySelector('#zfsm-replicate-remake').checked && root.querySelector('#zfsm-replicate-remake-row').style.display !== 'none' ? {remake: '1'} : {};
}

// Replace a copy, sent only ticked for This server (zfsmPostYes).
function zfsmReplicateReplace(root) {
	return root.querySelector('#zfsm-replicate-replace').checked && root.querySelector('#zfsm-replicate-replace-row').style.display !== 'none' ? {replace: '1'} : {};
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
	let html = say(plan.replace ? (plan.newer ? esc(plan.newer) : 'The copy there no longer matches this dataset')+'. It is renamed to '+esc(plan.replace)+' (named for the time the run starts), and everything is sent again'+size+'.'
		: plan.mode === 'resume' ? 'Carries on from where the last run to this copy stopped'+size+'.'
		: plan.mode === 'incremental' ? 'Sends only the changes since '+esc(plan.since)+size+'.'
		: 'The first replication sends everything'+size+'.');
	// How an encrypted dataset goes: as picked, as the copy was made, or as the stopped run went (zfsmReplicaPlanCrypt).
	const way = plan.raw ? 'raw' : 'decrypted';
	const how = {choice: plan.raw ? 'Sent raw: the copy stays locked with this dataset\'s key.' : '', copy: 'Sent '+way+', as this copy was made; to change that, replicate to a new name.',
		token: 'Sent '+way+', as the run that stopped was.', old: esc(plan.server)+' runs an older ZFS Master NG, so this copy goes as Send says: keep Raw for a copy this plugin made, or update the plugin there.'}[plan.how];
	if (how) html += say(how);
	if (plan.holder) html += say('It replaces the empty dataset there, made to hold the copies below it, which stay.');
	html = area+html;
	// An incremental or resumed receive into a copy here that has a /dev/zvol device removes it (volmode=none, zfsmReplicateReceiveArgs);
	// one on another server never had one (zfsmReplicaAreaProps).
	return html+say(plan.type === 'volume' ? 'The copy is read-only and has no /dev/zvol device'+(plan.mode !== 'full' && !plan.server ? ' (one it has now is removed)' : '')+', so nothing changes it between replications.'
		: 'The copy is unmounted and read-only, so nothing changes it between replications.');
}
