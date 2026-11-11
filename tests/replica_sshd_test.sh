#!/bin/sh
# Sending to a real sshd on this container's loopback, through the line Settings shows for it (tests/replica_sshd_test.php).
# Only in a throwaway container (tests/ci_php.sh), which gives the checker's #! its /usr/bin/php. Usage: unshare --mount --propagation private sh tests/replica_sshd_test.sh /path/to/php
set -eu
if [ "$(readlink /proc/self/ns/mnt)" = "$(readlink /proc/1/ns/mnt)" ]; then
    echo 'Refusing to run outside a private mount namespace' >&2
    exit 1
fi
zfsm_php=$1
zfsm_repo=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
[ -x /usr/bin/php ] && [ -x /usr/sbin/sshd ] || { echo 'FAILED: needs /usr/bin/php and /usr/sbin/sshd, as tests/ci_php.sh gives them' >&2; exit 1; }
mount -t tmpfs tmpfs /tmp
mkdir -p /usr/local/emhttp /usr/local/sbin /run/sshd
mount -t tmpfs tmpfs /usr/local/emhttp
mount -t tmpfs tmpfs /usr/local/sbin
w=/tmp/zfsm-sshd
mkdir -p "$w/pool" "$w/bin" /usr/local/emhttp/webGui/include /usr/local/emhttp/plugins
# Unraid's layout for the checker, whose docroot is its own path's; the Helpers fixture reads replica_accept beside it.
ln -s "$zfsm_repo/zfs.master" /usr/local/emhttp/plugins/zfs.master
ln -s "$zfsm_repo/tests/fixtures/unraid/Helpers.php" /usr/local/emhttp/webGui/include/Helpers.php
printf 'backup/replicas' > /usr/local/emhttp/zfsm-replica-accept
# sshd and the checker scrub the environment and fix PATH: the fake zfs found there works on this test's pool.
printf '#!/bin/sh\nZFSM_TEST_REPL_DIR=%s/pool exec sh %s/tests/fixtures/bin/zfs "$@"\n' "$w" "$zfsm_repo" > /usr/local/sbin/zfs
chmod 755 /usr/local/sbin/zfs
cp "$zfsm_repo/tests/fixtures/bin/zfs" "$zfsm_repo/tests/fixtures/bin/zpool" "$w/bin/"
chmod +x "$w/bin/zfs" "$w/bin/zpool"
ln -s "$(command -v ssh)" "$w/bin/ssh"
ssh-keygen -q -t ed25519 -N '' -f "$w/host_key"
cat > "$w/sshd_config" <<EOF
Port 2222
ListenAddress 127.0.0.1
HostKey $w/host_key
AuthorizedKeysFile $w/authorized_keys
PidFile $w/sshd.pid
StrictModes no
UsePAM no
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
LogLevel VERBOSE
EOF
touch "$w/authorized_keys"
/usr/sbin/sshd -f "$w/sshd_config" -E "$w/sshd.log"
trap 'kill "$(cat "$w/sshd.pid")" 2>/dev/null || true' EXIT
ZFSM_TEST_WORK=$w ZFSM_TEST_BIN_DIR=$w/bin ZFSM_TEST_REPL_DIR=$w/pool ZFSM_TEST_JOB_DIR=$w/state ZFSM_TEST_SSH_DIR=$w/flash ZFSM_TEST_IDENT_FILE=$w/ident.cfg \
    timeout 300 "$zfsm_php" -n "$zfsm_repo/tests/replica_sshd_test.php"
