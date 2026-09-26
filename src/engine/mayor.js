/**
 * Co-CEO Mayor — the decision engine.
 * Looks at the whole city, ranks bottlenecks by real business impact
 * (simulated, not guessed), explains root causes, simulates every candidate
 * move and returns the highest-leverage actions with owner skill and priority.
 */
import { simulate } from './model.js';
import { applyAction, actionChanges } from './rootcause.js';
import { secondOrderEffects } from './scenario.js';
import { fmtValue, fmtDelta } from './format.js';
import { LEVERS } from './levers.js';

const EFFORT_COST = { low: 1, medium: 1.8, high: 3 };

function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}

export function mayorBrief(city, opts = {}) {
  const { structures, districts, facts, inputs, sensitivities, rules } = city;
  const operational = structures.filter((s) => s.def.district_id !== 'cityhall');

  // ── Weakest and bottlenecks ───────────────────────────────────
  const weakest = [...operational].sort((a, b) => a.scores.health - b.scores.health).slice(0, 3);
  const bottlenecks = [...operational].sort((a, b) => b.scores.bottleneck - a.scores.bottleneck).slice(0, 5);
  const primaryBottleneck = bottlenecks[0];
  const primaryDistrict = districts.find((d) => d.def.id === primaryBottleneck?.def.district_id);

  // ── Candidate moves: every action of every matched cause on any structure ─
  const base = facts;
  const candidates = [];
  const seen = new Set();
  for (const s of structures) {
    for (const cause of s.rootCauses) {
      for (const action of cause.actions ?? []) {
        const changes = actionChanges(inputs, action);
        if (!changes.length) continue;
        const key = changes.map((c) => `${c.lever}=${c.to}`).join('|');
        if (seen.has(key)) continue;
        seen.add(key);
        const alt = simulate(applyAction(inputs, action));
        const impact = {
          dContribution: alt.contribution - base.contribution,
          dNet: alt.net - base.net,
          dRevenue: alt.total_revenue - base.total_revenue,
          dJoined: alt.joined - base.joined,
          dOpened: alt.opened - base.opened,
          dUtilization: alt.rep_utilization - base.rep_utilization,
          dCac: alt.cac - base.cac,
          dCash: alt.cash_tied_up - base.cash_tied_up,
          utilizationAfter: alt.rep_utilization,
        };
        const secondOrder = secondOrderEffects(base, alt);
        const effort = action.effort ?? 'medium';
        // Leverage = money per unit of effort; penalise moves that break rep capacity.
        let leverage = impact.dContribution / (EFFORT_COST[effort] ?? 1.8);
        if (impact.utilizationAfter > 0.95 && impact.dUtilization > 0.02) leverage *= 0.5;
        candidates.push({
          action,
          changes,
          structure: s,
          cause,
          impact,
          secondOrder,
          effort,
          leverage,
          ownerSkill: action.owner_skill ?? s.def.owner_skill,
          isBottleneckFix: s.def.id === primaryBottleneck?.def.id || s.def.district_id === primaryBottleneck?.def.district_id,
        });
      }
    }
  }
  candidates.sort((a, b) => b.leverage - a.leverage);
  const positive = candidates.filter((c) => c.impact.dContribution > 0);
  const moves = positive.slice(0, opts.maxMoves ?? 5).map((c, i) => ({
    ...c,
    rank: i + 1,
    priority: priorityOf(c, positive[0]),
  }));

  // ── State of the city ─────────────────────────────────────────
  const weakDistricts = districts.filter((d) => d.def.id !== 'cityhall' && d.scores.status !== 'green');
  const criticalCount = operational.filter((s) => s.scores.flags.critical).length;
  const overloadedCount = operational.filter((s) => s.scores.flags.overloaded).length;
  const leakyCount = operational.filter((s) => s.scores.flags.leaky).length;
  const fragileCount = operational.filter((s) => s.scores.flags.fragile).length;

  const state = {
    health: city.city.health,
    status: city.city.status,
    revenue: facts.total_revenue,
    contribution: facts.contribution,
    net: facts.net,
    netMargin: facts.net_margin,
    joined: facts.joined,
    leads: facts.leads_total,
    utilization: facts.rep_utilization,
    counts: { weak: operational.filter((s) => s.scores.flags.weak).length, critical: criticalCount, overloaded: overloadedCount, leaky: leakyCount, fragile: fragileCount },
    weakDistricts: weakDistricts.map((d) => d.def),
    trend: cityTrend(city),
  };

  const narrative = buildNarrative(city, state, primaryBottleneck, primaryDistrict, moves);
  const monitor = whatToMonitor(city, primaryBottleneck, moves);

  return { state, weakest, bottlenecks, primaryBottleneck, primaryDistrict, moves, candidates: positive, narrative, monitor, generatedAt: new Date().toISOString() };
}

function priorityOf(c, top) {
  if (!top) return 'low';
  const ratio = c.leverage / Math.max(1e-9, top.leverage);
  if (c.isBottleneckFix && ratio > 0.4) return 'high';
  if (ratio > 0.6) return 'high';
  if (ratio > 0.25) return 'medium';
  return 'low';
}

function cityTrend(city) {
  const h = city.factsHistory;
  if (h.length < 2) return { dir: 'flat', dContribution: 0, dJoined: 0 };
  const a = h[h.length - 2];
  const b = h[h.length - 1];
  const dContribution = b.contribution - a.contribution;
  const dJoined = b.joined - a.joined;
  const dLeads = b.leads_total - a.leads_total;
  return { dir: dContribution > 0 ? 'up' : dContribution < 0 ? 'down' : 'flat', dContribution, dJoined, dLeads, dConv: b.lead_to_joined - a.lead_to_joined };
}

/** The CEO paragraph: not "sales were X" but "what limits growth, why, and what to do". */
function buildNarrative(city, state, bottleneck, district, moves) {
  const f = city.facts;
  const parts = [];
  const gate = city.byId.districts.acquisition;
  const gateOk = gate && gate.scores.health >= 60;
  const leadsTrend = state.trend.dLeads > 0 ? 'ועולים' : state.trend.dLeads < 0 ? 'ויורדים' : '';
  if (gateOk) {
    parts.push(`העיר לא חלשה בגלל מחסור בלידים — ${fmtValue(f.leads_total, 'int')} לידים בחודש ${leadsTrend}, שער הכניסה ${statusWord(gate.scores.status)}.`);
  } else {
    parts.push(`שער הכניסה עצמו חלש (${fmtValue(gate?.scores.health ?? 0, 'num')}) — ${fmtValue(f.leads_total, 'int')} לידים בחודש ${leadsTrend}.`);
  }
  if (bottleneck && district) {
    const cause = bottleneck.rootCauses[0];
    parts.push(`היא מוגבלת ב${district.def.name_he} (${district.def.name}): ${bottleneck.def.name_he}` + (cause ? ` — ${cause.title}.` : '.'));
    const chain = downstreamChain(city, district);
    if (chain) parts.push(chain);
  }
  if (Number.isFinite(f.net_margin)) {
    if (f.net_margin < 0.05) parts.push(`התוצאה: הכנסה של ${fmtValue(f.total_revenue, 'money')} בחודש שכמעט לא משאירה רווח (${fmtValue(f.net, 'money')}).`);
    else parts.push(`התוצאה: ${fmtValue(f.total_revenue, 'money')} הכנסה ו-${fmtValue(f.net, 'money')} רווח בחודש.`);
  }
  if (moves.length) {
    const m = moves[0];
    const joinedTxt = m.impact.dJoined > 0.5 ? `${fmtDelta(m.impact.dJoined, 'int')} תלמידים חדשים` : m.impact.dUtilization < -0.01 ? 'ומשחרר קיבולת נציגים' : 'בלי עומס נוסף על הנציגים';
    parts.push(`המהלך הנכון עכשיו: ${m.action.title} — צפוי ${fmtDelta(m.impact.dContribution, 'money')} תרומה בחודש (${joinedTxt}). אחראי: ${skillNameOf(city, m.ownerSkill)}.`);
    if (moves[1]) parts.push(`מהלך שני: ${moves[1].action.title} (${fmtDelta(moves[1].impact.dContribution, 'money')}).`);
  }
  return parts.join(' ');
}

function skillNameOf(city, id) {
  return city.skills?.find((s) => s.id === id)?.name_he ?? id;
}

function statusWord(s) {
  return s === 'green' ? 'בריא' : s === 'yellow' ? 'דורש תשומת לב' : 'חלש';
}

/** Explain how the bottleneck shrinks everything downstream. */
function downstreamChain(city, district) {
  const order = ['acquisition', 'qualification', 'fitcall', 'onboarding', 'broker', 'monetization', 'success', 'growth', 'referral'];
  const idx = order.indexOf(district.def.id);
  if (idx < 0) return null;
  const f = city.facts;
  const links = {
    qualification: `רק ${fmtValue(f.qual_rate_effective, 'pct')} מהלידים הופכים ל-Qualified`,
    fitcall: `רק ${fmtValue(f.joined, 'int')} תלמידים חדשים מ-${fmtValue(f.calls_completed, 'int')} שיחות (${fmtValue(f.fit_conv_effective, 'pct')})`,
    onboarding: `רק ${fmtValue(f.started, 'int')} מתחילים (${fmtValue(f.started_rate, 'pct')})`,
    broker: `רק ${fmtValue(f.opened, 'int')} פותחים חשבון (${fmtValue(f.open_rate, 'pct')})`,
    monetization: `${fmtValue(f.revenue_per_student, 'money')} הכנסה לתלמיד מול CAC של ${fmtValue(f.cac, 'money')}`,
    success: `${fmtValue(f.at_risk_rate, 'pct')} מהתלמידים בסיכון`,
    growth: `${fmtValue(f.coaching_deals, 'num')} עסקאות אימון בחודש`,
    referral: `רק ${fmtValue(f.leads_referral, 'int')} לידים מהפניות`,
  };
  const downstream = order.slice(idx + 1, idx + 4).map((id) => links[id]).filter(Boolean);
  if (!downstream.length) return null;
  return 'בגלל זה, במורד הזרם: ' + downstream.join('; ') + '.';
}

function whatToMonitor(city, bottleneck, moves) {
  const items = [];
  if (bottleneck) {
    for (const m of bottleneck.metrics.slice(0, 2)) items.push({ metric: m.name, structure: bottleneck.def.name_he, value: fmtValue(m.value, m.fmt), target: fmtValue(m.target, m.fmt), why: 'צוואר הבקבוק הראשי' });
  }
  for (const mv of moves.slice(0, 3)) {
    for (const c of mv.changes.slice(0, 1)) {
      const def = LEVERS[c.lever];
      items.push({ metric: def?.label ?? c.lever, structure: mv.structure.def.name_he, value: fmtValue(c.from, def?.fmt), target: fmtValue(c.to, def?.fmt), why: `המדד של המהלך: ${mv.action.title}` });
    }
  }
  items.push({ metric: 'ניצולת נציגים', structure: 'מגדל קיבולת הנציגים', value: fmtValue(city.facts.rep_utilization, 'pct'), target: '≤ 82%', why: 'כל שיפור במעלה הזרם מעלה עומס כאן' });
  return items.slice(0, 6);
}

export function priorityLabel(p) {
  return { high: 'גבוהה', medium: 'בינונית', low: 'נמוכה' }[p] ?? p;
}

export { clamp };
