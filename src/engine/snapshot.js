/** Compact, serialisable view of a computed city — what the skills read. */
export function snapshotOf(city) {
  const r = (v) => (typeof v === 'number' && Number.isFinite(v) ? Math.round(v * 1000) / 1000 : v);
  return {
    generatedAt: new Date().toISOString(),
    period: city.periodLabel,
    overrides: city.overrides,
    city: { health: r(city.city.health), status: city.city.status, counts: city.city.counts },
    facts: Object.fromEntries(Object.entries(city.facts).map(([k, v]) => [k, r(v)])),
    inputs: city.inputs,
    districts: city.districts.map((d) => ({
      id: d.def.id, name: d.def.name, name_he: d.def.name_he, stage_order: d.def.stage_order,
      upstream_ids: d.def.upstream_ids, downstream_ids: d.def.downstream_ids, owner_skill: d.def.owner_skill,
      scores: mapScores(d.scores), flow: d.flow,
      kpis: d.kpis.map((k) => ({ key: k.key, label: k.label, value: r(k.value) })),
    })),
    structures: city.structures.map((s) => ({
      id: s.def.id, district_id: s.def.district_id, name: s.def.name, name_he: s.def.name_he, type: s.def.type,
      owner_skill: s.def.owner_skill, primary_lever: s.def.primary_lever,
      scores: mapScores(s.scores), status: s.scores.status, flags: s.scores.flags, visual_state: s.scores.visual,
      metrics: s.metrics.map((m) => ({ id: m.id, name: m.name, source: m.source, value: r(m.value), target: m.target, fmt: m.fmt, score: r(m.score), status: m.status, trend: { dir: m.trend.dir, delta: r(m.trend.delta), consecutiveWorse: m.trend.consecutiveWorse }, history: m.history.map(r) })),
      root_causes: s.rootCauses.map((c) => ({ id: c.id, title: c.title, description: c.description, confidence: r(c.confidence), impact: c.impact, evidence: c.evidence.map((e) => ({ name: e.name, value: r(e.value), status: e.status })), actions: (c.actions ?? []).map((a) => ({ id: a.id, title: a.title, lever: a.lever, set: a.set, add: a.add, mul: a.mul, also: a.also, effort: a.effort, owner_skill: a.owner_skill })) })),
    })),
    sensitivities: city.ranking.map((s) => ({ lever: s.lever, from: r(s.from), to: r(s.to), dContribution: r(s.dContribution), dJoined: r(s.dJoined), dUtilization: r(s.dUtilization) })),
    mayor: {
      narrative: city.mayor.narrative,
      state: { ...city.mayor.state, weakDistricts: city.mayor.state.weakDistricts.map((d) => d.id) },
      weakest: city.mayor.weakest.map((s) => ({ id: s.def.id, name_he: s.def.name_he, health: r(s.scores.health) })),
      bottlenecks: city.mayor.bottlenecks.map((s) => ({ id: s.def.id, name_he: s.def.name_he, bottleneck: r(s.scores.bottleneck), health: r(s.scores.health) })),
      primary_bottleneck: city.mayor.primaryBottleneck?.def.id,
      moves: city.mayor.moves.map((m) => ({ rank: m.rank, title: m.action.title, structure: m.structure.def.id, cause: m.cause.id, changes: m.changes, impact: Object.fromEntries(Object.entries(m.impact).map(([k, v]) => [k, r(v)])), second_order: m.secondOrder.map((e) => e.text), effort: m.effort, owner_skill: m.ownerSkill, priority: m.priority })),
      monitor: city.mayor.monitor,
    },
    alerts: city.alerts,
  };
}

function mapScores(s) {
  const r = (v) => (typeof v === 'number' ? Math.round(v) : v);
  return { health: r(s.health), size: r(s.size), strength: r(s.strength), risk: r(s.risk), bottleneck: r(s.bottleneck) };
}
