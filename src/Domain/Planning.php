<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Planification des besoins : liste stock/besoins (MD04), calcul des besoins (MD01 / MD02)
 * et contrôle de disponibilité des composants d'un ordre de fabrication (stock + commandes en cours).
 */
final class Planning {

	/** Délai de fabrication (jours) entre le début de l'ordre et la disponibilité du produit fini. */
	public const PROD_DAYS = 1;

	/** Stock libre utilisable (hors zones de transit), toutes réservations confondues. */
	public static function free_stock( Ctx $ctx, int $material_id ): float {
		global $wpdb;
		$s = Db::t( 'stock' );
		$l = Db::t( 'storage_location' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(s.qty),0) FROM {$s} s JOIN {$l} l ON l.id = s.storage_location_id WHERE s.session_id = %d AND s.material_id = %d AND s.status = 'LIBRE' AND (l.is_transit = 0 OR l.code = '100')", $ctx->id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Éléments de la liste stock/besoins d'un article : entrées (commandes d'achat, ordres de fabrication)
	 * et sorties (commandes clients, besoins de composants), triés par date.
	 */
	public static function elements( Ctx $ctx, int $material_id ): array {
		global $wpdb;
		$out = array();
		$po  = Db::t( 'purchase_order' );
		$poi = Db::t( 'purchase_order_item' );
		$s   = Db::t( 'supplier' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.po_no, i.delivery_date, i.qty - i.qty_received open, s.name FROM {$poi} i JOIN {$po} o ON o.id = i.po_id JOIN {$s} s ON s.id = o.supplier_id WHERE o.session_id = %d AND i.material_id = %d AND o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE') AND i.qty > i.qty_received AND i.final_delivery = 0 AND i.deleted = 0", $ctx->id, $material_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array( 'date' => max( $ctx->date(), (string) ( $r['delivery_date'] ?: $ctx->date() ) ), 'type' => 'CA', 'label' => 'Commande d\'achat', 'no' => $r['po_no'], 'partner' => $r['name'], 'qty' => (float) $r['open'] );
		}
		$pr = Db::t( 'production_order' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT prod_no, start_date, qty - qty_done - qty_scrap open, status FROM {$pr} WHERE session_id = %d AND material_id = %d AND status IN ('CREE','LANCE','PARTIELLEMENT_CONFIRME') AND qty > qty_done + qty_scrap", $ctx->id, $material_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array( 'date' => max( $ctx->date(), gmdate( 'Y-m-d', strtotime( $r['start_date'] . ' +' . self::PROD_DAYS . ' days' ) ) ), 'type' => 'OF', 'label' => 'Ordre de fabrication', 'no' => $r['prod_no'], 'partner' => '', 'qty' => (float) $r['open'] );
		}
		$so  = Db::t( 'sales_order' );
		$soi = Db::t( 'sales_order_item' );
		$c   = Db::t( 'customer' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.so_no, o.req_date, i.line_no, i.confirmed_date, i.is_delayed, i.qty - i.qty_delivered open, c.name FROM {$soi} i JOIN {$so} o ON o.id = i.so_id JOIN {$c} c ON c.id = o.customer_id WHERE o.session_id = %d AND i.material_id = %d AND o.status IN ('CONFIRMEE','EN_PREPARATION','PARTIELLEMENT_LIVREE') AND i.qty > i.qty_delivered AND i.reject_reason = ''", $ctx->id, $material_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Stock client E : la quantité réservée physiquement couvre déjà ce besoin.
			$e    = StockTx::e_stock( $ctx, StockTx::so_ref( (string) $r['so_no'], (int) $r['line_no'] ), $material_id );
			$open = max( 0.0, (float) $r['open'] - $e );
			if ( $e > 0.0005 ) {
				$out[] = array( 'date' => max( $ctx->date(), (string) $r['req_date'] ), 'type' => 'E', 'label' => 'Stock client E (poste ' . (int) $r['line_no'] . ')', 'no' => $r['so_no'], 'partner' => $r['name'], 'qty' => 0.0, 'info' => $e );
			}
			if ( $open > 0.0005 ) {
				$out[] = array( 'date' => max( $ctx->date(), (string) $r['req_date'] ), 'type' => 'CC', 'label' => 'Commande client' . ( (int) $r['is_delayed'] ? ' (cochée : confirmée au ' . Api::fr_date( (string) $r['confirmed_date'] ) . ')' : '' ), 'no' => $r['so_no'], 'partner' => $r['name'], 'qty' => -$open );
			}
		}
		$pc = Db::t( 'production_component' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.prod_no, p.start_date, c.qty_required - c.qty_issued open FROM {$pc} c JOIN {$pr} p ON p.id = c.prod_id WHERE p.session_id = %d AND c.material_id = %d AND p.status IN ('CREE','LANCE','PARTIELLEMENT_CONFIRME') AND c.qty_required > c.qty_issued", $ctx->id, $material_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array( 'date' => max( $ctx->date(), (string) $r['start_date'] ), 'type' => 'BC', 'label' => 'Besoin de composant', 'no' => $r['prod_no'], 'partner' => '', 'qty' => -(float) $r['open'] );
		}
		usort( $out, static fn( $a, $b ) => array( $a['date'], $a['qty'] < 0 ? 1 : 0 ) <=> array( $b['date'], $b['qty'] < 0 ? 1 : 0 ) );
		return $out;
	}

	/** Liste stock/besoins (MD04) : disponible cumulé et messages d'exception. */
	public static function md04( Ctx $ctx, int $material_id ): array {
		$m = Catalog::material( $material_id );
		if ( ! $m ) {
			throw new DomainError( 'Article introuvable.' );
		}
		$stock = self::free_stock( $ctx, $material_id );
		$rows  = array( array( 'date' => $ctx->date(), 'type' => 'ST', 'label' => 'Stock libre', 'no' => '', 'partner' => '', 'qty' => $stock, 'avail' => $stock, 'exception' => '' ) );
		$run   = $stock;
		$first = null;
		foreach ( self::elements( $ctx, $material_id ) as $e ) {
			$run += $e['qty'];
			$exc  = '';
			if ( $run < -0.0005 && null === $first ) {
				$first = $e['date'];
				$exc   = 'Rupture : disponible négatif';
			}
			$rows[] = $e + array( 'avail' => round( $run, 3 ), 'exception' => $exc );
		}
		$info = self::supply_info( $m );
		return array(
			'material'  => Api::material_summary( $m ),
			'reorder'   => (float) $m['reorder_point'],
			'lot_size'  => (float) $m['lot_size'],
			'procure'   => $info['type'],
			'lead_time' => $info['lead'],
			'supplier'  => $info['supplier'],
			'rows'      => $rows,
			'shortage'  => $first,
			'final'     => round( $run, 3 ),
		);
	}

	/** Mode d'approvisionnement : fabrication (nomenclature) ou achat (fournisseur principal). */
	public static function supply_info( array $m ): array {
		$bom = Production::bom( (int) $m['id'] );
		if ( $bom ) {
			return array( 'type' => 'F', 'lead' => self::PROD_DAYS, 'supplier' => null, 'min' => 1 );
		}
		$info = (int) $m['main_supplier_id'] ? Purchasing::info_record( (int) $m['main_supplier_id'], (int) $m['id'] ) : null;
		$sup  = (int) $m['main_supplier_id'] ? Purchasing::supplier( (int) $m['main_supplier_id'] ) : null;
		return array(
			'type'     => 'E',
			'lead'     => $info ? max( 1, (int) $info['lead_time_days'] ) : max( 1, (int) ( $m['lead_time_days'] ?? 5 ) ),
			'supplier' => $sup ? array( 'id' => (int) $sup['id'], 'no' => $sup['supplier_no'], 'name' => $sup['name'] ) : null,
			'min'      => $info ? (float) $info['min_qty'] : 1,
		);
	}

	/**
	 * Calcul des besoins nets (MD01 : tous les articles concernés ; MD02 : un article et ses composants).
	 * Les propositions ne sont pas enregistrées : l'apprenant les convertit lui-même (ME21N, CO01).
	 */
	public static function mrp( Ctx $ctx, int $material_id = 0 ): array {
		global $wpdb;
		$ids = array();
		if ( $material_id ) {
			$ids[] = $material_id;
		} else {
			$soi = Db::t( 'sales_order_item' );
			$so  = Db::t( 'sales_order' );
			$pc  = Db::t( 'production_component' );
			$pr  = Db::t( 'production_order' );
			$ids = array_map( 'intval', array_merge(
				(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.material_id FROM {$soi} i JOIN {$so} o ON o.id = i.so_id WHERE o.session_id = %d AND o.status IN ('CONFIRMEE','EN_PREPARATION','PARTIELLEMENT_LIVREE')", $ctx->id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT c.material_id FROM {$pc} c JOIN {$pr} p ON p.id = c.prod_id WHERE p.session_id = %d AND p.status IN ('CREE','LANCE','PARTIELLEMENT_CONFIRME')", $ctx->id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			) );
		}
		// Explosion des nomenclatures (3 niveaux au plus) : un composant est planifié après son parent.
		$order = array();
		$queue = array_values( array_unique( $ids ) );
		$depth = 0;
		while ( $queue && $depth < 4 ) {
			$next = array();
			foreach ( $queue as $id ) {
				unset( $order[ $id ] );
				$order[ $id ] = true;
				$bom = Production::bom( $id );
				foreach ( $bom ? $bom['items'] : array() as $bi ) {
					$next[] = (int) $bi['component_id'];
				}
			}
			$queue = array_values( array_unique( $next ) );
			++$depth;
		}
		$dependent = array(); // material_id => [[date, qty, from]]
		$out       = array();
		foreach ( array_keys( $order ) as $id ) {
			$m = Catalog::material( $id );
			if ( ! $m ) {
				continue;
			}
			$el = self::elements( $ctx, $id );
			foreach ( $dependent[ $id ] ?? array() as $d ) {
				$el[] = array( 'date' => $d[0], 'type' => 'BP', 'label' => 'Besoin d\'un ordre planifié', 'no' => $d[2], 'partner' => '', 'qty' => -$d[1] );
			}
			usort( $el, static fn( $a, $b ) => array( $a['date'], $a['qty'] < 0 ? 1 : 0 ) <=> array( $b['date'], $b['qty'] < 0 ? 1 : 0 ) );
			$info = self::supply_info( $m );
			$run  = self::free_stock( $ctx, $id );
			foreach ( $el as $e ) {
				$run += $e['qty'];
				if ( $run >= -0.0005 ) {
					continue;
				}
				$short = -$run;
				$qty   = max( $short, (float) $info['min'] );
				if ( 'PCE' === $m['base_uom'] || 'F' === $info['type'] ) {
					$qty = ceil( $qty );
				}
				$need   = $e['date'];
				$start  = gmdate( 'Y-m-d', strtotime( $need . ' -' . $info['lead'] . ' days' ) );
				$late   = $start < $ctx->date();
				$avail  = $late ? gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . $info['lead'] . ' days' ) ) : $need;
				$out[]  = array(
					'material'   => Api::material_summary( $m ),
					'type'       => $info['type'],
					'type_label' => 'F' === $info['type'] ? 'Ordre planifié (fabrication)' : 'Demande d\'achat',
					'need_date'  => $need,
					'need_ref'   => $e['label'] . ' ' . $e['no'],
					'shortage'   => round( $short, 3 ),
					'qty'        => round( $qty, 3 ),
					'start'      => $late ? $ctx->date() : $start,
					'avail'      => $avail,
					'lead'       => $info['lead'],
					'supplier'   => $info['supplier'],
					'exception'  => $late ? sprintf( 'Date de début dépassée : disponible au plus tôt le %s (délai %d j)', Api::fr_date( $avail ), $info['lead'] ) : '',
				);
				$run += $qty;
				if ( 'F' === $info['type'] ) {
					$bom = Production::bom( $id );
					foreach ( $bom ? $bom['items'] : array() as $bi ) {
						$dependent[ (int) $bi['component_id'] ][] = array( $late ? $ctx->date() : $start, round( $qty * (float) $bi['qty'] * ( 1 + (float) $bi['scrap_pct'] / 100 ), 3 ), 'pour ' . $m['material_no'] );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Contrôle de disponibilité des composants d'un ordre (date de début) : stock libre non réservé,
	 * puis réceptions attendues (commandes d'achat) jusqu'à la date de début.
	 */
	public static function availability( Ctx $ctx, int $material_id, float $qty, string $start ): array {
		$bom = Production::bom( $material_id );
		$out = array();
		foreach ( $bom ? $bom['items'] : array() as $bi ) {
			$c    = Catalog::material( (int) $bi['component_id'] );
			$need = round( $qty * (float) $bi['qty'] * ( 1 + (float) $bi['scrap_pct'] / 100 ), 3 );
			if ( $c && 'PCE' === $c['base_uom'] ) {
				$need = ceil( $need );
			}
			$now      = StockService::available( $ctx->id, (int) $bi['component_id'] );
			$receipts = array_filter( self::elements( $ctx, (int) $bi['component_id'] ), static fn( $e ) => $e['qty'] > 0 );
			$cum      = $now;
			$date     = $now + 0.0005 >= $need ? $ctx->date() : null;
			$by_start = $now;
			foreach ( $receipts as $r ) {
				$cum += $r['qty'];
				if ( $r['date'] <= $start ) {
					$by_start += $r['qty'];
				}
				if ( null === $date && $cum + 0.0005 >= $need ) {
					$date = $r['date'];
				}
			}
			$out[] = array(
				'material'  => Api::material_summary( $c ),
				'need'      => $need,
				'now'       => round( $now, 3 ),
				'by_start'  => round( $by_start, 3 ),
				'date'      => $date,
				'status'    => $now + 0.0005 >= $need ? 'DISPO' : ( null !== $date && $date <= $start ? 'PREVU' : ( null !== $date ? 'RETARD' : 'MANQUANT' ) ),
			);
		}
		return $out;
	}
}
