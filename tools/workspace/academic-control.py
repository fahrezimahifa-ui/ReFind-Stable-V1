from pathlib import Path
import argparse,json,os,secrets,socket,subprocess,time,sys,shutil
import psutil
ROOT=Path('C:/ReFind-Laravel')
PHP=ROOT/'runtime/php85/php.exe'; INI=ROOT/'runtime/php85/php.ini'; APP=ROOT/'laravel'; DB=ROOT/'runtime/mariadb/bin'
OWNER=ROOT/'.refind-laravel-owner.json'; RECORD=ROOT/'state/academic-processes.json'; FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0

def owned_root():
 o=json.loads(OWNER.read_text(encoding='utf-8-sig'))
 if o['project']!='refind-laravel-poc' or Path(o['root']).resolve()!=ROOT.resolve():raise RuntimeError('Wrong workspace owner')
 for relative in ['state','runtime','laravel']:
  p=ROOT/relative
  if p.is_symlink() or p.is_junction() or p.resolve()!=p:raise RuntimeError('Redirected workspace refused')
 b=json.loads((ROOT/'state/academic-owner.json').read_text())
 if b.get('project')!='refind-laravel-academic-b' or Path(b.get('root','')).resolve()!=ROOT.resolve() or b.get('parent_workspace_id')!=o['workspace_id']:raise RuntimeError('Academic owner mismatch')
 return o

def records():return json.loads(RECORD.read_text()) if RECORD.exists() else {}
def save(r):RECORD.write_text(json.dumps(r,indent=2),encoding='utf-8')
def php_args(profile,port):
 base=ROOT/'state'/profile
 return ['-c',str(INI),'-d','error_log='+str(base/'php-error.log'),'-d','upload_tmp_dir='+str(base/'uploads'),'-d','sys_temp_dir='+str(base/'tmp'),'-S','127.0.0.1:'+str(port),'-t',str(APP/'public'),str(APP/'tools/server.php')]

def checked_process(record):
 expected=Path(record['exe']).resolve()
 allowed=[PHP.resolve(),(DB/'mariadbd.exe').resolve(),(DB/'mysqld.exe').resolve()]
 if expected not in allowed or not expected.is_relative_to((ROOT/'runtime').resolve()):raise RuntimeError('Process executable is outside the owned runtime')
 required=record['required_args']
 if expected==PHP.resolve():
  profile=record.get('profile')
  allowed_ports=[8013] if profile=='academic-dev' else [8014,8015] if profile=='academic-test' else []
  valid=any(required==php_args(profile,port) for port in allowed_ports)
 else:valid=record.get('profile') is None and required==['--defaults-file='+str(ROOT/'state/mariadb.ini')]
 if not valid:raise RuntimeError('Process command is outside the owned launch template')
 try:
  p=psutil.Process(record['pid'])
  if abs(p.create_time()-record['created'])>0.01 or Path(p.exe()).resolve()!=expected:raise RuntimeError('Process ownership mismatch; refusing control')
  if p.cmdline()[1:]!=required:raise RuntimeError('Process command ownership mismatch')
  return p
 except (psutil.NoSuchProcess,psutil.ZombieProcess):return None

def port_free(port):
 s=socket.socket()
 try:s.bind(('127.0.0.1',port))
 except OSError:raise RuntimeError('Reserved port occupied: '+str(port))
 finally:s.close()

def preflight():
 owned_root();m=psutil.virtual_memory();free=shutil.disk_usage(ROOT).free
 if m.available<2*1024**3:raise RuntimeError('Startup gate: less than 2 GiB available RAM')
 if free<5*1024**3:raise RuntimeError('Startup gate: less than 5 GiB free disk')
 logs=sum(p.stat().st_size for p in (ROOT/'state').rglob('*.log') if p.is_file())
 if logs>20*1024**2:raise RuntimeError('Private logs exceed 20 MiB; review before starting more workload')
 return {'available_memory_mib':round(m.available/1024**2),'disk_free_gib':round(free/1024**3,2)}

def spawn(key,exe,args,profile=None):
 r=records()
 if key in r and checked_process(r[key]):return
 env=os.environ.copy();env['REFIND_PROFILE']=profile or 'academic-dev';env['PHP_INI_SCAN_DIR']=str(ROOT/'runtime/empty-ini-scan')
 (ROOT/'runtime/empty-ini-scan').mkdir(exist_ok=True)
 log=(ROOT/'state/academic-runtime'/f'{key}.log').open('ab')
 p=subprocess.Popen([str(exe)]+args,cwd=APP,env=env,stdin=subprocess.DEVNULL,stdout=log,stderr=subprocess.STDOUT,creationflags=FLAGS)
 log.close();proc=psutil.Process(p.pid)
 r[key]={'pid':p.pid,'created':proc.create_time(),'exe':str(exe),'required_args':args,'profile':profile};save(r)

def wait_port(key,port):
 deadline=time.monotonic()+45
 while time.monotonic()<deadline:
  if not checked_process(records()[key]):raise RuntimeError(key+' exited; inspect its private log')
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.3):return
  except OSError:time.sleep(.2)
 raise RuntimeError('Timed out waiting for owned '+key)

def db_config():
 base=(ROOT/'runtime/mariadb').as_posix();data=(ROOT/'state/mariadb-data').as_posix();r=ROOT.as_posix()
 return '\n'.join(['[mysqld]','basedir='+base,'datadir='+data,'port=3307','bind-address=127.0.0.1','skip-name-resolve','pid-file='+r+'/state/mariadb.pid','log-error='+r+'/state/mariadb-error.log','tmpdir='+r+'/state/tmp','character-set-server=utf8mb4','collation-server=utf8mb4_unicode_ci','innodb-buffer-pool-size=128M','innodb-log-file-size=32M','max-connections=12','tmp-table-size=16M','max-heap-table-size=16M','table-open-cache=128','thread-cache-size=4','performance-schema=OFF','skip-log-bin',''])

def checked_database_config():
 marker=json.loads((ROOT/'state/database-owner.json').read_text())
 if marker.get('workspace_id')!=owned_root()['workspace_id'] or marker.get('port')!=3307 or Path(marker.get('datadir','')).resolve()!=(ROOT/'state/mariadb-data').resolve():raise RuntimeError('Database marker mismatch')
 for relative in ['state/mariadb-data','runtime/mariadb','runtime/mariadb/bin','runtime/php85','state/tmp']:
  p=ROOT/relative
  if p.is_symlink() or p.is_junction() or p.resolve()!=p:raise RuntimeError('Redirected runtime directory refused')
 cfg=ROOT/'state/mariadb.ini'
 if cfg.is_symlink() or cfg.read_text(encoding='utf-8')!=db_config():raise RuntimeError('Database configuration differs from the approved isolated template')

def checked_admin_client():
 import configparser
 p=ROOT/'state/admin-client.ini'
 if p.is_symlink():raise RuntimeError('Redirected client configuration refused')
 cfg=configparser.ConfigParser(interpolation=None);cfg.read(p,encoding='utf-8');c=cfg['client']
 if c.get('host')!='127.0.0.1' or c.get('port')!='3307' or c.get('protocol')!='tcp' or c.get('user')!='root':raise RuntimeError('Administrative connection target mismatch')
 if c.get('password')!=json.loads((ROOT/'state/owner-credentials.json').read_text())['password']:raise RuntimeError('Administrative credential identity mismatch')

def start_db():
 checked_database_config();r=records()
 if 'db' in r and checked_process(r['db']):return
 port_free(3307)
 if not (ROOT/'state/database-owner.json').exists():raise RuntimeError('Database initialization marker missing')
 exe=DB/'mariadbd.exe'
 if not exe.exists():exe=DB/'mysqld.exe'
 spawn('db',exe,['--defaults-file='+str(ROOT/'state/mariadb.ini')]);wait_port('db',3307)

def run_php(script,profile):
 env=os.environ.copy();env['REFIND_PROFILE']=profile;env['PHP_INI_SCAN_DIR']=str(ROOT/'runtime/empty-ini-scan')
 p=subprocess.run([str(PHP),'-c',str(INI),str(APP/script)],cwd=APP,env=env,text=True,capture_output=True,timeout=90)
 if p.returncode:
  (ROOT/'state'/f'{profile}-provision-error.log').write_text(p.stdout+p.stderr,encoding='utf-8')
  raise RuntimeError('Isolated provisioning failed; inspect private provisioning log')
 print(p.stdout.strip())

def initialize():
 owned_root();print(json.dumps(preflight()));start_db()
 for profile in ['academic-dev','academic-test']:run_php('tools/provision-academic.php',profile)
 print('Dedicated B schemas provisioned; business tables remain read-only.')

def checked_profile(profile):
 import base64
 if profile not in ['academic-dev','academic-test']:raise RuntimeError('Explicit isolated profile required')
 p=ROOT/'state'/profile/'profile.json'
 if p.is_symlink() or p.resolve()!=p:raise RuntimeError('Redirected application profile refused')
 cfg=json.loads(p.read_text(encoding='utf-8'))
 expected={'profile':profile,'workspace_id':json.loads((ROOT/'state/academic-owner.json').read_text())['workspace_id'],'database':'refind_laravel_b_'+profile.removeprefix('academic-'),'username':'refind_b_'+profile.removeprefix('academic-')+'_app','cookie':'refind_laravel_b_'+profile.removeprefix('academic-'),'url':'http://127.0.0.1:'+str(8013 if profile=='academic-dev' else 8014)}
 if any(cfg.get(k)!=v for k,v in expected.items()):raise RuntimeError('Application profile identity mismatch before launch')
 if not cfg.get('key','').startswith('base64:') or len(base64.b64decode(cfg['key'][7:],validate=True))!=32:raise RuntimeError('Application key configuration invalid')
 for key,value in {'DB_HOST':'127.0.0.1','DB_PORT':'3307','DB_DATABASE':expected['database'],'DB_USERNAME':expected['username'],'DB_CONNECTION':'mariadb','SESSION_DRIVER':'file','SESSION_COOKIE':'refind_laravel_b_'+profile.removeprefix('academic-')}.items():
  if key in os.environ and os.environ[key]!=value:raise RuntimeError('Conflicting inherited configuration: '+key)
 if os.environ.get('DB_URL') or os.environ.get('DATABASE_URL'):raise RuntimeError('Connection URL override refused')
 for relative in ['framework/sessions','framework/cache/data','app/private/refind']:
  d=ROOT/'state'/profile/relative
  if not d.is_dir() or d.is_symlink() or d.is_junction() or d.resolve()!=d:raise RuntimeError('Profile storage boundary invalid')
 return cfg

def start(profile,second=False):
 checked_profile(profile);print(json.dumps(preflight()));start_db()
 if not (ROOT/'state'/profile/'provisioned.json').exists():raise RuntimeError('Completed synthetic provisioning receipt required')
 port=8013 if profile=='academic-dev' else (8015 if second else 8014)
 key='web-'+str(port);r=records()
 if key in r and checked_process(r[key]):print('Owned server already running: '+str(port));return
 if sum(1 for k,v in r.items() if k.startswith('web-') and checked_process(v))>=2:raise RuntimeError('At most two migration web instances may run')
 port_free(port)
 args=php_args(profile,port)
 spawn(key,PHP,args,profile);wait_port(key,port);print('Started owned '+profile+' server at http://127.0.0.1:'+str(port)+'/')

def stop(profile=None):
 owned_root();r=records()
 for key,rec in list(r.items()):
  if key.startswith('web-') and (profile is None or rec.get('profile')==profile):
   p=checked_process(rec)
   if p:p.terminate();p.wait(timeout=10)
   del r[key];save(r)
 if profile is not None:
  print('Owned '+profile+' web instances stopped; database preserved.');return
 if 'db' in r:
  checked_database_config();checked_admin_client()
  p=checked_process(r['db'])
  if p:
   result=subprocess.run([str(DB/'mariadb-admin.exe'),'--defaults-file='+str(ROOT/'state/admin-client.ini'),'shutdown'],capture_output=True,timeout=20,creationflags=FLAGS)
   if result.returncode:raise RuntimeError('Graceful owned database shutdown failed; no force stop attempted')
   p.wait(timeout=20)
  del r['db'];save(r)
 print('All migration-owned processes stopped; private data preserved.')

def status():
 owned_root();out={}
 for key,rec in records().items():
  p=checked_process(rec);out[key]={'running':p is not None,'pid':rec['pid'],'rss_mib':round(p.memory_info().rss/1024**2,1) if p else 0}
 print(json.dumps({'processes':out,'available_memory_mib':round(psutil.virtual_memory().available/1024**2)},indent=2))

if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('action',choices=['init','start','stop','status','preflight']);parser.add_argument('--profile',choices=['academic-dev','academic-test']);parser.add_argument('--second',action='store_true');a=parser.parse_args()
 try:
  owned_root()
  if a.action=='init':initialize()
  elif a.action=='start':
   if not a.profile:raise RuntimeError('Explicit --profile required')
   start(a.profile,a.second)
  elif a.action=='stop':stop(a.profile)
  elif a.action=='status':status()
  else:print(json.dumps(preflight()))
 except Exception as e:print('STOPPED: '+str(e),file=sys.stderr);sys.exit(1)
