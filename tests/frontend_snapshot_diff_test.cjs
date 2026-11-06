// The changes dialog: every state says what it found, and file names never become markup.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMSnapshotDiff.js'), 'utf8'), context);
const html = diff => context.zfsmDiffHtml(diff);

const named = html({changes: [{change: '+', type: 'F', path: '/mnt/tank/<img src=x onerror=alert(1)>'}, {change: 'R', type: '/', path: '/mnt/tank/a', to: '/mnt/tank/<b>'}], truncated: false});
assert.doesNotMatch(named, /<img|<b>/, 'paths and new names are escaped');
assert.match(named, /<td>Added<\/td><td>File<\/td>/);
assert.match(named, /<td>Renamed<\/td><td>Folder<\/td><td[^>]*>\/mnt\/tank\/a → \/mnt\/tank\/&lt;b&gt;<\/td>/, 'a rename shows old and new names');
assert.match(named, /2 changes since this snapshot\./);

assert.match(html({changes: [{change: '-', type: '@', path: '/a'}], truncated: false}), /1 change since this snapshot\.[\s\S]*<td>Removed<\/td><td>Link<\/td>/, 'one change, in the singular');
assert.match(html({changes: [{change: 'M', type: '?', path: '/a'}], truncated: false}), /<td>Modified<\/td><td>Other<\/td>/, 'an unknown file type still shows');
assert.match(html({changes: [], truncated: false}), /Nothing has changed since this snapshot\./);
assert.match(html({changes: Array.from({length: 3}, (_, n) => ({change: 'M', type: 'F', path: '/f'+n})), truncated: true}), /Showing the first 3 changes\./, 'a cut-short list says so');
assert.match(html({error: '<script>Cannot diff an unmounted snapshot'}), /^<p[^>]*>&lt;script&gt;Cannot diff/, 'errors are escaped');
assert.doesNotMatch(html({error: 'nope', changes: [{change: '+', type: 'F', path: '/a'}]}), /<table/, 'an error shows no list');

console.log('snapshot diff dialog test passed');
