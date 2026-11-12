<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tranches de numéros par session et type de document (verrou de ligne).
 */
final class NumberRange {

	/** type => [début, longueur] */
	public const RANGES = array(
		'PO'   => array( 4500000000, 10 ),
		'MDOC' => array( 4900000000, 10 ),
		'SO'   => array( 10000, 10 ),
		'DLV'  => array( 80000000, 10 ),
		'TO'   => array( 0, 10 ),
		'PROD' => array( 1000000, 7 ),
		'INV'  => array( 100000000, 10 ),
		'RET'  => array( 60000000, 10 ),
		'QL'   => array( 10000000000, 12 ),
		'SHP'  => array( 1000, 10 ),
		'BATCH' => array( 589000, 10 ),
		'CONF' => array( 20000, 8 ),
		'IBD'  => array( 80500000, 8 ),
	);

	public static function next( int $session_id, string $type ): string {
		global $wpdb;
		if ( ! isset( self::RANGES[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Type de numéro inconnu' );
		}
		$t = Db::t( 'number_range' );
		return Db::transaction(
			static function () use ( $wpdb, $t, $session_id, $type ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, last_no FROM {$t} WHERE session_id = %d AND doc_type = %s FOR UPDATE", $session_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row ) {
					$last = self::RANGES[ $type ][0];
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (session_id, doc_type, last_no) VALUES (%d, %s, %d)", $session_id, $type, $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, last_no FROM {$t} WHERE session_id = %d AND doc_type = %s FOR UPDATE", $session_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				$next = (int) $row['last_no'] + 1;
				$wpdb->update( $t, array( 'last_no' => $next ), array( 'id' => (int) $row['id'] ) );
				return str_pad( (string) $next, self::RANGES[ $type ][1], '0', STR_PAD_LEFT );
			}
		);
	}

	/** Initialise les tranches d'une session à partir de celles d'une autre (copie du fond de décor). */
	public static function copy( int $from_session, int $to_session ): void {
		global $wpdb;
		$t = Db::t( 'number_range' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (session_id, doc_type, last_no) SELECT %d, doc_type, last_no FROM {$t} WHERE session_id = %d", $to_session, $from_session ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
