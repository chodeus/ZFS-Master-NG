// Where the Create Dataset result shows: the Main page's dialogs open under the Shadowbox, so a failure must show inside it.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMCreateDataset.php'), 'utf8');
function slice(from, to) {
  const start = page.indexOf(from);
  const end = page.indexOf(to, start);
  assert.ok(start > 0 && end > start, from+' found in ZFSMCreateDataset.php');
  return page.slice(start, end);
}
const script = slice('function escapeHtml(', 'function getFormData(')+slice('function createDataset()', '</script>');
assert.match(page, /<script src="[^"]*\/assets\/sweetalert2\.all\.min\.js/, 'the dialog loads its own SweetAlert');

function run(answer) {
  const calls = [];
  const top = {
    Swal2: {fire: options => calls.push(['main page', options.icon, options.html])},
    Shadowbox: {close: () => calls.push(['close'])},
    requestRefresh: () => calls.push(['refresh']),
  };
  const context = vm.createContext({
    top,
    Swal2: {fire: options => calls.push(['dialog', options.icon, options.html])},
    $: {post: (url, data, done) => done(JSON.stringify(answer))},
    getFormData: () => ({}),
    parseJSONResponse: value => typeof value === 'string' ? JSON.parse(value) : value,
    zfsm_csrf_token: 'token',
  });
  vm.runInContext(script+'\ncreateDataset();', context);
  return calls;
}

assert.deepEqual(run({succeeded: {}, failed: {'tank/a': 'A dataset with this name already exists.'}}),
  [['refresh'], ['dialog', 'error', '&emsp;&emsp;tank/a: A dataset with this name already exists.<br>']], 'a failure shows in the dialog, which stays open');
assert.deepEqual(run({succeeded: {}, failed: {'tank/a': 'Created, but it could not be made read-only: denied'}}),
  [['refresh'], ['dialog', 'error', '&emsp;&emsp;tank/a: Created, but it could not be made read-only: denied<br>']], 'a dataset created with a problem still appears on the Main page');
assert.deepEqual(run({succeeded: {'tank/a': 'Success'}, failed: {}}),
  [['refresh'], ['main page', 'info', '&emsp;&emsp;tank/a: Success<br>'], ['close']], 'a success shows on the Main page and closes the dialog');
assert.equal(run({succeeded: {}, failed: {'tank/<b>': 'bad & worse'}})[1][2], '&emsp;&emsp;tank/&lt;b&gt;: bad &amp; worse<br>', 'the message is escaped');

console.log('create dataset result tests passed');
