<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Admin\AdminMenu;
use LogiERP\Admin\Ui;
use LogiERP\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Espace enseignant sur le site : code court [logierp_enseignant].
 *
 * Affiche les mêmes pages que l'administration (mêmes contrôles de droits, mêmes actions protégées
 * par nonce via admin-post.php) ; les liens et les retours d'action restent sur la page publique.
 * Les réglages et les outils techniques restent réservés à l'administration.
 */
final class TeacherSpace {

	public const SHORTCODE = 'logierp_enseignant';

	private static bool $loaded = false;

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		// Menus du site : le lien vers l'espace enseignant n'apparaît qu'aux enseignants.
		add_filter( 'wp_nav_menu_objects', array( $this, 'hide_menu_items' ) );
		add_filter( 'render_block_core/navigation-link', array( $this, 'hide_nav_block' ), 10, 2 );
		add_filter( 'render_block_core/navigation-submenu', array( $this, 'hide_nav_block' ), 10, 2 );
		add_filter( 'wp_list_pages_excludes', array( $this, 'exclude_page' ) );
		add_filter( 'get_pages', array( $this, 'filter_pages' ) );
	}

	public function register_assets(): void {
		wp_register_style( 'logierp-admin', LOGIERP_URL . 'assets/css/admin.css', array(), LOGIERP_VERSION );
		wp_register_style( 'logierp-teacher', LOGIERP_URL . 'assets/css/teacher.css', array( 'logierp-admin' ), LOGIERP_VERSION );
		wp_register_script( 'logierp-admin', LOGIERP_URL . 'assets/js/admin.js', array(), LOGIERP_VERSION, true );
	}

	public static function page_uses_space(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	public function maybe_enqueue(): void {
		if ( self::page_uses_space() ) {
			self::load();
		}
	}

	public function body_class( array $classes ): array {
		if ( self::page_uses_space() ) {
			$classes[] = 'logierp-page';
		}
		return $classes;
	}

	private static function is_teacher(): bool {
		return is_user_logged_in() && current_user_can( AdminMenu::PAGES['logierp'][1] );
	}

	/** Pages qui portent l'espace enseignant (adresse sans le domaine ni la barre finale, pour comparer les liens). */
	private static function teacher_targets(): array {
		$out = array();
		$id  = (int) get_option( 'logierp_teacher_page_id', 0 );
		if ( $id ) {
			$out[] = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) ) . '?' . (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_QUERY );
		}
		return array_filter( $out );
	}

	private static function points_to_teacher( string $url, int $object_id = 0 ): bool {
		$id = (int) get_option( 'logierp_teacher_page_id', 0 );
		if ( $id && $object_id === $id ) {
			return true;
		}
		if ( '' === $url ) {
			return false;
		}
		$key = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
		return in_array( $key, self::teacher_targets(), true );
	}

	/** Menus classiques : retire l'entrée « Espace enseignant » (et ses sous-entrées) pour un apprenti ou un visiteur. */
	public function hide_menu_items( $items ) {
		if ( self::is_teacher() || ! is_array( $items ) ) {
			return $items;
		}
		$removed = array();
		foreach ( $items as $k => $item ) {
			$parent = (int) ( $item->menu_item_parent ?? 0 );
			if ( in_array( $parent, $removed, true ) || ( 'post_type' === ( $item->type ?? '' ) && self::points_to_teacher( '', (int) $item->object_id ) ) || self::points_to_teacher( (string) ( $item->url ?? '' ) ) ) {
				$removed[] = (int) $item->ID;
				unset( $items[ $k ] );
			}
		}
		return $items;
	}

	/** Listes automatiques de pages (menu par défaut, bloc « Liste des pages »). */
	public function exclude_page( $ids ) {
		$id = (int) get_option( 'logierp_teacher_page_id', 0 );
		if ( $id && ! self::is_teacher() ) {
			$ids   = (array) $ids;
			$ids[] = $id;
		}
		return $ids;
	}

	public function filter_pages( $pages ) {
		$id = (int) get_option( 'logierp_teacher_page_id', 0 );
		if ( ! $id || is_admin() || self::is_teacher() || ! is_array( $pages ) ) {
			return $pages;
		}
		return array_values( array_filter( $pages, static fn( $p ) => ! is_object( $p ) || (int) $p->ID !== $id ) );
	}

	/** Navigation des thèmes par blocs : même règle. */
	public function hide_nav_block( $html, $block ) {
		if ( self::is_teacher() ) {
			return $html;
		}
		$a   = (array) ( $block['attrs'] ?? array() );
		$id  = (int) ( $a['id'] ?? $a['pageId'] ?? 0 );
		$url = (string) ( $a['url'] ?? $a['link'] ?? '' );
		return self::points_to_teacher( $url, $id ) ? '' : $html;
	}

	private static function load(): void {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;
		wp_enqueue_style( 'logierp-teacher' );
		if ( ! is_user_logged_in() || ! current_user_can( AdminMenu::PAGES['logierp'][1] ) ) {
			return; // Pas de nonce REST ni de script pour un visiteur sans droits.
		}
		wp_enqueue_script( 'logierp-admin' );
		wp_add_inline_script(
			'logierp-admin',
			'window.LOGIERP_ADMIN = ' . wp_json_encode(
				array(
					'rest'   => esc_url_raw( rest_url( \LogiERP\Rest\Routes::NS . '/' ) ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'appUrl' => esc_url_raw( \LogiERP\Core\Activator::app_url() ),
				)
			) . ';',
			'before'
		);
	}

	/** Adresse de la page courante, sans les paramètres propres à l'espace enseignant. */
	private static function base_url(): string {
		$id  = get_queried_object_id();
		$url = $id ? (string) get_permalink( $id ) : '';
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		return Ui::valid_front_base( $url );
	}

	private static function shell( string $inner ): string {
		return '<div class="logierp-teacher">' . $inner . '</div>';
	}

	private static function card( string $title, string $text, string $button = '', string $href = '' ): string {
		$html = '<div class="lw-t-body"><div class="lw-t-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $button ) {
			$html .= '<p><a class="button button-primary" href="' . esc_url( $href ) . '">' . esc_html( $button ) . '</a></p>';
		}
		return $html . '</div></div>';
	}

	/**
	 * @param array|string $atts Attributs du code court (aucun n'est utilisé).
	 */
	public function render( $atts = array() ): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Contenu propre à l'utilisateur connecté.
		}
		// Le code court n'est rendu qu'une fois par requête (évite un double affichage via un extrait ou un widget).
		static $done = false;
		if ( $done || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$done = true;
		self::load();
		$base = self::base_url();

		if ( ! is_user_logged_in() ) {
			return self::shell( self::head( array(), '', $base, array( 'title' => 'Espace enseignant', 'lead' => 'Concevez des scénarios, pilotez vos classes et contrôlez les résultats de vos apprentis.' ) ) . self::card( 'Espace enseignant LogiERP', 'Connectez-vous avec votre compte enseignant pour gérer vos scénarios, vos classes et vos résultats.', 'Se connecter', wp_login_url( $base ) ) );
		}
		if ( ! current_user_can( AdminMenu::PAGES['logierp'][1] ) ) {
			return self::shell( self::head( array(), '', $base, array( 'title' => 'Accès réservé', 'lead' => '' ) ) . self::card( 'Accès réservé aux enseignants', 'Votre compte n\'a pas accès à l\'espace enseignant. Si vous êtes apprenant, utilisez la page du simulateur.', 'Ouvrir le simulateur', \LogiERP\Core\Activator::app_url() ) );
		}

		$pages = array();
		foreach ( Ui::FRONT_PAGES as $slug ) {
			if ( current_user_can( AdminMenu::PAGES[ $slug ][1] ) ) {
				$pages[ $slug ] = AdminMenu::PAGES[ $slug ][0];
			}
		}
		$view = Ui::get_str( 'lw_view' );
		$view = isset( $pages[ $view ] ) ? $view : 'logierp';

		Ui::set_front( $base );
		ob_start();
		try {
			( new AdminMenu() )->dispatch( $view );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			Ui::set_front( null );
			\LogiERP\Support\Logger::error( 'Espace enseignant public : ' . $e->getMessage() );
			return self::shell( self::head( $pages, $view, $base, array( 'title' => $pages[ $view ] ?? 'LogiERP', 'lead' => '' ) ) . self::card( 'Erreur', 'Cette page n\'a pas pu être affichée. L\'erreur a été enregistrée dans le journal technique.' ) );
		}
		$content = (string) ob_get_clean();
		$t       = Ui::front_title() ?? array( 'title' => $pages[ $view ] ?? 'LogiERP', 'lead' => '' );
		Ui::set_front( null );
		if ( 'logierp' === $view ) {
			$u = wp_get_current_user();
			$t = array( 'title' => 'Bonjour ' . ( $u->first_name ?: $u->display_name ), 'lead' => 'Créez vos scénarios, assignez-les à vos classes, suivez vos apprentis en direct et publiez leurs résultats.', 'art' => true );
		}

		return self::shell( self::head( $pages, $view, $base, $t ) . '<div class="lw-t-body">' . $content . '</div>' );
	}

	/**
	 * Bandeau, sur le modèle du simulateur des apprentis : logo et badge « Espace enseignant », utilisateur,
	 * titre de la page (ou « Bonjour … » sur le tableau de bord) puis navigation en pastilles.
	 *
	 * @param array{title:string,lead:string,art?:bool} $t
	 */
	private static function head( array $pages, string $current, string $base, array $t ): string {
		$html = '<header class="lw-t-head"><div class="lw-t-top"><div class="lw-t-brandrow"><a class="lw-brand" href="' . esc_url( $base ) . '"><img class="lw-logo" src="' . esc_url( LOGIERP_URL . 'assets/img/logierp-logo-blanc.svg' ) . '" alt="LogiERP by LogiProf" width="300" height="80"></a><span class="lw-space-badge teacher">Espace enseignant</span></div>';
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$html .= '<div class="lw-user"><strong>' . esc_html( $user->display_name ) . '</strong>';
			$html .= '<a href="' . esc_url( \LogiERP\Core\Activator::app_url() ) . '" title="Ouvrir le simulateur comme un apprenti">Simulateur apprenti</a>';
			if ( current_user_can( Capabilities::SETTINGS ) ) {
				$html .= '<a href="' . esc_url( admin_url( 'admin.php?page=logierp-settings' ) ) . '">Réglages</a>';
			}
			$html .= '<a href="' . esc_url( wp_logout_url( $base ) ) . '">Se déconnecter</a></div>';
		}
		$html .= '</div>';
		$art   = ! empty( $t['art'] );
		$html .= '<div class="lw-t-main' . ( $art ? ' has-art' : '' ) . '"><div class="lw-t-text"><p class="lw-t-kicker">Espace enseignant · Simulateur ERP / WMS</p><h1 class="lw-t-title">' . esc_html( $t['title'] ) . '</h1>' . ( '' !== $t['lead'] ? '<p class="lw-t-lead">' . esc_html( $t['lead'] ) . '</p>' : '' );
		// Navigation dans la colonne du texte : l'illustration reste à droite et ne la recouvre jamais.
		if ( $pages ) {
			$html .= '<nav class="lw-t-nav" aria-label="Espace enseignant">';
			foreach ( $pages as $slug => $label ) {
				$url   = 'logierp' === $slug ? $base : add_query_arg( 'lw_view', $slug, $base );
				$html .= '<a href="' . esc_url( $url ) . '"' . ( $slug === $current ? ' class="active" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			$html .= '</nav>';
		}
		$html .= '</div>';
		if ( $art ) {
			$html .= '<div class="lw-t-art" aria-hidden="true">' . (string) file_get_contents( LOGIERP_DIR . 'assets/img/hero.svg' ) . '</div>'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- illustration SVG statique du plugin.
		}
		$html .= '</div>';
		return $html . '</header>';
	}
}
