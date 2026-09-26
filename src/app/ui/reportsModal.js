import { h, btn, clear } from './dom.js';
import { openModal } from './modal.js';
import { renderMarkdown } from './markdown.js';
import { dailyBrief, weeklyReview, monthlyStructural, whatIfReport } from '../../engine/reports.js';
import { copyText, downloadText } from './toast.js';

const KINDS = [
  { id: 'daily', label: 'Daily Mayor Brief' },
  { id: 'weekly', label: 'Weekly City Review' },
  { id: 'monthly', label: 'Monthly Structural Review' },
  { id: 'whatif', label: 'What-if Report' },
];

export function openReports(store, kind = 'daily') {
  let current = kind;
  let md = '';
  const content = h('div', { class: 'md' });
  const tabs = h('div', { class: 'tabs' });
  const actions = h('div', { class: 'row', style: { gap: '6px' } },
    btn('העתק', { icon: 'copy', cls: 'small', onClick: () => copyText(md, 'הדוח הועתק (Markdown)') }),
    btn('הורד .md', { icon: 'download', cls: 'small', onClick: () => downloadText(md, `cityos-${current}-${store.current.periodLabel}.md`) }),
  );
  const modal = openModal({ title: 'דוחות העיר', sub: store.hasScenario() ? 'תרחיש פעיל — הדוחות משקפים את הסימולציה' : store.current.periodLabel, headExtra: actions, body: h('div', {}, tabs, h('div', { class: 'divider' }), content) });

  function render() {
    clear(tabs);
    for (const k of KINDS) tabs.append(h('button', { class: k.id === current ? 'active' : '', onClick: () => { current = k.id; render(); } }, k.label));
    const city = store.current;
    if (current === 'daily') md = dailyBrief(city);
    else if (current === 'weekly') md = weeklyReview(city);
    else if (current === 'monthly') md = monthlyStructural(city);
    else md = store.hasScenario() ? whatIfReport(store.base, store.current, store.overrides) : '# What-if Report\n\n- אין תרחיש פעיל. פתחו את הסימולציה, הזיזו ידית וחזרו לכאן.';
    content.innerHTML = renderMarkdown(md);
    modal.body.scrollTop = 0;
  }
  render();
}
