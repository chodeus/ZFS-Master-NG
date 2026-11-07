// The page's own dialog handlers on this Unraid version's jQuery and the bundled library: each reads back the controls it drew.
// Usage: page_dialog_test.cjs <webgui-tree>
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const tree = path.resolve(process.argv[2]);
const jquery = [path.join(tree, 'emhttp/plugins/dynamix/javascript/dynamix.js'), path.join(tree, 'plugins/dynamix/javascript/dynamix.js')].find(fs.existsSync);
assert.ok(jquery, 'no dynamix.js (Unraid jQuery) under ' + tree);
const root = path.resolve(process.argv[3] || path.join(__dirname, '../zfs.master'));
const page = fs.readFileSync(path.join(root, 'ZFSMaster.page'), 'utf8');

function handlers(from, to) {
  const start = page.indexOf(from);
  const end = page.indexOf(to, start);
  assert.ok(start > 0 && end > start, from + ' found in ZFSMaster.page');
  const code = page.slice(start, end).replaceAll('<?=$urlzmadmin?>', '/zfsm-admin');
  assert.equal(code.includes('<?'), false, 'unhandled PHP in ' + from);
  return code;
}

const {window} = new JSDOM('<!doctype html><body></body>', {url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true});
window.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}});
window.eval(fs.readFileSync(jquery, 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'assets/sweetalert2.all.min.js'), 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'frontend/ZFSMImpact.js'), 'utf8'));
window.eval(handlers('function getPropertyEditHTML(', 'function adminDatasetSnaps('));
window.eval(handlers('function renameDataset(', 'function addDirectoryListing('));
window.eval(handlers('function moveDirectory(', 'function convertDirectory('));

const posts = [], replies = [];
let properties = {};
let rewritePreview = {};
window.$.post = (url, data, done) => {
  posts.push({...data});
  if (data.cmd === 'getdatasetproperties') done(JSON.stringify(properties));
  if (data.cmd === 'previewrewrite') done(JSON.stringify(rewritePreview));
  const reply = window.$.Deferred();
  replies.push(reply);
  return reply.promise();
};
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => window.setTimeout(resolve, 0)); };
const shown = () => window.document.querySelector('.swal2-html-container');
async function open(handler, ...args) {
  window[handler](...args);
  await settle();
  assert.equal(shown().style.display, 'grid', handler + ' lost the page grid layout of its dialog');
  return shown();
}
async function confirm() {
  window.Swal2.clickConfirm();
  await settle();
  return posts.at(-1);
}

(async () => {
  const rename = await open('renameDataset', 'tank/data');
  const name = rename.querySelector('input[type="mytext"]');
  name.value = '';
  posts.length = 0;
  await confirm();
  assert.equal(posts.length, 0, 'an empty name must not be sent');
  assert.match(window.document.querySelector('.swal2-validation-message').textContent, /Enter a value for the dataset name/);
  name.value = 'tank/renamed';
  rename.querySelector('input[type="checkbox"]').checked = true;
  assert.deepEqual(await confirm(), {cmd: 'renamedataset', zdataset: 'tank/data', newname: 'tank/renamed', force: 1});

  properties = {compression: 'lz4', mountpoint: '/mnt/tank/data', quota: 2473901162496};
  let select = (await open('editDatasetProperty', 'tank/data', 'compression')).querySelector('select');
  assert.equal(select.value, 'lz4', 'the current value is preselected');
  select.value = 'zstd';
  assert.deepEqual(await confirm(), {cmd: 'editdatasetproperty', zdataset: 'tank/data', property: 'compression', value: 'zstd'});

  // Confirming the value the dialog opened with must change nothing: not a rounded quota, not an inherited value.
  for (const [property, shownValue] of [['compression', 'lz4'], ['quota', '2.3TiB']]) {
    const control = (await open('editDatasetProperty', 'tank/data', property)).querySelector('#zfsm-swal-input');
    assert.equal(control.value, shownValue);
    posts.length = 0;
    await confirm();
    assert.deepEqual(posts, [], 'an unchanged ' + property + ' was sent');
  }

  properties = {compression: 'zstd-3'};
  select = (await open('editDatasetProperty', 'tank/data', 'compression')).querySelector('select');
  assert.equal(select.value, 'zstd-3', 'a value missing from the list opened on another value');
  posts.length = 0;
  await confirm();
  assert.deepEqual(posts, [], 'an unchanged zstd-3 was sent');

  (await open('editDatasetProperty', 'tank/data', 'mountpoint')).querySelector('input').value = '/mnt/tank/moved';
  assert.deepEqual(await confirm(), {cmd: 'editdatasetproperty', zdataset: 'tank/data', property: 'mountpoint', value: '/mnt/tank/moved'});

  (await open('moveDirectory', '/mnt/tank/data/dir')).querySelector('input').value = '/mnt/tank/data/moved';
  assert.deepEqual(await confirm(), {cmd: 'movedirectory', directory: '/mnt/tank/data/dir', newname: '/mnt/tank/data/moved'});

  // Copy progress through the page's own script: its feed handler, inventory controller and rows.
  const main = [...page.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(script => script.includes('var zfsm_csrf_token'));
  const requests = [];
  const feed = {};
  window.$.ajax = options => {
    const request = {options, done(callback) { this.resolve = callback; return this; }, fail() { return this; }};
    requests.push(request);
    return request;
  };
  window.NchanSubscriber = function() { this.on = (event, callback) => { feed[event] = callback; }; this.start = () => {}; };
  window.document.body.insertAdjacentHTML('beforeend', '<table><tbody id="zfs_master_body"></tbody></table>');
  window.eval(fs.readFileSync(path.join(root, 'frontend/ZFSMInventory.js'), 'utf8'));
  window.eval(main.replace(/<\?(?:php|=)[\s\S]*?\?>/g, php =>
    php.includes('$zfsm_cfg') ? JSON.stringify({version: '7.3.2', refresh_interval: 0, destructive_mode: 0, snap_max_days_alert: 30, directory_listing: []})
      : php.includes('$display') ? JSON.stringify({text: 0, warning: 80, critical: 90})
      : php.includes('$urlzmadmin') ? '/zfsm-admin' : ''));
  await settle();
  assert.equal(typeof feed.message, 'function', 'the page must subscribe to the copy feed');
  const send = op => feed.message(JSON.stringify({op, data: {dataset: 'tank/new'}}));
  const dataset = name => ({name, type: 'filesystem', used: 1, available: 1, mountpoint: '/mnt/' + name, snapshots: [], directories: [], child: {}});
  const inventory = () => {
    const tank = dataset('tank');
    tank.child['tank/new'] = dataset('tank/new');
    return {op: 'getAll', data: {pools: {tank: {Pool: 'tank', Health: 'ONLINE', Snapshots: 0}}, devices: {tank: ''}, datasets: {tank}, errors: {}}};
  };
  const answer = () => { assert.ok(requests.at(-1).resolve, 'no inventory request to answer'); requests.at(-1).resolve(inventory()); };
  const redraw = () => { window.requestRefresh(); answer(); };
  const progress = () => window.document.getElementById(window.datasetRowId('tank/new')).children[0].textContent;
  send('start_directory_copy');
  assert.equal(requests.length, 1, 'a copy must refresh the page so the new dataset gets its row');
  send('clone_directory');
  answer();
  assert.equal(progress(), 'Cloning files…', 'a row drawn after the message must say the files are being cloned');
  redraw();
  assert.equal(progress(), 'Cloning files…', 'a redraw must keep the progress');
  send('stop_directory_copy');
  assert.equal(progress(), '', 'the row must clear when the copy stops');
  redraw();
  assert.equal(progress(), '', 'a redraw after the copy must not bring the progress back');
  assert.equal(requests.length, 3, 'each redraw is one inventory read');

  // A lost stop message must not leave progress behind: a dropped feed and the finished Convert request each clear it.
  send('clone_directory');
  assert.equal(progress(), 'Cloning files…');
  assert.ok(feed.disconnect, 'the page must listen for a dropped feed');
  feed.disconnect();
  assert.equal(progress(), '', 'a dropped feed must clear the progress');
  redraw();
  assert.equal(progress(), '', 'a redraw after a dropped feed must not bring the progress back');
  send('clone_directory');
  window.convertDirectory('/mnt/tank/new', 'tank');
  await settle();
  window.Swal2.clickConfirm();
  await settle();
  assert.equal(posts.at(-1).cmd, 'convertdirectory');
  assert.equal(progress(), 'Cloning files…', 'progress must stay while the Convert request runs');
  replies.at(-1).reject();
  assert.equal(progress(), '', 'a Convert request that ended, even in failure, must clear the progress');

  // A rewrite reports on the same row: started, how many files are done, stopped.
  send('rewrite_start');
  assert.equal(progress(), 'Rewriting files…', 'the row says the files are being rewritten');
  feed.message(JSON.stringify({op: 'rewrite_progress', data: {dataset: 'tank/new', files: 42}}));
  assert.equal(progress(), 'Rewriting files… 42 done', 'the row counts the files done');
  redraw();
  assert.equal(progress(), 'Rewriting files… 42 done', 'a redraw keeps the count');
  send('rewrite_stop');
  assert.equal(progress(), '', 'the row clears when the rewrite stops');
  // A rewrite whose stop message was lost goes quiet: 12 seconds without word clear its row.
  const timers = [];
  const realTimeout = window.setTimeout;
  window.setTimeout = (callback, ms) => { timers.push({callback, ms}); return timers.length; };
  send('rewrite_start');
  window.setTimeout = realTimeout;
  assert.deepEqual(timers.map(timer => timer.ms), [12000], 'each rewrite message gives the row 12 seconds');
  timers[0].callback();
  assert.equal(progress(), '', 'a rewrite silent for 12 seconds no longer shows as running');

  // Rewrite Files: offered on a filesystem where zfs rewrite exists, reviewed, then started.
  window._ = text => text;
  let menu = [];
  window.context = {attach: (id, options) => { menu = options; }};
  const rewriteItem = (type, available) => {
    window.zfsm_config.rewrite = available;
    window.addDatasetContext('tank', 'tank/new', 0, 'menu', 0, 'none', '', type);
    return menu.find(option => option.text === 'Rewrite Files');
  };
  assert.equal(rewriteItem('volume', true), undefined, 'a volume has no files to rewrite');
  assert.equal(rewriteItem('filesystem', false), undefined, 'no zfs rewrite before OpenZFS 2.4');
  const shown = id => { const node = window.document.getElementById(id); return node ? node.style.display !== 'none' : null; };
  const box = () => window.document.getElementById('zfsm-rewrite-physical');
  // The pool's feature is enabled: the box starts off, with the full-send warning; ticking it warns that it is for good.
  rewritePreview = {can_proceed: true, physical_feature: 'enabled', snapshot_space: 1073741824};
  rewriteItem('filesystem', true).action({preventDefault() {}});
  await settle();
  assert.deepEqual(posts.at(-1), {cmd: 'previewrewrite', zdataset: 'tank/new'}, 'the dialog asks for its review first');
  const review = window.document.querySelector('.swal2-html-container').textContent;
  assert.match(review, /record size is not applied/, 'the review says a record size change is not applied');
  assert.match(review, /snapshots hold 1\.00 GiB now/, 'the review shows the snapshot space');
  assert.deepEqual([box().checked, shown('zfsm-rewrite-resend'), shown('zfsm-rewrite-activate')], [false, true, false], 'with the feature only enabled, -P starts off and the full-send warning shows');
  box().checked = true;
  box().dispatchEvent(new window.Event('change'));
  assert.deepEqual([shown('zfsm-rewrite-resend'), shown('zfsm-rewrite-activate')], [false, true], 'ticked, it warns that the feature turns on for good');
  assert.match(window.document.getElementById('zfsm-rewrite-activate').textContent, /Unraid before 7\.3 \(OpenZFS before 2\.4\) could then import this pool read-only only/);
  window.Swal2.clickConfirm();
  await settle();
  assert.deepEqual(posts.at(-1), {cmd: 'rewritedataset', zdataset: 'tank/new', physical: 1}, 'confirming starts the rewrite with -P as ticked');
  // Already active: nothing more to lose, so the box starts on.
  rewritePreview = {can_proceed: true, physical_feature: 'active', snapshot_space: 0};
  rewriteItem('filesystem', true).action({preventDefault() {}});
  await settle();
  assert.deepEqual([box().checked, shown('zfsm-rewrite-resend'), shown('zfsm-rewrite-activate')], [true, false, null], 'with the feature already active, -P starts on');
  box().checked = false;
  box().dispatchEvent(new window.Event('change'));
  window.Swal2.clickConfirm();
  await settle();
  assert.deepEqual(posts.at(-1), {cmd: 'rewritedataset', zdataset: 'tank/new', physical: 0}, 'unticked, no -P');
  // No feature: no box, and the full-send warning; a snapshot space that could not be read says so.
  rewritePreview = {can_proceed: true, physical_feature: '', snapshot_space: null};
  rewriteItem('filesystem', true).action({preventDefault() {}});
  await settle();
  assert.deepEqual([box(), shown('zfsm-rewrite-resend')], [null, true], 'without the feature there is no -P to choose');
  assert.match(window.document.querySelector('.swal2-html-container').textContent, /hold an amount that could not be read now/, 'an unread snapshot space is not shown as 0 B');
  window.Swal2.close();
  rewritePreview = {can_proceed: false, error: 'A rewrite of this dataset is already running'};
  rewriteItem('filesystem', true).action({preventDefault() {}});
  await settle();
  assert.match(window.document.querySelector('.swal2-html-container').textContent, /already running/, 'a refused review says why');
  posts.length = 0;
  window.Swal2.clickConfirm();
  await settle();
  assert.deepEqual(posts, [], 'a refused review starts nothing');
  window.Swal2.close();

  console.log('page dialog tests passed on jQuery ' + window.$.fn.jquery);
  window.close();
})().catch(error => { console.error(error); process.exit(1); });
