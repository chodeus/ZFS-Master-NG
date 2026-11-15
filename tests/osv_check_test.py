"""osv_check.py: what it asks OSV about, and that only a full answer with no advisory past those accepted passes."""
import importlib.util
import json
import shutil
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('osv_check', root / '.github/scripts/osv_check.py')
osv = importlib.util.module_from_spec(spec)
spec.loader.exec_module(osv)
work = Path(tempfile.mkdtemp())
for name in ('vendor.json', 'tests/package-lock.json', '.github/osv-accepted.json'):
    (work / name).parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(root / name, work / name)


def stopped(answer, accepting=None):
    (work / '.github/osv-accepted.json').write_text(json.dumps({'accepted': {}} if accepting is None else accepting))
    osv.post = answer
    try:
        osv.main(work)
    except SystemExit as error:
        return str(error)
    return None


libraries = {(lib['npm'], lib['version']) for lib in json.loads((root / 'vendor.json').read_text())['libraries']}
locked = {(path[path.rindex('node_modules/') + len('node_modules/'):], meta['version']) for path, meta in json.loads((root / 'tests/package-lock.json').read_text())['packages'].items() if path}
pairs = osv.packages(root)
assert pairs == sorted(libraries | locked), 'each bundled library and locked package once, by its name, and not the lock\'s own entry'
assert set(json.loads((root / 'tests/package.json').read_text())['devDependencies'].items()) <= set(pairs), 'the test packages are among them'
assert isinstance(osv.accepted(root), dict), 'the repo\'s accept list reads, with a why for each advisory it accepts'

asked = []
clean = lambda body: asked.append(json.loads(body)) or json.dumps({'results': [{} for query in json.loads(body)['queries']]}).encode()
assert stopped(clean) is None and asked[0]['queries'][0] == {'package': {'ecosystem': 'npm', 'name': pairs[0][0]}, 'version': pairs[0][1]}, 'no advisory passes'
hit = lambda body: json.dumps({'results': [{'vulns': [{'id': 'GHSA-xxxx-yyyy-zzzz', 'modified': '2026-11-01T00:00:00Z'}]}] + [{}] * (len(pairs) - 1)}).encode()
assert 'does not accept' in (stopped(hit) or ''), 'an advisory stops it'
assert osv.advisories(pairs, hit) == [(pairs[0][0], pairs[0][1], ['GHSA-xxxx-yyyy-zzzz'])], 'and names the package and the advisory'
assert stopped(hit, {'accepted': {'GHSA-xxxx-yyyy-zzzz': 'test-only package with no fix'}}) is None, 'one the accept list names with why passes'
assert 'does not accept' in (stopped(hit, {'accepted': {'GHSA-aaaa-bbbb-cccc': 'another'}}) or ''), 'one it does not name still stops it'
for broken in ({'accepted': {'GHSA-xxxx-yyyy-zzzz': ''}}, {'accepted': ['GHSA-xxxx-yyyy-zzzz']}, {}):
    assert 'osv-accepted.json could not be read' in (stopped(hit, broken) or ''), 'an accept list without a why for each id is no pass: ' + json.dumps(broken)
(work / '.github/osv-accepted.json').unlink()
osv.post = hit
try:
    osv.main(work)
    missing = None
except SystemExit as error:
    missing = str(error)
assert 'osv-accepted.json could not be read' in (missing or ''), 'nor is a missing one'
short = lambda body: json.dumps({'results': [{}]}).encode()
assert 'OSV could not be read' in (stopped(short) or ''), 'an answer to fewer queries than asked is no pass'
for page in ({'next_page_token': 'more'}, {'vulns': [{'id': 'GHSA-xxxx-yyyy-zzzz'}], 'next_page_token': 'more'}):
    paged = lambda body: json.dumps({'results': [page] + [{}] * (len(pairs) - 1)}).encode()
    assert 'only in part' in (stopped(paged, {'accepted': {'GHSA-xxxx-yyyy-zzzz': 'test-only package with no fix'}}) or ''), 'nor is a paged answer: ' + json.dumps(page)


def down(body):
    raise OSError('Network is unreachable')


assert 'OSV could not be read' in (stopped(down) or ''), 'nor is OSV being down'
assert 'OSV could not be read' in (stopped(lambda body: b'<html>busy</html>') or ''), 'nor is an answer that is not OSV\'s'
shutil.rmtree(work)
print('osv check tests passed')
