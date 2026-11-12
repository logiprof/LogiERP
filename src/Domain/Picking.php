<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\Dictionaries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Préparation des commandes.
 * VL10 : liste des commandes clients à livrer et création groupée des livraisons, en série
 * (un préparateur par commande) ou en parallèle (un préparateur par secteur, puis regroupement).
 * HU02 : colisage en unités d'expédition (palette EUR, CP3, perdue ou colis), avec numéro SSCC,
 * contrôles de charge, de volume et de compatibilité des marchandises sur une même unité.
 */
final class Picking {

	/** Unités d'expédition : libellé, tare (kg), charge maximale (kg), volume utile (l). */
	public const HU_TYPES = array(
		'EUR'    => array( 'Palette EUR 1200×800', 25.0, 1000.0, 1536.0 ),
		'CP3'    => array( 'Palette chimique CP3 1140×1140', 22.0, 1000.0, 2079.0 ),
		'PERDUE' => array( 'Palette perdue 1200×800', 15.0, 500.0, 1536.0 ),
		'COLIS'  => array( 'Colis carton 600×400×400', 0.5, 25.0, 96.0 ),
	);

	public const MAX_HU = 40;

	/* ─────────────── VL10 : commandes à livrer ─────────────── */

	/** Commandes clients ouvertes avec ce qui reste à livrer, secteurs de prélèvement et charge estimée. */
	public static function due_list( Ctx $ctx, string $until = '' ): array {
		global $wpdb;
		$so  = Db::t( 'sales_order' );
		$si  = Db::t( 'sales_order_item' );
		$d   = Db::t( 'outbound_delivery' );
		$di  = Db::t( 'outbound_delivery_item' );
		$sql = "SELECT * FROM {$so} WHERE session_id = %d AND status IN ('CONFIRMEE','PARTIELLEMENT_LIVREE','EN_PREPARATION')";
		$arg = array( $ctx->id );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			$sql  .= ' AND req_date <= %s';
			$arg[] = $until;
		}
		$sql   .= ' ORDER BY priority, req_date, id LIMIT 100';
		$orders = (array) $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$mine   = array();
		foreach ( $ctx->doc_map()['refs'] ?? array() as $ref => $r ) {
			if ( 'SO' === ( $r['type'] ?? '' ) ) {
				$mine[ (string) $r['no'] ] = (string) $ref;
			}
		}
		$out    = array();
		foreach ( $orders as $o ) {
			$items    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$si} WHERE so_id = %d ORDER BY line_no", (int) $o['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$reserved = array();
			$lines    = array();
			$sectors  = array();
			$weight   = 0.0;
			$volume   = 0.0;
			$missing  = false;
			$delayed  = false;
			$adr      = false;
			$cold     = false;
			foreach ( $items as $i ) {
				if ( '' !== (string) $i['reject_reason'] || (int) $i['delivery_block'] ) {
					continue;
				}
				$in_open = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(x.qty),0) FROM {$di} x JOIN {$d} h ON h.id = x.delivery_id WHERE x.so_item_id = %d AND h.status NOT IN ('EXPEDIEE','SUPPRIMEE')", (int) $i['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$open    = (float) $i['qty'] - (float) $i['qty_delivered'] - $in_open;
				if ( $open <= 0.0005 ) {
					continue;
				}
				$m     = Catalog::material( (int) $i['material_id'] );
				$alloc = Strategies::allocate( $ctx->id, $m, $open, $ctx->date(), array( 'reserved' => $reserved ) );
				$secs  = array();
				foreach ( $alloc['alloc'] as $a ) {
					$reserved[ (int) $a['row']['id'] ] = ( $reserved[ (int) $a['row']['id'] ] ?? 0 ) + $a['qty'];
					$code = (string) $a['row']['location_code'];
					$secs[ $code ] = true;
					if ( ! isset( $sectors[ $code ] ) ) {
						$loc = Catalog::location( (int) $a['row']['storage_location_id'] );
						$sectors[ $code ] = array( 'code' => $code, 'name' => $loc ? $loc['name'] : '', 'lines' => 0 );
					}
				}
				foreach ( array_keys( $secs ) as $code ) {
					$sectors[ $code ]['lines']++;
				}
				$missing = $missing || $alloc['missing'] > 0.0005;
				$adr     = $adr || '' !== (string) $m['adr_class'];
				$cold    = $cold || ( null !== $m['temp_max'] && (float) $m['temp_max'] <= 8 );
				$weight += $open * (float) $m['gross_weight'];
				$volume += $open * self::unit_volume( $m );
				$lines[] = array( 'line_no' => (int) $i['line_no'], 'material' => Api::material_summary( $m ), 'open' => $open, 'missing' => $alloc['missing'], 'sectors' => array_keys( $secs ), 'delayed' => (int) $i['is_delayed'], 'confirmed_date' => $i['confirmed_date'] );
				$delayed = $delayed || (int) $i['is_delayed'];
			}
			if ( ! $lines ) {
				continue;
			}
			$c     = Sales::customer( (int) $o['customer_id'] );
			ksort( $sectors );
			$out[] = array(
				'so_no'    => $o['so_no'],
				'mission'  => isset( $mine[ $o['so_no'] ] ),
				'status'   => $o['status'],
				'priority' => $o['priority'],
				'req_date' => $o['req_date'],
				'customer' => $c ? array( 'no' => $c['customer_no'], 'name' => $c['name'], 'city' => $c['city'], 'pallet' => (string) $c['pallet_type'] ) : null,
				'lines'    => $lines,
				'sectors'  => array_values( $sectors ),
				'weight'   => round( $weight, 1 ),
				'volume'   => round( $volume, 1 ),
				'pallets'  => round( max( $weight / self::HU_TYPES['EUR'][2], $volume / self::HU_TYPES['EUR'][3] ), 2 ),
				'missing'  => $missing,
				'delayed'  => $delayed,
				'internal' => $c && 'Site interne' === $c['type'],
				'reuse'    => self::deleted_numbers( $ctx, (int) $o['id'] ),
				'adr'      => $adr,
				'cold'     => $cold,
			);
		}
		usort( $out, static fn( $a, $b ) => ( (int) $b['mission'] <=> (int) $a['mission'] ) ?: ( strcmp( $a['priority'], $b['priority'] ) ?: strcmp( (string) $a['req_date'], (string) $b['req_date'] ) ) );
		return $out;
	}

	/**
	 * VL10 : crée les livraisons des commandes choisies, toutes avec le même mode de préparation.
	 *
	 * @param string[] $orders numéros de commandes clients
	 */
	/** N° de bulletins de livraisons supprimées de la commande (réutilisables : « n° de bulletin désiré »). */
	private static function deleted_numbers( Ctx $ctx, int $so_id ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT dlv_no FROM ' . Db::t( 'outbound_delivery' ) . " WHERE session_id = %d AND so_id = %d AND status = 'SUPPRIMEE' ORDER BY id", $ctx->id, $so_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function create_deliveries( Ctx $ctx, array $orders, string $mode, string $picker = '', array $reuse = array() ): array {
		$orders = array_values( array_unique( array_filter( array_map( static fn( $o ) => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $o ) ), $orders ) ) ) );
		if ( ! $orders ) {
			throw new DomainError( 'Cochez au moins une commande à livrer.' );
		}
		if ( ! isset( Sales::PICK_MODES[ $mode ] ) ) {
			throw new DomainError( 'Choisissez le mode de préparation : en série ou en parallèle.' );
		}
		if ( count( $orders ) > 30 ) {
			throw new DomainError( 'Trente commandes au maximum à la fois.' );
		}
		$done   = array();
		$errors = array();
		foreach ( $orders as $no ) {
			try {
				$r      = Sales::create_delivery( $ctx, $no, $mode, $picker, (string) ( $reuse[ $no ] ?? '' ) );
				$done[] = array( 'so_no' => $no ) + $r;
			} catch ( DomainError $e ) {
				$errors[] = $no . ' : ' . $e->getMessage() . ( $e->details() ? ' (' . implode( ' ', $e->details() ) . ')' : '' );
			}
		}
		if ( ! $done ) {
			throw new DomainError( 'Aucune livraison créée.', $errors );
		}
		return array( 'created' => $done, 'errors' => $errors );
	}

	/* ─────────────── HU02 : colisage ─────────────── */

	public static function unit_volume( array $m ): float {
		$v = (float) ( $m['volume_l'] ?? 0 );
		if ( $v <= 0 ) {
			$v = (float) $m['length_mm'] * (float) $m['width_mm'] * (float) $m['height_mm'] / 1000000;
		}
		return max( 0.0, $v );
	}

	/** Classe de température : FROID (≤ 8 °C), SURGELE (≤ −15 °C) ou AMBIANT. */
	private static function temp_class( array $m ): string {
		if ( null === $m['temp_max'] || '' === (string) $m['temp_max'] ) {
			return 'AMBIANT';
		}
		return (float) $m['temp_max'] <= -15 ? 'SURGELE' : ( (float) $m['temp_max'] <= 8 ? 'FROID' : 'AMBIANT' );
	}

	private static function delivery( Ctx $ctx, string $dlv_no ): array {
		$d = Sales::delivery_by_no( $ctx, $dlv_no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $dlv_no ) );
		}
		return $d;
	}

	/** Ordres de prélèvement de la livraison, par secteur. */
	public static function pick_orders( Ctx $ctx, int $delivery_id ): array {
		global $wpdb;
		$t = Db::t( 'transfer_order' );
		$i = Db::t( 'transfer_order_item' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.to_no, t.status, t.sector, t.picker, COUNT(i.id) n_lines FROM {$t} t LEFT JOIN {$i} i ON i.to_id = t.id WHERE t.session_id = %d AND t.ref_type = 'DLV' AND t.ref_id = %d AND t.type = 'PICK' GROUP BY t.id ORDER BY t.id", $ctx->id, $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function units_of( int $delivery_id ): array {
		global $wpdb;
		$h = Db::t( 'handling_unit' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$h} WHERE delivery_id = %d ORDER BY id", $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function unit_items( int $hu_id ): array {
		global $wpdb;
		$t = Db::t( 'handling_unit_item' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE hu_id = %d ORDER BY id", $hu_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Quantités colisées par ligne de livraison. */
	private static function packed_by_item( int $delivery_id ): array {
		global $wpdb;
		$h    = Db::t( 'handling_unit' );
		$t    = Db::t( 'handling_unit_item' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT x.delivery_item_id, SUM(x.qty) q FROM {$t} x JOIN {$h} u ON u.id = x.hu_id WHERE u.delivery_id = %d GROUP BY x.delivery_item_id", $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['delivery_item_id'] ] = (float) $r['q'];
		}
		return $out;
	}

	/** Quantité disponible au colisage : prélevée (gestion détaillée) ou livrée (gestion simple). */
	private static function packable_qty( Ctx $ctx, array $item ): float {
		return $ctx->simple() ? (float) $item['qty'] : (float) $item['qty_picked'];
	}

	/** Charge d'une unité : poids brut (tare comprise), volume, contenu. */
	private static function unit_load( array $hu ): array {
		$type   = self::HU_TYPES[ $hu['type'] ] ?? self::HU_TYPES['EUR'];
		$weight = $type[1];
		$volume = 0.0;
		$items  = array();
		foreach ( self::unit_items( (int) $hu['id'] ) as $x ) {
			$m       = Catalog::material( (int) $x['material_id'] );
			$weight += (float) $x['qty'] * (float) $m['gross_weight'];
			$volume += (float) $x['qty'] * self::unit_volume( $m );
			$items[] = array( 'id' => (int) $x['id'], 'material' => Api::material_summary( $m ), 'qty' => (float) $x['qty'], 'm' => $m );
		}
		return array( 'weight' => $weight, 'volume' => $volume, 'items' => $items );
	}

	/** Contrôles d'une unité après ajout de $qty × $m. @return string[] */
	private static function check_unit( array $hu, array $load, array $m, float $qty ): array {
		$type   = self::HU_TYPES[ $hu['type'] ];
		$errors = array();
		$weight = $load['weight'] + $qty * (float) $m['gross_weight'];
		$volume = $load['volume'] + $qty * self::unit_volume( $m );
		if ( $weight > $type[2] + $type[1] + 0.0005 ) {
			$errors[] = sprintf( 'Charge : %s kg sur %s (maximum %s kg de marchandise%s). Ouvrez une nouvelle unité.', Catalog::format_qty( round( $weight - $type[1], 1 ) ), mb_strtolower( $type[0] ), Catalog::format_qty( $type[2] ), 'COLIS' === $hu['type'] ? ', manutention manuelle' : '' );
		}
		if ( $volume > $type[3] + 0.0005 ) {
			$errors[] = sprintf( 'Volume : %s l pour %s l utiles (%s, hauteur de chargement %s). Ouvrez une nouvelle unité.', Catalog::format_qty( round( $volume, 1 ) ), Catalog::format_qty( $type[3] ), mb_strtolower( $type[0] ), 'COLIS' === $hu['type'] ? '400 mm' : '1,60 m' );
		}
		$tc = self::temp_class( $m );
		foreach ( $load['items'] as $x ) {
			$o = $x['m'];
			if ( (int) $o['id'] === (int) $m['id'] ) {
				continue;
			}
			if ( self::temp_class( $o ) !== $tc ) {
				$errors[] = sprintf( 'Température : %s (%s) ne peut pas voyager sur la même unité que %s (%s). Séparez froid, surgelé et ambiant.', $m['material_no'], mb_strtolower( $tc ), $o['material_no'], mb_strtolower( self::temp_class( $o ) ) );
			}
			$a = (string) $m['adr_class'];
			$b = (string) $o['adr_class'];
			if ( '' !== $a && '' !== $b ) {
				foreach ( Dictionaries::incompatible_pairs() as $pair ) {
					if ( ( $pair[0] === $a && $pair[1] === $b ) || ( $pair[1] === $a && $pair[0] === $b ) ) {
						$errors[] = sprintf( 'Chargement en commun interdit (ADR 7.5.2) : classe %s avec classe %s sur la même unité.', $a, $b );
					}
				}
			}
			if ( ( '' !== $a && 'AMBIANT' !== self::temp_class( $o ) ) || ( '' !== $b && 'AMBIANT' !== $tc ) ) {
				$errors[] = 'Matières dangereuses et denrées alimentaires ne se chargent pas sur la même unité (ADR 7.5.4).';
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/** Rend l'état complet du colisage d'une livraison. */
	public static function detail( Ctx $ctx, string $dlv_no ): array {
		global $wpdb;
		$d      = self::delivery( $ctx, $dlv_no );
		$di     = Db::t( 'outbound_delivery_item' );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$di} WHERE delivery_id = %d ORDER BY id", (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$packed = self::packed_by_item( (int) $d['id'] );
		$items  = array();
		$open   = 0.0;
		foreach ( $rows as $r ) {
			$m       = Catalog::material( (int) $r['material_id'] );
			$avail   = self::packable_qty( $ctx, $r );
			$p       = (float) ( $packed[ (int) $r['id'] ] ?? 0 );
			$open   += max( 0, $avail - $p );
			$items[] = array( 'id' => (int) $r['id'], 'material' => Api::material_summary( $m ), 'qty' => (float) $r['qty'], 'picked' => $avail, 'packed' => $p, 'open' => max( 0, round( $avail - $p, 3 ) ), 'weight' => (float) $m['gross_weight'], 'volume' => round( self::unit_volume( $m ), 3 ), 'temp' => self::temp_class( $m ) );
		}
		$units = array();
		foreach ( self::units_of( (int) $d['id'] ) as $u ) {
			$load    = self::unit_load( $u );
			$type    = self::HU_TYPES[ $u['type'] ] ?? self::HU_TYPES['EUR'];
			$units[] = array(
				'hu_no'      => $u['hu_no'],
				'sscc'       => $u['sscc'],
				'type'       => $u['type'],
				'type_label' => $type[0],
				'status'     => $u['status'],
				'weight'     => round( $load['weight'], 1 ),
				'volume'     => round( $load['volume'], 1 ),
				'fill_w'     => (int) round( ( $load['weight'] - $type[1] ) / $type[2] * 100 ),
				'fill_v'     => (int) round( $load['volume'] / $type[3] * 100 ),
				'mixed'      => count( array_unique( array_map( static fn( $x ) => $x['material']['id'], $load['items'] ) ) ) > 1,
				'items'      => array_map( static fn( $x ) => array( 'id' => $x['id'], 'material' => $x['material'], 'qty' => $x['qty'] ), $load['items'] ),
			);
		}
		$tos     = self::pick_orders( $ctx, (int) $d['id'] );
		$waiting = array_values( array_filter( $tos, static fn( $t ) => 'OUVERT' === $t['status'] ) );
		$so      = $wpdb->get_row( $wpdb->prepare( 'SELECT so_no, customer_id FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cust    = $so ? Sales::customer( (int) $so['customer_id'] ) : null;
		$cons    = (int) $d['cons_bin_id'] ? Catalog::bin( (int) $d['cons_bin_id'] ) : null;
		$sender  = Company::delivery_address();
		return array(
			'dlv_no'        => $d['dlv_no'],
			'so_no'         => $so ? $so['so_no'] : '',
			'status'        => $d['status'],
			'mode'          => $d['pick_mode'],
			'mode_label'    => Sales::PICK_MODES[ $d['pick_mode'] ] ?? $d['pick_mode'],
			'consolidation' => $cons ? $cons['code'] : '',
			'locked'        => in_array( $d['status'], array( 'EMBALLEE', 'EXPEDIEE' ), true ) || (int) $d['shipment_id'] > 0,
			'ready'         => $ctx->simple() || ! $waiting,
			'pick_orders'   => $tos,
			'waiting'       => $waiting,
			'items'         => $items,
			'open'          => round( $open, 3 ),
			'units'         => $units,
			'types'         => array_map( static fn( $k, $t ) => array( 'code' => $k, 'label' => $t[0], 'tare' => $t[1], 'max_kg' => $t[2], 'max_l' => $t[3] ), array_keys( self::HU_TYPES ), self::HU_TYPES ),
			'customer'      => $cust ? array( 'no' => $cust['customer_no'], 'name' => $cust['name'], 'street' => $cust['street'], 'city' => $cust['zip'] . ' ' . $cust['city'], 'pallet' => (string) $cust['pallet_type'], 'pallet_label' => Transport::SUPPORTS[ $cust['pallet_type'] ] ?? 'Indifférent' ) : null,
			'sender'        => array( 'name' => $sender[0], 'street' => $sender[1], 'city' => $sender[2] ),
		);
	}

	private static function unit_by_no( Ctx $ctx, array $d, string $hu_no ): array {
		global $wpdb;
		$h = Db::t( 'handling_unit' );
		$u = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$h} WHERE session_id = %d AND delivery_id = %d AND (hu_no = %s OR sscc = %s)", $ctx->id, (int) $d['id'], $hu_no, $hu_no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $u ) {
			throw new DomainError( sprintf( 'Unité d\'expédition %s introuvable pour la livraison %s.', $hu_no, $d['dlv_no'] ) );
		}
		return $u;
	}

	private static function editable( array $d ): void {
		if ( in_array( $d['status'], array( 'EMBALLEE', 'EXPEDIEE' ), true ) || (int) $d['shipment_id'] ) {
			throw new DomainError( 'La livraison est déjà emballée ou expédiée : le colisage ne peut plus être modifié.' );
		}
	}

	/** SSCC des unités expédiées : extension 3, préfixe entreprise 761 + 54321, référence série, clé. */
	private static function sscc( Ctx $ctx, int $serial ): string {
		$body = '3' . '761' . '54321' . str_pad( (string) ( ( $ctx->id * 10000 + $serial ) % 100000000 ), 8, '0', STR_PAD_LEFT );
		return $body . Gs1::check_digit( $body );
	}

	/** Ouvre une nouvelle unité d'expédition (palette ou colis) pour la livraison. */
	public static function create_unit( Ctx $ctx, string $dlv_no, string $type ): array {
		global $wpdb;
		$d    = self::delivery( $ctx, $dlv_no );
		$type = strtoupper( $type );
		self::editable( $d );
		if ( ! isset( self::HU_TYPES[ $type ] ) ) {
			throw new DomainError( 'Choisissez le type d\'unité : palette EUR, palette CP3, palette perdue ou colis.' );
		}
		if ( count( self::units_of( (int) $d['id'] ) ) >= self::MAX_HU ) {
			throw new DomainError( sprintf( 'Au maximum %d unités par livraison.', self::MAX_HU ) );
		}
		$so   = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cust = $so ? Sales::customer( (int) $so['customer_id'] ) : null;
		$req  = $cust ? (string) $cust['pallet_type'] : '';
		if ( '' !== $req && 'COLIS' !== $type && $type !== $req ) {
			throw new DomainError( sprintf( 'Le client %s exige : %s.', $cust['name'], ( Transport::SUPPORTS[ $req ] ?? $req ) ) );
		}
		$h      = Db::t( 'handling_unit' );
		$serial = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$h} WHERE session_id = %d", $ctx->id ) ) + 1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$no     = '1' . str_pad( (string) $serial, 9, '0', STR_PAD_LEFT );
		Db::insert( 'handling_unit', array( 'session_id' => $ctx->id, 'hu_no' => $no, 'sscc' => self::sscc( $ctx, $serial ), 'delivery_id' => (int) $d['id'], 'type' => $type, 'status' => 'OUVERTE', 'created_at' => Db::now() ) );
		return array( 'hu_no' => $no, 'message' => sprintf( '%s ouverte : unité %s.', self::HU_TYPES[ $type ][0], $no ) );
	}

	/**
	 * Place une quantité d'un article de la livraison sur une unité ouverte.
	 * $code : n° d'article ou EAN scanné.
	 */
	public static function add( Ctx $ctx, string $dlv_no, string $hu_no, string $code, float $qty ): array {
		global $wpdb;
		$d = self::delivery( $ctx, $dlv_no );
		self::editable( $d );
		if ( ! $ctx->simple() ) {
			$waiting = array_values( array_filter( self::pick_orders( $ctx, (int) $d['id'] ), static fn( $t ) => 'OUVERT' === $t['status'] ) );
			if ( $waiting ) {
				throw new DomainError(
					'Regroupement incomplet : la marchandise de tous les secteurs n\'est pas encore arrivée.',
					array_map( static fn( $t ) => sprintf( 'En attente : %s%s (OT %s).', $t['picker'] ?: 'prélèvement', '' !== (string) $t['sector'] ? ', secteur ' . $t['sector'] : '', $t['to_no'] ), $waiting )
				);
			}
		}
		$u = self::unit_by_no( $ctx, $d, $hu_no );
		if ( 'OUVERTE' !== $u['status'] ) {
			throw new DomainError( sprintf( 'L\'unité %s est fermée. Rouvrez-la ou ouvrez une nouvelle unité.', $u['hu_no'] ) );
		}
		$code = strtoupper( trim( $code ) );
		$di   = Db::t( 'outbound_delivery_item' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$di} WHERE delivery_id = %d ORDER BY id", (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$m    = null;
		$line = null;
		foreach ( $rows as $r ) {
			$mm = Catalog::material( (int) $r['material_id'] );
			if ( $code === strtoupper( $mm['material_no'] ) || ( '' !== $mm['ean'] && ( $code === $mm['ean'] || ltrim( $code, '0' ) === ltrim( $mm['ean'], '0' ) ) ) ) {
				$m    = $mm;
				$line = $r;
				break;
			}
		}
		if ( ! $line ) {
			throw new DomainError( sprintf( 'L\'article %s ne fait pas partie de la livraison %s.', sanitize_text_field( $code ), $d['dlv_no'] ) );
		}
		$packed = self::packed_by_item( (int) $d['id'] );
		$avail  = self::packable_qty( $ctx, $line ) - (float) ( $packed[ (int) $line['id'] ] ?? 0 );
		$qty    = round( $qty, 3 );
		if ( $qty <= 0 ) {
			throw new DomainError( 'Indiquez une quantité positive.' );
		}
		if ( $avail <= 0.0005 ) {
			throw new DomainError( sprintf( 'Article %s : toute la quantité prélevée est déjà colisée.', $m['material_no'] ) );
		}
		if ( $qty > $avail + 0.0005 ) {
			throw new DomainError( sprintf( 'Article %s : il reste %s %s à coliser, pas %s.', $m['material_no'], Catalog::format_qty( $avail ), $m['base_uom'], Catalog::format_qty( $qty ) ) );
		}
		$load   = self::unit_load( $u );
		$errors = self::check_unit( $u, $load, $m, $qty );
		if ( $errors ) {
			throw new DomainError( sprintf( 'Impossible de placer %s sur l\'unité %s.', $m['material_no'], $u['hu_no'] ), $errors );
		}
		$t        = Db::t( 'handling_unit_item' );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE hu_id = %d AND delivery_item_id = %d", (int) $u['id'], (int) $line['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET qty = qty + %f WHERE id = %d", $qty, $existing ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			Db::insert( 'handling_unit_item', array( 'hu_id' => (int) $u['id'], 'delivery_item_id' => (int) $line['id'], 'material_id' => (int) $m['id'], 'qty' => $qty ) );
		}
		$left = $avail - $qty;
		return array( 'message' => sprintf( '%s %s %s placés sur l\'unité %s.%s', Catalog::format_qty( $qty ), $m['base_uom'], $m['material_no'], $u['hu_no'], $left > 0.0005 ? sprintf( ' Reste %s à coliser.', Catalog::format_qty( $left ) ) : '' ) );
	}

	/** Retire une ligne d'une unité ouverte. */
	public static function remove( Ctx $ctx, string $dlv_no, string $hu_no, int $item_id ): array {
		global $wpdb;
		$d = self::delivery( $ctx, $dlv_no );
		self::editable( $d );
		$u = self::unit_by_no( $ctx, $d, $hu_no );
		if ( 'OUVERTE' !== $u['status'] ) {
			throw new DomainError( 'Rouvrez d\'abord l\'unité pour modifier son contenu.' );
		}
		$n = $wpdb->delete( Db::t( 'handling_unit_item' ), array( 'id' => $item_id, 'hu_id' => (int) $u['id'] ) );
		if ( ! $n ) {
			throw new DomainError( 'Ligne introuvable sur cette unité.' );
		}
		return array( 'message' => sprintf( 'Ligne retirée de l\'unité %s.', $u['hu_no'] ) );
	}

	/** Ferme (filmée, étiquetée), rouvre ou supprime une unité. */
	public static function set_status( Ctx $ctx, string $dlv_no, string $hu_no, string $act ): array {
		global $wpdb;
		$d = self::delivery( $ctx, $dlv_no );
		self::editable( $d );
		$u = self::unit_by_no( $ctx, $d, $hu_no );
		if ( 'close' === $act ) {
			if ( ! self::unit_items( (int) $u['id'] ) ) {
				throw new DomainError( 'Unité vide : placez d\'abord des articles, ou supprimez-la.' );
			}
			Db::update( 'handling_unit', array( 'status' => 'FERMEE', 'closed_at' => Db::now() ), array( 'id' => (int) $u['id'] ) );
			return array( 'message' => sprintf( 'Unité %s fermée : filmez-la et collez l\'étiquette SSCC %s.', $u['hu_no'], $u['sscc'] ) );
		}
		if ( 'reopen' === $act ) {
			Db::update( 'handling_unit', array( 'status' => 'OUVERTE', 'closed_at' => null ), array( 'id' => (int) $u['id'] ) );
			return array( 'message' => sprintf( 'Unité %s rouverte.', $u['hu_no'] ) );
		}
		$wpdb->delete( Db::t( 'handling_unit_item' ), array( 'hu_id' => (int) $u['id'] ) );
		$wpdb->delete( Db::t( 'handling_unit' ), array( 'id' => (int) $u['id'] ) );
		return array( 'message' => sprintf( 'Unité %s supprimée : son contenu est à nouveau à coliser.', $u['hu_no'] ) );
	}

	/**
	 * Pour l'emballage (VL02N) : contrôle le colisage et calcule supports, nombre d'unités et poids.
	 * Retourne null si la livraison n'est pas colisée (et que ce n'est pas obligatoire).
	 */
	public static function for_packing( Ctx $ctx, array $d ): ?array {
		$units = self::units_of( (int) $d['id'] );
		if ( ! $units ) {
			if ( 'PARALLELE' === $d['pick_mode'] ) {
				throw new DomainError( 'Préparation en parallèle : regroupez les articles des différents secteurs et faites le colisage (HU02) avant d\'emballer.' );
			}
			return null;
		}
		$errors = array();
		$weight = 0.0;
		$types  = array();
		foreach ( $units as $u ) {
			if ( 'FERMEE' !== $u['status'] ) {
				$errors[] = sprintf( 'Unité %s encore ouverte : fermez-la (HU02).', $u['hu_no'] );
			}
			$weight            += self::unit_load( $u )['weight'];
			$types[ $u['type'] ] = ( $types[ $u['type'] ] ?? 0 ) + 1;
		}
		$det = self::detail( $ctx, $d['dlv_no'] );
		foreach ( $det['items'] as $i ) {
			if ( $i['open'] > 0.0005 ) {
				$errors[] = sprintf( 'Article %s : %s %s prélevés mais pas colisés.', $i['material']['no'], Catalog::format_qty( $i['open'] ), $i['material']['uom'] );
			}
		}
		if ( $errors ) {
			throw new DomainError( 'Colisage incomplet.', $errors );
		}
		$pallets = array_diff_key( $types, array( 'COLIS' => 1 ) );
		arsort( $pallets );
		return array(
			'packages' => count( $units ),
			'support'  => $pallets ? (string) array_key_first( $pallets ) : 'COLIS',
			'weight'   => $weight,
		);
	}
}
