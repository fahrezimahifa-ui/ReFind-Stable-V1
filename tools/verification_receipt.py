"""Success receipts are emitted only after a verifier's final assertion."""
import json, os, re, time, uuid
from pathlib import Path
STARTED = time.time()
ROOT = Path('C:/ReFind-Laravel/evidence/phase5').resolve()
def complete_receipt(checks, results=None):
    names = ['RUN_ID','INVOCATION_ID','SUITE','PROFILE','CHECKPOINT','RECEIPT']
    values = {n:os.environ['REFIND_VERIFY_'+n] for n in names if 'REFIND_VERIFY_'+n in os.environ}
    if not values: return
    if (len(values)!=len(names) or not re.fullmatch('[a-f0-9]{32}',values['RUN_ID']) or not re.fullmatch('[a-f0-9]{32}',values['INVOCATION_ID']) or not re.fullmatch('[a-z0-9-]+',values['SUITE']) or values['PROFILE'] not in ['academic-test','academic-dev'] or values['PROFILE']!=os.environ.get('REFIND_PROFILE') or values['CHECKPOINT']!='crud'):
        raise RuntimeError('Invalid verification receipt identity')
    path = Path(values['RECEIPT']).resolve()
    if not path.is_relative_to(ROOT) or path.exists() or type(checks) is not int or checks<1:
        raise RuntimeError('Receipt requires a fresh isolated destination and positive checks')
    record = dict(schema_version=1,phase=5,checkpoint='crud',run_id=values['RUN_ID'],invocation_id=values['INVOCATION_ID'],suite=values['SUITE'],profile=values['PROFILE'],receipt_path=str(path).replace('\\','/').lower(),started_at=STARTED,completed_at=time.time(),passed=True,completed=True,checks=checks,results=results or {})
    tmp = path.with_name(path.name+'.'+uuid.uuid4().hex+'.tmp')
    with tmp.open('x',encoding='utf-8') as f:
        json.dump(record,f,indent=2); f.flush(); os.fsync(f.fileno())
    if path.exists(): raise RuntimeError('Receipt already exists')
    tmp.rename(path)
