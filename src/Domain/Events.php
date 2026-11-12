<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Événements de scénario : déclenchés au démarrage, après N actions, ou en direct par l'enseignant.
 */
final class Events {

	public const TYPES = array(
		'message'              => 'Message à l\'apprenant',
		'unannounced_delivery' => 'Livraison non annoncée',
		'block_bin'            => 'Emplacement bloqué (panne, rack endommagé)',
		'urgent_order'         => 'Commande client urgente',
		'cancel_order'         => 'Annulation d\'une commande client',
		'damaged_delivery'     => 'Colis endommagé sur une livraison',
		'inventory_gap'        => 'Écart d\'inventaire caché',
		'supplier_late'        => 'Fournisseur en retard',
	);

	public const TRIGGERS = array(
		'start'         => 'Au démarrage',
		'after_actions' => 'Après N actions réussies',
		'manual'        => 'Manuellement (en direct)',
	);

	public static function events_of( int $scenario_id ): array {
		global $wpdb;
		$t = Db::t( 'scenario_event' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY id", $scenario_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function fired_ids( int $session_id ): array {
		global $wpdb;
		$t = Db::t( 'session_event_log' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$t} WHERE session_id = %d", $session_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function fire_trigger( Ctx $ctx, string $trigger ): void {
		global $wpdb;
		$fired = self::fired_ids( $ctx->id );
		$count = null;
		foreach ( self::events_of( (int) $ctx->scenario['id'] ) as $e ) {
			if ( $e['trigger_type'] !== $trigger || in_array( (int) $e['id'], $fired, true ) ) {
				continue;
			}
			if ( 'after_actions' === $trigger ) {
				if ( null === $count ) {
					$t     = Db::t( 'action_log' );
					$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND result = 'ok'", $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				if ( $count < (int) $e['trigger_value'] ) {
					continue;
				}
			}
			self::fire( $ctx, $e );
		}
	}

	/** Applique l'effet d'un événement et l'inscrit au journal de la session. */
	public static function fire( Ctx $ctx, array $e ): void {
		$p   = json_decode( (string) $e['params'], true );
		$p   = is_array( $p ) ? $p : array();
		$msg = (string) $e['message'];
		try {
			switch ( $e['type'] ) {
				case 'unannounced_delivery':
					$doc = array( 'ref' => 'EVT' . $e['id'], 'partner_id' => (int) ( $p['supplier_id'] ?? 0 ), 'note' => 'Livraison non annoncée : le chauffeur attend au quai.' );
					$items = array( array( 'material_id' => (int) ( $p['material_id'] ?? 0 ), 'qty' => (float) ( $p['qty'] ?? 1 ), 'damage' => (string) ( $p['damage'] ?? '' ), 'delivered_qty' => $p['delivered_qty'] ?? '' ) );
					$no    = Purchasing::create_from_scenario( $ctx, $doc, $items, $ctx->date() );
					$msg   = $msg ?: sprintf( 'Un camion se présente au quai sans avis de livraison. Document de référence : commande %s.', $no );
					$msg  .= ' (Commande ' . $no . ')';
					break;
				case 'block_bin':
					$bin = Catalog::bin_by_code( (string) ( $p['bin_code'] ?? '' ) );
					if ( $bin ) {
						$t = Db::t( 'session_bin_state' );
						global $wpdb;
						$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (session_id, bin_id, blocked_in, blocked_out, reason) VALUES (%d, %d, 1, %d, %s) ON DUPLICATE KEY UPDATE blocked_in = 1, blocked_out = VALUES(blocked_out), reason = VALUES(reason)", $ctx->id, (int) $bin['id'], empty( $p['out'] ) ? 0 : 1, mb_substr( (string) ( $p['reason'] ?? 'Incident' ), 0, 120 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						Strategies::forget_blocks( $ctx->id );
						$msg = $msg ?: sprintf( 'L\'emplacement %s est bloqué : %s.', $bin['code'], (string) ( $p['reason'] ?? 'incident' ) );
					}
					break;
				case 'urgent_order':
					$doc = array( 'ref' => 'EVT' . $e['id'], 'partner_id' => (int) ( $p['customer_id'] ?? 0 ), 'priority' => 'A', 'note' => 'Commande urgente : départ avec la tournée du jour.' );
					$no  = Sales::create_from_scenario( $ctx, $doc, (array) ( $p['items'] ?? array() ), $ctx->date() );
					$map = $ctx->doc_map();
					$map['refs'][ (string) ( $p['ref'] ?? ( 'EVT' . $e['id'] ) ) ] = array( 'type' => 'SO', 'no' => $no );
					$ctx->save_doc_map( $map );
					$msg = ( $msg ?: 'Commande client urgente reçue, à livrer aujourd\'hui.' ) . ' (Commande ' . $no . ')';
					break;
				case 'cancel_order':
					$ref = (string) ( $p['ref'] ?? '' );
					$no  = $ctx->doc_map()['refs'][ $ref ]['no'] ?? '';
					if ( $no ) {
						try {
							Sales::cancel( $ctx, $no );
							$msg = ( $msg ?: 'Le client annule sa commande.' ) . ' (Commande ' . $no . ')';
						} catch ( DomainError $ex ) {
							$msg = 'Le client voulait annuler la commande ' . $no . ', mais une livraison est déjà en cours.';
						}
					}
					break;
				case 'damaged_delivery':
					$ref = (string) ( $p['ref'] ?? '' );
					$no  = $ctx->doc_map()['refs'][ $ref ]['no'] ?? '';
					$po  = $no ? Purchasing::by_no( $ctx, $no ) : null;
					if ( $po ) {
						global $wpdb;
						$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'purchase_order_item' ) . ' SET damage = %s WHERE po_id = %d ORDER BY line_no LIMIT 1', mb_substr( (string) ( $p['damage'] ?? 'Colis écrasé' ), 0, 60 ), (int) $po['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$msg = ( $msg ?: 'Le chauffeur signale un colis endommagé.' ) . ' (Commande ' . $no . ')';
					}
					break;
				case 'inventory_gap':
					$m   = Catalog::material( (int) ( $p['material_id'] ?? 0 ) );
					$bin = Catalog::bin_by_code( (string) ( $p['bin_code'] ?? '' ) );
					if ( $m ) {
						$map = $ctx->doc_map();
						$loc = $bin ? (int) $bin['storage_location_id'] : (int) ( $p['location_id'] ?? 0 );
						$key = Counting::gap_key( (int) $m['id'], $loc, $ctx->simple() ? 0 : ( $bin ? (int) $bin['id'] : 0 ) );
						$map['gaps'][ $key ] = (float) ( $p['delta'] ?? -1 );
						$ctx->save_doc_map( $map );
					}
					$msg = $msg ?: '';
					break;
				case 'supplier_late':
					$ref = (string) ( $p['ref'] ?? '' );
					$no  = $ctx->doc_map()['refs'][ $ref ]['no'] ?? '';
					$msg = ( $msg ?: 'Le fournisseur annonce un retard de livraison.' ) . ( $no ? ' (Commande ' . $no . ')' : '' );
					break;
			}
		} catch ( \Throwable $ex ) {
			\LogiERP\Support\Logger::error( 'Événement ' . $e['id'] . ' : ' . $ex->getMessage() );
		}
		Db::insert(
			'session_event_log',
			array(
				'session_id' => $ctx->id,
				'event_id'   => (int) $e['id'],
				'type'       => (string) $e['type'],
				'message'    => mb_substr( $msg, 0, 255 ),
				'seen'       => '' === $msg ? 1 : 0,
				'fired_at'   => Db::now(),
			)
		);
	}

	/** Messages non lus pour l'apprenant. */
	public static function unread( Ctx $ctx ): array {
		global $wpdb;
		$t = Db::t( 'session_event_log' );
		if ( (int) ( $ctx->session['team_id'] ?? 0 ) ) {
			// Session de groupe : chaque membre reçoit chaque imprévu une fois (suivi par compte).
			$uid  = get_current_user_id();
			$key  = 'logierp_ev_' . $ctx->id;
			$last = (int) get_user_meta( $uid, $key, true );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, message, fired_at FROM {$t} WHERE session_id = %d AND id > %d AND message <> '' ORDER BY id", $ctx->id, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $rows ) {
				update_user_meta( $uid, $key, (int) end( $rows )['id'] );
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET seen = 1 WHERE session_id = %d", $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $rows;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, message, fired_at FROM {$t} WHERE session_id = %d AND seen = 0 AND message <> '' ORDER BY id", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $rows ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET seen = 1 WHERE session_id = %d", $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $rows;
	}
}
