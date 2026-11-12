<?php
declare(strict_types=1);

namespace LogiERP\Core;

use LogiERP\Admin\AdminMenu;
use LogiERP\Front\App;
use LogiERP\Rest\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point d'entrée : enregistre les hooks des différentes couches.
 */
final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'logierp', false, dirname( plugin_basename( LOGIERP_FILE ) ) . '/languages' );

		// Migrations : comparées à chaque chargement de l'administration.
		// Schéma à jour avant toute requête (y compris l'API de l'application après une mise à jour par téléversement).
		add_action( 'init', array( Migrator::class, 'maybe_upgrade' ), 1 );
		add_action( 'admin_init', array( \LogiERP\Seeder\JobRunner::class, 'maybe_upgrade_data' ) );

		// Rôles et capacités (idempotent, une fois par version).
		add_action( 'init', array( Capabilities::class, 'maybe_sync' ) );

		( new AdminMenu() )->register();
		( new App() )->register();
		( new \LogiERP\Front\TeacherSpace() )->register();
		( new \LogiERP\Front\ActivityDoc() )->register();
		( new \LogiERP\Front\BusinessDocs() )->register();
		( new \LogiERP\Front\MiniWarehouse() )->register();
		( new \LogiERP\Front\MissionDashboard() )->register();
		( new \LogiERP\Front\Manuals() )->register();
		( new \LogiERP\Front\LabelPrint() )->register();
		add_action( 'save_post_page', array( \LogiERP\Core\Activator::class, 'track_pages' ), 10, 2 );
		( new Routes() )->register();
		( new \LogiERP\Rest\SapRoutes() )->register();

		// Tâches de fond par lots (génération du jeu de base).
		add_action( 'logierp_run_job', array( \LogiERP\Seeder\JobRunner::class, 'run_scheduled' ) );

		// Confidentialité : export et effacement des données personnelles.
		add_filter( 'wp_privacy_personal_data_exporters', array( \LogiERP\Security\Privacy::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( \LogiERP\Security\Privacy::class, 'register_eraser' ) );

		// Groupes Sensei Pro : les classes liées suivent leur groupe (connexion de l'élève + toutes les heures).
		add_action( 'wp_login', array( \LogiERP\Domain\SenseiGroups::class, 'on_login' ), 10, 2 );
		add_action( 'logierp_sensei_sync', array( \LogiERP\Domain\SenseiGroups::class, 'sync_all' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( 'logierp_sensei_sync' ) ) {
					wp_schedule_event( time() + 600, 'hourly', 'logierp_sensei_sync' );
				}
			}
		);

		// Purge périodique du journal d'audit.
		add_action( 'logierp_daily_maintenance', array( \LogiERP\Security\Audit::class, 'purge_old' ) );
	}
}
