import { h } from './dom.js';

export function toast(text, { ok = false, ms = 2400 } = {}) {
  const root = document.getElementById('toast');
  const el = h('div', { class: `toast ${ok ? 'ok' : ''}` }, text);
  root.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity 300ms';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 320);
  }, ms);
}

export async function copyText(text, label = 'הועתק') {
  try {
    await navigator.clipboard.writeText(text);
    toast(label, { ok: true });
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast(label, { ok: true }); } catch { toast('ההעתקה נכשלה'); }
    ta.remove();
  }
}

export function downloadText(text, filename, type = 'text/markdown') {
  const blob = new Blob([text], { type: `${type};charset=utf-8` });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
