from pathlib import Path
import urllib.request, json, hashlib, zipfile, shutil, subprocess, os
ROOT=Path('C:/ReFind-Laravel')
assert json.loads((ROOT/'.refind-laravel-owner.json').read_text(encoding='utf-8-sig'))['project']=='refind-laravel-poc'
DL=ROOT/'runtime/downloads'
manifest={}
def fetch(url):
 with urllib.request.urlopen(url,timeout=60) as r: return r.read()
def download(url,name,expected=None):
 target=DL/name
 if not target.exists():
  print('Downloading '+name,flush=True)
  with urllib.request.urlopen(url,timeout=90) as r, target.open('wb') as f:
   shutil.copyfileobj(r,f,1024*1024)
 digest=hashlib.sha256(target.read_bytes()).hexdigest()
 if expected and digest.lower()!=expected.lower(): raise RuntimeError('Checksum mismatch: '+name)
 manifest[name]={'url':url,'sha256':digest}
 return target
def extract(archive,target,strip=False):
 target.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(archive) as z:
  for entry in z.infolist():
   parts=Path(entry.filename).parts
   if strip: parts=parts[1:]
   if not parts: continue
   out=target.joinpath(*parts)
   if not out.resolve().is_relative_to(target.resolve()): raise RuntimeError('Unsafe archive path')
   if entry.is_dir(): out.mkdir(parents=True,exist_ok=True)
   else:
    out.parent.mkdir(parents=True,exist_ok=True)
    with z.open(entry) as src,out.open('wb') as dest: shutil.copyfileobj(src,dest)
php=json.loads(fetch('https://downloads.php.net/~windows/releases/releases.json'))['8.5']
pkg=php['nts-vs17-x64']['zip']
a=download('https://downloads.php.net/~windows/releases/'+pkg['path'],pkg['path'],pkg['sha256'])
extract(a,ROOT/'runtime/php85')
manifest['selected_php']=php['version']
releases=json.loads(fetch('https://downloads.mariadb.org/rest-api/mariadb/10.11/'))['releases']
version=sorted(releases,key=lambda x:tuple(map(int,x.split('.'))),reverse=True)[0]
pkg=next(p for p in releases[version]['files'] if p['file_name'].endswith('-winx64.zip'))
a=download(pkg['file_download_url'].replace('http://','https://'),pkg['file_name'],pkg['checksum']['sha256sum'])
extract(a,ROOT/'runtime/mariadb',True)
manifest['selected_mariadb']=version
versions=json.loads(fetch('https://getcomposer.org/versions'))
stable=versions['stable'][0]
composer_url='https://getcomposer.org'+stable['path']
composer_sha=fetch(composer_url+'.sha256sum').decode().split()[0]
a=download(composer_url,'composer.phar',composer_sha)
shutil.copyfile(a,ROOT/'runtime/composer.phar')
manifest['selected_composer']=stable['version']
ca=download('https://curl.se/ca/cacert.pem','cacert.pem')
shutil.copyfile(ca,ROOT/'runtime/cacert.pem')
branch=json.loads(fetch('https://api.github.com/repos/laravel/laravel/branches/13.x'))
sha=branch['commit']['sha']
a=download('https://codeload.github.com/laravel/laravel/zip/'+sha,'laravel-skeleton.zip')
app=ROOT/'laravel'
if app.exists(): raise RuntimeError('Laravel directory already exists; refusing extraction')
extract(a,app,True)
manifest['skeleton_sha']=sha
phpdir=ROOT/'runtime/php85'
ini='''[PHP]
extension_dir="C:/ReFind-Laravel/runtime/php85/ext"
memory_limit=128M
max_execution_time=60
display_errors=Off
log_errors=On
error_log="C:/ReFind-Laravel/state/php-error.log"
date.timezone=UTC
upload_max_filesize=40M
post_max_size=40M
max_file_uploads=20
upload_tmp_dir="C:/ReFind-Laravel/state/uploads"
sys_temp_dir="C:/ReFind-Laravel/state/tmp"
openssl.cafile="C:/ReFind-Laravel/runtime/cacert.pem"
curl.cainfo="C:/ReFind-Laravel/runtime/cacert.pem"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=zip
'''
(phpdir/'php.ini').write_text(ini,encoding='utf-8')
for relative in ['state/uploads','state/tmp','state/composer-home','state/composer-cache']:
 (ROOT/relative).mkdir(exist_ok=True)
composer={'name':'refind/laravel-poc','type':'project','description':'Isolated ReFind Laravel foundation and technical proof of concept','license':'proprietary','require':{'php':'^8.4','laravel/framework':'^13.0'},'require-dev':{'phpunit/phpunit':'^12.0','mockery/mockery':'^1.6'},'autoload':{'psr-4':{'App\\':'app/'}},'autoload-dev':{'psr-4':{'Tests\\':'tests/'}},'config':{'sort-packages':True,'preferred-install':'dist','allow-plugins':{}},'minimum-stability':'stable','prefer-stable':True}
(app/'composer.json').write_text(json.dumps(composer,indent=4)+'\n',encoding='utf-8')
(ROOT/'evidence/runtime-downloads.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
subprocess.run(['git','init','--initial-branch=migration/laravel13',str(app)],check=True)
subprocess.run(['git','-C',str(app),'config','user.name','ReFind Migration'],check=True)
subprocess.run(['git','-C',str(app),'config','user.email','migration@refind.local.invalid'],check=True)
print(json.dumps({'php':manifest['selected_php'],'mariadb':version,'composer':stable['version'],'skeleton':sha}),flush=True)
