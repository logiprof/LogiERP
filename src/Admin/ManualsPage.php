<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Front\Manuals;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page « Manuels » : ouvrir ou télécharger les manuels PDF, aller directement à une procédure,
 * et (administrateur) remplacer un manuel par une version plus récente.
 */
final class ManualsPage {

	public function register(): void {
		add_action( 'admin_post_logierp_manual_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_logierp_manual_restore', array( $this, 'handle_restore' ) );
	}

	public function render(): void {
		Ui::header( 'Manuels', 'Les manuels de LogiERP, à lire à l\'écran ou à télécharger en PDF. Les apprentis trouvent le manuel d\'utilisation et les fiches de procédure dans le simulateur (portail, boutons « Manuel » et « Fiche » de chaque session).' );
		$map = Manuals::map();
		echo '<div class="lw-manuals">';
		foreach ( Manuals::LIST as $key => $m ) {
			if ( ! Manuals::can( $key ) ) {
				continue;
			}
			$i = Manuals::info( $key );
			echo '<div class="lw-box lw-manual"><div class="lw-manual-head"><span class="lw-manual-ico" aria-hidden="true">PDF</span><div><h2>' . esc_html( $m['title'] ) . '</h2><p class="description">' . esc_html( 'teacher' === $key ? 'Pour les enseignants.' : 'Pour les apprentis et les enseignants.' ) . '</p></div></div>';
			echo '<p>' . esc_html( $m['text'] ) . '</p>';
			if ( $i['outdated'] ) {
				echo '<div class="notice notice-warning inline"><p><strong>Une version plus récente de ce manuel est livrée avec LogiERP ' . esc_html( $i['version'] ) . '.</strong> Le PDF déposé le ' . esc_html( Ui::fr_datetime( $i['updated'] ) ) . ' reste affiché tant qu\'il n\'est pas retiré' . ( current_user_can( Capabilities::SETTINGS ) && ! Ui::is_front() ? ' : utilisez « Revenir à la version livrée avec LogiERP » ci-dessous.' : ' : demandez à l\'administrateur du site de revenir à la version livrée.' ) . '</p></div>';
			}
			if ( $i['exists'] ) {
				echo '<p class="lw-manual-meta">' . esc_html( trim( ( $i['pages'] && ! $i['custom'] ? $i['pages'] . ' pages · ' : '' ) . size_format( $i['size'], 1 ) . ( $i['custom'] ? ' · version déposée le ' . Ui::fr_datetime( $i['updated'] ) : '' ) ) ) . '</p>';
				echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( Manuals::url( $key ) ) . '">Ouvrir</a> <a class="button" href="' . esc_url( Manuals::url( $key, true ) ) . '">Télécharger le PDF</a></p>';
			} else {
				echo '<div class="notice notice-warning inline"><p>Ce manuel n\'est pas installé.' . ( current_user_can( Capabilities::SETTINGS ) ? ' Déposez le PDF ci-dessous.' : ' Demandez à l\'administrateur du site de le déposer.' ) . '</p></div>';
			}
			// Sommaire : chaque procédure s'ouvre à sa page (version livrée avec l'extension).
			$procs = (array) ( $map[ $key ]['procedures'] ?? array() );
			if ( $i['exists'] && ! $i['custom'] && $procs ) {
				uasort( $procs, static fn( $a, $b ) => (int) $a['page'] <=> (int) $b['page'] );
				$groups = array();
				foreach ( $procs as $code => $p ) {
					$g              = 'teacher' === $key ? substr( $code, 0, 3 ) : ( 'fiches' === $key ? 'PR' : $code[0] );
					$groups[ $g ][] = array( $code, $p );
				}
				echo '<details class="lw-manual-toc"><summary>' . esc_html( 'fiches' === $key ? 'Sommaire : aller directement à une fiche' : 'Sommaire : aller directement à une procédure' ) . '</summary>';
				foreach ( $groups as $g => $items ) {
					echo '<h3>' . esc_html( $g . ' · ' . ( $map['domains'][ $g ] ?? '' ) ) . '</h3><ul>';
					foreach ( $items as [ $code, $p ] ) {
						echo '<li><a target="_blank" rel="noopener" href="' . esc_url( Manuals::url( $key ) . '#page=' . (int) $p['page'] ) . '"><b>' . esc_html( $code ) . '</b> ' . esc_html( (string) $p['title'] ) . '</a> <span class="description">p. ' . (int) $p['page'] . '</span></li>';
					}
					echo '</ul>';
				}
				echo '</details>';
			}
			if ( current_user_can( Capabilities::SETTINGS ) && ! Ui::is_front() ) {
				echo '<details class="lw-manual-admin"' . ( $i['outdated'] ? ' open' : '' ) . '><summary>Administrateur : remplacer ce manuel</summary>';
				Ui::form_open( 'logierp_manual_upload', array( 'manual' => $key ), 'enctype="multipart/form-data"' );
				echo '<p><input type="file" name="pdf" accept="application/pdf,.pdf" required> ';
				Ui::form_close( 'Déposer le PDF', 'button' );
				echo '</p><p class="description">PDF uniquement, ' . (int) Manuals::MAX_MB . ' Mo au plus (et dans la limite d\'envoi de votre hébergement : ' . esc_html( size_format( wp_max_upload_size() ) ) . '). Le sommaire par procédure n\'est disponible que pour la version livrée avec LogiERP.</p>';
				if ( $i['custom'] ) {
					Ui::button_form( 'logierp_manual_restore', array( 'manual' => $key ), 'Revenir à la version livrée avec LogiERP', 'button-link-delete', 'Supprimer le PDF déposé et revenir à la version livrée avec LogiERP ?' );
				}
				echo '</details>';
			}
			echo '</div>';
		}
		echo '</div>';
		Ui::footer();
	}

	private static function key(): string {
		$k = Ui::post_str( 'manual', 10 );
		if ( ! isset( Manuals::LIST[ $k ] ) ) {
			wp_die( esc_html__( 'Manuel inconnu.', 'logierp' ), '', array( 'response' => 400 ) );
		}
		return $k;
	}

	public function handle_upload(): void {
		Ui::guard( 'logierp_manual_upload', Capabilities::SETTINGS );
		$k = self::key();
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce vérifié par guard() ; fichier contrôlé ci-dessous.
		$f = $_FILES['pdf'] ?? null;
		// phpcs:enable
		if ( ! $f || ! is_array( $f ) || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? -1 ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			Ui::redirect( 'logierp-manuals', array(), 'error', 'Envoi impossible : fichier absent ou trop volumineux pour l\'hébergement.' );
		}
		$tmp  = (string) $f['tmp_name'];
		$size = (int) filesize( $tmp );
		$head = (string) file_get_contents( $tmp, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- en-tête du fichier envoyé.
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $tmp ) : 'application/pdf';
		if ( '%PDF-' !== $head || 'application/pdf' !== $mime || $size < 1024 || $size > Manuals::MAX_MB * 1048576 ) {
			Ui::redirect( 'logierp-manuals', array(), 'error', 'Ce fichier n\'est pas un PDF valide (ou dépasse ' . Manuals::MAX_MB . ' Mo).' );
		}
		try {
			Manuals::replace( $k, $tmp );
		} catch ( \RuntimeException $e ) {
			Ui::redirect( 'logierp-manuals', array(), 'error', $e->getMessage() );
		}
		Audit::log( 'manual_upload', 'manual', 0, array( 'manual' => $k, 'size' => $size ) );
		Ui::redirect( 'logierp-manuals', array(), 'success', Manuals::LIST[ $k ]['title'] . ' remplacé.' );
	}

	public function handle_restore(): void {
		Ui::guard( 'logierp_manual_restore', Capabilities::SETTINGS );
		$k = self::key();
		Manuals::restore( $k );
		Manuals::ensure();
		Audit::log( 'manual_restore', 'manual', 0, array( 'manual' => $k ) );
		Ui::redirect( 'logierp-manuals', array(), Manuals::info( $k )['exists'] ? 'success' : 'warning', Manuals::info( $k )['exists'] ? 'Version livrée avec LogiERP rétablie.' : 'Le PDF déposé est supprimé, mais la version livrée n\'est plus disponible : réinstallez l\'extension ou déposez un PDF.' );
	}
}
