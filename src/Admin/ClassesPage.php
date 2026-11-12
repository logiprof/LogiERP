<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\SenseiGroups;
use LogiERP\Domain\Teams;
use LogiERP\Security\Audit;
use LogiERP\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classes, inscriptions (groupe Sensei, compte existant, import CSV, code d'invitation) et assignations de scénarios.
 */
final class ClassesPage {

	private const PAGE = 'logierp-classes';

	public function register(): void {
		foreach ( array( 'create', 'update', 'delete', 'add_member', 'remove_member', 'import', 'invite', 'invite_off', 'assign', 'unassign', 'sensei_create', 'sensei_link', 'sensei_unlink', 'sensei_sync', 'group_create', 'group_rename', 'group_delete', 'groups_save', 'groups_split', 'roster_code', 'roster_move', 'roster_add', 'roster_remove', 'roster_team', 'roster_enable', 'codes_print' ) as $a ) {
			add_action( 'admin_post_logierp_cl_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	/** Classe si l'utilisateur courant en est l'enseignant (ou voit tout). */
	public static function owned( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'class' );
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $c ) {
			return null;
		}
		if ( (int) $c['teacher_id'] !== get_current_user_id() && ! current_user_can( Capabilities::VIEW_ALL ) ) {
			return null;
		}
		return $c;
	}

	private static function owned_or_die( int $id ): array {
		$c = self::owned( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Classe introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		return $c;
	}

	/** Classes visibles par l'utilisateur courant. */
	public static function visible_classes(): array {
		global $wpdb;
		$t = Db::t( 'class' );
		if ( current_user_can( Capabilities::VIEW_ALL ) ) {
			return (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE teacher_id = %d ORDER BY name", get_current_user_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function back( int $id, string $type, string $text, array $details = array() ): void {
		Ui::redirect( self::PAGE, $id ? array( 'id' => $id ) : array(), $type, $text, $details );
	}

	/* ───────── Affichage ───────── */

	public function render(): void {
		$id = Ui::get_int( 'id' );
		if ( $id ) {
			$this->detail( $id );
			return;
		}
		global $wpdb;
		Ui::header( 'Classes et assignations', 'Une classe regroupe des apprenants ; vous lui assignez ensuite un ou plusieurs scénarios.' );
		echo '<div class="lw-box"><h2>Nouvelle classe</h2>';
		Ui::form_open( 'logierp_cl_create' );
		echo '<p><input type="text" name="name" required maxlength="120" placeholder="ex. Logisticiens CFC 2e année" class="regular-text"> <input type="text" name="year" maxlength="20" placeholder="2026-2027" style="width:120px"> ';
		Ui::form_close( 'Créer la classe' );
		echo '</p>';
		self::sensei_create_box();
		echo '</div>';
		$cm = Db::t( 'class_member' );
		$a  = Db::t( 'assignment' );
		echo '<table class="widefat striped lw-table-admin"><thead><tr><th>Classe</th><th>Année</th><th>Apprenants</th><th>Groupe Sensei</th><th>Assignations</th><th>Enseignant</th><th></th></tr></thead><tbody>';
		$classes = self::visible_classes();
		if ( ! $classes ) {
			echo '<tr><td colspan="7">Aucune classe pour l\'instant.</td></tr>';
		}
		foreach ( $classes as $c ) {
			SenseiGroups::sync_if_stale( $c );
			$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cm} WHERE class_id = %d", (int) $c['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$na = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$a} WHERE class_id = %d", (int) $c['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$t  = get_userdata( (int) $c['teacher_id'] );
			echo '<tr><td><strong>' . esc_html( $c['name'] ) . '</strong></td><td>' . esc_html( $c['year'] ) . '</td><td>' . (int) $n . '</td><td>' . ( (int) $c['sensei_group_id'] ? '<span class="lw-pill">' . esc_html( SenseiGroups::title( (int) $c['sensei_group_id'] ) ) . '</span>' : '—' ) . '</td><td>' . (int) $na . '</td><td>' . esc_html( $t ? $t->display_name : '—' ) . '</td><td><a class="button" href="' . esc_url( Ui::url( self::PAGE, array( 'id' => (int) $c['id'] ) ) ) . '">Gérer</a></td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private function detail( int $id ): void {
		global $wpdb;
		$c = self::owned( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Classe introuvable.', 'logierp' ) );
		}
		$aid = Ui::get_int( 'assignment' );
		if ( $aid ) {
			$this->roster_view( $c, $aid );
			return;
		}
		Ui::header( $c['name'], 'Apprenants, groupes de travail, codes d\'invitation et scénarios assignés.' );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::PAGE ) ) . '">← Toutes les classes</a> ';
		Ui::button_form( 'logierp_cl_delete', array( 'id' => $id ), 'Supprimer la classe', 'button lw-danger', 'Supprimer cette classe ? Les sessions déjà jouées sont conservées dans les résultats.' );
		echo '</p>';

		echo '<div class="lw-box"><h2>Informations</h2>';
		Ui::form_open( 'logierp_cl_update', array( 'id' => $id ) );
		echo '<table class="form-table">';
		Ui::field( 'Nom', Ui::input( 'name', $c['name'], 'text', 'maxlength="120" required' ) );
		Ui::field( 'Année scolaire', Ui::input( 'year', $c['year'], 'text', 'maxlength="20"' ) );
		echo '</table>';
		Ui::form_close( 'Enregistrer', 'button' );
		echo '</div>';

		self::sensei_box( $c );
		$c = self::owned( $id ) ?? $c; // Date de synchronisation à jour.

		// Assignations.
		$a  = Db::t( 'assignment' );
		$sc = Db::t( 'scenario' );
		$as = (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.*, s.title, s.status AS sc_status FROM {$a} a JOIN {$sc} s ON s.id = a.scenario_id WHERE a.class_id = %d ORDER BY a.id DESC", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>Scénarios assignés</h2><table class="widefat striped"><thead><tr><th>Scénario</th><th>Travail</th><th>Ouverture</th><th>Fermeture</th><th>Tentatives</th><th>Durée max.</th><th>Résultats</th><th></th></tr></thead><tbody>';
		if ( ! $as ) {
			echo '<tr><td colspan="8">Aucun scénario assigné.</td></tr>';
		}
		foreach ( $as as $r ) {
			echo '<tr><td><strong>' . esc_html( $r['title'] ) . '</strong>' . ( 'PUBLISHED' !== $r['sc_status'] ? ' <span class="lw-pill warn">non publié : invisible</span>' : '' ) . '</td><td>' . self::work_pills( $r ) . '</td><td>' . esc_html( Ui::fr_datetime( $r['opens_at'] ) ) . '</td><td>' . esc_html( Ui::fr_datetime( $r['closes_at'] ) ) . '</td><td>' . (int) $r['max_attempts'] . '</td><td>' . esc_html( (int) $r['time_limit_min'] ? $r['time_limit_min'] . ' min' : '—' ) . '</td><td>' . esc_html( (int) $r['show_results'] ? 'Visibles après publication' : 'Masqués' ) . '</td><td class="lw-actions-cell">';
			echo '<a class="button button-primary" href="' . esc_url( Ui::url( self::PAGE, array( 'id' => $id, 'assignment' => (int) $r['id'] ) ) ) . '">' . ( 'GROUP' === $r['work_mode'] ? 'Groupes et codes' : 'Apprentis et codes' ) . '</a> <a class="button" href="' . esc_url( Ui::url( 'logierp-live', array( 'assignment' => (int) $r['id'] ) ) ) . '">Suivi</a> <a class="button" href="' . esc_url( Ui::url( 'logierp-results', array( 'assignment' => (int) $r['id'] ) ) ) . '">Résultats</a> <a class="button" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\ActivityDoc::assignment_url( (int) $r['id'] ) ) . '">Document d\'activité</a> <a class="button" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\BusinessDocs::assignment_url( (int) $r['id'] ) ) . '">Documents logistiques</a> ';
			Ui::button_form( 'logierp_cl_unassign', array( 'id' => $id, 'assignment_id' => (int) $r['id'] ), 'Retirer', 'button-link-delete', 'Retirer cette assignation ? Les sessions jouées restent consultables.' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$uid  = get_current_user_id();
		$list = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$sc} WHERE deleted_at IS NULL AND status = 'PUBLISHED' AND (owner_id = %d OR shared = 1 OR %d = 1) ORDER BY title", $uid, current_user_can( Capabilities::SETTINGS ) ? 1 : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<h3>Assigner un scénario</h3>';
		if ( ! $list ) {
			echo '<p>Aucun scénario publié. Publiez un scénario dans « Scénarios » pour pouvoir l\'assigner.</p>';
		} else {
			$opts = array();
			foreach ( $list as $s ) {
				$opts[ (string) $s['id'] ] = $s['title'];
			}
			Ui::form_open( 'logierp_cl_assign', array( 'id' => $id ), 'class="lw-assign"' );
			echo '<table class="form-table">';
			Ui::field( 'Scénario', Ui::select( 'scenario_id', $opts, '' ) );
			self::assign_fields( $id );
			Ui::field( 'Ouverture', Ui::input( 'opens_at', '', 'datetime-local' ), 'Vide = ouvert immédiatement. Heure du site.' );
			Ui::field( 'Fermeture', Ui::input( 'closes_at', '', 'datetime-local' ), 'Vide = sans date de fin.' );
			Ui::field( 'Tentatives autorisées', Ui::input( 'max_attempts', '1', 'number', 'min="1" max="20"' ) );
			Ui::field( 'Durée maximale (min)', Ui::input( 'time_limit_min', '0', 'number', 'min="0" max="600"' ), '0 = pas de limite. Une session qui dépasse est remise automatiquement.' );
			Ui::field( 'Résultats', Ui::checkbox( 'show_results', true, 'L\'apprenant voit sa note et le détail après publication par l\'enseignant' ) );
			Ui::field( 'Code personnel', Ui::checkbox( 'access_code', true, 'Exiger le code personnel de mission au premier accès' ), 'Chaque apprenti·e reçoit son propre code (fiche PDF à imprimer, une page par apprenti). Un code ne fonctionne qu\'avec le compte de son titulaire.' );
			echo '</table>';
			Ui::form_close( 'Assigner' );
		}
		echo '</div>';

		// Apprenants.
		$cm      = Db::t( 'class_member' );
		$members = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, added_at, source FROM {$cm} WHERE class_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = array();
		foreach ( $members as $m ) {
			$u = get_userdata( (int) $m['user_id'] );
			if ( $u ) {
				$rows[] = array( $u, $m['added_at'], 'sensei' === $m['source'] && (int) $c['sensei_group_id'] );
			}
		}
		usort( $rows, static fn( $x, $y ) => strcasecmp( $x[0]->last_name . $x[0]->display_name, $y[0]->last_name . $y[0]->display_name ) );
		echo '<div class="lw-box"><h2>Apprenants (' . count( $rows ) . ')</h2><table class="widefat striped"><thead><tr><th>Nom</th><th>Identifiant</th><th>Courriel</th><th>Origine</th><th>Inscrit le</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Aucun apprenant inscrit.</td></tr>';
		}
		foreach ( $rows as [ $u, $added, $from_group ] ) {
			echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $u->user_login ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . ( $from_group ? '<span class="lw-pill">Groupe Sensei</span>' : 'Ajout manuel' ) . '</td><td>' . esc_html( Ui::fr_datetime( $added ) ) . '</td><td>';
			if ( $from_group ) {
				echo '<span class="description">Géré par le groupe</span>';
			} else {
				Ui::button_form( 'logierp_cl_remove_member', array( 'id' => $id, 'user_id' => (int) $u->ID ), 'Retirer', 'button-link-delete', 'Retirer cet apprenant de la classe ?' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="lw-cols"><div><h3>Ajouter un compte existant</h3>';
		Ui::form_open( 'logierp_cl_add_member', array( 'id' => $id ) );
		echo '<p><input type="text" name="who" required maxlength="190" placeholder="Identifiant ou courriel" class="regular-text"> ';
		Ui::form_close( 'Ajouter', 'button' );
		echo '</p></div>';

		if ( current_user_can( 'create_users' ) || current_user_can( Capabilities::SETTINGS ) ) {
			echo '<div><h3>Importer une liste (CSV)</h3><p class="description">Colonnes : prénom;nom;courriel (une ligne par apprenant, 200 maximum). Les comptes manquants sont créés avec le rôle « Apprenant LogiERP » et reçoivent un courriel pour définir leur mot de passe.</p>';
			Ui::form_open( 'logierp_cl_import', array( 'id' => $id ), 'enctype="multipart/form-data"' );
			echo '<p><input type="file" name="file" accept=".csv,text/csv,text/plain" required> ' . Ui::checkbox( 'notify', true, 'Envoyer le courriel d\'accès' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			Ui::form_close( 'Importer', 'button' );
			echo '</p></div>';
		}

		echo '<div><h3>Code d\'invitation</h3>';
		if ( '' !== $c['invite_code'] && ( ! $c['invite_expires'] || $c['invite_expires'] > Db::now() ) ) {
			echo '<p class="lw-invite"><code>' . esc_html( $c['invite_code'] ) . '</code></p><p class="description">' . esc_html( sprintf( 'Valable jusqu\'au %s · utilisé %d fois%s. L\'apprenant connecté le saisit dans « Rejoindre une classe » sur la page du simulateur.', Ui::fr_datetime( $c['invite_expires'] ), (int) $c['invite_uses'], (int) $c['invite_max'] ? ' sur ' . (int) $c['invite_max'] : '' ) ) . '</p>';
			Ui::button_form( 'logierp_cl_invite_off', array( 'id' => $id ), 'Désactiver le code', 'button' );
		} else {
			Ui::form_open( 'logierp_cl_invite', array( 'id' => $id ) );
			echo '<p>Valable <input type="number" name="days" value="7" min="1" max="90" style="width:70px"> jours, <input type="number" name="max" value="30" min="0" max="500" style="width:80px"> utilisations max. (0 = illimité) ';
			Ui::form_close( 'Générer un code', 'button' );
			echo '</p>';
		}
		echo '</div></div></div>';
		self::groups_box( $id, $rows );
		Ui::footer();
	}

	/* ───────── Groupes Sensei ───────── */

	/** Options de liste des groupes : favoris (★) d'abord, nombre d'élèves, classes déjà liées signalées. */
	private static function group_options( int $except_class = 0 ): array {
		$linked = array();
		foreach ( self::visible_classes() as $k ) {
			if ( (int) $k['sensei_group_id'] && (int) $k['id'] !== $except_class ) {
				$linked[ (int) $k['sensei_group_id'] ] = $k['name'];
			}
		}
		$opts = array( '' => '— Choisir un groupe —' );
		foreach ( SenseiGroups::groups() as $g ) {
			$opts[ (string) $g['id'] ] = ( $g['fav'] ? '★ ' : '' ) . $g['title'] . ' · ' . sprintf( _n( '%d élève', '%d élèves', $g['count'], 'logierp' ), $g['count'] ) . ( isset( $linked[ $g['id'] ] ) ? ' · déjà liée à « ' . $linked[ $g['id'] ] . ' »' : '' );
		}
		return $opts;
	}

	/** Liste des classes : créer une classe à partir d'un groupe Sensei. */
	private static function sensei_create_box(): void {
		if ( ! SenseiGroups::available() ) {
			return;
		}
		echo '<h3>Ou reprendre un groupe Sensei</h3>';
		$opts = self::group_options();
		if ( count( $opts ) < 2 ) {
			echo '<p class="description">Aucun groupe Sensei publié.</p>';
			return;
		}
		Ui::form_open( 'logierp_cl_sensei_create', array(), 'class="lw-sensei-create"' );
		echo '<p>' . Ui::select( 'group_id', $opts, '' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé par Ui::select().
		Ui::form_close( 'Créer la classe depuis ce groupe', 'button' );
		echo '</p><p class="description">La classe prend le nom du groupe et ses élèves ; elle suit ensuite le groupe automatiquement. ★ = vos classes favorites (les mêmes que dans LogiProgress et LogiExam).</p>';
	}

	/** Fiche de classe : liaison au groupe Sensei. */
	private static function sensei_box( array $c ): void {
		$id  = (int) $c['id'];
		$gid = (int) $c['sensei_group_id'];
		echo '<div class="lw-box lw-sensei"><h2>Groupe Sensei</h2>';
		if ( ! SenseiGroups::available() ) {
			echo '<p>Sensei Pro n\'est pas détecté sur ce site : les apprenants s\'inscrivent par compte, par liste CSV ou par code d\'invitation.' . ( $gid ? ' La classe reste liée au groupe #' . (int) $gid . ' et reprendra la synchronisation dès que Sensei Pro sera de nouveau actif.' : '' ) . '</p></div>';
			return;
		}
		if ( $gid ) {
			$r = SenseiGroups::sync_class( $c );
			if ( $r['ok'] ) {
				$c = self::owned( $id ) ?? $c;
				echo '<p>Classe liée au groupe <strong>' . esc_html( SenseiGroups::title( $gid ) ) . '</strong> · ' . esc_html( sprintf( '%d élève(s) dans le groupe', count( SenseiGroups::member_ids( $gid ) ) ) ) . ' · synchronisée le ' . esc_html( Ui::fr_datetime( $c['synced_at'] ) ) . '.</p>';
				echo '<p class="description">Les élèves ajoutés au groupe dans Sensei sont inscrits automatiquement (à l\'ouverture de cette page, à leur connexion et toutes les heures) ; ceux qui le quittent sont retirés. Les apprenants ajoutés à la main restent inscrits.' . ( $r['skipped'] ? ' ' . sprintf( '%d compte(s) du personnel du groupe ignoré(s).', (int) $r['skipped'] ) : '' ) . '</p>';
			} else {
				echo '<div class="notice notice-warning inline"><p>Le groupe lié <strong>' . esc_html( SenseiGroups::title( $gid ) ) . '</strong> est introuvable ou n\'est plus publié : la synchronisation est suspendue et aucun apprenant n\'est retiré.</p></div>';
			}
			echo '<p>';
			Ui::button_form( 'logierp_cl_sensei_sync', array( 'id' => $id ), 'Synchroniser maintenant', 'button' );
			echo ' ';
			Ui::button_form( 'logierp_cl_sensei_unlink', array( 'id' => $id ), 'Délier le groupe', 'button-link-delete', 'Délier la classe du groupe Sensei ? Les élèves restent inscrits (ajout manuel) ; la classe ne suivra plus le groupe.' );
			echo '</p></div>';
			return;
		}
		$opts = self::group_options( $id );
		if ( count( $opts ) < 2 ) {
			echo '<p>Aucun groupe Sensei publié pour l\'instant.</p></div>';
			return;
		}
		echo '<p>Liez cette classe à un groupe Sensei pour reprendre ses élèves et suivre automatiquement ses changements.</p>';
		Ui::form_open( 'logierp_cl_sensei_link', array( 'id' => $id ) );
		echo '<p>' . Ui::select( 'group_id', $opts, '' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé par Ui::select().
		Ui::form_close( 'Lier et synchroniser' );
		echo '</p><p class="description">★ = vos classes favorites (les mêmes que dans LogiProgress et LogiExam).</p></div>';
	}

	private static function sync_message( array $r ): string {
		return sprintf( '%d élève(s) inscrit(s), %d retiré(s) ; la classe compte %d apprenant(s).', $r['added'], $r['removed'], $r['members'] ) . ( $r['skipped'] ? sprintf( ' %d compte(s) du personnel ignoré(s).', $r['skipped'] ) : '' );
	}

	public function handle_sensei_create(): void {
		Ui::guard( 'logierp_cl_sensei_create', Capabilities::CLASSES );
		$g = SenseiGroups::group( Ui::post_int( 'group_id' ) );
		if ( ! $g ) {
			$this->back( 0, 'error', 'Groupe Sensei introuvable.' );
		}
		$name = mb_substr( sanitize_text_field( $g['title'] ), 0, 120 );
		$id   = Db::insert( 'class', array( 'teacher_id' => get_current_user_id(), 'name' => $name, 'year' => SenseiGroups::year_from_title( $name ), 'sensei_group_id' => $g['id'], 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
		Audit::log( 'class_create', 'class', $id, array( 'sensei_group' => $g['id'] ) );
		$r = SenseiGroups::sync_class( (array) self::owned( $id ) );
		$this->back( $id, 'success', 'Classe créée depuis le groupe « ' . $name . ' ». ' . self::sync_message( $r ) );
	}

	public function handle_sensei_link(): void {
		Ui::guard( 'logierp_cl_sensei_link', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$g = SenseiGroups::group( Ui::post_int( 'group_id' ) );
		if ( ! $g ) {
			$this->back( $id, 'error', 'Choisissez un groupe Sensei publié.' );
		}
		Db::update( 'class', array( 'sensei_group_id' => $g['id'], 'synced_at' => null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'class_sensei_link', 'class', $id, array( 'group' => $g['id'] ) );
		$r = SenseiGroups::sync_class( (array) self::owned( $id ) );
		$this->back( $id, 'success', 'Classe liée au groupe « ' . $g['title'] . ' ». ' . self::sync_message( $r ) );
	}

	public function handle_sensei_unlink(): void {
		global $wpdb;
		Ui::guard( 'logierp_cl_sensei_unlink', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		// Les élèves repris restent inscrits : ils deviennent des inscriptions manuelles.
		$wpdb->update( Db::t( 'class_member' ), array( 'source' => 'manual' ), array( 'class_id' => $id, 'source' => 'sensei' ) );
		Db::update( 'class', array( 'sensei_group_id' => 0, 'synced_at' => null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'class_sensei_unlink', 'class', $id );
		$this->back( $id, 'success', 'Groupe délié. Les élèves restent inscrits dans la classe.' );
	}

	public function handle_sensei_sync(): void {
		Ui::guard( 'logierp_cl_sensei_sync', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		$c  = self::owned_or_die( $id );
		$r  = SenseiGroups::sync_class( $c );
		if ( ! $r['ok'] ) {
			$this->back( $id, 'error', 'Synchronisation impossible : groupe Sensei introuvable, non publié ou Sensei Pro inactif.' );
		}
		$this->back( $id, 'success', 'Classe synchronisée. ' . self::sync_message( $r ) );
	}

	/* ───────── Traitements ───────── */

	public function handle_create(): void {
		Ui::guard( 'logierp_cl_create', Capabilities::CLASSES );
		$name = Ui::post_str( 'name', 120 );
		if ( '' === $name ) {
			$this->back( 0, 'error', 'Indiquez un nom de classe.' );
		}
		$id = Db::insert( 'class', array( 'teacher_id' => get_current_user_id(), 'name' => $name, 'year' => Ui::post_str( 'year', 20 ), 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
		Audit::log( 'class_create', 'class', $id );
		$this->back( $id, 'success', 'Classe créée. Inscrivez maintenant vos apprenants.' );
	}

	public function handle_update(): void {
		Ui::guard( 'logierp_cl_update', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		$c  = self::owned_or_die( $id );
		Db::update( 'class', array( 'name' => Ui::post_str( 'name', 120 ) ?: $c['name'], 'year' => Ui::post_str( 'year', 20 ), 'updated_at' => Db::now() ), array( 'id' => $id ) );
		$this->back( $id, 'success', 'Classe enregistrée.' );
	}

	public function handle_delete(): void {
		global $wpdb;
		Ui::guard( 'logierp_cl_delete', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$wpdb->delete( Db::t( 'class_member' ), array( 'class_id' => $id ) );
		$wpdb->delete( Db::t( 'class' ), array( 'id' => $id ) );
		// Les assignations restent liées aux sessions jouées : on les conserve mais elles ne sont plus visibles (classe absente).
		Audit::log( 'class_delete', 'class', $id );
		$this->back( 0, 'success', 'Classe supprimée.' );
	}

	private static function add( int $class_id, int $user_id ): bool {
		global $wpdb;
		$t = Db::t( 'class_member' );
		return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (class_id, user_id, added_at) VALUES (%d, %d, %s)", $class_id, $user_id, Db::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Inscription par code (appelée par l'API REST /join). */
	public static function join_by_code( int $user_id, string $code ): array {
		global $wpdb;
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
		if ( strlen( $code ) < 6 ) {
			return array( false, 'Code invalide.' );
		}
		$t = Db::t( 'class' );
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE invite_code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $c || ( $c['invite_expires'] && $c['invite_expires'] < Db::now() ) || ( (int) $c['invite_max'] && (int) $c['invite_uses'] >= (int) $c['invite_max'] ) ) {
			return array( false, 'Code invalide ou expiré. Demandez un nouveau code à votre enseignant.' );
		}
		$u = get_userdata( $user_id );
		if ( $u && ! user_can( $u, Capabilities::PLAY ) ) {
			$u->add_role( 'logierp_student' ); // Rôle minimal : lecture + simulateur.
		}
		if ( self::add( (int) $c['id'], $user_id ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET invite_uses = invite_uses + 1 WHERE id = %d", (int) $c['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Audit::log( 'class_join', 'class', (int) $c['id'] );
			return array( true, sprintf( 'Vous avez rejoint la classe « %s ».', $c['name'] ) );
		}
		return array( true, sprintf( 'Vous faites déjà partie de la classe « %s ».', $c['name'] ) );
	}

	public function handle_add_member(): void {
		Ui::guard( 'logierp_cl_add_member', Capabilities::CLASSES );
		$id  = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$who = Ui::post_str( 'who' );
		$u   = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $u ) {
			$this->back( $id, 'error', 'Aucun compte ne correspond à cet identifiant ou ce courriel.' );
		}
		if ( ! user_can( $u, Capabilities::PLAY ) ) {
			// Compte existant sans rôle LogiERP : on ajoute le rôle apprenant (jamais de droit supplémentaire au-delà).
			if ( user_can( $u, 'edit_posts' ) && ! current_user_can( 'promote_users' ) ) {
				$this->back( $id, 'error', 'Ce compte n\'a pas accès au simulateur et seul un administrateur peut lui ajouter le rôle apprenant.' );
			}
			$u->add_role( 'logierp_student' );
		}
		self::add( $id, (int) $u->ID );
		$this->back( $id, 'success', sprintf( '%s ajouté(e) à la classe.', $u->display_name ) );
	}

	public function handle_remove_member(): void {
		global $wpdb;
		Ui::guard( 'logierp_cl_remove_member', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		$c = self::owned_or_die( $id );
		if ( (int) $c['sensei_group_id'] && 'sensei' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM ' . Db::t( 'class_member' ) . ' WHERE class_id = %d AND user_id = %d', $id, Ui::post_int( 'user_id' ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->back( $id, 'error', 'Cet apprenant vient du groupe Sensei : retirez-le du groupe dans Sensei, la classe suivra automatiquement.' );
		}
		$wpdb->delete( Db::t( 'class_member' ), array( 'class_id' => $id, 'user_id' => Ui::post_int( 'user_id' ) ) );
		$this->back( $id, 'success', 'Apprenant retiré de la classe.' );
	}

	public function handle_import(): void {
		Ui::guard( 'logierp_cl_import', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		if ( ! current_user_can( 'create_users' ) && ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		if ( ! RateLimiter::hit( 'import' ) ) {
			$this->back( $id, 'error', 'Trop d\'imports en peu de temps : réessayez dans une heure.' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > MB_IN_BYTES ) {
			$this->back( $id, 'error', 'Fichier manquant ou trop volumineux (1 Mo maximum).' );
		}
		$raw = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire.
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$raw     = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$lines   = array_slice( preg_split( '/\r\n|\r|\n/', $raw ), 0, 201 );
		$created = 0;
		$added   = 0;
		$errors  = array();
		$notify  = Ui::post_bool( 'notify' );
		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$sep = substr_count( $line, ';' ) >= substr_count( $line, ',' ) ? ';' : ',';
			$f   = array_map( static fn( $v ) => trim( (string) $v, " \t\"" ), str_getcsv( $line, $sep, '"', '\\' ) );
			$first = sanitize_text_field( $f[0] ?? '' );
			$last  = sanitize_text_field( $f[1] ?? '' );
			$email = sanitize_email( $f[2] ?? '' );
			if ( 0 === $n && ! is_email( $email ) && preg_match( '/mail/i', $line ) ) {
				continue; // ligne d'en-tête.
			}
			if ( ! is_email( $email ) ) {
				$errors[] = sprintf( 'Ligne %d : courriel invalide.', $n + 1 );
				continue;
			}
			$u = get_user_by( 'email', $email );
			if ( ! $u ) {
				$base  = sanitize_user( strtolower( remove_accents( $first . '.' . $last ) ), true ) ?: 'apprenant';
				$base  = trim( preg_replace( '/[^a-z0-9._-]/', '', $base ), '.' ) ?: 'apprenant';
				$login = $base;
				$i     = 1;
				while ( username_exists( $login ) ) {
					$login = $base . ( ++$i );
				}
				$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ?: $login, 'role' => 'logierp_student' ) );
				if ( is_wp_error( $uid ) ) {
					$errors[] = sprintf( 'Ligne %d : %s', $n + 1, $uid->get_error_message() );
					continue;
				}
				if ( $notify ) {
					wp_new_user_notification( $uid, null, 'user' );
				}
				$u = get_userdata( $uid );
				++$created;
			} elseif ( ! user_can( $u, Capabilities::PLAY ) ) {
				if ( user_can( $u, 'edit_posts' ) && ! current_user_can( 'promote_users' ) ) {
					$errors[] = sprintf( 'Ligne %d : compte existant non modifiable.', $n + 1 );
					continue;
				}
				$u->add_role( 'logierp_student' );
			}
			if ( self::add( $id, (int) $u->ID ) ) {
				++$added;
			}
		}
		Audit::log( 'class_import', 'class', $id, array( 'created' => $created, 'added' => $added ) );
		$this->back( $id, $errors ? 'warning' : 'success', sprintf( '%d apprenant(s) ajouté(s), dont %d compte(s) créé(s).', $added, $created ), array_slice( $errors, 0, 30 ) );
	}

	public function handle_invite(): void {
		Ui::guard( 'logierp_cl_invite', Capabilities::CLASSES );
		$id   = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$alph = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$code .= $alph[ random_int( 0, strlen( $alph ) - 1 ) ];
		}
		$days = max( 1, min( 90, Ui::post_int( 'days' ) ) );
		Db::update( 'class', array( 'invite_code' => $code, 'invite_expires' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ), 'invite_uses' => 0, 'invite_max' => max( 0, min( 500, Ui::post_int( 'max' ) ) ) ), array( 'id' => $id ) );
		$this->back( $id, 'success', 'Code d\'invitation généré : ' . $code );
	}

	public function handle_invite_off(): void {
		Ui::guard( 'logierp_cl_invite_off', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		Db::update( 'class', array( 'invite_code' => '', 'invite_expires' => null ), array( 'id' => $id ) );
		$this->back( $id, 'success', 'Code désactivé.' );
	}

	/** Date locale du formulaire (datetime-local) convertie en UTC. */
	private static function to_utc( string $local ): ?string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local ) ) {
			return null;
		}
		try {
			$d = new \DateTimeImmutable( $local, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		return $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public function handle_assign(): void {
		Ui::guard( 'logierp_cl_assign', Capabilities::CLASSES );
		$id  = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$sid = Ui::post_int( 'scenario_id' );
		$sc  = \LogiERP\Domain\SessionService::scenario( $sid );
		if ( ! $sc || 'PUBLISHED' !== $sc['status'] || ! ScenariosPage::can_view( $sc ) ) {
			$this->back( $id, 'error', 'Scénario introuvable ou non publié.' );
		}
		$open  = self::to_utc( Ui::post_str( 'opens_at', 20 ) );
		$close = self::to_utc( Ui::post_str( 'closes_at', 20 ) );
		if ( $open && $close && $close <= $open ) {
			$this->back( $id, 'error', 'La fermeture doit être postérieure à l\'ouverture.' );
		}
		$mode   = 'GROUP' === Ui::post_str( 'work_mode', 10 ) ? 'GROUP' : 'INDIV';
		$groups = self::post_ids( 'groups' );
		$users  = 'some' === Ui::post_str( 'who', 10 ) ? self::post_ids( 'users' ) : array();
		if ( 'GROUP' === $mode && ! $groups ) {
			$this->back( $id, 'error', 'Cochez au moins un groupe de travail.' );
		}
		if ( 'INDIV' === $mode && 'some' === Ui::post_str( 'who', 10 ) && ! $users ) {
			$this->back( $id, 'error', 'Cochez au moins un apprenti, ou choisissez « Toute la classe ».' );
		}
		$roster = 'GROUP' === $mode || $users ? Teams::ROSTER_FIXED : Teams::ROSTER_CLASS;
		$row    = array( 'scenario_id' => $sid, 'class_id' => $id, 'max_attempts' => max( 1, min( 20, Ui::post_int( 'max_attempts' ) ) ), 'time_limit_min' => max( 0, min( 600, Ui::post_int( 'time_limit_min' ) ) ), 'show_results' => Ui::post_bool( 'show_results' ) ? 1 : 0, 'work_mode' => $mode, 'access_code' => Ui::post_bool( 'access_code' ) ? 1 : 0, 'roster' => $roster, 'created_by' => get_current_user_id(), 'created_at' => Db::now(), 'updated_at' => Db::now() );
		if ( $open ) {
			$row['opens_at'] = $open;
		}
		if ( $close ) {
			$row['closes_at'] = $close;
		}
		try {
			[ $aid, $res ] = Db::transaction(
				static function () use ( $row, $id, $mode, $groups, $users ) {
					$aid = Db::insert( 'assignment', $row );
					return array( $aid, Teams::create_roster( $aid, $id, $mode, $groups, $users ) );
				}
			);
		} catch ( DomainError $e ) {
			$this->back( $id, 'error', $e->getMessage() );
		}
		Audit::log( 'assignment_create', 'assignment', $aid, array( 'scenario' => $sid, 'class' => $id, 'mode' => $mode ) );
		if ( 'GROUP' === $mode && ! $res['teams'] ) {
			$this->back( $id, 'warning', 'Mission assignée, mais aucun groupe coché ne compte de membre : ajoutez des groupes dans « Groupes et codes ».' );
		}
		$msg = 'GROUP' === $mode
			? sprintf( 'Mission assignée à %d groupe(s) (%d apprenti(s)).', $res['teams'], $res['members'] )
			: sprintf( 'Mission assignée à %d apprenti(s).', $res['members'] );
		if ( (int) $row['access_code'] ) {
			$msg .= ' Imprimez les codes personnels depuis « ' . ( 'GROUP' === $mode ? 'Groupes et codes' : 'Apprentis et codes' ) . ' ».';
		}
		$details = 'GROUP' === $mode && $res['without_group'] ? array( sprintf( '%d apprenti(s) de la classe sans groupe ne reçoivent pas cette mission.', $res['without_group'] ) ) : array();
		$this->back( $id, $details ? 'warning' : 'success', $msg, $details );
	}

	public function handle_unassign(): void {
		global $wpdb;
		Ui::guard( 'logierp_cl_unassign', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$aid = Ui::post_int( 'assignment_id' );
		// L'assignation est détachée de la classe (class_id = 0) : les sessions et notes restent consultables.
		$wpdb->update( Db::t( 'assignment' ), array( 'class_id' => 0, 'closes_at' => Db::now() ), array( 'id' => $aid, 'class_id' => $id ) );
		Audit::log( 'assignment_remove', 'assignment', $aid );
		$this->back( $id, 'success', 'Assignation retirée.' );
	}

	/* ───────── Groupes de travail ───────── */

	/** Pastilles « Individuel / En groupe » et « code » d'une assignation. */
	private static function work_pills( array $a ): string {
		global $wpdb;
		$h = 'GROUP' === $a['work_mode']
			? '<span class="lw-pill">En groupe · ' . (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'assignment_team' ) . ' WHERE assignment_id = %d', (int) $a['id'] ) ) . ' groupe(s)</span>' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: '<span class="lw-pill">Individuel</span>';
		if ( Teams::ROSTER_FIXED === (int) $a['roster'] && 'GROUP' !== $a['work_mode'] ) {
			$h .= ' <span class="lw-pill">' . (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'assignment_member' ) . ' WHERE assignment_id = %d', (int) $a['id'] ) ) . ' apprenti(s) choisi(s)</span>'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( (int) $a['access_code'] ) {
			$h .= ' <span class="lw-pill ok">code personnel</span>';
		}
		return $h;
	}

	/** Formulaire d'assignation : mode de travail et destinataires. */
	private static function assign_fields( int $class_id ): void {
		$groups = array_filter( Teams::groups( $class_id ), static fn( $g ) => (bool) $g['members'] );
		$mode   = '<label class="lw-radio"><input type="radio" name="work_mode" value="INDIV" checked> <strong>Individuel</strong> : chaque apprenti·e travaille dans sa propre session</label>'
			. '<label class="lw-radio"><input type="radio" name="work_mode" value="GROUP"' . ( $groups ? '' : ' disabled' ) . '> <strong>En groupe</strong> : une session partagée par groupe de travail, une note par groupe</label>';
		Ui::field( 'Mode de travail', $mode, $groups ? '' : 'Pour travailler en groupe, créez d\'abord les groupes de travail de la classe (en bas de page).' );
		$users = Teams::class_members( $class_id );
		usort( $users, array( Teams::class, 'cmp_users' ) );
		$h = '<div class="lw-for-indiv"><label class="lw-radio"><input type="radio" name="who" value="all" checked> Toute la classe <span class="description">(les apprentis inscrits plus tard sont ajoutés automatiquement)</span></label>'
			. '<label class="lw-radio"><input type="radio" name="who" value="some"> Seulement les apprentis cochés :</label><div class="lw-checks">';
		foreach ( $users as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$h .= '<label><input type="checkbox" name="users[]" value="' . (int) $uid . '"> ' . esc_html( $u->display_name ) . '</label>';
			}
		}
		$h .= '</div></div><div class="lw-for-group"><p>Groupes qui reçoivent la mission :</p><div class="lw-checks">';
		foreach ( $groups as $g ) {
			$h .= '<label><input type="checkbox" name="groups[]" value="' . (int) $g['id'] . '" checked> <strong>' . esc_html( $g['name'] ) . '</strong> <span class="description">' . esc_html( implode( ', ', array_map( array( Teams::class, 'short_name' ), $g['members'] ) ) ) . '</span></label>';
		}
		$h .= '</div><p class="description">Les groupes sont figés pour cette mission au moment de l\'assignation ; vous pourrez encore déplacer un apprenti d\'un groupe à l\'autre dans « Groupes et codes ».</p></div>';
		Ui::field( 'Destinataires', $h );
	}

	/** Groupes de travail de la classe : création, répartition, répartition automatique. */
	private static function groups_box( int $id, array $rows ): void {
		$groups = Teams::groups( $id );
		$of     = array();
		foreach ( $groups as $g ) {
			foreach ( $g['members'] as $uid ) {
				$of[ $uid ] = $g['id'];
			}
		}
		echo '<div class="lw-box yellow" id="groupes"><h2>Groupes de travail (' . count( $groups ) . ')</h2>';
		echo '<p class="description">Sous-groupes de la classe pour les activités en groupe. Un apprenti appartient à un seul groupe de la classe. À l\'assignation en mode « En groupe », chaque groupe partage une même session et reçoit une note commune ; chaque membre garde son propre code personnel.</p>';
		if ( $groups ) {
			echo '<div class="lw-groups">';
			foreach ( $groups as $g ) {
				echo '<div class="lw-group"><div class="lw-group-head">';
				Ui::form_open( 'logierp_cl_group_rename', array( 'id' => $id, 'group_id' => $g['id'] ), 'class="lw-inline"' );
				echo '<input type="text" name="name" value="' . esc_attr( $g['name'] ) . '" maxlength="60" aria-label="Nom du groupe"> ';
				Ui::form_close( 'Renommer', 'button button-small' );
				Ui::button_form( 'logierp_cl_group_delete', array( 'id' => $id, 'group_id' => $g['id'] ), 'Supprimer', 'button-link-delete', 'Supprimer ce groupe ? Ses membres deviennent « sans groupe ». Les missions déjà assignées ne changent pas.' );
				echo '</div><ul>';
				foreach ( $g['members'] as $uid ) {
					$u = get_userdata( $uid );
					echo '<li>' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</li>';
				}
				echo $g['members'] ? '' : '<li class="description">Aucun membre</li>';
				echo '</ul></div>';
			}
			echo '</div>';
		}
		if ( $rows && $groups ) {
			echo '<h3>Répartir les apprentis</h3>';
			Ui::form_open( 'logierp_cl_groups_save', array( 'id' => $id ) );
			$opts = array( '0' => '— sans groupe —' );
			foreach ( $groups as $g ) {
				$opts[ (string) $g['id'] ] = $g['name'];
			}
			echo '<table class="widefat striped lw-split"><thead><tr><th>Apprenti</th><th>Groupe</th></tr></thead><tbody>';
			foreach ( $rows as [ $u ] ) {
				echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . Ui::select( 'members[' . (int) $u->ID . ']', $opts, (string) ( $of[ (int) $u->ID ] ?? 0 ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select échappe.
			}
			echo '</tbody></table><p>';
			Ui::form_close( 'Enregistrer la répartition' );
			echo '</p>';
		}
		echo '<div class="lw-cols"><div><h3>Nouveau groupe</h3>';
		Ui::form_open( 'logierp_cl_group_create', array( 'id' => $id ) );
		echo '<p><input type="text" name="name" maxlength="60" placeholder="Groupe ' . ( count( $groups ) + 1 ) . '"> ';
		Ui::form_close( 'Créer le groupe', 'button' );
		echo '</p></div><div><h3>Répartition automatique</h3>';
		Ui::form_open( 'logierp_cl_groups_split', array( 'id' => $id ) );
		echo '<p>Groupes de <input type="number" name="size" value="3" min="2" max="8" style="width:64px"> apprentis, tirés au hasard ';
		Ui::form_close( 'Répartir', 'button', $groups ? 'Remplacer les groupes actuels par une nouvelle répartition au hasard ? Les missions déjà assignées ne changent pas.' : '' );
		echo '</p><p class="description">Les effectifs sont équilibrés (13 apprentis par 3 : 4 groupes de 4, 3, 3 et 3).</p></div></div></div>';
	}

	/* ───────── Liste d'une assignation : groupes et codes ───────── */

	private static function assignment_of( int $class_id, int $aid ): array {
		global $wpdb;
		$a  = Db::t( 'assignment' );
		$sc = Db::t( 'scenario' );
		$r  = $wpdb->get_row( $wpdb->prepare( "SELECT a.*, s.title FROM {$a} a JOIN {$sc} s ON s.id = a.scenario_id WHERE a.id = %d AND a.class_id = %d", $aid, $class_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $r ) {
			wp_die( esc_html__( 'Assignation introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		return $r;
	}

	private function roster_view( array $c, int $aid ): void {
		$id = (int) $c['id'];
		$a  = self::assignment_of( $id, $aid );
		Teams::sync( $a );
		$group = 'GROUP' === $a['work_mode'];
		Ui::header( $a['title'], $c['name'] . ' · ' . ( $group ? 'travail en groupe' : 'travail individuel' ) . ( (int) $a['access_code'] ? ' · code personnel exigé au premier accès' : ' · sans code' ) );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::PAGE, array( 'id' => $id ) ) ) . '">← ' . esc_html( $c['name'] ) . '</a> ';
		if ( Teams::ROSTER_NONE !== (int) $a['roster'] ) {
			Ui::form_open( 'logierp_cl_codes_print', array( 'id' => $id, 'assignment_id' => $aid ), 'target="_blank" style="display:inline"' );
			Ui::form_close( 'Codes en PDF (une page par apprenti)', 'button button-primary' );
		}
		echo ' <a class="button" href="' . esc_url( Ui::url( 'logierp-live', array( 'assignment' => $aid ) ) ) . '">Suivi en direct</a></p>';

		if ( Teams::ROSTER_NONE === (int) $a['roster'] ) {
			echo '<div class="lw-box"><h2>Codes personnels</h2><p>Cette mission a été assignée avant l\'arrivée des codes : tous les apprentis de la classe la voient et la commencent sans code.</p>';
			Ui::button_form( 'logierp_cl_roster_enable', array( 'id' => $id, 'assignment_id' => $aid ), 'Générer un code personnel pour chaque apprenti', 'button button-primary', 'Générer les codes ? Les apprentis qui n\'ont pas encore commencé devront saisir leur code.' );
			echo '</div>';
			Ui::footer();
			return;
		}
		$roster  = Teams::roster( $aid );
		$teams   = $group ? Teams::teams( $aid ) : array();
		$in      = array();
		foreach ( $roster as $m ) {
			$in[ (int) $m['user_id'] ] = $m;
		}
		$topts = array();
		foreach ( $teams as $t ) {
			$topts[ (string) $t['id'] ] = $t['name'];
		}
		echo '<div class="lw-box"><h2>' . ( $group ? 'Groupes et codes' : 'Apprentis et codes' ) . ' (' . count( $roster ) . ' apprenti(s))</h2>';
		echo '<p class="description">Chaque code ne fonctionne qu\'avec le compte de son titulaire, connecté au site. « Fiche PDF » réimprime le code d\'un seul apprenti ; un code perdu ? « Nouveau code » le remplace : l\'ancien ne fonctionne plus.' . ( $group ? ' Tous les membres d\'un groupe travaillent dans la même session.' : '' ) . '</p>';
		$blocks = $group ? $teams : array( array( 'id' => 0, 'name' => '', 'members' => $roster ) );
		foreach ( $blocks as $t ) {
			if ( $group ) {
				echo '<h3>' . esc_html( $t['name'] ) . ' <span class="description">(' . count( $t['members'] ) . ' membre(s))</span></h3>';
			}
			echo '<table class="widefat striped lw-codes"><thead><tr><th>Apprenti</th><th>Code</th><th>Premier accès</th><th>Actions</th></tr></thead><tbody>';
			if ( ! $t['members'] ) {
				echo '<tr><td colspan="4" class="description">Aucun membre : déplacez un apprenti dans ce groupe.</td></tr>';
			}
			foreach ( $t['members'] as $m ) {
				$u = get_userdata( (int) $m['user_id'] );
				echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $m['user_id'] ) . '</td><td><code class="lw-code">' . esc_html( Teams::format( (string) $m['code'] ) ) . '</code></td><td>' . esc_html( $m['code_used_at'] ? Ui::fr_datetime( $m['code_used_at'] ) : ( (int) $a['access_code'] ? 'pas encore' : '—' ) ) . '</td><td class="lw-actions-cell">';
				Ui::form_open( 'logierp_cl_codes_print', array( 'id' => $id, 'assignment_id' => $aid, 'user_id' => (int) $m['user_id'] ), 'target="_blank" style="display:inline"' );
				Ui::form_close( 'Fiche PDF', 'button button-small' );
				echo ' ';
				Ui::button_form( 'logierp_cl_roster_code', array( 'id' => $id, 'assignment_id' => $aid, 'user_id' => (int) $m['user_id'] ), 'Nouveau code', 'button button-small', 'Remplacer le code de cet apprenti ? L\'ancien ne fonctionnera plus.' );
				if ( $group && count( $topts ) > 1 ) {
					echo ' ';
					Ui::form_open( 'logierp_cl_roster_move', array( 'id' => $id, 'assignment_id' => $aid, 'user_id' => (int) $m['user_id'] ), 'class="lw-inline"' );
					echo Ui::select( 'team_id', $topts, (string) $t['id'] ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select échappe.
					Ui::form_close( 'Déplacer', 'button button-small', 'Changer cet apprenti de groupe ? Il rejoindra la session de son nouveau groupe et n\'aura plus accès à celle de l\'ancien.' );
				}
				echo ' ';
				Ui::button_form( 'logierp_cl_roster_remove', array( 'id' => $id, 'assignment_id' => $aid, 'user_id' => (int) $m['user_id'] ), 'Retirer', 'button-link-delete', 'Retirer cet apprenti de la mission ? Il ne la verra plus. Le travail déjà fait reste dans les résultats.' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $group ) {
			echo '<h3>Ajouter un groupe à cette mission</h3>';
			Ui::form_open( 'logierp_cl_roster_team', array( 'id' => $id, 'assignment_id' => $aid ) );
			echo '<p><input type="text" name="name" maxlength="60" placeholder="Groupe ' . ( count( $teams ) + 1 ) . '"> ';
			Ui::form_close( 'Ajouter le groupe', 'button' );
			echo '</p>';
		}
		// Apprentis de la classe hors de la liste.
		$out = array_diff( Teams::class_members( $id ), array_keys( $in ) );
		usort( $out, array( Teams::class, 'cmp_users' ) );
		if ( $out ) {
			echo '<h3>Apprentis de la classe sans cette mission (' . count( $out ) . ')</h3><table class="widefat striped"><tbody>';
			foreach ( $out as $uid ) {
				$u = get_userdata( $uid );
				echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</td><td>';
				Ui::form_open( 'logierp_cl_roster_add', array( 'id' => $id, 'assignment_id' => $aid, 'user_id' => $uid ), 'class="lw-inline"' );
				if ( $group ) {
					if ( ! $topts ) {
						echo '<span class="description">Ajoutez d\'abord un groupe.</span></form>';
						echo '</td></tr>';
						continue;
					}
					echo Ui::select( 'team_id', $topts, '' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select échappe.
				}
				Ui::form_close( 'Ajouter à la mission', 'button button-small' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		// Codes utilisés par un autre compte.
		$mis = Teams::misuse( $aid );
		echo '<div class="lw-box"><h2>Codes refusés</h2>';
		if ( ! $mis ) {
			echo '<p class="description">Aucune tentative d\'utiliser le code d\'un autre apprenti.</p>';
		} else {
			echo '<p class="description">Ces comptes ont saisi le code personnel d\'un autre apprenti : l\'accès a été refusé.</p><table class="widefat striped"><thead><tr><th>Date</th><th>Compte qui a saisi le code</th><th>Titulaire du code</th></tr></thead><tbody>';
			foreach ( $mis as $r ) {
				$by = get_userdata( (int) $r['user_id'] );
				$d  = json_decode( (string) $r['details'], true );
				$ow = get_userdata( (int) ( $d['owner'] ?? 0 ) );
				echo '<tr><td>' . esc_html( Ui::fr_datetime( $r['created_at'] ) ) . '</td><td>' . esc_html( $by ? $by->display_name : '—' ) . '</td><td>' . esc_html( $ow ? $ow->display_name : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		Ui::footer();
	}

	/** Identifiants envoyés sous forme de tableau (cases à cocher). */
	private static function post_ids( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$v = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		return array_values( array_unique( array_filter( array_map( 'absint', $v ) ) ) );
	}

	private function domain( int $id, callable $fn, string $ok ): void {
		try {
			$fn();
		} catch ( DomainError $e ) {
			$this->back( $id, 'error', $e->getMessage() );
		}
		$this->back( $id, 'success', $ok );
	}

	public function handle_group_create(): void {
		Ui::guard( 'logierp_cl_group_create', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$this->domain( $id, static fn() => Teams::create_group( $id, Ui::post_str( 'name', 60 ) ), 'Groupe créé. Répartissez maintenant les apprentis.' );
	}

	public function handle_group_rename(): void {
		Ui::guard( 'logierp_cl_group_rename', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$this->domain( $id, static fn() => Teams::rename_group( $id, Ui::post_int( 'group_id' ), Ui::post_str( 'name', 60 ) ), 'Groupe renommé.' );
	}

	public function handle_group_delete(): void {
		Ui::guard( 'logierp_cl_group_delete', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$this->domain( $id, static fn() => Teams::delete_group( $id, Ui::post_int( 'group_id' ) ), 'Groupe supprimé.' );
	}

	public function handle_groups_save(): void {
		Ui::guard( 'logierp_cl_groups_save', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$raw = isset( $_POST['members'] ) && is_array( $_POST['members'] ) ? wp_unslash( $_POST['members'] ) : array();
		$this->domain(
			$id,
			static function () use ( $id, $raw ) {
				Db::transaction(
					static function () use ( $id, $raw ) {
						foreach ( $raw as $uid => $gid ) {
							Teams::set_member( $id, absint( $uid ), absint( $gid ) );
						}
					}
				);
				Audit::log( 'class_groups', 'class', $id );
			},
			'Répartition enregistrée.'
		);
	}

	public function handle_groups_split(): void {
		Ui::guard( 'logierp_cl_groups_split', Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$this->domain(
			$id,
			static function () use ( $id ) {
				$n = Teams::auto_split( $id, Ui::post_int( 'size' ) );
				Audit::log( 'class_groups_split', 'class', $id, array( 'groups' => $n ) );
			},
			'Apprentis répartis au hasard.'
		);
	}

	/** Contrôles communs des actions sur la liste d'une assignation. */
	private static function roster_guard( string $action ): array {
		Ui::guard( $action, Capabilities::CLASSES );
		$id = Ui::post_int( 'id' );
		self::owned_or_die( $id );
		$a = self::assignment_of( $id, Ui::post_int( 'assignment_id' ) );
		return array( $id, $a );
	}

	private function back_roster( int $id, int $aid, string $type, string $text ): void {
		Ui::redirect( self::PAGE, array( 'id' => $id, 'assignment' => $aid ), $type, $text );
	}

	private function roster_do( string $action, callable $fn, string $ok ): void {
		[ $id, $a ] = self::roster_guard( $action );
		try {
			$fn( $a );
			Audit::log( str_replace( 'logierp_cl_', '', $action ), 'assignment', (int) $a['id'], array( 'user' => Ui::post_int( 'user_id' ) ) );
		} catch ( DomainError $e ) {
			$this->back_roster( $id, (int) $a['id'], 'error', $e->getMessage() );
		}
		$this->back_roster( $id, (int) $a['id'], 'success', $ok );
	}

	public function handle_roster_code(): void {
		$this->roster_do( 'logierp_cl_roster_code', static fn( $a ) => Teams::regenerate_code( (int) $a['id'], Ui::post_int( 'user_id' ) ), 'Nouveau code généré : imprimez-le pour cet apprenti.' );
	}

	public function handle_roster_move(): void {
		$this->roster_do( 'logierp_cl_roster_move', static fn( $a ) => Teams::move_member( (int) $a['id'], Ui::post_int( 'user_id' ), Ui::post_int( 'team_id' ) ), 'Apprenti déplacé.' );
	}

	public function handle_roster_add(): void {
		$this->roster_do(
			'logierp_cl_roster_add',
			static function ( $a ) {
				$uid = Ui::post_int( 'user_id' );
				if ( ! in_array( $uid, Teams::class_members( (int) $a['class_id'] ), true ) ) {
					throw new DomainError( 'Cet apprenti n\'est pas inscrit dans la classe.' );
				}
				if ( 'GROUP' === $a['work_mode'] ) {
					Teams::move_member( (int) $a['id'], $uid, Ui::post_int( 'team_id' ) );
				} else {
					Teams::add_member( (int) $a['id'], $uid, 0 );
				}
			},
			'Apprenti ajouté à la mission.'
		);
	}

	public function handle_roster_remove(): void {
		$this->roster_do( 'logierp_cl_roster_remove', static fn( $a ) => Teams::remove_member( (int) $a['id'], Ui::post_int( 'user_id' ) ), 'Apprenti retiré de la mission.' );
	}

	public function handle_roster_team(): void {
		$this->roster_do(
			'logierp_cl_roster_team',
			static function ( $a ) {
				if ( 'GROUP' !== $a['work_mode'] ) {
					throw new DomainError( 'Cette mission est individuelle.' );
				}
				Teams::add_team( (int) $a['id'], Ui::post_str( 'name', 60 ) );
			},
			'Groupe ajouté : déplacez-y des apprentis.'
		);
	}

	/** Assignation antérieure aux codes : liste de toute la classe et codes personnels. */
	public function handle_roster_enable(): void {
		$this->roster_do(
			'logierp_cl_roster_enable',
			static function ( $a ) {
				if ( Teams::ROSTER_NONE !== (int) $a['roster'] ) {
					return;
				}
				Db::transaction(
					static function () use ( $a ) {
						Db::update( 'assignment', array( 'roster' => Teams::ROSTER_CLASS, 'access_code' => 1, 'updated_at' => Db::now() ), array( 'id' => (int) $a['id'] ) );
						Teams::create_roster( (int) $a['id'], (int) $a['class_id'], 'INDIV', array(), array() );
						// Les apprentis déjà en mission ne sont pas bloqués : leur code est considéré comme utilisé.
						global $wpdb;
						$am = Db::t( 'assignment_member' );
						$st = Db::t( 'session' );
						$wpdb->query( $wpdb->prepare( "UPDATE {$am} m SET m.code_used_at = %s WHERE m.assignment_id = %d AND EXISTS (SELECT 1 FROM {$st} s WHERE s.assignment_id = m.assignment_id AND s.user_id = m.user_id)", Db::now(), (int) $a['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
				);
			},
			'Codes générés pour toute la classe.'
		);
	}

	/** Fiches « Code personnel de mission » en PDF : une page par apprenti (triées par groupe), ou la fiche d'un seul apprenti. */
	public function handle_codes_print(): void {
		[ $id, $a ] = self::roster_guard( 'logierp_cl_codes_print' );
		$c    = self::owned_or_die( $id );
		$only = Ui::post_int( 'user_id' );
		if ( $only && ! Teams::member( (int) $a['id'], $only ) ) {
			wp_die( esc_html__( 'Apprenti introuvable dans cette mission.', 'logierp' ), '', array( 'response' => 404 ) );
		}
		$pdf  = CodeSheets::pdf( $c, $a, $only ?: null );
		Audit::log( 'codes_print', 'assignment', (int) $a['id'], $only ? array( 'user' => $only ) : array() );
		$slug = sanitize_title( remove_accents( (string) $a['title'] ) ) ?: 'mission';
		if ( $only ) {
			$u     = get_userdata( $only );
			$slug .= '-' . sanitize_title( remove_accents( $u ? $u->display_name : (string) $only ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="codes-' . substr( $slug, 0, 80 ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document PDF binaire généré par le plugin.
		exit;
	}

}
