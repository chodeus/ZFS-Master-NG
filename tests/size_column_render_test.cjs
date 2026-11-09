// Real browser: ZFS Master's Size, Used and Free cells, bars and labels sit where Unraid's own pool table puts them, in every theme.
// Usage: size_column_render_test.cjs <webgui-tree>. Set CHROME to override the browser path. Our rows come from the real ZFSMFrontEnd.js.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(dir => fs.existsSync(dir));
assert.ok(dynamix, 'webGUI not found under ' + tree);
const styles = path.join(dynamix, 'styles');
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');

// Unraid's pool table: this version's own classes (BootDevice.page), and a row as nchan/device_list writes it.
const unraidClasses = fs.readFileSync(path.join(dynamix, 'BootDevice.page'), 'utf8').match(/<table class="([^"<]+)">/)[1];
// 7.0 gave the pool tables the unraid class, so it marks the trees where the page's $is_unraid7 is true.
const unraid7 = unraidClasses.split(' ').includes('unraid');
const bar = (percent, text) => `<td><div class='usage-disk'><span style='width:${percent}%' class='greenbar'></span><span>${text}</span></div></td>`;
const unraidTable = `<div class="TableContainer"><table class="${unraidClasses}"><thead><tr><td>Device</td><td>Identification</td><td>Temp</td><td>Reads</td><td>Writes</td><td>Errors</td><td>FS</td><td>Size</td><td>Used</td><td>Free</td></tr></thead>
<tbody><tr><td>Boot</td><td>Boot Partition</td><td>*</td><td>0.0 B/s</td><td>0.0 B/s</td><td>0</td><td>zfs</td><td>13 GB</td>${bar(23, '3.03 GB')}${bar(77, '9.97 GB')}</tr></tbody></table></div>`;

// Ours: the page's own table tag, header and CSS, with a pool row and a dataset row from the real renderer.
const page = fs.readFileSync(path.join(plugin, 'ZFSMaster.page'), 'utf8').replace(/<\?=\$is_unraid7 \? '([^']*)' : '([^']*)'\?>/g, (match, yes, no) => unraid7 ? yes : no);
assert.doesNotMatch(page.match(/<style type="text\/css">[\s\S]*?<\/style>/)[0], /<\?/, 'every PHP value in the page CSS is rendered here');
const ourHead = page.match(/<table id="zfs_master"[^>]*>\s*<thead>[\s\S]*?<\/thead>/)[0];
const pageCss = page.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];
const context = vm.createContext({console, localStorage: {getItem: () => null, setItem() {}, removeItem() {}}, document: {}, window: {}, Date, encodeURIComponent, decodeURIComponent});
for (const file of ['ZFSMSize.js', 'ZFSMFrontEnd.js']) vm.runInContext(fs.readFileSync(path.join(plugin, 'frontend', file), 'utf8'), context);
const now = Math.floor(Date.now() / 1000);
const root = {name: 'flash', type: 'filesystem', creation: now, used: 3027000000, available: 9970000000, referenced: 98000, mountpoint: 'none', keystatus: 'none', child: {}, snapshots: []};
const display = {text: 1, warning: 70, critical: 90};
const ourTable = units => {
  context.zfsm_config = {size_units: units};
  context.zfsm_display = {number: '.,', scale: -1};
  const rows = '<tr>'+context.generatePoolTableRows({Pool: 'flash', Health: 'ONLINE', Size: '12.5G', Used: '2.82G', Free: '9.68G', MountPoint: 'none', Refer: '', Snapshots: 1}, 'nvme0n1p3', true, display, root)+'</tr>'
    + context.generateDatasetRow('flash', {...root, name: 'flash/boot', mountpoint: '/boot'}, 'zdataset-flash', true, 0, 30, display);
  if (units === 'decimal') assert.match(rows, />13 GB<\/td>[\s\S]*>3\.03 GB<[\s\S]*>9\.97 GB</, 'in Unraid\'s units the pool row shows Unraid\'s own text');
  return `<div id="zfs_master_div" class="TableContainer">${ourHead}<tbody>${rows}</tbody></table></div>`;
};

// Inlined, a sheet's font URLs must become file paths: /webGui/styles ones point at the styles folder, relative ones sit beside their sheet.
const sheet = name => {
  const file = path.join(styles, name);
  return fs.readFileSync(file, 'utf8').replace(/url\((['"]?)([^'")]+)\1\)/g, (match, quote, ref) => {
    if (/^(data|https?|file):/.test(ref)) return match;
    const bare = ref.split(/[?#]/)[0];
    const target = bare.startsWith('/webGui/styles/') ? path.join(styles, bare.slice('/webGui/styles/'.length)) : path.resolve(path.dirname(file), bare);
    return `url('file://${target}')`;
  });
};
const base = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css'].filter(name => fs.existsSync(path.join(styles, name)));
const themes = ['black', 'white', 'azure', 'gray'].map(theme => [`themes/${theme}.css`, `default-${theme}.css`].find(name => fs.existsSync(path.join(styles, name)))).filter(Boolean);
assert.ok(themes.length > 0, 'no Unraid theme found under ' + styles);

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-sizes-'));
let checked = 0;
const skipped = new Set();
try {
  for (const theme of themes) for (const width of [1280, 1920]) for (const units of ['decimal', 'binary']) {
    const css = [...base, theme].map(sheet).join('\n');
    // 6.9-6.11 size the last column by its text (width:4%), so Unraid's own tables only line up when their text matches.
    if (units === 'binary' && /disk_status tr td:last-child\{width:4%/.test(css)) { skipped.add(units); continue; }
    const file = path.join(work, `table-${width}-${units}.html`);
    fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><style>${css}\n${pageCss}</style></head><body>
${unraidTable}${ourTable(units)}
<script>
document.fonts.ready.then(() => {
  const box = el => { const r = el.getBoundingClientRect(); return [r.left, r.right]; };
  const lastThree = row => [...row.cells].slice(-3).map(td => {
    const disk = td.querySelector('.usage-disk');
    return {cell: box(td), disk: disk && box(disk), fill: disk && box(disk.firstElementChild)[0], label: disk && box(disk.lastElementChild)[1]};
  });
  const tables = document.querySelectorAll('table');
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  out.textContent = JSON.stringify({unraid: lastThree(tables[0].tBodies[0].rows[0]), ours: [...tables[1].tBodies[0].rows].map(lastThree)});
  document.documentElement.appendChild(out);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      `--window-size=${width},800`, '--virtual-time-budget=5000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    assert.ok(match, `${theme} at ${width} in ${units}: the tables did not render`);
    const {unraid, ours} = JSON.parse(match[1].replace(/&quot;/g, '"'));
    const near = (a, b, what) => assert.ok(Math.abs(a - b) <= 0.5, `${theme} at ${width} in ${units}: ${what} at ${a.toFixed(2)}, Unraid's at ${b.toFixed(2)}`);
    ours.forEach((row, r) => row.forEach((cell, c) => {
      const name = `${r ? 'dataset' : 'pool'} ${['Size', 'Used', 'Free'][c]}`;
      near(cell.cell[0], unraid[c].cell[0], name+' cell left');
      near(cell.cell[1], unraid[c].cell[1], name+' cell right');
      if (!unraid[c].disk) return;
      assert.ok(cell.disk, `${theme} at ${width} in ${units}: ${name} has no bar where Unraid's has one`);
      near(cell.disk[0], unraid[c].disk[0], name+' bar left');
      near(cell.disk[1], unraid[c].disk[1], name+' bar right');
      near(cell.fill, unraid[c].fill, name+' fill start');
      near(cell.label, unraid[c].label, name+' label end');
      checked++;
    }));
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`Size, Used and Free line up with Unraid's pool table (${unraid7 ? 'fixed' : 'auto'} layout): ${checked} bars in ${themes.length} themes at two widths${skipped.size ? ', in Unraid\'s units only (its last column fits its text)' : ', in both units'}`);
