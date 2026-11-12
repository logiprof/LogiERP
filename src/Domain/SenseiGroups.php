<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groupes Sensei Pro (type de contenu « group », membres dans {prefix}sensei_pro_groups_students).
 *
 * Lecture seule côté Sensei : LogiERP ne modifie jamais un groupe ni ses membres.
 * Une classe LogiERP liée à un groupe reprend ses élèves (origine « sensei ») et suit ses changements :
 * élève ajouté au groupe → inscrit dans la classe ; élève retiré du groupe → retiré de la classe.
 * Les apprenants ajoutés à la main (origine « manual ») ne sont jamais retirés par la synchronisation.
 * Sans Sensei Pro, toutes les méthodes répondent « indisponible » sans erreur.
 */
final class SenseiGroups {

	/** Mêmes favoris que LogiProgress et LogiExam (métadonnée de l'enseignant). */
	public const FAVORITES_META = 'psgpt_favorite_groups';

	/** Une synchronisation automatique au plus toutes les 5 minutes par classe (pages enseignant). */
	private const THROTTLE = 300;

	private static ?bool $available = null;

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sensei_pro_groups_students';
	}

	/** Sensei Pro est installé : la table des membres de groupes existe. */
	public static function available(): bool {
		if ( null === self::$available ) {
			global $wpdb;
			$t               = self::table();
			self::$available = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t;
		}
		return self::$available;
	}

	/** Réinitialise le cache de détection (tests). */
	public static function reset_cache(): void {
		self::$available = null;
	}

	/** @return int[] */
	public static function favorite_ids( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		$ids     = $user_id ? get_user_meta( $user_id, self::FAVORITES_META, true ) : array();
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Groupes publiés, favoris de l'enseignant d'abord puis par ordre naturel du nom.
	 *
	 * @return array<int,array{id:int,title:string,count:int,fav:bool}>
	 */
	public static function groups(): array {
		if ( ! self::available() ) {
			return array();
		}
		global $wpdb;
		$t    = self::table();
		$rows = (array) $wpdb->get_results( "SELECT p.ID AS id, p.post_title AS title, COUNT(DISTINCT u.ID) AS n FROM {$wpdb->posts} p LEFT JOIN {$t} g ON g.group_id = p.ID LEFT JOIN {$wpdb->users} u ON u.ID = g.student_id WHERE p.post_type = 'group' AND p.post_status = 'publish' GROUP BY p.ID, p.post_title LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$favs = self::favorite_ids();
		$out  = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['id'];
			$out[] = array(
				'id'    => $id,
				'title' => '' !== trim( (string) $r['title'] ) ? (string) $r['title'] : sprintf( 'Groupe #%d', $id ),
				'count' => (int) $r['n'],
				'fav'   => in_array( $id, $favs, true ),
			);
		}
		usort(
			$out,
			static fn( $a, $b ) => ( $b['fav'] <=> $a['fav'] ) ?: strnatcasecmp( remove_accents( $a['title'] ), remove_accents( $b['title'] ) )
		);
		return $out;
	}

	/** Groupe publié, ou null. */
	public static function group( int $id ): ?array {
		if ( $id <= 0 || ! self::available() ) {
			return null;
		}
		$p = get_post( $id );
		if ( ! $p || 'group' !== $p->post_type || 'publish' !== $p->post_status ) {
			return null;
		}
		return array( 'id' => (int) $p->ID, 'title' => '' !== trim( $p->post_title ) ? $p->post_title : sprintf( 'Groupe #%d', $p->ID ) );
	}

	/** Titre d'un groupe lié (même dépublié ou supprimé, pour l'affichage). */
	public static function title( int $id ): string {
		$p = $id > 0 ? get_post( $id ) : null;
		return $p && 'group' === $p->post_type ? ( '' !== trim( $p->post_title ) ? $p->post_title : sprintf( 'Groupe #%d', $id ) ) : sprintf( 'Groupe #%d (introuvable)', $id );
	}

	/** @return int[] Comptes existants membres du groupe. */
	public static function member_ids( int $group_id ): array {
		if ( $group_id <= 0 || ! self::available() ) {
			return array();
		}
		global $wpdb;
		$t = self::table();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT g.student_id FROM {$t} g JOIN {$wpdb->users} u ON u.ID = g.student_id WHERE g.group_id = %d", $group_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Année scolaire lue dans le nom du groupe (« C1a 2026-2027 » → « 2026-2027 »). */
	public static function year_from_title( string $title ): string {
		return preg_match( '/\b(20\d{2})\s*[-\/–]\s*(20\d{2}|\d{2})\b/u', $title, $m ) ? $m[1] . '-' . ( 2 === strlen( $m[2] ) ? substr( $m[1], 0, 2 ) . $m[2] : $m[2] ) : '';
	}

	/**
	 * Donne au compte le rôle apprenant si nécessaire.
	 * Jamais pour un compte du personnel (rédacteur, enseignant…) : il est alors ignoré.
	 */
	private static function ensure_player( int $user_id ): bool {
		$u = get_current_user_id() === $user_id ? wp_get_current_user() : get_userdata( $user_id );
		if ( ! $u || ! $u->exists() ) {
			return false;
		}
		if ( user_can( $u, Capabilities::PLAY ) ) {
			return true;
		}
		if ( user_can( $u, 'edit_posts' ) ) {
			return false;
		}
		$u->add_role( 'logierp_student' );
		return true;
	}

	/**
	 * Aligne les membres d'une classe liée sur son groupe Sensei.
	 *
	 * @param array $class Ligne de la table des classes.
	 * @return array{ok:bool,added:int,removed:int,skipped:int,members:int}
	 */
	public static function sync_class( array $class ): array {
		$res = array( 'ok' => false, 'added' => 0, 'removed' => 0, 'skipped' => 0, 'members' => 0 );
		$gid = (int) ( $class['sensei_group_id'] ?? 0 );
		$cid = (int) ( $class['id'] ?? 0 );
		if ( ! $gid || ! $cid || ! self::available() || ! self::group( $gid ) ) {
			return $res; // Groupe introuvable ou dépublié : on ne retire personne.
		}
		global $wpdb;
		$cm      = Db::t( 'class_member' );
		$want    = self::member_ids( $gid );
		$current = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, source FROM {$cm} WHERE class_id = %d", $cid ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current[ (int) $r['user_id'] ] = (string) $r['source'];
		}
		foreach ( $want as $uid ) {
			if ( isset( $current[ $uid ] ) ) {
				continue;
			}
			if ( ! self::ensure_player( $uid ) ) {
				++$res['skipped'];
				continue;
			}
			$res['added'] += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cm} (class_id, user_id, added_at, source) VALUES (%d, %d, %s, 'sensei')", $cid, $uid, Db::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$gone = array_diff( array_keys( array_filter( $current, static fn( $s ) => 'sensei' === $s ) ), $want );
		foreach ( $gone as $uid ) {
			$res['removed'] += (int) $wpdb->delete( $cm, array( 'class_id' => $cid, 'user_id' => (int) $uid, 'source' => 'sensei' ) );
		}
		Db::update( 'class', array( 'synced_at' => Db::now() ), array( 'id' => $cid ) );
		$res['ok']      = true;
		$res['members'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cm} WHERE class_id = %d", $cid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $res['added'] || $res['removed'] ) {
			Audit::log( 'class_sensei_sync', 'class', $cid, array( 'group' => $gid, 'added' => $res['added'], 'removed' => $res['removed'] ) );
		}
		return $res;
	}

	/** Synchronise si la dernière synchronisation date de plus de 5 minutes. */
	public static function sync_if_stale( array $class ): void {
		if ( (int) ( $class['sensei_group_id'] ?? 0 ) && ( empty( $class['synced_at'] ) || strtotime( $class['synced_at'] . ' UTC' ) < time() - self::THROTTLE ) ) {
			self::sync_class( $class );
		}
	}

	/** Toutes les classes liées (tâche planifiée horaire). */
	public static function sync_all(): void {
		if ( ! self::available() ) {
			return;
		}
		global $wpdb;
		$t = Db::t( 'class' );
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE sensei_group_id > 0 LIMIT 500", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::sync_class( $c );
		}
	}

	/**
	 * Aligne les classes d'un seul élève (connexion, ouverture du simulateur) :
	 * il voit tout de suite les scénarios de la classe liée à son groupe.
	 */
	public static function sync_user( int $user_id ): void {
		if ( $user_id <= 0 || ! self::available() ) {
			return;
		}
		global $wpdb;
		$c  = Db::t( 'class' );
		$cm = Db::t( 'class_member' );
		$g  = self::table();
		// Classes liées à un groupe publié dont l'élève est membre, et où il n'est pas encore inscrit.
		$missing = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT c.id FROM {$c} c JOIN {$g} s ON s.group_id = c.sensei_group_id AND s.student_id = %d JOIN {$wpdb->posts} p ON p.ID = c.sensei_group_id AND p.post_type = 'group' AND p.post_status = 'publish' LEFT JOIN {$cm} m ON m.class_id = c.id AND m.user_id = %d WHERE c.sensei_group_id > 0 AND m.id IS NULL", $user_id, $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $missing && self::ensure_player( $user_id ) ) {
			foreach ( $missing as $cid ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cm} (class_id, user_id, added_at, source) VALUES (%d, %d, %s, 'sensei')", $cid, $user_id, Db::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		// Inscriptions « sensei » d'un groupe publié qu'il a quitté.
		$stale = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT m.class_id FROM {$cm} m JOIN {$c} c ON c.id = m.class_id JOIN {$wpdb->posts} p ON p.ID = c.sensei_group_id AND p.post_type = 'group' AND p.post_status = 'publish' LEFT JOIN {$g} s ON s.group_id = c.sensei_group_id AND s.student_id = m.user_id WHERE m.user_id = %d AND m.source = 'sensei' AND s.student_id IS NULL", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $stale as $cid ) {
			$wpdb->delete( $cm, array( 'class_id' => $cid, 'user_id' => $user_id, 'source' => 'sensei' ) );
		}
	}

	/** Connexion : met à jour les classes de l'élève. */
	public static function on_login( string $login, $user = null ): void {
		if ( $user instanceof \WP_User ) {
			self::sync_user( (int) $user->ID );
		}
	}
}
