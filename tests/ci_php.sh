#!/bin/sh
# Runs inside a disposable php:<version>-cli container (--privileged, for the private mount namespace).
set -eu
# xargs reports a failed lint; find -exec with \; always exits 0
find zfs.master tests \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
php_bin=$(command -v php)
unshare --mount --propagation private sh tests/run_http_inventory_tests.sh "$php_bin"
echo "PHP $(php -r 'echo PHP_VERSION;'): lint and backend tests passed"
