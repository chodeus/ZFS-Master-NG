// Real browser at phone width and real Unraid themes: dialog text, the Starts at boxes, Snapshots Admin and Pool Info read and fit; the forms widen on a desktop.
// Usage: phone_render_test.cjs <webgui-tree>. Set CHROME to override the browser path. Each page runs in a frame as wide as on a 390 px phone.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const sheetFiles = require('./render_sheets.cjs');
const plugin = path.resolve(__dirname, '../zfs.master');
const frontend = path.join(plugin, 'frontend');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(fs.existsSync);
assert.ok(dynamix, 'webGUI not found under ' + tree);
const styles = path.join(dynamix, 'styles');
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');
// Unraid 7 styles its boxes from theme variables, which the time box rule reads too; Unraid 6 hard-codes each theme's.
const seven = fs.existsSync(path.join(styles, 'default-base.css'));

const sheets = (theme, context) => sheetFiles(styles, theme, context).map(file => `<link rel="stylesheet" href="file://${file}">`).join('\n');
const link = (...files) => files.map(file => file.endsWith('.js') ? `<script src="file://${file}"></script>` : `<link rel="stylesheet" href="file://${file}">`).join('\n');
const dialogs = link(path.join(plugin, 'assets/sweetalert2.all.min.js'), path.join(plugin, 'assets/sweetalert2.min.css'), path.join(frontend, 'ZFSMDialogs.js'), path.join(plugin, 'styles/zfsm-dialogs.css'));

// A page's own style block and body, without its PHP.
function page(file) {
  const source = fs.readFileSync(path.join(frontend, file), 'utf8');
  const style = source.match(/<style type="text\/css">[\s\S]*?<\/style>/);
  const body = source.slice(source.indexOf('<body>') + 6, source.indexOf('</body>')).replace(/<\?(php|=)[\s\S]*?\?>/g, '');
  assert.ok(style && !/<\?|\?>/.test(body), `${file}: style block and body found`);
  return {style: style[0], body};
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-phone-'));
// Runs measure() inside a frame `width` wide after the page loads, and returns what it found.
function render(name, width, head, body, measure) {
  const inner = `<!doctype html><html><head><meta charset="utf-8">${head}
<style>*,*::before,*::after{animation:none!important;transition:none!important}</style></head><body>${body}<script>
window.addEventListener('load', () => setTimeout(() => {
  const out = parent.document.createElement('pre'); out.id = 'zfsm-result'; out.textContent = JSON.stringify((${measure})()); parent.document.body.appendChild(out);
}, 50));
</script></body></html>`;
  const file = path.join(work, name.replace(/\W+/g, '-') + '.html');
  fs.writeFileSync(file, `<!doctype html><html><body style="margin:0"><iframe style="border:0;width:${width}px;height:800px" srcdoc="${inner.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"></iframe></body></html>`);
  const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
    '--window-size=800,900', '--virtual-time-budget=3000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
  const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
  assert.ok(match, `${name}: the page did not render`);
  return JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
}

let checked = 0;
try {
  // The Replicate and Schedules dialogs with a repeat on days, Schedules' copies to a server, as on the Main tab of a 390 px phone.
  const repeats = [['Replicate', "zfsmReplicateHtml({snapshots: ['tank/data@a']}, 'tank/data')", 'zfsm-replicate', 'zfsmReplicateRepeatSync'],
    ['Schedules', "zfsmScheduleDialogHtml({every: 'days', time: '05:00', days: [1], keep: '', inherited: 'Snapshot Every Dataset, every hour at :30; it keeps all it takes', skipped: 'tank/appdata has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool', copies: {every: 'days', time: '06:00', days: [1], keep: '', under: 'backup', server: 'nas', servers: ['nas'], inherited: 'Replicate Every Dataset, which is off: no scheduled copies', skipped: ''}})", 'zfsm-schedules', 'zfsmScheduleDialogSync'],
    ['Schedules', "zfsmScheduleDialogHtml({every: 'days', time: '05:00', days: [1], keep: '', inherited: 'Snapshot Every Dataset, every hour at :30; it keeps all it takes', skipped: 'tank/appdata has Sanoid or Syncoid snapshots (autosnap_, syncoid_), so it is left to that tool', copies: {every: 'days', time: '06:00', days: [1], keep: '', under: 'backup', server: 'nas', servers: ['nas'], inherited: 'Replicate Every Dataset, which is off: no scheduled copies', skipped: ''}})", 'zfsm-copies', 'zfsmScheduleDialogSync']];
  for (const [dialog, html, prefix, sync] of repeats) for (const theme of ['black', 'white', 'azure', 'gray']) {
    const where = `${dialog} dialog (${prefix}), ${theme} theme`;
    const shown = render(where, 390, sheets(theme, 'main') + dialogs + link(path.join(frontend, 'ZFSMImpact.js')), `<script>
window.addEventListener('load', () => {
  zfsmFormDialog({title: '${dialog}', html: ${html}, showCancelButton: true, confirmButtonText: 'Save'});
  const popup = Swal2.getPopup(); popup.querySelector('#${prefix}-every').value = 'days'; ${sync}(popup);
});
</script>`, `() => {
  const popup = Swal2.getPopup();
  const look = box => { const s = getComputedStyle(box); return {background: s.backgroundColor, color: s.color, font: s.fontFamily, size: s.fontSize,
    border: [s.borderTopWidth, s.borderRightWidth, s.borderLeftWidth, s.borderBottomWidth + ' ' + s.borderBottomStyle + ' ' + s.borderBottomColor, s.borderRadius], height: Math.round(box.getBoundingClientRect().height),
    focused: (box.focus(), getComputedStyle(box).backgroundColor)}; };
  return {align: Array.from(popup.querySelectorAll('p, [role=group]'), p => getComputedStyle(p).textAlign), time: look(popup.querySelector('#${prefix}-time')), text: look(popup.querySelector('#${prefix}-keep')),
    themed: getComputedStyle(popup).getPropertyValue('--focus-input-bg-color').trim() !== '', wide: [Swal2.getHtmlContainer().scrollWidth, Swal2.getHtmlContainer().clientWidth, document.documentElement.scrollWidth, innerWidth]};
}`);
    assert.ok(shown.align.length >= 5, `${where}: its rows and help found`);
    assert.ok(shown.wide[0] <= shown.wide[1] && shown.wide[2] <= shown.wide[3], `${where}: nothing may scroll sideways in the dialog or past the screen: ${JSON.stringify(shown.wide)}`);
    assert.deepEqual([...new Set(shown.align)], ['left'], `${where}: every line of text must read left-aligned, not justified`);
    const {time, text} = shown;
    for (const key of ['background', 'color', 'font', 'size']) assert.equal(time[key], text[key], `${where}: Starts at must have the text box's ${key}: ${JSON.stringify(shown)}`);
    // Unraid 6 names each theme's border and focus colours in its own sheet, and its azure and gray draw a full box.
    if (seven) assert.deepEqual([time.border, time.height], [text.border, text.height], `${where}: Starts at must have the text box's border and height: ${JSON.stringify(shown)}`);
    // Where the theme names no focus colour, the text box shows none (Unraid 7 gray) or its own sheet's; Starts at still shows a focus.
    if (seven && shown.themed) assert.equal(time.focused, text.focused, `${where}: Starts at must have the text box's focus colour: ${JSON.stringify(shown)}`);
    else assert.notEqual(time.focused, time.background, `${where}: a focused Starts at must look focused: ${JSON.stringify(shown)}`);
    checked++;
  }

  // The Schedules dialog with both parts on Default, each following a dataset deep in a pool, as on the Main tab of a 390 px phone.
  const deep = 'tank/' + 'appdata/nextcloud_data/'.repeat(4) + 'files';
  for (const theme of ['black', 'white', 'azure', 'gray']) {
    const where = `Schedules dialog on Default, ${theme} theme`;
    const shown = render(where, 390, sheets(theme, 'main') + dialogs + link(path.join(frontend, 'ZFSMImpact.js')), `<script>
window.addEventListener('load', () => {
  zfsmFormDialog({title: 'Schedules', html: zfsmScheduleDialogHtml({every: 'default', time: '', days: [], keep: '', inherited: 'the snapshot schedule of ${deep}, every hour at :30; it keeps all it takes', skipped: '',
    copies: {every: 'default', time: '', days: [], keep: '', under: '', server: '', servers: [], inherited: 'the copies of ${deep} and below, under backup every hour at :40; it keeps all its snapshots', skipped: ''}}), showCancelButton: true, confirmButtonText: 'Save'});
  zfsmScheduleDialogSync(Swal2.getPopup());
});
</script>`, `() => {
  const popup = Swal2.getPopup(), seen = id => { const p = popup.querySelector(id); return p.getBoundingClientRect().height > 0 && getComputedStyle(p).visibility === 'visible' && p.textContent.includes('${deep}'); };
  return {seen: [seen('#zfsm-schedules-default'), seen('#zfsm-copies-default')], wide: [Swal2.getHtmlContainer().scrollWidth, Swal2.getHtmlContainer().clientWidth, document.documentElement.scrollWidth, innerWidth]};
}`);
    assert.deepEqual(shown.seen, [true, true], `${where}: both Default lines must show what they follow: ${JSON.stringify(shown)}`);
    assert.ok(shown.wide[0] <= shown.wide[1] && shown.wide[2] <= shown.wide[3], `${where}: nothing may scroll sideways in the dialog or past the screen: ${JSON.stringify(shown.wide)}`);
    checked++;
  }

  // The same forms on a desktop screen: wider than a plain message, which has room for one short line.
  for (const [dialog, html] of repeats.slice(0, 2)) {
    const where = `${dialog} dialog on a 1280 px screen`;
    const shown = render(where, 1280, sheets('black', 'main') + dialogs + link(path.join(frontend, 'ZFSMImpact.js')), `<script>
window.addEventListener('load', () => {
  Swal2.fire({title: 'Message'}); window.plain = Swal2.getPopup().getBoundingClientRect().width;
  zfsmFormDialog({title: '${dialog}', html: ${html}, showCancelButton: true, confirmButtonText: 'Save'});
});
</script>`, `() => [window.plain, Swal2.getPopup().getBoundingClientRect().width, innerWidth]`);
    assert.ok(shown[1] > shown[0] * 1.3 && shown[1] < shown[2], `${where}: the form must open wider than a message, and fit the screen: ${JSON.stringify(shown)}`);
    checked++;
  }

  // Snapshots Admin in Shadowbox's 342 px frame: rows of two datasets with long names, as the page prints them.
  const admin = page('ZFSMAdminDatasetSnaps.php');
  const rows = ['tank/appdata/plex', 'tank/appdata/nextcloud'].flatMap(dataset => [1, 2, 3].map(n => `<tr data-snapshot="${dataset}@autosnap_2026-11-16_17:3${n}:44_hourly" data-automatic="1">`
    + `<td><input class="snapl-check" type="checkbox" value="${dataset}@autosnap_2026-11-16_17:3${n}:44_hourly"></td><td class="zfsm-snap-name"><span class="zfsm-ds">${dataset}@</span>autosnap_2026-11-16_17:3${n}:44_hourly<span class="zfsm-badge">held</span></td>`
    + '<td class="num">1.21 GiB</td><td class="num">48.6 GiB</td><td class="num">312 MiB</td><td class="num">1</td><td>16/11/2026, 17:30:44</td><td><span class="zfsm-actions">'
    + ['folder-open-o', 'history', 'backward', 'font', 'pause', 'play', 'clone'].map(icon => `<a><i class="fa fa-${icon}"></i></a>`).join('') + '</span></td></tr>').join(''));
  const body = admin.body.replace('<tbody id="zpools">', '<tbody id="zpools">' + rows);
  // In the page's order: its own style block first, then Unraid's sheets and ours.
  const head = admin.style + sheets('black', 'popup') + link(path.join(plugin, 'styles/zfsm-forms.css')) + dialogs
    + link(path.join(dynamix, 'javascript/dynamix.js'), path.join(frontend, 'ZFSMSnapshotList.js'));
  // One dataset's three ticked, then one of each: the long status that says Delete works on one dataset at a time.
  const name = (dataset, n) => `tank/appdata/${dataset}@autosnap_2026-11-16_17:3${n}:44_hourly`;
  for (const [state, ticks] of [['three ticked', [name('plex', 1), name('plex', 2), name('plex', 3)]], ['two datasets ticked', [name('plex', 1), name('nextcloud', 1)]]]) {
    const where = `Snapshots Admin, ${state}`;
    const shown = render(where, 342, head, body + `<script>window.addEventListener('load', () => { zfsmInitSnapshotList(); ${JSON.stringify(ticks)}.forEach(value => document.querySelector('.snapl-check[value="' + value + '"]').click()); });</script>`, `() => {
  const table = document.querySelector('.zfsm-snap-table'), box = table.parentElement, button = document.getElementById('delete-snaps'), section = document.querySelector('.zfsm-section');
  const px = (element, side) => parseFloat(getComputedStyle(element)[side]);
  const flush = table.getBoundingClientRect().left - section.getBoundingClientRect().left - px(section, 'borderLeftWidth');
  box.scrollLeft = box.scrollWidth;
  const last = table.querySelector('th:last-child').getBoundingClientRect(), frame = box.getBoundingClientRect(), b = button.getBoundingClientRect();
  return {view: innerWidth, page: document.documentElement.scrollWidth, flush, scrolls: box.scrollWidth > box.clientWidth, actions: [last.right, frame.right],
    end: button.parentElement.getBoundingClientRect().right - px(button.parentElement, 'paddingRight') - b.right - px(button, 'marginRight'),
    button: {text: button.textContent, status: document.getElementById('zfs_status').textContent, wide: [button.scrollWidth, button.clientWidth], tall: [button.scrollHeight, button.clientHeight], right: b.right}};
}`);
    const all = JSON.stringify(shown);
    assert.equal(shown.page, shown.view, `${where}: nothing may reach past the popup's right edge: ${all}`);
    assert.ok(Math.abs(shown.flush) <= 0.5, `${where}: the table must sit flush with its section's border, as before: ${all}`);
    assert.ok(shown.scrolls && shown.actions[0] <= shown.actions[1] + 1, `${where}: the table must scroll sideways until Actions shows: ${all}`);
    assert.match(shown.button.text, /^Delete selected \(\d\)$/, `${where}: Delete counts the ticked: ${all}`);
    const {wide, tall, right} = shown.button;
    assert.ok(wide[0] <= wide[1] && tall[0] <= tall[1] && right <= shown.view, `${where}: Delete's text must stay inside its border and the popup: ${all}`);
    assert.ok(Math.abs(shown.end) <= 1, `${where}: Delete must sit at the right of the footer: ${all}`);
    checked++;
  }

  // Pool Info in the same frame, every branch of its markup at once: its error, heading and notes are <p>, which Unraid 7 justifies.
  const info = page('ZFSMPoolInfo.php');
  const feature = '<tbody><tr><td class="zfsm-feature">physical_rewrite_with_a_long_name</td><td class="zfsm-needs">Not in this plugin\'s list</td><td>zfs rewrite -P on a dataset of this pool</td></tr></tbody>';
  const shown = render('Pool Info', 342, info.style + sheets('black', 'popup') + link(path.join(plugin, 'styles/zfsm-forms.css')), info.body.replace('</thead>', '</thead>' + feature).replace('<details class="zfsm-all">', '<details class="zfsm-all" open>'),
    `() => ({align: Array.from(document.querySelectorAll('p'), p => getComputedStyle(p).textAlign), view: innerWidth, page: document.documentElement.scrollWidth})`);
  assert.ok(shown.align.length >= 4, 'Pool Info: its error, heading and notes found');
  assert.deepEqual([...new Set(shown.align)], ['left'], 'Pool Info: every line of text must read left-aligned, not justified');
  assert.equal(shown.page, shown.view, `Pool Info: a wide feature table must scroll in its box, not widen the popup: ${JSON.stringify(shown)}`);
  checked++;
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`${checked} dialogs and pages read and fit at phone width, the forms wider on a desktop${seven ? '' : " (Unraid 6: the time box's border, height and focus colour not compared)"}`);
