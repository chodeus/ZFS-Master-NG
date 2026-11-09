// The review shown before a destroy or rollback. Names are user-controlled, so everything is escaped here.
function zfsmImpactHtml(preview, heading) {
	const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
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
	if (preview.error) return warn(String(preview.error).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])));
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
