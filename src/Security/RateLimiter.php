<?php
declare(strict_types=1);

namespace LogiERP\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limitation de débit par utilisateur, fenêtre glissante simple via transients.
 */
final class RateLimiter {

	/** Limites par type : [nombre, secondes]. */
	public const LIMITS = array(
		'write'   => array( 120, MINUTE_IN_SECONDS ),
		'session' => array( 10, HOUR_IN_SECONDS ),
		'import'  => array( 3, HOUR_IN_SECONDS ),
		'join'    => array( 10, HOUR_IN_SECONDS ),
		'code'    => array( 10, 15 * MINUTE_IN_SECONDS ),
		'md_import' => array( 30, HOUR_IN_SECONDS ),
	);

	/** Retourne true si l'action est autorisée (et la compte), false si la limite est atteinte. */
	public static function hit( string $type, int $user_id = 0 ): bool {
		if ( ! isset( self::LIMITS[ $type ] ) ) {
			return true;
		}
		$user_id = $user_id ?: get_current_user_id();
		[ $max, $window ] = self::LIMITS[ $type ];
		$key   = 'lw_rl_' . $type . '_' . $user_id;
		$data  = get_transient( $key );
		$now   = time();
		if ( ! is_array( $data ) || ( $data['start'] ?? 0 ) + $window < $now ) {
			$data = array( 'start' => $now, 'count' => 0 );
		}
		if ( $data['count'] >= $max ) {
			return false;
		}
		++$data['count'];
		set_transient( $key, $data, $window );
		return true;
	}
}
