// Seznam Webmaster API: přehled indexace + žádosti o reindexaci nových URL ze sitemapy.
// Spouští workflow „Seznam Webmaster“. Klíč je v secretu SEZNAM_WMT_API_KEY (posílá se jen do API Seznamu).
// Odeslané URL si pamatuje v .seznam/odeslano.txt (GitHub cache, ne v repu).
import fs from 'node:fs';

const API = 'https://reporter.seznam.cz/wm-api';
const KEY = process.env.SEZNAM_WMT_API_KEY;
const SITE = `https://${process.env.SITE_HOST || 'nabrunch.cz'}`;
const LIMIT = Number(process.env.LIMIT || 450); // Seznam povoluje 500 reindexací denně
const STAV = '.seznam/odeslano.txt';
const SUMMARY = process.env.GITHUB_STEP_SUMMARY;

if (!KEY) { console.error('Chybí secret SEZNAM_WMT_API_KEY.'); process.exit(1); }

const out = (s = '') => { console.log(s); if (SUMMARY) fs.appendFileSync(SUMMARY, s + '\n'); };
const skryj = (s) => String(s).replaceAll(KEY, '***');
const pauza = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(cesta, { method = 'GET', query = {} } = {}) {
  const url = new URL(API + cesta);
  url.searchParams.set('key', KEY);
  for (const [k, v] of Object.entries(query)) url.searchParams.set(k, v);
  for (let pokus = 0; pokus < 4; pokus++) {
    const r = await fetch(url, { method, headers: { accept: 'application/json' } });
    if (r.status === 429 || r.status >= 502) { await pauza(1000 * 2 ** pokus); continue; }
    const text = await r.text();
    if (r.status === 204) return { status: 204, data: null };
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = skryj(text).slice(0, 300); }
    return { status: r.status, data };
  }
  return { status: 429, data: 'Překročen limit požadavků' };
}

// 1) Přehled indexace
out('## Seznam Webmaster – nabrunch.cz');
const web = await api('/web');
if (web.status === 401 || web.status === 400) { out(`Chybný API klíč (HTTP ${web.status}).`); process.exit(1); }
if (web.status !== 200) {
  out(`Přehled nedostupný (HTTP ${web.status}): ${JSON.stringify(web.data)}`);
} else {
  const d = web.data.documents || {};
  const n = (x) => (x && typeof x.count === 'number' ? x.count : '–');
  out('');
  out('| Zaindexováno | Stažený obsah | Přesměrování | Chyby | Známé stránky |');
  out('|---|---|---|---|---|');
  out(`| ${n(d.index)} | ${n(d.content)} | ${n(d.redirect)} | ${n(d.error)} | ${n(d.doc_count)} |`);
  const chyby = d.error?.urls || [];
  if (chyby.length) { out(''); out('**Ukázka chybových stránek:**'); chyby.slice(0, 15).forEach((u) => out(`- ${u}`)); }
  const hist = (web.data.history || []).slice(-7);
  if (hist.length) {
    out(''); out('**Posledních 7 dní** (datum: zaindexováno / chyby)');
    hist.forEach((h) => out(`- ${h.date}: ${h.counts?.indexed ?? '–'} / ${h.counts?.error ?? '–'}`));
  }
}

// 2) Reindexace nových URL ze sitemapy
if (process.env.REINDEX === 'false') process.exit(0);
const xml = await (await fetch(`${SITE}/sitemap.xml`)).text();
const vSitemape = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim()).filter((u) => u.startsWith(SITE));
const odeslano = new Set(fs.existsSync(STAV) ? fs.readFileSync(STAV, 'utf8').split('\n').filter(Boolean) : []);
const nove = vSitemape.filter((u) => !odeslano.has(u));

out(''); out(`**Reindexace:** v sitemapě ${vSitemape.length} URL, nových ${nove.length}, dnes odesílám max. ${LIMIT}.`);
let ok = 0, chyba = 0, posledniChyba = '';
for (const u of nove.slice(0, LIMIT)) {
  const r = await api('/web/document/reindex', { method: 'POST', query: { url: u } });
  if (r.status >= 200 && r.status < 300) { ok++; odeslano.add(u); }
  else {
    chyba++; posledniChyba = `HTTP ${r.status} ${JSON.stringify(r.data)}`;
    if (r.status === 403) { out('Klíč nemá právo zápisu – reindexace nejde. Vytvoř v Seznam Webmasteru klíč s právem zápisu.'); break; }
    if (r.status === 429 || chyba >= 10) break; // denní limit nebo opakované chyby
  }
  await pauza(700); // max. 100 požadavků za minutu
}
fs.mkdirSync('.seznam', { recursive: true });
fs.writeFileSync(STAV, [...odeslano].join('\n') + '\n');
out(`Odesláno ${ok}, chyb ${chyba}${posledniChyba ? ` (poslední: ${posledniChyba})` : ''}. Zbývá ${Math.max(0, nove.length - ok)}.`);
