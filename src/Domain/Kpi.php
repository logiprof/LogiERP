<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indicateurs de fin de mission, comme ceux d'un chef d'équipe d'entrepôt.
 * Calculés à partir du journal des actions et des documents de la session (lecture seule).
 */
final class Kpi {

	/** Pause au-delà de laquelle le temps n'est plus compté comme actif (minutes). */
	private const IDLE_CAP = 10;

	public static function session( Ctx $ctx ): array {
		global $wpdb;
		$sid = $ctx->id;
		$al  = Db::t( 'action_log' );
		$log = (array) $wpdb->get_results( $wpdb->prepare( "SELECT transaction_code, result, created_at FROM {$al} WHERE session_id = %d ORDER BY id", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Temps : durée totale et temps actif (pauses de plus de 10 minutes exclues).
		$start  = strtotime( (string) $ctx->session['started_at'] . ' UTC' );
		$end    = $ctx->session['submitted_at'] ? strtotime( $ctx->session['submitted_at'] . ' UTC' ) : ( $log ? strtotime( end( $log )['created_at'] . ' UTC' ) : $start );
		$active = 0;
		$prev   = $start;
		$tx     = array();
		$ok     = 0;
		$err    = 0;
		$bins   = array();
		foreach ( $log as $l ) {
			$t       = strtotime( $l['created_at'] . ' UTC' );
			$active += min( max( 0, $t - $prev ), self::IDLE_CAP * 60 );
			$prev    = $t;
			if ( 'ok' === $l['result'] ) {
				++$ok;
			} else {
				++$err;
			}
			$code = (string) $l['transaction_code'];
			if ( '' !== $code ) {
				$tx[ $code ] = ( $tx[ $code ] ?? 0 ) + 1;
			}
			$b          = (int) floor( max( 0, $t - $start ) / 300 );
			$bins[ $b ] = $bins[ $b ] ?? array( 0, 0 );
			++$bins[ $b ][ 'ok' === $l['result'] ? 0 : 1 ];
		}
		arsort( $tx );
		$hours = max( 1 / 60, $active / 3600 );

		// Préparation : lignes d'ordres de prélèvement confirmées.
		$to   = Db::t( 'transfer_order' );
		$toi  = Db::t( 'transfer_order_item' );
		$pick = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(i.qty_confirmed > 0) AS picked, SUM(i.qty_confirmed + 0.0005 < i.qty) AS short, SUM(i.strategy_ok = 0) AS off FROM {$toi} i JOIN {$to} t ON t.id = i.to_id WHERE t.session_id = %d AND t.type = 'PICK' AND t.status = 'CONFIRME'", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$put  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$toi} i JOIN {$to} t ON t.id = i.to_id WHERE t.session_id = %d AND t.type IN ('PUTAWAY','REPL','MOVE') AND t.status = 'CONFIRME' AND i.qty_confirmed > 0", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Commandes clients du scénario (et ajoutées en direct) : service et priorités.
		$so     = Db::t( 'sales_order' );
		$dl     = Db::t( 'outbound_delivery' );
		$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.so_no, o.scenario_ref, o.priority, o.status, o.created_at, MAX(d.issued_at) AS issued FROM {$so} o LEFT JOIN {$dl} d ON d.so_id = o.id AND d.session_id = o.session_id AND d.issued_at IS NOT NULL WHERE o.session_id = %d AND o.scenario_ref <> '' GROUP BY o.id ORDER BY o.id", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$served  = 0;
		$partial = 0;
		$urgent  = array();
		$late    = 0;
		$open    = 0;
		foreach ( $orders as $o ) {
			if ( 'ANNULEE' === $o['status'] ) {
				continue;
			}
			++$open;
			if ( 'LIVREE' === $o['status'] ) {
				++$served;
			} elseif ( 'PARTIELLEMENT_LIVREE' === $o['status'] ) {
				++$partial;
			}
			if ( 'A' === $o['priority'] ) {
				$urgent[] = $o;
			}
		}
		// Priorité : commande B ou C expédiée alors qu'une commande A attendait déjà.
		$waits = array();
		foreach ( $urgent as $u ) {
			$from = strtotime( $u['created_at'] . ' UTC' );
			$to_t = $u['issued'] ? strtotime( $u['issued'] . ' UTC' ) : $end;
			foreach ( $orders as $o ) {
				if ( 'A' !== $o['priority'] && $o['issued'] ) {
					$t = strtotime( $o['issued'] . ' UTC' );
					if ( $t > $from && $t < $to_t ) {
						++$late;
					}
				}
			}
			if ( $u['issued'] ) {
				$waits[] = max( 0, (int) round( ( strtotime( $u['issued'] . ' UTC' ) - $from ) / 60 ) );
			}
		}

		// Réception : lignes reçues, réserves et CMR.
		$po   = Db::t( 'purchase_order' );
		$poi  = Db::t( 'purchase_order_item' );
		$rec  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(i.qty_received > 0) AS got FROM {$poi} i JOIN {$po} p ON p.id = i.po_id WHERE p.session_id = %d AND p.scenario_ref <> ''", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$md   = Db::t( 'material_doc' );
		$mdi  = Db::t( 'material_doc_item' );
		$resv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mdi} i JOIN {$md} d ON d.id = i.mdoc_id WHERE d.session_id = %d AND ((i.movement_type = '101' AND (i.reason <> '' OR i.stock_status <> 'LIBRE')) OR i.movement_type = '122')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cmr  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$po} WHERE session_id = %d AND cmr_signed_at IS NOT NULL", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inv  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'inventory_doc' ) . " WHERE session_id = %d AND status = 'COMPTABILISE'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$gaps = count( array_filter( (array) ( $ctx->doc_map()['gaps'] ?? array() ), static fn( $g ) => abs( (float) $g ) > 0.0005 ) );

		$picked = (int) ( $pick['picked'] ?? 0 );
		return array(
			'minutes'      => (int) round( max( 0, $end - $start ) / 60 ),
			'active'       => (int) round( $active / 60 ),
			'ok'           => $ok,
			'errors'       => $err,
			'error_rate'   => $ok + $err ? round( 100 * $err / ( $ok + $err ), 1 ) : 0.0,
			'tx'           => array_slice( $tx, 0, 8, true ),
			'timeline'     => $bins,
			'pick_lines'   => $picked,
			'pick_short'   => (int) ( $pick['short'] ?? 0 ),
			'pick_off'     => (int) ( $pick['off'] ?? 0 ),
			'lines_hour'   => $picked && $active >= 180 ? round( $picked / $hours, 1 ) : 0.0, // Moins de 3 min actives : non significatif.
			'putaway'      => $put,
			'orders'       => $open,
			'served'       => $served,
			'partial'      => $partial,
			'urgent'       => count( $urgent ),
			'urgent_done'  => count( $waits ),
			'urgent_wait'  => $waits ? (int) round( array_sum( $waits ) / count( $waits ) ) : null,
			'priority_ko'  => $late,
			'rec_lines'    => (int) ( $rec['n'] ?? 0 ),
			'rec_done'     => (int) ( $rec['got'] ?? 0 ),
			'reserves'     => $resv,
			'cmr'          => $cmr,
			'inventories'  => $inv,
			'gaps'         => $gaps,
			'interventions' => Interventions::of_session( $sid ),
		);
	}
}
