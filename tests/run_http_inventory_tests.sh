#!/bin/sh
# Run in an isolated mount namespace as root; nothing is installed into Unraid.
# Usage: unshare --mount --propagation private sh tests/run_http_inventory_tests.sh /path/to/php
set -eu
if [ "$(readlink /proc/self/ns/mnt)" = "$(readlink /proc/1/ns/mnt)" ]; then
    echo 'Refusing to run outside a private mount namespace' >&2
    exit 1
fi
zfsm_php=$1
zfsm_repo=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mount -t tmpfs tmpfs /tmp
# impact_test.php writes under /mnt/user, which on an Unraid host holds the user shares.
mkdir -p /mnt/user
mount -t tmpfs tmpfs /mnt/user
mkdir -p /tmp/zfsm-http-test/webGui/include /tmp/zfsm-http-test/plugins /tmp/zfsm-bin
ln -s "$zfsm_repo/zfs.master" /tmp/zfsm-http-test/plugins/zfs.master
ln -s "$zfsm_repo/tests/fixtures/unraid/Helpers.php" /tmp/zfsm-http-test/webGui/include/Helpers.php
ln -s "$zfsm_repo/tests/fixtures/unraid/publish_forbidden.php" /tmp/zfsm-http-test/webGui/include/publish.php
cp "$zfsm_repo/tests/fixtures/bin/zfs" "$zfsm_repo/tests/fixtures/bin/zpool" /tmp/zfsm-bin/
chmod +x /tmp/zfsm-bin/zfs /tmp/zfsm-bin/zpool
export ZFSM_TEST_DOCROOT=/tmp/zfsm-http-test ZFSM_TEST_BIN_DIR=/tmp/zfsm-bin
touch /tmp/publishPaused
for mode in full lazy pool invalid missing gone pool_failure snapshot_failure refresh_paused; do
    "$zfsm_php" -n "$zfsm_repo/tests/inventory_route_test.php" "$mode"
done
"$zfsm_php" -n "$zfsm_repo/tests/inventory_timeout_test.php"
ZFSM_TEST_LOG=/tmp/zfsm-backend-commands.log "$zfsm_php" -n ${ZFSM_TEST_POSIX:+-d "extension=$ZFSM_TEST_POSIX"} "$zfsm_repo/tests/backend_logic_test.php"
for gate in rollback_newer destroy_recursive destroy_dataset rollback_plain destroy_plain destroy_unreviewed snapshot_route_dataset newer_with_mode invalid_exclusion lua_exclusion encrypt_no_passphrase encrypt_other_value encrypt_inherit_passphrase preview_destroy preview_rollback diff_snapshot preview_convert; do
    "$zfsm_php" -n "$zfsm_repo/tests/admin_gate_test.php" "$gate"
done
"$zfsm_php" -n "$zfsm_repo/tests/impact_test.php"
"$zfsm_php" -n "$zfsm_repo/tests/snapshot_files_test.php"
"$zfsm_php" -n "$zfsm_repo/tests/alerts_test.php"
"$zfsm_php" -n "$zfsm_repo/tests/rewrite_test.php"
sh "$zfsm_repo/tests/directory_real_fs_test.sh" "$zfsm_php" "$zfsm_repo"
for route in createdataset editdatasetproperty snapshotdataset snapshotdataset_utc rollbacksnapshot renamesnapshot destroysnapshot destroydataset previewrewrite rewritedataset; do
    "$zfsm_php" -n ${ZFSM_TEST_POSIX:+-d "extension=$ZFSM_TEST_POSIX"} "$zfsm_repo/tests/admin_route_test.php" "$route"
done
