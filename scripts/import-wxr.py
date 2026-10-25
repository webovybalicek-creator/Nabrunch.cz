#!/usr/bin/env python3
"""
Převod WordPress exportu (WXR) z nabrunch.cz do dat Astro webu.

  python3 scripts/import-wxr.py cesta/k/exportu.xml

Vytvoří / přepíše:
  src/data/podniky.json     – publikované podniky (CPT „podniky“) s poli z JetEngine
  src/data/lokality.json    – lokality (taxonomie „lokalita“)
  src/content/blog/*.md     – publikované články (HTML tělo, frontmatter)
  scripts/obrazky.json      – seznam obrázků z wp-content/uploads, které web používá

Obrázky se nestahují tady, ale workflow „Stažení obrázků“ (síť ze sandboxu na nabrunch.cz nevede).
Cesty obrázků zůstávají stejné jako na WordPressu (/wp-content/uploads/…), aby fungovaly i staré odkazy.
"""
import html
import json
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET
from pathlib import Path

NS = {
    'wp': 'http://wordpress.org/export/1.2/',
    'content': 'http://purl.org/rss/1.0/modules/content/',
    'excerpt': 'http://wordpress.org/export/1.2/excerpt/',
}
ROOT = Path(__file__).resolve().parent.parent
UPLOADS = re.compile(r'https?://(?:www\.)?nabrunch\.cz(/wp-content/uploads/[^\s"\'<>(),]+)')
VELIKOST = re.compile(r'-(\d+)x(\d+)(\.[a-z0-9]+)$', re.I)


def slugify(s: str) -> str:
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', s.lower()).strip('-')


def meta(item) -> dict:
    out = {}
    for m in item.findall('wp:postmeta', NS):
        out[m.findtext('wp:meta_key', namespaces=NS)] = m.findtext('wp:meta_value', namespaces=NS) or ''
    return out


def php_checkboxy(v: str) -> list[str]:
    """JetEngine checkbox: a:2:{s:6:"Kartou";s:4:"true";…} → zaškrtnuté položky."""
    return [k for k, val in re.findall(r's:\d+:"([^"]*)";s:\d+:"(true|false)";', v or '') if val == 'true']


def ano(v: str) -> bool | None:
    v = (v or '').strip().lower()
    return True if v in ('true', '1', 'ano', 'yes') else False if v in ('false', '0', 'ne', 'no') else None


def text(v: str) -> str:
    return html.unescape(re.sub(r'<[^>]+>', '', v or '')).strip()


def hodnota(v: str) -> str | None:
    """Prázdné pole nebo zástupná pomlčka („-“, „–“) → None."""
    v = (v or '').strip()
    return None if v in ('', '-', '–', '—', '@') else v


def radky(v: str) -> list[str]:
    """Otevírací doba z WYSIWYG: <p>Po - Pá: 7:30 - 19:30<br />So: …</p> → řádky."""
    v = re.sub(r'<br\s*/?>|</p>\s*<p[^>]*>', '\n', v or '', flags=re.I)
    return [r.strip() for r in text(v).split('\n') if r.strip()]


def main(wxr: str) -> None:
    root = ET.parse(wxr).getroot()
    items = root.findall('channel/item')
    typ = lambda i: i.findtext('wp:post_type', namespaces=NS)
    stav = lambda i: i.findtext('wp:status', namespaces=NS)

    # ---------------------------------------------------------------- přílohy
    prilohy = {}  # id → cesta /wp-content/uploads/…
    for i in items:
        if typ(i) == 'attachment':
            u = i.findtext('wp:attachment_url', namespaces=NS) or ''
            m = UPLOADS.search(u)
            if m:
                prilohy[i.findtext('wp:post_id', namespaces=NS)] = m.group(1)
    originaly = set(prilohy.values())
    pouzite: set[str] = set()

    def cesta(url: str) -> str:
        """Absolutní URL obrázku → kořenová cesta originálu (bez -1024x683)."""
        m = UPLOADS.search(url)
        if not m:
            return url
        p = m.group(1)
        o = VELIKOST.sub(r'\3', p)
        if o in originaly:
            p = o
        else:
            # WordPress u velkých fotek ukládá originál jako -scaled
            s = VELIKOST.sub(r'-scaled\3', p)
            if s in originaly:
                p = s
        pouzite.add(p)
        return p

    def obsah(h: str) -> str:
        h = re.sub(r'<!--\s*/?wp:.*?-->', '', h or '', flags=re.S)
        h = re.sub(r'\s(srcset|sizes)="[^"]*"', '', h)
        h = re.sub(r'\sclass=""', '', h)
        h = UPLOADS.sub(lambda m: cesta(m.group(0)), h)
        h = re.sub(r'https?://(?:www\.)?nabrunch\.cz/', '/', h)
        return re.sub(r'\n{3,}', '\n\n', h).strip()

    # ---------------------------------------------------------------- lokality
    lokality = {}
    for t in root.findall('channel/wp:term', NS):
        if t.findtext('wp:term_taxonomy', namespaces=NS) == 'lokalita':
            nazev = html.unescape(t.findtext('wp:term_name', namespaces=NS))
            lokality[nazev] = t.findtext('wp:term_slug', namespaces=NS)

    # ---------------------------------------------------------------- podniky
    podniky = []
    for i in items:
        if typ(i) != 'podniky' or stav(i) != 'publish':
            continue
        m = meta(i)
        kat = [c.text for c in i.findall('category') if c.get('domain') == 'kategorie']
        lok = [html.unescape(c.text) for c in i.findall('category') if c.get('domain') == 'lokalita']
        # Souřadnice z geokódování JetEngine (klíč <hash>_lat/_lng; neplatné hodnoty typu „je-coord-error“ přeskočit)
        lat = lng = None
        for k, v in m.items():
            if not re.fullmatch(r'-?\d+(\.\d+)?', (v or '').strip()):
                continue
            if k.endswith('_lat') and lat is None:
                lat = float(v)
            if k.endswith('_lng') and lng is None:
                lng = float(v)
        foto = prilohy.get(m.get('_thumbnail_id', ''))
        if foto:
            pouzite.add(foto)
        galerie = [cesta(u.strip()) for u in (m.get('galerie') or '').split(',') if u.strip()]
        galerie = [g for n, g in enumerate(galerie) if g != foto and g not in galerie[:n]]
        ig = hodnota(m.get('instagram'))
        podniky.append({
            'id': int(i.findtext('wp:post_id', namespaces=NS)),
            'slug': i.findtext('wp:post_name', namespaces=NS),
            'nazev': html.unescape(i.findtext('title') or '').strip(),
            'datum': i.findtext('wp:post_date', namespaces=NS),
            'doporuceny': 'Doporučené podniky' in kat,
            'lokality': lok,
            'adresa': (m.get('adresa') or '').strip(),
            'lat': lat,
            'lon': lng,
            'oteviraciDoba': radky(m.get('oteviracidoba')),
            'web': hodnota(m.get('web')),
            'instagram': ig,
            'instagramUrl': hodnota(m.get('instagramodkaz')),
            'telefon': hodnota(re.sub(r'\s', '', m.get('kontakt-odkaz') or '')),
            'telefonText': hodnota(m.get('kontakt-zobrazeni')),
            'googleMaps': hodnota(m.get('googlemapslink')),
            'rezervace': hodnota(m.get('rezervace')),
            'platba': php_checkboxy(m.get('platba')),
            'wifi': ano(m.get('wifi')),
            'wc': ano(m.get('wc')),
            'kuchyne': php_checkboxy(m.get('kuchyne')),
            'atmosfera': php_checkboxy(m.get('atmosfera')),
            'sezeni': php_checkboxy(m.get('sezeni')),
            'tipNadpis': text(m.get('nastipnadpis')) or None,
            'tip': obsah(m.get('nastiptext')) or None,
            'popis': obsah(i.findtext('content:encoded', namespaces=NS)),
            'seoTitulek': text(m.get('_seopress_titles_title')) or None,
            'seoPopis': text(m.get('_seopress_titles_desc')) or None,
            'foto': foto,
            'galerie': galerie,
        })
    podniky.sort(key=lambda p: p['datum'], reverse=True)

    # ---------------------------------------------------------------- články
    blog = ROOT / 'src/content/blog'
    blog.mkdir(parents=True, exist_ok=True)
    for f in blog.glob('*.md'):
        f.unlink()
    pocet_clanku = 0
    for i in items:
        if typ(i) != 'post' or stav(i) != 'publish':
            continue
        m = meta(i)
        slug = i.findtext('wp:post_name', namespaces=NS)
        slug_cisty = slugify(html.unescape(re.sub(r'%[0-9a-f]{2}', '', slug, flags=re.I))) or slug
        titulek = html.unescape(i.findtext('title') or '').strip()
        foto = prilohy.get(m.get('_thumbnail_id', ''))
        if foto:
            pouzite.add(foto)
        perex = text(i.findtext('excerpt:encoded', namespaces=NS))
        fm = {
            'title': titulek,
            'description': text(m.get('_seopress_titles_desc')) or perex or None,
            'seoTitle': text(m.get('_seopress_titles_title')) or None,
            'date': i.findtext('wp:post_date', namespaces=NS).replace(' ', 'T'),
            'image': foto,
            'wpSlug': slug if slug != slug_cisty else None,
        }
        fm = {k: v for k, v in fm.items() if v}
        telo = obsah(i.findtext('content:encoded', namespaces=NS))
        frontmatter = '\n'.join(f'{k}: {json.dumps(v, ensure_ascii=False)}' for k, v in fm.items())
        (blog / f'{slug_cisty}.md').write_text(f'---\n{frontmatter}\n---\n\n{telo}\n', encoding='utf-8')
        pocet_clanku += 1

    # ---------------------------------------------------------------- textové stránky (bez Elementoru)
    stranky = {}
    for i in items:
        slug = i.findtext('wp:post_name', namespaces=NS)
        if typ(i) == 'page' and stav(i) == 'publish' and slug in ('ochrana-osobnich-udaju', 'obchodni-podminky'):
            stranky[slug] = {
                'titulek': html.unescape(i.findtext('title') or '').strip(),
                'obsah': obsah(i.findtext('content:encoded', namespaces=NS)),
            }

    # ---------------------------------------------------------------- zápis
    data = ROOT / 'src/data'
    data.mkdir(parents=True, exist_ok=True)
    (data / 'podniky.json').write_text(json.dumps(podniky, ensure_ascii=False, indent=1), encoding='utf-8')
    (data / 'stranky.json').write_text(json.dumps(stranky, ensure_ascii=False, indent=1), encoding='utf-8')
    (data / 'lokality.json').write_text(json.dumps(lokality, ensure_ascii=False, indent=1), encoding='utf-8')
    # Logo/favicon a obrázek pro sdílení (používá layout webu)
    pouzite.update({'/wp-content/uploads/2025/03/nabrunch-favicon.png', '/wp-content/uploads/2025/03/nabrunch-1.jpg'})
    obrazky = sorted(p for p in pouzite if p.startswith('/wp-content/uploads/'))
    (ROOT / 'scripts/obrazky.json').write_text(json.dumps(obrazky, ensure_ascii=False, indent=1), encoding='utf-8')
    print(f'Podniků: {len(podniky)}, článků: {pocet_clanku}, lokalit: {len(lokality)}, obrázků: {len(obrazky)}')


if __name__ == '__main__':
    main(sys.argv[1])
