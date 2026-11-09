import type { APIRoute } from 'astro';
import { SITE } from '../lib/config';
import { PODNIKY, velky, url, lokality, lokalitaUrl, TEMATA, temaUrl, kombinace, kombinaceUrl } from '../lib/data';
import { KAVARNY, mestaKavaren, indexovatMesto, indexovatKavarnu, kavarnaUrl, mestoKavarenUrl } from '../lib/kavarny';
import { clanky, clanekUrl, KATEGORIE, kategorieUrl } from '../lib/blog';

const den = (d: string | Date) => new Date(d).toISOString().slice(0, 10);

export const GET: APIRoute = async () => {
  const vse = await clanky();
  const posledni = den(PODNIKY.reduce((m, p) => (p.datum > m ? p.datum : m), '2025-01-01'));
  const polozky: { loc: string; lastmod?: string; obrazky?: string[] }[] = [
    { loc: '/', lastmod: posledni },
    { loc: '/podniky/', lastmod: posledni },
    { loc: '/mapa/', lastmod: posledni },
    { loc: '/otevreno-ted/', lastmod: posledni },
    { loc: '/lokality/', lastmod: posledni },
    ...lokality().map((l) => ({ loc: lokalitaUrl(l), lastmod: posledni })),
    ...kombinace().map((k) => ({ loc: kombinaceUrl(k), lastmod: posledni })),
    { loc: '/kategorie/doporucene-podniky/', lastmod: posledni },
    { loc: '/kategorie/nove-pridane/', lastmod: posledni },
    ...TEMATA.map((t) => ({ loc: temaUrl(t), lastmod: posledni })),
    ...PODNIKY.map((p) => ({ loc: url(p), lastmod: den(p.datum.replace(' ', 'T')), obrazky: [...new Set([p.foto, ...p.galerie])].filter(Boolean) })),
    { loc: '/blog/', lastmod: vse[0] ? den(vse[0].data.date) : undefined },
    ...KATEGORIE.map((k) => ({ loc: kategorieUrl(k) })),
    ...vse.map((c) => ({ loc: clanekUrl(c), lastmod: den(c.data.date), obrazky: c.data.image ? [c.data.image] : [] })),
    // Neověřené kavárny: jen zveřejněné a s dost údaji
    ...(KAVARNY.length ? [{ loc: '/kavarny/' }] : []),
    ...mestaKavaren()
      .filter(indexovatMesto)
      .map((m) => ({ loc: mestoKavarenUrl(m.slug), lastmod: den(m.kavarny.reduce((x, k) => (k.zverejnit! > x ? k.zverejnit! : x), '2026-01-01')) })),
    ...KAVARNY.filter(indexovatKavarnu).map((k) => ({ loc: kavarnaUrl(k), lastmod: den(k.zverejnit!) })),
    ...['/o-nas/', '/kontakt/', '/pridat-podnik/', '/casto-kladene-dotazy/', '/obchodni-podminky/', '/ochrana-osobnich-udaju/'].map((loc) => ({ loc })),
  ];
  const body =
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">\n' +
    polozky.map((x) => `<url><loc>${new URL(x.loc, SITE.url).href}</loc>${x.lastmod ? `<lastmod>${x.lastmod}</lastmod>` : ''}${(x.obrazky ?? []).map((o) => `<image:image><image:loc>${new URL(velky(o), SITE.url).href}</image:loc></image:image>`).join('')}</url>`).join('\n') +
    '\n</urlset>\n';
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
};
