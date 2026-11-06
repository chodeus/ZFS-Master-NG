const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = vm.createContext({});
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

assert.equal(context.zfsmImpactSize(512), '512 B');
assert.equal(context.zfsmImpactSize(2048), '2.00 KiB');
assert.equal(context.zfsmImpactSize(0), '0 B');
assert.equal(context.zfsmImpactSize(1024 ** 5 * 3), '3.00 PiB');

assert.match(context.zfsmImpactSummary({objects: ['a'], reclaim: 2048}, 'destroys'), /destroys 1 item\(s\) and frees about 2\.00 KiB:/);
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

const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/ZFSMaster.page'), 'utf8');
const popup = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMAdminDatasetSnaps.php'), 'utf8');
for (const file of [page, popup]) assert.match(file, /frontend\/ZFSMImpact\.js/, 'both pages load the review');
const bulk = popup.slice(popup.indexOf('#delete-snaps").click'), popup.indexOf('#checkAll'));
const confirmed = bulk.indexOf('if (!result.isConfirmed) return;');
assert.ok(bulk.indexOf('zfsmFetchReview(') >= 0 && confirmed > bulk.indexOf('zfsmFetchReview('), 'bulk delete fetches the review, then waits for the confirm');
assert.ok([...bulk.matchAll(/destroysnapshot/g)].every(found => found.index > confirmed), 'bulk delete destroys nothing before the confirm');
assert.match(bulk, /cmd: 'destroysnapshot', 'snapshot': target,[^\n]*'token': preview\.token/, 'bulk delete sends the reviewed list with its token, not one request per snapshot');
const diff = popup.slice(popup.indexOf('function diffSnapshot('), popup.indexOf('function renameSnapshot('));
assert.match(diff, /zfsmFetchReview\('[^']*', \{cmd: 'diffsnapshot'[\s\S]*\n\t\}, ''\);\n/, 'the changes dialog is read-only, so its failures do not say "Nothing was changed"');

console.log('destroy and rollback dialog tests passed');
