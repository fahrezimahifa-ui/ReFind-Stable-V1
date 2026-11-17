from verification_receipt import complete_receipt
from pathlib import Path
import urllib.request,urllib.error,urllib.parse,http.cookiejar,json,subprocess,os,time,concurrent.futures
ROOT=Path('C:/ReFind-Laravel');BASE='http://127.0.0.1:';credentials=json.loads((ROOT/'state/academic-test/credentials.json').read_text())['student_a'];jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
s=json.load(client.open(BASE+'8014/api/session.php'));data=urllib.parse.urlencode(dict(action='login',email=credentials['email'],password=credentials['password'])).encode();r=client.open(urllib.request.Request(BASE+'8014/api/auth.php',data=data,headers={'X-CSRF-Token':s['csrf']}));logged=json.load(r);token=logged['csrf'];cookie='; '.join(c.name+'='+c.value for c in jar);encrypted=next(c.value for c in jar if c.name=='refind_laravel_b_test')
(ROOT/'state/academic-test/concurrency-input.json').write_text(json.dumps({'cookie':encrypted}),encoding='utf-8')
ready=ROOT/'state/academic-test/lock-ready.json'
if ready.exists():ready.unlink()
env=os.environ.copy();env['REFIND_PROFILE']='academic-test'
helper=subprocess.Popen([str(ROOT/'runtime/php85/php.exe'),'-c',str(ROOT/'runtime/php85/php.ini'),str(ROOT/'laravel/tools/hold-session-lock.php')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,creationflags=subprocess.CREATE_NO_WINDOW)
try:
 deadline=time.monotonic()+8
 while not ready.exists():
  if helper.poll() is not None:raise AssertionError('Lock helper exited before acquisition')
  if time.monotonic()>deadline:raise AssertionError('Lock helper readiness timeout')
  time.sleep(.02)
 start=time.monotonic();request=urllib.request.Request(BASE+'8015/api/items.php',headers={'Cookie':cookie});result=urllib.request.urlopen(request,timeout=10);elapsed=time.monotonic()-start
 assert result.code==200 and elapsed>.65,'Cross-process session lock was not observed'
 helper.communicate(timeout=5);assert helper.returncode==0,'Lock helper failed'
finally:
 if helper.poll() is None:helper.terminate();helper.wait(timeout=5)
def send(port,path,data=None):
 h={'Cookie':cookie};body=None
 if data is not None:body=urllib.parse.urlencode(data).encode();h['X-CSRF-Token']=token
 try:r=urllib.request.urlopen(urllib.request.Request(BASE+str(port)+path,data=body,headers=h),timeout=15)
 except urllib.error.HTTPError as e:r=e
 r.read();return r.code
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
 a=pool.submit(send,8014,'/api/auth.php',{'action':'logout'});b=pool.submit(send,8015,'/api/claims.php',{'action':'submit'});outcomes=[a.result(),b.result()]
assert outcomes[0]==200 and outcomes[1] in [401,501],'Logout race violated authenticated mutation boundary'
assert send(8014,'/api/items.php')==401 and send(8015,'/api/items.php')==401,'Old cookie identity replayed across processes'
receipt={'profile':'academic-test','checks_passed':4,'independent_web_processes':2,'observed_lock_wait_seconds':round(elapsed,3),'logout_race_statuses':outcomes,'old_cookie_replay_rejected':True}
(ROOT/'evidence/phase5/concurrency-results.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps(receipt))
complete_receipt(4, receipt)
