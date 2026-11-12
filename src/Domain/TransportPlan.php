<?php
/**
 * Planification des transports du jour (lot 6) : livraisons à planifier (VL06T), plan du jour (VT11),
 * création groupée (VT04), enlèvements par transporteur, remise, papiers de transport (VT70) et
 * impression groupée des bulletins de livraison (VL71).
 *
 * Deux sortes de transport cohabitent :
 *  - TOURNEE : un camion fait des arrêts chez les clients (flotte de l'entreprise ou camion affrété) ;
 *  - ENLEVEMENT : un transporteur vient chercher les envois au quai et les livre par son réseau
 *    (colis, express, groupage), sans itinéraire à calculer chez nous.
 *
 * @package LogiERP
 */

declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TransportPlan {

	/**
	 * Services des transporteurs qui enlèvent au quai (données fictives) :
	 * mode, tarif (par colis ou par 100 kg avec minimum), heure d'enlèvement et limites.
	 */
	public const SERVICES = array(
		'POST'  => array( 'mode' => 'COLIS', 'label' => 'Colis, livraison le lendemain', 'per' => 'colis', 'price' => 9.80, 'min' => 0.0, 'pickup' => '16:30', 'max_pkg' => 30.0, 'max_total' => 600.0, 'pallets' => false, 'adr' => false, 'cold' => false ),
		'RAPID' => array( 'mode' => 'EXPRESS', 'label' => 'Express, livraison avant 9 h le lendemain', 'per' => 'colis', 'price' => 34.00, 'min' => 0.0, 'pickup' => '17:30', 'max_pkg' => 70.0, 'max_total' => 350.0, 'pallets' => false, 'adr' => false, 'cold' => false ),
		'LEMAN' => array( 'mode' => 'GROUPAGE', 'label' => 'Groupage régional, livraison le lendemain', 'per' => '100kg', 'price' => 14.50, 'min' => 48.0, 'pickup' => '15:00', 'max_pkg' => 1200.0, 'max_total' => 12000.0, 'pallets' => true, 'adr' => false, 'cold' => false ),
		'HFRET' => array( 'mode' => 'GROUPAGE', 'label' => 'Groupage national, livraison le lendemain', 'per' => '100kg', 'price' => 12.80, 'min' => 55.0, 'pickup' => '14:30', 'max_pkg' => 1200.0, 'max_total' => 24000.0, 'pallets' => true, 'adr' => false, 'cold' => false ),
		'FRIGO' => array( 'mode' => 'GROUPAGE', 'label' => 'Groupage frigorifique 2–8 °C, livraison le lendemain', 'per' => '100kg', 'price' => 19.50, 'min' => 65.0, 'pickup' => '14:00', 'max_pkg' => 1000.0, 'max_total' => 8000.0, 'pallets' => true, 'adr' => false, 'cold' => true ),
		'ADR'   => array( 'mode' => 'GROUPAGE', 'label' => 'Groupage marchandises dangereuses (ADR), livraison le lendemain', 'per' => '100kg', 'price' => 23.00, 'min' => 85.0, 'pickup' => '13:30', 'max_pkg' => 1000.0, 'max_total' => 10000.0, 'pallets' => true, 'adr' => true, 'cold' => false ),
	);

	public const MODES = array( 'COLIS' => 'Colis', 'EXPRESS' => 'Express', 'GROUPAGE' => 'Groupage' );

	/* ───────────────────────── Libellés ───────────────────────── */

	public static function carrier_name( string $code ): string {
		global $wpdb;
		$n = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Db::t( 'carrier' ) . ' WHERE code = %s', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $n ? $n : $code;
	}

	/** Moyen de transport d'un transport (ligne de la table shipment). */
	public static function means_label( array $s ): string {
		if ( 'ENLEVEMENT' === ( $s['shp_type'] ?? 'TOURNEE' ) ) {
			$svc = self::SERVICES[ $s['carrier_code'] ] ?? null;
			return self::carrier_name( (string) $s['carrier_code'] ) . ( $svc ? ' – ' . self::MODES[ $svc['mode'] ] : '' );
		}
		$v = Transport::vehicle( (int) $s['vehicle_id'] );
		return $v ? $v['name'] : '';
	}

	/** Moyens de transport disponibles : véhicules (tournées) et services d'enlèvement. */
	public static function means( Ctx $ctx, string $date ): array {
		global $wpdb;
		$veh = array();
		foreach ( Transport::vehicles() as $v ) {
			$tours = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT shp_no, depart_time, return_time FROM ' . Db::t( 'shipment' ) . " WHERE session_id = %d AND vehicle_id = %d AND ship_date = %s AND status IN ('PLANIFIE','EN_ROUTE','TERMINE') ORDER BY depart_time", $ctx->id, $v['id'], $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$v['busy'] = array_map( static fn( $t ) => $t['shp_no'] . ' ' . $t['depart_time'] . '–' . $t['return_time'], $tours );
			$veh[]     = $v;
		}
		$svc = array();
		foreach ( self::SERVICES as $code => $s ) {
			$svc[] = array( 'code' => $code, 'name' => self::carrier_name( $code ), 'mode' => $s['mode'], 'mode_label' => self::MODES[ $s['mode'] ], 'label' => $s['label'], 'pickup' => $s['pickup'], 'tariff' => self::tariff_label( $s ), 'max_pkg' => $s['max_pkg'], 'max_total' => $s['max_total'], 'pallets' => $s['pallets'] ? 1 : 0, 'adr' => $s['adr'] ? 1 : 0, 'cold' => $s['cold'] ? 1 : 0 );
		}
		return array( 'vehicles' => $veh, 'services' => $svc );
	}

	private static function tariff_label( array $s ): string {
		return 'colis' === $s['per'] ? sprintf( 'CHF %s par colis', number_format( $s['price'], 2, '.', '' ) ) : sprintf( 'CHF %s par 100 kg (minimum CHF %s par envoi)', number_format( $s['price'], 2, '.', '' ), number_format( $s['min'], 2, '.', '' ) );
	}

	private static function service_cost( array $s, array $d ): float {
		if ( 'colis' === $s['per'] ) {
			return round( $s['price'] * max( 1, (int) $d['packages'] ), 2 );
		}
		return round( max( $s['min'], $s['price'] * ceil( (float) $d['weight'] / 100 ) ), 2 );
	}

	/* ───────────────────────── VL06T : livraisons à planifier ───────────────────────── */

	/**
	 * Livraisons non expédiées dont la date de livraison souhaitée tombe au plus tard le $to.
	 *
	 * @param string $scope OPEN = pas encore affectées, ALL = toutes
	 */
	public static function deliveries( Ctx $ctx, string $to, string $scope = 'OPEN' ): array {
		global $wpdb;
		if ( ! Api::valid_date( $to ) ) {
			throw new DomainError( 'Date invalide.' );
		}
		$d    = Db::t( 'outbound_delivery' );
		$o    = Db::t( 'sales_order' );
		$sh   = Db::t( 'shipment' );
		$cond = 'OPEN' === $scope ? 'AND d.shipment_id = 0' : '';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.dlv_no, s.shp_no FROM {$d} d JOIN {$o} o ON o.id = d.so_id LEFT JOIN {$sh} s ON s.id = d.shipment_id WHERE d.session_id = %d AND d.status IN ('A_PREPARER','EN_PREPARATION','PREPAREE','EMBALLEE') AND o.req_date <= %s {$cond} ORDER BY o.req_date, o.priority, d.id LIMIT 120", $ctx->id, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$i            = Transport::delivery_info( $ctx, (string) $r['dlv_no'] );
			$c            = Sales::customer( (int) $i['customer']['id'] );
			$i['default'] = (string) ( $c['carrier_code'] ?? '' );
			$i['shp_no']  = (string) ( $r['shp_no'] ?? '' );
			$i['late']    = $i['req_date'] < $ctx->date() ? 1 : 0;
			$out[]        = $i;
		}
		return $out;
	}

	/* ───────────────────────── VT11 : plan du jour ───────────────────────── */

	public static function board( Ctx $ctx, string $date ): array {
		global $wpdb;
		if ( ! Api::valid_date( $date ) ) {
			throw new DomainError( 'Date invalide.' );
		}
		$list = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'shipment' ) . " WHERE session_id = %d AND ship_date = %s ORDER BY shp_type DESC, depart_time, id", $ctx->id, $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ships = array();
		foreach ( $list as $s ) {
			$ships[] = self::summary( $ctx, $s );
		}
		$open = self::deliveries( $ctx, self::next_workday( $date ), 'OPEN' );
		return array( 'date' => $date, 'weekday' => Transport::DAYS[ (int) gmdate( 'N', strtotime( $date ) ) ], 'until' => self::next_workday( $date ), 'open' => $open, 'shipments' => $ships, 'means' => self::means( $ctx, $date ) );
	}

	/** Le jour ouvrable suivant : les transporteurs livrent le lendemain des envois enlevés aujourd'hui. */
	public static function next_workday( string $date ): string {
		$t = strtotime( $date . ' +1 day' );
		while ( (int) gmdate( 'N', $t ) > 5 ) {
			$t = strtotime( '+1 day', $t );
		}
		return gmdate( 'Y-m-d', $t );
	}

	/** Résumé d'un transport : moyen, livraisons, chargement, coût, contrôles. */
	public static function summary( Ctx $ctx, array $s ): array {
		global $wpdb;
		$stops = Transport::stops( (int) $s['id'] );
		$dl    = array();
		$w     = 0.0;
		$pal   = 0;
		$pkg   = 0;
		$est   = 0;
		foreach ( $stops as $st ) {
			$no = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT dlv_no FROM ' . Db::t( 'outbound_delivery' ) . ' WHERE id = %d', (int) $st['delivery_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( '' === $no ) {
				continue;
			}
			$i     = Transport::delivery_info( $ctx, $no );
			$w    += $i['weight'];
			$pal  += $i['pallets'];
			$pkg  += $i['packages'];
			$est  += $i['estimated'];
			$dl[]  = array( 'no' => $i['no'], 'seq' => (int) $st['seq'], 'eta' => $st['eta'], 'customer' => $i['customer'], 'status' => $i['status'], 'weight' => $i['weight'], 'pallets' => $i['pallets'], 'packages' => $i['packages'], 'support' => $i['support'], 'estimated' => $i['estimated'], 'adr' => $i['adr'], 'cold' => $i['cold'], 'window' => $i['rules']['window'] );
		}
		$type = $s['shp_type'] ?? 'TOURNEE';
		$cap  = array( 'weight' => 0.0, 'places' => 0 );
		$v    = null;
		if ( 'TOURNEE' === $type ) {
			$v   = Transport::vehicle( (int) $s['vehicle_id'] );
			$cap = array( 'weight' => $v ? (float) $v['payload_kg'] : 0.0, 'places' => $v ? (int) $v['pallet_places'] : 0 );
		} else {
			$svc = self::SERVICES[ $s['carrier_code'] ] ?? null;
			$cap = array( 'weight' => $svc ? (float) $svc['max_total'] : 0.0, 'places' => 0 );
		}
		$ready = $dl && ! array_filter( $dl, static fn( $x ) => 'EMBALLEE' !== $x['status'] && 'EXPEDIEE' !== $x['status'] );
		return array(
			'no'        => $s['shp_no'],
			'type'      => $type,
			'type_label' => 'TOURNEE' === $type ? 'Tournée' : 'Enlèvement',
			'status'    => $s['status'],
			'means'     => self::means_label( $s ),
			'carrier'   => (string) ( $s['carrier_code'] ?? '' ),
			'owner'     => $v ? $v['owner'] : 'EXTERNE',
			'vehicle_id' => (int) $s['vehicle_id'],
			'date'      => $s['ship_date'],
			'time'      => 'TOURNEE' === $type ? $s['depart_time'] . ' → ' . $s['return_time'] : 'enlèvement ' . ( $s['pickup_time'] ?? '' ),
			'km'        => (float) $s['distance_km'],
			'cost'      => (float) $s['cost'],
			'weight'    => round( $w, 1 ),
			'pallets'   => $pal,
			'packages'  => $pkg,
			'estimated' => $est,
			'capacity'  => $cap,
			'load_pct'  => $cap['weight'] > 0 ? (int) round( $w / $cap['weight'] * 100 ) : 0,
			'ready'     => $ready ? 1 : 0,
			'deliveries' => $dl,
			'outputs'   => count( StockTx::outputs_of( $ctx, 'SHP', (string) $s['shp_no'] ) ),
		);
	}

	/* ───────────────────────── Affectation ───────────────────────── */

	/** Contrôle d'une livraison pour un service d'enlèvement ; renvoie la liste des non-conformités. */
	public static function service_issues( array $d, string $code ): array {
		$s = self::SERVICES[ $code ] ?? null;
		if ( ! $s ) {
			return array( 'Transporteur inconnu.' );
		}
		$name = self::carrier_name( $code );
		$out  = array();
		$who  = sprintf( 'Livraison %s (%s)', $d['no'], $d['customer']['name'] );
		if ( $d['adr'] && ! $s['adr'] ) {
			$out[] = $who . ' : marchandises dangereuses, seul un transport ADR est autorisé (document de transport, étiquetage, chauffeur formé).';
		}
		if ( $d['cold'] && ! $s['cold'] ) {
			$out[] = $who . ' : chaîne du froid, transport frigorifique obligatoire.';
		}
		if ( $d['pallets'] && ! $s['pallets'] ) {
			$out[] = sprintf( '%s : %s ne transporte pas de palettes (%d palette%s).', $who, $name, $d['pallets'], $d['pallets'] > 1 ? 's' : '' );
		}
		$per = (float) $d['weight'] / max( 1, (int) $d['packages'] );
		if ( $per > $s['max_pkg'] ) {
			$out[] = sprintf( '%s : %s kg par colis, limite de %s : %s kg.', $who, Catalog::format_qty( round( $per, 1 ) ), $name, Catalog::format_qty( $s['max_pkg'] ) );
		}
		if ( (float) $d['weight'] > $s['max_total'] ) {
			$out[] = sprintf( '%s : %s kg, au-delà de la limite d\'un envoi chez %s (%s kg).', $who, Catalog::format_qty( round( (float) $d['weight'] ) ), $name, Catalog::format_qty( $s['max_total'] ) );
		}
		return $out;
	}

	/** Ordres d'arrêts candidats : ordre donné, plus proche voisin, plage de livraison la plus tôt. */
	private static function orders( Ctx $ctx, array $nos ): array {
		$info = array();
		foreach ( $nos as $n ) {
			$info[ $n ] = Transport::delivery_info( $ctx, $n );
		}
		$pos  = Transport::DEPOT;
		$left = $nos;
		$nn   = array();
		while ( $left ) {
			$best = null;
			$bd   = PHP_FLOAT_MAX;
			foreach ( $left as $n ) {
				$r  = $info[ $n ]['rules'];
				$to = null !== $r['lat'] ? array( $r['lat'], $r['lon'] ) : Transport::DEPOT;
				$dd = ( $to[0] - $pos[0] ) ** 2 + ( ( $to[1] - $pos[1] ) * 0.69 ) ** 2;
				if ( $dd < $bd ) {
					$bd   = $dd;
					$best = $n;
				}
			}
			$nn[]  = $best;
			$left  = array_values( array_diff( $left, array( $best ) ) );
			$r     = $info[ $best ]['rules'];
			$pos   = null !== $r['lat'] ? array( $r['lat'], $r['lon'] ) : Transport::DEPOT;
		}
		$win = $nos;
		usort(
			$win,
			static function ( $a, $b ) use ( $info ) {
				$ta = '' !== $info[ $a ]['rules']['to'] ? $info[ $a ]['rules']['to'] : '99:99';
				$tb = '' !== $info[ $b ]['rules']['to'] ? $info[ $b ]['rules']['to'] : '99:99';
				return strcmp( $ta, $tb );
			}
		);
		$cands = array( $nos, $nn, $win );
		$uniq  = array();
		foreach ( $cands as $c ) {
			$uniq[ implode( '|', $c ) ] = $c;
		}
		return array_values( $uniq );
	}

	/** Meilleur plan d'une tournée : le premier ordre sans non-conformité, sinon celui qui en a le moins. */
	public static function best_plan( Ctx $ctx, int $vehicle_id, string $date, string $depart, array $nos, int $exclude = 0 ): array {
		$best = null;
		foreach ( self::orders( $ctx, $nos ) as $order ) {
			$p = Transport::simulate( $ctx, $vehicle_id, $date, $depart, $order, $exclude );
			if ( ! $p['issues'] ) {
				return $p;
			}
			if ( null === $best || count( $p['issues'] ) < count( $best['issues'] ) ) {
				$best = $p;
			}
		}
		return $best;
	}

	/** Réécrit les arrêts d'une tournée planifiée à partir d'un plan calculé. */
	private static function store_tour( Ctx $ctx, int $sid, array $plan ): void {
		global $wpdb;
		$wpdb->delete( Db::t( 'shipment_stop' ), array( 'shipment_id' => $sid ) );
		foreach ( $plan['stops'] as $st ) {
			Db::insert( 'shipment_stop', array( 'shipment_id' => $sid, 'seq' => $st['seq'], 'delivery_id' => $st['delivery']['id'], 'customer_id' => $st['delivery']['customer']['id'], 'km' => $st['km'], 'eta' => $st['eta'], 'etd' => $st['etd'], 'wait_min' => $st['wait'] ) );
			Db::update( 'outbound_delivery', array( 'shipment_id' => $sid, 'carrier_code' => '' ), array( 'id' => $st['delivery']['id'] ) );
		}
		Db::update( 'shipment', array( 'distance_km' => $plan['km'], 'duration_min' => $plan['minutes'], 'cost' => $plan['cost'], 'depart_time' => $plan['depart'], 'return_time' => $plan['return'] ), array( 'id' => $sid ) );
	}

	/** Réécrit un enlèvement : arrêts dans l'ordre, coût du tarif. */
	private static function store_pickup( Ctx $ctx, int $sid, string $code, array $nos ): void {
		global $wpdb;
		$wpdb->delete( Db::t( 'shipment_stop' ), array( 'shipment_id' => $sid ) );
		$cost = 0.0;
		$svc  = self::SERVICES[ $code ];
		foreach ( array_values( $nos ) as $k => $n ) {
			$d     = Transport::delivery_info( $ctx, $n );
			$cost += self::service_cost( $svc, $d );
			Db::insert( 'shipment_stop', array( 'shipment_id' => $sid, 'seq' => $k + 1, 'delivery_id' => $d['id'], 'customer_id' => $d['customer']['id'], 'km' => 0, 'eta' => '', 'etd' => '', 'wait_min' => 0 ) );
			Db::update( 'outbound_delivery', array( 'shipment_id' => $sid, 'carrier_code' => $code ), array( 'id' => $d['id'] ) );
		}
		Db::update( 'shipment', array( 'cost' => round( $cost, 2 ) ), array( 'id' => $sid ) );
	}

	private static function shipment_nos( Ctx $ctx, int $sid ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT d.dlv_no FROM ' . Db::t( 'shipment_stop' ) . ' x JOIN ' . Db::t( 'outbound_delivery' ) . ' d ON d.id = x.delivery_id WHERE x.shipment_id = %d ORDER BY x.seq', $sid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function planned( Ctx $ctx, string $no ): array {
		$s = Transport::by_no( $ctx, $no );
		if ( ! $s ) {
			throw new DomainError( sprintf( 'Transport %s introuvable.', $no ) );
		}
		if ( 'PLANIFIE' !== $s['status'] ) {
			throw new DomainError( sprintf( 'Le transport %s est déjà parti : il ne se modifie plus.', $no ) );
		}
		return $s;
	}

	/** Retire une livraison de son transport (avant le départ). */
	private static function detach( Ctx $ctx, string $dlv_no ): void {
		$d = Sales::delivery_by_no( $ctx, $dlv_no );
		if ( ! $d || ! (int) $d['shipment_id'] ) {
			return;
		}
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'shipment' ) . ' WHERE id = %d AND session_id = %d', (int) $d['shipment_id'], $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $s ) {
			Db::update( 'outbound_delivery', array( 'shipment_id' => 0 ), array( 'id' => (int) $d['id'] ) );
			return;
		}
		if ( 'PLANIFIE' !== $s['status'] ) {
			throw new DomainError( sprintf( 'La livraison %s est partie avec le transport %s.', $dlv_no, $s['shp_no'] ) );
		}
		$rest = array_values( array_diff( self::shipment_nos( $ctx, (int) $s['id'] ), array( $dlv_no ) ) );
		Db::update( 'outbound_delivery', array( 'shipment_id' => 0 ), array( 'id' => (int) $d['id'] ) );
		if ( ! $rest ) {
			$wpdb->delete( Db::t( 'shipment_stop' ), array( 'shipment_id' => (int) $s['id'] ) );
			$wpdb->delete( Db::t( 'shipment' ), array( 'id' => (int) $s['id'] ) );
			return;
		}
		if ( 'TOURNEE' === $s['shp_type'] ) {
			$plan = Transport::simulate( $ctx, (int) $s['vehicle_id'], max( (string) $s['ship_date'], $ctx->date() ), (string) $s['depart_time'], $rest, (int) $s['id'] );
			self::store_tour( $ctx, (int) $s['id'], $plan );
		} else {
			self::store_pickup( $ctx, (int) $s['id'], (string) $s['carrier_code'], $rest );
		}
	}

	/**
	 * Affecte des livraisons à un moyen de transport du jour (VT11).
	 *
	 * @param array $target {shipment?: n° de transport existant, vehicle_id?: nouvelle tournée, depart?: heure, carrier?: enlèvement}
	 */
	public static function assign( Ctx $ctx, string $date, array $nos, array $target ): array {
		$nos = array_values( array_unique( array_filter( array_map( 'strval', $nos ) ) ) );
		if ( ! $nos ) {
			throw new DomainError( 'Sélectionnez au moins une livraison.' );
		}
		if ( ! Api::valid_date( $date ) || $date < $ctx->date() || $date > gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +30 days' ) ) ) {
			throw new DomainError( 'Date de transport invalide : entre aujourd\'hui et 30 jours.' );
		}
		foreach ( $nos as $n ) {
			$i = Transport::delivery_info( $ctx, $n );
			if ( ! in_array( $i['status'], Transport::PLANABLE, true ) ) {
				throw new DomainError( sprintf( 'La livraison %s est déjà expédiée ou supprimée.', $n ) );
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $date, $nos, $target ) {
				$moved = array();
				foreach ( $nos as $n ) {
					$i = Transport::delivery_info( $ctx, $n );
					if ( $i['shipment_id'] ) {
						$moved[] = $n;
						self::detach( $ctx, $n );
					}
				}
				$shp = (string) ( $target['shipment'] ?? '' );
				if ( '' !== $shp ) {
					$s = self::planned( $ctx, $shp );
					if ( $s['ship_date'] !== $date ) {
						throw new DomainError( sprintf( 'Le transport %s est prévu le %s.', $shp, gmdate( 'd.m.Y', strtotime( $s['ship_date'] ) ) ) );
					}
					$all = array_merge( self::shipment_nos( $ctx, (int) $s['id'] ), $nos );
					if ( 'TOURNEE' === $s['shp_type'] ) {
						$plan = self::best_plan( $ctx, (int) $s['vehicle_id'], $date, (string) $s['depart_time'], $all, (int) $s['id'] );
						if ( $plan['issues'] ) {
							throw new DomainError( sprintf( 'Ces livraisons ne peuvent pas partir avec la tournée %s (%s).', $shp, self::means_label( $s ) ), $plan['issues'] );
						}
						self::store_tour( $ctx, (int) $s['id'], $plan );
					} else {
						self::check_service( $ctx, $nos, (string) $s['carrier_code'] );
						self::store_pickup( $ctx, (int) $s['id'], (string) $s['carrier_code'], $all );
					}
					$no = $shp;
				} elseif ( ! empty( $target['carrier'] ) ) {
					$no = self::pickup_for( $ctx, $date, strtoupper( (string) $target['carrier'] ), $nos );
				} else {
					$no = self::new_tour( $ctx, $date, (int) ( $target['vehicle_id'] ?? 0 ), (string) ( $target['depart'] ?? '07:00' ), $nos );
				}
				$s = Transport::by_no( $ctx, $no );
				foreach ( $nos as $n ) {
					StockTx::log_change( $ctx, 'SHP', $no, 0, 'Livraison', '', $n, 'VT11' );
				}
				return array( 'no' => $no, 'doc_no' => $no, 'message' => sprintf( '%d livraison%s affectée%s au transport %s (%s)%s.', count( $nos ), count( $nos ) > 1 ? 's' : '', count( $nos ) > 1 ? 's' : '', $no, self::means_label( $s ), $moved ? ', retirée' . ( count( $moved ) > 1 ? 's' : '' ) . ' de leur transport précédent' : '' ) );
			}
		);
	}

	private static function check_service( Ctx $ctx, array $nos, string $code ): void {
		$errors = array();
		foreach ( $nos as $n ) {
			$errors = array_merge( $errors, self::service_issues( Transport::delivery_info( $ctx, $n ), $code ) );
		}
		if ( $errors ) {
			throw new DomainError( sprintf( 'Envoi refusé par %s.', self::carrier_name( $code ) ), $errors );
		}
	}

	/** Enlèvement du transporteur pour la date (un seul par transporteur et par jour). */
	private static function pickup_for( Ctx $ctx, string $date, string $code, array $nos ): string {
		global $wpdb;
		if ( ! isset( self::SERVICES[ $code ] ) ) {
			throw new DomainError( 'Choisissez un transporteur.' );
		}
		self::check_service( $ctx, $nos, $code );
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'shipment' ) . " WHERE session_id = %d AND shp_type = 'ENLEVEMENT' AND carrier_code = %s AND ship_date = %s AND status = 'PLANIFIE' ORDER BY id LIMIT 1", $ctx->id, $code, $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $s ) {
			$all = array_merge( self::shipment_nos( $ctx, (int) $s['id'] ), $nos );
			$tot = 0.0;
			foreach ( $all as $n ) {
				$tot += Transport::delivery_info( $ctx, $n )['weight'];
			}
			if ( $tot > self::SERVICES[ $code ]['max_total'] * 3 ) {
				throw new DomainError( sprintf( 'Enlèvement %s complet pour aujourd\'hui (%s kg).', $s['shp_no'], Catalog::format_qty( round( $tot ) ) ) );
			}
			self::store_pickup( $ctx, (int) $s['id'], $code, $all );
			return (string) $s['shp_no'];
		}
		$no  = NumberRange::next( $ctx->id, 'SHP' );
		$sid = Db::insert( 'shipment', array( 'session_id' => $ctx->id, 'shp_no' => $no, 'vehicle_id' => 0, 'ship_date' => $date, 'depart_time' => self::SERVICES[ $code ]['pickup'], 'pickup_time' => self::SERVICES[ $code ]['pickup'], 'shp_type' => 'ENLEVEMENT', 'carrier_code' => $code, 'status' => 'PLANIFIE', 'created_at' => Db::now() ) );
		self::store_pickup( $ctx, $sid, $code, $nos );
		return $no;
	}

	private static function new_tour( Ctx $ctx, string $date, int $vehicle_id, string $depart, array $nos ): string {
		if ( count( $nos ) > 12 ) {
			throw new DomainError( 'Une tournée compte au maximum 12 arrêts.' );
		}
		$plan = self::best_plan( $ctx, $vehicle_id, $date, $depart, $nos );
		if ( $plan['issues'] ) {
			throw new DomainError( 'Tournée non conforme avec ce véhicule.', $plan['issues'] );
		}
		$no  = NumberRange::next( $ctx->id, 'SHP' );
		$sid = Db::insert( 'shipment', array( 'session_id' => $ctx->id, 'shp_no' => $no, 'vehicle_id' => $vehicle_id, 'ship_date' => $date, 'depart_time' => $plan['depart'], 'shp_type' => 'TOURNEE', 'status' => 'PLANIFIE', 'created_at' => Db::now() ) );
		self::store_tour( $ctx, $sid, $plan );
		return $no;
	}

	/** Retire une livraison de son transport (VT11). */
	public static function unassign( Ctx $ctx, string $dlv_no ): array {
		$d = Sales::delivery_by_no( $ctx, $dlv_no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $dlv_no ) );
		}
		if ( ! (int) $d['shipment_id'] ) {
			throw new DomainError( sprintf( 'La livraison %s n\'est affectée à aucun transport.', $dlv_no ) );
		}
		global $wpdb;
		$shp = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT shp_no FROM ' . Db::t( 'shipment' ) . ' WHERE id = %d', (int) $d['shipment_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Db::transaction( static fn() => self::detach( $ctx, $dlv_no ) );
		StockTx::log_change( $ctx, 'SHP', $shp, 0, 'Livraison', $dlv_no, '', 'VT11' );
		return array( 'no' => $shp, 'message' => sprintf( 'Livraison %s retirée du transport %s.', $dlv_no, $shp ) );
	}

	/** Supprime un transport planifié : ses livraisons redeviennent à planifier. */
	public static function delete( Ctx $ctx, string $no ): array {
		$s = self::planned( $ctx, $no );
		return Db::transaction(
			static function () use ( $ctx, $s, $no ) {
				global $wpdb;
				$wpdb->update( Db::t( 'outbound_delivery' ), array( 'shipment_id' => 0 ), array( 'shipment_id' => (int) $s['id'], 'session_id' => $ctx->id ) );
				$wpdb->delete( Db::t( 'shipment_stop' ), array( 'shipment_id' => (int) $s['id'] ) );
				$wpdb->delete( Db::t( 'shipment' ), array( 'id' => (int) $s['id'] ) );
				return array( 'no' => $no, 'message' => sprintf( 'Transport %s supprimé : ses livraisons sont de nouveau à planifier.', $no ) );
			}
		);
	}

	/** Change l'heure de départ d'une tournée planifiée (recalcul de l'itinéraire). */
	public static function retime( Ctx $ctx, string $no, string $depart ): array {
		$s = self::planned( $ctx, $no );
		if ( 'TOURNEE' !== $s['shp_type'] ) {
			throw new DomainError( 'L\'heure d\'enlèvement est fixée par le transporteur.' );
		}
		$plan = self::best_plan( $ctx, (int) $s['vehicle_id'], max( (string) $s['ship_date'], $ctx->date() ), $depart, self::shipment_nos( $ctx, (int) $s['id'] ), (int) $s['id'] );
		if ( $plan['issues'] ) {
			throw new DomainError( 'Avec ce départ, la tournée n\'est plus conforme.', $plan['issues'] );
		}
		Db::transaction( static fn() => self::store_tour( $ctx, (int) $s['id'], $plan ) );
		StockTx::log_change( $ctx, 'SHP', $no, 0, 'Départ', (string) $s['depart_time'], $plan['depart'], 'VT02N' );
		return array( 'no' => $no, 'message' => sprintf( 'Tournée %s : départ %s, retour prévu %s.', $no, $plan['depart'], $plan['return'] ) );
	}

	/* ───────────────────────── VT04 : création groupée ───────────────────────── */

	/**
	 * Proposition d'affectation des livraisons ouvertes : transporteur habituel du client,
	 * ADR et froid vers un transport adapté, livraisons « flotte » regroupées en tournées.
	 */
	public static function proposal( Ctx $ctx, string $date, string $depart = '06:30' ): array {
		$open  = self::deliveries( $ctx, self::next_workday( $date ), 'OPEN' );
		$rows  = array();
		$fleet = array();
		foreach ( $open as $d ) {
			$def = $d['default'];
			if ( $d['adr'] ) {
				$rows[ $d['no'] ] = array( 'C:ADR', 'Marchandises dangereuses : groupage ADR.' );
			} elseif ( $d['cold'] && 'PROPRE' !== $def ) {
				$rows[ $d['no'] ] = array( 'C:FRIGO', 'Chaîne du froid : groupage frigorifique.' );
			} elseif ( isset( self::SERVICES[ $def ] ) && ! self::service_issues( $d, $def ) ) {
				$rows[ $d['no'] ] = array( 'C:' . $def, 'Transporteur habituel du client (fiche client).' );
			} elseif ( isset( self::SERVICES[ $def ] ) && 'POST' === $def && ! self::service_issues( $d, 'LEMAN' ) ) {
				$rows[ $d['no'] ] = array( 'C:LEMAN', 'Trop lourd ou sur palette pour le service colis : groupage.' );
			} else {
				$fleet[] = $d;
			}
		}
		// Flotte : remplissage des véhicules (le plus petit qui convient d'abord), ordre par proximité.
		$vehicles = array_values( array_filter( Transport::vehicles(), static fn( $v ) => 'INTERNE' === $v['owner'] ) );
		usort( $vehicles, static fn( $a, $b ) => $a['payload'] <=> $b['payload'] );
		$used   = array();
		$groups = array();
		$queue  = $fleet;
		while ( $queue ) {
			$seed  = array_shift( $queue );
			$found = null;
			foreach ( $vehicles as $v ) {
				if ( in_array( $v['id'], $used, true ) || ( $seed['cold'] && ! $v['cold'] ) ) {
					continue;
				}
				try {
					$p = self::best_plan( $ctx, $v['id'], $date, $depart, array( $seed['no'] ) );
				} catch ( DomainError $e ) {
					continue;
				}
				if ( ! $p['issues'] ) {
					$found = $v;
					break;
				}
			}
			if ( ! $found ) {
				$alt = $seed['cold'] ? 'FRIGO' : 'LEMAN';
				$rows[ $seed['no'] ] = array( 'C:' . $alt, 'Aucun véhicule de la flotte disponible ou adapté : groupage.' );
				continue;
			}
			// Agrandit la tournée tant que le plus grand véhicule libre adapté reste conforme.
			$members = array( $seed['no'] );
			$bigger  = array_values( array_filter( $vehicles, static fn( $v ) => ! in_array( $v['id'], $used, true ) && $v['payload'] >= $found['payload'] && ( ! $seed['cold'] || $v['cold'] ) ) );
			$veh     = $found;
			foreach ( $queue as $k => $d ) {
				if ( $d['cold'] !== $seed['cold'] || count( $members ) >= 8 ) {
					continue;
				}
				foreach ( $bigger as $v ) {
					$p = self::best_plan( $ctx, $v['id'], $date, $depart, array_merge( $members, array( $d['no'] ) ) );
					if ( ! $p['issues'] ) {
						$members[] = $d['no'];
						$veh       = $v['payload'] > $veh['payload'] ? $v : $veh;
						unset( $queue[ $k ] );
						break;
					}
				}
			}
			$queue = array_values( $queue );
			// Le plus petit véhicule qui accepte la tournée finale.
			foreach ( $vehicles as $v ) {
				if ( in_array( $v['id'], $used, true ) || ( $seed['cold'] && ! $v['cold'] ) ) {
					continue;
				}
				$p = self::best_plan( $ctx, $v['id'], $date, $depart, $members );
				if ( ! $p['issues'] ) {
					$veh = $v;
					break;
				}
			}
			$used[]   = $veh['id'];
			$groups[] = array( 'vehicle' => $veh, 'nos' => $members );
			foreach ( $members as $m ) {
				$rows[ $m ] = array( 'V:' . $veh['id'], 'Client livré par la flotte : tournée ' . $veh['name'] . '.' );
			}
		}
		$list = array();
		foreach ( $open as $d ) {
			if ( isset( $rows[ $d['no'] ] ) ) {
				$list[] = array( 'delivery' => $d, 'target' => $rows[ $d['no'] ][0], 'reason' => $rows[ $d['no'] ][1] );
			}
		}
		return array( 'date' => $date, 'depart' => $depart, 'rows' => $list, 'means' => self::means( $ctx, $date ) );
	}

	/**
	 * VT04 : crée les transports à partir des affectations (n° de livraison → « C:code » ou « V:id véhicule »).
	 * Chaque transport est créé séparément : une erreur n'empêche pas les autres.
	 */
	public static function create_collective( Ctx $ctx, string $date, string $depart, array $map ): array {
		$groups = array();
		foreach ( $map as $no => $key ) {
			$key = (string) $key;
			if ( preg_match( '/^(C:[A-Z]{2,10}|V:\d{1,6})$/', $key ) ) {
				$groups[ $key ][] = (string) $no;
			}
		}
		if ( ! $groups ) {
			throw new DomainError( 'Aucune livraison à affecter.' );
		}
		$created = array();
		$errors  = array();
		foreach ( $groups as $key => $nos ) {
			try {
				$target = 'C' === $key[0] ? array( 'carrier' => substr( $key, 2 ) ) : array( 'vehicle_id' => (int) substr( $key, 2 ), 'depart' => $depart );
				$r         = self::assign( $ctx, $date, $nos, $target );
				$created[] = $r['no'];
			} catch ( DomainError $e ) {
				$errors[] = $e->getMessage() . ( $e->details() ? ' ' . implode( ' ', $e->details() ) : '' );
			}
		}
		if ( ! $created ) {
			throw new DomainError( 'Aucun transport créé.', $errors );
		}
		return array( 'created' => $created, 'errors' => $errors, 'doc_no' => implode( ', ', $created ), 'message' => sprintf( '%d transport%s créé%s ou complété%s : %s.', count( $created ), count( $created ) > 1 ? 's' : '', count( $created ) > 1 ? 's' : '', count( $created ) > 1 ? 's' : '', implode( ', ', $created ) ) );
	}

	/* ───────────────────────── Remise au transporteur ───────────────────────── */

	/** VT02N pour un enlèvement : le transporteur charge au quai ; sortie de marchandises de chaque livraison. */
	public static function handover( Ctx $ctx, string $no ): array {
		$s = self::planned( $ctx, $no );
		if ( 'ENLEVEMENT' !== $s['shp_type'] ) {
			throw new DomainError( 'Une tournée part avec « Départ du camion ».' );
		}
		$nos  = self::shipment_nos( $ctx, (int) $s['id'] );
		$open = array();
		foreach ( $nos as $n ) {
			$i = Transport::delivery_info( $ctx, $n );
			if ( 'EMBALLEE' !== $i['status'] ) {
				$open[] = sprintf( 'Livraison %s (%s) : pas encore emballée.', $n, $i['customer']['name'] );
			}
		}
		if ( $open ) {
			throw new DomainError( 'Le transporteur ne peut pas charger : toutes les livraisons doivent être emballées (VL02N).', $open );
		}
		self::check_service( $ctx, $nos, (string) $s['carrier_code'] );
		return Db::transaction(
			static function () use ( $ctx, $s, $nos ) {
				global $wpdb;
				$docs = array();
				foreach ( $nos as $n ) {
					$d = Sales::delivery_by_no( $ctx, $n );
					Db::update( 'outbound_delivery', array( 'carrier_code' => (string) $s['carrier_code'] ), array( 'id' => (int) $d['id'] ) );
					$docs[] = Sales::goods_issue( $ctx, $n );
					$cust   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					Packaging::issue_for_delivery( $ctx, Sales::delivery_by_no( $ctx, $n ), $cust, (string) $s['shp_no'] );
				}
				Db::update( 'shipment', array( 'status' => 'REMIS', 'departed_at' => Db::now(), 'closed_at' => Db::now() ), array( 'id' => (int) $s['id'] ) );
				return array( 'doc_no' => implode( ', ', array_filter( $docs ) ), 'message' => sprintf( 'Envoi %s remis à %s : %d livraison%s sortie%s du stock (601).', $s['shp_no'], self::carrier_name( (string) $s['carrier_code'] ), count( $nos ), count( $nos ) > 1 ? 's' : '', count( $nos ) > 1 ? 's' : '' ) );
			}
		);
	}

	/* ───────────────────────── VT70 : papiers du transport ───────────────────────── */

	public static function papers( Ctx $ctx, string $no ): array {
		$t = Transport::detail( $ctx, $no );
		$s = Transport::by_no( $ctx, $no );
		$dg     = array();
		$stops  = array();
		$tw     = 0.0;
		$tp     = 0;
		$tk     = 0;
		foreach ( $t['stops'] as $st ) {
			$d    = $st['delivery'];
			$note = ShippingTx::delivery_note( $ctx, $d['no'] );
			foreach ( $note['lines'] as $l ) {
				if ( ! empty( $l['material']['un'] ) ) {
					$dg[] = array( 'delivery' => $d['no'], 'un' => $l['material']['un'], 'name' => $l['material']['hazard'], 'class' => $l['material']['adr'], 'pg' => $l['material']['packing_group'] ?? '', 'qty' => $l['qty'], 'uom' => $l['material']['uom'], 'weight' => round( (float) $l['qty'] * (float) ( Catalog::material_by_no( (string) $l['material']['no'] )['gross_weight'] ?? 0 ), 1 ) );
				}
			}
			$tw     += $d['weight'];
			$tp     += $d['pallets'];
			$tk     += $d['packages'];
			$stops[] = array( 'seq' => $st['seq'], 'eta' => $st['eta'], 'etd' => $st['etd'], 'no' => $d['no'], 'so_no' => $d['so_no'], 'customer' => $d['customer'], 'window' => $d['rules']['window'], 'appointment' => $d['rules']['appointment'], 'weight' => $d['weight'], 'pallets' => $d['pallets'], 'packages' => $d['packages'], 'support' => $d['support'], 'estimated' => $d['estimated'], 'balances' => $st['balances'] );
		}
		return array(
			'no'       => $t['no'],
			'type'     => $t['type'],
			'status'   => $t['status'],
			'date'     => $t['date'],
			'means'    => $t['means'],
			'carrier'  => $t['carrier'] ? self::carrier_name( $t['carrier'] ) : '',
			'service'  => $t['carrier'] ? ( self::SERVICES[ $t['carrier'] ]['label'] ?? '' ) : '',
			'vehicle'  => $t['vehicle'],
			'depart'   => $t['depart'],
			'return'   => $t['return'],
			'pickup'   => $t['pickup'],
			'km'       => $t['km'],
			'stops'    => $stops,
			'loading'  => array_reverse( array_map( static fn( $x ) => array( 'seq' => $x['seq'], 'no' => $x['no'], 'customer' => $x['customer']['name'] ), $stops ) ),
			'dangerous' => $dg,
			'totals'   => array( 'weight' => round( $tw, 1 ), 'pallets' => $tp, 'packages' => $tk ),
			'sender'   => array( 'name' => Company::name() ),
			'outputs'  => $t['outputs'],
			'estimated' => $s ? (int) array_sum( array_column( $stops, 'estimated' ) ) : 0,
		);
	}

	/* ───────────────────────── VL71 : bulletins de livraison en série ───────────────────────── */

	/** Sélection des livraisons pour l'impression des bulletins : par date, transport ou transporteur. */
	public static function vl71_list( Ctx $ctx, string $date, string $shp, string $carrier ): array {
		global $wpdb;
		$d     = Db::t( 'outbound_delivery' );
		$o     = Db::t( 'sales_order' );
		$c     = Db::t( 'customer' );
		$sh    = Db::t( 'shipment' );
		$where = array( $wpdb->prepare( 'd.session_id = %d', $ctx->id ), "d.status <> 'SUPPRIMEE'" );
		if ( '' !== $shp ) {
			$where[] = $wpdb->prepare( 's.shp_no = %s', $shp );
		} elseif ( '' !== $date ) {
			$where[] = $wpdb->prepare( '( s.ship_date = %s OR ( d.shipment_id = 0 AND o.req_date <= %s ) )', $date, self::next_workday( $date ) );
		}
		if ( '' !== $carrier ) {
			$where[] = 'PROPRE' === $carrier ? "s.shp_type = 'TOURNEE'" : $wpdb->prepare( '( d.carrier_code = %s OR s.carrier_code = %s )', $carrier, $carrier );
		}
		$rows = (array) $wpdb->get_results( 'SELECT d.dlv_no, d.status, d.packages, d.weight_kg, d.carrier_code, o.so_no, o.req_date, c.customer_no, c.name, c.city, s.shp_no, s.shp_type, s.carrier_code shp_carrier, s.vehicle_id, s.ship_date FROM ' . $d . ' d JOIN ' . $o . ' o ON o.id = d.so_id JOIN ' . $c . ' c ON c.id = o.customer_id LEFT JOIN ' . $sh . ' s ON s.id = d.shipment_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.shp_no IS NULL, s.shp_no, d.dlv_no LIMIT 150', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$prints = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'output_msg' ) . " WHERE session_id = %d AND obj_type = 'DLV' AND obj_no = %s AND output_type = 'ZPAL'", $ctx->id, $r['dlv_no'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[]  = array( 'no' => $r['dlv_no'], 'status' => $r['status'], 'so_no' => $r['so_no'], 'customer' => $r['customer_no'] . ' ' . $r['name'], 'city' => $r['city'], 'shp_no' => (string) $r['shp_no'], 'means' => $r['shp_no'] ? self::means_label( array( 'shp_type' => $r['shp_type'], 'carrier_code' => $r['shp_carrier'], 'vehicle_id' => $r['vehicle_id'] ) ) : ( $r['carrier_code'] ? self::carrier_name( (string) $r['carrier_code'] ) : '' ), 'packages' => (int) $r['packages'], 'weight' => (float) $r['weight_kg'], 'packed' => in_array( $r['status'], array( 'EMBALLEE', 'EXPEDIEE' ), true ) ? 1 : 0, 'prints' => $prints );
		}
		return $out;
	}

	/** VL71 : bulletins des livraisons choisies, chaque impression enregistrée comme message ZPAL. */
	public static function vl71_print( Ctx $ctx, array $nos ): array {
		$nos = array_values( array_unique( array_filter( array_map( 'strval', $nos ) ) ) );
		if ( ! $nos ) {
			throw new DomainError( 'Sélectionnez au moins une livraison.' );
		}
		if ( count( $nos ) > 60 ) {
			throw new DomainError( 'Au plus 60 bulletins par impression.' );
		}
		$notes = array();
		$skip  = array();
		foreach ( $nos as $n ) {
			$d = Sales::delivery_by_no( $ctx, $n );
			if ( ! $d || 'SUPPRIMEE' === $d['status'] ) {
				$skip[] = sprintf( 'Livraison %s introuvable ou supprimée.', $n );
				continue;
			}
			if ( ! in_array( $d['status'], array( 'EMBALLEE', 'EXPEDIEE' ), true ) ) {
				$skip[] = sprintf( 'Livraison %s : pas encore emballée, le bulletin serait incomplet (colis, poids).', $n );
				continue;
			}
			$notes[] = ShippingTx::delivery_note( $ctx, $n );
		}
		if ( ! $notes ) {
			throw new DomainError( 'Aucun bulletin imprimable.', $skip );
		}
		Db::transaction(
			static function () use ( $ctx, $notes ) {
				foreach ( $notes as $nt ) {
					StockTx::output( $ctx, 'DLV', $nt['dlv_no'], 'ZPAL', 'VL71' );
				}
			}
		);
		return array( 'notes' => $notes, 'skipped' => $skip, 'message' => sprintf( '%d bulletin%s de livraison envoyé%s à l\'impression (ZPAL).', count( $notes ), count( $notes ) > 1 ? 's' : '', count( $notes ) > 1 ? 's' : '' ) );
	}
}
