// Oblíbené podniky – jen v prohlížeči (localStorage), bez cookies a registrace.

export interface UlozenyPodnik {
  id: number;
  u: string; // URL
  n: string; // název
  a: string; // adresa
  f?: string; // náhled fotky
  c?: number; // čas uložení
}

const KLIC = 'nb-oblibene';

export function oblibene(): UlozenyPodnik[] {
  try {
    const v = JSON.parse(localStorage.getItem(KLIC) ?? '[]');
    return Array.isArray(v) ? v : [];
  } catch {
    return [];
  }
}

function uloz(data: UlozenyPodnik[]) {
  try {
    localStorage.setItem(KLIC, JSON.stringify(data));
  } catch {
    /* soukromý režim – funkce prostě nebude trvalá */
  }
  window.dispatchEvent(new CustomEvent('nb-oblibene'));
}

export const jeOblibeny = (id: number) => oblibene().some((s) => s.id === id);

export function prepniOblibeny(s: UlozenyPodnik): boolean {
  const vse = oblibene();
  const i = vse.findIndex((x) => x.id === s.id);
  if (i >= 0) vse.splice(i, 1);
  else vse.unshift({ ...s, c: Date.now() });
  uloz(vse);
  return i < 0;
}

export const odeberOblibeny = (id: number) => uloz(oblibene().filter((s) => s.id !== id));

function obnovTlacitko(b: HTMLButtonElement) {
  const s = JSON.parse(b.dataset.oblibene!) as UlozenyPodnik;
  const on = jeOblibeny(s.id);
  b.setAttribute('aria-pressed', String(on));
  b.title = on ? 'Odebrat z oblíbených' : 'Přidat do oblíbených';
  const t = b.querySelector('[data-srdce-text]');
  if (t) t.textContent = on ? 'V oblíbených' : 'Do oblíbených';
}

export function inicializujSrdicka(root: ParentNode = document) {
  root.querySelectorAll<HTMLButtonElement>('[data-oblibene]').forEach((b) => {
    if (b.dataset.srdceOk) return;
    b.dataset.srdceOk = '1';
    obnovTlacitko(b);
    b.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      prepniOblibeny(JSON.parse(b.dataset.oblibene!));
      b.classList.remove('pulz');
      void b.offsetWidth;
      b.classList.add('pulz');
    });
  });
  window.addEventListener('nb-oblibene', () =>
    document.querySelectorAll<HTMLButtonElement>('[data-oblibene]').forEach(obnovTlacitko),
  );
}

export function inicializujPocitadlo() {
  const el = document.querySelector<HTMLElement>('[data-pocet-oblibenych]');
  if (!el) return;
  const obnov = () => {
    const n = oblibene().length;
    el.hidden = n === 0;
    el.textContent = String(n);
  };
  obnov();
  window.addEventListener('nb-oblibene', obnov);
  window.addEventListener('storage', obnov);
}
