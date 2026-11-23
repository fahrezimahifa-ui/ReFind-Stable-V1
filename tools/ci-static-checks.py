"""Static checks for CI (.github/workflows/ci.yml); they need no database, web server or private state.

1. Asset provenance (docs/SOURCE_PROVENANCE.json): every native file under source_files stays byte-identical to native,
   except the adapted assets, which must match their recorded hash and so need a provenance update when they change.
   Laravel-only front-end files listed under added_assets must match their recorded hash too.
2. Python syntax: every tracked .py file parses.

Hashes are taken from committed blobs (HEAD), so a Windows working copy's line endings don't matter; commit first.
    python tools/ci-static-checks.py
"""
import ast, hashlib, json, subprocess, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def git(*args):
    return subprocess.run(['git', '-C', str(ROOT), *args], capture_output=True, check=True).stdout


def blob(path):
    return git('cat-file', 'blob', 'HEAD:' + path)


problems = []
provenance = json.loads(blob('docs/SOURCE_PROVENANCE.json'))
native, adapted = provenance['source_files'], provenance['adapted_assets']
for name in sorted(set(native) | set(adapted)):
    expected = adapted[name]['sha256'] if name in adapted else native[name]
    try:
        actual = hashlib.sha256(blob('public/' + name)).hexdigest()
    except subprocess.CalledProcessError:
        problems.append('public/%s is listed in SOURCE_PROVENANCE.json but not committed' % name)
        continue
    if actual != expected:
        kind = 'adapted asset (update its sha256 and reason)' if name in adapted else 'pinned native asset (must stay identical)'
        problems.append('public/%s differs from SOURCE_PROVENANCE.json: %s' % (name, kind))
added = provenance.get('added_assets', {})  # Laravel-only front-end files (not in native), recorded the same way
for name in sorted(added):
    if name in native:
        problems.append('public/%s is a native file; record it under adapted_assets, not added_assets' % name)
        continue
    try:
        actual = hashlib.sha256(blob('public/' + name)).hexdigest()
    except subprocess.CalledProcessError:
        problems.append('public/%s is listed in SOURCE_PROVENANCE.json but not committed' % name)
        continue
    if actual != added[name]['sha256']:
        problems.append('public/%s differs from SOURCE_PROVENANCE.json: added asset (update its sha256 and reason)' % name)
print('provenance: %d pinned native files, %d adapted assets, %d added assets' % (len(set(native) - set(adapted)), len(adapted), len(added)))

python_files = [p for p in git('ls-files', '-z', '--', '*.py').decode('utf-8').split('\0') if p]
for path in python_files:
    try:
        ast.parse(blob(path), filename=path)
    except SyntaxError as error:
        problems.append('Python syntax: %s:%s %s' % (path, error.lineno, error.msg))
print('python syntax: %d files' % len(python_files))

for problem in problems:
    print('FAIL ' + problem)
sys.exit(1 if problems else 0)
