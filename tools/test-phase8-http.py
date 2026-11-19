"""Phase 8 live HTTP checks on phase8-dev (8020): BM Reports tab and manual status, notification read-marking,
account self-service and loopback self-registration through the real server, session and CSRF.
Synthetic data only; the caller restores the snapshot and auth limiter state afterwards.
"""
from importlib import import_module
from pathlib import Path
import json, os, secrets, sys

os.environ.setdefault('REFIND_DEV_PROFILE', 'phase8-dev')
sys.path.insert(0, str(Path(__file__).resolve().parent))
h = import_module('test-phase6-http')
check, Client, BASE = h.check, h.Client, h.BASES[0]


def run():
    guest = Client(BASE)
    session = guest.call('/api/session.php')[1]
    check('session advertises loopback registration', session['registration_enabled'] is True)
    guest.token = session['csrf']
    email = 'phase8-' + secrets.token_hex(4) + '@refind-poc.invalid'
    form = dict(name='Synthetic Phase Eight Student', email=email.upper(), password='Synthetic pass 12345', confirm_password='Synthetic pass 12345')
    code, obj, _, _ = guest.call('/api/register.php', dict(form, confirm_password='mismatch value 1'))
    check('registration validation 422', code == 422 and 'confirm_password' in obj['fields'], obj)
    code, obj, _, _ = guest.call('/api/register.php', form)
    check('registration 201 and signed in', code == 201 and obj['user']['role'] == 'STUDENT' and obj['redirect'] == '/index.html', obj)
    guest.token = obj['csrf']
    check('new Student session active', guest.call('/api/session.php')[1]['authenticated'] is True)
    code, obj, _, _ = guest.call('/api/register.php', form)
    check('already authenticated 409', code == 409 and obj['code'] == 'already_authenticated', obj)
    other = Client(BASE); other.token = other.call('/api/session.php')[1]['csrf']
    code, obj, _, _ = other.call('/api/register.php', dict(form, email=email))
    check('duplicate email 409 registration_conflict', code == 409 and obj['code'] == 'registration_conflict', obj)

    profile = guest.call('/api/account.php')[1]['profile']
    check('account profile', profile['email'] == email and profile['role'] == 'STUDENT')
    code, obj, _, _ = guest.call('/api/account.php', dict(action='update_profile', name='Synthetic Renamed Eight'))
    check('display name saved', code == 200 and obj['profile']['name'] == 'Synthetic Renamed Eight', obj)
    code, obj, _, _ = guest.call('/api/account.php', dict(action='change_password', current_password='Synthetic pass 12345', new_password='Synthetic new pass 678', confirm_password='Synthetic new pass 678'))
    check('password changed with CSRF rotation', code == 200 and obj['csrf'] != guest.token, obj)
    old_token = guest.token; guest.token = obj['csrf']
    stale = Client(BASE); stale.http = guest.http; stale.token = old_token
    check('old CSRF token refused after rotation', stale.call('/api/account.php', dict(action='update_profile', name='Nope'))[0] == 403)
    relog = Client(BASE); relog.token = relog.call('/api/session.php')[1]['csrf']
    code, obj, _, _ = relog.call('/api/auth.php', dict(action='login', email=email, password='Synthetic new pass 678'))
    check('login with the new password', code == 200, obj)

    a = Client(BASE).login('student_a'); b = Client(BASE).login('student_b'); bm = Client(BASE).login('bm')
    found = h.report(b, 'FOUND', 'Phase eight desk lamp')
    queue = bm.call('/api/staff.php?q=Phase+eight+desk+lamp')[1]
    check('BM report queue (default resource)', queue['total'] == 1 and queue['reports'][0]['allowed_statuses'] == ['UNDER_REVIEW', 'UNCLAIMED_CLOSED'])
    detail = bm.call('/api/staff.php?resource=reports&id=' + found)[1]['report']
    code, obj, _, _ = bm.call('/api/staff.php', dict(action='status', id=found, status='UNDER_REVIEW', note='Checking the desk log for this lamp.', revision=detail['revision'], confirm='1'))
    check('manual status 200', code == 200 and obj == {'ok': True, 'id': found, 'status': 'UNDER_REVIEW'}, obj)
    check('stale manual status 409', bm.call('/api/staff.php', dict(action='status', id=found, status='OPEN', note='Synthetic replay note.', revision=detail['revision'], confirm='1'))[0] == 409)
    check('Student cannot change report status', a.call('/api/staff.php', dict(action='status', id=found))[0] == 403)
    notes = b.call('/api/notifications.php?unread=1')[1]
    op_note = next(n for n in notes['notifications'] if n['notification_type'] == 'OPERATIONAL_NOTE')
    check('finder receives the operational note', op_note['title'] == 'Report updated: Under review' and op_note['message'] == 'Checking the desk log for this lamp.')
    code, obj, _, _ = b.call('/api/notifications.php', dict(id=op_note['notification_id']))
    check('mark one read', code == 200 and obj['unread_count'] == notes['unread_count'] - 1, obj)
    check('foreign notification 404', a.call('/api/notifications.php', dict(id=op_note['notification_id']))[0] == 404)
    code, obj, _, _ = b.call('/api/notifications.php', dict(action='read_all'))
    check('mark all read', code == 200 and obj['unread_count'] == 0, obj)
    for path in ('/profile.html', '/settings.html', '/history.html', '/notifications.html'):
        check('page served ' + path, a.call(path)[0] == 200)
    check('BM Reports tab served', bm.call('/admin.html?tab=reports&report=' + found)[0] == 200)


if __name__ == '__main__':
    try:
        run()
        print(json.dumps({'passed': True, 'checks': len(h.checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(h.checks), 'error': str(e)[:600]})); sys.exit(1)
