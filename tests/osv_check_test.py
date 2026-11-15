"""osv_check.py: what it asks OSV about, and that only a full answer with no advisory passes."""
import importlib.util
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('osv_check', root / '.github/scripts/osv_check.py')
osv = importlib.util.module_from_spec(spec)
spec.loader.exec_module(osv)


def stopped(answer):
    osv.post = answer
    try:
        osv.main(root)
    except SystemExit as error:
        return str(error)
    return None


bundled = json.loads((root / 'vendor.json').read_text())['libraries'][0]
pairs = osv.packages(root)
assert ('sweetalert2', bundled['version']) in pairs and ('jsdom', '31.0.1') in pairs, 'the bundled library and the test packages are asked about'
locked = [path for path in json.loads((root / 'tests/package-lock.json').read_text())['packages'] if path]
assert len(pairs) == 1 + len(locked), 'the bundled library and each locked package once, and the lock\'s own entry not at all'
assert not [name for name, version in pairs if 'node_modules' in name], 'a package is its name, not its node_modules path'

asked = []
clean = lambda body: asked.append(json.loads(body)) or json.dumps({'results': [{} for query in json.loads(body)['queries']]}).encode()
assert stopped(clean) is None and asked[0]['queries'][0] == {'package': {'ecosystem': 'npm', 'name': pairs[0][0]}, 'version': pairs[0][1]}, 'no advisory passes'
hit = lambda body: json.dumps({'results': [{'vulns': [{'id': 'GHSA-xxxx-yyyy-zzzz', 'modified': '2026-11-01T00:00:00Z'}]}] + [{}] * (len(pairs) - 1)}).encode()
assert 'have an advisory' in (stopped(hit) or ''), 'an advisory stops it'
assert osv.advisories(pairs, hit) == [(pairs[0][0], pairs[0][1], ['GHSA-xxxx-yyyy-zzzz'])], 'and names the package and the advisory'
short = lambda body: json.dumps({'results': [{}]}).encode()
assert 'could not be read' in (stopped(short) or ''), 'an answer to fewer queries than asked is no pass'


def down(body):
    raise OSError('Network is unreachable')


assert 'could not be read' in (stopped(down) or ''), 'nor is OSV being down'
assert 'could not be read' in (stopped(lambda body: b'<html>busy</html>') or ''), 'nor is an answer that is not OSV\'s'
print('osv check tests passed')
