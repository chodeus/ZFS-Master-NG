/* The Main tab's diagnostics record (zfsmDiagRecord) and the icon offsets it keeps. */

// What the Main tab last showed, for a diagnostics run to send (ZFSMDiagnostics.js); make gets the part's last entry. Strings are cut
// to 200, lists to 40, errors to 8,000 characters of JSON oldest first, and render's headers until it is 3,000 (the report keeps 50 KB).
function zfsmDiagRecord(part, make) {
	try {
		const record = zfsmDiagBounded(loadFromLocalStorage('zfsm-diagnostics') || {});
		const entry = zfsmDiagBounded(make(record[part]));
		record[part] = part === 'errors' ? (record.errors || []).concat([entry]).slice(-20) : entry;
		while (JSON.stringify(record.errors || []).length > 8000) record.errors.shift();
		while (record.render && JSON.stringify(record.render).length > 3000 && (record.render.headers || []).length) record.render.headers.pop();
		saveToLocalStorage('zfsm-diagnostics', record);
	} catch (error) {}
}

// Each icon kind's middle against its name's first line in CSS px, down positive, in the first row that shows it. Kinds that sit
// level read alike; one far from the rest was moved by another sheet's rule.
function zfsmIconOffsets(last) {
	const offsets = {};
	try {
		const table = document.getElementById('zfs_master');
		// A Main tab section not shown (Unraid's tabbed view) has no box: keep what the last render on screen measured.
		if (!table.getClientRects().length) return last || {};
		for (const label of table.querySelectorAll('tbody .zfsm-name-label')) {
			const kinds = Array.from(label.querySelectorAll('i.fa'), icon => [icon, Array.from(icon.classList).find(name => name.startsWith('fa-'))])
				.filter(([, kind]) => kind && !(kind in offsets));
			const name = Array.from(label.childNodes).filter(node => node.nodeType === 3 && node.textContent.trim()).pop();
			if (!kinds.length || !name) continue;
			const range = document.createRange();
			range.selectNodeContents(name);
			const line = range.getClientRects()[0];
			for (const [icon, kind] of kinds) {
				const box = icon.getBoundingClientRect();
				// A folded row's icons, and one a sheet hides, have no box: they are not shown.
				if (box.height) offsets[kind] = Math.round((box.top + box.height / 2 - line.top - line.height / 2) * 10) / 10;
			}
		}
	} catch (error) {
		offsets.unmeasured = String(error);
	}
	return offsets;
}

function zfsmDiagBounded(value) {
	if (typeof value === 'string') return value.slice(0, 200);
	if (Array.isArray(value)) return value.slice(0, 40).map(zfsmDiagBounded);
	if (value === null || typeof value !== 'object') return value;
	const bounded = {};
	for (const key of Object.keys(value)) bounded[key] = zfsmDiagBounded(value[key]);
	return bounded;
}
