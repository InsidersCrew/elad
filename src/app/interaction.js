/** Pointer → raycast → hover / select / focus. */
import * as THREE from 'three';

export function createInteraction(three, cityScene, { onHover, onSelectStructure, onSelectDistrict, onFocusDistrict }) {
  const raycaster = new THREE.Raycaster();
  const ndc = new THREE.Vector2();
  const el = three.renderer.domElement;
  let pending = null;
  let down = null;
  let lastTap = 0;

  function pick(x, y) {
    const r = el.getBoundingClientRect();
    ndc.set(((x - r.left) / r.width) * 2 - 1, -((y - r.top) / r.height) * 2 + 1);
    raycaster.setFromCamera(ndc, three.camera);
    const hits = raycaster.intersectObjects(cityScene.meshes, false);
    if (hits.length) return { type: 'structure', id: hits[0].object.userData.structureId, districtId: hits[0].object.userData.districtId, point: hits[0].point };
    const plates = raycaster.intersectObjects(cityScene.plates, false);
    if (plates.length) return { type: 'district', id: plates[0].object.userData.districtId, point: plates[0].point };
    return null;
  }

  el.addEventListener('pointermove', (e) => {
    pending = { x: e.clientX, y: e.clientY };
  });
  el.addEventListener('pointerleave', () => {
    pending = null;
    cityScene.setHover(null);
    onHover?.(null);
  });
  el.addEventListener('pointerdown', (e) => {
    down = { x: e.clientX, y: e.clientY, t: performance.now() };
  });
  el.addEventListener('pointerup', (e) => {
    if (!down) return;
    const moved = Math.hypot(e.clientX - down.x, e.clientY - down.y);
    const dt = performance.now() - down.t;
    down = null;
    if (moved > 6 || dt > 600) return;
    const hit = pick(e.clientX, e.clientY);
    const now = performance.now();
    const isDouble = now - lastTap < 350;
    lastTap = now;
    if (!hit) return;
    if (hit.type === 'structure') {
      onSelectStructure?.(hit.id);
      if (isDouble) onFocusDistrict?.(hit.districtId);
    } else {
      onSelectDistrict?.(hit.id);
      if (isDouble) onFocusDistrict?.(hit.id);
    }
  });

  three.onFrame(() => {
    if (!pending) return;
    const hit = pick(pending.x, pending.y);
    const id = hit?.type === 'structure' ? hit.id : null;
    cityScene.setHover(id);
    onHover?.(hit ? { ...hit, x: pending.x, y: pending.y } : null);
    pending = null;
  });
}
