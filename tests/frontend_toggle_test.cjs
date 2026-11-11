// togglePoolTable must keep rows under a collapsed dataset hidden when the pool table is shown again.
// Real row generation (ZFSMFrontEnd.js) and the real toggle functions from ZFSMaster.page; only the DOM is a shim.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../zfs.master');
// ZFSMSize.js first, as ZFSMaster.page loads it: the rows format their sizes with it.
const frontend = ['ZFSMSize.js', 'ZFSMFrontEnd.js'].map(file => fs.readFileSync(path.join(root, 'frontend', file), 'utf8')).join('\n');
const page = fs.readFileSync(path.join(root, 'ZFSMaster.page'), 'utf8');
const start = page.indexOf('function processHiddenDatasets()');
const end = page.indexOf('function createDataset(zpool)');
assert.ok(start > 0 && end > start, 'toggle functions found in ZFSMaster.page');
const toggles = page.slice(start, end);

const storage = new Map();
const rows = [];
function wrap(row) {
  return {
    attr(name, value) { if (value === undefined) return name === 'class' ? row.classes.join(' ') : undefined; return this; },
    hide() { row.visible = false; return this; },
    show() { row.visible = true; return this; },
  };
}
function $(selector) { return typeof selector === 'object' ? wrap(selector) : {attr() { return this; }}; }
const button = {firstChild: {data: 'Hide Datasets'}};
const context = vm.createContext({
  $, console, encodeURIComponent, decodeURIComponent, Date,
  localStorage: {getItem: k => storage.has(k) ? storage.get(k) : null, setItem: (k, v) => storage.set(k, String(v)), removeItem: k => storage.delete(k)},
  document: {
    getElementsByClassName: cls => rows.filter(row => row.classes.includes(cls)),
    getElementById: id => id === 'show-zpool-tank' ? button : null,
  },
  window: {},
});
vm.runInContext(frontend, context);
vm.runInContext(toggles, context);

const leaf = {name: 'tank/apps/db', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/tank/apps/db', snapshots: [], directories: [], child: {}};
const apps = {name: 'tank/apps', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/tank/apps', snapshots: [], directories: [], child: {'tank/apps/db': leaf}};
const other = {name: 'tank/media', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/tank/media', snapshots: [], directories: [], child: {}};
const pool = {name: 'tank', type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/tank', snapshots: [], directories: [], child: {'tank/apps': apps, 'tank/media': other}};
apps.directories = ['/mnt/tank/apps/config'];
const html = context.generateDatasetArrayRows('tank', pool, '', true, 0, 30, {});
assert.doesNotMatch(frontend, /datasetChildClass\(zpool\['Pool'\]\), show_status/, 'the page starts the root row without its own child class');
for (const match of html.matchAll(/<tr id="tr-([^"]+)" class="([^"]+)"/g)) rows.push({id: decodeURIComponent(match[1]), classes: match[2].split(/\s+/), visible: true});
const row = id => rows.find(r => r.id === id);

// Collapse tank/apps: its child row and its own directory rows hide.
context.toggleDataset('tank/apps');
assert.equal(row('tank/apps/db').visible, false, 'collapsing tank/apps hides tank/apps/db');
const directoryRow = rows.find(r => r.id.startsWith('dir-'));
assert.ok(directoryRow, 'the fixture has a directory row');
assert.equal(directoryRow.visible, false, 'collapsing tank/apps hides its directories');

// Collapse the pool root: the root row stays, so it can be expanded again.
context.toggleDataset('tank');
assert.notEqual(row('tank').visible, false, 'collapsing the root must not hide the root row');
assert.equal(row('tank/media').visible, false, 'collapsing the root hides its children');
context.toggleDataset('tank');
assert.equal(row('tank/media').visible, true, 'expanding the root shows them again');

// Hide the pool table, then show it again. The collapsed dataset's children must stay hidden.
context.togglePoolTable('show-zpool-tank', 'zdataset-tank');
assert.equal(row('tank/media').visible, false, 'pool hide hides rows');
context.togglePoolTable('show-zpool-tank', 'zdataset-tank');
assert.equal(row('tank/media').visible, true, 'pool show shows ordinary rows');
assert.equal(row('tank/apps/db').visible, false,
  'pool show must keep rows under the collapsed tank/apps hidden (row classes: ' + row('tank/apps/db').classes.join(' ') + '; hidden list: ' + storage.get('zfsm-datasetsHidden') + ')');
console.log('pool toggle tests passed');
