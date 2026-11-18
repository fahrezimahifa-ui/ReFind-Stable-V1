from pathlib import Path
import os,sys,json,subprocess,datetime,importlib.util,threading,time,psutil,argparse,uuid,hashlib,statistics
from verification_protocol import execute_checked
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';PHP=ROOT/'runtime/php85/php.exe';INI=ROOT/'runtime/php85/php.ini'
spec=importlib.util.spec_from_file_location('b',ROOT/'launchers/academic-control.py');control=importlib.util.module_from_spec(spec);spec.loader.exec_module(control)
parser=argparse.ArgumentParser();parser.add_argument('--checkpoint',choices=['crud','matching'],required=True);CHECKPOINT=parser.parse_args().checkpoint
RUN_ID=uuid.uuid4().hex
run=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');OUT=ROOT/'evidence/phase5'/('verification-'+CHECKPOINT+'-'+run+'-'+RUN_ID[:8]);OUT.mkdir();SUITES=OUT/'suites';SUITES.mkdir()
env=os.environ.copy();env.update(REFIND_PROFILE='academic-test',REFIND_QUERY_METRICS='1',PYTHONOPTIMIZE='0',PHP_INI_SCAN_DIR=str(ROOT/'runtime/empty-ini-scan'),COMPOSER_HOME=str(ROOT/'state/academic-runtime/composer-home'),COMPOSER_CACHE_DIR=str(ROOT/'state/academic-runtime/composer-cache'))
base=[str(PHP),'-c',str(INI),'-d','error_log='+str(ROOT/'state/academic-test/php-error.log')]
results={};failure=None;done=threading.Event();resources={'minimum_available_memory_mib':round(psutil.virtual_memory().available/1024**2),'maximum_php_process_rss_mib':0,'maximum_owned_db_rss_mib':0,'maximum_child_chrome_rss_mib':0}
def sample():
 while not done.is_set():
  try:
   resources['minimum_available_memory_mib']=min(resources['minimum_available_memory_mib'],round(psutil.virtual_memory().available/1024**2))
   chrome=0
   for p in psutil.process_iter(['exe','memory_info','cmdline']):
    e=(p.info['exe'] or '').lower().replace('\\','/');rss=p.info['memory_info'].rss/1024**2 if p.info['memory_info'] else 0
    if e==str(PHP).lower().replace('\\','/'):resources['maximum_php_process_rss_mib']=round(max(resources['maximum_php_process_rss_mib'],rss),1)
    if e.startswith(str(ROOT/'runtime/mariadb').lower().replace('\\','/')):resources['maximum_owned_db_rss_mib']=round(max(resources['maximum_owned_db_rss_mib'],rss),1)
   for p in psutil.Process().children(recursive=True):
    if p.name().lower()=='chrome.exe':chrome+=p.memory_info().rss/1024**2
   resources['maximum_child_chrome_rss_mib']=round(max(resources['maximum_child_chrome_rss_mib'],chrome),1)
  except (psutil.NoSuchProcess,psutil.AccessDenied):pass
  done.wait(.5)
thread=threading.Thread(target=sample,daemon=True);thread.start()
def command(label,args,profile='academic-test',extra_env=None):
 e=env.copy()
 if extra_env:e.update(extra_env)
 stdout,receipt=execute_checked(label,args,APP,e,SUITES,RUN_ID,profile or 'academic-test',CHECKPOINT)
 results[label]={'exit_code':0,'checks':receipt['checks'],'receipt':str(SUITES/(label+'.json')),'invocation_id':receipt['invocation_id']}
 print('PASS '+label+' ('+str(receipt['checks'])+' verified checks; fresh receipt)',flush=True)
 return stdout

def php(label,file,args=None,profile=None,extra_env=None):return command(label,base+[str(APP/file)]+(args or []),profile,extra_env)
def vendor(label,mode):return command(label,[sys.executable,'-B',APP/'tools/verification-vendor.py',mode])
try:
 control.stop();preflight=control.preflight();results['preflight']=preflight
 vendor('php-syntax','syntax')
 vendor('composer-validation','composer')
 vendor('platform-requirements','platform')
 command('verification-protocol',[sys.executable,'-B',APP/'tools/test-verification-protocol.py'])
 for mode in ['0','1']:
  php('metrics-bootstrap-'+mode,'tools/test-metrics-bootstrap.php',extra_env={'REFIND_QUERY_METRICS':mode})
 control.start_db()
 command('guard-tests',[sys.executable,'-B',APP/'tools/test-academic-guards.py'])
 for profile in ['academic-test','academic-dev']:
  audit=json.loads(php('privileges-'+profile,'tools/report-privileges.php',profile=profile));(OUT/('privileges-'+profile+'.json')).write_text(json.dumps(audit,indent=2),encoding='utf-8')
 php('write-foundation','tools/test-write-foundation.php')
 php('report-service','tools/test-report-service.php')
 if CHECKPOINT=='matching':
  php('native-matching-parity','tools/test-report-matching.php')
  command('matching-concurrency',[sys.executable,'-B',APP/'tools/test-report-matching-concurrency.py'])
  php('dev-reporting-effects','tools/test-reporting-dev.php',profile='academic-dev')
 receipt=json.loads((SUITES/'write-foundation.json').read_text())
 if receipt['results'].get('restore_verified') is not True:raise RuntimeError('Write restoration receipt is incomplete')
 php('academic-dev','tools/test-academic-dev.php',profile='academic-dev')
 php('search-regression','tools/test-search.php')
 php('reset-before-phpunit','tools/reset-test-auth.php',['--auth-state-only'])
 vendor('phpunit','phpunit')
 php('reset-before-http','tools/reset-test-auth.php',['--auth-state-only'])
 snapshot=json.loads(php('snapshot-before-http','tools/phase5-fixtures.php',['save']))['snapshot']
 php('eligibility-fixtures','tools/phase5-fixtures.php',['eligibility'])
 control.start('academic-test');command('report-http',[sys.executable,'-B',APP/'tools/test-report-http.py'])
 if CHECKPOINT=='matching':command('matching-http',[sys.executable,'-B',APP/'tools/test-report-matching-http.py'])
 control.stop('academic-test');php('restore-after-report-http','tools/phase5-fixtures.php',['restore',snapshot])
 php('reset-before-regression','tools/reset-test-auth.php',['--auth-state-only'])
 control.start('academic-test');command('http-contract',[sys.executable,'-B',APP/'tools/test-http.py'])
 control.start('academic-test',True);command('session-concurrency',[sys.executable,'-B',APP/'tools/test-concurrency.py'])
 control.stop('academic-test');control.start('academic-test');control.preflight();command('browser-ui',[sys.executable,'-B',APP/'tools/test-ui.py'])
 command('report-browser-ui',[sys.executable,'-B',APP/'tools/test-report-ui.py'])
 control.stop('academic-test')
 try:
  php('claim-read-browser-fixture','tools/phase5-fixtures.php',['claim-read-browser'])
  control.start('academic-test');command('claim-read-browser',[sys.executable,'-B',APP/'tools/test-claim-read-ui.py'])
 finally:
  control.stop('academic-test');php('restore-after-browser','tools/phase5-fixtures.php',['restore',snapshot])
 after=json.loads(php('poc-database-after','tools/poc-database-fingerprint.php'));before=json.loads((ROOT/'state/phase4-poc-database-before.json').read_text())
 if before!=after:raise RuntimeError('SAFETY GATE FAILED: original PoC databases differ')
 results['original-poc-databases-unchanged']={'pass':True,'connection_credentials':'original SELECT-only identities','tables_per_schema':15}
 command('poc-state-protection',[sys.executable,'-B',APP/'tools/protect-academic.py'])
 command('native-protection',[sys.executable,'-B',APP/'tools/check-native-phase5.py'])
 results['final-resource-preflight']=control.preflight()
except Exception as e:
 failure=str(e);print('FAIL '+failure,flush=True)
finally:
 try:
  control.stop()
  for port in [3307,8013,8014,8015]:control.port_free(port)
  results['final-owned-processes-stopped']={'pass':True}
 except Exception as e:failure=failure or str(e)
 done.set();thread.join(timeout=2)
 summary={'schema_version':1,'run_id':RUN_ID,'phase':5,'checkpoint':CHECKPOINT,'completed':failure is None,'passed':failure is None,'failure':failure,'results':results,'resources':resources,'receipt_directory':str(OUT),'timestamp_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
 (OUT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8');(ROOT/'evidence/phase5/latest-verification.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps({'phase5_checkpoint_passed':failure is None,'receipt_directory':str(OUT),'failure':failure}),flush=True)
sys.exit(0 if failure is None else 1)
