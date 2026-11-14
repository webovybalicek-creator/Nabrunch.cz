import type { APIRoute } from 'astro';
import { SITE } from '../lib/config';
import { PODNIKY, url, mesta, lokalitaUrl, TEMATA, temaUrl, podnikyTematu, podnikuText } from '../lib/data';
import { KAVARNY } from '../lib/kavarny';
import { clanky, clanekUrl, nazev, perex } from '../lib/blog';
import { dobaText } from '../lib/dotazy';

// /llms.txt – stručný přehled webu pro AI vyhledávače a asistenty (formát llmstxt.org).
// Obsahuje jen fakta z dat: ověřené podniky s adresou a otevírací dobou, témata a články.
const abs = (cesta: string) => new URL(cesta, SITE.url).href;

export const GET: APIRoute = async () => {
  const radky: string[] = [
    `# ${SITE.nazev}`,
    '',
    `> Průvodce snídaňovými a brunchovými místy v Česku. ${podnikuText(PODNIKY.length)} osobně ověřených redakcí naBrunch.cz v ${mesta().length} městech, s adresou, otevírací dobou, fotkami a tipem, co si dát. Navíc přehled dalších kaváren z OpenStreetMap (označené jako neověřené).`,
    '',
    'Všechny podniky v sekci „Ověřené podniky“ redakce osobně navštívila nebo prověřila. Otevírací doba se může změnit, aktuální stav ukazuje stránka podniku.',
    '',
    '## Hlavní stránky',
    '',
    `- [Přehled podniků](${abs('/podniky/')}): všechny ověřené podniky s filtry podle města, kuchyně a atmosféry`,
    `- [Otevřeno teď](${abs('/otevreno-ted/')}): nejbližší místa na snídani, která mají právě otevřeno`,
    `- [Mapa](${abs('/mapa/')}): všechny podniky na mapě`,
    `- [Naše srdcovky](${abs('/kategorie/doporucene-podniky/')}): podniky, které redakce doporučuje nejvíc`,
    `- [Lokality](${abs('/lokality/')}): brunch podle měst a pražských obvodů`,
    `- [Kavárny podle měst](${abs('/kavarny/')}): ${KAVARNY.length.toLocaleString('cs-CZ')} dalších kaváren z OpenStreetMap, zatím neověřených`,
    `- [Blog](${abs('/blog/')}): tipy, kam na brunch, a vysvětlení pojmů (flat white, matcha, shakshuka…)`,
    '',
    '## Témata',
    '',
    ...TEMATA.map((t) => `- [${t.nadpis}](${abs(temaUrl(t))}): ${podnikuText(podnikyTematu(t).length)}`),
    '',
    '## Ověřené podniky podle měst',
  ];

  const vypsane = new Set<number>();
  for (const m of [...mesta()].sort((a, b) => b.podniky.length - a.podniky.length)) {
    const podniky = m.podniky.filter((p) => !vypsane.has(p.id)).sort((a, b) => a.nazev.localeCompare(b.nazev, 'cs'));
    if (!podniky.length) continue;
    radky.push('', `### ${m.nazev}`, '', `Stránka lokality: ${abs(lokalitaUrl(m))}`, '');
    for (const p of podniky) {
      vypsane.add(p.id);
      const vlastnosti = [
        p.doporuceny && 'srdcovka redakce',
        p.atmosfera.includes('Pet-friendly') && 'pet-friendly',
        (p.atmosfera.includes('Zahrádka') || p.sezeni.includes('Zahrádka')) && 'zahrádka',
        p.kuchyne.includes('Veganská') && 'veganské',
        p.kuchyne.includes('Zdravá/fitness') && 'zdravé',
      ].filter(Boolean);
      const doba = dobaText(p);
      radky.push(
        `- [${p.nazev}](${abs(url(p))}): ${p.adresa}${doba ? `. Otevřeno: ${doba}` : ''}${vlastnosti.length ? `. ${vlastnosti.join(', ')}` : ''}`,
      );
    }
  }

  const vse = await clanky();
  radky.push('', '## Články', '', ...vse.slice(0, 40).map((c) => `- [${nazev(c)}](${abs(clanekUrl(c))}): ${perex(c, 140)}`));
  radky.push('', '## Kontakt', '', `- E-mail redakce: ${SITE.email}`, `- Přidat podnik: ${abs('/pridat-podnik/')}`, '');

  return new Response(radky.join('\n'), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
