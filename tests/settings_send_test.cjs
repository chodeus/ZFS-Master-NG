// The Settings page's replication script (Snapshot Every Dataset, Replicate To, and the Accept Replicas Into, Keep Replicas and Snapshots Pattern checks) on this Unraid version's jQuery: what it does with each answer ZFSMAdmin.php can give;
// and what Keep Replicas' field lets Apply send.
// Usage: settings_send_test.cjs <webgui-tree>
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const {execFileSync} = require('node:child_process');
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
  + '<input id="zfsm-send-name"><input id="zfsm-send-host"><input id="zfsm-send-port" value="22"><span id="zfsm-send-found"></span>'
  + '<input name="replica_accept"><span id="zfsm-replica-accept-warning"></span><input name="replica_keep"><span id="zfsm-replica-keep-warning"></span>'
  + '<input name="snap_prefix" value="auto-"><input name="snap_pattern"><span id="zfsm-snap-name-warning"></span><select name="snap_utc"><option value="0">Local</option><option value="1" selected>UTC</option></select>'
  + '<dl><dt>Snapshot Every Dataset:</dt><dd><select id="zfsm-snapsched-every"><option value="off" selected>Off</option><option value="hourly">Every hour</option><option value="days">On days</option></select></dd>'
  + '<dt>Days:</dt><dd><span id="zfsm-snapsched-days"><input type="checkbox" class="zfsm-snapsched-day" value="1" checked><input type="checkbox" class="zfsm-snapsched-day" value="2">'
  + '<input type="checkbox" class="zfsm-snapsched-day" value="0" checked></span></dd><dt>Starts At:</dt><dd><input id="zfsm-snapsched-time" value="04:00"></dd>'
  + '<dt>Snapshots To Keep:</dt><dd><input id="zfsm-snapsched-keep" value="3"></dd>'
  + '<dt>&nbsp;</dt><dd><input type="button" id="zfsm-snapsched-save"> <span id="zfsm-snapsched-status"></span></dd>'
  + '<dt>Replicate Every Dataset:</dt><dd><select id="zfsm-copysched-every"><option value="off">Off</option><option value="hourly" selected>Every hour</option><option value="days">On days</option></select></dd>'
  + '<dt>Copy Under:</dt><dd><input id="zfsm-copysched-under" value="backup"></dd>'
  + '<dt>Days:</dt><dd><span id="zfsm-copysched-days"><input type="checkbox" class="zfsm-copysched-day" value="3" checked><input type="checkbox" class="zfsm-copysched-day" value="5"></span></dd>'
  + '<dt>Starts At:</dt><dd><input id="zfsm-copysched-time" value="02:30"></dd><dt>Snapshots To Keep:</dt><dd><input id="zfsm-copysched-keep" value="7d"></dd>'
  + '<dt>&nbsp;</dt><dd><input type="button" id="zfsm-copysched-save"> <span id="zfsm-copysched-status"></span></dd></dl>'
  + '<span id="zfsm-schedules"><span>tank/data to backup/data</span></span><span id="zfsm-schedules-status"></span></body>',
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
const refused = posts.pop();
assert.deepEqual({...refused.data}, {cmd: 'replicaadd', name: 'nas', host: '10.0.20.11', port: '2222', key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});

// A refused Add says why beside the host key read and keeps its Add: a name put right goes without another read.
refused.done({error: 'nas is already a server here; remove it first to change it'});
const box = $('#zfsm-send-found input[type=checkbox]');
assert.deepEqual([add.prop('disabled'), box.prop('disabled'), box.prop('checked')], [false, false, true], 'Add and its ticked box are back on');
assert.match(text('zfsm-send-found'), /^Host key SHA256:abc .* nas is already a server here; remove it first to change it$/, 'the reason shows after the host key read');
$('#zfsm-send-name').val('nas2');
add.trigger('click');
assert.equal(text('zfsm-send-found').includes('already a server'), false, 'the reason goes when Add runs again');
const again = posts.pop();
assert.deepEqual({...again.data}, {cmd: 'replicaadd', name: 'nas2', host: '10.0.20.11', port: '2222', key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'}, 'the same host key goes with the new name');
again.done({servers_html: '<span>nas2</span>'});
assert.deepEqual([text('zfsm-send-found'), $('#zfsm-send-name').val(), $('#zfsm-send-host').val(), text('zfsm-send-servers')], ['', '', '', 'nas2'], 'an Add that worked lists the server and clears the read and its fields');

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

// Accept Replicas Into: empty asks nothing; each answer shows what is wrong with the dataset typed.
assert.equal(posts.length, 0, 'an empty setting is not checked on load');
const accept = value => {
  $('input[name="replica_accept"]').val(value).trigger('change');
  return posts.pop();
};
const check = accept('backup/replicas');
assert.deepEqual([check.data.cmd, check.data.dataset], ['validateaccept', 'backup/replicas']);
check.done({valid: true, missing: true});
assert.equal(text('zfsm-replica-accept-warning'), 'This dataset does not exist, so nothing is accepted: create it first (Main, Create Dataset).');
accept('backup/replicas').done({valid: true, missing: false});
assert.equal(text('zfsm-replica-accept-warning'), '', 'a dataset that exists has no warning');
accept('backup/replicas').done({valid: true, missing: null});
assert.equal(text('zfsm-replica-accept-warning'), 'Whether this dataset exists could not be read.');
accept('backup').done({valid: false, missing: null});
assert.equal(text('zfsm-replica-accept-warning'), 'Not a dataset inside a pool, so nothing is accepted.');
accept('backup/replicas').done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.equal(text('zfsm-replica-accept-warning'), 'This dataset could not be checked: Unknown or missing command', 'a failed check says so');
$('input[name="replica_accept"]').val('').trigger('change');
assert.deepEqual([posts.length, text('zfsm-replica-accept-warning')], [0, ''], 'emptied, it asks nothing and warns of nothing');
// An answer for a value changed since is dropped.
const older = accept('backup/old');
accept('backup/replicas').done({valid: true, missing: false});
older.done({valid: true, missing: true});
assert.equal(text('zfsm-replica-accept-warning'), '', 'the older answer does not replace the newer one');

// Keep Replicas, asked as it is typed (input, not change, which waits for the field to lose the focus, often to Apply itself): what the server can't read is said,
// and Apply refused on it once it answers, until the field changes; empty asks nothing.
const keepIn = $('input[name="replica_keep"]')[0];
const keepAsk = value => {
  $(keepIn).val(value).trigger('input');
  return posts.pop();
};
const unread = '"7d 7d" is not read, so all stay: give a number up to 9999';
const keepCheck = keepAsk('7d 7d');
assert.deepEqual([keepCheck.data.cmd, keepCheck.data.keep], ['validatekeep', '7d 7d']);
keepCheck.done({warning: unread});
assert.deepEqual([text('zfsm-replica-keep-warning'), keepIn.validationMessage], [unread, unread], 'what the server can\'t read is said, and refuses Apply');
keepAsk('7d').done({warning: ''});
assert.deepEqual([text('zfsm-replica-keep-warning'), keepIn.validity.valid], ['', true], 'what it reads clears both');
keepAsk('7d 7d').done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.deepEqual([text('zfsm-replica-keep-warning'), keepIn.validity.valid], ['This could not be checked: Unknown or missing command', true], 'a failed check says so, and Apply still goes');
const keepOlder = keepAsk('7d 7d');
keepAsk('7d').done({warning: ''});
keepOlder.done({warning: unread});
assert.deepEqual([text('zfsm-replica-keep-warning'), keepIn.validity.valid], ['', true], 'the older answer does not replace the newer one');
keepAsk('3x').done({warning: unread});
$(keepIn).val(' ').trigger('input');
assert.deepEqual([posts.length, text('zfsm-replica-keep-warning'), keepIn.validity.valid], [0, '', true], 'emptied, it asks nothing and refuses nothing');

// Snapshots Pattern, Prefix and Time, asked as each changes: a name ZFS refuses is said, and refuses Apply on Pattern and Prefix, until the server takes one.
const patternIn = $('input[name="snap_pattern"]')[0];
const prefixIn = $('input[name="snap_prefix"]')[0];
const nameAsk = (field, value) => {
  $(field).val(value).trigger(field.tagName === 'SELECT' ? 'change' : 'input');
  return posts.pop();
};
const nameShown = () => [text('zfsm-snap-name-warning'), patternIn.validationMessage, prefixIn.validationMessage];
const untaken = 'Snapshots Pattern and Prefix in Settings give new snapshots a name ZFS refuses, such as @auto-2026/11';
const nameCheck = nameAsk(patternIn, 'Y/m');
assert.deepEqual({...nameCheck.data}, {cmd: 'validatesnapname', pattern: 'Y/m', prefix: 'auto-', utc: '1'});
nameCheck.done({warning: untaken});
assert.deepEqual(nameShown(), [untaken, untaken, untaken], 'a name ZFS refuses is said, and refuses Apply on both fields');
nameAsk(prefixIn, 'auto').done({warning: ''});
assert.deepEqual(nameShown(), ['', '', ''], 'one it takes clears them');
const utcCheck = nameAsk($('select[name="snap_utc"]')[0], '0');
assert.ok(utcCheck, 'Snapshot Time asks too');
assert.deepEqual({...utcCheck.data}, {cmd: 'validatesnapname', pattern: 'Y/m', prefix: 'auto', utc: '0'});
nameAsk(patternIn, 'Y/m').done({warning: untaken});
nameAsk(patternIn, 'Y/m/').done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.deepEqual(nameShown(), ['This could not be checked: Unknown or missing command', '', ''], 'a failed check after a refusal says so, and Apply goes again');
const nameOlder = nameAsk(patternIn, 'Y/m');
nameAsk(prefixIn, 'x').done({warning: ''});
nameOlder.done({warning: untaken});
assert.deepEqual(nameShown(), ['', '', ''], 'an answer asked before another field changed does not replace the newer one');

// Keep Replicas, its tag as the page writes it: the browser refuses Apply on what is no number or periods, at once; the server check above
// catches the rest. Every value the server reads gets through (php on PATH runs zfsmKeepParse).
const form = window.document.createElement('form');
form.innerHTML = page.replace(/<\?=.*?\?>/g, '').match(/<input [^>]*name="replica_keep"[^>]*>/)[0];
const keep = form.querySelector('input');
const keeps = ['', '0', '12', '9999', '24 7d 4w 12m', '1H,2y', ' 3 ', ',7d', '7d,,4w', '1O', '-5', '2.5', '3 days', '7x', 'd', '7d\u00a04w', '\u00a0'];
const browser = keeps.map(value => { keep.value = value; return form.checkValidity(); });
assert.deepEqual(browser, [true, true, true, true, true, true, true, true, true, false, false, false, false, false, false, false, false], 'Keep Replicas takes numbers and periods');
const server = JSON.parse(execFileSync('php', ['-n', '-r', 'require $argv[1]; echo json_encode(array_map(function($v) { return zfsmKeepParse($v) !== null; }, json_decode($argv[2])));',
  path.join(__dirname, '../zfs.master/include/ZFSMKeepRules.php'), JSON.stringify(keeps)], {encoding: 'utf8'}));
assert.deepEqual(keeps.filter((value, i) => server[i] && !browser[i]), [], 'the browser lets through every value the server reads');

// Schedules: Remove asks for the schedule by its id; a refusal says why and keeps the list, and the list it answers replaces it.
window.zfsmScheduleRemove('0a1b2c3d');
const remove = posts.pop();
assert.deepEqual([remove.data.cmd, remove.data.id, Object.keys(remove.data).length], ['scheduleremove', '0a1b2c3d', 2]);
remove.done({error: 'No such schedule; it may have been removed already'});
assert.deepEqual([text('zfsm-schedules-status'), text('zfsm-schedules')], ['No such schedule; it may have been removed already', 'tank/data to backup/data'], 'a refusal says why and keeps the list');
window.zfsmScheduleRemove('0a1b2c3d');
posts.pop().done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.deepEqual([text('zfsm-schedules-status'), text('zfsm-schedules')], ['Unknown or missing command', 'tank/data to backup/data'], 'as does a backend failure');
window.zfsmScheduleRemove('0a1b2c3d');
posts.pop().done({schedules_html: 'None yet'});
assert.deepEqual([text('zfsm-schedules-status'), text('zfsm-schedules')], ['', 'None yet'], 'a removal shows the list it answers, and clears the last refusal');
window.zfsmScheduleRemove('0a1b2c3d');
posts.pop().done({error: 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (x)', schedules_html: 'tank/a to backup/a'});
assert.deepEqual([text('zfsm-schedules-status'), text('zfsm-schedules')], ['The schedules were saved, but Unraid\'s crontab could not be rebuilt (x)', 'tank/a to backup/a'], 'one saved whose crontab was not rebuilt says so, and shows the list it saved');

// Snapshot Every Dataset: Off shows only its own row, Every hour adds Keep, On days adds Days and Starts At too; each row with its name.
const shown = () => ['zfsm-snapsched-days', 'zfsm-snapsched-time', 'zfsm-snapsched-keep'].map(id => {
  const dd = window.document.getElementById(id).closest('dd');
  return [dd.style.display, dd.previousElementSibling.style.display].join('/');
});
assert.deepEqual(shown(), ['none/none', 'none/none', 'none/none'], 'Off on load shows none of its other rows');
$('#zfsm-snapsched-every').val('days').trigger('change');
assert.deepEqual(shown(), ['/', '/', '/'], 'On days shows them all');
$('#zfsm-snapsched-every').val('hourly').trigger('change');
assert.deepEqual(shown(), ['none/none', 'none/none', '/'], 'Every hour shows Keep only');
const color = () => window.document.getElementById('zfsm-snapsched-status').style.color;
// Save sends what the rows say, the ticked days in their order; it is off while it saves, and the answer's list replaces Settings'.
$('#zfsm-snapsched-every').val('days').trigger('change');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
const snapshots = posts.pop();
assert.deepEqual({...snapshots.data}, {cmd: 'schedulesnapshots', every: 'days', time: '04:00', days: '1,0', keep: '3'});
assert.deepEqual([$('#zfsm-snapsched-save').prop('disabled'), text('zfsm-snapsched-status')], [true, 'Saving…'], 'Save is off while it saves');
snapshots.done({said: 'Saved: a snapshot of each of 5 dataset(s)', schedules_html: '<span>Snapshot Every Dataset</span>'});
assert.deepEqual([$('#zfsm-snapsched-save').prop('disabled'), text('zfsm-snapsched-status'), color(), text('zfsm-schedules')],
  [false, 'Saved: a snapshot of each of 5 dataset(s)', '', 'Snapshot Every Dataset'], 'saved: says what, and the list shows it');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
posts.pop().done({error: 'Nothing was saved: tick at least one day'});
assert.deepEqual([$('#zfsm-snapsched-save').prop('disabled'), text('zfsm-snapsched-status'), color(), text('zfsm-schedules')],
  [false, 'Nothing was saved: tick at least one day', 'rgb(226, 40, 40)', 'Snapshot Every Dataset'], 'a refusal says why in red and keeps the list');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
posts.pop().done({said: 'Saved: x', error: 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (y)', schedules_html: 'z'});
assert.deepEqual([text('zfsm-snapsched-status'), color(), text('zfsm-schedules')],
  ['Saved: x. The schedules were saved, but Unraid\'s crontab could not be rebuilt (y)', 'rgb(226, 40, 40)', 'z'], 'saved with a crontab that was not rebuilt: both, in red');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
posts.pop().done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
assert.deepEqual([$('#zfsm-snapsched-save').prop('disabled'), text('zfsm-snapsched-status'), text('zfsm-schedules')], [false, 'Unknown or missing command', 'z'], 'as does a backend failure');

// Replicate Every Dataset: its own rows by its prefix, Copy Under with Keep; on load as saved (Every hour), and Save sends Copy Under too.
const copyShown = () => ['zfsm-copysched-under', 'zfsm-copysched-days', 'zfsm-copysched-time', 'zfsm-copysched-keep'].map(id => window.document.getElementById(id).closest('dd').style.display);
assert.deepEqual(copyShown(), ['', 'none', 'none', ''], 'Every hour on load shows Copy Under and Keep only');
$('#zfsm-copysched-every').val('off').trigger('change');
assert.deepEqual(copyShown(), ['none', 'none', 'none', 'none'], 'Off shows none of its other rows');
assert.deepEqual(shown(), ['/', '/', '/'], 'and leaves Snapshot Every Dataset\'s rows as they were');
$('#zfsm-copysched-every').val('days').trigger('change');
window.zfsmEverySave('zfsm-copysched', 'schedulecopies');
const copies = posts.pop();
assert.deepEqual({...copies.data}, {cmd: 'schedulecopies', every: 'days', time: '02:30', days: '3', keep: '7d', under: 'backup'});
assert.deepEqual([$('#zfsm-copysched-save').prop('disabled'), text('zfsm-copysched-status'), text('zfsm-snapsched-status')], [true, 'Saving…', 'Unknown or missing command'], 'its Save and answer are its own');
copies.done({said: 'Saved: a copy of each of 4 dataset(s) under backup', schedules_html: 'w'});
assert.deepEqual([$('#zfsm-copysched-save').prop('disabled'), text('zfsm-copysched-status'), text('zfsm-schedules')], [false, 'Saved: a copy of each of 4 dataset(s) under backup', 'w']);

console.log('settings send tests passed on jQuery ' + window.$.fn.jquery);
