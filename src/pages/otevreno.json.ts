import type { APIRoute } from 'astro';
import { PODNIKY, url, nahled, tydenPodniku } from '../lib/data';
import { KAVARNY, kavarnaUrl, adresaKavarny, tydenKavarny, nabizi } from '../lib/kavarny';

// Data pro stránku /otevreno-ted/: místa se známou otevírací dobou.
// [lat, lon, název, url, adresa, druh, týden, náhled, snídaně/brunch]
// druh: 1 = srdcovka, 0 = ověřený podnik, 2 = neověřená kavárna, 3 = kavárna ověřená majitelem
export const GET: APIRoute = () => {
  const podniky = PODNIKY.filter((p) => p.lat != null && p.lon != null && tydenPodniku(p)).map((p) => [
    p.lat,
    p.lon,
    p.nazev,
    url(p),
    p.adresa,
    p.doporuceny ? 1 : 0,
    tydenPodniku(p),
    nahled(p.foto),
    1,
  ]);
  const kavarny = KAVARNY.filter((k) => tydenKavarny(k)).map((k) => {
    const n = nabizi(k);
    return [
      k.lat,
      k.lon,
      k.nazev,
      kavarnaUrl(k),
      adresaKavarny(k) || k.mesto,
      k.overeno ? 3 : 2,
      tydenKavarny(k),
      k.fotky?.length ? `${k.fotky[0]}_640.webp` : '',
      n.snidane || n.brunch ? 1 : 0,
    ];
  });
  return new Response(JSON.stringify([...podniky, ...kavarny]), { headers: { 'Content-Type': 'application/json' } });
};
