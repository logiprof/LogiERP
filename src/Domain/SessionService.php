<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sessions : copie de travail d'un scénario, propre à un apprenant.
 * La référence (session 0) et le scénario ne sont jamais modifiés par l'apprenant.
 */
final class SessionService {

	/** Tables portant session_id, dans l'ordre de suppression. */
	private const SESSION_TABLES = array( 'stock', 'session_bin_state', 'session_event_log', 'action_log', 'number_range', 'purchase_order', 'sales_order', 'outbound_delivery', 'transfer_order', 'production_order', 'inventory_doc', 'material_doc', 'return_doc', 'inspection_lot', 'shipment', 'packaging_account', 'packaging_move', 'handling_unit', 'intervention', 'change_log', 'output_msg', 'prod_confirmation', 'container', 'container_move' );

	/** Tables d'en-tête et leurs lignes. */
	private const CHILDREN = array(
		'purchase_order'    => array( 'purchase_order_item', 'po_id' ),
		'shipment'          => array( 'shipment_stop', 'shipment_id' ),
		'sales_order'       => array( 'sales_order_item', 'so_id' ),
		'outbound_delivery' => array( 'outbound_delivery_item', 'delivery_id' ),
		'transfer_order'    => array( 'transfer_order_item', 'to_id' ),
		'production_order'  => array( 'production_component', 'prod_id' ),
		'inventory_doc'     => array( 'inventory_item', 'inv_id' ),
		'material_doc'      => array( 'material_doc_item', 'mdoc_id' ),
		'return_doc'        => array( 'return_item', 'ret_id' ),
		'handling_unit'     => array( 'handling_unit_item', 'hu_id' ),
	);

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'session' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function scenario( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'scenario' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND deleted_at IS NULL", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/**
	 * Contexte d'une session si l'utilisateur y a accès (propriétaire ; enseignant de la classe en lecture).
	 */
	public static function context_for( int $session_id, int $user_id, bool $write = false ): ?Ctx {
		$s = self::get( $session_id );
		if ( ! $s ) {
			return null;
		}
		$own = Teams::is_participant( $s, $user_id );
		if ( ! $own ) {
			if ( $write || ! self::teacher_can_view( $user_id, $s ) ) {
				return null;
			}
		}
		$sc = self::scenario( (int) $s['scenario_id'] );
		if ( ! $sc ) {
			return null;
		}
		$ctx = new Ctx( $s, $sc );
		// Durée maximale ou date de fermeture dépassée : remise automatique, puis contexte relu.
		$deadline = self::deadline( $s );
		if ( 'EN_COURS' === $s['status'] && null !== $deadline && $deadline <= Db::now() ) {
			try {
				self::submit( $ctx );
			} catch ( DomainError $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- déjà remise par une requête concurrente.
			}
			$s = self::get( $session_id );
			return $s ? new Ctx( $s, $sc ) : null;
		}
		return $ctx;
	}

	/** @return array{0:int,1:int,2:?string} [durée maximale (min), affichage des résultats, fermeture] de l'assignation. */
	public static function assignment_rules( array $session ): array {
		global $wpdb;
		if ( ! (int) $session['assignment_id'] ) {
			return array( 0, 1, null );
		}
		$a   = Db::t( 'assignment' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT time_limit_min, show_results, closes_at FROM {$a} WHERE id = %d", (int) $session['assignment_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? array( (int) $row['time_limit_min'], (int) $row['show_results'], $row['closes_at'] ) : array( 0, 1, null );
	}

	/** Échéance UTC d'une session (durée maximale ou fermeture de l'assignation), null si aucune. */
	public static function deadline( array $session ): ?string {
		if ( ! (int) $session['assignment_id'] ) {
			return null;
		}
		[ $limit, , $closes ] = self::assignment_rules( $session );
		$cands = array();
		if ( $limit > 0 ) {
			$cands[] = gmdate( 'Y-m-d H:i:s', strtotime( $session['started_at'] . ' UTC' ) + $limit * 60 );
		}
		if ( $closes ) {
			$cands[] = (string) $closes;
		}
		return $cands ? min( $cands ) : null;
	}

	public static function teacher_can_view( int $user_id, array $session ): bool {
		if ( user_can( $user_id, Capabilities::VIEW_ALL ) ) {
			return true;
		}
		if ( ! user_can( $user_id, Capabilities::VIEW_CLASS ) ) {
			return false;
		}
		if ( (int) $session['assignment_id'] === 0 ) {
			$sc = self::scenario( (int) $session['scenario_id'] );
			return $sc && (int) $sc['owner_id'] === $user_id;
		}
		global $wpdb;
		$a = Db::t( 'assignment' );
		$c = Db::t( 'class' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT a.created_by, c.teacher_id FROM {$a} a LEFT JOIN {$c} c ON c.id = a.class_id WHERE a.id = %d", (int) $session['assignment_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Enseignant de la classe, ou auteur de l'assignation (classe supprimée ou assignation retirée).
		return $row && ( (int) $row['teacher_id'] === $user_id || (int) $row['created_by'] === $user_id );
	}

	/**
	 * Assignations d'un apprenant : celles de ses classes, sauf si l'assignation a une liste dont il ne fait pas partie.
	 * Chaque ligne porte le mode de travail, l'équipe et l'état du code personnel.
	 */
	public static function assignments_for( int $user_id ): array {
		global $wpdb;
		$a    = Db::t( 'assignment' );
		$cm   = Db::t( 'class_member' );
		$c    = Db::t( 'class' );
		$s    = Db::t( 'scenario' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.*, s.title, s.level, s.duration_min, s.objectives, c.name AS class_name FROM {$a} a JOIN {$cm} m ON m.class_id = a.class_id JOIN {$c} c ON c.id = a.class_id JOIN {$s} s ON s.id = a.scenario_id AND s.deleted_at IS NULL AND s.status = 'PUBLISHED' WHERE m.user_id = %d ORDER BY a.id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$r['member'] = null;
			$r['team']   = null;
			if ( Teams::ROSTER_NONE !== (int) $r['roster'] ) {
				Teams::sync( $r );
				$m = Teams::member( (int) $r['id'], $user_id );
				if ( ! $m ) {
					continue; // Mission attribuée à d'autres apprenants ou groupes de la classe.
				}
				$r['member'] = $m;
				if ( 'GROUP' === $r['work_mode'] && (int) $m['team_id'] ) {
					$t         = Teams::team( (int) $m['team_id'] );
					$r['team'] = array(
						'name'    => $t ? (string) $t['name'] : 'Groupe',
						'members' => array_map( array( Teams::class, 'short_name' ), array_values( array_diff( Teams::team_member_ids( (int) $m['team_id'] ), array( $user_id ) ) ) ),
					);
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	public static function sessions_of( int $user_id ): array {
		global $wpdb;
		$t = Db::t( 'session' );
		$s = Db::t( 'scenario' );
		$a = Db::t( 'assignment' );
		$m = Db::t( 'assignment_member' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT x.id, x.assignment_id, x.scenario_id, x.team_id, x.status, x.is_test, x.score, x.max_score, x.grade, x.published, x.started_at, x.submitted_at, s.title, COALESCE(a.show_results, 1) AS show_results FROM {$t} x JOIN {$s} s ON s.id = x.scenario_id LEFT JOIN {$a} a ON a.id = x.assignment_id WHERE (x.user_id = %d AND x.team_id = 0) OR x.team_id IN (SELECT m.team_id FROM {$m} m WHERE m.user_id = %d AND m.team_id > 0 AND m.assignment_id = x.assignment_id) ORDER BY x.id DESC", $user_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Démarre une session depuis une assignation (apprenant) ou en test (enseignant, $assignment_id = 0).
	 * Travail de groupe : une seule session par équipe ; le premier membre la crée, les autres la rejoignent.
	 * Code personnel : exigé au premier accès de chaque apprenant, valable uniquement pour son titulaire.
	 */
	public static function start( int $user_id, int $assignment_id, int $scenario_id = 0, string $code = '' ): int {
		global $wpdb;
		if ( $assignment_id > 0 && ! RateLimiter::hit( 'session', $user_id ) ) {
			throw new DomainError( 'Trop de sessions lancées en peu de temps. Réessayez dans une heure.' );
		}
		$is_test = 0;
		$team_id = 0;
		if ( $assignment_id > 0 ) {
			$a  = Db::t( 'assignment' );
			$cm = Db::t( 'class_member' );
			$as = $wpdb->get_row( $wpdb->prepare( "SELECT a.* FROM {$a} a JOIN {$cm} m ON m.class_id = a.class_id AND m.user_id = %d WHERE a.id = %d", $user_id, $assignment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $as ) {
				throw new DomainError( 'Ce scénario ne vous est pas assigné.' );
			}
			$member = null;
			if ( Teams::ROSTER_NONE !== (int) $as['roster'] ) {
				Teams::sync( $as );
				$member = Teams::member( $assignment_id, $user_id );
				if ( ! $member ) {
					throw new DomainError( 'Ce scénario ne vous est pas assigné.' );
				}
				if ( 'GROUP' === $as['work_mode'] ) {
					$team_id = (int) $member['team_id'];
					if ( ! $team_id ) {
						throw new DomainError( 'Vous n\'êtes dans aucun groupe pour cette mission. Demandez à votre enseignant de vous ajouter à un groupe.' );
					}
				}
			}
			$now = Db::now();
			if ( $as['opens_at'] && $as['opens_at'] > $now ) {
				throw new DomainError( 'Ce scénario n\'est pas encore ouvert.' );
			}
			if ( $as['closes_at'] && $as['closes_at'] < $now ) {
				throw new DomainError( 'Ce scénario est fermé.' );
			}
			if ( $member ) {
				Teams::check_code( $as, $member, $code );
			}
			$st = Db::t( 'session' );
			if ( $team_id ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE assignment_id = %d AND team_id = %d", $assignment_id, $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$open  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE assignment_id = %d AND team_id = %d AND status = 'EN_COURS' ORDER BY id DESC LIMIT 1", $assignment_id, $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE assignment_id = %d AND user_id = %d AND team_id = 0", $assignment_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$open  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE assignment_id = %d AND user_id = %d AND team_id = 0 AND status = 'EN_COURS' ORDER BY id DESC LIMIT 1", $assignment_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $open ) {
				return $open;
			}
			if ( (int) $as['max_attempts'] > 0 && $count >= (int) $as['max_attempts'] ) {
				throw new DomainError( $team_id ? 'Votre groupe a utilisé toutes ses tentatives pour ce scénario.' : 'Vous avez utilisé toutes vos tentatives pour ce scénario.' );
			}
			$scenario_id = (int) $as['scenario_id'];
		} else {
			if ( ! Capabilities::is_teacher( $user_id ) ) {
				throw new DomainError( 'Accès refusé.' );
			}
			$is_test = 1;
		}
		$sc = self::scenario( $scenario_id );
		if ( ! $sc ) {
			throw new DomainError( 'Scénario introuvable.' );
		}
		if ( 1 !== $is_test && 'PUBLISHED' !== $sc['status'] ) {
			throw new DomainError( 'Ce scénario n\'est pas publié.' );
		}
		if ( 'done' !== get_option( 'logierp_seed_state' ) ) {
			throw new DomainError( 'Le jeu de données de base n\'est pas encore généré. Contactez l\'administrateur.' );
		}

		$create = static function () use ( $user_id, $assignment_id, $sc, $is_test, $team_id ) {
			$sid = Db::insert(
				'session',
				array(
					'assignment_id'    => $assignment_id,
					'scenario_id'      => (int) $sc['id'],
					'scenario_version' => (int) $sc['version'],
					'user_id'          => $user_id,
					'team_id'          => $team_id,
					'status'           => 'EN_COURS',
					'is_test'          => $is_test,
					'location_mode'    => 'SIMPLE' === $sc['location_mode'] ? 'SIMPLE' : 'DETAILED',
					'sim_date'         => $sc['sim_date'],
					'seed'             => wp_rand( 1, 2000000000 ),
					'doc_map'          => '{}',
					'started_at'       => Db::now(),
					'last_activity'    => Db::now(),
				)
			);
			self::copy_initial_state( $sid, $sc );
			$session = self::get( $sid );
			$ctx     = new Ctx( $session, $sc );
			self::create_scenario_documents( $ctx );
			Quality::expire( $ctx );
			Events::fire_trigger( $ctx, 'start' );
			return $sid;
		};
		if ( ! $team_id ) {
			return Db::transaction( $create );
		}
		// Deux membres qui commencent en même temps : un verrou garantit une seule session pour l'équipe.
		$lock = 'logierp_team_' . $team_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock ) ) ) {
			throw new DomainError( 'Un membre de votre groupe ouvre la mission en ce moment. Réessayez dans quelques secondes.' );
		}
		try {
			$st   = Db::t( 'session' );
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE assignment_id = %d AND team_id = %d AND status = 'EN_COURS' ORDER BY id DESC LIMIT 1", $assignment_id, $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $open ?: Db::transaction( $create );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function copy_initial_state( int $sid, array $sc ): void {
		global $wpdb;
		$st     = Db::t( 'stock' );
		$simple = 'SIMPLE' === $sc['location_mode'];
		if ( 'EMPTY' !== $sc['initial_stock_mode'] ) {
			if ( $simple ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$st} (session_id, material_id, storage_location_id, bin_id, batch, bbd, status, qty, gr_date) SELECT %d, material_id, storage_location_id, 0, batch, MIN(bbd), status, SUM(qty), MIN(gr_date) FROM {$st} WHERE session_id = 0 GROUP BY material_id, storage_location_id, batch, status", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$st} (session_id, material_id, storage_location_id, bin_id, batch, bbd, status, qty, gr_date) SELECT %d, material_id, storage_location_id, bin_id, batch, bbd, status, qty, gr_date FROM {$st} WHERE session_id = 0", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			// Commandes ouvertes du fond de décor.
			self::copy_background_docs( $sid );
			// Conteneurs loués (articles X) : copiés au premier accès (Containers::ensure), avec leur historique de départ.
			NumberRange::copy( 0, $sid );
		}
		// Stock propre au scénario (ajouté à la base, ou seul stock si EMPTY/CUSTOM).
		$is = Db::t( 'scenario_initial_stock' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$is} WHERE scenario_id = %d", (int) $sc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$bin = $simple ? 0 : (int) $r['bin_id'];
			$loc = (int) $r['storage_location_id'];
			if ( ! $loc && $r['bin_id'] ) {
				$b   = Catalog::bin( (int) $r['bin_id'] );
				$loc = $b ? (int) $b['storage_location_id'] : 0;
			}
			if ( ! $loc ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$st} (session_id, material_id, storage_location_id, bin_id, batch, bbd, status, qty, gr_date) VALUES (%d, %d, %d, %d, %s, %s, %s, %f, %s) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)", $sid, (int) $r['material_id'], $loc, $bin, (string) $r['batch'], $r['bbd'] ?: null, (string) $r['status'], (float) $r['qty'], $sc['sim_date'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$st} SET bbd = NULL WHERE session_id = %d AND bbd = '0000-00-00'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function copy_background_docs( int $sid ): void {
		global $wpdb;
		foreach ( array( 'purchase_order' => 'po_id', 'sales_order' => 'so_id' ) as $head => $fk ) {
			$ht    = Db::t( $head );
			$it    = Db::t( self::CHILDREN[ $head ][0] );
			$heads = $wpdb->get_results( "SELECT * FROM {$ht} WHERE session_id = 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $heads as $h ) {
				$old_id = (int) $h['id'];
				unset( $h['id'] );
				$h['session_id'] = $sid;
				$new_id          = Db::insert( $head, $h );
				$items           = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it} WHERE {$fk} = %d", $old_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $items as $i ) {
					unset( $i['id'] );
					$i[ $fk ] = $new_id;
					$i        = array_filter( $i, static fn( $v ) => null !== $v );
					Db::insert( self::CHILDREN[ $head ][0], $i );
				}
			}
		}
	}

	/** Documents préparés par l'enseignant : commandes fournisseurs, clients, ordres de fabrication. */
	private static function create_scenario_documents( Ctx $ctx ): void {
		global $wpdb;
		$dt   = Db::t( 'scenario_document' );
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$dt} WHERE scenario_id = %d ORDER BY id", (int) $ctx->scenario['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$map  = $ctx->doc_map();
		foreach ( (array) $docs as $d ) {
			$items = json_decode( (string) $d['items'], true );
			$items = is_array( $items ) ? $items : array();
			$date  = gmdate( 'Y-m-d', strtotime( $ctx->date() . ' ' . sprintf( '%+d', (int) $d['day_offset'] ) . ' days' ) );
			if ( 'PO' === $d['doc_type'] ) {
				$no = Purchasing::create_from_scenario( $ctx, $d, $items, $date );
			} elseif ( 'SO' === $d['doc_type'] ) {
				$no = Sales::create_from_scenario( $ctx, $d, $items, $date );
			} elseif ( 'PROD' === $d['doc_type'] ) {
				$no = Production::create_from_scenario( $ctx, $d, $items );
			} else {
				continue;
			}
			$map['refs'][ $d['ref'] ] = array( 'type' => $d['doc_type'], 'no' => $no );
		}
		$ctx->save_doc_map( $map );
	}

	/** Journal des actions de l'apprenant (base de la correction). */
	public static function log_action( Ctx $ctx, string $tcode, string $action, array $payload, bool $ok, string $message, string $doc_no = '' ): void {
		Db::insert(
			'action_log',
			array(
				'session_id'       => $ctx->id,
				'user_id'          => get_current_user_id(),
				'transaction_code' => mb_substr( $tcode, 0, 20 ),
				'action'           => mb_substr( $action, 0, 40 ),
				'payload'          => mb_substr( (string) wp_json_encode( $payload ), 0, 20000 ),
				'result'           => $ok ? 'ok' : 'error',
				'message'          => mb_substr( $message, 0, 255 ),
				'doc_no'           => mb_substr( $doc_no, 0, 20 ),
				'created_at'       => Db::now(),
			)
		);
		Db::update( 'session', array( 'last_activity' => Db::now() ), array( 'id' => $ctx->id ) );
		if ( $ok ) {
			Events::fire_trigger( $ctx, 'after_actions' );
		}
	}

	/**
	 * Trace informative (lecture de la présentation, consultation d'un document) : visible dans le journal,
	 * mais n'est comptée ni comme action réussie (déclencheurs, consignes) ni comme erreur.
	 */
	public static function log_info( Ctx $ctx, string $tcode, string $action, string $message, string $doc_no = '' ): void {
		Db::insert(
			'action_log',
			array(
				'session_id'       => $ctx->id,
				'user_id'          => get_current_user_id(),
				'transaction_code' => mb_substr( $tcode, 0, 20 ),
				'action'           => mb_substr( $action, 0, 40 ),
				'payload'          => '{}',
				'result'           => 'info',
				'message'          => mb_substr( $message, 0, 255 ),
				'doc_no'           => mb_substr( $doc_no, 0, 20 ),
				'created_at'       => Db::now(),
			)
		);
		Db::update( 'session', array( 'last_activity' => Db::now() ), array( 'id' => $ctx->id ) );
	}

	/** L'apprenant·e a-t-il déjà lu la présentation de l'entreprise dans cette session ? */
	public static function company_seen( int $session_id ): bool {
		global $wpdb;
		$t = Db::t( 'action_log' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE session_id = %d AND action = 'company_read' LIMIT 1", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function submit( Ctx $ctx ): void {
		if ( 'EN_COURS' !== $ctx->session['status'] ) {
			throw new DomainError( 'Cette session est déjà soumise.' );
		}
		Db::update( 'session', array( 'status' => 'SOUMISE', 'submitted_at' => Db::now() ), array( 'id' => $ctx->id ) );
		$ctx->session['status'] = 'SOUMISE';
		\LogiERP\Scenario\Grader::grade( $ctx );
	}

	public static function delete_session( int $sid ): void {
		global $wpdb;
		Photos::delete_for_session( $sid );
		Db::transaction(
			static function () use ( $wpdb, $sid ) {
				foreach ( self::CHILDREN as $head => [ $child, $fk ] ) {
					$ht = Db::t( $head );
					$ct = Db::t( $child );
					$wpdb->query( $wpdb->prepare( "DELETE c FROM {$ct} c JOIN {$ht} h ON h.id = c.{$fk} WHERE h.session_id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				foreach ( self::SESSION_TABLES as $t ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( $t ) . ' WHERE session_id = %d', $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
				$wpdb->delete( Db::t( 'session' ), array( 'id' => $sid ) );
			}
		);
	}
}
