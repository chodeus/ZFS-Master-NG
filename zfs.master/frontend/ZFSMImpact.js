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
	const warn = text => '<p style="color:#e22828;margin:.5em 0">'+text+'</p>';
	if (preview.error) return warn(String(preview.error).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])));
	let html = say('This writes every file in the dataset again, in place, so a changed compression, checksum, dedup or copies setting applies to the data already there. A changed record size is not applied.');
	html += warn('Snapshots and clones keep the old blocks, so the pool can use up to the size of these files again until those snapshots are deleted. This dataset\'s snapshots hold '+zfsmImpactSize(preview.snapshot_space)+' now.');
	html += preview.physical
		? say('Blocks keep their birth times, so the next incremental send stays small.')
		: warn('This pool can\'t keep blocks\' birth times (its physical_rewrite feature is off), so the next incremental send of this dataset carries all of its data again.');
	return html+say('It runs in the background. The row shows how many files are done, and a notification says when it finishes.');
}

function zfsmImpactSummary(preview, verb) {
	if (!preview.objects || !preview.objects.length) return '';
	const frees = preview.reclaim === null || preview.reclaim === undefined ? '' : ' and frees about '+zfsmImpactSize(preview.reclaim);
	return 'This permanently '+verb+' '+preview.objects.length+' item(s)'+frees+':';
}

function zfsmImpactSize(bytes) {
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
	let value = Number(bytes) || 0;
	let unit = 0;
	while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
	return (unit ? value.toFixed(2) : value)+' '+units[unit];
}
