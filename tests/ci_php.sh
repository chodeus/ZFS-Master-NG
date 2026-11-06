#!/bin/sh
# Runs inside a disposable php:<version>-cli container (--privileged, for the private mount namespace).
set -eu
# xargs reports a failed lint; find -exec with \; always exits 0
find zfs.master tests \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
php_bin=$(command -v php)
if ! command -v rsync >/dev/null; then
	apt-get update -qq
	# The security pool of an end-of-life image can drop packages its index still lists.
	apt-get install -y -qq rsync >/dev/null 2>&1 || {
		sed -i '/security/d' /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null || true
		apt-get update -qq
		apt-get install -y -qq rsync >/dev/null
	}
fi
unshare --mount --propagation private sh tests/run_http_inventory_tests.sh "$php_bin"
echo "PHP $(php -r 'echo PHP_VERSION;'): lint and backend tests passed"
