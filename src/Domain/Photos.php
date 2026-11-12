<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos de non-conformité prises au terminal mobile (réception : avarie, écart, étiquette).
 *
 * Sécurité : seules les images JPEG, PNG ou WebP sont acceptées ; elles sont décodées puis
 * ré-encodées en JPEG (ce qui supprime les métadonnées EXIF, dont la position GPS, et neutralise
 * tout contenu caché). Les fichiers sont rangés dans un dossier privé au nom aléatoire, sous un
 * nom aléatoire, protégé contre l'accès direct, et ne sont servis qu'à l'apprenant·e propriétaire
 * de la session et à ses enseignant·es, après contrôle des droits.
 */
final class Photos {

	public const MAX_UPLOAD_BYTES = 12582912; // 12 Mo avant réduction.
	public const MAX_PIXELS       = 50000000; // 50 mégapixels : protège la mémoire du serveur.
	public const MAX_SIDE         = 1600;
	public const QUALITY          = 82;
	public const MAX_PER_SESSION  = 30;
	private const MIME            = array( IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' );

	/** Dossier privé des photos (créé et protégé au besoin). */
	public static function dir(): string {
		$token = (string) get_option( 'logierp_photo_dir', '' );
		if ( ! preg_match( '/^[a-z0-9]{24}$/', $token ) ) {
			$token = strtolower( wp_generate_password( 24, false, false ) );
			update_option( 'logierp_photo_dir', $token, false );
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'logierp-private/' . $token;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( dirname( $dir ) );
		self::protect( $dir );
		return $dir;
	}

	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# LogiERP : accès direct interdit.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$f = $dir . '/' . $name;
			if ( ! file_exists( $f ) ) {
				file_put_contents( $f, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	public static function count( int $session_id ): int {
		global $wpdb;
		$t = Db::t( 'session_photo' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Enregistre une photo envoyée par l'apprenant·e.
	 *
	 * @param array $file entrée de $_FILES
	 */
	public static function store( Ctx $ctx, array $file, string $ref_no, int $line_no, string $note ): array {
		if ( self::count( $ctx->id ) >= self::MAX_PER_SESSION ) {
			throw new DomainError( sprintf( 'Limite de %d photos par session atteinte. Supprimez une photo inutile.', self::MAX_PER_SESSION ) );
		}
		$err = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			throw new DomainError( 'Photo trop lourde pour le serveur.' );
		}
		if ( UPLOAD_ERR_OK !== $err || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			throw new DomainError( 'Aucune photo reçue. Reprenez la photo.' );
		}
		$tmp  = (string) $file['tmp_name'];
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			throw new DomainError( 'Photo vide ou trop lourde (12 Mo au maximum).' );
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || ! isset( self::MIME[ (int) $info[2] ] ) ) {
			throw new DomainError( 'Format refusé : envoyez une photo JPEG, PNG ou WebP.' );
		}
		[ $w, $h ] = array( (int) $info[0], (int) $info[1] );
		if ( $w < 16 || $h < 16 || $w * $h > self::MAX_PIXELS ) {
			throw new DomainError( 'Dimensions de l\'image non acceptées.' );
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			throw new DomainError( 'Le serveur ne peut pas traiter les images (extension GD absente). Prévenez l\'administrateur du site.' );
		}
		$data = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src  = false !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		unset( $data );
		if ( ! $src ) {
			throw new DomainError( 'Image illisible ou endommagée.' );
		}
		$src = self::orient( $src, $tmp, (int) $info[2] );
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$k   = min( 1.0, self::MAX_SIDE / max( $w, $h ) );
		$nw  = max( 1, (int) round( $w * $k ) );
		$nh  = max( 1, (int) round( $h * $k ) );
		$dst = imagecreatetruecolor( $nw, $nh );
		imagefill( $dst, 0, 0, (int) imagecolorallocate( $dst, 255, 255, 255 ) );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $src );

		$name = bin2hex( random_bytes( 16 ) ) . '.jpg';
		$path = self::dir() . '/' . $name;
		$ok   = imagejpeg( $dst, $path, self::QUALITY );
		imagedestroy( $dst );
		if ( ! $ok || ! is_file( $path ) ) {
			throw new DomainError( 'La photo n\'a pas pu être enregistrée sur le serveur.' );
		}
		$id = Db::insert(
			'session_photo',
			array(
				'session_id' => $ctx->id,
				'user_id'    => get_current_user_id(),
				'ref_no'     => mb_substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $ref_no ) ), 0, 20 ),
				'line_no'    => max( 0, min( 9999, $line_no ) ),
				'note'       => mb_substr( sanitize_text_field( $note ), 0, 190 ),
				'file'       => $name,
				'width'      => $nw,
				'height'     => $nh,
				'bytes'      => (int) filesize( $path ),
				'taken_at'   => $ctx->date(),
				'created_at' => Db::now(),
			)
		);
		$row = self::row( $ctx->id, $id );
		return array( 'photo' => self::summary( (array) $row ), 'message' => 'Photo enregistrée' . ( $ref_no ? ' (' . $ref_no . ( $line_no ? ', ligne ' . $line_no : '' ) . ')' : '' ) . '.' );
	}

	/** Redresse une photo JPEG selon son orientation EXIF (téléphone tenu en portrait). */
	private static function orient( $img, string $tmp, int $type ) {
		if ( IMAGETYPE_JPEG !== $type || ! function_exists( 'exif_read_data' ) ) {
			return $img;
		}
		$exif = @exif_read_data( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$o    = is_array( $exif ) ? (int) ( $exif['Orientation'] ?? 1 ) : 1;
		$deg  = array( 3 => 180, 6 => -90, 8 => 90 )[ $o ] ?? 0;
		if ( $deg ) {
			$r = imagerotate( $img, $deg, 0 );
			if ( $r ) {
				imagedestroy( $img );
				return $r;
			}
		}
		return $img;
	}

	public static function row( int $session_id, int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'session_photo' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND session_id = %d", $id, $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function summary( array $r ): array {
		return array(
			'id'      => (int) $r['id'],
			'ref_no'  => (string) $r['ref_no'],
			'line_no' => (int) $r['line_no'],
			'note'    => (string) $r['note'],
			'width'   => (int) $r['width'],
			'height'  => (int) $r['height'],
			'date'    => (string) $r['taken_at'],
			'at'      => (string) $r['created_at'],
		);
	}

	/** @return array<int,array> */
	public static function list_for( int $session_id ): array {
		global $wpdb;
		$t = Db::t( 'session_photo' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d ORDER BY id", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'summary' ), (array) $rows );
	}

	/** Lignes de commande documentées par au moins une photo : « PO|ligne » => nombre. */
	public static function documented( int $session_id ): array {
		global $wpdb;
		$t    = Db::t( 'session_photo' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ref_no, line_no, COUNT(*) n FROM {$t} WHERE session_id = %d GROUP BY ref_no, line_no", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['ref_no'] . '|' . (int) $r['line_no'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function delete( Ctx $ctx, int $id ): array {
		$r = self::row( $ctx->id, $id );
		if ( ! $r ) {
			throw new DomainError( 'Photo introuvable.' );
		}
		self::unlink( (string) $r['file'] );
		global $wpdb;
		$wpdb->delete( Db::t( 'session_photo' ), array( 'id' => (int) $r['id'] ) );
		return array( 'message' => 'Photo supprimée.' );
	}

	/** Supprime les fichiers et les lignes d'une session (appelé à la suppression de la session). */
	public static function delete_for_session( int $session_id ): void {
		global $wpdb;
		$t     = Db::t( 'session_photo' );
		$files = (array) $wpdb->get_col( $wpdb->prepare( "SELECT file FROM {$t} WHERE session_id = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $files as $f ) {
			self::unlink( (string) $f );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE session_id = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Supprime tous les fichiers photo (réinitialisation complète des données). */
	public static function purge_files(): void {
		foreach ( (array) glob( self::dir() . '/*.jpg' ) as $f ) {
			if ( is_string( $f ) && preg_match( '/\/[a-f0-9]{32}\.jpg$/', $f ) ) {
				wp_delete_file( $f );
			}
		}
	}

	private static function unlink( string $file ): void {
		if ( preg_match( '/^[a-f0-9]{32}\.jpg$/', $file ) ) {
			$p = self::dir() . '/' . $file;
			if ( is_file( $p ) ) {
				wp_delete_file( $p );
			}
		}
	}

	/** Envoie l'image au navigateur (droits vérifiés par l'appelant) puis termine la requête. */
	public static function send( array $row ): void {
		$file = (string) $row['file'];
		$path = preg_match( '/^[a-f0-9]{32}\.jpg$/', $file ) ? self::dir() . '/' . $file : '';
		if ( '' === $path || ! is_file( $path ) ) {
			status_header( 404 );
			exit;
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="logierp-photo-' . (int) $row['id'] . '.jpg"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Cache-Control: private, no-store, max-age=0' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
