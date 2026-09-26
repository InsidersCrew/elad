import { h, icon, btn, statusChip, deltaChip } from './dom.js';
import { openModal } from './modal.js';
import { copyText } from './toast.js';
import { fmtValue, fmtDelta } from '../../engine/format.js';
import { simulate } from '../../engine/model.js';
import { applyAction, actionChanges } from '../../engine/rootcause.js';
import { LEVERS } from '../../engine/levers.js';
import { snapshotOf } from '../../engine/snapshot.js';

/** Deterministic "run" of a skill: its structures, their causes, simulated moves — plus a copy-able prompt for the AI skill. */
export function openSkill(store, { id, structureId }) {
  const city = store.current;
  const skill = city.skills.find((s) => s.id === id);
  if (!skill) return;
  const structures = id === 'mayor' ? city.mayor.bottlenecks : city.structures.filter((s) => skill.linked_structures?.includes(s.def.id));
  const focus = structureId ? city.byId.structures[structureId] : null;
  const inputs = city.inputs;
  const base = city.facts;

  const moves = [];
  for (const s of structures) {
    for (const rc of s.rootCauses) {
      for (const a of rc.actions ?? []) {
        const changes = actionChanges(inputs, a);
        if (!changes.length) continue;
        const alt = simulate(applyAction(inputs, a));
        moves.push({ s, rc, a, changes, dC: alt.contribution - base.contribution, dJ: alt.joined - base.joined, dU: alt.rep_utilization - base.rep_utilization });
      }
    }
  }
  moves.sort((x, y) => y.dC - x.dC);

  const prompt = buildPrompt(store, skill, structures, focus, moves);

  const body = h('div', {},
    h('div', { class: 'row', style: { gap: '8px', flexWrap: 'wrap' } },
      h('span', { class: 'chip purple' }, icon('skill'), skill.name),
      ...(skill.linked_districts ?? []).map((d) => h('span', { class: 'chip blue' }, city.byId.districts[d]?.def.name_he ?? d)),
      h('span', { class: 'chip' }, skill.skill_path),
    ),
    h('p', { style: { margin: '10px 0' } }, skill.role),
    skill.focus ? h('div', { class: 'chips' }, ...skill.focus.map((f) => h('span', { class: 'chip' }, f))) : null,

    h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'המבנים באחריות הסקיל'),
      ...structures.map((s) => h('div', { class: `card ${focus?.def.id === s.def.id ? 'spotlight' : ''}` },
        h('div', { class: 'row' }, h('div', { class: 'grow' }, h('h4', {}, s.def.name_he), h('div', { class: 'meta' }, s.def.name)), statusChip(s.scores.status, Math.round(s.scores.health))),
        s.rootCauses.length ? h('p', {}, '↳ ', s.rootCauses.map((c) => c.title).join(' · ')) : null,
      )),
    ),

    h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'מהלכים בתחום הסקיל', h('span', { class: 'n' }, 'מסומלץ על המודל')),
      moves.length ? moves.slice(0, 6).map((m) => h('div', { class: 'card move' },
        h('div', { class: 'title' }, m.a.title),
        h('div', { class: 'meta' }, `${m.s.def.name_he} · ${m.changes.map((ch) => `${LEVERS[ch.lever]?.label ?? ch.lever} ${fmtValue(ch.from, LEVERS[ch.lever]?.fmt)}→${fmtValue(ch.to, LEVERS[ch.lever]?.fmt)}`).join(' · ')}`),
        h('div', { class: 'impacts' }, deltaChip('תרומה', m.dC, 'money'), Math.abs(m.dJ) >= 0.5 ? deltaChip('תלמידים', m.dJ, 'int') : null, Math.abs(m.dU) >= 0.005 ? deltaChip('ניצולת', m.dU, 'pct', { betterUp: false }) : null),
      )) : h('div', { class: 'empty' }, 'אין מהלכים פתוחים — המבנים בתחום הסקיל בטווח תקין'),
    ),

    h('div', { class: 'section' },
      h('div', { class: 'section-title' }, 'הפעלת הסקיל ב-Claude', h('span', { class: 'n' }, 'Prompt + Snapshot')),
      h('p', { class: 'small' }, `הפרומפט כולל את ההגדרה של הסקיל ואת ה-Snapshot של העיר (המבנים בתחומו). העתיקו אותו ל-Claude, או הריצו את הסקיל מהמאגר: ${skill.skill_path}`),
      h('div', { class: 'row', style: { gap: '6px', margin: '8px 0' } },
        btn('העתק פרומפט מלא', { icon: 'copy', cls: 'small primary', onClick: () => copyText(prompt, 'הפרומפט הועתק') }),
        btn('העתק Snapshot בלבד', { icon: 'copy', cls: 'small', onClick: () => copyText(JSON.stringify(scopedSnapshot(store, skill, structures)), 'Snapshot הועתק') }),
      ),
      h('pre', { class: 'skill-prompt' }, prompt.slice(0, 2400) + (prompt.length > 2400 ? '\n… (הפרומפט המלא מועתק בלחיצה)' : '')),
    ),
  );
  openModal({ title: `הפעלת סקיל — ${skill.name_he}`, sub: store.hasScenario() ? 'על בסיס התרחיש הפעיל' : city.periodLabel, body });
}

function scopedSnapshot(store, skill, structures) {
  const snap = snapshotOf(store.current);
  const ids = new Set(structures.map((s) => s.def.id));
  return {
    generatedAt: snap.generatedAt,
    period: snap.period,
    skill: skill.id,
    city: snap.city,
    facts: snap.facts,
    structures: snap.structures.filter((s) => ids.has(s.id) || skill.id === 'mayor'),
    districts: snap.districts.filter((d) => skill.id === 'mayor' || skill.linked_districts?.includes(d.id)),
    sensitivities: snap.sensitivities,
    mayor: skill.id === 'mayor' ? snap.mayor : { primary_bottleneck: snap.mayor.primary_bottleneck, narrative: snap.mayor.narrative },
  };
}

function buildPrompt(store, skill, structures, focus, moves) {
  const c = store.current;
  const lines = [];
  if (skill.prompt) lines.push(skill.prompt);
  else {
    lines.push(`You are the ${skill.name} of INSIDERS CityOS — a specialist responsible for: ${skill.role}`);
    lines.push(`Focus areas: ${(skill.focus ?? []).join(', ')}.`);
    lines.push(`Return: ${(skill.output_schema ?? []).join(' · ')}. Think in end-to-end flow, capacity and economics — never local optimisation alone. Answer in Hebrew.`);
  }
  lines.push('');
  lines.push(`## City context (${c.periodLabel}${store.hasScenario() ? ', scenario active' : ''})`);
  lines.push(`City health ${Math.round(c.city.health)}/100. Revenue ${fmtValue(c.facts.total_revenue, 'money')}, net ${fmtValue(c.facts.net, 'money')}, joined ${fmtValue(c.facts.joined, 'int')}, rep utilization ${fmtValue(c.facts.rep_utilization, 'pct')}.`);
  lines.push(`Mayor narrative: ${c.mayor.narrative}`);
  if (focus) lines.push(`Focus structure: ${focus.def.name} (${focus.def.name_he}) — health ${Math.round(focus.scores.health)}.`);
  lines.push('');
  lines.push('## Structures in scope');
  for (const s of structures) {
    lines.push(`- ${s.def.name} [${s.scores.status}] health ${Math.round(s.scores.health)} size ${Math.round(s.scores.size)} strength ${Math.round(s.scores.strength)} risk ${Math.round(s.scores.risk)} bottleneck ${Math.round(s.scores.bottleneck)}`);
    for (const m of s.metrics) lines.push(`    · ${m.name}: ${fmtValue(m.value, m.fmt)} (target ${fmtValue(m.target, m.fmt)}, ${m.status}, trend ${m.trend.dir}${m.trend.consecutiveWorse >= 3 ? ', 3 periods worse' : ''})`);
    for (const rc of s.rootCauses) lines.push(`    ↳ cause (${Math.round(rc.confidence * 100)}%): ${rc.title} — ${rc.description}`);
  }
  if (moves.length) {
    lines.push('');
    lines.push('## Candidate moves already simulated on the operating model');
    for (const m of moves.slice(0, 8)) lines.push(`- ${m.a.title}: contribution ${fmtDelta(m.dC, 'money')}/month, joined ${fmtDelta(m.dJ, 'int')}, rep utilization ${fmtDelta(m.dU, 'pct')}`);
  }
  lines.push('');
  lines.push('## Snapshot (JSON)');
  lines.push(JSON.stringify(scopedSnapshot(store, skill, structures)));
  return lines.join('\n');
}
