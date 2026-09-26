/**
 * buildCity — the single entry point of the engine.
 * data + (optional) lever overrides → fully computed city state:
 * facts, metrics, scores, flags, root causes, sensitivities, mayor brief.
 */
import { simulate, simulateHistory, inputsAtPeriod } from './model.js';
import { resolveMetrics } from './metrics.js';
import { scoreStructure, attachBottleneck, scoreDistrict } from './scoring.js';
import { computeSensitivities, rankSensitivities } from './sensitivity.js';
import { evaluateRootCauses } from './rootcause.js';
import { mayorBrief } from './mayor.js';
import { SCENARIO_LEVERS, LEVERS } from './levers.js';
import { applyChanges } from './scenario.js';

/**
 * @param {{city:object, model:object, rules:object, rootcauses:object, skills:object}} data
 * @param {Record<string,number>} [overrides] scenario lever changes
 */
export function buildCity(data, overrides = null) {
  const { city: cityDef, model, rules, rootcauses, skills } = data;
  const baseInputs = inputsAtPeriod(model, model.period.labels.length - 1);
  const inputs = overrides ? applyChanges(baseInputs, overrides) : baseInputs;
  const facts = simulate(inputs);

  // History: real periods; the current period is replaced by the (possibly overridden) inputs.
  const factsHistory = simulateHistory(model);
  factsHistory[factsHistory.length - 1] = facts;

  const sensitivities = computeSensitivities(inputs, SCENARIO_LEVERS, rules.sensitivity_step ?? 0.1);
  const ranking = rankSensitivities(sensitivities);

  const downstreamWeights = computeDownstreamWeights(cityDef.districts);
  const factLabel = (k) => LEVERS[k]?.label;

  const structures = cityDef.structures.map((def) => {
    const metrics = resolveMetrics(def, facts, factsHistory, rules);
    const scores = scoreStructure(def, metrics, { downstreamWeight: downstreamWeights[def.district_id] ?? 0.5 }, rules);
    return { def, metrics, scores, rootCauses: [], districtId: def.district_id };
  });
  // Load is a district property: an overloaded Fit Call district constrains every structure in it.
  const districtLoad = {};
  for (const s of structures) {
    if (s.scores.maxLoad !== null) districtLoad[s.def.district_id] = Math.max(districtLoad[s.def.district_id] ?? 0, s.scores.maxLoad);
  }
  for (const s of structures) {
    if (s.scores.maxLoad === null && districtLoad[s.def.district_id] !== undefined) s.scores.maxLoad = districtLoad[s.def.district_id];
    attachBottleneck(s.scores, s.def, sensitivities, rules);
    s.rootCauses = evaluateRootCauses(rootcauses.rules, s.def, facts, s.scores, s.metrics, factLabel);
  }
  const structuresById = Object.fromEntries(structures.map((s) => [s.def.id, s]));

  const districts = cityDef.districts.map((def) => {
    const own = structures.filter((s) => s.def.district_id === def.id);
    const scores = scoreDistrict(def, own, rules);
    const flow = def.flow ? resolveFlow(def.flow, facts) : null;
    return { def, structures: own, scores, flow, kpis: (def.kpis ?? []).map((k) => ({ key: k, value: facts[k], label: factLabel(k) ?? metricNameFor(k, structures) ?? k, fmt: fmtFor(k, structures) })) };
  });
  const districtsById = Object.fromEntries(districts.map((d) => [d.def.id, d]));

  const operational = districts.filter((d) => d.def.id !== 'cityhall');
  const cityHealth = operational.reduce((a, d) => a + d.scores.health, 0) / Math.max(1, operational.length);
  const city = {
    health: cityHealth,
    status: cityHealth < rules.weak_health ? 'red' : cityHealth < rules.attention_health ? 'yellow' : 'green',
    counts: {
      weak: structures.filter((s) => s.scores.flags.weak).length,
      critical: structures.filter((s) => s.scores.flags.critical).length,
      overloaded: structures.filter((s) => s.scores.flags.overloaded).length,
      leaky: structures.filter((s) => s.scores.flags.leaky).length,
      fragile: structures.filter((s) => s.scores.flags.fragile).length,
      unprofitable: structures.filter((s) => s.scores.flags.unprofitable).length,
    },
  };

  const state = {
    period: model.period,
    periodLabel: model.period.labels[model.period.labels.length - 1],
    baseInputs,
    inputs,
    overrides: overrides ?? {},
    facts,
    factsHistory,
    sensitivities,
    ranking,
    structures,
    districts,
    byId: { structures: structuresById, districts: districtsById },
    city,
    rules,
    skills: skills?.skills ?? [],
    meta: { city: cityDef.meta, model: model.meta },
  };
  state.mayor = mayorBrief(state);
  state.alerts = buildAlerts(state);
  return state;
}

function resolveFlow(flow, facts) {
  return {
    in: flow.in ? facts[flow.in] ?? 0 : 0,
    out: flow.out ? facts[flow.out] ?? 0 : 0,
    leak: flow.leak ? facts[flow.leak] ?? 0 : 0,
    load: flow.load ? facts[flow.load] ?? 0 : 0,
  };
}

/** Share of the city that sits downstream of each district (reachability). */
function computeDownstreamWeights(districts) {
  const map = Object.fromEntries(districts.map((d) => [d.id, d]));
  const n = districts.length - 1;
  const out = {};
  for (const d of districts) {
    const seen = new Set();
    const stack = [...(d.downstream_ids ?? [])];
    while (stack.length) {
      const id = stack.pop();
      if (seen.has(id) || id === d.id) continue;
      seen.add(id);
      for (const nx of map[id]?.downstream_ids ?? []) stack.push(nx);
    }
    out[d.id] = n > 0 ? seen.size / n : 0;
  }
  out.cityhall = 0.5;
  return out;
}

function metricNameFor(key, structures) {
  for (const s of structures) for (const m of s.metrics) if (m.source === key) return m.name;
  return null;
}
function fmtFor(key, structures) {
  if (LEVERS[key]) return LEVERS[key].fmt;
  for (const s of structures) for (const m of s.metrics) if (m.source === key) return m.fmt;
  if (/revenue|contribution|net$|cac/.test(key)) return 'money';
  if (/rate|share|conv|margin|utilization/.test(key)) return 'pct';
  return 'num';
}

function buildAlerts(state) {
  const alerts = [];
  for (const s of state.structures) {
    const f = s.scores.flags;
    if (f.critical) alerts.push({ level: 'red', structure: s.def.id, text: `צוואר בקבוק קריטי: ${s.def.name_he}` });
    else if (f.overloaded) alerts.push({ level: 'red', structure: s.def.id, text: `עומס יתר: ${s.def.name_he} (${Math.round(s.scores.maxLoad * 100)}%)` });
    else if (f.weak) alerts.push({ level: 'yellow', structure: s.def.id, text: `מבנה חלש: ${s.def.name_he} (${Math.round(s.scores.health)})` });
    if (f.fragile && !f.weak) alerts.push({ level: 'yellow', structure: s.def.id, text: `מגמה שלילית 3 תקופות: ${s.def.name_he}` });
    if (f.leaky && !f.critical) alerts.push({ level: 'yellow', structure: s.def.id, text: `דליפה: ${s.def.name_he}` });
  }
  const order = { red: 0, yellow: 1 };
  return alerts.sort((a, b) => order[a.level] - order[b.level]).slice(0, 12);
}

export { simulate, applyChanges };
