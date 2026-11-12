<?php
declare(strict_types=1);

namespace LogiERP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mises à jour automatiques depuis les « releases » GitHub du dépôt officiel.
 *
 * Principes de sécurité :
 *  - un seul dépôt autorisé, codé en dur (logiprof/LogiERP), interrogé uniquement en HTTPS sur api.github.com ;
 *  - le paquet doit s'appeler logierp-X.Y.Z.zip, correspondre au numéro de la release et être accompagné
 *    de son empreinte SHA-256 (logierp-X.Y.Z.zip.sha256) : un paquet altéré ou incomplet est refusé ;
 *  - l'archive doit contenir le dossier logierp/ avec logierp.php à la bonne version, sinon l'installation est annulée ;
 *  - le jeton d'accès éventuel (dépôt privé) est lu dans wp-config.php (constante LOGIERP_GITHUB_TOKEN),
 *    jamais enregistré en base, jamais affiché, et envoyé uniquement à api.github.com ;
 *  - l'en-tête « Update URI » empêche WordPress.org de proposer une autre extension du même nom.
 */
final class Updater {

	public const REPO      = 'logiprof/LogiERP';
	private const API      = 'https://api.github.com/repos/' . self::REPO;
	private const CACHE    = 'logierp_update_release';
	private const TTL_OK   = 6 * HOUR_IN_SECONDS;
	private const TTL_FAIL = 30 * MINUTE_IN_SECONDS;
	private const SLUG     = 'logierp';

	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'check_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( self::class, 'after_upgrade' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( LOGIERP_FILE ), array( self::class, 'action_link' ) );
		add_action( 'admin_post_logierp_check_update', array( self::class, 'handle_check' ) );
	}

	/* ───────── Interrogation de GitHub ───────── */

	/**
	 * Dernière release publiée, validée. Résultat mis en cache (6 h, ou 30 min après une erreur).
	 *
	 * @return array{version:string,tag:string,zip:int,sha:int,size:int,url:string,date:string,notes:string}|null
	 */
	public static function latest( bool $force = false ): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}
		$release = null;
		$error   = '';
		$res     = self::api_get( self::API . '/releases/latest' );
		if ( is_wp_error( $res ) ) {
			$error = $res->get_error_message();
		} else {
			$release = self::parse( $res );
			if ( null === $release ) {
				$error = 'La dernière release ne contient pas de paquet logierp-X.Y.Z.zip avec son empreinte .sha256.';
			}
		}
		set_site_transient(
			self::CACHE,
			array( 'release' => $release, 'error' => $error, 'checked' => time() ),
			null === $release ? self::TTL_FAIL : self::TTL_OK
		);
		return $release;
	}

	/** État de la dernière vérification (pour la page Réglages). */
	public static function status(): array {
		$c = get_site_transient( self::CACHE );
		return array(
			'release' => is_array( $c ) ? ( $c['release'] ?? null ) : null,
			'error'   => is_array( $c ) ? (string) ( $c['error'] ?? '' ) : '',
			'checked' => is_array( $c ) ? (int) ( $c['checked'] ?? 0 ) : 0,
			'token'   => '' !== self::token(),
		);
	}

	/** Valide la réponse de l'API et ne retient que ce qui est attendu. */
	private static function parse( array $r ): ?array {
		if ( ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {
			return null;
		}
		$tag = (string) ( $r['tag_name'] ?? '' );
		if ( ! preg_match( '/^v?(\d{1,3}\.\d{1,3}\.\d{1,3})$/', $tag, $m ) ) {
			return null;
		}
		$version = $m[1];
		$zip     = 'logierp-' . $version . '.zip';
		$out     = array( 'zip' => 0, 'sha' => 0, 'size' => 0 );
		foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
			$name = (string) ( $a['name'] ?? '' );
			$id   = (int) ( $a['id'] ?? 0 );
			if ( $id <= 0 || 'uploaded' !== ( $a['state'] ?? 'uploaded' ) ) {
				continue;
			}
			if ( $zip === $name ) {
				$out['zip']  = $id;
				$out['size'] = (int) ( $a['size'] ?? 0 );
			} elseif ( $zip . '.sha256' === $name ) {
				$out['sha'] = $id;
			}
		}
		if ( ! $out['zip'] || ! $out['sha'] || $out['size'] <= 0 || $out['size'] > 200 * MB_IN_BYTES ) {
			return null;
		}
		return array(
			'version' => $version,
			'tag'     => $tag,
			'zip'     => $out['zip'],
			'sha'     => $out['sha'],
			'size'    => $out['size'],
			'url'     => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $tag ),
			'date'    => substr( (string) ( $r['published_at'] ?? '' ), 0, 10 ),
			'notes'   => mb_substr( (string) ( $r['body'] ?? '' ), 0, 20000 ),
		);
	}

	/** Adresse « paquet » transmise à WordPress : l'API de l'asset, jamais une URL arbitraire. */
	private static function package_url( array $rel ): string {
		return self::API . '/releases/assets/' . $rel['zip'];
	}

	/* ───────── Intégration à WordPress ───────── */

	/** Filtre update_plugins_github.com (WordPress 5.8+, via l'en-tête Update URI). */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LOGIERP_FILE ) !== $plugin_file ) {
			return $update;
		}
		$rel = self::latest();
		if ( null === $rel ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $rel['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => version_compare( $rel['version'], LOGIERP_VERSION, '>' ) ? self::package_url( $rel ) : '',
			'requires'     => LOGIERP_MIN_WP,
			'requires_php' => LOGIERP_MIN_PHP,
			'icons'        => array( 'svg' => LOGIERP_URL . 'assets/img/icon.svg' ),
		);
	}

	/** Fenêtre « Voir les détails de la version ». */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$rel = self::latest();
		$v   = $rel['version'] ?? LOGIERP_VERSION;
		return (object) array(
			'name'          => 'LogiERP',
			'slug'          => self::SLUG,
			'version'       => $v,
			'author'        => 'LogiProf',
			'homepage'      => 'https://logiprof.ch',
			'requires'      => LOGIERP_MIN_WP,
			'requires_php'  => LOGIERP_MIN_PHP,
			'last_updated'  => $rel['date'] ?? '',
			'download_link' => $rel ? self::package_url( $rel ) : '',
			'sections'      => array(
				'description' => '<p>Simulateur ERP/WMS pédagogique pour la formation logistique.</p>',
				'changelog'   => $rel ? self::notes_html( $rel ) : '<p>Aucune information disponible.</p>',
			),
		);
	}

	/** Notes de version : texte brut échappé, listes à puces simples. */
	private static function notes_html( array $rel ): string {
		$html = '<h4>' . esc_html( $rel['version'] ) . ( $rel['date'] ? ' (' . esc_html( $rel['date'] ) . ')' : '' ) . '</h4>';
		$list = false;
		foreach ( preg_split( '/\R/', $rel['notes'] ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$list  = true;
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( '' !== $line ) {
				$html .= '<p>' . esc_html( ltrim( $line, '# ' ) ) . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * Téléchargement du paquet : asset GitHub uniquement, empreinte SHA-256 vérifiée avant toute installation.
	 *
	 * @return false|string|\WP_Error
	 */
	public static function download( $reply, $package, $upgrader ) {
		$prefix = self::API . '/releases/assets/';
		if ( false !== $reply || ! is_string( $package ) || ! str_starts_with( $package, $prefix ) ) {
			return $reply;
		}
		$id  = substr( $package, strlen( $prefix ) );
		$rel = self::latest();
		if ( ! ctype_digit( $id ) || null === $rel || (int) $id !== $rel['zip'] ) {
			return new \WP_Error( 'logierp_update', 'Paquet de mise à jour inconnu : téléchargement refusé.' );
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', 'GitHub ' . self::REPO );
		}
		$expected = self::fetch_checksum( $rel );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		$file = self::fetch_asset( $rel['zip'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$actual = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'logierp_update', 'Empreinte SHA-256 du paquet incorrecte : mise à jour annulée, rien n\'a été modifié.' );
		}
		return $file;
	}

	/** Après décompression : l'archive doit contenir logierp/logierp.php à la version annoncée. */
	public static function check_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( LOGIERP_FILE ) ) {
			return $source;
		}
		$dir  = untrailingslashit( (string) $source );
		$main = $dir . '/logierp.php';
		if ( self::SLUG !== basename( $dir ) || ! is_readable( $main ) ) {
			return self::reject( $remote_source, 'Archive inattendue (dossier logierp/ absent) : mise à jour annulée, rien n\'a été modifié.' );
		}
		$data = get_file_data( $main, array( 'Version' => 'Version' ) );
		$rel  = self::latest();
		if ( null === $rel || $data['Version'] !== $rel['version'] ) {
			return self::reject( $remote_source, 'La version contenue dans l\'archive ne correspond pas à la release : mise à jour annulée, rien n\'a été modifié.' );
		}
		return $source;
	}

	/** Refus : supprime la copie décompressée (dossier de travail de WordPress uniquement). */
	private static function reject( $remote_source, string $message ): \WP_Error {
		global $wp_filesystem;
		$work = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/' );
		$path = wp_normalize_path( (string) $remote_source );
		if ( is_object( $wp_filesystem ) && str_starts_with( $path, $work ) && strlen( $path ) > strlen( $work ) && ! str_contains( substr( $path, strlen( $work ) ), '..' ) ) {
			$wp_filesystem->delete( $path, true );
		}
		return new \WP_Error( 'logierp_update', $message );
	}

	public static function after_upgrade( $upgrader, $extra ): void {
		if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( plugin_basename( LOGIERP_FILE ), (array) ( $extra['plugins'] ?? array() ), true ) ) {
			delete_site_transient( self::CACHE );
			if ( class_exists( \LogiERP\Security\Audit::class ) ) {
				\LogiERP\Security\Audit::log( 'plugin_update' );
			}
		}
	}

	/* ───────── Lien « Rechercher les mises à jour » ───────── */

	public static function action_link( array $links ): array {
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=logierp_check_update' ), 'logierp_check_update' );
			$links[] = '<a href="' . esc_url( $url ) . '">Rechercher les mises à jour</a>';
		}
		return $links;
	}

	public static function handle_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'logierp_check_update' );
		$rel = self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$back = wp_get_referer();
		if ( $back && str_contains( $back, 'page=logierp-settings' ) ) {
			$st = self::status();
			if ( null === $rel ) {
				\LogiERP\Admin\Ui::redirect( 'logierp-settings', array(), 'error', 'Vérification impossible : ' . $st['error'] );
			} elseif ( version_compare( $rel['version'], LOGIERP_VERSION, '>' ) ) {
				\LogiERP\Admin\Ui::redirect( 'logierp-settings', array(), 'warning', 'La version ' . $rel['version'] . ' est disponible : installez-la depuis la page Extensions (ou Tableau de bord › Mises à jour).' );
			}
			\LogiERP\Admin\Ui::redirect( 'logierp-settings', array(), 'success', 'LogiERP est à jour (version ' . LOGIERP_VERSION . ').' );
		}
		$back = self_admin_url( 'plugins.php' );
		wp_safe_redirect( $back );
		exit;
	}

	/* ───────── HTTP ───────── */

	/** Jeton facultatif (dépôt privé), uniquement depuis wp-config.php. */
	private static function token(): string {
		$t = defined( 'LOGIERP_GITHUB_TOKEN' ) ? (string) constant( 'LOGIERP_GITHUB_TOKEN' ) : '';
		return preg_match( '/^[A-Za-z0-9_]{20,255}$/', $t ) ? $t : '';
	}

	private static function headers( string $accept ): array {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'LogiERP/' . LOGIERP_VERSION . ' (WordPress)',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/** @return array|\WP_Error */
	private static function api_get( string $url ) {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 2, 'headers' => self::headers( 'application/vnd.github+json' ) ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'logierp_update', 'GitHub injoignable : ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return new \WP_Error( 'logierp_update', '' === self::token()
				? 'Aucune release trouvée. Si le dépôt est privé, ajoutez LOGIERP_GITHUB_TOKEN dans wp-config.php.'
				: 'Aucune release trouvée, ou le jeton n\'a pas accès au dépôt.' );
		}
		if ( 401 === $code ) {
			return new \WP_Error( 'logierp_update', 'Jeton GitHub refusé (expiré ou révoqué).' );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'logierp_update', 'Réponse inattendue de GitHub (HTTP ' . $code . ').' );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : new \WP_Error( 'logierp_update', 'Réponse illisible de GitHub.' );
	}

	/**
	 * Adresse de téléchargement réelle d'un asset. L'API répond par une redirection vers une adresse signée
	 * et temporaire : on la suit sans renvoyer le jeton (il ne quitte jamais api.github.com).
	 *
	 * @return string|\WP_Error
	 */
	private static function asset_location( int $id ) {
		$res = wp_safe_remote_get( self::API . '/releases/assets/' . $id, array( 'timeout' => 15, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'logierp_update', 'Téléchargement impossible : ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) && str_starts_with( $loc, 'https://' ) ) {
			$host = (string) wp_parse_url( $loc, PHP_URL_HOST );
			if ( 'github.com' === $host || str_ends_with( $host, '.github.com' ) || str_ends_with( $host, '.githubusercontent.com' ) ) {
				return $loc;
			}
		}
		return new \WP_Error( 'logierp_update', 'Téléchargement refusé par GitHub (HTTP ' . $code . ').' );
	}

	/** @return string|\WP_Error Chemin du fichier temporaire. */
	private static function fetch_asset( int $id ) {
		$loc = self::asset_location( $id );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $loc, 300 );
		return is_wp_error( $file ) ? new \WP_Error( 'logierp_update', 'Téléchargement interrompu : ' . $file->get_error_message() ) : $file;
	}

	/** @return string|\WP_Error Empreinte SHA-256 attendue (64 caractères hexadécimaux). */
	private static function fetch_checksum( array $rel ) {
		$loc = self::asset_location( $rel['sha'] );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		$res = wp_safe_remote_get( $loc, array( 'timeout' => 15, 'limit_response_size' => 4096 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'logierp_update', 'Empreinte SHA-256 introuvable : mise à jour annulée.' );
		}
		if ( ! preg_match( '/^([a-f0-9]{64})\b/i', trim( (string) wp_remote_retrieve_body( $res ) ), $m ) ) {
			return new \WP_Error( 'logierp_update', 'Empreinte SHA-256 illisible : mise à jour annulée.' );
		}
		return strtolower( $m[1] );
	}
}
