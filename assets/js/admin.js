/* LogiERP — espace enseignant : génération du jeu de base et suivi en direct.
   Aucune donnée n'est insérée en HTML : tout passe par textContent. */
(function () {
	'use strict';
	var C = window.LOGIERP_ADMIN || {};

	/* Espace enseignant sur le site : largeur visible réelle (sans barre de défilement) pour la pleine largeur. */
	if (document.querySelector('.logierp-teacher')) {
		var setVw = function () { document.documentElement.style.setProperty('--lw-vw', document.documentElement.clientWidth + 'px'); };
		setVw();
		window.addEventListener('resize', setVw);
	}

	/** Adresse REST : fonctionne avec ou sans permaliens (base « ?rest_route= »). */
	function restUrl(path) {
		var i = path.indexOf('?');
		if (i < 0) { return C.rest + path; }
		return C.rest + path.slice(0, i) + (C.rest.indexOf('?') >= 0 ? '&' : '?') + path.slice(i + 1);
	}
	function api(method, path, body) {
		return fetch(restUrl(path), {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return { ok: false, message: 'Réponse invalide du serveur (' + r.status + ').' }; });
		});
	}

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { n.textContent = attrs[k]; }
			else if (k === 'class') { n.className = attrs[k]; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), attrs[k]); }
			else { n.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}

	/* ── Génération du jeu de base ─────────────────────────── */
	var seed = document.getElementById('lw-seed');
	var start = document.getElementById('lw-seed-start');
	if (seed && start) {
		var bar = seed.querySelector('.lw-progress-bar span');
		var label = seed.querySelector('.lw-progress-label');
		var running = false;
		var show = function (s) {
			bar.style.width = Math.round(100 * s.step / Math.max(1, s.total)) + '%';
			seed.setAttribute('data-state', s.state);
			label.textContent = s.state === 'done' ? 'Terminé : le jeu de base est prêt. ' + (s.message || '') : s.step + ' / ' + s.total + ' – ' + s.label + (s.message ? ' · ' + s.message : '');
		};
		var loop = function (fails) {
			api('POST', 'admin/seed').then(function (res) {
				if (!res || !res.ok) { throw new Error(res && res.message ? res.message : 'Erreur'); }
				var s = res.data;
				show(s);
				if (s.state === 'done') {
					running = false;
					start.textContent = 'Terminé';
					setTimeout(function () { window.location.reload(); }, 1500);
					return;
				}
				if (s.state === 'error') {
					running = false;
					start.disabled = false;
					start.textContent = 'Reprendre la préparation';
					return;
				}
				setTimeout(function () { loop(0); }, s.busy ? 2000 : 150);
			}).catch(function (e) {
				if (fails < 5) { setTimeout(function () { loop(fails + 1); }, 3000); return; }
				running = false;
				start.disabled = false;
				start.textContent = 'Reprendre la préparation';
				label.textContent = 'Interruption : ' + e.message + ' Vous pouvez reprendre : la préparation repart de la dernière étape terminée.';
			});
		};
		var go = function () {
			if (running) { return; }
			running = true;
			start.disabled = true;
			start.textContent = 'Préparation en cours… (gardez cette page ouverte)';
			loop(0);
		};
		start.addEventListener('click', go);
		if (seed.getAttribute('data-autostart') === '1') { go(); }
	}

	/* ── Interventions en direct : seul le bloc de l'intervention choisie est affiché ── */
	var iv = document.getElementById('lw-intervene');
	if (iv) {
		var syncIv = function () {
			var cur = iv.querySelector('input[name="type"]:checked');
			Array.prototype.forEach.call(iv.querySelectorAll('fieldset.lw-iv'), function (f) {
				var on = !!cur && f.getAttribute('data-iv') === cur.value;
				f.hidden = !on;
				Array.prototype.forEach.call(f.querySelectorAll('input, select'), function (x) { x.disabled = !on; });
			});
		};
		iv.addEventListener('change', syncIv);
		syncIv();
	}

	/* ── Suivi en direct ───────────────────────────────────── */
	var live = document.getElementById('lw-live');
	if (live) {
		var aid = live.getAttribute('data-assignment');
		var resultsUrl = live.getAttribute('data-results');
		var body = document.getElementById('lw-live-body');
		var updated = document.getElementById('lw-live-updated');
		var busy = false;
		var editing = false;
		var statusLabel = { EN_COURS: 'En cours', SOUMISE: 'Remise', CORRIGEE: 'Corrigée' };
		var flash = '';

		var act = function (path, payload, okText) {
			busy = true;
			return api('POST', path, payload).then(function (res) {
				flash = res && res.message ? res.message : (okText || 'Fait.');
			}).finally(function () { busy = false; refresh(); });
		};

		var intervene = function (s, events) {
			var box = el('div', { class: 'lw-intervene' });
			if (s.status !== 'EN_COURS') {
				box.appendChild(el('a', { class: 'button button-small', href: resultsUrl + s.id, text: 'Voir la correction' }));
				return box;
			}
			var manual = events;
			if (manual.length) {
				var sel = el('select', { 'aria-label': 'Événement à déclencher' }, [el('option', { value: '', text: 'Imprévu…' })].concat(manual.map(function (e) {
					return el('option', { value: String(e.id), text: e.label + (e.message ? ' – ' + e.message : '') });
				})));
				sel.addEventListener('focus', function () { editing = true; });
				sel.addEventListener('blur', function () { editing = false; });
				box.appendChild(sel);
				box.appendChild(el('button', { type: 'button', class: 'button button-small', text: 'Déclencher', onclick: function () {
					if (!sel.value) { return; }
					act('teacher/sessions/' + s.id + '/event', { event_id: parseInt(sel.value, 10) });
				} }));
			}
			var msg = el('input', { type: 'text', maxlength: '255', placeholder: 'Message à l\'apprenant' });
			msg.addEventListener('focus', function () { editing = true; });
			msg.addEventListener('blur', function () { editing = false; });
			box.appendChild(msg);
			box.appendChild(el('button', { type: 'button', class: 'button button-small', text: 'Envoyer', onclick: function () {
				if (!msg.value.trim()) { return; }
				editing = false;
				act('teacher/sessions/' + s.id + '/message', { text: msg.value.trim() });
			} }));
			box.appendChild(el('button', { type: 'button', class: 'button button-small', text: s.paused ? 'Reprendre' : 'Pause', onclick: function () {
				act('teacher/sessions/' + s.id + '/pause', { paused: s.paused ? 0 : 1 });
			} }));
			box.appendChild(el('a', { class: 'button button-small', href: resultsUrl + s.id, text: 'Détail' }));
			return box;
		};

		var render = function (data) {
			while (body.firstChild) { body.removeChild(body.firstChild); }
			if (!data.rows.length) {
				body.appendChild(el('tr', {}, [el('td', { colspan: '9', text: 'Aucun apprenant dans cette classe.' })]));
				return;
			}
			data.rows.forEach(function (r) {
				var s = r.session;
				if (!s) {
					body.appendChild(el('tr', {}, [el('td', { text: r.user }), el('td', { colspan: '8', text: 'Pas encore commencé' })]));
					return;
				}
				var idle = s.idle_min === null ? '—' : s.idle_min + ' min';
				body.appendChild(el('tr', {}, [
					el('td', { text: r.user }),
					el('td', { text: (statusLabel[s.status] || s.status) + (s.paused ? ' (pause)' : '') }),
					el('td', { text: s.tasks }),
					el('td', { text: String(s.ok) }),
					el('td', { text: String(s.errors) }),
					el('td', { class: s.last_ok ? '' : 'lw-ko', text: s.last || '—' }),
					el('td', { class: s.status === 'EN_COURS' && s.idle_min !== null && s.idle_min >= 10 ? 'lw-idle' : '', text: idle }),
					el('td', { text: s.grade === null ? '—' : s.grade.toFixed(1) }),
					el('td', {}, [intervene(s, data.events)])
				]));
			});
		};

		var refresh = function () {
			if (busy || editing || document.hidden) { return; }
			api('GET', 'teacher/assignments/' + encodeURIComponent(aid) + '/monitor').then(function (res) {
				if (!res || !res.ok) { updated.textContent = res && res.message ? res.message : 'Erreur de chargement.'; return; }
				render(res.data);
				updated.textContent = (flash ? flash + ' · ' : '') + 'Mis à jour à ' + new Date().toLocaleTimeString('fr-CH');
				flash = '';
			}).catch(function () { updated.textContent = 'Connexion perdue, nouvel essai dans 10 secondes.'; });
		};
		refresh();
		setInterval(refresh, 10000);
	}
})();
