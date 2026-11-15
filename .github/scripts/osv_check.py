"""The bundled libraries (vendor.json) and the locked test packages (tests/package-lock.json) against OSV's live advisories."""
import json
import sys
import urllib.request
from pathlib import Path

OSV = 'https://api.osv.dev/v1/querybatch'


def packages(root):
    """Each npm (name, version) the repo bundles or installs."""
    found = [(lib['npm'], lib['version']) for lib in json.loads((root / 'vendor.json').read_text())['libraries']]
    for path, meta in json.loads((root / 'tests/package-lock.json').read_text())['packages'].items():
        if path:
            found.append((path.rsplit('node_modules/', 1)[-1], meta['version']))
    return sorted(set(found))


def advisories(pairs, post):
    """Each pair an advisory affects, with the advisories' ids; post(body) is OSV's answer to the batch."""
    queries = [{'package': {'ecosystem': 'npm', 'name': name}, 'version': version} for name, version in pairs]
    results = json.loads(post(json.dumps({'queries': queries}).encode()))['results']
    if len(results) != len(pairs):
        raise ValueError(f'OSV answered {len(results)} of {len(pairs)} queries')
    return [(name, version, [vuln['id'] for vuln in result['vulns']]) for (name, version), result in zip(pairs, results) if result.get('vulns')]


def post(body):
    request = urllib.request.Request(OSV, data=body, headers={'Content-Type': 'application/json', 'User-Agent': 'zfs.master-osv-check'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def main(root):
    pairs = packages(root)
    try:
        hits = advisories(pairs, post)
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        raise SystemExit(f'OSV could not be read, so nothing was checked: {error}')
    for name, version, ids in hits:
        print(f'{name}@{version}: {", ".join(ids)}')
    if hits:
        raise SystemExit(f'{len(hits)} package(s) have an advisory on OSV: update them')
    print(f'OSV: no advisory for any of {len(pairs)} packages')


if __name__ == '__main__':
    main(Path(sys.argv[1] if len(sys.argv) > 1 else '.'))
