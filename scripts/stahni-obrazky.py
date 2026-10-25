#!/usr/bin/env python3
"""
Stáhne obrázky ze scripts/obrazky.json z nabrunch.cz (WordPress) do public/wp-content/uploads/…
a vytvoří náhledy do public/nahledy/… (WebP, šířka 640 px). Stažené soubory přeskakuje.

Originály: max. 1600 px na delší straně, JPEG kvalita 82 (PNG zůstává PNG, jen zmenšené).
Spouští workflow „Stažení obrázků“ (sandbox na nabrunch.cz nevidí).
"""
import io
import json
import sys
import urllib.request
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parent.parent
ZDROJ = 'https://nabrunch.cz'
MAX = 1600
NAHLED = 640

seznam = json.loads((ROOT / 'scripts/obrazky.json').read_text())
nove = chyby = 0
for cesta in seznam:
    cil = ROOT / 'public' / cesta.lstrip('/')
    nahled = ROOT / 'public/nahledy' / Path(cesta).relative_to('/wp-content/uploads').with_suffix('.webp')
    if cil.exists() and nahled.exists():
        continue
    try:
        if cil.exists():
            data = cil.read_bytes()
        else:
            req = urllib.request.Request(ZDROJ + urllib.request.quote(cesta), headers={'User-Agent': 'nabrunch-migrace/1.0'})
            data = urllib.request.urlopen(req, timeout=60).read()
        img = Image.open(io.BytesIO(data))
        img = ImageOps.exif_transpose(img)
        fmt = (img.format or Path(cesta).suffix.lstrip('.')).upper()
        cil.parent.mkdir(parents=True, exist_ok=True)
        if fmt in ('JPEG', 'JPG', 'PNG', 'WEBP') and max(img.size) > MAX:
            velky = img.copy()
            velky.thumbnail((MAX, MAX), Image.LANCZOS)
            if fmt in ('JPEG', 'JPG'):
                velky.convert('RGB').save(cil, 'JPEG', quality=82, optimize=True, progressive=True)
            elif fmt == 'PNG':
                velky.save(cil, 'PNG', optimize=True)
            else:
                velky.save(cil, 'WEBP', quality=82)
        else:
            cil.write_bytes(data)
        nahled.parent.mkdir(parents=True, exist_ok=True)
        maly = img.convert('RGBA' if img.mode in ('RGBA', 'LA', 'P') else 'RGB')
        maly.thumbnail((NAHLED, NAHLED * 2), Image.LANCZOS)
        maly.save(nahled, 'WEBP', quality=78, method=6)
        nove += 1
    except Exception as e:  # noqa: BLE001 – jeden vadný obrázek nesmí zastavit ostatní
        chyby += 1
        print(f'CHYBA {cesta}: {e}', file=sys.stderr)

print(f'Nově zpracováno: {nove}, chyb: {chyby}, celkem v seznamu: {len(seznam)}')
