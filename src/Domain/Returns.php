<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retours clients (651 → quarantaine 800) et décision : remise en stock (453), rebut (551), retour fournisseur (161).
 */
final class Returns {

	public static function customer_return( Ctx $ctx, string $so_no, int $material_id, float $qty, string $reason, string $batch = '' ): string {
		global $wpdb;
		$so = Sales::by_no( $ctx, $so_no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $so_no ) );
		}
		$si   = Db::t( 'sales_order_item' );
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$si} WHERE so_id = %d AND material_id = %d LIMIT 1", (int) $so['id'], $material_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item || (float) $item['qty_delivered'] <= 0 ) {
			throw new DomainError( 'Cet article n\'a pas été livré sur cette commande.' );
		}
		$rd       = Db::t( 'return_doc' );
		$ri       = Db::t( 'return_item' );
		$returned = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.qty),0) FROM {$ri} i JOIN {$rd} d ON d.id = i.ret_id WHERE d.session_id = %d AND d.type = 'CLIENT' AND d.ref_no = %s AND i.material_id = %d", $ctx->id, $so_no, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $qty <= 0 || $returned + $qty > (float) $item['qty_delivered'] + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité retournée invalide : livré %s, déjà retourné %s.', Catalog::format_qty( (float) $item['qty_delivered'] ), Catalog::format_qty( $returned ) ) );
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif du retour.' );
		}
		$m = Catalog::material( $material_id );
		if ( ! empty( $m['batch_managed'] ) && '' === $batch ) {
			throw new DomainError( 'Article géré par lot : indiquez le lot retourné (voir l\'étiquette).' );
		}
		[ $bin, $loc ] = Receiving::reception_bin( $ctx, '800' );
		return Db::transaction(
			static function () use ( $ctx, $so, $so_no, $m, $qty, $reason, $batch, $bin, $loc ) {
				$no  = NumberRange::next( $ctx->id, 'RET' );
				$rid = Db::insert( 'return_doc', array( 'session_id' => $ctx->id, 'ret_no' => $no, 'type' => 'CLIENT', 'partner_id' => (int) $so['customer_id'], 'ref_no' => $so_no, 'status' => 'RECU', 'created_at' => Db::now() ) );
				Db::insert( 'return_item', array( 'ret_id' => $rid, 'material_id' => (int) $m['id'], 'qty' => $qty, 'batch' => $batch, 'reason' => mb_substr( $reason, 0, 120 ) ) );
				StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'RET', 'ref_id' => $rid, 'ref_no' => $no, 'text' => 'Retour client ' . $so_no ), array( array( 'movement_type' => '651', 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => $ctx->simple() ? 0 : (int) $bin['id'], 'batch' => $batch, 'status' => 'BLOQUE', 'qty' => $qty, 'reason' => mb_substr( $reason, 0, 120 ) ) ) );
				return $no;
			}
		);
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'return_doc' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND ret_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$r = self::by_no( $ctx, $no );
		if ( ! $r ) {
			throw new DomainError( 'Retour introuvable.' );
		}
		$ri    = Db::t( 'return_item' );
		$items = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ri} WHERE ret_id = %d", (int) $r['id'] ), ARRAY_A ) as $i ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items[] = array( 'id' => (int) $i['id'], 'material' => Api::material_summary( Catalog::material( (int) $i['material_id'] ) ), 'qty' => (float) $i['qty'], 'batch' => $i['batch'], 'reason' => $i['reason'], 'decision' => $i['decision'] );
		}
		return array( 'ret_no' => $r['ret_no'], 'type' => $r['type'], 'ref_no' => $r['ref_no'], 'status' => $r['status'], 'items' => $items );
	}

	public static function decide( Ctx $ctx, string $ret_no, int $item_id, string $decision, string $target = '' ): string {
		global $wpdb;
		$r = self::by_no( $ctx, $ret_no );
		if ( ! $r ) {
			throw new DomainError( 'Retour introuvable.' );
		}
		$ri   = Db::t( 'return_item' );
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ri} WHERE id = %d AND ret_id = %d", $item_id, (int) $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item || '' !== $item['decision'] ) {
			throw new DomainError( 'Ligne de retour introuvable ou déjà traitée.' );
		}
		$m   = Catalog::material( (int) $item['material_id'] );
		$loc = Catalog::location_by_code( '800' );
		$qty = (float) $item['qty'];
		// Ligne de stock bloqué en quarantaine.
		$st  = Db::t( 'stock' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$st} WHERE session_id = %d AND material_id = %d AND storage_location_id = %d AND batch = %s AND status = 'BLOQUE' AND qty >= %f ORDER BY ABS(qty - %f) < 0.0005 DESC, qty ASC, id LIMIT 1", $ctx->id, (int) $m['id'], (int) $loc['id'], (string) $item['batch'], $qty - 0.0005, $qty ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			throw new DomainError( 'Le stock retourné n\'est plus en quarantaine (magasin 800).' );
		}
		$src = array( 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'BLOQUE' );
		switch ( $decision ) {
			case 'RESTOCK':
				if ( $row['bbd'] && $row['bbd'] < $ctx->date() ) {
					throw new DomainError( 'Lot périmé : la remise en stock est interdite.' );
				}
				if ( $ctx->simple() ) {
					$dst_loc = Catalog::location_by_code( $target );
					$errors  = $dst_loc ? Rules::check_location( $m, $dst_loc ) : array( 'Magasin de destination inconnu.' );
					$dst_bin = 0;
				} else {
					$bin     = Catalog::bin_by_code( $target );
					$errors  = $bin ? Rules::check_bin( $ctx->id, $m, $bin, $qty, (string) $row['batch'] ) : array( 'Emplacement de destination inconnu.' );
					$dst_loc = $bin ? Catalog::location( (int) $bin['storage_location_id'] ) : null;
					$dst_bin = $bin ? (int) $bin['id'] : 0;
				}
				if ( $errors ) {
					throw new DomainError( 'Remise en stock refusée.', $errors );
				}
				$ctx->require_key_for( $dst_loc );
				$lines = array(
					$src + array( 'movement_type' => '453', 'qty' => -$qty ),
					array( 'movement_type' => '453', 'material_id' => (int) $m['id'], 'location_id' => (int) $dst_loc['id'], 'bin_id' => $dst_bin, 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'LIBRE', 'qty' => $qty ),
				);
				break;
			case 'SCRAP':
				$lines = array( $src + array( 'movement_type' => '551', 'qty' => -$qty, 'reason' => 'Retour client non conforme' ) );
				break;
			case 'SUPPLIER':
				$lines = array( $src + array( 'movement_type' => '161', 'qty' => -$qty, 'reason' => 'Retour au fournisseur' ) );
				break;
			default:
				throw new DomainError( 'Décision inconnue.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $r, $item, $decision, $lines, $wpdb, $ri ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'RET', 'ref_id' => (int) $r['id'], 'ref_no' => $r['ret_no'], 'text' => 'Décision retour ' . $decision ), $lines );
				$wpdb->update( $ri, array( 'decision' => $decision ), array( 'id' => (int) $item['id'] ) );
				$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ri} WHERE ret_id = %d AND decision = ''", (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $left ) {
					Db::update( 'return_doc', array( 'status' => 'TRAITE' ), array( 'id' => (int) $r['id'] ) );
				}
				return $doc;
			}
		);
	}
}
