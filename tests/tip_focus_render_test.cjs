// Real browser, real input: a label's hint shows on hover, on keyboard focus and on any focus on a touch screen, and goes when the pointer leaves
// a label a click focused (a click on a Settings label opens Unraid's help). Usage: tip_focus_render_test.cjs <webgui-tree>. Set CHROME to override the browser path.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {execFileSync, spawn} = require('node:child_process');
// A Node test whose await never settles exits 0: only the last line clears this.
process.exitCode = 1;
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(fs.existsSync);
assert.ok(dynamix, 'webGUI not found under ' + tree);
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');
const styles = path.join(dynamix, 'styles');
const base = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css'].map(name => path.join(styles, name)).filter(fs.existsSync);
const themes = ['black', 'white', 'azure', 'gray'].map(theme => [`themes/${theme}.css`, `default-${theme}.css`].map(name => path.join(styles, name)).find(fs.existsSync)).filter(Boolean);
assert.ok(themes.length > 0, 'no Unraid theme found under ' + styles);
const jquery = path.join(dynamix, 'javascript/dynamix.js');
assert.ok(fs.existsSync(jquery), 'no dynamix.js (Unraid\'s jQuery) under ' + dynamix);

// A Settings row as Unraid's Markdown leaves it, help block after it, marked by the page's own script (ZFSMasterSettings.page) on Unraid's jQuery;
// and the Create Dataset dialog's Mount row as the plugin ships it.
const settings = fs.readFileSync(path.join(plugin, 'ZFSMasterSettings.page'), 'utf8');
const from = settings.indexOf("$('details.zfsm-section dl').each(");
const to = settings.indexOf(`$('textarea[name="exclusion"]')`);
assert.ok(from > 0 && to > from, 'the script that marks the labels found in ZFSMasterSettings.page');
const sizeTip = settings.match(/'size_units' => _\('([^']+)'\)/);
assert.ok(sizeTip, 'ZFSMasterSettings.page has a hint for Size Units');
const mount = fs.readFileSync(path.join(plugin, 'frontend/ZFSMCreateDataset.php'), 'utf8').match(/<dl><dt class="zfsm-tip"[^>]*>Mount:<\/dt>.*?<\/dl>/);
assert.ok(mount, 'the Create Dataset dialog has no Mount row with a hint');
const body = `<form><details class="zfsm-section" open><summary>General Settings</summary>
<dl>
<dt>Size Units:</dt>
<dd><select name="size_units"><option>Unraid (GB)</option></select></dd>
</dl>
<blockquote class='inline_help'>
<p>Unraid's own help for this setting.</p>
</blockquote>
</details></form>
<form class="zfsm-form"><details class="zfsm-section" open><summary>Basic</summary><div>
${mount[0]}
</div></details></form>
<script>var zfsm_tips = ${JSON.stringify({size_units: sizeTip[1]})};
${settings.slice(from, to)}</script>`;

// Chrome's DevTools protocol over --remote-debugging-pipe: requests on fd 3, answers on fd 4, each JSON message ended by a NUL.
// A machine with no mouse, such as a CI runner, reports hover: none: --blink-settings gives the page one, and touch emulation still takes it away.
function launch(file, width, profile) {
  const child = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${profile}`,
    `--window-size=${width},600`, '--blink-settings=primaryHoverType=2,availableHoverTypes=2,primaryPointerType=4,availablePointerTypes=4',
    '--remote-debugging-pipe', pathToFileURL(file).href], {stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe']});
  const waiting = new Map();
  let id = 0;
  let buffer = '';
  const fail = why => { for (const wait of waiting.values()) wait.reject(new Error(why)); waiting.clear(); };
  const exited = new Promise(resolve => child.on('exit', code => { fail(`Chrome exited (${code})`); resolve(); }));
  child.on('error', error => fail(`Chrome did not start: ${error.message}`));
  child.stdio[3].on('error', error => fail(`Chrome's pipe closed: ${error.message}`));
  child.stdio[4].setEncoding('utf8');
  child.stdio[4].on('data', chunk => {
    buffer += chunk;
    for (let end; (end = buffer.indexOf('\0')) >= 0; buffer = buffer.slice(end + 1)) {
      const message = JSON.parse(buffer.slice(0, end));
      const wait = waiting.get(message.id);
      if (!wait) continue;
      waiting.delete(message.id);
      clearTimeout(wait.timer);
      if (message.error) wait.reject(new Error(`${wait.method}: ${message.error.message}`));
      else wait.resolve(message.result);
    }
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const mine = ++id;
    const timer = setTimeout(() => { waiting.delete(mine); reject(new Error(`${method}: no answer in 15 s`)); }, 15000);
    waiting.set(mine, {resolve, reject, method, timer});
    child.stdio[3].write(JSON.stringify({id: mine, method, params, sessionId}) + '\0');
  });
  return {child, send, exited};
}

async function check(theme, width, work) {
  const file = path.join(work, `tips-${width}.html`);
  fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
${[...base, theme].map(sheet => `<link rel="stylesheet" href="${pathToFileURL(sheet).href}">`).join('\n')}
<link rel="stylesheet" href="${pathToFileURL(path.join(plugin, 'styles/zfsm-forms.css')).href}">
<script src="${pathToFileURL(jquery).href}"></script></head><body>${body}</body></html>`);
  const {child, send, exited} = launch(file, width, path.join(work, `profile-${width}`));
  const where = `${path.basename(theme)} at ${width}`;
  try {
    let page;
    for (let tries = 0; !page && tries < 50; tries++) {
      page = (await send('Target.getTargets')).targetInfos.find(target => target.type === 'page' && target.url.startsWith('file:'));
      if (!page) await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(page, `${where}: the page never opened`);
    const {sessionId} = await send('Target.attachToTarget', {targetId: page.targetId, flatten: true});
    const run = async expression => {
      const answer = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true}, sessionId);
      assert.equal(answer.exceptionDetails, undefined, `${where}: ${expression} threw`);
      return answer.result.value;
    };
    // The target names the file before its document commits: wait for the document itself.
    let loaded = false;
    for (let tries = 0; !loaded && tries < 100; tries++) {
      loaded = await send('Runtime.evaluate', {expression: `location.protocol === 'file:' && document.readyState === 'complete'`, returnByValue: true}, sessionId).then(answer => answer.result.value === true, () => false);
      if (!loaded) await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(loaded, `${where}: the page never loaded`);
    await run('document.fonts.ready.then(() => true)');
    const labels = await run(`[...document.querySelectorAll('.zfsm-tip')].map(dt => { const box = dt.getBoundingClientRect(); return {name: dt.textContent, tip: dt.dataset.tip, x: box.left + box.width / 2, y: box.top + box.height / 2}; })`);
    assert.deepEqual(labels.map(label => label.name), ['Size Units:', 'Mount:'], `${where}: both labels rendered`);
    const shown = index => run(`getComputedStyle(document.querySelectorAll('.zfsm-tip')[${index}], '::after').content`);
    const focused = () => run(`document.activeElement.classList.contains('zfsm-tip') ? document.activeElement.textContent : document.activeElement.tagName`);
    const mouse = (type, x, y) => send('Input.dispatchMouseEvent', {type, x, y, button: type === 'mouseMoved' ? 'none' : 'left', clickCount: type === 'mouseMoved' ? 0 : 1}, sessionId);
    const tab = async () => {
      await send('Input.dispatchKeyEvent', {type: 'rawKeyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9}, sessionId);
      await send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9}, sessionId);
    };
    const corner = [width - 5, 595];
    const away = () => mouse('mouseMoved', ...corner);
    assert.equal(await run(`matchMedia('(hover: hover) and (pointer: fine)').matches`), true, `${where}: the page sees a mouse`);

    for (const [index, label] of labels.entries()) {
      await away();
      await mouse('mouseMoved', label.x, label.y);
      assert.equal(await shown(index), JSON.stringify(label.tip), `${where}: hovering ${label.name} shows its hint`);
      await mouse('mousePressed', label.x, label.y);
      await mouse('mouseReleased', label.x, label.y);
      assert.equal(await focused(), label.name, `${where}: the click focused ${label.name}`);
      await away();
      assert.equal(await shown(index), 'none', `${where}: the pointer left ${label.name} after a click, and its hint stayed up`);
    }
    // Tab from the clicked Size Units label reaches the Mount label: keyboard focus shows its hint with the pointer away.
    await mouse('mousePressed', labels[0].x, labels[0].y);
    await mouse('mouseReleased', labels[0].x, labels[0].y);
    await away();
    for (let presses = 0; presses < 6 && await focused() !== labels[1].name; presses++) await tab();
    assert.equal(await focused(), labels[1].name, `${where}: Tab reaches the Mount label`);
    assert.equal(await shown(1), JSON.stringify(labels[1].tip), `${where}: keyboard focus on Mount shows its hint`);
    await tab();
    assert.equal(await shown(1), 'none', `${where}: Tab away from Mount hides its hint`);
    // A touch screen (no hover), where a tap may focus the label without hovering it: clicks with the pointer moved off, so only focus shows the hint.
    await send('Emulation.setTouchEmulationEnabled', {enabled: true, maxTouchPoints: 1}, sessionId);
    assert.equal(await run(`matchMedia('(hover: none)').matches`), true, `${where}: the page sees a screen with no hover`);
    for (const [index, label] of labels.entries()) {
      await mouse('mousePressed', label.x, label.y);
      await mouse('mouseReleased', label.x, label.y);
      await away();
      assert.equal(await shown(index), JSON.stringify(label.tip), `${where}: on a touch screen, focus on ${label.name} shows its hint with no hover`);
      await mouse('mousePressed', ...corner);
      await mouse('mouseReleased', ...corner);
      assert.equal(await shown(index), 'none', `${where}: on a touch screen, focus leaving ${label.name} hides its hint`);
    }
    await send('Browser.close').catch(() => {});
  } finally {
    // Its profile folder is removed next: Chrome must be gone first.
    if (child.exitCode === null) child.kill();
    await Promise.race([exited, new Promise(resolve => setTimeout(resolve, 10000))]);
  }
}

(async () => {
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-tipfocus-'));
  let checked = 0;
  try {
    // 500 is inside Unraid's phone layout, where the hint drops under its label (zfsm-forms.css's media rule).
    for (const theme of themes) for (const width of [500, 1280]) {
      await check(theme, width, work);
      checked++;
    }
  } finally {
    fs.rmSync(work, {recursive: true, force: true, maxRetries: 5, retryDelay: 200});
  }
  console.log(`label hints show on hover, keyboard focus and any focus on a touch screen, and go once the pointer leaves a clicked label: ${checked} renders in ${themes.length} themes, phone and desktop`);
  process.exitCode = 0;
})().catch(error => { console.error(error); process.exitCode = 1; });
