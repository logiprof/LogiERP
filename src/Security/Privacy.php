<?php
declare(strict_types=1);

namespace LogiERP\Security;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intégration aux outils WordPress d'export / effacement des données personnelles (nLPD / RGPD).
 */
final class Privacy {

	public static function register_exporter( array $exporters ): array {
		$exporters['logierp'] = array(
			'exporter_friendly_name' => __( 'LogiERP – sessions et résultats', 'logierp' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['logierp'] = array(
			'eraser_friendly_name' => __( 'LogiERP – sessions et résultats', 'logierp' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$t    = Db::t( 'session' );
			$s    = Db::t( 'scenario' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.id, x.status, x.score, x.max_score, x.grade, x.started_at, x.submitted_at, s.title FROM {$t} x LEFT JOIN {$s} s ON s.id = x.scenario_id WHERE x.user_id = %d OR x.team_id IN (SELECT team_id FROM " . Db::t( 'assignment_member' ) . " WHERE user_id = %d AND team_id > 0) ORDER BY x.id", $user->ID, $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $r ) {
				$data[] = array(
					'group_id'    => 'logierp-sessions',
					'group_label' => __( 'Sessions LogiERP', 'logierp' ),
					'item_id'     => 'logierp-session-' . (int) $r['id'],
					'data'        => array(
						array( 'name' => __( 'Scénario', 'logierp' ), 'value' => (string) $r['title'] ),
						array( 'name' => __( 'Statut', 'logierp' ), 'value' => (string) $r['status'] ),
						array( 'name' => __( 'Note', 'logierp' ), 'value' => (string) $r['grade'] ),
						array( 'name' => __( 'Début', 'logierp' ), 'value' => (string) $r['started_at'] ),
						array( 'name' => __( 'Photos de non-conformité', 'logierp' ), 'value' => (string) \LogiERP\Domain\Photos::count( (int) $r['id'] ) ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			global $wpdb;
			$t   = Db::t( 'session' );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, team_id FROM {$t} WHERE user_id = %d", $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				// Session de groupe : le travail des autres membres est conservé, la session passe à un autre membre.
				$others = (int) $r['team_id'] ? array_values( array_diff( \LogiERP\Domain\Teams::team_member_ids( (int) $r['team_id'] ), array( (int) $user->ID ) ) ) : array();
				if ( $others ) {
					Db::update( 'session', array( 'user_id' => $others[0] ), array( 'id' => (int) $r['id'] ) );
				} else {
					\LogiERP\Domain\SessionService::delete_session( (int) $r['id'] );
				}
				$removed = true;
			}
			// Saisies faites dans des sessions de groupe conservées : anonymisées.
			$al = Db::t( 'action_log' );
			$n  = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$al} SET user_id = 0 WHERE user_id = %d", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed = $removed || $n > 0;
			foreach ( array( 'class_member', 'class_group_member', 'assignment_member' ) as $tb ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( $tb ) . ' WHERE user_id = %d', $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user->ID, $wpdb->esc_like( 'logierp_ev_' ) . '%' ) );
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
