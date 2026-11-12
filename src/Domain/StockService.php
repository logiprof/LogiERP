<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moteur de mouvements : le stock n'est JAMAIS modifié directement.
 * Chaque variation crée un document de mouvement immuable et met à jour lw_stock
 * dans la même transaction, avec verrou de ligne (FOR UPDATE).
 */
final class StockService {

	public const MOVEMENTS = array(
		'101' => 'Entrée de marchandises',
		'102' => 'Annulation entrée de marchandises',
		'122' => 'Retour au fournisseur',
		'161' => 'Retour fournisseur sur commande de retour',
		'201' => 'Sortie pour centre de coûts',
		'202' => 'Annulation sortie pour centre de coûts',
		'261' => 'Sortie pour ordre de fabrication',
		'262' => 'Annulation sortie pour ordre / entrée de procédé',
		'309' => 'Transfert d\'article à article',
		'310' => 'Annulation transfert d\'article à article',
		'311' => 'Transfert de magasin à magasin',
		'312' => 'Annulation transfert de magasin à magasin',
		'321' => 'Contrôle qualité → libre',
		'322' => 'Libre → contrôle qualité',
		'331' => 'Échantillon prélevé (stock en contrôle qualité)',
		'333' => 'Prélèvement d\'échantillon',
		'335' => 'Échantillon prélevé (stock bloqué)',
		'350' => 'Contrôle qualité → bloqué',
		'343' => 'Bloqué → libre',
		'344' => 'Libre → bloqué',
		'411' => 'Stock client (E) → stock libre',
		'412' => 'Stock libre → stock client (E)',
		'453' => 'Retour client remis en stock libre',
		'501' => 'Entrée sans commande (centre de coûts)',
		'502' => 'Annulation entrée sans commande',
		'551' => 'Mise au rebut',
		'552' => 'Annulation mise au rebut',
		'601' => 'Sortie de marchandises pour livraison',
		'602' => 'Annulation sortie de marchandises',
		'621' => 'Emballage consigné remis au client',
		'622' => 'Retour d\'emballages consignés vides',
		'651' => 'Retour client (stock bloqué)',
		'652' => 'Annulation retour client',
		'701' => 'Écart d\'inventaire positif',
		'702' => 'Écart d\'inventaire négatif',
		'711' => 'Écart de gestion des emplacements soldé (LI21)',
		'924' => 'Conteneur consigné repris en stock propre',
		'925' => 'Annulation reprise de conteneur consigné',
		'999' => 'Transfert d\'emplacement à emplacement',
	);

	/** Colonnes facultatives d'une ligne de document (onglets MIGO, imputation, stock spécial). */
	private const ITEM_EXTRAS = array( 'cost_center' => 10, 'gl_account' => 10, 'special_ref' => 20, 'cert' => 1, 'vendor_batch' => 20, 'tus' => 3 );

	/**
	 * Comptabilise un document de mouvement.
	 *
	 * @param array $header posting_date, ref_type, ref_id, ref_no, text
	 * @param array $lines  movement_type, material_id, location_id, bin_id, batch, bbd, status, qty (signée), reason
	 * @return string numéro du document de mouvement
	 */
	public static function post( int $session_id, array $header, array $lines ): string {
		if ( ! $lines ) {
			throw new DomainError( 'Aucune ligne à comptabiliser.' );
		}
		return Db::transaction(
			static function () use ( $session_id, $header, $lines ) {
				$doc_no = NumberRange::next( $session_id, 'MDOC' );
				$doc_id = Db::insert(
					'material_doc',
					array(
						'session_id'   => $session_id,
						'doc_no'       => $doc_no,
						'posting_date' => $header['posting_date'],
						'ref_type'     => mb_substr( (string) ( $header['ref_type'] ?? '' ), 0, 10 ),
						'ref_id'       => (int) ( $header['ref_id'] ?? 0 ),
						'ref_no'       => mb_substr( (string) ( $header['ref_no'] ?? '' ), 0, 20 ),
						'header_text'  => mb_substr( (string) ( $header['text'] ?? '' ), 0, 255 ),
						'tcode'        => mb_substr( (string) ( $header['tcode'] ?? '' ), 0, 10 ),
						'rev_of'       => mb_substr( (string) ( $header['rev_of'] ?? '' ), 0, 20 ),
						'created_by'   => get_current_user_id(),
						'created_at'   => Db::now(),
					)
				);
				foreach ( array_values( $lines ) as $i => $line ) {
					$m = Catalog::material( (int) $line['material_id'] );
					if ( ! $m ) {
						throw new DomainError( 'Article introuvable.' );
					}
					if ( ! isset( self::MOVEMENTS[ (string) $line['movement_type'] ] ) ) {
						throw new DomainError( 'Code mouvement inconnu.' );
					}
					$qty = round( (float) $line['qty'], 3 );
					if ( 0.0 === $qty ) {
						continue;
					}
					if ( 'INV' !== ( $header['ref_type'] ?? '' ) && $session_id > 0 ) {
						$frozen = Counting::frozen( $session_id, (int) $line['location_id'], (int) ( $line['bin_id'] ?? 0 ), (int) $m['id'] );
						if ( '' !== $frozen ) {
							throw new DomainError( sprintf( 'Article %s gelé par l\'inventaire %s en cours : aucun mouvement avant la comptabilisation.', $m['material_no'], $frozen ) );
						}
					}
					self::apply( $session_id, $m, $line, $qty, $header['posting_date'] );
					$extra = array();
					foreach ( self::ITEM_EXTRAS as $col => $len ) {
						if ( isset( $line[ $col ] ) && '' !== (string) $line[ $col ] ) {
							$extra[ $col ] = mb_substr( (string) $line[ $col ], 0, $len );
						}
					}
					foreach ( array( 'pack_count', 'pack_qty' ) as $col ) {
						if ( ! empty( $line[ $col ] ) ) {
							$extra[ $col ] = 'pack_count' === $col ? (int) $line[ $col ] : round( (float) $line[ $col ], 3 );
						}
					}
					if ( ! empty( $line['mfg_date'] ) && Api::valid_date( (string) $line['mfg_date'] ) ) {
						$extra['mfg_date'] = (string) $line['mfg_date'];
					}
					Db::insert(
						'material_doc_item',
						$extra + array(
							'mdoc_id'             => $doc_id,
							'line_no'             => $i + 1,
							'movement_type'       => (string) $line['movement_type'],
							'material_id'         => (int) $m['id'],
							'storage_location_id' => (int) $line['location_id'],
							'bin_id'              => (int) ( $line['bin_id'] ?? 0 ),
							'batch'               => (string) ( $line['batch'] ?? '' ),
							'bbd'                 => $line['bbd'] ?? null,
							'stock_status'        => (string) ( $line['status'] ?? 'LIBRE' ),
							'qty'                 => $qty,
							'value'               => round( $qty * (float) $m['std_price'], 2 ),
							'reason'              => mb_substr( (string) ( $line['reason'] ?? '' ), 0, 120 ),
						)
					);
				}
				return $doc_no;
			}
		);
	}

	/** Met à jour la ligne de stock correspondante (verrouillée). */
	private static function apply( int $session_id, array $m, array $line, float $qty, string $posting_date ): void {
		global $wpdb;
		$t      = Db::t( 'stock' );
		$loc    = (int) $line['location_id'];
		$bin    = (int) ( $line['bin_id'] ?? 0 );
		$batch  = (string) ( $line['batch'] ?? '' );
		$status = (string) ( $line['status'] ?? 'LIBRE' );
		$sref   = (string) ( $line['special_ref'] ?? '' );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, qty, bbd, gr_date FROM {$t} WHERE session_id = %d AND material_id = %d AND storage_location_id = %d AND bin_id = %d AND batch = %s AND status = %s AND special_ref = %s FOR UPDATE", $session_id, (int) $m['id'], $loc, $bin, $batch, $status, $sref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old    = $row ? (float) $row['qty'] : 0.0;
		$new    = round( $old + $qty, 3 );
		if ( $new < -0.0005 ) {
			$l   = Catalog::location( $loc );
			$b   = $bin ? Catalog::bin( $bin ) : null;
			$where = $b ? $b['code'] : ( $l ? $l['code'] : '?' );
			throw new DomainError(
				sprintf(
					'Stock insuffisant : article %s, %s, statut %s%s. Disponible : %s, demandé : %s.',
					$m['material_no'],
					$where,
					$status,
					'' !== $batch ? ', lot ' . $batch : '',
					Catalog::format_qty( $old ),
					Catalog::format_qty( abs( $qty ) )
				)
			);
		}
		if ( $row ) {
			if ( $new <= 0.0005 ) {
				$wpdb->delete( $t, array( 'id' => (int) $row['id'] ) );
			} else {
				$data = array( 'qty' => $new );
				if ( $qty > 0 && empty( $row['bbd'] ) && ! empty( $line['bbd'] ) ) {
					$data['bbd'] = $line['bbd'];
				}
				$wpdb->update( $t, $data, array( 'id' => (int) $row['id'] ) );
			}
			return;
		}
		Db::insert(
			'stock',
			array(
				'session_id'          => $session_id,
				'material_id'         => (int) $m['id'],
				'storage_location_id' => $loc,
				'bin_id'              => $bin,
				'batch'               => $batch,
				'bbd'                 => $line['bbd'] ?? null,
				'status'              => $status,
				'special_ref'         => $sref,
				'qty'                 => $new,
				'gr_date'             => $line['gr_date'] ?? $posting_date,
			)
		);
	}

	/** Lit une ligne de stock d'une session (contrôle de propriété inclus). */
	public static function row( int $session_id, int $stock_id ): ?array {
		global $wpdb;
		$t = Db::t( 'stock' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND session_id = %d", $stock_id, $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/** Quantité libre non réservée d'un article. */
	public static function available( int $session_id, int $material_id ): float {
		global $wpdb;
		$s     = Db::t( 'stock' );
		$l     = Db::t( 'storage_location' );
		$free  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(s.qty),0) FROM {$s} s JOIN {$l} l ON l.id = s.storage_location_id WHERE s.session_id = %d AND s.material_id = %d AND s.status = 'LIBRE' AND l.is_transit = 0", $session_id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$so    = Db::t( 'sales_order' );
		$soi   = Db::t( 'sales_order_item' );
		$res   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.qty_reserved),0) FROM {$soi} i JOIN {$so} o ON o.id = i.so_id WHERE o.session_id = %d AND i.material_id = %d AND o.status IN ('CONFIRMEE','EN_PREPARATION','PARTIELLEMENT_LIVREE')", $session_id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$po    = Db::t( 'production_order' );
		$pc    = Db::t( 'production_component' );
		$res  += (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(c.qty_required - c.qty_issued),0) FROM {$pc} c JOIN {$po} p ON p.id = c.prod_id WHERE p.session_id = %d AND c.material_id = %d AND p.status IN ('LANCE','PARTIELLEMENT_CONFIRME')", $session_id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return max( 0.0, $free - max( 0.0, $res ) );
	}
}
