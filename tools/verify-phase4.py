from pathlib import Path
import os,sys,json,subprocess,datetime,importlib.util,threading,time,psutil
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';PHP=ROOT/'runtime/php85/php.exe';INI=ROOT/'runtime/php85/php.ini'
spec=importlib.util.spec_from_file_location('b',ROOT/'launchers/academic-control.py');control=importlib.util.module_from_spec(spec);spec.loader.exec_module(control)
control.stop()
preflight=control.preflight()
run=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');OUT=ROOT/'evidence/phase4'/('verification-'+run);OUT.mkdir()
env=os.environ.copy();env.update(REFIND_PROFILE='academic-test',PHP_INI_SCAN_DIR=str(ROOT/'runtime/empty-ini-scan'),COMPOSER_HOME=str(ROOT/'state/academic-runtime/composer-home'),COMPOSER_CACHE_DIR=str(ROOT/'state/academic-runtime/composer-cache'))
base=[str(PHP),'-c',str(INI),'-d','error_log='+str(ROOT/'state/academic-test/php-error.log')]
results={};failure=None;done=threading.Event();resources={'minimum_available_memory_mib':preflight['available_memory_mib'],'maximum_php_process_rss_mib':0,'maximum_owned_db_rss_mib':0,'maximum_child_chrome_rss_mib':0}
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
def command(label,args,profile=None):
 e=env.copy()
 if profile:e['REFIND_PROFILE']=profile
 p=subprocess.run([str(x) for x in args],cwd=APP,env=e,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
 (OUT/(label+'.txt')).write_text(p.stdout+p.stderr,encoding='utf-8')
 if p.returncode:raise RuntimeError(label+' failed; inspect isolated receipt')
 results[label]={'exit_code':0};print('PASS '+label,flush=True);return p.stdout
def php(label,file,args=None,profile=None):return command(label,base+[str(APP/file)]+(args or []),profile)
try:
 results['preflight']=preflight;control.start_db()
 files=[]
 for folder in ['app','bootstrap','config','routes','tests','tools']:files.extend(p for p in (APP/folder).rglob('*.php') if not p.is_relative_to(APP/'bootstrap/cache'))
 for p in files:
  q=subprocess.run(base+['-l',str(p)],capture_output=True,env=env,timeout=20)
  if q.returncode:raise RuntimeError('PHP syntax failed: '+str(p.relative_to(APP)))
 results['php-syntax']={'files':len(files),'pass':True};print('PASS php-syntax '+str(len(files)),flush=True)
 command('composer-validation',base+[str(ROOT/'runtime/composer.phar'),'validate','--strict'])
 command('platform-requirements',base+[str(ROOT/'runtime/composer.phar'),'check-platform-reqs'])
 command('guard-tests',[sys.executable,'-B',APP/'tools/test-academic-guards.py'])
 php('write-foundation','tools/test-write-foundation.php')
 receipt=json.loads((ROOT/'evidence/phase4/write-results.json').read_text());assert receipt['restore_verified'];results['write-checks']=receipt['passed']
 php('academic-dev','tools/test-academic-dev.php',profile='academic-dev')
 php('search-regression','tools/test-search.php')
 php('reset-before-phpunit','tools/reset-test-auth.php',['--auth-state-only'])
 php('phpunit','vendor/phpunit/phpunit/phpunit',['--configuration',str(APP/'phpunit.xml')])
 php('reset-before-http','tools/reset-test-auth.php',['--auth-state-only'])
 control.start('academic-test');command('http-contract',[sys.executable,'-B',APP/'tools/test-http.py'])
 control.start('academic-test',True);command('session-concurrency',[sys.executable,'-B',APP/'tools/test-concurrency.py'])
 control.stop('academic-test');control.start('academic-test');control.preflight();command('browser-ui',[sys.executable,'-B',APP/'tools/test-ui.py'])
 after=json.loads(php('poc-database-after','tools/poc-database-fingerprint.php'));before=json.loads((ROOT/'state/phase4-poc-database-before.json').read_text())
 if before!=after:raise RuntimeError('SAFETY GATE FAILED: original PoC databases differ')
 results['original-poc-databases-unchanged']={'pass':True,'connection_credentials':'original SELECT-only identities','tables_per_schema':15}
 command('poc-state-protection',[sys.executable,'-B',APP/'tools/protect-academic.py'])
 command('native-protection',[sys.executable,'-B',APP/'tools/check-native-phase4.py'])
except Exception as e:
 failure=str(e);print('FAIL '+failure,flush=True)
finally:
 try:
  control.stop()
  for port in [3307,8013,8014,8015]:control.port_free(port)
  results['final-owned-processes-stopped']={'pass':True}
 except Exception as e:failure=failure or str(e)
 done.set();thread.join(timeout=2)
 summary={'phase':4,'passed':failure is None,'failure':failure,'results':results,'resources':resources,'receipt_directory':str(OUT),'timestamp_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
 (OUT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8');(ROOT/'evidence/phase4/latest-verification.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps({'phase4_passed':failure is None,'receipt_directory':str(OUT),'failure':failure}),flush=True)
sys.exit(0 if failure is None else 1)
