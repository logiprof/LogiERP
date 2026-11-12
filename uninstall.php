<?php
/**
 * Désinstallation de LogiERP.
 * Les données ne sont supprimées que si l'option « Supprimer toutes les données » a été cochée dans les réglages.
 */
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'logierp_daily_maintenance' );
wp_clear_scheduled_hook( 'logierp_run_job' );
wp_clear_scheduled_hook( 'logierp_sensei_sync' );

if ( ! get_option( 'logierp_delete_on_uninstall', 0 ) ) {
	return;
}

require_once __DIR__ . '/src/Core/Db.php';
require_once __DIR__ . '/src/Core/Capabilities.php';

global $wpdb;
// Liste blanche des tables du plugin : aucune autre table n'est touchée.
foreach ( \LogiERP\Core\Db::TABLES as $logierp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'lw_' . $logierp_table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

\LogiERP\Core\Capabilities::uninstall();

// Imprévus déjà lus dans les sessions de groupe (suivi par compte).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'logierp_ev_' ) . '%' ) );

$logierp_page = (int) get_option( 'logierp_page_id', 0 );
if ( $logierp_page && 'page' === get_post_type( $logierp_page ) ) {
	wp_delete_post( $logierp_page, true );
}

// Photos de non-conformité : uniquement le dossier privé du plugin (nom vérifié).
$logierp_up  = wp_upload_dir( null, false );
$logierp_dir = trailingslashit( $logierp_up['basedir'] ) . 'logierp-private';
if ( is_dir( $logierp_dir ) && 'logierp-private' === basename( $logierp_dir ) ) {
	$logierp_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $logierp_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $logierp_it as $logierp_f ) {
		if ( $logierp_f->isLink() ) {
			continue;
		}
		$logierp_f->isDir() ? @rmdir( $logierp_f->getPathname() ) : @unlink( $logierp_f->getPathname() ); // phpcs:ignore
	}
	@rmdir( $logierp_dir ); // phpcs:ignore
}

// Manuels PDF : dossier privé au nom aléatoire (nom vérifié), fichiers à plat.
$logierp_man = get_option( 'logierp_manuals', array() );
if ( is_array( $logierp_man ) && ! empty( $logierp_man['dir'] ) && preg_match( '/^logierp-manuels-[a-z0-9]{20}$/', (string) $logierp_man['dir'] ) ) {
	$logierp_mdir = trailingslashit( $logierp_up['basedir'] ) . $logierp_man['dir'];
	if ( is_dir( $logierp_mdir ) && ! is_link( $logierp_mdir ) ) {
		foreach ( array_diff( (array) scandir( $logierp_mdir ), array( '.', '..' ) ) as $logierp_mn ) {
			$logierp_mf = $logierp_mdir . '/' . $logierp_mn;
			if ( is_file( $logierp_mf ) && ! is_link( $logierp_mf ) ) {
				@unlink( $logierp_mf ); // phpcs:ignore
			}
		}
		@rmdir( $logierp_mdir ); // phpcs:ignore
	}
}

// Options et transients du plugin (préfixe logierp_ / lw_ uniquement).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'logierp_' ) . '%', $wpdb->esc_like( '_transient_lw_' ) . '%', $wpdb->esc_like( '_transient_timeout_lw_' ) . '%', $wpdb->esc_like( '_transient_logierp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_logierp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
