// Real browser: the Main tab's diagnostics record (zfsmDiagRecord, ZFSMaster.page's render callback) holds the table's header cells and
// each shown row icon kind's offset, read by the page's own statement on Unraid's jQuery. Usage: diag_record_render_test.cjs <webgui-tree>.
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
// Unraid's sheets hide a row's details text (a.info span) as on the Main tab, then the page's own; one more rule moves the clone badge.
const sheets = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css', 'default-black.css', 'themes/black.css']
  .map(name => path.join(dynamix, 'styles', name)).filter(file => fs.existsSync(file));
const pageCss = page.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-diag-record-'));
try {
  fs.copyFileSync(path.join(dynamix, 'javascript/dynamix.js'), path.join(work, 'dynamix.js'));
  const html = path.join(work, 'page.html');
  fs.writeFileSync(html, `<!doctype html><html><head><meta charset="utf-8"><script src="dynamix.js"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMSize.js')}"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMFrontEnd.js')}"></script>
${sheets.map(sheet => `<link rel="stylesheet" href="file://${sheet}">`).join('\n')}
<style>${pageCss}\n#zfs_master .fa-clone{position:relative;top:10px}</style></head><body>${table}
<script>
window.addEventListener('load', () => {
  localStorage.removeItem('zfsm-diagnostics');
  const began = Date.now();
  const data = {pools: {tank: {}}};
  // Rows as the page draws them, each with the other plugin's cell: a dataset with a child and an origin, and its child folded away
  // with a key that is not loaded.
  const now = Math.floor(Date.now() / 1000);
  const ds = (name, extra) => Object.assign({name, type: 'filesystem', creation: now, used: 1024, available: 3072, referenced: 512, mountpoint: '/mnt/' + name, keystatus: 'none', child: {}, snapshots: []}, extra);
  document.getElementById('zfs_master_body').innerHTML = (generateDatasetRow('tank', ds('tank', {child: {'tank/data': ds('tank/data')}, origin: 'tank/base@s'}), 'zdataset-tank', true, 0, 30, {text: 1})
    + generateDatasetRow('tank', ds('tank/data', {keystatus: 'unavailable'}), 'zdataset-tank', false, 0, 30, {text: 1})).replace(/<tr[^>]*>/g, '$&<td></td>');
  ${record}
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  out.textContent = JSON.stringify(JSON.parse(localStorage.getItem('zfsm-diagnostics') || 'null'));
  document.documentElement.appendChild(out);
});
</script></body></html>`);
  const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
    '--window-size=1280,800', '--virtual-time-budget=3000', '--dump-dom', `file://${html}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
  const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
  assert.ok(match, 'the page did not run');
  const saved = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
  assert.ok(saved && saved.render, 'no render was recorded');
  assert.deepEqual(saved.render.headers, ['Other Plugin', 'Pool', 'Health', 'Option/Dataset Name', 'Mount Point', 'Refer', 'Snapshots', 'Size', 'Used', 'Free'],
    'the record holds every header cell, another plugin\'s too');
  const icons = saved.render.icons;
  assert.deepEqual(Object.keys(icons || {}).sort(), ['fa-clone', 'fa-hdd-o', 'fa-minus-square'], 'each icon kind on screen is in the record, none from a folded row: ' + JSON.stringify(icons));
  assert.ok(Math.abs(icons['fa-minus-square'] - icons['fa-hdd-o']) <= 1, 'icons nothing moves read alike: ' + JSON.stringify(icons));
  assert.ok(Math.abs(icons['fa-clone'] - icons['fa-hdd-o'] - 10) <= 0.5, 'a sheet that moves one kind 10 px down shows as 10 px: ' + JSON.stringify(icons));
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log('the diagnostics record holds every header cell and how far each row icon kind sits from its name');
