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
const rows = [...html.matchAll(/<tr id="tr-([^"]+)" class="([^"]+)"/g)].map(match => ({id: match[1], classes: match[2].split(' ')}));
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

console.log('dataset row class tests passed');
