/**
 * City layout: the value chain is a loop. Nine districts sit on an elliptical
 * ring road (the gate at the front-left, the referral district closing the
 * loop back to it), City Hall on a plateau at the centre.
 */
import * as THREE from 'three';

export const RING = { rx: 44, rz: 29 };
export const PLATE = { w: 17, d: 11.5, r: 1.6, h: 0.5, offset: 8.5 };
export const CITYHALL = { radius: 11, h: 0.9 };

const ORDER = ['acquisition', 'qualification', 'fitcall', 'onboarding', 'broker', 'monetization', 'success', 'growth', 'referral'];

/** Angle (radians) of the i-th ring district: 150° → −170°, clockwise from above. */
export function ringAngle(i) {
  return THREE.MathUtils.degToRad(150 - i * 40);
}

export function ringPoint(theta, scale = 1) {
  return new THREE.Vector3(RING.rx * scale * Math.cos(theta), 0, RING.rz * scale * Math.sin(theta));
}

/** Slots for up to 5 buildings inside a plate (local coordinates, x along the ring). */
const SLOTS = [
  [-5.2, -2.4], [0, -2.6], [5.2, -2.4],
  [-2.7, 2.6], [2.7, 2.6],
];

export function computeLayout(cityDef) {
  const districts = new Map();
  for (const d of cityDef.districts) {
    if (d.id === 'cityhall') {
      districts.set(d.id, { id: d.id, center: new THREE.Vector3(0, 0, 0), angle: 0, entrance: new THREE.Vector3(0, 0, 0), normal: new THREE.Vector3(0, 0, 1) });
      continue;
    }
    const i = ORDER.indexOf(d.id);
    const theta = ringAngle(i);
    const ring = ringPoint(theta);
    // Outward normal of the ellipse at theta
    const normal = new THREE.Vector3(Math.cos(theta) / RING.rx, 0, Math.sin(theta) / RING.rz).normalize();
    const center = ring.clone().add(normal.clone().multiplyScalar(PLATE.offset));
    // Tangent direction (so the plate's long side follows the ring)
    const tangent = new THREE.Vector3(-RING.rx * Math.sin(theta), 0, RING.rz * Math.cos(theta)).normalize();
    const rotationY = Math.atan2(tangent.x, tangent.z) - Math.PI / 2;
    districts.set(d.id, { id: d.id, index: i, theta, center, ring, normal, tangent, rotationY, entrance: ring.clone() });
  }

  // Structures: slot assignment inside their district plate
  const structures = new Map();
  const perDistrict = {};
  for (const s of cityDef.structures) {
    const list = (perDistrict[s.district_id] ??= []);
    const idx = list.length;
    list.push(s.id);
    const foot = s.footprint ?? 1;
    const w = 2.6 * Math.sqrt(foot);
    if (s.district_id === 'cityhall') {
      const positions = [[0, 0], [-6.2, 2.2], [6.2, 2.2], [0, -6.4]];
      const [x, z] = positions[idx] ?? [0, 0];
      structures.set(s.id, { id: s.id, districtId: s.district_id, local: new THREE.Vector3(x, 0, z), w: idx === 0 ? 4.2 : w, d: idx === 0 ? 4.2 : w * 0.9 });
      continue;
    }
    const [x, z] = SLOTS[idx] ?? [0, 0];
    structures.set(s.id, { id: s.id, districtId: s.district_id, local: new THREE.Vector3(x, 0, z), w, d: w * 0.85 });
  }

  // The ring road as a closed curve through the district entrances (in flow order)
  const pts = ORDER.map((id) => districts.get(id).ring.clone());
  const road = new THREE.CatmullRomCurve3(pts, true, 'centripetal', 0.5);
  const entranceT = {};
  const samples = road.getPoints(1200);
  for (const id of ORDER) {
    const e = districts.get(id).ring;
    let best = 0;
    let bd = Infinity;
    samples.forEach((p, i) => {
      const dd = p.distanceToSquared(e);
      if (dd < bd) {
        bd = dd;
        best = i;
      }
    });
    entranceT[id] = best / 1200;
  }

  return { districts, structures, road, order: ORDER, entranceT };
}

/** World-space position of a structure's base centre. */
export function structureWorld(layout, structureId) {
  const s = layout.structures.get(structureId);
  const d = layout.districts.get(s.districtId);
  const p = s.local.clone();
  if (d.rotationY !== undefined) p.applyAxisAngle(new THREE.Vector3(0, 1, 0), d.rotationY);
  return p.add(d.center);
}
