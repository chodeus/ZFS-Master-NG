// The Settings page's Replicate To script on this Unraid version's jQuery: what it does with each answer ZFSMAdmin.php can give.
// Usage: settings_send_test.cjs <webgui-tree>
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const tree = path.resolve(process.argv[2]);
const jquery = [path.join(tree, 'emhttp/plugins/dynamix/javascript/dynamix.js'), path.join(tree, 'plugins/dynamix/javascript/dynamix.js')].find(fs.existsSync);
assert.ok(jquery, 'no dynamix.js (Unraid jQuery) under ' + tree);
const page = fs.readFileSync(path.join(__dirname, '../zfs.master/ZFSMasterSettings.page'), 'utf8');
const from = page.indexOf('// Replicate To Other Servers:');
const to = page.indexOf('</script>', from);
assert.ok(from > 0 && to > from, 'the Replicate To script is in ZFSMasterSettings.page');
const code = page.slice(from, to);
assert.equal(code.includes('<?'), false, 'no PHP in the Replicate To script');

const {window} = new JSDOM('<!doctype html><body><span id="zfsm-send-key"></span><span id="zfsm-send-key-status"></span>'
  + '<span id="zfsm-send-servers"><span id="zfsm-send-6e6173"></span></span><span id="zfsm-send-servers-status"></span>'
  + '<input id="zfsm-send-name"><input id="zfsm-send-host"><input id="zfsm-send-port" value="22"><span id="zfsm-send-found"></span></body>',
  {url: 'http://localhost/', runScripts: 'outside-only'});
window.eval(fs.readFileSync(jquery, 'utf8'));
const posts = [];
window.$.post = (url, data, done) => {
  posts.push({url, data, done});
  return {fail() { return this; }};
};
window.eval(code);
const $ = window.$;
const text = id => window.document.getElementById(id).textContent;

// Make is off while it runs, and back on after a failure.
$('#zfsm-send-key').html('<input type="button" value="Make">');
window.zfsmSendKey();
assert.equal($('#zfsm-send-key input').prop('disabled'), true, 'Make is off while it runs');
posts.pop().done({error: 'Could not make the key folder'});
assert.deepEqual([$('#zfsm-send-key input').prop('disabled'), text('zfsm-send-key-status')], [false, 'Could not make the key folder'], 'and back on after a failure, saying why');

// ZFSMAdmin.php's catch-all answers {succeeded: {}, failed: {request: why}}, with no error field.
window.zfsmSendTest('nas', 'zfsm-send-6e6173');
assert.equal(text('zfsm-send-6e6173'), 'Asking…');
posts.pop().done({succeeded: {}, failed: {request: 'ssh timed out'}});
assert.equal(text('zfsm-send-6e6173'), 'ssh timed out', 'a backend failure shows as one, not as ready');
window.zfsmSendTest('nas', 'zfsm-send-6e6173');
posts.pop().done({area: 'backup/My Replicas/tower'});
assert.equal(text('zfsm-send-6e6173'), 'Ready: copies go into backup/My Replicas/tower');

// Add waits for the match box, then sends the address, port and key read, with the fingerprint shown.
$('#zfsm-send-name').val('nas');
$('#zfsm-send-host').val('10.0.20.11');
$('#zfsm-send-port').val('2222');
window.zfsmSendScan();
const scan = posts.pop();
assert.deepEqual([scan.data.cmd, scan.data.host, scan.data.port], ['replicascan', '10.0.20.11', '2222']);
scan.done({key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});
const add = $('#zfsm-send-found input[type=button]');
assert.equal(add.prop('disabled'), true, 'Add waits for the match box');
$('#zfsm-send-found input[type=checkbox]').prop('checked', true).trigger('change');
add.trigger('click');
assert.deepEqual([add.prop('disabled'), $('#zfsm-send-found input[type=checkbox]').prop('disabled')], [true, true], 'Add and its box are off while it runs');
// Copied out of jsdom's realm: strict deepEqual compares prototypes too.
assert.deepEqual({...posts.pop().data}, {cmd: 'replicaadd', name: 'nas', host: '10.0.20.11', port: '2222', key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});

// A scan's answer for an address changed since is dropped: nothing to add for the old one.
window.zfsmSendScan();
const stale = posts.pop();
$('#zfsm-send-host').val('10.0.20.12').trigger('input');
stale.done({key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});
assert.equal($('#zfsm-send-found input').length, 0, 'no Add for the old address');
window.zfsmSendAdd();
assert.equal(posts.length, 0, 'and nothing to send');

window.zfsmSendScan();
posts.pop().done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.equal(text('zfsm-send-found'), 'Unknown or missing command', 'a failed scan says why, with nothing to add');

// An Add answer that arrives after a newer read leaves that read, its Add and its address alone; the list still updates.
$('#zfsm-send-name').val('nas');
$('#zfsm-send-host').val('10.0.20.11').trigger('input');
window.zfsmSendScan();
posts.pop().done({key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});
$('#zfsm-send-found input[type=checkbox]').prop('checked', true).trigger('change');
$('#zfsm-send-found input[type=button]').trigger('click');
const late = posts.pop();
$('#zfsm-send-host').val('10.0.20.12').trigger('input');
window.zfsmSendScan();
posts.pop().done({key: 'ssh-ed25519 BBBB', fingerprint: 'SHA256:def'});
late.done({servers_html: '<span>nas</span>'});
assert.deepEqual([$('#zfsm-send-found input[type=button]').length, $('#zfsm-send-host').val(), text('zfsm-send-servers')], [1, '10.0.20.12', 'nas'], 'the newer read keeps its Add and address');

console.log('settings send tests passed on jQuery ' + window.$.fn.jquery);
