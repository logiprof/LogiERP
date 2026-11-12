<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Security\Audit;
use LogiERP\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groupes de travail et codes de mission.
 *
 * - Groupes de la classe : sous-groupes réutilisables (un apprenant appartient au plus à un groupe par classe).
 * - Liste de l'assignation (roster) : figée à l'assignation. En travail de groupe, chaque groupe devient une équipe
 *   qui partage une seule session ; en travail individuel, chacun a sa session.
 * - Chaque apprenant de la liste reçoit un code personnel. Un code n'ouvre la mission que pour son titulaire,
 *   connecté avec son propre compte : un code trouvé ou échangé ne sert à rien à un autre apprenant.
 */
final class Teams {

	/** Alphabet sans caractères ambigus (0/O, 1/I/L). */
	private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	public const CODE_LEN  = 8;

	/** roster : 0 = toute la classe sans liste (assignations antérieures), 1 = liste choisie, 2 = toute la classe (complétée automatiquement). */
	public const ROSTER_NONE  = 0;
	public const ROSTER_FIXED = 1;
	public const ROSTER_CLASS = 2;

	/* ───────────── Groupes de la classe ───────────── */

	/** @return array<int,array{id:int,name:string,sort:int,members:int[]}> Groupes de la classe, membres inclus. */
	public static function groups( int $class_id ): array {
		global $wpdb;
		$g    = Db::t( 'class_group' );
		$gm   = Db::t( 'class_group_member' );
		$cm   = Db::t( 'class_member' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, sort FROM {$g} WHERE class_id = %d ORDER BY sort, id", $class_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = array( 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'sort' => (int) $r['sort'], 'members' => array() );
		}
		// Seuls les membres encore inscrits dans la classe comptent.
		$mem = (array) $wpdb->get_results( $wpdb->prepare( "SELECT gm.group_id, gm.user_id FROM {$gm} gm JOIN {$cm} m ON m.class_id = gm.class_id AND m.user_id = gm.user_id WHERE gm.class_id = %d", $class_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $mem as $m ) {
			if ( isset( $out[ (int) $m['group_id'] ] ) ) {
				$out[ (int) $m['group_id'] ]['members'][] = (int) $m['user_id'];
			}
		}
		foreach ( $out as &$o ) {
			usort( $o['members'], array( self::class, 'cmp_users' ) );
		}
		return $out;
	}

	/** Groupe d'un apprenant dans la classe (0 = aucun). */
	public static function group_of( int $class_id, int $user_id ): int {
		global $wpdb;
		$gm = Db::t( 'class_group_member' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$gm} WHERE class_id = %d AND user_id = %d", $class_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function create_group( int $class_id, string $name ): int {
		global $wpdb;
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			$name = 'Groupe ' . ( count( self::groups( $class_id ) ) + 1 );
		}
		$g    = Db::t( 'class_group' );
		$sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort), 0) + 1 FROM {$g} WHERE class_id = %d", $class_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $sort > 60 ) {
			throw new DomainError( 'Une classe compte au plus 60 groupes.' );
		}
		return Db::insert( 'class_group', array( 'class_id' => $class_id, 'name' => $name, 'sort' => $sort, 'created_at' => Db::now() ) );
	}

	public static function rename_group( int $class_id, int $group_id, string $name ): void {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			throw new DomainError( 'Indiquez un nom de groupe.' );
		}
		self::own_group( $class_id, $group_id );
		Db::update( 'class_group', array( 'name' => $name ), array( 'id' => $group_id ) );
	}

	public static function delete_group( int $class_id, int $group_id ): void {
		global $wpdb;
		self::own_group( $class_id, $group_id );
		$wpdb->delete( Db::t( 'class_group_member' ), array( 'group_id' => $group_id ) );
		$wpdb->delete( Db::t( 'class_group' ), array( 'id' => $group_id ) );
	}

	/** Place un apprenant dans un groupe de sa classe (0 = sans groupe). */
	public static function set_member( int $class_id, int $user_id, int $group_id ): void {
		global $wpdb;
		if ( ! self::in_class( $class_id, $user_id ) ) {
			throw new DomainError( 'Cet apprenant n\'est pas inscrit dans la classe.' );
		}
		$gm = Db::t( 'class_group_member' );
		if ( ! $group_id ) {
			$wpdb->delete( $gm, array( 'class_id' => $class_id, 'user_id' => $user_id ) );
			return;
		}
		self::own_group( $class_id, $group_id );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$gm} (group_id, class_id, user_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)", $group_id, $class_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Répartit les apprenants de la classe au hasard en groupes de $size (les groupes existants sont remplacés).
	 * Les effectifs sont équilibrés : 13 apprenants par 3 donnent 4 groupes (4, 3, 3, 3).
	 */
	public static function auto_split( int $class_id, int $size ): int {
		global $wpdb;
		$size  = max( 2, min( 8, $size ) );
		$cm    = Db::t( 'class_member' );
		$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$cm} WHERE class_id = %d", $class_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( count( $users ) < 2 ) {
			throw new DomainError( 'Il faut au moins deux apprenants dans la classe pour former des groupes.' );
		}
		// Mélange cryptographique (aucune prévisibilité de la répartition).
		for ( $i = count( $users ) - 1; $i > 0; $i-- ) {
			$j = random_int( 0, $i );
			[ $users[ $i ], $users[ $j ] ] = array( $users[ $j ], $users[ $i ] );
		}
		$n = (int) ceil( count( $users ) / $size );
		return Db::transaction(
			static function () use ( $wpdb, $class_id, $users, $n ) {
				$wpdb->delete( Db::t( 'class_group_member' ), array( 'class_id' => $class_id ) );
				$wpdb->delete( Db::t( 'class_group' ), array( 'class_id' => $class_id ) );
				$ids = array();
				for ( $k = 1; $k <= $n; $k++ ) {
					$ids[] = Db::insert( 'class_group', array( 'class_id' => $class_id, 'name' => 'Groupe ' . $k, 'sort' => $k, 'created_at' => Db::now() ) );
				}
				foreach ( $users as $i => $uid ) {
					Db::insert( 'class_group_member', array( 'group_id' => $ids[ $i % $n ], 'class_id' => $class_id, 'user_id' => $uid ) );
				}
				return $n;
			}
		);
	}

	/* ───────────── Liste de l'assignation et codes ───────────── */

	/**
	 * Crée la liste figée d'une nouvelle assignation.
	 *
	 * @param int[] $group_ids Groupes retenus (travail de groupe).
	 * @param int[] $user_ids  Apprenants retenus (travail individuel ; vide = toute la classe).
	 * @return array{teams:int,members:int,without_group:int}
	 */
	public static function create_roster( int $aid, int $class_id, string $mode, array $group_ids, array $user_ids ): array {
		$teams   = 0;
		$members = 0;
		$without = 0;
		if ( 'GROUP' === $mode ) {
			$groups = self::groups( $class_id );
			$pick   = array_values( array_intersect( array_keys( $groups ), array_map( 'intval', $group_ids ) ) );
			foreach ( $pick as $k => $gid ) {
				$g = $groups[ $gid ];
				if ( ! $g['members'] ) {
					continue;
				}
				$tid = Db::insert( 'assignment_team', array( 'assignment_id' => $aid, 'group_id' => $gid, 'name' => $g['name'], 'sort' => $k + 1 ) );
				++$teams;
				foreach ( $g['members'] as $uid ) {
					self::add_member( $aid, $uid, $tid );
					++$members;
				}
			}
			$in_groups = array();
			foreach ( $groups as $g ) {
				$in_groups = array_merge( $in_groups, $g['members'] );
			}
			$without = count( array_diff( self::class_members( $class_id ), $in_groups ) );
			return array( 'teams' => $teams, 'members' => $members, 'without_group' => $without );
		}
		$all  = self::class_members( $class_id );
		$pick = $user_ids ? array_values( array_intersect( $all, array_map( 'intval', $user_ids ) ) ) : $all;
		foreach ( $pick as $uid ) {
			self::add_member( $aid, $uid, 0 );
			++$members;
		}
		return array( 'teams' => 0, 'members' => $members, 'without_group' => 0 );
	}

	/**
	 * Complète la liste : nouvel inscrit de la classe (assignation à toute la classe) ou nouveau membre d'un groupe
	 * déjà retenu (travail de groupe). Personne n'est retiré automatiquement : les sessions restent intactes.
	 */
	public static function sync( array $assignment ): void {
		global $wpdb;
		$aid = (int) $assignment['id'];
		$cid = (int) $assignment['class_id'];
		if ( ! $cid || self::ROSTER_NONE === (int) $assignment['roster'] ) {
			return;
		}
		$am  = Db::t( 'assignment_member' );
		$has = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$am} WHERE assignment_id = %d", $aid ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'GROUP' === $assignment['work_mode'] ) {
			$groups = self::groups( $cid );
			foreach ( self::teams( $aid ) as $t ) {
				if ( ! $t['group_id'] || ! isset( $groups[ $t['group_id'] ] ) ) {
					continue;
				}
				foreach ( $groups[ $t['group_id'] ]['members'] as $uid ) {
					if ( ! isset( $has[ $uid ] ) ) {
						self::add_member( $aid, $uid, $t['id'] );
						$has[ $uid ] = true;
					}
				}
			}
			return;
		}
		if ( self::ROSTER_CLASS === (int) $assignment['roster'] ) {
			foreach ( self::class_members( $cid ) as $uid ) {
				if ( ! isset( $has[ $uid ] ) ) {
					self::add_member( $aid, $uid, 0 );
				}
			}
		}
	}

	/** Ligne de la liste pour un apprenant, null s'il n'y figure pas. */
	public static function member( int $aid, int $user_id ): ?array {
		global $wpdb;
		$am = Db::t( 'assignment_member' );
		$r  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$am} WHERE assignment_id = %d AND user_id = %d", $aid, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function add_member( int $aid, int $user_id, int $team_id ): void {
		global $wpdb;
		$am = Db::t( 'assignment_member' );
		for ( $try = 0; $try < 5; $try++ ) {
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$am} (assignment_id, user_id, team_id, code, added_at) VALUES (%d, %d, %d, %s, %s)", $aid, $user_id, $team_id, self::new_code(), Db::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $ok || self::member( $aid, $user_id ) ) {
				return; // Inséré, ou déjà présent.
			}
		}
		throw new DomainError( 'Impossible de générer un code unique. Réessayez.' );
	}

	public static function remove_member( int $aid, int $user_id ): void {
		global $wpdb;
		$wpdb->delete( Db::t( 'assignment_member' ), array( 'assignment_id' => $aid, 'user_id' => $user_id ) );
	}

	/** Change un apprenant d'équipe ; il n'a plus accès à la session de son ancienne équipe. */
	public static function move_member( int $aid, int $user_id, int $team_id ): void {
		global $wpdb;
		$t = Db::t( 'assignment_team' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d AND assignment_id = %d", $team_id, $aid ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new DomainError( 'Groupe introuvable.' );
		}
		if ( ! self::member( $aid, $user_id ) ) {
			self::add_member( $aid, $user_id, $team_id );
			return;
		}
		Db::update( 'assignment_member', array( 'team_id' => $team_id ), array( 'assignment_id' => $aid, 'user_id' => $user_id ) );
	}

	/** Nouveau code pour un apprenant (code perdu ou divulgué) : l'ancien ne fonctionne plus. */
	public static function regenerate_code( int $aid, int $user_id ): string {
		for ( $try = 0; $try < 5; $try++ ) {
			$code = self::new_code();
			global $wpdb;
			// Le code n'ouvre la mission qu'au compte de son titulaire : un apprenti déjà entré garde son accès.
			$ok = $wpdb->update( Db::t( 'assignment_member' ), array( 'code' => $code ), array( 'assignment_id' => $aid, 'user_id' => $user_id ) );
			if ( false !== $ok ) {
				return $code;
			}
		}
		throw new DomainError( 'Impossible de générer un code unique. Réessayez.' );
	}

	/** Ajoute une équipe vide à une assignation de groupe (répartition ajustée par l'enseignant). */
	public static function add_team( int $aid, string $name ): int {
		global $wpdb;
		$t    = Db::t( 'assignment_team' );
		$sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort), 0) + 1 FROM {$t} WHERE assignment_id = %d", $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$name = self::clean_name( $name ) ?: 'Groupe ' . $sort;
		return Db::insert( 'assignment_team', array( 'assignment_id' => $aid, 'group_id' => 0, 'name' => $name, 'sort' => $sort ) );
	}

	/** @return array<int,array{id:int,name:string,group_id:int,members:array}> Équipes d'une assignation et leurs membres. */
	public static function teams( int $aid ): array {
		global $wpdb;
		$t    = Db::t( 'assignment_team' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, group_id FROM {$t} WHERE assignment_id = %d ORDER BY sort, id", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = array( 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'group_id' => (int) $r['group_id'], 'members' => array() );
		}
		foreach ( self::roster( $aid ) as $m ) {
			if ( isset( $out[ (int) $m['team_id'] ] ) ) {
				$out[ (int) $m['team_id'] ]['members'][] = $m;
			}
		}
		return $out;
	}

	/** Toute la liste (une ligne par apprenant), triée par nom. */
	public static function roster( int $aid ): array {
		global $wpdb;
		$am   = Db::t( 'assignment_member' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$am} WHERE assignment_id = %d", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		usort( $rows, static fn( $a, $b ) => self::cmp_users( (int) $a['user_id'], (int) $b['user_id'] ) );
		return $rows;
	}

	public static function team( int $team_id ): ?array {
		global $wpdb;
		$t = Db::t( 'assignment_team' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $team_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/** @return int[] */
	public static function team_member_ids( int $team_id ): array {
		global $wpdb;
		$am  = Db::t( 'assignment_member' );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$am} WHERE team_id = %d", $team_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		usort( $ids, array( self::class, 'cmp_users' ) );
		return $ids;
	}

	/**
	 * Vérifie le code personnel avant le premier accès d'un apprenant à la mission.
	 * Un code valide d'un autre apprenant est refusé comme un code faux, et signalé à l'enseignant (journal d'audit).
	 */
	public static function check_code( array $assignment, array $member, string $code ): void {
		if ( ! (int) $assignment['access_code'] || null !== $member['code_used_at'] ) {
			return;
		}
		$code = self::normalize( $code );
		if ( '' === $code ) {
			throw new DomainError( 'Saisissez votre code personnel de mission (remis par votre enseignant).' );
		}
		if ( hash_equals( (string) $member['code'], $code ) ) {
			Db::update( 'assignment_member', array( 'code_used_at' => Db::now() ), array( 'id' => (int) $member['id'] ) );
			return;
		}
		self::refuse( (int) $assignment['id'], $code );
	}

	/**
	 * Recherche la mission d'un code saisi dans le champ « Code de mission » du portail.
	 * Ne renvoie que l'assignation de l'apprenant connecté : le code d'un autre est refusé.
	 */
	public static function assignment_for_code( int $user_id, string $code ): int {
		global $wpdb;
		$code = self::normalize( $code );
		if ( strlen( $code ) !== self::CODE_LEN ) {
			self::refuse( 0, $code );
		}
		$am  = Db::t( 'assignment_member' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT assignment_id, user_id FROM {$am} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			self::refuse( $row ? (int) $row['assignment_id'] : 0, $code );
		}
		return (int) $row['assignment_id'];
	}

	/** @return never */
	private static function refuse( int $aid, string $code ): void {
		global $wpdb;
		$owner = 0;
		if ( '' !== $code ) {
			$am    = Db::t( 'assignment_member' );
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$am} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $owner && $owner !== get_current_user_id() ) {
			// Code authentique utilisé par un autre compte : tracé pour l'enseignant, sans rien révéler à l'apprenant.
			$aid = $aid ?: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT assignment_id FROM ' . Db::t( 'assignment_member' ) . ' WHERE code = %s', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			Audit::log( 'code_misuse', 'assignment', $aid, array( 'owner' => $owner ) );
		}
		if ( ! RateLimiter::hit( 'code' ) ) {
			throw new DomainError( 'Trop de codes erronés. Réessayez dans un quart d\'heure ou demandez votre code à votre enseignant.' );
		}
		throw new DomainError( 'Code refusé : ce code n\'est pas le vôtre ou il est erroné. Chaque apprenti·e a son propre code, valable uniquement avec son compte.' );
	}

	/** Tentatives d'utilisation du code d'un autre apprenant (pour l'enseignant). */
	public static function misuse( int $aid ): array {
		global $wpdb;
		$t = Db::t( 'audit_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, details, created_at FROM {$t} WHERE action = 'code_misuse' AND object_type = 'assignment' AND object_id = %d ORDER BY id DESC LIMIT 30", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ───────────── Sessions partagées ───────────── */

	/** L'utilisateur travaille-t-il dans cette session (titulaire ou membre de l'équipe) ? */
	public static function is_participant( array $session, int $user_id ): bool {
		if ( (int) $session['user_id'] === $user_id ) {
			return ! (int) ( $session['team_id'] ?? 0 ) || self::in_team( (int) $session['assignment_id'], (int) $session['team_id'], $user_id );
		}
		$tid = (int) ( $session['team_id'] ?? 0 );
		return $tid > 0 && self::in_team( (int) $session['assignment_id'], $tid, $user_id );
	}

	/** Membre de l'équipe, et entré dans la mission avec son code personnel si l'assignation l'exige. */
	private static function in_team( int $aid, int $team_id, int $user_id ): bool {
		$m = self::member( $aid, $user_id );
		if ( ! $m || (int) $m['team_id'] !== $team_id ) {
			return false;
		}
		return null !== $m['code_used_at'] || ! self::code_required( $aid );
	}

	private static function code_required( int $aid ): bool {
		static $cache = array();
		if ( ! isset( $cache[ $aid ] ) ) {
			global $wpdb;
			$cache[ $aid ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT access_code FROM ' . Db::t( 'assignment' ) . ' WHERE id = %d', $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $cache[ $aid ];
	}

	/** Nom affiché d'une session : apprenant, ou groupe et ses membres. */
	public static function label( array $session, bool $with_members = true ): string {
		$tid = (int) ( $session['team_id'] ?? 0 );
		if ( $tid ) {
			$t     = self::team( $tid );
			$name  = $t ? (string) $t['name'] : 'Groupe';
			$names = array_map( array( self::class, 'short_name' ), self::team_member_ids( $tid ) );
			return $with_members && $names ? $name . ' · ' . implode( ', ', $names ) : $name;
		}
		$u = get_userdata( (int) $session['user_id'] );
		return $u ? $u->display_name : '#' . (int) $session['user_id'];
	}

	/** « Prénom N. » : court et suffisant dans une équipe. */
	public static function short_name( int $user_id ): string {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return '#' . $user_id;
		}
		if ( $u->first_name && $u->last_name ) {
			return $u->first_name . ' ' . mb_substr( $u->last_name, 0, 1 ) . '.';
		}
		return $u->display_name;
	}

	/* ───────────── Outils ───────────── */

	public static function format( string $code ): string {
		return strlen( $code ) === self::CODE_LEN ? substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) : $code;
	}

	public static function normalize( string $code ): string {
		return substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $code ) ), 0, 16 );
	}

	private static function new_code(): string {
		$c   = '';
		$max = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < self::CODE_LEN; $i++ ) {
			$c .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $c;
	}

	/** @return int[] */
	public static function class_members( int $class_id ): array {
		global $wpdb;
		$cm = Db::t( 'class_member' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$cm} WHERE class_id = %d", $class_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function in_class( int $class_id, int $user_id ): bool {
		global $wpdb;
		$cm = Db::t( 'class_member' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$cm} WHERE class_id = %d AND user_id = %d", $class_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function own_group( int $class_id, int $group_id ): void {
		global $wpdb;
		$g = Db::t( 'class_group' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$g} WHERE id = %d AND class_id = %d", $group_id, $class_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new DomainError( 'Groupe introuvable.' );
		}
	}

	private static function clean_name( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, 60 );
	}

	public static function cmp_users( int $a, int $b ): int {
		$ua = get_userdata( $a );
		$ub = get_userdata( $b );
		$ka = $ua ? $ua->last_name . ' ' . $ua->first_name . ' ' . $ua->display_name : '';
		$kb = $ub ? $ub->last_name . ' ' . $ub->first_name . ' ' . $ub->display_name : '';
		return strcasecmp( $ka, $kb ) ?: $a <=> $b;
	}
}
