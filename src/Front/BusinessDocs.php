<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Admin\ScenariosPage;
use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Cmr;
use LogiERP\Domain\Company;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\Events;
use LogiERP\Domain\Labels;
use LogiERP\Domain\Production;
use LogiERP\Domain\Purchasing;
use LogiERP\Domain\Sales;
use LogiERP\Domain\SessionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents logistiques d'une activité (A4, imprimables) : ce que l'apprenti a entre les mains dans la réalité.
 *
 *  - commande d'achat (copie interne) et bulletin de livraison du fournisseur pour chaque arrivage ;
 *  - lettre de voiture CMR pour un fournisseur étranger ;
 *  - commande du client, tamponnée avec le numéro de saisie LogiERP ;
 *  - ordre de fabrication avec la liste des composants.
 *
 * Les documents sont produits à partir des données du scénario à chaque ouverture : modifier le scénario les met à jour.
 * Les numéros (commande 4500000001…) sont ceux que l'apprenti verra dans sa session : les tranches de numéros
 * et les SSCC sont identiques d'une session à l'autre.
 * Accès : enseignant (scénario ou assignation de sa classe) ou session (apprenti propriétaire, enseignant de la classe).
 */
final class BusinessDocs {

	public const QUERY = 'logierp_doc';
	private const VALUE = 'files';
	private const VAT   = 8.1;

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	private static function url( string $key, int $id ): string {
		return add_query_arg(
			array(
				self::QUERY => self::VALUE,
				$key        => $id,
				'_wpnonce'  => wp_create_nonce( 'lw_files_' . $key . '_' . $id ),
			),
			home_url( '/' )
		);
	}

	public static function teacher_url( int $scenario_id ): string {
		return self::url( 'scenario', $scenario_id );
	}

	public static function assignment_url( int $assignment_id ): string {
		return self::url( 'assignment', $assignment_id );
	}

	public static function session_url( int $session_id ): string {
		return self::url( 'session', $session_id );
	}

	/* ───────── Point d'entrée ───────── */

	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		if ( ! isset( $_GET[ self::QUERY ] ) || self::VALUE !== $_GET[ self::QUERY ] ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid  = get_current_user_id();
		$data = null;
		foreach ( array( 'scenario', 'assignment', 'session' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			$id    = absint( $_GET[ $key ] );
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! $id || ! wp_verify_nonce( $nonce, 'lw_files_' . $key . '_' . $id ) ) {
				self::deny( 'Lien expiré ou invalide : rouvrez les documents depuis LogiERP.' );
			}
			try {
				$data = self::resolve( $key, $id, $uid );
			} catch ( DomainError $e ) {
				self::deny( $e->getMessage() );
			}
			break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $data ) {
			self::deny( 'Documents introuvables ou accès refusé.' );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		echo self::html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document entièrement échappé.
		exit;
	}

	private static function deny( string $msg ): void {
		wp_die( esc_html( $msg ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 403 ) );
	}

	/** Droits, puis contexte : session de référence de l'enseignant ou session de l'apprenti. */
	private static function resolve( string $key, int $id, int $uid ): ?array {
		global $wpdb;
		$class = '';
		if ( 'scenario' === $key || 'assignment' === $key ) {
			if ( ! current_user_can( Capabilities::SCENARIOS ) ) {
				return null;
			}
			if ( 'assignment' === $key ) {
				$a  = Db::t( 'assignment' );
				$c  = Db::t( 'class' );
				$as = $wpdb->get_row( $wpdb->prepare( "SELECT a.scenario_id, c.name, c.teacher_id FROM {$a} a JOIN {$c} c ON c.id = a.class_id WHERE a.id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $as || ( (int) $as['teacher_id'] !== $uid && ! current_user_can( Capabilities::VIEW_ALL ) ) ) {
					return null;
				}
				$class = (string) $as['name'];
				$id    = (int) $as['scenario_id'];
			}
			$sc = SessionService::scenario( $id );
			if ( ! $sc || ! ScenariosPage::can_view( $sc ) ) {
				return null;
			}
			return array( 'ctx' => Labels::reference_session( $id, $uid ), 'teacher' => true, 'class' => $class, 'student' => '' );
		}
		$ctx = SessionService::context_for( $id, $uid, false );
		if ( ! $ctx ) {
			return null;
		}
		$own = \LogiERP\Domain\Teams::is_participant( $ctx->session, $uid );
		return array( 'ctx' => $ctx, 'teacher' => ! $own, 'class' => '', 'student' => \LogiERP\Domain\Teams::label( $ctx->session ), 'session' => true );
	}

	/* ───────── Inventaire des documents ───────── */

	/**
	 * @return array<int,array{type:string,title:string,no:string,give:string,html:string}>
	 */
	public static function documents( Ctx $ctx, bool $teacher, bool $session ): array {
		global $wpdb;
		$sid    = $ctx->id;
		$scid   = (int) $ctx->scenario['id'];
		$events = array();
		foreach ( Events::events_of( $scid ) as $e ) {
			$events[ 'EVT' . $e['id'] ] = $e;
		}
		$docs = array();

		// Arrivages fournisseurs : préparés par le scénario ou par un événement déjà survenu.
		$po = Db::t( 'purchase_order' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT po_no, scenario_ref FROM {$po} WHERE session_id = %d AND scenario_ref <> '' ORDER BY id", $sid ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d   = Purchasing::detail( $ctx, (string) $r['po_no'] );
			$sup = Purchasing::supplier( (int) ( $d['supplier']['id'] ?? 0 ) );
			$evt = $events[ (string) $r['scenario_ref'] ] ?? null;
			$when = $evt ? self::event_when( $evt ) : 'Avec la marchandise, au quai de réception';
			if ( ! $evt ) {
				$docs[] = array( 'type' => 'Commande d\'achat', 'title' => 'Commande d\'achat (copie interne)', 'no' => $d['po_no'], 'give' => 'Dossier du service des achats (consultable dans ME23N)', 'html' => self::purchase_order( $ctx, $d, $sup ) );
			}
			$docs[] = array( 'type' => 'Bulletin de livraison', 'title' => 'Bulletin de livraison du fournisseur', 'no' => 'BL-' . $d['po_no'], 'give' => $when, 'html' => self::delivery_note( $ctx, $d, $sup, (bool) $d['unannounced'] ) );
			if ( Cmr::is_international( $sup ) ) {
				try {
					$cmr    = Cmr::build( $ctx, (string) $r['po_no'] );
					$docs[] = array( 'type' => 'Lettre de voiture CMR', 'title' => 'Lettre de voiture internationale CMR', 'no' => $cmr['no'], 'give' => $when . ' (remise par le chauffeur)', 'html' => self::cmr( $cmr ) );
				} catch ( DomainError $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- plus rien à livrer : pas de CMR.
				}
			}
		}

		// Commandes clients du scénario.
		$so = Db::t( 'sales_order' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT so_no, scenario_ref FROM {$so} WHERE session_id = %d AND scenario_ref <> '' ORDER BY id", $sid ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d      = Sales::detail( $ctx, (string) $r['so_no'] );
			$evt    = $events[ (string) $r['scenario_ref'] ] ?? null;
			$live   = str_starts_with( (string) $r['scenario_ref'], 'LIVE' );
			$cust   = Sales::customer( (int) ( $d['customer']['id'] ?? 0 ) );
			$docs[] = array( 'type' => 'Commande client', 'title' => $live ? 'Commande du client (urgente, ajoutée en direct)' : 'Commande du client', 'no' => $d['so_no'], 'give' => $evt ? self::event_when( $evt ) : ( $live ? 'Intervention en direct de l\'enseignant' : 'Au début de la mission (service clients)' ), 'html' => self::customer_order( $ctx, $d, $cust, true ) );
			$changes = (array) ( $ctx->doc_map()['changes'][ $d['so_no'] ] ?? array() );
			if ( $changes ) {
				$docs[] = array( 'type' => 'Modification de commande', 'title' => 'Modification de commande du client', 'no' => $d['so_no'], 'give' => 'Intervention en direct de l\'enseignant (appel du client)', 'html' => self::order_change( $ctx, $d, $cust, $changes ) );
			}
		}

		// Ordres de fabrication du scénario.
		foreach ( (array) ( $ctx->doc_map()['refs'] ?? array() ) as $d ) {
			if ( 'PROD' === ( $d['type'] ?? '' ) ) {
				try {
					$p      = Production::detail( $ctx, (string) $d['no'] );
					$docs[] = array( 'type' => 'Ordre de fabrication', 'title' => 'Ordre de fabrication', 'no' => $p['prod_no'], 'give' => 'Au début de la mission (atelier)', 'html' => self::production_order( $ctx, $p ) );
				} catch ( DomainError $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
		}

		// Événements pas encore survenus : l'enseignant prépare le document, le numéro vient au déclenchement.
		if ( $teacher && ! $session ) {
			$fired = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT event_id FROM ' . Db::t( 'session_event_log' ) . ' WHERE session_id = %d', $sid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $events as $e ) {
				if ( in_array( (int) $e['id'], $fired, true ) ) {
					continue;
				}
				$p = json_decode( (string) $e['params'], true );
				$p = is_array( $p ) ? $p : array();
				if ( 'unannounced_delivery' === $e['type'] ) {
					$sup = Purchasing::supplier( (int) ( $p['supplier_id'] ?? 0 ) );
					$m   = Catalog::material( (int) ( $p['material_id'] ?? 0 ) );
					if ( $sup && $m ) {
						$qty = isset( $p['delivered_qty'] ) && '' !== (string) $p['delivered_qty'] ? (float) $p['delivered_qty'] : (float) ( $p['qty'] ?? 1 );
						$d   = array( 'po_no' => '', 'order_date' => $ctx->date(), 'note' => '', 'supplier' => array( 'id' => (int) $sup['id'] ), 'items' => array( array( 'line_no' => 10, 'qty' => $qty, 'price' => 0, 'delivery_date' => $ctx->date(), 'material' => array( 'id' => (int) $m['id'] ), 'delivery_note' => array( 'material' => array( 'id' => (int) $m['id'] ), 'qty' => $qty, 'bbd' => null, 'batch' => '', 'units' => array() ) ) ) );
						$docs[] = array( 'type' => 'Bulletin de livraison', 'title' => 'Bulletin de livraison (livraison non annoncée)', 'no' => '—', 'give' => self::event_when( $e ), 'html' => self::delivery_note( $ctx, $d, $sup, true ) );
					}
				} elseif ( 'urgent_order' === $e['type'] ) {
					$c     = Sales::customer( (int) ( $p['customer_id'] ?? 0 ) );
					$items = array();
					foreach ( (array) ( $p['items'] ?? array() ) as $i => $it ) {
						$m = Catalog::material( (int) ( $it['material_id'] ?? 0 ) );
						if ( $m ) {
							$items[] = array( 'line_no' => ( $i + 1 ) * 10, 'qty' => (float) ( $it['qty'] ?? 1 ), 'material' => array( 'id' => (int) $m['id'] ) );
						}
					}
					if ( $c && $items ) {
						$d      = array( 'so_no' => '', 'order_date' => $ctx->date(), 'req_date' => $ctx->date(), 'priority' => 'A', 'note' => 'Commande urgente : départ avec la tournée du jour.', 'items' => $items );
						$docs[] = array( 'type' => 'Commande client', 'title' => 'Commande du client (urgente)', 'no' => '—', 'give' => self::event_when( $e ), 'html' => self::customer_order( $ctx, $d, $c, false ) );
					}
				}
			}
		}
		return $docs;
	}

	private static function event_when( array $e ): string {
		$t = (string) $e['trigger_type'];
		if ( 'start' === $t ) {
			return 'Événement au démarrage : au début de la mission';
		}
		if ( 'after_actions' === $t ) {
			return sprintf( 'Événement « %s » : après %d actions réussies', Events::TYPES[ $e['type'] ] ?? $e['type'], (int) $e['trigger_value'] );
		}
		return sprintf( 'Événement « %s » : quand vous le déclenchez dans le suivi en direct', Events::TYPES[ $e['type'] ] ?? $e['type'] );
	}

	/* ───────── Gabarits ───────── */

	private static function e( $v ): string {
		return esc_html( (string) $v );
	}

	private static function d( ?string $date ): string {
		return $date ? gmdate( 'd.m.Y', (int) strtotime( $date ) ) : '';
	}

	private static function chf( float $v ): string {
		return number_format( $v, 2, '.', "\u{2019}" );
	}

	private static function q( float $v ): string {
		$s = Catalog::format_qty( $v );
		return str_contains( $s, '.' ) ? $s : number_format( (float) $s, 0, '.', "\u{2019}" );
	}

	private static function bc( string $code ): string {
		return '' !== $code ? '<span class="bc" data-c128="' . esc_attr( $code ) . '"></span>' : '';
	}

	/** Bloc d'adresse. @param string[] $lines */
	private static function addr( string $label, array $lines ): string {
		$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
		return '<div class="addr"><small>' . self::e( $label ) . '</small>' . implode( '<br>', array_map( array( self::class, 'e' ), $lines ) ) . '</div>';
	}

	private static function partner_lines( ?array $p ): array {
		if ( ! $p ) {
			return array( '—' );
		}
		$ctry = (string) ( $p['country'] ?? 'CH' );
		return array( (string) $p['name'], (string) ( $p['street'] ?? '' ), trim( ( 'CH' !== $ctry ? $ctry . '-' : '' ) . ( $p['zip'] ?? '' ) . ' ' . ( $p['city'] ?? '' ) ), 'CH' !== $ctry ? Cmr::country_name( $ctry ) : '' );
	}

	private static function company_lines(): array {
		[ $name, $street, $city ] = Company::delivery_address();
		return array( $name, $street, $city );
	}

	/** En-tête : émetteur (logo ou nom) à gauche, titre et numéro à droite. */
	private static function head( string $issuer_html, string $title, string $no, array $meta, ?string $scan = null ): string {
		$h = '<header class="dh"><div class="iss">' . $issuer_html . '</div><div class="dt"><h2>' . self::e( $title ) . '</h2>';
		if ( '' !== $no ) {
			$h .= '<div class="no">N° <b>' . self::e( $no ) . '</b></div>' . self::bc( $scan ?? $no );
		}
		$h .= '</div></header><dl class="meta">';
		foreach ( $meta as $k => $v ) {
			if ( '' !== (string) $v ) {
				$h .= '<div><dt>' . self::e( $k ) . '</dt><dd>' . self::e( $v ) . '</dd></div>';
			}
		}
		return $h . '</dl>';
	}

	private static function company_issuer(): string {
		$c = Company::get();
		return Company::logo_svg( 40 ) . '<p>' . implode( '<br>', array_map( array( self::class, 'e' ), array_filter( array_merge( self::company_lines(), array( 'Tél. ' . ( $c['phone'] ?? '' ) ) ) ) ) ) . '</p>';
	}

	private static function partner_issuer( ?array $p, string $extra = '' ): string {
		$lines = self::partner_lines( $p );
		return '<div class="pname">' . self::e( array_shift( $lines ) ) . '</div><p>' . implode( '<br>', array_map( array( self::class, 'e' ), array_filter( $lines ) ) ) . ( $p && ! empty( $p['phone'] ) ? '<br>Tél. ' . self::e( $p['phone'] ) : '' ) . ( $extra ? '<br>' . self::e( $extra ) : '' ) . '</p>';
	}

	/** Commande d'achat : de l'entreprise au fournisseur. */
	private static function purchase_order( Ctx $ctx, array $d, ?array $sup ): string {
		$h     = self::head( self::company_issuer(), 'Commande d\'achat', $d['po_no'], array( 'Date de commande' => self::d( $d['order_date'] ), 'Fournisseur n°' => $sup['supplier_no'] ?? '', 'Conditions de paiement' => $sup['payment_terms'] ?? '', 'Incoterms® 2020' => $sup ? trim( ( $sup['incoterm'] ?: 'DAP' ) . ' ' . ( in_array( (string) $sup['incoterm'], array( 'EXW', 'FCA' ), true ) ? $sup['city'] : 'Aclens' ) ) : '' ) );
		$h    .= '<div class="addrs">' . self::addr( 'Fournisseur', self::partner_lines( $sup ) ) . self::addr( 'Adresse de livraison', array_merge( self::company_lines(), array( 'Quai de réception, lu–ve 05 h 30 – 14 h 00' ) ) ) . '</div>';
		$h    .= '<table class="ln"><thead><tr><th>Pos.</th><th>Article</th><th>Désignation</th><th>EAN</th><th class="r">Quantité</th><th>Unité</th><th>Livraison</th><th class="r">Prix unit. CHF</th><th class="r">Montant CHF</th></tr></thead><tbody>';
		$total = 0.0;
		foreach ( $d['items'] as $i ) {
			$m      = Catalog::material( (int) $i['material']['id'] );
			$amount = (float) $i['qty'] * (float) $i['price'];
			$total += $amount;
			$h     .= '<tr><td>' . (int) $i['line_no'] . '</td><td class="mono">' . self::e( $m['material_no'] ?? '' ) . '</td><td>' . self::e( $m['designation'] ?? '' ) . '</td><td class="mono">' . self::e( $m['ean'] ?? '' ) . '</td><td class="r">' . self::e( self::q( (float) $i['qty'] ) ) . '</td><td>' . self::e( $m['base_uom'] ?? '' ) . '</td><td>' . self::e( self::d( $i['delivery_date'] ) ) . '</td><td class="r">' . self::e( self::chf( (float) $i['price'] ) ) . '</td><td class="r">' . self::e( self::chf( $amount ) ) . '</td></tr>';
		}
		$h .= '</tbody><tfoot><tr><td colspan="8" class="r">Montant net de la commande (TVA ' . self::e( number_format( self::VAT, 1, '.', '' ) ) . ' % en sus)</td><td class="r"><b>' . self::e( self::chf( $total ) ) . '</b></td></tr></tfoot></table>';
		$h .= '<div class="notes"><p><b>Merci d\'indiquer notre numéro de commande sur le bulletin de livraison, la facture et chaque unité de manutention (étiquette SSCC).</b></p><p>Livraison conforme à la commande : les quantités, articles, lots et dates limites sont contrôlés à la réception. Toute livraison partielle ou de remplacement doit être annoncée au service des achats.</p>' . ( '' !== trim( (string) $d['note'] ) ? '<p>Remarque : ' . self::e( $d['note'] ) . '</p>' : '' ) . '</div>';
		$h .= '<div class="sign one"><div><small>Pour ' . self::e( Company::name() ) . ', service des achats</small><span>Signature</span></div></div>';
		return $h;
	}

	/** Conditionnement d'une quantité : cartons et palettes, poids brut. */
	private static function packing( ?array $m, float $qty ): array {
		if ( ! $m || $qty <= 0 ) {
			return array( 'text' => '', 'weight' => 0.0 );
		}
		$upc     = max( 1, (int) $m['units_per_carton'] );
		$cpp     = max( 1, (int) $m['cartons_per_pallet'] );
		$cartons = (int) ceil( $qty / $upc );
		$pallets = $cpp > 1 && $cartons >= $cpp / 2 ? (int) ceil( $cartons / $cpp ) : 0;
		$text    = $pallets ? sprintf( '%d pal. EUR · %d ctn', $pallets, $cartons ) : sprintf( '%d ctn', $cartons );
		return array( 'text' => $upc > 1 ? $text . ' × ' . $upc : $text, 'weight' => round( $qty * (float) $m['gross_weight'] + $pallets * 25.0, 1 ) );
	}

	/**
	 * Bulletin de livraison du fournisseur : ce que le fournisseur déclare livrer (écarts du scénario compris).
	 * Les avaries ne figurent pas sur le document : l'apprenti les constate et les note en réserve.
	 */
	private static function delivery_note( Ctx $ctx, array $d, ?array $sup, bool $unannounced ): string {
		$po_no = (string) $d['po_no'];
		$h     = self::head( self::partner_issuer( $sup, $sup && ! empty( $sup['email'] ) ? $sup['email'] : '' ), 'Bulletin de livraison', '' !== $po_no ? 'BL-' . $po_no : '', array( 'Date de livraison' => self::d( $ctx->date() ), 'Votre commande n°' => '' !== $po_no ? $po_no : ( $unannounced ? 'non communiquée' : '' ), 'Votre n° de fournisseur' => $sup['supplier_no'] ?? '', 'Conditions de livraison' => $sup ? ( $sup['incoterm'] ?: 'DAP' ) . ' Incoterms® 2020' : '' ), $po_no );
		$h    .= '<div class="addrs">' . self::addr( 'Destinataire', self::company_lines() ) . self::addr( 'Lieu de déchargement', array( 'Quai de réception', 'Centre de distribution d\'Aclens' ) ) . '</div>';
		$h    .= '<table class="ln"><thead><tr><th>Pos.</th><th>Article</th><th>Désignation</th><th>EAN</th><th>Lot</th><th>DLC / DLUO</th><th class="r">Qté livrée</th><th>Unité</th><th>Conditionnement</th><th class="r">Poids brut kg</th></tr></thead><tbody>';
		$units  = array();
		$weight = 0.0;
		$lines  = 0;
		foreach ( $d['items'] as $i ) {
			$dn  = $i['delivery_note'];
			$qty = (float) $dn['qty'];
			if ( $qty <= 0 ) {
				continue;
			}
			$m       = Catalog::material( (int) ( $dn['material']['id'] ?? $i['material']['id'] ) );
			$pk      = self::packing( $m, $qty );
			$weight += $pk['weight'];
			++$lines;
			$h .= '<tr><td>' . (int) $i['line_no'] . '</td><td class="mono">' . self::e( $m['material_no'] ?? '' ) . '</td><td>' . self::e( $m['designation'] ?? '' ) . ( $m && '' !== (string) $m['adr_class'] ? '<br><small>UN ' . self::e( $m['un_number'] ) . ', classe ' . self::e( $m['adr_class'] ) . ( $m['packing_group'] ? ', GE ' . self::e( $m['packing_group'] ) : '' ) . '</small>' : '' ) . '</td><td class="mono">' . self::e( $m['ean'] ?? '' ) . '</td><td class="mono">' . self::e( $dn['batch'] ) . '</td><td>' . self::e( self::d( $dn['bbd'] ) ) . '</td><td class="r"><b>' . self::e( self::q( $qty ) ) . '</b></td><td>' . self::e( $m['base_uom'] ?? '' ) . '</td><td>' . self::e( $pk['text'] ) . '</td><td class="r">' . self::e( number_format( $pk['weight'], 1, '.', "\u{2019}" ) ) . '</td></tr>';
			foreach ( (array) $dn['units'] as $u ) {
				$units[] = array( (int) $i['line_no'], (string) $u['sscc'], (float) $u['qty'], (string) ( $u['kind'] ?? $u['type'] ?? '' ) );
			}
		}
		if ( ! $lines ) {
			$h .= '<tr><td colspan="10">Livraison soldée : plus rien à livrer sur cette commande.</td></tr>';
		}
		$h .= '</tbody><tfoot><tr><td colspan="9" class="r">Poids brut total</td><td class="r"><b>' . self::e( number_format( $weight, 1, '.', "\u{2019}" ) ) . '</b></td></tr></tfoot></table>';
		if ( $units ) {
			$h .= '<h3>Unités de manutention (étiquettes SSCC)</h3><table class="ln sscc"><thead><tr><th>Pos.</th><th>SSCC</th><th class="r">Quantité</th></tr></thead><tbody>';
			foreach ( $units as [ $pos, $sscc, $q ] ) {
				$h .= '<tr><td>' . (int) $pos . '</td><td class="mono">(00) ' . self::e( $sscc ) . '</td><td class="r">' . self::e( self::q( $q ) ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		}
		$h .= '<div class="notes"><p>Contrôlez la marchandise en présence du chauffeur : nombre d\'unités, état des emballages, articles, lots et dates limites. Notez toute avarie ou tout manquant ci-dessous ; sans réserve, la livraison est réputée conforme.</p></div>';
		$h .= '<div class="reserve"><b>Réserves du destinataire</b><div class="box"></div></div>';
		$h .= '<div class="sign"><div><small>Reçu le (date, heure)</small><span></span></div><div><small>Nom du réceptionnaire</small><span></span></div><div><small>Signature du réceptionnaire</small><span></span></div><div><small>Signature du chauffeur</small><span></span></div></div>';
		return $h;
	}

	/** Lettre de voiture CMR (cases numérotées comme le formulaire IRU). */
	private static function cmr( array $c ): string {
		$box = static function ( string $n, string $label, string $body, string $cls = '' ): string {
			return '<div class="cb ' . $cls . '"><small><i>' . $n . '</i>' . esc_html( $label ) . '</small><div>' . $body . '</div></div>';
		};
		$lines = static fn( array $l ): string => implode( '<br>', array_map( 'esc_html', array_filter( $l ) ) );
		$h  = '<header class="dh"><div class="iss"><div class="pname">LETTRE DE VOITURE INTERNATIONALE</div><p>Ce transport est soumis à la Convention relative au contrat de transport international de marchandises par route (CMR).</p></div><div class="dt"><h2>CMR</h2><div class="no">N° <b>' . self::e( $c['no'] ) . '</b></div>' . self::bc( (string) $c['po_no'] ) . '</div></header>';
		$h .= '<div class="cmr">';
		$h .= $box( '1', 'Expéditeur', $lines( $c['sender'] ) ) . $box( '16', 'Transporteur', $lines( $c['carrier'] ) . '<br>Plaques : ' . esc_html( $c['plates'] ) . '<br>Chauffeur : ' . esc_html( $c['driver'] ) );
		$h .= $box( '2', 'Destinataire', $lines( $c['consignee'] ) ) . $box( '17', 'Transporteurs successifs', '—' );
		$h .= $box( '3', 'Lieu prévu pour la livraison', esc_html( $c['delivery'] ) ) . $box( '18', 'Réserves et observations du transporteur', '' );
		$h .= $box( '4', 'Lieu et date de la prise en charge', esc_html( $c['pickup']['place'] . ', ' . self::d( $c['pickup']['date'] ) ) ) . $box( '5', 'Documents annexés', esc_html( $c['documents'] ) );
		$rows = '';
		foreach ( $c['lines'] as $l ) {
			$rows .= '<tr><td>' . esc_html( $l['marks'] ) . '</td><td class="r">' . (int) $l['count'] . '</td><td>' . esc_html( $l['packing'] ) . '</td><td>' . esc_html( $l['nature'] ) . '</td><td class="mono">' . esc_html( $l['article'] ) . '</td><td class="r">' . esc_html( number_format( (float) $l['weight'], 1, '.', "\u{2019}" ) ) . '</td><td class="r">' . esc_html( number_format( (float) $l['volume'], 3, '.', '' ) ) . '</td></tr>';
		}
		$h .= '<div class="cb wide"><small><i>6–12</i>Marques et numéros · nombre de colis · emballage · nature de la marchandise · n° statistique · poids brut kg · cubage m³</small><table class="ln"><tbody>' . $rows . '<tr class="tot"><td>Total</td><td class="r">' . (int) $c['total']['count'] . '</td><td colspan="3"></td><td class="r">' . esc_html( number_format( (float) $c['total']['weight'], 1, '.', "\u{2019}" ) ) . '</td><td class="r">' . esc_html( number_format( (float) $c['total']['volume'], 3, '.', '' ) ) . '</td></tr></tbody></table></div>';
		$h .= $box( '13', 'Instructions de l\'expéditeur', nl2br( esc_html( $c['instructions'] ) ) ) . $box( '19', 'Conventions particulières', esc_html( $c['agreements'] ) );
		$h .= $box( '14', 'Prescriptions d\'affranchissement', esc_html( $c['payment'] ) ) . $box( '20', 'À payer par', esc_html( $c['payer'] ) );
		$h .= $box( '21', 'Établie à', esc_html( $c['established']['place'] . ', le ' . self::d( $c['established']['date'] ) ) ) . $box( '15', 'Remboursement', '—' );
		$h .= $box( '22', 'Signature et timbre de l\'expéditeur', '<span class="sigl"></span>', 'sig' ) . $box( '23', 'Signature et timbre du transporteur', '<span class="sigl"></span>', 'sig' );
		$h .= $box( '24', 'Marchandises reçues · lieu, date · signature et timbre du destinataire', 'Lieu : ………………………… Date : ……………… Heure : ………<br>Réserves : ……………………………………………………………………<span class="sigl"></span>', 'wide sig' );
		$h .= '</div><p class="small">Saisissez aussi la réception de la case 24 dans la transaction <code>ZCMR</code> : signature avec ou sans réserves.</p>';
		return $h;
	}

	/** Commande du client, avec le tampon de saisie (numéro LogiERP). */
	private static function customer_order( Ctx $ctx, array $d, ?array $cust, bool $entered ): string {
		$ref = 'CDE-' . str_pad( (string) ( abs( crc32( ( $d['so_no'] ?: 'X' ) . '|' . (int) $ctx->scenario['id'] . '|' . ( $cust['customer_no'] ?? '' ) ) ) % 900000 + 100000 ), 6, '0', STR_PAD_LEFT );
		$h   = self::head( self::partner_issuer( $cust, $cust && ! empty( $cust['email'] ) ? $cust['email'] : '' ), 'A' === $d['priority'] ? 'Commande urgente' : 'Commande', $ref, array( 'Date' => self::d( $d['order_date'] ), 'Livraison souhaitée le' => self::d( $d['req_date'] ), 'Notre n° de client' => $cust['customer_no'] ?? '', 'Créneau de livraison' => $cust['delivery_window'] ?? '' ), '' );
		$h  .= '<div class="addrs">' . self::addr( 'Fournisseur', self::company_lines() ) . self::addr( 'Livrer à', self::partner_lines( $cust ) ) . '</div>';
		$h  .= '<p>Madame, Monsieur,<br>Nous vous prions de nous livrer les articles suivants aux conditions habituelles :</p>';
		$h  .= '<table class="ln"><thead><tr><th>Pos.</th><th>Votre n° d\'article</th><th>Désignation</th><th>EAN</th><th class="r">Quantité</th><th>Unité</th></tr></thead><tbody>';
		foreach ( $d['items'] as $i ) {
			$m  = Catalog::material( (int) $i['material']['id'] );
			$h .= '<tr><td>' . (int) $i['line_no'] . '</td><td class="mono">' . self::e( $m['material_no'] ?? '' ) . '</td><td>' . self::e( $m['designation'] ?? '' ) . '</td><td class="mono">' . self::e( $m['ean'] ?? '' ) . '</td><td class="r"><b>' . self::e( self::q( (float) $i['qty'] ) ) . '</b></td><td>' . self::e( $m['base_uom'] ?? '' ) . '</td></tr>';
		}
		$h .= '</tbody></table><div class="notes">';
		if ( ! empty( $cust['packing_req'] ) ) {
			$h .= '<p><b>Exigences d\'emballage :</b> ' . self::e( $cust['packing_req'] ) . '</p>';
		}
		if ( '' !== trim( (string) $d['note'] ) ) {
			$h .= '<p><b>Remarque :</b> ' . self::e( $d['note'] ) . '</p>';
		}
		$h .= '<p>Merci de mentionner notre référence ' . self::e( $ref ) . ' sur le bulletin de livraison.</p></div>';
		$h .= '<div class="stamp"><b>Reçu et saisi dans LogiERP</b>' . ( $entered ? 'Commande client n° <b>' . self::e( $d['so_no'] ) . '</b>' . self::bc( (string) $d['so_no'] ) : 'N° attribué à l\'arrivée de la commande (voir la notification).' ) . '</div>';
		return $h;
	}

	/** Avis de modification envoyé par le client (confirmation écrite d'un appel). */
	private static function order_change( Ctx $ctx, array $d, ?array $cust, array $changes ): string {
		$h  = self::head( self::partner_issuer( $cust, $cust && ! empty( $cust['email'] ) ? $cust['email'] : '' ), 'Modification de commande', $d['so_no'], array( 'Date' => self::d( $ctx->date() ), 'Notre n° de client' => $cust['customer_no'] ?? '', 'Votre commande n°' => $d['so_no'], 'Livraison souhaitée le' => self::d( $d['req_date'] ) ) );
		$h .= '<div class="addrs">' . self::addr( 'Fournisseur', self::company_lines() ) . self::addr( 'Livrer à', self::partner_lines( $cust ) ) . '</div>';
		$h .= '<p>Madame, Monsieur,<br>Suite à notre appel téléphonique, nous vous confirmons les modifications suivantes de notre commande :</p><table class="ln"><thead><tr><th>#</th><th>Modification</th></tr></thead><tbody>';
		foreach ( array_values( $changes ) as $k => $c ) {
			$h .= '<tr><td>' . ( $k + 1 ) . '</td><td><b>' . self::e( $c['detail'] ) . '</b></td></tr>';
		}
		$h .= '</tbody></table><div class="notes"><p>Les autres articles et conditions de la commande restent inchangés. Merci de nous confirmer la prise en compte.</p></div>';
		$h .= '<div class="stamp"><b>Modification saisie dans LogiERP</b>Visa : ……………… Date : ………………</div>';
		return $h;
	}

	/** Ordre de fabrication (document d'atelier). */
	private static function production_order( Ctx $ctx, array $p ): string {
		$m  = Catalog::material( (int) $p['material']['id'] );
		$h  = self::head( self::company_issuer(), 'Ordre de fabrication', $p['prod_no'], array( 'Début prévu' => self::d( $p['start_date'] ), 'Poste de travail' => $p['work_center'], 'Article à fabriquer' => ( $m['material_no'] ?? '' ) . ' · ' . ( $m['designation'] ?? '' ), 'Quantité' => self::q( (float) $p['qty'] ) . ' ' . ( $m['base_uom'] ?? '' ) ) );
		$h .= '<h3>Composants à mettre à disposition</h3><table class="ln"><thead><tr><th>Article</th><th>Désignation</th><th class="r">Qté requise</th><th>Unité</th><th>Prélevé (qté, visa)</th></tr></thead><tbody>';
		foreach ( $p['components'] as $c ) {
			$cm = Catalog::material( (int) $c['material']['id'] );
			$h .= '<tr><td class="mono">' . self::e( $cm['material_no'] ?? '' ) . '</td><td>' . self::e( $cm['designation'] ?? '' ) . '</td><td class="r"><b>' . self::e( self::q( (float) $c['qty_required'] ) ) . '</b></td><td>' . self::e( $cm['base_uom'] ?? '' ) . '</td><td class="fill"></td></tr>';
		}
		$h .= '</tbody></table><div class="notes"><p>Mise à disposition des composants : <code>LP10</code>. Confirmation de la production (quantité bonne et rebut) : <code>CO11N</code>.</p></div>';
		$h .= '<table class="ln conf"><thead><tr><th>Quantité bonne</th><th>Rebut</th><th>Date</th><th>Visa de l\'opérateur</th><th>Visa du chef d\'atelier</th></tr></thead><tbody><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table>';
		return $h;
	}

	/* ───────── Page ───────── */

	public static function html( array $data ): string {
		/** @var Ctx $ctx */
		$ctx   = $data['ctx'];
		$sc    = $ctx->scenario;
		$docs  = self::documents( $ctx, (bool) $data['teacher'], ! empty( $data['session'] ) );
		$fnt   = LOGIERP_URL . 'assets/fonts/';
		$h     = array();
		$h[]   = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
		$h[]   = '<title>' . self::e( 'Documents – ' . $sc['title'] ) . '</title><style>';
		foreach ( array( 400, 500, 600, 700 ) as $w ) {
			$h[] = '@font-face{font-family:Poppins;src:url(' . esc_url( $fnt . 'poppins-latin-' . $w . '-normal.woff2' ) . ') format("woff2");font-weight:' . $w . '}';
		}
		$h[] = self::css() . '</style></head><body>';
		$h[] = '<div class="bar"><button type="button" onclick="window.print()">Imprimer / enregistrer en PDF</button><span>' . self::e( sprintf( '%d document(s) · générés à partir du scénario (version %d)', count( $docs ), (int) $sc['version'] ) ) . '</span></div>';

		// Fiche de distribution.
		$h[] = '<main><section class="page"><header class="dh"><div class="iss">' . Company::logo_svg( 34 ) . '</div><div class="dt"><h2>Documents de l\'activité</h2></div></header>';
		$h[] = '<p class="lead"><b>' . self::e( $sc['title'] ) . '</b>' . ( $data['class'] ? ' · classe ' . self::e( $data['class'] ) : '' ) . ( $data['student'] ? ' · ' . self::e( $data['student'] ) : '' ) . ' · date de la mission dans le système : ' . self::e( self::d( $ctx->date() ) ) . '</p>';
		if ( $data['teacher'] && empty( $data['session'] ) ) {
			$h[] = '<p>Les documents ci-après sont ceux que l\'apprenti aurait en main dans l\'entreprise. Leurs numéros sont identiques dans toutes les sessions de ce scénario : imprimez-les une fois par poste de travail (ou par binôme). Les écarts prévus par le scénario (quantités, article de remplacement, dates limites) figurent sur le bulletin de livraison ; les avaries ne sont pas écrites : l\'apprenti doit les constater.</p>';
		}
		if ( ! $docs ) {
			$h[] = '<p class="empty">Ce scénario ne prépare aucun document (aucune commande d\'achat, commande client ni ordre de fabrication).</p>';
		} else {
			$h[] = '<table class="ln toc"><thead><tr><th>#</th><th>Document</th><th>Numéro</th><th>Remis</th></tr></thead><tbody>';
			foreach ( $docs as $k => $d ) {
				$h[] = '<tr><td>' . ( $k + 1 ) . '</td><td><a href="#d' . ( $k + 1 ) . '">' . self::e( $d['title'] ) . '</a></td><td class="mono">' . self::e( $d['no'] ) . '</td><td>' . self::e( $d['give'] ) . '</td></tr>';
			}
			$h[] = '</tbody></table>';
		}
		$h[] = '</section>';
		foreach ( $docs as $k => $d ) {
			$h[] = '<section class="page doc" id="d' . ( $k + 1 ) . '"><div class="tag">' . self::e( $d['type'] ) . ' · ' . self::e( $d['give'] ) . '</div>' . $d['html'] . '<footer>' . self::e( Company::name() ) . ' · ' . self::e( $sc['title'] ) . ' · document ' . ( $k + 1 ) . '/' . count( $docs ) . ' · remis : ' . self::e( $d['give'] ) . ' · simulation de formation LogiERP</footer></section>';
		}
		$h[] = '</main><script src="' . esc_url( LOGIERP_URL . 'assets/js/barcode.js?ver=' . LOGIERP_VERSION ) . '"></script>';
		$h[] = '<script>(function(){var B=window.LogiBarcode;if(!B){return;}document.querySelectorAll(".bc[data-c128]").forEach(function(n){try{var s=B.code128Svg(n.getAttribute("data-c128"),{module:2,height:40,hri:""});s.style.height="10mm";s.style.width="auto";n.appendChild(s);}catch(e){}});})();</script>';
		$h[] = '</body></html>';
		return implode( '', $h );
	}

	private static function css(): string {
		return '@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:9pt/1.45 Poppins,Arial,sans-serif;color:#1d2733}'
			. '.bar{position:sticky;top:0;z-index:2;display:flex;gap:14px;align-items:center;padding:10px 16px;background:#232c37;color:#c9d2dc;font-size:13px}.bar button{font:600 14px Poppins,Arial;background:#f2c200;color:#1d2733;border:0;border-radius:20px;padding:9px 18px;cursor:pointer}'
			. '.page{max-width:210mm;min-height:270mm;margin:16px auto;background:#fff;padding:12mm;box-shadow:0 2px 10px rgba(0,0,0,.12);position:relative;display:flex;flex-direction:column}'
			. '.tag{margin:-6mm 0 3mm;font-size:7pt;color:#8a939d;text-transform:uppercase;letter-spacing:.04em}'
			. '.dh{display:flex;justify-content:space-between;gap:10mm;align-items:flex-start;border-bottom:2px solid #232c37;padding-bottom:4mm;margin-bottom:4mm}.iss p{margin:2mm 0 0;font-size:8.4pt;color:#3d4753}.pname{font-size:13pt;font-weight:700;line-height:1.2}'
			. '.dt{text-align:right}.dt h2{margin:0;font-size:17pt;line-height:1.1;text-transform:uppercase;letter-spacing:.02em}.no{font-size:10.5pt;margin:1mm 0}.bc{display:block;margin-top:1mm}'
			. '.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:2mm;margin:0 0 4mm}.meta div{border:1px solid #c9cfd6;border-radius:1.5mm;padding:1.5mm 2.5mm}.meta dt{font-size:7pt;color:#5d6875;text-transform:uppercase}.meta dd{margin:0;font-weight:600}'
			. '.addrs{display:grid;grid-template-columns:1fr 1fr;gap:6mm;margin:0 0 4mm}.addr{border-left:3px solid #232c37;padding:1mm 0 1mm 3mm;font-size:9.4pt}.addr small{display:block;font-size:7pt;color:#5d6875;text-transform:uppercase}'
			. 'table.ln{width:100%;border-collapse:collapse;margin:0 0 4mm;font-size:8.4pt}.ln th{background:#232c37;color:#fff;text-align:left;font-weight:600;padding:1.4mm 1.8mm}.ln td{border-bottom:1px solid #d5dae0;padding:1.4mm 1.8mm;vertical-align:top}.ln tfoot td{border-bottom:0;padding-top:2mm}.ln .r{text-align:right}.ln small{color:#8a0010}'
			. '.mono{font-family:"DejaVu Sans Mono",Consolas,monospace;font-size:8pt}.sscc td{padding:.8mm 1.8mm}h3{font-size:10pt;margin:2mm 0 1.5mm}'
			. '.notes{font-size:8.4pt;color:#3d4753}.notes p{margin:0 0 1.5mm}code{font-family:"DejaVu Sans Mono",monospace;background:#eef1f4;padding:0 .3em;border-radius:3px}'
			. '.reserve{margin:2mm 0 4mm}.reserve .box{border:1px solid #9aa3ad;height:22mm;border-radius:1.5mm;margin-top:1mm;background:repeating-linear-gradient(#fff,#fff 6.8mm,#e3e6ea 6.8mm,#e3e6ea 7mm)}'
			. '.sign{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm;margin-top:auto}.sign.one{grid-template-columns:1fr 1fr}.sign div{border-top:1px solid #1d2733;padding-top:1mm;min-height:16mm}.sign small{font-size:7pt;color:#5d6875}'
			. '.stamp{margin:6mm 0 0 auto;width:78mm;border:2.5px solid #1c64b0;color:#1c64b0;border-radius:3mm;padding:3mm 4mm;transform:rotate(-2deg);font-size:9pt}.stamp b:first-child{display:block;text-transform:uppercase;letter-spacing:.05em}'
			. '.cmr{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid #b3261e;border-radius:1mm}.cb{border:.5px solid #b3261e;padding:1.2mm 2mm;min-height:15mm;font-size:8.2pt}.cb small{display:block;font-size:6.6pt;color:#b3261e}.cb i{font-style:normal;font-weight:700;display:inline-block;min-width:6mm}.cb.wide{grid-column:1/-1}.cb .ln{margin:1mm 0 0}.cb .ln td{padding:.8mm 1.4mm}.cb.sig{min-height:24mm}.tot td{font-weight:700}.small{font-size:7.6pt;color:#5d6875}'
			. '.conf td{height:12mm}.fill{min-width:35mm}.lead{font-size:10.5pt}.toc a{color:#1c64b0;text-decoration:none}.empty{padding:6mm;background:#f6f7f9;border-radius:2mm}'
			. 'footer{margin-top:6mm;padding-top:2mm;border-top:1px solid #e3e6ea;font-size:7pt;color:#8a939d}'
			. '@media print{body{background:#fff}.bar,.tag{display:none}.page{box-shadow:none;margin:0;max-width:none;min-height:271mm;padding:0;page-break-after:always;break-after:page}.page:last-child{page-break-after:auto}}';
	}
}
