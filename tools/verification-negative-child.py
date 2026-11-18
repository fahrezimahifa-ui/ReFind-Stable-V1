"""Synthetic receipt failures, never application/database operations."""
import json, os, sys, time
from pathlib import Path
from verification_receipt import complete_receipt
mode=sys.argv[1]
if mode=='missing':sys.exit(0)
complete_receipt(1,dict(synthetic=True))
p=Path(os.environ['REFIND_VERIFY_RECEIPT'])
if mode=='valid':sys.exit(0)
if mode=='nonzero':sys.exit(7)
if mode=='malformed':p.write_text('{',encoding='utf-8');sys.exit(0)
r=json.loads(p.read_text())
if mode=='incomplete':r['completed']=False
elif mode=='failed':r['passed']=False
elif mode=='zero':r['checks']=0
elif mode=='boolean-count':r['checks']=True
elif mode=='stale':r['started_at']=r['completed_at']=time.time()-3600
elif mode=='foreign-suite':
    expected_suite=os.environ['REFIND_VERIFY_SUITE']
    r['suite']=expected_suite+'-unexpected'
    assert r['suite']!=expected_suite, 'Foreign-suite fixture must differ before rejection is tested'
elif mode=='foreign-run':r['run_id']='0'*32
elif mode=='foreign-invocation':r['invocation_id']='0'*32
elif mode=='foreign-profile':r['profile']='academic-dev'
elif mode=='foreign-checkpoint':
    expected_checkpoint=os.environ['REFIND_VERIFY_CHECKPOINT']
    r['checkpoint']='matching' if expected_checkpoint=='crud' else 'crud'
    assert r['checkpoint']!=expected_checkpoint
else:raise RuntimeError('Unknown negative fixture')
p.write_text(json.dumps(r),encoding='utf-8')
