<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Core\Activator;
use LogiERP\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Espace apprenant : code court [logierp_eleve] (alias historique [logierp_app]) et bloc logierp/app.
 * Les scripts et styles ne sont chargés que sur les pages qui affichent l'application.
 */
final class App {

	private static bool $loaded = false;

	public function register(): void {
		add_shortcode( 'logierp_eleve', array( $this, 'render' ) );
		add_shortcode( 'logierp_app', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'admin_post_logierp_join', array( $this, 'handle_join' ) );
	}

	/** Formulaire « Rejoindre une classe » pour un compte sans accès (sans JavaScript). */
	public function handle_join(): void {
		check_admin_referer( 'logierp_join' );
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		$ok   = false;
		if ( \LogiERP\Security\RateLimiter::hit( 'join' ) ) {
			[ $ok ] = \LogiERP\Admin\ClassesPage::join_by_code( get_current_user_id(), $code );
		}
		wp_safe_redirect( $ok ? Activator::app_url() : add_query_arg( 'lw_join', 'error', Activator::app_url() ) );
		exit;
	}

	private static function load_css(): void {
		wp_enqueue_style( 'logierp-app' );
	}

	public function register_assets(): void {
		wp_register_style( 'logierp-app', LOGIERP_URL . 'assets/css/app.css', array(), LOGIERP_VERSION );
		wp_register_script( 'logierp-barcode', LOGIERP_URL . 'assets/js/barcode.js', array(), LOGIERP_VERSION, true );
		wp_register_script( 'logierp-sap', LOGIERP_URL . 'assets/js/sap.js', array( 'logierp-barcode' ), LOGIERP_VERSION, true );
		wp_register_script( 'logierp-app', LOGIERP_URL . 'assets/js/app.js', array( 'logierp-barcode', 'logierp-sap' ), LOGIERP_VERSION, true );
	}

	public function register_block(): void {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				'logierp/app',
				array(
					'title'           => 'LogiERP',
					'render_callback' => array( $this, 'render' ),
				)
			);
		}
	}

	private static function page_uses_app(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		return has_shortcode( (string) $post->post_content, 'logierp_eleve' ) || has_shortcode( (string) $post->post_content, 'logierp_app' ) || has_block( 'logierp/app', $post ) || (int) get_option( 'logierp_page_id' ) === (int) $post->ID;
	}

	public function maybe_enqueue(): void {
		if ( self::page_uses_app() ) {
			self::load();
		}
	}

	public function body_class( array $classes ): array {
		if ( self::page_uses_app() ) {
			$classes[] = 'logierp-page';
		}
		return $classes;
	}

	private static function load(): void {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;
		wp_enqueue_style( 'logierp-app' );
		wp_enqueue_script( 'logierp-app' );
		$user = wp_get_current_user();
		$data = array(
			'rest'      => esc_url_raw( rest_url( \LogiERP\Rest\Routes::NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'assets'    => esc_url_raw( LOGIERP_URL . 'assets/' ),
			'appUrl'    => esc_url_raw( Activator::app_url() ),
			'logoutUrl' => esc_url_raw( wp_logout_url( Activator::app_url() ) ),
			'adminUrl'  => Capabilities::is_teacher() ? esc_url_raw( Activator::teacher_url() ) : '',
			'hero'      => (string) file_get_contents( LOGIERP_DIR . 'assets/img/hero.svg' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du plugin.
			'user'      => array( 'name' => $user->display_name, 'first' => $user->first_name ?: $user->display_name ),
			'company'   => is_user_logged_in() ? \LogiERP\Domain\Company::for_app() : null,
			'manual'    => Manuals::can( 'user' ) && Manuals::info( 'user' )['exists'] ? array( 'url' => esc_url_raw( Manuals::url( 'user' ) ), 'download' => esc_url_raw( Manuals::url( 'user', true ) ), 'pages' => Manuals::tx_pages() ) : null,
			'fiches'    => Manuals::can( 'fiches' ) && Manuals::info( 'fiches' )['exists'] ? array( 'url' => esc_url_raw( Manuals::url( 'fiches' ) ), 'download' => esc_url_raw( Manuals::url( 'fiches', true ) ), 'pages' => Manuals::fiche_pages() ) : null,
		);
		wp_add_inline_script( 'logierp-app', 'window.LOGIERP = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public function render( $atts = array() ): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // La page dépend de l'utilisateur connecté.
		}
		if ( ! is_user_logged_in() ) {
			return '<div class="logierp-app lw-guest"><div class="lw-body"><div class="lw-card lw-login-card"><h3>' . esc_html__( 'Simulateur LogiERP', 'logierp' ) . '</h3><p>' . esc_html__( 'Connectez-vous pour accéder à vos scénarios.', 'logierp' ) . '</p><a class="lw-btn lw-btn-primary" href="' . esc_url( wp_login_url( Activator::app_url() ) ) . '">' . esc_html__( 'Se connecter', 'logierp' ) . '</a></div></div></div>';
		}
		// Élève d'un groupe Sensei lié à une classe : inscription immédiate, sans attendre la synchronisation horaire.
		\LogiERP\Domain\SenseiGroups::sync_user( get_current_user_id() );
		if ( ! current_user_can( Capabilities::PLAY ) ) {
			$err  = isset( $_GET['lw_join'] ) && 'error' === $_GET['lw_join']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage d'un message fixe.
			$html = '<div class="logierp-app"><div class="lw-body"><div class="lw-card lw-login-card"><h3>' . esc_html__( 'Simulateur LogiERP', 'logierp' ) . '</h3><p>' . esc_html__( 'Votre compte n\'a pas encore accès au simulateur. Saisissez le code d\'invitation donné par votre enseignant, ou demandez-lui de vous inscrire dans une classe.', 'logierp' ) . '</p>';
			if ( $err ) {
				$html .= '<div class="lw-msg error" role="alert"><div>' . esc_html__( 'Code invalide ou expiré.', 'logierp' ) . '</div></div>';
			}
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw-join-row"><input type="hidden" name="action" value="logierp_join">' . wp_nonce_field( 'logierp_join', '_wpnonce', true, false ) . '<input type="text" name="code" maxlength="12" required autocomplete="off" aria-label="' . esc_attr__( 'Code d\'invitation', 'logierp' ) . '"><button type="submit" class="lw-btn lw-btn-primary">' . esc_html__( 'Rejoindre', 'logierp' ) . '</button></form></div></div></div>';
			self::load_css();
			return $html;
		}
		self::load();
		return '<div id="logierp-root" class="logierp-app" data-version="' . esc_attr( LOGIERP_VERSION ) . '"><noscript>' . esc_html__( 'LogiERP nécessite JavaScript.', 'logierp' ) . '</noscript><div class="lw-loading">' . esc_html__( 'Chargement du simulateur…', 'logierp' ) . '</div></div>';
	}
}
