/**
 * INSIDERS CityOS — entry point.
 * Data → engine (buildCity) → 3D city + panels. Scenario overrides re-run the
 * engine and the whole city re-shapes.
 */
import * as THREE from 'three';
import cityDef from '../data/city.json';
import modelSeed from '../data/model.json';
import rules from '../data/rules.json';
import rootcauses from '../data/rootcauses.json';
import skills from '../data/skills.json';
import { buildCity } from './engine/index.js';
import { fmtValue } from './engine/format.js';
import { createScene } from './app/scene.js';
import { computeLayout } from './app/layout.js';
import { buildCityScene } from './app/city.js';
import { createTraffic } from './app/traffic.js';
import { createLabels } from './app/labels.js';
import { createInteraction } from './app/interaction.js';
import { createTopbar } from './app/ui/topbar.js';
import { createMayorPanel } from './app/ui/mayorPanel.js';
import { createDetailPanel } from './app/ui/detailPanel.js';
import { createScenarioDrawer } from './app/ui/scenarioDrawer.js';
import { createLegend } from './app/ui/legend.js';
import { openReports } from './app/ui/reportsModal.js';
import { openData } from './app/ui/dataModal.js';
import { openSkill } from './app/ui/skillModal.js';
import { closeModal } from './app/ui/modal.js';
import { h, statusChip } from './app/ui/dom.js';
import { toast } from './app/ui/toast.js';

// ── Persistence (per-viewer conveniences only) ─────────────────
const LS = { model: 'cityos.model.v1', overrides: 'cityos.overrides.v1', scenarios: 'cityos.scenarios.v1', lens: 'cityos.lens.v1' };
function lsGet(k, d) {
  try {
    const v = localStorage.getItem(k);
    return v ? JSON.parse(v) : d;
  } catch {
    return d;
  }
}
function lsSet(k, v) {
  try {
    if (v === null || v === undefined) localStorage.removeItem(k);
    else localStorage.setItem(k, JSON.stringify(v));
  } catch { /* private mode etc. */ }
}

// ── Store ──────────────────────────────────────────────────────
function createStore() {
  const listeners = {};
  const savedModel = lsGet(LS.model, null);
  const data = { city: cityDef, model: savedModel && savedModel.inputs && savedModel.period ? savedModel : structuredClone(modelSeed), rules, rootcauses, skills };
  const s = {
    data,
    lens: lsGet(LS.lens, 'health'),
    overrides: lsGet(LS.overrides, {}) || {},
    savedScenarios: lsGet(LS.scenarios, []) || [],
    selected: null,
    base: null,
    current: null,
  };
  s.on = (ev, cb) => {
    (listeners[ev] ??= []).push(cb);
    return () => listeners[ev].splice(listeners[ev].indexOf(cb), 1);
  };
  s.emit = (ev, p) => (listeners[ev] ?? []).forEach((cb) => cb(p));
  s.hasScenario = () => Object.keys(s.overrides).length > 0;
  s.recompute = () => {
    s.base = buildCity(data);
    s.current = s.hasScenario() ? buildCity(data, s.overrides) : s.base;
    s.emit('state', s);
  };
  s.setOverrides = (changes, { merge = true } = {}) => {
    const next = merge ? { ...s.overrides, ...changes } : { ...changes };
    for (const k of Object.keys(next)) {
      if (!(k in s.base.baseInputs) || typeof next[k] !== 'number' || Math.abs(next[k] - s.base.baseInputs[k]) < 1e-9) delete next[k];
    }
    s.overrides = next;
    lsSet(LS.overrides, next);
    s.recompute();
  };
  s.resetScenario = () => s.setOverrides({}, { merge: false });
  s.setLens = (id) => {
    s.lens = id;
    lsSet(LS.lens, id);
    s.emit('lens', id);
  };
  s.select = (sel) => {
    s.selected = sel;
    s.emit('select', sel);
  };
  s.setInputs = (inputs) => {
    data.model = { ...data.model, inputs: { ...data.model.inputs, ...inputs }, meta: { ...data.model.meta, source: 'edited', updated: new Date().toISOString().slice(0, 10) } };
    lsSet(LS.model, data.model);
    s.recompute();
  };
  s.updateModel = (m, source = 'edited') => {
    data.model = { ...m, meta: { ...(m.meta ?? {}), source, updated: new Date().toISOString().slice(0, 10) } };
    lsSet(LS.model, data.model);
    s.recompute();
  };
  s.replaceModel = (m) => {
    data.model = { ...structuredClone(modelSeed), ...m, meta: { ...(m.meta ?? {}), source: 'imported', updated: new Date().toISOString().slice(0, 10) } };
    lsSet(LS.model, data.model);
    s.recompute();
  };
  s.resetData = () => {
    data.model = structuredClone(modelSeed);
    lsSet(LS.model, null);
    s.overrides = {};
    lsSet(LS.overrides, null);
    s.recompute();
  };
  s.saveScenario = (name) => {
    s.savedScenarios = [...s.savedScenarios, { name, changes: { ...s.overrides }, at: Date.now() }].slice(-12);
    lsSet(LS.scenarios, s.savedScenarios);
    s.emit('state', s);
  };
  s.deleteScenario = (i) => {
    s.savedScenarios = s.savedScenarios.filter((_, j) => j !== i);
    lsSet(LS.scenarios, s.savedScenarios);
    s.emit('state', s);
  };
  s.recompute();
  return s;
}

// ── Boot ───────────────────────────────────────────────────────
const store = createStore();
const canvas = document.getElementById('scene');
const three = createScene(canvas);
const layout = computeLayout(cityDef);
const cityScene = buildCityScene(three, layout, cityDef);
const traffic = createTraffic(three, layout);
const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

function focusDistrict(id) {
  const L = layout.districts.get(id);
  if (!L) return;
  three.flyTo(L.center.clone(), id === 'cityhall' ? 52 : 46, 1100);
}

// ── UI shell: which panels are open ────────────────────────────
const panels = { mayor: document.getElementById('mayor'), detail: document.getElementById('detail'), scenario: document.getElementById('scenario') };
const ui = {
  isOpen: (name) => !panels[name].hidden,
  open(name, payload) {
    if (name === 'reports') return openReports(store, payload ?? 'daily');
    if (name === 'data') return openData(store);
    if (name === 'skill') return openSkill(store, payload ?? {});
    if (isMobile()) for (const k of Object.keys(panels)) if (k !== name) panels[k].hidden = true;
    panels[name].hidden = false;
    if (name === 'scenario') scenarioDrawer.build();
    store.emit('ui', { name, open: true });
  },
  close(name) {
    panels[name].hidden = true;
    if (name === 'detail') store.selected = null;
    store.emit('ui', { name, open: false });
  },
  toggle(name) {
    this.isOpen(name) ? this.close(name) : this.open(name);
  },
  closeAll() {
    closeModal();
    for (const k of Object.keys(panels)) panels[k].hidden = true;
    store.select(null);
    store.emit('ui', { name: 'all', open: false });
  },
  selectStructure(id) {
    store.select({ type: 'structure', id });
    const s = cityScene.structures.get(id);
    if (s) three.flyTo(s.world.clone().add(new THREE.Vector3(0, Math.min(6, s.height * 0.4), 0)), 44, 900, 0.62);
  },
};

const labels = createLabels(document.getElementById('labels'), three, layout, cityDef, {
  onClick: (id) => {
    store.select({ type: 'district', id });
    focusDistrict(id);
  },
});
const tooltip = document.getElementById('tooltip');
createInteraction(three, cityScene, {
  onHover: (hit) => {
    if (!hit || hit.type !== 'structure') {
      tooltip.hidden = true;
      return;
    }
    const s = store.current.byId.structures[hit.id];
    if (!s) return;
    const top = s.metrics[0];
    tooltip.replaceChildren(
      h('div', { class: 't' }, s.def.name_he),
      h('div', { class: 'en' }, `${s.def.name} · ${store.current.byId.districts[s.def.district_id].def.name_he}`),
      h('div', { class: 'r' }, statusChip(s.scores.status, `Health ${Math.round(s.scores.health)}`), h('span', { class: 'k' }, `Bottleneck ${Math.round(s.scores.bottleneck)}`)),
      top ? h('div', { class: 'r' }, h('span', { class: 'k' }, top.name), h('span', { class: 'v' }, fmtValue(top.value, top.fmt))) : null,
      s.rootCauses[0] ? h('div', { class: 'r' }, h('span', { class: 'k' }, '↳ ' + s.rootCauses[0].title)) : null,
    );
    tooltip.hidden = false;
    const x = Math.min(window.innerWidth - 140, Math.max(140, hit.x));
    tooltip.style.left = x + 'px';
    tooltip.style.top = hit.y + 'px';
  },
  onSelectStructure: (id) => {
    store.select({ type: 'structure', id });
  },
  onSelectDistrict: (id) => {
    store.select({ type: 'district', id });
  },
  onFocusDistrict: focusDistrict,
});

const topbar = createTopbar(document.getElementById('topbar'), store, ui, three);
createMayorPanel(panels.mayor, store, ui);
createDetailPanel(panels.detail, store, ui, { focusDistrict });
const scenarioDrawer = createScenarioDrawer(panels.scenario, store, ui);
createLegend(document.getElementById('legend'), store);

// ── Wire engine → 3D ───────────────────────────────────────────
function applyToScene(animate = true) {
  cityScene.applyState(store.current, store.lens, { animate });
  traffic.configure(store.current, cityScene);
  labels.setState(store.current);
  const sel = store.selected;
  if (sel?.type === 'structure') {
    const s = store.current.byId.structures[sel.id];
    const m = cityScene.structures.get(sel.id);
    labels.setSelectedStructure(s ? { ...s, world: m.world } : null, () => m.height);
  }
}
store.on('state', () => applyToScene(true));
store.on('lens', () => applyToScene(false));
store.on('select', (sel) => {
  cityScene.setSelected(sel?.type === 'structure' ? sel.id : null);
  labels.highlight(sel?.type === 'district' ? sel.id : sel?.type === 'structure' ? store.current.byId.structures[sel.id]?.def.district_id : null);
  if (sel?.type === 'structure') {
    const s = store.current.byId.structures[sel.id];
    const m = cityScene.structures.get(sel.id);
    labels.setSelectedStructure({ ...s, world: m.world }, () => m.height);
  } else labels.setSelectedStructure(null);
  if (sel) ui.open('detail');
  else panels.detail.hidden = true;
  store.emit('ui', { name: 'detail', open: !!sel });
});
three.onFrame((dt, now) => {
  cityScene.tick(dt, now);
  traffic.update(dt);
  labels.update();
});

// Initial state
if (isMobile()) panels.mayor.hidden = true;
if (store.hasScenario()) toast('תרחיש שמור נטען — לחצו "איפוס" בסימולציה כדי לחזור לבסיס');
applyToScene(true);

// Splash → city rises
const splash = document.getElementById('splash');
const t0 = performance.now();
document.fonts?.ready?.then?.(() => {}).catch?.(() => {});
setTimeout(() => {
  splash.classList.add('done');
  window.__cityos.ready = true;
}, Math.max(0, 1100 - (performance.now() - t0)));

// Keyboard: Esc closes, 1-6 lenses
document.addEventListener('keydown', (e) => {
  if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;
  if (e.key === 'Escape') {
    if (store.selected) store.select(null);
    else if (ui.isOpen('scenario')) ui.close('scenario');
  }
  const idx = ['1', '2', '3', '4', '5', '6'].indexOf(e.key);
  if (idx >= 0) store.setLens(['health', 'weak', 'bottleneck', 'profit', 'cash', 'quality'][idx]);
});

// Public API (also used by scripts/screenshot.mjs)
window.__cityos = {
  ready: false,
  store,
  three,
  cityScene,
  setLens: (id) => store.setLens(id),
  select: (id) => ui.selectStructure(id),
  selectDistrict: (id) => { store.select({ type: 'district', id }); focusDistrict(id); },
  openScenario: () => ui.open('scenario'),
  setScenario: (changes) => store.setOverrides(changes, { merge: false }),
  resetScenario: () => store.resetScenario(),
  openReports: (kind) => ui.open('reports', kind),
  openData: () => ui.open('data'),
  openSkill: (id, structureId) => ui.open('skill', { id, structureId }),
  closeAll: () => ui.closeAll(),
  resetView: () => three.resetView(),
  THREE,
};
