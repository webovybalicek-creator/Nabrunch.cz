// Časté dotazy k podniku – skládají se jen z faktů v datech (žádné vymyšlené texty).
// Když údaj chybí, otázka se vynechá. Používá detail podniku (viditelná sekce + FAQPage)
// a /llms.txt (přehled pro AI vyhledávače).
import { mesto, vOkoli, kmText, tydenPodniku, type Podnik } from './data';
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

  // Rezervace
  const r = (p.rezervace ?? '').trim();
  if (r) {
    const odpoved = /^https?:/i.test(r) || /online/i.test(r)
      ? 'Ano, stůl si můžeš rezervovat online.'
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
