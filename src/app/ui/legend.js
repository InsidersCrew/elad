import { h, clear } from './dom.js';
import { lensById } from '../lenses.js';

export function createLegend(root, store) {
  function render() {
    const lens = lensById(store.lens);
    clear(root);
    root.append(h('span', { class: 't' }, lens.label));
    root.append(h('span', { class: 'sep' }));
    for (const it of lens.legend) {
      root.append(h('span', { class: 'item' }, it.icon ? h('span', { class: 'sw icon' }, it.icon) : h('span', { class: 'sw', style: { '--c': it.c } }), it.t));
    }
    root.append(h('span', { class: 'sep' }), h('span', { class: 'desc' }, lens.desc));
  }
  store.on('lens', render);
  render();
}
