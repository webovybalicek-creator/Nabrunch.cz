import type { APIRoute } from 'astro';
import { KAVARNY, kavarnaUrl, adresaKavarny } from '../lib/kavarny';

// Body neověřených kaváren pro mapu (načítá se až po zapnutí přepínače)
export const GET: APIRoute = () =>
  new Response(JSON.stringify(KAVARNY.map((k) => [k.lat, k.lon, k.nazev, kavarnaUrl(k), adresaKavarny(k)])), {
    headers: { 'Content-Type': 'application/json' },
  });
