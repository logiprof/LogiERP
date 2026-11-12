<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\NumberRange;
use LogiERP\Domain\Rules;
use LogiERP\Domain\StockService;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock initial du jeu de base (session 0 = référence, jamais modifiée par un apprenant).
 */
final class StockSeeder {

	public static function base_date(): string {
		$d = (string) get_option( 'logierp_base_date', '2026-01-05' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '2026-01-05';
	}

	/** Attribution des emplacements fixes (lotissement par case). */
	public static function assign_fixed_bins( int $dataset ): int {
		global $wpdb;
		$bt = Db::t( 'bin' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} WHERE fixed_material_id > 0" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 0;
		}
		$mt  = Db::t( 'material' );
		$all = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$mt} WHERE dataset_id = %d ORDER BY material_no", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$plan = array(
			'130' => static fn( $m ) => 'HV' === $m['profile_code'] && (float) $m['net_weight'] < 3 && (float) $m['volume_l'] < 3,
			'140' => static fn( $m ) => 'HV' === $m['profile_code'],
			'120' => static fn( $m ) => 'SMALL' === $m['profile_code'],
			'180' => static fn( $m ) => 'FRAG' === $m['profile_code'],
			'220' => static fn( $m ) => 'TEMP' === $m['profile_code'],
			'304' => static fn( $m ) => 'CHEM' === $m['profile_code'] && '6.1' === $m['adr_class'],
			'306' => static fn( $m ) => 'BATT' === $m['profile_code'],
			'400' => static fn( $m ) => 'STD' === $m['profile_code'] && 'A' === $m['abc'] && (float) $m['gross_weight'] <= 12 && (float) $m['volume_l'] <= 20,
		);
		$done     = array();
		$assigned = 0;
		foreach ( $plan as $code => $filter ) {
			$loc  = Catalog::location_by_code( (string) $code );
			$bins = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$bt} WHERE storage_location_id = %d AND fixed_material_id = 0 ORDER BY pick_sequence", (int) $loc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$i    = 0;
			foreach ( $all as $m ) {
				if ( '400' !== (string) $code && isset( $done[ (int) $m['id'] ] ) ) {
					continue;
				}
				if ( ! $filter( $m ) ) {
					continue;
				}
				if ( ! isset( $bins[ $i ] ) ) {
					break;
				}
				$bin = $bins[ $i++ ];
				if ( Rules::check_location( $m, $loc ) || (int) $m['length_mm'] > (int) $bin['length_mm'] ) {
					--$i;
					continue;
				}
				$cap  = Rules::capacity_units( $m, $bin, array( 'weight' => 0.0, 'volume' => 0.0, 'materials' => array(), 'batches' => array(), 'adr' => array(), 'qty' => array() ) );
				$maxq = max( 1, min( $cap, 9999 ) );
				$upc  = max( 1, (int) $m['units_per_carton'] );
				if ( $maxq >= $upc ) {
					$maxq = floor( $maxq / $upc ) * $upc;
				}
				$wpdb->update(
					$bt,
					array(
						'fixed_material_id' => (int) $m['id'],
						'max_qty'           => $maxq,
						'min_qty'           => max( 1, floor( $maxq * 0.25 ) ),
					),
					array( 'id' => (int) $bin['id'] )
				);
				if ( '400' !== (string) $code ) {
					$done[ (int) $m['id'] ] = true;
				}
				++$assigned;
			}
		}
		Catalog::reset_cache();
		return $assigned;
	}

	/**
	 * Place le stock initial d'un profil (une étape de la tâche, en mémoire pour la rapidité).
	 */
	public static function seed_profile( int $dataset, int $seed, string $profile, bool $missing_only = false ): int {
		global $wpdb;
		$mt   = Db::t( 'material' );
		$st   = Db::t( 'stock' );
		$prefix = Dictionaries::profiles()[ $profile ]['prefix'];
		if ( ! $missing_only ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} s JOIN {$mt} m ON m.id = s.material_id WHERE s.session_id = 0 AND m.profile_code = %s", $profile ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists > 0 ) {
				return 0;
			}
		}
		$rng   = Rng::for( $seed, ( $missing_only ? 800 : 500 ) + (int) $prefix );
		$base  = self::base_date();
		$only  = $missing_only ? " AND m.status = 'ACTIF' AND m.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM {$st} x WHERE x.session_id = 0 AND x.material_id = m.id)" : '';
		$mats  = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$mt} m WHERE m.dataset_id = %d AND m.profile_code = %s{$only} ORDER BY m.material_no", $dataset, $profile ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $mats ) {
			return 0;
		}

		// Emplacements chaotiques candidats + occupation actuelle (en mémoire).
		$locs = array();
		foreach ( Catalog::locations() as $loc ) {
			if ( 'CHAOTIC' === $loc['strategy'] && ! in_array( $loc['zone_type'], array( 'PICK', 'PROD', 'TRANSIT' ), true ) ) {
				$locs[ (int) $loc['id'] ] = $loc;
			}
		}
		$bt    = Db::t( 'bin' );
		$bins  = array();
		$in    = implode( ',', array_keys( $locs ) );
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$bt} WHERE storage_location_id IN ({$in}) AND fixed_material_id = 0 AND blocked_in = 0 ORDER BY pick_sequence", ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bins[ (int) $b['storage_location_id'] ][] = $b;
		}
		$occ = \LogiERP\Domain\Strategies::occupancy_for_locations( 0, array_keys( $locs ) );

		$rows   = array();
		$placed = 0;
		foreach ( $mats as $m ) {
			$fefo = ! empty( $m['batch_managed'] ) && (int) $m['shelf_life_days'] > 0;
			$lots = $fefo && $rng->chance( 0.4 ) ? 2 : 1;
			$fixed_bins = Catalog::fixed_bins_of( (int) $m['id'] );
			$upc  = max( 1, (int) $m['units_per_carton'] );
			$unit_pallet = max( 1, $upc * max( 1, (int) $m['cartons_per_pallet'] ) );

			// Emplacements fixes : stock entre 40 et 90 % de la capacité.
			$reserve_needed = true;
			foreach ( $fixed_bins as $bid ) {
				$bin = Catalog::bin( $bid );
				$loc = Catalog::location( (int) $bin['storage_location_id'] );
				$qty = (float) $bin['max_qty'] * $rng->range( 0.4, 0.9 );
				$qty = $qty >= $upc ? floor( $qty / $upc ) * $upc : max( 1, floor( $qty ) );
				$lot = self::lot( $m, $rng, $base );
				$rows[] = self::row( $m, $loc, $bin, $qty, $lot );
				++$placed;
				if ( 'PICK' !== $loc['zone_type'] ) {
					$reserve_needed = false;
				}
			}
			if ( ! $reserve_needed ) {
				continue;
			}

			// Réserve en stockage chaotique.
			$cands = array();
			foreach ( $locs as $lid => $loc ) {
				if ( ! Rules::check_location( $m, $loc ) && ! empty( $bins[ $lid ] ) ) {
					$cands[] = $lid;
				}
			}
			if ( ! $cands ) {
				continue;
			}
			for ( $l = 0; $l < $lots; $l++ ) {
				$lot   = self::lot( $m, $rng, $base );
				$want  = max( 1, $unit_pallet * $rng->range( 0.5, 1.0 ) );
				$found = null;
				$pool  = array();
				foreach ( $cands as $lid ) {
					foreach ( $bins[ $lid ] as $b ) {
						$pool[] = $b;
					}
				}
				// Deuxième lot seulement s'il reste de la place : la priorité est d'avoir chaque article en stock.
				if ( $l > 0 ) {
					$free = 0;
					foreach ( $pool as $pb ) {
						if ( empty( $occ[ (int) $pb['id'] ]['materials'] ) ) {
							++$free;
						}
					}
					if ( $free < 0.3 * count( $pool ) ) {
						break;
					}
				}
				// Tirage au hasard (stockage chaotique), puis parcours complet si rien n'a été trouvé :
				// chaque article reçoit un stock dès qu'un emplacement conforme existe.
				$order = array();
				for ( $try = 0; $try < 60; $try++ ) {
					$order[] = $rng->int( 0, count( $pool ) - 1 );
				}
				$order = array_merge( $order, array_keys( $pool ) );
				foreach ( $order as $idx ) {
					if ( $found ) {
						break;
					}
					$b = $pool[ $idx ];
					$o = $occ[ (int) $b['id'] ] ?? array( 'weight' => 0.0, 'volume' => 0.0, 'materials' => array(), 'batches' => array(), 'adr' => array(), 'qty' => array() );
					if ( $o['materials'] ) {
						continue;
					}
					$cap = Rules::capacity_units( $m, $b, $o );
					if ( $cap < 1 ) {
						continue;
					}
					$q = min( $want, $cap );
					$q = $q >= $upc ? floor( $q / $upc ) * $upc : max( 1, floor( $q ) );
					if ( ! Rules::check_bin( 0, $m, $b, $q, $lot['batch'], $o, true ) ) {
						$found = array( $b, $q, $o );
					}
				}
				if ( ! $found ) {
					continue;
				}
				[ $b, $q, $o ] = $found;
				$loc    = $locs[ (int) $b['storage_location_id'] ];
				$rows[] = self::row( $m, $loc, $b, $q, $lot );
				$o['weight'] += $q * (float) $m['gross_weight'];
				$o['volume'] += $q * (float) $m['volume_l'];
				$o['materials'][ (int) $m['id'] ] = true;
				$o['batches'][ (int) $m['id'] . '|' . $lot['batch'] ] = true;
				$o['qty'][ (int) $m['id'] ] = $q;
				if ( '' !== (string) $m['adr_class'] ) {
					$o['adr'][ (string) $m['adr_class'] ] = true;
				}
				$occ[ (int) $b['id'] ] = $o;
				++$placed;
			}
		}
		self::insert_rows( $rows );
		return $placed;
	}

	private static function lot( array $m, Rng $rng, string $base ): array {
		$shelf = (int) $m['shelf_life_days'];
		$age   = $shelf > 0 ? $rng->int( 1, max( 2, (int) floor( $shelf * 0.5 ) ) ) : $rng->int( 1, 120 );
		$gr    = gmdate( 'Y-m-d', strtotime( $base . ' -' . $age . ' days' ) );
		if ( empty( $m['batch_managed'] ) ) {
			return array( 'batch' => '', 'bbd' => null, 'gr' => $gr );
		}
		$batch = 'L' . gmdate( 'ymd', strtotime( $gr ) ) . str_pad( (string) $rng->int( 1, 99 ), 2, '0', STR_PAD_LEFT );
		$bbd   = $shelf > 0 ? gmdate( 'Y-m-d', strtotime( $gr . ' +' . $shelf . ' days' ) ) : null;
		return array( 'batch' => $batch, 'bbd' => $bbd, 'gr' => $gr );
	}

	private static function row( array $m, array $loc, array $bin, float $qty, array $lot ): array {
		return array(
			'session_id'          => 0,
			'material_id'         => (int) $m['id'],
			'storage_location_id' => (int) $loc['id'],
			'bin_id'              => (int) $bin['id'],
			'batch'               => $lot['batch'],
			'bbd'                 => $lot['bbd'],
			'status'              => 'LIBRE',
			'qty'                 => $qty,
			'gr_date'             => $lot['gr'],
		);
	}

	private static function insert_rows( array $rows ): void {
		$with    = array_values( array_filter( $rows, static fn( $r ) => null !== $r['bbd'] ) );
		$without = array_values( array_filter( $rows, static fn( $r ) => null === $r['bbd'] ) );
		if ( $with ) {
			Db::bulk_insert( 'stock', array( 'session_id', 'material_id', 'storage_location_id', 'bin_id', 'batch', 'bbd', 'status', 'qty', 'gr_date' ), array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s' ), $with );
		}
		if ( $without ) {
			Db::bulk_insert(
				'stock',
				array( 'session_id', 'material_id', 'storage_location_id', 'bin_id', 'batch', 'status', 'qty', 'gr_date' ),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%s' ),
				array_map( static function ( $r ) {
					unset( $r['bbd'] );
					return $r;
				}, $without )
			);
		}
	}

	/** Donne un stock de référence à chaque article actif qui n'en a pas encore (dès qu'un emplacement conforme existe). */
	public static function fill_missing( int $dataset, int $seed ): int {
		$n = 0;
		foreach ( array_keys( Dictionaries::profiles() ) as $p ) {
			$n += self::seed_profile( $dataset, $seed, (string) $p, true );
		}
		Catalog::reset_cache();
		// Zone compatible pleine : une allée est ajoutée (mêmes dimensions que la zone), puis nouvel essai.
		$extended = array();
		foreach ( self::missing_materials( $dataset ) as $m ) {
			foreach ( Catalog::locations() as $loc ) {
				if ( 'CHAOTIC' !== $loc['strategy'] || ! empty( $loc['is_transit'] ) || Rules::check_location( $m, $loc ) || isset( $extended[ (int) $loc['id'] ] ) ) {
					continue;
				}
				if ( self::extend_zone( $loc ) > 0 ) {
					$extended[ (int) $loc['id'] ] = true;
					$n += self::seed_profile( $dataset, $seed, (string) $m['profile_code'], true );
				}
				break;
			}
		}
		Catalog::reset_cache();
		return $n;
	}

	private static function missing_materials( int $dataset ): array {
		global $wpdb;
		$mt = Db::t( 'material' );
		$st = Db::t( 'stock' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$mt} m WHERE m.dataset_id = %d AND m.status = 'ACTIF' AND m.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM {$st} x WHERE x.session_id = 0 AND x.material_id = m.id) ORDER BY m.material_no", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Ajoute une allée à une zone entièrement occupée, sur le modèle de sa première allée. */
	private static function extend_zone( array $loc ): int {
		global $wpdb;
		$bt   = Db::t( 'bin' );
		$st   = Db::t( 'stock' );
		$free = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bt} b WHERE b.storage_location_id = %d AND b.fixed_material_id = 0 AND b.blocked_in = 0 AND NOT EXISTS (SELECT 1 FROM {$st} s WHERE s.bin_id = b.id AND s.session_id = 0)", (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $free > 0 ) {
			return 0;
		}
		$first = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE storage_location_id = %d ORDER BY id LIMIT 1", (int) $loc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $first ) {
			return 0;
		}
		$aisles = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT aisle FROM {$bt} WHERE storage_location_id = %d", (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base   = (string) $first['aisle'];
		$next   = '';
		for ( $k = 2; $k < 100 && '' === $next; $k++ ) {
			$cand = preg_match( '/^([A-Z]*)(\d+)$/', $base, $mm ) ? $mm[1] . str_pad( (string) ( (int) $mm[2] + $k - 1 ), strlen( $mm[2] ), '0', STR_PAD_LEFT ) : $base . $k;
			if ( ! in_array( $cand, $aisles, true ) ) {
				$next = $cand;
			}
		}
		$bays   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT bay) FROM {$bt} WHERE storage_location_id = %d AND aisle = %s", (int) $loc['id'], $base ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$levels = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT level FROM {$bt} WHERE storage_location_id = %d AND aisle = %s ORDER BY level", (int) $loc['id'], $base ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pos    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT position FROM {$bt} WHERE storage_location_id = %d AND aisle = %s ORDER BY position", (int) $loc['id'], $base ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' === $next || $bays < 1 ) {
			return 0;
		}
		return \LogiERP\Admin\WarehousePage::generate_bins(
			$loc,
			array( 'aisle' => $next, 'bays' => $bays, 'levels' => $levels ?: array( '' ), 'pos' => $pos ?: array( '' ), 'type' => $first['bin_type'], 'weight' => (float) $first['max_weight'], 'vol' => (float) $first['max_volume_l'], 'len' => (int) $first['length_mm'] )
		);
	}

	/** Situations d'apprentissage déjà présentes dans le jeu de base. */
	public static function situations( int $dataset, int $seed ): array {
		global $wpdb;
		if ( get_option( 'logierp_situations_done' ) ) {
			return array();
		}
		$rng  = Rng::for( $seed, 700 );
		$base = self::base_date();
		$st   = Db::t( 'stock' );
		$mt   = Db::t( 'material' );
		$bt   = Db::t( 'bin' );
		$lt   = Db::t( 'storage_location' );

		// 25 lots proches de la DLC, 10 lots périmés (zones froides, tempérées, chimie).
		$lots = $wpdb->get_col( "SELECT s.id FROM {$st} s WHERE s.session_id = 0 AND s.bbd IS NOT NULL ORDER BY s.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lots = $rng->shuffle( array_map( 'intval', $lots ) );
		foreach ( array_slice( $lots, 0, 25 ) as $id ) {
			$wpdb->update( $st, array( 'bbd' => gmdate( 'Y-m-d', strtotime( $base . ' +' . $rng->int( 1, 10 ) . ' days' ) ) ), array( 'id' => $id ) );
		}
		foreach ( array_slice( $lots, 25, 10 ) as $id ) {
			$wpdb->update( $st, array( 'bbd' => gmdate( 'Y-m-d', strtotime( $base . ' -' . $rng->int( 1, 20 ) . ' days' ) ) ), array( 'id' => $id ) );
		}

		// 20 lignes en contrôle qualité.
		$qc = $wpdb->get_col( "SELECT s.id FROM {$st} s JOIN {$lt} l ON l.id = s.storage_location_id WHERE s.session_id = 0 AND l.strategy = 'CHAOTIC' ORDER BY s.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_slice( $rng->shuffle( array_map( 'intval', $qc ) ), 0, 20 ) as $id ) {
			$wpdb->update( $st, array( 'status' => 'CONTROLE_QUALITE' ), array( 'id' => $id ) );
		}

		// 15 emplacements bloqués.
		$reasons = array( 'Lisse endommagée, contrôle du rayonnage demandé', 'Chariot en panne dans l\'allée', 'Fuite détectée, nettoyage en cours', 'Inventaire en cours', 'Éclairage défectueux' );
		$cand    = $wpdb->get_col( "SELECT b.id FROM {$bt} b JOIN {$lt} l ON l.id = b.storage_location_id WHERE l.code IN ('110','150','200') AND b.fixed_material_id = 0 ORDER BY b.id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_slice( $rng->shuffle( array_map( 'intval', $cand ) ), 0, 15 ) as $i => $id ) {
			$wpdb->update( $bt, array( 'blocked_in' => 1, 'blocked_out' => $i % 3 ? 0 : 1, 'block_reason' => $reasons[ $i % count( $reasons ) ] ), array( 'id' => $id ) );
		}

		update_option( 'logierp_situations_done', 1, false );
		Catalog::reset_cache();
		return array();
	}

	/**
	 * Paramètres de réapprovisionnement cohérents avec le stock, puis 60 articles volontairement
	 * placés sous leur point de commande (besoins d'achat à traiter dans ME2M). Une seule fois.
	 */
	public static function align( int $seed ): array {
		global $wpdb;
		if ( get_option( 'logierp_align_done' ) ) {
			return array( 'aligned' => 0, 'below_reorder' => 0 );
		}
		$rng = Rng::for( $seed, 710 );
		$st  = Db::t( 'stock' );
		$mt  = Db::t( 'material' );
		// Paramètres de réapprovisionnement alignés sur le stock libre réel :
		// minimum ≤ point de commande < stock libre ≤ maximum, maximum arrondi au lot d'approvisionnement.
		$aligned = self::align_parameters();

		// 60 articles sous le point de commande.
		$tot = $wpdb->get_results( "SELECT material_id, SUM(qty) q FROM {$st} WHERE session_id = 0 AND status = 'LIBRE' GROUP BY material_id ORDER BY material_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n   = 0;
		foreach ( $rng->shuffle( (array) $tot ) as $r ) {
			if ( $n >= 60 ) {
				break;
			}
			// Paramètres cohérents : minimum ≤ point de commande ≤ maximum.
			$rop = ceil( (float) $r['q'] * $rng->range( 1.2, 2.0 ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$mt} SET reorder_point = %f, min_stock = LEAST(min_stock, %f), max_stock = GREATEST(max_stock, %f + 2 * lot_size) WHERE id = %d", $rop, $rop, $rop, (int) $r['material_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			++$n;
		}
		update_option( 'logierp_align_done', 1, false );
		Catalog::reset_cache();
		return array( 'aligned' => $aligned, 'below_reorder' => $n );
	}

	/**
	 * Rend les paramètres de chaque article cohérents avec son stock libre de référence.
	 * Stock au-dessus du maximum : le maximum est relevé (arrondi au lot). Stock sous le point de
	 * commande : le point de commande (et si besoin le minimum) est abaissé. Retourne le nombre d'articles corrigés.
	 */
	public static function align_parameters(): int {
		global $wpdb;
		$st   = Db::t( 'stock' );
		$mt   = Db::t( 'material' );
		$rows = $wpdb->get_results( "SELECT m.id, m.min_stock, m.reorder_point, m.max_stock, m.lot_size, s.q FROM {$mt} m JOIN (SELECT material_id, SUM(qty) q FROM {$st} WHERE session_id = 0 AND status = 'LIBRE' GROUP BY material_id) s ON s.material_id = m.id WHERE m.deleted_at IS NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n    = 0;
		foreach ( (array) $rows as $r ) {
			$q   = (float) $r['q'];
			$min = (float) $r['min_stock'];
			$rop = (float) $r['reorder_point'];
			$max = (float) $r['max_stock'];
			$lot = max( 1.0, (float) $r['lot_size'] );
			if ( $q <= 0 ) {
				continue;
			}
			$changed = false;
			if ( $q <= $rop ) {
				$rop     = floor( $q * 0.6 );
				$min     = min( $min, floor( $rop * 0.5 ) );
				$changed = true;
			}
			if ( $max > 0 && $q > $max ) {
				$max     = ceil( ( $q * 1.15 ) / $lot ) * $lot;
				$changed = true;
			}
			if ( $max > 0 && $max < $rop + $lot ) {
				$max     = $rop + $lot;
				$changed = true;
			}
			if ( $changed ) {
				$wpdb->update( $mt, array( 'min_stock' => $min, 'reorder_point' => $rop, 'max_stock' => $max ), array( 'id' => (int) $r['id'] ), array( '%f', '%f', '%f' ), array( '%d' ) );
				++$n;
			}
		}
		return $n;
	}

	/** 30 commandes fournisseurs et 40 commandes clients ouvertes (fond de décor). */
	public static function open_documents( int $dataset, int $seed ): array {
		global $wpdb;
		$pot = Db::t( 'purchase_order' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pot} WHERE session_id = 0" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array();
		}
		$rng  = Rng::for( $seed, 800 );
		$base = StockSeeder::base_date();
		$smt  = Db::t( 'supplier_material' );
		$mt   = Db::t( 'material' );
		$links = $wpdb->get_results( "SELECT sm.supplier_id, sm.material_id, sm.price, m.lot_size FROM {$smt} sm JOIN {$mt} m ON m.id = sm.material_id WHERE sm.is_main = 1 ORDER BY sm.id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_sup = array();
		foreach ( (array) $links as $l ) {
			$by_sup[ (int) $l['supplier_id'] ][] = $l;
		}
		$sup_ids = array_keys( $by_sup );
		for ( $i = 0; $i < 30; $i++ ) {
			$sid   = $rng->pick( $sup_ids );
			$po_id = Db::insert(
				'purchase_order',
				array(
					'session_id' => 0,
					'po_no'      => NumberRange::next( 0, 'PO' ),
					'supplier_id' => $sid,
					'order_date' => gmdate( 'Y-m-d', strtotime( $base . ' -' . $rng->int( 2, 20 ) . ' days' ) ),
					'status'     => 'ENVOYEE',
					'created_at' => Db::now(),
				)
			);
			foreach ( array_slice( $rng->shuffle( $by_sup[ $sid ] ), 0, $rng->int( 1, 4 ) ) as $ln => $l ) {
				Db::insert(
					'purchase_order_item',
					array(
						'po_id'         => $po_id,
						'line_no'       => ( $ln + 1 ) * 10,
						'material_id'   => (int) $l['material_id'],
						'qty'           => max( 1, (float) $l['lot_size'] ),
						'price'         => (float) $l['price'],
						'delivery_date' => gmdate( 'Y-m-d', strtotime( $base . ' ' . sprintf( '%+d', $rng->int( -3, 10 ) ) . ' days' ) ),
					)
				);
			}
		}
		$ct    = Db::t( 'customer' );
		$custs = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$ct} WHERE dataset_id = %d AND blocked = 0 ORDER BY id", $dataset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$st    = Db::t( 'stock' );
		$avail = $wpdb->get_results( "SELECT s.material_id, SUM(s.qty) q, m.sale_price FROM {$st} s JOIN {$mt} m ON m.id = s.material_id WHERE s.session_id = 0 AND s.status = 'LIBRE' GROUP BY s.material_id HAVING q >= 4 ORDER BY s.material_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		for ( $i = 0; $i < 40; $i++ ) {
			$so_id = Db::insert(
				'sales_order',
				array(
					'session_id' => 0,
					'so_no'      => NumberRange::next( 0, 'SO' ),
					'customer_id' => $rng->pick( $custs ),
					'order_date' => gmdate( 'Y-m-d', strtotime( $base . ' -' . $rng->int( 0, 5 ) . ' days' ) ),
					'req_date'   => gmdate( 'Y-m-d', strtotime( $base . ' +' . $rng->int( 1, 10 ) . ' days' ) ),
					'priority'   => $rng->pick( array( 'A', 'B', 'B', 'C' ) ),
					'status'     => 'CONFIRMEE',
					'created_at' => Db::now(),
				)
			);
			foreach ( array_slice( $rng->shuffle( (array) $avail ), 0, $rng->int( 1, 5 ) ) as $ln => $a ) {
				$qty = max( 1, floor( (float) $a['q'] * $rng->range( 0.05, 0.25 ) ) );
				Db::insert(
					'sales_order_item',
					array(
						'so_id'        => $so_id,
						'line_no'      => ( $ln + 1 ) * 10,
						'material_id'  => (int) $a['material_id'],
						'qty'          => $qty,
						'qty_reserved' => min( $qty, StockService::available( 0, (int) $a['material_id'] ) ),
						'price'        => (float) $a['sale_price'],
					)
				);
			}
		}
		return array( 'po' => 30, 'so' => 40 );
	}
}
