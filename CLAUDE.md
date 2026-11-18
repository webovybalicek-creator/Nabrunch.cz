# naBrunch.cz – pokyny pro Clauda

Průvodce snídaňovými a brunchovými místy v Česku. Statický web na **Astro 7**, data v JSON a Markdownu,
žádná databáze ani CMS. Do 25. 10. 2026 běžel na WordPressu, odkud se převzala data, adresy i SEO.
Podrobnosti viz `README.md`.

**Komunikuj česky. Texty na webu piš česky, s českými uvozovkami „…“ a správnou diakritikou.**

## Jak se pracuje

- Úpravy se dělají přímo v repu. **Každý push do `main` se automaticky nasadí na ostrý web** (GitHub Action `deploy.yml`, FTPS na hosting). Žádný ruční upload.
- Před pushem vždy ověř, že web jde sestavit: `npm install` (jednou) a `npm run build`. Když build spadne, nepushuj.
- Lokální náhled: `npm run dev`.
- Commit zprávy piš česky a krátce popiš, co se změnilo (např. „Přidán podnik Café XY, Praha 2“).
- Po pushu zkontroluj v záložce Actions, že workflow **Build a deploy** doběhl zeleně.

## Kde co je

| Co chci upravit | Soubor |
|---|---|
| Podniky (karty, detail, mapa) | `src/data/podniky.json` |
| Neověřené kavárny (generované) | `src/data/kavarny.json` – jen přes workflow Import kaváren |
| Úpravy kaváren od majitelů | `src/data/kavarny-majitele.json` – jen přes workflow Synchronizace kaváren |
| Články blogu | `src/content/blog/<slug>.md` |
| Lokality (název → slug) | `src/data/lokality.json` |
| Témata (vegan, pet-friendly…) | `TEMATA` v `src/lib/data.ts` |
| Obchodní podmínky, GDPR | `src/data/stranky.json` |
| Statické stránky (O nás, Kontakt, FAQ…) | `src/pages/*.astro` |
| Hlavička, patička, komponenty | `src/components/` |
| Barvy, písma, rozvržení | `src/styles/global.css` (proměnné v `:root`) |
| E-mail, sociální sítě, AdSense, GA | `src/lib/config.ts` |
| Přesměrování starých adres | `public/.htaccess` |
| Formuláře (přidat podnik, newsletter, hodnocení, správa kaváren) | `public/api/*.php` |
| Administrace (schvalování hodnocení, majitelů, úprav, fotek) | `public/api/admin.php`, adresa `/prihlaseni/` |

## Přidání nebo úprava podniku

Podniky a články jde upravovat i v administraci (`/prihlaseni/` → záložky **Podniky** a **Články**, kód `public/api/_admin-obsah.php`).
Editor uloží změnu jedním commitem do `main` přes GitHub API (token v administraci na záložce Nastavení, uložený jen na serveru
v `/_data/github-token.txt`) a web se nasadí jako po každém pushi. Fotky převede na WebP (obě velikosti). JSON zapisuje ve stejném
formátu jako soubory v repu, takže v diffu jsou jen skutečné změny. **Před vlastní úpravou v repu si proto vždy stáhni aktuální `main`.**

Podnik je jeden objekt v poli `src/data/podniky.json`. Strukturu popisuje rozhraní `Podnik` v `src/lib/data.ts` –
nový podnik zkopíruj z existujícího a vyplň všechna pole.

- `id` – unikátní číslo (vezmi nejvyšší existující + 1).
- `slug` – malá písmena bez diakritiky, slova oddělená pomlčkou; z něj vznikne adresa `/podniky/<slug>/`. **U existujících podniků slug neměň** (rozbilo by to adresu, kterou zná Google).
- `datum` – ve formátu `RRRR-MM-DD HH:MM:SS`; podle něj se řadí „Nově přidané“.
- `doporuceny: true` = „Naše srdcovka“ (kategorie Doporučené podniky).
- `lokality` – u Prahy obvod i město, např. `["Praha 2", "Praha"]`. Nová lokalita musí mít záznam v `src/data/lokality.json`.
- `lat` / `lon` – souřadnice pro mapu (z Google Maps).
- `popis` a `tip` jsou HTML (`<p>`, `<h2>`, `<strong>`…).
- `foto` a `galerie` – viz Obrázky níže.

## Obrázky

V datech jsou obrázky uložené pod cestou `/wp-content/uploads/RRRR/MM/nazev.jpg`, ale web zobrazuje WebP verze:

- `public/obrazky/RRRR/MM/nazev.webp` – velký, max. 1400 px (detail, galerie, články)
- `public/nahledy/RRRR/MM/nazev.webp` – náhled, šířka 640 px (karty)

**Nové obrázky** (WordPress už neběží, workflow „Stažení obrázků“ pro ně nepoužívej): převeď fotku na WebP
ve dvou velikostech, ulož do obou složek pod stejnou cestou a v datech uveď odpovídající cestu
`/wp-content/uploads/RRRR/MM/nazev.jpg` (přípona v datech nevadí, převádí se na `.webp`).
Na převod lze použít Python s Pillow (viz `scripts/stahni-obrazky.py`, funkce `uloz`).
AVIF verze (`.avif` vedle každého `.webp`, web je načítá přednostně přes `<picture>`) vytvoří po pushi sám workflow
**AVIF obrázky** (`scripts/avif.py`), ručně je dělat není potřeba.
Názvy souborů bez mezer a diakritiky.

## Články blogu

Soubor `src/content/blog/<slug>.md`, adresa článku je `/<slug>/`. Frontmatter:

```yaml
---
title: "Nadpis článku"
description: "Popis pro vyhledávače (cca 150 znaků)"
seoTitle: "Titulek pro Google – naBrunch.cz"   # nepovinné
date: "2026-10-25T10:00:00"
image: "/wp-content/uploads/2026/10/obrazek.jpg"
---
```

Tělo je HTML (převzaté z WordPressu), nové články můžou být v HTML i Markdownu.

## SEO – na co pozor

- `src/data/seo-wp.json` obsahuje titulky a popisy převzaté z WordPressu a **má přednost** před tím, co je v datech.
  Když chceš u existující stránky změnit `<title>` nebo meta popis, uprav záznam pro její cestu právě tady.
- Neměň adresy (slugy) existujících stránek. Pokud je nutné, přidej přesměrování 301 do `public/.htaccess`.
- `SITE.indexovat` v `src/lib/config.ts` musí zůstat `true`.

## Co neměnit bez domluvy s Vladimírem

- `.github/workflows/deploy.yml` a `deploy/deploy.php` – nasazení na hosting.
- `public/api/_smtp.php` a odesílání e-mailů, `public/.htaccess`.
- ID AdSense a Analytics v `src/lib/config.ts`, `public/ads.txt`.
- **Nespouštěj `scripts/import-wxr.py` ani `scripts/seo-z-wp.py`** – sloužily k jednorázovému převodu z WordPressu
  a přepsaly by všechna data včetně pozdějších úprav.
- Hesla a API klíče nikdy nedávej do repa. Jsou v GitHub Secrets (`FTP_*`, `SMTP_PASSWORD`, `ECOMAIL_API_KEY`)
  a deploy je zapisuje jen na server.

## Neověřené kavárny z OpenStreetMap ☕

Vedle ověřených podniků (`podniky.json`) web ukazuje i kavárny z OpenStreetMap. Jsou **oddělené**: nemění homepage,
srdcovky, nově přidané ani témata a všude mají štítek „Neověřeno“.

**Data:** `src/data/kavarny.json` – generuje workflow **Import kaváren** (`scripts/import-kavarny.mjs`), ručně neupravovat.
Import stáhne `amenity=cafe` z OSM, doplní okolí (MHD, park), z webu kavárny vyčte jen příznaky (snídaně, brunch,
výběrová káva, pražírna, vegan, bezlepkové…) – žádné texty. Vynechá duplicity s `podniky.json` (do 60 m + podobný název).
U známých kaváren zachová slug a rozvrh zveřejnění. Data z Google Map se nepoužívají (porušuje podmínky).

**Postupné zveřejnění:** každá kavárna má v datech čas `zverejnit`; build zobrazí jen ty, jejichž čas nastal.
Workflow **Publikace kaváren** běží 2× denně (5:23 a 14:41 UTC) a když je něco nového na řadě, spustí deploy.
Nejdřív vycházejí kavárny s nejvíc údaji, 8–110 denně. Nové kavárny z dalšího importu se zařadí na konec rozvrhu.

**Stránky:** `/kavarny/` (přehled), `/kavarny/<město>/` (seznam, filtry, mapa), `/kavarny/<město>/<slug>/` (detail).
Dále přepínač na `/mapa/`, výsledky ve vyhledávání a sekce „Další kavárny“ na stránkách lokalit.
Kód: `src/lib/kavarny.ts`, `src/components/KavarnaKarta.astro`, `src/components/Neovereno.astro`, `src/pages/kavarny/`.

**Ochrana proti penalizaci (neměnit bez rozmyslu):**
- Text detailu se skládá jen z faktů (`popisKavarny`), žádné přepisované cizí texty ani hromadné AI texty.
- Do Googlu jen detaily s dost údaji (`indexovatKavarnu`: otevírací doba + adresa + skóre údajů ≥ 9, ne řetězce);
  ostatní `noindex` a mimo sitemapu. Stránka města se indexuje od 3 kaváren (`indexovatMesto`).

**Ověření kavárny:** po osobní návštěvě ji přidej jako běžný podnik do `podniky.json`. Při dalším importu se z neověřených
vyřadí. Pokud už byla zaindexovaná, přidej 301 ze staré adresy `/kavarny/…` na `/podniky/…` do `public/.htaccess`.

### Správa kaváren majiteli

**Vstup:** stránka `/pridat-podnik/` – 1) majitel vyhledá svůj podnik; neověřená kavárna → „převzít správu“ (vede na
formulář `#majitel` na detailu kavárny), ověřený podnik naBrunche → e-mail redakci; 2) když podnik nenajde, založí nový
(název, adresa, poloha na mapě, otevírací doba, vlastnosti, kontakty). Nový podnik se přidá hned, když e-mail je na doméně
webu podniku a web uvádí jeho název; jinak ho redakce schválí jedním klikem z e-mailu. Zaškrtnutím „chci návštěvu“ dostane
redakce žádost o zařazení mezi doporučené (ty dál vznikají jen ručně v `podniky.json`).

Na detailu kavárny je „Jste majitel? Upravte údaje zdarma“ (`/api/kavarna.php`, data v SQLite v `/_data/`).
- **Automatické ověření:** e-mail se shoduje s e-mailem kavárny v OSM, je na doméně jejího webu, nebo je uvedený na jejím webu
  (kontroluje se úvodní stránka a /kontakt) → přihlašovací odkaz přijde hned. Jinak přijde žádost na karolina@nabrunch.cz
  se schválením jedním klikem.
- Majitel na `/sprava-kavarny/` upraví otevírací dobu, kontakty, vlastnosti (výběr ze seznamu), krátký popis (bez odkazů)
  a nahraje až 6 fotek (převod na WebP, ukládají se na serveru do `/fotky-kavaren/`, mimo repo).
- **Kontrola:** formát polí, zákaz odkazů v popisu, filtr vulgarismů a spamu, limity pokusů. Čisté změny jdou na web
  automaticky, podezřelé čekají na schválení. Každá změna a fotka přijde e-mailem s odkazy Vrátit / Smazat / Zablokovat.
- **Administrace `/prihlaseni/`** (`public/api/admin.php`): schvalování textů hodnocení podniků i kaváren (záložka Hodnocení: skrýt text, smazat i hvězdičky),
  žádostí o správu a nových kaváren (i s úpravou údajů před schválením, tlačítko Upravit), zadržené úpravy
  (se zvýrazněním změn), fotky, majitelé (blokace) a historie změn (vrátit předchozí). Přihlášení bez hesla: e-mail ze seznamu
  `SPRAVCI` v `admin.php` dostane odkaz na 30 minut, nebo odkaz „Přehled všech majitelů a změn“ z konce e-mailu pro správce.
  Přihlášení platí 30 dní (cookie). Logika zásahů je v `$spravce` v `kavarna.php` – sdílí ji admin i odkazy z e-mailů.
- Workflow **Synchronizace kaváren** každé 3 h stáhne `?export=1` do `src/data/kavarny-majitele.json` a při změně nasadí web.
  Kavárna ověřená majitelem se zveřejní hned (bez čekání na rozvrh), má štítek „Ověřeno majitelem“ a jde do indexu.
- **Hodnocení** (`/api/hodnoceni.php`, komponenta `Hodnoceni.astro`) mají ověřené podniky (id = číslo) i kavárny (id = `n…`/`w…`/`r…`
  z OSM, `m…` od majitele). Na kartách kaváren se hvězdičky ukážou jen u hodnocených.

## Užitečné workflow (GitHub → Actions → Run workflow)

- **Screenshot živého webu** – vyfotí zadané stránky (desktop i mobil) do větve `screenshots` a vypíše chyby z konzole.
- **Kontrola URL** – stavové kódy a přesměrování pro zadané cesty.
- **Test SMTP** – ověří odesílání e-mailů z formulářů.
- **Import kaváren** – obnoví data neověřených kaváren z OpenStreetMap (spouštět ručně, cca 1× za čtvrtletí).
- **Publikace kaváren** – běží sám 2× denně, ručně jen pro okamžité zveřejnění.
- **Synchronizace kaváren** – každé 3 hodiny přenese úpravy majitelů na web, ručně pro okamžité zveřejnění.
- **Seznam Webmaster** – denně vypíše do souhrnu běhu stav indexace v Seznamu a pošle nové URL ze sitemapy
  k reindexaci (max. 450/den, odeslané si pamatuje v cache). Klíč v secretu `SEZNAM_WMT_API_KEY` (musí mít právo zápisu).
  Ověření webu: `public/seznam-wmt-….txt` a meta `seznam-wmt` v `src/layouts/Base.astro` – nemazat.
