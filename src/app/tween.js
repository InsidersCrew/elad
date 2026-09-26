/** Minimal tween runner — INSIDERS easings (rise / settle), no dependencies. */
const active = new Set();

export const ease = {
  // cubic-bezier(0.16, 1, 0.3, 1) ≈ easeOutQuint-like "rise"
  rise: (t) => 1 - Math.pow(1 - t, 5),
  // cubic-bezier(0.25, 0.46, 0.45, 0.94) ≈ easeOutCubic "settle"
  settle: (t) => 1 - Math.pow(1 - t, 3),
  inOut: (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
  linear: (t) => t,
};

/**
 * @param {{duration?:number, delay?:number, ease?:(t:number)=>number, onUpdate:(k:number)=>void, onComplete?:()=>void, key?:any}} opts
 */
export function tween(opts) {
  const t = {
    start: performance.now() + (opts.delay ?? 0),
    duration: opts.duration ?? 600,
    ease: opts.ease ?? ease.settle,
    onUpdate: opts.onUpdate,
    onComplete: opts.onComplete,
    key: opts.key,
    done: false,
  };
  if (t.key !== undefined) for (const o of active) if (o.key === t.key) active.delete(o);
  active.add(t);
  return t;
}

export function cancelTween(t) {
  active.delete(t);
}

export function updateTweens(now) {
  for (const t of active) {
    if (now < t.start) continue;
    const k = Math.min(1, (now - t.start) / t.duration);
    t.onUpdate(t.ease(k));
    if (k >= 1) {
      active.delete(t);
      t.done = true;
      t.onComplete?.();
    }
  }
}

export function lerp(a, b, k) {
  return a + (b - a) * k;
}
