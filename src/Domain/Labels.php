<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Étiquettes code-barres à imprimer par l'enseignant (atelier, rayonnages, palettes d'exercice).
 *
 * Chaque étiquette produite ici est lisible par le terminal mobile (Gs1::resolve) :
 *  - article : EAN-13 de la fiche article ;
 *  - emplacement : Code 128 du code d'emplacement ;
 *  - unité logistique : étiquette GS1 complète (SSCC, contenu, lot, date limite) d'un arrivage du scénario ;
 *  - document : Code 128 du n° de commande d'achat (ouvre la réception) ;
 *  - libre : Code 128 d'un texte quelconque.
 */
final class Labels {

	public const TYPES = array(
		'article'  => 'Articles (EAN-13)',
		'bin'      => 'Emplacements (Code 128)',
		'sscc'     => 'Palettes et colis d\'un arrivage (SSCC, étiquette GS1)',
		'document' => 'Documents d\'un scénario (n° de commande)',
		'free'     => 'Étiquettes libres (Code 128)',
	);

	/** Formats de planche A4 : [libellé, largeur mm, hauteur mm, colonnes, lignes]. */
	public const FORMATS = array(
		'S'  => array( 'Petites 70 × 37 mm – 24 par page A4', 70, 37.125, 3, 8 ),
		'M'  => array( 'Moyennes 105 × 74 mm – 8 par page A4', 105, 74.25, 2, 4 ),
		'L'  => array( 'Palette A6 105 × 148 mm – 4 par page A4', 105, 148.5, 2, 2 ),
		'XL' => array( 'Panneau A5 210 × 148 mm – 2 par page A4', 210, 148.5, 1, 2 ),
	);

	public const DEFAULT_FORMAT = array( 'article' => 'S', 'bin' => 'M', 'sscc' => 'L', 'document' => 'M', 'free' => 'S' );

	/** Nombre maximal d'étiquettes par impression (protège le serveur et le navigateur). */
	public const MAX = 1200;

	/** Articles cités par un scénario : documents, stock de départ, événements. */
	/** Articles cités par le scénario : documents (et composants des ordres de fabrication), stock de départ, imprévus. */
	public static function scenario_material_ids( int $scenario_id ): array {
		global $wpdb;
		$ids = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT doc_type, items FROM ' . Db::t( 'scenario_document' ) . ' WHERE scenario_id = %d ORDER BY id', $scenario_id ), ARRAY_A ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) json_decode( (string) $d['items'], true ) as $i ) {
				$mid   = (int) ( $i['material_id'] ?? 0 );
				$ids[] = $mid;
				if ( 'PROD' === $d['doc_type'] && $mid ) {
					foreach ( (array) ( Production::bom( $mid )['items'] ?? array() ) as $c ) {
						$ids[] = (int) $c['component_id'];
					}
				}
			}
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT material_id FROM ' . Db::t( 'scenario_initial_stock' ) . ' WHERE scenario_id = %d ORDER BY id', $scenario_id ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids[] = (int) $id;
		}
		foreach ( Events::events_of( $scenario_id ) as $e ) {
			$p     = json_decode( (string) $e['params'], true ) ?: array();
			$ids[] = (int) ( $p['material_id'] ?? 0 );
			foreach ( (array) ( $p['items'] ?? array() ) as $i ) {
				$ids[] = (int) ( $i['material_id'] ?? 0 );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function scenario_materials( int $scenario_id ): array {
		$out = array();
		foreach ( self::scenario_material_ids( $scenario_id ) as $id ) {
			$m = Catalog::material( $id );
			if ( $m ) {
				$out[] = $m['material_no'];
			}
		}
		return $out;
	}

	/**
	 * Étiquettes d'articles.
	 *
	 * @param array<int,array{0:string,1:int}> $wanted [n° d'article, nombre d'exemplaires]
	 * @return array{0:array,1:string[]} [étiquettes, erreurs]
	 */
	public static function articles( array $wanted ): array {
		$out = array();
		$err = array();
		foreach ( $wanted as [ $no, $copies ] ) {
			$m = Catalog::material_by_no( $no );
			if ( ! $m ) {
				$err[] = sprintf( 'Article %s introuvable.', $no );
				continue;
			}
			$ean = (string) $m['ean'];
			if ( ! Gs1::is_ean13( $ean ) ) {
				$err[] = sprintf( 'Article %s : EAN-13 invalide.', $no );
				continue;
			}
			$tags = array();
			if ( '' !== (string) $m['adr_class'] ) {
				$tags[] = 'ADR ' . $m['adr_class'];
			}
			if ( null !== $m['temp_max'] && '' !== (string) $m['temp_max'] ) {
				$tags[] = Catalog::format_qty( (float) $m['temp_min'] ) . '…' . Catalog::format_qty( (float) $m['temp_max'] ) . ' °C';
			}
			if ( (int) $m['fragile'] ) {
				$tags[] = 'Fragile';
			}
			$label = array(
				'kind' => 'article',
				'no'   => (string) $m['material_no'],
				'name' => (string) $m['designation'],
				'uom'  => (string) $m['base_uom'],
				'ean'  => $ean,
				'tags' => $tags,
			);
			for ( $k = 0; $k < $copies; $k++ ) {
				$out[] = $label;
			}
		}
		return array( $out, $err );
	}

	/**
	 * Étiquettes d'emplacements d'un magasin.
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function bins( string $location, string $prefix, bool $fixed_only, string $arrow, int $copies ): array {
		global $wpdb;
		$loc = Catalog::location_by_code( $location );
		if ( ! $loc ) {
			return array( array(), array( 'Magasin inconnu.' ) );
		}
		$b     = Db::t( 'bin' );
		$where = $wpdb->prepare( 'storage_location_id = %d', (int) $loc['id'] );
		if ( '' !== $prefix ) {
			$where .= $wpdb->prepare( ' AND code LIKE %s', $wpdb->esc_like( strtoupper( $prefix ) ) . '%' );
		}
		if ( $fixed_only ) {
			$where .= ' AND fixed_material_id > 0';
		}
		$rows = (array) $wpdb->get_results( "SELECT code, bin_type, level, fixed_material_id FROM {$b} WHERE {$where} ORDER BY pick_sequence, code LIMIT " . (int) self::MAX, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return array( array(), array( 'Aucun emplacement ne correspond à cette sélection.' ) );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$fixed = (int) $r['fixed_material_id'] ? Catalog::material( (int) $r['fixed_material_id'] ) : null;
			$label = array(
				'kind'  => 'bin',
				'code'  => (string) $r['code'],
				'check' => Catalog::check_digit( (string) $r['code'] ),
				'zone'  => $loc['code'] . ' – ' . $loc['name'],
				'type'  => (string) $r['bin_type'],
				'arrow' => in_array( $arrow, array( 'up', 'down' ), true ) ? $arrow : '',
				'fixed' => $fixed ? $fixed['material_no'] . ' · ' . $fixed['designation'] : '',
			);
			for ( $k = 0; $k < $copies; $k++ ) {
				$out[] = $label;
			}
		}
		return array( $out, array() );
	}

	/**
	 * Session d'essai de l'enseignant pour un scénario : ses documents (numéros, SSCC, lots) sont
	 * identiques à ceux des apprentis, ce qui rend les étiquettes valables pour toute la classe.
	 */
	public static function reference_session( int $scenario_id, int $user_id ): Ctx {
		global $wpdb;
		$sc = SessionService::scenario( $scenario_id );
		if ( ! $sc ) {
			throw new DomainError( 'Scénario introuvable.' );
		}
		$t   = Db::t( 'session' );
		$al  = Db::t( 'action_log' );
		// Session de test intacte (aucune saisie) : le stock et les documents sont ceux du départ du scénario.
		$sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT s.id FROM {$t} s WHERE s.scenario_id = %d AND s.user_id = %d AND s.is_test = 1 AND s.status = 'EN_COURS' AND s.scenario_version = %d AND NOT EXISTS (SELECT 1 FROM {$al} a WHERE a.session_id = s.id) ORDER BY s.id DESC LIMIT 1", $scenario_id, $user_id, (int) $sc['version'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $sid ) {
			$sid = SessionService::start( $user_id, 0, $scenario_id );
		}
		$ctx = SessionService::context_for( $sid, $user_id, false );
		if ( ! $ctx ) {
			throw new DomainError( 'Session de référence indisponible.' );
		}
		return $ctx;
	}

	/** Commandes d'achat préparées par le scénario (référence => numéro). */
	private static function scenario_pos( Ctx $ctx ): array {
		$out = array();
		foreach ( (array) ( $ctx->doc_map()['refs'] ?? array() ) as $ref => $d ) {
			if ( 'PO' === ( $d['type'] ?? '' ) ) {
				$out[ (string) $ref ] = (string) $d['no'];
			}
		}
		return $out;
	}

	/**
	 * Étiquettes logistiques GS1 des arrivages du scénario (une par palette ou colis).
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function sscc( int $scenario_id, int $user_id ): array {
		$ctx  = self::reference_session( $scenario_id, $user_id );
		$out  = array();
		$co   = Company::get();
		$to   = array( (string) Company::name(), implode( ', ', array_filter( array_map( 'trim', explode( "\n", (string) ( $co['address'] ?? '' ) ) ) ) ) );
		foreach ( self::scenario_pos( $ctx ) as $ref => $no ) {
			$po = Purchasing::detail( $ctx, $no );
			foreach ( $po['items'] as $i ) {
				$dn = $i['delivery_note'];
				$m  = $dn['material'] ?: $i['material'];
				$gtin = ! empty( $m['ean'] ) ? Gs1::gtin14( (string) $m['ean'] ) : '';
				foreach ( (array) $dn['units'] as $u ) {
					$content = '' !== $gtin ? '(02)' . $gtin . ( $dn['bbd'] ? '(17)' . gmdate( 'ymd', (int) strtotime( (string) $dn['bbd'] ) ) : '' ) . '(37)' . (int) round( (float) $u['qty'] ) : '';
					$out[]   = array(
						'kind'    => 'sscc',
						'sscc'    => (string) $u['sscc'],
						'from'    => array( $po['supplier'] ? (string) $po['supplier']['name'] : '', $po['supplier'] ? $po['supplier']['country'] . '-' . $po['supplier']['city'] : '' ),
						'to'      => $to,
						'gtin'    => $gtin,
						'article' => ( $m['no'] ?? '' ) . ' · ' . ( $m['designation'] ?? '' ),
						'qty'     => Catalog::format_qty( (float) $u['qty'] ) . ' ' . ( $m['uom'] ?? '' ) . ( (int) $u['cartons'] > 1 ? ' (' . (int) $u['cartons'] . ' cartons)' : '' ),
						'batch'   => (string) $dn['batch'],
						'bbd'     => $dn['bbd'] ? gmdate( 'd.m.Y', (int) strtotime( (string) $dn['bbd'] ) ) : '',
						'unit'    => $u['type'] . ' ' . $u['n'] . ' / ' . $u['of'],
						'order'   => $po['po_no'] . ' / pos. ' . $i['line_no'] . ' (réf. ' . $ref . ')',
						'content' => $content,
						'damage'  => (string) $dn['damage'],
					);
				}
			}
		}
		return array( $out, $out ? array() : array( 'Ce scénario ne prépare aucune commande d\'achat à réceptionner.' ) );
	}

	/**
	 * Étiquettes des n° de commande d'achat du scénario (scan = ouverture de la réception).
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function documents( int $scenario_id, int $user_id, int $copies ): array {
		$ctx = self::reference_session( $scenario_id, $user_id );
		$out = array();
		foreach ( self::scenario_pos( $ctx ) as $ref => $no ) {
			$po    = Purchasing::detail( $ctx, $no );
			$label = array(
				'kind'  => 'document',
				'code'  => $no,
				'title' => 'Commande d\'achat ' . $no,
				'sub'   => ( $po['supplier'] ? $po['supplier']['no'] . ' · ' . $po['supplier']['name'] : '' ) . ' · réf. ' . $ref,
				'hint'  => 'Scanner au terminal mobile : ouvre la réception de la commande.',
			);
			for ( $k = 0; $k < $copies; $k++ ) {
				$out[] = $label;
			}
		}
		return array( $out, $out ? array() : array( 'Ce scénario ne prépare aucune commande d\'achat.' ) );
	}

	/**
	 * Étiquettes libres : une ligne « texte;code » (ou seulement le code).
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function free( string $text, int $copies ): array {
		$out = array();
		$err = array();
		foreach ( preg_split( '/\R/', $text ) ?: array() as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ';', $line, 2 ) );
			$code  = $parts[1] ?? $parts[0];
			$title = isset( $parts[1] ) ? $parts[0] : '';
			if ( '' === $code || strlen( $code ) > 40 || ! preg_match( '/^[\x20-\x7E]+$/', $code ) ) {
				$err[] = sprintf( 'Ligne %d : code vide, trop long (40 caractères au plus) ou avec des caractères non codables (lettres accentuées).', $n + 1 );
				continue;
			}
			for ( $k = 0; $k < $copies; $k++ ) {
				$out[] = array( 'kind' => 'free', 'code' => $code, 'title' => mb_substr( $title, 0, 60 ) );
			}
		}
		return array( $out, $err );
	}
}
