/**
 * Root Cause Engine — not just "what is weak" but "why".
 * Rules live in data/rootcauses.json; each rule is a set of conditions over
 * model facts, the evidence to show, and actions with lever changes that the
 * scenario engine can simulate for expected impact.
 */
import { LEVERS } from './levers.js';

const OPS = {
  '>': (a, b) => a > b,
  '<': (a, b) => a < b,
  '>=': (a, b) => a >= b,
  '<=': (a, b) => a <= b,
  '==': (a, b) => a === b,
};

function readFact(name, facts, scores) {
  if (name === 'health') return scores?.health;
  if (name.startsWith('scores.')) return scores?.[name.slice(7)];
  return facts[name];
}

export function conditionHolds(cond, facts, scores) {
  const v = readFact(cond.fact, facts, scores);
  if (v === undefined || v === null || Number.isNaN(v)) return false;
  const op = OPS[cond.op];
  return op ? op(v, cond.value) : false;
}

/** Apply an action's lever changes to an inputs map, range-clamped. */
export function applyAction(inputs, action) {
  const next = { ...inputs };
  const changes = [{ lever: action.lever, set: action.set, add: action.add, mul: action.mul }, ...(action.also ?? [])];
  for (const c of changes) {
    if (!c.lever || !(c.lever in next)) continue;
    const def = LEVERS[c.lever];
    let v = next[c.lever];
    if (c.set !== undefined) v = c.set;
    else if (c.add !== undefined) v += c.add;
    else if (c.mul !== undefined) v *= c.mul;
    if (def) v = Math.min(def.max, Math.max(def.min, v));
    next[c.lever] = v;
  }
  return next;
}

export function actionChanges(inputs, action) {
  const next = applyAction(inputs, action);
  return Object.keys(next)
    .filter((k) => next[k] !== inputs[k])
    .map((k) => ({ lever: k, from: inputs[k], to: next[k] }));
}

/**
 * Evaluate the rules for one structure.
 * @returns matched causes, sorted by confidence desc
 */
export function evaluateRootCauses(rules, structure, facts, scores, metrics, factLabel) {
  const metricsBySource = new Map(metrics.map((m) => [m.source, m]));
  const out = [];
  for (const rule of rules.filter((r) => r.structure_id === structure.id)) {
    const held = rule.when.filter((c) => conditionHolds(c, facts, scores));
    if (held.length !== rule.when.length) continue;
    const evidence = (rule.evidence ?? []).map((src) => {
      const m = metricsBySource.get(src);
      return {
        source: src,
        name: m?.name ?? factLabel?.(src) ?? src,
        value: facts[src],
        fmt: m?.fmt ?? LEVERS[src]?.fmt ?? guessFmt(src, facts[src]),
        status: m?.status ?? null,
        trend: m?.trend ?? null,
      };
    });
    const redCount = evidence.filter((e) => e.status === 'red').length;
    const worsening = evidence.filter((e) => e.trend && e.trend.consecutiveWorse >= 2).length;
    const confidence = Math.min(0.97, (rule.confidence ?? 0.6) + 0.05 * redCount + 0.03 * worsening);
    out.push({ ...rule, evidence, confidence, matched: true });
  }
  // Generic fallback so a weak structure never shows an empty "why".
  if (out.length === 0 && scores.health < 60) {
    const reds = metrics.filter((m) => m.status === 'red');
    if (reds.length) {
      out.push({
        id: `generic_${structure.id}`,
        structure_id: structure.id,
        title: 'מדדי ליבה מתחת לסף',
        description: `המבנה חלש בעיקר בגלל: ${reds.map((m) => m.name).join(', ')}.`,
        evidence: reds.map((m) => ({ source: m.source, name: m.name, value: m.value, fmt: m.fmt, status: m.status, trend: m.trend })),
        confidence: 0.5,
        impact: 'נדרש ניתוח ידני עם הסקיל האחראי.',
        actions: [],
        matched: true,
        generic: true,
      });
    }
  }
  return out.sort((a, b) => b.confidence - a.confidence);
}

function guessFmt(key, v) {
  if (/_rate|_share|_conv|_quality|_coverage|utilization|margin|resolve/.test(key)) return 'pct';
  if (/revenue|cost|cac|value|contribution|spend|net$|tied_up|leakage/.test(key)) return 'money';
  if (/_min$/.test(key)) return 'min';
  if (/_days$/.test(key)) return 'days';
  if (/_hours$/.test(key)) return 'hours';
  if (Number.isInteger(v)) return 'int';
  return 'num';
}
