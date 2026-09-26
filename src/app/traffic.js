/**
 * Traffic = flow of leads / students around the ring road.
 * Density on each road segment ∝ what leaves the previous district; particles
 * bunch up (queue) before an overloaded district; leaks spill off the road at
 * a district's exit; smoke rises from unprofitable structures.
 */
import * as THREE from 'three';
import { glowTexture } from './textures.js';

const UP = new THREE.Vector3(0, 1, 0);
const KIND_COLOR = {
  lead: new THREE.Color('#dff1ff'),
  student: new THREE.Color('#83cdff'),
  referral: new THREE.Color('#b39dff'),
  queue: new THREE.Color('#f5a623'),
};

export function createTraffic(three, layout) {
  const N = 1100;
  const SAMPLES = 1200;
  const points = layout.road.getSpacedPoints(SAMPLES);
  const sides = points.map((p, i) => {
    const t = i / SAMPLES;
    const tan = layout.road.getTangentAt(t);
    return new THREE.Vector3().crossVectors(tan, UP).normalize();
  });
  const glow = glowTexture();

  // ── Flow particles ───────────────────────────────────────────
  const pos = new Float32Array(N * 3);
  const col = new Float32Array(N * 3);
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
  const mat = new THREE.PointsMaterial({ size: 1.15, map: glow, transparent: true, opacity: 0.95, depthWrite: false, blending: THREE.AdditiveBlending, vertexColors: true, sizeAttenuation: true });
  const points3 = new THREE.Points(geo, mat);
  points3.frustumCulled = false;
  three.scene.add(points3);

  const particles = Array.from({ length: N }, () => ({ seg: -1, t: 0, u: 0, speed: 1, lane: 0, alive: false, kind: 'lead' }));
  const segments = []; // { i, from, to, tA, len, count, congestion, kind }

  // ── Leak / smoke particles ───────────────────────────────────
  const M = 600;
  const fxPos = new Float32Array(M * 3);
  const fxCol = new Float32Array(M * 3);
  const fxGeo = new THREE.BufferGeometry();
  fxGeo.setAttribute('position', new THREE.BufferAttribute(fxPos, 3));
  fxGeo.setAttribute('color', new THREE.BufferAttribute(fxCol, 3));
  const fxMat = new THREE.PointsMaterial({ size: 1.4, map: glow, transparent: true, opacity: 0.8, depthWrite: false, blending: THREE.NormalBlending, vertexColors: true });
  const fx = new THREE.Points(fxGeo, fxMat);
  fx.frustumCulled = false;
  three.scene.add(fx);
  const fxp = Array.from({ length: M }, () => ({ alive: false, life: 0, max: 1, p: new THREE.Vector3(), v: new THREE.Vector3(), c: new THREE.Color(), kind: 'leak' }));
  const emitters = []; // { p: Vector3, dir: Vector3, rate, kind, acc }

  function segmentAt(t) {
    return points[Math.floor(((t % 1) + 1) % 1 * SAMPLES) % SAMPLES];
  }

  /** Configure densities, congestion and emitters from a computed city. */
  function configure(city, cityScene) {
    segments.length = 0;
    const order = layout.order;
    const flows = order.map((id) => city.byId.districts[id]?.flow?.out ?? 0);
    const maxFlow = Math.max(...flows.map((f) => Math.sqrt(Math.max(0, f))), 1);
    let total = 0;
    order.forEach((id, i) => {
      const next = order[(i + 1) % order.length];
      const tA = layout.entranceT[id];
      const tB = layout.entranceT[next];
      const len = ((tB - tA) % 1 + 1) % 1 || 1;
      const nd = city.byId.districts[next];
      const load = nd?.flow?.load ?? 0;
      const congestion = nd?.scores.flags.overloaded ? Math.min(1, (load - 0.85) / 0.15 + 0.35) : 0;
      const density = Math.sqrt(Math.max(0, flows[i])) / maxFlow;
      const count = Math.round(8 + density * 110);
      const kind = i <= 1 ? 'lead' : id === 'referral' ? 'referral' : 'student';
      segments.push({ i, from: id, to: next, tA, len, count, congestion, kind, density });
      total += count;
    });
    // Assign particles to segments
    let p = 0;
    for (const seg of segments) {
      for (let k = 0; k < seg.count && p < N; k++, p++) {
        const pt = particles[p];
        pt.seg = seg.i;
        pt.alive = true;
        pt.kind = seg.kind;
        if (!(pt.u > 0 && pt.u < 1)) pt.u = Math.random();
        pt.speed = 0.7 + Math.random() * 0.6;
        pt.lane = (Math.random() - 0.5) * 2.2;
        const c = KIND_COLOR[pt.kind];
        col.set([c.r, c.g, c.b], p * 3);
      }
    }
    for (; p < N; p++) {
      particles[p].alive = false;
      pos.set([0, -50, 0], p * 3);
    }
    geo.attributes.color.needsUpdate = true;

    // Emitters: leaks at district exits, smoke on unprofitable roofs
    emitters.length = 0;
    const maxLeak = Math.max(...order.map((id) => Math.sqrt(Math.max(0, city.byId.districts[id]?.flow?.leak ?? 0))), 1);
    for (const id of order) {
      const d = city.byId.districts[id];
      const leak = d?.flow?.leak ?? 0;
      if (leak > 0.5) {
        const L = layout.districts.get(id);
        const t = layout.entranceT[id] + 0.012;
        const p0 = segmentAt(t).clone().setY(0.6);
        const dir = L.normal.clone().multiplyScalar(-1); // spill toward the centre, off the road
        emitters.push({ p: p0, dir, rate: 2 + (Math.sqrt(leak) / maxLeak) * 16, kind: 'leak', acc: 0 });
      }
    }
    for (const s of city.structures) {
      if (s.scores.flags.unprofitable && cityScene) {
        const st = cityScene.structures.get(s.def.id);
        if (!st) continue;
        emitters.push({ p: st.world.clone(), roof: st, dir: new THREE.Vector3(0, 1, 0), rate: 4, kind: 'smoke', acc: 0 });
      }
    }
  }

  const tmp = new THREE.Vector3();
  function update(dt) {
    // Flow
    for (let i = 0; i < N; i++) {
      const pt = particles[i];
      if (!pt.alive) continue;
      const seg = segments[pt.seg];
      let mult = 1;
      if (seg.congestion > 0 && pt.u > 0.7) {
        const q = (pt.u - 0.7) / 0.3;
        mult = Math.max(0.04, 1 - q * seg.congestion * 1.15);
      }
      pt.u += (dt * 0.02 * pt.speed * mult) / seg.len;
      if (pt.u >= 1) pt.u -= 1;
      const t = seg.tA + pt.u * seg.len;
      const idx = Math.floor(((t % 1) + 1) % 1 * SAMPLES) % SAMPLES;
      const p = points[idx];
      const side = sides[idx];
      tmp.copy(p).addScaledVector(side, pt.lane);
      pos[i * 3] = tmp.x;
      pos[i * 3 + 1] = 0.55 + (pt.kind === 'student' ? 0.15 : 0);
      pos[i * 3 + 2] = tmp.z;
      // Queue colouring: amber when crawling
      const c = mult < 0.5 ? KIND_COLOR.queue : KIND_COLOR[pt.kind];
      col[i * 3] = c.r;
      col[i * 3 + 1] = c.g;
      col[i * 3 + 2] = c.b;
    }
    geo.attributes.position.needsUpdate = true;
    geo.attributes.color.needsUpdate = true;

    // Effects
    for (const e of emitters) {
      e.acc += e.rate * dt;
      while (e.acc >= 1) {
        e.acc -= 1;
        const f = fxp.find((x) => !x.alive);
        if (!f) break;
        f.alive = true;
        f.kind = e.kind;
        f.life = 0;
        if (e.kind === 'leak') {
          f.max = 1.4 + Math.random() * 0.8;
          f.p.copy(e.p).add(new THREE.Vector3((Math.random() - 0.5) * 1.2, 0, (Math.random() - 0.5) * 1.2));
          f.v.copy(e.dir).multiplyScalar(2.5 + Math.random() * 2).add(new THREE.Vector3((Math.random() - 0.5) * 1.5, 1.2 + Math.random(), (Math.random() - 0.5) * 1.5));
          f.c.set('#e53935');
        } else {
          f.max = 2.2 + Math.random() * 1.2;
          const h = e.roof?.height ?? 4;
          f.p.copy(e.p).add(new THREE.Vector3((Math.random() - 0.5) * 1.4, h + 0.3, (Math.random() - 0.5) * 1.4));
          f.v.set((Math.random() - 0.5) * 0.6, 1.6 + Math.random() * 0.8, (Math.random() - 0.5) * 0.6);
          f.c.set('#9aa7bd');
        }
      }
    }
    for (let i = 0; i < M; i++) {
      const f = fxp[i];
      if (!f.alive) {
        fxPos[i * 3 + 1] = -50;
        continue;
      }
      f.life += dt;
      if (f.life >= f.max) {
        f.alive = false;
        fxPos[i * 3 + 1] = -50;
        continue;
      }
      const k = f.life / f.max;
      if (f.kind === 'leak') f.v.y -= 3.5 * dt;
      else f.v.x += Math.sin(f.life * 3) * 0.3 * dt;
      f.p.addScaledVector(f.v, dt);
      fxPos[i * 3] = f.p.x;
      fxPos[i * 3 + 1] = Math.max(0.05, f.p.y);
      fxPos[i * 3 + 2] = f.p.z;
      const fade = f.kind === 'leak' ? 1 - k : 0.7 * (1 - k);
      fxCol[i * 3] = f.c.r * fade;
      fxCol[i * 3 + 1] = f.c.g * fade;
      fxCol[i * 3 + 2] = f.c.b * fade;
    }
    fxGeo.attributes.position.needsUpdate = true;
    fxGeo.attributes.color.needsUpdate = true;
  }

  return { configure, update, points: points3, fx };
}
