<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emballages consignés (MB58) : palettes EUR et CP3, cadres et couvercles de palette,
 * fûts, conteneurs IBC et caisses navettes. Remise au client à l'expédition (621),
 * retour des vides (622), compte d'emballages par client et valeur des consignes.
 */
final class Packaging {

	/** Articles d'emballage (numéros réservés 19000001 à 19000008). */
	public const ITEMS = array(
		'EUR'   => '19000001',
		'CP3'   => '19000002',
		'FRAME' => '19000003',
		'LID'   => '19000004',
	);

	public const EMPTIES_LOCATION = '950';

	/** Politique de retour des clients (bon du chauffeur) selon le type de client. */
	private const EXCHANGE_TYPES = array( 'Grossiste / distributeur', 'Industrie / atelier', 'Santé / laboratoire' );

	public static function item( string $key ): ?array {
		return isset( self::ITEMS[ $key ] ) ? Catalog::material_by_no( self::ITEMS[ $key ] ) : null;
	}

	public static function is_packaging( ?array $m ): bool {
		return $m && 'VERP' === $m['type'];
	}

	/** Emplacement des vides (magasin 950). */
	private static function empties_bin( Ctx $ctx ): array {
		global $wpdb;
		$loc = Catalog::location_by_code( self::EMPTIES_LOCATION );
		if ( ! $loc ) {
			throw new DomainError( 'Le magasin des emballages vides (950) n\'existe pas : relancez la mise à jour des données (Outils et santé).' );
		}
		$bin = $ctx->simple() ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bin' ) . ' WHERE storage_location_id = %d ORDER BY code LIMIT 1', (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( $loc, $bin );
	}

	public static function balance( Ctx $ctx, int $customer_id, int $material_id ): float {
		global $wpdb;
		$t = Db::t( 'packaging_account' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(qty),0) FROM {$t} WHERE session_id IN (0, %d) AND customer_id = %d AND material_id = %d", $ctx->id, $customer_id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Soldes d'emballages chez un client (ou chez tous les clients si 0). */
	public static function balances( Ctx $ctx, int $customer_id = 0 ): array {
		global $wpdb;
		$t    = Db::t( 'packaging_account' );
		$c    = Db::t( 'customer' );
		$sql  = "SELECT a.customer_id, a.material_id, SUM(a.qty) q FROM {$t} a WHERE a.session_id IN (0, %d)" . ( $customer_id ? ' AND a.customer_id = %d' : '' ) . ' GROUP BY a.customer_id, a.material_id HAVING ABS(q) > 0.0005 ORDER BY a.customer_id, a.material_id LIMIT 600';
		$rows = (array) $wpdb->get_results( $customer_id ? $wpdb->prepare( $sql, $ctx->id, $customer_id ) : $wpdb->prepare( $sql, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$m    = Catalog::material( (int) $r['material_id'] );
			$cust = Sales::customer( (int) $r['customer_id'] );
			if ( ! $m || ! $cust ) {
				continue;
			}
			$out[] = array( 'customer' => array( 'id' => (int) $cust['id'], 'no' => $cust['customer_no'], 'name' => $cust['name'], 'city' => $cust['city'] ), 'material' => Api::material_summary( $m ), 'qty' => (float) $r['q'], 'unit_value' => (float) $m['deposit_value'], 'value' => round( (float) $r['q'] * (float) $m['deposit_value'], 2 ) );
		}
		return $out;
	}

	public static function moves( Ctx $ctx, int $customer_id ): array {
		global $wpdb;
		$t    = Db::t( 'packaging_move' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND customer_id = %d ORDER BY id DESC LIMIT 100", $ctx->id, $customer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( static fn( $r ) => array( 'movement' => $r['movement_type'], 'material' => Api::material_summary( Catalog::material( (int) $r['material_id'] ) ), 'qty' => (float) $r['qty'], 'damaged' => (float) $r['qty_damaged'], 'ref_no' => $r['ref_no'], 'text' => $r['text'], 'at' => $r['created_at'] ), $rows );
	}

	private static function account( Ctx $ctx, int $customer_id, int $material_id, float $delta, string $mvt, float $damaged, string $ref, string $text ): void {
		global $wpdb;
		$t = Db::t( 'packaging_account' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (session_id, customer_id, material_id, qty) VALUES (%d, %d, %d, %f) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)", $ctx->id, $customer_id, $material_id, $delta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Db::insert( 'packaging_move', array( 'session_id' => $ctx->id, 'customer_id' => $customer_id, 'material_id' => $material_id, 'movement_type' => $mvt, 'qty' => abs( $delta ), 'qty_damaged' => $damaged, 'ref_no' => mb_substr( $ref, 0, 20 ), 'text' => mb_substr( $text, 0, 190 ), 'created_at' => Db::now() ) );
	}

	/**
	 * Emballages remis au client avec une livraison (621) : supports consignés et cadres/couvercles
	 * prélevés dans le stock de vides, contenants consignés (fûts, IBC, caisses) des articles livrés.
	 *
	 * @return array<int,float> material_id => quantité remise
	 */
	public static function issue_for_delivery( Ctx $ctx, array $d, int $customer_id, string $ref ): array {
		global $wpdb;
		$given = array();
		$own   = array();
		if ( in_array( $d['pallet_type'], array( 'EUR', 'CP3' ), true ) && (int) $d['packages'] > 0 ) {
			$own[ $d['pallet_type'] ] = (int) $d['packages'];
		}
		if ( (int) $d['frames'] > 0 ) {
			$own['FRAME'] = (int) $d['frames'];
		}
		if ( (int) $d['lids'] > 0 ) {
			$own['LID'] = (int) $d['lids'];
		}
		if ( $own ) {
			[ $loc, $bin ] = self::empties_bin( $ctx );
			$lines         = array();
			foreach ( $own as $key => $q ) {
				$m = self::item( $key );
				if ( ! $m ) {
					continue;
				}
				$lines[]                    = array( 'movement_type' => '621', 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => $bin, 'batch' => '', 'bbd' => null, 'status' => 'LIBRE', 'qty' => -$q, 'reason' => 'Remis au client' );
				$given[ (int) $m['id'] ] = ( $given[ (int) $m['id'] ] ?? 0 ) + $q;
			}
			if ( $lines ) {
				StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'DLV', 'ref_id' => (int) $d['id'], 'ref_no' => $d['dlv_no'], 'text' => 'Emballages consignés livraison ' . $d['dlv_no'] ), $lines );
			}
		}
		$items = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT material_id, IF(qty_picked > 0, qty_picked, qty) q FROM ' . Db::t( 'outbound_delivery_item' ) . ' WHERE delivery_id = %d', (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $items as $i ) {
			$m = Catalog::material( (int) $i['material_id'] );
			if ( $m && (int) $m['deposit_material_id'] && (float) $m['deposit_per'] > 0 ) {
				$n = (int) ceil( (float) $i['q'] / (float) $m['deposit_per'] - 0.0005 );
				if ( $n > 0 ) {
					$given[ (int) $m['deposit_material_id'] ] = ( $given[ (int) $m['deposit_material_id'] ] ?? 0 ) + $n;
				}
			}
		}
		foreach ( $given as $mid => $q ) {
			self::account( $ctx, $customer_id, (int) $mid, (float) $q, '621', 0, $ref, 'Livraison ' . $d['dlv_no'] );
		}
		return $given;
	}

	/**
	 * Bon de retour du chauffeur (réalité du terrain) : ce que le client rend en vides.
	 * Clients en échange 1:1 : autant de supports que livrés ; les autres rendent une partie de leur solde.
	 * Contenants (fûts, IBC, caisses) : le client rend les vides des livraisons précédentes.
	 */
	public static function driver_report( Ctx $ctx, int $customer_id, array $given, string $ref ): array {
		$cust     = Sales::customer( $customer_id );
		$exchange = $cust && in_array( $cust['type'], self::EXCHANGE_TYPES, true );
		$h        = abs( crc32( $ref . '|' . $customer_id ) );
		$out      = array();
		$supports = array_filter( array_map( static fn( $k ) => self::item( $k ), array_keys( self::ITEMS ) ) );
		$ids      = array_unique( array_merge( array_map( static fn( $m ) => (int) $m['id'], $supports ), array_map( 'intval', array_keys( $given ) ) ) );
		foreach ( self::balances( $ctx, $customer_id ) as $b ) {
			$ids[] = (int) $b['material']['id'];
		}
		foreach ( array_unique( $ids ) as $mid ) {
			$m   = Catalog::material( $mid );
			$bal = self::balance( $ctx, $customer_id, $mid );
			if ( ! $m || $bal <= 0.0005 ) {
				continue;
			}
			$now = (float) ( $given[ $mid ] ?? 0 );
			if ( in_array( $m['material_no'], self::ITEMS, true ) ) {
				$ret = $exchange ? min( $bal, $now ) : floor( $bal / 2 );
			} else {
				$ret = max( 0, $bal - $now );
			}
			$ret = (int) $ret;
			if ( $ret <= 0 ) {
				continue;
			}
			$bad   = $ret >= 4 && 1 === $h % 2 && self::ITEMS['EUR'] === $m['material_no'] ? 1 : 0;
			$out[] = array( 'material_id' => $mid, 'no' => $m['material_no'], 'designation' => $m['designation'], 'good' => $ret - $bad, 'damaged' => $bad );
		}
		return array( 'policy' => $exchange ? 'Échange 1:1 des supports à la livraison' : 'Retour partiel des vides', 'lines' => $out );
	}

	/** Retour d'emballages vides (622) : vides réutilisables en stock libre, vides endommagés en stock bloqué. */
	public static function receive_empties( Ctx $ctx, int $customer_id, int $material_id, float $good, float $damaged, string $ref, string $text ): string {
		$m = Catalog::material( $material_id );
		if ( ! self::is_packaging( $m ) ) {
			throw new DomainError( 'Choisissez un emballage consigné (palette, cadre, couvercle, fût, IBC, caisse).' );
		}
		$cust = Sales::customer( $customer_id );
		if ( ! $cust ) {
			throw new DomainError( 'Client introuvable.' );
		}
		$total = $good + $damaged;
		if ( $total <= 0 ) {
			throw new DomainError( 'Indiquez la quantité rendue.' );
		}
		$bal = self::balance( $ctx, $customer_id, $material_id );
		if ( $total > $bal + 0.0005 ) {
			throw new DomainError( sprintf( '%s : le client n\'a que %s %s en compte (vous saisissez %s). Vérifiez le bon de retour.', $m['designation'], Catalog::format_qty( $bal ), $m['base_uom'], Catalog::format_qty( $total ) ) );
		}
		[ $loc, $bin ] = self::empties_bin( $ctx );
		$lines         = array();
		if ( $good > 0 ) {
			$lines[] = array( 'movement_type' => '622', 'material_id' => $material_id, 'location_id' => (int) $loc['id'], 'bin_id' => $bin, 'batch' => '', 'bbd' => null, 'status' => 'LIBRE', 'qty' => $good, 'reason' => 'Vide rendu par ' . $cust['customer_no'] );
		}
		if ( $damaged > 0 ) {
			$lines[] = array( 'movement_type' => '622', 'material_id' => $material_id, 'location_id' => (int) $loc['id'], 'bin_id' => $bin, 'batch' => '', 'bbd' => null, 'status' => 'BLOQUE', 'qty' => $damaged, 'reason' => 'Vide endommagé (à réparer ou détruire)' );
		}
		$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'MB58', 'ref_no' => mb_substr( $ref, 0, 20 ), 'text' => mb_substr( $text, 0, 255 ) ), $lines );
		self::account( $ctx, $customer_id, $material_id, -$total, '622', $damaged, $ref, $text );
		return $doc;
	}
}
