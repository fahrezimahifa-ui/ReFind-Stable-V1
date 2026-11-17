"""One fail-closed process + fresh receipt acceptance path, shared by runner and negative tests."""
from pathlib import Path
import json, math, os, re, subprocess, time, uuid
class VerificationRejected(RuntimeError): pass
KEYS = ('RUN_ID','INVOCATION_ID','SUITE','PROFILE','CHECKPOINT','RECEIPT')
def validate_receipt(path, expected, started, ended):
    path=Path(path)
    try: data=json.loads(path.read_text(encoding='utf-8'))
    except (OSError,ValueError) as e: raise VerificationRejected('missing or malformed completion receipt') from e
    for key,value in expected.items():
        if data.get(key)!=value or type(data.get(key)) is not type(value):
            raise VerificationRejected('incorrect receipt identity: '+key)
    if data.get('passed') is not True or data.get('completed') is not True or type(data.get('checks')) is not int or data['checks']<1 or type(data.get('results')) is not dict:
        raise VerificationRejected('incomplete receipt')
    a,b=data.get('started_at'),data.get('completed_at')
    if any(type(x) not in (int,float) or not math.isfinite(x) for x in [a,b]) or not started<=a<=b<=ended or not started-1<=path.stat().st_mtime<=ended+1:
        raise VerificationRejected('stale or invalid receipt timestamps')
    return data

def execute_checked(label,args,cwd,env,out,run_id,profile='academic-test',checkpoint='crud',timeout=180):
    if not re.fullmatch('[a-z0-9-]+',label): raise VerificationRejected('invalid suite name')
    out=Path(out);out.mkdir(parents=True,exist_ok=True)
    path=(out/(label+'.json')).resolve()
    if path.exists(): raise VerificationRejected('receipt destination already exists')
    invocation=uuid.uuid4().hex
    child=env.copy()
    values=[run_id,invocation,label,profile,checkpoint,str(path)]
    child.update({'REFIND_VERIFY_'+k:v for k,v in zip(KEYS,values)})
    child['REFIND_PROFILE']=profile;child['PYTHONOPTIMIZE']='0'
    expected=dict(schema_version=1,phase=5,checkpoint=checkpoint,run_id=run_id,invocation_id=invocation,suite=label,profile=profile,receipt_path=str(path).replace('\\','/').lower())
    started=time.time()
    p=subprocess.run([str(x) for x in args],cwd=cwd,env=child,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=timeout)
    ended=time.time()
    (out/(label+'.txt')).write_text(p.stdout+p.stderr,encoding='utf-8')
    if p.returncode!=0: raise VerificationRejected(label+' exited '+str(p.returncode))
    receipt=validate_receipt(path,expected,started,ended)
    return p.stdout,receipt
