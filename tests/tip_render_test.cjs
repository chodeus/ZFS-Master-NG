// Real browser: a row's details fit inside a phone's window, in either orientation, and inside a desktop's.
// Usage: tip_render_test.cjs <webgui-tree>. Set CHROME to override the browser path. Runs the page's own zfsmShowTip on Unraid's jQuery and sheets.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(fs.existsSync);
assert.ok(dynamix, 'webGUI not found under ' + tree);
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');
const page = fs.readFileSync(path.join(plugin, 'ZFSMaster.page'), 'utf8');
const from = page.indexOf('  function zfsmTipPlace(');
const to = page.indexOf('  // Pointer and keyboard both');
assert.ok(from > 0 && to > from, 'the details functions found in ZFSMaster.page');
const sheets = ['default-fonts.css', 'default-base.css', 'default-black.css', 'themes/black.css'].map(name => path.join(dynamix, 'styles', name)).filter(fs.existsSync);

// Pools of 40 and 12 devices, the longest details the Main tab shows, and a dataset's few properties.
const pool = count => Array.from({length: count}, (_, i) => `sd${String.fromCharCode(97 + i % 26)} WDC_WD80EFZX-68UW8N0_VK1A${1000 + i} ONLINE`).join('<br>');
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-tip-'));
let checked = 0;
const sizes = [];
try {
  for (const [width, height] of [[500, 700], [844, 390], [1280, 800]]) {
    for (const [name, details] of [['40 devices', pool(40)], ['12 devices', pool(12)], ['properties', 'Creation Date: 1/1/2026<br>Compression: lz4<br>Record Size: 128K']]) {
      const file = path.join(work, `${name.replace(/ /g, '-')}-${width}.html`);
      fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8">
${sheets.map(sheet => `<link rel="stylesheet" href="file://${sheet}">`).join('\n')}
<script src="file://${dynamix}/javascript/dynamix.js"></script></head><body>
<table id="zfs_master"><tr><td><a class="info hand" tabindex="0" style="position:absolute;left:30px;top:${Math.round(height / 3)}px"><i>i</i><span>${details}</span></a></td></tr></table>
<script>${page.slice(from, to)}
window.addEventListener('load', () => {
  zfsmShowTip(document.querySelector('#zfs_master a.info'));
  const shown = document.getElementById('zfsm-tip-text');
  const tip = shown.getBoundingClientRect();
  // Every piece of every line, as drawn (after any scale), and the text size it is drawn at.
  const lines = [], walker = document.createTreeWalker(shown, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) { const range = document.createRange(); range.selectNodeContents(walker.currentNode); for (const r of range.getClientRects()) lines.push([r.left, r.top, r.right, r.bottom]); }
  const font = parseFloat(getComputedStyle(shown).fontSize) * tip.height / shown.offsetHeight;
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  out.textContent = JSON.stringify({view: [innerWidth, innerHeight], tip: [tip.left, tip.top, tip.right, tip.bottom], inner: [shown.scrollHeight, shown.clientHeight], lines, font});
  document.documentElement.appendChild(out);
});
</script></body></html>`);
      const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
        `--window-size=${width},${height}`, '--virtual-time-budget=3000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
      const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
      assert.ok(match, `${name} at ${width}x${height}: the details did not show`);
      const {view: [w, h], tip: [left, top, right, bottom], inner: [scroll, client], lines, font} = JSON.parse(match[1].replace(/&quot;/g, '"'));
      const where = `${name} at ${width}x${height} (${w}x${h})`;
      assert.ok(left >= 0 && top >= 0 && right <= w && bottom <= h, `${where}: the details must fit the window: ${match[1]}`);
      assert.ok(scroll <= client + 1, `${where}: no line may be cut off inside the details`);
      assert.ok(lines.length >= details.split('<br>').length, `${where}: every line must be drawn`);
      const outside = lines.find(([l, t, r, b]) => l < left - 1 || t < top - 1 || r > right + 1 || b > bottom + 1 || l < 0 || t < 0 || r > w || b > h);
      assert.equal(outside, undefined, `${where}: every line must sit inside the details and the window`);
      // Only details too long for the window are scaled down; the floor catches a scale gone wrong.
      assert.ok(font >= 7, `${where}: the text is drawn at ${font}px`);
      sizes.push(`${where}: ${font.toFixed(1)}px`);
      checked++;
    }
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`row details fit the window in ${checked} layouts; text drawn at ${sizes.join(', ')}`);
