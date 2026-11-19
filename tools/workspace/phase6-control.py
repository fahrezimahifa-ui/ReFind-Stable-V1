"""Owned launcher for the disposable, unaccepted development profiles (phase6-dev, phase7-dev).

Usage: phase6-control.py <action> [--profile phase8-dev|phase7-dev|phase6-dev] (default phase8-dev in this branch).
The development profiles share the migration-owned portable MariaDB on 127.0.0.1:3307 with the accepted academic
profiles. This launcher never touches their schemas, grants, web processes or state. Every control action
re-verifies live ownership (psutil pid/create-time/exe/cmdline, port probes and PROCESSLIST), not only the
JSON process records.
"""
from pathlib import Path
import argparse, base64, configparser, json, os, secrets, shutil, socket, subprocess, sys, time
import psutil

ROOT = Path('C:/ReFind-Laravel')
PHP = ROOT / 'runtime/php85/php.exe'; INI = ROOT / 'runtime/php85/php.ini'; DB = ROOT / 'runtime/mariadb/bin'
DEV_PROFILES = {
    'phase6-dev': {'app': 'laravel-p6', 'ports': (8016, 8017), 'records': 'phase6-processes.json', 'database': 'refind_laravel_p6_dev', 'username': 'refind_p6_dev_app', 'cookie': 'refind_laravel_p6_dev'},
    'phase7-dev': {'app': 'laravel-p7', 'ports': (8018, 8019), 'records': 'phase7-processes.json', 'database': 'refind_laravel_p7_dev', 'username': 'refind_p7_dev_app', 'cookie': 'refind_laravel_p7_dev'},
    'phase8-dev': {'app': 'laravel-p8', 'ports': (8020, 8021), 'records': 'phase8-processes.json', 'database': 'refind_laravel_p8_dev', 'username': 'refind_p8_dev_app', 'cookie': 'refind_laravel_p8_dev'},
}
STAGES = {'phase6-dev': 'student,review,approval', 'phase7-dev': 'student,review,approval,pickup', 'phase8-dev': 'student,review,approval,pickup,status,notifications,account,registration'}
OWNER = ROOT / '.refind-laravel-owner.json'
ACADEMIC = ROOT / 'state/academic-processes.json'  # shared 'db' record so the canonical launcher can still stop it
POC = ROOT / 'state/processes.json'
APP = RECORD = PROFILE = STATE = PORTS = IDENTITY = None


def select(profile):
    global APP, RECORD, PROFILE, STATE, PORTS, IDENTITY
    d = DEV_PROFILES[profile]
    PROFILE = profile; APP = ROOT / d['app']; RECORD = ROOT / 'state' / d['records']; STATE = ROOT / 'state' / profile; PORTS = d['ports']
    IDENTITY = {'database': d['database'], 'username': d['username'], 'cookie': d['cookie'], 'url': 'http://127.0.0.1:' + str(d['ports'][0])}
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
DIRS = ['app/private/refind/items', 'app/private/refind/evidence', 'app/private/refind/claims', 'app/private/refind/avatars',
        'app/private/refind/thumbs', 'auth', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs',
        'operations/manifests', 'operations/staging', 'snapshots', 'tmp', 'uploads', 'phpunit-cache']


def redirected(p):
    return p.is_symlink() or p.is_junction() or p.resolve() != p


def owned_root():
    o = json.loads(OWNER.read_text(encoding='utf-8-sig'))
    if o['project'] != 'refind-laravel-poc' or Path(o['root']).resolve() != ROOT.resolve():
        raise RuntimeError('Wrong workspace owner')
    for relative in ['state', 'runtime', 'laravel', APP.name]:
        if redirected(ROOT / relative):
            raise RuntimeError('Redirected workspace refused')
    b = json.loads((ROOT / 'state/academic-owner.json').read_text())
    if b.get('project') != 'refind-laravel-academic-b' or Path(b.get('root', '')).resolve() != ROOT.resolve() or b.get('parent_workspace_id') != o['workspace_id']:
        raise RuntimeError('Academic owner mismatch')
    return b


def load(path):
    return json.loads(path.read_text()) if path.exists() else {}


def save(path, data):
    path.write_text(json.dumps(data, indent=2), encoding='utf-8')


def php_args(port):
    return ['-c', str(INI), '-d', 'error_log=' + str(STATE / 'php-error.log'), '-d', 'upload_tmp_dir=' + str(STATE / 'uploads'),
            '-d', 'sys_temp_dir=' + str(STATE / 'tmp'), '-S', '127.0.0.1:' + str(port), '-t', str(APP / 'public'), str(APP / 'tools/server.php')]


def live(record):
    """Return the live psutil process for a record, or None. Raises if the pid now belongs to someone else."""
    try:
        p = psutil.Process(record['pid'])
        if abs(p.create_time() - record['created']) > 0.01:
            return None  # pid reused by an unrelated process: the recorded process is gone
        if Path(p.exe()).resolve() != Path(record['exe']).resolve() or p.cmdline()[1:] != record['required_args']:
            raise RuntimeError('Process ownership mismatch; refusing control')
        return p
    except (psutil.NoSuchProcess, psutil.ZombieProcess):
        return None


def checked_web(record):
    if Path(record['exe']).resolve() != PHP.resolve() or record.get('profile') != PROFILE or not any(record['required_args'] == php_args(port) for port in PORTS):
        raise RuntimeError('Process command is outside the ' + PROFILE + ' launch template')
    return live(record)


def checked_db(record):
    allowed = [(DB / 'mariadbd.exe').resolve(), (DB / 'mysqld.exe').resolve()]
    if Path(record['exe']).resolve() not in allowed or record.get('profile') is not None or record['required_args'] != ['--defaults-file=' + str(ROOT / 'state/mariadb.ini')]:
        raise RuntimeError('Database process is outside the owned launch template')
    return live(record)


def other_live_web():
    """Live web workers of other profiles (academic, PoC or the other dev profile). Used to refuse shared-server disruption."""
    out = []
    for path in (ACADEMIC, POC, *[ROOT / 'state' / d['records'] for name, d in DEV_PROFILES.items() if name != PROFILE]):
        for key, rec in load(path).items():
            if key.startswith('web-') and live(rec):
                out.append(path.name + ':' + key)
    return out


def port_open(port):
    s = socket.socket(); s.settimeout(0.3)
    try:
        return s.connect_ex(('127.0.0.1', port)) == 0
    finally:
        s.close()


def port_free(port):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', port))
    except OSError:
        raise RuntimeError('Reserved port occupied: ' + str(port))
    finally:
        s.close()


def preflight():
    owned_root(); m = psutil.virtual_memory(); free = shutil.disk_usage(ROOT).free
    if m.available < 2 * 1024 ** 3:
        raise RuntimeError('Startup gate: less than 2 GiB available RAM')
    if free < 5 * 1024 ** 3:
        raise RuntimeError('Startup gate: less than 5 GiB free disk')
    logs = sum(p.stat().st_size for p in (ROOT / 'state').rglob('*.log') if p.is_file())
    if logs > 20 * 1024 ** 2:
        raise RuntimeError('Private logs exceed 20 MiB; review before starting more workload')
    return {'available_memory_mib': round(m.available / 1024 ** 2), 'disk_free_gib': round(free / 1024 ** 3, 2)}


def db_config():
    base = (ROOT / 'runtime/mariadb').as_posix(); data = (ROOT / 'state/mariadb-data').as_posix(); r = ROOT.as_posix()
    return '\n'.join(['[mysqld]', 'basedir=' + base, 'datadir=' + data, 'port=3307', 'bind-address=127.0.0.1', 'skip-name-resolve', 'pid-file=' + r + '/state/mariadb.pid', 'log-error=' + r + '/state/mariadb-error.log', 'tmpdir=' + r + '/state/tmp', 'character-set-server=utf8mb4', 'collation-server=utf8mb4_unicode_ci', 'innodb-buffer-pool-size=128M', 'innodb-log-file-size=32M', 'max-connections=12', 'tmp-table-size=16M', 'max-heap-table-size=16M', 'table-open-cache=128', 'thread-cache-size=4', 'performance-schema=OFF', 'skip-log-bin', ''])


def checked_database_config():
    marker = json.loads((ROOT / 'state/database-owner.json').read_text())
    if marker.get('workspace_id') != json.loads(OWNER.read_text(encoding='utf-8-sig'))['workspace_id'] or marker.get('port') != 3307 or Path(marker.get('datadir', '')).resolve() != (ROOT / 'state/mariadb-data').resolve():
        raise RuntimeError('Database marker mismatch')
    for relative in ['state/mariadb-data', 'runtime/mariadb', 'runtime/mariadb/bin', 'runtime/php85', 'state/tmp']:
        if redirected(ROOT / relative):
            raise RuntimeError('Redirected runtime directory refused')
    cfg = ROOT / 'state/mariadb.ini'
    if cfg.is_symlink() or cfg.read_text(encoding='utf-8') != db_config():
        raise RuntimeError('Database configuration differs from the approved isolated template')


def checked_admin_client():
    p = ROOT / 'state/admin-client.ini'
    if p.is_symlink():
        raise RuntimeError('Redirected client configuration refused')
    cfg = configparser.ConfigParser(interpolation=None); cfg.read(p, encoding='utf-8'); c = cfg['client']
    if c.get('host') != '127.0.0.1' or c.get('port') != '3307' or c.get('protocol') != 'tcp' or c.get('user') != 'root':
        raise RuntimeError('Administrative connection target mismatch')
    if c.get('password') != json.loads((ROOT / 'state/owner-credentials.json').read_text())['password']:
        raise RuntimeError('Administrative credential identity mismatch')


def admin_sql(sql):
    checked_admin_client()
    r = subprocess.run([str(DB / 'mariadb.exe'), '--defaults-file=' + str(ROOT / 'state/admin-client.ini'), '-N', '-B', '-e', sql], capture_output=True, text=True, timeout=20, creationflags=FLAGS)
    if r.returncode:
        raise RuntimeError('Owned administrative query failed')
    return [line.split('\t') for line in r.stdout.splitlines() if line]


def spawn(path, key, exe, args, profile):
    env = os.environ.copy(); env['PHP_INI_SCAN_DIR'] = str(ROOT / 'runtime/empty-ini-scan')
    if profile:
        env['REFIND_PROFILE'] = profile
    (ROOT / 'runtime/empty-ini-scan').mkdir(exist_ok=True)
    (ROOT / 'state/academic-runtime').mkdir(exist_ok=True)
    log = (ROOT / 'state/academic-runtime' / ('phase6-' + key + '.log')).open('ab')
    p = subprocess.Popen([str(exe)] + args, cwd=APP, env=env, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT, creationflags=FLAGS)
    log.close(); proc = psutil.Process(p.pid)
    r = load(path); r[key] = {'pid': p.pid, 'created': proc.create_time(), 'exe': str(exe), 'required_args': args, 'profile': profile}; save(path, r)
    return r[key]


def wait_port(record, port, check):
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        if not check(record):
            raise RuntimeError('Owned process exited; inspect its private log')
        if port_open(port):
            return
        time.sleep(0.2)
    raise RuntimeError('Timed out waiting for owned process on ' + str(port))


def start_db():
    checked_database_config(); r = load(ACADEMIC)
    if 'db' in r and checked_db(r['db']):
        if not port_open(3307):
            raise RuntimeError('Recorded database is not listening')
        return 'reused'
    port_free(3307)
    if not (ROOT / 'state/database-owner.json').exists():
        raise RuntimeError('Database initialization marker missing')
    exe = DB / 'mariadbd.exe'
    if not exe.exists():
        exe = DB / 'mysqld.exe'
    rec = spawn(ACADEMIC, 'db', exe, ['--defaults-file=' + str(ROOT / 'state/mariadb.ini')], None)
    wait_port(rec, 3307, checked_db)
    return 'started'


def run_php(script, *args, env_extra=None):
    env = os.environ.copy(); env['REFIND_PROFILE'] = PROFILE; env['PHP_INI_SCAN_DIR'] = str(ROOT / 'runtime/empty-ini-scan')
    env.update(env_extra or {})
    p = subprocess.run([str(PHP), '-c', str(INI), str(APP / script), *args], cwd=APP, env=env, text=True, capture_output=True, timeout=180)
    if p.returncode:
        (STATE / 'phase6-control-error.log').write_text(p.stdout + p.stderr, encoding='utf-8')
        raise RuntimeError(script + ' failed; inspect state/' + PROFILE + '/phase6-control-error.log')
    return p.stdout.strip()


def dummy_hash():
    code = "echo password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 10]);"
    env = os.environ.copy(); env['PHP_INI_SCAN_DIR'] = str(ROOT / 'runtime/empty-ini-scan')
    return subprocess.run([str(PHP), '-c', str(INI), '-r', code], text=True, capture_output=True, timeout=30, env=env, check=True).stdout.strip()


def checked_profile():
    p = STATE / 'profile.json'
    if redirected(p):
        raise RuntimeError('Redirected application profile refused')
    cfg = json.loads(p.read_text(encoding='utf-8'))
    expected = dict(IDENTITY, profile=PROFILE, workspace_id=owned_root()['workspace_id'])
    if any(cfg.get(k) != v for k, v in expected.items()):
        raise RuntimeError('Application profile identity mismatch before launch')
    if not cfg.get('key', '').startswith('base64:') or len(base64.b64decode(cfg['key'][7:], validate=True)) != 32:
        raise RuntimeError('Application key configuration invalid')
    for key in ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_URL', 'DATABASE_URL', 'SESSION_COOKIE']:
        if key in os.environ:
            raise RuntimeError('Inherited configuration override refused: ' + key)
    for relative in ['framework/sessions', 'framework/cache/data', 'app/private/refind']:
        d = STATE / relative
        if not d.is_dir() or redirected(d):
            raise RuntimeError('Profile storage boundary invalid')
    return cfg


def initialize():
    owner = owned_root(); print(json.dumps(preflight())); print('db: ' + start_db())
    if redirected(STATE.parent):
        raise RuntimeError('Redirected state refused')
    STATE.mkdir(exist_ok=True)
    if redirected(STATE):
        raise RuntimeError('Redirected profile state refused')
    for relative in DIRS:
        (STATE / relative).mkdir(parents=True, exist_ok=True)
    profile = STATE / 'profile.json'
    if not profile.exists():
        cfg = dict(IDENTITY, profile=PROFILE, workspace_id=owner['workspace_id'], password=secrets.token_hex(24),
                   key='base64:' + base64.b64encode(secrets.token_bytes(32)).decode(), dummy_hash=dummy_hash())
        with open(profile, 'x', encoding='utf-8') as f:
            json.dump(cfg, f, indent=2)
    checked_profile()
    print(run_php('tools/provision-academic.php'))


def grants(stages):
    owned_root()
    if live_web():
        raise RuntimeError('Stop ' + PROFILE + ' web workers before changing grants')
    print(run_php('tools/grant-reporting.php', 'matching'))  # accepted reporting set first
    for stage in stages:
        print(run_php('tools/grant-claims.php', stage))


def live_web():
    return {k: v for k, v in load(RECORD).items() if k.startswith('web-') and checked_web(v)}


def start(second=False):
    checked_profile(); print(json.dumps(preflight())); print('db: ' + start_db())
    if not (STATE / 'provisioned.json').exists():
        raise RuntimeError('Completed synthetic provisioning receipt required')
    port = PORTS[1] if second else PORTS[0]; key = 'web-' + str(port)
    r = load(RECORD)
    if key in r and checked_web(r[key]):
        print('Owned server already running: ' + str(port)); return
    if len(live_web()) + len(other_live_web()) >= 2:
        raise RuntimeError('At most two migration web instances may run')
    port_free(port)
    rec = spawn(RECORD, key, PHP, php_args(port), PROFILE)
    wait_port(rec, port, checked_web)
    print('Started owned ' + PROFILE + ' server at http://127.0.0.1:' + str(port) + '/')


def stop(db=False):
    owned_root(); r = load(RECORD)
    for key, rec in list(r.items()):
        if key.startswith('web-'):
            p = checked_web(rec)
            if p:
                p.terminate(); p.wait(timeout=10)
            del r[key]; save(RECORD, r)
    for port in PORTS:
        if port_open(port):
            raise RuntimeError('A listener remains on ' + str(port) + ' after stopping owned workers')
    if not db:
        print(PROFILE + ' web workers stopped; database and other profiles untouched.'); return
    others = other_live_web()
    if others:
        raise RuntimeError('Other profiles are serving (' + ', '.join(others) + '); shared database left running')
    a = load(ACADEMIC)
    if 'db' not in a:
        print('No recorded owned database.'); return
    checked_database_config(); p = checked_db(a['db'])
    if p:
        users = admin_sql("SELECT USER FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND USER NOT IN ('system user','event_scheduler')")
        if users:
            raise RuntimeError('Live database sessions exist (' + ', '.join(sorted({u[0] for u in users})) + '); shutdown refused')
        res = subprocess.run([str(DB / 'mariadb-admin.exe'), '--defaults-file=' + str(ROOT / 'state/admin-client.ini'), 'shutdown'], capture_output=True, timeout=20, creationflags=FLAGS)
        if res.returncode:
            raise RuntimeError('Graceful owned database shutdown failed; no force stop attempted')
        p.wait(timeout=20)
    a = load(ACADEMIC); a.pop('db', None); save(ACADEMIC, a)
    print(PROFILE + ' workers and the idle owned database stopped; private data preserved.')


def status():
    owned_root(); out = {}
    for key, rec in load(RECORD).items():
        p = checked_web(rec); out[key] = {'running': p is not None, 'rss_mib': round(p.memory_info().rss / 1024 ** 2, 1) if p else 0}
    a = load(ACADEMIC)
    if 'db' in a:
        p = checked_db(a['db']); out['db'] = {'running': p is not None, 'rss_mib': round(p.memory_info().rss / 1024 ** 2, 1) if p else 0}
    out['other_live_web'] = other_live_web()
    print(json.dumps({'processes': out, 'available_memory_mib': round(psutil.virtual_memory().available / 1024 ** 2)}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['init', 'grants', 'start', 'stop', 'status', 'preflight'])
    parser.add_argument('--second', action='store_true')
    parser.add_argument('--db', action='store_true')
    parser.add_argument('--profile', choices=sorted(DEV_PROFILES), default='phase8-dev')
    parser.add_argument('--stages', default=None)
    a = parser.parse_args()
    select(a.profile)
    if a.stages is None:
        a.stages = STAGES[a.profile]
    try:
        owned_root()
        if a.action == 'init': initialize()
        elif a.action == 'grants': grants([s for s in a.stages.split(',') if s])
        elif a.action == 'start': start(a.second)
        elif a.action == 'stop': stop(a.db)
        elif a.action == 'status': status()
        else: print(json.dumps(preflight()))
    except Exception as e:
        print('ERROR: ' + str(e), file=sys.stderr); sys.exit(1)
