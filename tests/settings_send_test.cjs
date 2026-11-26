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
  + '<input name="replica_accept"><span id="zfsm-replica-accept-warning"></span><form><input name="replica_keep"><input type="hidden" name="replica_keep_rules" value="24 7d 4w"><span id="zfsm-replica-keep-warning"></span></form>'
  + '<input name="snap_prefix" value="auto-"><input name="snap_pattern"><span id="zfsm-snap-name-warning"></span><select name="snap_utc"><option value="0">Local</option><option value="1" selected>UTC</option></select>'
  + '<dl><dt>Snapshot Every Dataset:</dt><dd><select id="zfsm-snapsched-every"><option value="off" selected>Off</option><option value="hourly">Every hour</option><option value="days">On days</option><option value="cron">Custom (cron)</option><option value="start">When the array starts</option></select></dd>'
  + '<dt>Days:</dt><dd><span id="zfsm-snapsched-days"><input type="checkbox" class="zfsm-snapsched-day" value="1" checked><input type="checkbox" class="zfsm-snapsched-day" value="2">'
  + '<input type="checkbox" class="zfsm-snapsched-day" value="0" checked></span></dd><dt>Starts At:</dt><dd><input id="zfsm-snapsched-time" value="04:00"></dd><dt>Cron Line:</dt><dd><input id="zfsm-snapsched-cron" value=""></dd>'
  + '<dt>Snapshots To Keep:</dt><dd><input id="zfsm-snapsched-keep" value="3"></dd><dt>Leave Out:</dt><dd><input id="zfsm-snapsched-leave-out" value=""></dd>'
  + '<dt>&nbsp;</dt><dd><input type="button" id="zfsm-snapsched-save"> <span id="zfsm-snapsched-status"></span></dd>'
  + '<dt>Replicate Every Dataset:</dt><dd><select id="zfsm-copysched-every"><option value="off">Off</option><option value="hourly" selected>Every hour</option><option value="days">On days</option><option value="cron">Custom (cron)</option><option value="start">When the array starts</option></select></dd>'
  + '<dt>Server:</dt><dd><select id="zfsm-copysched-server"><option value="" selected>This server</option><option value="nas">nas</option></select></dd>'
  + '<dt>Copy Under:</dt><dd><input id="zfsm-copysched-under" value="backup"></dd>'
  + '<dt>Days:</dt><dd><span id="zfsm-copysched-days"><input type="checkbox" class="zfsm-copysched-day" value="3" checked><input type="checkbox" class="zfsm-copysched-day" value="5"></span></dd>'
  + '<dt>Starts At:</dt><dd><input id="zfsm-copysched-time" value="02:30"></dd><dt>Cron Line:</dt><dd><input id="zfsm-copysched-cron" value=""></dd><dt>Snapshots To Keep:</dt><dd><input id="zfsm-copysched-keep" value="7d"></dd>'
  + '<dt>Copy Keeps:</dt><dd><input id="zfsm-copysched-copy-keeps" value=" 30D "></dd><dt>Leave Out:</dt><dd><input id="zfsm-copysched-leave-out" value=" appdata, backup_* "></dd>'
  + '<dt>&nbsp;</dt><dd><input type="button" id="zfsm-copysched-save"> <span id="zfsm-copysched-status"></span></dd></dl>'
  + '<span id="zfsm-schedules"><span>tank/data to backup/data</span></span><span id="zfsm-schedules-status"></span></body>',
  {url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true});
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
assert.deepEqual({...refused.data}, {cmd: 'replicaadd', name: 'nas', server: '', host: '10.0.20.11', port: '2222', key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'});

// A refused Add says why beside the host key read and keeps its Add: a name put right goes without another read.
refused.done({error: 'nas is already a server here; remove it first to change it'});
const box = $('#zfsm-send-found input[type=checkbox]');
assert.deepEqual([add.prop('disabled'), box.prop('disabled'), box.prop('checked')], [false, false, true], 'Add and its ticked box are back on');
assert.match(text('zfsm-send-found'), /^Host key SHA256:abc .* nas is already a server here; remove it first to change it$/, 'the reason shows after the host key read');
$('#zfsm-send-name').val('nas2');
add.trigger('click');
assert.equal(text('zfsm-send-found').includes('already a server'), false, 'the reason goes when Add runs again');
const again = posts.pop();
assert.deepEqual({...again.data}, {cmd: 'replicaadd', name: 'nas2', server: '', host: '10.0.20.11', port: '2222', key: 'ssh-ed25519 AAAA', fingerprint: 'SHA256:abc'}, 'the same host key goes with the new name');
again.done({servers_html: '<span>nas2</span>', server_options: '<option value="nas">nas</option><option value="nas2">nas2</option>'});
assert.deepEqual([text('zfsm-send-found'), $('#zfsm-send-name').val(), $('#zfsm-send-host').val(), text('zfsm-send-servers')], ['', '', '', 'nas2'], 'an Add that worked lists the server and clears the read and its fields');
const serverChoice = () => [[...$('#zfsm-copysched-server option').map((i, option) => option.value + ':' + option.textContent)], $('#zfsm-copysched-server').val()];
assert.deepEqual(serverChoice(), [[':This server', 'nas:nas', 'nas2:nas2'], ''], 'and Server offers it, This server still chosen');

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
late.done({servers_html: '<span>nas</span>', server_options: '<option value="nas">nas</option><option value="nas2">nas2</option>'});
assert.deepEqual([$('#zfsm-send-found input[type=button]').length, $('#zfsm-send-host').val(), text('zfsm-send-servers')], [1, '10.0.20.12', 'nas'], 'the newer read keeps its Add and address');

// A Remove sends what Server has chosen, and its answer redraws Server's options with that choice kept.
$('#zfsm-copysched-server').val('nas2');
window.zfsmSendRemove('nas');
const removed = posts.pop();
assert.deepEqual({...removed.data}, {cmd: 'replicaremove', name: 'nas', server: 'nas2'});
removed.done({servers_html: '<span>nas2</span>', server_options: '<option value="nas2" selected>nas2</option>'});
assert.deepEqual([text('zfsm-send-servers'), serverChoice()], ['nas2', [[':This server', 'nas2:nas2'], 'nas2']], 'the removed server goes from Server, and nas2 stays chosen');
window.zfsmSendRemove('nas2');
posts.pop().done({error: 'Could not save /boot/config/plugins/zfs.master/ssh/remotes.json'});
assert.deepEqual([text('zfsm-send-servers-status'), serverChoice()], ['Could not save /boot/config/plugins/zfs.master/ssh/remotes.json', [[':This server', 'nas2:nas2'], 'nas2']], 'a refused Remove leaves Server as it was');
$('#zfsm-copysched-server').html('<option value="" selected>This server</option><option value="nas">nas</option>');

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

// Keep Replicas, asked as it is typed (input, not change, which waits for the field to lose the focus, often to Apply itself): Apply waits while it asks;
// what the server can't read is said, and refuses Apply until the field changes; a failed check lets Apply go; empty asks nothing.
const keepIn = $('input[name="replica_keep"]')[0];
const keepAsk = value => {
  $(keepIn).val(value).trigger('input');
  return posts.pop();
};
const unread = '"7d 7d" is not read, so all stay: give a number up to 9999';
const keepCheck = keepAsk('7d 7d');
assert.deepEqual([keepCheck.data.cmd, keepCheck.data.keep], ['validatekeep', '7d 7d']);
assert.equal(keepIn.validationMessage, 'Checking what this keeps', 'Apply waits while it asks');
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

// Apply sends Keep Replicas twice, as it stands then: an older version's Apply posts only replica_keep, so replica_keep_rules stays as sent here.
const keepRules = $('input[name="replica_keep_rules"]')[0];
const apply = value => {
  $(keepIn).val(value);
  keepIn.form.dispatchEvent(new window.Event('submit', {cancelable: true}));
  return keepRules.value;
};
assert.deepEqual([apply('7d 4w'), apply('10'), apply('')], ['7d 4w', '10', ''], 'each Apply sends the field as typed in both, emptied too');
assert.equal(posts.length, 0, 'Apply asks nothing more');

// Snapshots Pattern, Prefix and Time, asked as each changes: a name ZFS refuses is said, and refuses Apply on Pattern and Prefix; an answer that takes one, or a check that fails, lets Apply go.
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
const checking = 'Checking the names these give';
assert.deepEqual(nameShown(), ['', checking, checking], 'Apply waits on both fields while it asks');
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

// Snapshot Every Dataset: Off shows only its own row, Every hour adds Keep and Leave Out, On days Days and Starts At too, Custom (cron) Cron Line
// in place of those two; each row with its name.
const shown = () => ['zfsm-snapsched-days', 'zfsm-snapsched-time', 'zfsm-snapsched-keep', 'zfsm-snapsched-leave-out', 'zfsm-snapsched-cron'].map(id => {
  const dd = window.document.getElementById(id).closest('dd');
  return [dd.style.display, dd.previousElementSibling.style.display].join('/');
});
assert.deepEqual(shown(), ['none/none', 'none/none', 'none/none', 'none/none', 'none/none'], 'Off on load shows none of its other rows');
$('#zfsm-snapsched-every').val('days').trigger('change');
assert.deepEqual(shown(), ['/', '/', '/', '/', 'none/none'], 'On days shows all but Cron Line');
$('#zfsm-snapsched-every').val('hourly').trigger('change');
assert.deepEqual(shown(), ['none/none', 'none/none', '/', '/', 'none/none'], 'Every hour shows Keep and Leave Out only');
$('#zfsm-snapsched-every').val('cron').trigger('change');
assert.deepEqual(shown(), ['none/none', 'none/none', '/', '/', '/'], 'Custom (cron) shows Cron Line, Keep and Leave Out');
$('#zfsm-snapsched-cron').val('*/15 * * * *');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
const cron = posts.pop();
assert.deepEqual({...cron.data}, {cmd: 'schedulesnapshots', every: 'cron', time: '*/15 * * * *', days: '1,0', keep: '3', leave_out: ''}, 'its Save sends the Cron Line as the time');
cron.done({said: 'Saved', schedules_html: '<span>Snapshot Every Dataset</span>'});
$('#zfsm-snapsched-every').val('start').trigger('change');
assert.deepEqual(shown(), ['none/none', 'none/none', '/', '/', 'none/none'], 'When the array starts shows Keep and Leave Out only');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
const start = posts.pop();
assert.deepEqual({...start.data}, {cmd: 'schedulesnapshots', every: 'start', time: '04:00', days: '1,0', keep: '3', leave_out: ''}, 'its Save sends When the array starts');
start.done({said: 'Saved', schedules_html: '<span>Snapshot Every Dataset</span>'});
const color = () => window.document.getElementById('zfsm-snapsched-status').style.color;
// Save sends what the rows say, the ticked days in their order; it is off while it saves, and the answer's list replaces Settings'.
$('#zfsm-snapsched-every').val('days').trigger('change');
window.zfsmEverySave('zfsm-snapsched', 'schedulesnapshots');
const snapshots = posts.pop();
assert.deepEqual({...snapshots.data}, {cmd: 'schedulesnapshots', every: 'days', time: '04:00', days: '1,0', keep: '3', leave_out: ''}, 'an empty Leave Out is sent, so Save clears it');
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
const copyShown = () => ['zfsm-copysched-server', 'zfsm-copysched-under', 'zfsm-copysched-days', 'zfsm-copysched-time', 'zfsm-copysched-keep', 'zfsm-copysched-copy-keeps', 'zfsm-copysched-leave-out', 'zfsm-copysched-cron'].map(id => window.document.getElementById(id).closest('dd').style.display);
assert.deepEqual(copyShown(), ['', '', 'none', 'none', '', '', '', 'none'], 'Every hour on load shows Server, Copy Under, Keep, Copy Keeps and Leave Out only');
$('#zfsm-copysched-every').val('off').trigger('change');
assert.deepEqual(copyShown(), ['none', 'none', 'none', 'none', 'none', 'none', 'none', 'none'], 'Off shows none of its other rows');
assert.deepEqual(shown(), ['/', '/', '/', '/', 'none/none'], 'and leaves Snapshot Every Dataset\'s rows as they were');
$('#zfsm-copysched-every').val('days').trigger('change');
$('#zfsm-schedules').html('<span>tank/a to backup/a<input type="button" id="zfsm-row-remove"></span>');
window.zfsmEverySave('zfsm-copysched', 'schedulecopies');
const copies = posts.pop();
assert.deepEqual([$('#zfsm-row-remove').prop('disabled'), $('#zfsm-snapsched-save').prop('disabled')], [true, true], 'the list\'s buttons and the other Save are off while it saves: its answer redraws the list');
assert.deepEqual({...copies.data}, {cmd: 'schedulecopies', every: 'days', time: '02:30', days: '3', keep: '7d', under: 'backup', server: '', copy_keeps: ' 30D ', leave_out: ' appdata, backup_* '});
assert.deepEqual([$('#zfsm-copysched-save').prop('disabled'), text('zfsm-copysched-status'), text('zfsm-snapsched-status')], [true, 'Saving…', 'Unknown or missing command'], 'its Save and answer are its own');
copies.done({said: 'Saved: a copy of each of 4 dataset(s) under backup', schedules_html: 'w'});
assert.deepEqual([$('#zfsm-copysched-save').prop('disabled'), $('#zfsm-snapsched-save').prop('disabled'), text('zfsm-copysched-status'), text('zfsm-schedules')], [false, false, 'Saved: a copy of each of 4 dataset(s) under backup', 'w']);
// Another server: Copy Keeps is hidden, and none is sent, as its Keep Replicas decides; This server shows it again.
$('#zfsm-copysched-server').val('nas').trigger('change');
assert.deepEqual(copyShown(), ['', '', '', '', '', 'none', '', 'none'], 'a server hides Copy Keeps, not Leave Out');
window.zfsmEverySave('zfsm-copysched', 'schedulecopies');
assert.deepEqual({...posts.pop().data}, {cmd: 'schedulecopies', every: 'days', time: '02:30', days: '3', keep: '7d', under: 'backup', server: 'nas', copy_keeps: '', leave_out: ' appdata, backup_* '},
  'Save sends the server, Leave Out and no Copy Keeps');
$('#zfsm-copysched-server').val('').trigger('change');
assert.deepEqual(copyShown()[5], '', 'This server shows it again');
$('#zfsm-copysched-every').val('cron').trigger('change');
$('#zfsm-copysched-cron').val('5 3 1 * *');
assert.deepEqual(copyShown(), ['', '', 'none', 'none', '', '', '', ''], 'Custom (cron) shows Cron Line in place of Days and Starts At');
window.zfsmEverySave('zfsm-copysched', 'schedulecopies');
assert.deepEqual({...posts.pop().data}, {cmd: 'schedulecopies', every: 'cron', time: '5 3 1 * *', days: '3', keep: '7d', under: 'backup', server: '', copy_keeps: ' 30D ', leave_out: ' appdata, backup_* '},
  'and its Save sends the Cron Line as the time');
$('#zfsm-copysched-every').val('start').trigger('change');
assert.deepEqual(copyShown(), ['', '', 'none', 'none', '', '', '', 'none'], 'When the array starts shows Server, Copy Under, Keep, Copy Keeps and Leave Out only');
window.zfsmEverySave('zfsm-copysched', 'schedulecopies');
assert.equal(posts.pop().data.every, 'start', 'and its Save sends it');


// Change Keep: a dialog with its row's Keep and Copy Keeps as saved, from the buttons Settings' list makes (zfsmScheduleKeepButton). Save
// sends them as typed; a refusal shows in the dialog, as text, with what was typed and the list kept; one saved closes it and shows the
// list it answers, and one whose crontab was not rebuilt says so under it. One on a server has no Copy Keeps, and sends it empty.
const dialogFiles = ['assets/sweetalert2.all.min.js', 'frontend/ZFSMDialogs.js', 'frontend/ZFSMImpact.js', 'frontend/ZFSMForm.js'];
const pageLoads = [...page.matchAll(/autov\('\/plugins\/zfs\.master\/((?:assets|frontend)\/[\w.]+\.js)'\)/g)].map(match => match[1]);
assert.deepEqual(pageLoads.filter(file => dialogFiles.includes(file)), dialogFiles, 'ZFSMasterSettings.page loads the dialog files, in this order');
// jsdom has no scrollTo, which a closing dialog calls to put the page back.
window.scrollTo = () => {};
for (const file of dialogFiles) window.eval(fs.readFileSync(path.join(__dirname, '../zfs.master', file), 'utf8'));
const keepButtons = execFileSync('php', ['-n', '-r', 'function publish($c, $m) {} require $argv[1]; $job = json_decode($argv[2], true);'
  + ' echo zfsmScheduleKeepButton($job, "backup/data", "every hour at :05"), zfsmScheduleKeepButton(array("id" => "0b0b0b0b", "server" => "nas", "keep" => 3) + $job, "data on server nas", "when the array starts");',
  path.join(__dirname, '../zfs.master/backend/ZFSMOperations.php'), JSON.stringify({id: '0a1b2c3d', dataset: 'tank/data', dest: 'backup/data', server: '', every: 'hourly', minute: 5, hour: 0,
    days: [0, 1, 2, 3, 4, 5, 6], keep: '7d', copy_keeps: '30d 12m'})], {encoding: 'utf8'});
(async () => {
  const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => window.setTimeout(resolve, 0)); };
  const popup = () => window.Swal2.getPopup();
  const field = id => popup()?.querySelector('#zfsm-change-' + id);
  // jsdom lays nothing out, so Swal2.isVisible() is always false: a dialog is open while its container is in the page.
  const shown = () => window.document.querySelector('.swal2-container') !== null;
  const refusal = () => shown() && window.Swal2.getValidationMessage().style.display !== 'none' ? window.Swal2.getValidationMessage().textContent : null;
  const open = async which => {
    $('#zfsm-schedules').html('<table><tbody><tr><td>' + keepButtons + '</td></tr></tbody></table>');
    // outside-only runs no onclick attribute: call what it names.
    const button = $('#zfsm-schedules input[type=button]').get(which);
    assert.equal(button.getAttribute('onclick'), 'zfsmScheduleKeepDialog(this)');
    window.zfsmScheduleKeepDialog(button);
    await settle();
  };
  const save = async () => { window.Swal2.clickConfirm(); await settle(); return posts.pop(); };
  $('#zfsm-schedules-status').text('');
  await open(0);
  assert.deepEqual([popup().querySelector('.swal2-title').innerHTML, popup().querySelector('.swal2-html-container p').textContent, field('keep')?.value, field('copy-keeps')?.value],
    ['<strong>Change Keep<br>tank/data</strong>', 'Copied to backup/data, every hour at :05.', '7d', '30d 12m'], 'it opens with what the row saved');
  field('keep').value = ' 7D ';
  field('copy-keeps').value = 'all';
  const change = await save();
  assert.deepEqual({...change.data}, {cmd: 'schedulekeepchange', id: '0a1b2c3d', keep: ' 7D ', copy_keeps: 'all'}, 'Save sends both as typed');
  change.done({error: 'Nothing was saved: under Copy Keeps, <b>x</b>'});
  await settle();
  assert.deepEqual([refusal(), field('keep')?.value, $('#zfsm-schedules input[type=button]').length, text('zfsm-schedules-status')], ['Nothing was saved: under Copy Keeps, <b>x</b>', ' 7D ', 2, ''],
    'a refusal shows in the dialog as text, with what was typed, and the list stays');
  (await save()).done({succeeded: {}, failed: {request: 'Unknown or missing command'}});
  await settle();
  assert.equal(refusal(), 'Unknown or missing command', 'as does a backend failure');
  (await save()).done({schedules_html: 'saved'});
  await settle();
  assert.deepEqual([shown(), text('zfsm-schedules'), text('zfsm-schedules-status')], [false, 'saved', ''], 'one saved closes it and shows the list it answers');
  await open(0);
  (await save()).done({error: 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (x)', schedules_html: 'tank/a to backup/a'});
  await settle();
  assert.deepEqual([shown(), text('zfsm-schedules'), text('zfsm-schedules-status')], [false, 'tank/a to backup/a', 'The schedules were saved, but Unraid\'s crontab could not be rebuilt (x)'],
    'one saved whose crontab was not rebuilt closes it, shows the list it saved and says so under it');
  await open(1);
  assert.deepEqual([popup().querySelector('.swal2-html-container p').textContent, field('keep').value, field('copy-keeps')], ['Copied to data on server nas, when the array starts.', '3', null],
    'one on a server has no Copy Keeps');
  const remote = await save();
  assert.deepEqual({...remote.data}, {cmd: 'schedulekeepchange', id: '0b0b0b0b', keep: '3', copy_keeps: ''}, 'and sends it empty');
  remote.done({schedules_html: 'saved'});
  await settle();
  await open(0);
  const sent = posts.length;
  window.Swal2.clickCancel();
  await settle();
  assert.deepEqual([shown(), posts.length, $('#zfsm-schedules input[type=button]').length], [false, sent, 2], 'Cancel sends nothing and keeps the list');
  console.log('settings send tests passed on jQuery ' + window.$.fn.jquery);
})().catch(error => { console.error(error); process.exit(1); });
