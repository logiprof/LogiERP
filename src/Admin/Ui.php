<?php
declare(strict_types=1);

namespace LogiERP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Petits utilitaires d'affichage pour l'administration (tout est échappé à la sortie).
 */
final class Ui {

	/** Adresse de la page publique quand l'espace enseignant est affiché par le code court (null = administration). */
	private static ?string $front_base = null;

	/** Pages proposées dans l'espace enseignant public (les réglages et outils restent dans l'administration). */
	public const FRONT_PAGES = array( 'logierp', 'logierp-scenarios', 'logierp-classes', 'logierp-live', 'logierp-results', 'logierp-labels', 'logierp-manuals', 'logierp-data', 'logierp-warehouse', 'logierp-company' );

	public static function set_front( ?string $base ): void {
		self::$front_base  = $base;
		self::$front_title = null;
	}

	public static function is_front(): bool {
		return null !== self::$front_base;
	}

	/** Adresse validée d'une page publique du même site (sinon chaîne vide). */
	public static function valid_front_base( string $url ): string {
		$url  = esc_url_raw( remove_query_arg( array( 'lw_view', 'lw_notice', 'id', 'tab', 'q', 'profile', 'location', 'blocked', 'assignment', 'session', 'lw_p' ), $url ) );
		$safe = wp_validate_redirect( $url, '' );
		if ( '' === $safe || str_contains( $safe, '/wp-admin/' ) || str_contains( $safe, 'wp-login.php' ) ) {
			return '';
		}
		return $safe;
	}

	/** Lien vers une page de l'espace enseignant, dans l'administration ou sur la page publique. */
	public static function url( string $page, array $args = array() ): string {
		if ( null !== self::$front_base && in_array( $page, self::FRONT_PAGES, true ) ) {
			return add_query_arg( array_merge( array( 'lw_view' => $page ), $args ), self::$front_base );
		}
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/** Ouvre un formulaire de filtre (GET) qui reste dans le bon contexte. */
	public static function get_form_open( string $page, array $hidden = array() ): void {
		if ( null !== self::$front_base ) {
			$parts = wp_parse_url( self::$front_base );
			$base  = array();
			if ( ! empty( $parts['query'] ) ) {
				wp_parse_str( $parts['query'], $base );
			}
			echo '<form method="get" class="lw-filters" action="' . esc_url( self::$front_base ) . '">';
			$hidden = array_merge( $base, array( 'lw_view' => $page ), $hidden );
		} else {
			echo '<form method="get" class="lw-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			$hidden = array_merge( array( 'page' => $page ), $hidden );
		}
		foreach ( $hidden as $k => $v ) {
			if ( is_scalar( $v ) ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
			}
		}
	}

	/** Titre et chapeau de la page affichée dans l'espace enseignant du site (repris dans le bandeau). */
	private static ?array $front_title = null;

	public static function front_title(): ?array {
		return self::$front_title;
	}

	public static function header( string $title, string $lead = '' ): void {
		echo '<div class="wrap logierp-admin">';
		if ( null !== self::$front_base && null === self::$front_title ) {
			// Espace enseignant : le titre et le chapeau vont dans le bandeau, comme dans le simulateur des apprentis.
			self::$front_title = array( 'title' => $title, 'lead' => $lead );
			self::notices();
			return;
		}
		if ( null === self::$front_base ) {
			echo '<div class="lw-admin-head"><img class="lw-admin-logo" src="' . esc_url( LOGIERP_URL . 'assets/img/logierp-logo-blanc.svg' ) . '" alt="LogiERP by LogiProf" width="300" height="80"><span class="lw-space-badge teacher">Espace enseignant</span></div>';
		}
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $lead ) {
			echo '<p class="lw-lead-admin">' . esc_html( $lead ) . '</p>';
		}
		self::notices();
	}

	/**
	 * Préparation du jeu de base (entrepôt, articles, partenaires, stock, scénarios).
	 * Pour un administrateur, la génération démarre automatiquement (barre de progression).
	 */
	public static function seed_box(): void {
		$state = \LogiERP\Seeder\JobRunner::state();
		if ( 'done' === $state['state'] ) {
			return;
		}
		echo '<div class="lw-box yellow"><h2>Préparation des données</h2>';
		if ( current_user_can( \LogiERP\Core\Capabilities::SETTINGS ) ) {
			$auto = 'error' !== $state['state'];
			echo $state['step'] > 0 && (int) get_option( 'logierp_data_rev', 1 ) < \LogiERP\Seeder\JobRunner::DATA_REV
				? '<p>Nouvelle version : LogiERP complète votre jeu de données (fournisseurs européens, stock pour chaque article, nouveau scénario modèle). Vos articles, scénarios et sessions sont conservés. Gardez cette page ouverte quelques secondes.</p>'
				: '<p>LogiERP prépare son jeu de données : entrepôt de plus de 5 000 emplacements répartis en zones, 3 000 articles en stock, 130 fournisseurs (100 en Suisse, 30 en Europe), 100 clients en Suisse et 19 scénarios modèles. Cela prend environ une minute ; gardez cette page ouverte.</p>';
			echo '<div class="lw-progress" id="lw-seed" data-state="' . esc_attr( $state['state'] ) . '" data-autostart="' . ( $auto ? '1' : '0' ) . '"><div class="lw-progress-bar"><span style="width:' . esc_attr( (string) round( 100 * $state['step'] / max( 1, $state['total'] ) ) ) . '%"></span></div><p class="lw-progress-label">' . esc_html( $state['step'] . ' / ' . $state['total'] . ' – ' . $state['label'] . ( 'error' === $state['state'] && $state['message'] ? ' · ' . $state['message'] : '' ) ) . '</p></div>';
			echo '<p><button type="button" class="button button-primary" id="lw-seed-start">' . esc_html( 'error' === $state['state'] ? 'Reprendre la préparation' : 'Préparer les données' ) . '</button></p>';
		} else {
			echo '<p>Les données de LogiERP sont en cours de préparation. Un administrateur du site doit ouvrir une fois le tableau de bord LogiERP pour la lancer ; revenez dans quelques minutes.</p>';
		}
		echo '</div>';
	}

	public static function footer(): void {
		echo '</div>';
	}

	/** Notice transmise par redirection (clé en liste blanche, texte stocké côté serveur). */
	public static function notices(): void {
		$key = isset( $_GET['lw_notice'] ) ? sanitize_key( wp_unslash( $_GET['lw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage seul.
		if ( '' === $key ) {
			return;
		}
		$data = get_transient( 'lw_notice_' . get_current_user_id() . '_' . $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( 'lw_notice_' . get_current_user_id() . '_' . $key );
		$cls = 'error' === $data['type'] ? 'notice-error' : ( 'warning' === $data['type'] ? 'notice-warning' : 'notice-success' );
		echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $data['text'] ) . '</p>';
		if ( ! empty( $data['details'] ) ) {
			echo '<ul style="list-style:disc;margin-left:20px">';
			foreach ( (array) $data['details'] as $d ) {
				echo '<li>' . esc_html( (string) $d ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** Redirige vers une page d'administration avec une notice. */
	public static function redirect( string $page, array $args, string $type, string $text, array $details = array() ): void {
		$key = substr( md5( uniqid( '', true ) ), 0, 10 );
		set_transient( 'lw_notice_' . get_current_user_id() . '_' . $key, array( 'type' => $type, 'text' => $text, 'details' => $details ), 120 );
		// Retour vers la page publique si le formulaire venait du code court (adresse du même site uniquement).
		$front = isset( $_POST['lw_front'] ) ? self::valid_front_base( wp_unslash( (string) $_POST['lw_front'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant (Ui::guard).
		if ( '' !== $front ) {
			self::$front_base = $front;
		}
		wp_safe_redirect( add_query_arg( array( 'lw_notice' => $key ), self::url( $page, $args ) ) );
		exit;
	}

	public static function form_open( string $action, array $hidden = array(), string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs fixes fournis par le code.
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		if ( null !== self::$front_base ) {
			echo '<input type="hidden" name="lw_front" value="' . esc_attr( self::$front_base ) . '">';
		}
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
	}

	public static function form_close( string $label, string $class = 'button button-primary', string $confirm = '' ): void {
		$onclick = '' !== $confirm ? ' onclick="return window.confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '';
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . $onclick . '>' . esc_html( $label ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé ci-dessus.
	}

	/** Petit formulaire à un bouton (action POST protégée par nonce). */
	public static function button_form( string $action, array $hidden, string $label, string $class = 'button', string $confirm = '' ): void {
		self::form_open( $action, $hidden, 'style="display:inline"' );
		self::form_close( $label, $class, $confirm );
	}

	public static function field( string $label, string $html, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html construit avec des fonctions d'échappement.
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function input( string $name, string $value, string $type = 'text', string $extra = '' ): string {
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" ' . $extra . '>';
	}

	public static function textarea( string $name, string $value, int $rows = 4 ): string {
		return '<textarea name="' . esc_attr( $name ) . '" rows="' . (int) $rows . '" class="large-text">' . esc_textarea( $value ) . '</textarea>';
	}

	/** @param array<string,string> $options */
	public static function select( string $name, array $options, string $value ): string {
		$h = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $l ) {
			$h .= '<option value="' . esc_attr( (string) $k ) . '"' . selected( (string) $k, $value, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $h . '</select>';
	}

	public static function checkbox( string $name, bool $checked, string $label ): string {
		return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/** Lecture POST nettoyée (après check_admin_referer). */
	public static function post_str( string $key, int $max = 190 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
		return isset( $_POST[ $key ] ) ? mb_substr( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ), 0, $max ) : '';
	}

	public static function post_text( string $key, int $max = 5000 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
		return isset( $_POST[ $key ] ) ? mb_substr( sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ), 0, $max ) : '';
	}

	public static function post_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
		return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
	}

	public static function post_float( string $key ): float {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
		return isset( $_POST[ $key ] ) && is_numeric( $_POST[ $key ] ) ? (float) $_POST[ $key ] : 0.0;
	}

	public static function post_bool( string $key ): bool {
		return ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
	}

	public static function get_int( string $key ): int {
		return isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture d'un identifiant, droits vérifiés ensuite.
	}

	public static function get_str( string $key ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre d'affichage.
	}

	/** Contrôle commun des actions POST : nonce + capacité. */
	public static function guard( string $action, string $cap ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'logierp' ), '', array( 'response' => 403 ) );
		}
	}

	public static function fr_datetime( ?string $d ): string {
		if ( ! $d || str_starts_with( $d, '1970' ) ) {
			return '—';
		}
		return wp_date( 'd.m.Y H:i', strtotime( $d . ' UTC' ) );
	}

	public static function grade_badge( $grade ): string {
		if ( null === $grade || '' === $grade ) {
			return '—';
		}
		$g   = (float) $grade;
		$cls = $g >= 5 ? 'good' : ( $g >= 4 ? 'mid' : 'bad' );
		return '<span class="lw-gr ' . esc_attr( $cls ) . '">' . esc_html( number_format( $g, 1 ) ) . '</span>';
	}

	public static function app_link( int $session_id, string $label ): string {
		return '<a class="button" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Core\Activator::app_url() . '#/s/' . $session_id ) . '">' . esc_html( $label ) . '</a>';
	}
}
