<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Gs1;
use LogiERP\Domain\NumberRange;
use LogiERP\Domain\Strategies;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chimie de procédé et procédures SAP de l'entreprise (révision de données 6) :
 * citernes de vrac (310) et aire des écarts (999), produits en vrac au kilo avec pertes de citerne,
 * emballages de conditionnement (fûts neufs et lavés, bidons, GRV), conteneurs GRV loués (articles X
 * suivis par numéro), produits finis fabriqués par ordre de procédé, site cédant interne (UMLAGERUNG)
 * et commandes ouvertes correspondantes dans le fond de décor.
 * Idempotent : chaque partie vérifie ce qui existe déjà. Aucune donnée réelle : noms et numéros fictifs.
 */
final class ProcessSeeder {

	/** Gestionnaires de production (filtre de COR8). */
	public const SUPERVISORS = array(
		'250' => 'Acides balance 1',
		'251' => 'Acides balance 2',
		'252' => 'Acides spéciaux',
		'253' => 'Spécialités',
		'254' => 'Désinfection / piscine / H2O2',
		'255' => 'Solvants détail',
		'256' => 'Solvants fûts',
		'257' => 'Poudres',
		'258' => 'Sels toxiques',
		'259' => 'Laboratoire',
	);

	/** Site cédant des transferts entre sites (UMLAGERUNG), fictif. */
	public const SUPPLY_SITE      = '1100';
	public const SUPPLY_SITE_NAME = 'Usine Nord (site de production)';
	public const SITE_SUPPLIER    = 'W1100';
	public const SITE_CUSTOMER    = 'W1200';

	/** Conteneur GRV loué (article X) et article propre correspondant. */
	public const RENTED_IBC = 'X39000013';
	public const OWN_IBC    = '39000013';

	public static function run( int $dataset, int $seed ): array {
		WarehouseBuilder::build(); // citernes 310 et aire des écarts 999
		Catalog::reset_cache();
		$m = self::materials( $dataset );
		Catalog::reset_cache();
		$b = self::boms();
		$p = self::partners( $dataset );
		$s = self::stock( $seed );
		$c = self::containers( $dataset );
		$d = self::documents( $dataset );
		Catalog::reset_cache();
		update_option( 'logierp_process_done', 1, false );
		return array( 'materials' => $m, 'boms' => $b, 'partners' => $p, 'stock' => $s, 'containers' => $c, 'documents' => $d );
	}

	private static function ean( int $n ): string {
		$body = '76' . str_pad( (string) ( 4839000000 + $n ), 10, '0', STR_PAD_LEFT );
		return $body . Gs1::check_digit( $body );
	}

	/** Copie la fiche d'un article du même profil et remplace les champs utiles. */
	private static function add_material( int $dataset, string $profile, array $fields ): int {
		global $wpdb;
		$t  = Db::t( 'material' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND material_no = %s", $dataset, $fields['material_no'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			return $id;
		}
		$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND profile_code = %s AND deleted_at IS NULL ORDER BY id LIMIT 1", $dataset, $profile ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $base ) {
			$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND deleted_at IS NULL ORDER BY id LIMIT 1", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $base ) {
			return 0;
		}
		unset( $base['id'] );
		$defaults = array(
			'profile_code' => $profile, 'batch_managed' => 0, 'shelf_life_days' => 0, 'serial_managed' => 0, 'un_number' => '', 'adr_class' => '', 'packing_group' => '', 'hazard_text' => '',
			'temp_min' => null, 'temp_max' => null, 'fragile' => 0, 'high_value' => 0, 'stackable' => 0, 'deposit_material_id' => 0, 'deposit_per' => 0, 'deposit_value' => 0,
			'bulk' => 0, 'tank_loss_truck' => 0, 'tank_loss_wagon' => 0, 'cert_required' => 0, 'prod_supervisor' => '', 'own_material_id' => 0,
			'abc' => 'B', 'xyz' => 'Y', 'min_stock' => 0, 'max_stock' => 0, 'reorder_point' => 0, 'lot_size' => 1, 'lead_time_days' => 5, 'main_supplier_id' => 0,
			'units_per_carton' => 1, 'cartons_per_pallet' => 1, 'status' => 'ACTIF', 'created_at' => Db::now(), 'updated_at' => Db::now(), 'deleted_at' => null,
		);
		return Db::insert( 'material', array_merge( $base, $defaults, $fields ) );
	}

	private static function chem_supplier( int $dataset ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'supplier' ) . " WHERE dataset_id = %d AND specialty = 'Chimie industrielle' AND country = 'CH' AND active = 1 ORDER BY id LIMIT 1", $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Articles : vrac (kg), emballages, conteneur loué, produits finis. */
	private static function materials( int $dataset ): int {
		global $wpdb;
		$sup  = self::chem_supplier( $dataset );
		$n    = 0;
		$grp  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM ' . Db::t( 'material' ) . " WHERE dataset_id = %d AND profile_code = 'CHEM' GROUP BY group_id ORDER BY COUNT(*) DESC LIMIT 1", $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$emb  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM ' . Db::t( 'material' ) . " WHERE dataset_id = %d AND type = 'EMB' GROUP BY group_id ORDER BY COUNT(*) DESC LIMIT 1", $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// no, désignation, ADR, ONU, groupe, danger, densité (kg/L), prix CHF/kg, perte camion, perte wagon
		$bulk = array(
			array( '39000001', 'Acide sulfurique 96 %, vrac', '8', '1830', 'II', 'Acide sulfurique, corrosif', 1.84, 0.32, 40, 100 ),
			array( '39000002', 'Soude caustique 50 %, vrac', '8', '1824', 'II', 'Hydroxyde de sodium en solution, corrosif', 1.52, 0.41, 40, 100 ),
			array( '39000003', 'Eau oxygénée 35 %, vrac', '5.1', '2014', 'II', 'Peroxyde d\'hydrogène en solution aqueuse, comburant', 1.13, 0.62, 100, 100 ),
			array( '39000004', 'Éthanol 96 %, vrac', '3', '1170', 'II', 'Éthanol, liquide inflammable', 0.81, 0.95, 40, 100 ),
		);
		foreach ( $bulk as $i => $b ) {
			$id = self::add_material( $dataset, 'CHEM', array( 'material_no' => $b[0], 'designation' => $b[1], 'group_id' => $grp, 'type' => 'MRCH', 'base_uom' => 'KG', 'adr_class' => $b[2], 'un_number' => $b[3], 'packing_group' => $b[4], 'hazard_text' => $b[5], 'net_weight' => 1, 'gross_weight' => 1, 'length_mm' => 0, 'width_mm' => 0, 'height_mm' => 0, 'volume_l' => round( 1 / $b[6], 3 ), 'std_price' => $b[7], 'sale_price' => round( $b[7] * 1.45, 4 ), 'batch_managed' => 1, 'shelf_life_days' => 0, 'bulk' => 1, 'tank_loss_truck' => $b[8], 'tank_loss_wagon' => $b[9], 'cert_required' => 1, 'min_stock' => 2000, 'reorder_point' => 6000, 'max_stock' => 50000, 'lot_size' => 24000, 'lead_time_days' => 3, 'main_supplier_id' => $sup, 'ean' => self::ean( 1 + $i ) ) );
			$n += $id ? 1 : 0;
			self::info_record( $sup, $id, $b[7], 5000, 3 );
		}
		// Emballages de conditionnement.
		$pack = array(
			array( '39000010', 'Fût PEHD 220 L neuf, homologué UN', 9.5, array( 600, 600, 935 ), 38.00 ),
			array( '39000011', 'Fût PEHD 220 L lavé (reconditionné)', 9.5, array( 600, 600, 935 ), 14.00 ),
			array( '39000012', 'Bidon PEHD 25 L neuf, homologué UN', 1.3, array( 300, 250, 420 ), 4.20 ),
			array( '39000013', 'GRV 1000 L (conteneur vrac intermédiaire), en propre', 62, array( 1200, 1000, 1160 ), 285.00 ),
			array( '39000014', 'Étiquette produit avec pictogrammes SGH', 0.01, array( 150, 100, 1 ), 0.18 ),
		);
		foreach ( $pack as $i => $p ) {
			$id = self::add_material( $dataset, 'BULKY', array( 'material_no' => $p[0], 'designation' => $p[1], 'group_id' => $emb, 'type' => 'EMB', 'base_uom' => 'PCE', 'net_weight' => $p[2], 'gross_weight' => $p[2], 'length_mm' => $p[3][0], 'width_mm' => $p[3][1], 'height_mm' => $p[3][2], 'volume_l' => round( $p[3][0] * $p[3][1] * $p[3][2] / 1e6, 3 ), 'std_price' => $p[4], 'sale_price' => $p[4], 'min_stock' => 5, 'reorder_point' => 10, 'max_stock' => 200, 'lot_size' => 20, 'main_supplier_id' => $sup, 'ean' => self::ean( 10 + $i ) ) );
			$n += $id ? 1 : 0;
			self::info_record( $sup, $id, $p[4], 1, 5 );
		}
		// Conteneur GRV loué au client (article X, suivi par numéro de conteneur).
		$own = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'material' ) . ' WHERE dataset_id = %d AND material_no = %s', $dataset, self::OWN_IBC ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$id  = self::add_material( $dataset, 'BULKY', array( 'material_no' => self::RENTED_IBC, 'designation' => 'GRV 1000 L loué (consigne client, suivi par n° de conteneur)', 'group_id' => $emb, 'type' => 'VERP', 'base_uom' => 'PCE', 'net_weight' => 62, 'gross_weight' => 62, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1160, 'volume_l' => 1392, 'std_price' => 250, 'sale_price' => 250, 'deposit_value' => 250, 'serial_managed' => 1, 'batch_managed' => 1, 'own_material_id' => $own, 'ean' => self::ean( 20 ) ) );
		$n  += $id ? 1 : 0;
		// Produits finis fabriqués par ordre de procédé.
		$fin = array(
			array( '39000101', 'Acide sulfurique 37 %, fût PEHD 220 L (250 kg)', '8', '2796', 'II', 'Acide sulfurique, corrosif', 262, array( 600, 600, 935 ), 118.00, '251' ),
			array( '39000102', 'Soude caustique 25 %, bidon 25 L (32 kg)', '8', '1824', 'II', 'Hydroxyde de sodium en solution, corrosif', 33.5, array( 300, 250, 420 ), 21.50, '250' ),
			array( '39000103', 'Eau oxygénée 12 %, GRV 1000 L (1040 kg)', '5.1', '2984', 'III', 'Peroxyde d\'hydrogène en solution aqueuse', 1105, array( 1200, 1000, 1160 ), 690.00, '254' ),
		);
		$ibc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'material' ) . ' WHERE dataset_id = %d AND material_no = %s', $dataset, self::RENTED_IBC ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $fin as $i => $f ) {
			$id = self::add_material( $dataset, 'CHEM', array( 'material_no' => $f[0], 'designation' => $f[1], 'group_id' => $grp, 'type' => 'PF', 'base_uom' => 'PCE', 'adr_class' => $f[2], 'un_number' => $f[3], 'packing_group' => $f[4], 'hazard_text' => $f[5], 'net_weight' => $f[6] - 10, 'gross_weight' => $f[6], 'length_mm' => $f[7][0], 'width_mm' => $f[7][1], 'height_mm' => $f[7][2], 'volume_l' => round( $f[7][0] * $f[7][1] * $f[7][2] / 1e6, 3 ), 'std_price' => $f[8], 'sale_price' => round( $f[8] * 1.4, 2 ), 'batch_managed' => 1, 'shelf_life_days' => 730, 'cert_required' => 1, 'prod_supervisor' => $f[9], 'min_stock' => 2, 'reorder_point' => 4, 'max_stock' => 40, 'lot_size' => 8, 'lead_time_days' => 2, 'stackable' => 0, 'deposit_material_id' => '39000103' === $f[0] ? $ibc : 0, 'deposit_per' => '39000103' === $f[0] ? 1 : 0, 'ean' => self::ean( 30 + $i ) ) );
			$n += $id ? 1 : 0;
		}
		// Sacs livrés par le site cédant (transfert entre sites).
		$id = self::add_material( $dataset, 'PAL', array( 'material_no' => '39000020', 'designation' => 'Hydroxyde de calcium (chaux éteinte), sac 25 kg', 'group_id' => $grp, 'type' => 'MRCH', 'base_uom' => 'PCE', 'net_weight' => 25, 'gross_weight' => 25.2, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 120, 'volume_l' => 28.8, 'std_price' => 9.40, 'sale_price' => 14.20, 'batch_managed' => 1, 'shelf_life_days' => 0, 'units_per_carton' => 1, 'cartons_per_pallet' => 40, 'stackable' => 1, 'min_stock' => 40, 'reorder_point' => 80, 'max_stock' => 400, 'lot_size' => 40, 'ean' => self::ean( 40 ) ) );
		$n += $id ? 1 : 0;
		return $n;
	}

	private static function info_record( int $supplier_id, int $material_id, float $price, float $min, int $lead ): void {
		global $wpdb;
		if ( ! $supplier_id || ! $material_id ) {
			return;
		}
		$t = Db::t( 'supplier_material' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE supplier_id = %d AND material_id = %d", $supplier_id, $material_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		Db::insert( 'supplier_material', array( 'supplier_id' => $supplier_id, 'material_id' => $material_id, 'supplier_ref' => 'P-' . $material_id, 'price' => $price, 'min_qty' => $min, 'lead_time_days' => $lead, 'is_main' => 1 ) );
	}

	private static function mat( string $no ): int {
		$m = Catalog::material_by_no( $no );
		return $m ? (int) $m['id'] : 0;
	}

	/** Recettes (nomenclatures de procédé) des produits finis. */
	private static function boms(): int {
		global $wpdb;
		$recipes = array(
			'39000101' => array( 'Acides balance 2', 6.0, array( array( '39000001', 100.5, 0 ), array( '39000011', 1, 0 ), array( '39000014', 1, 0 ) ) ),
			'39000102' => array( 'Acides balance 1', 1.5, array( array( '39000002', 16.0, 0 ), array( '39000012', 1, 0 ), array( '39000014', 1, 0 ) ) ),
			'39000103' => array( 'Désinfection / piscine / H2O2', 12.0, array( array( '39000003', 357.0, 0 ), array( self::OWN_IBC, 1, 0 ), array( '39000014', 1, 0 ) ) ),
		);
		$n = 0;
		foreach ( $recipes as $no => [ $wc, $min, $items ] ) {
			$mid = self::mat( (string) $no );
			if ( ! $mid || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bom' ) . ' WHERE material_id = %d', $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				continue;
			}
			$bom = Db::insert( 'bom', array( 'material_id' => $mid, 'version' => 'R1', 'work_center' => $wc, 'minutes_per_unit' => $min ) );
			foreach ( $items as [ $cno, $qty, $scrap ] ) {
				$cid = self::mat( (string) $cno );
				if ( $cid ) {
					Db::insert( 'bom_item', array( 'bom_id' => $bom, 'component_id' => $cid, 'qty' => $qty, 'scrap_pct' => $scrap ) );
				}
			}
			++$n;
		}
		return $n;
	}

	/** Site cédant interne : fournisseur (réceptions UMLAGERUNG) et client (expéditions UMLAGERUNG). */
	private static function partners( int $dataset ): int {
		global $wpdb;
		$n  = 0;
		$st = Db::t( 'supplier' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE dataset_id = %d AND supplier_no = %s", $dataset, self::SITE_SUPPLIER ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$st} WHERE dataset_id = %d AND country = 'CH' ORDER BY id LIMIT 1", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $base ) {
				unset( $base['id'] );
				Db::insert( 'supplier', array_merge( $base, array( 'supplier_no' => self::SITE_SUPPLIER, 'name' => self::SUPPLY_SITE_NAME . ' – site cédant', 'specialty' => 'Site interne (UMLAGERUNG)', 'street' => 'Chemin des Usines 4', 'zip' => '1860', 'city' => 'Aigle', 'country' => 'CH', 'contact' => 'Planification des transferts', 'email' => 'transferts@usine-nord.example', 'phone' => '+41 24 000 00 00', 'incoterm' => 'FCA', 'lead_time_days' => 1, 'reliability' => 98, 'is_problematic' => 0, 'active' => 1 ) ) );
				++$n;
			}
		}
		$ct = Db::t( 'customer' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE dataset_id = %d AND customer_no = %s", $dataset, self::SITE_CUSTOMER ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE dataset_id = %d AND type = 'Industrie / atelier' ORDER BY id LIMIT 1", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $base ) {
				unset( $base['id'] );
				Db::insert( 'customer', array_merge( $base, array( 'customer_no' => self::SITE_CUSTOMER, 'name' => 'Usine Sud (site interne, transfert UMLAGERUNG)', 'type' => 'Site interne', 'street' => 'Route du Rhône 2', 'zip' => '1870', 'city' => 'Monthey', 'country' => 'CH', 'email' => 'reception@usine-sud.example', 'priority' => 'B', 'credit_limit' => 0, 'blocked' => 0, 'active' => 1, 'packing_req' => 'Transfert interne : paquets faits automatiquement', 'pallet_type' => '' ) ) );
				++$n;
			}
		}
		return $n;
	}

	/** Stock de départ (fond de décor) : citernes, emballages, produits finis, sacs. */
	private static function stock( int $seed ): int {
		global $wpdb;
		$st   = Db::t( 'stock' );
		$base = StockSeeder::base_date();
		$n    = 0;
		$tank = static function ( string $code ): ?array {
			return Catalog::bin_by_code( $code );
		};
		// Citernes : une citerne par produit ; 310-T-05 et 310-T-06 restent libres.
		$tanks = array(
			array( '39000001', '310-T-01', '0000581011', 18400, 48 ),
			array( '39000002', '310-T-02', '0000581027', 9600, 30 ),
			array( '39000003', '310-T-03', '0000581034', 6100, 22 ),
			array( '39000004', '310-T-04', '0000581046', 7200, 15 ),
		);
		foreach ( $tanks as [ $no, $bin_code, $batch, $qty, $age ] ) {
			$mid = self::mat( $no );
			$bin = $tank( $bin_code );
			if ( ! $mid || ! $bin || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE session_id = 0 AND material_id = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			Db::insert( 'stock', array( 'session_id' => 0, 'material_id' => $mid, 'storage_location_id' => (int) $bin['storage_location_id'], 'bin_id' => (int) $bin['id'], 'batch' => $batch, 'bbd' => null, 'status' => 'LIBRE', 'special_ref' => '', 'qty' => $qty, 'gr_date' => gmdate( 'Y-m-d', strtotime( $base . ' -' . $age . ' days' ) ) ) );
			++$n;
		}
		// Emballages, produits finis et sacs : emplacements proposés par la stratégie de rangement.
		$rng   = Rng::for( $seed, 6600 );
		// no, quantité, lot, DLC (jours), unités par emplacement
		$items = array(
			array( '39000010', 40, '', 0, 8 ),
			array( '39000011', 6, '', 0, 6 ),
			array( '39000012', 120, '', 0, 60 ),
			array( self::OWN_IBC, 8, '', 0, 2 ),
			array( '39000014', 400, '', 0, 400 ),
			array( '39000101', 12, '0000582101', 300, 3 ),
			array( '39000102', 48, '0000582117', 420, 8 ),
			array( '39000103', 3, '0000582123', 500, 1 ),
			array( '39000020', 80, '0000580990', 0, 40 ),
		);
		foreach ( $items as [ $no, $qty, $batch, $life, $per ] ) {
			$m = Catalog::material_by_no( (string) $no );
			if ( ! $m || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$st} WHERE session_id = 0 AND material_id = %d", (int) $m['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			$left  = $qty;
			$guard = 0;
			while ( $left > 0 && $guard++ < 40 ) {
				$q = (int) min( $left, $per );
				$p = null;
				foreach ( array_unique( array( $q, max( 1, (int) floor( $q / 2 ) ), 1 ) ) as $try ) {
					try {
						$p = Strategies::propose_putaway( 0, $m, (float) $try, $batch, $rng );
						$q = $try;
						break;
					} catch ( \Throwable $e ) {
						$p = null;
					}
				}
				if ( ! $p ) {
					break;
				}
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$st} (session_id, material_id, storage_location_id, bin_id, batch, bbd, status, special_ref, qty, gr_date) VALUES (0, %d, %d, %d, %s, %s, 'LIBRE', '', %f, %s) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)", (int) $m['id'], (int) $p['location']['id'], (int) $p['bin']['id'], $batch, $life ? gmdate( 'Y-m-d', strtotime( $base . ' +' . $life . ' days' ) ) : null, $q, gmdate( 'Y-m-d', strtotime( $base . ' -12 days' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$left -= $q;
				++$n;
			}
		}
		return $n;
	}

	/** Conteneurs GRV loués : douze numéros, une partie chez des clients industriels (fond de décor). */
	private static function containers( int $dataset ): int {
		global $wpdb;
		$mid = self::mat( self::RENTED_IBC );
		$ct  = Db::t( 'container' );
		if ( ! $mid || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE session_id = 0 AND material_id = %d", $mid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 0;
		}
		$cust = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'customer' ) . " WHERE dataset_id = %d AND type = 'Industrie / atelier' AND blocked = 0 ORDER BY id LIMIT 4", $dataset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n   = 0;
		for ( $i = 1; $i <= 12; $i++ ) {
			$serial = (string) ( 7100000 + $i );
			$at     = $i <= 8 && $cust ? $cust[ ( $i - 1 ) % count( $cust ) ] : 0;
			Db::insert( 'container', array( 'session_id' => 0, 'material_id' => $mid, 'serial' => $serial, 'holder' => $at ? 'CLIENT' : 'NOUS', 'customer_id' => $at, 'state' => $at ? 'PLEIN' : 'VIDE', 'ref_no' => '', 'updated_at' => Db::now() ) );
			if ( $at ) {
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'packaging_account' ) . ' (session_id, customer_id, material_id, qty) VALUES (0, %d, %d, 1) ON DUPLICATE KEY UPDATE qty = qty + 1', $at, $mid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			++$n;
		}
		return $n;
	}

	/** Commandes ouvertes du fond de décor : vrac par camion et par wagon, transfert entre sites. */
	private static function documents( int $dataset ): int {
		global $wpdb;
		$po  = Db::t( 'purchase_order' );
		$n   = 0;
		$sup = self::chem_supplier( $dataset );
		$w   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'supplier' ) . ' WHERE dataset_id = %d AND supplier_no = %s', $dataset, self::SITE_SUPPLIER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( "SELECT id FROM {$po} WHERE session_id = 0 AND (transport <> '' OR po_type = 'UB') LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 0;
		}
		$base = StockSeeder::base_date();
		$docs = array(
			array( $sup, 'NB', 'CAMION', '39000001', 25050, 'Vrac par camion-citerne : dépotage en citerne, perte de citerne à la confirmation (LT12).', '' ),
			array( $sup, 'NB', 'WAGON', '39000002', 30100, 'Vrac par wagon-citerne : voir le portail transporteur (ZRAIL) pour l\'arrivée du wagon.', '' ),
			array( $w, 'UB', '', '39000020', 40, 'Transfert entre sites (UMLAGERUNG) depuis l\'Usine Nord : contrôlez le TUS (sacs).', 'IB' ),
		);
		foreach ( $docs as [ $sid, $type, $transport, $mno, $qty, $note, $ib ] ) {
			$mid = self::mat( $mno );
			if ( ! $sid || ! $mid ) {
				continue;
			}
			$no    = NumberRange::next( 0, 'PO' );
			$po_id = Db::insert( 'purchase_order', array( 'session_id' => 0, 'po_no' => $no, 'supplier_id' => $sid, 'order_date' => gmdate( 'Y-m-d', strtotime( $base . ' -6 days' ) ), 'status' => 'ENVOYEE', 'note' => $note, 'po_type' => $type, 'supply_site' => 'UB' === $type ? self::SUPPLY_SITE : '', 'ib_dlv_no' => $ib ? NumberRange::next( 0, 'IBD' ) : '', 'transport' => $transport, 'created_at' => Db::now() ) );
			$m     = Catalog::material( $mid );
			Db::insert( 'purchase_order_item', array( 'po_id' => $po_id, 'line_no' => 10, 'material_id' => $mid, 'qty' => $qty, 'price' => (float) $m['std_price'], 'delivery_date' => $base ) );
			++$n;
		}
		return $n;
	}
}
