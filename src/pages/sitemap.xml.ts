import type { APIRoute } from 'astro';
import { SITE } from '../lib/config';
import { PODNIKY, url, lokality, lokalitaUrl, TEMATA, temaUrl } from '../lib/data';
import { clanky, clanekUrl, KATEGORIE, kategorieUrl } from '../lib/blog';

const den = (d: string | Date) => new Date(d).toISOString().slice(0, 10);

export const GET: APIRoute = async () => {
  const vse = await clanky();
  const posledni = den(PODNIKY.reduce((m, p) => (p.datum > m ? p.datum : m), '2025-01-01'));
  const polozky: { loc: string; lastmod?: string }[] = [
    { loc: '/', lastmod: posledni },
    { loc: '/podniky/', lastmod: posledni },
    { loc: '/mapa/', lastmod: posledni },
    { loc: '/lokality/', lastmod: posledni },
    ...lokality().map((l) => ({ loc: lokalitaUrl(l), lastmod: posledni })),
    { loc: '/kategorie/doporucene-podniky/', lastmod: posledni },
    { loc: '/kategorie/nove-pridane/', lastmod: posledni },
    ...TEMATA.map((t) => ({ loc: temaUrl(t), lastmod: posledni })),
    ...PODNIKY.map((p) => ({ loc: url(p), lastmod: den(p.datum.replace(' ', 'T')) })),
    { loc: '/blog/', lastmod: vse[0] ? den(vse[0].data.date) : undefined },
    ...KATEGORIE.map((k) => ({ loc: kategorieUrl(k) })),
    ...vse.map((c) => ({ loc: clanekUrl(c), lastmod: den(c.data.date) })),
    ...['/o-nas/', '/kontakt/', '/pridat-podnik/', '/casto-kladene-dotazy/', '/obchodni-podminky/', '/ochrana-osobnich-udaju/'].map((loc) => ({ loc })),
  ];
  const body =
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    polozky.map((x) => `<url><loc>${new URL(x.loc, SITE.url).href}</loc>${x.lastmod ? `<lastmod>${x.lastmod}</lastmod>` : ''}</url>`).join('\n') +
    '\n</urlset>\n';
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
};
