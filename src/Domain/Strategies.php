<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stratégies d'entreposage (proposition d'emplacement) et de prélèvement (FIFO / FEFO).
 */
final class Strategies {

	/** @var array<int,array<int,array>> */
	private static array $blocks = array();

	/** Charge en une requête les blocages propres à une session. */
	public static function session_blocks( int $session_id ): array {
		global $wpdb;
		if ( ! isset( self::$blocks[ $session_id ] ) ) {
			self::$blocks[ $session_id ] = array();
			if ( $session_id > 0 ) {
				$t    = Db::t( 'session_bin_state' );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bin_id, blocked_in, blocked_out, reason FROM {$t} WHERE session_id = %d", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $rows as $r ) {
					self::$blocks[ $session_id ][ (int) $r['bin_id'] ] = $r;
				}
			}
		}
		return self::$blocks[ $session_id ];
	}

	public static function forget_blocks( int $session_id ): void {
		unset( self::$blocks[ $session_id ] );
	}

	private static function blocked_in( int $session_id, array $bin ): bool {
		if ( ! empty( $bin['blocked_in'] ) ) {
			return true;
		}
		$b = self::session_blocks( $session_id )[ (int) $bin['id'] ] ?? null;
		return $b && ! empty( $b['blocked_in'] );
	}

	/** Occupation de tous les emplacements de plusieurs magasins (une requête). */
	public static function occupancy_for_locations( int $session_id, array $location_ids ): array {
		global $wpdb;
		if ( ! $location_ids ) {
			return array();
		}
		$s    = Db::t( 'stock' );
		$m    = Db::t( 'material' );
		$in   = implode( ',', array_map( 'intval', $location_ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.bin_id, s.material_id, s.batch, s.qty, m.gross_weight, m.volume_l, m.adr_class FROM {$s} s JOIN {$m} m ON m.id = s.material_id WHERE s.session_id = %d AND s.storage_location_id IN ({$in}) AND s.qty > 0", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$by[ (int) $r['bin_id'] ][] = $r;
		}
		$out = array();
		foreach ( $by as $bin_id => $list ) {
			$out[ $bin_id ] = Rules::occupancy_from_rows( $list );
		}
		return $out;
	}

	private static function empty_occ(): array {
		return array( 'weight' => 0.0, 'volume' => 0.0, 'materials' => array(), 'batches' => array(), 'adr' => array(), 'qty' => array() );
	}

	/**
	 * Propose un emplacement pour $qty unités. Résultat : ['bin' => array, 'location' => array, 'reason' => string]
	 * ou lance DomainError si aucun emplacement compatible n'est disponible.
	 *
	 * @param array $opts exclude_zone_types, add_to_existing (bool), location_ids (restreint la recherche)
	 */
	public static function propose_putaway( int $session_id, array $m, float $qty, string $batch, Rng $rng, array $opts = array() ): array {
		global $wpdb;
		$exclude  = $opts['exclude_zone_types'] ?? array( 'PICK', 'PROD', 'TRANSIT' );
		$only     = $opts['location_ids'] ?? null;
		$add_mode = $opts['add_to_existing'] ?? true;
		$skip     = array_flip( array_map( 'intval', $opts['exclude_bin_ids'] ?? array() ) );

		// 1. Emplacements fixes de l'article.
		foreach ( Catalog::fixed_bins_of( (int) $m['id'] ) as $bin_id ) {
			$bin = Catalog::bin( $bin_id );
			$loc = $bin ? Catalog::location( (int) $bin['storage_location_id'] ) : null;
			if ( ! $bin || ! $loc || in_array( $loc['zone_type'], $exclude, true ) ) {
				continue;
			}
			if ( null !== $only && ! in_array( (int) $loc['id'], $only, true ) ) {
				continue;
			}
			if ( self::blocked_in( $session_id, $bin ) ) {
				continue;
			}
			if ( ! Rules::check_bin( $session_id, $m, $bin, $qty, $batch, null, true ) ) {
				return array( 'bin' => $bin, 'location' => $loc, 'reason' => 'Emplacement fixe attribué à l\'article' );
			}
		}

		// 2. Magasins chaotiques compatibles.
		$locs = array();
		foreach ( Catalog::locations() as $loc ) {
			if ( 'CHAOTIC' !== $loc['strategy'] || in_array( $loc['zone_type'], $exclude, true ) ) {
				continue;
			}
			if ( null !== $only && ! in_array( (int) $loc['id'], $only, true ) ) {
				continue;
			}
			if ( ! Rules::check_location( $m, $loc ) ) {
				$locs[ (int) $loc['id'] ] = $loc;
			}
		}
		if ( ! $locs ) {
			throw new DomainError( sprintf( 'Aucun magasin à stockage chaotique n\'accepte l\'article %s (profil « %s »).', $m['material_no'], Catalog::profile_label( $m['profile_code'] ) ) );
		}
		$bt   = Db::t( 'bin' );
		$in   = implode( ',', array_map( 'intval', array_keys( $locs ) ) );
		$bins = $wpdb->get_results( "SELECT * FROM {$bt} WHERE storage_location_id IN ({$in}) AND fixed_material_id = 0 ORDER BY storage_location_id, pick_sequence", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$occ  = self::occupancy_for_locations( $session_id, array_keys( $locs ) );

		$existing = array();
		$free     = array();
		foreach ( (array) $bins as $bin ) {
			if ( self::blocked_in( $session_id, $bin ) ) {
				continue;
			}
			$o = $occ[ (int) $bin['id'] ] ?? self::empty_occ();
			if ( Rules::check_bin( $session_id, $m, $bin, $qty, $batch, $o, true ) ) {
				continue;
			}
			if ( isset( $o['batches'][ (int) $m['id'] . '|' . $batch ] ) || ( ! empty( $m['bulk'] ) && isset( $o['materials'][ (int) $m['id'] ] ) ) ) {
				$existing[] = $bin; // Vrac : on complète la citerne qui contient déjà le produit.
			} elseif ( ! $o['materials'] && ! isset( $skip[ (int) $bin['id'] ] ) ) {
				$free[] = $bin;
			}
		}
		if ( $add_mode && $existing ) {
			$bin = $existing[0];
			return array( 'bin' => $bin, 'location' => $locs[ (int) $bin['storage_location_id'] ], 'reason' => 'Ajout au stock existant du même article et du même lot' );
		}
		if ( $free ) {
			$bin = $rng->pick( $free );
			return array( 'bin' => $bin, 'location' => $locs[ (int) $bin['storage_location_id'] ], 'reason' => 'Tirage aléatoire parmi les emplacements libres compatibles (stockage chaotique)' );
		}
		if ( $existing ) {
			$bin = $existing[0];
			return array( 'bin' => $bin, 'location' => $locs[ (int) $bin['storage_location_id'] ], 'reason' => 'Ajout au stock existant' );
		}
		throw new DomainError( sprintf( 'Aucun emplacement compatible disponible pour %s %s de l\'article %s.', Catalog::format_qty( $qty ), $m['base_uom'], $m['material_no'] ) );
	}

	/** Le prélèvement de cet article suit-il FEFO ? */
	public static function is_fefo( array $m ): bool {
		return ! empty( $m['batch_managed'] ) && (int) $m['shelf_life_days'] > 0;
	}

	/**
	 * Lignes de stock disponibles pour le prélèvement, dans l'ordre de la stratégie.
	 *
	 * @return array<int,array> lignes de stock (avec location_code, bin_code, zone_type, expired)
	 */
	public static function removal_candidates( int $session_id, array $m, string $sim_date, array $opts = array() ): array {
		global $wpdb;
		$s     = Db::t( 'stock' );
		$l     = Db::t( 'storage_location' );
		$b     = Db::t( 'bin' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, l.code AS location_code, l.zone_type, l.is_transit, b.code AS bin_code, b.blocked_out, b.pick_sequence FROM {$s} s JOIN {$l} l ON l.id = s.storage_location_id LEFT JOIN {$b} b ON b.id = s.bin_id WHERE s.session_id = %d AND s.material_id = %d AND s.status = 'LIBRE' AND s.qty > 0", $session_id, (int) $m['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocks = self::session_blocks( $session_id );
		$fefo  = self::is_fefo( $m );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['is_transit'] ) && empty( $opts['include_transit'] ) ) {
				continue;
			}
			if ( isset( $opts['location_ids'] ) && ! in_array( (int) $r['storage_location_id'], $opts['location_ids'], true ) ) {
				continue;
			}
			if ( isset( $opts['batch'] ) && '' !== (string) $opts['batch'] && (string) $r['batch'] !== (string) $opts['batch'] ) {
				continue; // Lot imposé (VL02N, changement de lot).
			}
			$blocked = ! empty( $r['blocked_out'] ) || ( isset( $blocks[ (int) $r['bin_id'] ] ) && ! empty( $blocks[ (int) $r['bin_id'] ]['blocked_out'] ) );
			if ( $blocked ) {
				continue;
			}
			$r['expired'] = ( $r['bbd'] && $r['bbd'] < $sim_date ) ? 1 : 0;
			$out[]        = $r;
		}
		usort(
			$out,
			static function ( $a, $b ) use ( $fefo ) {
				$pa = 'PICK' === $a['zone_type'] ? 0 : 1;
				$pb = 'PICK' === $b['zone_type'] ? 0 : 1;
				if ( $fefo ) {
					$da = $a['bbd'] ?: '9999-12-31';
					$db = $b['bbd'] ?: '9999-12-31';
					if ( $da !== $db ) {
						return strcmp( $da, $db );
					}
				}
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				$ga = $a['gr_date'] ?: '9999-12-31';
				$gb = $b['gr_date'] ?: '9999-12-31';
				if ( $ga !== $gb ) {
					return strcmp( $ga, $gb );
				}
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		return $out;
	}

	/**
	 * Répartit $qty sur les lignes de stock selon la stratégie (lots périmés exclus).
	 *
	 * @return array{alloc: array<int,array>, missing: float}
	 */
	public static function allocate( int $session_id, array $m, float $qty, string $sim_date, array $opts = array() ): array {
		$alloc = array();
		$left  = $qty;
		foreach ( self::removal_candidates( $session_id, $m, $sim_date, $opts ) as $r ) {
			if ( $left <= 0.0005 ) {
				break;
			}
			if ( $r['expired'] ) {
				continue;
			}
			$take    = min( $left, (float) $r['qty'] - (float) ( $opts['reserved'][ (int) $r['id'] ] ?? 0 ) );
			if ( $take <= 0.0005 ) {
				continue;
			}
			$alloc[] = array( 'row' => $r, 'qty' => $take );
			$left   -= $take;
		}
		return array( 'alloc' => $alloc, 'missing' => max( 0.0, $left ) );
	}

	/**
	 * Le prélèvement sur la ligne $chosen respecte-t-il la stratégie, comparé à la proposition $proposed ?
	 */
	public static function respects_strategy( array $m, array $chosen, array $proposed ): bool {
		if ( (int) $chosen['id'] === (int) $proposed['id'] ) {
			return true;
		}
		if ( self::is_fefo( $m ) ) {
			$c = $chosen['bbd'] ?: '9999-12-31';
			$p = $proposed['bbd'] ?: '9999-12-31';
			return $c <= $p;
		}
		$c = $chosen['gr_date'] ?: '9999-12-31';
		$p = $proposed['gr_date'] ?: '9999-12-31';
		return $c <= $p;
	}
}
