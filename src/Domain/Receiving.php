<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Réception des marchandises (MIGO, mouvement 101) et retour fournisseur (122).
 */
final class Receiving {

	public const STATUSES = array( 'LIBRE', 'CONTROLE_QUALITE', 'BLOQUE' );

	public static function tolerance(): float {
		return max( 0.0, min( 50.0, (float) get_option( 'logierp_gr_tolerance', 5 ) ) ) / 100;
	}

	/** Emplacement de réception le moins chargé (magasin 100). */
	public static function reception_bin( Ctx $ctx, string $location_code = '100' ): array {
		global $wpdb;
		$loc = Catalog::location_by_code( $location_code );
		$bt  = Db::t( 'bin' );
		$st  = Db::t( 'stock' );
		$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT b.id FROM {$bt} b LEFT JOIN {$st} s ON s.bin_id = b.id AND s.session_id = %d WHERE b.storage_location_id = %d GROUP BY b.id ORDER BY COUNT(s.id), b.pick_sequence LIMIT 1", $ctx->id, (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( Catalog::bin( $id ), $loc );
	}

	/**
	 * @param array $lines [{item_id, qty, batch, bbd, status, reason, location_id}]
	 * @return array{doc_no:string, to_no:string, messages:string[]}
	 */
	public static function post( Ctx $ctx, string $po_no, array $lines, string $text = '', bool $accept_over = false, string $transport = '' ): array {
		$po = Purchasing::by_no( $ctx, $po_no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $po_no ) );
		}
		if ( in_array( $po['status'], array( 'RECUE', 'SOLDEE', 'ANNULEE' ), true ) ) {
			throw new DomainError( sprintf( 'La commande %s est déjà entièrement reçue ou soldée.', $po_no ) );
		}
		$detail = Purchasing::detail( $ctx, $po_no );
		$items  = array();
		foreach ( $detail['items'] as $i ) {
			$items[ $i['id'] ] = $i;
		}
		$errors = array();
		$post   = array();
		$tol    = self::tolerance();
		foreach ( $lines as $l ) {
			$id  = (int) ( $l['item_id'] ?? 0 );
			$qty = round( (float) ( $l['qty'] ?? 0 ), 3 );
			if ( ! isset( $items[ $id ] ) ) {
				$errors[] = 'Ligne de commande inconnue.';
				continue;
			}
			if ( $qty <= 0 ) {
				continue;
			}
			$item = $items[ $id ];
			$m    = Catalog::material( (int) $item['material']['id'] );
			$ln   = $item['line_no'];
			if ( $qty > Purchasing::MAX_QTY ) {
				$errors[] = sprintf( 'Ligne %d : quantité invalide.', $ln );
				continue;
			}
			$max = $item['qty'] * ( 1 + $tol ) - $item['qty_received'];
			$reason = mb_substr( sanitize_text_field( (string) ( $l['reason'] ?? '' ) ), 0, 120 );
			if ( $qty > $max + 0.0005 ) {
				if ( ! $accept_over ) {
					$errors[] = sprintf( 'Ligne %d : surlivraison de %s %s au-delà de la tolérance de %s %%. Refusez l\'excédent ou acceptez-le avec un motif.', $ln, Catalog::format_qty( $qty - max( 0, $item['open'] ) ), $m['base_uom'], Catalog::format_qty( $tol * 100 ) );
					continue;
				}
				if ( '' === $reason ) {
					$errors[] = sprintf( 'Ligne %d : un motif est obligatoire pour accepter une surlivraison.', $ln );
					continue;
				}
			}
			$status = strtoupper( (string) ( $l['status'] ?? 'LIBRE' ) );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$errors[] = sprintf( 'Ligne %d : statut de stock invalide.', $ln );
				continue;
			}
			$batch = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $l['batch'] ?? '' ) ) );
			$batch = mb_substr( $batch, 0, 20 );
			$bbd   = (string) ( $l['bbd'] ?? '' );
			if ( empty( $m['batch_managed'] ) ) {
				$batch = '';
			}
			// Onglets MIGO : certificat, date de fabrication, lot fournisseur, quantité par emballage, TUS.
			$cert   = strtoupper( substr( (string) ( $l['cert'] ?? '' ), 0, 1 ) );
			$mfg    = (string) ( $l['mfg_date'] ?? '' );
			$vbatch = mb_substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $l['vendor_batch'] ?? '' ) ) ), 0, 20 );
			$pcount = max( 0, (int) ( $l['pack_count'] ?? 0 ) );
			$pqty   = max( 0.0, round( (float) ( $l['pack_qty'] ?? 0 ), 3 ) );
			$tus    = (string) preg_replace( '/\D/', '', (string) ( $l['tus'] ?? '' ) );
			if ( ! empty( $m['cert_required'] ) && ! in_array( $cert, array( 'O', 'N' ), true ) ) {
				$errors[] = sprintf( 'Ligne %d : onglet Qualité, indiquez s\'il y a un certificat d\'analyse avec les papiers d\'arrivage (oui ou non).', $ln );
				continue;
			}
			if ( '' !== $mfg && ( ! Api::valid_date( $mfg ) || $mfg > $ctx->date() ) ) {
				$errors[] = sprintf( 'Ligne %d : date de fabrication invalide (au plus tard aujourd\'hui).', $ln );
				continue;
			}
			if ( ! empty( $m['cert_required'] ) && '' === $mfg ) {
				$errors[] = sprintf( 'Ligne %d : onglet Lot, saisissez la date de fabrication (à défaut, la date du jour).', $ln );
				continue;
			}
			if ( $pcount > 0 && $pqty > 0 && abs( $pcount * $pqty - $qty ) > 0.0015 ) {
				$errors[] = sprintf( 'Ligne %d : onglet Emballage, %d × %s = %s, différent de la quantité reçue %s.', $ln, $pcount, Catalog::format_qty( $pqty ), Catalog::format_qty( $pcount * $pqty ), Catalog::format_qty( $qty ) );
				continue;
			}
			if ( '' !== $tus && ! in_array( $tus, array( '1', '11', '12' ), true ) ) {
				$errors[] = sprintf( 'Ligne %d : TUS %s inconnu (1 = standard, 11 ou 12 = magasin grande hauteur).', $ln, $tus );
				continue;
			}
			if ( (int) $m['shelf_life_days'] > 0 ) {
				if ( ! Api::valid_date( $bbd ) ) {
					$errors[] = sprintf( 'Ligne %d : saisissez la date limite (DLC) de l\'article %s.', $ln, $m['material_no'] );
					continue;
				}
				if ( $bbd < $ctx->date() && 'LIBRE' === $status ) {
					$errors[] = sprintf( 'Ligne %d : lot périmé (DLC %s). Il ne peut pas entrer en stock libre : refusez-le ou réceptionnez-le en stock bloqué.', $ln, Api::fr_date( $bbd ) );
					continue;
				}
			} else {
				$bbd = '';
			}
			if ( $ctx->simple() ) {
				$loc = Catalog::location( (int) ( $l['location_id'] ?? 0 ) );
				if ( ! $loc ) {
					$errors[] = sprintf( 'Ligne %d : choisissez le magasin de destination.', $ln );
					continue;
				}
				$le = Rules::check_location( $m, $loc );
				if ( $le ) {
					$errors[] = sprintf( 'Ligne %d : %s', $ln, implode( ' ', $le ) );
					continue;
				}
				if ( ! empty( $loc['secured'] ) && ! $ctx->hv_key() ) {
					$errors[] = sprintf( 'Ligne %d : le magasin %s est sous clé et vous n\'avez pas l\'habilitation.', $ln, $loc['code'] );
					continue;
				}
				$bin_id = 0;
			} else {
				[ $bin, $loc ] = self::reception_bin( $ctx );
				$bin_id        = (int) $bin['id'];
			}
			$post[] = array( 'item' => $item, 'm' => $m, 'qty' => $qty, 'batch' => $batch, 'bbd' => $bbd ?: null, 'status' => $status, 'reason' => $reason, 'loc' => $loc, 'bin_id' => $bin_id, 'cert' => $cert, 'mfg_date' => $mfg, 'vendor_batch' => $vbatch, 'pack_count' => $pcount, 'pack_qty' => $pqty, 'tus' => '' !== $tus ? $tus : '1', 'final' => ! empty( $l['final'] ) );
		}
		if ( $errors ) {
			throw new DomainError( 'La réception contient des erreurs.', $errors );
		}
		if ( ! $post ) {
			throw new DomainError( 'Saisissez au moins une quantité reçue.' );
		}

		$transport = strtoupper( $transport );
		if ( '' !== $transport && ! in_array( $transport, array( 'CAMION', 'WAGON' ), true ) ) {
			throw new DomainError( 'Moyen de transport du vrac : camion-citerne ou wagon-citerne.' );
		}
		foreach ( $post as $p ) {
			if ( ! empty( $p['m']['bulk'] ) && '' === $transport && '' === (string) $po['transport'] ) {
				throw new DomainError( sprintf( 'Vrac (%s) : indiquez si la marchandise arrive par camion-citerne ou par wagon-citerne (perte de citerne différente).', $p['m']['material_no'] ) );
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $po, $post, $text, $transport ) {
				global $wpdb;
				if ( '' !== $transport && $transport !== (string) $po['transport'] ) {
					Db::update( 'purchase_order', array( 'transport' => $transport ), array( 'id' => (int) $po['id'] ) );
				}
				// Lot interne attribué par le système quand le champ « Lot » est laissé vide.
				foreach ( $post as $k => $p ) {
					if ( ! empty( $p['m']['batch_managed'] ) && '' === $p['batch'] ) {
						$post[ $k ]['batch'] = StockTx::new_batch( $ctx );
					}
				}
				$lines = array();
				foreach ( $post as $p ) {
					$lines[] = array(
						'movement_type' => '101',
						'material_id'   => (int) $p['m']['id'],
						'location_id'   => (int) $p['loc']['id'],
						'bin_id'        => $p['bin_id'],
						'batch'         => $p['batch'],
						'bbd'           => $p['bbd'],
						'status'        => $p['status'],
						'qty'           => $p['qty'],
						'reason'        => $p['reason'],
						'cert'          => $p['cert'],
						'mfg_date'      => $p['mfg_date'],
						'vendor_batch'  => $p['vendor_batch'],
						'pack_count'    => $p['pack_count'],
						'pack_qty'      => $p['pack_qty'],
						'tus'           => $p['tus'],
					);
				}
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PO', 'ref_id' => (int) $po['id'], 'ref_no' => $po['po_no'], 'text' => $text, 'tcode' => 'UB' === (string) $po['po_type'] ? 'MIGO_GR' : 'MIGO' ), $lines );
				$it = Db::t( 'purchase_order_item' );
				foreach ( $post as $p ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$it} SET qty_received = qty_received + %f" . ( $p['final'] ? ', final_delivery = 1' : '' ) . ' WHERE id = %d', $p['qty'], (int) $p['item']['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				}
				Purchasing::refresh_status( (int) $po['id'] );
				$to_no    = '';
				$messages = array();
				foreach ( $post as $p ) {
					if ( '1' !== $p['tus'] ) {
						$messages[] = sprintf( 'Article %s : TUS %s, la marchandise est prévue pour le magasin grande hauteur. Pour des sacs qui repartent dans les 24 heures, le TUS doit être 1.', $p['m']['material_no'], $p['tus'] );
					}
					if ( '' !== $p['vendor_batch'] || ( ! empty( $p['m']['batch_managed'] ) ) ) {
						$messages[] = sprintf( 'Article %s : lot %s%s.', $p['m']['material_no'], $p['batch'], '' !== $p['vendor_batch'] ? ' (lot fournisseur ' . $p['vendor_batch'] . ')' : '' );
					}
				}
				foreach ( $post as $p ) {
					if ( 'CONTROLE_QUALITE' === $p['status'] ) {
						$qa  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT qa_result FROM {$it} WHERE id = %d", (int) $p['item']['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$lot = Quality::create_lot( $ctx, 'GR', $p['m'], (string) $p['batch'], $p['bbd'], 'CONTROLE_QUALITE', (float) $p['qty'], $po['po_no'], (int) $p['item']['id'], $qa );
						$messages[] = sprintf( 'Lot de contrôle %s créé pour l\'article %s (QA32) : échantillon et décision d\'emploi avant toute utilisation.', $lot, $p['m']['material_no'] );
					}
				}
				if ( ! $ctx->simple() ) {
					$to_lines = array();
					foreach ( $post as $p ) {
						$to_lines[] = array( 'm' => $p['m'], 'qty' => $p['qty'], 'batch' => $p['batch'], 'bbd' => $p['bbd'], 'status' => $p['status'], 'src_location_id' => (int) $p['loc']['id'], 'src_bin_id' => $p['bin_id'] );
					}
					$res      = Warehouse::create_putaway( $ctx, 'GR', (int) $po['id'], $to_lines );
					$to_no    = $res['to_no'];
					$messages = array_merge( $messages, $res['messages'] );
				}
				return array( 'doc_no' => $doc, 'to_no' => $to_no, 'messages' => $messages );
			}
		);
	}

	/** Retour au fournisseur depuis une ligne de stock (122). */
	public static function return_to_supplier( Ctx $ctx, string $po_no, int $item_id, int $stock_id, float $qty, string $reason ): string {
		global $wpdb;
		$po = Purchasing::by_no( $ctx, $po_no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $po_no ) );
		}
		$it   = Db::t( 'purchase_order_item' );
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$it} WHERE id = %d AND po_id = %d", $item_id, (int) $po['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row  = StockService::row( $ctx->id, $stock_id );
		if ( ! $item || ! $row || (int) $row['material_id'] !== (int) $item['material_id'] ) {
			throw new DomainError( 'Choisissez une ligne de stock de l\'article de cette commande.' );
		}
		if ( $qty <= 0 || $qty > (float) $item['qty_received'] + 0.0005 ) {
			throw new DomainError( 'La quantité retournée dépasse la quantité reçue sur cette ligne.' );
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif du retour.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $po, $item, $row, $qty, $reason, $wpdb, $it ) {
				$doc = StockService::post(
					$ctx->id,
					array( 'posting_date' => $ctx->date(), 'ref_type' => 'PO', 'ref_id' => (int) $po['id'], 'ref_no' => $po['po_no'], 'text' => 'Retour fournisseur' ),
					array( array( 'movement_type' => '122', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty, 'reason' => mb_substr( $reason, 0, 120 ) ) )
				);
				$wpdb->query( $wpdb->prepare( "UPDATE {$it} SET qty_received = GREATEST(0, qty_received - %f) WHERE id = %d", $qty, (int) $item['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Purchasing::refresh_status( (int) $po['id'] );
				Warehouse::cancel_open_putaway_for_row( $ctx, $row, $qty );
				return $doc;
			}
		);
	}
}
