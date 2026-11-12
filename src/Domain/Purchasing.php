<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Achats : commandes fournisseurs (ME21N / ME23 / ME2M).
 */
final class Purchasing {

	public const MAX_LINES = 50;
	public const MAX_QTY   = 9999999;

	public static function supplier( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'supplier' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function info_record( int $supplier_id, int $material_id ): ?array {
		global $wpdb;
		$t = Db::t( 'supplier_material' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE supplier_id = %d AND material_id = %d", $supplier_id, $material_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/**
	 * @param array $lines [{material_id, qty, price?}]
	 */
	public static function create( Ctx $ctx, int $supplier_id, array $lines, string $note = '' ): string {
		$sup = self::supplier( $supplier_id );
		if ( ! $sup || empty( $sup['active'] ) ) {
			throw new DomainError( 'Fournisseur introuvable ou inactif.' );
		}
		if ( ! $lines ) {
			throw new DomainError( 'Ajoutez au moins une ligne à la commande.' );
		}
		if ( count( $lines ) > self::MAX_LINES ) {
			throw new DomainError( sprintf( 'Une commande est limitée à %d lignes.', self::MAX_LINES ) );
		}
		$errors = array();
		$valid  = array();
		foreach ( array_values( $lines ) as $i => $l ) {
			$n   = ( $i + 1 ) * 10;
			$m   = Catalog::material( (int) $l['material_id'] );
			$qty = (float) $l['qty'];
			if ( ! $m || 'ACTIF' !== $m['status'] ) {
				$errors[] = sprintf( 'Ligne %d : article introuvable ou inactif.', $n );
				continue;
			}
			if ( $qty <= 0 || $qty > self::MAX_QTY ) {
				$errors[] = sprintf( 'Ligne %d : quantité invalide.', $n );
				continue;
			}
			$info = self::info_record( $supplier_id, (int) $m['id'] );
			if ( ! $info ) {
				$errors[] = sprintf( 'Ligne %d : aucune fiche d\'info-achat, le fournisseur %s ne livre pas l\'article %s.', $n, $sup['supplier_no'], $m['material_no'] );
				continue;
			}
			if ( $qty < (float) $info['min_qty'] ) {
				$errors[] = sprintf( 'Ligne %d : quantité minimale de commande %s %s.', $n, Catalog::format_qty( (float) $info['min_qty'] ), $m['base_uom'] );
				continue;
			}
			$valid[] = array( $m, $qty, (float) $info['price'], (int) $info['lead_time_days'] );
		}
		if ( $errors ) {
			throw new DomainError( 'La commande contient des erreurs.', $errors );
		}
		return Db::transaction(
			static function () use ( $ctx, $sup, $valid, $note ) {
				$no    = NumberRange::next( $ctx->id, 'PO' );
				$po_id = Db::insert(
					'purchase_order',
					array(
						'session_id'  => $ctx->id,
						'po_no'       => $no,
						'supplier_id' => (int) $sup['id'],
						'order_date'  => $ctx->date(),
						'status'      => 'ENVOYEE',
						'note'        => mb_substr( $note, 0, 255 ),
						'created_by'  => get_current_user_id(),
						'created_at'  => Db::now(),
					)
				);
				foreach ( $valid as $i => [ $m, $qty, $price, $lead ] ) {
					Db::insert(
						'purchase_order_item',
						array(
							'po_id'         => $po_id,
							'line_no'       => ( $i + 1 ) * 10,
							'material_id'   => (int) $m['id'],
							'qty'           => $qty,
							'price'         => $price,
							'delivery_date' => gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 1, $lead ) . ' days' ) ),
						)
					);
				}
				return $no;
			}
		);
	}

	/** Commande préparée par le scénario (avec la « réalité » de la livraison : écarts, avaries). */
	public static function create_from_scenario( Ctx $ctx, array $doc, array $items, string $date ): string {
		$no    = NumberRange::next( $ctx->id, 'PO' );
		$po_id = Db::insert(
			'purchase_order',
			array(
				'session_id'   => $ctx->id,
				'po_no'        => $no,
				'scenario_ref' => (string) $doc['ref'],
				'supplier_id'  => (int) $doc['partner_id'],
				'order_date'   => gmdate( 'Y-m-d', strtotime( $ctx->date() . ' -7 days' ) ),
				'status'       => 'ENVOYEE',
				'note'         => (string) $doc['note'],
				'unannounced'  => str_contains( strtolower( (string) $doc['note'] ), 'non annonc' ) ? 1 : 0,
				'created_at'   => Db::now(),
			)
		);
		foreach ( array_values( $items ) as $i => $it ) {
			$m = Catalog::material( (int) ( $it['material_id'] ?? 0 ) );
			if ( ! $m ) {
				continue;
			}
			$info = self::info_record( (int) $doc['partner_id'], (int) $m['id'] );
			$row  = array(
				'po_id'         => $po_id,
				'line_no'       => ( $i + 1 ) * 10,
				'material_id'   => (int) $m['id'],
				'qty'           => (float) $it['qty'],
				'price'         => $info ? (float) $info['price'] : (float) $m['std_price'],
				'delivery_date' => $date,
				'damage'        => mb_substr( (string) ( $it['damage'] ?? '' ), 0, 60 ),
				'qa_result'     => mb_substr( (string) ( $it['qa'] ?? '' ), 0, 255 ),
			);
			if ( isset( $it['delivered_qty'] ) && '' !== (string) $it['delivered_qty'] ) {
				$row['qty_delivered_note'] = (float) $it['delivered_qty'];
			}
			if ( ! empty( $it['delivered_material_id'] ) ) {
				$row['delivered_material_id'] = (int) $it['delivered_material_id'];
			}
			if ( isset( $it['bbd_days'] ) && '' !== (string) $it['bbd_days'] ) {
				$row['delivered_bbd'] = gmdate( 'Y-m-d', strtotime( $ctx->date() . ' ' . sprintf( '%+d', (int) $it['bbd_days'] ) . ' days' ) );
			}
			Db::insert( 'purchase_order_item', $row );
		}
		return $no;
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'purchase_order' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND po_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/** Commande complète : en-tête, fournisseur, lignes et bon de livraison du fournisseur. */
	public static function detail( Ctx $ctx, string $no, bool $include_deleted = false ): array {
		global $wpdb;
		$po = self::by_no( $ctx, $no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $no ) );
		}
		$it    = Db::t( 'purchase_order_item' );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it} WHERE po_id = %d ORDER BY line_no", (int) $po['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array_values( array_filter( (array) $items, static fn( $i ) => ! (int) $i['deleted'] || $include_deleted ) );
		$out   = array();
		$sup   = self::supplier( (int) $po['supplier_id'] );
		$ctry  = $sup ? (string) $sup['country'] : 'CH';
		foreach ( (array) $items as $i ) {
			$m        = Catalog::material( (int) $i['material_id'] );
			$dm       = (int) $i['delivered_material_id'] ? Catalog::material( (int) $i['delivered_material_id'] ) : $m;
			$open     = (int) $i['final_delivery'] ? 0.0 : max( 0.0, (float) $i['qty'] - (float) $i['qty_received'] );
			$note_qty = null !== $i['qty_delivered_note'] ? (float) $i['qty_delivered_note'] : $open;
			$bbd      = $i['delivered_bbd'] ?: ( $m && (int) $m['shelf_life_days'] > 0 ? gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . (int) $m['shelf_life_days'] . ' days' ) ) : null );
			$out[]    = array(
				'id'            => (int) $i['id'],
				'line_no'       => (int) $i['line_no'],
				'material'      => Api::material_summary( $m ),
				'qty'           => (float) $i['qty'],
				'qty_received'  => (float) $i['qty_received'],
				'open'          => $open,
				'price'         => (float) $i['price'],
				'delivery_date' => $i['delivery_date'],
				'final_delivery' => (int) $i['final_delivery'],
				'deleted'       => (int) $i['deleted'],
				'delivery_note' => array(
					'material' => Api::material_summary( $dm ),
					'qty'      => $note_qty,
					'bbd'      => $bbd,
					'batch'    => $m && $m['batch_managed'] ? 'L' . gmdate( 'ymd', strtotime( $ctx->date() ) ) . str_pad( (string) ( (int) $i['line_no'] / 10 ), 2, '0', STR_PAD_LEFT ) : '',
					'damage'   => (string) $i['damage'],
					'units'    => $dm ? Gs1::units( $dm, $open > 0 ? $note_qty : 0.0, $ctry, (int) $po['supplier_id'], Gs1::serial_base( (int) ( $ctx->scenario['id'] ?? 0 ), (string) $po['po_no'], (int) $i['line_no'] ) ) : array(),
				),
			);
		}
		return array(
			'po_no'       => $po['po_no'],
			'status'      => $po['status'],
			'order_date'  => $po['order_date'],
			'note'        => $po['note'],
			'unannounced' => (int) $po['unannounced'],
			'po_type'     => (string) $po['po_type'],
			'supply_site' => (string) $po['supply_site'],
			'ib_dlv_no'   => (string) $po['ib_dlv_no'],
			'transport'   => (string) $po['transport'],
			'supplier'    => $sup ? array( 'id' => (int) $sup['id'], 'no' => $sup['supplier_no'], 'name' => $sup['name'], 'city' => $sup['city'], 'country' => $sup['country'], 'incoterm' => $sup['incoterm'] ) : null,
			'items'       => $out,
		);
	}

	public static function refresh_status( int $po_id ): void {
		global $wpdb;
		$it  = Db::t( 'purchase_order_item' );
		// Postes supprimés exclus ; un poste « livraison finale » est soldé même s'il manque une quantité.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, SUM(qty) q, SUM(LEAST(qty_received, qty)) r, SUM(IF(final_delivery = 1 OR qty_received + 0.0005 >= qty, 0, 1)) open_items, SUM(final_delivery) fin FROM {$it} WHERE po_id = %d AND deleted = 0", $po_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$q   = (float) $row['q'];
		$r   = (float) $row['r'];
		if ( ! (int) $row['n'] ) {
			$st = 'ANNULEE';
		} elseif ( ! (int) $row['open_items'] && ( $r + 0.0005 < $q || (int) $row['fin'] ) ) {
			$st = $r <= 0.0005 && ! (int) $row['fin'] ? 'ENVOYEE' : 'SOLDEE';
		} else {
			$st = $r <= 0.0005 ? 'ENVOYEE' : ( $r + 0.0005 >= $q ? 'RECUE' : 'PARTIELLEMENT_RECUE' );
		}
		Db::update( 'purchase_order', array( 'status' => $st ), array( 'id' => $po_id ) );
	}
}
