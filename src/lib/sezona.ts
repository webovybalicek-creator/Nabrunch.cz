// Sezónní výběr článků („Brunch tipy“ na úvodní stránce).
//
// Článek může mít ve frontmatteru:
//   sezona:            # okna, kdy je článek aktuální (mimo ně se na HP nezobrazí)
//     - od: "11-15"    # MM-DD, nebo pohyblivý svátek: „velikonoce-21“, „denmatek-14“, „velikonoce+1“
//       do: "01-02"    # když je „do“ před „od“, okno přechází přes Nový rok
//   obdobi: [zima, podzim]   # roční období, kdy se článek hodí víc (jen bonus, celoročně se zobrazuje dál)
//
// Pořadí se počítá při buildu (pro vyhledávače a bez JavaScriptu) a znovu v prohlížeči podle dnešního data,
// takže výběr sedí, i když web dlouho nikdo nenasadí. Logika skóre je v `skore()` a používá se na obou místech.

export type Obdobi = 'jaro' | 'leto' | 'podzim' | 'zima';
export interface Okno {
  od: string;
  do: string;
}
/** Okna převedená na konkrétní data [od, do] ve formátu RRRR-MM-DD */
export type OknaData = [string, string][];

export interface SezonniClanek {
  okna: OknaData;
  /** Článek má sezónní okna (mimo ně se nezobrazuje) */
  sezonni: boolean;
  obdobi: Obdobi[];
  /** Rubrika článku – „Kam na brunch“ dostane o víkendu bonus; z jedné rubriky max. polovina výběru */
  kat: string;
  /** Datum vydání RRRR-MM-DD */
  datum: string;
}

const iso = (d: Date) => d.toISOString().slice(0, 10);
const den = (r: number, m: number, d: number) => new Date(Date.UTC(r, m - 1, d));
const posun = (d: Date, dny: number) => new Date(d.getTime() + dny * 864e5);

/** Velikonoční neděle (gregoriánský kalendář) */
export function velikonoce(r: number): Date {
  const a = r % 19, b = Math.floor(r / 100), c = r % 100, d = Math.floor(b / 4), e = b % 4;
  const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  const mesic = Math.floor((h + l - 7 * m + 114) / 31), dd = ((h + l - 7 * m + 114) % 31) + 1;
  return den(r, mesic, dd);
}
/** Den matek: druhá květnová neděle */
export function denMatek(r: number): Date {
  const prvni = den(r, 5, 1);
  return posun(prvni, ((7 - prvni.getUTCDay()) % 7) + 7);
}

function datum(s: string, r: number): Date {
  const m = s.trim().match(/^(velikonoce|denmatek)\s*([+-]\s*\d+)?$/i);
  if (m) {
    const zaklad = m[1].toLowerCase() === 'velikonoce' ? velikonoce(r) : denMatek(r);
    return posun(zaklad, m[2] ? parseInt(m[2].replace(/\s/g, ''), 10) : 0);
  }
  const [mm, dd] = s.split('-').map(Number);
  return den(r, mm, dd);
}

/** Okna pro roky od–do (včetně); okno přes Nový rok končí v následujícím roce */
export function oknaData(okna: Okno[] | undefined, odRoku: number, doRoku: number): OknaData {
  const out: OknaData = [];
  for (let r = odRoku; r <= doRoku; r++) {
    for (const o of okna ?? []) {
      const a = datum(o.od, r);
      let b = datum(o.do, r);
      if (b < a) b = datum(o.do, r + 1);
      out.push([iso(a), iso(b)]);
    }
  }
  return out;
}

export function obdobiDne(d: Date): Obdobi {
  const m = d.getMonth() + 1;
  return m >= 3 && m <= 5 ? 'jaro' : m >= 6 && m <= 8 ? 'leto' : m >= 9 && m <= 11 ? 'podzim' : 'zima';
}

export const OBDOBI_NADPIS: Record<Obdobi, string> = {
  jaro: 'Tipy na jaro',
  leto: 'Tipy na léto',
  podzim: 'Tipy na podzim',
  zima: 'Tipy na zimu',
};

/**
 * Skóre článku pro daný den; `null` = nezobrazovat (sezónní článek mimo svá okna).
 * - aktivní sezónní článek: 1000 + čím blíž konec okna (svátek), tím výš
 * - hodí se do ročního období: +40
 * - víkend (pá–ne) a rubrika „Kam na brunch“: +15
 * Ve výběru je z jedné rubriky nejvýš polovina karet, aby se střídaly tipy na místa a vysvětlující články.
 * - novější články mírně výš (do +30, klesá s věkem)
 */
export function skore(c: SezonniClanek, dnes: Date): number | null {
  const t = iso(new Date(Date.UTC(dnes.getFullYear(), dnes.getMonth(), dnes.getDate())));
  let s = 0;
  if (c.sezonni) {
    const okno = c.okna.find(([a, b]) => a <= t && t <= b);
    if (!okno) return null;
    const zbyva = (Date.parse(okno[1]) - Date.parse(t)) / 864e5;
    s += 1000 + Math.max(0, 60 - zbyva);
  }
  if (c.obdobi.includes(obdobiDne(dnes))) s += 40;
  const dvt = dnes.getDay();
  if (c.kat === 'Kam na brunch' && (dvt === 5 || dvt === 6 || dvt === 0)) s += 15;
  const stari = (Date.parse(t) - Date.parse(c.datum)) / 864e5;
  s += Math.max(0, 30 - Math.max(0, stari) / 12);
  return s;
}

/** Indexy vybraných článků seřazené podle skóre (z jedné rubriky max. polovina, zaokrouhleno nahoru) */
export function vyber(clanky: SezonniClanek[], dnes: Date, n: number): number[] {
  const serazene = clanky
    .map((c, i) => ({ i, s: skore(c, dnes) }))
    .filter((x): x is { i: number; s: number } => x.s !== null)
    .sort((a, b) => b.s - a.s || a.i - b.i);
  const limit = Math.ceil(n / 2);
  const pocty = new Map<string, number>();
  const out: number[] = [];
  for (const x of serazene) {
    const k = clanky[x.i].kat;
    // sezónní články v okně se berou vždy
    if ((pocty.get(k) ?? 0) >= limit && !clanky[x.i].sezonni) continue;
    pocty.set(k, (pocty.get(k) ?? 0) + 1);
    out.push(x.i);
    if (out.length === n) break;
  }
  // kdyby kvůli limitu chyběly karty, doplnit zbylými podle skóre
  for (const x of serazene) {
    if (out.length === n) break;
    if (!out.includes(x.i)) out.push(x.i);
  }
  return out;
}
