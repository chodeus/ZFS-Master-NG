// The Settings page's Diagnostics button through the real ZFSMDiagnostics.js, and the Main tab's record through the real
// zfsmDiagRecord (ZFSMFrontEnd.js); only $.post (HTTP), timers and localStorage are faked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '../zfs.master'));
const source = ['ZFSMFrontEnd.js', 'ZFSMDiagnostics.js'].map(file => fs.readFileSync(path.join(root, 'frontend', file), 'utf8')).join('\n');

function element() {
  const state = {text: '', attrs: {}, props: {}, shown: false};
  return {state,
    text(value) { if (value === undefined) return state.text; state.text = value; return this; },
    attr(name, value) { state.attrs[name] = value; return this; },
    prop(name, value) { if (value === undefined) return state.props[name]; state.props[name] = value; return this; },
    show() { state.shown = true; return this; }, hide() { state.shown = false; return this; }};
}

function launch(stored = {}) {
  const posts = [], timers = [], cleared = [];
  const storage = new Map(Object.entries(stored));
  const localStorage = {get length() { return storage.size; }, key: index => [...storage.keys()][index], getItem: key => storage.has(key) ? storage.get(key) : null,
    setItem: (key, value) => storage.set(key, String(value)), removeItem: key => storage.delete(key)};
  const $ = {post(url, data, done) {
    const post = {url, data, done, fail(callback) { this.failed = callback; return this; }};
    posts.push(post);
    return post;
  }};
  const window = {localStorage, navigator: {userAgent: 'TestBrowser/1.0'}, innerWidth: 1280, innerHeight: 800, devicePixelRatio: 1.25,
    document: {documentElement: {className: 'Theme--black'}, body: {className: 'page'}}};
  const context = vm.createContext({$, localStorage, window, console: {warn() {}, error() {}}, setTimeout: callback => { timers.push(callback); return timers.length; }, clearTimeout: id => cleared.push(id)});
  vm.runInContext(source, context);
  const parts = {button: element(), box: element(), status: element(), link: element()};
  parts.box.prop('checked', true);
  const diagnostics = context.createZFSMDiagnostics({url: '/admin', download: '/download?file=', ...parts, report: () => context.zfsmDiagReport(window)});
  return {diagnostics, posts, timers, cleared, storage, context, window, ...parts};
}

// A start: one request with the box's state and the browser's report; the button is off until the run ends.
let page = launch({'zfsm-cache': 'x'.repeat(5000), 'zfsm-datasetsHidden': '["tank/data"]', 'other-plugin': 'not ours'});
page.diagnostics.start();
assert.equal(page.posts.length, 1, 'a click sends one request');
assert.deepEqual([page.posts[0].url, page.posts[0].data.cmd, page.posts[0].data.unraid], ['/admin', 'diagnosticsstart', '1'], 'the start, with the box on');
const report = JSON.parse(page.posts[0].data.report);
assert.deepEqual([report.userAgent, report.window, report.pixelRatio, report.theme], ['TestBrowser/1.0', [1280, 800], 1.25, 'Theme--black page'], 'the report says what the browser is and shows');
assert.deepEqual(report.kept, {'zfsm-cache': '5000 characters', 'zfsm-datasetsHidden': '["tank/data"]'}, 'and what the plugin keeps in it: the cache by its size, nothing of another plugin');
assert.deepEqual([page.button.state.props.disabled, page.status.state.text, page.link.state.shown], [true, 'Collecting. This can take a few minutes.', false], 'the button is off while the run goes');
page.diagnostics.start();
assert.equal(page.posts.length, 1, 'a second click while it runs sends nothing');

// The run is followed: asked again every two seconds while it runs, then the link to its zip.
page.posts[0].done({state: 'started', why: ''});
assert.deepEqual([page.posts.length, page.posts[1].data.cmd], [2, 'diagnosticsstatus'], 'once started, the state is asked for');
page.posts[1].done({state: 'running'});
assert.equal(page.timers.length, 1, 'a run still going is asked about again');
page.timers[0]();
page.posts[2].done({state: 'done', zip: 'zfs.master-diagnostics-20260102-100000.zip'});
assert.deepEqual([page.button.state.props.disabled, page.link.state.shown, page.link.state.attrs.href, page.link.state.text, page.timers.length],
  [false, true, '/download?file=zfs.master-diagnostics-20260102-100000.zip', 'zfs.master-diagnostics-20260102-100000.zip', 1], 'a finished run shows its zip as a link, turns the button on and asks no more');

// The box off; a start that fails, a server that does not answer, and an answer with nothing in it.
page = launch();
page.box.prop('checked', false);
page.diagnostics.start();
assert.equal(page.posts[0].data.unraid, '0', 'the box off is sent as off');
page.posts[0].done({state: 'failed', why: 'no room'});
assert.deepEqual([page.status.state.text, page.button.state.props.disabled, page.posts.length], ['Diagnostics failed: no room', false, 1], 'a start that fails says why and asks no more');
page.diagnostics.start();
page.posts[1].failed({status: 200});
assert.deepEqual([page.status.state.text, page.button.state.props.disabled], ['Diagnostics failed: the server did not answer (HTTP 200). Reload the page and try again.', false], 'a request with no answer is a failure, never a run');
page.diagnostics.poll();
assert.doesNotThrow(() => page.posts[2].done(null), 'an empty answer is no error');
assert.deepEqual([page.status.state.text, page.link.state.shown, page.timers.length], ['', false, 0], 'an empty answer shows nothing');
page.diagnostics.poll();
page.posts[3].done({state: 'running'});
page.diagnostics.start();
assert.equal(page.posts.length, 4, 'a run found going when the page loads can\'t be started again');

// The Main tab's record: a status and a render replace the last one, errors add up to twenty, and a part that can't be made is skipped.
page = launch();
page.context.zfsmDiagRecord('status', () => ({text: 'first'}));
page.context.zfsmDiagRecord('status', () => ({text: 'second'}));
for (let index = 1; index <= 25; index++) page.context.zfsmDiagRecord('errors', () => ({text: 'error ' + index}));
assert.doesNotThrow(() => page.context.zfsmDiagRecord('render', () => { throw new Error('no table'); }), 'a part that can\'t be made breaks nothing');
const record = JSON.parse(page.storage.get('zfsm-diagnostics'));
assert.deepEqual([record.status, record.errors.length, record.errors[0].text, record.errors[19].text, 'render' in record], [{text: 'second'}, 20, 'error 6', 'error 25', false], 'the record keeps the last status and the newest twenty errors');
assert.match(JSON.stringify(JSON.parse(page.context.zfsmDiagReport(page.window)).kept['zfsm-diagnostics']), /error 25/, 'and the report carries it');

// Errors whose text quotes a JSON answer, as a failed request's does: each string is cut before the record is kept, so escaping it
// again can't grow it past its bound, and the report keeps the record whole and stays within what the server keeps.
page = launch();
for (let index = 1; index <= 20; index++) page.context.zfsmDiagRecord('errors', () => ({at: 't' + index, text: 'error ' + index + ' ' + JSON.stringify({answer: '"\\'.repeat(600)}), where: 'ZFSMFrontEnd.js:' + index}));
const quoted = JSON.parse(page.storage.get('zfsm-diagnostics'));
const quotedReport = page.context.zfsmDiagReport(page.window);
assert.deepEqual([quoted.errors[quoted.errors.length - 1].at, JSON.stringify(quoted.errors).length <= 8000, quoted.errors.every(entry => entry.text.length <= 200), JSON.parse(quotedReport).leftOut, Buffer.byteLength(quotedReport) < 65536, JSON.stringify(JSON.parse(quotedReport).kept['zfsm-diagnostics']) === page.storage.get('zfsm-diagnostics')],
  ['t20', true, true, 0, true, true], 'each string is cut to 200 characters and the errors to their share, newest kept; the report holds the whole record within 64 KB: ' + Buffer.byteLength(quotedReport) + ' bytes');
// A value that does not fit is counted and takes no room: what comes after it is still kept.
page = launch();
for (let index = 0; index < 24; index++) page.storage.set('zdataset-tank/d' + index, 'y'.repeat(2000));
page.storage.set('zfsm-big', 'z'.repeat(5000));
page.storage.set('zfsm-small', 'kept');
const roomy = JSON.parse(page.context.zfsmDiagReport(page.window));
assert.deepEqual([roomy.leftOut, 'zfsm-big' in roomy.kept, roomy.kept['zfsm-small']], [1, false, 'kept'], 'a value left out takes no room from the values after it');
// The Main tab's record is kept first, whatever was stored before it, and as the object it is.
page = launch();
for (let index = 0; index < 30; index++) page.storage.set('zdataset-tank/f' + index, 'y'.repeat(2000));
for (let index = 1; index <= 20; index++) page.context.zfsmDiagRecord('errors', () => ({at: 't' + index, text: '"'.repeat(300), where: '"'.repeat(300)}));
page.context.zfsmDiagRecord('render', () => ({at: 'r', headers: Array(50).fill('"'.repeat(300))}));
const first = page.context.zfsmDiagReport(page.window);
const firstKept = JSON.parse(first).kept['zfsm-diagnostics'];
assert.deepEqual([typeof firstKept, firstKept && firstKept.errors.length > 0, firstKept && firstKept.render.headers.length > 0, JSON.stringify(firstKept).length < 13000, JSON.parse(first).leftOut > 0, Buffer.byteLength(first) < 65536],
  ['object', true, true, true, true, true], 'the record is kept whole, first, even at its largest, and the report stays within 64 KB: ' + Buffer.byteLength(first) + ' bytes');

// The budget is in bytes as sent: two-byte characters fill it twice as fast as their count says.
page = launch();
for (let index = 0; index < 24; index++) page.storage.set('zdataset-tank/e' + index, '\u00e9'.repeat(2000));
const wide = page.context.zfsmDiagReport(page.window);
assert.deepEqual([Buffer.byteLength(wide) < 65536, JSON.parse(wide).leftOut > 0], [true, true], 'a report of two-byte text stays within 64 KB as sent: ' + Buffer.byteLength(wide) + ' bytes');

// At its largest, in text that takes the most room as sent (three-byte characters and escaped control characters), the record
// still fits: the report keeps it whole and stays within 64 KB.
page = launch();
for (let index = 0; index < 20; index++) page.context.zfsmDiagRecord('errors', () => ({at: '\u4e2d'.repeat(200), text: '\u0001'.repeat(200), where: '\u4e2d'.repeat(200)}));
page.context.zfsmDiagRecord('render', () => ({at: 'r', headers: Array(40).fill('\u4e2d'.repeat(200))}));
page.context.zfsmDiagRecord('status', () => ({at: 's', text: '\u0001'.repeat(200)}));
const widest = page.context.zfsmDiagReport(page.window);
const widestKept = JSON.parse(widest).kept['zfsm-diagnostics'];
assert.deepEqual([JSON.parse(widest).leftOut, Boolean(widestKept && widestKept.status), Buffer.byteLength(widest) < 65536, page.storage.get('zfsm-diagnostics').length <= 13000],
  [0, true, true, true], 'the widest record fits the report whole: ' + Buffer.byteLength(widest) + ' bytes');

// The page loads with no run, and its read of the state fails: that is not a failed run.
page = launch();
page.diagnostics.poll();
page.posts[0].failed({status: 500});
assert.deepEqual([page.status.state.text, page.button.state.props.disabled, page.timers.length], ['The state of diagnostics could not be read: the server answered HTTP 500.', undefined, 0], 'an idle page whose read fails says only that');

// The page is left while a run goes: its timer is cleared, and an answer still on its way is not shown.
page = launch();
page.diagnostics.start();
page.posts[0].done({state: 'started', why: ''});
page.posts[1].done({state: 'running'});
page.diagnostics.poll();
page.diagnostics.stop();
page.posts[2].done({state: 'done', zip: 'zfs.master-diagnostics-20260102-100000.zip'});
assert.deepEqual([page.cleared, page.link.state.shown, page.timers.length], [[1], false, 1], 'leaving the page clears the pending poll\'s timer and drops the answer on its way');
// Back from the browser's cache, the page asks again (ZFSMasterSettings.page on pageshow): that answer is shown.
page.diagnostics.poll();
page.posts[3].done({state: 'done', zip: 'zfs.master-diagnostics-20260102-100000.zip'});
assert.deepEqual([page.link.state.shown, page.button.state.props.disabled], [true, false], 'a poll after the page came back shows its answer');

// The record as the Main tab writes it: twenty long script errors, then a render. Each part is bounded where it is written, so the
// report carries the newest error and the render whole, and its JSON stays whole.
page = launch();
for (let index = 1; index <= 20; index++) page.context.zfsmDiagRecord('errors', () => ({at: 't' + index, text: 'Uncaught TypeError number ' + index + ' ' + 'x'.repeat(3000), where: 'ZFSMFrontEnd.js:' + index}));
page.context.zfsmDiagRecord('render', () => ({at: 'r', milliseconds: 41, pools: 2, rows: 37, headers: ['Name', 'Size', 'Other Plugin Column']}));
const bounded = JSON.parse(page.storage.get('zfsm-diagnostics'));
assert.deepEqual([bounded.errors.length, bounded.errors.every(entry => entry.text.length === 200), bounded.errors[19].at, /^Uncaught TypeError number 20 x/.test(bounded.errors[19].text)],
  [20, true, 't20', true], 'an error too long for the record keeps its time and the start of its text');
let sent = JSON.stringify(JSON.parse(page.context.zfsmDiagReport(page.window)).kept['zfsm-diagnostics']);
assert.ok(sent.includes('number 20') && sent.includes('Other Plugin Column'), 'the report carries the newest error and the render');
// Many dataset keys: what does not fit is counted, never cut through.
for (let index = 0; index < 60; index++) page.storage.set('zdataset-tank/d' + index, 'y'.repeat(1500));
const full = JSON.parse(page.context.zfsmDiagReport(page.window));
const shown = Object.keys(full.kept).filter(key => key.startsWith('zdataset-')).length;
assert.deepEqual([shown + full.leftOut, full.leftOut > 0, 'zfsm-diagnostics' in full.kept], [60, true, true], 'values that do not fit are left out and counted');

// Answers that come back late or not at all: only the newest request's answer shows, and a lost poll while a run goes is asked again.
page = launch();
page.diagnostics.poll();
page.diagnostics.start();
page.posts[0].done({state: 'none'});
assert.deepEqual([page.status.state.text, page.button.state.props.disabled], ['Collecting. This can take a few minutes.', true], 'the answer to the poll sent before the start is stale');
page.posts[1].done({state: 'started', why: ''});
page.posts[2].done({state: 'running'});
page.timers[0]();
page.posts[3].failed({status: 0});
assert.deepEqual([page.status.state.text, page.button.state.props.disabled, page.timers.length], ['Collecting. The server did not answer; asking again.', true, 2], 'one lost poll while the run goes is asked again, not a failure');
for (let lost = 0; lost < 3; lost++) {
  page.timers[page.timers.length - 1]();
  page.posts[page.posts.length - 1].failed({status: 0});
}
assert.deepEqual([page.status.state.text, page.button.state.props.disabled], ['Diagnostics failed: the server did not answer. Reload the page and try again.', false], 'four lost polls in a row are a failure');
page.diagnostics.poll();
page.posts[page.posts.length - 1].done({state: 'done', zip: 'zfs.master-diagnostics-20260102-100000.zip', why: 'older zips could not be removed from /boot/logs: zfs.master-diagnostics-20250101-000000.zip'});
assert.equal(page.status.state.text, 'Ready, and kept in /boot/logs (older zips could not be removed from /boot/logs: zfs.master-diagnostics-20250101-000000.zip): ', 'a finished run says what else it has to say');

console.log('diagnostics button and record tests passed');
