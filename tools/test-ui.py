from verification_receipt import complete_receipt
from pathlib import Path
import os,json,datetime
import psutil
ROOT=Path('C:/ReFind-Laravel');BASE='http://127.0.0.1:8014';os.environ['TEMP']=os.environ['TMP']=str(ROOT/'state/academic-test/tmp')
from playwright.sync_api import sync_playwright, expect
creds=json.loads((ROOT/'state/academic-test/credentials.json').read_text())['student_a']
run=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');out=ROOT/'evidence/phase5/ui'/run;out.mkdir(parents=True)
checks=[];errors=[];traffic=[]
def check(name,ok):
 if not ok:raise AssertionError(name)
 checks.append(name)
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
 try:
  for theme in ['light','dark']:
   for width,height in [(360,800),(390,844),(768,1024),(1280,800),(1440,900)]:
    if psutil.virtual_memory().available<1024**3:raise RuntimeError('Browser workload paused: less than 1 GiB available memory')
    context=browser.new_context(viewport={'width':width,'height':height},color_scheme=theme,reduced_motion='reduce',timezone_id='Asia/Jakarta')
    def route_handler(route):
     if route.request.url.startswith(BASE+'/') or route.request.url.startswith(('data:','blob:')):route.continue_()
     else:traffic.append(route.request.url);route.abort()
    context.route('**/*',route_handler)
    page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
    try:
     page.goto(BASE+'/login.html');page.wait_for_load_state('networkidle');check(f'login theme {theme} {width}',page.evaluate('() => document.documentElement.dataset.theme')==theme)
     check(f'login no overflow {theme} {width}',page.evaluate('() => document.documentElement.scrollWidth<=innerWidth'))
     if width in [390,1440]:page.screenshot(path=str(out/f'login-{theme}-{width}.png'),full_page=True)
     page.locator('#email').fill(creds['email']);page.locator('#password').fill(creds['password']);page.locator('#login-submit').click();page.wait_for_url('**/index.html');page.wait_for_load_state('networkidle');page.locator('body[data-student-ready="true"]').wait_for()
     check(f'Laravel login and home {theme} {width}',page.locator('#home-reports').locator(':scope > *').count()==4)
     for path,kind in [('/browse.html','browse'),('/item.html?id=1','item')]:
      page.goto(BASE+path);page.wait_for_load_state('networkidle');page.locator('body[data-ready="true"]').wait_for()
      check(f'{kind} no overflow {theme} {width}',page.evaluate('() => document.documentElement.scrollWidth<=innerWidth'))
      check(f'{kind} main skip target {theme} {width}',page.locator('#main-content').count()==1)
      check(f'{kind} no loading error {theme} {width}',page.locator('#page-feedback').is_hidden())
      if width in [390,1440]:page.screenshot(path=str(out/f'{kind}-{theme}-{width}.png'),full_page=True)
      if kind=='browse':
       page.locator('#filter-q').fill('leather wallets');expect(page.locator('#browse-results > *')).to_have_count(2);page.wait_for_load_state('networkidle');check(f'live search {theme} {width}',page.locator('#browse-results').locator(':scope > *').count()==2)
      else:
       check(f'item content {theme} {width}',page.locator('#item-title').inner_text()=='Brown leather wallet');page.locator('#gallery-open').click();check(f'photo dialog opens {theme} {width}',page.locator('dialog[open]').count()==1);page.keyboard.press('Escape');check(f'photo dialog closes {theme} {width}',page.locator('dialog[open]').count()==0)
    finally:context.close()
 finally:browser.close()
check('no JavaScript exceptions',not errors);check('no native/external browser traffic',not traffic)
receipt={'profile':'academic-test','base':BASE,'passed':len(checks),'checks':checks,'javascript_errors':errors,'external_requests':traffic,'screenshots':str(out),'themes':['light','dark'],'viewports':[360,390,768,1280,1440]}
(ROOT/'evidence/phase5/ui-results.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps({'ui_checks_passed':len(checks),'screenshots':str(out)}))
complete_receipt(len(checks), receipt)
