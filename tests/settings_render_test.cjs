// Real browser: a Settings section's last control keeps a gap above the section's bottom border, on a phone and a desktop, in every theme.
// Usage: settings_render_test.cjs <webgui-tree>. Set CHROME to override the browser path. The section is what Unraid's Markdown makes of the page.
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
const styles = path.join(dynamix, 'styles');
const base = ['default-fonts.css', 'font-awesome.css', 'default-color-palette.css', 'default-base.css'].map(name => path.join(styles, name)).filter(fs.existsSync);
const themes = ['black', 'white', 'azure', 'gray'].map(theme => [`themes/${theme}.css`, `default-${theme}.css`].map(name => path.join(styles, name)).find(fs.existsSync)).filter(Boolean);
assert.ok(themes.length > 0, 'no Unraid theme found under ' + styles);
// Unraid's Markdown output for two rows of the General Settings section (MarkdownExtra on the page's definition lists),
// and for the Diagnostics section, which is after the form and ends in a button.
const section = `<form><details class="zfsm-section" open><summary>General Settings</summary>
<dl>
<dt>Refresh Interval:</dt>
<dd><select name="refresh_interval"><option>30 seconds</option></select></dd>
<dt>Destructive Mode:</dt>
<dd><select name="destructive_mode"><option>No</option></select></dd>
</dl>
</details></form>
<details class="zfsm-section" open><summary>Diagnostics</summary>
<dl>
<dt>Unraid Diagnostics:</dt>
<dd><input type="checkbox" checked></dd>
<dt>Diagnostics Zip:</dt>
<dd><input type="button" value="Generate"> <span>Collecting. This can take a few minutes.</span></dd>
</dl>
</details>`;

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-settings-'));
let checked = 0;
try {
  // 500 is the narrowest window headless Chrome keeps, inside Unraid's phone layout.
  for (const theme of themes) for (const width of [500, 1280]) {
    const file = path.join(work, `settings-${width}.html`);
    fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
${[...base, theme].map(sheet => `<link rel="stylesheet" href="file://${sheet}">`).join('\n')}
<link rel="stylesheet" href="file://${path.join(plugin, 'styles/zfsm-forms.css')}"></head><body>${section}
<script>
window.addEventListener('load', () => {
  const gaps = [...document.querySelectorAll('.zfsm-section')].map(box => {
    const last = [...box.querySelectorAll('select, input[type=button]')].pop();
    return box.getBoundingClientRect().bottom - parseFloat(getComputedStyle(box).borderBottomWidth) - last.getBoundingClientRect().bottom;
  });
  const out = document.createElement('pre'); out.id = 'zfsm-result'; out.textContent = JSON.stringify({gaps});
  document.documentElement.appendChild(out);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      `--window-size=${width},800`, '--virtual-time-budget=3000', '--dump-dom', `file://${file}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    assert.ok(match, `${path.basename(theme)} at ${width}: the section did not render`);
    const {gaps} = JSON.parse(match[1].replace(/&quot;/g, '"'));
    assert.equal(gaps.length, 2, `${path.basename(theme)} at ${width}: both sections rendered`);
    for (const gap of gaps) assert.ok(gap >= 8, `${path.basename(theme)} at ${width}: the last control sits ${gap.toFixed(1)}px above the section's bottom border, under 8`);
    checked++;
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`settings sections keep a gap under their last control: ${checked} renders in ${themes.length} themes, phone and desktop`);
