/** Tiny DOM helpers + shared UI atoms. */
import { fmtValue, fmtDelta } from '../../engine/format.js';

export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs ?? {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, v);
  }
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export const $ = (sel, root = document) => root.querySelector(sel);

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

const ICONS = {
  mayor: '<path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
  scenario: '<path d="M4 6h16M4 12h10M4 18h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="17" cy="12" r="2" fill="currentColor"/><circle cx="13" cy="18" r="2" fill="currentColor"/>',
  report: '<path d="M6 3h9l4 4v14H6z M15 3v4h4 M9 12h6 M9 16h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
  data: '<ellipse cx="12" cy="6" rx="7" ry="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6 M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3" fill="none" stroke="currentColor" stroke-width="1.8"/>',
  reset: '<path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
  close: '<path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
  play: '<path d="M7 5v14l11-7z" fill="currentColor"/>',
  copy: '<rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 15V5a1 1 0 0 1 1-1h9" fill="none" stroke="currentColor" stroke-width="1.8"/>',
  download: '<path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
  bloom: '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
  back: '<path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  skill: '<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.2l5.9-.9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
  chevron: '<path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  up: '<path d="M6 15l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
};
export function icon(name) {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = ICONS[name] ?? '';
  return s;
}

export function btn(label, { icon: ic, cls = '', onClick, title } = {}) {
  return h('button', { class: `btn ${cls}`, onClick, title: title ?? label }, ic ? icon(ic) : null, h('span', { class: 'label' }, label));
}

export const STATUS_HE = { green: 'בריא', yellow: 'דורש תשומת לב', red: 'חלש / סיכון' };
export function statusChip(status, text) {
  return h('span', { class: `chip solid ${status}` }, h('span', { class: 'dot' }), text ?? STATUS_HE[status] ?? status);
}

export function scoreBar(label, value, color) {
  const v = Math.max(0, Math.min(100, value));
  return h('div', { class: 'scorebar' },
    h('span', { class: 'lbl' }, label),
    h('div', { class: 'track' }, h('div', { class: 'fill', style: { width: `${v}%`, '--fill': color } })),
    h('span', { class: 'val' }, Math.round(v)),
  );
}

export function kpi(label, value, fmt, delta) {
  const cls = delta === undefined || delta === null ? '' : delta > 0 ? 'pos' : delta < 0 ? 'neg' : 'zero';
  return h('div', { class: 'kpi' },
    h('div', { class: 'v' }, fmtValue(value, fmt)),
    h('div', { class: 'l' }, label),
    delta !== undefined && delta !== null ? h('div', { class: `d ${cls}` }, fmtDelta(delta, fmt)) : null,
  );
}

export function scoreColor(v) {
  return v >= 75 ? 'var(--success)' : v >= 60 ? 'var(--warning)' : 'var(--danger)';
}

/** Delta chip coloured by whether the change is good. */
export function deltaChip(label, delta, fmt, { betterUp = true } = {}) {
  if (!Number.isFinite(delta) || Math.abs(delta) < 1e-9) return h('span', { class: 'chip' }, `${label} —`);
  const good = betterUp ? delta > 0 : delta < 0;
  return h('span', { class: `chip ${good ? 'green' : 'red'}` }, `${label} `, h('span', { class: 'num' }, fmtDelta(delta, fmt)));
}
