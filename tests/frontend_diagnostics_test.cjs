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
  const posts = [], timers = [];
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
  const context = vm.createContext({$, localStorage, window, console: {warn() {}, error() {}}, setTimeout: callback => { timers.push(callback); return timers.length; }, clearTimeout() {}});
  vm.runInContext(source, context);
  const parts = {button: element(), box: element(), status: element(), link: element()};
  parts.box.prop('checked', true);
  const diagnostics = context.createZFSMDiagnostics({url: '/admin', download: '/download?file=', ...parts, report: () => context.zfsmDiagReport(window)});
  return {diagnostics, posts, timers, storage, context, window, ...parts};
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
page.posts[2].done(null);
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
page.context.zfsmDiagRecord('render', () => { throw new Error('no table'); });
const record = JSON.parse(page.storage.get('zfsm-diagnostics'));
assert.deepEqual([record.status, record.errors.length, record.errors[0].text, record.errors[19].text, 'render' in record], [{text: 'second'}, 20, 'error 6', 'error 25', false], 'the record keeps the last status and the newest twenty errors');
assert.match(JSON.parse(page.context.zfsmDiagReport(page.window)).kept['zfsm-diagnostics'], /error 25/, 'and the report carries it');

console.log('diagnostics button and record tests passed');
