/**
 * Metric resolution and scoring against thresholds.
 * A metric definition (from data/city.json) points to a fact key; here we read
 * the current value, its history, score it 0–100 against good/warn/target and
 * derive its trend.
 */

function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}

/** 0–100 score of a value against a metric's thresholds. */
export function scoreMetric(value, m) {
  if (value === null || value === undefined || Number.isNaN(value)) return 50;
  if (!Number.isFinite(value)) return m.better === 'down' && value > 0 ? 0 : value > 0 ? 100 : 0;
  const { good, warn, target, better } = m;
  if (better === 'down') {
    if (value <= good) {
      const span = Math.max(1e-9, good - (target ?? good * 0.8));
      return 85 + 15 * clamp((good - value) / span, 0, 1);
    }
    if (value <= warn) return 60 + 25 * ((warn - value) / Math.max(1e-9, warn - good));
    const over = warn === 0 ? value : (value - warn) / Math.abs(warn);
    return 60 * clamp(1 - over, 0, 1);
  }
  // better === 'up'
  if (value >= good) {
    const span = Math.max(1e-9, (target ?? good * 1.2) - good);
    return 85 + 15 * clamp((value - good) / span, 0, 1);
  }
  if (value >= warn) return 60 + 25 * ((value - warn) / Math.max(1e-9, good - warn));
  if (warn <= 0) return value >= 0 ? 55 : 0;
  return 60 * clamp(value / warn, 0, 1);
}

export function statusOf(score, rules) {
  if (score < (rules?.weak_health ?? 60)) return 'red';
  if (score < (rules?.attention_health ?? 75)) return 'yellow';
  return 'green';
}

/** Is `b` worse than `a` for this metric? */
function worse(a, b, better) {
  if (a === undefined || b === undefined) return false;
  const eps = Math.abs(a) * 0.002;
  return better === 'down' ? b > a + eps : b < a - eps;
}

export function trendOf(history, better) {
  const h = (history || []).filter((v) => Number.isFinite(v));
  if (h.length < 2) return { dir: 'flat', delta: 0, deltaPct: 0, consecutiveWorse: 0, consecutiveBetter: 0 };
  const last = h[h.length - 1];
  const prev = h[h.length - 2];
  const delta = last - prev;
  const deltaPct = prev !== 0 ? delta / Math.abs(prev) : 0;
  let consecutiveWorse = 0;
  for (let i = h.length - 1; i > 0; i--) {
    if (worse(h[i - 1], h[i], better)) consecutiveWorse++;
    else break;
  }
  let consecutiveBetter = 0;
  for (let i = h.length - 1; i > 0; i--) {
    if (worse(h[i], h[i - 1], better)) consecutiveBetter++;
    else break;
  }
  let dir = 'flat';
  if (Math.abs(deltaPct) >= 0.01) dir = delta > 0 ? 'up' : 'down';
  const improving = dir === 'flat' ? null : better === 'down' ? dir === 'down' : dir === 'up';
  return { dir, delta, deltaPct, consecutiveWorse, consecutiveBetter, improving };
}

/**
 * @param {object} structure structure definition
 * @param {Record<string,number>} facts current derived facts
 * @param {Record<string,number>[]} factsHistory derived facts per period (last = current)
 * @param {object} rules
 */
export function resolveMetrics(structure, facts, factsHistory, rules) {
  return structure.metrics.map((m) => {
    const value = facts[m.source];
    const history = factsHistory.map((f) => f[m.source]);
    const score = scoreMetric(value, m);
    const trend = trendOf(history, m.better);
    const gap = m.target !== undefined && Number.isFinite(value) ? value - m.target : null;
    const gapPct = gap !== null && m.target ? gap / Math.abs(m.target) : null;
    return {
      ...m,
      value,
      history,
      score,
      status: statusOf(score, rules),
      trend,
      gap,
      gapPct,
      weight: m.weight ?? 1,
    };
  });
}
