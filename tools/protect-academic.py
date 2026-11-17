from verification_receipt import complete_receipt
from pathlib import Path
import hashlib,json,subprocess,sys
ROOT=Path('C:/ReFind-Laravel');before=json.loads((ROOT/'state/phase4-protection-before.json').read_text());bad=[]
for relative,digest in before['files'].items():
 p=ROOT/relative
 if not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=digest:bad.append(relative)
for profile in ['dev','test']:
 prior={n.replace('\\','/') for n in before['files'] if n.replace('\\','/').startswith('state/'+profile+'/')}
 now={str(p.relative_to(ROOT)).replace('\\','/') for p in (ROOT/'state'/profile).rglob('*') if p.is_file()}
 # Snapshot uses native separators for files enumerated on Windows.
 prior={n.replace('\\','/') for n in prior}
 if now!=prior:bad.append(profile+': file set')
sha=subprocess.check_output(['git','rev-parse','migration/laravel13'],cwd=ROOT/'laravel',text=True).strip()
if sha!=before['checkpoint']:bad.append('original branch')
print(json.dumps({'protected_poc_files':len(before['files']),'mismatches':bad,'original_branch_preserved':sha==before['checkpoint']}))
if not bad:complete_receipt(len(before['files'])+1, {'protected_poc_files':len(before['files']), 'mismatches':bad, 'original_branch_preserved':True})
sys.exit(bool(bad))
