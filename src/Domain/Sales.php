<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ventes et expédition : commandes clients (VA01), livraisons (VL01N), emballage et sortie 601 (VL02N).
 */
final class Sales {

	public const SELLABLE = array( 'MRCH', 'PF', 'SF' );

	public static function customer( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'customer' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function open_value( Ctx $ctx, int $customer_id ): float {
		global $wpdb;
		$o = Db::t( 'sales_order' );
		$i = Db::t( 'sales_order_item' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM((i.qty - i.qty_delivered) * i.price),0) FROM {$i} i JOIN {$o} o ON o.id = i.so_id WHERE o.session_id = %d AND o.customer_id = %d AND o.status IN ('CONFIRMEE','EN_PREPARATION','PARTIELLEMENT_LIVREE')", $ctx->id, $customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array $lines [{material_id, qty}]
	 * @return array{so_no:string, lines:array}
	 */
	public static function create( Ctx $ctx, int $customer_id, array $lines, string $req_date, string $priority = 'B', string $note = '' ): array {
		$c = self::customer( $customer_id );
		if ( ! $c || empty( $c['active'] ) ) {
			throw new DomainError( 'Client introuvable ou inactif.' );
		}
		if ( ! empty( $c['blocked'] ) ) {
			throw new DomainError( sprintf( 'Le client %s est bloqué pour livraison (contentieux) : la commande ne peut pas être saisie. Informez le service commercial.', $c['customer_no'] ) );
		}
		if ( ! Api::valid_date( $req_date ) || $req_date < $ctx->date() ) {
			throw new DomainError( 'La date de livraison souhaitée doit être une date valide, à partir d\'aujourd\'hui.' );
		}
		if ( ! in_array( $priority, array( 'A', 'B', 'C' ), true ) ) {
			$priority = 'B';
		}
		if ( ! $lines || count( $lines ) > Purchasing::MAX_LINES ) {
			throw new DomainError( 'La commande doit contenir entre 1 et 50 lignes.' );
		}
		$errors = array();
		$valid  = array();
		$value  = 0.0;
		foreach ( array_values( $lines ) as $i => $l ) {
			$n   = ( $i + 1 ) * 10;
			$m   = Catalog::material( (int) $l['material_id'] );
			$qty = (float) $l['qty'];
			if ( ! $m || 'ACTIF' !== $m['status'] ) {
				$errors[] = sprintf( 'Ligne %d : article introuvable ou inactif.', $n );
				continue;
			}
			if ( ! in_array( $m['type'], self::SELLABLE, true ) ) {
				$errors[] = sprintf( 'Ligne %d : l\'article %s (%s) n\'est pas vendable.', $n, $m['material_no'], $m['type'] );
				continue;
			}
			if ( $qty <= 0 || $qty > Purchasing::MAX_QTY ) {
				$errors[] = sprintf( 'Ligne %d : quantité invalide.', $n );
				continue;
			}
			$valid[] = array( $m, $qty );
			$value  += $qty * (float) $m['sale_price'];
		}
		if ( $errors ) {
			throw new DomainError( 'La commande contient des erreurs.', $errors );
		}
		$open = self::open_value( $ctx, $customer_id );
		if ( (float) $c['credit_limit'] > 0 && $open + $value > (float) $c['credit_limit'] ) {
			throw new DomainError( sprintf( 'Limite de crédit dépassée : %s CHF déjà en cours + %s CHF pour une limite de %s CHF. Validation commerciale nécessaire.', number_format( $open, 2, '.', '\'' ), number_format( $value, 2, '.', '\'' ), number_format( (float) $c['credit_limit'], 2, '.', '\'' ) ) );
		}
		return Db::transaction(
			static function () use ( $ctx, $c, $valid, $req_date, $priority, $note ) {
				$no    = NumberRange::next( $ctx->id, 'SO' );
				$so_id = Db::insert(
					'sales_order',
					array(
						'session_id'  => $ctx->id,
						'so_no'       => $no,
						'customer_id' => (int) $c['id'],
						'order_date'  => $ctx->date(),
						'req_date'    => $req_date,
						'priority'    => $priority,
						'status'      => 'CONFIRMEE',
						'note'        => mb_substr( $note, 0, 255 ),
						'created_by'  => get_current_user_id(),
						'created_at'  => Db::now(),
					)
				);
				$out = array();
				foreach ( $valid as $i => [ $m, $qty ] ) {
					$avail = StockService::available( $ctx->id, (int) $m['id'] );
					$res   = min( $qty, $avail );
					// Position « cochée » : le système ne peut pas tenir le délai et reporte la date confirmée.
					$conf  = $res + 0.0005 < $qty ? max( $req_date, gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 1, (int) $m['lead_time_days'] ) . ' days' ) ) ) : $req_date;
					$late  = $conf > $req_date;
					Db::insert( 'sales_order_item', array( 'so_id' => $so_id, 'line_no' => ( $i + 1 ) * 10, 'material_id' => (int) $m['id'], 'qty' => $qty, 'qty_reserved' => $res, 'price' => (float) $m['sale_price'], 'is_delayed' => $late ? 1 : 0, 'confirmed_date' => $conf, 'created_at' => Db::now() ) );
					$out[] = array(
						'line_no'   => ( $i + 1 ) * 10,
						'material'  => $m['material_no'],
						'qty'       => $qty,
						'reserved'  => $res,
						'missing'   => max( 0, $qty - $res ),
						'available_date' => $res + 0.0005 >= $qty ? $ctx->date() : gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 1, (int) $m['lead_time_days'] ) . ' days' ) ),
					);
				}
				return array( 'so_no' => $no, 'lines' => $out );
			}
		);
	}

	public static function create_from_scenario( Ctx $ctx, array $doc, array $items, string $date ): string {
		$no    = NumberRange::next( $ctx->id, 'SO' );
		$so_id = Db::insert(
			'sales_order',
			array(
				'session_id'   => $ctx->id,
				'so_no'        => $no,
				'scenario_ref' => (string) $doc['ref'],
				'customer_id'  => (int) $doc['partner_id'],
				'order_date'   => $ctx->date(),
				'req_date'     => max( $ctx->date(), $date ),
				'priority'     => in_array( $doc['priority'], array( 'A', 'B', 'C' ), true ) ? $doc['priority'] : 'B',
				'status'       => 'CONFIRMEE',
				'note'         => (string) $doc['note'],
				'created_at'   => Db::now(),
			)
		);
		foreach ( array_values( $items ) as $i => $it ) {
			$m = Catalog::material( (int) ( $it['material_id'] ?? 0 ) );
			if ( ! $m ) {
				continue;
			}
			$qty  = (float) $it['qty'];
			$res  = min( $qty, StockService::available( $ctx->id, (int) $m['id'] ) );
			$req  = max( $ctx->date(), $date );
			$conf = $res + 0.0005 < $qty ? max( $req, gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . max( 1, (int) $m['lead_time_days'] ) . ' days' ) ) ) : $req;
			Db::insert( 'sales_order_item', array( 'so_id' => $so_id, 'line_no' => ( $i + 1 ) * 10, 'material_id' => (int) $m['id'], 'qty' => $qty, 'qty_reserved' => $res, 'price' => (float) $m['sale_price'], 'is_delayed' => $conf > $req ? 1 : 0, 'confirmed_date' => $conf, 'created_at' => Db::now() ) );
		}
		return $no;
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'sales_order' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND so_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function items( int $so_id ): array {
		global $wpdb;
		$t = Db::t( 'sales_order_item' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE so_id = %d ORDER BY line_no", $so_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$so = self::by_no( $ctx, $no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $no ) );
		}
		$c     = self::customer( (int) $so['customer_id'] );
		$items = array();
		foreach ( self::items( (int) $so['id'] ) as $i ) {
			$m       = Catalog::material( (int) $i['material_id'] );
			$items[] = array(
				'id'            => (int) $i['id'],
				'line_no'       => (int) $i['line_no'],
				'material'      => Api::material_summary( $m ),
				'qty'           => (float) $i['qty'],
				'qty_reserved'  => (float) $i['qty_reserved'],
				'qty_delivered' => (float) $i['qty_delivered'],
				'price'         => (float) $i['price'],
				'available'     => StockService::available( $ctx->id, (int) $i['material_id'] ),
				'delayed'       => (int) $i['is_delayed'],
				'confirmed_date' => $i['confirmed_date'],
				'reject_reason' => (string) $i['reject_reason'],
				'delivery_block' => (int) $i['delivery_block'],
				'stock_e'       => StockTx::e_stock( $ctx, StockTx::so_ref( (string) $so['so_no'], (int) $i['line_no'] ), (int) $i['material_id'] ),
							);
		}
		$d    = Db::t( 'outbound_delivery' );
		$dlvs = $wpdb->get_results( $wpdb->prepare( "SELECT dlv_no, status, carrier_code FROM {$d} WHERE so_id = %d AND session_id = %d ORDER BY id", (int) $so['id'], $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'so_no'      => $so['so_no'],
			'status'     => $so['status'],
			'order_date' => $so['order_date'],
			'req_date'   => $so['req_date'],
			'priority'   => $so['priority'],
			'note'       => $so['note'],
			'customer'   => $c ? array( 'id' => (int) $c['id'], 'no' => $c['customer_no'], 'name' => $c['name'], 'city' => $c['city'], 'carrier' => $c['carrier_code'], 'window' => $c['delivery_window'], 'packing' => $c['packing_req'], 'blocked' => (int) $c['blocked'] ) : null,
			'items'      => $items,
			'deliveries' => (array) $dlvs,
		);
	}

	/** Modes de préparation des commandes. */
	public const PICK_MODES = array(
		'SERIE'     => 'En série : un préparateur prélève toute la commande',
		'PARALLELE' => 'En parallèle : un préparateur par secteur, puis regroupement',
	);

	/**
	 * Crée la livraison et, en gestion détaillée, les ordres de prélèvement (FIFO / FEFO).
	 * Série : un seul ordre, déposé au quai d'expédition (700).
	 * Parallèle : un ordre par secteur (magasin de prélèvement), tous déposés au même
	 * emplacement de regroupement (zone 600) où la commande est rassemblée et colisée.
	 */
	public static function create_delivery( Ctx $ctx, string $so_no, string $mode = 'SERIE', string $picker = '', string $reuse_no = '' ): array {
		global $wpdb;
		$mode = strtoupper( $mode );
		if ( ! isset( self::PICK_MODES[ $mode ] ) ) {
			throw new DomainError( 'Mode de préparation inconnu : choisissez « en série » ou « en parallèle ».' );
		}
		if ( 'PARALLELE' === $mode && $ctx->simple() ) {
			throw new DomainError( 'La préparation en parallèle par secteur demande la gestion détaillée des emplacements.' );
		}
		$picker = mb_substr( trim( (string) preg_replace( '/[^\p{L}\p{N} \-\.\']/u', '', $picker ) ), 0, 40 );
		$so = self::by_no( $ctx, $so_no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $so_no ) );
		}
		if ( ! in_array( $so['status'], array( 'CONFIRMEE', 'PARTIELLEMENT_LIVREE', 'EN_PREPARATION' ), true ) ) {
			throw new DomainError( sprintf( 'La commande %s ne peut plus être livrée (statut %s).', $so_no, $so['status'] ) );
		}
		$c = self::customer( (int) $so['customer_id'] );
		if ( $c && ! empty( $c['blocked'] ) ) {
			throw new DomainError( 'Le client est bloqué pour livraison.' );
		}
		$di = Db::t( 'outbound_delivery_item' );
		$d  = Db::t( 'outbound_delivery' );
		$plan     = array();
		$messages = array();
		$reserved_rows = array();
		$reuse = null;
		if ( '' !== $reuse_no ) {
			$reuse = self::delivery_by_no( $ctx, $reuse_no );
			if ( ! $reuse || 'SUPPRIMEE' !== $reuse['status'] || (int) $reuse['so_id'] !== (int) $so['id'] ) {
				throw new DomainError( sprintf( 'Le n° de bulletin %s ne peut pas être repris : il doit s\'agir d\'une livraison supprimée de la commande %s.', $reuse_no, $so_no ) );
			}
		}
		foreach ( self::items( (int) $so['id'] ) as $i ) {
			if ( '' !== (string) $i['reject_reason'] || (int) $i['delivery_block'] ) {
				continue; // Poste refusé ou bloqué pour la livraison (contrat).
			}
			$in_open = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(di.qty),0) FROM {$di} di JOIN {$d} d ON d.id = di.delivery_id WHERE di.so_item_id = %d AND d.status IN ('A_PREPARER','EN_PREPARATION','PREPAREE','EMBALLEE')", (int) $i['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$open    = (float) $i['qty'] - (float) $i['qty_delivered'] - $in_open;
			if ( $open <= 0.0005 ) {
				continue;
			}
			$m     = Catalog::material( (int) $i['material_id'] );
			$alloc = Strategies::allocate( $ctx->id, $m, $open, $ctx->date(), array( 'reserved' => $reserved_rows ) );
			foreach ( $alloc['alloc'] as $a ) {
				$reserved_rows[ (int) $a['row']['id'] ] = ( $reserved_rows[ (int) $a['row']['id'] ] ?? 0 ) + $a['qty'];
			}
			$got = $open - $alloc['missing'];
			if ( $alloc['missing'] > 0.0005 ) {
				$messages[] = sprintf( 'Ligne %d : %s %s manquants (rupture), livraison partielle.', (int) $i['line_no'], Catalog::format_qty( $alloc['missing'] ), $m['base_uom'] );
			}
			if ( $got > 0.0005 ) {
				$plan[] = array( 'item' => $i, 'm' => $m, 'qty' => $got, 'alloc' => $alloc['alloc'] );
			}
		}
		if ( ! $plan ) {
			throw new DomainError( 'Rien à livrer : aucune quantité ouverte n\'est disponible en stock libre (lots périmés et emplacements bloqués exclus).', $messages );
		}
		if ( 'PARALLELE' === $mode ) {
			$sectors = array();
			foreach ( $plan as $p ) {
				foreach ( $p['alloc'] as $a ) {
					$sectors[ (int) $a['row']['storage_location_id'] ] = true;
				}
			}
			if ( count( $sectors ) < 2 ) {
				$messages[] = 'Tous les articles se trouvent dans un seul secteur : une préparation en série aurait suffi.';
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $so, $plan, $messages, $c, $mode, $picker, $reuse, $wpdb ) {
				if ( $reuse ) {
					$wpdb->delete( Db::t( 'outbound_delivery' ), array( 'id' => (int) $reuse['id'] ) );
				}
				$no  = $reuse ? (string) $reuse['dlv_no'] : NumberRange::next( $ctx->id, 'DLV' );
				$did = Db::insert( 'outbound_delivery', array( 'session_id' => $ctx->id, 'dlv_no' => $no, 'so_id' => (int) $so['id'], 'status' => 'EN_PREPARATION', 'carrier_code' => '', 'pick_mode' => $mode, 'created_at' => Db::now() ) );
				$tos = array();
				$dst = null;
				if ( ! $ctx->simple() ) {
					$dst = Receiving::reception_bin( $ctx, 'PARALLELE' === $mode ? '600' : '700' );
					if ( 'PARALLELE' === $mode ) {
						Db::update( 'outbound_delivery', array( 'cons_bin_id' => (int) $dst[0]['id'] ), array( 'id' => $did ) );
					}
				}
				// Un ordre par secteur en parallèle (clé = magasin source), un seul ordre en série.
				$to_for = static function ( int $loc_id ) use ( $ctx, $did, $mode, $picker, &$tos ): array {
					$key = 'PARALLELE' === $mode ? $loc_id : 0;
					if ( ! isset( $tos[ $key ] ) ) {
						$loc         = Catalog::location( $loc_id );
						$sector      = 'PARALLELE' === $mode && $loc ? (string) $loc['code'] : '';
						$name        = 'PARALLELE' === $mode ? 'Préparateur ' . chr( 65 + min( 25, count( $tos ) ) ) : ( '' !== $picker ? $picker : 'Préparateur 1' );
						$to_no       = NumberRange::next( $ctx->id, 'TO' );
						$tos[ $key ] = array(
							'id'     => Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $to_no, 'type' => 'PICK', 'ref_type' => 'DLV', 'ref_id' => $did, 'status' => 'OUVERT', 'sector' => $sector, 'picker' => $name, 'created_at' => Db::now() ) ),
							'to_no'  => $to_no,
							'sector' => $sector,
							'picker' => $name,
							'line'   => 0,
						);
					}
					return $tos[ $key ];
				};
				foreach ( $plan as $p ) {
					$di_id = Db::insert( 'outbound_delivery_item', array( 'delivery_id' => $did, 'so_item_id' => (int) $p['item']['id'], 'material_id' => (int) $p['m']['id'], 'qty' => $p['qty'] ) );
					if ( $dst ) {
						[ $ship_bin, $ship_loc ] = $dst;
						foreach ( $p['alloc'] as $a ) {
							$to  = $to_for( (int) $a['row']['storage_location_id'] );
							$key = 'PARALLELE' === $mode ? (int) $a['row']['storage_location_id'] : 0;
							$tos[ $key ]['line']++;
							Db::insert(
								'transfer_order_item',
								array(
									'to_id'           => $to['id'],
									'line_no'         => $tos[ $key ]['line'],
									'material_id'     => (int) $p['m']['id'],
									'batch'           => (string) $a['row']['batch'],
									'bbd'             => $a['row']['bbd'] ?: null,
									'stock_status'    => 'LIBRE',
									'src_location_id' => (int) $a['row']['storage_location_id'],
									'src_bin_id'      => (int) $a['row']['bin_id'],
									'dst_location_id' => (int) $ship_loc['id'],
									'dst_bin_id'      => (int) $ship_bin['id'],
									'qty'             => $a['qty'],
									'ref_item_id'     => $di_id,
								)
							);
						}
					}
				}
				Db::update( 'sales_order', array( 'status' => 'EN_PREPARATION' ), array( 'id' => (int) $so['id'] ) );
				$list = array_values( array_map( static fn( $t ) => array( 'to_no' => $t['to_no'], 'sector' => $t['sector'], 'picker' => $t['picker'], 'lines' => $t['line'] ), $tos ) );
				return array( 'dlv_no' => $no, 'to_no' => $list ? $list[0]['to_no'] : '', 'tos' => $list, 'mode' => $mode, 'consolidation' => $dst && 'PARALLELE' === $mode ? $dst[0]['code'] : '', 'messages' => $messages, 'carrier' => $c ? $c['carrier_code'] : '' );
			}
		);
	}

	public static function delivery_by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'outbound_delivery' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND dlv_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function delivery_detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$d = self::delivery_by_no( $ctx, $no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $no ) );
		}
		$di    = Db::t( 'outbound_delivery_item' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$di} WHERE delivery_id = %d ORDER BY id", (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array();
		$needs = array( 'adr' => false, 'cold' => false );
		foreach ( (array) $rows as $r ) {
			$m = Catalog::material( (int) $r['material_id'] );
			if ( '' !== (string) $m['adr_class'] ) {
				$needs['adr'] = true;
			}
			if ( null !== $m['temp_max'] && (float) $m['temp_max'] <= 8 ) {
				$needs['cold'] = true;
			}
			$items[] = array( 'material' => Api::material_summary( $m ), 'qty' => (float) $r['qty'], 'qty_picked' => (float) $r['qty_picked'], 'weight' => round( (float) $r['qty'] * (float) $m['gross_weight'], 3 ) );
		}
		$so  = $wpdb->get_row( $wpdb->prepare( 'SELECT so_no, customer_id FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cust = $so ? self::customer( (int) $so['customer_id'] ) : null;
		$shp  = (int) $d['shipment_id'] ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT shp_no FROM ' . Db::t( 'shipment' ) . ' WHERE id = %d', (int) $d['shipment_id'] ) ) : ''; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tos = $wpdb->get_results( $wpdb->prepare( 'SELECT to_no, status FROM ' . Db::t( 'transfer_order' ) . " WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d", $ctx->id, (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'dlv_no'    => $d['dlv_no'],
			'so_no'     => $so ? $so['so_no'] : '',
			'status'    => $d['status'],
			'carrier'   => $d['carrier_code'],
			'packages'  => (int) $d['packages'],
			'weight_kg' => (float) $d['weight_kg'],
			'support'   => $d['pallet_type'],
			'frames'    => (int) $d['frames'],
			'lids'      => (int) $d['lids'],
			'shipment'  => $shp,
			'customer'  => $cust ? array( 'no' => $cust['customer_no'], 'name' => $cust['name'], 'city' => $cust['zip'] . ' ' . $cust['city'] ) + Transport::constraints( $cust ) : null,
			'items'     => $items,
			'needs'     => $needs,
			'transfer_orders' => Picking::pick_orders( $ctx, (int) $d['id'] ) ?: (array) $tos,
			'mode'      => $d['pick_mode'],
			'mode_label' => self::PICK_MODES[ $d['pick_mode'] ] ?? $d['pick_mode'],
			'consolidation' => (int) $d['cons_bin_id'] ? ( Catalog::bin( (int) $d['cons_bin_id'] ) ?: array( 'code' => '' ) )['code'] : '',
			'units'     => array_map( static fn( $u ) => array( 'hu_no' => $u['hu_no'], 'sscc' => $u['sscc'], 'type' => $u['type'], 'status' => $u['status'] ), Picking::units_of( (int) $d['id'] ) ),
		);
	}

	/** Mise à jour après confirmation d'un prélèvement. */
	public static function after_pick( Ctx $ctx, int $delivery_id ): void {
		global $wpdb;
		$ti = Db::t( 'transfer_order_item' );
		$t  = Db::t( 'transfer_order' );
		$di = Db::t( 'outbound_delivery_item' );
		$si = Db::t( 'sales_order_item' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.ref_item_id, SUM(i.qty_confirmed) q FROM {$ti} i JOIN {$t} o ON o.id = i.to_id WHERE o.session_id = %d AND o.ref_type = 'DLV' AND o.ref_id = %d AND o.status = 'CONFIRME' GROUP BY i.ref_item_id", $ctx->id, $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$di} WHERE id = %d", (int) $r['ref_item_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $item ) {
				continue;
			}
			$delta = (float) $r['q'] - (float) $item['qty_picked'];
			$wpdb->update( $di, array( 'qty_picked' => (float) $r['q'] ), array( 'id' => (int) $item['id'] ) );
			if ( $delta > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = GREATEST(0, qty_reserved - %f) WHERE id = %d", $delta, (int) $item['so_item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d AND status = 'OUVERT'", $ctx->id, $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $open ) {
			Db::update( 'outbound_delivery', array( 'status' => 'PREPAREE' ), array( 'id' => $delivery_id ) );
		}
	}

	/** Emballage : poids, colis, choix du transporteur avec contrôles ADR / froid / poids. */
	public static function pack( Ctx $ctx, string $dlv_no, string $carrier_code, int $packages, string $support = '', int $frames = 0, int $lids = 0 ): array {
		global $wpdb;
		$d = self::delivery_by_no( $ctx, $dlv_no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $dlv_no ) );
		}
		if ( 'EXPEDIEE' === $d['status'] ) {
			throw new DomainError( 'Cette livraison est déjà expédiée.' );
		}
		if ( ! $ctx->simple() && 'PREPAREE' !== $d['status'] && 'EMBALLEE' !== $d['status'] ) {
			throw new DomainError( 'Confirmez d\'abord le prélèvement (LT12) avant d\'emballer.' );
		}
		$forced = '';
		if ( (int) $d['shipment_id'] ) {
			// Livraison déjà affectée à un transport (VT11) : le moyen de transport est imposé tant que le transport n'est pas parti.
			$sr = $wpdb->get_row( $wpdb->prepare( 'SELECT shp_no, status, shp_type, carrier_code FROM ' . Db::t( 'shipment' ) . ' WHERE id = %d', (int) $d['shipment_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $sr && 'PLANIFIE' !== $sr['status'] ) {
				throw new DomainError( sprintf( 'Cette livraison est partie avec le transport %s.', $sr['shp_no'] ) );
			}
			if ( $sr ) {
				$forced       = 'TOURNEE' === $sr['shp_type'] ? 'TOUR' : (string) $sr['carrier_code'];
				$carrier_code = $forced;
			}
		}
		$tour    = 'TOUR' === strtoupper( $carrier_code );
		$ct      = Db::t( 'carrier' );
		$carrier = $tour ? array( 'code' => '', 'name' => 'Tournée (VT01N)', 'max_weight' => 40000 ) : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE code = %s", strtoupper( $carrier_code ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $carrier ) {
			throw new DomainError( 'Choisissez un transporteur ou « tournée planifiée (VT01N) ».' );
		}
		$support = strtoupper( $support );
		if ( '' !== $support && ! isset( Transport::SUPPORTS[ $support ] ) ) {
			throw new DomainError( 'Support de charge inconnu.' );
		}
		if ( $frames < 0 || $lids < 0 || $frames > 99 || $lids > 99 ) {
			throw new DomainError( 'Nombre de cadres ou de couvercles invalide.' );
		}
		$so_row = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cust   = $so_row ? self::customer( (int) $so_row['customer_id'] ) : null;
		// Colisage par unités d'expédition (HU02) : il fixe le nombre d'unités, le support et le poids.
		$hu = Picking::for_packing( $ctx, $d );
		if ( $hu ) {
			$packages = $hu['packages'];
			$support  = $hu['support'];
		}
		if ( $tour && '' === $support ) {
			throw new DomainError( 'Indiquez le support de charge (palette EUR, CP3, perdue ou colis) : il est nécessaire pour planifier la tournée.' );
		}
		if ( $packages < 1 || $packages > 999 ) {
			throw new DomainError( 'Indiquez le nombre de colis ou de palettes (1 à 999).' );
		}
		$detail = self::delivery_detail( $ctx, $dlv_no );
		$weight = 0.0;
		if ( $hu ) {
			$weight = $hu['weight'] + $frames * 10 + $lids * 3;
		} else {
			foreach ( $detail['items'] as $i ) {
				$q       = $ctx->simple() ? $i['qty'] : $i['qty_picked'];
				$weight += $q * (float) $i['material']['gross_weight'];
			}
			$weight += $packages * ( in_array( $support, array( 'EUR', 'CP3', 'PERDUE' ), true ) ? 25 : ( 'COLIS' === $support ? 0.5 : ( $weight / max( 1, $packages ) > 200 ? 25 : 0.5 ) ) ) + $frames * 10 + $lids * 3; // tare palette, carton, cadre, couvercle
		}
		$errors  = array();
		if ( $detail['needs']['adr'] && 'ADR' !== $carrier['code'] ) {
			$errors[] = 'Matières dangereuses (ADR) : seul un transporteur ADR est autorisé, avec document de transport et étiquetage.';
		}
		if ( $detail['needs']['cold'] && 'FRIGO' !== $carrier['code'] ) {
			$errors[] = 'Chaîne du froid : cette livraison contient des produits à conserver au froid, utilisez un transport frigorifique.';
		}
		$per_pkg = in_array( (string) ( $carrier['type'] ?? '' ), array( 'colis', 'express' ), true );
		if ( $per_pkg && $weight / max( 1, $packages ) > (float) $carrier['max_weight'] ) {
			$errors[] = sprintf( 'Poids : %s kg par colis dépasse la limite du transporteur %s (%s kg par colis).', Catalog::format_qty( round( $weight / max( 1, $packages ), 1 ) ), $carrier['name'], Catalog::format_qty( (float) $carrier['max_weight'] ) );
		} elseif ( ! $per_pkg && $weight > (float) $carrier['max_weight'] ) {
			$errors[] = sprintf( 'Poids : %s kg dépasse la limite du transporteur %s (%s kg).', Catalog::format_qty( round( $weight, 1 ) ), $carrier['name'], Catalog::format_qty( (float) $carrier['max_weight'] ) );
		}
		if ( $cust && '' !== (string) $cust['pallet_type'] && '' !== $support && 'COLIS' !== $support && $support !== $cust['pallet_type'] ) {
			$errors[] = sprintf( 'Le client %s exige : %s.', $cust['name'], ( Transport::SUPPORTS[ $cust['pallet_type'] ] ?? $cust['pallet_type'] ) );
		}
		if ( ( $frames || $lids ) && ! in_array( $support, array( 'EUR' ), true ) ) {
			$errors[] = 'Les cadres et couvercles consignés s\'utilisent avec des palettes EUR.';
		}
		if ( $tour ) {
			$errors = array_values( array_filter( $errors, static fn( $e ) => ! str_starts_with( $e, 'Matières dangereuses' ) && ! str_starts_with( $e, 'Chaîne du froid' ) ) );
		}
		if ( $errors ) {
			throw new DomainError( 'Emballage refusé.', $errors );
		}
		if ( '' !== $forced && ! $tour ) {
			$svc = TransportPlan::SERVICES[ $forced ] ?? null;
			if ( $svc && $weight / max( 1, $packages ) > $svc['max_pkg'] ) {
				throw new DomainError( 'Emballage refusé.', array( sprintf( '%s kg par colis : la limite du transporteur est de %s kg par colis. Répartissez en plus de colis ou changez de transport (VT11).', Catalog::format_qty( round( $weight / max( 1, $packages ), 1 ) ), Catalog::format_qty( $svc['max_pkg'] ) ) ) );
			}
		}
		Db::update( 'outbound_delivery', array( 'carrier_code' => $carrier['code'], 'packages' => $packages, 'weight_kg' => round( $weight, 3 ), 'pallet_type' => $support, 'frames' => $frames, 'lids' => $lids, 'status' => 'EMBALLEE' ), array( 'id' => (int) $d['id'] ) );
		return array( 'weight' => round( $weight, 1 ), 'carrier' => $carrier['name'], 'forced' => $forced );
	}

	/** Sortie de marchandises (601). */
	public static function goods_issue( Ctx $ctx, string $dlv_no, bool $tour = false ): string {
		global $wpdb;
		$d = self::delivery_by_no( $ctx, $dlv_no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $dlv_no ) );
		}
		if ( 'EXPEDIEE' === $d['status'] ) {
			throw new DomainError( 'Cette livraison est déjà expédiée.' );
		}
		if ( 'EMBALLEE' !== $d['status'] ) {
			throw new DomainError( $ctx->simple() ? 'Emballez d\'abord la livraison et choisissez le transporteur.' : 'Confirmez le prélèvement puis emballez la livraison avant la sortie de marchandises.' );
		}
		if ( ! $tour && '' === (string) $d['carrier_code'] ) {
			throw new DomainError( (int) $d['shipment_id'] ? 'Cette livraison part avec une tournée : la sortie de marchandises se fait au départ du camion (VT02N).' : 'Livraison prévue en tournée : planifiez-la (VT01N), la sortie de marchandises se fera au départ du camion.' );
		}
		$so    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$di    = Db::t( 'outbound_delivery_item' );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$di} WHERE delivery_id = %d", (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lines = array();
		$done  = array();
		if ( $ctx->simple() ) {
			foreach ( (array) $items as $i ) {
				$m     = Catalog::material( (int) $i['material_id'] );
				$alloc = Strategies::allocate( $ctx->id, $m, (float) $i['qty'], $ctx->date() );
				if ( $alloc['missing'] > 0.0005 ) {
					throw new DomainError( sprintf( 'Stock insuffisant pour l\'article %s au moment de la sortie.', $m['material_no'] ) );
				}
				foreach ( $alloc['alloc'] as $a ) {
					$loc = Catalog::location( (int) $a['row']['storage_location_id'] );
					$ctx->require_key_for( $loc );
					$lines[] = array( 'movement_type' => '601', 'material_id' => (int) $m['id'], 'location_id' => (int) $a['row']['storage_location_id'], 'bin_id' => 0, 'batch' => $a['row']['batch'], 'bbd' => $a['row']['bbd'], 'status' => 'LIBRE', 'qty' => -$a['qty'] );
				}
				$done[ (int) $i['id'] ] = (float) $i['qty'];
			}
		} else {
			$ti   = Db::t( 'transfer_order_item' );
			$t    = Db::t( 'transfer_order' );
			$pick = $wpdb->get_results( $wpdb->prepare( "SELECT i.* FROM {$ti} i JOIN {$t} o ON o.id = i.to_id WHERE o.session_id = %d AND o.ref_type = 'DLV' AND o.ref_id = %d AND o.status = 'CONFIRME' AND i.qty_confirmed > 0", $ctx->id, (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $pick as $p ) {
				$lines[] = array( 'movement_type' => '601', 'material_id' => (int) $p['material_id'], 'location_id' => (int) $p['dst_location_id'], 'bin_id' => (int) $p['dst_bin_id'], 'batch' => $p['batch'], 'bbd' => $p['bbd'], 'status' => 'LIBRE', 'qty' => -(float) $p['qty_confirmed'] );
				$done[ (int) $p['ref_item_id'] ] = ( $done[ (int) $p['ref_item_id'] ] ?? 0 ) + (float) $p['qty_confirmed'];
			}
		}
		if ( ! $lines ) {
			throw new DomainError( 'Aucune quantité prélevée : rien à expédier.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $so, $lines, $done, $items, $wpdb, $di ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'DLV', 'ref_id' => (int) $d['id'], 'ref_no' => $d['dlv_no'], 'text' => 'Sortie de marchandises livraison ' . $d['dlv_no'] ), $lines );
				$si  = Db::t( 'sales_order_item' );
				foreach ( (array) $items as $i ) {
					$q = (float) ( $done[ (int) $i['id'] ] ?? 0 );
					$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_delivered = qty_delivered + %f WHERE id = %d", $q, (int) $i['so_item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $ctx->simple() ) {
						$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = GREATEST(0, qty_reserved - %f) WHERE id = %d", $q, (int) $i['so_item_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->update( $di, array( 'qty_picked' => $q ), array( 'id' => (int) $i['id'] ) );
					}
				}
				Db::update( 'outbound_delivery', array( 'status' => 'EXPEDIEE', 'issued_at' => Db::now() ), array( 'id' => (int) $d['id'] ) );
				self::refresh_status( (int) $so['id'] );
				return $doc;
			}
		);
	}

	public static function refresh_status( int $so_id ): void {
		global $wpdb;
		$si  = Db::t( 'sales_order_item' );
		// Poste refusé (motif de refus) : soldé à la quantité déjà livrée.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(IF(reject_reason <> '', LEAST(qty_delivered, qty), qty)) q, SUM(LEAST(qty_delivered, qty)) d, SUM(IF(reject_reason <> '', 1, 0)) rj, COUNT(*) n FROM {$si} WHERE so_id = %d", $so_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $row['n'] && (int) $row['rj'] === (int) $row['n'] && (float) $row['d'] <= 0.0005 ) {
			Db::update( 'sales_order', array( 'status' => 'ANNULEE' ), array( 'id' => $so_id ) );
			return;
		}
		$st  = (float) $row['d'] + 0.0005 >= (float) $row['q'] ? 'LIVREE' : ( (float) $row['d'] > 0 ? 'PARTIELLEMENT_LIVREE' : 'CONFIRMEE' );
		Db::update( 'sales_order', array( 'status' => $st ), array( 'id' => $so_id ) );
	}

	public static function cancel( Ctx $ctx, string $so_no ): void {
		global $wpdb;
		$so = self::by_no( $ctx, $so_no );
		if ( ! $so ) {
			throw new DomainError( 'Commande introuvable.' );
		}
		$d    = Db::t( 'outbound_delivery' );
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE so_id = %d AND status NOT IN ('EXPEDIEE','SUPPRIMEE')", (int) $so['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $open ) {
			throw new DomainError( 'Une livraison est en cours pour cette commande : elle ne peut plus être annulée.' );
		}
		Db::update( 'sales_order', array( 'status' => 'ANNULEE' ), array( 'id' => (int) $so['id'] ) );
		$wpdb->update( Db::t( 'sales_order_item' ), array( 'qty_reserved' => 0 ), array( 'so_id' => (int) $so['id'] ) );
	}
}
