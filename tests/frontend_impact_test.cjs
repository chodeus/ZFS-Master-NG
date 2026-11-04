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
	context.zfsmFetchReview('/admin', {}, preview => { shown = preview; });
	return shown;
};
assert.equal(review({answer: {objects: ['a'], can_proceed: true}}).can_proceed, true, 'an object answer');
assert.equal(review({answer: '{"objects":["a"],"can_proceed":true}'}).can_proceed, true, 'a JSON string answer');
assert.match(review({answer: '{"succeeded":[],"failed":{"request":"timed out"}}'}).error, /timed out/, 'a backend failure shows its reason');
assert.match(review({answer: 'not json'}).error, /could not be read/);
assert.match(review({answer: 'null'}).error, /could not be read/);
assert.match(review({network: true}).error, /could not be loaded/);
assert.match(html(review({network: true}), 'h'), /Nothing was changed/, 'and the dialog shows it');

const page = fs.readFileSync(path.resolve(__dirname, '../zfs.master/ZFSMaster.page'), 'utf8');
const popup = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMAdminDatasetSnaps.php'), 'utf8');
for (const file of [page, popup]) assert.match(file, /frontend\/ZFSMImpact\.js/, 'both pages load the review');
const bulk = popup.slice(popup.indexOf('#delete-snaps").click'), popup.indexOf('#checkAll'));
assert.doesNotMatch(bulk, /^\t\t\$\.post[^\n]*destroysnapshot/m, 'bulk delete asks first');
assert.match(bulk, /cmd: 'destroysnapshot', 'snapshot': target,[^\n]*'token': preview\.token/, 'bulk delete sends the reviewed list with its token, not one request per snapshot');

console.log('destroy and rollback dialog tests passed');
