"""Build a private regression fixture from the OKAY QA evidence folder.

Usage: python3 -I dev/tools/build-okay-fixtures.py <evidence-dir> <out.json>

The output contains public page text and model outputs only (no credentials, no database).
It is written under tests/fixtures/private/ which is git-ignored.
"""
import json, os, re, sys

src, out = sys.argv[1], sys.argv[2]
inputs = json.load(open(os.path.join(src, 'qa-inputs.json')))
corr = json.load(open(os.path.join(src, 'qa-v4', 'reviewed-corrections.json')))
records = {}
for name in sorted(os.listdir(os.path.join(src, 'qa-v4'))):
    m = re.match(r'^([a-zA-Z_]+)-(\d)-(\d)\.json$', name)
    if not m:
        continue
    lang, page = m.group(1), int(m.group(2))
    data = json.load(open(os.path.join(src, 'qa-v4', name)))
    if not isinstance(data, dict) or not isinstance(data.get('translations'), dict):
        print('skip', name)
        continue
    strings = inputs[page]['strings']
    for k, t in data['translations'].items():
        k = int(k)
        if k >= len(strings):
            continue
        records[(lang, page, k)] = {'lang': lang, 'page': page, 'key': k, 'source': strings[k], 'model': t}
for case, fixes in corr.items():
    lang, page = case.rsplit('-', 1)
    for k, t in fixes.items():
        rec = records.get((lang, int(page), int(k)))
        if rec is None:
            strings = inputs[int(page)]['strings']
            rec = records[(lang, int(page), int(k))] = {'lang': lang, 'page': int(page), 'key': int(k), 'source': strings[int(k)], 'model': None}
        rec['reviewed'] = t
json.dump(sorted(records.values(), key=lambda r: (r['lang'], r['page'], r['key'])), open(out, 'w'), ensure_ascii=False, indent=1)
print(len(records), 'records ->', out)
