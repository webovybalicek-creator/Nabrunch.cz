# naBrunch.cz 🥞

Průvodce nejlepšími snídaňovými a brunchovými místy v Česku. Statický web na [Astro](https://astro.build), stejný základ jako najdikadernictvi.cz.

Zatím běží na **new.nabrunch.cz** (noindex) vedle původního WordPressu na nabrunch.cz.

## Vývoj

```bash
npm install
npm run dev      # lokální náhled
npm run build    # sestavení do dist/
```

## Obsah

| Co | Kde |
|---|---|
| Podniky (99) | `src/data/podniky.json` |
| Lokality (slugy z WP) | `src/data/lokality.json` |
| Články blogu | `src/content/blog/*.md` (HTML z WordPressu) |
| Obchodní podmínky, GDPR | `src/data/stranky.json` |
| Témata (vegan, pet-friendly…) | `TEMATA` v `src/lib/data.ts` |
| Nastavení webu, AdSense, GA | `src/lib/config.ts` |

Data vznikla z exportu WordPressu: `python3 scripts/import-wxr.py export.xml`
(přepíše podniky, lokality, články a seznam obrázků `scripts/obrazky.json`).

Adresy zůstávají stejné jako na WordPressu: `/podniky/<slug>/`, články `/<slug>/`, lokality `/<lokalita>/`,
obrázky `/wp-content/uploads/…`. Staré adresy taxonomií přesměrovává `public/.htaccess`.

## SEO (převzato z WordPressu)

- Všechny adresy z WP sitemap existují na stejné URL (podniky, články, `/lokalita/…/`, `/kategorie/…/`, stránky).
- Titulky a popisy jsou přesně z živého WP: `src/data/seo-wp.json`. Obnovení: workflow **SEO audit WordPressu**
  (výsledek ve větvi `seo-audit`) → `python3 scripts/seo-z-wp.py wp-seo.json`.
- Staré adresy bez vlastní stránky přesměrovává `public/.htaccess` (stránkování, štítky, autoři, WP sitemapy, obrázky).
- Workflow **Kontrola URL** vypíše stavové kódy a přesměrování pro zadané cesty.

## Obrázky

Workflow **Stažení obrázků** stáhne obrázky z nabrunch.cz podle `scripts/obrazky.json`, zmenší je (max. 1600 px),
vytvoří náhledy `public/nahledy/…` (WebP 640 px) a commitne je. Spouští se samo při změně seznamu.

## Deploy

Každý push do `main` (a dokončené stažení obrázků) web sestaví a přes FTPS nahraje na hosting (`.github/workflows/deploy.yml`).
Nahrávají se jen změněné soubory, server je rozbalí dočasným PHP skriptem.

Secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` (FTP účet `new.nabrunch.cz` → `/nabrunch.cz/sub/new`).
Proměnná `SITE_HOST` (výchozí `new.nabrunch.cz`) – doména, přes kterou se volá rozbalovač.

## Formulář „Přidat podnik“

`public/api/podnik.php` pošle údaje i fotky e-mailem na karolina@nabrunch.cz.

## Spuštění na nabrunch.cz

1. `SITE.indexovat = true` v `src/lib/config.ts` (zapne indexaci, sitemapu v robots.txt; AdSense a GA se načtou jen na nabrunch.cz).
2. Na hostingu přepnout službu nabrunch.cz na složku webu, FTP účet a proměnnou `SITE_HOST` na `nabrunch.cz`.
3. V AdSense nastavit zprávu o souhlasu (GDPR) – Consent Mode je ve výchozím stavu „denied“.
