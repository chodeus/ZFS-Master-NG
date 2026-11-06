// The changes dialog in Snapshots Admin, from zfsmSnapshotDiff in ZFSMSnapshotFiles.php. File names are user-controlled, so everything is escaped.
function zfsmDiffHtml(diff) {
	const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
	const changes = {'+': 'Added', '-': 'Removed', 'M': 'Modified', 'R': 'Renamed'};
	const types = {'F': 'File', '/': 'Folder', '@': 'Link', '=': 'Socket', '|': 'Pipe', 'B': 'Block device', 'C': 'Character device'};
	if (diff.error) return '<p style="color:#e22828;margin:.5em 0">'+esc(diff.error)+'</p>';
	const list = diff.changes || [];
	if (!list.length) return '<p style="margin:.5em 0">Nothing has changed since this snapshot.</p>';
	const count = diff.truncated ? 'Showing the first '+list.length+' changes.' : list.length+(list.length === 1 ? ' change' : ' changes')+' since this snapshot.';
	const rows = list.map(item => '<tr><td>'+esc(changes[item.change] || item.change)+'</td><td>'+esc(types[item.type] || 'Other')+'</td>'
		+'<td style="overflow-wrap:anywhere">'+esc(item.path)+(item.to ? ' → '+esc(item.to) : '')+'</td></tr>').join('');
	return '<p style="margin:.5em 0">'+count+'</p><div style="text-align:left;max-height:24em;overflow:auto">'
		+'<table style="width:100%"><thead><tr><th>Change</th><th>Type</th><th>Path</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
}
