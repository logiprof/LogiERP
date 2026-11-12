<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\MasterData;
use LogiERP\Domain\Rules;
use LogiERP\Security\Audit;
use LogiERP\Seeder\Dictionaries;
use LogiERP\Seeder\WarehouseBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entrepôt cartographié : zones de stockage, critères de stockage par zone et emplacements.
 *
 * Les critères d'une zone (profils admis, plage de température, classes ADR, sécurisation) sont
 * appliqués par le moteur de règles à chaque rangement. Toute modification est refusée si elle
 * rendait non conforme du stock existant (stock de départ ou session en cours).
 */
final class WarehousePage {

	private const WH  = 'logierp-warehouse';
	private const PER = 50;

	public const STRATEGIES = array( 'CHAOTIC' => 'Chaotique (emplacement libre compatible)', 'FIXED' => 'Emplacement fixe (lotissement par case)', 'NONE' => 'Sans emplacement (zone de transit)' );

	public const ZONE_TYPES = array(
		'STD'     => 'Stockage standard',
		'PICK'    => 'Prélèvement (picking)',
		'COLD'    => 'Froid positif',
		'FROZEN'  => 'Froid négatif',
		'TEMP'    => 'Température contrôlée',
		'CHEM'    => 'Matières dangereuses',
		'HV'      => 'Haute valeur',
		'LONG'    => 'Charges longues',
		'HEAVY'   => 'Charges lourdes',
		'PROD'    => 'Bord de ligne production',
		'TRANSIT' => 'Transit (quais, contrôle, expédition)',
	);

	public const BIN_TYPES = array( 'PAL' => 'Palette', 'ETAGERE' => 'Étagère', 'BAC' => 'Bac', 'ARMOIRE' => 'Armoire', 'CANTILEVER' => 'Cantilever', 'SOL' => 'Sol', 'PLATEAU' => 'Plateau (magasin vertical)' );

	/** Couleurs du plan par type de zone (charte LogiProf). */
	private const MAP_COLORS = array( 'STD' => '#dcefe3', 'PICK' => '#fff4c2', 'COLD' => '#dbeafe', 'FROZEN' => '#c7d2fe', 'TEMP' => '#e0f2fe', 'CHEM' => '#fde7e4', 'HV' => '#ede9fe', 'LONG' => '#e7e5e4', 'HEAVY' => '#d6d3d1', 'PROD' => '#fef3c7', 'TRANSIT' => '#eceeea' );

	public function register(): void {
		foreach ( array( 'zone_create', 'zone_save', 'zone_delete', 'bin_block', 'bins_generate' ) as $a ) {
			add_action( 'admin_post_logierp_wh_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	/** Zones livrées avec LogiERP : utilisées par les processus et les scénarios modèles (non supprimables). */
	private static function system_codes(): array {
		return array_map( static fn( $l ) => $l['code'], WarehouseBuilder::locations() );
	}

	private static function is_system( array $l ): bool {
		return in_array( $l['code'], self::system_codes(), true );
	}

	/* ───────── Vue d'ensemble ───────── */

	public function render(): void {
		$lid = Ui::get_int( 'location' );
		if ( ! Catalog::locations() ) {
			Ui::header( 'Entrepôt' );
			Ui::seed_box();
			Ui::footer();
			return;
		}
		if ( '1' === Ui::get_str( 'new' ) ) {
			$this->zone_form( null );
			return;
		}
		if ( $lid ) {
			$this->zone( $lid );
			return;
		}
		global $wpdb;
		Ui::header( 'Entrepôt', 'Plan de l\'entrepôt, zones de stockage et critères de stockage. Chaque rangement est contrôlé selon les critères de la zone et les limites de l\'emplacement.' );
		$locs   = Catalog::locations();
		$bt     = Db::t( 'bin' );
		$st     = Db::t( 'stock' );
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT storage_location_id l, COUNT(*) n, SUM(blocked_in OR blocked_out) b, SUM(fixed_material_id > 0) f FROM {$bt} GROUP BY storage_location_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ (int) $r['l'] ] = $r;
		}
		$fill = array();
		foreach ( (array) $wpdb->get_results( "SELECT storage_location_id l, COUNT(DISTINCT bin_id) n FROM {$st} WHERE session_id = 0 AND qty > 0 GROUP BY storage_location_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$fill[ (int) $r['l'] ] = (int) $r['n'];
		}
		echo '<p class="lw-toolbar-admin"><a class="button button-primary" href="' . esc_url( Ui::url( self::WH, array( 'new' => '1' ) ) ) . '">+ Nouvelle zone de stockage</a></p>';
		echo '<div class="lw-box"><h2>Plan de l\'entrepôt</h2>';
		self::map( $locs, $counts, $fill );
		echo '</div>';
		echo '<div class="lw-box"><h2>Zones de stockage (' . count( $locs ) . ')</h2><div class="lw-table-scroll"><table class="widefat striped lw-table-admin"><thead><tr><th>Zone</th><th>Nom</th><th>Type</th><th>Rangement</th><th>Profils admis</th><th>Critères</th><th>Emplacements</th><th></th></tr></thead><tbody>';
		foreach ( $locs as $l ) {
			$c = $counts[ (int) $l['id'] ] ?? array( 'n' => 0, 'b' => 0, 'f' => 0 );
			echo '<tr><td><strong>' . esc_html( $l['code'] ) . '</strong></td><td>' . esc_html( $l['name'] ) . '</td><td>' . esc_html( self::ZONE_TYPES[ $l['zone_type'] ] ?? $l['zone_type'] ) . '</td><td>' . esc_html( array( 'CHAOTIC' => 'Chaotique', 'FIXED' => 'Fixe', 'NONE' => '—' )[ $l['strategy'] ] ?? $l['strategy'] ) . '</td><td>' . esc_html( self::profiles_text( $l ) ) . '</td><td>' . esc_html( implode( ' · ', self::criteria( $l ) ) ) . '</td><td>' . (int) $c['n'] . ( isset( $fill[ (int) $l['id'] ] ) ? ' <span class="lw-pill">' . (int) $fill[ (int) $l['id'] ] . ' occupé(s)</span>' : '' ) . ( (int) $c['b'] ? ' <span class="lw-pill warn">' . (int) $c['b'] . ' bloqué(s)</span>' : '' ) . '</td><td><a class="button" href="' . esc_url( Ui::url( self::WH, array( 'location' => (int) $l['id'] ) ) ) . '">Gérer</a></td></tr>';
		}
		echo '</tbody></table></div></div>';
		Ui::footer();
	}

	private static function profiles_text( array $l ): string {
		if ( ! empty( $l['is_transit'] ) ) {
			return 'Tous (transit)';
		}
		$codes = array_filter( array_map( 'trim', explode( ',', (string) $l['profiles'] ) ) );
		return $codes ? implode( ', ', array_map( array( Catalog::class, 'profile_label' ), $codes ) ) : 'Aucun';
	}

	/** @return string[] */
	private static function criteria( array $l ): array {
		$c = array();
		if ( null !== $l['temp_min'] && '' !== (string) $l['temp_min'] ) {
			$c[] = Catalog::format_qty( (float) $l['temp_min'] ) . ' à ' . Catalog::format_qty( (float) $l['temp_max'] ) . ' °C';
		}
		if ( '' !== (string) $l['adr_classes'] ) {
			$c[] = 'ADR ' . str_replace( ',', ', ', $l['adr_classes'] );
		}
		if ( (int) $l['secured'] ) {
			$c[] = 'Sous clé';
		}
		if ( (int) $l['allow_mixed'] ) {
			$c[] = 'Mélange autorisé';
		}
		return $c;
	}

	/** Plan SVG (aucune donnée insérée sans échappement). */
	private static function map( array $locs, array $counts, array $fill ): void {
		$w = 1200;
		$h = 100;
		foreach ( $locs as $l ) {
			$w = max( $w, (int) $l['map_x'] + (int) $l['map_w'] + 20 );
			$h = max( $h, (int) $l['map_y'] + (int) $l['map_h'] + 20 );
		}
		echo '<div class="lw-map-wrap"><svg class="lw-map" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" role="img" aria-label="Plan de l\'entrepôt"><rect x="0" y="0" width="' . (int) $w . '" height="' . (int) $h . '" fill="#f6f7f4"/>';
		foreach ( $locs as $l ) {
			$x = (int) $l['map_x'];
			$y = (int) $l['map_y'];
			$rw = max( 40, (int) $l['map_w'] );
			$rh = max( 30, (int) $l['map_h'] );
			$n  = (int) ( $counts[ (int) $l['id'] ]['n'] ?? 0 );
			$f  = (int) ( $fill[ (int) $l['id'] ] ?? 0 );
			$pc = $n ? (int) round( 100 * $f / $n ) : 0;
			$url = Ui::url( self::WH, array( 'location' => (int) $l['id'] ) );
			echo '<a href="' . esc_url( $url ) . '"><title>' . esc_html( $l['code'] . ' – ' . $l['name'] . ( $n ? ' · ' . $pc . ' % occupé' : '' ) ) . '</title>';
			echo '<rect x="' . $x . '" y="' . $y . '" width="' . $rw . '" height="' . $rh . '" rx="4" fill="' . esc_attr( self::MAP_COLORS[ $l['zone_type'] ] ?? '#eceeea' ) . '" stroke="#313a45" stroke-width="1.5"/>';
			if ( $n && empty( $l['is_transit'] ) ) {
				echo '<rect x="' . $x . '" y="' . ( $y + $rh - 6 ) . '" width="' . (int) round( $rw * $pc / 100 ) . '" height="6" fill="#067030" opacity=".75"/>';
			}
			echo '<text x="' . ( $x + 8 ) . '" y="' . ( $y + 22 ) . '" font-family="Poppins, system-ui, sans-serif" font-weight="700" font-size="17" fill="#313a45">' . esc_html( $l['code'] ) . '</text>';
			if ( $rw >= 120 ) {
				echo '<text x="' . ( $x + 8 ) . '" y="' . ( $y + 42 ) . '" font-family="system-ui, sans-serif" font-size="12" fill="#313a45">' . esc_html( mb_strimwidth( (string) $l['name'], 0, (int) floor( $rw / 7 ), '…' ) ) . '</text>';
			}
			echo '</a>';
		}
		echo '</svg></div><p class="description">Cliquez sur une zone pour la gérer. La barre verte indique le taux d\'occupation des emplacements dans le stock de départ.</p>';
	}

	/* ───────── Fiche zone ───────── */

	private function zone( int $lid ): void {
		global $wpdb;
		$l = Catalog::location( $lid );
		if ( ! $l ) {
			wp_die( esc_html__( 'Zone introuvable.', 'logierp' ) );
		}
		Ui::header( 'Zone ' . $l['code'] . ' – ' . $l['name'], (string) $l['rack_type'] );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::WH ) ) . '">← Entrepôt</a></p>';
		$this->zone_form( $l );

		$q     = strtoupper( Ui::get_str( 'q' ) );
		$page  = max( 1, Ui::get_int( 'lw_p' ) );
		$bt    = Db::t( 'bin' );
		$mt    = Db::t( 'material' );
		$where = $wpdb->prepare( 'b.storage_location_id = %d', $lid );
		if ( '' !== $q ) {
			$where .= $wpdb->prepare( ' AND b.code LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' );
		}
		if ( '1' === Ui::get_str( 'blocked' ) ) {
			$where .= ' AND (b.blocked_in = 1 OR b.blocked_out = 1)';
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} b WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bins  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.*, m.material_no FROM {$bt} b LEFT JOIN {$mt} m ON m.id = b.fixed_material_id WHERE {$where} ORDER BY b.pick_sequence, b.code LIMIT %d OFFSET %d", self::PER, ( $page - 1 ) * self::PER ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>Emplacements (' . (int) $total . ')</h2>';
		Ui::get_form_open( self::WH, array( 'location' => (int) $lid ) );
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Code emplacement"> <label><input type="checkbox" name="blocked" value="1"' . checked( '1' === Ui::get_str( 'blocked' ), true, false ) . '> Bloqués uniquement</label> <button class="button">Filtrer</button></form>';
		echo '<div class="lw-table-scroll"><table class="widefat striped"><thead><tr><th>Emplacement</th><th>Type</th><th>Charge max.</th><th>Volume</th><th>Longueur utile</th><th>Séquence</th><th>Fixe</th><th>Blocage</th><th></th></tr></thead><tbody>';
		if ( ! $bins ) {
			echo '<tr><td colspan="9">Aucun emplacement. ' . ( 'NONE' === $l['strategy'] ? '' : 'Générez une allée ci-dessous.' ) . '</td></tr>';
		}
		foreach ( $bins as $b ) {
			$blk = array();
			if ( (int) $b['blocked_in'] ) {
				$blk[] = 'entrées';
			}
			if ( (int) $b['blocked_out'] ) {
				$blk[] = 'sorties';
			}
			echo '<tr><td><code>' . esc_html( $b['code'] ) . '</code></td><td>' . esc_html( self::BIN_TYPES[ $b['bin_type'] ] ?? $b['bin_type'] ) . '</td><td>' . esc_html( Catalog::format_qty( (float) $b['max_weight'] ) ) . ' kg</td><td>' . esc_html( Catalog::format_qty( (float) $b['max_volume_l'] ) ) . ' l</td><td>' . (int) $b['length_mm'] . ' mm</td><td>' . (int) $b['pick_sequence'] . '</td><td>' . esc_html( $b['material_no'] ? $b['material_no'] . ' (' . Catalog::format_qty( (float) $b['min_qty'] ) . '–' . Catalog::format_qty( (float) $b['max_qty'] ) . ')' : '' ) . '</td><td>' . esc_html( $blk ? 'Bloqué ' . implode( ' + ', $blk ) . ( $b['block_reason'] ? ' : ' . $b['block_reason'] : '' ) : '' ) . '</td><td class="lw-actions-cell">';
			if ( $blk ) {
				Ui::button_form( 'logierp_wh_bin_block', array( 'location' => $lid, 'bin_id' => (int) $b['id'], 'mode' => 'none' ), 'Débloquer', 'button button-small' );
			} else {
				Ui::form_open( 'logierp_wh_bin_block', array( 'location' => $lid, 'bin_id' => (int) $b['id'] ), 'style="display:inline"' );
				echo '<select name="mode"><option value="in">Entrées</option><option value="both">Entrées + sorties</option></select> <input type="text" name="reason" maxlength="120" placeholder="Motif" style="width:140px"> ';
				Ui::form_close( 'Bloquer', 'button button-small' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		DataPage::pager( $total, $page, array( 'location' => $lid, 'q' => $q ), self::WH, self::PER );
		echo '</div>';

		if ( 'NONE' !== $l['strategy'] ) {
			echo '<div class="lw-box"><h2>Ajouter des emplacements</h2><p class="description">Crée une allée complète. Codification : zone-allée-travée-niveau[-position], par ex. ' . esc_html( $l['code'] ) . '-A07-01-00-G. Les codes existants sont ignorés.</p>';
			Ui::form_open( 'logierp_wh_bins_generate', array( 'location' => $lid ) );
			echo '<table class="form-table">';
			self::bin_fields( $l, true );
			echo '</table>';
			Ui::form_close( 'Générer les emplacements', 'button' );
			echo '</div>';
		}

		if ( ! self::is_system( $l ) ) {
			echo '<div class="lw-box"><h2>Supprimer la zone</h2><p class="description">Possible uniquement si aucun stock (stock de départ ou session) ni scénario n\'utilise la zone. Les emplacements de la zone sont supprimés avec elle.</p>';
			Ui::button_form( 'logierp_wh_zone_delete', array( 'location' => $lid ), 'Supprimer la zone ' . $l['code'], 'button lw-danger', 'Supprimer définitivement la zone ' . $l['code'] . ' et ses emplacements ?' );
			echo '</div>';
		}
		Ui::footer();
	}

	private static function bin_fields( ?array $l, bool $required ): void {
		$code = $l ? $l['code'] : 'zone';
		$r    = $required ? ' required' : '';
		Ui::field( 'Allée', Ui::input( 'aisle', '', 'text', 'maxlength="4" pattern="[A-Za-z0-9]{1,4}" placeholder="A07"' . $r ), 'Lettres et chiffres, 4 caractères au maximum.' );
		Ui::field( 'Travées', Ui::input( 'bays', '10', 'number', 'min="1" max="60"' ) );
		Ui::field( 'Niveaux', Ui::input( 'levels', '00,01,02,03', 'text', 'maxlength="60" pattern="[0-9A-Za-z]{1,2}(,[0-9A-Za-z]{1,2})*"' ), 'Liste séparée par des virgules (00 = sol).' );
		Ui::field( 'Positions', Ui::input( 'positions', 'G,M,D', 'text', 'maxlength="30"' ), 'Gauche, milieu, droite. Vide = une seule position par niveau. Exemple de code : ' . $code . '-A07-01-00-G.' );
		Ui::field( 'Type d\'emplacement', Ui::select( 'bin_type', self::BIN_TYPES, 'PAL' ) );
		Ui::field( 'Charge maximale (kg)', Ui::input( 'max_weight', '1000', 'number', 'min="1" max="10000" step="any"' ), 'Charge admissible par emplacement (plaque de charge du rayonnage).' );
		Ui::field( 'Volume maximal (l)', Ui::input( 'max_volume', '1536', 'number', 'min="1" max="20000" step="any"' ), '1 palette EUR 1200 × 800 × 1600 mm ≈ 1 536 l.' );
		Ui::field( 'Longueur utile (mm)', Ui::input( 'length_mm', '1200', 'number', 'min="50" max="12000"' ), 'Plus grande longueur d\'article admise.' );
	}

	/** Formulaire de zone : création (null) ou modification. */
	private function zone_form( ?array $l ): void {
		$new = null === $l;
		if ( $new ) {
			Ui::header( 'Nouvelle zone de stockage', 'Définissez les critères de stockage : seuls les articles qui les respectent pourront y être rangés.' );
			echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::WH ) ) . '">← Entrepôt</a></p>';
			$l = array( 'code' => '', 'name' => '', 'zone_type' => 'STD', 'strategy' => 'CHAOTIC', 'profiles' => 'STD', 'adr_classes' => '', 'temp_min' => null, 'temp_max' => null, 'secured' => 0, 'allow_mixed' => 0, 'is_transit' => 0, 'rack_type' => '', 'notes' => '', 'map_x' => '', 'map_y' => '', 'map_w' => 200, 'map_h' => 120 );
		}
		$refill = get_transient( 'lw_zone_' . get_current_user_id() );
		if ( is_array( $refill ) && (int) ( $refill['id'] ?? -1 ) === (int) ( $l['id'] ?? 0 ) ) {
			$l = array_merge( $l, $refill['values'] );
			delete_transient( 'lw_zone_' . get_current_user_id() );
		}
		$system  = ! $new && self::is_system( $l );
		$transit = ! empty( $l['is_transit'] );
		echo '<div class="lw-box"><h2>' . ( $new ? 'Identification' : 'Paramètres et critères de stockage' ) . '</h2>';
		Ui::form_open( $new ? 'logierp_wh_zone_create' : 'logierp_wh_zone_save', $new ? array() : array( 'location' => (int) $l['id'] ) );
		echo '<table class="form-table">';
		if ( $new ) {
			Ui::field( 'Code de la zone *', Ui::input( 'code', (string) $l['code'], 'text', 'maxlength="3" pattern="[1-9][0-9]{2}" required placeholder="230"' ), 'Trois chiffres (100 à 999), unique. Il préfixe les codes d\'emplacement.' );
		}
		Ui::field( 'Nom *', Ui::input( 'name', (string) $l['name'], 'text', 'maxlength="120" required' ) );
		$types = self::ZONE_TYPES;
		if ( ! $transit ) {
			unset( $types['TRANSIT'] );
		}
		Ui::field( 'Type de zone', $system || $transit ? '<strong>' . esc_html( self::ZONE_TYPES[ $l['zone_type'] ] ?? $l['zone_type'] ) . '</strong>' : Ui::select( 'zone_type', $types, (string) $l['zone_type'] ), $system ? 'Zone utilisée par les processus et les scénarios modèles : son type est fixe.' : 'Prélèvement : servie en premier à la préparation et réapprovisionnée depuis la réserve. Production : bord de ligne.' );
		if ( $transit ) {
			Ui::field( 'Stratégie de rangement', '<strong>Sans emplacement (zone de transit)</strong>', 'Les zones de transit acceptent tous les articles le temps du contrôle, de l\'emballage ou de l\'expédition.' );
		} else {
			$strategies = self::STRATEGIES;
			unset( $strategies['NONE'] );
			Ui::field( 'Stratégie de rangement', Ui::select( 'strategy', $strategies, (string) $l['strategy'] ), 'Chaotique : tout emplacement libre compatible. Fixe : chaque article a sa case (à attribuer dans la fiche article).' );
			Ui::field( 'Stratégie de prélèvement', '<strong>FIFO / FEFO selon l\'article</strong>', 'Les articles à date limite sont prélevés en FEFO (premier périmé, premier sorti), les autres en FIFO.' );
		}
		Ui::field( 'Équipement', Ui::input( 'rack_type', (string) $l['rack_type'], 'text', 'maxlength="120" placeholder="Palettier 5 niveaux"' ) );
		Ui::field( 'Remarque affichée', Ui::input( 'notes', (string) $l['notes'], 'text', 'maxlength="255"' ) );
		echo '</table>';

		if ( ! $transit ) {
			echo '<h3>Critères de stockage</h3><p class="description">Un article n\'est accepté que s\'il respecte <strong>tous</strong> les critères de la zone. Ces contrôles s\'appliquent aux apprenants à chaque rangement.</p><table class="form-table">';
			$sel  = array_filter( array_map( 'trim', explode( ',', (string) $l['profiles'] ) ) );
			$html = '<div class="lw-checks">';
			foreach ( Dictionaries::profiles() as $code => $p ) {
				$html .= '<label><input type="checkbox" name="profiles[]" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, $sel, true ), true, false ) . '> ' . esc_html( $p['name'] ) . '</label>';
			}
			Ui::field( 'Profils de stockage admis *', $html . '</div>', 'Au moins un profil.' );
			Ui::field( 'Température', '<span class="lw-filters" style="margin:0">de <input type="text" inputmode="decimal" name="temp_min" value="' . esc_attr( null === $l['temp_min'] || '' === (string) $l['temp_min'] ? '' : Catalog::format_qty( (float) $l['temp_min'] ) ) . '" class="small-text" maxlength="6"> à <input type="text" inputmode="decimal" name="temp_max" value="' . esc_attr( null === $l['temp_max'] || '' === (string) $l['temp_max'] ? '' : Catalog::format_qty( (float) $l['temp_max'] ) ) . '" class="small-text" maxlength="6"> °C</span>', 'Vide = température ambiante non contrôlée. Un article réfrigéré (+2/+8 °C) n\'est accepté que si la plage de la zone est comprise dans la sienne.' );
			$adr  = array_filter( array_map( 'trim', explode( ',', (string) $l['adr_classes'] ) ) );
			$html = '<div class="lw-checks">';
			foreach ( MasterData::ADR as $code => $label ) {
				$html .= '<label><input type="checkbox" name="adr[]" value="' . esc_attr( (string) $code ) . '"' . checked( in_array( (string) $code, $adr, true ), true, false ) . '> ' . esc_html( $code . ' – ' . $label ) . '</label>';
			}
			Ui::field( 'Matières dangereuses admises (classes ADR)', $html . '</div>', 'Aucune case = aucune matière dangereuse. Respectez la séparation des classes incompatibles (ex. 3 et 5.1 jamais ensemble).' );
			Ui::field( 'Sécurisation', Ui::checkbox( 'secured', (bool) $l['secured'], 'Zone sous clé / accès contrôlé (obligatoire pour la haute valeur)' ) );
			Ui::field( 'Mélange', Ui::checkbox( 'allow_mixed', (bool) $l['allow_mixed'], 'Autoriser plusieurs articles ou lots dans un même emplacement' ) );
			echo '</table>';
			if ( ! $new ) {
				echo '<h3>Limites des emplacements</h3><p class="description">Facultatif : applique de nouvelles limites à <strong>tous</strong> les emplacements de la zone. Laissez vide pour ne rien changer.</p><table class="form-table">';
				Ui::field( 'Charge maximale (kg)', Ui::input( 'bin_max_weight', '', 'number', 'min="1" max="10000" step="any"' ) );
				Ui::field( 'Volume maximal (l)', Ui::input( 'bin_max_volume', '', 'number', 'min="1" max="20000" step="any"' ) );
				Ui::field( 'Longueur utile (mm)', Ui::input( 'bin_length', '', 'number', 'min="50" max="12000"' ) );
				echo '</table>';
			}
		}

		echo '<h3>Position sur le plan</h3><table class="form-table">';
		Ui::field( 'Position et taille', '<span class="lw-filters" style="margin:0">X <input type="number" name="map_x" value="' . esc_attr( (string) $l['map_x'] ) . '" class="small-text" min="0" max="5000"> Y <input type="number" name="map_y" value="' . esc_attr( (string) $l['map_y'] ) . '" class="small-text" min="0" max="5000"> largeur <input type="number" name="map_w" value="' . esc_attr( (string) $l['map_w'] ) . '" class="small-text" min="40" max="2000"> hauteur <input type="number" name="map_h" value="' . esc_attr( (string) $l['map_h'] ) . '" class="small-text" min="30" max="2000"></span>', $new ? 'Laissez X et Y vides pour placer la zone automatiquement sous le plan existant.' : 'Coordonnées du plan (environ 1 200 × 760).' );
		echo '</table>';

		if ( $new ) {
			echo '<h3>Premiers emplacements (facultatif)</h3><p class="description">Générez une première allée maintenant, ou plus tard depuis la fiche de la zone.</p><table class="form-table">';
			self::bin_fields( null, false );
			echo '</table>';
		}
		Ui::form_close( $new ? 'Créer la zone' : 'Enregistrer la zone' );
		if ( ! $new ) {
			echo '<p class="description">Si une modification rendait non conforme du stock déjà présent dans la zone (stock de départ ou session en cours), elle est refusée et les articles concernés sont listés.</p>';
		}
		echo '</div>';
		if ( $new ) {
			Ui::footer();
		}
	}

	/* ───────── Lecture et contrôle du formulaire ───────── */

	/** @return array{0:array<string,mixed>,1:string[]} */
	private static function read_zone( array $base ): array {
		$e    = array();
		$data = array(
			'name'      => Ui::post_str( 'name', 120 ),
			'rack_type' => Ui::post_str( 'rack_type', 120 ),
			'notes'     => Ui::post_str( 'notes', 255 ),
		);
		if ( '' === $data['name'] ) {
			$e[] = 'Le nom de la zone est obligatoire.';
		}
		foreach ( array( 'map_x' => array( 0, 5000 ), 'map_y' => array( 0, 5000 ), 'map_w' => array( 40, 2000 ), 'map_h' => array( 30, 2000 ) ) as $k => [ $min, $max ] ) {
			$raw = Ui::post_str( $k, 6 );
			if ( '' === $raw ) {
				$data[ $k ] = null;
				continue;
			}
			$data[ $k ] = max( $min, min( $max, (int) $raw ) );
		}
		if ( ! empty( $base['is_transit'] ) ) {
			return array( $data, $e );
		}
		if ( ! WarehousePage::is_system_code( (string) ( $base['code'] ?? '' ) ) ) {
			$type = Ui::post_str( 'zone_type', 10 );
			if ( ! isset( self::ZONE_TYPES[ $type ] ) || 'TRANSIT' === $type ) {
				$e[] = 'Type de zone invalide.';
			}
			$data['zone_type'] = $type;
		}
		$st = Ui::post_str( 'strategy', 10 );
		if ( ! in_array( $st, array( 'CHAOTIC', 'FIXED' ), true ) ) {
			$e[] = 'Stratégie de rangement invalide.';
		}
		$data['strategy'] = $st;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant (Ui::guard).
		$profiles = isset( $_POST['profiles'] ) && is_array( $_POST['profiles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['profiles'] ) ) : array();
		$adr      = isset( $_POST['adr'] ) && is_array( $_POST['adr'] ) ? array_map( static fn( $v ) => preg_replace( '/[^0-9.]/', '', (string) $v ), wp_unslash( $_POST['adr'] ) ) : array();
		// phpcs:enable
		$profiles = array_values( array_intersect( array_keys( Dictionaries::profiles() ), array_map( 'strtoupper', $profiles ) ) );
		$adr      = array_values( array_intersect( array_map( 'strval', array_keys( MasterData::ADR ) ), $adr ) );
		if ( ! $profiles ) {
			$e[] = 'Cochez au moins un profil de stockage admis.';
		}
		foreach ( Dictionaries::incompatible_pairs() as [ $a, $b ] ) {
			if ( in_array( $a, $adr, true ) && in_array( $b, $adr, true ) ) {
				$e[] = sprintf( 'Classes ADR %s et %s incompatibles : elles ne peuvent pas être stockées dans la même zone.', $a, $b );
			}
		}
		if ( $adr && ! array_intersect( $profiles, array( 'CHEM', 'BATT' ) ) ) {
			$e[] = 'Des classes ADR sont admises : cochez aussi le profil Chimie ou Batteries lithium.';
		}
		$tmin = Ui::post_str( 'temp_min', 6 );
		$tmax = Ui::post_str( 'temp_max', 6 );
		$data['temp_min'] = null;
		$data['temp_max'] = null;
		if ( '' !== $tmin || '' !== $tmax ) {
			$a = MasterData::parse_dec( ltrim( str_replace( '−', '-', $tmin ), '+' ) );
			$b = MasterData::parse_dec( ltrim( str_replace( '−', '-', $tmax ), '+' ) );
			if ( null === $a || null === $b || $a > $b || $a < -40 || $b > 60 ) {
				$e[] = 'Température : indiquez une plage valide (minimum ≤ maximum, entre −40 et +60 °C), ou laissez les deux champs vides.';
			} else {
				$data['temp_min'] = $a;
				$data['temp_max'] = $b;
			}
		}
		$data['profiles']    = implode( ',', $profiles );
		$data['adr_classes'] = implode( ',', $adr );
		$data['secured']     = Ui::post_bool( 'secured' ) ? 1 : 0;
		$data['allow_mixed'] = Ui::post_bool( 'allow_mixed' ) ? 1 : 0;
		return array( $data, $e );
	}

	public static function is_system_code( string $code ): bool {
		return '' !== $code && in_array( $code, self::system_codes(), true );
	}

	/**
	 * Stock (départ + sessions en cours) qui deviendrait non conforme avec les nouveaux critères.
	 *
	 * @return string[]
	 */
	private static function conflicts( array $loc, ?array $limits ): array {
		global $wpdb;
		$st  = Db::t( 'stock' );
		$se  = Db::t( 'session' );
		$bt  = Db::t( 'bin' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.material_id, s.bin_id, s.session_id, SUM(s.qty) qty FROM {$st} s LEFT JOIN {$se} x ON x.id = s.session_id WHERE s.storage_location_id = %d AND s.qty > 0 AND (s.session_id = 0 OR x.status = 'EN_COURS') GROUP BY s.material_id, s.bin_id, s.session_id", (int) $loc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		$seen = array();
		$occ  = array();
		foreach ( $rows as $r ) {
			$m = Catalog::material( (int) $r['material_id'] );
			if ( ! $m ) {
				continue;
			}
			if ( ! isset( $seen[ $m['id'] ] ) ) {
				$seen[ $m['id'] ] = true;
				foreach ( Rules::check_location( $m, $loc ) as $msg ) {
					$out[] = $m['material_no'] . ' ' . $m['designation'] . ' : ' . $msg;
				}
			}
			$k = $r['session_id'] . ':' . $r['bin_id'];
			$occ[ $k ] = $occ[ $k ] ?? array( 'w' => 0.0, 'v' => 0.0, 'len' => 0, 'bin' => (int) $r['bin_id'] );
			$occ[ $k ]['w']  += (float) $r['qty'] * (float) $m['gross_weight'];
			$occ[ $k ]['v']  += (float) $r['qty'] * (float) $m['volume_l'];
			$occ[ $k ]['len'] = max( $occ[ $k ]['len'], (int) $m['length_mm'] );
		}
		if ( $limits ) {
			foreach ( $occ as $o ) {
				$code = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$bt} WHERE id = %d", $o['bin'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( null !== $limits['max_weight'] && $o['w'] > $limits['max_weight'] + 0.001 ) {
					$out[] = sprintf( 'Emplacement %s : %s kg déjà stockés dépasseraient la nouvelle charge maximale.', $code, Catalog::format_qty( round( $o['w'], 1 ) ) );
				}
				if ( null !== $limits['max_volume_l'] && $o['v'] > $limits['max_volume_l'] + 0.001 ) {
					$out[] = sprintf( 'Emplacement %s : %s l déjà occupés dépasseraient le nouveau volume maximal.', $code, Catalog::format_qty( round( $o['v'], 1 ) ) );
				}
				if ( null !== $limits['length_mm'] && $o['len'] > $limits['length_mm'] ) {
					$out[] = sprintf( 'Emplacement %s : un article de %d mm y est stocké, plus long que la nouvelle longueur utile.', $code, $o['len'] );
				}
			}
		}
		// Emplacements fixes déjà attribués : l'article doit rester admis.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT fixed_material_id FROM {$bt} WHERE storage_location_id = %d AND fixed_material_id > 0", (int) $loc['id'] ) ) as $mid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$m = Catalog::material( (int) $mid );
			if ( $m && ! isset( $seen[ $m['id'] ] ) && Rules::check_location( $m, $loc ) ) {
				$out[] = $m['material_no'] . ' ' . $m['designation'] . ' : emplacement fixe dans cette zone, l\'article ne serait plus admis.';
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ───────── Actions ───────── */

	public function handle_zone_create(): void {
		Ui::guard( 'logierp_wh_zone_create', Capabilities::MASTER_DATA );
		global $wpdb;
		$code = Ui::post_str( 'code', 3 );
		[ $data, $e ] = self::read_zone( array( 'code' => '' ) );
		if ( ! preg_match( '/^[1-9]\d{2}$/', $code ) ) {
			$e[] = 'Code de zone : trois chiffres de 100 à 999.';
		} elseif ( Catalog::location_by_code( $code ) ) {
			$e[] = sprintf( 'La zone %s existe déjà.', $code );
		}
		$bins = self::read_bins( false );
		if ( is_string( $bins ) ) {
			$e[] = $bins;
		}
		if ( $e ) {
			set_transient( 'lw_zone_' . get_current_user_id(), array( 'id' => 0, 'values' => array_merge( $data, array( 'code' => $code ) ) ), 600 );
			Ui::redirect( self::WH, array( 'new' => '1' ), 'error', 'Zone non créée : corrigez les points suivants.', $e );
		}
		$locs  = Catalog::locations();
		$first = reset( $locs );
		if ( null === $data['map_x'] || null === $data['map_y'] ) {
			$bottom = 0;
			foreach ( $locs as $l ) {
				$bottom = max( $bottom, (int) $l['map_y'] + (int) $l['map_h'] );
			}
			$row = array_filter( $locs, static fn( $l ) => (int) $l['map_y'] >= $bottom + 20 );
			$x   = 20;
			foreach ( $row as $l ) {
				$x = max( $x, (int) $l['map_x'] + (int) $l['map_w'] + 20 );
			}
			$data['map_x'] = $x;
			$data['map_y'] = $bottom + 20;
		}
		$data['map_w'] = $data['map_w'] ?? 200;
		$data['map_h'] = $data['map_h'] ?? 120;
		$sort          = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(sort),0) + 1 FROM ' . Db::t( 'storage_location' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$nulls         = null === $data['temp_min'];
		if ( $nulls ) {
			unset( $data['temp_min'], $data['temp_max'] );
		}
		$lid = Db::insert(
			'storage_location',
			array_merge(
				$data,
				array(
					'site_id'       => (int) $first['site_id'],
					'code'          => $code,
					'location_mode' => 'DETAILED',
					'removal'       => 'FIFO',
					'is_transit'    => 0,
					'sort'          => $sort,
				)
			)
		);
		Catalog::reset_cache();
		Audit::log( 'zone_create', 'storage_location', $lid );
		$msg = sprintf( 'Zone %s créée.', $code );
		if ( is_array( $bins ) ) {
			$n    = self::generate_bins( Catalog::location( $lid ), $bins );
			$msg .= sprintf( ' %d emplacement(s) créé(s) dans l\'allée %s.', $n, $bins['aisle'] );
		} else {
			$msg .= ' Ajoutez maintenant des emplacements.';
		}
		Ui::redirect( self::WH, array( 'location' => $lid ), 'success', $msg );
	}

	public function handle_zone_save(): void {
		Ui::guard( 'logierp_wh_zone_save', Capabilities::MASTER_DATA );
		global $wpdb;
		$lid = Ui::post_int( 'location' );
		$l   = Catalog::location( $lid );
		if ( ! $l ) {
			wp_die( esc_html__( 'Zone introuvable.', 'logierp' ) );
		}
		[ $data, $e ] = self::read_zone( $l );
		$limits = null;
		if ( empty( $l['is_transit'] ) ) {
			$limits = array(
				'max_weight'   => '' !== Ui::post_str( 'bin_max_weight', 8 ) ? max( 1.0, min( 10000.0, Ui::post_float( 'bin_max_weight' ) ) ) : null,
				'max_volume_l' => '' !== Ui::post_str( 'bin_max_volume', 8 ) ? max( 1.0, min( 20000.0, Ui::post_float( 'bin_max_volume' ) ) ) : null,
				'length_mm'    => '' !== Ui::post_str( 'bin_length', 8 ) ? max( 50, min( 12000, Ui::post_int( 'bin_length' ) ) ) : null,
			);
			if ( null === $limits['max_weight'] && null === $limits['max_volume_l'] && null === $limits['length_mm'] ) {
				$limits = null;
			}
		}
		if ( ! $e ) {
			$candidate = array_merge( $l, $data );
			$conflicts = self::conflicts( $candidate, $limits );
			if ( $conflicts ) {
				$e[] = sprintf( 'Modification refusée : %d point(s) de stock deviendraient non conformes. Videz d\'abord ces emplacements (ou adaptez les critères).', count( $conflicts ) );
				$e   = array_merge( $e, array_slice( $conflicts, 0, 15 ) );
				if ( count( $conflicts ) > 15 ) {
					$e[] = sprintf( '… et %d autre(s).', count( $conflicts ) - 15 );
				}
			}
		}
		if ( $e ) {
			set_transient( 'lw_zone_' . get_current_user_id(), array( 'id' => $lid, 'values' => array_filter( $data, static fn( $v ) => null !== $v ) ), 600 );
			Ui::redirect( self::WH, array( 'location' => $lid ), 'error', 'Zone non enregistrée.', $e );
		}
		foreach ( array( 'map_x', 'map_y', 'map_w', 'map_h' ) as $k ) {
			if ( null === $data[ $k ] ) {
				unset( $data[ $k ] );
			}
		}
		$null_temp = array_key_exists( 'temp_min', $data ) && null === $data['temp_min'];
		if ( $null_temp ) {
			unset( $data['temp_min'], $data['temp_max'] );
		}
		Db::transaction(
			static function () use ( $wpdb, $lid, $data, $null_temp, $limits ) {
				Db::update( 'storage_location', $data, array( 'id' => $lid ) );
				if ( $null_temp ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'storage_location' ) . ' SET temp_min = NULL, temp_max = NULL WHERE id = %d', $lid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
				if ( $limits ) {
					$set = array_filter( $limits, static fn( $v ) => null !== $v );
					$wpdb->update( Db::t( 'bin' ), $set, array( 'storage_location_id' => $lid ) );
				}
			}
		);
		Catalog::reset_cache();
		Audit::log( 'zone_update', 'storage_location', $lid );
		Ui::redirect( self::WH, array( 'location' => $lid ), 'success', 'Zone enregistrée.' . ( $limits ? ' Limites appliquées à tous ses emplacements.' : '' ) );
	}

	public function handle_zone_delete(): void {
		Ui::guard( 'logierp_wh_zone_delete', Capabilities::MASTER_DATA );
		global $wpdb;
		$lid = Ui::post_int( 'location' );
		$l   = Catalog::location( $lid );
		if ( ! $l ) {
			wp_die( esc_html__( 'Zone introuvable.', 'logierp' ) );
		}
		if ( self::is_system( $l ) ) {
			Ui::redirect( self::WH, array( 'location' => $lid ), 'error', 'Cette zone fait partie de l\'entrepôt de base : elle ne peut pas être supprimée.' );
		}
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'stock' ) . ' WHERE storage_location_id = %d AND qty > 0', $lid ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'scenario_initial_stock' ) . ' WHERE storage_location_id = %d', $lid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $used ) {
			Ui::redirect( self::WH, array( 'location' => $lid ), 'error', 'La zone contient du stock ou est utilisée par un scénario : suppression impossible.' );
		}
		Db::transaction(
			static function () use ( $wpdb, $lid ) {
				$wpdb->delete( Db::t( 'bin' ), array( 'storage_location_id' => $lid ) );
				$wpdb->delete( Db::t( 'aisle' ), array( 'storage_location_id' => $lid ) );
				$wpdb->delete( Db::t( 'storage_location' ), array( 'id' => $lid ) );
			}
		);
		Catalog::reset_cache();
		Audit::log( 'zone_delete', 'storage_location', $lid, array( 'code' => $l['code'] ) );
		Ui::redirect( self::WH, array(), 'success', sprintf( 'Zone %s supprimée.', $l['code'] ) );
	}

	public function handle_bin_block(): void {
		global $wpdb;
		Ui::guard( 'logierp_wh_bin_block', Capabilities::MASTER_DATA );
		$lid  = Ui::post_int( 'location' );
		$mode = Ui::post_str( 'mode', 4 );
		$in   = in_array( $mode, array( 'in', 'both' ), true ) ? 1 : 0;
		$out  = 'both' === $mode ? 1 : 0;
		$wpdb->update( Db::t( 'bin' ), array( 'blocked_in' => $in, 'blocked_out' => $out, 'block_reason' => $in ? ( Ui::post_str( 'reason', 120 ) ?: 'Bloqué par l\'enseignant' ) : '' ), array( 'id' => Ui::post_int( 'bin_id' ), 'storage_location_id' => $lid ) );
		Ui::redirect( self::WH, array( 'location' => $lid ), 'success', $in ? 'Emplacement bloqué.' : 'Emplacement débloqué.' );
	}

	/**
	 * Paramètres de génération d'allée. null = rien demandé (allée vide et facultative), string = erreur.
	 *
	 * @return array|string|null
	 */
	private static function read_bins( bool $required ) {
		$aisle = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', Ui::post_str( 'aisle', 4 ) ) );
		if ( '' === $aisle ) {
			return $required ? 'Indiquez le code de l\'allée.' : null;
		}
		$levels = array_values( array_filter( array_map( static fn( $v ) => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $v ) ), explode( ',', Ui::post_str( 'levels', 60 ) ) ), static fn( $v ) => '' !== $v && strlen( $v ) <= 2 ) );
		$pos    = array_values( array_filter( array_map( static fn( $v ) => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $v ) ), explode( ',', Ui::post_str( 'positions', 30 ) ) ), static fn( $v ) => '' !== $v && strlen( $v ) <= 2 ) ) ?: array( '' );
		$type   = Ui::post_str( 'bin_type', 12 );
		$bays   = max( 1, min( 60, Ui::post_int( 'bays' ) ) );
		if ( ! $levels || ! isset( self::BIN_TYPES[ $type ] ) ) {
			return 'Emplacements : niveaux ou type invalides.';
		}
		if ( $bays * count( $levels ) * count( $pos ) > 2000 ) {
			return 'Au maximum 2 000 emplacements par génération.';
		}
		return array(
			'aisle'  => $aisle,
			'bays'   => $bays,
			'levels' => $levels,
			'pos'    => $pos,
			'type'   => $type,
			'weight' => max( 1.0, min( 10000.0, Ui::post_float( 'max_weight' ) ?: 1000.0 ) ),
			'vol'    => max( 1.0, min( 20000.0, Ui::post_float( 'max_volume' ) ?: 1536.0 ) ),
			'len'    => max( 50, min( 12000, Ui::post_int( 'length_mm' ) ?: 1200 ) ),
		);
	}

	public static function generate_bins( array $l, array $p ): int {
		global $wpdb;
		$bt  = Db::t( 'bin' );
		$seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(pick_sequence),0) FROM {$bt} WHERE storage_location_id = %d", (int) $l['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$aid = Db::insert( 'aisle', array( 'storage_location_id' => (int) $l['id'], 'code' => $p['aisle'], 'rack_type' => mb_substr( (string) $l['rack_type'], 0, 120 ) ) );
		$n   = 0;
		for ( $b = 1; $b <= $p['bays']; $b++ ) {
			$bay = str_pad( (string) $b, 2, '0', STR_PAD_LEFT );
			foreach ( $p['levels'] as $lv ) {
				foreach ( $p['pos'] as $ps ) {
					$code = implode( '-', array_filter( array( $l['code'], $p['aisle'], $bay, $lv, $ps ), static fn( $x ) => '' !== $x ) );
					$ok   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$bt} (storage_location_id, aisle_id, code, aisle, bay, level, position, bin_type, max_weight, max_volume_l, length_mm, pick_sequence) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %f, %f, %d, %d)", (int) $l['id'], $aid, $code, $p['aisle'], $bay, $lv, $ps, $p['type'], $p['weight'], $p['vol'], $p['len'], $seq + $n + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$n   += $ok ? 1 : 0;
				}
			}
		}
		Catalog::reset_cache();
		Audit::log( 'bins_generate', 'storage_location', (int) $l['id'], array( 'aisle' => $p['aisle'], 'count' => $n ) );
		return $n;
	}

	public function handle_bins_generate(): void {
		Ui::guard( 'logierp_wh_bins_generate', Capabilities::MASTER_DATA );
		$lid = Ui::post_int( 'location' );
		$l   = Catalog::location( $lid );
		if ( ! $l || 'NONE' === $l['strategy'] ) {
			wp_die( esc_html__( 'Zone introuvable.', 'logierp' ) );
		}
		$p = self::read_bins( true );
		if ( ! is_array( $p ) ) {
			Ui::redirect( self::WH, array( 'location' => $lid ), 'error', (string) $p );
		}
		$n = self::generate_bins( $l, $p );
		Ui::redirect( self::WH, array( 'location' => $lid ), 'success', sprintf( '%d emplacement(s) créé(s) dans l\'allée %s.', $n, $p['aisle'] ) );
	}
}
