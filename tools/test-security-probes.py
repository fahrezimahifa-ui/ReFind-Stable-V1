"""Live security probes on a development profile (default phase8-dev, worker 8020). Synthetic data only.

Injection and traversal inputs, private storage paths, security headers, CSRF/origin and method override,
role and object-level isolation, error-response leakage, and a private-log scan for synthetic secrets.
"""
from importlib import import_module
from pathlib import Path
import json, os, re, sys

os.environ.setdefault('REFIND_DEV_PROFILE', 'phase8-dev')
sys.path.insert(0, str(Path(__file__).resolve().parent))
h = import_module('test-phase6-http')
check, Client, BASE = h.check, h.Client, h.BASES[0]
LEAK = re.compile(r'SQLSTATE|Stack trace|vendor[\\/]laravel|C:\\\\|C:/ReFind|password_hash|PDOException|#0 ', re.I)


def no_leak(label, raw):
    check(label + ' leaks no internals', not LEAK.search(raw.decode('utf-8', 'replace')))


def run():
    a = Client(BASE).login('student_a'); b = Client(BASE).login('student_b'); bm = Client(BASE).login('bm'); guest = Client(BASE)
    secret_answer = 'Synthetic secret answer marker 7f3a9c'
    found = h.report(b, 'FOUND', 'Security probe umbrella', location='40', category='8')
    code, obj, _, _ = h.claim(a, found, answer=secret_answer)
    check('probe claim created', code == 201, obj); claim = obj['id']

    # Injection and malformed identifiers never reach SQL as code and never 500.
    for path in ("/api/items.php?q=' OR 1=1 -- ", '/api/items.php?q=%27%3B%20DROP%20TABLE%20items%3B--', "/api/claims.php?id=1 OR 1=1", '/api/claims.php?id=-1', '/api/claims.php?id=99999999999',
                 "/api/staff.php?resource=claims&q=%27%20UNION%20SELECT%20password_hash%20FROM%20users--", "/api/staff.php?resource=reports&status=OPEN'--", "/api/handovers.php?q=' OR '1'='1", '/api/notifications.php?page=0', '/api/items.php?limit=100000'):
        client = bm if 'staff.php' in path else a
        code, obj, _, raw = client.call(path.replace(' ', '%20'))
        check('no 500 for ' + path, code < 500)
        no_leak(path, raw)
        if 'UNION' in path:
            check('UNION payload returns no password material', b'$2y$' not in raw)
    code, obj, _, raw = bm.call("/api/staff.php?resource=claims&q=%27%20OR%20%271%27%3D%271")
    check('quoted search is literal (no matches)', code == 200 and obj['total'] == 0)

    # Traversal and private storage paths.
    for path in ('/api/photos.php?kind=claim&id=..%2F..%2Fstate', '/api/photos.php?kind=../../x&id=1', '/api/avatar.php?token=..%2F..%2Fprofile', '/api/avatar.php?token=' + 'a' * 32,
                 '/../state/phase8-dev/profile.json', '/state/phase8-dev/credentials.json', '/storage/app/private/refind/claims/x.png', '/.env', '/tools/server.php', '/vendor/autoload.php', '/index.php/../../state', '/composer.json'):
        code, _, _, raw = a.call(path)
        check('private path refused ' + path, code in (400, 403, 404))
        check('no credential bytes served ' + path, b'password' not in raw.lower() or code >= 400)

    # Security headers on pages and JSON.
    _, _, headers, _ = a.call('/my-reports.html')
    check('page CSP and framing headers', "default-src 'self'" in headers.get('Content-Security-Policy', '') and headers.get('X-Frame-Options') == 'DENY' and headers.get('X-Content-Type-Options') == 'nosniff')
    _, _, headers, _ = a.call('/api/claims.php')
    check('API responses are not cacheable', 'no-store' in headers.get('Cache-Control', '') and headers.get('X-Content-Type-Options') == 'nosniff')

    # CSRF, origin and method override.
    check('missing CSRF refused', a.call('/api/claims.php', dict(action='withdraw', itemId=found), token=False)[0] == 403)
    check('cross-origin POST refused', a.call('/api/claims.php', dict(action='withdraw', itemId=found), headers={'Origin': 'https://attacker.invalid'})[0] == 403)
    # Laravel honours _method/X-HTTP-Method-Override (accepted since Phase 5): overrides only ever deny (405).
    # Native ignores the field and answers 400 'Unsupported request field.'; recorded as a parity difference.
    check('DELETE not allowed', a.call('/api/claims.php', method='DELETE')[0] == 405)
    check('method override cannot perform the action', a.call('/api/claims.php?_method=DELETE', dict(action='withdraw', itemId=found), headers={'X-HTTP-Method-Override': 'DELETE'})[0] in (400, 405)
          and a.call('/api/claims.php', dict(action='withdraw', itemId=found, _method='GET'))[0] in (400, 405)
          and a.call('/api/claims.php?id=' + claim)[1]['claims'][0]['claim_status'] == 'PENDING')
    check('guest mutations need a session', guest.call('/api/account.php', dict(action='update_profile', name='x'), token=False)[0] == 401)

    # Role and object-level isolation.
    check('Student cannot read the BM queue', a.call('/api/staff.php?resource=claims')[0] == 403)
    check('Student cannot review', a.call('/api/staff.php', dict(action='review', id=claim))[0] == 403)
    check('Student cannot change pickups', a.call('/api/handovers.php', dict(action='complete', id='1'))[0] == 403)
    check('BM cannot submit claims', bm.call('/api/claims.php', dict(itemId=found, answer='x' * 10))[0] == 403)
    check('foreign claim indistinguishable from missing', b.call('/api/claims.php?id=' + claim)[0] == 404)
    code, _, _, raw = b.call('/api/claims.php?itemId=' + found)
    check('finder never sees the ownership answer', code == 200 and secret_answer.encode() not in raw)
    proof = bm.call('/api/staff.php?resource=claims&id=' + claim)[1]['claim']
    check('BM sees the answer for review', proof['ownership_answer'] == secret_answer)
    notes = b.call('/api/notifications.php')[1]['notifications']
    check('notification list scoped to recipient', all('Security probe' not in (n.get('message') or '') or n['notification_type'] == 'STATUS_CHANGED' for n in notes))
    check('foreign notification cannot be marked', a.call('/api/notifications.php', dict(id=notes[0]['notification_id']))[0] == 404 if notes else True)
    check('account endpoint is self-only (no id parameter)', a.call('/api/account.php?id=' + h.CREDS['student_b']['id'])[0] == 400)

    # Error responses stay sanitized.
    for path, data in (('/api/claims.php', {'itemId': found, 'answer': '\xff\xfe' * 3}), ('/api/items.php', {'action': 'create', 'type': 'LOST', 'title': 'x' * 5000}), ('/api/staff.php', {'action': 'review', 'id': claim, 'decision': ['a'], 'note': 'x'})):
        client = bm if 'staff' in path else a
        code, _, _, raw = client.call(path, data)
        check('sanitized error for ' + path, code in (400, 403, 409, 422))
        no_leak('error ' + path, raw)

    # Private logs never contain synthetic secrets (passwords, ownership answers).
    secrets_ = [c['password'] for c in h.CREDS.values()] + [secret_answer]
    hits = []
    for log in list((h.STATE / 'logs').glob('*')) + [h.STATE / 'php-error.log']:
        if log.is_file():
            text = log.read_text('utf-8', 'replace')
            hits += [log.name for s in secrets_ if s and s in text]
    check('no synthetic secrets in private logs', not hits)


if __name__ == '__main__':
    try:
        run()
        print(json.dumps({'passed': True, 'checks': len(h.checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(h.checks), 'error': str(e)[:600]})); sys.exit(1)
