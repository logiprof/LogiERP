<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventaire (MI01 / MI04 / MI07) : document, gel des emplacements, comptage à l'aveugle, écarts 701/702.
 * La « réalité terrain » = stock comptable + écarts cachés injectés par l'enseignant.
 */
final class Counting {

	public const MAX_LINES = 200;

	public static function create( Ctx $ctx, string $scope_type, string $value ): string {
		global $wpdb;
		$st   = Db::t( 'stock' );
		$bt   = Db::t( 'bin' );
		$mt   = Db::t( 'material' );
		$value = strtoupper( trim( $value ) );
		$sql  = "SELECT s.* FROM {$st} s LEFT JOIN {$bt} b ON b.id = s.bin_id JOIN {$mt} m ON m.id = s.material_id WHERE s.session_id = %d AND s.qty > 0";
		$args = array( $ctx->id );
		switch ( $scope_type ) {
			case 'location':
				$loc = Catalog::location_by_code( $value );
				if ( ! $loc ) {
					throw new DomainError( 'Magasin inconnu.' );
				}
				$sql   .= ' AND s.storage_location_id = %d';
				$args[] = (int) $loc['id'];
				$label  = 'Magasin ' . $loc['code'];
				break;
			case 'aisle':
				$sql   .= ' AND b.code LIKE %s';
				$args[] = $wpdb->esc_like( $value ) . '-%';
				$label  = 'Allée ' . $value;
				break;
			case 'material':
				$m = Catalog::material_by_no( $value );
				if ( ! $m ) {
					throw new DomainError( 'Article inconnu.' );
				}
				$sql   .= ' AND s.material_id = %d';
				$args[] = (int) $m['id'];
				$label  = 'Article ' . $m['material_no'];
				break;
			case 'abc':
				if ( ! in_array( $value, array( 'A', 'B', 'C' ), true ) ) {
					throw new DomainError( 'Classe ABC invalide.' );
				}
				$sql   .= ' AND m.abc = %s';
				$args[] = $value;
				$label  = 'Classe ' . $value;
				break;
			default:
				throw new DomainError( 'Périmètre d\'inventaire invalide.' );
		}
		$sql .= ' ORDER BY s.storage_location_id, b.pick_sequence, s.material_id LIMIT ' . ( self::MAX_LINES + 1 );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			throw new DomainError( 'Aucun stock dans ce périmètre.' );
		}
		if ( count( $rows ) > self::MAX_LINES ) {
			throw new DomainError( sprintf( 'Le périmètre dépasse %d lignes : choisissez une allée ou une classe plus restreinte (inventaire tournant).', self::MAX_LINES ) );
		}
		return Db::transaction(
			static function () use ( $ctx, $rows, $label ) {
				$no  = NumberRange::next( $ctx->id, 'INV' );
				$iid = Db::insert( 'inventory_doc', array( 'session_id' => $ctx->id, 'inv_no' => $no, 'scope' => $label, 'status' => 'OUVERT', 'created_at' => Db::now() ) );
				foreach ( $rows as $r ) {
					Db::insert( 'inventory_item', array( 'inv_id' => $iid, 'material_id' => (int) $r['material_id'], 'storage_location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'batch' => (string) $r['batch'], 'book_qty' => (float) $r['qty'] ) );
				}
				return $no;
			}
		);
	}

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'inventory_doc' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND inv_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function items( int $inv_id ): array {
		global $wpdb;
		$t = Db::t( 'inventory_item' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE inv_id = %d ORDER BY id", $inv_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Clé d'écart caché pour une ligne. */
	public static function gap_key( int $material_id, int $loc, int $bin ): string {
		return $material_id . ':' . $loc . ':' . $bin;
	}

	/** Quantité physique réellement présente (vue « terrain »). */
	public static function physical( Ctx $ctx, array $item ): float {
		$gaps = $ctx->doc_map()['gaps'] ?? array();
		$key  = self::gap_key( (int) $item['material_id'], (int) $item['storage_location_id'], (int) $item['bin_id'] );
		return max( 0.0, (float) $item['book_qty'] + (float) ( $gaps[ $key ] ?? 0 ) );
	}

	/** Détail ; la quantité comptable est masquée tant que le document n'est pas compté (comptage à l'aveugle). */
	public static function detail( Ctx $ctx, string $no, bool $reveal_book = false ): array {
		$d = self::by_no( $ctx, $no );
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Document d\'inventaire %s introuvable.', $no ) );
		}
		$counted_all = true;
		$items       = array();
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			if ( null === $i['counted_qty'] ) {
				$counted_all = false;
			}
		}
		$show = $reveal_book || $counted_all || 'OUVERT' !== $d['status'];
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			$m   = Catalog::material( (int) $i['material_id'] );
			$bin = (int) $i['bin_id'] ? Catalog::bin( (int) $i['bin_id'] ) : null;
			$loc = Catalog::location( (int) $i['storage_location_id'] );
			$items[] = array(
				'id'          => (int) $i['id'],
				'material'    => Api::material_summary( $m ),
				'location'    => $loc ? $loc['code'] : '',
				'bin'         => $bin ? $bin['code'] : '',
				'batch'       => $i['batch'],
				'book_qty'    => $show ? (float) $i['book_qty'] : null,
				'counted_qty' => null !== $i['counted_qty'] ? (float) $i['counted_qty'] : null,
				'diff'        => $show && null !== $i['diff'] ? (float) $i['diff'] : null,
				'value'       => $show && null !== $i['diff'] ? round( (float) $i['diff'] * (float) $m['std_price'], 2 ) : null,
				'recount'     => (int) $i['recount'],
			);
		}
		return array( 'inv_no' => $d['inv_no'], 'scope' => $d['scope'], 'status' => $d['status'], 'blind' => ! $show, 'items' => $items );
	}

	/** « Aller compter » : renvoie ce que l'apprenant voit physiquement au rayonnage. */
	public static function look( Ctx $ctx, string $no, int $item_id ): float {
		$d = self::by_no( $ctx, $no );
		if ( ! $d || 'OUVERT' !== $d['status'] ) {
			throw new DomainError( 'Document d\'inventaire introuvable ou déjà comptabilisé.' );
		}
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			if ( (int) $i['id'] === $item_id ) {
				return self::physical( $ctx, $i );
			}
		}
		throw new DomainError( 'Ligne d\'inventaire introuvable.' );
	}

	/** @param array $counts [{item_id, qty}] */
	public static function count( Ctx $ctx, string $no, array $counts ): array {
		global $wpdb;
		$d = self::by_no( $ctx, $no );
		if ( ! $d || 'OUVERT' !== $d['status'] ) {
			throw new DomainError( 'Document d\'inventaire introuvable ou déjà comptabilisé.' );
		}
		$items = array();
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			$items[ (int) $i['id'] ] = $i;
		}
		$recount = 0;
		$t       = Db::t( 'inventory_item' );
		foreach ( $counts as $c ) {
			$id = (int) ( $c['item_id'] ?? 0 );
			if ( ! isset( $items[ $id ] ) || '' === (string) ( $c['qty'] ?? '' ) ) {
				continue;
			}
			$q = round( (float) $c['qty'], 3 );
			if ( $q < 0 || $q > Purchasing::MAX_QTY ) {
				throw new DomainError( 'Quantité comptée invalide.' );
			}
			$diff = round( $q - (float) $items[ $id ]['book_qty'], 3 );
			$need = abs( $diff ) > max( 1.0, 0.05 * (float) $items[ $id ]['book_qty'] ) && ! (int) $items[ $id ]['recount'] && null === $items[ $id ]['counted_qty'];
			$wpdb->update( $t, array( 'counted_qty' => $q, 'diff' => $diff, 'recount' => $need ? 1 : (int) $items[ $id ]['recount'] ), array( 'id' => $id ) );
			if ( $need ) {
				++$recount;
			}
		}
		return array( 'recount' => $recount );
	}

	/** Comptabilisation des écarts (701 / 702) et dégel. */
	public static function post( Ctx $ctx, string $no ): string {
		$d = self::by_no( $ctx, $no );
		if ( ! $d || 'OUVERT' !== $d['status'] ) {
			throw new DomainError( 'Document d\'inventaire introuvable ou déjà comptabilisé.' );
		}
		$lines = array();
		foreach ( self::items( (int) $d['id'] ) as $i ) {
			if ( null === $i['counted_qty'] ) {
				throw new DomainError( 'Toutes les lignes doivent être comptées avant la comptabilisation.' );
			}
			$diff = (float) $i['diff'];
			if ( abs( $diff ) < 0.0005 ) {
				continue;
			}
			$status = 'LIBRE';
			$row    = Warehouse::stock_at( $ctx, (int) $i['material_id'], (int) $i['storage_location_id'], (int) $i['bin_id'], (string) $i['batch'], 'LIBRE' );
			if ( ! $row ) {
				foreach ( array( 'CONTROLE_QUALITE', 'BLOQUE' ) as $s ) {
					if ( Warehouse::stock_at( $ctx, (int) $i['material_id'], (int) $i['storage_location_id'], (int) $i['bin_id'], (string) $i['batch'], $s ) ) {
						$status = $s;
						break;
					}
				}
			}
			$lines[] = array( 'movement_type' => $diff > 0 ? '701' : '702', 'material_id' => (int) $i['material_id'], 'location_id' => (int) $i['storage_location_id'], 'bin_id' => (int) $i['bin_id'], 'batch' => $i['batch'], 'status' => $status, 'qty' => $diff, 'reason' => 'Inventaire ' . $d['inv_no'] );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $lines ) {
				$doc = '';
				if ( $lines ) {
					$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'INV', 'ref_id' => (int) $d['id'], 'ref_no' => $d['inv_no'], 'text' => 'Écarts d\'inventaire' ), $lines );
				}
				Db::update( 'inventory_doc', array( 'status' => 'COMPTABILISE', 'posted_at' => Db::now() ), array( 'id' => (int) $d['id'] ) );
				// Les écarts cachés sont désormais corrigés dans le stock comptable.
				$map = $ctx->doc_map();
				foreach ( self::items( (int) $d['id'] ) as $i ) {
					unset( $map['gaps'][ self::gap_key( (int) $i['material_id'], (int) $i['storage_location_id'], (int) $i['bin_id'] ) ] );
				}
				$ctx->save_doc_map( $map );
				return $doc;
			}
		);
	}

	/** Emplacements gelés par un inventaire ouvert. */
	public static function frozen( int $session_id, int $location_id, int $bin_id, int $material_id ): string {
		global $wpdb;
		$d = Db::t( 'inventory_doc' );
		$i = Db::t( 'inventory_item' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT d.inv_no FROM {$i} i JOIN {$d} d ON d.id = i.inv_id WHERE d.session_id = %d AND d.status = 'OUVERT' AND i.storage_location_id = %d AND i.bin_id = %d AND i.material_id = %d LIMIT 1", $session_id, $location_id, $bin_id, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
