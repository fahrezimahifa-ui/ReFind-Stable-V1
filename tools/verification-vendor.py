"""Adapters for third-party verifiers; exit zero alone is insufficient."""
from pathlib import Path
import os, subprocess, sys, xml.etree.ElementTree as ET
from verification_receipt import complete_receipt
ROOT=Path('C:/ReFind-Laravel');APP=ROOT/'laravel';base=[str(ROOT/'runtime/php85/php.exe'),'-c',str(ROOT/'runtime/php85/php.ini')]
mode=sys.argv[1]
def run(args):
    p=subprocess.run(args,cwd=APP,env=os.environ.copy(),capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=170)
    sys.stdout.write(p.stdout);sys.stderr.write(p.stderr)
    if p.returncode: raise RuntimeError('Third-party verifier exited '+str(p.returncode))
    return p.stdout+p.stderr
if mode=='syntax':
    files=[p for folder in ['app','bootstrap','config','routes','tests','tools'] for p in (APP/folder).rglob('*.php') if not p.is_relative_to(APP/'bootstrap/cache')]
    for p in files:
        output=run(base+['-l',str(p)])
        if 'No syntax errors detected' not in output:raise RuntimeError('Incomplete lint result')
    complete_receipt(len(files),dict(files=len(files)))
elif mode=='composer':
    output=run(base+[str(ROOT/'runtime/composer.phar'),'validate','--strict','--no-ansi'])
    if 'is valid' not in output:raise RuntimeError('Incomplete Composer validation')
    complete_receipt(1,dict(composer_valid=True))
elif mode=='platform':
    import json
    output=run(base+[str(ROOT/'runtime/composer.phar'),'check-platform-reqs','--format=json','--no-ansi'])
    # Progress messages are on stderr; parse the actual stdout separately below if present.
    start=output.find('[');end=output.rfind(']')
    data=json.loads(output[start:end+1])
    if not data or any(row.get('status')!='success' for row in data):raise RuntimeError('Incomplete platform requirements')
    complete_receipt(len(data),dict(platform_requirements=data))
elif mode=='phpunit':
    log=Path(os.environ['REFIND_VERIFY_RECEIPT']).with_suffix('.junit.xml')
    if log.exists():raise RuntimeError('JUnit destination must be fresh')
    run(base+[str(APP/'vendor/phpunit/phpunit/phpunit'),'--configuration',str(APP/'phpunit.xml'),'--log-junit',str(log),'--fail-on-warning','--fail-on-risky','--fail-on-skipped','--fail-on-incomplete','--fail-on-empty-test-suite'])
    tree=ET.parse(log);cases=tree.findall('.//testcase')
    if not cases or any(tree.findall('.//'+tag) for tag in ['failure','error','skipped']):raise RuntimeError('Incomplete PHPUnit result')
    complete_receipt(len(cases),dict(tests=len(cases),assertions=sum(int(n.get('assertions','0')) for n in cases),junit=str(log)))
else:raise RuntimeError('Unsupported verifier adapter')
