"""Photo search (Stable round 5) browser journeys on live phase8-dev (8020), offline fake engine only.

Run inside stable-tools/p8_envelope.py with REFIND_VISUAL_PROVIDER=fake (server and indexer then never call a provider);
the snapshot, media and limiter files are restored afterwards. Two views (1440 light, 390 dark), reduced motion.
Student Explore: camera in the search field, crop screen (drag a corner, keyboard, Use whole photo), chip, ranked cards
with labels, typed words set aside and restored by Clear, filters narrowing without a new request, honest no-match,
report-form hint. BM: camera only on the Reports tab, ranked queue cards with thumbnail and label, Lost/All switch
re-runs. Indexer: a new report's photo becomes searchable on its own. No JS errors, 5xx or foreign requests.
"""
import io, json, os, secrets, subprocess, sys, time
from pathlib import Path

import psutil
from PIL import Image, ImageDraw
from playwright.sync_api import expect, sync_playwright

ROOT = Path('C:/ReFind-Laravel'); APP = ROOT / 'laravel-p8'; STATE = ROOT / 'state/phase8-dev'; BASE = 'http://127.0.0.1:8020'
CREDS = json.loads((STATE / 'credentials.json').read_text())
TMP = STATE / 'tmp/visual-ui'; TMP.mkdir(parents=True, exist_ok=True)
os.environ['TEMP'] = os.environ['TMP'] = str(STATE / 'tmp')
checks, errors, server_errors, foreign, searches = [], [], [], [], []


def check(label, ok):
    if not ok:
        raise AssertionError(label)
    checks.append(label)


def php(*args, background=False):
    env = dict(os.environ, REFIND_PROFILE='phase8-dev', PHP_INI_SCAN_DIR=str(ROOT / 'runtime/empty-ini-scan'))
    cmd = [str(ROOT / 'runtime/php85/php.exe'), '-c', str(ROOT / 'runtime/php85/php.ini'), str(APP / 'tools/visual-index.php'), *args]
    if background:
        return subprocess.Popen(cmd, cwd=APP, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    p = subprocess.run(cmd, cwd=APP, env=env, capture_output=True, text=True, timeout=300)
    if p.returncode:
        raise RuntimeError('visual-index.php ' + args[0] + ' failed: ' + (p.stderr or p.stdout)[-300:])
    return json.loads(p.stdout.strip().splitlines()[-1])


def guard(route):
    url = route.request.url
    if url.startswith(('data:', 'blob:')) or url.startswith(BASE + '/'):
        route.continue_()
    else:
        foreign.append(url); route.abort()


def context(pw, width, theme):
    if psutil.virtual_memory().available < 2 * 1024 ** 3:
        raise RuntimeError('Browser resource floor reached (2 GiB available RAM)')
    browser = pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe', headless=True)
    ctx = browser.new_context(viewport={'width': width, 'height': 900}, color_scheme=theme, reduced_motion='reduce', timezone_id='Asia/Jakarta')
    ctx.route('**/*', guard)
    ctx.on('close', lambda _c: browser.close())
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)[:200]))
    page.on('console', lambda m: errors.append(m.text[:200]) if m.type == 'error' else None)
    page.on('response', lambda r: server_errors.append(r.url) if r.status >= 500 else None)
    page.on('request', lambda r: searches.append(r.url) if 'visual-search.php' in r.url else None)
    return ctx, page


def login(page, key):
    page.goto(BASE + '/login.html'); page.locator('#email').fill(CREDS[key]['email']); page.locator('#password').fill(CREDS[key]['password'])
    page.locator('#login-submit').click(); page.wait_for_url(lambda u: 'login.html' not in u)


def distinctive(seed, path):
    img = Image.new('RGB', (480, 360), (240, 240, 236)); d = ImageDraw.Draw(img)
    d.ellipse((90 + seed % 40, 60, 330, 300), fill=(20 + seed % 200, 150, 60)); d.rectangle((300, 200, 420, 330), fill=(200, 40, 140))
    img.save(path, format='JPEG', quality=90); return path


def explore(pw, width, theme, sample, new_photo, new_id):
    ctx, page = context(pw, width, theme); tag = f' {width}'
    login(page, 'student_a'); page.goto(BASE + '/browse.html'); page.locator('#browse-results .discovery-card').first.wait_for()
    camera = page.locator('.photo-search-camera')
    check('camera inside the Explore search field' + tag, camera.is_visible() and page.locator('.search-field .photo-search-camera').count() == 1)
    page.locator('#filter-q').fill('kept words'); page.wait_for_timeout(400)
    page.locator('.photo-search-input').set_input_files(str(sample))
    dialog = page.locator('.photo-crop[open]'); dialog.wait_for()
    check('crop screen opens with the whole photo' + tag, page.locator('.photo-crop-box').get_attribute('aria-valuetext') == 'Whole photo')
    check('AI notice shown before sending' + tag, 'sent to an AI service' in dialog.inner_text())
    handle = page.locator('.photo-crop-box [data-handle=se]').bounding_box()
    page.mouse.move(handle['x'] + 10, handle['y'] + 10); page.mouse.down(); page.mouse.move(handle['x'] - 60, handle['y'] - 50, steps=6); page.mouse.up()
    check('dragging a corner crops' + tag, page.locator('.photo-crop-box').get_attribute('aria-valuetext') != 'Whole photo')
    page.locator('.photo-crop-box').focus(); page.keyboard.press('ArrowLeft')
    before = len(searches); page.locator('[data-crop-whole]').click()
    page.locator('#browse-results .photo-tags').first.wait_for(timeout=20000)
    check('one search request' + tag, len(searches) == before + 1)
    check('typed words set aside' + tag, page.locator('#filter-q').input_value() == '' and page.locator('#filter-q').get_attribute('placeholder') == 'Searching by photo')
    check('chip with one photo and Clear' + tag, page.locator('.photo-search-chip .photo-search-thumb').count() == 1 and page.locator('.photo-search-clear').is_visible())
    first = page.locator('#browse-results .discovery-card').first
    check('best match labelled Very similar' + tag, first.locator('.photo-match').inner_text().strip() == 'Very similar')
    total = page.locator('#browse-results .discovery-card').count()
    before = len(searches); page.locator('[data-filter-type="LOST"]').click(); page.wait_for_timeout(300)
    check('filters narrow the matches without a new search' + tag, len(searches) == before and page.locator('#browse-results .discovery-card, #browse-results .empty-state').count() >= 1 and page.locator('#browse-results .discovery-card').count() <= total)
    page.locator('[data-filter-type=""]').click()
    page.locator('.photo-search-clear').click(); page.locator('#browse-results .discovery-card').first.wait_for()
    check('Clear restores the typed words' + tag, page.locator('#filter-q').input_value() == 'kept words' and page.locator('.photo-search-chip').is_hidden())
    page.locator('#filter-q').fill(''); page.wait_for_timeout(400)
    page.locator('.photo-search-input').set_input_files(str(TMP / 'magenta.jpg')); page.locator('.photo-crop[open]').wait_for(); page.locator('[data-crop-search]').click()
    page.locator('.photo-search-notice:not([hidden])').wait_for(timeout=20000)
    check('honest no-match notice with the normal list' + tag, 'No close photo matches' in page.locator('.photo-search-notice').inner_text() and page.locator('#browse-results .discovery-card').count() > 0)
    page.locator('.photo-search-clear').click()
    if new_photo:
        page.locator('.photo-search-input').set_input_files(str(new_photo)); page.locator('.photo-crop[open]').wait_for(); page.locator('[data-crop-whole]').click()
        page.locator('#browse-results .photo-tags').first.wait_for(timeout=20000)
        check('newly reported photo found after automatic indexing' + tag, page.locator(f'#browse-results .discovery-card[data-item-id="{new_id}"]').count() == 1)
    page.goto(BASE + '/report.html?type=lost'); page.locator('body[data-ready="true"]').wait_for()
    expect(page.locator('#photo-search-report-hint')).to_be_visible(timeout=10000)
    check('report form photo-search hint' + tag, page.locator('#photo-search-report-hint').is_visible() and page.locator('#visual-report-consent').count() == 0)
    ctx.close()


def bm_reports(pw, width, theme, sample):
    ctx, page = context(pw, width, theme); tag = f' BM {width}'
    login(page, 'bm'); page.goto(BASE + '/admin.html?tab=claims'); page.locator('#bm-loading[hidden]').wait_for(state='attached')
    check('no camera on the Claims tab' + tag, page.locator('.photo-search-camera').is_hidden())
    page.locator('[data-bm-tab="reports"]').click(); page.locator('.bm-queue-card').first.wait_for()
    check('camera on the Reports tab' + tag, page.locator('.photo-search-camera').is_visible())
    page.locator('.photo-search-input').set_input_files(str(sample)); page.locator('.photo-crop[open]').wait_for()
    before = len(searches); page.locator('[data-crop-whole]').click()
    page.locator('.photo-search-toggle').wait_for()
    page.wait_for_function("() => { const l = document.querySelector('.photo-search-label'); return l && !l.textContent.includes('…'); }", timeout=20000)
    page.locator('.photo-search-toggle button:not(.active)').click()
    page.locator('.bm-queue-card.has-photo').first.wait_for(timeout=20000)
    check('Lost/All switch searches again' + tag, len(searches) >= before + 2)
    card = page.locator('.bm-queue-card.has-photo').first
    check('queue card shows thumbnail and label' + tag, card.locator('.bm-photo-thumb').count() == 1 and card.locator('.bm-photo-label').inner_text().strip() in ('Very similar', 'Similar'))
    check('queue count names photo matches' + tag, 'photo match' in page.locator('#queue-count').inner_text())
    page.locator('[data-bm-tab="handover"]').click(); page.wait_for_timeout(500)
    check('leaving the Reports tab ends photo mode' + tag, page.locator('.photo-search-camera').is_hidden() and page.locator('.photo-search-chip').is_hidden())
    ctx.close()


def run():
    check('fake engine only (no provider calls)', os.environ.get('REFIND_VISUAL_PROVIDER') == 'fake')
    check('fake engine configured for this run', php('status').get('provider') == 'fake')
    while php('status')['photos']['pending']:
        php('sync', '--limit', '200')
    Image.new('RGB', (96, 72), (255, 0, 255)).save(TMP / 'magenta.jpg', format='JPEG')
    # A gallery photo as the query: fetch it through the app as a student (private media are never read directly).
    with sync_playwright() as pw:
        ctx, page = context(pw, 1440, 'light'); login(page, 'student_a')
        items = page.request.get(BASE + '/api/items.php?active=1&limit=48').json()['items']
        target = next(i for i in items if i['photos'])
        sample = TMP / 'sample.jpg'; sample.write_bytes(page.request.get(BASE + target['photos'][0]).body())
        # A brand-new report with a distinctive photo; the background indexer (fake engine) must pick it up by itself.
        new_photo = distinctive(secrets.randbelow(1000), TMP / 'new-report.jpg')
        token = page.request.get(BASE + '/api/session.php').json()['csrf']; today = page.request.get(BASE + '/api/references.php').json()['campus_today']
        r = page.request.post(BASE + '/api/items.php', headers={'X-CSRF-Token': token}, multipart={'action': 'create', 'type': 'FOUND', 'title': 'Synthetic photo-search UI report', 'description': 'Synthetic green round object with a pink block.', 'category_id': '1', 'location_id': '38', 'date': today, 'time': '', 'clue': 'Synthetic private mark.', 'photos[]': {'name': 'new.jpg', 'mimeType': 'image/jpeg', 'buffer': new_photo.read_bytes()}})
        check('new report with a photo created', r.status == 201)
        new_id = str(r.json()['id']); ctx.close()
        loop = php('loop', background=True)
        try:
            deadline = time.time() + 75
            while time.time() < deadline and php('status')['photos']['pending']:
                time.sleep(3)
            check('indexer made the new photo searchable on its own', php('status')['photos']['pending'] == 0)
        finally:
            loop.terminate(); loop.wait(timeout=20)
        explore(pw, 1440, 'light', sample, new_photo, new_id)
        explore(pw, 390, 'dark', sample, None, None)
        bm_reports(pw, 1440, 'light', sample)
        bm_reports(pw, 390, 'dark', sample)
    check('no page errors', not errors)
    check('no 5xx responses', not server_errors)
    check('no requests outside the app', not foreign)


if __name__ == '__main__':
    try:
        run()
        print(json.dumps({'passed': True, 'checks': len(checks)}))
    except Exception as e:
        print(json.dumps({'passed': False, 'checks_before_failure': len(checks), 'error': str(e)[:600], 'errors': errors[:5], 'server_errors': server_errors[:5], 'foreign': foreign[:5]})); sys.exit(1)
