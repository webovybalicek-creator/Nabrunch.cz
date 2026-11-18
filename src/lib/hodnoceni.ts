// Souhrn hodnocení (průměr, počet) z ostrého webu – stahuje se při buildu, aby strukturovaná data
// (AggregateRating) byla přímo v HTML a vyhledávače nemusely spouštět JavaScript.
// Když API není dostupné (lokální build bez sítě), vrátí prázdný objekt a stránky se sestaví bez hodnocení.
import { SITE } from './config';

let cache: Promise<Record<string, [number, number]>> | undefined;

export function souhrnHodnoceni() {
  cache ??= fetch(new URL('/api/hodnoceni.php?souhrn=1', SITE.url), { signal: AbortSignal.timeout(8000) })
    .then((r) => (r.ok ? r.json() : {}))
    .then((d) => (d && typeof d === 'object' ? (d as Record<string, [number, number]>) : {}))
    .catch(() => ({}));
  return cache;
}
