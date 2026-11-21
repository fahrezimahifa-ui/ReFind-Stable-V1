"""Stable round 2 (R2-01): download the source photos named in r2_spec.json that are not on disk yet.

    python -I -B tools/fixtures/r2_fetch.py [--batch A|B]

Commons originals go to C:/ReFind-Laravel/fixtures/round2/incoming/originals/<sha1(title)[:12]>.<ext> (the names
r2_prepare.py expects); Flickr photos go to incoming/flickr/<openverse id>.jpg (1024 px, falling back to 800/640 px).
Downloads are sequential and slow (one every 5 s), honour Retry-After, and stop at the first rate-limit refusal instead
of hammering the server; run again later to continue. Files are treated as untrusted data: nothing is executed, and
r2_prepare.py re-encodes every image before it is uploaded.
"""
import hashlib, json, sys, time
from pathlib import Path

import requests

FIX = Path('C:/ReFind-Laravel/fixtures/round2'); SPEC = json.loads(Path(__file__).with_name('r2_spec.json').read_text(encoding='utf-8'))
UA = {'User-Agent': 'ReFind-QA-fixture-fetcher/1.0 (local academic test data)'}


def main():
    batch = sys.argv[2] if len(sys.argv) == 3 and sys.argv[1] == '--batch' else None
    meta = json.loads((FIX / 'commons_metadata.json').read_text(encoding='utf-8'))
    fpath = FIX / 'flickr_metadata.json'; flickr = json.loads(fpath.read_text(encoding='utf-8')) if fpath.exists() else {}
    got = have = 0
    for key, ph in SPEC['photos'].items():
        if ph['source'] == 'owner' or (batch and ph.get('batch', 'A') != batch):
            continue
        if ph['source'] == 'commons':
            name = hashlib.sha1(ph['title'].encode()).hexdigest()[:12]; url = meta[ph['title']]['url']
            dst = FIX / 'incoming/originals' / (name + ('.png' if url.lower().endswith('.png') else '.jpg')); urls = [url]
        else:
            base = flickr[ph['id']]['base']; dst = FIX / 'incoming/flickr' / (ph['id'] + '.jpg'); urls = [base + s + '.jpg' for s in ('_b', '_c', '_z')]
        if dst.exists() and dst.stat().st_size:
            have += 1; continue
        dst.parent.mkdir(parents=True, exist_ok=True)
        for url in urls:
            r = requests.get(url, headers=UA, timeout=120)
            if r.status_code == 429:
                print('rate limited by', url.split('/')[2], '- retry after', r.headers.get('Retry-After', '?'), 's; stopping here. Run again later.')
                print('fetched', got, 'already present', have); return 2
            if r.status_code == 200 and r.headers.get('content-type', '').startswith('image/'):
                dst.write_bytes(r.content); got += 1; print('fetched', key); break
        else:
            print('not available:', key)
        time.sleep(5)
    print('fetched', got, 'already present', have); return 0


if __name__ == '__main__':
    sys.exit(main())
