#!/bin/bash
# Runs this plugin through a real Unraid webGUI tree: its plugin installer, CSRF prepend, helpers and pages.
# Disposable container only, as root. Usage: unraid_compat_test.sh <webgui-tree> <unraid-version>
set -u
# This script replaces plugin files and system binaries, so it must never touch a real server.
if [ -e /etc/unraid-version ] || [ -d /boot/config ]; then echo 'Refusing to run on an Unraid system' >&2; exit 2; fi
web=$(cd "$1" && pwd); version=$2; repo=$(pwd)
fail() { echo "FAILED [$version]: $*"; exit 1; }
command -v xz >/dev/null || { apt-get update -qq && apt-get install -y -qq xz-utils >/dev/null; } || fail 'xz unavailable'

docroot=/usr/local/emhttp
flash=/boot/config/plugins/zfs.master
live=$docroot/plugins/zfs.master
mkdir -p "$docroot/plugins" /boot/config/plugins /boot/config/plugins-error /var/log/plugins /var/log/packages /var/run /usr/bin
# Tags before 6.12 keep plugins/ at the top of the tree.
plugins="$web/emhttp/plugins"; [ -d "$plugins" ] || plugins="$web/plugins"
cp -R "$plugins/dynamix" "$plugins/dynamix.plugin.manager" "$docroot/plugins/"
mkdir -p /var/local/emhttp; printf 'csrf_token="compat-test-token"\n' > /var/local/emhttp/var.ini
ln -sfn "$docroot/plugins/dynamix" "$docroot/webGui"
rm -rf "$docroot/plugins/dynamix.plugin.manager/post-hooks" "$docroot/plugins/dynamix.plugin.manager/pre-hooks"
ln -sf "$(command -v php)" /usr/bin/php
plugin="php $docroot/plugins/dynamix.plugin.manager/scripts/plugin"

# Slackware's package tools; everything else is real.
cat > /sbin/upgradepkg <<'STUB'
#!/bin/bash
for pkg; do :; done
tar -xJf "$pkg" -C / || exit 1
touch "/var/log/packages/$(basename "$pkg" .txz)"
STUB
cat > /sbin/removepkg <<'STUB'
#!/bin/bash
rm -rf /usr/local/emhttp/plugins/zfs.master
rm -f "/var/log/packages/$1"
STUB
chmod +x /sbin/upgradepkg /sbin/removepkg

work=$(mktemp -d)
cp -R zfs.master zfs.master.plg pkg_build.sh "$work/"
(cd "$work" && bash pkg_build.sh --version 0000.00.00 --branch beta --out "$work/dist" >/dev/null) || fail 'package build'
package=zfs.master-0000.00.00-x86_64-1.txz
set_version() { printf 'version="%s"\n' "$1" > /etc/unraid-version; }
reset() { rm -rf "$flash" "$live" /var/log/plugins/zfs.master.plg /boot/config/plugins/zfs.master.plg /var/log/packages/zfs.master-* /tmp/zfsm_reload; mkdir -p "$flash"; }
legacy() {
	mkdir -p "$live/nchan" "$live/scripts"
	touch "$live/nchan/zfs_master" "$live/scripts/zfs_pool_get_datasets.lua" "$flash/zfs.master-2024.12.09.104.tgz" /tmp/zfsm_reload
	printf 'exclusion = "/docker%%-old/.*"\n' > "$flash/zfs.master.cfg"
	printf 'webGui/nchan/notify_poller\nplugins/zfs.master/nchan/zfs_master\nplugins/zfs.master/nchan/zfs_master:stop\nplugins/other/nchan/zfs_master:1\n' > /var/run/nchan.pid
}
set_version "$version"

# Upgrade over an old tar install, using the installer's own md5 check on the flash copy.
reset; legacy; cp "$work/dist/$package" "$flash/"
$plugin install "$work/zfs.master.plg" > "$work/install.log" 2>&1 || { cat "$work/install.log"; fail 'install refused'; }
[ -f "$live/backend/ZFSMAdmin.php" ] || fail 'package files not installed'
[ -L /var/log/plugins/zfs.master.plg ] || fail 'plugin not registered'
[ ! -e "$live/nchan" ] && [ ! -e "$live/scripts" ] || fail 'old daemon or Lua files left behind'
[ ! -e "$flash/zfs.master-2024.12.09.104.tgz" ] || fail 'old archive left on the flash'
[ -f "$flash/$package" ] || fail 'current archive removed from the flash'
grep -q 'docker%-old' "$flash/zfs.master.cfg" || fail 'settings lost on upgrade'
grep -q 'plugins/zfs.master/nchan/zfs_master' /var/run/nchan.pid && fail 'old daemon still in nchan.pid'
grep -q 'plugins/other/nchan/zfs_master:1' /var/run/nchan.pid || fail 'another plugin removed from nchan.pid'

# Real plugin removal.
$plugin remove zfs.master.plg > "$work/remove.log" 2>&1 || { cat "$work/remove.log"; fail 'remove failed'; }
[ ! -e "$live" ] && [ ! -e "$flash" ] && [ ! -e /var/log/plugins/zfs.master.plg ] || fail 'remove left files behind'
ls /var/log/packages/zfs.master-* >/dev/null 2>&1 && fail 'remove left a package record'

# A download that fails must leave the old install and the archive its next boot needs.
reset; legacy; cp "$live/nchan/zfs_master" "$work/old-file"
$plugin install "$work/zfs.master.plg" > "$work/failed.log" 2>&1 && fail 'install succeeded without its package'
[ -f "$flash/zfs.master-2024.12.09.104.tgz" ] || fail 'failed download deleted the archive the next boot needs'
[ -f "$live/nchan/zfs_master" ] || fail 'failed download changed the running install'
grep -q 'plugins/zfs.master/nchan/zfs_master' /var/run/nchan.pid || fail 'failed download changed nchan.pid'

# A failed upgradepkg must also leave the old install, its archive and its registration.
build_version() {
	local dir="$work/v$1"; mkdir -p "$dir"; cp -R "$repo/zfs.master" "$repo/zfs.master.plg" "$repo/pkg_build.sh" "$dir/"
	(cd "$dir" && bash pkg_build.sh --version "$1" --branch beta --out "$dir/dist" >/dev/null) || fail "build $1"
}
register_old() {
	cp "$repo/tests/fixtures/installed_old.plg" /boot/config/plugins/zfs.master.plg
	ln -sfn /boot/config/plugins/zfs.master.plg /var/log/plugins/zfs.master.plg
}
build_version 2026.11.01; build_version 2026.11.02
first=zfs.master-2026.11.01-x86_64-1; second=zfs.master-2026.11.02-x86_64-1
reset; legacy; register_old; cp "$work/v2026.11.01/dist/$first.txz" "$flash/"
cp /sbin/upgradepkg "$work/upgradepkg"; printf '#!/bin/bash\nexit 1\n' > /sbin/upgradepkg
$plugin install "$work/v2026.11.01/zfs.master.plg" > "$work/broken.log" 2>&1 && fail 'install reported success though upgradepkg failed'
cp "$work/upgradepkg" /sbin/upgradepkg
[ -f "$flash/zfs.master-2024.12.09.104.tgz" ] || fail 'failed upgradepkg deleted the old archive'
[ -e "$live/nchan/zfs_master" ] || fail 'failed upgradepkg removed the old files'
grep -q '2026.09.25.112' /boot/config/plugins/zfs.master.plg || fail 'failed upgradepkg replaced the installed manifest'

# Over a registered old version, then package to package, then what a reboot does.
reset; legacy; register_old; cp "$work/v2026.11.01/dist/$first.txz" "$flash/"
$plugin install "$work/v2026.11.01/zfs.master.plg" > "$work/over-old.log" 2>&1 || { cat "$work/over-old.log"; fail 'upgrade over a registered old version refused'; }
grep -q '"2026.11.01"' /boot/config/plugins/zfs.master.plg || fail 'manifest not updated on upgrade'
cp "$work/v2026.11.02/dist/$second.txz" "$flash/"
$plugin install "$work/v2026.11.02/zfs.master.plg" > "$work/next.log" 2>&1 || { cat "$work/next.log"; fail 'package upgrade refused'; }
[ ! -e "$flash/$first.txz" ] && [ ! -e "/var/log/packages/$first" ] || fail 'package upgrade left the previous version behind'
[ -f "$flash/$second.txz" ] && [ -e "/var/log/packages/$second" ] || fail 'package upgrade lost the current version'
rm -f /var/log/plugins/zfs.master.plg
$plugin install /boot/config/plugins/zfs.master.plg > "$work/boot.log" 2>&1 || { cat "$work/boot.log"; fail 'reinstall at boot failed'; }
[ -f "$flash/$second.txz" ] || fail 'reinstall at boot deleted its own archive'

# The manifest's floor is enforced by the real installer.
reset; set_version 6.8.3; cp "$work/dist/$package" "$flash/"
$plugin install "$work/zfs.master.plg" > "$work/old-os.log" 2>&1 && fail 'installed below min'
grep -qi 'version' "$work/old-os.log" || { cat "$work/old-os.log"; fail 'no reason given below min'; }
set_version "$version"

# Real CSRF prepend, helpers, backend and pages, with fake zfs and zpool binaries.
reset; cp "$work/dist/$package" "$flash/"
$plugin install "$work/zfs.master.plg" > "$work/install2.log" 2>&1 || fail 'second install'
printf '[general]\r\nrefresh_interval="300"\r\nlazy_load="0"\r\ndestructive_mode="1"\r\nexclussion="/docker%%-old/.*"\r\ndirectory_listing="cache/appdata\r\ncache/My Share"\r\nsnap_max_days_alert="7"\r\nsnap_pattern="Y-m-d"\r\nsnap_prefix="auto-"\r\n' > "$flash/zfs.master.cfg"
php -n -d display_errors=stderr "$repo/tests/unraid_compat_check.php" saved_settings "$version" || fail 'settings saved by older versions'
cp tests/fixtures/config/zfs.master.cfg "$flash/zfs.master.cfg"
cp "$repo/tests/fixtures/bin/zfs" "$repo/tests/fixtures/bin/zpool" /usr/bin/ && chmod +x /usr/bin/zfs /usr/bin/zpool
rm -f /usr/sbin/zpool
php -n -d display_errors=stderr "$repo/tests/unraid_compat_check.php" menu_order "$version" || fail 'menu without ZFS'
mkdir -p /usr/sbin; ln -sf /usr/bin/zpool /usr/sbin/zpool
for check in menu_order body_token no_token wrong_token main_page settings_page create_popup snapshot_popup time_format; do
	php -n -d display_errors=stderr "$repo/tests/unraid_compat_check.php" "$check" "$version" || fail "$check"
done
echo "Unraid $version: installer, CSRF, backend and pages passed"
