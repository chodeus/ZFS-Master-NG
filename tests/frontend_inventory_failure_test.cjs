// Inventory failure paths through the real ZFSMInventory.js, ZFSMaster.page script and ZFSMFrontEnd.js;
// only $.ajax (HTTP), timers and localStorage are faked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '../zfs.master'));
const page = fs.readFileSync(path.join(root, 'ZFSMaster.page'), 'utf8');
const frontend = fs.readFileSync(path.join(root, 'frontend/ZFSMFrontEnd.js'), 'utf8');
const inventory = fs.readFileSync(path.join(root, 'frontend/ZFSMInventory.js'), 'utf8');
const keys = fs.readFileSync(path.join(root, 'frontend/ZFSMKeys.js'), 'utf8');
const mainScript = [...page.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]).find(script => script.includes('var zfsm_csrf_token'));

function launch({interval = 30} = {}) {
  const ready = [], requests = [], timers = new Map(), texts = new Map(), events = {};
  let timerId = 0;
  const elements = new Map();
  const body = {innerHTML: ''};
  function $(selector) {
    if (typeof selector === 'function') { ready.push(selector); return; }
    if (!elements.has(selector)) {
      const element = {
        text(value) { if (value === undefined) return texts.get(selector); texts.set(selector, value); return this; },
        css() { return this; }, removeClass() { return this; }, addClass() { return this; },
        click() { return this; }, on() { return this; }, remove() { return this; }, removeAttr() { return this; }, hide() { return this; }, attr() { return this; }, ajaxSuccess() { return this; }
      };
      elements.set(selector, element);
    }
    return elements.get(selector);
  }
  $.ajax = options => {
    const request = {options, done(callback) { this.resolve = callback; return this; },
      fail(callback) { this.reject = callback; return this; }, abort() { this.aborted = true; }};
    requests.push(request);
    return request;
  };
  const storage = new Map();
  const context = vm.createContext({$, console: {error() {}, warn() {}}, csrf_token: 'fixture-token',
    document: {hidden: false, getElementById: id => id === 'zfs_master_body' ? body : null, getElementsByClassName: () => []},
    window: {addEventListener: (event, callback) => { events[event] = callback; }},
    localStorage: {getItem: k => storage.get(k) ?? null, setItem: (k, v) => storage.set(k, v), removeItem: k => storage.delete(k)},
    setTimeout(callback, ms) { timers.set(++timerId, {callback, ms}); return timerId; },
    clearTimeout(id) { timers.delete(id); }
  });
  vm.runInContext(frontend, context);
  vm.runInContext(inventory, context);
  vm.runInContext(keys, context);
  const script = mainScript.replace(/<\?(?:php|=)[\s\S]*?\?>/g, php => {
    if (php.includes('zfsmJson($zfsm_cfg')) return JSON.stringify({version: '7.3.2', refresh_interval: interval,
      destructive_mode: 0, snap_max_days_alert: 30, directory_listing: []});
    if (php.includes('zfsmJson($display')) return JSON.stringify({text: 0, warning: 80, critical: 90});
    if (php.includes('$urlzmadmin')) return '/plugins/zfs.master/backend/ZFSMAdmin.php';
    return '';
  });
  vm.runInContext(script, context, {filename: 'rendered-ZFSMaster.page.js'});
  ready.forEach(callback => callback());
  return {context, requests, timers, texts, body, events, storage,
    status: () => texts.get('#zfsm-last-refresh') || '',
    delays: () => [...timers.values()].map(t => t.ms),
    expire: () => { const pending = [...timers.values()]; timers.clear(); pending.forEach(t => t.callback()); }};
}

const lazy = () => ({op: 'getDatasets', data: {
  pools: {tank: {Pool: 'tank', Snapshots: null}, cache: {Pool: 'cache', Snapshots: null}},
  datasets: {tank: {name: 'tank'}, cache: {name: 'cache'}}, devices: {tank: '', cache: ''}, errors: {}
}});
const poolResult = (pool, count) => ({op: 'getAll', data: {
  pools: {[pool]: {Pool: pool, Snapshots: count}}, datasets: {[pool]: {name: pool, snapshots: []}},
  devices: {[pool]: ''}, errors: {}
}});

function check(name, fn) {
  try { fn(); } catch (error) { error.message = `${name}: ${error.message}`; throw error; }
}

// Leaving the page (pagehide → stop) must stop the lazy snapshot chain,
// whether the in-flight pool request succeeds or fails. Expected: no request after stop.
check('stop: failed per-pool read after pagehide does not start the next pool', () => {
  const s = launch();
  s.context.updateFullBodyTable = () => {};
  s.requests[0].resolve(lazy());
  assert.equal(s.requests.length, 2);
  s.events.pagehide();
  assert.equal(s.requests[1].aborted, true, 'leaving the page leaves the pool read running');
  s.requests[1].reject({status: 0}, 'abort');
  assert.equal(s.requests.length, 2, 'stopped controller issued a request for the next pool');
});
check('stop: the first inventory read is aborted on pagehide', () => {
  const s = launch();
  s.events.pagehide();
  assert.equal(s.requests[0].aborted, true, 'leaving the page leaves the inventory read running');
});
check('stop: succeeded per-pool read after pagehide (control)', () => {
  const s = launch();
  s.context.updateFullBodyTable = () => {};
  s.requests[0].resolve(lazy());
  s.events.pagehide();
  s.requests[1].resolve(poolResult('tank', 1));
  assert.equal(s.requests.length, 2);
});

// A renderer that keeps failing while merging a pool must end the refresh: no next pool, a retry, the error shown.
check('render exception during lazy merge does not wedge the controller', () => {
  const s = launch();
  let calls = 0;
  s.context.updateFullBodyTable = () => { if (++calls > 1) throw new Error('render broke'); };
  s.requests[0].resolve(lazy());
  try { s.requests[1].resolve(poolResult('tank', 1)); } catch (_) { /* jQuery would rethrow too */ }
  assert.equal(s.requests.length, 2, 'a renderer that keeps failing still started the next pool');
  assert.equal(s.timers.size, 1, 'no retry timer: refresh is stuck busy');
  assert.match(s.status(), /render broke/, 'the render error is not shown: ' + s.status());
});

// A pool that answers with a query error must not slow refreshes for every pool.
check('a persistent query error keeps the chosen interval', () => {
  const s = launch({interval: 30});
  s.context.updateFullBodyTable = () => {};
  const withError = () => ({op: 'getAll', data: {pools: {tank: {Pool: 'tank', Snapshots: 0}}, devices: {tank: ''},
    datasets: {tank: {name: 'tank', snapshots: [], _snapshot_error: 'zfs list: permission'}}, errors: {tank: 'zfs list: permission'}}});
  for (let i = 0; i < 6; i++) { s.requests[i].resolve(withError()); if (i < 5) s.expire(); }
  assert.deepEqual(s.delays(), [30000], 'delay after successful reads: ' + s.delays());
});

// Snapshots read pool by pool keep the server's date text through the merge and the page's render.
check('lazy-loaded snapshots show the server date text', () => {
  const s = launch();
  const ds = name => ({name, child: {}, type: 'filesystem', mountpoint: '/mnt/' + name, used: 1, available: 1, referenced: 1});
  const answer = lazy();
  answer.data.datasets = {tank: ds('tank'), cache: ds('cache')};
  s.requests[0].resolve(answer);
  assert.equal(s.requests.length, 2, 'real renderer rejected fixture: ' + s.status());
  const part = poolResult('tank', 1);
  part.data.datasets.tank = {...ds('tank'), creation: 1793700000, creation_text: '03-11-2026, 18:40',
    snapshots: [{name: 'tank@a', creation: 1793700000, creation_text: '03-11-2026, 18:41'}]};
  s.requests[1].resolve(part);
  const row = (s.body.innerHTML.match(/<tr id="tr-tank"[\s\S]*?<\/tr>/) || [''])[0];
  assert.match(row, /Creation Date: 03-11-2026, 18:40/, 'dataset date: ' + row.slice(0, 300));
  assert.match(row, /Last Snap Date: 03-11-2026, 18:41/, 'last snapshot date: ' + row.slice(0, 600));
});

// A pool whose snapshots failed must say so, not imply its datasets have none.
check('failed pool datasets do not claim zero snapshots', () => {
  const s = launch();
  const ds = name => ({name, child: {}, type: 'filesystem', mountpoint: '/mnt/' + name, used: 1, available: 1, referenced: 1});
  const answer = lazy();
  answer.data.datasets = {tank: {...ds('tank'), child: {'tank/data': ds('tank/data')}}, cache: ds('cache')};
  s.requests[0].resolve(answer);
  assert.equal(s.requests.length, 2, 'real renderer rejected fixture: ' + s.status());
  s.requests[1].reject({status: 504}, 'error');
  const part = poolResult('cache', 3); part.data.datasets.cache = {...ds('cache'), snapshots: []};
  s.requests[2].resolve(part);
  assert.match(s.body.innerHTML, /Unavailable/, 'pool row');
  for (const id of ['tr-tank', 'tr-tank%2Fdata']) {
    const row = (s.body.innerHTML.match(new RegExp('<tr id="' + id + '"[\\s\\S]*?</tr>')) || [''])[0];
    assert.match(row, /zfsm-snap-count">—</, id + ' must not claim a snapshot count it could not read: ' + row.replace(/\s+/g, ' ').slice(-200));
  }
  const cacheRow = (s.body.innerHTML.match(/<tr id="tr-cache"[\s\S]*?<\/tr>/) || [''])[0];
  assert.match(cacheRow, /zfsm-snap-count">0</, 'a pool that answered keeps its real count');
});

// A pool that answers with a snapshot error must not have its zero counts cached as complete.
check('snapshot error in an answer is not cached as complete', () => {
  const s = launch();
  const ds = name => ({name, child: {}, type: 'filesystem', mountpoint: '/mnt/' + name, used: 1, available: 1, referenced: 1});
  const answer = lazy();
  answer.data.datasets = {tank: {...ds('tank'), child: {'tank/data': ds('tank/data')}}, cache: ds('cache')};
  s.requests[0].resolve(answer);
  const broken = poolResult('tank', 0); broken.data.datasets.tank = {...ds('tank'), snapshots: [], _snapshot_error: 'zfs list -t snapshot failed', child: {'tank/data': {...ds('tank/data'), snapshots: []}}};
  s.requests[1].resolve(broken);
  const part = poolResult('cache', 3); part.data.datasets.cache = {...ds('cache'), snapshots: []};
  s.requests[2].resolve(part);
  assert.equal(s.storage.has('zfsm-cache'), false, 'zero counts from a failed snapshot read were cached as complete');
  const row = (s.body.innerHTML.match(/<tr id="tr-tank%2Fdata"[\s\S]*?<\/tr>/) || [''])[0];
  assert.match(row, /zfsm-snap-count">—</, 'the failed pool shows a dash, not 0: ' + row.replace(/\s+/g, ' ').slice(-160));
  assert.deepEqual(s.delays(), [30000], 'an answered error does not slow the next refresh: ' + s.delays());
});

// With lazy loading off, the first answer already holds snapshots; an error there must not be cached either.
check('snapshot error in a full answer is not cached as complete', () => {
  const s = launch();
  const ds = name => ({name, child: {}, type: 'filesystem', mountpoint: '/mnt/' + name, used: 1, available: 1, referenced: 1, snapshots: []});
  const full = poolResult('tank', 0);
  full.data.datasets.tank = {...ds('tank'), _snapshot_error: 'zfs list -t snapshot failed', child: {'tank/data': ds('tank/data')}};
  s.requests[0].resolve(full);
  assert.equal(s.requests.length, 1, 'a full answer needs no per-pool reads');
  assert.equal(s.storage.has('zfsm-cache'), false, 'zero counts from a failed snapshot read were cached as complete');
  const row = (s.body.innerHTML.match(/<tr id="tr-tank%2Fdata"[\s\S]*?<\/tr>/) || [''])[0];
  assert.match(row, /zfsm-snap-count">—</, 'the failed pool shows a dash, not 0: ' + row.replace(/\s+/g, ' ').slice(-160));
});

// A pool whose dataset read failed answers with an empty tree; that must not be cached as complete either.
check('dataset error in a full answer is not cached as complete', () => {
  const s = launch();
  const full = poolResult('tank', 0);
  full.data.datasets.tank = {name: 'tank', child: {}, snapshots: [], total_snapshots: 0, _error: 'zfs list failed'};
  s.requests[0].resolve(full);
  assert.equal(s.storage.has('zfsm-cache'), false, 'an empty tree from a failed dataset read was cached as complete');
  assert.match(s.body.innerHTML, /ZFS query failed/, 'the failure is shown');
});

// A pool whose first dataset read failed but whose own read then answered cleanly makes the result complete again.
check('a pool that recovers in its own read is cached as complete', () => {
  const s = launch();
  s.context.updateFullBodyTable = () => {};
  const answer = lazy();
  answer.data.datasets.tank = {name: 'tank', child: {}, _error: 'zfs list failed'};
  answer.data.errors = {tank: 'zfs list failed'};
  s.requests[0].resolve(answer);
  s.requests[1].resolve(poolResult('tank', 1));
  s.requests[2].resolve(poolResult('cache', 2));
  assert.equal(s.storage.has('zfsm-cache'), true, 'a recovered pool left the cache unsaved');
});

console.log('inventory failure path tests passed');
