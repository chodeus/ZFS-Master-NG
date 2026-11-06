// Real browser, real Unraid themes: a dialog takes the page's colours, and its buttons look like the page's own.
// Usage: sweetalert_render_test.cjs <webgui-tree>. Set CHROME to override the browser path.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assets = path.resolve(__dirname, '../zfs.master/assets');
const plugin = path.resolve(__dirname, '../zfs.master');
const tree = path.resolve(process.argv[2]);
const styles = [path.join(tree, 'emhttp/plugins/dynamix/styles'), path.join(tree, 'plugins/dynamix/styles')].find(fs.existsSync);
assert.ok(styles, 'webGUI styles not found');
const chrome = process.env.CHROME || ['google-chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(bin => {
  try { execFileSync(bin, ['--version'], {stdio: 'ignore'}); return true; } catch { return false; }
});
assert.ok(chrome, 'Chrome not found');

// The Main page's sheets, or a Shadowbox dialog's: mirrors zfsmPopupStylesheets() in ZFSMHelpers.php.
function sheets(theme, context) {
  const popup = fs.existsSync(path.join(styles, 'default-popup.css')) ? ['default-popup.css'] : ['default-color-palette.css', 'default-base.css', `themes/${theme}.css`];
  const names = context === 'popup' ? ['default-fonts.css', 'font-awesome.css', ...popup] : ['default-fonts.css', 'default-cases.css', 'default-color-palette.css', 'default-base.css', 'default-dynamix.css',
    `default-${theme}.css`, `dynamix-${theme}.css`, `themes/${theme}.css`];
  return names.map(name => path.join(styles, name)).filter(fs.existsSync);
}

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-render-'));
let checked = 0;
try {
  for (const [context, theme] of ['black', 'white', 'azure', 'gray'].map(theme => ['main', theme]).concat([['popup', 'black']])) {
    const found = sheets(theme, context);
    assert.ok(context === 'popup' || found.some(file => file.includes(theme)), `no ${theme} theme in this webGUI`);
    const page = path.join(work, `${context}-${theme}.html`);
    fs.writeFileSync(page, `<!doctype html><html><head><meta charset="utf-8">
${found.map(file => `<link rel="stylesheet" href="file://${file}">`).join('\n')}
<script src="file://${assets}/sweetalert2.all.min.js"></script>
<link rel="stylesheet" href="file://${assets}/sweetalert2.min.css">
<script src="file://${plugin}/frontend/ZFSMDialogs.js"></script>
<link rel="stylesheet" href="file://${plugin}/styles/zfsm-dialogs.css">
<style>*,*::before,*::after{animation:none!important;transition:none!important}</style></head><body><button type="button" id="plain">Plain</button><script>
window.addEventListener('load', () => {
  Swal2.fire({title: 'Destroy', icon: 'warning', input: 'checkbox', inputPlaceholder: 'Also', showCancelButton: true, confirmButtonText: 'Destroy'});
  setTimeout(() => {
    const read = element => { const s = getComputedStyle(element); return {background: s.backgroundColor, image: s.backgroundImage, color: s.color, border: s.borderTopStyle, transform: s.textTransform}; };
    const page = [document.body, document.documentElement].map(read).find(s => !/^(transparent|rgba\(0, 0, 0, 0\))$/.test(s.background));
    const out = document.createElement('pre'); out.id = 'zfsm-result';
    out.textContent = JSON.stringify({page, popup: read(document.querySelector('.swal2-popup')), title: read(document.querySelector('.swal2-title')), checkbox: read(document.querySelector('.swal2-checkbox')),
      confirm: read(document.querySelector('.swal2-confirm')), cancel: read(document.querySelector('.swal2-cancel')), plain: read(document.getElementById('plain'))});
    document.documentElement.appendChild(out);
  }, 50);
});
</script></body></html>`);
    const dom = execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files', `--user-data-dir=${path.join(work, 'profile')}`,
      '--virtual-time-budget=3000', '--dump-dom', `file://${page}`], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 50 * 1024 * 1024});
    const match = dom.match(/<pre id="zfsm-result">([^<]+)<\/pre>/);
    assert.ok(match, `${context} ${theme}: the dialog did not render`);
    const {page: shown, popup, title, checkbox, confirm, cancel, plain} = JSON.parse(match[1].replace(/&quot;/g, '"'));
    assert.ok(shown, `${context} ${theme}: the page has no background colour`);
    assert.equal(popup.background, shown.background, `${context} ${theme}: the dialog must take the page's background: ${match[1]}`);
    assert.equal(title.color, shown.color, `${context} ${theme}: the dialog text must take the page's colour: ${match[1]}`);
    assert.equal(checkbox.background, shown.background, `${context} ${theme}: the checkbox label must take the page's background: ${match[1]}`);
    for (const [name, button] of [['Destroy', confirm], ['Cancel', cancel]]) {
      const look = ({background, image, border, transform}) => ({background, image, border, transform});
      assert.deepEqual(look(button), look(plain), `${context} ${theme}: ${name} must look like the page's own button: ${match[1]}`);
    }
    assert.notEqual(confirm.background, 'rgb(112, 102, 224)', `${context} ${theme}: Destroy still has the library's purple`);
    checked++;
  }
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
console.log(`dialogs follow ${checked} Unraid themes and dialog pages`);
