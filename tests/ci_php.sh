#!/bin/sh
# Runs inside a disposable php:<version>-cli container (--privileged, for the private mount namespace).
set -eu
# xargs reports a failed lint; find -exec with \; always exits 0
find zfs.master tests \( -name '*.php' -o -name '*.page' \) -print0 | xargs -0 -n1 php -l
php_bin=$(command -v php)
# replica_remote_test.php runs the real ssh-keygen and ssh -G; replica_sshd_test.sh a real sshd, and ip for the line's from=.
missing=''
command -v rsync >/dev/null || missing="$missing rsync"
command -v ssh-keygen >/dev/null || missing="$missing openssh-client"
[ -x /usr/sbin/sshd ] || missing="$missing openssh-server"
command -v ip >/dev/null || missing="$missing iproute2"
if [ -n "$missing" ]; then
	apt-get update -qq
	# The security pool of an end-of-life image can drop packages its index still lists.
	apt-get install -y -qq $missing >/dev/null 2>&1 || {
		sed -i '/security/d' /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null || true
		apt-get update -qq
		apt-get install -y -qq $missing >/dev/null
	}
fi
unshare --mount --propagation private sh tests/run_http_inventory_tests.sh "$php_bin"
# The checker's #! is Unraid's /usr/bin/php; this throwaway container has PHP elsewhere.
[ -e /usr/bin/php ] || ln -s "$php_bin" /usr/bin/php
unshare --mount --propagation private sh tests/replica_sshd_test.sh "$php_bin"
echo "PHP $(php -r 'echo PHP_VERSION;'): lint and backend tests passed"
