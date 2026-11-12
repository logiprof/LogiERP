<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interventions de l'enseignant pendant une mission (en direct, pour un apprenti ou toute la classe).
 *
 *  - le client modifie sa commande (quantité, ligne ajoutée ou supprimée, date) : refusé si la commande est déjà en préparation ;
 *  - rupture : stock bloqué par la qualité, stock prélevé par un autre service, ou manquant physique caché ;
 *  - nouvelle commande urgente, prioritaire sur les commandes en cours.
 *
 * Chaque intervention est enregistrée (table intervention), notifiée à l'apprenti quand elle est visible dans la réalité
 * (le manquant physique ne l'est pas : il le découvre au prélèvement) et reprise dans le tableau d'indicateurs.
 */
final class Interventions {

	public const TYPES = array(
		'order_change' => 'Le client modifie sa commande',
		'shortage'     => 'Rupture ou stock indisponible',
		'urgent_order' => 'Nouvelle commande urgente',
	);

	public const CHANGES = array(
		'qty'    => 'Modifier la quantité d\'un article',
		'add'    => 'Ajouter un article',
		'remove' => 'Supprimer un article',
		'date'   => 'Avancer ou reporter la date de livraison',
	);

	public const SHORTAGES = array(
		'block'   => 'Bloqué par la qualité (visible dans le système)',
		'issue'   => 'Prélevé par un autre service : rupture (visible dans le système)',
		'missing' => 'Manquant au rayonnage (écart caché, découvert au prélèvement)',
	);

	/** Applique une intervention à une session ; renvoie [ok, message pour l'enseignant]. */
	public static function apply( Ctx $ctx, string $type, array $p, int $assignment_id ): array {
		if ( 'EN_COURS' !== $ctx->session['status'] ) {
			return array( false, 'session déjà remise' );
		}
		try {
			$res = Db::transaction(
				static function () use ( $ctx, $type, $p ) {
					switch ( $type ) {
						case 'order_change':
							return self::order_change( $ctx, $p );
						case 'shortage':
							return self::shortage( $ctx, $p );
						case 'urgent_order':
							return self::urgent_order( $ctx, $p );
					}
					throw new DomainError( 'Intervention inconnue.' );
				}
			);
			$ok = true;
		} catch ( DomainError $e ) {
			$res = array( 'teacher' => $e->getMessage(), 'student' => '' );
			$ok  = false;
		}
		Db::insert(
			'intervention',
			array(
				'assignment_id' => $assignment_id,
				'session_id'    => $ctx->id,
				'type'          => $type,
				'params'        => wp_json_encode( $p ),
				'result'        => $ok ? 'ok' : 'refused',
				'message'       => mb_substr( (string) $res['teacher'], 0, 255 ),
				'created_by'    => get_current_user_id(),
				'created_at'    => Db::now(),
			)
		);
		if ( $ok && '' !== $res['student'] ) {
			Db::insert( 'session_event_log', array( 'session_id' => $ctx->id, 'event_id' => 0, 'type' => 'live_' . $type, 'message' => mb_substr( $res['student'], 0, 255 ), 'seen' => 0, 'fired_at' => Db::now() ) );
		}
		Audit::log( 'intervention', 'session', $ctx->id, array( 'type' => $type, 'ok' => $ok ) );
		return array( $ok, (string) $res['teacher'] );
	}

	/** Commande client de la session à partir de sa référence de scénario (SO1, LIVE1…) ou de son numéro. */
	private static function sales_order( Ctx $ctx, string $ref ): array {
		$no = (string) ( $ctx->doc_map()['refs'][ $ref ]['no'] ?? $ref );
		$so = Sales::by_no( $ctx, $no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'commande %s introuvable', $ref ) );
		}
		return $so;
	}

	private static function order_change( Ctx $ctx, array $p ): array {
		global $wpdb;
		$so = self::sales_order( $ctx, (string) ( $p['ref'] ?? '' ) );
		if ( in_array( $so['status'], array( 'ANNULEE', 'LIVREE' ), true ) ) {
			throw new DomainError( sprintf( 'commande %s %s : trop tard', $so['so_no'], 'ANNULEE' === $so['status'] ? 'annulée' : 'déjà livrée' ) );
		}
		$dl = Db::t( 'outbound_delivery' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$dl} WHERE session_id = %d AND so_id = %d", $ctx->id, (int) $so['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new DomainError( sprintf( 'commande %s déjà en préparation (livraison créée) : modification refusée, comme en entreprise', $so['so_no'] ) );
		}
		$c     = Sales::customer( (int) $so['customer_id'] );
		$who   = $c ? $c['name'] : 'Le client';
		$it    = Db::t( 'sales_order_item' );
		$items = Sales::items( (int) $so['id'] );
		$what  = (string) ( $p['change'] ?? '' );
		$m     = Catalog::material( (int) ( $p['material_id'] ?? 0 ) );
		$line  = null;
		foreach ( $items as $i ) {
			if ( $m && (int) $i['material_id'] === (int) $m['id'] ) {
				$line = $i;
			}
		}
		$qty = round( (float) ( $p['qty'] ?? 0 ), 3 );
		switch ( $what ) {
			case 'qty':
				if ( ! $line ) {
					throw new DomainError( 'article absent de la commande' );
				}
				if ( $qty <= 0 ) {
					throw new DomainError( 'quantité invalide (pour retirer l\'article, choisissez « Supprimer un article »)' );
				}
				if ( abs( $qty - (float) $line['qty'] ) < 0.0005 ) {
					throw new DomainError( 'quantité identique à la commande' );
				}
				$reserve = min( $qty, StockService::available( $ctx->id, (int) $m['id'] ) + (float) $line['qty_reserved'] );
				$wpdb->update( $it, array( 'qty' => $qty, 'qty_reserved' => $reserve ), array( 'id' => (int) $line['id'] ) );
				$detail = sprintf( '%s %s : %s %s au lieu de %s', $m['material_no'], $m['designation'], Catalog::format_qty( $qty ), $m['base_uom'], Catalog::format_qty( (float) $line['qty'] ) );
				break;
			case 'add':
				if ( ! $m || $qty <= 0 ) {
					throw new DomainError( 'article ou quantité invalide' );
				}
				if ( $line ) {
					throw new DomainError( 'article déjà dans la commande : modifiez sa quantité' );
				}
				$next = 10 + (int) max( array_merge( array( 0 ), array_map( static fn( $i ) => (int) $i['line_no'], $items ) ) );
				Db::insert( 'sales_order_item', array( 'so_id' => (int) $so['id'], 'line_no' => $next, 'material_id' => (int) $m['id'], 'qty' => $qty, 'qty_reserved' => min( $qty, StockService::available( $ctx->id, (int) $m['id'] ) ), 'price' => (float) $m['sale_price'] ) );
				$detail = sprintf( 'ajout de %s %s %s', Catalog::format_qty( $qty ), $m['base_uom'], $m['designation'] . ' (' . $m['material_no'] . ')' );
				break;
			case 'remove':
				if ( ! $line ) {
					throw new DomainError( 'article absent de la commande' );
				}
				if ( count( $items ) < 2 ) {
					throw new DomainError( 'dernier article de la commande : utilisez une annulation de commande' );
				}
				$wpdb->delete( $it, array( 'id' => (int) $line['id'] ) );
				$detail = sprintf( 'suppression de %s %s', $m['material_no'], $m['designation'] );
				break;
			case 'date':
				$d = (string) ( $p['date'] ?? '' );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || $d < $ctx->date() ) {
					throw new DomainError( 'date invalide (au plus tôt le jour de la mission)' );
				}
				Db::update( 'sales_order', array( 'req_date' => $d ), array( 'id' => (int) $so['id'] ) );
				$detail = sprintf( 'livraison souhaitée le %s au lieu du %s', Api::fr_date( $d ), Api::fr_date( (string) $so['req_date'] ) );
				break;
			default:
				throw new DomainError( 'modification inconnue' );
		}
		Sales::refresh_status( (int) $so['id'] );
		self::record_change( $ctx, $so['so_no'], $detail );
		return array(
			'teacher' => sprintf( 'commande %s modifiée (%s)', $so['so_no'], $detail ),
			'student' => sprintf( 'Appel du client : %s modifie sa commande %s – %s. Vérifiez la commande (VA03) avant de préparer.', $who, $so['so_no'], $detail ),
		);
	}

	/** Mémorise la modification pour l'avis imprimable (documents logistiques de la session). */
	private static function record_change( Ctx $ctx, string $so_no, string $detail ): void {
		$map                      = $ctx->doc_map();
		$map['changes'][ $so_no ][] = array( 'at' => Db::now(), 'detail' => $detail );
		$ctx->save_doc_map( $map );
	}

	private static function shortage( Ctx $ctx, array $p ): array {
		global $wpdb;
		$m = Catalog::material( (int) ( $p['material_id'] ?? 0 ) );
		if ( ! $m ) {
			throw new DomainError( 'article introuvable' );
		}
		$mode = (string) ( $p['mode'] ?? '' );
		if ( ! isset( self::SHORTAGES[ $mode ] ) ) {
			throw new DomainError( 'type de rupture inconnu' );
		}
		$want = max( 0.0, round( (float) ( $p['qty'] ?? 0 ), 3 ) );
		$st   = Db::t( 'stock' );
		$l    = Db::t( 'storage_location' );
		$b    = Db::t( 'bin' );
		$sql  = "SELECT s.*, b.code AS bin_code FROM {$st} s JOIN {$l} l ON l.id = s.storage_location_id LEFT JOIN {$b} b ON b.id = s.bin_id WHERE s.session_id = %d AND s.material_id = %d AND s.status = 'LIBRE' AND s.qty > 0 AND l.is_transit = 0";
		$args = array( $ctx->id, (int) $m['id'] );
		$code = strtoupper( trim( (string) ( $p['bin_code'] ?? '' ) ) );
		if ( '' !== $code ) {
			$sql   .= ' AND (b.code = %s OR (s.bin_id = 0 AND l.code = %s))';
			$args[] = $code;
			$args[] = $code;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY b.pick_sequence, s.bbd IS NULL, s.bbd, s.id', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			throw new DomainError( sprintf( 'aucun stock libre de %s%s', $m['material_no'], '' !== $code ? ' à ' . $code : '' ) );
		}
		$left  = $want > 0 ? $want : INF;
		$parts = array();
		$lines = array();
		$map   = $ctx->doc_map();
		foreach ( $rows as $r ) {
			if ( $left <= 0.0005 ) {
				break;
			}
			$q = min( (float) $r['qty'], $left );
			if ( 'missing' === $mode ) {
				$key = Counting::gap_key( (int) $m['id'], (int) $r['storage_location_id'], (int) $r['bin_id'] );
				$cur = (float) ( $map['gaps'][ $key ] ?? 0 );
				$q   = min( $q, max( 0.0, (float) $r['qty'] + $cur ) ); // On ne retire pas plus que ce qui reste physiquement.
				if ( $q <= 0.0005 ) {
					continue;
				}
				$map['gaps'][ $key ] = $cur - $q;
			} else {
				$base    = array( 'material_id' => (int) $m['id'], 'location_id' => (int) $r['storage_location_id'], 'bin_id' => (int) $r['bin_id'], 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'gr_date' => $r['gr_date'] );
				$lines[] = $base + array( 'movement_type' => 'block' === $mode ? '344' : '201', 'status' => 'LIBRE', 'qty' => -$q );
				if ( 'block' === $mode ) {
					$lines[] = $base + array( 'movement_type' => '344', 'status' => 'BLOQUE', 'qty' => $q );
				}
			}
			$parts[] = sprintf( '%s %s à %s', Catalog::format_qty( $q ), $m['base_uom'], $r['bin_code'] ?: 'magasin' );
			$left   -= $q;
		}
		if ( ! $parts ) {
			throw new DomainError( 'plus rien à retirer physiquement' );
		}
		$where = implode( ', ', $parts );
		if ( 'missing' === $mode ) {
			$ctx->save_doc_map( $map );
			return array( 'teacher' => sprintf( 'manquant caché : retirez du rayonnage %s de %s (%s)', $where, $m['material_no'], $m['designation'] ), 'student' => '' );
		}
		StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'LIVE', 'ref_id' => 0, 'ref_no' => '', 'text' => 'block' === $mode ? 'Blocage qualité (non-conformité signalée)' : 'Prélèvement urgent par un autre service' ), $lines );
		$student = 'block' === $mode
			? sprintf( 'Contrôle qualité : %s de l\'article %s %s sont bloqués (non-conformité). Ce stock ne peut plus être livré : vérifiez les commandes concernées.', $where, $m['material_no'], $m['designation'] )
			: sprintf( 'Rupture : un autre service a prélevé %s de l\'article %s %s pour une urgence. Vérifiez la disponibilité (MMBE, MD04) et les commandes concernées.', $where, $m['material_no'], $m['designation'] );
		return array( 'teacher' => ( 'block' === $mode ? 'bloqué : ' : 'prélevé : ' ) . $where . ' de ' . $m['material_no'], 'student' => $student );
	}

	private static function urgent_order( Ctx $ctx, array $p ): array {
		$c = Sales::customer( (int) ( $p['customer_id'] ?? 0 ) );
		if ( ! $c ) {
			throw new DomainError( 'client introuvable' );
		}
		$items = array();
		foreach ( (array) ( $p['items'] ?? array() ) as $i ) {
			$m = Catalog::material( (int) ( $i['material_id'] ?? 0 ) );
			$q = round( (float) ( $i['qty'] ?? 0 ), 3 );
			if ( $m && $q > 0 ) {
				$items[] = array( 'material_id' => (int) $m['id'], 'qty' => $q );
			}
		}
		if ( ! $items ) {
			throw new DomainError( 'aucun article' );
		}
		$map = $ctx->doc_map();
		$n   = 1;
		while ( isset( $map['refs'][ 'LIVE' . $n ] ) ) {
			++$n;
		}
		$ref  = 'LIVE' . $n;
		$note = mb_substr( trim( (string) ( $p['note'] ?? '' ) ) ?: 'Commande urgente : à livrer aujourd\'hui, en priorité.', 0, 255 );
		$no   = Sales::create_from_scenario( $ctx, array( 'ref' => $ref, 'partner_id' => (int) $c['id'], 'priority' => 'A', 'note' => $note ), $items, $ctx->date() );
		$map  = $ctx->doc_map();
		$map['refs'][ $ref ] = array( 'type' => 'SO', 'no' => $no );
		$ctx->save_doc_map( $map );
		return array(
			'teacher' => sprintf( 'commande urgente %s créée (%s)', $no, $c['name'] ),
			'student' => sprintf( 'Commande urgente de %s (commande %s, priorité A) : à livrer aujourd\'hui, avant les commandes en cours. %s', $c['name'], $no, $note ),
		);
	}

	/** Interventions d'une session (tableau d'indicateurs, résultats). */
	public static function of_session( int $session_id ): array {
		global $wpdb;
		$t = Db::t( 'intervention' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d ORDER BY id", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Libellé lisible d'une intervention. */
	public static function label( array $row ): string {
		$p = json_decode( (string) $row['params'], true ) ?: array();
		$t = self::TYPES[ $row['type'] ] ?? $row['type'];
		if ( 'order_change' === $row['type'] ) {
			$t .= ' · ' . ( self::CHANGES[ $p['change'] ?? '' ] ?? '' );
		} elseif ( 'shortage' === $row['type'] ) {
			$t .= ' · ' . ( self::SHORTAGES[ $p['mode'] ?? '' ] ?? '' );
		}
		return $t;
	}
}
