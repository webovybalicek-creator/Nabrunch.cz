import type { APIRoute } from 'astro';
import { VSECHNY_KAVARNY, kavarnaUrl } from '../../lib/kavarny';
import { PODNIKY } from '../../lib/data';

// Soubory pro PHP v /api/. Začínají podtržítkem → přímý přístup z webu zakazuje .htaccess.
// Astro ignoruje soubory začínající „_“, proto dynamická cesta.
// - _kavarny-kontakty.php: kontakty kaváren pro /api/kavarna.php (ověření majitele): id → [název, url, e-mail, web]
// - _podniky-nazvy.php: názvy ověřených podniků pro administraci hodnocení: id → název
export function getStaticPaths() {
  return [{ params: { soubor: '_kavarny-kontakty.php' } }, { params: { soubor: '_podniky-nazvy.php' } }];
}

const php = (data: unknown) =>
  new Response(`<?php\nreturn json_decode(<<<'JSON'\n${JSON.stringify(data)}\nJSON, true);\n`, { headers: { 'Content-Type': 'text/plain' } });

export const GET: APIRoute = ({ params }) => {
  if (params.soubor === '_podniky-nazvy.php') return php(Object.fromEntries(PODNIKY.map((p) => [p.id, p.nazev])));
  return php(Object.fromEntries(VSECHNY_KAVARNY.map((k) => [k.id, [k.nazev, kavarnaUrl(k), k.email ?? '', k.web ?? '']])));
};
