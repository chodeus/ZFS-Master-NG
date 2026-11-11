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
// Unraid's row carries the same text as ours: 6.9-6.11 size the last column by its text (width:4%), so only equal text can line up.
const unraidTable = ([size, used, free]) => `<div class="TableContainer"><table class="${unraidClasses}"><thead><tr><td>Device</td><td>Identification</td><td>Temp</td><td>Reads</td><td>Writes</td><td>Errors</td><td>FS</td><td>Size</td><td>Used</td><td>Free</td></tr></thead>
<tbody><tr><td>Boot</td><td>Boot Partition</td><td>*</td><td>0.0 B/s</td><td>0.0 B/s</td><td>0</td><td>zfs</td><td>${size}</td>${bar(23, used)}${bar(77, free)}</tr></tbody></table></div>`;

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
const longName = 'flash/appdata/docker/containers/'+'a-very-long-dataset-name-'.repeat(3)+'end';
// Unraid's own text for this pool in its units, and ours in ZFS units (tests/fixtures/zfs_sizes.json).
const texts = {decimal: ['13 GB', '3.03 GB', '9.97 GB'], binary: [12997000000, 3027000000, 9970000000].map(bytes => context.fromBytesToString(bytes))};
const ourTable = units => {
  context.zfsm_config = {size_units: units};
  context.zfsm_display = {number: '.,', scale: -1};
  // A deep, long name and mount must wrap, not push the columns out of line or hide the name.
  const longDataset = context.generateDatasetRow('flash', {...root, name: longName, mountpoint: '/mnt/'+longName, origin: 'flash@s', keystatus: 'available', 'org.znapzend:enabled': 'on', referenced: 1073636966, child: {[longName+'/c']: {}}}, 'zdataset-flash', true, 0, 30, display);
  // Two jobs' progress at once, as zfsmJobProgress joins them, and an error row as ZFSMFrontEnd.js writes one, its path unbroken
  // and wider than the table: neither may push the columns out of line.
  const progressRow = longDataset.replace('<span class="zfsm-row-progress"></span>', '<span class="zfsm-row-progress">Rewrite 45% · Replicating 1.2 GiB of 3.4 GiB</span>');
  assert.notEqual(progressRow, longDataset, 'the long dataset row has an empty progress cell to fill');
  const errorRow = '<tr><td colspan="9" style="color:#e22828">ZFS query failed: cannot open \''+longName+'/'+'x'.repeat(400)+'\': I/O error on device nvme1n1p1</td></tr>';
  // The pool's count reads Loading… until it arrives; the second pool has a long name, SUSPENDED (the longest state) and a big count.
  const rows = '<tr>'+context.generatePoolTableRows({Pool: 'flash', Health: 'ONLINE', Size: '12.5G', Used: '2.82G', Free: '9.68G', MountPoint: 'none', Refer: '', Snapshots: null}, 'nvme0n1p3', true, display, root)+'</tr>'
    + context.generateDatasetRow('flash', {...root, name: 'flash/boot', mountpoint: '/boot'}, 'zdataset-flash', true, 0, 30, display)
    + progressRow
    + '<tr>'+context.generatePoolTableRows({Pool: 'fast-nvme-pool-01', Health: 'SUSPENDED', Size: '12.5G', Used: '2.82G', Free: '9.68G', MountPoint: 'none', Refer: '', Snapshots: 12345}, 'nvme1n1p1', true, display, {...root, name: 'fast-nvme-pool-01'})+'</tr>'
    + errorRow;
  const [size, used, free] = texts[units];

  assert.ok(rows.includes('>'+size+'</td>') && rows.includes('>'+used+'<') && rows.includes('>'+free+'<'), `in ${units} units the pool row shows ${texts[units].join(', ')}`);
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
try {
  // 390, a phone: 7.2+'s narrowest columns (1000px table), also with a 20% larger font (its display font setting; phones draw wider).
  // A 6.x table sizes itself by its own rows there, so it only keeps words whole.
  const runs = unraid7 ? [[390, ''], [390, '75%'], [1280, ''], [1920, '']] : [[390, ''], [1280, ''], [1920, '']];
  for (const theme of themes) {
  const css = [...base, theme].map(sheet).join('\n');
  for (const [width, font] of runs) for (const units of ['decimal', 'binary']) {
    const run = `${theme} at ${width}${font ? ' with a '+font+' font' : ''} in ${units}`;
    const file = path.join(work, `table-${width}${font ? '-font' : ''}-${units}.html`);
    // Headless Chrome keeps a window 500px wide at least: the tables sit in a frame of the width being checked.
    fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><style>${css}\n${pageCss}</style></head><body>
${font ? `<style>:root{--custom-font-size:${font}}</style>` : ''}<div id="zfsm-frame" style="width:${width}px">${unraidTable(texts[units])}${ourTable(units)}</div>
<script>
document.fonts.ready.then(() => {
  const box = el => { const r = el.getBoundingClientRect(); return [r.left, r.right]; };
  const lastThree = row => [...row.cells].slice(-3).map(td => {
    const disk = td.querySelector('.usage-disk');
    return {cell: box(td), disk: disk && box(disk), fill: disk && box(disk.firstElementChild)[0], label: disk && box(disk.lastElementChild)[1]};
  });
  const tables = document.querySelectorAll('table');
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  // A short cell wraps to two lines at most: a word split letter by letter shows as many. Pool names, like dataset names, may take more.
  const lines = td => { const rects = [...(() => { const r = document.createRange(); r.selectNodeContents(td); return r.getClientRects(); })()].sort((a, b) => a.top - b.top);
    return rects.reduce((n, rect, i) => n + (i && rect.top > rects[i - 1].top + rects[i - 1].height / 2 ? 1 : 0), rects.length ? 1 : 0); };
  const tall = [...tables[1].tHead.rows[0].cells, ...tables[1].querySelectorAll('td.zfsm-health, td.zfsm-refer, td.zfsm-snaps')].filter(td => lines(td) > 2).map(td => (td.className || 'head')+': '+td.textContent.trim().slice(0, 20));
  const clipped = [tables[1].tHead.rows[0], ...tables[1].tBodies[0].rows].flatMap((row, r) => [...row.cells].filter(td => td.scrollWidth > td.clientWidth + 1).map(td => (r ? r - 1 : 'head')+':'+(td.className || td.cellIndex)));
  out.textContent = JSON.stringify({viewport: document.getElementById('zfsm-frame').clientWidth, unraid: lastThree(tables[0].tBodies[0].rows[0]), ours: [...tables[1].tBodies[0].rows].filter(row => row.cells.length > 1).map(lastThree), clipped, tall});
  document.documentElement.appendChild(out);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      `--window-size=${Math.max(width, 500)},800`, '--virtual-time-budget=5000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    assert.ok(match, `${run}: the tables did not render`);
    const {viewport, unraid, ours, clipped, tall} = JSON.parse(match[1].replace(/&quot;/g, '"'));
    assert.equal(viewport, width, `${run}: the tables were laid out ${viewport}px wide`);
    assert.deepEqual(clipped, [], `${run}: these cells (row:cell) hide part of their content`);
    // A larger font may split a long word; it must still show whole.
    if (!font) assert.deepEqual(tall, [], `${run}: these cells wrap to more than two lines`);
    if (font || (!unraid7 && width < 1280)) continue;
    const near = (a, b, what) => assert.ok(Math.abs(a - b) <= 0.5, `${run}: ${what} at ${a.toFixed(2)}, Unraid's at ${b.toFixed(2)}`);
    ours.forEach((row, r) => row.forEach((cell, c) => {
      const name = `${['pool', 'dataset', 'long dataset', 'degraded pool'][r]} ${['Size', 'Used', 'Free'][c]}`;
      near(cell.cell[0], unraid[c].cell[0], name+' cell left');
      near(cell.cell[1], unraid[c].cell[1], name+' cell right');
      if (!unraid[c].disk) return;
      assert.ok(cell.disk, `${run}: ${name} has no bar where Unraid's has one`);
      near(cell.disk[0], unraid[c].disk[0], name+' bar left');
      near(cell.disk[1], unraid[c].disk[1], name+' bar right');
      near(cell.fill, unraid[c].fill, name+' fill start');
      near(cell.label, unraid[c].label, name+' label end');
      checked++;
    }));
  }
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`Size, Used and Free line up with Unraid's pool table and no cell clips (${unraid7 ? 'fixed' : 'auto'} layout): ${checked} bars in ${themes.length} themes at ${unraid7 ? 'a phone\'s width (also with a larger font) and two others' : 'two widths, and a phone\'s for wrapping'}, in both units`);
