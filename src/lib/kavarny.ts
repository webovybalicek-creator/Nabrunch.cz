// Neověřené kavárny z OpenStreetMap (src/data/kavarny.json, generuje workflow „Import kaváren“).
// Jsou oddělené od ověřených podniků: nemění homepage, srdcovky, nově přidané ani témata.
// Zveřejňují se postupně podle pole `zverejnit` – při každém buildu jen ty, jejichž čas už nastal.
import data from '../data/kavarny.json';
import majiteleRaw from '../data/kavarny-majitele.json';
import { PODNIKY, url as podnikUrl, kmText, type Podnik } from './data';
import { slugify } from './slug';
import { parseTyden, souhrn, hhmm, type Tyden } from './oteviraci-doba';

export interface Kavarna {
  id: string;
  slug: string;
  nazev: string;
  mesto: string;
  mestoSlug: string;
  obvod: string | null;
  ctvrt: string | null;
  ulice: string | null;
  psc: string | null;
  lat: number;
  lon: number;
  oteviraciDoba: string | null;
  web: string | null;
  telefon: string | null;
  email: string | null;
  instagram: string | null;
  facebook: string | null;
  retezec: boolean;
  znacka: string | null;
  vlastnosti: Record<'wifi' | 'zahradka' | 'psi' | 'bezbarierove' | 'karta' | 'sSebou' | 'vegan' | 'vegetarian' | 'bezlepek' | 'snidane', boolean | null>;
  kuchyne: string[];
  okoli: { mhd: { nazev: string; typ: string; m: number } | null; park: { nazev: string; m: number } | null };
  zWebu: Partial<Record<'snidane' | 'brunch' | 'vyberovaKava' | 'prazirna' | 'pekarna' | 'dorty' | 'vegan' | 'bezlepek' | 'rezervace' | 'hryProDeti', true>> | null;
  pridano: string;
  zverejnit: string | null;
  /** Údaje od majitele (po ověření) */
  overeno?: string;
  popisMajitele?: string;
  fotky?: string[];
  rezervace?: string;
}

interface UpravaMajitele {
  overeno: string;
  telefon?: string;
  email?: string;
  web?: string;
  rezervace?: string;
  instagram?: string;
  facebook?: string;
  popis?: string;
  oteviraciDoba?: string;
  vlastnosti?: string[];
  fotky?: string[];
  /** Kavárna, kterou majitel založil sám (není v OSM) */
  novy?: { nazev: string; ulice: string; mesto: string; obvod: string | null; psc: string | null; lat: number; lon: number; url: string };
}
const MAJITELE = ((majiteleRaw as { kavarny?: Record<string, UpravaMajitele> }).kavarny ?? {}) as Record<string, UpravaMajitele>;

/** Přepíše údaje z OSM tím, co zadal ověřený majitel */
function sUpravou(k: Kavarna): Kavarna {
  const u = MAJITELE[k.id];
  if (!u?.overeno) return k;
  const v = new Set(u.vlastnosti ?? []);
  const maVlastnosti = Array.isArray(u.vlastnosti);
  const flag = (klic: string, puvodni: boolean | null) => (maVlastnosti ? v.has(klic) || null : puvodni);
  return {
    ...k,
    telefon: u.telefon ?? k.telefon,
    email: u.email ?? k.email,
    web: u.web ?? k.web,
    instagram: u.instagram ?? k.instagram,
    facebook: u.facebook ?? k.facebook,
    oteviraciDoba: u.oteviraciDoba ?? k.oteviraciDoba,
    rezervace: u.rezervace,
    popisMajitele: u.popis,
    fotky: u.fotky,
    overeno: u.overeno,
    vlastnosti: maVlastnosti
      ? {
          ...k.vlastnosti,
          wifi: flag('wifi', k.vlastnosti.wifi),
          zahradka: flag('zahradka', k.vlastnosti.zahradka),
          psi: flag('psi', k.vlastnosti.psi),
          bezbarierove: flag('bezbarierove', k.vlastnosti.bezbarierove),
          karta: flag('karta', k.vlastnosti.karta),
          vegan: flag('vegan', k.vlastnosti.vegan),
          bezlepek: flag('bezlepek', k.vlastnosti.bezlepek),
          snidane: flag('snidane', k.vlastnosti.snidane),
        }
      : k.vlastnosti,
    // Příznaky od majitele nahrazují odhad z webu
    zWebu: maVlastnosti
      ? Object.fromEntries(['brunch', 'vyberovaKava', 'pekarna', 'dorty', 'vegan', 'bezlepek'].filter((x) => v.has(x)).map((x) => [x, true]).concat(v.has('deti') ? [['hryProDeti', true]] : []))
      : k.zWebu,
  };
}

const TED = Date.now();
/** Kavárny založené majiteli (nejsou v OSM) – základ záznamu z jejich údajů */
const _cesty = new Set(((data as { kavarny?: Kavarna[] }).kavarny ?? []).map((k) => `${k.mestoSlug}/${k.slug}`));
const ZALOZENE: Kavarna[] = Object.entries(MAJITELE)
  .filter(([, u]) => u.novy)
  .map(([id, u]) => {
    const n = u.novy!;
    const [, , mestoSlug, zaklad] = n.url.split('/');
    const slug = _cesty.has(`${mestoSlug}/${zaklad}`) ? `${zaklad}-${id}` : zaklad;
    return {
      id,
      slug,
      nazev: n.nazev,
      mesto: n.mesto,
      mestoSlug,
      obvod: n.obvod,
      ctvrt: null,
      ulice: n.ulice,
      psc: n.psc,
      lat: n.lat,
      lon: n.lon,
      oteviraciDoba: null,
      web: null,
      telefon: null,
      email: null,
      instagram: null,
      facebook: null,
      retezec: false,
      znacka: null,
      vlastnosti: { wifi: null, zahradka: null, psi: null, bezbarierove: null, karta: null, sSebou: null, vegan: null, vegetarian: null, bezlepek: null, snidane: null },
      kuchyne: [],
      okoli: { mhd: null, park: null },
      zWebu: null,
      pridano: u.overeno,
      zverejnit: null,
    } satisfies Kavarna;
  });

/** Všechny kavárny z importu (i ty, které ještě nejsou zveřejněné) a kavárny založené majiteli */
export const VSECHNY_KAVARNY: Kavarna[] = [...((data as { kavarny?: Kavarna[] }).kavarny ?? []), ...ZALOZENE].map(sUpravou);
/** Stejné pravidlo jako při importu (scripts/import-kavarny.mjs): do 60 m od ověřeného podniku s podobným názvem,
 *  nebo do 15 m → je to duplicita. Díky tomu kavárna zmizí hned po přidání podniku, ne až při dalším importu. */
const normNazev = (s: string) => slugify(s).replace(/-(cafe|kavarna|coffee|bistro|praha|brno)$/g, '');
const metry = (aLat: number, aLon: number, bLat: number, bLon: number) => {
  const r = Math.PI / 180;
  const h = Math.sin(((bLat - aLat) * r) / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(((bLon - aLon) * r) / 2) ** 2;
  return 12742000 * Math.asin(Math.sqrt(h));
};
const jeDuplicitaPodniku = (k: Kavarna) =>
  PODNIKY.some((p) => {
    if (p.lat == null || p.lon == null) return false;
    const m = metry(k.lat, k.lon, p.lat, p.lon);
    if (m > 60) return false;
    const a = normNazev(k.nazev), b = normNazev(p.nazev);
    return m <= 15 || b.includes(a.slice(0, 6)) || a.includes(b.slice(0, 6));
  });
/** Zveřejněné kavárny: čas zveřejnění už nastal, nebo je ověřil majitel */
export const KAVARNY: Kavarna[] = VSECHNY_KAVARNY.filter(
  (k) => (k.overeno || (k.zverejnit && Date.parse(k.zverejnit) <= TED)) && (k.overeno || !jeDuplicitaPodniku(k)),
);
export const ZDROJ = (data as { zdroj?: string }).zdroj ?? '© přispěvatelé OpenStreetMap';

export const kavarnaUrl = (k: Kavarna) => `/kavarny/${k.mestoSlug}/${k.slug}/`;
export const mestoKavarenUrl = (slug: string) => `/kavarny/${slug}/`;
export const adresaKavarny = (k: Kavarna) => [k.ulice, [k.psc?.replace(/^(\d{3})(\d{2})$/, '$1 $2'), k.obvod ?? k.mesto].filter(Boolean).join(' ')].filter(Boolean).join(', ');

const tydenCache = new Map<string, Tyden | null>();
export const tydenKavarny = (k: Kavarna) => {
  if (!tydenCache.has(k.id)) tydenCache.set(k.id, parseTyden(k.oteviraciDoba ?? undefined, k.lat, k.lon));
  return tydenCache.get(k.id)!;
};

/** Co o kavárně víme (OSM + její web) – sjednocené příznaky */
export function nabizi(k: Kavarna) {
  const v = k.vlastnosti;
  const w = k.zWebu ?? {};
  return {
    snidane: !!(v.snidane || w.snidane),
    brunch: !!w.brunch,
    vyberovaKava: !!w.vyberovaKava,
    prazirna: !!w.prazirna,
    pekarna: !!w.pekarna,
    dorty: !!w.dorty,
    vegan: !!(v.vegan || w.vegan),
    bezlepek: !!(v.bezlepek || w.bezlepek),
    rezervace: !!w.rezervace,
    deti: !!w.hryProDeti,
  };
}

/** Míra údajů – rozhoduje o indexaci detailu */
export function skore(k: Kavarna) {
  const n = nabizi(k);
  return (
    (tydenKavarny(k) ? 4 : 0) +
    (k.ulice ? 1 : 0) +
    (k.web || k.instagram ? 1 : 0) +
    (k.telefon ? 1 : 0) +
    Object.values(k.vlastnosti).filter((x) => x != null).length +
    Object.values(n).filter(Boolean).length +
    (k.popisMajitele ? 3 : 0) +
    (k.fotky?.length ? 3 : 0) +
    (k.okoli.mhd || k.okoli.park ? 1 : 0)
  );
}
/** Do vyhledávačů jen detaily s dost vlastními údaji (jinak noindex, dokud je nedoplníme) */
export const indexovatKavarnu = (k: Kavarna) =>
  !!k.overeno ? !!(k.popisMajitele || k.fotky?.length || tydenKavarny(k)) : !k.retezec && !!tydenKavarny(k) && !!k.ulice && skore(k) >= 9;

// ---------------------------------------------------------------- města
export interface MestoKavaren {
  nazev: string;
  slug: string;
  kavarny: Kavarna[];
}
let _mesta: MestoKavaren[] | undefined;
export function mestaKavaren(): MestoKavaren[] {
  if (_mesta) return _mesta;
  const map = new Map<string, MestoKavaren>();
  for (const k of KAVARNY) {
    if (!map.has(k.mestoSlug)) map.set(k.mestoSlug, { nazev: k.mesto, slug: k.mestoSlug, kavarny: [] });
    map.get(k.mestoSlug)!.kavarny.push(k);
  }
  for (const m of map.values()) m.kavarny.sort((a, b) => skore(b) - skore(a) || a.nazev.localeCompare(b.nazev, 'cs'));
  _mesta = [...map.values()].sort((a, b) => b.kavarny.length - a.kavarny.length || a.nazev.localeCompare(b.nazev, 'cs'));
  return _mesta;
}
/** Stránka města do vyhledávačů jen s aspoň 3 kavárnami */
export const indexovatMesto = (m: MestoKavaren) => m.kavarny.length >= 3;
/** Kavárny pro lokalitu naBrunche („Brno“, „Praha 7“) */
export const kavarnyLokality = (nazev: string) =>
  /^Praha \d+$/.test(nazev) ? KAVARNY.filter((k) => k.obvod === nazev) : mestaKavaren().find((m) => m.nazev === nazev)?.kavarny ?? [];

// ---------------------------------------------------------------- okolí
function km(a: { lat: number; lon: number }, b: { lat: number | null; lon: number | null }) {
  const R = 6371;
  const dLat = (((b.lat ?? 0) - a.lat) * Math.PI) / 180;
  const dLon = (((b.lon ?? 0) - a.lon) * Math.PI) / 180;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos((a.lat * Math.PI) / 180) * Math.cos(((b.lat ?? 0) * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}
/** Nejbližší ověřené podniky naBrunche (do 25 km) */
export const overeneVOkoli = (k: Kavarna, n = 3): { p: Podnik; km: number }[] =>
  PODNIKY.filter((p) => p.lat != null)
    .map((p) => ({ p, km: km(k, p) }))
    .filter((x) => x.km < 25)
    .sort((a, b) => a.km - b.km)
    .slice(0, n);
/** Další kavárny poblíž (do 3 km) */
export const kavarnyVOkoli = (k: Kavarna, n = 6): { k: Kavarna; km: number }[] =>
  KAVARNY.filter((o) => o.id !== k.id && Math.abs(o.lat - k.lat) < 0.05 && Math.abs(o.lon - k.lon) < 0.08)
    .map((o) => ({ k: o, km: km(k, o) }))
    .filter((x) => x.km < 3)
    .sort((a, b) => a.km - b.km)
    .slice(0, n);

// ---------------------------------------------------------------- text z faktů
const vyber = <T,>(k: Kavarna, moznosti: T[], sul = 0): T => {
  let h = sul;
  for (const ch of k.id) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return moznosti[h % moznosti.length];
};
const seznam = (v: string[]) => (v.length > 1 ? `${v.slice(0, -1).join(', ')} a ${v[v.length - 1]}` : (v[0] ?? ''));
const mText = (m: number) => (m < 100 ? 'pár kroků' : `asi ${Math.round(m / 50) * 50} m`);
const TYP_MHD: Record<string, string> = { metro: 'stanice metra', tramvaj: 'tramvajová zastávka', vlak: 'železniční zastávka' };

/** Odstavce popisu kavárny – poskládané jen z ověřitelných údajů, jiné u každé kavárny */
export function popisKavarny(k: Kavarna): string[] {
  const out: string[] = [];
  const t = tydenKavarny(k);
  const n = nabizi(k);
  const kde = [k.ulice, k.obvod ?? k.mesto].filter(Boolean).join(', ');

  // 1. kdo a kde
  out.push(
    vyber(k, [
      `${k.nazev} je kavárna${k.retezec && k.znacka ? ` sítě ${k.znacka}` : ''} na adrese ${kde || k.mesto}.`,
      `Kavárnu ${k.nazev}${k.retezec && k.znacka ? ` (síť ${k.znacka})` : ''} najdete na adrese ${kde || k.mesto}.`,
      `${k.nazev} – kavárna${k.retezec && k.znacka ? ` sítě ${k.znacka}` : ''}, ${kde || k.mesto}.`,
    ]) +
      (k.okoli.mhd
        ? ` ${vyber(k, [`Nejbližší ${TYP_MHD[k.okoli.mhd.typ] ?? 'zastávka'} je ${k.okoli.mhd.nazev}`, `Nejblíž je ${TYP_MHD[k.okoli.mhd.typ] ?? 'zastávka'} ${k.okoli.mhd.nazev}`, `Dojdete sem i od zastávky ${k.okoli.mhd.nazev}`], 1)} (${mText(k.okoli.mhd.m)})${k.okoli.park ? `, ${k.okoli.park.m < 200 ? 'hned vedle' : 'kousek odsud'} je ${/^park\b/i.test(k.okoli.park.nazev) ? k.okoli.park.nazev : `park ${k.okoli.park.nazev}`}` : ''}.`
        : k.okoli.park
          ? ` ${k.okoli.park.m < 200 ? 'Hned vedle' : 'Kousek odsud'} je ${/^park\b/i.test(k.okoli.park.nazev) ? k.okoli.park.nazev : `park ${k.okoli.park.nazev}`} (${mText(k.okoli.park.m)}), takže se dá kafe vzít i s sebou na procházku.`
          : ''),
  );

  // 2. otevírací doba – co z ní plyne
  if (t) {
    const vsedni = t.slice(0, 5).filter((d) => d.length);
    const prvni = vsedni.length ? Math.min(...vsedni.map((d) => d[0][0])) : null;
    const posledni = vsedni.length ? Math.max(...vsedni.map((d) => d[d.length - 1][1])) : null;
    const v: string[] = [];
    if (prvni != null && prvni <= 7 * 60 + 30) v.push(`ve všední dny otevírá už v ${hhmm(prvni)}, takže se hodí na kávu cestou do práce`);
    else if (prvni != null && prvni >= 10 * 60) v.push(`otevírá až v ${hhmm(prvni)}, na ranní kávu je potřeba jinam`);
    else if (prvni != null) v.push(`ve všední dny otevírá v ${hhmm(prvni)}`);
    if (posledni != null && posledni >= 20 * 60) v.push(`večer má otevřeno do ${hhmm(posledni)}`);
    else if (posledni != null && posledni <= 16 * 60) v.push(`zavírá už v ${hhmm(posledni)}`);
    const so = t[5].length > 0;
    const ne = t[6].length > 0;
    if (so && ne) v.push('o víkendu má otevřeno v sobotu i v neděli');
    else if (so) v.push('v sobotu má otevřeno, v neděli zavřeno');
    else if (ne) v.push('v neděli má otevřeno, v sobotu ne');
    else v.push('o víkendu má zavřeno');
    const zavreno = t.slice(0, 5).map((d, i) => (d.length ? null : ['pondělí', 'úterý', 'středu', 'čtvrtek', 'pátek'][i])).filter(Boolean) as string[];
    if (zavreno.length && zavreno.length < 5) v.push(`v ${seznam(zavreno)} je zavřeno`);
    out.push(`Podle otevírací doby ${seznam(v)}.`);
  }

  // 3. co nabízí
  const nab: string[] = [];
  if (n.brunch) nab.push('brunch');
  else if (n.snidane) nab.push('snídaně');
  if (n.vyberovaKava) nab.push('výběrovou kávu');
  if (n.prazirna) nab.push('vlastní pražírnu');
  if (n.pekarna) nab.push('pečivo');
  if (n.dorty) nab.push('dorty a zákusky');
  if (n.vegan) nab.push('veganské možnosti');
  if (n.bezlepek) nab.push('bezlepkové možnosti');
  if (nab.length)
    out.push(
      k.zWebu && Object.keys(k.zWebu).length
        ? k.overeno
          ? `Podle majitele nabízí ${seznam(nab)}.`
          : `${vyber(k, ['Na svém webu kavárna zmiňuje', 'Podle vlastního webu nabízí', 'Kavárna na webu uvádí'], 2)} ${seznam(nab)}.${n.brunch || n.snidane ? ' Jestli snídaně podávají i o víkendu, doporučujeme ověřit předem.' : ''}`
        : `Podle údajů z mapy nabízí ${seznam(nab)}.`,
    );

  // 4. praktické
  const v = k.vlastnosti;
  const prak: string[] = [];
  if (v.wifi) prak.push('wifi');
  if (v.zahradka) prak.push('posezení venku');
  if (v.bezbarierove) prak.push('bezbariérový vstup');
  if (v.karta) prak.push('platbu kartou');
  if (v.sSebou) prak.push('kávu s sebou');
  const ne: string[] = [];
  if (v.wifi === false) ne.push('wifi tu není');
  if (v.zahradka === false) ne.push('venkovní posezení chybí');
  if (prak.length || v.psi != null || ne.length)
    out.push(
      [
        prak.length ? `K dispozici je ${seznam(prak)}` : '',
        v.psi === true ? 'pejsci jsou vítáni' : v.psi === false ? 'psi sem nesmí' : '',
        ...ne,
      ]
        .filter(Boolean)
        .join(', ')
        .replace(/^./, (c) => c.toUpperCase()) + '.',
    );
  return out;
}

/** Meta popisek detailu (do ~158 znaků) */
export function metaKavarny(k: Kavarna) {
  const t = tydenKavarny(k);
  const n = nabizi(k);
  const doba = t
    ? souhrn(t)
        .filter((x) => x.cas !== 'Zavřeno')
        .slice(0, 2)
        .map((x) => `${x.dny} ${x.cas}`)
        .join(', ')
    : '';
  const vlast = [n.brunch ? 'brunch' : n.snidane ? 'snídaně' : '', n.vyberovaKava ? 'výběrová káva' : '', k.vlastnosti.wifi ? 'wifi' : '', k.vlastnosti.zahradka ? 'zahrádka' : '', k.vlastnosti.psi ? 'se psem' : '']
    .filter(Boolean)
    .slice(0, 3);
  const vety = [
    `☕ ${k.nazev}, ${k.ulice ? `${k.ulice}, ` : ''}${k.obvod ?? k.mesto}.`,
    doba && `Otevřeno ${doba}.`,
    vlast.length ? `${vlast.join(', ').replace(/^./, (c) => c.toUpperCase())}.` : '',
    'Mapa, kontakt a ověřené brunche v okolí.',
  ].filter(Boolean) as string[];
  let d = '';
  for (const v of vety) if ((d ? `${d} ${v}` : v).length <= 158) d = d ? `${d} ${v}` : v;
  // Pobočky se stejným názvem ve stejném městě by měly stejný titulek → přidat ulici
  const pobocka = k.ulice && KAVARNY.some((x) => x !== k && x.nazev === k.nazev && x.mesto === k.mesto);
  const title = `${k.nazev}${pobocka ? `, ${k.ulice}` : ''} – kavárna ${k.obvod ?? k.mesto}${t ? ', otevírací doba' : ''}`;
  return { title, description: d };
}

export const vzdalenost = kmText;
export { podnikUrl };
