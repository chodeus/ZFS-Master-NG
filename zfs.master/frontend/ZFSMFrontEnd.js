//region utils

function escapeHtml(value) {
	return String(value ?? '').replace(/[&<>"']/g, function(char) {
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
	});
}

function safeJsArg(value) {
	return "decodeURIComponent('"+encodeURIComponent(String(value ?? '')).replace(/'/g, '%27')+"')";
}

// Row class for a dataset's descendants. Encoded so spaces stay one token; prefixed so a pool named "red" can't hit a theme class.
function datasetChildClass(name) {
	return 'zfsm-child-'+encodeURIComponent(String(name ?? ''));
}

function datasetRowId(name) {
	return 'tr-'+encodeURIComponent(String(name ?? ''));
}

// No other id holds '/': pool names can't, and dataset ids encode it. So a pool row keeps keyboard focus across a redraw.
function poolRowId(name) {
	return 'pool/'+encodeURIComponent(String(name ?? ''));
}

function snapshotCell(color, count) {
	const style = color ? ' style="color:'+escapeHtml(color)+'"' : '';
	return '<i class="fa fa-camera-retro icon"'+style+'></i><span class="zfsm-snap-count">'+escapeHtml(count)+'</span>';
}

// The name, then Actions, centred on one line however tall Unraid draws the button; divs, as 6.x indents every span in its tables.
function nameCell(label, actions) {
	return '<td class="zfsm-name"><div class="zfsm-name-row"><div class="zfsm-name-label">'+label+'</div>'+actions+'</div></td>';
}

// A dataset or folder row starts with its browse link, then the slot a copy or rewrite reports into (zfsmShowCopyProgress).
function rowLeadCell(browsePath) {
	// Unraid 7.2+ browses only /mnt and /boot; mirrors zfsmSnapshotBrowseRoot in ZFSMSnapshotFiles.php.
	const browse = typeof browsePath === 'string' && /^\/(?:mnt|boot)(?:\/|$)/.test(browsePath)
		? '<a class="zfsm-browse" href="/Main/Browse?dir='+encodeURIComponent(browsePath)+'"><i class="icon-u-tab zfs_bar_button" title="Browse '+escapeHtml(browsePath)+'"></i></a>'
		: '';
	return '<td class="zfsm-lead" colspan="2">'+browse+'<span class="zfsm-row-progress"></span></td>';
}

// Puts the refresh time and buttons on Unraid's own title line and opts it out of the reads/writes toggle.
// Tabbed pages have no title line, so the bar stays where it is.
function zfsmMoveStatusIntoTitle(bar) {
	let title = bar ? bar.previousElementSibling : null;
	// Unraid 6.9 and 6.11 mark it div#title; 6.12 and later use div.title.
	while (title && !(title.tagName === 'DIV' && (title.id === 'title' || title.classList.contains('title')))) title = title.previousElementSibling;
	if (!title) return false;
	title.classList.add('disable_diskio');
	title.classList.add('zfsm-title');
	let right = title.querySelector('span.right');
	if (!right) {
		right = document.createElement('span');
		right.className = 'right zfsm-title-right';
		title.appendChild(right);
	}
	const status = document.createElement('span');
	status.className = 'zfsm-title-status';
	while (bar.firstChild) status.appendChild(bar.firstChild);
	right.appendChild(status);
	bar.remove();
	return true;
}

function parseJSONResponse(value) {
	return typeof value === 'string' ? JSON.parse(value) : value;
}

// One id per name, safe in a # selector: a short checksum let two datasets share a menu button.
function elementToken(name) {
	return 'zfsm-'+encodeURIComponent(String(name ?? '')).replace(/[^A-Za-z0-9]/g, char => '_'+char.charCodeAt(0).toString(16));
}

function nl2br(str, is_xhtml) {
    if (typeof str === 'undefined' || str === null) {
        return '';
    }
    var breakTag = (is_xhtml || typeof is_xhtml === 'undefined') ? '<br />' : '<br>';
    return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1' + breakTag + '$2');
}

function fromStringToBytes(spacestr) {
	let returnNumber = parseFloat(spacestr);

	switch (spacestr.slice(-1)) {
	  case 'E':
		returnNumber *= 1024;
	  case 'P':
		returnNumber *= 1024;
	  case 'T':
		returnNumber *= 1024;
	  case 'G':
		returnNumber *= 1024;
	  case 'M':
		returnNumber *= 1024;
	  case 'K':
		returnNumber *= 1024;
	  default:
		break;
	}

	return returnNumber;
}

// zpool list prints short sizes (1.12T); rows print them in the Size Units setting. A faulted pool prints -.
function poolSizeText(value, decimals) {
	const text = String(value ?? '');
	const bytes = fromStringToBytes(text);
	return Number.isFinite(bytes) ? zfsmSize(bytes, decimals) : escapeHtml(text);
}

function calculateFreePercent(used, free) {
	const usedTmp = typeof used === "string" ? fromStringToBytes(used) : used;
	const freeTmp = typeof free === "string" ? fromStringToBytes(free) : free;

	const result = freeTmp / (freeTmp + usedTmp);
	return result * 100;
}

function implodeWithKeys(glue, array, symbol = ': ') {
    return Object.keys(array)
	  .map((key) => escapeHtml(key) + symbol + escapeHtml(array[key]))
	  .join(glue);
}

function daysToNow(timestamp) {
    const currentDate = new Date();
    const diffDate = new Date(timestamp * 1000);

    const timeDifference = currentDate - diffDate; 

    const daysDifference = Math.floor(timeDifference / (1000 * 60 * 60 * 24));

    return daysDifference;
}

function saveToLocalStorage(key, value, encode=true) {
	try {
		localStorage.setItem(key, encode ? JSON.stringify(value) : value);
	} catch (error) {
		console.warn('ZFS Master NG could not save its browser cache', error);
	}
}

function loadFromLocalStorage(key, decode=true) {
	try {
		const value = localStorage.getItem(key);
		return value === null ? null : (decode ? JSON.parse(value) : value);
	} catch (error) {
		console.warn('ZFS Master NG ignored an unavailable or invalid browser cache', error);
		return null;
	}
}

function removeFromLocalStorage(key) {
	try { localStorage.removeItem(key); } catch (error) {
		console.warn('ZFS Master NG could not clear its browser cache', error);
	}
}

function usage_color(percent, free, display) {
	if (display['text'] ==1 || parseInt(display['text']/10)==1)
		return '';

	if (!free) {
	  if (display['critical'] > 0 && percent >= display['critical'])
	  	return 'redbar';

	  if (display['warning'] > 0 && percent >= display['warning'])
	  	return 'orangebar';

	  return 'greenbar';
	} else {
	  if (display['critical'] > 0 && percent <= 100-display['critical'])
	  	return 'redbar';

	  if (display['warning'] > 0 && percent <= 100-display['warning'])
	  	return 'orangebar';

	  return 'greenbar';
	}
}

function hasDirectories(dataset) {
	if (dataset['directories'] === undefined || dataset['directories'] == null)
		return false;

	if (dataset['directories'].length <= 0)
		return false;

	return true;
}

function parseDirectoryCopy(msg) {
	const regex = /(?<data>[\d,]+)[\s\t]+(?<progress>[\d]+%)[\s\t]+(?<speed>[\d.]+[G|M|K|B]+\/s)[\s\t]+(?<time>[\d]+:[\d]+:[\d]+)/;
	
	const match = regex.exec(msg.replace(/(?:\\[rn])+/g, ""));
	
	if (match == null)
		return '';

	const groups = match.groups;

	groups['data'] = zfsmSize(groups['data'].replace(/,/g, ''));

	return '<div class="usage-disk"><span style="margin:0;width:'+groups['progress']+'" class="greenbar"></span><span>'+groups['data']+' - '+groups['speed']+' - '+groups['time']+'</span></div>';
}

//endregion utils


function getLastSnap(zsnapshots) {
	var lastsnap = zsnapshots[0];

	for (const snap of zsnapshots) {
		if (snap['creation'] > lastsnap['creation']) {
			lastsnap = snap;
		}
	}

	return lastsnap;
}

function getPoolStatusColor(status) {
	switch(status) {
		case 'ONLINE':
			return 'green';
		case 'DEGRADED':
			return 'yellow';
		case 'FAULTED':
			return 'red';
		case 'OFFLINE':
			return 'blue';
		case 'UNAVAIL':
			return 'grey';
		case 'REMOVED':
			return 'grey';
	}

	return 'grey';
}

function getPoolStatusMsg(status) {
	switch (status) {
		case 'ONLINE':
			return 'The pool is in normal working order';
		case 'DEGRADED':
			return 'One or more devices with problems. Functioning in a degraded state';
		case 'FAULTED':
			return 'One or more devices could not be used. Pool unable to continue functioning';
		case 'OFFLINE':
			return 'One or more devices has been explicitly taken offline by the administrator ';
		case 'UNAVAIL':
			return 'One or more devices or virtual devices cannot be opened';
		case 'REMOVED':
			return 'One or more devices were physically removed while the system was running';
	}

	return 'Status Unknown'
}

function getPoolShowButtonText(show_status) {
	return show_status == true ? "Hide Datasets" : "Show Datasets";
}

function getPoolShowStatus(zpool) {
	var status = loadFromLocalStorage('zdataset-'+zpool, false);

	if (status == 'hide')
		return false;

	return true;
}

function generateDatasetDirectoryRows(zpool, zdataset, parent, show_status, destructive_mode) {
	var agg = '';
	const icon_color = 'grey';
	const depth = zdataset['name'].split('/').length;

	Object.values(zdataset.directories).forEach((directory) => {
		const id = elementToken(directory);
		var tr = '<tr id="tr-dir-'+id+'" class="zdataset-'+escapeHtml(zpool)+' '+escapeHtml(parent)+'" style="display: '+(show_status ? 'table-row' : 'none')+'">';
		tr += rowLeadCell(directory);
		tr += nameCell('&emsp;&emsp;'.repeat(depth)+'<a><i class="fa fa-folder-o icon" style="color:'+icon_color+'"></i></a>'+escapeHtml(directory.substring(directory.lastIndexOf("/") + 1)),
			'<button type="button" id="'+id+'" onclick="addDirectoryContext('+safeJsArg(directory)+', '+safeJsArg(zpool)+', '+safeJsArg(id)+', '+destructive_mode+');" class="zfs_compact">Actions</button>');
		tr += '<td class="zfsm-mount">'+escapeHtml(directory)+'</td><td class="zfsm-refer"></td>';
		tr += '<td class="zfsm-snaps">'+snapshotCell(icon_color, 0)+'</td><td></td><td></td><td></td>';
		agg += tr+'</tr>';
	});

	return agg;
}

// ZFS's own form: 116 from the backend reads 1.16x.
function compressRatioText(value) {
	const ratio = Number(value) / 100;
	return Number.isFinite(ratio) && ratio > 0 ? ratio.toFixed(2)+'x' : '';
}

function getPropertiesByType(zdataset) {
	const created = zdataset['creation_text'] ?? new Date(zdataset['creation'] * 1000).toLocaleString();
	var properties = {};

	if (zdataset['type'] !== undefined && zdataset['type'] == "filesystem".toLowerCase()) {
		properties = {
			'Creation Date' : created,
			'Type' : zdataset['type'],
			'Compression' : zdataset['compression'],
			'Compress Ratio' : compressRatioText(zdataset['compressratio']),
			'Record Size' : fromBytesToString(zdataset['recordsize']),
			'Access Time' : zdataset['atime'],
			'XAttr' : zdataset['xattr'],
			'Primary Cache' : zdataset['primarycache'],
			'Encryption' : zdataset['encryption'],
			'Key Status' : zdataset['keystatus'],
			'Quota' : zfsmSize(zdataset['quota']),
			'Read Only' : zdataset['readonly'],
			'Case Sensitive' : zdataset['casesensitivity'],
			'Sync' : zdataset['sync'],
			'Origin' : zdataset['origin'] ?? '',
			'Space used by Snaps' : zfsmSize(zdataset['usedbysnapshots'])
		}

	} else if (zdataset['type'] !== undefined && zdataset['type'] == "volume".toLowerCase()) {
		properties = {
			'Creation Date' : created,
			'Type' : zdataset['type'],
			'Block Size' : fromBytesToString(zdataset['volblocksize']),
			'Compression' : zdataset['compression'],
			'Compress Ratio' : compressRatioText(zdataset['compressratio']),
			'Primary Cache' : zdataset['primarycache'],
			'Encryption' : zdataset['encryption'],			
			'Key Status' : zdataset['keystatus'],
			'Read Only' : zdataset['readonly'],
			'Sync' : zdataset['sync'],
			'Origin': zdataset['origin'] ?? '',
			'Space used by Snaps' : zfsmSize(zdataset['usedbysnapshots'])
		}
	}

	return properties;
}

function getZnapProperties(zdataset) {
	const ZNAPZEND_PREFIX = "org.znapzend:";
	var properties = [];

	Object.keys(zdataset).filter(k => k.startsWith(ZNAPZEND_PREFIX)).forEach((property) => {
		properties[property.slice(ZNAPZEND_PREFIX.length)] = zdataset[property];
	});

	return Object.fromEntries(Object.entries(properties).sort(([a],[b]) => a.localeCompare(b)));
}

// the snapshot icon colour and count of a dataset; adds its last snapshot to properties
function datasetSnapInfo(zdataset, snap_max_days_alert, properties) {
	if (zdataset['snapshots'] === undefined || zdataset['snapshots'].length === 0)
		return {icon_color: 'grey', snap_count: 0};

	const snap = getLastSnap(zdataset['snapshots']);
	properties['Last Snap Date'] = snap['creation_text'] ?? new Date(snap['creation'] * 1000).toLocaleString();
	properties['Last Snap'] = snap['name'];

	return {icon_color: daysToNow(snap['creation']) > snap_max_days_alert ? 'orange' : '#486dba', snap_count: zdataset['snapshots'].length};
}

// Snapshots of every dataset under this one, which Snapshots Admin can list with it.
function childSnapshotCount(zdataset) {
	return Object.values(zdataset['child'] || {}).reduce((count, child) => count + (child['snapshots'] || []).length + childSnapshotCount(child), 0);
}

// An icon whose details show on hover or focus (ZFSMaster.page's zfsmShowTip); the label names what they describe.
function detailsLink(label, icon, details) {
	return '<a class="info hand" tabindex="0" aria-label="'+escapeHtml(label)+'">'+icon+'<span>'+details+'</span></a>';
}

// the icons after a dataset's name: znapzend plan, children, clone, encryption
function datasetBadges(zdataset) {
	var html = '';
	const znap_properties = getZnapProperties(zdataset);

	if (Object.keys(znap_properties).length > 0) {
		html += detailsLink('znapzend plan of '+zdataset['name'], '<i class="fa fa-clock-o fa-append"></i>', implodeWithKeys('<br>', znap_properties));
	}

	if (Object.keys(zdataset.child).length > 0 || hasDirectories(zdataset)) {
		html += '<i class="fa fa-minus-square fa-append" name="'+escapeHtml(zdataset['name'])+'" role="button" tabindex="0" aria-expanded="true" aria-label="'+escapeHtml('Contents of '+zdataset['name'])+'" style="cursor:pointer" onclick="toggleDataset('+safeJsArg(zdataset['name'])+');"></i>';
	}

	if (zdataset['origin'] !== undefined) {
		html += '<i class="fa fa-clone fa-append"></i>';
	}

	if (zdataset['keystatus'] != 'none') {
		html += zdataset['keystatus'] == 'available' ? '<i class="fa fa-unlock fa-append"></i>' : '<i class="fa fa-lock fa-append"></i>';
	}

	return html;
}

// one Used or Free cell: the text alone, or a bar when the display setting asks for one
// Unraid's own markup for Used and Free (nchan/device_list), so its theme draws the bar and the label exactly as in its tables.
function usageCell(text, percent, free, display) {
	const plain = free ? (display['text'] < 10 ? display['text'] % 10 == 0 : display['text'] % 10 != 0) : display['text'] % 10 == 0;

	if (plain)
		return text;

	// A share that can't be worked out (an empty or faulted pool) draws an empty bar.
	const width = Number.isFinite(percent) ? percent : 0;
	return '<div class="usage-disk"><span style="width:'+width+'%" class="'+usage_color(width, free, display)+'"></span><span>'+text+'</span></div>';
}

function generateDatasetRow(zpool, zdataset, parent, show_status, destructive_mode, snap_max_days_alert, display) {
	var tr = '<tr id="'+datasetRowId(zdataset['name'])+'" class="zdataset-'+escapeHtml(zpool)+' '+escapeHtml(parent)+'" style="display: '+(show_status ? 'table-row' : 'none')+'">';
	tr += rowLeadCell(zdataset['mountpoint']);

	const properties = getPropertiesByType(zdataset);
	const {icon_color, snap_count} = datasetSnapInfo(zdataset, snap_max_days_alert, properties);
	const id = elementToken(zdataset['name']);

	const label = '&emsp;&emsp;'.repeat(zdataset['name'].split('/').length - 1)
		+detailsLink('Details of '+zdataset['name'], '<i class="fa fa-hdd-o icon" style="color:'+icon_color+'"></i>', implodeWithKeys('<br>', properties))
		+datasetBadges(zdataset)
		+escapeHtml(zdataset['name'].substring(zdataset['name'].lastIndexOf("/") + 1));
	tr += nameCell(label, '<button type="button" id="'+id+'" onclick="addDatasetContext('+safeJsArg(zpool)+', '+safeJsArg(zdataset['name'])+', '+snap_count+', '+safeJsArg(id)+', '+destructive_mode+', '+safeJsArg(zdataset['keystatus'])+', '+safeJsArg(zdataset['origin'] ?? '')+', '+safeJsArg(zdataset['type'] ?? 'filesystem')+', '+childSnapshotCount(zdataset)+');" class="zfs_compact">Actions</button>');

	tr += '<td class="zfsm-mount">'+(zdataset['mountpoint'] != "none" ? escapeHtml(zdataset['mountpoint']) : '')+'</td>';
	tr += '<td class="zfsm-refer">'+zfsmSize(zdataset['referenced'])+'</td>';
	tr += '<td class="zfsm-snaps">'+snapshotCell(icon_color, zdataset['_snapshots_unavailable'] ? '—' : snap_count)+'</td>';

	// A dataset has no Size of its own; Used and Free share its space.
	var percent = 100-Math.round(calculateFreePercent(zdataset['used'], zdataset['available']));
	tr += '<td></td>';
	tr += '<td>'+usageCell(zfsmSize(zdataset['used']), percent, false, display)+'</td>';
	tr += '<td>'+usageCell(zfsmSize(zdataset['available']), 100-percent, true, display)+'</td>';
	return tr+'</tr>';
}

function generateDatasetArrayRows(zpool, dataset, parent, show_status, destructive_mode, snap_max_days_alert, display) {
	var tr = '';
	if (!dataset || dataset['_error']) return tr;
	tr += generateDatasetRow(zpool, dataset, parent, show_status, destructive_mode, snap_max_days_alert, display);
	if (hasDirectories(dataset)) {
		tr += generateDatasetDirectoryRows(zpool, dataset, parent+' '+datasetChildClass(dataset['name']), show_status, destructive_mode);
	}
	Object.values(dataset.child || {}).forEach((child) => {
		tr += generateDatasetArrayRows(zpool, child, parent+' '+datasetChildClass(dataset['name']), show_status, destructive_mode, snap_max_days_alert, display);
	});

	return tr;
}

function generatePoolTableRows(zpool, devices, show_status, display, root) {
	const show_button_text = getPoolShowButtonText(show_status);
	const status_color = getPoolStatusColor(zpool['Health']);
	const status_msg = getPoolStatusMsg(zpool['Health']);
	const pool = escapeHtml(zpool['Pool']);

	var tr = '<td id="'+pool+'-attribute-pool" class="zfsm-pool">'+detailsLink('Devices of '+zpool['Pool'], '<i id="zpool-'+pool+'" class="fa fa-circle orb '+status_color+'-orb"></i>', nl2br(escapeHtml(devices)))+' '+pool+'</td>';
	tr += '<td id="'+pool+'-attribute-health" class="zfsm-health">'+detailsLink('Health of '+zpool['Pool'], '<i class="fa fa-heartbeat" style="color:'+status_color+'"></i>', status_msg)+' '+escapeHtml(zpool['Health'])+'</td>';
	tr += '<td id="'+pool+'-attribute-name" class="zfsm-name"><button type="button" id="show-zpool-'+pool+'" onclick="togglePoolTable('+safeJsArg('show-zpool-'+zpool['Pool'])+', '+safeJsArg('zdataset-'+zpool['Pool'])+');">'+show_button_text+'</button>';
	tr += '<button type="button" onclick="createDataset('+safeJsArg(zpool['Pool'])+');">Create Dataset</button></td>';
	tr += '<td id="'+pool+'-attribute-mountpoint" class="zfsm-mount">'+escapeHtml(zpool['MountPoint'])+'</td>';
	tr += '<td id="'+pool+'-attribute-refer" class="zfsm-refer">'+escapeHtml(zpool['Refer'])+'</td>';
	tr += '<td id="'+pool+'-attribute-snapshots" class="zfsm-snaps">'+snapshotCell('', zpool['Snapshots'] == null ? 'Loading…' : zpool['Snapshots'])+'</td>';
	return tr+poolSpaceCells(zpool, root, display);
}

// Size, Used and Free as Unraid shows a pool: its usable space, from the root dataset; zpool's raw figures when that couldn't be read.
function poolSpaceCells(zpool, root, display) {
	const pool = escapeHtml(zpool['Pool']);
	const usable = !!root && !root['_error'] && Number.isFinite(root['used']) && Number.isFinite(root['available']);
	const used = usable ? root['used'] : zpool['Used'];
	const free = usable ? root['available'] : zpool['Free'];
	const text = (bytes, raw, decimals) => usable ? zfsmSize(bytes, decimals) : poolSizeText(raw, decimals);
	const percent = 100-Math.round(calculateFreePercent(used, free));
	return '<td id="'+pool+'-attribute-size">'+(usable ? zfsmSize(used + free, -1) : poolSizeText(zpool['Size'], -1))+'</td>'
		+'<td id="'+pool+'-attribute-used">'+usageCell(text(used, zpool['Used']), percent, false, display)+'</td>'
		+'<td id="'+pool+'-attribute-free">'+usageCell(text(free, zpool['Free']), 100-percent, true, display)+'</td>';
}

function updateFullBodyTable(data, destructive_mode, snap_max_days_alert, display) {
	var html_pools = "";

	const zfs_table_body = document.getElementById('zfs_master_body');

	if (!zfs_table_body) {
		return;
	}
	if (!data || !data.pools || Object.keys(data.pools).length === 0) {
		zfs_table_body.innerHTML = '<tr><td colspan="9">No imported ZFS pools were found.</td></tr>';
		return;
	}

	Object.values(data.pools).forEach((zpool) => {
		const show_status = getPoolShowStatus(zpool['Pool']);

		html_pools += '<tr id="'+poolRowId(zpool['Pool'])+'">';
		html_pools += generatePoolTableRows(zpool, data['devices'][zpool['Pool']], show_status, display, data['datasets'][zpool['Pool']]);
		html_pools += '</tr>';
		if (data['datasets'][zpool['Pool']] && data['datasets'][zpool['Pool']]['_error']) {
			html_pools += '<tr class="zdataset-'+escapeHtml(zpool['Pool'])+'"><td colspan="9" style="color:#e22828">ZFS query failed: '+escapeHtml(data['datasets'][zpool['Pool']]['_error'])+'</td></tr>';
		} else {
			html_pools += generateDatasetArrayRows(zpool['Pool'], data['datasets'][zpool['Pool']], '', show_status, destructive_mode, snap_max_days_alert, display);
			if (data['datasets'][zpool['Pool']]['_snapshot_error']) {
				html_pools += '<tr class="zdataset-'+escapeHtml(zpool['Pool'])+'"><td colspan="9" style="color:#e22828">Snapshot query failed: '+escapeHtml(data['datasets'][zpool['Pool']]['_snapshot_error'])+'</td></tr>';
			}
		}
	});

	zfs_table_body.innerHTML = html_pools;
}
