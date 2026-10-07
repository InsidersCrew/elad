/* INSIDERS Collections — scan gate, phone side. Runs in the phone's browser after the
   camera opened the QR link. Talks only to this site's REST API (CSP connect-src 'self'). */
(function () {
	'use strict';
	var C = window.ICOL_GATE_PHONE || {};
	var card = document.getElementById('icolg');
	if (!card) { return; }

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
		for (var i = 2; i < arguments.length; i++) {
			var kid = arguments[i];
			if (kid === null || kid === undefined || kid === false) { continue; }
			(Array.isArray(kid) ? kid : [kid]).forEach(function (k2) { el.appendChild(k2 instanceof Node ? k2 : document.createTextNode(String(k2))); });
		}
		return el;
	}
	function screen() {
		while (card.firstChild) { card.removeChild(card.firstChild); }
		card.appendChild(h('div', { class: 'icolg-logo' }, h('img', { src: C.logo, alt: 'INSIDERS' })));
		for (var i = 0; i < arguments.length; i++) { if (arguments[i]) { card.appendChild(arguments[i]); } }
	}
	function done(icon, kind, title, text) {
		screen(h('div', { class: 'icolg-state ' + kind, text: icon }), h('h1', { text: title }), text ? h('p', { text: text }) : null);
	}

	function b64u(buf) {
		var b = new Uint8Array(buf), s = '';
		for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function unb64u(s) {
		s = s.replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) { s += '='; }
		var bin = atob(s), out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
		return out.buffer;
	}
	function post(path, body) {
		return fetch(C.root + '/gate/phone/' + C.id + '/' + path, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body || {}) })
			.then(function (res) {
				return res.json().catch(function () { return {}; }).then(function (d) {
					if (!res.ok) { var e = new Error((d && d.message) || 'שגיאה'); e.code = d && d.code; throw e; }
					return d;
				});
			});
	}
	function webauthnError(e) {
		if (e && e.name === 'NotAllowedError') { return 'האימות בוטל או שפג הזמן. אפשר לנסות שוב.'; }
		if (e && e.name === 'InvalidStateError') { return 'הטלפון הזה כבר מחובר.'; }
		if (e && e.name === 'SecurityError') { return 'הדפדפן חסם את האימות בכתובת הזו.'; }
		return (e && e.message) || 'האימות לא עבר.';
	}

	function meta(o) {
		return h('div', { class: 'icolg-meta' },
			h('div', {}, 'מחשב: ', h('strong', { text: o.desktop.browser })),
			h('div', {}, 'כתובת: ', h('strong', { text: o.desktop.ip, dir: 'ltr' })),
			h('div', {}, 'שעה: ', h('strong', { text: o.desktop.at })));
	}

	function unlock(o) {
		var err = h('div', { class: 'icolg-err', role: 'alert' });
		var busy = false;
		var pick = function (n) {
			if (busy) { return; }
			busy = true;
			err.textContent = '';
			var pk = o.publicKey;
			navigator.credentials.get({ publicKey: {
				challenge: unb64u(pk.challenge),
				rpId: pk.rpId,
				allowCredentials: pk.allowCredentials.map(function (c) { return { type: 'public-key', id: unb64u(c.id) }; }),
				userVerification: 'required',
				timeout: pk.timeout
			} }).then(function (cred) {
				return post('approve', { number: n, credential: {
					id: cred.id, rawId: b64u(cred.rawId), type: cred.type,
					response: {
						clientDataJSON: b64u(cred.response.clientDataJSON),
						authenticatorData: b64u(cred.response.authenticatorData),
						signature: b64u(cred.response.signature),
						userHandle: cred.response.userHandle ? b64u(cred.response.userHandle) : null
					}
				} });
			}).then(function () {
				done('✓', 'ok', 'אושר', 'אפשר לחזור למחשב. המערכת נפתחת שם.');
			}).catch(function (e) {
				busy = false;
				err.textContent = e.code ? e.message : webauthnError(e);
				if (e.code === 'gate_wrong_number' && /יותר מדי/.test(e.message)) { done('!', 'bad', 'הקוד נחסם', 'יש לרענן את המסך במחשב ולסרוק שוב.'); }
			});
		};
		screen(
			h('h1', { text: 'כניסה למערכת התשלומים' }),
			h('p', { text: 'בקשה לפתוח את המערכת בשם ' + o.who + '. אם הבקשה לא שלך, אין לאשר.' }),
			meta(o),
			h('p', { text: 'בוחרים את המספר שמופיע עכשיו במסך המחשב:' }),
			h('div', { class: 'icolg-nums' }, o.numbers.map(function (n) { return h('button', { class: 'icolg-num', type: 'button', text: String(n), onclick: function () { pick(n); } }); })),
			err,
			h('div', { class: 'icolg-foot', text: 'אחרי הבחירה הטלפון יבקש זיהוי פנים, טביעת אצבע או קוד נעילה.' })
		);
	}

	function pair(o) {
		var ua = navigator.userAgent || '';
		var guess = /iPhone/.test(ua) ? 'אייפון' : (/Android/.test(ua) ? 'אנדרואיד' : 'טלפון');
		var label = h('input', { type: 'text', id: 'icolg-label', maxlength: 60, value: guess + ' של ' + o.who });
		var err = h('div', { class: 'icolg-err', role: 'alert' });
		var btn = h('button', { class: 'icolg-btn', type: 'button', text: 'יצירת מפתח בטלפון' });
		btn.addEventListener('click', function () {
			err.textContent = '';
			btn.disabled = true;
			var pk = o.publicKey;
			navigator.credentials.create({ publicKey: {
				challenge: unb64u(pk.challenge),
				rp: pk.rp,
				user: { id: unb64u(pk.user.id), name: pk.user.name, displayName: pk.user.displayName },
				pubKeyCredParams: pk.pubKeyCredParams,
				authenticatorSelection: pk.authenticatorSelection,
				attestation: 'none',
				excludeCredentials: pk.excludeCredentials.map(function (c) { return { type: 'public-key', id: unb64u(c.id) }; }),
				timeout: pk.timeout
			} }).then(function (cred) {
				return post('register', { label: label.value, credential: {
					id: cred.id, rawId: b64u(cred.rawId), type: cred.type,
					response: { clientDataJSON: b64u(cred.response.clientDataJSON), attestationObject: b64u(cred.response.attestationObject) }
				} });
			}).then(function (r) {
				var code = String(r.code);
				screen(
					h('div', { class: 'icolg-state ok', text: '✓' }),
					h('h1', { text: 'המפתח נוצר' }),
					h('p', { text: 'מקלידים במחשב את הקוד:' }),
					h('div', { class: 'icolg-code', text: code.slice(0, 3) + ' ' + code.slice(3) }),
					h('div', { class: 'icolg-foot', text: 'מעכשיו הטלפון הזה פותח את מערכת התשלומים, תמיד עם זיהוי פנים או טביעת אצבע.' })
				);
			}).catch(function (e) {
				btn.disabled = false;
				err.textContent = e.code ? e.message : webauthnError(e);
			});
		});
		screen(
			h('h1', { text: 'חיבור הטלפון למערכת התשלומים' }),
			h('p', { text: 'חיבור הטלפון הזה עבור ' + o.who + '. הטלפון ייצור מפתח שנשמר רק בו ובחשבון הענן שלו, והמפתח לא נשלח לאתר.' }),
			meta(o),
			h('label', { for: 'icolg-label', text: 'שם למכשיר' }), label,
			btn, err
		);
	}

	if (!C.id) { done('!', 'bad', 'הקישור לא תקין', 'יש לסרוק שוב את הקוד מהמחשב.'); return; }
	if (!C.secure) { done('!', 'bad', 'נדרש חיבור מאובטח', 'הכתובת צריכה להתחיל ב-https.'); return; }
	if (!window.PublicKeyCredential || !navigator.credentials) { done('!', 'bad', 'הדפדפן לא תומך', 'יש לפתוח את הקישור בספארי או בכרום, בגרסה עדכנית.'); return; }
	post('options').then(function (o) {
		if (o.kind === 'unlock') { unlock(o); } else { pair(o); }
	}).catch(function (e) {
		done('!', 'bad', 'הקוד לא פעיל', e.message);
	});
})();
