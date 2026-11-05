"""Vytvoří AVIF verze ke všem WebP obrázkům v public/obrazky a public/nahledy (jen chybějící).

Web je servíruje přes <picture> (AVIF, záloha WebP). Spouští ho i workflow „AVIF obrázky“
po každém přidání nových WebP fotek.
"""
import glob
import os
import sys
from concurrent.futures import ProcessPoolExecutor

from PIL import Image

KVALITA = 55


def preved(webp: str) -> int:
    avif = webp[:-5] + '.avif'
    if os.path.exists(avif) and os.path.getmtime(avif) >= os.path.getmtime(webp):
        return 0
    with Image.open(webp) as im:
        im.save(avif, 'AVIF', quality=KVALITA, speed=6)
    # Když by AVIF vyšel větší než WebP (výjimečně), nech raději WebP
    if os.path.getsize(avif) >= os.path.getsize(webp):
        with Image.open(webp) as im:
            im.save(avif, 'AVIF', quality=KVALITA - 15, speed=6)
    return 1


if __name__ == '__main__':
    soubory = sorted(glob.glob('public/obrazky/**/*.webp', recursive=True) + glob.glob('public/nahledy/**/*.webp', recursive=True))
    with ProcessPoolExecutor() as ex:
        n = sum(ex.map(preved, soubory, chunksize=8))
    print(f'AVIF: nově převedeno {n} z {len(soubory)} obrázků')
    sys.exit(0)
