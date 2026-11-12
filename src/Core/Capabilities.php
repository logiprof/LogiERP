<?php
declare(strict_types=1);

namespace LogiERP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rôles et capacités LogiERP. Aucun rôle existant n'est modifié, sauf l'ajout
 * des capacités LogiERP à l'administrateur.
 */
final class Capabilities {

	public const SETTINGS     = 'logierp_manage_settings';
	public const MASTER_DATA  = 'logierp_manage_master_data';
	public const SCENARIOS    = 'logierp_manage_scenarios';
	public const CLASSES      = 'logierp_manage_classes';
	public const VIEW_ALL     = 'logierp_view_all_results';
	public const VIEW_CLASS   = 'logierp_view_class_results';
	public const GRADE        = 'logierp_grade';
	public const RESET        = 'logierp_reset_data';
	public const PLAY         = 'logierp_play';
	public const VIEW_OWN     = 'logierp_view_own_results';

	private const VERSION = '1';

	/** @return array<string,array{0:string,1:string[]}> */
	public static function roles(): array {
		return array(
			'logierp_teacher'  => array(
				__( 'Enseignant LogiERP', 'logierp' ),
				array( 'read', self::SCENARIOS, self::CLASSES, self::VIEW_CLASS, self::GRADE, self::PLAY, self::VIEW_OWN ),
			),
			'logierp_student'  => array(
				__( 'Apprenant LogiERP', 'logierp' ),
				array( 'read', self::PLAY, self::VIEW_OWN ),
			),
			'logierp_observer' => array(
				__( 'Observateur LogiERP', 'logierp' ),
				array( 'read', self::VIEW_CLASS ),
			),
		);
	}

	public static function admin_caps(): array {
		return array( self::SETTINGS, self::MASTER_DATA, self::SCENARIOS, self::CLASSES, self::VIEW_ALL, self::VIEW_CLASS, self::GRADE, self::RESET, self::PLAY, self::VIEW_OWN );
	}

	public static function install(): void {
		foreach ( self::roles() as $slug => $def ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				add_role( $slug, $def[0], array_fill_keys( $def[1], true ) );
				continue;
			}
			foreach ( $def[1] as $cap ) {
				$role->add_cap( $cap );
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::admin_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		// Option : les enseignants peuvent modifier les données de base.
		$teacher = get_role( 'logierp_teacher' );
		if ( $teacher ) {
			if ( get_option( 'logierp_teachers_edit_master', 0 ) ) {
				$teacher->add_cap( self::MASTER_DATA );
			} else {
				$teacher->remove_cap( self::MASTER_DATA );
			}
		}
		update_option( 'logierp_caps_version', self::VERSION, false );
	}

	public static function maybe_sync(): void {
		if ( get_option( 'logierp_caps_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function uninstall(): void {
		foreach ( array_keys( self::roles() ) as $slug ) {
			$users = get_users( array( 'role' => $slug, 'fields' => 'ID' ) );
			foreach ( $users as $uid ) {
				$user = get_userdata( (int) $uid );
				if ( ! $user ) {
					continue;
				}
				$user->remove_role( $slug );
				if ( empty( $user->roles ) ) {
					$user->add_role( 'subscriber' );
				}
			}
			remove_role( $slug );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::admin_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	public static function is_teacher( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, self::SCENARIOS );
	}
}
