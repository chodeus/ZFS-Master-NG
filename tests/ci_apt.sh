#!/bin/sh
# ci_apt.sh <package>...: installs what a test's disposable container lacks.
apt-get update -qq
# The security pool of an end-of-life image can drop packages its index still lists.
apt-get install -y -qq "$@" >/dev/null 2>&1 || {
	sed -i '/security/d' /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null || true
	apt-get update -qq
	apt-get install -y -qq "$@" >/dev/null
}
