<?php
/**
 * Plugin Name:       LogiERP
 * Plugin URI:        https://logiprof.ch
 * Description:       Simulateur ERP/WMS pédagogique : achats, réception, entreposage chaotique ou par emplacements fixes, stock, ventes, préparation, expédition, production, inventaire, retours. Interface enseignant pour créer des scénarios et corriger automatiquement. Aux couleurs LogiOne / LogiHub.
 * Version:           2.3.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            LogiProf
 * License:           GPL-2.0-or-later
 * Text Domain:       logierp
 * Domain Path:       /languages
 * Update URI:        https://github.com/logiprof/LogiERP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOGIERP_VERSION', '2.3.1' );
define( 'LOGIERP_DB_VERSION', '2.2.0' );
define( 'LOGIERP_FILE', __FILE__ );
define( 'LOGIERP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOGIERP_URL', plugin_dir_url( __FILE__ ) );
define( 'LOGIERP_MIN_PHP', '8.1' );
define( 'LOGIERP_MIN_WP', '6.4' );

/*
 * Autoload PSR-4 minimal : LogiERP\Core\Plugin -> src/Core/Plugin.php.
 * Aucune dépendance Composer n'est nécessaire à l'exécution.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'LogiERP\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = LOGIERP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Vérifie les prérequis. Retourne un message d'erreur, ou une chaîne vide si tout est conforme.
 */
function logierp_requirements_error(): string {
	global $wp_version, $wpdb;
	if ( version_compare( PHP_VERSION, LOGIERP_MIN_PHP, '<' ) ) {
		/* translators: 1: version requise, 2: version actuelle */
		return sprintf( __( 'LogiERP nécessite PHP %1$s ou plus récent (version actuelle : %2$s).', 'logierp' ), LOGIERP_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( (string) $wp_version, LOGIERP_MIN_WP, '<' ) ) {
		/* translators: 1: version requise, 2: version actuelle */
		return sprintf( __( 'LogiERP nécessite WordPress %1$s ou plus récent (version actuelle : %2$s).', 'logierp' ), LOGIERP_MIN_WP, (string) $wp_version );
	}
	$engines = $wpdb->get_col( 'SHOW ENGINES' );
	if ( is_array( $engines ) && $engines && ! in_array( 'InnoDB', $engines, true ) ) {
		return __( 'LogiERP nécessite le moteur de base de données InnoDB.', 'logierp' );
	}
	return '';
}

register_activation_hook(
	__FILE__,
	static function (): void {
		$error = logierp_requirements_error();
		if ( '' !== $error ) {
			deactivate_plugins( plugin_basename( LOGIERP_FILE ) );
			wp_die( esc_html( $error ), esc_html__( 'Activation impossible', 'logierp' ), array( 'back_link' => true ) );
		}
		\LogiERP\Core\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\LogiERP\Core\Activator::deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// Les mises à jour restent possibles même si un prérequis manque (une version corrective peut alors être installée).
		\LogiERP\Core\Updater::register();
		if ( '' !== logierp_requirements_error() ) {
			return;
		}
		\LogiERP\Core\Plugin::instance()->boot();
	}
);
