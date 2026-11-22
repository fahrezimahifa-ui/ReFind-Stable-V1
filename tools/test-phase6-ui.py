"""Phase 6 browser journey on live phase6-dev (8016): Student claim with private photo, BM request-more-info,
Student resubmission, stale BM drawer refresh with draft kept, approval outcome, and withdrawal.
Synthetic data only; the caller restores the phase6-dev snapshot afterwards.
"""
from pathlib import Path
import io, json, os, sys
from urllib.parse import urlparse

import psutil
from PIL import Image
from playwright.sync_api import expect, sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from importlib import import_module
http = import_module('test-phase6-http')

ROOT = Path('C:/ReFind-Laravel'); BASE = http.BASES[0]; STATE = http.STATE
os.environ['TEMP'] = os.environ['TMP'] = str(STATE / 'tmp')
CREDS = http.CREDS
checks, errors, server_errors, forbidden_calls = [], [], [], []


def check(label, condition):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


def guard(route):
    url = route.request.url
    if url.startswith(('data:', 'blob:')) or url.startswith(BASE + '/'):
        if '/api/handovers.php' in url and not http.PICKUP:
            forbidden_calls.append(urlparse(url).path)
        route.continue_()
    else:
        forbidden_calls.append(url); route.abort()


def context(playwright, width, theme):
    # Pre-stage resource gate, then a fresh Chrome per phase so memory is returned between phases.
    if psutil.virtual_memory().available < 2 * 1024 ** 3:
        raise RuntimeError('Browser resource floor reached (2 GiB available RAM)')
    browser = playwright.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    ctx = browser.new_context(viewport={'width': width, 'height': 900}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
    ctx.route('**/*', guard)
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)[:200]))
    page.on('response', lambda r: server_errors.append(r.url) if r.status >= 500 else None)
    ctx.on('close', lambda _ctx: browser.close())
    return ctx, page


def login(page, key, landing):
    page.goto(BASE + '/login.html')
    page.locator('#email').fill(CREDS[key]['email']); page.locator('#password').fill(CREDS[key]['password'])
    page.locator('#login-submit').click()
    page.wait_for_url(lambda url: urlparse(url).path == '/' + landing)


def ready(page):
    page.locator('body[data-ready="true"]').wait_for()


def photo_file():
    path = STATE / 'tmp' / 'ui-proof.png'
    Image.new('RGB', (320, 240), (60, 120, 180)).save(path, format='PNG')
    return str(path)


with sync_playwright() as playwright:
    try:
        api_b = http.Client(BASE).login('student_b'); api_a = http.Client(BASE).login('student_a'); api_bm = http.Client(BASE).login('bm')
        found = http.report(api_b, 'FOUND', 'Browser blue backpack found')
        second = http.report(api_b, 'FOUND', 'Browser withdraw sample')

        # Student: item -> claim form -> private photo -> submit -> My Activity deep link.
        sctx, student = context(playwright, 390, 'dark')
        login(student, 'student_a', 'index.html')
        student.goto(BASE + '/item.html?id=' + found); ready(student)
        link = student.locator('#item-actions a[href*="claim.html"]')
        expect(link).to_have_text('I think this is mine'); link.click()
        student.wait_for_url(lambda u: urlparse(u).path == '/claim.html'); ready(student)
        expect(student.locator('#claim-form')).to_be_visible()
        student.locator('#c-answer').fill('Blue backpack with a yellow keyring and a torn left strap.')
        student.locator('details.private-card summary').click()
        student.locator('#c-proof').set_input_files(photo_file())
        expect(student.locator('#claim-upload .upload-previews img')).to_have_count(1)
        expect(student.locator('#claim-submit')).to_be_enabled()
        student.locator('#claim-submit').click()
        student.wait_for_url(lambda u: urlparse(u).path == '/my-reports.html' and 'claim=' in u); ready(student)
        claim_id = urlparse(student.url).query.split('claim=')[1].split('&')[0]
        card = student.locator('.activity-card[data-claim-id="' + claim_id + '"]')
        expect(card).to_be_visible(); expect(card).to_contain_text('1 private photo')
        expect(card.locator('button', has_text='Withdraw claim')).to_be_visible()
        check('student submitted a claim with a private photo through the UI', True)
        check('student layout has no horizontal overflow', not student.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1'))
        sctx.close()  # one browser context at a time keeps the 2 GiB RAM floor

        # BM: request more information from the review drawer.
        bctx, bm = context(playwright, 1440, 'light')
        login(bm, 'bm', 'admin.html')
        bm.goto(BASE + '/admin.html?tab=claims&claim=' + claim_id); ready(bm)
        actions = bm.locator('[data-bm-action]')
        expect(actions).to_have_count(3)
        # Two decision buttons; Request more info is a text button with the same dialog, opened here by keyboard.
        bar = bm.locator('.bm-detail-actions')
        check('claim decisions show two buttons, Approve first', bar.locator('.btn').count() == 2 and actions.first.get_attribute('data-bm-action') == 'approved')
        more = bm.locator('[data-bm-action="need_more_info"]')
        check('Request more info is a text button', more.evaluate("e => e.tagName === 'BUTTON' && e.classList.contains('text-button') && !e.classList.contains('btn')"))
        more.focus(); bm.keyboard.press('Enter')
        expect(bm.locator('#operation-dialog')).to_be_visible()
        bm.locator('#operation-note').fill('Please describe what is inside the front pocket.')
        bm.locator('#operation-submit').click()
        expect(bm.locator('#bm-feedback, .feedback').filter(has_text='More information requested from the claimant.').first).to_be_visible()
        check('BM requested more information through the drawer', True)
        bctx.close()

        # Student: resubmit keeping the saved private evidence.
        sctx, student = context(playwright, 390, 'dark')
        login(student, 'student_a', 'index.html')
        student.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim_id); ready(student)
        card = student.locator('.activity-card[data-claim-id="' + claim_id + '"]')
        expect(card).to_contain_text('Please describe what is inside the front pocket.')
        card.locator('a', has_text='Update claim').click()  # Stable round 3: was "Update / resubmit claim"
        student.wait_for_url(lambda u: urlparse(u).path == '/claim.html'); ready(student)
        expect(student.locator('#keep-claim-proof')).to_be_checked()
        student.locator('#c-answer').fill('Front pocket holds a green library card and a folded campus map.')
        student.locator('#claim-submit').click()
        student.wait_for_url(lambda u: urlparse(u).path == '/my-reports.html'); ready(student)
        expect(student.locator('.activity-card[data-claim-id="' + claim_id + '"]')).to_contain_text('1 private photo')
        check('student resubmitted on the same record keeping evidence', True)
        sctx.close()

        # BM: a stale drawer refuses the decision, keeps the draft, and refreshes on request.
        bctx, bm = context(playwright, 1440, 'light')
        login(bm, 'bm', 'admin.html')
        bm.goto(BASE + '/admin.html?tab=claims&claim=' + claim_id); ready(bm)
        expect(bm.locator('[data-bm-action="approved"]')).to_be_visible()
        detail = http.detail(api_bm, claim_id)
        status, body, _, _ = http.decide(api_bm, claim_id, 'need_more_info', detail['revision'], 'Concurrent synthetic request from another BM.')
        check('concurrent API decision applied', status == 200)
        status, body, _, _ = http.claim(api_a, found, answer='Front pocket holds a green library card and a folded campus map.')
        check('student resubmitted again behind the open drawer', status == 200)
        bm.locator('[data-bm-action="approved"]').click()
        bm.locator('#operation-note').fill('Ownership details verified by Building Management.')
        bm.locator('#operation-confirm').check(); bm.locator('#operation-submit').click()
        expect(bm.locator('#operation-feedback')).to_contain_text('This record changed')
        expect(bm.locator('#operation-refresh')).to_be_visible()
        check('stale BM drawer denied with refresh offered', bm.locator('#operation-note').input_value() == 'Ownership details verified by Building Management.')
        with bm.expect_response(lambda r: 'resource=claims&id=' + claim_id in r.url and r.status == 200):
            bm.locator('#operation-refresh').click()
        bm.wait_for_timeout(800)  # let the refreshed drawer render (smooth-scroll/focus settle)
        bm.locator('[data-bm-action="approved"]').click()
        if not bm.locator('#operation-note').input_value():
            bm.locator('#operation-note').fill('Ownership details verified by Building Management.')
        bm.locator('#operation-confirm').check(); bm.locator('#operation-submit').click()
        expect(bm.locator('#bm-feedback, .feedback').filter(has_text='Claim approved.').first).to_be_visible()
        check('pickup shortcut follows the pickup stage', (bm.locator('text=Schedule pickup now').count() > 0) == http.PICKUP)
        expect(bm.locator('[data-bm-action]')).to_have_count(0)
        check('BM layout has no horizontal overflow', not bm.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1'))
        bctx.close()

        # Student: approved outcome without pickup reads.
        sctx, student = context(playwright, 390, 'dark')
        login(student, 'student_a', 'index.html')
        student.goto(BASE + '/my-reports.html?tab=claims&claim=' + claim_id); ready(student)
        expect(student.locator('.activity-card[data-claim-id="' + claim_id + '"]')).to_contain_text('Approved. Building Management will set a pickup time and notify you.')  # pending handover (round 3 wording)
        student.goto(BASE + '/item.html?id=' + found); ready(student)
        expect(student.locator('#item-notice')).to_contain_text('Your claim: ')
        check('student sees the approved outcome', True)

        # Student: withdraw from My Activity.
        status, body, _, _ = http.claim(api_a, second)
        check('second claim created', status == 201)
        student.goto(BASE + '/my-reports.html?tab=claims&claim=' + body['id']); ready(student)
        student.locator('.activity-card[data-claim-id="' + body['id'] + '"] button', has_text='Withdraw claim').click()
        expect(student.locator('#withdraw-dialog')).to_be_visible(); student.locator('#confirm-withdraw').click()
        expect(student.locator('.feedback, #page-feedback, #shell-feedback').filter(has_text='Claim withdrawn').first).to_be_visible()
        check('student withdrew through the dialog', http.Client(BASE).login('student_a').call('/api/claims.php?id=' + body['id'])[1]['claims'][0]['claim_status'] == 'WITHDRAWN')
        sctx.close()
        check('no browser JavaScript errors', not errors)
        check('no server errors', not server_errors)
        check('no handover or foreign requests', not forbidden_calls)
        print(json.dumps({'passed': True, 'checks': len(checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:700], 'js_errors': errors[:3], 'server_errors': server_errors[:3], 'forbidden': forbidden_calls[:3]}))
        sys.exit(1)
