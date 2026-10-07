/* INSIDERS Collections — admin app (no build step). All dynamic text goes through
   textContent; nothing from the server is ever inserted as HTML. */
(function () {
	'use strict';
	var C = window.ICOL || {};
	var root = document.getElementById('icol-root');
	if (!root) { return; }

	/* ---------- helpers ---------- */
	function h(tag, attrs) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.className = v; }
			else if (k === 'text') { el.textContent = v; }
			else if (k.slice(0, 2) === 'on') { el.addEventListener(k.slice(2), v); }
			else if (k === 'value') { el.value = v; }
			else if (k === 'checked') { el.checked = !!v; }
			else { el.setAttribute(k, v === true ? '' : v); }
		});
		for (var i = 2; i < arguments.length; i++) { add(el, arguments[i]); }
		return el;
	}
	function add(el, kid) {
		if (kid === null || kid === undefined || kid === false) { return; }
		if (Array.isArray(kid)) { kid.forEach(function (k) { add(el, k); }); return; }
		el.appendChild(kid instanceof Node ? kid : document.createTextNode(String(kid)));
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
	function uuid() { return (crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random(); }
	function money(minor, cur) {
		if (minor === null || minor === undefined || minor === '') { return '—'; }
		var n = Number(minor) / 100;
		var s = n.toLocaleString('he-IL', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
		return s + ' ' + ({ ILS: '₪', USD: '$', EUR: '€' }[cur || 'ILS'] || cur);
	}
	function can(cap) { return !!(C.caps && C.caps[cap]); }
	function qs(obj) {
		var p = [];
		Object.keys(obj || {}).forEach(function (k) { if (obj[k] !== '' && obj[k] !== null && obj[k] !== undefined) { p.push(encodeURIComponent(k) + '=' + encodeURIComponent(obj[k])); } });
		return p.length ? '?' + p.join('&') : '';
	}

	var L = {
		state: { draft: 'טיוטה', active: 'פעיל', waiting_reply: 'ממתין לתשובה', promise_pending: 'בקשת מועד ממתינה', promise_hold: 'המתנה להבטחה', payment_verification: 'אימות תשלום', human_review: 'אצל נציג', paused: 'מושהה', closed: 'סגור' },
		finance: { draft: 'טיוטה', not_due: 'טרם הגיע מועד', open: 'פתוח', partially_paid: 'שולם חלקית', settled: 'הוסדר', cancelled: 'בוטל', review: 'בבירור' },
		card: { not_applicable: 'לא רלוונטי', unknown: 'לא ידוע', update_required: 'נדרש עדכון', update_in_progress: 'בעדכון', pending_verification: 'ממתין לאימות', confirmed_manual: 'אושר ידנית', verified: 'אומת מול ספק', failed: 'נכשל' },
		source: { recurring_failure: 'חיוב חוזר שלא עבר', non_open_charge: 'אי פתיחת חשבון', other: 'אחר' },
		delivery: { draft: 'טיוטה', queued: 'בתור', accepted: 'התקבל אצל WATI', sent: 'נשלח', delivered: 'נמסר', read: 'נקרא', failed: 'נכשל', unknown: 'לא ידוע', received: 'התקבל', simulated: 'סימולציה (לא נשלח)', cancelled: 'בוטל לפני שליחה', internal: 'פנימי', imported: 'יובא' },
		task: { open: 'פתוחה', in_progress: 'בטיפול', needs_clarification: 'נדרש בירור', confirmed_manual: 'אושר ידנית', verified: 'אומת', not_required: 'לא נדרש', cancelled: 'בוטל' },
		severity: { critical: 'קריטי', high: 'גבוה', medium: 'בינוני', low: 'נמוך' },
		method: { bank_transfer: 'העברה בנקאית', bit: 'ביט', cash: 'מזומן', check: 'צ׳ק', card_other: 'כרטיס בעסקה נפרדת', other: 'אחר' }
	};

	/* ---------- API ---------- */
	function request(method, path, body) {
		var opts = { method: method, headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' }, credentials: 'same-origin' };
		if (method !== 'GET') {
			opts.headers['Content-Type'] = 'application/json';
			opts.headers['Idempotency-Key'] = uuid();
			opts.body = JSON.stringify(body || {});
		}
		var url = /^https?:/.test(path) ? path : C.root + path;
		// Sites without pretty permalinks expose REST as ?rest_route=...: query strings must join with '&'.
		var first = url.indexOf('?');
		if (first >= 0) { url = url.slice(0, first + 1) + url.slice(first + 1).replace(/\?/g, '&'); }
		return fetch(url, opts).then(function (res) {
			return res.text().then(function (t) {
				var data = null;
				try { data = t ? JSON.parse(t) : null; } catch (e) { data = { message: t }; }
				// Idle or expired gate session: the page reloads into the lock screen (no data stays on screen).
				if (res.status === 401 && data && data.code === 'gate_locked') { location.reload(); }
				if (!res.ok) { var err = new Error((data && data.message) || ('HTTP ' + res.status)); err.status = res.status; err.data = data || {}; throw err; }
				return data;
			});
		});
	}
	var api = {
		get: function (p, q) { return request('GET', p + qs(q)); },
		post: function (p, b) { return request('POST', p, b); }
	};

	/* ---------- toast / modal ---------- */
	var toasts = h('div', { class: 'icol-toasts', 'aria-live': 'polite' });
	document.body.appendChild(toasts);
	function toast(msg, kind) {
		var t = h('div', { class: 'icol-toast ' + (kind || '') , role: kind === 'err' ? 'alert' : 'status' }, msg);
		toasts.appendChild(t);
		setTimeout(function () { t.remove(); }, kind === 'err' ? 8000 : 4000);
	}
	function fail(e) {
		var msg = (e && e.message) || 'שגיאה';
		if (e && e.data && e.data.correlation_id && e.status >= 500) { msg += ' (' + e.data.correlation_id + ')'; }
		toast(msg, 'err');
		return e;
	}
	function modal(opts) {
		var overlay = h('div', { class: 'icol-overlay', role: 'dialog', 'aria-modal': 'true' });
		var box = h('div', { class: 'icol-modal' + (opts.wide ? ' wide' : '') });
		var close = function () { overlay.remove(); document.removeEventListener('keydown', esc); };
		var esc = function (e) { if (e.key === 'Escape') { close(); } };
		document.addEventListener('keydown', esc);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
		add(box, h('h3', { text: opts.title }));
		if (opts.lead) { add(box, h('p', { class: 'lead', text: opts.lead })); }
		if (opts.body) { add(box, opts.body); }
		var foot = h('div', { class: 'foot' });
		(opts.actions || []).forEach(function (a) {
			var b = h('button', { class: 'icol-btn ' + (a.kind || ''), type: 'button', text: a.label, disabled: a.disabled });
			b.addEventListener('click', function () {
				if (!a.onClick) { close(); return; }
				b.disabled = true;
				Promise.resolve(a.onClick(close)).then(function (keep) { if (keep !== true) { close(); } }).catch(function (e) { b.disabled = false; showFieldErrors(box, e); fail(e); });
			});
			add(foot, b);
		});
		add(foot, h('button', { class: 'icol-btn ghost', type: 'button', text: opts.cancelLabel || 'ביטול', onclick: close }));
		add(box, foot);
		add(overlay, box);
		document.body.appendChild(overlay);
		var first = box.querySelector('input,select,textarea,button');
		if (first) { first.focus(); }
		return close;
	}

	/* ---------- form fields ---------- */
	function field(name, label, type, o) {
		o = o || {};
		var input;
		if (type === 'select') {
			input = h('select', { name: name });
			(o.options || []).forEach(function (op) { add(input, h('option', { value: op[0], text: op[1], selected: String(op[0]) === String(o.value) })); });
		} else if (type === 'textarea') {
			input = h('textarea', { name: name, placeholder: o.placeholder || '', rows: o.rows || 3 });
			input.value = o.value || '';
		} else if (type === 'checkbox') {
			input = h('input', { type: 'checkbox', name: name, checked: !!o.value });
			return { el: h('div', { class: 'icol-field' + (o.wide ? ' wide' : '') , 'data-field': name }, h('label', { class: 'icol-check' }, input, label), o.help ? h('div', { class: 'help', text: o.help }) : null), input: input };
		} else {
			input = h('input', { type: type || 'text', name: name, placeholder: o.placeholder || '', value: o.value === undefined ? '' : o.value, inputmode: o.inputmode, step: o.step });
		}
		if (o.required) { input.setAttribute('aria-required', 'true'); }
		var el = h('div', { class: 'icol-field' + (o.wide ? ' wide' : ''), 'data-field': name }, h('label', { text: label + (o.required ? ' *' : '') }), input, o.help ? h('div', { class: 'help', text: o.help }) : null);
		return { el: el, input: input };
	}
	function val(f) { return f.input.type === 'checkbox' ? f.input.checked : f.input.value.trim(); }
	function showFieldErrors(container, e) {
		container.querySelectorAll('.err').forEach(function (n) { n.remove(); });
		container.querySelectorAll('.has-err').forEach(function (n) { n.classList.remove('has-err'); });
		var fe = e && e.data && e.data.field_errors;
		if (!fe) { return; }
		Object.keys(fe).forEach(function (k) {
			var base = k.split('.')[0];
			var el = container.querySelector('[data-field="' + k + '"]') || container.querySelector('[data-field="' + base + '"]');
			if (el) { el.classList.add('has-err'); add(el, h('div', { class: 'err', text: fe[k] })); }
		});
	}
	function kv(pairs) {
		var dl = h('dl', { class: 'icol-kv' });
		pairs.forEach(function (p) { if (p[1] !== null && p[1] !== undefined && p[1] !== '') { add(dl, [h('dt', { text: p[0] }), h('dd', {}, p[1])]); } });
		return dl;
	}
	function chip(text, kind) { return h('span', { class: 'icol-chip ' + (kind || ''), text: text }); }
	function stateCell(state) { return h('span', {}, h('span', { class: 'icol-state-dot ' + state }), L.state[state] || state); }
	function table(cols, rows, onRow) {
		if (!rows.length) { return h('div', { class: 'icol-table-wrap' }, h('div', { class: 'icol-empty', text: 'אין רשומות להצגה' })); }
		var thead = h('thead', {}, h('tr', {}, cols.map(function (c) { return h('th', { text: c[1] }); })));
		var tbody = h('tbody');
		rows.forEach(function (r) {
			var tr = h('tr', { class: onRow ? 'click' : '' });
			if (onRow) { tr.addEventListener('click', function (e) { if (e.target.closest('button,a,input,select')) { return; } onRow(r); }); tr.tabIndex = 0; tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') { onRow(r); } }); }
			cols.forEach(function (c) {
				var v = typeof c[2] === 'function' ? c[2](r) : r[typeof c[2] === 'string' ? c[2] : c[0]];
				add(tr, h('td', { class: c[3] || '' }, v === null || v === undefined || v === '' ? h('span', { class: 'muted', text: '—' }) : v));
			});
			add(tbody, tr);
		});
		return h('div', { class: 'icol-table-wrap' }, h('table', { class: 'icol-table' }, thead, tbody));
	}
	function pageHead(title, hint, actions) {
		return h('div', { class: 'icol-page-head' }, h('div', {}, h('h1', { text: title }), hint ? h('div', { class: 'hint', text: hint }) : null), actions ? h('div', { class: 'icol-actions' }, actions) : null);
	}
	function loading(el) { clear(el); add(el, h('div', { class: 'icol-loading', text: 'טוען…' })); }

	/* ---------- shell ---------- */
	var counts = {};
	var main = h('main', { class: 'icol-main' });
	var topbar = h('div', { class: 'icol-top' });
	var page = h('div', { class: 'icol-page' });
	var nav = h('nav', { class: 'icol-nav', 'aria-label': 'ניווט' });
	var NAV = [
		['#/', 'לוח גבייה', null],
		['#/cases', 'תיקים', null],
		['#/tasks', 'המשימות שלי', 'my_tasks'],
		['#/exceptions', 'תור חריגים', 'exceptions_open'],
		['#/cards', 'כרטיסים לעדכון', 'card_tasks_open'],
		['#/candidates', 'מועמדים לחיוב', 'candidates_new'],
		['sep'],
		['#/new', 'חוב חדש', null, 'icol_create_draft'],
		['#/handover', 'המשך טיפול', null, 'icol_create_draft'],
		['sep'],
		['#/settings', 'הגדרות ובריאות', null, 'icol_admin'],
		['#/security', 'אבטחה וטלפונים', null]
	];
	function renderNav() {
		clear(nav);
		var cur = location.hash || '#/';
		NAV.forEach(function (n) {
			if (n[0] === 'sep') { add(nav, h('div', { class: 'sep' })); return; }
			if (n[3] && !can(n[3])) { return; }
			var on = n[0] === '#/' ? (cur === '#/' || cur === '') : cur.indexOf(n[0]) === 0;
			var badge = n[2] && counts[n[2]] ? h('span', { class: 'icol-badge' + (n[2] === 'exceptions_open' ? ' hot' : ''), text: counts[n[2]] }) : null;
			add(nav, h('a', { href: n[0], class: on ? 'on' : '' , 'aria-current': on ? 'page' : null }, h('span', { text: n[1] }), badge));
		});
	}
	function renderTop(mode) {
		clear(topbar);
		var chips = h('div', { class: 'icol-mode' });
		if (mode) {
			if (mode.kill_switch) { add(chips, chip('מתג עצירה פעיל, אין משלוחים ללקוחות', 'bad')); }
			else if (mode.send_mode === 'simulate') { add(chips, chip('מצב תצוגה בלבד, הודעות מתועדות ולא נשלחות', 'info')); }
			else { add(chips, chip('משלוחים פעילים', 'ok')); }
			if (!mode.policy_approved) { add(chips, chip('מדיניות פנייה טרם אושרה', 'warn')); }
		}
		if (C.gate && C.gate.off) { add(chips, chip('שער הסריקה כבוי (ICOL_GATE_OFF)', 'bad')); }
		var ks = null;
		if (can('icol_work_case') && mode) {
			ks = mode.kill_switch
				? (can('icol_admin') ? h('button', { class: 'icol-btn sm', text: 'ביטול מתג העצירה', onclick: function () { killSwitch(false); } }) : null)
				: h('button', { class: 'icol-btn sm danger', text: 'עצירת כל המשלוחים', onclick: function () { killSwitch(true); } });
		}
		var lock = C.gate && C.gate.enforced && window.ICOLGate ? h('button', { class: 'icol-btn sm', text: 'נעילה', title: 'סגירת המערכת במחשב הזה עד הסריקה הבאה', onclick: function () { window.ICOLGate.lock(); } }) : null;
		add(topbar, [chips, h('div', { class: 'icol-actions' }, ks, lock, h('span', { class: 'who', text: (C.user && C.user.name) + ' · ' + roleLabel(C.user && C.user.role) }))]);
	}
	function roleLabel(r) { return { admin: 'מנהל', collector: 'אחראי גבייה', rep: 'נציג', viewer: 'צופה' }[r] || 'ללא תפקיד'; }
	function killSwitch(on) {
		var reason = field('reason', 'סיבה', 'textarea', { required: true });
		modal({
			title: on ? 'עצירת כל המשלוחים ללקוחות' : 'חידוש משלוחים',
			lead: on ? 'כל ההודעות היזומות ייעצרו מיד. קליטת תשלומים ואימותים ממשיכים. הודעות שבתור יבוטלו ולא יישלחו בדיעבד.' : 'המשלוחים יחודשו. הודעות שבוטלו לא יישלחו; המערכת תתזמן מחדש לפי המצב העדכני.',
			body: h('div', { class: 'icol-form' }, reason.el),
			actions: [{ label: on ? 'עצירה' : 'חידוש', kind: on ? 'danger' : 'primary', onClick: function () { return api.post('/kill-switch', { on: on, reason: val(reason) }).then(function () { toast(on ? 'המשלוחים נעצרו' : 'המשלוחים חודשו', 'ok'); route(); }); } }]
		});
	}
	function refreshCounts() {
		return api.get('/dashboard').then(function (d) { counts = d.kpis || {}; renderNav(); renderTop(d.mode); return d; }).catch(function () { return null; });
	}

	clear(root);
	add(root, h('div', { class: 'icol-shell' },
		h('aside', { class: 'icol-side' }, h('div', { class: 'icol-logo' }, 'IN', h('span', { text: '/' }), 'SIDERS'), h('div', { class: 'icol-sub', text: 'תשלומים וגבייה · ' + (C.version || '') }), nav),
		add2(main, [topbar, page])
	));
	function add2(el, kids) { add(el, kids); return el; }

	/* ---------- router ---------- */
	function parseHash() {
		var raw = (location.hash || '#/').slice(1);
		var parts = raw.split('?');
		var q = {};
		(parts[1] || '').split('&').forEach(function (kv2) { if (kv2) { var p = kv2.split('='); q[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); } });
		return { path: parts[0] || '/', q: q };
	}
	function route() {
		var r = parseHash();
		document.querySelectorAll('.icol-overlay').forEach(function (o) { o.remove(); });
		renderNav();
		var m;
		loading(page);
		refreshCounts();
		if (r.path === '/' ) { return viewDashboard(); }
		if (r.path === '/cases') { return viewCases(r.q); }
		if ((m = r.path.match(/^\/cases\/(\d+)$/))) { return viewCase(Number(m[1]), r.q.tab); }
		if (r.path === '/new') { return viewNewCase(false); }
		if (r.path === '/handover') { return viewNewCase(true); }
		if (r.path === '/exceptions') { return viewExceptions(); }
		if (r.path === '/cards') { return viewCards(); }
		if (r.path === '/candidates') { return viewCandidates(); }
		if (r.path.indexOf('/tasks') === 0) { return viewTasks(); }
		if (r.path.indexOf('/settings') === 0) { return viewSettings(r.path.split('/')[2] || 'health'); }
		if (r.path === '/security') { return viewSecurity(); }
		clear(page); add(page, h('div', { class: 'icol-empty', text: 'העמוד לא נמצא' }));
	}
	window.addEventListener('hashchange', route);

	/* ---------- dashboard ---------- */
	function viewDashboard() {
		api.get('/dashboard').then(function (d) {
			clear(page);
			var k = d.kpis;
			add(page, pageHead('לוח גבייה', 'יתרות שהגיע מועד פירעונן, תקבולים שהותאמו ועבודה פתוחה. לחיצה על מדד פותחת רשימה מסוננת.'));
			if (d.mode.kill_switch) { add(page, h('div', { class: 'icol-banner stop' }, h('div', {}, h('strong', { text: 'מתג העצירה פעיל. ' }), 'אין משלוחים ללקוחות. תשלומים ממשיכים להיקלט.'))); }
			else if (d.mode.send_mode === 'simulate') { add(page, h('div', { class: 'icol-banner sim' }, h('div', {}, h('strong', { text: 'מצב תצוגה בלבד. ' }), 'המערכת מחשבת ומתעדת מה הייתה שולחת, בלי לשלוח. ' + d.mode.simulated_last_7d + ' הודעות סימולציה בשבוע האחרון.'), h('a', { href: '#/cases?state=waiting_reply', text: 'לצפייה בתיקים' }))); }
			var tile = function (label, value, foot, href, alert) {
				return h('div', { class: 'icol-card icol-kpi' + (alert ? ' alert' : ''), role: 'link', tabindex: 0, onclick: function () { location.hash = href; }, onkeydown: function (e) { if (e.key === 'Enter') { location.hash = href; } } }, h('div', { class: 'kpi-label', text: label }), h('div', { class: 'kpi-value', text: value }), foot ? h('div', { class: 'kpi-foot', text: foot }) : null);
			};
			add(page, h('div', { class: 'icol-grid icol-kpis' },
				tile('יתרה פתוחה שהגיע מועדה', money(k.open_due_minor), 'בשקלים, ללא תשלומים עתידיים', '#/cases?sort=balance'),
				tile('תקבולים שהותאמו · 30 יום', money(k.matched_30d_minor), 'שיוכים מאומתים נטו', '#/cases?scope=all&state=closed'),
				tile('תיקים שמחכים לנציג', String(k.waiting_rep), null, '#/cases?state=human_review', k.waiting_rep > 0),
				tile('תשלומים בבדיקה', String(k.payment_verification), 'לקוח טען ששילם', '#/cases?state=payment_verification', k.payment_verification > 0),
				tile('הבטחות להיום', String(k.promises_today), null, '#/cases?state=promise_hold'),
				tile('משימות כרטיס פתוחות', String(k.card_tasks_open), 'עדכון אמצעי תשלום לחיובים הבאים', '#/cards', k.card_tasks_open > 0),
				tile('חריגים פתוחים', String(k.exceptions_open), null, '#/exceptions', k.exceptions_open > 0),
				tile('טיוטות', String(k.drafts), 'ממתינות לאישור והפעלה', '#/cases?state=draft'),
				tile('מועמדים מדשבורד ההכנסות', String(k.candidates_new), 'עבר המועד לפתיחת חשבון', '#/candidates')
			));
			var by = {};
			(d.by_state || []).forEach(function (r) { by[r.state] = Number(r.n); });
			add(page, h('div', { class: 'icol-card', style: 'margin-top:12px' }, h('h3', { text: 'תיקים לפי מצב טיפול' }), h('div', { class: 'icol-mode' }, Object.keys(L.state).map(function (s) { return h('a', { href: '#/cases?state=' + s + (s === 'closed' ? '&scope=all' : ''), class: 'icol-chip', text: L.state[s] + ' · ' + (by[s] || 0) }); }))));
		}).catch(fail);
	}

	/* ---------- cases list ---------- */
	function viewCases(q) {
		q = Object.assign({ page: 1 }, q);
		var search = h('input', { type: 'search', placeholder: 'שם, טלפון, מספר תיק, הוראה או עסקה', value: q.q || '' });
		var state = field('state', 'מצב', 'select', { value: q.state || '', options: [['', 'כל המצבים הפתוחים']].concat(Object.keys(L.state).map(function (s) { return [s, L.state[s]]; })) }).input;
		var source = field('source', 'מקור', 'select', { value: q.source || '', options: [['', 'כל המקורות']].concat(Object.keys(L.source).map(function (s) { return [s, L.source[s]]; })) }).input;
		var owner = field('owner', 'אחראי', 'select', { value: q.owner || '', options: [['', 'כל האחראים'], ['me', 'שלי'], ['none', 'תור כללי']] }).input;
		var age = field('age', 'גיל חוב', 'select', { value: q.age || '', options: [['', 'כל גיל'], ['0-7', 'עד שבוע'], ['8-30', '8–30 ימים'], ['31-60', '31–60'], ['61-9999', 'מעל 60']] }).input;
		var sort = field('sort', 'מיון', 'select', { value: q.sort || '', options: [['', 'עודכן לאחרונה'], ['balance', 'יתרה'], ['age', 'גיל חוב'], ['next', 'פעולה הבאה']] }).input;
		var apply = function (extra) {
			var n = Object.assign({}, { q: search.value.trim(), state: state.value, source: source.value, owner: owner.value, age: age.value, sort: sort.value, scope: state.value === 'closed' ? 'all' : '' }, extra || {});
			location.hash = '#/cases' + qs(n);
		};
		[state, source, owner, age, sort].forEach(function (s) { s.addEventListener('change', function () { apply(); }); });
		search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { apply(); } });
		var listEl = h('div');
		clear(page);
		add(page, pageHead('תיקים', 'טבלת התיקים עם יתרה, מצב, פנייה ותגובה אחרונות ופעולה הבאה.', [
			can('icol_export') ? h('button', { class: 'icol-btn sm', text: 'ייצוא CSV', onclick: function () { exportCsv(q); } }) : null,
			can('icol_create_draft') ? h('a', { class: 'icol-btn sm primary', href: '#/new', text: 'חוב חדש' }) : null
		]));
		add(page, h('div', { class: 'icol-filters' }, search, state, source, owner, age, sort, h('button', { class: 'icol-btn sm', text: 'חיפוש', onclick: function () { apply(); } })));
		add(page, listEl);
		loading(listEl);
		api.get('/cases', Object.assign({ per_page: 25 }, q)).then(function (res) {
			clear(listEl);
			add(listEl, table([
				['id', 'תיק', function (r) { return h('span', { class: 'num', text: '#' + r.id }); }],
				['customer', 'לקוח', function (r) { return h('span', {}, r.customer, h('span', { class: 'sub', text: r.phone || '' })); }],
				['source', 'מקור', function (r) { return h('span', {}, r.source_label, r.entry_mode === 'handover' ? h('span', { class: 'sub', text: 'המשך טיפול' }) : null); }],
				['due', 'יתרה', function (r) { return h('span', { class: 'num', text: r.due_minor ? money(r.due_minor, r.currency) : ', ' }); }, 'num'],
				['age', 'גיל', function (r) { return r.age_days === null ? null : h('span', { class: 'num', text: r.age_days + ' ימים' }); }],
				['state', 'מצב טיפול', function (r) { return h('span', {}, stateCell(r.state), r.state_reason ? h('span', { class: 'sub', text: r.state_reason }) : null); }],
				['last_out', 'פנייה אחרונה', 'last_out'],
				['last_in', 'תגובה אחרונה', function (r) { return r.last_in ? h('span', {}, r.last_in, h('span', { class: 'sub', text: r.last_in_text })) : null; }],
				['next', 'פעולה הבאה', function (r) { return r.next_action_at ? h('span', {}, r.next_action_at, h('span', { class: 'sub', text: r.next_action === 'send_reminder' ? 'תזכורת' : (r.next_action || '') })) : null; }],
				['owner', 'אחראי', function (r) { return r.owner || h('span', { class: 'muted', text: 'תור כללי' }); }],
				['card', 'כרטיס', function (r) { return r.card_status ? chip(L.card[r.card_status] || r.card_status, r.card_status === 'update_required' ? 'warn' : (r.card_status === 'verified' ? 'ok' : '')) : null; }]
			], res.rows, function (r) { location.hash = '#/cases/' + r.id; }));
			var pages = Math.max(1, Math.ceil(res.total / res.per_page));
			add(listEl, h('div', { class: 'icol-pager' }, res.total + ' תיקים · עמוד ' + res.page + ' מתוך ' + pages,
				h('button', { class: 'icol-btn sm', text: 'הקודם', disabled: res.page <= 1, onclick: function () { apply({ page: res.page - 1 }); } }),
				h('button', { class: 'icol-btn sm', text: 'הבא', disabled: res.page >= pages, onclick: function () { apply({ page: res.page + 1 }); } })));
		}).catch(fail);
	}
	function exportCsv(q) {
		api.get('/export/cases', q).then(function (rows) {
			var cols = ['id', 'customer', 'phone', 'source_label', 'due_minor', 'age_days', 'state_label', 'last_out', 'last_in', 'next_action_at', 'owner', 'card_status'];
			var esc = function (v) { v = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
			var csv = '﻿' + cols.join(',') + '\n' + rows.map(function (r) { return cols.map(function (c) { return esc(c === 'due_minor' ? (r[c] / 100).toFixed(2) : r[c]); }).join(','); }).join('\n');
			var a = h('a', { href: URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })), download: 'cases.csv' });
			document.body.appendChild(a); a.click(); a.remove();
			toast('הייצוא נרשם ביומן הפעולות', 'ok');
		}).catch(fail);
	}

	/* ---------- case card ---------- */
	var currentTab = 'items';
	function viewCase(id, tab) {
		if (tab) { currentTab = tab; }
		api.get('/cases/' + id).then(function (d) { renderCase(d); }).catch(function (e) { clear(page); add(page, h('div', { class: 'icol-empty', text: e.message })); });
	}
	function actionBtn(d, key, label, kind, fn) {
		var a = d.actions[key] || { allowed: true };
		var b = h('button', { class: 'icol-btn sm ' + (kind || ''), type: 'button', text: label, disabled: !a.allowed, title: a.reason || '' , onclick: fn });
		return a.allowed ? b : h('span', {}, b, h('span', { class: 'icol-why', text: a.reason }));
	}
	function reload(d) { viewCase(d.case.id); }
	function renderCase(d) {
		var c = d.case, cu = d.customer, f = d.finance;
		clear(page);
		var contact = [];
		contact.push(chip(cu.contact_status === 'verified' ? 'טלפון מאומת' : (cu.contact_status === 'wrong_number' ? 'מספר שגוי' : 'טלפון לא מאומת'), cu.contact_status === 'verified' ? 'ok' : 'warn'));
		if (cu.shares_phone) { contact.push(chip('מספר משותף ללקוח אחר', 'bad')); }
		contact.push(chip(cu.permission === true ? 'הרשאת וואטסאפ' : (cu.permission === false ? 'ביקש להפסיק הודעות' : 'אין הרשאת קשר מתועדת'), cu.permission === true ? 'ok' : 'bad'));
		if (cu.conversation_owner && cu.conversation_owner !== 'none') { contact.push(chip('בעלות שיחה: ' + ({ collections: 'גבייה', onboarding: 'סוכן ההרשמה', human: 'נציג' }[cu.conversation_owner] || cu.conversation_owner), cu.conversation_owner === 'collections' ? 'info' : 'warn')); }
		if (cu.in_service_window) { contact.push(chip('חלון שירות 24ש׳ פתוח', 'info')); }
		var stateChips = [chip(L.state[c.workflow_state] || c.workflow_state, 'violet'), chip(L.source[c.source_type] + (c.entry_mode === 'handover' ? ' · המשך טיפול' : ''))];
		if (Number(c.dispute_open)) { stateChips.push(chip('מחלוקת פתוחה', 'bad')); }
		if (Number(c.claims_account_opened)) { stateChips.push(chip('טענת פתיחת חשבון', 'warn')); }
		if (d.order) { stateChips.push(chip('כרטיס: ' + (L.card[d.order.card_status] || d.order.card_status), d.order.card_status === 'update_required' ? 'warn' : (d.order.card_status === 'verified' ? 'ok' : ''))); }

		add(page, h('div', { class: 'icol-page-head' }, h('div', {}, h('a', { href: '#/cases', text: '← לרשימת התיקים' })), h('div', { class: 'muted num', text: 'תיק #' + c.id + ' · גרסה ' + c.version })));
		add(page, h('div', { class: 'icol-case-head' },
			h('div', { class: 'icol-card icol-person' },
				h('h2', { text: cu.full_name }),
				h('div', { class: 'muted num', text: [cu.phone, cu.email].filter(Boolean).join(' · ') }),
				h('div', { class: 'meta' }, stateChips),
				h('div', { class: 'meta' }, contact),
				c.state_reason ? h('div', { class: 'muted', style: 'margin-top:8px', text: 'סיבת המצב: ' + c.state_reason }) : null,
				h('div', { class: 'muted', style: 'margin-top:4px', text: 'אחראי: ' + (c.owner_name || 'תור כללי') + (c.next_action_local ? ' · פעולה הבאה: ' + c.next_action_local : '') })
			),
			h('div', { class: 'icol-money' },
				h('div', { class: 'm due' }, h('div', { class: 'l', text: 'יתרה שהגיע מועדה' }), h('div', { class: 'v', text: money(f.due_balance_minor, f.currency) })),
				h('div', { class: 'm' }, h('div', { class: 'l', text: 'תשלומים עתידיים' }), h('div', { class: 'v', text: f.not_due_minor ? money(f.not_due_minor, f.currency) : (d.future && d.future.future_installments ? d.future.future_installments + ' תשלומים' : ', ') })),
				h('div', { class: 'm' }, h('div', { class: 'l', text: 'תקבולים ששויכו' }), h('div', { class: 'v', text: money(f.allocated_minor, f.currency) }))
			)
		));

		var bar = h('div', { class: 'icol-actionbar' });
		add(bar, [
			actionBtn(d, 'preview', c.workflow_state === 'draft' ? 'תצוגה מקדימה והפעלה' : 'תצוגת ההודעה הבאה', c.workflow_state === 'draft' ? 'primary' : '', function () { previewModal(d); }),
			f.draft_minor > 0 ? actionBtn(d, 'approve', 'אישור חוב', 'blue', function () { approveModal(d); }) : null,
			actionBtn(d, 'pause', 'השהיה', '', function () { reasonModal('השהיית התיק', 'פניות מתוזמנות יבוטלו. קליטת תשלומים ממשיכה.', '/cases/' + c.id + '/pause', d); }),
			actionBtn(d, 'resume', 'חידוש טיפול', '', function () { reasonModal('חידוש טיפול', 'המערכת תתכנן רצף חדש מהמצב העדכני, בלי להשלים הודעות ישנות.', '/cases/' + c.id + '/resume', d); }),
			actionBtn(d, 'promise', 'רישום הבטחה', '', function () { promiseModal(d); }),
			actionBtn(d, 'dispute', 'פתיחת מחלוקת', '', function () { simpleModal('פתיחת מחלוקת', 'הפניות ייעצרו והתיק יועבר לאחראי.', [field('note', 'מה הלקוח טוען', 'textarea', { required: true })], function (fs) { return api.post('/cases/' + c.id + '/reviews', { type: 'dispute', note: val(fs[0]) }); }, d); }),
			actionBtn(d, 'verify_payment', 'אימות תשלום חיצוני', '', function () { paymentModal(d); }),
			actionBtn(d, 'payment_request', 'קישור תשלום', '', function () { api.post('/cases/' + c.id + '/payment-requests').then(function (r) { modal({ title: 'קישור תשלום', lead: 'זה הקישור של המערכת. הוא בודק יתרה עדכנית לפני המעבר לטרנזילה.', body: h('div', { class: 'icol-secret', text: r.url }), actions: [{ label: 'העתקה', kind: 'primary', onClick: function () { return navigator.clipboard.writeText(r.url).then(function () { toast('הועתק', 'ok'); }); } }] }); }).catch(fail); }),
			actionBtn(d, 'adjust', 'התאמה כספית', '', function () { adjustModal(d); }),
			actionBtn(d, 'resolve_dispute', 'סגירת מחלוקת', '', function () { resolveFlagModal(d, 'resolve-dispute', 'סגירת מחלוקת'); }),
			actionBtn(d, 'resolve_account_claim', 'החלטה על טענת פתיחת חשבון', '', function () { resolveFlagModal(d, 'resolve-account-claim', 'החלטה על טענת פתיחת חשבון'); }),
			can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: 'הערה פנימית', onclick: function () { simpleModal('הערה פנימית', 'ההערה מסומנת כפנימית ולא תישלח ללקוח.', [field('text', 'הערה', 'textarea', { required: true })], function (fs) { return api.post('/cases/' + c.id + '/notes', { text: val(fs[0]) }); }, d); } }) : null,
			can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: 'תיעוד שיחה', onclick: function () { contactModal(d); } }) : null,
			can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: 'העברת בעלות', onclick: function () { ownerModal(d); } }) : null,
			actionBtn(d, 'close', 'סגירת תיק', '', function () { reasonModal('סגירת תיק', 'כל הפריטים הוסדרו או בוטלו.', '/cases/' + c.id + '/close', d); })
		]);
		add(page, bar);

		var tabs = [['items', 'פריטי חוב ותנועות'], ['timeline', 'התכתבות וציר זמן'], ['promises', 'התחייבויות והסדרים'], ['payment', 'אמצעי תשלום'], ['tasks', 'משימות'], ['customer', 'פרטי לקוח והסכם'], ['audit', 'יומן שינויים']];
		var tabBar = h('div', { class: 'icol-tabs', role: 'tablist' });
		var body = h('div');
		tabs.forEach(function (t) { add(tabBar, h('button', { class: currentTab === t[0] ? 'on' : '', role: 'tab', 'aria-selected': currentTab === t[0] ? 'true' : 'false', text: t[1], onclick: function () { currentTab = t[0]; renderCase(d); } })); });
		add(page, [tabBar, body]);
		({ items: tabItems, timeline: tabTimeline, promises: tabPromises, payment: tabPayment, tasks: tabTasks, customer: tabCustomer, audit: tabAudit })[currentTab](d, body);
	}

	function tabItems(d, body) {
		d.finance.items.forEach(function (it) {
			var card = h('div', { class: 'icol-card', style: 'margin-bottom:10px' });
			add(card, h('div', { class: 'icol-page-head', style: 'margin-bottom:6px' },
				h('div', {}, h('h3', { text: (it.description || 'פריט חוב') + ' · מועד ' + it.due_at }), h('div', { class: 'muted', text: 'בעלות על ניסיון הגבייה: ' + ({ my_billing: 'My Billing', separate_payment: 'תשלום נפרד', manual: 'טיפול ידני' }[it.collection_owner] || it.collection_owner) + (it.approved_by_name ? ' · אושר בידי ' + it.approved_by_name : ' · טרם אושר') })),
				h('div', { class: 'icol-mode' }, chip(L.finance[it.finance_state] || it.finance_state, it.finance_state === 'settled' ? 'ok' : (it.finance_state === 'review' ? 'bad' : (it.finance_state === 'draft' ? 'warn' : 'info'))), chip('מקורי ' + money(it.original_amount_minor, it.currency)), chip('יתרה ' + money(it.cached_balance_minor, it.currency), 'violet'))));
			if (it.review_reason) { add(card, h('div', { class: 'icol-banner warn', text: 'בבירור: ' + it.review_reason })); }
			if (it.approval_basis) { add(card, h('div', { class: 'muted', text: 'בסיס החיוב: ' + it.approval_basis })); }
			var rows = [];
			(it.attempts || []).forEach(function (a) { rows.push({ t: a.occurred_local, k: 'ניסיון חיוב', d: (a.response_code === '000' ? 'הצליח' : 'נכשל · קוד ' + a.response_code + (a.failure_label ? ' · ' + a.failure_label : '')) + ' · ' + a.terminal + ' #' + a.transaction_index, a: a.amount_minor }); });
			(it.allocations || []).forEach(function (a) { rows.push({ t: a.created_at, k: Number(a.amount_minor) < 0 ? 'היפוך שיוך' : 'שיוך תקבול', d: (a.reason || '') + ' · ' + a.provider + ' ' + a.transaction_id, a: -a.amount_minor }); });
			(it.adjustments || []).forEach(function (a) { rows.push({ t: a.created_at, k: 'התאמה: ' + a.type, d: a.reason + (a.evidence_ref ? ' · ' + a.evidence_ref : ''), a: a.amount_minor }); });
			add(card, table([['t', 'מועד'], ['k', 'תנועה'], ['d', 'פרטים'], ['a', 'השפעה על היתרה', function (r) { return h('span', { class: 'num', text: r.a === null || r.a === undefined ? ', ' : money(r.a) }); }, 'num']], rows));
			add(body, card);
		});
		if (!d.finance.items.length) { add(body, h('div', { class: 'icol-empty', text: 'אין פריטי חוב' })); }
		if (can('icol_create_draft') && d.case.workflow_state !== 'closed') {
			add(body, h('button', { class: 'icol-btn sm', text: 'הוספת פריט חוב', onclick: function () { addItemModal(d); } }));
		}
		if (d.requests.length) {
			add(body, h('h3', { style: 'margin:14px 0 8px', text: 'בקשות תשלום' }));
			add(body, table([['id', '#'], ['amount', 'סכום', function (r) { return money(r.amount_minor, r.currency); }], ['status', 'מצב'], ['opened_at', 'נפתח'], ['provider_pr_id', 'מזהה ספק'], ['created_at', 'נוצר']], d.requests));
		}
	}
	function tabTimeline(d, body) {
		var tl = h('div', { class: 'icol-timeline' });
		d.timeline.forEach(function (m) {
			var cls = { in: 'in', out: 'out', note: 'note', ai: 'ai', imported: 'imported', system: 'system' }[m.type] || 'system';
			var head = h('div', { class: 'mh' }, h('span', { text: m.local + (m.author ? ' · ' + m.author : '') }), h('span', {},
				m.type === 'note' ? h('span', { class: 'flag', text: 'פנימי, לא נשלח ללקוח' }) : null,
				m.type === 'ai' ? h('span', { class: 'flag', text: 'הצעת AI לנציג, לא נשלחה' }) : null,
				m.type === 'imported' ? 'יובא · ' : null,
				m.state && m.type === 'out' ? (L.delivery[m.state] || m.state) : null,
				m.intent ? ' · כוונה: ' + m.intent : null,
				m.counts ? ' · נספר במכסה' : null));
			add(tl, h('div', { class: 'icol-msg ' + cls }, m.type !== 'system' ? head : null, m.body || ''));
		});
		if (!d.timeline.length) { add(tl, h('div', { class: 'icol-empty', text: 'אין התכתבות' })); }
		add(body, tl);
	}
	function tabPromises(d, body) {
		add(body, table([['promised_at', 'מועד'], ['amount', 'סכום', function (p) { return p.amount_minor ? money(p.amount_minor) : null; }], ['state', 'מצב', function (p) { return { requested: 'בקשה', approved: 'מאושרת', kept: 'קוימה', broken: 'הופרה', cancelled: 'בוטלה' }[p.state] || p.state; }], ['source', 'מקור'], ['note', 'הערה'],
			['act', '', function (p) { return p.state === 'requested' && can('icol_work_case') ? h('span', { class: 'icol-actions' }, h('button', { class: 'icol-btn sm primary', text: 'אישור', onclick: function () { api.post('/promises/' + p.id + '/approve', { note: '' }).then(function () { toast('ההבטחה אושרה', 'ok'); reload(d); }).catch(fail); } }), h('button', { class: 'icol-btn sm', text: 'דחייה', onclick: function () { simpleModal('דחיית בקשת מועד', '', [field('note', 'סיבה', 'textarea', { required: true })], function (fs) { return api.post('/promises/' + p.id + '/reject', { note: val(fs[0]) }); }, d); } })) : null; }]], d.promises));
		add(body, h('p', { class: 'muted', text: 'הבטחת לקוח יוצרת מועד מעקב תפעולי. מועד הפירעון החשבונאי אינו משתנה.' }));
	}
	function tabPayment(d, body) {
		if (!d.order) { add(body, h('div', { class: 'icol-empty', text: 'אין הוראת קבע משויכת לתיק' })); return; }
		add(body, h('div', { class: 'icol-card' }, kv([['מסוף', d.order.terminal], ['מזהה הוראה', d.order.sto_id], ['מצב כרטיס', L.card[d.order.card_status] || d.order.card_status], ['בסיס', d.order.card_status_basis], ['חיוב הבא', d.order.next_due_at], ['יום חיוב', d.order.charge_dom], ['תשלומים עתידיים', d.order.future_installments]])));
		if (d.card_tasks.length) {
			add(body, h('h3', { style: 'margin:14px 0 8px', text: 'משימות עדכון כרטיס' }));
			add(body, table([['id', '#'], ['state', 'מצב', function (t) { return L.task[t.state] || t.state; }], ['due_at', 'יעד'], ['urgency', 'דחיפות'], ['confirmation_ref', 'אסמכתה'], ['note', 'הערה']], d.card_tasks));
			add(body, h('a', { href: '#/cards', text: 'לטיפול במסך כרטיסים לעדכון' }));
		}
	}
	function tabTasks(d, body) {
		add(body, table([['type', 'סוג'], ['reason', 'סיבה'], ['due_at', 'יעד'], ['status', 'מצב'], ['priority', 'דחיפות'],
			['act', '', function (t) { return t.status === 'open' && t.type !== 'card_update' && can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: 'סימון כבוצע', onclick: function () { completeTask(t.id, function () { reload(d); }); } }) : null; }]], d.tasks));
	}
	function tabCustomer(d, body) {
		var cu = d.customer;
		add(body, h('div', { class: 'icol-split' },
			h('div', { class: 'icol-card' }, h('h3', { text: 'לקוח' }), kv([['שם', cu.full_name], ['שם פרטי', cu.first_name ? cu.first_name + (Number(cu.first_name_reliable) ? ' (אמין לפנייה)' : ' (לא בשימוש בפנייה)') : ''], ['טלפון', cu.phone], ['מייל', cu.email], ['משתמש וורדפרס', cu.wp_user_id]]),
				h('div', { class: 'icol-actions', style: 'margin-top:12px' },
					can('icol_approve_debt') && cu.contact_status !== 'verified' ? h('button', { class: 'icol-btn sm', text: 'אימות מספר וזהות', onclick: function () { simpleModal('אימות מספר וזהות', 'מאשרים שהמספר שייך ללקוח הזה. במספר משותף זה גם אישור זהות.', [field('note', 'איך אומת', 'textarea', { required: true })], function (fs) { return api.post('/customers/' + cu.id + '/verify-contact', { note: val(fs[0]) }); }, d); } }) : null,
					can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: cu.permission === true ? 'חסימת וואטסאפ' : 'רישום הרשאת וואטסאפ', onclick: function () { simpleModal(cu.permission === true ? 'חסימת פניות בוואטסאפ' : 'רישום הרשאת קשר', 'נשמר מקור ההחלטה.', [field('source', 'מקור (לדוגמה: סעיף בהסכם, בקשת הלקוח)', 'text', { required: true })], function (fs) { return api.post('/customers/' + cu.id + '/permission', { allowed: cu.permission !== true, source: val(fs[0]) }); }, d); } }) : null,
					can('icol_work_case') ? h('button', { class: 'icol-btn sm', text: 'בעלות על השיחה', onclick: function () { var s = field('owner', 'בעלות', 'select', { value: cu.conversation_owner, options: [['none', 'ללא'], ['collections', 'גבייה'], ['onboarding', 'סוכן ההרשמה'], ['human', 'נציג']] }); simpleModal('בעלות על השיחה', 'רק בעל השיחה רשאי לענות ללקוח. הערך מסונכרן ל-WATI.', [s, field('note', 'סיבה', 'text')], function (fs) { return api.post('/customers/' + cu.id + '/conversation-owner', { owner: val(fs[0]), note: val(fs[1]) }); }, d); } }) : null)),
			h('div', { class: 'icol-card' }, h('h3', { text: 'הסכם' }), d.agreement ? kv([['תוכנית', d.agreement.program], ['אסמכתה', d.agreement.reference], ['מסמך', d.agreement.document_ref], ['הצטרפות', d.agreement.joined_at], ['מועד אחרון לפתיחת חשבון', d.agreement.account_open_deadline], ['הארכות', d.agreement.extensions_json], ['בדיקת סטטוס', d.agreement.status_checked_at]]) : h('div', { class: 'muted', text: 'לא הוזן הסכם' }))
		));
	}
	function tabAudit(d, body) {
		add(body, table([['local', 'מועד'], ['actor', 'מבצע'], ['action', 'פעולה'], ['entity', 'ישות'], ['reason', 'סיבה']], d.audit));
	}

	/* ---------- case modals ---------- */
	function simpleModal(title, lead, fields, submit, d, okMsg) {
		modal({ title: title, lead: lead, body: h('div', { class: 'icol-form' }, fields.map(function (f2) { return f2.el; })), actions: [{ label: 'שמירה', kind: 'primary', onClick: function () { return submit(fields).then(function () { toast(okMsg || 'נשמר', 'ok'); if (d) { reload(d); } else { route(); } }); } }] });
	}
	function reasonModal(title, lead, path, d) {
		simpleModal(title, lead, [field('reason', 'סיבה', 'textarea', { required: true })], function (fs) { return api.post(path, { version: d.case.version, reason: val(fs[0]) }); }, d);
	}
	function approveModal(d) {
		var items = d.finance.items.filter(function (i) { return !i.approved_at; });
		var basis = field('approval_basis', 'בסיס החיוב ואסמכתה', 'textarea', { required: true, value: items[0] && items[0].approval_basis || '', help: 'לדוגמה: לפי סעיף 4 בהסכם ההצטרפות, המועד לפתיחת חשבון עבר ב־30/09 ולא נפתח חשבון.' });
		modal({ title: 'אישור חוב', lead: 'האישור נרשם עם שמך ומועד האישור. הוא לא שולח הודעה ולא מחייב כרטיס.',
			body: h('div', { class: 'icol-form' }, h('div', { class: 'icol-summary' }, items.map(function (i) { return h('div', { class: 'row2' }, h('span', { text: (i.description || 'פריט') + ' · ' + i.due_at }), h('strong', { class: 'num', text: money(i.original_amount_minor, i.currency) })); })), basis.el),
			actions: [{ label: 'אישור ' + items.length + ' פריטים', kind: 'primary', onClick: function () { return api.post('/cases/' + d.case.id + '/approve', { approval_basis: val(basis) }).then(function () { toast('החוב אושר', 'ok'); reload(d); }); } }] });
	}
	function previewModal(d) {
		api.post('/cases/' + d.case.id + '/preview').then(function (p) {
			var draft = d.case.workflow_state === 'draft';
			var scopeName = { case: 'חוסם', transient: 'זמני', setup: 'הקמה' };
			var blockers = h('ul', { class: 'icol-blockers' }, (p.blockers || []).map(function (b) { return h('li', { class: b.scope, text: '[' + (scopeName[b.scope] || b.scope) + '] ' + b.message }); }));
			var caseBlock = (p.blockers || []).filter(function (b) { return b.scope === 'case' && b.code !== 'state'; });
			var summary = h('div', { class: 'icol-summary' },
				h('div', { class: 'row2' }, h('span', { text: 'לקוח' }), h('strong', { text: d.customer.full_name })),
				h('div', { class: 'row2' }, h('span', { text: 'מקור החוב' }), h('span', { text: L.source[d.case.source_type] })),
				draft && !p.finance_summary.due_balance_minor
					? h('div', { class: 'row2' }, h('span', { text: 'סכום לגבייה לאחר אישור' }), h('strong', { class: 'num', text: money(p.finance_summary.draft_minor, p.finance_summary.currency) }))
					: h('div', { class: 'row2' }, h('span', { text: 'יתרה שהגיע מועדה' }), h('strong', { class: 'num', text: money(p.finance_summary.due_balance_minor, p.finance_summary.currency) })),
				p.finance_summary.not_due_minor ? h('div', { class: 'row2' }, h('span', { text: 'טרם הגיע מועד' }), h('span', { class: 'num', text: money(p.finance_summary.not_due_minor) })) : null,
				h('div', { class: 'row2' }, h('span', { text: 'מועד פנייה' }), h('strong', { text: p.send_after_local })),
				h('div', { class: 'row2' }, h('span', { text: 'תבנית' }), h('span', { text: p.template_id + ' · גרסה ' + p.template_version })),
				h('div', { class: 'row2' }, h('span', { text: 'מצב משלוח' }), h('span', { text: p.mode === 'live' ? 'חי' : 'סימולציה (לא יישלח)' })),
				p.link_route && !p.link_route.allowed ? h('div', { class: 'row2' }, h('span', { text: 'קישור תשלום' }), h('span', { text: p.link_route.reason })) : null);
			var acts = [];
			if (draft) {
				acts.push({ label: 'הפעלת גבייה: ' + money(p.finance_summary.due_balance_minor || p.finance_summary.draft_minor) + ' · פנייה ראשונה ' + p.send_after_local, kind: 'primary', disabled: !d.actions.activate.allowed || caseBlock.length > 0,
					onClick: function () { return api.post('/cases/' + d.case.id + '/activate', { version: d.case.version, balance_version: p.balance_version }).then(function () { toast('התיק הופעל', 'ok'); reload(d); }); } });
			}
			modal({ title: draft ? 'סיכום לפני הפעלה' : 'ההודעה הבאה', lead: draft ? 'זה בדיוק מה שיישלח ומתי. ההפעלה בודקת שוב שהיתרה לא השתנתה.' : 'כך תיראה ההודעה הבאה. לפני שליחה המערכת בודקת הכול מחדש.', wide: true,
				body: h('div', {}, summary, h('div', { class: 'icol-section-title', style: 'margin-top:12px', text: 'נוסח ההודעה' }), h('div', { class: 'icol-preview', text: p.rendered_text }), (p.blockers || []).length ? [h('div', { class: 'icol-section-title', text: 'חסימות ובדיקות' }), blockers] : h('div', { class: 'muted', text: 'אין חסימות כרגע.' })),
				actions: acts, cancelLabel: 'סגירה' });
		}).catch(fail);
	}
	function promiseModal(d) {
		var fs = [field('date', 'מועד תשלום', 'date', { required: true }), field('amount', 'סכום מובטח (אופציונלי)', 'text', { inputmode: 'decimal' }), field('note', 'הערה', 'textarea'), field('approve', 'לאשר עכשיו (במקום בקשה שממתינה)', 'checkbox')];
		simpleModal('רישום הבטחת תשלום', 'עד המועד לא יישלחו תזכורות. בדיקת תשלום תתבצע ביום העסקים שאחרי.', fs, function () { return api.post('/cases/' + d.case.id + '/promises', { date: val(fs[0]), amount: val(fs[1]), note: val(fs[2]), approve: val(fs[3]) }); }, d);
	}
	function paymentModal(d) {
		var open = d.finance.items.filter(function (i) { return i.finance_state === 'open' || i.finance_state === 'partially_paid' || i.finance_state === 'not_due'; });
		var amount = field('amount', 'סכום התקבול', 'text', { required: true, inputmode: 'decimal' });
		var method = field('method', 'אמצעי', 'select', { options: Object.keys(L.method).map(function (k) { return [k, L.method[k]]; }) });
		var evidence = field('evidence_ref', 'אסמכתה (מספר העברה / אישור)', 'text', { required: true, help: 'צילום מסך או הודעת "שילמתי" אינם אסמכתה.' });
		var paid = field('paid_at', 'תאריך תשלום', 'date');
		var doc = field('document_ref', 'מספר קבלה במערכת הקיימת', 'text');
		var allocs = open.map(function (i) { return { item: i, f: field('alloc_' + i.id, (i.description || 'פריט') + ' · יתרה ' + money(i.cached_balance_minor), 'text', { inputmode: 'decimal', placeholder: '0' }) }; });
		modal({ title: 'אימות תשלום חיצוני', lead: 'שיוך מפורש לכל פריט. סכום שלא שויך יישאר כתקבול לא מוקצה לבירור.', wide: true,
			body: h('div', { class: 'icol-form' }, h('div', { class: 'icol-fields' }, amount.el, method.el, evidence.el, paid.el, doc.el), h('div', { class: 'icol-section-title', text: 'שיוך לפריטים' }), h('div', { class: 'icol-fields' }, allocs.map(function (a) { return a.f.el; }))),
			actions: [{ label: 'אימות ושיוך', kind: 'primary', onClick: function () {
				var list = allocs.filter(function (a) { return val(a.f) !== '' && val(a.f) !== '0'; }).map(function (a) { return { debt_item_id: a.item.id, amount: val(a.f) }; });
				return api.post('/payments/manual-verifications', { customer_id: d.customer.id, amount: val(amount), currency: d.finance.currency || 'ILS', method: val(method), evidence_ref: val(evidence), paid_at: val(paid), document_ref: val(doc), allocations: list }).then(function (r) { toast('התקבול נרשם' + (r.unallocated_minor ? ' · לא מוקצה: ' + money(r.unallocated_minor) : ''), 'ok'); reload(d); });
			} }] });
	}
	function adjustModal(d) {
		var item = field('debt_item_id', 'פריט', 'select', { options: d.finance.items.map(function (i) { return [i.id, (i.description || 'פריט') + ' · יתרה ' + money(i.cached_balance_minor)]; }) });
		var type = field('type', 'סוג', 'select', { options: [['credit', 'זיכוי'], ['write_off', 'מחיקה מאושרת'], ['correction_decrease', 'תיקון: הפחתה'], ['correction_increase', 'תיקון: הגדלה']] });
		var fs = [item, type, field('amount', 'סכום', 'text', { required: true, inputmode: 'decimal' }), field('reason', 'סיבה', 'textarea', { required: true }), field('evidence_ref', 'אסמכתה', 'text')];
		simpleModal('התאמה כספית', 'התאמה היא תנועה נפרדת עם סכום, סיבה, מבצע ואסמכתה. אין עריכה חופשית של יתרה.', fs, function () { return api.post('/cases/' + d.case.id + '/adjustments', { debt_item_id: Number(val(fs[0])), type: val(fs[1]), amount: val(fs[2]), reason: val(fs[3]), evidence_ref: val(fs[4]) }); }, d);
	}
	function resolveFlagModal(d, path, title) {
		var fs = [field('outcome', 'החלטה', 'select', { options: [['continue', 'להמשיך בגבייה'], ['adjust', 'התאמת סכום (בפעולה נפרדת)'], ['close', 'סגירת החוב (מחיקה בפעולה נפרדת)']] }), field('note', 'נימוק ואסמכתה', 'textarea', { required: true })];
		simpleModal(title, 'ההחלטה מתועדת. כדי לחזור לפניות יש לבחור אחר כך "חידוש טיפול".', fs, function () { return api.post('/cases/' + d.case.id + '/' + path, { outcome: val(fs[0]), note: val(fs[1]) }); }, d);
	}
	function contactModal(d) {
		var fs = [field('channel', 'ערוץ', 'select', { options: [['phone', 'טלפון'], ['whatsapp', 'וואטסאפ ידני'], ['email', 'מייל'], ['sms', 'SMS']] }), field('summary', 'תקציר', 'textarea', { required: true }), field('proactive', 'פנייה יזומה (נספרת במכסה)', 'checkbox', { value: true })];
		simpleModal('תיעוד שיחה או פנייה ידנית', 'פניות ידניות משפיעות על המשך הרצף ועל מכסת הפניות.', fs, function () { return api.post('/cases/' + d.case.id + '/contact-log', { channel: val(fs[0]), summary: val(fs[1]), proactive: val(fs[2]) }); }, d);
	}
	function ownerModal(d) {
		api.get('/staff').then(function (staff) {
			var fs = [field('owner_id', 'אחראי', 'select', { value: d.case.owner_id || '', options: [['', 'תור גבייה כללי']].concat(staff.map(function (s) { return [s.id, s.name + ' · ' + roleLabel(s.role)]; })) }), field('reason', 'סיבה', 'text')];
			simpleModal('העברת בעלות', '', fs, function () { return api.post('/cases/' + d.case.id + '/owner', { version: d.case.version, owner_id: Number(val(fs[0])) || 0, reason: val(fs[1]) }); }, d);
		}).catch(fail);
	}
	function addItemModal(d) {
		var fs = [field('amount', 'סכום', 'text', { required: true, inputmode: 'decimal' }), field('due_at', 'מועד פירעון', 'date', { required: true }), field('description', 'תיאור', 'text'), field('evidence_ref', 'אסמכתה', 'text'), field('basis', 'בסיס החיוב', 'textarea', { required: true })];
		simpleModal('הוספת פריט חוב', 'הפריט נשמר כטיוטה עד לאישור.', fs, function () { return api.post('/cases/' + d.case.id + '/items', { amount: val(fs[0]), due_at: val(fs[1]), description: val(fs[2]), evidence_ref: val(fs[3]), basis: val(fs[4]) }); }, d);
	}
	function completeTask(id, after) {
		simpleModal('סימון משימה כבוצעה', '', [field('note', 'מה בוצע', 'textarea', { required: true })], function (fs) { return api.post('/tasks/' + id + '/complete', { note: val(fs[0]) }).then(function () { if (after) { after(); } }); });
	}

	/* ---------- new debt / handover ---------- */
	function customerPicker(onPick) {
		var wrap = h('div', { class: 'icol-card' });
		var chosen = h('div');
		var search = h('input', { type: 'search', placeholder: 'חיפוש לפי שם, טלפון או מייל' });
		var results = h('div', { class: 'icol-grid', style: 'margin-top:8px' });
		var timer = null;
		search.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () {
				if (search.value.trim().length < 2) { clear(results); return; }
				api.get('/customers', { q: search.value.trim() }).then(function (rows) {
					clear(results);
					rows.forEach(function (r) { add(results, h('button', { class: 'icol-btn sm', type: 'button', text: r.full_name + ' · ' + (r.phone_e164 || '') + ' · ' + (r.email || ''), onclick: function () { pick(r); } })); });
					if (!rows.length) { add(results, h('div', { class: 'muted', text: 'לא נמצא. אפשר ליצור לקוח חדש.' })); }
				}).catch(fail);
			}, 250);
		});
		function pick(r) { clear(chosen); add(chosen, h('div', { class: 'icol-banner sim' }, h('div', {}, h('strong', { text: 'לקוח: ' + r.full_name }), ' · ' + (r.phone_e164 || '') + ' · ' + (r.contact_status === 'verified' ? 'טלפון מאומת' : 'טלפון לא מאומת')), h('button', { class: 'icol-btn sm ghost', type: 'button', text: 'החלפה', onclick: function () { clear(chosen); onPick(null); } }))); onPick(r); clear(results); search.value = ''; }
		var newBtn = h('button', { class: 'icol-btn sm', type: 'button', text: 'לקוח חדש', onclick: function () { newCustomerModal(pick); } });
		add(wrap, [h('h3', { text: 'לקוח' }), h('div', { class: 'icol-filters' }, search, newBtn), results, chosen]);
		return wrap;
	}
	function newCustomerModal(pick) {
		var fs = { full_name: field('full_name', 'שם מלא', 'text', { required: true }), first_name: field('first_name', 'שם פרטי לפנייה', 'text'), reliable: field('first_name_reliable', 'השם הפרטי אמין לפנייה אישית', 'checkbox'), phone: field('phone', 'טלפון', 'tel', { required: true }), verified: field('phone_verified', 'המספר אומת מול הלקוח', 'checkbox'), email: field('email', 'מייל', 'email'), perm: field('contact_permission_ref', 'מקור הרשאת קשר בוואטסאפ', 'text', { help: 'לדוגמה: הסכם הצטרפות סעיף 9' }), wp: field('wp_user_id', 'מזהה משתמש וורדפרס (אם יש)', 'text') };
		var dup = h('div');
		var confirmed = false;
		modal({ title: 'לקוח חדש', lead: 'לפני יצירה נבדקות כפילויות לפי טלפון, מייל ומשתמש. אין מיזוג אוטומטי.', wide: true,
			body: h('div', { class: 'icol-form' }, h('div', { class: 'icol-fields' }, Object.keys(fs).map(function (k) { return fs[k].el; })), dup),
			actions: [{ label: 'בדיקה ויצירה', kind: 'primary', onClick: function () {
				return api.get('/customers/duplicates', { phone: val(fs.phone), email: val(fs.email), wp_user_id: val(fs.wp) }).then(function (rows) {
					if (rows.length && !confirmed) {
						clear(dup);
						add(dup, h('div', { class: 'icol-banner warn' }, h('div', {}, h('strong', { text: 'נמצאו רשומות דומות: ' }), rows.map(function (r) { return r.full_name + ' (' + (r.phone_e164 || r.email || '') + ')'; }).join(' · '), '. אם זה לקוח אחר, לחצו שוב לאישור. אם זה אותו לקוח, סגרו ובחרו אותו בחיפוש.')));
						confirmed = true;
						return true;
					}
					return api.post('/customers', { full_name: val(fs.full_name), first_name: val(fs.first_name), first_name_reliable: val(fs.reliable), phone: val(fs.phone), phone_verified: val(fs.verified), email: val(fs.email), contact_permission_ref: val(fs.perm), wp_user_id: val(fs.wp) }).then(function (r) {
						pick({ id: r.customer_id, full_name: val(fs.full_name), phone_e164: val(fs.phone), email: val(fs.email), contact_status: val(fs.verified) ? 'verified' : 'unverified' });
					});
				});
			} }] });
	}
	function itemsEditor() {
		var box = h('div', { class: 'icol-repeat' });
		var rows = [];
		function addRow() {
			var r = { amount: field('amount', 'סכום', 'text', { inputmode: 'decimal', required: true }), due: field('due_at', 'מועד פירעון', 'date', { required: true }), desc: field('description', 'תיאור', 'text') };
			var del = h('button', { class: 'icol-btn sm ghost', type: 'button', text: 'הסרה', onclick: function () { row.remove(); rows.splice(rows.indexOf(r), 1); } });
			var row = h('div', { class: 'icol-repeat-row', 'data-field': 'debt_items.' + rows.length }, r.amount.el, r.due.el, r.desc.el, del);
			rows.push(r);
			add(box, row);
		}
		addRow();
		return { el: h('div', {}, box, h('button', { class: 'icol-btn sm', type: 'button', text: 'פריט נוסף', onclick: addRow, style: 'margin-top:8px' })), values: function () { return rows.map(function (r) { return { amount: val(r.amount), due_at: val(r.due), description: val(r.desc) }; }); } };
	}
	function viewNewCase(handover) {
		var customer = null;
		clear(page);
		add(page, pageHead(handover ? 'המשך טיפול' : 'חוב חדש', handover ? 'הזנת לקוח שכבר קיבל פניות. ברירת המחדל אחרי השמירה היא טיוטה, עם תצוגה מקדימה של מה יישלח ומתי.' : 'הזנה ידנית של חוב. נשמר כטיוטה; אחראי גבייה מאשר את הבסיס והסכום לפני הפעלה.'));
		var form = h('div', { class: 'icol-form' });
		add(form, customerPicker(function (c) { customer = c; }));
		var src = field('source_type', 'מקור החוב', 'select', { value: 'non_open_charge', options: [['non_open_charge', 'אי פתיחת חשבון במסגרת תוכנית הליווי'], ['recurring_failure', 'חיוב חוזר שלא עבר'], ['other', 'אחר']] });
		var owner = field('owner_id', 'בעל התיק', 'select', { options: [['', 'תור גבייה כללי']] });
		api.get('/staff').then(function (s) { s.forEach(function (u) { add(owner.input, h('option', { value: u.id, text: u.name + ' · ' + roleLabel(u.role) })); }); }).catch(function () {});
		var agr = { program: field('agreement.program', 'תוכנית', 'text', { value: 'תוכנית ליווי למתחילים' }), reference: field('agreement.reference', 'מספר הסכם / אסמכתה', 'text'), doc: field('agreement.document_ref', 'קישור למסמך ההסכם', 'text'), joined: field('agreement.joined_at', 'תאריך הצטרפות', 'date'), deadline: field('agreement.account_open_deadline', 'מועד אחרון לפתיחת חשבון', 'date'), ext: field('agreement.extensions', 'הארכות שניתנו', 'text', { placeholder: 'לדוגמה: עד 15/10 באישור מיכל' }), checked: field('agreement.status_checked_at', 'תאריך בדיקת סטטוס', 'date') };
		var items = itemsEditor();
		var basis = field('approval_basis', 'הסבר קצר לחיוב ופרטי המאשר', 'textarea', { wide: true });
		var clar = field('clarification_first', 'להתחיל בשלב בירור ("כבר פתחת חשבון?") לפני דרישת תשלום', 'checkbox', { wide: true });
		var sections = [h('div', { class: 'icol-card' }, h('h3', { text: 'החוב' }), h('div', { class: 'icol-fields' }, src.el, owner.el), h('div', { class: 'icol-section-title', style: 'margin:12px 0 8px', text: 'פריטי חוב' }), items.el, h('div', { class: 'icol-fields', style: 'margin-top:12px' }, basis.el, clar.el)),
			h('div', { class: 'icol-card' }, h('h3', { text: 'הסכם ותוכנית' }), h('div', { class: 'icol-fields' }, Object.keys(agr).map(function (k) { return agr[k].el; })))];
		var hv = {};
		if (handover) {
			hv = { mode: field('history_mode', 'שיטת יתרה', 'select', { options: [['net_opening', 'יתרת פתיחה נטו (תשלומי עבר כהיסטוריה בלבד)'], ['full_ledger', 'ספר תנועות מלא']] }), asof: field('opening_balance_as_of', 'יתרה נכון לתאריך', 'date', { required: true }),
				lastAt: field('last_contact_at', 'פנייה אחרונה, מועד', 'datetime-local'), lastCh: field('last_contact_channel', 'ערוץ', 'select', { options: [['whatsapp', 'וואטסאפ'], ['phone', 'טלפון'], ['email', 'מייל'], ['sms', 'SMS']] }), lastBy: field('last_contact_sender', 'שולח', 'text'), lastSum: field('last_contact_summary', 'תוכן או תקציר', 'textarea'),
				replyAt: field('last_reply_at', 'תגובה אחרונה, מועד', 'datetime-local'), replyText: field('last_reply_text', 'מה הלקוח אמר', 'textarea'),
				arrType: field('existing_arrangement.type', 'סיכום קיים', 'select', { options: [['', 'אין'], ['promise', 'הבטחת תשלום'], ['installments', 'פריסה'], ['clarification', 'בקשה לבירור'], ['dispute', 'מחלוקת']] }), arrDate: field('existing_arrangement.date', 'מועד', 'date'), arrAmount: field('existing_arrangement.amount', 'סכום', 'text'), arrBy: field('existing_arrangement.approved_by_label', 'מי אישר', 'text'), arrNote: field('existing_arrangement.note', 'פרטים', 'text'),
				nextType: field('next_action.type', 'פעולה הבאה', 'select', { options: [['', 'לפי המערכת'], ['reminder', 'תזכורת'], ['rep_call', 'שיחת נציג'], ['wait', 'המתנה'], ['clarify', 'בירור'], ['check_payment', 'בדיקת תשלום']] }), nextAt: field('next_action.at', 'מועד הפעולה', 'datetime-local'),
				paste: field('history_paste', 'הדבקת התכתבות קודמת', 'textarea', { wide: true, rows: 6, help: 'נשמרת עם זהות המזין. אין להציג ללקוח היכרות או הסכמה שאינן מתועדות.' }),
				prev: field('previous_outreach', 'מועדי פניות קודמות (לחישוב מכסה)', 'text', { wide: true, placeholder: 'YYYY-MM-DD, YYYY-MM-DD' }) };
			sections.push(h('div', { class: 'icol-card' }, h('h3', { text: 'יתרת פתיחה והיסטוריה' }), h('div', { class: 'icol-fields' }, hv.mode.el, hv.asof.el),
				h('div', { class: 'icol-section-title', style: 'margin:12px 0 8px', text: 'פנייה ותגובה אחרונות' }), h('div', { class: 'icol-fields' }, hv.lastAt.el, hv.lastCh.el, hv.lastBy.el, hv.lastSum.el, hv.replyAt.el, hv.replyText.el),
				h('div', { class: 'icol-section-title', style: 'margin:12px 0 8px', text: 'הסדר ופעולה הבאה' }), h('div', { class: 'icol-fields' }, hv.arrType.el, hv.arrDate.el, hv.arrAmount.el, hv.arrBy.el, hv.arrNote.el, hv.nextType.el, hv.nextAt.el),
				h('div', { class: 'icol-fields', style: 'margin-top:12px' }, hv.paste.el, hv.prev.el)));
		}
		add(form, sections);
		var save = h('button', { class: 'icol-btn primary', type: 'button', text: 'שמירת טיוטה' });
		save.addEventListener('click', function () {
			if (!customer) { toast('יש לבחור לקוח או ליצור לקוח חדש', 'err'); return; }
			var payload = { customer_id: customer.id, source_type: val(src), entry_mode: handover ? 'handover' : 'new', currency: 'ILS', owner_id: Number(val(owner)) || null, approval_basis: val(basis), clarification_first: val(clar), debt_items: items.values(),
				agreement: { program: val(agr.program), reference: val(agr.reference), document_ref: val(agr.doc), joined_at: val(agr.joined), account_open_deadline: val(agr.deadline), extensions: val(agr.ext) ? [{ note: val(agr.ext) }] : [], status_checked_at: val(agr.checked) } };
			if (handover) {
				var hist = [];
				if (val(hv.paste)) { hist.push({ import_mode: 'paste', entry_type: 'note', content: val(hv.paste), author_label: 'הדבקה' }); }
				val(hv.prev).split(/[,\s]+/).filter(function (x) { return /^\d{4}-\d{2}-\d{2}$/.test(x); }).forEach(function (dt) { hist.push({ import_mode: 'summary', entry_type: 'message_out', original_at: dt, content: 'פנייה קודמת (הוזנה לחישוב מכסה)', counts_toward_quota: true }); });
				Object.assign(payload, { history_mode: val(hv.mode), opening_balance_as_of: val(hv.asof), last_contact_at: val(hv.lastAt).replace('T', ' '), last_contact_channel: val(hv.lastCh), last_contact_sender: val(hv.lastBy), last_contact_summary: val(hv.lastSum), last_reply_at: val(hv.replyAt).replace('T', ' '), last_reply_text: val(hv.replyText), imported_history: hist,
					existing_arrangement: val(hv.arrType) ? { type: val(hv.arrType), date: val(hv.arrDate), amount: val(hv.arrAmount), approved_by_label: val(hv.arrBy), note: val(hv.arrNote) } : {}, next_action: val(hv.nextType) ? { type: val(hv.nextType), at: val(hv.nextAt).replace('T', ' ') } : {} });
			}
			save.disabled = true;
			api.post('/cases', payload).then(function (r) { toast('הטיוטה נשמרה', 'ok'); location.hash = '#/cases/' + r.case_id; }).catch(function (e) { save.disabled = false; showFieldErrors(form, e); fail(e); });
		});
		add(form, h('div', { class: 'icol-actions' }, save, h('span', { class: 'muted', text: 'שמירה אינה שולחת הודעה. ההפעלה נעשית מתוך התיק אחרי אישור ותצוגה מקדימה.' })));
		add(page, form);
	}

	/* ---------- exceptions ---------- */
	function viewExceptions() {
		api.get('/exceptions').then(function (rows) {
			clear(page);
			add(page, pageHead('תור חריגים', 'כל מה שהמערכת לא מכריעה לבד. פתרון חריג שומר את המיפוי והאסמכתה.'));
			add(page, table([
				['severity', 'חומרה', function (e) { return chip(L.severity[e.severity] || e.severity, e.severity === 'high' || e.severity === 'critical' ? 'bad' : (e.severity === 'medium' ? 'warn' : '')); }],
				['type', 'סוג', 'type_label'],
				['summary', 'פירוט', function (e) { return h('span', {}, e.summary, e.details && e.details.sto_id ? h('span', { class: 'sub', text: 'הוראה ' + e.details.sto_id + ' · מסוף ' + e.details.terminal }) : null); }],
				['opened', 'נפתח', 'opened_local'],
				['owner', 'בעלים', 'owner_name'],
				['act', '', function (e) { return h('span', { class: 'icol-actions' }, exceptionActions(e)); }]
			], rows));
		}).catch(fail);
	}
	function exceptionActions(e) {
		var out = [];
		if (!can('icol_resolve_exception')) { return out; }
		if (e.type === 'charge_without_cycle' && e.attempt && !e.attempt.debt_item_id) {
			out.push(h('button', { class: 'icol-btn sm blue', text: 'שיוך לתשלום', onclick: function () {
				var fs = [field('due_at', 'מועד התשלום המקורי', 'date', { required: true }), field('description', 'תיאור', 'text'), field('note', 'על מה מבוסס השיוך', 'textarea', { required: true }), field('activate', 'להפעיל את התיק אחרי השיוך', 'checkbox', { value: true })];
				simpleModal('שיוך ניסיון חיוב לתשלום', 'ניסיון של ' + money(e.attempt.amount_minor) + ' · קוד ' + e.attempt.response_code + ' · עסקה ' + e.attempt.transaction_index, fs, function () { return api.post('/attempts/' + e.attempt.id + '/match', { due_at: val(fs[0]), description: val(fs[1]), note: val(fs[2]), activate: val(fs[3]) }); });
			} }));
		}
		if ((e.type === 'payment_without_debt' || e.type === 'overpayment') && e.payment && e.payment.unallocated_minor > 0) {
			out.push(h('button', { class: 'icol-btn sm blue', text: 'שיוך תקבול', onclick: function () { allocateModal(e.payment); } }));
		}
		if (e.type === 'event_without_customer' && e.details && e.details.sto_id) {
			out.push(h('button', { class: 'icol-btn sm blue', text: 'מיפוי הוראה ללקוח', onclick: function () { mapStoModal(e.details); } }));
		}
		out.push(h('button', { class: 'icol-btn sm', text: 'סגירה עם תיעוד', onclick: function () { simpleModal('סגירת חריג', e.summary, [field('resolution', 'מה נעשה ואסמכתה', 'textarea', { required: true })], function (fs) { return api.post('/exceptions/' + e.id + '/resolve', { resolution: val(fs[0]) }); }); } }));
		return out;
	}
	function allocateModal(p) {
		var caseId = field('case', 'מספר תיק', 'text', { required: true });
		var itemSel = field('debt_item_id', 'פריט', 'select', { options: [] });
		var amount = field('amount', 'סכום לשיוך', 'text', { value: (p.unallocated_minor / 100).toFixed(2) });
		var note = field('note', 'בסיס לשיוך (נבדק מול מה?)', 'textarea', { required: true });
		caseId.input.addEventListener('change', function () {
			api.get('/cases/' + caseId.input.value.replace('#', '')).then(function (d) { clear(itemSel.input); d.finance.items.forEach(function (i) { add(itemSel.input, h('option', { value: i.id, text: (i.description || 'פריט') + ' · ' + i.due_at + ' · יתרה ' + money(i.cached_balance_minor) })); }); }).catch(fail);
		});
		simpleModal('שיוך תקבול', 'תקבול ' + p.provider + ' ' + p.transaction_id + ' · לא מוקצה ' + money(p.unallocated_minor) + '. אין שיוך אוטומטי לחוב הישן ביותר.', [caseId, itemSel, amount, note], function () { return api.post('/payments/' + p.id + '/allocate', { debt_item_id: Number(val(itemSel)), amount: val(amount), note: val(note) }); });
	}
	function mapStoModal(det) {
		var chosen = null;
		var picker = customerPicker(function (c) { chosen = c; });
		var fs = [field('terminal', 'מסוף', 'text', { value: det.terminal }), field('sto_id', 'מזהה הוראה', 'text', { value: det.sto_id }), field('charge_dom', 'יום חיוב בחודש', 'number'), field('future_installments', 'מספר תשלומים עתידיים', 'number'), field('next_due_at', 'חיוב הבא', 'date'), field('evidence_ref', 'אסמכתה למיפוי', 'text', { required: true })];
		modal({ title: 'מיפוי הוראת קבע ללקוח', lead: 'אחרי המיפוי, האירועים שחיכו להוראה הזו יעובדו מחדש.', wide: true, body: h('div', { class: 'icol-form' }, picker, h('div', { class: 'icol-fields' }, fs.map(function (f2) { return f2.el; }))),
			actions: [{ label: 'מיפוי', kind: 'primary', onClick: function () { if (!chosen) { return Promise.reject(new Error('יש לבחור לקוח')); } return api.post('/sto-mappings', { customer_id: chosen.id, terminal: val(fs[0]), sto_id: val(fs[1]), charge_dom: val(fs[2]), future_installments: val(fs[3]), next_due_at: val(fs[4]), evidence_ref: val(fs[5]) }).then(function () { toast('ההוראה מופתה', 'ok'); route(); }); } }] });
	}

	/* ---------- card tasks ---------- */
	function viewCards() {
		api.get('/card-tasks').then(function (rows) {
			clear(page);
			add(page, pageHead('כרטיסים לעדכון', 'הסדרת תשלום ועדכון הכרטיס לחיובים הבאים הם שני תהליכים נפרדים. "אושר ידנית" אינו "אומת מול ספק".'));
			add(page, table([
				['customer', 'לקוח', function (t) { return h('a', { href: '#/cases?q=' + encodeURIComponent(t.sto_id), text: t.full_name }); }],
				['sto', 'הוראה', function (t) { return h('span', {}, t.sto_id, h('span', { class: 'sub', text: 'מסוף ' + t.terminal })); }],
				['payment', 'עסקה ששולמה', function (t) { return h('span', {}, money(t.amount_minor), h('span', { class: 'sub', text: (L.method[t.payment_method] || t.payment_method) + ' · ' + t.pay_terminal + ' #' + t.transaction_id })); }],
				['card', 'כרטיס', function (t) { return t.has_credential > 0 ? h('span', { class: 'num', text: '•••• ' + (t.card_last4 || '') + (t.expiry ? ' · ' + t.expiry : '') }) : h('span', { class: 'muted', text: Number(t.has_reusable_token) ? 'טוקן לא נשמר' : 'אין טוקן לשימוש חוזר' }); }],
				['next', 'חיוב הבא', function (t) { return t.next_due_at || (t.future_installments ? t.future_installments + ' תשלומים' : null); }],
				['due', 'יעד', function (t) { return h('span', {}, t.due_local, t.overdue ? chip('באיחור', 'bad') : null, t.urgency === 'high' ? chip('דחוף', 'warn') : null); }],
				['state', 'מצב', function (t) { return L.task[t.state] || t.state; }],
				['act', '', function (t) { return h('span', { class: 'icol-actions' },
					can('icol_card_confirm') ? h('button', { class: 'icol-btn sm blue', text: 'עודכן ידנית', onclick: function () { confirmCard(t); } }) : null,
					can('icol_card_confirm') ? h('button', { class: 'icol-btn sm', text: 'בדיקה מול טרנזילה', onclick: function () { api.post('/card-update-tasks/' + t.id + '/verify').then(function (r) { toast(r.reason, r.verified ? 'ok' : 'err'); route(); }).catch(fail); } }) : null,
					can('icol_reveal_token') && t.has_credential > 0 ? h('button', { class: 'icol-btn sm', text: 'טוקן ותוקף', onclick: function () { revealToken(t.id); } }) : null); }]
			], rows));
		}).catch(fail);
	}
	function confirmCard(t) {
		var type = field('confirmation_type', 'סוג', 'select', { options: [['confirmed_manual', 'עודכן ידנית בהוראה'], ['not_required', 'לא נדרש, אין חיובים עתידיים']] });
		var sto = field('sto_id', 'מזהה ההוראה שעודכנה (הקלדה)', 'text', { required: true, help: 'ההוראה בתיק: ' + t.sto_id });
		var ev = field('evidence_ref', 'אסמכתה', 'text', { required: true, help: 'לדוגמה: צילום מסך מהמסוף, מספר פעולה' });
		var note = field('note', 'הערה', 'textarea');
		modal({ title: 'אישור עדכון כרטיס', lead: 'נרשם כ"אושר ידנית" עם שמך, הזמן והאסמכתה. אימות מול ספק נקבע רק מבדיקה מול טרנזילה.', body: h('div', { class: 'icol-form' }, type.el, sto.el, ev.el, note.el),
			actions: [{ label: 'אישור', kind: 'primary', onClick: function () { return api.post('/card-update-tasks/' + t.id + '/confirm', { confirmation_type: val(type), sto_id: val(sto), evidence_ref: val(ev), note: val(note), recurring_order_id: Number(t.recurring_order_id), source_payment_id: Number(t.source_payment_id) }).then(function () { toast('נשמר', 'ok'); route(); }); } }] });
	}
	function revealToken(id) {
		var show = function () {
			return api.post('/card-update-tasks/' + id + '/reveal').then(function (r) {
				var box = h('div', { class: 'icol-secret', text: r.token });
				var close = modal({ title: 'טוקן ותוקף', lead: 'מסוף ' + r.terminal + ' · הטוקן קשור למסוף הזה בלבד. הצפייה נרשמה ביומן. החלון ייסגר בעוד 60 שניות.', body: h('div', { class: 'icol-form' }, box, kv([['תוקף', r.expiry], ['ספרות אחרונות', r.last4]])),
					actions: [{ label: 'העתקת טוקן', kind: 'primary', onClick: function () { return navigator.clipboard.writeText(r.token).then(function () { toast('הועתק', 'ok'); return true; }); } }], cancelLabel: 'סגירה' });
				setTimeout(close, 60000);
			});
		};
		show().catch(function (e) {
			if (e.status !== 401) { fail(e); return; }
			var pw = field('password', 'סיסמה', 'password', { required: true });
			modal({ title: 'אימות מחדש', lead: 'צפייה בטוקן דורשת הזנת סיסמה. ההרשאה תקפה ל-10 דקות.', body: h('div', { class: 'icol-form' }, pw.el), actions: [{ label: 'אימות', kind: 'primary', onClick: function () { return api.post('/reauth', { password: pw.input.value }).then(show); } }] });
		});
	}

	/* ---------- candidates ---------- */
	function viewCandidates() {
		api.get('/candidates').then(function (res) {
			var rows = res.rows || [];
			clear(page);
			add(page, pageHead('מועמדים לחיוב, מדשבורד ההכנסות', 'תלמידים שעברו 90 יום מההצטרפות בלי לפתוח חשבון ובלי לשלם. המערכת לא קובעת חיוב בעצמה: יצירת טיוטה דורשת סכום, בסיס ואישור.'));
			if (res.sync && res.sync.stale) {
				add(page, h('div', { class: 'icol-banner stop', text: 'דשבורד ההכנסות לא סנכרן את פייפדרייב ' + (res.sync.age_hours === null ? 'אף פעם' : res.sync.age_hours + ' שעות') + '. מועמדים חדשים לא נקלטים עד שהסנכרון יתעדכן, כי ייתכן שתלמיד פתח חשבון והדשבורד עוד לא יודע.' }));
			} else if (res.sync) {
				add(page, h('p', { class: 'muted', text: 'סנכרון אחרון של הדשבורד מול פייפדרייב: ' + res.sync.ok_at }));
			}
			add(page, table([
				['name', 'תלמיד', function (c) { var s = c.snapshot || {}; return h('span', {}, s.name || ('#' + (c.pipedrive_person_id || c.wp_user_id)), h('span', { class: 'sub', text: [s.phone, s.email].filter(Boolean).join(' · ') || (s.enrich_error ? 'פרטי קשר לא נמשכו מפייפדרייב' : '') })); }],
				['owner', 'נציג', function (c) { return (c.snapshot || {}).owner_name || null; }],
				['joined', 'הצטרפות', function (c) { return (c.snapshot || {}).joined_at; }],
				['deadline', 'מועד אחרון', function (c) { return h('span', {}, c.deadline, (c.snapshot || {}).extension_until ? h('span', { class: 'sub', text: 'כולל הארכה' }) : null); }],
				['days', 'ימים מאז', function (c) { var d = (c.snapshot || {}).days_left; return d !== null && d !== undefined ? h('span', { class: 'num', text: String(-d) }) : null; }],
				['act', '', function (c) { return can('icol_create_draft') ? h('span', { class: 'icol-actions' }, h('button', { class: 'icol-btn sm blue', text: 'יצירת טיוטת חוב', onclick: function () { candidateDraft(c); } }), h('button', { class: 'icol-btn sm', text: 'לא רלוונטי', onclick: function () { simpleModal('סימון כלא רלוונטי', '', [field('note', 'סיבה', 'textarea', { required: true })], function (fs) { return api.post('/candidates/' + c.id + '/dismiss', { note: val(fs[0]) }); }); } })) : null; }]
			], rows));
			if (!rows.length) { add(page, h('p', { class: 'muted', text: res.source === 'none' ? 'החיבור לדשבורד ההכנסות לא נמצא. יש לוודא שתוסף הדשבורד פעיל, או להריץ את כלי האבחון revenue_probe.' : 'אין מועמדים חדשים.' })); }
		}).catch(fail);
	}
	function candidateDraft(c) {
		var s = c.snapshot || {};
		var fs = [field('amount', 'סכום לחיוב לפי ההסכם', 'text', { required: true, inputmode: 'decimal', value: s.suggested_amount_minor ? String(s.suggested_amount_minor / 100) : '', help: s.suggested_amount_minor ? 'הוצע לפי מחיר מוצר הקנס בפייפדרייב. יש לוודא מול ההסכם של התלמיד.' : 'לא נקבע מחיר אחיד: הסכום מוזן להסכם המסוים.' }), field('due_at', 'מועד פירעון', 'date', { value: new Date().toISOString().slice(0, 10) }), field('approval_basis', 'בסיס החיוב', 'textarea', { required: true, value: c.pipedrive_deal_id ? 'לא נפתח חשבון עד ' + c.deadline + ' לפי דשבורד ההכנסות (דיל ' + c.pipedrive_deal_id + ')' : '' }), field('document_ref', 'קישור להסכם', 'text'), field('clarification_first', 'להתחיל בבירור לפני דרישת תשלום', 'checkbox', { value: true })];
		modal({ title: 'טיוטת חוב, ' + (s.name || ''), lead: 'הטיוטה נשמרת בלי שליחה. אחראי גבייה מאשר ומפעיל מתוך התיק.', body: h('div', { class: 'icol-form' }, fs.map(function (f2) { return f2.el; })),
			actions: [{ label: 'יצירת טיוטה', kind: 'primary', onClick: function () { return api.post('/candidates/' + c.id + '/draft', { amount: val(fs[0]), due_at: val(fs[1]), approval_basis: val(fs[2]), document_ref: val(fs[3]), clarification_first: val(fs[4]) }).then(function (r) { location.hash = '#/cases/' + r.case_id; }); } }] });
	}

	/* ---------- security: phones and open sessions ---------- */
	var DEV = { active: ['פעיל', 'ok'], pending: ['ממתין לאישור', 'warn'], revoked: ['נותק', ''] };
	function viewSecurity() {
		Promise.all([api.get('/gate/devices'), api.get('/gate/sessions'), api.get('/gate/state')]).then(function (res) {
			var devices = res[0], sessions = res[1], st = res[2];
			clear(page);
			add(page, pageHead('אבטחה וטלפונים', 'המערכת נפתחת רק בסריקה מטלפון מחובר. ' + (st.is_owner ? 'טלפונים חדשים של אחרים נפתחים רק אחרי אישור שלך.' : 'טלפון חדש ממתין לאישור של ' + st.owner_name + '.'),
				[h('button', { class: 'icol-btn primary', text: 'חיבור טלפון נוסף', onclick: pairModal })]));
			if (st.off) { add(page, h('div', { class: 'icol-banner stop', text: 'ICOL_GATE_OFF מוגדר ב-wp-config.php: כרגע אין צורך בסריקה. אחרי שחזור הגישה יש למחוק את השורה.' })); }
			add(page, h('h2', { class: 'icol-section-title', text: 'טלפונים' }));
			add(page, table([
				['label', 'טלפון', function (d) { return h('span', {}, d.label, h('span', { class: 'sub', text: d.synced ? 'מפתח מסונכרן לחשבון הענן של הטלפון' : 'מפתח שמור במכשיר בלבד' })); }],
				['user', 'משתמש', 'user'],
				['status', 'מצב', function (d) { var x = DEV[d.status] || [d.status, '']; return chip(x[0], x[1]); }],
				['created', 'חובר', 'created'],
				['last', 'שימוש אחרון', 'last_used'],
				['act', '', function (d) {
					return h('span', { class: 'icol-actions' },
						d.can_approve ? h('button', { class: 'icol-btn sm blue', text: 'אישור', onclick: function () { api.post('/gate/devices/' + d.id + '/approve').then(function () { toast('הטלפון אושר', 'ok'); viewSecurity(); }).catch(fail); } }) : null,
						d.can_revoke ? h('button', { class: 'icol-btn sm danger', text: 'ניתוק', onclick: function () { modal({ title: 'ניתוק הטלפון "' + d.label + '"', lead: 'הטלפון לא יוכל לפתוח את המערכת, וכל החיבורים שנפתחו בו נסגרים מיד.' + (d.is_mine && st.active_devices <= 1 ? ' זה הטלפון היחיד שלך: אחרי הניתוק יהיה צורך לחבר טלפון חדש.' : ''), actions: [{ label: 'ניתוק', kind: 'danger', onClick: function () { return api.post('/gate/devices/' + d.id + '/revoke').then(function () { toast('הטלפון נותק', 'ok'); viewSecurity(); }); } }] }); } }) : null);
				}]
			], devices));
			add(page, h('h2', { class: 'icol-section-title', text: 'חיבורים פתוחים' }));
			add(page, table([
				['user', 'משתמש', function (x) { return h('span', {}, x.user, x.current ? h('span', { class: 'sub', text: 'המחשב הזה' }) : null); }],
				['device', 'נפתח בטלפון', 'device'],
				['browser', 'מחשב', function (x) { return h('span', {}, x.browser, h('span', { class: 'sub', text: x.ip, dir: 'ltr' })); }],
				['started', 'נפתח', 'started'],
				['seen', 'פעילות אחרונה', 'last_seen'],
				['act', '', function (x) { return h('button', { class: 'icol-btn sm', text: x.current ? 'נעילה' : 'סגירה', onclick: function () { api.post('/gate/sessions/' + x.id + '/revoke').then(function () { if (x.current) { location.reload(); } else { viewSecurity(); } }).catch(fail); } }); }]
			], sessions));
			if (st.is_owner) {
				var idle = field('idle_minutes', 'נעילה אחרי חוסר פעילות (דקות)', 'number', { value: st.idle_minutes, help: '5 עד 240' });
				var hours = field('session_hours', 'משך חיבור מרבי (שעות)', 'number', { value: st.session_hours, help: '1 עד 24. אחרי הזמן הזה סורקים שוב.' });
				add(page, h('h2', { class: 'icol-section-title', text: 'הגדרות' }));
				add(page, h('div', { class: 'icol-card' }, h('div', { class: 'icol-fields' }, idle.el, hours.el),
					h('div', { class: 'icol-actions', style: 'margin-top:12px' }, h('button', { class: 'icol-btn primary', text: 'שמירה', onclick: function () { api.post('/gate/settings', { idle_minutes: Number(val(idle)), session_hours: Number(val(hours)) }).then(function () { toast('נשמר', 'ok'); }).catch(function (e) { showFieldErrors(page, e); fail(e); }); } }))));
			}
		}).catch(fail);
	}
	function pairModal() {
		var slot = h('div', {});
		var ctl = null;
		var close = modal({ title: 'חיבור טלפון נוסף', lead: 'הטלפון יפתח את המערכת עם זיהוי פנים או טביעת אצבע. ' + (C.gate && C.gate.is_owner ? 'טלפון שלך מתחבר מיד.' : 'הטלפון ימתין לאישור של בעל המערכת.'), body: slot, wide: true, actions: [], cancelLabel: 'סגירה' });
		ctl = window.ICOLGate.pair(slot, { needsPassword: true }, function (r) {
			close();
			toast(r.status === 'active' ? 'הטלפון חובר' : 'הטלפון חובר וממתין לאישור', 'ok');
			viewSecurity();
		});
		var obs = new MutationObserver(function () { if (!document.body.contains(slot)) { if (ctl) { ctl.stop(); } obs.disconnect(); } });
		obs.observe(document.body, { childList: true });
	}

	/* ---------- tasks ---------- */
	function viewTasks() {
		api.get('/tasks', { owner: 'me' }).then(function (rows) {
			clear(page);
			add(page, pageHead('המשימות שלי', 'משימות שהוקצו לי ומשימות בתור הכללי.'));
			add(page, table([
				['type', 'סוג', function (t) { return h('span', {}, t.type_label, t.repeat_count > 0 ? h('span', { class: 'sub', text: 'התראה חוזרת ×' + t.repeat_count }) : null); }],
				['customer', 'לקוח', function (t) { return t.case_id ? h('a', { href: '#/cases/' + t.case_id, text: t.full_name || ('תיק #' + t.case_id) }) : (t.full_name || null); }],
				['reason', 'סיבה', 'reason'],
				['due', 'יעד', function (t) { return h('span', {}, t.due_local, t.overdue ? chip('באיחור', 'bad') : null); }],
				['assignee', 'אחראי', function (t) { return t.assignee || h('span', { class: 'muted', text: 'תור כללי' }); }],
				['act', '', function (t) { return t.type === 'card_update' ? h('a', { href: '#/cards', text: 'למסך הכרטיסים' }) : h('button', { class: 'icol-btn sm', text: 'בוצע', onclick: function () { completeTask(t.id); } }); }]
			], rows));
		}).catch(fail);
	}

	/* ---------- settings ---------- */
	function viewSettings(tab) {
		var tabs = [['health', 'בריאות המערכת'], ['caps', 'יכולות ומצב'], ['connections', 'חיבורים'], ['policy', 'מדיניות פנייה'], ['templates', 'תבניות'], ['team', 'צוות והרשאות'], ['codes', 'קודי תשובה']];
		clear(page);
		add(page, pageHead('הגדרות ובריאות', 'כל שינוי נרשם ביומן. יכולות חיצוניות כבויות עד שאומתו.'));
		var bar = h('div', { class: 'icol-tabs' });
		tabs.forEach(function (t) { add(bar, h('button', { class: tab === t[0] ? 'on' : '', text: t[1], onclick: function () { location.hash = '#/settings/' + t[0]; } })); });
		var body = h('div');
		add(page, [bar, body]);
		loading(body);
		if (tab === 'health') { return settingsHealth(body); }
		api.get('/settings').then(function (s) {
			clear(body);
			({ caps: settingsCaps, connections: settingsConnections, policy: settingsPolicy, templates: settingsTemplates, team: settingsTeam, codes: settingsCodes })[tab](body, s);
		}).catch(fail);
	}
	function saveSettings(values, secrets) { return api.post('/settings', { settings: values, secrets: secrets || {} }).then(function () { toast('ההגדרות נשמרו', 'ok'); route(); }); }
	function settingsHealth(body) {
		Promise.all([api.get('/health'), can('icol_admin') ? api.get('/settings') : Promise.resolve(null)]).then(function (res) {
			var hl = res[0], s = res[1];
			clear(body);
			var jobs = Object.keys(hl.jobs || {}).map(function (k) { var j = hl.jobs[k]; return { job: k, attempt: j.attempt, success: j.success, error: j.error ? j.error + ' · ' + j.last_error : '' }; });
			var q = function (arr) { return (arr || []).map(function (r) { return r.state + ': ' + r.n; }).join(' · ') || '—'; };
			add(body, h('div', { class: 'icol-split' },
				h('div', { class: 'icol-card' }, h('h3', { text: 'עבודות מתוזמנות, ניסיון מול הצלחה' }), table([['job', 'עבודה'], ['attempt', 'ניסיון אחרון'], ['success', 'הצלחה אחרונה'], ['error', 'שגיאה אחרונה']], jobs),
					hl.reconcile_stale ? h('div', { class: 'icol-banner warn', style: 'margin-top:10px', text: 'ההתאמה מול טרנזילה לא עדכנית, תזכורות לחיובים חוזרים מושהות עד חידוש.' }) : null,
					h('div', { class: 'icol-actions', style: 'margin-top:10px' }, can('icol_admin') ? h('button', { class: 'icol-btn sm', text: 'הרצת מחזור עבודה עכשיו', onclick: function () { api.post('/tools/tick').then(function (r) { toast('בוצע: ' + JSON.stringify(r).slice(0, 160), 'ok'); route(); }).catch(fail); } }) : null, C.diag ? h('a', { class: 'icol-btn sm', href: C.diag.tools, target: '_blank', rel: 'noopener', text: 'כלי אבחון' }) : null)),
				h('div', { class: 'icol-card' }, h('h3', { text: 'חיבורים ותורים' }), kv([
					['טרנזילה', hl.integrations.tranzila ? chip('מוגדר', 'ok') : chip('לא מוגדר', 'warn')], ['WATI', hl.integrations.wati ? chip('מוגדר', 'ok') : chip('לא מוגדר', 'warn')], ['פייפדרייב', hl.integrations.pipedrive ? chip('מוגדר', 'ok') : chip('לא מוגדר')], ['AI', hl.integrations.ai ? chip('זמין', 'ok') : chip('לא זמין')], ['הצפנה', hl.integrations.encryption ? chip('מפתח קיים', 'ok') : chip('חסר ICOL_ENCRYPTION_KEY', 'bad')],
					['אירוע טרנזילה אחרון', hl.last_event.tranzila], ['אירוע WATI אחרון', hl.last_event.wati], ['תיבת אירועים', q(hl.queues.inbox)], ['פעולות יוצאות', q(hl.queues.outbox)], ['פעולות מתוזמנות', q(hl.queues.actions)],
					['WP-Cron כבוי', hl.wp_cron_disabled ? 'כן (cron שרת)' : 'לא, מומלץ cron שרת'], ['גרסה', hl.version]]),
					Object.keys(hl.suspended || {}).length ? h('div', { class: 'icol-banner stop', style: 'margin-top:10px' }, h('div', {}, 'חיבורים מושעים: ' + Object.keys(hl.suspended).join(', ')), Object.keys(hl.suspended).map(function (n) { return h('button', { class: 'icol-btn sm', text: 'חידוש ' + n, onclick: function () { api.post('/integrations/' + n + '/unsuspend').then(route).catch(fail); } }); })) : null)
			));
			var urls = h('div', { class: 'icol-card', style: 'margin-top:12px' }, h('h3', { text: 'כתובות להגדרה אצל הספקים' }),
				h('div', { class: 'icol-section-title', text: 'Cron שרת (כל דקה, Cloudways ← Cron Job Management)' }), h('div', { class: 'icol-code', text: 'curl -s "' + hl.cron_url + '" > /dev/null' }));
			if (s && s.webhooks) {
				add(urls, [h('div', { class: 'icol-section-title', style: 'margin-top:10px', text: 'My Billing → Transaction Notification Endpoint' }), h('div', { class: 'icol-code', text: s.webhooks.tranzila_sto }),
					h('div', { class: 'icol-section-title', style: 'margin-top:10px', text: 'Notify של בקשות תשלום' }), h('div', { class: 'icol-code', text: s.webhooks.tranzila_pr }),
					h('div', { class: 'icol-section-title', style: 'margin-top:10px', text: 'WATI Webhook (message, status events)' }), h('div', { class: 'icol-code', text: s.webhooks.wati })]);
			}
			add(body, urls);
			add(body, h('div', { class: 'icol-card', style: 'margin-top:12px' }, h('h3', { text: 'מבנה נתונים' }), h('div', { class: 'icol-mode' }, Object.keys(hl.schema).map(function (t) { return chip(t + ': ' + hl.schema[t], hl.schema[t] === 'ok' ? '' : 'bad'); }))));
		}).catch(fail);
	}
	function settingsCaps(body, s) {
		var st = s.settings;
		var caps = [
			['display_only', 'מצב תצוגה בלבד', 'המערכת מחשבת ומתעדת מה הייתה שולחת, בלי לשלוח. להשאיר פעיל עד סיום שלב המדידה.'],
			['cap_customer_sends', 'משלוחים ללקוחות ב-WATI', 'דורש תבניות מאושרות, מדיניות מאושרת וחיבור WATI.'],
			['cap_payment_links', 'קישורי תשלום', 'רק אחרי בדיקת pr/create בסביבת בדיקה של טרנזילה.'],
			['cap_separate_payment_recurring', 'תשלום נפרד לפריט של הוראת קבע', 'מסוכן: עלול לגרום לחיוב כפול. להפעיל רק אחרי שטרנזילה אישרה איך מסמנים פריט כמוסדר ומונעים ניסיון חוזר.'],
			['tranzila_inbound_verified', 'אמון בהודעות טרנזילה ללא קריאת אימות', 'כבוי = כל הודעה נבדקת מול דוח העסקאות. להפעיל רק אם טרנזילה סיפקה מנגנון אימות נכנס.'],
			['tranzila_card_fix_email', 'מייל תיקון כרטיס של טרנזילה פעיל במסוף', 'אם פעיל, הודעת כשל כרטיס מפנה ללקוח למייל של טרנזילה במקום לקישור.'],
			['cap_ai_suggestions', 'הצעות AI לנציג', 'סיווג ותקציר בלבד. לא שולח ולא משנה כסף.'],
			['cap_pipedrive_tasks', 'משימות בפייפדרייב', 'עותק של משימות פנימיות.'],
			['cap_email_alerts', 'התראות במייל', 'לנמען שמוגדר בחיבורים.']
		];
		var fs = caps.map(function (c) { return { key: c[0], f: field(c[0], c[1], 'checkbox', { value: Number(st[c[0]]) === 1, help: c[2], wide: true }) }; });
		add(body, h('div', { class: 'icol-card' }, h('div', { class: 'icol-form' }, fs.map(function (x) { return x.f.el; })), h('div', { class: 'icol-actions', style: 'margin-top:12px' }, h('button', { class: 'icol-btn primary', text: 'שמירה', onclick: function () { var v = {}; fs.forEach(function (x) { v[x.key] = x.f.input.checked ? 1 : 0; }); saveSettings(v).catch(fail); } }), h('span', { class: 'muted', text: 'מתג העצירה הכללי נמצא בסרגל העליון.' }))));
	}
	function settingsConnections(body, s) {
		var st = s.settings;
		var keys = [
			['tranzila_terminals', 'מסופי טרנזילה מוכרים (פסיקים)'], ['tranzila_my_billing_terminal', 'מסוף My Billing'], ['tranzila_pr_terminal', 'מסוף לבקשות תשלום'],
			['tranzila_cycle_strategy', 'זיהוי מחזור', [['manual', 'ידני (בטוח)'], ['schedule', 'לפי לוח החיובים של ההוראה'], ['field', 'לפי שדה שאושר']]], ['tranzila_cycle_field', 'שדה מחזור (אם אושר)'],
			['tranzila_charge_tranmodes', 'קידומות tranmode של חיוב'], ['tranzila_pr_match_field', 'שדה pr_id ב-Notify'], ['tranzila_hmac_order', 'סדר HMAC', [['secret_time_nonce', 'secret+time+nonce'], ['time_nonce_secret', 'time+nonce+secret']]], ['tranzila_report_amount_unit', 'יחידת סכום בדוחות', [['major', 'שקלים'], ['minor', 'אגורות']]],
			['wati_api_base', 'כתובת API של WATI'], ['wati_channel_number', 'channel_number ב-WATI'], ['wati_conversation_attr', 'שם מאפיין בעלות השיחה'],
			['pipedrive_api_base', 'כתובת פייפדרייב'], ['alert_email', 'מייל להתראות'], ['support_whatsapp', 'מספר וואטסאפ לדף התשלום'], ['fallback_owner_id', 'מזהה בעלים חלופי'],
			['ai_model', 'מודל AI'], ['ai_effort', 'עומק חשיבה', [['low', 'low'], ['medium', 'medium'], ['high', 'high']]],
			['revenue_meta_deadline', 'דשבורד הכנסות: מפתח מועד אחרון'], ['revenue_meta_opened', 'דשבורד הכנסות: מפתח חשבון נפתח'], ['revenue_meta_joined', 'דשבורד הכנסות: מפתח הצטרפות'], ['revenue_meta_extension', 'דשבורד הכנסות: מפתח הארכה'], ['revenue_meta_phone', 'מפתח טלפון במשתמש'], ['revenue_candidate_horizon_days', 'ימים קדימה למועמדים'], ['revenue_max_staleness_hours', 'דשבורד הכנסות: שעות מרביות מהסנכרון האחרון']
		];
		var fs = keys.map(function (k) { return { key: k[0], f: Array.isArray(k[2]) ? field(k[0], k[1], 'select', { value: st[k[0]], options: k[2] }) : field(k[0], k[1], 'text', { value: st[k[0]] === undefined ? '' : st[k[0]] }) }; });
		var secrets = [['tranzila_app_key', 'Tranzila app key'], ['tranzila_secret', 'Tranzila secret'], ['wati_token', 'WATI token'], ['pipedrive_token', 'Pipedrive API token'], ['anthropic_api_key', 'Anthropic API key']];
		var sf = secrets.map(function (k) { return { key: k[0], f: field(k[0], k[1] + (s.secrets[k[0]] ? ' · מוגדר' : ''), 'password', { placeholder: s.secrets[k[0]] ? 'להשאיר ריק כדי לא לשנות' : '' }) }; });
		add(body, h('div', { class: 'icol-card' }, h('div', { class: 'icol-fields' }, fs.map(function (x) { return x.f.el; })),
			h('div', { class: 'icol-section-title', style: 'margin:16px 0 8px', text: 'מפתחות (נשמרים מוצפנים, לא מוצגים שוב)' }), h('div', { class: 'icol-fields' }, sf.map(function (x) { return x.f.el; })),
			h('div', { class: 'icol-actions', style: 'margin-top:12px' }, h('button', { class: 'icol-btn primary', text: 'שמירה', onclick: function () { var v = {}, sec = {}; fs.forEach(function (x) { v[x.key] = val(x.f); }); sf.forEach(function (x) { if (val(x.f)) { sec[x.key] = val(x.f); } }); saveSettings(v, sec).catch(fail); } }))));
	}
	function settingsPolicy(body, s) {
		var cur = s.policies[0];
		var approved = s.policies.filter(function (p) { return p.approved_at; })[0];
		var cfg = (cur && cur.config) || {};
		add(body, h('div', { class: 'icol-banner ' + (approved ? 'sim' : 'warn') }, h('div', {}, approved ? 'גרסה מאושרת: ' + approved.version + ' · בתוקף מ־' + (approved.effective_at || '') : 'אין גרסת מדיניות מאושרת, לא יישלחו הודעות.')));
		add(body, table([['version', 'גרסה'], ['note', 'הערה'], ['state', 'מצב', function (p) { return p.approved_at ? chip('מאושרת', 'ok') : chip('טיוטה', 'warn'); }], ['created_at', 'נוצרה'], ['act', '', function (p) { return !p.approved_at ? h('button', { class: 'icol-btn sm primary', text: 'אישור והפעלה', onclick: function () { modal({ title: 'אישור גרסת מדיניות ' + p.version, lead: 'מהרגע הזה תזכורות חדשות יתוכננו לפי הגרסה הזו. שינוי מקבל גרסה ותאריך תחולה.', actions: [{ label: 'אישור', kind: 'primary', onClick: function () { return api.post('/policy/' + p.version + '/approve').then(function () { toast('המדיניות אושרה', 'ok'); route(); }); } }] }); } }) : null; }]], s.policies));
		var days = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
		var dayBoxes = days.map(function (d2, i) { return field('d' + i, d2, 'checkbox', { value: (cfg.send_days || []).indexOf(i) >= 0 }); });
		var f2 = { ws: field('window_start', 'שעת התחלה', 'text', { value: cfg.window_start }), we: field('window_end', 'שעת סיום', 'text', { value: cfg.window_end }), erev: field('erev_cutoff', 'סיום בערב חג', 'text', { value: cfg.erev_cutoff }),
			c1: field('c1', 'תזכורת 2 אחרי (ימי עבודה)', 'number', { value: (cfg.cadence_business_days || [])[0] }), c2: field('c2', 'תזכורת 3 אחרי (ימי עבודה)', 'number', { value: (cfg.cadence_business_days || [])[1] }), max: field('max_reminders', 'מספר תזכורות לפני נציג', 'number', { value: cfg.max_reminders }),
			qw: field('quota_window_business_days', 'חלון מכסה (ימי עבודה)', 'number', { value: cfg.quota_window_business_days }), qm: field('quota_max', 'פניות מקסימום בחלון', 'number', { value: cfg.quota_max }), gap: field('min_gap_hours', 'מרווח מינימלי (שעות)', 'number', { value: cfg.min_gap_hours }), bp: field('broken_promise_followups', 'הודעות המשך אחרי הפרת הבטחה', 'number', { value: cfg.broken_promise_followups }), jit: field('first_contact_jitter_minutes', 'פיזור פנייה ראשונה (דקות)', 'number', { value: cfg.first_contact_jitter_minutes }) };
		var grace = Object.keys(s.failure_classes).map(function (k) { var v = (cfg.grace_business_days || {})[k]; return { key: k, f: field('g_' + k, s.failure_classes[k] + ', ימי המתנה לניסיון של My Billing', 'text', { value: v === null || v === undefined ? 'נציג' : v, help: 'מספר, או "נציג" = אין פנייה אוטומטית' }) }; });
		var toLines = function (o) { return Object.keys(o || {}).map(function (k) { return k + ' ' + o[k]; }).join('\n'); };
		var blocked = field('blocked_dates', 'ימי חסימה (שורה לכל יום: YYYY-MM-DD שם)', 'textarea', { value: toLines(cfg.blocked_dates), rows: 8, wide: true });
		var half = field('half_days', 'חצאי ימים, ערבי חג (עד שעת הסיום בערב חג)', 'textarea', { value: toLines(cfg.half_days), rows: 5, wide: true });
		var note = field('note', 'הערה לגרסה', 'text', { wide: true });
		var fromLines = function (t) { var o = {}; t.split('\n').forEach(function (l) { var m = l.trim().match(/^(\d{4}-\d{2}-\d{2})\s*(.*)$/); if (m) { o[m[1]] = m[2] || 'חסום'; } }); return o; };
		add(body, h('div', { class: 'icol-card', style: 'margin-top:12px' }, h('h3', { text: 'עריכת מדיניות (נשמרת כגרסה חדשה לאישור)' }),
			h('div', { class: 'icol-section-title', text: 'ימי פנייה' }), h('div', { class: 'icol-mode', style: 'margin:8px 0' }, dayBoxes.map(function (b) { return b.el; })),
			h('div', { class: 'icol-fields' }, Object.keys(f2).map(function (k) { return f2[k].el; })),
			h('div', { class: 'icol-section-title', style: 'margin:12px 0 8px', text: 'המתנה לפני פנייה ראשונה לפי סוג כשל' }), h('div', { class: 'icol-fields' }, grace.map(function (g) { return g.f.el; })),
			h('div', { class: 'icol-fields', style: 'margin-top:12px' }, blocked.el, half.el, note.el),
			h('div', { class: 'icol-actions', style: 'margin-top:12px' }, h('button', { class: 'icol-btn primary', text: 'שמירת טיוטה חדשה', onclick: function () {
				var g = {}; grace.forEach(function (x) { var v = val(x.f); g[x.key] = /^\d+$/.test(v) ? Number(v) : null; });
				var conf = Object.assign({}, cfg, { send_days: dayBoxes.map(function (b, i) { return b.input.checked ? i : -1; }).filter(function (i) { return i >= 0; }), window_start: val(f2.ws), window_end: val(f2.we), erev_cutoff: val(f2.erev), cadence_business_days: [Number(val(f2.c1)), Number(val(f2.c2))], max_reminders: Number(val(f2.max)), quota_window_business_days: Number(val(f2.qw)), quota_max: Number(val(f2.qm)), min_gap_hours: Number(val(f2.gap)), broken_promise_followups: Number(val(f2.bp)), first_contact_jitter_minutes: Number(val(f2.jit)), grace_business_days: g, blocked_dates: fromLines(val(blocked)), half_days: fromLines(val(half)) });
				Object.keys(conf).forEach(function (k) { if (k.charAt(0) === '_') { delete conf[k]; } });
				api.post('/policy/draft', { config: conf, note: val(note) }).then(function (r) { toast('נשמרה טיוטה ' + r.version + '. יש לאשר אותה כדי שתיכנס לתוקף.', 'ok'); route(); }).catch(fail);
			} }))));
	}
	function settingsTemplates(body, s) {
		add(body, h('p', { class: 'muted', text: 'עריכת נוסח יוצרת גרסה חדשה במצב טיוטה: נוסח ששונה אינו הנוסח שמטא אישרה. בהודעות ללקוח לא משתמשים במילים "חוב" או "גבייה" (מדיניות WhatsApp Business אוסרת שימוש לגביית חובות).' }));
		add(body, table([['key', 'תבנית'], ['channel', 'ערוץ', function (t) { return { whatsapp_template: 'תבנית WhatsApp', whatsapp_session: 'הודעת שירות (24ש׳)', internal: 'פנימי' }[t.channel] || t.channel; }], ['provider_name', 'שם ב-WATI'], ['version', 'גרסה'], ['approval_state', 'אישור', function (t) { return chip({ draft: 'טיוטה', submitted: 'הוגשה', approved: 'מאושרת', rejected: 'נדחתה' }[t.approval_state] || t.approval_state, t.approval_state === 'approved' ? 'ok' : 'warn'); }], ['body', 'נוסח', function (t) { return h('span', { style: 'white-space:pre-wrap;display:block;max-width:520px', text: t.body }); }],
			['act', '', function (t) { return h('button', { class: 'icol-btn sm', text: 'עריכה', onclick: function () { editTemplate(t); } }); }]], s.templates));
	}
	function editTemplate(t) {
		var fs = [field('body', 'נוסח', 'textarea', { value: t.body, rows: 7, help: 'משתנים: ' + (t.params || []).map(function (p) { return '{{' + p + '}}'; }).join(' ') }), field('provider_name', 'שם התבנית ב-WATI', 'text', { value: t.provider_name }), field('approval_state', 'מצב אישור אצל מטא', 'select', { value: t.approval_state, options: [['draft', 'טיוטה'], ['submitted', 'הוגשה'], ['approved', 'מאושרת'], ['rejected', 'נדחתה']] })];
		modal({ title: 'תבנית ' + t.key, lead: (t.use_when ? 'שימוש: ' + t.use_when + '. ' : '') + (t.block_when ? 'חסימה: ' + t.block_when : ''), wide: true, body: h('div', { class: 'icol-form' }, fs.map(function (f2) { return f2.el; })),
			actions: [{ label: 'שמירה', kind: 'primary', onClick: function () { return api.post('/templates/' + t.key, { body: val(fs[0]), provider_name: val(fs[1]), approval_state: val(fs[2]) }).then(function (r) { toast('נשמר · גרסה ' + r.version + ' · ' + r.approval_state, 'ok'); route(); }); } }] });
	}
	function settingsTeam(body, s) {
		var search = h('input', { type: 'search', placeholder: 'חיפוש משתמש וורדפרס לפי שם או מייל' });
		var results = h('div', { class: 'icol-grid', style: 'margin-top:8px' });
		search.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') { return; }
			request('GET', C.users_url + '?context=edit&per_page=10&search=' + encodeURIComponent(search.value)).then(function (users) {
				clear(results);
				users.forEach(function (u) { add(results, h('div', { class: 'icol-filters' }, h('span', { text: u.name + ' · ' + (u.email || '') }), roleSelect(u.id, ''))); });
			}).catch(fail);
		});
		function roleSelect(uid, cur) {
			var sel = field('role', 'תפקיד', 'select', { value: cur, options: [['', 'ללא גישה']].concat(Object.keys(s.roles).map(function (k) { return [k, s.roles[k]]; })) }).input;
			sel.addEventListener('change', function () { api.post('/users/' + uid + '/role', { role: sel.value }).then(function () { toast('התפקיד עודכן', 'ok'); }).catch(fail); });
			return sel;
		}
		add(body, h('div', { class: 'icol-card' }, h('h3', { text: 'צוות' }), h('p', { class: 'muted', text: 'התפקידים במערכת הגבייה נשמרים בנפרד מתפקידי וורדפרס, כדי לא לשנות את הסיווג של המשתמש בתוספים אחרים. מנהלי אתר מקבלים הרשאות מנהל אוטומטית.' }),
			table([['name', 'שם'], ['email', 'מייל'], ['role', 'תפקיד', function (u) { return roleSelect(u.id, u.role); }], ['pd', 'מזהה בפייפדרייב', 'pipedrive_user_id']], s.users),
			h('div', { class: 'icol-section-title', style: 'margin:14px 0 8px', text: 'הוספת משתמש' }), search, results));
	}
	function settingsCodes(body, s) {
		var ta = field('tranzila_code_map', 'מיפוי קוד תשובה → סוג כשל (JSON)', 'textarea', { value: JSON.stringify(s.code_map, null, 2), rows: 14, wide: true, help: 'סוגים: ' + Object.keys(s.failure_classes).join(', ') + '. קוד שלא מופה = unknown, ולא נשלחת עליו פנייה אוטומטית. הטבלה ההתחלתית צריכה אימות מול התיעוד של טרנזילה ודגימות אמיתיות.' });
		add(body, h('div', { class: 'icol-card' }, ta.el, h('div', { class: 'icol-actions', style: 'margin-top:12px' }, h('button', { class: 'icol-btn primary', text: 'שמירה', onclick: function () { var parsed; try { parsed = JSON.parse(ta.input.value); } catch (e) { toast('JSON לא תקין', 'err'); return; } saveSettings({ tranzila_code_map: JSON.stringify(parsed) }).catch(fail); } }))));
	}

	route();
})();
