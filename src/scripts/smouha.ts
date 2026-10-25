// „Šmouha“ za myší: při rychlém pohybu kreslí do plátna tlustou broskvovou stopu, která rychle vybledne.
// Jen pro myš (ne dotyk) a jen když uživatel nemá zapnuté omezení animací.

const ZIVOT = 520; // ms, za jak dlouho bod zmizí
const PRAH = 0.45; // px/ms, od jaké rychlosti se kreslí
const MAX_SIRKA = 70;

interface Bod {
  x: number;
  y: number;
  w: number;
  t: number;
}

export function smouha(platno: HTMLCanvasElement, oblast: HTMLElement) {
  if (!matchMedia('(pointer: fine)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const ctx = platno.getContext('2d');
  if (!ctx) return;

  let body: Bod[] = [];
  let posledni: { x: number; y: number; t: number } | null = null;
  let bezi = false;
  let dpr = 1;

  const velikost = () => {
    dpr = Math.min(2, devicePixelRatio || 1);
    const r = oblast.getBoundingClientRect();
    platno.width = Math.round(r.width * dpr);
    platno.height = Math.round(r.height * dpr);
    platno.style.width = `${r.width}px`;
    platno.style.height = `${r.height}px`;
  };
  velikost();
  new ResizeObserver(velikost).observe(oblast);

  oblast.addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse') return;
    const r = oblast.getBoundingClientRect();
    const x = e.clientX - r.left;
    const y = e.clientY - r.top;
    const t = performance.now();
    if (posledni) {
      const dt = Math.max(1, t - posledni.t);
      const v = Math.hypot(x - posledni.x, y - posledni.y) / dt;
      if (v > PRAH) {
        // Šířka roste s rychlostí, plynule navazuje na předchozí bod
        const cil = Math.min(MAX_SIRKA, 10 + (v - PRAH) * 28);
        const pred = body.at(-1);
        const w = pred && t - pred.t < 60 ? pred.w + (cil - pred.w) * 0.35 : cil * 0.5;
        body.push({ x, y, w, t });
        if (!bezi) {
          bezi = true;
          requestAnimationFrame(kresli);
        }
      } else if (body.length && t - body.at(-1)!.t > 60) {
        body.push({ x: NaN, y: NaN, w: 0, t }); // přerušení stopy
      }
    }
    posledni = { x, y, t };
  });
  oblast.addEventListener('pointerleave', () => (posledni = null));

  function kresli() {
    const ted = performance.now();
    body = body.filter((b) => ted - b.t < ZIVOT);
    ctx!.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx!.clearRect(0, 0, platno.width, platno.height);
    ctx!.lineCap = 'round';
    ctx!.lineJoin = 'round';
    for (let i = 1; i < body.length; i++) {
      const a = body[i - 1];
      const b = body[i];
      if (Number.isNaN(a.x) || Number.isNaN(b.x)) continue;
      const k = 1 - (ted - b.t) / ZIVOT; // 1 → 0
      const g = ctx!.createLinearGradient(a.x, a.y, b.x, b.y);
      g.addColorStop(0, `rgba(255, 142, 95, ${0.45 * k})`);
      g.addColorStop(1, `rgba(255, 154, 152, ${0.55 * k})`);
      ctx!.strokeStyle = g;
      ctx!.lineWidth = Math.max(1, b.w * (0.35 + 0.65 * k));
      ctx!.beginPath();
      ctx!.moveTo(a.x, a.y);
      ctx!.lineTo(b.x, b.y);
      ctx!.stroke();
    }
    if (body.length) requestAnimationFrame(kresli);
    else {
      bezi = false;
      ctx!.clearRect(0, 0, platno.width, platno.height);
    }
  }
}
