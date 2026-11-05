// Every named function, function-valued const/let/var and define() constant must be used: a PHP name in PHP, a JavaScript name
// anywhere (PHP writes handlers into the page). Comments that start a line are skipped; a self-calling function still passes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '../zfs.master'));
const manifest = path.join(root, '..', 'zfs.master.plg');

function files(dir) {
  return fs.readdirSync(dir, {withFileTypes: true}).flatMap(entry => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return entry.name === 'assets' ? [] : files(full); // bundled libraries
    return [full];
  });
}

// a comment starts after whitespace and outside quotes: "a // b" and http://x stay code
const balanced = before => ['"', "'", '`'].every(quote => before.split(quote).length % 2 === 1);
function stripLine(line, marker) {
  for (const match of (' ' + line).matchAll(marker)) {
    const before = line.slice(0, match.index);
    if (!balanced(before)) continue;
    if (match[1] !== '/*') return before;
    const end = line.indexOf('*/', match.index + 2);
    if (end >= 0) return stripLine(before + ' ' + line.slice(end + 2), marker);
  }
  return line;
}
const uncomment = (text, marker) => text.replace(/^[ \t]*\/\*[\s\S]*?\*\//gm, '').replace(/<!--[\s\S]*?-->/g, '')
  .split('\n').map(line => stripLine(line, marker)).join('\n');
const php = [], js = [], scripts = [];
for (const file of files(root)) {
  const text = fs.readFileSync(file, 'utf8');
  if (/\.js$/.test(file)) { js.push(text); continue; }
  if (/\.(php|page)$/.test(file)) {
    // the parts inside PHP tags are PHP; the rest is the HTML and JavaScript the page sends
    js.push(text.replace(/<\?(?:php|=)?([\s\S]*?)(?:\?>|$)/g, (_, code) => { php.push(code); return '\n'; }));
  } else if (/\.sh$/.test(file) || text.startsWith('#!')) scripts.push(text);
}
if (fs.existsSync(manifest)) scripts.push(fs.readFileSync(manifest, 'utf8'));

// PHP function names ignore case; PHP constants and every JavaScript name do not
const languages = {
  php: {text: uncomment(php.join('\n'), /\s(\/\/|#|\/\*)/g), anyCase: match => match[1] !== undefined,
    defined: /\bfunction\s+([A-Za-z_]\w*)\s*\(|\bdefine\s*\(\s*['"]([A-Za-z_]\w*)['"]|\bconst\s+([A-Za-z_]\w*)\s*=/g},
  js: {text: uncomment(js.join('\n'), /\s(\/\/|\/\*)/g), anyCase: () => false,
    defined: /\bfunction\s+([A-Za-z_$][\w$]*)\s*\(|\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:function\b|\([^()]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/g}
};
const scriptText = scripts.join('\n');
const usedIn = {php: [languages.php.text, scriptText], js: [languages.js.text, languages.php.text, scriptText]};
let total = 0;
const unused = [];
for (const [language, {text, anyCase, defined}] of Object.entries(languages)) {
  const found = [...text.matchAll(defined)].map(match => ({name: match[1] ?? match[2] ?? match[3], anyCase: anyCase(match)}));
  const seen = new Set();
  for (const {name, anyCase: ci} of found) {
    const key = ci ? name.toLowerCase() : name;
    if (seen.has(key)) continue;
    seen.add(key);
    const word = new RegExp('(?<![\\w$])' + name.replace(/\$/g, '\\$') + '(?![\\w$])', ci ? 'gi' : 'g');
    const definitions = found.filter(other => (ci ? other.name.toLowerCase() === key : other.name === name)).length;
    if (usedIn[language].reduce((sum, where) => sum + (where.match(word) || []).length, 0) <= definitions) unused.push(language + ' ' + name);
  }
  total += seen.size;
}
assert.ok(total > 50, 'found only ' + total + ' definitions under ' + root);
assert.deepEqual(unused, [], 'defined but never used: ' + unused.join(', '));
console.log('dead code test passed (' + total + ' names)');
