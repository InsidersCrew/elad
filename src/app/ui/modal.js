import { h, icon, clear } from './dom.js';

let current = null;

export function openModal({ title, sub, headExtra = null, body, onClose }) {
  const host = document.getElementById('modal');
  clear(host);
  const bodyEl = h('div', { class: 'panel-body' });
  if (body) bodyEl.append(body);
  const box = h('div', { class: 'modal-box', role: 'dialog', 'aria-modal': 'true' },
    h('div', { class: 'panel-head' },
      h('div', {}, h('h2', {}, title), sub ? h('div', { class: 'sub' }, sub) : null),
      h('div', { class: 'spacer' }),
      headExtra,
      h('button', { class: 'iconbtn', title: 'סגור', onClick: () => closeModal() }, icon('close')),
    ),
    bodyEl,
  );
  host.append(box);
  host.hidden = false;
  host.onclick = (e) => { if (e.target === host) closeModal(); };
  current = { onClose, body: bodyEl, box };
  return current;
}

export function closeModal() {
  const host = document.getElementById('modal');
  host.hidden = true;
  clear(host);
  current?.onClose?.();
  current = null;
}

export function isModalOpen() {
  return !!current;
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && current) closeModal();
});
