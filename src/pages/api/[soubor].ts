import type { APIRoute } from 'astro';
import { VSECHNY_KAVARNY, kavarnaUrl } from '../../lib/kavarny';

// Kontakty kaváren pro /api/kavarna.php (ověření majitele): id → [název, url, e-mail, web].
// Soubor začíná podtržítkem → přímý přístup z webu zakazuje .htaccess.
// Astro ignoruje soubory začínající „_“, proto dynamická cesta
export function getStaticPaths() {
  return [{ params: { soubor: '_kavarny-kontakty.php' } }];
}

export const GET: APIRoute = () => {
  const data = Object.fromEntries(VSECHNY_KAVARNY.map((k) => [k.id, [k.nazev, kavarnaUrl(k), k.email ?? '', k.web ?? '']]));
  return new Response(`<?php\nreturn json_decode(<<<'JSON'\n${JSON.stringify(data)}\nJSON, true);\n`, { headers: { 'Content-Type': 'text/plain' } });
};
