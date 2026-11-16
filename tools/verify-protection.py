from pathlib import Path
import subprocess,hashlib,json,sys
ROOT=Path('C:/ReFind-Laravel');before=json.loads((ROOT/'state/protection-before.json').read_text());checks=[];mismatches=[]
for name,prior in before['installations'].items():
 p=Path('C:/xampp/htdocs')/name
 head=subprocess.check_output(['git','-C',str(p),'rev-parse','HEAD'],text=True).strip();status=subprocess.check_output(['git','--no-optional-locks','-C',str(p),'status','--porcelain=v1','--untracked-files=all'],text=True)
 if head!=prior['head'] or status!=prior['status']:mismatches.append(name+': Git state')
 for rel,digest in prior['modified_hashes'].items():
  if hashlib.sha256((p/rel).read_bytes()).hexdigest()!=digest:mismatches.append(name+': uncommitted file '+rel)
 current={}
 for relative in ['app/config.local.php','storage/auth/secret.json']:
  f=p/relative;current[relative]=hashlib.sha256(f.read_bytes()).hexdigest() if f.is_file() else None
 for collection in ['items','evidence','claims','avatars','sessions','auth']:
  folder=p/'storage'/collection;listing={str(f.relative_to(p)).replace('\\','/'):hashlib.sha256(f.read_bytes()).hexdigest() for f in folder.rglob('*') if f.is_file()} if folder.exists() else {}
  current[collection]=hashlib.sha256(json.dumps(listing,sort_keys=True).encode()).hexdigest()
 for label,digest in prior['private_fingerprints'].items():
  if current[label]!=digest:mismatches.append(name+': '+label)
 checks.append(name)
for filename,digest in before['shared_configuration_hashes'].items():
 if hashlib.sha256(Path(filename).read_bytes()).hexdigest()!=digest:mismatches.append(filename)
 checks.append(filename)
receipt={'protected_installations_and_configs_checked':len(checks),'uncommitted_paths_preserved':sum(len(p['modified_hashes']) for p in before['installations'].values()),'mismatches':mismatches,'native_database_connections_performed':False}
(ROOT/'evidence/protection-results.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps(receipt));sys.exit(1 if mismatches else 0)
