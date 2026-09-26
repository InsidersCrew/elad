/**
 * The city itself: district plates, structures (buildings), ring road, spokes
 * to City Hall, beacons, congestion rings — and applyState() which re-shapes
 * everything from a computed city state + lens.
 */
import * as THREE from 'three';
import { PLATE, CITYHALL, structureWorld } from './layout.js';
import { facadeTexture, glowTexture } from './textures.js';
import { lensStyle, districtStyle, COLORS } from './lenses.js';
import { tween, ease } from './tween.js';

const UP = new THREE.Vector3(0, 1, 0);

function roundedRectShape(w, d, r) {
  const s = new THREE.Shape();
  const x = -w / 2;
  const y = -d / 2;
  s.moveTo(x + r, y);
  s.lineTo(x + w - r, y);
  s.quadraticCurveTo(x + w, y, x + w, y + r);
  s.lineTo(x + w, y + d - r);
  s.quadraticCurveTo(x + w, y + d, x + w - r, y + d);
  s.lineTo(x + r, y + d);
  s.quadraticCurveTo(x, y + d, x, y + d - r);
  s.lineTo(x, y + r);
  s.quadraticCurveTo(x, y, x + r, y);
  return s;
}

function outlinePoints(shape, y) {
  return shape.getPoints(24).map((p) => new THREE.Vector3(p.x, y, -p.y));
}

function ribbonGeometry(curve, width, samples = 420, y = 0.06) {
  const pos = [];
  const idx = [];
  const uv = [];
  for (let i = 0; i <= samples; i++) {
    const t = i / samples;
    const p = curve.getPointAt(t);
    const tan = curve.getTangentAt(t);
    const side = new THREE.Vector3().crossVectors(tan, UP).normalize().multiplyScalar(width / 2);
    pos.push(p.x - side.x, y, p.z - side.z, p.x + side.x, y, p.z + side.z);
    uv.push(0, t * 40, 1, t * 40);
    if (i < samples) {
      const a = i * 2;
      idx.push(a, a + 1, a + 2, a + 1, a + 3, a + 2);
    }
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
  g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  g.setIndex(idx);
  g.computeVertexNormals();
  return g;
}

function offsetLoop(curve, offset, samples = 420, y = 0.09) {
  const pts = [];
  for (let i = 0; i <= samples; i++) {
    const t = i / samples;
    const p = curve.getPointAt(t);
    const tan = curve.getTangentAt(t);
    const side = new THREE.Vector3().crossVectors(tan, UP).normalize().multiplyScalar(offset);
    pts.push(new THREE.Vector3(p.x + side.x, y, p.z + side.z));
  }
  return pts;
}

export function buildCityScene(three, layout, cityDef) {
  const { scene } = three;
  const root = new THREE.Group();
  scene.add(root);

  // ── Road ring, edge lights, spokes ───────────────────────────
  const road = new THREE.Mesh(ribbonGeometry(layout.road, 3.4), new THREE.MeshStandardMaterial({ color: 0x0a2150, roughness: 0.95, metalness: 0.05 }));
  road.receiveShadow = true;
  root.add(road);
  for (const off of [-1.85, 1.85]) {
    const line = new THREE.Line(new THREE.BufferGeometry().setFromPoints(offsetLoop(layout.road, off)), new THREE.LineBasicMaterial({ color: 0x2f7be0, transparent: true, opacity: 0.4 }));
    root.add(line);
  }
  const center = new THREE.Line(new THREE.BufferGeometry().setFromPoints(offsetLoop(layout.road, 0, 420, 0.08)), new THREE.LineDashedMaterial({ color: 0x3d8bff, dashSize: 1.4, gapSize: 1.6, transparent: true, opacity: 0.25 }));
  center.computeLineDistances();
  root.add(center);

  const spokes = [];
  for (const id of layout.order) {
    const d = layout.districts.get(id);
    const from = d.ring.clone().setY(0.12);
    const to = from.clone().normalize().multiplyScalar(CITYHALL.radius + 0.4).setY(0.12);
    const geo = new THREE.BufferGeometry().setFromPoints([from, to]);
    const line = new THREE.Line(geo, new THREE.LineDashedMaterial({ color: 0x83cdff, dashSize: 0.9, gapSize: 1.3, transparent: true, opacity: 0.16 }));
    line.computeLineDistances();
    root.add(line);
    spokes.push(line);
  }

  // ── District plates ──────────────────────────────────────────
  const districts = new Map();
  const plateMat = new THREE.MeshStandardMaterial({ color: 0x0c2657, roughness: 0.85, metalness: 0.15 });
  for (const def of cityDef.districts) {
    const L = layout.districts.get(def.id);
    const g = new THREE.Group();
    g.position.copy(L.center);
    if (L.rotationY !== undefined) g.rotation.y = L.rotationY;
    root.add(g);

    let plate;
    let outline;
    if (def.id === 'cityhall') {
      plate = new THREE.Mesh(new THREE.CylinderGeometry(CITYHALL.radius, CITYHALL.radius + 0.6, CITYHALL.h, 64), plateMat.clone());
      plate.position.y = CITYHALL.h / 2;
      outline = [];
      for (let i = 0; i <= 64; i++) {
        const a = (i / 64) * Math.PI * 2;
        outline.push(new THREE.Vector3(Math.cos(a) * CITYHALL.radius, CITYHALL.h + 0.02, Math.sin(a) * CITYHALL.radius));
      }
    } else {
      const shape = roundedRectShape(PLATE.w, PLATE.d, PLATE.r);
      plate = new THREE.Mesh(new THREE.ExtrudeGeometry(shape, { depth: PLATE.h, bevelEnabled: false }), plateMat.clone());
      plate.rotation.x = -Math.PI / 2;
      outline = outlinePoints(shape, PLATE.h + 0.02);
    }
    plate.receiveShadow = true;
    plate.castShadow = false;
    plate.userData = { districtId: def.id };
    g.add(plate);

    const rim = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(outline), new THREE.LineBasicMaterial({ color: 0x00c28a, transparent: true, opacity: 0.9 }));
    g.add(rim);

    // Halo: additive glow under the plate
    const haloGeo = def.id === 'cityhall' ? new THREE.CircleGeometry(CITYHALL.radius + 2.2, 64) : new THREE.ShapeGeometry(roundedRectShape(PLATE.w + 3.2, PLATE.d + 3.2, PLATE.r + 1.2));
    const halo = new THREE.Mesh(haloGeo, new THREE.MeshBasicMaterial({ color: 0x00c28a, transparent: true, opacity: 0.16, blending: THREE.AdditiveBlending, depthWrite: false }));
    halo.rotation.x = -Math.PI / 2;
    halo.position.y = 0.03;
    g.add(halo);

    // Congestion ring at the entrance (on the road), pulsing amber when overloaded
    let ring = null;
    if (def.id !== 'cityhall') {
      ring = new THREE.Mesh(new THREE.RingGeometry(2.2, 3.0, 48), new THREE.MeshBasicMaterial({ color: 0xf5a623, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide }));
      ring.rotation.x = -Math.PI / 2;
      ring.position.copy(L.ring).setY(0.12);
      root.add(ring);
    }
    districts.set(def.id, { def, group: g, plate, rim, halo, ring, layout: L, overloaded: false });
  }

  // ── Structures ───────────────────────────────────────────────
  const structures = new Map();
  const meshes = [];
  const unitBox = new THREE.BoxGeometry(1, 1, 1);
  unitBox.translate(0, 0.5, 0);
  const topMat = new THREE.MeshStandardMaterial({ color: 0x0a1e46, roughness: 0.7, metalness: 0.2 });
  const glow = glowTexture();
  cityDef.structures.forEach((def, i) => {
    const L = layout.structures.get(def.id);
    const D = districts.get(def.district_id);
    const side = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.6, metalness: 0.15, emissive: 0xffffff, emissiveIntensity: 0.5 });
    const mesh = new THREE.Mesh(unitBox, [side, side, topMat, topMat, side, side]);
    mesh.position.copy(L.local).setY(def.district_id === 'cityhall' ? CITYHALL.h : PLATE.h);
    mesh.scale.set(L.w, 0.05, L.d);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    mesh.userData = { structureId: def.id, districtId: def.district_id };
    D.group.add(mesh);
    meshes.push(mesh);

    // Base halo sprite
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0x00c28a, transparent: true, opacity: 0.3, blending: THREE.AdditiveBlending, depthWrite: false }));
    halo.position.copy(mesh.position).add(new THREE.Vector3(0, 0.25, 0));
    halo.scale.set(L.w * 2.6, L.w * 2.6, 1);
    D.group.add(halo);

    // Roof beacon
    const beacon = new THREE.Mesh(new THREE.SphereGeometry(0.42, 16, 12), new THREE.MeshBasicMaterial({ color: 0xe53935, transparent: true, opacity: 0 }));
    beacon.visible = false;
    D.group.add(beacon);
    const beaconGlow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0xe53935, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false }));
    beaconGlow.scale.set(3.5, 3.5, 1);
    beaconGlow.visible = false;
    D.group.add(beaconGlow);

    // Selection ring
    const selRing = new THREE.Mesh(new THREE.RingGeometry(L.w * 0.75, L.w * 0.75 + 0.35, 48), new THREE.MeshBasicMaterial({ color: 0x83cdff, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
    selRing.rotation.x = -Math.PI / 2;
    selRing.position.copy(mesh.position).add(new THREE.Vector3(0, 0.05, 0));
    D.group.add(selRing);

    structures.set(def.id, { def, mesh, side, halo, beacon, beaconGlow, selRing, layout: L, index: i, height: 0.05, style: null, beaconOn: false, world: structureWorld(layout, def.id) });
  });

  const state = { hovered: null, selected: null, selectedDistrict: null, first: true, lens: 'health', time: 0 };

  function heightFor(s, cs) {
    const base = s.def.district_id === 'cityhall' && s.index === cityDef.structures.findIndex((d) => d.id === 'mayor_office') ? 4 : 2.2;
    const scale = s.def.id === 'mayor_office' ? 14 : 10;
    return base + (cs.scores.size / 100) * scale;
  }

  /** Re-shape the whole city from a computed state. */
  function applyState(cityState, lensId, { animate = true } = {}) {
    state.lens = lensId;
    const stagger = state.first ? 28 : 0;
    for (const s of structures.values()) {
      const cs = cityState.byId.structures[s.def.id];
      if (!cs) continue;
      const style = lensStyle(lensId, cs, cityState);
      s.style = style;
      const h = heightFor(s, cs);
      const rows = Math.round(h / 1.1);
      const tex = facadeTexture({ id: s.def.id, lit: 0.22 + (cs.scores.health / 100) * 0.7, cracks: cs.scores.visual.cracks, tint: style.color, rows, dim: style.dim });
      s.side.map = tex;
      s.side.emissiveMap = tex;
      s.side.needsUpdate = true;
      const targetInt = 0.25 + style.intensity * 0.85;
      s.baseIntensity = targetInt;
      s.side.emissiveIntensity = s.def.id === state.hovered ? targetInt * 1.5 : targetInt;
      s.halo.material.color.set(style.color);
      s.halo.material.opacity = style.dim > 0.4 ? 0.04 : 0.12 + style.intensity * 0.22;
      const showBeacon = cs.scores.visual.beacon > 0 || (lensId === 'bottleneck' && cityState.mayor.primaryBottleneck?.def.id === s.def.id) || cs.scores.flags.overloaded;
      s.beaconOn = showBeacon;
      s.beaconKind = cs.scores.flags.critical || lensId === 'bottleneck' ? 'red' : 'amber';
      s.beacon.material.color.set(s.beaconKind === 'red' ? 0xe53935 : 0xf5a623);
      s.beaconGlow.material.color.set(s.beaconKind === 'red' ? 0xe53935 : 0xf5a623);
      s.beacon.visible = s.beaconGlow.visible = showBeacon;
      const from = s.height;
      if (animate) {
        tween({
          key: 'h:' + s.def.id, duration: state.first ? 1100 : 800, delay: state.first ? s.index * stagger : 0, ease: state.first ? ease.rise : ease.settle,
          onUpdate: (k) => setHeight(s, from + (h - from) * k),
        });
      } else setHeight(s, h);
      s.targetHeight = h;
    }
    for (const d of districts.values()) {
      const cd = cityState.byId.districts[d.def.id];
      if (!cd) continue;
      const st = districtStyle(lensId, cd, cityState);
      const c = new THREE.Color(st.color);
      d.rim.material.color.copy(c);
      d.halo.material.color.copy(c);
      d.halo.material.opacity = d.def.id === 'cityhall' ? 0.2 : 0.1 + (1 - cd.scores.health / 100) * 0.16;
      d.overloaded = !!cd.scores.flags.overloaded;
      if (d.ring) d.ring.material.opacity = d.overloaded ? 0.35 : 0;
    }
    state.first = false;
  }

  function setHeight(s, h) {
    s.height = h;
    s.mesh.scale.y = h;
    s.beacon.position.copy(s.mesh.position).add(new THREE.Vector3(0, h + 0.5, 0));
    s.beaconGlow.position.copy(s.beacon.position);
  }

  function setHover(id) {
    if (state.hovered === id) return;
    if (state.hovered) {
      const p = structures.get(state.hovered);
      if (p) p.side.emissiveIntensity = p.baseIntensity ?? 0.5;
    }
    state.hovered = id;
    if (id) {
      const s = structures.get(id);
      if (s) s.side.emissiveIntensity = (s.baseIntensity ?? 0.5) * 1.6;
    }
    three.renderer.domElement.style.cursor = id ? 'pointer' : '';
  }

  function setSelected(id) {
    for (const s of structures.values()) s.selRing.material.opacity = s.def.id === id ? 0.85 : 0;
    state.selected = id;
  }

  function tick(dt, now) {
    state.time += dt;
    const pulse = 0.5 + 0.5 * Math.sin(state.time * 3.2);
    for (const s of structures.values()) {
      if (s.beaconOn) {
        s.beacon.material.opacity = 0.55 + pulse * 0.45;
        s.beaconGlow.material.opacity = 0.25 + pulse * 0.5;
        const sc = 3 + pulse * 1.6;
        s.beaconGlow.scale.set(sc, sc, 1);
      }
      if (state.selected === s.def.id) s.selRing.material.opacity = 0.55 + pulse * 0.4;
    }
    for (const d of districts.values()) {
      if (d.ring && d.overloaded) {
        d.ring.material.opacity = 0.25 + pulse * 0.4;
        const sc = 1 + pulse * 0.25;
        d.ring.scale.set(sc, sc, 1);
      }
    }
    const dash = (now / 60) % 100;
    spokes.forEach((l) => { l.material.dashOffset = -dash * 0.02; });
  }

  return { root, districts, structures, meshes, plates: [...districts.values()].map((d) => d.plate), applyState, setHover, setSelected, tick, state };
}

export { COLORS };
