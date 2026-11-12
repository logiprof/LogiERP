<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Admin\ScenariosPage;
use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Company;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\Events;
use LogiERP\Domain\Labels;
use LogiERP\Domain\Purchasing;
use LogiERP\Domain\Sales;
use LogiERP\Domain\SessionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit « mini-entrepôt » d'un scénario : tout ce qu'il faut imprimer pour transformer une salle en entrepôt.
 *
 *  - fiche de montage : chaque emplacement, ce qu'on y pose et les écarts cachés à préparer ;
 *  - étiquettes d'emplacements avec code-barres et chiffre de contrôle ;
 *  - cartes d'articles (EAN-13, lot, date limite) en nombre égal au stock physique ;
 *  - cartes des arrivages (colis des commandes d'achat, avaries signalées) ;
 *  - étiquettes des bacs de préparation (une par commande client).
 *
 * Tout est calculé à partir d'une session de référence intacte : le stock du départ, identique pour tous les apprentis.
 */
final class MiniWarehouse {

	public const QUERY = 'logierp_doc';
	private const VALUE = 'kit';
	private const MAX_ROWS = 150;
	private const MAX_CARDS_ROW = 24;

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	public static function url( int $scenario_id ): string {
		return add_query_arg(
			array(
				self::QUERY => self::VALUE,
				'scenario'  => $scenario_id,
				'_wpnonce'  => wp_create_nonce( 'lw_kit' ),
			),
			home_url( '/' )
		);
	}

	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		if ( ! isset( $_GET[ self::QUERY ] ) || self::VALUE !== $_GET[ self::QUERY ] ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$id    = isset( $_GET['scenario'] ) ? absint( $_GET['scenario'] ) : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $id || ! wp_verify_nonce( $nonce, 'lw_kit' ) ) {
			self::deny( 'Lien expiré ou invalide : rouvrez le kit depuis LogiERP.' );
		}
		$sc = SessionService::scenario( $id );
		if ( ! current_user_can( Capabilities::SCENARIOS ) || ! $sc || ! ScenariosPage::can_view( $sc ) ) {
			self::deny( 'Accès refusé.' );
		}
		try {
			$ctx  = Labels::reference_session( $id, get_current_user_id() );
			$data = self::build( $ctx );
		} catch ( DomainError $e ) {
			self::deny( $e->getMessage() );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		echo self::html( $ctx, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document entièrement échappé.
		exit;
	}

	private static function deny( string $msg ): void {
		wp_die( esc_html( $msg ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 403 ) );
	}

	/* ───────── Données ───────── */

	/**
	 * Cartes pour une quantité : à l'unité jusqu'à 12, puis au carton (et un reste), sinon une carte « lot ».
	 *
	 * @return array<int,string> libellés de quantité, une entrée par carte
	 */
	public static function cards_for( float $qty, array $m ): array {
		$uom = (string) $m['base_uom'];
		if ( $qty <= 0 ) {
			return array();
		}
		if ( abs( $qty - round( $qty ) ) > 0.0005 ) {
			return array( Catalog::format_qty( $qty ) . ' ' . $uom );
		}
		$q   = (int) round( $qty );
		$upc = max( 1, (int) $m['units_per_carton'] );
		if ( $upc > 1 && $q >= $upc && intdiv( $q, $upc ) + ( $q % $upc ? 1 : 0 ) <= self::MAX_CARDS_ROW ) {
			$out = array_fill( 0, intdiv( $q, $upc ), sprintf( '1 carton = %d %s', $upc, $uom ) );
			if ( $q % $upc ) {
				$out[] = sprintf( 'Carton entamé : %d %s', $q % $upc, $uom );
			}
			return $out;
		}
		if ( $q <= 12 ) {
			return array_fill( 0, $q, '1 ' . $uom );
		}
		return array( sprintf( 'Lot de %s %s', number_format( $q, 0, '.', "\u{2019}" ), $uom ) );
	}

	/** Écarts cachés prévus par le scénario (imprévus « écart d'inventaire ») : clé matériel:magasin:emplacement => écart. */
	private static function hidden_gaps( int $scenario_id ): array {
		$out = array();
		foreach ( Events::events_of( $scenario_id ) as $e ) {
			if ( 'inventory_gap' !== $e['type'] ) {
				continue;
			}
			$p   = json_decode( (string) $e['params'], true ) ?: array();
			$bin = Catalog::bin_by_code( (string) ( $p['bin_code'] ?? '' ) );
			$loc = $bin ? (int) $bin['storage_location_id'] : (int) ( $p['location_id'] ?? 0 );
			$key = (int) ( $p['material_id'] ?? 0 ) . ':' . $loc . ':' . ( $bin ? (int) $bin['id'] : 0 );
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + (float) ( $p['delta'] ?? -1 );
		}
		return $out;
	}

	public static function build( Ctx $ctx ): array {
		global $wpdb;
		$scid = (int) $ctx->scenario['id'];
		$mids = Labels::scenario_material_ids( $scid );
		$gaps = self::hidden_gaps( $scid );
		$rows = array();
		if ( $mids ) {
			$st   = Db::t( 'stock' );
			$b    = Db::t( 'bin' );
			$l    = Db::t( 'storage_location' );
			$in   = implode( ',', array_map( 'intval', $mids ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.*, b.code AS bin_code, b.pick_sequence, l.code AS loc_code, l.name AS loc_name FROM {$st} s JOIN {$l} l ON l.id = s.storage_location_id LEFT JOIN {$b} b ON b.id = s.bin_id WHERE s.session_id = %d AND s.qty > 0 AND s.material_id IN ({$in}) ORDER BY l.code, b.pick_sequence, b.code, s.material_id, s.bbd LIMIT " . (int) self::MAX_ROWS, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$places = array();
		$cards  = array();
		$setup  = array();
		foreach ( $rows as $r ) {
			$m = Catalog::material( (int) $r['material_id'] );
			if ( ! $m ) {
				continue;
			}
			$code = $r['bin_code'] ? (string) $r['bin_code'] : (string) $r['loc_code'];
			if ( ! isset( $places[ $code ] ) ) {
				$places[ $code ] = array( 'code' => $code, 'zone' => $r['loc_code'] . ' – ' . $r['loc_name'], 'is_bin' => (bool) $r['bin_code'] );
			}
			$key      = (int) $r['material_id'] . ':' . (int) $r['storage_location_id'] . ':' . (int) $r['bin_id'];
			$gap      = (float) ( $gaps[ $key ] ?? 0 );
			$book     = (float) $r['qty'];
			$physical = max( 0.0, $book + $gap );
			unset( $gaps[ $key ] ); // Un écart ne s'applique qu'une fois (première ligne de l'emplacement).
			$notes = array();
			if ( 'LIBRE' !== $r['status'] ) {
				$notes[] = 'Stock ' . ( array( 'BLOQUE' => 'bloqué', 'QUALITE' => 'en contrôle qualité' )[ $r['status'] ] ?? strtolower( $r['status'] ) ) . ' : la mention figure sur les cartes';
			}
			if ( $r['bbd'] && $r['bbd'] < $ctx->date() ) {
				$notes[] = 'Lot périmé (DLC dépassée) : à repérer par l\'apprenti';
			}
			if ( abs( $gap ) > 0.0005 ) {
				$notes[] = sprintf( 'Écart caché : %s%s %s par rapport au système', $gap > 0 ? '+' : '', Catalog::format_qty( $gap ), $m['base_uom'] );
			}
			$labels  = self::cards_for( $physical, $m );
			$setup[] = array( 'place' => $code, 'check' => $r['bin_code'] ? Catalog::check_digit( $code ) : '', 'm' => $m, 'batch' => (string) $r['batch'], 'bbd' => $r['bbd'], 'status' => (string) $r['status'], 'book' => $book, 'physical' => $physical, 'cards' => count( $labels ), 'notes' => $notes );
			foreach ( $labels as $q ) {
				$cards[] = array( 'm' => $m, 'qty' => $q, 'batch' => (string) $r['batch'], 'bbd' => $r['bbd'], 'status' => (string) $r['status'], 'place' => $code, 'expired' => $r['bbd'] && $r['bbd'] < $ctx->date() );
			}
		}

		// Arrivages : colis des commandes d'achat préparées par le scénario (ce que le camion apporte vraiment).
		$arrivals = array();
		$po       = Db::t( 'purchase_order' );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT po_no FROM {$po} WHERE session_id = %d AND scenario_ref <> '' ORDER BY id", $ctx->id ) ) as $no ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d = Purchasing::detail( $ctx, (string) $no );
			foreach ( $d['items'] as $i ) {
				$dn = $i['delivery_note'];
				$m  = Catalog::material( (int) ( $dn['material']['id'] ?? $i['material']['id'] ) );
				if ( ! $m || (float) $dn['qty'] <= 0 ) {
					continue;
				}
				$units = (array) $dn['units'];
				foreach ( $units as $n => $u ) {
					$arrivals[] = array( 'po' => (string) $no, 'm' => $m, 'qty' => sprintf( '%s %s', Catalog::format_qty( (float) $u['qty'] ), $m['base_uom'] ), 'unit' => sprintf( '%s %d/%d', $u['type'] ?? 'Colis', $n + 1, count( $units ) ), 'sscc' => (string) $u['sscc'], 'batch' => (string) $dn['batch'], 'bbd' => $dn['bbd'], 'damage' => 0 === $n ? (string) $dn['damage'] : '', 'line' => (int) $i['line_no'] );
				}
			}
		}

		// Bacs de préparation : une étiquette par commande client du scénario.
		$crates = array();
		$so     = Db::t( 'sales_order' );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT so_no FROM {$so} WHERE session_id = %d AND scenario_ref <> '' ORDER BY id", $ctx->id ) ) as $no ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d        = Sales::detail( $ctx, (string) $no );
			$crates[] = array( 'no' => $d['so_no'], 'customer' => $d['customer'] ? $d['customer']['name'] . ' · ' . $d['customer']['city'] : '', 'priority' => $d['priority'], 'date' => $d['req_date'], 'lines' => count( $d['items'] ) );
		}
		return array( 'places' => array_values( $places ), 'cards' => $cards, 'setup' => $setup, 'arrivals' => $arrivals, 'crates' => $crates, 'truncated' => count( $rows ) >= self::MAX_ROWS );
	}

	/* ───────── Rendu ───────── */

	private static function e( $v ): string {
		return esc_html( (string) $v );
	}

	private static function d( ?string $date ): string {
		return $date ? gmdate( 'd.m.Y', (int) strtotime( $date ) ) : '';
	}

	private static function sheet( string $cls, array $items, int $per_page ): string {
		$h = '';
		foreach ( array_chunk( $items, max( 1, $per_page ) ) as $chunk ) {
			$h .= '<div class="sheet ' . $cls . '">' . implode( '', $chunk ) . '</div>';
		}
		return $h;
	}

	public static function html( Ctx $ctx, array $data ): string {
		$sc  = $ctx->scenario;
		$cd  = ! empty( $sc['check_digits'] );
		$fnt = LOGIERP_URL . 'assets/fonts/';
		$h   = array();
		$h[] = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . self::e( 'Kit mini-entrepôt – ' . $sc['title'] ) . '</title><style>';
		foreach ( array( 400, 600, 700 ) as $w ) {
			$h[] = '@font-face{font-family:Poppins;src:url(' . esc_url( $fnt . 'poppins-latin-' . $w . '-normal.woff2' ) . ') format("woff2");font-weight:' . $w . '}';
		}
		$h[] = self::css() . '</style></head><body>';
		$parts = array( 'setup' => 'Fiche de montage', 'bins' => 'Étiquettes d\'emplacements', 'cards' => 'Cartes d\'articles', 'arrivals' => 'Cartes des arrivages', 'crates' => 'Étiquettes des bacs' );
		$h[]   = '<div class="bar"><button type="button" onclick="window.print()">Imprimer / enregistrer en PDF</button><span>À imprimer :</span>';
		foreach ( $parts as $k => $l ) {
			$h[] = '<label><input type="checkbox" data-part="' . esc_attr( $k ) . '" checked> ' . self::e( $l ) . '</label>';
		}
		$h[] = '</div><main>';

		// 1. Fiche de montage.
		$h[] = '<section class="part" data-part="setup"><div class="page"><header class="dh"><div>' . Company::logo_svg( 30 ) . '</div><div class="dt"><h1>Kit mini-entrepôt</h1><p>' . self::e( $sc['title'] ) . '</p></div></header>';
		$h[] = '<div class="howto"><h2>Monter l\'entrepôt en 15 minutes</h2><ol>'
			. '<li>Collez chaque <b>étiquette d\'emplacement</b> sur un mur, une table ou une étagère, dans l\'ordre du tableau (c\'est l\'ordre du chemin de prélèvement).</li>'
			. '<li>Découpez les <b>cartes d\'articles</b> et déposez-les à leur emplacement (enveloppe, boîte ou pince). Une carte = la quantité écrite dessus.</li>'
			. '<li>Préparez les <b>écarts cachés</b> signalés dans la colonne « À préparer » : ils sont déjà pris en compte dans le nombre de cartes.</li>'
			. '<li>Posez les <b>cartes des arrivages</b> au quai de réception avec les étiquettes SSCC (menu Étiquettes), et un <b>bac</b> étiqueté par commande client au poste de préparation.</li>'
			. ( $cd ? '<li>Le scénario exige le <b>chiffre de contrôle</b> : l\'apprenti le lit sur l\'étiquette de l\'emplacement et le saisit à chaque prélèvement. Il prouve qu\'il s\'est déplacé.</li>' : '<li>Option : cochez « chiffre de contrôle » dans les options du scénario pour obliger l\'apprenti à lire l\'étiquette de chaque emplacement prélevé.</li>' )
			. '</ol></div>';
		$h[] = '<table class="ln"><thead><tr><th>Emplacement</th><th>Contrôle</th><th>Article</th><th>Lot · DLC</th><th class="r">Système</th><th class="r">Physique</th><th class="r">Cartes</th><th>À préparer</th></tr></thead><tbody>';
		foreach ( $data['setup'] as $s ) {
			$h[] = '<tr' . ( $s['notes'] ? ' class="warn"' : '' ) . '><td class="mono b">' . self::e( $s['place'] ) . '</td><td class="mono">' . self::e( $s['check'] ) . '</td><td><span class="mono">' . self::e( $s['m']['material_no'] ) . '</span> ' . self::e( $s['m']['designation'] ) . '</td><td>' . self::e( trim( $s['batch'] . ( $s['bbd'] ? ' · ' . self::d( $s['bbd'] ) : '' ), ' ·' ) ) . '</td><td class="r">' . self::e( Catalog::format_qty( $s['book'] ) . ' ' . $s['m']['base_uom'] ) . '</td><td class="r b">' . self::e( Catalog::format_qty( $s['physical'] ) ) . '</td><td class="r">' . (int) $s['cards'] . '</td><td>' . self::e( implode( ' · ', $s['notes'] ) ) . '</td></tr>';
		}
		if ( ! $data['setup'] ) {
			$h[] = '<tr><td colspan="8">Aucun stock pour les articles de ce scénario.</td></tr>';
		}
		$h[] = '</tbody></table>';
		if ( $data['truncated'] ) {
			$h[] = '<p class="small">Liste limitée aux ' . (int) self::MAX_ROWS . ' premières lignes de stock.</p>';
		}
		$h[] = '<p class="small">' . self::e( sprintf( '%d emplacement(s) · %d carte(s) d\'articles · %d colis à l\'arrivage · %d bac(s) de préparation. Matériel : ciseaux, enveloppes ou boîtes, bacs, pâte adhésive.', count( $data['places'] ), count( $data['cards'] ), count( $data['arrivals'] ), count( $data['crates'] ) ) ) . '</p></div></section>';

		// 2. Étiquettes d'emplacements (A4, 2 × 4).
		$items = array();
		foreach ( $data['places'] as $p ) {
			$items[] = '<div class="bin"><div class="zone">' . self::e( $p['zone'] ) . '</div>' . ( $p['is_bin'] ? '<div class="cd"><small>Contrôle</small>' . self::e( Catalog::check_digit( $p['code'] ) ) . '</div>' : '' ) . '<div class="code">' . self::e( $p['code'] ) . '</div><span class="bc" data-c128="' . esc_attr( $p['code'] ) . '"></span></div>';
		}
		$h[] = '<section class="part" data-part="bins">' . self::sheet( 's-bins', $items, 8 ) . '</section>';

		// 3. Cartes d'articles (A4, 3 × 8).
		$items = array();
		foreach ( $data['cards'] as $c ) {
			$m       = $c['m'];
			$tag     = 'LIBRE' !== $c['status'] ? '<span class="tag red">' . self::e( 'BLOQUE' === $c['status'] ? 'BLOQUÉ' : $c['status'] ) . '</span>' : '';
			$items[] = '<div class="card"><div class="t"><b class="mono">' . self::e( $m['material_no'] ) . '</b>' . $tag . '<span class="pl mono">' . self::e( $c['place'] ) . '</span></div><div class="n">' . self::e( $m['designation'] ) . '</div><div class="q">' . self::e( $c['qty'] ) . '</div>'
				. ( $c['batch'] || $c['bbd'] ? '<div class="lot">' . self::e( trim( ( $c['batch'] ? 'Lot ' . $c['batch'] : '' ) . ( $c['bbd'] ? ' · DLC ' . self::d( $c['bbd'] ) : '' ), ' ·' ) ) . '</div>' : '' )
				. ( '' !== (string) $m['ean'] ? '<span class="bc" data-ean="' . esc_attr( (string) $m['ean'] ) . '"></span>' : '' ) . '</div>';
		}
		$h[] = '<section class="part" data-part="cards">' . ( $items ? self::sheet( 's-cards', $items, 24 ) : '' ) . '</section>';

		// 4. Arrivages (A4, 3 × 8).
		$items = array();
		foreach ( $data['arrivals'] as $a ) {
			$m       = $a['m'];
			$items[] = '<div class="card arr' . ( '' !== $a['damage'] ? ' dmg' : '' ) . '"><div class="t"><b class="mono">' . self::e( $m['material_no'] ) . '</b><span class="pl">' . self::e( $a['unit'] ) . '</span></div><div class="n">' . self::e( $m['designation'] ) . '</div><div class="q">' . self::e( $a['qty'] ) . '</div><div class="lot">' . self::e( 'Cde ' . $a['po'] . ' · pos. ' . $a['line'] . ( $a['batch'] ? ' · lot ' . $a['batch'] : '' ) . ( $a['bbd'] ? ' · DLC ' . self::d( $a['bbd'] ) : '' ) ) . '</div>'
				. ( '' !== $a['damage'] ? '<div class="dm">AVARIE CONSTATÉE : ' . self::e( $a['damage'] ) . '</div>' : '' )
				. ( '' !== (string) $m['ean'] ? '<span class="bc" data-ean="' . esc_attr( (string) $m['ean'] ) . '"></span>' : '' ) . '</div>';
		}
		$h[] = '<section class="part" data-part="arrivals">' . ( $items ? self::sheet( 's-cards', $items, 24 ) : '' ) . '</section>';

		// 5. Bacs de préparation (A4, 2 × 2).
		$items = array();
		foreach ( $data['crates'] as $c ) {
			$items[] = '<div class="crate"><div class="k">Bac de préparation' . ( 'A' === $c['priority'] ? ' <span class="tag red">URGENT</span>' : '' ) . '</div><div class="code">' . self::e( $c['no'] ) . '</div><span class="bc" data-c128="' . esc_attr( $c['no'] ) . '"></span><div class="c">' . self::e( $c['customer'] ) . '</div><div class="s">' . self::e( sprintf( 'Livraison le %s · %d ligne(s) · priorité %s', self::d( $c['date'] ), $c['lines'], $c['priority'] ) ) . '</div></div>';
		}
		$h[] = '<section class="part" data-part="crates">' . ( $items ? self::sheet( 's-crates', $items, 4 ) : '' ) . '</section>';

		$h[] = '</main><script src="' . esc_url( LOGIERP_URL . 'assets/js/barcode.js?ver=' . LOGIERP_VERSION ) . '"></script><script>(function(){var B=window.LogiBarcode;if(B){document.querySelectorAll(".bc").forEach(function(n){try{var s=n.dataset.ean?B.ean13Svg(n.dataset.ean,{module:2,height:40}):B.code128Svg(n.dataset.c128,{module:2,height:44,hri:""});s.removeAttribute("height");s.setAttribute("preserveAspectRatio","xMidYMid meet");n.appendChild(s);}catch(e){}});}'
			. 'document.querySelectorAll(".bar input[data-part]").forEach(function(c){c.addEventListener("change",function(){var p=document.querySelector("section[data-part=\'"+c.dataset.part+"\']");if(p){p.classList.toggle("off",!c.checked);}});});})();</script></body></html>';
		return implode( '', $h );
	}

	private static function css(): string {
		return '@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:9pt/1.4 Poppins,Arial,sans-serif;color:#1d2733}'
			. '.bar{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:14px;align-items:center;padding:10px 16px;background:#232c37;color:#c9d2dc;font-size:13px}.bar button{font:600 14px Poppins,Arial;background:#f2c200;color:#1d2733;border:0;border-radius:20px;padding:9px 18px;cursor:pointer}.bar label{display:flex;gap:4px;align-items:center}'
			. '.page,.sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.12)}.page{padding:12mm}.off{display:none}'
			. '.dh{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #232c37;padding-bottom:3mm;margin-bottom:4mm}.dt{text-align:right}.dt h1{margin:0;font-size:18pt;text-transform:uppercase}.dt p{margin:0;font-weight:600}'
			. '.howto{background:#f6f7f9;border-left:4px solid #f2c200;padding:2mm 4mm;margin-bottom:4mm}.howto h2{font-size:11pt;margin:1mm 0}.howto ol{margin:0;padding-left:5mm}.howto li{margin:.6mm 0}'
			. 'table.ln{width:100%;border-collapse:collapse;font-size:8pt}.ln th{background:#232c37;color:#fff;text-align:left;padding:1.2mm 1.6mm;font-weight:600}.ln td{border-bottom:1px solid #d5dae0;padding:1.1mm 1.6mm;vertical-align:top}.ln .r{text-align:right}.ln tr.warn td{background:#fff6d6}.b{font-weight:700}.mono{font-family:"DejaVu Sans Mono",Consolas,monospace}.small{font-size:7.5pt;color:#5d6875}'
			. '.sheet{display:grid;align-content:start}.s-bins{grid-template-columns:repeat(2,105mm);grid-auto-rows:74.25mm}.s-cards{grid-template-columns:repeat(3,70mm);grid-auto-rows:37.125mm}.s-crates{grid-template-columns:repeat(2,105mm);grid-auto-rows:148.5mm}'
			. '.bin,.card,.crate{border:.2mm dashed #b9c0c8;position:relative;overflow:hidden;display:flex;flex-direction:column}'
			. '.bin{align-items:center;justify-content:center;padding:4mm;text-align:center}.bin .zone{font-size:8pt;color:#5d6875}.bin .code{font:700 30pt/1 "DejaVu Sans Mono",monospace;margin:2mm 0}.bin .cd{position:absolute;right:4mm;top:4mm;border:.6mm solid #000;border-radius:1.5mm;padding:.5mm 2mm;font:700 18pt/1 Poppins,Arial;text-align:center}.bin .cd small{display:block;font-size:5.5pt;font-weight:600;text-transform:uppercase}.bin .bc{width:86mm;height:20mm}'
			. '.card{padding:2mm 2.5mm}.card .t{display:flex;gap:2mm;align-items:center;font-size:8pt}.card .pl{margin-left:auto;font-size:6.5pt;color:#5d6875}.card .n{font-size:7.5pt;line-height:1.2;max-height:2.4em;overflow:hidden}.card .q{font-weight:700;font-size:9pt}.card .lot{font-size:6.5pt;color:#3d4753}.card .bc{margin-top:auto;height:12mm;display:block}.card .bc svg{width:100%;height:100%}'
			. '.card.dmg{background:repeating-linear-gradient(45deg,#fff,#fff 3mm,#fde7e7 3mm,#fde7e7 6mm)}.card .dm{font-size:6.5pt;font-weight:700;color:#b3261e}.tag{font-size:6pt;font-weight:700;padding:0 1.2mm;border-radius:1mm;border:.3mm solid}.tag.red{color:#b3261e;border-color:#b3261e}'
			. '.crate{align-items:center;justify-content:center;padding:8mm;text-align:center}.crate .k{font-size:12pt;font-weight:600;text-transform:uppercase}.crate .code{font:700 30pt/1.1 "DejaVu Sans Mono",monospace;margin:4mm 0}.crate .bc{width:90mm;height:22mm}.crate .c{font-size:12pt;font-weight:600;margin-top:4mm}.crate .s{font-size:9pt;color:#5d6875}'
			. '.bin .bc svg,.crate .bc svg{width:100%;height:100%}'
			. '@media print{body{background:#fff}.bar{display:none}.page,.sheet{margin:0;box-shadow:none;page-break-after:always;break-after:page}.page{min-height:0}.bin,.card,.crate{border-color:#d5dae0}}';
	}
}
