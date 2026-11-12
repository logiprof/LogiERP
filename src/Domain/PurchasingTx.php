<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\ProcessSeeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Achats et réception « comme dans SAP » : ME2L (commandes par fournisseur), Y_P01 (arrivages planifiés),
 * ME22N (modification : quantité, livraison finale, suppression de poste, impression du document EM ZWEZ),
 * MIGO_GR (réception d'un transfert entre sites par n° de bulletin) et commandes de transfert (UB).
 */
final class PurchasingTx {

	public const TYPES = array( 'NB' => 'Commande standard', 'UB' => 'Commande de transfert (UMLAGERUNG)' );

	/** ME2L : postes de commandes d'achat par fournisseur (ou article). */
	public static function me2l( Ctx $ctx, string $supplier, string $material, bool $open_only ): array {
		global $wpdb;
		$po    = Db::t( 'purchase_order' );
		$pi    = Db::t( 'purchase_order_item' );
		$s     = Db::t( 'supplier' );
		$m     = Db::t( 'material' );
		$where = array( 'o.session_id = %d', 'i.deleted = 0' );
		$args  = array( $ctx->id );
		$supplier = trim( $supplier );
		if ( '' !== $supplier ) {
			$like    = '%' . $wpdb->esc_like( rtrim( $supplier, '*' ) ) . '%';
			$where[] = '(s.supplier_no = %s OR s.name LIKE %s)';
			$args[]  = strtoupper( $supplier );
			$args[]  = $like;
		}
		$material = trim( $material );
		if ( '' !== $material ) {
			$where[] = '(m.material_no = %s OR m.designation LIKE %s)';
			$args[]  = strtoupper( $material );
			$args[]  = '%' . $wpdb->esc_like( $material ) . '%';
		}
		if ( $open_only ) {
			$where[] = "o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') AND i.final_delivery = 0 AND i.qty > i.qty_received";
		}
		if ( '' === $supplier && '' === $material ) {
			throw new DomainError( 'Indiquez un fournisseur (numéro ou nom, le * est accepté) ou un article.' );
		}
		$sqlw = implode( ' AND ', $where );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.po_no, o.po_type, o.status, o.order_date, o.ib_dlv_no, o.transport, s.supplier_no, s.name supplier, i.line_no, i.qty, i.qty_received, i.final_delivery, i.delivery_date, m.material_no, m.designation, m.base_uom FROM {$pi} i JOIN {$po} o ON o.id = i.po_id JOIN {$s} s ON s.id = o.supplier_id JOIN {$m} m ON m.id = i.material_id WHERE {$sqlw} ORDER BY s.name, o.po_no, i.line_no LIMIT 300", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['qty']          = (float) $r['qty'];
			$r['qty_received'] = (float) $r['qty_received'];
			$r['open']         = (int) $r['final_delivery'] ? 0.0 : max( 0.0, $r['qty'] - $r['qty_received'] );
		}
		return $rows;
	}

	/** Y_P01 : arrivages fournisseurs planifiés sur une période (commandes ouvertes par date de livraison). */
	public static function arrivals( Ctx $ctx, string $from, string $to ): array {
		global $wpdb;
		$from = Api::valid_date( $from ) ? $from : $ctx->date();
		$to   = Api::valid_date( $to ) ? $to : gmdate( 'Y-m-d', strtotime( $from . ' +14 days' ) );
		if ( $to < $from ) {
			throw new DomainError( 'La date de fin précède la date de début.' );
		}
		$po   = Db::t( 'purchase_order' );
		$pi   = Db::t( 'purchase_order_item' );
		$s    = Db::t( 'supplier' );
		$m    = Db::t( 'material' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.delivery_date, o.po_no, o.po_type, o.transport, o.ib_dlv_no, s.name supplier, s.country, i.line_no, m.material_no, m.designation, m.base_uom, m.bulk, m.adr_class, GREATEST(i.qty - i.qty_received, 0) open, i.qty FROM {$pi} i JOIN {$po} o ON o.id = i.po_id JOIN {$s} s ON s.id = o.supplier_id JOIN {$m} m ON m.id = i.material_id WHERE o.session_id = %d AND o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') AND i.deleted = 0 AND i.final_delivery = 0 AND i.qty > i.qty_received AND COALESCE(i.delivery_date, o.order_date) BETWEEN %s AND %s ORDER BY i.delivery_date, o.po_no, i.line_no LIMIT 300", $ctx->id, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pi} i JOIN {$po} o ON o.id = i.po_id WHERE o.session_id = %d AND o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') AND i.deleted = 0 AND i.final_delivery = 0 AND i.qty > i.qty_received AND i.delivery_date < %s", $ctx->id, $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['open'] = (float) $r['open'];
			$r['qty']  = (float) $r['qty'];
			$r['bulk'] = (int) $r['bulk'];
		}
		return array( 'from' => $from, 'to' => $to, 'rows' => $rows, 'late' => $late );
	}

	/** ME22N / ME23N : commande avec postes supprimés, historique, documents EM et messages imprimés. */
	public static function detail( Ctx $ctx, string $po_no ): array {
		global $wpdb;
		$d    = Purchasing::detail( $ctx, $po_no, true );
		$docs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT doc_no, posting_date, tcode, rev_of FROM ' . Db::t( 'material_doc' ) . " WHERE session_id = %d AND ref_type = 'PO' AND ref_no = %s ORDER BY id", $ctx->id, $po_no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$d['type_label']   = self::TYPES[ $d['po_type'] ] ?? $d['po_type'];
		$d['supply_name']  = 'UB' === $d['po_type'] ? ProcessSeeder::SUPPLY_SITE_NAME : '';
		$d['gr_docs']      = $docs;
		$d['changes']      = StockTx::changes( $ctx, 'PO', $po_no );
		$d['outputs']      = StockTx::outputs_of( $ctx, 'PO', $po_no );
		$d['editable']     = ! in_array( $d['status'], array( 'ANNULEE' ), true );
		return $d;
	}

	/**
	 * ME22N : modifications de postes.
	 *
	 * @param array $changes [{item_id, qty, final (0/1), delete (0/1)}]
	 */
	public static function change( Ctx $ctx, string $po_no, array $changes ): array {
		global $wpdb;
		$po = Purchasing::by_no( $ctx, $po_no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $po_no ) );
		}
		if ( 'ANNULEE' === $po['status'] ) {
			throw new DomainError( 'Cette commande est annulée.' );
		}
		$pi    = Db::t( 'purchase_order_item' );
		$items = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pi} WHERE po_id = %d", (int) $po['id'] ), ARRAY_A ) as $i ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items[ (int) $i['id'] ] = $i;
		}
		$errors = array();
		$plan   = array();
		foreach ( $changes as $c ) {
			$id = (int) ( $c['item_id'] ?? 0 );
			if ( ! isset( $items[ $id ] ) ) {
				$errors[] = 'Poste inconnu.';
				continue;
			}
			$i   = $items[ $id ];
			$ln  = (int) $i['line_no'];
			$m   = Catalog::material( (int) $i['material_id'] );
			if ( (int) $i['deleted'] ) {
				continue;
			}
			$new = array();
			if ( ! empty( $c['delete'] ) ) {
				if ( (float) $i['qty_received'] > 0.0005 ) {
					$errors[] = sprintf( 'Poste %d : déjà partiellement reçu, il ne peut pas être supprimé. Mettez « livraison finale ».', $ln );
					continue;
				}
				$new['deleted'] = 1;
			} else {
				if ( isset( $c['qty'] ) && '' !== $c['qty'] && null !== $c['qty'] ) {
					$q = round( (float) $c['qty'], 3 );
					if ( $q <= 0 || $q > Purchasing::MAX_QTY ) {
						$errors[] = sprintf( 'Poste %d : quantité invalide.', $ln );
						continue;
					}
					if ( $q + 0.0005 < (float) $i['qty_received'] ) {
						$errors[] = sprintf( 'Poste %d : la quantité commandée ne peut pas être inférieure à la quantité déjà reçue (%s %s).', $ln, Catalog::format_qty( (float) $i['qty_received'] ), $m['base_uom'] );
						continue;
					}
					if ( abs( $q - (float) $i['qty'] ) > 0.0005 ) {
						$new['qty'] = $q;
					}
				}
				if ( isset( $c['final'] ) && '' !== $c['final'] && null !== $c['final'] ) {
					$f = ! empty( $c['final'] ) ? 1 : 0;
					if ( $f !== (int) $i['final_delivery'] ) {
						$new['final_delivery'] = $f;
					}
				}
			}
			if ( $new ) {
				$plan[] = array( $i, $new, $m );
			}
		}
		if ( $errors ) {
			throw new DomainError( 'La modification contient des erreurs.', $errors );
		}
		if ( ! $plan ) {
			throw new DomainError( 'Aucune modification à enregistrer.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $po, $plan, $pi, $wpdb ) {
				$lines = array();
				foreach ( $plan as [ $i, $new, $m ] ) {
					$wpdb->update( $pi, $new, array( 'id' => (int) $i['id'] ) );
					$ln = (int) $i['line_no'];
					if ( isset( $new['qty'] ) ) {
						StockTx::log_change( $ctx, 'PO', $po['po_no'], $ln, 'Quantité commandée', Catalog::format_qty( (float) $i['qty'] ) . ' ' . $m['base_uom'], Catalog::format_qty( $new['qty'] ) . ' ' . $m['base_uom'], 'ME22N' );
						$lines[] = sprintf( 'poste %d : quantité %s → %s', $ln, Catalog::format_qty( (float) $i['qty'] ), Catalog::format_qty( $new['qty'] ) );
					}
					if ( isset( $new['final_delivery'] ) ) {
						StockTx::log_change( $ctx, 'PO', $po['po_no'], $ln, 'Livraison finale', (int) $i['final_delivery'] ? 'oui' : 'non', $new['final_delivery'] ? 'oui' : 'non', 'ME22N' );
						$lines[] = sprintf( 'poste %d : livraison finale %s', $ln, $new['final_delivery'] ? 'cochée (le solde n\'est plus attendu)' : 'décochée (le solde reste attendu chez l\'acheteur)' );
					}
					if ( isset( $new['deleted'] ) ) {
						StockTx::log_change( $ctx, 'PO', $po['po_no'], $ln, 'Poste supprimé', 'non', 'oui', 'ME22N' );
						$lines[] = sprintf( 'poste %d supprimé', $ln );
					}
				}
				Purchasing::refresh_status( (int) $po['id'] );
				$st = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::t( 'purchase_order' ) . ' WHERE id = %d', (int) $po['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array( 'no' => $po['po_no'], 'status' => $st, 'message' => sprintf( 'Commande %s modifiée : %s.', $po['po_no'], implode( ' ; ', $lines ) ) );
			}
		);
	}

	/** Document EM (ZWEZ) : ce qui est commandé, reçu et encore attendu, prêt à imprimer. */
	public static function gr_document( Ctx $ctx, string $po_no ): array {
		$d = Purchasing::detail( $ctx, $po_no );
		$out = array();
		foreach ( $d['items'] as $i ) {
			$out[] = array( 'line_no' => $i['line_no'], 'material' => $i['material'], 'qty' => $i['qty'], 'received' => $i['qty_received'], 'open' => $i['open'], 'final' => $i['final_delivery'], 'delivery_date' => $i['delivery_date'] );
		}
		return array( 'po_no' => $d['po_no'], 'po_type' => $d['po_type'], 'status' => $d['status'], 'order_date' => $d['order_date'], 'supplier' => $d['supplier'], 'ib_dlv_no' => $d['ib_dlv_no'], 'transport' => $d['transport'], 'items' => $out, 'company' => Company::name(), 'date' => $ctx->date() );
	}

	/** MIGO_GR : commande de transfert à partir du n° de bulletin du site cédant. */
	public static function by_bulletin( Ctx $ctx, string $dlv_no ): array {
		global $wpdb;
		$no = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT po_no FROM ' . Db::t( 'purchase_order' ) . " WHERE session_id = %d AND ib_dlv_no = %s AND po_type = 'UB'", $ctx->id, $dlv_no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' === $no ) {
			throw new DomainError( sprintf( 'Aucun transfert entre sites (UMLAGERUNG) ne correspond au bulletin %s.', $dlv_no ) );
		}
		return array( 'po_no' => $no );
	}

	/** Bulletins de transfert attendus (MIGO_GR). */
	public static function bulletins( Ctx $ctx ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT o.ib_dlv_no, o.po_no, o.status, o.order_date FROM ' . Db::t( 'purchase_order' ) . " o WHERE o.session_id = %d AND o.po_type = 'UB' AND o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') ORDER BY o.id DESC LIMIT 50", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Commande de transfert (UB) vers notre site depuis le site cédant : pas de fiche d'info-achat, bulletin attribué. */
	public static function create_transfer( Ctx $ctx, array $lines, string $note ): array {
		global $wpdb;
		$sup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'supplier' ) . ' WHERE dataset_id = %d AND supplier_no = %s', Catalog::dataset(), ProcessSeeder::SITE_SUPPLIER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $sup ) {
			throw new DomainError( 'Le site cédant n\'est pas encore créé : mettez à jour les données (Outils et santé).' );
		}
		if ( ! $lines || count( $lines ) > Purchasing::MAX_LINES ) {
			throw new DomainError( 'Ajoutez entre 1 et 50 postes.' );
		}
		$valid = array();
		foreach ( array_values( $lines ) as $i => $l ) {
			$m = Catalog::material( (int) ( $l['material_id'] ?? 0 ) );
			$q = (float) ( $l['qty'] ?? 0 );
			if ( ! $m || 'ACTIF' !== $m['status'] || $q <= 0 || $q > Purchasing::MAX_QTY ) {
				throw new DomainError( sprintf( 'Poste %d : article ou quantité invalide.', ( $i + 1 ) * 10 ) );
			}
			$valid[] = array( $m, $q );
		}
		return Db::transaction(
			static function () use ( $ctx, $sup, $valid, $note ) {
				$no    = NumberRange::next( $ctx->id, 'PO' );
				$ib    = NumberRange::next( $ctx->id, 'IBD' );
				$po_id = Db::insert( 'purchase_order', array( 'session_id' => $ctx->id, 'po_no' => $no, 'supplier_id' => $sup, 'order_date' => $ctx->date(), 'status' => 'ENVOYEE', 'note' => mb_substr( $note, 0, 255 ), 'po_type' => 'UB', 'supply_site' => ProcessSeeder::SUPPLY_SITE, 'ib_dlv_no' => $ib, 'created_by' => get_current_user_id(), 'created_at' => Db::now() ) );
				foreach ( $valid as $k => [ $m, $q ] ) {
					Db::insert( 'purchase_order_item', array( 'po_id' => $po_id, 'line_no' => ( $k + 1 ) * 10, 'material_id' => (int) $m['id'], 'qty' => $q, 'price' => (float) $m['std_price'], 'delivery_date' => gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +1 day' ) ) ) );
				}
				return array( 'no' => $no, 'ib_dlv_no' => $ib, 'message' => sprintf( 'Commande de transfert %s créée : le site cédant expédie avec le bulletin %s (réception par MIGO_GR).', $no, $ib ) );
			}
		);
	}
}
