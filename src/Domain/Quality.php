<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestion de la qualité (QA32 / QA11) : lots de contrôle créés à la réception en contrôle qualité,
 * à l'atteinte de la date limite (blocage automatique) ou lors d'une mise en quarantaine.
 * Déroulement : prélèvement d'un échantillon (ordre de transfert, mouvement 331/333/335),
 * rapport du laboratoire, saisie du résultat, décision d'emploi.
 */
final class Quality {

	/** Profils périssables : une date limite dépassée ne peut jamais être prolongée. */
	public const PERISHABLE = array( 'COLD', 'FROZ' );

	public const ORIGINS = array( 'GR' => 'Réception (contrôle à l\'entrée)', 'DLC' => 'Date limite atteinte', 'MAN' => 'Mise en quarantaine' );

	public const DECISIONS = array(
		'LIBERER'     => 'Libérer (stock libre)',
		'BLOQUER'     => 'Confirmer le blocage',
		'QUARANTAINE' => 'Bloquer et mettre en quarantaine (zone 800)',
		'DETRUIRE'    => 'Détruire (mouvement 551)',
		'RETOUR'      => 'Retourner au fournisseur (mouvement 122)',
	);

	public const STATUS_LABELS = array( 'OUVERT' => 'À échantillonner', 'ECHANTILLON' => 'Échantillon à prélever', 'ANALYSE' => 'Résultat disponible', 'DECIDE' => 'Décision prise' );

	private const SAMPLE_MVT = array( 'CONTROLE_QUALITE' => '331', 'LIBRE' => '333', 'BLOQUE' => '335' );

	public static function perishable( array $m ): bool {
		return in_array( (string) $m['profile_code'], self::PERISHABLE, true ) || ( (int) $m['shelf_life_days'] > 0 && (int) $m['shelf_life_days'] <= 45 );
	}

	public static function create_lot( Ctx $ctx, string $origin, array $m, string $batch, ?string $bbd, string $status, float $qty, string $ref_no = '', int $ref_item_id = 0, string $qa = '' ): string {
		[ $truth, $text ] = self::truth( $origin, $m, $qa );
		$no = NumberRange::next( $ctx->id, 'QL' );
		Db::insert(
			'inspection_lot',
			array(
				'session_id'   => $ctx->id,
				'lot_no'       => $no,
				'origin'       => $origin,
				'ref_no'       => mb_substr( $ref_no, 0, 20 ),
				'ref_item_id'  => $ref_item_id,
				'material_id'  => (int) $m['id'],
				'batch'        => $batch,
				'bbd'          => $bbd ?: null,
				'stock_status' => $status,
				'qty'          => round( $qty, 3 ),
				'truth'        => $truth,
				'truth_text'   => mb_substr( $text, 0, 255 ),
				'status'       => 'OUVERT',
				'created_at'   => Db::now(),
			)
		);
		return $no;
	}

	/** Rapport du laboratoire (connu du système, révélé après l'échantillonnage). */
	private static function truth( string $origin, array $m, string $qa ): array {
		if ( preg_match( '/^(OK|KO)\s*:?\s*(.*)$/s', $qa, $x ) ) {
			return array( $x[1], '' !== trim( $x[2] ) ? trim( $x[2] ) : ( 'OK' === $x[1] ? 'Tous les paramètres sont conformes à la spécification.' : 'Paramètre hors spécification.' ) );
		}
		if ( 'DLC' === $origin ) {
			return self::perishable( $m )
				? array( 'KO', 'Analyse microbiologique non conforme (flore totale au-delà du seuil). Produit périssable dont la date limite est dépassée : impropre à la vente.' )
				: array( 'OK', 'Analyse conforme (aspect, viscosité, teneur). Le fabricant autorise une date de réanalyse à +90 jours.' );
		}
		if ( 'MAN' === $origin ) {
			return array( 'KO', 'Contrôle visuel : emballage endommagé, produit non commercialisable en l\'état.' );
		}
		return array( 'OK', 'Contrôle à réception conforme : identité, quantité, aspect et certificat d\'analyse du fournisseur conformes.' );
	}

	/** Blocage automatique des lots dont la date limite est atteinte (à l'ouverture de la session). */
	public static function expire( Ctx $ctx ): int {
		global $wpdb;
		if ( 'MANUEL' === ( $ctx->scenario['expiry_mode'] ?? 'AUTO' ) ) {
			return 0;
		}
		$st   = Db::t( 'stock' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$st} WHERE session_id = %d AND status = 'LIBRE' AND bbd IS NOT NULL AND bbd < %s AND qty > 0 ORDER BY material_id, batch", $ctx->id, $ctx->date() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lots = array();
		foreach ( $rows as $r ) {
			StockService::post(
				$ctx->id,
				array( 'posting_date' => $ctx->date(), 'ref_type' => 'QM', 'text' => 'Blocage automatique : date limite atteinte' ),
				array(
					array( 'movement_type' => '344', 'material_id' => (int) $r['material_id'], 'location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'status' => 'LIBRE', 'qty' => -(float) $r['qty'], 'reason' => 'DLC atteinte' ),
					array( 'movement_type' => '344', 'material_id' => (int) $r['material_id'], 'location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'status' => 'BLOQUE', 'qty' => (float) $r['qty'], 'gr_date' => $r['gr_date'], 'reason' => 'DLC atteinte' ),
				)
			);
			$key = $r['material_id'] . '|' . $r['batch'];
			$lots[ $key ] = array( 'm' => (int) $r['material_id'], 'batch' => (string) $r['batch'], 'bbd' => $r['bbd'], 'qty' => ( $lots[ $key ]['qty'] ?? 0 ) + (float) $r['qty'] );
		}
		foreach ( $lots as $l ) {
			$m = Catalog::material( $l['m'] );
			if ( $m ) {
				self::create_lot( $ctx, 'DLC', $m, $l['batch'], $l['bbd'], 'BLOQUE', $l['qty'] );
			}
		}
		return count( $lots );
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'inspection_lot' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND lot_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/** Lignes de stock concernées par le lot (même article, lot et statut, où qu'elles soient rangées). */
	public static function rows( Ctx $ctx, array $lot ): array {
		global $wpdb;
		$st = Db::t( 'stock' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$st} WHERE session_id = %d AND material_id = %d AND batch = %s AND status = %s AND qty > 0 ORDER BY qty DESC", $ctx->id, (int) $lot['material_id'], (string) $lot['batch'], (string) $lot['stock_status'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function summary( Ctx $ctx, array $l, bool $full = false ): array {
		$m   = Catalog::material( (int) $l['material_id'] );
		$out = array(
			'lot_no'       => $l['lot_no'],
			'origin'       => $l['origin'],
			'origin_label' => self::ORIGINS[ $l['origin'] ] ?? $l['origin'],
			'ref_no'       => $l['ref_no'],
			'material'     => Api::material_summary( $m ),
			'batch'        => $l['batch'],
			'bbd'          => $l['bbd'],
			'expired'      => $l['bbd'] && $l['bbd'] < $ctx->date() ? 1 : 0,
			'stock_status' => $l['stock_status'],
			'qty'          => (float) $l['qty'],
			'sample_qty'   => (float) $l['sample_qty'],
			'sample_to'    => $l['sample_to'],
			'status'       => $l['status'],
			'status_label' => self::STATUS_LABELS[ $l['status'] ] ?? $l['status'],
			'result'       => $l['result'],
			'decision'     => $l['decision'],
			'decision_label' => self::DECISIONS[ $l['decision'] ] ?? '',
			'created_at'   => $l['created_at'],
		);
		if ( $full ) {
			$out['perishable']    = $m && self::perishable( $m ) ? 1 : 0;
			$out['report']        = in_array( $l['status'], array( 'ANALYSE', 'DECIDE' ), true ) || 'MAN' === $l['origin'] ? array( 'verdict' => $l['truth'], 'text' => $l['truth_text'] ) : null;
			$out['result_text']   = $l['result_text'];
			$out['decision_text'] = $l['decision_text'];
			$out['new_bbd']       = $l['new_bbd'];
			$out['can_return']    = 'GR' === $l['origin'] && (int) $l['ref_item_id'] > 0 ? 1 : 0;
			$out['rows']          = array_map(
				static function ( $r ) {
					$b = (int) $r['bin_id'] ? Catalog::bin( (int) $r['bin_id'] ) : null;
					$c = Catalog::location( (int) $r['storage_location_id'] );
					return array( 'id' => (int) $r['id'], 'location' => $c ? $c['code'] : '', 'bin' => $b ? $b['code'] : '', 'qty' => (float) $r['qty'], 'status' => $r['status'] );
				},
				self::rows( $ctx, $l )
			);
		}
		return $out;
	}

	public static function list_for( Ctx $ctx, bool $open_only = false ): array {
		global $wpdb;
		$t    = Db::t( 'inspection_lot' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d" . ( $open_only ? " AND status <> 'DECIDE'" : '' ) . ' ORDER BY (status = \'DECIDE\'), id LIMIT 200', $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( static fn( $l ) => self::summary( $ctx, $l ), $rows );
	}

	public static function detail( Ctx $ctx, string $no ): array {
		$l = self::by_no( $ctx, $no );
		if ( ! $l ) {
			throw new DomainError( sprintf( 'Lot de contrôle %s introuvable.', $no ) );
		}
		return self::summary( $ctx, $l, true );
	}

	/** Prélèvement d'un échantillon : ordre de transfert (gestion détaillée) ou sortie directe (gestion simple). */
	public static function sample( Ctx $ctx, string $no, float $qty ): array {
		$l = self::by_no( $ctx, $no );
		if ( ! $l ) {
			throw new DomainError( sprintf( 'Lot de contrôle %s introuvable.', $no ) );
		}
		if ( 'OUVERT' !== $l['status'] ) {
			throw new DomainError( 'L\'échantillon de ce lot est déjà demandé ou prélevé.' );
		}
		if ( 'MAN' === $l['origin'] ) {
			throw new DomainError( 'Lot de quarantaine : le contrôle est visuel, passez directement à la décision (QA11).' );
		}
		$rows = self::rows( $ctx, $l );
		if ( ! $rows ) {
			throw new DomainError( 'Le stock de ce lot est introuvable : il a été déplacé ou changé de statut.' );
		}
		$row = $rows[0];
		$qty = round( $qty, 3 );
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 || $qty > max( 1, (float) $l['qty'] * 0.2 ) + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité d\'échantillon invalide : entre 1 et %s (20 %% du lot au maximum).', Catalog::format_qty( min( (float) $row['qty'], max( 1, floor( (float) $l['qty'] * 0.2 ) ) ) ) ) );
		}
		$m = Catalog::material( (int) $l['material_id'] );
		return Db::transaction(
			static function () use ( $ctx, $l, $row, $qty, $m ) {
				if ( $ctx->simple() || ! (int) $row['bin_id'] ) {
					$doc = self::post_sample( $ctx, $l, $row, $qty );
					return array( 'doc_no' => $doc, 'to_no' => '', 'message' => sprintf( 'Échantillon de %s %s prélevé (document %s) : le rapport du laboratoire est disponible.', Catalog::format_qty( $qty ), $m['base_uom'], $doc ) );
				}
				$to_no = NumberRange::next( $ctx->id, 'TO' );
				$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $to_no, 'type' => 'SAMPLE', 'ref_type' => 'QL', 'ref_id' => (int) $l['id'], 'status' => 'OUVERT', 'created_at' => Db::now() ) );
				Db::insert( 'transfer_order_item', array( 'to_id' => $to_id, 'line_no' => 1, 'material_id' => (int) $l['material_id'], 'batch' => (string) $l['batch'], 'bbd' => $l['bbd'] ?: null, 'stock_status' => (string) $l['stock_status'], 'src_location_id' => (int) $row['storage_location_id'], 'src_bin_id' => (int) $row['bin_id'], 'qty' => $qty ) );
				Db::update( 'inspection_lot', array( 'status' => 'ECHANTILLON', 'sample_to' => $to_no ), array( 'id' => (int) $l['id'] ) );
				return array( 'doc_no' => $to_no, 'to_no' => $to_no, 'message' => sprintf( 'Ordre de prélèvement d\'échantillon %s créé : allez prélever %s %s à l\'emplacement indiqué (LT12 ou terminal mobile).', $to_no, Catalog::format_qty( $qty ), $m['base_uom'] ) );
			}
		);
	}

	/** Sortie de l'échantillon (331 / 333 / 335) et passage du lot à « résultat disponible ». */
	public static function post_sample( Ctx $ctx, array $l, array $row, float $qty ): string {
		$mvt = self::SAMPLE_MVT[ $row['status'] ] ?? '333';
		$doc = StockService::post(
			$ctx->id,
			array( 'posting_date' => $ctx->date(), 'ref_type' => 'QL', 'ref_id' => (int) $l['id'], 'ref_no' => $l['lot_no'], 'text' => 'Échantillon lot de contrôle ' . $l['lot_no'] ),
			array( array( 'movement_type' => $mvt, 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty, 'reason' => 'Échantillon pour analyse' ) )
		);
		Db::update( 'inspection_lot', array( 'status' => 'ANALYSE', 'sample_qty' => (float) $l['sample_qty'] + $qty, 'qty' => max( 0, (float) $l['qty'] - $qty ) ), array( 'id' => (int) $l['id'] ) );
		return $doc;
	}

	/** Appelé à la confirmation d'un ordre de prélèvement d'échantillon. */
	public static function lot_by_id( Ctx $ctx, int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'inspection_lot' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND session_id = %d", $id, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/**
	 * Décision d'emploi (QA11).
	 *
	 * @param string $result OK | KO (résultat saisi par l'apprenant après lecture du rapport)
	 */
	public static function decide( Ctx $ctx, string $no, string $result, string $result_text, string $decision, string $text, string $new_bbd = '', string $dst = '' ): array {
		$l = self::by_no( $ctx, $no );
		if ( ! $l ) {
			throw new DomainError( sprintf( 'Lot de contrôle %s introuvable.', $no ) );
		}
		if ( 'DECIDE' === $l['status'] ) {
			throw new DomainError( 'La décision d\'emploi de ce lot est déjà prise.' );
		}
		if ( 'ANALYSE' !== $l['status'] && 'MAN' !== $l['origin'] ) {
			throw new DomainError( 'Prélevez d\'abord l\'échantillon : le résultat d\'analyse n\'est pas encore disponible.' );
		}
		if ( ! in_array( $result, array( 'OK', 'KO' ), true ) ) {
			throw new DomainError( 'Saisissez le résultat du contrôle (conforme ou non conforme).' );
		}
		if ( ! isset( self::DECISIONS[ $decision ] ) ) {
			throw new DomainError( 'Choisissez la décision d\'emploi.' );
		}
		$text = mb_substr( trim( $text ), 0, 255 );
		if ( 'LIBERER' !== $decision && '' === $text ) {
			throw new DomainError( 'Motivez la décision (texte obligatoire pour un blocage, une destruction ou un retour).' );
		}
		$m    = Catalog::material( (int) $l['material_id'] );
		$rows = self::rows( $ctx, $l );
		if ( ! $rows ) {
			throw new DomainError( 'Le stock de ce lot est introuvable : il a été déplacé, consommé ou a changé de statut.' );
		}
		$bbd = $l['bbd'];
		if ( 'LIBERER' === $decision ) {
			if ( 'OK' !== $result ) {
				throw new DomainError( 'Un lot non conforme ne peut pas être libéré.' );
			}
			if ( $bbd && $bbd < $ctx->date() ) {
				if ( self::perishable( $m ) ) {
					throw new DomainError( 'Produit périssable dont la date limite est dépassée : la libération est interdite, même avec une analyse conforme (sécurité alimentaire).' );
				}
				if ( ! Api::valid_date( $new_bbd ) || $new_bbd <= $ctx->date() ) {
					throw new DomainError( 'Date limite dépassée : indiquez la nouvelle date de réanalyse accordée (postérieure à aujourd\'hui).' );
				}
				if ( $new_bbd > gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +180 days' ) ) ) {
					throw new DomainError( 'La prolongation est limitée à 180 jours après une réanalyse.' );
				}
				$bbd = $new_bbd;
			}
		}
		if ( 'RETOUR' === $decision && ( 'GR' !== $l['origin'] || ! (int) $l['ref_item_id'] ) ) {
			throw new DomainError( 'Retour fournisseur impossible : ce lot ne provient pas d\'une réception sur commande. Choisissez la destruction.' );
		}
		$dst_bin = null;
		$dst_loc = null;
		if ( 'QUARANTAINE' === $decision ) {
			$dst_loc = Catalog::location_by_code( '800' );
			if ( ! $ctx->simple() ) {
				$dst_bin = Catalog::bin_by_code( $dst );
				if ( ! $dst_bin || (int) $dst_bin['storage_location_id'] !== (int) $dst_loc['id'] ) {
					throw new DomainError( 'Indiquez un emplacement de la zone de quarantaine 800 (ex. 800-Q-01).' );
				}
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $l, $m, $rows, $result, $result_text, $decision, $text, $bbd, $dst_bin, $dst_loc ) {
				$lines = array();
				$docs  = array();
				$new   = $l['stock_status'];
				foreach ( $rows as $r ) {
					$q    = (float) $r['qty'];
					$base = array( 'material_id' => (int) $r['material_id'], 'batch' => $r['batch'] );
					$src  = $base + array( 'location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'bbd' => $r['bbd'], 'status' => $r['status'], 'qty' => -$q, 'reason' => mb_substr( $text, 0, 120 ) );
					switch ( $decision ) {
						case 'LIBERER':
							$mvt     = 'CONTROLE_QUALITE' === $r['status'] ? '321' : '343';
							$lines[] = array( 'movement_type' => $mvt ) + $src;
							$lines[] = array( 'movement_type' => $mvt, 'location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'bbd' => $bbd, 'status' => 'LIBRE', 'qty' => $q, 'gr_date' => $r['gr_date'], 'reason' => 'Décision d\'emploi ' . $l['lot_no'] ) + $base;
							$new     = 'LIBRE';
							break;
						case 'BLOQUER':
						case 'QUARANTAINE':
							$to_loc = $dst_loc ? (int) $dst_loc['id'] : (int) $r['storage_location_id'];
							$to_bin = $dst_loc ? ( $dst_bin ? (int) $dst_bin['id'] : 0 ) : (int) $r['bin_id'];
							if ( 'BLOQUE' === $r['status'] && $to_loc === (int) $r['storage_location_id'] && $to_bin === (int) $r['bin_id'] ) {
								break;
							}
							$mvt     = 'QUARANTAINE' === $decision ? ( $to_loc === (int) $r['storage_location_id'] ? '999' : '311' ) : '350';
							$lines[] = array( 'movement_type' => $mvt ) + $src;
							$lines[] = array( 'movement_type' => $mvt, 'location_id' => $to_loc, 'bin_id' => $to_bin, 'bbd' => $r['bbd'], 'status' => 'BLOQUE', 'qty' => $q, 'gr_date' => $r['gr_date'], 'reason' => mb_substr( $text, 0, 120 ) ) + $base;
							$new     = 'BLOQUE';
							break;
						case 'DETRUIRE':
							$lines[] = array( 'movement_type' => '551' ) + $src;
							break;
						case 'RETOUR':
							$po      = Purchasing::by_no( $ctx, (string) $l['ref_no'] );
							$docs[]  = Receiving::return_to_supplier( $ctx, (string) ( $po['po_no'] ?? '' ), (int) $l['ref_item_id'], (int) $r['id'], $q, 'Lot de contrôle ' . $l['lot_no'] . ' : ' . $text );
							break;
					}
				}
				if ( $lines ) {
					$docs[] = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'QL', 'ref_id' => (int) $l['id'], 'ref_no' => $l['lot_no'], 'text' => 'Décision d\'emploi ' . $l['lot_no'] ), $lines );
				}
				Db::update(
					'inspection_lot',
					array(
						'status'        => 'DECIDE',
						'result'        => $result,
						'result_text'   => mb_substr( sanitize_text_field( $result_text ), 0, 255 ),
						'decision'      => $decision,
						'decision_text' => $text,
						'stock_status'  => $new,
						'bbd'           => $bbd ?: null,
						'new_bbd'       => $bbd !== $l['bbd'] ? $bbd : null,
						'decided_at'    => Db::now(),
					),
					array( 'id' => (int) $l['id'] )
				);
				return array( 'doc_no' => implode( ', ', array_filter( $docs ) ), 'message' => sprintf( 'Décision d\'emploi enregistrée pour le lot %s : %s.', $l['lot_no'], mb_strtolower( self::DECISIONS[ $decision ] ) ) );
			}
		);
	}

	/** Mise en quarantaine d'une ligne de stock (terminal mobile ou LT10) : transfert en zone 800, statut bloqué, lot de contrôle. */
	public static function quarantine( Ctx $ctx, int $stock_id, float $qty, string $dst_bin_code, string $reason ): array {
		$row = StockService::row( $ctx->id, $stock_id );
		if ( ! $row ) {
			throw new DomainError( 'Ligne de stock introuvable.' );
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif de la mise en quarantaine.' );
		}
		$qty = round( $qty, 3 );
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité invalide : %s disponible sur cette ligne.', Catalog::format_qty( (float) $row['qty'] ) ) );
		}
		$ctx->require_key_for( Catalog::location( (int) $row['storage_location_id'] ) );
		$loc = Catalog::location_by_code( '800' );
		$bin = null;
		if ( ! $ctx->simple() ) {
			$bin = Catalog::bin_by_code( $dst_bin_code );
			if ( ! $bin || (int) $bin['storage_location_id'] !== (int) $loc['id'] ) {
				throw new DomainError( 'Scannez un emplacement de la zone de quarantaine 800.' );
			}
		}
		$m = Catalog::material( (int) $row['material_id'] );
		return Db::transaction(
			static function () use ( $ctx, $row, $qty, $loc, $bin, $reason, $m ) {
				$doc = StockService::post(
					$ctx->id,
					array( 'posting_date' => $ctx->date(), 'ref_type' => 'QM', 'text' => 'Mise en quarantaine' ),
					array(
						array( 'movement_type' => '311', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty, 'reason' => mb_substr( $reason, 0, 120 ) ),
						array( 'movement_type' => '311', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $loc['id'], 'bin_id' => $bin ? (int) $bin['id'] : 0, 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'BLOQUE', 'qty' => $qty, 'gr_date' => $row['gr_date'], 'reason' => mb_substr( $reason, 0, 120 ) ),
					)
				);
				$lot = self::create_lot( $ctx, 'MAN', $m, (string) $row['batch'], $row['bbd'], 'BLOQUE', $qty, '', 0, 'KO:' . 'Contrôle visuel : ' . mb_substr( $reason, 0, 200 ) );
				return array( 'doc_no' => $doc, 'lot_no' => $lot, 'message' => sprintf( '%s %s mis en quarantaine (stock bloqué) ; lot de contrôle %s créé pour la décision (QA11).', Catalog::format_qty( $qty ), $m['base_uom'], $lot ) );
			}
		);
	}

	/** Liste des durées de conservation (MB5M) : lots périmés ou qui périment dans N jours. */
	public static function shelf_life( Ctx $ctx, int $days ): array {
		global $wpdb;
		$st    = Db::t( 'stock' );
		$limit = gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 0, min( 365, $days ) ) . ' days' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$st} WHERE session_id = %d AND bbd IS NOT NULL AND bbd <= %s AND qty > 0 ORDER BY bbd, material_id LIMIT 300", $ctx->id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $r ) {
			$m     = Catalog::material( (int) $r['material_id'] );
			$b     = (int) $r['bin_id'] ? Catalog::bin( (int) $r['bin_id'] ) : null;
			$c     = Catalog::location( (int) $r['storage_location_id'] );
			$left  = (int) floor( ( strtotime( $r['bbd'] ) - strtotime( $ctx->date() ) ) / 86400 );
			$out[] = array( 'id' => (int) $r['id'], 'material' => Api::material_summary( $m ), 'location' => $c ? $c['code'] : '', 'bin' => $b ? $b['code'] : '', 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'days_left' => $left, 'status' => $r['status'], 'qty' => (float) $r['qty'] );
		}
		return $out;
	}
}
