import openingHours from 'opening_hours';

/** Intervaly v minutách od půlnoci, index 0 = pondělí … 6 = neděle */
export type Tyden = [number, number][][];

export const DNY = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
export const DNY_KRATCE = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];

// Referenční týden bez státních svátků (pondělí 2. 11. 2026)
const START = new Date(2026, 10, 2, 0, 0, 0);

const cache = new Map<string, Tyden | null>();

export function parseTyden(value?: string, lat = 50, lon = 15): Tyden | null {
  if (!value) return null;
  if (cache.has(value)) return cache.get(value)!;
  let result: Tyden | null = null;
  try {
    const oh = new openingHours(value, { lat, lon, address: { country_code: 'cz', state: '' } }, { mode: 0 });
    const end = new Date(START.getTime() + 7 * 864e5);
    const intervals = oh.getOpenIntervals(START, end) as [Date, Date, boolean, string?][];
    const tyden: Tyden = [[], [], [], [], [], [], []];
    for (const [from, to, unknown] of intervals) {
      if (unknown) continue;
      // interval může přesáhnout půlnoc → rozdělit po dnech
      let cur = from;
      while (cur < to) {
        const dayIdx = Math.floor((cur.getTime() - START.getTime()) / 864e5);
        const dayStart = new Date(START.getTime() + dayIdx * 864e5);
        const dayEnd = new Date(dayStart.getTime() + 864e5);
        const segEnd = to < dayEnd ? to : dayEnd;
        const a = Math.round((cur.getTime() - dayStart.getTime()) / 6e4);
        const b = Math.round((segEnd.getTime() - dayStart.getTime()) / 6e4);
        if (dayIdx >= 0 && dayIdx < 7 && b > a) tyden[dayIdx].push([a, b]);
        cur = segEnd;
      }
    }
    result = tyden.some((d) => d.length) ? tyden : null;
  } catch {
    result = null;
  }
  cache.set(value, result);
  return result;
}

export const hhmm = (m: number) => `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;

export const denText = (d: [number, number][]) =>
  d.length ? d.map(([a, b]) => `${hhmm(a)}–${hhmm(b === 1440 ? 1440 : b)}`).join(', ') : 'Zavřeno';

/** Seskupí dny se stejnou dobou: „Po–Pá 9:00–18:00“ */
export function souhrn(t: Tyden): { dny: string; cas: string }[] {
  const out: { dny: string; cas: string }[] = [];
  let i = 0;
  while (i < 7) {
    const cas = denText(t[i]);
    let j = i;
    while (j + 1 < 7 && denText(t[j + 1]) === cas) j++;
    out.push({ dny: i === j ? DNY_KRATCE[i] : `${DNY_KRATCE[i]}–${DNY_KRATCE[j]}`, cas });
    i = j + 1;
  }
  return out;
}

const SCHEMA_DNY = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const iso = (m: number) => (m >= 1440 ? '23:59' : `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`);

export function schemaSpec(t: Tyden) {
  return t.flatMap((d, i) =>
    d.map(([a, b]) => ({
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: SCHEMA_DNY[i],
      opens: iso(a),
      closes: iso(b),
    })),
  );
}

// ---------------------------------------------------------------------------
// Otevírací doba ověřených podniků je volný český text („Po - Pá: 8:00 - 18:00“).
// Převod do týdne; když si převod není jistý (sezónní doba, nesmyslný čas,
// neznámý zápis), vrátí null a web stav „otevřeno / zavřeno“ nezobrazí.

const DEN_KLIC: [RegExp, number][] = [
  [/^po(ndělí)?\.?$/, 0],
  [/^út(erý)?\.?$/, 1],
  [/^st(ř|ředa)?\.?$/, 2],
  [/^čt(v|vrtek)?\.?$/, 3],
  [/^pá(tek)?\.?$/, 4],
  [/^so(bota)?\.?$/, 5],
  [/^ne(děle)?\.?$/, 6],
];
const den = (s: string) => DEN_KLIC.find(([re]) => re.test(s))?.[1];

function minuty(s: string): number | null {
  const m = /^(\d{1,2})(?::(\d{2}))?$/.exec(s);
  if (!m) return null;
  const h = Number(m[1]), min = Number(m[2] ?? 0);
  if (h > 24 || min > 59 || (h === 24 && min > 0)) return null;
  return h * 60 + min;
}

export function parseCesky(radky: string[]): Tyden | null {
  if (!radky.length) return null;
  const tyden: Tyden = [[], [], [], [], [], [], []];
  const intervaly: [number, number, number][] = []; // [den, od, do] – do může přesáhnout 1440
  let neco = false;
  for (const surovy of radky) {
    const r = surovy
      .toLowerCase()
      .replace(/\([^)]*\)/g, ' ') // poznámky v závorce
      .replace(/[–—]/g, '-')
      .replace(/\s+/g, ' ')
      .trim();
    if (!r) continue;
    const m = /^([a-zěščřžýáíéůú.]+)(?:\s*-\s*([a-zěščřžýáíéůú.]+))?\s*:?\s+(.+)$/.exec(r);
    if (!m) return null;
    const a = den(m[1]), b = m[2] ? den(m[2]) : a;
    if (a == null || b == null) return null;
    const dny: number[] = [];
    for (let d = a; ; d = (d + 1) % 7) {
      dny.push(d);
      if (d === b || dny.length > 7) break;
    }
    // pozdější řádek přepisuje dřívější („Út - Ne: 9–18, Čtv: 9–17“)
    for (const d of dny) for (let i = intervaly.length - 1; i >= 0; i--) if (intervaly[i][0] === d) intervaly.splice(i, 1);
    const cas = m[3].replace(/\.$/, '').trim();
    if (/^zavřeno$/.test(cas)) {
      neco = true;
      continue;
    }
    for (const usek of cas.split(/\s*,\s*/)) {
      // „14:00/15:00 nebo do vyprodání“ → bereme dřívější čas
      const t = /^(\d{1,2}(?::\d{2})?)\s*-\s*(\d{1,2}(?::\d{2})?)(?:\/\d{1,2}(?::\d{2})?)?(?:\s+nebo do vyprodání)?$/.exec(usek);
      if (!t) return null;
      const od = minuty(t[1]);
      let doo = minuty(t[2]);
      if (od == null || doo == null || od >= 1440) return null;
      if (doo <= od) doo += 1440; // přes půlnoc
      if (doo - od > 1440) return null;
      for (const d of dny) intervaly.push([d, od, doo]);
    }
    neco = true;
  }
  if (!neco) return null;
  for (const [d, od, doo] of intervaly) {
    tyden[d].push([od, Math.min(doo, 1440)]);
    if (doo > 1440) tyden[(d + 1) % 7].push([0, doo - 1440]);
  }
  for (const d of tyden) d.sort((x, y) => x[0] - y[0]);
  return tyden.some((d) => d.length) ? tyden : null;
}
