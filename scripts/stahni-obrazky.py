#!/usr/bin/env python3
"""
Stáhne obrázky ze scripts/obrazky.json z nabrunch.cz (WordPress) a uloží je jako WebP:
  public/obrazky/<cesta>.webp  – max. 1400 px (detail, články, galerie)
  public/nahledy/<cesta>.webp  – šířka 640 px (karty)
<cesta> = cesta pod /wp-content/uploads/ bez přípony. Hotové soubory přeskakuje.
Favicon se navíc uloží jako public/favicon.png.

Původní adresy /wp-content/uploads/… přesměrovává public/.htaccess na /obrazky/….webp.
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
VELKY, NAHLED = 1400, 640
FAVICON = '/wp-content/uploads/2025/03/nabrunch-favicon.png'


def uloz(img: Image.Image, cil: Path, max_sirka: int, max_vyska: int, kvalita: int) -> None:
    kopie = img.copy()
    kopie.thumbnail((max_sirka, max_vyska), Image.LANCZOS)
    cil.parent.mkdir(parents=True, exist_ok=True)
    kopie.save(cil, 'WEBP', quality=kvalita, method=6)


seznam = json.loads((ROOT / 'scripts/obrazky.json').read_text())
nove = chyby = 0
for cesta in seznam:
    rel = Path(cesta).relative_to('/wp-content/uploads').with_suffix('.webp')
    velky = ROOT / 'public/obrazky' / rel
    maly = ROOT / 'public/nahledy' / rel
    if velky.exists() and maly.exists() and (cesta != FAVICON or (ROOT / 'public/favicon.png').exists()):
        continue
    try:
        req = urllib.request.Request(ZDROJ + urllib.request.quote(cesta), headers={'User-Agent': 'nabrunch-migrace/1.0'})
        data = urllib.request.urlopen(req, timeout=60).read()
        img = ImageOps.exif_transpose(Image.open(io.BytesIO(data)))
        img = img.convert('RGBA' if img.mode in ('RGBA', 'LA', 'P') else 'RGB')
        uloz(img, velky, VELKY, VELKY, 80)
        uloz(img, maly, NAHLED, NAHLED * 2, 76)
        if cesta == FAVICON:
            (ROOT / 'public/favicon.png').write_bytes(data)
        nove += 1
    except Exception as e:  # noqa: BLE001 – jeden vadný obrázek nesmí zastavit ostatní
        chyby += 1
        print(f'CHYBA {cesta}: {e}', file=sys.stderr)

print(f'Nově zpracováno: {nove}, chyb: {chyby}, celkem v seznamu: {len(seznam)}')
