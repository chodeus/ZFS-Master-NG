// Where the Create Dataset result shows: the Main page's dialogs open under the Shadowbox, so a failure must show inside it.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMCreateDataset.php'), 'utf8');
const start = page.indexOf('function createDataset()');
const end = page.indexOf('function formatAnswer(');
assert.ok(start > 0 && end > start, 'createDataset found in ZFSMCreateDataset.php');
assert.match(page, /<script src="[^"]*\/assets\/sweetalert2\.all\.min\.js/, 'the dialog loads its own SweetAlert');

function run(answer) {
  const calls = [];
  const top = {
    Swal2: {fire: options => calls.push(['main page', options.icon])},
    Shadowbox: {close: () => calls.push(['close'])},
    requestRefresh: () => calls.push(['refresh']),
  };
  const context = vm.createContext({
    top,
    Swal2: {fire: options => calls.push(['dialog', options.icon])},
    $: {post: (url, data, done) => done(JSON.stringify(answer))},
    getFormData: () => ({}),
    formatAnswer: () => '',
    parseJSONResponse: value => typeof value === 'string' ? JSON.parse(value) : value,
    zfsm_csrf_token: 'token',
  });
  vm.runInContext(page.slice(start, end)+'\ncreateDataset();', context);
  return calls;
}

assert.deepEqual(run({succeeded: {}, failed: {'tank/a': 'A dataset with this name already exists.'}}), [['refresh'], ['dialog', 'error']], 'a failure shows in the dialog, which stays open');
assert.deepEqual(run({succeeded: {}, failed: {'tank/a': 'Created, but it could not be made read-only: denied'}}), [['refresh'], ['dialog', 'error']], 'a dataset created with a problem still appears on the Main page');
assert.deepEqual(run({succeeded: {'tank/a': 'Success'}, failed: {}}), [['refresh'], ['main page', 'info'], ['close']], 'a success shows on the Main page and closes the dialog');

console.log('create dataset result tests passed');
