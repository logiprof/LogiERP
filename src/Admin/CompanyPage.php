<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Domain\Company;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Présentation de l'entreprise fictive : c'est le premier écran que voit l'apprenant·e dans chaque session.
 */
final class CompanyPage {

	public function register(): void {
		add_action( 'admin_post_logierp_co_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_logierp_co_reset', array( $this, 'handle_reset' ) );
	}

	public function render(): void {
		$c = Company::get();
		Ui::header( 'Entreprise', 'L\'entreprise fictive dans laquelle travaillent vos apprenant·e·s. Cette présentation s\'affiche au début de chaque session, avant la mission.' );
		echo '<div class="lw-box lw-co-preview"><div class="lw-co-logo">' . Company::logo_svg( 52 ) . '</div><p><strong>' . esc_html( $c['name'] ) . '</strong> – ' . esc_html( $c['tagline'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG construit avec échappement.
		echo '<p class="description">Les apprenant·e·s voient la présentation complète (chiffres clés, sites, centre de distribution, organisation, historique, règles) dans le simulateur, rubrique « Notre entreprise ». Le nom et l\'adresse figurent aussi comme destinataire sur les lettres de voiture CMR.</p></div>';

		echo '<div class="lw-box"><h2>Modifier la présentation</h2>';
		Ui::form_open( 'logierp_co_save' );
		echo '<table class="form-table">';
		foreach ( Company::TEXTS as $k => $def ) {
			$html = $def[2]
				? Ui::textarea( 'co_' . $k, (string) $c[ $k ], 'address' === $k ? 2 : 5 )
				: Ui::input( 'co_' . $k, (string) $c[ $k ], 'text', 'maxlength="' . (int) $def[1] . '"' );
			Ui::field( $def[0], $html, 'address' === $k ? 'Adresse de livraison utilisée sur les documents de transport.' : '' );
		}
		foreach ( Company::LISTS as $k => $def ) {
			Ui::field( $def[0], Ui::textarea( 'co_' . $k, Company::list_text( $k ), min( 12, max( 4, count( (array) $c[ $k ] ) + 1 ) ) ), sprintf( 'Une ligne par élément, colonnes séparées par « | ». %d lignes au maximum.', $def[2] ) );
		}
		echo '</table>';
		Ui::form_close( 'Enregistrer la présentation' );
		echo '</div>';

		echo '<div class="lw-box"><h2>Revenir à la présentation d\'origine</h2><p>Remet le texte, les chiffres et les sites de Lémantis Logistique SA.</p>';
		Ui::button_form( 'logierp_co_reset', array(), 'Rétablir la présentation d\'origine', 'button', 'Rétablir la présentation d\'origine ? Vos modifications seront perdues.' );
		echo '</div>';
		Ui::footer();
	}

	public function handle_save(): void {
		Ui::guard( 'logierp_co_save', Capabilities::MASTER_DATA );
		$raw = array();
		foreach ( array_merge( array_keys( Company::TEXTS ), array_keys( Company::LISTS ) ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par Ui::guard().
			$raw[ $k ] = isset( $_POST[ 'co_' . $k ] ) ? mb_substr( (string) wp_unslash( $_POST[ 'co_' . $k ] ), 0, 8000 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nettoyé par Company::save().
		}
		Company::save( $raw );
		Audit::log( 'company_save' );
		Ui::redirect( 'logierp-company', array(), 'success', 'Présentation de l\'entreprise enregistrée.' );
	}

	public function handle_reset(): void {
		Ui::guard( 'logierp_co_reset', Capabilities::MASTER_DATA );
		Company::reset();
		Audit::log( 'company_reset' );
		Ui::redirect( 'logierp-company', array(), 'success', 'Présentation d\'origine rétablie.' );
	}
}
