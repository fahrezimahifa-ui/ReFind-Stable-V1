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
BATCH_B = ROOT / 'round2-batchB'
SPEC_R2 = Path(__file__).resolve().parent / 'fixtures' / 'r2_spec.json'
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


def round2_batch_b_split():
    """Fresh held-out: round 2 batch B (never seeded, never used for fitting). Gallery = every public photo of every
    round 2 report (batch A and B) so batch A reports act as distractors; queries come from the pending cases."""
    spec = json.loads(SPEC_R2.read_text(encoding='utf-8'))
    manifest = {c['case']: c for c in json.loads((ROUND2 / 'EVALUATION_MANIFEST.json').read_text(encoding='utf-8-sig'))['cases']}
    cases = [c for c in json.loads((ROUND2 / 'cases.json').read_text(encoding='utf-8-sig'))['cases'] if manifest[c['case']]['status'].startswith('pending')]
    def path(key):
        for candidate in (BATCH_B / (key + '.jpg'), ROUND2 / 'prepared' / (key + '.jpg')):
            if candidate.exists():
                return candidate
        return None
    reports = {r['key']: r for r in spec['reports']}
    gallery = [{'id': k, 'report': r['key'], 'file': str(path(k))} for r in spec['reports'] for k in r['photos'] if path(k)]
    def query_photo(r):
        for k in r['photos'] + list(r.get('private') or []):
            if path(k):
                return k
        return None
    queries = []
    for case in cases:
        rel, keys = case['relationship'], case['reports']
        rs = [reports[k] for k in keys if k in reports]
        if rel.startswith('positive'):
            found = [r['key'] for r in rs if r['type'] == 'FOUND']
            for q in case.get('queries') or []:
                if path(q):
                    queries.append({'id': case['case'] + ':' + q, 'kind': 'same_object', 'expected': found or [r['key'] for r in rs], 'own_report': None, 'file': str(path(q)), 'note': rel})
            if len(rs) == 2:
                for a, b in ((rs[0], rs[1]), (rs[1], rs[0])):
                    k = query_photo(a)
                    if k and any(g['report'] == b['key'] for g in gallery):
                        queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'same_object', 'expected': [b['key']], 'own_report': a['key'], 'file': str(path(k)), 'note': rel})
        elif rel.startswith('hard negative') and len(rs) == 2:
            for a, b in ((rs[0], rs[1]), (rs[1], rs[0])):
                k = query_photo(a)
                if k:
                    queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'hard_negative', 'expected': [], 'target': [b['key']], 'own_report': a['key'], 'file': str(path(k)), 'note': rel})
        elif rel.startswith('unrelated'):
            for a in rs:
                k = query_photo(a)
                if k:
                    queries.append({'id': case['case'] + ':' + a['key'], 'kind': 'irrelevant', 'expected': [], 'own_report': a['key'], 'file': str(path(k)), 'note': rel})
    out = {'round2-batchB': {'gallery': gallery, 'queries': queries}}
    # Unrelated photos (fruit, nature...) from the licensed held-out split, searched against this campus gallery.
    assets = json.loads((COMMONS / 'corpus.json').read_text(encoding='utf-8'))['assets']
    out['round2-batchB-unrelated'] = {'gallery': gallery, 'queries': [{'id': a['id'], 'kind': 'irrelevant', 'expected': [], 'own_report': None, 'file': str(COMMONS / a['image'])} for a in assets if a['split'] == 'held_out' and a['role'] == 'irrelevant']}
    return out


def main():
    splits = commons_splits() | round2_split() | round2_batch_b_split()
    files = sorted({e['file'] for s in splits.values() for e in s['gallery'] + s['queries']})
    spec = {'version': 2, 'calibrate_on': ['commons-validation-full', 'round2'], 'held_out': ['commons-held_out-full', 'round2-batchB', 'round2-batchB-unrelated'], 'splits': splits,
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
