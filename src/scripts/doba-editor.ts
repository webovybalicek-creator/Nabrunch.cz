// Editor otevírací doby po dnech (správa kavárny, přidání nové kavárny) ↔ formát OpenStreetMap „Mo-Fr 08:00-18:00; Sa 09:00-14:00“
export type Interval = [number, number];
const DNY = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
const OSM = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const hm = (m: number) => `${String(Math.floor(m / 60) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const naMin = (v: string) => {
  const m = v.match(/^(\d{1,2}):(\d{2})$/);
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
};

export function zOsm(s: string): Interval[][] {
  const w: Interval[][] = [[], [], [], [], [], [], []];
  for (const cast of s.split(';')) {
    const m = cast.trim().match(/^([A-Za-z,\- ]+)\s+([\d:,\- ]+)$/);
    if (!m) continue;
    const casy = m[2].split(',').map((x) => x.trim().split('-').map(naMin)).filter((x) => x[0] != null && x[1] != null) as Interval[];
    for (const r of m[1].split(',')) {
      const [a, b] = r.trim().split('-').map((d) => OSM.indexOf(d.trim()));
      if (a < 0) continue;
      for (let i = a; i <= (b >= 0 ? b : a); i++) w[i] = casy;
    }
  }
  return w;
}

export function doOsm(w: Interval[][]): string {
  const klic = w.map((d) => d.map(([a, b]) => `${hm(a)}-${hm(b)}`).join(','));
  const casti: string[] = [];
  let i = 0;
  while (i < 7) {
    let j = i;
    while (j + 1 < 7 && klic[j + 1] === klic[i]) j++;
    if (klic[i]) casti.push(`${OSM[i]}${j > i ? `-${OSM[j]}` : ''} ${klic[i]}`);
    i = j + 1;
  }
  return casti.join('; ');
}

/** Vykreslí editor do tabulky a vrátí funkci, která přečte zadanou dobu (formát OSM, '' = nevyplněno) */
export function editorDoby(tabulka: HTMLTableElement, tyden: Interval[][] = [[], [], [], [], [], [], []]): () => string {
  tabulka.innerHTML = DNY.map((den, i) => {
    const d = tyden[i] ?? [];
    const c = (n: number, j: 0 | 1) => (d[n] ? hm(d[n][j]) : '');
    return `<tr><th>${den}</th><td><label><input type="checkbox" data-zavreno ${d.length ? '' : 'checked'}> zavřeno</label></td>
      <td class="casy"><input type="time" value="${c(0, 0)}" aria-label="${den} od"> – <input type="time" value="${c(0, 1)}" aria-label="${den} do">
      <span class="pauza"><input type="time" value="${c(1, 0)}" aria-label="${den} od (odpoledne)"> – <input type="time" value="${c(1, 1)}" aria-label="${den} do (odpoledne)"></span></td></tr>`;
  }).join('');
  const prepni = (tr: HTMLTableRowElement) => {
    const z = tr.querySelector<HTMLInputElement>('[data-zavreno]')!.checked;
    tr.querySelectorAll<HTMLInputElement>('input[type=time]').forEach((x) => (x.disabled = z));
  };
  tabulka.querySelectorAll('tr').forEach((tr) => {
    prepni(tr);
    tr.querySelector('[data-zavreno]')!.addEventListener('change', () => prepni(tr));
  });
  return () => {
    const w: Interval[][] = [];
    for (const tr of tabulka.querySelectorAll('tr')) {
      if (tr.querySelector<HTMLInputElement>('[data-zavreno]')!.checked) {
        w.push([]);
        continue;
      }
      const c = [...tr.querySelectorAll<HTMLInputElement>('input[type=time]')].map((x) => naMin(x.value));
      const den: Interval[] = [];
      for (const k of [0, 2]) if (c[k] != null && c[k + 1] != null && c[k]! < c[k + 1]!) den.push([c[k]!, c[k + 1]!]);
      w.push(den);
    }
    return w.some((d) => d.length) ? doOsm(w) : '';
  };
}
