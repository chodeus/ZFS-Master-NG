const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../zfs.master');
const frontend = fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'ZFSMaster.page'), 'utf8');

const context = vm.createContext({console, localStorage: {getItem: () => null, setItem() {}, removeItem() {}}, document: {}, window: {}, Date, encodeURIComponent, decodeURIComponent});
vm.runInContext(frontend, context);

// Real row generation: the tree a pool named after a theme colour produces, with a space in a dataset name.
const leaf = {name: 'red/My Share/child', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/red/My Share/child', snapshots: [], directories: [], child: {}};
const share = {name: 'red/My Share', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/red/My Share', snapshots: [], directories: [], child: {'red/My Share/child': leaf}};
const pool = {name: 'red', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/red', snapshots: [], directories: [], child: {'red/My Share': share}};
const html = context.generateDatasetArrayRows('red', pool, context.datasetChildClass('red'), true, 0, 30, {});
const rows = [...html.matchAll(/<tr id="tr-([^"]+)" class="([^"]+)"/g)].map(match => ({id: decodeURIComponent(match[1]), rawId: match[1], classes: match[2].split(' ')}));
for (const row of rows) assert.doesNotMatch(row.rawId, /[\s/]/, 'a row id is one encoded token');
assert.equal(rows.length, 3, 'one row per dataset');

const shareClass = context.datasetChildClass('red/My Share');
assert.equal(shareClass.includes(' '), false, 'a dataset class must be one token');
const child = rows.find(row => row.id === 'red/My Share/child');
assert.ok(child.classes.includes(shareClass), 'a descendant carries its ancestor class, so collapsing the ancestor hides it');
assert.ok(child.classes.includes(context.datasetChildClass('red')), 'a descendant carries the pool class');
for (const row of rows) {
  assert.equal(row.classes.includes('red'), false, 'a pool name must not become a bare CSS class');
  assert.equal(row.classes.includes('Share'), false, 'a dataset name must not split into classes');
}

// The toggles must look rows up by the same class the rows were given.
assert.equal((page.match(/getElementsByClassName\(zdataset\)/g) || []).length, 0, 'toggles must not use the raw dataset name');
assert.equal((page.match(/getElementsByClassName\(datasetChildClass\(zdataset\)\)/g) || []).length, 2);

// The inline handler must decode back to the exact name.
const handler = [...html.matchAll(/onclick="(toggleDataset\([^"]+\));"/g)].map(match => match[1]).find(item => item.includes('My%20Share'));
assert.ok(handler, 'toggle handler for the share');
let toggled;
context.toggleDataset = name => { toggled = name; };
vm.runInContext(handler, context);
assert.equal(toggled, 'red/My Share');

const sources = frontend+page;
assert.doesNotMatch(sources, /'tr-'\+(?!encodeURIComponent)/, 'every dataset row id goes through datasetRowId');
assert.doesNotMatch(sources, /Spaces are not supported/, 'converting a folder with a space is left to the backend, which allows it');

// Two names with the same 16-bit checksum used to share a button id, so one could open the other's menu.
const crc = text => { let value = 0; for (const char of text) { value ^= char.charCodeAt(0); for (let bit = 0; bit < 8; bit++) value = value & 1 ? (value >>> 1) ^ 0xA001 : value >>> 1; } return value; };
const seen = new Map();
let first = null, second = null;
for (let n = 0; !first; n++) {
	const name = 'tank/ds'+n;
	if (seen.has(crc(name))) { first = seen.get(crc(name)); second = name; }
	seen.set(crc(name), name);
}
const bare = name => ({name, type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/'+name, snapshots: [], directories: [], child: {}});
const pair = context.generateDatasetArrayRows('tank', bare(first), '', true, 0, 30, {})+context.generateDatasetArrayRows('tank', bare(second), '', true, 0, 30, {});
const buttons = [...pair.matchAll(/<button type="button" id="([^"]+)"/g)].map(match => match[1]);
assert.equal(buttons.length, 2, 'each row has a menu button');
assert.notEqual(buttons[0], buttons[1], first+' and '+second+' must not share a menu button id');
for (const id of buttons) assert.match(id, /^[A-Za-z][A-Za-z0-9_-]*$/, 'a menu id works as a # selector');
const spaced = context.generateDatasetArrayRows('tank', bare('tank/My Share'), '', true, 0, 30, {}).match(/<button type="button" id="([^"]+)"/)[1];
assert.match(spaced, /^[A-Za-z][A-Za-z0-9_-]*$/, 'a name with a space too');

for (const [a, b] of [['tank/a b', 'tank/a_2520b'], ['tank/a%20b', 'tank/a_20b'], ['tank/a_b', 'tank/a b']]) {
	assert.notEqual(context.elementToken(a), context.elementToken(b), a+' and '+b+' need different ids');
}

// Every snapshot cell has the same slots, so the camera icons line up down the column.
const cells = source => [...source.matchAll(/<i class="fa fa-camera-retro icon"[^>]*><\/i>(.*?)<\/td>/g)].map(match => match[1]);
const datasetCells = cells(context.generateDatasetArrayRows('tank', {...bare('tank'), mountpoint: 'none', child: {a: bare('tank/a')}}, '', true, 0, 30, {}));
const poolCell = cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Size: '1T', MountPoint: '/mnt/tank', Refer: '', Used: '1', Free: '1', Snapshots: 3}, '', true, {})+'</tr>');
for (const cell of [...datasetCells, ...poolCell]) assert.match(cell, /^<span class="zfsm-snap-count">[^<]*<\/span><span class="zfsm-snap-browse">/, 'a snapshot cell keeps the count and browse slots: '+cell);
assert.equal(datasetCells.length + poolCell.length, 3, 'pool, unmounted root and mounted child all checked');
assert.doesNotMatch(datasetCells[0], /Main\/Browse/, 'no browse link without a mountpoint');
assert.match(datasetCells[1], /Main\/Browse\?dir=%2Fmnt%2Ftank%2Fa"/);
const hostile = context.snapshotCell('grey', 1, '/mnt/a"><img src=x onerror=alert(1)>');
assert.doesNotMatch(hostile, /<img/, 'a mountpoint cannot inject markup');
assert.doesNotMatch(context.snapshotCell('', 0, ''), /<a /);
assert.match(page, /\.zfsm-snap-browse \.zfs_bar_button\{float:none;display:inline-block/, 'the browse icon must not float inside its slot');
assert.match(page, /<div id="zfs_master_div" class="TableContainer">\s*<table id="zfs_master"/, 'on Unraid 7.2+ phones the table scrolls in its own box, like Unraid\'s tables');
assert.match(page, /@media \(max-width:768px\)\{\s*div\.title\.zfsm-title,div#title\.zfsm-title\{flex-wrap:wrap/, 'on phones only the plugin title line wraps');

// The status bar moves onto Unraid's title line, which then skips the reads/writes toggle.
const element = (tagName, classes = []) => {
	const node = {tagName, children: [], previousElementSibling: null, parent: null, className: classes.join(' '),
		classList: {contains: name => node.className.split(' ').includes(name), add: name => { if (!node.classList.contains(name)) node.className += ' '+name; }},
		get firstChild() { return node.children[0] || null; },
		appendChild(child) { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1); child.parent = node; node.children.push(child); return child; },
		querySelector(selector) { const [tag, cls] = selector.split('.'); return node.children.find(c => c.tagName === tag.toUpperCase() && c.classList.contains(cls)) || null; },
		remove() { node.removed = true; }};
	return node;
};
context.document = {createElement: tag => element(tag.toUpperCase())};
const layout = withRight => {
	const title = element('DIV', ['title']);
	if (withRight) title.appendChild(element('SPAN', ['right']));
	const note = element('P');
	note.previousElementSibling = title;
	const bar = element('DIV', ['title', 'zfs_master_title']);
	bar.previousElementSibling = note;
	for (const part of ['refresh time', 'settings', 'refresh']) bar.appendChild(element('SPAN', [part]));
	return {title, bar};
};
for (const withRight of [true, false]) {
	const {title, bar} = layout(withRight);
	assert.equal(context.zfsmMoveStatusIntoTitle(bar), true);
	assert.ok(title.classList.contains('disable_diskio'), 'the title opts out of the reads/writes toggle');
	assert.ok(title.classList.contains('zfsm-title'), 'the title is marked so only it wraps on phones');
	const right = title.children.find(c => c.classList.contains('right'));
	assert.deepEqual(right.children[0].children.map(c => c.className), ['refresh time', 'settings', 'refresh'], 'all three move, in order'+(withRight ? '' : ', into a right span made for them'));
	assert.ok(bar.removed, 'the empty bar goes');
}
// Unraid 6.9 and 6.11 mark the title line div#title; an earlier div.title belongs to someone else.
{
	const other = element('DIV', ['title']);
	const legacy = element('DIV');
	legacy.id = 'title';
	legacy.previousElementSibling = other;
	const bar = element('DIV', ['title', 'zfs_master_title']);
	bar.previousElementSibling = legacy;
	bar.appendChild(element('SPAN', ['refresh time']));
	assert.equal(context.zfsmMoveStatusIntoTitle(bar), true);
	assert.ok(legacy.classList.contains('disable_diskio'), 'the 6.9 title line takes the status');
	assert.equal(other.children.length, 0, 'an earlier title is left alone');
}
const tabbed = element('DIV', ['title', 'zfs_master_title']);
tabbed.appendChild(element('SPAN', ['refresh time']));
assert.equal(context.zfsmMoveStatusIntoTitle(tabbed), false, 'a tabbed page has no title line, so the bar stays');
assert.equal(tabbed.children.length, 1);
assert.equal(context.zfsmMoveStatusIntoTitle(null), false);

console.log('dataset row class tests passed');
