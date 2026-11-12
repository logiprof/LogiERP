<?php
declare(strict_types=1);

namespace LogiERP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Codes de transaction : codes SAP connus ; codes Z (convention SAP des transactions propres
 * à l'entreprise) pour les écrans spécifiques à LogiERP. Les anciens codes « LW-… » restent reconnus.
 */
final class Tcodes {

	public const RENAMED = array(
		'LW-ENTR'    => 'ZENTR',
		'LW-HOME'    => 'SMEN',
		'LW-JOURNAL' => 'SLG1',
		'LW-MM03'    => 'MM03',
		'LW-XK03'    => 'XK03',
		'LW-XD03'    => 'XD03',
		'LW-ME21'    => 'ME21N',
		'LW-ME23'    => 'ME23N',
		'LW-ME2M'    => 'ME2M',
		'LW-MIGO'    => 'MIGO',
		'LW-CMR'     => 'ZCMR',
		'LW-ETIQ'    => 'HU03',
		'LW-MMBE'    => 'MMBE',
		'LW-MB52'    => 'MB52',
		'LW-MB51'    => 'MB51',
		'LW-LT12'    => 'LT12',
		'LW-LT01'    => 'LT01',
		'LW-LX03'    => 'LX03',
		'LW-MAP'     => 'ZMAP',
		'LW-SCAN'    => 'LM00',
		'LW-MDE'     => 'LM00',
		'LW-VA01'    => 'VA01',
		'LW-VA03'    => 'VA03',
		'LW-VL01'    => 'VL01N',
		'LW-VL02'    => 'VL02N',
		'LW-CO01'    => 'CO01',
		'LW-CO11'    => 'CO11N',
		'LW-MI01'    => 'MI01',
		'LW-MI04'    => 'MI04',
		'LW-MI07'    => 'MI07',
		'LW-RET'     => 'ZRET',
	);

	public static function normalize( string $code ): string {
		$c = strtoupper( trim( $code ) );
		return self::RENAMED[ $c ] ?? $c;
	}

	/** Remplace les anciens codes dans un texte (consignes, objectifs). */
	public static function replace_in( string $text ): string {
		if ( ! str_contains( $text, 'LW-' ) ) {
			return $text;
		}
		return (string) preg_replace_callback( '/LW-[A-Z0-9]+/', static fn( $m ) => self::RENAMED[ $m[0] ] ?? $m[0], $text );
	}

	/** Migration des scénarios, consignes et journaux déjà enregistrés (idempotente). */
	public static function migrate_data(): void {
		global $wpdb;
		$tt = Db::t( 'scenario_task' );
		$al = Db::t( 'action_log' );
		foreach ( self::RENAMED as $old => $new ) {
			$wpdb->update( $tt, array( 'transaction_code' => $new ), array( 'transaction_code' => $old ) );
			$wpdb->update( $al, array( 'transaction_code' => $new ), array( 'transaction_code' => $old ) );
		}
		$st   = Db::t( 'scenario' );
		$rows = (array) $wpdb->get_results( "SELECT id, title, objectives, instructions FROM {$st} WHERE CONCAT(title, objectives, instructions) LIKE '%LW-%'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$wpdb->update( $st, array( 'title' => self::replace_in( (string) $r['title'] ), 'objectives' => self::replace_in( (string) $r['objectives'] ), 'instructions' => self::replace_in( (string) $r['instructions'] ) ), array( 'id' => (int) $r['id'] ) );
		}
		$rows = (array) $wpdb->get_results( "SELECT id, title, body FROM {$tt} WHERE CONCAT(title, body) LIKE '%LW-%'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$wpdb->update( $tt, array( 'title' => self::replace_in( (string) $r['title'] ), 'body' => self::replace_in( (string) $r['body'] ) ), array( 'id' => (int) $r['id'] ) );
		}
	}
}
