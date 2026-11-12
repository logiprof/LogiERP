<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectures des données de référence, avec cache par requête.
 */
final class Catalog {

	/** @var array<int,array> */
	private static array $materials = array();
	/** @var array<int,array>|null */
	private static ?array $locations = null;
	/** @var array<int,array> */
	private static array $bins = array();

	private static ?int $dataset = null;

	/** Identifiant du jeu de base (ne jamais supposer qu'il vaut 1). */
	public static function dataset(): int {
		global $wpdb;
		if ( null === self::$dataset ) {
			$t             = Db::t( 'dataset' );
			self::$dataset = (int) $wpdb->get_var( "SELECT id FROM {$t} WHERE code = 'BASE'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return self::$dataset;
	}

	public static function reset_cache(): void {
		self::$dataset   = null;
		self::$materials = array();
		self::$locations = null;
		self::$bins      = array();
	}

	public static function material( int $id ): ?array {
		global $wpdb;
		if ( ! isset( self::$materials[ $id ] ) ) {
			$t   = Db::t( 'material' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND deleted_at IS NULL", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return null;
			}
			self::$materials[ $id ] = $row;
		}
		return self::$materials[ $id ];
	}

	public static function material_by_no( string $no, int $dataset = 0 ): ?array {
		global $wpdb;
		$dataset = $dataset ?: self::dataset();
		$t  = Db::t( 'material' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND material_no = %s AND deleted_at IS NULL", $dataset, $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? self::material( $id ) : null;
	}

	/** @return array<int,array> magasins indexés par id */
	public static function locations(): array {
		global $wpdb;
		if ( null === self::$locations ) {
			$t    = Db::t( 'storage_location' );
			$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY sort, code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$locations = array();
			foreach ( (array) $rows as $r ) {
				self::$locations[ (int) $r['id'] ] = $r;
			}
		}
		return self::$locations;
	}

	public static function location( int $id ): ?array {
		return self::locations()[ $id ] ?? null;
	}

	public static function location_by_code( string $code ): ?array {
		foreach ( self::locations() as $l ) {
			if ( $l['code'] === $code ) {
				return $l;
			}
		}
		return null;
	}

	public static function bin( int $id ): ?array {
		global $wpdb;
		if ( ! isset( self::$bins[ $id ] ) ) {
			$t   = Db::t( 'bin' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return null;
			}
			self::$bins[ $id ] = $row;
		}
		return self::$bins[ $id ];
	}

	/**
	 * Chiffre de contrôle d'un emplacement (10 à 99), imprimé sur son étiquette et jamais affiché à l'écran :
	 * le préparateur le lit sur place, comme en préparation vocale, pour prouver qu'il est au bon emplacement.
	 */
	public static function check_digit( string $code ): string {
		return (string) ( 10 + abs( crc32( 'lw-cd|' . strtoupper( trim( $code ) ) ) ) % 90 );
	}

	public static function bin_by_code( string $code ): ?array {
		global $wpdb;
		$code = strtoupper( trim( $code ) );
		$t    = Db::t( 'bin' );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			self::$bins[ (int) $row['id'] ] = $row;
		}
		return $row ?: null;
	}

	/** @return int[] */
	public static function fixed_bins_of( int $material_id ): array {
		global $wpdb;
		$t = Db::t( 'bin' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE fixed_material_id = %d ORDER BY storage_location_id, pick_sequence", $material_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function profile_label( string $code ): string {
		$p = \LogiERP\Seeder\Dictionaries::profiles();
		return isset( $p[ $code ] ) ? $p[ $code ]['name'] : $code;
	}

	public static function format_qty( float $q ): string {
		return rtrim( rtrim( number_format( $q, 3, '.', '' ), '0' ), '.' );
	}
}
