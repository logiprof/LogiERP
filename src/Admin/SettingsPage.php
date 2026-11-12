<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\SessionService;
use LogiERP\Security\Audit;
use LogiERP\Seeder\JobRunner;
use LogiERP\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Réglages, outils de maintenance et état de santé.
 */
final class SettingsPage {

	public function register(): void {
		foreach ( array( 'save', 'reset_all', 'purge', 'clear_log', 'check' ) as $a ) {
			add_action( 'admin_post_logierp_st_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	public function render(): void {
		Ui::header( 'Réglages', 'Paramètres généraux du simulateur.' );
		echo '<div class="lw-box">';
		Ui::form_open( 'logierp_st_save' );
		echo '<table class="form-table">';
		Ui::field( 'Tolérance de réception (%)', Ui::input( 'gr_tolerance', (string) (int) get_option( 'logierp_gr_tolerance', 5 ), 'number', 'min="0" max="50"' ), 'Une réception peut dépasser la quantité commandée de ce pourcentage. Au-delà, le système la refuse.' );
		Ui::field( 'Date simulée de référence', Ui::input( 'base_date', (string) get_option( 'logierp_base_date', '2026-01-05' ), 'date' ), 'Date du stock de base (DLC, dates des commandes ouvertes). Changer cette date ne modifie que les données générées ensuite.' );
		Ui::field( 'Enseignants', Ui::checkbox( 'teachers_edit_master', (bool) get_option( 'logierp_teachers_edit_master', 0 ), 'Les enseignants peuvent modifier les données de base et l\'entrepôt' ) );
		Ui::field( 'Journal d\'audit', Ui::input( 'audit_months', (string) (int) get_option( 'logierp_audit_months', 12 ), 'number', 'min="1" max="60"' ) . ' mois', 'Durée de conservation des traces d\'administration (adresses IP anonymisées).' );
		Ui::field( 'Désinstallation', Ui::checkbox( 'delete_on_uninstall', (bool) get_option( 'logierp_delete_on_uninstall', 0 ), 'Supprimer toutes les données LogiERP (tables, réglages, rôles) quand l\'extension est supprimée' ), 'Désactivé par défaut : désactiver ou supprimer l\'extension conserve les données.' );
		echo '</table>';
		Ui::form_close( 'Enregistrer les réglages' );
		echo '</div>';
		echo '<div class="lw-box"><h2>Page du simulateur</h2><p>Les apprenants accèdent au simulateur sur <a href="' . esc_url( \LogiERP\Core\Activator::app_url() ) . '" target="_blank" rel="noopener">' . esc_html( \LogiERP\Core\Activator::app_url() ) . '</a>. Vous pouvez aussi placer le code court <code>[logierp_app]</code> ou le bloc « LogiERP » sur une autre page.</p></div>';
		self::update_box();
		Ui::footer();
	}

	/** État des mises à jour (releases GitHub). */
	private static function update_box(): void {
		$st = \LogiERP\Core\Updater::status();
		if ( ! $st['checked'] ) {
			// Première visite (ou cache expiré) : une vérification, puis le résultat reste en cache plusieurs heures.
			\LogiERP\Core\Updater::latest();
			$st = \LogiERP\Core\Updater::status();
		}
		$rel = $st['release'];
		echo '<div class="lw-box"><h2>Mises à jour</h2><table class="form-table">';
		Ui::field( 'Version installée', '<strong>' . esc_html( LOGIERP_VERSION ) . '</strong>' );
		if ( $rel ) {
			$new = version_compare( $rel['version'], LOGIERP_VERSION, '>' );
			Ui::field( 'Dernière version publiée', '<strong>' . esc_html( $rel['version'] ) . '</strong>' . ( $rel['date'] ? ' du ' . esc_html( wp_date( 'd.m.Y', (int) strtotime( $rel['date'] ) ) ) : '' ) . ' · <a href="' . esc_url( $rel['url'] ) . '" target="_blank" rel="noopener">notes de version</a>' . ( $new ? ' · <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">installer la mise à jour</a>' : ' · à jour' ) );
		} elseif ( $st['error'] ) {
			Ui::field( 'Dernière vérification', '<span style="color:#b42318">' . esc_html( $st['error'] ) . '</span>' );
		}
		if ( $st['checked'] ) {
			Ui::field( 'Vérifiée le', esc_html( wp_date( 'd.m.Y à H:i', $st['checked'] ) ) );
		}
		Ui::field( 'Source', '<code>github.com/' . esc_html( \LogiERP\Core\Updater::REPO ) . '</code> · jeton d\'accès : ' . ( $st['token'] ? 'configuré dans wp-config.php' : 'aucun (nécessaire seulement si le dépôt est privé)' ), 'Chaque paquet est vérifié (empreinte SHA-256, dossier et numéro de version) avant installation. Les mises à jour automatiques s\'activent depuis la page Extensions.' );
		echo '</table>';
		if ( current_user_can( 'update_plugins' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=logierp_check_update' ), 'logierp_check_update' ) ) . '">Rechercher les mises à jour</a></p>';
		}
		echo '</div>';
	}

	public function handle_save(): void {
		Ui::guard( 'logierp_st_save', Capabilities::SETTINGS );
		update_option( 'logierp_gr_tolerance', max( 0, min( 50, Ui::post_int( 'gr_tolerance' ) ) ), false );
		$d = Ui::post_str( 'base_date', 10 );
		if ( \LogiERP\Domain\Api::valid_date( $d ) ) {
			update_option( 'logierp_base_date', $d, false );
		}
		update_option( 'logierp_teachers_edit_master', Ui::post_bool( 'teachers_edit_master' ) ? 1 : 0, false );
		update_option( 'logierp_audit_months', max( 1, min( 60, Ui::post_int( 'audit_months' ) ) ), false );
		update_option( 'logierp_delete_on_uninstall', Ui::post_bool( 'delete_on_uninstall' ) ? 1 : 0, false );
		Capabilities::install();
		Audit::log( 'settings_save' );
		Ui::redirect( 'logierp-settings', array(), 'success', 'Réglages enregistrés.' );
	}

	/* ───────── Outils et santé ───────── */

	public function render_tools(): void {
		global $wpdb;
		Ui::header( 'Outils et santé', 'Génération du jeu de base, maintenance, contrôles d\'intégrité et journal technique.' );

		$state = JobRunner::state();
		echo '<div class="lw-box"><h2>Jeu de données de base</h2>';
		echo '<div class="lw-progress" id="lw-seed" data-state="' . esc_attr( $state['state'] ) . '"><div class="lw-progress-bar"><span style="width:' . esc_attr( (string) round( 100 * $state['step'] / max( 1, $state['total'] ) ) ) . '%"></span></div><p class="lw-progress-label">' . esc_html( 'done' === $state['state'] ? 'Généré : ' . $state['total'] . ' étapes terminées.' : $state['step'] . ' / ' . $state['total'] . ' – ' . $state['label'] ) . '</p></div>';
		if ( 'done' !== $state['state'] ) {
			echo '<p><button type="button" class="button button-primary" id="lw-seed-start">' . esc_html( 'pending' === $state['state'] ? 'Générer le jeu de base' : 'Reprendre la génération' ) . '</button></p>';
		}
		if ( current_user_can( Capabilities::RESET ) ) {
			echo '<h3>Tout régénérer</h3><p class="description">Supprime <strong>toutes</strong> les données LogiERP (articles, fournisseurs, clients, zones et emplacements ajoutés, scénarios, classes, sessions et notes) et restaure le jeu de base d\'origine. Action irréversible : exportez d\'abord vos scénarios et résultats.</p>';
			Ui::form_open( 'logierp_st_reset_all' );
			echo '<p><label>Tapez <code>REGENERER</code> pour confirmer : <input type="text" name="confirm" autocomplete="off" required pattern="REGENERER" style="width:140px"></label> ';
			Ui::form_close( 'Tout supprimer', 'button lw-danger', 'Dernière confirmation : toutes les données LogiERP seront supprimées.' );
			echo '</p>';
		}
		echo '</div>';

		echo '<div class="lw-box"><h2>Nettoyage des sessions</h2>';
		$t     = Db::t( 'session' );
		$tests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_test = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<p>' . esc_html( sprintf( '%d session(s) au total, dont %d session(s) de test.', $all, $tests ) ) . '</p>';
		Ui::form_open( 'logierp_st_purge' );
		echo '<p><select name="what"><option value="tests">Supprimer les sessions de test</option><option value="old">Supprimer les sessions de plus de…</option></select> <input type="number" name="months" value="12" min="1" max="120" style="width:70px"> mois ';
		Ui::form_close( 'Nettoyer', 'button', 'Supprimer ces sessions définitivement ?' );
		echo '</p></div>';

		echo '<div class="lw-box"><h2>Contrôle d\'intégrité</h2>';
		$report = get_transient( 'logierp_integrity_' . get_current_user_id() );
		if ( is_array( $report ) ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $report as $r ) {
				echo '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . esc_html( (string) $r[1] ) . '</td><td>' . ( $r[2] ? '<span class="lw-ok">✔</span>' : '<span class="lw-ko">✘</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Ui::form_open( 'logierp_st_check' );
		echo '<p>';
		Ui::form_close( 'Lancer le contrôle', 'button' );
		echo '</p></div>';

		echo '<div class="lw-box"><h2>Santé</h2><table class="widefat striped"><tbody>';
		$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', Db::t( 'stock' ) ) );
		$health = array(
			array( 'Version LogiERP', LOGIERP_VERSION . ' (schéma ' . get_option( 'logierp_db_version', '?' ) . ')', get_option( 'logierp_db_version' ) === LOGIERP_DB_VERSION ),
			array( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) ),
			array( 'WordPress', get_bloginfo( 'version' ), version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ),
			array( 'Base de données', (string) $wpdb->db_server_info(), true ),
			array( 'Moteur des tables', $engine ?: '?', 'InnoDB' === $engine ),
			array( 'Limite mémoire PHP', (string) ini_get( 'memory_limit' ), wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) >= 128 * MB_IN_BYTES || '-1' === ini_get( 'memory_limit' ) ),
			array( 'WP-Cron', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'désactivé (cron système requis pour la maintenance)' : 'actif', true ),
			array( 'Page du simulateur', \LogiERP\Core\Activator::app_url(), (bool) get_post( (int) get_option( 'logierp_page_id', 0 ) ) ),
			array( 'Caméra du terminal mobile (HTTPS)', str_starts_with( (string) \LogiERP\Core\Activator::app_url(), 'https://' ) ? 'site en HTTPS : scan par la caméra possible' : 'site sans HTTPS : les navigateurs bloquent la caméra, seule la saisie au clavier ou la douchette fonctionne', str_starts_with( (string) \LogiERP\Core\Activator::app_url(), 'https://' ) ),
			array( 'Traitement des photos (GD)', function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ? 'disponible' : 'extension PHP GD absente : les photos de non-conformité sont refusées', function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ),
		);
		foreach ( $health as $h ) {
			echo '<tr><td>' . esc_html( $h[0] ) . '</td><td>' . esc_html( $h[1] ) . '</td><td>' . ( $h[2] ? '<span class="lw-ok">✔</span>' : '<span class="lw-ko">✘</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		$log = Logger::all();
		echo '<div class="lw-box"><h2>Journal technique (' . count( $log ) . ')</h2><p class="description">Erreurs internes, jamais affichées aux apprenants (ils voient un message générique).</p>';
		if ( $log ) {
			echo '<table class="widefat striped"><thead><tr><th>Date (UTC)</th><th>Message</th><th>Contexte</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 100 ) as $l ) {
				echo '<tr><td>' . esc_html( $l['t'] ) . '</td><td>' . esc_html( $l['m'] ) . '</td><td><code>' . esc_html( $l['c'] ) . '</code></td></tr>';
			}
			echo '</tbody></table><p>';
			Ui::button_form( 'logierp_st_clear_log', array(), 'Vider le journal', 'button' );
			echo '</p>';
		} else {
			echo '<p>Aucune erreur enregistrée.</p>';
		}
		echo '</div>';
		Ui::footer();
	}

	public function handle_reset_all(): void {
		Ui::guard( 'logierp_st_reset_all', Capabilities::RESET );
		if ( 'REGENERER' !== Ui::post_str( 'confirm', 20 ) ) {
			Ui::redirect( 'logierp-tools', array(), 'error', 'Confirmation incorrecte : rien n\'a été supprimé.' );
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		JobRunner::reset_all();
		Ui::redirect( 'logierp-tools', array(), 'success', 'Toutes les données ont été supprimées. Relancez la génération du jeu de base.' );
	}

	public function handle_purge(): void {
		global $wpdb;
		Ui::guard( 'logierp_st_purge', Capabilities::SETTINGS );
		$t = Db::t( 'session' );
		if ( 'old' === Ui::post_str( 'what', 10 ) ) {
			$months = max( 1, min( 120, Ui::post_int( 'months' ) ) );
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE started_at < %s LIMIT 2000", gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE is_test = 1 LIMIT 2000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $ids as $id ) {
			SessionService::delete_session( (int) $id );
		}
		Audit::log( 'sessions_purge', 'session', 0, array( 'count' => count( (array) $ids ) ) );
		Ui::redirect( 'logierp-tools', array(), 'success', sprintf( '%d session(s) supprimée(s).', count( (array) $ids ) ) );
	}

	public function handle_clear_log(): void {
		Ui::guard( 'logierp_st_clear_log', Capabilities::SETTINGS );
		Logger::clear();
		Ui::redirect( 'logierp-tools', array(), 'success', 'Journal vidé.' );
	}

	public function handle_check(): void {
		global $wpdb;
		Ui::guard( 'logierp_st_check', Capabilities::SETTINGS );
		$ds  = \LogiERP\Domain\Catalog::dataset();
		$cnt = static fn( string $table, string $where = '1=1' ) => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tables et conditions fixes.
		$missing = array();
		foreach ( Db::TABLES as $t ) {
			if ( Db::t( $t ) !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::t( $t ) ) ) ) {
				$missing[] = $t;
			}
		}
		$s  = Db::t( 'stock' );
		$b  = Db::t( 'bin' );
		$se = Db::t( 'session' );
		$r  = array(
			array( 'Tables présentes', $missing ? 'Manquantes : ' . implode( ', ', $missing ) : count( Db::TABLES ) . ' tables', ! $missing ),
			array( 'Articles', $cnt( 'material', 'dataset_id = ' . $ds ), $cnt( 'material', 'dataset_id = ' . $ds ) >= 3000 ),
			array( 'Fournisseurs / clients', $cnt( 'supplier', 'dataset_id = ' . $ds ) . ' / ' . $cnt( 'customer', 'dataset_id = ' . $ds ), $cnt( 'supplier', 'dataset_id = ' . $ds ) >= 100 && $cnt( 'customer', 'dataset_id = ' . $ds ) >= 100 ),
			array( 'Emplacements', $cnt( 'bin' ), $cnt( 'bin' ) > 0 ),
			array( 'Lignes de stock de référence', $cnt( 'stock', 'session_id = 0' ), $cnt( 'stock', 'session_id = 0' ) > 0 ),
			array( 'Stocks négatifs', $cnt( 'stock', 'qty < 0' ), 0 === $cnt( 'stock', 'qty < 0' ) ),
		);
		$wrong = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} s JOIN {$b} b ON b.id = s.bin_id WHERE s.bin_id > 0 AND b.storage_location_id <> s.storage_location_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r[]   = array( 'Stock rattaché au mauvais magasin', $wrong, 0 === $wrong );
		$orph  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} s LEFT JOIN {$se} x ON x.id = s.session_id WHERE s.session_id > 0 AND x.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r[]   = array( 'Stock de sessions supprimées', $orph, 0 === $orph );
		$viol  = count( \LogiERP\Scenario\Grader::non_compliant_in( 0 ) );
		$r[]   = array( 'Stock de base hors critères de stockage', $viol, 0 === $viol );
		set_transient( 'logierp_integrity_' . get_current_user_id(), $r, HOUR_IN_SECONDS );
		Ui::redirect( 'logierp-tools', array(), 'success', 'Contrôle terminé.' );
	}
}
