#!/bin/bash
set -euo pipefail

# Usage: pkg_build.sh --version YYYY.MM.DD[.N] [--branch NAME] [--out DIR]
# The release workflow owns version numbering; local test builds pass any version explicitly.
# Capability checks, not uname: a Mac can have GNU sed and tar first on PATH
md5_of() { if command -v md5sum >/dev/null; then md5sum "$1" | awk '{print $1}'; else md5 -q "$1"; fi; }
make_tar() {
    if tar --version 2>/dev/null | grep -q GNU; then
        tar --owner=0 --group=0 --no-xattrs -cJf "$1" usr
    else
        COPYFILE_DISABLE=1 tar --uid 0 --gid 0 --uname root --gname root -cJf "$1" usr
    fi
}
rewrite_plg() { sed "$1" "$plg" > "$plg.tmp" && mv "$plg.tmp" "$plg"; }

root=$(cd "$(dirname "$0")" && pwd)
plg="$root/zfs.master.plg"
out="$root/dist"
version=""
branch=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) version="$2"; shift 2 ;;
        --branch)  branch="$2";  shift 2 ;;
        --out)     out="$2";     shift 2 ;;
        *) echo "ERROR: unknown option '$1'"; exit 1 ;;
    esac
done
[ -n "$version" ] || { echo "ERROR: --version is required (e.g. --version 0000.00.00 for a test build)"; exit 1; }
# Only a guessed branch falls back: a build asked for a branch keeps that branch's update check.
if [ -z "$branch" ]; then
    branch=$(git -C "$root" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)
    case "$branch" in main|beta) ;; *) echo "Warning: branch '$branch' is not a channel, pointing pluginURL at main"; branch=main ;; esac
fi
[[ "$branch" =~ ^[A-Za-z0-9._/+-]+$ ]] || { echo "ERROR: branch '$branch' cannot go in pluginURL"; exit 1; }

mkdir -p "$out"
out=$(cd "$out" && pwd)
filename="$out/zfs.master-$version-x86_64-1.txz"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
dest="$work/usr/local/emhttp/plugins/zfs.master"
mkdir -p "$dest"
cp -R "$root/zfs.master/." "$dest/"
filecount=$(find "$work" -type f | wc -l | tr -d ' ')
[ "$filecount" -ge 10 ] || { echo "ERROR: only $filecount files copied to the package (expected at least 10)"; exit 1; }
# Every packaged file with its md5, so an install can be checked against the release; after the count above, and written
# outside $dest, so it is never counted and never lists itself.
(cd "$dest" && find . -type f | LC_ALL=C sort | while IFS= read -r file; do sum=$(md5_of "$file") && [ -n "$sum" ] || exit 1; printf '%s  %s\n' "$sum" "${file#./}"; done) > "$work/manifest.md5"
mv "$work/manifest.md5" "$dest/manifest.md5"
find "$work" -type d -exec chmod 0755 {} +
find "$work" -type f -exec chmod 0644 {} +
# Cron and the Settings page (#command) run these directly.
chmod 0755 "$dest"/scripts/*
xattr -cr "$work" 2>/dev/null || true
# Refreshes autov() URLs so browsers fetch the new assets
find "$work" -type f -exec touch {} +
(cd "$work" && make_tar "$filename")

[ "$(wc -c < "$filename" | tr -d ' ')" -ge 1000 ] || { echo "ERROR: package is suspiciously small"; rm -f "$filename"; exit 1; }
md5=$(md5_of "$filename")

rewrite_plg "s/<!ENTITY version \"[^\"]*\">/<!ENTITY version \"$version\">/"
rewrite_plg "s/<!ENTITY md5 \"[^\"]*\">/<!ENTITY md5 \"$md5\">/"
rewrite_plg "s|&github;/[^&]*/&name;.plg|\&github;/$branch/\&name;.plg|"
grep -q "&github;/$branch/&name;.plg" "$plg" || { echo "ERROR: pluginURL not written to the manifest"; exit 1; }
grep -q "<!ENTITY version \"$version\">" "$plg" || { echo "ERROR: version not written to the manifest"; exit 1; }
grep -q "<!ENTITY md5 \"$md5\">" "$plg" || { echo "ERROR: md5 not written to the manifest"; exit 1; }

echo "Package: $filename"
echo "Version: $version  MD5: $md5  Branch: $branch"
