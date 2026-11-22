"""Stable round 1 browser journey on live phase8-dev (8020): return navigation and form safety.

Creates synthetic Found reports through the API so Explore, My Activity and the BM Reports tab have several pages; the
caller restores the phase8-dev snapshot and limiter state afterwards. Checks:
- the item page's Back link and the browser Back button return to the exact list URL, scroll position and opened card
  (Explore at 1440 px light and 390 px dark), and the right list for History, Home, My Activity and the BM queue;
- BM search, status filter and page survive a reload; a fresh item URL falls back to "Back to Explore";
- after submitting a report, Back skips the spent form; a changed form asks before leaving, an untouched one does not;
- text typed before a session ended comes back after signing in (report form and BM decision note).
"""
import http.cookiejar, json, os, sys, urllib.error, urllib.parse, urllib.request
from pathlib import Path
from urllib.parse import urlparse

import psutil
from playwright.sync_api import expect, sync_playwright

ROOT = Path('C:/ReFind-Laravel'); PROFILE = os.environ.get('REFIND_DEV_PROFILE', 'phase8-dev')
STATE = ROOT / 'state' / PROFILE; BASE = {'phase8-dev': 'http://127.0.0.1:8020'}[PROFILE]
COOKIE = 'refind_laravel_p8_dev'
CREDS = json.loads((STATE / 'credentials.json').read_text(encoding='utf-8'))
os.environ['TEMP'] = os.environ['TMP'] = str(STATE / 'tmp')
SEEDED = 30
checks = []


def check(label, ok, detail=''):
    if not ok:
        raise AssertionError(label + (' :: ' + str(detail)[:300] if detail != '' else ''))
    checks.append(label)


class Client:
    def __init__(self):
        self.token = ''; self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        headers = {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': self.token} if data is not None else {}
        try:
            r = self.http.open(urllib.request.Request(BASE + path, data=body, headers=headers, method='POST' if data is not None else 'GET'), timeout=30)
        except urllib.error.HTTPError as e:
            r = e
        raw = r.read(); obj = json.loads(raw) if raw and 'json' in r.headers.get('Content-Type', '') else None
        if isinstance(obj, dict) and isinstance(obj.get('csrf'), str):
            self.token = obj['csrf']
        return r.code, obj

    def login(self, key):
        self.call('/api/session.php'); code, obj = self.call('/api/auth.php', {'action': 'login', 'email': CREDS[key]['email'], 'password': CREDS[key]['password']})
        check('API sign-in ' + key, code == 200, obj); return self


def seed():
    a = Client().login('student_a'); today = a.call('/api/references.php')[1]['campus_today']
    for n in range(1, SEEDED + 1):
        code, obj = a.call('/api/items.php', {'action': 'create', 'type': 'FOUND', 'title': 'Round1 navigation item %02d' % n, 'description': 'Synthetic report for the Stable round 1 navigation checks.',
                                              'category_id': '8', 'location_id': '40', 'location_detail': 'Shelf %d' % n, 'date': today, 'time': '', 'clue': 'Synthetic private clue %02d' % n,
                                              'keep_photo_ids': '[]', 'keep_ownership_ids': '[]'})
        if code != 201:
            raise AssertionError('seed report %d returned %s %s' % (n, code, obj))
    checks.append('seeded %d synthetic Found reports through the API' % SEEDED)


def path_query(url):
    u = urlparse(url); return u.path + ('?' + u.query if u.query else '')


class Session:
    def __init__(self, browser, width=1440, theme='light', key=None):
        if psutil.virtual_memory().available < 1536 * 1024 ** 2:
            raise RuntimeError('Browser resource floor reached (1.5 GiB available RAM)')
        self.ctx = browser.new_context(viewport={'width': width, 'height': 860}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
        self.page = self.ctx.new_page(); self.errors = []; self.dialogs = []; self.accept_dialogs = False
        self.page.on('pageerror', lambda e: self.errors.append(str(e)))
        self.page.on('console', lambda m: self.errors.append(m.text) if m.type == 'error' else None)
        self.page.on('dialog', self.on_dialog)
        if key:
            self.login(key)

    def on_dialog(self, dialog):
        self.dialogs.append(dialog.type)
        dialog.accept() if self.accept_dialogs else dialog.dismiss()

    def login(self, key, expect_path=None):
        p = self.page
        if urlparse(p.url).path != '/login.html':
            p.goto(BASE + '/login.html')
        p.locator('body[data-ready="true"], #login-submit').first.wait_for()
        p.locator('#email').fill(CREDS[key]['email']); p.locator('#password').fill(CREDS[key]['password']); p.locator('#login-submit').click()
        p.wait_for_url(lambda u: urlparse(u).path != '/login.html', timeout=20000)
        if expect_path:
            check('sign-in returns to ' + expect_path, urlparse(p.url).path == expect_path, p.url)
        ready(p)

    def close(self, label, ignore=()):
        bad = [e for e in self.errors if not any(i in e for i in ignore)]
        check(label + ': no JavaScript errors', not bad, bad[:3]); self.ctx.close()


def ready(page, settle=900):
    page.locator('body[data-ready="true"]').wait_for(timeout=20000); page.wait_for_timeout(settle)


def scroll_y(page):
    return page.evaluate('Math.round(scrollY)')


def opened_card(page):
    return page.evaluate('document.activeElement && document.activeElement.closest("[data-item-id]") ? document.activeElement.closest("[data-item-id]").getAttribute("data-item-id") : null')


def explore_return(browser, width, theme):
    s = Session(browser, width, theme, 'student_b'); p = s.page; tag = '%d %s' % (width, theme)
    p.goto(BASE + '/browse.html?type=FOUND'); ready(p)
    p.locator('#pager button', has_text='Next').click(); expect(p.locator('#pager span')).to_contain_text('Page 2 of'); p.wait_for_timeout(900)
    list_url = path_query(p.url); check(tag + ' Explore keeps page 2 and the Found filter in its URL', 'page=2' in list_url and 'type=FOUND' in list_url, list_url)
    p.evaluate('window.scrollTo({top: document.documentElement.scrollHeight, behavior: "instant"})'); p.wait_for_timeout(900)
    # Round 3: the seeded reports have no photo and now show a compact strip, so page 2 is shorter than before.
    y = scroll_y(p); check(tag + ' scrolled down the list', y > 150, y)
    card = p.locator('#browse-results .discovery-card').last; item = card.get_attribute('data-item-id')
    card.locator('a.card-details').click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    back = p.locator('#item-back')
    check(tag + ' item Back link says Back to Explore', back.inner_text().strip() == 'Back to Explore', back.inner_text())
    check(tag + ' item Back link targets the exact list URL', path_query(BASE + back.get_attribute('href')) == list_url, back.get_attribute('href'))
    back.click(); p.wait_for_url(lambda u: path_query(u) == list_url); ready(p)
    check(tag + ' Back link returns to Explore page 2 with the filter', path_query(p.url) == list_url, p.url)
    check(tag + ' Back link restores the scroll position', abs(scroll_y(p) - y) <= 40, (scroll_y(p), y))
    check(tag + ' Back link focuses the opened card', opened_card(p) == item, opened_card(p))
    p.locator('#browse-results .discovery-card').last.locator('a.card-details').click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    p.go_back(); p.wait_for_url(lambda u: path_query(u) == list_url); p.wait_for_load_state('load'); ready(p, 1500)
    check(tag + ' browser Back returns to the same list URL', path_query(p.url) == list_url, p.url)
    check(tag + ' browser Back restores the scroll position', abs(scroll_y(p) - y) <= 40, (scroll_y(p), y))
    s.close(tag + ' Explore')


def other_lists(browser):
    s = Session(browser, 1440, 'light', 'student_a'); p = s.page
    # Home
    p.goto(BASE + '/index.html'); p.locator('body[data-student-ready="true"]').wait_for(); p.wait_for_timeout(500)
    p.locator('#home-reports a.card-details').first.click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    check('Home → item says Back to Home', p.locator('#item-back').inner_text().strip() == 'Back to Home', p.locator('#item-back').inner_text())
    p.locator('#item-back').click(); p.wait_for_url('**/index.html'); check('Back to Home returns Home', urlparse(p.url).path == '/index.html', p.url)
    # History
    p.goto(BASE + '/history.html?view=returned'); ready(p)
    if p.locator('#history-results a.card-details').count():
        p.locator('#history-results a.card-details').first.click(); p.wait_for_url('**/item.html?id=*'); ready(p)
        check('History → item says Back to History', p.locator('#item-back').inner_text().strip() == 'Back to History', p.locator('#item-back').inner_text())
        p.locator('#item-back').click(); p.wait_for_url(lambda u: urlparse(u).path == '/history.html'); ready(p)
        check('Back to History keeps the Returned view', 'view=returned' in p.url, p.url)
    else:
        check('History has a returned report to open', False, 'none')
    # My Activity page 2
    p.goto(BASE + '/my-reports.html?tab=reports'); ready(p)
    p.locator('#pager button', has_text='Next').click(); expect(p.locator('#pager span')).to_contain_text('Page 2 of'); p.wait_for_timeout(900)
    check('My Activity keeps its page in the URL', 'page=2' in p.url and 'tab=reports' in p.url, p.url)
    list_url = path_query(p.url)
    p.locator('#activity-results a.card-details').first.click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    check('My Activity → item says Back to My Activity', p.locator('#item-back').inner_text().strip() == 'Back to My Activity', p.locator('#item-back').inner_text())
    p.locator('#item-back').click(); p.wait_for_url(lambda u: path_query(u) == list_url); ready(p)
    check('Back to My Activity returns to page 2', path_query(p.url) == list_url and 'Page 2 of' in p.locator('#pager span').inner_text(), p.url)
    s.close('Home, History, My Activity')
    # A new tab has nothing remembered (signing in lands on Home, so the first tab legitimately remembers Home).
    s = Session(browser, 1440, 'light', 'student_b'); p = s.ctx.new_page(); p.on('pageerror', lambda e: s.errors.append(str(e)))
    p.goto(BASE + '/item.html?id=1'); ready(p)
    check('a fresh item URL falls back to Back to Explore', p.locator('#item-back').inner_text().strip() == 'Back to Explore' and p.locator('#item-back').get_attribute('href').endswith('browse.html'), p.locator('#item-back').get_attribute('href'))
    s.close('fresh item tab')


def bm_queue(browser):
    s = Session(browser, 1440, 'light', 'bm'); p = s.page
    p.goto(BASE + '/admin.html?tab=reports'); ready(p)
    p.locator('#bm-search').fill('Round1'); expect(p.locator('#queue-count')).to_contain_text(str(SEEDED) + ' record'); p.wait_for_timeout(600)
    p.locator('#bm-pager button', has_text='Next').click(); expect(p.locator('#bm-pager span')).to_contain_text('2 /'); p.wait_for_timeout(900)
    check('BM queue keeps search and page in the URL', 'q=Round1' in p.url and 'page=2' in p.url, p.url)
    p.reload(); ready(p)
    check('BM search survives a reload', p.locator('#bm-search').input_value() == 'Round1', p.locator('#bm-search').input_value())
    check('BM page survives a reload', p.locator('#bm-pager span').inner_text().startswith('2 /'), p.locator('#bm-pager span').inner_text())
    p.locator('#bm-state').select_option('OPEN'); p.wait_for_timeout(1200)
    check('BM status filter is in the URL', 'status=OPEN' in p.url, p.url)
    p.reload(); ready(p)
    check('BM status filter survives a reload', p.locator('#bm-state').input_value() == 'OPEN' and p.locator('#bm-search').input_value() == 'Round1', (p.locator('#bm-state').input_value(), p.url))
    p.locator('#bm-queue-list .bm-queue-card').nth(1).click(); p.wait_for_timeout(1200)
    queue_url = path_query(p.url); check('BM selection is in the URL', 'report=' in queue_url, queue_url)
    p.locator('#bm-detail a', has_text='Public detail').click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    check('BM → public detail says Back to Review Queue', p.locator('#item-back').inner_text().strip() == 'Back to Review Queue', p.locator('#item-back').inner_text())
    p.locator('#item-back').click(); p.wait_for_url(lambda u: urlparse(u).path == '/admin.html'); ready(p)
    check('Back to Review Queue returns to the same queue and record', path_query(p.url) == queue_url and p.locator('#bm-search').input_value() == 'Round1' and p.locator('#bm-state').input_value() == 'OPEN', (p.url, queue_url))
    check('the opened record is selected again', p.locator('#bm-queue-list .bm-queue-card.selected').count() == 1)
    # A decision note typed before the session ended comes back in its dialog.
    p.locator('#bm-detail [data-bm-action="status"]').click(); expect(p.locator('#operation-dialog')).to_be_visible()
    non_final = p.evaluate('[...document.querySelectorAll("#operation-status option")].map(o => [o.value, o.textContent]).filter(([v, t]) => v && !t.includes("(final)")).map(([v]) => v)')
    check('the report offers a non-final status', bool(non_final), non_final)
    p.locator('#operation-status').select_option(non_final[0]); p.locator('#operation-note').fill('Round1 note kept across sign-in.')
    s.ctx.clear_cookies(name=COOKIE); s.accept_dialogs = True
    p.locator('#operation-submit').click(); p.wait_for_url('**/login.html?return=*')
    check('the changed dialog did not ask before the session redirect', 'beforeunload' not in s.dialogs, s.dialogs)
    s.login('bm', '/admin.html'); expect(p.locator('#bm-feedback')).to_contain_text('your note was kept')
    p.locator('#bm-detail [data-bm-action="status"]').click(); expect(p.locator('#operation-dialog')).to_be_visible()
    check('the kept note is back in the dialog', p.locator('#operation-note').input_value() == 'Round1 note kept across sign-in.', p.locator('#operation-note').input_value())
    p.locator('#operation-dismiss').click()
    s.close('BM queue', ignore=('status of 401',))


def forms(browser):
    s = Session(browser, 1440, 'light', 'student_b'); p = s.page
    # After submitting, Back skips the spent form.
    p.goto(BASE + '/browse.html'); ready(p)
    p.locator('a', has_text='Report an item').first.click(); p.wait_for_url('**/report.html*'); ready(p, 300)
    p.locator('[data-report-type="FOUND"]').click()
    p.locator('#r-title').fill('Round1 submitted report'); p.locator('#r-description').fill('Synthetic report submitted by the navigation suite.')
    p.locator('#r-category').select_option('8'); p.locator('#r-location').select_option('40'); p.locator('#r-clue').fill('Synthetic private clue for submit')
    p.locator('#report-submit').click(); p.wait_for_url('**/item.html?id=*'); ready(p)
    check('submitting did not ask before leaving', 'beforeunload' not in s.dialogs, s.dialogs)
    p.go_back(); p.wait_for_load_state('load'); p.wait_for_timeout(1200)
    check('Back after submitting skips the spent form', urlparse(p.url).path == '/browse.html', p.url)
    # A changed form asks before leaving; dismissing keeps the text.
    p.goto(BASE + '/report.html?type=lost'); ready(p, 300)
    p.locator('#r-title').fill('Round1 unsaved title')
    p.locator('header a[href$="browse.html"]').first.click(); p.wait_for_timeout(1200)
    check('a changed form asks before leaving', 'beforeunload' in s.dialogs, s.dialogs)
    check('staying keeps the typed text', urlparse(p.url).path == '/report.html' and p.locator('#r-title').input_value() == 'Round1 unsaved title', p.url)
    s.accept_dialogs = True; s.dialogs.clear()
    p.locator('header a[href$="browse.html"]').first.click(); p.wait_for_url('**/browse.html*')
    check('leaving after confirming works', urlparse(p.url).path == '/browse.html', p.url)
    s.dialogs.clear(); p.goto(BASE + '/report.html?type=lost'); ready(p, 300)
    p.locator('header a[href$="browse.html"]').first.click(); p.wait_for_url('**/browse.html*')
    check('an untouched form leaves without asking', 'beforeunload' not in s.dialogs, s.dialogs)
    # Text typed before the session ended comes back after signing in.
    p.goto(BASE + '/report.html?type=found'); ready(p, 300)
    p.locator('#r-title').fill('Round1 expired draft'); p.locator('#r-description').fill('Typed before the session ended.')
    p.locator('#r-category').select_option('8'); p.locator('#r-location').select_option('40'); p.locator('#r-clue').fill('Private clue typed before expiry')
    s.ctx.clear_cookies(name=COOKIE); s.dialogs.clear()
    p.locator('#report-submit').click(); p.wait_for_url('**/login.html?return=*')
    check('the session redirect did not ask before leaving', 'beforeunload' not in s.dialogs, s.dialogs)
    s.login('student_b', '/report.html')
    check('the typed text is restored after signing in', p.locator('#r-title').input_value() == 'Round1 expired draft' and p.locator('#r-clue').input_value() == 'Private clue typed before expiry' and p.locator('#r-category').input_value() == '8', p.locator('#r-title').input_value())
    expect(p.locator('#shell-feedback')).to_contain_text('add any photos again')
    s.close('forms', ignore=('status of 401',))


with sync_playwright() as pw:
    seed()
    browser = pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    try:
        explore_return(browser, 1440, 'light')
        explore_return(browser, 390, 'dark')
        other_lists(browser)
        bm_queue(browser)
        forms(browser)
    finally:
        browser.close()
print(json.dumps({'stable_navigation_ui_checks': len(checks), 'passed': True}))
