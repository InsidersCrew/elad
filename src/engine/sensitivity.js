/**
 * Lever sensitivity: how much does the city's contribution move when a lever
 * improves by a standard step? This is what turns "weak" into "bottleneck":
 * a structure whose lever barely moves the city is not the constraint, however
 * ugly its KPI looks.
 */
import { simulate } from './model.js';
import { LEVERS } from './levers.js';

function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}

/** Apply a relative improvement to a lever, direction-aware and range-clamped. */
export function improvedValue(id, value, step) {
  const def = LEVERS[id];
  if (!def) return value;
  if (id === 'reps') return clamp(value + 1, def.min, def.max);
  if (def.better === 'down') return clamp(value * (1 - step), def.min, def.max);
  // A relative step for every lever (28% → 30.8%, 18 min → 16.2 min) so levers
  // are compared by elasticity, not by how much headroom they happen to have.
  return clamp(value * (1 + step), def.min, def.max);
}

/**
 * @param {Record<string,number>} inputs
 * @param {string[]} leverIds
 * @param {number} step relative improvement (0.10 = 10%)
 */
export function computeSensitivities(inputs, leverIds, step = 0.1) {
  const base = simulate(inputs);
  const out = {};
  let maxAbs = 0;
  for (const id of leverIds) {
    const def = LEVERS[id];
    if (!def) continue;
    const nv = improvedValue(id, inputs[id], step);
    if (nv === inputs[id]) {
      out[id] = { lever: id, from: inputs[id], to: nv, dContribution: 0, dJoined: 0, dRevenue: 0, dUtilization: 0, norm: 0 };
      continue;
    }
    const alt = simulate({ ...inputs, [id]: nv });
    const dContribution = alt.contribution - base.contribution;
    out[id] = {
      lever: id,
      from: inputs[id],
      to: nv,
      dContribution,
      dJoined: alt.joined - base.joined,
      dRevenue: alt.total_revenue - base.total_revenue,
      dUtilization: alt.rep_utilization - base.rep_utilization,
      elasticity: base.contribution !== 0 ? dContribution / Math.abs(base.contribution) / step : 0,
      norm: 0,
    };
    maxAbs = Math.max(maxAbs, Math.abs(dContribution));
  }
  for (const s of Object.values(out)) {
    s.norm = maxAbs > 0 ? clamp((s.dContribution / maxAbs) * 100, 0, 100) : 0;
  }
  return out;
}

export function rankSensitivities(sens) {
  return Object.values(sens).sort((a, b) => b.dContribution - a.dContribution);
}
