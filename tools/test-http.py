from verification_receipt import complete_receipt
from pathlib import Path
import json,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,io
from PIL import Image
ROOT=Path('C:/ReFind-Laravel');BASE='http://127.0.0.1:8014';CREDS=json.loads((ROOT/'state/academic-test/credentials.json').read_text());MEDIA=json.loads((ROOT/'state/academic-test/fixtures.json').read_text())
results=[]
def check(label,condition):
 if not condition:raise AssertionError(label)
 results.append(label)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl):return None
class Client:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedirect());self.token=''
 def cookie(self):return '; '.join(c.name+'='+c.value for c in self.jar)
 def call(self,path,data=None,method=None,token=True,headers=None):
  h=dict(headers or {});raw=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
  if data is not None:
   h['Content-Type']='application/x-www-form-urlencoded'
   if token is not False:h['X-CSRF-Token']=self.token if token is True else token
  req=urllib.request.Request(BASE+path,data=raw,headers=h,method=method or ('POST' if data is not None else 'GET'))
  try:r=self.opener.open(req,timeout=15)
  except urllib.error.HTTPError as e:r=e
  body=r.read();obj=json.loads(body) if 'application/json' in r.headers.get('Content-Type','') and body else None
  return r.code,obj,r.headers,body
 def boot(self):
  status,obj,h,b=self.call('/api/session.php');check('guest session bootstrap',status==200 and obj['authenticated'] is False);self.token=obj['csrf'];check('mandatory 64-character hex token',re.fullmatch('[a-f0-9]{64}',self.token) is not None);return obj,h
 def login(self,who='student_a',body=False):
  self.boot();old=self.token;oldcookie=self.cookie();u=CREDS[who];data={'action':'login','email':u['email'],'password':u['password']}
  if body:data['_csrf']=self.token
  status,obj,h,b=self.call('/api/auth.php',data,token=False if body else True,headers={'Origin':BASE})
  check('synthetic '+who+' login',status==200 and obj['authenticated']);check('login rotates CSRF token',obj['csrf']!=old and re.fullmatch('[a-f0-9]{64}',obj['csrf']) is not None);check('login rotates session cookie',self.cookie()!=oldcookie);self.token=obj['csrf'];return obj,old,oldcookie

guest=Client();boot,h=guest.boot();check('AI and registration disabled',not boot['visual_search_enabled'] and not boot['registration_enabled']);check('expiry contract',boot['expires']=={'idle_seconds':1800,'absolute_seconds':28800});cookie=h.get('Set-Cookie','');check('isolated HttpOnly Lax cookie','refind_laravel_b_test=' in cookie and 'httponly' in cookie.lower() and 'samesite=lax' in cookie.lower());check('no XSRF cookie',all(c.name!='XSRF-TOKEN' for c in guest.jar));check('stable token on GET',guest.call('/api/session.php')[1]['csrf']==guest.token)
for path,status in [('/api/items.php',401),('/api/items.php?mine=1',401),('/api/photos.php?kind=item&id=1',401),('/api/avatar.php?token='+MEDIA['avatar_token'],401),('/api/session.php?unexpected=1',400),('/api/auth.php',405),('/app/config.php',404),('/storage/auth/secret.json',404),('/vendor/autoload.php',404),('/api/bootstrap.php',404),('/storage/items/test.png',404)]:check('guest boundary '+path,guest.call(path)[0]==status)
check('guest protected page redirects',guest.call('/browse.html')[0]==302);check('guest home redirects to login',guest.call('/')[2].get('Location','').endswith('/login.html'));check('nonloopback host refused',guest.call('/api/session.php',headers={'Host':'example.invalid:8014'})[0]==403)
for method in ['HEAD','OPTIONS']:
 r=guest.call('/api/session.php',method=method);check(method+' does not expand API methods',r[0]==405 and r[2].get('Allow')=='GET')
for label,token,headers,data in [('missing',False,{},{}),('same-origin missing',False,{'Sec-Fetch-Site':'same-origin'},{}),('invalid','0'*64,{},{}),('conflicting',True,{}, {'_csrf':'b'*64}),('array',False,{}, {'_csrf[]':'x'}),('wrong origin',True,{'Origin':'https://example.invalid'},{}),('wrong port',True,{'Origin':'http://127.0.0.1:8013'},{})]:
 status,obj,_,_=guest.call('/api/auth.php',dict(action='login',**data),token=token,headers=headers);check('CSRF '+label,status==403 and obj['code']=='csrf_invalid')
check('disabled registration before CSRF',guest.call('/api/register.php',{'name':'Synthetic'},token=False)[0]==403)
check('guest mutation guard precedes CSRF',guest.call('/api/claims.php',{'action':'submit'},token=False)[0]==401)
refs=guest.call('/api/references.php');check('guest reference read allowed',refs[0]==200);check('active category/area references',len(refs[1]['categories'])==6 and len(refs[1]['locations'])==6);check('campus timezone retained',refs[1]['timezone']=='Asia/Jakarta')
a=Client();login,old,_=a.login();check('safe Student landing',login['redirect']=='/index.html');check('no automatic password fields returned','password_hash' not in json.dumps(login))
status,obj,_,_=a.call('/api/items.php');check('discovery envelope',status==200 and set(obj)=={'ok','items','total','page','limit','pages'} and obj['total']==8);check('public projection privacy',all(not({'verification_clue','private_clue','ownership_photos','claimant_email'}&set(x)) for x in obj['items']));check('explicit ID types',all(isinstance(x['item_id'],str) and isinstance(x['reporter_user_id'],str) for x in obj['items']))
item=a.call('/api/items.php?id=1')[1]['item'];check('detail history and matches',len(item['history'])==1 and len(item['matches'])==1);check('own report eligibility',item['can_edit'] is True and item['can_claim'] is False);check('null event time preserved',item['event_time'] is None);check('history actor labels',item['history'][0]['actor']=='you')
check('Found claim eligibility',a.call('/api/items.php?id=2')[1]['item']['can_claim'] is True);check('own reports scoped',all(x['reporter_user_id']=='1' for x in a.call('/api/items.php?mine=1')[1]['items']))
check('word order and conservative plurals',a.call('/api/items.php?q=leather+brown+wallets')[1]['total']==2);check('SQL injection stays a bound value',a.call('/api/items.php?q=x%27+OR+1%3D1+--')[1]['total']==0);check('pagination contract',a.call('/api/items.php?page=2&limit=2')[1]['pages']==4);check('active filter',a.call('/api/items.php?active=1')[1]['total']==6);check('archive filter',a.call('/api/items.php?closed=1')[1]['total']==2)
for query in ['id=0','id=4294967296','limit=49','page=100001','active=1&closed=1','status=FAKE','mine=0','surprise=1']:
 check('invalid query '+query,a.call('/api/items.php?'+query)[0]==400)
check('missing item',a.call('/api/items.php?id=99999')[0]==404);check('own edit metadata retains permitted clue',a.call('/api/items.php?id=6&editable=1')[1]['item']['ownership_photo_ids']==[MEDIA['ownership']]);check('another reporter edit forbidden',a.call('/api/items.php?id=2&editable=1')[0]==403)
check('own unread count',a.call('/api/notifications.php?count=1')[1]['unread_count']==2);check('invalid notification count filter',a.call('/api/notifications.php?count=1&unread=no')[0]==400)
for path in ['/index.html','/browse.html','/item.html?id=1','/BROWSE.HTML']:check('preserved page '+path,a.call(path)[0]==200)
check('Student BM page denied',a.call('/admin.html')[0]==403);check('Student reporting page enabled',a.call('/report.html')[0]==200);check('unfinished account explicit',a.call('/api/account.php')[0]==501)
check('valid guarded business POST unavailable',a.call('/api/claims.php',{'action':'submit'})[0]==501);check('old login token rejected',a.call('/api/claims.php',{'action':'submit'},token=old)[0]==403);check('role rejection precedes token',a.call('/api/staff.php',{'action':'review'},token=False)[0]==403);check('AI endpoint remains disabled',a.call('/api/visual-search.php',{})[1]['code']=='visual_disabled')
for kind,id_ in [('item',MEDIA['ownership']),('claim',MEDIA['claim'])]:check('Student private evidence denied '+kind,a.call('/api/photos.php?'+urllib.parse.urlencode({'kind':kind,'id':id_}))[0]==403)
photo=a.call('/api/photos.php?kind=item&id='+str(MEDIA['item_1']));check('authorized public image',photo[0]==200 and photo[2].get_content_type()=='image/png');check('public image dimensions',Image.open(io.BytesIO(photo[3])).size==(900,540));check('native photo cache header contract','private' in photo[2].get('Cache-Control','') and 'no-cache' in photo[2].get('Cache-Control','') and 'Pragma' not in photo[2]);etag=photo[2].get('ETag');check('authorized private revalidation',a.call('/api/photos.php?kind=item&id=1',headers={'If-None-Match':etag})[0]==304);check('guest revalidation cannot bypass auth',guest.call('/api/photos.php?kind=item&id=1',headers={'If-None-Match':etag})[0]==401)
thumb=a.call('/api/photos.php?kind=item&id=1&w=480');check('authorized thumbnail dimensions',thumb[0]==200 and Image.open(io.BytesIO(thumb[3])).size==(480,288));check('invalid thumbnail size',a.call('/api/photos.php?kind=item&id=1&w=999')[0]==400);check('avatar authorized',a.call('/api/avatar.php?token='+MEDIA['avatar_token'])[0]==200);check('avatar traversal rejected',a.call('/api/avatar.php?token=../../file')[0]==404)
b=Client();b.login('student_b',body=True);check('body-only token works',b.call('/api/items.php')[0]==200);check('other Student unread scope',b.call('/api/notifications.php?count=1')[1]['unread_count']==0)
bm=Client();bm_login,_,_=bm.login('bm');check('BM landing retained',bm_login['redirect']=='/admin.html');check('BM discovery shared',bm.call('/browse.html')[0]==200);check('BM Student page denied',bm.call('/report.html')[0]==403);check('BM unfinished operations explicit',bm.call('/admin.html')[0]==501)
for kind,id_ in [('item',MEDIA['ownership']),('claim',MEDIA['claim'])]:check('BM private evidence authorized '+kind,bm.call('/api/photos.php?'+urllib.parse.urlencode({'kind':kind,'id':id_}))[0]==200)
check('BM cannot submit report',bm.call('/api/claims.php',{'action':'submit'})[0]==403)
old_cookie=a.cookie();old_token=a.token;check('logout is protected',a.call('/api/auth.php',{'action':'logout'},token=False)[0]==403);check('logout succeeds',a.call('/api/auth.php',{'action':'logout'})[0]==200);check('logged out session denied',a.call('/api/items.php')[0]==401);replay=Client();check('old authenticated cookie cannot replay',replay.call('/api/items.php',headers={'Cookie':old_cookie})[0]==401);fresh=a.call('/api/session.php')[1];check('logout leaves fresh guest token',not fresh['authenticated'] and fresh['csrf']!=old_token and re.fullmatch('[a-f0-9]{64}',fresh['csrf']) is not None)
inactive=Client();inactive.boot();cred=CREDS['inactive'];check('inactive account cannot log in',inactive.call('/api/auth.php',dict(action='login',email=cred['email'],password=cred['password']))[0]==401)
for i in range(5):
 limited=Client();limited.boot();r=limited.call('/api/auth.php',dict(action='login',email='limit-probe@refind-poc.invalid',password='incorrect'));check('persistent failed login '+str(i+1),r[0]==401)
limited=Client();limited.boot();r=limited.call('/api/auth.php',dict(action='login',email='limit-probe@refind-poc.invalid',password='incorrect'));check('limiter survives fresh session',r[0]==429 and int(r[2].get('Retry-After','0'))>0)
(ROOT/'evidence/phase5/http-contract-results.json').write_text(json.dumps({'profile':'academic-test','base':BASE,'passed':len(results),'checks':results},indent=2),encoding='utf-8')
print(json.dumps({'http_contract_checks_passed':len(results),'native_traffic':False}))
complete_receipt(len(results), {'checks': results, 'native_traffic': False})
