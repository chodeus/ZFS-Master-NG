// Runs the real tests/dead_code_test.cjs on small plugin trees: each case states the verdict it must give.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const checker = path.join(__dirname, 'dead_code_test.cjs');
// The checker refuses trees with 50 or fewer names, so every tree gets 60 used helpers.
const padding = Array.from({length: 60}, (_, i) => `function pad${i}() {}\npad${i}();\n`).join('');

function verdict(files) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'zfsm-dead-'));
  fs.writeFileSync(path.join(root, 'padding.js'), padding);
  for (const [name, body] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(root, name)), {recursive: true});
    fs.writeFileSync(path.join(root, name), body);
  }
  const run = spawnSync(process.execPath, [checker, root], {encoding: 'utf8'});
  fs.rmSync(root, {recursive: true, force: true});
  return {passes: run.status === 0, output: (run.stdout + run.stderr).split('\n').find(l => /defined but never used|passed/.test(l)) || ''};
}

const cases = [
  // A PHP function nobody calls is dead even if a JS function with the same name is called.
  {name: 'php_copy_dead_but_js_namesake_called', expectPass: false,
   files: {'backend/a.php': '<?php function sharedName($x) { return $x; } ?>',
           'frontend/b.js': 'function sharedName() {}\nsharedName();\n'}},
  // A mention in a comment is not a use.
  {name: 'only_mentioned_in_comment', expectPass: false,
   files: {'frontend/c.js': 'function legacyHelper() {}\n// legacyHelper was replaced by pad1\n'}},
  // PHP function names are case-insensitive: this function IS used.
  {name: 'php_call_differs_in_case', expectPass: true,
   files: {'include/e.php': '<?php function zfsmHelperX() { return 1; }\necho ZfsmHelperX(); ?>'}},
  // A function called only from a shell script shipped under zfs.master/ IS used.
  {name: 'called_from_shipped_shell_script', expectPass: true,
   files: {'include/f.php': '<?php function zfsmCronEntry() { return 1; } ?>',
           'scripts/cron.sh': '#!/bin/sh\nphp -r "require \'/usr/local/emhttp/plugins/zfs.master/include/f.php\'; zfsmCronEntry();"\n'}},
  // An unused arrow function or lowercase constant is dead code too.
  {name: 'unused_arrow_function', expectPass: false,
   files: {'frontend/g.js': 'const unusedArrow = (x) => x * 2;\n'}},
  {name: 'unused_lowercase_constant', expectPass: false,
   files: {'include/h.php': '<?php define("zfsm_unused_lower", 1); ?>'}},
];

let failures = 0;
for (const c of cases) {
  const got = verdict(c.files);
  const ok = got.passes === c.expectPass;
  if (!ok) failures++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${c.name}: expected checker to ${c.expectPass ? 'pass' : 'fail'}, it ${got.passes ? 'passed' : 'failed'} (${got.output.trim()})`);
}
if (failures) { console.log(`dead code checker: ${failures} of ${cases.length} cases wrong`); process.exit(1); }
console.log('dead code checker tests passed (' + cases.length + ' cases)');
