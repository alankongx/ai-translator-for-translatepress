#!/usr/bin/env python3
"""Build the private phase-4 lists for OKAY from the acceptance folder (read-only).

  python3 -I dev/tools/okay-build-phase4.py <acceptance folder>

Writes (gitignored, never upload):
  tests/fixtures/private/okay-accepted-hhg-repair.json  rows the earlier HHG repair wrote to production
                                                        (language, id, original, new) -> accepted list
  tests/fixtures/private/okay-candidates-remaining.json legacy candidates minus the ones already repaired
                                                        (language, id, original, old) -> candidate import
Prints counts only. Never reads backups-private/.
"""
import glob
import json
import os
import sys


def lang_of(table):
    # wp_trp_dictionary_en_us_de_de -> de_de (the plugin matches languages case-insensitively)
    return table.split('trp_dictionary_', 1)[1].split('_', 2)[2]


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    src = os.path.abspath(sys.argv[1])
    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tests', 'fixtures', 'private')
    os.makedirs(out, exist_ok=True)

    accepted = []
    status = {}
    for f in sorted(glob.glob(os.path.join(src, 'production-deployment', 'manifests', '*.applied.json'))):
        for r in json.load(open(f, encoding='utf-8'))['records']:
            accepted.append({'language': lang_of(r['table']), 'id': str(r['row']['id']), 'original': r['row']['original'], 'new': r['new']})
            status[str(r['new_status'])] = status.get(str(r['new_status']), 0) + 1

    done = set()
    for f in sorted(glob.glob(os.path.join(src, 'legacy-candidates-v4', 'regeneration', '*-applied.json'))):
        d = json.load(open(f, encoding='utf-8'))
        for r in d.get('records', []):
            row = r.get('row', r)
            lang = lang_of(r['table']) if 'table' in r else r.get('language', '')
            done.add((lang.lower(), str(row.get('id'))))
    cands = json.load(open(os.path.join(src, 'legacy-candidates-v4', 'remaining-candidates.json'), encoding='utf-8'))
    remaining = [c for c in cands if (c['language'].lower(), str(c['id'])) not in done]
    structured = sum(1 for c in remaining if c['original'].lstrip().startswith(('{', '<script', '[')) or '&#8220;@context' in c['original'] or '"@context"' in c['original'])

    json.dump({'rows': accepted}, open(os.path.join(out, 'okay-accepted-hhg-repair.json'), 'w', encoding='utf-8'), ensure_ascii=False)
    json.dump({'rows': remaining}, open(os.path.join(out, 'okay-candidates-remaining.json'), 'w', encoding='utf-8'), ensure_ascii=False)
    print(json.dumps({'accepted_rows': len(accepted), 'accepted_new_status': status, 'candidates_listed': len(cands), 'already_repaired': len(done), 'candidates_remaining': len(remaining), 'remaining_structured': structured}))


if __name__ == '__main__':
    main()
