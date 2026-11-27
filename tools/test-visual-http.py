"""Photo search (Stable round 5) live HTTP checks on phase8-dev (8020), offline fake engine only.

Run inside stable-tools/p8_envelope.py with REFIND_VISUAL_PROVIDER=fake in the environment, so the server and the indexer
use the offline fake engine (no provider call is possible) and the snapshot, media and limiter files are restored.
Covers: the gate and session flags, roles, CSRF, photo validation, ranking with the matched photo first and a label,
honest no-match, BM Reports-tab search and its filters, and that no score ever reaches the browser.
"""
import io, json, os, secrets, subprocess, sys, urllib.error, urllib.parse, urllib.request, http.cookiejar
from pathlib import Path
from PIL import Image

ROOT = Path('C:/ReFind-Laravel'); APP = ROOT / 'laravel-p8'; STATE = ROOT / 'state/phase8-dev'; BASE = 'http://127.0.0.1:8020'
CREDS = json.loads((STATE / 'credentials.json').read_text())
checks = []


def check(label, ok, detail=''):
    checks.append(label)
    if not ok:
        raise AssertionError(label + (' :: ' + str(detail)[:400] if detail else ''))


class Client:
    def __init__(self):
        self.token = ''; self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, path, data=None, files=None, token=True):
        h = {}; body = None
        if data is not None or files is not None:
            boundary = 'vs' + secrets.token_hex(12); chunks = []
            for k, v in (data or {}).items():
                chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode())
            for field, name, mime, payload in files or []:
                chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + field + '"; filename="' + name + '"\r\nContent-Type: ' + mime + '\r\n\r\n').encode() + payload + b'\r\n')
            chunks.append(('--' + boundary + '--\r\n').encode()); body = b''.join(chunks); h['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            if token:
                h['X-CSRF-Token'] = self.token
        req = urllib.request.Request(BASE + path, data=body, headers=h, method='POST' if body is not None else 'GET')
        try:
            r = self.http.open(req, timeout=30)
        except urllib.error.HTTPError as e:
            r = e
        raw = r.read(); obj = json.loads(raw) if raw and 'application/json' in r.headers.get('Content-Type', '') else None
        return r.code, obj, r.headers, raw

    def login(self, key):
        self.token = self.call('/api/session.php')[1]['csrf']
        body = urllib.parse.urlencode(dict(action='login', email=CREDS[key]['email'], password=CREDS[key]['password'])).encode()
        r = self.http.open(urllib.request.Request(BASE + '/api/auth.php', data=body, headers={'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': self.token}), timeout=30)
        obj = json.loads(r.read()); check('login ' + key, obj.get('authenticated') is True); self.token = obj['csrf']
        return self


def php(*args):
    env = dict(os.environ, REFIND_PROFILE='phase8-dev', PHP_INI_SCAN_DIR=str(ROOT / 'runtime/empty-ini-scan'))
    p = subprocess.run([str(ROOT / 'runtime/php85/php.exe'), '-c', str(ROOT / 'runtime/php85/php.ini'), str(APP / 'tools/visual-index.php'), *args], cwd=APP, env=env, capture_output=True, text=True, timeout=300)
    if p.returncode:
        raise RuntimeError('visual-index.php ' + args[0] + ' failed: ' + (p.stderr or p.stdout)[-300:])
    return json.loads(p.stdout.strip().splitlines()[-1])


def jpeg(color, size=(96, 72)):
    b = io.BytesIO(); Image.new('RGB', size, color).save(b, format='JPEG', quality=90); return b.getvalue()


def photo(payload, name='q.jpg', mime='image/jpeg'):
    return ('photo[]', name, mime, payload)


def run():
    check('fake engine only (no provider calls)', os.environ.get('REFIND_VISUAL_PROVIDER') == 'fake')
    status = php('status')
    check('fake engine configured for this run', status.get('provider') == 'fake' and status.get('calibration') == 'present', status)
    while php('status')['photos']['pending']:
        php('sync', '--limit', '200')
    check('every public photo indexed (fake engine)', php('status')['photos']['pending'] == 0)

    guest = Client(); guest.token = guest.call('/api/session.php')[1]['csrf']
    check('guest session flag off', guest.call('/api/session.php')[1]['visual_search_enabled'] is False)
    check('guest 401', guest.call('/api/visual-search.php', {}, [photo(jpeg((10, 10, 10)))])[0] == 401)

    student = Client().login('student_a')
    session = student.call('/api/session.php')[1]
    check('student session: Explore photo search on, BM off', session['visual_search_enabled'] is True and session['visual_staff_enabled'] is False, session)
    items = student.call('/api/items.php?active=1&limit=48')[1]['items']
    target = next(i for i in items if i['photos'] and str(i['reporter_user_id']) != str(session['user']['id']))
    raw_photo = student.call(target['photos'][0])[3]
    code, obj, _, raw = student.call('/api/visual-search.php', {}, [photo(raw_photo)])
    check('exact photo -> matches', code == 200 and obj['state'] == 'matches', obj)
    first = obj['items'][0]
    check('its report ranks first with its photo first', first['item_id'] == target['item_id'] and first['photos'][0] == target['photos'][0], first)
    check('very similar label', first['match_label'] == 'very_similar', first)
    check('no score reaches the browser', b'score' not in raw and b'verification_clue' not in raw)
    code, obj, _, _ = student.call('/api/visual-search.php', {}, [photo(jpeg((255, 0, 255)))])
    check('unrelated photo -> honest no match', code == 200 and obj['state'] == 'no_match' and obj['items'] == [], obj)
    code, obj, _, _ = student.call('/api/visual-search.php', {}, [photo(jpeg((255, 0, 255))), photo(raw_photo)])
    check('best match over two photos', code == 200 and obj['items'][0]['item_id'] == target['item_id'], obj)
    check('no photo 422', student.call('/api/visual-search.php', {'type': ''}, [])[0] in (400, 422))
    check('four photos 422', student.call('/api/visual-search.php', {}, [photo(raw_photo)] * 4)[0] == 422)
    check('not an image 422', student.call('/api/visual-search.php', {}, [photo(b'not an image at all')])[0] == 422)
    code, obj, _, _ = student.call('/api/visual-search.php', {'q': 'words'}, [photo(raw_photo)])
    check('unknown field 400', code == 400 and obj['code'] == 'invalid_request', obj)
    code, obj, _, _ = student.call('/api/visual-search.php', {}, [photo(raw_photo)], token=False)
    check('missing CSRF rejected', code == 403 and obj['code'] == 'csrf_invalid', obj)
    check('student blocked from BM endpoint', student.call('/api/staff-visual-search.php', {}, [photo(raw_photo)])[0] == 403)

    bm = Client().login('bm')
    session = bm.call('/api/session.php')[1]
    check('BM session: Reports-tab photo search on, Explore off', session['visual_staff_enabled'] is True and session['visual_search_enabled'] is False, session)
    check('BM blocked from student endpoint', bm.call('/api/visual-search.php', {}, [photo(raw_photo)])[0] == 403)
    code, obj, _, raw = bm.call('/api/staff-visual-search.php', {'type': '', 'status': 'all'}, [photo(raw_photo)])
    check('BM all reports -> target first', code == 200 and obj['state'] == 'matches' and obj['reports'][0]['item_id'] == target['item_id'], obj)
    check('BM card fields: label, revision, allowed statuses, one page', {'match_label', 'revision', 'allowed_statuses'} <= set(obj['reports'][0]) and obj['pages'] == 1, obj['reports'][0])
    check('BM response carries no score', b'score' not in raw)
    other = 'FOUND' if target['item_type'] == 'LOST' else 'LOST'
    code, obj, _, _ = bm.call('/api/staff-visual-search.php', {'type': other, 'status': 'all'}, [photo(raw_photo)])
    check('BM type filter excludes the target', code == 200 and all(r['item_id'] != target['item_id'] for r in obj['reports']), obj)
    code, obj, _, _ = bm.call('/api/staff-visual-search.php', {'type': 'BOTH'}, [photo(raw_photo)])
    check('BM invalid type 400', code == 400, obj)


if __name__ == '__main__':
    try:
        run()
        print(json.dumps({'passed': True, 'checks': len(checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:600]})); sys.exit(1)
