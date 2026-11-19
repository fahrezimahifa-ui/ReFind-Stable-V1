"""Phase 8 browser journey on live phase8-dev (8020): guest self-registration, profile name and password change,
notification read-marking, campus history, and BM manual report status from the Reports tab.
Synthetic data only; the caller restores the snapshot and limiter state afterwards.
"""
from importlib import import_module
from pathlib import Path
import json, os, secrets, sys
from urllib.parse import urlparse

import psutil
from playwright.sync_api import expect, sync_playwright

os.environ.setdefault('REFIND_DEV_PROFILE', 'phase8-dev')
sys.path.insert(0, str(Path(__file__).resolve().parent))
http = import_module('test-phase6-http')
BASE = http.BASES[0]; STATE = http.STATE
os.environ['TEMP'] = os.environ['TMP'] = str(STATE / 'tmp')
checks, errors, server_errors, foreign = [], [], [], []


def check(label, ok):
    if not ok:
        raise AssertionError(label)
    checks.append(label)


def guard(route):
    url = route.request.url
    if url.startswith(('data:', 'blob:')) or url.startswith(BASE + '/'):
        route.continue_()
    else:
        foreign.append(url); route.abort()


def browser_ctx(playwright, width, theme):
    if psutil.virtual_memory().available < 2 * 1024 ** 3:
        raise RuntimeError('Browser resource floor reached (2 GiB available RAM)')
    browser = playwright.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    ctx = browser.new_context(viewport={'width': width, 'height': 900}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
    ctx.route('**/*', guard)
    ctx.on('close', lambda _c: browser.close())
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)[:200]))
    page.on('response', lambda r: server_errors.append(r.url) if r.status >= 500 else None)
    return ctx, page


def login(page, email, password, landing):
    page.goto(BASE + '/login.html')
    page.locator('#email').fill(email); page.locator('#password').fill(password)
    page.locator('#login-submit').click()
    page.wait_for_url(lambda url: urlparse(url).path == '/' + landing)


def ready(page):
    page.locator('body[data-ready="true"]').wait_for()


def overflow(page):
    return page.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1')


with sync_playwright() as playwright:
    try:
        b = http.Client(BASE).login('student_b'); bm_api = http.Client(BASE).login('bm')
        found = http.report(b, 'FOUND', 'Browser phase eight stapler', location='39', category='6')
        email = 'ui-' + secrets.token_hex(4) + '@refind-poc.invalid'; password = 'Synthetic pass 12345'; newpw = 'Synthetic new pass 678'

        # Guest registration (main ReFind enables it on loopback; native registration_local_only).
        ctx, page = browser_ctx(playwright, 390, 'dark')
        page.goto(BASE + '/register.html'); expect(page.locator('#register-form')).to_be_visible()
        page.locator('#full-name').fill('Synthetic Browser Student'); page.locator('#email').fill(email)
        page.locator('#password').fill(password); page.locator('#confirm-password').fill(password + 'x')
        page.locator('#register-submit').click()
        expect(page.locator('#confirm-password-error')).to_be_visible()
        page.locator('#confirm-password').fill(password); page.locator('#register-submit').click()
        page.wait_for_url(lambda u: urlparse(u).path == '/index.html'); ready(page)
        check('guest registered and landed signed in', True)
        check('registration layout has no horizontal overflow', not overflow(page))

        # Profile name, then password change on Settings.
        page.goto(BASE + '/profile.html'); ready(page)
        expect(page.locator('#profile-email')).to_have_value(email)
        page.locator('#display-name').fill('Synthetic Renamed Browser'); page.locator('#profile-save').click()
        expect(page.locator('#profile-feedback')).to_contain_text('Your display name has been saved.')
        page.goto(BASE + '/settings.html'); ready(page)
        page.locator('#current-password').fill(password); page.locator('#new-password').fill(newpw); page.locator('#confirm-password').fill(newpw)
        page.locator('#password-save').click()
        expect(page.locator('#password-feedback')).to_contain_text('Password updated')
        check('profile name and password changed through the UI', True)
        ctx.close()
        ctx, page = browser_ctx(playwright, 1440, 'light')
        login(page, email, newpw, 'index.html')
        check('new password signs in', True)
        page.goto(BASE + '/history.html'); ready(page)
        expect(page.locator('[data-history="returned"]')).to_be_visible()
        check('campus history page renders', page.locator('#history-results').count() == 1)
        ctx.close()

        # BM: Reports tab manual status; the finder then reads and marks the operational note.
        ctx, page = browser_ctx(playwright, 1440, 'light')
        login(page, http.CREDS['bm']['email'], http.CREDS['bm']['password'], 'admin.html')
        page.goto(BASE + '/admin.html?tab=reports&report=' + found); ready(page)
        expect(page.locator('[data-bm-tab="reports"]')).to_have_attribute('aria-pressed', 'true')
        page.locator('[data-bm-action="status"]').click()
        expect(page.locator('#operation-dialog')).to_be_visible()
        page.locator('#operation-status').select_option('UNDER_REVIEW')
        page.locator('#operation-note').fill('Checking the desk log for this stapler.')
        if page.locator('#operation-confirm').is_visible():
            page.locator('#operation-confirm').check()
        page.locator('#operation-submit').click()
        expect(page.locator('#bm-feedback, .feedback').filter(has_text='Report status updated.').first).to_be_visible()
        check('BM changed a report status from the Reports tab', http.Client(BASE).login('bm').call('/api/staff.php?resource=reports&id=' + found)[1]['report']['status_code'] == 'UNDER_REVIEW')
        check('BM layout has no horizontal overflow', not overflow(page))
        ctx.close()

        ctx, page = browser_ctx(playwright, 390, 'dark')
        login(page, http.CREDS['student_b']['email'], http.CREDS['student_b']['password'], 'index.html')
        page.goto(BASE + '/notifications.html'); ready(page)
        first = page.locator('.notification-card').first
        expect(first).to_contain_text('Report updated: Under review')
        expect(page.locator('#mark-all-read')).to_be_visible()
        page.locator('#mark-all-read').click()
        expect(page.locator('#shell-feedback, .feedback').filter(has_text='All notifications are marked as read.').first).to_be_visible()
        check('notifications marked read through the UI', http.Client(BASE).login('student_b').call('/api/notifications.php?count=1')[1]['unread_count'] == 0)
        ctx.close()

        check('no browser JavaScript errors', not errors)
        check('no server errors', not server_errors)
        check('no foreign requests', not foreign)
        print(json.dumps({'passed': True, 'checks': len(checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:700], 'js_errors': errors[:3], 'server_errors': server_errors[:3], 'foreign': foreign[:3]}))
        sys.exit(1)
