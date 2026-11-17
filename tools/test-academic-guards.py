from verification_receipt import complete_receipt
from pathlib import Path
import json,importlib.util,subprocess,os,hashlib,psutil
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';PHP=ROOT/'runtime/php85/php.exe';INI=ROOT/'runtime/php85/php.ini'
spec=importlib.util.spec_from_file_location('b',ROOT/'launchers/academic-control.py');b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b);checks=[]
def check(label,ok):
 if not ok:raise RuntimeError('SAFETY GATE FAILED: '+label)
 checks.append(label)
def denied(label,fn):
 try:fn()
 except (RuntimeError,ValueError):check(label,True);return
 raise RuntimeError('SAFETY GATE FAILED: '+label)
b.owned_root();b.checked_database_config();b.checked_admin_client();check('approved runtime/server configuration',True)
for p in ['dev','test','live','../dev']:denied('old/native profile refused '+p,lambda:b.checked_profile(p))
for p in ['academic-dev','academic-test']:b.checked_profile(p);check('academic profile accepted '+p,True)
p=ROOT/'state/academic-test/profile.json';original=p.read_bytes()
try:
 for key,value in [('database','refind_laravel_test'),('database','refind_db_0_2_0'),('username','root'),('workspace_id','0'*32),('cookie','refind_v1'),('url','http://localhost/refind/')]:
  cfg=json.loads(original);cfg[key]=value;p.write_text(json.dumps(cfg),encoding='utf-8');denied('prelaunch profile '+key+' refused',lambda:b.checked_profile('academic-test'))
finally:p.write_bytes(original)
env=os.environ.copy();env.update(REFIND_PROFILE='academic-test',PHP_INI_SCAN_DIR=str(ROOT/'runtime/empty-ini-scan'))
for key,value in [('REFIND_PROFILE','test'),('REFIND_PROFILE','dev'),('DB_PORT','3306'),('DB_HOST','localhost'),('DB_DATABASE','refind_laravel_test'),('DB_USERNAME','root'),('DB_URL','mysql://root@127.0.0.1:3306/refind_db_0_2_0'),('SESSION_COOKIE','refind_v1')]:
 e=env.copy();e[key]=value
 p=subprocess.run([str(PHP),'-c',str(INI),'-d','error_log='+str(ROOT/'state/academic-test/php-error.log'),str(APP/'artisan'),'route:list','--json'],env=e,cwd=APP,capture_output=True,timeout=25)
 check('bootstrap override '+key+' refused',p.returncode!=0)
profiles=[json.loads((ROOT/'state'/p/'profile.json').read_text()) for p in ['dev','test','academic-dev','academic-test']]
for key in ['database','username','password','key']:
 check('independent '+key,len({p[key] for p in profiles})==4)
check('independent B cookies',profiles[2]['cookie']!=profiles[3]['cookie'] and all(p['cookie'] not in ['refind_v1','refind_test','refind_laravel_dev','refind_laravel_test'] for p in profiles[2:]))
check('original checkpoint branch unchanged',subprocess.check_output(['git','rev-parse','migration/laravel13'],cwd=APP,text=True).strip()=='ac03a9dae33c5d03318ac357fdb0c31abcaffa75')
check('academic branch selected',subprocess.check_output(['git','branch','--show-current'],cwd=APP,text=True).strip()=='migration/academic-b')
check('no remote',not subprocess.check_output(['git','remote'],cwd=APP,text=True).strip())
check('canonical academic launcher matches',(APP/'tools/workspace/academic-control.py').read_bytes()==(ROOT/'launchers/academic-control.py').read_bytes())
denied('unowned process refused',lambda:b.checked_process({'exe':psutil.Process().exe(),'required_args':[],'pid':os.getpid(),'created':psutil.Process().create_time()}))
provenance=json.loads((APP/'docs/SOURCE_PROVENANCE.json').read_text())
for rel,digest in provenance['source_files'].items():check('preserved frontend '+rel,hashlib.sha256((APP/'public'/rel).read_bytes()).hexdigest()==digest)
receipt={'passed':len(checks),'checks':checks,'native_connections':False,'original_profile_files_modified':False};(ROOT/'evidence/phase5/guard-results.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps({'academic_guard_checks_passed':len(checks)}))
complete_receipt(len(checks), receipt)
