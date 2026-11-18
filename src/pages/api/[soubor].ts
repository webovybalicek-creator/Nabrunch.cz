import type { APIRoute } from 'astro';
import { VSECHNY_KAVARNY, kavarnaUrl } from '../../lib/kavarny';
import { PODNIKY } from '../../lib/data';
import { getCollection } from 'astro:content';

// Soubory pro PHP v /api/. Začínají podtržítkem → přímý přístup z webu zakazuje .htaccess.
// Astro ignoruje soubory začínající „_“, proto dynamická cesta.
// - _kavarny-kontakty.php: kontakty kaváren pro /api/kavarna.php (ověření majitele): id → [název, url, e-mail, web]
// - _podniky-nazvy.php: názvy ověřených podniků pro administraci hodnocení: id → název
// - _clanky-seznam.php: seznam článků pro editor v administraci: [{slug, title, date}]
export function getStaticPaths() {
  return [{ params: { soubor: '_kavarny-kontakty.php' } }, { params: { soubor: '_podniky-nazvy.php' } }, { params: { soubor: '_clanky-seznam.php' } }];
}

const php = (data: unknown) =>
  new Response(`<?php\nreturn json_decode(<<<'JSON'\n${JSON.stringify(data)}\nJSON, true);\n`, { headers: { 'Content-Type': 'text/plain' } });

export const GET: APIRoute = async ({ params }) => {
  if (params.soubor === '_clanky-seznam.php') {
    const clanky = (await getCollection('blog')).sort((a, b) => b.data.date.getTime() - a.data.date.getTime());
    return php(clanky.map((c) => ({ slug: c.id, title: c.data.title, date: c.data.date.toISOString() })));
  }
  if (params.soubor === '_podniky-nazvy.php') return php(Object.fromEntries(PODNIKY.map((p) => [p.id, p.nazev])));
  return php(Object.fromEntries(VSECHNY_KAVARNY.map((k) => [k.id, [k.nazev, kavarnaUrl(k), k.email ?? '', k.web ?? '']])));
};
