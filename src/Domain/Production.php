<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production (CO01 / CO11) : ordre de fabrication, consommation 261, déclaration 101.
 */
final class Production {

	public static function bom( int $material_id ): array {
		global $wpdb;
		$b   = Db::t( 'bom' );
		$bi  = Db::t( 'bom_item' );
		$bom = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$b} WHERE material_id = %d ORDER BY id LIMIT 1", $material_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $bom ) {
			return array();
		}
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$bi} WHERE bom_id = %d ORDER BY id", (int) $bom['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'bom' => $bom, 'items' => (array) $items );
	}

	public static function create( Ctx $ctx, int $material_id, float $qty, string $ref = '', string $start = '' ): string {
		$start = '' === $start ? $ctx->date() : $start;
		if ( ! Api::valid_date( $start ) || $start < $ctx->date() || $start > gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +120 days' ) ) ) {
			throw new DomainError( 'Date de début invalide : entre aujourd\'hui et 120 jours.' );
		}
		$m = Catalog::material( $material_id );
		if ( ! $m ) {
			throw new DomainError( 'Article introuvable.' );
		}
		$bom = self::bom( $material_id );
		if ( ! $bom ) {
			throw new DomainError( sprintf( 'L\'article %s n\'a pas de nomenclature : il ne peut pas être fabriqué.', $m['material_no'] ) );
		}
		if ( $qty <= 0 || $qty > 100000 ) {
			throw new DomainError( 'Quantité à fabriquer invalide.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $m, $qty, $bom, $ref, $start ) {
				$no  = NumberRange::next( $ctx->id, 'PROD' );
				$pid = Db::insert( 'production_order', array( 'session_id' => $ctx->id, 'prod_no' => $no, 'scenario_ref' => $ref, 'material_id' => (int) $m['id'], 'qty' => $qty, 'status' => 'CREE', 'start_date' => $start, 'created_at' => Db::now() ) );
				foreach ( $bom['items'] as $bi ) {
					$req = round( $qty * (float) $bi['qty'] * ( 1 + (float) $bi['scrap_pct'] / 100 ), 3 );
					$c   = Catalog::material( (int) $bi['component_id'] );
					if ( $c && 'PCE' === $c['base_uom'] ) {
						$req = ceil( $req );
					}
					Db::insert( 'production_component', array( 'prod_id' => $pid, 'material_id' => (int) $bi['component_id'], 'qty_required' => $req ) );
				}
				return $no;
			}
		);
	}

	public static function create_from_scenario( Ctx $ctx, array $doc, array $items ): string {
		$first = $items[0] ?? array();
		return self::create( $ctx, (int) ( $first['material_id'] ?? 0 ), (float) ( $first['qty'] ?? 1 ), (string) $doc['ref'] );
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'production_order' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND prod_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function components( int $pid ): array {
		global $wpdb;
		$t = Db::t( 'production_component' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE prod_id = %d ORDER BY id", $pid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function detail( Ctx $ctx, string $no ): array {
		$p = self::by_no( $ctx, $no );
		if ( ! $p ) {
			throw new DomainError( sprintf( 'Ordre de fabrication %s introuvable.', $no ) );
		}
		$comps = array();
		foreach ( self::components( (int) $p['id'] ) as $c ) {
			$m       = Catalog::material( (int) $c['material_id'] );
			$comps[] = array( 'id' => (int) $c['id'], 'material' => Api::material_summary( $m ), 'qty_required' => (float) $c['qty_required'], 'qty_issued' => (float) $c['qty_issued'], 'available' => StockService::available( $ctx->id, (int) $c['material_id'] ) + max( 0, (float) $c['qty_required'] - (float) $c['qty_issued'] ) * ( in_array( $p['status'], array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), true ) ? 1 : 0 ) );
		}
		$bom   = self::bom( (int) $p['material_id'] );
		$stage = self::staging_to( $ctx, (int) $p['id'] );
		return array(
			'availability' => in_array( $p['status'], array( 'CREE' ), true ) ? Planning::availability( $ctx, (int) $p['material_id'], (float) $p['qty'], (string) $p['start_date'] ) : array(),
			'staging'    => $stage ? array( 'to_no' => $stage['to_no'], 'status' => $stage['status'] ) : null,
			'prod_no'    => $p['prod_no'],
			'status'     => $p['status'],
			'material'   => Api::material_summary( Catalog::material( (int) $p['material_id'] ) ),
			'qty'        => (float) $p['qty'],
			'qty_done'   => (float) $p['qty_done'],
			'qty_scrap'  => (float) $p['qty_scrap'],
			'start_date' => $p['start_date'],
			'work_center' => $bom ? $bom['bom']['work_center'] : '',
			'components' => $comps,
		);
	}

	public static function release( Ctx $ctx, string $no ): void {
		$p = self::by_no( $ctx, $no );
		if ( ! $p || 'CREE' !== $p['status'] ) {
			throw new DomainError( 'Seul un ordre au statut « créé » peut être lancé.' );
		}
		$missing = array();
		foreach ( self::components( (int) $p['id'] ) as $c ) {
			$avail = StockService::available( $ctx->id, (int) $c['material_id'] );
			if ( $avail + 0.0005 < (float) $c['qty_required'] ) {
				$m         = Catalog::material( (int) $c['material_id'] );
				$missing[] = sprintf( '%s : besoin %s, disponible %s.', $m['material_no'], Catalog::format_qty( (float) $c['qty_required'] ), Catalog::format_qty( $avail ) );
			}
		}
		if ( $missing ) {
			throw new DomainError( 'Lancement impossible : composants manquants. Commandez-les ou réduisez la quantité.', $missing );
		}
		Db::update( 'production_order', array( 'status' => 'LANCE' ), array( 'id' => (int) $p['id'] ) );
	}

	/** Dernier ordre de mise à disposition (LP10) d'un ordre de fabrication. */
	public static function staging_to( Ctx $ctx, int $prod_id ): ?array {
		global $wpdb;
		$t = Db::t( 'transfer_order' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND type = 'STAGE' AND ref_type = 'PROD' AND ref_id = %d ORDER BY id DESC LIMIT 1", $ctx->id, $prod_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/**
	 * Mise à disposition des composants au bord de ligne (LP10) : ordre de transfert depuis les emplacements
	 * de stockage (FIFO / FEFO) vers le magasin 500. En gestion simple, transfert direct (311).
	 */
	public static function stage( Ctx $ctx, string $no ): array {
		$p = self::by_no( $ctx, $no );
		if ( ! $p || ! in_array( $p['status'], array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), true ) ) {
			throw new DomainError( 'Lancez l\'ordre de fabrication (CO02) avant de mettre les composants à disposition.' );
		}
		$open = self::staging_to( $ctx, (int) $p['id'] );
		if ( $open && 'OUVERT' === $open['status'] ) {
			throw new DomainError( sprintf( 'La mise à disposition %s est déjà en cours : confirmez-la (LT12 ou terminal mobile).', $open['to_no'] ) );
		}
		$line_side = Catalog::location_by_code( '500' );
		$store_ids = array();
		foreach ( Catalog::locations() as $l ) {
			if ( empty( $l['is_transit'] ) && 'PROD' !== $l['zone_type'] ) {
				$store_ids[] = (int) $l['id'];
			}
		}
		$plan = array();
		foreach ( self::components( (int) $p['id'] ) as $c ) {
			$m      = Catalog::material( (int) $c['material_id'] );
			$need   = (float) $c['qty_required'] - (float) $c['qty_issued'];
			$at     = Strategies::allocate( $ctx->id, $m, 999999, $ctx->date(), array( 'location_ids' => array( (int) $line_side['id'] ) ) );
			$there  = 999999 - $at['missing'];
			$need  -= $there;
			if ( $need <= 0.0005 ) {
				continue;
			}
			$alloc = Strategies::allocate( $ctx->id, $m, $need, $ctx->date(), array( 'location_ids' => $store_ids ) );
			if ( $alloc['missing'] > 0.0005 ) {
				throw new DomainError( sprintf( 'Composant %s : il manque %s %s en stock libre pour la mise à disposition.', $m['material_no'], Catalog::format_qty( $alloc['missing'] ), $m['base_uom'] ) );
			}
			foreach ( $alloc['alloc'] as $a ) {
				$ctx->require_key_for( Catalog::location( (int) $a['row']['storage_location_id'] ) );
				$plan[] = array( $m, $a['row'], $a['qty'] );
			}
		}
		if ( ! $plan ) {
			throw new DomainError( 'Tous les composants sont déjà au bord de ligne.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $p, $plan, $line_side ) {
				global $wpdb;
				if ( $ctx->simple() ) {
					$lines = array();
					foreach ( $plan as [ $m, $r, $q ] ) {
						$lines[] = array( 'movement_type' => '311', 'material_id' => (int) $m['id'], 'location_id' => (int) $r['storage_location_id'], 'bin_id' => 0, 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'status' => 'LIBRE', 'qty' => -$q );
						$lines[] = array( 'movement_type' => '311', 'material_id' => (int) $m['id'], 'location_id' => (int) $line_side['id'], 'bin_id' => 0, 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'status' => 'LIBRE', 'qty' => $q, 'gr_date' => $r['gr_date'] );
					}
					$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'ref_no' => $p['prod_no'], 'text' => 'Mise à disposition OF ' . $p['prod_no'] ), $lines );
					return array( 'doc_no' => $doc, 'to_no' => '', 'message' => 'Composants transférés au bord de ligne (magasin 500).' );
				}
				$bins  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bin' ) . ' WHERE storage_location_id = %d ORDER BY pick_sequence, code', (int) $line_side['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$to_no = NumberRange::next( $ctx->id, 'TO' );
				$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $to_no, 'type' => 'STAGE', 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'status' => 'OUVERT', 'created_at' => Db::now() ) );
				foreach ( $plan as $k => [ $m, $r, $q ] ) {
					$dst = 0;
					foreach ( $bins as $b ) {
						if ( ! Rules::check_bin( $ctx->id, $m, $b, $q, (string) $r['batch'] ) ) {
							$dst = (int) $b['id'];
							break;
						}
					}
					Db::insert( 'transfer_order_item', array( 'to_id' => $to_id, 'line_no' => $k + 1, 'material_id' => (int) $m['id'], 'batch' => (string) $r['batch'], 'bbd' => $r['bbd'] ?: null, 'stock_status' => 'LIBRE', 'src_location_id' => (int) $r['storage_location_id'], 'src_bin_id' => (int) $r['bin_id'], 'dst_location_id' => (int) $line_side['id'], 'proposed_bin_id' => $dst, 'qty' => $q ) );
				}
				return array( 'doc_no' => $to_no, 'to_no' => $to_no, 'message' => sprintf( 'Ordre de mise à disposition %s créé (%d ligne%s) : prélevez les composants et déposez-les au bord de ligne 500.', $to_no, count( $plan ), count( $plan ) > 1 ? 's' : '' ) );
			}
		);
	}

	/** Consommation des composants (261), en commençant par le bord de ligne. */
	public static function issue_components( Ctx $ctx, string $no ): string {
		$p = self::by_no( $ctx, $no );
		if ( ! $p || ! in_array( $p['status'], array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), true ) ) {
			throw new DomainError( 'Lancez l\'ordre de fabrication avant de consommer les composants.' );
		}
		$stg = self::staging_to( $ctx, (int) $p['id'] );
		if ( $stg && 'OUVERT' === $stg['status'] ) {
			throw new DomainError( sprintf( 'La mise à disposition %s n\'est pas encore confirmée : les composants ne sont pas au bord de ligne.', $stg['to_no'] ) );
		}
		$prod_locs = array();
		foreach ( Catalog::locations() as $l ) {
			if ( 'PROD' === $l['zone_type'] ) {
				$prod_locs[] = (int) $l['id'];
			}
		}
		$lines   = array();
		$updates = array();
		foreach ( self::components( (int) $p['id'] ) as $c ) {
			$need = (float) $c['qty_required'] - (float) $c['qty_issued'];
			if ( $need <= 0.0005 ) {
				continue;
			}
			$m     = Catalog::material( (int) $c['material_id'] );
			$first = Strategies::allocate( $ctx->id, $m, $need, $ctx->date(), array( 'location_ids' => $prod_locs ) );
			$rest  = $first['missing'] > 0.0005 ? Strategies::allocate( $ctx->id, $m, $first['missing'], $ctx->date(), array( 'reserved' => array_column( array_map( static fn( $a ) => array( (int) $a['row']['id'], $a['qty'] ), $first['alloc'] ), 1, 0 ) ) ) : array( 'alloc' => array(), 'missing' => 0 );
			if ( $rest['missing'] > 0.0005 ) {
				throw new DomainError( sprintf( 'Composant %s : il manque %s %s en stock libre.', $m['material_no'], Catalog::format_qty( $rest['missing'] ), $m['base_uom'] ) );
			}
			foreach ( array_merge( $first['alloc'], $rest['alloc'] ) as $a ) {
				$loc = Catalog::location( (int) $a['row']['storage_location_id'] );
				$ctx->require_key_for( $loc );
				$lines[] = array( 'movement_type' => '261', 'material_id' => (int) $m['id'], 'location_id' => (int) $a['row']['storage_location_id'], 'bin_id' => (int) $a['row']['bin_id'], 'batch' => $a['row']['batch'], 'bbd' => $a['row']['bbd'], 'status' => 'LIBRE', 'qty' => -$a['qty'] );
			}
			$updates[ (int) $c['id'] ] = (float) $c['qty_required'];
		}
		if ( ! $lines ) {
			throw new DomainError( 'Tous les composants sont déjà consommés.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $p, $lines, $updates ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'ref_no' => $p['prod_no'], 'text' => 'Consommation OF ' . $p['prod_no'] ), $lines );
				foreach ( $updates as $id => $q ) {
					Db::update( 'production_component', array( 'qty_issued' => $q ), array( 'id' => $id ) );
				}
				return $doc;
			}
		);
	}

	/** Déclaration de production (101) et entreposage du produit fini. */
	public static function confirm( Ctx $ctx, string $no, float $good, float $scrap ): array {
		$p = self::by_no( $ctx, $no );
		if ( ! $p || ! in_array( $p['status'], array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), true ) ) {
			throw new DomainError( 'Seul un ordre lancé peut être confirmé.' );
		}
		foreach ( self::components( (int) $p['id'] ) as $c ) {
			if ( (float) $c['qty_issued'] + 0.0005 < (float) $c['qty_required'] ) {
				throw new DomainError( 'Consommez d\'abord les composants (mouvement 261) avant de déclarer la production.' );
			}
		}
		$open = (float) $p['qty'] - (float) $p['qty_done'] - (float) $p['qty_scrap'];
		if ( $good < 0 || $scrap < 0 || $good + $scrap <= 0 || $good + $scrap > $open + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantités invalides : il reste %s à déclarer (bonnes + rebut).', Catalog::format_qty( $open ) ) );
		}
		$m = Catalog::material( (int) $p['material_id'] );
		return Db::transaction(
			static function () use ( $ctx, $p, $m, $good, $scrap, $open ) {
				$doc   = '';
				$to_no = '';
				if ( $good > 0 ) {
					if ( $ctx->simple() ) {
						$dst = null;
						foreach ( Catalog::locations() as $l ) {
							if ( empty( $l['is_transit'] ) && ! Rules::check_location( $m, $l ) ) {
								$dst = $l;
								break;
							}
						}
						$bin_id = 0;
					} else {
						[ $bin, $dst ] = Receiving::reception_bin( $ctx );
						$bin_id        = (int) $bin['id'];
					}
					$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'ref_no' => $p['prod_no'], 'text' => 'Entrée de production' ), array( array( 'movement_type' => '101', 'material_id' => (int) $m['id'], 'location_id' => (int) $dst['id'], 'bin_id' => $bin_id, 'batch' => '', 'status' => 'LIBRE', 'qty' => $good ) ) );
					if ( ! $ctx->simple() ) {
						$res   = Warehouse::create_putaway( $ctx, 'PROD', (int) $p['id'], array( array( 'm' => $m, 'qty' => $good, 'batch' => '', 'bbd' => null, 'status' => 'LIBRE', 'src_location_id' => (int) $dst['id'], 'src_bin_id' => $bin_id ) ) );
						$to_no = $res['to_no'];
					}
				}
				$done  = (float) $p['qty_done'] + $good;
				$scr   = (float) $p['qty_scrap'] + $scrap;
				$state = $good + $scrap + 0.0005 >= $open ? 'TERMINE' : 'PARTIELLEMENT_CONFIRME';
				Db::update( 'production_order', array( 'qty_done' => $done, 'qty_scrap' => $scr, 'status' => $state ), array( 'id' => (int) $p['id'] ) );
				return array( 'doc_no' => $doc, 'to_no' => $to_no, 'status' => $state );
			}
		);
	}
}
