"""Phase 7 live HTTP contract and real two-worker pickup races on phase7-dev (8018 + 8019).

Prerequisites: phase6-control.py start and start --second (profile phase7-dev), phase6-dev.php seed-students and a
saved snapshot. Writes only synthetic phase7-dev data; the caller restores the snapshot afterwards.
"""
from datetime import datetime, timedelta, timezone
from importlib import import_module
from pathlib import Path
import json, os, sys

os.environ.setdefault('REFIND_DEV_PROFILE', 'phase8-dev' if Path(__file__).resolve().parent.parent.name == 'laravel-p8' else 'phase7-dev')
sys.path.insert(0, str(Path(__file__).resolve().parent))
h = import_module('test-phase6-http')
check, Client, BASES = h.check, h.Client, h.BASES
JAKARTA = timezone(timedelta(hours=7))  # Asia/Jakarta has no DST


def slot(days, hhmm):
    return (datetime.now(JAKARTA) + timedelta(days=days)).strftime('%Y-%m-%d') + 'T' + hhmm


def approved(a, b, bm, title, linked=True):
    found = h.report(b, 'FOUND', title + ' found')
    lost = h.report(a, 'LOST', title + ' lost') if linked else ''
    code, obj, _, _ = h.claim(a, found, lost)
    check('claim for ' + title, code == 201, obj)
    code, res, _, _ = h.decide(bm, obj['id'], 'approved', h.detail(bm, obj['id'])['revision'], 'Ownership details verified by Building Management.')
    check('approve ' + title, code == 200 and isinstance(res['handover_id'], str), res)
    return found, lost, obj['id'], res['handover_id']


def rev(bm, hid):
    code, obj, _, _ = bm.call('/api/handovers.php?id=' + hid)
    check('BM pickup detail ' + hid, code == 200, obj)
    return obj['handovers'][0]


def op(bm, hid, action, revision, **extra):
    return bm.call('/api/handovers.php', dict(action=action, id=hid, revision=revision, confirm='1', **extra))


def handover(state, hid):
    return next((x for x in state['handovers'] if x['handover_id'] == int(hid)), None)


def contract():
    a = Client(BASES[0]).login('student_a'); b = Client(BASES[0]).login('student_b'); bm = Client(BASES[0]).login('bm')
    found, lost, cid, hid = approved(a, b, bm, 'HTTP pickup keyboard')
    mine = a.call('/api/handovers.php')[1]
    check('claimant sees own pending pickup without contact or audit fields', mine['total'] >= 1 and all(k not in mine['handovers'][0] for k in ('claimant_email', 'revision', 'operations', 'audit')))
    check('finder sees no pickup', b.call('/api/handovers.php?id=' + hid)[0] == 404)
    check('Student cannot change a pickup', a.call('/api/handovers.php', dict(action='complete', id=hid, confirm='1'))[0] == 403)
    d = rev(bm, hid)
    check('BM pending operations', d['operations'] == ['schedule', 'complete', 'release'] and d['audit'] == [])
    code, obj, _, _ = op(bm, hid, 'schedule', d['revision'], pickup_location_id='36', scheduled_at=slot(2, '10:30'))
    check('schedule 200', code == 200 and obj == {'ok': True, 'id': hid, 'status': 'SCHEDULED'}, obj)
    check('stale schedule replay 409', op(bm, hid, 'schedule', d['revision'], pickup_location_id='36', scheduled_at=slot(2, '10:30'))[0] == 409)
    p = a.call('/api/handovers.php?id=' + hid)[1]['handovers'][0]
    check('claimant sees the appointment in UTC', p['handover_status'] == 'SCHEDULED' and p['pickup_location'] == 'Gedung B' and p['scheduled_at'].endswith('Z'))
    notes = a.call('/api/notifications.php')[1]
    first = notes['notifications'][0]
    check('claimant notification carries the structured pickup event', first['notification_type'] == 'HANDOVER_SCHEDULE' and first['pickup_event']['after']['status'] == 'SCHEDULED' and first['handover_id'] == hid)
    check('read-marking stays unavailable', a.call('/api/notifications.php', dict(action='read_all'))[0] == 501)
    d = rev(bm, hid)
    code, obj, _, _ = op(bm, hid, 'reschedule', d['revision'], pickup_location_id='35', scheduled_at=slot(3, '14:00'), note='Owner asked for a later slot.')
    check('reschedule 200', code == 200 and obj['status'] == 'SCHEDULED', obj)
    code, obj, _, _ = op(bm, hid, 'cancel_schedule', rev(bm, hid)['revision'], note='Desk closed for maintenance.')
    check('cancel appointment keeps ownership', code == 200 and obj['status'] == 'PENDING' and a.call('/api/claims.php?id=' + cid)[1]['claims'][0]['claim_status'] == 'APPROVED')
    code, obj, _, _ = op(bm, hid, 'complete', rev(bm, hid)['revision'])
    check('confirm return 200', code == 200 and obj['status'] == 'COMPLETED', obj)
    check('both reports returned', [c.call('/api/items.php?id=' + i)[1]['item']['status_code'] for c, i in ((b, found), (a, lost))] == ['RESOLVED', 'RESOLVED'])
    check('BM audit lists every pickup change', [e['pickup_event']['operation'] for e in rev(bm, hid)['audit']] == ['schedule', 'reschedule', 'cancel_schedule', 'complete'])
    check('completed pickup has no operations', rev(bm, hid)['operations'] == [])
    f2, l2, c2, h2 = approved(a, b, bm, 'HTTP release umbrella')
    code, obj, _, _ = op(bm, h2, 'release', rev(bm, h2)['revision'], note='Owner did not collect within the window.')
    check('release 200', code == 200 and obj['status'] == 'RELEASED', obj)
    check('released pickup is gone and the claim closed', bm.call('/api/handovers.php?id=' + h2)[0] == 404 and a.call('/api/claims.php?id=' + c2)[1]['claims'][0]['claim_status'] == 'REJECTED')
    check('released reports available again', [c.call('/api/items.php?id=' + i)[1]['item']['status_code'] for c, i in ((b, f2), (a, l2))] == ['OPEN', 'OPEN'])


def races():
    a = Client(BASES[0]).login('student_a'); b = Client(BASES[0]).login('student_b'); c = Client(BASES[1]).login('student_c')
    bm1 = Client(BASES[0]).login('bm'); bm2 = Client(BASES[1]).login('bm')
    outcomes = {}

    # P1: two BMs schedule the same pending pickup with the same revision.
    _, _, _, hid = approved(a, b, bm1, 'Race schedule twice')
    r0 = rev(bm1, hid)['revision']
    r = h.race(lambda: op(bm1, hid, 'schedule', r0, pickup_location_id='36', scheduled_at=slot(2, '09:00')), lambda: op(bm2, hid, 'schedule', r0, pickup_location_id='35', scheduled_at=slot(2, '15:00')))
    check('double schedule: exactly one wins', sorted(x[0] for x in r) == [200, 409], [x[1] for x in r])
    s = h.state(); row = handover(s, hid)
    check('double schedule: one consistent appointment', row['handover_status'] == 'SCHEDULED' and row['pickup_location_id'] in (35, 36))
    outcomes['double_schedule_location'] = row['pickup_location_id']

    # P2: confirm return races release of the same pickup.
    found, lost, cid, hid = approved(a, b, bm1, 'Race complete release')
    r0 = rev(bm1, hid)['revision']
    r = h.race(lambda: op(bm1, hid, 'complete', r0), lambda: op(bm2, hid, 'release', r0, note='Concurrent synthetic release.'))
    codes = [x[0] for x in r]
    # The loser gets 409, or native 404 when release (which deletes the handover row) ran first.
    check('complete vs release: exactly one wins', codes in ([200, 409], [409, 200], [404, 200]), [x[1] for x in r])
    s = h.state(); row = handover(s, hid); claim = next(x for x in s['claims'] if x['claim_id'] == int(cid))
    status = {i['item_id']: i['status_code'] for i in s['items']}
    if codes[0] == 200:
        check('return won: COMPLETED, owner kept, reports RESOLVED', row and row['handover_status'] == 'COMPLETED' and claim['claim_status'] == 'APPROVED' and status[int(found)] == status[int(lost)] == 'RESOLVED')
    else:
        check('release won: pickup removed, claim closed, reports OPEN', row is None and claim['claim_status'] == 'REJECTED' and status[int(found)] == status[int(lost)] == 'OPEN')
    outcomes['complete_vs_release'] = 'complete' if codes[0] == 200 else 'release'

    # P3: reschedule races cancellation of a scheduled pickup.
    _, _, _, hid = approved(a, b, bm1, 'Race reschedule cancel')
    check('setup schedule', op(bm1, hid, 'schedule', rev(bm1, hid)['revision'], pickup_location_id='36', scheduled_at=slot(2, '10:00'))[0] == 200)
    r0 = rev(bm1, hid)['revision']
    r = h.race(lambda: op(bm1, hid, 'reschedule', r0, pickup_location_id='35', scheduled_at=slot(4, '10:00'), note='Synthetic reschedule reason.'), lambda: op(bm2, hid, 'cancel_schedule', r0, note='Synthetic cancel reason text.'))
    codes = [x[0] for x in r]
    check('reschedule vs cancel: exactly one wins', sorted(codes) == [200, 409], [x[1] for x in r])
    row = handover(h.state(), hid)
    check('reschedule vs cancel: legal final state', row['handover_status'] == ('SCHEDULED' if codes[0] == 200 else 'PENDING'))
    outcomes['reschedule_vs_cancel'] = row['handover_status']

    # P4: release races a different Student's claim on the same Found report.
    found, _, _, hid = approved(a, b, bm1, 'Race release vs claim', linked=False)
    r0 = rev(bm2, hid)['revision']
    r = h.race(lambda: h.claim(c, found, answer='A different synthetic owner detail.'), lambda: op(bm2, hid, 'release', r0, note='Concurrent synthetic release.'))
    claim_code, release_code = r[0][0], r[1][0]
    check('release vs new claim: release always applies', release_code == 200, r[1][1])
    check('release vs new claim: claim only after availability', claim_code in (201, 409), r[0][1])
    s = h.state()
    check('release vs new claim: report state consistent', next(i for i in s['items'] if i['item_id'] == int(found))['status_code'] == ('VERIFICATION' if claim_code == 201 else 'OPEN'))
    outcomes['release_vs_claim'] = claim_code

    s = h.state()
    approved_ids = {x['claim_id'] for x in s['claims'] if x['claim_status'] == 'APPROVED'}
    check('invariant: handovers only for approved claims', all(x['claim_id'] in approved_ids for x in s['handovers']))
    check('invariant: one handover per claim', len({x['claim_id'] for x in s['handovers']}) == len(s['handovers']))
    check('invariant: one approved claim per Found', s['approved_per_found'] == [])
    check('invariant: one confirmed pair per report', s['confirmed_per_report'] == [])
    return outcomes


if __name__ == '__main__':
    try:
        contract(); outcomes = races()
        print(json.dumps({'passed': True, 'checks': len(h.checks), 'race_outcomes': outcomes}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(h.checks), 'error': str(e)[:600]})); sys.exit(1)
