<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manuels PDF consultables et téléchargeables depuis l'outil.
 *
 *  - « user »    : manuel d'utilisation (apprentis, et enseignants) ;
 *  - « teacher » : manuel de l'enseignant (enseignants seulement).
 *
 * Les PDF livrés avec l'extension (dossier manuals/) sont copiés dans un dossier privé des téléversements, au nom
 * aléatoire et protégé (.htaccess, index.php), puis retirés du dossier de l'extension quand c'est possible :
 * le manuel de l'enseignant n'a ainsi aucune adresse publique. Ils sont toujours servis par PHP, après contrôle des droits.
 * Un administrateur peut remplacer un manuel par une version plus récente (page Manuels).
 */
final class Manuals {

	public const QUERY  = 'logierp_manual';
	private const OPT   = 'logierp_manuals';
	public const MAX_MB = 120;

	public const LIST = array(
		'user'    => array(
			'file'  => 'manuel-utilisation.pdf',
			'name'  => 'LogiERP-Manuel-utilisation.pdf',
			'title' => 'Manuel d\'utilisation',
			'text'  => 'Toutes les transactions, étape par étape, avec captures d\'écran : réception, stock, qualité, préparation, expédition, transport, production, inventaire, retours, terminal mobile et smartphone.',
		),
		'teacher' => array(
			'file'  => 'manuel-enseignant.pdf',
			'name'  => 'LogiERP-Manuel-enseignant.pdf',
			'title' => 'Manuel de l\'enseignant',
			'text'  => 'Classes et groupes Sensei, conception des scénarios, documents d\'activité et documents logistiques, étiquettes et kit mini-entrepôt, suivi en direct et interventions, résultats et tableau d\'indicateurs.',
		),
	);

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
		// Dès le premier chargement après une installation ou une mise à jour : les PDF quittent le dossier public de l'extension.
		add_action( 'init', array( self::class, 'ensure' ) );
	}

	/* ───────── Droits ───────── */

	public static function can( string $key ): bool {
		if ( 'teacher' === $key ) {
			return current_user_can( Capabilities::VIEW_CLASS ) || current_user_can( Capabilities::SCENARIOS );
		}
		return 'user' === $key && ( current_user_can( Capabilities::PLAY ) || current_user_can( Capabilities::VIEW_CLASS ) || current_user_can( Capabilities::SCENARIOS ) );
	}

	/** Adresse d'ouverture (ou de téléchargement) ; la page se met après « #page= » côté navigateur. */
	public static function url( string $key, bool $download = false ): string {
		return add_query_arg( array_filter( array( self::QUERY => $key, 'dl' => $download ? '1' : '' ) ), home_url( '/' ) );
	}

	/* ───────── Stockage privé ───────── */

	private static function state(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Dossier privé (créé au besoin), avec protections contre l'accès direct. */
	public static function dir(): string {
		$s = self::state();
		if ( empty( $s['dir'] ) || ! preg_match( '/^logierp-manuels-[a-z0-9]{20}$/', (string) $s['dir'] ) ) {
			$s['dir'] = 'logierp-manuels-' . strtolower( wp_generate_password( 20, false, false ) );
			update_option( self::OPT, $s, false );
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . $s['dir'];
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichiers de protection.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/** Copie privée de la version livrée avec l'extension. */
	public static function path( string $key ): string {
		return isset( self::LIST[ $key ] ) ? self::dir() . '/' . self::LIST[ $key ]['file'] : '';
	}

	/** Version déposée par un administrateur (prioritaire, la version livrée reste disponible). */
	private static function custom_path( string $key ): string {
		return isset( self::LIST[ $key ] ) ? self::dir() . '/custom-' . self::LIST[ $key ]['file'] : '';
	}

	/**
	 * Installe les PDF livrés avec l'extension (nouvelle version), sauf ceux remplacés par un administrateur.
	 * Appelé à l'activation et dans l'administration ; ne fait rien quand tout est en place.
	 */
	public static function ensure(): void {
		$src = LOGIERP_DIR . 'manuals/';
		if ( ! is_file( $src . self::LIST['user']['file'] ) && ! is_file( $src . self::LIST['teacher']['file'] ) ) {
			return; // Déjà en lieu sûr : rien à faire (cas de toutes les requêtes ordinaires).
		}
		self::dir();
		$s = self::state();
		foreach ( self::LIST as $key => $m ) {
			$from = $src . $m['file'];
			if ( ! is_file( $from ) ) {
				continue;
			}
			$to = self::path( $key );
			if ( ! is_file( $to ) || ( $s['bundled'][ $key ]['size'] ?? 0 ) !== filesize( $from ) || ( $s['bundled'][ $key ]['version'] ?? '' ) !== LOGIERP_VERSION ) {
				if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- échec géré : le PDF reste servi depuis l'extension.
					continue;
				}
				$s['bundled'][ $key ] = array( 'size' => filesize( $from ), 'version' => LOGIERP_VERSION );
				update_option( self::OPT, $s, false );
			}
			// Le dossier de l'extension est public : on y retire la copie dès qu'elle est en lieu sûr.
			if ( is_file( $to ) && filesize( $to ) === filesize( $from ) ) {
				wp_delete_file( $from );
			}
		}
	}

	/** Fichier à servir : copie privée, ou PDF de l'extension si la copie n'a pas pu être faite. */
	public static function file( string $key ): string {
		self::ensure();
		$c = self::custom_path( $key );
		if ( $c && ! empty( self::state()[ $key ]['custom'] ) && is_file( $c ) ) {
			return $c;
		}
		$p = self::path( $key );
		if ( $p && is_file( $p ) ) {
			return $p;
		}
		$b = isset( self::LIST[ $key ] ) ? LOGIERP_DIR . 'manuals/' . self::LIST[ $key ]['file'] : '';
		return $b && is_file( $b ) ? $b : '';
	}

	/** Remplacement par un administrateur (PDF vérifié). */
	public static function replace( string $key, string $tmp ): void {
		$to = self::custom_path( $key );
		if ( ! $to || ! @move_uploaded_file( $tmp, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( 'Le fichier n\'a pas pu être enregistré.' );
		}
		$s         = self::state();
		$s[ $key ] = array( 'custom' => 1, 'updated' => gmdate( 'Y-m-d H:i:s' ) );
		update_option( self::OPT, $s, false );
	}

	/** Revient à la version livrée avec l'extension. */
	public static function restore( string $key ): void {
		$s = self::state();
		unset( $s[ $key ] );
		update_option( self::OPT, $s, false );
		$to = self::custom_path( $key );
		if ( is_file( $to ) ) {
			wp_delete_file( $to );
		}
	}

	public static function info( string $key ): array {
		$f = self::file( $key );
		$s = self::state()[ $key ] ?? array();
		return array( 'exists' => '' !== $f, 'size' => $f ? (int) filesize( $f ) : 0, 'custom' => ! empty( $s['custom'] ), 'updated' => (string) ( $s['updated'] ?? '' ), 'pages' => (int) ( self::map()[ $key ]['pages'] ?? 0 ) );
	}

	/** Pages des procédures (version livrée seulement : un PDF remplacé peut avoir une autre pagination). */
	public static function map(): array {
		static $m = null;
		if ( null === $m ) {
			$f = LOGIERP_DIR . 'manuals/manuals.json';
			$m = is_file( $f ) ? (array) json_decode( (string) file_get_contents( $f ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		}
		return $m;
	}

	/** Transaction → page du manuel d'utilisation où elle est expliquée. */
	public static function tx_pages(): array {
		if ( ! empty( self::state()['user']['custom'] ) ) {
			return array();
		}
		$procs = (array) ( self::map()['user']['procedures'] ?? array() );
		$out   = array();
		foreach ( ActivityDoc::HELP as $tx => $codes ) {
			$first = trim( explode( '·', $codes )[0] );
			if ( isset( $procs[ $first ] ) ) {
				$out[ $tx ] = (int) ( $procs[ $first ]['page'] ?? 0 );
			}
		}
		return $out;
	}

	/* ───────── Envoi ───────── */

	public function maybe_serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule, droits contrôlés.
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) );
		$dl  = ! empty( $_GET['dl'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( ! isset( self::LIST[ $key ] ) || ! self::can( $key ) ) {
			wp_die( esc_html__( 'Accès refusé à ce manuel.', 'logierp' ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 403 ) );
		}
		$f = self::file( $key );
		if ( '' === $f ) {
			wp_die( esc_html__( 'Ce manuel n\'est pas encore installé. Demandez à l\'administrateur de le déposer dans LogiERP › Manuels.', 'logierp' ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 404 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $f ) );
		header( 'Content-Disposition: ' . ( $dl ? 'attachment' : 'inline' ) . '; filename="' . self::LIST[ $key ]['name'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: private, max-age=3600' );
		$h = fopen( $f, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $h ) {
			while ( ! feof( $h ) ) {
				echo fread( $h, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- contenu binaire du PDF.
				flush();
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
