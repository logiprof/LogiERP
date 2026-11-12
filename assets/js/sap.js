/* LogiERP — transactions SAP des procédures de l'entreprise (version 2.0). Chargé avant app.js, s'enregistre par LOGIERP_EXT.
   Aucune donnée n'est insérée par innerHTML : tous les nœuds sont créés avec textContent. */
(function () {
	'use strict';
	window.LOGIERP_EXT = window.LOGIERP_EXT || [];
	window.LOGIERP_EXT.push(function (K) {
		var h = K.h, append = K.append, clear = K.clear, qty = K.qty, chf = K.chf, date = K.date, badge = K.badge, get = K.get, post = K.post, sp = K.sp;
		var status = K.status, msgBox = K.msgBox, act = K.act, modal = K.modal, f4Field = K.f4Field, field = K.field, inp = K.inp, num = K.num, sel = K.sel, btn = K.btn;
		var table = K.table, dl = K.dl, card = K.card, loading = K.loading, fail = K.fail, matCell = K.matCell, go = K.go, link = K.link, tx = K.tx, tabbed = K.tabbed, S = K.S;

		if (K.GROUPS.indexOf('Emballages et portails') < 0) { K.GROUPS.splice(K.GROUPS.indexOf('Retours') + 1, 0, 'Emballages et portails'); }

		/* ── Utilitaires ── */
		function mono(t) { return h('span', { class: 'lw-mono', text: t }); }
		function codeInp(attrs) { return inp(Object.assign({ class: 'lw-input code' }, attrs || {})); }
		function onEnter(el, fn) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fn(); } }); return el; }
		function today() { return S.session.sim_date; }
		function hint(text) { return h('p', { class: 'lw-small lw-muted lw-sap-hint', text: text }); }
		function steps(list) { return h('ol', { class: 'lw-small lw-sap-steps' }, list.map(function (t) { return h('li', { text: t }); })); }
		function mvBadge(code, neg) { return h('span', { class: 'lw-badge ' + (neg ? 'red' : 'green'), text: code }); }
		function yesno(v) { return Number(v) ? 'Oui' : 'Non'; }
		function dt(v) { return v ? date(v) + ' ' + String(v).slice(11, 16) : ''; }
		/** Document imprimable : enregistre le message de sortie (journal), puis lance l'impression. */
		function printOut(el, obj, no, type, detail) {
			return btn('Imprimer', 'lw-btn-secondary lw-noprint', function () {
				post(sp('/output'), { obj_type: obj, obj_no: no, type: type, detail: detail || '' }).then(function (j) { status('ok', j.message); }).catch(function (e) { status('error', e.message); });
				document.querySelectorAll('.lw-printable').forEach(function (x) { if (x !== el) { x.classList.remove('lw-printable'); } });
				el.classList.add('lw-printable');
				window.print();
			});
		}
		function docHead(title, right) { return h('div', { class: 'lw-doc-head' }, h('div', null, h('div', { class: 'lw-doc-co', text: S.session && C().company ? C().company.name : '' }), h('h3', { text: title })), right || null); }
		function C() { return K.C; }
		function changesTable(rows) {
			if (!rows || !rows.length) { return h('p', { class: 'lw-muted lw-small', text: 'Aucune modification enregistrée.' }); }
			return table(['Date', 'Poste', 'Zone', 'Ancienne valeur', 'Nouvelle valeur', 'Transaction', 'Par'], rows.map(function (r) { return { cells: [dt(r.created_at), r.line_no || '—', r.field, r.old_value, r.new_value, mono(r.tcode), r.by] }; }));
		}
		function outputsTable(rows) {
			if (!rows || !rows.length) { return h('p', { class: 'lw-muted lw-small', text: 'Aucun message imprimé.' }); }
			return table(['Message', 'Désignation', 'Détail', 'Imprimé le'], rows.map(function (r) { return { cells: [mono(r.output_type), r.label, r.detail || '', dt(r.created_at)] }; }));
		}
		function stockRowText(x) { return [x.location, x.bin, x.batch ? 'lot ' + x.batch : '', K.STATUS[x.status] || x.status, x.special_ref ? 'E ' + x.special_ref : '', qty(x.qty) + ' ' + x.material.uom].filter(Boolean).join(' · '); }

		/** Bulletin de livraison (message ZPAL) : destinataire, transport, lignes, matières dangereuses. */
		function noteDoc(n) {
			var t = n.shipment;
			return card(null, h('div', null, docHead('Bulletin de livraison ' + n.dlv_no, h('span', { class: 'lw-mono', text: 'ZPAL' })),
				h('div', { class: 'lw-grid2' }, dl([['Destinataire', n.customer ? n.customer.no + ' – ' + n.customer.name : ''], ['Localité', n.customer ? n.customer.city : ''], ['Commande', n.so_no]]), dl([['Date', date(n.date)], ['Transport', t ? t.no + ' · ' + t.means + (t.eta ? ' · arrivée prévue ' + t.eta : '') : (n.carrier || '— (à planifier)')], ['Colis / supports', Number(n.packages) ? n.packages + ' · ' + (n.support || '—') : '— (à l\'emballage)'], ['Poids brut', qty(n.weight_kg) + ' kg']])),
				table(['Pos.', 'Article', 'Lots', 'Quantité', 'Matière dangereuse'], n.lines.map(function (l) { return { cells: [l.pos, matCell(l.material), l.batches.map(function (b) { return b.batch + ' (' + qty(b.qty) + ')'; }).join(', ') || '—', qty(l.qty) + ' ' + l.material.uom, l.material.un ? 'UN ' + l.material.un + ', ' + l.material.hazard + ', cl. ' + l.material.adr + (l.material.packing_group ? ', GE ' + l.material.packing_group : '') : ''] }; }), [3]),
				h('div', { class: 'lw-sign' }, h('span', { text: 'Marchandise reçue en bon état : ……………………' }), h('span', { text: 'Date, signature' }))));
		}
		/** Imprime une feuille composée (plusieurs documents) sans passer par printOut. */
		function printSheet(el) {
			document.querySelectorAll('.lw-printable').forEach(function (x) { if (x !== el) { x.classList.remove('lw-printable'); } });
			el.classList.add('lw-printable');
			window.print();
		}

		/* Sélecteur de stock LS26 (lignes de stock d'un article, y compris stock client E). */
		function quantPicker(onPick, opts) {
			opts = opts || {};
			var out = h('div');
			var mat = codeInp({ placeholder: 'Article', value: opts.material || '' });
			var loc = codeInp({ placeholder: 'Magasin (facultatif)', value: opts.location || '', maxlength: '10' });
			function run() {
				clear(out); append(out, loading());
				get(sp('/ls26'), { material: mat.value.trim(), location: loc.value.trim() }).then(function (d) {
					clear(out);
					var rows = d.quants.filter(function (q) { return !opts.filter || opts.filter(q); });
					if (!rows.length) { append(out, h('p', { class: 'lw-muted', text: 'Aucune ligne de stock correspondante.' })); return; }
					append(out, table(['Article', 'Magasin', 'Emplacement', 'Lot', 'Statut', 'Stock spécial', 'Quantité'], rows.map(function (q) {
						return { cells: [matCell(q.material), q.location, mono(q.bin), q.batch || '', badge(q.status), q.special_ref ? mono('E ' + q.special_ref) : '', qty(q.qty) + ' ' + q.material.uom], onclick: function () { onPick(q); } };
					}), [6]));
				}).catch(function (e) { fail(out, e); });
			}
			onEnter(mat, run); onEnter(loc, run);
			var wrap = h('div', null, h('div', { class: 'lw-form' }, field('Article', mat), field('Magasin', loc), btn('Rechercher', 'lw-btn-primary', run)), h('div', { style: { marginTop: '10px' } }, out));
			if (opts.material || opts.location) { run(); }
			return wrap;
		}

		/* ══════════════════ LOT 1 · STOCK ET CORRECTIONS ══════════════════ */

		var MB11 = [
			['201', '201 – Sortie pour centre de coûts (usage interne)'],
			['309', '309 – Transfert d\'article à article'],
			['311', '311 – Magasin → magasin de production'],
			['312', '312 – Magasin de production → magasin'],
			['344', '344 – Stock libre → bloqué'],
			['343', '343 – Stock bloqué → libre'],
			['412E', '412 E – Stock libre → stock client (réservation)'],
			['411E', '411 E – Stock client → libre (libération)'],
			['501', '501 – Entrée sans commande (centre de coûts)'],
			['551', '551 – Mise au rebut'],
			['924', '924 – Conteneur loué repris en stock propre']
		];
		tx('MB11', 'Mouvement de stock (par code mouvement)', 'Réception et stock', 'INV|PP|WH|GR|TRA', function (el, p) {
			var mv = sel(MB11, p.mvt || '201', { 'aria-label': 'Code mouvement' });
			var area = h('div');
			function draw() {
				clear(area);
				var code = mv.value;
				var msg = h('div');
				var q = num({ placeholder: 'Quantité' });
				var text = inp({ placeholder: 'Texte d\'en-tête (facultatif)', maxlength: '255' });
				var reason = inp({ placeholder: 'Motif', maxlength: '120' });
				var picked = null;
				var pickBox = h('div');
				var fields = h('div', { class: 'lw-form' });
				var extra = {};
				var needStock = ['201', '309', '311', '312', '343', '344', '411E', '412E', '551'].indexOf(code) >= 0;
				function setPick(x) { picked = x; clear(pickBox); append(pickBox, h('div', { class: 'lw-paper' }, h('strong', { text: x.material.no + ' – ' + x.material.designation }), h('div', { class: 'lw-small', text: stockRowText(x) }))); q.value = x.qty; }
				var info = {
					'201': 'Usage interne : la marchandise sort du stock sur un centre de coûts et un compte général (ex. 6200 / 670000).',
					'309': 'Le stock d\'un article (cédant) passe sur un autre article (prenant), dans la même unité : par exemple des conteneurs neufs utilisés comme lavés pour une production.',
					'311': 'Transfert du stock vers un magasin de production (bord de ligne 500). En gestion détaillée, un ordre de transfert est créé : quittancez-le dans LS26.',
					'312': 'Retour du magasin de production vers un magasin de stockage (OT à quittancer dans LS26).',
					'344': 'Blocage d\'une quantité de stock libre (avarie, réclamation).',
					'343': 'Déblocage d\'une quantité de stock bloqué (autorisation nécessaire en entreprise).',
					'412E': 'Réservation physique d\'une quantité pour une commande client (stock spécial E) : elle n\'est plus disponible pour les autres commandes.',
					'411E': 'Libération du stock client E avant de livrer la commande : la quantité redevient libre.',
					'501': 'Entrée sans commande d\'achat (échantillon, emballage non géré…) imputée sur un centre de coûts (ex. 9942). En gestion détaillée : quai de réception et OT d\'entreposage.',
					'551': 'Mise au rebut d\'une quantité (destruction).',
					'924': 'Un conteneur loué (article X, n° de conteneur dans le champ « Lot ») est repris chez nous sous l\'article propre (sans X).'
				}[code];
				append(area, hint(info));
				if (needStock) {
					var filter = { '343': function (x) { return x.status === 'BLOQUE'; }, '344': function (x) { return x.status === 'LIBRE'; }, '411E': function (x) { return x.status === 'STOCK_E'; }, '412E': function (x) { return x.status === 'LIBRE'; }, '201': function (x) { return x.status === 'LIBRE'; }, '309': function (x) { return x.status === 'LIBRE'; }, '311': function (x) { return x.status === 'LIBRE' && !Number(x.transit); }, '312': function (x) { return x.status === 'LIBRE' && x.location === '500'; } }[code];
					append(area, card('Poste cédant : choisir la ligne de stock', h('div', null, quantPicker(setPick, { filter: filter, material: p.material || '' }), pickBox)));
				}
				if (code === '201') {
					extra.cc = codeInp({ placeholder: '6200', maxlength: '10', 'data-noscan': '1' }); extra.acc = codeInp({ placeholder: '670000', maxlength: '6', 'data-noscan': '1' });
					append(fields, field('Compte général', extra.acc), field('Centre de coûts', extra.cc));
				}
				if (code === '501') {
					extra.cc = codeInp({ placeholder: '9942', maxlength: '10', 'data-noscan': '1' });
					extra.mat = f4Field('Article', 'material', function (r) { extra.m = r; });
					extra.batch = codeInp({ placeholder: 'Lot (vide : attribué)', maxlength: '20' });
					extra.loc = S.session.mode === 'SIMPLE' ? codeInp({ placeholder: 'Magasin', maxlength: '10' }) : null;
					append(fields, extra.mat, field('Lot', extra.batch), field('Centre de coûts', extra.cc), extra.loc ? field('Magasin', extra.loc) : null);
				}
				if (code === '309') {
					extra.mat = f4Field('Article prenant', 'material', function (r) { extra.m = r; });
					append(fields, extra.mat);
				}
				if (code === '311' || code === '312') {
					extra.loc = codeInp({ placeholder: code === '311' ? '500' : '110', maxlength: '10', value: code === '311' ? '500' : '' });
					append(fields, field('Magasin prenant', extra.loc, code === '311' ? 'magasin de production : 500 (bord de ligne)' : 'magasin de stockage'));
				}
				if (code === '411E' || code === '412E') {
					extra.so = codeInp({ placeholder: 'N° de commande', maxlength: '12', value: p.so || '' }); extra.line = num({ placeholder: '10', step: '10', value: p.line || '10' });
					append(fields, field('Commande client', extra.so), field('Poste', extra.line));
				}
				if (code === '924') {
					extra.cust = f4Field('Client (réceptionnaire)', 'customer', function (r) { extra.c = r; });
					extra.serial = codeInp({ placeholder: 'N° du conteneur', maxlength: '20' });
					extra.loc = S.session.mode === 'SIMPLE' ? codeInp({ placeholder: 'Magasin', maxlength: '10' }) : null;
					extra.xm = sel([['', '— article cédant (X) —']], '');
					get(sp('/serial-materials')).then(function (l) { l.forEach(function (m) { extra.xm.appendChild(h('option', { value: m.id, text: m.no + ' – ' + m.designation + (m.own ? ' (→ ' + m.own + ')' : '') })); }); if (l.length === 1) { extra.xm.value = l[0].id; } });
					q.value = '1';
					append(fields, extra.cust, field('Article cédant', extra.xm), field('Lot (n° du conteneur)', extra.serial), extra.loc ? field('Magasin', extra.loc) : null);
				}
				append(fields, field('Quantité', q), ['551', '309', '344', '343', '201', '501'].indexOf(code) >= 0 ? field('Motif', reason) : null, field('Texte', text));
				append(area, card('Mouvement ' + code, h('div', null, msg, fields, h('div', { class: 'lw-actions' }, btn('Enregistrer', 'lw-btn-key', function () {
					if (needStock && !picked) { status('error', 'Choisissez la ligne de stock (poste cédant).'); return; }
					var body = { movement: code, qty: q.value, reason: reason.value, text: text.value, stock_id: picked ? picked.stock_id : 0 };
					if (extra.cc) { body.cost_center = extra.cc.value; }
					if (extra.acc) { body.gl_account = extra.acc.value; }
					if (extra.m) { body.material_id = extra.m.id; }
					if (extra.batch) { body.batch = extra.batch.value; }
					if (extra.loc) { body.location = extra.loc.value; }
					if (extra.so) { body.so_no = extra.so.value; body.so_line = extra.line.value; }
					if (code === '924') { body.customer_id = extra.c ? extra.c.id : 0; body.material_id = extra.xm.value; body.serial = extra.serial.value; }
					act(msg, post(sp('/mb11'), body), function (d) {
						var pickedNo = picked ? picked.material.no : (extra.m ? extra.m.no : '');
						clear(pickBox); picked = null;
						var links = h('div', { class: 'lw-actions', style: { marginBottom: '14px' } });
						if (d.doc_no) { append(links, btn('Afficher le document ' + d.doc_no + ' (MB03)', 'lw-btn-secondary', function () { go('MB03', { no: d.doc_no }); })); }
						if (d.to_no) { append(links, btn('Quittancer dans LS26', 'lw-btn-key', function () { go('LS26', { material: pickedNo }); })); }
						append(msg, links);
						K.refreshSession();
					});
				})))));
			}
			mv.addEventListener('change', draw);
			append(el, card(null, h('div', { class: 'lw-form' }, field('Code mouvement', mv))), area);
			draw();
		});

		function mb03View(no, out) {
			clear(out); append(out, loading());
			get(sp('/mb03/' + encodeURIComponent(no))).then(function (d) {
				clear(out);
				var rows = d.items.map(function (i) {
					var tabs = [i.cost_center ? 'CC ' + i.cost_center : '', i.gl_account ? 'compte ' + i.gl_account : '', i.special_ref ? 'stock E ' + i.special_ref : '', i.vendor_batch ? 'lot fourn. ' + i.vendor_batch : '', i.mfg_date ? 'fab. ' + date(i.mfg_date) : '', i.cert ? 'certificat ' + (i.cert === 'O' ? 'oui' : 'non') : '', Number(i.pack_count) ? i.pack_count + ' × ' + qty(i.pack_qty) : '', i.tus && i.tus !== '1' ? 'TUS ' + i.tus : ''].filter(Boolean).join(' · ');
					return { cls: Number(i.reversed) ? 'expired' : '', cells: [i.line_no, h('span', { title: i.movement_label }, mvBadge(i.movement, i.qty < 0)), matCell(i.material), i.location, mono(i.bin), i.batch, K.STATUS[i.status] || i.status, qty(i.qty) + ' ' + i.material.uom, chf(i.value), [i.reason, tabs ? h('div', { class: 'lw-small lw-muted', text: tabs }) : null], Number(i.reversed) ? h('span', { class: 'lw-badge red', text: 'Annulé' }) : ''] };
				});
				var doc = card(null, h('div', null,
					h('div', { class: 'lw-inline', style: { justifyContent: 'space-between' } }, h('h3', { text: 'Document d\'article ' + d.doc_no }), h('div', null, d.tcode ? mono(d.tcode) : null, ' ', d.rev_of ? h('span', { class: 'lw-badge red', text: 'Annulation de ' + d.rev_of }) : null)),
					h('div', { class: 'lw-grid2' }, dl([['Date comptable', date(d.posting_date)], ['Saisi le', dt(d.created_at)], ['Par', d.created_by]]), dl([['Référence', d.ref_type ? d.ref_type + ' ' + (d.ref_no || '') : ''], ['Texte', d.text], ['Annulé par', d.reversed_by.join(', ')]])),
					table(['Poste', 'Mvt', 'Article', 'Magasin', 'Emplacement', 'Lot', 'Statut', 'Quantité', 'Valeur CHF', 'Motif / onglets', ''], rows, [7, 8])));
				var acts = h('div', { class: 'lw-actions lw-noprint' }, K.printBtn(doc));
				if (d.items.some(function (i) { return Number(i.reversible); })) { append(acts, btn('Annuler ce document (MBST)', 'lw-btn-danger', function () { go('MBST', { no: d.doc_no }); })); }
				if (d.ref_type === 'PO' && d.ref_no) { append(acts, btn('Commande ' + d.ref_no, 'lw-btn-secondary', function () { go('ME22N', { no: d.ref_no }); })); }
				append(out, doc, acts);
			}).catch(function (e) { fail(out, e); });
		}
		tx('MB03', 'Afficher un document d\'article', 'Réception et stock', '', function (el, p) {
			var out = h('div');
			var input = onEnter(codeInp({ placeholder: '4900000001', value: p.no || '' }), function () { mb03View(input.value.trim(), out); });
			append(el, card(null, h('div', { class: 'lw-inline' }, field('Document d\'article', input), btn('Afficher', 'lw-btn-primary', function () { mb03View(input.value.trim(), out); }), btn('Rechercher (MB51)', 'lw-btn-secondary', function () { go('MB51'); }))), out);
			if (p.no) { mb03View(p.no, out); }
		});

		tx('MBST', 'Annuler un document d\'article', 'Réception et stock', 'GR|INV|PP|WH|TRA', function (el, p) {
			var out = h('div');
			var input = onEnter(codeInp({ placeholder: '4900000001', value: p.no || '' }), load);
			function load() {
				var no = input.value.trim();
				if (!no) { return; }
				clear(out); append(out, loading());
				get(sp('/mb03/' + encodeURIComponent(no))).then(function (d) {
					clear(out);
					var msg = h('div');
					var checks = [];
					var rows = d.items.map(function (i) {
						var cb = h('input', { type: 'checkbox', checked: Number(i.reversible) ? true : null, disabled: Number(i.reversible) ? null : true, 'aria-label': 'Annuler le poste ' + i.line_no });
						checks.push([cb, i.line_no]);
						return { cls: Number(i.reversed) ? 'expired' : '', cells: [cb, i.line_no, mvBadge(i.movement, i.qty < 0), matCell(i.material), i.location + (i.bin ? ' · ' + i.bin : ''), i.batch, qty(i.qty) + ' ' + i.material.uom, Number(i.reversed) ? 'Déjà annulé' : (Number(i.reversible) ? 'Annulable' : 'Non annulable ici')] };
					});
					var reason = inp({ placeholder: 'Motif de l\'annulation', maxlength: '120' });
					append(out, card('Document ' + d.doc_no + ' du ' + date(d.posting_date) + (d.ref_no ? ' · réf. ' + d.ref_no : ''), h('div', null, msg,
						hint('Décochez les postes que vous ne voulez pas annuler. Un transfert (cédant et prenant) s\'annule entièrement. Le mouvement inverse est créé (101 → 102, 201 → 202, 311 → 312…).'),
						table(['', 'Poste', 'Mvt', 'Article', 'Lieu', 'Lot', 'Quantité', ''], rows, [6]),
						h('div', { class: 'lw-form', style: { marginTop: '10px' } }, field('Motif', reason)),
						h('div', { class: 'lw-actions' }, btn('Enregistrer l\'annulation', 'lw-btn-danger', function () {
							var lines = checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; });
							act(msg, post(sp('/mbst'), { doc_no: d.doc_no, lines: lines, reason: reason.value }), function (r) { append(msg, h('div', { class: 'lw-actions' }, btn('Afficher l\'annulation ' + r.doc_no, 'lw-btn-secondary', function () { go('MB03', { no: r.doc_no }); }))); K.refreshSession(); });
						})))));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-inline' }, field('N° de document d\'article', input), btn('Charger', 'lw-btn-primary', load), btn('Trouver le document (MB51)', 'lw-btn-secondary', function () { go('MB51'); }))), out);
			if (p.no) { load(); }
		});

		/* MB51 enrichi : lot, période, document cliquable (MB03). */
		tx('MB51', 'Liste des documents de mouvement', 'Réception et stock', '', function (el, p) {
			var out = h('div');
			var mat = codeInp({ placeholder: 'Article', value: p.material || '' });
			var bat = codeInp({ placeholder: 'Lot', 'data-noscan': '1' });
			var mv = codeInp({ placeholder: 'ex. 101', maxlength: '3', inputmode: 'numeric', 'data-noscan': '1' });
			var from = inp({ type: 'date' }), to = inp({ type: 'date' });
			function run() {
				clear(out); append(out, loading());
				get(sp('/mdocs'), { material: mat.value.trim(), batch: bat.value.trim(), movement: mv.value.trim(), from: from.value, to: to.value }).then(function (r) {
					clear(out);
					append(out, hint('Cliquez sur une ligne pour ouvrir le document d\'article (MB03) et ses onglets.'));
					append(out, table(['Document', 'Date', 'Mvt', 'Article', 'Magasin', 'Emplacement', 'Lot', 'Statut', 'Quantité', 'Valeur CHF', 'Référence', 'Motif'], r.rows.map(function (x) {
						return { cls: Number(x.reversed) ? 'expired' : '', onclick: function () { go('MB03', { no: x.doc_no }); }, cells: [mono(x.doc_no), date(x.posting_date), h('span', { title: x.movement_label, class: 'lw-badge ' + (x.qty < 0 ? 'red' : 'green'), text: x.movement_type }), [mono(x.material_no), ' ', x.designation], x.location, mono(x.bin || ''), x.batch, (K.STATUS[x.stock_status] || x.stock_status) + (x.special_ref ? ' ' + x.special_ref : ''), qty(x.qty) + ' ' + x.base_uom, chf(x.value), [x.ref_no, x.rev_of ? h('div', { class: 'lw-small', text: 'annule ' + x.rev_of }) : null], x.reason] };
					}), [8, 9]));
				}).catch(function (e) { fail(out, e); });
			}
			[mat, bat, mv].forEach(function (i) { onEnter(i, run); });
			append(el, card(null, h('div', { class: 'lw-form' }, field('Article', mat), field('Lot', bat), field('Code mouvement', mv), field('Du', from), field('Au', to), btn('Afficher', 'lw-btn-primary', run))), out);
			run();
		});

		function ls26View(mno, loc, out, notice) {
			clear(out); append(out, loading());
			get(sp('/ls26'), { material: mno, location: loc }).then(function (d) {
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				append(out, msg);
				if (d.material) { append(out, card(d.material.no + ' – ' + d.material.designation, h('div', { class: 'lw-grid3' }, dl([['Unité', d.material.uom], ['Disponible', qty(d.material.available) + ' ' + d.material.uom]]), dl(d.totals.map(function (t) { return ['Type ' + t.location, qty(t.qty)]; })), dl([['Profil', d.material.profile_label], ['Vrac', d.material.bulk ? 'Oui (citerne)' : 'Non']])), 'ink')); }
				append(out, card('Quants par type de magasin et emplacement', d.quants.length ? table(['Type', 'Emplacement', 'Article', 'Lot', 'DLC', 'Statut', 'Stock spécial', 'Entrée', 'Quantité', ''], d.quants.map(function (q) {
					return { cells: [h('span', { title: q.location_name, text: q.location }), mono(q.bin), matCell(q.material), q.batch, date(q.bbd), badge(q.status), q.special_ref ? mono('E ' + q.special_ref) : '', date(q.gr_date), qty(q.qty) + ' ' + q.material.uom, q.location === '100' && !Number(q.putaway_open) ? btn('Créer OT d\'entreposage', 'lw-btn-secondary lw-btn-sm', function () { act(msg, post(sp('/ls26/putaway'), { stock_id: q.stock_id }), function (r2, m2) { ls26View(mno, loc, out, m2); }); }) : ''] };
				}), [8]) : h('p', { class: 'lw-muted', text: 'Aucun stock.' })));
				append(out, card('En transfert : ordres de transfert à quittancer', d.moves.length ? table(['Unité de stock', 'OT', 'Type', 'Article', 'Lot', 'De', 'Vers', 'Quantité', ''], d.moves.map(function (m) {
					return { cells: [mono(m.su), link(m.to_no, 'LT12', { no: m.to_no }), m.type_label, matCell(m.material), m.batch, mono(m.src), mono(m.dst), qty(m.qty) + ' ' + m.material.uom, Number(m.su_confirm) ? btn('Quittancer', 'lw-btn-key lw-btn-sm', function () { act(msg, post(sp('/lt13'), { su: m.su }), function (r2, m2) { ls26View(mno, loc, out, m2); K.refreshSession(); }); }) : btn(m.bulk ? 'LT12 (perte de citerne)' : 'LT12', 'lw-btn-secondary lw-btn-sm', function () { go('LT12', { no: m.to_no }); })] };
				}), [7]) : h('p', { class: 'lw-muted', text: 'Aucun ordre de transfert ouvert.' })));
			}).catch(function (e) { fail(out, e); });
		}
		tx('LS26', 'Stock de l\'entrepôt (gestion des emplacements)', 'Entrepôt', 'WH|PUT|PICK|INV|GR|PP', function (el, p) {
			var out = h('div');
			var mat = f4Field('Article', 'material', function (r) { mat.input.value = r.no; ls26View(r.no, loc.value.trim(), out); });
			var loc = codeInp({ placeholder: 'ex. 999', maxlength: '10', value: p.location || '' });
			onEnter(loc, function () { ls26View(mat.input.value.trim(), loc.value.trim(), out); });
			append(el, card(null, h('div', { class: 'lw-form' }, mat, field('Type de magasin', loc), btn('Afficher', 'lw-btn-primary', function () { ls26View(mat.input.value.trim(), loc.value.trim(), out); }))), out);
			append(el, hint('Quittancer = confirmer l\'ordre de transfert une fois la marchandise déplacée (LT13 par unité de stock). Un vrac se quittance dans LT12 pour déduire la perte de citerne.'));
			if (p.material) { mat.input.value = p.material; }
			if (p.material || p.location) { ls26View(p.material || '', p.location || '', out); }
		});

		tx('LT13', 'Confirmer un OT par unité de stock', 'Entrepôt', 'WH|PUT', function (el, p) {
			var msg = h('div');
			var su = onEnter(codeInp({ placeholder: '1000000123', maxlength: '12', value: p.su || '', inputmode: 'numeric' }), send);
			function send() { act(msg, post(sp('/lt13'), { su: su.value.trim() }), function () { su.value = ''; K.refreshSession(); }); }
			append(el, card(null, h('div', null, msg, hint('Le numéro d\'unité de stock figure dans LS26 (colonne « Unité de stock ») et sur l\'étiquette de la palette.'), h('div', { class: 'lw-inline' }, field('N° d\'unité de stock', su), btn('Confirmer', 'lw-btn-key', send), btn('LS26', 'lw-btn-secondary', function () { go('LS26'); })))));
		});

		tx('LT15', 'Annuler un ordre de transfert', 'Entrepôt', 'WH|PICK|PUT|PP', function (el, p) {
			var msg = h('div');
			var no = onEnter(codeInp({ placeholder: '0000000012', value: p.no || '' }), send);
			function send() { act(msg, post(sp('/lt15'), { to_no: no.value.trim() }), function () { K.refreshSession(); }); }
			var list = h('div');
			append(el, card(null, h('div', null, msg, hint('Seul un OT non confirmé s\'annule. Un prélèvement annulé laisse la marchandise à son emplacement : corrigez la livraison (VL02N) sans LT10.'), h('div', { class: 'lw-inline' }, field('Ordre de transfert', no), btn('Annuler l\'OT', 'lw-btn-danger', send)))), list);
			get(sp('/to')).then(function (rows) {
				append(list, card('Ordres de transfert ouverts', table(['OT', 'Type', 'Référence', 'Lignes'], rows.map(function (r) { return { cells: [mono(r.to_no), K.TO_LABEL[r.type] || r.type, r.ref_no || '—', r.n_lines], onclick: function () { no.value = r.to_no; } }; }), [3])));
			}).catch(function () {});
		});

		tx('LT31', 'Réimprimer un OT et ses étiquettes', 'Entrepôt', 'WH|PUT|PICK|PP', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '0000000012', value: p.no || '' }), load);
			var copies = num({ value: '1', min: '1', max: '20', step: '1' });
			var withDoc = h('input', { type: 'checkbox' }), withLabels = h('input', { type: 'checkbox', checked: true });
			function load() {
				clear(out); append(out, loading());
				get(sp('/to/' + encodeURIComponent(no.value.trim()))).then(function (to) {
					clear(out);
					var sheet = h('div', { class: 'lw-labels' });
					if (withDoc.checked) {
						append(sheet, card(null, h('div', null, docHead('Ordre de transfert ' + to.to_no, badge(to.status)), dl([['Type', to.type_label], ['Référence', to.ref_no || '—'], ['Créé le', dt(to.created_at)]]),
							table(['Poste', 'Article', 'Lot', 'De', 'Vers', 'Quantité'], to.items.map(function (i) { return { cells: [i.line_no, matCell(i.material), i.batch, mono(i.src), mono(i.dst || i.proposed), qty(i.qty) + ' ' + i.material.uom] }; }), [5]))));
					}
					if (withLabels.checked) {
						for (var c = 0; c < Math.max(1, Math.min(20, Number(copies.value) || 1)); c++) {
							to.items.forEach(function (i) {
								append(sheet, h('div', { class: 'lw-wm-label' }, h('div', { class: 'lw-wm-l1' }, h('strong', { text: i.material.no }), h('span', { text: 'OT ' + to.to_no + ' / ' + i.line_no })), h('div', { class: 'lw-wm-l2', text: i.material.designation }), h('div', { class: 'lw-wm-l3' }, h('span', { text: 'Lot ' + (i.batch || '—') }), h('span', { text: qty(i.qty) + ' ' + i.material.uom }), h('span', { class: 'lw-mono', text: '→ ' + (i.dst || i.proposed || '') }))));
							});
						}
					}
					append(out, sheet, h('div', { class: 'lw-actions lw-noprint' }, printOut(sheet, 'TO', to.to_no, 'LT31', (withDoc.checked ? 'document ' : '') + (withLabels.checked ? copies.value + ' étiquette(s)' : ''))));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('N° d\'OT', no), field('Quantité d\'étiquettes', copies), h('label', { class: 'lw-field' }, h('span', { text: 'Document OT' }), h('span', { class: 'lw-inline' }, withDoc, ' imprimer')), h('label', { class: 'lw-field' }, h('span', { text: 'Étiquettes' }), h('span', { class: 'lw-inline' }, withLabels, ' imprimer')), btn('Préparer', 'lw-btn-primary', load))), hint('Le n° d\'OT se retrouve dans LS26 (lot → document) ou dans MB51 (document → livraison → OT).'), out);
			if (p.no) { load(); }
		});

		tx('LI21', 'Solder les écarts (aire 999)', 'Entrepôt', 'WH|INV|CNT', function (el) {
			var out = h('div');
			function load() {
				clear(out); append(out, loading());
				get(sp('/li21')).then(function (rows) {
					clear(out);
					var msg = h('div');
					var checks = [];
					append(out, msg, hint('L\'aire des écarts 999 reçoit les pertes de citerne et les écarts de confirmation (codes d\'écart LT12) ainsi que la marchandise à détruire (emplacement SCHROTT, via LT10). LI21 sort ces quantités du stock (mouvement 711).'));
					if (!rows.length) { append(out, msgBox('ok', 'Aucun écart en attente dans l\'aire 999.')); return; }
					append(out, table(['', 'Emplacement', 'Article', 'Lot', 'Quantité', 'Entrée'], rows.map(function (q) { var cb = h('input', { type: 'checkbox', checked: true }); checks.push([cb, q.stock_id]); return { cells: [cb, mono(q.bin), matCell(q.material), q.batch, qty(q.qty) + ' ' + q.material.uom, date(q.gr_date)] }; }), [4]));
					append(out, h('div', { class: 'lw-actions' }, btn('Comptabiliser les écarts sélectionnés', 'lw-btn-danger', function () {
						act(msg, post(sp('/li21'), { stock_ids: checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; }) }), function () { setTimeout(load, 900); K.refreshSession(); });
					})));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, out);
			load();
		});

		/* ══════════════════ LOT 2 · ACHAT ET RÉCEPTION ══════════════════ */

		tx('ME2L', 'Commandes d\'achat par fournisseur', 'Achats', 'PUR|GR', function (el, p) {
			var out = h('div');
			var sup = onEnter(inp({ placeholder: 'N° ou nom, ex. Simplon*', value: p.supplier || '' }), run);
			var mat = onEnter(codeInp({ placeholder: 'Article (facultatif)' }), run);
			var open = h('input', { type: 'checkbox', checked: true });
			function run() {
				clear(out); append(out, loading());
				get(sp('/me2l'), { supplier: sup.value.trim(), material: mat.value.trim(), open: open.checked ? '1' : '0' }).then(function (rows) {
					clear(out);
					append(out, table(['Fournisseur', 'Commande', 'Type', 'Poste', 'Article', 'Commandé', 'Reçu', 'Reste', 'Livraison', 'Statut'], rows.map(function (r) {
						return { onclick: function () { go('ME23N', { no: r.po_no }); }, cells: [r.supplier_no + ' ' + r.supplier, mono(r.po_no), r.po_type === 'UB' ? h('span', { class: 'lw-badge', text: 'UB ' + r.ib_dlv_no }) : 'NB', r.line_no, [mono(r.material_no), ' ', r.designation], qty(r.qty) + ' ' + r.base_uom, qty(r.qty_received), Number(r.final_delivery) ? h('span', { class: 'lw-badge', text: 'Livr. finale' }) : qty(r.open), date(r.delivery_date), badge(r.status)] };
					}), [5, 6, 7]));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Fournisseur', sup, 'le * remplace la fin du nom'), field('Article', mat), h('label', { class: 'lw-field' }, h('span', { text: 'Postes ouverts' }), h('span', { class: 'lw-inline' }, open, ' seulement')), btn('Exécuter', 'lw-btn-primary', run))), hint('Utile pour retrouver le n° de commande quand il ne figure pas sur le bulletin de livraison du fournisseur.'), out);
			if (p.supplier) { run(); }
		});

		tx('Y_P01', 'Arrivages fournisseurs planifiés', 'Achats', 'GR|PUR', function (el) {
			var out = h('div');
			var from = inp({ type: 'date', value: today() }), to = inp({ type: 'date', value: K.addDays(today(), 7) });
			function run() {
				clear(out); append(out, loading());
				get(sp('/arrivals'), { from: from.value, to: to.value }).then(function (d) {
					clear(out);
					if (d.late) { append(out, msgBox('warn', d.late + ' poste(s) attendu(s) avant le ' + date(d.from) + ' ne sont pas encore reçus (retard fournisseur).')); }
					append(out, table(['Livraison', 'Commande', 'Fournisseur', 'Article', 'Reste attendu', 'Arrivage'], d.rows.map(function (r) {
						var how = r.po_type === 'UB' ? 'Transfert entre sites, bulletin ' + r.ib_dlv_no : (Number(r.bulk) ? 'Vrac : ' + (r.transport === 'WAGON' ? 'wagon-citerne' : 'camion-citerne') : (r.country !== 'CH' ? 'International (' + r.country + ', CMR)' : ''));
						return { onclick: function () { go(r.po_type === 'UB' ? 'MIGO_GR' : 'MIGO', r.po_type === 'UB' ? { bulletin: r.ib_dlv_no } : { po: r.po_no }); }, cells: [date(r.delivery_date), mono(r.po_no), r.supplier, [mono(r.material_no), ' ', r.designation, r.adr_class ? h('span', { class: 'lw-chip adr', text: 'ADR ' + r.adr_class }) : null], qty(r.open) + ' ' + r.base_uom, how] };
					}), [4]));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Division', h('span', { class: 'lw-mono', text: '1000' })), field('Livraison du', from), field('au', to), btn('Exécuter', 'lw-btn-primary', run))), out);
			run();
		});

		function poEdit(no, out, notice) {
			clear(out); append(out, loading());
			get(sp('/po2/' + encodeURIComponent(no))).then(function (po) {
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				var ro = S.session.readonly;
				var edits = [];
				var rows = po.items.map(function (i) {
					var e = { item_id: i.id, i: i };
					e.qty = num({ value: i.qty, disabled: Number(i.deleted) || ro ? true : null, 'aria-label': 'Quantité poste ' + i.line_no });
					e.final = h('input', { type: 'checkbox', checked: Number(i.final_delivery) ? true : null, disabled: Number(i.deleted) || ro ? true : null, 'aria-label': 'Livraison finale poste ' + i.line_no });
					e.del = h('input', { type: 'checkbox', disabled: Number(i.deleted) || i.qty_received > 0 || ro ? true : null, 'aria-label': 'Supprimer le poste ' + i.line_no });
					edits.push(e);
					return { cls: Number(i.deleted) ? 'expired' : '', cells: [i.line_no, matCell(i.material), e.qty, qty(i.qty_received), qty(i.open), date(i.delivery_date), h('label', { class: 'lw-inline' }, e.final, ' livr. finale'), Number(i.deleted) ? h('span', { class: 'lw-badge red', text: 'Supprimé' }) : h('label', { class: 'lw-inline' }, e.del, ' supprimer')] };
				});
				var head = h('div', { class: 'lw-grid2' }, dl([['Type', po.type_label], ['Fournisseur', po.supplier ? po.supplier.no + ' – ' + po.supplier.name : ''], po.po_type === 'UB' ? ['Site cédant', po.supply_site + ' – ' + po.supply_name] : null, po.ib_dlv_no ? ['Bulletin du site cédant', mono(po.ib_dlv_no)] : null]), dl([['Date de commande', date(po.order_date)], ['Statut', badge(po.status)], po.transport ? ['Transport', po.transport === 'WAGON' ? 'Wagon-citerne' : 'Camion-citerne'] : null, ['Remarque', po.note]]));
				var tabs = {};
				tabs['Postes'] = function () {
					return h('div', null, msg, table(['Poste', 'Article', 'Quantité commandée', 'Reçu', 'Reste', 'Livraison', 'Livraison finale', ''], rows, [2, 3, 4]),
						hint('Livraison finale décochée : le solde reste attendu chez l\'acheteur. Quantité réellement reçue inférieure (vrac) : réduisez la quantité commandée.'),
						ro ? null : h('div', { class: 'lw-actions' }, btn('Enregistrer', 'lw-btn-key', function () {
							var ch = [];
							edits.forEach(function (e) {
								if (Number(e.i.deleted)) { return; }
								var c = { item_id: e.item_id };
								var any = false;
								if (Number(e.qty.value) !== Number(e.i.qty)) { c.qty = e.qty.value; any = true; }
								if ((e.final.checked ? 1 : 0) !== Number(e.i.final_delivery)) { c.final = e.final.checked ? '1' : '0'; any = true; }
								if (e.del.checked) { c['delete'] = 1; any = true; }
								if (any) { ch.push(c); }
							});
							act(msg, post(sp('/po2/' + encodeURIComponent(po.po_no) + '/change'), { changes: ch }), function (r2, m2) { poEdit(po.po_no, out, m2); });
						})));
				};
				tabs['Historique des achats'] = function () {
					return po.gr_docs.length ? table(['Document', 'Date', 'Transaction', 'Annule'], po.gr_docs.map(function (g) { return { onclick: function () { go('MB03', { no: g.doc_no }); }, cells: [mono(g.doc_no), date(g.posting_date), mono(g.tcode || 'MIGO'), g.rev_of || ''] }; })) : h('p', { class: 'lw-muted', text: 'Aucune entrée de marchandises.' });
				};
				tabs['Messages (document EM)'] = function () {
					var box = h('div', null, loading());
					get(sp('/po2/' + encodeURIComponent(po.po_no) + '/gr-doc')).then(function (g) {
						clear(box);
						var doc = card(null, h('div', null, docHead('Document d\'entrée de marchandises', h('span', { class: 'lw-mono', text: 'ZWEZ' })),
							h('div', { class: 'lw-grid2' }, dl([['Commande', mono(g.po_no)], ['Fournisseur', g.supplier ? g.supplier.no + ' – ' + g.supplier.name : ''], g.ib_dlv_no ? ['Bulletin', g.ib_dlv_no] : null]), dl([['Imprimé le', date(g.date)], ['Statut', K.STATUS[g.status] || g.status], g.transport ? ['Arrivage', g.transport === 'WAGON' ? 'Wagon-citerne' : 'Camion-citerne'] : null])),
							table(['Poste', 'Article', 'Commandé', 'Déjà reçu', 'Solde attendu', 'Livraison'], g.items.map(function (i) { return { cells: [i.line_no, matCell(i.material), qty(i.qty) + ' ' + i.material.uom, qty(i.received), Number(i.final) ? 'Livraison finale' : h('strong', { text: qty(i.open) }), date(i.delivery_date)] }; }), [2, 3, 4]),
							h('div', { class: 'lw-sign' }, h('span', { text: 'Contrôlé par : ……………………' }), h('span', { text: 'Date : ……………' }))));
						append(box, outputsTable(po.outputs), h('h4', { text: 'Aperçu du message ZWEZ' }), doc, h('div', { class: 'lw-actions lw-noprint' }, printOut(doc, 'PO', po.po_no, 'ZWEZ', 'solde')));
					}).catch(function (e) { fail(box, e); });
					return box;
				};
				tabs['Modifications'] = function () { return changesTable(po.changes); };
				append(out, card('Commande ' + po.po_no, h('div', null, head, tabbed(tabs))), h('div', { class: 'lw-actions' }, ['ENVOYEE', 'PARTIELLEMENT_RECUE'].indexOf(po.status) >= 0 ? btn(po.po_type === 'UB' ? 'Réceptionner (MIGO_GR)' : 'Réceptionner (MIGO)', 'lw-btn-key', function () { go(po.po_type === 'UB' ? 'MIGO_GR' : 'MIGO', po.po_type === 'UB' ? { bulletin: po.ib_dlv_no } : { po: po.po_no }); }) : null));
			}).catch(function (e) { fail(out, e); });
		}
		tx('ME22N', 'Modifier une commande d\'achat', 'Achats', 'PUR|GR', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '4500000001', value: p.no || '' }), function () { poEdit(no.value.trim(), out); });
			append(el, card(null, h('div', { class: 'lw-inline' }, field('Autre commande', no), btn('Charger', 'lw-btn-primary', function () { poEdit(no.value.trim(), out); }), btn('Trouver (ME2L)', 'lw-btn-secondary', function () { go('ME2L'); }))), out);
			if (p.no) { poEdit(p.no, out); } else { append(out, K.docPicker('po', 'Commandes d\'achat', function (n) { no.value = n; poEdit(n, out); }, false)); }
		});

		tx('MIGO_GR', 'Entrée de marchandises : transfert entre sites', 'Réception et stock', 'GR', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '80500001', value: p.bulletin || '' }), load);
			function load() {
				var v = no.value.trim();
				if (!v) { return; }
				clear(out); append(out, loading());
				get(sp('/bulletin/' + encodeURIComponent(v))).then(function (r) { K.grForm(r.po_no, out); }).catch(function (e) { fail(out, e); });
			}
			var list = h('div');
			append(el, card(null, h('div', null, h('div', { class: 'lw-inline' }, field('Livraison entrante (UMLAGERUNG) – n° de bulletin', no), btn('Charger', 'lw-btn-primary', load)),
				hint('Contrôlez l\'onglet Emballage : pour des sacs, si le TUS indique 11 ou 12, mettez 1, sinon la marchandise part au magasin grande hauteur alors qu\'elle repart dans les 24 heures. Erreur de lot : saisissez le lot réellement reçu.'))), list, out);
			if (p.bulletin) { load(); } else {
				get(sp('/bulletins')).then(function (rows) { append(list, card('Bulletins attendus', table(['Bulletin', 'Commande', 'Date', 'Statut'], rows.map(function (r) { return { onclick: function () { no.value = r.ib_dlv_no; load(); }, cells: [mono(r.ib_dlv_no), mono(r.po_no), date(r.order_date), badge(r.status)] }; })))); }).catch(function () {});
			}
		});

		/* ══════════════════ LOT 3 · EXPÉDITION ET VENTE ══════════════════ */

		/* VL02N : l'écran existant (emballage, sortie) devient le premier onglet. */
		var vl02nOrig = K.TX.VL02N ? K.TX.VL02N.render : null;
		function dlvChange(no, out, notice) {
			clear(out); append(out, loading());
			get(sp('/dlv2/' + encodeURIComponent(no))).then(function (d) {
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				append(out, msg);
				if (d.status === 'SUPPRIMEE') { append(out, msgBox('info', 'Livraison supprimée : son n° de bulletin peut être repris lors de la prochaine livraison de la commande (VL10).')); return; }
				if (Number(d.delayed)) { append(out, msgBox('warn', 'Positions cochées : la confirmation de commande indiquera la date reportée. Enlevez la coche dans VA02 (disponibilité) avant la sortie.')); }
				if (!Number(d.changeable)) { append(out, msgBox('info', d.status === 'EXPEDIEE' ? 'Livraison expédiée : annulez la sortie (VL09) pour la modifier.' : 'Un ordre de prélèvement est en cours : annulez-le d\'abord (LT15) si la marchandise n\'est pas encore prélevée.')); }
				var edits = [];
				var rows = d.items_edit.map(function (i) {
					var e = { i: i };
					e.qty = num({ value: i.qty, disabled: Number(d.changeable) ? null : true, 'aria-label': 'Quantité poste ' + i.so_line });
					e.batch = i.batches.length ? sel([['', '— selon la stratégie —']].concat(i.batches.map(function (b) { return [b.batch, b.batch + ' · ' + qty(b.qty) + (b.bbd ? ' · DLC ' + date(b.bbd) : '')]; })), i.batch, { disabled: Number(d.changeable) ? null : true, 'aria-label': 'Lot poste ' + i.so_line }) : null;
					e.del = h('input', { type: 'checkbox', disabled: Number(d.changeable) ? null : true });
					edits.push(e);
					var picked = i.picked.map(function (b) { return b.batch + ' (' + qty(b.qty) + ')'; }).join(', ');
					return { cells: [i.so_line, matCell(i.i ? i.i.material : i.material), e.qty, e.batch || (i.material.batch_managed ? '—' : 'sans lot'), picked || '—', Number(i.delayed) ? h('span', { class: 'lw-badge yellow', text: 'Cochée (' + date(i.confirmed_date) + ')' }) : '', h('label', { class: 'lw-inline' }, e.del, ' supprimer')] };
				});
				append(out, table(['Poste', 'Article', 'Quantité de livraison', 'Lot imposé', 'Lots prélevés', 'Position', ''], rows, [2]));
				var acts = h('div', { class: 'lw-actions' });
				if (Number(d.changeable)) {
					append(acts, btn('Enregistrer les postes', 'lw-btn-key', function () {
						var ch = [];
						edits.forEach(function (e) {
							var c = { item_id: e.i.id }, any = false;
							if (e.del.checked) { c['delete'] = 1; any = true; }
							if (Number(e.qty.value) !== Number(e.i.qty)) { c.qty = e.qty.value; any = true; }
							if (e.batch && e.batch.value !== e.i.batch) { c.batch = e.batch.value; any = true; }
							if (any) { ch.push(c); }
						});
						act(msg, post(sp('/dlv2/' + encodeURIComponent(d.dlv_no) + '/change'), { changes: ch }), function (r2, m2) { dlvChange(d.dlv_no, out, m2); });
					}));
				}
				if (Number(d.can_create_to)) { append(acts, btn('Créer l\'OT de prélèvement', 'lw-btn-primary', function () { act(msg, post(sp('/dlv2/' + encodeURIComponent(d.dlv_no) + '/create-to')), function (r, m2) { dlvChange(d.dlv_no, out, m2); K.refreshSession(); }); })); }
				if (Number(d.deletable)) {
					append(acts, btn('Supprimer la livraison', 'lw-btn-danger', function () {
						var close = modal('Supprimer la livraison ' + d.dlv_no, h('div', null, h('p', { text: 'Les paquets sont défaits et les OT ouverts annulés. Si la marchandise est déjà prélevée, elle reste au quai d\'expédition (700) : remettez-la en stock avec LT10.' })), { buttons: btn('Supprimer', 'lw-btn-danger', function () { close(); act(msg, post(sp('/dlv2/' + encodeURIComponent(d.dlv_no) + '/delete')), function (r2, m2) { dlvChange(d.dlv_no, out, m2); K.refreshSession(); }); }) });
					}));
				}
				if (d.status === 'EXPEDIEE') { append(acts, btn('Annuler la sortie (VL09)', 'lw-btn-danger', function () { go('VL09', { no: d.dlv_no }); })); }
				append(out, acts, h('h4', { text: 'Modifications' }), changesTable(d.changes));
			}).catch(function (e) { fail(out, e); });
		}
		function dlvMessages(no, out) {
			clear(out); append(out, loading());
			Promise.all([get(sp('/dlv2/' + encodeURIComponent(no))), get(sp('/dlv2/' + encodeURIComponent(no) + '/note'))]).then(function (r) {
				var d = r[0], n = r[1];
				clear(out);
				append(out, h('h4', { text: 'Messages imprimés' }), outputsTable(d.outputs));
				var bl = noteDoc(n);
				append(out, h('h4', { text: 'Message ZPAL : bulletin de livraison' }), bl, h('div', { class: 'lw-actions lw-noprint' }, printOut(bl, 'DLV', n.dlv_no, 'ZPAL')));
				var certMsg = h('div');
				var certBox = h('div', null, certMsg);
				append(out, h('h4', { text: 'Message de poste : certificat d\'analyse' }), certBox);
				var certItems = d.items_edit.filter(function (i) { return i.material.batch_managed; });
				if (!certItems.length) { append(certBox, h('p', { class: 'lw-muted', text: 'Aucun article géré par lot dans cette livraison.' })); }
				certItems.forEach(function (i) {
					append(certBox, btn('Certificat : poste ' + i.so_line + ' – ' + i.material.no, 'lw-btn-secondary', function () {
						get(sp('/dlv2/' + encodeURIComponent(no) + '/cert/' + i.id)).then(function (c) {
							var doc = card(null, h('div', null, docHead('Certificat d\'analyse', h('span', { class: 'lw-mono', text: 'ZCOA' })),
								dl([['Client', c.customer ? c.customer.no + ' – ' + c.customer.name : ''], ['Livraison', c.dlv_no + ' · commande ' + c.so_no], ['Produit', c.material.no + ' – ' + c.material.designation], ['Date', date(c.date)]]),
								c.batches.map(function (b) { return h('div', null, h('h4', { text: 'Lot ' + b.batch + ' · ' + qty(b.qty) + ' ' + c.material.uom }), table(['Caractéristique', 'Résultat', 'Spécification'], b.results.map(function (x) { return { cells: x }; }))); }),
								h('p', { class: 'lw-small', text: 'Nous certifions que le produit livré est conforme aux spécifications ci-dessus. Document établi électroniquement, valable sans signature.' })));
							var close = modal('Certificat d\'analyse', h('div', null, doc), { buttons: printOut(doc, 'DLV', no, 'ZCOA', 'poste ' + i.so_line) });
							return close;
						}).catch(function (e) { clear(certMsg); append(certMsg, msgBox('error', e.message)); status('error', e.message); });
					}));
				});
			}).catch(function (e) { fail(out, e); });
		}
		tx('VL02N', 'Modifier une livraison (préparer, expédier, corriger)', 'Ventes et expédition', 'SD|SHIP|PICK', function (el, p) {
			var no = p.no || '';
			var tabs = {};
			tabs['Emballage et sortie'] = function () { var x = h('div'); if (vl02nOrig) { vl02nOrig(x, p); } return x; };
			tabs['Postes (lot, quantité, OT)'] = function () {
				var out = h('div');
				var input = onEnter(codeInp({ placeholder: '0080000001', value: no }), function () { no = input.value.trim(); dlvChange(no, out); });
				var w = h('div', null, h('div', { class: 'lw-inline' }, field('Livraison', input), btn('Charger', 'lw-btn-primary', function () { no = input.value.trim(); dlvChange(no, out); })), out);
				if (no) { dlvChange(no, out); }
				return w;
			};
			tabs['Messages (ZPAL, certificat)'] = function () {
				var out = h('div');
				var input = onEnter(codeInp({ placeholder: '0080000001', value: no }), function () { no = input.value.trim(); dlvMessages(no, out); });
				var w = h('div', null, h('div', { class: 'lw-inline' }, field('Livraison', input), btn('Charger', 'lw-btn-primary', function () { no = input.value.trim(); dlvMessages(no, out); })), out);
				if (no) { dlvMessages(no, out); }
				return w;
			};
			append(el, tabbed(tabs));
		});

		tx('VL06O', 'Moniteur des livraisons (sortie collective)', 'Ventes et expédition', 'SD|SHIP|PICK', function (el) {
			var out = h('div');
			function load() {
				clear(out); append(out, loading());
				get(sp('/vl06o')).then(function (rows) {
					clear(out);
					var msg = h('div');
					var checks = [];
					var all = h('input', { type: 'checkbox', 'aria-label': 'Tout sélectionner', onchange: function () { checks.forEach(function (c) { if (!c[0].disabled) { c[0].checked = all.checked; } }); } });
					append(out, msg, table([all, 'Livraison', 'Commande', 'Client', 'Prio.', 'Livraison prévue', 'Statut', 'Transporteur', 'Poids kg', ''], rows.map(function (r) {
						var cb = h('input', { type: 'checkbox', disabled: Number(r.ready) ? null : true, 'aria-label': 'Sélectionner ' + r.dlv_no });
						checks.push([cb, r.dlv_no]);
						return { cells: [cb, link(r.dlv_no, 'VL02N', { no: r.dlv_no }), mono(r.so_no), [r.customer, Number(r.internal) ? h('span', { class: 'lw-badge', text: 'UMLAGERUNG' }) : null], r.priority, date(r.req_date), badge(r.status), r.carrier_code || (Number(r.shipment_id) ? 'Tournée' : '—'), qty(r.weight_kg), Number(r.ready) ? 'Prête pour la sortie' : (r.status === 'EN_PREPARATION' ? 'OT à confirmer' : (r.status === 'A_PREPARER' ? 'OT à créer' : 'À emballer'))] };
					}), [8]));
					append(out, h('div', { class: 'lw-actions' }, btn('Sortie de marchandises (sélection)', 'lw-btn-key', function () {
						var sel2 = checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; });
						act(msg, post(sp('/vl06o/issue'), { deliveries: sel2 }), function (d) { if (d.errors && d.errors.length) { append(msg, msgBox('warn', 'Non traitées', d.errors)); } if (d.warnings && d.warnings.length) { append(msg, msgBox('warn', 'Positions cochées', d.warnings)); } setTimeout(load, 1200); K.refreshSession(); });
					}), btn('Actualiser', 'lw-btn-secondary', load)));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, hint('Livraisons faites dans VL10 : sélectionnez celles qui sont prêtes (prélevées et emballées) et comptabilisez la sortie de marchandises en une fois.'), out);
			load();
		});

		tx('VL09', 'Annuler une sortie de marchandises', 'Ventes et expédition', 'SD|SHIP|RET', function (el, p) {
			var msg = h('div');
			var no = codeInp({ placeholder: 'Livraison ou retour', value: p.no || '' });
			var reason = inp({ placeholder: 'Motif', maxlength: '120' });
			append(el, card(null, h('div', null, msg, hint('Livraison : la sortie 601 est annulée (602), la marchandise revient au quai d\'expédition et la livraison redevient modifiable. Retour client non encore traité : l\'entrée du retour est annulée (652).'),
				h('div', { class: 'lw-form' }, field('N° de livraison ou de retour', no), field('Motif', reason)),
				h('div', { class: 'lw-actions' }, btn('Annuler la sortie', 'lw-btn-danger', function () { act(msg, post(sp('/vl09'), { no: no.value.trim(), reason: reason.value }), function () { K.refreshSession(); }); })))));
		});

		tx('VL74', 'Réimprimer les étiquettes d\'unités d\'expédition', 'Ventes et expédition', 'SHIP|PICK', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '0080000001', value: p.no || '' }), load);
			function load() {
				clear(out); append(out, loading());
				get(sp('/vl74/' + encodeURIComponent(no.value.trim()))).then(function (d) {
					clear(out);
					if (!d.units.length) { append(out, msgBox('info', 'Aucune unité d\'expédition (SSCC) pour cette livraison : les paquets se font dans HU02.')); return; }
					var sheet = h('div', { class: 'lw-labels' }, d.units.map(function (u, k) {
						return h('div', { class: 'lw-ship-label' }, h('div', { class: 'lw-wm-l1' }, h('strong', { text: d.customer ? d.customer.name : '' }), h('span', { text: (k + 1) + ' / ' + d.units.length })), h('div', { class: 'lw-wm-l2', text: d.customer ? d.customer.city : '' }), h('div', { class: 'lw-wm-l3' }, h('span', { text: 'Livraison ' + d.dlv_no }), h('span', { text: u.type })), h('div', { class: 'lw-sscc', text: '(00) ' + u.sscc }));
					}));
					append(out, table(['Unité', 'SSCC', 'Type', 'Statut'], d.units.map(function (u) { return { cells: [mono(u.hu_no), mono(u.sscc), u.type, u.status] }; })), sheet, h('div', { class: 'lw-actions lw-noprint' }, printOut(sheet, 'DLV', d.dlv_no, 'VL74', d.units.length + ' étiquette(s)')));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-inline' }, field('Livraison', no), btn('Afficher', 'lw-btn-primary', load))), out);
			if (p.no) { load(); }
		});

		function soEdit(no, out, notice) {
			clear(out); append(out, loading());
			get(sp('/so2/' + encodeURIComponent(no))).then(function (so) {
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				var ro = S.session.readonly || !Number(so.editable);
				var rr = [['', '—']].concat(Object.keys(so.reject_reasons).map(function (k) { return [k, k + ' – ' + so.reject_reasons[k]]; }));
				var edits = [];
				var rows = so.items.map(function (i) {
					var e = { i: i };
					e.qty = num({ value: i.qty, disabled: ro || i.reject_reason ? true : null, 'aria-label': 'Quantité poste ' + i.line_no });
					e.rej = sel(rr, i.reject_reason, { disabled: ro ? true : null, 'aria-label': 'Motif de refus poste ' + i.line_no });
					e.block = h('input', { type: 'checkbox', checked: Number(i.delivery_block) ? true : null, disabled: ro ? true : null });
					edits.push(e);
					return { cls: i.reject_reason ? 'expired' : '', cells: [i.line_no, matCell(i.material), e.qty, qty(i.qty_delivered), qty(i.in_delivery), qty(i.qty_reserved), i.stock_e ? mono(qty(i.stock_e)) : '', Number(i.delayed) ? h('span', { class: 'lw-badge yellow', text: 'Cochée · ' + date(i.confirmed_date) }) : date(i.confirmed_date), h('label', { class: 'lw-inline' }, e.block, ' bloqué'), e.rej] };
				});
				var req = inp({ type: 'date', value: so.req_date, disabled: ro ? true : null });
				var addBox = h('div');
				var adds = [];
				var addF = f4Field('Ajouter un poste', 'material', function (r) { var q = num({ placeholder: 'Quantité' }); adds.push({ m: r, q: q }); append(addBox, h('div', { class: 'lw-inline' }, matCell(r), q)); addF.input.value = ''; });
				var tabs = {};
				tabs['Postes'] = function () {
					return h('div', null, msg, table(['Poste', 'Article', 'Quantité', 'Livré', 'En livraison', 'Réservé', 'Stock E', 'Date confirmée', 'Blocage livr.', 'Motif de refus'], rows, [2, 3, 4, 5]),
						ro ? null : h('div', null, h('div', { class: 'lw-form', style: { marginTop: '10px' } }, field('Date de livraison souhaitée', req), addF), addBox,
							h('div', { class: 'lw-actions' },
								btn('Enregistrer', 'lw-btn-key', function () {
									var items = [];
									edits.forEach(function (e) {
										var c = { item_id: e.i.id }, any = false;
										if (Number(e.qty.value) !== Number(e.i.qty)) { c.qty = e.qty.value; any = true; }
										if (e.rej.value !== e.i.reject_reason) { c.reject = e.rej.value; any = true; }
										if ((e.block.checked ? 1 : 0) !== Number(e.i.delivery_block)) { c.block = e.block.checked ? '1' : '0'; any = true; }
										if (any) { items.push(c); }
									});
									act(msg, post(sp('/so2/' + encodeURIComponent(so.so_no) + '/change'), { req_date: req.value !== so.req_date ? req.value : '', items: items, add: adds.map(function (a) { return { material_id: a.m.id, qty: a.q.value }; }) }), function (r2, m2) { soEdit(so.so_no, out, m2); });
								}),
								so.items.some(function (i) { return Number(i.delayed); }) ? btn('Contrôler la disponibilité (enlever la coche)', 'lw-btn-secondary', function () { act(msg, post(sp('/so2/' + encodeURIComponent(so.so_no) + '/availability')), function (r2, m2) { soEdit(so.so_no, out, m2); }); }) : null)));
				};
				tabs['Environnement › Modifications'] = function () { return changesTable(so.changes); };
				tabs['Livraisons'] = function () { return so.deliveries.length ? table(['Livraison', 'Statut', 'Transporteur'], so.deliveries.map(function (x) { return { onclick: function () { go('VL02N', { no: x.dlv_no }); }, cells: [mono(x.dlv_no), badge(x.status), x.carrier_code || ''] }; })) : h('p', { class: 'lw-muted', text: 'Aucune livraison.' }); };
				append(out, card('Commande client ' + so.so_no, h('div', null, h('div', { class: 'lw-grid2' }, dl([['Donneur d\'ordre', so.customer ? so.customer.no + ' – ' + so.customer.name : ''], ['Localité', so.customer ? so.customer.city : ''], ['Saisie le', dt(so.created_at)]]), dl([['Statut', badge(so.status)], ['Livraison souhaitée', date(so.req_date)], ['Priorité', so.priority], ['Remarque', so.note]])), tabbed(tabs))));
			}).catch(function (e) { fail(out, e); });
		}
		tx('VA02', 'Modifier une commande client', 'Ventes et expédition', 'SD', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '0000010001', value: p.no || '' }), function () { soEdit(no.value.trim(), out); });
			append(el, card(null, h('div', { class: 'lw-inline' }, field('Commande', no), btn('Charger', 'lw-btn-primary', function () { soEdit(no.value.trim(), out); }), btn('Liste (VA05)', 'lw-btn-secondary', function () { go('VA05'); }))), out);
			if (p.no) { soEdit(p.no, out); } else { append(out, K.docPicker('so', 'Commandes clients', function (n) { no.value = n; soEdit(n, out); })); }
		});

		tx('VA05', 'Liste des commandes clients', 'Ventes et expédition', 'SD', function (el, p) {
			var out = h('div');
			var cust = onEnter(inp({ placeholder: 'N° ou nom du donneur d\'ordre', value: p.customer || '' }), run);
			var mat = onEnter(codeInp({ placeholder: 'Article' }), run);
			function run() {
				clear(out); append(out, loading());
				get(sp('/va05'), { customer: cust.value.trim(), material: mat.value.trim() }).then(function (rows) {
					clear(out);
					append(out, table(['Commande', 'Donneur d\'ordre', 'Poste', 'Article', 'Quantité', 'Livré', 'Livraison', 'Statut', 'Saisie du poste'], rows.map(function (r) {
						return { cls: r.reject_reason ? 'expired' : '', onclick: function () { go('VA02', { no: r.so_no }); }, cells: [mono(r.so_no), r.customer_no + ' ' + r.customer, r.line_no, [mono(r.material_no), ' ', r.designation], qty(r.qty) + ' ' + r.base_uom, qty(r.qty_delivered), Number(r.is_delayed) ? h('span', { class: 'lw-badge yellow', text: 'Cochée ' + date(r.confirmed_date) }) : date(r.req_date), r.reject_reason ? 'Refus ' + r.reject_reason : badge(r.status), dt(r.entered_at)] };
					}), [4, 5]));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Donneur d\'ordre', cust), field('Article', mat), btn('Exécuter', 'lw-btn-primary', run))), out);
			if (p.customer) { run(); }
		});

		/* ══════════════════ LOT 4 · PRODUCTION PAR PROCÉDÉ ══════════════════ */

		var SUP = null;
		function supervisors() { return SUP ? Promise.resolve(SUP) : get(sp('/cor8'), { status: 'OPEN' }).then(function (d) { SUP = d.supervisors; return SUP; }); }
		function supSel(value) {
			var s2 = sel([['', '— gestionnaire —']], value || '', { 'aria-label': 'Gestionnaire de production' });
			supervisors().then(function (m) { Object.keys(m).forEach(function (k) { var o = h('option', { value: k, text: k + ' – ' + m[k] }); if (k === value) { o.selected = true; } s2.appendChild(o); }); });
			return s2;
		}
		function orderBadge(o) { return h('span', { class: 'lw-badge ' + ({ CRTD: '', LANC: 'green', 'LANC CNFP': 'green', CNF: 'LIBRE', CLTE: 'red' }[o.sap_status] || ''), text: o.sap_status }); }

		tx('COR1', 'Créer un ordre de procédé', 'Production', 'PP', function (el, p) {
			var msg = h('div');
			var type = sel([['PI01', 'PI01 – Ordre de procédé (fabrication, conditionnement)'], ['SH08', 'SH08 – Changement de lot en citerne']], p.type || 'PI01');
			var mat = null;
			var fm = f4Field('Article', 'material', function (r) { mat = r; showBom(); });
			var q = num({ placeholder: 'Quantité' });
			var end = inp({ type: 'date', value: K.addDays(today(), 2), min: today() });
			var sup = supSel('');
			var bom = h('div');
			var tank = h('div');
			var picked = null;
			function showBom() {
				clear(bom); clear(tank); picked = null;
				if (!mat) { return; }
				if (type.value === 'SH08') {
					append(tank, card('Lot à remplacer dans la citerne', quantPicker(function (x) { picked = x; q.value = x.qty; clear(tank); append(tank, h('div', { class: 'lw-paper', text: 'Citerne ' + x.bin + ' · lot ' + x.batch + ' · ' + qty(x.qty) + ' ' + x.material.uom })); }, { material: mat.no, filter: function (x) { return x.status === 'LIBRE' && x.location === '310'; } })));
					return;
				}
				get(sp('/cs03/' + encodeURIComponent(mat.no))).then(function (b) {
					append(bom, card('Recette (CS03) : pour 1 ' + mat.uom, table(['Pos.', 'Composant', 'Quantité', 'Disponible'], b.items.map(function (i) { return { cells: [i.pos, matCell(i.material), qty(i.qty) + ' ' + i.material.uom, qty(i.available)] }; }), [2, 3])));
				}).catch(function (e) { fail(bom, e); });
			}
			type.addEventListener('change', showBom);
			append(el, card(null, h('div', null, msg, h('div', { class: 'lw-form' }, field('Type d\'ordre', type), fm, field('Quantité', q), field('Date de fin', end), field('Gestionnaire', sup, 'vide : celui de l\'article')),
				h('div', { class: 'lw-actions' }, btn('Créer l\'ordre', 'lw-btn-key', function () {
					act(msg, post(sp('/cor1'), { material_id: mat ? mat.id : 0, qty: q.value, end: end.value, type: type.value, supervisor: sup.value, stock_id: picked ? picked.stock_id : 0 }), function (d) { append(msg, h('div', { class: 'lw-actions' }, btn('Afficher ' + d.no + ' (COR3)', 'lw-btn-secondary', function () { go('COR3', { no: d.no }); }), btn('Lancer (COR5)', 'lw-btn-primary', function () { go('COR5'); }))); K.refreshSession(); });
				})))), tank, bom, hint('Au besoin, transférez d\'abord les composants au magasin de production avec MB11 311 (bord de ligne 500), puis quittancez le transfert dans LS26.'));
		});

		function corView(no, out, editable, notice) {
			clear(out); append(out, loading());
			get(sp('/cor/' + encodeURIComponent(no))).then(function (o) {
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				var ro = S.session.readonly || !editable;
				var canEdit = !ro && (o.status === 'CREE' || o.status === 'LANCE');
				var edits = [];
				var comps = o.components.map(function (c) {
					var e = { c: c, f: null };
					e.qty = num({ value: c.qty_required, disabled: canEdit && !c.qty_issued ? null : true });
					e.rm = h('input', { type: 'checkbox', disabled: canEdit && !c.qty_issued ? null : true });
					e.rep = canEdit && !c.qty_issued ? f4Field('Remplacer par', 'material', function (r) { e.f = r; }) : null;
					edits.push(e);
					return { cells: [matCell(c.material), e.qty, qty(c.qty_issued) + ' ' + c.material.uom, qty(c.available), e.rep || '', h('label', { class: 'lw-inline' }, e.rm, ' retirer')] };
				});
				var hq = num({ value: o.qty, disabled: canEdit ? null : true });
				var he = inp({ type: 'date', value: o.end_date, disabled: canEdit ? null : true });
				var hs = supSel(o.supervisor);
				if (!canEdit) { hs.disabled = true; }
				var adds = [];
				var addBox = h('div');
				var addF = canEdit ? f4Field('Ajouter un composant', 'material', function (r) { var q = num({ placeholder: 'Quantité' }); adds.push({ m: r, q: q }); append(addBox, h('div', { class: 'lw-inline' }, matCell(r), q)); addF.input.value = ''; }) : null;
				var tabs = {};
				tabs['Composants'] = function () {
					return h('div', null, msg, h('div', { class: 'lw-form' }, field('Quantité de l\'ordre', hq), field('Date de fin', he), field('Gestionnaire', hs)),
						table(['Composant', 'Quantité nécessaire', 'Consommé', 'Disponible', 'Changer d\'article', ''], comps, [1, 2, 3]), addF, addBox,
						canEdit ? h('div', { class: 'lw-actions' }, btn('Enregistrer', 'lw-btn-key', function () {
							var body = { components: [], add: adds.map(function (a) { return { material_id: a.m.id, qty: a.q.value }; }) };
							if (Number(hq.value) !== Number(o.qty)) { body.qty = hq.value; }
							if (he.value !== o.end_date) { body.end = he.value; }
							if (hs.value !== o.supervisor) { body.supervisor = hs.value; }
							edits.forEach(function (e) { var c = { id: e.c.id }, any = false; if (e.rm.checked) { c.remove = 1; any = true; } if (e.f) { c.material_id = e.f.id; any = true; } if (Number(e.qty.value) !== Number(e.c.qty_required)) { c.qty = e.qty.value; any = true; } if (any) { body.components.push(c); } });
							act(msg, post(sp('/cor/' + encodeURIComponent(o.prod_no) + '/change'), body), function (r2, m2) { corView(o.prod_no, out, editable, m2); });
						})) : null);
				};
				tabs['Confirmations'] = function () { return o.confirmations.length ? table(['Confirmation', 'Bonnes', 'Rebut', 'Lot', 'Document', 'Date', 'Annulée'], o.confirmations.map(function (c) { return { cls: Number(c.cancelled) ? 'expired' : '', cells: [mono(c.conf_no), qty(c.qty_good), qty(c.qty_scrap), c.batch, link(c.doc_no, 'MB03', { no: c.doc_no }), dt(c.created_at), Number(c.cancelled) ? 'Oui (' + c.cancel_reason + ', ' + c.cancel_doc + ')' : ''] }; }), [1, 2]) : h('p', { class: 'lw-muted', text: 'Aucune confirmation.' }); };
				tabs['Fonctions'] = function () {
					var box = h('div', null, msg);
					if (!ro) {
						append(box, h('div', { class: 'lw-actions' },
							o.status === 'CREE' ? btn('Lancer et imprimer', 'lw-btn-primary', function () { act(msg, post(sp('/cor5'), { orders: [o.prod_no] }), function (r2, m2) { corView(o.prod_no, out, editable, m2); }); }) : null,
							['LANCE', 'PARTIELLEMENT_CONFIRME'].indexOf(o.status) >= 0 ? btn('Confirmer (CORK)', 'lw-btn-key', function () { go('CORK', { no: o.prod_no }); }) : null,
							o.status !== 'CREE' && o.status !== 'CLOTURE' ? btn('Limiter le traitement › Clôture technique', 'lw-btn-danger', function () { act(msg, post(sp('/cor/' + encodeURIComponent(o.prod_no) + '/close'), { undo: false }), function (r2, m2) { corView(o.prod_no, out, editable, m2); }); }) : null,
							o.status === 'CLOTURE' ? btn('Annuler la clôture technique', 'lw-btn-secondary', function () { act(msg, post(sp('/cor/' + encodeURIComponent(o.prod_no) + '/close'), { undo: true }), function (r2, m2) { corView(o.prod_no, out, editable, m2); }); }) : null));
					}
					append(box, h('h4', { text: 'Impressions' }), outputsTable(o.outputs));
					return box;
				};
				tabs['Modifications'] = function () { return changesTable(o.changes); };
				append(out, card('Ordre ' + o.prod_no + ' · ' + o.order_type, h('div', null, h('div', { class: 'lw-grid2' }, dl([['Article', matCell(o.material)], ['Quantité', qty(o.qty) + ' ' + o.material.uom], ['Confirmé', qty(o.qty_done) + ' (rebut ' + qty(o.qty_scrap) + ')']]), dl([['Statut', orderBadge(o)], ['Fin prévue', date(o.end_date)], ['Gestionnaire', o.supervisor ? o.supervisor + ' – ' + o.supervisor_label : '—'], ['Poste de travail', o.work_center]])), tabbed(tabs))));
			}).catch(function (e) { fail(out, e); });
		}
		function corScreen(editable) {
			return function (el, p) {
				var out = h('div');
				var no = onEnter(codeInp({ placeholder: '1000001', value: p.no || '' }), function () { corView(no.value.trim(), out, editable); });
				append(el, card(null, h('div', { class: 'lw-inline' }, field('Ordre de procédé', no), btn('Charger', 'lw-btn-primary', function () { corView(no.value.trim(), out, editable); }), btn('Liste (COR8)', 'lw-btn-secondary', function () { go('COR8'); }))), out);
				if (p.no) { corView(p.no, out, editable); }
			};
		}
		tx('COR2', 'Modifier un ordre de procédé', 'Production', 'PP', corScreen(true));
		tx('COR3', 'Afficher un ordre de procédé', 'Production', 'PP', corScreen(false));

		function orderList(el, opts) {
			var out = h('div');
			var sup = supSel('');
			var st = sel([['OPEN', 'Ouverts (CRTD, LANC)'], ['CRTD', 'Créés (CRTD)'], ['LANC', 'Lancés (LANC)'], ['CNF', 'Confirmés (CNF)'], ['CLTE', 'Clôturés (CLTE)'], ['', 'Tous']], opts.status || 'OPEN');
			var to = inp({ type: 'date', value: opts.to || '' });
			function run() {
				clear(out); append(out, loading());
				get(sp('/cor8'), { supervisor: sup.value, status: st.value, to: to.value }).then(function (d) {
					clear(out);
					var msg = h('div');
					var checks = [];
					var all = h('input', { type: 'checkbox', 'aria-label': 'Tout sélectionner', onchange: function () { checks.forEach(function (c) { if (!c[0].disabled) { c[0].checked = all.checked; } }); } });
					append(out, msg, table([opts.release ? all : '', 'Ordre', 'Type', 'Article', 'Quantité', 'Confirmé', 'Fin', 'Gestionnaire', 'Statut'], d.rows.map(function (o) {
						var cb = h('input', { type: 'checkbox', disabled: o.status === 'CREE' ? null : true });
						checks.push([cb, o.prod_no]);
						return { cells: [opts.release ? cb : '', link(o.prod_no, 'COR3', { no: o.prod_no }), o.order_type, matCell(o.material), qty(o.qty), qty(o.qty_done), date(o.end_date), o.supervisor ? o.supervisor + ' ' + o.supervisor_label : '—', orderBadge(o)] };
					}), [4, 5]));
					if (opts.release && !S.session.readonly) {
						append(out, h('div', { class: 'lw-actions' }, btn('Lancer et imprimer la sélection', 'lw-btn-key', function () {
							act(msg, post(sp('/cor5'), { orders: checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; }) }), function () { setTimeout(run, 1200); K.refreshSession(); });
						})));
					}
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Division', h('span', { class: 'lw-mono', text: '1000' })), field('Gestionnaire', sup), field('Statut', st), field('Fin jusqu\'au', to), btn('Exécuter', 'lw-btn-primary', run))), out);
			run();
		}
		tx('COR8', 'Liste des ordres de procédé', 'Production', 'PP|MRP', function (el) { orderList(el, { release: false }); append(el, hint('Le filtre « gestionnaire » cible les ordres d\'un poste de production. Sélectionnez les ordres à lancer dans COR5.')); });
		tx('COR5', 'Lancer et imprimer des ordres (collectif)', 'Production', 'PP', function (el) { orderList(el, { release: true, status: 'CRTD', to: K.addDays(today(), 28) }); append(el, hint('Date de lancement : jusqu\'à 4 semaines. Avant le lancement, vous pouvez modifier un ordre (quantité, emballage, article) dans COR2. Le lancement imprime l\'ordre et les étiquettes.')); });

		tx('CORK', 'Confirmer un ordre de procédé', 'Production', 'PP', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '1000001', value: p.no || '' }), load);
			function load() {
				clear(out); append(out, loading());
				get(sp('/cork/' + encodeURIComponent(no.value.trim()))).then(function (d) {
					clear(out);
					var msg = h('div');
					var good = num({ value: d.open });
					var scrap = num({ value: '0' });
					var fin = h('input', { type: 'checkbox', checked: d.order.order_type !== 'SH08' ? true : null });
					var lines = d.lines.map(function (l) { return { l: l, q: num({ value: l.qty, 'aria-label': 'Quantité ' + l.material.no + ' lot ' + l.batch }) }; });
					var extra = [];
					var extraBox = h('div');
					function addExtra() {
						var box = h('div', { class: 'lw-paper' });
						append(extraBox, box);
						append(box, quantPicker(function (x) { var q = num({ value: '', placeholder: 'Quantité' }); extra.push({ x: x, q: q }); clear(box); append(box, h('div', { class: 'lw-inline' }, h('span', { text: x.material.no + ' · ' + stockRowText(x) }), q)); }, { filter: function (x) { return x.status === 'LIBRE'; } }));
					}
					append(out, card('Ordre ' + d.order.prod_no + ' · ' + d.order.material.no + ' – ' + d.order.material.designation, h('div', null, msg,
						h('div', { class: 'lw-form' }, field('Quantité bonne', good, 'reste ' + qty(d.open)), field('Rebut', scrap), h('label', { class: 'lw-field' }, h('span', { text: 'Confirmation finale' }), h('span', { class: 'lw-inline' }, fin, ' plus rien n\'est attendu sur cet ordre'))),
						h('h4', { text: 'Mouvements de stock : consommations (261)' + (d.out_mvt === '262' ? ' et nouveau lot (262)' : ' et entrée du produit (101)') }),
						d.missing.length ? msgBox('warn', 'Composants manquants : quels emballages ont réellement été utilisés ?', d.missing.map(function (m) { return m.material.no + ' ' + m.material.designation + ' : manque ' + qty(m.qty) + ' ' + m.material.uom + (m.packaging ? ' (emballage)' : ''); }).concat(['Stock faux (emballage bien utilisé) : faites-le entrer (ZVLGUT, MIGO autres 501). Autre emballage utilisé : ajoutez-le ci-dessous (ou transférez-le avec MB11 309).'])) : null,
						table(['Composant', 'Magasin', 'Emplacement', 'Lot', 'Disponible', 'Quantité consommée'], lines.map(function (x) { return { cells: [matCell(x.l.material), x.l.location, mono(x.l.bin), x.l.batch, qty(x.l.available), x.q] }; }), [4]),
						extraBox, h('div', { class: 'lw-actions' }, btn('+ Ajouter un mouvement (autre lot, autre emballage)', 'lw-btn-secondary', addExtra)),
						hint(d.out_mvt === '262' ? 'SH08 : le lot consommé (261) est repris sous un nouveau lot (262) dans la même citerne. Clôturez ensuite l\'ordre (COR2).' : 'Contrôlez les lots proposés et modifiez-les si nécessaire. Le produit fini reçoit un nouveau lot ; en gestion détaillée, un OT d\'entreposage est créé.'),
						h('div', { class: 'lw-actions' }, btn('Enregistrer la confirmation', 'lw-btn-key', function () {
							var body = { good: good.value, scrap: scrap.value, final: fin.checked, lines: lines.map(function (x) { return { stock_id: x.l.stock_id, qty: x.q.value }; }).concat(extra.map(function (x) { return { stock_id: x.x.stock_id, qty: x.q.value }; })) };
							act(msg, post(sp('/cork/' + encodeURIComponent(d.order.prod_no)), body), function (r) { append(msg, h('div', { class: 'lw-actions' }, r.to_no ? btn('Quittancer l\'entreposage (LS26)', 'lw-btn-key', function () { go('LS26', { material: d.order.material.no }); }) : null, btn('Afficher l\'ordre (COR3)', 'lw-btn-secondary', function () { go('COR3', { no: d.order.prod_no }); }))); K.refreshSession(); });
						})))));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-inline' }, field('Ordre de procédé', no), btn('Charger', 'lw-btn-primary', load))), out);
			if (p.no) { load(); }
		});

		tx('CORS', 'Annuler une confirmation', 'Production', 'PP', function (el, p) {
			var msg = h('div');
			var no = codeInp({ placeholder: '1000001', value: p.no || '' });
			var reason = inp({ placeholder: 'Motif d\'annulation', maxlength: '120' });
			append(el, card(null, h('div', null, msg, hint('La dernière confirmation de l\'ordre est annulée : le produit fabriqué sort du stock (102), les composants y reviennent (262). Contrôlez ensuite dans LS26.'),
				h('div', { class: 'lw-form' }, field('Ordre de procédé', no), field('Motif', reason)),
				h('div', { class: 'lw-actions' }, btn('Annuler la confirmation', 'lw-btn-danger', function () { act(msg, post(sp('/cors/' + encodeURIComponent(no.value.trim())), { reason: reason.value }), function () { K.refreshSession(); }); })))));
		});

		tx('CS03', 'Afficher une nomenclature (recette)', 'Production', 'PP|MD|MRP', function (el, p) {
			var out = h('div');
			var fm = f4Field('Article', 'material', function (r) { show(r.no); });
			function show(n) {
				clear(out); append(out, loading());
				get(sp('/cs03/' + encodeURIComponent(n))).then(function (b) {
					clear(out);
					append(out, card(b.material.no + ' – ' + b.material.designation, h('div', null, dl([['Utilisation', 'Production (1)'], ['Version', b.version], ['Poste de travail', b.work_center], ['Quantité de base', '1 ' + b.material.uom], ['Temps par unité', qty(b.minutes) + ' min']]),
						table(['Pos.', 'Composant', 'Type', 'Quantité', 'Rebut %', 'Disponible'], b.items.map(function (i) { return { cells: [i.pos, matCell(i.material), i.material.type, qty(i.qty) + ' ' + i.material.uom, qty(i.scrap_pct), qty(i.available)] }; }), [3, 4, 5]),
						h('div', { class: 'lw-actions' }, btn('Cas d\'emploi d\'un composant (CS15)', 'lw-btn-secondary', function () { go('CS15'); })))));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, fm, field('Division', h('span', { class: 'lw-mono', text: '1000' })))), out);
			if (p.material) { fm.input.value = p.material; show(p.material); }
		});

		tx('CS15', 'Cas d\'emploi d\'un composant', 'Production', 'PP|MD|MRP', function (el, p) {
			var out = h('div');
			var fm = f4Field('Composant', 'material', function (r) { show(r.no); });
			function show(n) {
				clear(out); append(out, loading());
				get(sp('/cs15/' + encodeURIComponent(n))).then(function (d) {
					clear(out);
					append(out, card('L\'article ' + d.material.no + ' entre dans la composition de :', d.used_in.length ? table(['Article', 'Quantité par unité', 'Poste de travail'], d.used_in.map(function (u) { return { onclick: function () { go('CS03', { material: u.material.no }); }, cells: [matCell(u.material), qty(u.qty) + ' ' + d.material.uom, u.work_center] }; }), [1]) : h('p', { class: 'lw-muted', text: 'Aucune nomenclature n\'utilise cet article.' })));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, fm, field('Utilisation', h('span', { text: 'Production (nomenclatures)' })))), out);
			if (p.material) { fm.input.value = p.material; show(p.material); }
		});

		/* ══════════════════ LOT 5 · EMBALLAGES ET PORTAILS ══════════════════ */

		var SERIALS = null;
		function serialMaterials() { return SERIALS ? Promise.resolve(SERIALS) : get(sp('/serial-materials')).then(function (l) { SERIALS = l; return l; }); }
		function serialSel() {
			var s2 = sel([['', '— article loué (X) —']], '');
			serialMaterials().then(function (l) { l.forEach(function (m) { s2.appendChild(h('option', { value: m.id, text: m.no + ' – ' + m.designation })); }); if (l.length === 1) { s2.value = String(l[0].id); } });
			return s2;
		}
		function containerTable(rows, onPick) {
			return table(['Article', 'N° de conteneur', 'Chez', 'État', 'Mise à jour'], rows.map(function (c) { return { onclick: onPick ? function () { onPick(c); } : null, cells: [c.material.no, mono(c.serial), c.holder === 'CLIENT' && c.customer ? c.customer.no + ' ' + c.customer.name + ' (' + c.customer.city + ')' : 'Chez nous', h('span', { class: 'lw-badge ' + (c.state === 'REBUT' ? 'red' : (c.state === 'A_CONTROLER' ? 'yellow' : '')), text: c.state_label }), dt(c.updated_at)] }; }));
		}

		tx('ZVLGUT', 'Gestion des emballages consignés', 'Emballages et portails', 'TRA|RET|GR|SHIP', function (el, p) {
			var tabs = {};
			tabs['Retours › conteneurs loués'] = function () {
				var msg = h('div');
				var cust = null;
				var fc = f4Field('Réceptionnaire (client)', 'customer', function (r) { cust = r; showAt(); });
				var xm = serialSel();
				var n = num({ value: '1', min: '1', max: '40', step: '1' });
				var ref = inp({ placeholder: 'N° du bon de retour', maxlength: '20' });
				var serBox = h('div', { class: 'lw-form' });
				var serials = [];
				var atBox = h('div');
				function drawSerials() { clear(serBox); serials = []; for (var i = 0; i < Math.max(1, Math.min(40, Number(n.value) || 1)); i++) { var s2 = codeInp({ placeholder: 'N° du conteneur', maxlength: '20' }); serials.push(s2); append(serBox, field('Conteneur ' + (i + 1), s2)); } }
				function showAt() { clear(atBox); if (!cust) { return; } get(sp('/containers'), { customer_id: cust.id }).then(function (rows) { append(atBox, card('Conteneurs enregistrés chez ' + cust.name, rows.length ? containerTable(rows, function (c) { var free = serials.filter(function (s2) { return !s2.value; })[0]; if (free) { free.value = c.serial; } }) : h('p', { class: 'lw-muted', text: 'Aucun conteneur loué chez ce client.' }))); }); }
				n.addEventListener('input', drawSerials);
				drawSerials();
				return h('div', null, msg, hint('Entrée marchandise normale des emballages vides rendus : une ligne par conteneur avec son numéro (plaque). Les conteneurs entrent au magasin des vides (950) en stock bloqué, à contrôler.'),
					h('div', { class: 'lw-form' }, fc, field('Article', xm), field('Date comptable', h('span', { text: date(today()) })), field('Nombre de conteneurs', n), field('Référence', ref)), serBox, atBox,
					h('div', { class: 'lw-actions' }, btn('Enregistrer le retour', 'lw-btn-key', function () { act(msg, post(sp('/zvlgut/return'), { customer_id: cust ? cust.id : 0, material_id: xm.value, serials: serials.map(function (s2) { return s2.value.trim(); }).filter(Boolean), ref: ref.value }), function () { drawSerials(); showAt(); K.refreshSession(); }); })));
			};
			tabs['Retours › emballages consignés (palettes, fûts…)'] = function () { var x = h('div'); if (K.TX.MB58) { append(x, hint('Palettes, cadres, couvercles, fûts et caisses consignés : retour des vides par client (mouvement 622).')); K.TX.MB58.render(x, p); } return x; };
			tabs['Contrôle des conteneurs'] = function () {
				var out = h('div');
				var msg = h('div');
				function load() {
					clear(out); append(out, loading());
					get(sp('/containers')).then(function (rows) {
						clear(out);
						var todo = rows.filter(function (c) { return c.state === 'A_CONTROLER'; });
						if (!todo.length) { append(out, msgBox('ok', 'Aucun conteneur à contrôler.')); return; }
						append(out, table(['Conteneur', 'Article', 'Rendu le', 'Contrôle'], todo.map(function (c) {
							var reason = inp({ placeholder: 'Motif du rebut', maxlength: '120' });
							return { cells: [mono(c.serial), c.material.no, dt(c.updated_at), h('div', { class: 'lw-inline' }, btn('Conforme, lavé', 'lw-btn-primary lw-btn-sm', function () { act(msg, post(sp('/zvlgut/check'), { material_id: c.material.id, serial: c.serial, result: 'OK' }), load); }), reason, btn('Rebuter', 'lw-btn-danger lw-btn-sm', function () { act(msg, post(sp('/zvlgut/check'), { material_id: c.material.id, serial: c.serial, result: 'KO', reason: reason.value }), load); }))] };
						})));
					}).catch(function (e) { fail(out, e); });
				}
				load();
				return h('div', null, msg, out);
			};
			tabs['Codes d\'emballage'] = function () {
				var box = h('div', null, loading());
				get(sp('/pack-codes')).then(function (c) { clear(box); append(box, hint('Codes utilisés pour la conception des paquets (VL02N, HU02) et support LogiERP correspondant.'), table(['Code', 'Désignation', 'Support LogiERP'], Object.keys(c).map(function (k) { return { cells: [mono(k), c[k][0], c[k][1]] }; }))); }).catch(function (e) { fail(box, e); });
				return box;
			};
			append(el, tabbed(tabs));
		});

		/* MB58 : onglet « par n° de conteneur » pour les articles loués suivis par numéro. */
		var mb58Orig = K.TX.MB58 ? K.TX.MB58.render : null;
		if (mb58Orig) {
			K.TX.MB58.render = function (el, p) {
				var tabs = {};
				tabs['Par client'] = function () { var x = h('div'); mb58Orig(x, p); return x; };
				tabs['Par n° de conteneur (articles X)'] = function () {
					var out = h('div');
					var xm = serialSel();
					var ser = onEnter(codeInp({ placeholder: 'N° du conteneur (vide : tous)', maxlength: '20' }), run);
					function run() {
						clear(out); append(out, loading());
						serialMaterials().then(function (l) {
							var m = l.filter(function (x) { return String(x.id) === xm.value; })[0];
							return get(sp('/containers'), { material: m ? m.no : '', serial: ser.value.trim() });
						}).then(function (rows) {
							clear(out);
							append(out, containerTable(rows, function (c) { showHist(c); }));
						}).catch(function (e) { fail(out, e); });
					}
					function showHist(c) {
						get(sp('/containers/' + c.id + '/history')).then(function (hh) {
							modal('Conteneur ' + c.serial, h('div', null, dl([['Article', c.material.no + ' – ' + c.material.designation], ['Chez', c.customer ? c.customer.name : 'Chez nous'], ['État', c.state_label]]), table(['Date', 'Mouvement', 'Client', 'Référence', 'Source'], hh.map(function (x) { return { cls: Number(x.cancelled) ? 'expired' : '', cells: [date(x.date), x.kind, x.customer, x.ref_no, x.source === 'PORTAIL' ? 'Portail' : 'ERP'] }; }))));
						}).catch(function (e) { status('error', e.message); });
					}
					return h('div', null, h('div', { class: 'lw-form' }, field('Article', xm), field('Lot (n° de conteneur)', ser), btn('Exécuter', 'lw-btn-primary', run)), hint('Sans n° de conteneur : tous les conteneurs du même type et le client qui les détient.'), out);
				};
				append(el, tabbed(tabs));
			};
		}

		tx('ZPEMB', 'Portail emballages (loueur, simulation)', 'Emballages et portails', 'TRA|RET', function (el) {
			var out = h('div');
			var tabs = {};
			tabs['Tracking overview'] = function () {
				var box = h('div');
				var ser = onEnter(codeInp({ placeholder: 'vide : tous', maxlength: '20' }), run);
				function run() {
					clear(box); append(box, loading());
					get(sp('/portal'), { serial: ser.value.trim() }).then(function (d) {
						clear(box);
						var msg = h('div');
						append(box, msg, table(['Conteneur', 'Selon le portail', 'Selon l\'ERP', 'Concordance', 'Déclarations'], d.rows.map(function (r) {
							return { cells: [mono(r.serial), r.portal, r.erp, Number(r.match) ? h('span', { class: 'lw-badge LIBRE', text: 'OK' }) : h('span', { class: 'lw-badge red', text: 'Écart à corriger' }), r.moves.map(function (mv) { return h('div', { class: 'lw-small' + (Number(mv.cancelled) ? ' lw-muted' : '') }, (mv.kind === 'LIVRAISON' ? 'Livraison ' : 'Retour ') + date(mv.date) + ' · ' + mv.customer + (Number(mv.cancelled) ? ' (annulée)' : ' '), Number(mv.cancelled) ? null : btn('✕', 'lw-btn-secondary lw-btn-sm', function () { act(msg, post(sp('/portal/' + mv.id + '/cancel')), run); }, { title: 'Annuler cette déclaration' })); })] };
						})));
					}).catch(function (e) { fail(box, e); });
				}
				run();
				return h('div', null, h('div', { class: 'lw-inline' }, field('Conteneur', ser), btn('Rechercher', 'lw-btn-primary', run)), box);
			};
			tabs['Livraison / retour'] = function () {
				var msg = h('div');
				var kind = sel([['LIVRAISON', 'Livraison (conteneur remis à un client)'], ['RETOUR', 'Retour (conteneur rendu)']], 'RETOUR');
				var ser = codeInp({ placeholder: 'N° du conteneur', maxlength: '20' });
				var d = inp({ type: 'date', value: today(), max: today() });
				var cust = null;
				var fc = f4Field('Entreprise', 'customer', function (r) { cust = r; });
				return h('div', null, msg, h('div', { class: 'lw-form' }, field('Mouvement', kind), field('Conteneur', ser), field('Date du mouvement', d), fc), h('div', { class: 'lw-actions' }, btn('Enregistrer sur le portail', 'lw-btn-key', function () { act(msg, post(sp('/portal'), { serial: ser.value.trim(), kind: kind.value, customer_id: cust ? cust.id : 0, date: d.value })); })));
			};
			append(el, h('div', { class: 'lw-portal-bar' }, h('strong', { text: 'Portail emballages' }), h('span', { text: 'Application du loueur de conteneurs (simulation, données fictives) : chaque mouvement de conteneur doit aussi y être déclaré.' })), tabbed(tabs), out);
		});

		tx('ZRAIL', 'Portail transporteur ferroviaire (simulation)', 'Emballages et portails', 'GR|TRA|PUR', function (el) {
			var out = h('div', null, loading());
			append(el, h('div', { class: 'lw-portal-bar rail' }, h('strong', { text: 'Portail fret ferroviaire' }), h('span', { text: 'Application du transporteur (simulation, données fictives) : wagons attendus à notre gare d\'exploitation.' })), out);
			get(sp('/wagons')).then(function (d) {
				clear(out);
				append(out, dl([['Gare d\'exploitation', mono(d.station)], ['Wagons', String(d.rows.length)]]));
				if (!d.rows.length) { append(out, msgBox('info', 'Aucun wagon attendu.')); return; }
				append(out, table(['Wagon', 'Commande', 'Expéditeur', 'Marchandise', 'Poids kg', 'Arrivée prévue', 'État', ''], d.rows.map(function (r) {
					return { cells: [mono(r.wagon), mono(r.po_no), r.supplier + ' (' + r.from + ')', [r.material, r.un ? h('div', { class: 'lw-small', text: 'UN ' + r.un + ' · classe ' + r.adr }) : null], qty(r.weight), date(r.eta), r.status, btn('Document', 'lw-btn-secondary lw-btn-sm', function () {
						var doc = card(null, h('div', null, h('h3', { text: 'Avis d\'arrivée du wagon ' + r.wagon }), dl([['Gare d\'exploitation', r.station], ['Expéditeur', r.supplier + ', ' + r.from], ['Marchandise', r.material], ['Matière dangereuse', r.un ? 'UN ' + r.un + ', classe ' + r.adr : '—'], ['Poids net', qty(r.weight) + ' kg'], ['Arrivée prévue', date(r.eta)], ['Commande d\'achat', r.po_no]]), h('p', { class: 'lw-small', text: 'Dépotage : fond non dépotable du wagon 100 kg, à déduire à la confirmation (LT12, code d\'écart 0).' })));
						modal('Wagon ' + r.wagon, doc, { buttons: [K.printBtn(doc), btn('Réceptionner (MIGO)', 'lw-btn-key', function () { go('MIGO', { po: r.po_no }); })] });
					})] };
				}), [4]));
			}).catch(function (e) { fail(out, e); });
		});

		/* ══════════════════ LOT 6 · PLANIFICATION DES TRANSPORTS ══════════════════ */

		function kg(v) { return qty(Math.round(Number(v) * 10) / 10) + ' kg'; }
		function needChips(d) { return [d.adr ? h('span', { class: 'lw-chip adr', text: 'ADR' }) : null, d.cold ? h('span', { class: 'lw-chip cold', text: 'Froid' }) : null]; }
		function weightCell(d) { return h('span', { title: Number(d.estimated) ? 'Poids estimé à partir des articles : la livraison n\'est pas encore emballée.' : 'Poids de l\'emballage' }, kg(d.weight) + (Number(d.estimated) ? ' *' : '')); }
		function unitsCell(d) { return d.pallets ? d.pallets + ' pal. ' + (d.support || '') : d.packages + ' colis'; }
		function typeBadge(t) { return h('span', { class: 'lw-badge ' + (t === 'TOURNEE' ? 'green' : ''), text: t === 'TOURNEE' ? 'Tournée' : 'Enlèvement' }); }
		function shpStatus(st) { return h('span', { class: 'lw-badge ' + ({ PLANIFIE: 'yellow', EN_ROUTE: 'green', TERMINE: 'LIBRE', REMIS: 'LIBRE' }[st] || ''), text: { PLANIFIE: 'Planifié', EN_ROUTE: 'En route', TERMINE: 'Terminé', REMIS: 'Remis au transporteur' }[st] || st }); }
		function dayInput(v) { return inp({ type: 'date', value: v || today(), min: today() }); }
		/** Liste des cibles d'affectation : transports planifiés du jour, nouvelle tournée par véhicule, enlèvement par transporteur. */
		function targetOptions(board) {
			var o = [['', '— moyen de transport —']];
			board.shipments.filter(function (x) { return x.status === 'PLANIFIE'; }).forEach(function (x) { o.push(['S:' + x.no, 'Transport ' + x.no + ' · ' + x.means + ' (' + x.deliveries.length + ' livr., ' + x.load_pct + ' %)']); });
			board.means.vehicles.forEach(function (v) { o.push(['V:' + v.id, 'Nouvelle tournée · ' + (v.owner === 'INTERNE' ? 'flotte · ' : 'camion affrété · ') + v.name + ' · ' + qty(v.payload) + ' kg, ' + v.places + ' pal.' + (v.cold ? ', froid' : '') + (v.adr ? ', ADR' : '') + (v.busy.length ? ' (déjà : ' + v.busy.join(', ') + ')' : '')]); });
			board.means.services.forEach(function (c) { o.push(['C:' + c.code, 'Enlèvement · ' + c.name + ' · ' + c.mode_label + ' (enlèvement ' + c.pickup + ', ' + c.tariff + ')']); });
			return o;
		}

		tx('VL06T', 'Livraisons à planifier pour le transport', 'Transport', 'TRA|SHIP|SD', function (el, p) {
			var out = h('div');
			var day = dayInput(p.date);
			var scope = sel([['OPEN', 'Pas encore affectées à un transport'], ['ALL', 'Toutes les livraisons non expédiées']], 'OPEN');
			function run() {
				clear(out); append(out, loading());
				get(sp('/vl06t'), { date: day.value, scope: scope.value }).then(function (rows) {
					clear(out);
					if (!rows.length) { append(out, msgBox('ok', 'Aucune livraison à planifier jusqu\'au lendemain ouvrable.')); return; }
					var w = rows.reduce(function (a, d) { return a + Number(d.weight); }, 0);
					append(out, h('p', { class: 'lw-small', text: rows.length + ' livraison(s) · ' + kg(w) + ' · ' + rows.reduce(function (a, d) { return a + d.pallets; }, 0) + ' palette(s). * = poids estimé (pas encore emballée).' }));
					append(out, table(['Livraison', 'Client', 'Souhaitée', 'Statut', 'Poids', 'Unités', 'Exigences', 'Transporteur habituel', 'Transport'], rows.map(function (d) {
						return { cells: [link(d.no, 'VL02N', { no: d.no }), [h('strong', { text: d.customer.name }), h('div', { class: 'lw-small', text: d.customer.city })], [date(d.req_date), Number(d.late) ? h('span', { class: 'lw-badge red', text: 'en retard' }) : null], badge(d.status), weightCell(d), unitsCell(d), [needChips(d), h('div', { class: 'lw-small', text: d.rules.window + (d.rules.vehicle ? ' · ' + d.rules.vehicle_label : '') })], d.default || '—', d.shp_no ? link(d.shp_no, 'VT03N', { no: d.shp_no }) : h('span', { class: 'lw-muted', text: 'à planifier' })] };
					}), [4]));
					append(out, h('div', { class: 'lw-actions' }, btn('Créer les transports (VT04)', 'lw-btn-key', function () { go('VT04', { date: day.value }); }), btn('Plan du jour (VT11)', 'lw-btn-secondary', function () { go('VT11', { date: day.value }); })));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Date de transport', day, 'livraisons souhaitées jusqu\'au jour ouvrable suivant'), field('Sélection', scope), btn('Exécuter', 'lw-btn-primary', run))), out);
			run();
		});

		tx('VT04', 'Créer les transports en masse', 'Transport', 'TRA|SHIP', function (el, p) {
			var out = h('div');
			var day = dayInput(p.date);
			var dep = inp({ type: 'time', value: '06:30' });
			function run() {
				clear(out); append(out, loading());
				get(sp('/vt04'), { date: day.value, depart: dep.value }).then(function (d) {
					clear(out);
					if (!d.rows.length) { append(out, msgBox('ok', 'Toutes les livraisons du jour sont déjà affectées à un transport.'), h('div', { class: 'lw-actions' }, btn('Plan du jour (VT11)', 'lw-btn-primary', function () { go('VT11', { date: day.value }); }))); return; }
					var msg = h('div');
					var opts = [['', '— ne pas affecter —']];
					d.means.services.forEach(function (c) { opts.push(['C:' + c.code, 'Enlèvement · ' + c.name + ' (' + c.mode_label + ')']); });
					d.means.vehicles.forEach(function (v) { opts.push(['V:' + v.id, 'Tournée · ' + v.name]); });
					var sels = [];
					append(out, msg, hint('Proposition : transporteur habituel du client (fiche client), marchandises dangereuses au transporteur ADR, froid en frigorifique, clients livrés par l\'entreprise regroupés en tournées (le plus petit véhicule qui convient). Contrôlez et modifiez la colonne « Moyen de transport ».'),
						table(['Livraison', 'Client', 'Poids', 'Unités', 'Exigences', 'Moyen de transport', 'Motif de la proposition'], d.rows.map(function (r) {
							var s2 = sel(opts, r.target, { 'aria-label': 'Moyen de transport ' + r.delivery.no });
							sels.push([r.delivery.no, s2]);
							return { cells: [h('span', { class: 'lw-mono', text: r.delivery.no }), [h('strong', { text: r.delivery.customer.name }), h('div', { class: 'lw-small', text: r.delivery.customer.city })], weightCell(r.delivery), unitsCell(r.delivery), [needChips(r.delivery), h('div', { class: 'lw-small', text: r.delivery.rules.window })], s2, h('span', { class: 'lw-small', text: r.reason })] };
						}), [2]),
						S.session.readonly ? null : h('div', { class: 'lw-actions' }, btn('Créer les transports', 'lw-btn-key', function () {
							var map = {};
							sels.forEach(function (x) { if (x[1].value) { map[x[0]] = x[1].value; } });
							act(msg, post(sp('/vt04'), { date: day.value, depart: dep.value, map: map }), function (r) {
								if (r.errors && r.errors.length) { append(msg, msgBox('warn', 'Transports non créés', r.errors)); }
								append(msg, h('div', { class: 'lw-actions' }, btn('Contrôler le plan du jour (VT11)', 'lw-btn-primary', function () { go('VT11', { date: day.value }); })));
								K.refreshSession();
							});
						})));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Date de transport', day), field('Départ des tournées', dep), btn('Proposer', 'lw-btn-primary', run))), out);
			run();
		});

		function shipCard(x, reload, msg) {
			var ro = S.session.readonly || x.status !== 'PLANIFIE';
			var capTxt = x.type === 'TOURNEE' ? kg(x.weight) + ' / ' + qty(x.capacity.weight) + ' kg · ' + x.pallets + ' / ' + x.capacity.places + ' pal.' : kg(x.weight) + ' · ' + x.packages + ' colis/unités';
			var bar = h('div', { class: 'lw-load' }, h('span', { style: { width: Math.min(100, x.load_pct) + '%' }, class: x.load_pct > 100 ? 'over' : '' }));
			var body = h('div', null,
				h('div', { class: 'lw-ship-head' }, typeBadge(x.type), ' ', shpStatus(x.status), h('span', { class: 'lw-small', text: ' ' + x.time + (x.type === 'TOURNEE' ? ' · ' + qty(x.km) + ' km' : '') + ' · CHF ' + chf(x.cost) })),
				x.type === 'TOURNEE' ? bar : null,
				h('div', { class: 'lw-small', text: capTxt + (x.estimated ? ' (* estimé)' : '') }),
				table([x.type === 'TOURNEE' ? 'Arrêt' : '#', 'Livraison', 'Client', 'Poids', 'Unités', ''], x.deliveries.map(function (d) {
					return { cells: [x.type === 'TOURNEE' ? d.seq + (d.eta ? ' · ' + d.eta : '') : d.seq, link(d.no, 'VL02N', { no: d.no }), [d.customer.name, needChips(d), h('div', { class: 'lw-small', text: d.customer.city })], weightCell(d), unitsCell(d), ro ? badge(d.status) : btn('Retirer', 'lw-btn-secondary lw-btn-sm', function () { act(msg, post(sp('/vt11/unassign'), { delivery: d.no }), reload); })] };
				}), [3]),
				h('div', { class: 'lw-actions' },
					btn('Ouvrir (VT02N)', 'lw-btn-secondary lw-btn-sm', function () { go('VT02N', { no: x.no }); }),
					btn('Papiers (VT70)', 'lw-btn-secondary lw-btn-sm', function () { go('VT70', { no: x.no }); }),
					btn('Bulletins (VL71)', 'lw-btn-secondary lw-btn-sm', function () { go('VL71', { shipment: x.no }); }),
					x.ready && x.status === 'PLANIFIE' ? h('span', { class: 'lw-badge green', text: 'Prêt à partir' }) : null));
			return card(x.no + ' · ' + x.means, body, x.type === 'TOURNEE' ? 'ink' : null);
		}

		tx('VT11', 'Plan des transports du jour', 'Transport', 'TRA|SHIP', function (el, p) {
			var out = h('div');
			var msg = h('div');
			var day = dayInput(p.date);
			function load(r2, m2) {
				clear(out); append(out, loading());
				get(sp('/vt11'), { date: day.value }).then(function (b) {
					clear(out); clear(msg);
					if (m2) { append(msg, msgBox('ok', m2)); }
					var checks = [];
					var all = h('input', { type: 'checkbox', 'aria-label': 'Tout sélectionner', onchange: function () { checks.forEach(function (c) { c[0].checked = all.checked; }); } });
					var target = sel(targetOptions(b), '', { 'aria-label': 'Moyen de transport' });
					var dep = inp({ type: 'time', value: '06:30' });
					var openBox = b.open.length ? h('div', null,
						table([all, 'Livraison', 'Client', 'Souhaitée', 'Poids', 'Unités', 'Exigences', 'Habituel'], b.open.map(function (d) {
							var cb = h('input', { type: 'checkbox', 'aria-label': 'Sélectionner ' + d.no });
							checks.push([cb, d.no]);
							return { cells: [cb, link(d.no, 'VL02N', { no: d.no }), [h('strong', { text: d.customer.name }), h('div', { class: 'lw-small', text: d.customer.city })], [date(d.req_date), Number(d.late) ? h('span', { class: 'lw-badge red', text: 'retard' }) : null], weightCell(d), unitsCell(d), [needChips(d), h('div', { class: 'lw-small', text: d.rules.window + (d.rules.vehicle ? ' · ' + d.rules.vehicle_label : '') })], d.default || '—'] };
						}), [4]),
						S.session.readonly ? null : h('div', { class: 'lw-form', style: { marginTop: '10px' } }, field('Affecter la sélection à', target), field('Départ (nouvelle tournée)', dep), btn('Affecter', 'lw-btn-key', function () {
							var nos = checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; });
							var t = target.value;
							var body = { date: day.value, deliveries: nos, depart: dep.value };
							if (t.indexOf('S:') === 0) { body.shipment = t.slice(2); } else if (t.indexOf('V:') === 0) { body.vehicle_id = t.slice(2); } else if (t.indexOf('C:') === 0) { body.carrier = t.slice(2); } else { status('error', 'Choisissez le moyen de transport.'); return; }
							act(msg, post(sp('/vt11/assign'), body), load);
						}), btn('Proposition automatique (VT04)', 'lw-btn-secondary', function () { go('VT04', { date: day.value }); }))) : msgBox('ok', 'Toutes les livraisons du jour sont affectées à un transport.');
					append(out, card('À planifier · ' + b.weekday + ' ' + date(b.date) + ' (livraisons souhaitées jusqu\'au ' + date(b.until) + ')', openBox, 'yellow'));
					var cost = b.shipments.reduce(function (a, x) { return a + x.cost; }, 0);
					append(out, h('h3', { text: b.shipments.length + ' transport(s) · coût estimé CHF ' + chf(cost) }));
					append(out, h('div', { class: 'lw-board' }, b.shipments.map(function (x) { return shipCard(x, load, msg); })));
					if (!b.shipments.length) { append(out, h('p', { class: 'lw-muted', text: 'Aucun transport ce jour-là.' })); }
				}).catch(function (e) { fail(out, e); });
			}
			day.addEventListener('change', function () { load(); });
			append(el, card(null, h('div', { class: 'lw-form' }, field('Date de transport', day), btn('Actualiser', 'lw-btn-primary', function () { load(); }), btn('Livraisons (VL06T)', 'lw-btn-secondary', function () { go('VL06T', { date: day.value }); }), btn('Bulletins du jour (VL71)', 'lw-btn-secondary', function () { go('VL71', { date: day.value }); }))), msg, out);
			load();
		});

		/** Vue d'un transport (VT02N / VT03N) : tournée → écran de tournée existant, enlèvement → remise au transporteur. */
		function shpView(no, out, edit, notice) {
			clear(out); append(out, loading());
			get(sp('/shp/' + encodeURIComponent(no))).then(function (r) {
				var t = r.detail;
				clear(out);
				var msg = h('div', null, notice ? msgBox('ok', notice) : null);
				var ro = !edit || S.session.readonly;
				var acts = h('div', { class: 'lw-actions' },
					btn('Papiers (VT70)', 'lw-btn-secondary', function () { go('VT70', { no: t.no }); }),
					btn('Bulletins (VL71)', 'lw-btn-secondary', function () { go('VL71', { shipment: t.no }); }));
				if (!ro && t.status === 'PLANIFIE') {
					if (t.type === 'TOURNEE') {
						var dep = inp({ type: 'time', value: t.depart });
						append(acts, h('span', { class: 'lw-inline' }, dep, btn('Changer l\'heure de départ', 'lw-btn-secondary', function () { act(msg, post(sp('/shp/' + encodeURIComponent(t.no) + '/retime'), { depart: dep.value }), function (x, m2) { shpView(t.no, out, edit, m2); }); })));
					} else {
						append(acts, btn('Remettre au transporteur : sortie de marchandises (601)', 'lw-btn-key', function () { act(msg, post(sp('/shp/' + encodeURIComponent(t.no) + '/handover')), function (x, m2) { shpView(t.no, out, edit, m2); K.refreshSession(); }); }));
					}
					append(acts, btn('Supprimer le transport', 'lw-btn-danger', function () { act(msg, post(sp('/shp/' + encodeURIComponent(t.no) + '/delete')), function (x, m2) { clear(out); append(out, msgBox('ok', m2)); }); }));
				}
				append(out, msg, acts);
				if (t.type === 'TOURNEE') {
					var inner = h('div');
					append(out, inner);
					if (K.tourView) { K.tourView(t.no, inner); }
					if (ro) { inner.classList.add('lw-readonly-view'); }
				} else {
					append(out, card('Enlèvement ' + t.no + ' · ' + t.means, h('div', null,
						h('div', { class: 'lw-grid3' }, dl([['Statut', shpStatus(t.status)], ['Date', date(t.date)], ['Heure d\'enlèvement', t.pickup]]), dl([['Transporteur', t.means], ['Envois', String(t.stops.length)]]), dl([['Coût estimé', 'CHF ' + chf(t.cost)]])),
						table(['#', 'Livraison', 'Destinataire', 'Statut', 'Poids', 'Unités'], t.stops.map(function (s) { return { cells: [s.seq, link(s.delivery.no, 'VL02N', { no: s.delivery.no }), [s.delivery.customer.name, needChips(s.delivery), h('div', { class: 'lw-small', text: s.delivery.customer.street + ', ' + s.delivery.customer.city })], badge(s.delivery.status), weightCell(s.delivery), unitsCell(s.delivery)] }; }), [4]))));
				}
				append(out, h('h4', { text: 'Messages imprimés' }), outputsTable(t.outputs), h('h4', { text: 'Modifications' }), changesTable(r.changes));
			}).catch(function (e) { fail(out, e); });
		}
		function shpScreen(edit) {
			return function (el, p) {
				var out = h('div');
				var no = onEnter(codeInp({ placeholder: '0000001001', value: p.no || '' }), function () { shpView(no.value.trim(), out, edit); });
				var list = h('div');
				append(el, card(null, h('div', { class: 'lw-inline' }, field('Transport', no), btn('Afficher', 'lw-btn-primary', function () { shpView(no.value.trim(), out, edit); }), btn('Plan du jour (VT11)', 'lw-btn-secondary', function () { go('VT11'); }))), out, list);
				if (p.no) { shpView(p.no, out, edit); return; }
				get(sp('/tours')).then(function (rows) {
					append(list, card('Transports', rows.length ? table(['Transport', 'Type', 'Date', 'Heure', 'Moyen de transport', 'Livraisons', 'Coût CHF', 'Statut'], rows.map(function (r) { return { onclick: function () { no.value = r.no; shpView(r.no, out, edit); list.innerHTML = ''; }, cells: [mono(r.no), typeBadge(r.type), date(r.date), r.depart, r.vehicle, r.stops, chf(r.cost), shpStatus(r.status)] }; }), [5, 6]) : h('p', { class: 'lw-muted', text: 'Aucun transport. Planifiez dans VT11 ou VT04.' })));
				}).catch(function (e) { fail(list, e); });
			};
		}
		tx('VT02N', 'Traiter un transport (départ, remise, retour)', 'Transport', 'TRA|SHIP', shpScreen(true));
		tx('VT03N', 'Afficher un transport', 'Transport', 'TRA|SHIP', shpScreen(false));

		tx('VT70', 'Papiers du transport (feuille de route, bordereau)', 'Transport', 'TRA|SHIP', function (el, p) {
			var out = h('div');
			var no = onEnter(codeInp({ placeholder: '0000001001', value: p.no || '' }), load);
			var withBl = h('input', { type: 'checkbox' });
			function load() {
				var v = no.value.trim();
				if (!v) { return; }
				clear(out); append(out, loading());
				get(sp('/vt70/' + encodeURIComponent(v))).then(function (d) {
					clear(out);
					var sheet = h('div', { class: 'lw-labels lw-papers' });
					var co = d.sender ? d.sender.name : '';
					if (d.type === 'TOURNEE') {
						var v2 = d.vehicle || {};
						append(sheet, card(null, h('div', null, docHead('Feuille de route · tournée ' + d.no, h('span', { class: 'lw-mono', text: 'ZTRA' })),
							h('div', { class: 'lw-grid3' }, dl([['Date', date(d.date)], ['Véhicule', v2.name || d.means], ['Départ / retour prévu', d.depart + ' → ' + d.return]]), dl([['Distance', qty(d.km) + ' km'], ['Chargement', kg(d.totals.weight) + ' · ' + d.totals.pallets + ' pal. · ' + d.totals.packages + ' unités']]), dl([['Chauffeur', '……………………'], ['Km au départ', '…………'], ['Km au retour', '…………']])),
							table(['Arrêt', 'Arrivée', 'Destinataire', 'Plage', 'Livraison', 'Unités', 'Poids', 'Emballages à reprendre'], d.stops.map(function (s) { return { cells: [s.seq, h('strong', { text: s.eta }), [h('strong', { text: s.customer.name }), h('div', { class: 'lw-small', text: s.customer.street + ', ' + s.customer.city })], s.window + (Number(s.appointment) ? ' (rendez-vous)' : ''), s.no, unitsCell(s), kg(s.weight), s.balances.map(function (b) { return b.material.designation.split(' (')[0] + ' : ' + qty(b.qty); }).join(' · ') || '—'] }; }), [6]),
							h('h4', { text: 'Ordre de chargement (le dernier arrêt est chargé en premier)' }),
							h('ol', { class: 'lw-small' }, d.loading.map(function (l) { return h('li', { text: 'Arrêt ' + l.seq + ' · ' + l.customer + ' · livraison ' + l.no }); })),
							h('div', { class: 'lw-sign' }, h('span', { text: 'Chargement contrôlé par : ……………………' }), h('span', { text: 'Départ (heure, signature chauffeur) : ……………' })))));
					} else {
						append(sheet, card(null, h('div', null, docHead('Bordereau de remise au transporteur · ' + d.no, h('span', { class: 'lw-mono', text: 'ZTRA' })),
							h('div', { class: 'lw-grid3' }, dl([['Expéditeur', co], ['Date', date(d.date)]]), dl([['Transporteur', d.carrier], ['Service', d.service]]), dl([['Enlèvement prévu', d.pickup], ['Total', d.stops.length + ' envoi(s) · ' + d.totals.packages + ' unités · ' + kg(d.totals.weight)]])),
							table(['#', 'Livraison', 'Destinataire', 'Localité', 'Unités', 'Poids'], d.stops.map(function (s) { return { cells: [s.seq, s.no, s.customer.name, s.customer.city, unitsCell(s), kg(s.weight)] }; }), [5]),
							h('div', { class: 'lw-sign' }, h('span', { text: 'Remis par (quai) : ……………………' }), h('span', { text: 'Pris en charge par (chauffeur, plaque, heure) : ……………………' })))));
					}
					if (d.dangerous.length) {
						append(sheet, card(null, h('div', null, docHead('Document de transport ADR (marchandises dangereuses)', null),
							table(['Livraison', 'N° ONU', 'Désignation officielle', 'Classe', 'Groupe d\'emballage', 'Quantité', 'Poids brut'], d.dangerous.map(function (g) { return { cells: [g.delivery, 'UN ' + g.un, g.name, g.class, g.pg || '—', qty(g.qty) + ' ' + g.uom, kg(g.weight)] }; }), [5, 6]),
							h('p', { class: 'lw-small', text: 'Le chauffeur doit disposer des consignes écrites et de l\'équipement prescrit ; le véhicule porte les panneaux orange. Expéditeur : ' + co + '.' }))));
					}
					if (Number(d.estimated)) { append(out, msgBox('warn', 'Des livraisons ne sont pas encore emballées : poids et unités estimés. Imprimez après l\'emballage.')); }
					append(out, sheet, h('div', { class: 'lw-actions lw-noprint' }, btn('Imprimer', 'lw-btn-key', function () {
						var nos = d.stops.map(function (s) { return s.no; });
						var go2 = function () { post(sp('/output'), { obj_type: 'SHP', obj_no: d.no, type: 'ZTRA', detail: d.type === 'TOURNEE' ? 'feuille de route' : 'bordereau de remise' }).then(function (j) { status('ok', j.message); printSheet(sheet); }).catch(function (e) { status('error', e.message); }); };
						if (!withBl.checked) { go2(); return; }
						post(sp('/vl71/print'), { deliveries: nos }).then(function (j) {
							sheet.querySelectorAll('.lw-bl-page').forEach(function (x) { x.remove(); });
							j.data.notes.forEach(function (n) { var pg = h('div', { class: 'lw-bl-page' }, noteDoc(n)); sheet.appendChild(pg); });
							go2();
						}).catch(function (e) { status('error', e.message); });
					})));
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Transport', no), h('label', { class: 'lw-field' }, h('span', { text: 'Bulletins de livraison' }), h('span', { class: 'lw-inline' }, withBl, ' joindre (VL71)')), btn('Afficher', 'lw-btn-primary', load))), out);
			if (p.no) { load(); }
		});

		tx('VL71', 'Imprimer les bulletins de livraison en série', 'Transport', 'SHIP|TRA|SD', function (el, p) {
			var out = h('div');
			var day = dayInput(p.date);
			var shp = codeInp({ placeholder: 'N° de transport (facultatif)', value: p.shipment || '' });
			var car = sel([['', 'Tous'], ['PROPRE', 'Flotte de l\'entreprise (tournées)'], ['POST', 'Colis Express Suisse'], ['RAPID', 'Rapido Express'], ['LEMAN', 'Transports Léman'], ['HFRET', 'Helvetia Fret'], ['FRIGO', 'Frigo Trans Romandie'], ['ADR', 'Fret Dangereux Alpes']], '');
			function run() {
				clear(out); append(out, loading());
				get(sp('/vl71'), { date: day.value, shipment: shp.value.trim(), carrier: car.value }).then(function (rows) {
					clear(out);
					var msg = h('div');
					if (!rows.length) { append(out, h('p', { class: 'lw-muted', text: 'Aucune livraison pour cette sélection.' })); return; }
					var checks = [];
					var all = h('input', { type: 'checkbox', checked: true, 'aria-label': 'Tout sélectionner', onchange: function () { checks.forEach(function (c) { if (!c[0].disabled) { c[0].checked = all.checked; } }); } });
					var sheet = h('div', { class: 'lw-bl-sheet' });
					append(out, msg, table([all, 'Livraison', 'Commande', 'Destinataire', 'Transport', 'Statut', 'Colis', 'Poids', 'Imprimé'], rows.map(function (r) {
						var cb = h('input', { type: 'checkbox', checked: Number(r.packed) ? true : null, disabled: Number(r.packed) ? null : true, 'aria-label': 'Imprimer ' + r.no });
						checks.push([cb, r.no]);
						return { cells: [cb, mono(r.no), r.so_no, [r.customer, h('div', { class: 'lw-small', text: r.city })], r.shp_no ? [mono(r.shp_no), h('div', { class: 'lw-small', text: r.means })] : (r.means || h('span', { class: 'lw-muted', text: 'non planifiée' })), badge(r.status), Number(r.packed) ? r.packages : '—', Number(r.packed) ? kg(r.weight) : '—', Number(r.prints) ? r.prints + '×' : ''] };
					}), [6, 7]), hint('Seules les livraisons emballées s\'impriment : le bulletin indique le nombre de colis, le poids et le transport.'),
						h('div', { class: 'lw-actions' }, btn('Imprimer les bulletins sélectionnés', 'lw-btn-key', function () {
							var nos = checks.filter(function (c) { return c[0].checked; }).map(function (c) { return c[1]; });
							act(msg, post(sp('/vl71/print'), { deliveries: nos }), function (d) {
								clear(sheet);
								d.notes.forEach(function (n) { append(sheet, h('div', { class: 'lw-bl-page' }, noteDoc(n))); });
								if (d.skipped && d.skipped.length) { append(msg, msgBox('warn', 'Non imprimés', d.skipped)); }
								printSheet(sheet);
							});
						})), sheet);
				}).catch(function (e) { fail(out, e); });
			}
			append(el, card(null, h('div', { class: 'lw-form' }, field('Date de transport', day), field('Transport', shp), field('Transporteur', car), btn('Exécuter', 'lw-btn-primary', run))), out);
			run();
		});
	});
})();
