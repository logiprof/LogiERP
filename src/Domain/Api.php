<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sérialisation des objets métier et requêtes de lecture pour l'interface (toujours bornées et paginées).
 */
final class Api {

	public static function valid_date( string $d ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return false;
		}
		[ $y, $m, $day ] = array_map( 'intval', explode( '-', $d ) );
		return checkdate( $m, $day, $y );
	}

	public static function fr_date( string $d ): string {
		return self::valid_date( substr( $d, 0, 10 ) ) ? gmdate( 'd.m.Y', strtotime( substr( $d, 0, 10 ) ) ) : $d;
	}

	public static function material_summary( ?array $m ): ?array {
		if ( ! $m ) {
			return null;
		}
		return array(
			'id'            => (int) $m['id'],
			'no'            => $m['material_no'],
			'designation'   => $m['designation'],
			'uom'           => $m['base_uom'],
			'type'          => $m['type'],
			'profile'       => $m['profile_code'],
			'profile_label' => Catalog::profile_label( $m['profile_code'] ),
			'batch_managed' => (int) $m['batch_managed'],
			'shelf_life'    => (int) $m['shelf_life_days'],
			'gross_weight'  => (float) $m['gross_weight'],
			'adr'           => (string) $m['adr_class'],
			'temp'          => null !== $m['temp_min'] ? Catalog::format_qty( (float) $m['temp_min'] ) . ' / ' . Catalog::format_qty( (float) $m['temp_max'] ) . ' °C' : '',
			'hv'            => (int) $m['high_value'],
			'fragile'       => (int) $m['fragile'],
			'ean'           => $m['ean'],
			'bulk'          => (int) ( $m['bulk'] ?? 0 ),
			'cert'          => (int) ( $m['cert_required'] ?? 0 ),
			'mtype'         => (string) $m['type'],
					);
				}

	public static function material_full( Ctx $ctx, array $m ): array {
		global $wpdb;
		$g      = Db::t( 'material_group' );
		$group  = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$g} WHERE id = %d", (int) $m['group_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sm     = Db::t( 'supplier_material' );
		$s      = Db::t( 'supplier' );
		$supps  = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.supplier_no, s.name, sm.price, sm.min_qty, sm.lead_time_days, sm.is_main, sm.supplier_ref FROM {$sm} sm JOIN {$s} s ON s.id = sm.supplier_id WHERE sm.material_id = %d ORDER BY sm.is_main DESC, s.name", (int) $m['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fixed  = array();
		foreach ( Catalog::fixed_bins_of( (int) $m['id'] ) as $bid ) {
			$b       = Catalog::bin( $bid );
			$fixed[] = array( 'code' => $b['code'], 'min' => (float) $b['min_qty'], 'max' => (float) $b['max_qty'] );
		}
		$allowed = array();
		foreach ( Catalog::locations() as $l ) {
			if ( empty( $l['is_transit'] ) && ! Rules::check_location( $m, $l ) ) {
				$allowed[] = $l['code'] . ' – ' . $l['name'];
			}
		}
		$bom = Production::bom( (int) $m['id'] );
		$components = array();
		foreach ( $bom['items'] ?? array() as $bi ) {
			$c            = Catalog::material( (int) $bi['component_id'] );
			$components[] = array( 'material' => self::material_summary( $c ), 'qty' => (float) $bi['qty'], 'scrap_pct' => (float) $bi['scrap_pct'] );
		}
		$total = self::stock_totals( $ctx, (int) $m['id'] );
		return self::material_summary( $m ) + array(
			'group'          => (string) $group,
			'net_weight'     => (float) $m['net_weight'],
			'dimensions'     => sprintf( '%d × %d × %d mm', (int) $m['length_mm'], (int) $m['width_mm'], (int) $m['height_mm'] ),
			'volume_l'       => (float) $m['volume_l'],
			'std_price'      => (float) $m['std_price'],
			'sale_price'     => (float) $m['sale_price'],
			'abc'            => $m['abc'],
			'xyz'            => $m['xyz'],
			'serial_managed' => (int) $m['serial_managed'],
			'min_stock'      => (float) $m['min_stock'],
			'max_stock'      => (float) $m['max_stock'],
			'reorder_point'  => (float) $m['reorder_point'],
			'lot_size'       => (float) $m['lot_size'],
			'lead_time'      => (int) $m['lead_time_days'],
			'un'             => $m['un_number'],
			'packing_group'  => $m['packing_group'],
			'hazard_text'    => $m['hazard_text'],
			'stackable'      => (int) $m['stackable'],
			'units_per_carton'   => (int) $m['units_per_carton'],
			'cartons_per_pallet' => (int) $m['cartons_per_pallet'],
			'suppliers'      => (array) $supps,
			'fixed_bins'     => $fixed,
			'allowed_locations' => $allowed,
			'bom'            => $components,
			'stock'          => $total,
		);
	}

	public static function bin_summary( array $b ): array {
		$l = Catalog::location( (int) $b['storage_location_id'] );
		return array(
			'id'         => (int) $b['id'],
			'code'       => $b['code'],
			'location'   => $l ? $l['code'] : '',
			'location_name' => $l ? $l['name'] : '',
			'type'       => $b['bin_type'],
			'level'      => $b['level'],
			'max_weight' => (float) $b['max_weight'],
			'max_volume' => (float) $b['max_volume_l'],
			'length'     => (int) $b['length_mm'],
			'fixed'      => (int) $b['fixed_material_id'],
		);
	}

	public static function stock_totals( Ctx $ctx, int $material_id ): array {
		global $wpdb;
		$s    = Db::t( 'stock' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, SUM(qty) q FROM {$s} WHERE session_id = %d AND material_id = %d GROUP BY status", $ctx->id, $material_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array( 'LIBRE' => 0.0, 'CONTROLE_QUALITE' => 0.0, 'BLOQUE' => 0.0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (float) $r['q'];
		}
		$out['disponible'] = StockService::available( $ctx->id, $material_id );
		return $out;
	}

	public static function search_materials( string $q, int $limit = 20 ): array {
		global $wpdb;
		$t    = Db::t( 'material' );
		$q    = trim( $q );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND deleted_at IS NULL AND (material_no LIKE %s OR designation LIKE %s OR ean = %s) ORDER BY material_no LIMIT %d", Catalog::dataset(), $like, $like, $q, min( 50, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'material_summary' ), (array) $rows );
	}

	public static function customer_by_no( string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'customer' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND customer_no = %s", Catalog::dataset(), $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function search_partners( string $type, string $q, int $limit = 20 ): array {
		global $wpdb;
		$t    = Db::t( 'supplier' === $type ? 'supplier' : 'customer' );
		$no   = 'supplier' === $type ? 'supplier_no' : 'customer_no';
		$like = '%' . $wpdb->esc_like( trim( $q ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND ({$no} LIKE %s OR name LIKE %s OR city LIKE %s) ORDER BY {$no} LIMIT %d", Catalog::dataset(), $like, $like, $like, min( 50, max( 1, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'      => (int) $r['id'],
				'no'      => $r[ $no ],
				'name'    => $r['name'],
				'city'    => $r['zip'] . ' ' . $r['city'],
				'country' => $r['country'],
				'extra'   => 'supplier' === $type ? $r['specialty'] : $r['type'],
				'blocked' => (int) ( $r['blocked'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function partner_full( string $type, int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'supplier' === $type ? 'supplier' : 'customer' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $r ) {
			return null;
		}
		unset( $r['dataset_id'] );
		if ( 'customer' === $type ) {
			$r['rules'] = Transport::constraints( $r );
		}
		return $r;
	}

	/**
	 * Liste de stock filtrée (MMBE, MB52, LX03).
	 *
	 * @param array $f material, location, bin, status, batch, expiring (jours), page
	 */
	public static function stock_list( Ctx $ctx, array $f ): array {
		global $wpdb;
		$s     = Db::t( 'stock' );
		$m     = Db::t( 'material' );
		$l     = Db::t( 'storage_location' );
		$b     = Db::t( 'bin' );
		$where = array( 's.session_id = %d', 's.qty > 0' );
		$args  = array( $ctx->id );
		if ( ! empty( $f['material'] ) ) {
			$where[] = '(m.material_no = %s OR m.designation LIKE %s)';
			$args[]  = strtoupper( (string) $f['material'] );
			$args[]  = '%' . $wpdb->esc_like( (string) $f['material'] ) . '%';
		}
		if ( ! empty( $f['location'] ) ) {
			$where[] = 'l.code = %s';
			$args[]  = (string) $f['location'];
		}
		if ( ! empty( $f['bin'] ) ) {
			$where[] = 'b.code LIKE %s';
			$args[]  = $wpdb->esc_like( strtoupper( (string) $f['bin'] ) ) . '%';
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], array( 'LIBRE', 'CONTROLE_QUALITE', 'BLOQUE' ), true ) ) {
			$where[] = 's.status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['expiring'] ) ) {
			$where[] = 's.bbd IS NOT NULL AND s.bbd <= %s';
			$args[]  = gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . (int) $f['expiring'] . ' days' ) );
		}
		$page  = max( 1, (int) ( $f['page'] ?? 1 ) );
		$per   = 50;
		$sqlw  = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} s JOIN {$m} m ON m.id = s.material_id JOIN {$l} l ON l.id = s.storage_location_id LEFT JOIN {$b} b ON b.id = s.bin_id WHERE {$sqlw}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$args2 = array_merge( $args, array( $per, ( $page - 1 ) * $per ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.qty, s.status, s.batch, s.bbd, s.gr_date, m.id material_id, m.material_no, m.designation, m.base_uom, m.std_price, l.code location, b.code bin FROM {$s} s JOIN {$m} m ON m.id = s.material_id JOIN {$l} l ON l.id = s.storage_location_id LEFT JOIN {$b} b ON b.id = s.bin_id WHERE {$sqlw} ORDER BY m.material_no, l.code, b.pick_sequence LIMIT %d OFFSET %d", $args2 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'material_id' => (int) $r['material_id'],
				'material'    => $r['material_no'],
				'designation' => $r['designation'],
				'uom'         => $r['base_uom'],
				'location'    => $r['location'],
				'bin'         => (string) $r['bin'],
				'status'      => $r['status'],
				'batch'       => $r['batch'],
				'bbd'         => $r['bbd'],
				'expired'     => $r['bbd'] && $r['bbd'] < $ctx->date() ? 1 : 0,
				'gr_date'     => $r['gr_date'],
				'qty'         => (float) $r['qty'],
				'value'       => round( (float) $r['qty'] * (float) $r['std_price'], 2 ),
			);
		}
		return array( 'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / $per ) );
	}

	/** Liste des emplacements d'un magasin avec occupation (LX03 / plan). */
	public static function bins( Ctx $ctx, string $location_code, string $filter = '', int $page = 1 ): array {
		global $wpdb;
		$loc = Catalog::location_by_code( $location_code );
		if ( ! $loc ) {
			throw new DomainError( 'Magasin inconnu.' );
		}
		$b    = Db::t( 'bin' );
		$s    = Db::t( 'stock' );
		$m    = Db::t( 'material' );
		$st   = Db::t( 'session_bin_state' );
		$per  = 100;
		$page = max( 1, $page );
		$having = '';
		if ( 'empty' === $filter ) {
			$having = 'HAVING COUNT(s.id) = 0';
		} elseif ( 'occupied' === $filter ) {
			$having = 'HAVING COUNT(s.id) > 0';
		} elseif ( 'blocked' === $filter ) {
			$having = 'HAVING blocked_in > 0 OR blocked_out > 0';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.code, b.bin_type, b.level, b.max_weight, b.max_volume_l, b.fixed_material_id, b.pick_sequence, GREATEST(b.blocked_in, COALESCE(x.blocked_in,0)) blocked_in, GREATEST(b.blocked_out, COALESCE(x.blocked_out,0)) blocked_out, COALESCE(NULLIF(x.reason,''), b.block_reason) reason, COUNT(s.id) n, COALESCE(SUM(s.qty * m.gross_weight),0) w, COALESCE(SUM(s.qty * m.volume_l),0) v, GROUP_CONCAT(DISTINCT m.material_no ORDER BY m.material_no SEPARATOR ', ') mats FROM {$b} b LEFT JOIN {$st} x ON x.bin_id = b.id AND x.session_id = %d LEFT JOIN {$s} s ON s.bin_id = b.id AND s.session_id = %d AND s.qty > 0 LEFT JOIN {$m} m ON m.id = s.material_id WHERE b.storage_location_id = %d GROUP BY b.id {$having} ORDER BY b.pick_sequence LIMIT %d OFFSET %d", $ctx->id, $ctx->id, (int) $loc['id'], $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$fixed = (int) $r['fixed_material_id'] ? Catalog::material( (int) $r['fixed_material_id'] ) : null;
			$fill  = max( (float) $r['w'] / max( 1, (float) $r['max_weight'] ), (float) $r['v'] / max( 1, (float) $r['max_volume_l'] ) );
			$out[] = array(
				'code'     => $r['code'],
				'type'     => $r['bin_type'],
				'fixed'    => $fixed ? $fixed['material_no'] : '',
				'materials' => (string) $r['mats'],
				'fill'     => round( min( 1.5, $fill ) * 100 ),
				'weight'   => round( (float) $r['w'], 1 ),
				'max_weight' => (float) $r['max_weight'],
				'blocked'  => ( (int) $r['blocked_in'] ? 'E' : '' ) . ( (int) $r['blocked_out'] ? 'S' : '' ),
				'reason'   => (string) $r['reason'],
			);
		}
		return array( 'location' => self::location_summary( $loc ), 'bins' => $out, 'page' => $page );
	}

	public static function location_summary( array $l ): array {
		return array(
			'id'       => (int) $l['id'],
			'code'     => $l['code'],
			'name'     => $l['name'],
			'zone'     => $l['zone_type'],
			'strategy' => $l['strategy'],
			'removal'  => $l['removal'],
			'profiles' => $l['profiles'],
			'adr'      => $l['adr_classes'],
			'temp'     => null !== $l['temp_min'] ? Catalog::format_qty( (float) $l['temp_min'] ) . ' / ' . Catalog::format_qty( (float) $l['temp_max'] ) . ' °C' : '',
			'secured'  => (int) $l['secured'],
			'transit'  => (int) $l['is_transit'],
			'rack'     => $l['rack_type'],
			'notes'    => $l['notes'],
			'map'      => array( (int) $l['map_x'], (int) $l['map_y'], (int) $l['map_w'], (int) $l['map_h'] ),
		);
	}

	/** Plan de l'entrepôt : magasins + taux de remplissage de la session. */
	public static function map( Ctx $ctx ): array {
		global $wpdb;
		$b    = Db::t( 'bin' );
		$s    = Db::t( 'stock' );
		$st   = Db::t( 'session_bin_state' );
		$tot  = $wpdb->get_results( "SELECT storage_location_id, COUNT(*) n, SUM(blocked_in OR blocked_out) blocked FROM {$b} GROUP BY storage_location_id", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$used = $wpdb->get_results( $wpdb->prepare( "SELECT storage_location_id, COUNT(DISTINCT bin_id) n, SUM(qty) q FROM {$s} WHERE session_id = %d AND qty > 0 GROUP BY storage_location_id", $ctx->id ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sblk = $wpdb->get_results( $wpdb->prepare( "SELECT b.storage_location_id, COUNT(*) n FROM {$st} x JOIN {$b} b ON b.id = x.bin_id WHERE x.session_id = %d AND (x.blocked_in = 1 OR x.blocked_out = 1) GROUP BY b.storage_location_id", $ctx->id ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( Catalog::locations() as $l ) {
			$id    = (int) $l['id'];
			$n     = isset( $tot[ $id ] ) ? (int) $tot[ $id ]->n : 0;
			$u     = isset( $used[ $id ] ) ? (int) $used[ $id ]->n : 0;
			$out[] = self::location_summary( $l ) + array(
				'bins'    => $n,
				'used'    => $ctx->simple() ? ( $u ? $n : 0 ) : $u,
				'fill'    => $n ? round( 100 * ( $ctx->simple() ? ( $u ? 1 : 0 ) : $u / $n ) ) : 0,
				'qty'     => isset( $used[ $id ] ) ? (float) $used[ $id ]->q : 0,
				'blocked' => ( isset( $tot[ $id ] ) ? (int) $tot[ $id ]->blocked : 0 ) + ( isset( $sblk[ $id ] ) ? (int) $sblk[ $id ]->n : 0 ),
			);
		}
		return $out;
	}

	/** Liste des documents de mouvement (MB51). */
	public static function mdocs( Ctx $ctx, array $f ): array {
		global $wpdb;
		$d     = Db::t( 'material_doc' );
		$i     = Db::t( 'material_doc_item' );
		$m     = Db::t( 'material' );
		$l     = Db::t( 'storage_location' );
		$b     = Db::t( 'bin' );
		$where = array( 'd.session_id = %d' );
		$args  = array( $ctx->id );
		if ( ! empty( $f['material'] ) ) {
			$where[] = 'm.material_no = %s';
			$args[]  = strtoupper( (string) $f['material'] );
		}
		if ( ! empty( $f['movement'] ) && preg_match( '/^\d{3}$/', (string) $f['movement'] ) ) {
			$where[] = 'i.movement_type = %s';
			$args[]  = (string) $f['movement'];
		}
		if ( ! empty( $f['batch'] ) ) {
			$where[] = 'i.batch = %s';
			$args[]  = strtoupper( (string) $f['batch'] );
		}
		if ( ! empty( $f['from'] ) && self::valid_date( (string) $f['from'] ) ) {
			$where[] = 'd.posting_date >= %s';
			$args[]  = (string) $f['from'];
		}
		if ( ! empty( $f['to'] ) && self::valid_date( (string) $f['to'] ) ) {
			$where[] = 'd.posting_date <= %s';
			$args[]  = (string) $f['to'];
		}
		$page  = max( 1, (int) ( $f['page'] ?? 1 ) );
		$args[] = 100;
		$args[] = ( $page - 1 ) * 100;
		$sqlw  = implode( ' AND ', $where );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT d.doc_no, d.posting_date, d.ref_type, d.ref_no, d.header_text, d.tcode, d.rev_of, d.created_at, i.line_no, i.movement_type, i.qty, i.value, i.batch, i.stock_status, i.reason, i.reversed, i.special_ref, m.material_no, m.designation, m.base_uom, l.code location, b.code bin FROM {$i} i JOIN {$d} d ON d.id = i.mdoc_id JOIN {$m} m ON m.id = i.material_id JOIN {$l} l ON l.id = i.storage_location_id LEFT JOIN {$b} b ON b.id = i.bin_id WHERE {$sqlw} ORDER BY d.id DESC, i.line_no LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['movement_label'] = StockService::MOVEMENTS[ $r['movement_type'] ] ?? '';
			$r['qty']            = (float) $r['qty'];
			$r['value']          = (float) $r['value'];
		}
		return array( 'rows' => (array) $rows, 'page' => $page );
	}

	/** Documents ouverts (pour les listes de choix). */
	public static function documents( Ctx $ctx, string $type, string $status = '' ): array {
		global $wpdb;
		switch ( $type ) {
			case 'po':
				$t = Db::t( 'purchase_order' );
				$s = Db::t( 'supplier' );
				$sql = "SELECT o.po_no no, o.status, o.order_date date, o.note, o.unannounced flag, s.name partner FROM {$t} o JOIN {$s} s ON s.id = o.supplier_id WHERE o.session_id = %d";
				$open = "'ENVOYEE','PARTIELLEMENT_RECUE'";
				break;
			case 'so':
				$t = Db::t( 'sales_order' );
				$s = Db::t( 'customer' );
				$sql = "SELECT o.so_no no, o.status, o.req_date date, o.note, o.priority flag, s.name partner FROM {$t} o JOIN {$s} s ON s.id = o.customer_id WHERE o.session_id = %d";
				$open = "'CONFIRMEE','EN_PREPARATION','PARTIELLEMENT_LIVREE'";
				break;
			case 'dlv':
				$t = Db::t( 'outbound_delivery' );
				$o = Db::t( 'sales_order' );
				$s = Db::t( 'customer' );
				$sql = "SELECT d.dlv_no no, d.status, DATE(d.created_at) date, o.so_no note, d.carrier_code flag, s.name partner FROM {$t} d JOIN {$o} o ON o.id = d.so_id JOIN {$s} s ON s.id = o.customer_id WHERE d.session_id = %d";
				$open = "'EN_PREPARATION','PREPAREE','EMBALLEE'";
				break;
			case 'prod':
				$t = Db::t( 'production_order' );
				$m = Db::t( 'material' );
				$sql = "SELECT p.prod_no no, p.status, p.start_date date, m.designation note, p.qty flag, m.material_no partner FROM {$t} p JOIN {$m} m ON m.id = p.material_id WHERE p.session_id = %d";
				$open = "'CREE','LANCE','PARTIELLEMENT_CONFIRME'";
				break;
			case 'inv':
				$t = Db::t( 'inventory_doc' );
				$sql = "SELECT inv_no no, status, DATE(created_at) date, scope note, '' flag, '' partner FROM {$t} WHERE session_id = %d";
				$open = "'OUVERT'";
				break;
			case 'ret':
				$t = Db::t( 'return_doc' );
				$sql = "SELECT ret_no no, status, DATE(created_at) date, ref_no note, type flag, '' partner FROM {$t} WHERE session_id = %d";
				$open = "'RECU'";
				break;
			default:
				return array();
		}
		if ( 'open' === $status ) {
			$alias = array( 'po' => 'o.', 'so' => 'o.', 'dlv' => 'd.', 'prod' => 'p.', 'inv' => '', 'ret' => '' )[ $type ];
			$sql  .= " AND {$alias}status IN ({$open})";
		}
		$sql .= ' ORDER BY 1 DESC LIMIT 200';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Articles sous le point de commande (besoins d'achat). */
	public static function reorder_list( Ctx $ctx ): array {
		global $wpdb;
		$m  = Db::t( 'material' );
		$s  = Db::t( 'stock' );
		$po = Db::t( 'purchase_order' );
		$pi = Db::t( 'purchase_order_item' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.id, m.material_no, m.designation, m.base_uom, m.reorder_point, m.lot_size, m.main_supplier_id, COALESCE(st.q,0) stock, COALESCE(op.q,0) open_po FROM {$m} m LEFT JOIN (SELECT material_id, SUM(qty) q FROM {$s} WHERE session_id = %d AND status = 'LIBRE' GROUP BY material_id) st ON st.material_id = m.id LEFT JOIN (SELECT i.material_id, SUM(GREATEST(i.qty - i.qty_received,0)) q FROM {$pi} i JOIN {$po} o ON o.id = i.po_id WHERE o.session_id = %d AND o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') AND i.final_delivery = 0 AND i.deleted = 0 GROUP BY i.material_id) op ON op.material_id = m.id WHERE m.dataset_id = %d AND m.deleted_at IS NULL HAVING stock + open_po < m.reorder_point ORDER BY m.material_no LIMIT 200", $ctx->id, $ctx->id, Catalog::dataset() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$sup   = Purchasing::supplier( (int) $r['main_supplier_id'] );
			$out[] = array(
				'material_id' => (int) $r['id'],
				'material'    => $r['material_no'],
				'designation' => $r['designation'],
				'uom'         => $r['base_uom'],
				'stock'       => (float) $r['stock'],
				'open_po'     => (float) $r['open_po'],
				'reorder'     => (float) $r['reorder_point'],
				'lot_size'    => (float) $r['lot_size'],
				'available'   => StockService::available( $ctx->id, (int) $r['id'] ),
				'supplier'    => $sup ? array( 'id' => (int) $sup['id'], 'no' => $sup['supplier_no'], 'name' => $sup['name'] ) : null,
			);
		}
		return $out;
	}

	public static function carriers(): array {
		global $wpdb;
		$t = Db::t( 'carrier' );
		return (array) $wpdb->get_results( "SELECT code, name, type, max_weight FROM {$t} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
