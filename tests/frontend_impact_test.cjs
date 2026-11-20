const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMSize.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMImpact.js'), 'utf8'), context);
const html = (preview, heading) => context.zfsmImpactHtml(preview, heading);

assert.doesNotMatch(html({objects: ['tank/<img src=x onerror=alert(1)>'], clones: [], held: []}, 'h'), /<img/, 'names are escaped');
assert.doesNotMatch(html({error: '<script>'}, 'h'), /<script>/, 'errors are escaped');
assert.doesNotMatch(html({objects: [], clones: ['<b>'], held: ['<i>']}, '<u>'), /<b>|<i>|<u>/, 'every list and the heading are escaped');
assert.match(html({error: 'nope', objects: ['tank/data']}, 'h'), /nope/);
assert.doesNotMatch(html({error: 'nope', objects: ['tank/data']}, 'h'), /tank\/data/, 'an error shows no list');
assert.match(html({newer: ['tank/a@b'], clones: []}, 'h'), /tank\/a@b/, 'rollback lists newer items');
assert.match(html({objects: ['a'], clones: ['c'], held: []}, 'h'), /clones depend/);
assert.match(html({objects: ['a'], clones: [], held: ['b']}, 'h'), /have a hold/);

assert.match(html({objects: ['flash/boot@a'], clones: [], held: [], boot_pool: '<b>flash'}, 'h'), /^<p[^>]*>This is on &lt;b&gt;flash, the pool Unraid boots from\./, 'the boot pool leads the review, escaped');
assert.doesNotMatch(html({objects: ['tank/a'], clones: [], held: [], boot_pool: ''}, 'h'), /boots from/, 'another pool gets no boot warning');
assert.match(html({objects: ['a', 'b'], clones: [], held: [], automatic: 2}, 'h'), /2 of these were made by Sanoid or Syncoid\. Replication may need them\./);
assert.match(html({newer: ['a'], clones: [], automatic: 1}, 'h'), /1 of these was made by Sanoid or Syncoid/, 'rollback too, in the singular');
assert.doesNotMatch(html({objects: ['a'], clones: [], held: [], automatic: 0}, 'h'), /Sanoid/, 'none counted, no line');
assert.doesNotMatch(html({error: 'nope', boot_pool: 'flash', automatic: 3}, 'h'), /boots from|Sanoid/, 'an error shows only itself');

const many = html({objects: Array.from({length: 60}, (_, n) => `tank@${n}`), clones: [], held: []}, 'h');
assert.equal((many.match(/<li/g) || []).length, 51, 'a long list is capped');
assert.match(many, /and 10 more/);

// Review sizes follow the Size Units setting: ZFS's by default, Unraid's when picked.
assert.match(context.zfsmImpactSummary({objects: ['a'], reclaim: 2048}, 'destroys'), /destroys 1 item\(s\) and frees about 2 KiB:/);
context.zfsm_config = {size_units: 'decimal'};
context.zfsm_display = {number: '.,', scale: -1};
assert.match(context.zfsmImpactSummary({objects: ['a'], reclaim: 2048}, 'destroys'), /frees about 2\.05 KB:/, 'in Unraid\'s units when Settings picks them');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: 2048, raw: false}), /about 2\.05 KB\./, 'and so is a replication\'s');
delete context.zfsm_config;
assert.doesNotMatch(context.zfsmImpactSummary({objects: ['a'], reclaim: null}, 'destroys'), /frees/, 'an unknown size is left out, not shown as 0');
assert.match(context.zfsmImpactSummary({objects: ['a'], reclaim: 0}, 'destroys'), /frees about 0 B/, 'a real zero is shown');
assert.equal(context.zfsmImpactSummary({objects: [], reclaim: 0}, 'destroys'), '');

const review = (setup) => {
	let shown = null;
	context.$ = {post(url, data, done) {
		const request = {fail(handler) { if (setup.network) handler(); return request; }};
		if (!setup.network) done(setup.answer);
		return request;
	}};
	context.zfsmFetchReview('/admin', {}, preview => { shown = preview; }, ...('after' in setup ? [setup.after] : []));
	return shown;
};
assert.equal(review({answer: {objects: ['a'], can_proceed: true}}).can_proceed, true, 'an object answer');
assert.equal(review({answer: '{"objects":["a"],"can_proceed":true}'}).can_proceed, true, 'a JSON string answer');
assert.match(review({answer: '{"succeeded":[],"failed":{"request":"timed out"}}'}).error, /timed out/, 'a backend failure shows its reason');
assert.match(review({answer: 'not json'}).error, /could not be read/);
assert.match(review({answer: 'null'}).error, /could not be read/);
assert.match(review({network: true}).error, /The request failed/);
assert.match(html(review({network: true}), 'h'), /Nothing was changed/, 'and the dialog shows it');
assert.equal(review({network: true, after: ''}).error, 'The request failed.', 'a read-only caller drops "Nothing was changed"');
assert.equal(review({answer: '{"failed":{"request":"zfs timed out"}}', after: ''}).error, 'zfs timed out');
assert.equal(review({answer: '{"failed":{"request":"zfs timed out"}}'}).error, 'zfs timed out. Nothing was changed.', 'a reason ends before what follows it');

const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/ZFSMaster.page'), 'utf8');
const popup = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMAdminDatasetSnaps.php'), 'utf8');
for (const file of [page, popup]) assert.match(file, /frontend\/ZFSMImpact\.js/, 'both pages load the review');
assert.ok(popup.includes('$("#delete-snaps").click(deleteSnapshots);'), 'Delete selected runs deleteSnapshots');
const bulk = popup.slice(popup.indexOf('function deleteSnapshots('), popup.indexOf('$("#delete-snaps").click(deleteSnapshots);'));
const confirmed = bulk.indexOf('if (!result.isConfirmed) return;');
assert.ok(bulk.indexOf('zfsmFetchReview(') >= 0 && confirmed > bulk.indexOf('zfsmFetchReview('), 'bulk delete fetches the review, then waits for the confirm');
assert.ok([...bulk.matchAll(/destroysnapshot/g)].every(found => found.index > confirmed), 'bulk delete destroys nothing before the confirm');
assert.match(bulk, /cmd: 'destroysnapshot', 'snapshot': target,[^\n]*'token': preview\.token/, 'bulk delete sends the reviewed list with its token, not one request per snapshot');
const diff = popup.slice(popup.indexOf('function diffSnapshot('), popup.indexOf('function renameSnapshot('));
assert.match(diff, /zfsmFetchReview\('[^']*', \{cmd: 'diffsnapshot'[\s\S]*\n\t\}, ''\);\n/, 'the changes dialog is read-only, so its failures do not say "Nothing was changed"');

// The Replicate dialog (include/ZFSMReplicaPlan.php's preview): names and plans are escaped; no destination yet shows no plan.
const replicate = context.zfsmReplicateHtml({snapshots: ['new<b>', 'old'], last_destination: {server: '', dest: 'backup/"data'}, servers: []}, 'tank/data');
assert.doesNotMatch(replicate, /new<b>/, 'a snapshot name cannot inject markup');
assert.match(replicate, /<option value="new&lt;b&gt;">[\s\S]*<option value="old">[\s\S]*<option value="">Take one now<\/option>/, 'newest snapshot first, Take one now last');
assert.match(replicate, /id="zfsm-replicate-dest"[^>]*placeholder="pool\/dataset"[^>]*value="backup\/&quot;data"/, 'the last destination is filled in');
assert.doesNotMatch(replicate, /zfsm-replicate-server/, 'with no trusted servers there is no Server select');
// Trusted servers: the last copy's server is picked, and its name inside that server's area filled in.
const remote = context.zfsmReplicateHtml({snapshots: [], servers: ['nas', 'x<y'], last_destination: {server: 'nas', dest: 'backup/replicas/tower/da"ta'}}, 'tank/data');
assert.match(remote, /<select id="zfsm-replicate-server"[^>]*><option value="">This server<\/option><option value="nas" selected>nas<\/option><option value="x&lt;y">x&lt;y<\/option><\/select>/,
  'This server, then each trusted server, escaped; the last copy\'s is picked');
assert.match(remote, /id="zfsm-replicate-dest"[^>]*placeholder="name"[^>]*value="da&quot;ta"/, 'its name inside the area is filled in');
const gone = context.zfsmReplicateHtml({snapshots: [], servers: ['nas'], last_destination: {server: 'old', dest: 'backup/replicas/tower/data'}}, 'tank/data');
assert.match(gone, /<option value="">This server<\/option><option value="nas">nas<\/option>/, 'a last copy on a server no longer trusted picks This server');
assert.match(gone, /id="zfsm-replicate-dest"[^>]*placeholder="pool\/dataset"[^>]*value=""/, 'with nothing filled in');
assert.match(context.zfsmReplicateHtml({snapshots: [], servers: [], servers_error: 'remotes.json <bad>'}, 'tank/data'), /color:#e22828[^>]*>remotes\.json &lt;bad&gt;</, 'servers that can\'t be read say so, escaped');
assert.match(context.zfsmReplicateHtml({snapshots: [], servers: ['nas'], servers_error: 'unread'}, 'tank/data'), /<select id="zfsm-replicate-server" aria-describedby="zfsm-replicate-server-error"[\s\S]*<p id="zfsm-replicate-server-error"[^>]*>unread<\/p>/,
  'Replicate: the Server select names the line saying why its list is short');
assert.doesNotMatch(remote, /aria-describedby="zfsm-replicate-server-error"/, 'and names none when the servers were read');
assert.deepEqual([null, {server: '', dest: 'backup/data'}, {server: 'nas', dest: 'a/b/c'}, {server: 'nas', dest: 'a/b/c'}].map((last, at) => context.zfsmReplicateName(last, ['nas', 'nas', '', 'nas'][at], 'tank/media/photos')),
  ['photos', 'photos', '', 'c'], 'each server\'s name: the dataset\'s last part on a server it has no copy on, the last copy\'s where it has one');
assert.equal(context.zfsmReplicatePlanHtml({can_proceed: false, error: '', server: 'n<s', area: 'a/b'}), '<p style="margin:.5em 0">On n&lt;s, copies go inside a/b.</p>', 'a trusted server picked: its area shows before a name is given');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: 1, raw: false, server: 'nas', area: 'a/b'}), /^<p[^>]*>On nas, copies go inside a\/b\.<\/p><p[^>]*>The first replication/, 'and above the plan');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'incremental', since: 'v', size: 1, raw: false, type: 'volume', server: 'nas', area: 'a/b'}), /has no \/dev\/zvol device, so/, 'a volume\'s copy there never had a device to remove');
assert.equal(context.zfsmReplicatePlanHtml({can_proceed: false, error: ''}), '', 'no destination yet: no plan');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: false, error: 'The copy goes <inside>'}), /color:#e22828[\s\S]*The copy goes &lt;inside&gt;/, 'a refusal shows, escaped');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: 2048, raw: false}), /The first replication sends everything, about 2 KiB\./);
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: null, holder: true}), /It replaces the empty dataset there, made to hold the copies below it, which stay\./, 'a copy onto a holder says it replaces it');
assert.doesNotMatch(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: null, holder: false}), /replaces the empty dataset/, 'only onto a holder');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'incremental', since: 'a<b', size: null, raw: true}), /changes since a&lt;b\.<\/p>[\s\S]*Sent raw/, 'a size not yet known is left out; an encrypted dataset goes raw');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'resume', size: 0, raw: false}), /Carries on from where the last run to this copy stopped, about 0 B\./);
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: 1, raw: false, type: 'filesystem'}), /The copy is unmounted and read-only/, 'a filesystem\'s copy is unmounted');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'full', size: 1, raw: false, type: 'volume'}), /The copy is read-only and has no \/dev\/zvol device, so/, 'a volume\'s first copy has no device');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'incremental', since: 'v', size: 1, raw: false, type: 'volume'}), /has no \/dev\/zvol device \(one it has now is removed\), so/, 'an incremental says a device the copy has now goes');
assert.match(context.zfsmReplicatePlanHtml({can_proceed: true, mode: 'resume', size: 1, raw: false, type: 'volume'}), /has no \/dev\/zvol device \(one it has now is removed\), so/, 'so does a resumed run');

// The Schedules and Replicate forms: a hint in each row it explains, linked to that section of the wiki's guide, in place of help paragraphs.
const schedules = context.zfsmScheduleDialogHtml({every: 'default', time: '', days: [], keep: '', inherited: 'x', skipped: '',
  copies: {every: 'default', time: '', days: [], keep: '', under: '', server: '', servers: ['a"<b>'], servers_error: '<i>unread</i>', inherited: 'y', skipped: ''}});
const words = html => html.replace(/<select[\s\S]*?<\/select>/g, '').replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
assert.ok(words(schedules) < 250 && words(replicate) < 150, `the forms read in a glance, hidden lines too: ${words(schedules)} and ${words(replicate)} words`);
// A copy of the wiki headings these link to, as GitHub names them: change the wiki's Schedules page and this together.
const sections = ['replication', 'snapshots-to-keep', 'copy-keeps', 'datasets-left-out', 'replicate-repeats', 'copies-on-another-server'];
const guides = (schedules + replicate).match(/<a [^>]*>Guide<\/a>/g);
for (const guide of guides) {
  const [, section] = guide.match(/^<a href="https:\/\/github\.com\/chodeus\/ZFS-Master-NG\/wiki\/Schedules#([a-z-]+)" target="_blank" rel="noopener noreferrer">Guide<\/a>$/) || [];
  assert.ok(sections.includes(section), `${guide}: opens a section of the guide in a new tab, which can't reach back to this page`);
}
assert.equal(guides.length, 9, 'Snapshots to keep three times, Copy Keeps twice, Copy Under for this server and another, and the footer; Replicate\'s repeat line');
const hinted = (html, prefix, row, label, text, section) => assert.match(html, new RegExp('<p id="' + prefix + '-' + row + '-row"[^>]*><label[^>]*>' + label + ':<input [^>]*aria-describedby="' + prefix + '-' + row
  + '-hint"></label><span id="' + prefix + '-' + row + '-hint"[^>]*>' + text.replace(/[.()]/g, '\\$&') + ' <a [^>]*#' + section + '"[^>]*>Guide</a></span></p>'), `${prefix}: ${label}'s hint shows and hides with its row, and its box names it`);
for (const [html, prefix] of [[schedules, 'zfsm-schedules'], [schedules, 'zfsm-copies'], [replicate, 'zfsm-replicate']]) hinted(html, prefix, 'keep', 'Snapshots to keep', 'A number keeps the newest that many; 7d 4w 12m keep the newest of each of the last 7 days, 4 weeks and 12 months that have one. Empty keeps all.', 'snapshots-to-keep');
for (const [html, prefix] of [[schedules, 'zfsm-copies'], [replicate, 'zfsm-replicate']]) hinted(html, prefix, 'copy-keeps', 'Copy Keeps', 'Empty: the copy loses what Snapshots to keep removes here. Type all to keep every snapshot in it, or rules such as 30d 12m, which count every snapshot in it.', 'copy-keeps');
assert.match(schedules, new RegExp('<p id="zfsm-copies-under-row"[^>]*><label[^>]*>Copy Under:<input [^>]*aria-describedby="zfsm-copies-under-hint"></label><span id="zfsm-copies-under-hint">'
  + '<span id="zfsm-copies-under-here"[^>]*>A dataset that already exists on this server, in another pool: tank/data is copied to backup/tank/data\\. <a [^>]*#replication"[^>]*>Guide</a></span>'
  + '<span id="zfsm-copies-under-there"[^>]*>Inside the dataset that server keeps for this server\'s copies; a run makes it if it is missing\\. <a [^>]*#copies-on-another-server"[^>]*>Guide</a></span></span></p><div id="zfsm-copies-days-row"'),
  'Copy Under: a hint for this server and one for another, in its row above Days, and its box names the one shown');
assert.match(schedules, /<select id="zfsm-copies-every"[^>]*>(?:<option[^>]*>[^<]*<\/option>)*<\/select><\/label><\/p><p id="zfsm-copies-server-row" style="[^"]*display:none"><label[^>]*>Server:<select id="zfsm-copies-server" aria-describedby="zfsm-copies-server-error"[^>]*><option value="">This server<\/option><option value="a&quot;&lt;b&gt;">a&quot;&lt;b&gt;<\/option><\/select><\/label><span id="zfsm-copies-server-error"[^>]*>&lt;i&gt;unread&lt;\/i&gt;<\/span><\/p><p id="zfsm-copies-under-row"/,
  'Server, below Replication\'s choice and above Copy Under: This server and each server, escaped, and why they can\'t be read, as text it names');
assert.doesNotMatch(context.zfsmScheduleDialogHtml({every: 'default', time: '', days: [], keep: '', inherited: 'x', skipped: '',
  copies: {every: 'default', time: '', days: [], keep: '', under: '', server: '', servers: ['nas'], inherited: 'y', skipped: ''}}), /zfsm-copies-server-error/,
  'servers read: the Server select names no error line');
assert.match(schedules, /^<div style="text-align:left"><div role="group" aria-label="Snapshots"[^>]*><p[^>]*><label[^>]*><b>Snapshots:<\/b><select id="zfsm-schedules-every"[\s\S]*<\/div><div role="group" aria-label="Replication"[^>]*><p[^>]*><label[^>]*><b>Replication:<\/b><select id="zfsm-copies-every"/,
  'Snapshots and Replication each have their own labelled part');
assert.match(replicate, /<p id="zfsm-replicate-repeat-help" style="[^"]*display:none">A repeat runs now, then at its time, and takes a new snapshot each run; Settings lists it\. On another server, that server's Keep Replicas decides what the copy keeps\. <a [^>]*#replicate-repeats"[^>]*>Guide<\/a><\/p><\/div>$/,
  'Replicate says what a repeat does in one line, hidden until one is picked');

console.log('destroy and rollback dialog tests passed');
