<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\Dictionaries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Règles professionnelles de stockage : un article n'est accepté que si TOUS ses critères sont satisfaits.
 */
final class Rules {

	/**
	 * Contrôle au niveau du magasin (profil, température, classe ADR, sécurité).
	 *
	 * @return string[] messages d'erreur (vide = conforme)
	 */
	public static function check_location( array $m, array $loc ): array {
		if ( ! empty( $loc['is_transit'] ) ) {
			return array();
		}
		$errors   = array();
		$profiles = array_filter( array_map( 'trim', explode( ',', (string) $loc['profiles'] ) ) );
		if ( ! in_array( $m['profile_code'], $profiles, true ) ) {
			$errors[] = sprintf( 'Le magasin %s (%s) n\'accepte pas les articles de profil « %s ».', $loc['code'], $loc['name'], Catalog::profile_label( $m['profile_code'] ) );
		}
		if ( null !== $m['temp_min'] && '' !== (string) $m['temp_min'] ) {
			if ( null === $loc['temp_min'] || (float) $loc['temp_min'] < (float) $m['temp_min'] - 0.01 || (float) $loc['temp_max'] > (float) $m['temp_max'] + 0.01 ) {
				$errors[] = sprintf( 'Température : l\'article doit rester entre %s et %s °C ; le magasin %s ne le garantit pas.', Catalog::format_qty( (float) $m['temp_min'] ), Catalog::format_qty( (float) $m['temp_max'] ), $loc['code'] );
			}
		}
		if ( '' !== (string) $m['adr_class'] ) {
			$allowed = array_filter( array_map( 'trim', explode( ',', (string) $loc['adr_classes'] ) ) );
			if ( ! in_array( (string) $m['adr_class'], $allowed, true ) ) {
				$errors[] = sprintf( 'Matière dangereuse classe %s : le magasin %s n\'est pas prévu pour cette classe.', $m['adr_class'], $loc['code'] );
			}
		}
		if ( ! empty( $m['high_value'] ) && empty( $loc['secured'] ) ) {
			$errors[] = sprintf( 'Article à haute valeur : il doit être stocké sous clé ; le magasin %s n\'est pas sécurisé.', $loc['code'] );
		}
		return array_values( array_unique( $errors ) );
	}

	/** Occupation actuelle d'un emplacement dans une session. */
	public static function bin_occupancy( int $session_id, int $bin_id ): array {
		global $wpdb;
		$s    = Db::t( 'stock' );
		$m    = Db::t( 'material' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.material_id, s.batch, s.qty, m.gross_weight, m.volume_l, m.adr_class FROM {$s} s JOIN {$m} m ON m.id = s.material_id WHERE s.session_id = %d AND s.bin_id = %d AND s.qty > 0", $session_id, $bin_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::occupancy_from_rows( (array) $rows );
	}

	public static function occupancy_from_rows( array $rows ): array {
		$o = array( 'weight' => 0.0, 'volume' => 0.0, 'materials' => array(), 'batches' => array(), 'adr' => array(), 'qty' => array() );
		foreach ( $rows as $r ) {
			$o['qty'][ (int) $r['material_id'] ] = ( $o['qty'][ (int) $r['material_id'] ] ?? 0 ) + (float) $r['qty'];
			$o['weight'] += (float) $r['qty'] * (float) $r['gross_weight'];
			$o['volume'] += (float) $r['qty'] * (float) $r['volume_l'];
			$o['materials'][ (int) $r['material_id'] ] = true;
			$o['batches'][ (int) $r['material_id'] . '|' . $r['batch'] ] = true;
			if ( '' !== (string) $r['adr_class'] ) {
				$o['adr'][ (string) $r['adr_class'] ] = true;
			}
		}
		return $o;
	}

	/** Blocage d'un emplacement (référence + état propre à la session). */
	public static function bin_blocked( int $session_id, array $bin, string $direction ): string {
		global $wpdb;
		$field = 'in' === $direction ? 'blocked_in' : 'blocked_out';
		if ( ! empty( $bin[ $field ] ) ) {
			return $bin['block_reason'] ?: 'bloqué';
		}
		if ( $session_id > 0 ) {
			$t   = Db::t( 'session_bin_state' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT blocked_in, blocked_out, reason FROM {$t} WHERE session_id = %d AND bin_id = %d", $session_id, (int) $bin['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row && ! empty( $row[ $field ] ) ) {
				return $row['reason'] ?: 'bloqué';
			}
		}
		return '';
	}

	/**
	 * Contrôle complet d'un emplacement pour une entrée de $qty unités.
	 *
	 * @param array|null $occ occupation pré-calculée (performance), sinon lue en base
	 * @return string[]
	 */
	public static function check_bin( int $session_id, array $m, array $bin, float $qty, string $batch = '', ?array $occ = null, bool $skip_block = false ): array {
		$loc = Catalog::location( (int) $bin['storage_location_id'] );
		if ( ! $loc ) {
			return array( 'Magasin introuvable.' );
		}
		$errors = self::check_location( $m, $loc );
		if ( ! $skip_block ) {
			$blocked = self::bin_blocked( $session_id, $bin, 'in' );
			if ( '' !== $blocked ) {
				$errors[] = sprintf( 'L\'emplacement %s est bloqué en entrée (%s).', $bin['code'], $blocked );
			}
		}
		if ( ! empty( $loc['is_transit'] ) ) {
			return $errors;
		}
		// Citernes : vrac uniquement, et le vrac ne va qu'en citerne.
		$tank = 'CITERNE' === (string) $bin['bin_type'];
		if ( $tank && empty( $m['bulk'] ) ) {
			$errors[] = sprintf( 'L\'emplacement %s est une citerne : il ne reçoit que de la marchandise en vrac.', $bin['code'] );
		} elseif ( ! $tank && ! empty( $m['bulk'] ) ) {
			$errors[] = sprintf( 'L\'article %s est livré en vrac : il se dépote dans une citerne (magasin 310), pas à l\'emplacement %s.', $m['material_no'], $bin['code'] );
		}
		$fixed = (int) $bin['fixed_material_id'];
		if ( $fixed && $fixed !== (int) $m['id'] ) {
			$other    = Catalog::material( $fixed );
			$errors[] = sprintf( 'L\'emplacement %s est réservé à l\'article %s : on ne peut rien y stocker d\'autre.', $bin['code'], $other ? $other['material_no'] : '?' );
		} elseif ( ! $fixed && 'FIXED' === $loc['strategy'] ) {
			$errors[] = sprintf( 'Le magasin %s est géré par emplacements fixes : l\'emplacement %s n\'est attribué à aucun article.', $loc['code'], $bin['code'] );
		}
		if ( (int) $m['length_mm'] > (int) $bin['length_mm'] ) {
			$errors[] = sprintf( 'Dimensions : l\'article mesure %d mm, l\'emplacement %s accepte %d mm au maximum.', (int) $m['length_mm'], $bin['code'], (int) $bin['length_mm'] );
		}
		if ( ! empty( $m['fragile'] ) && '' !== $bin['level'] && (int) $bin['level'] > 2 ) {
			$errors[] = sprintf( 'Article fragile : il doit être stocké aux niveaux bas (01 ou 02), pas au niveau %s.', $bin['level'] );
		}
		$occ = $occ ?? self::bin_occupancy( $session_id, (int) $bin['id'] );
		if ( empty( $loc['allow_mixed'] ) ) {
			$others = array_diff( array_keys( $occ['materials'] ), array( (int) $m['id'] ) );
			if ( $others ) {
				$errors[] = sprintf( 'L\'emplacement %s contient déjà un autre article : le stockage mélangé est interdit dans ce magasin.', $bin['code'] );
			} elseif ( ! $tank && isset( $occ['materials'][ (int) $m['id'] ] ) && ! isset( $occ['batches'][ (int) $m['id'] . '|' . $batch ] ) && 'FIXED' !== $loc['strategy'] ) {
				$errors[] = sprintf( 'L\'emplacement %s contient déjà un autre lot de cet article : un lot par emplacement.', $bin['code'] );
			}
		}
		if ( '' !== (string) $m['adr_class'] && $occ['adr'] ) {
			foreach ( Dictionaries::incompatible_pairs() as $pair ) {
				foreach ( array_keys( $occ['adr'] ) as $present ) {
					if ( ( $pair[0] === (string) $m['adr_class'] && $pair[1] === (string) $present ) || ( $pair[1] === (string) $m['adr_class'] && $pair[0] === (string) $present ) ) {
						$errors[] = sprintf( 'Ségrégation : la classe %s est incompatible avec la classe %s déjà présente dans %s.', $m['adr_class'], $present, $bin['code'] );
					}
				}
			}
		}
		$add_w = $qty * (float) $m['gross_weight'];
		$add_v = $qty * (float) $m['volume_l'];
		if ( $occ['weight'] + $add_w > (float) $bin['max_weight'] + 0.001 ) {
			$errors[] = sprintf( 'Charge : %s kg déjà présents + %s kg dépasseraient la charge maximale de %s kg de l\'emplacement %s.', Catalog::format_qty( round( $occ['weight'], 1 ) ), Catalog::format_qty( round( $add_w, 1 ) ), Catalog::format_qty( (float) $bin['max_weight'] ), $bin['code'] );
		}
		if ( $occ['volume'] + $add_v > (float) $bin['max_volume_l'] + 0.001 ) {
			$errors[] = sprintf( 'Volume : %s L déjà occupés + %s L dépasseraient les %s L disponibles dans %s.', Catalog::format_qty( round( $occ['volume'], 1 ) ), Catalog::format_qty( round( $add_v, 1 ) ), Catalog::format_qty( (float) $bin['max_volume_l'] ), $bin['code'] );
		}
		if ( $fixed && $fixed === (int) $m['id'] && (float) $bin['max_qty'] > 0 ) {
			$present = (float) ( $occ['qty'][ $fixed ] ?? 0 );
			if ( $present + $qty > (float) $bin['max_qty'] + 0.001 ) {
				$errors[] = sprintf( 'Quantité maximale de l\'emplacement fixe %s : %s (déjà %s).', $bin['code'], Catalog::format_qty( (float) $bin['max_qty'] ), Catalog::format_qty( round( $present ) ) );
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/** Nombre d'unités qu'un emplacement peut encore recevoir (poids et volume). */
	public static function capacity_units( array $m, array $bin, array $occ ): float {
		$w = (float) $m['gross_weight'] > 0 ? ( (float) $bin['max_weight'] - $occ['weight'] ) / (float) $m['gross_weight'] : 1e9;
		$v = (float) $m['volume_l'] > 0 ? ( (float) $bin['max_volume_l'] - $occ['volume'] ) / (float) $m['volume_l'] : 1e9;
		$c = floor( min( $w, $v ) );
		if ( (int) $bin['fixed_material_id'] === (int) $m['id'] && (float) $bin['max_qty'] > 0 ) {
			$present = (float) ( $occ['qty'][ (int) $m['id'] ] ?? 0 );
			$c       = min( $c, floor( (float) $bin['max_qty'] - $present ) );
		}
		return max( 0.0, $c );
	}
}
