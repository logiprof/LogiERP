<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\MasterData;
use LogiERP\Security\Audit;
use LogiERP\Security\RateLimiter;
use LogiERP\Support\Xlsx;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Données de base : articles, fournisseurs et clients (consultation, saisie, import / export Excel).
 * Les modifications s'appliquent au jeu de base, donc aux sessions qui démarrent ensuite.
 */
final class DataPage {

	private const PAGE = 'logierp-data';
	private const PER  = 50;
	private const TABS = array( 'materials' => 'material', 'suppliers' => 'supplier', 'customers' => 'customer' );
	private const MAX_UPLOAD = 8 * 1024 * 1024;

	public function register(): void {
		foreach ( array( 'save', 'fixed_bin', 'unfix_bin', 'import' ) as $a ) {
			add_action( 'admin_post_logierp_md_' . $a, array( $this, 'handle_' . $a ) );
		}
		add_action( 'admin_post_logierp_md_xlsx', array( $this, 'handle_xlsx' ) );
		( new WarehousePage() )->register();
	}

	private static function tabs( string $current ): void {
		echo '<nav class="nav-tab-wrapper lw-tabs">';
		foreach ( array( 'materials' => 'Articles', 'suppliers' => 'Fournisseurs', 'customers' => 'Clients' ) as $k => $l ) {
			echo '<a class="nav-tab' . ( $k === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( self::PAGE, array( 'tab' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
	}

	public static function pager( int $total, int $page, array $args, string $slug, int $per = self::PER ): void {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p class="lw-pager">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( Ui::url( $slug, array_merge( $args, array( 'lw_p' => $page - 1 ) ) ) ) . '">← Précédent</a> ';
		}
		echo esc_html( sprintf( 'Page %d / %d · %d éléments', $page, $pages, $total ) );
		if ( $page < $pages ) {
			echo ' <a class="button" href="' . esc_url( Ui::url( $slug, array_merge( $args, array( 'lw_p' => $page + 1 ) ) ) ) . '">Suivant →</a>';
		}
		echo '</p>';
	}

	/* ───────── Affichage ───────── */

	public function render(): void {
		$tab  = Ui::get_str( 'tab' );
		$tab  = isset( self::TABS[ $tab ] ) ? $tab : 'materials';
		$kind = self::TABS[ $tab ];
		if ( ! Catalog::dataset() ) {
			Ui::header( 'Données de base' );
			Ui::seed_box();
			Ui::footer();
			return;
		}
		if ( Ui::get_int( 'id' ) || '1' === Ui::get_str( 'new' ) ) {
			$this->record( $tab, $kind, Ui::get_int( 'id' ) );
			return;
		}
		Ui::header( 'Données de base', 'Jeu de base commun à tous les scénarios : les clients sont tous en Suisse ; les fournisseurs sont en Suisse ou en Europe (livraisons avec lettre de voiture CMR). Une modification s\'applique aux sessions qui démarrent après elle.' );
		self::tabs( $tab );
		echo '<p class="lw-toolbar-admin"><a class="button button-primary" href="' . esc_url( Ui::url( self::PAGE, array( 'tab' => $tab, 'new' => '1' ) ) ) . '">+ ' . esc_html( array( 'material' => 'Nouvel article', 'supplier' => 'Nouveau fournisseur', 'customer' => 'Nouveau client' )[ $kind ] ) . '</a></p>';
		'material' === $kind ? $this->materials() : $this->partners( $tab, $kind );
		$this->excel_box( $tab, $kind );
		Ui::footer();
	}

	private function excel_box( string $tab, string $kind ): void {
		$label = mb_strtolower( MasterData::label( $kind ) );
		echo '<div class="lw-box yellow"><h2>Import et export Excel</h2>';
		echo '<p>Téléchargez le modèle, remplissez une ligne par enregistrement, puis importez le fichier. La feuille « Aide » du modèle décrit chaque colonne et ses valeurs autorisées ; la feuille « Exemples » montre des lignes correctes.</p>';
		echo '<p class="lw-toolbar-admin">';
		echo '<a class="button" href="' . esc_url( self::xlsx_url( $kind, 'template' ) ) . '">Télécharger le modèle Excel (' . esc_html( $label ) . ')</a> ';
		echo '<a class="button" href="' . esc_url( self::xlsx_url( $kind, 'export' ) ) . '">Exporter les ' . esc_html( $label ) . ' actuels (Excel)</a></p>';
		Ui::form_open( 'logierp_md_import', array( 'kind' => $kind, 'tab' => $tab ), 'enctype="multipart/form-data"' );
		echo '<table class="form-table">';
		Ui::field( 'Fichier', '<input type="file" name="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>', 'Format .xlsx (Excel), 8 Mo et ' . Xlsx::MAX_ROWS . ' lignes au maximum. Un numéro existant met l\'enregistrement à jour, un numéro vide crée un nouvel enregistrement.' );
		Ui::field( 'En cas d\'erreur', Ui::checkbox( 'skip_errors', false, 'Importer quand même les lignes correctes et ignorer les lignes en erreur' ), 'Par défaut, rien n\'est importé tant qu\'une ligne contient une erreur : vous corrigez le fichier et vous recommencez.' );
		echo '</table>';
		Ui::form_close( 'Importer' );
		echo '</div>';
	}

	public static function xlsx_url( string $kind, string $mode ): string {
		$args = array( 'action' => 'logierp_md_xlsx', 'kind' => $kind, 'mode' => $mode );
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'logierp_md_xlsx_' . $kind . '_' . $mode );
	}

	private function materials(): void {
		global $wpdb;
		$q       = Ui::get_str( 'q' );
		$profile = Ui::get_str( 'profile' );
		$page    = max( 1, Ui::get_int( 'lw_p' ) );
		$t       = Db::t( 'material' );
		$where   = $wpdb->prepare( 'dataset_id = %d AND deleted_at IS NULL', Catalog::dataset() );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$where .= $wpdb->prepare( ' AND (material_no LIKE %s OR designation LIKE %s OR ean LIKE %s)', $like, $like, $like );
		}
		$profiles = \LogiERP\Seeder\Dictionaries::profiles();
		if ( isset( $profiles[ $profile ] ) ) {
			$where .= $wpdb->prepare( ' AND profile_code = %s', $profile );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, material_no, designation, profile_code, base_uom, gross_weight, std_price, abc, batch_managed, shelf_life_days, adr_class, status FROM {$t} WHERE {$where} ORDER BY material_no LIMIT %d OFFSET %d", self::PER, ( $page - 1 ) * self::PER ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opts  = array( '' => 'Tous les profils' );
		foreach ( $profiles as $k => $p ) {
			$opts[ $k ] = $p['name'];
		}
		Ui::get_form_open( self::PAGE, array( 'tab' => 'materials' ) );
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="N°, désignation, EAN"> ' . Ui::select( 'profile', $opts, $profile ) . ' <button class="button">Rechercher</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="lw-table-scroll"><table class="widefat striped lw-table-admin"><thead><tr><th>Article</th><th>Désignation</th><th>Profil</th><th>UQB</th><th>Poids brut</th><th>Prix std</th><th>ABC</th><th>Lot / DLC</th><th>ADR</th><th>Statut</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="10">Aucun article ne correspond.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( self::PAGE, array( 'tab' => 'materials', 'id' => (int) $r['id'] ) ) ) . '"><strong>' . esc_html( $r['material_no'] ) . '</strong></a></td><td>' . esc_html( $r['designation'] ) . '</td><td>' . esc_html( $profiles[ $r['profile_code'] ]['name'] ?? $r['profile_code'] ) . '</td><td>' . esc_html( $r['base_uom'] ) . '</td><td>' . esc_html( Catalog::format_qty( (float) $r['gross_weight'] ) ) . ' kg</td><td>' . esc_html( number_format( (float) $r['std_price'], 2, '.', '\'' ) ) . '</td><td>' . esc_html( $r['abc'] ) . '</td><td>' . esc_html( (int) $r['batch_managed'] ? 'Lot' . ( (int) $r['shelf_life_days'] ? ' · ' . (int) $r['shelf_life_days'] . ' j' : '' ) : '—' ) . '</td><td>' . esc_html( $r['adr_class'] ?: '—' ) . '</td><td>' . esc_html( MasterData::STATUSES[ $r['status'] ] ?? $r['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::pager( $total, $page, array( 'tab' => 'materials', 'q' => $q, 'profile' => $profile ), self::PAGE );
	}

	private function partners( string $tab, string $kind ): void {
		global $wpdb;
		$q     = Ui::get_str( 'q' );
		$page  = max( 1, Ui::get_int( 'lw_p' ) );
		$sup   = 'supplier' === $kind;
		$t     = Db::t( $kind );
		$no    = MasterData::number_key( $kind );
		$where = $wpdb->prepare( 'dataset_id = %d', Catalog::dataset() );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$where .= $wpdb->prepare( " AND ({$no} LIKE %s OR name LIKE %s OR city LIKE %s OR zip LIKE %s)", $like, $like, $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$no} LIMIT %d OFFSET %d", self::PER, ( $page - 1 ) * self::PER ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Ui::get_form_open( self::PAGE, array( 'tab' => $tab ) );
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="N°, nom, NPA, localité"> <button class="button">Rechercher</button></form>';
		echo '<div class="lw-table-scroll"><table class="widefat striped lw-table-admin"><thead><tr><th>N°</th><th>Nom</th><th>' . ( $sup ? 'Spécialité' : 'Type' ) . '</th><th>Code postal / localité</th><th>' . ( $sup ? 'Délai / fiabilité' : 'Priorité / transporteur' ) . '</th><th>Remarque</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Aucun enregistrement ne correspond.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$flag = array();
			if ( $sup && (int) $r['is_problematic'] ) {
				$flag[] = 'Fournisseur à problèmes';
			}
			if ( ! $sup && (int) $r['blocked'] ) {
				$flag[] = 'Bloqué';
			}
			if ( ! (int) $r['active'] ) {
				$flag[] = 'Inactif';
			}
			echo '<tr><td><a href="' . esc_url( Ui::url( self::PAGE, array( 'tab' => $tab, 'id' => (int) $r['id'] ) ) ) . '"><strong>' . esc_html( $r[ $no ] ) . '</strong></a></td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $sup ? $r['specialty'] : $r['type'] ) . '</td><td>' . esc_html( ( $sup && 'CH' !== $r['country'] ? $r['country'] . '-' : '' ) . $r['zip'] . ' ' . $r['city'] ) . '</td><td>' . esc_html( $sup ? $r['lead_time_days'] . ' j · ' . $r['reliability'] . ' %' : $r['priority'] . ' · ' . ( $r['carrier_code'] ?: '—' ) ) . '</td><td>' . esc_html( implode( ' · ', $flag ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::pager( $total, $page, array( 'tab' => $tab, 'q' => $q ), self::PAGE );
	}

	/** Fiche complète (création ou modification), générée à partir de la définition des colonnes. */
	private function record( string $tab, string $kind, int $id ): void {
		global $wpdb;
		$current = null;
		if ( $id ) {
			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( $kind ) . ' WHERE id = %d AND dataset_id = %d', $id, Catalog::dataset() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $current ) {
				wp_die( esc_html__( 'Enregistrement introuvable.', 'logierp' ) );
			}
		}
		$cols   = MasterData::columns( $kind );
		$key    = MasterData::number_key( $kind );
		$values = $current ? MasterData::to_values( $kind, $current ) : array();
		$refill = get_transient( 'lw_form_' . get_current_user_id() );
		if ( is_array( $refill ) && ( $refill['kind'] ?? '' ) === $kind && (int) ( $refill['id'] ?? -1 ) === $id ) {
			$values = array_merge( $values, (array) $refill['values'] );
			delete_transient( 'lw_form_' . get_current_user_id() );
		}
		$locked = 'material' === $kind && $current && MasterData::material_in_use( $id );
		$title  = $current ? $current[ $key ] . ' – ' . ( $current['designation'] ?? $current['name'] ) : array( 'material' => 'Nouvel article', 'supplier' => 'Nouveau fournisseur', 'customer' => 'Nouveau client' )[ $kind ];
		$lead   = 'material' === $kind && $current ? 'Profil : ' . Catalog::profile_label( $current['profile_code'] ) . ( $current['adr_class'] ? ' · ADR classe ' . $current['adr_class'] . ' · ONU ' . $current['un_number'] : '' ) : ( 'material' === $kind ? '' : ( 'supplier' === $kind ? 'Fournisseur en Suisse ou en Europe : le code postal et le téléphone sont contrôlés selon le pays.' : 'Client situé en Suisse (NPA à 4 chiffres).' ) );
		Ui::header( $title, $lead );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::PAGE, array( 'tab' => $tab ) ) ) . '">← ' . esc_html( MasterData::label( $kind ) ) . '</a></p>';
		echo '<div class="lw-box">';
		if ( $locked ) {
			echo '<p class="description">Cet article a du stock ou est utilisé dans un scénario : ses caractéristiques physiques (profil, dimensions, poids, lot, ADR) sont figées pour que le stock existant reste conforme. Les données de gestion restent modifiables.</p>';
		}
		Ui::form_open( 'logierp_md_save', array( 'kind' => $kind, 'id' => $id, 'tab' => $tab ) );
		echo '<table class="form-table">';
		foreach ( $cols as $k => $c ) {
			if ( $k === $key && $current ) {
				Ui::field( $c[0], '<strong>' . esc_html( $current[ $key ] ) . '</strong>' );
				continue;
			}
			$val      = (string) ( $values[ $k ] ?? '' );
			$disabled = $locked && in_array( $k, MasterData::STRUCTURAL, true ) ? ' disabled' : '';
			$req      = $c[1] && ! $current ? ' required' : '';
			$name     = 'f[' . $k . ']';
			switch ( $c[2] ) {
				case 'list':
					$opts = array( '' => $c[1] ? '— choisir —' : '— par défaut —' );
					foreach ( $c[4] as $code => $label ) {
						$opts[ (string) $code ] = (string) $code === (string) $label ? (string) $label : $code . ' – ' . $label;
					}
					$html = str_replace( '<select ', '<select' . $disabled . $req . ' ', Ui::select( $name, $opts, $val ) );
					break;
				case 'bool':
					$html = str_replace( '<select ', '<select' . $disabled . ' ', Ui::select( $name, array( '' => '— par défaut —', 'OUI' => 'Oui', 'NON' => 'Non' ), $val ) );
					break;
				case 'int':
				case 'dec':
					$html = '<input type="text" inputmode="decimal" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="small-text lw-num"' . $disabled . $req . ' maxlength="20">';
					break;
				default:
					$html = Ui::input( $name, $val, 'text', 'maxlength="190"' . $disabled . $req );
			}
			Ui::field( $c[0] . ( $c[1] ? ' *' : '' ), $html, $c[3] );
		}
		echo '</table>';
		Ui::form_close( $current ? 'Enregistrer' : 'Créer' );
		echo '<p class="description">* obligatoire. Les règles appliquées sont les mêmes que pour l\'import Excel.</p></div>';

		if ( 'material' === $kind && $current ) {
			LabelsPage::quick_article( (string) $current['material_no'], (string) $current['ean'] );
			$this->fixed_bins( $id );
		}
		Ui::footer();
	}

	private function fixed_bins( int $id ): void {
		global $wpdb;
		$bt    = Db::t( 'bin' );
		$fixed = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, code, min_qty, max_qty FROM {$bt} WHERE fixed_material_id = %d ORDER BY code", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>Emplacements fixes (lotissement par case)</h2><p class="description">Dans une zone en stratégie « Emplacement fixe », l\'article ne peut être rangé que dans sa case. Le minimum déclenche le réapprovisionnement depuis la réserve, le maximum limite la quantité rangée.</p><table class="widefat striped"><thead><tr><th>Emplacement</th><th>Min.</th><th>Max.</th><th></th></tr></thead><tbody>';
		if ( ! $fixed ) {
			echo '<tr><td colspan="4">Aucun : stockage chaotique.</td></tr>';
		}
		foreach ( $fixed as $b ) {
			echo '<tr><td><code>' . esc_html( $b['code'] ) . '</code></td><td>' . esc_html( Catalog::format_qty( (float) $b['min_qty'] ) ) . '</td><td>' . esc_html( Catalog::format_qty( (float) $b['max_qty'] ) ) . '</td><td>';
			Ui::button_form( 'logierp_md_unfix_bin', array( 'id' => $id, 'bin_id' => (int) $b['id'] ), 'Libérer', 'button button-small lw-danger', 'Libérer cet emplacement fixe ?' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::form_open( 'logierp_md_fixed_bin', array( 'id' => $id ) );
		echo '<p class="lw-filters"><input type="text" name="bin" required maxlength="40" placeholder="ex. 400-P01-01-01" class="regular-text"> min <input type="number" name="min_qty" step="any" min="0" value="5" style="width:80px"> max <input type="number" name="max_qty" step="any" min="0" value="30" style="width:80px"> ';
		Ui::form_close( 'Attribuer', 'button' );
		echo '</p></div>';
	}

	/* ───────── Actions ───────── */

	public function handle_save(): void {
		Ui::guard( 'logierp_md_save', Capabilities::MASTER_DATA );
		global $wpdb;
		$kind = Ui::post_str( 'kind', 10 );
		if ( ! in_array( $kind, MasterData::KINDS, true ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'logierp' ), '', array( 'response' => 400 ) );
		}
		$tab = array_search( $kind, self::TABS, true );
		$id  = Ui::post_int( 'id' );
		$cur = null;
		if ( $id ) {
			$cur = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( $kind ) . ' WHERE id = %d AND dataset_id = %d', $id, Catalog::dataset() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $cur ) {
				wp_die( esc_html__( 'Enregistrement introuvable.', 'logierp' ) );
			}
		}
		$in   = array();
		$post = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- nonce vérifié (Ui::guard) ; chaque valeur est nettoyée ci-dessous puis contrôlée par MasterData::validate().
		foreach ( array_keys( MasterData::columns( $kind ) ) as $k ) {
			$in[ $k ] = isset( $post[ $k ] ) && is_scalar( $post[ $k ] ) ? mb_substr( sanitize_text_field( (string) $post[ $k ] ), 0, 190 ) : '';
		}
		if ( $cur ) {
			// Champs figés (désactivés dans le formulaire) : valeurs actuelles.
			$in[ MasterData::number_key( $kind ) ] = $cur[ MasterData::number_key( $kind ) ];
		}
		$reserved          = array();
		[ $data, $errors ] = MasterData::validate( $kind, $in, $cur, $reserved );
		$back              = $id ? array( 'tab' => $tab, 'id' => $id ) : array( 'tab' => $tab, 'new' => '1' );
		if ( $errors ) {
			set_transient( 'lw_form_' . get_current_user_id(), array( 'kind' => $kind, 'id' => $id, 'values' => $in ), 600 );
			Ui::redirect( self::PAGE, $back, 'error', 'Enregistrement refusé : corrigez les points suivants.', $errors );
		}
		try {
			$new_id = Db::transaction( static fn() => MasterData::save( $kind, $data, $cur, $reserved ) );
		} catch ( \Throwable $e ) {
			\LogiERP\Support\Logger::error( 'Données de base : ' . $e->getMessage() );
			Ui::redirect( self::PAGE, $back, 'error', 'Erreur d\'enregistrement. Voir le journal technique.' );
		}
		Audit::log( $cur ? 'md_update' : 'md_create', $kind, (int) $new_id );
		Ui::redirect( self::PAGE, array( 'tab' => $tab, 'id' => (int) $new_id ), 'success', $cur ? 'Modifications enregistrées.' : 'Enregistrement créé.' );
	}

	/** Téléchargement du modèle ou de l'export (lien signé). */
	public function handle_xlsx(): void {
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		if ( ! in_array( $kind, MasterData::KINDS, true ) || ! in_array( $mode, array( 'template', 'export' ), true ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'logierp' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'logierp_md_xlsx_' . $kind . '_' . $mode );
		if ( ! current_user_can( Capabilities::MASTER_DATA ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		if ( ! Xlsx::available() ) {
			wp_die( esc_html__( 'L\'extension PHP « zip » n\'est pas active sur ce serveur : impossible de produire un fichier Excel.', 'logierp' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$data = MasterData::workbook( $kind, 'export' === $mode );
		$name = 'logierp-' . array( 'material' => 'articles', 'supplier' => 'fournisseurs', 'customer' => 'clients' )[ $kind ] . ( 'template' === $mode ? '-modele' : '-' . gmdate( 'Y-m-d' ) ) . '.xlsx';
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $data ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier binaire produit par le plugin.
		exit;
	}

	public function handle_import(): void {
		Ui::guard( 'logierp_md_import', Capabilities::MASTER_DATA );
		$kind = Ui::post_str( 'kind', 10 );
		if ( ! in_array( $kind, MasterData::KINDS, true ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'logierp' ), '', array( 'response' => 400 ) );
		}
		$tab  = (string) array_search( $kind, self::TABS, true );
		$back = array( 'tab' => $tab );
		if ( ! RateLimiter::hit( 'md_import' ) ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Trop d\'imports en peu de temps. Réessayez dans une heure.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié (Ui::guard).
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- contrôlé ci-dessous.
		// phpcs:enable
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Aucun fichier reçu (ou fichier trop volumineux pour le serveur).' );
		}
		if ( (int) $f['size'] > self::MAX_UPLOAD ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Fichier trop volumineux (8 Mo au maximum).' );
		}
		$ext  = strtolower( pathinfo( sanitize_file_name( (string) $f['name'] ), PATHINFO_EXTENSION ) );
		$head = (string) file_get_contents( (string) $f['tmp_name'], false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier téléversé local.
		try {
			if ( 'xlsx' === $ext && "PK\x03\x04" === $head ) {
				$rows = Xlsx::read( (string) $f['tmp_name'], array( MasterData::label( $kind ) ) );
			} elseif ( 'csv' === $ext && "PK\x03\x04" !== $head ) {
				$rows = Xlsx::read_csv( (string) $f['tmp_name'] );
			} else {
				Ui::redirect( self::PAGE, $back, 'error', 'Format non reconnu : utilisez un classeur Excel .xlsx (ou un fichier .csv).' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$res = MasterData::import( $kind, $rows, Ui::post_bool( 'skip_errors' ) );
		} catch ( \Throwable $e ) {
			if ( ! $e instanceof \RuntimeException ) {
				\LogiERP\Support\Logger::error( 'Import Excel : ' . $e->getMessage() );
			}
			Ui::redirect( self::PAGE, $back, 'error', 'Import impossible : ' . ( $e instanceof \RuntimeException ? $e->getMessage() : 'erreur inattendue (voir le journal technique).' ) );
		} finally {
			wp_delete_file( (string) $f['tmp_name'] );
		}
		$errors = $res['errors'];
		$shown  = array_slice( $errors, 0, 40 );
		if ( count( $errors ) > 40 ) {
			$shown[] = sprintf( '… et %d autre(s) erreur(s).', count( $errors ) - 40 );
		}
		Audit::log( 'md_import', $kind, 0, array( 'created' => $res['created'], 'updated' => $res['updated'], 'skipped' => $res['skipped'] ) );
		if ( ! $res['imported'] && $errors ) {
			$why = $res['skipped'] ? sprintf( 'Rien n\'a été importé : %d ligne(s) à corriger. Corrigez le fichier puis importez-le à nouveau (ou cochez « importer quand même les lignes correctes »).', $res['skipped'] ) : 'Rien n\'a été importé.';
			Ui::redirect( self::PAGE, $back, 'error', $why, $shown );
		}
		$msg = sprintf( 'Import terminé : %d créé(s), %d mis à jour.', $res['created'], $res['updated'] );
		if ( $res['skipped'] ) {
			$msg .= sprintf( ' %d ligne(s) ignorée(s) à cause d\'erreurs.', $res['skipped'] );
		}
		Ui::redirect( self::PAGE, $back, $res['skipped'] ? 'warning' : 'success', $msg, $shown );
	}

	public function handle_fixed_bin(): void {
		Ui::guard( 'logierp_md_fixed_bin', Capabilities::MASTER_DATA );
		$id   = Ui::post_int( 'id' );
		$m    = Catalog::material( $id );
		$bin  = Catalog::bin_by_code( strtoupper( Ui::post_str( 'bin', 40 ) ) );
		$back = array( 'tab' => 'materials', 'id' => $id );
		if ( ! $m || ! $bin ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Emplacement inconnu.' );
		}
		$loc = Catalog::location( (int) $bin['storage_location_id'] );
		if ( ! $loc || 'FIXED' !== $loc['strategy'] ) {
			Ui::redirect( self::PAGE, $back, 'error', sprintf( 'La zone %s n\'est pas en stratégie « Emplacement fixe ».', $loc ? $loc['code'] : '?' ) );
		}
		if ( (int) $bin['fixed_material_id'] && (int) $bin['fixed_material_id'] !== $id ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Cet emplacement est déjà attribué à l\'article ' . ( Catalog::material( (int) $bin['fixed_material_id'] )['material_no'] ?? '?' ) . '.' );
		}
		$problems = \LogiERP\Domain\Rules::check_location( $m, $loc );
		if ( $problems ) {
			Ui::redirect( self::PAGE, $back, 'error', 'Cette zone ne respecte pas les critères de stockage de l\'article.', $problems );
		}
		if ( (int) $m['length_mm'] > (int) $bin['length_mm'] || (float) $m['gross_weight'] > (float) $bin['max_weight'] ) {
			Ui::redirect( self::PAGE, $back, 'error', 'L\'article est trop long ou trop lourd pour cet emplacement.' );
		}
		$min = max( 0.0, Ui::post_float( 'min_qty' ) );
		$max = max( $min, Ui::post_float( 'max_qty' ) );
		Db::update( 'bin', array( 'fixed_material_id' => $id, 'min_qty' => $min, 'max_qty' => $max ), array( 'id' => (int) $bin['id'] ) );
		Audit::log( 'fixed_bin', 'bin', (int) $bin['id'], array( 'material' => $id ) );
		Ui::redirect( self::PAGE, $back, 'success', 'Emplacement fixe attribué.' );
	}

	public function handle_unfix_bin(): void {
		Ui::guard( 'logierp_md_unfix_bin', Capabilities::MASTER_DATA );
		$id = Ui::post_int( 'id' );
		global $wpdb;
		$wpdb->update( Db::t( 'bin' ), array( 'fixed_material_id' => 0, 'min_qty' => 0, 'max_qty' => 0 ), array( 'id' => Ui::post_int( 'bin_id' ), 'fixed_material_id' => $id ) );
		Ui::redirect( self::PAGE, array( 'tab' => 'materials', 'id' => $id ), 'success', 'Emplacement libéré.' );
	}
}
