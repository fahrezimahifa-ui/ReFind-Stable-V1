from verification_receipt import complete_receipt
from pathlib import Path
import os,json,datetime
import psutil
from report_test_client import png,ROOT,BASE
os.environ['TEMP']=os.environ['TMP']=str(ROOT/'state/academic-test/tmp')
from playwright.sync_api import sync_playwright,expect
creds=json.loads((ROOT/'state/academic-test/credentials.json').read_text())['student_a'];checks=[];errors=[];traffic=[]
out=ROOT/'evidence/phase5/report-ui'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');out.mkdir(parents=True)
def check(label,ok):
 if not ok:raise AssertionError('CONSISTENCY GATE FAILED: '+label)
 checks.append(label)
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
 try:
  for theme in ['light','dark']:
   for width,height in [(360,800),(390,844),(768,1024),(1280,800),(1440,900)]:
    if psutil.virtual_memory().available<1024**3:raise RuntimeError('Browser resource gate failed')
    context=browser.new_context(viewport={'width':width,'height':height},color_scheme=theme,reduced_motion='reduce',timezone_id='Asia/Jakarta')
    def route_handler(route):
     if route.request.url.startswith(BASE+'/') or route.request.url.startswith(('data:','blob:')):route.continue_()
     else:traffic.append(route.request.url);route.abort()
    context.route('**/*',route_handler);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
    try:
     page.goto(BASE+'/login.html');page.locator('#email').fill(creds['email']);page.locator('#password').fill(creds['password']);page.locator('#login-submit').click();page.wait_for_url('**/index.html')
     page.goto(BASE+'/report.html');page.wait_for_load_state('networkidle');page.locator('body[data-ready="true"]').wait_for();tag=theme+' '+str(width)
     check('report theme '+tag,page.evaluate('() => document.documentElement.dataset.theme')==theme);check('report layout '+tag,page.evaluate('() => document.documentElement.scrollWidth<=innerWidth'))
     page.locator('#report-submit').click();check('inline required errors '+tag,page.locator('[data-error="title"]').is_visible())
     title='UI synthetic report '+theme+' '+str(width)
     page.locator('#r-title').fill(title);page.locator('#r-category').select_option('1');page.locator('#r-location').select_option('35');page.locator('#r-description').fill('Unique blue verification container.');page.locator('#r-clue').fill('Private synthetic detail for a report.');page.locator('#r-time').fill('')
     page.locator('#r-photos').set_input_files({'name':'public.png','mimeType':'image/png','buffer':png()});page.locator('#r-proof').set_input_files({'name':'proof.png','mimeType':'image/png','buffer':png()});expect(page.locator('#public-upload .upload-preview')).to_have_count(1);expect(page.locator('#proof-upload .upload-preview')).to_have_count(1);expect(page.locator('#report-submit')).to_be_enabled()
     check('public/private previews '+tag,True);check('AI controls remain hidden '+tag,page.locator('#visual-report-consent').is_hidden())
     if width in [390,1440]:page.screenshot(path=str(out/f'report-{theme}-{width}.png'),full_page=True)
     page.locator('#report-submit').click();page.wait_for_url('**/item.html?id=*');page.locator('body[data-ready="true"]').wait_for();expect(page.locator('#item-title')).to_have_text(title);check('browser create '+tag,True)
     page.get_by_role('link',name='Edit report',exact=True).click();page.locator('body[data-ready="true"]').wait_for();check('edit retained photos '+tag,page.locator('#saved-public img').count()==1 and page.locator('#keep-proof').is_checked());check('private evidence is count-only '+tag,page.locator('#lost-evidence img').count()==0)
     page.locator('#r-title').fill(title+' edited');page.locator('#saved-public button').click();page.locator('#r-photos').set_input_files({'name':'replacement.png','mimeType':'image/png','buffer':png(100,60)});expect(page.locator('#report-submit')).to_be_enabled();page.locator('#report-submit').click();page.wait_for_url('**/item.html?id=*');page.locator('body[data-ready="true"]').wait_for();expect(page.locator('#item-title')).to_have_text(title+' edited');check('browser edit/replacement '+tag,True)
     page.goto(BASE+'/my-reports.html');page.locator('body[data-ready="true"]').wait_for();card=page.locator('#activity-results > *').filter(has=page.get_by_text(title+' edited',exact=True));expect(card).to_have_count(1);check('My Reports own item '+tag,True);check('My Reports layout '+tag,page.evaluate('() => document.documentElement.scrollWidth<=innerWidth'));check('claims tab stays disabled '+tag,page.locator('[data-activity="claims"]').is_disabled())
     if width in [390,1440]:page.screenshot(path=str(out/f'my-reports-{theme}-{width}.png'),full_page=True)
     card.get_by_role('button',name='Delete',exact=True).click();check('delete confirmation '+tag,page.locator('#confirm-dialog').is_visible());page.locator('#confirm-delete').click();expect(card).to_have_count(0);check('browser delete '+tag,True)
     page.locator('[data-activity="closed"]').click();page.locator('body[data-ready="true"]').wait_for();check('closed list no error '+tag,page.locator('#page-feedback').is_hidden())
    finally:context.close()
 finally:browser.close()
check('no JavaScript errors',not errors);check('no external/native traffic',not traffic)
(ROOT/'evidence/phase5/report-ui-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'errors':errors,'traffic':traffic,'screenshots':str(out)},indent=2),encoding='utf-8');print(json.dumps({'report_ui_checks_passed':len(checks),'screenshots':str(out)}))
complete_receipt(len(checks), {'checks': checks, 'errors': errors, 'traffic': traffic, 'screenshots': str(out)})
