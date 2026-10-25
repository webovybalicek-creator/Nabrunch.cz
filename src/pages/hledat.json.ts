import type { APIRoute } from 'astro';
import { PODNIKY, lokality, lokalitaUrl, url, mesto, nahled, TEMATA, temaUrl, podnikyTematu } from '../lib/data';

// Index pro našeptávač (načte se až při prvním psaní):
// m: [název, url, počet, nadřazená lokalita]  – města a pražské obvody
// s: [název, url, místo, náhled, hledaný text] – podniky
// t: [název, url, počet, emoji]               – témata
export const GET: APIRoute = () => {
  const m = lokality().map((l) => [l.nazev, lokalitaUrl(l), l.podniky.length, l.nadrazena ? 'Praha' : '']);
  const s = PODNIKY.map((p) => [p.nazev, url(p), p.adresa || mesto(p), nahled(p.foto), `${p.adresa} ${p.lokality.join(' ')}`]);
  const t = TEMATA.map((x) => [x.nadpis, temaUrl(x), podnikyTematu(x).length, x.emoji, `${x.kratce} ${x.hodnota}`]);
  return new Response(JSON.stringify({ m, s, t }), { headers: { 'Content-Type': 'application/json' } });
};
