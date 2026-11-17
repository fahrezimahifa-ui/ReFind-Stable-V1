raise RuntimeError('Historical Milestone A runner: use tools/verify-phase4.py on the academic branch.')
from pathlib import Path
import os,sys,json,subprocess,datetime,importlib.util,hashlib
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';PHP=ROOT/'runtime/php85/php.exe';INI=ROOT/'runtime/php85/php.ini';COMPOSER=ROOT/'runtime/composer.phar'
run=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');OUT=ROOT/'evidence'/('verification-'+run);OUT.mkdir()
env=os.environ.copy();env.update(REFIND_PROFILE='test',COMPOSER_HOME=str(ROOT/'state/composer-home'),COMPOSER_CACHE_DIR=str(ROOT/'state/composer-cache'),COMPOSER_MAX_PARALLEL_HTTP='4')
spec=importlib.util.spec_from_file_location('owned_control',ROOT/'launchers/control.py');control=importlib.util.module_from_spec(spec);spec.loader.exec_module(control)
results={};failure=None

def command(label,args):
 p=subprocess.run([str(a) for a in args],cwd=APP,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
 (OUT/(label+'.txt')).write_text(p.stdout+p.stderr,encoding='utf-8')
 if p.returncode:raise RuntimeError(label+' failed; inspect its private verification receipt')
 results[label]={'exit_code':0};print('PASS '+label,flush=True);return p.stdout

def php(label,script,args=None):return command(label,[PHP,'-c',INI,APP/script]+(args or []))
try:
 control.stop();control.port_free(3307);control.port_free(8013);control.port_free(8014);control.port_free(8015);results['graceful-stop-boundary']={'pass':True}
 control.start('test')
 before=json.loads(php('database-before','tools/audit.php'));(OUT/'database-before.json').write_text(json.dumps(before,indent=2),encoding='utf-8')
 original=json.loads((ROOT/'state/test/audit-before.json').read_text(encoding='utf-8-sig'))
 if original!=before:raise RuntimeError('Synthetic business data differs from initial pre-test fingerprint')
 phpfiles=[]
 for folder in ['app','bootstrap','config','routes','tests','tools']:
  phpfiles.extend(p for p in (APP/folder).rglob('*.php') if not p.is_relative_to(APP/'bootstrap/cache'))
 for p in phpfiles:
  r=subprocess.run([str(PHP),'-c',str(INI),'-l',str(p)],capture_output=True,env=env,timeout=15)
  if r.returncode:raise RuntimeError('PHP syntax failure: '+str(p.relative_to(APP)))
 results['php-syntax']={'files':len(phpfiles),'pass':True};print('PASS php-syntax '+str(len(phpfiles)),flush=True)
 command('composer-validation',[PHP,'-c',INI,COMPOSER,'validate','--strict'])
 command('platform-requirements',[PHP,'-c',INI,COMPOSER,'check-platform-reqs'])
 audit=command('dependency-audit',[PHP,'-c',INI,COMPOSER,'audit','--locked','--format=json']);(OUT/'dependency-audit.json').write_text(audit,encoding='utf-8')
 command('foundation-guards',[sys.executable,'-B',APP/'tools/test-foundation.py'])
 php('search-regression','tools/test-search.php')
 php('reset-before-phpunit','tools/reset-test-auth.php',['--auth-state-only'])
 php('phpunit','vendor/phpunit/phpunit/phpunit',['--configuration',str(APP/'phpunit.xml')])
 php('reset-before-http','tools/reset-test-auth.php',['--auth-state-only'])
 command('http-contract',[sys.executable,'-B',APP/'tools/test-http.py'])
 control.start('test',True)
 command('session-concurrency',[sys.executable,'-B',APP/'tools/test-concurrency.py'])
 control.stop('test');control.start('test')
 command('browser-ui',[sys.executable,'-B',APP/'tools/test-ui.py'])
 after=json.loads(php('database-after','tools/audit.php'));(OUT/'database-after.json').write_text(json.dumps(after,indent=2),encoding='utf-8')
 if before!=after:raise RuntimeError('Synthetic business data/schema/authoritative media changed during request tests')
 results['business-data-and-media-unchanged']={'pass':True}
 command('native-protection',[sys.executable,'-B',APP/'tools/verify-protection.py'])
 r=control.records();results['resource-sample']={k:{'rss_mib':round(control.checked_process(v).memory_info().rss/1024**2,1)} for k,v in r.items() if control.checked_process(v)}
except Exception as e:
 failure=str(e);print('FAIL '+failure,flush=True)
finally:
 try:
  control.stop();control.port_free(3307);control.port_free(8013);control.port_free(8014);control.port_free(8015);results['final-owned-processes-stopped']={'pass':True}
 except Exception as e:failure=failure or ('Shutdown requires review: '+str(e))
 summary={'phase':'0-2 technical proof of concept','passed':failure is None,'failure':failure,'results':results,'receipt_directory':str(OUT),'timestamp_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
 (OUT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8');(ROOT/'evidence/latest-verification.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps({'verification_passed':failure is None,'receipt_directory':str(OUT),'failure':failure}),flush=True)
sys.exit(0 if failure is None else 1)
