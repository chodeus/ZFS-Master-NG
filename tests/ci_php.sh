#!/bin/sh
# Runs inside a disposable php:<version>-cli container (--privileged, for the private mount namespace).
set -eu
# xargs reports a failed lint; find -exec with \; always exits 0
find zfs.master tests \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
php_bin=$(command -v php)
# zip and unzip: the diagnostics tests pack a real zip, as the plugin does on Unraid, and open it.
# openssh-client: replica_remote_test.php runs the real ssh-keygen and ssh -G, replicate_test.php the real ssh-keygen.
# zstd: replicate_test.php and replica_serve_test.php send streams through the real zstd, as Unraid has it; xz makes the one it must not read.
if ! command -v rsync >/dev/null || ! command -v zip >/dev/null || ! command -v unzip >/dev/null || ! command -v ssh-keygen >/dev/null || ! command -v zstd >/dev/null || ! command -v xz >/dev/null; then sh tests/ci_apt.sh rsync zip unzip openssh-client zstd xz-utils; fi
# pcntl: Unraid's PHP has it, and diagnostics_test.php's signal cases need it; php:<version>-cli builds it from its own source.
pcntl_loads() { "$php_bin" -n -d extension=pcntl -r 'exit(function_exists("pcntl_async_signals") ? 0 : 1);'; }
if ! pcntl_loads >/dev/null 2>&1; then
	docker-php-ext-install -j"$(nproc)" pcntl >/dev/null || { echo 'pcntl could not be built, and the signal cases need it' >&2; exit 1; }
	pcntl_loads || { echo 'pcntl was built but does not load, and the signal cases need it' >&2; exit 1; }
fi
export ZFSM_TEST_PCNTL=pcntl
unshare --mount --propagation private sh tests/run_http_inventory_tests.sh "$php_bin"
echo "PHP $(php -r 'echo PHP_VERSION;'): lint and backend tests passed"
