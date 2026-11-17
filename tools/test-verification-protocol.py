from pathlib import Path
import json, os, subprocess, sys, uuid
from verification_protocol import execute_checked, VerificationRejected
from verification_receipt import complete_receipt
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';T=APP/'tools'
# All negative artifacts live in this invocation's fresh private evidence directory.
out=Path(os.environ['REFIND_VERIFY_RECEIPT']).parent/'negative-fixtures';run=uuid.uuid4().hex;checks=[]
def rejected(label,args,env=None):
    try:execute_checked(label,args,APP,env or os.environ.copy(),out,run)
    except VerificationRejected as error:
        if label=='foreign-suite':
            assert str(error)=='incorrect receipt identity: suite', 'Fixture must fail for suite mismatch, not an unrelated child failure'
            assert json.loads((out/(label+'.json')).read_text())['suite']!=label, 'Foreign-suite receipt must actually differ'
        checks.append(label)
    else:raise AssertionError('Negative verification was accepted: '+label)
for mode in ['missing','nonzero','malformed','incomplete','failed','zero','boolean-count','stale','foreign-suite','foreign-run','foreign-invocation','foreign-profile','foreign-checkpoint']:
    rejected(mode,[sys.executable,'-B',T/'verification-negative-child.py',mode])
execute_checked('positive-control',[sys.executable,'-B',T/'verification-negative-child.py','valid'],APP,os.environ.copy(),out,run);checks.append('positive-control')
rejected('positive-control',[sys.executable,'-B',T/'verification-negative-child.py','valid']);checks.append('preexisting-receipt-rejected')
base=[str(ROOT/'runtime/php85/php.exe'),'-c',str(ROOT/'runtime/php85/php.ini')]
for mode in ['exception','fatal']:
    label='early-'+mode
    rejected(label,base+[str(T/'test-cli-initialization.php'),mode])
    log=(out/(label+'.txt')).read_text()
    if 'PASS' in log or (out/(label+'.json')).exists() or 'CLI' not in log:raise AssertionError('Early failure reported success')
    checks.append(label+'-no-pass-or-receipt')
# A real bootstrap failure before the kernel finishes is also nonzero.
e={k:v for k,v in os.environ.items() if not k.startswith('REFIND_VERIFY_')};e['REFIND_PROFILE']='invalid-protected-profile'
p=subprocess.run(base+[str(T/'test-cli-initialization.php')],env=e,cwd=APP,capture_output=True,text=True,timeout=20)
(out/'bootstrap-failure.txt').write_text(p.stdout+p.stderr)
if p.returncode==0 or 'PASS' in p.stdout+p.stderr:raise AssertionError('Bootstrap failure was masked')
checks.append('bootstrap-failure-nonzero-no-pass')
complete_receipt(len(checks),dict(checks=checks,early_failures_do_not_report_pass=True,native_connections=False))
print(json.dumps(dict(verification_protocol_checks_passed=len(checks))))
