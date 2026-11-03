const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');

const manifest = fs.readFileSync(path.join(root, 'zfs.master.plg'), 'utf8');
assert.doesNotMatch(manifest, /rc\.nginx/, 'Plugin install/remove must not restart Unraid services');
const removepkg = manifest.split('<FILE').filter(block => /removepkg/.test(block));
assert.ok(removepkg.length > 0 && removepkg.every(block => /Method="remove"/.test(block)), 'removepkg belongs only in the remove block');

// Builds in a copy: pkg_build.sh rewrites the manifest it runs beside.
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-package-'));
try {
  fs.cpSync(path.join(root, 'zfs.master'), path.join(work, 'zfs.master'), {recursive: true});
  for (const file of ['zfs.master.plg', 'pkg_build.sh']) fs.copyFileSync(path.join(root, file), path.join(work, file));
  execFileSync('bash', ['pkg_build.sh', '--version', '0000.00.00', '--branch', 'beta', '--out', path.join(work, 'dist')], {cwd: work, stdio: 'pipe'});

  const urlFromBuild = (args) => {
    const copy = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-branch-'));
    try {
      fs.cpSync(path.join(root, 'zfs.master'), path.join(copy, 'zfs.master'), {recursive: true});
      for (const file of ['zfs.master.plg', 'pkg_build.sh']) fs.copyFileSync(path.join(root, file), path.join(copy, file));
      execFileSync('bash', ['pkg_build.sh', '--version', '0000.00.00', '--out', path.join(copy, 'dist'), ...args], {cwd: copy, stdio: 'pipe'});
      return fs.readFileSync(path.join(copy, 'zfs.master.plg'), 'utf8').match(/<!ENTITY pluginURL "([^"]+)">/)[1];
    } finally {
      fs.rmSync(copy, {recursive: true, force: true});
    }
  };
  assert.equal(urlFromBuild(['--branch', 'beta']), 'https://raw.githubusercontent.com/&github;/beta/&name;.plg');
  assert.equal(urlFromBuild(['--branch', 'feat/my-test_1.2']), 'https://raw.githubusercontent.com/&github;/feat/my-test_1.2/&name;.plg', 'a named branch keeps its own update check');
  assert.equal(urlFromBuild([]), 'https://raw.githubusercontent.com/&github;/main/&name;.plg', 'an undetected local branch falls back to main');
  assert.equal(urlFromBuild(['--branch', 'feature/a+b']), 'https://raw.githubusercontent.com/&github;/feature/a+b/&name;.plg', 'git allows + in a branch name');
  assert.throws(() => urlFromBuild(['--branch', 'a&b|c']), /Command failed/, 'a branch name that cannot go in a URL is refused');

  const built = fs.readFileSync(path.join(work, 'zfs.master.plg'), 'utf8');
  const archive = path.join(work, 'dist', 'zfs.master-0000.00.00-x86_64-1.txz');
  assert.match(built, /<!ENTITY version "0000\.00\.00">/);
  assert.equal(crypto.createHash('md5').update(fs.readFileSync(archive)).digest('hex'), built.match(/<!ENTITY md5 "([0-9a-f]{32})">/)[1]);

  const unpacked = path.join(work, 'unpacked');
  fs.mkdirSync(unpacked);
  execFileSync('tar', ['-xJf', archive, '-C', unpacked]);
  const packaged = path.join(unpacked, 'usr/local/emhttp/plugins/zfs.master');
  let checked = 0;
  function visit(directory) {
    for (const entry of fs.readdirSync(path.join(root, directory), {withFileTypes: true})) {
      const relative = path.join(directory, entry.name);
      if (entry.isDirectory()) { visit(relative); continue; }
      const source = fs.readFileSync(path.join(root, relative));
      assert.ok(source.equals(fs.readFileSync(path.join(packaged, path.relative('zfs.master', relative)))), `Stale package file: ${relative}`);
      if (/\.(?:php|page|js|sh)$/.test(relative)) assert.equal(source.includes(Buffer.from('\r\n')), false, `Non-Unix line endings: ${relative}`);
      checked++;
    }
  }
  visit('zfs.master');
  const files = execFileSync('tar', ['-tJf', archive], {encoding: 'utf8'}).trim().split(/\r?\n/).filter(entry => !entry.endsWith('/'));
  assert.equal(files.length, checked, 'Unexpected package files');
  assert.ok(files.every(entry => entry.replace(/^\.\//, '').startsWith('usr/local/emhttp/plugins/zfs.master/')), 'Package must install only under the plugin directory');
  console.log(`Package: checksum and all ${checked} source files verified`);
} finally {
  fs.rmSync(work, {recursive: true, force: true});
}
