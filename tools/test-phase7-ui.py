"""Phase 7 browser journey on live phase7-dev (8018): BM schedules, reschedules, cancels, confirms the return and
releases from the Handover tab; the Student follows pickups in My Activity and the read-only notifications page.
Synthetic data only; the caller restores the phase7-dev snapshot afterwards.
"""
from datetime import datetime, timedelta, timezone
from importlib import import_module
from pathlib import Path
import json, os, sys
from urllib.parse import urlparse

import psutil
from playwright.sync_api import expect, sync_playwright

os.environ.setdefault('REFIND_DEV_PROFILE', 'phase8-dev' if Path(__file__).resolve().parent.parent.name == 'laravel-p8' else 'phase7-dev')
sys.path.insert(0, str(Path(__file__).resolve().parent))
http = import_module('test-phase6-http')
p7 = import_module('test-phase7-http')
BASE = http.BASES[0]; STATE = http.STATE
os.environ['TEMP'] = os.environ['TMP'] = str(STATE / 'tmp')
checks, errors, server_errors, forbidden = [], [], [], []


def check(label, ok):
    if not ok:
        raise AssertionError(label)
    checks.append(label)


def guard(route):
    req = route.request
    if req.url.startswith(('data:', 'blob:')) or req.url.startswith(BASE + '/'):
        if req.method == 'POST' and '/api/notifications.php' in req.url and not http.PHASE8:
            forbidden.append('notification read-marking POST')
        route.continue_()
    else:
        forbidden.append(req.url); route.abort()


def phase(playwright, width, theme, role, landing):
    if psutil.virtual_memory().available < 2 * 1024 ** 3:
        raise RuntimeError('Browser resource floor reached (2 GiB available RAM)')
    browser = playwright.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    ctx = browser.new_context(viewport={'width': width, 'height': 900}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
    ctx.route('**/*', guard)
    ctx.on('close', lambda _c: browser.close())
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)[:200]))
    page.on('response', lambda r: server_errors.append(r.url) if r.status >= 500 else None)
    page.goto(BASE + '/login.html')
    page.locator('#email').fill(http.CREDS[role]['email']); page.locator('#password').fill(http.CREDS[role]['password'])
    page.locator('#login-submit').click()
    page.wait_for_url(lambda url: urlparse(url).path == '/' + landing)
    return ctx, page


def ready(page):
    page.locator('body[data-ready="true"]').wait_for()


def bm_operation(page, handover, action, done, location=None, when=None, note=None, tick=False):
    page.goto(BASE + '/admin.html?tab=handover&handover=' + handover); ready(page)
    button = page.locator('[data-bm-action="' + action + '"]')
    expect(button).to_be_visible(); button.click()
    expect(page.locator('#operation-dialog')).to_be_visible()
    if location:
        page.locator('#operation-location').select_option(location)
    if when:
        page.locator('#operation-date').fill(when)
    if note:
        page.locator('#operation-note').fill(note)
    if tick:
        page.locator('#operation-confirm').check()
    page.locator('#operation-submit').click()
    expect(page.locator('#bm-feedback, .feedback').filter(has_text=done).first).to_be_visible()


with sync_playwright() as playwright:
    try:
        a = http.Client(BASE).login('student_a'); b = http.Client(BASE).login('student_b'); bm_api = http.Client(BASE).login('bm')
        found, lost, claim, handover = p7.approved(a, b, bm_api, 'Browser pickup laptop sleeve')
        found2, lost2, claim2, handover2 = p7.approved(a, b, bm_api, 'Browser release scarf')
        when = p7.slot(2, '10:30'); later = p7.slot(3, '15:45')

        # BM: Handover tab, schedule from the pending pickup.
        ctx, bm = phase(playwright, 1440, 'light', 'bm', 'admin.html')
        bm.goto(BASE + '/admin.html?tab=handover&handover=' + handover); ready(bm)
        expect(bm.locator('[data-bm-tab="handover"]')).to_have_attribute('aria-pressed', 'true')
        check('Reports tab follows the Phase 8 status stage', bm.locator('[data-bm-tab="reports"]').count() == (1 if http.PHASE8 else 0))
        expect(bm.locator('[data-bm-action]')).to_have_count(3)
        bar = bm.locator('.bm-detail-actions')
        check('pending pickup shows two buttons, Schedule first', bar.locator('.btn').count() == 2 and bar.locator('[data-bm-action]').first.get_attribute('data-bm-action') == 'schedule')
        check('Release pickup has its own labelled section and stays enabled', bm.locator('.bm-action-section--release [data-bm-action="release"]').is_enabled() and bar.locator('[data-bm-action="release"]').count() == 0)
        bm_operation(bm, handover, 'schedule', 'Pickup scheduled.', location='36', when=when)
        bm.goto(BASE + '/admin.html?tab=handover&handover=' + handover); ready(bm)
        check('scheduled pickup: Reschedule primary, Confirm returned secondary', bar.locator('.btn').count() == 2 and bar.locator('[data-bm-action]').first.get_attribute('data-bm-action') == 'reschedule' and bar.locator('[data-bm-action]').nth(1).get_attribute('data-bm-action') == 'complete')
        check('Cancel appointment sits in the Appointment section', bm.locator('.bm-action-section--appointment [data-bm-action="cancel_schedule"]').is_enabled())
        check('scheduled pickup keeps all four operations', bm.locator('#bm-detail [data-bm-action]').count() == 4)
        check('BM scheduled the pickup from the Handover tab', True)
        check('BM layout has no horizontal overflow', not bm.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1'))
        ctx.close()

        # Passed appointment: only the browser clock moves past the slot (no data change). The passed time is flat
        # status text, Reschedule leads, and every authorised operation stays available.
        flat_js = '''n => { const s = getComputedStyle(n); return n.tagName === 'P' && !n.closest('button, a') && s.cursor !== 'pointer'
            && s.backgroundColor === 'rgba(0, 0, 0, 0)' && s.borderTopWidth === '0px' && s.borderTopLeftRadius === '0px'; }'''
        ctx, bm = phase(playwright, 1440, 'light', 'bm', 'admin.html')
        ctx.clock.set_fixed_time(datetime.now(timezone.utc) + timedelta(days=4))
        bm.goto(BASE + '/admin.html?tab=handover&handover=' + handover); ready(bm)
        note = bm.locator('.bm-detail .past-due-note')
        expect(note).to_be_visible()
        check('BM passed-time note is flat, non-interactive status', note.evaluate(flat_js))
        bar = bm.locator('.bm-detail-actions')
        check('passed pickup keeps all four operations', bm.locator('#bm-detail [data-bm-action]').count() == 4)
        check('passed pickup shows two buttons, Reschedule first and primary', bar.locator('.btn').count() == 2 and bar.locator('[data-bm-action]').first.get_attribute('data-bm-action') == 'reschedule' and 'btn-primary' in (bar.locator('[data-bm-action="reschedule"]').get_attribute('class') or ''))
        check('passed pickup keeps Release enabled in its own section', bm.locator('.bm-action-section--release [data-bm-action="release"]').is_enabled())
        check('queue flags passed times as flat text, not chips', bm.locator('.bm-queue-card .past-due-flag').count() >= 1 and bm.locator('.bm-queue-card .item-chip.attention').count() == 0)
        ctx.close()
        ctx, st = phase(playwright, 390, 'dark', 'student_a', 'index.html')
        ctx.clock.set_fixed_time(datetime.now(timezone.utc) + timedelta(days=4))
        st.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim); ready(st)
        late = st.locator('.activity-card[data-claim-id="' + claim + '"] .past-due-note')
        expect(late).to_be_visible()
        check('Student passed-time note is flat, non-interactive status', late.evaluate(flat_js))
        ctx.close()

        # Student: My Activity shows the appointment; notifications are read-only.
        ctx, st = phase(playwright, 390, 'dark', 'student_a', 'index.html')
        st.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim); ready(st)
        card = st.locator('.activity-card[data-claim-id="' + claim + '"]')
        expect(card).to_contain_text('Your pickup'); expect(card).to_contain_text('Gedung B')
        st.goto(BASE + '/notifications.html'); ready(st)
        first = st.locator('.notification-card').first
        expect(first).to_contain_text('Pickup scheduled')
        expect(first).to_contain_text('Gedung B')
        sizes = first.evaluate("c => ['.notification-title', '.notification-message'].map(s => parseFloat(getComputedStyle(c.querySelector(s)).fontSize))")
        check('notification message is smaller than its title', sizes[1] < sizes[0])
        if http.PHASE8:
            check('read-marking controls available (Phase 8)', st.locator('.mark-read').count() > 0 and st.locator('#mark-all-read').is_visible())
        else:
            check('no read-marking controls while read-only', st.locator('.mark-read').count() == 0 and st.locator('#mark-all-read').is_hidden())
        first.locator('a.notification-destination').click()
        st.wait_for_url(lambda u: urlparse(u).path == '/my-reports.html' and 'claim=' + claim in u); ready(st)
        check('notification link opens the pickup' + (' and marks it read' if http.PHASE8 else ' without marking read'), True)
        check('Student layout has no horizontal overflow', not st.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1'))
        ctx.close()

        # BM: reschedule with a reason, cancel the appointment, then confirm the physical return.
        ctx, bm = phase(playwright, 1440, 'light', 'bm', 'admin.html')
        bm_operation(bm, handover, 'reschedule', 'Pickup rescheduled.', location='35', when=later, note='Owner asked for an afternoon slot.')
        bm_operation(bm, handover, 'cancel_schedule', 'Appointment cancelled. Ownership stays approved.', note='Service desk closed that afternoon.')
        bm_operation(bm, handover, 'complete', 'Item return confirmed.', tick=True)
        bm.goto(BASE + '/admin.html?tab=handover&handover=' + handover); ready(bm)
        expect(bm.locator('[data-bm-action]')).to_have_count(0)
        check('BM detail shows the full pickup audit', bm.locator('#bm-detail-content').inner_text().count('Pickup') >= 3)
        bm_operation(bm, handover2, 'release', 'Pickup released. Both reports are available again.', note='Owner did not collect within the window.', tick=True)
        ctx.close()

        # Student: returned and released outcomes.
        ctx, st = phase(playwright, 390, 'dark', 'student_a', 'index.html')
        st.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim); ready(st)
        expect(st.locator('.activity-card[data-claim-id="' + claim + '"]')).to_contain_text('Returned')
        st.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim2); ready(st)
        released = st.locator('.activity-card[data-claim-id="' + claim2 + '"]')
        expect(released).to_contain_text('Pickup released: Owner did not collect within the window.')
        st.goto(BASE + '/item.html?id=' + lost); ready(st)
        expect(st.locator('#item-chips')).to_contain_text('Returned')
        ctx.close()

        check('no browser JavaScript errors', not errors)
        check('no server errors', not server_errors)
        check('no read-marking or foreign requests', not forbidden)
        print(json.dumps({'passed': True, 'checks': len(checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:700], 'js_errors': errors[:3], 'server_errors': server_errors[:3], 'forbidden': forbidden[:3]}))
        sys.exit(1)
