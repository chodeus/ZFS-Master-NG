// Snapshots Admin list on this Unraid version's jQuery and tablesorter: selection, filter and sort, with no rows too.
// Usage: snapshot_admin_page_test.cjs <webgui-tree>. Header, filter bar and footer come from the page; rows mirror what it prints.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const tree = path.resolve(process.argv[2]);
const jquery = [path.join(tree, 'emhttp/plugins/dynamix/javascript/dynamix.js'), path.join(tree, 'plugins/dynamix/javascript/dynamix.js')].find(fs.existsSync);
assert.ok(jquery, 'no dynamix.js (Unraid jQuery) under ' + tree);
const frontend = path.resolve(__dirname, '../zfs.master/frontend');
const page = fs.readFileSync(path.join(frontend, 'ZFSMAdminDatasetSnaps.php'), 'utf8');
const list = fs.readFileSync(path.join(frontend, 'ZFSMSnapshotList.js'), 'utf8');

const footer = page.match(/<div class="zfsm-footer">[\s\S]*?<\/div>/);
const thead = page.match(/<thead>[\s\S]*?<\/thead>/);
const tools = page.match(/<div class="zfsm-snap-tools">[\s\S]*?<\/div>/);
assert.ok(footer && thead && tools, 'header, filter bar and footer found in the page');
const bar = tools[0].replace(/<\?php[\s\S]*?\?>/g, '');

// [name, used bytes, created epoch, used as shown], in the order zfs listed them.
function load(snapshots) {
  const rows = snapshots.map(([name, used, created, usedText = '']) => `<tr data-snapshot="${name}" data-automatic="${/^(autosnap_|syncoid_)/.test(name) ? 1 : 0}">`
    + `<td><input class="snapl-check" type="checkbox" value="tank/data@${name}"></td><td data-text="${name}">${name}</td>`
    + `<td class="num" data-text="${used}">${usedText}</td><td class="num" data-text="0"></td><td class="num" data-text="0"></td><td class="num" data-text="0"></td>`
    + `<td data-text="${created}"></td><td></td></tr>`).join('');
  const {window} = new JSDOM(`<!doctype html><body>${snapshots.length ? bar : ''}<table class="zfsm-snap-table">${thead[0]}<tbody id="zpools">${rows}</tbody></table>${footer[0]}</body>`, {runScripts: 'outside-only'});
  window.eval(fs.readFileSync(jquery, 'utf8'));
  window.eval(list + '\nzfsmInitSnapshotList();');
  const $ = window.$;
  const box = name => window.document.querySelector(`.snapl-check[value="tank/data@${name}"]`);
  return {
    window,
    tick: name => { box(name).checked = true; $(box(name)).trigger('change'); },
    ticked: () => Array.from($('.snapl-check:checked').map(function() { return this.value.split('@')[1]; }).get()),
    shown: () => Array.from($('#zpools tr').not('.zfsm-hidden').map(function() { return this.getAttribute('data-snapshot'); }).get()),
    filter: text => $('#zfsm-snap-filter').val(text).trigger('input'),
    hideAutomatic: on => $('#zfsm-hide-auto').prop('checked', on).trigger('change'),
    // A header sort lands on tablesorter's own timer.
    sortBy: column => { $('.zfsm-snap-table thead th').eq(column).trigger('sort'); return new Promise(done => setTimeout(done, 150)); },
    selectAll: () => window.document.getElementById('checkAll').click(),
    state: () => ({delete: $('#delete-snaps').text(), deleteOff: $('#delete-snaps').prop('disabled'), all: $('#checkAll').prop('checked'), allOff: $('#checkAll').prop('disabled'), status: $('#zfs_status').text(), count: $('#zfsm-snap-shown').text()}),
  };
}

(async () => {
const empty = load([]);
assert.deepEqual(empty.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: true, status: '', count: ''}, 'no snapshots: nothing to delete or select');

const three = load([['a', 900, 1, '900 B'], ['b', 10, 2, '10 B'], ['c', 2048, 3, '2.00 KiB']]);
assert.deepEqual(three.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: false, status: 'Tick snapshots to delete them together.', count: ''}, 'nothing ticked yet');
three.tick('a');
three.tick('c');
assert.deepEqual(three.state(), {delete: 'Delete selected (2)', deleteOff: false, all: false, allOff: false, status: '2 selected', count: ''}, 'two of three ticked');
three.selectAll();
assert.equal(three.state().delete, 'Delete selected (3)', 'Select all ticks every row');
assert.equal(three.state().all, true);
three.selectAll();
assert.deepEqual(three.ticked(), [], 'and clears them again');

// Sorting goes by the raw bytes: as text, 2.00 KiB would sort before 10 B.
assert.deepEqual(three.shown(), ['a', 'b', 'c'], 'starts oldest first, as listed');
await three.sortBy(2);
assert.deepEqual(three.shown(), ['b', 'a', 'c'], 'Used sorts by size');
await three.sortBy(2);
assert.deepEqual(three.shown(), ['c', 'a', 'b'], 'and again the other way');
const unsortable = three.window.$('.zfsm-snap-table thead th').map(function(index) { return this.classList.contains('sorter-false') ? index : null; }).get();
assert.deepEqual(Array.from(unsortable), [0, 7], 'only the tick box and Actions columns do not sort');
await three.sortBy(6);
const once = three.shown();
await three.sortBy(6);
assert.deepEqual([once.join(), three.shown().join()].sort(), ['a,b,c', 'c,b,a'], 'Created sorts by time, both ways');

const mixed = load([['daily', 5, 1], ['autosnap_2026-11-06_00:00:01_hourly', 6, 2], ['syncoid_tower_2026-11-06:00:00:02-GMT08:00', 7, 3], ['Weekly', 8, 4]]);
mixed.tick('daily');
mixed.filter('AUTO');
assert.deepEqual(mixed.shown(), ['autosnap_2026-11-06_00:00:01_hourly'], 'the filter matches names in any case');
assert.equal(mixed.state().count, '1 of 4 shown', 'how many the filter shows');
mixed.filter('weekly');
assert.deepEqual(mixed.shown(), ['Weekly'], 'a lowercase filter finds a capitalised name');
mixed.filter('AUTO');
assert.deepEqual(mixed.ticked(), [], 'a ticked row the filter hides is unticked, so Delete never acts on it');
mixed.filter('');
mixed.selectAll();
mixed.filter('ly');
assert.deepEqual(mixed.shown(), ['daily', 'autosnap_2026-11-06_00:00:01_hourly', 'Weekly'], 'filtering again');
assert.deepEqual(mixed.ticked(), ['daily', 'autosnap_2026-11-06_00:00:01_hourly', 'Weekly'], 'keeps the shown rows ticked');
assert.equal(mixed.state().all, true, 'Select all stays ticked: every shown row is');
mixed.selectAll();
mixed.selectAll();
assert.equal(mixed.state().delete, 'Delete selected (3)', 'Select all ticks only the shown rows');
mixed.filter('');
assert.equal(mixed.state().all, false, 'a row shown again unticked clears Select all');
mixed.filter('nothing matches');
assert.deepEqual(mixed.state(), {delete: 'Delete selected', deleteOff: true, all: false, allOff: true, status: '', count: '0 of 4 shown'}, 'nothing shown: nothing to delete or select');
mixed.filter('');
mixed.hideAutomatic(true);
assert.deepEqual(mixed.shown(), ['daily', 'Weekly'], 'Hide Sanoid and Syncoid snapshots');
await mixed.sortBy(2);
await mixed.sortBy(2);
mixed.hideAutomatic(false);
assert.deepEqual(mixed.shown(), ['Weekly', 'syncoid_tower_2026-11-06:00:00:02-GMT08:00', 'autosnap_2026-11-06_00:00:01_hourly', 'daily'], 'and back, in the sorted order');

// Every action's result goes through showResult: a good answer, an unreadable one, and a failed request each show a dialog.
const helpers = ['function parseJSONResponse(', 'function escapeHtml(', 'function showResult(', 'function formatAnswer('].map(from => {
  const at = page.indexOf(from);
  assert.ok(at > 0, from+' found in the page');
  return page.slice(at, page.indexOf('\n  }\n', at)+4);
}).join('\n');
const results = load([]);
const shown = [];
results.window.Swal2 = {fire: options => { shown.push([options.icon, options.html ?? options.text]); return new Promise(() => {}); }};
results.window.eval(helpers);
const answer = (outcome, data) => { const request = results.window.$.Deferred(); results.window.showResult('Delete Result', request.promise()); outcome === 'done' ? request.resolve(data) : request.reject(); };
answer('done', JSON.stringify({succeeded: {'tank/data@a': 'Success'}, failed: {}}));
answer('done', '<html>Login</html>');
answer('fail');
assert.deepEqual(shown, [
  ['info', '&emsp;&emsp;tank/data@a: Success<br>'],
  ['error', 'The answer could not be read. The page will reload to show what changed.'],
  ['error', 'The request failed. The page will reload to show what changed.'],
], 'every outcome shows a dialog');

console.log('snapshot admin list, filter, sort and result tests passed');
})().catch(error => { console.error(error); process.exit(1); });
