/**
 * Structure / district / city scoring.
 * Health, Size, Strength, Risk and Bottleneck per structure, plus the rule flags
 * (weak, small, overloaded, leaky, fragile, unprofitable, critical bottleneck).
 */
import { statusOf } from './metrics.js';

function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}

function weightedAvg(items, pick) {
  let sum = 0;
  let w = 0;
  for (const it of items) {
    const v = pick(it);
    if (!Number.isFinite(v)) continue;
    sum += v * it.weight;
    w += it.weight;
  }
  return w > 0 ? sum / w : null;
}

/**
 * @param {object} structure definition
 * @param {object[]} metrics resolved metrics
 * @param {{downstreamWeight:number}} ctx
 * @param {object} rules
 */
export function scoreStructure(structure, metrics, ctx, rules) {
  const volumeUp = metrics.filter((m) => m.role === 'volume' && m.better === 'up');
  const quality = metrics.filter((m) => m.role === 'quality');
  const loads = metrics.filter((m) => m.role === 'load');

  // Health: weighted metric scores with penalties for fragility and overload
  let health = weightedAvg(metrics, (m) => m.score) ?? 50;
  const fragileMetrics = metrics.filter((m) => m.weight >= 2 && m.trend.consecutiveWorse >= (rules.fragile_periods ?? 3));
  const fragile = fragileMetrics.length > 0;
  if (fragile) health -= Math.min(12, 6 * fragileMetrics.length);

  const maxLoad = loads.length ? Math.max(...loads.map((m) => m.value)) : null;
  const overloaded = maxLoad !== null && maxLoad > (rules.overload_load ?? 0.85);
  if (overloaded) health -= clamp(((maxLoad - rules.overload_load) / 0.15) * 10, 0, 10);
  const ownLoad = maxLoad;
  health = clamp(health, 0, 100);

  // Size: actual volume relative to target (up-volume metrics only)
  const size = volumeUp.length
    ? clamp(weightedAvg(volumeUp, (m) => (m.target ? (m.value / m.target) * 100 : 100)), 5, 140)
    : 80;
  const small = volumeUp.some((m) => m.target && m.value < m.target * (1 - (rules.small_gap ?? 0.2)));

  // Strength: quality of the structure regardless of its size
  const strength = clamp(weightedAvg(quality, (m) => m.score) ?? health, 0, 100);

  const leakIds = new Set(rules.leak_metrics ?? []);
  const econIds = new Set(rules.economic_metrics ?? []);
  const leaky = metrics.some((m) => leakIds.has(m.id) && m.status === 'red');
  const unprofitable = metrics.some((m) => econIds.has(m.id) && m.status === 'red');
  const weak = health < (rules.weak_health ?? 60);

  // Risk: how much this structure endangers the whole chain
  const downstreamWeight = ctx.downstreamWeight ?? 0.5;
  const risk = clamp(
    0.45 * (100 - health) + 25 * downstreamWeight + (fragile ? 15 : 0) + (overloaded ? 15 : 0) + (leaky ? 10 : 0),
    0,
    100,
  );

  return {
    health,
    size,
    strength,
    risk,
    bottleneck: 0, // filled by attachBottleneck once sensitivities are known
    maxLoad,
    ownLoad,
    downstreamWeight,
    flags: { weak, small, overloaded, leaky, fragile, unprofitable, critical: false },
    fragileMetrics: fragileMetrics.map((m) => m.id),
    status: statusOf(health, rules),
  };
}

/**
 * Bottleneck = how much the structure constrains the whole city:
 * sensitivity of city contribution to its primary lever, its health gap and its load.
 */
export function attachBottleneck(scores, structure, sensitivities, rules) {
  const sens = structure.primary_lever ? sensitivities[structure.primary_lever]?.norm ?? 0 : 0;
  const gap = 100 - scores.health;
  const load = scores.maxLoad !== null && scores.maxLoad > rules.overload_load
    ? clamp(((scores.maxLoad - rules.overload_load) / 0.15) * 100, 0, 100)
    : 0;
  scores.bottleneck = clamp(0.55 * sens + 0.3 * gap + 0.15 * load, 0, 100);
  scores.sensitivity = sens;
  scores.flags.critical =
    scores.flags.weak &&
    scores.bottleneck >= (rules.critical_bottleneck_score ?? 60) &&
    scores.downstreamWeight >= (rules.critical_downstream_weight ?? 0.4);
  if (scores.flags.critical) scores.status = 'red';
  scores.visual = visualState(scores, rules);
  return scores;
}

export function visualState(s, rules) {
  return {
    height: s.size / 100,
    dim: s.flags.weak ? clamp((rules.weak_health - s.health) / rules.weak_health, 0.2, 0.8) : 0,
    cracks: s.strength < 60 ? clamp((60 - s.strength) / 60, 0, 1) : 0,
    congestion: s.flags.overloaded ? clamp((s.maxLoad - rules.overload_load) / 0.15, 0.2, 1) : 0,
    leak: s.flags.leaky ? 1 : 0,
    smoke: s.flags.unprofitable ? 1 : 0,
    glow: s.health >= (rules.strong_health ?? 85) ? clamp((s.health - rules.strong_health) / 15 + 0.4, 0.4, 1) : 0,
    beacon: s.flags.critical ? 1 : 0,
  };
}

export function scoreDistrict(district, structures, rules) {
  const w = (s) => s.def.footprint ?? 1;
  const tot = structures.reduce((a, s) => a + w(s), 0) || 1;
  const avg = (pick) => structures.reduce((a, s) => a + pick(s) * w(s), 0) / tot;
  const health = avg((s) => s.scores.health);
  const size = avg((s) => s.scores.size);
  const strength = avg((s) => s.scores.strength);
  const risk = Math.max(...structures.map((s) => s.scores.risk));
  const bottleneck = Math.max(...structures.map((s) => s.scores.bottleneck));
  const flags = {
    weak: health < rules.weak_health,
    overloaded: structures.some((s) => s.scores.flags.overloaded),
    leaky: structures.some((s) => s.scores.flags.leaky),
    fragile: structures.some((s) => s.scores.flags.fragile),
    unprofitable: structures.some((s) => s.scores.flags.unprofitable),
    critical: structures.some((s) => s.scores.flags.critical),
  };
  return { health, size, strength, risk, bottleneck, flags, status: flags.critical ? 'red' : statusOf(health, rules) };
}
