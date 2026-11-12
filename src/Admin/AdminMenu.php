<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Seeder\JobRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu principal « LogiERP » de l'administration et pages de l'espace enseignant
 * (les mêmes pages sont aussi affichées par le code court [logierp_enseignant]).
 */
final class AdminMenu {

	public const PAGES = array(
		'logierp'           => array( 'Tableau de bord', Capabilities::VIEW_CLASS ),
		'logierp-scenarios' => array( 'Scénarios', Capabilities::SCENARIOS ),
		'logierp-classes'   => array( 'Classes et assignations', Capabilities::CLASSES ),
		'logierp-live'      => array( 'Suivi en direct', Capabilities::VIEW_CLASS ),
		'logierp-results'   => array( 'Résultats', Capabilities::VIEW_CLASS ),
		'logierp-labels'    => array( 'Étiquettes', Capabilities::SCENARIOS ),
		'logierp-manuals'   => array( 'Manuels', Capabilities::VIEW_CLASS ),
		'logierp-data'      => array( 'Données de base', Capabilities::MASTER_DATA ),
		'logierp-warehouse' => array( 'Entrepôt', Capabilities::MASTER_DATA ),
		'logierp-company'   => array( 'Entreprise', Capabilities::MASTER_DATA ),
		'logierp-settings'  => array( 'Réglages', Capabilities::SETTINGS ),
		'logierp-tools'     => array( 'Outils et santé', Capabilities::SETTINGS ),
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		( new ScenariosPage() )->register();
		( new ClassesPage() )->register();
		( new ResultsPage() )->register();
		( new DataPage() )->register();
		( new CompanyPage() )->register();
		( new SettingsPage() )->register();
		( new ManualsPage() )->register();
	}

	/** Icône du menu : le cube LogiERP en monochrome (WordPress la recolore selon le thème d'administration). */
	private static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1 17.5 5.3v8.4L10 18 2.5 13.7V5.3Zm0 1.7L4.4 5.9 10 9.1l5.6-3.2ZM3.9 7.1v5.8l5.4 3.1v-5.8Zm12.2 0-5.4 3.1V16l5.4-3.1Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icône SVG du menu.
	}

	public function menu(): void {
		add_menu_page( 'LogiERP – Tableau de bord', 'LogiERP', self::PAGES['logierp'][1], 'logierp', array( $this, 'render' ), self::icon(), 26 );
		foreach ( self::PAGES as $slug => [ $title, $cap ] ) {
			add_submenu_page( 'logierp', 'LogiERP – ' . $title, $title, $cap, $slug, array( $this, 'render' ) );
		}
	}

	/** Après l'activation : ouvre le tableau de bord, où la génération du jeu de base démarre seule. */
	public function activation_redirect(): void {
		if ( ! get_transient( 'logierp_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'logierp_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture d'un indicateur WordPress.
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || is_network_admin() || ! current_user_can( Capabilities::SETTINGS ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=logierp' ) );
		exit;
	}

	public function assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix des ressources à charger.
		if ( ! isset( self::PAGES[ $page ] ) ) {
			return;
		}
		wp_enqueue_style( 'logierp-admin', LOGIERP_URL . 'assets/css/admin.css', array(), LOGIERP_VERSION );
		wp_enqueue_script( 'logierp-admin', LOGIERP_URL . 'assets/js/admin.js', array(), LOGIERP_VERSION, true );
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

	public function render(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'logierp'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routage d'affichage.
		if ( ! isset( self::PAGES[ $page ] ) || ! current_user_can( self::PAGES[ $page ][1] ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'logierp' ) );
		}
		$this->dispatch( $page );
	}

	/** Affiche une page de l'espace enseignant (droits vérifiés par l'appelant). */
	public function dispatch( string $page ): void {
		switch ( $page ) {
			case 'logierp-scenarios':
				( new ScenariosPage() )->render();
				break;
			case 'logierp-classes':
				( new ClassesPage() )->render();
				break;
			case 'logierp-live':
				( new ResultsPage() )->render_live();
				break;
			case 'logierp-results':
				( new ResultsPage() )->render();
				break;
			case 'logierp-labels':
				( new LabelsPage() )->render();
				break;
			case 'logierp-manuals':
				( new ManualsPage() )->render();
				break;
			case 'logierp-data':
				( new DataPage() )->render();
				break;
			case 'logierp-warehouse':
				( new WarehousePage() )->render();
				break;
			case 'logierp-company':
				( new CompanyPage() )->render();
				break;
			case 'logierp-settings':
				( new SettingsPage() )->render();
				break;
			case 'logierp-tools':
				( new SettingsPage() )->render_tools();
				break;
			default:
				$this->dashboard();
		}
	}

	private function dashboard(): void {
		global $wpdb;
		Ui::header( 'Tableau de bord', 'Simulateur ERP / WMS pédagogique : créez des scénarios, assignez-les à vos classes et suivez vos apprenants.' );
		Ui::seed_box();
		$uid  = get_current_user_id();
		$all  = current_user_can( Capabilities::VIEW_ALL );
		$c    = Db::t( 'class' );
		$a    = Db::t( 'assignment' );
		$s    = Db::t( 'session' );
		$sc   = Db::t( 'scenario' );
		$own  = $all ? '1=1' : $wpdb->prepare( 'c.teacher_id = %d', $uid );
		$classes  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} c WHERE {$own}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$running  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} x JOIN {$a} a ON a.id = x.assignment_id JOIN {$c} c ON c.id = a.class_id WHERE {$own} AND x.status = 'EN_COURS'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$to_pub   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} x JOIN {$a} a ON a.id = x.assignment_id JOIN {$c} c ON c.id = a.class_id WHERE {$own} AND x.status = 'CORRIGEE' AND x.published = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$scenarios = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sc} WHERE deleted_at IS NULL AND (owner_id = %d OR shared = 1)", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-kpis">';
		foreach ( array( array( $scenarios, 'Scénarios disponibles', 'logierp-scenarios' ), array( $classes, 'Classes', 'logierp-classes' ), array( $running, 'Sessions en cours', 'logierp-live' ), array( $to_pub, 'Résultats à publier', 'logierp-results' ) ) as $k ) {
			echo '<a class="lw-kpi" href="' . esc_url( Ui::url( $k[2] ) ) . '"><strong>' . esc_html( (string) $k[0] ) . '</strong><span>' . esc_html( $k[1] ) . '</span></a>';
		}
		echo '</div>';
		echo '<div class="lw-box"><h2>Démarrer en 5 étapes</h2><ol class="lw-steps">';
		echo '<li><strong>Testez un scénario modèle</strong> : Scénarios › « Tester ». Vous jouez comme un apprenant, dans une session jetable.</li>';
		echo '<li><strong>Créez une classe</strong> et inscrivez vos apprenants (comptes existants, import CSV ou code d\'invitation).</li>';
		echo '<li><strong>Assignez un scénario</strong> à la classe avec des dates d\'ouverture et de fermeture, et distribuez son <strong>document d\'activité</strong> (fiche de mission imprimable, aussi disponible sur le portail des apprentis).</li>';
		echo '<li><strong>Suivez en direct</strong>, déclenchez des imprévus, puis corrigez et publiez les résultats.</li><li><strong>Équipez l\'atelier</strong> (facultatif) : imprimez les étiquettes code-barres des articles, des emplacements et des palettes (menu Étiquettes) pour scanner avec le terminal mobile.</li>';
		echo '</ol><p>Page de l\'application pour les apprenants : <a href="' . esc_url( \LogiERP\Core\Activator::app_url() ) . '" target="_blank" rel="noopener">' . esc_html( \LogiERP\Core\Activator::app_url() ) . '</a></p></div>';
		if ( ! Ui::is_front() ) {
			echo '<div class="lw-box"><h2>Intégration dans votre site</h2><p>Placez ces codes courts dans n\'importe quelle page (bloc « Code court ») :</p><table class="widefat striped lw-shortcodes"><tbody>';
			echo '<tr><td><code>[logierp_eleve]</code></td><td>Espace apprenant : scénarios assignés, simulateur, résultats publiés. Un compte sans accès peut y saisir un code d\'invitation.</td></tr>';
			echo '<tr><td><code>[logierp_enseignant]</code></td><td>Espace enseignant sur le site : tableau de bord, scénarios, classes, suivi en direct, résultats' . ( current_user_can( Capabilities::MASTER_DATA ) ? ', données de base et entrepôt' : '' ) . '. Les réglages et les outils restent ici, dans l\'administration.</td></tr>';
			echo '</tbody></table><p class="description">Les deux pages exigent une connexion. Chaque personne n\'y voit que ce que son rôle autorise : un apprenant qui ouvre la page enseignant reçoit un message d\'accès refusé.</p></div>';
		}
		Ui::footer();
	}
}
