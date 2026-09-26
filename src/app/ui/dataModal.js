import { h, btn, clear } from './dom.js';
import { openModal, closeModal } from './modal.js';
import { LEVERS, LEVER_GROUPS } from '../../engine/levers.js';
import { snapshotOf } from '../../engine/snapshot.js';
import { toast, downloadText, copyText } from './toast.js';

export function openData(store) {
  const inputs = { ...store.data.model.inputs };
  const fields = new Map();

  const grid = h('div', { class: 'data-grid' });
  for (const g of LEVER_GROUPS) {
    const ids = Object.keys(LEVERS).filter((id) => LEVERS[id].group === g.id);
    if (!ids.length) continue;
    grid.append(h('div', { class: 'group-title' }, g.label));
    for (const id of ids) {
      const def = LEVERS[id];
      const input = h('input', { type: 'number', step: def.step, min: def.min, max: def.max, value: def.fmt === 'pct' ? +(inputs[id] * 100).toFixed(2) : inputs[id] });
      fields.set(id, input);
      grid.append(h('div', { class: 'data-row' }, h('span', { class: 'lbl', title: id }, def.label, def.fmt === 'pct' ? ' (%)' : ''), input));
    }
  }

  const ta = h('textarea', { class: 'json', placeholder: 'הדביקו כאן JSON במבנה של data/model.json (inputs + history) או רק {"inputs": {...}}' });
  const file = h('input', { type: 'file', accept: 'application/json', style: { display: 'none' } });
  file.addEventListener('change', async () => {
    const f = file.files?.[0];
    if (!f) return;
    ta.value = await f.text();
  });

  function readFields() {
    const out = {};
    for (const [id, input] of fields) {
      let v = Number(input.value);
      if (Number.isNaN(v)) continue;
      if (LEVERS[id].fmt === 'pct') v = v / 100;
      out[id] = Math.min(LEVERS[id].max, Math.max(LEVERS[id].min, v));
    }
    return out;
  }

  let armed = false;
  const resetBtn = btn('איפוס לנתוני הדוגמה', { icon: 'reset', cls: 'small ghost', onClick: () => {
    if (!armed) {
      armed = true;
      resetBtn.querySelector('.label').textContent = 'בטוח? לחצו שוב לאיפוס';
      resetBtn.classList.add('active');
      setTimeout(() => { armed = false; resetBtn.querySelector('.label').textContent = 'איפוס לנתוני הדוגמה'; resetBtn.classList.remove('active'); }, 4000);
      return;
    }
    store.resetData();
    toast('אופס לנתוני הדוגמה', { ok: true });
    closeModal();
  } });
  const body = h('div', {},
    h('p', { class: 'small' }, 'הערכים כאן הם ה"אמת" של העיר לתקופה הנוכחית (KPI ingestion). שינוי כאן מזיז את הבסיס — לא תרחיש. הנתונים נשמרים בדפדפן הזה בלבד.'),
    h('div', { class: 'row', style: { gap: '6px', flexWrap: 'wrap', margin: '8px 0 12px' } },
      btn('שמור כבסיס', { icon: 'download', cls: 'small primary', onClick: () => { store.setInputs(readFields()); toast('הנתונים עודכנו', { ok: true }); closeModal(); } }),
      btn('ייצא model.json', { icon: 'download', cls: 'small', onClick: () => downloadText(JSON.stringify(store.data.model, null, 2), 'model.json', 'application/json') }),
      btn('ייצא Snapshot לסקילים', { icon: 'download', cls: 'small', onClick: () => downloadText(JSON.stringify(snapshotOf(store.current), null, 2), 'city-snapshot.json', 'application/json') }),
      btn('העתק Snapshot', { icon: 'copy', cls: 'small', onClick: () => copyText(JSON.stringify(snapshotOf(store.current)), 'Snapshot הועתק') }),
      resetBtn,
    ),
    grid,
    h('div', { class: 'divider' }),
    h('div', { class: 'section-title' }, 'ייבוא JSON'),
    ta,
    h('div', { class: 'row', style: { gap: '6px', marginTop: '8px', flexWrap: 'wrap' } },
      btn('בחר קובץ', { icon: 'data', cls: 'small', onClick: () => file.click() }),
      btn('ייבא', { icon: 'play', cls: 'small accent', onClick: () => {
        try {
          const obj = JSON.parse(ta.value);
          if (obj.inputs && obj.period) store.replaceModel(obj);
          else if (obj.inputs) store.setInputs(obj.inputs);
          else throw new Error('חסר inputs');
          toast('הנתונים יובאו', { ok: true });
          closeModal();
        } catch (e) {
          toast('JSON לא תקין: ' + e.message);
        }
      } }),
      file,
    ),
  );
  openModal({ title: 'נתוני העיר — KPI Ingestion', sub: `תקופה: ${store.current.periodLabel} · מקור: ${store.data.model.meta?.source ?? '—'}`, body });
}
