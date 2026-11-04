#!/usr/bin/env python3
"""Compare the bundled libraries with the sources vendor.json names, or copy them in: vendor.py check | sync."""
import argparse
import base64
import hashlib
import io
import json
import re
import sys
import tarfile
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
# Unraid's own pages call swal(); the library's default exports would replace it on the shared Main tab.
SWAL_EXPORTS = re.compile(rb'this\.swal\s*=\s*this\.sweetAlert\s*=\s*this\.Swal\s*=\s*this\.SweetAlert\s*=')
# :where() gives rules no weight, so Unraid's theme would restyle the dialogs: Destroy and Cancel end up identical.
ZERO_WEIGHT = re.compile(rb':where\(([^()]*)\)')
# Past releases played audio and blocked the page on some hosts. Refuse anything like it rather than ship it.
REFUSED = re.compile(rb"anthem|\.mp3|xn--p1ai|navigator\.language|createElement\(['\"]audio", re.IGNORECASE)


def get(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'zfs.master-vendor'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def from_npm(lib):
    name, version = lib['npm'], lib['version']
    info = json.loads(get(f'https://registry.npmjs.org/{urllib.parse.quote(name, safe="@")}/{urllib.parse.quote(version)}'))
    tarball = get(info['dist']['tarball'])
    algo, digest = info['dist']['integrity'].split('-', 1)
    if base64.b64encode(hashlib.new(algo, tarball).digest()).decode() != digest:
        raise SystemExit(f'{name}@{version}: the tarball does not match the registry integrity')
    with tarfile.open(fileobj=io.BytesIO(tarball)) as tar:
        members = {m.name.split('/', 1)[1]: m for m in tar.getmembers() if m.isfile() and '/' in m.name}
        missing = [src for src in lib['files'] if src not in members]
        if missing:
            raise SystemExit(f'{name}@{version} has no {", ".join(missing)}')
        return {src: tar.extractfile(members[src]).read() for src in lib['files']}


def prepare(name, src, data):
    if REFUSED.search(data):
        raise SystemExit(f'{name}: {src} contains code this plugin refuses to bundle: {REFUSED.search(data).group().decode()}')
    if name == 'sweetalert2' and src.endswith('.js'):
        data, count = SWAL_EXPORTS.subn(b'this.Swal2=this.SweetAlert=', data)
        if count != 1:
            raise SystemExit(f'{name}: expected one global export line in {src}, found {count}')
    if name == 'sweetalert2':
        data = ZERO_WEIGHT.sub(rb'\1', data)
        if b':where(' in data:
            raise SystemExit(f'{name}: {src} has a nested :where() this script cannot give weight to')
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('check', 'sync'))
    mode = parser.parse_args().mode
    manifest = json.loads((ROOT / 'vendor.json').read_text())
    root = (ROOT / manifest['root']).resolve()
    stale = []
    for lib in manifest['libraries']:
        label = f'{lib["npm"]}@{lib["version"]}'
        for src, data in from_npm(lib).items():
            dest = (root / lib['files'][src]).resolve()
            if not dest.is_relative_to(root):
                raise SystemExit(f'vendor.json: {lib["files"][src]} is outside {manifest["root"]}')
            data = prepare(lib['npm'], src, data)
            if dest.is_file() and dest.read_bytes() == data:
                continue
            stale.append(dest.relative_to(ROOT))
            if mode == 'sync':
                dest.write_bytes(data)
                print(f'{label}: updated {dest.relative_to(ROOT)}')
    if mode == 'check' and stale:
        raise SystemExit('Bundled files differ from vendor.json: ' + ', '.join(map(str, stale)))
    if mode == 'check':
        print('Bundled libraries match vendor.json')


if __name__ == '__main__':
    sys.exit(main())
