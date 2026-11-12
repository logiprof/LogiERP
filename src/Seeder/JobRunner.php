<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Génération du jeu de base en étapes courtes (chacune < 30 s), pilotées depuis l'administration
 * avec une barre de progression, ou par WP-Cron en secours.
 */
final class JobRunner {

	/** Révision du jeu de données (2 : fournisseurs européens, stock pour chaque article, paramètres alignés). */
	public const DATA_REV = 7;

	public const SEED = 20260105;

	/** @return string[] */
	public static function steps(): array {
		$profiles = array_keys( Dictionaries::profiles() );
		$steps    = array( 'warehouse', 'partners' );
		foreach ( $profiles as $p ) {
			$steps[] = 'mat:' . $p;
		}
		$steps[] = 'links';
		$steps[] = 'foreign';
		$steps[] = 'fixed';
		foreach ( $profiles as $p ) {
			$steps[] = 'stock:' . $p;
		}
		$steps[] = 'stockfill';
		$steps[] = 'situations';
		$steps[] = 'align';
		$steps[] = 'logistics';
		$steps[] = 'docs';
		$steps[] = 'process';
		$steps[] = 'templates';
		return $steps;
	}

	public static function label( string $step ): string {
		$map = array(
			'warehouse'  => 'Cartographie de l\'entrepôt et emplacements',
			'partners'   => 'Fournisseurs, clients et transporteurs',
			'links'      => 'Fiches d\'info-achat et nomenclatures',
			'foreign'    => 'Fournisseurs européens et fiches d\'info-achat',
			'fixed'      => 'Attribution des emplacements fixes',
			'stockfill'  => 'Stock de départ : contrôle de chaque article',
			'align'      => 'Paramètres de réapprovisionnement (min., point de commande, max.)',
			'logistics'  => 'Transport : flotte, exigences des clients, emballages consignés',
			'situations' => 'Situations d\'apprentissage (DLC, blocages, contrôle qualité)',
			'docs'       => 'Commandes ouvertes (fond de décor)',
			'process'    => 'Chimie de procédé : citernes, vrac, emballages, recettes, conteneurs loués',
			'templates'  => 'Scénarios modèles',
		);
		if ( isset( $map[ $step ] ) ) {
			return $map[ $step ];
		}
		[ $kind, $p ] = array_pad( explode( ':', $step, 2 ), 2, '' );
		$name = Catalog::profile_label( $p );
		return 'mat' === $kind ? 'Articles : ' . $name : 'Stock initial : ' . $name;
	}

	/**
	 * Mise à jour d'un site déjà équipé : les étapes ajoutées depuis (fournisseurs européens, stock de
	 * chaque article, paramètres, nouveaux scénarios modèles) sont rejouées sans toucher aux données existantes.
	 */
	public static function maybe_upgrade_data(): void {
		if ( 'done' !== get_option( 'logierp_seed_state' ) || (int) get_option( 'logierp_data_rev', 1 ) >= self::DATA_REV ) {
			return;
		}
		// Révision 2 : fournisseurs étrangers et stocks ; révision 3 : nouveaux scénarios modèles seulement.
		$rev  = (int) get_option( 'logierp_data_rev', 1 );
		$from = array_search( $rev < 2 ? 'foreign' : ( $rev < 4 ? 'logistics' : ( $rev < 6 ? 'process' : 'templates' ) ), self::steps(), true );
		update_option( 'logierp_seed_step', (int) $from, false );
		update_option( 'logierp_seed_state', 'pending', false );
		if ( ! wp_next_scheduled( 'logierp_run_job' ) ) {
			wp_schedule_single_event( time() + 30, 'logierp_run_job' );
		}
	}

	public static function state(): array {
		$step  = (int) get_option( 'logierp_seed_step', 0 );
		$steps = self::steps();
		return array(
			'state'   => (string) get_option( 'logierp_seed_state', 'pending' ),
			'step'    => $step,
			'total'   => count( $steps ),
			'label'   => isset( $steps[ $step ] ) ? self::label( $steps[ $step ] ) : 'Terminé',
			'message' => (string) get_option( 'logierp_seed_message', '' ),
		);
	}

	/** Exécute l'étape suivante. Verrou anti-concurrence par transient. */
	public static function run_next(): array {
		if ( get_transient( 'logierp_seed_lock' ) ) {
			return self::state() + array( 'busy' => true );
		}
		set_transient( 'logierp_seed_lock', 1, 120 );
		$steps = self::steps();
		$step  = (int) get_option( 'logierp_seed_step', 0 );
		try {
			if ( $step >= count( $steps ) ) {
				update_option( 'logierp_seed_state', 'done', false );
				return self::state();
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			update_option( 'logierp_seed_state', 'running', false );
			$msg = self::run_step( $steps[ $step ] );
			update_option( 'logierp_seed_message', $msg, false );
			update_option( 'logierp_seed_step', $step + 1, false );
			if ( $step + 1 >= count( $steps ) ) {
				update_option( 'logierp_seed_state', 'done', false );
				update_option( 'logierp_data_rev', self::DATA_REV, false );
				Audit::log( 'seed_done', 'dataset', 1 );
			}
		} catch ( \Throwable $e ) {
			\LogiERP\Support\Logger::error( 'Génération, étape ' . $steps[ $step ] . ' : ' . $e->getMessage() );
			update_option( 'logierp_seed_state', 'error', false );
			update_option( 'logierp_seed_message', 'Erreur à l\'étape « ' . self::label( $steps[ $step ] ) . ' ». Voir le journal technique.', false );
		} finally {
			delete_transient( 'logierp_seed_lock' );
		}
		return self::state();
	}

	private static function run_step( string $step ): string {
		$dataset = MasterDataSeeder::dataset_id( self::SEED );
		Catalog::reset_cache();
		switch ( true ) {
			case 'warehouse' === $step:
				$r = WarehouseBuilder::build();
				return sprintf( '%d emplacements créés.', $r['bins'] );
			case 'partners' === $step:
				$r = MasterDataSeeder::partners( $dataset, self::SEED );
				return sprintf( '%d fournisseurs, %d clients.', $r['suppliers'], $r['customers'] );
			case str_starts_with( $step, 'mat:' ):
				$n = MasterDataSeeder::materials_for_profile( $dataset, self::SEED, substr( $step, 4 ) );
				return sprintf( '%d articles créés.', $n );
			case 'links' === $step:
				$r = MasterDataSeeder::links_and_boms( $dataset, self::SEED );
				return sprintf( '%d fiches d\'info-achat, %d nomenclatures.', $r['links'], $r['boms'] );
			case 'foreign' === $step:
				$r = MasterDataSeeder::foreign_suppliers( $dataset, self::SEED );
				return sprintf( '%d fournisseurs européens, %d fiches d\'info-achat.', $r['suppliers'], $r['links'] );
			case 'stockfill' === $step:
				return sprintf( '%d lignes de stock ajoutées.', StockSeeder::fill_missing( $dataset, self::SEED ) );
			case 'align' === $step:
				$r = StockSeeder::align( self::SEED );
				return sprintf( '%d articles ajustés, %d sous le point de commande.', $r['aligned'], $r['below_reorder'] );
			case 'logistics' === $step:
				$r = LogisticsSeeder::run( $dataset, self::SEED );
				return sprintf( '%d véhicules, %d articles d\'emballage et produits consignés, %d clients avec exigences de livraison.', $r['vehicles'], $r['materials'], $r['customers'] );
			case 'fixed' === $step:
				return sprintf( '%d emplacements fixes attribués.', StockSeeder::assign_fixed_bins( $dataset ) );
			case str_starts_with( $step, 'stock:' ):
				return sprintf( '%d lignes de stock placées.', StockSeeder::seed_profile( $dataset, self::SEED, substr( $step, 6 ) ) );
			case 'situations' === $step:
				StockSeeder::situations( $dataset, self::SEED );
				return 'Situations d\'apprentissage créées.';
			case 'docs' === $step:
				StockSeeder::open_documents( $dataset, self::SEED );
				return '30 commandes fournisseurs et 40 commandes clients ouvertes.';
			case 'process' === $step:
				$r = ProcessSeeder::run( $dataset, self::SEED );
				return sprintf( '%d articles de procédé, %d recettes, %d lignes de stock, %d conteneurs loués, %d commandes ouvertes.', $r['materials'], $r['boms'], $r['stock'], $r['containers'], $r['documents'] );
			case 'templates' === $step:
				$n = \LogiERP\Scenario\Templates::install_all();
				return $n > 1 ? sprintf( '%d scénarios modèles installés.', $n ) : ( 1 === $n ? '1 scénario modèle installé.' : 'Scénarios modèles à jour.' );
		}
		return '';
	}

	/** Secours WP-Cron : enchaîne les étapes tant qu'il reste du temps. */
	public static function run_scheduled(): void {
		$start = time();
		while ( time() - $start < 20 ) {
			$s = self::run_next();
			if ( in_array( $s['state'], array( 'done', 'error' ), true ) || ! empty( $s['busy'] ) ) {
				return;
			}
		}
		wp_schedule_single_event( time() + 5, 'logierp_run_job' );
	}

	/** Efface tout le jeu de base et les sessions (outil d'administration, confirmé). */
	public static function reset_all(): void {
		global $wpdb;
		foreach ( Db::TABLES as $t ) {
			if ( in_array( $t, array( 'audit_log' ), true ) ) {
				continue;
			}
			$wpdb->query( 'DELETE FROM ' . Db::t( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table en liste blanche.
			$wpdb->query( 'ALTER TABLE ' . Db::t( $t ) . ' AUTO_INCREMENT = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table en liste blanche.
		}
		foreach ( array( 'logierp_seed_step', 'logierp_seed_message', 'logierp_situations_done', 'logierp_foreign_done', 'logierp_align_done', 'logierp_data_rev', 'logierp_logistics_done' ) as $o ) {
			delete_option( $o );
		}
		update_option( 'logierp_seed_state', 'pending', false );
		\LogiERP\Domain\Photos::purge_files();
		Catalog::reset_cache();
		Audit::log( 'reset_all', 'dataset', 1 );
	}
}
