<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structure physique du site 1000 : magasins (zones) et emplacements.
 */
final class WarehouseBuilder {

	/**
	 * Définition des magasins.
	 * bins : [aisles[], nb travées, niveaux[], positions[], type, charge kg, volume L, longueur mm]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function locations(): array {
		$L = static fn( int $n, int $start = 1, string $prefix = '' ) => array_map( static fn( $i ) => $prefix . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), range( $start, $start + $n - 1 ) );
		return array(
			array( 'code' => '100', 'name' => 'Réception (quais, contrôle)', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Sol, emplacements tampons', 'map' => array( 20, 20, 160, 120 ), 'notes' => 'Stock en contrôle ou en attente d\'entreposage',
				'bins' => array( array( 'R' ), 12, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			array( 'code' => '110', 'name' => 'Stock général palettes', 'zone_type' => 'STD', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'STD,PAL,SMALL', 'rack' => 'Palettier 5 niveaux', 'overflow' => '170', 'map' => array( 200, 20, 520, 300 ),
				'bins' => array( $L( 6, 1, 'A' ), 24, $L( 5, 0 ), array( 'G', 'M', 'D' ), 'PAL', 1000, 1536, 1200 ) ),
			array( 'code' => '120', 'name' => 'Petites pièces', 'zone_type' => 'STD', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'SMALL,STD', 'rack' => 'Étagères à bacs 600×400', 'overflow' => '110', 'map' => array( 740, 20, 200, 140 ),
				'bins' => array( $L( 4, 1, 'B' ), 15, $L( 5, 1 ), array( '' ), 'BAC', 30, 72, 600 ) ),
			array( 'code' => '130', 'name' => 'Magasin vertical automatique', 'zone_type' => 'STD', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'SMALL,HV', 'secured' => 1, 'rack' => 'Magasin vertical à plateaux, accès par badge', 'map' => array( 960, 20, 220, 140 ),
				'bins' => array( array( 'T01', 'T02' ), 30, array( '01', '02' ), array( '' ), 'PLATEAU', 60, 60, 600, 'P' ) ),
			array( 'code' => '140', 'name' => 'Haute valeur (sous clé)', 'zone_type' => 'HV', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'HV', 'secured' => 1, 'rack' => 'Cage grillagée et armoires fortes', 'map' => array( 960, 180, 220, 140 ),
				'bins' => array( $L( 4, 1, 'C' ), 10, $L( 5, 1 ), array( '' ), 'ARMOIRE', 80, 120, 800 ) ),
			array( 'code' => '150', 'name' => 'Marchandises longues', 'zone_type' => 'LONG', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'LONG', 'rack' => 'Cantilever 6 m', 'map' => array( 200, 480, 300, 120 ),
				'bins' => array( $L( 4, 1, 'C' ), 10, $L( 5, 1 ), array( '' ), 'CANTILEVER', 500, 3000, 6000 ) ),
			array( 'code' => '160', 'name' => 'Charges lourdes (pont roulant)', 'zone_type' => 'HEAVY', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'HEAVY', 'rack' => 'Sol renforcé, pont roulant', 'map' => array( 520, 480, 200, 120 ),
				'bins' => array( $L( 4, 1, 'S' ), 40, array( '' ), array( '' ), 'SOL', 5000, 4000, 3000 ) ),
			array( 'code' => '170', 'name' => 'Volumineux / gerbage au sol', 'zone_type' => 'STD', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'BULKY,PAL', 'rack' => 'Gerbage au sol (blocs)', 'map' => array( 200, 620, 520, 120 ),
				'bins' => array( $L( 6, 1, 'B' ), 30, array( '' ), array( '' ), 'SOL', 2000, 6000, 2500 ) ),
			array( 'code' => '180', 'name' => 'Fragile', 'zone_type' => 'STD', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'FRAG', 'rack' => 'Étagères, niveaux bas', 'map' => array( 740, 180, 200, 140 ),
				'bins' => array( array( 'F01', 'F02' ), 25, array( '01', '02' ), array( '' ), 'ETAGERE', 80, 300, 1000 ) ),
			array( 'code' => '200', 'name' => 'Froid positif +2/+8 °C', 'zone_type' => 'COLD', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'COLD', 'temp' => array( 2, 8 ), 'rack' => 'Palettier réfrigéré', 'map' => array( 740, 340, 200, 140 ),
				'bins' => array( $L( 3, 1, 'K' ), 15, $L( 4, 0 ), array( 'G', 'D' ), 'PAL', 1000, 1536, 1200 ) ),
			array( 'code' => '210', 'name' => 'Froid négatif −25/−18 °C', 'zone_type' => 'FROZEN', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'FROZ', 'temp' => array( -25, -18 ), 'rack' => 'Palettier congélation', 'map' => array( 960, 340, 220, 140 ),
				'bins' => array( $L( 2, 1, 'N' ), 12, $L( 4, 0 ), array( 'G', 'D' ), 'PAL', 1000, 1536, 1200 ) ),
			array( 'code' => '220', 'name' => 'Tempéré +15/+25 °C', 'zone_type' => 'TEMP', 'strategy' => 'FIXED', 'removal' => 'FEFO', 'profiles' => 'TEMP', 'temp' => array( 15, 25 ), 'rack' => 'Étagères', 'map' => array( 740, 500, 200, 120 ),
				'bins' => array( $L( 3, 1, 'T' ), 15, $L( 4, 1 ), array( '' ), 'ETAGERE', 150, 400, 1000 ) ),
			array( 'code' => '301', 'name' => 'Chimie – inflammables (cl. 3)', 'zone_type' => 'CHEM', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'CHEM', 'adr' => '3', 'rack' => 'Palettier ATEX + bac de rétention', 'map' => array( 960, 500, 70, 115 ),
				'bins' => array( array( 'X01', 'X02', 'X03' ), 12, $L( 3, 0 ), array( 'G', 'D' ), 'PAL', 1000, 1536, 1200 ) ),
			array( 'code' => '302', 'name' => 'Chimie – corrosifs (cl. 8)', 'zone_type' => 'CHEM', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'CHEM', 'adr' => '8', 'rack' => 'Étagères + rétention', 'map' => array( 1035, 500, 70, 115 ),
				'bins' => array( array( 'X01', 'X02' ), 8, $L( 4, 1 ), array( 'G', 'D' ), 'ETAGERE', 300, 600, 1200 ) ),
			array( 'code' => '303', 'name' => 'Chimie – comburants (cl. 5.1)', 'zone_type' => 'CHEM', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'CHEM', 'adr' => '5.1', 'rack' => 'Étagères', 'map' => array( 1110, 500, 70, 115 ),
				'bins' => array( array( 'X01', 'X02' ), 10, $L( 2, 1 ), array( 'G', 'D' ), 'ETAGERE', 300, 600, 1200 ) ),
			array( 'code' => '304', 'name' => 'Chimie – toxiques (cl. 6.1)', 'zone_type' => 'CHEM', 'strategy' => 'FIXED', 'removal' => 'FEFO', 'profiles' => 'CHEM', 'adr' => '6.1', 'secured' => 1, 'rack' => 'Armoire ventilée sous clé', 'map' => array( 960, 625, 70, 115 ),
				'bins' => array( array( 'A01' ), 8, $L( 5, 1 ), array( '' ), 'ARMOIRE', 120, 200, 800 ) ),
			array( 'code' => '305', 'name' => 'Aérosols / gaz (cl. 2)', 'zone_type' => 'CHEM', 'strategy' => 'CHAOTIC', 'removal' => 'FEFO', 'profiles' => 'CHEM', 'adr' => '2', 'rack' => 'Cage aérosols', 'map' => array( 1035, 625, 70, 115 ),
				'bins' => array( array( 'X01', 'X02' ), 10, $L( 2, 1 ), array( 'G', 'D' ), 'ETAGERE', 300, 600, 1200 ) ),
			array( 'code' => '306', 'name' => 'Batteries lithium (cl. 9)', 'zone_type' => 'CHEM', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'BATT', 'adr' => '9', 'rack' => 'Armoire anti-feu', 'map' => array( 1110, 625, 70, 115 ),
				'bins' => array( array( 'A01', 'A02' ), 10, $L( 5, 1 ), array( '' ), 'ARMOIRE', 150, 200, 800 ) ),
			array( 'code' => '400', 'name' => 'Prélèvement (picking fixe)', 'zone_type' => 'PICK', 'strategy' => 'FIXED', 'removal' => 'FIFO', 'profiles' => 'STD,SMALL', 'rack' => 'Étagères dynamiques', 'overflow' => '110', 'map' => array( 200, 340, 250, 120 ),
				'bins' => array( $L( 4, 1, 'P' ), 25, $L( 3, 1 ), array( '' ), 'ETAGERE', 200, 500, 1000 ) ),
			array( 'code' => '500', 'name' => 'Bord de ligne production', 'zone_type' => 'PROD', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'STD,SMALL,PAL,CHEM,BULKY', 'adr' => '3,8,5.1', 'mixed' => 1, 'rack' => 'Étagères', 'map' => array( 470, 340, 250, 120 ),
				'bins' => array( array( 'L01' ), 10, $L( 3, 1 ), array( '' ), 'ETAGERE', 500, 1500, 1200 ) ),
			array( 'code' => '600', 'name' => 'Consolidation / emballage', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Tables, sol', 'map' => array( 20, 160, 160, 90 ),
				'bins' => array( array( 'E' ), 10, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			array( 'code' => '700', 'name' => 'Expédition (quais)', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Sol', 'map' => array( 20, 270, 160, 120 ),
				'bins' => array( array( 'Q' ), 8, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			array( 'code' => '800', 'name' => 'Retours / quarantaine', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Sol, étagères', 'map' => array( 20, 410, 160, 90 ),
				'bins' => array( array( 'Q' ), 10, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			array( 'code' => '950', 'name' => 'Emballages vides (consignés)', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Sol : piles de palettes, fûts et caisses vides', 'map' => array( 20, 600, 160, 70 ),
				'bins' => array( array( 'V' ), 8, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			array( 'code' => '900', 'name' => 'Rebut / destruction', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Sol', 'map' => array( 20, 520, 160, 60 ),
				'bins' => array( array( 'D' ), 4, array( '' ), array( '' ), 'SOL', 8000, 12000, 7000 ) ),
			// 2.0 : citernes de vrac (chimie de procédé) et aire des écarts de la gestion des emplacements.
			array( 'code' => '310', 'name' => 'Citernes vrac (chimie)', 'zone_type' => 'CHEM', 'strategy' => 'CHAOTIC', 'removal' => 'FIFO', 'profiles' => 'CHEM', 'adr' => '3,8,5.1', 'rack' => 'Citernes inox sur rétention, poste de dépotage', 'map' => array( 740, 640, 200, 100 ), 'notes' => 'Une citerne = un seul article ; le fond non dépotable des camions et wagons part en perte',
				'bins' => array( array( 'T' ), 6, array( '' ), array( '' ), 'CITERNE', 60000, 60000, 12000 ) ),
			array( 'code' => '999', 'name' => 'Écarts et différences (WM)', 'zone_type' => 'TRANSIT', 'strategy' => 'NONE', 'removal' => 'FIFO', 'profiles' => '*', 'transit' => 1, 'rack' => 'Aire virtuelle des écarts (soldée par LI21)', 'map' => array( 20, 685, 160, 55 ), 'notes' => 'Pertes de citerne, écarts de confirmation et marchandise à détruire (emplacement SCHROTT)',
				'bins' => array( array( 'E', 'SCHROTT' ), 2, array( '' ), array( '' ), 'SOL', 99999, 99999, 12000 ) ),
		);
	}

	/** Profils de stockage (critères). */
	public static function storage_profiles(): array {
		$out = array();
		foreach ( Dictionaries::profiles() as $code => $p ) {
			$temp  = array( 'COLD' => array( 2, 8 ), 'FROZ' => array( -25, -18 ), 'TEMP' => array( 15, 25 ) )[ $code ] ?? null;
			$out[] = array(
				'code'        => $code,
				'name'        => $p['name'],
				'description' => '',
				'temp_min'    => $temp ? $temp[0] : null,
				'temp_max'    => $temp ? $temp[1] : null,
				'secured'     => 'HV' === $code ? 1 : 0,
			);
		}
		return $out;
	}

	public static function build(): array {
		global $wpdb;
		$site_t = Db::t( 'site' );
		$site   = (int) $wpdb->get_var( "SELECT id FROM {$site_t} WHERE code = '1000'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $site ) {
			$site = Db::insert( 'site', array( 'code' => '1000', 'name' => 'Centre logistique Romandie', 'address' => 'Route de l\'Industrie 12, 1023 Crissier' ) );
		}
		foreach ( self::storage_profiles() as $p ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'storage_profile' ) . ' WHERE code = %s', $p['code'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $exists ) {
				Db::insert( 'storage_profile', $p );
			}
		}
		$bins_total = 0;
		foreach ( self::locations() as $sort => $loc ) {
			$loc_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'storage_location' ) . ' WHERE site_id = %d AND code = %s', $site, $loc['code'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $loc_id ) {
				continue;
			}
			$loc_id = Db::insert(
				'storage_location',
				array(
					'site_id'       => $site,
					'code'          => $loc['code'],
					'name'          => $loc['name'],
					'zone_type'     => $loc['zone_type'],
					'location_mode' => 'DETAILED',
					'strategy'      => $loc['strategy'],
					'removal'       => $loc['removal'],
					'profiles'      => $loc['profiles'],
					'adr_classes'   => $loc['adr'] ?? '',
					'temp_min'      => isset( $loc['temp'] ) ? $loc['temp'][0] : null,
					'temp_max'      => isset( $loc['temp'] ) ? $loc['temp'][1] : null,
					'secured'       => $loc['secured'] ?? 0,
					'allow_mixed'   => $loc['mixed'] ?? 0,
					'is_transit'    => $loc['transit'] ?? 0,
					'overflow_code' => $loc['overflow'] ?? '',
					'rack_type'     => $loc['rack'],
					'notes'         => $loc['notes'] ?? '',
					'map_x'         => $loc['map'][0],
					'map_y'         => $loc['map'][1],
					'map_w'         => $loc['map'][2],
					'map_h'         => $loc['map'][3],
					'sort'          => $sort,
				)
			);
			$bins_total += self::build_bins( $loc_id, $loc );
		}
		return array( 'site_id' => $site, 'bins' => $bins_total );
	}

	private static function build_bins( int $loc_id, array $loc ): int {
		[ $aisles, $bays, $levels, $positions, $type, $weight, $volume, $length ] = $loc['bins'];
		$bay_prefix = $loc['bins'][8] ?? '';
		$rows       = array();
		$seq        = 0;
		foreach ( $aisles as $ai => $aisle ) {
			$aisle_id = Db::insert( 'aisle', array( 'storage_location_id' => $loc_id, 'code' => $aisle, 'rack_type' => $loc['rack'] ) );
			$bay_list = range( 1, $bays );
			if ( 1 === $ai % 2 ) {
				$bay_list = array_reverse( $bay_list ); // Parcours en serpentin.
			}
			foreach ( $bay_list as $b ) {
				$bay = $bay_prefix . str_pad( (string) $b, 2, '0', STR_PAD_LEFT );
				foreach ( $levels as $level ) {
					foreach ( $positions as $pos ) {
						$parts  = array_filter( array( $loc['code'], $aisle, $bay, $level, $pos ), static fn( $p ) => '' !== $p );
						$rows[] = array(
							'storage_location_id' => $loc_id,
							'aisle_id'            => $aisle_id,
							'code'                => implode( '-', $parts ),
							'aisle'               => $aisle,
							'bay'                 => $bay,
							'level'               => $level,
							'position'            => $pos,
							'bin_type'            => $type,
							'max_weight'          => $weight,
							'max_volume_l'        => $volume,
							'length_mm'           => $length,
							'pick_sequence'       => ++$seq,
						);
					}
				}
			}
		}
		Db::bulk_insert(
			'bin',
			array( 'storage_location_id', 'aisle_id', 'code', 'aisle', 'bay', 'level', 'position', 'bin_type', 'max_weight', 'max_volume_l', 'length_mm', 'pick_sequence' ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d' ),
			$rows
		);
		return count( $rows );
	}
}
