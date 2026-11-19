"""Executable verification workflow for the integrated ReFind Laravel candidate (development profile).

    python -B tools/verify-candidate.py [--profile phase8-dev] [--browser] [--skip-live]

Runs targeted gates in order and stops at the first failure: resource preflight, static checks, exact grant
audit, PHPUnit, live HTTP/concurrency suites (optionally browser), exact synthetic restore, and protection
checks. It always restores the snapshot and limiter state it took and stops the processes it started.
Results go to C:/ReFind-Laravel/evidence/phase8/candidate-<UTC>/summary.json. It never touches the accepted
academic profiles, native ReFind or XAMPP, and it does not replace the deferred acceptance gates it lists.
"""
from datetime import datetime, timezone
from pathlib import Path
import argparse, hashlib, json, os, shutil, socket, subprocess, sys, time, zipfile

ROOT = Path('C:/ReFind-Laravel'); APP = Path(__file__).resolve().parent.parent
PHP = ROOT / 'runtime/php85/php.exe'; INI = ROOT / 'runtime/php85/php.ini'
CONTROL = APP / 'tools/workspace/phase6-control.py'
EVIDENCE_6B = ROOT / 'evidence/phase6/checkpoint-6b-20261118T093432Z'
ACCEPTED_REFS = {'migration/academic-b': '7f864aa6ddd88cf6de130213143c74870253c049', 'migration/phase6-6a': 'b70b711a4246b4427f15a5eb5edd6b1daf45bc7a', 'migration/laravel13': 'ac03a9dae33c5d03318ac357fdb0c31abcaffa75'}
DEFERRED = [
    'Independent native HTTP parity for claims, BM review, approval, pickups, BM reports/status, notifications, account and registration (external comparator kit; never same-host).',
    'Full inherited 33-suite matrix plus the Phase 6-8 suites with fresh source-bound receipts (extend VerificationReceipt/verify-phase5.py beyond phase 5).',
    'Fresh-Windows install and restart qualification at the accepted SHA (external machine).',
    'Separately approved activation of the Phase 6-8 stages and grant-claims.php deltas on academic-dev/academic-test, with exact before/after grant audits.',
    'Authenticated WDD rubric mapping and academic evidence sign-off.',
    'Owner sign-off: stronger claim revision digest; native REJECTED->CONFIRMED pair conversion; notification read-marking, account and registration grants.',
]
steps = []


def now():
    return datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')


def env(profile):
    e = dict(os.environ, REFIND_PROFILE=profile, PHP_INI_SCAN_DIR=str(ROOT / 'runtime/empty-ini-scan'))
    e.pop('REFIND_PHASE6_STAGES', None)
    return e


def run(name, cmd, profile, out, timeout=900, parse_json=False):
    started = time.monotonic()
    p = subprocess.run(cmd, cwd=APP, env=env(profile), capture_output=True, text=True, timeout=timeout)
    (out / (name + '.log')).write_text(p.stdout + '\n--- stderr ---\n' + p.stderr, encoding='utf-8')
    result = {'step': name, 'exit': p.returncode, 'seconds': round(time.monotonic() - started, 1)}
    if parse_json:
        try:
            result['result'] = json.loads(p.stdout.strip().splitlines()[-1])
        except Exception:
            result['result'] = None
    ok = p.returncode == 0 and (not parse_json or (result.get('result') or {}).get('passed') is True)
    result['passed'] = ok
    steps.append(result)
    if not ok:
        raise RuntimeError(name + ' failed; see ' + str(out / (name + '.log')))
    return p.stdout


def php(script, *args):
    return [str(PHP), '-c', str(INI), '-d', 'display_errors=stderr', str(APP / script), *args]


def port_open(port):
    s = socket.socket(); s.settimeout(0.3)
    try:
        return s.connect_ex(('127.0.0.1', port)) == 0
    finally:
        s.close()


def static_checks(profile, out):
    files = sorted({str(p) for d in ('app', 'config', 'routes', 'tests', 'tools', 'bootstrap', 'database') for p in (APP / d).rglob('*.php')})
    bad = [f for f in files if subprocess.run([str(PHP), '-c', str(INI), '-l', f], capture_output=True, env=env(profile)).returncode]
    js = [f for f in ('public/js/student.js', 'public/js/bm.js', 'public/js/student-common.js', 'public/js/account-ui.js', 'public/js/registration-ui.js') if subprocess.run(['node', '--check', str(APP / f)], capture_output=True).returncode]
    py = [str(f) for f in (APP / 'tools').rglob('*.py') if subprocess.run([sys.executable, '-m', 'py_compile', str(f)], capture_output=True).returncode]
    steps.append({'step': 'static', 'php_files': len(files), 'failures': bad + js + py, 'passed': not (bad or js or py)})
    if bad or js or py:
        raise RuntimeError('static checks failed: ' + ', '.join(bad + js + py)[:400])


def protection(profile, out):
    def q(sql):
        r = subprocess.run([str(ROOT / 'runtime/mariadb/bin/mariadb.exe'), '--defaults-file=' + str(ROOT / 'state/admin-client.ini'), '-B', '-e', sql], capture_output=True, text=True, check=True)
        lines = r.stdout.splitlines()
        return [dict(zip(lines[0].split('\t'), l.split('\t'))) for l in lines[1:]] if lines else []
    canon = lambda rows: sorted(json.dumps(r, sort_keys=True) for r in rows)
    grants_ok = {}
    for prof, user in (('test', 'refind_b_test_app'), ('dev', 'refind_b_dev_app')):
        ev = json.loads((EVIDENCE_6B / 'focused-20261118T095345Z-051fc8c7' / ('grants-academic-' + prof + '-after.txt')).read_text())['grants']
        g = "'" + user + "'@'127.0.0.1'"
        live = {'global': q('SELECT PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE="' + g + '"'),
                'schema_grants': q('SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE="' + g + '"'),
                'table_grants': q('SELECT TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE="' + g + '"'),
                'column_grants': q('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE="' + g + '"')}
        grants_ok['academic-' + prof] = all(canon(ev.get(k, [])) == canon(live[k]) for k in live)
    archive = hashlib.sha256((EVIDENCE_6B / 'checkpoint-6b-candidate.zip').read_bytes()).hexdigest()
    manifest = json.loads(zipfile.ZipFile(EVIDENCE_6B / 'checkpoint-6b-candidate.zip').read('manifest.json'))
    files = {**manifest['modified'], **manifest['new']}
    canonical = sum(hashlib.sha256((ROOT / 'laravel' / p).read_bytes()).hexdigest() == d for p, d in files.items())
    refs = {r: subprocess.run(['git', '-C', str(ROOT / 'laravel'), 'rev-parse', r], capture_output=True, text=True).stdout.strip() for r in ACCEPTED_REFS}
    result = {'step': 'protection', 'academic_grants_match_6B_evidence': grants_ok, 'archive_6B': archive[:16], 'canonical_6B_files': str(canonical) + '/' + str(len(files)), 'accepted_refs': {r: refs[r] == s for r, s in ACCEPTED_REFS.items()}}
    result['passed'] = all(grants_ok.values()) and archive.startswith('c1f4bde6a2375d46') and canonical == len(files) and all(result['accepted_refs'].values())
    steps.append(result)
    if not result['passed']:
        raise RuntimeError('protection check failed: ' + json.dumps(result))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--profile', default={'laravel-p8': 'phase8-dev', 'laravel-p7': 'phase7-dev'}.get(APP.name, 'phase6-dev'))
    ap.add_argument('--browser', action='store_true'); ap.add_argument('--skip-live', action='store_true')
    a = ap.parse_args(); profile = a.profile; state = ROOT / 'state' / profile
    out = ROOT / 'evidence/phase8' / ('candidate-' + now()); out.mkdir(parents=True)
    head = subprocess.run(['git', '-C', str(APP), 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
    dirty = subprocess.run(['git', '-C', str(APP), 'status', '--porcelain=v1'], capture_output=True, text=True).stdout.strip() != ''
    db_was_up = port_open(3307)
    snapshot = auth_backup = None; ok = False
    ctl = lambda *args: run('control-' + '-'.join(args), [sys.executable, '-B', str(CONTROL), *args, '--profile', profile], profile, out, timeout=300)
    try:
        ctl('preflight'); ctl('init')
        static_checks(profile, out)
        run('grant-audit', php('tools/report-privileges.php'), profile, out)
        run('phpunit', [str(PHP), '-c', str(INI), '-d', 'error_log=' + str(state / 'php-error.log'), str(APP / 'vendor/phpunit/phpunit/phpunit'), '--configuration', str(APP / ('phpunit.' + profile.split('-')[0] + '.xml')), '--log-junit', str(out / 'phpunit.junit.xml')], profile, out, timeout=1800)
        if not a.skip_live:
            before = run('state-before', php('tools/phase6-dev.php', 'state'), profile, out)
            snapshot = json.loads(run('snapshot-save', php('tools/phase6-dev.php', 'save'), profile, out))['id']
            auth_backup = {f.name: f.read_bytes() for f in (state / 'auth').glob('*.json')}
            run('seed-students', php('tools/phase6-dev.php', 'seed-students'), profile, out)
            ctl('start'); ctl('start', '--second')
            for suite in ('test-phase6-http.py', 'test-phase7-http.py', 'test-phase8-http.py'):
                if (APP / 'tools' / suite).exists():
                    run(suite[:-3], [sys.executable, '-B', str(APP / 'tools' / suite)], profile, out, parse_json=True)
            if a.browser:
                ctl('stop'); ctl('start')
                for suite in ('test-phase6-ui.py', 'test-phase7-ui.py'):
                    run(suite[:-3], [sys.executable, '-B', str(APP / 'tools' / suite)], profile, out, parse_json=True)
            ctl('stop')
            run('snapshot-restore', php('tools/phase6-dev.php', 'restore', snapshot), profile, out); snapshot = None
            after = run('state-after', php('tools/phase6-dev.php', 'state'), profile, out)
            strip = lambda t: {k: v for k, v in json.loads(t).items() if k != 'action'}
            same = strip(before) == strip(after)
            steps.append({'step': 'restore-identical', 'passed': same})
            if not same:
                raise RuntimeError('synthetic state differs after restore')
        protection(profile, out)
        ok = True
    except Exception as e:
        steps.append({'step': 'stopped', 'error': str(e)[:600], 'passed': False})
    finally:
        try:
            subprocess.run([sys.executable, '-B', str(CONTROL), 'stop', '--profile', profile], cwd=APP, env=env(profile), capture_output=True, timeout=120)
            if snapshot:
                subprocess.run(php('tools/phase6-dev.php', 'restore', snapshot), cwd=APP, env=env(profile), capture_output=True, timeout=300)
                steps.append({'step': 'emergency-restore', 'snapshot': snapshot})
            if auth_backup is not None:
                for f in (state / 'auth').glob('*.json'):
                    if f.name not in auth_backup:
                        f.unlink()
                for name, data in auth_backup.items():
                    (state / 'auth' / name).write_bytes(data)
            (state / 'tmp/extra-credentials.json').unlink(missing_ok=True)
            if not db_was_up:
                subprocess.run([sys.executable, '-B', str(CONTROL), 'stop', '--db', '--profile', profile], cwd=APP, env=env(profile), capture_output=True, timeout=120)
        finally:
            summary = {'candidate': head, 'dirty_worktree': dirty, 'profile': profile, 'passed': ok, 'accepted': False, 'steps': steps, 'deferred_gates': DEFERRED, 'evidence_dir': str(out)}
            (out / 'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
            print(json.dumps({'passed': ok, 'accepted': False, 'evidence': str(out / 'summary.json'), 'failed_step': next((s['step'] for s in steps if not s.get('passed', True)), None)}))
            sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
