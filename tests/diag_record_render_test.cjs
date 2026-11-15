// Real browser: the Main tab's diagnostics record (zfsmDiagRecord, ZFSMaster.page's render callback) holds the table's header cells and
// each shown row icon kind's offset, read by the page's own statement on Unraid's jQuery. Usage: diag_record_render_test.cjs <webgui-tree>.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {inlineSheet} = require('./unraid_sheets.cjs');
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
const record = cut("zfsmDiagRecord('render', function(last) {", '});');
// The page's own folding, which zfsmRender runs after each redraw.
const folding = cut('function processHiddenDatasets() {', '\n  }\n') + cut('function zfsmFoldIcon(', '\n  }\n');
// Unraid's sheets hide a row's details text (a.info span) as on the Main tab, then the page's own; one more rule moves the clone badge.
const styles = path.join(dynamix, 'styles');
const sheets = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css', 'default-black.css', 'themes/black.css']
  .filter(name => fs.existsSync(path.join(styles, name))).map(name => inlineSheet(styles, name)).join('\n');
const pageCss = page.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-diag-record-'));
try {
  fs.copyFileSync(path.join(dynamix, 'javascript/dynamix.js'), path.join(work, 'dynamix.js'));
  const html = path.join(work, 'page.html');
  fs.writeFileSync(html, `<!doctype html><html><head><meta charset="utf-8"><script src="dynamix.js"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMSize.js')}"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMFrontEnd.js')}"></script>
<script src="file://${path.join(plugin, 'frontend/ZFSMDiagRecord.js')}"></script>
<style>${sheets}\n${pageCss}\n#zfs_master .fa-clone{position:relative;top:10px}</style></head><body><div id="tab3">${table}</div>
<script>
${folding}
window.addEventListener('load', () => {
  localStorage.removeItem('zfsm-diagnostics');
  const began = Date.now();
  const data = {pools: {tank: {}}};
  // A dataset with a child and an origin, folded as the owner left it, its child's key not loaded; each row with the other plugin's cell.
  const now = Math.floor(Date.now() / 1000);
  const ds = (name, extra) => Object.assign({name, type: 'filesystem', creation: now, used: 1024, available: 3072, referenced: 512, mountpoint: '/mnt/' + name, keystatus: 'none', child: {}, snapshots: [], directories: []}, extra);
  document.getElementById('zfs_master_body').innerHTML = generateDatasetArrayRows('tank', ds('tank', {child: {'tank/data': ds('tank/data', {keystatus: 'unavailable'})}, origin: 'tank/base@s'}), '', true, 0, 30, {text: 1})
    .replace(/<tr[^>]*>/g, '$&<td></td>');
  saveToLocalStorage('zfsm-datasetsHidden', {hidden: ['tank']});
  processHiddenDatasets();
  // The rows ask for FontAwesome; a face that failed or is missing measures a fallback glyph.
  document.fonts.load('16px FontAwesome').then(faces => faces.length, () => 0).then(fontAwesome => {
    ${record}
    const shown = loadFromLocalStorage('zfsm-diagnostics');
    // Unraid's tabbed view hides the section while another tab is open, and the refresh redraws it there.
    document.getElementById('tab3').style.display = 'none';
    ${record}
    const out = document.createElement('pre'); out.id = 'zfsm-result';
    out.textContent = JSON.stringify({fontAwesome, shown, hidden: loadFromLocalStorage('zfsm-diagnostics')});
    document.documentElement.appendChild(out);
  });
});
</script></body></html>`);
  const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
    '--window-size=1280,800', '--virtual-time-budget=3000', '--dump-dom', `file://${html}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
  const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
  assert.ok(match, 'the page did not run');
  const result = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
  assert.ok(result.fontAwesome, 'FontAwesome did not load, so the icons could not be measured');
  const saved = result.shown;
  assert.ok(saved && saved.render, 'no render was recorded');
  assert.deepEqual(saved.render.headers, ['Other Plugin', 'Pool', 'Health', 'Option/Dataset Name', 'Mount Point', 'Refer', 'Snapshots', 'Size', 'Used', 'Free'],
    'the record holds every header cell, another plugin\'s too');
  const icons = saved.render.icons;
  assert.deepEqual(Object.keys(icons || {}).sort(), ['fa-clone', 'fa-hdd-o', 'fa-plus-square'], 'each icon kind shown is in the record, none from a folded row: ' + JSON.stringify(icons));
  assert.ok(Math.abs(icons['fa-plus-square'] - icons['fa-hdd-o']) <= 1, 'icons nothing moves read alike: ' + JSON.stringify(icons));
  assert.ok(Math.abs(icons['fa-clone'] - icons['fa-hdd-o'] - 10) <= 0.5, 'a sheet that moves one kind 10 px down shows as 10 px: ' + JSON.stringify(icons));
  assert.deepEqual(result.hidden && result.hidden.render.icons, icons, 'a redraw while the table is on a tab not shown keeps what the last shown one measured');
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log('the diagnostics record holds every header cell and how far each shown row icon kind sits from its name, kept while the tab is not shown');
