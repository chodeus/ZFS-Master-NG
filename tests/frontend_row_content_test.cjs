// What dataset and pool rows show, through the real renderer: badges, Used and Free cells, snapshot colour and count, copy progress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
// ZFSMSize.js first, as ZFSMaster.page loads it: the rows format their sizes with it.
const frontend = ['ZFSMSize.js', 'ZFSMFrontEnd.js'].map(file => fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend', file), 'utf8')).join('\n');

const context = vm.createContext({console, localStorage: {getItem: () => null, setItem() {}, removeItem() {}}, document: {}, window: {}, Date, encodeURIComponent, decodeURIComponent});
vm.runInContext(frontend, context);

const now = Math.floor(Date.now() / 1000);
const day = 86400;
const ds = extra => Object.assign({name: 'tank/data', type: 'filesystem', creation: now, used: 1024, available: 3072, referenced: 512, mountpoint: '/mnt/tank/data', keystatus: 'none', child: {}, snapshots: [], directories: []}, extra);
const row = (dataset, display = {text: 1}) => context.generateDatasetRow('tank', dataset, 'zdataset-tank', true, 0, 30, display);
const cells = html => [...html.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map(match => match[1]);
// A dataset row: lead (browse and progress, two columns wide), name with Actions, mount, refer, snapshots, size, used, free.
const nameCell = dataset => cells(row(dataset))[1];

// Badges after the name.
assert.match(nameCell(ds({keystatus: 'available'})), /fa-unlock/, 'an unlocked key shows an open lock');
assert.doesNotMatch(nameCell(ds({keystatus: 'unavailable'})), /fa-unlock/, 'a locked key does not show an open lock');
assert.match(nameCell(ds({keystatus: 'unavailable'})), /fa-lock/, 'a locked key shows a lock');
assert.doesNotMatch(nameCell(ds()), /fa-(un)?lock/, 'no encryption, no lock');
assert.match(nameCell(ds({child: {'tank/data/a': ds({name: 'tank/data/a'})}})), /fa-minus-square/, 'a dataset with children can be folded');
assert.match(nameCell(ds({directories: ['/mnt/tank/data/x']})), /fa-minus-square/, 'a dataset with listed directories can be folded');
assert.doesNotMatch(nameCell(ds()), /fa-minus-square/, 'nothing to fold, no toggle');
assert.match(nameCell(ds({compressratio: 116})), /Compress Ratio: 1\.16x</, 'the ratio reads as ZFS prints it');
assert.match(nameCell(ds({compressratio: 100})), /Compress Ratio: 1\.00x</, 'including an uncompressed one');
const folding = nameCell(ds({child: {'tank/data/a': ds({name: 'tank/data/a'})}}));
assert.match(folding, /<i class="fa fa-minus-square fa-append" name="tank\/data" role="button" tabindex="0" aria-expanded="true" aria-label="Contents of tank\/data" style="cursor:pointer" onclick="toggleDataset\(/, 'the minus icon folds the dataset, from the keyboard too');
assert.doesNotMatch(folding, /fa-hdd-o[^>]*onclick/, 'the drive icon only shows the details');
assert.match(nameCell(ds({origin: 'tank/base@s'})), /fa-clone/, 'a clone is marked');
assert.doesNotMatch(nameCell(ds()), /fa-clone/, 'a plain dataset is not marked as a clone');
assert.match(nameCell(ds({'org.znapzend:enabled': 'on'})), /fa-clock-o[\s\S]*enabled: on/, 'a znapzend plan is shown with its settings');
// Every details icon takes focus, so each says whose details it shows.
const labels = html => [...html.matchAll(/<a class="info hand" tabindex="0"( aria-label="([^"]*)")?>/g)].map(match => match[2]);
assert.deepEqual(labels(nameCell(ds({'org.znapzend:enabled': 'on'}))), ['Details of tank/data', 'znapzend plan of tank/data'], 'the dataset and its znapzend plan are named');
assert.deepEqual(labels(context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Used: '1', Free: '1', Snapshots: 0}, 'sda', true, {text: 0})), ['Devices of tank', 'Health of tank'], 'the pool\'s devices and health are named');
// A pool's own text goes through escapeHtml, and its name reaches the buttons' handlers as safeJsArg.
const marked = context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE<b>', MountPoint: '/mnt/<img src=x>', Refer: '1<2', Used: '1', Free: '1', Snapshots: 0}, 'sda', true, {text: 0});
assert.deepEqual(['ONLINE&lt;b&gt;', '/mnt/&lt;img src=x&gt;', '1&lt;2'].map(text => marked.includes(text)), [true, true, true], 'health, mount point and refer are shown as text');
assert.doesNotMatch(marked, /<img|<b>/, 'nothing in them becomes markup');
assert.match(marked, /onclick="togglePoolTable\(decodeURIComponent\('show-zpool-tank'\), decodeURIComponent\('zdataset-tank'\)\);"/, 'the show and hide button gets the pool by safeJsArg');
assert.match(marked, /<button type="button" onclick="createDataset\(decodeURIComponent\('tank'\)\);">Create Dataset<\/button>/, 'and Create Dataset, with no stray quote after it');

// Used and Free: text or a bar, as the display setting says; the bars split the space between them.
for (const [text, usedBar, freeBar] of [[0, false, false], [1, true, true], [10, false, true], [11, true, false]]) {
  const [used, free] = cells(row(ds(), {text})).slice(6, 8);
  assert.equal(/usage-disk/.test(used), usedBar, `display ${text}: Used ${usedBar ? 'is' : 'is not'} a bar`);
  assert.equal(/usage-disk/.test(free), freeBar, `display ${text}: Free ${freeBar ? 'is' : 'is not'} a bar`);
  const pool = context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Used: '1K', Free: '3K', Snapshots: 0}, 'sda', true, {text});
  assert.equal(/-attribute-used"><div class="usage-disk/.test(pool), usedBar, `display ${text}: the pool's Used matches`);
  assert.equal(/-attribute-free"><div class="usage-disk/.test(pool), freeBar, `display ${text}: the pool's Free matches`);
}
// Pool sizes come from zpool's short form and read like dataset sizes.
const poolCells = cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Size: '2.78T', Used: '1.12T', Free: '1.5P', Snapshots: 0}, 'sda', true, {text: 0})+'</tr>');
assert.deepEqual([poolCells[6], poolCells[7], poolCells[8]], ['2.8 TiB', '1.1 TiB', '1.5 PiB'], 'pool Size, Used and Free, last in the row');
assert.equal(cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Size: '1E', Used: '1', Free: '1', Snapshots: 0}, 'sda', true, {text: 0})+'</tr>')[6], '1 EiB', 'an exabyte pool');
const faulted = cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'UNAVAIL', Size: '-', Used: '-', Free: '-', Snapshots: 0}, 'sda', true, {text: 0})+'</tr>');
assert.deepEqual([faulted[6], faulted[7], faulted[8]], ['-', '-', '-'], 'a faulted pool keeps the - zpool prints');
const [usedBar, freeBar] = cells(row(ds(), {text: 1})).slice(6, 8);
assert.match(usedBar, /width:25%/, 'Used is a quarter of the space');
assert.match(freeBar, /width:75%/, 'Free is the rest');

// Snapshots: colour by age of the newest, count of all, and the newest named in the tooltip.
const snapIcon = html => html.match(/fa-hdd-o icon" style="color:([^"]+)"/)[1];
const snapCount = html => html.match(/zfsm-snap-count">([^<]*)</)[1];
assert.equal(snapIcon(row(ds())), 'grey', 'no snapshots: grey');
assert.equal(snapCount(row(ds())), '0', 'no snapshots: 0');
const recent = row(ds({snapshots: [{name: 'tank/data@old', creation: now - 40 * day}, {name: 'tank/data@new', creation: now - 100}]}));
assert.equal(snapIcon(recent), '#486dba', 'a recent snapshot: blue');
assert.equal(snapCount(recent), '2', 'every snapshot is counted');
assert.match(recent, /Last Snap: tank\/data@new/, 'the newest snapshot is named');
assert.equal(snapIcon(row(ds({snapshots: [{name: 'tank/data@old', creation: now - 40 * day}]}))), 'orange', 'a snapshot older than the alert: orange');
assert.equal(snapCount(row(ds({_snapshots_unavailable: true}))), '—', 'an unread count is not shown as a number');

// Directory copy progress.
const progress = context.parseDirectoryCopy('  1,234,567  45%   12.3MB/s    0:01:02');
assert.match(progress, /width:45%/, 'the copy bar follows the percentage');
assert.match(progress, / - 12\.3MB\/s - 0:01:02</, 'speed and time are shown');
assert.equal(context.parseDirectoryCopy('sending incremental file list'), '', 'a line without progress shows nothing');

// Actions sits at the end of the name cell, on dataset and folder rows alike.
assert.match(nameCell(ds()), /data<button type="button" id="[^"]+" onclick="addDatasetContext\([^"]+\);" class="zfs_compact">Actions<\/button>$/, 'a dataset\'s Actions ends its name cell');
const folder = cells(context.generateDatasetDirectoryRows('tank', ds({directories: ['/mnt/tank/data/x']}), 'zdataset-tank', true, 0));
assert.equal(folder.length, 8, 'a folder row has the same cells as a dataset row');
assert.match(folder[1], /x<button type="button" id="[^"]+" onclick="addDirectoryContext\([^"]+\);" class="zfs_compact">Actions<\/button>$/, 'a folder\'s Actions ends its name cell');
// Used and Free carry Unraid's own markup (nchan/device_list), so its theme draws them exactly as in its pool tables.
assert.match(cells(row(ds(), {text: 1}))[6], /^<div class="usage-disk"><span style="width:25%" class="[a-z-]*"><\/span><span>1 KiB<\/span><\/div>$/, 'Used is Unraid\'s usage-disk markup');

// A pool shows its usable space, as Unraid does: the root dataset's used and available; zpool's raw figures only if that is unread.
const pool = {Pool: 'tank', Health: 'ONLINE', Size: '2.78T', Used: '1.12T', Free: '1.5P', Snapshots: 0};
const space = root => cells('<tr>'+context.generatePoolTableRows(pool, 'sda', true, {text: 0}, root)+'</tr>').slice(6, 9);
assert.deepEqual(space({used: 3 * 1024 ** 3, available: 9 * 1024 ** 3}), ['12 GiB', '3 GiB', '9 GiB'], 'usable size is used plus available');
assert.deepEqual(space({_error: 'zfs failed'}), ['2.8 TiB', '1.1 TiB', '1.5 PiB'], 'an unread root dataset falls back to zpool');
assert.deepEqual(space(undefined), ['2.8 TiB', '1.1 TiB', '1.5 PiB'], 'and so does a missing one');
// Settings' Size Units: Unraid's units on the Main tab, with its decimals and number format (tests/size_format_test.cjs).
context.zfsm_config = {size_units: 'decimal'};
context.zfsm_display = {number: ',.', scale: -1};
assert.deepEqual(space({used: 3027000000, available: 9970000000}), ['13 GB', '3,03 GB', '9,97 GB'], 'a pool in Unraid\'s units, as its Boot Pool table shows the same space');
assert.deepEqual([cells(row(ds({referenced: 225000000000, used: 225000000000, available: 616000000000}), {text: 0}))[3], cells(row(ds({used: 225000000000, available: 616000000000}), {text: 0}))[6]], ['225 GB', '225 GB'], 'a dataset\'s Refer and Used too');
delete context.zfsm_config;
delete context.zfsm_display;

console.log('dataset and pool row content tests passed');
