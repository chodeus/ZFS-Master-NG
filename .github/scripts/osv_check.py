"""The bundled libraries (vendor.json) and the locked test packages (tests/package-lock.json) against OSV's live advisories;
an advisory passes only when .github/osv-accepted.json accepts it, with why."""
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


def accepted(root):
    """Each accepted advisory's id, with why it is accepted."""
    found = json.loads((root / '.github/osv-accepted.json').read_text())['accepted']
    if not isinstance(found, dict) or not all(isinstance(why, str) and why for why in found.values()):
        raise ValueError('accepted must map each advisory id to why')
    return found


def advisories(pairs, post):
    """Each pair an advisory affects, with the advisories' ids; post(body) is OSV's answer to the batch."""
    queries = [{'package': {'ecosystem': 'npm', 'name': name}, 'version': version} for name, version in pairs]
    results = json.loads(post(json.dumps({'queries': queries}).encode()))['results']
    if len(results) != len(pairs):
        raise ValueError(f'OSV answered {len(results)} of {len(pairs)} queries')
    paged = sum(1 for result in results if result.get('next_page_token'))
    if paged:
        raise ValueError(f'OSV answered {paged} queries only in part (next_page_token)')
    return [(name, version, [vuln['id'] for vuln in result['vulns']]) for (name, version), result in zip(pairs, results) if result.get('vulns')]


def post(body):
    request = urllib.request.Request(OSV, data=body, headers={'Content-Type': 'application/json', 'User-Agent': 'zfs.master-osv-check'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def main(root):
    pairs = packages(root)
    try:
        allowed = accepted(root)
    except (OSError, ValueError, KeyError) as error:
        raise SystemExit(f'.github/osv-accepted.json could not be read, so nothing was checked: {error}')
    try:
        hits = advisories(pairs, post)
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        raise SystemExit(f'OSV could not be read, so nothing was checked: {error}')
    for name, version, ids in hits:
        print(f'{name}@{version}: ' + ', '.join(f'{id} (accepted: {allowed[id]})' if id in allowed else id for id in ids))
    unaccepted = [name for name, version, ids in hits if set(ids) - set(allowed)]
    if unaccepted:
        raise SystemExit(f'{len(unaccepted)} package(s) have an advisory on OSV that .github/osv-accepted.json does not accept: update them, or accept it there with why')
    print(f'OSV: no advisory past those accepted for any of {len(pairs)} packages')


if __name__ == '__main__':
    main(Path(sys.argv[1] if len(sys.argv) > 1 else '.'))
