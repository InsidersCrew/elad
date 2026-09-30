import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadData } from '../src/node/load.js';
import { buildCity } from '../src/engine/index.js';
import { simulate } from '../src/engine/model.js';
import { scoreMetric, trendOf } from '../src/engine/metrics.js';
import { computeSensitivities, improvedValue } from '../src/engine/sensitivity.js';
import { diffCities } from '../src/engine/scenario.js';
import { applyAction } from '../src/engine/rootcause.js';
import { dailyBrief, weeklyReview, monthlyStructural, whatIfReport } from '../src/engine/reports.js';
import { snapshotOf } from '../src/engine/snapshot.js';
import { LEVERS, SCENARIO_LEVERS } from '../src/engine/levers.js';

const data = loadData();
const city = buildCity(data);

test('model: the funnel is monotone and conserves flow', () => {
  const f = city.facts;
  assert.ok(f.leads_total > f.qualified, 'leads > qualified');
  assert.ok(f.qualified >= f.calls_completed, 'qualified >= calls');
  assert.ok(f.calls_completed >= f.joined, 'calls >= joined');
  assert.ok(f.joined >= f.started, 'joined >= started');
  assert.ok(f.started >= f.opened, 'started >= opened');
  assert.ok(Math.abs(f.opened + f.not_opened - f.started) < 1e-9, 'opened + not opened = started');
  assert.ok(f.total_revenue > 0 && Number.isFinite(f.contribution));
  assert.ok(f.rep_utilization > 0 && f.rep_utilization < 2);
});

test('model: every lever in data/model.json has a definition and vice versa', () => {
  for (const k of Object.keys(data.model.inputs)) assert.ok(LEVERS[k], `lever definition missing for ${k}`);
  for (const k of Object.keys(LEVERS)) assert.ok(k in data.model.inputs, `model input missing for ${k}`);
  for (const [k, arr] of Object.entries(data.model.history)) {
    assert.ok(LEVERS[k], `history for unknown lever ${k}`);
    assert.equal(arr.length, data.model.period.labels.length, `history length for ${k}`);
  }
});

test('city: every metric source resolves to a numeric fact', () => {
  for (const s of city.structures) {
    for (const m of s.metrics) {
      assert.ok(typeof m.value === 'number' && !Number.isNaN(m.value), `${s.def.id}.${m.id} (${m.source}) is not numeric`);
      assert.ok(m.score >= 0 && m.score <= 100, `${s.def.id}.${m.id} score out of range`);
    }
  }
  for (const d of city.districts) {
    if (!d.def.flow) continue;
    for (const key of ['in', 'out', 'leak', 'load']) {
      const src = d.def.flow[key];
      if (src) assert.ok(typeof city.facts[src] === 'number', `district ${d.def.id} flow.${key} → ${src} missing`);
    }
  }
});

test('city: every root cause rule references a known structure and known facts', () => {
  const ids = new Set(city.structures.map((s) => s.def.id));
  for (const r of data.rootcauses.rules) {
    assert.ok(ids.has(r.structure_id), `rule ${r.id} → unknown structure ${r.structure_id}`);
    for (const c of r.when) assert.ok(c.fact === 'health' || c.fact in city.facts, `rule ${r.id} condition on unknown fact ${c.fact}`);
    for (const e of r.evidence) assert.ok(e in city.facts, `rule ${r.id} evidence on unknown fact ${e}`);
    for (const a of r.actions ?? []) {
      assert.ok(LEVERS[a.lever], `rule ${r.id} action ${a.id} → unknown lever ${a.lever}`);
      for (const also of a.also ?? []) assert.ok(LEVERS[also.lever], `rule ${r.id} action ${a.id} also → unknown lever ${also.lever}`);
    }
  }
});

test('scoring: thresholds behave in both directions', () => {
  const up = { good: 0.33, warn: 0.28, target: 0.36, better: 'up' };
  assert.ok(scoreMetric(0.36, up) >= 99);
  assert.ok(scoreMetric(0.33, up) >= 85 && scoreMetric(0.33, up) < 86);
  assert.ok(scoreMetric(0.30, up) > 60 && scoreMetric(0.30, up) < 85);
  assert.ok(scoreMetric(0.20, up) < 60);
  const down = { good: 14, warn: 17, target: 13, better: 'down' };
  assert.ok(scoreMetric(13, down) >= 99);
  assert.ok(scoreMetric(18, down) < 60);
  assert.ok(scoreMetric(15, down) > 60 && scoreMetric(15, down) < 85);
  assert.equal(scoreMetric(Infinity, { good: 900, warn: 1100, better: 'down' }), 0);
});

test('scoring: fragile = 3 consecutive worse periods', () => {
  assert.equal(trendOf([0.33, 0.32, 0.31, 0.30], 'up').consecutiveWorse, 3);
  assert.equal(trendOf([0.30, 0.31, 0.32, 0.33], 'up').consecutiveWorse, 0);
  assert.equal(trendOf([15, 16, 17, 18], 'down').consecutiveWorse, 3);
  assert.equal(trendOf([15, 16, 15.5, 16], 'down').consecutiveWorse, 1);
});

test('rules: overloaded reps are flagged and become a bottleneck driver', () => {
  const overloaded = buildCity(data, { reps: 2 });
  const tower = overloaded.byId.structures.rep_capacity;
  assert.ok(tower.scores.flags.overloaded, 'rep capacity tower is overloaded with 2 reps');
  assert.ok(overloaded.facts.rep_utilization > 0.95);
  assert.ok(overloaded.facts.calls_overflow > 0, 'calls overflow with 2 reps');
  assert.ok(overloaded.facts.joined < city.facts.joined, 'fewer students with fewer reps');
});

test('sensitivity: improving a lever moves contribution in the right direction', () => {
  const sens = computeSensitivities(city.inputs, SCENARIO_LEVERS, 0.1);
  assert.ok(sens.fit_conv.dContribution > 0, 'better fit conversion → more contribution');
  assert.ok(sens.call_duration_min.dContribution >= 0, 'shorter calls never hurt');
  assert.ok(sens.open_rate.dContribution > 0);
  assert.ok(sens.waiver_rate.dContribution > 0, 'lower waiver → more contribution');
  assert.ok(improvedValue('call_duration_min', 18, 0.1) < 18);
  assert.ok(improvedValue('fit_conv', 0.28, 0.1) > 0.28);
  assert.equal(improvedValue('reps', 4, 0.1), 5);
});

test('scenario: the doc example — fit conversion 28% → 36% grows the city and shifts load', () => {
  const alt = buildCity(data, { fit_conv: 0.36 });
  const d = diffCities(city, alt);
  const joined = d.outcomes.find((o) => o.key === 'joined');
  assert.ok(joined.delta > 0, 'more students');
  assert.ok(alt.facts.contribution > city.facts.contribution);
  assert.ok(alt.facts.rep_utilization > city.facts.rep_utilization, 'second-order: more load on reps');
  assert.ok(d.secondOrder.some((e) => /עומס/.test(e.text)), 'second-order effect reported');
  assert.ok(alt.byId.structures.fit_conversion.scores.health > city.byId.structures.fit_conversion.scores.health);
});

test('scenario: call duration 18 → 13 releases rep capacity', () => {
  const alt = buildCity(data, { call_duration_min: 13 });
  assert.ok(alt.facts.rep_utilization < city.facts.rep_utilization);
  assert.ok(alt.facts.calls_capacity > city.facts.calls_capacity);
});

test('root causes: actions apply lever changes with clamping', () => {
  const a = { lever: 'fit_conv', set: 0.34, also: [{ lever: 'call_duration_min', add: -30 }] };
  const next = applyAction(city.inputs, a);
  assert.equal(next.fit_conv, 0.34);
  assert.equal(next.call_duration_min, LEVERS.call_duration_min.min);
});

test('mayor: brief has the eight required sections and simulated, positive moves', () => {
  const m = city.mayor;
  assert.equal(m.weakest.length, 3);
  assert.ok(m.bottlenecks.length >= 3);
  assert.ok(m.primaryBottleneck);
  assert.ok(m.moves.length >= 3, 'at least three recommended moves');
  for (const mv of m.moves) {
    assert.ok(mv.impact.dContribution > 0, `move ${mv.action.id} has positive impact`);
    assert.ok(['high', 'medium', 'low'].includes(mv.priority));
    assert.ok(mv.ownerSkill);
    assert.ok(mv.changes.length > 0);
  }
  assert.ok(m.narrative.length > 50);
  assert.ok(m.monitor.length >= 3);
  assert.ok(!/undefined|NaN/.test(m.narrative));
});

test('reports: all four generators produce markdown without NaN/undefined', () => {
  const alt = buildCity(data, { fit_conv: 0.36, call_duration_min: 13 });
  for (const md of [dailyBrief(city), weeklyReview(city), monthlyStructural(city), whatIfReport(city, alt, { fit_conv: 0.36, call_duration_min: 13 })]) {
    assert.ok(md.startsWith('# '));
    assert.ok(!/NaN|undefined/.test(md), md.match(/.{0,60}(NaN|undefined).{0,60}/)?.[0]);
  }
});

test('snapshot: serialisable and complete', () => {
  const snap = snapshotOf(city);
  const json = JSON.stringify(snap);
  assert.ok(json.length > 1000);
  assert.equal(snap.structures.length, city.structures.length);
  assert.ok(snap.mayor.moves.length >= 3);
  assert.ok(!/NaN/.test(json));
});

test('determinism: same inputs → same city', () => {
  const a = buildCity(data);
  const b = buildCity(data);
  assert.equal(a.city.health, b.city.health);
  assert.deepEqual(a.mayor.moves.map((m) => m.action.id), b.mayor.moves.map((m) => m.action.id));
  const s = simulate(city.inputs);
  assert.equal(s.joined, city.facts.joined);
});

test('periods: add / set / remove keep inputs and history in sync', async () => {
  const { addPeriod, setValues, removeLastPeriod, valuesAt, normalizeModel, nextPeriodLabel, changedLevers } = await import('../src/engine/periods.js');
  const m0 = normalizeModel(data.model);
  const n = m0.period.labels.length;
  assert.equal(nextPeriodLabel('ספט׳ 26'), 'אוק׳ 26');
  assert.equal(nextPeriodLabel('דצמ׳ 26'), 'ינו׳ 27');
  assert.equal(nextPeriodLabel('תקופה 3'), 'תקופה 4');

  const m1 = addPeriod(m0, 'אוק׳ 26', { fit_conv: 0.31, leads_paid: 1050 });
  assert.equal(m1.period.labels.length, n + 1);
  assert.equal(m1.inputs.fit_conv, 0.31);
  assert.equal(m1.inputs.leads_paid, 1050);
  assert.equal(m1.history.fit_conv.length, n + 1);
  assert.equal(m1.history.fit_conv[n - 1], m0.inputs.fit_conv, 'previous month untouched');
  assert.equal(valuesAt(m1, n).open_rate, m0.inputs.open_rate, 'unchanged levers carry forward');
  assert.ok(changedLevers(m1, n).includes('fit_conv'));
  assert.throws(() => addPeriod(m1, 'אוק׳ 26'), /כבר קיימת/);

  const m2 = setValues(m1, n - 1, { open_rate: 0.53 });
  assert.equal(valuesAt(m2, n - 1).open_rate, 0.53);
  assert.equal(m2.inputs.open_rate, m0.inputs.open_rate, 'editing a past month does not change the current one');

  const m3 = removeLastPeriod(m2);
  assert.equal(m3.period.labels.length, n);
  assert.equal(m3.inputs.fit_conv, m0.inputs.fit_conv);
  assert.equal(m3.inputs.open_rate, 0.53, 'previous month becomes current');
  const city2 = buildCity({ ...data, model: m1 });
  assert.equal(city2.periodLabel, 'אוק׳ 26');
  assert.equal(city2.factsHistory.length, n + 1);
  // the original data object was never mutated
  assert.equal(data.model.period.labels.length, n);
});
