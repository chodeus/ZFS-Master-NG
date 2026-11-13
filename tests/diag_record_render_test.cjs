// Real browser: the Main tab's diagnostics record (zfsmDiagRecord, ZFSMaster.page's render callback) holds the table's header cells,
// read by the page's own statement on Unraid's jQuery. Usage: diag_record_render_test.cjs <webgui-tree>. Set CHROME to override.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(dir => fs.existsSync(dir));
assert.ok(dynamix, 'webGUI not found under ' + tree);
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch (error) { return false; }
});
assert.ok(chrome, 'Chrome not found: set CHROME');

const page = fs.readFileSync(path.join(plugin, 'ZFSMaster.page'), 'utf8');
function cut(from, to) {
  const start = page.indexOf(from);
  const end = page.indexOf(to, start);
  assert.ok(start >= 0 && end > start, from + ' is in ZFSMaster.page');
  return page.slice(start, end + to.length);
}
// The table as it ships, with another plugin's column added to its header row, as one that extends the table would.
const table = cut('<table id="zfs_master"', '</table>').replace(/(<thead>\s*<tr[^>]*>)/, '$1<td>Other Plugin</td>');
const record = cut("zfsmDiagRecord('render', function() {", '});');

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-diag-record-'));
try {
  fs.copyFileSync(path.join(dynamix, 'javascript/dynamix.js'), path.join(work, 'dynamix.js'));
  const html = path.join(work, 'page.html');
  fs.writeFileSync(html, `<!doctype html><html><head><meta charset="utf-8"><script src="dynamix.js"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMFrontEnd.js')}"></script></head><body>${table}
<script>
window.addEventListener('load', () => {
  localStorage.removeItem('zfsm-diagnostics');
  const began = Date.now();
  const data = {pools: {tank: {}}};
  ${record}
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  out.textContent = JSON.stringify(JSON.parse(localStorage.getItem('zfsm-diagnostics') || 'null'));
  document.documentElement.appendChild(out);
});
</script></body></html>`);
  const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
    '--virtual-time-budget=3000', '--dump-dom', `file://${html}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
  const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
  assert.ok(match, 'the page did not run');
  const saved = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
  assert.ok(saved && saved.render, 'no render was recorded');
  assert.deepEqual(saved.render.headers, ['Other Plugin', 'Pool', 'Health', 'Option/Dataset Name', 'Mount Point', 'Refer', 'Snapshots', 'Size', 'Used', 'Free'],
    'the record holds every header cell, another plugin\'s too');
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log('diagnostics record render test passed');
