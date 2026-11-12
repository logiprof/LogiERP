<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\DomainError;
use LogiERP\Domain\Events;
use LogiERP\Domain\SessionService;
use LogiERP\Scenario\Grader;
use LogiERP\Security\Audit;
use LogiERP\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scénarios : liste, éditeur en sections, test, duplication, publication, export / import signé.
 */
final class ScenariosPage {

	private const PAGE = 'logierp-scenarios';

	public const MODULES = array(
		'MD'   => 'Données de base',
		'PUR'  => 'Achats',
		'GR'   => 'Réception',
		'PUT'  => 'Entreposage',
		'INV'  => 'Gestion de stock',
		'WH'   => 'Entrepôt (OT, emplacements)',
		'SD'   => 'Ventes',
		'PICK' => 'Préparation',
		'SHIP' => 'Expédition',
		'PP'   => 'Production',
		'MRP'  => 'Planification des besoins (MD01, MD04)',
		'QM'   => 'Qualité (lots de contrôle, DLC)',
		'TRA'  => 'Transport et emballages consignés',
		'CNT'  => 'Inventaire',
		'RET'  => 'Retours',
	);

	public const LEVELS = array( 'debutant' => 'Débutant', 'intermediaire' => 'Intermédiaire', 'avance' => 'Avancé', 'examen' => 'Examen' );

	public function register(): void {
		foreach ( array( 'save', 'create', 'duplicate', 'status', 'delete', 'test', 'add_doc', 'del_doc', 'add_stock', 'del_stock', 'add_event', 'del_event', 'save_tasks', 'add_check', 'del_check', 'export', 'import' ) as $a ) {
			add_action( 'admin_post_logierp_sc_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	/* ───────── Accès ───────── */

	private static function get( int $id ): ?array {
		return SessionService::scenario( $id );
	}

	public static function can_edit( array $sc ): bool {
		return current_user_can( Capabilities::SETTINGS ) || ( (int) $sc['owner_id'] === get_current_user_id() && current_user_can( Capabilities::SCENARIOS ) );
	}

	public static function can_view( array $sc ): bool {
		return self::can_edit( $sc ) || ( ! empty( $sc['shared'] ) && current_user_can( Capabilities::SCENARIOS ) );
	}

	private static function editable_or_die( int $id ): array {
		$sc = self::get( $id );
		if ( ! $sc || ! self::can_edit( $sc ) ) {
			wp_die( esc_html__( 'Scénario introuvable ou non modifiable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		return $sc;
	}

	private static function touch( int $id ): void {
		global $wpdb;
		$t = Db::t( 'scenario' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET version = version + 1, updated_at = %s WHERE id = %d", Db::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ───────── Affichage ───────── */

	public function render(): void {
		$id = Ui::get_int( 'id' );
		if ( $id ) {
			$this->editor( $id );
			return;
		}
		global $wpdb;
		Ui::header( 'Scénarios', 'Les scénarios modèles sont partagés : testez-les, puis dupliquez-les pour les adapter à vos classes.' );
		$t    = Db::t( 'scenario' );
		$uid  = get_current_user_id();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE deleted_at IS NULL AND (owner_id = %d OR shared = 1 OR %d = 1) ORDER BY template_key = '' DESC, updated_at DESC, id", $uid, current_user_can( Capabilities::SETTINGS ) ? 1 : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>Nouveau scénario</h2>';
		Ui::form_open( 'logierp_sc_create' );
		echo '<p><input type="text" name="title" required maxlength="190" class="regular-text" placeholder="Titre du scénario"> ';
		Ui::form_close( 'Créer', 'button button-primary' );
		echo '</p></div>';
		echo '<table class="widefat striped lw-table-admin"><thead><tr><th>Titre</th><th>Niveau</th><th>Gestion</th><th>Statut</th><th>Propriétaire</th><th>Version</th><th>Actions</th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$owner = (int) $r['owner_id'] ? get_userdata( (int) $r['owner_id'] ) : null;
			echo '<tr><td><strong>' . esc_html( $r['title'] ) . '</strong>' . ( '' !== $r['template_key'] ? ' <span class="lw-pill">Modèle</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( self::LEVELS[ $r['level'] ] ?? $r['level'] ) . '</td>';
			echo '<td>' . esc_html( 'SIMPLE' === $r['location_mode'] ? 'Simple' : 'Détaillée' ) . '</td>';
			echo '<td>' . esc_html( array( 'DRAFT' => 'Brouillon', 'PUBLISHED' => 'Publié', 'ARCHIVED' => 'Archivé' )[ $r['status'] ] ?? $r['status'] ) . '</td>';
			echo '<td>' . esc_html( $owner ? $owner->display_name : 'LogiERP' ) . '</td><td>' . (int) $r['version'] . '</td><td class="lw-actions-cell">';
			echo '<a class="button" href="' . esc_url( Ui::url( self::PAGE, array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html( self::can_edit( $r ) ? 'Modifier' : 'Consulter' ) . '</a> ';
			Ui::button_form( 'logierp_sc_test', array( 'id' => (int) $r['id'] ), 'Tester', 'button' );
			echo ' <a class="button" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\ActivityDoc::teacher_url( (int) $r['id'] ) ) . '">Document</a> <a class="button lw-files-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\BusinessDocs::teacher_url( (int) $r['id'] ) ) . '">Documents logistiques</a> ';
			Ui::button_form( 'logierp_sc_duplicate', array( 'id' => (int) $r['id'] ), 'Dupliquer', 'button' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="lw-box"><h2>Importer un scénario</h2><p class="description">Fichier JSON exporté depuis LogiERP (signé). Taille maximale 5 Mo.</p>';
		Ui::form_open( 'logierp_sc_import', array(), 'enctype="multipart/form-data"' );
		echo '<p><input type="file" name="file" accept="application/json,.json" required> ';
		if ( current_user_can( Capabilities::SETTINGS ) ) {
			echo Ui::checkbox( 'unsigned_ok', false, 'J\'accepte d\'importer un fichier non signé (provenant d\'un autre site)' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		Ui::form_close( 'Importer', 'button' );
		echo '</p></div>';
		Ui::footer();
	}

	private function editor( int $id ): void {
		global $wpdb;
		$sc = self::get( $id );
		if ( ! $sc || ! self::can_view( $sc ) ) {
			wp_die( esc_html__( 'Scénario introuvable.', 'logierp' ) );
		}
		$edit = self::can_edit( $sc );
		Ui::header( $sc['title'], $edit ? 'Modifiez chaque section puis enregistrez. Une session en cours garde la version du scénario qui l\'a lancée.' : 'Scénario partagé en lecture seule : dupliquez-le pour l\'adapter.' );
		echo '<p class="lw-toolbar-admin"><a class="button" href="' . esc_url( Ui::url( self::PAGE ) ) . '">← Tous les scénarios</a> ';
		Ui::button_form( 'logierp_sc_test', array( 'id' => $id ), 'Tester en tant qu\'apprenant', 'button button-primary' );
		echo ' <a class="button lw-doc-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\ActivityDoc::teacher_url( $id ) ) . '">Document d\'activité</a>';
		echo ' <a class="button lw-files-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\BusinessDocs::teacher_url( $id ) ) . '">Documents logistiques</a>';
		echo ' <a class="button lw-kit-btn" target="_blank" rel="noopener" href="' . esc_url( \LogiERP\Front\MiniWarehouse::url( $id ) ) . '">Kit mini-entrepôt</a>';
		echo ' <a class="button" href="' . esc_url( Ui::url( 'logierp-labels', array( 'scenario' => $id ) ) ) . '">Étiquettes</a> ';
		Ui::button_form( 'logierp_sc_duplicate', array( 'id' => $id ), 'Dupliquer', 'button' );
		if ( $edit ) {
			echo ' ';
			Ui::button_form( 'logierp_sc_export', array( 'id' => $id ), 'Exporter (JSON)', 'button' );
			echo ' ';
			Ui::button_form( 'logierp_sc_status', array( 'id' => $id, 'status' => 'PUBLISHED' === $sc['status'] ? 'DRAFT' : 'PUBLISHED' ), 'PUBLISHED' === $sc['status'] ? 'Repasser en brouillon' : 'Publier', 'button' );
			echo ' ';
			Ui::button_form( 'logierp_sc_status', array( 'id' => $id, 'status' => 'ARCHIVED' ), 'Archiver', 'button' );
			echo ' ';
			Ui::button_form( 'logierp_sc_delete', array( 'id' => $id ), 'Supprimer', 'button lw-danger', 'Supprimer définitivement ce scénario ?' );
		}
		echo '</p>';

		// 1–2. Cadre et environnement.
		echo '<div class="lw-box"><h2>1. Cadre et environnement</h2>';
		if ( $edit ) {
			Ui::form_open( 'logierp_sc_save', array( 'id' => $id ) );
		}
		echo '<table class="form-table">';
		Ui::field( 'Titre', Ui::input( 'title', $sc['title'], 'text', 'maxlength="190" required' ) );
		Ui::field( 'Objectifs pédagogiques', Ui::textarea( 'objectives', (string) $sc['objectives'], 4 ), 'Un objectif par ligne. Affichés à l\'apprenant.' );
		Ui::field( 'Mise en situation', Ui::textarea( 'instructions', (string) $sc['instructions'], 5 ) );
		Ui::field( 'Niveau', Ui::select( 'level', self::LEVELS, $sc['level'] ) );
		Ui::field( 'Durée estimée (min)', Ui::input( 'duration_min', (string) $sc['duration_min'], 'number', 'min="5" max="600"' ) );
		$mods = array_filter( explode( ',', (string) $sc['modules'] ) );
		$mh   = '';
		foreach ( self::MODULES as $k => $l ) {
			$mh .= '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="modules[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $mods, true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		Ui::field( 'Modules actifs', $mh, 'Les transactions des modules non cochés sont masquées à l\'apprenant. Aucun coché = tout visible.' );
		Ui::field( 'Gestion des emplacements', Ui::select( 'location_mode', array( 'DETAILED' => 'Détaillée (emplacements, ordres de transfert)', 'SIMPLE' => 'Simple (au niveau du magasin)' ), $sc['location_mode'] ) );
		Ui::field( 'Stock de départ', Ui::select( 'initial_stock_mode', array( 'BASE' => 'Copie du jeu de base + stock du scénario', 'EMPTY' => 'Uniquement le stock défini dans le scénario' ), $sc['initial_stock_mode'] ) );
		Ui::field( 'Date simulée de départ', Ui::input( 'sim_date', $sc['sim_date'], 'date' ) );
		Ui::field( 'Document d\'activité', Ui::checkbox( 'doc_criteria', ! isset( $sc['doc_criteria'] ) || (int) $sc['doc_criteria'], 'Afficher les critères d\'évaluation et leurs points dans le document distribué aux apprentis' ) . '<br>' . Ui::textarea( 'doc_notes', (string) ( $sc['doc_notes'] ?? '' ), 3 ), 'Remarques de l\'enseignant imprimées dans le document (matériel, organisation, travail en binôme, temps imparti…).' );
		Ui::field( 'Options', Ui::checkbox( 'guided', (bool) $sc['guided'], 'Mode guidé (consignes cochées, emplacements proposés pré-remplis)' ) . '<br>' . Ui::checkbox( 'hv_key', (bool) $sc['hv_key'], 'L\'apprenant possède la clé des zones sous clé (haute valeur, toxiques)' ) . '<br>' . Ui::checkbox( 'allow_strategy_override', (bool) $sc['allow_strategy_override'], 'Autoriser un prélèvement hors FIFO/FEFO (tracé comme écart)' ) . '<br>' . Ui::checkbox( 'check_digits', ! empty( $sc['check_digits'] ), 'Mini-entrepôt : le préparateur confirme chaque prélèvement avec le chiffre de contrôle imprimé sur l\'étiquette de l\'emplacement' ) . '<br>' . Ui::checkbox( 'expiry_auto', 'MANUEL' !== ( $sc['expiry_mode'] ?? 'AUTO' ), 'Blocage automatique des lots dont la date limite est atteinte (lot de contrôle qualité créé) ; décoché : l\'apprenant doit repérer et bloquer lui-même les lots périmés' ) . '<br>' . Ui::checkbox( 'shared', (bool) $sc['shared'], 'Partager avec les autres enseignants' ) );
		echo '</table>';
		if ( $edit ) {
			Ui::form_close( 'Enregistrer' );
		}
		echo '</div>';

		$this->section_documents( $sc, $edit );
		$this->section_stock( $sc, $edit );
		$this->section_events( $sc, $edit );
		$this->section_tasks( $sc, $edit );
		$this->section_checks( $sc, $edit );
		Ui::footer();
	}

	/** Paramètres lisibles d'un événement ou d'un critère (numéros métier, pas d'identifiants internes). */
	private static function params_text( array $p ): string {
		$p     = self::portable( $p );
		$names = array( 'material_no' => 'article', 'article' => 'article', 'supplier_no' => 'fournisseur', 'customer_no' => 'client', 'ref' => 'réf.', 'qty' => 'quantité', 'delta' => 'écart', 'bin_code' => 'emplacement', 'location_code' => 'magasin', 'status' => 'statut', 'movement' => 'mouvement', 'reason' => 'motif', 'damage' => 'avarie', 'max' => 'maximum', 'min' => 'minimum', 'carrier' => 'transporteur', 'out' => 'sorties bloquées', 'reserves' => 'réserves obligatoires', 'min_count' => 'nombre minimal' );
		$out   = array();
		foreach ( $p as $k => $v ) {
			if ( 'items' === $k && is_array( $v ) ) {
				foreach ( $v as $i ) {
					$out[] = 'article ' . ( $i['material_no'] ?? '?' ) . ' × ' . ( $i['qty'] ?? '' );
				}
				continue;
			}
			if ( '' === $v || null === $v || array() === $v || 'location_id' === $k ) {
				continue;
			}
			if ( is_bool( $v ) || ( in_array( $k, array( 'out', 'reserves' ), true ) ) ) {
				if ( $v ) {
					$out[] = $names[ $k ] ?? $k;
				}
				continue;
			}
			$out[] = ( $names[ $k ] ?? $k ) . ' ' . ( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v );
		}
		return $out ? implode( ' · ', $out ) : '—';
	}

	private static function mat_no( int $id ): string {
		$m = Catalog::material( $id );
		return $m ? $m['material_no'] : '?';
	}

	private static function partner_label( string $type, int $id ): string {
		$p = \LogiERP\Domain\Api::partner_full( 'PO' === $type ? 'supplier' : 'customer', $id );
		return $p ? ( $p['supplier_no'] ?? $p['customer_no'] ) . ' ' . $p['name'] : '—';
	}

	private function section_documents( array $sc, bool $edit ): void {
		global $wpdb;
		$t    = Db::t( 'scenario_document' );
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY id", (int) $sc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>2. Documents préparés</h2><p class="description">Commandes fournisseurs (avec la réalité de la livraison : manquant, avarie, mauvaise DLC), commandes clients et ordres de fabrication créés au démarrage de chaque session. La référence (PO1, SO1…) sert aux critères de correction.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Réf.</th><th>Type</th><th>Partenaire</th><th>Lignes</th><th>Remarque</th><th></th></tr></thead><tbody>';
		foreach ( (array) $docs as $d ) {
			$items = json_decode( (string) $d['items'], true ) ?: array();
			$lines = array();
			foreach ( $items as $i ) {
				$l = self::mat_no( (int) ( $i['material_id'] ?? 0 ) ) . ' × ' . ( $i['qty'] ?? '' );
				if ( isset( $i['delivered_qty'] ) && '' !== (string) $i['delivered_qty'] ) {
					$l .= ' (livré ' . $i['delivered_qty'] . ')';
				}
				if ( ! empty( $i['damage'] ) ) {
					$l .= ' [avarie : ' . $i['damage'] . ']';
				}
				if ( isset( $i['bbd_days'] ) && '' !== (string) $i['bbd_days'] ) {
					$l .= ' [DLC J' . sprintf( '%+d', (int) $i['bbd_days'] ) . ']';
				}
				$lines[] = $l;
			}
			echo '<tr><td><code>' . esc_html( $d['ref'] ) . '</code></td><td>' . esc_html( array( 'PO' => 'Commande fournisseur', 'SO' => 'Commande client', 'PROD' => 'Ordre de fabrication' )[ $d['doc_type'] ] ?? $d['doc_type'] ) . '</td><td>' . esc_html( 'PROD' === $d['doc_type'] ? '—' : self::partner_label( $d['doc_type'], (int) $d['partner_id'] ) ) . '</td><td>' . esc_html( implode( ' ; ', $lines ) ) . '</td><td>' . esc_html( $d['note'] . ( 'SO' === $d['doc_type'] ? ' (priorité ' . $d['priority'] . ')' : '' ) ) . '</td><td>';
			if ( $edit ) {
				Ui::button_form( 'logierp_sc_del_doc', array( 'id' => (int) $sc['id'], 'doc_id' => (int) $d['id'] ), 'Retirer', 'button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $edit ) {
			echo '<h3>Ajouter un document</h3>';
			Ui::form_open( 'logierp_sc_add_doc', array( 'id' => (int) $sc['id'] ) );
			echo '<table class="form-table">';
			Ui::field( 'Type', Ui::select( 'doc_type', array( 'PO' => 'Commande fournisseur', 'SO' => 'Commande client', 'PROD' => 'Ordre de fabrication' ), 'PO' ) );
			Ui::field( 'Référence', Ui::input( 'ref', '', 'text', 'maxlength="20" placeholder="PO1" required pattern="[A-Za-z0-9]+"' ) );
			Ui::field( 'Fournisseur ou client', Ui::input( 'partner_no', '', 'text', 'placeholder="F20001 ou C10001"' ), 'Laisser vide pour un ordre de fabrication.' );
			Ui::field( 'Lignes', Ui::textarea( 'lines', '', 4 ), 'Une ligne par article : article;quantité commandée;quantité réellement livrée;avarie;DLC en jours (ex. 10000001;40;36;;  ou  10000002;10;10;Carton écrasé;). Pour une commande client ou un OF : article;quantité.' );
			Ui::field( 'Priorité (commande client)', Ui::select( 'priority', array( 'A' => 'A', 'B' => 'B', 'C' => 'C' ), 'B' ) );
			Ui::field( 'Décalage de date (jours)', Ui::input( 'day_offset', '0', 'number', 'min="-30" max="60"' ) );
			Ui::field( 'Remarque', Ui::input( 'note', '', 'text', 'maxlength="255" placeholder="ex. Livraison non annoncée"' ) );
			echo '</table>';
			Ui::form_close( 'Ajouter le document', 'button' );
		}
		echo '</div>';
	}

	private function section_stock( array $sc, bool $edit ): void {
		global $wpdb;
		$t    = Db::t( 'scenario_initial_stock' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY id", (int) $sc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>3. Stock de départ propre au scénario</h2><p class="description">Ajouté au jeu de base, ou seul stock si « Uniquement le stock du scénario ». Ces lignes ne sont pas contrôlées par les règles : vous pouvez volontairement créer une situation anormale (lot périmé, produit mal rangé) que l\'apprenant devra corriger.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Article</th><th>Emplacement / magasin</th><th>Lot</th><th>DLC</th><th>Statut</th><th>Quantité</th><th></th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$bin = (int) $r['bin_id'] ? Catalog::bin( (int) $r['bin_id'] ) : null;
			$loc = Catalog::location( (int) $r['storage_location_id'] );
			echo '<tr><td>' . esc_html( self::mat_no( (int) $r['material_id'] ) ) . '</td><td><code>' . esc_html( $bin ? $bin['code'] : ( $loc ? $loc['code'] : '?' ) ) . '</code></td><td>' . esc_html( $r['batch'] ) . '</td><td>' . esc_html( (string) $r['bbd'] ) . '</td><td>' . esc_html( $r['status'] ) . '</td><td>' . esc_html( Catalog::format_qty( (float) $r['qty'] ) ) . '</td><td>';
			if ( $edit ) {
				Ui::button_form( 'logierp_sc_del_stock', array( 'id' => (int) $sc['id'], 'row_id' => (int) $r['id'] ), 'Retirer', 'button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $edit ) {
			Ui::form_open( 'logierp_sc_add_stock', array( 'id' => (int) $sc['id'] ) );
			echo '<p>' . Ui::textarea( 'lines', '', 3 ) . '</p><p class="description">Une ligne par stock : article;emplacement ou magasin;quantité;statut (LIBRE, CONTROLE_QUALITE, BLOQUE);lot;DLC (date AAAA-MM-JJ ou décalage en jours, ex. -2). Exemple : 40000012;200-K01-01-01-G;30;LIBRE;L260101A;+4</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			Ui::form_close( 'Ajouter', 'button' );
		}
		echo '</div>';
	}

	private function section_events( array $sc, bool $edit ): void {
		echo '<div class="lw-box"><h2>4. Événements et imprévus</h2><p class="description">Déclenchés au démarrage, après un nombre d\'actions réussies, ou en direct depuis le suivi.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Déclenchement</th><th>Paramètres</th><th>Message à l\'apprenant</th><th></th></tr></thead><tbody>';
		foreach ( Events::events_of( (int) $sc['id'] ) as $e ) {
			$p = json_decode( (string) $e['params'], true ) ?: array();
			if ( isset( $p['material_id'] ) ) {
				$p['article'] = self::mat_no( (int) $p['material_id'] );
				unset( $p['material_id'] );
			}
			echo '<tr><td>' . esc_html( Events::TYPES[ $e['type'] ] ?? $e['type'] ) . '</td><td>' . esc_html( ( Events::TRIGGERS[ $e['trigger_type'] ] ?? $e['trigger_type'] ) . ( 'after_actions' === $e['trigger_type'] ? ' (' . (int) $e['trigger_value'] . ')' : '' ) ) . '</td><td>' . esc_html( self::params_text( $p ) ) . '</td><td>' . esc_html( $e['message'] ) . '</td><td>';
			if ( $edit ) {
				Ui::button_form( 'logierp_sc_del_event', array( 'id' => (int) $sc['id'], 'event_id' => (int) $e['id'] ), 'Retirer', 'button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $edit ) {
			echo '<h3>Ajouter un événement</h3>';
			Ui::form_open( 'logierp_sc_add_event', array( 'id' => (int) $sc['id'] ) );
			echo '<table class="form-table">';
			Ui::field( 'Type', Ui::select( 'type', Events::TYPES, 'message' ) );
			Ui::field( 'Déclenchement', Ui::select( 'trigger_type', Events::TRIGGERS, 'manual' ) . ' ' . Ui::input( 'trigger_value', '3', 'number', 'min="0" max="500" style="width:80px"' ), 'Pour « Après N actions », indiquez N.' );
			Ui::field( 'Message', Ui::input( 'message', '', 'text', 'maxlength="255"' ) );
			Ui::field( 'Article', Ui::input( 'p_material', '', 'text', 'placeholder="n° article"' ), 'Livraison non annoncée, commande urgente, écart d\'inventaire.' );
			Ui::field( 'Quantité / écart', Ui::input( 'p_qty', '', 'number', 'step="any"' ), 'Écart d\'inventaire : négatif = manquant (ex. -2).' );
			Ui::field( 'Fournisseur / client', Ui::input( 'p_partner', '', 'text', 'placeholder="F20001 ou C10001"' ) );
			Ui::field( 'Emplacement', Ui::input( 'p_bin', '', 'text', 'placeholder="110-A01-01-00-G"' ), 'Emplacement bloqué ou écart d\'inventaire.' );
			Ui::field( 'Référence de document', Ui::input( 'p_ref', '', 'text', 'placeholder="PO1, SO1…"' ), 'Annulation, colis endommagé, retard fournisseur ; référence de la commande urgente créée.' );
			Ui::field( 'Motif / avarie', Ui::input( 'p_reason', '', 'text', 'maxlength="120"' ) );
			Ui::field( 'Blocage en sortie', Ui::checkbox( 'p_out', false, 'Bloquer aussi les sorties (emplacement bloqué)' ) );
			echo '</table>';
			Ui::form_close( 'Ajouter l\'événement', 'button' );
		}
		echo '</div>';
	}

	private function section_tasks( array $sc, bool $edit ): void {
		global $wpdb;
		$t     = Db::t( 'scenario_task' );
		$tasks = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY seq", (int) $sc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<div class="lw-box"><h2>5. Consignes (tâches affichées à l\'apprenant)</h2>';
		if ( $edit ) {
			Ui::form_open( 'logierp_sc_save_tasks', array( 'id' => (int) $sc['id'] ) );
		}
		echo '<table class="widefat"><thead><tr><th style="width:26%">Titre</th><th>Consigne</th><th style="width:14%">Transaction</th></tr></thead><tbody>';
		for ( $i = 0; $i < max( count( $tasks ) + ( $edit ? 2 : 0 ), 1 ); $i++ ) {
			$task = $tasks[ $i ] ?? array( 'title' => '', 'body' => '', 'transaction_code' => '' );
			echo '<tr><td><input type="text" class="widefat" maxlength="190" name="task_title[]" value="' . esc_attr( $task['title'] ) . '"' . disabled( ! $edit, true, false ) . '></td><td><textarea class="widefat" rows="2" name="task_body[]"' . disabled( ! $edit, true, false ) . '>' . esc_textarea( (string) $task['body'] ) . '</textarea></td><td><input type="text" class="widefat" maxlength="20" name="task_code[]" placeholder="MIGO" value="' . esc_attr( $task['transaction_code'] ) . '"' . disabled( ! $edit, true, false ) . '></td></tr>';
		}
		echo '</tbody></table><p class="description">Laisser le titre vide pour supprimer une consigne. En mode guidé, une consigne est cochée quand l\'apprenant a utilisé sa transaction avec succès.</p>';
		if ( $edit ) {
			Ui::form_close( 'Enregistrer les consignes', 'button' );
		}
		echo '</div>';
	}

	private function section_checks( array $sc, bool $edit ): void {
		echo '<div class="lw-box"><h2>6. Correction automatique</h2><p class="description">Chaque critère est évalué à la remise de la session. Note = 1 + 5 × points obtenus / points maximum, arrondie au demi-point.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Critère</th><th>Type</th><th>Paramètres</th><th>Points</th><th></th></tr></thead><tbody>';
		$total = 0.0;
		foreach ( Grader::checks_of( (int) $sc['id'] ) as $c ) {
			$p = json_decode( (string) $c['params'], true ) ?: array();
			if ( isset( $p['material_id'] ) ) {
				$p['article'] = self::mat_no( (int) $p['material_id'] );
				unset( $p['material_id'] );
			}
			$total += (float) $c['points'];
			echo '<tr><td>' . esc_html( $c['label'] ) . '</td><td>' . esc_html( Grader::TYPES[ $c['type'] ] ?? $c['type'] ) . '</td><td>' . esc_html( self::params_text( $p ) ) . '</td><td>' . esc_html( Catalog::format_qty( (float) $c['points'] ) ) . '</td><td>';
			if ( $edit ) {
				Ui::button_form( 'logierp_sc_del_check', array( 'id' => (int) $sc['id'], 'check_id' => (int) $c['id'] ), 'Retirer', 'button-link-delete' );
			}
			echo '</td></tr>';
		}
		echo '<tr><td colspan="3"><strong>Total</strong></td><td><strong>' . esc_html( Catalog::format_qty( $total ) ) . '</strong></td><td></td></tr></tbody></table>';
		if ( $edit ) {
			echo '<h3>Ajouter un critère</h3>';
			Ui::form_open( 'logierp_sc_add_check', array( 'id' => (int) $sc['id'] ) );
			echo '<table class="form-table">';
			Ui::field( 'Type', Ui::select( 'type', Grader::TYPES, 'doc_status' ) );
			Ui::field( 'Intitulé', Ui::input( 'label', '', 'text', 'maxlength="190" required' ) );
			Ui::field( 'Points', Ui::input( 'points', '1', 'number', 'min="0" max="100" step="0.5"' ) );
			Ui::field( 'Référence de document', Ui::input( 'p_ref', '', 'text', 'placeholder="PO1, SO1, PROD1"' ), 'Statut d\'un document, mouvement, réception, transporteur.' );
			Ui::field( 'Statut(s) attendu(s)', Ui::input( 'p_status', '', 'text', 'placeholder="LIVREE ou RECUE,PARTIELLEMENT_RECUE ; ou LIBRE / BLOQUE pour le stock"' ), 'Décision d\'emploi : LIBERER, BLOQUER, QUARANTAINE, DETRUIRE ou RETOUR (plusieurs séparées par des virgules). Mode de préparation : SERIE ou PARALLELE. Colisage : type d\'unité EUR, CP3, PERDUE ou COLIS.' );
			Ui::field( 'Article', Ui::input( 'p_material', '', 'text' ) );
			Ui::field( 'Magasin', Ui::input( 'p_location', '', 'text', 'placeholder="110"' ) );
			Ui::field( 'Emplacement', Ui::input( 'p_bin', '', 'text' ) );
			Ui::field( 'Code mouvement', Ui::input( 'p_movement', '', 'text', 'placeholder="101"' ) );
			Ui::field( 'Quantité / variation / minimum', Ui::input( 'p_qty', '', 'number', 'step="any"' ), 'Variation de stock : écart attendu (+24). Quantité en stock : minimum. Quantité réceptionnée : quantité exacte. Nombre maximal d\'erreurs : maximum. Colisage : nombre maximal d\'unités d\'expédition.' );
			Ui::field( 'Maximum (quantité en stock)', Ui::input( 'p_max', '', 'number', 'step="any"' ), 'Ordre de fabrication planifié : nombre de jours après la date de la session avant lequel l\'ordre ne peut pas commencer.' );
			Ui::field( 'Transporteur', Ui::input( 'p_carrier', '', 'text', 'placeholder="ADR, FRIGO, POST…"' ) );
			Ui::field( 'Option', Ui::checkbox( 'p_reserves', false, 'CMR : réserves obligatoires en case 24 · Colisage : au moins une palette mixte' ), 'Pour les critères « Lettre de voiture CMR signée » et « Commande regroupée et colisée ».' );
			echo '</table>';
			Ui::form_close( 'Ajouter le critère', 'button' );
		}
		echo '</div>';
	}

	/* ───────── Traitements ───────── */

	private function back( int $id, string $type, string $text, array $details = array() ): void {
		Ui::redirect( self::PAGE, $id ? array( 'id' => $id ) : array(), $type, $text, $details );
	}

	public function handle_create(): void {
		Ui::guard( 'logierp_sc_create', Capabilities::SCENARIOS );
		$title = Ui::post_str( 'title' );
		if ( '' === $title ) {
			$this->back( 0, 'error', 'Indiquez un titre.' );
		}
		$id = Db::insert( 'scenario', array( 'owner_id' => get_current_user_id(), 'title' => $title, 'objectives' => '', 'instructions' => '', 'modules' => '', 'sim_date' => \LogiERP\Seeder\StockSeeder::base_date(), 'dataset_id' => Catalog::dataset(), 'status' => 'DRAFT', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
		Audit::log( 'scenario_create', 'scenario', $id );
		$this->back( $id, 'success', 'Scénario créé avec son document d\'activité et ses documents logistiques (bulletins de livraison, commandes, CMR). Complétez les sections : les documents se mettent à jour à chaque enregistrement. Publiez le scénario quand il est prêt.' );
	}

	public function handle_save(): void {
		Ui::guard( 'logierp_sc_save', Capabilities::SCENARIOS );
		$id   = Ui::post_int( 'id' );
		$sc   = self::editable_or_die( $id );
		$mods = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST['modules'] ) ), array_map( 'strtolower', array_keys( self::MODULES ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par guard(), valeurs en liste blanche.
		$date = Ui::post_str( 'sim_date', 10 );
		$level = Ui::post_str( 'level', 20 );
		Db::update(
			'scenario',
			array(
				'title'                   => Ui::post_str( 'title' ) ?: $sc['title'],
				'objectives'              => Ui::post_text( 'objectives' ),
				'instructions'            => Ui::post_text( 'instructions' ),
				'level'                   => isset( self::LEVELS[ $level ] ) ? $level : 'debutant',
				'duration_min'            => max( 5, min( 600, Ui::post_int( 'duration_min' ) ) ),
				'modules'                 => implode( ',', array_map( 'strtoupper', $mods ) ),
				'location_mode'           => 'SIMPLE' === Ui::post_str( 'location_mode', 10 ) ? 'SIMPLE' : 'DETAILED',
				'initial_stock_mode'      => 'EMPTY' === Ui::post_str( 'initial_stock_mode', 10 ) ? 'EMPTY' : 'BASE',
				'sim_date'                => \LogiERP\Domain\Api::valid_date( $date ) ? $date : $sc['sim_date'],
				'guided'                  => Ui::post_bool( 'guided' ) ? 1 : 0,
				'hv_key'                  => Ui::post_bool( 'hv_key' ) ? 1 : 0,
				'expiry_mode'             => Ui::post_bool( 'expiry_auto' ) ? 'AUTO' : 'MANUEL',
				'allow_strategy_override' => Ui::post_bool( 'allow_strategy_override' ) ? 1 : 0,
				'check_digits'            => Ui::post_bool( 'check_digits' ) ? 1 : 0,
				'shared'                  => Ui::post_bool( 'shared' ) ? 1 : 0,
				'doc_criteria'            => Ui::post_bool( 'doc_criteria' ) ? 1 : 0,
				'doc_notes'               => Ui::post_text( 'doc_notes' ),
			),
			array( 'id' => $id )
		);
		self::touch( $id );
		$this->back( $id, 'success', 'Scénario enregistré. Le document d\'activité et les documents logistiques sont mis à jour automatiquement.' );
	}

	/** Copie complète (sans les sessions). */
	public static function duplicate( int $id, string $title_suffix = ' (copie)' ): int {
		global $wpdb;
		$sc = self::get( $id );
		$new = $sc;
		unset( $new['id'] );
		$new['owner_id']     = get_current_user_id();
		$new['template_key'] = '';
		$new['title']        = mb_substr( $sc['title'] . $title_suffix, 0, 190 );
		$new['status']       = 'DRAFT';
		$new['shared']       = 0;
		$new['version']      = 1;
		$new['created_at']   = Db::now();
		$new['updated_at']   = Db::now();
		$new                 = array_filter( $new, static fn( $v ) => null !== $v );
		$nid                 = Db::insert( 'scenario', $new );
		foreach ( array( 'scenario_initial_stock', 'scenario_document', 'scenario_event', 'scenario_task', 'scenario_check' ) as $t ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( $t ) . ' WHERE scenario_id = %d ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				unset( $r['id'] );
				$r['scenario_id'] = $nid;
				Db::insert( $t, array_filter( $r, static fn( $v ) => null !== $v ) );
			}
		}
		return $nid;
	}

	public function handle_duplicate(): void {
		Ui::guard( 'logierp_sc_duplicate', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		$sc = self::get( $id );
		if ( ! $sc || ! self::can_view( $sc ) ) {
			wp_die( esc_html__( 'Scénario introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		$nid = self::duplicate( $id );
		Audit::log( 'scenario_duplicate', 'scenario', $nid, array( 'from' => $id ) );
		$this->back( $nid, 'success', 'Copie créée : vous pouvez l\'adapter.' );
	}

	public function handle_status(): void {
		Ui::guard( 'logierp_sc_status', Capabilities::SCENARIOS );
		$id     = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		$status = Ui::post_str( 'status', 10 );
		if ( ! in_array( $status, array( 'DRAFT', 'PUBLISHED', 'ARCHIVED' ), true ) ) {
			$this->back( $id, 'error', 'Statut invalide.' );
		}
		if ( 'PUBLISHED' === $status && ! Grader::checks_of( $id ) ) {
			$this->back( $id, 'warning', 'Ajoutez au moins un critère de correction avant de publier.' );
		}
		Db::update( 'scenario', array( 'status' => $status, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		$this->back( $id, 'success', 'Statut mis à jour.' );
	}

	public function handle_delete(): void {
		global $wpdb;
		Ui::guard( 'logierp_sc_delete', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		$as = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'assignment' ) . ' WHERE scenario_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $as ) {
			$this->back( $id, 'error', 'Ce scénario est assigné à une classe : archivez-le plutôt que de le supprimer.' );
		}
		Db::update( 'scenario', array( 'deleted_at' => Db::now(), 'status' => 'ARCHIVED' ), array( 'id' => $id ) );
		Audit::log( 'scenario_delete', 'scenario', $id );
		$this->back( 0, 'success', 'Scénario supprimé.' );
	}

	public function handle_test(): void {
		Ui::guard( 'logierp_sc_test', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		$sc = self::get( $id );
		if ( ! $sc || ! self::can_view( $sc ) ) {
			wp_die( esc_html__( 'Scénario introuvable.', 'logierp' ), '', array( 'response' => 403 ) );
		}
		try {
			$sid = SessionService::start( get_current_user_id(), 0, $id );
		} catch ( DomainError $e ) {
			$this->back( $id, 'error', $e->getMessage() );
		}
		wp_safe_redirect( \LogiERP\Core\Activator::app_url() . '#/s/' . $sid );
		exit;
	}

	/** Lignes « champ;champ;… » nettoyées. */
	private static function parse_lines( string $text, int $max = 100 ): array {
		$out = array();
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', $text ), 0, $max ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = array_map( 'trim', explode( ';', $line ) );
		}
		return $out;
	}

	private static function partner_id( string $type, string $no ): int {
		global $wpdb;
		$t   = Db::t( 'PO' === $type ? 'supplier' : 'customer' );
		$col = 'PO' === $type ? 'supplier_no' : 'customer_no';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND {$col} = %s", Catalog::dataset(), strtoupper( $no ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function handle_add_doc(): void {
		Ui::guard( 'logierp_sc_add_doc', Capabilities::SCENARIOS );
		$id   = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		$type = Ui::post_str( 'doc_type', 4 );
		$ref  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', Ui::post_str( 'ref', 20 ) ) );
		if ( ! in_array( $type, array( 'PO', 'SO', 'PROD' ), true ) || '' === $ref ) {
			$this->back( $id, 'error', 'Type ou référence invalide.' );
		}
		$partner = 0;
		if ( 'PROD' !== $type ) {
			$partner = self::partner_id( $type, Ui::post_str( 'partner_no', 20 ) );
			if ( ! $partner ) {
				$this->back( $id, 'error', 'PO' === $type ? 'Fournisseur inconnu.' : 'Client inconnu.' );
			}
		}
		$errors = array();
		$items  = array();
		foreach ( self::parse_lines( Ui::post_text( 'lines' ), 50 ) as $n => $f ) {
			$m = Catalog::material_by_no( $f[0] ?? '' );
			$q = (float) str_replace( ',', '.', $f[1] ?? '0' );
			if ( ! $m || $q <= 0 ) {
				$errors[] = sprintf( 'Ligne %d : article inconnu ou quantité invalide.', $n + 1 );
				continue;
			}
			if ( 'PO' === $type && ! \LogiERP\Domain\Purchasing::info_record( $partner, (int) $m['id'] ) ) {
				$errors[] = sprintf( 'Ligne %d : le fournisseur ne livre pas l\'article %s (pas de fiche d\'info-achat).', $n + 1, $m['material_no'] );
				continue;
			}
			if ( 'PROD' === $type && ! \LogiERP\Domain\Production::bom( (int) $m['id'] ) ) {
				$errors[] = sprintf( 'Ligne %d : l\'article %s n\'a pas de nomenclature.', $n + 1, $m['material_no'] );
				continue;
			}
			$item = array( 'material_id' => (int) $m['id'], 'qty' => $q );
			if ( 'PO' === $type ) {
				if ( isset( $f[2] ) && '' !== $f[2] ) {
					$item['delivered_qty'] = (float) str_replace( ',', '.', $f[2] );
				}
				if ( ! empty( $f[3] ) ) {
					$item['damage'] = mb_substr( sanitize_text_field( $f[3] ), 0, 60 );
				}
				if ( isset( $f[4] ) && '' !== $f[4] ) {
					$item['bbd_days'] = (int) $f[4];
				}
			}
			$items[] = $item;
		}
		if ( $errors || ! $items ) {
			$this->back( $id, 'error', 'Document non ajouté.', $errors ?: array( 'Aucune ligne valide.' ) );
		}
		Db::insert( 'scenario_document', array( 'scenario_id' => $id, 'ref' => $ref, 'doc_type' => $type, 'partner_id' => $partner, 'day_offset' => max( -30, min( 60, Ui::post_int( 'day_offset' ) ) ), 'priority' => in_array( Ui::post_str( 'priority', 1 ), array( 'A', 'B', 'C' ), true ) ? Ui::post_str( 'priority', 1 ) : 'B', 'note' => Ui::post_str( 'note', 255 ), 'items' => wp_json_encode( $items ) ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Document ajouté. Ses pièces (commande, bulletin de livraison, CMR…) figurent dans « Documents logistiques ».' );
	}

	public function handle_del_doc(): void {
		Ui::guard( 'logierp_sc_del_doc', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		global $wpdb;
		$wpdb->delete( Db::t( 'scenario_document' ), array( 'id' => Ui::post_int( 'doc_id' ), 'scenario_id' => $id ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Document retiré.' );
	}

	public function handle_add_stock(): void {
		Ui::guard( 'logierp_sc_add_stock', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		$sc = self::editable_or_die( $id );
		$errors = array();
		$n      = 0;
		foreach ( self::parse_lines( Ui::post_text( 'lines' ), 100 ) as $i => $f ) {
			$m    = Catalog::material_by_no( $f[0] ?? '' );
			$code = strtoupper( $f[1] ?? '' );
			$bin  = Catalog::bin_by_code( $code );
			$loc  = $bin ? Catalog::location( (int) $bin['storage_location_id'] ) : Catalog::location_by_code( $code );
			$q    = (float) str_replace( ',', '.', $f[2] ?? '0' );
			$st   = strtoupper( $f[3] ?? 'LIBRE' ) ?: 'LIBRE';
			if ( ! $m || ! $loc || $q <= 0 || ! in_array( $st, array( 'LIBRE', 'CONTROLE_QUALITE', 'BLOQUE' ), true ) ) {
				$errors[] = sprintf( 'Ligne %d : article, emplacement, quantité ou statut invalide.', $i + 1 );
				continue;
			}
			$bbd = $f[5] ?? '';
			if ( preg_match( '/^[+-]?\d+$/', $bbd ) ) {
				$bbd = gmdate( 'Y-m-d', strtotime( $sc['sim_date'] . ' ' . sprintf( '%+d', (int) $bbd ) . ' days' ) );
			}
			$row = array( 'scenario_id' => $id, 'material_id' => (int) $m['id'], 'storage_location_id' => (int) $loc['id'], 'bin_id' => $bin ? (int) $bin['id'] : 0, 'batch' => strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $f[4] ?? '' ) ), 'status' => $st, 'qty' => $q );
			if ( \LogiERP\Domain\Api::valid_date( $bbd ) ) {
				$row['bbd'] = $bbd;
			}
			Db::insert( 'scenario_initial_stock', $row );
			++$n;
		}
		self::touch( $id );
		$this->back( $id, $errors ? 'warning' : 'success', sprintf( '%d ligne(s) de stock ajoutée(s).', $n ), $errors );
	}

	public function handle_del_stock(): void {
		Ui::guard( 'logierp_sc_del_stock', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		global $wpdb;
		$wpdb->delete( Db::t( 'scenario_initial_stock' ), array( 'id' => Ui::post_int( 'row_id' ), 'scenario_id' => $id ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Ligne retirée.' );
	}

	public function handle_add_event(): void {
		Ui::guard( 'logierp_sc_add_event', Capabilities::SCENARIOS );
		$id   = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		$type = Ui::post_str( 'type', 30 );
		$trig = Ui::post_str( 'trigger_type', 20 );
		if ( ! isset( Events::TYPES[ $type ] ) || ! isset( Events::TRIGGERS[ $trig ] ) ) {
			$this->back( $id, 'error', 'Type ou déclenchement invalide.' );
		}
		$m       = Catalog::material_by_no( Ui::post_str( 'p_material', 20 ) );
		$q       = Ui::post_float( 'p_qty' );
		$partner = Ui::post_str( 'p_partner', 20 );
		$p       = array();
		$need    = '';
		switch ( $type ) {
			case 'unannounced_delivery':
				$sid = self::partner_id( 'PO', $partner );
				if ( ! $m || ! $sid || ! \LogiERP\Domain\Purchasing::info_record( $sid, (int) $m['id'] ) ) {
					$need = 'Indiquez un fournisseur et un article qu\'il livre.';
				}
				$p = array( 'supplier_id' => $sid, 'material_id' => $m ? (int) $m['id'] : 0, 'qty' => $q ?: 1, 'damage' => Ui::post_str( 'p_reason', 60 ) );
				break;
			case 'block_bin':
				$bin = Catalog::bin_by_code( Ui::post_str( 'p_bin', 40 ) );
				if ( ! $bin ) {
					$need = 'Emplacement inconnu.';
				}
				$p = array( 'bin_code' => $bin ? $bin['code'] : '', 'reason' => Ui::post_str( 'p_reason', 120 ) ?: 'Incident', 'out' => Ui::post_bool( 'p_out' ) ? 1 : 0 );
				break;
			case 'urgent_order':
				$cid = self::partner_id( 'SO', $partner );
				if ( ! $m || ! $cid || $q <= 0 ) {
					$need = 'Indiquez un client, un article et une quantité.';
				}
				$p = array( 'customer_id' => $cid, 'ref' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', Ui::post_str( 'p_ref', 20 ) ) ), 'items' => array( array( 'material_id' => $m ? (int) $m['id'] : 0, 'qty' => $q ) ) );
				break;
			case 'inventory_gap':
				$bin = Catalog::bin_by_code( Ui::post_str( 'p_bin', 40 ) );
				if ( ! $m || ! $bin || 0.0 === $q ) {
					$need = 'Indiquez l\'article, son emplacement et l\'écart (non nul).';
				}
				$p = array( 'material_id' => $m ? (int) $m['id'] : 0, 'bin_code' => $bin ? $bin['code'] : '', 'location_id' => $bin ? (int) $bin['storage_location_id'] : 0, 'delta' => $q );
				break;
			case 'cancel_order':
			case 'damaged_delivery':
			case 'supplier_late':
				$p = array( 'ref' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', Ui::post_str( 'p_ref', 20 ) ) ), 'damage' => Ui::post_str( 'p_reason', 60 ) );
				if ( '' === $p['ref'] ) {
					$need = 'Indiquez la référence du document (PO1, SO1…).';
				}
				break;
		}
		if ( '' !== $need ) {
			$this->back( $id, 'error', $need );
		}
		Db::insert( 'scenario_event', array( 'scenario_id' => $id, 'type' => $type, 'trigger_type' => $trig, 'trigger_value' => max( 0, min( 500, Ui::post_int( 'trigger_value' ) ) ), 'params' => wp_json_encode( $p ), 'message' => Ui::post_str( 'message', 255 ) ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Événement ajouté.' );
	}

	public function handle_del_event(): void {
		Ui::guard( 'logierp_sc_del_event', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		global $wpdb;
		$wpdb->delete( Db::t( 'scenario_event' ), array( 'id' => Ui::post_int( 'event_id' ), 'scenario_id' => $id ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Événement retiré.' );
	}

	public function handle_save_tasks(): void {
		global $wpdb;
		Ui::guard( 'logierp_sc_save_tasks', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$titles = isset( $_POST['task_title'] ) && is_array( $_POST['task_title'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['task_title'] ) ) : array();
		$bodies = isset( $_POST['task_body'] ) && is_array( $_POST['task_body'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['task_body'] ) ) : array();
		$codes  = isset( $_POST['task_code'] ) && is_array( $_POST['task_code'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['task_code'] ) ) : array();
		// phpcs:enable
		$wpdb->delete( Db::t( 'scenario_task' ), array( 'scenario_id' => $id ) );
		$seq = 0;
		foreach ( array_slice( $titles, 0, 50 ) as $i => $title ) {
			if ( '' === trim( $title ) ) {
				continue;
			}
			$code = \LogiERP\Core\Tcodes::normalize( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $codes[ $i ] ?? '' ) );
			Db::insert( 'scenario_task', array( 'scenario_id' => $id, 'seq' => ++$seq, 'title' => mb_substr( $title, 0, 190 ), 'body' => mb_substr( (string) ( $bodies[ $i ] ?? '' ), 0, 2000 ), 'transaction_code' => mb_substr( $code, 0, 20 ) ) );
		}
		self::touch( $id );
		$this->back( $id, 'success', 'Consignes enregistrées.' );
	}

	public function handle_add_check(): void {
		Ui::guard( 'logierp_sc_add_check', Capabilities::SCENARIOS );
		$id   = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		$type = Ui::post_str( 'type', 30 );
		if ( ! isset( Grader::TYPES[ $type ] ) ) {
			$this->back( $id, 'error', 'Type de critère inconnu.' );
		}
		$m    = Catalog::material_by_no( Ui::post_str( 'p_material', 20 ) );
		$ref  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', Ui::post_str( 'p_ref', 20 ) ) );
		$q    = Ui::post_float( 'p_qty' );
		$st   = array_values( array_filter( array_map( static fn( $s ) => strtoupper( trim( $s ) ), explode( ',', Ui::post_str( 'p_status', 120 ) ) ) ) );
		$loc  = preg_replace( '/[^0-9]/', '', Ui::post_str( 'p_location', 10 ) );
		$bin  = strtoupper( Ui::post_str( 'p_bin', 40 ) );
		$p    = array();
		$need = '';
		switch ( $type ) {
			case 'doc_status':
				$p = array( 'ref' => $ref, 'status' => $st );
				$need = '' === $ref || ! $st ? 'Référence et statut attendus obligatoires.' : '';
				break;
			case 'movement_exists':
				$p = array( 'movement' => preg_replace( '/[^0-9]/', '', Ui::post_str( 'p_movement', 3 ) ), 'ref' => $ref, 'material_id' => $m ? (int) $m['id'] : 0, 'min_count' => max( 1, (int) $q ) );
				$need = '' === $p['movement'] ? 'Code mouvement obligatoire.' : '';
				break;
			case 'stock_delta':
				$p = array( 'material_id' => $m ? (int) $m['id'] : 0, 'location_code' => $loc, 'status' => $st[0] ?? '', 'delta' => $q );
				$need = ! $m ? 'Article obligatoire.' : '';
				break;
			case 'stock_qty':
				$p = array( 'material_id' => $m ? (int) $m['id'] : 0, 'location_code' => $loc, 'bin_code' => $bin, 'status' => $st[0] ?? '', 'min' => $q, 'max' => Ui::post_str( 'p_max', 20 ) );
				$need = ! $m ? 'Article obligatoire.' : '';
				break;
			case 'po_received_qty':
				$p = array( 'ref' => $ref, 'material_id' => $m ? (int) $m['id'] : 0, 'qty' => $q );
				$need = ! $m || '' === $ref ? 'Référence et article obligatoires.' : '';
				break;
			case 'reserve_recorded':
				$p = array( 'ref' => $ref );
				break;
			case 'cmr_signed':
				$p    = array( 'ref' => $ref, 'reserves' => Ui::post_bool( 'p_reserves' ) );
				$need = '' === $ref ? 'Référence de la commande obligatoire (ex. PO1).' : '';
				break;
			case 'qa_decision':
				$dec  = array_values( array_intersect( $st, array_keys( \LogiERP\Domain\Quality::DECISIONS ) ) );
				$p    = array( 'material_id' => $m ? (int) $m['id'] : 0, 'decision' => $dec );
				$need = ! $m || ! $dec ? 'Article et décision attendue obligatoires (champ « Statut(s) » : LIBERER, BLOQUER, QUARANTAINE, DETRUIRE ou RETOUR).' : '';
				break;
			case 'po_ordered':
				$p    = array( 'material_id' => $m ? (int) $m['id'] : 0, 'min' => max( 0.001, $q ) );
				$need = ! $m ? 'Article obligatoire.' : '';
				break;
			case 'prod_planned':
				$p    = array( 'material_id' => $m ? (int) $m['id'] : 0, 'min' => max( 1, $q ), 'not_before' => max( 0, (int) Ui::post_str( 'p_max', 5 ) ) );
				$need = ! $m ? 'Produit fini obligatoire.' : '';
				break;
			case 'staged':
				$p    = array( 'material_id' => $m ? (int) $m['id'] : 0 );
				$need = ! $m ? 'Produit fini obligatoire.' : '';
				break;
			case 'tours_ok':
				$p = $q > 0 ? array( 'min' => (int) $q ) : array();
				break;
			case 'pick_mode':
				$mode = in_array( 'PARALLELE', $st, true ) ? 'PARALLELE' : 'SERIE';
				$p    = array( 'ref' => $ref, 'mode' => $mode );
				$need = '' === $ref ? 'Référence de la commande client obligatoire (ex. SO1) ; « Statut(s) » : SERIE ou PARALLELE.' : '';
				break;
			case 'consolidated':
				$ty   = array_values( array_intersect( $st, array_keys( \LogiERP\Domain\Picking::HU_TYPES ) ) );
				$p    = array( 'ref' => $ref, 'max_units' => max( 0, (int) $q ), 'type' => $ty[0] ?? '', 'mixed' => Ui::post_bool( 'p_reserves' ) ? 1 : 0 );
				$need = '' === $ref ? 'Référence de la commande client obligatoire (ex. SO1) ; « Quantité » = nombre maximal d\'unités, « Statut(s) » = EUR, CP3, PERDUE ou COLIS.' : '';
				break;
			case 'expired_free':
			case 'support_ok':
			case 'packaging_returns':
				$p = array();
				break;
			case 'photo_documented':
				$p    = array( 'ref' => $ref );
				$need = '' === $ref ? 'Référence de la commande obligatoire (ex. PO1).' : '';
				break;
			case 'nothing_in_zone':
				$p = array( 'location_code' => $loc ?: '100' );
				break;
			case 'carrier_used':
				$p = array( 'ref' => $ref, 'carrier' => strtoupper( Ui::post_str( 'p_carrier', 10 ) ) );
				break;
			case 'replenished':
				$p = array( 'bin_code' => $bin );
				break;
			case 'max_errors':
				$p = array( 'max' => (int) $q );
				break;
			case 'strategy_respected':
				$p = array( 'require_pick' => 1 );
				break;
		}
		if ( '' !== $need ) {
			$this->back( $id, 'error', $need );
		}
		Db::insert( 'scenario_check', array( 'scenario_id' => $id, 'type' => $type, 'label' => Ui::post_str( 'label' ) ?: Grader::TYPES[ $type ], 'params' => wp_json_encode( $p ), 'points' => max( 0, min( 100, Ui::post_float( 'points' ) ) ) ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Critère ajouté.' );
	}

	public function handle_del_check(): void {
		Ui::guard( 'logierp_sc_del_check', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		self::editable_or_die( $id );
		global $wpdb;
		$wpdb->delete( Db::t( 'scenario_check' ), array( 'id' => Ui::post_int( 'check_id' ), 'scenario_id' => $id ) );
		self::touch( $id );
		$this->back( $id, 'success', 'Critère retiré.' );
	}

	/* ───────── Export / import signé ───────── */

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, (string) get_option( 'logierp_hmac_key', '' ) );
	}

	/** Remplace les identifiants internes par des numéros métier (portable entre sites). */
	private static function portable( array $p ): array {
		if ( isset( $p['material_id'] ) ) {
			$p['material_no'] = self::mat_no( (int) $p['material_id'] );
			unset( $p['material_id'] );
		}
		if ( isset( $p['items'] ) && is_array( $p['items'] ) ) {
			$p['items'] = array_map( array( self::class, 'portable' ), $p['items'] );
		}
		foreach ( array( 'supplier_id' => 'PO', 'customer_id' => 'SO' ) as $k => $t ) {
			if ( isset( $p[ $k ] ) ) {
				$x = \LogiERP\Domain\Api::partner_full( 'PO' === $t ? 'supplier' : 'customer', (int) $p[ $k ] );
				$p[ str_replace( '_id', '_no', $k ) ] = $x ? ( $x['supplier_no'] ?? $x['customer_no'] ) : '';
				unset( $p[ $k ] );
			}
		}
		return $p;
	}

	private static function local( array $p ): array {
		if ( isset( $p['material_no'] ) ) {
			$m = Catalog::material_by_no( (string) $p['material_no'] );
			$p['material_id'] = $m ? (int) $m['id'] : 0;
			unset( $p['material_no'] );
		}
		if ( isset( $p['items'] ) && is_array( $p['items'] ) ) {
			$p['items'] = array_map( array( self::class, 'local' ), $p['items'] );
		}
		foreach ( array( 'supplier_no' => 'PO', 'customer_no' => 'SO' ) as $k => $t ) {
			if ( isset( $p[ $k ] ) ) {
				$p[ str_replace( '_no', '_id', $k ) ] = self::partner_id( $t, (string) $p[ $k ] );
				unset( $p[ $k ] );
			}
		}
		return $p;
	}

	public function handle_export(): void {
		global $wpdb;
		Ui::guard( 'logierp_sc_export', Capabilities::SCENARIOS );
		$id = Ui::post_int( 'id' );
		$sc = self::editable_or_die( $id );
		$data = array(
			'format'   => 'logierp-scenario',
			'version'  => 1,
			'scenario' => array_intersect_key( $sc, array_flip( array( 'title', 'objectives', 'instructions', 'level', 'duration_min', 'modules', 'location_mode', 'guided', 'allow_strategy_override', 'check_digits', 'hv_key', 'sim_date', 'initial_stock_mode', 'expiry_mode', 'doc_criteria', 'doc_notes' ) ) ),
		);
		foreach ( array( 'scenario_document' => 'documents', 'scenario_event' => 'events', 'scenario_task' => 'tasks', 'scenario_check' => 'checks', 'scenario_initial_stock' => 'initial_stock' ) as $t => $key ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( $t ) . ' WHERE scenario_id = %d ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$data[ $key ] = array_map(
				static function ( $r ) use ( $t ) {
					unset( $r['id'], $r['scenario_id'] );
					foreach ( array( 'items', 'params' ) as $json ) {
						if ( isset( $r[ $json ] ) ) {
							$v          = json_decode( (string) $r[ $json ], true ) ?: array();
							$r[ $json ] = 'items' === $json ? array_map( array( self::class, 'portable' ), $v ) : self::portable( $v );
						}
					}
					if ( isset( $r['partner_id'] ) && 'scenario_document' === $t ) {
						$r['partner_no'] = ( self::portable( array( ( 'PO' === $r['doc_type'] ? 'supplier_id' : 'customer_id' ) => (int) $r['partner_id'] ) ) )[ 'PO' === $r['doc_type'] ? 'supplier_no' : 'customer_no' ] ?? '';
						unset( $r['partner_id'] );
					}
					if ( 'scenario_initial_stock' === $t ) {
						$r['material_no'] = self::mat_no( (int) $r['material_id'] );
						$b                = (int) $r['bin_id'] ? Catalog::bin( (int) $r['bin_id'] ) : null;
						$l                = Catalog::location( (int) $r['storage_location_id'] );
						$r['place']       = $b ? $b['code'] : ( $l ? $l['code'] : '' );
						unset( $r['material_id'], $r['bin_id'], $r['storage_location_id'] );
					}
					return $r;
				},
				$rows
			);
		}
		$payload = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$out     = (string) wp_json_encode( array( 'signature' => self::sign( $payload ), 'payload' => $payload ), JSON_UNESCAPED_UNICODE );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="logierp-scenario-' . $id . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- téléchargement JSON.
		exit;
	}

	public function handle_import(): void {
		Ui::guard( 'logierp_sc_import', Capabilities::SCENARIOS );
		if ( ! RateLimiter::hit( 'import' ) ) {
			$this->back( 0, 'error', 'Trop d\'imports en peu de temps : réessayez dans une heure.' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 5 * MB_IN_BYTES ) {
			$this->back( 0, 'error', 'Fichier manquant ou trop volumineux (5 Mo maximum).' );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], array( 'json' => 'application/json' ) );
		if ( 'json' !== $check['ext'] && ! str_ends_with( strtolower( (string) $file['name'] ), '.json' ) ) {
			$this->back( 0, 'error', 'Le fichier doit être un export JSON de LogiERP.' );
		}
		$raw = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier téléversé temporaire, jamais conservé.
		$env = json_decode( $raw, true );
		if ( ! is_array( $env ) || ! isset( $env['payload'] ) || ! is_string( $env['payload'] ) ) {
			$this->back( 0, 'error', 'Format de fichier invalide.' );
		}
		$signed = isset( $env['signature'] ) && hash_equals( self::sign( $env['payload'] ), (string) $env['signature'] );
		if ( ! $signed && ! ( current_user_can( Capabilities::SETTINGS ) && Ui::post_bool( 'unsigned_ok' ) ) ) {
			$this->back( 0, 'error', 'Signature absente ou invalide : seul un administrateur peut importer ce fichier, en le confirmant explicitement.' );
		}
		$data = json_decode( $env['payload'], true );
		if ( ! is_array( $data ) || 'logierp-scenario' !== ( $data['format'] ?? '' ) || ! is_array( $data['scenario'] ?? null ) ) {
			$this->back( 0, 'error', 'Ce fichier n\'est pas un scénario LogiERP.' );
		}
		$s     = $data['scenario'];
		$level = (string) ( $s['level'] ?? 'debutant' );
		$date  = (string) ( $s['sim_date'] ?? '' );
		$sid   = Db::insert(
			'scenario',
			array(
				'owner_id'                => get_current_user_id(),
				'title'                   => mb_substr( sanitize_text_field( (string) ( $s['title'] ?? 'Scénario importé' ) ), 0, 180 ) . ' (import)',
				'objectives'              => sanitize_textarea_field( (string) ( $s['objectives'] ?? '' ) ),
				'instructions'            => sanitize_textarea_field( (string) ( $s['instructions'] ?? '' ) ),
				'level'                   => isset( self::LEVELS[ $level ] ) ? $level : 'debutant',
				'duration_min'            => max( 5, min( 600, (int) ( $s['duration_min'] ?? 45 ) ) ),
				'modules'                 => implode( ',', array_intersect( explode( ',', strtoupper( (string) ( $s['modules'] ?? '' ) ) ), array_keys( self::MODULES ) ) ),
				'location_mode'           => 'SIMPLE' === ( $s['location_mode'] ?? '' ) ? 'SIMPLE' : 'DETAILED',
				'guided'                  => empty( $s['guided'] ) ? 0 : 1,
				'allow_strategy_override' => empty( $s['allow_strategy_override'] ) ? 0 : 1,
				'check_digits'            => empty( $s['check_digits'] ) ? 0 : 1,
				'hv_key'                  => empty( $s['hv_key'] ) ? 0 : 1,
				'expiry_mode'             => 'MANUEL' === ( $s['expiry_mode'] ?? 'AUTO' ) ? 'MANUEL' : 'AUTO',
				'sim_date'                => \LogiERP\Domain\Api::valid_date( $date ) ? $date : \LogiERP\Seeder\StockSeeder::base_date(),
				'initial_stock_mode'      => 'EMPTY' === ( $s['initial_stock_mode'] ?? '' ) ? 'EMPTY' : 'BASE',
				'doc_criteria'            => isset( $s['doc_criteria'] ) && empty( $s['doc_criteria'] ) ? 0 : 1,
				'doc_notes'               => sanitize_textarea_field( (string) ( $s['doc_notes'] ?? '' ) ),
				'dataset_id'              => Catalog::dataset(),
				'status'                  => 'DRAFT',
				'created_at'              => Db::now(),
				'updated_at'              => Db::now(),
			)
		);
		foreach ( array_slice( (array) ( $data['documents'] ?? array() ), 0, 100 ) as $d ) {
			$type = in_array( $d['doc_type'] ?? '', array( 'PO', 'SO', 'PROD' ), true ) ? $d['doc_type'] : null;
			if ( ! $type ) {
				continue;
			}
			Db::insert( 'scenario_document', array( 'scenario_id' => $sid, 'ref' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $d['ref'] ?? 'DOC' ) ) ), 'doc_type' => $type, 'partner_id' => 'PROD' === $type ? 0 : self::partner_id( $type, (string) ( $d['partner_no'] ?? '' ) ), 'day_offset' => (int) ( $d['day_offset'] ?? 0 ), 'priority' => in_array( $d['priority'] ?? 'B', array( 'A', 'B', 'C' ), true ) ? $d['priority'] : 'B', 'note' => mb_substr( sanitize_text_field( (string) ( $d['note'] ?? '' ) ), 0, 255 ), 'items' => wp_json_encode( array_map( array( self::class, 'local' ), array_slice( (array) ( $d['items'] ?? array() ), 0, 50 ) ) ) ) );
		}
		foreach ( array_slice( (array) ( $data['events'] ?? array() ), 0, 100 ) as $e ) {
			if ( ! isset( Events::TYPES[ $e['type'] ?? '' ], Events::TRIGGERS[ $e['trigger_type'] ?? '' ] ) ) {
				continue;
			}
			Db::insert( 'scenario_event', array( 'scenario_id' => $sid, 'type' => $e['type'], 'trigger_type' => $e['trigger_type'], 'trigger_value' => max( 0, (int) ( $e['trigger_value'] ?? 0 ) ), 'params' => wp_json_encode( self::local( (array) ( $e['params'] ?? array() ) ) ), 'message' => mb_substr( sanitize_text_field( (string) ( $e['message'] ?? '' ) ), 0, 255 ) ) );
		}
		foreach ( array_slice( (array) ( $data['tasks'] ?? array() ), 0, 50 ) as $i => $t ) {
			Db::insert( 'scenario_task', array( 'scenario_id' => $sid, 'seq' => $i + 1, 'title' => mb_substr( sanitize_text_field( (string) ( $t['title'] ?? '' ) ), 0, 190 ), 'body' => sanitize_textarea_field( (string) ( $t['body'] ?? '' ) ), 'transaction_code' => strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $t['transaction_code'] ?? '' ) ) ) ) );
		}
		foreach ( array_slice( (array) ( $data['checks'] ?? array() ), 0, 100 ) as $c ) {
			if ( ! isset( Grader::TYPES[ $c['type'] ?? '' ] ) ) {
				continue;
			}
			Db::insert( 'scenario_check', array( 'scenario_id' => $sid, 'type' => $c['type'], 'label' => mb_substr( sanitize_text_field( (string) ( $c['label'] ?? '' ) ), 0, 190 ), 'params' => wp_json_encode( self::local( (array) ( $c['params'] ?? array() ) ) ), 'points' => max( 0, min( 100, (float) ( $c['points'] ?? 1 ) ) ) ) );
		}
		foreach ( array_slice( (array) ( $data['initial_stock'] ?? array() ), 0, 500 ) as $r ) {
			$m   = Catalog::material_by_no( (string) ( $r['material_no'] ?? '' ) );
			$bin = Catalog::bin_by_code( (string) ( $r['place'] ?? '' ) );
			$loc = $bin ? Catalog::location( (int) $bin['storage_location_id'] ) : Catalog::location_by_code( (string) ( $r['place'] ?? '' ) );
			if ( ! $m || ! $loc ) {
				continue;
			}
			$row = array( 'scenario_id' => $sid, 'material_id' => (int) $m['id'], 'storage_location_id' => (int) $loc['id'], 'bin_id' => $bin ? (int) $bin['id'] : 0, 'batch' => strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $r['batch'] ?? '' ) ) ), 'status' => in_array( $r['status'] ?? '', array( 'LIBRE', 'CONTROLE_QUALITE', 'BLOQUE' ), true ) ? $r['status'] : 'LIBRE', 'qty' => max( 0, (float) ( $r['qty'] ?? 0 ) ) );
			if ( \LogiERP\Domain\Api::valid_date( (string) ( $r['bbd'] ?? '' ) ) ) {
				$row['bbd'] = $r['bbd'];
			}
			Db::insert( 'scenario_initial_stock', $row );
		}
		Audit::log( 'scenario_import', 'scenario', $sid, array( 'signed' => $signed ) );
		$this->back( $sid, 'success', 'Scénario importé en brouillon. Vérifiez-le puis publiez-le.' );
	}
}
