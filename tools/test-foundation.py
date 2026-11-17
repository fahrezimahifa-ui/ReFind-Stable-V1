raise RuntimeError('Historical Milestone A runner: use tools/verify-phase4.py on the academic branch.')
from pathlib import Path
import json,importlib.util,subprocess,os,psutil,hashlib
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';PHP=ROOT/'runtime/php85/php.exe';INI=ROOT/'runtime/php85/php.ini'
spec=importlib.util.spec_from_file_location('control',ROOT/'launchers/control.py');control=importlib.util.module_from_spec(spec);spec.loader.exec_module(control)
passed=[]
def check(label,condition):
 if not condition:raise AssertionError(label)
 passed.append(label)
def rejected(label,fn):
 try:fn()
 except RuntimeError:passed.append(label);return
 raise AssertionError(label)
control.owned_root();control.checked_database_config();control.checked_admin_client();passed.append('owned workspace/database/client templates accepted')
control.checked_profile('dev');control.checked_profile('test');passed.append('application profiles validated before launch')
profile_file=ROOT/'state/test/profile.json';profile_original=profile_file.read_bytes()
try:
 bad=json.loads(profile_original);bad['database']='refind_db_0_2_0';profile_file.write_text(json.dumps(bad),encoding='utf-8');rejected('native schema identity rejected before process launch',lambda:control.checked_profile('test'))
finally:profile_file.write_bytes(profile_original)

config=ROOT/'state/mariadb.ini';original=config.read_bytes()
try:
 config.write_bytes(original.replace(b'port=3307',b'port=3306'));rejected('native database port rejected before launch',control.checked_database_config)
 config.write_bytes(original.replace(b'datadir=C:/ReFind-Laravel/state/mariadb-data',b'datadir=C:/xampp/mysql/data'));rejected('native data directory rejected before launch',control.checked_database_config)
finally:config.write_bytes(original)
rejected('unowned process executable refused',lambda:control.checked_process({'pid':os.getpid(),'created':psutil.Process().create_time(),'exe':psutil.Process().exe(),'required_args':[]}))
base=os.environ.copy();base['REFIND_PROFILE']='test'
for name,value in [('REFIND_PROFILE','live'),('DB_PORT','3306'),('DB_DATABASE','refind_db_0_2_0'),('DB_USERNAME','root'),('DB_HOST','localhost'),('DB_URL','mysql://root@127.0.0.1:3306/refind_db_0_2_0')]:
 env=base.copy();env[name]=value
 r=subprocess.run([str(PHP),'-c',str(INI),str(APP/'artisan'),'route:list','--json'],cwd=APP,env=env,capture_output=True,timeout=20)
 check('fail-closed configuration '+name,r.returncode!=0)
env=base.copy();env.pop('REFIND_PROFILE',None)
r=subprocess.run([str(PHP),'-c',str(INI),str(APP/'artisan'),'route:list','--json'],cwd=APP,env=env,capture_output=True,timeout=20);check('missing explicit profile refused',r.returncode!=0)
for profile in ['dev','test']:
 p=ROOT/'state'/profile/'profile.json';cfg=json.loads(p.read_text());check('separate schema '+profile,cfg['database']=='refind_laravel_'+profile)
 check('native ignored runtime excluded '+profile,not (ROOT/'native-reference/app/config.local.php').exists() and not (ROOT/'native-reference/storage/auth/secret.json').exists())
refs=[]
for path in [ROOT/'native-reference',APP]:refs.append(subprocess.check_output(['git','-C',str(path),'rev-parse','--path-format=absolute','--git-common-dir'],text=True).strip())
check('independent Git metadata',len(set(refs))==2)
check('native reference pinned',subprocess.check_output(['git','-C',str(ROOT/'native-reference'),'rev-parse','HEAD'],text=True).strip()=='47eea8648d2d47faa895d855fe6da53e6b1a3087')
check('native reference clean',not subprocess.check_output(['git','--no-optional-locks','-C',str(ROOT/'native-reference'),'status','--porcelain'],text=True).strip())
check('Laravel has no remote',not subprocess.check_output(['git','-C',str(APP),'remote'],text=True).strip())
check('Laravel branch ownership',subprocess.check_output(['git','-C',str(APP),'symbolic-ref','--short','HEAD'],text=True).strip()=='migration/laravel13')
provenance=json.loads((APP/'docs/SOURCE_PROVENANCE.json').read_text())
for rel,digest in provenance['source_files'].items():check('preserved asset '+rel,hashlib.sha256((APP/'public'/rel).read_bytes()).hexdigest()==digest)
receipt={'passed':len(passed),'checks':passed,'native_connections':False,'destructive_commands':False}
(ROOT/'evidence/foundation-guard-results.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps({'foundation_guard_checks_passed':len(passed)}))
