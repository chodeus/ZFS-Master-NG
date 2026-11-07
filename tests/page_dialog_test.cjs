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
  const code = page.slice(start, end).replaceAll('<?=$urlzmadmin?>', '/zfsm-admin').replaceAll('<?=$urladmindatasetsnaps?>', '/zfsm-snaps');
  assert.equal(code.includes('<?'), false, 'unhandled PHP in ' + from);
  return code;
}

const {window} = new JSDOM('<!doctype html><body></body>', {url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true});
window.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}});
window.eval(fs.readFileSync(jquery, 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'assets/sweetalert2.all.min.js'), 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8'));
window.eval(handlers('function getPropertyEditHTML(', 'function adminDatasetSnaps('));
window.eval(handlers('function renameDataset(', 'function addDirectoryListing('));
window.eval(handlers('function moveDirectory(', 'function convertDirectory('));

const posts = [], replies = [];
let properties = {};
window.$.post = (url, data, done) => {
  posts.push({...data});
  if (data.cmd === 'getdatasetproperties') done(JSON.stringify(properties));
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
  window.document.body.insertAdjacentHTML('beforeend', '<table id="zfs_master"><tbody id="zfs_master_body"></tbody></table>');
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

  // Snapshots Admin is offered when the dataset or a child has snapshots; with only the child's, it opens listing them.
  window.eval(handlers('function addDatasetContext(', 'function removeContext('));
  window.eval(handlers('function adminDatasetSnaps(', 'function unlockDataset('));
  window.zfsm_config = {directory_listing: []};
  window._ = text => text;
  let menu = [];
  window.context = {attach: (id, options) => { menu = options; }};
  const opened = [];
  window.openBox = url => opened.push(url);
  const snapshotsAdmin = (own, children) => {
    window.addDatasetContext('tank', 'tank', own, 'menu', 0, 'none', '', 'filesystem', children);
    return menu.find(option => option.text === 'Snapshots Admin');
  };
  assert.equal(snapshotsAdmin(0, 0), undefined, 'no snapshots anywhere: no Snapshots Admin');
  assert.ok(snapshotsAdmin(0, 3), 'snapshots only under it: Snapshots Admin must be offered');
  snapshotsAdmin(0, 3).action({preventDefault() {}});
  snapshotsAdmin(2, 3).action({preventDefault() {}});
  assert.deepEqual(opened, ['/zfsm-snaps?zpool=tank&zdataset=tank&children=1', '/zfsm-snaps?zpool=tank&zdataset=tank'], 'only snapshots under it: the list opens with the child datasets');

  // Icons that act as buttons take Enter and Space; the fold icon says whether its dataset is folded.
  window.document.body.insertAdjacentHTML('afterbegin', page.match(/<div id="zfs-master-title"[\s\S]*?\n<\/div>/)[0]);
  // jsdom here runs no onclick attributes (runScripts outside-only); a browser runs the clicked one.
  window.document.addEventListener('click', event => { const run = event.target.getAttribute('onclick'); if (run) window.eval(run); });
  const keyEvent = (element, type, name) => element.dispatchEvent(new window.KeyboardEvent(type, {key: name, bubbles: true, cancelable: true}));
  // A key press is a keydown then a keyup; says whether the keydown went through.
  const key = (element, name) => { const passed = keyEvent(element, 'keydown', name); keyEvent(element, 'keyup', name); return passed; };
  const fold = () => window.document.querySelector('i[name="tank"]');
  assert.equal(key(fold(), 'a'), true, 'other keys pass through');
  assert.equal(fold().getAttribute('aria-expanded'), 'true', 'and fold nothing');
  assert.equal(key(fold(), 'Enter'), false, 'Enter is taken by the icon');
  assert.deepEqual([fold().getAttribute('aria-expanded'), fold().classList.contains('fa-plus-square')], ['false', true], 'Enter folds the dataset');
  redraw();
  assert.equal(fold().getAttribute('aria-expanded'), 'false', 'a redraw keeps it folded, and says so');
  assert.deepEqual([keyEvent(fold(), 'keydown', ' '), fold().getAttribute('aria-expanded')], [false, 'false'], 'Space down is taken, so the page does not scroll, and presses nothing yet');
  keyEvent(fold(), 'keyup', ' ');
  assert.deepEqual([fold().getAttribute('aria-expanded'), fold().classList.contains('fa-minus-square')], ['true', true], 'Space unfolds it as the key comes up');
  // A redraw replaces the rows, so keyboard focus goes back to the same control; focus elsewhere stays put.
  fold().focus();
  redraw();
  assert.equal(window.document.activeElement, fold(), 'focus on a fold icon survives a redraw');
  const actions = () => window.document.getElementById(window.datasetRowId('tank/new')).querySelector('button');
  actions().focus();
  redraw();
  assert.equal(window.document.activeElement, actions(), 'and on an Actions button');
  const refresh = () => window.document.getElementById('zfsm-refresh').parentNode;
  assert.deepEqual([refresh().getAttribute('role'), refresh().tabIndex], ['button', 0], 'Refresh takes keyboard focus as a button');
  refresh().focus();
  redraw();
  assert.equal(window.document.activeElement, refresh(), 'focus outside the table stays where it is');
  let reads = requests.length;
  key(refresh(), 'Enter');
  assert.equal(requests.length, reads + 1, 'Enter on Refresh reads the inventory again');
  answer();
  // Unraid's non-tabbed view moves the bar into its title line, and the buttons must still take the keys there.
  const bar = window.document.getElementById('zfs-master-title');
  bar.insertAdjacentHTML('beforebegin', '<div class="title"><span class="left">Main</span></div>');
  assert.equal(window.zfsmMoveStatusIntoTitle(bar), true, 'the bar moved into the title line');
  reads = requests.length;
  key(refresh(), ' ');
  assert.equal(requests.length, reads + 1, 'Space on Refresh reads the inventory again from the title line');
  answer();
  const outside = window.document.createElement('span');
  outside.setAttribute('role', 'button');
  let outsidePressed = 0;
  outside.onclick = () => { outsidePressed++; };
  window.document.body.append(outside);
  assert.deepEqual([key(outside, 'Enter'), outsidePressed], [true, 0], 'a button outside the plugin is not ours to press');

  console.log('page dialog tests passed on jQuery ' + window.$.fn.jquery);
  window.close();
})().catch(error => { console.error(error); process.exit(1); });
