"""Build the photo-search evaluation spec from the two private photo sets (no network, no database).

Sources (private, outside Git):
  C:/ReFind-Laravel/fixtures/visual-commons/corpus.json   native's licensed Wikimedia set (validation/held_out/discovery)
  C:/ReFind-Laravel/fixtures/round2/EVALUATION_MANIFEST.json + prepared/   phase8-dev round 2 photo reports (seeded cases)
Output: C:/ReFind-Laravel/fixtures/visual-eval/eval-spec.json

Splits (each: gallery entries + queries; a query never sees its own report):
  commons-validation / commons-held_out   native-size gallery: the split's 4 references + 6 distractors
  commons-validation-full / -held_out-full  the same plus the 60 discovery photos as extra distractors
  round2   every public photo of a seeded round 2 report; positives query with one side's photo
Query kinds: same_object (expected = target ids), lookalike / hard_negative (target listed, returns counted
separately), irrelevant (expected = none). Cutoffs are fitted on commons-validation-full only.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path('C:/ReFind-Laravel/fixtures')
COMMONS = ROOT / 'visual-commons'
ROUND2 = ROOT / 'round2'
OUT = ROOT / 'visual-eval' / 'eval-spec.json'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def commons_splits():
    assets = json.loads((COMMONS / 'corpus.json').read_text(encoding='utf-8'))['assets']
    discovery = [a for a in assets if a['split'] == 'discovery']
    out = {}
    for split in ('validation', 'held_out'):
        mine = [a for a in assets if a['split'] == split]
        gallery = [a for a in mine if a['role'] in ('reference', 'gallery')]
        by_object = {a['object_id']: a['id'] for a in gallery}
        queries = []
        for a in mine:
            if a['role'] == 'positive':
                queries.append({'id': a['id'], 'kind': 'same_object', 'expected': [by_object[a['object_id']]]})
            elif a['role'] == 'lookalike':
                queries.append({'id': a['id'], 'kind': 'lookalike', 'expected': [], 'target': [by_object[a['expected_object_id']]]})
            elif a['role'] == 'irrelevant':
                queries.append({'id': a['id'], 'kind': 'irrelevant', 'expected': []})
        photos = {a['id']: str(COMMONS / a['image']) for a in mine + discovery}
        entry = lambda a: {'id': a['id'], 'report': a['id'], 'file': photos[a['id']]}
        queries = [dict(q, file=photos[q['id']], own_report=None) for q in queries]
        out['commons-' + split] = {'gallery': [entry(a) for a in gallery], 'queries': queries}
        out['commons-' + split + '-full'] = {'gallery': [entry(a) for a in gallery + discovery], 'queries': queries}
    return out


def round2_split():
    manifest = json.loads((ROUND2 / 'EVALUATION_MANIFEST.json').read_text(encoding='utf-8-sig'))
    seeded = [c for c in manifest['cases'] if c['status'].startswith('seeded')]
    photo = lambda key: ROUND2 / 'prepared' / (key + '.jpg')
    reports = {}
    for case in seeded:
        for r in case['reports']:
            reports[r['key']] = r
    gallery = [{'id': p['key'], 'report': key, 'file': str(photo(p['key']))}
               for key, r in sorted(reports.items()) for p in r['public_photos'] if photo(p['key']).exists()]
    queries = []
    for case in seeded:
        rel = case['relationship']
        rs = case['reports']
        def first_photo(r):
            for p in r['public_photos'] + r['private_photos']:
                if photo(p['key']).exists():
                    return p['key']
            return None
        if rel.startswith('positive') and len(rs) == 2:
            for a, b in ((rs[0], rs[1]), (rs[1], rs[0])):
                key = first_photo(a)
                if key and any(g['report'] == b['key'] for g in gallery):
                    queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'same_object', 'expected': [b['key']], 'own_report': a['key'], 'file': str(photo(key)), 'note': rel})
        elif rel.startswith('hard negative') and len(rs) == 2:
            for a, b in ((rs[0], rs[1]), (rs[1], rs[0])):
                key = first_photo(a)
                if key:
                    queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'hard_negative', 'expected': [], 'target': [b['key']], 'own_report': a['key'], 'file': str(photo(key)), 'note': rel})
        elif rel.startswith('unrelated'):
            for a in rs:
                key = first_photo(a)
                if key:
                    queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'irrelevant', 'expected': [], 'own_report': a['key'], 'file': str(photo(key)), 'note': rel})
    return {'round2': {'gallery': gallery, 'queries': queries}}


def main():
    splits = commons_splits() | round2_split()
    files = sorted({e['file'] for s in splits.values() for e in s['gallery'] + s['queries']})
    spec = {'version': 1, 'calibrate_on': 'commons-validation-full', 'splits': splits,
            'files': {f: sha(Path(f)) for f in files}}
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(spec, indent=1) + '\n', encoding='utf-8')
    for name, s in splits.items():
        kinds = {}
        for q in s['queries']:
            kinds[q['kind']] = kinds.get(q['kind'], 0) + 1
        print(f"{name}: gallery {len(s['gallery'])}, queries {kinds}")
    print(f'{len(files)} distinct photo files -> {OUT}')


if __name__ == '__main__':
    main()
