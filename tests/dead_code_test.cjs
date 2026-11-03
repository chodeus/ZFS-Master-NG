// Every function and constant the plugin defines must be used somewhere in the plugin.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '../zfs.master'));

function sources(dir) {
  return fs.readdirSync(dir, {withFileTypes: true}).flatMap(entry => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return entry.name === 'assets' ? [] : sources(full); // bundled libraries
    return /\.(js|php|page)$/.test(entry.name) ? [fs.readFileSync(full, 'utf8')] : [];
  });
}

const text = sources(root).join('\n');
const count = pattern => (text.match(pattern) || []).length;
const names = new Set([
  ...[...text.matchAll(/\bfunction\s+([A-Za-z_]\w*)\s*\(/g)].map(match => match[1]),
  ...[...text.matchAll(/\bdefine\(\s*['"]([A-Z0-9_]+)['"]/g)].map(match => match[1])
]);
assert.ok(names.size > 50, 'found only ' + names.size + ' definitions under ' + root);
const unused = [...names].filter(name => {
  const definitions = count(new RegExp('\\bfunction\\s+' + name + '\\s*\\(', 'g')) + count(new RegExp('\\bdefine\\(\\s*[\'"]' + name + '[\'"]', 'g'));
  return count(new RegExp('(?<![\\w$])' + name + '(?![\\w$])', 'g')) <= definitions;
});
assert.deepEqual(unused, [], 'defined but never used: ' + unused.join(', '));
console.log('dead code test passed (' + names.size + ' names)');
