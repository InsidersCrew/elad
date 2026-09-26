import { h, icon, btn, clear, statusChip, scoreBar, kpi, scoreColor, deltaChip } from './dom.js';
import { sparkline } from './sparkline.js';
import { fmtValue, fmtDelta } from '../../engine/format.js';
import { simulate } from '../../engine/model.js';
import { applyAction, actionChanges } from '../../engine/rootcause.js';
import { LEVERS } from '../../engine/levers.js';

const FLAG_HE = { weak: ['חלש', 'red'], small: ['קטן מהיעד', 'yellow'], overloaded: ['עומס יתר', 'red'], leaky: ['דליפה', 'red'], fragile: ['שביר (מגמה)', 'yellow'], unprofitable: ['כלכלית חלש', 'red'], critical: ['צוואר בקבוק קריטי', 'red'] };
const TREND_ARROW = { up: '↑', down: '↓', flat: '→' };

export function createDetailPanel(root, store, ui, { focusDistrict }) {
  const skillOf = (id) => store.current.skills.find((s) => s.id === id);

  function render() {
    const sel = store.selected;
    if (!sel) {
      root.hidden = true;
      return;
    }
    root.hidden = false;
    clear(root);
    if (sel.type === 'structure') renderStructure(store.current.byId.structures[sel.id]);
    else renderDistrict(store.current.byId.districts[sel.id]);
  }

  function head(title, sub, extra) {
    return h('div', { class: 'panel-head' },
      extra,
      h('div', { class: 'grow', style: { minWidth: 0 } }, h('h2', {}, title), h('div', { class: 'sub' }, sub)),
      h('div', { class: 'spacer' }),
      h('button', { class: 'iconbtn', title: 'סגור', onClick: () => store.select(null) }, icon('close')),
    );
  }

  function flagChips(flags) {
    return h('div', { class: 'chips' }, ...Object.entries(flags).filter(([, v]) => v).map(([k]) => h('span', { class: `chip ${FLAG_HE[k]?.[1] ?? ''}` }, FLAG_HE[k]?.[0] ?? k)));
  }

  function renderStructure(s) {
    if (!s) return;
    const c = store.current;
    const d = c.byId.districts[s.def.district_id];
    const skill = skillOf(s.def.owner_skill);
    root.append(head(s.def.name_he, `${s.def.name} · ${d.def.name_he}`, h('button', { class: 'iconbtn', title: 'לרובע', onClick: () => store.select({ type: 'district', id: d.def.id }) }, icon('back'))));
    const body = h('div', { class: 'panel-body' });
    root.append(body);

    body.append(h('div', { class: 'row', style: { gap: '6px', flexWrap: 'wrap' } }, statusChip(s.scores.status), flagChips(s.scores.flags)));
    body.append(h('p', { class: 'small', style: { margin: '8px 0 0' } }, s.def.description));

    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'ציונים'),
      scoreBar('Health', s.scores.health, scoreColor(s.scores.health)),
      scoreBar('Size', s.scores.size, 'var(--brand-accent)'),
      scoreBar('Strength', s.scores.strength, scoreColor(s.scores.strength)),
      scoreBar('Risk', s.scores.risk, s.scores.risk > 60 ? 'var(--danger)' : s.scores.risk > 40 ? 'var(--warning)' : 'var(--success)'),
      scoreBar('Bottleneck', s.scores.bottleneck, s.scores.bottleneck > 60 ? 'var(--danger)' : s.scores.bottleneck > 35 ? 'var(--warning)' : 'var(--brand-sky)'),
    ));

    // KPIs
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'KPI עיקריים', h('span', { class: 'n' }, `${c.period.labels.length} תקופות`)),
      ...s.metrics.map((m) => {
        const tr = m.trend;
        const trendCls = tr.improving === true ? 'pos' : tr.improving === false ? 'neg' : 'zero';
        return h('div', { class: 'metric', title: `${m.name} · יעד ${fmtValue(m.target, m.fmt)} · ציון ${Math.round(m.score)}` },
          h('span', { class: `dot ${m.status}` }),
          h('div', { class: 'name' }, m.name, h('small', {}, `יעד ${fmtValue(m.target, m.fmt)} · משקל ${m.weight}`)),
          h('div', { class: 'val' }, fmtValue(m.value, m.fmt), h('small', { class: `trend ${trendCls}` }, `${TREND_ARROW[tr.dir]} ${fmtDelta(tr.delta, m.fmt)}${tr.consecutiveWorse >= 3 ? ' · 3↓' : ''}`)),
          sparkline(m.history, { better: m.better }),
        );
      }),
    ));

    // Root causes
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'למה זה קורה', h('span', { class: 'n' }, 'Root Cause Engine')),
      s.rootCauses.length ? s.rootCauses.map((rc, i) => causeCard(rc, i === 0)) : h('div', { class: 'empty' }, 'לא זוהו סיבות — המבנה בטווח תקין'),
    ));

    // Suggested fixes with simulated impact
    const actions = s.rootCauses.flatMap((rc) => (rc.actions ?? []).map((a) => ({ a, rc })));
    if (actions.length) {
      body.append(h('div', { class: 'section' },
        h('div', { class: 'section-title' }, 'מהלכים מוצעים', h('span', { class: 'n' }, 'השפעה מסומלצת')),
        ...actions.map(({ a, rc }) => fixCard(a, rc)),
      ));
    }

    // Owner skill
    if (skill) {
      body.append(h('div', { class: 'section' },
        h('div', { class: 'section-title' }, 'סקיל אחראי'),
        h('div', { class: 'card' },
          h('div', { class: 'row' }, icon('skill'), h('div', { class: 'grow' }, h('h4', {}, skill.name_he), h('div', { class: 'meta' }, skill.name)), btn('הפעל סקיל', { icon: 'play', cls: 'small primary', onClick: () => ui.open('skill', { id: skill.id, structureId: s.def.id }) })),
          h('p', {}, skill.role),
        ),
      ));
    }

    // Linked impact
    body.append(linkedImpact(d));
  }

  function causeCard(rc, open) {
    const card = h('div', { class: `card cause ${open ? 'open' : ''}` });
    const titleRow = h('div', { class: 'title', onClick: () => card.classList.toggle('open') },
      h('span', { class: 'chip' }, icon('chevron')),
      h('h4', { class: 'grow' }, rc.title),
      h('span', { class: 'conf' }, `${Math.round(rc.confidence * 100)}%`),
    );
    const bodyEl = h('div', { class: 'body' },
      h('p', {}, rc.description),
      rc.impact ? h('div', { class: 'impact' }, '⇒ ', rc.impact) : null,
      h('div', { class: 'evidence' }, ...rc.evidence.map((e) => h('span', { class: `chip ${e.status ?? ''}` }, e.name, h('span', { class: 'num' }, fmtValue(e.value, e.fmt))))),
    );
    card.append(titleRow, bodyEl);
    return card;
  }

  function fixCard(a, rc) {
    const base = store.current.facts;
    const inputs = store.current.inputs;
    const changes = actionChanges(inputs, a);
    const alt = simulate(applyAction(inputs, a));
    const dC = alt.contribution - base.contribution;
    const dJ = alt.joined - base.joined;
    const dU = alt.rep_utilization - base.rep_utilization;
    const skill = skillOf(a.owner_skill);
    return h('div', { class: 'card move' },
      h('div', { class: 'title' }, a.title),
      h('div', { class: 'meta' }, changes.map((ch) => `${LEVERS[ch.lever]?.label ?? ch.lever}: ${fmtValue(ch.from, LEVERS[ch.lever]?.fmt)} → ${fmtValue(ch.to, LEVERS[ch.lever]?.fmt)}`).join(' · ')),
      h('div', { class: 'impacts' },
        deltaChip('תרומה', dC, 'money'),
        Math.abs(dJ) >= 0.5 ? deltaChip('תלמידים', dJ, 'int') : null,
        Math.abs(dU) >= 0.005 ? deltaChip('ניצולת', dU, 'pct', { betterUp: false }) : null,
      ),
      h('div', { class: 'foot' },
        h('span', { class: 'chip' }, { low: 'מאמץ נמוך', medium: 'מאמץ בינוני', high: 'מאמץ גבוה' }[a.effort] ?? a.effort),
        skill ? h('span', { class: 'chip blue' }, skill.name_he) : null,
        h('span', { style: { flex: 1 } }),
        changes.length ? btn('סמלץ', { icon: 'play', cls: 'small accent', onClick: () => { store.setOverrides(Object.fromEntries(changes.map((ch) => [ch.lever, ch.to]))); ui.open('scenario'); } }) : null,
      ),
    );
  }

  function linkedImpact(d) {
    const c = store.current;
    const up = (d.def.upstream_ids ?? []).map((id) => c.byId.districts[id]).filter(Boolean);
    const down = (d.def.downstream_ids ?? []).map((id) => c.byId.districts[id]).filter(Boolean);
    const link = (x, arrow) => h('div', { class: 'card clickable link-district', onClick: () => store.select({ type: 'district', id: x.def.id }) },
      h('span', { class: 'arrow' }, arrow), h('div', { class: 'grow' }, h('h4', {}, x.def.name_he), h('div', { class: 'meta' }, x.def.name)), statusChip(x.scores.status, Math.round(x.scores.health)));
    return h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'השפעה במעלה ובמורד הזרם'),
      d.flow ? h('div', { class: 'flow-strip' },
        h('div', { class: 'step' }, h('div', { class: 'v' }, fmtValue(d.flow.in, 'int')), h('div', { class: 'l' }, 'נכנס')),
        h('div', { class: 'step' }, h('div', { class: 'v' }, fmtValue(d.flow.out, 'int')), h('div', { class: 'l' }, 'יוצא')),
        d.flow.leak ? h('div', { class: 'step leak' }, h('div', { class: 'v' }, fmtValue(d.flow.leak, 'int')), h('div', { class: 'l' }, 'דולף')) : null,
        d.flow.load ? h('div', { class: `step ${d.flow.load > 0.85 ? 'leak' : ''}` }, h('div', { class: 'v' }, fmtValue(d.flow.load, 'pct')), h('div', { class: 'l' }, 'עומס')) : null,
      ) : null,
      ...up.map((x) => link(x, '↑')),
      ...down.map((x) => link(x, '↓')),
    );
  }

  function renderDistrict(d) {
    if (!d) return;
    const c = store.current;
    const skill = skillOf(d.def.owner_skill);
    root.append(head(d.def.name_he, `${d.def.name} · שלב ${d.def.stage_order}`, null));
    const body = h('div', { class: 'panel-body' });
    root.append(body);
    body.append(h('div', { class: 'row', style: { gap: '6px', flexWrap: 'wrap' } }, statusChip(d.scores.status, `Health ${Math.round(d.scores.health)}`), flagChips(d.scores.flags), h('span', { style: { flex: 1 } }), btn('התמקד', { icon: 'play', cls: 'small', onClick: () => focusDistrict(d.def.id) })));
    body.append(h('p', { class: 'small', style: { margin: '8px 0 0' } }, d.def.description));
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'KPI עיקריים'),
      h('div', { class: 'kpi-grid' }, ...d.kpis.map((k) => kpi(k.label, k.value, k.fmt))),
    ));
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'מבנים', h('span', { class: 'n' }, `${d.structures.length}`)),
      ...[...d.structures].sort((a, b) => a.scores.health - b.scores.health).map((s) => h('div', { class: 'card clickable', onClick: () => store.select({ type: 'structure', id: s.def.id }) },
        h('div', { class: 'row' }, h('div', { class: 'grow' }, h('h4', {}, s.def.name_he), h('div', { class: 'meta' }, s.def.name)), statusChip(s.scores.status, Math.round(s.scores.health))),
        h('div', { class: 'row', style: { gap: '10px', marginTop: '6px' } }, h('span', { class: 'small' }, `Size ${Math.round(s.scores.size)}`), h('span', { class: 'small' }, `Strength ${Math.round(s.scores.strength)}`), h('span', { class: 'small' }, `Bottleneck ${Math.round(s.scores.bottleneck)}`)),
        s.rootCauses[0] ? h('p', {}, '↳ ', s.rootCauses[0].title) : null,
      )),
    ));
    if (skill) {
      body.append(h('div', { class: 'section' },
        h('div', { class: 'section-title' }, 'סקיל אחראי'),
        h('div', { class: 'card' }, h('div', { class: 'row' }, icon('skill'), h('div', { class: 'grow' }, h('h4', {}, skill.name_he), h('div', { class: 'meta' }, skill.name)), btn('הפעל סקיל', { icon: 'play', cls: 'small primary', onClick: () => ui.open('skill', { id: skill.id }) }))),
      ));
    }
    body.append(linkedImpact(d));
  }

  store.on('select', render);
  store.on('state', render);
  render();
  return { render };
}
