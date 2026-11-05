import type { APIRoute } from 'astro';
import { KAVARNY, mestaKavaren, mestoKavarenUrl, kavarnaUrl } from '../lib/kavarny';
import { PODNIKY, lokality, lokalitaUrl, url, mesto, nahled, TEMATA, temaUrl, podnikyTematu } from '../lib/data';

// Index pro našeptávač (načte se až při prvním psaní):
// m: [název, url, počet, nadřazená lokalita]  – města a pražské obvody
// s: [název, url, místo, náhled, hledaný text] – podniky
// t: [název, url, počet, emoji]               – témata
export const GET: APIRoute = () => {
  const m = lokality().map((l) => [l.nazev, lokalitaUrl(l), l.podniky.length, l.nadrazena ? 'Praha' : '']);
  const s = PODNIKY.map((p) => [p.nazev, url(p), p.adresa || mesto(p), nahled(p.foto), `${p.adresa} ${p.lokality.join(' ')}`]);
  const t = TEMATA.map((x) => [x.nadpis, temaUrl(x), podnikyTematu(x).length, x.emoji, `${x.kratce} ${x.hodnota}`]);
  // c: [město, url, počet kaváren], k: [kavárna, url, místo, id] – neověřené kavárny z OSM
  const c = mestaKavaren().map((x) => [x.nazev, mestoKavarenUrl(x.slug), x.kavarny.length]);
  const k = KAVARNY.map((x) => [x.nazev, kavarnaUrl(x), [x.ulice, x.obvod ?? x.mesto].filter(Boolean).join(', '), x.id]);
  return new Response(JSON.stringify({ m, s, t, c, k }), { headers: { 'Content-Type': 'application/json' } });
};
