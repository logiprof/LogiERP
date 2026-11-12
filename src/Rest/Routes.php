<?php
declare(strict_types=1);

namespace LogiERP\Rest;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Api;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Counting;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\Events;
use LogiERP\Domain\Packaging;
use LogiERP\Domain\Picking;
use LogiERP\Domain\Planning;
use LogiERP\Domain\Production;
use LogiERP\Domain\Quality;
use LogiERP\Domain\Transport;
use LogiERP\Domain\Purchasing;
use LogiERP\Domain\Receiving;
use LogiERP\Domain\Returns;
use LogiERP\Domain\Sales;
use LogiERP\Domain\SessionService;
use LogiERP\Domain\StockOps;
use LogiERP\Domain\Warehouse;
use LogiERP\Security\RateLimiter;
use LogiERP\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API REST logierp/v1. Authentification par cookie WordPress + nonce wp_rest (en-tête X-WP-Nonce).
 * Chaque route vérifie la capacité ; chaque accès à une session vérifie la propriété (anti-IDOR).
 */
final class Routes {

	public const NS = 'logierp/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/* ─────────────── Permissions ─────────────── */

	public static function can_play(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::PLAY );
	}

	public static function can_teach(): bool {
		return is_user_logged_in() && ( current_user_can( Capabilities::SCENARIOS ) || current_user_can( Capabilities::VIEW_CLASS ) );
	}

	public static function can_admin(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::SETTINGS );
	}

	/* ─────────────── Utilitaires ─────────────── */

	public static function ok( $data = null, string $message = '', array $extra = array() ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data, 'message' => $message ) + $extra, 200 );
	}

	public static function fail( string $message, int $status = 422, array $details = array() ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message, 'errors' => array_values( $details ) ), $status );
	}

	public static function str( WP_REST_Request $r, string $key, int $max = 190 ): string {
		$v = $r->get_param( $key );
		return is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, $max ) : '';
	}

	public static function code( WP_REST_Request $r, string $key ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', self::str( $r, $key, 40 ) ) );
	}

	public static function num( WP_REST_Request $r, string $key ): float {
		$v = $r->get_param( $key );
		return is_numeric( $v ) ? round( (float) $v, 3 ) : 0.0;
	}

	/**
	 * Nettoie un tableau de lignes selon un schéma : champ => 'int' | 'float' | 'str' | 'code' | 'date'.
	 */
	public static function lines( WP_REST_Request $r, string $key, array $schema, int $max = 200 ): array {
		$raw = $r->get_param( $key );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $raw, 0, $max ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$clean = array();
			foreach ( $schema as $field => $type ) {
				$v = $line[ $field ] ?? null;
				switch ( $type ) {
					case 'int':
						$clean[ $field ] = is_numeric( $v ) ? (int) $v : 0;
						break;
					case 'float':
						$clean[ $field ] = is_numeric( $v ) ? round( (float) $v, 3 ) : ( '' === $v || null === $v ? '' : 0.0 );
						break;
					case 'code':
						$clean[ $field ] = is_scalar( $v ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $v ) ) : '';
						$clean[ $field ] = mb_substr( $clean[ $field ], 0, 40 );
						break;
					case 'date':
						$clean[ $field ] = is_scalar( $v ) && Api::valid_date( (string) $v ) ? (string) $v : '';
						break;
					default:
						$clean[ $field ] = is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, 190 ) : '';
				}
			}
			$out[] = $clean;
		}
		return $out;
	}

	/** Charge le contexte d'une session (lecture : propriétaire ou enseignant ; écriture : propriétaire). */
	public static function ctx( WP_REST_Request $r, bool $write ): Ctx {
		$sid = (int) $r['sid'];
		if ( $write ) {
			self::lock( $sid );
		}
		$ctx = SessionService::context_for( $sid, get_current_user_id(), $write );
		if ( ! $ctx ) {
			throw new DomainError( 'Session introuvable.' );
		}
		if ( $write && ! $ctx->writable() ) {
			throw new DomainError( ! empty( $ctx->session['paused'] ) ? 'Votre enseignant a mis la session en pause.' : 'Cette session est terminée : elle est en lecture seule.' );
		}
		return $ctx;
	}

	/** Verrous de session pris par la requête courante. */
	private static array $locks = array();

	/**
	 * Une seule écriture à la fois par session : indispensable quand plusieurs membres d'un groupe
	 * travaillent dans la même mission (pas de stock prélevé deux fois, numéros de documents cohérents).
	 */
	private static function lock( int $sid ): void {
		global $wpdb;
		if ( isset( self::$locks[ $sid ] ) ) {
			return;
		}
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'logierp_s_' . $sid ) ) ) {
			throw new DomainError( 'Un membre de votre groupe enregistre une action dans la mission. Réessayez dans un instant.' );
		}
		self::$locks[ $sid ] = true;
	}

	private static function unlock(): void {
		global $wpdb;
		foreach ( array_keys( self::$locks ) as $sid ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'logierp_s_' . $sid ) );
		}
		self::$locks = array();
	}

	/**
	 * Exécute une transaction de l'apprenant : contexte, limite de débit, journalisation, erreurs.
	 */
	public static function run( WP_REST_Request $r, string $tcode, string $action, bool $write, callable $fn ): WP_REST_Response {
		$ctx = null;
		try {
			$ctx = self::ctx( $r, $write );
			if ( $write && ! RateLimiter::hit( 'write' ) ) {
				return self::fail( 'Trop d\'actions en peu de temps. Patientez une minute.', 429 );
			}
			$res = $fn( $ctx );
			if ( $write ) {
				$doc = is_array( $res ) ? (string) ( $res['doc_no'] ?? $res['no'] ?? '' ) : '';
				SessionService::log_action( $ctx, $tcode, $action, self::loggable( $r ), true, is_array( $res ) ? (string) ( $res['message'] ?? 'OK' ) : 'OK', $doc );
			}
			$msg = is_array( $res ) ? (string) ( $res['message'] ?? '' ) : '';
			return self::ok( $res, $msg );
		} catch ( DomainError $e ) {
			if ( $write && $ctx && $ctx->writable() ) {
				SessionService::log_action( $ctx, $tcode, $action, self::loggable( $r ), false, $e->getMessage() );
			}
			return self::fail( $e->getMessage(), 422, $e->details() );
		} catch ( \Throwable $e ) {
			Logger::error( 'REST ' . $tcode . ' : ' . $e->getMessage(), array( 'file' => basename( $e->getFile() ), 'line' => $e->getLine() ) );
			return self::fail( 'Une erreur technique est survenue. L\'action n\'a pas été enregistrée.', 500 );
		} finally {
			self::unlock();
		}
	}

	private static function loggable( WP_REST_Request $r ): array {
		$p = $r->get_json_params() ?: $r->get_body_params();
		return is_array( $p ) ? $p : array();
	}

	/* ─────────────── Routes ─────────────── */

	public function routes(): void {
		$play  = array( self::class, 'can_play' );
		$teach = array( self::class, 'can_teach' );
		$admin = array( self::class, 'can_admin' );
		$sid   = '/s/(?P<sid>\d+)';
		$no    = '(?P<no>[A-Za-z0-9\-]{1,20})';

		$get  = static fn( $path, $cb, $perm ) => register_rest_route( self::NS, $path, array( 'methods' => 'GET', 'callback' => $cb, 'permission_callback' => $perm ) );
		$post = static fn( $path, $cb, $perm ) => register_rest_route( self::NS, $path, array( 'methods' => 'POST', 'callback' => $cb, 'permission_callback' => $perm ) );

		$get( '/me', array( $this, 'me' ), $play );
		$post( '/sessions', array( $this, 'start' ), $play );
		$post( '/join', array( $this, 'join' ), 'is_user_logged_in' );
		$get( $sid, array( $this, 'session' ), $play );
		$post( $sid . '/submit', array( $this, 'submit' ), $play );
		$get( $sid . '/messages', array( $this, 'messages' ), $play );
		$get( $sid . '/journal', array( $this, 'journal' ), $play );
		$post( $sid . '/company-read', array( $this, 'company_read' ), $play );

		$get( $sid . '/materials', array( $this, 'materials' ), $play );
		$get( $sid . '/materials/(?P<mno>[A-Za-z0-9]{1,20})', array( $this, 'material' ), $play );
		$get( $sid . '/partners', array( $this, 'partners' ), $play );
		$get( $sid . '/partners/(?P<type>supplier|customer)/(?P<id>\d+)', array( $this, 'partner' ), $play );
		$get( $sid . '/stock', array( $this, 'stock' ), $play );
		$get( $sid . '/bins', array( $this, 'bins' ), $play );
		$get( $sid . '/map', array( $this, 'map' ), $play );
		$get( $sid . '/docs/(?P<type>po|so|dlv|prod|inv|ret)', array( $this, 'docs' ), $play );
		$get( $sid . '/mdocs', array( $this, 'mdocs' ), $play );
		$get( $sid . '/reorder', array( $this, 'reorder' ), $play );
		$get( $sid . '/carriers', array( $this, 'carriers' ), $play );

		$get( $sid . '/scan', array( $this, 'scan' ), $play );
		$get( $sid . '/photos', array( $this, 'photos' ), $play );
		$post( $sid . '/photos', array( $this, 'photo_upload' ), $play );
		$get( $sid . '/photos/(?P<pid>\d+)', array( $this, 'photo_file' ), array( self::class, 'can_view_photo' ) );
		$post( $sid . '/photos/(?P<pid>\d+)/delete', array( $this, 'photo_delete' ), $play );
		$get( $sid . '/po/' . $no, array( $this, 'po' ), $play );
		$get( $sid . '/cmr', array( $this, 'cmr_list' ), $play );
		$get( $sid . '/cmr/' . $no, array( $this, 'cmr' ), $play );
		$post( $sid . '/cmr/' . $no . '/sign', array( $this, 'cmr_sign' ), $play );
		$post( $sid . '/po', array( $this, 'po_create' ), $play );
		$post( $sid . '/gr', array( $this, 'gr' ), $play );
		$post( $sid . '/return-supplier', array( $this, 'return_supplier' ), $play );

		$get( $sid . '/to', array( $this, 'to_list' ), $play );
		$get( $sid . '/to/' . $no, array( $this, 'to' ), $play );
		$post( $sid . '/to/' . $no . '/confirm', array( $this, 'to_confirm' ), $play );
		$post( $sid . '/to', array( $this, 'to_move' ), $play );
		$get( $sid . '/replenishment', array( $this, 'repl_list' ), $play );
		$post( $sid . '/replenishment', array( $this, 'repl_create' ), $play );

		$post( $sid . '/stock/transfer', array( $this, 'stock_transfer' ), $play );
		$post( $sid . '/stock/status', array( $this, 'stock_status' ), $play );
		$post( $sid . '/stock/issue', array( $this, 'stock_issue' ), $play );

		$get( $sid . '/so/' . $no, array( $this, 'so' ), $play );
		$post( $sid . '/so', array( $this, 'so_create' ), $play );
		$post( $sid . '/so/' . $no . '/delivery', array( $this, 'so_delivery' ), $play );
		$get( $sid . '/vl10', array( $this, 'vl10_list' ), $play );
		$post( $sid . '/vl10', array( $this, 'vl10_create' ), $play );
		$get( $sid . '/hu/' . $no, array( $this, 'hu' ), $play );
		$post( $sid . '/hu/' . $no . '/(?P<act>create|add|remove|close|reopen|delete)', array( $this, 'hu_action' ), $play );
		$get( $sid . '/dlv/' . $no, array( $this, 'dlv' ), $play );
		$post( $sid . '/dlv/' . $no . '/pack', array( $this, 'dlv_pack' ), $play );
		$post( $sid . '/dlv/' . $no . '/issue', array( $this, 'dlv_issue' ), $play );

		$get( $sid . '/prod/' . $no, array( $this, 'prod' ), $play );
		$post( $sid . '/prod', array( $this, 'prod_create' ), $play );
		$post( $sid . '/prod/' . $no . '/(?P<act>release|issue|confirm|stage)', array( $this, 'prod_action' ), $play );
		$get( $sid . '/prod-check', array( $this, 'prod_check' ), $play );
		$get( $sid . '/md04/(?P<mno>[A-Za-z0-9]{1,20})', array( $this, 'md04' ), $play );
		$get( $sid . '/mrp', array( $this, 'mrp' ), $play );

		$get( $sid . '/qa', array( $this, 'qa_list' ), $play );
		$get( $sid . '/qa/' . $no, array( $this, 'qa' ), $play );
		$post( $sid . '/qa/' . $no . '/sample', array( $this, 'qa_sample' ), $play );
		$post( $sid . '/qa/' . $no . '/decide', array( $this, 'qa_decide' ), $play );
		$get( $sid . '/shelf-life', array( $this, 'shelf_life' ), $play );
		$post( $sid . '/stock/quarantine', array( $this, 'quarantine' ), $play );

		$get( $sid . '/vehicles', array( $this, 'vehicles' ), $play );
		$get( $sid . '/tours', array( $this, 'tours' ), $play );
		$get( $sid . '/tour-ready', array( $this, 'tour_ready' ), $play );
		$post( $sid . '/tour-plan', array( $this, 'tour_plan' ), $play );
		$post( $sid . '/tours', array( $this, 'tour_create' ), $play );
		$get( $sid . '/tours/' . $no, array( $this, 'tour' ), $play );
		$post( $sid . '/tours/' . $no . '/(?P<act>depart|close)', array( $this, 'tour_action' ), $play );
		$get( $sid . '/packaging', array( $this, 'packaging' ), $play );
		$post( $sid . '/packaging/return', array( $this, 'packaging_return' ), $play );

		$post( $sid . '/inv', array( $this, 'inv_create' ), $play );
		$get( $sid . '/inv/' . $no, array( $this, 'inv' ), $play );
		$post( $sid . '/inv/' . $no . '/look', array( $this, 'inv_look' ), $play );
		$post( $sid . '/inv/' . $no . '/count', array( $this, 'inv_count' ), $play );
		$post( $sid . '/inv/' . $no . '/post', array( $this, 'inv_post' ), $play );

		$post( $sid . '/ret', array( $this, 'ret_create' ), $play );
		$get( $sid . '/ret/' . $no, array( $this, 'ret' ), $play );
		$post( $sid . '/ret/' . $no . '/decide', array( $this, 'ret_decide' ), $play );

		// Enseignant.
		$get( '/teacher/assignments/(?P<id>\d+)/monitor', array( $this, 'monitor' ), $teach );
		$post( '/teacher/sessions/(?P<id>\d+)/event', array( $this, 'teacher_event' ), $teach );
		$post( '/teacher/sessions/(?P<id>\d+)/message', array( $this, 'teacher_message' ), $teach );
		$post( '/teacher/sessions/(?P<id>\d+)/pause', array( $this, 'teacher_pause' ), $teach );

		// Administration : génération du jeu de base.
		$get( '/admin/seed', array( $this, 'seed_state' ), $admin );
		$post( '/admin/seed', array( $this, 'seed_next' ), $admin );
	}

	/* ─────────────── Sessions ─────────────── */

	public function me( WP_REST_Request $r ): WP_REST_Response {
		$u    = wp_get_current_user();
		$list = array();
		foreach ( SessionService::assignments_for( (int) $u->ID ) as $a ) {
			$list[] = array(
				'id'         => (int) $a['id'],
				'title'      => $a['title'],
				'class'      => $a['class_name'],
				'level'      => $a['level'],
				'duration'   => (int) $a['duration_min'],
				'objectives' => $a['objectives'],
				'opens_at'   => $a['opens_at'],
				'closes_at'  => $a['closes_at'],
				'attempts'   => (int) $a['max_attempts'],
				'doc_url'    => \LogiERP\Front\ActivityDoc::assignment_url( (int) $a['id'] ),
				'group'      => 'GROUP' === $a['work_mode'],
				'team'       => $a['team'],
				'need_code'  => $a['member'] && (int) $a['access_code'] && null === $a['member']['code_used_at'],
			);
		}
		$sessions = array();
		$locked   = array();
		foreach ( $list as $a ) {
			if ( $a['need_code'] ) {
				$locked[ $a['id'] ] = true; // Session du groupe visible seulement après saisie du code personnel.
			}
		}
		foreach ( SessionService::sessions_of( (int) $u->ID ) as $s ) {
			if ( (int) $s['team_id'] && isset( $locked[ (int) $s['assignment_id'] ] ) ) {
				continue;
			}
			$published  = ( (int) $s['published'] && (int) $s['show_results'] ) || (int) $s['is_test'];
			$sessions[] = array(
				'id'           => (int) $s['id'],
				'assignment'   => (int) $s['assignment_id'],
				'title'        => $s['title'],
				'team'         => (int) $s['team_id'] ? \LogiERP\Domain\Teams::label( $s, false ) : '',
				'status'       => $s['status'],
				'test'         => (int) $s['is_test'],
				'grade'        => $published && null !== $s['grade'] ? (float) $s['grade'] : null,
				'score'        => $published && null !== $s['score'] ? (float) $s['score'] : null,
				'max'          => $published && null !== $s['max_score'] ? (float) $s['max_score'] : null,
				'started_at'   => $s['started_at'],
				'submitted_at' => $s['submitted_at'],
				'kpi_url'      => 'EN_COURS' !== $s['status'] || (int) $s['is_test'] ? \LogiERP\Front\MissionDashboard::session_url( (int) $s['id'] ) : '',
			);
		}
		return self::ok(
			array(
				'user'        => array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'first_name' => $u->first_name ?: $u->display_name ),
				'teacher'     => Capabilities::is_teacher(),
				'assignments' => $list,
				'sessions'    => $sessions,
				'ready'       => 'done' === get_option( 'logierp_seed_state' ),
			)
		);
	}

	/** Rejoindre une classe avec un code d'invitation (limité à 10 essais par heure). */
	public function join( WP_REST_Request $r ): WP_REST_Response {
		if ( ! \LogiERP\Security\RateLimiter::hit( 'join' ) ) {
			return self::fail( 'Trop d\'essais. Réessayez dans une heure.', 429 );
		}
		[ $ok, $msg ] = \LogiERP\Admin\ClassesPage::join_by_code( get_current_user_id(), self::str( $r, 'code', 20 ) );
		return $ok ? self::ok( null, $msg ) : self::fail( $msg );
	}

	public function start( WP_REST_Request $r ): WP_REST_Response {
		try {
			$aid  = (int) $r->get_param( 'assignment_id' );
			$sc   = (int) $r->get_param( 'scenario_id' );
			$code = self::str( $r, 'code', 20 );
			if ( ! $aid && ! $sc && '' !== $code ) {
				// Champ « Code de mission » du portail : le code désigne la mission, et seulement pour son titulaire.
				$aid = \LogiERP\Domain\Teams::assignment_for_code( get_current_user_id(), $code );
			}
			if ( ! $aid && ! $sc ) {
				return self::fail( 'Choisissez un scénario.' );
			}
			$sid = SessionService::start( get_current_user_id(), $aid, $aid ? 0 : $sc, $code );
			return self::ok( array( 'session_id' => $sid ) );
		} catch ( DomainError $e ) {
			return self::fail( $e->getMessage() );
		} catch ( \Throwable $e ) {
			Logger::error( 'Démarrage de session : ' . $e->getMessage() );
			return self::fail( 'Impossible de démarrer la session.', 500 );
		}
	}

	public function session( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) {
				global $wpdb;
				$tt    = Db::t( 'scenario_task' );
				$tasks = $wpdb->get_results( $wpdb->prepare( "SELECT seq, title, body, transaction_code FROM {$tt} WHERE scenario_id = %d ORDER BY seq", (int) $ctx->scenario['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$al    = Db::t( 'action_log' );
				$done  = array_flip( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT transaction_code FROM {$al} WHERE session_id = %d AND result = 'ok'", $ctx->id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $tasks as &$t ) {
					$t['done'] = ! empty( $ctx->scenario['guided'] ) && '' !== $t['transaction_code'] && isset( $done[ $t['transaction_code'] ] ) ? 1 : 0;
				}
				$report = null;
				$s      = $ctx->session;
				$owner  = \LogiERP\Domain\Teams::is_participant( $s, get_current_user_id() );
				[ , $show ] = SessionService::assignment_rules( $s );
				if ( 'CORRIGEE' === $s['status'] && ( ( (int) $s['published'] && $show ) || (int) $s['is_test'] || ! $owner ) ) {
					$report = json_decode( (string) $s['report'], true );
				}
				$refs = array();
				foreach ( (array) ( $ctx->doc_map()['refs'] ?? array() ) as $ref => $d ) {
					$refs[] = array( 'ref' => $ref, 'type' => $d['type'], 'no' => $d['no'] );
				}
				return array(
					'id'           => $ctx->id,
					'title'        => $ctx->scenario['title'],
					'objectives'   => (string) $ctx->scenario['objectives'],
					'instructions' => (string) $ctx->scenario['instructions'],
					'level'        => $ctx->scenario['level'],
					'modules'      => $ctx->modules(),
					'mode'         => $ctx->session['location_mode'],
					'guided'       => (int) $ctx->scenario['guided'],
					'hv_key'       => (int) $ctx->scenario['hv_key'],
					'check_digits' => (int) ( $ctx->scenario['check_digits'] ?? 0 ),
					'sim_date'     => $ctx->date(),
					'status'       => $s['status'],
					'paused'       => (int) $s['paused'],
					'test'         => (int) $s['is_test'],
					'teacher_message' => (string) $s['teacher_message'],
					'team'         => (int) $s['team_id'] ? \LogiERP\Domain\Teams::label( $s ) : '',
					'started_at'   => $s['started_at'],
					'deadline'     => SessionService::deadline( $s ),
					'now'          => Db::now(),
					'tasks'        => (array) $tasks,
					'documents'    => $refs,
					'grade'        => null !== $report ? (float) $s['grade'] : null,
					'score'        => null !== $report ? (float) $s['score'] : null,
					'max'          => null !== $report ? (float) $s['max_score'] : null,
					'report'       => $report,
					'comment'      => null !== $report ? (string) $s['teacher_comment'] : '',
					'readonly'     => ! $owner || ! $ctx->writable(),
					'company_seen' => SessionService::company_seen( $ctx->id ) ? 1 : 0,
					'doc_url'      => \LogiERP\Front\ActivityDoc::session_url( $ctx->id ),
					'files_url'    => \LogiERP\Front\BusinessDocs::session_url( $ctx->id ),
					'kpi_url'      => ! $owner || 'EN_COURS' !== $s['status'] || (int) $s['is_test'] ? \LogiERP\Front\MissionDashboard::session_url( $ctx->id ) : '',
				);
			}
		);
	}

	/** Présentation de l'entreprise lue : tracée une fois par session (information, sans effet sur la note). */
	public function company_read( WP_REST_Request $r ): WP_REST_Response {
		try {
			$ctx = self::ctx( $r, true );
			if ( ! SessionService::company_seen( $ctx->id ) ) {
				SessionService::log_info( $ctx, 'ZENTR', 'company_read', sprintf( 'Présentation de %s lue.', \LogiERP\Domain\Company::name() ) );
			}
			return self::ok( null );
		} catch ( DomainError $e ) {
			return self::fail( $e->getMessage(), 422 );
		} catch ( \Throwable $e ) {
			Logger::error( 'REST company_read : ' . $e->getMessage() );
			return self::fail( 'Une erreur technique est survenue.', 500 );
		} finally {
			self::unlock();
		}
	}

	public function submit( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			$r,
			'SUBMIT',
			'submit',
			true,
			static function ( Ctx $ctx ) {
				SessionService::submit( $ctx );
				return array( 'message' => 'Session remise. Votre enseignant publiera le résultat.' );
			}
		);
	}

	public function messages( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => array( 'events' => \LogiERP\Domain\Teams::is_participant( $ctx->session, get_current_user_id() ) ? Events::unread( $ctx ) : array(), 'teacher_message' => (string) $ctx->session['teacher_message'], 'paused' => (int) $ctx->session['paused'], 'status' => $ctx->session['status'] ) );
	}

	public function journal( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) {
				global $wpdb;
				$t = Db::t( 'action_log' );
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, transaction_code, action, result, message, doc_no, created_at FROM {$t} WHERE session_id = %d ORDER BY id DESC LIMIT 300", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$team = (int) $ctx->session['team_id'];
				foreach ( $rows as &$row ) {
					// Session de groupe : qui a fait la saisie (prénom et initiale) ; jamais l'identifiant du compte.
					$row['by'] = $team && (int) $row['user_id'] ? \LogiERP\Domain\Teams::short_name( (int) $row['user_id'] ) : '';
					unset( $row['user_id'] );
				}
				return $rows;
			}
		);
	}

	/* ─────────────── Lectures ─────────────── */

	public function materials( WP_REST_Request $r ): WP_REST_Response {
		$q = self::str( $r, 'q', 60 );
		return self::run( $r, '', '', false, static fn() => Api::search_materials( $q ) );
	}

	public function material( WP_REST_Request $r ): WP_REST_Response {
		$no = preg_replace( '/[^0-9A-Za-z]/', '', (string) $r['mno'] );
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) use ( $no ) {
				$m = Catalog::material_by_no( $no );
				if ( ! $m ) {
					$list = Api::search_materials( $no, 1 );
					$m    = $list ? Catalog::material( $list[0]['id'] ) : null;
				}
				if ( ! $m ) {
					throw new DomainError( 'Article introuvable.' );
				}
				return Api::material_full( $ctx, $m );
			}
		);
	}

	public function partners( WP_REST_Request $r ): WP_REST_Response {
		$type = 'supplier' === $r->get_param( 'type' ) ? 'supplier' : 'customer';
		$q    = self::str( $r, 'q', 60 );
		return self::run( $r, '', '', false, static fn() => Api::search_partners( $type, $q ) );
	}

	public function partner( WP_REST_Request $r ): WP_REST_Response {
		$type = (string) $r['type'];
		$id   = (int) $r['id'];
		return self::run(
			$r,
			'',
			'',
			false,
			static function () use ( $type, $id ) {
				$p = Api::partner_full( $type, $id );
				if ( ! $p ) {
					throw new DomainError( 'Partenaire introuvable.' );
				}
				return $p;
			}
		);
	}

	public function stock( WP_REST_Request $r ): WP_REST_Response {
		$f = array(
			'material' => self::str( $r, 'material', 60 ),
			'location' => self::code( $r, 'location' ),
			'bin'      => self::code( $r, 'bin' ),
			'status'   => self::code( $r, 'status' ),
			'expiring' => (int) $r->get_param( 'expiring' ),
			'page'     => (int) $r->get_param( 'page' ),
		);
		$f['status'] = str_replace( '-', '_', $f['status'] );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::stock_list( $ctx, $f ) );
	}

	public function bins( WP_REST_Request $r ): WP_REST_Response {
		$loc    = self::code( $r, 'location' );
		$filter = self::str( $r, 'filter', 20 );
		$page   = (int) $r->get_param( 'page' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::bins( $ctx, $loc, $filter, $page ) );
	}

	public function map( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::map( $ctx ) );
	}

	public function docs( WP_REST_Request $r ): WP_REST_Response {
		$type   = (string) $r['type'];
		$status = 'open' === $r->get_param( 'status' ) ? 'open' : '';
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::documents( $ctx, $type, $status ) );
	}

	public function mdocs( WP_REST_Request $r ): WP_REST_Response {
		$f = array( 'material' => self::code( $r, 'material' ), 'movement' => self::code( $r, 'movement' ), 'batch' => self::code( $r, 'batch' ), 'from' => self::str( $r, 'from', 10 ), 'to' => self::str( $r, 'to', 10 ), 'page' => (int) $r->get_param( 'page' ) );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::mdocs( $ctx, $f ) );
	}

	public function reorder( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Api::reorder_list( $ctx ) );
	}

	public function carriers( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn() => Api::carriers() );
	}

	/* ─────────────── Achats et réception ─────────────── */

	public function po( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Purchasing::detail( $ctx, $no ) );
	}

	/** Identifie un code scanné (caméra du smartphone, douchette ou saisie). */
	public function scan( WP_REST_Request $r ): WP_REST_Response {
		$v   = $r->get_param( 'code' );
		$raw = is_scalar( $v ) ? (string) preg_replace( '/[^\x1d\x20-\x7e]/', '', (string) $v ) : '';
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => \LogiERP\Domain\Gs1::resolve( $ctx, $raw ) );
	}

	/* ─────────────── Photos de non-conformité ─────────────── */

	public static function can_view_photo(): bool {
		return self::can_play() || self::can_teach();
	}

	public function photos( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => \LogiERP\Domain\Photos::list_for( $ctx->id ) );
	}

	public function photo_upload( WP_REST_Request $r ): WP_REST_Response {
		$files = $r->get_file_params();
		$file  = isset( $files['photo'] ) && is_array( $files['photo'] ) && ! is_array( $files['photo']['tmp_name'] ?? null ) ? $files['photo'] : array();
		$ref   = self::code( $r, 'ref_no' );
		$line  = (int) $r->get_param( 'line_no' );
		$note  = self::str( $r, 'note', 190 );
		return self::run( $r, 'LM00', 'photo', true, static fn( Ctx $ctx ) => \LogiERP\Domain\Photos::store( $ctx, $file, $ref, $line, $note ) );
	}

	public function photo_delete( WP_REST_Request $r ): WP_REST_Response {
		$pid = (int) $r['pid'];
		return self::run( $r, 'LM00', 'photo_delete', true, static fn( Ctx $ctx ) => \LogiERP\Domain\Photos::delete( $ctx, $pid ) );
	}

	/** Image d'une photo : apprenant·e propriétaire ou enseignant·e de la classe (contrôle dans context_for). */
	public function photo_file( WP_REST_Request $r ) {
		$ctx = SessionService::context_for( (int) $r['sid'], get_current_user_id(), false );
		$row = $ctx ? \LogiERP\Domain\Photos::row( $ctx->id, (int) $r['pid'] ) : null;
		if ( ! $row ) {
			return self::fail( 'Photo introuvable.', 404 );
		}
		\LogiERP\Domain\Photos::send( $row );
	}

	public function cmr_list( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => \LogiERP\Domain\Cmr::open_list( $ctx ) );
	}

	public function cmr( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => \LogiERP\Domain\Cmr::build( $ctx, $no ) );
	}

	public function cmr_sign( WP_REST_Request $r ): WP_REST_Response {
		$no       = self::code( $r, 'no' );
		$reserves = self::str( $r, 'reserves', 255 );
		return self::run( $r, 'ZCMR', 'cmr_sign', true, static fn( Ctx $ctx ) => \LogiERP\Domain\Cmr::sign( $ctx, $no, $reserves ) );
	}

	public function po_create( WP_REST_Request $r ): WP_REST_Response {
		$supplier = (int) $r->get_param( 'supplier_id' );
		$lines    = self::lines( $r, 'lines', array( 'material_id' => 'int', 'qty' => 'float' ), Purchasing::MAX_LINES );
		$note     = self::str( $r, 'note', 255 );
		return self::run(
			$r,
			'ME21N',
			'create_po',
			true,
			static function ( Ctx $ctx ) use ( $supplier, $lines, $note ) {
				$no = Purchasing::create( $ctx, $supplier, $lines, $note );
				return array( 'no' => $no, 'message' => sprintf( 'Commande d\'achat %s créée et envoyée au fournisseur.', $no ) );
			}
		);
	}

	public function gr( WP_REST_Request $r ): WP_REST_Response {
		$po    = self::code( $r, 'po_no' );
		$lines = self::lines( $r, 'lines', array( 'item_id' => 'int', 'qty' => 'float', 'batch' => 'code', 'bbd' => 'date', 'status' => 'code', 'reason' => 'str', 'location_id' => 'int', 'cert' => 'code', 'mfg_date' => 'date', 'vendor_batch' => 'code', 'pack_count' => 'int', 'pack_qty' => 'float', 'tus' => 'code', 'final' => 'int' ) );
		$text  = self::str( $r, 'text', 255 );
		$over  = (bool) $r->get_param( 'accept_over' );
		$tr    = self::code( $r, 'transport' );
		foreach ( $lines as &$l ) {
			$l['status'] = str_replace( '-', '_', $l['status'] ?: 'LIBRE' );
		}
		return self::run(
			$r,
			'MIGO',
			'goods_receipt',
			true,
			static function ( Ctx $ctx ) use ( $po, $lines, $text, $over, $tr ) {
				$res            = Receiving::post( $ctx, $po, $lines, $text, $over, $tr );
				$res['message'] = sprintf( 'Document de mouvement %s enregistré (entrée 101).', $res['doc_no'] ) . ( $res['to_no'] ? sprintf( ' Ordre d\'entreposage %s créé.', $res['to_no'] ) : '' );
				return $res;
			}
		);
	}

	public function return_supplier( WP_REST_Request $r ): WP_REST_Response {
		$po     = self::code( $r, 'po_no' );
		$item   = (int) $r->get_param( 'item_id' );
		$stock  = (int) $r->get_param( 'stock_id' );
		$qty    = self::num( $r, 'qty' );
		$reason = self::str( $r, 'reason', 120 );
		return self::run(
			$r,
			'MIGO',
			'return_supplier',
			true,
			static function ( Ctx $ctx ) use ( $po, $item, $stock, $qty, $reason ) {
				$doc = Receiving::return_to_supplier( $ctx, $po, $item, $stock, $qty, $reason );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Retour fournisseur enregistré (122), document %s.', $doc ) );
			}
		);
	}

	/* ─────────────── Ordres de transfert ─────────────── */

	public function to_list( WP_REST_Request $r ): WP_REST_Response {
		$type = self::code( $r, 'type' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Warehouse::list_open( $ctx, $type ) );
	}

	public function to( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Warehouse::detail( $ctx, $no ) );
	}

	public function to_confirm( WP_REST_Request $r ): WP_REST_Response {
		$no    = self::code( $r, 'no' );
		$items = self::lines( $r, 'items', array( 'item_id' => 'int', 'bin_code' => 'code', 'qty' => 'float', 'scan' => 'code', 'batch' => 'code', 'check' => 'code', 'diff' => 'code' ) );
		return self::run(
			$r,
			'LT12',
			'confirm_to',
			true,
			static function ( Ctx $ctx ) use ( $no, $items ) {
				$res            = Warehouse::confirm( $ctx, $no, $items );
				$res['message'] = sprintf( 'Ordre de transfert %s confirmé.', $no ) . ( $res['messages'] ? ' ' . implode( ' ', $res['messages'] ) : '' );
				return $res;
			}
		);
	}

	public function to_move( WP_REST_Request $r ): WP_REST_Response {
		$stock = (int) $r->get_param( 'stock_id' );
		$qty   = self::num( $r, 'qty' );
		$dst   = self::code( $r, 'dst_bin' );
		return self::run(
			$r,
			'LT01',
			'create_to',
			true,
			static function ( Ctx $ctx ) use ( $stock, $qty, $dst ) {
				$no = Warehouse::create_move( $ctx, $stock, $qty, $dst );
				return array( 'no' => $no, 'message' => sprintf( 'Ordre de transfert %s créé : confirmez-le une fois le déplacement fait.', $no ) );
			}
		);
	}

	public function repl_list( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Warehouse::replenishment_needs( $ctx ) );
	}

	public function repl_create( WP_REST_Request $r ): WP_REST_Response {
		$bin = (int) $r->get_param( 'bin_id' );
		return self::run(
			$r,
			'LT01',
			'create_repl',
			true,
			static function ( Ctx $ctx ) use ( $bin ) {
				$no = Warehouse::create_replenishment( $ctx, $bin );
				return array( 'no' => $no, 'message' => sprintf( 'Ordre de réapprovisionnement %s créé.', $no ) );
			}
		);
	}

	/* ─────────────── Mouvements directs ─────────────── */

	public function stock_transfer( WP_REST_Request $r ): WP_REST_Response {
		$stock = (int) $r->get_param( 'stock_id' );
		$qty   = self::num( $r, 'qty' );
		$loc   = self::code( $r, 'location' );
		$bin   = self::code( $r, 'bin' );
		return self::run(
			$r,
			'MIGO',
			'transfer',
			true,
			static function ( Ctx $ctx ) use ( $stock, $qty, $loc, $bin ) {
				$doc = StockOps::transfer( $ctx, $stock, $qty, $loc, $bin );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Transfert enregistré, document %s.', $doc ) );
			}
		);
	}

	public function stock_status( WP_REST_Request $r ): WP_REST_Response {
		$stock  = (int) $r->get_param( 'stock_id' );
		$qty    = self::num( $r, 'qty' );
		$status = str_replace( '-', '_', self::code( $r, 'status' ) );
		$reason = self::str( $r, 'reason', 120 );
		return self::run(
			$r,
			'MIGO',
			'status_change',
			true,
			static function ( Ctx $ctx ) use ( $stock, $qty, $status, $reason ) {
				$doc = StockOps::change_status( $ctx, $stock, $qty, $status, $reason );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Changement de statut enregistré, document %s.', $doc ) );
			}
		);
	}

	public function stock_issue( WP_REST_Request $r ): WP_REST_Response {
		$stock  = (int) $r->get_param( 'stock_id' );
		$qty    = self::num( $r, 'qty' );
		$mvt    = self::code( $r, 'movement' );
		$reason = self::str( $r, 'reason', 120 );
		return self::run(
			$r,
			'MIGO',
			'issue_' . $mvt,
			true,
			static function ( Ctx $ctx ) use ( $stock, $qty, $mvt, $reason ) {
				$doc = StockOps::issue( $ctx, $stock, $qty, $mvt, $reason );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Sortie %s enregistrée, document %s.', $mvt, $doc ) );
			}
		);
	}

	/* ─────────────── Ventes et expédition ─────────────── */

	public function so( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Sales::detail( $ctx, $no ) );
	}

	public function so_create( WP_REST_Request $r ): WP_REST_Response {
		$cust  = (int) $r->get_param( 'customer_id' );
		$lines = self::lines( $r, 'lines', array( 'material_id' => 'int', 'qty' => 'float' ), Purchasing::MAX_LINES );
		$date  = self::str( $r, 'req_date', 10 );
		$prio  = self::code( $r, 'priority' );
		$note  = self::str( $r, 'note', 255 );
		return self::run(
			$r,
			'VA01',
			'create_so',
			true,
			static function ( Ctx $ctx ) use ( $cust, $lines, $date, $prio, $note ) {
				$res            = Sales::create( $ctx, $cust, $lines, $date, $prio, $note );
				$res['no']      = $res['so_no'];
				$missing        = array_filter( $res['lines'], static fn( $l ) => $l['missing'] > 0 );
				$res['message'] = sprintf( 'Commande client %s créée.', $res['so_no'] ) . ( $missing ? ' Attention : certaines lignes ne sont pas entièrement disponibles.' : ' Toutes les quantités sont réservées.' );
				return $res;
			}
		);
	}

	public function so_delivery( WP_REST_Request $r ): WP_REST_Response {
		$no     = self::code( $r, 'no' );
		$mode   = self::code( $r, 'mode' ) ?: 'SERIE';
		$picker = self::str( $r, 'picker', 40 );
		$reuse  = self::code( $r, 'reuse_no' );
		return self::run(
			$r,
			'VL01N',
			'create_delivery',
			true,
			static function ( Ctx $ctx ) use ( $no, $mode, $picker, $reuse ) {
				$res            = Sales::create_delivery( $ctx, $no, $mode, $picker, $reuse );
				$res['no']      = $res['dlv_no'];
				$res['message'] = sprintf( 'Livraison %s créée.', $res['dlv_no'] ) . ( $res['to_no'] ? sprintf( ' Ordre de prélèvement %s à confirmer.', $res['to_no'] ) : '' ) . ( $res['messages'] ? ' ' . implode( ' ', $res['messages'] ) : '' );
				return $res;
			}
		);
	}

	/* ─────────────── VL10, HU02 : préparation et colisage ─────────────── */

	public function vl10_list( WP_REST_Request $r ): WP_REST_Response {
		$until = self::str( $r, 'until', 10 );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Picking::due_list( $ctx, $until ) );
	}

	public function vl10_create( WP_REST_Request $r ): WP_REST_Response {
		$raw    = $r->get_param( 'orders' );
		$orders = is_array( $raw ) ? array_slice( array_values( array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) ) : '', $raw ) ) ), 0, 30 ) : array();
		$mode   = self::code( $r, 'mode' );
		$picker = self::str( $r, 'picker', 40 );
		$rraw   = $r->get_param( 'reuse' );
		$reuse  = array();
		if ( is_array( $rraw ) ) {
			foreach ( array_slice( $rraw, 0, 30, true ) as $k => $v ) {
				$k = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $k ) );
				$v = is_scalar( $v ) ? strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) ) : '';
				if ( '' !== $k && '' !== $v ) {
					$reuse[ $k ] = mb_substr( $v, 0, 20 );
				}
			}
		}
		return self::run(
			$r,
			'VL10',
			'create_deliveries',
			true,
			static function ( Ctx $ctx ) use ( $orders, $mode, $picker, $reuse ) {
				$res            = Picking::create_deliveries( $ctx, $orders, $mode, $picker, $reuse );
				$n              = count( $res['created'] );
				$res['message'] = sprintf( '%d livraison%s créée%s (%s).', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : '', 'PARALLELE' === $mode ? 'préparation en parallèle par secteur' : 'préparation en série' ) . ( $res['errors'] ? ' ' . count( $res['errors'] ) . ' commande(s) non traitée(s).' : '' );
				return $res;
			}
		);
	}

	public function hu( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Picking::detail( $ctx, $no ) );
	}

	public function hu_action( WP_REST_Request $r ): WP_REST_Response {
		$no  = self::code( $r, 'no' );
		$act = self::code( $r, 'act' );
		$act = strtolower( $act );
		$hu  = self::code( $r, 'hu' );
		$typ = self::code( $r, 'type' );
		$mat = self::code( $r, 'material' );
		$qty = self::num( $r, 'qty' );
		$iid = (int) $r->get_param( 'item_id' );
		return self::run(
			$r,
			'HU02',
			'hu_' . $act,
			true,
			static function ( Ctx $ctx ) use ( $no, $act, $hu, $typ, $mat, $qty, $iid ) {
				switch ( $act ) {
					case 'create':
						return Picking::create_unit( $ctx, $no, $typ );
					case 'add':
						return Picking::add( $ctx, $no, $hu, $mat, $qty );
					case 'remove':
						return Picking::remove( $ctx, $no, $hu, $iid );
					default:
						return Picking::set_status( $ctx, $no, $hu, $act );
				}
			}
		);
	}

	public function dlv( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Sales::delivery_detail( $ctx, $no ) );
	}

	public function dlv_pack( WP_REST_Request $r ): WP_REST_Response {
		$no       = self::code( $r, 'no' );
		$carrier  = self::code( $r, 'carrier' );
		$packages = (int) $r->get_param( 'packages' );
		$support  = self::code( $r, 'support' );
		$frames   = (int) $r->get_param( 'frames' );
		$lids     = (int) $r->get_param( 'lids' );
		return self::run(
			$r,
			'VL02N',
			'pack',
			true,
			static function ( Ctx $ctx ) use ( $no, $carrier, $packages, $support, $frames, $lids ) {
				$res            = Sales::pack( $ctx, $no, $carrier, $packages, $support, $frames, $lids );
				$res['message'] = '' !== $res['forced'] ? sprintf( 'Livraison emballée : %s kg, transport imposé par la planification (%s).', $res['weight'], 'TOUR' === $res['forced'] ? 'tournée' : $res['carrier'] ) : sprintf( 'Livraison emballée : %s kg, %s.', $res['weight'], 'TOUR' === $carrier ? 'à planifier dans une tournée (VT01N ou VT11)' : 'transporteur ' . $res['carrier'] );
				return $res;
			}
		);
	}

	public function dlv_issue( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run(
			$r,
			'VL02N',
			'goods_issue',
			true,
			static function ( Ctx $ctx ) use ( $no ) {
				$warn = \LogiERP\Domain\ShippingTx::delayed_warning( $ctx, $no );
				$doc  = Sales::goods_issue( $ctx, $no );
				return array( 'doc_no' => $doc, 'warning' => $warn, 'message' => sprintf( 'Sortie de marchandises 601 enregistrée, document %s. La marchandise a quitté l\'entrepôt.', $doc ) . ( '' !== $warn ? ' ' . $warn : '' ) );
			}
		);
	}

	/* ─────────────── Production ─────────────── */

	public function prod( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Production::detail( $ctx, $no ) );
	}

	public function prod_create( WP_REST_Request $r ): WP_REST_Response {
		$mat   = (int) $r->get_param( 'material_id' );
		$qty   = self::num( $r, 'qty' );
		$start = self::str( $r, 'start', 10 );
		return self::run(
			$r,
			'CO01',
			'create_prod',
			true,
			static function ( Ctx $ctx ) use ( $mat, $qty, $start ) {
				$no = Production::create( $ctx, $mat, $qty, '', $start );
				return array( 'no' => $no, 'message' => sprintf( 'Ordre de fabrication %s créé (début le %s).', $no, Api::fr_date( $start ?: $ctx->date() ) ) );
			}
		);
	}

	public function prod_action( WP_REST_Request $r ): WP_REST_Response {
		$no    = self::code( $r, 'no' );
		$act   = (string) $r['act'];
		$good  = self::num( $r, 'good' );
		$scrap = self::num( $r, 'scrap' );
		return self::run(
			$r,
			'confirm' === $act || 'issue' === $act ? 'CO11N' : ( 'stage' === $act ? 'LP10' : 'CO02' ),
			'prod_' . $act,
			true,
			static function ( Ctx $ctx ) use ( $no, $act, $good, $scrap ) {
				if ( 'release' === $act ) {
					Production::release( $ctx, $no );
					return array( 'message' => sprintf( 'Ordre %s lancé : composants réservés.', $no ) );
				}
				if ( 'stage' === $act ) {
					return Production::stage( $ctx, $no );
				}
				if ( 'issue' === $act ) {
					$doc = Production::issue_components( $ctx, $no );
					return array( 'doc_no' => $doc, 'message' => sprintf( 'Composants consommés (261), document %s.', $doc ) );
				}
				$res            = Production::confirm( $ctx, $no, $good, $scrap );
				$res['message'] = 'Production déclarée.' . ( $res['doc_no'] ? sprintf( ' Entrée 101, document %s.', $res['doc_no'] ) : '' ) . ( $res['to_no'] ? sprintf( ' Ordre d\'entreposage %s créé.', $res['to_no'] ) : '' );
				return $res;
			}
		);
	}

	/* ─────────────── Planification ─────────────── */

	public function prod_check( WP_REST_Request $r ): WP_REST_Response {
		$mat   = (int) $r->get_param( 'material_id' );
		$qty   = self::num( $r, 'qty' );
		$start = self::str( $r, 'start', 10 );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Planning::availability( $ctx, $mat, max( 0.001, $qty ), Api::valid_date( $start ) ? $start : $ctx->date() ) );
	}

	public function md04( WP_REST_Request $r ): WP_REST_Response {
		$mno = self::code( $r, 'mno' );
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) use ( $mno ) {
				$m = Catalog::material_by_no( $mno ) ?: \LogiERP\Domain\Gs1::material_by_gtin( $mno );
				if ( ! $m ) {
					throw new DomainError( sprintf( 'Article %s introuvable.', $mno ) );
				}
				return Planning::md04( $ctx, (int) $m['id'] );
			}
		);
	}

	public function mrp( WP_REST_Request $r ): WP_REST_Response {
		$mno = self::code( $r, 'material' );
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) use ( $mno ) {
				$m = '' !== $mno ? Catalog::material_by_no( $mno ) : null;
				if ( '' !== $mno && ! $m ) {
					throw new DomainError( sprintf( 'Article %s introuvable.', $mno ) );
				}
				return Planning::mrp( $ctx, $m ? (int) $m['id'] : 0 );
			}
		);
	}

	/* ─────────────── Qualité ─────────────── */

	public function qa_list( WP_REST_Request $r ): WP_REST_Response {
		$open = 'open' === $r->get_param( 'status' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Quality::list_for( $ctx, $open ) );
	}

	public function qa( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Quality::detail( $ctx, $no ) );
	}

	public function qa_sample( WP_REST_Request $r ): WP_REST_Response {
		$no  = self::code( $r, 'no' );
		$qty = self::num( $r, 'qty' );
		return self::run( $r, 'QA32', 'qa_sample', true, static fn( Ctx $ctx ) => Quality::sample( $ctx, $no, $qty ) );
	}

	public function qa_decide( WP_REST_Request $r ): WP_REST_Response {
		$no       = self::code( $r, 'no' );
		$result   = self::code( $r, 'result' );
		$rtext    = self::str( $r, 'result_text', 255 );
		$decision = self::code( $r, 'decision' );
		$text     = self::str( $r, 'text', 255 );
		$bbd      = self::str( $r, 'new_bbd', 10 );
		$dst      = self::code( $r, 'bin' );
		return self::run( $r, 'QA11', 'qa_decide', true, static fn( Ctx $ctx ) => Quality::decide( $ctx, $no, $result, $rtext, $decision, $text, $bbd, $dst ) );
	}

	public function shelf_life( WP_REST_Request $r ): WP_REST_Response {
		$days = (int) $r->get_param( 'days' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Quality::shelf_life( $ctx, $days ) );
	}

	public function quarantine( WP_REST_Request $r ): WP_REST_Response {
		$stock  = (int) $r->get_param( 'stock_id' );
		$qty    = self::num( $r, 'qty' );
		$bin    = self::code( $r, 'bin' );
		$reason = self::str( $r, 'reason', 120 );
		return self::run( $r, 'LT10', 'quarantine', true, static fn( Ctx $ctx ) => Quality::quarantine( $ctx, $stock, $qty, $bin, $reason ) );
	}

	/* ─────────────── Transport et emballages ─────────────── */

	public function vehicles( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn() => Transport::vehicles() );
	}

	public function tours( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Transport::list_for( $ctx ) );
	}

	public function tour_ready( WP_REST_Request $r ): WP_REST_Response {
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Transport::ready( $ctx ) );
	}

	private static function tour_params( WP_REST_Request $r ): array {
		$raw = $r->get_param( 'deliveries' );
		$nos = is_array( $raw ) ? array_slice( array_values( array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $v ) ) : '', $raw ) ) ), 0, 12 ) : array();
		return array( (int) $r->get_param( 'vehicle_id' ), self::str( $r, 'date', 10 ), self::str( $r, 'depart', 5 ), $nos );
	}

	public function tour_plan( WP_REST_Request $r ): WP_REST_Response {
		[ $v, $d, $t, $nos ] = self::tour_params( $r );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Transport::simulate( $ctx, $v, $d, $t, $nos ) );
	}

	public function tour_create( WP_REST_Request $r ): WP_REST_Response {
		[ $v, $d, $t, $nos ] = self::tour_params( $r );
		return self::run( $r, 'VT01N', 'tour_create', true, static fn( Ctx $ctx ) => Transport::create( $ctx, $v, $d, $t, $nos ) );
	}

	public function tour( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Transport::detail( $ctx, $no ) );
	}

	public function tour_action( WP_REST_Request $r ): WP_REST_Response {
		$no  = self::code( $r, 'no' );
		$act = (string) $r['act'];
		$ret = self::lines( $r, 'returns', array( 'stop_id' => 'int', 'material_id' => 'int', 'good' => 'float', 'damaged' => 'float' ), 120 );
		return self::run( $r, 'VT02N', 'tour_' . $act, true, static fn( Ctx $ctx ) => 'depart' === $act ? Transport::depart( $ctx, $no ) : Transport::close( $ctx, $no, $ret ) );
	}

	public function packaging( WP_REST_Request $r ): WP_REST_Response {
		$cust = self::code( $r, 'customer' );
		return self::run(
			$r,
			'',
			'',
			false,
			static function ( Ctx $ctx ) use ( $cust ) {
				$c = '' !== $cust ? Api::customer_by_no( $cust ) : null;
				if ( '' !== $cust && ! $c ) {
					throw new DomainError( sprintf( 'Client %s introuvable.', $cust ) );
				}
				return array( 'balances' => Packaging::balances( $ctx, $c ? (int) $c['id'] : 0 ), 'moves' => $c ? Packaging::moves( $ctx, (int) $c['id'] ) : array(), 'customer' => $c ? array( 'id' => (int) $c['id'], 'no' => $c['customer_no'], 'name' => $c['name'] ) : null );
			}
		);
	}

	public function packaging_return( WP_REST_Request $r ): WP_REST_Response {
		$cust = (int) $r->get_param( 'customer_id' );
		$mat  = (int) $r->get_param( 'material_id' );
		$good = self::num( $r, 'good' );
		$bad  = self::num( $r, 'damaged' );
		$text = self::str( $r, 'text', 120 );
		return self::run(
			$r,
			'MB58',
			'packaging_return',
			true,
			static function ( Ctx $ctx ) use ( $cust, $mat, $good, $bad, $text ) {
				if ( floor( $good ) !== $good || floor( $bad ) !== $bad || $good < 0 || $bad < 0 ) {
					throw new DomainError( 'Les quantités d\'emballages sont des nombres entiers positifs.' );
				}
				$doc = Packaging::receive_empties( $ctx, $cust, $mat, $good, $bad, 'MB58', '' !== $text ? $text : 'Retour d\'emballages au quai' );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Retour d\'emballages enregistré (622), document %s.', $doc ) );
			}
		);
	}

	/* ─────────────── Inventaire ─────────────── */

	public function inv_create( WP_REST_Request $r ): WP_REST_Response {
		$scope = self::str( $r, 'scope', 20 );
		$value = self::str( $r, 'value', 40 );
		return self::run(
			$r,
			'MI01',
			'create_inv',
			true,
			static function ( Ctx $ctx ) use ( $scope, $value ) {
				$no = Counting::create( $ctx, $scope, $value );
				return array( 'no' => $no, 'message' => sprintf( 'Document d\'inventaire %s créé : les emplacements sont gelés.', $no ) );
			}
		);
	}

	public function inv( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Counting::detail( $ctx, $no ) );
	}

	public function inv_look( WP_REST_Request $r ): WP_REST_Response {
		$no   = self::code( $r, 'no' );
		$item = (int) $r->get_param( 'item_id' );
		return self::run(
			$r,
			'MI04',
			'look',
			true,
			static function ( Ctx $ctx ) use ( $no, $item ) {
				return array( 'qty' => Counting::look( $ctx, $no, $item ), 'message' => '' );
			}
		);
	}

	public function inv_count( WP_REST_Request $r ): WP_REST_Response {
		$no     = self::code( $r, 'no' );
		$counts = self::lines( $r, 'counts', array( 'item_id' => 'int', 'qty' => 'float' ), Counting::MAX_LINES );
		return self::run(
			$r,
			'MI04',
			'count',
			true,
			static function ( Ctx $ctx ) use ( $no, $counts ) {
				$res            = Counting::count( $ctx, $no, $counts );
				$res['message'] = $res['recount'] ? sprintf( 'Comptage enregistré. %d ligne(s) à recompter (écart important).', $res['recount'] ) : 'Comptage enregistré.';
				return $res;
			}
		);
	}

	public function inv_post( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run(
			$r,
			'MI07',
			'post_inv',
			true,
			static function ( Ctx $ctx ) use ( $no ) {
				$doc = Counting::post( $ctx, $no );
				return array( 'doc_no' => $doc, 'message' => $doc ? sprintf( 'Écarts comptabilisés (701/702), document %s. Emplacements dégelés.', $doc ) : 'Aucun écart : inventaire clôturé, emplacements dégelés.' );
			}
		);
	}

	/* ─────────────── Retours ─────────────── */

	public function ret_create( WP_REST_Request $r ): WP_REST_Response {
		$so     = self::code( $r, 'so_no' );
		$mat    = (int) $r->get_param( 'material_id' );
		$qty    = self::num( $r, 'qty' );
		$reason = self::str( $r, 'reason', 120 );
		$batch  = self::code( $r, 'batch' );
		return self::run(
			$r,
			'ZRET',
			'customer_return',
			true,
			static function ( Ctx $ctx ) use ( $so, $mat, $qty, $reason, $batch ) {
				$no = Returns::customer_return( $ctx, $so, $mat, $qty, $reason, $batch );
				return array( 'no' => $no, 'message' => sprintf( 'Retour %s enregistré (651) : marchandise en quarantaine, magasin 800.', $no ) );
			}
		);
	}

	public function ret( WP_REST_Request $r ): WP_REST_Response {
		$no = self::code( $r, 'no' );
		return self::run( $r, '', '', false, static fn( Ctx $ctx ) => Returns::detail( $ctx, $no ) );
	}

	public function ret_decide( WP_REST_Request $r ): WP_REST_Response {
		$no       = self::code( $r, 'no' );
		$item     = (int) $r->get_param( 'item_id' );
		$decision = self::code( $r, 'decision' );
		$target   = self::code( $r, 'target' );
		return self::run(
			$r,
			'ZRET',
			'decide_return',
			true,
			static function ( Ctx $ctx ) use ( $no, $item, $decision, $target ) {
				$doc = Returns::decide( $ctx, $no, $item, $decision, $target );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Décision enregistrée, document %s.', $doc ) );
			}
		);
	}

	/* ─────────────── Enseignant : suivi en direct ─────────────── */

	private static function teacher_assignment( int $id ): ?array {
		global $wpdb;
		$a   = Db::t( 'assignment' );
		$c   = Db::t( 'class' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT a.*, c.teacher_id FROM {$a} a LEFT JOIN {$c} c ON c.id = a.class_id WHERE a.id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$me = get_current_user_id();
		if ( (int) $row['teacher_id'] !== $me && (int) $row['created_by'] !== $me && ! current_user_can( Capabilities::VIEW_ALL ) ) {
			return null;
		}
		return $row;
	}

	public function monitor( WP_REST_Request $r ): WP_REST_Response {
		global $wpdb;
		$a = self::teacher_assignment( (int) $r['id'] );
		if ( ! $a ) {
			return self::fail( 'Assignation introuvable.', 404 );
		}
		$cm    = Db::t( 'class_member' );
		$st    = Db::t( 'session' );
		$al    = Db::t( 'action_log' );
		$tt    = Db::t( 'scenario_task' );
		$ntask = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} WHERE scenario_id = %d", (int) $a['scenario_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = (array) $wpdb->get_col( $wpdb->prepare( "SELECT transaction_code FROM {$tt} WHERE scenario_id = %d AND transaction_code <> ''", (int) $a['scenario_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Lignes du suivi : une par groupe (travail de groupe), sinon une par apprenant de la liste ou de la classe.
		$as    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'assignment' ) . ' WHERE id = %d', (int) $a['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$units = array();
		if ( $as && \LogiERP\Domain\Teams::ROSTER_NONE !== (int) $as['roster'] ) {
			\LogiERP\Domain\Teams::sync( $as );
		}
		if ( $as && 'GROUP' === $as['work_mode'] ) {
			foreach ( \LogiERP\Domain\Teams::teams( (int) $a['id'] ) as $t ) {
				$names   = array_map( static fn( $m ) => \LogiERP\Domain\Teams::short_name( (int) $m['user_id'] ), $t['members'] );
				$units[] = array( $t['name'] . ( $names ? ' · ' . implode( ', ', $names ) : '' ), $wpdb->prepare( 'team_id = %d', $t['id'] ) );
			}
		} else {
			$users = $as && \LogiERP\Domain\Teams::ROSTER_NONE !== (int) $as['roster']
				? array_map( static fn( $m ) => (int) $m['user_id'], \LogiERP\Domain\Teams::roster( (int) $a['id'] ) )
				: $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$cm} WHERE class_id = %d", (int) $a['class_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $users as $uid ) {
				$u       = get_userdata( (int) $uid );
				$units[] = array( $u ? $u->display_name : '#' . $uid, $wpdb->prepare( 'user_id = %d AND team_id = 0', (int) $uid ) );
			}
		}
		$rows = array();
		foreach ( $units as [ $label, $who ] ) {
			$s   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$st} WHERE assignment_id = %d AND {$who} ORDER BY id DESC LIMIT 1", (int) $a['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = array( 'user' => $label, 'session' => null );
			if ( $s ) {
				$stats = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(result='ok') ok, SUM(result='error') err, MAX(created_at) last FROM {$al} WHERE session_id = %d", (int) $s['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$last  = $wpdb->get_row( $wpdb->prepare( "SELECT transaction_code, message, result FROM {$al} WHERE session_id = %d ORDER BY id DESC LIMIT 1", (int) $s['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$done  = $codes ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT transaction_code) FROM {$al} WHERE session_id = %d AND result = 'ok' AND transaction_code IN (" . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ')', array_merge( array( (int) $s['id'] ), $codes ) ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$idle  = $stats['last'] ? ( time() - strtotime( $stats['last'] . ' UTC' ) ) : null;
				$row['session'] = array(
					'id'       => (int) $s['id'],
					'status'   => $s['status'],
					'paused'   => (int) $s['paused'],
					'ok'       => (int) $stats['ok'],
					'errors'   => (int) $stats['err'],
					'tasks'    => min( $done, $ntask ) . ' / ' . $ntask,
					'last'     => $last ? $last['transaction_code'] . ' – ' . $last['message'] : '',
					'last_ok'  => $last ? 'ok' === $last['result'] : true,
					'idle_min' => null !== $idle ? (int) floor( $idle / 60 ) : null,
					'grade'    => null !== $s['grade'] ? (float) $s['grade'] : null,
				);
			}
			$rows[] = $row;
		}
		return self::ok( array( 'rows' => $rows, 'events' => array_map( static fn( $e ) => array( 'id' => (int) $e['id'], 'type' => $e['type'], 'label' => Events::TYPES[ $e['type'] ] ?? $e['type'], 'trigger' => $e['trigger_type'], 'message' => $e['message'] ), Events::events_of( (int) $a['scenario_id'] ) ) ) );
	}

	private static function teacher_session( int $sid ): ?Ctx {
		$s = SessionService::get( $sid );
		if ( ! $s || ! SessionService::teacher_can_view( get_current_user_id(), $s ) ) {
			return null;
		}
		$sc = SessionService::scenario( (int) $s['scenario_id'] );
		return $sc ? new Ctx( $s, $sc ) : null;
	}

	public function teacher_event( WP_REST_Request $r ): WP_REST_Response {
		$ctx = self::teacher_session( (int) $r['id'] );
		if ( ! $ctx || ! current_user_can( Capabilities::SCENARIOS ) ) {
			return self::fail( 'Session introuvable.', 404 );
		}
		$eid = (int) $r->get_param( 'event_id' );
		foreach ( Events::events_of( (int) $ctx->scenario['id'] ) as $e ) {
			if ( (int) $e['id'] === $eid ) {
				Events::fire( $ctx, $e );
				\LogiERP\Security\Audit::log( 'fire_event', 'session', $ctx->id, array( 'event' => $eid ) );
				return self::ok( null, 'Événement déclenché.' );
			}
		}
		return self::fail( 'Événement introuvable.' );
	}

	public function teacher_message( WP_REST_Request $r ): WP_REST_Response {
		$ctx = self::teacher_session( (int) $r['id'] );
		if ( ! $ctx || ! current_user_can( Capabilities::SCENARIOS ) ) {
			return self::fail( 'Session introuvable.', 404 );
		}
		$text = self::str( $r, 'text', 255 );
		Db::update( 'session', array( 'teacher_message' => $text ), array( 'id' => $ctx->id ) );
		return self::ok( null, 'Message envoyé.' );
	}

	public function teacher_pause( WP_REST_Request $r ): WP_REST_Response {
		$ctx = self::teacher_session( (int) $r['id'] );
		if ( ! $ctx || ! current_user_can( Capabilities::SCENARIOS ) ) {
			return self::fail( 'Session introuvable.', 404 );
		}
		Db::update( 'session', array( 'paused' => $r->get_param( 'paused' ) ? 1 : 0 ), array( 'id' => $ctx->id ) );
		return self::ok( null, $r->get_param( 'paused' ) ? 'Session en pause.' : 'Session reprise.' );
	}

	/* ─────────────── Administration ─────────────── */

	public function seed_state(): WP_REST_Response {
		return self::ok( \LogiERP\Seeder\JobRunner::state() );
	}

	public function seed_next(): WP_REST_Response {
		return self::ok( \LogiERP\Seeder\JobRunner::run_next() );
	}
}
