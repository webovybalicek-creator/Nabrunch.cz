// Časté dotazy k podniku – skládají se jen z faktů v datech (žádné vymyšlené texty).
// Když údaj chybí, otázka se vynechá. Používá detail podniku (viditelná sekce + FAQPage)
// a /llms.txt (přehled pro AI vyhledávače).
import { mesto, vOkoli, kmText, tydenPodniku, vLokalite, podnikuText, type Podnik, type Lokalita } from './data';
import { souhrn, hhmm } from './oteviraci-doba';

/** „Po–Pá 8:00–18:00, So–Ne zavřeno“ (null, když dobu neznáme) */
export function dobaText(p: Podnik): string | null {
  const t = tydenPodniku(p);
  if (!t) return null;
  return souhrn(t)
    .map(({ dny, cas }) => `${dny} ${cas === 'Zavřeno' ? 'zavřeno' : cas}`)
    .join(', ');
}

const vecny = (v: string) => v.replace(/\s+/g, ' ').trim();

export function dotazyPodniku(p: Podnik): [string, string][] {
  const out: [string, string][] = [];
  const n = p.nazev;
  const t = tydenPodniku(p);

  // Otevírací doba
  const doba = dobaText(p);
  if (doba && t) {
    const nedele = t[6].filter(([a]) => a > 0);
    const sobota = t[5].filter(([a]) => a > 0);
    const vikend =
      nedele.length && sobota.length
        ? sobota[0][0] === nedele[0][0]
          ? ` O víkendu otevírá v sobotu i v neděli v ${hhmm(sobota[0][0])}.`
          : ` O víkendu otevírá v sobotu v ${hhmm(sobota[0][0])} a v neděli v ${hhmm(nedele[0][0])}.`
        : sobota.length
          ? ` V sobotu otevírá v ${hhmm(sobota[0][0])}, v neděli je zavřeno.`
          : nedele.length
            ? ` V sobotu je zavřeno, v neděli otevírá v ${hhmm(nedele[0][0])}.`
            : ' O víkendu je zavřeno.';
    out.push([`Kdy má otevřeno ${n}?`, vecny(`Otevírací doba: ${doba}.${vikend}`)]);
  }

  // Poloha
  if (p.adresa) {
    const okoli = vOkoli(p, 1)[0];
    const blizko = okoli && okoli.km < 1.5 ? ` Nejbližší další podnik z naBrunche je ${okoli.p.nazev} (${kmText(okoli.km)} vzdušnou čarou).` : '';
    const m = mesto(p).replace(/ \d+$/, '');
    const adresa = p.adresa.includes(m) ? p.adresa : `${p.adresa}, ${m}`;
    out.push([`Kde najdu ${n}?`, vecny(`${n} sídlí na adrese ${adresa}.${blizko}`)]);
  }

  // Menu (lidé často hledají „<podnik> menu“) – naše tipy z návštěvy + odkaz, kde je aktuální nabídka
  const tip = (p.tip ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const kdeMenu = p.web ? 'na webu podniku' : p.instagram ? `na Instagramu ${/^@/.test(p.instagram) ? p.instagram : 'podniku'}` : '';
  if (tip || kdeMenu)
    out.push([
      `Jaké má ${n} menu a co si dát?`,
      vecny(`${tip ? `Náš tip: ${tip.length > 220 ? tip.slice(0, 220).replace(/\s+\S*$/, '') + '…' : tip}` : ''} ${kdeMenu ? `Aktuální menu a ceny najdete ${kdeMenu}, odkaz je v kontaktech na této stránce.` : ''}`),
    ]);

  // Rezervace
  const r = (p.rezervace ?? '').trim();
  if (r) {
    const odpoved = /^https?:/i.test(r) || /online/i.test(r)
      ? 'Ano, stůl si můžete rezervovat online.'
      : /telefon/i.test(r)
        ? `Ano, rezervovat lze telefonicky${p.telefonText || p.telefon ? ` na čísle ${p.telefonText ?? p.telefon}` : ''}.`
        : /^ne\b/i.test(r)
          ? 'Rezervace nepřijímají, místa se obsazují podle příchodu.'
          : /^ano\b/i.test(r)
            ? 'Ano, rezervace jsou možné.'
            : '';
    if (odpoved) out.push([`Dá se v podniku ${n} rezervovat stůl?`, odpoved]);
  }

  // Psi, zahrádka
  if (p.atmosfera.includes('Pet-friendly')) out.push([`Můžu do podniku ${n} vzít psa?`, 'Ano, podnik je pet-friendly a pejsci jsou tu vítáni.']);
  if (p.atmosfera.includes('Zahrádka') || p.sezeni.includes('Zahrádka'))
    out.push([`Má podnik ${n} venkovní zahrádku?`, 'Ano, kromě sezení uvnitř se dá v teplých měsících sedět venku na zahrádce.']);

  // Strava
  const strava = [
    p.kuchyne.includes('Veganská') && 'veganská jídla',
    p.kuchyne.includes('Zdravá/fitness') && 'zdravé a fitness snídaně',
    p.kuchyne.includes('Orientální') && 'jídla orientální kuchyně',
  ].filter(Boolean) as string[];
  if (strava.length) out.push([`Co nabízí podnik ${n} kromě klasických snídaní?`, `V nabídce jsou i ${strava.join(', ')}.`]);

  // Platba a Wi-Fi
  const karta = p.platba.includes('Kartou'), hotove = p.platba.includes('Hotově');
  const platba = karta && hotove ? 'platit lze kartou i hotově' : karta ? 'platí se kartou' : hotove ? 'platí se jen hotově' : '';
  const wifi = p.wifi === true ? 'Wi-Fi je k dispozici' : p.wifi === false ? 'Wi-Fi tu není' : '';
  if (platba || wifi) {
    const v = [platba, wifi].filter(Boolean).join(' a ');
    out.push([
      platba && wifi ? `Dá se v podniku ${n} platit kartou a je tu Wi-Fi?` : platba ? `Dá se v podniku ${n} platit kartou?` : `Je v podniku ${n} Wi-Fi?`,
      v.charAt(0).toUpperCase() + v.slice(1) + '.',
    ]);
  }
  return out;
}

// ---------------------------------------------------------------- dotazy ke stránce města / obvodu
const vycet = (jmena: string[], max = 3) => {
  const v = jmena.slice(0, max);
  const zbytek = jmena.length - v.length;
  if (v.length === 1) return v[0];
  const zaklad = `${v.slice(0, -1).join(', ')} a ${v[v.length - 1]}`;
  return zbytek === 1 ? `${v.join(', ')} a jeden další` : zbytek > 1 ? `${v.join(', ')} a ${zbytek} dalších` : zaklad;
};
/** Kdy podnik v daných dnech otevírá nejdřív (interval od 0:00 je pokračování předchozí noci) */
const otevira = (p: Podnik, dny: number[]) => {
  const t = tydenPodniku(p);
  if (!t) return null;
  const c = dny.flatMap((d) => t[d].filter(([a]) => a > 0).map(([a]) => a));
  return c.length ? Math.min(...c) : null;
};

export function dotazyLokality(l: Lokalita): [string, string][] {
  const out: [string, string][] = [];
  const P = l.podniky;
  if (P.length < 2) return out;
  // „v Praze 1“ (vLokalite dává hovorové „na Praze 1“)
  const kde = /^Praha \d+$/.test(l.nazev) ? `v ${l.nazev.replace('Praha', 'Praze')}` : vLokalite(l.nazev);
  const srdcovky = P.filter((p) => p.doporuceny).map((p) => p.nazev);
  out.push([
    `Kde je nejlepší brunch ${kde}?`,
    srdcovky.length
      ? `${srdcovky.length === 1 ? `Naše srdcovka ${kde} je` : `Naše srdcovky ${kde} jsou`} ${vycet(srdcovky)}. Celkem jsme ${kde} vybrali ${podnikuText(P.length)} na snídani a brunch.`
      : `${kde.charAt(0).toUpperCase() + kde.slice(1)} jsme vybrali ${podnikuText(P.length)} na snídani a brunch, například ${vycet(P.map((p) => p.nazev))}.`,
  ]);

  const rano = P.map((p) => ({ p, od: otevira(p, [0, 1, 2, 3, 4]) }))
    .filter((x): x is { p: Podnik; od: number } => x.od != null)
    .sort((a, b) => a.od - b.od);
  if (rano.length >= 2)
    out.push([
      `Kde se ${kde} nasnídat brzy ráno?`,
      `Ve všední dny otevírá nejdřív ${rano[0].p.nazev} (od ${hhmm(rano[0].od)}), dále ${vycet(rano.slice(1, 3).map((x) => `${x.p.nazev} (od ${hhmm(x.od)})`))}.`,
    ]);

  const nedele = P.map((p) => ({ p, od: otevira(p, [6]) }))
    .filter((x): x is { p: Podnik; od: number } => x.od != null)
    .sort((a, b) => a.od - b.od);
  const sDobou = P.filter((p) => tydenPodniku(p)).length;
  if (sDobou >= 2)
    out.push([
      `Kam ${kde} na brunch v neděli?`,
      nedele.length
        ? `V neděli má otevřeno ${nedele.length} z ${sDobou} podniků, u kterých známe otevírací dobu. Nejdřív otevírá ${nedele[0].p.nazev} (od ${hhmm(nedele[0].od)})${nedele.length > 1 ? `, dále ${vycet(nedele.slice(1, 3).map((x) => `${x.p.nazev} (od ${hhmm(x.od)})`))}` : ''}.`
        : `Podniky, které ${kde} sledujeme, mají v neděli zavřeno. Mrkněte na okolní města nebo na stránku Otevřeno teď.`,
    ]);

  const psi = P.filter((p) => p.atmosfera.includes('Pet-friendly')).map((p) => p.nazev);
  if (psi.length) out.push([`Kam ${kde} na brunch se psem?`, `Pejsci jsou vítáni v ${psi.length === 1 ? 'podniku' : 'podnicích'} ${vycet(psi, 4)}.`]);
  const zahradka = P.filter((p) => p.atmosfera.includes('Zahrádka') || p.sezeni.includes('Zahrádka')).map((p) => p.nazev);
  if (zahradka.length) out.push([`Kde ${kde} posedět na zahrádce?`, `Venkovní zahrádku ${zahradka.length === 1 ? 'má' : 'mají'} ${vycet(zahradka, 4)}.`]);
  const vegan = P.filter((p) => p.kuchyne.includes('Veganská')).map((p) => p.nazev);
  if (vegan.length) out.push([`Kde ${kde} najdu veganskou snídani?`, `Veganská jídla ${vegan.length === 1 ? 'nabízí' : 'nabízejí'} ${vycet(vegan, 4)}.`]);
  return out;
}
