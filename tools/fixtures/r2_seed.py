"""Stable round 2 (R2-01): add realistic photo fixtures through the app's own API.

    python -B tools/fixtures/r2_seed.py seed  --target phase8-dev --batch A
    python -B tools/fixtures/r2_seed.py seed  --target demo --batch A --backup C:/ReFind-Laravel/backups/demo/<UTC>
    python -B tools/fixtures/r2_seed.py check --target phase8-dev|demo

Targets: phase8-dev (8020, disposable) and demo (8030, the owner's persistent Stable demo). Writes go through the normal
API one at a time (registration, report create with multipart photos, claims, BM review, pickups), so matching,
history, notifications and the media journal behave exactly as in real use; nothing is written to the database
directly and no application code changes. Photos must first be prepared by r2_prepare.py; their SHA-256 values are
checked against provenance.json before anything is written. Each batch runs once per target.

Demo guards: --backup must name a backup made by backups/demo/backup_demo.py less than three hours ago whose
VALIDATION.json says the restore was identical and the live demo did not differ; every demo table's CHECKSUM must
still equal the backup's (so nothing happened in between); the six persona emails must not exist yet; the worker on
8030 must answer and allow loopback registration. Rollback = the exact restore in docs/stable/STABLE.md section 4 from
that backup. phase8-dev rollback = its pre-seed snapshot (phase6-dev.php restore <id>, worker stopped).

Accounts: the six personas use the demo's shared password, read from the private state/demo/demo-accounts.json and
never printed. On the demo they are appended to DEMO-ACCOUNTS.md and demo-accounts.json.

Batches: A (owner photos and Flickr photos) and B (Wikimedia Commons photos, when they can be downloaded). The first
batch registers the personas; later batches sign them in.

Outputs (private, outside Git): state/<profile>/r2-fixtures-receipt-<batch>.json (fixture key -> ids) and
fixtures/round2/EVALUATION_MANIFEST[-demo].{json,md} with that target's receipts merged in.
"""
import hashlib, http.cookiejar, json, random, secrets, subprocess, sys, urllib.error, urllib.parse, urllib.request
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path('C:/ReFind-Laravel'); APP = Path(__file__).resolve().parents[2]; FIX = ROOT / 'fixtures/round2'
if APP.name != 'laravel-p8':
    sys.exit('r2_seed runs from the laravel-p8 checkout')
SPEC = json.loads(Path(__file__).with_name('r2_spec.json').read_text(encoding='utf-8'))
TARGETS = {'phase8-dev': {'base': 'http://127.0.0.1:8020', 'state': ROOT / 'state/phase8-dev', 'schema': 'refind_laravel_p8_dev', 'suffix': ''},
           'demo': {'base': 'http://127.0.0.1:8030', 'state': ROOT / 'state/demo', 'schema': 'refind_laravel_demo', 'suffix': '-demo'}}
DEMO_ACCOUNTS_JSON = ROOT / 'state/demo/demo-accounts.json'; DEMO_ACCOUNTS_MD = ROOT / 'state/demo/DEMO-ACCOUNTS.md'
MARIADB = [str(ROOT / 'runtime/mariadb/bin/mariadb.exe'), '--defaults-file=' + str(ROOT / 'state/admin-client.ini'), '-N', '-B']
TABLES = ['users', 'buildings', 'categories', 'item_statuses', 'campus_locations', 'location_floors', 'items', 'item_photos', 'item_matches', 'claims', 'claim_photos', 'handovers',
          'notifications', 'item_status_history', '_workspace_owner', '_write_operations']
T = None  # the chosen target


class Failure(RuntimeError):
    pass


def receipt_path(batch):
    return T['state'] / ('r2-fixtures-receipt-' + batch + '.json')


def receipts():
    return {p.stem.rsplit('-', 1)[-1]: json.loads(p.read_text(encoding='utf-8')) for p in sorted(T['state'].glob('r2-fixtures-receipt-*.json'))}


def sql(q):
    r = subprocess.run(MARIADB + ['-e', q], capture_output=True, text=True, timeout=120)
    if r.returncode:
        raise Failure('read-only query failed: ' + r.stderr[-200:])
    return [line.split('\t') for line in r.stdout.splitlines() if line]


class Client:
    def __init__(self, label):
        self.label = label; self.token = ''
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.call('/api/session.php')

    def call(self, path, data=None, files=None, expect=(200,)):
        expect = (expect,) if isinstance(expect, int) else expect
        h = {}; body = None
        if data is not None:
            if not files:
                body = urllib.parse.urlencode(data).encode(); h['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                boundary = 'r2' + secrets.token_hex(12); chunks = []
                for k, v in data.items():
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode())
                for field, name, payload in files:
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + field + '"; filename="' + name + '"\r\nContent-Type: image/jpeg\r\n\r\n').encode() + payload + b'\r\n')
                chunks.append(('--' + boundary + '--\r\n').encode()); body = b''.join(chunks); h['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            h['X-CSRF-Token'] = self.token
        req = urllib.request.Request(T['base'] + path, data=body, headers=h, method='POST' if data is not None else 'GET')
        try:
            r = self.http.open(req, timeout=120)
        except urllib.error.HTTPError as e:
            r = e
        raw = r.read(); obj = json.loads(raw) if raw and 'application/json' in r.headers.get('Content-Type', '') else None
        if r.code not in expect:
            raise Failure(self.label + ' ' + path.split('?')[0] + ' returned ' + str(r.code) + ': ' + json.dumps(obj)[:300])
        if isinstance(obj, dict) and isinstance(obj.get('csrf'), str):
            self.token = obj['csrf']
        return obj

    def raw(self, path):
        try:
            r = self.http.open(urllib.request.Request(T['base'] + path), timeout=120)
        except urllib.error.HTTPError as e:
            r = e
        return r.code, r.headers.get('Content-Type', ''), r.read()

    def login(self, email, password):
        self.call('/api/auth.php', dict(action='login', email=email, password=password)); return self


def shared_password():
    accounts = json.loads(DEMO_ACCOUNTS_JSON.read_text(encoding='utf-8'))['accounts']
    passwords = {a['password'] for a in accounts}
    if len(passwords) != 1:
        raise Failure('the demo accounts do not share one password; refusing to guess')
    return passwords.pop()


def bm_login():
    if T['state'].name == 'demo':
        bm = next(a for a in json.loads(DEMO_ACCOUNTS_JSON.read_text(encoding='utf-8'))['accounts'] if a['key'] == 'bm')
        return Client('bm').login(bm['email'], bm['password'])
    creds = json.loads((T['state'] / 'credentials.json').read_text(encoding='utf-8'))
    return Client('bm').login(creds['bm']['email'], creds['bm']['password'])


def prepared_photos(keys):
    prov = json.loads((FIX / 'provenance.json').read_text(encoding='utf-8'))['photos']
    data = {}
    for key in keys:
        if key not in prov:
            raise Failure('photo not prepared yet (run r2_prepare.py once its source is downloaded): ' + key)
        b = (FIX / 'prepared' / (key + '.jpg')).read_bytes()
        if hashlib.sha256(b).hexdigest() != prov[key]['prepared_sha256']:
            raise Failure('prepared photo changed since r2_prepare.py: ' + key)
        if len(b) > 250_000:
            raise Failure('prepared photo too large: ' + key)
        data[key] = b
    return data


def order(reports):
    rng = random.Random(20261121)  # creation order follows the story's dates; ties are shuffled
    keyed = [(-r['days_ago'], r['time'] or '23:59', rng.random(), r) for r in reports]
    return [k[-1] for k in sorted(keyed, key=lambda k: k[:3])]


def demo_guards(backup):
    folder = Path(backup).resolve()
    if folder.parent != (ROOT / 'backups/demo').resolve() or not (folder / 'MANIFEST.json').is_file():
        raise Failure('--backup must be a folder made by backups/demo/backup_demo.py')
    taken = datetime.strptime(folder.name, '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc)
    if datetime.now(timezone.utc) - taken > timedelta(hours=3):
        raise Failure('the backup is older than three hours; take a fresh one')
    v = json.loads((folder / 'VALIDATION.json').read_text(encoding='utf-8')) if (folder / 'VALIDATION.json').is_file() else {}
    if v.get('restore_identical_to_backup') is not True or v.get('live_differs_from_backup'):
        raise Failure('the backup has no clean VALIDATION.json (restore identical, live not different)')
    manifest = json.loads((folder / 'MANIFEST.json').read_text(encoding='utf-8'))
    live = {row[0].split('.')[-1]: row[1] for row in sql('CHECKSUM TABLE ' + ', '.join('`refind_laravel_demo`.`' + t + '`' for t in TABLES))}
    drift = [t for t in TABLES if str(manifest['tables'].get(t, {}).get('checksum')) != live.get(t)]
    if drift:
        raise Failure('the demo changed since the backup (' + ', '.join(drift) + '); take a fresh backup first')
    print('guard: backup', folder.name, 'validated and the live demo still equals it')
    return folder.name


def add_demo_accounts(password):
    data = json.loads(DEMO_ACCOUNTS_JSON.read_text(encoding='utf-8'))
    for p in SPEC['personas']:
        data['accounts'].append({'key': p['key'], 'name': p['name'], 'email': p['email'], 'password': password, 'previous_email': None, 'role': 'STUDENT', 'role_label': 'Student',
                                 'created_by': 'registration API (Round 2 photo fixtures, 21 Nov 2026)', 'story': p['story']})
    DEMO_ACCOUNTS_JSON.write_text(json.dumps(data, indent=2), encoding='utf-8')
    lines = DEMO_ACCOUNTS_MD.read_text(encoding='utf-8').splitlines()
    last = max(i for i, line in enumerate(lines) if line.startswith('| ') and '@' in line)
    rows = ['| ' + ' | '.join([p['name'], 'Student', p['email'], password, p['story']]) + ' |' for p in SPEC['personas']]
    lines[last + 1:last + 1] = rows
    lines += ['', 'The six accounts from Nadia Aulia to Kevin Wijaya were added on 21 November 2026 with the Round 2 photo fixtures (same shared password).',
              'A reset (reset-demo) returns to the seeded baseline, which does not include them.']
    DEMO_ACCOUNTS_MD.write_text('\n'.join(lines) + '\n', encoding='utf-8')


def seed(batch, backup=None):
    if receipt_path(batch).exists():
        raise Failure('Batch ' + batch + ' is already on this target (receipt exists).')
    reports = [r for r in SPEC['reports'] if r.get('batch', 'A') == batch]
    claims = [c for c in SPEC['claims'] if c.get('batch', 'A') == batch]
    if not reports:
        raise Failure('no reports in batch ' + batch)
    photos = prepared_photos(sorted({k for r in reports for k in r.get('photos', []) + r.get('private', [])}))
    password = shared_password()
    backup_id = demo_guards(backup) if T['state'].name == 'demo' else None
    emails = [p['email'] for p in SPEC['personas']]
    existing = int(sql("SELECT COUNT(*) FROM `" + T['schema'] + "`.users WHERE email IN ('" + "','".join(emails) + "')")[0][0])
    first = not receipts()
    if first and existing:
        raise Failure('some persona emails already exist on this target; refusing')
    session = Client('guest').call('/api/session.php')
    if first and session.get('registration_enabled') is not True:
        raise Failure('loopback registration is not enabled on this worker')
    people = {}
    for p in SPEC['personas']:
        c = Client(p['key'])
        if first:
            c.call('/api/register.php', dict(name=p['name'], email=p['email'], password=password, confirm_password=password), expect=201)
        else:
            c.login(p['email'], password)
        people[p['key']] = c
    if first and T['state'].name == 'demo':
        add_demo_accounts(password)
    bm = bm_login()
    today = date.fromisoformat(people[SPEC['personas'][0]['key']].call('/api/references.php')['campus_today'])
    receipt = {'batch': batch, 'target': T['state'].name, 'backup': backup_id, 'spec_version': SPEC['version'], 'seeded_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
               'campus_today': today.isoformat(), 'base': T['base'], 'personas': {p['key']: p['email'] for p in SPEC['personas']}, 'reports': {}, 'claims': {}}
    rpath = receipt_path(batch)
    try:
        for r in order(reports):
            data = dict(action='create', type=r['type'], title=r['title'], description=r['description'], category_id=r['category'], location_id=r['area'],
                        location_detail=r['detail'], date=(today - timedelta(days=r['days_ago'])).isoformat(), time=r['time'] or '', clue=r['clue'])
            files = [('photos[]', k + '.jpg', photos[k]) for k in r.get('photos', [])] + [('ownershipPhotos[]', k + '.jpg', photos[k]) for k in r.get('private', [])]
            c = people[r['by']]
            item_id = c.call('/api/items.php', data, files, expect=201)['id']
            item = c.call('/api/items.php?id=' + str(item_id))['item']
            receipt['reports'][r['key']] = {'id': item_id, 'type': r['type'], 'by': r['by'], 'photo_keys': r.get('photos', []), 'private_keys': r.get('private', []),
                                            'photo_ids': [str(i) for i in item.get('photo_ids', [])]}
            print('report', r['key'], item_id)
        ids = {k: v['id'] for rc in receipts().values() for k, v in rc['reports'].items()}; ids.update({k: v['id'] for k, v in receipt['reports'].items()})
        for cl in claims:
            c = people[cl['by']]
            claim_id = c.call('/api/claims.php', dict(action='submit', itemId=ids[cl['found']], lostItemId=ids[cl['lost']] if cl['lost'] else '', answer=cl['answer']), expect=201)['id']
            entry = {'id': claim_id, 'found': cl['found'], 'lost': cl['lost'] or None, 'steps': []}
            for step in cl['steps']:
                if step[0] == 'review':
                    rev = bm.call('/api/staff.php?resource=claims&id=' + str(claim_id))['claim']['revision']
                    bm.call('/api/staff.php', dict(action='review', id=claim_id, decision=step[1], note=step[2], revision=rev, confirm='1'))
                elif step[0] in ('schedule', 'complete'):
                    hid = bm.call('/api/staff.php?resource=claims&id=' + str(claim_id))['claim'].get('handover_id')
                    if not hid:
                        raise Failure('no pickup record for claim ' + str(claim_id))
                    rev = bm.call('/api/handovers.php?id=' + str(hid))['handovers'][0]['revision']
                    if step[0] == 'schedule':
                        when = (today + timedelta(days=step[2])).isoformat() + 'T' + step[3]
                        bm.call('/api/handovers.php', dict(action='schedule', id=hid, pickup_location_id=step[1], scheduled_at=when, note=step[4], revision=rev, confirm='1'))
                    else:
                        bm.call('/api/handovers.php', dict(action='complete', id=hid, note=step[1], revision=rev, confirm='1'))
                    entry['handover_id'] = hid
                elif step[0] == 'withdraw':
                    c.call('/api/claims.php', dict(action='withdraw', itemId=ids[cl['found']]))
                entry['steps'].append(step[0] if step[0] != 'review' else 'review:' + step[1])
            receipt['claims'][cl['key']] = entry
            print('claim', cl['key'], claim_id, entry['steps'])
    finally:  # a partial run still leaves a receipt naming exactly what was created
        receipt['complete'] = len(receipt['reports']) == len(reports) and len(receipt['claims']) == len(claims)
        rpath.write_text(json.dumps(receipt, indent=1), encoding='utf-8')
    manifest()
    print('batch', batch, 'on', T['state'].name, ':', len(receipt['reports']), 'reports and', len(receipt['claims']), 'claims')


def manifest():
    cases_path = FIX / 'cases.json'
    if not cases_path.exists():
        return
    rcs = receipts(); reports = {k: v for rc in rcs.values() for k, v in rc['reports'].items()}
    cases = json.loads(cases_path.read_text(encoding='utf-8')); prov = json.loads((FIX / 'provenance.json').read_text(encoding='utf-8'))['photos']
    out = {'about': cases['about'], 'spec_version': SPEC['version'], 'target': T['state'].name, 'batches': {b: rc['seeded_at'] for b, rc in rcs.items()}, 'base': T['base'], 'cases': []}
    for case in cases['cases']:
        if not all(k in reports for k in case['reports']):
            out['cases'].append({**{k: v for k, v in case.items() if k != 'queries'}, 'status': 'pending: its reports are in a batch not seeded yet'}); continue
        reps = [{'key': k, 'item_id': reports[k]['id'], 'type': reports[k]['type'],
                 'public_photos': [{'key': p, 'photo_id': i, **{f: prov[p].get(f) for f in ('source', 'title', 'file', 'author', 'license', 'page')}}
                                   for p, i in zip(reports[k]['photo_keys'], reports[k]['photo_ids'] or [None] * len(reports[k]['photo_keys']))],
                 'private_photos': reports[k]['private_keys']} for k in case['reports']]
        queries = [{'key': q, **{f: prov.get(q, {}).get(f) for f in ('source', 'title', 'author', 'license', 'page')}, 'file': str(FIX / 'prepared' / (q + '.jpg'))} for q in case.get('queries', [])]
        out['cases'].append({**{k: v for k, v in case.items() if k not in ('reports', 'queries')}, 'status': 'seeded', 'reports': reps, 'held_out_queries': queries})
    name = 'EVALUATION_MANIFEST' + T['suffix']
    (FIX / (name + '.json')).write_text(json.dumps(out, indent=1, ensure_ascii=False), encoding='utf-8')
    md = ['# ReFind Round 2 image-search evaluation manifest (' + T['state'].name + ')', '', cases['about'], '',
          'Seeded on ' + T['base'] + ': ' + ', '.join('batch ' + b + ' at ' + t for b, t in out['batches'].items()) + '.', '',
          '| Case | Relationship | Status | Reports (item id) | Held-out queries | Visible distinguishing traits | Expectation |', '|---|---|---|---|---|---|---|']
    for c in out['cases']:
        reps = ', '.join(r['key'] + ' (' + str(r['item_id']) + ')' for r in c['reports']) if c['status'] == 'seeded' else ', '.join(c['reports'])
        qs = ', '.join(q['key'] for q in c.get('held_out_queries', [])) or '-'
        md.append('| ' + ' | '.join([c['case'], c['relationship'], c['status'].split(':')[0], reps, qs, c['traits'], c['expectation']]) + ' |')
    md += ['', 'Ground truth stays here, outside Git and outside the served folder. Do not add it to report text, file names or any model input.',
           'A labelled positive is source-session evidence of one physical object, not a validated AI match: nothing here has been run through an image-search model.']
    (FIX / (name + '.md')).write_text(chr(10).join(md) + chr(10), encoding='utf-8')
    print('manifest', name, len(out['cases']), 'cases,', sum(c['status'] == 'seeded' for c in out['cases']), 'seeded')


def check():
    rcs = receipts()
    if not rcs:
        raise Failure('nothing seeded on this target yet')
    c = bm_login(); problems = []; n = 0
    from io import BytesIO
    from PIL import Image
    reports = {k: v for rc in rcs.values() for k, v in rc['reports'].items()}
    for key, r in reports.items():
        item = c.call('/api/items.php?id=' + str(r['id']))['item']
        for pid in item.get('photo_ids', []):
            for suffix in ('', '&w=480'):
                code, ctype, body = c.raw('/api/photos.php?kind=item&id=' + str(pid) + suffix)
                ok = code == 200 and ctype.startswith('image/')
                if ok:
                    im = Image.open(BytesIO(body)); ok = min(im.size) >= 200 and not im.getexif() and 'icc_profile' not in im.info
                n += 1
                if not ok:
                    problems.append((key, pid, suffix, code, ctype))
        if len(item.get('photo_ids', [])) != len(r['photo_keys']):
            problems.append((key, 'photo count', len(item.get('photo_ids', [])), len(r['photo_keys'])))
    print(json.dumps({'target': T['state'].name, 'batches': sorted(rcs), 'complete': all(rc.get('complete', True) for rc in rcs.values()), 'reports': len(reports), 'photo_requests': n, 'problems': problems}, indent=1))
    return 1 if problems else 0


if __name__ == '__main__':
    args = sys.argv[1:]
    opt = lambda name: args[args.index(name) + 1] if name in args and args.index(name) + 1 < len(args) else None
    target, batch = opt('--target'), opt('--batch')
    if target not in TARGETS or (args[:1] == ['seed'] and batch not in ('A', 'B')) or args[:1] not in (['seed'], ['check']):
        sys.exit('usage: r2_seed.py seed --target phase8-dev|demo --batch A|B [--backup <demo backup dir>]   or   r2_seed.py check --target phase8-dev|demo')
    T = TARGETS[target]
    if target == 'demo' and args[0] == 'seed' and not opt('--backup'):
        sys.exit('seeding the demo needs --backup <fresh validated backup folder>')
    try:
        sys.exit(seed(batch, opt('--backup')) or 0) if args[0] == 'seed' else sys.exit(check())
    except Failure as e:
        sys.exit('refused: ' + str(e))
