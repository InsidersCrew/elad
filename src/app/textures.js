/**
 * Procedural facade textures: lit windows (density = health), cracks
 * (quality problems), tint (lens colour). Drawn once per state change.
 */
import * as THREE from 'three';

const cache = new Map();

function rng(seed) {
  let s = seed >>> 0 || 1;
  return () => {
    s ^= s << 13;
    s ^= s >>> 17;
    s ^= s << 5;
    return ((s >>> 0) % 10000) / 10000;
  };
}

function hash(str) {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) h = Math.imul(h ^ str.charCodeAt(i), 16777619);
  return h >>> 0;
}

/**
 * @param {{id:string, lit:number, cracks:number, tint:string, rows:number, dim:number}} p
 */
export function facadeTexture(p) {
  const key = `${p.id}|${p.lit.toFixed(2)}|${p.cracks.toFixed(2)}|${p.tint}|${p.rows}|${p.dim.toFixed(2)}`;
  if (cache.has(key)) return cache.get(key);
  const W = 128;
  const H = 64 * Math.max(2, Math.min(14, p.rows));
  const c = document.createElement('canvas');
  c.width = W;
  c.height = H;
  const ctx = c.getContext('2d');
  const rand = rng(hash(p.id));

  // Facade: deep navy with a hint of the tint, darker when dim
  const base = new THREE.Color('#0b2350').lerp(new THREE.Color(p.tint), 0.12).multiplyScalar(1 - p.dim * 0.55);
  ctx.fillStyle = `#${base.getHexString()}`;
  ctx.fillRect(0, 0, W, H);

  // Window grid
  const cols = 5;
  const rows = Math.round(H / 32);
  const cw = W / cols;
  const rh = H / rows;
  const tint = new THREE.Color(p.tint);
  for (let r = 0; r < rows; r++) {
    for (let col = 0; col < cols; col++) {
      const on = rand() < p.lit;
      const x = col * cw + cw * 0.22;
      const y = r * rh + rh * 0.22;
      const w = cw * 0.56;
      const h = rh * 0.5;
      if (on) {
        const warm = rand();
        const cc = tint.clone().lerp(new THREE.Color('#ffffff'), 0.35 + warm * 0.35);
        ctx.fillStyle = `rgba(${Math.round(cc.r * 255)},${Math.round(cc.g * 255)},${Math.round(cc.b * 255)},${(0.75 + rand() * 0.25) * (1 - p.dim * 0.5)})`;
      } else {
        ctx.fillStyle = 'rgba(2,10,28,0.85)';
      }
      ctx.fillRect(x, y, w, h);
    }
  }

  // Cracks: jagged dark lines with a faint light edge
  if (p.cracks > 0.02) {
    const n = Math.round(1 + p.cracks * 6);
    for (let i = 0; i < n; i++) {
      let x = rand() * W;
      let y = rand() * H * 0.6;
      ctx.beginPath();
      ctx.moveTo(x, y);
      const segs = 6 + Math.round(rand() * 8);
      for (let s = 0; s < segs; s++) {
        x += (rand() - 0.5) * 22;
        y += 6 + rand() * 18;
        ctx.lineTo(x, y);
      }
      ctx.strokeStyle = 'rgba(1,6,18,0.95)';
      ctx.lineWidth = 1.6 + p.cracks * 1.6;
      ctx.stroke();
      ctx.strokeStyle = 'rgba(180,200,230,0.18)';
      ctx.lineWidth = 0.6;
      ctx.stroke();
    }
  }

  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.anisotropy = 4;
  tex.needsUpdate = true;
  if (cache.size > 400) {
    const first = cache.keys().next().value;
    cache.get(first).dispose();
    cache.delete(first);
  }
  cache.set(key, tex);
  return tex;
}

/** Soft radial glow sprite (for halos, beacons, particles). */
let glowTex = null;
export function glowTexture() {
  if (glowTex) return glowTex;
  const c = document.createElement('canvas');
  c.width = c.height = 64;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
  g.addColorStop(0, 'rgba(255,255,255,1)');
  g.addColorStop(0.35, 'rgba(255,255,255,0.55)');
  g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 64, 64);
  glowTex = new THREE.CanvasTexture(c);
  glowTex.colorSpace = THREE.SRGBColorSpace;
  return glowTex;
}
