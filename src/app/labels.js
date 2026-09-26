/** HTML labels projected onto the 3D districts (RTL text stays crisp). */
import * as THREE from 'three';
import { STATUS_COLOR, COLORS } from './lenses.js';

export function createLabels(container, three, layout, cityDef, { onClick }) {
  const items = new Map();
  const v = new THREE.Vector3();
  for (const def of cityDef.districts) {
    const L = layout.districts.get(def.id);
    const el = document.createElement('div');
    el.className = 'city-label' + (def.id === 'cityhall' ? ' cityhall' : '');
    el.innerHTML = `<span class="dot"></span><span class="he">${def.name_he}</span><span class="en">${def.name.replace(' District', '')}</span><span class="score num"></span>`;
    el.addEventListener('click', (e) => {
      e.stopPropagation();
      onClick?.(def.id);
    });
    container.appendChild(el);
    const anchor = L.center.clone().add(new THREE.Vector3(0, def.id === 'cityhall' ? 1.4 : 0.9, def.id === 'cityhall' ? 0 : 0));
    // Put ring labels on the outer edge of the plate so they don't cover buildings
    if (def.id !== 'cityhall') anchor.addScaledVector(L.normal, 7.2);
    items.set(def.id, { el, anchor, def, score: el.querySelector('.score'), dot: el.querySelector('.dot') });
  }

  const selected = document.createElement('div');
  selected.className = 'city-label selected';
  selected.style.display = 'none';
  container.appendChild(selected);
  let selectedAnchor = null;

  function setState(city) {
    for (const it of items.values()) {
      const d = city.byId.districts[it.def.id];
      if (!d) continue;
      it.score.textContent = Math.round(d.scores.health);
      const c = it.def.id === 'cityhall' ? COLORS.purple : STATUS_COLOR[d.scores.status] ?? COLORS.blue;
      it.el.style.setProperty('--dot', c);
    }
  }

  function setSelectedStructure(s, height) {
    if (!s) {
      selected.style.display = 'none';
      selectedAnchor = null;
      return;
    }
    selected.innerHTML = `<span class="dot"></span><span class="he">${s.def.name_he}</span><span class="score num">${Math.round(s.scores.health)}</span>`;
    selected.style.setProperty('--dot', STATUS_COLOR[s.scores.status] ?? COLORS.blue);
    selected.style.display = '';
    selectedAnchor = { world: s.world, height };
  }

  function highlight(id) {
    for (const it of items.values()) it.el.classList.toggle('selected', it.def.id === id);
  }

  function project(world, el) {
    v.copy(world).project(three.camera);
    const w = container.clientWidth;
    const h = container.clientHeight;
    if (v.z > 1 || v.z < -1) {
      el.classList.add('behind');
      return;
    }
    el.classList.remove('behind');
    const x = (v.x + 1) / 2 * w;
    const y = (1 - v.y) / 2 * h;
    el.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) translate(-50%, -100%)`;
  }

  let frame = 0;
  function update() {
    if (frame++ % 2) return; // 30 fps is plenty for labels
    const camPos = three.camera.position;
    for (const it of items.values()) {
      project(it.anchor, it.el);
      it.el.classList.toggle('far', camPos.distanceTo(it.anchor) > 150);
    }
    if (selectedAnchor) project(selectedAnchor.world.clone().add(new THREE.Vector3(0, selectedAnchor.height() + 1.2, 0)), selected);
  }

  return { setState, update, highlight, setSelectedStructure, items };
}
