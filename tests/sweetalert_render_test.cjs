// Real browser, real Unraid themes: Destroy must look like the library's button, and not like Cancel.
// Usage: sweetalert_render_test.cjs <webgui-tree>. Set CHROME to override the browser path.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assets = path.resolve(__dirname, '../zfs.master/assets');
const tree = path.resolve(process.argv[2]);
const styles = [path.join(tree, 'emhttp/plugins/dynamix/styles'), path.join(tree, 'plugins/dynamix/styles')].find(fs.existsSync);
assert.ok(styles, 'webGUI styles not found');
const chrome = process.env.CHROME || ['google-chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');

function sheets(theme) {
  const names = ['default-fonts.css', 'default-cases.css', 'default-color-palette.css', 'default-base.css', 'default-dynamix.css',
    `default-${theme}.css`, `dynamix-${theme}.css`, `themes/${theme}.css`];
  return names.map(name => path.join(styles, name)).filter(fs.existsSync);
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-render-'));
let checked = 0;
try {
  for (const theme of ['black', 'white', 'azure', 'gray']) {
    const found = sheets(theme);
    assert.ok(found.some(file => file.includes(theme)), `no ${theme} theme in this webGUI`);
    const page = path.join(work, `${theme}.html`);
    fs.writeFileSync(page, `<!doctype html><html><head><meta charset="utf-8">
${found.map(file => `<link rel="stylesheet" href="file://${file}">`).join('\n')}
<script src="file://${assets}/sweetalert2.all.min.js"></script>
<link rel="stylesheet" href="file://${assets}/sweetalert2.min.css">
<style>*,*::before,*::after{animation:none!important;transition:none!important}</style></head><body><script>
window.addEventListener('load', () => {
  Swal2.fire({title: 'Destroy', icon: 'warning', input: 'checkbox', inputPlaceholder: 'Also', showCancelButton: true, confirmButtonText: 'Destroy'});
  const read = selector => { const s = getComputedStyle(document.querySelector(selector)); return {background: s.backgroundColor, color: s.color, border: s.borderTopStyle}; };
  const out = document.createElement('pre'); out.id = 'zfsm-result';
  out.textContent = JSON.stringify({confirm: read('.swal2-confirm'), cancel: read('.swal2-cancel')});
  document.documentElement.appendChild(out);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      '--virtual-time-budget=3000', '--dump-dom', `file://${page}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    assert.ok(match, `${theme}: the dialog did not render`);
    const {confirm, cancel} = JSON.parse(match[1].replace(/&quot;/g, '"'));
    const transparent = /rgba\(0, 0, 0, 0\)|transparent/;
    assert.doesNotMatch(confirm.background, transparent, `${theme}: Destroy has no background, so Unraid's theme won: ${JSON.stringify(confirm)}`);
    assert.doesNotMatch(cancel.background, transparent, `${theme}: Cancel has no background, so Unraid's theme won: ${JSON.stringify(cancel)}`);
    assert.notEqual(confirm.background, cancel.background, `${theme}: Destroy and Cancel look the same: ${match[1]}`);
    assert.equal(confirm.background, 'rgb(112, 102, 224)', `${theme}: Destroy must keep the library's colour: ${JSON.stringify(confirm)}`);
    checked++;
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`dialog buttons render correctly on ${checked} Unraid themes`);
