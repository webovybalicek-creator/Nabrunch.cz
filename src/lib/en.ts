// Anglická verze webu (/en/…). Překlady podniků jsou v src/data/en/podniky.json,
// články v src/content/blog-en/. Podnik bez překladu anglickou stránku nemá.
import podnikyEn from '../data/en/podniky.json';
import { PODNIKY, tydenPodniku, type Podnik } from './data';

export interface PodnikEnText {
  oblast: string;
  seoTitulek: string;
  seoPopis: string;
  popis: string;
  tip: string;
}

/** Dokud není fáze 1 hotová, anglické stránky nejsou ve vyhledávačích ani v sitemapě */
export const EN_INDEXOVAT = false;

const texty = podnikyEn as Record<string, PodnikEnText>;

export const PODNIKY_EN = PODNIKY.filter((p) => texty[p.slug]).map((p) => ({ p, en: texty[p.slug] }));

export const enText = (p: Podnik): PodnikEnText | undefined => texty[p.slug];
export const enUrl = (p: Podnik | string) => `/en/places/${typeof p === 'string' ? p : p.slug}/`;
export const maEn = (p: Podnik) => !!texty[p.slug];

const DNY = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const cas = (m: number) => `${Math.floor(m / 60) % 24}:${String(m % 60).padStart(2, '0')}`;

/** Otevírací doba po anglicku, stejné dny sloučené: „Mon–Fri 7:30–19:00“ */
export function hodinyEn(p: Podnik): string[] {
  const t = tydenPodniku(p);
  if (!t) return [];
  const radky = t.map((d) => (d.length ? d.map(([a, b]) => `${cas(a)}–${cas(b)}`).join(', ') : 'closed'));
  const out: string[] = [];
  for (let i = 0; i < 7; ) {
    let j = i;
    while (j + 1 < 7 && radky[j + 1] === radky[i]) j++;
    out.push(`${i === j ? DNY[i] : `${DNY[i]}–${DNY[j]}`}: ${radky[i]}`);
    i = j + 1;
  }
  return out;
}

export const UI = {
  podniky: 'Places',
  brunchPraha: 'Brunch in Prague',
  oNas: 'About',
  cesky: 'Česky',
};

/** Název pro anglickou verzi – bez přívěsku „Praha“ (Stranou Praha → Stranou) */
export const nazevEn = (p: Podnik) => p.nazev.replace(/\s+Praha$/, '');
