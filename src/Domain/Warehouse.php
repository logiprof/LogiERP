<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordres de transfert (LT01 / LT12) : entreposage, prélèvement, réapprovisionnement, transfert interne.
 */
final class Warehouse {

	/** Emplacements déjà promis à un ordre de transfert ouvert (évite deux propositions identiques). */
	private static function pending_bins( Ctx $ctx ): array {
		global $wpdb;
		$t = Db::t( 'transfer_order' );
		$i = Db::t( 'transfer_order_item' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.proposed_bin_id FROM {$i} i JOIN {$t} t ON t.id = i.to_id WHERE t.session_id = %d AND t.status = 'OUVERT' AND t.type IN ('PUTAWAY','REPL','MOVE') AND i.proposed_bin_id > 0", $ctx->id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Crée un ordre d'entreposage avec proposition d'emplacement pour chaque ligne.
	 *
	 * @param array $lines [{m, qty, batch, bbd, status, src_location_id, src_bin_id}]
	 */
	public static function create_putaway( Ctx $ctx, string $ref_type, int $ref_id, array $lines ): array {
		$no       = NumberRange::next( $ctx->id, 'TO' );
		$to_id    = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $no, 'type' => 'PUTAWAY', 'ref_type' => $ref_type, 'ref_id' => $ref_id, 'status' => 'OUVERT', 'created_at' => Db::now() ) );
		$messages = array();
		$taken    = self::pending_bins( $ctx );
		$line_no  = 0;
		foreach ( array_values( $lines ) as $l ) {
			// Une unité de stock par emplacement : si la quantité ne tient dans aucun emplacement, elle est répartie (palettisation).
			foreach ( self::split_for_putaway( $ctx, $l, $taken, $messages ) as $part ) {
				Db::insert(
					'transfer_order_item',
					array(
						'to_id'           => $to_id,
						'line_no'         => ++$line_no,
						'material_id'     => (int) $l['m']['id'],
						'batch'           => (string) $l['batch'],
						'bbd'             => $l['bbd'] ?: null,
						'stock_status'    => (string) $l['status'],
						'src_location_id' => (int) $l['src_location_id'],
						'src_bin_id'      => (int) $l['src_bin_id'],
						'dst_location_id' => $part['loc'],
						'proposed_bin_id' => $part['bin'],
						'qty'             => $part['qty'],
					)
				);
			}
		}
		return array( 'to_no' => $no, 'messages' => $messages );
	}

	/**
	 * Propositions d'emplacement pour une ligne d'entreposage : la quantité entière si un emplacement l'accepte,
	 * sinon des unités de stock successives de la plus grande quantité acceptée (au plus 20 lignes).
	 *
	 * @return array<int,array{qty:float,bin:int,loc:int}>
	 */
	private static function split_for_putaway( Ctx $ctx, array $l, array &$taken, array &$messages ): array {
		$left  = (float) $l['qty'];
		$parts = array();
		$err   = '';
		while ( $left > 0.0005 && count( $parts ) < 20 ) {
			$try = $left;
			$p   = null;
			while ( null === $p ) {
				try {
					$p = Strategies::propose_putaway( $ctx->id, $l['m'], $try, (string) $l['batch'], $ctx->rng(), array( 'exclude_bin_ids' => $taken ) );
				} catch ( DomainError $e ) {
					$err = '' === $err ? $e->getMessage() : $err;
					if ( $try <= 1 ) {
						break;
					}
					$try = max( 1.0, floor( $try / 2 ) );
				}
			}
			if ( null === $p ) {
				break;
			}
			// Affinage : la plus grande quantité entière qui tient (entre la dernière réussite et le dernier échec).
			$hi = $try < $left ? min( $left, $try * 2 ) : $try;
			while ( $hi - $try > 1 ) {
				$mid = floor( ( $try + $hi ) / 2 );
				try {
					$p   = Strategies::propose_putaway( $ctx->id, $l['m'], $mid, (string) $l['batch'], $ctx->rng(), array( 'exclude_bin_ids' => $taken ) );
					$try = $mid;
				} catch ( DomainError $e ) {
					$hi = $mid;
				}
			}
			$taken[] = (int) $p['bin']['id'];
			$parts[] = array( 'qty' => $try, 'bin' => (int) $p['bin']['id'], 'loc' => (int) $p['location']['id'] );
			$left   -= $try;
		}
		if ( $left > 0.0005 ) {
			$parts[] = array( 'qty' => $left, 'bin' => 0, 'loc' => 0 );
			$messages[] = $err ?: 'Aucun emplacement proposé pour une partie de la quantité : choisissez-le à la confirmation (LT12).';
		} elseif ( count( $parts ) > 1 ) {
			$messages[] = sprintf( 'Article %s : quantité répartie sur %d emplacements (capacité d\'un emplacement dépassée).', $l['m']['material_no'], count( $parts ) );
		}
		return $parts;
	}

	public const TYPES = array( 'PUTAWAY' => 'Entreposage', 'PICK' => 'Prélèvement', 'REPL' => 'Réapprovisionnement', 'MOVE' => 'Transfert interne', 'STAGE' => 'Mise à disposition production', 'SAMPLE' => 'Prélèvement d\'échantillon' );

	public static function by_no( Ctx $ctx, string $no ): ?array {
		global $wpdb;
		$t = Db::t( 'transfer_order' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND to_no = %s", $ctx->id, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	public static function items( int $to_id ): array {
		global $wpdb;
		$i = Db::t( 'transfer_order_item' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$i} WHERE to_id = %d ORDER BY line_no", $to_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function detail( Ctx $ctx, string $no ): array {
		$to = self::by_no( $ctx, $no );
		if ( ! $to ) {
			throw new DomainError( sprintf( 'Ordre de transfert %s introuvable.', $no ) );
		}
		$items = array();
		foreach ( self::items( (int) $to['id'] ) as $i ) {
			$m     = Catalog::material( (int) $i['material_id'] );
			$srcb  = (int) $i['src_bin_id'] ? Catalog::bin( (int) $i['src_bin_id'] ) : null;
			$srcl  = Catalog::location( (int) $i['src_location_id'] );
			$prop  = (int) $i['proposed_bin_id'] ? Catalog::bin( (int) $i['proposed_bin_id'] ) : null;
			$dstb  = (int) $i['dst_bin_id'] ? Catalog::bin( (int) $i['dst_bin_id'] ) : null;
			$items[] = array(
				'id'            => (int) $i['id'],
				'line_no'       => (int) $i['line_no'],
				'material'      => Api::material_summary( $m ),
				'batch'         => $i['batch'],
				'bbd'           => $i['bbd'],
				'stock_status'  => $i['stock_status'],
				'qty'           => (float) $i['qty'],
				'qty_confirmed' => (float) $i['qty_confirmed'],
				'src'           => $srcb ? $srcb['code'] : ( $srcl ? $srcl['code'] : '' ),
				'proposed'      => $prop ? $prop['code'] : '',
				'proposed_info' => $prop ? Api::bin_summary( $prop ) : null,
				'dst'           => $dstb ? $dstb['code'] : '',
				'strategy_ok'   => (int) $i['strategy_ok'],
			);
		}
		$labels = self::TYPES;
		return array(
			'to_no'      => $to['to_no'],
			'type'       => $to['type'],
			'type_label' => $labels[ $to['type'] ] ?? $to['type'],
			'status'     => $to['status'],
			'ref_type'   => $to['ref_type'],
			'ref_no'     => self::ref_no( $ctx, $to ),
			'sector'     => (string) $to['sector'],
			'picker'     => (string) $to['picker'],
			'created_at' => $to['created_at'],
			'items'      => $items,
		);
	}

	private static function ref_no( Ctx $ctx, array $to ): string {
		global $wpdb;
		$map = array( 'GR' => array( 'purchase_order', 'po_no' ), 'DLV' => array( 'outbound_delivery', 'dlv_no' ), 'PROD' => array( 'production_order', 'prod_no' ), 'RET' => array( 'return_doc', 'ret_no' ), 'QL' => array( 'inspection_lot', 'lot_no' ) );
		if ( ! isset( $map[ $to['ref_type'] ] ) ) {
			return '';
		}
		$t = Db::t( $map[ $to['ref_type'] ][0] );
		$c = $map[ $to['ref_type'] ][1];
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$c} FROM {$t} WHERE id = %d AND session_id = %d", (int) $to['ref_id'], $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function list_open( Ctx $ctx, string $type = '' ): array {
		global $wpdb;
		$t    = Db::t( 'transfer_order' );
		$i    = Db::t( 'transfer_order_item' );
		$sql  = "SELECT t.id, t.to_no, t.type, t.status, t.ref_type, t.ref_id, t.sector, t.picker, t.created_at, COUNT(i.id) n_lines FROM {$t} t LEFT JOIN {$i} i ON i.to_id = t.id WHERE t.session_id = %d AND t.status = 'OUVERT'";
		$args = array( $ctx->id );
		if ( isset( self::TYPES[ $type ] ) ) {
			$sql   .= ' AND t.type = %s';
			$args[] = $type;
		}
		$sql .= ' GROUP BY t.id ORDER BY t.id LIMIT 100';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$cust = '';
			$prio = '';
			if ( 'DLV' === $r['ref_type'] ) {
				$c = $wpdb->get_row( $wpdb->prepare( 'SELECT c.name, o.priority FROM ' . Db::t( 'outbound_delivery' ) . ' d JOIN ' . Db::t( 'sales_order' ) . ' o ON o.id = d.so_id JOIN ' . Db::t( 'customer' ) . ' c ON c.id = o.customer_id WHERE d.id = %d', (int) $r['ref_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cust = $c ? (string) $c['name'] : '';
				$prio = $c ? (string) $c['priority'] : '';
			}
			$out[] = array(
				'to_no'    => $r['to_no'],
				'type'     => $r['type'],
				'status'   => $r['status'],
				'created_at' => $r['created_at'],
				'n_lines'  => (int) $r['n_lines'],
				'ref_no'   => self::ref_no( $ctx, $r ),
				'sector'   => (string) $r['sector'],
				'picker'   => (string) $r['picker'],
				'customer' => $cust,
				'priority' => $prio,
			);
		}
		return $out;
	}

	private static function check_scan( array $m, string $scan, int $line ): void {
		$scan = strtoupper( trim( $scan ) );
		if ( '' === $scan ) {
			throw new DomainError( sprintf( 'Ligne %d : scannez (saisissez) le code de l\'article pour confirmer.', $line ) );
		}
		if ( $scan !== strtoupper( $m['material_no'] ) && $scan !== $m['ean'] ) {
			throw new DomainError( sprintf( 'Ligne %d : le code scanné ne correspond pas à l\'article %s. Vérifiez l\'étiquette.', $line, $m['material_no'] ) );
		}
	}

	/**
	 * Confirme un ordre de transfert.
	 *
	 * @param array $input [{item_id, bin_code, qty, scan}]
	 */
	public static function confirm( Ctx $ctx, string $to_no, array $input ): array {
		$to = self::by_no( $ctx, $to_no );
		if ( ! $to ) {
			throw new DomainError( sprintf( 'Ordre de transfert %s introuvable.', $to_no ) );
		}
		if ( 'OUVERT' !== $to['status'] ) {
			throw new DomainError( sprintf( 'L\'ordre de transfert %s est déjà %s.', $to_no, strtolower( $to['status'] ) ) );
		}
		$by_id = array();
		foreach ( $input as $in ) {
			$by_id[ (int) ( $in['item_id'] ?? 0 ) ] = $in;
		}
		$items = self::items( (int) $to['id'] );
		return Db::transaction(
			static function () use ( $ctx, $to, $items, $by_id ) {
				global $wpdb;
				$lines    = array();
				$messages = array();
				$updates  = array();
				foreach ( $items as $it ) {
					$ln = (int) $it['line_no'];
					$in = $by_id[ (int) $it['id'] ] ?? null;
					if ( null === $in ) {
						throw new DomainError( sprintf( 'Ligne %d : confirmez la ligne ou indiquez une quantité 0.', $ln ) );
					}
					$m   = Catalog::material( (int) $it['material_id'] );
					$qty = round( (float) ( $in['qty'] ?? 0 ), 3 );
					if ( $qty < 0 || $qty > (float) $it['qty'] + 0.0005 ) {
						throw new DomainError( sprintf( 'Ligne %d : la quantité confirmée doit être comprise entre 0 et %s.', $ln, Catalog::format_qty( (float) $it['qty'] ) ) );
					}
					if ( $qty <= 0.0005 ) {
						$updates[] = array( (int) $it['id'], 0.0, (int) $it['dst_bin_id'], 1, null );
						$messages[] = sprintf( 'Ligne %d : rien n\'a été déplacé (quantité 0).', $ln );
						continue;
					}
					self::check_scan( $m, (string) ( $in['scan'] ?? '' ), $ln );
					$bin = Catalog::bin_by_code( (string) ( $in['bin_code'] ?? '' ) );
					if ( ! $bin ) {
						throw new DomainError( sprintf( 'Ligne %d : emplacement « %s » inconnu.', $ln, sanitize_text_field( (string) ( $in['bin_code'] ?? '' ) ) ) );
					}
					$loc = Catalog::location( (int) $bin['storage_location_id'] );
					if ( 'SAMPLE' === $to['type'] ) {
						if ( (int) $bin['id'] !== (int) $it['src_bin_id'] ) {
							throw new DomainError( sprintf( 'Ligne %d : prélevez l\'échantillon à l\'emplacement du lot (%s).', $ln, ( Catalog::bin( (int) $it['src_bin_id'] ) ?: array( 'code' => '?' ) )['code'] ) );
						}
						$lot = Quality::lot_by_id( $ctx, (int) $to['ref_id'] );
						$row = self::stock_at( $ctx, (int) $it['material_id'], (int) $it['src_location_id'], (int) $it['src_bin_id'], (string) $it['batch'], (string) $it['stock_status'] );
						if ( ! $lot || ! $row ) {
							throw new DomainError( 'Le stock du lot de contrôle est introuvable à cet emplacement.' );
						}
						Quality::post_sample( $ctx, $lot, $row, $qty );
						$updates[] = array( (int) $it['id'], $qty, (int) $it['src_bin_id'], 1, null );
						$messages[] = 'Échantillon remis au laboratoire : le rapport d\'analyse est disponible dans QA11.';
						continue;
					}
					if ( 'PICK' === $to['type'] ) {
						if ( $ctx->check_digits() && Catalog::check_digit( (string) $bin['code'] ) !== preg_replace( '/\D/', '', (string) ( $in['check'] ?? '' ) ) ) {
							throw new DomainError( sprintf( 'Ligne %d : chiffre de contrôle incorrect pour l\'emplacement %s. Rendez-vous à l\'emplacement et lisez les deux chiffres imprimés sur son étiquette.', $ln, $bin['code'] ) );
						}
						$r = self::pick_line( $ctx, $it, $m, $bin, $loc, $qty, (string) ( $in['batch'] ?? '' ) );
						$lines      = array_merge( $lines, $r['lines'] );
						$updates[]  = array( (int) $it['id'], $qty, (int) $it['dst_bin_id'], $r['strategy_ok'], (int) $bin['id'] );
						if ( ! $r['strategy_ok'] ) {
							$messages[] = sprintf( 'Ligne %d : prélèvement hors stratégie (%s), écart tracé.', $ln, Strategies::is_fefo( $m ) ? 'FEFO' : 'FIFO' );
						}
						continue;
					}
					// Entreposage, réapprovisionnement, transfert : destination = emplacement saisi.
					$ctx->require_key_for( $loc );
					$src_loc = Catalog::location( (int) $it['src_location_id'] );
					$ctx->require_key_for( $src_loc );
					$errors = Rules::check_bin( $ctx->id, $m, $bin, $qty, (string) $it['batch'] );
					if ( (int) $bin['id'] === (int) $it['src_bin_id'] ) {
						$errors[] = 'L\'emplacement de destination est identique à la source.';
					}
					if ( $errors ) {
						throw new DomainError( sprintf( 'Ligne %d : l\'emplacement %s ne convient pas.', $ln, $bin['code'] ), $errors );
					}
					$src_bin = (int) $it['src_bin_id'] ? Catalog::bin( (int) $it['src_bin_id'] ) : null;
					if ( $src_bin && '' !== Rules::bin_blocked( $ctx->id, $src_bin, 'out' ) ) {
						throw new DomainError( sprintf( 'Ligne %d : l\'emplacement source %s est bloqué en sortie.', $ln, $src_bin['code'] ) );
					}
					$mvt     = (int) $it['src_location_id'] === (int) $loc['id'] ? '999' : '311';
					$gr_date = self::src_gr_date( $ctx, $it );
					$lines[] = array( 'movement_type' => $mvt, 'material_id' => (int) $m['id'], 'location_id' => (int) $it['src_location_id'], 'bin_id' => (int) $it['src_bin_id'], 'batch' => $it['batch'], 'bbd' => $it['bbd'], 'status' => $it['stock_status'], 'qty' => -$qty, 'gr_date' => null );
					$lines[] = array( 'movement_type' => $mvt, 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => (int) $bin['id'], 'batch' => $it['batch'], 'bbd' => $it['bbd'], 'status' => $it['stock_status'], 'qty' => $qty, 'gr_date' => $gr_date );
					// Écart à la confirmation : avec un code d'écart, la différence part dans l'aire des écarts (999).
					$diff_code = (string) preg_replace( '/\D/', '', (string) ( $in['diff'] ?? '' ) );
					$diff_qty  = round( (float) $it['qty'] - $qty, 3 );
					if ( '' !== $diff_code && ! isset( StockTx::DIFF_CODES[ $diff_code ] ) ) {
						throw new DomainError( sprintf( 'Ligne %d : code d\'écart %s inconnu (0 perte de citerne, 1 casse, 2 manquant).', $ln, $diff_code ) );
					}
					if ( '' !== $diff_code && $diff_qty <= 0.0005 ) {
						throw new DomainError( sprintf( 'Ligne %d : un code d\'écart ne s\'utilise que si la quantité confirmée est inférieure à la quantité de l\'OT.', $ln ) );
					}
					if ( ! empty( $m['bulk'] ) && $diff_qty > 0.0005 && '' === $diff_code ) {
						throw new DomainError( sprintf( 'Ligne %d : vrac confirmé avec %s %s de moins. Indiquez le code d\'écart 0 (perte de citerne) : le fond non dépotable part dans l\'aire des écarts.', $ln, Catalog::format_qty( $diff_qty ), $m['base_uom'] ) );
					}
					if ( '' !== $diff_code ) {
						[ $dloc, $dbin ] = StockTx::diff_bin();
						$lines[] = array( 'movement_type' => '999', 'material_id' => (int) $m['id'], 'location_id' => (int) $it['src_location_id'], 'bin_id' => (int) $it['src_bin_id'], 'batch' => $it['batch'], 'bbd' => $it['bbd'], 'status' => $it['stock_status'], 'qty' => -$diff_qty, 'gr_date' => null, 'reason' => StockTx::DIFF_CODES[ $diff_code ] );
						$lines[] = array( 'movement_type' => '999', 'material_id' => (int) $m['id'], 'location_id' => (int) $dloc['id'], 'bin_id' => $dbin ? (int) $dbin['id'] : 0, 'batch' => $it['batch'], 'bbd' => $it['bbd'], 'status' => $it['stock_status'], 'qty' => $diff_qty, 'gr_date' => $gr_date, 'reason' => StockTx::DIFF_CODES[ $diff_code ] );
						$wpdb->update( Db::t( 'transfer_order_item' ), array( 'diff_code' => $diff_code, 'qty_diff' => $diff_qty ), array( 'id' => (int) $it['id'] ) );
						$messages[] = sprintf( 'Ligne %d : écart de %s %s (%s) placé dans l\'aire des écarts 999, à solder avec LI21.', $ln, Catalog::format_qty( $diff_qty ), $m['base_uom'], StockTx::DIFF_CODES[ $diff_code ] );
						if ( ! empty( $m['bulk'] ) && '0' === $diff_code ) {
							$expected = self::tank_loss( $ctx, $to, $m );
							if ( is_array( $expected ) && abs( $expected['qty'] - $diff_qty ) > 0.5 ) {
								$messages[] = sprintf( 'Attention : la perte de citerne prévue est de %s kg (%s), vous en avez déduit %s.', Catalog::format_qty( $expected['qty'] ), $expected['mode'], Catalog::format_qty( $diff_qty ) );
							}
						}
					}
					$updates[] = array( (int) $it['id'], $qty, (int) $bin['id'], 1, null );
					if ( (int) $it['proposed_bin_id'] && (int) $it['proposed_bin_id'] !== (int) $bin['id'] ) {
						$messages[] = sprintf( 'Ligne %d : emplacement %s choisi au lieu de la proposition (contrôlé conforme).', $ln, $bin['code'] );
					}
					if ( $qty < (float) $it['qty'] - 0.0005 && '' === $diff_code ) {
						$messages[] = sprintf( 'Ligne %d : écart de %s %s, le reste demeure à l\'emplacement source.', $ln, Catalog::format_qty( (float) $it['qty'] - $qty ), $m['base_uom'] );
					}
				}
				$doc = '';
				if ( $lines ) {
					$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'TO', 'ref_id' => (int) $to['id'], 'ref_no' => $to['to_no'], 'text' => 'Confirmation OT ' . $to['to_no'] ), $lines );
				}
				$it_t = Db::t( 'transfer_order_item' );
				foreach ( $updates as [ $id, $q, $dst, $ok, $src ] ) {
					$data = array( 'qty_confirmed' => $q, 'dst_bin_id' => $dst, 'strategy_ok' => $ok );
					if ( null !== $src ) {
						$data['src_bin_id'] = $src;
					}
					$wpdb->update( $it_t, $data, array( 'id' => $id ) );
				}
				Db::update( 'transfer_order', array( 'status' => 'CONFIRME', 'confirmed_at' => Db::now(), 'confirmed_by' => get_current_user_id() ), array( 'id' => (int) $to['id'] ) );
				if ( 'PICK' === $to['type'] ) {
					Sales::after_pick( $ctx, (int) $to['ref_id'] );
				}
				return array( 'doc_no' => $doc, 'messages' => $messages );
			}
		);
	}

	/** Perte de citerne prévue (fond non dépotable) selon le moyen de transport de la commande. */
	public static function tank_loss( Ctx $ctx, array $to, array $m ) {
		global $wpdb;
		$transport = '';
		if ( 'GR' === $to['ref_type'] ) {
			$transport = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT transport FROM ' . Db::t( 'purchase_order' ) . ' WHERE id = %d AND session_id = %d', (int) $to['ref_id'], $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wagon = 'WAGON' === $transport;
		$qty   = (float) ( $wagon ? $m['tank_loss_wagon'] : $m['tank_loss_truck'] );
		return $qty > 0 ? array( 'qty' => $qty, 'mode' => $wagon ? 'wagon-citerne' : 'camion-citerne' ) : 0;
	}

	private static function src_gr_date( Ctx $ctx, array $it ): ?string {
		$row = self::stock_at( $ctx, (int) $it['material_id'], (int) $it['src_location_id'], (int) $it['src_bin_id'], (string) $it['batch'], (string) $it['stock_status'] );
		return $row ? $row['gr_date'] : null;
	}

	public static function stock_at( Ctx $ctx, int $material_id, int $loc, int $bin, string $batch, string $status ): ?array {
		global $wpdb;
		$t = Db::t( 'stock' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d AND material_id = %d AND storage_location_id = %d AND bin_id = %d AND batch = %s AND status = %s", $ctx->id, $material_id, $loc, $bin, $batch, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/** Prélèvement : la source est l'emplacement scanné ; contrôle de la stratégie FIFO / FEFO. */
	private static function pick_line( Ctx $ctx, array $it, array $m, array $bin, array $loc, float $qty, string $batch ): array {
		global $wpdb;
		$ln = (int) $it['line_no'];
		$ctx->require_key_for( $loc );
		$t    = Db::t( 'stock' );
		$sql  = "SELECT * FROM {$t} WHERE session_id = %d AND material_id = %d AND bin_id = %d AND status = 'LIBRE' AND qty > 0";
		$args = array( $ctx->id, (int) $m['id'], (int) $bin['id'] );
		$batch = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $batch ) );
		if ( '' !== $batch ) {
			$sql   .= ' AND batch = %s';
			$args[] = $batch;
		} elseif ( (int) $bin['id'] === (int) $it['src_bin_id'] && '' !== (string) $it['batch'] ) {
			$sql   .= ' AND batch = %s';
			$args[] = $it['batch'];
		}
		$sql .= ' ORDER BY bbd IS NULL, bbd, gr_date, id LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			throw new DomainError( sprintf( 'Ligne %d : aucun stock libre de l\'article %s à l\'emplacement %s.', $ln, $m['material_no'], $bin['code'] ) );
		}
		if ( '' !== Rules::bin_blocked( $ctx->id, $bin, 'out' ) ) {
			throw new DomainError( sprintf( 'Ligne %d : l\'emplacement %s est bloqué en sortie.', $ln, $bin['code'] ) );
		}
		if ( $row['bbd'] && $row['bbd'] < $ctx->date() ) {
			throw new DomainError( sprintf( 'Ligne %d : le lot %s est périmé (DLC %s) : interdit à la livraison. Signalez-le et bloquez ce stock.', $ln, $row['batch'], Api::fr_date( $row['bbd'] ) ) );
		}
		if ( (float) $row['qty'] + 0.0005 < $qty ) {
			throw new DomainError( sprintf( 'Ligne %d : seulement %s %s à l\'emplacement %s.', $ln, Catalog::format_qty( (float) $row['qty'] ), $m['base_uom'], $bin['code'] ) );
		}
		// Écart caché (manquant physique) : on ne prélève pas ce qui n'est pas dans le rayonnage.
		$gap = (float) ( ( $ctx->doc_map()['gaps'] ?? array() )[ Counting::gap_key( (int) $m['id'], (int) $row['storage_location_id'], (int) $row['bin_id'] ) ] ?? 0 );
		if ( $gap < 0 && max( 0.0, (float) $row['qty'] + $gap ) + 0.0005 < $qty ) {
			throw new DomainError( sprintf( 'Ligne %d : à l\'emplacement %s, vous ne trouvez que %s %s (le système en annonce %s). Confirmez la quantité réellement prélevée et signalez l\'écart pour un comptage de l\'emplacement (MI01).', $ln, $bin['code'], Catalog::format_qty( max( 0.0, (float) $row['qty'] + $gap ) ), $m['base_uom'], Catalog::format_qty( (float) $row['qty'] ) ) );
		}
		// Comparaison avec la proposition (ligne de stock d'origine si elle existe encore).
		$proposed = self::stock_at( $ctx, (int) $m['id'], (int) $it['src_location_id'], (int) $it['src_bin_id'], (string) $it['batch'], 'LIBRE' );
		$ok       = $proposed ? Strategies::respects_strategy( $m, $row, $proposed ) : true;
		if ( ! $ok && ! $ctx->allow_override() ) {
			$why = Strategies::is_fefo( $m )
				? sprintf( 'FEFO : le lot proposé (DLC %s) doit sortir avant celui-ci (DLC %s).', Api::fr_date( (string) $proposed['bbd'] ), Api::fr_date( (string) $row['bbd'] ) )
				: sprintf( 'FIFO : le stock proposé (entré le %s) doit sortir avant celui-ci (entré le %s).', Api::fr_date( (string) $proposed['gr_date'] ), Api::fr_date( (string) $row['gr_date'] ) );
			throw new DomainError( sprintf( 'Ligne %d : prélèvement refusé. %s', $ln, $why ) );
		}
		$dst_bin = Catalog::bin( (int) $it['dst_bin_id'] );
		$lines   = array(
			array( 'movement_type' => '999', 'material_id' => (int) $m['id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'LIBRE', 'qty' => -$qty ),
			array( 'movement_type' => (int) $row['storage_location_id'] === (int) $it['dst_location_id'] ? '999' : '311', 'material_id' => (int) $m['id'], 'location_id' => (int) $it['dst_location_id'], 'bin_id' => (int) $dst_bin['id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'LIBRE', 'qty' => $qty, 'gr_date' => $row['gr_date'] ),
		);
		// Mémorise le lot réellement prélevé (pour la sortie de marchandises).
		$wpdb->update( Db::t( 'transfer_order_item' ), array( 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'src_location_id' => (int) $row['storage_location_id'] ), array( 'id' => (int) $it['id'] ) );
		return array( 'lines' => $lines, 'strategy_ok' => $ok ? 1 : 0 );
	}

	/** Transfert manuel d'une ligne de stock vers un autre emplacement (création d'un OT). */
	public static function create_move( Ctx $ctx, int $stock_id, float $qty, string $dst_code ): string {
		$row = StockService::row( $ctx->id, $stock_id );
		if ( ! $row ) {
			throw new DomainError( 'Ligne de stock introuvable.' );
		}
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 ) {
			throw new DomainError( 'Quantité invalide pour ce transfert.' );
		}
		$m   = Catalog::material( (int) $row['material_id'] );
		$bin = Catalog::bin_by_code( $dst_code );
		if ( ! $bin ) {
			throw new DomainError( sprintf( 'Emplacement « %s » inconnu.', sanitize_text_field( $dst_code ) ) );
		}
		$errors = Rules::check_bin( $ctx->id, $m, $bin, $qty, (string) $row['batch'] );
		if ( $errors ) {
			throw new DomainError( sprintf( 'L\'emplacement %s ne convient pas.', $bin['code'] ), $errors );
		}
		$no    = NumberRange::next( $ctx->id, 'TO' );
		$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $no, 'type' => 'MOVE', 'status' => 'OUVERT', 'created_at' => Db::now() ) );
		Db::insert(
			'transfer_order_item',
			array(
				'to_id'           => $to_id,
				'line_no'         => 1,
				'material_id'     => (int) $m['id'],
				'batch'           => (string) $row['batch'],
				'bbd'             => $row['bbd'] ?: null,
				'stock_status'    => (string) $row['status'],
				'src_location_id' => (int) $row['storage_location_id'],
				'src_bin_id'      => (int) $row['bin_id'],
				'dst_location_id' => (int) $bin['storage_location_id'],
				'proposed_bin_id' => (int) $bin['id'],
				'qty'             => $qty,
			)
		);
		return $no;
	}

	/** Emplacements de prélèvement fixes sous leur minimum. */
	public static function replenishment_needs( Ctx $ctx ): array {
		global $wpdb;
		$b   = Db::t( 'bin' );
		$l   = Db::t( 'storage_location' );
		$s   = Db::t( 'stock' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.code, b.fixed_material_id, b.min_qty, b.max_qty, COALESCE(SUM(s.qty),0) qty FROM {$b} b JOIN {$l} l ON l.id = b.storage_location_id AND l.zone_type = 'PICK' LEFT JOIN {$s} s ON s.bin_id = b.id AND s.session_id = %d AND s.material_id = b.fixed_material_id WHERE b.fixed_material_id > 0 GROUP BY b.id HAVING qty < b.min_qty ORDER BY b.pick_sequence LIMIT 200", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$m       = Catalog::material( (int) $r['fixed_material_id'] );
			$reserve = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(s.qty),0) FROM {$s} s JOIN {$l} l ON l.id = s.storage_location_id WHERE s.session_id = %d AND s.material_id = %d AND s.status = 'LIBRE' AND l.zone_type <> 'PICK' AND l.is_transit = 0", $ctx->id, (int) $m['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[]   = array( 'bin_id' => (int) $r['id'], 'bin' => $r['code'], 'material' => Api::material_summary( $m ), 'qty' => (float) $r['qty'], 'min' => (float) $r['min_qty'], 'max' => (float) $r['max_qty'], 'reserve' => $reserve );
		}
		return $out;
	}

	public static function create_replenishment( Ctx $ctx, int $bin_id ): string {
		$bin = Catalog::bin( $bin_id );
		if ( ! $bin || ! (int) $bin['fixed_material_id'] ) {
			throw new DomainError( 'Emplacement de prélèvement introuvable.' );
		}
		$loc = Catalog::location( (int) $bin['storage_location_id'] );
		if ( 'PICK' !== $loc['zone_type'] ) {
			throw new DomainError( 'Le réapprovisionnement concerne les emplacements de prélèvement fixes.' );
		}
		$m       = Catalog::material( (int) $bin['fixed_material_id'] );
		$occ     = Rules::bin_occupancy( $ctx->id, $bin_id );
		$need    = max( 0.0, (float) $bin['max_qty'] - (float) ( $occ['qty'][ (int) $m['id'] ] ?? 0 ) );
		$reserve = array();
		foreach ( Catalog::locations() as $l ) {
			if ( 'PICK' !== $l['zone_type'] && empty( $l['is_transit'] ) ) {
				$reserve[] = (int) $l['id'];
			}
		}
		$alloc = Strategies::allocate( $ctx->id, $m, $need, $ctx->date(), array( 'location_ids' => $reserve ) );
		if ( ! $alloc['alloc'] ) {
			throw new DomainError( sprintf( 'Aucun stock de réserve disponible pour l\'article %s.', $m['material_no'] ) );
		}
		$no    = NumberRange::next( $ctx->id, 'TO' );
		$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $no, 'type' => 'REPL', 'status' => 'OUVERT', 'created_at' => Db::now() ) );
		foreach ( $alloc['alloc'] as $i => $a ) {
			Db::insert(
				'transfer_order_item',
				array(
					'to_id'           => $to_id,
					'line_no'         => $i + 1,
					'material_id'     => (int) $m['id'],
					'batch'           => (string) $a['row']['batch'],
					'bbd'             => $a['row']['bbd'] ?: null,
					'stock_status'    => 'LIBRE',
					'src_location_id' => (int) $a['row']['storage_location_id'],
					'src_bin_id'      => (int) $a['row']['bin_id'],
					'dst_location_id' => (int) $loc['id'],
					'proposed_bin_id' => $bin_id,
					'qty'             => $a['qty'],
				)
			);
		}
		return $no;
	}

	/** Réduit un entreposage encore ouvert quand une partie du stock reçu repart (retour fournisseur). */
	public static function cancel_open_putaway_for_row( Ctx $ctx, array $row, float $qty ): void {
		global $wpdb;
		$t     = Db::t( 'transfer_order' );
		$i     = Db::t( 'transfer_order_item' );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT i.*, t.id tid FROM {$i} i JOIN {$t} t ON t.id = i.to_id WHERE t.session_id = %d AND t.status = 'OUVERT' AND t.type = 'PUTAWAY' AND i.material_id = %d AND i.src_bin_id = %d AND i.batch = %s ORDER BY i.id", $ctx->id, (int) $row['material_id'], (int) $row['bin_id'], (string) $row['batch'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $items as $it ) {
			if ( $qty <= 0 ) {
				break;
			}
			$take = min( $qty, (float) $it['qty'] );
			$wpdb->update( $i, array( 'qty' => (float) $it['qty'] - $take ), array( 'id' => (int) $it['id'] ) );
			$qty -= $take;
			$left = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(qty) FROM {$i} WHERE to_id = %d", (int) $it['tid'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $left <= 0.0005 ) {
				$wpdb->update( $t, array( 'status' => 'ANNULE' ), array( 'id' => (int) $it['tid'] ) );
			}
		}
	}
}
