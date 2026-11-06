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

// [name, used bytes, created epoch, used as shown, dataset], in the order zfs listed them. With children the page lists whole names.
function load(snapshots, {children = false} = {}) {
  const rows = snapshots.map(([name, used, created, usedText = '', dataset = 'tank/data']) => [children ? dataset+'@'+name : name, name, used, created, usedText, dataset]).map(([listed, name, used, created, usedText, dataset]) => `<tr data-snapshot="${listed}" data-automatic="${/^(autosnap_|syncoid_)/.test(name) ? 1 : 0}">`
    + `<td><input class="snapl-check" type="checkbox" value="${dataset}@${name}"></td><td data-text="${listed}">${listed}</td>`
    + `<td class="num" data-text="${used}">${usedText}</td><td class="num" data-text="0"></td><td class="num" data-text="0"></td><td class="num" data-text="0"></td>`
    + `<td data-text="${created}"></td><td></td></tr>`).join('');
  const {window} = new JSDOM(`<!doctype html><body>${snapshots.length ? bar : ''}<table class="zfsm-snap-table">${thead[0]}<tbody id="zpools">${rows}</tbody></table>${footer[0]}</body>`, {runScripts: 'outside-only'});
  window.eval(fs.readFileSync(jquery, 'utf8'));
  window.eval(list + '\nzfsmInitSnapshotList();');
  const $ = window.$;
  const box = name => window.document.querySelector(`.snapl-check[value="${name.includes('@') ? name : 'tank/data@'+name}"]`);
  return {
    window,
    tick: name => { box(name).checked = true; $(box(name)).trigger('change'); },
    untick: name => { box(name).checked = false; $(box(name)).trigger('change'); },
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

// With child datasets: Delete works on one dataset's snapshots, and the filter matches the dataset too.
const family = [['daily', 5, 1, '', 'tank/a'], ['daily', 6, 2, '', 'tank/b'], ['weekly', 7, 3, '', 'tank/b']];
const kids = load(family, {children: true});
kids.tick('tank/b@daily');
kids.tick('tank/b@weekly');
assert.deepEqual(kids.state(), {delete: 'Delete selected (2)', deleteOff: false, all: false, allOff: false, status: '2 selected', count: ''}, 'two of one dataset ticked');
assert.equal(kids.window.zfsmTickedDataset(), 'tank/b');
kids.tick('tank/a@daily');
assert.deepEqual(kids.state(), {delete: 'Delete selected (3)', deleteOff: true, all: true, allOff: false, status: 'Delete works on one dataset at a time. Tick snapshots of one dataset, or filter by its name.', count: ''}, 'two datasets ticked: Delete waits for one');
assert.equal(kids.window.zfsmTickedDataset(), null);
kids.filter('tank/b@');
assert.deepEqual(kids.shown(), ['tank/b@daily', 'tank/b@weekly'], 'the filter matches the dataset name');
assert.equal(kids.state().deleteOff, false, 'the other dataset filtered out and unticked, Delete works again');
const reloads = [];
kids.window.zfsmShowChildren = children => reloads.push(children);
kids.window.$('#zfsm-children').prop('checked', false).trigger('change');
assert.deepEqual(reloads, [false], 'the Include child datasets box reloads the list');
const admin = 'http://tower/plugins/zfs.master/frontend/ZFSMAdminDatasetSnaps.php?zpool=tank&zdataset=tank';
assert.equal(kids.window.zfsmChildrenUrl(admin, true), admin+'&children=1');
assert.equal(kids.window.zfsmChildrenUrl(admin+'&children=1', false), admin);

// The page's own Delete handler: never one request for two datasets, whose snapshot names would land on the first.
const pageFunction = from => {
  const at = page.indexOf(from);
  assert.ok(at > 0, from+' found in the page');
  return page.slice(at, page.indexOf('\n  }\n', at)+4).replaceAll('<?=$urlzmadmin?>', '/zfsm-admin');
};
const deleting = load(family, {children: true});
const reviews = [];
deleting.window.zfsmFetchReview = (url, data) => reviews.push(data.target);
deleting.window.zfsm_csrf_token = 'token';
deleting.window.eval(pageFunction('function deleteSnapshots('));
deleting.tick('tank/a@daily');
deleting.tick('tank/b@daily');
deleting.window.deleteSnapshots();
assert.deepEqual(reviews, [], 'snapshots of two datasets were sent as one dataset\'s');
deleting.untick('tank/a@daily');
deleting.tick('tank/b@weekly');
deleting.window.deleteSnapshots();
assert.deepEqual(reviews, ['tank/b@daily,weekly'], 'one dataset\'s snapshots go in one request under its name');

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
