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

console.log('dataset row class tests passed');
