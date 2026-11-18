from pathlib import Path
import json
import os
from urllib.parse import urlparse

import psutil
from playwright.sync_api import expect, sync_playwright
from verification_receipt import complete_receipt

ROOT = Path('C:/ReFind-Laravel')
BASE = 'http://127.0.0.1:8014'
os.environ['TEMP'] = os.environ['TMP'] = str(ROOT/'state/academic-test/tmp')
credentials = json.loads((ROOT/'state/academic-test/credentials.json').read_text())
checks, errors, unexpected = [], [], []


def check(label, condition):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


def route_claim_reads(route):
    url = route.request.url
    if url.startswith(('data:', 'blob:')):
        route.continue_()
    elif url.startswith(BASE + '/') and (route.request.method == 'GET' or url == BASE + '/api/auth.php'):
        route.continue_()
    else:
        unexpected.append((route.request.method, urlparse(url).path))
        route.abort()


def login(page, role, landing):
    page.goto(BASE + '/login.html')
    page.locator('#email').fill(credentials[role]['email'])
    page.locator('#password').fill(credentials[role]['password'])
    page.locator('#login-submit').click()
    page.wait_for_url(lambda url: urlparse(url).path == '/' + landing)


def ready(page):
    page.locator('body[data-ready="true"]').wait_for()


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    terminal_id = None
    try:
        for role, landing, width, theme in [('student_b', 'index.html', 390, 'dark'), ('bm', 'admin.html', 1440, 'light'), ('bm', 'admin.html', 390, 'dark')]:
            if psutil.virtual_memory().available < 2 * 1024**3:
                raise RuntimeError('Claim read browser resource floor reached')
            context = browser.new_context(viewport={'width': width, 'height': 844}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
            context.route('**/*', route_claim_reads)
            page = context.new_page()
            page.on('pageerror', lambda _error: errors.append('browser JavaScript exception'))
            try:
                login(page, role, landing)
                if role == 'student_b':
                    page.goto(BASE + '/my-reports.html')
                    ready(page)
                    page.locator('[data-activity="claims"]').click()
                    terminal = page.locator('article[data-claim-id]').filter(has_text='Synthetic terminal review note.')
                    expect(terminal).to_have_count(1)
                    terminal_id = terminal.get_attribute('data-claim-id')
                    check('Student owns visible terminal claim', terminal_id is not None)
                    page.goto(BASE + '/my-reports.html?tab=claims&claim=' + terminal_id)
                    ready(page)
                    card = page.locator(f'[data-claim-id="{terminal_id}"].selected-claim')
                    expect(card).to_contain_text('Withdrawn')
                    check('Student terminal deep link restores and focuses the selected card', card.evaluate('(el) => document.activeElement === el'))
                    check('Student view has no claim write controls or private proof link', card.locator('button, a[href*="claim.html"], a[href*="photos.php"]').count() == 0)
                    page.goto(BASE + '/my-reports.html?tab=claims&claim=1')
                    ready(page)
                    expect(page.get_by_text('The linked claim is unavailable.')).to_be_visible()
                    check('Student foreign deep link never renders the requested claim', page.locator('[data-claim-id="1"]').count() == 0)
                    page.goto(BASE + '/item.html?id=1')
                    ready(page)
                    check('Student item UI does not link to an unavailable claim form', page.locator('a[href*="claim.html"]').count() == 0)
                else:
                    ready(page)
                    check(f'BM active filter defaults on {width}px', page.locator('#bm-state').input_value() == 'active')
                    expect(page.locator('[data-record-id="1"]')).to_be_visible()
                    page.locator('[data-record-id="1"]').click()
                    detail = page.locator('[data-detail-claim-id="1"]')
                    expect(detail).to_be_visible()
                    detail.get_by_role('button', name='Enlarge Private claim evidence 1').click()
                    dialog = page.locator('#bm-evidence-dialog[open]')
                    expect(dialog).to_be_visible()
                    expect(dialog.locator('img')).to_have_js_property('complete', True)
                    check(f'BM private proof loads {width}px', dialog.locator('img').evaluate('(el) => el.complete && el.naturalWidth > 0'))
                    dialog.get_by_role('button', name='Close').click()
                    page.goto(BASE + '/admin.html?tab=claims&claim=' + terminal_id)
                    ready(page)
                    expect(page.locator(f'[data-detail-claim-id="{terminal_id}"]')).to_contain_text('Withdrawn')
                    expect(page.locator('#bm-selection-note')).to_be_visible()
                    check(f'BM terminal deep link works outside active queue {width}px', page.locator('#bm-state').input_value() == 'active')
                    check(f'BM has no decision, pickup or report operation controls {width}px', page.locator('#operation-form, [data-bm-action], [data-bm-tab="handover"], [data-bm-tab="reports"]').count() == 0)
                    if width < 1100:
                        page.locator('#bm-back').click()
                        expect(page.locator('[data-mobile-view="queue"]')).to_be_visible()
                        check('BM mobile back returns to the queue', page.locator('[data-record-id="1"]').is_visible())
                    else:
                        page.locator('#bm-state').select_option('WITHDRAWN')
                        expect(page.locator(f'[data-record-id="{terminal_id}"]')).to_be_visible()
                        page.locator('#bm-search').fill('6B candidate does not exist')
                        expect(page.get_by_text('Queue is clear')).to_be_visible()
                        check('BM terminal status filter and empty search work', page.locator('.bm-queue-card').count() == 0)
                check(f'{role} read view fits {width}px', page.evaluate('() => document.documentElement.scrollWidth <= innerWidth'))
                check(f'{role} {theme} theme on {width}px', page.locator('html').get_attribute('data-theme') == theme)
            finally:
                context.close()
    finally:
        browser.close()

check('no JavaScript errors', not errors)
check('no external/native traffic or business writes', not unexpected)
print(json.dumps({'claim_read_browser_checks_passed': len(checks)}))
complete_receipt(len(checks), {'checks': checks, 'scope': 'synthetic read-only claim browser views'})
