from pathlib import Path
import os,sys,json,time,uuid,subprocess,importlib.util
from verification_receipt import complete_receipt
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel'
spec=importlib.util.spec_from_file_location('academic_control',ROOT/'launchers/academic-control.py');control=importlib.util.module_from_spec(spec);spec.loader.exec_module(control)
control.preflight()
env={k:v for k,v in os.environ.items() if not k.startswith('REFIND_VERIFY_')};env['REFIND_PROFILE']='academic-test'
base=[str(ROOT/'runtime/php85/php.exe'),'-c',str(ROOT/'runtime/php85/php.ini')];checks=[]
def check(label,ok):
 if not ok:raise AssertionError('MATCHING CONCURRENCY GATE FAILED: '+label)
 checks.append(label)
def php(file,*args):
 p=subprocess.run(base+[str(APP/'tools'/file)]+list(args),env=env,cwd=APP,capture_output=True,text=True,timeout=40)
 if p.returncode:raise RuntimeError('Matching helper failed: '+file+' '+p.stderr[-1000:])
 return json.loads(p.stdout)
def pair(payloads):
 directory=ROOT/'state/academic-test/tmp'/('matching-concurrency-'+uuid.uuid4().hex);directory.mkdir();workers=[]
 try:
  for index,payload in enumerate(payloads,1):
   path=directory/('worker-'+str(index)+'.json');path.write_text(json.dumps(payload),encoding='utf-8')
   proc=subprocess.Popen(base+[str(APP/'tools/matching-worker.php'),str(path)],env=env,cwd=APP,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,creationflags=subprocess.CREATE_NO_WINDOW)
   workers.append((proc,path))
  deadline=time.monotonic()+12
  while not all(Path(str(path)+'.ready').is_file() for proc,path in workers):
   if any(proc.poll() is not None for proc,path in workers) or time.monotonic()>deadline:raise RuntimeError('Matching workers failed to reach barrier')
   time.sleep(.02)
  (directory/'go').write_text('go');results=[]
  for proc,path in workers:
   stdout,stderr=proc.communicate(timeout=25)
   check('worker exits successfully '+path.name,proc.returncode==0)
   result=json.loads(stdout);check('worker produces complete success '+path.name,result.get('ok') is True);results.append(result['result'])
  return results
 finally:
  for proc,path in workers:
   if proc.poll() is None:proc.terminate();proc.wait(timeout=5)
primary_category = '1'
comparison_category = '3'
active_categories = {str(category) for category in php('phase5-fixtures.php', 'active-categories')['active_category_ids']}
check('fixture categories are distinct and active', primary_category != comparison_category and {primary_category, comparison_category} <= active_categories)
snapshot=php('phase5-fixtures.php','save')['snapshot']
# Fixtures use a fixed past event day; same exact public tokens force a score of 100.
data=dict(action='create',type='LOST',title='Quartz jade cobalt amber copper',description='Zygomorphic syzygy.',category_id=primary_category,location_id='35',date='2026-01-15',time='',clue='Private concurrent report clue never scored')
created=pair([dict(actor=1,data=data),dict(actor=2,data=data|dict(type='FOUND'))]);lost,found=[x['id'] for x in created]
state=php('matching-concurrency-state.php',lost,found)
check('one coherent suggestion after concurrent opposite creates',state['pair_count']==1 and state['pair_score']=='100.00')
check('both created reports reach possible-match status',state['statuses']==['POTENTIAL_MATCH','POTENTIAL_MATCH'])
check('exactly one submission per owner',state['submissions']==[1,1])
check('exactly one status alert per owner',state['status_alerts']==[1,1])
check('each report has initial and transition history',state['history_counts']==[2,2])
check('private clues absent from notification text',state['private_text_absent'])
pair([dict(actor=1,data=data|dict(action='update',id=lost,category_id=comparison_category)),dict(actor=2,data=dict(action='delete',id=found))])
state=php('matching-concurrency-state.php',lost,found)
check('concurrent edit/delete removes stale pair',state['pair_count']==0)
check('survivor is open and deleted counterpart absent',state['statuses']==['OPEN',None])
check('historical alerts survive deletion with nullable item',state['deleted_alerts_retained'])
check('no orphan match history or notification foreign keys',state['no_orphans'])
restored=php('phase5-fixtures.php','restore',snapshot);check('exact isolated database/media restoration',restored.get('restored') is True)
complete_receipt(len(checks),dict(checks=checks,synthetic_profile='academic-test',separate_worker_processes=2,native_connections=False,restore_verified=True))
print(json.dumps(dict(matching_concurrency_checks_passed=len(checks))))
