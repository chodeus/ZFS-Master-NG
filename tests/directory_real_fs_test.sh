#!/bin/sh
# Real rm, rsync and mounts on a fake zfs. Runs inside the private mount namespace of run_http_inventory_tests.sh.
set -eu
exec "$1" -n "$2/tests/directory_real_fs_test.php"
