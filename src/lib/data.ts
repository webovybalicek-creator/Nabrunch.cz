import podnikyRaw from '../data/podniky.json';
import lokalitySlugy from '../data/lokality.json';
import { slugify } from './slug';
import { parseCesky, type Tyden } from './oteviraci-doba';

export interface Podnik {
  id: number;
  slug: string;
  nazev: string;
  /** Datum přidání (WordPress post_date) */
  datum: string;
  /** „Naše srdcovka“ – kategorie Doporučené podniky */
  doporuceny: boolean;
  /** Lokality z WP, např. ["Praha 7", "Praha"] */
  lokality: string[];
  adresa: string;
  lat: number | null;
  lon: number | null;
  oteviraciDoba: string[];
  web: string | null;
  instagram: string | null;
  instagramUrl: string | null;
  telefon: string | null;
  telefonText: string | null;
  googleMaps: string | null;
  rezervace: string | null;
  platba: string[];
  wifi: boolean | null;
  wc: boolean | null;
  kuchyne: string[];
  atmosfera: string[];
  sezeni: string[];
  tipNadpis: string | null;
  tip: string | null;
  popis: string;
  seoTitulek: string | null;
  seoPopis: string | null;
  foto: string;
  galerie: string[];
}

// Názvy jako na WordPressu (wptexturize: rovný apostrof → ’)
export const PODNIKY = (podnikyRaw as Podnik[]).map((p) => ({ ...p, nazev: p.nazev.replace(/'/g, '’') }));
export const url = (p: Podnik) => `/podniky/${p.slug}/`;

/** Otevírací doba jako týden (null, když ji z textu nejde spolehlivě převést) */
const tydenCache = new Map<number, Tyden | null>();
export const tydenPodniku = (p: Podnik) => {
  if (!tydenCache.has(p.id)) tydenCache.set(p.id, parseCesky(p.oteviraciDoba));
  return tydenCache.get(p.id)!;
};

// ---------------------------------------------------------------- obrázky
// Obrázky z WordPressu jsou v datech pod původní cestou /wp-content/uploads/…;
// workflow „Stažení obrázků“ z nich dělá WebP v /obrazky/ (1400 px) a /nahledy/ (640 px).
const PRIPONA = /\.(jpe?g|png|webp|gif|avif)$/i;
/** Náhled obrázku pro karty (640 px, WebP) */
export const nahled = (cesta: string) => cesta.replace(/^\/wp-content\/uploads\//, '/nahledy/').replace(PRIPONA, '.webp');
/** Velký obrázek (1400 px, WebP) – detail, galerie, články */
export const velky = (cesta: string) => cesta.replace(/^\/wp-content\/uploads\//, '/obrazky/').replace(PRIPONA, '.webp');
/** HTML z WordPressu: odkazy na obrázky v /wp-content/uploads/ → WebP v /obrazky/ */
export const obrazkyVHtml = (html: string) =>
  html.replace(/\/wp-content\/uploads\/([^"'\s)]+?)\.(jpe?g|png|webp|gif)(?=["'\s)])/gi, '/obrazky/$1.webp')
    // <img> s WebP → <picture> s AVIF (menší) a WebP jako zálohou
    .replace(/<img\b([^>]*?)\ssrc="(\/obrazky\/[^"]+)\.webp"([^>]*)>/gi, '<picture><source type="image/avif" srcset="$2.avif"><img$1 src="$2.webp"$3></picture>');

// ---------------------------------------------------------------- lokality
export interface Lokalita {
  nazev: string;
  slug: string;
  podniky: Podnik[];
  /** U Prahy: obvody Praha 1–10 */
  obvody?: Lokalita[];
  /** U obvodu: nadřazená Praha */
  nadrazena?: string;
}

const SLUGY = lokalitySlugy as Record<string, string>;
const OBVOD = /^Praha \d+$/;
export const lokalitaSlug = (nazev: string) => SLUGY[nazev] ?? slugify(nazev);
/** Stejné adresy jako taxonomie „lokalita“ na WordPressu */
export const lokalitaUrl = (l: Pick<Lokalita, 'slug'>) => `/lokalita/${l.slug}/`;

/** Nejkonkrétnější lokalita podniku (obvod má přednost před „Praha“) */
export const hlavniLokalita = (p: Podnik) => p.lokality.find((l) => OBVOD.test(l)) ?? p.lokality[0] ?? '';
/** Město pro zobrazení: „Praha 7“ / „Brno“ */
export const mesto = hlavniLokalita;

let _lok: Lokalita[] | undefined;
export function lokality(): Lokalita[] {
  if (_lok) return _lok;
  const map = new Map<string, Lokalita>();
  for (const p of PODNIKY) {
    const nazvy = new Set(p.lokality);
    // Pražský obvod patří vždy i pod Prahu
    if ([...nazvy].some((l) => OBVOD.test(l))) nazvy.add('Praha');
    for (const n of nazvy) {
      if (!map.has(n)) map.set(n, { nazev: n, slug: lokalitaSlug(n), podniky: [] });
      map.get(n)!.podniky.push(p);
    }
  }
  const vse = [...map.values()];
  for (const l of vse) l.podniky.sort(poradi);
  const praha = map.get('Praha');
  if (praha) {
    praha.obvody = vse
      .filter((l) => OBVOD.test(l.nazev))
      .sort((a, b) => a.nazev.localeCompare(b.nazev, 'cs', { numeric: true }));
    for (const o of praha.obvody) o.nadrazena = 'praha';
  }
  _lok = vse.sort((a, b) => a.nazev.localeCompare(b.nazev, 'cs', { numeric: true }));
  return _lok;
}

/** Lokality pro přehledy: bez pražských obvodů (ty jsou pod Prahou) */
export const mesta = () => lokality().filter((l) => !OBVOD.test(l.nazev));
export const lokalitaPodle = (nazev: string) => lokality().find((l) => l.nazev === nazev);

// ---------------------------------------------------------------- řazení a výběry
/** Srdcovky první, pak od nejnovějších */
export const poradi = (a: Podnik, b: Podnik) =>
  Number(b.doporuceny) - Number(a.doporuceny) || b.datum.localeCompare(a.datum);

export const doporucene = () => PODNIKY.filter((p) => p.doporuceny).sort((a, b) => b.datum.localeCompare(a.datum));
export const nejnovejsi = (n: number) => [...PODNIKY].sort((a, b) => b.datum.localeCompare(a.datum)).slice(0, n);

function km(a: Podnik, b: Podnik) {
  const R = 6371;
  const dLat = (((b.lat ?? 0) - (a.lat ?? 0)) * Math.PI) / 180;
  const dLon = (((b.lon ?? 0) - (a.lon ?? 0)) * Math.PI) / 180;
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(((a.lat ?? 0) * Math.PI) / 180) * Math.cos(((b.lat ?? 0) * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

/** Nejbližší podniky (do 60 km) */
export function vOkoli(p: Podnik, n = 4): { p: Podnik; km: number }[] {
  if (p.lat == null) return [];
  return PODNIKY.filter((o) => o.id !== p.id && o.lat != null)
    .map((o) => ({ p: o, km: km(p, o) }))
    .filter((x) => x.km < 60)
    .sort((a, b) => a.km - b.km)
    .slice(0, n);
}

export const kmText = (k: number) =>
  k < 1 ? `${Math.max(50, Math.round((k * 1000) / 50) * 50)} m` : `${k.toLocaleString('cs-CZ', { maximumFractionDigits: 1 })} km`;

// ---------------------------------------------------------------- filtry a tematické stránky
export const KUCHYNE = ['Klasická', 'Veganská', 'Vegetariánská', 'Zdravá/fitness', 'Orientální'] as const;
export const ATMOSFERA = ['Sezení uvnitř', 'Zahrádka', 'Pet-friendly', 'Instagram-friendly místo'] as const;

export interface Tema {
  slug: string;
  /** Hodnota filtru v datech */
  hodnota: string;
  pole: 'kuchyne' | 'atmosfera';
  nadpis: string;
  kratce: string;
  popis: string;
  emoji: string;
}

export const TEMATA: Tema[] = [
  {
    slug: 'veganske-snidane-a-brunche',
    hodnota: 'Veganská',
    pole: 'kuchyne',
    nadpis: 'Veganské snídaně a brunche',
    kratce: 'Veganské',
    emoji: '🌱',
    popis: 'Podniky, kde si dáš snídani nebo brunch úplně bez živočišných produktů – od tofu scramble po rostlinné palačinky.',
  },
  {
    slug: 'vegetarianske-snidane-a-brunche',
    hodnota: 'Vegetariánská',
    pole: 'kuchyne',
    nadpis: 'Vegetariánské snídaně a brunche',
    kratce: 'Vegetariánské',
    emoji: '🥑',
    popis: 'Místa s vegetariánskou nabídkou na snídani a brunch – vejce, avokádo, sýry, pečivo i sladké talíře.',
  },
  {
    slug: 'zdrave-snidane-a-brunche',
    hodnota: 'Zdravá/fitness',
    pole: 'kuchyne',
    nadpis: 'Zdravé a fitness snídaně',
    kratce: 'Zdravé / fitness',
    emoji: '🥗',
    popis: 'Podniky se zdravější nabídkou – bowly, kaše, proteinové snídaně a lehčí brunchové talíře.',
  },
  {
    slug: 'orientalni-snidane-a-brunche',
    hodnota: 'Orientální',
    pole: 'kuchyne',
    nadpis: 'Orientální snídaně a brunche',
    kratce: 'Orientální',
    emoji: '🍜',
    popis: 'Snídaně a brunche s chutěmi Asie a Blízkého východu – od shakshuky po asijské pečivo.',
  },
  {
    slug: 'klasicke-snidane-a-brunche',
    hodnota: 'Klasická',
    pole: 'kuchyne',
    nadpis: 'Klasické snídaně a brunche',
    kratce: 'Klasické',
    emoji: '🍳',
    popis: 'Vejce Benedikt, míchaná vajíčka, croissanty a pancakes – podniky s klasickou snídaňovou nabídkou.',
  },
  {
    slug: 'brunch-se-zahradkou',
    hodnota: 'Zahrádka',
    pole: 'atmosfera',
    nadpis: 'Brunch se zahrádkou',
    kratce: 'Se zahrádkou',
    emoji: '🌿',
    popis: 'Kavárny a bistra, kde si snídani nebo brunch vychutnáš venku na zahrádce nebo terase.',
  },
  {
    slug: 'pet-friendly-brunch',
    hodnota: 'Pet-friendly',
    pole: 'atmosfera',
    nadpis: 'Pet-friendly podniky na brunch',
    kratce: 'Pet-friendly',
    emoji: '🐶',
    popis: 'Podniky, kam můžeš na snídani nebo brunch vzít i svého psího parťáka.',
  },
  {
    slug: 'instagramove-podniky-na-brunch',
    hodnota: 'Instagram-friendly místo',
    pole: 'atmosfera',
    nadpis: 'Instagramové podniky na brunch',
    kratce: 'Instagram-friendly',
    emoji: '📸',
    popis: 'Místa s krásným interiérem a talíři, které si zaslouží fotku – brunch i pro oči.',
  },
];

export const podnikyTematu = (t: Tema) => PODNIKY.filter((p) => p[t.pole].includes(t.hodnota)).sort(poradi);
export const temaUrl = (t: Tema) => `/${t.slug}/`;

export const pocetText = (n: number, jedna: string, dve: string, pet: string) =>
  `${n.toLocaleString('cs-CZ')} ${n === 1 ? jedna : n >= 2 && n <= 4 ? dve : pet}`;
export const podnikuText = (n: number) => pocetText(n, 'podnik', 'podniky', 'podniků');

/** Lokativ pro nadpisy „v Brně“, „na Praze 2“ – jen známé tvary, jinak „v lokalitě X“ */
const LOKATIV: Record<string, string> = {
  Praha: 'v Praze',
  Brno: 'v Brně',
  Olomouc: 'v Olomouci',
  Plzeň: 'v Plzni',
  Ostrava: 'v Ostravě',
  Liberec: 'v Liberci',
  Pardubice: 'v Pardubicích',
  'Hradec Králové': 'v Hradci Králové',
  'České Budějovice': 'v Českých Budějovicích',
  'Český Krumlov': 'v Českém Krumlově',
  'Ústí nad Labem': 'v Ústí nad Labem',
  'Karlovy Vary': 'v Karlových Varech',
  'Mladá Boleslav': 'v Mladé Boleslavi',
  'Nový Bor': 'v Novém Boru',
  'Velké Karlovice': 've Velkých Karlovicích',
  Beroun: 'v Berouně',
  Bořetice: 'v Bořeticích',
  Čelákovice: 'v Čelákovicích',
  Chomutov: 'v Chomutově',
  Chrudim: 'v Chrudimi',
  Jihlava: 'v Jihlavě',
  Kladno: 'v Kladně',
  Klatovy: 'v Klatovech',
  Klecany: 'v Klecanech',
  Líbeznice: 'v Líbeznicích',
  Litoměřice: 'v Litoměřicích',
  Litomyšl: 'v Litomyšli',
  Mikulov: 'v Mikulově',
  Opava: 'v Opavě',
  Písek: 'v Písku',
  Poděbrady: 'v Poděbradech',
  Průhonice: 'v Průhonicích',
  Příbram: 'v Příbrami',
  Říčany: 'v Říčanech',
  Šumperk: 'v Šumperku',
  Tábor: 'v Táboře',
  Zlín: 've Zlíně',
  Znojmo: 've Znojmě',
};
export const vLokalite = (nazev: string) =>
  LOKATIV[nazev] ?? (/^Praha \d+$/.test(nazev) ? `na ${nazev.replace('Praha', 'Praze')}` : `v lokalitě ${nazev}`);

// ---------------------------------------------------------------- lokalita × téma
// Stránky typu „Veganské snídaně a brunche v Praze“ (/lokalita/praha/veganske-snidane-a-brunche/).
// Jen ověřené podniky a jen tam, kde má výběr smysl: aspoň 3 podniky a zároveň ne skoro všechny podniky lokality
// (jinak by stránka jen kopírovala výpis lokality – tenký / duplicitní obsah).
export interface Kombinace {
  l: Lokalita;
  t: Tema;
  podniky: Podnik[];
}
const KOMBINACE_MIN = 3;
const KOMBINACE_MAX_PODIL = 0.8;
/** Hodnoty, které mají skoro všechny podniky – samostatná stránka by nic nepřinesla */
const KOMBINACE_BEZ = ['Klasická', 'Vegetariánská'];

let _komb: Kombinace[] | undefined;
export function kombinace(): Kombinace[] {
  if (_komb) return _komb;
  _komb = [];
  for (const l of lokality()) {
    for (const t of TEMATA) {
      if (KOMBINACE_BEZ.includes(t.hodnota)) continue;
      const podniky = l.podniky.filter((p) => p[t.pole].includes(t.hodnota));
      if (podniky.length >= KOMBINACE_MIN && podniky.length <= l.podniky.length * KOMBINACE_MAX_PODIL) _komb.push({ l, t, podniky });
    }
  }
  return _komb;
}
export const kombinaceUrl = (k: Kombinace) => `${lokalitaUrl(k.l)}${k.t.slug}/`;
export const kombinacePro = (l: Lokalita, t: Tema) => kombinace().find((k) => k.l.slug === l.slug && k.t.slug === t.slug);
/** „Veganské snídaně a brunche v Praze“ */
export const kombinaceNadpis = (k: Kombinace) => `${k.t.nadpis} ${vLokalite(k.l.nazev)}`;

// ---------------------------------------------------------------- alt texty fotek
/** „Spojka Karlín, Praha 8 – snídaně a brunch“ (+ pořadí fotky v galerii) */
export const altFoto = (p: Podnik, i?: number) => {
  const lok = hlavniLokalita(p);
  const mestoVNazvu = p.nazev.toLowerCase().includes(lok.replace(/ \d+$/, '').toLowerCase());
  return `${p.nazev}${mestoVNazvu ? '' : `, ${lok}`} – snídaně a brunch${i ? `, fotka ${i + 1}` : ''}`;
};
