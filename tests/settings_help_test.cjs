// The Settings page through this Unraid version's Markdown and help binder: each help opens from one label, the Schedules help from none.
// Usage: settings_help_test.cjs <webgui-tree>; php on PATH, or PHP_IMAGE to run it in that image, for an Unraid older than that PHP.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const {execFileSync} = require('node:child_process');
const tree = path.resolve(process.argv[2]);
const dynamix = [path.join(tree, 'emhttp/plugins/dynamix'), path.join(tree, 'plugins/dynamix')].find(fs.existsSync);
assert.ok(dynamix, 'webGUI not found under ' + tree);
const page = path.join(__dirname, '../zfs.master/ZFSMasterSettings.page');
// Unraid's own parse_text, lifted out of Translations.php (which needs Unraid's docroot), turns each :..._plug: and :end into PHP; then
// Markdown runs, then that PHP, which leaves no mark. Unraid defines _() itself, as its PHP has no gettext.
const render = ['php', '-n', '-d', 'display_errors=stderr', '-r', `$inc = $argv[1].'/include/';
  require is_file($inc.'MarkdownExtra.inc.php') ? $inc.'MarkdownExtra.inc.php' : $inc.'Markdown.php';
  if (!function_exists('_')) { function _($text) { return $text; } }
  if (!preg_match('/^function parse_text\\(\\$text\\) \\{.*?^\\}/ms', file_get_contents($inc.'Translations.php'), $found)) { fwrite(STDERR, "no parse_text\\n"); exit(1); }
  eval(str_replace('function parse_text(', 'function zfsm_parse_text(', $found[0]));
  $page = file_get_contents($argv[2]);
  echo preg_replace('/<\\?.*?\\?>/s', '', Markdown(zfsm_parse_text(substr($page, strpos($page, "\\n---\\n") + 5))));`, dynamix, page];
const image = process.env.PHP_IMAGE;
const html = image ? execFileSync('docker', ['run', '--rm', '-v', `${dynamix}:${dynamix}:ro`, '-v', `${page}:${page}:ro`, image, ...render], {encoding: 'utf8'})
  : execFileSync(render[0], render.slice(1), {encoding: 'utf8'});
const layout = ['include/DefaultPageLayout/BodyInlineJS.php', 'include/DefaultPageLayout.php'].map(name => path.join(dynamix, name)).find(fs.existsSync);
const source = fs.readFileSync(layout, 'utf8');
const start = source.indexOf("$('blockquote.inline_help').each(function(i) {");
assert.ok(start > 0, 'no help binder in ' + layout);
let end = source.indexOf('(', start + 30), depth = 0;
do depth += {'(': 1, ')': -1}[source[end++]] || 0; while (depth > 0);

const {window} = new JSDOM(html, {runScripts: 'outside-only'});
window.eval(fs.readFileSync(path.join(dynamix, 'javascript/dynamix.js'), 'utf8'));
const $ = window.$;
const helps = $('blockquote.inline_help').get();
assert.ok(helps.length > 20, `Markdown made ${helps.length} help blocks`);
// Each click records the help it opens, in place of Unraid's slide.
let opened = [];
$.fn.toggle = function() { opened.push(...this.get()); return this; };
window.eval(source.slice(start, end) + ';');
const words = help => help.textContent.trim().split(/\s+/).slice(0, 5).join(' ');
const labels = new Map(helps.map(help => [help, []]));
for (const dt of $('dt').get().filter(dt => dt.style.cursor === 'help')) {
  opened = [];
  $(dt).trigger('click');
  for (const help of opened) labels.get(help).push(dt.textContent.trim());
  assert.ok(opened.length <= 1, `${dt.textContent.trim()} opens ${opened.length} helps: ${opened.map(words).join(' / ')}`);
}
const schedules = helps.find(help => words(help) === 'Snapshot Every Dataset and Replicate');
assert.ok(schedules, 'no Schedules help');
assert.deepEqual([...labels].filter(([, by]) => by.length !== 1).map(([help, by]) => [words(help), by]), [['Snapshot Every Dataset and Replicate', []]],
  'every help but the Schedules help opens from one label, and that one from none');
console.log(`${helps.length - 1} Settings helps open from their own labels, and the Schedules help from none, on jQuery ${$.fn.jquery}`);
