<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Admin\ScenariosPage;
use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Api;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Company;
use LogiERP\Domain\Events;
use LogiERP\Domain\SessionService;
use LogiERP\Scenario\Grader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document d'activité d'un scénario : la fiche de mission distribuée aux apprentis (imprimable, A4).
 *
 * Il est généré à partir du scénario à chaque ouverture : toute modification du scénario le met à jour.
 * Trois accès, chacun protégé par un nonce et un contrôle de droits :
 *  - enseignant : à partir du scénario (références PO1, SO1… des documents) ;
 *  - apprenant : à partir d'une assignation de sa classe ;
 *  - session : avec les vrais numéros de documents de la session (commande 4500000031…).
 */
final class ActivityDoc {

	public const QUERY = 'logierp_doc';

	/** Libellés des transactions citées dans les consignes. */
	private const TX = array(
		'ZENTR' => 'Présentation de l\'entreprise', 'SMEN' => 'Accueil de la session', 'SLG1' => 'Journal de mes actions',
		'MM03' => 'Afficher un article', 'XK03' => 'Afficher un fournisseur', 'XD03' => 'Afficher un client',
		'ME21N' => 'Créer une commande d\'achat', 'ME23N' => 'Afficher une commande d\'achat', 'ME2M' => 'Besoins d\'achat',
		'MIGO' => 'Mouvements de marchandises', 'ZCMR' => 'Lettres de voiture CMR', 'HU03' => 'Étiquettes palettes SSCC',
		'MMBE' => 'Aperçu du stock d\'un article', 'MB52' => 'Stock par magasin', 'MB51' => 'Documents de mouvement',
		'LT12' => 'Confirmer un ordre de transfert', 'LT01' => 'Créer un ordre de transfert', 'LT10' => 'Déplacer du stock (quarantaine)',
		'LX03' => 'Emplacements d\'un magasin', 'ZMAP' => 'Plan de l\'entrepôt', 'LM00' => 'Terminal de scan',
		'VA01' => 'Créer une commande client', 'VA03' => 'Afficher une commande client', 'VL01N' => 'Créer une livraison',
		'VL02N' => 'Préparer et expédier une livraison', 'VL10' => 'Commandes à livrer (préparation)', 'HU02' => 'Colisage SSCC',
		'CO01' => 'Créer un ordre de fabrication', 'CO02' => 'Lancer un ordre de fabrication', 'LP10' => 'Mise à disposition production',
		'CO11N' => 'Confirmer un ordre de fabrication', 'MI01' => 'Créer un document d\'inventaire', 'MI04' => 'Saisir un comptage',
		'MI07' => 'Comptabiliser les écarts', 'ZRET' => 'Retours clients', 'MD04' => 'Liste stock/besoins', 'MD01' => 'Calcul des besoins',
		'MD02' => 'Calcul des besoins (un article)', 'QA32' => 'Lots de contrôle qualité', 'QA11' => 'Décision d\'emploi',
		'MB5M' => 'Dates limites (DLC)', 'VT01N' => 'Planifier une tournée', 'VT02N' => 'Traiter une tournée', 'MB58' => 'Emballages consignés',
		'MB11' => 'Mouvement de stock par code', 'MB03' => 'Afficher un document d\'article', 'MBST' => 'Annuler un document d\'article',
		'LS26' => 'Stock de l\'entrepôt (quants)', 'LT13' => 'Confirmer un OT par unité de stock', 'LT15' => 'Annuler un ordre de transfert',
		'LT31' => 'Réimprimer un OT et ses étiquettes', 'LI21' => 'Solder les écarts (aire 999)', 'ME2L' => 'Commandes d\'achat par fournisseur',
		'Y_P01' => 'Arrivages planifiés', 'ME22N' => 'Modifier une commande d\'achat', 'MIGO_GR' => 'Réception d\'un transfert entre sites',
		'VA02' => 'Modifier une commande client', 'VA05' => 'Liste des commandes clients', 'VL06O' => 'Sortie collective des livraisons',
		'VL09' => 'Annuler une sortie de marchandises', 'VL74' => 'Réimprimer les étiquettes d\'expédition', 'COR1' => 'Créer un ordre de procédé',
		'COR2' => 'Modifier un ordre de procédé', 'COR3' => 'Afficher un ordre de procédé', 'COR5' => 'Lancer des ordres de procédé',
		'COR8' => 'Liste des ordres de procédé', 'CORK' => 'Confirmer un ordre de procédé', 'CORS' => 'Annuler une confirmation',
		'CS03' => 'Afficher une recette', 'CS15' => 'Cas d\'emploi d\'un composant', 'ZVLGUT' => 'Emballages consignés et conteneurs loués',
		'ZPEMB' => 'Portail emballages (simulation)', 'ZRAIL' => 'Portail ferroviaire (simulation)',
		'VL06T' => 'Livraisons à planifier pour le transport', 'VT04' => 'Créer les transports en masse', 'VT11' => 'Plan des transports du jour',
		'VT03N' => 'Afficher un transport', 'VT70' => 'Papiers du transport', 'VL71' => 'Bulletins de livraison en série',
	);

	/** Procédures du manuel d'utilisation qui décrivent chaque transaction. */
	public const HELP = array(
		'ZENTR' => 'A.1.1', 'SMEN' => 'A.1.2', 'SLG1' => 'A.1.2', 'MM03' => 'C.3.3', 'XK03' => 'C.3.3', 'XD03' => 'C.3.3',
		'ME21N' => 'B.1.0', 'ME23N' => 'B.1.1', 'ME2M' => 'B.1.0', 'MIGO' => 'B.2.0 · B.2.1 · C.2.0', 'ZCMR' => 'B.2.2', 'HU03' => 'B.2.3',
		'MMBE' => 'C.3.0', 'MB52' => 'C.3.0', 'MB51' => 'C.3.1', 'LT12' => 'C.1.0 · E.2.0', 'LT01' => 'C.1.1 · C.1.2', 'LT10' => 'C.1.3',
		'LX03' => 'C.3.2', 'ZMAP' => 'C.3.2', 'VA01' => 'E.1.0', 'VA03' => 'E.1.1', 'VL01N' => 'E.2.0', 'VL02N' => 'E.2.1 · P.3.1',
		'VL10' => 'E.3.0', 'HU02' => 'E.3.1', 'CO01' => 'G.1.1', 'CO02' => 'G.1.1', 'LP10' => 'G.1.2', 'CO11N' => 'G.1.2',
		'MI01' => 'H.1.0', 'MI04' => 'H.1.0 · M.7.0', 'MI07' => 'H.1.0', 'ZRET' => 'I.1.0', 'MD04' => 'G.1.0', 'MD01' => 'G.1.0',
		'QA32' => 'D.1.0', 'QA11' => 'D.1.0 · D.1.1', 'MB5M' => 'D.2.0', 'VT01N' => 'F.1.0', 'VT02N' => 'F.1.1 · P.6.2', 'MB58' => 'F.1.1 · P.5.0',
		'MB11' => 'P.1.0', 'MB03' => 'P.1.1', 'MBST' => 'P.1.1', 'LS26' => 'P.1.2', 'LT13' => 'P.1.2', 'LT15' => 'P.1.2 · P.3.1', 'LT31' => 'P.1.2',
		'LI21' => 'P.5.1', 'ME2L' => 'P.2.0', 'Y_P01' => 'P.2.0', 'ME22N' => 'P.2.1', 'MIGO_GR' => 'P.2.2', 'VA02' => 'P.3.0', 'VA05' => 'P.3.0',
		'VL06O' => 'P.3.2', 'VL09' => 'P.3.2', 'VL74' => 'P.3.2', 'CS03' => 'P.4.0', 'CS15' => 'P.4.0', 'COR1' => 'P.4.0', 'COR5' => 'P.4.0', 'COR8' => 'P.4.0',
		'CORK' => 'P.4.1', 'CORS' => 'P.4.1', 'COR2' => 'P.4.1', 'COR3' => 'P.4.1', 'ZVLGUT' => 'P.5.0', 'ZPEMB' => 'P.5.0', 'ZRAIL' => 'P.5.1',
		'VL06T' => 'P.6.0', 'VT04' => 'P.6.0', 'VT11' => 'P.6.1', 'VT03N' => 'P.6.2', 'VT70' => 'P.6.2', 'VL71' => 'P.6.2',
	);

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/* ───────── Adresses (avec nonce propre à l'utilisateur connecté) ───────── */

	private static function url( string $key, int $id ): string {
		return add_query_arg(
			array(
				self::QUERY => 'activity',
				$key        => $id,
				'_wpnonce'  => wp_create_nonce( 'lw_doc_' . $key . '_' . $id ),
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

	/* ───────── Affichage ───────── */

	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous pour chaque type d'accès.
		if ( ! isset( $_GET[ self::QUERY ] ) || 'activity' !== $_GET[ self::QUERY ] ) {
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
			if ( ! $id || ! wp_verify_nonce( $nonce, 'lw_doc_' . $key . '_' . $id ) ) {
				self::deny( 'Lien expiré ou invalide : rouvrez le document depuis LogiERP.' );
			}
			$data = self::resolve( $key, $id, $uid );
			break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $data ) {
			self::deny( 'Document introuvable ou accès refusé.' );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		echo self::html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document entièrement échappé dans html().
		exit;
	}

	private static function deny( string $msg ): void {
		wp_die( esc_html( $msg ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 403 ) );
	}

	/** Contrôle des droits et réunion des données. */
	private static function resolve( string $key, int $id, int $uid ): ?array {
		global $wpdb;
		$sc = null;
		$ctx = array( 'class' => '', 'student' => '', 'opens' => null, 'closes' => null, 'numbers' => array(), 'audience' => 'teacher' );
		if ( 'scenario' === $key ) {
			$sc = SessionService::scenario( $id );
			if ( ! $sc || ! ScenariosPage::can_view( $sc ) ) {
				return null;
			}
		} elseif ( 'assignment' === $key ) {
			$a  = Db::t( 'assignment' );
			$c  = Db::t( 'class' );
			$cm = Db::t( 'class_member' );
			$as = $wpdb->get_row( $wpdb->prepare( "SELECT a.*, c.name AS class_name, c.teacher_id FROM {$a} a JOIN {$c} c ON c.id = a.class_id WHERE a.id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $as ) {
				return null;
			}
			$member  = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$cm} WHERE class_id = %d AND user_id = %d", (int) $as['class_id'], $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $member && \LogiERP\Domain\Teams::ROSTER_NONE !== (int) $as['roster'] ) {
				// Mission attribuée à une liste : seuls ses membres voient le document d'activité.
				\LogiERP\Domain\Teams::sync( $as );
				$member = null !== \LogiERP\Domain\Teams::member( $id, $uid );
			}
			$teacher = (int) $as['teacher_id'] === $uid || current_user_can( Capabilities::VIEW_ALL );
			if ( ! $member && ! $teacher ) {
				return null;
			}
			$sc = SessionService::scenario( (int) $as['scenario_id'] );
			if ( ! $sc || ( $member && ! $teacher && 'PUBLISHED' !== $sc['status'] ) ) {
				return null;
			}
			$ctx['class']    = (string) $as['class_name'];
			$ctx['opens']    = $as['opens_at'];
			$ctx['closes']   = $as['closes_at'];
			$ctx['audience'] = $member && ! $teacher ? 'student' : 'teacher';
			if ( $member ) {
				$ctx['student'] = wp_get_current_user()->display_name;
				// Session déjà commencée (la sienne ou celle de son groupe) : les vrais numéros de documents.
				$t    = Db::t( 'session' );
				$mrow = \LogiERP\Domain\Teams::member( $id, $uid );
				$tid  = $mrow ? (int) $mrow['team_id'] : 0;
				if ( $tid ) {
					$tm = \LogiERP\Domain\Teams::team( $tid );
					$ctx['student'] = ( $tm ? $tm['name'] . ' · ' : '' ) . implode( ', ', array_map( array( \LogiERP\Domain\Teams::class, 'short_name' ), \LogiERP\Domain\Teams::team_member_ids( $tid ) ) );
				}
				$map = $tid
					? (string) $wpdb->get_var( $wpdb->prepare( "SELECT doc_map FROM {$t} WHERE assignment_id = %d AND team_id = %d ORDER BY id DESC LIMIT 1", $id, $tid ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					: (string) $wpdb->get_var( $wpdb->prepare( "SELECT doc_map FROM {$t} WHERE assignment_id = %d AND user_id = %d AND team_id = 0 ORDER BY id DESC LIMIT 1", $id, $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ctx['numbers'] = self::numbers( $map );
			}
		} else {
			$c = SessionService::context_for( $id, $uid, false );
			if ( ! $c ) {
				return null;
			}
			$sc              = $c->scenario;
			$ctx['numbers']  = self::numbers( (string) ( $c->session['doc_map'] ?? '' ) );
			$ctx['audience'] = \LogiERP\Domain\Teams::is_participant( $c->session, $uid ) ? 'student' : 'teacher';
			if ( 'student' === $ctx['audience'] ) {
				$ctx['student'] = (int) $c->session['team_id'] ? \LogiERP\Domain\Teams::label( $c->session ) : wp_get_current_user()->display_name;
			}
			if ( (int) $c->session['assignment_id'] ) {
				$a = Db::t( 'assignment' );
				$k = Db::t( 'class' );
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT a.opens_at, a.closes_at, k.name FROM {$a} a JOIN {$k} k ON k.id = a.class_id WHERE a.id = %d", (int) $c->session['assignment_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $r ) {
					$ctx['class']  = (string) $r['name'];
					$ctx['opens']  = $r['opens_at'];
					$ctx['closes'] = $r['closes_at'];
				}
			}
		}
		return array( 'sc' => $sc, 'ctx' => $ctx );
	}

	/** @return array<string,string> réf. (PO1) => numéro de document de la session */
	private static function numbers( string $doc_map ): array {
		$map = json_decode( $doc_map, true );
		$out = array();
		foreach ( (array) ( $map['refs'] ?? array() ) as $ref => $d ) {
			$out[ (string) $ref ] = (string) ( $d['no'] ?? '' );
		}
		return $out;
	}

	/** Données du document (aussi utilisées par les tests). */
	public static function content( array $sc, array $ctx ): array {
		global $wpdb;
		$sid   = (int) $sc['id'];
		$tasks = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'scenario_task' ) . ' WHERE scenario_id = %d ORDER BY seq', $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$docs  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'scenario_document' ) . ' WHERE scenario_id = %d ORDER BY id', $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$d_out = array();
		foreach ( $docs as $d ) {
			$items = json_decode( (string) $d['items'], true ) ?: array();
			$lines = array();
			foreach ( $items as $i ) {
				$m = Catalog::material( (int) ( $i['material_id'] ?? 0 ) );
				if ( $m ) {
					$lines[] = array( 'no' => $m['material_no'], 'designation' => $m['designation'], 'qty' => Catalog::format_qty( (float) ( $i['qty'] ?? 0 ) ) . ' ' . $m['base_uom'] );
				}
			}
			$partner = '';
			if ( 'PROD' !== $d['doc_type'] && (int) $d['partner_id'] ) {
				$p       = Api::partner_full( 'PO' === $d['doc_type'] ? 'supplier' : 'customer', (int) $d['partner_id'] );
				$partner = $p ? ( $p['supplier_no'] ?? $p['customer_no'] ) . ' · ' . $p['name'] . ( ! empty( $p['city'] ) ? ', ' . $p['city'] : '' ) : '';
			}
			$date    = gmdate( 'd.m.Y', (int) strtotime( (string) $sc['sim_date'] . ' ' . sprintf( '%+d', (int) $d['day_offset'] ) . ' days' ) );
			$d_out[] = array(
				'ref'      => (string) $d['ref'],
				'no'       => (string) ( $ctx['numbers'][ $d['ref'] ] ?? '' ),
				'type'     => (string) $d['doc_type'],
				'label'    => array( 'PO' => 'Commande d\'achat (fournisseur)', 'SO' => 'Commande client', 'PROD' => 'Ordre de fabrication' )[ $d['doc_type'] ] ?? $d['doc_type'],
				'partner'  => $partner,
				'date'     => $date,
				'priority' => 'SO' === $d['doc_type'] ? (string) $d['priority'] : '',
				'note'     => (string) $d['note'],
				'lines'    => $lines,
			);
		}
		$checks = array();
		$total  = 0.0;
		foreach ( Grader::checks_of( $sid ) as $c ) {
			$checks[] = array( 'label' => (string) $c['label'], 'points' => (float) $c['points'] );
			$total   += (float) $c['points'];
		}
		$codes = array();
		foreach ( $tasks as $t ) {
			$code = strtoupper( (string) $t['transaction_code'] );
			if ( '' !== $code && ! isset( $codes[ $code ] ) ) {
				$codes[ $code ] = array( self::TX[ $code ] ?? '', self::HELP[ $code ] ?? '' );
			}
		}
		$mods = array();
		foreach ( array_filter( explode( ',', (string) $sc['modules'] ) ) as $m ) {
			$mods[] = ScenariosPage::MODULES[ $m ] ?? $m;
		}
		return array(
			'title'        => (string) $sc['title'],
			'level'        => ScenariosPage::LEVELS[ $sc['level'] ] ?? (string) $sc['level'],
			'duration'     => (int) $sc['duration_min'],
			'version'      => (int) $sc['version'],
			'sim_date'     => gmdate( 'd.m.Y', (int) strtotime( (string) $sc['sim_date'] ) ),
			'mode'         => 'SIMPLE' === $sc['location_mode'] ? 'Gestion simple : le stock est géré par magasin (zone), sans emplacements.' : 'Gestion détaillée : chaque mouvement d\'entrepôt passe par un ordre de transfert et un emplacement.',
			'guided'       => (int) $sc['guided'],
			'hv_key'       => (int) $sc['hv_key'],
			'expiry'       => 'MANUEL' === ( $sc['expiry_mode'] ?? 'AUTO' ),
			'instructions' => (string) $sc['instructions'],
			'objectives'   => array_values( array_filter( array_map( 'trim', explode( "\n", (string) $sc['objectives'] ) ) ) ),
			'tasks'        => array_map( static fn( $t ) => array( 'title' => (string) $t['title'], 'body' => (string) $t['body'], 'code' => strtoupper( (string) $t['transaction_code'] ) ), $tasks ),
			'docs'         => $d_out,
			'codes'        => $codes,
			'modules'      => $mods,
			'events'       => count( Events::events_of( $sid ) ),
			'checks'       => (int) ( $sc['doc_criteria'] ?? 1 ) ? $checks : array(),
			'total'        => $total,
			'notes'        => (string) ( $sc['doc_notes'] ?? '' ),
			'ctx'          => $ctx,
		);
	}

	private static function e( string $s ): string {
		return esc_html( $s );
	}

	private static function fr_date( ?string $dt ): string {
		return $dt ? wp_date( 'd.m.Y à H:i', (int) strtotime( $dt . ' UTC' ) ) : '';
	}

	public static function html( array $data ): string {
		$c   = self::content( $data['sc'], $data['ctx'] );
		$ctx = $c['ctx'];
		$fnt = LOGIERP_URL . 'assets/fonts/';
		$h   = array();
		$h[] = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
		$h[] = '<title>' . self::e( 'Document d\'activité – ' . $c['title'] ) . '</title><style>';
		foreach ( array( 400, 500, 600, 700 ) as $w ) {
			$h[] = '@font-face{font-family:Poppins;src:url(' . esc_url( $fnt . 'poppins-latin-' . $w . '-normal.woff2' ) . ') format("woff2");font-weight:' . $w . '}';
		}
		$h[] = self::css();
		$h[] = '</style></head><body><div class="bar"><button type="button" onclick="window.print()">Imprimer / enregistrer en PDF</button><span>Document généré à partir du scénario (version ' . (int) $c['version'] . ').</span></div><main class="doc">';

		// En-tête comme les procédures : bande, titre, code.
		$h[] = '<header class="hd"><div class="brand">' . Company::logo_svg( 34 ) . '<span>Formation logistique · simulateur LogiERP</span></div><div class="kind">Document d\'activité</div></header>';
		$h[] = '<table class="tt"><tr><td class="band"></td><td class="ttl">' . self::e( $c['title'] ) . '</td><td class="lvl">' . self::e( $c['level'] ) . '<small>' . (int) $c['duration'] . ' min</small></td></tr>';
		$h[] = '<tr><td class="band"></td><td colspan="2" class="meta"><span><b>Classe</b> ' . self::e( $ctx['class'] ?: '……………………………' ) . '</span><span><b>Apprenti·e</b> ' . self::e( $ctx['student'] ?: '……………………………………' ) . '</span><span><b>Date</b> ' . ( $ctx['opens'] ? self::e( self::fr_date( $ctx['opens'] ) ) : '……………………' ) . '</span></td></tr></table>';
		if ( $ctx['closes'] ) {
			$h[] = '<p class="dl">À remettre au plus tard le <b>' . self::e( self::fr_date( $ctx['closes'] ) ) . '</b>.</p>';
		}

		// 1. Mission.
		$h[] = '<section><h2><i>1</i>Votre mission</h2>';
		if ( '' !== trim( $c['instructions'] ) ) {
			$h[] = '<div class="mission">' . nl2br( self::e( $c['instructions'] ) ) . '</div>';
		}
		if ( $c['objectives'] ) {
			$h[] = '<h3>Objectifs</h3><ul class="obj">';
			foreach ( $c['objectives'] as $o ) {
				$h[] = '<li>' . self::e( $o ) . '</li>';
			}
			$h[] = '</ul>';
		}
		$h[] = '</section>';

		// 2. Cadre.
		$h[] = '<section><h2><i>2</i>Le cadre de travail</h2><dl class="kv">';
		$h[] = '<dt>Entreprise</dt><dd>' . self::e( Company::name() ) . ' – lisez la présentation dans la transaction <code>ZENTR</code> au début de la mission.</dd>';
		$h[] = '<dt>Date dans le système</dt><dd>' . self::e( $c['sim_date'] ) . ' (date simulée de la journée de travail)</dd>';
		$h[] = '<dt>Organisation du stock</dt><dd>' . self::e( $c['mode'] ) . '</dd>';
		if ( $c['modules'] ) {
			$h[] = '<dt>Domaines ouverts</dt><dd>' . self::e( implode( ' · ', $c['modules'] ) ) . '</dd>';
		}
		$opts = array();
		$opts[] = $c['guided'] ? 'Mode guidé : les consignes se cochent quand la transaction est réussie, les emplacements proposés sont pré-remplis.' : 'Mode autonome : vous décidez vous-même de l\'ordre et des emplacements, rien n\'est pré-rempli.';
		if ( $c['hv_key'] ) {
			$opts[] = 'Vous avez la clé des zones sous clé (haute valeur, produits toxiques).';
		}
		if ( $c['expiry'] ) {
			$opts[] = 'Les lots périmés ne sont pas bloqués automatiquement : à vous de les repérer et de les bloquer.';
		}
		$h[] = '<dt>Règles du scénario</dt><dd>' . self::e( implode( ' ', $opts ) ) . '</dd>';
		if ( $c['events'] ) {
			$h[] = '<dt>Imprévus</dt><dd>Des imprévus peuvent survenir pendant la mission (message, livraison, commande urgente…). Lisez chaque message et adaptez votre travail.</dd>';
		}
		$h[] = '</dl></section>';

		// 3. Documents.
		if ( $c['docs'] ) {
			$h[] = '<section><h2><i>3</i>Les documents de la mission</h2><p class="hint">' . self::e( $ctx['numbers'] ? 'Les numéros sont ceux de votre session.' : 'Les numéros définitifs (commande 45…, commande client 00…) s\'affichent dans votre session ; la référence (PO1, SO1…) permet de les retrouver dans « Documents du scénario ».' ) . '</p>';
			foreach ( $c['docs'] as $d ) {
				$h[] = '<div class="docb"><div class="dh"><b>' . self::e( $d['label'] ) . '</b><span class="ref">' . self::e( '' !== $d['no'] ? 'N° ' . $d['no'] . ' · réf. ' . $d['ref'] : 'Réf. ' . $d['ref'] ) . '</span></div>';
				$meta = array();
				if ( '' !== $d['partner'] ) {
					$meta[] = ( 'PO' === $d['type'] ? 'Fournisseur : ' : 'Client : ' ) . $d['partner'];
				}
				$meta[] = ( 'PO' === $d['type'] ? 'Livraison attendue le ' : ( 'SO' === $d['type'] ? 'Livraison souhaitée le ' : 'Début prévu le ' ) ) . $d['date'];
				if ( '' !== $d['priority'] ) {
					$meta[] = 'Priorité ' . $d['priority'] . ( 'A' === $d['priority'] ? ' (urgente)' : '' );
				}
				if ( '' !== $d['note'] ) {
					$meta[] = $d['note'];
				}
				$h[] = '<p class="dm">' . self::e( implode( ' · ', $meta ) ) . '</p><table class="lines"><thead><tr><th>Article</th><th>Désignation</th><th class="r">Quantité</th></tr></thead><tbody>';
				foreach ( $d['lines'] as $l ) {
					$h[] = '<tr><td class="mono">' . self::e( $l['no'] ) . '</td><td>' . self::e( $l['designation'] ) . '</td><td class="r">' . self::e( $l['qty'] ) . '</td></tr>';
				}
				$h[] = '</tbody></table></div>';
			}
			$h[] = '</section>';
		}

		// 4. Étapes.
		$n   = $c['docs'] ? 4 : 3;
		$h[] = '<section><h2><i>' . $n . '</i>Les étapes à réaliser</h2>';
		if ( $c['tasks'] ) {
			$h[] = '<ol class="tasks">';
			foreach ( $c['tasks'] as $k => $t ) {
				$code = $t['code'];
				$h[]  = '<li><span class="box"></span><div><b>' . self::e( $t['title'] ) . '</b>' . ( '' !== $t['body'] ? '<p>' . nl2br( self::e( $t['body'] ) ) . '</p>' : '' ) . ( '' !== $code ? '<p class="tx">Transaction <code>' . self::e( $code ) . '</code>' . ( isset( self::TX[ $code ] ) ? ' – ' . self::e( self::TX[ $code ] ) : '' ) . ( isset( self::HELP[ $code ] ) ? ' · manuel, procédure ' . self::e( self::HELP[ $code ] ) : '' ) . '</p>' : '' ) . '</div></li>';
				unset( $k );
			}
			$h[] = '</ol>';
		} else {
			$h[] = '<p>Suivez la mise en situation ci-dessus.</p>';
		}
		$h[] = '<p class="hint">Cochez chaque case quand l\'étape est terminée. Quand tout est fait, cliquez sur <b>Remettre ma session</b> : la session est corrigée automatiquement.</p></section>';

		// 5. Évaluation.
		++$n;
		if ( $c['checks'] ) {
			$h[] = '<section class="keep"><h2><i>' . $n . '</i>Comment votre travail est évalué</h2><table class="lines crit"><thead><tr><th>Critère contrôlé à la remise</th><th class="r">Points</th></tr></thead><tbody>';
			foreach ( $c['checks'] as $k ) {
				$h[] = '<tr><td>' . self::e( $k['label'] ) . '</td><td class="r">' . self::e( Catalog::format_qty( $k['points'] ) ) . '</td></tr>';
			}
			$h[] = '<tr class="tot"><td>Total</td><td class="r">' . self::e( Catalog::format_qty( (float) $c['total'] ) ) . '</td></tr></tbody></table><p class="hint">Note = 1 + 5 × points obtenus ÷ ' . self::e( Catalog::format_qty( (float) $c['total'] ) ) . ', arrondie au demi-point. Chaque saisie refusée par le système compte comme une erreur : lisez les messages et corrigez.</p></section>';
			++$n;
		}

		// 6. Transactions et aide.
		if ( $c['codes'] ) {
			$h[] = '<section class="keep"><h2><i>' . $n . '</i>Transactions utiles et aide</h2><table class="lines"><thead><tr><th>Code</th><th>Transaction</th><th>Manuel d\'utilisation</th></tr></thead><tbody>';
			foreach ( $c['codes'] as $code => $x ) {
				$h[] = '<tr><td class="mono">' . self::e( (string) $code ) . '</td><td>' . self::e( $x[0] ) . '</td><td>' . self::e( '' !== $x[1] ? 'Procédure ' . $x[1] : '' ) . '</td></tr>';
			}
			$h[] = '</tbody></table></section>';
			++$n;
		}
		if ( '' !== trim( $c['notes'] ) ) {
			$h[] = '<section class="keep"><h2><i>' . $n . '</i>Remarques de votre enseignant·e</h2><div class="notes">' . nl2br( self::e( $c['notes'] ) ) . '</div></section>';
		}
		$h[] = '<section class="keep"><h2 class="imp">Important !</h2><ul class="imp-l"><li>Travaillez comme dans une vraie entreprise : contrôlez chaque document avant de saisir, ne saisissez jamais une quantité que vous n\'avez pas vue.</li><li>Une avarie, un écart ou un doute se signale : réserve, statut bloqué, message à votre enseignant·e.</li><li>Votre journal (<code>SLG1</code>) garde toutes vos actions, réussies ou refusées.</li></ul></section>';
		$h[] = '<footer>' . self::e( Company::name() ) . ' · ' . self::e( $c['title'] ) . ' · version ' . (int) $c['version'] . ' · généré le ' . self::e( wp_date( 'd.m.Y' ) ) . '</footer>';
		$h[] = '</main></body></html>';
		return implode( '', $h );
	}

	private static function css(): string {
		return '@page{size:A4;margin:14mm 14mm 14mm}*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:10pt/1.5 Poppins,Arial,sans-serif;color:#1d2733}'
			. '.bar{position:sticky;top:0;z-index:2;display:flex;gap:14px;align-items:center;padding:10px 16px;background:#232c37;color:#c9d2dc;font-size:13px}.bar button{font:600 14px Poppins,Arial;background:#f2c200;color:#1d2733;border:0;border-radius:20px;padding:9px 18px;cursor:pointer}'
			. '.doc{max-width:210mm;margin:16px auto;background:#fff;padding:14mm;box-shadow:0 2px 10px rgba(0,0,0,.12)}'
			. '.hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:5mm}.brand{display:flex;gap:10px;align-items:center}.brand strong{display:block;font-size:12pt}.brand span{font-size:8pt;color:#5d6875}.kind{font-weight:700;color:#1f8a3b;text-transform:uppercase;letter-spacing:.06em;font-size:9pt}'
			. '.tt{width:100%;border-collapse:collapse;border:1.5px solid #222;margin-bottom:3mm}.tt td{border:1.5px solid #222;padding:2.5mm 3mm}.band{width:5mm;padding:0!important;background:#1f8a3b}.ttl{font-size:15pt;font-weight:700;text-align:center;background:#e9f5ec}.lvl{width:32mm;text-align:center;font-weight:700;color:#1f8a3b}.lvl small{display:block;color:#5d6875;font-weight:500}.meta{font-size:9pt}.meta span{display:inline-block;margin-right:7mm}.meta b{margin-right:2mm}'
			. '.dl{margin:0 0 4mm;color:#b42318}section{margin:6mm 0 0}h2{font-size:12.5pt;margin:0 0 2.5mm;display:flex;align-items:center;gap:2.5mm;border-bottom:2px solid #1f8a3b;padding-bottom:1.5mm}h2 i{font-style:normal;background:#1f8a3b;color:#fff;width:7mm;height:7mm;border-radius:50%;display:inline-grid;place-items:center;font-size:10pt}h3{font-size:10.5pt;margin:4mm 0 1mm}'
			. '.mission{background:#12306b;color:#fff;border-radius:2mm;padding:3.5mm 4.5mm}.obj{margin:0;padding-left:5mm}.obj li{margin:1mm 0}.kv{display:grid;grid-template-columns:42mm 1fr;gap:1.5mm 4mm;margin:0}.kv dt{font-weight:600}.kv dd{margin:0}'
			. '.hint{color:#5d6875;font-size:8.8pt;margin:1.5mm 0}.docb{border:1px solid #c9cfd6;border-left:4px solid #1c64b0;border-radius:1.5mm;padding:2.5mm 3.5mm;margin:2.5mm 0;break-inside:avoid}.dh{display:flex;justify-content:space-between;gap:4mm}.ref{font-family:"DejaVu Sans Mono",monospace;font-size:9pt;background:#eef1f4;padding:0 2mm;border-radius:1mm}.dm{margin:1mm 0 2mm;font-size:9pt}'
			. 'table.lines{width:100%;border-collapse:collapse;font-size:9pt}table.lines th{text-align:left;background:#eef1f4;padding:1.5mm 2mm;border-bottom:1px solid #c9cfd6}table.lines td{padding:1.3mm 2mm;border-bottom:1px solid #e3e6ea}.r{text-align:right}.mono,code{font-family:"DejaVu Sans Mono",monospace}code{background:#eef1f4;padding:0 1mm;border-radius:1mm}.crit .tot td{font-weight:700;border-top:2px solid #1d2733}'
			. '.tasks{list-style:none;counter-reset:t;padding:0;margin:0}.tasks li{display:flex;gap:3.5mm;padding:2.5mm 0;border-bottom:1px dashed #c9cfd6;break-inside:avoid}.tasks .box{flex:none;width:5.5mm;height:5.5mm;border:1.5px solid #1d2733;border-radius:1mm;margin-top:.5mm}.tasks p{margin:.8mm 0}.tasks .tx{font-size:8.8pt;color:#1c64b0}'
			. '.notes{border:1.5px dashed #f2c200;background:#fffbea;padding:3mm 4mm;border-radius:2mm}.imp{color:#e3001b;border-color:#e3001b}.imp-l{color:#8a0010;margin:0;padding-left:5mm}.keep{break-inside:avoid}footer{margin-top:8mm;padding-top:2mm;border-top:1px solid #c9cfd6;font-size:7.5pt;color:#8a939d}'
			. '@media print{body{background:#fff}.bar{display:none}.doc{margin:0;padding:0;box-shadow:none;max-width:none}}';
	}
}
