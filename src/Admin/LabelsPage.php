<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Labels;
use LogiERP\Front\LabelPrint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Étiquettes code-barres : articles, emplacements, palettes SSCC, documents et étiquettes libres,
 * imprimées sur planches A4 et lisibles par le terminal mobile (caméra du smartphone ou douchette).
 */
final class LabelsPage {

	private static function scenarios(): array {
		global $wpdb;
		$t    = Db::t( 'scenario' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, owner_id, shared, template_key FROM {$t} WHERE deleted_at IS NULL AND (owner_id = %d OR shared = 1 OR template_key <> '' OR %d = 1) ORDER BY template_key = '' DESC, title", get_current_user_id(), current_user_can( Capabilities::SETTINGS ) ? 1 : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array( '' => '— choisir un scénario —' );
		foreach ( $rows as $r ) {
			$out[ (string) $r['id'] ] = $r['title'] . ( '' !== $r['template_key'] ? ' (modèle)' : '' );
		}
		return $out;
	}

	private static function formats(): array {
		return array_map( static fn( $f ) => $f[0], Labels::FORMATS );
	}

	/** Bloc d'options commun : format, exemplaires, position de départ, traits de coupe. */
	private static function common( string $type ): string {
		$h  = '<tr><th scope="row">Format</th><td>' . Ui::select( 'format', self::formats(), Labels::DEFAULT_FORMAT[ $type ] ) . '</td></tr>';
		$h .= 'sscc' !== $type ? '<tr><th scope="row">Exemplaires</th><td>' . Ui::input( 'copies', '1', 'number', 'min="1" max="50" style="width:90px"' ) . ' <span class="description">par étiquette</span></td></tr>' : '';
		$h .= '<tr><th scope="row">Commencer à la position</th><td>' . Ui::input( 'start', '1', 'number', 'min="1" max="24" style="width:90px"' ) . ' <span class="description">pour réutiliser une planche déjà entamée (1 = en haut à gauche)</span></td></tr>';
		$h .= '<tr><th scope="row">Traits de coupe</th><td>' . Ui::checkbox( 'border', true, 'Afficher un cadre fin autour de chaque étiquette (papier ordinaire à découper)' ) . '</td></tr>';
		return $h;
	}

	private static function form( string $type, string $title, string $intro, string $fields ): void {
		echo '<div class="lw-box lw-label-box" id="lw-lb-' . esc_attr( $type ) . '"><h2>' . esc_html( $title ) . '</h2><p class="description">' . esc_html( $intro ) . '</p>';
		echo '<form method="post" target="_blank" action="' . esc_url( home_url( '/' ) ) . '">';
		echo '<input type="hidden" name="' . esc_attr( LabelPrint::QUERY ) . '" value="labels"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
		wp_nonce_field( LabelPrint::NONCE, '_lwnonce' );
		echo '<table class="form-table">' . $fields . self::common( $type ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- champs construits avec Ui (échappés).
		echo '<p><button type="submit" class="button button-primary">Aperçu et impression</button> <span class="description">S\'ouvre dans un nouvel onglet ; imprimez à 100 % (sans « ajuster à la page »).</span></p></form></div>';
	}

	/** Encadré « étiquette » d'une fiche article : EAN-13 et impression directe. */
	public static function quick_article( string $material_no, string $ean ): void {
		if ( ! current_user_can( Capabilities::SCENARIOS ) ) {
			return;
		}
		echo '<div class="lw-box"><h2>Code-barres et étiquette</h2><p>EAN-13 de l\'article : <code>' . esc_html( $ean ) . '</code>. C\'est ce code que le terminal mobile lit sur l\'étiquette de l\'article.</p>';
		echo '<form method="post" target="_blank" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="' . esc_attr( LabelPrint::QUERY ) . '" value="labels"><input type="hidden" name="type" value="article"><input type="hidden" name="articles" value="' . esc_attr( $material_no ) . '"><input type="hidden" name="border" value="1">';
		wp_nonce_field( LabelPrint::NONCE, '_lwnonce' );
		echo Ui::select( 'format', self::formats(), 'S' ) . ' ' . Ui::input( 'copies', '1', 'number', 'min="1" max="50" style="width:80px" aria-label="Exemplaires"' ) . ' <button type="submit" class="button">Imprimer l\'étiquette</button></form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- champs construits avec Ui (échappés).
	}

	public function render(): void {
		Ui::header( 'Étiquettes code-barres', 'Imprimez des étiquettes lisibles par le terminal mobile (caméra du smartphone ou douchette) : articles, emplacements, palettes d\'un arrivage, commandes et étiquettes libres. Idéal pour équiper un atelier ou un rayonnage d\'exercice.' );
		$scs  = self::scenarios();
		$pre  = Ui::get_int( 'scenario' );
		$sval = $pre ? (string) $pre : '';
		$locs = array();
		foreach ( Catalog::locations() as $l ) {
			if ( empty( $l['is_transit'] ) || in_array( $l['code'], array( '100', '600', '700', '800' ), true ) ) {
				$locs[ (string) $l['code'] ] = $l['code'] . ' – ' . $l['name'];
			}
		}
		// Kit complet d'un scénario (formulaire GET : la page vérifie le jeton et les droits).
		echo '<div class="lw-box lw-kit-box"><h2>Kit mini-entrepôt d\'un scénario</h2><p class="description">Tout pour transformer une salle en entrepôt : fiche de montage, étiquettes d\'emplacements avec chiffre de contrôle, cartes d\'articles conformes au stock du scénario (écarts cachés compris), cartes des arrivages et étiquettes des bacs de préparation.</p>';
		echo '<form method="get" action="' . esc_url( home_url( '/' ) ) . '" target="_blank"><input type="hidden" name="logierp_doc" value="kit"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'lw_kit' ) ) . '"><p>' . Ui::select( 'scenario', $scs, $sval ) . ' <button type="submit" class="button button-primary">Ouvrir le kit</button></p></form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::select échappe.
		echo '<nav class="lw-label-nav">';
		foreach ( Labels::TYPES as $k => $l ) {
			echo '<a class="button" href="#lw-lb-' . esc_attr( $k ) . '">' . esc_html( $l ) . '</a> ';
		}
		echo '</nav>';

		self::form(
			'article',
			'Articles (EAN-13)',
			'Chaque article porte un EAN-13. Scanné au terminal, il identifie l\'article (rangement, prélèvement, colisage, stock, inventaire).',
			'<tr><th scope="row">Articles</th><td>' . Ui::textarea( 'articles', '', 5 ) . '<p class="description">Un numéro d\'article par ligne, éventuellement suivi du nombre d\'exemplaires : <code>10000213;5</code>. Ou choisissez ci-dessous un scénario.</p></td></tr>'
			. '<tr><th scope="row">Articles d\'un scénario</th><td>' . Ui::select( 'scenario', $scs, $sval ) . '<p class="description">Ajoute tous les articles cités par le scénario (documents, stock de départ, imprévus).</p></td></tr>'
		);
		self::form(
			'bin',
			'Emplacements (Code 128)',
			'Étiquettes de rayonnage : le code de l\'emplacement en grand, avec son code-barres. Scannées au rangement, au prélèvement et à l\'inventaire.',
			'<tr><th scope="row">Magasin</th><td>' . Ui::select( 'location', $locs, '110' ) . '</td></tr>'
			. '<tr><th scope="row">Codes commençant par</th><td>' . Ui::input( 'prefix', '', 'text', 'placeholder="ex. 110-A01" maxlength="30"' ) . ' <span class="description">vide = tout le magasin (' . (int) Labels::MAX . ' étiquettes au plus)</span></td></tr>'
			. '<tr><th scope="row">Sélection</th><td>' . Ui::checkbox( 'fixed_only', false, 'Uniquement les emplacements fixes (article attribué, zone de prélèvement)' ) . '</td></tr>'
			. '<tr><th scope="row">Flèche</th><td>' . Ui::select( 'arrow', array( '' => 'Aucune', 'up' => '▲ vers le haut (niveau supérieur)', 'down' => '▼ vers le bas (niveau inférieur)' ), '' ) . '</td></tr>'
		);
		self::form(
			'sscc',
			'Palettes et colis d\'un arrivage (SSCC)',
			'Étiquette logistique GS1 de chaque palette ou colis des commandes d\'achat du scénario, comme celle collée par le fournisseur : SSCC, article (GTIN), quantité, lot et date limite. Les codes sont les mêmes pour tous les apprentis du scénario : imprimez une fois pour toute la classe.',
			'<tr><th scope="row">Scénario</th><td>' . Ui::select( 'scenario', $scs, $sval ) . '<p class="description">Une session d\'essai (la vôtre) sert de référence ; elle est créée si besoin.</p></td></tr>'
		);
		self::form(
			'document',
			'Documents d\'un scénario',
			'Le n° de chaque commande d\'achat du scénario en Code 128 : au terminal, le scan ouvre directement la réception de la commande.',
			'<tr><th scope="row">Scénario</th><td>' . Ui::select( 'scenario', $scs, $sval ) . '</td></tr>'
		);
		self::form(
			'free',
			'Étiquettes libres (Code 128)',
			'Pour tout autre besoin : un titre et un code par ligne, séparés par un point-virgule. Exemple : « Quai de réception;100-R-01 ».',
			'<tr><th scope="row">Étiquettes</th><td>' . Ui::textarea( 'lines', '', 5 ) . '<p class="description">Code : 40 caractères au plus, sans accent (lettres, chiffres, tirets…).</p></td></tr>'
		);
		echo '<div class="lw-box"><h2>Bien imprimer et scanner</h2><ul class="lw-steps"><li>Imprimez à <strong>100 %</strong> (désactivez « Ajuster à la page »), sur papier blanc mat ou planches d\'étiquettes adhésives A4 du format choisi.</li><li>Laissez la <strong>zone blanche</strong> de chaque côté du code-barres : ne la recouvrez pas et ne coupez pas dedans.</li><li>Au terminal mobile, scannez avec le bouton <strong>Scanner</strong> (caméra) ou une douchette ; le code lisible sous les barres peut aussi être saisi au clavier.</li></ul></div>';
		Ui::footer();
	}
}
