// Real browser, every Unraid theme, phone and desktop: Settings' Schedules list as the plugin makes it (zfsmSchedulesHtml) stays inside
// the page, its buttons on one line, a short Keep on one line on a desktop; Change Keep's button opens its dialog, larger text and inside the page.
// Usage: schedules_render_test.cjs <webgui-tree>. Set CHROME to override the browser path; php must be on PATH.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const sheets = require('./render_sheets.cjs');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(fs.existsSync);
assert.ok(dynamix, 'webGUI not found under ' + tree);
const chrome = process.env.CHROME || ['google-chrome', 'chromium', 'chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');
const page = fs.readFileSync(path.join(plugin, 'ZFSMasterSettings.page'), 'utf8');
const from = page.indexOf('// Replicate To Other Servers:');
const script = page.slice(from, page.indexOf('</script>', from));
assert.ok(from > 0 && !script.includes('<?'), 'the Settings script is in ZFSMasterSettings.page, with no PHP');

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-schedules-'));
let checked = 0;
try {
  // One schedule of each kind, long names, Leave Out and Keep, and the copy with Change Keep has a warning.
  const root = path.join(work, 'root');
  const jobs = path.join(work, 'jobs');
  fs.mkdirSync(path.join(root, 'boot/config/plugins/zfs.master'), {recursive: true});
  fs.mkdirSync(path.join(jobs, 'schedule'), {recursive: true});
  const every = {minute: 0, hour: 0, days: [0, 1, 2, 3, 4, 5, 6]};
  fs.writeFileSync(path.join(root, 'boot/config/plugins/zfs.master/schedules.json'), JSON.stringify([
    {...every, id: '1a2b3c4d', dataset: '', dest: '', server: '', every: 'hourly', minute: 27, keep: 2, leave_out: ['appdata', 'domains', 'isos', 'system', 'backup_*', 'media-library', 'downloads-incoming']},
    {...every, id: '2b3c4d5e', dataset: '', dest: 'replicas/', server: 'nas', every: 'days', minute: 30, hour: 3, days: [1, 3, 5], keep: '24 7d 4w 12m'},
    {...every, id: '3c4d5e6f', dataset: 'cache/appdata/a-long-dataset-name', dest: 'backup/replicas/cache/appdata/a-long-dataset-name', server: '', every: 'days', hour: 4, keep: '7d',
      copy_keeps: '30d 12m', remake: true, replace: true},
    {...every, id: '4d5e6f70', dataset: 'cache/domains', dest: '', server: '', every: 'cron', cron: '30 */6 * * 1-5', keep: 4},
    {...every, id: '5e6f7081', dataset: 'cache/isos', dest: '', server: '', every: 'none', keep: 0}]));
  fs.writeFileSync(path.join(jobs, 'schedule/3c4d5e6f.json'), JSON.stringify({failures: 3, told: true}));
  const table = execFileSync('php', ['-n', '-d', 'display_errors=stderr', '-r', 'function publish($c, $m) {} require $argv[1]; $GLOBALS["zfsm_root"] = $argv[2]; echo zfsmSchedulesHtml(array());',
    path.join(plugin, 'backend/ZFSMOperations.php'), root], {encoding: 'utf8', env: {...process.env, ZFSM_TEST_JOB_DIR: jobs}});
  assert.equal((table.match(/<tbody/g) || []).length, 5, 'a tbody for each schedule');
  assert.match(table, /<tr><td colspan="6"[^>]*>3 run\(s\) in a row with a problem/, 'and the copy\'s warning on a line across');

  for (const theme of ['black', 'white', 'azure', 'gray']) for (const width of [500, 1280]) {
    const styles = path.join(dynamix, 'styles');
    const found = sheets(styles, theme, 'main');
    assert.ok(found.some(file => file.includes(theme)), `no ${theme} theme in this webGUI`);
    const file = path.join(work, `schedules-${theme}-${width}.html`);
    // The section as Unraid's Markdown leaves it: the rows' list, then the list's block (tests/unraid_compat_check.php checks that).
    fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
${found.map(sheet => `<link rel="stylesheet" href="file://${sheet}">`).join('\n')}
<link rel="stylesheet" href="file://${plugin}/styles/zfsm-forms.css">
<link rel="stylesheet" href="file://${plugin}/assets/sweetalert2.min.css">
<link rel="stylesheet" href="file://${plugin}/styles/zfsm-dialogs.css">
<style>*,*::before,*::after{animation:none!important;transition:none!important}</style>
<script src="file://${path.join(dynamix, 'javascript/dynamix.js')}"></script>
${['assets/sweetalert2.all.min.js', 'frontend/ZFSMDialogs.js', 'frontend/ZFSMImpact.js', 'frontend/ZFSMForm.js'].map(name => `<script src="file://${plugin}/${name}"></script>`).join('\n')}
</head><body><details class="zfsm-section" open><summary>Schedules</summary>
<dl><dt>Replicate Every Dataset:</dt><dd><select><option>Off</option></select></dd></dl>
<div class="zfsm-sched-block"><div id="zfsm-schedules">${table}</div><div id="zfsm-schedules-status" style="color:#e22828"></div></div>
</details>
<script>${script}</script>
<script>
window.addEventListener('load', () => {
  const rows = [...document.querySelectorAll('.zfsm-sched-table tbody > tr:first-child')];
  const scroll = document.querySelector('.zfsm-table-scroll');
  const lines = element => Math.round(element.getBoundingClientRect().height / parseFloat(getComputedStyle(element).lineHeight));
  const buttons = [...rows[2].querySelectorAll('input[type=button]')];
  const list = {page: document.documentElement.scrollWidth - document.documentElement.clientWidth, scroll: scroll.scrollWidth - scroll.clientWidth,
    keep: lines(rows[0].cells[4].firstElementChild), buttons: buttons.map(button => Math.round(button.getBoundingClientRect().top)),
    dash: getComputedStyle(rows[0].cells[2], '::before').content, warning: Math.round(document.querySelector('td[colspan="6"]').getBoundingClientRect().width - rows[2].getBoundingClientRect().width)};
  buttons[0].click();
  setTimeout(() => {
    const size = selector => Math.round(1000 * parseFloat(getComputedStyle(document.querySelector(selector)).fontSize) / parseFloat(getComputedStyle(document.documentElement).fontSize)) / 1000;
    const popup = Swal2.getPopup().getBoundingClientRect();
    const dialog = {title: Swal2.getTitle().textContent, popup: size('.swal2-popup'), text: size('.swal2-html-container p'), hint: size('#zfsm-change-keep-hint'), input: size('#zfsm-change-copy-keeps'), button: size('.swal2-confirm'),
      inside: popup.left >= 0 && popup.right <= document.documentElement.clientWidth, wide: Swal2.getHtmlContainer().scrollWidth - Swal2.getHtmlContainer().clientWidth};
    const out = document.createElement('pre'); out.id = 'zfsm-result'; out.textContent = JSON.stringify({list, dialog});
    document.documentElement.appendChild(out);
  }, 50);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      `--window-size=${width},800`, '--virtual-time-budget=3000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    const at = `${theme} at ${width}`;
    assert.ok(match, `${at}: the list or its dialog did not render`);
    const {list, dialog} = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
    assert.ok(list.page <= 0, `${at}: the list widens the page by ${list.page}px`);
    // A phone scrolls the table on its own (.zfsm-table-scroll); a desktop has room for it all.
    if (width === 1280) assert.equal(list.keep, 1, `${at}: the newest 2 takes ${list.keep} lines`);
    if (width === 1280) assert.ok(list.scroll <= 0, `${at}: the table is ${list.scroll}px wider than the section`);
    assert.deepEqual([list.buttons.length, new Set(list.buttons).size], [2, 1], `${at}: Change Keep and Remove are not side by side: ${list.buttons}`);
    assert.equal(list.dash, '"–"', `${at}: an empty cell shows ${list.dash}, not a dash`);
    assert.ok(Math.abs(list.warning) <= 1, `${at}: the warning is ${list.warning}px off its row's width`);
    // The library sizes its text 1.125 times the popup's, a hint is .9 of that: 15.2px and 13.7px on Unraid's 10px root.
    assert.deepEqual([dialog.title, dialog.popup, dialog.text, dialog.hint, dialog.input, dialog.button], ['Change Keepcache/appdata/a-long-dataset-name', 1.35, 1.519, 1.367, 1.5, 1.25],
      `${at}: Change Keep's dialog, or its text sizes (in rem): ${JSON.stringify(dialog)}`);
    assert.ok(dialog.inside && dialog.wide <= 0, `${at}: the dialog is outside the page, or its fields ${dialog.wide}px wider than it`);
    checked++;
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`the Schedules list and Change Keep fit ${checked} renders in 4 themes, phone and desktop`);
