<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Gs1;
use LogiERP\Domain\Strategies;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transport et emballages (révision de données 4) : magasin des vides (950), flotte de l'entreprise
 * et véhicules des prestataires, articles d'emballage consignés, produits livrés en fût, IBC ou caisse
 * navette, exigences de livraison des clients (jours, plages, véhicule, support) et soldes d'ouverture.
 * Idempotent : chaque partie vérifie ce qui existe déjà.
 */
final class LogisticsSeeder {

	/** Coordonnées approximatives des localités (pour le calcul des tournées). */
	public const COORDS = array(
		'1003' => array( 46.5197, 6.6323 ), '1020' => array( 46.5390, 6.5880 ), '1110' => array( 46.5113, 6.4985 ), '1260' => array( 46.3833, 6.2396 ),
		'1201' => array( 46.2100, 6.1430 ), '1227' => array( 46.1810, 6.1390 ), '1400' => array( 46.7785, 6.6411 ), '1800' => array( 46.4628, 6.8419 ),
		'1820' => array( 46.4312, 6.9107 ), '1860' => array( 46.3180, 6.9700 ), '1870' => array( 46.2550, 6.9540 ), '1920' => array( 46.1020, 7.0720 ),
		'1950' => array( 46.2330, 7.3600 ), '3960' => array( 46.2920, 7.5350 ), '1700' => array( 46.8065, 7.1620 ), '1630' => array( 46.6190, 7.0570 ),
		'1530' => array( 46.8220, 6.9380 ), '2000' => array( 46.9900, 6.9290 ), '2300' => array( 47.1000, 6.8260 ), '2400' => array( 47.0570, 6.7490 ),
		'2800' => array( 47.3650, 7.3450 ), '2900' => array( 47.4160, 7.0750 ), '2502' => array( 47.1370, 7.2470 ), '3000' => array( 46.9480, 7.4470 ),
		'1510' => array( 46.6680, 6.7980 ), '1350' => array( 46.7250, 6.5310 ), '1305' => array( 46.6110, 6.5250 ), '1023' => array( 46.5450, 6.5760 ),
		'1030' => array( 46.5510, 6.5560 ), '1196' => array( 46.4210, 6.2700 ), '4000' => array( 47.5600, 7.5880 ), '8000' => array( 47.3770, 8.5400 ),
		'6000' => array( 47.0500, 8.3090 ), '1580' => array( 46.8800, 7.0410 ), '1904' => array( 46.1350, 7.0390 ),
	);

	private const PACK_IDS = array( 'EUR' => '19000001', 'CP3' => '19000002', 'FRAME' => '19000003', 'LID' => '19000004', 'DRUM' => '19000005', 'IBC' => '19000006', 'PDRUM' => '19000007', 'CRATE' => '19000008' );

	public static function run( int $dataset, int $seed ): array {
		WarehouseBuilder::build(); // ajoute le magasin 950 s'il manque
		Catalog::reset_cache();
		$v = self::vehicles();
		$m = self::materials( $dataset, $seed );
		$c = self::customers( $dataset, $seed );
		self::balances( $dataset, $seed );
		update_option( 'logierp_logistics_done', 1, false );
		return array( 'vehicles' => $v, 'materials' => $m, 'customers' => $c );
	}

	private static function vehicles(): int {
		global $wpdb;
		$t    = Db::t( 'vehicle' );
		$list = array(
			// code, nom, propriétaire, transporteur, type, charge utile, places, hayon, froid, ADR, forfait, /km, /h
			array( 'VD-512301', 'Fourgon 3,5 t (VD 512 301)', 'INTERNE', '', 'FOURGON', 1100, 4, 1, 0, 0, 0, 0.85, 55 ),
			array( 'VD-512302', 'Porteur 18 t avec hayon (VD 512 302)', 'INTERNE', '', 'PORTEUR', 9000, 16, 1, 0, 0, 0, 1.45, 62 ),
			array( 'VD-512303', 'Porteur frigorifique 12 t (VD 512 303)', 'INTERNE', '', 'PORTEUR', 5500, 12, 1, 1, 0, 0, 1.65, 62 ),
			array( 'VD-512304', 'Semi-remorque 40 t (VD 512 304)', 'INTERNE', '', 'SEMI', 25000, 33, 0, 0, 0, 0, 2.05, 68 ),
			array( 'EXT-LEMAN', 'Transports Léman SA – porteur 26 t hayon', 'EXTERNE', 'LEMAN', 'PORTEUR', 12000, 18, 1, 0, 0, 180, 2.40, 0 ),
			array( 'EXT-HFRET', 'Helvetia Fret – semi-remorque 40 t', 'EXTERNE', 'HFRET', 'SEMI', 24000, 33, 0, 0, 0, 260, 2.90, 0 ),
			array( 'EXT-FRIGO', 'Frigo Trans Romandie – porteur frigorifique', 'EXTERNE', 'FRIGO', 'PORTEUR', 8000, 15, 1, 1, 0, 220, 2.75, 0 ),
			array( 'EXT-ADR', 'Fret Dangereux Alpes – porteur ADR', 'EXTERNE', 'ADR', 'PORTEUR', 10000, 15, 1, 0, 1, 240, 2.95, 0 ),
		);
		$n = 0;
		foreach ( $list as $v ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE code = %s", $v[0] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			Db::insert( 'vehicle', array_combine( array( 'code', 'name', 'owner', 'carrier_code', 'type', 'payload_kg', 'pallet_places', 'tail_lift', 'cold', 'adr', 'cost_fixed', 'cost_km', 'cost_hour' ), $v ) );
			++$n;
		}
		return $n;
	}

	private static function ean( int $n ): string {
		$body = '76' . str_pad( (string) ( 4801000000 + $n ), 10, '0', STR_PAD_LEFT );
		return $body . Gs1::check_digit( $body );
	}

	/** Clone une fiche article d'un profil donné et remplace les champs utiles. */
	private static function add_material( int $dataset, string $profile, array $fields ): int {
		global $wpdb;
		$t = Db::t( 'material' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND material_no = %s", $dataset, $fields['material_no'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			return $id;
		}
		$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND profile_code = %s AND deleted_at IS NULL ORDER BY id LIMIT 1", $dataset, $profile ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $base ) {
			return 0;
		}
		unset( $base['id'] );
		$row = array_merge( $base, array( 'profile_code' => $profile, 'batch_managed' => 0, 'shelf_life_days' => 0, 'serial_managed' => 0, 'un_number' => '', 'adr_class' => '', 'packing_group' => '', 'hazard_text' => '', 'temp_min' => null, 'temp_max' => null, 'fragile' => 0, 'high_value' => 0, 'deposit_material_id' => 0, 'deposit_per' => 0, 'deposit_value' => 0, 'status' => 'ACTIF', 'created_at' => Db::now(), 'updated_at' => Db::now(), 'deleted_at' => null ), $fields );
		return Db::insert( 'material', $row );
	}

	private static function materials( int $dataset, int $seed ): int {
		global $wpdb;
		$n    = 0;
		$pack = array(
			// no, désignation, consigne CHF, poids kg, L×l×h mm, stock de vides
			array( '19000001', 'Palette EUR / EPAL 1200×800 (échange)', 18.00, 25, array( 1200, 800, 144 ), 380 ),
			array( '19000002', 'Palette chimique CP3 1140×1140 (consignée)', 24.00, 28, array( 1140, 1140, 156 ), 80 ),
			array( '19000003', 'Cadre de palette EUR, rehausse bois 200 mm', 9.00, 10, array( 1200, 800, 200 ), 120 ),
			array( '19000004', 'Couvercle de palette EUR', 6.50, 3, array( 1200, 800, 20 ), 120 ),
			array( '19000005', 'Fût acier 200 L consigné (vide)', 45.00, 18, array( 585, 585, 880 ), 14 ),
			array( '19000006', 'Conteneur IBC 1000 L consigné (vide)', 160.00, 65, array( 1200, 1000, 1160 ), 4 ),
			array( '19000007', 'Fût plastique 60 L consigné (vide)', 25.00, 3, array( 400, 400, 600 ), 10 ),
			array( '19000008', 'Caisse navette 600×400 consignée (bac plastique)', 12.00, 1.8, array( 600, 400, 320 ), 300 ),
		);
		$ids  = array();
		foreach ( $pack as $i => $p ) {
			$ids[ $p[0] ] = self::add_material( $dataset, 'BULKY', array( 'material_no' => $p[0], 'designation' => $p[1], 'type' => 'VERP', 'base_uom' => 'PCE', 'net_weight' => $p[3], 'gross_weight' => $p[3], 'length_mm' => $p[4][0], 'width_mm' => $p[4][1], 'height_mm' => $p[4][2], 'volume_l' => round( $p[4][0] * $p[4][1] * $p[4][2] / 1e6, 3 ), 'std_price' => $p[2], 'sale_price' => $p[2], 'deposit_value' => $p[2], 'abc' => 'C', 'min_stock' => 0, 'max_stock' => 0, 'reorder_point' => 0, 'lot_size' => 1, 'main_supplier_id' => 0, 'ean' => self::ean( $i + 1 ), 'units_per_carton' => 1, 'cartons_per_pallet' => 1 ) );
			$n++;
		}
		// Produits livrés dans un contenant consigné.
		$sup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT m.main_supplier_id FROM ' . Db::t( 'material' ) . " m WHERE m.dataset_id = %d AND m.profile_code = 'CHEM' AND m.main_supplier_id > 0 GROUP BY m.main_supplier_id ORDER BY COUNT(*) DESC LIMIT 1", $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prod = array(
			array( '19100001', 'Huile hydraulique HLP 46, fût acier 200 L', 'HEAVY', 'FUT', 190, array( 585, 585, 880 ), 412.00, '19000005', '', '', '', '', 1825, 18 ),
			array( '19100002', 'Diluant nitrocellulosique, fût acier 200 L', 'CHEM', 'FUT', 182, array( 585, 585, 880 ), 486.00, '19000005', '1263', '3', 'II', 'Matières apparentées aux peintures, inflammables', 730, 10 ),
			array( '19100003', 'AdBlue (urée 32,5 %), conteneur IBC 1000 L', 'HEAVY', 'IBC', 1150, array( 1200, 1000, 1160 ), 395.00, '19000006', '', '', '', '', 365, 6 ),
			array( '19100004', 'Acide phosphorique 75 %, fût plastique 60 L', 'CHEM', 'FUT', 98, array( 400, 400, 600 ), 168.00, '19000007', '1805', '8', 'III', 'Acide phosphorique en solution, corrosif', 1095, 12 ),
			array( '19100005', 'Nettoyant industriel neutre, conteneur IBC 1000 L', 'HEAVY', 'IBC', 1080, array( 1200, 1000, 1160 ), 1240.00, '19000006', '', '', '', '', 730, 5 ),
		);
		$rng = Rng::for( $seed, 4400 );
		foreach ( $prod as $i => $p ) {
			$mid = self::add_material( $dataset, $p[2], array( 'material_no' => $p[0], 'designation' => $p[1], 'type' => 'MRCH', 'base_uom' => $p[3], 'net_weight' => $p[4] - ( 'IBC' === $p[3] ? 65 : 18 ), 'gross_weight' => $p[4], 'length_mm' => $p[5][0], 'width_mm' => $p[5][1], 'height_mm' => $p[5][2], 'volume_l' => round( $p[5][0] * $p[5][1] * $p[5][2] / 1e6, 3 ), 'std_price' => $p[6], 'sale_price' => round( $p[6] * 1.38, 2 ), 'deposit_material_id' => (int) $ids[ $p[7] ], 'deposit_per' => 1, 'un_number' => $p[8], 'adr_class' => $p[9], 'packing_group' => $p[10], 'hazard_text' => $p[11], 'batch_managed' => 1, 'shelf_life_days' => $p[12], 'abc' => 'B', 'min_stock' => 2, 'reorder_point' => 4, 'max_stock' => $p[13] + 6, 'lot_size' => 4, 'lead_time_days' => 4, 'main_supplier_id' => $sup, 'ean' => self::ean( 20 + $i ), 'units_per_carton' => 1, 'cartons_per_pallet' => 'IBC' === $p[3] ? 1 : ( $p[4] < 120 ? 2 : 4 ), 'stackable' => 0 ) );
			if ( ! $mid ) {
				continue;
			}
			$n++;
			if ( $sup && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'supplier_material' ) . ' WHERE supplier_id = %d AND material_id = %d', $sup, $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Db::insert( 'supplier_material', array( 'supplier_id' => $sup, 'material_id' => $mid, 'supplier_ref' => 'R-' . substr( $p[0], 3 ), 'price' => $p[6], 'min_qty' => 1, 'lead_time_days' => 4, 'is_main' => 1 ) );
			}
			self::place_stock( $mid, $p[13], $rng, $p[12] );
		}
		// Produits frais livrés en caisses navettes consignées (restaurants, laboratoires).
		$crate = (int) $ids['19000008'];
		$mt    = Db::t( 'material' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$mt} SET deposit_material_id = %d, deposit_per = GREATEST(1, units_per_carton) WHERE dataset_id = %d AND profile_code = 'COLD' AND deposit_material_id = 0 AND gross_weight <= 15 ORDER BY material_no LIMIT 40", $crate, $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Stock de vides au magasin 950.
		$loc = Catalog::location_by_code( '950' );
		$bin = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bin' ) . ' WHERE storage_location_id = %d ORDER BY code LIMIT 1', (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$st  = Db::t( 'stock' );
		foreach ( $pack as $p ) {
			$mid = (int) $ids[ $p[0] ];
			if ( $mid && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE session_id = 0 AND material_id = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::insert( 'stock', array( 'session_id' => 0, 'material_id' => $mid, 'storage_location_id' => (int) $loc['id'], 'bin_id' => $bin, 'batch' => '', 'bbd' => null, 'status' => 'LIBRE', 'qty' => $p[5], 'gr_date' => StockSeeder::base_date() ) );
			}
		}
		Catalog::reset_cache();
		return $n;
	}

	private static function place_stock( int $mid, int $qty, Rng $rng, int $life ): void {
		global $wpdb;
		$st = Db::t( 'stock' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE session_id = 0 AND material_id = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		Catalog::reset_cache();
		$m    = Catalog::material( $mid );
		$left = $qty;
		$lots = 0;
		while ( $left > 0 && $lots < 6 ) {
			$q     = (int) min( $left, max( 1, (int) $m['cartons_per_pallet'] ) );
			$batch = 'L' . gmdate( 'ymd', strtotime( StockSeeder::base_date() . ' -' . ( 20 + 15 * $lots ) . ' days' ) ) . chr( 65 + $lots );
			$p = null;
			foreach ( array_unique( array( $q, 1 ) ) as $try ) {
				try {
					$p = Strategies::propose_putaway( 0, $m, $try, $batch, $rng );
					$q = $try;
					break;
				} catch ( \Throwable $e ) {
					$p = null;
				}
			}
			if ( ! $p ) {
				return;
			}
			Db::insert( 'stock', array( 'session_id' => 0, 'material_id' => $mid, 'storage_location_id' => (int) $p['location']['id'], 'bin_id' => (int) $p['bin']['id'], 'batch' => $batch, 'bbd' => gmdate( 'Y-m-d', strtotime( StockSeeder::base_date() . ' +' . max( 60, $life - 30 - 15 * $lots ) . ' days' ) ), 'status' => 'LIBRE', 'qty' => $q, 'gr_date' => gmdate( 'Y-m-d', strtotime( StockSeeder::base_date() . ' -' . ( 20 + 15 * $lots ) . ' days' ) ) ) );
			$left -= $q;
			++$lots;
		}
	}

	/** Exigences de livraison selon le type de client, coordonnées de la localité. */
	private static function customers( int $dataset, int $seed ): int {
		global $wpdb;
		$t    = Db::t( 'customer' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, zip FROM {$t} WHERE dataset_id = %d AND lat IS NULL", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$rng = Rng::for( $seed, 5000 + (int) $r['id'] );
			[ $days, $win, $pal, $veh, $app ] = self::profile_for( (string) $r['type'], $rng );
			$co = self::COORDS[ $r['zip'] ] ?? array( 46.52, 6.63 );
			$wpdb->update(
				$t,
				array( 'delivery_days' => $days, 'window_from' => $win[0], 'window_to' => $win[1], 'delivery_window' => '' !== $win[0] ? $win[0] . '–' . $win[1] : 'Toute la journée', 'pallet_type' => $pal, 'vehicle_req' => $veh, 'appointment' => $app, 'lat' => $co[0] + $rng->range( -0.006, 0.006 ), 'lon' => $co[1] + $rng->range( -0.008, 0.008 ) ),
				array( 'id' => (int) $r['id'] )
			);
		}
		return count( $rows );
	}

	private static function profile_for( string $type, Rng $rng ): array {
		switch ( $type ) {
			case 'Commerce de détail':
				return array( '1,2,3,4,5,6', $rng->pick( array( array( '07:00', '10:00' ), array( '08:00', '11:30' ) ) ), 'EUR', $rng->pick( array( 'PORTEUR', 'PORTEUR', 'HAYON' ) ), 0 );
			case 'Grossiste / distributeur':
				return array( '1,2,3,4,5', array( '06:00', '14:00' ), 'EUR', '', 1 );
			case 'Industrie / atelier':
				return array( '1,2,3,4,5', $rng->pick( array( array( '07:00', '16:00' ), array( '07:30', '11:45' ) ) ), $rng->chance( 0.3 ) ? 'CP3' : 'EUR', $rng->pick( array( '', '', 'HAYON' ) ), 0 );
			case 'Construction / bâtiment':
				return array( '1,2,3,4,5', array( '07:00', '15:00' ), 'PERDUE', 'PORTEUR', 0 );
			case 'Santé / laboratoire':
				return array( '1,2,3,4,5', array( '07:00', '10:00' ), 'EUR', 'HAYON', 0 );
			case 'Restauration / hôtellerie':
				return array( $rng->pick( array( '2,4,5', '1,3,5', '1,2,3,4,5' ) ), $rng->pick( array( array( '09:00', '11:00' ), array( '14:00', '16:00' ) ) ), '', $rng->pick( array( 'FOURGON', 'HAYON' ) ), 0 );
		}
		return array( '1,2,3,4,5,6', array( '', '' ), 'COLIS', '', 0 );
	}

	/** Soldes d'emballages chez les clients au démarrage (livraisons passées). */
	private static function balances( int $dataset, int $seed ): void {
		global $wpdb;
		$a = Db::t( 'packaging_account' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE session_id = 0" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$ids = array();
		foreach ( self::PACK_IDS as $k => $no ) {
			$m = Catalog::material_by_no( $no, $dataset );
			if ( $m ) {
				$ids[ $k ] = (int) $m['id'];
			}
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, pallet_type FROM ' . Db::t( 'customer' ) . ' WHERE dataset_id = %d ORDER BY id', $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $r ) {
			$rng = Rng::for( $seed, 6000 + (int) $r['id'] );
			$add = array();
			if ( 'EUR' === $r['pallet_type'] && $rng->chance( 0.6 ) ) {
				$add['EUR'] = $rng->int( 2, 14 );
				if ( $rng->chance( 0.3 ) ) {
					$add['FRAME'] = $rng->int( 2, 8 );
					$add['LID']   = $add['FRAME'];
				}
			}
			if ( 'CP3' === $r['pallet_type'] ) {
				$add['CP3']   = $rng->int( 2, 8 );
				$add['DRUM']  = $rng->int( 1, 4 );
				$add['PDRUM'] = $rng->int( 0, 3 );
			}
			if ( 'Industrie / atelier' === $r['type'] && $rng->chance( 0.4 ) ) {
				$add['IBC']  = $rng->int( 1, 2 );
				$add['DRUM'] = ( $add['DRUM'] ?? 0 ) + $rng->int( 1, 3 );
			}
			if ( in_array( $r['type'], array( 'Restauration / hôtellerie', 'Santé / laboratoire' ), true ) ) {
				$add['CRATE'] = $rng->int( 6, 30 );
			}
			foreach ( $add as $k => $q ) {
				if ( $q > 0 && isset( $ids[ $k ] ) ) {
					Db::insert( 'packaging_account', array( 'session_id' => 0, 'customer_id' => (int) $r['id'], 'material_id' => $ids[ $k ], 'qty' => $q ) );
				}
			}
		}
	}
}
