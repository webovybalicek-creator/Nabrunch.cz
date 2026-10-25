#!/usr/bin/env python3
"""
Převezme SEO titulky a popisy z živého WordPressu, aby se po přechodu nezměnilo, co Google zná.

  python3 scripts/seo-z-wp.py wp-seo.json

Vstup: wp-seo.json z workflow „SEO audit WordPressu“ (větev seo-audit).
Výstup: src/data/seo-wp.json  { "/cesta/": {"t": titulek, "d": popis?} }
Layout (Base.astro) titulek z tohoto souboru použije přesně, popis jen pokud existuje.
"""
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlparse, unquote

ROOT = Path(__file__).resolve().parent.parent
data = json.load(open(sys.argv[1], encoding='utf-8'))['stranky']
out = {}
for url, v in sorted(data.items()):
    if v.get('status') != 200 or not v.get('title'):
        continue
    cesta = unquote(urlparse(url).path)
    t = re.sub(r'\s+', ' ', v['title'].replace('\xa0', ' ')).strip()
    d = re.sub(r'\s+', ' ', (v.get('description') or '').replace('\xa0', ' ')).strip()
    # Rozbité automatické popisy (poskládané z textu tlačítek) nepřebírat
    if re.search(r'(Více\s*){2,}', d) or len(d) < 40:
        d = ''
    out[cesta] = {'t': t, **({'d': d} if d else {})}
(ROOT / 'src/data/seo-wp.json').write_text(json.dumps(out, ensure_ascii=False, indent=1), encoding='utf-8')
print(f'Stránek: {len(out)}, s popisem: {sum(1 for x in out.values() if "d" in x)}')
