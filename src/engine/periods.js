/**
 * Period (month) management for data/model.json.
 * The model keeps `inputs` = the current (last) period and `history[lever]` =
 * one value per period label. These helpers keep both in sync so a month can
 * be added, edited or removed without hand-editing arrays.
 * All functions return a new model object; the input is never mutated.
 */
import { LEVERS } from './levers.js';

const HE_MONTHS = ['ינו׳', 'פבר׳', 'מרץ', 'אפר׳', 'מאי', 'יוני', 'יולי', 'אוג׳', 'ספט׳', 'אוק׳', 'נוב׳', 'דצמ׳'];

function clone(model) {
  return JSON.parse(JSON.stringify(model));
}

export function periodCount(model) {
  return model.period.labels.length;
}

/** Make every history array exactly `n` long: pad at the front with the first value, truncate the tail. */
export function normalizeModel(model) {
  const m = clone(model);
  const n = periodCount(m);
  m.history ??= {};
  for (const [k, arr] of Object.entries(m.history)) {
    if (!Array.isArray(arr) || !LEVERS[k]) {
      delete m.history[k];
      continue;
    }
    const vals = arr.filter((v) => typeof v === 'number' && !Number.isNaN(v));
    if (!vals.length) {
      delete m.history[k];
      continue;
    }
    while (vals.length < n) vals.unshift(vals[0]);
    m.history[k] = vals.slice(vals.length - n);
    // The last history entry is the current period by definition.
    m.history[k][n - 1] = typeof m.inputs[k] === 'number' ? m.inputs[k] : m.history[k][n - 1];
  }
  return m;
}

/** Values of every lever at period i (levers without history use the current value). */
export function valuesAt(model, i) {
  const out = { ...model.inputs };
  for (const [k, arr] of Object.entries(model.history ?? {})) {
    if (Array.isArray(arr) && typeof arr[i] === 'number') out[k] = arr[i];
  }
  return out;
}

/** Set one lever for period i. Creates the history array on first divergence. */
export function setValue(model, i, lever, value) {
  const m = normalizeModel(model);
  const n = periodCount(m);
  if (i < 0 || i >= n || !LEVERS[lever] || typeof value !== 'number' || Number.isNaN(value)) return m;
  const def = LEVERS[lever];
  const v = Math.min(def.max, Math.max(def.min, value));
  if (!m.history[lever]) m.history[lever] = Array.from({ length: n }, () => m.inputs[lever]);
  m.history[lever][i] = v;
  if (i === n - 1) m.inputs[lever] = v;
  // Drop history arrays that are flat again — keeps the file readable.
  if (m.history[lever].every((x) => x === m.inputs[lever])) delete m.history[lever];
  return m;
}

/** Set many levers for period i. */
export function setValues(model, i, values) {
  let m = model;
  for (const [k, v] of Object.entries(values)) m = setValue(m, i, k, v);
  return m;
}

/** Append a new period. Every lever carries forward from the previous period (optionally overridden). */
export function addPeriod(model, label, values = {}) {
  const m = normalizeModel(model);
  const n = periodCount(m);
  const name = (label ?? '').trim() || nextPeriodLabel(m.period.labels[n - 1]);
  if (m.period.labels.includes(name)) throw new Error(`התקופה "${name}" כבר קיימת`);
  m.period.labels.push(name);
  for (const k of Object.keys(m.history)) m.history[k].push(m.history[k][n - 1]);
  m.meta = { ...(m.meta ?? {}), updated: new Date().toISOString().slice(0, 10) };
  return setValues(m, n, values);
}

/** Remove the last period; the previous one becomes current. Never removes the only period. */
export function removeLastPeriod(model) {
  const m = normalizeModel(model);
  const n = periodCount(m);
  if (n <= 1) return m;
  m.period.labels.pop();
  for (const k of Object.keys(m.history)) {
    m.history[k].pop();
    m.inputs[k] = m.history[k][n - 2];
    if (m.history[k].every((x) => x === m.inputs[k])) delete m.history[k];
  }
  return m;
}

export function renamePeriod(model, i, label) {
  const m = normalizeModel(model);
  const name = (label ?? '').trim();
  if (!name || m.period.labels.includes(name)) return m;
  m.period.labels[i] = name;
  return m;
}

/** "ספט׳ 26" → "אוק׳ 26", "דצמ׳ 26" → "ינו׳ 27"; anything else → "תקופה N". */
export function nextPeriodLabel(last) {
  const s = (last ?? '').trim();
  const mIdx = HE_MONTHS.findIndex((mo) => s.startsWith(mo.replace('׳', '')) || s.startsWith(mo));
  const year = s.match(/(\d{2,4})\s*$/)?.[1];
  if (mIdx >= 0 && year) {
    const next = (mIdx + 1) % 12;
    const y = Number(year) + (next === 0 ? 1 : 0);
    return `${HE_MONTHS[next]} ${y}`;
  }
  const num = s.match(/(\d+)\s*$/)?.[1];
  return num ? s.replace(/\d+\s*$/, String(Number(num) + 1)) : 'תקופה חדשה';
}

/** Which levers changed in period i vs. period i-1 — handy for review. */
export function changedLevers(model, i) {
  if (i <= 0) return [];
  const a = valuesAt(model, i - 1);
  const b = valuesAt(model, i);
  return Object.keys(b).filter((k) => Math.abs(b[k] - a[k]) > 1e-12);
}

export { HE_MONTHS };
