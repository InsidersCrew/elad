import { h, btn, clear, icon } from './dom.js';
import { openModal, closeModal } from './modal.js';
import { LEVERS, LEVER_GROUPS } from '../../engine/levers.js';
import { snapshotOf } from '../../engine/snapshot.js';
import { fmtValue } from '../../engine/format.js';
import { normalizeModel, valuesAt, setValues, addPeriod, removeLastPeriod, renamePeriod, nextPeriodLabel, changedLevers } from '../../engine/periods.js';
import { toast, downloadText, copyText } from './toast.js';

/**
 * KPI ingestion by month: pick a period, edit its values, add the next month.
 * The last period is the city you see; earlier periods feed trends and the
 * Weekly review. Everything is saved through store.updateModel (localStorage
 * in the browser; export the JSON to update data/model.json in the repo).
 */
export function openData(store) {
  let model = normalizeModel(store.data.model);
  let period = model.period.labels.length - 1;
  let dirty = false;
  const fields = new Map();

  const periodBar = h('div', { class: 'period-bar' });
  const grid = h('div', { class: 'data-grid' });
  const labelInput = h('input', { type: 'text', class: 'period-name', value: model.period.labels[period], title: 'שם התקופה' });
  const status = h('div', { class: 'small' });

  function isLast() {
    return period === model.period.labels.length - 1;
  }

  function renderPeriodBar() {
    clear(periodBar);
    model.period.labels.forEach((l, i) => {
      const ch = changedLevers(model, i).length;
      periodBar.append(h('button', { class: `period-chip ${i === period ? 'active' : ''} ${i === model.period.labels.length - 1 ? 'current' : ''}`, onClick: () => { commitFields(); period = i; renderAll(); } },
        l, i === model.period.labels.length - 1 ? h('span', { class: 'tag' }, 'נוכחי') : null, ch ? h('span', { class: 'tag muted' }, ch) : null));
    });
    periodBar.append(h('button', { class: 'period-chip add', title: 'הוספת חודש: הערכים מועתקים מהחודש האחרון ואפשר לערוך אותם', onClick: () => {
      commitFields();
      try {
        model = addPeriod(model, nextPeriodLabel(model.period.labels.at(-1)));
        period = model.period.labels.length - 1;
        dirty = true;
        renderAll();
        toast(`נוסף חודש: ${model.period.labels[period]} — ערכו את המספרים ושמרו`, { ok: true });
      } catch (e) {
        toast(e.message);
      }
    } }, '+ חודש חדש'));
  }

  function renderGrid() {
    clear(grid);
    fields.clear();
    const vals = valuesAt(model, period);
    const prev = period > 0 ? valuesAt(model, period - 1) : null;
    for (const g of LEVER_GROUPS) {
      const ids = Object.keys(LEVERS).filter((id) => LEVERS[id].group === g.id);
      if (!ids.length) continue;
      grid.append(h('div', { class: 'group-title' }, g.label));
      for (const id of ids) {
        const def = LEVERS[id];
        const input = h('input', { type: 'number', step: def.fmt === 'pct' ? 0.1 : def.step, min: def.fmt === 'pct' ? def.min * 100 : def.min, max: def.fmt === 'pct' ? def.max * 100 : def.max, value: def.fmt === 'pct' ? +(vals[id] * 100).toFixed(2) : +vals[id].toFixed(4), id: `lever-${id}` });
        input.addEventListener('input', () => { dirty = true; input.classList.add('edited'); });
        fields.set(id, input);
        const changed = prev && Math.abs(prev[id] - vals[id]) > 1e-12;
        grid.append(h('div', { class: `data-row ${changed ? 'changed' : ''}` },
          h('label', { class: 'lbl', for: `lever-${id}`, title: id }, def.label, def.fmt === 'pct' ? ' (%)' : '', prev ? h('span', { class: 'prev' }, ` קודם: ${fmtValue(prev[id], def.fmt)}`) : null),
          input,
        ));
      }
    }
  }

  function commitFields() {
    const out = {};
    for (const [id, input] of fields) {
      if (input.value === '') continue;
      let v = Number(input.value);
      if (Number.isNaN(v)) continue;
      if (LEVERS[id].fmt === 'pct') v = v / 100;
      out[id] = v;
    }
    const next = setValues(model, period, out);
    if (labelInput.value.trim() && labelInput.value.trim() !== model.period.labels[period]) model = renamePeriod(next, period, labelInput.value);
    else model = next;
  }

  function renderAll() {
    renderPeriodBar();
    labelInput.value = model.period.labels[period];
    renderGrid();
    status.textContent = isLast()
      ? 'זו התקופה הנוכחית — העיר, ראש העיר והדוחות מחושבים ממנה. התקופות הקודמות משמשות למגמות (3 תקופות ברצף = שביר) ול-Weekly Review.'
      : 'עריכת תקופה קודמת משנה מגמות והשוואות, לא את מצב העיר הנוכחי.';
  }

  function save() {
    commitFields();
    store.updateModel(model, 'edited');
    dirty = false;
    toast(`נשמר: ${model.period.labels.length} תקופות, נוכחית ${model.period.labels.at(-1)}`, { ok: true });
    closeModal();
  }

  let armedRemove = false;
  const removeBtn = btn('מחק חודש אחרון', { icon: 'close', cls: 'small ghost', onClick: () => {
    if (model.period.labels.length <= 1) return toast('חייבת להישאר תקופה אחת לפחות');
    if (!armedRemove) {
      armedRemove = true;
      removeBtn.querySelector('.label').textContent = `למחוק את ${model.period.labels.at(-1)}? לחצו שוב`;
      removeBtn.classList.add('active');
      setTimeout(() => { armedRemove = false; removeBtn.querySelector('.label').textContent = 'מחק חודש אחרון'; removeBtn.classList.remove('active'); }, 4000);
      return;
    }
    commitFields();
    model = removeLastPeriod(model);
    period = model.period.labels.length - 1;
    dirty = true;
    armedRemove = false;
    removeBtn.querySelector('.label').textContent = 'מחק חודש אחרון';
    removeBtn.classList.remove('active');
    renderAll();
  } });

  let armedReset = false;
  const resetBtn = btn('איפוס לנתוני הדוגמה', { icon: 'reset', cls: 'small ghost', onClick: () => {
    if (!armedReset) {
      armedReset = true;
      resetBtn.querySelector('.label').textContent = 'בטוח? לחצו שוב לאיפוס';
      resetBtn.classList.add('active');
      setTimeout(() => { armedReset = false; resetBtn.querySelector('.label').textContent = 'איפוס לנתוני הדוגמה'; resetBtn.classList.remove('active'); }, 4000);
      return;
    }
    store.resetData();
    toast('אופס לנתוני הדוגמה', { ok: true });
    closeModal();
  } });

  const ta = h('textarea', { class: 'json', placeholder: 'הדביקו כאן JSON במבנה של data/model.json (period.labels + inputs + history), או רק {"inputs": {...}} לעדכון החודש הנוכחי' });
  const file = h('input', { type: 'file', accept: 'application/json', style: { display: 'none' } });
  file.addEventListener('change', async () => {
    const f = file.files?.[0];
    if (f) ta.value = await f.text();
  });

  const body = h('div', {},
    h('div', { class: 'section-title' }, 'תקופות', h('span', { class: 'n' }, 'חודש = תקופה · המספר הקטן = כמה ידיות השתנו מול החודש הקודם')),
    periodBar,
    h('div', { class: 'row', style: { gap: '8px', margin: '10px 0 6px', flexWrap: 'wrap' } },
      h('span', { class: 'small' }, 'שם התקופה:'), labelInput,
      h('span', { style: { flex: 1 } }),
      btn('שמור', { icon: 'download', cls: 'small primary', onClick: save }),
      removeBtn,
    ),
    status,
    grid,
    h('div', { class: 'divider' }),
    h('div', { class: 'section-title' }, 'ייצוא / ייבוא'),
    h('p', { class: 'small' }, 'הנתונים נשמרים בדפדפן הזה. כדי שהמאגר יהיה מקור האמת: ייצאו model.json והחליפו את data/model.json, או השתמשו ב-CLI: npm run period -- add "אוק׳ 26" fit_conv=0.31'),
    h('div', { class: 'row', style: { gap: '6px', flexWrap: 'wrap', marginBottom: '8px' } },
      btn('ייצא model.json', { icon: 'download', cls: 'small', onClick: () => { commitFields(); downloadText(JSON.stringify(model, null, 2), 'model.json', 'application/json'); } }),
      btn('העתק model.json', { icon: 'copy', cls: 'small', onClick: () => { commitFields(); copyText(JSON.stringify(model, null, 2), 'model.json הועתק'); } }),
      btn('ייצא Snapshot לסקילים', { icon: 'download', cls: 'small', onClick: () => downloadText(JSON.stringify(snapshotOf(store.current), null, 2), 'city-snapshot.json', 'application/json') }),
      btn('העתק Snapshot', { icon: 'copy', cls: 'small', onClick: () => copyText(JSON.stringify(snapshotOf(store.current)), 'Snapshot הועתק') }),
      resetBtn,
    ),
    ta,
    h('div', { class: 'row', style: { gap: '6px', marginTop: '8px', flexWrap: 'wrap' } },
      btn('בחר קובץ', { icon: 'data', cls: 'small', onClick: () => file.click() }),
      btn('ייבא', { icon: 'play', cls: 'small accent', onClick: () => {
        try {
          const obj = JSON.parse(ta.value);
          if (obj.inputs && obj.period?.labels) {
            model = normalizeModel({ ...store.data.model, ...obj });
          } else if (obj.inputs) {
            model = setValues(model, model.period.labels.length - 1, obj.inputs);
          } else throw new Error('חסר inputs');
          period = model.period.labels.length - 1;
          dirty = true;
          renderAll();
          toast('יובא — בדקו ולחצו "שמור"', { ok: true });
        } catch (e) {
          toast('JSON לא תקין: ' + e.message);
        }
      } }),
      file,
    ),
  );
  renderAll();
  openModal({
    title: 'נתוני העיר — הזנה לפי חודש',
    sub: `${model.period.labels.length} תקופות · מקור: ${store.data.model.meta?.source ?? '—'} · עודכן ${store.data.model.meta?.updated ?? '—'}`,
    body,
    onClose: () => { if (dirty) toast('שינויים שלא נשמרו נזרקו'); },
  });
}

export { icon };
