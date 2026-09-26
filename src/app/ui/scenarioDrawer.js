import { h, icon, btn, clear, deltaChip } from './dom.js';
import { LEVERS, LEVER_GROUPS, SCENARIO_LEVERS } from '../../engine/levers.js';
import { fmtValue, fmtDelta, fmtPctChange } from '../../engine/format.js';
import { diffCities } from '../../engine/scenario.js';
import { toast } from './toast.js';

export function createScenarioDrawer(root, store, ui) {
  let group = 'fitcall';
  let sliders = new Map();
  let outcomesEl;
  let tabsEl;
  let slidersEl;
  let savedEl;
  let built = false;

  const presets = () => {
    const b = store.base.baseInputs;
    return [
      { label: 'Fit Call 28% → 36%', changes: { fit_conv: 0.36 } },
      { label: 'שיחה 18 → 13 דק׳', changes: { call_duration_min: 13 } },
      { label: '+1 נציג', changes: { reps: b.reps + 1 } },
      { label: 'אונבורדינג קבוצתי 80%', changes: { group_share: 0.8, onboarding_attendance: 0.72 } },
      { label: 'Open rate 60%', changes: { open_rate: 0.6 } },
      { label: 'גבייה 85% + ויתור 12%', changes: { collection_rate: 0.85, waiver_rate: 0.12 } },
      { label: 'אימון: המרה 42% · ₪7,500', changes: { coaching_conv: 0.42, revenue_per_deal: 7500 } },
      { label: 'הפניות ×2', changes: { referral_request_rate: 0.5, referral_rate: 0.18 } },
      { label: '+30% לידים ממומנים', changes: { leads_paid: Math.round(b.leads_paid * 1.3) } },
    ];
  };

  function build() {
    clear(root);
    root.append(h('div', { class: 'panel-head' },
      icon('scenario'),
      h('div', {}, h('h2', {}, 'סימולציה — מה יקרה אם…'), h('div', { class: 'sub' }, 'הזיזו ידית — העיר, ראש העיר והדוחות מגיבים מיד')),
      h('div', { class: 'spacer' }),
      btn('איפוס', { icon: 'reset', cls: 'small', onClick: () => store.resetScenario() }),
      btn('שמור תרחיש', { icon: 'download', cls: 'small', onClick: saveScenario }),
      btn('דוח What-if', { icon: 'report', cls: 'small accent', onClick: () => ui.open('reports', 'whatif') }),
      h('button', { class: 'iconbtn', title: 'סגור', onClick: () => ui.close('scenario') }, icon('close')),
    ));
    const body = h('div', { class: 'panel-body' });
    root.append(body);
    const presetsEl = h('div', { class: 'presets' }, ...presets().map((p) => h('button', { onClick: () => { store.setOverrides(p.changes); toast(`הוחל: ${p.label}`, { ok: true }); } }, p.label)));
    tabsEl = h('div', { class: 'drawer-tabs' });
    slidersEl = h('div', { class: 'sliders' });
    outcomesEl = h('div', {});
    savedEl = h('div', {});
    body.append(h('div', { class: 'drawer-grid' },
      h('div', {}, presetsEl, tabsEl, slidersEl),
      h('div', {}, h('div', { class: 'section-title' }, 'תוצאות', h('span', { class: 'n' }, 'מול מצב הבסיס')), outcomesEl, savedEl),
    ));
    built = true;
    buildTabs();
    buildSliders();
    updateOutcomes();
  }

  function buildTabs() {
    clear(tabsEl);
    for (const g of LEVER_GROUPS) {
      const levers = SCENARIO_LEVERS.filter((id) => LEVERS[id].group === g.id);
      if (!levers.length) continue;
      const changed = levers.some((id) => id in store.overrides);
      tabsEl.append(h('button', { class: `${g.id === group ? 'active' : ''} ${changed ? 'changed' : ''}`, onClick: () => { group = g.id; buildTabs(); buildSliders(); } }, g.label));
    }
  }

  function buildSliders() {
    clear(slidersEl);
    sliders = new Map();
    const base = store.base.baseInputs;
    for (const id of SCENARIO_LEVERS.filter((l) => LEVERS[l].group === group)) {
      const def = LEVERS[id];
      const cur = store.current.inputs[id];
      const input = h('input', { type: 'range', min: def.min, max: def.max, step: def.step, value: cur });
      const curEl = h('span', { class: 'cur' }, fmtValue(cur, def.fmt));
      const wrap = h('div', { class: 'slider' },
        h('span', { class: 'lbl', title: def.label }, def.label),
        h('span', { class: 'vals' }, h('span', { class: 'base' }, fmtValue(base[id], def.fmt)), ' → ', curEl),
        input,
      );
      input.addEventListener('input', () => {
        const v = Number(input.value);
        store.setOverrides({ [id]: v });
      });
      slidersEl.append(wrap);
      sliders.set(id, { input, curEl, wrap, def });
    }
    syncSliders();
  }

  function syncSliders() {
    const base = store.base.baseInputs;
    for (const [id, s] of sliders) {
      const cur = store.current.inputs[id];
      if (document.activeElement !== s.input) s.input.value = cur;
      s.curEl.textContent = fmtValue(cur, s.def.fmt);
      const changed = Math.abs(cur - base[id]) > 1e-9;
      s.curEl.classList.toggle('changed', changed);
      s.wrap.classList.toggle('changed', changed);
      const p = ((cur - s.def.min) / (s.def.max - s.def.min)) * 100;
      s.input.style.setProperty('--p', `${p}%`);
    }
  }

  function updateOutcomes() {
    if (!built) return;
    clear(outcomesEl);
    const base = store.base;
    const cur = store.current;
    if (!store.hasScenario()) {
      outcomesEl.append(h('div', { class: 'empty' }, 'הזיזו ידית או בחרו תרחיש מוכן — כאן יופיע מה ישתנה בעיר.'));
      renderSaved();
      return;
    }
    const d = diffCities(base, cur);
    const keys = ['joined', 'opened', 'total_revenue', 'contribution', 'net', 'rep_utilization', 'cac', 'cash_tied_up'];
    outcomesEl.append(h('div', { class: 'outcomes' }, ...d.outcomes.filter((o) => keys.includes(o.key)).map((o) => {
      const betterUp = !['rep_utilization', 'cac', 'cash_tied_up', 'revenue_leakage'].includes(o.key);
      const good = o.delta === 0 ? 'zero' : (betterUp ? o.delta > 0 : o.delta < 0) ? 'pos' : 'neg';
      return h('div', { class: 'outcome' }, h('div', { class: 'l' }, o.label), h('div', { class: 'v' }, fmtValue(o.to, o.fmt)), h('div', { class: `d ${good}` }, `${o.text} (${fmtPctChange(o.pct)})`));
    })));
    outcomesEl.append(h('div', { class: 'bottleneck-shift' },
      h('div', {}, 'בריאות העיר: ', h('span', { class: 'num' }, `${Math.round(d.cityHealth.from)} → ${Math.round(d.cityHealth.to)}`)),
      h('div', {}, 'צוואר בקבוק: ', h('b', {}, d.bottleneck.from?.name_he ?? '—'), ' → ', h('b', {}, d.bottleneck.to?.name_he ?? '—'), d.bottleneck.moved ? ' (עבר)' : ' (נשאר)'),
    ));
    if (d.secondOrder.length) outcomesEl.append(h('div', { class: 'second-order', style: { marginTop: '8px' } }, h('div', { class: 'section-title' }, 'אפקטים מסדר שני'), h('ul', {}, ...d.secondOrder.map((e) => h('li', { class: e.kind }, e.text)))));
    if (d.structures.length) {
      outcomesEl.append(h('div', { class: 'section-title', style: { marginTop: '10px' } }, 'מבנים שהשתנו', h('span', { class: 'n' }, d.structures.length)));
      outcomesEl.append(h('div', { class: 'chips' }, ...d.structures.slice(0, 8).map((s) => h('span', { class: `chip ${s.dHealth > 0 ? 'green' : s.dHealth < 0 ? 'red' : ''}`, style: { cursor: 'pointer' }, onClick: () => ui.selectStructure(s.id) }, `${s.name} `, h('span', { class: 'num' }, fmtDelta(s.dHealth, 'num'))))));
    }
    outcomesEl.append(h('div', { class: 'drawer-actions' }, btn('העתק תרחיש כ-CLI', { icon: 'copy', cls: 'small ghost', onClick: () => { const args = Object.entries(store.overrides).map(([k, v]) => `--set ${k}=${+v.toFixed(4)}`).join(' '); navigator.clipboard?.writeText(`npm run mayor -- ${args}`).then(() => toast('הועתק', { ok: true })); } })));
    renderSaved();
  }

  function renderSaved() {
    clear(savedEl);
    if (!store.savedScenarios.length) return;
    savedEl.append(h('div', { class: 'section-title', style: { marginTop: '12px' } }, 'תרחישים שמורים'));
    savedEl.append(h('div', { class: 'chips' }, ...store.savedScenarios.map((sc, i) => h('span', { class: 'chip blue', style: { cursor: 'pointer' } },
      h('span', { onClick: () => store.setOverrides(sc.changes, { merge: false }) }, sc.name),
      h('span', { style: { opacity: 0.6, marginInlineStart: '4px' }, title: 'מחק', onClick: (e) => { e.stopPropagation(); store.deleteScenario(i); } }, '×'),
    ))));
  }

  function saveScenario() {
    if (!store.hasScenario()) return toast('אין שינויים לשמירה');
    const name = Object.entries(store.overrides).map(([k, v]) => `${LEVERS[k]?.label ?? k} ${fmtValue(v, LEVERS[k]?.fmt)}`).join(' · ').slice(0, 60);
    store.saveScenario(name);
    toast('התרחיש נשמר', { ok: true });
  }

  store.on('state', () => { if (!built) return; syncSliders(); buildTabs(); updateOutcomes(); });
  store.on('ui', ({ name, open }) => { if (name === 'scenario' && open && !built) build(); });
  return { build: () => { if (!built) build(); } };
}
