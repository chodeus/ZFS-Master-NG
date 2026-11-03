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

// Same three slots on every row, so the camera icons line up whether or not a row can be browsed.
function snapshotCell(color, count, browsePath) {
	const style = color ? ' style="color:'+escapeHtml(color)+'"' : '';
	const browse = typeof browsePath === 'string' && browsePath.startsWith('/')
		? '<a href="/Main/Browse?dir='+encodeURIComponent(browsePath)+'"><i class="icon-u-tab zfs_bar_button" title="Browse '+escapeHtml(browsePath)+'"></i></a>'
		: '';
	return '<i class="fa fa-camera-retro icon"'+style+'></i><span class="zfsm-snap-count">'+escapeHtml(count)+'</span><span class="zfsm-snap-browse">'+browse+'</span>';
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

function fromBytesToString(bytes) {
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  
	bytes = Number(bytes);
	bytes = Number.isFinite(bytes) ? Math.max(bytes, 0) : 0;
	const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
	const limitedPow = Math.min(pow, units.length - 1);
  
	bytes /= Math.pow(1024, limitedPow);
  
	return (Math.round((bytes + Number.EPSILON) * 10) / 10) + ' ' + units[limitedPow];
}

function fromStringToBytes(spacestr) {
	let returnNumber = parseFloat(spacestr);

	switch (spacestr.slice(-1)) {
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
	regex = /(?<data>[\d,]+)[\s\t]+(?<progress>[\d]+%)[\s\t]+(?<speed>[\d.]+[G|M|K|B]+\/s)[\s\t]+(?<time>[\d]+:[\d]+:[\d]+)/;
	
	groups = regex.exec(msg.replace(/(?:\\[rn])+/g, ""));
	
	if (groups == null || !'groups' in groups)
		return '';

	groups = groups['groups'];

	groups['data'] = fromBytesToString(groups['data'].replace(/,/g, ''));

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

function generateDatasetDirectoryRows(zpool, zdataset, parent, show_status, destructive_mode, snap_max_days_alert, display) {
	var agg = '';

	var icon_color = 'grey';
	var snap_count = 0;

	const depth = zdataset['name'].split('/').length;

	Object.values(zdataset.directories).forEach((directory) => {
		var tr = '<tr id="tr-dir-'+elementToken(directory)+'" class="zdataset-'+escapeHtml(zpool)+' '+escapeHtml(parent)+'" style="display: '+(show_status ? 'table-row' : 'none')+'">';
		tr += '<td></td><td></td><td>';

		for (let i = 1; i <= depth; i++) {
			tr += '&emsp;&emsp;';
		}

		tr += '<a><i class="fa fa-folder-o icon" style="color:'+icon_color+'"></i></a>';

		tr += escapeHtml(directory.substring(directory.lastIndexOf("/") + 1));
		tr += '</td>';

		// Actions

		tr += '<td>';
		var id = elementToken(directory);

		tr += '<button type="button" id="'+id+'" onclick="addDirectoryContext('+safeJsArg(directory)+', '+safeJsArg(zpool)+', '+safeJsArg(id)+', '+destructive_mode+');" class="zfs_compact">Actions</button>';
		tr += '</td>';

		//mountpoint
		tr += '<td>'+escapeHtml(directory)+'</td>';

		// Referr
		tr += '<td></td>';

		// Used
		tr += '<td></td>';

		// Free
		tr += '<td></td>';

		// Snapshots
		tr += '<td>'+snapshotCell(icon_color, snap_count, directory)+'</td>';
		tr += '</tr>';
		agg += tr;
	});

	return agg;
}

function getPropertiesByType(zdataset) {
	const created = zdataset['creation_text'] ?? new Date(zdataset['creation'] * 1000).toLocaleString();
	var properties = {};

	if (zdataset['type'] !== undefined && zdataset['type'] == "filesystem".toLowerCase()) {
		properties = {
			'Creation Date' : created,
			'Type' : zdataset['type'],
			'Compression' : zdataset['compression'],
			'Compress Ratio' : zdataset['compressratio']/100,
			'Record Size' : fromBytesToString(zdataset['recordsize']),
			'Access Time' : zdataset['atime'],
			'XAttr' : zdataset['xattr'],
			'Primary Cache' : zdataset['primarycache'],
			'Encryption' : zdataset['encryption'],
			'Key Status' : zdataset['keystatus'],
			'Quota' : fromBytesToString(zdataset['quota']),
			'Read Only' : zdataset['readonly'],
			'Case Sensitive' : zdataset['casesensitivity'],
			'Sync' : zdataset['sync'],
			'Origin' : zdataset['origin'] ?? '',
			'Space used by Snaps' : fromBytesToString(zdataset['usedbysnapshots'])
		}

	} else if (zdataset['type'] !== undefined && zdataset['type'] == "volume".toLowerCase()) {
		properties = {
			'Creation Date' : created,
			'Type' : zdataset['type'],
			'Block Size' : fromBytesToString(zdataset['volblocksize']),
			'Compression' : zdataset['compression'],
			'Compress Ratio' : zdataset['compressratio']/100,
			'Primary Cache' : zdataset['primarycache'],
			'Encryption' : zdataset['encryption'],			
			'Key Status' : zdataset['keystatus'],
			'Read Only' : zdataset['readonly'],
			'Sync' : zdataset['sync'],
			'Origin': zdataset['origin'] ?? '',
			'Space used by Snaps' : fromBytesToString(zdataset['usedbysnapshots'])
		}
	}

	return properties;
}

function getZnapProperties(zdataset) {
	const ZNAPZEND_PREFIX = "org.znapzend:";
	var properties = [];

	keys = Object.keys(zdataset).filter(k => k.startsWith(ZNAPZEND_PREFIX));

	keys.forEach((property) => {
		properties[property.slice(ZNAPZEND_PREFIX.length)] = zdataset[property];
	});

	return Object.fromEntries(Object.entries(properties).sort(([a],[b]) => a.localeCompare(b)));
}

function generateDatasetRow(zpool, zdataset, parent, show_status, destructive_mode, snap_max_days_alert, display) {
	var tr = '<tr id="'+datasetRowId(zdataset['name'])+'" class="zdataset-'+escapeHtml(zpool)+' '+escapeHtml(parent)+'" style="display: '+(show_status ? 'table-row' : 'none')+'">';
	tr += '<td></td><td></td><td>';

	var properties = getPropertiesByType(zdataset);

	var icon_color = 'grey';
	var snap_count = 0;

	if (zdataset['snapshots'] !== undefined && zdataset['snapshots'].length > 0) {
		const snap = getLastSnap(zdataset['snapshots']);
		var snapdate = new Date(snap['creation'] * 1000);

		if (daysToNow(snap['creation']) > snap_max_days_alert) {
			icon_color = 'orange';
		} else {
			icon_color = '#486dba';
		}

		properties['Last Snap Date'] = snap['creation_text'] ?? snapdate.toLocaleString();
		properties['Last Snap'] = snap['name'];

		snap_count = zdataset['snapshots'].length;
	}

	const depth = zdataset['name'].split('/').length - 1;

	for (let i = 1; i <= depth; i++) {
    	tr += '&emsp;&emsp;';
	}

	tr += '<a class="info hand"><i class="fa fa-hdd-o icon" style="color:'+icon_color+'" onclick="toggleDataset('+safeJsArg(zdataset['name'])+');"></i>';
	tr += '<span>'+implodeWithKeys('<br>', properties)+'</span></a>';

	var znap_properties = getZnapProperties(zdataset);

	if (Object.keys(znap_properties).length > 0) {
		tr += '<a class="info hand"><i class="fa fa-clock-o fa-append"></i>';
		tr += '<span>'+implodeWithKeys('<br>', znap_properties)+'</span></a>';
	}

	if (Object.keys(zdataset.child).length > 0 || hasDirectories(zdataset)) {
		tr += '<i class="fa fa-minus-square fa-append" name="'+escapeHtml(zdataset['name'])+'"></i>';
	}

	if (zdataset['origin'] !== undefined) {
		tr += '<i class="fa fa-clone fa-append"></i>';
	}

	if (zdataset['keystatus'] != 'none') {
		if (zdataset['keystatus'] == 'available') {
			tr += '<i class="fa fa-unlock fa-append"></i>';
		} else {
			tr += '<i class="fa fa-lock fa-append"></i>';
		}
	}

	tr += escapeHtml(zdataset['name'].substring(zdataset['name'].lastIndexOf("/") + 1));
	tr += '</td>';

	// Actions

	tr += '<td>';
	var id = elementToken(zdataset['name']);

	tr += '<button type="button" id="'+id+'" onclick="addDatasetContext('+safeJsArg(zpool)+', '+safeJsArg(zdataset['name'])+', '+snap_count+', '+safeJsArg(id)+', '+destructive_mode+', '+safeJsArg(zdataset['keystatus'])+', '+safeJsArg(zdataset['origin'] ?? '')+', '+safeJsArg(zdataset['type'] ?? 'filesystem')+');" class="zfs_compact">Actions</button>';
	tr += '</td>';

	//mountpoint
	tr += '<td>';
	if (zdataset['mountpoint'] != "none") {
		tr += escapeHtml(zdataset['mountpoint']);
	}

	tr += '</td>';

	// Referr
	tr += '<td>';
	tr += fromBytesToString(zdataset['referenced']);
	tr += '</td>';

	// Used
	var percent = 100-Math.round(calculateFreePercent(zdataset['used'], zdataset['available']));

	tr += '<td>';
	if (display['text'] % 10 == 0) {
		tr += fromBytesToString(zdataset['used']);
	} else {
		tr += '<div class="usage-disk"><span style="margin:0;width:'+percent+'%" class="'+usage_color(percent, false, display)+'"></span><span>'+fromBytesToString(zdataset['used'])+'</span></div>';
	}
	tr += '</td>';

	// Free
	tr += '<td>';	
	if (display['text'] < 10 ? display['text'] % 10 == 0 : display['text'] % 10 != 0) {
		tr += fromBytesToString(zdataset['available']);
	} else {
		tr += '<div class="usage-disk"><span style="margin:0;width:'+(100-percent)+'%" class="'+usage_color(100-percent, true, display)+'"></span><span>'+fromBytesToString(zdataset['available'])+'</span></div>';
	}
	tr += '</td>';

	// Snapshots

	tr += '<td>'+snapshotCell(icon_color, zdataset['_snapshots_unavailable'] ? '—' : snap_count, zdataset['mountpoint'])+'</td>';
	tr += '</tr>';

	return tr;
}

function generateDatasetArrayRows(zpool, dataset, parent, show_status, destructive_mode, snap_max_days_alert, display) {
	var tr = '';
	if (!dataset || dataset['_error']) return tr;
	tr += generateDatasetRow(zpool, dataset, parent, show_status, destructive_mode, snap_max_days_alert, display);
	if (hasDirectories(dataset)) {
		tr += generateDatasetDirectoryRows(zpool, dataset, parent+' '+datasetChildClass(dataset['name']), show_status, destructive_mode, snap_max_days_alert, display);
	}
	Object.values(dataset.child || {}).forEach((child) => {
		tr += generateDatasetArrayRows(zpool, child, parent+' '+datasetChildClass(dataset['name']), show_status, destructive_mode, snap_max_days_alert, display);
	});

	return tr;
}

function generatePoolTableRows(zpool, devices, show_status, display) {
	const show_button_text = getPoolShowButtonText(show_status);
	const status_color = getPoolStatusColor(zpool['Health']);
	const status_msg = getPoolStatusMsg(zpool['Health']);

	// Name and devices
	var tr = '<td id="'+escapeHtml(zpool['Pool'])+'-attribute-pool"><a class="info hand"><i id="zpool-'+escapeHtml(zpool['Pool'])+'" class="fa fa-circle orb '+status_color+'-orb"></i><span>'+nl2br(escapeHtml(devices))+'</span></a> '+escapeHtml(zpool['Pool'])+'</td>';

	// Health
	tr += '<td id="'+zpool['Pool']+'-attribute-health"><a class="info hand"><i class="fa fa-heartbeat" style="color:'+status_color+'"></i><span>'+status_msg+'</span></a> '+zpool['Health']+'</td>';

	// Buttons
	tr += '<td id="'+zpool['Pool']+'-attribute-name"><button type="button" id="show-zpool-'+zpool['Pool']+'" onclick="togglePoolTable(\'show-zpool-'+zpool['Pool']+'\', \'zdataset-'+zpool['Pool']+'\');">'+show_button_text+'</button>'; 
	tr += '<button type="button" onclick="createDataset(\''+zpool['Pool']+'\')";">Create Dataset</button></td>';

	// Size
	tr += '<td id="'+zpool['Pool']+'-attribute-size">'+zpool['Size']+'</td>'; 

	// Mountpoint
	tr += '<td id="'+zpool['Pool']+'-attribute-mountpoint">'+zpool['MountPoint']+'</td>'; 

	// Refer
	tr += '<td id="'+zpool['Pool']+'-attribute-refer">'+zpool['Refer']+'</td>'; 

	// Used
	var percent = 100-Math.round(calculateFreePercent(zpool['Used'], zpool['Free']));

	tr += '<td id="'+zpool['Pool']+'-attribute-used">';

	if (display['text'] % 10 == 0) {
		tr += zpool['Used']+'iB';
	} else {
		tr += '<div class="usage-disk"><span style="margin:0;width:'+percent+'%" class="'+usage_color(percent, false, display)+'"></span><span>'+zpool['Used']+'iB</span></div>';
	}
	tr += '</td>';

	// Free
	tr += '<td id="'+zpool['Pool']+'-attribute-free">';

	if (display['text'] < 10 ? display['text'] % 10 == 0 : display['text'] % 10 != 0) {
		tr += zpool['Free']+'iB';
	} else {
		tr += '<div class="usage-disk"><span style="margin:0;width:'+(100-percent)+'%" class="'+usage_color(100-percent, true, display)+'"></span><span>'+zpool['Free']+'iB</span></div>';
	}
	tr += '</td>';

	// Snapshots
	tr += '<td id="'+zpool['Pool']+'-attribute-snapshots">'+snapshotCell('', zpool['Snapshots'] == null ? 'Loading…' : zpool['Snapshots'], '')+'</td>';

	return tr; 
}

function updateFullBodyTable(data, destructive_mode, snap_max_days_alert, display, directory_listing) {
	var html_pools = "";

	zfs_table_body = document.getElementById('zfs_master_body');

	if (!zfs_table_body) {
		return;
	}
	if (!data || !data.pools || Object.keys(data.pools).length === 0) {
		zfs_table_body.innerHTML = '<tr><td colspan="9">No imported ZFS pools were found.</td></tr>';
		return;
	}

	Object.values(data.pools).forEach((zpool) => {
		const show_status = getPoolShowStatus(zpool['Pool']);

		html_pools += '<tr>';
		html_pools += generatePoolTableRows( zpool, data['devices'][zpool['Pool']], show_status, display);
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
