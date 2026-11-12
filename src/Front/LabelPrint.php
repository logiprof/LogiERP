<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Core\Capabilities;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\Labels;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Planches d'étiquettes prêtes à imprimer (A4) : page autonome, codes-barres dessinés en SVG
 * par barcode.js (le même encodeur que l'application, vérifié par le lecteur du terminal).
 */
final class LabelPrint {

	public const QUERY = 'logierp_print';
	public const NONCE = 'logierp_labels';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	private static function post( string $k, int $max = 190 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans maybe_render().
		return isset( $_POST[ $k ] ) ? mb_substr( sanitize_textarea_field( wp_unslash( (string) $_POST[ $k ] ) ), 0, $max ) : '';
	}

	public function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- simple aiguillage, nonce vérifié juste après.
		if ( ! isset( $_POST[ self::QUERY ] ) || 'labels' !== $_POST[ self::QUERY ] ) {
			return;
		}
		nocache_headers();
		$nonce = isset( $_POST['_lwnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_lwnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( Capabilities::SCENARIOS ) ) {
			wp_die( esc_html__( 'Accès refusé ou formulaire expiré : rouvrez la page Étiquettes.', 'logierp' ), 'LogiERP', array( 'response' => 403 ) );
		}
		$type   = self::post( 'type', 20 );
		$format = self::post( 'format', 4 );
		$format = isset( Labels::FORMATS[ $format ] ) ? $format : ( Labels::DEFAULT_FORMAT[ $type ] ?? 'S' );
		$copies = max( 1, min( 50, (int) self::post( 'copies', 4 ) ) );
		$start  = max( 1, (int) self::post( 'start', 4 ) );
		$border = '' !== self::post( 'border', 2 );
		$uid    = get_current_user_id();
		$labels = array();
		$errors = array();
		try {
			switch ( $type ) {
				case 'article':
					$wanted = array();
					foreach ( preg_split( '/\R/', self::post( 'articles', 20000 ) ) ?: array() as $line ) {
						$p = array_map( 'trim', explode( ';', trim( $line ) ) );
						if ( '' !== $p[0] ) {
							$wanted[] = array( strtoupper( $p[0] ), max( 1, min( 50, (int) ( $p[1] ?? $copies ) ) ) );
						}
					}
					$sc = (int) self::post( 'scenario', 12 );
					if ( $sc ) {
						$s = \LogiERP\Domain\SessionService::scenario( $sc );
						if ( $s && \LogiERP\Admin\ScenariosPage::can_view( $s ) ) {
							foreach ( Labels::scenario_materials( $sc ) as $no ) {
								$wanted[] = array( $no, $copies );
							}
						}
					}
					if ( ! $wanted ) {
						$errors[] = 'Indiquez au moins un numéro d\'article ou choisissez un scénario.';
					}
					[ $labels, $errors2 ] = Labels::articles( $wanted );
					$errors = array_merge( $errors, $errors2 );
					break;
				case 'bin':
					[ $labels, $errors ] = Labels::bins( self::post( 'location', 10 ), (string) preg_replace( '/[^A-Za-z0-9\-]/', '', self::post( 'prefix', 30 ) ), '' !== self::post( 'fixed_only', 2 ), self::post( 'arrow', 6 ), $copies );
					break;
				case 'sscc':
				case 'document':
					$sc = (int) self::post( 'scenario', 12 );
					$s  = $sc ? \LogiERP\Domain\SessionService::scenario( $sc ) : null;
					if ( ! $s || ! \LogiERP\Admin\ScenariosPage::can_view( $s ) ) {
						$errors[] = 'Choisissez un scénario.';
						break;
					}
					[ $labels, $errors ] = 'sscc' === $type ? Labels::sscc( $sc, $uid ) : Labels::documents( $sc, $uid, $copies );
					break;
				case 'free':
					[ $labels, $errors ] = Labels::free( self::post( 'lines', 20000 ), $copies );
					break;
				default:
					$errors[] = 'Type d\'étiquette inconnu.';
			}
		} catch ( DomainError $e ) {
			$errors[] = $e->getMessage();
		}
		if ( count( $labels ) > Labels::MAX ) {
			$errors[] = sprintf( 'Seules les %d premières étiquettes sont affichées : affinez la sélection.', Labels::MAX );
			$labels   = array_slice( $labels, 0, Labels::MAX );
		}
		Audit::log( 'labels_print', 'labels', 0, array( 'type' => $type, 'count' => count( $labels ) ) );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		echo self::html( $type, $format, $labels, $errors, $start, $border ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page construite avec échappement ; données transmises en JSON (wp_json_encode avec JSON_HEX_*).
		exit;
	}

	private static function html( string $type, string $format, array $labels, array $errors, int $start, bool $border ): string {
		[ $flabel, $w, $hgt, $cols, $rows ] = Labels::FORMATS[ $format ];
		$per   = $cols * $rows;
		$start = min( $start, $per );
		$fnt   = LOGIERP_URL . 'assets/fonts/';
		$cfg   = array( 'w' => $w, 'h' => $hgt, 'cols' => $cols, 'rows' => $rows, 'start' => $start, 'format' => $format, 'border' => $border );
		$json  = static fn( $v ) => (string) wp_json_encode( $v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		$h     = array();
		$h[]   = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( 'Étiquettes – ' . ( Labels::TYPES[ $type ] ?? '' ) ) . '</title><style>';
		foreach ( array( 400, 600, 700 ) as $wt ) {
			$h[] = '@font-face{font-family:Poppins;src:url(' . esc_url( $fnt . 'poppins-latin-' . $wt . '-normal.woff2' ) . ') format("woff2");font-weight:' . $wt . '}';
		}
		$h[] = self::css( $w, $hgt, $cols, $rows );
		$h[] = '</style></head><body class="f-' . esc_attr( $format ) . ( $border ? ' cut' : '' ) . '">';
		$h[] = '<div class="bar"><button type="button" onclick="window.print()">Imprimer</button><span><b>' . (int) count( $labels ) . '</b> étiquette(s) · ' . esc_html( $flabel ) . ' · <b id="lw-pages">…</b> page(s). Imprimez à 100 %, sans « ajuster à la page ».</span></div>';
		if ( $errors ) {
			$h[] = '<div class="err"><ul>';
			foreach ( array_slice( $errors, 0, 30 ) as $e ) {
				$h[] = '<li>' . esc_html( $e ) . '</li>';
			}
			$h[] = '</ul></div>';
		}
		$h[] = '<div id="sheets"></div>';
		$h[] = '<script src="' . esc_url( LOGIERP_URL . 'assets/js/barcode.js?ver=' . LOGIERP_VERSION ) . '"></script>';
		$h[] = '<script>window.LW_LABELS=' . $json( $labels ) . ';window.LW_CFG=' . $json( $cfg ) . ';</script>';
		$h[] = '<script>' . self::js() . '</script></body></html>';
		return implode( '', $h );
	}

	private static function css( float $w, float $hgt, int $cols, int $rows ): string {
		$gx = ( 210 - $cols * $w ) / 2;
		$gy = max( 0.0, ( 297 - $rows * $hgt ) / 2 );
		return '@page{size:A4;margin:0}*{box-sizing:border-box}html,body{margin:0}body{background:#d8dde3;font-family:Poppins,Arial,sans-serif;color:#000}'
			. '.bar{position:sticky;top:0;z-index:3;display:flex;gap:14px;align-items:center;padding:10px 16px;background:#232c37;color:#dfe5eb;font-size:13px}.bar button{font:600 14px Poppins,Arial;background:#f2c200;color:#1d2733;border:0;border-radius:20px;padding:9px 20px;cursor:pointer}'
			. '.err{max-width:210mm;margin:12px auto;background:#fde7e4;color:#8a1c12;border-left:4px solid #b42318;padding:8px 14px;font-size:13px}'
			. '.sheet{width:210mm;height:297mm;margin:14px auto;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.2);padding:' . $gy . 'mm ' . $gx . 'mm;display:grid;grid-template-columns:repeat(' . $cols . ',' . $w . 'mm);grid-template-rows:repeat(' . $rows . ',' . $hgt . 'mm);overflow:hidden;page-break-after:always}'
			. '.lb{position:relative;overflow:hidden;padding:2.5mm 3mm;display:flex;flex-direction:column;justify-content:space-between}.cut .lb{outline:.2mm dashed #9aa3ad;outline-offset:-.1mm}.lb.blank{outline:none}'
			. '.lb svg{display:block;max-width:100%;height:auto}.bc{display:flex;justify-content:center}.mono{font-family:"DejaVu Sans Mono",Consolas,monospace}'
			. '.t1{font-weight:700;line-height:1.15}.t2{font-size:7.5pt;line-height:1.25}.chips span{display:inline-block;border:.3mm solid #000;border-radius:1mm;padding:0 1.2mm;margin-right:1mm;font-size:6.5pt;font-weight:600}'
			. '.f-S .art .t1{font-size:8pt;max-height:2.35em;overflow:hidden}.f-S .art .no{font-size:10pt}'
			. '.art .no{font-weight:700;font-size:13pt}.art .t1{font-size:10pt}.bin{align-items:center;text-align:center}.bin .code{font-weight:700;letter-spacing:.02em;line-height:1}.f-S .bin .code{font-size:14pt}.f-M .bin .code{font-size:24pt}.f-L .bin .code,.f-XL .bin .code{font-size:40pt}'
			. '.bin .zone{font-size:7pt;color:#333}.bin .cd{position:absolute;right:2mm;top:1.5mm;font-size:8pt;font-weight:700;border:.4mm solid #000;border-radius:1mm;padding:0 1.2mm}.f-S .bin .cd{font-size:6.5pt;top:.8mm}.bin .fix{font-size:7pt;font-weight:600}.arr{position:absolute;left:3mm;top:50%;transform:translateY(-50%);font-size:22pt;line-height:1}.f-S .arr{font-size:13pt;left:1.5mm}'
			. '.doc .t1{font-size:11pt}.doc .hint{font-size:6.8pt;color:#333}.free{align-items:center;text-align:center}.free .t1{font-size:10pt}'
			. '.gl{padding:3mm 3.5mm;font-size:7.4pt;justify-content:flex-start;gap:1.2mm}.gl .parties{display:grid;grid-template-columns:1fr 1fr;gap:2mm;border-bottom:.4mm solid #000;padding-bottom:1.2mm}.gl .k{display:block;font-size:6pt;text-transform:uppercase;letter-spacing:.05em}'
			. '.gl .ss{display:flex;justify-content:space-between;align-items:baseline;border-bottom:.4mm solid #000;padding-bottom:1mm}.gl .ss strong{font-size:12pt;letter-spacing:.03em}.gl .row{display:flex;justify-content:space-between;gap:2mm;border-bottom:.15mm solid #888;padding:.35mm 0}.gl .row span{color:#333}.gl .row strong{text-align:right}'
			. '.gl .codes{margin-top:auto;display:flex;flex-direction:column;gap:1.5mm}.gl .dmg{font-size:6.5pt;font-weight:600;border:.3mm solid #000;padding:.5mm 1mm}'
			. '@media print{body{background:#fff}.bar,.err{display:none}.sheet{margin:0;box-shadow:none}}';
	}

	/** Rendu des étiquettes (largeur de module choisie pour rester lisible par la caméra du smartphone). */
	private static function js(): string {
		return <<<'JS'
(function () {
	var L = window.LW_LABELS || [], C = window.LW_CFG, B = window.LogiBarcode;
	var root = document.getElementById('sheets');
	function el(t, cls, txt) { var e = document.createElement(t); if (cls) { e.className = cls; } if (txt !== undefined && txt !== null) { e.textContent = txt; } return e; }
	function sized(svg, mm) { if (!svg) { return el('div', 'err', 'Code non imprimable'); } svg.setAttribute('width', mm + 'mm'); svg.removeAttribute('height'); svg.setAttribute('preserveAspectRatio', 'xMidYMid meet'); var d = el('div', 'bc'); d.appendChild(svg); return d; }
	var big = C.format !== 'S';
	function article(x) {
		var d = el('div', 'lb art');
		var top = el('div'); top.appendChild(el('div', 'no mono', x.no)); top.appendChild(el('div', 't1', x.name));
		if (x.tags && x.tags.length && big) { var ch = el('div', 'chips'); x.tags.forEach(function (t) { ch.appendChild(el('span', '', t)); }); top.appendChild(ch); }
		d.appendChild(top);
		d.appendChild(sized(B.ean13Svg(x.ean, { module: 2, height: big ? 60 : 44 }), C.format === 'S' ? 40 : 52));
		return d;
	}
	function bin(x) {
		var d = el('div', 'lb bin');
		if (x.arrow) { d.appendChild(el('div', 'arr', x.arrow === 'up' ? '▲' : '▼')); }
		d.appendChild(el('div', 'zone', x.zone + (x.type ? ' · ' + x.type : '')));
		d.appendChild(el('div', 'code mono', x.code));
		d.appendChild(sized(B.code128Svg(x.code, { module: 2, height: big ? 52 : 34, hri: '' }), Math.min(C.w - 10, C.format === 'S' ? 60 : 82)));
		if (x.check) { d.appendChild(el('div', 'cd', 'Contrôle ' + x.check)); }
		if (x.fixed && big) { d.appendChild(el('div', 'fix', 'Emplacement fixe : ' + x.fixed)); }
		return d;
	}
	function doc(x) {
		var d = el('div', 'lb doc');
		var t = el('div'); t.appendChild(el('div', 't1', x.title)); t.appendChild(el('div', 't2', x.sub)); d.appendChild(t);
		d.appendChild(sized(B.code128Svg(x.code, { module: 2, height: 46 }), Math.min(C.w - 12, 70)));
		if (big) { d.appendChild(el('div', 'hint', x.hint)); }
		return d;
	}
	function free(x) {
		var d = el('div', 'lb free');
		if (x.title) { d.appendChild(el('div', 't1', x.title)); }
		d.appendChild(sized(B.code128Svg(x.code, { module: 2, height: big ? 50 : 36 }), Math.min(C.w - 8, Math.max(30, x.code.length * 4.2 + 14))));
		return d;
	}
	function row(k, v) { var r = el('div', 'row'); r.appendChild(el('span', '', k)); r.appendChild(el('strong', '', v || '—')); return r; }
	function sscc(x) {
		var d = el('div', 'lb gl');
		var p = el('div', 'parties');
		[['Expéditeur · From', x.from], ['Destinataire · To', x.to]].forEach(function (q) { var c = el('div'); c.appendChild(el('span', 'k', q[0])); c.appendChild(el('strong', '', q[1][0])); c.appendChild(el('div', '', q[1][1])); p.appendChild(c); });
		d.appendChild(p);
		var s = el('div', 'ss'); s.appendChild(el('span', '', 'SSCC')); s.appendChild(el('strong', 'mono', x.sscc)); d.appendChild(s);
		d.appendChild(row('Contenu (GTIN)', x.gtin)); d.appendChild(row('Article', x.article)); d.appendChild(row('Nombre', x.qty));
		d.appendChild(row('Lot', x.batch)); d.appendChild(row('Date limite', x.bbd)); d.appendChild(row('Unité', x.unit)); d.appendChild(row('Commande', x.order));
		var codes = el('div', 'codes');
		var wide = C.format === 'S' ? C.w - 6 : Math.min(C.w - 8, 92);
		if (x.content && C.format !== 'S') { codes.appendChild(sized(B.code128Svg(x.content, { gs1: true, module: 2, height: 40, fontSize: 14 }), wide)); }
		if (x.batch && C.format !== 'S') { codes.appendChild(sized(B.code128Svg('(10)' + x.batch, { gs1: true, module: 2, height: 34, fontSize: 14 }), Math.min(wide, 48))); }
		codes.appendChild(sized(B.code128Svg('(00)' + x.sscc, { gs1: true, module: 2, height: 52, fontSize: 15 }), Math.min(wide, 78)));
		d.appendChild(codes);
		return d;
	}
	var make = { article: article, bin: bin, document: doc, free: free, sscc: sscc };
	var per = C.cols * C.rows, slots = [];
	for (var k = 1; k < C.start; k++) { slots.push(null); }
	L.forEach(function (x) { slots.push(x); });
	var pages = Math.max(1, Math.ceil(slots.length / per));
	for (var p = 0; p < pages; p++) {
		var sh = el('section', 'sheet');
		for (var i = 0; i < per; i++) {
			var x = slots[p * per + i];
			sh.appendChild(x ? make[x.kind](x) : el('div', 'lb blank'));
		}
		root.appendChild(sh);
	}
	document.getElementById('lw-pages').textContent = L.length ? pages : 0;
})();
JS;
	}
}
