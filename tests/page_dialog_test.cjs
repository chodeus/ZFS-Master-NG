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

const {window} = new JSDOM('<!doctype html><body></body>', {runScripts: 'outside-only', pretendToBeVisual: true});
window.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}});
window.eval(fs.readFileSync(jquery, 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'assets/sweetalert2.all.min.js'), 'utf8'));
window.eval(fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8'));
window.eval(handlers('function getPropertyEditHTML(', 'function adminDatasetSnaps('));
window.eval(handlers('function renameDataset(', 'function addDirectoryListing('));
window.eval(handlers('function moveDirectory(', 'function convertDirectory('));

const posts = [];
let properties = {};
window.$.post = (url, data, done) => {
  posts.push({...data});
  if (data.cmd === 'getdatasetproperties') done(JSON.stringify(properties));
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

  // Copy progress on the dataset row: cloning first, cleared when the copy stops.
  window.eval(handlers('function zfsmHandleMessage(', '  $(function() {'));
  window.processHiddenDatasets = () => {};
  window.document.body.insertAdjacentHTML('beforeend', '<table><tr id="' + window.datasetRowId('tank/new') + '"><td></td><td></td></tr></table>');
  const progress = () => window.document.getElementById(window.datasetRowId('tank/new')).children[0].textContent;
  window.zfsmHandleMessage(JSON.stringify({op: 'clone_directory', data: {dataset: 'tank/new'}}));
  assert.equal(progress(), 'Cloning files…', 'the row must say the files are being cloned');
  window.zfsmHandleMessage(JSON.stringify({op: 'stop_directory_copy', data: {dataset: 'tank/new'}}));
  assert.equal(progress(), '', 'the row must clear when the copy stops');

  console.log('page dialog tests passed on jQuery ' + window.$.fn.jquery);
  window.close();
})().catch(error => { console.error(error); process.exit(1); });
