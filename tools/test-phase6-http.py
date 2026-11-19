"""Phase 6 HTTP contract and real concurrency checks against live phase6-dev workers (8016 and 8017).

Prerequisites: phase6-control.py start and start --second, phase6-dev.php seed-students, a saved snapshot.
Writes only synthetic phase6-dev data; the caller restores the snapshot afterwards.
"""
from pathlib import Path
import http.cookiejar, io, json, os, secrets, subprocess, sys, threading, urllib.error, urllib.parse, urllib.request
from PIL import Image

ROOT = Path('C:/ReFind-Laravel'); APP = Path(__file__).resolve().parent.parent
PROFILE = os.environ.get('REFIND_DEV_PROFILE', {'laravel-p7': 'phase7-dev', 'laravel-p8': 'phase8-dev'}.get(APP.name, 'phase6-dev'))
STATE = ROOT / 'state' / PROFILE
BASES = {'phase6-dev': ('http://127.0.0.1:8016', 'http://127.0.0.1:8017'), 'phase7-dev': ('http://127.0.0.1:8018', 'http://127.0.0.1:8019'), 'phase8-dev': ('http://127.0.0.1:8020', 'http://127.0.0.1:8021')}[PROFILE]
PICKUP = PROFILE in ('phase7-dev', 'phase8-dev')
CREDS = {**json.loads((STATE / 'credentials.json').read_text()), **json.loads((STATE / 'tmp/extra-credentials.json').read_text())}
checks = []


def check(label, ok, detail=''):
    checks.append(label)
    if not ok:
        raise AssertionError(label + (' :: ' + str(detail)[:400] if detail else ''))


class Client:
    def __init__(self, base=BASES[0]):
        self.base = base; self.token = ''
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, path, data=None, files=None, token=True, method=None, headers=None):
        h = dict(headers or {}); body = None
        if data is not None:
            if files is None:
                body = urllib.parse.urlencode(data, doseq=True).encode(); h['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                boundary = 'p6' + secrets.token_hex(12); chunks = []
                for k, v in data.items():
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode())
                for field, name, mime, payload in files:
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + field + '"; filename="' + name + '"\r\nContent-Type: ' + mime + '\r\n\r\n').encode() + payload + b'\r\n')
                chunks.append(('--' + boundary + '--\r\n').encode()); body = b''.join(chunks); h['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            if token:
                h['X-CSRF-Token'] = self.token
        req = urllib.request.Request(self.base + path, data=body, headers=h, method=method or ('POST' if data is not None else 'GET'))
        try:
            r = self.http.open(req, timeout=30)
        except urllib.error.HTTPError as e:
            r = e
        raw = r.read(); obj = json.loads(raw) if raw and 'application/json' in r.headers.get('Content-Type', '') else None
        return r.code, obj, r.headers, raw

    def login(self, key):
        self.token = self.call('/api/session.php')[1]['csrf']; c = CREDS[key]
        status, obj, _, _ = self.call('/api/auth.php', dict(action='login', email=c['email'], password=c['password']))
        check('login ' + key + ' via ' + self.base[-4:], status == 200, obj); self.token = obj['csrf']
        return self


def png(seed=0):
    b = io.BytesIO(); Image.new('RGB', (64, 48), (40 + seed % 200, 90, 150)).save(b, format='PNG'); return b.getvalue()


def state():
    env = dict(os.environ, REFIND_PROFILE=PROFILE, PHP_INI_SCAN_DIR=str(ROOT / 'runtime/empty-ini-scan'))
    p = subprocess.run([str(ROOT / 'runtime/php85/php.exe'), '-c', str(ROOT / 'runtime/php85/php.ini'), str(APP / 'tools/phase6-dev.php'), 'state'], cwd=APP, env=env, capture_output=True, text=True, timeout=60)
    if p.returncode:
        raise RuntimeError('state dump failed: ' + p.stderr[-300:])
    return json.loads(p.stdout)


def report(client, kind, title, location='38', category='1'):
    today = client.call('/api/references.php')[1]['campus_today']
    data = dict(action='create', type=kind, title=title, description='Synthetic grey keyboard with a white stripe.', category_id=category, location_id=location, date=today, time='', clue='Synthetic private verification mark.')
    status, obj, _, _ = client.call('/api/items.php', data)
    check('create ' + kind + ' ' + title, status == 201, obj)
    return obj['id']


def claim(client, item, lost='', answer='Synthetic identifying detail for HTTP checks.', photos=None, keep=None, action='submit'):
    data = dict(action=action, itemId=item, lostItemId=lost, answer=answer)
    if keep is not None:
        data['keep_photo_ids'] = keep
    files = [('ownershipPhotos[]', 'p.png', 'image/png', p) for p in (photos or [])]
    return client.call('/api/claims.php', data, files if files else None)


def detail(bm, claim_id):
    status, obj, _, _ = bm.call('/api/staff.php?resource=claims&id=' + str(claim_id))
    check('BM detail ' + str(claim_id), status == 200, obj)
    return obj['claim']


def decide(bm, claim_id, decision, revision, note='Synthetic BM decision note.'):
    return bm.call('/api/staff.php', dict(action='review', id=claim_id, decision=decision, note=note, revision=revision, confirm='1'))


def race(*calls):
    barrier = threading.Barrier(len(calls)); results = [None] * len(calls)

    def run(i, fn):
        barrier.wait(); results[i] = fn()
    threads = [threading.Thread(target=run, args=(i, fn)) for i, fn in enumerate(calls)]
    [t.start() for t in threads]; [t.join(60) for t in threads]
    return results


def contract():
    guest = Client()
    check('guest claim POST 401', guest.call('/api/claims.php', dict(itemId='1'), token=False)[0] == 401)
    check('OPTIONS claims 405', guest.call('/api/claims.php', method='OPTIONS')[0] == 405)
    a = Client().login('student_a'); b = Client().login('student_b'); bm = Client().login('bm')
    found = report(b, 'FOUND', 'HTTP grey keyboard found'); lost = report(a, 'LOST', 'HTTP grey keyboard lost')
    code, _, headers, raw = a.call('/claim.html?id=' + found)
    check('claim page served under the student stage', code == 200 and b'data-claims-read-only="false"' in raw)
    check('claim page keeps CSP', "script-src 'self'" in headers.get('Content-Security-Policy', ''))
    check('BM cannot open claim page', bm.call('/claim.html?id=' + found)[0] == 403)
    bad = Client().login('student_a'); bad.token = 'f' * 64
    code, obj, _, _ = claim(bad, found)
    check('wrong CSRF token 403', code == 403 and obj['code'] == 'csrf_invalid')
    code, obj, _, _ = a.call('/api/claims.php', dict(itemId=found, answer='Synthetic origin probe.'), headers={'Origin': 'http://evil.invalid'})
    check('foreign Origin 403', code == 403 and obj['code'] == 'csrf_invalid')
    code, obj, _, _ = claim(bm, found)
    check('BM cannot submit claims', code == 403 and obj['code'] == 'forbidden')

    code, obj, _, _ = claim(a, found, lost, photos=[png(1)], keep='[]')
    check('multipart submit 201', code == 201 and obj == {'ok': True, 'id': obj['id'], 'resubmitted': False}, obj)
    cid = obj['id']
    mine = a.call('/api/claims.php?itemId=' + found)[1]['claims'][0]
    check('claimant sees proof IDs only', mine['ownership_photo_count'] == 1 and 'ownership_photos' not in mine)
    pid = mine['ownership_photo_ids'][0]
    check('claimant cannot fetch proof bytes', a.call('/api/photos.php?kind=claim&id=' + str(pid))[0] == 403)
    code, _, headers, raw = bm.call('/api/photos.php?kind=claim&id=' + str(pid))
    check('BM proof bytes are private and revalidated', code == 200 and raw[:8] == b'\x89PNG\r\n\x1a\n' and 'private' in headers.get('Cache-Control', '') and headers.get('ETag'))
    check('BM proof 304 on matching ETag', bm.call('/api/photos.php?kind=claim&id=' + str(pid), headers={'If-None-Match': headers['ETag']})[0] == 304)
    check('Found moved to claim review', a.call('/api/items.php?id=' + found)[1]['item']['status_code'] == 'VERIFICATION')

    d = detail(bm, cid)
    check('BM sees decisions and private proof URL', set(d['decisions']) == {'approved', 'rejected', 'need_more_info'} and len(d['ownership_photos']) == 1)
    code, obj, _, _ = decide(bm, cid, 'need_more_info', d['revision'])
    check('request more info 200', code == 200 and obj['status'] == 'NEED_MORE_INFO' and obj['handover_id'] is None, obj)
    check('repeated decision 409', decide(bm, cid, 'need_more_info', d['revision'])[0] == 409)
    code, obj, _, _ = claim(a, found, lost, photos=[png(2)], keep='[]', action='resubmit')
    check('resubmit replaces proof on the same record', code == 200 and obj['id'] == cid and obj['resubmitted'] is True, obj)
    check('old revision denied after resubmission', decide(bm, cid, 'rejected', d['revision'])[0] == 409)
    d = detail(bm, cid)
    check('previous request shown to BM', d['previous_request'] is not None and d['resubmitted_at'] is not None)
    code, obj, _, _ = decide(bm, cid, 'approved', d['revision'], 'Ownership details verified by Building Management.')
    check('approve returns string handover ID', code == 200 and obj['status'] == 'APPROVED' and isinstance(obj['handover_id'], str), obj)
    check('Found and Lost ready for pickup', [c.call('/api/items.php?id=' + i)[1]['item']['status_code'] for c, i in ((b, found), (a, lost))] == ['HANDOVER', 'HANDOVER'])
    check('BM detail links the handover', detail(bm, cid)['handover_id'] == obj['handover_id'])
    if PICKUP:
        check('handover reads available under the pickup stage', bm.call('/api/handovers.php')[0] == 200)
        check('pickup validation reached under the pickup stage', bm.call('/api/handovers.php', dict(action='schedule', id=obj['handover_id']))[0] == 422)
    else:
        check('handover reads stay Phase 7', bm.call('/api/handovers.php')[0] == 501)
        check('pickup scheduling stays Phase 7', bm.call('/api/handovers.php', dict(action='schedule', id=obj['handover_id']))[0] == 501)
    check('manual report status stays out of scope', bm.call('/api/staff.php', dict(action='status', id=found))[0] == 501)
    check('claimant unread count grew', a.call('/api/notifications.php?count=1')[1]['unread_count'] >= 3)


def concurrency():
    a1 = Client(BASES[0]).login('student_a'); a2 = Client(BASES[1]).login('student_a')
    b = Client(BASES[0]).login('student_b'); c = Client(BASES[1]).login('student_c')
    bm1 = Client(BASES[0]).login('bm'); bm2 = Client(BASES[1]).login('bm')
    outcomes = {}

    # S1: two BMs approve competing claims on the same Found at the same time.
    for round_ in range(2):
        f = report(b, 'FOUND', 'Race approve ' + str(round_))
        ca = claim(a1, f)[1]['id']; cc = claim(c, f)[1]['id']
        ra, rc = detail(bm1, ca)['revision'], detail(bm2, cc)['revision']
        r = race(lambda: decide(bm1, ca, 'approved', ra), lambda: decide(bm2, cc, 'approved', rc))
        codes = sorted(x[0] for x in r)
        check('competing approvals: exactly one wins (round ' + str(round_) + ')', codes == [200, 409], [x[1] for x in r])
        s = state(); fid = int(f)
        approved = [x for x in s['claims'] if x['item_id'] == fid and x['claim_status'] == 'APPROVED']
        check('one approved owner and one handover', len(approved) == 1 and len([h for h in s['handovers'] if h['claim_id'] == approved[0]['claim_id']]) == 1)
        outcomes['competing_approvals_' + str(round_)] = codes

    # S2: withdraw races approval of the same claim.
    f = report(b, 'FOUND', 'Race withdraw approve'); ca = claim(a1, f)[1]['id']; rv = detail(bm2, ca)['revision']
    r = race(lambda: a1.call('/api/claims.php', dict(action='withdraw', itemId=f)), lambda: decide(bm2, ca, 'approved', rv))
    codes = [x[0] for x in r]
    check('withdraw vs approve: exactly one succeeds', sorted(codes) == [200, 409], [x[1] for x in r])
    s = state(); row = next(x for x in s['claims'] if x['claim_id'] == int(ca))
    check('withdraw vs approve: consistent final state', (row['claim_status'] == 'APPROVED') == any(h['claim_id'] == int(ca) for h in s['handovers']) and row['claim_status'] in ('APPROVED', 'WITHDRAWN'))
    outcomes['withdraw_vs_approve'] = row['claim_status']

    # S3: the same Lost used for two Found claims at once.
    l = report(a1, 'LOST', 'Race shared lost'); f1 = report(b, 'FOUND', 'Race shared lost F1'); f2 = report(b, 'FOUND', 'Race shared lost F2')
    r = race(lambda: claim(a1, f1, l), lambda: claim(a2, f2, l))
    codes = sorted(x[0] for x in r)
    check('one active claim per Lost under contention', codes == [201, 422], [x[1] for x in r])
    s = state(); check('only one active claim references the Lost', len([x for x in s['claims'] if x['related_lost_item_id'] == int(l) and x['claim_status'] in ('PENDING', 'NEED_MORE_INFO')]) == 1)

    # S4: duplicate submissions by the same claimant.
    f = report(b, 'FOUND', 'Race duplicate submit')
    r = race(lambda: claim(a1, f), lambda: claim(a2, f))
    check('duplicate submit: one 201 and one duplicate_claim', sorted(x[0] for x in r) == [201, 409] and any(x[1] and x[1].get('code') == 'duplicate_claim' for x in r), [x[1] for x in r])

    # S5: claim submission races deletion of the Found report.
    b2 = Client(BASES[1]).login('student_b')
    f = report(b, 'FOUND', 'Race delete vs submit')
    r = race(lambda: claim(a1, f), lambda: b2.call('/api/items.php', dict(action='delete', id=f)))
    submit_code, delete_code = r[0][0], r[1][0]
    check('submit vs delete: exactly one wins', (submit_code == 201 and delete_code == 409) or (delete_code == 200 and submit_code in (404, 409)), [x[1] for x in r])
    s = state(); exists = any(i['item_id'] == int(f) for i in s['items'])
    check('submit vs delete: no orphan claim', exists == (submit_code == 201) and (exists or not any(x['item_id'] == int(f) for x in s['claims'])))
    outcomes['submit_vs_delete'] = 'claim' if submit_code == 201 else 'deleted'

    # S6: resubmission with a new Lost link races a BM rejection using the pre-resubmission revision.
    f = report(b, 'FOUND', 'Race resubmit vs review'); ca = claim(a1, f)[1]['id']
    check('setup request more info', decide(bm1, ca, 'need_more_info', detail(bm1, ca)['revision'])[0] == 200)
    l3 = report(a1, 'LOST', 'Race relink lost'); rv = detail(bm2, ca)['revision']
    r = race(lambda: claim(a1, f, l3), lambda: decide(bm2, ca, 'rejected', rv))
    codes = [x[0] for x in r]
    check('resubmit vs review: never both applied', sorted(codes) == [200, 409], [x[1] for x in r])
    s = state(); row = next(x for x in s['claims'] if x['claim_id'] == int(ca))
    check('resubmit vs review: legal final state', (row['claim_status'], tuple(codes)) in {('PENDING', (200, 409)), ('REJECTED', (409, 200))}, (row, codes))
    outcomes['resubmit_vs_review'] = row['claim_status']

    s = state()
    check('global invariant: one approved claim per Found', s['approved_per_found'] == [])
    check('global invariant: one confirmed pair per report', s['confirmed_per_report'] == [])
    check('global invariant: handovers only for approved claims', all(next(x for x in s['claims'] if x['claim_id'] == h['claim_id'])['claim_status'] == 'APPROVED' for h in s['handovers']))
    if not PICKUP:
        check('global invariant: every handover pending', all(h['handover_status'] == 'PENDING' for h in s['handovers']))
    return outcomes


if __name__ == '__main__':
    try:
        contract(); outcomes = concurrency()
        print(json.dumps({'passed': True, 'checks': len(checks), 'race_outcomes': outcomes}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:600]})); sys.exit(1)
