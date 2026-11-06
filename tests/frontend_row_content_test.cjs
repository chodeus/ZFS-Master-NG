// What dataset and pool rows show, through the real renderer: badges, Used and Free cells, snapshot colour and count, copy progress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const frontend = fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMFrontEnd.js'), 'utf8');

const context = vm.createContext({console, localStorage: {getItem: () => null, setItem() {}, removeItem() {}}, document: {}, window: {}, Date, encodeURIComponent, decodeURIComponent});
vm.runInContext(frontend, context);

const now = Math.floor(Date.now() / 1000);
const day = 86400;
const ds = extra => Object.assign({name: 'tank/data', type: 'filesystem', creation: now, used: 1024, available: 3072, referenced: 512, mountpoint: '/mnt/tank/data', keystatus: 'none', child: {}, snapshots: [], directories: []}, extra);
const row = (dataset, display = {text: 1}) => context.generateDatasetRow('tank', dataset, 'zdataset-tank', true, 0, 30, display);
const cells = html => [...html.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map(match => match[1]);
const nameCell = dataset => cells(row(dataset))[2];

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
assert.match(folding, /<i class="fa fa-minus-square fa-append" name="tank\/data" style="cursor:pointer" onclick="toggleDataset\(/, 'the minus icon folds the dataset');
assert.doesNotMatch(folding, /fa-hdd-o[^>]*onclick/, 'the drive icon only shows the details');
assert.match(nameCell(ds({origin: 'tank/base@s'})), /fa-clone/, 'a clone is marked');
assert.doesNotMatch(nameCell(ds()), /fa-clone/, 'a plain dataset is not marked as a clone');
assert.match(nameCell(ds({'org.znapzend:enabled': 'on'})), /fa-clock-o[\s\S]*enabled: on/, 'a znapzend plan is shown with its settings');

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
assert.deepEqual([poolCells[3], poolCells[6], poolCells[7]], ['2.8 TiB', '1.1 TiB', '1.5 PiB'], 'pool Size, Used and Free');
assert.equal(cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'ONLINE', Size: '1E', Used: '1', Free: '1', Snapshots: 0}, 'sda', true, {text: 0})+'</tr>')[3], '1 EiB', 'an exabyte pool');
const faulted = cells('<tr>'+context.generatePoolTableRows({Pool: 'tank', Health: 'UNAVAIL', Size: '-', Used: '-', Free: '-', Snapshots: 0}, 'sda', true, {text: 0})+'</tr>');
assert.deepEqual([faulted[3], faulted[6], faulted[7]], ['-', '-', '-'], 'a faulted pool keeps the - zpool prints');
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

console.log('dataset and pool row content tests passed');
