<?php
declare(strict_types=1);

namespace LogiERP\Security;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journal d'audit (administration et sécurité). IP tronquée (dernier octet masqué).
 */
final class Audit {

	public static function log( string $action, string $object_type = '', int $object_id = 0, array $details = array() ): void {
		try {
			Db::insert(
				'audit_log',
				array(
					'user_id'     => get_current_user_id(),
					'ip'          => self::anonymized_ip(),
					'action'      => mb_substr( $action, 0, 60 ),
					'object_type' => mb_substr( $object_type, 0, 30 ),
					'object_id'   => $object_id,
					'details'     => $details ? (string) wp_json_encode( $details ) : null,
					'created_at'  => Db::now(),
				)
			);
		} catch ( \Throwable $e ) {
			\LogiERP\Support\Logger::error( 'Audit : ' . $e->getMessage() );
		}
	}

	public static function anonymized_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', $ip );
			return implode( ':', array_slice( $parts, 0, 3 ) ) . '::';
		}
		return '';
	}

	public static function purge_old(): void {
		global $wpdb;
		$months = max( 1, (int) get_option( 'logierp_audit_months', 12 ) );
		$t      = Db::t( 'audit_log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
