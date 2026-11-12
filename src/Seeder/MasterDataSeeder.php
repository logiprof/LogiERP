<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Données de base du dataset système : partenaires, transporteurs, articles, fiches d'info-achat, nomenclatures.
 */
final class MasterDataSeeder {

	public const DATASET_CODE = 'BASE';

	public static function dataset_id( int $seed ): int {
		global $wpdb;
		$t  = Db::t( 'dataset' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE code = %s", self::DATASET_CODE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			return $id;
		}
		return Db::insert(
			'dataset',
			array(
				'code'       => self::DATASET_CODE,
				'name'       => 'Base LogiERP',
				'is_system'  => 1,
				'seed'       => $seed,
				'created_at' => Db::now(),
				'updated_at' => Db::now(),
			)
		);
	}

	/* ─────────────────────────── Partenaires ─────────────────────────── */

	public static function partners( int $dataset, int $seed ): array {
		global $wpdb;
		$rng = Rng::for( $seed, 1 );
		$t   = Db::t( 'supplier' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE dataset_id = %d", $dataset ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'suppliers' => 0, 'customers' => 0 );
		}

		// Transporteurs.
		foreach ( Dictionaries::carriers() as $c ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'carrier' ) . ' WHERE code = %s', $c[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $exists ) {
				Db::insert( 'carrier', array( 'code' => $c[0], 'name' => $c[1], 'type' => $c[2], 'max_weight' => $c[3] ) );
			}
		}

		// Fournisseurs : tous situés en Suisse.
		$countries = $rng->shuffle( array_fill( 0, 100, 'CH' ) );
		$roots     = $rng->shuffle( Dictionaries::company_roots() );
		$suffixes  = Dictionaries::company_suffixes();
		$used      = array();
		$rows      = array();
		$n         = 0;
		$problem   = array( 7, 23, 41, 58, 86 );
		foreach ( Dictionaries::supplier_specialties() as $spec ) {
			for ( $i = 0; $i < $spec[2]; $i++ ) {
				$country = $countries[ $n ];
				do {
					$name = $rng->pick( $roots ) . ' ' . $rng->pick( $suffixes ) . ' ' . $rng->pick( Dictionaries::legal_forms( $country ) );
				} while ( isset( $used[ $name ] ) );
				$used[ $name ] = true;
				$city          = 'CH' === $country ? $rng->pick( Dictionaries::swiss_cities() ) : $rng->pick( Dictionaries::foreign_cities()[ $country ] );
				$slug          = sanitize_title( strtok( $name, ' ' ) );
				$is_problem    = in_array( $n, $problem, true );
				$rows[]        = array(
					'dataset_id'     => $dataset,
					'supplier_no'    => 'F' . ( 20001 + $n ),
					'name'           => $name,
					'specialty'      => $spec[0],
					'street'         => $rng->pick( Dictionaries::streets() ) . ' ' . $rng->int( 1, 80 ),
					'zip'            => $city[0],
					'city'           => $city[1],
					'country'        => $country,
					'contact'        => $rng->pick( Dictionaries::contacts() ),
					'email'          => 'commandes@' . $slug . '-' . ( 20001 + $n ) . '.example',
					'phone'          => self::fake_phone( $rng, $country ),
					'payment_terms'  => $rng->pick( array( '30 jours net', '10 jours 2 %, 30 jours net', '60 jours net' ) ),
					'incoterm'       => 'CH' === $country ? $rng->pick( array( 'DAP', 'DDP' ) ) : $rng->pick( array( 'EXW', 'FCA', 'DAP' ) ),
					'lead_time_days' => 'CN' === $country ? $rng->int( 30, 45 ) : ( 'CH' === $country ? $rng->int( 1, 7 ) : $rng->int( 4, 14 ) ),
					'reliability'    => $is_problem ? $rng->int( 70, 80 ) : $rng->int( 88, 99 ),
					'currency'       => 'CH' === $country ? 'CHF' : ( 'CN' === $country ? 'USD' : 'EUR' ),
					'is_problematic' => $is_problem ? 1 : 0,
					'active'         => 1,
				);
				++$n;
			}
		}
		Db::bulk_insert(
			'supplier',
			array( 'dataset_id', 'supplier_no', 'name', 'specialty', 'street', 'zip', 'city', 'country', 'contact', 'email', 'phone', 'payment_terms', 'incoterm', 'lead_time_days', 'reliability', 'currency', 'is_problematic', 'active' ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ),
			$rows
		);

		// Clients.
		$crows   = array();
		$n       = 0;
		$blocked = array( 12, 47, 88 );
		$cused   = array();
		$tpl     = array(
			'Commerce de détail'         => array( 'Boutique %s', '%s Market', 'Droguerie %s', 'Épicerie %s' ),
			'Grossiste / distributeur'   => array( '%s Distribution SA', '%s Grossiste Sàrl', 'Centrale %s SA' ),
			'Industrie / atelier'        => array( '%s Mécanique SA', 'Atelier %s Sàrl', '%s Précision SA', '%s Usinage AG' ),
			'Construction / bâtiment'    => array( '%s Construction SA', 'Entreprise %s Bâtiment', '%s Charpente Sàrl' ),
			'Santé / laboratoire'        => array( 'Laboratoire %s', 'Clinique %s', 'Pharmacie %s' ),
			'Restauration / hôtellerie'  => array( 'Hôtel %s', 'Restaurant %s', 'Traiteur %s' ),
			'Particulier / e-commerce'   => array( 'Client web %s' ),
		);
		foreach ( Dictionaries::customer_types() as $ct ) {
			for ( $i = 0; $i < $ct[1]; $i++ ) {
				do {
					$root = 'Particulier / e-commerce' === $ct[0] ? $rng->pick( Dictionaries::contacts() ) : $rng->pick( Dictionaries::company_roots() );
					$name = sprintf( $rng->pick( $tpl[ $ct[0] ] ), $root );
				} while ( isset( $cused[ $name ] ) );
				$cused[ $name ] = true;
				$city           = $rng->pick( Dictionaries::swiss_cities() );
				$carrier        = 'Particulier / e-commerce' === $ct[0] || 'Commerce de détail' === $ct[0] ? 'POST' : ( str_contains( $ct[0], 'Santé' ) || str_contains( $ct[0], 'Restauration' ) ? 'FRIGO' : $rng->pick( array( 'LEMAN', 'HFRET', 'PROPRE' ) ) );
				$crows[]        = array(
					'dataset_id'      => $dataset,
					'customer_no'     => 'C' . ( 10001 + $n ),
					'name'            => $name,
					'type'            => $ct[0],
					'street'          => $rng->pick( Dictionaries::streets() ) . ' ' . $rng->int( 1, 120 ),
					'zip'             => $city[0],
					'city'            => $city[1],
					'country'         => 'CH',
					'email'           => 'logistique@client-' . ( 10001 + $n ) . '.example',
					'phone'           => self::fake_phone( $rng, 'CH' ),
					'priority'        => $rng->pick( $ct[2] ),
					'payment_terms'   => $rng->pick( array( '10 jours net', '30 jours net', '60 jours net' ) ),
					'carrier_code'    => $carrier,
					'delivery_window' => $rng->pick( array( '07:00–11:00', '08:00–12:00', '13:00–17:00', '06:00–09:00', 'Toute la journée' ) ),
					'packing_req'     => $ct[3],
					'credit_limit'    => $rng->pick( array( 5000, 10000, 25000, 50000, 100000 ) ),
					'blocked'         => in_array( $n, $blocked, true ) ? 1 : 0,
					'active'          => 1,
				);
				++$n;
			}
		}
		Db::bulk_insert(
			'customer',
			array( 'dataset_id', 'customer_no', 'name', 'type', 'street', 'zip', 'city', 'country', 'email', 'phone', 'priority', 'payment_terms', 'carrier_code', 'delivery_window', 'packing_req', 'credit_limit', 'blocked', 'active' ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%d' ),
			$crows
		);
		return array( 'suppliers' => count( $rows ), 'customers' => count( $crows ) );
	}

	/**
	 * 30 fournisseurs européens livrant par la route (lettre de voiture CMR), avec leurs fiches d'info-achat.
	 * Ils deviennent fournisseur principal d'une partie des articles de leur spécialité.
	 * Idempotent : n'agit qu'une fois (aussi lors d'une mise à jour d'un site déjà équipé).
	 */
	public static function foreign_suppliers( int $dataset, int $seed ): array {
		global $wpdb;
		if ( get_option( 'logierp_foreign_done' ) ) {
			return array( 'suppliers' => 0, 'links' => 0, 'main' => 0 );
		}
		$rng = Rng::for( $seed, 900 );
		$st  = Db::t( 'supplier' );
		$mt  = Db::t( 'material' );
		$smt = Db::t( 'supplier_material' );

		$specs = array();
		foreach ( Dictionaries::foreign_specialties() as $spec => $n ) {
			$specs = array_merge( $specs, array_fill( 0, $n, $spec ) );
		}
		$specs = $rng->shuffle( $specs );
		$spec_prof = array();
		foreach ( Dictionaries::supplier_specialties() as $sp ) {
			$spec_prof[ $sp[0] ] = $sp[1];
		}
		$used = array_flip( (array) $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$st} WHERE dataset_id = %d", $dataset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(supplier_no) FROM {$st} WHERE dataset_id = %d AND supplier_no REGEXP '^F[0-9]{5}$'", $dataset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = max( 20101, $max ? (int) substr( $max, 1 ) + 1 : 20101 );
		$roots = $rng->shuffle( Dictionaries::company_roots() );

		$rows = array();
		$i    = 0;
		foreach ( Dictionaries::foreign_supplier_countries() as $c ) {
			[ $country, $count, $cities, $streets, $suffixes, $contacts ] = $c;
			for ( $k = 0; $k < $count; $k++, $i++ ) {
				$tries = 0;
				do {
					$name = $roots[ ( $i + $tries ) % count( $roots ) ] . ' ' . $rng->pick( $suffixes ) . ' ' . $rng->pick( Dictionaries::legal_forms( $country ) );
					++$tries;
				} while ( isset( $used[ $name ] ) && $tries < 200 );
				$used[ $name ] = true;
				$city   = $cities[ $k % count( $cities ) ];
				$street = $rng->pick( $streets );
				$num    = (string) $rng->int( 2, 120 );
				$no     = 'F' . $next++;
				$slug   = sanitize_title( strtok( $name, ' ' ) );
				$problem = 0 === $i % 11;
				$rows[]  = array(
					'dataset_id'     => $dataset,
					'supplier_no'    => $no,
					'name'           => $name,
					'specialty'      => $specs[ $i ] ?? 'Négoce général',
					'street'         => 'FR' === $country ? $num . ' ' . $street : $street . ' ' . $num,
					'zip'            => $city[0],
					'city'           => $city[1],
					'country'        => $country,
					'contact'        => $rng->pick( $contacts ),
					'email'          => 'orders@' . $slug . '-' . substr( $no, 1 ) . '.example',
					'phone'          => self::fake_phone( $rng, $country ),
					'payment_terms'  => $rng->pick( array( '30 jours net', '14 jours 2 %, 30 jours net', '45 jours fin de mois' ) ),
					'incoterm'       => $rng->pick( array( 'FCA', 'DAP', 'DAP', 'EXW', 'CPT' ) ),
					'lead_time_days' => in_array( $country, array( 'PL', 'CZ', 'NL', 'BE' ), true ) ? $rng->int( 7, 12 ) : $rng->int( 4, 9 ),
					'reliability'    => $problem ? $rng->int( 72, 82 ) : $rng->int( 86, 98 ),
					'currency'       => 'EUR',
					'is_problematic' => $problem ? 1 : 0,
					'active'         => 1,
				);
			}
		}
		Db::bulk_insert(
			'supplier',
			array( 'dataset_id', 'supplier_no', 'name', 'specialty', 'street', 'zip', 'city', 'country', 'contact', 'email', 'phone', 'payment_terms', 'incoterm', 'lead_time_days', 'reliability', 'currency', 'is_problematic', 'active' ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ),
			$rows
		);

		// Fiches d'info-achat : ~25 articles de la spécialité par fournisseur, dont ~40 % en fournisseur principal.
		$links = 0;
		$main  = 0;
		$taken = array();
		foreach ( $rows as $r ) {
			$sup = $wpdb->get_row( $wpdb->prepare( "SELECT id, lead_time_days FROM {$st} WHERE dataset_id = %d AND supplier_no = %s", $dataset, $r['supplier_no'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prof = $spec_prof[ $r['specialty'] ] ?? array( 'STD' );
			$in   = implode( ',', array_fill( 0, count( $prof ), '%s' ) );
			$mats = $wpdb->get_results( $wpdb->prepare( "SELECT id, material_no, std_price, units_per_carton FROM {$mt} WHERE dataset_id = %d AND deleted_at IS NULL AND status = 'ACTIF' AND profile_code IN ({$in}) ORDER BY id", array_merge( array( $dataset ), $prof ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$mats = array_slice( $rng->shuffle( (array) $mats ), 0, 25 );
			$recs = array();
			foreach ( $mats as $m ) {
				$is_main = ! isset( $taken[ (int) $m['id'] ] ) && $rng->chance( 0.4 );
				$recs[]  = array(
					'supplier_id'    => (int) $sup['id'],
					'material_id'    => (int) $m['id'],
					'supplier_ref'   => $r['country'] . '-' . substr( $m['material_no'], -5 ) . '-' . $rng->int( 10, 99 ),
					'price'          => round( (float) $m['std_price'] * $rng->range( 0.84, 1.02 ), 4 ),
					'min_qty'        => max( 1, (int) $m['units_per_carton'] ) * $rng->pick( array( 2, 5, 10 ) ),
					'lead_time_days' => (int) $sup['lead_time_days'],
					'is_main'        => 0,
				);
				if ( $is_main ) {
					$taken[ (int) $m['id'] ] = true;
					$wpdb->update( $smt, array( 'is_main' => 0 ), array( 'material_id' => (int) $m['id'] ) );
					$wpdb->update( $mt, array( 'main_supplier_id' => (int) $sup['id'], 'lead_time_days' => (int) $sup['lead_time_days'] ), array( 'id' => (int) $m['id'] ) );
					$recs[ count( $recs ) - 1 ]['is_main'] = 1;
					++$main;
				}
			}
			Db::bulk_insert( 'supplier_material', array( 'supplier_id', 'material_id', 'supplier_ref', 'price', 'min_qty', 'lead_time_days', 'is_main' ), array( '%d', '%d', '%s', '%f', '%f', '%d', '%d' ), $recs );
			$links += count( $recs );
		}
		update_option( 'logierp_foreign_done', 1, false );
		\LogiERP\Domain\Catalog::reset_cache();
		return array( 'suppliers' => count( $rows ), 'links' => $links, 'main' => $main );
	}

	private static function fake_phone( Rng $rng, string $country ): string {
		// Plage 0xx 555 xx xx : numéros de fiction.
		$cc = array( 'CH' => '+41', 'DE' => '+49', 'FR' => '+33', 'IT' => '+39', 'AT' => '+43', 'NL' => '+31', 'BE' => '+32', 'PL' => '+48', 'CZ' => '+420', 'CN' => '+86' )[ $country ] ?? '+41';
		return $cc . ' ' . $rng->int( 21, 79 ) . ' 555 ' . str_pad( (string) $rng->int( 0, 99 ), 2, '0', STR_PAD_LEFT ) . ' ' . str_pad( (string) $rng->int( 0, 99 ), 2, '0', STR_PAD_LEFT );
	}

	/* ─────────────────────────── Articles ─────────────────────────── */

	/** Génère les articles d'un profil (une étape de la tâche). */
	public static function materials_for_profile( int $dataset, int $seed, string $profile ): int {
		global $wpdb;
		$profiles = Dictionaries::profiles();
		$p        = $profiles[ $profile ];
		$mt       = Db::t( 'material' );
		$like     = $p['prefix'] . '%';
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mt} WHERE dataset_id = %d AND material_no LIKE %s", $dataset, $like ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return 0;
		}
		$rng      = Rng::for( $seed, 100 + array_search( $profile, array_keys( $profiles ), true ) );
		$families = Dictionaries::families()[ $profile ];
		$group_ids = array();
		foreach ( $families as $fi => $fam ) {
			$code = $profile . '-' . ( $fi + 1 );
			$gid  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'material_group' ) . ' WHERE dataset_id = %d AND code = %s', $dataset, $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $gid ) {
				$gid = Db::insert( 'material_group', array( 'dataset_id' => $dataset, 'code' => $code, 'name' => $fam[0] ) );
			}
			$group_ids[ $fi ] = $gid;
		}

		// Suppliers compatibles avec le profil.
		$st         = Db::t( 'supplier' );
		$suppliers  = $wpdb->get_results( $wpdb->prepare( "SELECT id, specialty, lead_time_days FROM {$st} WHERE dataset_id = %d", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spec_prof  = array();
		foreach ( Dictionaries::supplier_specialties() as $spec ) {
			$spec_prof[ $spec[0] ] = $spec[1];
		}
		$compatible = array_values( array_filter( $suppliers, static fn( $s ) => in_array( $profile, $spec_prof[ $s['specialty'] ] ?? array(), true ) ) );
		if ( ! $compatible ) {
			$compatible = $suppliers;
		}

		// Répartition des familles (chimie : par classe ADR).
		$plan = array();
		if ( 'CHEM' === $profile ) {
			foreach ( Dictionaries::chem_classes() as $fi => $cls ) {
				for ( $i = 0; $i < $cls['count']; $i++ ) {
					$plan[] = $fi;
				}
			}
		} else {
			for ( $i = 0; $i < $p['count']; $i++ ) {
				$plan[] = $rng->int( 0, count( $families ) - 1 );
			}
		}

		$food_families = array( 'Épicerie sèche', 'Boissons', 'Alimentation animale' );
		$rows          = array();
		$used_names    = array();
		foreach ( $plan as $idx => $fi ) {
			$fam      = $families[ $fi ];
			$noun     = $rng->pick( $fam[1] );
			$qual     = $rng->pick( $fam[2] );
			$fmt      = $rng->pick( $fam[3] );
			$name     = $noun . ' ' . $qual . ' ' . $fmt;
			$guard    = 0;
			while ( isset( $used_names[ $name ] ) && $guard++ < 6 ) {
				$fmt  = $rng->pick( $fam[3] );
				$qual = $rng->pick( $fam[2] );
				$name = $noun . ' ' . $qual . ' ' . $fmt;
			}
			if ( isset( $used_names[ $name ] ) ) {
				$name .= ' (v' . ( $idx % 9 + 2 ) . ')';
			}
			$used_names[ $name ] = true;

			$w = round( $rng->log_range( $p['w'][0], $p['w'][1] ), 3 );
			$v = round( $rng->log_range( $p['v'][0], $p['v'][1] ), 3 );
			if ( 'LONG' === $profile && preg_match( '/(\d+)(?:,(\d))?\s*m$/', $fmt, $m ) ) {
				$len = (int) $m[1] * 1000 + ( isset( $m[2] ) ? (int) $m[2] * 100 : 0 );
			} else {
				$len = $rng->int( $p['len'][0], $p['len'][1] );
			}
			$cross = max( 1.0, ( $v * 1000000.0 ) / max( 1, $len ) );
			$side  = (int) max( 5, min( 1200, round( sqrt( $cross ) ) ) );
			$price = round( $rng->log_range( $p['price'][0], $p['price'][1] ), 2 );
			$upc   = $rng->int( $p['upc'][0], $p['upc'][1] );
			if ( $upc > 1 && $upc > 6 ) {
				$upc = (int) ( round( $upc / 6 ) * 6 );
			}
			if ( in_array( $profile, array( 'LONG', 'HEAVY', 'BULKY' ), true ) ) {
				$cpp = 1;
				$upc = 1;
			} else {
				$cv  = $upc * $v * 1.1;
				$cw  = $upc * $w * 1.05;
				$cpp = (int) max( 1, min( 80, floor( min( 1300 / max( 0.001, $cv ), 900 / max( 0.001, $cw ) ) ) ) );
			}

			$type = $rng->float();
			$type = $type < 0.70 ? 'MRCH' : ( $type < 0.85 ? 'MP' : ( $type < 0.92 ? 'SF' : ( $type < 0.97 ? 'PF' : 'EMB' ) ) );
			if ( 'Emballages' === $fam[0] || 'Cartonnages en lots' === $fam[0] ) {
				$type = 'EMB';
			}

			$is_food  = in_array( $fam[0], $food_families, true );
			$batch    = in_array( $profile, array( 'COLD', 'FROZ', 'TEMP', 'CHEM' ), true ) || $is_food ? 1 : 0;
			$shelf    = 0;
			if ( 'COLD' === $profile ) {
				$shelf = $rng->int( 10, 45 );
			} elseif ( 'FROZ' === $profile ) {
				$shelf = $rng->int( 180, 540 );
			} elseif ( 'TEMP' === $profile ) {
				$shelf = $rng->int( 365, 1095 );
			} elseif ( 'CHEM' === $profile ) {
				$shelf = $rng->int( 365, 1460 );
			} elseif ( $is_food ) {
				$shelf = $rng->int( 180, 720 );
			}
			$temp = array( 'COLD' => array( 2, 8 ), 'FROZ' => array( -25, -18 ), 'TEMP' => array( 15, 25 ) )[ $profile ] ?? null;

			$adr = ''; $un = ''; $pg = ''; $htxt = '';
			if ( 'CHEM' === $profile ) {
				$cls  = Dictionaries::chem_classes()[ $fi ];
				$adr  = $cls['class'];
				$un   = $rng->pick( $cls['un'] );
				$pg   = $cls['pg'];
				$htxt = $cls['text'];
			} elseif ( 'BATT' === $profile ) {
				$adr  = '9';
				$un   = $rng->pick( array( '3480', '3481' ) );
				$htxt = 'Piles au lithium ionique';
			}

			$daily   = round( $rng->log_range( 0.3, 40 ), 1 );
			$lead    = 5;
			$sup     = $rng->pick( $compatible );
			$lead    = (int) $sup['lead_time_days'];
			$lot     = max( 1, $upc ) * max( 1, (int) ceil( ( $daily * 10 ) / max( 1, $upc ) ) );
			$reorder = (int) ceil( $daily * ( $lead + 3 ) );
			$abc     = $rng->float();
			$abc     = $abc < 0.2 ? 'A' : ( $abc < 0.5 ? 'B' : 'C' );

			$rows[] = array(
				'dataset_id'         => $dataset,
				'material_no'        => $p['prefix'] . str_pad( (string) ( $idx + 1 ), 6, '0', STR_PAD_LEFT ),
				'designation'        => mb_substr( $name, 0, 190 ),
				'group_id'           => $group_ids[ $fi ],
				'type'               => $type,
				'base_uom'           => $p['uom'],
				'profile_code'       => $profile,
				'net_weight'         => $w,
				'gross_weight'       => round( $w * 1.05, 3 ),
				'length_mm'          => $len,
				'width_mm'           => $side,
				'height_mm'          => $side,
				'volume_l'           => $v,
				'std_price'          => $price,
				'sale_price'         => round( $price * $rng->range( 1.25, 1.8 ), 2 ),
				'abc'                => $abc,
				'xyz'                => $rng->pick( array( 'X', 'Y', 'Z' ) ),
				'batch_managed'      => $batch,
				'shelf_life_days'    => $shelf,
				'serial_managed'     => 'HV' === $profile && $rng->chance( 0.5 ) ? 1 : 0,
				'min_stock'          => (int) ceil( $daily * 3 ),
				'max_stock'          => $reorder + 2 * $lot,
				'reorder_point'      => $reorder,
				'lot_size'           => $lot,
				'lead_time_days'     => $lead,
				'main_supplier_id'   => (int) $sup['id'],
				'ean'                => self::ean( $rng ),
				'un_number'          => $un,
				'adr_class'          => $adr,
				'packing_group'      => $pg,
				'hazard_text'        => $htxt,
				'temp_min'           => $temp ? $temp[0] : null,
				'temp_max'           => $temp ? $temp[1] : null,
				'stackable'          => in_array( $profile, array( 'BULKY', 'FRAG', 'HEAVY' ), true ) ? 0 : 1,
				'fragile'            => 'FRAG' === $profile ? 1 : 0,
				'high_value'         => 'HV' === $profile ? 1 : 0,
				'units_per_carton'   => max( 1, $upc ),
				'cartons_per_pallet' => $cpp,
				'status'             => 'ACTIF',
				'created_at'         => Db::now(),
				'updated_at'         => Db::now(),
			);
		}

		// Les colonnes NULL (températures) imposent deux formats : on insère en deux passes.
		$cols = array_keys( $rows[0] );
		$fmt  = array();
		foreach ( $cols as $c ) {
			$fmt[] = in_array( $c, array( 'dataset_id', 'group_id', 'length_mm', 'width_mm', 'height_mm', 'batch_managed', 'shelf_life_days', 'serial_managed', 'lead_time_days', 'main_supplier_id', 'stackable', 'fragile', 'high_value', 'units_per_carton', 'cartons_per_pallet' ), true ) ? '%d'
				: ( in_array( $c, array( 'net_weight', 'gross_weight', 'volume_l', 'std_price', 'sale_price', 'min_stock', 'max_stock', 'reorder_point', 'lot_size', 'temp_min', 'temp_max' ), true ) ? '%f' : '%s' );
		}
		$with_temp    = array_values( array_filter( $rows, static fn( $r ) => null !== $r['temp_min'] ) );
		$without_temp = array_values( array_filter( $rows, static fn( $r ) => null === $r['temp_min'] ) );
		if ( $with_temp ) {
			Db::bulk_insert( 'material', $cols, $fmt, $with_temp );
		}
		if ( $without_temp ) {
			$cols2 = array_values( array_diff( $cols, array( 'temp_min', 'temp_max' ) ) );
			$fmt2  = array();
			foreach ( $cols as $i => $c ) {
				if ( in_array( $c, $cols2, true ) ) {
					$fmt2[] = $fmt[ $i ];
				}
			}
			Db::bulk_insert( 'material', $cols2, $fmt2, array_map( static function ( $r ) {
				unset( $r['temp_min'], $r['temp_max'] );
				return $r;
			}, $without_temp ) );
		}
		return count( $rows );
	}

	private static function ean( Rng $rng ): string {
		$d = '2' . $rng->int( 0, 9 ) . str_pad( (string) $rng->int( 0, 9999999999 ), 10, '0', STR_PAD_LEFT );
		$d = substr( $d, 0, 12 );
		$s = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			$s += (int) $d[ $i ] * ( 0 === $i % 2 ? 1 : 3 );
		}
		return $d . ( ( 10 - $s % 10 ) % 10 );
	}

	/** Fiches d'info-achat (1 à 3 fournisseurs par article) et nomenclatures. */
	public static function links_and_boms( int $dataset, int $seed ): array {
		global $wpdb;
		$rng = Rng::for( $seed, 300 );
		$mt  = Db::t( 'material' );
		$smt = Db::t( 'supplier_material' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$smt}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'links' => 0, 'boms' => 0 );
		}
		$materials = $wpdb->get_results( $wpdb->prepare( "SELECT id, material_no, profile_code, type, std_price, main_supplier_id, lead_time_days, units_per_carton FROM {$mt} WHERE dataset_id = %d ORDER BY id", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$st        = Db::t( 'supplier' );
		$suppliers = $wpdb->get_results( $wpdb->prepare( "SELECT id, specialty, lead_time_days FROM {$st} WHERE dataset_id = %d", $dataset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spec_prof = array();
		foreach ( Dictionaries::supplier_specialties() as $spec ) {
			$spec_prof[ $spec[0] ] = $spec[1];
		}
		$rows = array();
		foreach ( $materials as $m ) {
			$compatible = array_values( array_filter( $suppliers, static fn( $s ) => in_array( $m['profile_code'], $spec_prof[ $s['specialty'] ] ?? array(), true ) && (int) $s['id'] !== (int) $m['main_supplier_id'] ) );
			$rows[]     = array(
				'supplier_id'    => (int) $m['main_supplier_id'],
				'material_id'    => (int) $m['id'],
				'supplier_ref'   => 'REF-' . substr( $m['material_no'], -5 ) . '-' . $rng->int( 10, 99 ),
				'price'          => round( (float) $m['std_price'] * $rng->range( 0.92, 1.0 ), 4 ),
				'min_qty'        => (int) $m['units_per_carton'],
				'lead_time_days' => (int) $m['lead_time_days'],
				'is_main'        => 1,
			);
			$extra = $rng->int( 0, 2 );
			foreach ( array_slice( $rng->shuffle( $compatible ), 0, $extra ) as $s ) {
				$rows[] = array(
					'supplier_id'    => (int) $s['id'],
					'material_id'    => (int) $m['id'],
					'supplier_ref'   => 'S' . $s['id'] . '-' . substr( $m['material_no'], -5 ),
					'price'          => round( (float) $m['std_price'] * $rng->range( 0.95, 1.15 ), 4 ),
					'min_qty'        => (int) $m['units_per_carton'],
					'lead_time_days' => (int) $s['lead_time_days'],
					'is_main'        => 0,
				);
			}
		}
		Db::bulk_insert( 'supplier_material', array( 'supplier_id', 'material_id', 'supplier_ref', 'price', 'min_qty', 'lead_time_days', 'is_main' ), array( '%d', '%d', '%s', '%f', '%f', '%d', '%d' ), $rows );

		// Nomenclatures : au moins 40 produits finis.
		$pf = array_values( array_filter( $materials, static fn( $m ) => 'PF' === $m['type'] && in_array( $m['profile_code'], array( 'STD', 'BULKY', 'SMALL', 'FRAG' ), true ) ) );
		$components = array_values( array_filter( $materials, static fn( $m ) => in_array( $m['type'], array( 'MP', 'SF' ), true ) && in_array( $m['profile_code'], array( 'STD', 'SMALL', 'PAL' ), true ) ) );
		// Garantit 40 produits finis même si le tirage en donne moins.
		if ( count( $pf ) < 40 ) {
			$candidates = array_values( array_filter( $materials, static fn( $m ) => 'MRCH' === $m['type'] && in_array( $m['profile_code'], array( 'STD', 'BULKY' ), true ) ) );
			foreach ( array_slice( $candidates, 0, 40 - count( $pf ) ) as $c ) {
				$wpdb->update( $mt, array( 'type' => 'PF' ), array( 'id' => (int) $c['id'] ) );
				$c['type'] = 'PF';
				$pf[]      = $c;
			}
		}
		$boms = 0;
		foreach ( $pf as $prod ) {
			$bom_id = Db::insert( 'bom', array( 'material_id' => (int) $prod['id'], 'version' => '1', 'work_center' => $rng->pick( array( 'Montage 1', 'Montage 2', 'Conditionnement', 'Atelier mécanique' ) ), 'minutes_per_unit' => $rng->int( 3, 45 ) ) );
			$items  = array();
			foreach ( array_slice( $rng->shuffle( $components ), 0, $rng->int( 3, 8 ) ) as $comp ) {
				$items[] = array( 'bom_id' => $bom_id, 'component_id' => (int) $comp['id'], 'qty' => $rng->pick( array( 1, 1, 2, 2, 4, 6, 8, 10 ) ), 'scrap_pct' => $rng->pick( array( 0, 0, 1, 2, 5 ) ) );
			}
			Db::bulk_insert( 'bom_item', array( 'bom_id', 'component_id', 'qty', 'scrap_pct' ), array( '%d', '%d', '%f', '%f' ), $items );
			++$boms;
		}
		return array( 'links' => count( $rows ), 'boms' => $boms );
	}
}
