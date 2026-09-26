import { h, icon, btn, clear, statusChip, scoreBar, kpi, scoreColor, deltaChip } from './dom.js';
import { fmtValue, fmtDelta, PRIORITY_LABEL } from '../../engine/format.js';

const EFFORT_HE = { low: 'מאמץ נמוך', medium: 'מאמץ בינוני', high: 'מאמץ גבוה' };

export function createMayorPanel(root, store, ui) {
  const skillName = (id) => store.current.skills.find((s) => s.id === id)?.name_he ?? id;

  function render() {
    const c = store.current;
    const m = c.mayor;
    const f = c.facts;
    const scenario = store.hasScenario();
    const ref = scenario ? store.base.facts : c.factsHistory[c.factsHistory.length - 2] ?? f;
    const refLabel = scenario ? 'מול מצב הבסיס' : 'מול התקופה הקודמת';
    clear(root);

    root.append(h('div', { class: 'panel-head' },
      icon('mayor'),
      h('div', {}, h('h2', {}, 'ראש העיר — Co-CEO'), h('div', { class: 'sub' }, `${c.periodLabel} · ${scenario ? 'תרחיש פעיל' : 'מצב נוכחי'}`)),
      h('div', { class: 'spacer' }),
      scenario ? h('span', { class: 'scenario-badge' }, 'סימולציה') : null,
      h('button', { class: 'iconbtn', title: 'סגור', onClick: () => ui.close('mayor') }, icon('close')),
    ));

    const body = h('div', { class: 'panel-body' });
    root.append(body);

    // 1. State of the city
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, '1 · מצב העיר', h('span', { class: 'n' }, refLabel)),
      h('div', { class: 'row', style: { marginBottom: '8px', gap: '6px', flexWrap: 'wrap' } },
        statusChip(m.state.status, `Health ${Math.round(m.state.health)}`),
        h('span', { class: 'chip' }, `${m.state.counts.weak} חלשים`),
        m.state.counts.critical ? h('span', { class: 'chip red' }, `${m.state.counts.critical} קריטיים`) : null,
        m.state.counts.overloaded ? h('span', { class: 'chip yellow' }, `${m.state.counts.overloaded} בעומס`) : null,
        m.state.counts.leaky ? h('span', { class: 'chip red' }, `${m.state.counts.leaky} דולפים`) : null,
        m.state.counts.fragile ? h('span', { class: 'chip yellow' }, `${m.state.counts.fragile} שבירים`) : null,
      ),
      h('div', { class: 'kpi-grid' },
        kpi('הכנסות / חודש', f.total_revenue, 'money', f.total_revenue - ref.total_revenue),
        kpi('רווח נקי', f.net, 'money', f.net - ref.net),
        kpi('תלמידים חדשים', f.joined, 'int', f.joined - ref.joined),
        kpi('ניצולת נציגים', f.rep_utilization, 'pct', f.rep_utilization - ref.rep_utilization),
      ),
      h('div', { class: 'flow-strip' },
        ...[['לידים', f.leads_total], ['Qualified', f.qualified], ['שיחות', f.calls_completed], ['Joined', f.joined], ['Started', f.started], ['נפתחו', f.opened]].map(([l, v]) => h('div', { class: 'step' }, h('div', { class: 'v' }, fmtValue(v, 'int')), h('div', { class: 'l' }, l))),
      ),
      h('div', { class: 'quote' }, m.narrative),
    ));

    // 2. Weakest 3
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, '2 · שלושת המבנים החלשים'),
      ...m.weakest.map((s) => h('div', { class: 'card clickable', onClick: () => ui.selectStructure(s.def.id) },
        h('div', { class: 'row' }, h('div', { class: 'grow' }, h('h4', {}, s.def.name_he), h('div', { class: 'meta' }, `${s.def.name} · ${c.byId.districts[s.def.district_id].def.name_he}`)), statusChip(s.scores.status, Math.round(s.scores.health))),
        scoreBar('Strength', s.scores.strength, scoreColor(s.scores.strength)),
        s.rootCauses[0] ? h('p', {}, '↳ ', s.rootCauses[0].title) : null,
      )),
    ));

    // 3. Bottleneck ranking
    const pb = m.primaryBottleneck;
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, '3 · צוואר הבקבוק הראשי'),
      pb ? h('div', { class: 'card spotlight clickable', onClick: () => ui.selectStructure(pb.def.id) },
        h('div', { class: 'row' }, h('div', { class: 'grow' }, h('h4', {}, pb.def.name_he), h('div', { class: 'meta' }, c.byId.districts[pb.def.district_id].def.name_he)), h('span', { class: 'chip purple' }, `Bottleneck ${Math.round(pb.scores.bottleneck)}`)),
        h('p', {}, pb.rootCauses[0]?.description ?? ''),
        pb.def.primary_lever && c.sensitivities[pb.def.primary_lever] ? h('div', { class: 'small', style: { marginTop: '6px' } }, `שיפור של 10% בידית הראשית = ${fmtDelta(c.sensitivities[pb.def.primary_lever].dContribution, 'money')} תרומה בחודש`) : null,
      ) : null,
      h('div', { style: { marginTop: '8px' } }, ...m.bottlenecks.map((s, i) => h('div', { class: 'scorebar', style: { cursor: 'pointer' }, onClick: () => ui.selectStructure(s.def.id) },
        h('span', { class: 'lbl', style: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, `${i + 1}. ${s.def.name_he}`),
        h('div', { class: 'track' }, h('div', { class: 'fill', style: { width: `${s.scores.bottleneck}%`, '--fill': i === 0 ? 'var(--danger)' : 'var(--warning)' } })),
        h('span', { class: 'val' }, Math.round(s.scores.bottleneck)),
      ))),
    ));

    // 4-8. Moves
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, '4 · מהלכים מומלצים', h('span', { class: 'n' }, 'השפעה מסומלצת')),
      m.moves.length ? m.moves.map((mv) => moveCard(mv)) : h('div', { class: 'empty' }, 'אין מהלכים עם השפעה חיובית'),
    ));

    // 9. Monitor
    body.append(h('div', { class: 'section' },
      h('div', { class: 'section-title' }, '5 · מה לנטר עכשיו'),
      ...m.monitor.map((it) => h('div', { class: 'monitor-row' }, h('span', {}, h('b', {}, it.metric), h('span', { class: 'small' }, ` · ${it.structure}`)), h('span', { class: 'val' }, `${it.value} → ${it.target}`), h('span', { class: 'why' }, it.why))),
    ));

    body.append(h('div', { class: 'section', style: { display: 'flex', gap: '6px', flexWrap: 'wrap' } },
      btn('Daily Brief', { icon: 'report', cls: 'small', onClick: () => ui.open('reports', 'daily') }),
      btn('Weekly Review', { icon: 'report', cls: 'small', onClick: () => ui.open('reports', 'weekly') }),
      btn('Structural Review', { icon: 'report', cls: 'small', onClick: () => ui.open('reports', 'monthly') }),
    ));
  }

  function moveCard(mv) {
    const changes = Object.fromEntries(mv.changes.map((ch) => [ch.lever, ch.to]));
    return h('div', { class: `card move p-${mv.priority}` },
      h('div', { class: 'row', style: { alignItems: 'flex-start' } },
        h('span', { class: 'rank' }, mv.rank),
        h('div', { class: 'grow' }, h('div', { class: 'title' }, mv.action.title), h('div', { class: 'meta' }, `${mv.structure.def.name_he} · ${mv.cause.title}`)),
      ),
      h('div', { class: 'impacts' },
        deltaChip('תרומה', mv.impact.dContribution, 'money'),
        Math.abs(mv.impact.dJoined) >= 0.5 ? deltaChip('תלמידים', mv.impact.dJoined, 'int') : null,
        Math.abs(mv.impact.dOpened) >= 0.5 ? deltaChip('חשבונות', mv.impact.dOpened, 'int') : null,
        Math.abs(mv.impact.dUtilization) >= 0.005 ? deltaChip('ניצולת', mv.impact.dUtilization, 'pct', { betterUp: false }) : null,
      ),
      mv.secondOrder.length ? h('div', { class: 'second-order' }, h('ul', {}, ...mv.secondOrder.map((e) => h('li', { class: e.kind }, e.text)))) : null,
      h('div', { class: 'foot' },
        h('span', { class: `chip ${mv.priority === 'high' ? 'purple' : mv.priority === 'medium' ? 'blue' : ''}` }, `עדיפות ${PRIORITY_LABEL[mv.priority]}`),
        h('span', { class: 'chip' }, EFFORT_HE[mv.effort] ?? mv.effort),
        h('span', { class: 'chip blue', style: { cursor: 'pointer' }, onClick: () => ui.open('skill', { id: mv.ownerSkill, structureId: mv.structure.def.id }) }, icon('skill'), skillName(mv.ownerSkill)),
        h('span', { class: 'spacer', style: { flex: 1 } }),
        btn('סמלץ', { icon: 'play', cls: 'small accent', onClick: () => { store.setOverrides(changes); ui.open('scenario'); } }),
      ),
    );
  }

  store.on('state', render);
  render();
  return { render };
}
