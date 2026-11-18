from pathlib import Path
import urllib.request,urllib.error,urllib.parse,http.cookiejar,json,secrets,io,os
from PIL import Image
ROOT=Path('C:/ReFind-Laravel');BASE=os.environ.get('REFIND_TEST_BASE','http://127.0.0.1:8014');PROFILE=os.environ.get('REFIND_TEST_PROFILE','academic-test')
class Client:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.token=''
 def call(self,path,data=None,files=None,token=True,method=None):
  headers={};body=None
  if data is not None:
   if files is None:body=urllib.parse.urlencode(data,doseq=True).encode();headers['Content-Type']='application/x-www-form-urlencoded'
   else:
    boundary='refind'+secrets.token_hex(12);chunks=[]
    for k,v in data.items():
     vals=v if isinstance(v,list) else [v]
     for val in vals:chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+str(val)+'\r\n').encode())
    for field,name,mime,payload in files:chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+name+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode()+payload+b'\r\n')
    chunks.append(('--'+boundary+'--\r\n').encode());body=b''.join(chunks);headers['Content-Type']='multipart/form-data; boundary='+boundary
   if token:headers['X-CSRF-Token']=self.token
  req=urllib.request.Request(BASE+path,data=body,headers=headers,method=method or ('POST' if data is not None else 'GET'))
  try:r=self.http.open(req,timeout=20)
  except urllib.error.HTTPError as e:r=e
  raw=r.read();obj=json.loads(raw) if raw and 'application/json' in r.headers.get('Content-Type','') else None
  return r.code,obj,r.headers,raw
 def login(self,key):
  self.token=self.call('/api/session.php')[1]['csrf'];c=json.loads((ROOT/'state'/PROFILE/'credentials.json').read_text())[key];status,obj,_,_=self.call('/api/auth.php',dict(action='login',email=c['email'],password=c['password']));assert status==200;self.token=obj['csrf']
def png(width=80,height=40):
 b=io.BytesIO();Image.new('RGB',(width,height),(70,110,160)).save(b,format='PNG');return b.getvalue()
def data(client,**changes):
 today=client.call('/api/references.php')[1]['campus_today'];return dict(action='create',type='LOST',title='Synthetic report verification case',description='A compact blue container with a visible white stripe.',category_id='1',location_id='35',date=today,time='',clue='A private synthetic mark inside the lid.',**{})|changes
