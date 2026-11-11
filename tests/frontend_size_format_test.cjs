// Sizes as Settings picks them: ZFS's units by default, Unraid's my_scale when picked, checked against Unraid's own answers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../zfs.master/frontend/ZFSMSize.js'), 'utf8'), context);

// my_scale's own answers (Unraid 6.9.2 and 7.3.2 agree, on PHP 7.4 and 8.4); unraid_compat_check.php re-checks them on every Unraid in CI.
const answers = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/unraid_sizes.json'), 'utf8'));
assert.ok(answers.length > 400, 'the fixture holds Unraid\'s answers');
for (const c of answers) {
  assert.equal(context.zfsmUnraidSize(c.bytes, {scale: c.scale, number: c.number}, c.decimals ?? undefined), c.text, `${c.bytes} B, decimals ${c.decimals}, scale ${c.scale}, number "${c.number}"`);
}

// A null display setting is Unraid's default, as a missing one is, in PHP too (size_format_test.php).
// 999.96 GB rounds up to 1 TB only under the default scale.
assert.equal(context.zfsmUnraidSize(999960000000, {scale: null, number: null}), '1 TB');

// The setting: ZFS's units unless Settings picks Unraid's; anything else keeps ZFS's.
const size = (config, bytes, decimals) => {
  context.zfsm_config = config;
  context.zfsm_display = {number: '.,', scale: -1};
  return context.zfsmSize(bytes, decimals);
};
assert.equal(size(undefined, 3027000000), '2.8 GiB', 'no setting yet: ZFS units, as before');
assert.equal(size({size_units: 'binary'}, 3027000000), '2.8 GiB');
assert.equal(size({size_units: 'decimal'}, 3027000000), '3.03 GB');
assert.equal(size({size_units: 'decimal'}, 13000000000, -1), '13 GB', 'Size passes -1 decimals, as Unraid\'s Size column does');
assert.equal(size({size_units: 'GB'}, 3027000000), '2.8 GiB', 'an unknown value keeps ZFS units');

// ZFS units print as the Main tab always has: powers of 1024, one decimal; size_format_test.php checks PHP against the same answers.
for (const [bytes, text] of JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/zfs_sizes.json'), 'utf8'))) {
  assert.equal(context.fromBytesToString(bytes), text, `${bytes} B in ZFS units`);
}

console.log(`size format tests passed: ${answers.length} of Unraid's answers`);
