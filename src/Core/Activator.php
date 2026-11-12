<?php
declare(strict_types=1);

namespace LogiERP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation idempotente : tables, rôles, page du simulateur, planification de la génération.
 * La génération du jeu de base n'a jamais lieu dans la requête d'activation.
 */
final class Activator {

	public static function activate(): void {
		Migrator::migrate();
		Capabilities::install();
		self::ensure_page();
		\LogiERP\Front\Manuals::ensure();
		if ( ! get_option( 'logierp_hmac_key' ) ) {
			add_option( 'logierp_hmac_key', wp_generate_password( 64, true, true ), '', false );
		}
		if ( ! wp_next_scheduled( 'logierp_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'logierp_daily_maintenance' );
		}
		// Jeu de base : préparé automatiquement, jamais dans la requête d'activation elle-même.
		// 1) l'administrateur est redirigé vers le tableau de bord, où la préparation démarre seule ;
		// 2) en secours, WP-Cron enchaîne les étapes (verrou partagé : aucune exécution en double).
		if ( ! get_option( 'logierp_seed_state' ) ) {
			update_option( 'logierp_seed_state', 'pending', false );
		}
		if ( 'done' !== get_option( 'logierp_seed_state' ) ) {
			set_transient( 'logierp_activation_redirect', 1, 60 );
			if ( ! wp_next_scheduled( 'logierp_run_job' ) ) {
				wp_schedule_single_event( time() + 30, 'logierp_run_job' );
			}
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'logierp_daily_maintenance' );
		wp_clear_scheduled_hook( 'logierp_run_job' );
		wp_clear_scheduled_hook( 'logierp_sensei_sync' );
	}

	public static function ensure_page(): void {
		$page_id = (int) get_option( 'logierp_page_id', 0 );
		if ( $page_id && get_post_status( $page_id ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Simulateur LogiERP', 'logierp' ),
				'post_name'    => 'logierp',
				'post_content' => '[logierp_eleve]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'logierp_page_id', (int) $page_id, false );
		}
	}

	public static function app_url(): string {
		$url = self::page_url( 'logierp_page_id', array( 'logierp_eleve', 'logierp_app' ) );
		return '' !== $url ? $url : home_url( '/' );
	}

	/** Espace enseignant : page publique avec [logierp_enseignant] si elle existe, sinon l'administration. */
	public static function teacher_url(): string {
		$url = self::page_url( 'logierp_teacher_page_id', array( 'logierp_enseignant' ) );
		return '' !== $url ? $url : admin_url( 'admin.php?page=logierp' );
	}

	/**
	 * Adresse de la page publiée qui porte le code court. Si la page retenue a été dépubliée ou
	 * supprimée, une autre page publiée contenant le code court est recherchée et retenue.
	 *
	 * @param string[] $shortcodes
	 */
	private static function page_url( string $option, array $shortcodes ): string {
		$page_id = (int) get_option( $option, 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		foreach ( $shortcodes as $code ) {
			$found = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					's'                => '[' . $code,
					'posts_per_page'   => 5,
					'orderby'          => 'modified',
					'order'            => 'DESC',
					'suppress_filters' => true,
				)
			);
			foreach ( $found as $p ) {
				if ( has_shortcode( (string) $p->post_content, $code ) ) {
					update_option( $option, (int) $p->ID, false );
					return (string) get_permalink( $p );
				}
			}
		}
		return '';
	}

	/**
	 * Retient la dernière page publiée qui contient chaque code court, pour que les liens (connexion,
	 * retour au portail, lien « Espace enseignant ») pointent vers la page choisie par l'administrateur.
	 */
	public static function track_pages( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$content = (string) $post->post_content;
		if ( has_shortcode( $content, 'logierp_eleve' ) || has_shortcode( $content, 'logierp_app' ) || has_block( 'logierp/app', $post ) ) {
			update_option( 'logierp_page_id', $post_id, false );
		}
		if ( has_shortcode( $content, 'logierp_enseignant' ) ) {
			update_option( 'logierp_teacher_page_id', $post_id, false );
		}
	}
}
