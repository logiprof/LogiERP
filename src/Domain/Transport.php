<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transport (VT01N / VT02N) : tournées avec la flotte de l'entreprise ou un prestataire,
 * calcul de l'itinéraire (distances, heures d'arrivée), contrôle des exigences des clients
 * (jours et plages de livraison, type de véhicule, hayon, ADR, froid, capacité, temps de travail),
 * coût de la tournée, départ (sortie de marchandises) et retour (emballages consignés).
 */
final class Transport {

	/** Centre de distribution d'Aclens (point de départ et de retour). */
	public const DEPOT = array( 46.5686, 6.5125 );

	public const ROAD_FACTOR = 1.3;
	public const SPEED_KMH   = 60;
	public const MAX_DUTY    = 600; // amplitude maximale du chauffeur (minutes)

	public const DAYS = array( 1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche' );

	public const SUPPORTS = array(
		'EUR'    => 'Palette EUR 1200×800 (échange)',
		'CP3'    => 'Palette chimique CP3 1140×1140 (consignée)',
		'PERDUE' => 'Palette perdue (non consignée)',
		'COLIS'  => 'Colis / cartons',
	);

	public const VEHICLE_REQ = array(
		''        => 'Aucune restriction',
		'PORTEUR' => 'Porteur uniquement (pas de semi-remorque)',
		'HAYON'   => 'Hayon élévateur obligatoire',
		'FOURGON' => 'Accès restreint : fourgon 3,5 t uniquement',
	);

	/** Statuts de livraison qu'on peut affecter à un transport (avant ou après l'emballage). */
	public const PLANABLE = array( 'A_PREPARER', 'EN_PREPARATION', 'PREPAREE', 'EMBALLEE' );

	public const VEHICLE_TYPES = array( 'FOURGON' => 'Fourgon', 'PORTEUR' => 'Porteur', 'SEMI' => 'Semi-remorque' );

	public static function vehicles(): array {
		global $wpdb;
		$t = Db::t( 'vehicle' );
		return array_map( array( self::class, 'vehicle_summary' ), (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE active = 1 ORDER BY owner DESC, payload_kg", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function vehicle( int $id ): ?array {
		global $wpdb;
		$t = Db::t( 'vehicle' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND active = 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function vehicle_summary( array $v ): array {
		return array(
			'id'       => (int) $v['id'],
			'code'     => $v['code'],
			'name'     => $v['name'],
			'owner'    => $v['owner'],
			'carrier'  => $v['carrier_code'],
			'type'     => $v['type'],
			'type_label' => self::VEHICLE_TYPES[ $v['type'] ] ?? $v['type'],
			'payload'  => (float) $v['payload_kg'],
			'places'   => (int) $v['pallet_places'],
			'tail_lift' => (int) $v['tail_lift'],
			'cold'     => (int) $v['cold'],
			'adr'      => (int) $v['adr'],
			'tariff'   => 'INTERNE' === $v['owner'] ? sprintf( 'CHF %s/km + CHF %s/h (chauffeur)', number_format( (float) $v['cost_km'], 2, '.', '' ), number_format( (float) $v['cost_hour'], 2, '.', '' ) ) : sprintf( 'CHF %s forfait + CHF %s/km', number_format( (float) $v['cost_fixed'], 2, '.', '' ), number_format( (float) $v['cost_km'], 2, '.', '' ) ),
		);
	}

	/** Exigences de livraison d'un client, lisibles. */
	public static function constraints( array $c ): array {
		$days = array_filter( array_map( 'intval', explode( ',', (string) $c['delivery_days'] ) ) );
		return array(
			'days'        => array_values( $days ),
			'days_label'  => $days ? ( 5 === count( $days ) && ! array_diff( array( 1, 2, 3, 4, 5 ), $days ) ? 'du lundi au vendredi' : implode( ', ', array_map( static fn( $d ) => self::DAYS[ $d ] ?? '', $days ) ) ) : 'tous les jours',
			'from'        => (string) $c['window_from'],
			'to'          => (string) $c['window_to'],
			'window'      => '' !== (string) $c['window_from'] ? $c['window_from'] . '–' . $c['window_to'] : 'toute la journée',
			'pallet'      => (string) $c['pallet_type'],
			'pallet_label' => self::SUPPORTS[ $c['pallet_type'] ] ?? 'Indifférent',
			'vehicle'     => (string) $c['vehicle_req'],
			'vehicle_label' => self::VEHICLE_REQ[ $c['vehicle_req'] ] ?? '',
			'appointment' => (int) $c['appointment'],
			'lat'         => null !== $c['lat'] ? (float) $c['lat'] : null,
			'lon'         => null !== $c['lon'] ? (float) $c['lon'] : null,
		);
	}

	private static function km( array $a, array $b ): float {
		$r  = 6371.0;
		$dl = deg2rad( $b[0] - $a[0] );
		$dn = deg2rad( $b[1] - $a[1] );
		$x  = sin( $dl / 2 ) ** 2 + cos( deg2rad( $a[0] ) ) * cos( deg2rad( $b[0] ) ) * sin( $dn / 2 ) ** 2;
		return round( 2 * $r * asin( min( 1, sqrt( $x ) ) ) * self::ROAD_FACTOR, 1 );
	}

	private static function minutes( string $hm ): int {
		return preg_match( '/^(\d{1,2}):(\d{2})$/', $hm, $m ) ? (int) $m[1] * 60 + (int) $m[2] : -1;
	}

	private static function hm( int $min ): string {
		$min = max( 0, $min );
		return sprintf( '%02d:%02d', intdiv( $min, 60 ) % 24, $min % 60 );
	}

	/** Livraisons emballées, pas encore affectées à une tournée. */
	public static function ready( Ctx $ctx ): array {
		global $wpdb;
		$d    = Db::t( 'outbound_delivery' );
		$o    = Db::t( 'sales_order' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.dlv_no FROM {$d} d JOIN {$o} o ON o.id = d.so_id WHERE d.session_id = %d AND d.status = 'EMBALLEE' AND d.shipment_id = 0 ORDER BY o.req_date, d.id", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( static fn( $r ) => self::delivery_info( $ctx, (string) $r['dlv_no'] ), $rows );
	}

	/** Données de transport d'une livraison : client, exigences, poids, palettes, ADR, froid. */
	public static function delivery_info( Ctx $ctx, string $no ): array {
		global $wpdb;
		$d = Sales::delivery_by_no( $ctx, $no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Livraison %s introuvable.', $no ) );
		}
		$so  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'sales_order' ) . ' WHERE id = %d', (int) $d['so_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$c   = Sales::customer( (int) $so['customer_id'] );
		$det    = Sales::delivery_detail( $ctx, $no );
		$packed = in_array( $d['status'], array( 'EMBALLEE', 'EXPEDIEE' ), true );
		$pal    = in_array( $d['pallet_type'], array( 'EUR', 'CP3', 'PERDUE' ), true );
		$weight = (float) $d['weight_kg'];
		$pkgs   = (int) $d['packages'];
		$pals   = $pal ? (int) $d['packages'] : 0;
		$supp   = (string) $d['pallet_type'];
		if ( ! $packed ) {
			// Avant l'emballage : poids et supports estimés à partir des articles (le planificateur travaille souvent avant la préparation).
			$net = 0.0;
			foreach ( $det['items'] as $i ) {
				$net += (float) $i['weight'];
			}
			$pals   = $net >= 150 ? (int) ceil( $net / 600 ) : 0;
			$pkgs   = $pals ?: max( 1, (int) ceil( $net / 20 ) );
			$weight = round( $net + ( $pals ? 25 * $pals : 0.5 * $pkgs ), 1 );
			$supp   = $pals ? ( '' !== (string) $c['pallet_type'] ? (string) $c['pallet_type'] : 'EUR' ) : 'COLIS';
		}
		return array(
			'id'       => (int) $d['id'],
			'no'       => $d['dlv_no'],
			'so_no'    => $so['so_no'],
			'req_date' => $so['req_date'],
			'status'   => $d['status'],
			'customer' => array( 'id' => (int) $c['id'], 'no' => $c['customer_no'], 'name' => $c['name'], 'street' => $c['street'], 'city' => $c['zip'] . ' ' . $c['city'], 'type' => $c['type'] ),
			'rules'    => self::constraints( $c ),
			'weight'   => $weight,
			'packages' => $pkgs,
			'pallets'  => $pals,
			'support'  => $supp,
			'estimated' => $packed ? 0 : 1,
			'shipment_id' => (int) $d['shipment_id'],
			'carrier'  => (string) $d['carrier_code'],
			'adr'      => (int) $det['needs']['adr'],
			'cold'     => (int) $det['needs']['cold'],
		);
	}

	/**
	 * Calcule une tournée sans l'enregistrer : distances, heures d'arrivée, attentes, coût et non-conformités.
	 *
	 * @param string[] $dlv_nos livraisons dans l'ordre des arrêts
	 */
	public static function simulate( Ctx $ctx, int $vehicle_id, string $date, string $depart, array $dlv_nos, int $exclude_shipment = 0 ): array {
		$v = self::vehicle( $vehicle_id );
		if ( ! $v ) {
			throw new DomainError( 'Choisissez un véhicule.' );
		}
		if ( ! Api::valid_date( $date ) || $date < $ctx->date() || $date > gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +30 days' ) ) ) {
			throw new DomainError( 'Date de tournée invalide : entre aujourd\'hui et 30 jours.' );
		}
		$t0 = self::minutes( $depart );
		if ( $t0 < 240 || $t0 > 1200 ) {
			throw new DomainError( 'Heure de départ invalide (entre 04:00 et 20:00).' );
		}
		$dlv_nos = array_values( array_unique( array_filter( array_map( 'strval', $dlv_nos ) ) ) );
		if ( ! $dlv_nos ) {
			throw new DomainError( 'Ajoutez au moins une livraison à la tournée.' );
		}
		if ( count( $dlv_nos ) > 12 ) {
			throw new DomainError( 'Une tournée compte au maximum 12 arrêts.' );
		}
		$dow    = (int) gmdate( 'N', strtotime( $date ) );
		$issues = array();
		$stops  = array();
		$pos    = self::DEPOT;
		$clock  = $t0;
		$km     = 0.0;
		$weight = 0.0;
		$pals   = 0;
		$adr    = false;
		$cold   = false;
		foreach ( $dlv_nos as $i => $no ) {
			$d = self::delivery_info( $ctx, $no );
			if ( ! in_array( $d['status'], self::PLANABLE, true ) ) {
				throw new DomainError( sprintf( 'La livraison %s ne peut pas être planifiée : elle est déjà expédiée ou supprimée.', $no ) );
			}
			if ( $d['shipment_id'] && $d['shipment_id'] !== $exclude_shipment ) {
				throw new DomainError( sprintf( 'La livraison %s est déjà affectée à un autre transport : retirez-la d\'abord (VT11).', $no ) );
			}
			$r      = $d['rules'];
			$name   = $d['customer']['name'];
			$weight += $d['weight'];
			$pals   += $d['pallets'];
			$adr     = $adr || $d['adr'];
			$cold    = $cold || $d['cold'];
			$to      = null !== $r['lat'] ? array( $r['lat'], $r['lon'] ) : self::DEPOT;
			$leg     = self::km( $pos, $to );
			$drive   = (int) ceil( $leg / self::SPEED_KMH * 60 );
			$arrive  = $clock + $drive;
			$wait    = 0;
			$from    = '' !== $r['from'] ? self::minutes( $r['from'] ) : -1;
			$until   = '' !== $r['to'] ? self::minutes( $r['to'] ) : -1;
			if ( $from >= 0 && $arrive < $from ) {
				$wait   = $from - $arrive;
				$arrive = $from;
			}
			$service = 15 + ( $d['pallets'] ? 4 * $d['pallets'] : min( 15, $d['packages'] ) );
			$sv      = array();
			if ( $r['days'] && ! in_array( $dow, $r['days'], true ) ) {
				$sv[] = sprintf( '%s ne se fait livrer que %s (tournée prévue un %s).', $name, $r['days_label'], self::DAYS[ $dow ] );
			}
			if ( $until >= 0 && $arrive > $until ) {
				$sv[] = sprintf( '%s : arrivée à %s, après la fin de la plage de livraison (%s).', $name, self::hm( $arrive ), $r['window'] );
			}
			if ( 'PORTEUR' === $r['vehicle'] && 'SEMI' === $v['type'] ) {
				$sv[] = sprintf( '%s : accès interdit aux semi-remorques.', $name );
			}
			if ( 'FOURGON' === $r['vehicle'] && 'FOURGON' !== $v['type'] ) {
				$sv[] = sprintf( '%s : accès limité aux véhicules de 3,5 t (fourgon).', $name );
			}
			if ( 'HAYON' === $r['vehicle'] && ! (int) $v['tail_lift'] ) {
				$sv[] = sprintf( '%s : pas de quai de déchargement, un hayon élévateur est obligatoire.', $name );
			}
			if ( '' !== $r['pallet'] && $d['support'] && $d['support'] !== $r['pallet'] && 'COLIS' !== $d['support'] ) {
				$sv[] = sprintf( '%s exige : %s (livraison %s emballée sur %s).', $name, $r['pallet_label'], $no, ( self::SUPPORTS[ $d['support'] ] ?? $d['support'] ) );
			}
			if ( $d['adr'] && ! (int) $v['adr'] ) {
				$sv[] = sprintf( 'Livraison %s : marchandises dangereuses, véhicule et chauffeur ADR obligatoires.', $no );
			}
			if ( $d['cold'] && ! (int) $v['cold'] ) {
				$sv[] = sprintf( 'Livraison %s : chaîne du froid, véhicule frigorifique obligatoire.', $no );
			}
			$issues = array_merge( $issues, $sv );
			$depart_at = $arrive + $service;
			$stops[]   = array(
				'seq'      => $i + 1,
				'delivery' => $d,
				'km'       => $leg,
				'eta'      => self::hm( $arrive ),
				'etd'      => self::hm( $depart_at ),
				'wait'     => $wait,
				'issues'   => $sv,
			);
			$km   += $leg;
			$clock = $depart_at;
			$pos   = $to;
		}
		$back   = self::km( $pos, self::DEPOT );
		$km    += $back;
		$clock += (int) ceil( $back / self::SPEED_KMH * 60 );
		$total  = $clock - $t0;
		if ( $weight > (float) $v['payload_kg'] ) {
			$issues[] = sprintf( 'Surcharge : %s kg pour une charge utile de %s kg.', Catalog::format_qty( round( $weight ) ), Catalog::format_qty( (float) $v['payload_kg'] ) );
		}
		if ( $pals > (int) $v['pallet_places'] ) {
			$issues[] = sprintf( 'Capacité : %d palettes pour %d places au sol.', $pals, (int) $v['pallet_places'] );
		}
		if ( $adr && $cold ) {
			$issues[] = 'Chargement en commun interdit : marchandises dangereuses (ADR) et denrées alimentaires dans le même véhicule.';
		}
		if ( 'INTERNE' === $v['owner'] ) {
			// Un véhicule de la flotte ne fait pas deux tournées en même temps.
			global $wpdb;
			$busy = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, shp_no, depart_time, return_time FROM ' . Db::t( 'shipment' ) . " WHERE session_id = %d AND vehicle_id = %d AND ship_date = %s AND status IN ('PLANIFIE','EN_ROUTE') AND id <> %d", $ctx->id, $vehicle_id, $date, $exclude_shipment ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $busy as $b ) {
				$bs = self::minutes( (string) $b['depart_time'] );
				$be = self::minutes( (string) $b['return_time'] );
				if ( $bs >= 0 && $be >= 0 && $t0 < $be + 30 && $clock + 30 > $bs ) {
					$issues[] = sprintf( 'Véhicule déjà engagé dans la tournée %s de %s à %s (30 min de chargement comprises) : choisissez un autre véhicule ou une autre heure de départ.', $b['shp_no'], $b['depart_time'], $b['return_time'] );
				}
			}
		}
		if ( $total > self::MAX_DUTY ) {
			$issues[] = sprintf( 'Durée de la tournée %s : dépasse l\'amplitude de travail du chauffeur (10 h, OTR 1). Répartissez les arrêts sur deux tournées.', self::hm( $total ) );
		}
		$hours = $total / 60;
		$cost  = 'INTERNE' === $v['owner'] ? $km * (float) $v['cost_km'] + $hours * (float) $v['cost_hour'] : (float) $v['cost_fixed'] + $km * (float) $v['cost_km'];
		return array(
			'vehicle'  => self::vehicle_summary( $v ),
			'date'     => $date,
			'weekday'  => self::DAYS[ $dow ],
			'depart'   => self::hm( $t0 ),
			'return'   => self::hm( $clock ),
			'km'       => round( $km, 1 ),
			'km_back'  => $back,
			'minutes'  => $total,
			'duration' => self::hm( $total ),
			'weight'   => round( $weight, 1 ),
			'pallets'  => $pals,
			'load_pct' => (float) $v['payload_kg'] > 0 ? (int) round( $weight / (float) $v['payload_kg'] * 100 ) : 0,
			'cost'     => round( $cost, 2 ),
			'stops'    => $stops,
			'issues'   => array_values( array_unique( $issues ) ),
		);
	}

	/** VT01N : enregistre la tournée si elle respecte toutes les exigences. */
	public static function create( Ctx $ctx, int $vehicle_id, string $date, string $depart, array $dlv_nos ): array {
		$plan = self::simulate( $ctx, $vehicle_id, $date, $depart, $dlv_nos );
		if ( $plan['issues'] ) {
			throw new DomainError( 'Tournée non conforme : corrigez l\'ordre des arrêts, le véhicule ou la date.', $plan['issues'] );
		}
		return Db::transaction(
			static function () use ( $ctx, $plan, $vehicle_id ) {
				$no  = NumberRange::next( $ctx->id, 'SHP' );
				$sid = Db::insert( 'shipment', array( 'session_id' => $ctx->id, 'shp_no' => $no, 'vehicle_id' => $vehicle_id, 'ship_date' => $plan['date'], 'depart_time' => $plan['depart'], 'status' => 'PLANIFIE', 'distance_km' => $plan['km'], 'duration_min' => $plan['minutes'], 'cost' => $plan['cost'], 'return_time' => $plan['return'], 'created_at' => Db::now() ) );
				foreach ( $plan['stops'] as $s ) {
					Db::insert( 'shipment_stop', array( 'shipment_id' => $sid, 'seq' => $s['seq'], 'delivery_id' => $s['delivery']['id'], 'customer_id' => $s['delivery']['customer']['id'], 'km' => $s['km'], 'eta' => $s['eta'], 'etd' => $s['etd'], 'wait_min' => $s['wait'] ) );
					Db::update( 'outbound_delivery', array( 'shipment_id' => $sid, 'carrier_code' => '' ), array( 'id' => $s['delivery']['id'] ) );
				}
				return array( 'no' => $no, 'doc_no' => $no, 'message' => sprintf( 'Tournée %s enregistrée : %d arrêt%s, %s km, retour prévu à %s, coût estimé CHF %s.', $no, count( $plan['stops'] ), count( $plan['stops'] ) > 1 ? 's' : '', Catalog::format_qty( $plan['km'] ), $plan['return'], number_format( $plan['cost'], 2, '.', '\'' ) ) );
			}
		);
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'shipment' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND shp_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function stops( int $shipment_id ): array {
		global $wpdb;
		$t = Db::t( 'shipment_stop' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE shipment_id = %d ORDER BY seq", $shipment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function list_for( Ctx $ctx ): array {
		global $wpdb;
		$t    = Db::t( 'shipment' );
		$st   = Db::t( 'shipment_stop' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.*, (SELECT COUNT(*) FROM {$st} x WHERE x.shipment_id = s.id) n FROM {$t} s WHERE s.session_id = %d ORDER BY s.id DESC LIMIT 100", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static function ( $s ) {
				$v = self::vehicle( (int) $s['vehicle_id'] );
				return array( 'no' => $s['shp_no'], 'date' => $s['ship_date'], 'depart' => 'TOURNEE' === ( $s['shp_type'] ?? 'TOURNEE' ) ? $s['depart_time'] : ( $s['pickup_time'] ?? '' ), 'status' => $s['status'], 'type' => $s['shp_type'] ?? 'TOURNEE', 'stops' => (int) $s['n'], 'km' => (float) $s['distance_km'], 'cost' => (float) $s['cost'], 'vehicle' => TransportPlan::means_label( $s ) );
			},
			$rows
		);
	}

	public static function detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$s = self::by_no( $ctx, $no );
		if ( ! $s ) {
			throw new DomainError( sprintf( 'Tournée %s introuvable.', $no ) );
		}
		$v     = self::vehicle( (int) $s['vehicle_id'] );
		$stops = array();
		foreach ( self::stops( (int) $s['id'] ) as $st ) {
			$dn      = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT dlv_no FROM ' . Db::t( 'outbound_delivery' ) . ' WHERE id = %d', (int) $st['delivery_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$d       = self::delivery_info( $ctx, $dn );
			$report  = json_decode( (string) $st['report'], true );
			$stops[] = array( 'id' => (int) $st['id'], 'seq' => (int) $st['seq'], 'delivery' => $d, 'km' => (float) $st['km'], 'eta' => $st['eta'], 'etd' => $st['etd'], 'wait' => (int) $st['wait_min'], 'returns_done' => (int) $st['returns_done'], 'report' => is_array( $report ) ? $report : null, 'balances' => Packaging::balances( $ctx, (int) $st['customer_id'] ) );
		}
		return array( 'no' => $s['shp_no'], 'status' => $s['status'], 'type' => $s['shp_type'] ?? 'TOURNEE', 'carrier' => $s['carrier_code'] ?? '', 'means' => TransportPlan::means_label( $s ), 'pickup' => $s['pickup_time'] ?? '', 'date' => $s['ship_date'], 'depart' => $s['depart_time'], 'return' => $s['return_time'], 'km' => (float) $s['distance_km'], 'minutes' => (int) $s['duration_min'], 'cost' => (float) $s['cost'], 'vehicle' => $v ? self::vehicle_summary( $v ) : null, 'stops' => $stops, 'outputs' => StockTx::outputs_of( $ctx, 'SHP', (string) $s['shp_no'] ) );
	}

	/** VT02N « Départ » : sortie de marchandises de chaque livraison et remise des emballages consignés. */
	public static function depart( Ctx $ctx, string $no ): array {
		global $wpdb;
		$s = self::by_no( $ctx, $no );
		if ( ! $s ) {
			throw new DomainError( sprintf( 'Tournée %s introuvable.', $no ) );
		}
		if ( 'PLANIFIE' !== $s['status'] ) {
			throw new DomainError( 'Cette tournée est déjà partie.' );
		}
		if ( 'TOURNEE' !== ( $s['shp_type'] ?? 'TOURNEE' ) ) {
			throw new DomainError( 'Ce transport est un enlèvement par le transporteur : utilisez « Remettre au transporteur » (VT02N).' );
		}
		$nos  = array();
		$open = array();
		foreach ( self::stops( (int) $s['id'] ) as $st ) {
			$dn = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT dlv_no FROM ' . Db::t( 'outbound_delivery' ) . " WHERE id = %d AND status <> 'EXPEDIEE'", (int) $st['delivery_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( '' === $dn ) {
				continue;
			}
			$nos[] = $dn;
			$info  = self::delivery_info( $ctx, $dn );
			if ( 'EMBALLEE' !== $info['status'] ) {
				$open[] = sprintf( 'Livraison %s (%s) : pas encore emballée.', $dn, $info['customer']['name'] );
			}
		}
		if ( $open ) {
			throw new DomainError( 'Le camion ne peut pas partir : toutes les livraisons doivent être préparées et emballées (VL02N).', $open );
		}
		if ( $nos ) {
			// Contrôle final avec les poids et les palettes réels de l'emballage.
			$plan = self::simulate( $ctx, (int) $s['vehicle_id'], (string) $s['ship_date'] < $ctx->date() ? $ctx->date() : (string) $s['ship_date'], (string) $s['depart_time'], $nos, (int) $s['id'] );
			$hard = array_values( array_filter( $plan['issues'], static fn( $i ) => str_starts_with( $i, 'Surcharge' ) || str_starts_with( $i, 'Capacité' ) || str_contains( $i, 'ADR' ) || str_contains( $i, 'froid' ) ) );
			if ( $hard ) {
				throw new DomainError( 'Chargement non conforme avec les poids et palettes réels de l\'emballage : répartissez les livraisons (VT11).', $hard );
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $s, $wpdb ) {
				$docs = array();
				foreach ( self::stops( (int) $s['id'] ) as $st ) {
					$d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'outbound_delivery' ) . ' WHERE id = %d AND session_id = %d', (int) $st['delivery_id'], $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( ! $d ) {
						continue;
					}
					if ( 'EMBALLEE' === $d['status'] ) {
						$docs[] = Sales::goods_issue( $ctx, (string) $d['dlv_no'], true );
					}
					$given  = Packaging::issue_for_delivery( $ctx, $d, (int) $st['customer_id'], (string) $s['shp_no'] );
					$report = Packaging::driver_report( $ctx, (int) $st['customer_id'], $given, (string) $s['shp_no'] );
					Db::update( 'shipment_stop', array( 'report' => wp_json_encode( $report ) ), array( 'id' => (int) $st['id'] ) );
				}
				Db::update( 'shipment', array( 'status' => 'EN_ROUTE', 'departed_at' => Db::now() ), array( 'id' => (int) $s['id'] ) );
				return array( 'doc_no' => implode( ', ', $docs ), 'message' => sprintf( 'Tournée %s partie : sorties de marchandises comptabilisées (601), emballages consignés remis aux clients (621).', $s['shp_no'] ) );
			}
		);
	}

	/**
	 * VT02N « Retour » : saisie des emballages vides rapportés par le chauffeur, arrêt par arrêt, puis clôture.
	 *
	 * @param array $returns [{stop_id, material_id, good, damaged}]
	 */
	public static function close( Ctx $ctx, string $no, array $returns ): array {
		$s = self::by_no( $ctx, $no );
		if ( ! $s ) {
			throw new DomainError( sprintf( 'Tournée %s introuvable.', $no ) );
		}
		if ( 'EN_ROUTE' !== $s['status'] ) {
			throw new DomainError( 'Seule une tournée partie peut être clôturée.' );
		}
		$stops = array();
		foreach ( self::stops( (int) $s['id'] ) as $st ) {
			$stops[ (int) $st['id'] ] = $st;
		}
		$by = array();
		foreach ( $returns as $r ) {
			$sid = (int) ( $r['stop_id'] ?? 0 );
			if ( ! isset( $stops[ $sid ] ) ) {
				throw new DomainError( 'Arrêt inconnu dans cette tournée.' );
			}
			$good = (float) ( $r['good'] ?? 0 );
			$bad  = (float) ( $r['damaged'] ?? 0 );
			if ( $good < 0 || $bad < 0 || $good + $bad > 999 || floor( $good ) !== $good || floor( $bad ) !== $bad ) {
				throw new DomainError( 'Les quantités d\'emballages rendus sont des nombres entiers positifs.' );
			}
			if ( $good + $bad > 0 ) {
				$by[] = array( $stops[ $sid ], (int) ( $r['material_id'] ?? 0 ), $good, $bad );
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $s, $stops, $by ) {
				$docs = array();
				foreach ( $by as [ $st, $mid, $good, $bad ] ) {
					$docs[] = Packaging::receive_empties( $ctx, (int) $st['customer_id'], $mid, $good, $bad, (string) $s['shp_no'], 'Retour tournée ' . $s['shp_no'] );
				}
				foreach ( $stops as $st ) {
					Db::update( 'shipment_stop', array( 'returns_done' => 1 ), array( 'id' => (int) $st['id'] ) );
				}
				Db::update( 'shipment', array( 'status' => 'TERMINE', 'closed_at' => Db::now() ), array( 'id' => (int) $s['id'] ) );
				return array( 'doc_no' => implode( ', ', array_filter( $docs ) ), 'message' => sprintf( 'Tournée %s clôturée : emballages vides comptabilisés (622).', $s['shp_no'] ) );
			}
		);
	}
}
