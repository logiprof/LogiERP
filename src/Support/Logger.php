<?php
declare(strict_types=1);

namespace LogiERP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journal technique : 200 dernières erreurs dans une option non autoloadée.
 * Rien n'est jamais affiché aux utilisateurs.
 */
final class Logger {

	private const OPTION = 'logierp_tech_log';
	private const MAX    = 200;

	public static function error( string $message, array $context = array() ): void {
		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			't' => gmdate( 'Y-m-d H:i:s' ),
			'm' => mb_substr( $message, 0, 500 ),
			'c' => $context ? mb_substr( (string) wp_json_encode( $context ), 0, 1000 ) : '',
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	/** @return array<int,array{t:string,m:string,c:string}> */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
