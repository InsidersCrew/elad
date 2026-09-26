/**
 * Scenario ("what-if") engine: apply lever changes, recompute the city and
 * describe what moved — including where the bottleneck went.
 */
import { LEVERS } from './levers.js';
import { fmtValue, fmtDelta } from './format.js';

export function applyChanges(inputs, changes) {
  const next = { ...inputs };
  for (const [k, v] of Object.entries(changes ?? {})) {
    if (!(k in next) || typeof v !== 'number' || Number.isNaN(v)) continue;
    const def = LEVERS[k];
    next[k] = def ? Math.min(def.max, Math.max(def.min, v)) : v;
  }
  return next;
}

export const KEY_OUTCOMES = [
  { key: 'leads_total', label: 'לידים', fmt: 'int' },
  { key: 'qualified', label: 'Qualified', fmt: 'int' },
  { key: 'calls_completed', label: 'שיחות התאמה', fmt: 'int' },
  { key: 'joined', label: 'תלמידים חדשים', fmt: 'int' },
  { key: 'started', label: 'התחילו', fmt: 'int' },
  { key: 'opened', label: 'חשבונות נפתחו', fmt: 'int' },
  { key: 'rep_utilization', label: 'ניצולת נציגים', fmt: 'pct' },
  { key: 'total_revenue', label: 'הכנסות', fmt: 'money' },
  { key: 'contribution', label: 'תרומה', fmt: 'money' },
  { key: 'net', label: 'רווח נקי', fmt: 'money' },
  { key: 'cac', label: 'CAC', fmt: 'money' },
  { key: 'revenue_per_student', label: 'הכנסה לתלמיד', fmt: 'money' },
  { key: 'cash_tied_up', label: 'מזומן כלוא', fmt: 'money' },
  { key: 'revenue_leakage', label: 'דליפת הכנסה', fmt: 'money' },
];

/**
 * @param {object} base computed city (from buildCity)
 * @param {object} alt computed city with changes applied
 */
export function diffCities(base, alt) {
  const outcomes = KEY_OUTCOMES.map((o) => {
    const from = base.facts[o.key];
    const to = alt.facts[o.key];
    const delta = to - from;
    return { ...o, from, to, delta, pct: from ? delta / Math.abs(from) : 0, text: fmtDelta(delta, o.fmt) };
  });
  const structures = alt.structures.map((s) => {
    const b = base.byId.structures[s.def.id];
    return {
      id: s.def.id,
      name: s.def.name_he,
      district: s.def.district_id,
      dHealth: s.scores.health - b.scores.health,
      dSize: s.scores.size - b.scores.size,
      dBottleneck: s.scores.bottleneck - b.scores.bottleneck,
      fromStatus: b.scores.status,
      toStatus: s.scores.status,
    };
  });
  const moved = structures.filter((s) => Math.abs(s.dHealth) >= 2 || Math.abs(s.dSize) >= 4 || s.fromStatus !== s.toStatus);
  const bottleneckFrom = base.mayor.primaryBottleneck?.def;
  const bottleneckTo = alt.mayor.primaryBottleneck?.def;
  const secondOrder = secondOrderEffects(base.facts, alt.facts);
  return {
    outcomes,
    structures: moved.sort((a, b) => Math.abs(b.dHealth) + Math.abs(b.dSize) - (Math.abs(a.dHealth) + Math.abs(a.dSize))),
    cityHealth: { from: base.city.health, to: alt.city.health },
    bottleneck: { from: bottleneckFrom, to: bottleneckTo, moved: bottleneckFrom?.id !== bottleneckTo?.id },
    secondOrder,
  };
}

/** Effects a local optimizer would miss. */
export function secondOrderEffects(base, alt) {
  const out = [];
  const du = alt.rep_utilization - base.rep_utilization;
  if (du > 0.03) {
    out.push({ kind: 'warn', text: `מעלה עומס נציגים ל-${fmtValue(alt.rep_utilization, 'pct')} (${fmtDelta(du, 'pct')})` + (alt.rep_utilization > 0.95 ? ' — מעבר לקיבולת, יידרש נציג נוסף או שחרור זמן' : '') });
  } else if (du < -0.03) {
    out.push({ kind: 'good', text: `משחרר קיבולת נציגים: ניצולת ${fmtValue(alt.rep_utilization, 'pct')} (${fmtDelta(du, 'pct')})` });
  }
  if (alt.calls_overflow > base.calls_overflow + 5) {
    out.push({ kind: 'warn', text: `${fmtValue(alt.calls_overflow, 'int')} שיחות בחודש לא יבוצעו מחוסר קיבולת` });
  }
  const dcash = alt.cash_tied_up - base.cash_tied_up;
  if (dcash > base.cash_tied_up * 0.1) {
    out.push({ kind: 'warn', text: `מגדיל מזומן כלוא ב-${fmtValue(dcash, 'money')} — צמיחה דורשת הון חוזר` });
  }
  const dcac = alt.cac - base.cac;
  if (Number.isFinite(dcac) && dcac > base.cac * 0.05) {
    out.push({ kind: 'warn', text: `CAC עולה ל-${fmtValue(alt.cac, 'money')} (${fmtDelta(dcac, 'money')})` });
  } else if (Number.isFinite(dcac) && dcac < -base.cac * 0.05) {
    out.push({ kind: 'good', text: `CAC יורד ל-${fmtValue(alt.cac, 'money')} (${fmtDelta(dcac, 'money')})` });
  }
  const dleak = alt.revenue_leakage - base.revenue_leakage;
  if (dleak > 2000) out.push({ kind: 'warn', text: `דליפת הכנסה גדלה ב-${fmtValue(dleak, 'money')} (יותר אי-פותחים → יותר ויתורים ואי-גבייה)` });
  if (alt.rep_utilization < 0.6 && base.rep_utilization >= 0.6) out.push({ kind: 'info', text: 'הנציגים יורדים מתחת ל-60% ניצולת — יש מקום להגדיל נפח לידים' });
  return out;
}

export function describeChanges(baseInputs, changes) {
  return Object.entries(changes ?? {})
    .filter(([k, v]) => k in baseInputs && v !== baseInputs[k])
    .map(([k, v]) => {
      const def = LEVERS[k];
      return { lever: k, label: def?.label ?? k, from: baseInputs[k], to: v, fmt: def?.fmt ?? 'num', text: `${def?.label ?? k}: ${fmtValue(baseInputs[k], def?.fmt)} → ${fmtValue(v, def?.fmt)}` };
    });
}
