/* LogiERP — codes-barres (EAN-13, Code 128 / GS1-128 en SVG) et lecteur caméra du terminal mobile.
 * Aucune dépendance obligatoire : le lecteur utilise l'API BarcodeDetector du navigateur et, à défaut
 * (iPhone, ordinateur), la bibliothèque ZXing fournie avec l'extension (chargée seulement à la demande). */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var GS = '\u001d';

	function svgEl(tag, attrs) {
		var el = document.createElementNS(NS, tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { el.textContent = attrs[k]; } else { el.setAttribute(k, attrs[k]); } });
		return el;
	}

	/* ── Clé de contrôle GS1 (modulo 10, poids 3/1 depuis la droite) ── */
	function checkDigit(digits) {
		var s = 0;
		for (var i = 0; i < digits.length; i++) { s += Number(digits.charAt(digits.length - 1 - i)) * (i % 2 === 0 ? 3 : 1); }
		return (10 - s % 10) % 10;
	}
	function validGs1(code) { return /^\d{8,18}$/.test(code) && checkDigit(code.slice(0, -1)) === Number(code.slice(-1)); }

	/* ── EAN-13 ── */
	var EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
	var EAN_R = EAN_L.map(function (p) { return p.replace(/./g, function (c) { return c === '0' ? '1' : '0'; }); });
	var EAN_G = EAN_R.map(function (p) { return p.split('').reverse().join(''); });
	var EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

	function eanModules(code) {
		if (!/^\d{13}$/.test(code)) { return null; }
		var par = EAN_PARITY[Number(code.charAt(0))];
		var m = '101';
		for (var i = 1; i <= 6; i++) { var d = Number(code.charAt(i)); m += par.charAt(i - 1) === 'L' ? EAN_L[d] : EAN_G[d]; }
		m += '01010';
		for (var j = 7; j <= 12; j++) { m += EAN_R[Number(code.charAt(j))]; }
		return m + '101';
	}

	/** Symbole EAN-13 en SVG (largeur de module x, chiffres lisibles sous le code). */
	function ean13Svg(code, opts) {
		opts = opts || {};
		var mods = eanModules(code);
		if (!mods) { return null; }
		var x = opts.module || 2, hgt = opts.height || 70, quiet = 11 * x, fs = 9 * x;
		var w = quiet * 2 + mods.length * x;
		var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + (hgt + fs + 4), width: opts.width || w, role: 'img', 'aria-label': 'EAN ' + code, class: 'lw-barcode' });
		svg.appendChild(svgEl('rect', { x: 0, y: 0, width: w, height: hgt + fs + 4, fill: '#fff' }));
		var guard = function (i) { return i < 3 || (i >= 45 && i < 50) || i >= 92; };
		for (var i = 0; i < mods.length; i++) {
			if (mods.charAt(i) === '1') { svg.appendChild(svgEl('rect', { x: quiet + i * x, y: 0, width: x, height: guard(i) ? hgt + fs / 2 : hgt, fill: '#000' })); }
		}
		var ty = hgt + fs;
		var t = function (s, cx) { svg.appendChild(svgEl('text', { x: cx, y: ty, 'font-family': 'ui-monospace, Menlo, Consolas, monospace', 'font-size': fs, 'text-anchor': 'middle', fill: '#000', text: s })); };
		t(code.charAt(0), quiet - 4 * x);
		t(code.slice(1, 7), quiet + (3 + 21) * x);
		t(code.slice(7), quiet + (50 + 21) * x);
		return svg;
	}

	/* ── Code 128 (jeux B et C) avec FNC1 pour GS1-128 ── */
	var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '2331112'];
	var FNC1 = 102, START_B = 104, START_C = 105, CODE_B = 100, CODE_C = 99, STOP = 106;

	function digitRun(s, i) { var n = 0; while (i + n < s.length && s.charCodeAt(i + n) >= 48 && s.charCodeAt(i + n) <= 57) { n++; } return n; }

	/** Valeurs Code 128 d'une chaîne ; GS (\x1d) en position quelconque = FNC1 ; gs1 = FNC1 initial. */
	function c128Values(text, gs1) {
		var v = [], set = null, i = 0;
		var first = digitRun(text, 0);
		if (gs1 || first >= 4 || (first === text.length && first >= 2 && first % 2 === 0)) { set = 'C'; v.push(START_C); } else { set = 'B'; v.push(START_B); }
		if (gs1) { v.push(FNC1); }
		while (i < text.length) {
			var ch = text.charAt(i);
			if (ch === GS) { v.push(FNC1); i++; continue; }
			var run = digitRun(text, i);
			if (set === 'C') {
				if (run >= 2) { v.push(Number(text.substr(i, 2))); i += 2; continue; }
				v.push(CODE_B); set = 'B';
				continue;
			}
			// Jeu B : passage en C pour les longues suites de chiffres (paires complètes).
			if (run >= 4 && (run % 2 === 0 || i + run === text.length || run >= 6)) {
				if (run % 2 === 1) { v.push(text.charCodeAt(i) - 32); i++; }
				v.push(CODE_C); set = 'C';
				continue;
			}
			var code = text.charCodeAt(i);
			if (code < 32 || code > 126) { throw new Error('Caractère non codable en Code 128'); }
			v.push(code - 32); i++;
		}
		var sum = v[0];
		for (var k = 1; k < v.length; k++) { sum += v[k] * k; }
		v.push(sum % 103);
		v.push(STOP);
		return v;
	}

	/**
	 * Code 128 en SVG. text peut contenir des parenthèses GS1 « (00)3761… » : avec opts.gs1, elles sont
	 * retirées du code (FNC1 initial, FNC1 après chaque champ variable qui n'est pas le dernier) et conservées
	 * dans le texte lisible.
	 */
	function code128Svg(text, opts) {
		opts = opts || {};
		var data = text, hri = opts.hri !== undefined ? opts.hri : text;
		if (opts.gs1) { data = gs1Data(text); }
		var values = c128Values(data, !!opts.gs1);
		var x = opts.module || 2, hgt = opts.height || 60, quiet = 10 * x, fs = opts.hri === '' ? 0 : (opts.fontSize || 8 * x);
		var widths = values.map(function (val) { return C128[val]; }).join('') + '2';
		var total = 0;
		for (var a = 0; a < widths.length; a++) { total += Number(widths.charAt(a)); }
		var w = quiet * 2 + total * x;
		var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + (hgt + (fs ? fs + 6 : 0)), width: opts.width || w, role: 'img', 'aria-label': 'Code-barres ' + hri, class: 'lw-barcode', preserveAspectRatio: 'none' });
		svg.appendChild(svgEl('rect', { x: 0, y: 0, width: w, height: hgt + fs + 6, fill: '#fff' }));
		var pos = quiet, bar = true;
		for (var b = 0; b < widths.length; b++) {
			var wd = Number(widths.charAt(b)) * x;
			if (bar) { svg.appendChild(svgEl('rect', { x: pos, y: 0, width: wd, height: hgt, fill: '#000' })); }
			pos += wd; bar = !bar;
		}
		if (fs) { svg.appendChild(svgEl('text', { x: w / 2, y: hgt + fs + 2, 'font-family': 'ui-monospace, Menlo, Consolas, monospace', 'font-size': fs, 'text-anchor': 'middle', fill: '#000', text: hri })); }
		return svg;
	}

	var GS1_FIXED = { '00': 18, '01': 14, '02': 14, '11': 6, '13': 6, '15': 6, '16': 6, '17': 6 };
	function gs1Data(text) {
		var parts = [], re = /\((\d{2,4})\)([^()]*)/g, m;
		while ((m = re.exec(text))) { parts.push([m[1], m[2].replace(/\s+/g, '')]); }
		return parts.map(function (p, i) { return p[0] + p[1] + (!GS1_FIXED[p[0]] && i < parts.length - 1 ? GS : ''); }).join('');
	}

	/** Lecture d'une chaîne GS1 (côté client : pour l'aide à la saisie ; le serveur fait foi). */
	function parseGs1(raw) {
		var s = String(raw || '').replace(/^\][A-Za-z]\d/, '').replace(/^\u001d+/, '');
		var out = {}, i = 0, VAR = { '10': 20, '21': 20, '37': 8 };
		while (i < s.length) {
			var ai = s.substr(i, 2);
			if (GS1_FIXED[ai]) { out[ai] = s.substr(i + 2, GS1_FIXED[ai]); i += 2 + GS1_FIXED[ai]; if (s.charAt(i) === GS) { i++; } continue; }
			if (VAR[ai]) { var end = s.indexOf(GS, i + 2); end = end < 0 ? s.length : end; out[ai] = s.slice(i + 2, Math.min(end, i + 2 + VAR[ai])); i = end + 1; continue; }
			return null;
		}
		return out;
	}

	/* ════════════════════ Lecteur caméra ════════════════════ */

	var zxingPromise = null;
	function loadZxing(base) {
		if (window.ZXing) { return Promise.resolve(window.ZXing); }
		if (zxingPromise) { return zxingPromise; }
		zxingPromise = new Promise(function (resolve, reject) {
			var sc = document.createElement('script');
			sc.src = base + 'vendor/zxing.min.js';
			sc.async = true;
			sc.onload = function () { if (window.ZXing) { resolve(window.ZXing); } else { reject(new Error('ZXing')); } };
			sc.onerror = function () { zxingPromise = null; reject(new Error('Le module de lecture n\'a pas pu être chargé.')); };
			document.head.appendChild(sc);
		});
		return zxingPromise;
	}

	var nativeFormats = null;
	function nativeDetector() {
		if (!('BarcodeDetector' in window)) { return Promise.resolve(null); }
		if (nativeFormats) { return Promise.resolve(nativeFormats.length ? new window.BarcodeDetector({ formats: nativeFormats }) : null); }
		return window.BarcodeDetector.getSupportedFormats().then(function (f) {
			nativeFormats = ['ean_13', 'ean_8', 'code_128', 'qr_code', 'data_matrix'].filter(function (x) { return f.indexOf(x) >= 0; });
			return nativeFormats.indexOf('code_128') >= 0 && nativeFormats.indexOf('ean_13') >= 0 ? new window.BarcodeDetector({ formats: nativeFormats }) : null;
		}).catch(function () { nativeFormats = []; return null; });
	}

	function beep() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) { return; }
			var ac = beep.ac || (beep.ac = new Ctx());
			var o = ac.createOscillator(), g = ac.createGain();
			o.type = 'square'; o.frequency.value = 2600;
			g.gain.value = 0.06;
			o.connect(g); g.connect(ac.destination);
			o.start(); o.stop(ac.currentTime + 0.09);
		} catch (e) { /* son facultatif */ }
	}
	function vibrate(p) { try { if (navigator.vibrate) { navigator.vibrate(p); } } catch (e) { /* facultatif */ } }

	function cameraSupport() {
		if (!window.isSecureContext) { return 'La caméra n\'est disponible que sur une connexion sécurisée (adresse en https://). Saisissez le code au clavier ou demandez à l\'administrateur du site d\'activer le HTTPS.'; }
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { return 'Ce navigateur ne donne pas accès à la caméra. Saisissez le code au clavier.'; }
		return '';
	}

	/**
	 * Ouvre le lecteur plein écran. opts : { base (URL des assets), title, hint, onCode(text) }.
	 * Renvoie une fonction de fermeture.
	 */
	function openScanner(opts) {
		var closed = false, stream = null, timer = null, busy = false;
		var wrap = document.createElement('div');
		wrap.className = 'lw-cam';
		wrap.setAttribute('role', 'dialog');
		wrap.setAttribute('aria-modal', 'true');
		wrap.setAttribute('aria-label', opts.title || 'Scanner');
		var video = document.createElement('video');
		video.setAttribute('playsinline', '');
		video.setAttribute('muted', '');
		video.muted = true;
		var frame = document.createElement('div'); frame.className = 'lw-cam-frame';
		var laser = document.createElement('div'); laser.className = 'lw-cam-laser'; frame.appendChild(laser);
		var top = document.createElement('div'); top.className = 'lw-cam-top';
		var title = document.createElement('strong'); title.textContent = opts.title || 'Scanner un code';
		var hint = document.createElement('span'); hint.textContent = opts.hint || 'Placez le code-barres dans le cadre.';
		top.appendChild(title); top.appendChild(hint);
		var bar = document.createElement('div'); bar.className = 'lw-cam-bar';
		var torchBtn = document.createElement('button'); torchBtn.type = 'button'; torchBtn.className = 'lw-cam-btn'; torchBtn.textContent = 'Lampe'; torchBtn.hidden = true;
		var closeBtn = document.createElement('button'); closeBtn.type = 'button'; closeBtn.className = 'lw-cam-btn lw-cam-close'; closeBtn.textContent = 'Fermer';
		bar.appendChild(torchBtn); bar.appendChild(closeBtn);
		var msg = document.createElement('div'); msg.className = 'lw-cam-msg'; msg.setAttribute('role', 'status');
		wrap.appendChild(video); wrap.appendChild(frame); wrap.appendChild(top); wrap.appendChild(msg); wrap.appendChild(bar);
		document.body.appendChild(wrap);
		document.body.classList.add('lw-cam-on');

		function close() {
			if (closed) { return; }
			closed = true;
			if (timer) { clearTimeout(timer); }
			if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); }
			if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
			document.body.classList.remove('lw-cam-on');
			document.removeEventListener('keydown', esc);
			if (opts.onClose) { opts.onClose(); }
		}
		function esc(e) { if (e.key === 'Escape') { close(); } }
		document.addEventListener('keydown', esc);
		closeBtn.addEventListener('click', close);
		function say(t) { msg.textContent = t; msg.style.display = t ? 'block' : 'none'; }

		var unsupported = cameraSupport();
		if (unsupported) { say(unsupported); return close; }
		say('Ouverture de la caméra…');

		var detect = null;
		navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } }).then(function (s) {
			if (closed) { s.getTracks().forEach(function (t) { t.stop(); }); return null; }
			stream = s;
			video.srcObject = s;
			var track = s.getVideoTracks()[0];
			try {
				var caps = track.getCapabilities ? track.getCapabilities() : {};
				if (caps.torch) {
					var on = false;
					torchBtn.hidden = false;
					torchBtn.addEventListener('click', function () { on = !on; track.applyConstraints({ advanced: [{ torch: on }] }).catch(function () {}); torchBtn.classList.toggle('on', on); });
				}
				if (caps.focusMode && caps.focusMode.indexOf('continuous') >= 0) { track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {}); }
			} catch (e) { /* options facultatives */ }
			return video.play().then(function () { return nativeDetector(); });
		}).then(function (native) {
			if (closed || !stream) { return; }
			if (native) {
				detect = function () { return native.detect(video).then(function (r) { return r && r.length ? r[0].rawValue : null; }); };
				say(''); loop();
				return;
			}
			return loadZxing(opts.base).then(function (ZX) {
				if (closed) { return; }
				var hints = new Map();
				hints.set(ZX.DecodeHintType.POSSIBLE_FORMATS, [ZX.BarcodeFormat.EAN_13, ZX.BarcodeFormat.EAN_8, ZX.BarcodeFormat.CODE_128, ZX.BarcodeFormat.QR_CODE, ZX.BarcodeFormat.DATA_MATRIX]);
				hints.set(ZX.DecodeHintType.TRY_HARDER, true);
				var reader = new ZX.MultiFormatReader();
				reader.setHints(hints);
				var canvas = document.createElement('canvas');
				var ctx2 = canvas.getContext('2d', { willReadFrequently: true });
				detect = function () {
					var vw = video.videoWidth, vh = video.videoHeight;
					if (!vw || !vh) { return Promise.resolve(null); }
					// Zone centrale (là où se trouve le cadre de visée), réduite pour la rapidité.
					var cw = Math.round(vw * 0.9), ch = Math.round(vh * 0.6);
					var k = Math.min(1, 960 / cw);
					canvas.width = Math.round(cw * k); canvas.height = Math.round(ch * k);
					ctx2.drawImage(video, (vw - cw) / 2, (vh - ch) / 2, cw, ch, 0, 0, canvas.width, canvas.height);
					try {
						var src = new ZX.HTMLCanvasElementLuminanceSource(canvas);
						var res = reader.decodeWithState(new ZX.BinaryBitmap(new ZX.HybridBinarizer(src)));
						return Promise.resolve(res ? res.getText() : null);
					} catch (e) { return Promise.resolve(null); }
				};
				say(''); loop();
			});
		}).catch(function (e) {
			if (closed) { return; }
			var n = e && e.name;
			say(n === 'NotAllowedError' || n === 'SecurityError' ? 'Accès à la caméra refusé. Autorisez la caméra pour ce site dans les réglages du navigateur, ou saisissez le code au clavier.'
				: n === 'NotFoundError' || n === 'OverconstrainedError' ? 'Aucune caméra trouvée sur cet appareil. Saisissez le code au clavier.'
					: (e && e.message) || 'La caméra n\'a pas pu démarrer.');
		});

		var last = '', lastAt = 0;
		function loop() {
			if (closed) { return; }
			timer = setTimeout(function () {
				if (closed || busy || !detect) { loop(); return; }
				busy = true;
				detect().then(function (text) {
					busy = false;
					if (closed) { return; }
					if (text) {
						var now = Date.now();
						if (text === last && now - lastAt < 2500) { loop(); return; }
						last = text; lastAt = now;
						beep(); vibrate(70);
						frame.classList.add('hit');
						var r = opts.onCode(text);
						if (r === false) { setTimeout(function () { frame.classList.remove('hit'); }, 400); loop(); return; }
						close();
						return;
					}
					loop();
				}).catch(function () { busy = false; loop(); });
			}, 120);
		}
		return close;
	}

	window.LogiBarcode = {
		checkDigit: checkDigit,
		validGs1: validGs1,
		ean13Svg: ean13Svg,
		code128Svg: code128Svg,
		gs1Data: gs1Data,
		parseGs1: parseGs1,
		openScanner: openScanner,
		cameraSupport: cameraSupport,
		beep: beep,
		vibrate: vibrate
	};
})();
