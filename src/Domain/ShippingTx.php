<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expédition et vente « comme dans SAP » : VL02N (modifier lot et quantité, supprimer, créer l'OT, messages ZPAL et
 * certificat), VL06O (sortie de marchandises collective), VL09 (annulation de la sortie ou d'un retour), VL74
 * (réimpression des étiquettes d'unités d'expédition), VA02 (modification de commande, motif de refus, disponibilité,
 * historique) et VA05 (liste des commandes).
 */
final class ShippingTx {

	public const REJECT_REASONS = array(
		'Z1' => 'Annulé à la demande du client',
		'Z2' => 'Remplacé par un autre article',
		'Z3' => 'Livraison impossible (rupture durable)',
		'Z4' => 'Retour liquidé',
	);

	/** Codes d'emballage de la conception des paquets (C.0.0) et support LogiERP correspondant. */
	public const PACKAGING_CODES = array(
		'30-058' => array( 'Palette EURO (pool d\'échange)', 'EUR' ),
		'30-059' => array( 'Palette en bois chimique / industrielle', 'CP3' ),
		'30-107' => array( 'Palette en matière plastique', 'EUR' ),
		'30-113' => array( 'Marchandise en vrac', 'VRAC' ),
		'30-114' => array( 'Cadre de palette', 'FRAME' ),
		'30-115' => array( 'GRV (conteneur)', 'GRV' ),
		'30-116' => array( 'Palette à sens unique 1000 × 1200 (perdue)', 'PERDUE' ),
		'30-117' => array( 'Palette à sens unique 800 × 1200 (perdue)', 'PERDUE' ),
		'30-118' => array( 'Palette à sens unique 1140 × 1140 (perdue)', 'PERDUE' ),
	);

	private static function delivery( Ctx $ctx, string $no ): array {
		$d = Sales::delivery_by_no( $ctx, $no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $no ) );
		}
		return $d;
	}

	private static function tos( Ctx $ctx, int $delivery_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'transfer_order' ) . " WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d ORDER BY id", $ctx->id, $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function items( int $delivery_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'outbound_delivery_item' ) . ' WHERE delivery_id = %d ORDER BY id', $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Lots réellement prélevés par ligne de livraison (OT confirmés). */
	private static function picked_batches( Ctx $ctx, int $delivery_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ti.ref_item_id, ti.batch, ti.bbd, SUM(ti.qty_confirmed) q FROM ' . Db::t( 'transfer_order_item' ) . ' ti JOIN ' . Db::t( 'transfer_order' ) . " o ON o.id = ti.to_id WHERE o.session_id = %d AND o.ref_type = 'DLV' AND o.ref_id = %d AND o.status = 'CONFIRME' AND ti.qty_confirmed > 0 GROUP BY ti.ref_item_id, ti.batch, ti.bbd", $ctx->id, $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['ref_item_id'] ][] = array( 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'qty' => (float) $r['q'] );
		}
		return $out;
	}

	/** VL02N : livraison complète pour l'écran de modification. */
	public static function detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$d       = self::delivery( $ctx, $no );
		$base    = 'SUPPRIMEE' === $d['status'] ? array( 'dlv_no' => $d['dlv_no'], 'status' => $d['status'] ) : Sales::delivery_detail( $ctx, $no );
		$tos     = self::tos( $ctx, (int) $d['id'] );
		$active  = array_filter( $tos, static fn( $t ) => in_array( $t['status'], array( 'OUVERT', 'CONFIRME' ), true ) );
		$picked  = self::picked_batches( $ctx, (int) $d['id'] );
		$si      = Db::t( 'sales_order_item' );
		$items   = array();
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			$m    = Catalog::material( (int) $i['material_id'] );
			$soi  = $wpdb->get_row( $wpdb->prepare( "SELECT line_no, qty, qty_delivered, is_delayed, confirmed_date FROM {$si} WHERE id = %d", (int) $i['so_item_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items[] = array(
				'id'        => (int) $i['id'],
				'so_line'   => $soi ? (int) $soi['line_no'] : 0,
				'material'  => Api::material_summary( $m ) + array( 'bulk' => (int) $m['bulk'], 'cert' => (int) $m['cert_required'] ),
				'qty'       => (float) $i['qty'],
				'qty_picked' => (float) $i['qty_picked'],
				'batch'     => (string) $i['batch'],
				'picked'    => $picked[ (int) $i['id'] ] ?? array(),
				'delayed'   => $soi ? (int) $soi['is_delayed'] : 0,
				'confirmed_date' => $soi ? $soi['confirmed_date'] : null,
				'so_open'   => $soi ? max( 0.0, (float) $soi['qty'] - (float) $soi['qty_delivered'] ) : 0.0,
				'batches'   => ! empty( $m['batch_managed'] ) ? self::free_batches( $ctx, $m ) : array(),
			);
		}
		$base['items_edit']  = $items;
		$base['tos']         = array_map( static fn( $t ) => array( 'to_no' => $t['to_no'], 'status' => $t['status'] ), $tos );
		$base['changeable']  = ! in_array( $d['status'], array( 'EXPEDIEE', 'SUPPRIMEE' ), true ) && ! $active && ! (int) $d['shipment_id'] ? 1 : 0;
		$base['can_create_to'] = ! $ctx->simple() && 'A_PREPARER' === $d['status'] && ! $active && $items ? 1 : 0;
		$base['deletable']   = ! in_array( $d['status'], array( 'EXPEDIEE', 'SUPPRIMEE' ), true ) && ! (int) $d['shipment_id'] ? 1 : 0;
		$base['outputs']     = StockTx::outputs_of( $ctx, 'DLV', $no );
		$base['changes']     = StockTx::changes( $ctx, 'DLV', $no );
		$base['delayed']     = array_sum( array_column( $items, 'delayed' ) ) > 0 ? 1 : 0;
		$base['issue_doc']   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT doc_no FROM ' . Db::t( 'material_doc' ) . " WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d AND tcode <> 'VL09' AND rev_of = '' ORDER BY id DESC LIMIT 1", $ctx->id, (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$base['packaging_codes'] = self::PACKAGING_CODES;
		return $base;
	}

	/** Lots libres d'un article (pour le changement de lot). */
	private static function free_batches( Ctx $ctx, array $m ): array {
		$out = array();
		foreach ( Strategies::removal_candidates( $ctx->id, $m, $ctx->date() ) as $r ) {
			if ( $r['expired'] || '' === (string) $r['batch'] ) {
				continue;
			}
			$out[ $r['batch'] ] = array( 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'qty' => ( $out[ $r['batch'] ]['qty'] ?? 0 ) + (float) $r['qty'] );
		}
		return array_values( $out );
	}

	/**
	 * VL02N : modification des postes (quantité, lot imposé, suppression d'un poste).
	 *
	 * @param array $changes [{item_id, qty, batch, delete}]
	 */
	public static function change( Ctx $ctx, string $no, array $changes ): array {
		global $wpdb;
		$d    = self::delivery( $ctx, $no );
		$info = self::detail( $ctx, $no );
		if ( ! $info['changeable'] ) {
			throw new DomainError( 'Livraison non modifiable : elle est expédiée, dans une tournée, ou un ordre de prélèvement est en cours. Annulez d\'abord l\'OT ouvert (LT15) ; si la marchandise est déjà prélevée, supprimez la livraison et remettez la marchandise en stock (LT10).' );
		}
		$by   = array();
		foreach ( $info['items_edit'] as $i ) {
			$by[ $i['id'] ] = $i;
		}
		$di     = Db::t( 'outbound_delivery_item' );
		$done   = array();
		$errors = array();
		$plan   = array();
		foreach ( $changes as $c ) {
			$id = (int) ( $c['item_id'] ?? 0 );
			if ( ! isset( $by[ $id ] ) ) {
				continue;
			}
			$i = $by[ $id ];
			$m = Catalog::material( (int) $i['material']['id'] );
			if ( ! empty( $c['delete'] ) ) {
				$plan[] = array( 'delete', $i, $m );
				continue;
			}
			$q = isset( $c['qty'] ) && '' !== $c['qty'] && null !== $c['qty'] ? round( (float) $c['qty'], 3 ) : $i['qty'];
			if ( $q <= 0 ) {
				$errors[] = sprintf( 'Poste %d : quantité invalide (supprimez le poste pour ne pas le livrer).', $i['so_line'] );
				continue;
			}
			if ( $q > $i['so_open'] + 0.0005 ) {
				$errors[] = sprintf( 'Poste %d : la commande n\'a que %s %s ouverts.', $i['so_line'], Catalog::format_qty( $i['so_open'] ), $m['base_uom'] );
				continue;
			}
			$b = strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $c['batch'] ?? $i['batch'] ) ) );
			if ( '' !== $b ) {
				if ( empty( $m['batch_managed'] ) ) {
					$errors[] = sprintf( 'Poste %d : l\'article %s n\'est pas géré par lot.', $i['so_line'], $m['material_no'] );
					continue;
				}
				$have = Strategies::allocate( $ctx->id, $m, $q, $ctx->date(), array( 'batch' => $b ) );
				if ( $have['missing'] > 0.0005 ) {
					$errors[] = sprintf( 'Poste %d : le lot %s n\'a que %s %s libres et non périmés (il en faut %s).', $i['so_line'], $b, Catalog::format_qty( $q - $have['missing'] ), $m['base_uom'], Catalog::format_qty( $q ) );
					continue;
				}
			}
			if ( abs( $q - $i['qty'] ) > 0.0005 || $b !== $i['batch'] ) {
				$plan[] = array( 'update', $i, $m, $q, $b );
			}
		}
		if ( $errors ) {
			throw new DomainError( 'Modification refusée.', $errors );
		}
		if ( ! $plan ) {
			throw new DomainError( 'Aucune modification à enregistrer.' );
		}
		if ( count( array_filter( $plan, static fn( $p ) => 'delete' === $p[0] ) ) >= count( $by ) ) {
			throw new DomainError( 'Tous les postes seraient supprimés : supprimez plutôt la livraison.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $plan, $di, $wpdb ) {
				$msg = array();
				foreach ( $plan as $p ) {
					$i = $p[1];
					if ( 'delete' === $p[0] ) {
						$wpdb->delete( $di, array( 'id' => (int) $i['id'] ) );
						StockTx::log_change( $ctx, 'DLV', $d['dlv_no'], (int) $i['so_line'], 'Poste supprimé', $i['material']['no'], '—', 'VL02N' );
						$msg[] = sprintf( 'poste %d supprimé', $i['so_line'] );
						continue;
					}
					[ , , $m, $q, $b ] = $p;
					$wpdb->update( $di, array( 'qty' => $q, 'batch' => $b ), array( 'id' => (int) $i['id'] ) );
					if ( abs( $q - $i['qty'] ) > 0.0005 ) {
						StockTx::log_change( $ctx, 'DLV', $d['dlv_no'], (int) $i['so_line'], 'Quantité de livraison', Catalog::format_qty( $i['qty'] ), Catalog::format_qty( $q ), 'VL02N' );
						$msg[] = sprintf( 'poste %d : %s %s', $i['so_line'], Catalog::format_qty( $q ), $m['base_uom'] );
					}
					if ( $b !== $i['batch'] ) {
						StockTx::log_change( $ctx, 'DLV', $d['dlv_no'], (int) $i['so_line'], 'Lot imposé', $i['batch'] ?: '(stratégie)', $b ?: '(stratégie)', 'VL02N' );
						$msg[] = sprintf( 'poste %d : lot %s', $i['so_line'], '' !== $b ? $b : 'selon la stratégie' );
					}
				}
				return array( 'no' => $d['dlv_no'], 'message' => sprintf( 'Livraison %s modifiée : %s.', $d['dlv_no'], implode( ' ; ', $msg ) ) . ( ! $ctx->simple() && 'A_PREPARER' === $d['status'] ? ' Créez maintenant l\'ordre de prélèvement.' : '' ) );
			}
		);
	}

	/** VL02N « Créer OT » (LT03) : nouvel ordre de prélèvement pour une livraison sans OT en cours. */
	public static function create_to( Ctx $ctx, string $no ): array {
		$d    = self::delivery( $ctx, $no );
		$info = self::detail( $ctx, $no );
		if ( ! $info['can_create_to'] ) {
			throw new DomainError( $ctx->simple() ? 'En gestion simple, il n\'y a pas d\'ordre de transfert.' : 'Un ordre de prélèvement est déjà en cours (ou la livraison n\'est plus à préparer).' );
		}
		$plan     = array();
		$reserved = array();
		$missing  = array();
		foreach ( $info['items_edit'] as $i ) {
			$m     = Catalog::material( (int) $i['material']['id'] );
			$opts  = array( 'reserved' => $reserved );
			if ( '' !== $i['batch'] ) {
				$opts['batch'] = $i['batch'];
			}
			$alloc = Strategies::allocate( $ctx->id, $m, $i['qty'], $ctx->date(), $opts );
			if ( $alloc['missing'] > 0.0005 ) {
				$missing[] = sprintf( 'Poste %d (%s) : il manque %s %s en stock libre%s.', $i['so_line'], $m['material_no'], Catalog::format_qty( $alloc['missing'] ), $m['base_uom'], '' !== $i['batch'] ? ' dans le lot ' . $i['batch'] : '' );
				continue;
			}
			foreach ( $alloc['alloc'] as $a ) {
				$reserved[ (int) $a['row']['id'] ] = ( $reserved[ (int) $a['row']['id'] ] ?? 0 ) + $a['qty'];
				$plan[] = array( $i, $m, $a );
			}
		}
		if ( $missing ) {
			throw new DomainError( 'Erreur de transfert : l\'OT ne peut pas être créé (analysez le stock dans LS26 : production ou entrée non quittancée, marchandise en zone retour…).', $missing );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $plan ) {
				[ $ship_bin, $ship_loc ] = Receiving::reception_bin( $ctx, '700' );
				$to_no = NumberRange::next( $ctx->id, 'TO' );
				$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $to_no, 'type' => 'PICK', 'ref_type' => 'DLV', 'ref_id' => (int) $d['id'], 'status' => 'OUVERT', 'sector' => '', 'picker' => 'Préparateur 1', 'created_at' => Db::now() ) );
				foreach ( $plan as $k => [ $i, $m, $a ] ) {
					Db::insert( 'transfer_order_item', array( 'to_id' => $to_id, 'line_no' => $k + 1, 'material_id' => (int) $m['id'], 'batch' => (string) $a['row']['batch'], 'bbd' => $a['row']['bbd'] ?: null, 'stock_status' => 'LIBRE', 'src_location_id' => (int) $a['row']['storage_location_id'], 'src_bin_id' => (int) $a['row']['bin_id'], 'dst_location_id' => (int) $ship_loc['id'], 'dst_bin_id' => (int) $ship_bin['id'], 'qty' => $a['qty'], 'ref_item_id' => (int) $i['id'] ) );
				}
				Db::update( 'outbound_delivery', array( 'status' => 'EN_PREPARATION', 'pick_mode' => 'SERIE', 'cons_bin_id' => 0 ), array( 'id' => (int) $d['id'] ) );
				return array( 'no' => $to_no, 'to_no' => $to_no, 'message' => sprintf( 'Ordre de prélèvement %s créé pour la livraison %s (%d ligne%s).', $to_no, $d['dlv_no'], count( $plan ), count( $plan ) > 1 ? 's' : '' ) );
			}
		);
	}

	/** VL02N « Supprimer » : défait les paquets, annule les OT ouverts ; la marchandise prélevée reste au quai (700). */
	public static function delete( Ctx $ctx, string $no ): array {
		global $wpdb;
		$d = self::delivery( $ctx, $no );
		if ( in_array( $d['status'], array( 'EXPEDIEE', 'SUPPRIMEE' ), true ) ) {
			throw new DomainError( 'EXPEDIEE' === $d['status'] ? 'Livraison expédiée : annulez d\'abord la sortie de marchandises (VL09).' : 'Livraison déjà supprimée.' );
		}
		if ( (int) $d['shipment_id'] ) {
			throw new DomainError( 'La livraison est dans une tournée : la planification doit d\'abord la retirer du transport.' );
		}
		$picked = self::picked_batches( $ctx, (int) $d['id'] );
		$qty_picked = array_sum( array_map( static fn( $l ) => array_sum( array_column( $l, 'qty' ) ), $picked ) );
		return Db::transaction(
			static function () use ( $ctx, $d, $wpdb, $picked, $qty_picked ) {
				$hu  = Db::t( 'handling_unit' );
				$hui = Db::t( 'handling_unit_item' );
				$wpdb->query( $wpdb->prepare( "DELETE i FROM {$hui} i JOIN {$hu} h ON h.id = i.hu_id WHERE h.session_id = %d AND h.delivery_id = %d", $ctx->id, (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$hu} WHERE session_id = %d AND delivery_id = %d", $ctx->id, (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'transfer_order' ) . " SET status = 'ANNULE' WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d AND status = 'OUVERT'", $ctx->id, (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				// La réservation des postes prélevés revient à la commande.
				$si = Db::t( 'sales_order_item' );
				foreach ( self::items( (int) $d['id'] ) as $i ) {
					$q = array_sum( array_column( $picked[ (int) $i['id'] ] ?? array(), 'qty' ) );
					if ( $q > 0 ) {
						$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = LEAST(GREATEST(0, qty - qty_delivered), qty_reserved + %f) WHERE id = %d", $q, (int) $i['so_item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
				}
				$wpdb->delete( Db::t( 'outbound_delivery_item' ), array( 'delivery_id' => (int) $d['id'] ) );
				Db::update( 'outbound_delivery', array( 'status' => 'SUPPRIMEE', 'carrier_code' => '', 'packages' => 0, 'weight_kg' => 0 ), array( 'id' => (int) $d['id'] ) );
				Sales::refresh_status( (int) $d['so_id'] );
				StockTx::log_change( $ctx, 'DLV', $d['dlv_no'], 0, 'Livraison', $d['status'], 'SUPPRIMEE', 'VL02N' );
				$msg = sprintf( 'Livraison %s supprimée.', $d['dlv_no'] );
				if ( $qty_picked > 0.0005 ) {
					$msg .= ' La marchandise déjà prélevée reste au quai d\'expédition (700) : remettez-la en stock avec LT10.';
				}
				return array( 'no' => $d['dlv_no'], 'message' => $msg . ' Le n° de bulletin pourra être repris lors de la prochaine livraison de cette commande.' );
			}
		);
	}

	/** VL06O : livraisons à traiter (prélèvement à confirmer, à emballer, prêtes pour la sortie). */
	public static function vl06o( Ctx $ctx ): array {
		global $wpdb;
		$d    = Db::t( 'outbound_delivery' );
		$o    = Db::t( 'sales_order' );
		$c    = Db::t( 'customer' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.dlv_no, d.status, d.carrier_code, d.weight_kg, d.packages, d.shipment_id, d.created_at, o.so_no, o.req_date, o.priority, c.name customer, c.city, c.type FROM {$d} d JOIN {$o} o ON o.id = d.so_id JOIN {$c} c ON c.id = o.customer_id WHERE d.session_id = %d AND d.status IN ('A_PREPARER','EN_PREPARATION','PREPAREE','EMBALLEE') ORDER BY FIELD(d.status,'EMBALLEE','PREPAREE','EN_PREPARATION','A_PREPARER'), o.priority, o.req_date LIMIT 200", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['ready']    = 'EMBALLEE' === $r['status'] && '' !== (string) $r['carrier_code'] && ! (int) $r['shipment_id'] ? 1 : 0;
			$r['internal'] = 'Site interne' === $r['type'] ? 1 : 0;
			$r['weight_kg'] = (float) $r['weight_kg'];
		}
		return $rows;
	}

	/** Avertissement « position cochée » avant la sortie de marchandises. */
	public static function delayed_warning( Ctx $ctx, string $no ): string {
		global $wpdb;
		$d = Sales::delivery_by_no( $ctx, $no );
		if ( ! $d ) {
			return '';
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT MAX(si.confirmed_date) cd, SUM(si.is_delayed) n FROM ' . Db::t( 'outbound_delivery_item' ) . ' di JOIN ' . Db::t( 'sales_order_item' ) . ' si ON si.id = di.so_item_id WHERE di.delivery_id = %d AND si.is_delayed = 1', (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row && (int) $row['n'] ? sprintf( 'Attention : la commande a encore des positions cochées ; la confirmation envoyée au client indiquera le %s au lieu de la date réelle. Enlevez la coche (VA02, contrôle de disponibilité) avant la sortie.', Api::fr_date( (string) $row['cd'] ) ) : '';
	}

	/** VL06O : sortie de marchandises collective. */
	public static function collective_gi( Ctx $ctx, array $nos ): array {
		$nos = array_slice( array_values( array_unique( array_filter( array_map( static fn( $v ) => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) ), $nos ) ) ) ), 0, 30 );
		if ( ! $nos ) {
			throw new DomainError( 'Sélectionnez au moins une livraison.' );
		}
		$done   = array();
		$errors = array();
		$warn   = array();
		foreach ( $nos as $no ) {
			try {
				$w = self::delayed_warning( $ctx, $no );
				$done[] = array( 'dlv_no' => $no, 'doc_no' => Sales::goods_issue( $ctx, $no ) );
				if ( '' !== $w ) {
					$warn[] = $no . ' : ' . $w;
				}
			} catch ( DomainError $e ) {
				$errors[] = $no . ' : ' . $e->getMessage();
			}
		}
		if ( ! $done ) {
			throw new DomainError( 'Aucune sortie de marchandises enregistrée.', $errors );
		}
		return array( 'done' => $done, 'errors' => $errors, 'warnings' => $warn, 'doc_no' => $done[0]['doc_no'], 'message' => sprintf( '%d sortie%s de marchandises enregistrée%s.', count( $done ), count( $done ) > 1 ? 's' : '', count( $done ) > 1 ? 's' : '' ) . ( $errors ? ' ' . count( $errors ) . ' livraison(s) non traitée(s).' : '' ) );
	}

	/** VL09 : annulation de la sortie de marchandises (602) ou de l'entrée d'un retour client non encore traité (652). */
	public static function vl09( Ctx $ctx, string $no, string $reason ): array {
		global $wpdb;
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif de l\'annulation.' );
		}
		$ret = Returns::by_no( $ctx, $no );
		if ( $ret ) {
			return self::cancel_return( $ctx, $ret, $reason );
		}
		$d = self::delivery( $ctx, $no );
		if ( 'EXPEDIEE' !== $d['status'] ) {
			throw new DomainError( sprintf( 'La livraison %s n\'a pas de sortie de marchandises à annuler.', $no ) );
		}
		if ( (int) $d['shipment_id'] ) {
			throw new DomainError( 'Cette livraison est partie avec une tournée : l\'annulation passe par la planification du transport.' );
		}
		$docs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'material_doc' ) . " WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d AND rev_of = '' ORDER BY id", $ctx->id, (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$lines = array();
		$pick  = array();
		$back  = array();
		foreach ( $docs as $doc ) {
			foreach ( StockTx::doc_items( (int) $doc['id'] ) as $i ) {
				if ( '601' !== $i['movement_type'] || (int) $i['reversed'] ) {
					continue;
				}
				$lines[] = array( 'movement_type' => '602', 'material_id' => (int) $i['material_id'], 'location_id' => (int) $i['storage_location_id'], 'bin_id' => (int) $i['bin_id'], 'batch' => $i['batch'], 'bbd' => $i['bbd'], 'status' => $i['stock_status'], 'qty' => -(float) $i['qty'], 'reason' => mb_substr( $reason, 0, 120 ) );
				$pick[]  = (int) $i['id'];
				$back[ (int) $i['material_id'] ] = ( $back[ (int) $i['material_id'] ] ?? 0 ) - (float) $i['qty'];
				$rev_of  = $doc['doc_no'];
			}
		}
		if ( ! $lines ) {
			throw new DomainError( 'Aucune sortie de marchandises active pour cette livraison.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $lines, $pick, $back, $rev_of, $reason, $wpdb ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'DLV', 'ref_id' => (int) $d['id'], 'ref_no' => $d['dlv_no'], 'text' => 'Annulation de la sortie de marchandises ' . $d['dlv_no'], 'tcode' => 'VL09', 'rev_of' => $rev_of ), $lines );
				foreach ( $pick as $id ) {
					$wpdb->update( Db::t( 'material_doc_item' ), array( 'reversed' => 1 ), array( 'id' => $id ) );
				}
				$si = Db::t( 'sales_order_item' );
				foreach ( self::items( (int) $d['id'] ) as $i ) {
					$q = min( (float) $i['qty_picked'] > 0 ? (float) $i['qty_picked'] : (float) $i['qty'], (float) ( $back[ (int) $i['material_id'] ] ?? 0 ) );
					if ( $q > 0 ) {
						$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_delivered = GREATEST(0, qty_delivered - %f) WHERE id = %d", $q, (int) $i['so_item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$back[ (int) $i['material_id'] ] -= $q;
					}
				}
				Db::update( 'outbound_delivery', array( 'status' => 'EMBALLEE', 'issued_at' => null ), array( 'id' => (int) $d['id'] ) );
				Sales::refresh_status( (int) $d['so_id'] );
				StockTx::log_change( $ctx, 'DLV', $d['dlv_no'], 0, 'Sortie de marchandises', 'comptabilisée', 'annulée : ' . $reason, 'VL09' );
				return array( 'doc_no' => $doc, 'no' => $d['dlv_no'], 'message' => sprintf( 'Sortie de marchandises de la livraison %s annulée (602), document %s. La marchandise est de nouveau au quai d\'expédition ; la livraison peut être corrigée ou ressortie.', $d['dlv_no'], $doc ) );
			}
		);
	}

	private static function cancel_return( Ctx $ctx, array $ret, string $reason ): array {
		global $wpdb;
		if ( 'RECU' !== $ret['status'] ) {
			throw new DomainError( sprintf( 'Le retour %s est déjà traité (%s) : il ne peut plus être annulé.', $ret['ret_no'], strtolower( $ret['status'] ) ) );
		}
		$docs  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'material_doc' ) . " WHERE session_id = %d AND ref_type = 'RET' AND ref_id = %d AND rev_of = ''", $ctx->id, (int) $ret['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$lines = array();
		$ids   = array();
		$of    = '';
		foreach ( $docs as $doc ) {
			foreach ( StockTx::doc_items( (int) $doc['id'] ) as $i ) {
				if ( '651' === $i['movement_type'] && ! (int) $i['reversed'] ) {
					$lines[] = array( 'movement_type' => '652', 'material_id' => (int) $i['material_id'], 'location_id' => (int) $i['storage_location_id'], 'bin_id' => (int) $i['bin_id'], 'batch' => $i['batch'], 'bbd' => $i['bbd'], 'status' => $i['stock_status'], 'qty' => -(float) $i['qty'], 'reason' => mb_substr( $reason, 0, 120 ) );
					$ids[]   = (int) $i['id'];
					$of      = $doc['doc_no'];
				}
			}
		}
		if ( ! $lines ) {
			throw new DomainError( 'Aucune entrée de retour à annuler.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $ret, $lines, $ids, $of, $wpdb ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'RET', 'ref_id' => (int) $ret['id'], 'ref_no' => $ret['ret_no'], 'text' => 'Annulation de l\'entrée du retour ' . $ret['ret_no'], 'tcode' => 'VL09', 'rev_of' => $of ), $lines );
				foreach ( $ids as $id ) {
					$wpdb->update( Db::t( 'material_doc_item' ), array( 'reversed' => 1 ), array( 'id' => $id ) );
				}
				Db::update( 'return_doc', array( 'status' => 'ANNULE' ), array( 'id' => (int) $ret['id'] ) );
				return array( 'doc_no' => $doc, 'no' => $ret['ret_no'], 'message' => sprintf( 'Entrée du retour %s annulée (652), document %s. Pour clôturer le document de retour, saisissez un motif de refus dans VA02.', $ret['ret_no'], $doc ) );
			}
		);
	}

	/** VL74 : étiquettes des unités d'expédition d'une livraison. */
	public static function vl74( Ctx $ctx, string $no ): array {
		$d = self::delivery( $ctx, $no );
		$det = Sales::delivery_detail( $ctx, $no );
		$units = array();
		foreach ( Picking::units_of( (int) $d['id'] ) as $u ) {
			$units[] = array( 'hu_no' => $u['hu_no'], 'sscc' => $u['sscc'], 'type' => $u['type'], 'status' => $u['status'] );
		}
		return array( 'dlv_no' => $d['dlv_no'], 'so_no' => $det['so_no'], 'customer' => $det['customer'], 'units' => $units, 'packages' => (int) $d['packages'], 'weight_kg' => (float) $d['weight_kg'], 'support' => $d['pallet_type'], 'carrier' => $d['carrier_code'] );
	}

	/** Bulletin de livraison (message ZPAL) : données à imprimer. */
	public static function delivery_note( Ctx $ctx, string $no ): array {
		$d       = self::delivery( $ctx, $no );
		$det     = Sales::delivery_detail( $ctx, $no );
		$picked  = self::picked_batches( $ctx, (int) $d['id'] );
		$lines   = array();
		foreach ( self::items( (int) $d['id'] ) as $k => $i ) {
			$m       = Catalog::material( (int) $i['material_id'] );
			$lines[] = array( 'pos' => ( $k + 1 ) * 10, 'material' => Api::material_summary( $m ) + array( 'un' => $m['un_number'], 'packing_group' => $m['packing_group'], 'hazard' => $m['hazard_text'] ), 'qty' => (float) $i['qty'], 'batches' => $picked[ (int) $i['id'] ] ?? ( '' !== (string) $i['batch'] ? array( array( 'batch' => $i['batch'], 'qty' => (float) $i['qty'] ) ) : array() ) );
		}
		// Poids brut : celui de l'emballage s'il est saisi, sinon calculé à partir des articles.
		$weight = (float) $d['weight_kg'];
		if ( $weight <= 0 ) {
			foreach ( self::items( (int) $d['id'] ) as $i ) {
				$m       = Catalog::material( (int) $i['material_id'] );
				$weight += (float) $i['qty'] * (float) ( $m['gross_weight'] ?? 0 );
			}
		}
		$shp = null;
		if ( (int) $d['shipment_id'] ) {
			global $wpdb;
			$sr = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'shipment' ) . ' WHERE id = %d', (int) $d['shipment_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $sr ) {
				$eta = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT eta FROM ' . Db::t( 'shipment_stop' ) . ' WHERE shipment_id = %d AND delivery_id = %d', (int) $sr['id'], (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$shp = array( 'no' => $sr['shp_no'], 'type' => $sr['shp_type'], 'means' => TransportPlan::means_label( $sr ), 'date' => $sr['ship_date'], 'eta' => $eta );
			}
		}
		return array( 'dlv_no' => $d['dlv_no'], 'so_no' => $det['so_no'], 'status' => $d['status'], 'date' => $shp ? $shp['date'] : $ctx->date(), 'shipment' => $shp, 'customer' => $det['customer'], 'carrier' => $d['carrier_code'] ? TransportPlan::carrier_name( (string) $d['carrier_code'] ) : ( $shp ? $shp['means'] : '' ), 'packages' => (int) $d['packages'], 'weight_kg' => round( $weight, 1 ), 'support' => $d['pallet_type'], 'lines' => $lines, 'company' => Company::name(), 'address' => Company::delivery_address() );
	}

	/** Certificat d'analyse (message de poste) : valeurs du lot, reproductibles. */
	public static function certificate( Ctx $ctx, string $no, int $item_id ): array {
		$d    = self::delivery( $ctx, $no );
		$note = self::delivery_note( $ctx, $no );
		$item = null;
		foreach ( self::items( (int) $d['id'] ) as $k => $i ) {
			if ( (int) $i['id'] === $item_id ) {
				$item = $note['lines'][ $k ];
			}
		}
		if ( ! $item ) {
			throw new DomainError( 'Poste de livraison introuvable.' );
		}
		$m = Catalog::material( (int) $item['material']['id'] );
		if ( empty( $m['batch_managed'] ) ) {
			throw new DomainError( 'Le certificat d\'analyse se rapporte à un lot : cet article n\'est pas géré par lot.' );
		}
		$batches = $item['batches'];
		if ( ! $batches ) {
			throw new DomainError( 'Le lot n\'est pas encore connu : confirmez le prélèvement (ou imposez le lot) avant d\'imprimer le certificat.' );
		}
		$out = array();
		foreach ( $batches as $b ) {
			$h     = abs( crc32( $m['material_no'] . '|' . $b['batch'] ) );
			$pct   = preg_match( '/(\d+(?:[.,]\d+)?)\s*%/', (string) $m['designation'], $mm ) ? (float) str_replace( ',', '.', $mm[1] ) : 0.0;
			$out[] = array(
				'batch'   => $b['batch'],
				'qty'     => $b['qty'],
				'results' => array_values( array_filter( array(
					$pct > 0 ? array( 'Teneur', sprintf( '%s %%', number_format( $pct + ( ( $h % 21 ) - 10 ) / 50, 2, '.', '' ) ), sprintf( '%s ± 0,3 %%', number_format( $pct, 1, ',', '' ) ) ) : null,
					array( 'Aspect', 'Liquide limpide, incolore', 'Conforme à la spécification' ),
					array( 'Fer (Fe)', sprintf( '%d mg/kg', 1 + $h % 6 ), '≤ 10 mg/kg' ),
					array( 'Résidu à l\'évaporation', sprintf( '%s %%', number_format( ( $h % 9 ) / 100, 2, '.', '' ) ), '≤ 0,10 %' ),
				) ) ),
			);
		}
		return array( 'dlv_no' => $d['dlv_no'], 'so_no' => $note['so_no'], 'customer' => $note['customer'], 'material' => $item['material'], 'qty' => $item['qty'], 'batches' => $out, 'date' => $ctx->date(), 'company' => $note['company'] );
	}

	/* ───────── VA02 / VA05 ───────── */

	public static function so_detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$d  = Sales::detail( $ctx, $no );
		$so = Sales::by_no( $ctx, $no );
		foreach ( $d['items'] as &$i ) {
			$row          = $wpdb->get_row( $wpdb->prepare( 'SELECT created_at FROM ' . Db::t( 'sales_order_item' ) . ' WHERE id = %d', (int) $i['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$i['created_at'] = $row && $row['created_at'] ? $row['created_at'] : $so['created_at'];
			$i['in_delivery'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(di.qty),0) FROM ' . Db::t( 'outbound_delivery_item' ) . ' di JOIN ' . Db::t( 'outbound_delivery' ) . " d ON d.id = di.delivery_id WHERE di.so_item_id = %d AND d.status NOT IN ('EXPEDIEE','SUPPRIMEE')", (int) $i['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$d['created_at']     = $so['created_at'];
		$d['changes']        = StockTx::changes( $ctx, 'SO', $no );
		$d['reject_reasons'] = self::REJECT_REASONS;
		$d['editable']       = ! in_array( $d['status'], array( 'LIVREE', 'ANNULEE' ), true ) ? 1 : 0;
		$d['internal']       = $d['customer'] && $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM ' . Db::t( 'customer' ) . ' WHERE id = %d', (int) $d['customer']['id'] ) ) === 'Site interne' ? 1 : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $d;
	}

	/**
	 * VA02 : modification de la commande client.
	 *
	 * @param array $items [{item_id, qty, reject, block}] ; $add [{material_id, qty}]
	 */
	public static function so_change( Ctx $ctx, string $no, string $req_date, array $items, array $add ): array {
		global $wpdb;
		$so = Sales::by_no( $ctx, $no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $no ) );
		}
		if ( in_array( $so['status'], array( 'LIVREE', 'ANNULEE' ), true ) ) {
			throw new DomainError( sprintf( 'La commande %s est %s : elle ne peut plus être modifiée.', $no, 'LIVREE' === $so['status'] ? 'entièrement livrée' : 'annulée' ) );
		}
		$det    = self::so_detail( $ctx, $no );
		$by     = array();
		foreach ( $det['items'] as $i ) {
			$by[ $i['id'] ] = $i;
		}
		$si     = Db::t( 'sales_order_item' );
		$errors = array();
		$plan   = array();
		if ( '' !== $req_date && $req_date !== $so['req_date'] ) {
			if ( ! Api::valid_date( $req_date ) || $req_date < $ctx->date() ) {
				$errors[] = 'Date de livraison invalide (à partir d\'aujourd\'hui).';
			} else {
				$plan[] = array( 'date', $req_date );
			}
		}
		foreach ( $items as $c ) {
			$id = (int) ( $c['item_id'] ?? 0 );
			if ( ! isset( $by[ $id ] ) ) {
				continue;
			}
			$i   = $by[ $id ];
			$ln  = $i['line_no'];
			$uom = $i['material']['uom'];
			$rej = strtoupper( (string) ( $c['reject'] ?? $i['reject_reason'] ) );
			if ( '' !== $rej && ! isset( self::REJECT_REASONS[ $rej ] ) ) {
				$errors[] = sprintf( 'Poste %d : motif de refus inconnu.', $ln );
				continue;
			}
			$min = $i['qty_delivered'] + $i['in_delivery'];
			if ( '' !== $rej && $rej !== $i['reject_reason'] ) {
				if ( $i['in_delivery'] > 0.0005 ) {
					$errors[] = sprintf( 'Poste %d : une livraison est en cours pour ce poste ; supprimez-la (VL02N) avant de refuser le poste.', $ln );
					continue;
				}
				$plan[] = array( 'reject', $i, $rej );
			} elseif ( '' === $rej && '' !== $i['reject_reason'] ) {
				$plan[] = array( 'reject', $i, '' );
			}
			if ( isset( $c['qty'] ) && '' !== $c['qty'] && null !== $c['qty'] ) {
				$q = round( (float) $c['qty'], 3 );
				if ( $q <= 0 || $q > Purchasing::MAX_QTY ) {
					$errors[] = sprintf( 'Poste %d : quantité invalide.', $ln );
					continue;
				}
				if ( $q + 0.0005 < $min ) {
					$errors[] = sprintf( 'Poste %d : déjà livré ou en livraison %s %s, la quantité ne peut pas être inférieure.', $ln, Catalog::format_qty( $min ), $uom );
					continue;
				}
				if ( abs( $q - $i['qty'] ) > 0.0005 ) {
					$plan[] = array( 'qty', $i, $q );
				}
			}
			if ( isset( $c['block'] ) && '' !== $c['block'] && null !== $c['block'] ) {
				$b = ! empty( $c['block'] ) ? 1 : 0;
				if ( $b !== (int) $i['delivery_block'] ) {
					$plan[] = array( 'block', $i, $b );
				}
			}
		}
		$new = array();
		foreach ( array_slice( $add, 0, 20 ) as $a ) {
			$m = Catalog::material( (int) ( $a['material_id'] ?? 0 ) );
			$q = round( (float) ( $a['qty'] ?? 0 ), 3 );
			if ( ! $m || 'ACTIF' !== $m['status'] || ! in_array( $m['type'], Sales::SELLABLE, true ) ) {
				$errors[] = 'Nouveau poste : article introuvable ou non vendable.';
				continue;
			}
			if ( $q <= 0 || $q > Purchasing::MAX_QTY ) {
				$errors[] = 'Nouveau poste : quantité invalide.';
				continue;
			}
			$new[] = array( $m, $q );
		}
		if ( $errors ) {
			throw new DomainError( 'La modification contient des erreurs.', $errors );
		}
		if ( ! $plan && ! $new ) {
			throw new DomainError( 'Aucune modification à enregistrer.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $so, $plan, $new, $si, $wpdb ) {
				$msg = array();
				foreach ( $plan as $p ) {
					switch ( $p[0] ) {
						case 'date':
							Db::update( 'sales_order', array( 'req_date' => $p[1] ), array( 'id' => (int) $so['id'] ) );
							$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET confirmed_date = %s WHERE so_id = %d AND is_delayed = 0", $p[1], (int) $so['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							StockTx::log_change( $ctx, 'SO', $so['so_no'], 0, 'Date de livraison', Api::fr_date( (string) $so['req_date'] ), Api::fr_date( $p[1] ), 'VA02' );
							$msg[] = 'date de livraison ' . Api::fr_date( $p[1] );
							break;
						case 'qty':
							$i   = $p[1];
							$q   = $p[2];
							$res = min( max( 0, $q - $i['qty_delivered'] ), $i['qty_reserved'] + max( 0, StockService::available( $ctx->id, (int) $i['material']['id'] ) ) );
							$wpdb->update( $si, array( 'qty' => $q, 'qty_reserved' => $res ), array( 'id' => (int) $i['id'] ) );
							StockTx::log_change( $ctx, 'SO', $so['so_no'], (int) $i['line_no'], 'Quantité commandée', Catalog::format_qty( $i['qty'] ) . ' ' . $i['material']['uom'], Catalog::format_qty( $q ) . ' ' . $i['material']['uom'], 'VA02' );
							$msg[] = sprintf( 'poste %d : %s %s', $i['line_no'], Catalog::format_qty( $q ), $i['material']['uom'] );
							break;
						case 'reject':
							$i = $p[1];
							$wpdb->update( $si, array( 'reject_reason' => $p[2], 'qty_reserved' => '' !== $p[2] ? 0 : min( max( 0, $i['qty'] - $i['qty_delivered'] ), StockService::available( $ctx->id, (int) $i['material']['id'] ) ) ), array( 'id' => (int) $i['id'] ) );
							StockTx::log_change( $ctx, 'SO', $so['so_no'], (int) $i['line_no'], 'Motif de refus', $i['reject_reason'] ? $i['reject_reason'] . ' ' . ( self::REJECT_REASONS[ $i['reject_reason'] ] ?? '' ) : '—', '' !== $p[2] ? $p[2] . ' ' . self::REJECT_REASONS[ $p[2] ] : '—', 'VA02' );
							$msg[] = sprintf( 'poste %d : %s', $i['line_no'], '' !== $p[2] ? 'refusé (' . self::REJECT_REASONS[ $p[2] ] . ')' : 'refus levé' );
							break;
						case 'block':
							$i = $p[1];
							$wpdb->update( $si, array( 'delivery_block' => $p[2] ), array( 'id' => (int) $i['id'] ) );
							StockTx::log_change( $ctx, 'SO', $so['so_no'], (int) $i['line_no'], 'Blocage de livraison', $i['delivery_block'] ? 'oui' : 'non', $p[2] ? 'oui' : 'non', 'VA02' );
							$msg[] = sprintf( 'poste %d : %s', $i['line_no'], $p[2] ? 'bloqué pour la livraison' : 'débloqué pour la livraison' );
							break;
					}
				}
				if ( $new ) {
					$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(line_no) FROM {$si} WHERE so_id = %d", (int) $so['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					foreach ( $new as [ $m, $q ] ) {
						$max  += 10;
						$res   = min( $q, StockService::available( $ctx->id, (int) $m['id'] ) );
						$late  = $res + 0.0005 < $q;
						$req   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT req_date FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $so['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						Db::insert( 'sales_order_item', array( 'so_id' => (int) $so['id'], 'line_no' => $max, 'material_id' => (int) $m['id'], 'qty' => $q, 'qty_reserved' => $res, 'price' => (float) $m['sale_price'], 'is_delayed' => $late ? 1 : 0, 'confirmed_date' => $late ? max( $req, gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 1, (int) $m['lead_time_days'] ) . ' days' ) ) ) : $req, 'created_at' => Db::now() ) );
						StockTx::log_change( $ctx, 'SO', $so['so_no'], $max, 'Nouveau poste', '—', $m['material_no'] . ' · ' . Catalog::format_qty( $q ) . ' ' . $m['base_uom'], 'VA02' );
						$msg[] = sprintf( 'poste %d ajouté (%s)', $max, $m['material_no'] );
					}
				}
				Sales::refresh_status( (int) $so['id'] );
				$st = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $so['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( 'LIVREE' !== $st && 'ANNULEE' !== $st && in_array( $so['status'], array( 'EN_PREPARATION' ), true ) && $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'outbound_delivery' ) . " WHERE so_id = %d AND status NOT IN ('EXPEDIEE','SUPPRIMEE')", (int) $so['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					Db::update( 'sales_order', array( 'status' => 'EN_PREPARATION' ), array( 'id' => (int) $so['id'] ) );
				}
				return array( 'no' => $so['so_no'], 'message' => sprintf( 'Commande %s modifiée : %s.', $so['so_no'], implode( ' ; ', $msg ) ) );
			}
		);
	}

	/** VA02 « Disponibilité » : enlève la coche des positions désormais couvertes par le stock. */
	public static function availability( Ctx $ctx, string $no ): array {
		global $wpdb;
		$so = Sales::by_no( $ctx, $no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $no ) );
		}
		$si   = Db::t( 'sales_order_item' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$si} WHERE so_id = %d AND is_delayed = 1 AND reject_reason = ''", (int) $so['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			throw new DomainError( 'Aucune position cochée sur cette commande.' );
		}
		$ok   = array();
		$ko   = array();
		foreach ( $rows as $r ) {
			$m    = Catalog::material( (int) $r['material_id'] );
			$need = (float) $r['qty'] - (float) $r['qty_delivered'];
			$have = (float) $r['qty_reserved'] + StockService::available( $ctx->id, (int) $r['material_id'] ) + StockTx::e_stock( $ctx, StockTx::so_ref( (string) $so['so_no'], (int) $r['line_no'] ), (int) $r['material_id'] );
			if ( $have + 0.0005 >= $need ) {
				$res = min( $need, (float) $r['qty_reserved'] + StockService::available( $ctx->id, (int) $r['material_id'] ) );
				$wpdb->update( $si, array( 'is_delayed' => 0, 'confirmed_date' => $so['req_date'], 'qty_reserved' => $res ), array( 'id' => (int) $r['id'] ) );
				StockTx::log_change( $ctx, 'SO', $so['so_no'], (int) $r['line_no'], 'Date confirmée', Api::fr_date( (string) $r['confirmed_date'] ), Api::fr_date( (string) $so['req_date'] ), 'VA02' );
				$ok[] = (int) $r['line_no'];
			} else {
				$ko[] = sprintf( 'Poste %d (%s) : disponible %s pour %s %s.', (int) $r['line_no'], $m['material_no'], Catalog::format_qty( $have ), Catalog::format_qty( $need ), $m['base_uom'] );
			}
		}
		if ( ! $ok ) {
			throw new DomainError( 'La marchandise n\'est toujours pas disponible : la coche reste.', $ko );
		}
		return array( 'no' => $so['so_no'], 'message' => sprintf( 'Coche enlevée sur le%s poste%s %s : date confirmée au %s.', count( $ok ) > 1 ? 's' : '', count( $ok ) > 1 ? 's' : '', implode( ', ', $ok ), Api::fr_date( (string) $so['req_date'] ) ) . ( $ko ? ' Restent cochés : ' . implode( ' ', $ko ) : '' ) );
	}

	/** VA05 : liste des commandes clients par donneur d'ordre ou article. */
	public static function va05( Ctx $ctx, string $customer, string $material ): array {
		global $wpdb;
		$so    = Db::t( 'sales_order' );
		$si    = Db::t( 'sales_order_item' );
		$c     = Db::t( 'customer' );
		$m     = Db::t( 'material' );
		$where = array( 'o.session_id = %d' );
		$args  = array( $ctx->id );
		if ( '' !== trim( $customer ) ) {
			$where[] = '(c.customer_no = %s OR c.name LIKE %s)';
			$args[]  = strtoupper( trim( $customer ) );
			$args[]  = '%' . $wpdb->esc_like( rtrim( trim( $customer ), '*' ) ) . '%';
		}
		if ( '' !== trim( $material ) ) {
			$where[] = '(m.material_no = %s OR m.designation LIKE %s)';
			$args[]  = strtoupper( trim( $material ) );
			$args[]  = '%' . $wpdb->esc_like( trim( $material ) ) . '%';
		}
		if ( 1 === count( $where ) ) {
			throw new DomainError( 'Indiquez le donneur d\'ordre (n° ou nom) ou un article.' );
		}
		$sqlw = implode( ' AND ', $where );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.so_no, o.status, o.order_date, o.req_date, o.created_at, c.customer_no, c.name customer, i.line_no, i.qty, i.qty_delivered, i.is_delayed, i.confirmed_date, i.reject_reason, i.created_at item_created, m.material_no, m.designation, m.base_uom FROM {$si} i JOIN {$so} o ON o.id = i.so_id JOIN {$c} c ON c.id = o.customer_id JOIN {$m} m ON m.id = i.material_id WHERE {$sqlw} ORDER BY o.id DESC, i.line_no LIMIT 300", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['qty']           = (float) $r['qty'];
			$r['qty_delivered'] = (float) $r['qty_delivered'];
			$r['entered_at']    = $r['item_created'] ?: $r['created_at'];
		}
		return $rows;
	}
}
