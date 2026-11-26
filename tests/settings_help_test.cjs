// The Settings page through this Unraid version's Markdown and help binder: each help opens from one label, the Schedules help from none;
// none is a wall, and each Schedules help hides with its row, Help button or not.
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
const schedules = helps.find(help => words(help) === 'Every schedule on this server.');
assert.ok(schedules, 'no Schedules help');
assert.deepEqual([...labels].filter(([, by]) => by.length !== 1).map(([help, by]) => [words(help), by]), [['Every schedule on this server.', []]],
  'every help but the Schedules help opens from one label, and that one from none');
// Each help under Schedules opens from the setting it is about, and no help is over 40 words.
const section = [...$('#zfsm-snapsched-every').closest('details').find('blockquote.inline_help').get()];
assert.deepEqual(section.map(help => [labels.get(help)[0] ?? null, words(help)]), [
  ['Snapshot Every Dataset:', 'Snapshots every dataset in every'], ['Cron Line:', 'Five fields, as in the'], ['Snapshots To Keep:', 'A number keeps the newest'],
  ['Leave Out:', 'Datasets at the top of'], ['Replicate Every Dataset:', 'Copies every dataset in every'], ['Copy Under:', 'The dataset the copies go'],
  ['Cron Line:', 'Five fields, as in the'], ['Snapshots To Keep:', 'A number keeps the newest'], ['Copy Keeps:', 'What each copy keeps. Empty'],
  ['Leave Out:', 'Datasets at the top of'], [null, 'Every schedule on this server.']], 'each Schedules help opens from its own setting');
assert.deepEqual([...helps.filter(help => help.textContent.trim().split(/\s+/).length > 40).map(words)], [], 'no help is over 40 words');

// The page's own script on that render: each help hides with its row, even once Unraid's Help button has shown every help.
window.$.post = () => ({fail() { return this; }});
window.$.fx.off = true;
const style = window.document.createElement('style');
style.textContent = fs.readFileSync(path.join(__dirname, '../zfs.master/styles/zfsm-forms.css'), 'utf8');
window.document.head.appendChild(style);
// What mk_option and zfsmReplicaServerOptions write, which this render leaves out.
for (const id of ['zfsm-snapsched-every', 'zfsm-copysched-every']) $('#' + id).append(['off', 'hourly', 'days', 'cron', 'start'].map(value => `<option value="${value}">`).join(''));
$('#zfsm-copysched-server').append('<option value=""></option><option value="nas"></option>');
const script = fs.readFileSync(page, 'utf8');
const from = script.indexOf('// Replicate To Other Servers:');
window.eval(script.slice(from, script.indexOf('</script>', from)));
const buttonPage = fs.readFileSync(path.join(dynamix, 'HelpButton.page'), 'utf8');
const button = buttonPage.indexOf('function HelpButton()');
assert.ok(button > 0, 'no HelpButton in ' + dynamix);
window.eval(buttonPage.slice(button, buttonPage.indexOf('</script>', button)));
$('body').append('<div id="nav-item" class="nav-item HelpButton"></div>');
const seen = () => section.map(help => window.getComputedStyle(help).display !== 'none');
const pick = (id, value) => $('#' + id).val(value).trigger('change');
pick('zfsm-snapsched-every', 'hourly');
window.HelpButton();
assert.deepEqual(seen(), [true, false, true, true, true, false, false, false, false, false, true],
  'Help shows each shown row\'s help: Every hour leaves out Cron Line\'s, and Replicate Every Dataset at Off all but its own');
pick('zfsm-snapsched-every', 'cron');
pick('zfsm-copysched-every', 'days');
assert.deepEqual(seen(), [true, true, true, true, true, true, false, true, true, true, true], 'Custom (cron) shows Cron Line\'s, and On days all but Cron Line\'s');
pick('zfsm-copysched-server', 'nas');
assert.deepEqual(seen(), [true, true, true, true, true, true, false, true, false, true, true], 'another server hides Copy Keeps\' with its row');
window.HelpButton();
assert.deepEqual(seen(), Array(11).fill(false), 'and Help again hides them all');
console.log(`${helps.length - 1} Settings helps open from their own labels, the Schedules help from none, and each hides with its row, on jQuery ${$.fn.jquery}`);
