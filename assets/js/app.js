/* LogiERP — application de l'apprenant (aucune dépendance, aucun innerHTML avec des données). */
(function () {
	'use strict';

	var C = window.LOGIERP;
	var root = document.getElementById('logierp-root');
	if (!root || !C) { return; }

	/* ── Pleine largeur sans barre de défilement horizontale ── */
	function setVw() { document.documentElement.style.setProperty('--lw-vw', document.documentElement.clientWidth + 'px'); }
	setVw();
	window.addEventListener('resize', setVw);

	/* ── Mode smartphone : toute l'application adaptée aux petits écrans tactiles ── */
	function phoneLayout() { try { return window.matchMedia('(max-width: 760px)').matches || (window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(max-width: 940px)').matches); } catch (e) { return false; } }
	function applyPhone() { root.classList.toggle('lw-phone', phoneLayout()); }
	applyPhone();
	window.addEventListener('resize', applyPhone);

	/* ── Fabrique de nœuds ── */
	function h(tag, attrs) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.className = v; }
			else if (k === 'text') { el.textContent = v; }
			else if (k === 'style') { Object.keys(v).forEach(function (s) { el.style[s] = v[s]; }); }
			else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), v); }
			else if (k === 'value') { el.value = v; }
			else if (k === 'checked') { el.checked = !!v; }
			else if (k === 'dataset') { Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; }); }
			else { el.setAttribute(k, v === true ? '' : String(v)); }
		});
		for (var i = 2; i < arguments.length; i++) { append(el, arguments[i]); }
		return el;
	}
	function append(el, c) {
		if (arguments.length > 2) { for (var i = 1; i < arguments.length; i++) { append(el, arguments[i]); } return; }
		if (c === null || c === undefined || c === false) { return; }
		if (Array.isArray(c)) { c.forEach(function (x) { append(el, x); }); return; }
		el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
	var SVGNS = 'http://www.w3.org/2000/svg';
	function s(tag, attrs) {
		var el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { el.textContent = attrs[k]; }
			else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), attrs[k]); }
			else { el.setAttribute(k, attrs[k]); }
		});
		for (var i = 2; i < arguments.length; i++) { if (arguments[i]) { el.appendChild(arguments[i]); } }
		return el;
	}

	/* ── Formats suisses ── */
	function qty(n) {
		if (n === null || n === undefined || n === '') { return ''; }
		var v = Number(n);
		var parts = (Math.round(v * 1000) / 1000).toString().split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '’');
		return parts.join('.');
	}
	function chf(n) { return Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '’'); }
	function date(d) { if (!d) { return ''; } var p = String(d).slice(0, 10).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d; }
	function addDays(d, n) { var x = new Date(d + 'T12:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); }
	var STATUS = { LIBRE: 'Libre', CONTROLE_QUALITE: 'Contrôle qualité', BLOQUE: 'Bloqué', ENVOYEE: 'Envoyée', PARTIELLEMENT_RECUE: 'Partiellement reçue', RECUE: 'Reçue', CONFIRMEE: 'Confirmée', EN_PREPARATION: 'En préparation', PARTIELLEMENT_LIVREE: 'Partiellement livrée', LIVREE: 'Livrée', ANNULEE: 'Annulée', PREPAREE: 'Préparée', EMBALLEE: 'Emballée', EXPEDIEE: 'Expédiée', OUVERT: 'Ouvert', CONFIRME: 'Confirmé', ANNULE: 'Annulé', CREE: 'Créé', LANCE: 'Lancé', PARTIELLEMENT_CONFIRME: 'Partiellement confirmé', TERMINE: 'Terminé', COMPTABILISE: 'Comptabilisé', RECU: 'Reçu', TRAITE: 'Traité', EN_COURS: 'En cours', SOUMISE: 'Remise', CORRIGEE: 'Corrigée', STOCK_E: 'Stock client E', SOLDEE: 'Soldée', SUPPRIMEE: 'Supprimée', CLOTURE: 'Clôturé (CLTE)', A_PREPARER: 'À préparer', A_CONTROLER: 'À contrôler' };
	function badge(st) { return h('span', { class: 'lw-badge ' + st, text: STATUS[st] || st }); }
	function chips(m) {
		if (!m) { return null; }
		return [
			m.adr ? h('span', { class: 'lw-chip adr', title: 'Matière dangereuse', text: 'ADR ' + m.adr }) : null,
			m.temp ? h('span', { class: 'lw-chip cold', title: 'Température', text: m.temp }) : null,
			m.hv ? h('span', { class: 'lw-chip hv', text: 'Sous clé' }) : null,
			m.fragile ? h('span', { class: 'lw-chip frag', text: 'Fragile' }) : null
		];
	}

	/* ── API ── */
	/** Adresse REST : fonctionne avec ou sans permaliens (base « ?rest_route= »). */
	function restUrl(path) {
		var i = path.indexOf('?');
		if (i < 0) { return C.rest + path; }
		return C.rest + path.slice(0, i) + (C.rest.indexOf('?') >= 0 ? '&' : '?') + path.slice(i + 1);
	}
	function api(method, path, body) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' } };
		if (typeof FormData !== 'undefined' && body instanceof FormData) { opts.body = body; } else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
		return fetch(restUrl(path), opts).then(function (r) {
			return r.json().catch(function () { return { ok: false, message: 'Réponse illisible du serveur.' }; }).then(function (j) {
				if (r.status === 401 || r.status === 403) {
					j = { ok: false, message: 'Votre connexion a expiré. Rechargez la page.' };
				}
				if (!j || j.ok === false) {
					var err = new Error((j && j.message) || 'Erreur');
					err.details = (j && j.errors) || [];
					throw err;
				}
				return j;
			});
		});
	}
	function get(path, params) {
		var q = params ? Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] !== null && params[k] !== undefined; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
		return api('GET', path + (q ? '?' + q : '')).then(function (j) { return j.data; });
	}
	function post(path, body) { return api('POST', path, body || {}); }

	/* ── État ── */
	var S = { me: null, session: null, sid: 0, tcode: 'SMEN', params: {}, poll: null, busy: false };
	function sp(path) { return 's/' + S.sid + path; }

	/* ── Barre de statut ── */
	var statusEl = null;
	function status(type, msg, details) {
		if (!statusEl) { return; }
		statusEl.className = 'lw-statusbar ' + (type || '');
		clear(statusEl);
		append(statusEl, [h('span', { class: 'dot' }), h('span', { text: msg || 'Prêt.' })]);
		if (details && details.length) { append(statusEl, h('span', { class: 'lw-small', text: '(' + details.length + ' détail' + (details.length > 1 ? 's' : '') + ' ci-dessus)' })); }
		// Sur smartphone, le message s'efface seul pour libérer l'écran (il reste affiché dans la page).
		if (root.classList.contains('lw-phone') && type) { clearTimeout(S.stTimer); var el = statusEl; S.stTimer = setTimeout(function () { el.className = 'lw-statusbar'; }, type === 'error' ? 9000 : 5000); }
	}
	function msgBox(type, text, details) {
		return h('div', { class: 'lw-msg ' + type, role: type === 'error' ? 'alert' : 'status' }, h('div', { text: text }), details && details.length ? h('ul', null, details.map(function (d) { return h('li', { text: d }); })) : null);
	}
	/** Exécute une action d'écriture : affiche le résultat au-dessus du formulaire et dans la barre de statut. */
	function act(box, promise, onOk) {
		if (S.busy) { return; }
		S.busy = true;
		var btns = box ? box.parentNode.querySelectorAll('button') : [];
		Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });
		status('', 'Traitement…');
		promise.then(function (j) {
			if (box) { clear(box); append(box, msgBox('ok', j.message || 'Enregistré.')); }
			status('ok', j.message || 'Enregistré.');
			if (onOk) { onOk(j.data, j.message); }
		}).catch(function (e) {
			if (box) { clear(box); append(box, msgBox('error', e.message, e.details)); box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
			status('error', e.message, e.details);
		}).then(function () {
			S.busy = false;
			Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
		});
	}
	function toast(title, text) {
		var t = h('div', { class: 'lw-toast', role: 'status' }, h('strong', { text: title }), h('div', { text: text }));
		root.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 9000);
		t.addEventListener('click', function () { t.parentNode.removeChild(t); });
	}

	/* ── Fenêtres et aides de recherche (F4) ── */
	function modal(title, body, opts) {
		var box = h('div', { class: 'lw-modal-box', role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, h('h3', { text: title }), body);
		var m = h('div', { class: 'lw-modal', onclick: function (e) { if (e.target === m) { close(); } } }, box);
		function close() { if (m.parentNode) { m.parentNode.removeChild(m); } document.removeEventListener('keydown', esc); }
		function esc(e) { if (e.key === 'Escape') { close(); } }
		document.addEventListener('keydown', esc);
		append(box, h('div', { class: 'lw-actions' }, (opts && opts.buttons) || null, h('button', { class: 'lw-btn lw-btn-secondary', type: 'button', text: 'Fermer', onclick: close })));
		root.appendChild(m);
		var f = box.querySelector('input,select,button'); if (f) { f.focus(); }
		return close;
	}
	var F4 = {
		material: { title: 'Rechercher un article', path: 'materials', cols: [['no', 'Article'], ['designation', 'Désignation'], ['profile_label', 'Profil'], ['uom', 'Unité']] },
		supplier: { title: 'Rechercher un fournisseur', path: 'partners', extra: { type: 'supplier' }, cols: [['no', 'N°'], ['name', 'Nom'], ['city', 'Localité'], ['extra', 'Spécialité']] },
		customer: { title: 'Rechercher un client', path: 'partners', extra: { type: 'customer' }, cols: [['no', 'N°'], ['name', 'Nom'], ['city', 'Localité'], ['extra', 'Type']] }
	};
	function f4(kind, onPick, initial) {
		var cfg = F4[kind];
		var input = h('input', { class: 'lw-input', type: 'search', placeholder: 'Numéro, nom, EAN…', value: initial || '', 'aria-label': 'Recherche' });
		var res = h('div', { class: 'lw-table-wrap', style: { marginTop: '10px' } });
		var close;
		function run() {
			var p = Object.assign({ q: input.value.trim() }, cfg.extra || {});
			get(sp('/' + cfg.path), p).then(function (rows) {
				clear(res);
				if (!rows.length) { append(res, h('div', { class: 'lw-empty', text: 'Aucun résultat.' })); return; }
				append(res, table(cfg.cols.map(function (c) { return c[1]; }), rows.map(function (r) {
					return { cells: cfg.cols.map(function (c) { return c[0] === 'no' ? h('span', { class: 'lw-mono', text: r[c[0]] }) : (r[c[0]] || ''); }).concat(r.blocked ? [] : []), onclick: function () { close(); onPick(r); }, cls: r.blocked ? 'expired' : '' };
				})));
			}).catch(function (e) { clear(res); append(res, msgBox('error', e.message)); });
		}
		var timer;
		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
		close = modal(cfg.title, h('div', null, input, res));
		run();
	}
	function f4Field(label, kind, onPick, hint) {
		var input = h('input', { class: 'lw-input code', type: 'text', autocomplete: 'off', 'aria-label': label });
		var wrap = h('label', { class: 'lw-field lw-f4' }, h('span', { text: label }), input,
			h('button', { type: 'button', title: 'Aide à la recherche (F4)', text: 'F4', onclick: function () { f4(kind, function (r) { input.value = r.no; onPick(r); }, input.value); } }),
			hint ? h('small', { text: hint }) : null);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'F4') { e.preventDefault(); f4(kind, function (r) { input.value = r.no; onPick(r); }, input.value); }
			if (e.key === 'Enter') { e.preventDefault(); f4(kind, function (r) { input.value = r.no; onPick(r); }, input.value); }
		});
		wrap.input = input;
		return wrap;
	}
	function field(label, input, hint) { return h('label', { class: 'lw-field' }, h('span', { text: label }), input, hint ? h('small', { text: hint }) : null); }
	function inp(attrs) { return h('input', Object.assign({ class: 'lw-input', type: 'text' }, attrs || {})); }
	function num(attrs) { return h('input', Object.assign({ class: 'lw-input num', type: 'number', step: 'any', min: '0', inputmode: 'decimal' }, attrs || {})); }
	function sel(options, value, attrs) {
		var el = h('select', Object.assign({ class: 'lw-select' }, attrs || {}));
		options.forEach(function (o) { var opt = h('option', { value: o[0], text: o[1] }); if (String(o[0]) === String(value)) { opt.selected = true; } el.appendChild(opt); });
		return el;
	}
	function btn(text, cls, onclick, attrs) { return h('button', Object.assign({ type: 'button', class: 'lw-btn ' + (cls || 'lw-btn-primary'), text: text, onclick: onclick }, attrs || {})); }

	/** Tableau ERP. rows : [{cells:[], onclick, cls}] ; numCols : index des colonnes numériques. */
	function table(heads, rows, numCols) {
		numCols = numCols || [];
		if (!rows.length) { return h('div', { class: 'lw-empty', text: 'Aucune donnée.' }); }
		var trs = rows.map(function (r) {
			return h('tr', { class: (r.cls || '') + (r.onclick ? ' clickable' : ''), tabindex: r.onclick ? '0' : null, onclick: r.onclick || null, onkeydown: r.onclick ? function (e) { if (e.key === 'Enter') { r.onclick(); } } : null },
				r.cells.map(function (c, i) { return h('td', { class: numCols.indexOf(i) >= 0 ? 'num' : '', 'data-label': typeof heads[i] === 'string' ? heads[i] || null : null }, c); }));
		});
		// Sur smartphone, les longues listes s'affichent par pages de 25 fiches.
		var page = root.classList.contains('lw-phone') ? 25 : 0;
		var tbody = h('tbody', null, page ? trs.slice(0, page) : trs);
		var wrap = h('div', { class: 'lw-table-wrap' }, h('table', { class: 'lw-table' },
			h('thead', null, h('tr', null, heads.map(function (t, i) { var str = typeof t === 'string'; return h('th', { class: numCols.indexOf(i) >= 0 ? 'num' : '', scope: 'col', text: str ? t : null }, str ? null : t); }))), tbody));
		if (page && trs.length > page) {
			var shown = page;
			var more = btn('', 'lw-btn-secondary lw-more', function () {
				append(tbody, trs.slice(shown, shown + page)); shown += page;
				if (shown >= trs.length) { more.parentNode.removeChild(more); } else { label(); }
			});
			var label = function () { more.textContent = 'Afficher la suite (' + (trs.length - shown) + ' restante' + (trs.length - shown > 1 ? 's' : '') + ' sur ' + trs.length + ')'; };
			label();
			append(wrap, more);
		}
		return wrap;
	}
	function dl(pairs) { return h('dl', { class: 'lw-dl' }, pairs.filter(Boolean).map(function (p) { return [h('dt', { text: p[0] }), h('dd', null, p[1] === '' || p[1] === null || p[1] === undefined ? '—' : p[1])]; })); }
	function card(title, body, cls) { return h('div', { class: 'lw-card ' + (cls || '') }, title ? h('h3', { text: title }) : null, body); }
	function loading() { return h('div', { class: 'lw-empty', text: 'Chargement…' }); }
	function fail(el, e) { clear(el); append(el, msgBox('error', e.message, e.details)); }
	function matCell(m) { return m ? [h('span', { class: 'lw-mono', text: m.no }), ' ', h('span', { text: m.designation }), ' ', chips(m)] : ''; }
	/** Manuel d'utilisation (PDF), ouvert à la procédure de la transaction affichée quand on la connaît. */
	function manualUrl() {
		if (!C.manual) { return ''; }
		var m = /^#\/s\/\d+\/([A-Z0-9_]+)/.exec(location.hash || ''), p = m ? C.manual.pages[m[1]] : 0;
		return C.manual.url + (p ? '#page=' + p : '');
	}
	function openManual() { var u = manualUrl(); if (u) { window.open(u, '_blank', 'noopener'); } }

	function go(tcode, params) {
		var q = params ? Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
		location.hash = '#/s/' + S.sid + '/' + tcode + (q ? '?' + q : '');
	}
	function link(text, tcode, params) { return h('a', { href: '#/s/' + S.sid + '/' + tcode + (params ? '?' + Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&') : ''), class: 'lw-mono', text: text }); }
	function printable(el) { el.classList.add('lw-printable'); return el; }
	function printBtn(el) { return btn('Imprimer', 'lw-btn-secondary lw-noprint', function () { document.querySelectorAll('.lw-printable').forEach(function (x) { if (x !== el) { x.classList.remove('lw-printable'); } }); el.classList.add('lw-printable'); window.print(); }); }

	/* ════════════════════════ Coquille : bandeau, portail, session ════════════════════════ */

	var TX = {}; // code → {label, group, mod, render(main, params)}
	var GROUPS = ['Session', 'Données de base', 'Achats', 'Réception et stock', 'Qualité', 'Entrepôt', 'Ventes et expédition', 'Transport', 'Planification', 'Production', 'Inventaire', 'Retours'];
	/* Anciens codes « LW-… » (versions 1.0 à 1.3) toujours acceptés. */
	var OLD_CODES = { 'LW-ENTR': 'ZENTR', 'LW-HOME': 'SMEN', 'LW-JOURNAL': 'SLG1', 'LW-ME21': 'ME21N', 'LW-ME23': 'ME23N', 'LW-CMR': 'ZCMR', 'LW-ETIQ': 'HU03', 'LW-MAP': 'ZMAP', 'LW-SCAN': 'LM00', 'LW-MDE': 'LM00', 'LW-VL01': 'VL01N', 'LW-VL02': 'VL02N', 'LW-CO11': 'CO11N', 'LW-RET': 'ZRET' };
	/** Code saisi → code de transaction (accepte « /nMIGO », « /oMIGO » comme dans SAP, et les anciens codes). */
	function tcode(v) {
		var c = String(v || '').trim().toUpperCase().replace(/^\/[NO]/, '');
		if (OLD_CODES[c]) { return OLD_CODES[c]; }
		return c.replace(/^LW-/, '');
	}
	function tx(code, label, group, mod, render) { TX[code] = { code: code, label: label, group: group, mod: mod, render: render }; }

	function heroEl(opts) {
		var top = h('div', { class: 'lw-hero-top' },
			h('div', { class: 'lw-brandrow' },
				h('a', { class: 'lw-brand', href: '#/', 'aria-label': 'Accueil LogiERP' }, h('img', { class: 'lw-logo', src: C.assets + 'img/logierp-logo-blanc.svg', alt: 'LogiERP by LogiProf', width: '300', height: '80', decoding: 'async' })),
				h('span', { class: 'lw-space-badge student', text: 'Espace apprenti' })),
			h('div', { class: 'lw-user' },
				opts.clock ? h('span', { class: 'lw-clock', title: 'Date simulée de la session' }, '📅 ', date(opts.clock)) : null,
				h('span', null, h('strong', { text: C.user.name })),
				C.adminUrl ? h('a', { href: C.adminUrl, title: 'Vous êtes enseignant : retour à votre espace', text: 'Espace enseignant' }) : null,
				h('a', { href: C.logoutUrl, text: 'Se déconnecter' })));
		var hero = h('header', { class: 'lw-hero' + (opts.compact ? ' compact' : '') }, top);
		if (opts.toolbar) { append(hero, opts.toolbar); }
		var text = h('div', { class: 'lw-hero-text' }, opts.kicker ? h('p', { class: 'lw-kicker', text: opts.kicker }) : null, h('h2', { class: 'lw-hello', text: opts.title }), opts.lead ? h('p', { class: 'lw-lead', text: opts.lead }) : null);
		var main = h('div', { class: 'lw-hero-main' + (opts.art ? ' has-art' : '') }, text);
		if (opts.art) {
			var art = h('div', { class: 'lw-art' });
			var tpl = document.createElement('template');
			tpl.innerHTML = C.hero; // SVG statique fourni par le plugin (aucune donnée utilisateur).
			art.appendChild(tpl.content);
			append(main, art);
		}
		append(hero, main);
		return hero;
	}

	function backIcon() { return s('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true' }, s('path', { d: 'M15 5l-7 7 7 7', fill: 'none', stroke: 'currentColor', 'stroke-width': '2.6', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })); }

	/* ── Portail ── */
	function renderPortal() {
		stopPoll();
		clear(root);
		var body = h('div', { class: 'lw-body' }, loading());
		append(root, [heroEl({ title: 'Bonjour ' + (C.user.first || ''), kicker: 'Espace apprenti · Simulateur ERP / WMS', lead: 'Réceptionnez, rangez, préparez et expédiez comme dans un vrai entrepôt. Choisissez un scénario pour commencer.', art: true }), body]);
		statusEl = null;
		get('me').then(function (me) {
			S.me = me;
			clear(body);
			if (!me.ready) { append(body, msgBox('warn', 'Le jeu de données n\'est pas encore généré. Votre enseignant ou l\'administrateur doit lancer la génération (menu LogiERP › Outils et santé).')); }
			var mdeBanner = h('div');
			append(body, mdeBanner);
			if (C.company) {
				append(body, h('div', { class: 'lw-co-banner' },
					companyLogo(C.company.name, 40),
					h('div', null, h('strong', { text: 'Votre entreprise de formation' }), h('p', { class: 'lw-small', text: C.company.tagline })),
					btn('Découvrir l\'entreprise', 'lw-btn-secondary', function () { location.hash = '#/entreprise'; })));
			}
			if (C.manual) {
				append(body, h('div', { class: 'lw-manual-banner' },
					h('span', { class: 'lw-manual-ico', 'aria-hidden': 'true', text: 'PDF' }),
					h('div', null, h('strong', { text: 'Manuel d\'utilisation' }), h('p', { class: 'lw-small', text: 'Toutes les transactions expliquées étape par étape, avec captures d\'écran. Dans une mission, le bouton « Manuel » ouvre directement la bonne procédure.' })),
					h('div', { class: 'lw-manual-actions' },
						h('a', { class: 'lw-btn lw-btn-secondary', href: C.manual.url, target: '_blank', rel: 'noopener', text: 'Lire' }),
						h('a', { class: 'lw-btn lw-btn-secondary', href: C.manual.download, text: 'Télécharger le PDF' }))));
			}
			var hasOpen = me.sessions.some(function (x) { return x.status === 'EN_COURS'; });
			append(isPhone() ? mdeBanner : body, h('div', { class: 'lw-mde-banner' + (isPhone() ? ' phone' : '') }, h('div', null, h('strong', { text: 'Terminal mobile (MDE)' }), h('p', { class: 'lw-small', text: isPhone() ? 'Vous êtes sur smartphone : scannez les palettes, les articles et les emplacements avec la caméra.' : 'Sur smartphone, ouvrez cette page et touchez « Terminal mobile » pour scanner avec la caméra.' })),
				btn('Ouvrir le terminal', isPhone() ? 'lw-btn-key' : 'lw-btn-secondary', function () { location.hash = '#/mde'; }, { disabled: hasOpen ? null : true, title: hasOpen ? '' : 'Commencez d\'abord une mission' })));
			append(body, h('h3', { class: 'lw-section-title', text: 'Mes scénarios' }));
			if (me.assignments.some(function (a) { return a.need_code; })) {
				var mcode = h('input', { type: 'text', maxlength: '12', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', placeholder: 'ex. K7M2-QX9A', 'aria-label': 'Code de mission' });
				var merr = h('p', { class: 'lw-code-err', role: 'alert', hidden: true });
				var mbtn = btn('Commencer la mission', 'lw-btn-primary', function () {
					var v = mcode.value.trim();
					if (!v) { mcode.focus(); return; }
					startSession({ code: v }, mbtn, merr);
				});
				mcode.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mbtn.click(); } });
				append(body, h('div', { class: 'lw-join lw-mcode' }, h('strong', { text: 'Code de mission' }), h('p', { class: 'lw-small lw-muted', text: 'Saisissez le code personnel de votre fiche. Il ne fonctionne qu\'avec votre compte.' }), h('div', { class: 'lw-join-row' }, mcode, mbtn), merr));
			}
			var open = {};
			me.sessions.forEach(function (x) { if (x.status === 'EN_COURS' && x.assignment) { open[x.assignment] = x.id; } });
			if (!me.assignments.length) { append(body, h('p', { class: 'lw-muted', text: 'Aucun scénario ne vous est assigné pour le moment.' })); }
			append(body, h('div', { class: 'lw-tiles' }, me.assignments.map(function (a) {
				var closes = a.closes_at ? new Date(a.closes_at.replace(' ', 'T') + 'Z') : null;
				var soon = closes && (closes - Date.now()) < 2 * 86400000;
				var late = closes && closes < Date.now();
				return h('div', { class: 'lw-tile' + (late ? ' late' : soon ? ' warn' : '') },
					h('p', { class: 'lw-muted lw-small', text: a.class + ' · ' + levelLabel(a.level) + ' · ' + a.duration + ' min' }),
					h('h4', { text: a.title }),
					h('p', { class: 'lw-small lw-pre', text: (a.objectives || '').split('\n').slice(0, 3).join('\n') }),
					closes ? h('p', { class: 'lw-small lw-muted', text: 'Jusqu\'au ' + closes.toLocaleString('fr-CH') }) : null,
					a.group ? h('p', { class: 'lw-team-badge', text: a.team ? 'Travail en groupe · ' + a.team.name + (a.team.members.length ? ' · avec ' + a.team.members.join(', ') : '') : 'Travail en groupe · aucun groupe : demandez à votre enseignant' }) : null,
					a.doc_url ? h('a', { class: 'lw-doclink', href: a.doc_url, target: '_blank', rel: 'noopener', text: 'Document d\'activité (ma mission)' }) : null,
					open[a.id]
						? btn('Reprendre', 'lw-btn-key', function () { location.hash = '#/s/' + open[a.id]; })
						: a.need_code && !late
							? tileCode(a, me)
							: btn(late ? 'Fermé' : (a.group ? 'Commencer ou rejoindre' : 'Commencer'), 'lw-btn-primary', function () { startSession({ assignment_id: a.id }, this); }, { disabled: late || !me.ready }));
			})));
			var code = h('input', { type: 'text', maxlength: '12', autocomplete: 'off', placeholder: 'ex. K7M2QX9A', 'aria-label': 'Code d\'invitation' });
			var joinBtn = btn('Rejoindre', 'lw-btn-secondary', function () {
				var v = code.value.trim();
				if (!v) { return; }
				joinBtn.disabled = true;
				post('join', { code: v }).then(function (j) { toast('Classe rejointe', j.message); renderPortal(); }).catch(function (e) { joinBtn.disabled = false; status('error', e.message); alert(e.message); });
			});
			code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); joinBtn.click(); } });
			append(body, h('div', { class: 'lw-join' }, h('h3', { class: 'lw-section-title', text: 'Rejoindre une classe' }), h('p', { class: 'lw-small lw-muted', text: 'Votre enseignant vous a donné un code d\'invitation ? Saisissez-le ici.' }), h('div', { class: 'lw-join-row' }, code, joinBtn)));
			append(body, h('h3', { class: 'lw-section-title', text: 'Mes sessions' }));
			append(body, table(['Scénario', 'Statut', 'Début', 'Remise', 'Note', ''], me.sessions.map(function (x) {
				return { cells: [x.title + (x.test ? ' (test)' : '') + (x.team ? ' · ' + x.team : ''), badge(x.status), date(x.started_at), date(x.submitted_at), x.grade !== null ? h('strong', { text: Number(x.grade).toFixed(1) }) : (x.status === 'CORRIGEE' ? 'À publier' : ''), [btn(x.status === 'EN_COURS' ? 'Ouvrir' : 'Consulter', 'lw-btn-secondary lw-btn-sm', function () { location.hash = '#/s/' + x.id; }), x.kpi_url ? h('a', { class: 'lw-doclink lw-kpilink', href: x.kpi_url, target: '_blank', rel: 'noopener', text: 'Indicateurs' }) : null]] };
			}), [4]));
		}).catch(function (e) { fail(body, e); });
	}
	/* Code personnel sur la tuile : la mission ne s'ouvre qu'avec le code de son titulaire. */
	function tileCode(a, me) {
		var inp = h('input', { type: 'text', maxlength: '12', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', placeholder: 'Code personnel', 'aria-label': 'Code personnel pour ' + a.title });
		var err = h('p', { class: 'lw-code-err', role: 'alert', hidden: true });
		var go = btn(a.group ? 'Rejoindre' : 'Commencer', 'lw-btn-primary', function () {
			var v = inp.value.trim();
			if (!v) { inp.focus(); return; }
			startSession({ assignment_id: a.id, code: v }, go, err);
		}, { disabled: !me.ready });
		inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go.click(); } });
		return h('div', { class: 'lw-tile-code' }, inp, go, err);
	}
	function levelLabel(l) { return { debutant: 'Débutant', intermediaire: 'Intermédiaire', avance: 'Avancé', examen: 'Examen' }[l] || l; }
	function startSession(body, button, errEl) {
		if (button) { button.disabled = true; }
		if (errEl) { errEl.hidden = true; }
		post('sessions', body).then(function (j) { location.hash = '#/s/' + j.data.session_id; }).catch(function (e) {
			if (button) { button.disabled = false; }
			if (errEl) { errEl.textContent = e.message; errEl.hidden = false; } else { alert(e.message); }
		});
	}

	/* ── Session ── */
	var mainEl = null, navEl = null, tasksEl = null, workEl = null;
	function visible(code) {
		var t = TX[code];
		if (!t) { return false; }
		if (!t.mod) { return true; }
		var mods = (S.session && S.session.modules) || [];
		if (!mods.length) { return true; }
		return t.mod.split('|').some(function (m) { return mods.indexOf(m) >= 0; });
	}
	var phoneBar = null;
	function openSheet(el) {
		closeSheets();
		el.classList.add('lw-sheet-open');
		document.body.classList.add('lw-sheet-on');
		var f = el.querySelector('.lw-nav-filter'); if (f) { f.value = ''; f.dispatchEvent(new Event('input')); }
		var c = el.querySelector('.lw-sheet-close'); if (c) { c.focus(); }
	}
	function closeSheets() {
		[navEl, tasksEl].forEach(function (x) { if (x) { x.classList.remove('lw-sheet-open'); } });
		document.body.classList.remove('lw-sheet-on');
	}
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.classList.contains('lw-sheet-on')) { closeSheets(); } });
	function phoneIcon(name) {
		var P = {
			menu: 'M4 6h16 M4 12h16 M4 18h16',
			tasks: 'M9 6h11 M9 12h11 M9 18h11 M4 6l1 1 2-2 M4 12l1 1 2-2 M4 18l1 1 2-2',
			scan: 'M4 7V4h3 M17 4h3v3 M20 17v3h-3 M7 20H4v-3 M7 8v8 M10 8v8 M13 8v8 M16 8v8',
			send: 'M4 12l16-8-6 16-3-6z M11 14l9-10',
			lock: 'M6 11h12v9H6z M8 11V8a4 4 0 0 1 8 0v3'
		};
		return s('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true', class: 'lw-ico' }, s('path', { d: P[name], fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
	}

	/* Champs de code : bouton caméra sur smartphone (article, emplacement, document, SSCC…). */
	function scanValue(r, raw) {
		if (!r) { return raw; }
		if (r.kind === 'bin') { return r.bin.code; }
		if (r.kind === 'material' || r.kind === 'gtin') { return r.material.no; }
		if (r.kind === 'sscc') { return r.sscc; }
		if (r.kind === 'hu') { return r.hu_no; }
		return r.no || raw;
	}
	function fillScanned(inp, raw) {
		var done = function (v) {
			inp.value = v;
			inp.dispatchEvent(new Event('input', { bubbles: true }));
			inp.dispatchEvent(new Event('change', { bubbles: true }));
			inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
			if (BC) { BC.vibrate(40); }
		};
		if (!S.sid) { done(raw); return; }
		get(sp('/scan'), { code: raw }).then(function (r) { done(scanValue(r, raw)); }).catch(function () { done(String(raw).replace(/[^A-Za-z0-9\-]/g, '')); });
	}
	function enhanceScan(node) {
		if (!BC || !root.classList.contains('lw-phone') || !node || !node.querySelectorAll || document.body.classList.contains('lw-mde-on')) { return; }
		Array.prototype.forEach.call(node.querySelectorAll('input.lw-input.code:not([data-scan]):not([data-noscan])'), function (inp) {
			if (inp.disabled || inp.readOnly) { return; }
			inp.setAttribute('data-scan', '1');
			var label = inp.getAttribute('aria-label') || inp.getAttribute('placeholder') || 'Scanner un code';
			var wrap = h('span', { class: 'lw-scanwrap' });
			inp.parentNode.insertBefore(wrap, inp);
			wrap.appendChild(inp);
			append(wrap, h('button', { type: 'button', class: 'lw-scanbtn', 'aria-label': 'Scanner avec la caméra : ' + label, title: 'Scanner avec la caméra', onclick: function (e) { e.preventDefault(); e.stopPropagation(); camera(label, function (code) { fillScanned(inp, code); }); } }, icon('cam')));
		});
	}
	if (window.MutationObserver) {
		var scanTimer = null;
		new MutationObserver(function () { if (scanTimer) { return; } scanTimer = setTimeout(function () { scanTimer = null; enhanceScan(root); }, 60); }).observe(root, { childList: true, subtree: true });
	}

	function renderSession(sid, tcode, params) {
		var fresh = S.sid !== sid || !mainEl || !document.body.contains(mainEl);
		S.sid = sid; S.tcode = tcode || 'SMEN'; S.params = params || {};
		if (fresh) {
			clear(root);
			append(root, h('div', { class: 'lw-body' }, loading()));
			get('s/' + sid).then(function (sess) {
				S.session = sess;
				// Première ouverture : l'entreprise est présentée avant la mission.
				if (!tcode && !sess.company_seen && !sess.readonly && C.company) { S.tcode = 'ZENTR'; }
				buildSession(); showTx(); startPoll();
			}).catch(function (e) { clear(root); append(root, heroEl({ title: 'Session introuvable', compact: true })); append(root, h('div', { class: 'lw-body' }, msgBox('error', e.message), btn('Retour au portail', 'lw-btn-key', function () { location.hash = '#/'; }))); });
		} else {
			showTx();
		}
	}
	function refreshSession() { return get('s/' + S.sid).then(function (sess) { S.session = sess; renderTasks(); }); }
	function buildSession() {
		clear(root);
		var input = h('input', { id: 'lw-tcode', type: 'text', placeholder: 'MIGO', autocomplete: 'off', list: 'lw-tcodes', 'aria-label': 'Code transaction' });
		var dlist = h('datalist', { id: 'lw-tcodes' }, Object.keys(TX).filter(visible).map(function (c) { return h('option', { value: c, label: TX[c].label }); }));
		function jump() { var c = tcode(input.value); if (TX[c] && visible(c)) { input.value = ''; go(c); } else { status('error', 'Transaction inconnue ou non disponible dans ce scénario : ' + c); } }
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); jump(); } });
		var toolbar = h('div', { class: 'lw-toolbar' },
			h('button', { class: 'lw-back', type: 'button', onclick: function () { location.hash = '#/'; } }, backIcon(), 'Retour au portail'),
			h('div', { class: 'lw-tcode' }, h('label', { for: 'lw-tcode', text: 'Transaction' }), input, dlist, h('button', { type: 'button', text: 'OK', onclick: jump })),
			h('div', { class: 'lw-tools-right' },
				btn('Consignes', 'lw-btn-secondary', function () { workEl.classList.toggle('with-tasks'); tasksEl.classList.toggle('lw-hidden'); }),
				C.manual ? btn('Manuel', 'lw-btn-secondary', openManual, { title: 'Manuel d\'utilisation (PDF), ouvert à la procédure de la transaction affichée' }) : null,
				btn('Terminal mobile', 'lw-btn-secondary', mdeInvite, { title: 'Utiliser un smartphone comme terminal de scan (MDE)' }),
				S.session.readonly ? h('span', { class: 'lw-badge yellow', text: 'Lecture seule' }) : btn('Remettre ma session', 'lw-btn-key', submitSession)));
		var sess = S.session;
		append(root, heroEl({ compact: true, toolbar: toolbar, clock: sess.sim_date, kicker: levelLabel(sess.level) + ' · ' + (sess.mode === 'SIMPLE' ? 'Gestion simple (magasins)' : 'Gestion détaillée (emplacements)') + (sess.test ? ' · Session de test' : ''), title: sess.title }));
		navEl = h('nav', { class: 'lw-nav', 'aria-label': 'Transactions' });
		mainEl = h('main', { class: 'lw-main', tabindex: '-1' });
		tasksEl = h('aside', { class: 'lw-tasks', 'aria-label': 'Consignes' });
		workEl = h('div', { class: 'lw-work with-tasks' }, navEl, mainEl, tasksEl);
		statusEl = h('div', { class: 'lw-statusbar', role: 'status', 'aria-live': 'polite' });
		phoneBar = h('nav', { class: 'lw-phonebar', 'aria-label': 'Navigation' },
			h('button', { type: 'button', onclick: function () { openSheet(navEl); } }, phoneIcon('menu'), h('span', { text: 'Transactions' })),
			h('button', { type: 'button', class: 'lw-pb-tasks', onclick: function () { openSheet(tasksEl); } }, phoneIcon('tasks'), h('span', { text: 'Consignes' })),
			h('button', { type: 'button', onclick: function () { location.hash = '#/mde/' + S.sid; } }, phoneIcon('scan'), h('span', { text: 'Terminal' })),
			sess.readonly ? h('button', { type: 'button', disabled: true }, phoneIcon('lock'), h('span', { text: 'Lecture seule' })) : h('button', { type: 'button', class: 'key', onclick: submitSession }, phoneIcon('send'), h('span', { text: 'Remettre' })));
		append(root, [workEl, statusEl, phoneBar]);
		status('', sess.readonly ? 'Session en lecture seule.' : 'Prêt. Tapez un code transaction (ex. MIGO) ou choisissez dans le menu.');
		var filter = h('input', { class: 'lw-input lw-nav-filter', type: 'search', placeholder: 'Chercher : MIGO, stock, tournée…', 'aria-label': 'Chercher une transaction', autocomplete: 'off' });
		append(navEl, h('div', { class: 'lw-sheet-head' }, h('strong', { text: 'Transactions' }), h('button', { type: 'button', class: 'lw-sheet-close', 'aria-label': 'Fermer', text: '✕', onclick: closeSheets })), filter);
		GROUPS.forEach(function (g) {
			var codes = Object.keys(TX).filter(function (c) { return TX[c].group === g && visible(c); });
			if (!codes.length) { return; }
			append(navEl, h('h5', { text: g }));
			codes.forEach(function (c) { append(navEl, h('button', { type: 'button', dataset: { code: c, search: (c + ' ' + TX[c].label + ' ' + g).toLowerCase() }, onclick: function () { closeSheets(); go(c); } }, h('code', { text: c }), h('span', { text: TX[c].label }))); });
		});
		filter.addEventListener('input', function () {
			var q = filter.value.trim().toLowerCase();
			Array.prototype.forEach.call(navEl.querySelectorAll('button[data-code]'), function (b) { b.hidden = q !== '' && b.dataset.search.indexOf(q) < 0; });
			Array.prototype.forEach.call(navEl.querySelectorAll('h5'), function (hd) { var n = hd.nextElementSibling, any = false; while (n && n.tagName === 'BUTTON') { if (!n.hidden) { any = true; } n = n.nextElementSibling; } hd.hidden = !any; });
		});
		renderTasks();
	}
	function renderTasks() {
		if (!tasksEl) { return; }
		var sess = S.session;
		clear(tasksEl);
		append(tasksEl, h('div', { class: 'lw-sheet-head' }, h('strong', { text: 'Consignes' }), h('button', { type: 'button', class: 'lw-sheet-close', 'aria-label': 'Fermer', text: '✕', onclick: closeSheets })));
		append(tasksEl, h('h4', { class: 'lw-tasks-title', text: 'Consignes' }));
		if (phoneBar) {
			var done = sess.tasks.filter(function (t) { return Number(t.done); }).length;
			var lbl = phoneBar.querySelector('.lw-pb-tasks span');
			if (lbl) { lbl.textContent = 'Consignes ' + done + '/' + sess.tasks.length; }
		}
		if (sess.team) { append(tasksEl, h('p', { class: 'lw-team-badge', text: 'Travail en groupe · ' + sess.team })); }
		if (sess.teacher_message) { append(tasksEl, msgBox('warn', 'Message de l\'enseignant : ' + sess.teacher_message)); }
		if (sess.paused) { append(tasksEl, msgBox('warn', 'Session en pause par l\'enseignant.')); }
		if (sess.deadline && sess.status === 'EN_COURS') {
			var skew = Date.now() - new Date(sess.now.replace(' ', 'T') + 'Z').getTime();
			var end = new Date(sess.deadline.replace(' ', 'T') + 'Z').getTime() + skew;
			var left = h('p', { class: 'lw-deadline', role: 'timer' });
			var tick = function () {
				if (!document.body.contains(left)) { clearInterval(S.timer); return; }
				var min = Math.max(0, Math.floor((end - Date.now()) / 60000));
				left.textContent = 'Temps restant : ' + (min >= 60 ? Math.floor(min / 60) + ' h ' + String(min % 60).padStart(2, '0') : min + ' min') + (min < 10 ? ' – pensez à remettre votre session.' : '');
				left.classList.toggle('urgent', min < 10);
			};
			if (S.timer) { clearInterval(S.timer); }
			append(tasksEl, left);
			tick();
			S.timer = setInterval(tick, 20000);
		}
		append(tasksEl, h('ol', null, sess.tasks.map(function (t) {
			return h('li', { class: Number(t.done) ? 'done' : '' }, h('strong', { text: t.title }), h('span', { class: 'lw-small', text: t.body || '' }), t.transaction_code && TX[t.transaction_code] ? h('div', null, h('button', { type: 'button', text: '→ ' + t.transaction_code, onclick: function () { closeSheets(); go(t.transaction_code); } })) : null);
		})));
		if (sess.documents && sess.documents.length) {
			append(tasksEl, h('h4', { style: { marginTop: '16px' }, text: 'Documents du scénario' }));
			append(tasksEl, h('ul', { class: 'lw-small', style: { paddingLeft: '18px' } }, sess.documents.map(function (d) { return h('li', null, docLink(d.type, d.no)); })));
		}
	}
	function docLink(type, no) {
		var map = { PO: ['ME23N', 'Commande d\'achat'], SO: ['VA03', 'Commande client'], PROD: ['CO01', 'Ordre de fabrication'] };
		var m = map[type] || ['SMEN', type];
		return [m[1] + ' ', link(no, m[0], { no: no })];
	}
	function showTx() {
		var t = TX[S.tcode] && visible(S.tcode) ? TX[S.tcode] : TX['SMEN'];
		Array.prototype.forEach.call(navEl.querySelectorAll('button'), function (b) { b.classList.toggle('active', b.dataset.code === t.code); if (b.dataset.code === t.code) { b.setAttribute('aria-current', 'page'); } else { b.removeAttribute('aria-current'); } });
		clear(mainEl);
		append(mainEl, h('div', { class: 'lw-screen-head' }, h('h2', { text: t.label }), h('code', { text: t.code })));
		var body = h('div');
		append(mainEl, body);
		try { t.render(body, S.params); } catch (e) { append(body, msgBox('error', 'Erreur d\'affichage : ' + e.message)); }
		mainEl.focus({ preventScroll: true });
		closeSheets();
		if (root.classList.contains('lw-phone')) { var y = mainEl.getBoundingClientRect().top + window.pageYOffset - 8; if (window.pageYOffset > y) { window.scrollTo(0, y); } }
	}
	function submitSession() {
		var close = modal('Remettre la session', h('div', null, h('p', { text: 'Une fois remise, la session passe en lecture seule et est corrigée automatiquement. Vous ne pourrez plus rien modifier.' }), h('p', { class: 'lw-muted', text: 'Vérifiez que toutes les consignes sont terminées.' })), {
			buttons: btn('Remettre définitivement', 'lw-btn-key', function () {
				close();
				post(sp('/submit')).then(function (j) { toast('Session remise', j.message); S.mainReset = true; mainEl = null; renderSession(S.sid, 'SMEN', {}); }).catch(function (e) { status('error', e.message, e.details); });
			})
		});
	}
	function startPoll() {
		stopPoll();
		S.poll = setInterval(function () {
			if (document.hidden) { return; }
			get(sp('/messages')).then(function (m) {
				(m.events || []).forEach(function (e) { toast('Nouvel événement', e.message); });
				if (m.status !== S.session.status) { toast('Session remise', 'Le temps imparti est écoulé ou la session a été remise : elle passe en lecture seule.'); mainEl = null; if (S.mde) { S.session = null; route(); } else { renderSession(S.sid, 'SMEN', {}); } return; }
				if ((m.events || []).length || m.teacher_message !== S.session.teacher_message || m.paused !== S.session.paused) { if (S.mde) { if (m.teacher_message && m.teacher_message !== S.session.teacher_message) { toast('Message de votre enseignant', m.teacher_message); } refreshSessionQuiet(); } else { refreshSession(); } }
			}).catch(function () {});
		}, 15000);
		get(sp('/messages')).then(function (m) { (m.events || []).forEach(function (e) { toast('Nouvel événement', e.message); }); }).catch(function () {});
	}
	function stopPoll() { if (S.poll) { clearInterval(S.poll); S.poll = null; } }

	/* ── Clavier : F3 = retour ── */
	document.addEventListener('keydown', function (e) {
		if (e.key === 'F3' && S.sid && location.hash.indexOf('#/s/') === 0) { e.preventDefault(); history.back(); }
	});

	/* ── Routeur (hash) ── */
	function route() {
		var hsh = location.hash || '#/';
		var m = hsh.match(/^#\/s\/(\d+)(?:\/([A-Z0-9_-]+))?(?:\?(.*))?$/);
		var md = hsh.match(/^#\/mde(?:\/(\d+)(?:\/([a-z]+))?)?(?:\?(.*))?$/);
		if (md) {
			var mp = {};
			(md[3] || '').split('&').forEach(function (p) { if (!p) { return; } var kv2 = p.split('='); mp[decodeURIComponent(kv2[0])] = decodeURIComponent(kv2[1] || ''); });
			if (md[1]) { mdeRoute(parseInt(md[1], 10), md[2] || '', mp); } else { renderMdeHome(); }
			return;
		}
		if (S.mde) { S.mde = false; document.body.classList.remove('lw-mde-on'); }
		if (m) {
			var params = {};
			(m[3] || '').split('&').forEach(function (p) { if (!p) { return; } var kv = p.split('='); params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); });
			renderSession(parseInt(m[1], 10), m[2] ? tcode(m[2]) : '', params);
			return;
		}
		if (hsh === '#/entreprise') { renderCompanyPage(); return; }
		S.sid = 0; mainEl = null;
		renderPortal();
	}
	window.addEventListener('hashchange', route);

	/* ════════════════════════ Accueil de session et résultats ════════════════════════ */

	/* ════════════════════════ Notre entreprise ════════════════════════ */

	function companyLogo(name, size) {
		var parts = String(name || '').split(/\s+/);
		var first = parts.shift() || '';
		var rest = parts.join(' ').toUpperCase();
		var svg = s('svg', { viewBox: '0 0 260 50', width: String(Math.round(size * 5.2)), height: String(size), role: 'img', 'aria-label': name, class: 'lw-co-logo' },
			s('rect', { x: '1', y: '3', width: '44', height: '44', rx: '10', fill: '#0e3b5c' }),
			s('path', { d: 'M13 13v24h18', fill: 'none', stroke: '#ffffff', 'stroke-width': '5', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }),
			s('path', { d: 'M9 31c5-4 9-4 14 0s9 4 14 0', fill: 'none', stroke: '#3cc3b4', 'stroke-width': '3.2', 'stroke-linecap': 'round' }),
			s('text', { x: '54', y: '27', 'font-size': '21', 'font-weight': '700', fill: 'currentColor', text: first }),
			s('text', { x: '55', y: '44', 'font-size': '11.5', 'font-weight': '500', 'letter-spacing': '1.2', fill: '#2a9d8f', text: rest }));
		return svg;
	}

	/** Présentation complète de l'entreprise fictive. footer : boutons affichés en bas. */
	function companyView(footer) {
		var co = C.company;
		if (!co) { return msgBox('info', 'Présentation indisponible.'); }
		var wrap = h('div', { class: 'lw-co' });
		append(wrap, h('section', { class: 'lw-co-hero' },
			h('div', { class: 'lw-co-brand' }, companyLogo(co.name, 54)),
			h('p', { class: 'lw-co-tagline', text: co.tagline }),
			h('p', { class: 'lw-co-intro', text: co.intro }),
			h('p', { class: 'lw-small lw-co-addr', text: co.address.replace(/\n/g, ', ') + (co.phone ? ' · ' + co.phone : '') })));
		append(wrap, card('Votre rôle', h('p', { class: 'lw-pre', text: co.role }), 'yellow'));
		if (co.figures.length) {
			append(wrap, h('h3', { class: 'lw-section-title', text: 'Chiffres clés' }), h('p', { class: 'lw-small lw-muted', text: 'Valeurs indicatives pour une année.' }),
				h('div', { class: 'lw-co-figs' }, co.figures.map(function (f) { return h('div', { class: 'lw-co-fig' }, h('strong', { text: f.value }), h('span', { text: f.label })); })));
		}
		if (co.sites.length) {
			append(wrap, h('h3', { class: 'lw-section-title', text: 'Nos sites en Suisse' }),
				h('div', { class: 'lw-co-sites' }, co.sites.map(function (x, i) {
					return h('div', { class: 'lw-co-site' + (i === 0 ? ' main' : '') },
						h('div', { class: 'lw-co-site-head' }, h('span', { class: 'lw-co-canton', text: x.canton }), h('h4', { text: x.name })),
						h('p', { class: 'lw-small', text: x.activity }),
						h('p', { class: 'lw-small lw-muted', text: [x.surface, x.staff ? x.staff + ' collaborateurs' : ''].filter(Boolean).join(' · ') }));
				})));
		}
		var cols = h('div', { class: 'lw-grid2' });
		if (co.dc.length) { append(cols, card('Le centre de distribution', dl(co.dc.map(function (r) { return [r.label, r.value]; })))); }
		if (co.org.length) { append(cols, card('Organisation du site', dl(co.org.map(function (r) { return [r.label, r.value]; })), 'ink')); }
		append(wrap, cols);
		var cols2 = h('div', { class: 'lw-grid2' });
		if (co.timeline.length) {
			append(cols2, card('Historique', h('ol', { class: 'lw-co-time' }, co.timeline.map(function (t) { return h('li', null, h('b', { text: t.year }), h('span', { text: t.text })); }))));
		}
		if (co.rules.length) {
			append(cols2, card('Règles de la maison', h('ul', { class: 'lw-co-rules' }, co.rules.map(function (r) { return h('li', { text: r.text }); })), 'yellow'));
		}
		append(wrap, cols2);
		if (co.context) { append(wrap, card('Situation actuelle et enjeux', h('p', { class: 'lw-pre', text: co.context }))); }
		if (footer) { append(wrap, h('div', { class: 'lw-actions lw-co-actions' }, footer)); }
		return wrap;
	}

	function renderCompanyPage() {
		stopPoll();
		S.sid = 0; mainEl = null; statusEl = null;
		clear(root);
		append(root, heroEl({ title: C.company ? C.company.name : 'Notre entreprise', kicker: 'Votre entreprise', lead: 'Découvrez l\'entreprise dans laquelle vous travaillez avant de commencer vos missions.', compact: true }));
		append(root, h('div', { class: 'lw-body' }, companyView(btn('Retour au portail', 'lw-btn-key', function () { location.hash = '#/'; }))));
		window.scrollTo(0, 0);
	}

	tx('ZENTR', 'Notre entreprise', 'Session', '', function (el) {
		var seen = S.session.company_seen || S.session.readonly;
		var go2 = btn(seen ? 'Voir ma mission' : 'J\'ai pris connaissance de l\'entreprise : voir ma mission', 'lw-btn-key', function () {
			if (seen) { go('SMEN'); return; }
			go2.disabled = true;
			post(sp('/company-read')).then(function () { S.session.company_seen = 1; go('SMEN'); }).catch(function (e) { go2.disabled = false; status('error', e.message); });
		});
		if (!seen) { append(el, msgBox('info', 'Avant votre première mission, prenez quelques minutes pour découvrir l\'entreprise : vous y trouverez les informations utiles pour travailler (sites, zones, horaires, règles).')); }
		append(el, companyView(go2));
	});

	tx('SMEN', 'Accueil de la session', 'Session', '', function (el) {
		var sess = S.session;
		if (sess.report) {
			var g = Number(sess.grade);
			append(el, card('Résultat', h('div', null,
				h('div', { class: 'lw-inline', style: { gap: '20px', alignItems: 'center' } },
					h('div', { class: 'lw-grade ' + (g >= 5 ? 'good' : g >= 4 ? 'mid' : 'bad'), text: g.toFixed(1) }),
					h('div', null, h('p', { text: 'Points : ' + qty(sess.score) + ' / ' + qty(sess.max) }), sess.comment ? h('p', { class: 'lw-pre', text: 'Commentaire de l\'enseignant : ' + sess.comment }) : null)),
				h('div', { style: { marginTop: '14px' } }, sess.report.map(function (r) {
					var earned = r.adjusted !== null && r.adjusted !== undefined ? r.adjusted : r.earned;
					return h('div', { class: 'lw-check' + (earned > 0 ? ' ok' : '') }, h('b', { text: earned > 0 ? '✓' : '✗' }), h('div', null, h('strong', { text: r.label + ' (' + qty(earned) + ' / ' + qty(r.points) + ')' }), h('div', { class: 'lw-small lw-muted', text: r.detail }), r.comment ? h('div', { class: 'lw-small', text: 'Enseignant : ' + r.comment }) : null));
				}))), 'yellow'));
		} else if (sess.status !== 'EN_COURS') {
			append(el, msgBox('info', 'Session remise. Le résultat sera visible quand votre enseignant l\'aura publié.'));
		}
		if (sess.doc_url) { append(el, h('p', { class: 'lw-docbar' }, h('a', { class: 'lw-doclink', href: sess.doc_url, target: '_blank', rel: 'noopener', text: 'Document d\'activité : la mission, les documents et les étapes (imprimable)' }), sess.files_url ? h('a', { class: 'lw-doclink', href: sess.files_url, target: '_blank', rel: 'noopener', text: 'Documents logistiques : bulletins de livraison, commandes, CMR (imprimables)' }) : null, sess.kpi_url ? h('a', { class: 'lw-doclink lw-kpilink', href: sess.kpi_url, target: '_blank', rel: 'noopener', text: 'Tableau d\'indicateurs de la mission' }) : null)); }
		append(el, h('div', { class: 'lw-grid2' },
			card('Mise en situation', h('p', { class: 'lw-pre', text: sess.instructions || '—' })),
			card('Objectifs', h('p', { class: 'lw-pre', text: sess.objectives || '—' }), 'ink')));
		append(el, card('Comment travailler', h('ul', { class: 'lw-small', style: { paddingLeft: '18px', margin: 0 } },
			h('li', { text: 'Tapez un code transaction dans le champ « Transaction » (ex. MIGO) ou utilisez le menu.' }),
			h('li', { text: 'Touche F4 dans un champ article, fournisseur ou client : aide à la recherche. F3 : retour.' }),
			h('li', { text: 'Le système contrôle toutes les règles : profil de stockage, température, matières dangereuses, charge, emplacements fixes, FIFO / FEFO.' }),
			h('li', { text: 'Chaque action est enregistrée. Une erreur refusée compte comme erreur de saisie : lisez bien les messages.' }),
			h('li', { text: 'Quand toutes les consignes sont faites, cliquez sur « Remettre ma session ».' }))));
	});

	tx('SLG1', 'Mon journal d\'actions', 'Session', '', function (el) {
		var box = h('div', null, loading());
		append(el, box);
		get(sp('/journal')).then(function (rows) {
			clear(box);
			var team = rows.some(function (r) { return r.by; });
			append(box, table(['Date'].concat(team ? ['Membre'] : [], ['Transaction', 'Action', 'Résultat', 'Document', 'Message']), rows.map(function (r) {
				return { cells: [new Date(r.created_at.replace(' ', 'T') + 'Z').toLocaleTimeString('fr-CH')].concat(team ? [r.by] : [], [h('span', { class: 'lw-mono', text: r.transaction_code }), r.action, r.result === 'ok' ? badge('CONFIRME') : (r.result === 'info' ? h('span', { class: 'lw-badge', text: 'Info' }) : h('span', { class: 'lw-badge red', text: 'Erreur' })), r.doc_no, r.message]) };
			})));
		}).catch(function (e) { fail(box, e); });
	});

	/* ════════════════════════ Données de base ════════════════════════ */

	tx('MM03', 'Afficher un article', 'Données de base', '', function (el, p) {
		var out = h('div');
		var f = f4Field('Article', 'material', function (r) { show(r.no); }, 'Numéro, désignation ou EAN — F4 pour chercher');
		append(el, card(null, h('div', { class: 'lw-form' }, f)), out);
		function show(no) {
			clear(out); append(out, loading());
			get(sp('/materials/' + encodeURIComponent(no))).then(function (m) {
				clear(out);
				var tabs = { 'Général': function () {
					return h('div', { class: 'lw-grid2' }, dl([['Article', h('span', { class: 'lw-mono', text: m.no })], ['Désignation', m.designation], ['Groupe', m.group], ['Type', m.type], ['Unité de base', m.uom], ['EAN', m.ean && BC && /^\d{13}$/.test(m.ean) ? h('div', { class: 'lw-bc ean' }, BC.ean13Svg(m.ean, { module: 2, height: 54 })) : h('span', { class: 'lw-mono', text: m.ean })], ['Classe ABC / XYZ', m.abc + ' / ' + m.xyz], ['Prix standard', 'CHF ' + chf(m.std_price)], ['Prix de vente', 'CHF ' + chf(m.sale_price)]]),
						dl([['Poids net / brut', qty(m.net_weight) + ' / ' + qty(m.gross_weight) + ' kg'], ['Dimensions', m.dimensions], ['Volume', qty(m.volume_l) + ' L'], ['Conditionnement', m.units_per_carton + ' ' + m.uom + ' / carton, ' + m.cartons_per_pallet + ' cartons / palette'], ['Empilable', m.stackable ? 'Oui' : 'Non'], ['Gestion par lot', m.batch_managed ? 'Oui' : 'Non'], ['Durée de vie', m.shelf_life ? m.shelf_life + ' jours (DLC)' : '—'], ['N° de série', m.serial_managed ? 'Oui' : 'Non']]));
				}, 'Stockage': function () {
					return h('div', { class: 'lw-grid2' }, dl([['Profil de stockage', m.profile_label], ['Température', m.temp || 'Ambiante'], ['Matière dangereuse', m.adr ? 'Classe ' + m.adr + ' · ONU ' + m.un + (m.packing_group ? ' · GE ' + m.packing_group : '') + ' · ' + m.hazard_text : 'Non'], ['Sous clé', m.hv ? 'Oui (haute valeur)' : 'Non'], ['Fragile', m.fragile ? 'Oui : niveaux bas uniquement' : 'Non'], ['Emplacements fixes', m.fixed_bins.length ? m.fixed_bins.map(function (b) { return b.code + ' (min ' + qty(b.min) + ' / max ' + qty(b.max) + ')'; }).join(', ') : 'Aucun (stockage chaotique)']]),
						h('div', null, h('h4', { text: 'Magasins autorisés' }), h('ul', { class: 'lw-small', style: { paddingLeft: '18px' } }, m.allowed_locations.map(function (x) { return h('li', { text: x }); }))));
				}, 'Achats': function () {
					return h('div', null, dl([['Point de commande', qty(m.reorder_point)], ['Lot économique', qty(m.lot_size)], ['Stock min / max', qty(m.min_stock) + ' / ' + qty(m.max_stock)], ['Délai d\'approvisionnement', m.lead_time + ' jours']]), h('h4', { text: 'Fiches d\'info-achat' }),
						table(['Fournisseur', 'Nom', 'Réf. fournisseur', 'Prix CHF', 'Qté min.', 'Délai (j)', ''], m.suppliers.map(function (x) { return { cells: [h('span', { class: 'lw-mono', text: x.supplier_no }), x.name, x.supplier_ref, chf(x.price), qty(x.min_qty), x.lead_time_days, Number(x.is_main) ? h('span', { class: 'lw-badge green', text: 'Principal' }) : ''] }; }), [3, 4, 5]));
				}, 'Stock': function () {
					return h('div', null, dl([['Libre', qty(m.stock.LIBRE) + ' ' + m.uom], ['Contrôle qualité', qty(m.stock.CONTROLE_QUALITE)], ['Bloqué', qty(m.stock.BLOQUE)], ['Disponible (non réservé)', h('strong', { text: qty(m.stock.disponible) })]]), h('div', { class: 'lw-actions' }, btn('Détail par emplacement (MMBE)', 'lw-btn-secondary', function () { go('MMBE', { material: m.no }); })));
				}};
				if (m.bom.length) { tabs['Nomenclature'] = function () { return table(['Composant', 'Quantité', 'Rebut %'], m.bom.map(function (b) { return { cells: [matCell(b.material), qty(b.qty) + ' ' + b.material.uom, qty(b.scrap_pct)] }; }), [1, 2]); }; }
				append(out, card(null, [h('div', { class: 'lw-inline', style: { marginBottom: '8px' } }, h('strong', { text: m.no + ' – ' + m.designation }), chips(m)), tabbed(tabs)]));
			}).catch(function (e) { fail(out, e); });
		}
		if (p.no) { f.input.value = p.no; show(p.no); }
	});

	function tabbed(tabs) {
		var bar = h('div', { class: 'lw-tabs', role: 'tablist' });
		var pane = h('div', { role: 'tabpanel' });
		var names = Object.keys(tabs);
		function sel(n) { Array.prototype.forEach.call(bar.children, function (b) { b.classList.toggle('active', b.textContent === n); b.setAttribute('aria-selected', b.textContent === n ? 'true' : 'false'); }); clear(pane); append(pane, tabs[n]()); }
		names.forEach(function (n) { append(bar, h('button', { type: 'button', role: 'tab', text: n, onclick: function () { sel(n); } })); });
		sel(names[0]);
		return h('div', null, bar, pane);
	}

	function partnerScreen(type) {
		return function (el, p) {
			var out = h('div');
			var f = f4Field(type === 'supplier' ? 'Fournisseur' : 'Client', type, function (r) { show(r.id); });
			append(el, card(null, h('div', { class: 'lw-form' }, f)), out);
			function show(id) {
				clear(out); append(out, loading());
				get(sp('/partners/' + type + '/' + id)).then(function (x) {
					clear(out);
					var no = x.supplier_no || x.customer_no;
					append(out, card(no + ' – ' + x.name, h('div', { class: 'lw-grid2' },
						dl([['Adresse', x.street + ', ' + x.zip + ' ' + x.city + ' (' + x.country + ')'], ['Contact', x.contact || ''], ['E-mail', x.email], ['Téléphone', x.phone], ['Conditions de paiement', x.payment_terms]]),
						type === 'supplier'
							? dl([['Spécialité', x.specialty], ['Incoterm', x.incoterm], ['Délai moyen', x.lead_time_days + ' jours'], ['Fiabilité', x.reliability + ' %'], ['Devise', x.currency]])
							: dl([['Type', x.type], ['Priorité', x.priority], ['Transporteur habituel', x.carrier_code], ['Limite de crédit', 'CHF ' + chf(x.credit_limit)], ['Blocage livraison', Number(x.blocked) ? h('span', { class: 'lw-badge red', text: 'Bloqué (contentieux)' }) : 'Non']])), Number(x.blocked) ? 'yellow' : ''));
					if (type === 'customer' && x.rules) {
						var r = x.rules;
						append(out, card('Exigences de livraison', h('div', { class: 'lw-grid2' }, dl([['Jours de livraison', r.days_label], ['Plage horaire', r.window], ['Rendez-vous', r.appointment ? 'Obligatoire (annoncer la livraison)' : 'Non']]), dl([['Support de charge exigé', r.pallet_label], ['Véhicule / accès', r.vehicle_label || 'Aucune restriction'], ['Autres exigences', x.packing_req || '—']])), 'yellow'),
							h('div', { class: 'lw-actions' }, btn('Emballages consignés du client (MB58)', 'lw-btn-secondary', function () { go('MB58', { customer: x.customer_no }); })));
					}
				}).catch(function (e) { fail(out, e); });
			}
			if (p.id) { show(p.id); }
		};
	}
	tx('XK03', 'Afficher un fournisseur', 'Données de base', '', partnerScreen('supplier'));
	tx('XD03', 'Afficher un client', 'Données de base', '', partnerScreen('customer'));

	/* ════════════════════════ Achats ════════════════════════ */

	function docPicker(type, label, onPick, openOnly) {
		var box = h('div', null, loading());
		get(sp('/docs/' + type), { status: openOnly === false ? '' : 'open' }).then(function (rows) {
			clear(box);
			append(box, table(['N°', 'Partenaire', 'Date', 'Statut', 'Remarque'], rows.map(function (r) {
				var note = type === 'po' && Number(r.flag) ? h('span', { class: 'lw-badge red', text: 'Non annoncée' }) : (type === 'so' ? h('span', { class: 'lw-badge ' + (r.flag === 'A' ? 'red' : ''), text: 'Priorité ' + r.flag }) : null);
				return { cells: [h('span', { class: 'lw-mono', text: r.no }), r.partner, date(r.date), badge(r.status), [note, ' ', r.note || '']], onclick: function () { onPick(r.no); } };
			})));
		}).catch(function (e) { fail(box, e); });
		return card(label, box);
	}

	tx('ME21N', 'Créer une commande d\'achat', 'Achats', 'PUR', function (el, p) {
		var supplier = null;
		var lines = [];
		var msg = h('div');
		var info = h('div', { class: 'lw-small lw-muted' });
		var tbody = h('div');
		var fs = f4Field('Fournisseur', 'supplier', function (r) { supplier = r; clear(info); append(info, r.name + ' · ' + r.city + ' · ' + r.extra); });
		var note = inp({ placeholder: 'Remarque (facultatif)', maxlength: '255' });
		function draw() {
			clear(tbody);
			append(tbody, table(['Ligne', 'Article', 'Quantité', 'Prix CHF', 'Qté min.', 'Délai', ''], lines.map(function (l, i) {
				var q = num({ value: l.qty, 'aria-label': 'Quantité ligne ' + (i + 1) * 10, oninput: function () { l.qty = this.value; } });
				return { cells: [(i + 1) * 10, matCell(l.m), q, l.price !== null ? chf(l.price) : h('span', { class: 'lw-badge red', text: 'Pas de fiche' }), l.min !== null ? qty(l.min) : '', l.lead !== null ? l.lead + ' j' : '', btn('Retirer', 'lw-btn-danger lw-btn-sm', function () { lines.splice(i, 1); draw(); })] };
			}), [2, 3, 4]));
		}
		var fm = f4Field('Ajouter un article', 'material', function (r) { addLine(r.no, ''); });
		function addLine(no, q) {
			get(sp('/materials/' + encodeURIComponent(no))).then(function (m) {
				var rec = supplier ? m.suppliers.filter(function (x) { return Number(x.id) === Number(supplier.id); })[0] : null;
				lines.push({ m: m, qty: q || (rec ? rec.min_qty : ''), price: rec ? rec.price : null, min: rec ? rec.min_qty : null, lead: rec ? rec.lead_time_days : null });
				draw(); fm.input.value = '';
				if (!supplier && m.suppliers.length) { status('warn', 'Choisissez d\'abord le fournisseur : le prix et le délai viennent de sa fiche d\'info-achat.'); }
			}).catch(function (e) { status('error', e.message); });
		}
		var needs = h('div');
		append(el, msg, card('En-tête', h('div', { class: 'lw-form' }, fs, field('Remarque', note)), 'ink'), info,
			card('Lignes', h('div', null, h('div', { class: 'lw-form' }, fm), h('div', { style: { marginTop: '12px' } }, tbody), h('div', { class: 'lw-actions' },
				btn('Enregistrer la commande', 'lw-btn-key', function () {
					act(msg, post(sp('/po'), { supplier_id: supplier ? supplier.id : 0, note: note.value, lines: lines.map(function (l) { return { material_id: l.m.id, qty: l.qty }; }) }), function (d) { lines = []; draw(); clear(needs); append(needs, h('p', null, 'Commande créée : ', link(d.no, 'ME23N', { no: d.no }))); });
				}),
				btn('Afficher les besoins (sous le point de commande)', 'lw-btn-secondary', function () { go('ME2M'); })), needs)));
		draw();
		if (p.supplier_id) { get(sp('/partners/supplier/' + p.supplier_id)).then(function (x) { supplier = { id: x.id, no: x.supplier_no, name: x.name, city: x.city, extra: x.specialty }; fs.input.value = x.supplier_no; append(info, x.name + ' · ' + x.city); if (p.material) { addLine(p.material, p.qty || ''); } }); } else if (p.material) { addLine(p.material, p.qty || ''); }
	});

	function poView(no, out) {
		clear(out); append(out, loading());
		return get(sp('/po/' + encodeURIComponent(no))).then(function (po) {
			clear(out);
			var doc = card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Commande d\'achat ' + po.po_no }), h('div', null, badge(po.status), ' ', po.unannounced ? h('span', { class: 'lw-badge red', text: 'Livraison non annoncée' }) : null)),
				h('div', { class: 'lw-grid2' }, dl([['Fournisseur', po.supplier ? po.supplier.no + ' – ' + po.supplier.name : ''], ['Localité', po.supplier ? po.supplier.city + ' (' + po.supplier.country + ')' : ''], ['Incoterm', po.supplier ? po.supplier.incoterm : '']]), dl([['Date de commande', date(po.order_date)], ['Remarque', po.note]])),
				h('div', { style: { marginTop: '12px' } }, table(['Ligne', 'Article', 'Commandé', 'Reçu', 'Reste', 'Prix CHF', 'Livraison prévue'], po.items.map(function (i) {
					return { cells: [i.line_no, matCell(i.material), qty(i.qty) + ' ' + i.material.uom, qty(i.qty_received), qty(i.open), chf(i.price), date(i.delivery_date)] };
				}), [2, 3, 4, 5]))
			]);
			append(out, doc, h('div', { class: 'lw-actions lw-noprint' }, printBtn(doc), ['ENVOYEE', 'PARTIELLEMENT_RECUE'].indexOf(po.status) >= 0 ? btn('Réceptionner (MIGO)', 'lw-btn-key', function () { go('MIGO', { po: po.po_no }); }) : null));
			return po;
		}).catch(function (e) { fail(out, e); });
	}

	tx('ME23N', 'Afficher une commande d\'achat', 'Achats', 'PUR|GR', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: '4500000001', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { poView(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('N° de commande', input), btn('Afficher', 'lw-btn-primary', function () { poView(input.value.trim(), out); }))), out);
		if (p.no) { poView(p.no, out); } else { append(out, docPicker('po', 'Commandes d\'achat', function (no) { input.value = no; poView(no, out); }, false)); }
	});

	tx('ME2M', 'Besoins d\'achat et commandes ouvertes', 'Achats', 'PUR', function (el) {
		var box = h('div', null, loading());
		append(el, card('Articles sous le point de commande', box, 'yellow'), docPicker('po', 'Commandes d\'achat ouvertes', function (no) { go('ME23N', { no: no }); }));
		get(sp('/reorder')).then(function (rows) {
			clear(box);
			append(box, h('p', { class: 'lw-small lw-muted', text: 'Stock libre + commandes ouvertes < point de commande. Commandez le lot économique chez le fournisseur principal.' }));
			append(box, table(['Article', 'Stock libre', 'Commandes ouvertes', 'Point de commande', 'Lot économique', 'Fournisseur', ''], rows.map(function (r) {
				return { cells: [[h('span', { class: 'lw-mono', text: r.material }), ' ', r.designation], qty(r.stock), qty(r.open_po), qty(r.reorder), qty(r.lot_size), r.supplier ? r.supplier.no + ' ' + r.supplier.name : '', r.supplier ? btn('Commander', 'lw-btn-secondary lw-btn-sm', function () { go('ME21N', { material: r.material, qty: r.lot_size, supplier_id: r.supplier.id }); }) : ''] };
			}), [1, 2, 3, 4]));
		}).catch(function (e) { fail(box, e); });
	});

	/* ════════════════════════ Réception et mouvements (MIGO) ════════════════════════ */

	var LOCS = null;
	function locations() {
		if (LOCS) { return Promise.resolve(LOCS); }
		return get(sp('/map')).then(function (l) { LOCS = l; return l; });
	}

	function grForm(no, out) {
		clear(out); append(out, loading());
		Promise.all([get(sp('/po/' + encodeURIComponent(no))), locations()]).then(function (res) {
			var po = res[0], locs = res[1];
			clear(out);
			if (['ENVOYEE', 'PARTIELLEMENT_RECUE'].indexOf(po.status) < 0) { append(out, msgBox('info', 'La commande ' + po.po_no + ' est ' + (STATUS[po.status] || po.status).toLowerCase() + '.')); return; }
			var simple = S.session.mode === 'SIMPLE';
			var msg = h('div');
			var rows = [];
			// Bon de livraison du fournisseur = ce qui est réellement arrivé au quai.
			var paper = h('div', { class: 'lw-paper' }, h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h4', { text: 'Bon de livraison du fournisseur' }), btn('Étiquettes palettes (SSCC)', 'lw-btn-secondary lw-btn-sm', function () { go('HU03', { no: po.po_no }); })), h('p', { class: 'lw-small', text: (po.supplier ? po.supplier.name : '') + ' · réf. commande ' + po.po_no }),
				table(['Ligne', 'Article livré', 'Quantité livrée', 'Lot', 'DLC', 'Constat au déchargement'], po.items.map(function (i) {
					var dn = i.delivery_note;
					var wrong = dn.material && dn.material.id !== i.material.id;
					return { cells: [i.line_no, [wrong ? h('span', { class: 'lw-diff', text: '≠ ' }) : null, matCell(dn.material)], qty(dn.qty) + ' ' + i.material.uom, dn.batch || '—', dn.bbd ? date(dn.bbd) : '—', dn.damage ? h('span', { class: 'lw-damage', text: dn.damage }) : 'RAS'] };
				}), [2]));
			var statusOpts = [['LIBRE', 'Libre'], ['CONTROLE_QUALITE', 'Contrôle qualité'], ['BLOQUE', 'Bloqué']];
			var details = h('div', { class: 'lw-gr-details' });
			var ub = po.po_type === 'UB';
			var bulkPo = po.items.some(function (i) { return Number(i.material.bulk) === 1; });
			function lineRow(i, extra) {
				var r = { item_id: i.id };
				// Onglets du poste (SAP) : Qualité (certificat), Lot (date de fabrication, lot fournisseur), Emballage (nombre × quantité, TUS).
				r.ok = h('input', { type: 'checkbox', 'aria-label': 'Poste OK ligne ' + i.line_no });
				r.final = h('input', { type: 'checkbox', 'aria-label': 'Livraison finale ligne ' + i.line_no });
				r.cert = sel([['', '—'], ['O', 'Oui, certificat reçu'], ['N', 'Non, pas de certificat']], '', { 'aria-label': 'Certificat d\'analyse' });
				r.mfg = inp({ type: 'date', 'aria-label': 'Date de fabrication', max: S.session.sim_date });
				r.vbatch = inp({ class: 'lw-input code', placeholder: 'Lot du fournisseur', maxlength: '20', 'aria-label': 'Lot fournisseur', 'data-noscan': '1' });
				r.pcount = num({ placeholder: 'Nombre', step: '1', 'aria-label': 'Nombre d\'emballages' });
				r.pqty = num({ placeholder: 'Qté par unité', 'aria-label': 'Quantité par unité de stock' });
				r.tus = sel([['1', '1 – standard'], ['11', '11 – magasin grande hauteur'], ['12', '12 – magasin grande hauteur']], ub ? '11' : '1', { 'aria-label': 'TUS' });
				append(details, h('details', { class: 'lw-gr-item' + (Number(i.material.cert) === 1 ? ' need' : '') },
					h('summary', null, h('strong', { text: 'Poste ' + i.line_no + (extra ? ' (bis)' : '') }), ' ', i.material.no + ' ' + i.material.designation, Number(i.material.cert) === 1 ? h('span', { class: 'lw-badge yellow', text: 'Certificat et date de fabrication obligatoires' }) : null),
					h('div', { class: 'lw-form' },
						field('Qualité : analyse de certificat', r.cert),
						field('Lot : date de fabrication', r.mfg, 'à défaut, la date du jour'),
						field('Lot : lot fournisseur', r.vbatch, 'champ « Lot » vide : lot interne attribué'),
						field('Emballage : nombre', r.pcount, Number(i.material.bulk) ? 'nombre de cuves' : 'ex. 12 palettes'),
						field('Emballage : qté par unité', r.pqty, 'ex. 420 kg'),
						field('Emballage : TUS', r.tus, 'toujours 1, sauf stockage au MGH'),
						h('label', { class: 'lw-field' }, h('span', { text: 'Livraison finale' }), h('span', { class: 'lw-inline' }, r.final, ' le solde n\'est plus attendu')))));
				r.qty = num({ 'aria-label': 'Quantité reçue ligne ' + i.line_no, placeholder: '0' });
				r.status = sel(statusOpts, 'LIBRE', { 'aria-label': 'Statut' });
				r.batch = i.material.batch_managed ? inp({ class: 'lw-input code', placeholder: 'Lot', 'aria-label': 'Lot' }) : null;
				r.bbd = i.material.shelf_life ? inp({ type: 'date', 'aria-label': 'DLC' }) : null;
				r.reason = inp({ placeholder: 'Réserve / motif', maxlength: '120', 'aria-label': 'Motif' });
				r.loc = simple ? sel([['', '— magasin —']].concat(locs.filter(function (l) { return !l.transit; }).map(function (l) { return [l.id, l.code + ' – ' + l.name]; })), '', { 'aria-label': 'Magasin' }) : null;
				rows.push(r);
				return { cells: [i.line_no + (extra ? ' (bis)' : ''), matCell(i.material), qty(i.open), r.qty, r.status, r.batch || '—', r.bbd || '—'].concat(simple ? [r.loc] : []).concat([r.reason, h('label', { class: 'lw-okbox', title: 'Poste OK : contrôle terminé' }, r.ok, ' OK'), extra ? '' : btn('+ ligne', 'lw-btn-secondary lw-btn-sm', function () { addSplit(i); }, { title: 'Répartir la ligne (ex. une partie bloquée)' })]) };
			}
			var tableBox = h('div');
			var defs = po.items.filter(function (i) { return i.open > 0 || true; }).map(function (i) { return lineRow(i, false); });
			var heads = ['Ligne', 'Article commandé', 'Reste à recevoir', 'Qté reçue', 'Statut', 'Lot', 'DLC', simple ? 'Magasin' : null, 'Réserve / motif', 'Poste OK', ''].filter(function (x) { return x !== null; });
			var transport = bulkPo ? sel([['', '— vrac : moyen de transport —'], ['CAMION', 'Camion-citerne'], ['WAGON', 'Wagon-citerne']], po.transport || '', { 'aria-label': 'Moyen de transport du vrac' }) : null;
			function drawTable() { clear(tableBox); append(tableBox, table(heads, defs, [2])); }
			function addSplit(i) { defs.push(lineRow(i, true)); drawTable(); }
			drawTable();
			var over = h('input', { type: 'checkbox' });
			var text = inp({ placeholder: 'N° du bon de livraison fournisseur', maxlength: '255' });
			if (po.supplier && ['CH', 'LI'].indexOf(po.supplier.country) < 0) {
				append(out, h('div', { class: 'lw-cmr-note' }, h('div', null, h('strong', { text: 'Arrivage international (' + po.supplier.country + ')' }), h('p', { class: 'lw-small', text: 'La marchandise voyage avec une lettre de voiture CMR. Contrôlez-la et signez la case 24 avant de saisir l\'entrée.' })),
					btn('Ouvrir la lettre de voiture CMR', 'lw-btn-secondary', function () { go('ZCMR', { no: po.po_no }); })));
			}
			append(out, msg, h('div', { class: 'lw-grid2' }, paper, card('Contrôle', h('ul', { class: 'lw-small', style: { paddingLeft: '18px', margin: 0 } },
				h('li', { text: 'Comparez article, quantité, lot et DLC du bon de livraison avec la commande.' }),
				h('li', { text: 'Ne saisissez que ce qui est réellement livré et conforme.' }),
				h('li', { text: 'Avarie : réserve + stock bloqué, ou retour au fournisseur (onglet Retour fournisseur).' }),
				h('li', { text: simple ? 'Gestion simple : choisissez le magasin de destination adapté au profil de l\'article.' : 'Gestion détaillée : la marchandise arrive au quai (magasin 100), puis un ordre d\'entreposage est créé.' })), 'ink')),
				card('Entrée de marchandises – mouvement 101' + (ub ? ' (transfert entre sites, bulletin ' + po.ib_dlv_no + ')' : ''), h('div', null, tableBox,
					h('h4', { class: 'lw-gr-tabs-title', text: 'Onglets des postes (contrôlez chaque onglet, puis « Poste OK »)' }), details,
					h('div', { class: 'lw-form', style: { marginTop: '12px' } }, field('Texte d\'en-tête', text), transport ? field('Arrivage vrac par', transport, 'perte de citerne : camion 40 kg, wagon 100 kg') : null, h('label', { class: 'lw-field' }, h('span', { text: 'Surlivraison' }), h('span', { class: 'lw-inline' }, over, ' Accepter au-delà de la tolérance (motif obligatoire)'))),
					h('div', { class: 'lw-actions' }, btn('Enregistrer l\'entrée', 'lw-btn-key', function () {
						var filled = rows.filter(function (r) { return r.qty.value !== '' && Number(r.qty.value) > 0; });
						var notOk = filled.filter(function (r) { return !r.ok.checked; });
						if (notOk.length) { clear(msg); append(msg, msgBox('warn', 'Contrôlez chaque onglet du poste puis cochez « Poste OK » avant d\'enregistrer (' + notOk.length + ' poste' + (notOk.length > 1 ? 's' : '') + ').')); msg.scrollIntoView({ block: 'nearest' }); return; }
						var lines = filled.map(function (r) {
							return { item_id: r.item_id, qty: r.qty.value, status: r.status.value, batch: r.batch ? r.batch.value : '', bbd: r.bbd ? r.bbd.value : '', reason: r.reason.value, location_id: r.loc ? r.loc.value : 0, cert: r.cert.value, mfg_date: r.mfg.value, vendor_batch: r.vbatch.value, pack_count: r.pcount.value, pack_qty: r.pqty.value, tus: r.tus.value, final: r.final.checked ? 1 : 0 };
						});
						act(msg, post(sp('/gr'), { po_no: po.po_no, lines: lines, text: text.value, accept_over: over.checked, transport: transport ? transport.value : '' }), function (d) {
							clear(out); append(out, msgBox('ok', 'Entrée enregistrée : document ' + d.doc_no + '.'));
							if (d.messages && d.messages.length) { append(out, msgBox('warn', 'Attention', d.messages)); }
							if (d.to_no) { append(out, h('div', { class: 'lw-actions' }, btn('Ranger maintenant (OT ' + d.to_no + ')', 'lw-btn-key', function () { go('LT12', { no: d.to_no }); }))); }
							refreshSession();
						});
					})))));
		}).catch(function (e) { fail(out, e); });
	}

	/** Sélecteur de lignes de stock (filtre article / emplacement). */
	function stockPicker(onPick, preset) {
		var out = h('div');
		var mat = inp({ placeholder: 'Article (n° ou désignation)', value: (preset && preset.material) || '' });
		var bin = inp({ class: 'lw-input code', placeholder: S.session.mode === 'SIMPLE' ? 'Magasin' : 'Emplacement (début)', value: (preset && preset.bin) || '' });
		var st = sel([['', 'Tous statuts'], ['LIBRE', 'Libre'], ['CONTROLE_QUALITE', 'Contrôle qualité'], ['BLOQUE', 'Bloqué']], (preset && preset.status) || '');
		function run() {
			clear(out); append(out, loading());
			var simple = S.session.mode === 'SIMPLE';
			get(sp('/stock'), { material: mat.value.trim(), bin: simple ? '' : bin.value.trim(), location: simple ? bin.value.trim() : '', status: st.value }).then(function (r) {
				clear(out);
				append(out, table(['Article', 'Magasin', 'Emplacement', 'Lot', 'DLC', 'Statut', 'Quantité'], r.rows.map(function (x) {
					return { cls: x.expired ? 'expired' : '', cells: [[h('span', { class: 'lw-mono', text: x.material }), ' ', x.designation], x.location, h('span', { class: 'lw-mono', text: x.bin }), x.batch, x.bbd ? date(x.bbd) + (x.expired ? ' (périmé)' : '') : '', badge(x.status), qty(x.qty) + ' ' + x.uom], onclick: function () { onPick(x); } };
				}), [6]));
				if (r.total > r.rows.length) { append(out, h('p', { class: 'lw-small lw-muted', text: r.total + ' lignes : affinez la recherche.' })); }
			}).catch(function (e) { fail(out, e); });
		}
		[mat, bin].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { run(); } }); });
		var wrap = h('div', null, h('div', { class: 'lw-form' }, field('Article', mat), field(S.session.mode === 'SIMPLE' ? 'Magasin' : 'Emplacement', bin), field('Statut', st), btn('Rechercher', 'lw-btn-primary', run)), h('div', { style: { marginTop: '10px' } }, out));
		if (preset && (preset.material || preset.bin)) { run(); }
		return wrap;
	}
	function stockSummary(x) { return h('div', { class: 'lw-paper', style: { marginBottom: '10px' } }, h('strong', { text: x.material + ' – ' + x.designation }), h('div', { class: 'lw-small', text: 'Magasin ' + x.location + (x.bin ? ' · emplacement ' + x.bin : '') + (x.batch ? ' · lot ' + x.batch : '') + (x.bbd ? ' · DLC ' + date(x.bbd) : '') + ' · ' + (STATUS[x.status] || x.status) + ' · ' + qty(x.qty) + ' ' + x.uom })); }

	tx('MIGO', 'Mouvements de marchandises', 'Réception et stock', 'GR|INV', function (el, p) {
		var simple = S.session.mode === 'SIMPLE';
		var tabs = {};
		tabs['Entrée sur commande (101)'] = function () {
			var out = h('div');
			var input = inp({ class: 'lw-input code', placeholder: '4500000001', value: p.po || '' });
			input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { grForm(input.value.trim(), out); } });
			var wrap = h('div', null, card(null, h('div', { class: 'lw-inline' }, field('Commande d\'achat', input), btn('Charger', 'lw-btn-primary', function () { grForm(input.value.trim(), out); }))), out);
			if (p.po) { grForm(p.po, out); } else { append(out, docPicker('po', 'Commandes à réceptionner', function (no) { input.value = no; grForm(no, out); })); }
			return wrap;
		};
		function rowAction(title, build) {
			return function () {
				var area = h('div');
				var picker = stockPicker(function (x) { clear(area); append(area, stockSummary(x), build(x)); area.scrollIntoView({ block: 'nearest' }); }, { material: p.material || '' });
				return h('div', null, card(title, picker), area);
			};
		}
		tabs['Transfert'] = rowAction('Choisir la ligne de stock à transférer', function (x) {
			var msg = h('div');
			var q = num({ value: x.qty });
			var dst = simple ? sel([['', '— magasin —']].concat((LOCS || []).filter(function (l) { return !l.transit; }).map(function (l) { return [l.code, l.code + ' – ' + l.name]; })), '') : inp({ class: 'lw-input code', placeholder: '110-A01-01-00-G' });
			if (simple && !LOCS) { locations().then(function (l) { clear(dst); [['', '— magasin —']].concat(l.filter(function (z) { return !z.transit; }).map(function (z) { return [z.code, z.code + ' – ' + z.name]; })).forEach(function (o) { dst.appendChild(h('option', { value: o[0], text: o[1] })); }); }); }
			return card('Transfert (' + (simple ? '311' : '999 / 311') + ')', h('div', null, msg, h('div', { class: 'lw-form' }, field('Quantité', q), field(simple ? 'Magasin de destination' : 'Emplacement de destination', dst)), h('div', { class: 'lw-actions' }, btn('Transférer', 'lw-btn-key', function () {
				act(msg, post(sp('/stock/transfer'), { stock_id: x.id, qty: q.value, location: simple ? dst.value : '', bin: simple ? '' : dst.value }));
			}))));
		});
		tabs['Changement de statut'] = rowAction('Choisir la ligne de stock', function (x) {
			var msg = h('div');
			var q = num({ value: x.qty });
			var opts = x.status === 'LIBRE' ? [['CONTROLE_QUALITE', 'Libre → contrôle qualité (322)'], ['BLOQUE', 'Libre → bloqué (344)']] : x.status === 'CONTROLE_QUALITE' ? [['LIBRE', 'Contrôle qualité → libre (321)']] : [['LIBRE', 'Bloqué → libre (343)']];
			var to = sel(opts, opts[0][0]);
			var reason = inp({ placeholder: 'Motif', maxlength: '120' });
			return card('Changement de statut', h('div', null, msg, h('div', { class: 'lw-form' }, field('Quantité', q), field('Changement', to), field('Motif', reason)), h('div', { class: 'lw-actions' }, btn('Enregistrer', 'lw-btn-key', function () {
				act(msg, post(sp('/stock/status'), { stock_id: x.id, qty: q.value, status: to.value, reason: reason.value }));
			}))));
		});
		tabs['Sortie diverse'] = rowAction('Choisir la ligne de stock', function (x) {
			var msg = h('div');
			var q = num({ value: '' });
			var mv = sel([['551', 'Mise au rebut (551)'], ['201', 'Consommation centre de coûts (201)'], ['333', 'Prélèvement d\'échantillon (333)']], '551');
			var reason = inp({ placeholder: 'Motif obligatoire', maxlength: '120' });
			return card('Sortie de stock', h('div', null, msg, h('div', { class: 'lw-form' }, field('Quantité', q), field('Type de sortie', mv), field('Motif', reason)), h('div', { class: 'lw-actions' }, btn('Enregistrer la sortie', 'lw-btn-danger', function () {
				act(msg, post(sp('/stock/issue'), { stock_id: x.id, qty: q.value, movement: mv.value, reason: reason.value }));
			}))));
		});
		tabs['Retour fournisseur (122)'] = function () {
			var out = h('div');
			var input = inp({ class: 'lw-input code', placeholder: '4500000001' });
			function load() {
				clear(out);
				get(sp('/po/' + encodeURIComponent(input.value.trim()))).then(function (po) {
					var msg = h('div');
					var item = sel(po.items.map(function (i) { return [i.id, i.line_no + ' – ' + i.material.no + ' ' + i.material.designation + ' (reçu ' + qty(i.qty_received) + ')']; }), '');
					var picked = null;
					var pickBox = h('div');
					var q = num();
					var reason = inp({ placeholder: 'Motif du retour', maxlength: '120' });
					function pick() { clear(pickBox); var it = po.items.filter(function (i) { return String(i.id) === item.value; })[0]; append(pickBox, stockPicker(function (x) { picked = x; clear(pickBox); append(pickBox, stockSummary(x)); }, { material: it ? it.material.no : '' })); }
					item.addEventListener('change', pick);
					append(out, card('Retour au fournisseur', h('div', null, msg, h('div', { class: 'lw-form' }, field('Ligne de commande', item), field('Quantité', q), field('Motif', reason)), h('h4', { text: 'Stock à retourner' }), pickBox, h('div', { class: 'lw-actions' }, btn('Enregistrer le retour', 'lw-btn-danger', function () {
						if (!picked) { status('error', 'Choisissez la ligne de stock à retourner.'); return; }
						act(msg, post(sp('/return-supplier'), { po_no: po.po_no, item_id: item.value, stock_id: picked.id, qty: q.value, reason: reason.value }));
					})))));
					pick();
				}).catch(function (e) { fail(out, e); });
			}
			return h('div', null, card(null, h('div', { class: 'lw-inline' }, field('Commande d\'achat', input), btn('Charger', 'lw-btn-primary', load))), out);
		};
		append(el, tabbed(tabs));
	});

	/* ════════════════════════ Consultations de stock ════════════════════════ */

	/* ════════════════════════ Lettre de voiture CMR ════════════════════════ */

	var CMR_COPIES = [
		{ n: 1, cls: 'red', fr: 'Exemplaire de l\'expéditeur', de: 'Exemplar für den Absender' },
		{ n: 2, cls: 'blue', fr: 'Exemplaire du destinataire', de: 'Exemplar für den Empfänger' },
		{ n: 3, cls: 'green', fr: 'Exemplaire du transporteur', de: 'Exemplar für den Frachtführer' },
		{ n: 4, cls: 'black', fr: 'Exemplaire supplémentaire', de: 'Zusätzliches Exemplar' }
	];

	function cmrBox(num, label, content, cls) {
		return h('div', { class: 'cmr-box ' + (cls || '') }, h('div', { class: 'cmr-lbl' }, h('span', { class: 'cmr-num', text: String(num) }), label), h('div', { class: 'cmr-val' }, content));
	}
	function cmrLines(arr) { return arr.filter(function (x) { return x !== '' && x !== null && x !== undefined; }).map(function (x) { return h('div', { text: x }); }); }
	function cmrStamp(lines, extra) { return h('div', { class: 'cmr-stamp' + (extra ? ' ' + extra : '') }, lines.map(function (x, i) { return h('div', { class: i === 0 ? 'b' : '', text: x }); })); }

	/** Un exemplaire de la lettre de voiture (couleur selon l'exemplaire). */
	function cmrCopy(d, copy) {
		var sig = d.signature && copy.n > 1 ? d.signature : null;
		var goods = h('table', { class: 'cmr-goods' },
			h('thead', null, h('tr', null,
				h('th', null, h('span', { class: 'cmr-num', text: '6' }), 'Marques et numéros'),
				h('th', { class: 'num' }, h('span', { class: 'cmr-num', text: '7' }), 'Nombre des colis'),
				h('th', null, h('span', { class: 'cmr-num', text: '8' }), 'Mode d\'emballage'),
				h('th', null, h('span', { class: 'cmr-num', text: '9' }), 'Nature de la marchandise'),
				h('th', null, h('span', { class: 'cmr-num', text: '10' }), 'N° statistique'),
				h('th', { class: 'num' }, h('span', { class: 'cmr-num', text: '11' }), 'Poids brut, kg'),
				h('th', { class: 'num' }, h('span', { class: 'cmr-num', text: '12' }), 'Cubage m³'))),
			h('tbody', null, d.lines.map(function (l) {
				return h('tr', null, h('td', { text: l.marks }), h('td', { class: 'num', text: qty(l.count) }), h('td', { text: l.packing }),
					h('td', null, h('div', { text: l.nature }), h('div', { class: 'cmr-sub', text: 'Art. ' + l.article + ' · ' + qty(l.qty) + ' ' + l.uom })),
					h('td', { text: '' }), h('td', { class: 'num', text: qty(l.weight) }), h('td', { class: 'num', text: qty(l.volume) }));
			})),
			h('tfoot', null, h('tr', null, h('td', { text: 'Total' }), h('td', { class: 'num', text: qty(d.total.count) }), h('td', { colspan: '3' }), h('td', { class: 'num', text: qty(d.total.weight) }), h('td', { class: 'num', text: qty(d.total.volume) }))));
		var check = function (on, label) { return h('span', { class: 'cmr-check' }, h('b', { text: on ? '✕' : '' }), label); };
		var sigBox = sig
			? [cmrStamp([d.consignee[0], 'Réception marchandises', d.signature.place]), h('div', { class: 'cmr-write', text: date(sig.at) + ' ' + String(sig.at).slice(11, 16) + ' · ' + sig.by }),
				sig.reserves ? h('div', { class: 'cmr-reserve', text: 'Réserves : ' + sig.reserves }) : h('div', { class: 'cmr-write', text: 'Reçu sans réserve.' })]
			: h('div', { class: 'cmr-empty', text: copy.n === 1 ? '' : 'À remplir par le destinataire à la réception' });
		return h('article', { class: 'lw-cmr ' + copy.cls, 'aria-label': 'CMR exemplaire ' + copy.n },
			h('header', { class: 'cmr-head' },
				h('div', { class: 'cmr-copy' }, h('strong', { text: String(copy.n) }), h('div', null, h('div', { text: copy.fr }), h('div', { class: 'cmr-de', text: copy.de }))),
				h('div', { class: 'cmr-title' }, h('div', { text: 'LETTRE DE VOITURE INTERNATIONALE' }), h('div', { class: 'cmr-de', text: 'Internationaler Frachtbrief' })),
				h('div', { class: 'cmr-logo' }, h('strong', { text: 'CMR' }), h('span', { text: 'N° ' + d.no }))),
			h('p', { class: 'cmr-conv', text: 'Ce transport est soumis, nonobstant toute clause contraire, à la Convention relative au contrat de transport international de marchandises par route (CMR).' }),
			h('div', { class: 'cmr-grid' },
				cmrBox(1, 'Expéditeur (nom, adresse, pays)', cmrLines(d.sender)),
				cmrBox(16, 'Transporteur (nom, adresse, pays)', cmrLines(d.carrier.concat(['Chauffeur : ' + d.driver]))),
				cmrBox(2, 'Destinataire (nom, adresse, pays)', cmrLines(d.consignee)),
				cmrBox(17, 'Transporteurs successifs', ''),
				cmrBox(3, 'Lieu prévu pour la livraison de la marchandise', cmrLines([d.delivery])),
				cmrBox(18, 'Réserves et observations du transporteur', ''),
				cmrBox(4, 'Lieu et date de la prise en charge de la marchandise', cmrLines([d.pickup.place, date(d.pickup.date)])),
				cmrBox(19, 'Conventions particulières', cmrLines([d.agreements])),
				cmrBox(5, 'Documents annexés', cmrLines([d.documents]), 'wide')),
			goods,
			h('div', { class: 'cmr-grid' },
				cmrBox(13, 'Instructions de l\'expéditeur (formalités douanières et autres)', h('div', { class: 'lw-pre', text: d.instructions })),
				cmrBox(20, 'À payer par', cmrLines(['Prix de transport : ' + d.payer, 'Frais de douane : Destinataire'])),
				cmrBox(14, 'Prescriptions d\'affranchissement', [check(d.payment === 'Franco', 'Franco'), check(d.payment !== 'Franco', 'Non franco')]),
				cmrBox(15, 'Remboursement', ''),
				cmrBox(21, 'Établie à', cmrLines([d.established.place + ', le ' + date(d.established.date)])),
				cmrBox('', 'Immatriculation tracteur / remorque', cmrLines([d.plates]))),
			h('div', { class: 'cmr-sign' },
				cmrBox(22, 'Signature et timbre de l\'expéditeur', [cmrStamp(d.sender.slice(0, 3)), h('div', { class: 'cmr-hand', text: 'p.o. ' + d.sender[0].split(' ')[0] })]),
				cmrBox(23, 'Signature et timbre du transporteur', [cmrStamp(d.carrier.slice(0, 3)), h('div', { class: 'cmr-hand', text: d.driver })]),
				cmrBox(24, 'Marchandises reçues · lieu, date, signature et timbre du destinataire', sigBox, sig ? 'signed' : '')));
	}

	function cmrPrint(d) {
		var w = window.open('', '_blank');
		if (!w) { status('error', 'Le navigateur a bloqué la fenêtre d\'impression : autorisez les fenêtres pour ce site.'); return; }
		var holder = h('div', { class: 'logierp-app lw-cmr-set' }, CMR_COPIES.map(function (c) { return cmrCopy(d, c); }));
		w.document.open();
		w.document.write('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>CMR ' + d.no.replace(/[<>&"]/g, '') + '</title><link rel="stylesheet" href="' + C.assets.replace(/"/g, '') + 'css/app.css"></head><body class="lw-cmr-print">' + holder.outerHTML + '</body></html>');
		w.document.close();
		w.addEventListener('load', function () { w.focus(); w.print(); });
	}

	tx('ZCMR', 'Lettres de voiture CMR (arrivages)', 'Réception et stock', 'GR', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: '4500000001', value: p.no || '' });
		function open(no) { if (no) { go('ZCMR', { no: no }); } }
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(input.value.trim()); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Commande d\'achat', input), btn('Afficher la CMR', 'lw-btn-primary', function () { open(input.value.trim()); }))), out);
		if (!p.no) {
			append(out, msgBox('info', 'Une marchandise qui vient de l\'étranger voyage avec une lettre de voiture internationale CMR. Le chauffeur vous remet les exemplaires 2 (bleu, pour vous), 3 (vert, pour lui) et 4. Contrôlez-la avant de décharger, puis signez la case 24.'));
			var box = h('div', null, loading());
			append(out, card('Arrivages internationaux', box));
			get(sp('/cmr')).then(function (rows) {
				clear(box);
				if (!rows.length) { append(box, h('p', { class: 'lw-muted', text: 'Aucune commande auprès d\'un fournisseur étranger dans cette session.' })); return; }
				append(box, table(['Commande', 'N° CMR', 'Expéditeur', 'Provenance', 'Date commande', 'Statut', 'Case 24'], rows.map(function (r) {
					return { cells: [h('span', { class: 'lw-mono', text: r.no }), h('span', { class: 'lw-mono', text: r.cmr_no }), r.partner, r.from, date(r.date), badge(r.status), r.signed ? h('span', { class: 'lw-badge LIBRE', text: 'Signée' }) : h('span', { class: 'lw-badge yellow', text: 'À signer' })], onclick: function () { open(r.no); } };
				})));
			}).catch(function (e) { fail(box, e); });
			return;
		}
		append(out, loading());
		get(sp('/cmr/' + encodeURIComponent(p.no))).then(function (d) {
			clear(out);
			var current = CMR_COPIES[1];
			var view = h('div', { class: 'lw-cmr-view' });
			var bar = h('div', { class: 'lw-cmr-tabs', role: 'tablist' }, CMR_COPIES.map(function (c) {
				return h('button', { type: 'button', class: 'lw-cmr-tab ' + c.cls + (c === current ? ' active' : ''), role: 'tab', 'aria-selected': c === current ? 'true' : 'false', onclick: function () { current = c; draw(); } }, h('b', { text: String(c.n) }), c.fr);
			}));
			function draw() {
				Array.prototype.forEach.call(bar.children, function (b, i) { var on = CMR_COPIES[i] === current; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
				clear(view); append(view, cmrCopy(d, current));
			}
			var actions = h('div', { class: 'lw-actions' },
				btn('Saisir l\'entrée de marchandises', 'lw-btn-primary', function () { go('MIGO', { po: d.po_no }); }),
				btn('Imprimer les 4 exemplaires', 'lw-btn-secondary', function () { cmrPrint(d); }));
			append(out, card('Lettre de voiture ' + d.no + ' · commande ' + d.po_no, h('div', null,
				h('ul', { class: 'lw-small', style: { paddingLeft: '18px', margin: '0 0 12px' } },
					h('li', { text: 'Comparez la CMR avec la commande et le bon de livraison : expéditeur, nombre de colis, poids, marchandise.' }),
					h('li', { text: 'Contrôlez l\'état de la marchandise et des emballages pendant le déchargement.' }),
					h('li', { text: 'Signez la case 24 : notez une réserve précise (quoi, combien, quel dommage) si quelque chose ne va pas. Sans réserve, la livraison est réputée conforme.' })),
				actions)));
			if (d.can_sign) {
				var msg = h('div');
				var res = h('textarea', { class: 'lw-input', rows: '3', maxlength: '255', placeholder: 'Ex. : pos. 20, 1 carton enfoncé, 3 pièces cassées. Constaté en présence du chauffeur.', 'aria-label': 'Réserves' });
				append(out, card('Case 24 · réception de la marchandise', h('div', null, msg,
					field('Réserves du destinataire (laisser vide si tout est conforme)', res),
					h('div', { class: 'lw-actions' },
						btn('Signer sans réserve', 'lw-btn-secondary', function () {
							if (res.value.trim() !== '') { clear(msg); append(msg, msgBox('warn', 'Vous avez saisi des réserves : utilisez « Signer avec réserves ».')); return; }
							act(msg, post(sp('/cmr/' + encodeURIComponent(d.po_no) + '/sign'), { reserves: '' }), function () { refreshSession(); renderSession(S.sid, 'ZCMR', { no: d.po_no }); });
						}),
						btn('Signer avec réserves', 'lw-btn-key', function () {
							if (res.value.trim().length < 5) { clear(msg); append(msg, msgBox('warn', 'Décrivez la réserve : position, quantité et dommage constaté.')); res.focus(); return; }
							act(msg, post(sp('/cmr/' + encodeURIComponent(d.po_no) + '/sign'), { reserves: res.value.trim() }), function () { refreshSession(); renderSession(S.sid, 'ZCMR', { no: d.po_no }); });
						}))), 'yellow'));
			} else if (d.signature) {
				append(out, msgBox('ok', 'Case 24 signée le ' + date(d.signature.at) + (d.signature.reserves ? ', avec réserves.' : ', sans réserve.')));
			}
			append(out, bar, view);
			draw();
		}).catch(function (e) { fail(out, e); });
	});

	tx('MMBE', 'Aperçu du stock d\'un article', 'Réception et stock', '', function (el, p) {
		var out = h('div');
		var f = f4Field('Article', 'material', function (r) { show(r.no); });
		append(el, card(null, h('div', { class: 'lw-form' }, f)), out);
		function show(no) {
			clear(out); append(out, loading());
			Promise.all([get(sp('/materials/' + encodeURIComponent(no))), get(sp('/stock'), { material: no })]).then(function (r) {
				var m = r[0], st = r[1];
				clear(out);
				append(out, card(m.no + ' – ' + m.designation, h('div', null, h('div', { class: 'lw-grid3' }, dl([['Libre', qty(m.stock.LIBRE) + ' ' + m.uom], ['Disponible', qty(m.stock.disponible)]]), dl([['Contrôle qualité', qty(m.stock.CONTROLE_QUALITE)], ['Bloqué', qty(m.stock.BLOQUE)]]), dl([['Profil', m.profile_label], ['Prélèvement', m.batch_managed && m.shelf_life ? 'FEFO' : 'FIFO']])), h('div', { style: { marginTop: '12px' } },
					table(['Magasin', 'Emplacement', 'Lot', 'DLC', 'Entrée', 'Statut', 'Quantité', 'Valeur CHF'], st.rows.map(function (x) { return { cls: x.expired ? 'expired' : '', cells: [x.location, h('span', { class: 'lw-mono', text: x.bin }), x.batch, date(x.bbd) + (x.expired ? ' (périmé)' : ''), date(x.gr_date), badge(x.status), qty(x.qty), chf(x.value)] }; }), [6, 7])))));
			}).catch(function (e) { fail(out, e); });
		}
		if (p.material) { f.input.value = p.material; show(p.material); }
	});

	tx('MB52', 'Stock par magasin', 'Réception et stock', '', function (el, p) {
		var out = h('div');
		var page = 1;
		var loc = sel([['', 'Tous les magasins']], p.location || '');
		locations().then(function (l) { l.forEach(function (z) { var o = h('option', { value: z.code, text: z.code + ' – ' + z.name }); if (z.code === p.location) { o.selected = true; } loc.appendChild(o); }); });
		var st = sel([['', 'Tous statuts'], ['LIBRE', 'Libre'], ['CONTROLE_QUALITE', 'Contrôle qualité'], ['BLOQUE', 'Bloqué']], '');
		var exp = sel([['', '—'], ['7', 'DLC dans 7 jours ou dépassée'], ['30', 'DLC dans 30 jours ou dépassée']], '');
		var mat = inp({ placeholder: 'Article' });
		function run() {
			clear(out); append(out, loading());
			get(sp('/stock'), { location: loc.value, status: st.value, expiring: exp.value, material: mat.value.trim(), page: page }).then(function (r) {
				clear(out);
				append(out, table(['Article', 'Désignation', 'Magasin', 'Emplacement', 'Lot', 'DLC', 'Statut', 'Quantité', 'Valeur CHF'], r.rows.map(function (x) { return { cls: x.expired ? 'expired' : '', cells: [h('span', { class: 'lw-mono', text: x.material }), x.designation, x.location, h('span', { class: 'lw-mono', text: x.bin }), x.batch, date(x.bbd), badge(x.status), qty(x.qty) + ' ' + x.uom, chf(x.value)] }; }), [7, 8]));
				append(out, h('div', { class: 'lw-pager' }, 'Page ' + r.page + ' / ' + Math.max(1, r.pages) + ' · ' + r.total + ' lignes', r.page > 1 ? btn('‹', 'lw-btn-secondary lw-btn-sm', function () { page--; run(); }) : null, r.page < r.pages ? btn('›', 'lw-btn-secondary lw-btn-sm', function () { page++; run(); }) : null));
			}).catch(function (e) { fail(out, e); });
		}
		append(el, card(null, h('div', { class: 'lw-form' }, field('Magasin', loc), field('Statut', st), field('Péremption', exp), field('Article', mat), btn('Afficher', 'lw-btn-primary', function () { page = 1; run(); }))), out);
		run();
	});

	tx('MB51', 'Liste des documents de mouvement', 'Réception et stock', '', function (el) {
		var out = h('div');
		var mat = inp({ class: 'lw-input code', placeholder: 'Article' });
		var mv = inp({ class: 'lw-input code', placeholder: 'ex. 101', maxlength: '3', inputmode: 'numeric', 'data-noscan': '1' });
		function run() {
			clear(out); append(out, loading());
			get(sp('/mdocs'), { material: mat.value.trim(), movement: mv.value.trim() }).then(function (r) {
				clear(out);
				append(out, table(['Document', 'Date', 'Mvt', 'Article', 'Magasin', 'Emplacement', 'Lot', 'Statut', 'Quantité', 'Valeur CHF', 'Référence', 'Motif'], r.rows.map(function (x) { return { cells: [h('span', { class: 'lw-mono', text: x.doc_no }), date(x.posting_date), h('span', { title: x.movement_label, class: 'lw-badge ' + (x.qty < 0 ? 'red' : 'green'), text: x.movement_type }), [h('span', { class: 'lw-mono', text: x.material_no }), ' ', x.designation], x.location, h('span', { class: 'lw-mono', text: x.bin || '' }), x.batch, STATUS[x.stock_status] || x.stock_status, qty(x.qty) + ' ' + x.base_uom, chf(x.value), x.ref_no, x.reason] }; }), [8, 9]));
			}).catch(function (e) { fail(out, e); });
		}
		append(el, card(null, h('div', { class: 'lw-form' }, field('Article', mat), field('Code mouvement', mv), btn('Afficher', 'lw-btn-primary', run))), out);
		run();
	});

	/* ════════════════════════ Entrepôt ════════════════════════ */

	function toList(out, onPick, type) {
		var box = h('div', null, loading());
		get(sp('/to'), { type: type || '' }).then(function (rows) {
			clear(box);
			append(box, table(['Ordre de transfert', 'Type', 'Référence', 'Secteur / préparateur', 'Lignes', 'Créé le'], rows.map(function (r) {
				var lbl = TO_LABEL[r.type] || r.type;
				return { cells: [h('span', { class: 'lw-mono', text: r.to_no }), lbl, r.ref_no ? [h('span', { class: 'lw-mono', text: r.ref_no }), r.customer ? h('div', { class: 'lw-small lw-muted', text: r.customer + (r.priority ? ' · prio. ' + r.priority : '') }) : null] : '—', r.picker ? [r.sector ? h('span', { class: 'lw-chip sector', text: r.sector }) : null, ' ', r.picker] : '—', r.n_lines, new Date(r.created_at.replace(' ', 'T') + 'Z').toLocaleTimeString('fr-CH')], onclick: function () { onPick(r.to_no); } };
			}), [4]));
			if (!rows.length) { append(box, h('p', { class: 'lw-muted', text: 'Aucun ordre de transfert ouvert.' })); }
		}).catch(function (e) { fail(box, e); });
		append(out, card('Ordres de transfert ouverts', box));
	}

	function toForm(no, out) {
		clear(out); append(out, loading());
		get(sp('/to/' + encodeURIComponent(no))).then(function (to) {
			clear(out);
			var msg = h('div');
			var pick = to.type === 'PICK' || to.type === 'SAMPLE';
			var guided = Number(S.session.guided) === 1;
			var rows = [];
			var trs = to.items.map(function (i) {
				var r = { item_id: i.id };
				r.bin = inp({ class: 'lw-input code', placeholder: pick ? 'Emplacement source' : 'Emplacement', value: guided ? (pick ? i.src : i.proposed) : '', 'aria-label': 'Emplacement ligne ' + i.line_no });
				r.qty = num({ value: i.qty, 'aria-label': 'Quantité' });
				r.scan = inp({ class: 'lw-input code', placeholder: 'Scanner l\'article', 'aria-label': 'Code article scanné' });
				r.check = to.type === 'PICK' && Number(S.session.check_digits) === 1 ? inp({ class: 'lw-input code lw-cd', placeholder: 'Contrôle', maxlength: '2', inputmode: 'numeric', 'aria-label': 'Chiffre de contrôle de l\'emplacement' }) : null;
				r.batch = i.batch;
				// Code d'écart (confirmation d'un entreposage avec une quantité inférieure : perte de citerne, casse, manquant).
				r.diff = !pick && to.status === 'OUVERT' ? sel([['', 'Écart : —'], ['0', '0 – perte de citerne'], ['1', '1 – casse'], ['2', '2 – manquant']], '', { 'aria-label': 'Code d\'écart ligne ' + i.line_no, class: 'lw-select lw-diff-code' }) : null;
				if (i.material.bulk && r.diff) { r.diff.title = 'Vrac : déduisez la perte de citerne de la quantité et choisissez le code 0'; }
				rows.push(r);
				var info = i.proposed_info ? h('div', { class: 'lw-small lw-muted', text: i.proposed_info.type + ' · ' + i.proposed_info.location_name + ' · charge max ' + qty(i.proposed_info.max_weight) + ' kg' }) : null;
				return { cells: [i.line_no, matCell(i.material), i.batch ? [i.batch, i.bbd ? h('div', { class: 'lw-small', text: 'DLC ' + date(i.bbd) }) : null] : '—', badge(i.stock_status),
					h('span', { class: 'lw-mono', text: i.src }), pick ? h('span', { class: 'lw-mono', text: to.type === 'SAMPLE' ? '→ laboratoire' : '→ ' + (i.dst || '700') }) : [h('span', { class: 'lw-mono', text: i.proposed || '(aucune proposition)' }), info],
					qty(i.qty) + ' ' + i.material.uom, to.status === 'OUVERT' ? (r.check ? h('div', { class: 'lw-bincd' }, r.bin, r.check) : r.bin) : h('span', { class: 'lw-mono', text: i.dst || i.src }), to.status === 'OUVERT' ? (r.diff ? h('div', { class: 'lw-qtydiff' }, r.qty, r.diff) : r.qty) : qty(i.qty_confirmed), to.status === 'OUVERT' ? r.scan : (Number(i.strategy_ok) ? '✓' : h('span', { class: 'lw-badge red', text: 'Hors stratégie' }))] };
			});
			var doc = card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: to.type_label + ' – OT ' + to.to_no }), h('div', null, badge(to.status), to.ref_no ? ' réf. ' + to.ref_no : '')),
				to.picker ? h('p', { class: 'lw-small' }, to.sector ? h('span', { class: 'lw-chip sector', text: 'Secteur ' + to.sector }) : null, ' Préparateur : ' + to.picker) : null,
				h('p', { class: 'lw-small lw-muted', text: pick ? 'Allez à l\'emplacement proposé (ordre du chemin de prélèvement), prélevez le lot indiqué, scannez l\'emplacement et l\'article.' + (to.type === 'PICK' && Number(S.session.check_digits) === 1 ? ' Saisissez aussi le chiffre de contrôle imprimé sur l\'étiquette de l\'emplacement.' : '') + ' Un autre emplacement n\'est accepté que s\'il respecte la stratégie (FIFO / FEFO).' : 'Déposez l\'unité à l\'emplacement proposé ou choisissez-en un autre : il sera contrôlé (profil, température, ADR, sous clé, charge, volume, longueur, emplacement fixe). Scannez l\'emplacement et l\'article.' }),
				table(['Ligne', 'Article', 'Lot', 'Statut', 'Source', pick ? 'Destination' : 'Proposition', 'Quantité', pick ? 'Emplacement prélevé' : 'Emplacement confirmé', 'Qté confirmée', to.status === 'OUVERT' ? 'Scan article' : 'Stratégie'], trs, [6])
			]);
			append(out, msg, doc);
			if (to.status === 'OUVERT' && !S.session.readonly) {
				append(out, h('div', { class: 'lw-actions' }, btn('Confirmer l\'ordre de transfert', 'lw-btn-key', function () {
					act(msg, post(sp('/to/' + encodeURIComponent(to.to_no) + '/confirm'), { items: rows.map(function (r) { return { item_id: r.item_id, bin_code: r.bin.value.trim(), check: r.check ? r.check.value.trim() : '', diff: r.diff ? r.diff.value : '', qty: r.qty.value, scan: r.scan.value.trim(), batch: pick ? r.batch : '' }; }) }), function () { toForm(to.to_no, out); refreshSession(); });
				}), printBtn(doc)));
			}
		}).catch(function (e) { fail(out, e); });
	}

	tx('LT12', 'Confirmer un ordre de transfert', 'Entrepôt', 'PUT|PICK|WH|PP|SHIP', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: 'N° d\'OT', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { toForm(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Ordre de transfert', input), btn('Afficher', 'lw-btn-primary', function () { toForm(input.value.trim(), out); }))), out);
		if (p.no) { toForm(p.no, out); } else { toList(out, function (no) { input.value = no; toForm(no, out); }); }
	});

	tx('LT01', 'Créer un ordre de transfert', 'Entrepôt', 'PUT|WH|PICK', function (el) {
		var msg = h('div');
		var needs = h('div', null, loading());
		get(sp('/replenishment')).then(function (rows) {
			clear(needs);
			append(needs, table(['Emplacement de prélèvement', 'Article', 'Stock', 'Min', 'Max', 'Réserve disponible', ''], rows.map(function (r) {
				return { cells: [h('span', { class: 'lw-mono', text: r.bin }), matCell(r.material), qty(r.qty), qty(r.min), qty(r.max), qty(r.reserve), r.reserve > 0 ? btn('Créer l\'OT', 'lw-btn-secondary lw-btn-sm', function () { act(msg, post(sp('/replenishment'), { bin_id: r.bin_id }), function (d) { go('LT12', { no: d.no }); }); }) : h('span', { class: 'lw-small lw-muted', text: 'Pas de réserve : commander' })] };
			}), [2, 3, 4, 5]));
			if (!rows.length) { append(needs, h('p', { class: 'lw-muted', text: 'Tous les emplacements de prélèvement sont au-dessus de leur minimum.' })); }
		}).catch(function (e) { fail(needs, e); });
		var area = h('div');
		var picker = stockPicker(function (x) {
			clear(area);
			var q = num({ value: x.qty });
			var dst = inp({ class: 'lw-input code', placeholder: 'Emplacement de destination' });
			append(area, stockSummary(x), h('div', { class: 'lw-form' }, field('Quantité', q), field('Destination', dst), btn('Créer l\'OT', 'lw-btn-key', function () { act(msg, post(sp('/to'), { stock_id: x.id, qty: q.value, dst_bin: dst.value.trim() }), function (d) { go('LT12', { no: d.no }); }); })));
		});
		append(el, msg, card('Réapprovisionnement des emplacements de prélèvement (min / max)', needs, 'yellow'), card('Transfert interne d\'emplacement à emplacement', h('div', null, picker, area)));
	});

	tx('LX03', 'Emplacements d\'un magasin', 'Entrepôt', '', function (el, p) {
		var out = h('div');
		var info = h('div');
		var loc = sel([], p.location || '110');
		var filter = sel([['', 'Tous'], ['empty', 'Vides'], ['occupied', 'Occupés'], ['blocked', 'Bloqués']], '');
		var page = 1;
		locations().then(function (l) { l.forEach(function (z) { var o = h('option', { value: z.code, text: z.code + ' – ' + z.name }); if (z.code === (p.location || '110')) { o.selected = true; } loc.appendChild(o); }); run(); });
		function run() {
			clear(out); append(out, loading());
			get(sp('/bins'), { location: loc.value, filter: filter.value, page: page }).then(function (r) {
				clear(out); clear(info);
				var l = r.location;
				append(info, card(l.code + ' – ' + l.name, h('div', { class: 'lw-grid3' }, dl([['Type de zone', l.zone], ['Rayonnage', l.rack], ['Stratégie', { CHAOTIC: 'Stockage chaotique', FIXED: 'Emplacements fixes (lotissement par case)', NONE: 'Zone de transit' }[l.strategy] || l.strategy]]), dl([['Prélèvement', l.removal], ['Profils admis', l.profiles === '*' ? 'Tous (transit)' : l.profiles], ['Classes ADR', l.adr || '—']]), dl([['Température', l.temp || 'Ambiante'], ['Sous clé', l.secured ? 'Oui' : 'Non'], ['Remarque', l.notes]])), 'ink'));
				append(out, table(['Emplacement', 'Type', 'Article fixe', 'Contenu', 'Remplissage', 'Poids', 'Blocage'], r.bins.map(function (b) {
					var cls = b.fill > 90 ? 'high' : b.fill > 70 ? 'mid' : '';
					return { cells: [h('span', { class: 'lw-mono', text: b.code }), b.type, h('span', { class: 'lw-mono', text: b.fixed }), b.materials, h('div', { class: 'lw-fill ' + cls, title: b.fill + ' %' }, h('span', { style: { width: Math.min(100, b.fill) + '%' } })), qty(b.weight) + ' / ' + qty(b.max_weight) + ' kg', b.blocked ? h('span', { class: 'lw-badge red', title: b.reason, text: (b.blocked.indexOf('E') >= 0 ? 'Entrée' : '') + (b.blocked.length > 1 ? ' + ' : '') + (b.blocked.indexOf('S') >= 0 ? 'Sortie' : '') }) : ''], onclick: function () { binModal(b.code); } };
				}), [5]));
				append(out, h('div', { class: 'lw-pager' }, 'Page ' + r.page, r.page > 1 ? btn('‹', 'lw-btn-secondary lw-btn-sm', function () { page--; run(); }) : null, r.bins.length === 100 ? btn('›', 'lw-btn-secondary lw-btn-sm', function () { page++; run(); }) : null));
			}).catch(function (e) { fail(out, e); });
		}
		append(el, card(null, h('div', { class: 'lw-form' }, field('Magasin', loc), field('Filtre', filter), btn('Afficher', 'lw-btn-primary', function () { page = 1; run(); }))), info, out);
	});
	function binModal(code) {
		var body = h('div', null, loading());
		modal('Emplacement ' + code, body);
		get(sp('/stock'), { bin: code }).then(function (r) {
			clear(body);
			var rows = r.rows.filter(function (x) { return x.bin === code; });
			if (BC) { append(body, h('div', { class: 'lw-bc bin' }, BC.code128Svg(code, { module: 2, height: 56, fontSize: 16 }))); }
			append(body, rows.length ? table(['Article', 'Lot', 'DLC', 'Statut', 'Quantité'], rows.map(function (x) { return { cells: [[h('span', { class: 'lw-mono', text: x.material }), ' ', x.designation], x.batch, date(x.bbd), badge(x.status), qty(x.qty) + ' ' + x.uom] }; }), [4]) : h('p', { class: 'lw-muted', text: 'Emplacement vide.' }));
		}).catch(function (e) { fail(body, e); });
	}

	var ZONE_COLORS = { STD: ['#eceeea', '#4a5563'], PICK: ['#dcefe3', '#067030'], PROD: ['#dcefe3', '#067030'], TRANSIT: ['#dcefe3', '#067030'], COLD: ['#dbeaf7', '#2b6cb0'], FROZEN: ['#c4dbf2', '#1e4e8c'], TEMP: ['#fbe7d3', '#b45309'], CHEM: ['#fde7e4', '#b42318'], HV: ['#fff4c2', '#5c4800'], LONG: ['#e4e8ed', '#313a45'], HEAVY: ['#e4e8ed', '#313a45'] };
	tx('ZMAP', 'Plan de l\'entrepôt', 'Entrepôt', '', function (el) {
		var box = h('div', null, loading());
		append(el, box);
		get(sp('/map')).then(function (zones) {
			LOCS = zones;
			clear(box);
			var svg = s('svg', { class: 'lw-map', viewBox: '0 0 1200 760', role: 'img', 'aria-label': 'Plan de l\'entrepôt, site 1000' });
			zones.forEach(function (z) {
				var c = ZONE_COLORS[z.zone] || ZONE_COLORS.STD;
				var x = z.map[0], y = z.map[1], w = z.map[2], ht = z.map[3];
				var fillW = Math.max(0, Math.min(1, z.fill / 100)) * (w - 16);
				var fc = z.fill > 90 ? '#b42318' : z.fill > 70 ? '#f5c400' : '#067030';
				var g = s('g', { class: 'zone', tabindex: '0', role: 'button', 'aria-label': z.code + ' ' + z.name + ', remplissage ' + z.fill + ' %', onclick: function () { go('LX03', { location: z.code }); }, onkeydown: function (e) { if (e.key === 'Enter') { go('LX03', { location: z.code }); } } },
					s('title', { text: z.code + ' – ' + z.name + ' · ' + z.used + ' / ' + z.bins + ' emplacements occupés' + (z.blocked ? ' · ' + z.blocked + ' bloqué(s)' : '') }),
					s('rect', { x: x, y: y, width: w, height: ht, fill: c[0], stroke: c[1] }),
					s('text', { x: x + 8, y: y + 20, 'font-size': w < 100 ? 13 : 16, 'font-weight': 700, fill: '#313a45', text: z.code }),
					w >= 100 ? s('text', { x: x + 8, y: y + 38, 'font-size': 11.5, fill: '#4a5563', text: z.name.length > Math.floor(w / 6.5) ? z.name.slice(0, Math.floor(w / 6.5) - 1) + '…' : z.name }) : null,
					z.secured ? s('text', { x: x + w - 22, y: y + 20, 'font-size': 14, text: '🔒' }) : null,
					z.blocked ? s('text', { x: x + 8, y: y + ht - 22, 'font-size': 11, fill: '#b42318', 'font-weight': 700, text: z.blocked + ' bloqué(s)' }) : null,
					s('rect', { x: x + 8, y: y + ht - 14, width: w - 16, height: 6, rx: 3, fill: '#ffffff' }),
					s('rect', { x: x + 8, y: y + ht - 14, width: fillW, height: 6, rx: 3, fill: fc }));
				svg.appendChild(g);
			});
			append(box, h('div', { class: 'lw-legend' }, [['Standard', 'STD'], ['Froid positif', 'COLD'], ['Froid négatif', 'FROZEN'], ['Tempéré', 'TEMP'], ['Chimie / batteries', 'CHEM'], ['Haute valeur', 'HV'], ['Longues / lourdes', 'LONG'], ['Réception, préparation, expédition', 'TRANSIT']].map(function (l) { var c = ZONE_COLORS[l[1]]; return h('span', null, h('i', { style: { background: c[0], borderColor: c[1] } }), l[0]); }), h('span', null, '🔒 sous clé · barre : remplissage (vert < 70 %, jaune 70–90 %, rouge > 90 %)')));
			append(box, svg);
			append(box, h('p', { class: 'lw-small lw-muted', text: 'Cliquez sur un magasin pour voir ses emplacements.' }));
			// Smartphone : le plan est trop petit pour être touché, les magasins sont aussi listés en fiches.
			append(box, h('div', { class: 'lw-map-list' }, zones.map(function (z) {
				var c = ZONE_COLORS[z.zone] || ZONE_COLORS.STD;
				return h('button', { type: 'button', class: 'lw-map-item', style: { borderLeftColor: c[1] }, onclick: function () { go('LX03', { location: z.code }); } },
					h('strong', { text: z.code + ' · ' + z.name + (z.secured ? ' 🔒' : '') }),
					h('span', { class: 'lw-small', text: z.used + ' / ' + z.bins + ' emplacements occupés' + (z.blocked ? ' · ' + z.blocked + ' bloqué(s)' : '') }),
					h('div', { class: 'lw-fill' + (z.fill > 90 ? ' high' : z.fill > 70 ? ' mid' : '') }, h('span', { style: { width: Math.min(100, z.fill) + '%' } })));
			})));
		}).catch(function (e) { fail(box, e); });
	});

	tx('LM00', 'Terminal de scan', 'Entrepôt', 'PUT|PICK|WH', function (el, p) {
		var term = h('div', { class: 'lw-scan' });
		append(el, term);
		function list() {
			clear(term); append(term, h('h3', { text: 'Ordres à traiter' }));
			get(sp('/to')).then(function (rows) {
				if (!rows.length) { append(term, h('p', { text: 'Aucun ordre ouvert.' })); return; }
				rows.forEach(function (r) { append(term, h('div', { style: { marginBottom: '8px' } }, btn(r.to_no + ' · ' + (TO_LABEL[r.type] || r.type), 'lw-btn-key', function () { run(r.to_no); }))); });
			}).catch(function (e) { append(term, msgBox('error', e.message)); });
		}
		function run(no) {
			get(sp('/to/' + encodeURIComponent(no))).then(function (to) {
				var done = [];
				var k = 0;
				var pick = to.type === 'PICK' || to.type === 'SAMPLE';
				function step() {
					clear(term);
					if (k >= to.items.length) {
						var msg = h('div');
						append(term, h('h3', { text: 'OT ' + to.to_no + ' : tout est scanné' }), msg, btn('Valider l\'ordre', 'lw-btn-key', function () { act(msg, post(sp('/to/' + encodeURIComponent(to.to_no) + '/confirm'), { items: done }), function () { setTimeout(list, 1500); }); }), h('div', { style: { marginTop: '8px' } }, btn('Annuler', 'lw-btn-secondary', list)));
						return;
					}
					var i = to.items[k];
					var bin = inp({ class: 'lw-input code', placeholder: 'Scanner l\'emplacement', 'aria-label': 'Emplacement' });
					var art = inp({ class: 'lw-input code', placeholder: 'Scanner l\'article', 'aria-label': 'Article' });
					var q = num({ value: i.qty, 'aria-label': 'Quantité' });
					var cd = to.type === 'PICK' && Number(S.session.check_digits) === 1 ? inp({ class: 'lw-input code', placeholder: 'Chiffre de contrôle', maxlength: '2', inputmode: 'numeric', 'aria-label': 'Chiffre de contrôle' }) : null;
					append(term, h('h3', { text: 'Ligne ' + (k + 1) + ' / ' + to.items.length }), h('dl', { class: 'lw-dl' }, h('dt', { text: 'Aller à' }), h('dd', { class: 'lw-mono', text: pick ? i.src : (i.proposed || '—') }), h('dt', { text: 'Article' }), h('dd', { text: i.material.no + ' ' + i.material.designation }), h('dt', { text: 'Lot' }), h('dd', { text: i.batch || '—' }), h('dt', { text: 'Quantité' }), h('dd', { text: qty(i.qty) + ' ' + i.material.uom })),
						h('div', { style: { display: 'grid', gap: '8px', margin: '12px 0' } }, bin, cd, art, q), btn('Ligne suivante', 'lw-btn-key', function () { done.push({ item_id: i.id, bin_code: bin.value.trim(), check: cd ? cd.value.trim() : '', scan: art.value.trim(), qty: q.value, batch: pick ? i.batch : '' }); k++; step(); }));
					bin.focus();
					[bin, art].forEach(function (x, n) { x.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); (n === 0 ? art : q).focus(); } }); });
				}
				step();
			}).catch(function (e) { status('error', e.message); });
		}
		if (p.no) { run(p.no); } else { list(); }
	});

	/* ════════════════════════ Ventes et expédition ════════════════════════ */

	tx('VA01', 'Créer une commande client', 'Ventes et expédition', 'SD', function (el) {
		var customer = null;
		var lines = [];
		var msg = h('div');
		var info = h('div');
		var tbody = h('div');
		var fc = f4Field('Client', 'customer', function (r) {
			customer = r; clear(info);
			get(sp('/partners/customer/' + r.id)).then(function (x) { append(info, card(null, dl([['Client', x.customer_no + ' – ' + x.name], ['Priorité', x.priority], ['Livraison', x.delivery_window + ' · ' + x.carrier_code], ['Exigences', x.packing_req], ['Limite de crédit', 'CHF ' + chf(x.credit_limit)], ['Blocage', Number(x.blocked) ? h('span', { class: 'lw-badge red', text: 'Bloqué pour livraison' }) : 'Non']]), Number(x.blocked) ? 'yellow' : 'ink')); });
		});
		var dt = inp({ type: 'date', value: addDays(S.session.sim_date, 2), min: S.session.sim_date });
		var prio = sel([['A', 'A – urgente'], ['B', 'B – normale'], ['C', 'C – basse']], 'B');
		var note = inp({ maxlength: '255' });
		function draw() {
			clear(tbody);
			append(tbody, table(['Ligne', 'Article', 'Disponible', 'Quantité', 'Prix CHF', ''], lines.map(function (l, i) {
				return { cells: [(i + 1) * 10, matCell(l.m), qty(l.m.stock.disponible) + ' ' + l.m.uom, num({ value: l.qty, oninput: function () { l.qty = this.value; } }), chf(l.m.sale_price), btn('Retirer', 'lw-btn-danger lw-btn-sm', function () { lines.splice(i, 1); draw(); })] };
			}), [2, 3, 4]));
		}
		var fm = f4Field('Ajouter un article', 'material', function (r) { get(sp('/materials/' + encodeURIComponent(r.no))).then(function (m) { lines.push({ m: m, qty: '' }); draw(); fm.input.value = ''; }); });
		append(el, msg, card('En-tête', h('div', { class: 'lw-form' }, fc, field('Livraison souhaitée', dt), field('Priorité', prio), field('Remarque', note)), 'ink'), info,
			card('Lignes', h('div', null, h('div', { class: 'lw-form' }, fm), h('div', { style: { marginTop: '12px' } }, tbody), h('div', { class: 'lw-actions' }, btn('Enregistrer la commande', 'lw-btn-key', function () {
				act(msg, post(sp('/so'), { customer_id: customer ? customer.id : 0, req_date: dt.value, priority: prio.value, note: note.value, lines: lines.map(function (l) { return { material_id: l.m.id, qty: l.qty }; }) }), function (d) {
					lines = []; draw();
					append(msg, table(['Ligne', 'Article', 'Commandé', 'Réservé', 'Manquant', 'Disponible le'], d.lines.map(function (l) { return { cells: [l.line_no, l.material, qty(l.qty), qty(l.reserved), l.missing > 0 ? h('span', { class: 'lw-diff', text: qty(l.missing) }) : '0', date(l.available_date)] }; }), [2, 3, 4]), h('p', null, link(d.so_no, 'VA03', { no: d.so_no })));
				});
			})))));
		draw();
	});

	function soView(no, out) {
		clear(out); append(out, loading());
		get(sp('/so/' + encodeURIComponent(no))).then(function (so) {
			clear(out);
			var msg = h('div');
			var c = so.customer || {};
			var doc = card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Commande client ' + so.so_no }), h('div', null, badge(so.status), ' ', h('span', { class: 'lw-badge ' + (so.priority === 'A' ? 'red' : ''), text: 'Priorité ' + so.priority }))),
				h('div', { class: 'lw-grid2' }, dl([['Client', c.no + ' – ' + c.name], ['Localité', c.city], ['Plage de livraison', c.window], ['Exigences', c.packing]]), dl([['Date de commande', date(so.order_date)], ['Livraison souhaitée', date(so.req_date)], ['Transporteur habituel', c.carrier], ['Remarque', so.note]])),
				h('div', { style: { marginTop: '12px' } }, table(['Ligne', 'Article', 'Commandé', 'Réservé', 'Livré', 'Disponible', 'Prix CHF'], so.items.map(function (i) { return { cells: [i.line_no, matCell(i.material), qty(i.qty) + ' ' + i.material.uom, qty(i.qty_reserved), qty(i.qty_delivered), qty(i.available), chf(i.price)] }; }), [2, 3, 4, 5, 6])),
				so.deliveries.length ? h('p', { class: 'lw-small' }, 'Livraisons : ', so.deliveries.map(function (d) { return [link(d.dlv_no, 'VL02N', { no: d.dlv_no }), ' (' + (STATUS[d.status] || d.status) + ') ']; })) : null
			]);
			append(out, msg, doc, h('div', { class: 'lw-actions lw-noprint' },
				['CONFIRMEE', 'PARTIELLEMENT_LIVREE', 'EN_PREPARATION'].indexOf(so.status) >= 0 && !S.session.readonly ? btn('Créer la livraison', 'lw-btn-key', function () { act(msg, post(sp('/so/' + encodeURIComponent(so.so_no) + '/delivery')), function (d) { soView(so.so_no, out); if (d.to_no) { toast('Prélèvement', 'Ordre de prélèvement ' + d.to_no + ' créé. Confirmez-le dans LT12.'); } }); }) : null,
				printBtn(doc)));
		}).catch(function (e) { fail(out, e); });
	}
	tx('VA03', 'Afficher une commande client', 'Ventes et expédition', 'SD', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: 'N° de commande', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { soView(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Commande client', input), btn('Afficher', 'lw-btn-primary', function () { soView(input.value.trim(), out); }))), out);
		if (p.no) { soView(p.no, out); } else { append(out, docPicker('so', 'Commandes clients', function (no) { input.value = no; soView(no, out); }, false)); }
	});
	tx('VL01N', 'Créer une livraison', 'Ventes et expédition', 'SD|PICK', function (el) {
		var out = h('div');
		append(el, h('p', { class: 'lw-muted' }, 'Choisissez une commande ouverte (traitez les priorités A en premier), puis créez la livraison : le système propose les lots à prélever selon FIFO ou FEFO, en préparation en série. Pour préparer plusieurs commandes à la fois ou en parallèle par secteur, utilisez ', link('VL10', 'VL10'), '.'), docPicker('so', 'Commandes à livrer', function (no) { soView(no, out); }), out);
	});

	function dlvView(no, out, notice) {
		clear(out); append(out, loading());
		Promise.all([get(sp('/dlv/' + encodeURIComponent(no))), get(sp('/carriers'))]).then(function (r) {
			var d = r[0], carriers = r[1];
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			var simple = S.session.mode === 'SIMPLE';
			var doc = card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Livraison ' + d.dlv_no }), badge(d.status)),
				h('div', { class: 'lw-grid2' }, dl([['Commande client', link(d.so_no, 'VA03', { no: d.so_no })], ['Transport', d.shipment ? link(d.shipment, 'VT02N', { no: d.shipment }) : (d.carrier || (d.status === 'EMBALLEE' ? 'Tournée à planifier (VT01N)' : '—'))], ['Support / colis', d.packages ? d.packages + ' × ' + (d.support || 'colis') + (d.frames ? ' + ' + d.frames + ' cadre(s)' : '') + (d.lids ? ' + ' + d.lids + ' couvercle(s)' : '') : '—'], ['Poids', d.weight_kg ? qty(d.weight_kg) + ' kg' : '—']]),
					d.customer ? dl([['Client', d.customer.name + ', ' + d.customer.city], ['Livraison', d.customer.days_label + ' · ' + d.customer.window], ['Support exigé', d.customer.pallet_label], ['Accès', d.customer.vehicle_label || 'Aucune restriction']]) : null),
				h('div', { class: 'lw-inline', style: { margin: '8px 0' } }, d.needs.adr ? h('span', { class: 'lw-chip adr', text: 'Matières dangereuses : transport ADR' }) : null, d.needs.cold ? h('span', { class: 'lw-chip cold', text: 'Chaîne du froid : transport frigorifique' }) : null),
				table(['Article', 'Quantité', simple ? 'À expédier' : 'Prélevé', 'Poids kg'], d.items.map(function (i) { return { cells: [matCell(i.material), qty(i.qty) + ' ' + i.material.uom, simple ? qty(i.qty) : qty(i.qty_picked), qty(i.weight)] }; }), [1, 2, 3]),
				d.transfer_orders.length ? h('p', { class: 'lw-small' }, 'Ordres de prélèvement : ', d.transfer_orders.map(function (t) { return [link(t.to_no, 'LT12', { no: t.to_no }), ' (' + (t.picker ? t.picker + ', ' : '') + (STATUS[t.status] || t.status) + ') ']; })) : null,
				!simple ? h('p', { class: 'lw-small' }, 'Préparation : ' + (d.mode_label || 'en série') + (d.consolidation ? ' · regroupement en ' + d.consolidation : '')) : null,
				d.units && d.units.length ? h('p', { class: 'lw-small' }, 'Unités d\'expédition : ', d.units.map(function (u) { return [h('span', { class: 'lw-mono', text: u.sscc }), ' (' + u.type + ', ' + (u.status === 'FERMEE' ? 'fermée' : 'ouverte') + ') ']; })) : null
			]);
			append(out, msg, doc);
			var hasHu = d.units && d.units.length;
			if (d.status !== 'EXPEDIEE' && !S.session.readonly && !d.shipment && (hasHu || d.mode === 'PARALLELE')) {
				append(out, msgBox(hasHu ? 'info' : 'warn', hasHu ? 'Livraison colisée : le support et le nombre d\'unités sont repris du colisage (HU02).' : 'Préparation en parallèle : regroupez la commande et colisez-la (HU02) avant d\'emballer.'), h('div', { class: 'lw-actions' }, btn('Colisage (HU02)', 'lw-btn-secondary', function () { go('HU02', { no: d.dlv_no }); })));
			}
			if (d.status !== 'EXPEDIEE' && !S.session.readonly) {
				var carrier = sel([['', '— choisir —'], ['TOUR', 'Tournée planifiée (VT01N) : flotte ou prestataire']].concat(carriers.map(function (c) { return [c.code, c.name + ' (' + c.type + ', max ' + qty(c.max_weight) + ' kg)']; })), d.status === 'EMBALLEE' && !d.carrier ? 'TOUR' : (d.carrier || ''));
				var support = sel([['', '— support —']].concat(SUPPORTS), d.support || (d.customer ? d.customer.pallet : '') || '');
				var pk = num({ value: d.packages || (hasHu ? d.units.length : 1), min: '1', step: '1' });
				if (hasHu) { support.disabled = true; pk.disabled = true; }
				var frames = num({ value: d.frames || 0, min: '0', step: '1' });
				var lids = num({ value: d.lids || 0, min: '0', step: '1' });
				var planned = !!d.shipment;
				append(out, card('Emballage et expédition', h('div', null, planned ? msgBox('info', 'Livraison affectée à la tournée ' + d.shipment + ' : la sortie de marchandises se fait au départ du camion (VT02N).') : null,
					planned ? null : h('div', { class: 'lw-form' }, field('Transport', carrier), field('Support de charge', support), field('Nombre de palettes / colis', pk), field('Cadres de palette', frames, 'Consignés'), field('Couvercles', lids, 'Consignés'), btn('Emballer', 'lw-btn-primary', function () { act(msg, post(sp('/dlv/' + encodeURIComponent(d.dlv_no) + '/pack'), { carrier: carrier.value, packages: pk.value, support: support.value, frames: frames.value, lids: lids.value }), function (x) { dlvView(d.dlv_no, out, x && x.message); }); })),
					h('div', { class: 'lw-actions' }, planned || (d.status === 'EMBALLEE' && !d.carrier) ? btn('Planifier la tournée (VT01N)', 'lw-btn-key', function () { go(planned ? 'VT02N' : 'VT01N', planned ? { no: d.shipment } : {}); }) : btn('Sortie de marchandises (601)', 'lw-btn-key', function () { act(msg, post(sp('/dlv/' + encodeURIComponent(d.dlv_no) + '/issue')), function (x) { dlvView(d.dlv_no, out, x && x.message); refreshSession(); }); }), printBtn(doc))), 'yellow'));
			} else { append(out, h('div', { class: 'lw-actions' }, printBtn(doc))); }
		}).catch(function (e) { fail(out, e); });
	}
	tx('VL02N', 'Préparer et expédier une livraison', 'Ventes et expédition', 'SD|SHIP|PICK', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: 'N° de livraison', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { dlvView(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Livraison', input), btn('Afficher', 'lw-btn-primary', function () { dlvView(input.value.trim(), out); }))), out);
		if (p.no) { dlvView(p.no, out); } else { append(out, docPicker('dlv', 'Livraisons en cours', function (no) { input.value = no; dlvView(no, out); })); }
	});

	/* ════════════════════════ Préparation des commandes : VL10, HU02 ════════════════════════ */

	var MODES = [['SERIE', 'En série : un préparateur prélève toute la commande'], ['PARALLELE', 'En parallèle : un préparateur par secteur, puis regroupement en zone 600']];
	function sectorChips(list) { return h('span', { class: 'lw-inline lw-sectors' }, list.map(function (s) { return h('span', { class: 'lw-chip sector', title: s.name + ' · ' + s.lines + ' ligne' + (s.lines > 1 ? 's' : ''), text: s.code + (s.lines > 1 ? ' ×' + s.lines : '') }); })); }
	function pal(x) { return x < 0.01 ? '< 0,01' : String(x.toFixed(2)).replace('.', ','); }

	tx('VL10', 'Commandes à livrer (préparation)', 'Ventes et expédition', 'SD|PICK', function (el) {
		var msg = h('div');
		var list = h('div', null, loading());
		var result = h('div');
		var until = h('input', { class: 'lw-input', type: 'date' });
		var mine = h('input', { type: 'checkbox', checked: true });
		var rows = [], picked = {};
		var mode = sel([['', '— choisir —']].concat(MODES), '');
		var picker = inp({ maxlength: '40', placeholder: 'Préparateur 1' });
		function load() {
			clear(list); append(list, loading());
			get(sp('/vl10'), { until: until.value }).then(function (r) { rows = r; draw(); }).catch(function (e) { fail(list, e); });
		}
		function draw() {
			clear(list);
			var shown = rows.filter(function (o) { return !mine.checked || o.mission; });
			var other = rows.length - rows.filter(function (o) { return o.mission; }).length;
			append(list, table(['', 'Commande', 'Prio.', 'Client', 'Livraison souhaitée', 'Lignes', 'Secteurs de prélèvement', 'Poids kg', 'Volume l', 'Palette EUR', ''], shown.map(function (o) {
				var cb = h('input', { type: 'checkbox', 'aria-label': 'Choisir la commande ' + o.so_no, checked: !!picked[o.so_no], onchange: function () { picked[o.so_no] = this.checked; } });
				var c = o.customer || {};
				return { cls: o.missing ? 'warn' : '', cells: [cb, link(o.so_no, 'VA03', { no: o.so_no }), h('span', { class: 'lw-badge ' + (o.priority === 'A' ? 'red' : ''), text: o.priority }), [c.name || '', h('div', { class: 'lw-small lw-muted', text: c.city + (c.pallet ? ' · exige ' + c.pallet : '') })], date(o.req_date), o.lines.length, sectorChips(o.sectors), qty(o.weight), qty(o.volume), '≈ ' + pal(o.pallets),
					h('span', null, o.adr ? h('span', { class: 'lw-chip adr', text: 'ADR' }) : null, ' ', o.cold ? h('span', { class: 'lw-chip cold', text: 'Froid' }) : null, ' ', o.missing ? h('span', { class: 'lw-badge red', text: 'Rupture partielle' }) : null)] };
			}), [5, 7, 8]));
			if (!shown.length) { append(list, h('p', { class: 'lw-muted', text: 'Aucune commande à livrer pour ce filtre.' })); }
			if (mine.checked && other > 0) { append(list, h('p', { class: 'lw-small lw-muted', text: other + ' autre(s) commande(s) ouverte(s) du centre de distribution masquée(s).' })); }
		}
		mine.addEventListener('change', draw);
		append(el, h('p', { class: 'lw-muted', text: 'Liste des commandes clients à livrer, avec les secteurs de l\'entrepôt où se trouvent les articles (FIFO / FEFO) et la charge estimée. Cochez les commandes, choisissez le mode de préparation, puis créez les livraisons et leurs ordres de prélèvement.' }),
			card('Sélection', h('div', { class: 'lw-form' }, field('Livraison souhaitée jusqu\'au', until, 'Vide = toutes les dates'), h('label', { class: 'lw-check' }, mine, ' Commandes de la mission uniquement'), btn('Actualiser', 'lw-btn-secondary', load)), 'ink'),
			card('Commandes à livrer', list),
			card('Lancer la préparation', h('div', null,
				h('div', { class: 'lw-grid2 lw-modes' },
					h('div', { class: 'lw-mode' }, h('strong', { text: 'En série' }), h('p', { class: 'lw-small', text: 'Un préparateur prélève toute la commande, article après article, et la dépose au quai d\'expédition (700). Simple et sans regroupement : idéal quand la commande se trouve dans un seul secteur ou qu\'elle est petite.' })),
					h('div', { class: 'lw-mode' }, h('strong', { text: 'En parallèle' }), h('p', { class: 'lw-small', text: 'Un ordre de prélèvement par secteur : plusieurs préparateurs travaillent en même temps. Tout est déposé au même emplacement de la zone de regroupement (600), puis la commande est colisée (HU02), par exemple sur une seule palette mixte.' }))),
				h('div', { class: 'lw-form' }, field('Mode de préparation', mode), field('Préparateur (en série)', picker, 'Nom affiché sur l\'ordre de prélèvement'),
					btn('Créer les livraisons', 'lw-btn-key', function () {
						var orders = Object.keys(picked).filter(function (k) { return picked[k]; });
						act(msg, post(sp('/vl10'), { orders: orders, mode: mode.value, picker: picker.value }), function (d) {
							picked = {}; load(); refreshSession();
							clear(result);
							append(result, card('Livraisons créées', [
								table(['Commande', 'Livraison', 'Ordres de prélèvement', 'Dépôt'], d.created.map(function (c) {
									return { cells: [c.so_no, link(c.dlv_no, 'VL02N', { no: c.dlv_no }), h('div', null, (c.tos || []).map(function (t) { return h('div', null, link(t.to_no, 'LT12', { no: t.to_no }), ' · ' + t.picker + ' · ' + t.lines + ' ligne' + (t.lines > 1 ? 's' : '')); })), c.consolidation ? 'Regroupement ' + c.consolidation : (c.tos && c.tos.length ? 'Quai 700' : '—')] };
								})),
								d.errors && d.errors.length ? msgBox('warn', 'Commandes non traitées', d.errors) : null,
								d.created.some(function (c) { return (c.messages || []).length; }) ? msgBox('info', 'Remarques', [].concat.apply([], d.created.map(function (c) { return (c.messages || []).map(function (m) { return c.so_no + ' : ' + m; }); }))) : null,
								h('div', { class: 'lw-actions' }, btn('Prélever (LT12)', 'lw-btn-secondary', function () { go('LT12'); }), btn('Colisage (HU02)', 'lw-btn-secondary', function () { go('HU02'); }))
							], 'yellow'));
						});
					}))), 'yellow'),
			msg, result);
		load();
	});

	/** Étiquette d'expédition GS1 d'une unité (SSCC). */
	function huLabel(det, u, n, of) {
		var row = function (k, v) { return h('div', { class: 'lw-gl-row' }, h('span', { text: k }), h('strong', { text: v })); };
		var c = det.customer || {}, sd = det.sender || {};
		var mats = u.items.map(function (i) { return i.material.no + ' × ' + qty(i.qty); });
		return h('article', { class: 'lw-gl', 'aria-label': 'Étiquette SSCC ' + u.sscc },
			h('div', { class: 'lw-gl-parties' },
				h('div', null, h('span', { class: 'lw-gl-k', text: 'Expéditeur · From' }), h('strong', { text: sd.name }), h('span', { text: [sd.street, sd.city].filter(Boolean).join(', ') })),
				h('div', null, h('span', { class: 'lw-gl-k', text: 'Destinataire · To' }), h('strong', { text: c.name || '' }), h('span', { text: [c.street, c.city].filter(Boolean).join(', ') }))),
			h('div', { class: 'lw-gl-sscc' }, h('span', { text: 'SSCC' }), h('strong', { text: u.sscc })),
			h('div', { class: 'lw-gl-data' },
				row('Unité', u.type_label + ' · ' + n + ' / ' + of),
				row('Contenu', u.mixed ? 'Palette mixte : ' + u.items.length + ' articles' : (u.items[0] ? u.items[0].material.no + ' · ' + u.items[0].material.designation : '—')),
				u.mixed ? row('Articles', mats.join(' · ')) : row('Quantité', u.items[0] ? qty(u.items[0].qty) + ' ' + u.items[0].material.uom : '—'),
				row('Poids brut', qty(u.weight) + ' kg'),
				row('Livraison', det.dlv_no + ' / commande ' + det.so_no)),
			h('div', { class: 'lw-gl-codes' }, BC ? barcodeBox(BC.code128Svg('(00)' + u.sscc, { gs1: true, module: 2, height: 78, fontSize: 16 }), 'sscc') : null));
	}
	function gauge(label, pct) { var p = Math.max(0, Math.min(100, pct)); return h('div', { class: 'lw-gauge' + (pct > 100 ? ' over' : pct > 85 ? ' high' : '') }, h('span', { text: label + ' ' + pct + ' %' }), h('i', { style: { width: p + '%' } })); }

	function huView(no, out, notice) {
		clear(out); append(out, loading());
		get(sp('/hu/' + encodeURIComponent(no))).then(function (d) {
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			var c = d.customer || {};
			var ro = S.session.readonly || d.locked;
			var hu = function (act2, body) { return post(sp('/hu/' + encodeURIComponent(d.dlv_no) + '/' + act2), body || {}); };
			var again = function (x) { huView(d.dlv_no, out, x && x.message); };
			append(out, msg, card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Colisage – livraison ' + d.dlv_no }), badge(d.status)),
				h('div', { class: 'lw-grid2' }, dl([['Commande client', link(d.so_no, 'VA03', { no: d.so_no })], ['Client', c.name ? c.name + ', ' + c.city : ''], ['Support exigé', c.pallet_label]]), dl([['Préparation', d.mode_label], ['Regroupement', d.consolidation ? 'Emplacement ' + d.consolidation + ' (zone 600)' : 'Quai d\'expédition'], ['Reste à coliser', qty(d.open)]])),
				d.pick_orders.length ? table(['Ordre de prélèvement', 'Secteur', 'Préparateur', 'Lignes', 'Statut'], d.pick_orders.map(function (t) { return { cells: [link(t.to_no, 'LT12', { no: t.to_no }), t.sector || '—', t.picker || '—', t.n_lines, badge(t.status)] }; }), [3]) : null,
				d.waiting.length ? msgBox('warn', 'Regroupement incomplet : ' + d.waiting.length + ' secteur(s) n\'ont pas encore déposé leur marchandise. Le colisage commence quand tout est arrivé.', d.waiting.map(function (t) { return (t.picker || 'Prélèvement') + (t.sector ? ', secteur ' + t.sector : '') + ' : OT ' + t.to_no; })) : null,
				h('h4', { text: 'Articles à coliser' }),
				table(['Article', 'Livré', 'Prélevé', 'Colisé', 'Reste', 'Poids / u. kg', 'Volume / u. l', 'Température'], d.items.map(function (i) { return { cls: i.open > 0 ? '' : 'done', cells: [matCell(i.material), qty(i.qty), qty(i.picked), qty(i.packed), i.open > 0 ? h('strong', { text: qty(i.open) }) : '0', qty(i.weight), qty(i.volume), i.temp === 'AMBIANT' ? 'Ambiant' : i.temp === 'FROID' ? 'Froid' : 'Surgelé'] }; }), [1, 2, 3, 4, 5, 6])
			], 'ink'));
			var units = h('div', { class: 'lw-hu-grid' });
			d.units.forEach(function (u, k) {
				var open = u.status === 'OUVERTE';
				append(units, card(null, [
					h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h4', { text: u.type_label + ' · unité ' + u.hu_no }), h('span', { class: 'lw-badge ' + (open ? '' : 'green'), text: open ? 'Ouverte' : 'Fermée' })),
					h('p', { class: 'lw-small' }, 'SSCC ', h('span', { class: 'lw-mono', text: u.sscc }), u.mixed ? h('span', { class: 'lw-chip sector', text: 'Palette mixte' }) : null),
					gauge('Charge', u.fill_w), gauge('Volume', u.fill_v),
					u.items.length ? table(['Article', 'Quantité', ''], u.items.map(function (i) { return { cells: [matCell(i.material), qty(i.qty) + ' ' + i.material.uom, open && !ro ? btn('Retirer', 'lw-btn-danger lw-btn-sm', function () { act(msg, hu('remove', { hu: u.hu_no, item_id: i.id }), again); }) : ''] }; }), [1]) : h('p', { class: 'lw-muted', text: 'Unité vide.' }),
					h('p', { class: 'lw-small', text: 'Poids brut ' + qty(u.weight) + ' kg · volume ' + qty(u.volume) + ' l' }),
					ro ? null : h('div', { class: 'lw-actions' }, open ? btn('Fermer l\'unité', 'lw-btn-primary', function () { act(msg, hu('close', { hu: u.hu_no }), again); }) : btn('Rouvrir', 'lw-btn-secondary', function () { act(msg, hu('reopen', { hu: u.hu_no }), again); }), btn('Supprimer', 'lw-btn-danger', function () { act(msg, hu('delete', { hu: u.hu_no }), again); }))
				], open ? 'yellow' : ''));
				void k;
			});
			if (!ro && d.ready) {
				var openUnits = d.units.filter(function (u) { return u.status === 'OUVERTE'; });
				var todo = d.items.filter(function (i) { return i.open > 0; });
				var tsel = sel(d.types.map(function (t) { return [t.code, t.label + ' · max ' + qty(t.max_kg) + ' kg / ' + qty(t.max_l) + ' l']; }), c.pallet && c.pallet !== 'COLIS' ? c.pallet : 'EUR');
				var form = [h('div', { class: 'lw-form' }, field('Nouvelle unité', tsel), btn('Ouvrir une unité', 'lw-btn-secondary', function () { act(msg, hu('create', { type: tsel.value }), again); }))];
				if (openUnits.length && todo.length) {
					var usel = sel(openUnits.map(function (u) { return [u.hu_no, u.hu_no + ' · ' + u.type_label]; }), openUnits[openUnits.length - 1].hu_no);
					var msel = sel(todo.map(function (i) { return [i.material.no, i.material.no + ' · ' + i.material.designation + ' (reste ' + qty(i.open) + ')']; }), todo[0].material.no);
					var q = num({ value: todo[0].open });
					msel.addEventListener('change', function () { var it = todo.filter(function (i) { return i.material.no === msel.value; })[0]; if (it) { q.value = it.open; } });
					form.unshift(h('div', { class: 'lw-form' }, field('Unité', usel), field('Article', msel), field('Quantité', q), btn('Placer sur l\'unité', 'lw-btn-key', function () { act(msg, hu('add', { hu: usel.value, material: msel.value, qty: q.value }), again); })));
				}
				append(out, card('Regrouper et coliser', form, 'yellow'));
			}
			if (d.units.length) { append(out, h('h3', { text: 'Unités d\'expédition (' + d.units.length + ')' }), units); }
			var acts = h('div', { class: 'lw-actions' });
			if (d.units.length) {
				var sheet = h('div', { class: 'lw-gl-sheet' }, d.units.map(function (u, k) { return huLabel(d, u, k + 1, d.units.length); }));
				var shown = false;
				var labels = h('div');
				append(out, labels);
				append(acts, btn('Étiquettes SSCC', 'lw-btn-secondary', function () {
					shown = !shown; clear(labels);
					if (shown) { append(labels, card('Étiquettes d\'expédition', [h('p', { class: 'lw-small lw-muted lw-noprint', text: 'Une étiquette par unité, collée sur deux côtés de la palette filmée. Le transporteur et le client scannent le SSCC.' }), h('div', { class: 'lw-actions lw-noprint' }, printBtn(sheet)), sheet])); labels.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
				}));
			}
			if (!d.open && d.units.length && d.units.every(function (u) { return u.status === 'FERMEE'; }) && !d.locked) {
				append(out, msgBox('ok', 'Commande regroupée et colisée : ' + d.units.length + ' unité(s) fermée(s). Emballez et expédiez dans VL02N.'));
				append(acts, btn('Emballer et expédier (VL02N)', 'lw-btn-key', function () { go('VL02N', { no: d.dlv_no }); }));
			}
			append(out, acts);
		}).catch(function (e) { fail(out, e); });
	}
	tx('HU02', 'Colisage : unités d\'expédition (SSCC)', 'Ventes et expédition', 'PICK|SHIP', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: 'N° de livraison', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { huView(input.value.trim(), out); } });
		append(el, h('p', { class: 'lw-muted', text: 'Regroupez les articles prélevés et répartissez-les sur des unités d\'expédition : palette EUR, CP3, perdue ou colis. Le système contrôle la charge, le volume, la température et la compatibilité des marchandises (ADR) sur chaque unité, et attribue un numéro SSCC.' }),
			card(null, h('div', { class: 'lw-inline' }, field('Livraison', input), btn('Afficher', 'lw-btn-primary', function () { huView(input.value.trim(), out); }))), out);
		if (p.no) { huView(p.no, out); } else { append(out, docPicker('dlv', 'Livraisons en préparation', function (no) { input.value = no; huView(no, out); })); }
	});

	/* ════════════════════════ Production ════════════════════════ */

	function prodView(no, out, notice) {
		clear(out); append(out, loading());
		get(sp('/prod/' + encodeURIComponent(no))).then(function (p) {
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			var doc = card(null, [
				h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Ordre de fabrication ' + p.prod_no }), badge(p.status)),
				h('div', { class: 'lw-grid2' }, dl([['Produit fini', matCell(p.material)], ['Quantité', qty(p.qty) + ' ' + p.material.uom], ['Poste de travail', p.work_center]]), dl([['Date de début', date(p.start_date)], ['Bonnes déclarées', qty(p.qty_done)], ['Rebut', qty(p.qty_scrap)]])),
				h('h4', { text: 'Composants (nomenclature)' }),
				table(['Composant', 'Besoin', 'Consommé', 'Disponible'], p.components.map(function (c) { var miss = c.available + 0.0005 < c.qty_required - c.qty_issued; return { cls: miss ? 'expired' : '', cells: [matCell(c.material), qty(c.qty_required) + ' ' + c.material.uom, qty(c.qty_issued), qty(c.available)] }; }), [1, 2, 3]),
				p.availability && p.availability.length ? availTable(p.availability, p.start_date) : null,
				p.staging ? h('p', { class: 'lw-small' }, 'Mise à disposition au bord de ligne : ', link(p.staging.to_no, 'LT12', { no: p.staging.to_no }), ' (' + (STATUS[p.staging.status] || p.staging.status) + ')') : null
			]);
			append(out, msg, doc);
			if (S.session.readonly) { return; }
			var acts = h('div', { class: 'lw-actions' });
			if (p.status === 'CREE') { append(acts, btn('Lancer l\'ordre (CO02)', 'lw-btn-key', function () { act(msg, post(sp('/prod/' + encodeURIComponent(p.prod_no) + '/release')), function (d) { prodView(p.prod_no, out, d && d.message); }); })); }
			if ((p.status === 'LANCE' || p.status === 'PARTIELLEMENT_CONFIRME') && (!p.staging || p.staging.status !== 'OUVERT') && S.session.mode !== 'SIMPLE') {
				append(acts, btn('Mettre à disposition au bord de ligne (LP10)', 'lw-btn-secondary', function () { act(msg, post(sp('/prod/' + encodeURIComponent(p.prod_no) + '/stage')), function (d) { if (d.to_no) { toast('Mise à disposition', 'Ordre ' + d.to_no + ' : prélevez les composants et déposez-les au bord de ligne (LT12 ou terminal mobile).'); } prodView(p.prod_no, out, d && d.message); }); }));
			}
			if (p.status === 'LANCE' || p.status === 'PARTIELLEMENT_CONFIRME') {
				var good = num({ value: p.qty - p.qty_done - p.qty_scrap });
				var scrap = num({ value: 0 });
				append(acts, btn('Consommer les composants (261)', 'lw-btn-primary', function () { act(msg, post(sp('/prod/' + encodeURIComponent(p.prod_no) + '/issue')), function (d) { prodView(p.prod_no, out, d && d.message); }); }));
				append(out, card('Déclaration de production', h('div', { class: 'lw-form' }, field('Quantité bonne', good), field('Rebut', scrap), btn('Déclarer (101)', 'lw-btn-key', function () { act(msg, post(sp('/prod/' + encodeURIComponent(p.prod_no) + '/confirm'), { good: good.value, scrap: scrap.value }), function (d) { prodView(p.prod_no, out, d && d.message); if (d.to_no) { toast('Entreposage', 'Ordre ' + d.to_no + ' créé : rangez le produit fini.'); } }); })), 'yellow'));
			}
			append(out, acts);
		}).catch(function (e) { fail(out, e); });
	}
	tx('CO01', 'Ordre de fabrication', 'Production', 'PP', function (el, p) {
		var out = h('div');
		var msg = h('div');
		var mat = null;
		var fm = f4Field('Produit fini (avec nomenclature)', 'material', function (r) { mat = r; });
		var q = num({ value: p.qty || 1 });
		var start = inp({ type: 'date', value: p.start || S.session.sim_date, min: S.session.sim_date });
		var check = h('div');
		append(el, msg, card('Créer un ordre', h('div', null, h('div', { class: 'lw-form' }, fm, field('Quantité', q), field('Date de début', start),
			btn('Contrôler la disponibilité', 'lw-btn-secondary', function () { clear(check); if (!mat) { status('error', 'Choisissez le produit fini.'); return; } get(sp('/prod-check'), { material_id: mat.id, qty: q.value, start: start.value }).then(function (rows) { append(check, availTable(rows, start.value)); }).catch(function (e) { append(check, msgBox('error', e.message)); }); }),
			btn('Créer', 'lw-btn-key', function () { act(msg, post(sp('/prod'), { material_id: mat ? mat.id : 0, qty: q.value, start: start.value }), function (d) { prodView(d.no, out); }); })), check), 'ink'), out);
		if (p.material) { get(sp('/materials/' + encodeURIComponent(p.material))).then(function (m) { mat = { id: m.id, no: m.no }; fm.input.value = m.no; }).catch(function () {}); }
		if (p.no) { prodView(p.no, out); } else { append(out, docPicker('prod', 'Ordres de fabrication', function (no) { prodView(no, out); }, false)); }
	});
	function availTable(rows, start) {
		var LBL = { DISPO: ['green', 'Disponible'], PREVU: ['yellow', 'Réception prévue avant le début'], RETARD: ['red', 'Réception prévue trop tard'], MANQUANT: ['red', 'Non couvert : à commander'] };
		return h('div', null, h('h4', { text: 'Contrôle de disponibilité au ' + date(start) }), table(['Composant', 'Besoin', 'Disponible aujourd\'hui', 'Disponible au début', 'Disponible le', 'Statut'], rows.map(function (r) {
			var l = LBL[r.status] || ['', r.status];
			return { cells: [matCell(r.material), qty(r.need), qty(r.now), qty(r.by_start), r.date ? date(r.date) : '—', h('span', { class: 'lw-badge ' + l[0], text: l[1] })] };
		}), [1, 2, 3]));
	}
	tx('CO02', 'Lancer / modifier un ordre de fabrication', 'Production', 'PP', function (el, p) {
		var out = h('div');
		append(el, out);
		if (p.no) { prodView(p.no, out); } else { append(out, docPicker('prod', 'Ordres de fabrication', function (no) { prodView(no, out); })); }
	});
	tx('LP10', 'Mise à disposition production (bord de ligne)', 'Production', 'PP', function (el, p) {
		var out = h('div');
		append(el, card(null, h('p', { class: 'lw-small', text: 'Choisissez un ordre lancé : un ordre de transfert amène les composants des emplacements de stockage (FIFO / FEFO) au bord de ligne (magasin 500). Confirmez-le avec LT12 ou le terminal mobile, puis consommez (CO11N).' })), out);
		if (p.no) { prodView(p.no, out); } else { append(out, docPicker('prod', 'Ordres de fabrication ouverts', function (no) { prodView(no, out); })); }
	});
	tx('CO11N', 'Confirmer un ordre de fabrication', 'Production', 'PP', function (el, p) {
		var out = h('div');
		append(el, out);
		if (p.no) { prodView(p.no, out); } else { append(out, docPicker('prod', 'Ordres à confirmer', function (no) { prodView(no, out); })); }
	});

	/* ════════════════════════ Inventaire ════════════════════════ */

	tx('MI01', 'Créer un document d\'inventaire', 'Inventaire', 'CNT', function (el) {
		var msg = h('div');
		var scope = sel([['location', 'Magasin complet'], ['aisle', 'Allée (ex. 110-A01)'], ['material', 'Article'], ['abc', 'Classe ABC (A, B ou C)']], 'location');
		var value = inp({ class: 'lw-input code', placeholder: 'ex. 180' });
		append(el, msg, card('Périmètre', h('div', null, h('p', { class: 'lw-small lw-muted', text: 'Les emplacements du périmètre sont gelés jusqu\'à la comptabilisation : aucun mouvement possible. Maximum 200 lignes (inventaire tournant).' }), h('div', { class: 'lw-form' }, field('Type', scope), field('Valeur', value), btn('Créer le document', 'lw-btn-key', function () { act(msg, post(sp('/inv'), { scope: scope.value, value: value.value.trim() }), function (d) { go('MI04', { no: d.no }); }); })))), docPicker('inv', 'Documents d\'inventaire', function (no) { go('MI04', { no: no }); }, false));
	});
	function invView(no, out, mode, notice) {
		clear(out); append(out, loading());
		get(sp('/inv/' + encodeURIComponent(no))).then(function (d) {
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			var inputs = {};
			var open = d.status === 'OUVERT' && !S.session.readonly;
			var rows = d.items.map(function (i) {
				var q = num({ value: i.counted_qty !== null ? i.counted_qty : '', 'aria-label': 'Quantité comptée ' + i.material.no });
				inputs[i.id] = q;
				var look = btn('Aller compter', 'lw-btn-secondary lw-btn-sm', function () {
					post(sp('/inv/' + encodeURIComponent(d.inv_no) + '/look'), { item_id: i.id }).then(function (j) {
						modal('Au rayonnage ' + (i.bin || i.location), h('div', null, h('p', { text: 'Vous comptez physiquement :' }), h('p', { style: { fontSize: '34px', fontWeight: '700', margin: '6px 0' }, text: qty(j.data.qty) + ' ' + i.material.uom }), h('p', { class: 'lw-small lw-muted', text: i.material.no + ' ' + i.material.designation + (i.batch ? ' · lot ' + i.batch : '') })));
					}).catch(function (e) { status('error', e.message); });
				});
				return { cls: i.recount ? 'selected' : '', cells: [h('span', { class: 'lw-mono', text: i.bin || i.location }), matCell(i.material), i.batch, d.blind ? h('span', { class: 'lw-muted', text: 'masqué' }) : qty(i.book_qty), open ? h('div', { class: 'lw-inline' }, q, look) : qty(i.counted_qty), i.diff !== null ? h('span', { class: i.diff ? 'lw-diff' : '', text: (i.diff > 0 ? '+' : '') + qty(i.diff) }) : '', i.value !== null ? chf(i.value) : '', i.recount ? h('span', { class: 'lw-badge yellow', text: 'Recompter' }) : ''] };
			});
			var doc = card(null, [h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Inventaire ' + d.inv_no + ' – ' + d.scope }), badge(d.status)),
				d.blind ? h('p', { class: 'lw-small lw-muted', text: 'Comptage à l\'aveugle : le stock théorique est masqué jusqu\'à ce que toutes les lignes soient comptées.' }) : null,
				table(['Emplacement', 'Article', 'Lot', 'Stock théorique', 'Compté', 'Écart', 'Valeur écart CHF', ''], rows, [3, 5, 6])]);
			append(out, msg, doc);
			if (open) {
				append(out, h('div', { class: 'lw-actions' },
					btn('Enregistrer le comptage', 'lw-btn-primary', function () { act(msg, post(sp('/inv/' + encodeURIComponent(d.inv_no) + '/count'), { counts: Object.keys(inputs).map(function (id) { return { item_id: id, qty: inputs[id].value }; }).filter(function (c) { return c.qty !== ''; }) }), function (x, m) { invView(d.inv_no, out, mode, m); }); }),
					btn('Comptabiliser les écarts (MI07)', 'lw-btn-key', function () { act(msg, post(sp('/inv/' + encodeURIComponent(d.inv_no) + '/post')), function (x, m) { invView(d.inv_no, out, mode, m); refreshSession(); }); }),
					printBtn(doc)));
			}
		}).catch(function (e) { fail(out, e); });
	}
	tx('MI04', 'Saisir un comptage', 'Inventaire', 'CNT', function (el, p) {
		var out = h('div');
		append(el, out);
		if (p.no) { invView(p.no, out); } else { append(out, docPicker('inv', 'Documents d\'inventaire ouverts', function (no) { invView(no, out); })); }
	});
	tx('MI07', 'Comptabiliser les écarts', 'Inventaire', 'CNT', function (el, p) {
		var out = h('div');
		append(el, out);
		if (p.no) { invView(p.no, out); } else { append(out, docPicker('inv', 'Documents d\'inventaire', function (no) { invView(no, out); }, false)); }
	});

	/* ════════════════════════ Retours ════════════════════════ */

	tx('ZRET', 'Retours clients', 'Retours', 'RET', function (el, p) {
		var tabs = {};
		tabs['Enregistrer un retour (651)'] = function () {
			var out = h('div');
			var msg = h('div');
			var wrap = h('div', null, docPicker('so', 'Commande d\'origine (livrée)', function (no) { load(no); }, false), out);
			function load(no) {
				clear(out);
				get(sp('/so/' + encodeURIComponent(no))).then(function (so) {
					var items = so.items.filter(function (i) { return i.qty_delivered > 0; });
					if (!items.length) { append(out, msgBox('warn', 'Rien n\'a encore été livré sur cette commande.')); return; }
					var it = sel(items.map(function (i) { return [i.material.id, i.material.no + ' ' + i.material.designation + ' (livré ' + qty(i.qty_delivered) + ')']; }), '');
					var q = num();
					var batch = inp({ class: 'lw-input code', placeholder: 'Lot (si géré par lot)' });
					var reason = inp({ placeholder: 'Motif (erreur de commande, avarie…)', maxlength: '120' });
					clear(msg); append(out, card('Retour sur la commande ' + so.so_no, h('div', null, msg, h('div', { class: 'lw-form' }, field('Article', it), field('Quantité', q), field('Lot', batch), field('Motif', reason)), h('p', { class: 'lw-small lw-muted', text: 'Le retour entre en quarantaine (magasin 800, stock bloqué). Saisissez une ligne par motif.' }), h('div', { class: 'lw-actions' }, btn('Enregistrer le retour', 'lw-btn-key', function () { act(msg, post(sp('/ret'), { so_no: so.so_no, material_id: it.value, qty: q.value, batch: batch.value.trim(), reason: reason.value }), function (d) { append(msg, h('p', null, 'Traiter ce retour : ', link(d.no, 'ZRET', { no: d.no }))); q.value = ''; reason.value = ''; batch.value = ''; }); })))));
				}).catch(function (e) { fail(out, e); });
			}
			return wrap;
		};
		tabs['Traiter les retours'] = function () {
			var out = h('div');
			function view(no, notice) {
				clear(out); append(out, loading());
				get(sp('/ret/' + encodeURIComponent(no))).then(function (r) {
					clear(out);
					var msg = h('div', null, notice ? msgBox('ok', notice) : null);
					append(out, msg, card('Retour ' + r.ret_no + ' – commande ' + r.ref_no, table(['Article', 'Quantité', 'Lot', 'Motif', 'Décision'], r.items.map(function (i) {
						if (i.decision || S.session.readonly) { return { cells: [matCell(i.material), qty(i.qty), i.batch, i.reason, i.decision ? h('span', { class: 'lw-badge green', text: { RESTOCK: 'Remis en stock', SCRAP: 'Rebut', SUPPLIER: 'Retour fournisseur' }[i.decision] || i.decision }) : ''] }; }
						var dec = sel([['RESTOCK', 'Remettre en stock libre (453)'], ['SCRAP', 'Mettre au rebut (551)'], ['SUPPLIER', 'Retourner au fournisseur (161)']], 'RESTOCK');
						var target = inp({ class: 'lw-input code', placeholder: S.session.mode === 'SIMPLE' ? 'Magasin' : 'Emplacement de destination' });
						dec.addEventListener('change', function () { target.style.display = dec.value === 'RESTOCK' ? '' : 'none'; });
						return { cells: [matCell(i.material), qty(i.qty), i.batch, i.reason, h('div', { class: 'lw-inline' }, dec, target, btn('Valider', 'lw-btn-key lw-btn-sm', function () { act(msg, post(sp('/ret/' + encodeURIComponent(r.ret_no) + '/decide'), { item_id: i.id, decision: dec.value, target: target.value.trim() }), function (x, m) { view(r.ret_no, m); }); }))] };
					}), [1])));
				}).catch(function (e) { fail(out, e); });
			}
			var wrap = h('div', null, docPicker('ret', 'Retours', function (no) { view(no); }, false), out);
			if (p.no) { view(p.no); }
			return wrap;
		};
		append(el, tabbed(tabs));
		if (p.no) { el.querySelectorAll('.lw-tabs button')[1].click(); }
	});

	/* ════════════════════════ Planification des besoins ════════════════════════ */

	var PROC = { E: 'Achat', F: 'Fabrication' };
	function md04View(no, out) {
		clear(out); append(out, loading());
		get(sp('/md04/' + encodeURIComponent(no))).then(function (d) {
			clear(out);
			var head = h('div', { class: 'lw-grid2' },
				dl([['Article', matCell(d.material)], ['Approvisionnement', (PROC[d.procure] || d.procure) + (d.supplier ? ' · ' + d.supplier.no + ' ' + d.supplier.name : '')], ['Délai', d.lead_time + ' jour' + (d.lead_time > 1 ? 's' : '')]]),
				dl([['Point de commande', qty(d.reorder)], ['Lot économique', qty(d.lot_size)], ['Disponible final', h('strong', { class: d.final < 0 ? 'lw-diff' : '', text: qty(d.final) })], ['Première rupture', d.shortage ? h('span', { class: 'lw-badge red', text: date(d.shortage) }) : 'Aucune']]));
			append(out, card('Liste stock/besoins – ' + d.material.no, [head,
				table(['Date', 'Élément', 'Document', 'Partenaire', 'Entrée / sortie', 'Disponible', 'Message'], d.rows.map(function (r) {
					var doc = r.type === 'CA' ? link(r.no, 'ME23N', { no: r.no }) : r.type === 'CC' ? link(r.no, 'VA03', { no: r.no }) : (r.type === 'OF' || r.type === 'BC') ? link(r.no, 'CO02', { no: r.no }) : '';
					return { cls: r.avail < 0 ? 'expired' : '', cells: [date(r.date), r.label, doc, r.partner || '', r.type === 'ST' ? '' : (r.qty > 0 ? '+' : '') + qty(r.qty), h('strong', { text: qty(r.avail) }), r.exception ? h('span', { class: 'lw-badge red', text: r.exception }) : ''] };
				}), [4, 5]),
				h('p', { class: 'lw-small lw-muted', text: 'Entrées : commandes d\'achat (date de livraison) et ordres de fabrication (fin = début + 1 jour). Sorties : commandes clients (date souhaitée) et besoins en composants des ordres de fabrication (date de début).' })]));
		}).catch(function (e) { fail(out, e); });
	}
	tx('MD04', 'Liste stock/besoins', 'Planification', 'MRP|PP|PUR', function (el, p) {
		var out = h('div');
		var f = f4Field('Article', 'material', function (r) { md04View(r.no, out); }, 'N° d\'article ou EAN — F4 pour chercher');
		append(el, card(null, h('div', { class: 'lw-form' }, f)), out);
		if (p.material) { md04View(p.material, out); }
	});
	function mrpView(material, out) {
		clear(out); append(out, loading());
		get(sp('/mrp'), { material: material || '' }).then(function (rows) {
			clear(out);
			if (!rows.length) { append(out, msgBox('ok', 'Aucune rupture prévue : les besoins sont couverts par le stock, les commandes et les ordres en cours.')); return; }
			append(out, card('Propositions d\'approvisionnement (' + rows.length + ')', [
				h('p', { class: 'lw-small lw-muted', text: 'Le calcul ne crée rien : convertissez vous-même chaque proposition en commande d\'achat (ME21N) ou en ordre de fabrication (CO01). Vérifiez la quantité minimale et le délai.' }),
				table(['Article', 'Proposition', 'Besoin', 'Pour', 'Manque', 'Quantité proposée', 'Début / commande', 'Disponible le', 'Message', ''], rows.map(function (r) {
					var act2 = r.type === 'E' ? btn('Commander (ME21N)', 'lw-btn-secondary lw-btn-sm', function () { go('ME21N', { supplier_id: r.supplier ? r.supplier.id : '', material: r.material.no, qty: r.qty }); }) : btn('Créer l\'OF (CO01)', 'lw-btn-secondary lw-btn-sm', function () { go('CO01', { material: r.material.no, qty: r.qty, start: r.start }); });
					return { cls: r.exception ? 'expired' : '', cells: [matCell(r.material), r.type_label + (r.supplier ? ' · ' + r.supplier.name : ''), date(r.need_date), r.need_ref, qty(r.shortage), h('strong', { text: qty(r.qty) + ' ' + r.material.uom }), date(r.start), date(r.avail), r.exception ? h('span', { class: 'lw-badge red', text: r.exception }) : '', S.session.readonly ? '' : act2] };
				}), [4, 5])]));
		}).catch(function (e) { fail(out, e); });
	}
	tx('MD01', 'Calcul des besoins (tous les articles)', 'Planification', 'MRP|PP', function (el) {
		var out = h('div');
		append(el, card(null, [h('p', { class: 'lw-small', text: 'Calcul net des besoins pour tous les articles de vos commandes clients et ordres de fabrication, avec explosion des nomenclatures.' }), btn('Lancer le calcul des besoins', 'lw-btn-key', function () { mrpView('', out); })]), out);
	});
	tx('MD02', 'Calcul des besoins (un article)', 'Planification', 'MRP|PP', function (el, p) {
		var out = h('div');
		var f = f4Field('Article', 'material', function (r) { mrpView(r.no, out); }, 'Produit fini : ses composants sont aussi planifiés');
		append(el, card(null, h('div', { class: 'lw-form' }, f)), out);
		if (p.material) { mrpView(p.material, out); }
	});

	/* ════════════════════════ Qualité ════════════════════════ */

	var QA_STATUS_CLS = { OUVERT: 'yellow', ECHANTILLON: 'yellow', ANALYSE: '', DECIDE: 'green' };
	function qaList(out, onPick) {
		var box = h('div', null, loading());
		get(sp('/qa')).then(function (rows) {
			clear(box);
			if (!rows.length) { append(box, h('p', { class: 'lw-muted', text: 'Aucun lot de contrôle. Ils sont créés à la réception en « contrôle qualité », quand une date limite est atteinte ou lors d\'une mise en quarantaine.' })); return; }
			append(box, table(['Lot de contrôle', 'Origine', 'Article', 'Lot', 'Date limite', 'Quantité', 'Statut', 'Décision'], rows.map(function (r) {
				return { cells: [h('span', { class: 'lw-mono', text: r.lot_no }), r.origin_label + (r.ref_no ? ' · ' + r.ref_no : ''), matCell(r.material), r.batch || '—', r.bbd ? [date(r.bbd), r.expired ? h('span', { class: 'lw-badge red', text: 'dépassée' }) : null] : '—', qty(r.qty) + ' ' + r.material.uom, h('span', { class: 'lw-badge ' + (QA_STATUS_CLS[r.status] || ''), text: r.status_label }), r.decision_label || ''], onclick: function () { onPick(r.lot_no); } };
			}), [5]));
		}).catch(function (e) { fail(box, e); });
		append(out, card('Lots de contrôle', box));
	}
	function qaView(no, out, notice) {
		clear(out); append(out, loading());
		get(sp('/qa/' + encodeURIComponent(no))).then(function (l) {
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			append(out, msg, card('Lot de contrôle ' + l.lot_no, h('div', { class: 'lw-grid2' },
				dl([['Article', matCell(l.material)], ['Origine', l.origin_label + (l.ref_no ? ' (' + l.ref_no + ')' : '')], ['Lot', l.batch || '—'], ['Date limite', l.bbd ? date(l.bbd) + (l.expired ? ' (dépassée)' : '') : '—'], ['Produit périssable', l.perishable ? 'Oui : aucune prolongation possible' : 'Non']]),
				dl([['Quantité du lot', qty(l.qty) + ' ' + l.material.uom], ['Statut du stock', badge(l.stock_status)], ['Échantillon', l.sample_qty ? qty(l.sample_qty) + ' ' + l.material.uom : (l.sample_to ? 'à prélever (OT ' + l.sample_to + ')' : 'non prélevé')], ['Étape', h('span', { class: 'lw-badge ' + (QA_STATUS_CLS[l.status] || ''), text: l.status_label })], l.decision ? ['Décision', l.decision_label + (l.decision_text ? ' – ' + l.decision_text : '')] : null])), 'ink'));
			if (l.rows.length) { append(out, card('Stock du lot', table(['Magasin', 'Emplacement', 'Statut', 'Quantité'], l.rows.map(function (r) { return { cells: [r.location, h('span', { class: 'lw-mono', text: r.bin || '—' }), badge(r.status), qty(r.qty)] }; }), [3]))); }
			if (l.report) {
				append(out, h('div', { class: 'lw-paper lw-lab' }, h('h4', { text: l.origin === 'MAN' ? 'Constat de mise en quarantaine' : 'Rapport du laboratoire – lot ' + (l.batch || l.lot_no) }), h('p', { text: l.report.text }), h('p', { class: 'lw-small lw-muted', text: 'Lisez le rapport et saisissez vous-même le résultat dans la décision d\'emploi.' })));
			}
			if (S.session.readonly || l.status === 'DECIDE') { return; }
			if (l.status === 'OUVERT' && l.origin !== 'MAN') {
				var sq = num({ value: 1, min: '1', step: '1' });
				append(out, card('1. Échantillonnage', h('div', { class: 'lw-form' }, field('Quantité d\'échantillon', sq, '20 % du lot au maximum'), btn('Demander l\'échantillon', 'lw-btn-key', function () {
					act(msg, post(sp('/qa/' + encodeURIComponent(l.lot_no) + '/sample'), { qty: sq.value }), function (d) { qaView(l.lot_no, out, d.message); });
				})), 'yellow'));
				return;
			}
			if (l.status === 'ECHANTILLON') {
				append(out, msgBox('info', 'Échantillon à prélever : confirmez l\'ordre de transfert ' + l.sample_to + ' (LT12 ou terminal mobile). Le rapport du laboratoire sera ensuite disponible.'), h('div', { class: 'lw-actions' }, btn('Prélever (LT12)', 'lw-btn-key', function () { go('LT12', { no: l.sample_to }); })));
				return;
			}
			var res = sel([['', '— résultat —'], ['OK', 'Conforme'], ['KO', 'Non conforme']], '');
			var rtext = inp({ placeholder: 'Constat / valeurs relevées', maxlength: '255' });
			var dec = sel([['', '— décision —']].concat(Object.keys(QA_DEC).filter(function (k) { return k !== 'RETOUR' || l.can_return; }).map(function (k) { return [k, QA_DEC[k]]; })), '');
			var dtext = inp({ placeholder: 'Motif (obligatoire sauf libération)', maxlength: '255' });
			var nbbd = inp({ type: 'date' });
			var bin = inp({ class: 'lw-input code', placeholder: '800-Q-01' });
			var extra = h('div');
			function upd() {
				clear(extra);
				if (dec.value === 'LIBERER' && l.expired) { append(extra, field('Nouvelle date de réanalyse', nbbd, 'Uniquement pour un produit stable, après analyse conforme (180 jours au plus).')); }
				if (dec.value === 'QUARANTAINE' && S.session.mode !== 'SIMPLE') { append(extra, field('Emplacement de quarantaine (zone 800)', bin)); }
			}
			dec.addEventListener('change', upd);
			append(out, card('2. Décision d\'emploi (QA11)', h('div', null, h('div', { class: 'lw-form' }, field('Résultat du contrôle', res), field('Constat', rtext), field('Décision', dec), field('Motif', dtext)), extra,
				h('div', { class: 'lw-actions' }, btn('Enregistrer la décision', 'lw-btn-key', function () {
					act(msg, post(sp('/qa/' + encodeURIComponent(l.lot_no) + '/decide'), { result: res.value, result_text: rtext.value, decision: dec.value, text: dtext.value, new_bbd: nbbd.value, bin: bin.value.trim() }), function (d) { qaView(l.lot_no, out, d.message); refreshSession(); });
				}))), 'yellow'));
		}).catch(function (e) { fail(out, e); });
	}
	var QA_DEC = { LIBERER: 'Libérer (stock libre)', BLOQUER: 'Confirmer le blocage', QUARANTAINE: 'Bloquer et mettre en quarantaine (zone 800)', DETRUIRE: 'Détruire (551)', RETOUR: 'Retourner au fournisseur (122)' };
	tx('QA32', 'Lots de contrôle qualité', 'Qualité', 'QM|GR|INV', function (el, p) {
		var out = h('div');
		append(el, out);
		if (p.no) { qaView(p.no, out); } else { qaList(out, function (no) { go('QA11', { no: no }); }); }
	});
	tx('QA11', 'Décision d\'emploi', 'Qualité', 'QM|GR|INV', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: 'N° de lot de contrôle', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { qaView(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Lot de contrôle', input), btn('Afficher', 'lw-btn-primary', function () { qaView(input.value.trim(), out); }))), out);
		if (p.no) { qaView(p.no, out); } else { qaList(out, function (no) { input.value = no; qaView(no, out); }); }
	});
	tx('MB5M', 'Liste des dates limites (DLC)', 'Qualité', '', function (el) {
		var out = h('div');
		var days = sel([['0', 'Dépassées uniquement'], ['7', 'Dans les 7 jours'], ['30', 'Dans les 30 jours'], ['90', 'Dans les 90 jours']], '30');
		function run() {
			clear(out); append(out, loading());
			get(sp('/shelf-life'), { days: days.value }).then(function (rows) {
				clear(out);
				append(out, table(['Article', 'Magasin', 'Emplacement', 'Lot', 'Date limite', 'Jours restants', 'Statut', 'Quantité'], rows.map(function (r) {
					return { cls: r.days_left < 0 ? 'expired' : '', cells: [matCell(r.material), r.location, h('span', { class: 'lw-mono', text: r.bin }), r.batch, date(r.bbd), h('span', { class: r.days_left < 0 ? 'lw-badge red' : r.days_left <= 7 ? 'lw-badge yellow' : '', text: r.days_left < 0 ? 'dépassée (' + r.days_left + ')' : String(r.days_left) }), badge(r.status), qty(r.qty) + ' ' + r.material.uom] };
				}), [5, 7]));
				if (!rows.length) { append(out, h('p', { class: 'lw-muted', text: 'Aucun lot dans cette période.' })); }
			}).catch(function (e) { fail(out, e); });
		}
		days.addEventListener('change', run);
		append(el, card(null, h('div', { class: 'lw-form' }, field('Période', days), btn('Afficher', 'lw-btn-primary', run))), out);
		run();
	});
	tx('LT10', 'Déplacer du stock (quarantaine, transfert)', 'Entrepôt', 'WH|INV|QM', function (el, p) {
		var area = h('div');
		var picker = stockPicker(function (x) {
			clear(area);
			var msg = h('div');
			var q = num({ value: x.qty });
			var dst = inp({ class: 'lw-input code', placeholder: S.session.mode === 'SIMPLE' ? 'Magasin (ex. 800)' : 'Emplacement (ex. 800-Q-01)' });
			var reason = inp({ placeholder: 'Motif', maxlength: '120' });
			append(area, stockSummary(x), msg, h('div', { class: 'lw-form' }, field('Quantité', q), field('Destination', dst), field('Motif', reason)),
				h('div', { class: 'lw-actions' },
					btn('Transférer', 'lw-btn-primary', function () { var simple = S.session.mode === 'SIMPLE'; act(msg, post(sp('/stock/transfer'), { stock_id: x.id, qty: q.value, location: simple ? dst.value.trim() : '', bin: simple ? '' : dst.value.trim() })); }),
					btn('Mettre en quarantaine (bloqué, zone 800)', 'lw-btn-danger', function () { act(msg, post(sp('/stock/quarantine'), { stock_id: x.id, qty: q.value, bin: dst.value.trim(), reason: reason.value }), function (d) { if (d.lot_no) { toast('Quarantaine', 'Lot de contrôle ' + d.lot_no + ' créé : décision à prendre dans QA11.'); } }); })));
		}, { material: p.material || '', bin: p.bin || '' });
		append(el, card('Choisir la ligne de stock', picker), area);
	});

	/* ════════════════════════ Transport et emballages consignés ════════════════════════ */

	var SUPPORTS = [['EUR', 'Palette EUR 1200×800 (échange)'], ['CP3', 'Palette chimique CP3 (consignée)'], ['PERDUE', 'Palette perdue'], ['COLIS', 'Colis / cartons']];
	function rulesCell(r) {
		return h('div', { class: 'lw-small' }, h('div', null, '📅 ' + r.days_label + ' · 🕘 ' + r.window), h('div', null, (r.pallet ? '▣ ' + r.pallet_label : '▣ support indifférent') + (r.vehicle ? ' · 🚚 ' + r.vehicle_label : '') + (r.appointment ? ' · rendez-vous' : '')));
	}
	function tourPlanView(el) {
		var msg = h('div');
		var out = h('div');
		var chosen = [];
		var ready = [];
		var vehicles = [];
		var veh = sel([], '');
		var day = inp({ type: 'date', value: S.session.sim_date });
		var dep = inp({ type: 'time', value: '06:30' });
		var listBox = h('div');
		var stopsBox = h('div');
		function drawReady() {
			clear(listBox);
			var left = ready.filter(function (d) { return chosen.indexOf(d.no) < 0; });
			if (!left.length) { append(listBox, h('p', { class: 'lw-muted', text: ready.length ? 'Toutes les livraisons sont dans la tournée.' : 'Aucune livraison emballée en attente. Emballez les livraisons (VL02N) avec « tournée planifiée ».' })); return; }
			append(listBox, table(['Livraison', 'Client', 'Exigences', 'Poids', 'Palettes', 'Support', ''], left.map(function (d) {
				return { cells: [h('span', { class: 'lw-mono', text: d.no }), [h('strong', { text: d.customer.name }), h('div', { class: 'lw-small', text: d.customer.city })], rulesCell(d.rules), qty(Math.round(d.weight * 10) / 10) + ' kg', d.pallets || d.packages + ' colis', [d.support, d.adr ? h('span', { class: 'lw-chip adr', text: 'ADR' }) : null, d.cold ? h('span', { class: 'lw-chip cold', text: 'Froid' }) : null], btn('Ajouter', 'lw-btn-secondary lw-btn-sm', function () { chosen.push(d.no); drawReady(); drawStops(); })] };
			}), [3]));
		}
		function drawStops() {
			clear(stopsBox); clear(out);
			if (!chosen.length) { append(stopsBox, h('p', { class: 'lw-muted', text: 'Ajoutez les livraisons dans l\'ordre de passage.' })); return; }
			append(stopsBox, h('ol', { class: 'lw-stops' }, chosen.map(function (no, i) {
				var d = ready.filter(function (x) { return x.no === no; })[0];
				return h('li', null, h('span', null, h('strong', { text: d.customer.name }), ' · ' + d.customer.city + ' · ' + d.rules.window), h('span', { class: 'lw-inline' },
					btn('▲', 'lw-btn-secondary lw-btn-sm', function () { if (i > 0) { chosen.splice(i - 1, 0, chosen.splice(i, 1)[0]); drawStops(); } }, { title: 'Monter', 'aria-label': 'Monter' }),
					btn('▼', 'lw-btn-secondary lw-btn-sm', function () { if (i < chosen.length - 1) { chosen.splice(i + 1, 0, chosen.splice(i, 1)[0]); drawStops(); } }, { title: 'Descendre', 'aria-label': 'Descendre' }),
					btn('Retirer', 'lw-btn-secondary lw-btn-sm', function () { chosen.splice(i, 1); drawReady(); drawStops(); })));
			})));
		}
		function body() { return { vehicle_id: veh.value, date: day.value, depart: dep.value, deliveries: chosen }; }
		function showPlan(pl) {
			clear(out);
			var v = pl.vehicle;
			append(out, card('Itinéraire calculé', [
				h('div', { class: 'lw-grid3' }, dl([['Véhicule', v.name], ['Capacité', qty(v.payload) + ' kg · ' + v.places + ' palettes'], ['Équipement', [v.tail_lift ? 'hayon' : 'sans hayon', v.cold ? 'frigorifique' : '', v.adr ? 'ADR' : ''].filter(Boolean).join(', ')]]), dl([['Date', pl.weekday + ' ' + date(pl.date)], ['Départ / retour', pl.depart + ' → ' + pl.return], ['Durée', pl.duration]]), dl([['Distance', qty(pl.km) + ' km'], ['Chargement', qty(pl.weight) + ' kg (' + pl.load_pct + ' %) · ' + pl.pallets + ' pal.'], ['Coût estimé', h('strong', { text: 'CHF ' + chf(pl.cost) })]])),
				table(['Arrêt', 'Client', 'Plage', 'km', 'Arrivée', 'Attente', 'Départ', 'Contrôle'], pl.stops.map(function (s) {
					return { cls: s.issues.length ? 'expired' : '', cells: [s.seq, s.delivery.customer.name, s.delivery.rules.window, qty(s.km), h('strong', { text: s.eta }), s.wait ? s.wait + ' min' : '', s.etd, s.issues.length ? h('span', { class: 'lw-badge red', text: s.issues.length + ' problème' + (s.issues.length > 1 ? 's' : '') }) : h('span', { class: 'lw-badge green', text: 'OK' })] };
				}), [3]),
				pl.issues.length ? msgBox('error', 'Tournée non conforme', pl.issues) : msgBox('ok', 'Toutes les exigences sont respectées : vous pouvez enregistrer la tournée.')]));
		}
		var calc = btn('Calculer l\'itinéraire', 'lw-btn-primary', function () {
			clear(msg);
			post(sp('/tour-plan'), body()).then(function (j) { showPlan(j.data); }).catch(function (e) { append(msg, msgBox('error', e.message, e.details)); });
		});
		var save = btn('Enregistrer la tournée (VT01N)', 'lw-btn-key', function () {
			act(msg, post(sp('/tours'), body()), function (d) { chosen = []; load(); toast('Tournée', 'Tournée ' + d.no + ' enregistrée : départ dans VT02N.'); });
		});
		function load() {
			Promise.all([get(sp('/tour-ready')), get(sp('/vehicles'))]).then(function (r) {
				ready = r[0]; vehicles = r[1];
				clear(veh);
				vehicles.forEach(function (v) { veh.appendChild(h('option', { value: v.id, text: (v.owner === 'INTERNE' ? 'Flotte · ' : 'Prestataire · ') + v.name + ' · ' + qty(v.payload) + ' kg, ' + v.places + ' pal.' + (v.cold ? ', froid' : '') + (v.adr ? ', ADR' : '') + (v.tail_lift ? ', hayon' : '') })); });
				drawReady(); drawStops();
			}).catch(function (e) { fail(listBox, e); });
		}
		append(el, msg, card('Livraisons à planifier', listBox),
			card('Tournée', h('div', null, h('div', { class: 'lw-form' }, field('Véhicule', veh), field('Date', day), field('Départ du dépôt', dep)), h('h4', { text: 'Ordre des arrêts' }), stopsBox, h('div', { class: 'lw-actions' }, calc, S.session.readonly ? null : save)), 'ink'), out);
		load();
	}
	tx('VT01N', 'Planifier une tournée de transport', 'Transport', 'TRA|SHIP', function (el) { tourPlanView(el); });

	function tourView(no, out, notice) {
		clear(out); append(out, loading());
		get(sp('/tours/' + encodeURIComponent(no))).then(function (t) {
			clear(out);
			var msg = h('div', null, notice ? msgBox('ok', notice) : null);
			var v = t.vehicle || {};
			var doc = card('Tournée ' + t.no + ' – ' + v.name, [
				h('div', { class: 'lw-grid3' }, dl([['Statut', badge(t.status)], ['Date', date(t.date)], ['Départ / retour prévu', t.depart + ' → ' + t.return]]), dl([['Distance', qty(t.km) + ' km'], ['Durée', Math.floor(t.minutes / 60) + ' h ' + String(t.minutes % 60).padStart(2, '0')], ['Coût estimé', 'CHF ' + chf(t.cost)]]), dl([['Véhicule', v.type_label + (v.owner === 'EXTERNE' ? ' (prestataire)' : ' (flotte)')], ['Tarif', v.tariff]])),
				table(['Arrêt', 'Client', 'Adresse', 'Livraison', 'Arrivée', 'Départ', 'Emballages chez le client'], t.stops.map(function (s) {
					return { cells: [s.seq, s.delivery.customer.name, s.delivery.customer.street + ', ' + s.delivery.customer.city, link(s.delivery.no, 'VL02N', { no: s.delivery.no }), h('strong', { text: s.eta }), s.etd, s.balances.map(function (b) { return b.material.designation.split(' (')[0] + ' : ' + qty(b.qty); }).join(' · ') || '—'] };
				}))]);
			append(out, msg, doc);
			var acts = h('div', { class: 'lw-actions' }, printBtn(doc));
			append(out, acts);
			if (S.session.readonly) { return; }
			if (t.status === 'PLANIFIE') {
				append(acts, btn('Départ du camion : sortie de marchandises (601) et emballages remis (621)', 'lw-btn-key', function () { act(msg, post(sp('/tours/' + encodeURIComponent(t.no) + '/depart')), function (d) { tourView(t.no, out, d.message); refreshSession(); if (out.reloadList) { out.reloadList(); } }); }));
			}
			if (t.status === 'EN_ROUTE') {
				var rows = [];
				var cards = t.stops.map(function (s) {
					var lines = s.report ? s.report.lines : [];
					var mats = {};
					s.balances.forEach(function (b) { mats[b.material.id] = b.material; });
					return h('div', { class: 'lw-paper' }, h('h4', { text: 'Arrêt ' + s.seq + ' · ' + s.delivery.customer.name }),
						h('p', { class: 'lw-small', text: 'Bon de retour signé par le chauffeur (' + (s.report ? s.report.policy : '') + ') : ' + (lines.length ? lines.map(function (l) { return l.good + l.damaged + ' × ' + l.designation + (l.damaged ? ' dont ' + l.damaged + ' endommagé' + (l.damaged > 1 ? 's' : '') : ''); }).join(' ; ') : 'aucun emballage rendu.') }),
						table(['Emballage', 'Solde client', 'Rendus en bon état', 'Rendus endommagés'], s.balances.map(function (b) {
							var g = num({ value: '', min: '0', step: '1', placeholder: '0', 'aria-label': 'Rendus en bon état' });
							var d2 = num({ value: '', min: '0', step: '1', placeholder: '0', 'aria-label': 'Rendus endommagés' });
							rows.push({ stop_id: s.id, material_id: b.material.id, g: g, d: d2 });
							return { cells: [b.material.designation, qty(b.qty), g, d2] };
						}), [1]));
				});
				append(out, card('Retour du camion : emballages consignés rendus (622)', h('div', null, h('p', { class: 'lw-small lw-muted', text: 'Saisissez exactement ce que le chauffeur rapporte, en séparant les emballages endommagés (stock bloqué au magasin 950).' }), cards,
					h('div', { class: 'lw-actions' }, btn('Clôturer la tournée', 'lw-btn-key', function () {
						act(msg, post(sp('/tours/' + encodeURIComponent(t.no) + '/close'), { returns: rows.map(function (r) { return { stop_id: r.stop_id, material_id: r.material_id, good: r.g.value || 0, damaged: r.d.value || 0 }; }).filter(function (r) { return Number(r.good) + Number(r.damaged) > 0; }) }), function (d) { tourView(t.no, out, d.message); refreshSession(); if (out.reloadList) { out.reloadList(); } });
					}))), 'yellow'));
			}
		}).catch(function (e) { fail(out, e); });
	}
	tx('VT02N', 'Traiter une tournée (départ, retour)', 'Transport', 'TRA|SHIP', function (el, p) {
		var out = h('div');
		var list = h('div', null, loading());
		append(el, card('Tournées', list), out);
		out.reloadList = function () {
			get(sp('/tours')).then(function (rows) {
				clear(list);
				if (!rows.length) { append(list, h('p', { class: 'lw-muted', text: 'Aucune tournée. Planifiez-en une avec VT01N.' })); return; }
				append(list, table(['Tournée', 'Date', 'Départ', 'Véhicule', 'Arrêts', 'km', 'Coût CHF', 'Statut'], rows.map(function (r) { return { cells: [h('span', { class: 'lw-mono', text: r.no }), date(r.date), r.depart, r.vehicle, r.stops, qty(r.km), chf(r.cost), badge(r.status)], onclick: function () { tourView(r.no, out); } }; }), [4, 5, 6]));
			}).catch(function (e) { fail(list, e); });
		};
		out.reloadList();
		if (p.no) { tourView(p.no, out); }
	});
	tx('MB58', 'Emballages consignés chez les clients', 'Transport', 'TRA|SHIP|RET', function (el, p) {
		var out = h('div');
		var cust = null;
		var f = f4Field('Client', 'customer', function (r) { cust = r; run(r.no); }, 'Vide = tous les clients');
		function run(no) {
			clear(out); append(out, loading());
			get(sp('/packaging'), { customer: no || '' }).then(function (d) {
				clear(out);
				var total = d.balances.reduce(function (a, b) { return a + b.value; }, 0);
				append(out, card(d.customer ? 'Compte d\'emballages – ' + d.customer.no + ' ' + d.customer.name : 'Emballages consignés chez les clients', [
					table(['Client', 'Emballage', 'Quantité chez le client', 'Consigne unitaire', 'Valeur CHF'], d.balances.map(function (b) { return { cells: [b.customer.no + ' ' + b.customer.name, b.material.designation, h('strong', { text: qty(b.qty) }), chf(b.unit_value), chf(b.value)] }; }), [2, 3, 4]),
					h('p', { class: 'lw-small', text: 'Valeur totale des consignes : CHF ' + chf(total) })]));
				if (d.customer && !S.session.readonly) {
					var msg = h('div');
					var mat = sel(d.balances.map(function (b) { return [b.material.id, b.material.designation + ' (solde ' + qty(b.qty) + ')']; }), '');
					var g = num({ value: '', step: '1' });
					var bad = num({ value: 0, step: '1' });
					var tx2 = inp({ placeholder: 'Texte (ex. retour au quai par le client)', maxlength: '120' });
					append(out, card('Retour d\'emballages vides au quai (622)', h('div', null, msg, h('div', { class: 'lw-form' }, field('Emballage', mat), field('En bon état', g), field('Endommagés', bad), field('Texte', tx2), btn('Enregistrer', 'lw-btn-key', function () { act(msg, post(sp('/packaging/return'), { customer_id: d.customer.id, material_id: mat.value, good: g.value || 0, damaged: bad.value || 0, text: tx2.value }), function () { run(d.customer.no); }); })))));
					if (d.moves.length) { append(out, card('Mouvements de la session', table(['Date', 'Mouvement', 'Emballage', 'Quantité', 'Endommagés', 'Référence'], d.moves.map(function (m) { return { cells: [new Date(m.at.replace(' ', 'T') + 'Z').toLocaleString('fr-CH'), m.movement === '621' ? '621 remis au client' : '622 rendu', m.material.designation, qty(m.qty), m.damaged ? qty(m.damaged) : '', m.ref_no] }; }), [3, 4]))); }
				}
			}).catch(function (e) { fail(out, e); });
		}
		append(el, card(null, h('div', { class: 'lw-form' }, f, btn('Tous les clients', 'lw-btn-secondary', function () { run(''); }))), out);
		run(p.customer || '');
	});

	/* ════════════════════════ Étiquettes logistiques GS1 (SSCC) ════════════════════════ */

	var BC = window.LogiBarcode || null;

	function gs1Date(d) { return d ? String(d).slice(2, 4) + String(d).slice(5, 7) + String(d).slice(8, 10) : ''; }
	function barcodeBox(svg, cls) { return svg ? h('div', { class: 'lw-bc ' + (cls || '') }, svg) : null; }

	/** Étiquette logistique GS1 d'une unité (palette ou colis) : expéditeur, contenu, codes 02/17/37, 10, 00. */
	function gs1Label(po, item, unit) {
		var dn = item.delivery_note, m = dn.material || item.material;
		var gtin = m && m.ean ? ('00000000000000' + m.ean).slice(-14) : '';
		var co = C.company || { name: 'Destinataire', address: '' };
		var content = gtin ? '(02)' + gtin + (dn.bbd ? '(17)' + gs1Date(dn.bbd) : '') + '(37)' + Math.round(unit.qty) : '';
		var row = function (k, v, big) { return h('div', { class: 'lw-gl-row' + (big ? ' big' : '') }, h('span', { text: k }), h('strong', { text: v })); };
		return h('article', { class: 'lw-gl', 'aria-label': 'Étiquette SSCC ' + unit.sscc },
			h('div', { class: 'lw-gl-parties' },
				h('div', null, h('span', { class: 'lw-gl-k', text: 'Expéditeur · From' }), h('strong', { text: po.supplier ? po.supplier.name : '' }), h('span', { text: po.supplier ? po.supplier.country + '-' + po.supplier.city : '' })),
				h('div', null, h('span', { class: 'lw-gl-k', text: 'Destinataire · To' }), h('strong', { text: co.name }), h('span', { text: String(co.address || '').split('\n').filter(Boolean).join(', ') }))),
			h('div', { class: 'lw-gl-sscc' }, h('span', { text: 'SSCC' }), h('strong', { text: unit.sscc })),
			h('div', { class: 'lw-gl-data' },
				row('Contenu (GTIN)', gtin || '—'),
				row('Article', m ? m.no + ' · ' + m.designation : ''),
				row('Nombre', qty(unit.qty) + ' ' + (m ? m.uom : '') + (unit.cartons > 1 ? ' (' + unit.cartons + ' cartons)' : '')),
				row('Lot', dn.batch || '—'),
				row('Date limite', dn.bbd ? date(dn.bbd) : '—'),
				row(unit.type, unit.n + ' / ' + unit.of),
				row('Commande', po.po_no + ' / pos. ' + item.line_no)),
			h('div', { class: 'lw-gl-codes' },
				content && BC ? barcodeBox(BC.code128Svg(content, { gs1: true, module: 2, height: 64, fontSize: 15 })) : null,
				dn.batch && BC ? barcodeBox(BC.code128Svg('(10)' + dn.batch, { gs1: true, module: 2, height: 54, fontSize: 15 })) : null,
				BC ? barcodeBox(BC.code128Svg('(00)' + unit.sscc, { gs1: true, module: 2, height: 78, fontSize: 16 }), 'sscc') : null));
	}

	function labelsView(no, out) {
		clear(out); append(out, loading());
		get(sp('/po/' + encodeURIComponent(no))).then(function (po) {
			clear(out);
			var labels = [];
			po.items.forEach(function (i) { (i.delivery_note.units || []).forEach(function (u) { labels.push(gs1Label(po, i, u)); }); });
			if (!labels.length) { append(out, msgBox('info', 'La commande ' + po.po_no + ' n\'a plus rien à livrer : aucune étiquette.')); return; }
			var sheet = h('div', { class: 'lw-gl-sheet' }, labels);
			append(out, card(null, [h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: labels.length + ' étiquette' + (labels.length > 1 ? 's' : '') + ' logistique' + (labels.length > 1 ? 's' : '') + ' – commande ' + po.po_no }), h('div', { class: 'lw-inline lw-noprint' }, printBtn(sheet), btn('Réceptionner (MIGO)', 'lw-btn-key', function () { go('MIGO', { po: po.po_no }); }))),
				h('p', { class: 'lw-small lw-muted lw-noprint', text: 'Une étiquette par unité logistique, comme collée par le fournisseur sur chaque palette. Scannez le code SSCC (en bas) avec le terminal mobile pour réceptionner la palette ; le code du haut contient l\'article (GTIN), la date limite et la quantité.' }),
				sheet]));
		}).catch(function (e) { fail(out, e); });
	}

	tx('HU03', 'Étiquettes palettes SSCC (arrivages)', 'Réception et stock', 'GR', function (el, p) {
		var out = h('div');
		var input = inp({ class: 'lw-input code', placeholder: '4500000001', value: p.no || '' });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { labelsView(input.value.trim(), out); } });
		append(el, card(null, h('div', { class: 'lw-inline' }, field('Commande d\'achat', input), btn('Afficher les étiquettes', 'lw-btn-primary', function () { labelsView(input.value.trim(), out); }))), out);
		if (p.no) { labelsView(p.no, out); } else { append(out, docPicker('po', 'Arrivages attendus', function (no) { input.value = no; labelsView(no, out); })); }
	});

	/* ════════════════════════ Terminal mobile (MDE) ════════════════════════ */

	var MDE = { gr: {}, flash: null };
	function isPhone() { try { return window.matchMedia('(max-width: 760px)').matches || (window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(max-width: 1100px)').matches); } catch (e) { return false; } }
	function wedge() { try { return window.localStorage.getItem('logierp_mde_wedge') === '1'; } catch (e) { return false; } }
	function setWedge(on) { try { window.localStorage.setItem('logierp_mde_wedge', on ? '1' : '0'); } catch (e) { /* préférence facultative */ } }
	function mdeHash(view, params) {
		var q = params ? Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] !== undefined && params[k] !== null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
		return '#/mde/' + S.sid + (view ? '/' + view : '') + (q ? '?' + q : '');
	}
	function mgo(view, params) { location.hash = mdeHash(view, params); }

	function icon(name) {
		var P = {
			cam: 'M4 8h3l2-3h6l2 3h3v11H4z M12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
			in: 'M3 13h4l2 3h6l2-3h4 M5 13V5h14v8 M12 6v6 M9 9l3 3 3-3',
			put: 'M4 20V9l8-5 8 5v11 M8 20v-6h8v6 M12 4v5',
			pick: 'M5 4h14v6H5z M5 14h14v6H5z M12 10v4 M9 12l3 2 3-2',
			stock: 'M3 7l9-4 9 4-9 4z M3 7v10l9 4 9-4V7 M12 11v10',
			inv: 'M6 3h12v18H6z M9 8h6 M9 12h6 M9 16h4',
			photo: 'M4 6h16v12H4z M4 15l5-5 4 4 3-3 4 4 M15 9.5a1 1 0 1 0 0-.01',
			pc: 'M3 5h18v11H3z M8 20h8 M12 16v4',
			move: 'M4 12h14 M14 7l5 5-5 5 M4 5v14',
			qa: 'M9 3h6 M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3 M7 15h10',
			scan: 'M4 7V4h3 M17 4h3v3 M20 17v3h-3 M7 20H4v-3 M7 8v8 M10 8v8 M13 8v8 M16 8v8',
			pack: 'M3 19h18 M5 19v-2h14v2 M6 17V9h5v8 M13 17v-5h5v5 M6 12h5 M13 14.5h5'
		};
		return s('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true', class: 'lw-ico' }, s('path', { d: P[name] || P.scan, fill: 'none', stroke: 'currentColor', 'stroke-width': '1.9', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
	}

	/** Cadre plein écran du terminal : en-tête, zone de messages, contenu, barre d'actions. */
	function mdeShell(title, sub, backHash) {
		document.body.classList.add('lw-mde-on');
		clear(root);
		mainEl = null; statusEl = null;
		var back = backHash ? h('button', { type: 'button', class: 'lw-mde-back', 'aria-label': 'Retour', onclick: function () { location.hash = backHash; } }, backIcon()) : h('span', { class: 'lw-mde-back', 'aria-hidden': 'true' });
		var head = h('header', { class: 'lw-mde-head' }, back, h('div', { class: 'lw-mde-ttl' }, h('strong', { text: title }), sub ? h('span', { text: sub }) : null),
			S.session && S.session.sim_date && S.mde ? h('span', { class: 'lw-mde-date', title: 'Date simulée', text: date(S.session.sim_date) }) : null);
		MDE.flash = h('div', { class: 'lw-mde-flash', 'aria-live': 'assertive' });
		var body = h('main', { class: 'lw-mde-body' }, MDE.flash);
		MDE.foot = h('footer', { class: 'lw-mde-foot' });
		append(root, h('div', { class: 'lw-mde' }, head, body, MDE.foot));
		window.scrollTo(0, 0);
		return body;
	}
	function flash(type, text, details) {
		if (!MDE.flash) { return; }
		clear(MDE.flash);
		if (!text) { return; }
		append(MDE.flash, msgBox(type, text, details));
		if (type === 'error' && BC) { BC.vibrate([90, 60, 90]); }
		MDE.flash.scrollIntoView({ block: 'nearest' });
	}
	function foot(buttons) { clear(MDE.foot); append(MDE.foot, buttons); MDE.foot.classList.toggle('lw-hidden', !buttons || (Array.isArray(buttons) && !buttons.filter(Boolean).length)); }
	function bigBtn(text, cls, onclick, attrs) { return h('button', Object.assign({ type: 'button', class: 'lw-mde-btn ' + (cls || ''), text: text, onclick: onclick }, attrs || {})); }

	/** Champ de scan : douchette / clavier (Entrée) ou caméra du téléphone. */
	function scanField(label, onCode, opts) {
		opts = opts || {};
		var input = h('input', { class: 'lw-mde-input', type: 'text', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', enterkeyhint: 'go', placeholder: opts.placeholder || 'Scanner ou saisir le code', 'aria-label': label });
		function submit(v) { v = String(v || '').trim(); if (!v) { return; } input.value = ''; onCode(v); }
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(input.value); } });
		var camBtn = h('button', { type: 'button', class: 'lw-mde-cam', 'aria-label': 'Scanner avec la caméra', title: 'Scanner avec la caméra', onclick: function () { camera(label, submit, opts.hint); } }, icon('cam'), h('span', { text: 'Scanner' }));
		var okBtn = h('button', { type: 'button', class: 'lw-mde-ok', text: 'OK', 'aria-label': 'Valider le code saisi', onclick: function () { submit(input.value); } });
		var wrap = h('div', { class: 'lw-mde-scan' + (opts.cls ? ' ' + opts.cls : '') }, h('span', { class: 'lw-mde-lbl', text: label }), h('div', { class: 'lw-mde-scanrow' }, input, okBtn), camBtn);
		wrap.focusInput = function () { if (wedge()) { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } } };
		setTimeout(wrap.focusInput, 60);
		return wrap;
	}
	function camera(title, cb, hint) {
		if (!BC) { flash('error', 'Le lecteur de codes-barres n\'est pas chargé. Rechargez la page.'); return; }
		BC.openScanner({ base: C.assets, title: title, hint: hint, onCode: function (t) { setTimeout(function () { cb(t); }, 0); return true; } });
	}
	function resolve(code) { return get(sp('/scan'), { code: code }); }
	function ok(text) { if (BC) { BC.vibrate(40); } flash('ok', text); }

	function kv(pairs) { return h('dl', { class: 'lw-mde-kv' }, pairs.filter(Boolean).map(function (p) { return [h('dt', { text: p[0] }), h('dd', { class: p[2] || '' }, p[1] === '' || p[1] === null || p[1] === undefined ? '—' : p[1])]; })); }
	function mcard(title, body, cls) { return h('section', { class: 'lw-mde-card ' + (cls || '') }, title ? h('h3', { text: title }) : null, body); }
	function mNum(value, label) { return h('input', { class: 'lw-mde-input num', type: 'number', inputmode: 'decimal', step: 'any', min: '0', value: value === undefined || value === null ? '' : value, 'aria-label': label }); }
	function mSel(options, value, label) { var el = h('select', { class: 'lw-mde-input', 'aria-label': label }); options.forEach(function (o) { var x = h('option', { value: o[0], text: o[1] }); if (String(o[0]) === String(value)) { x.selected = true; } el.appendChild(x); }); return el; }
	function mField(label, input, hint) { return h('label', { class: 'lw-mde-field' }, h('span', { text: label }), input, hint ? h('small', { text: hint }) : null); }

	/* ── Photos ── */
	function shrinkImage(file) {
		return new Promise(function (resolve2) {
			var done = function (blob) { resolve2(blob || file); };
			var draw = function (src, w, hgt) {
				var k = Math.min(1, 1600 / Math.max(w, hgt));
				var c = document.createElement('canvas');
				c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(hgt * k));
				c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
				if (c.toBlob) { c.toBlob(done, 'image/jpeg', 0.85); } else { done(null); }
			};
			if (window.createImageBitmap) {
				window.createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) { draw(bmp, bmp.width, bmp.height); }).catch(function () { done(null); });
				return;
			}
			var url = URL.createObjectURL(file), img = new Image();
			img.onload = function () { URL.revokeObjectURL(url); draw(img, img.naturalWidth, img.naturalHeight); };
			img.onerror = function () { URL.revokeObjectURL(url); done(null); };
			img.src = url;
		});
	}
	function uploadPhoto(file, meta) {
		return shrinkImage(file).then(function (blob) {
			if (blob.size > 12 * 1024 * 1024) { throw new Error('Photo trop lourde (12 Mo au maximum).'); }
			var fd = new FormData();
			fd.append('photo', blob, 'photo.jpg');
			fd.append('ref_no', meta.ref || '');
			fd.append('line_no', String(meta.line || 0));
			fd.append('note', meta.note || '');
			return api('POST', sp('/photos'), fd);
		});
	}
	function photoImg(p) {
		var img = h('img', { class: 'lw-mde-photo', alt: (p.ref_no ? p.ref_no + (p.line_no ? ' ligne ' + p.line_no : '') : 'Photo') + (p.note ? ' – ' + p.note : ''), width: String(p.width || 160), height: String(p.height || 120), decoding: 'async' });
		fetch(restUrl(sp('/photos/' + p.id)), { credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } }).then(function (r) { if (!r.ok) { throw new Error('photo'); } return r.blob(); }).then(function (b) {
			var u = URL.createObjectURL(b);
			img.onload = function () { URL.revokeObjectURL(u); };
			img.src = u;
		}).catch(function () { img.alt = 'Photo indisponible'; img.classList.add('missing'); });
		return img;
	}
	/** Bloc « photos » d'une ligne : vignettes + bouton de prise de vue (caméra arrière). */
	function photoBlock(meta, onChange) {
		var box = h('div', { class: 'lw-mde-photos' });
		var list = h('div', { class: 'lw-mde-thumbs' });
		var input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', capture: 'environment', class: 'lw-hidden', 'aria-hidden': 'true', tabindex: '-1' });
		var note = h('input', { class: 'lw-mde-input', type: 'text', maxlength: '190', placeholder: 'Légende (ex. carton écrasé, coin gauche)', 'aria-label': 'Légende de la photo', value: meta.note || '' });
		var shoot = bigBtn('Prendre une photo', 'secondary', function () { input.click(); }, { disabled: S.session.readonly ? true : null });
		append(shoot, icon('photo'));
		input.addEventListener('change', function () {
			var f = input.files && input.files[0];
			input.value = '';
			if (!f) { return; }
			shoot.disabled = true; shoot.firstChild.textContent = 'Envoi de la photo…';
			uploadPhoto(f, { ref: meta.ref, line: meta.line, note: note.value }).then(function (j) { ok(j.message || 'Photo enregistrée.'); refresh(); }).catch(function (e) { flash('error', e.message, e.details); }).then(function () { shoot.disabled = false; shoot.firstChild.textContent = 'Prendre une photo'; });
		});
		function refresh() {
			get(sp('/photos')).then(function (rows) {
				var mine = rows.filter(function (p) { return (!meta.ref || p.ref_no === meta.ref) && (!meta.line || p.line_no === meta.line); });
				clear(list);
				append(list, mine.map(function (p) {
					return h('figure', null, photoImg(p), h('figcaption', { text: p.note || (p.ref_no ? p.ref_no + (p.line_no ? ' · ' + p.line_no : '') : '') }),
						S.session.readonly ? null : h('button', { type: 'button', class: 'lw-mde-del', 'aria-label': 'Supprimer la photo', text: '×', onclick: function () { if (window.confirm('Supprimer cette photo ?')) { post(sp('/photos/' + p.id + '/delete')).then(function () { refresh(); }).catch(function (e) { flash('error', e.message); }); } } }));
				}));
				box.dataset.count = String(mine.length);
				if (onChange) { onChange(mine.length); }
			}).catch(function () {});
		}
		append(box, list, mField('Légende', note), shoot, input);
		refresh();
		box.refresh = refresh;
		return box;
	}

	/* ── Accueil du terminal ── */
	function renderMdeHome() {
		S.mde = true;
		stopPoll(); S.sid = 0; S.session = null;
		var body = mdeShell('Terminal mobile LogiERP', C.user.name, '#/');
		foot(null);
		append(body, loading());
		get('me').then(function (me) {
			clear(body); append(body, MDE.flash);
			var open = me.sessions.filter(function (x) { return x.status === 'EN_COURS'; });
			append(body, h('p', { class: 'lw-mde-intro', text: 'Choisissez la mission à traiter avec le terminal : réception par scan des palettes, rangement, prélèvement, consultation du stock, inventaire et photos des non-conformités.' }));
			if (!open.length) {
				append(body, msgBox('info', 'Aucune mission en cours. Démarrez une mission depuis le portail, puis revenez ici.'));
			}
			append(body, h('div', { class: 'lw-mde-list' }, open.map(function (x) {
				return h('button', { type: 'button', class: 'lw-mde-item', onclick: function () { location.hash = '#/mde/' + x.id; } }, h('strong', { text: x.title + (x.test ? ' (test)' : '') }), h('span', { text: 'Commencée le ' + date(x.started_at) }));
			})));
			var support = BC ? BC.cameraSupport() : 'Lecteur indisponible.';
			var wedgeBox = h('input', { type: 'checkbox' });
			wedgeBox.checked = wedge();
			wedgeBox.addEventListener('change', function () { setWedge(wedgeBox.checked); });
			append(body, mcard('Lecture des codes', [
				support ? msgBox('warn', support) : h('p', { class: 'lw-small', text: 'Caméra disponible : touchez « Scanner » à côté d\'un champ. Le navigateur vous demandera une seule fois l\'autorisation d\'utiliser la caméra.' }),
				h('label', { class: 'lw-mde-check' }, wedgeBox, h('span', { text: 'Terminal avec lecteur intégré ou douchette (le curseur se place automatiquement dans le champ de scan)' }))]));
			foot([bigBtn('Portail (version ordinateur)', 'secondary', function () { location.hash = '#/'; })]);
		}).catch(function (e) { flash('error', e.message); });
	}

	/* ── Session ouverte sur le terminal ── */
	function mdeRoute(sid, view, params) {
		S.mde = true;
		var need = S.sid !== sid || !S.session || S.session.id !== sid;
		S.sid = sid;
		if (need) {
			mdeShell('Terminal mobile', 'Chargement…', '#/mde');
			get('s/' + sid).then(function (sess) { S.session = sess; startPoll(); mdeView(view, params); }).catch(function (e) { flash('error', e.message); });
			return;
		}
		mdeView(view, params);
	}
	function mdeView(view, params) {
		var v = { we: mdeReception, to: mdeTransfer, pack: mdePack, stock: mdeStock, inv: mdeInventory, photos: mdePhotos, move: mdeMove }[view];
		if (v) { v(params || {}); } else { mdeMenu(); }
	}
	function readonlyNote() { return S.session.readonly ? msgBox('warn', S.session.paused ? 'Votre enseignant a mis la session en pause.' : 'Session terminée : consultation uniquement.') : null; }

	function mdeMenu() {
		var sess = S.session;
		var body = mdeShell(sess.title, 'Mission en cours', '#/mde');
		var tiles = [
			visible('MIGO') ? ['we', 'in', 'Réception', 'Scanner les palettes (SSCC)', null] : null,
			visible('LT12') ? ['to', 'put', 'Rangement', 'Ordres d\'entreposage', { type: 'PUTAWAY' }] : null,
			visible('LT12') ? ['to', 'pick', 'Prélèvement', 'Commandes clients (par secteur) et production', { type: 'PICK' }] : null,
			visible('HU02') ? ['pack', 'pack', 'Regroupement / colisage', 'Palettes mixtes, colis, SSCC', null] : null,
			['stock', 'stock', 'Stock', 'Article ou emplacement', null],
			['move', 'move', 'Déplacer / bloquer', 'Transfert, statut, quarantaine, destruction', null],
			['to', 'qa', 'Échantillons', 'Prélèvements pour la qualité', { type: 'SAMPLE' }],
			visible('MI04') ? ['inv', 'inv', 'Inventaire', 'Comptage au rayon', null] : null,
			['photos', 'photo', 'Photos', 'Non-conformités', null]
		].filter(Boolean);
		append(body, readonlyNote());
		if (sess.teacher_message) { append(body, msgBox('info', 'Message de votre enseignant : ' + sess.teacher_message)); }
		append(body, scanField('Scan rapide', function (code) {
			resolve(code).then(function (r) {
				if (r.kind === 'sscc') { mgo('we', { po: r.po_no, line: r.item_id, sscc: r.sscc }); return; }
				if (r.kind === 'po') { mgo('we', { po: r.no }); return; }
				if (r.kind === 'to') { mgo('to', { no: r.no }); return; }
				if (r.kind === 'dlv') { mgo('pack', { no: r.no }); return; }
				if (r.kind === 'hu') { mgo('pack', { no: r.dlv_no, hu: r.hu_no }); return; }
				mgo('stock', { code: code });
			}).catch(function (e) { flash('error', e.message, e.details); });
		}, { hint: 'Palette (SSCC), article (EAN), emplacement ou document.' }));
		append(body, h('div', { class: 'lw-mde-tiles' }, tiles.map(function (t) {
			return h('button', { type: 'button', class: 'lw-mde-tile', onclick: function () { mgo(t[0], t[4]); } }, icon(t[1]), h('strong', { text: t[2] }), h('span', { text: t[3] }));
		})));
		foot([bigBtn('Toutes les transactions', 'secondary', function () { S.mde = false; document.body.classList.remove('lw-mde-on'); location.hash = '#/s/' + S.sid; })]);
	}

	/* ── Réception (mouvement 101) ── */
	function grState(po) {
		var key = 'lwgr_' + S.sid + '_' + po;
		if (!MDE.gr[key]) {
			var saved = null;
			try { saved = JSON.parse(window.sessionStorage.getItem(key) || 'null'); } catch (e) { saved = null; }
			MDE.gr[key] = saved && typeof saved === 'object' ? saved : { lines: {}, text: '', over: false };
		}
		return MDE.gr[key];
	}
	function grSave(po) { try { window.sessionStorage.setItem('lwgr_' + S.sid + '_' + po, JSON.stringify(grState(po))); } catch (e) { /* facultatif */ } }
	function grClear(po) { var key = 'lwgr_' + S.sid + '_' + po; delete MDE.gr[key]; try { window.sessionStorage.removeItem(key); } catch (e) { /* facultatif */ } }

	function mdeReception(p) {
		if (p.po) { return grPo(p.po, p.line ? Number(p.line) : 0, p.sscc || ''); }
		var body = mdeShell('Réception', 'Choisir l\'arrivage', mdeHash(''));
		foot(null);
		append(body, readonlyNote(), scanField('Étiquette palette (SSCC) ou n° de commande', function (code) {
			resolve(code).then(function (r) {
				if (r.kind === 'sscc') { mgo('we', { po: r.po_no, line: r.item_id, sscc: r.sscc }); return; }
				if (r.kind === 'po') { mgo('we', { po: r.no }); return; }
				flash('error', 'Ce code n\'est ni une étiquette palette (SSCC) ni une commande d\'achat.');
			}).catch(function (e) { flash('error', e.message, e.details); });
		}, { hint: 'Code SSCC en bas de l\'étiquette logistique.' }));
		var list = h('div', { class: 'lw-mde-list' }, loading());
		append(body, h('h3', { class: 'lw-mde-h', text: 'Arrivages attendus' }), list);
		get(sp('/docs/po'), { status: 'open' }).then(function (rows) {
			clear(list);
			if (!rows.length) { append(list, h('p', { class: 'lw-muted', text: 'Aucune commande à réceptionner.' })); }
			append(list, rows.map(function (r) { return h('button', { type: 'button', class: 'lw-mde-item', onclick: function () { mgo('we', { po: r.no }); } }, h('strong', { class: 'lw-mono', text: r.no }), h('span', { text: r.partner }), badge(r.status)); }));
		}).catch(function (e) { fail(list, e); });
	}

	function lineState(st, i) {
		var ls = st.lines[i.id];
		if (!ls) {
			var dn = i.delivery_note;
			ls = st.lines[i.id] = { units: {}, qty: '', nc: false, nc_type: dn.damage ? 'Avarie' : '', nc_qty: '', nc_status: 'BLOQUE', reason: dn.damage ? 'Avarie : ' + dn.damage : '', batch: dn.batch || '', bbd: dn.bbd || '', loc: '', checked: false };
		}
		return ls;
	}
	function unitsQty(i, ls) { return (i.delivery_note.units || []).filter(function (u) { return ls.units[u.sscc]; }).reduce(function (a, u) { return a + Number(u.qty); }, 0); }

	function grPo(no, lineId, sscc) {
		var body = mdeShell('Réception ' + no, 'Contrôle des palettes', mdeHash('we'));
		append(body, loading());
		Promise.all([get(sp('/po/' + encodeURIComponent(no))), S.session.mode === 'SIMPLE' ? locations() : Promise.resolve([])]).then(function (res) {
			var po = res[0], locs = res[1];
			var st = grState(po.po_no);
			if (['ENVOYEE', 'PARTIELLEMENT_RECUE'].indexOf(po.status) < 0) { clear(body); append(body, MDE.flash, msgBox('info', 'La commande ' + po.po_no + ' est ' + (STATUS[po.status] || po.status).toLowerCase() + '.')); foot([bigBtn('Retour', 'secondary', function () { mgo('we'); })]); return; }
			if (sscc) {
				var it = po.items.filter(function (x) { return x.id === lineId; })[0];
				if (it) { var l0 = lineState(st, it); var first = !Object.keys(l0.units).length; l0.units[sscc] = 1; if (first || l0.qty === '' || l0.auto) { l0.qty = unitsQty(it, l0); l0.auto = true; } grSave(po.po_no); }
			}
			if (lineId) {
				var item = po.items.filter(function (x) { return x.id === lineId; })[0];
				if (item) { return grLine(po, item, locs, sscc); }
			}
			grPoView(po);
		}).catch(function (e) { clear(body); append(body, MDE.flash); flash('error', e.message, e.details); });
	}

	function grPoView(po) {
		var st = grState(po.po_no);
		var body = mdeShell('Réception ' + po.po_no, po.supplier ? po.supplier.name : '', mdeHash('we'));
		append(body, readonlyNote());
		if (po.supplier && ['CH', 'LI'].indexOf(po.supplier.country) < 0) {
			append(body, msgBox('warn', 'Arrivage international (' + po.supplier.country + ') : contrôlez la lettre de voiture CMR et signez la case 24 sur le poste (ZCMR).'));
		}
		if (Number(po.unannounced)) { append(body, msgBox('warn', 'Livraison non annoncée : vérifiez avec les achats avant de réceptionner.')); }
		append(body, scanField('Scanner une étiquette palette (SSCC)', function (code) {
			resolve(code).then(function (r) {
				if (r.kind !== 'sscc') { flash('error', 'Scannez le code SSCC (en bas de l\'étiquette logistique).'); return; }
				if (r.po_no !== po.po_no) { flash('error', 'Cette palette appartient à la commande ' + r.po_no + ', pas à ' + po.po_no + '.'); return; }
				mgo('we', { po: po.po_no, line: r.item_id, sscc: r.sscc });
			}).catch(function (e) { flash('error', e.message, e.details); });
		}));
		var captured = 0;
		append(body, h('div', { class: 'lw-mde-list' }, po.items.map(function (i) {
			var ls = st.lines[i.id];
			var units = i.delivery_note.units || [];
			var n = ls ? units.filter(function (u) { return ls.units[u.sscc]; }).length : 0;
			if (ls && ls.checked) { captured++; }
			var state = !ls || !ls.checked ? h('span', { class: 'lw-badge', text: i.open > 0 ? 'À contrôler' : 'Soldée' }) : ls.nc ? h('span', { class: 'lw-badge red', text: 'Non conforme' }) : h('span', { class: 'lw-badge green', text: 'Conforme' });
			return h('button', { type: 'button', class: 'lw-mde-item' + (ls && ls.checked ? ' done' : ''), disabled: i.open <= 0 ? true : null, onclick: function () { mgo('we', { po: po.po_no, line: i.id }); } },
				h('strong', { text: i.line_no + ' · ' + i.material.no }), h('span', { text: i.material.designation }),
				h('span', { class: 'lw-mde-meta', text: 'Reste ' + qty(i.open) + ' ' + i.material.uom + ' · ' + n + '/' + units.length + ' SSCC' }), state);
		})));
		var text = h('input', { class: 'lw-mde-input', type: 'text', maxlength: '120', value: st.text || '', placeholder: 'N° du bon de livraison', 'aria-label': 'N° du bon de livraison' });
		text.addEventListener('input', function () { st.text = text.value; grSave(po.po_no); });
		var over = h('input', { type: 'checkbox' }); over.checked = !!st.over;
		over.addEventListener('change', function () { st.over = over.checked; grSave(po.po_no); });
		append(body, mcard('Document', [mField('Bon de livraison du fournisseur', text), h('label', { class: 'lw-mde-check' }, over, h('span', { text: 'Accepter une surlivraison au-delà de la tolérance (motif obligatoire sur la ligne)' }))]));
		foot(S.session.readonly ? null : [bigBtn('Enregistrer l\'entrée (' + captured + ' ligne' + (captured > 1 ? 's' : '') + ')', 'key', function () { grPost(po, this); }, { disabled: captured ? null : true })]);
	}

	function grLine(po, i, locs, scanned) {
		var st = grState(po.po_no), ls = lineState(st, i), dn = i.delivery_note, simple = S.session.mode === 'SIMPLE';
		var body = mdeShell('Ligne ' + i.line_no + ' · ' + po.po_no, i.material.no + ' ' + i.material.designation, mdeHash('we', { po: po.po_no }));
		append(body, readonlyNote());
		if (scanned) { ok('Palette ' + scanned + ' scannée.'); }
		var wrong = dn.material && dn.material.id !== i.material.id;
		var expired = dn.bbd && dn.bbd < S.session.sim_date;
		append(body, mcard('Étiquette et bon de livraison', kv([
			['Article commandé', i.material.no + ' · ' + i.material.designation],
			['Article livré', (dn.material ? dn.material.no + ' · ' + dn.material.designation : ''), wrong ? 'bad' : ''],
			['Reste à recevoir', qty(i.open) + ' ' + i.material.uom],
			['Quantité livrée (BL)', qty(dn.qty) + ' ' + i.material.uom],
			dn.batch ? ['Lot', dn.batch] : null,
			dn.bbd ? ['Date limite', date(dn.bbd) + (expired ? ' · DÉPASSÉE' : ''), expired ? 'bad' : ''] : null,
			['Constat au déchargement', dn.damage || 'RAS', dn.damage ? 'bad' : 'good']
		]), wrong || dn.damage || expired ? 'alert' : ''));
		if (wrong) { append(body, msgBox('error', 'L\'article livré ne correspond pas à l\'article commandé : non-conformité à traiter.')); }

		var units = dn.units || [];
		var unitList = h('ul', { class: 'lw-mde-units' }, units.map(function (u) { return h('li', { class: ls.units[u.sscc] ? 'on' : '' }, h('span', { class: 'lw-mono', text: u.sscc }), h('span', { text: u.type + ' ' + u.n + '/' + u.of + ' · ' + qty(u.qty) })); }));
		append(body, mcard('Unités logistiques (' + Object.keys(ls.units).filter(function (k) { return units.some(function (u) { return u.sscc === k; }); }).length + '/' + units.length + ' scannées)', [unitList,
			scanField('Scanner la palette suivante', function (code) {
				resolve(code).then(function (r) {
					if (r.kind !== 'sscc' || r.item_id !== i.id) { flash('error', r.kind === 'sscc' ? 'Cette palette correspond à une autre ligne (' + r.po_no + ' / ' + r.line_no + ').' : 'Scannez le code SSCC de l\'étiquette.'); return; }
					mgo('we', { po: po.po_no, line: i.id, sscc: r.sscc, t: Date.now() % 100000 });
				}).catch(function (e) { flash('error', e.message, e.details); });
			}, { cls: 'compact' })]));

		var q = mNum(ls.qty, 'Quantité reçue conforme');
		var ncYes = h('button', { type: 'button', class: 'lw-seg' + (ls.nc ? ' on bad' : ''), text: 'Non conforme' });
		var ncNo = h('button', { type: 'button', class: 'lw-seg' + (!ls.nc ? ' on' : ''), text: 'Conforme' });
		var ncBox = h('div', { class: ls.nc ? '' : 'lw-hidden' });
		var types = [['Avarie', 'Avarie (emballage ou produit endommagé)'], ['Quantité', 'Écart de quantité'], ['Article erroné', 'Article erroné'], ['Date limite', 'Date limite dépassée ou trop courte'], ['Étiquette', 'Étiquette illisible ou absente'], ['Autre', 'Autre']];
		var ncType = mSel([['', '— type de non-conformité —']].concat(types), ls.nc_type, 'Type de non-conformité');
		var ncQty = mNum(ls.nc_qty, 'Quantité non conforme');
		var ncStatus = mSel([['BLOQUE', 'Stock bloqué (en attente de décision)'], ['CONTROLE_QUALITE', 'Contrôle qualité'], ['REFUS', 'Refusée à la livraison (non réceptionnée)']], ls.nc_status, 'Traitement');
		var reason = h('input', { class: 'lw-mde-input', type: 'text', maxlength: '120', value: ls.reason, placeholder: 'Réserve / motif', 'aria-label': 'Réserve ou motif' });
		var photoWarn = h('div');
		var photos = photoBlock({ ref: po.po_no, line: i.line_no, note: ls.reason }, function (n) {
			clear(photoWarn);
			ls.photos = n;
			if (ls.nc && !n) { append(photoWarn, msgBox('warn', 'Documentez la non-conformité par au moins une photo.')); }
		});
		function setNc(on) { ls.nc = on; ncYes.classList.toggle('on', on); ncYes.classList.toggle('bad', on); ncNo.classList.toggle('on', !on); ncBox.classList.toggle('lw-hidden', !on); photos.refresh(); }
		ncYes.addEventListener('click', function () { setNc(true); });
		ncNo.addEventListener('click', function () { setNc(false); });
		append(ncBox, mField('Type', ncType), mField('Quantité non conforme', ncQty, 'Réceptionnée selon le traitement choisi, en plus de la quantité conforme.'), mField('Traitement', ncStatus), mField('Réserve / motif', reason), photoWarn, photos);

		var batch = i.material.batch_managed ? h('input', { class: 'lw-mde-input code', type: 'text', maxlength: '20', value: ls.batch, 'aria-label': 'Lot', autocapitalize: 'characters' }) : null;
		var bbd = i.material.shelf_life ? h('input', { class: 'lw-mde-input', type: 'date', value: ls.bbd, 'aria-label': 'Date limite' }) : null;
		var loc = simple ? mSel([['', '— magasin —']].concat(locs.filter(function (l) { return !l.transit; }).map(function (l) { return [l.id, l.code + ' – ' + l.name]; })), ls.loc, 'Magasin de destination') : null;
		append(body, mcard('Saisie', [
			mField('Quantité conforme reçue (' + i.material.uom + ')', q, 'Préremplie avec le contenu des palettes scannées : corrigez-la si nécessaire.'),
			batch ? mField('Lot (lu sur l\'étiquette)', batch) : null,
			bbd ? mField('Date limite (lue sur l\'étiquette)', bbd) : null,
			loc ? mField('Magasin de destination', loc, 'Gestion simple : choisissez un magasin adapté au profil de l\'article.') : null,
			h('div', { class: 'lw-mde-segs', role: 'group', 'aria-label': 'Conformité' }, ncNo, ncYes), ncBox]));

		function store() {
			ls.qty = q.value; ls.auto = false; ls.nc_type = ncType.value; ls.nc_qty = ncQty.value; ls.nc_status = ncStatus.value; ls.reason = reason.value;
			if (batch) { ls.batch = batch.value.trim().toUpperCase(); }
			if (bbd) { ls.bbd = bbd.value; }
			if (loc) { ls.loc = loc.value; }
			grSave(po.po_no);
		}
		foot(S.session.readonly ? null : [bigBtn('Annuler la saisie', 'secondary', function () { delete st.lines[i.id]; grSave(po.po_no); mgo('we', { po: po.po_no }); }),
			bigBtn('Valider la ligne', 'key', function () {
				store();
				var good = Number(ls.qty || 0), bad = Number(ls.nc_qty || 0);
				if (good < 0 || bad < 0) { flash('error', 'Les quantités ne peuvent pas être négatives.'); return; }
				if (ls.nc) {
					if (!ls.nc_type) { flash('error', 'Choisissez le type de non-conformité.'); return; }
					if (!String(ls.reason).trim()) { flash('error', 'Saisissez la réserve (motif) de la non-conformité.'); return; }
				}
				if (!good && !(ls.nc && bad > 0)) { flash('error', 'Saisissez la quantité reçue (conforme ou non conforme).'); return; }
				if (simple && !ls.loc) { flash('error', 'Choisissez le magasin de destination.'); return; }
				ls.checked = true; grSave(po.po_no);
				mgo('we', { po: po.po_no });
			})]);
	}

	function grPost(po, button) {
		var st = grState(po.po_no), lines = [], refused = [];
		po.items.forEach(function (i) {
			var ls = st.lines[i.id];
			if (!ls || !ls.checked) { return; }
			var base = { item_id: i.id, batch: ls.batch || '', bbd: ls.bbd || '', location_id: ls.loc || 0 };
			if (Number(ls.qty) > 0) { lines.push(Object.assign({ qty: Number(ls.qty), status: 'LIBRE', reason: st.over ? ls.reason || '' : '' }, base)); }
			if (ls.nc && Number(ls.nc_qty) > 0) {
				if (ls.nc_status === 'REFUS') { refused.push('L' + i.line_no + ' refus ' + qty(ls.nc_qty) + ' : ' + ls.reason); } else { lines.push(Object.assign({ qty: Number(ls.nc_qty), status: ls.nc_status, reason: (ls.nc_type ? ls.nc_type + ' – ' : '') + ls.reason }, base)); }
			}
		});
		if (!lines.length) { flash('error', refused.length ? 'Toute la marchandise contrôlée est refusée : rien à réceptionner. Signalez le refus au transporteur.' : 'Aucune ligne contrôlée.'); return; }
		var text = [st.text || 'Réception terminal mobile'].concat(refused).join(' · ').slice(0, 255);
		button.disabled = true;
		post(sp('/gr'), { po_no: po.po_no, lines: lines, text: text, accept_over: !!st.over }).then(function (j) {
			grClear(po.po_no);
			var d = j.data;
			var body = mdeShell('Entrée enregistrée', po.po_no, mdeHash('we'));
			if (BC) { BC.vibrate([40, 40, 40]); }
			append(body, msgBox('ok', 'Entrée de marchandises enregistrée : document ' + d.doc_no + '.'));
			if (refused.length) { append(body, msgBox('warn', 'Refus notés sur le document : ' + refused.join(' ; '))); }
			if (d.messages && d.messages.length) { append(body, msgBox('warn', 'Attention', d.messages)); }
			refreshSessionQuiet();
			foot([bigBtn('Autre arrivage', 'secondary', function () { mgo('we'); }), d.to_no ? bigBtn('Ranger maintenant (OT ' + d.to_no + ')', 'key', function () { mgo('to', { no: d.to_no }); }) : bigBtn('Menu', 'key', function () { mgo(''); })]);
		}).catch(function (e) { button.disabled = false; flash('error', e.message, e.details); });
	}
	function refreshSessionQuiet() { get('s/' + S.sid).then(function (sess) { S.session = sess; }).catch(function () {}); }

	/* ── Rangement et prélèvement (ordres de transfert) ── */
	var TO_LABEL = { PUTAWAY: 'Entreposage', PICK: 'Prélèvement', REPL: 'Réapprovisionnement', MOVE: 'Transfert interne', STAGE: 'Mise à disposition production', SAMPLE: 'Prélèvement d\'échantillon' };
	function mdeTransfer(p) {
		if (p.no) { return toRun(p.no); }
		var group = p.type === 'PICK' ? ['PICK', 'STAGE'] : p.type === 'SAMPLE' ? ['SAMPLE'] : ['PUTAWAY', 'REPL', 'MOVE'];
		var putaway = group[0] === 'PUTAWAY';
		var body = mdeShell(putaway ? 'Rangement' : p.type === 'SAMPLE' ? 'Échantillons qualité' : 'Prélèvement', 'Ordres de transfert ouverts', mdeHash(''));
		foot(null);
		append(body, readonlyNote(), scanField('Scanner ou saisir le n° d\'OT', function (code) {
			resolve(code).then(function (r) { if (r.kind === 'to') { mgo('to', { no: r.no }); } else { flash('error', 'Ce code n\'est pas un ordre de transfert.'); } }).catch(function (e) { flash('error', e.message, e.details); });
		}));
		var list = h('div', { class: 'lw-mde-list' }, loading());
		append(body, list);
		get(sp('/to')).then(function (rows) {
			clear(list);
			rows = rows.filter(function (r) { return group.indexOf(r.type) >= 0; });
			var sectors = rows.map(function (r) { return r.sector; }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }).sort();
			if (sectors.length) {
				append(list, h('div', { class: 'lw-mde-filter', role: 'group', 'aria-label': 'Secteur' }, [['', 'Tous']].concat(sectors.map(function (x) { return [x, 'Secteur ' + x]; })).map(function (o) { return h('button', { type: 'button', class: 'lw-mde-pill' + ((p.sector || '') === o[0] ? ' on' : ''), text: o[1], onclick: function () { mgo('to', { type: p.type, sector: o[0] }); } }); })));
				if (p.sector) { rows = rows.filter(function (r) { return r.sector === p.sector; }); }
			}
			if (!rows.length) { append(list, h('p', { class: 'lw-muted', text: putaway ? 'Aucun ordre de rangement ouvert. Réceptionnez d\'abord une livraison.' : p.type === 'SAMPLE' ? 'Aucun échantillon à prélever. Demandez-le depuis le lot de contrôle (QA32) sur le poste.' : 'Aucun ordre de prélèvement ouvert : livraison (VL01N) ou mise à disposition production (LP10) sur le poste.' })); }
			append(list, rows.map(function (r) { return h('button', { type: 'button', class: 'lw-mde-item', onclick: function () { mgo('to', { no: r.to_no }); } }, h('strong', { class: 'lw-mono', text: r.to_no + (r.priority === 'A' ? ' · prio. A' : '') }), h('span', { text: (TO_LABEL[r.type] || r.type) + ' · ' + r.n_lines + ' ligne' + (r.n_lines > 1 ? 's' : '') + (r.customer ? ' · ' + r.customer : '') }), r.picker ? h('span', { class: 'lw-small', text: r.picker + (r.sector ? ' · secteur ' + r.sector : '') }) : null); }));
		}).catch(function (e) { fail(list, e); });
	}

	function toRun(no) {
		var body = mdeShell('OT ' + no, 'Chargement…', mdeHash('to'));
		append(body, loading());
		get(sp('/to/' + encodeURIComponent(no))).then(function (to) {
			var pick = to.type === 'PICK' || to.type === 'SAMPLE';
			var backType = to.type === 'SAMPLE' ? 'SAMPLE' : (to.type === 'PICK' || to.type === 'STAGE') ? 'PICK' : '';
			if (to.status !== 'OUVERT') {
				body = mdeShell('OT ' + to.to_no, to.type_label, mdeHash('to', { type: backType }));
				append(body, msgBox('info', 'Cet ordre de transfert est ' + (STATUS[to.status] || to.status).toLowerCase() + '.'));
				foot([bigBtn('Retour', 'secondary', function () { mgo('to', { type: backType }); })]);
				return;
			}
			var done = [], k = 0;
			function step() {
				if (k >= to.items.length) { return finish(); }
				var i = to.items[k], target = pick ? i.src : (i.proposed || ''), binCode = '', mat = null, cdv = '';
				var needCd = to.type === 'PICK' && Number(S.session.check_digits) === 1;
				var b = mdeShell((TO_LABEL[to.type] || 'Transfert') + ' ' + to.to_no, 'Ligne ' + (k + 1) + ' / ' + to.items.length, mdeHash('to', { type: backType }));
				append(b, h('div', { class: 'lw-mde-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(to.items.length), 'aria-valuenow': String(k) }, h('span', { style: { width: Math.round(k / to.items.length * 100) + '%' } })));
				if (to.type === 'STAGE') { append(b, h('div', { class: 'lw-mde-goto from' }, h('span', { text: 'Prélever d\'abord à' }), h('strong', { class: 'lw-mono', text: i.src }))); }
				append(b, h('div', { class: 'lw-mde-goto' }, h('span', { text: to.type === 'SAMPLE' ? 'Prélever l\'échantillon à' : pick ? 'Prélever à' : to.type === 'STAGE' ? 'Puis déposer au bord de ligne' : 'Déposer à' }), h('strong', { class: 'lw-mono', text: target || 'Choisir un emplacement' }), i.proposed_info && !pick ? h('small', { text: i.proposed_info.type + ' · ' + i.proposed_info.location_name }) : null));
				append(b, mcard(null, kv([['Article', i.material.no + ' · ' + i.material.designation], i.batch ? ['Lot', i.batch] : null, i.bbd ? ['Date limite', date(i.bbd)] : null, ['Quantité', qty(i.qty) + ' ' + i.material.uom], ['Statut', STATUS[i.stock_status] || i.stock_status], pick ? null : ['Depuis', i.src]])));
				var stepBox = h('div');
				append(b, stepBox);
				var binField = scanField('1. Scanner l\'emplacement', function (code) {
					resolve(code).then(function (r) {
						if (r.kind !== 'bin') { flash('error', 'Scannez l\'étiquette de l\'emplacement.'); return; }
						binCode = r.bin.code;
						if (target && binCode !== target) { flash('warn', pick ? 'Ce n\'est pas l\'emplacement indiqué : un autre emplacement n\'est accepté que s\'il respecte FIFO / FEFO.' : 'Emplacement différent de la proposition : il sera contrôlé à la validation (profil, charge, ADR…).'); } else { ok('Emplacement ' + binCode + ' confirmé.'); }
						if (needCd) { showCheck(); } else { showArticle(); }
					}).catch(function (e) { flash('error', e.message, e.details); });
				}, { hint: 'Étiquette code-barres de l\'emplacement.' });
				append(stepBox, binField);
				function showCheck() {
					clear(stepBox);
					append(stepBox, h('p', { class: 'lw-mde-done', text: '✓ Emplacement ' + binCode }));
					var c = h('input', { class: 'lw-mde-input num', type: 'text', inputmode: 'numeric', maxlength: '2', autocomplete: 'off', 'aria-label': 'Chiffre de contrôle' });
					append(stepBox, mField('Chiffre de contrôle de l\'emplacement', c, 'Les deux chiffres encadrés sur l\'étiquette de l\'emplacement.'));
					foot([bigBtn('Valider', 'key', function () {
						if (!/^\d{2}$/.test(c.value.trim())) { flash('error', 'Saisissez les deux chiffres imprimés sur l\'étiquette.'); return; }
						cdv = c.value.trim(); foot(null); showArticle();
					})]);
					c.focus();
				}
				function showArticle() {
					clear(stepBox);
					append(stepBox, h('p', { class: 'lw-mde-done', text: '✓ Emplacement ' + binCode + (cdv ? ' · contrôle ' + cdv : '') }));
					append(stepBox, scanField('2. Scanner l\'article', function (code) {
						resolve(code).then(function (r) {
							var m = r.material;
							if (!m) { flash('error', 'Scannez le code EAN de l\'article (ou le code contenu de l\'étiquette palette).'); return; }
							if (m.id !== i.material.id) { flash('error', 'Mauvais article : ' + m.no + ' ' + m.designation + ' au lieu de ' + i.material.no + '.'); return; }
							if (pick && r.batch && i.batch && r.batch !== i.batch) { flash('error', 'Mauvais lot : ' + r.batch + ' au lieu de ' + i.batch + '.'); return; }
							mat = m; ok('Article ' + m.no + ' confirmé.'); showQty();
						}).catch(function (e) { flash('error', e.message, e.details); });
					}, { hint: 'Code EAN du carton ou du produit.' }));
				}
				function showQty() {
					clear(stepBox);
					append(stepBox, h('p', { class: 'lw-mde-done', text: '✓ Emplacement ' + binCode }), h('p', { class: 'lw-mde-done', text: '✓ Article ' + mat.no }));
					var q = mNum(i.qty, 'Quantité');
					append(stepBox, mField('3. Quantité ' + (pick ? 'prélevée' : 'déposée') + ' (' + i.material.uom + ')', q));
					foot([bigBtn('Recommencer la ligne', 'secondary', function () { step(); }), bigBtn(k + 1 < to.items.length ? 'Ligne suivante' : 'Terminer', 'key', function () {
						if (!(Number(q.value) > 0)) { flash('error', 'Saisissez la quantité.'); return; }
						done.push({ item_id: i.id, bin_code: binCode, check: cdv, qty: q.value, scan: mat.no, batch: pick ? i.batch : '' });
						k++; step();
					})]);
				}
				foot(null);
			}
			function finish() {
				var b = mdeShell('OT ' + to.to_no, 'Toutes les lignes sont scannées', mdeHash('to', { type: backType }));
				var drop = to.type === 'PICK' && to.items[0] ? to.items[0].dst : '';
				if (drop) { append(b, h('div', { class: 'lw-mde-goto' }, h('span', { text: /^600/.test(drop) ? 'Déposer au regroupement' : 'Déposer au quai d\'expédition' }), h('strong', { class: 'lw-mono', text: drop }), to.picker ? h('small', { text: to.picker + ' · livraison ' + to.ref_no }) : null)); }
				append(b, mcard('Récapitulatif', h('ul', { class: 'lw-mde-units' }, done.map(function (d, n) { var it = to.items[n]; return h('li', { class: 'on' }, h('span', { class: 'lw-mono', text: d.bin_code }), h('span', { text: it.material.no + ' · ' + qty(d.qty) + ' ' + it.material.uom })); }))));
				foot([bigBtn('Recommencer', 'secondary', function () { done = []; k = 0; step(); }), bigBtn('Valider l\'ordre de transfert', 'key', function () {
					var bt = this; bt.disabled = true;
					post(sp('/to/' + encodeURIComponent(to.to_no) + '/confirm'), { items: done }).then(function (j) {
						var b2 = mdeShell('OT ' + to.to_no + ' confirmé', TO_LABEL[to.type] || '', mdeHash('to', { type: backType }));
						if (BC) { BC.vibrate([40, 40, 40]); }
						append(b2, msgBox('ok', j.message || 'Ordre de transfert confirmé.'));
						refreshSessionQuiet();
						var parallel = to.type === 'PICK' && to.items[0] && /^600/.test(to.items[0].dst || '');
						foot([bigBtn('Menu', 'secondary', function () { mgo(''); }), parallel ? bigBtn('Regroupement', 'secondary', function () { mgo('pack', { no: to.ref_no }); }) : null, bigBtn('Ordre suivant', 'key', function () { mgo('to', { type: backType, sector: to.sector || '' }); })]);
					}).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); });
				})]);
			}
			step();
		}).catch(function (e) { clear(body); append(body, MDE.flash); flash('error', e.message, e.details); });
	}

	/* ── Regroupement et colisage (HU02) ── */
	function mdePack(p) {
		if (!p.no) {
			var body = mdeShell('Regroupement / colisage', 'Livraisons à coliser', mdeHash(''));
			foot(null);
			append(body, readonlyNote(), scanField('Scanner la livraison ou une étiquette SSCC', function (code) {
				resolve(code).then(function (r) {
					if (r.kind === 'dlv') { mgo('pack', { no: r.no }); return; }
					if (r.kind === 'hu') { mgo('pack', { no: r.dlv_no, hu: r.hu_no }); return; }
					flash('error', 'Scannez le n° de livraison (bon de préparation) ou l\'étiquette SSCC d\'une unité.');
				}).catch(function (e) { flash('error', e.message, e.details); });
			}, { hint: 'N° de livraison sur le bon de préparation.' }));
			var list = h('div', { class: 'lw-mde-list' }, loading());
			append(body, list);
			get(sp('/docs/dlv'), { status: 'open' }).then(function (rows) {
				clear(list);
				rows = rows.filter(function (r) { return r.status === 'EN_PREPARATION' || r.status === 'PREPAREE'; });
				if (!rows.length) { append(list, h('p', { class: 'lw-muted', text: 'Aucune livraison en préparation. Créez les livraisons sur le poste (VL10).' })); }
				append(list, rows.map(function (r) { return h('button', { type: 'button', class: 'lw-mde-item', onclick: function () { mgo('pack', { no: r.no }); } }, h('strong', { class: 'lw-mono', text: r.no }), h('span', { text: r.partner + ' · ' + (STATUS[r.status] || r.status) })); }));
			}).catch(function (e) { fail(list, e); });
			return;
		}
		var b = mdeShell('Colisage ' + p.no, 'Chargement…', mdeHash('pack'));
		append(b, loading());
		get(sp('/hu/' + encodeURIComponent(p.no))).then(function (d) {
			var call = function (a, body2) { return post(sp('/hu/' + encodeURIComponent(d.dlv_no) + '/' + a), body2 || {}); };
			var body = mdeShell('Colisage ' + d.dlv_no, (d.customer ? d.customer.name : '') + ' · ' + (d.mode === 'PARALLELE' ? 'en parallèle' : 'en série'), mdeHash('pack'));
			append(body, readonlyNote());
			if (d.locked) { append(body, msgBox('info', 'Livraison déjà emballée ou expédiée.')); foot([bigBtn('Retour', 'secondary', function () { mgo('pack'); })]); return; }
			if (!d.ready) {
				append(body, h('div', { class: 'lw-mde-goto from' }, h('span', { text: 'Regroupement en' }), h('strong', { class: 'lw-mono', text: d.consolidation || '600' }), h('small', { text: 'En attente des secteurs' })));
				append(body, mcard('Secteurs', h('ul', { class: 'lw-mde-units' }, d.pick_orders.map(function (t) { return h('li', { class: t.status === 'OUVERT' ? '' : 'on' }, h('span', { text: (t.picker || 'Prélèvement') + (t.sector ? ' · secteur ' + t.sector : '') }), h('span', { class: 'lw-mono', text: 'OT ' + t.to_no + ' · ' + (STATUS[t.status] || t.status) })); }))));
				foot([bigBtn('Actualiser', 'secondary', function () { mgo('pack', { no: d.dlv_no, t: Date.now() }); }), bigBtn('Prélever', 'key', function () { mgo('to', { no: d.waiting[0].to_no }); })]);
				return;
			}
			var open = d.units.filter(function (u) { return u.status === 'OUVERTE'; });
			var cur = open.filter(function (u) { return u.hu_no === p.hu; })[0] || open[open.length - 1] || null;
			var todo = d.items.filter(function (i) { return i.open > 0; });
			append(body, mcard('À coliser', h('ul', { class: 'lw-mde-units' }, d.items.map(function (i) { return h('li', { class: i.open > 0 ? '' : 'on' }, h('span', { text: i.material.no + ' · ' + i.material.designation }), h('strong', { text: qty(i.packed) + ' / ' + qty(i.picked) + ' ' + i.material.uom })); }))));
			if (S.session.readonly) { foot([bigBtn('Retour', 'secondary', function () { mgo('pack'); })]); return; }
			if (!todo.length && !open.length) {
				append(body, msgBox('ok', 'Commande regroupée et colisée : ' + d.units.length + ' unité(s) fermée(s). Collez les étiquettes SSCC, puis emballez et expédiez sur le poste (VL02N).'));
				append(body, mcard('Unités', h('ul', { class: 'lw-mde-units' }, d.units.map(function (u) { return h('li', { class: 'on' }, h('span', { text: u.type_label + (u.mixed ? ' · mixte' : '') }), h('span', { class: 'lw-mono', text: u.sscc })); }))));
				foot([bigBtn('Menu', 'secondary', function () { mgo(''); }), bigBtn('Autre livraison', 'key', function () { mgo('pack'); })]);
				return;
			}
			if (!cur) {
				append(body, mcard('Ouvrir une unité d\'expédition', [d.customer && d.customer.pallet ? h('p', { class: 'lw-small', text: 'Le client exige : ' + d.customer.pallet_label + '.' }) : null,
					h('div', { class: 'lw-mde-choices' }, d.types.map(function (t) { return bigBtn(t.label, t.code === (d.customer && d.customer.pallet) ? 'key' : 'secondary', function () { var bt = this; bt.disabled = true; call('create', { type: t.code }).then(function (j) { ok(j.message); mgo('pack', { no: d.dlv_no, hu: j.data.hu_no }); }).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); }); }); }))]));
				foot([bigBtn('Retour', 'secondary', function () { mgo('pack'); })]);
				return;
			}
			append(body, h('div', { class: 'lw-mde-goto' }, h('span', { text: cur.type_label }), h('strong', { class: 'lw-mono', text: cur.hu_no }), h('small', { text: 'SSCC ' + cur.sscc + ' · ' + qty(cur.weight) + ' kg' })));
			append(body, gauge('Charge', cur.fill_w), gauge('Volume', cur.fill_v));
			if (cur.items.length) { append(body, mcard('Sur l\'unité', h('ul', { class: 'lw-mde-units' }, cur.items.map(function (i) { return h('li', { class: 'on' }, h('span', { text: i.material.no + ' · ' + i.material.designation }), h('strong', { text: qty(i.qty) + ' ' + i.material.uom })); })))); }
			var box = h('div');
			append(body, box);
			if (todo.length) {
				append(box, scanField('Scanner l\'article à placer', function (code) {
					resolve(code).then(function (r) {
						var m = r.material;
						var it = m ? todo.filter(function (i) { return i.material.id === m.id; })[0] : null;
						if (!it) { flash('error', m ? 'Article ' + m.no + ' : rien à coliser pour cette livraison.' : 'Scannez le code EAN de l\'article.'); return; }
						clear(box);
						var q = mNum(it.open, 'Quantité');
						append(box, mcard(null, kv([['Article', it.material.no + ' · ' + it.material.designation], ['Reste à coliser', qty(it.open) + ' ' + it.material.uom]])), mField('Quantité placée (' + it.material.uom + ')', q));
						foot([bigBtn('Annuler', 'secondary', function () { mgo('pack', { no: d.dlv_no, hu: cur.hu_no, t: Date.now() }); }), bigBtn('Placer sur l\'unité', 'key', function () {
							var bt = this; bt.disabled = true;
							call('add', { hu: cur.hu_no, material: it.material.no, qty: q.value }).then(function (j) { ok(j.message); mgo('pack', { no: d.dlv_no, hu: cur.hu_no, t: Date.now() }); }).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); });
						})]);
					}).catch(function (e) { flash('error', e.message, e.details); });
				}, { hint: 'Code EAN du produit ou du carton.' }));
			} else {
				append(box, msgBox('ok', 'Tous les articles sont placés : fermez l\'unité.'));
			}
			foot([bigBtn('Nouvelle unité', 'secondary', function () { var bt = this; bt.disabled = true; call('create', { type: cur.type }).then(function (j) { ok(j.message); mgo('pack', { no: d.dlv_no, hu: j.data.hu_no }); }).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); }); }),
				bigBtn('Fermer l\'unité', 'key', function () { var bt = this; bt.disabled = true; call('close', { hu: cur.hu_no }).then(function (j) { ok(j.message); mgo('pack', { no: d.dlv_no, t: Date.now() }); }).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); }); })]);
		}).catch(function (e) { clear(b); append(b, MDE.flash); flash('error', e.message, e.details); });
	}

	/* ── Consultation du stock ── */
	function mdeStock(p) {
		var body = mdeShell('Stock', 'Scanner un article ou un emplacement', mdeHash(''));
		foot(null);
		var out = h('div');
		append(body, scanField('Article (EAN) ou emplacement', function (code) { mgo('stock', { code: code }); }), out);
		if (!p.code) { return; }
		append(out, loading());
		resolve(p.code).then(function (r) {
			if (r.kind === 'sscc') { clear(out); append(out, mcard('Unité logistique ' + r.sscc, kv([['Commande', r.po_no + ' / ligne ' + r.line_no], ['Contenu', qty(r.unit.qty)], [r.unit.type, r.unit.n + ' / ' + r.unit.of]])), bigBtn('Réceptionner cette palette', 'key', function () { mgo('we', { po: r.po_no, line: r.item_id, sscc: r.sscc }); })); return null; }
			var f = r.kind === 'bin' ? { bin: r.bin.code } : r.material ? { material: r.material.no } : null;
			if (!f) { clear(out); append(out, msgBox('info', 'Ce code est un document (' + (r.no || '') + ') : ouvrez-le depuis le menu.')); return null; }
			return get(sp('/stock'), f).then(function (res) {
				clear(out);
				var rows = r.kind === 'bin' ? res.rows.filter(function (x) { return x.bin === r.bin.code; }) : res.rows;
				var head = r.kind === 'bin' ? mcard('Emplacement ' + r.bin.code, kv([['Magasin', r.bin.location + ' – ' + r.bin.location_name], ['Type', r.bin.type], ['Charge max.', qty(r.bin.max_weight) + ' kg']])) : mcard(r.material.no, kv([['Désignation', r.material.designation], ['Profil', r.material.profile_label], ['EAN', r.material.ean]]));
				append(out, head);
				if (!rows.length) { append(out, msgBox('info', r.kind === 'bin' ? 'Emplacement vide.' : 'Aucun stock pour cet article.')); }
				append(out, h('div', { class: 'lw-mde-list' }, rows.map(function (x) {
					return h('div', { class: 'lw-mde-item static' + (x.expired ? ' bad' : '') }, h('strong', { text: r.kind === 'bin' ? x.material + ' · ' + x.designation : (x.bin || x.location) }), h('span', { class: 'lw-mde-meta', text: [x.batch ? 'Lot ' + x.batch : '', x.bbd ? 'DLC ' + date(x.bbd) + (x.expired ? ' (périmé)' : '') : ''].filter(Boolean).join(' · ') }), h('span', { class: 'lw-mde-qty', text: qty(x.qty) + ' ' + x.uom }), badge(x.status));
				})));
				if (res.total > rows.length && r.kind !== 'bin') { append(out, h('p', { class: 'lw-small lw-muted', text: res.total + ' lignes au total : consultez MMBE sur le poste.' })); }
			});
		}).catch(function (e) { clear(out); flash('error', e.message, e.details); });
	}

	/* ── Inventaire (comptage au rayon) ── */
	function mdeInventory(p) {
		if (p.no) { return invRun(p.no, p.item ? Number(p.item) : 0); }
		var body = mdeShell('Inventaire', 'Documents ouverts', mdeHash(''));
		foot(null);
		var list = h('div', { class: 'lw-mde-list' }, loading());
		append(body, readonlyNote(), list);
		get(sp('/docs/inv'), { status: 'open' }).then(function (rows) {
			clear(list);
			if (!rows.length) { append(list, h('p', { class: 'lw-muted', text: 'Aucun document d\'inventaire ouvert. Créez-le sur le poste (MI01).' })); }
			append(list, rows.map(function (r) { return h('button', { type: 'button', class: 'lw-mde-item', onclick: function () { mgo('inv', { no: r.no }); } }, h('strong', { class: 'lw-mono', text: r.no }), h('span', { text: r.note })); }));
		}).catch(function (e) { fail(list, e); });
	}
	function invRun(no, itemId) {
		var body = mdeShell('Inventaire ' + no, 'Comptage', mdeHash('inv'));
		append(body, loading());
		get(sp('/inv/' + encodeURIComponent(no))).then(function (d) {
			var open = d.status === 'OUVERT' && !S.session.readonly;
			var item = itemId ? d.items.filter(function (x) { return x.id === itemId; })[0] : null;
			if (!item) {
				body = mdeShell('Inventaire ' + d.inv_no, d.scope, mdeHash('inv'));
				var counted = d.items.filter(function (x) { return x.counted_qty !== null; }).length;
				append(body, readonlyNote(), h('div', { class: 'lw-mde-progress' }, h('span', { style: { width: Math.round(counted / Math.max(1, d.items.length) * 100) + '%' } })), h('p', { class: 'lw-small', text: counted + ' / ' + d.items.length + ' lignes comptées' + (d.blind ? ' · comptage à l\'aveugle' : '') }));
				if (open) {
					append(body, scanField('Scanner l\'emplacement à compter', function (code) {
						resolve(code).then(function (r) {
							var code2 = r.kind === 'bin' ? r.bin.code : '';
							var hit = d.items.filter(function (x) { return (x.bin || x.location) === code2 && x.counted_qty === null; })[0] || d.items.filter(function (x) { return (x.bin || x.location) === code2; })[0];
							if (!hit) { flash('error', 'Cet emplacement ne fait pas partie du document d\'inventaire.'); return; }
							mgo('inv', { no: d.inv_no, item: hit.id, bin: 1 });
						}).catch(function (e) { flash('error', e.message, e.details); });
					}));
				}
				append(body, h('div', { class: 'lw-mde-list' }, d.items.map(function (x) {
					return h('button', { type: 'button', class: 'lw-mde-item' + (x.counted_qty !== null ? ' done' : '') + (x.recount ? ' bad' : ''), disabled: open ? null : true, onclick: function () { mgo('inv', { no: d.inv_no, item: x.id }); } },
						h('strong', { class: 'lw-mono', text: x.bin || x.location }), h('span', { text: x.material.no + ' · ' + x.material.designation + (x.batch ? ' · lot ' + x.batch : '') }),
						x.counted_qty !== null ? h('span', { class: 'lw-mde-qty', text: qty(x.counted_qty) + ' ' + x.material.uom }) : h('span', { class: 'lw-badge', text: 'À compter' }), x.recount ? h('span', { class: 'lw-badge yellow', text: 'Recompter' }) : null);
				})));
				foot([bigBtn('Menu', 'secondary', function () { mgo(''); })]);
				if (counted === d.items.length && d.items.length) { append(body, msgBox('ok', 'Toutes les lignes sont comptées. La comptabilisation des écarts se fait sur le poste (MI07).')); }
				return;
			}
			var where = item.bin || item.location, binOk = !!(location.hash.indexOf('bin=1') >= 0), matOk = false;
			body = mdeShell('Comptage ' + where, d.inv_no, mdeHash('inv', { no: d.inv_no }));
			append(body, h('div', { class: 'lw-mde-goto' }, h('span', { text: 'Compter à' }), h('strong', { class: 'lw-mono', text: where })), mcard(null, kv([['Article', item.material.no + ' · ' + item.material.designation], item.batch ? ['Lot', item.batch] : null, ['Stock théorique', d.blind ? 'masqué (comptage à l\'aveugle)' : qty(item.book_qty) + ' ' + item.material.uom]])));
			var box = h('div');
			append(body, box);
			function render() {
				clear(box);
				if (!binOk) {
					append(box, scanField('1. Scanner l\'emplacement', function (code) { resolve(code).then(function (r) { if (r.kind === 'bin' && r.bin.code === where) { binOk = true; ok('Emplacement confirmé.'); render(); } else { flash('error', 'Ce n\'est pas l\'emplacement ' + where + '.'); } }).catch(function (e) { flash('error', e.message, e.details); }); }));
					return;
				}
				append(box, h('p', { class: 'lw-mde-done', text: '✓ Emplacement ' + where }));
				if (!matOk) {
					append(box, scanField('2. Scanner l\'article', function (code) { resolve(code).then(function (r) { if (r.material && r.material.id === item.material.id) { matOk = true; ok('Article confirmé.'); render(); } else { flash('error', r.material ? 'Autre article (' + r.material.no + ') : comptez uniquement ' + item.material.no + ' ici.' : 'Scannez le code EAN de l\'article.'); } }).catch(function (e) { flash('error', e.message, e.details); }); }));
					return;
				}
				append(box, h('p', { class: 'lw-mde-done', text: '✓ Article ' + item.material.no }));
				var shelf = h('div', { class: 'lw-mde-shelf' }, bigBtn('Compter au rayon', 'secondary', function () {
					post(sp('/inv/' + encodeURIComponent(d.inv_no) + '/look'), { item_id: item.id }).then(function (j) {
						clear(shelf); append(shelf, h('span', { text: 'Vous comptez physiquement' }), h('strong', { text: qty(j.data.qty) + ' ' + item.material.uom }));
					}).catch(function (e) { flash('error', e.message); });
				}));
				var q = mNum(item.counted_qty !== null ? item.counted_qty : '', 'Quantité comptée');
				append(box, shelf, mField('3. Quantité comptée (' + item.material.uom + ')', q));
				foot([bigBtn('Liste', 'secondary', function () { mgo('inv', { no: d.inv_no }); }), bigBtn('Enregistrer le comptage', 'key', function () {
					if (q.value === '' || Number(q.value) < 0) { flash('error', 'Saisissez la quantité comptée (0 si l\'emplacement est vide).'); return; }
					var bt = this; bt.disabled = true;
					post(sp('/inv/' + encodeURIComponent(d.inv_no) + '/count'), { counts: [{ item_id: item.id, qty: q.value }] }).then(function () {
						var next = d.items.filter(function (x) { return x.id !== item.id && x.counted_qty === null; })[0];
						if (next) { mgo('inv', { no: d.inv_no, item: next.id }); } else { mgo('inv', { no: d.inv_no }); }
					}).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); });
				})]);
			}
			foot([bigBtn('Liste', 'secondary', function () { mgo('inv', { no: d.inv_no }); })]);
			render();
		}).catch(function (e) { clear(body); append(body, MDE.flash); flash('error', e.message, e.details); });
	}

	/* ── Déplacer, bloquer, mettre en quarantaine, détruire ── */
	function mdeMove(p) {
		var body = mdeShell('Déplacer / bloquer', 'Scanner l\'emplacement ou l\'article', mdeHash(''));
		foot(null);
		append(body, readonlyNote());
		if (!p.id) {
			var out = h('div');
			append(body, scanField('Emplacement ou article (EAN)', function (code) { mgo('move', { code: code }); }), out);
			if (!p.code) { return; }
			append(out, loading());
			resolve(p.code).then(function (r) {
				var f = r.kind === 'bin' ? { bin: r.bin.code } : r.material ? { material: r.material.no } : null;
				if (!f) { clear(out); flash('error', 'Scannez un emplacement ou un article.'); return null; }
				return get(sp('/stock'), f).then(function (res) {
					clear(out);
					var rows = r.kind === 'bin' ? res.rows.filter(function (x) { return x.bin === r.bin.code; }) : res.rows;
					if (!rows.length) { append(out, msgBox('info', 'Aucun stock ici.')); return; }
					append(out, h('h3', { class: 'lw-mde-h', text: 'Choisissez la ligne de stock' }), h('div', { class: 'lw-mde-list' }, rows.map(function (x) {
						return h('button', { type: 'button', class: 'lw-mde-item' + (x.expired ? ' bad' : ''), onclick: function () { MDE.row = x; mgo('move', { id: x.id }); } }, h('strong', { text: x.material + ' · ' + x.designation }), h('span', { class: 'lw-mde-meta', text: (x.bin || x.location) + (x.batch ? ' · lot ' + x.batch : '') + (x.bbd ? ' · DLC ' + date(x.bbd) : '') }), h('span', { class: 'lw-mde-qty', text: qty(x.qty) + ' ' + x.uom }), badge(x.status));
					})));
				});
			}).catch(function (e) { clear(out); flash('error', e.message, e.details); });
			return;
		}
		var x = MDE.row && String(MDE.row.id) === String(p.id) ? MDE.row : null;
		if (!x) { mgo('move'); return; }
		var simple = S.session.mode === 'SIMPLE';
		append(body, mcard(x.material + ' · ' + x.designation, kv([['Emplacement', x.bin || x.location], x.batch ? ['Lot', x.batch] : null, x.bbd ? ['Date limite', date(x.bbd) + (x.expired ? ' (dépassée)' : '')] : null, ['Statut', STATUS[x.status] || x.status], ['Quantité', qty(x.qty) + ' ' + x.uom]])));
		var actions = [['TRANSFER', 'Transférer vers un autre emplacement']];
		if (x.status === 'LIBRE') { actions.push(['BLOQUE', 'Bloquer (344)'], ['CONTROLE_QUALITE', 'Mettre en contrôle qualité (322)']); }
		if (x.status === 'BLOQUE' && !x.expired) { actions.push(['LIBRE', 'Libérer (343)']); }
		if (x.status === 'CONTROLE_QUALITE') { actions.push(['LIBRE', 'Libérer (321)'], ['BLOQUE', 'Bloquer (350)']); }
		actions.push(['QUARANTAINE', 'Mettre en quarantaine (zone 800)'], ['DESTRUCTION', 'Détruire (551)']);
		var what = mSel(actions, 'TRANSFER', 'Action');
		var q = mNum(x.qty, 'Quantité');
		var reason = h('input', { class: 'lw-mde-input', type: 'text', maxlength: '120', placeholder: 'Motif', 'aria-label': 'Motif' });
		var dst = '';
		var dstBox = h('div');
		var photo = photoBlock({ ref: x.material, line: 0, note: '' });
		var photoWrap = h('div', { class: 'lw-hidden' }, photo);
		function upd() {
			clear(dstBox); dst = '';
			var needDst = what.value === 'TRANSFER' || (what.value === 'QUARANTAINE' && !simple);
			photoWrap.classList.toggle('lw-hidden', ['DESTRUCTION', 'QUARANTAINE', 'BLOQUE'].indexOf(what.value) < 0);
			if (!needDst) { return; }
			if (simple && what.value === 'TRANSFER') {
				var locSel = mSel([['', '— magasin —']].concat((LOCS || []).filter(function (l) { return !l.transit; }).map(function (l) { return [l.code, l.code + ' – ' + l.name]; })), '', 'Magasin');
				if (!LOCS) { locations().then(function () { upd(); }); }
				locSel.addEventListener('change', function () { dst = locSel.value; });
				append(dstBox, mField('Magasin de destination', locSel));
				return;
			}
			append(dstBox, scanField(what.value === 'QUARANTAINE' ? 'Scanner un emplacement de quarantaine (800)' : 'Scanner l\'emplacement de destination', function (code) {
				resolve(code).then(function (r) {
					if (r.kind !== 'bin') { flash('error', 'Scannez l\'étiquette d\'un emplacement.'); return; }
					if (what.value === 'QUARANTAINE' && r.bin.location !== '800') { flash('error', 'Ce n\'est pas un emplacement de la zone de quarantaine 800.'); return; }
					dst = r.bin.code; ok('Destination ' + dst + '.');
				}).catch(function (e) { flash('error', e.message, e.details); });
			}, { cls: 'compact' }));
		}
		what.addEventListener('change', upd);
		append(body, mcard('Action', [mField('Action', what), mField('Quantité (' + x.uom + ')', q), mField('Motif', reason), dstBox, photoWrap]));
		upd();
		foot(S.session.readonly ? null : [bigBtn('Annuler', 'secondary', function () { mgo('move', { code: x.bin || x.material }); }), bigBtn('Valider', 'key', function () {
			var bt = this, v = what.value, call;
			if (!(Number(q.value) > 0)) { flash('error', 'Saisissez la quantité.'); return; }
			if (v === 'TRANSFER') {
				if (!dst) { flash('error', simple ? 'Choisissez le magasin de destination.' : 'Scannez l\'emplacement de destination.'); return; }
				call = post(sp('/stock/transfer'), { stock_id: x.id, qty: q.value, location: simple ? dst : '', bin: simple ? '' : dst });
			} else if (v === 'QUARANTAINE') {
				if (!simple && !dst) { flash('error', 'Scannez un emplacement de la zone 800.'); return; }
				call = post(sp('/stock/quarantine'), { stock_id: x.id, qty: q.value, bin: dst, reason: reason.value });
			} else if (v === 'DESTRUCTION') {
				call = post(sp('/stock/issue'), { stock_id: x.id, qty: q.value, movement: '551', reason: reason.value });
			} else {
				call = post(sp('/stock/status'), { stock_id: x.id, qty: q.value, status: v, reason: reason.value });
			}
			bt.disabled = true;
			call.then(function (j) {
				var b2 = mdeShell('Mouvement enregistré', x.material, mdeHash(''));
				if (BC) { BC.vibrate([40, 40, 40]); }
				append(b2, msgBox('ok', j.message || 'Enregistré.'));
				if (j.data && j.data.lot_no) { append(b2, msgBox('info', 'Lot de contrôle ' + j.data.lot_no + ' créé : la décision d\'emploi se prend sur le poste (QA11).')); }
				refreshSessionQuiet();
				foot([bigBtn('Menu', 'secondary', function () { mgo(''); }), bigBtn('Autre mouvement', 'key', function () { mgo('move'); })]);
			}).catch(function (e) { bt.disabled = false; flash('error', e.message, e.details); });
		})]);
	}

	/* ── Photos de la session ── */
	function mdePhotos() {
		var body = mdeShell('Photos', 'Non-conformités documentées', mdeHash(''));
		foot(null);
		append(body, readonlyNote(), h('p', { class: 'lw-small', text: 'Les photos prises lors d\'une réception sont rattachées à la commande et à la ligne concernées. Votre enseignant les voit dans la correction.' }));
		append(body, mcard('Toutes les photos', photoBlock({ ref: '', line: 0, note: '' })));
	}

	/** Accès au terminal depuis la session sur ordinateur. */
	function mdeInvite() {
		var url = C.appUrl.replace(/#.*$/, '') + '#/mde/' + S.sid;
		modal('Terminal mobile (MDE)', h('div', null,
			h('p', { text: 'Utilisez votre smartphone comme terminal de saisie mobile : scan des étiquettes palettes (SSCC), des articles (EAN) et des emplacements, photos des non-conformités.' }),
			h('ol', { class: 'lw-small', style: { paddingLeft: '18px' } },
				h('li', { text: 'Sur le smartphone, connectez-vous au site avec votre compte.' }),
				h('li', { text: 'Ouvrez la page LogiERP puis touchez « Terminal mobile », ou ouvrez directement cette adresse :' })),
			h('p', { class: 'lw-mono lw-mde-url', text: url }),
			h('p', { class: 'lw-small lw-muted', text: 'Les étiquettes à scanner s\'affichent ici avec HU03 (palettes) ; l\'écran de l\'ordinateur ou une impression suffit.' })),
			{ buttons: btn('Ouvrir ici', 'lw-btn-secondary', function () { location.hash = '#/mde/' + S.sid; }) });
	}

	/* ── Extensions (transactions SAP de l'entreprise : assets/js/sap.js) ── */
	var KIT = { tourView: tourView, h: h, s: s, append: append, clear: clear, qty: qty, chf: chf, date: date, addDays: addDays, STATUS: STATUS, badge: badge, chips: chips, get: get, post: post, sp: sp, status: status, msgBox: msgBox, act: act, toast: toast, modal: modal, f4: f4, f4Field: f4Field, field: field, inp: inp, num: num, sel: sel, btn: btn, table: table, dl: dl, card: card, loading: loading, fail: fail, matCell: matCell, go: go, link: link, printable: printable, printBtn: printBtn, tx: tx, TX: TX, GROUPS: GROUPS, tabbed: tabbed, docPicker: docPicker, stockPicker: stockPicker, stockSummary: stockSummary, locations: locations, refreshSession: refreshSession, toForm: toForm, grForm: grForm, S: S, C: C, root: root, TO_LABEL: TO_LABEL };
	(window.LOGIERP_EXT || []).forEach(function (f) { try { f(KIT); } catch (e) { if (window.console) { window.console.error('LogiERP extension', e); } } });

	/* ── Démarrage ── */
	route();
})();
