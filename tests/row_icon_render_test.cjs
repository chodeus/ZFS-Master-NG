// Real browser: every row icon sits level with the dataset or folder name, on this Unraid version's sheets.
// Usage: row_icon_render_test.cjs <webgui-tree>. Set CHROME to override the browser path. Rows come from the real ZFSMFrontEnd.js.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const styles = [path.join(tree, 'emhttp/plugins/dynamix/styles'), path.join(tree, 'plugins/dynamix/styles')].find(dir => fs.existsSync(dir));
assert.ok(styles, 'webGUI styles not found under ' + tree);
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');

// A dataset with every badge and a snapshot, a plain one, and a listed folder.
const context = vm.createContext({console, localStorage: {getItem: () => null, setItem() {}, removeItem() {}}, document: {}, window: {}, Date, encodeURIComponent, decodeURIComponent});
for (const file of ['ZFSMSize.js', 'ZFSMFrontEnd.js']) vm.runInContext(fs.readFileSync(path.join(plugin, 'frontend', file), 'utf8'), context);
const now = Math.floor(Date.now() / 1000);
const ds = (name, extra) => Object.assign({name, type: 'filesystem', creation: now, used: 1024, available: 3072, referenced: 512, mountpoint: '/mnt/'+name, keystatus: 'none', child: {}, snapshots: []}, extra);
const rows = context.generateDatasetRow('cache', ds('cache', {child: {'cache/appdata': ds('cache/appdata')}, origin: 'cache/base@s', keystatus: 'available', 'org.znapzend:enabled': 'on', snapshots: [{name: 'cache@a', creation: now - 3600, used: 1, referenced: 1}]}), 'zdataset-cache', true, 0, 30, {text: 1})
  + context.generateDatasetRow('cache', ds('cache/isos'), 'zdataset-cache', true, 0, 30, {text: 1})
  + context.generateDatasetDirectoryRows('cache', ds('cache/isos', {directories: ['/mnt/cache/isos/dir']}), 'zdataset-cache', true, 0);
const pageCss = fs.readFileSync(path.join(plugin, 'ZFSMaster.page'), 'utf8').match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];
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
const sheets = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css', 'default-black.css', 'themes/black.css'].filter(name => fs.existsSync(path.join(styles, name))).map(sheet).join('\n');

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-icons-'));
let checked = 0;
try {
  const file = path.join(work, 'rows.html');
  fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><style>${sheets}\n${pageCss}</style></head><body>
<table id="zfs_master" class="zfs_table"><tbody>${rows}</tbody></table>
<script>
document.fonts.ready.then(() => {
  const canvas = document.createElement('canvas').getContext('2d');
  const ink = (text, font) => { canvas.font = font; const m = canvas.measureText(text); return [m.actualBoundingBoxAscent, m.actualBoundingBoxDescent]; };
  const fontOf = cs => [cs.fontStyle, cs.fontWeight, cs.fontSize, cs.fontFamily].join(' ');
  // A bare <b>: Unraid styles a.info span, so a span probe inside a details link would not sit on the baseline.
  const baseline = place => {
    const probe = document.createElement('b');
    probe.style.cssText = 'display:inline-block;width:0;height:0;margin:0;padding:0;position:static;vertical-align:baseline';
    place(probe);
    const top = probe.getBoundingClientRect().top;
    probe.remove();
    return top;
  };
  const rows = [...document.querySelectorAll('#zfs_master tr')].map(tr => {
    const cell = tr.querySelector('.zfsm-name-label');
    const name = [...cell.childNodes].filter(node => node.nodeType === 3 && node.textContent.trim()).pop();
    // Icons read as level with lowercase text whose x-height middle they share.
    const middle = baseline(probe => name.after(probe)) - ink('x', fontOf(getComputedStyle(cell)))[0] / 2;
    const icons = [...tr.querySelectorAll('i.fa')].map(icon => {
      const cs = getComputedStyle(icon, '::before');
      const [up, down] = ink(cs.content.replace(/^"|"$/g, ''), fontOf(cs));
      return [[...icon.classList].find(c => c.startsWith('fa-') && c !== 'fa-append'), baseline(probe => icon.appendChild(probe)) - (up - down) / 2 - middle];
    });
    // Horizontal gaps, in order: each icon in the name cell to the next, the last to the name.
    const range = document.createRange();
    range.selectNodeContents(name);
    const boxes = [...cell.querySelectorAll('i.fa')].map(icon => [[...icon.classList].find(c => c.startsWith('fa-') && c !== 'fa-append'), icon.getBoundingClientRect()]).concat([['name', range.getBoundingClientRect()]]);
    const gaps = boxes.slice(1).map(([what, box], i) => [boxes[i][0]+' to '+what, box.left - boxes[i][1].right]);
    return {name: name.textContent.trim(), icons, gaps};
  });
  const out = document.createElement('pre'); out.id = 'zfsm-result'; out.textContent = JSON.stringify({fontAwesome: document.fonts.check('16px FontAwesome'), rows}); document.documentElement.appendChild(out);
});
</script></body></html>`);
  const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
    '--window-size=1280,800', '--virtual-time-budget=5000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
  const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
  assert.ok(match, 'the rows did not render');
  const result = JSON.parse(match[1].replace(/&quot;/g, '"'));
  // Without its font every icon is a fallback glyph, and the offsets measure that instead.
  assert.ok(result.fontAwesome, 'FontAwesome did not load, so the icons could not be measured');
  if (process.env.ZFSM_ICON_REPORT) for (const row of result.rows) console.log(row.name, row.icons.map(([icon, off]) => icon+' '+off.toFixed(2)).join(' '), '| gaps', row.gaps.map(([, gap]) => gap.toFixed(1)).join(' '));
  for (const row of result.rows) {
    assert.ok(row.icons.length > 0, `${row.name}: no icons drawn`);
    for (const [icon, off] of row.icons) {
      // The open lock draws its ink high even when centred (0.61 px macOS, 0.83 px Linux); without the fix badges sit 1.3 to 2.0 px off.
      assert.ok(Math.abs(off) <= 1, `${row.name}: ${icon} sits ${off.toFixed(2)} px off the name's middle`);
      checked++;
    }
    // Every badge took the fold box's 4 px except the fold box itself, so clone, lock and the name touched.
    for (const [between, gap] of row.gaps) assert.ok(gap >= 3.5, `${row.name}: ${between} are ${gap.toFixed(2)} px apart`);
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`${checked} row icons sit level with their names, with room between them`);
