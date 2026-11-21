"""Stable round 2 (R2-01): prepare fixture photos for upload and record their provenance.

    python -I -B tools/fixtures/r2_prepare.py

Reads tools/fixtures/r2_spec.json ("photos"), takes each source image from the fixture folder outside Git
(C:/ReFind-Laravel/fixtures/round2/incoming/{owner,originals,flickr}), and writes upload-ready JPEGs to
C:/ReFind-Laravel/fixtures/round2/prepared/<key>.jpg:
- EXIF orientation applied, then every metadata block dropped (no EXIF, GPS, ICC or comments survive);
- an optional framing crop (fractions of width/height, recorded in provenance);
- longest side at most 1200 px; JPEG quality stepped down until the file is at most 245,000 bytes
  (the app's limit is 250,000 bytes and 1200 px; it stores uploaded bytes unchanged).
Nothing is drawn on the images. provenance.json beside them records source, author, licence, licence URL,
page URL, crop and the SHA-256 of the original and prepared files. Commons metadata comes from the candidate
lists saved during the search (fixtures/round2/commons_metadata.json); Flickr metadata comes from Openverse
(fixtures/round2/flickr_metadata.json). Photos whose source is not downloaded yet are reported and skipped, so batches can
be prepared as their sources arrive.
"""
import hashlib, io, json, sys
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path('C:/ReFind-Laravel'); FIX = ROOT / 'fixtures/round2'
SPEC = Path(__file__).with_name('r2_spec.json')
MAX_SIDE, MAX_BYTES = 1200, 245_000


def sha(b):
    return hashlib.sha256(b).hexdigest()


def commons_file(title, meta):
    key = hashlib.sha1(title.encode()).hexdigest()[:12]
    for ext in ('.jpg', '.png'):
        p = FIX / 'incoming/originals' / (key + ext)
        if p.exists():
            return p
    raise SystemExit('missing original for ' + title + ' (' + key + ')')


def prepare(src_bytes, crop):
    im = Image.open(io.BytesIO(src_bytes)); im = ImageOps.exif_transpose(im)
    if im.mode in ('RGBA', 'LA', 'P'):
        im = im.convert('RGBA'); bg = Image.new('RGB', im.size, 'white'); bg.paste(im, mask=im.split()[-1]); im = bg
    im = im.convert('RGB')
    if crop:
        w, h = im.size; l, t, r, b = crop
        im = im.crop((round(l * w), round(t * h), round(r * w), round(b * h)))
    im.thumbnail((MAX_SIDE, MAX_SIDE), Image.Resampling.LANCZOS)
    for q in range(88, 40, -4):
        out = io.BytesIO(); im.save(out, 'JPEG', quality=q, optimize=True, progressive=False)  # no exif= / icc_profile=: nothing carried over
        if out.tell() <= MAX_BYTES:
            return out.getvalue(), im.size, q
    raise SystemExit('could not compress under the size limit')


def source_of(key, ph, meta, flickr):
    if ph['source'] == 'owner':
        src = FIX / 'incoming/owner' / ph['file']; m = meta.get(ph.get('commons_title', ''), {})
        return src, {'source': 'owner-supplied (WhatsApp export, 21 Nov 2026, owner-authorised for these fixtures)', 'file': ph['file'],
                     'probable_commons_title': ph.get('commons_title'), 'author': m.get('artist'), 'license': m.get('license'), 'license_url': m.get('license_url'), 'page': m.get('page')}
    if ph['source'] == 'flickr':
        f = flickr[ph['id']]; src = FIX / 'incoming/flickr' / (ph['id'] + '.jpg')
        return src, {'source': 'Flickr (found through Openverse)', 'title': f['title'], 'author': f['creator'], 'license': ('CC ' + f['license'].upper() + ' ' + f['license_version']) if f['license'] not in ('cc0', 'pdm') else f['license'].upper(),
                     'license_url': f.get('license_url'), 'page': f.get('page'), 'openverse_id': ph['id']}
    m = meta[ph['title']]
    return commons_file(ph['title'], meta), {'source': 'Wikimedia Commons', 'title': ph['title'], 'author': m.get('artist'), 'uploader': m.get('user'), 'license': m.get('license'),
                                             'license_url': m.get('license_url'), 'page': m.get('page'), 'taken': m.get('taken')}


def main():
    spec = json.loads(SPEC.read_text(encoding='utf-8'))
    meta = json.loads((FIX / 'commons_metadata.json').read_text(encoding='utf-8'))
    fpath = FIX / 'flickr_metadata.json'; flickr = json.loads(fpath.read_text(encoding='utf-8')) if fpath.exists() else {}
    (FIX / 'prepared').mkdir(parents=True, exist_ok=True)
    ppath = FIX / 'provenance.json'
    records = json.loads(ppath.read_text(encoding='utf-8'))['photos'] if ppath.exists() else {}
    missing = []
    for key, ph in spec['photos'].items():
        try:
            src, origin = source_of(key, ph, meta, flickr)
        except SystemExit as e:
            missing.append(key); continue
        if not src.exists():
            missing.append(key); continue
        raw = src.read_bytes(); data, size, q = prepare(raw, ph.get('crop'))
        (FIX / 'prepared' / (key + '.jpg')).write_bytes(data)
        records[key] = {**origin, 'batch': ph.get('batch', 'A'), 'use': ph.get('use', 'upload'), 'crop': ph.get('crop'), 'original_sha256': sha(raw), 'prepared_sha256': sha(data),
                        'prepared_bytes': len(data), 'prepared_size': list(size), 'jpeg_quality': q}
        print(key, size, len(data))
    ppath.write_text(json.dumps({'spec_version': spec['version'], 'photos': records}, indent=1, ensure_ascii=False), encoding='utf-8')
    print('prepared', len(records), 'photos; source not available yet:', missing)


if __name__ == '__main__':
    sys.exit(main())
