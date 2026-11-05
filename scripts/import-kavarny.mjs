// Import kaváren z OpenStreetMap (Overpass API) do src/data/kavarny.json.
// Spouští workflow „Import kaváren“. Ruční úpravy a rozvrh zveřejnění u už známých kaváren zachovává.
//
// Co se ukládá: jen fakta (OSM tagy, okolí, a co kavárna sama uvádí na svém webu jako příznaky).
// Žádné texty z cizích webů ani data z Google Map.
import fs from 'node:fs';

const CIL = 'src/data/kavarny.json';
const PODNIKY = JSON.parse(fs.readFileSync('src/data/podniky.json', 'utf8'));
const LOKALITY = JSON.parse(fs.readFileSync('src/data/lokality.json', 'utf8'));
const STARE = fs.existsSync(CIL) ? JSON.parse(fs.readFileSync(CIL, 'utf8')) : { kavarny: [] };
const staraPodleId = new Map(STARE.kavarny.map((k) => [k.id, k]));

const SERVERY = [
  'https://overpass-api.de/api/interpreter',
  'https://overpass.kumi.systems/api/interpreter',
  'https://overpass.private.coffee/api/interpreter',
];
const UA = 'nabrunch.cz-import/1.0 (+https://nabrunch.cz)';

async function overpass(q) {
  let posledni;
  for (let pokus = 0; pokus < 6; pokus++) {
    const url = SERVERY[pokus % SERVERY.length];
    try {
      const r = await fetch(url, { method: 'POST', headers: { 'User-Agent': UA }, body: new URLSearchParams({ data: q }) });
      if (!r.ok) throw new Error(`${url} → ${r.status}`);
      return (await r.json()).elements;
    } catch (e) {
      posledni = e;
      console.log('Overpass chyba:', e.message, '– zkouším znovu');
      await new Promise((ok) => setTimeout(ok, 15000 * (pokus + 1)));
    }
  }
  throw posledni;
}

const CZ = 'area["ISO3166-1"="CZ"][admin_level=2]->.cz;';
const pozice = (e) => (e.lat != null ? [e.lat, e.lon] : e.center ? [e.center.lat, e.center.lon] : null);

function slugify(s) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/&/g, ' a ')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)
    .replace(/-+$/g, '');
}

function m(a, b) {
  const R = 6371000;
  const dLat = ((b[0] - a[0]) * Math.PI) / 180;
  const dLon = ((b[1] - a[1]) * Math.PI) / 180;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos((a[0] * Math.PI) / 180) * Math.cos((b[0] * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

/** Jednoduchá mřížka pro hledání nejbližších bodů */
function mrizka(body, krok = 0.02) {
  const g = new Map();
  const k = (la, lo) => `${Math.floor(la / krok)}_${Math.floor(lo / krok)}`;
  for (const b of body) {
    const key = k(b.p[0], b.p[1]);
    if (!g.has(key)) g.set(key, []);
    g.get(key).push(b);
  }
  return (p, max) => {
    const r = Math.ceil(max / 111000 / krok) + 1;
    const [i, j] = [Math.floor(p[0] / krok), Math.floor(p[1] / krok)];
    let best = null;
    for (let a = i - r; a <= i + r; a++)
      for (let b = j - r; b <= j + r; b++)
        for (const x of g.get(`${a}_${b}`) ?? []) {
          const d = m(p, x.p);
          if (d <= max && (!best || d < best.m)) best = { ...x, m: Math.round(d) };
        }
    return best;
  };
}

// ---------------------------------------------------------------- 1. kavárny
console.log('Stahuji kavárny…');
const kavarnyOsm = (await overpass(`[out:json][timeout:600];${CZ}nwr["amenity"="cafe"]["name"](area.cz);out center tags;`)).filter((e) => e.tags && pozice(e));
console.log('Kaváren v OSM:', kavarnyOsm.length);

// ---------------------------------------------------------------- 2. obce, MHD, parky (kontext okolí)
console.log('Stahuji obce…');
const obce = (await overpass(`[out:json][timeout:600];${CZ}node["place"~"^(city|town|village|suburb)$"]["name"](area.cz);out;`)).map((e) => ({
  p: [e.lat, e.lon],
  nazev: e.tags.name,
  typ: e.tags.place,
}));
const najdiObec = mrizka(obce.filter((o) => o.typ !== 'suburb'), 0.05);
const najdiCtvrt = mrizka(obce.filter((o) => o.typ === 'suburb'), 0.02);

console.log('Stahuji zastávky…');
const mhd = (
  await overpass(
    `[out:json][timeout:600];${CZ}(node["railway"~"^(tram_stop|station|halt)$"]["name"](area.cz);node["station"="subway"]["name"](area.cz););out;`,
  )
).map((e) => ({
  p: [e.lat, e.lon],
  nazev: e.tags.name,
  typ: e.tags.station === 'subway' || e.tags.subway === 'yes' ? 'metro' : e.tags.railway === 'tram_stop' ? 'tramvaj' : 'vlak',
}));
const najdiMhd = mrizka(mhd, 0.01);

console.log('Stahuji parky…');
const parky = (await overpass(`[out:json][timeout:600];${CZ}wr["leisure"="park"]["name"](area.cz);out center tags;`))
  .filter((e) => pozice(e))
  .map((e) => ({ p: pozice(e), nazev: e.tags.name }));
const najdiPark = mrizka(parky, 0.01);

// ---------------------------------------------------------------- 3. převod a obohacení
const RETEZCE = /^(starbucks|costa coffee|coffeeshop company|café café|cafe cafe|mamacoffee|dolce vita|paul|tchibo|mcdonald|mccaf[eé]|kfc|bageterie boulevard|crosscafe|cross caf[eé]|segafredo|gloria jean|coffee fusion|baristas|ugo|starcoffee|dunkin|tim hortons|benedict|costa)/i;
const ano = (v) => (v == null ? null : /^(yes|wlan|free|customers|outdoor|leashed|limited)$/i.test(v) ? true : /^no$/i.test(v) ? false : null);

const PRAHA_PSC = { '11': 'Praha 1', '12': 'Praha 2', '13': 'Praha 3', '14': 'Praha 4', '15': 'Praha 5', '16': 'Praha 6', '17': 'Praha 7', '18': 'Praha 8', '19': 'Praha 9', '10': 'Praha 10' };

const podnikyPozice = PODNIKY.filter((p) => p.lat != null).map((p) => ({ p: [p.lat, p.lon], nazev: p.nazev, slug: p.slug }));
const najdiPodnik = mrizka(podnikyPozice, 0.01);
const normNazev = (s) => slugify(s).replace(/-(cafe|kavarna|coffee|bistro|praha|brno)$/g, '');

const vystup = [];
const pouzite = new Set();
let duplicit = 0;
for (const e of kavarnyOsm) {
  const t = e.tags;
  const p = pozice(e);
  const id = `${e.type[0]}${e.id}`;
  // Je už mezi ověřenými podniky naBrunche? (do 60 m s podobným názvem, nebo do 15 m)
  const blizky = najdiPodnik(p, 60);
  if (blizky && (blizky.m <= 15 || normNazev(blizky.nazev).includes(normNazev(t.name).slice(0, 6)) || normNazev(t.name).includes(normNazev(blizky.nazev).slice(0, 6)))) {
    duplicit++;
    continue;
  }
  const obec = najdiObec(p, 15000);
  let mesto = t['addr:city'] || obec?.nazev || '';
  if (/^Praha\b/.test(mesto)) mesto = 'Praha';
  if (!mesto) continue;
  const psc = (t['addr:postcode'] || '').replace(/\s/g, '');
  const obvod = mesto === 'Praha' && /^1\d0\d\d$/.test(psc) ? PRAHA_PSC[psc.slice(0, 2)] ?? null : null;
  const ctvrt = t['addr:suburb'] || najdiCtvrt(p, 1500)?.nazev || null;
  const mestoSlug = LOKALITY[mesto] ?? slugify(mesto);

  const stara = staraPodleId.get(id);
  let slug = stara?.slug;
  if (!slug) {
    const zaklad = slugify(t.name) || 'kavarna';
    for (const kand of [zaklad, slugify(`${t.name} ${t['addr:street'] ?? ''}`), `${zaklad}-${e.id}`]) {
      if (kand && !pouzite.has(`${mestoSlug}/${kand}`)) {
        slug = kand;
        break;
      }
    }
  }
  pouzite.add(`${mestoSlug}/${slug}`);

  const ulice = [t['addr:street'] || t['addr:place'], t['addr:housenumber'] || t['addr:conscriptionnumber']].filter(Boolean).join(' ');
  const mh = najdiMhd(p, 600);
  const pk = najdiPark(p, 500);
  const diet = (k) => ano(t[`diet:${k}`]) || /^(only|yes)$/.test(t[`diet:${k}`] ?? '') || null;
  const cuisine = (t.cuisine || '').toLowerCase();
  vystup.push({
    id,
    slug,
    nazev: t.name.trim(),
    mesto,
    mestoSlug,
    obvod,
    ctvrt: ctvrt && ctvrt !== mesto ? ctvrt : null,
    ulice: ulice || null,
    psc: psc || null,
    lat: +p[0].toFixed(6),
    lon: +p[1].toFixed(6),
    oteviraciDoba: t.opening_hours || null,
    web: (t.website || t['contact:website'] || '').trim() || null,
    telefon: (t.phone || t['contact:phone'] || '').split(';')[0].trim() || null,
    email: (t.email || t['contact:email'] || '').trim() || null,
    instagram: (t['contact:instagram'] || '').trim() || null,
    facebook: (t['contact:facebook'] || '').trim() || null,
    retezec: !!(t.brand || RETEZCE.test(t.name)),
    znacka: t.brand || null,
    vlastnosti: {
      wifi: ano(t.internet_access),
      zahradka: ano(t.outdoor_seating),
      psi: ano(t.dog),
      bezbarierove: ano(t.wheelchair),
      karta: ano(t['payment:cards']) ?? ano(t['payment:credit_cards']) ?? ano(t['payment:debit_cards']),
      sSebou: ano(t.takeaway),
      vegan: diet('vegan'),
      vegetarian: diet('vegetarian'),
      bezlepek: diet('gluten_free'),
      snidane: ano(t.breakfast) || /breakfast|brunch/.test(cuisine) || null,
    },
    kuchyne: cuisine ? cuisine.split(/[;,]/).map((s) => s.trim()).filter(Boolean) : [],
    okoli: {
      mhd: mh ? { nazev: mh.nazev, typ: mh.typ, m: mh.m } : null,
      park: pk ? { nazev: pk.nazev, m: pk.m } : null,
    },
    zWebu: stara?.zWebu ?? null,
    zWebuKontrola: stara?.zWebuKontrola ?? null,
    pridano: stara?.pridano ?? new Date().toISOString().slice(0, 10),
    zverejnit: stara?.zverejnit ?? null,
  });
}
console.log('Vynecháno jako duplicity ověřených podniků:', duplicit);

// ---------------------------------------------------------------- 4. co kavárna uvádí na svém webu (jen příznaky)
const PRIZNAKY = {
  snidane: /snídan|snidan|breakfast/i,
  brunch: /brunch/i,
  vyberovaKava: /výběrov|vyberov|specialty|speciality coffee|third wave/i,
  prazirna: /pražírn|prazirn|pražíme|prazime|roastery|roasting/i,
  pekarna: /pekárn|pekarn|kváskov|kvaskov|croissant|bakery/i,
  dorty: /dort|zákusk|zakusk|cheesecake|dezert/i,
  vegan: /vegan/i,
  bezlepek: /bezlepk|gluten.?free/i,
  rezervace: /rezervac|reservation|book a table/i,
  hryProDeti: /dětsk[ýá] koutek|detsky koutek|herna|pro děti/i,
};
const dnes = new Date().toISOString().slice(0, 10);
const kontrolovat = vystup.filter((k) => k.web && (!k.zWebuKontrola || (Date.now() - Date.parse(k.zWebuKontrola)) / 864e5 > 90));
console.log('Kontroluji weby kaváren:', kontrolovat.length);
let hotovo = 0;
async function zkontroluj(k) {
  let url = k.web;
  if (!/^https?:\/\//i.test(url)) url = `https://${url}`;
  try {
    const c = new AbortController();
    const to = setTimeout(() => c.abort(), 10000);
    const r = await fetch(url, { signal: c.signal, redirect: 'follow', headers: { 'User-Agent': UA, Accept: 'text/html' } });
    clearTimeout(to);
    if (!r.ok || !/html/i.test(r.headers.get('content-type') || '')) throw new Error(String(r.status));
    const html = (await r.text()).slice(0, 400000);
    const text = html
      .replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ');
    const z = {};
    for (const [klic, re] of Object.entries(PRIZNAKY)) if (re.test(text)) z[klic] = true;
    if (!k.instagram) {
      const ig = html.match(/https?:\/\/(www\.)?instagram\.com\/([A-Za-z0-9_.]{2,30})\/?["'?]/);
      if (ig && !/^(p|reel|explore|accounts)$/.test(ig[2])) k.instagram = `https://www.instagram.com/${ig[2]}/`;
    }
    k.zWebu = z;
  } catch {
    k.zWebu = k.zWebu ?? null;
  }
  k.zWebuKontrola = dnes;
  if (++hotovo % 200 === 0) console.log(`  weby: ${hotovo}/${kontrolovat.length}`);
}
const fronta = [...kontrolovat];
await Promise.all(
  Array.from({ length: 12 }, async () => {
    while (fronta.length) await zkontroluj(fronta.shift());
  }),
);

// ---------------------------------------------------------------- 5. rozvrh postupného zveřejnění
// Nejdřív kavárny s nejvíc údaji. Každý den jedna dávka (60–110 kaváren), časy v rámci dne náhodně 6–21 h.
const skore = (k) =>
  (k.oteviraciDoba ? 4 : 0) +
  (k.web ? 2 : 0) +
  (k.telefon ? 1 : 0) +
  (k.ulice ? 1 : 0) +
  Object.values(k.vlastnosti).filter((v) => v != null).length +
  (k.zWebu ? Object.keys(k.zWebu).length : 0) -
  (k.retezec ? 3 : 0);
const naplanovane = vystup.filter((k) => k.zverejnit).map((k) => k.zverejnit).sort();
let den = new Date(naplanovane.length ? naplanovane[naplanovane.length - 1].slice(0, 10) : dnes);
if (den < new Date(dnes)) den = new Date(dnes);
let vDavce = 0;
let velikost = 60 + Math.floor(Math.random() * 51);
const nove = vystup.filter((k) => !k.zverejnit).sort((a, b) => skore(b) - skore(a));
for (const k of nove) {
  if (vDavce >= velikost) {
    den = new Date(den.getTime() + 864e5);
    vDavce = 0;
    velikost = 60 + Math.floor(Math.random() * 51);
  }
  const h = 6 + Math.floor(Math.random() * 15);
  const min = Math.floor(Math.random() * 60);
  // Čas v Praze (UTC+1/+2) – stačí přibližně
  k.zverejnit = `${den.toISOString().slice(0, 10)}T${String(h - 1).padStart(2, '0')}:${String(min).padStart(2, '0')}:00Z`;
  vDavce++;
}

vystup.sort((a, b) => a.id.localeCompare(b.id));
fs.writeFileSync(
  CIL,
  JSON.stringify(
    {
      _popis:
        'Kavárny z OpenStreetMap (neověřené). Generuje workflow „Import kaváren“ (scripts/import-kavarny.mjs). Ručně neupravovat – ověřenou kavárnu přidej do podniky.json, při dalším importu se sama vynechá.',
      importovano: new Date().toISOString(),
      zdroj: '© přispěvatelé OpenStreetMap (ODbL)',
      kavarny: vystup,
    },
    null,
    0,
  ).replace(/\},\{"id"/g, '},\n{"id"'),
);

const s = (f) => vystup.filter(f).length;
const souhrn = [
  `Kaváren celkem: ${vystup.length} (nových: ${nove.length}, vynecháno duplicit: ${duplicit})`,
  `S otevírací dobou: ${s((k) => k.oteviraciDoba)}, s webem: ${s((k) => k.web)}, s telefonem: ${s((k) => k.telefon)}, s adresou: ${s((k) => k.ulice)}`,
  `Wifi: ${s((k) => k.vlastnosti.wifi)}, zahrádka: ${s((k) => k.vlastnosti.zahradka)}, psi: ${s((k) => k.vlastnosti.psi)}, snídaně (OSM nebo web): ${s((k) => k.vlastnosti.snidane || k.zWebu?.snidane || k.zWebu?.brunch)}`,
  `Řetězce: ${s((k) => k.retezec)}, obcí: ${new Set(vystup.map((k) => k.mestoSlug)).size}`,
  `Poslední den rozvrhu: ${[...vystup.map((k) => k.zverejnit)].sort().pop()}`,
];
console.log(souhrn.join('\n'));
if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, souhrn.map((r) => `- ${r}`).join('\n') + '\n');
