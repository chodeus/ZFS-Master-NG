#!/bin/sh
# Runs inside a disposable php:<version>-cli container (--privileged, for the private mount namespace).
set -eu
# xargs reports a failed lint; find -exec with \; always exits 0
find zfs.master tests \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
php_bin=$(command -v php)
# zip and unzip: the diagnostics tests pack a real zip, as the plugin does on Unraid, and open it.
if ! command -v rsync >/dev/null || ! command -v zip >/dev/null || ! command -v unzip >/dev/null; then sh tests/ci_apt.sh rsync zip unzip; fi
# pcntl: Unraid's PHP has it, and diagnostics_test.php's signal cases need it; php:<version>-cli builds it from its own source.
pcntl_loads() { php -n -d extension=pcntl -r 'exit(function_exists("pcntl_async_signals") ? 0 : 1);' >/dev/null 2>&1; }
if ! pcntl_loads; then docker-php-ext-install pcntl >/dev/null && pcntl_loads || { echo 'pcntl could not be built, and the signal cases need it' >&2; exit 1; }; fi
export ZFSM_TEST_PCNTL=pcntl
unshare --mount --propagation private sh tests/run_http_inventory_tests.sh "$php_bin"
echo "PHP $(php -r 'echo PHP_VERSION;'): lint and backend tests passed"
