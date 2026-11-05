// Snapshots Admin selection on this Unraid version's jQuery: Delete and Select all follow what is ticked, with no rows too.
// Usage: snapshot_admin_page_test.cjs <webgui-tree>. Markup and script come from the page itself.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const tree = path.resolve(process.argv[2]);
const jquery = [path.join(tree, 'emhttp/plugins/dynamix/javascript/dynamix.js'), path.join(tree, 'plugins/dynamix/javascript/dynamix.js')].find(fs.existsSync);
assert.ok(jquery, 'no dynamix.js (Unraid jQuery) under ' + tree);
const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMAdminDatasetSnaps.php'), 'utf8');

const footer = page.match(/<div class="zfsm-footer">[\s\S]*?<\/div>/);
const checkAll = page.match(/<input type="checkbox" id="checkAll"[^>]*>/);
assert.ok(footer && checkAll, 'Select all and the footer found in the page');
const start = page.indexOf('// Counts what is ticked now');
const end = page.indexOf('function rollbackSnapshot(');
const update = page.match(/function updateStatus\(text\) \{[\s\S]*?\n {2}\}/);
assert.ok(start > 0 && end > start && update, 'selection script found in the page');

function load(rows) {
  const boxes = Array.from({length: rows}, (_, i) => `<input class="snapl-check" type="checkbox" value="tank/data@s${i}">`).join('');
  const {window} = new JSDOM(`<!doctype html><body>${checkAll[0]}${boxes}${footer[0]}</body>`, {runScripts: 'outside-only'});
  window.eval(fs.readFileSync(jquery, 'utf8'));
  window.eval(update[0] + page.slice(start, end));
  const $ = window.$;
  return {
    window,
    tick: index => { const box = window.document.querySelectorAll('.snapl-check')[index]; box.checked = true; $(box).trigger('change'); },
    state: () => ({delete: $('#delete-snaps').text(), deleteOff: $('#delete-snaps').prop('disabled'), all: $('#checkAll').prop('checked'), allOff: $('#checkAll').prop('disabled'), status: $('#zfs_status').text()}),
  };
}

const empty = load(0);
assert.deepEqual(empty.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: true, status: ''}, 'no snapshots: nothing to delete or select');

const three = load(3);
assert.deepEqual(three.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: false, status: 'Tick snapshots to delete them together.'}, 'nothing ticked yet');
three.tick(0);
three.tick(2);
assert.deepEqual(three.state(), {delete: 'Delete selected (2)', deleteOff: false, all: false, allOff: false, status: '2 selected'}, 'two of three ticked');
three.window.document.getElementById('checkAll').click();
assert.deepEqual(three.state(), {delete: 'Delete selected (3)', deleteOff: false, all: true, allOff: false, status: '3 selected'}, 'Select all ticks every row');
three.window.document.getElementById('checkAll').click();
assert.deepEqual(three.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: false, status: 'Tick snapshots to delete them together.'}, 'and clears them again');

console.log('snapshot admin selection tests passed');
