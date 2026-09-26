import { h, icon, btn, clear } from './dom.js';
import { LENSES } from '../lenses.js';
import { fmtValue } from '../../engine/format.js';

export function createTopbar(root, store, ui, three) {
  const R = 15;
  const circ = 2 * Math.PI * R;
  let meterBar;
  let meterVal;
  let ticker;
  let lensBtns;
  let lensSelect;
  let actionBtns = {};

  function build() {
    clear(root);
    const brand = h('a', { class: 'brand', href: '#', onClick: (e) => { e.preventDefault(); three.resetView(); store.select(null); } },
      h('span', { class: 'wordmark' }, 'IN', h('span', { class: 'slash' }, '/'), 'SIDERS'),
      h('span', { class: 'product' }, 'CityOS'),
      h('span', { class: 'period' }, store.current.periodLabel),
    );
    meterBar = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    const track = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    for (const [c, cls] of [[track, 'track'], [meterBar, 'bar']]) {
      c.setAttribute('cx', 18); c.setAttribute('cy', 18); c.setAttribute('r', R); c.setAttribute('class', cls);
    }
    meterBar.setAttribute('stroke-dasharray', circ.toFixed(1));
    meterBar.setAttribute('stroke-dashoffset', circ.toFixed(1));
    const ring = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    ring.setAttribute('viewBox', '0 0 36 36');
    ring.setAttribute('class', 'ring');
    ring.append(track, meterBar);
    meterVal = h('div', { class: 'val num' }, '—');
    const meter = h('div', { class: 'city-meter', title: 'City Health Score' }, ring, h('div', {}, meterVal, h('div', { class: 'lbl' }, 'בריאות העיר')));

    ticker = h('div', { class: 'alert-ticker' });
    const alertbar = document.getElementById('alertbar');
    clear(alertbar).append(ticker);

    lensBtns = LENSES.map((l) => h('button', { class: l.id === store.lens ? 'active' : '', onClick: () => store.setLens(l.id), title: l.desc }, l.label));
    const lenses = h('div', { class: 'lenses', role: 'tablist' }, lensBtns);
    lensSelect = h('select', { class: 'lenses-select', onChange: (e) => store.setLens(e.target.value) }, LENSES.map((l) => h('option', { value: l.id, selected: l.id === store.lens }, l.label)));

    actionBtns = {
      mayor: btn('ראש העיר', { icon: 'mayor', onClick: () => ui.toggle('mayor') }),
      scenario: btn('סימולציה', { icon: 'scenario', onClick: () => ui.toggle('scenario') }),
      reports: btn('דוחות', { icon: 'report', onClick: () => ui.open('reports', 'daily') }),
      data: btn('נתונים', { icon: 'data', onClick: () => ui.open('data') }),
      bloom: btn('זוהר', { icon: 'bloom', cls: (three.state.bloom ? 'active' : '') + ' mobile-hide', onClick: () => { three.setBloom(!three.state.bloom); actionBtns.bloom.classList.toggle('active', three.state.bloom); }, title: 'אפקט זוהר (bloom) — כיבוי משפר ביצועים' }),
      reset: btn('איפוס מבט', { icon: 'reset', cls: 'ghost mobile-hide', onClick: () => { three.resetView(); } }),
    };
    root.append(brand, meter, h('div', { class: 'spacer' }), lenses, lensSelect, h('div', { class: 'topbar-actions' }, Object.values(actionBtns)));
  }

  function update() {
    const c = store.current;
    const hlt = c.city.health;
    meterVal.textContent = Math.round(hlt);
    meterBar.setAttribute('stroke-dashoffset', (circ * (1 - hlt / 100)).toFixed(1));
    meterBar.style.setProperty('--meter', hlt >= 75 ? 'var(--success)' : hlt >= 60 ? 'var(--warning)' : 'var(--danger)');
    meterBar.parentElement.parentElement.style.setProperty('--meter', hlt >= 75 ? 'var(--success)' : hlt >= 60 ? 'var(--warning)' : 'var(--danger)');
    clear(ticker);
    const alerts = c.alerts.slice(0, 3);
    alerts.forEach((a, i) => {
      const el = h('span', { class: `alert ${a.level}`, style: { animationDelay: `${i * 90}ms` }, onClick: () => ui.selectStructure(a.structure) }, h('span', { class: 'dot' }), a.text);
      ticker.append(el);
    });
    if (c.alerts.length > 3) ticker.append(h('span', { class: 'more' }, `+${c.alerts.length - 3} התראות`));
    actionBtns.scenario.classList.toggle('active', store.hasScenario());
    actionBtns.mayor.classList.toggle('active', ui.isOpen('mayor'));
  }

  function updateLens() {
    lensBtns.forEach((b, i) => b.classList.toggle('active', LENSES[i].id === store.lens));
    lensSelect.value = store.lens;
  }

  build();
  store.on('state', update);
  store.on('lens', updateLens);
  store.on('ui', update);
  update();
  return { update };
}

export { fmtValue };
