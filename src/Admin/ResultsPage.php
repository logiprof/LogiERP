<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\SessionService;
use LogiERP\Scenario\Grader;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Résultats (correction, ajustement, publication, export) et suivi en direct.
 */
final class ResultsPage {

	private const PAGE = 'logierp-results';

	public const STATUS = array(
		'EN_COURS' => 'En cours',
		'SOUMISE'  => 'Remise',
		'CORRIGEE' => 'Corrigée',
	);

	public function register(): void {
		foreach ( array( 'adjust', 'publish', 'publish_all', 'regrade', 'force_submit', 'reset', 'export' ) as $a ) {
			add_action( 'admin_post_logierp_rs_' . $a, array( $this, 'handle_' . $a ) );
		}
		add_action( 'admin_post_logierp_rs_photo', array( $this, 'handle_photo' ) );
		add_action( 'admin_post_logierp_live_intervene', array( $this, 'handle_intervene' ) );
	}

	/** Clause SQL limitant les sessions visibles (alias x = session, a = assignment, c = class). */
	private static function scope_sql(): string {
		global $wpdb;
		if ( current_user_can( Capabilities::VIEW_ALL ) ) {
			return '1=1';
		}
		$uid = get_current_user_id();
		return $wpdb->prepare( '(c.teacher_id = %d OR a.created_by = %d OR (x.assignment_id = 0 AND x.user_id = %d))', $uid, $uid, $uid );
	}

	/** Session si l'utilisateur courant peut la consulter. */
	private static function session_or_die( int $sid ): array {
		$s = SessionService::get( $sid );
		if ( ! $s || ( (int) $s['user_id'] !== get_current_user_id() && ! SessionService::teacher_can_view( get_current_user_id(), $s ) ) ) {
			wp_die( esc_html__( 'Session introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		return $s;
	}

	private static function ctx( array $s ): Ctx {
		$sc = SessionService::scenario( (int) $s['scenario_id'] );
		if ( ! $sc ) {
			wp_die( esc_html__( 'Le scénario de cette session a été supprimé.', 'logierp' ) );
		}
		return new Ctx( $s, $sc );
	}

	/** Assignations visibles (pour les filtres). */
	public static function visible_assignments(): array {
		global $wpdb;
		$a     = Db::t( 'assignment' );
		$c     = Db::t( 'class' );
		$sc    = Db::t( 'scenario' );
		$where = current_user_can( Capabilities::VIEW_ALL ) ? '1=1' : $wpdb->prepare( '(c.teacher_id = %d OR a.created_by = %d)', get_current_user_id(), get_current_user_id() );
		return (array) $wpdb->get_results( "SELECT a.id, a.class_id, a.opens_at, a.closes_at, s.title, COALESCE(c.name, '(classe retirée)') AS class_name FROM {$a} a JOIN {$sc} s ON s.id = a.scenario_id LEFT JOIN {$c} c ON c.id = a.class_id WHERE {$where} ORDER BY a.id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function rows( int $assignment, bool $tests, string $status = '' ): array {
		global $wpdb;
		$x     = Db::t( 'session' );
		$a     = Db::t( 'assignment' );
		$c     = Db::t( 'class' );
		$sc    = Db::t( 'scenario' );
		$where = self::scope_sql();
		if ( $assignment ) {
			$where .= $wpdb->prepare( ' AND x.assignment_id = %d', $assignment );
		}
		if ( ! $tests ) {
			$where .= ' AND x.is_test = 0';
		}
		if ( isset( self::STATUS[ $status ] ) ) {
			$where .= $wpdb->prepare( ' AND x.status = %s', $status );
		}
		return (array) $wpdb->get_results( "SELECT x.*, s.title, c.name AS class_name FROM {$x} x JOIN {$sc} s ON s.id = x.scenario_id LEFT JOIN {$a} a ON a.id = x.assignment_id LEFT JOIN {$c} c ON c.id = a.class_id WHERE {$where} ORDER BY x.id DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ───────── Liste des résultats ───────── */

	public function render(): void {
		$sid = Ui::get_int( 'session' );
		if ( $sid ) {
			$this->detail( $sid );
			return;
		}
		$assignment = Ui::get_int( 'assignment' );
		$tests      = '1' === Ui::get_str( 'tests' );
		$status     = Ui::get_str( 'status' );
		Ui::header( 'Résultats', 'Les sessions remises sont corrigées automatiquement. Vérifiez, ajustez si besoin, puis publiez la note.' );

		Ui::get_form_open( self::PAGE );
		echo '<select name="assignment"><option value="0">Toutes les assignations</option>';
		foreach ( self::visible_assignments() as $as ) {
			echo '<option value="' . (int) $as['id'] . '"' . selected( $assignment, (int) $as['id'], false ) . '>' . esc_html( $as['class_name'] . ' – ' . $as['title'] ) . '</option>';
		}
		echo '</select> <select name="status"><option value="">Tous les statuts</option>';
		foreach ( self::STATUS as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <label><input type="checkbox" name="tests" value="1"' . checked( $tests, true, false ) . '> Inclure les sessions de test</label> <button class="button">Filtrer</button></form>';

		$rows = self::rows( $assignment, $tests, $status );
		echo '<p class="lw-toolbar-admin">';
		if ( $assignment && current_user_can( Capabilities::GRADE ) ) {
			Ui::button_form( 'logierp_rs_publish_all', array( 'assignment' => $assignment ), 'Publier toutes les notes corrigées', 'button button-primary', 'Publier toutes les notes corrigées de cette assignation ?' );
			echo ' ';
		}
		Ui::button_form( 'logierp_rs_export', array( 'assignment' => $assignment, 'tests' => $tests ? 1 : 0 ), 'Exporter (CSV)', 'button' );
		if ( $assignment ) {
			echo ' <a class="button lw-kpi-class-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\MissionDashboard::assignment_url( $assignment ) ) . '">Indicateurs de la classe</a>';
		}
		echo '</p>';

		echo '<table class="widefat striped lw-table-admin"><thead><tr><th>Apprenant ou groupe</th><th>Classe</th><th>Scénario</th><th>Statut</th><th>Début</th><th>Remise</th><th>Points</th><th>Note</th><th>Publiée</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="10">Aucune session.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( \LogiERP\Domain\Teams::label( $r ) ) . ( (int) $r['team_id'] ? ' <span class="lw-pill">groupe</span>' : '' ) . ( (int) $r['is_test'] ? ' <span class="lw-pill">test</span>' : '' ) . '</td><td>' . esc_html( (string) ( $r['class_name'] ?? '—' ) ) . '</td><td>' . esc_html( $r['title'] ) . '</td><td>' . esc_html( self::STATUS[ $r['status'] ] ?? $r['status'] ) . ( (int) $r['paused'] ? ' (pause)' : '' ) . '</td><td>' . esc_html( Ui::fr_datetime( $r['started_at'] ) ) . '</td><td>' . esc_html( Ui::fr_datetime( $r['submitted_at'] ) ) . '</td><td>' . esc_html( null !== $r['score'] ? \LogiERP\Domain\Catalog::format_qty( (float) $r['score'] ) . ' / ' . \LogiERP\Domain\Catalog::format_qty( (float) $r['max_score'] ) : '—' ) . '</td><td>' . Ui::grade_badge( $r['grade'] ) . '</td><td>' . esc_html( (int) $r['published'] ? 'Oui' : 'Non' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- grade_badge échappe.
			echo '<td><a class="button" href="' . esc_url( Ui::url( self::PAGE, array( 'session' => (int) $r['id'] ) ) ) . '">Détail</a></td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private function detail( int $sid ): void {
		global $wpdb;
		$s  = self::session_or_die( $sid );
		$sc = SessionService::scenario( (int) $s['scenario_id'] );
		Ui::header( \LogiERP\Domain\Teams::label( $s ) . ' – ' . ( $sc ? $sc['title'] : 'Scénario supprimé' ), 'Statut : ' . ( self::STATUS[ $s['status'] ] ?? $s['status'] ) . ' · débutée le ' . Ui::fr_datetime( $s['started_at'] ) . ( $s['submitted_at'] ? ' · remise le ' . Ui::fr_datetime( $s['submitted_at'] ) : '' ) );
		$grade = current_user_can( Capabilities::GRADE );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::PAGE, (int) $s['assignment_id'] ? array( 'assignment' => (int) $s['assignment_id'] ) : array() ) ) . '">← Résultats</a> ';
		echo Ui::app_link( $sid, 'Ouvrir la session (lecture)' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- app_link échappe.
		echo '<a class="button lw-kpi-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\MissionDashboard::session_url( $sid ) ) . '">Tableau d\'indicateurs</a> ';
		if ( $grade ) {
			if ( 'EN_COURS' === $s['status'] ) {
				Ui::button_form( 'logierp_rs_force_submit', array( 'session' => $sid ), 'Remettre et corriger maintenant', 'button', 'Remettre cette session à la place de l\'apprenant ? Il ne pourra plus la modifier.' );
			} else {
				Ui::button_form( 'logierp_rs_regrade', array( 'session' => $sid ), 'Relancer la correction automatique', 'button', 'Recalculer la correction ? Vos ajustements manuels seront perdus.' );
				echo ' ';
				Ui::button_form( 'logierp_rs_publish', array( 'session' => $sid, 'published' => (int) $s['published'] ? 0 : 1 ), (int) $s['published'] ? 'Retirer la publication' : 'Publier la note', (int) $s['published'] ? 'button' : 'button button-primary' );
			}
		}
		if ( current_user_can( Capabilities::RESET ) || ( $grade && ( (int) $s['is_test'] || SessionService::teacher_can_view( get_current_user_id(), $s ) ) ) ) {
			echo ' ';
			Ui::button_form( 'logierp_rs_reset', array( 'session' => $sid ), 'Supprimer la session', 'button lw-danger', 'Supprimer définitivement cette session ? L\'apprenant pourra recommencer (selon le nombre de tentatives).' );
		}
		echo '</p>';
		if ( (int) $s['team_id'] ) {
			// Travail de groupe : la part de chacun (saisies réussies et refusées) pour une évaluation juste.
			$al   = Db::t( 'action_log' );
			$part = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(result = 'ok') ok, SUM(result = 'error') err, MAX(created_at) last FROM {$al} WHERE session_id = %d GROUP BY user_id", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$by   = array();
			foreach ( $part as $p ) {
				$by[ (int) $p['user_id'] ] = $p;
			}
			echo '<div class="lw-box"><h2>Travail de groupe : contribution de chaque membre</h2><table class="widefat striped"><thead><tr><th>Membre</th><th>Saisies réussies</th><th>Saisies refusées</th><th>Dernière action</th></tr></thead><tbody>';
			foreach ( \LogiERP\Domain\Teams::team_member_ids( (int) $s['team_id'] ) as $uid ) {
				$u = get_userdata( $uid );
				$p = $by[ $uid ] ?? array( 'ok' => 0, 'err' => 0, 'last' => null );
				echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</td><td>' . (int) $p['ok'] . '</td><td>' . (int) $p['err'] . '</td><td>' . esc_html( $p['last'] ? Ui::fr_datetime( $p['last'] ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table><p class="description">La note de la session est celle de tout le groupe. Le journal de la session indique qui a fait chaque saisie.</p></div>';
		}

		$report = json_decode( (string) $s['report'], true );
		echo '<div class="lw-box"><h2>Correction ' . ( null !== $s['grade'] ? '– note ' . Ui::grade_badge( $s['grade'] ) : '' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! is_array( $report ) || ! $report ) {
			echo '<p>La correction sera disponible après la remise de la session.</p>';
		} else {
			if ( $grade ) {
				Ui::form_open( 'logierp_rs_adjust', array( 'session' => $sid ) );
			}
			echo '<table class="widefat striped"><thead><tr><th>Critère</th><th>Résultat</th><th>Détail</th><th>Points auto</th><th>Points retenus</th><th>Commentaire</th></tr></thead><tbody>';
			foreach ( $report as $i => $r ) {
				echo '<tr><td>' . esc_html( (string) $r['label'] ) . '</td><td>' . ( $r['ok'] ? '<span class="lw-ok">✔ Réussi</span>' : '<span class="lw-ko">✘ Non atteint</span>' ) . '</td><td>' . esc_html( (string) $r['detail'] ) . '</td><td>' . esc_html( \LogiERP\Domain\Catalog::format_qty( (float) $r['earned'] ) . ' / ' . \LogiERP\Domain\Catalog::format_qty( (float) $r['points'] ) ) . '</td>';
				echo '<td><input type="number" step="0.25" min="0" max="' . esc_attr( (string) $r['points'] ) . '" name="adj[' . (int) $i . ']" value="' . esc_attr( null !== $r['adjusted'] ? (string) $r['adjusted'] : '' ) . '" placeholder="' . esc_attr( (string) $r['earned'] ) . '" style="width:80px"' . disabled( ! $grade, true, false ) . '></td>';
				echo '<td><input type="text" maxlength="255" name="cmt[' . (int) $i . ']" value="' . esc_attr( (string) $r['comment'] ) . '" class="widefat"' . disabled( ! $grade, true, false ) . '></td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><label for="lw-tc"><strong>Commentaire général pour l\'apprenant</strong></label><br><textarea id="lw-tc" name="teacher_comment" rows="3" class="large-text"' . disabled( ! $grade, true, false ) . '>' . esc_textarea( (string) $s['teacher_comment'] ) . '</textarea></p>';
			if ( $grade ) {
				Ui::form_close( 'Enregistrer les ajustements' );
			}
		}
		echo '</div>';

		// Photos de non-conformité prises au terminal mobile.
		$photos = \LogiERP\Domain\Photos::list_for( $sid );
		if ( $photos ) {
			echo '<div class="lw-box"><h2>Photos prises au terminal mobile (' . count( $photos ) . ')</h2><p class="description">Photos jointes par l\'apprenant (non-conformités à la réception). Cliquez pour agrandir.</p><div class="lw-photos">';
			foreach ( $photos as $ph ) {
				$src = wp_nonce_url( admin_url( 'admin-post.php?action=logierp_rs_photo&session=' . $sid . '&photo=' . (int) $ph['id'] ), 'logierp_photo_' . $sid );
				$cap = trim( ( $ph['ref_no'] ? $ph['ref_no'] . ( $ph['line_no'] ? ' · ligne ' . $ph['line_no'] : '' ) : 'Photo' ) . ( '' !== $ph['note'] ? ' – ' . $ph['note'] : '' ) );
				echo '<figure><a href="' . esc_url( $src ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $cap ) . '" loading="lazy" width="' . (int) $ph['width'] . '" height="' . (int) $ph['height'] . '"></a><figcaption>' . esc_html( $cap ) . '<br><span class="description">' . esc_html( Ui::fr_datetime( $ph['at'] ) ) . '</span></figcaption></figure>';
			}
			echo '</div></div>';
		}

		// Journal des actions.
		$t    = Db::t( 'action_log' );
		$logs = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, transaction_code, action, result, message, doc_no, created_at FROM {$t} WHERE session_id = %d ORDER BY id DESC LIMIT 400", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok   = count( array_filter( $logs, static fn( $l ) => 'ok' === $l['result'] ) );
		echo '<div class="lw-box"><h2>Journal des actions (' . (int) $ok . ' réussies, ' . (int) ( count( $logs ) - $ok ) . ' refusées)</h2><p class="description">Toutes les tentatives ' . ( (int) $s['team_id'] ? 'des membres du groupe' : 'de l\'apprenant' ) . ', y compris les saisies refusées par le système : utile pour comprendre ses erreurs.</p><table class="widefat striped"><thead><tr><th>Heure</th>' . ( (int) $s['team_id'] ? '<th>Membre</th>' : '' ) . '<th>Transaction</th><th>Action</th><th>Document</th><th>Résultat</th></tr></thead><tbody>';
		foreach ( $logs as $l ) {
			$who = '';
			if ( (int) $s['team_id'] ) {
				$lu  = (int) $l['user_id'] ? get_userdata( (int) $l['user_id'] ) : null;
				$who = '<td>' . esc_html( $lu ? \LogiERP\Domain\Teams::short_name( (int) $l['user_id'] ) : ( (int) $l['user_id'] ? '—' : 'anonymisé' ) ) . '</td>';
			}
			echo '<tr><td>' . esc_html( Ui::fr_datetime( $l['created_at'] ) ) . '</td>' . $who . '<td><code>' . esc_html( $l['transaction_code'] ) . '</code></td><td>' . esc_html( $l['action'] ) . '</td><td>' . esc_html( $l['doc_no'] ) . '</td><td>' . ( 'ok' === $l['result'] ? '<span class="lw-ok">✔</span> ' : ( 'info' === $l['result'] ? '<span class="lw-info" title="Information">ℹ</span> ' : '<span class="lw-ko">✘</span> ' ) ) . esc_html( $l['message'] ) . '</td></tr>';
		}
		if ( ! $logs ) {
			echo '<tr><td colspan="6">Aucune action enregistrée.</td></tr>';
		}
		echo '</tbody></table></div>';
		Ui::footer();
	}

	/* ───────── Traitements ───────── */

	/** Image d'une photo de session (enseignant·e de la classe ou propriétaire). */
	public function handle_photo(): void {
		$sid = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- vérifié ci-dessous.
		$pid = isset( $_GET['photo'] ) ? absint( $_GET['photo'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'logierp_photo_' . $sid ) ) {
			wp_die( esc_html__( 'Lien expiré.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		self::session_or_die( $sid );
		$row = \LogiERP\Domain\Photos::row( $sid, $pid );
		if ( ! $row ) {
			wp_die( esc_html__( 'Photo introuvable.', 'logierp' ), '', array( 'response' => 404 ) );
		}
		\LogiERP\Domain\Photos::send( $row );
	}

	private function back_session( int $sid, string $type, string $text ): void {
		Ui::redirect( self::PAGE, array( 'session' => $sid ), $type, $text );
	}

	public function handle_adjust(): void {
		Ui::guard( 'logierp_rs_adjust', Capabilities::GRADE );
		$sid    = Ui::post_int( 'session' );
		$s      = self::session_or_die( $sid );
		$report = json_decode( (string) $s['report'], true );
		if ( ! is_array( $report ) ) {
			$this->back_session( $sid, 'error', 'Aucune correction à ajuster.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$adj = isset( $_POST['adj'] ) && is_array( $_POST['adj'] ) ? wp_unslash( $_POST['adj'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- converti en nombre ci-dessous.
		$cmt = isset( $_POST['cmt'] ) && is_array( $_POST['cmt'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['cmt'] ) ) : array();
		// phpcs:enable
		foreach ( $report as $i => &$r ) {
			$v = $adj[ $i ] ?? '';
			$r['adjusted'] = is_numeric( $v ) ? max( 0.0, min( (float) $r['points'], (float) $v ) ) : null;
			$r['comment']  = mb_substr( (string) ( $cmt[ $i ] ?? '' ), 0, 255 );
		}
		unset( $r );
		Grader::apply_adjustments( $sid, $report );
		Db::update( 'session', array( 'teacher_comment' => Ui::post_text( 'teacher_comment', 2000 ) ), array( 'id' => $sid ) );
		Audit::log( 'grade_adjust', 'session', $sid );
		$this->back_session( $sid, 'success', 'Ajustements enregistrés, note recalculée.' );
	}

	public function handle_publish(): void {
		Ui::guard( 'logierp_rs_publish', Capabilities::GRADE );
		$sid = Ui::post_int( 'session' );
		$s   = self::session_or_die( $sid );
		if ( 'CORRIGEE' !== $s['status'] ) {
			$this->back_session( $sid, 'error', 'Seule une session corrigée peut être publiée.' );
		}
		$pub = Ui::post_bool( 'published' );
		Db::update( 'session', array( 'published' => $pub ? 1 : 0 ), array( 'id' => $sid ) );
		Audit::log( $pub ? 'grade_publish' : 'grade_unpublish', 'session', $sid );
		$this->back_session( $sid, 'success', $pub ? 'Note publiée : l\'apprenant la voit sur son portail.' : 'Publication retirée.' );
	}

	public function handle_publish_all(): void {
		global $wpdb;
		Ui::guard( 'logierp_rs_publish_all', Capabilities::GRADE );
		$aid = Ui::post_int( 'assignment' );
		$ok  = false;
		foreach ( self::visible_assignments() as $a ) {
			$ok = $ok || (int) $a['id'] === $aid;
		}
		if ( ! $ok ) {
			wp_die( esc_html__( 'Assignation introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		$t = Db::t( 'session' );
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET published = 1 WHERE assignment_id = %d AND status = 'CORRIGEE' AND published = 0", $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Audit::log( 'grade_publish_all', 'assignment', $aid, array( 'count' => $n ) );
		Ui::redirect( self::PAGE, array( 'assignment' => $aid ), 'success', sprintf( '%d note(s) publiée(s).', $n ) );
	}

	public function handle_regrade(): void {
		Ui::guard( 'logierp_rs_regrade', Capabilities::GRADE );
		$sid = Ui::post_int( 'session' );
		$s   = self::session_or_die( $sid );
		if ( 'EN_COURS' === $s['status'] ) {
			$this->back_session( $sid, 'error', 'La session est encore en cours.' );
		}
		Grader::grade( self::ctx( $s ) );
		Audit::log( 'regrade', 'session', $sid );
		$this->back_session( $sid, 'success', 'Correction recalculée.' );
	}

	public function handle_force_submit(): void {
		Ui::guard( 'logierp_rs_force_submit', Capabilities::GRADE );
		$sid = Ui::post_int( 'session' );
		$s   = self::session_or_die( $sid );
		try {
			SessionService::submit( self::ctx( $s ) );
		} catch ( DomainError $e ) {
			$this->back_session( $sid, 'error', $e->getMessage() );
		}
		Audit::log( 'force_submit', 'session', $sid );
		$this->back_session( $sid, 'success', 'Session remise et corrigée.' );
	}

	public function handle_reset(): void {
		Ui::guard( 'logierp_rs_reset', Capabilities::GRADE );
		$sid = Ui::post_int( 'session' );
		$s   = self::session_or_die( $sid );
		SessionService::delete_session( $sid );
		Audit::log( 'session_delete', 'session', $sid, array( 'user' => (int) $s['user_id'] ) );
		Ui::redirect( self::PAGE, (int) $s['assignment_id'] ? array( 'assignment' => (int) $s['assignment_id'] ) : array(), 'success', 'Session supprimée.' );
	}

	/** Neutralise les formules dans un tableur (injection CSV). */
	private static function cell( $v ): string {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public function handle_export(): void {
		Ui::guard( 'logierp_rs_export', Capabilities::VIEW_CLASS );
		$rows = self::rows( Ui::post_int( 'assignment' ), Ui::post_bool( 'tests' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="logierp-resultats-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'Apprenant', 'Identifiant', 'Groupe', 'Classe', 'Scénario', 'Statut', 'Début', 'Remise', 'Points', 'Maximum', 'Note', 'Publiée' ), ';', '"', '\\' );
		$lines = array();
		foreach ( $rows as $r ) {
			// Travail de groupe : une ligne par membre, avec la note commune (report direct dans le carnet de notes).
			$tid = (int) $r['team_id'];
			$tm  = $tid ? \LogiERP\Domain\Teams::team( $tid ) : null;
			foreach ( $tid ? \LogiERP\Domain\Teams::team_member_ids( $tid ) : array( (int) $r['user_id'] ) as $uid ) {
				$lines[] = array( $uid, $tm ? (string) $tm['name'] : '', $r );
			}
		}
		foreach ( $lines as [ $uid, $team, $r ] ) {
			$u = get_userdata( $uid );
			fputcsv(
				$out,
				array_map(
					array( self::class, 'cell' ),
					array( $u ? $u->display_name : '', $u ? $u->user_login : '', $team, $r['class_name'] ?? '', $r['title'], self::STATUS[ $r['status'] ] ?? $r['status'], Ui::fr_datetime( $r['started_at'] ), Ui::fr_datetime( $r['submitted_at'] ), $r['score'] ?? '', $r['max_score'] ?? '', null !== $r['grade'] ? number_format( (float) $r['grade'], 1, '.', '' ) : '', (int) $r['published'] ? 'oui' : 'non' )
				),
				';',
				'"',
				'\\'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ───────── Suivi en direct ───────── */

	public function render_live(): void {
		$aid = Ui::get_int( 'assignment' );
		Ui::header( 'Suivi en direct', 'Avancement de chaque apprenant, actualisé toutes les 10 secondes. Déclenchez un imprévu, envoyez un message ou mettez une session en pause.' );
		$list = self::visible_assignments();
		Ui::get_form_open( 'logierp-live' );
		echo '<select name="assignment"><option value="0">Choisir une assignation…</option>';
		foreach ( $list as $as ) {
			echo '<option value="' . (int) $as['id'] . '"' . selected( $aid, (int) $as['id'], false ) . '>' . esc_html( $as['class_name'] . ' – ' . $as['title'] ) . '</option>';
		}
		echo '</select> <button class="button">Afficher</button></form>';
		if ( ! $aid ) {
			if ( ! $list ) {
				echo '<div class="lw-box"><p>Aucune assignation. Créez une classe et assignez-lui un scénario.</p></div>';
			}
			Ui::footer();
			return;
		}
		echo '<div class="lw-box"><div id="lw-live" data-assignment="' . (int) $aid . '" data-results="' . esc_url( Ui::url( self::PAGE, array( 'session' => '' ) ) ) . '">';
		echo '<p class="lw-live-meta"><span id="lw-live-updated">Chargement…</span> · <a target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\MissionDashboard::assignment_url( $aid ) ) . '">Indicateurs de la classe</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Apprenant ou groupe</th><th>Statut</th><th>Consignes</th><th>Actions réussies</th><th>Erreurs</th><th>Dernière action</th><th>Inactif</th><th>Note</th><th>Intervenir</th></tr></thead><tbody id="lw-live-body"><tr><td colspan="9">Chargement…</td></tr></tbody></table>';
		echo '</div></div>';
		if ( current_user_can( Capabilities::SCENARIOS ) ) {
			self::intervene_box( $aid );
		}
		Ui::footer();
	}

	/* ───────── Interventions en direct ───────── */

	/** Assignation visible par l'enseignant courant, avec son scénario. */
	private static function live_assignment( int $aid ): ?array {
		foreach ( self::visible_assignments() as $a ) {
			if ( (int) $a['id'] === $aid ) {
				global $wpdb;
				$a['scenario_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT scenario_id FROM ' . Db::t( 'assignment' ) . ' WHERE id = %d', $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return $a;
			}
		}
		return null;
	}

	/** Sessions en cours de l'assignation (hors tests). */
	private static function running_sessions( int $aid ): array {
		global $wpdb;
		$x = Db::t( 'session' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, team_id, assignment_id FROM {$x} WHERE assignment_id = %d AND status = 'EN_COURS' AND is_test = 0 ORDER BY id", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function intervene_box( int $aid ): void {
		global $wpdb;
		$as = self::live_assignment( $aid );
		if ( ! $as ) {
			return;
		}
		$scid    = (int) $as['scenario_id'];
		$targets = array( 'all' => 'Toute la classe (missions en cours)' );
		foreach ( self::running_sessions( $aid ) as $r ) {
			$targets[ (string) $r['id'] ] = \LogiERP\Domain\Teams::label( $r );
		}
		$mats = array( '' => '— article —' );
		foreach ( \LogiERP\Domain\Labels::scenario_material_ids( $scid ) as $mid ) {
			$m = \LogiERP\Domain\Catalog::material( $mid );
			if ( $m ) {
				$mats[ (string) $mid ] = $m['material_no'] . ' · ' . $m['designation'];
			}
		}
		$orders = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ref, partner_id FROM ' . Db::t( 'scenario_document' ) . " WHERE scenario_id = %d AND doc_type = 'SO' ORDER BY id", $scid ), ARRAY_A ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$c = \LogiERP\Domain\Sales::customer( (int) $d['partner_id'] );
			$orders[ (string) $d['ref'] ] = $d['ref'] . ' · ' . ( $c ? $c['name'] : '' );
		}
		$it = Db::t( 'intervention' );
		// Commandes urgentes déjà ajoutées en direct : LIVE1, LIVE2… (même numérotation dans chaque session).
		$live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM {$it} WHERE assignment_id = %d AND type = 'urgent_order' AND result = 'ok' GROUP BY session_id) t", $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		for ( $n = 1; $n <= $live; $n++ ) {
			$orders[ 'LIVE' . $n ] = 'LIVE' . $n . ' · commande urgente ajoutée en direct';
		}
		$custs = array( '' => '— client —' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, customer_no, name, city FROM ' . Db::t( 'customer' ) . ' WHERE dataset_id = %d AND blocked = 0 ORDER BY name LIMIT 300', \LogiERP\Domain\Catalog::dataset() ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$custs[ (string) $c['id'] ] = $c['name'] . ' · ' . $c['city'] . ' (' . $c['customer_no'] . ')';
		}
		echo '<div class="lw-box lw-intervene-box" id="lw-intervene"><h2>Intervenir dans la mission</h2><p class="description">Jouez le client, le service qualité ou un autre service, comme dans l\'entreprise. L\'apprenti reçoit une notification (sauf pour un manquant caché, qu\'il découvre au prélèvement). Chaque intervention est enregistrée et reprise dans le tableau d\'indicateurs.</p>';
		Ui::form_open( 'logierp_live_intervene', array( 'assignment' => $aid ) );
		echo '<table class="form-table">';
		Ui::field( 'Pour', Ui::select( 'target', $targets, 'all' ), 1 === count( $targets ) ? 'Aucune mission en cours pour l\'instant.' : '' );
		$radios = '';
		foreach ( \LogiERP\Domain\Interventions::TYPES as $k => $l ) {
			$radios .= '<label class="lw-radio"><input type="radio" name="type" value="' . esc_attr( $k ) . '"' . checked( 'order_change', $k, false ) . '> ' . esc_html( $l ) . '</label> ';
		}
		Ui::field( 'Intervention', $radios );
		echo '</table>';
		// Le client modifie sa commande.
		echo '<fieldset class="lw-iv" data-iv="order_change"><legend>Le client modifie sa commande</legend><table class="form-table">';
		Ui::field( 'Commande', $orders ? Ui::select( 'ref', $orders, (string) array_key_first( $orders ) ) : '<em>Ce scénario ne prépare aucune commande client.</em>' );
		Ui::field( 'Modification', Ui::select( 'change', \LogiERP\Domain\Interventions::CHANGES, 'qty' ) );
		Ui::field( 'Article', Ui::select( 'oc_material', $mats, '' ) . ' ' . Ui::input( 'oc_material_no', '', 'text', 'placeholder="ou n° d\'article" maxlength="20" style="width:140px"' ) );
		Ui::field( 'Nouvelle quantité', Ui::input( 'oc_qty', '', 'number', 'min="0" step="any" style="width:110px"' ) );
		Ui::field( 'Nouvelle date', Ui::input( 'oc_date', '', 'date' ), 'Pour « Avancer ou reporter la date ».' );
		echo '</table><p class="description">Refusée si l\'apprenti a déjà créé la livraison de cette commande : comme en entreprise, il est trop tard.</p></fieldset>';
		// Rupture.
		echo '<fieldset class="lw-iv" data-iv="shortage"><legend>Rupture ou stock indisponible</legend><table class="form-table">';
		Ui::field( 'Article', Ui::select( 'sh_material', $mats, '' ) . ' ' . Ui::input( 'sh_material_no', '', 'text', 'placeholder="ou n° d\'article" maxlength="20" style="width:140px"' ) );
		Ui::field( 'Situation', Ui::select( 'mode', \LogiERP\Domain\Interventions::SHORTAGES, 'block' ) );
		Ui::field( 'Quantité', Ui::input( 'sh_qty', '0', 'number', 'min="0" step="any" style="width:110px"' ), '0 = tout le stock libre de l\'article.' );
		Ui::field( 'Emplacement', Ui::input( 'bin_code', '', 'text', 'maxlength="40" placeholder="vide = tous les emplacements" style="width:220px"' ) );
		echo '</table><p class="description">« Manquant au rayonnage » : rien n\'apparaît dans le système. Retirez les cartes indiquées du mini-entrepôt ; l\'apprenti découvre le manque au prélèvement et doit le signaler.</p></fieldset>';
		// Commande urgente.
		echo '<fieldset class="lw-iv" data-iv="urgent_order"><legend>Nouvelle commande urgente</legend><table class="form-table">';
		Ui::field( 'Client', Ui::select( 'customer_id', $custs, '' ) );
		for ( $k = 1; $k <= 3; $k++ ) {
			Ui::field( 'Article ' . $k, Ui::select( 'uo_material_' . $k, $mats, '' ) . ' ' . Ui::input( 'uo_material_no_' . $k, '', 'text', 'placeholder="ou n° d\'article" maxlength="20" style="width:140px"' ) . ' quantité ' . Ui::input( 'uo_qty_' . $k, '', 'number', 'min="0" step="any" style="width:90px"' ) );
		}
		Ui::field( 'Message du client', Ui::input( 'note', '', 'text', 'maxlength="200" placeholder="ex. Livraison avant 11 h, chantier arrêté sans ce matériel"' ) );
		echo '</table></fieldset>';
		Ui::form_close( 'Intervenir' );
		self::interventions_log( $aid );
		echo '</div>';
	}

	private static function interventions_log( int $aid ): void {
		global $wpdb;
		$it   = Db::t( 'intervention' );
		$x    = Db::t( 'session' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.*, x.user_id, x.team_id, x.assignment_id AS s_assignment FROM {$it} i JOIN {$x} x ON x.id = i.session_id WHERE i.assignment_id = %d ORDER BY i.id DESC LIMIT 50", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return;
		}
		echo '<h3>Interventions de cette mission</h3><table class="widefat striped lw-iv-log"><thead><tr><th>Heure</th><th>Apprenant ou groupe</th><th>Intervention</th><th>Résultat</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( Ui::fr_datetime( $r['created_at'] ) ) . '</td><td>' . esc_html( \LogiERP\Domain\Teams::label( $r ) ) . '</td><td>' . esc_html( \LogiERP\Domain\Interventions::label( $r ) ) . '</td><td>' . ( 'ok' === $r['result'] ? '' : '<span class="lw-pill warn">refusée</span> ' ) . esc_html( $r['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function material_from_post( string $sel, string $no ): int {
		$no = Ui::post_str( $no, 20 );
		if ( '' !== $no ) {
			$m = \LogiERP\Domain\Catalog::material_by_no( $no );
			return $m ? (int) $m['id'] : -1;
		}
		return Ui::post_int( $sel );
	}

	public function handle_intervene(): void {
		Ui::guard( 'logierp_live_intervene', Capabilities::SCENARIOS );
		$aid  = Ui::post_int( 'assignment' );
		$back = static fn( string $type, string $text, array $details = array() ) => Ui::redirect( 'logierp-live', array( 'assignment' => $aid ), $type, $text, $details );
		if ( ! self::live_assignment( $aid ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		$type = Ui::post_str( 'type', 20 );
		if ( ! isset( \LogiERP\Domain\Interventions::TYPES[ $type ] ) ) {
			$back( 'error', 'Choisissez une intervention.' );
		}
		$p = array();
		if ( 'order_change' === $type ) {
			$p = array( 'ref' => Ui::post_str( 'ref', 20 ), 'change' => Ui::post_str( 'change', 10 ), 'material_id' => self::material_from_post( 'oc_material', 'oc_material_no' ), 'qty' => (float) Ui::post_str( 'oc_qty', 20 ), 'date' => Ui::post_str( 'oc_date', 10 ) );
		} elseif ( 'shortage' === $type ) {
			$p = array( 'material_id' => self::material_from_post( 'sh_material', 'sh_material_no' ), 'mode' => Ui::post_str( 'mode', 10 ), 'qty' => (float) Ui::post_str( 'sh_qty', 20 ), 'bin_code' => Ui::post_str( 'bin_code', 40 ) );
		} else {
			$items = array();
			for ( $k = 1; $k <= 3; $k++ ) {
				$mid = self::material_from_post( 'uo_material_' . $k, 'uo_material_no_' . $k );
				$q   = (float) Ui::post_str( 'uo_qty_' . $k, 20 );
				if ( $mid > 0 && $q > 0 ) {
					$items[] = array( 'material_id' => $mid, 'qty' => $q );
				}
			}
			$p = array( 'customer_id' => Ui::post_int( 'customer_id' ), 'items' => $items, 'note' => Ui::post_str( 'note', 200 ) );
		}
		$target   = Ui::post_str( 'target', 20 );
		$sessions = self::running_sessions( $aid );
		if ( 'all' !== $target ) {
			$sessions = array_values( array_filter( $sessions, static fn( $r ) => (int) $r['id'] === (int) $target ) );
		}
		if ( ! $sessions ) {
			$back( 'error', 'Aucune mission en cours pour cette cible.' );
		}
		$ok      = 0;
		$details = array();
		foreach ( $sessions as $r ) {
			$ctx = SessionService::context_for( (int) $r['id'], get_current_user_id(), false );
			if ( ! $ctx ) {
				continue;
			}
			[ $done, $msg ] = \LogiERP\Domain\Interventions::apply( $ctx, $type, $p, $aid );
			$details[]      = \LogiERP\Domain\Teams::label( $ctx->session ) . ' : ' . ( $done ? '' : 'refusée – ' ) . $msg;
			$ok            += $done ? 1 : 0;
		}
		$n = count( $sessions );
		$back( $ok === $n ? 'success' : ( $ok ? 'warning' : 'error' ), sprintf( '%s : appliquée à %d mission(s) sur %d.', \LogiERP\Domain\Interventions::TYPES[ $type ], $ok, $n ), array_slice( $details, 0, 40 ) );
	}
}
