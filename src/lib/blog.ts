import { getCollection, type CollectionEntry } from 'astro:content';
import { readFileSync } from 'node:fs';
import { slugify } from './slug';
import { PODNIKY, type Podnik } from './data';

export type Clanek = CollectionEntry<'blog'>;

/** Rubriky blogu (WordPress měl všechno v „Nezařazené“, třídíme podle titulku) */
export const KATEGORIE = ['Kam na brunch', 'Co je co', 'Inspirace'] as const;
export type Kategorie = (typeof KATEGORIE)[number];

export function kategorie(c: Clanek): Kategorie {
  const t = c.data.title;
  if (/^co (je|jsou|to)\b|\bvs\.?\b|rozdíl/i.test(t)) return 'Co je co';
  if (/kam na|nejlepší|\btop\b|mimo město|s dětmi|café|cafe|bistro|shelter|spot|brunch praha|pro děti/i.test(t)) return 'Kam na brunch';
  return 'Inspirace';
}

export const KATEGORIE_POPIS: Record<Kategorie, string> = {
  'Kam na brunch': 'Tipy na nejlepší snídaně a brunche podle měst a příležitostí.',
  'Co je co': 'Matcha, kombucha, sourdough nebo vejce Benedikt – vysvětlíme, co se skrývá za brunchovými pojmy.',
  Inspirace: 'Trendy, jídla a nápady pro každý víkendový brunch.',
};

/** Titulek článku jako na WordPressu (wptexturize: „ - “ → „ – “) */
export const nazev = (c: Clanek) => c.data.title.replace(/ - /g, ' – ');

export const kategorieUrl = (k: string) => `/blog/${slugify(k)}/`;
/** Články zůstávají na stejných adresách jako na WordPressu: /<slug>/ */
export const clanekUrl = (c: Clanek | string) => `/${typeof c === 'string' ? c : c.id}/`;

export async function clanky(): Promise<Clanek[]> {
  return (await getCollection('blog')).sort((a, b) => b.data.date.getTime() - a.data.date.getTime());
}

const slovaCache = new Map<string, number>();
function slova(c: Clanek): number {
  if (!slovaCache.has(c.id)) {
    const text = (c.body || (c.filePath ? readFileSync(c.filePath, 'utf8') : '')).replace(/<[^>]+>/g, ' ');
    slovaCache.set(c.id, text.split(/\s+/).filter(Boolean).length);
  }
  return slovaCache.get(c.id)!;
}
export const casCteni = (c: Clanek) => Math.max(2, Math.round(slova(c) / 200));
export const datumText = (d: Date) => d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });

/** Perex: popis ze SEOPress bez přípony webu, jinak začátek textu */
export function perex(c: Clanek, max = 170): string {
  const d = c.data.description?.replace(/\s*[-–|]\s*naBrunch\.cz\s*$/i, '').trim();
  if (d) return d;
  const t = (c.body ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  return t.length > max ? `${t.slice(0, max).replace(/\s+\S*$/, '')}…` : t;
}

const STOP = new Set(['snida', 'brunc', 'praze', 'praha', 'kavar', 'podni', 'ktery', 'ktera', 'ktere', 'nejle', 'jaky', 'jake', 'proc', 'mezi']);
const koreny = (s: string) =>
  new Set(
    s
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .split(/[^a-z0-9]+/)
      .filter((w) => w.length > 3)
      .map((w) => w.slice(0, 5))
      .filter((w) => !STOP.has(w)),
  );

/** Související články podle společných slov v titulku a popisu */
export function souvisejici(vse: Clanek[], c: Clanek, n = 3): Clanek[] {
  const k = koreny(`${c.data.title} ${c.data.description ?? ''}`);
  return vse
    .filter((x) => x.id !== c.id)
    .map((x) => {
      let skore = kategorie(x) === kategorie(c) ? 1 : 0;
      for (const w of koreny(`${x.data.title} ${x.data.description ?? ''}`)) if (k.has(w)) skore += 1;
      return { x, skore };
    })
    .sort((a, b) => b.skore - a.skore || b.x.data.date.getTime() - a.x.data.date.getTime())
    .slice(0, n)
    .map((v) => v.x);
}

/** Podniky, na jejichž profil článek odkazuje */
export function zminenePodniky(c: Clanek): Podnik[] {
  const body = c.body ?? '';
  return PODNIKY.filter((p) => body.includes(`/podniky/${p.slug}/`) || body.includes(`/podniky/${p.slug}"`));
}

/** Články, které odkazují na profil podniku */
export const clankyOPodniku = (vse: Clanek[], p: Podnik) =>
  vse.filter((c) => (c.body ?? '').includes(`/podniky/${p.slug}`));
