// Hover efekt tlačítek: při najetí myší z tlačítka spadne pár drobků (jako z croissantu).
// Jen pro myš a jen bez „omezit animace“. Jedno tlačítko nejvýš jednou za 700 ms.

const VYBER = '.btn, .srch__btn, .srch__geo, .odber__btn, .vypis__okoli, .slider__sip';
const BARVY = ['#d9a066', '#c98a4b', '#e8b77a', '#b8763d', '#f2cf9b'];
const posledni = new WeakMap<Element, number>();

function vysyp(el: HTMLElement) {
  const ted = performance.now();
  if (ted - (posledni.get(el) ?? 0) < 700) return;
  posledni.set(el, ted);
  const r = el.getBoundingClientRect();
  const pocet = 4 + Math.floor(Math.random() * 3);
  for (let i = 0; i < pocet; i++) {
    const d = document.createElement('span');
    d.className = 'drobek';
    const vel = 2.5 + Math.random() * 3.5;
    d.style.width = `${vel}px`;
    d.style.height = `${vel * (0.7 + Math.random() * 0.5)}px`;
    d.style.left = `${r.left + r.width * (0.15 + Math.random() * 0.7)}px`;
    d.style.top = `${r.bottom - 3}px`;
    d.style.background = BARVY[Math.floor(Math.random() * BARVY.length)];
    d.style.borderRadius = `${40 + Math.random() * 30}% ${30 + Math.random() * 40}% ${40 + Math.random() * 30}% ${30 + Math.random() * 40}%`;
    d.style.setProperty('--dx', `${(Math.random() - 0.5) * 22}px`);
    d.style.setProperty('--dy', `${22 + Math.random() * 26}px`);
    d.style.setProperty('--rot', `${(Math.random() - 0.5) * 360}deg`);
    d.style.animationDuration = `${550 + Math.random() * 350}ms`;
    d.style.animationDelay = `${Math.random() * 120}ms`;
    document.body.appendChild(d);
    d.addEventListener('animationend', () => d.remove());
  }
}

export function inicializujDrobky() {
  if (!matchMedia('(hover: hover) and (pointer: fine)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.addEventListener(
    'pointerover',
    (e) => {
      if ((e as PointerEvent).pointerType !== 'mouse') return;
      const el = (e.target as Element | null)?.closest?.(VYBER) as HTMLElement | null;
      if (!el || (el as HTMLButtonElement).disabled) return;
      const odkud = (e as PointerEvent).relatedTarget as Node | null;
      if (odkud && el.contains(odkud)) return; // pohyb uvnitř tlačítka
      vysyp(el);
    },
    { passive: true },
  );
}
