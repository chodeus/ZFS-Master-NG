// The bundled library in a real DOM, used the way this plugin's dialogs use it.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const assets = path.resolve(__dirname, '../zfs.master/assets');
const library = fs.readFileSync(path.join(assets, 'sweetalert2.all.min.js'), 'utf8');

const refused = /anthem|\.mp3|xn--p1ai|navigator\.language|createElement\(['"]audio/i.exec(library);
assert.equal(refused, null, `bundled library contains refused code: ${refused}`);

function load() {
  const dom = new JSDOM('<!doctype html><body></body>', {runScripts: 'outside-only', pretendToBeVisual: true});
  const unraidSwal = () => 'unraid';
  dom.window.swal = unraidSwal;
  // Real browsers have this; jsdom doesn't.
  dom.window.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}});
  dom.window.eval(library);
  return {window: dom.window, unraidSwal};
}

async function dialog(window, options, act = () => {}) {
  const shown = window.Swal2.fire(options);
  await new Promise(resolve => window.setTimeout(resolve, 0));
  const inspected = act(window.document) ?? {};
  window.Swal2.clickConfirm();
  return {result: await shown, ...inspected};
}

(async () => {
  const {window, unraidSwal} = load();
  assert.equal(window.swal, unraidSwal, "the library must not replace Unraid's swal()");
  assert.equal(window.Swal, undefined, 'only Swal2 may be exported');
  for (const method of ['fire', 'showValidationMessage', 'resetValidationMessage', 'enableButtons', 'showLoading', 'clickConfirm']) {
    assert.equal(typeof window.Swal2[method], 'function', `Swal2.${method} is used by the plugin`);
  }

  // Destroy and rollback dialogs: a checkbox whose value decides whether more data is removed.
  let {result} = await dialog(window, {title: 'Rollback', icon: 'warning', html: 'Discards changes', input: 'checkbox', inputPlaceholder: 'Also destroy newer', showCancelButton: true},
    document => { document.querySelector('.swal2-checkbox input').checked = true; });
  assert.equal(result.isConfirmed, true);
  assert.equal(result.value, 1, 'a ticked checkbox must be 1');
  ({result} = await dialog(window, {input: 'checkbox', inputPlaceholder: 'Also destroy newer'}));
  assert.equal(result.value, 0, 'an unticked checkbox must be 0');

  // Snapshot rename and unlock: the built-in text input, with a validator.
  let seen;
  ({result} = await dialog(window, {input: 'text', inputValidator: value => (seen = value, undefined), inputAttributes: {autocapitalize: 'off'}},
    document => { document.querySelector('input.swal2-input').value = 'new name'; }));
  assert.equal(result.value, 'new name');
  assert.equal(seen, 'new name');

  // Dataset rename and property edit: the plugin's own controls, restyled straight after fire() and read in preConfirm.
  let opened = false;
  const custom = window.Swal2.fire({
    title: 'Rename',
    html: '<input id="zfsm-swal-input" class="swal2-input" value="tank/data">'
      + '<label for="zfsm-swal-checkbox" class="swal2-checkbox" style="display: flex;"><input type="checkbox" id="zfsm-swal-checkbox" checked><span class="swal2-label">Force</span></label>',
    didOpen: () => { opened = true; },
    preConfirm: () => [window.document.getElementById('zfsm-swal-input').value, window.document.getElementById('zfsm-swal-checkbox').checked ? 1 : 0]
  });
  const container = window.document.querySelector('.swal2-html-container');
  assert.ok(container, 'the page restyles this container immediately after fire()');
  container.setAttribute('style', 'display: grid;');
  assert.ok(window.document.querySelector('.swal2-html-container #zfsm-swal-input'), 'the plugin reads its controls by these ids');
  await new Promise(resolve => window.setTimeout(resolve, 0));
  window.Swal2.clickConfirm();
  assert.deepEqual([...(await custom).value], ['tank/data', 1]);
  assert.equal(opened, true, 'didOpen must run');

  // A failed preConfirm keeps the dialog open with a message, then resets and runs again.
  let attempts = 0;
  const shown = window.Swal2.fire({input: 'text', preConfirm: () => {
    attempts++;
    if (attempts === 1) { window.Swal2.showValidationMessage('Name required'); return false; }
    return 'ok';
  }});
  await new Promise(resolve => window.setTimeout(resolve, 0));
  window.Swal2.clickConfirm();
  await new Promise(resolve => window.setTimeout(resolve, 0));
  await new Promise(resolve => window.setTimeout(resolve, 0));
  assert.match(window.document.querySelector('.swal2-validation-message').textContent, /Name required/);
  window.Swal2.resetValidationMessage();
  window.Swal2.enableButtons();
  window.Swal2.clickConfirm();
  assert.equal((await shown).value, 'ok');

  console.log('bundled dialog library tests passed');
  window.close();
})().catch(error => { console.error(error); process.exit(1); });
