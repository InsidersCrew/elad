/* INSIDERS Collections — scan gate, computer side (lock screen + phone pairing).
   Loaded alone while the system is locked (no data is on the page), and next to the
   app when unlocked (pairing a spare phone, the lock button). No build step. */
(function () {
	'use strict';
	var G = window.ICOL_GATE || {};

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

	function req(method, path, body) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': G.nonce, 'Accept': 'application/json' } };
		if (method !== 'GET') { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body || {}); }
		var url = G.root + path;
		var first = url.indexOf('?');
		if (first >= 0) { url = url.slice(0, first + 1) + url.slice(first + 1).replace(/\?/g, '&'); }
		return fetch(url, opts).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) { var e = new Error((data && data.message) || ('HTTP ' + res.status)); e.data = data || {}; e.status = res.status; throw e; }
				return data;
			});
		});
	}

	/* The SVG is generated on our server from our own URL (no user input inside), so it is inserted as markup. */
	function qrBox(c) {
		var box = h('div', { class: 'icol-gate-qr', role: 'img', 'aria-label': 'קוד QR לסריקה בטלפון' });
		if (c.qr) { box.innerHTML = c.qr; } else { add(box, h('a', { href: c.url, text: c.url })); }
		return box;
	}
	function mmss(s) { s = Math.max(0, s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

	/* ---------- unlock: QR + number + countdown, polled until the phone approves ---------- */
	function unlockPanel(container) {
		var timers = [];
		var renewals = 0;
		function stop() { timers.forEach(clearInterval); timers = []; }
		function start() {
			stop();
			clear(container);
			add(container, h('div', { class: 'icol-gate-wait', text: 'מכין קוד…' }));
			req('POST', '/gate/unlock').then(function (c) {
				var left = c.expires_in;
				var count = h('span', { class: 'num', text: mmss(left) });
				clear(container);
				add(container, h('div', { class: 'icol-gate-row' },
					qrBox(c),
					h('div', { class: 'icol-gate-steps' },
						h('ol', {},
							h('li', { text: 'פותחים את המצלמה בטלפון וסורקים את הקוד.' }),
							h('li', {}, 'בוחרים בטלפון את המספר ', h('strong', { class: 'icol-gate-match', text: String(c.match) })),
							h('li', { text: 'מאשרים בזיהוי פנים, בטביעת אצבע או בקוד הנעילה של הטלפון.' })
						),
						h('div', { class: 'icol-gate-foot' }, 'הקוד מתחלף בעוד ', count)
					)
				));
				timers.push(setInterval(function () {
					left -= 1;
					count.textContent = mmss(left);
					if (left <= 0) {
						stop();
						renewals += 1;
						if (renewals < 5) { start(); return; }
						clear(container);
						add(container, h('div', { class: 'icol-gate-wait' }, h('p', { text: 'הקוד פג.' }), h('button', { class: 'icol-btn primary', text: 'קוד חדש', onclick: function () { renewals = 0; start(); } })));
					}
				}, 1000));
				timers.push(setInterval(function () {
					req('GET', '/gate/unlock/' + c.id).then(function (r) {
						if (r.status === 'approved') {
							stop();
							clear(container);
							add(container, h('div', { class: 'icol-gate-wait ok', text: 'אושר. פותח את המערכת…' }));
							location.reload();
						} else if (r.status === 'burned') {
							renewals = 0; start();
						}
					}).catch(function () { /* transient: the next poll tries again */ });
				}, 2000));
			}).catch(function (e) {
				clear(container);
				add(container, h('div', { class: 'icol-gate-err', text: e.message }));
			});
		}
		start();
		return { stop: stop };
	}

	/* ---------- pairing: password (when locked) -> QR -> code from the phone ---------- */
	function pairPanel(container, opts, onDone) {
		opts = opts || {};
		var poll = null;
		function stop() { if (poll) { clearInterval(poll); poll = null; } }
		function err(msg) { return h('div', { class: 'icol-gate-err', text: msg }); }

		function stepPassword() {
			clear(container);
			var pw = h('input', { type: 'password', autocomplete: 'current-password', id: 'icol-gate-pw' });
			var box = h('div', {});
			var go = function () {
				clear(box);
				req('POST', '/gate/pair', { password: pw.value }).then(stepQr).catch(function (e) { add(box, err(e.message)); });
			};
			add(container, h('div', { class: 'icol-form icol-gate-narrow' },
				h('p', { class: 'hint', text: 'לפני חיבור טלפון מזינים שוב את סיסמת וורדפרס.' }),
				h('div', { class: 'icol-field' }, h('label', { for: 'icol-gate-pw', text: 'סיסמת וורדפרס' }), pw),
				box,
				h('div', {}, h('button', { class: 'icol-btn primary', text: 'המשך', onclick: go }))
			));
			pw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
			pw.focus();
		}

		function stepQr(c) {
			clear(container);
			add(container, h('div', { class: 'icol-gate-row' },
				qrBox(c),
				h('div', { class: 'icol-gate-steps' },
					h('ol', {},
						h('li', { text: 'סורקים את הקוד במצלמה של הטלפון שאיתו תפתחו את המערכת.' }),
						h('li', { text: 'בטלפון נותנים שם למכשיר ומאשרים יצירת מפתח בזיהוי פנים או בטביעת אצבע.' }),
						h('li', { text: 'הטלפון יציג קוד בן שש ספרות. מקלידים אותו כאן.' })
					),
					h('div', { class: 'icol-gate-foot', text: 'הקוד בתוקף חמש דקות.' })
				)
			));
			stop();
			poll = setInterval(function () {
				req('GET', '/gate/pair/' + c.id).then(function (r) {
					if (r.status === 'registered') { stop(); stepCode(c); }
					else if (r.status === 'expired' || r.status === 'replaced' || r.status === 'burned') { stop(); clear(container); add(container, err('הקוד פג. יש להתחיל מחדש.'), h('button', { class: 'icol-btn', text: 'התחלה מחדש', onclick: begin })); }
				}).catch(function () {});
			}, 2000);
		}

		function stepCode(c) {
			clear(container);
			var code = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, class: 'icol-gate-code', id: 'icol-gate-code' });
			var box = h('div', {});
			var go = function () {
				clear(box);
				req('POST', '/gate/pair/' + c.id + '/confirm', { code: code.value }).then(function (r) {
					stop();
					if (onDone) { onDone(r); }
				}).catch(function (e) { add(box, err(e.message)); if (e.data && e.data.code === 'gate_pair_state') { add(box, h('button', { class: 'icol-btn', text: 'התחלה מחדש', onclick: begin })); } });
			};
			add(container, h('div', { class: 'icol-form icol-gate-narrow' },
				h('p', { class: 'hint', text: 'המפתח נוצר בטלפון. מקלידים כאן את הקוד שמוצג בו.' }),
				h('div', { class: 'icol-field' }, h('label', { for: 'icol-gate-code', text: 'הקוד מהטלפון' }), code),
				box,
				h('div', {}, h('button', { class: 'icol-btn primary', text: 'אישור', onclick: go }))
			));
			code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
			code.focus();
		}

		function begin() {
			if (opts.needsPassword && !opts.password) { stepPassword(); return; }
			clear(container);
			req('POST', '/gate/pair', { password: opts.password || '' }).then(stepQr).catch(function (e) { clear(container); add(container, err(e.message)); });
			opts.password = null; // used once, not kept in memory for a restart
		}
		begin();
		return { stop: stop };
	}

	/* ---------- the lock screen ---------- */
	function lockScreen(root, s) {
		var body = h('div', { class: 'icol-gate-body' });
		var card = h('div', { class: 'icol-card icol-gate-card' },
			h('div', { class: 'icol-logo' }, h('img', { src: G.logo, alt: 'INSIDERS' })),
			h('h1', { text: 'מערכת התשלומים נעולה' }),
			h('p', { class: 'hint', text: 'הנתונים נפתחים רק בסריקה מטלפון מחובר, עם זיהוי פנים או טביעת אצבע.' }),
			body
		);
		clear(root);
		add(root, h('div', { class: 'icol-gate' }, card));
		if (G.tools) { add(card, h('div', { class: 'icol-gate-foot' }, h('a', { href: G.tools, target: '_blank', rel: 'noopener', text: 'כלי אבחון והתקנה' }), ' (בלי נתוני לקוחות)')); }

		if (!s.secure) {
			add(body, h('div', { class: 'icol-gate-err', text: 'הסריקה דורשת חיבור מאובטח (https). יש לפתוח את האתר בכתובת https.' }));
			return;
		}
		if (!s.owner_configured) {
			if (s.can_claim) {
				var pw = h('input', { type: 'password', autocomplete: 'current-password', id: 'icol-gate-claim-pw' });
				var box = h('div', {});
				var go = function () {
					clear(box);
					var pass = pw.value;
					req('POST', '/gate/owner', { password: pass }).then(function () {
						clear(body);
						add(body, h('h2', { class: 'icol-gate-h2', text: 'חיבור הטלפון' }));
						var slot = h('div', {});
						add(body, slot);
						pairPanel(slot, { needsPassword: true, password: pass }, function (r) { if (r.status === 'active') { location.reload(); } });
					}).catch(function (e) { add(box, h('div', { class: 'icol-gate-err', text: e.message })); });
				};
				add(body, h('div', { class: 'icol-form icol-gate-narrow' },
					h('h2', { class: 'icol-gate-h2', text: 'הגדרה ראשונה' }),
					h('p', { class: 'hint', text: 'בעל המערכת הוא מי שמאשר טלפונים של אנשי צוות. בשלב הבא מחברים את הטלפון שלך: סורקים קוד במצלמה ומאשרים בזיהוי פנים או בטביעת אצבע.' }),
					h('div', { class: 'icol-field' }, h('label', { for: 'icol-gate-claim-pw', text: 'סיסמת וורדפרס' }), pw),
					box,
					h('div', {}, h('button', { class: 'icol-btn primary', text: 'להגדיר אותי כבעלים של המערכת', onclick: go }))
				));
				pw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
				pw.focus();
			} else {
				add(body, h('div', { class: 'icol-gate-note', text: 'המערכת עוד לא הוגדרה. בעל האתר צריך להיכנס למסך הזה ולקבוע את בעל המערכת.' }));
			}
			return;
		}
		if (s.active_devices > 0) {
			unlockPanel(body);
			add(card, h('details', { class: 'icol-gate-help' }, h('summary', { text: 'הטלפון לא איתך או אבד?' }),
				h('p', { text: s.is_owner ? 'אם יש טלפון גיבוי מחובר, אפשר לסרוק איתו ולנתק את הטלפון שאבד במסך האבטחה. אם אין, צריך גישה לקבצי האתר: ההוראות ב-README, בסעיף "טלפון אבד".' : 'צריך לפנות ל' + s.owner_name + '. אחרי שינותק הטלפון הישן, אפשר לחבר טלפון חדש מהמסך הזה.' })));
			return;
		}
		if (s.pending_devices > 0) {
			add(body, h('div', { class: 'icol-gate-note' },
				h('p', { text: 'הטלפון שלך חובר וממתין לאישור של ' + s.owner_name + '. אחרי האישור אפשר לסרוק ולהיכנס.' }),
				h('button', { class: 'icol-btn', text: 'בדיקה שוב', onclick: function () { location.reload(); } })));
			return;
		}
		if (s.can_pair) {
			add(body, h('h2', { class: 'icol-gate-h2', text: 'חיבור הטלפון' }));
			add(body, h('p', { class: 'hint', text: s.is_owner ? 'זה הטלפון שיפתח את המערכת ויאשר טלפונים של אחרים. כדאי לחבר אחר כך גם טלפון גיבוי, מתוך מסך האבטחה.' : 'אחרי החיבור הטלפון ימתין לאישור של ' + s.owner_name + '.' }));
			var slot = h('div', {});
			add(body, slot);
			pairPanel(slot, { needsPassword: true }, function (r) {
				if (r.status === 'active') { location.reload(); return; }
				clear(body);
				add(body, h('div', { class: 'icol-gate-note', text: 'הטלפון חובר וממתין לאישור של ' + s.owner_name + '.' }));
			});
		}
	}

	window.ICOLGate = {
		pair: pairPanel,
		lock: function () { return req('POST', '/gate/lock').then(function () { location.reload(); }); }
	};

	var root = document.getElementById('icol-root');
	if (root && G.locked) {
		req('GET', '/gate/state').then(function (s) { lockScreen(root, s); }).catch(function (e) {
			clear(root); add(root, h('div', { class: 'icol-gate' }, h('div', { class: 'icol-card icol-gate-card' }, h('div', { class: 'icol-gate-err', text: e.message }))));
		});
	}
})();
