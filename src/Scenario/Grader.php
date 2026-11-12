<?php
declare(strict_types=1);

namespace LogiERP\Scenario;

use LogiERP\Core\Db;
use LogiERP\Domain\Catalog;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\Rules;
use LogiERP\Domain\Counting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Correction automatique : chaque critère est évalué sur l'état final de la session et sur le journal.
 */
final class Grader {

	/** Types de critères disponibles pour l'enseignant. */
	public const TYPES = array(
		'doc_status'          => 'Statut d\'un document du scénario',
		'movement_exists'     => 'Mouvement de stock comptabilisé',
		'stock_delta'         => 'Variation de stock d\'un article',
		'stock_qty'           => 'Quantité en stock (emplacement / statut)',
		'po_received_qty'     => 'Quantité réceptionnée sur une ligne',
		'reserve_recorded'    => 'Réserve ou retour saisi sur une réception',
		'cmr_signed'          => 'Lettre de voiture CMR signée à la réception',
		'photo_documented'    => 'Non-conformités photographiées au terminal mobile',
		'qa_decision'         => 'Lot de contrôle : décision d\'emploi correcte (QA11)',
		'expired_free'        => 'Aucun lot périmé en stock libre',
		'po_ordered'          => 'Commande d\'achat passée pour un article (quantité minimale)',
		'prod_planned'        => 'Ordre de fabrication planifié (quantité, date de début)',
		'staged'              => 'Composants mis à disposition au bord de ligne (LP10)',
		'tours_ok'            => 'Livraisons expédiées par tournées conformes et clôturées',
		'support_ok'          => 'Supports de charge conformes aux exigences des clients',
		'pick_mode'           => 'Mode de préparation adapté (en série ou en parallèle)',
		'consolidated'        => 'Commande regroupée et colisée (unités d\'expédition)',
		'packaging_returns'   => 'Retours d\'emballages consignés saisis selon le bon du chauffeur',
		'nothing_in_zone'     => 'Plus aucun stock dans un magasin (ex. réception)',
		'storage_compliance'  => 'Aucun article stocké hors critères',
		'strategy_respected'  => 'Stratégie FIFO / FEFO respectée',
		'no_expired_shipped'  => 'Aucun lot périmé expédié',
		'carrier_used'        => 'Transporteur correct sur une livraison',
		'inventory_posted'    => 'Inventaire comptabilisé sans écart restant',
		'replenished'         => 'Emplacement de prélèvement réapprovisionné',
		'return_decided'      => 'Retours clients traités',
		'max_errors'          => 'Nombre maximal d\'erreurs',
	);

	public static function checks_of( int $scenario_id ): array {
		global $wpdb;
		$t = Db::t( 'scenario_check' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY id", $scenario_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function grade( Ctx $ctx ): array {
		$report = array();
		$max    = 0.0;
		$score  = 0.0;
		foreach ( self::checks_of( (int) $ctx->scenario['id'] ) as $c ) {
			$p = json_decode( (string) $c['params'], true );
			$p = is_array( $p ) ? $p : array();
			try {
				[ $ok, $detail ] = self::evaluate( $ctx, (string) $c['type'], $p );
			} catch ( \Throwable $e ) {
				$ok     = false;
				$detail = 'Critère non évaluable.';
				\LogiERP\Support\Logger::error( 'Correction, critère ' . $c['id'] . ' : ' . $e->getMessage() );
			}
			$pts      = (float) $c['points'];
			$max     += $pts;
			$score   += $ok ? $pts : 0;
			$report[] = array( 'id' => (int) $c['id'], 'label' => $c['label'], 'ok' => $ok, 'points' => $pts, 'earned' => $ok ? $pts : 0, 'detail' => $detail, 'adjusted' => null, 'comment' => '' );
		}
		$grade = $max > 0 ? round( ( 1 + 5 * $score / $max ) * 2 ) / 2 : null;
		Db::update(
			'session',
			array(
				'score'     => $score,
				'max_score' => $max,
				'grade'     => $grade,
				'report'    => wp_json_encode( $report ),
				'status'    => 'CORRIGEE',
				'graded_at' => Db::now(),
			),
			array( 'id' => $ctx->id )
		);
		return array( 'score' => $score, 'max' => $max, 'grade' => $grade, 'report' => $report );
	}

	/** Recalcule note et score après ajustement manuel de l'enseignant. */
	public static function apply_adjustments( int $session_id, array $report ): array {
		$max   = 0.0;
		$score = 0.0;
		foreach ( $report as $r ) {
			$max   += (float) $r['points'];
			$score += null !== $r['adjusted'] ? (float) $r['adjusted'] : (float) $r['earned'];
		}
		$grade = $max > 0 ? round( ( 1 + 5 * $score / $max ) * 2 ) / 2 : null;
		Db::update( 'session', array( 'score' => $score, 'max_score' => $max, 'grade' => $grade, 'report' => wp_json_encode( $report ) ), array( 'id' => $session_id ) );
		return array( 'score' => $score, 'max' => $max, 'grade' => $grade );
	}

	private static function ref_no( Ctx $ctx, string $ref ): array {
		$r = $ctx->doc_map()['refs'][ $ref ] ?? null;
		return $r ? array( (string) $r['type'], (string) $r['no'] ) : array( '', '' );
	}

	/** Quantité de référence au démarrage (stock de base ou stock initial du scénario). */
	private static function reference_qty( Ctx $ctx, int $material_id, int $location_id, string $status ): float {
		global $wpdb;
		$total = 0.0;
		if ( 'EMPTY' !== $ctx->scenario['initial_stock_mode'] ) {
			$s    = Db::t( 'stock' );
			$sql  = "SELECT COALESCE(SUM(qty),0) FROM {$s} WHERE session_id = 0 AND material_id = %d";
			$args = array( $material_id );
			if ( $location_id ) {
				$sql   .= ' AND storage_location_id = %d';
				$args[] = $location_id;
			}
			if ( '' !== $status ) {
				$sql   .= ' AND status = %s';
				$args[] = $status;
			}
			$total += (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$is   = Db::t( 'scenario_initial_stock' );
		$sql  = "SELECT COALESCE(SUM(qty),0) FROM {$is} WHERE scenario_id = %d AND material_id = %d";
		$args = array( (int) $ctx->scenario['id'], $material_id );
		if ( $location_id ) {
			$sql   .= ' AND storage_location_id = %d';
			$args[] = $location_id;
		}
		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = $status;
		}
		return $total + (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function session_qty( Ctx $ctx, int $material_id, int $location_id, int $bin_id, string $status ): float {
		global $wpdb;
		$s    = Db::t( 'stock' );
		$sql  = "SELECT COALESCE(SUM(qty),0) FROM {$s} WHERE session_id = %d AND material_id = %d";
		$args = array( $ctx->id, $material_id );
		if ( $location_id ) {
			$sql   .= ' AND storage_location_id = %d';
			$args[] = $location_id;
		}
		if ( $bin_id ) {
			$sql   .= ' AND bin_id = %d';
			$args[] = $bin_id;
		}
		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = $status;
		}
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array{0:bool,1:string} */
	public static function evaluate( Ctx $ctx, string $type, array $p ): array {
		global $wpdb;
		$sid = $ctx->id;
		switch ( $type ) {
			case 'doc_status':
				[ $t, $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$table = array( 'PO' => array( 'purchase_order', 'po_no' ), 'SO' => array( 'sales_order', 'so_no' ), 'PROD' => array( 'production_order', 'prod_no' ) )[ $t ] ?? null;
				if ( ! $table ) {
					return array( false, 'Document du scénario introuvable.' );
				}
				$st      = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::t( $table[0] ) . " WHERE session_id = %d AND {$table[1]} = %s", $sid, $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$allowed = (array) ( $p['status'] ?? array() );
				return array( in_array( $st, $allowed, true ), sprintf( 'Document %s : statut %s (attendu : %s).', $no, $st ?: '?', implode( ' ou ', $allowed ) ) );

			case 'movement_exists':
				$d    = Db::t( 'material_doc' );
				$i    = Db::t( 'material_doc_item' );
				$sql  = "SELECT COUNT(*) FROM {$i} i JOIN {$d} d ON d.id = i.mdoc_id WHERE d.session_id = %d AND i.movement_type = %s";
				$args = array( $sid, (string) ( $p['movement'] ?? '' ) );
				if ( ! empty( $p['material_id'] ) ) {
					$sql   .= ' AND i.material_id = %d';
					$args[] = (int) $p['material_id'];
				}
				if ( ! empty( $p['ref'] ) ) {
					[ , $no ] = self::ref_no( $ctx, (string) $p['ref'] );
					$sql     .= ' AND d.ref_no = %s';
					$args[]   = $no;
				}
				$n   = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$min = max( 1, (int) ( $p['min_count'] ?? 1 ) );
				return array( $n >= $min, sprintf( '%d ligne(s) de mouvement %s trouvée(s), %d attendue(s) au minimum.', $n, (string) ( $p['movement'] ?? '' ), $min ) );

			case 'stock_delta':
				$loc   = ! empty( $p['location_code'] ) ? Catalog::location_by_code( (string) $p['location_code'] ) : null;
				$lid   = $loc ? (int) $loc['id'] : 0;
				$st    = (string) ( $p['status'] ?? '' );
				$delta = self::session_qty( $ctx, (int) $p['material_id'], $lid, 0, $st ) - self::reference_qty( $ctx, (int) $p['material_id'], $lid, $st );
				$exp   = (float) ( $p['delta'] ?? 0 );
				$tol   = (float) ( $p['tolerance'] ?? 0 );
				$m     = Catalog::material( (int) $p['material_id'] );
				return array( abs( $delta - $exp ) <= $tol + 0.0005, sprintf( 'Article %s%s : variation %s (attendu %s).', $m ? $m['material_no'] : '?', $loc ? ' dans ' . $loc['code'] : '', Catalog::format_qty( $delta ), Catalog::format_qty( $exp ) ) );

			case 'stock_qty':
				$loc = ! empty( $p['location_code'] ) ? Catalog::location_by_code( (string) $p['location_code'] ) : null;
				$bin = ! empty( $p['bin_code'] ) ? Catalog::bin_by_code( (string) $p['bin_code'] ) : null;
				$q   = self::session_qty( $ctx, (int) $p['material_id'], $loc ? (int) $loc['id'] : 0, $bin ? (int) $bin['id'] : 0, (string) ( $p['status'] ?? '' ) );
				$min = (float) ( $p['min'] ?? 0 );
				$max = isset( $p['max'] ) && '' !== (string) $p['max'] ? (float) $p['max'] : INF;
				return array( $q + 0.0005 >= $min && $q - 0.0005 <= $max, sprintf( 'Quantité trouvée : %s.', Catalog::format_qty( $q ) ) );

			case 'po_received_qty':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$po       = Db::t( 'purchase_order' );
				$pi       = Db::t( 'purchase_order_item' );
				$q        = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.qty_received),0) FROM {$pi} i JOIN {$po} o ON o.id = i.po_id WHERE o.session_id = %d AND o.po_no = %s AND i.material_id = %d", $sid, $no, (int) $p['material_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( abs( $q - (float) $p['qty'] ) < 0.0005, sprintf( 'Quantité réceptionnée : %s (attendu : %s).', Catalog::format_qty( $q ), Catalog::format_qty( (float) $p['qty'] ) ) );

			case 'reserve_recorded':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$d        = Db::t( 'material_doc' );
				$i        = Db::t( 'material_doc_item' );
				$n        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$i} i JOIN {$d} d ON d.id = i.mdoc_id WHERE d.session_id = %d AND d.ref_no = %s AND ( (i.movement_type = '101' AND (i.reason <> '' OR i.stock_status <> 'LIBRE')) OR i.movement_type = '122' )", $sid, $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $n > 0, $n > 0 ? 'Réserve, blocage ou retour enregistré.' : 'Aucune réserve ni retour enregistré sur cette réception.' );

			case 'cmr_signed':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$po       = Db::t( 'purchase_order' );
				$row      = $wpdb->get_row( $wpdb->prepare( "SELECT cmr_signed_at, cmr_reserves FROM {$po} WHERE session_id = %d AND po_no = %s", $sid, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row || null === $row['cmr_signed_at'] ) {
					return array( false, 'La lettre de voiture CMR n\'a pas été signée (case 24).' );
				}
				if ( ! empty( $p['reserves'] ) && '' === trim( (string) $row['cmr_reserves'] ) ) {
					return array( false, 'CMR signée sans réserve alors que la livraison présentait une anomalie.' );
				}
				return array( true, '' === trim( (string) $row['cmr_reserves'] ) ? 'CMR signée sans réserve.' : 'CMR signée avec réserves : « ' . $row['cmr_reserves'] . ' ».' );

			case 'photo_documented':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$po       = Db::t( 'purchase_order' );
				$pi       = Db::t( 'purchase_order_item' );
				$lines    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT i.line_no FROM {$pi} i JOIN {$po} o ON o.id = i.po_id WHERE o.session_id = %d AND o.po_no = %s AND (i.damage <> '' OR i.delivered_material_id > 0) ORDER BY i.line_no", $sid, $no ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$doc      = \LogiERP\Domain\Photos::documented( $sid );
				$missing  = array_values( array_filter( $lines, static fn( $l ) => empty( $doc[ $no . '|' . $l ] ) ) );
				if ( ! $lines ) {
					$any = array_filter( array_keys( $doc ), static fn( $k ) => str_starts_with( $k, $no . '|' ) );
					return array( (bool) $any, $any ? 'Photo(s) jointe(s) à la réception.' : 'Aucune photo jointe à la réception.' );
				}
				return array( ! $missing, $missing ? 'Ligne(s) non conforme(s) sans photo : ' . implode( ', ', $missing ) . '.' : 'Chaque ligne non conforme est documentée par une photo.' );

			case 'qa_decision':
				$ql  = Db::t( 'inspection_lot' );
				$lot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ql} WHERE session_id = %d AND material_id = %d ORDER BY (status = 'DECIDE') DESC, id DESC LIMIT 1", $sid, (int) ( $p['material_id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $lot ) {
					return array( false, 'Aucun lot de contrôle pour cet article.' );
				}
				if ( 'DECIDE' !== $lot['status'] ) {
					return array( false, sprintf( 'Lot %s : décision d\'emploi pas encore prise.', $lot['lot_no'] ) );
				}
				$want = (array) ( $p['decision'] ?? array() );
				$okd  = in_array( $lot['decision'], $want, true );
				$okr  = $lot['result'] === $lot['truth'];
				$dl   = \LogiERP\Domain\Quality::DECISIONS[ $lot['decision'] ] ?? $lot['decision'];
				return array( $okd && $okr, sprintf( 'Lot %s : résultat saisi %s (laboratoire : %s), décision « %s ».', $lot['lot_no'], 'OK' === $lot['result'] ? 'conforme' : 'non conforme', 'OK' === $lot['truth'] ? 'conforme' : 'non conforme', $dl ) . ( $okd ? '' : ' Décision attendue : ' . implode( ' ou ', array_map( static fn( $d ) => mb_strtolower( \LogiERP\Domain\Quality::DECISIONS[ $d ] ?? $d ), $want ) ) . '.' ) );

			case 'expired_free':
				$s = Db::t( 'stock' );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE session_id = %d AND status = 'LIBRE' AND bbd IS NOT NULL AND bbd < %s AND qty > 0", $sid, $ctx->date() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( 0 === $n, 0 === $n ? 'Aucun lot périmé en stock libre.' : sprintf( '%d ligne(s) de stock périmée(s) encore en stock libre.', $n ) );

			case 'po_ordered':
				$po = Db::t( 'purchase_order' );
				$pi = Db::t( 'purchase_order_item' );
				$q  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.qty),0) FROM {$pi} i JOIN {$po} o ON o.id = i.po_id WHERE o.session_id = %d AND o.scenario_ref = '' AND o.created_by > 0 AND i.material_id = %d", $sid, (int) ( $p['material_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $q + 0.0005 >= (float) ( $p['min'] ?? 1 ), sprintf( 'Quantité commandée : %s (minimum attendu %s).', Catalog::format_qty( $q ), Catalog::format_qty( (float) ( $p['min'] ?? 1 ) ) ) );

			case 'prod_planned':
				$pr   = Db::t( 'production_order' );
				$from = gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . (int) ( $p['not_before'] ?? 0 ) . ' days' ) );
				$row  = $wpdb->get_row( $wpdb->prepare( "SELECT prod_no, qty, start_date FROM {$pr} WHERE session_id = %d AND material_id = %d AND status <> 'ANNULE' ORDER BY id DESC LIMIT 1", $sid, (int) ( $p['material_id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row ) {
					return array( false, 'Aucun ordre de fabrication pour ce produit.' );
				}
				$okq = (float) $row['qty'] + 0.0005 >= (float) ( $p['min'] ?? 1 );
				$okd = $row['start_date'] >= $from;
				return array( $okq && $okd, sprintf( 'Ordre %s : %s unités, début le %s (au plus tôt le %s, quand tous les composants sont disponibles).', $row['prod_no'], Catalog::format_qty( (float) $row['qty'] ), \LogiERP\Domain\Api::fr_date( $row['start_date'] ), \LogiERP\Domain\Api::fr_date( $from ) ) );

			case 'staged':
				$pr = Db::t( 'production_order' );
				$t  = Db::t( 'transfer_order' );
				$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} t JOIN {$pr} p ON p.id = t.ref_id AND t.ref_type = 'PROD' WHERE t.session_id = %d AND t.type = 'STAGE' AND t.status = 'CONFIRME' AND p.material_id = %d", $sid, (int) ( $p['material_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $n && $ctx->simple() ) {
					$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'material_doc' ) . " WHERE session_id = %d AND ref_type = 'PROD' AND header_text LIKE %s", $sid, 'Mise à disposition%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
				return array( $n > 0, $n > 0 ? 'Mise à disposition confirmée au bord de ligne.' : 'Aucune mise à disposition confirmée (LP10).' );

			case 'tours_ok':
				$d    = Db::t( 'outbound_delivery' );
				$sh   = Db::t( 'shipment' );
				$tot  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE session_id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$out  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} d JOIN {$sh} s ON s.id = d.shipment_id WHERE d.session_id = %d AND d.status = 'EXPEDIEE' AND s.status = 'TERMINE'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$need = max( 1, (int) ( $p['min'] ?? $tot ) );
				return array( $out >= $need && $tot > 0, sprintf( '%d livraison(s) expédiée(s) par une tournée clôturée sur %d attendue(s).', $out, $need ) );

			case 'support_ok':
				$d    = Db::t( 'outbound_delivery' );
				$o    = Db::t( 'sales_order' );
				$c    = Db::t( 'customer' );
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.dlv_no, d.pallet_type, c.pallet_type req, c.name FROM {$d} d JOIN {$o} o ON o.id = d.so_id JOIN {$c} c ON c.id = o.customer_id WHERE d.session_id = %d AND d.status IN ('EMBALLEE','EXPEDIEE')", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bad  = array();
				foreach ( $rows as $r ) {
					if ( '' === $r['pallet_type'] || ( '' !== $r['req'] && 'COLIS' !== $r['pallet_type'] && $r['pallet_type'] !== $r['req'] ) ) {
						$bad[] = $r['dlv_no'] . ' (' . $r['name'] . ')';
					}
				}
				return array( $rows && ! $bad, $bad ? 'Support absent ou non conforme : ' . implode( ', ', $bad ) : sprintf( '%d livraison(s) sur le support exigé.', count( $rows ) ) );

			case 'packaging_returns':
				$sh    = Db::t( 'shipment' );
				$ss    = Db::t( 'shipment_stop' );
				$pm    = Db::t( 'packaging_move' );
				$stops = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.shp_no, x.customer_id, x.report FROM {$ss} x JOIN {$sh} s ON s.id = x.shipment_id WHERE s.session_id = %d AND s.status = 'TERMINE'", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $stops ) {
					return array( false, 'Aucune tournée clôturée avec retour d\'emballages.' );
				}
				$errs = array();
				foreach ( $stops as $st ) {
					$rep = json_decode( (string) $st['report'], true );
					foreach ( (array) ( $rep['lines'] ?? array() ) as $l ) {
						$got = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(qty),0) q, COALESCE(SUM(qty_damaged),0) d FROM {$pm} WHERE session_id = %d AND customer_id = %d AND material_id = %d AND movement_type = '622' AND ref_no = %s", $sid, (int) $st['customer_id'], (int) $l['material_id'], $st['shp_no'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$want = (float) $l['good'] + (float) $l['damaged'];
						if ( abs( (float) $got['q'] - $want ) > 0.0005 || abs( (float) $got['d'] - (float) $l['damaged'] ) > 0.0005 ) {
							$errs[] = sprintf( '%s : %s saisi, %s rendu (%s endommagé)', $l['designation'], Catalog::format_qty( (float) $got['q'] ), Catalog::format_qty( $want ), Catalog::format_qty( (float) $l['damaged'] ) );
						}
					}
				}
				return array( ! $errs, $errs ? 'Écarts : ' . implode( ' ; ', array_slice( $errs, 0, 4 ) ) : 'Tous les emballages rendus sont correctement comptabilisés (bons et endommagés).' );

			case 'nothing_in_zone':
				$loc = Catalog::location_by_code( (string) ( $p['location_code'] ?? '100' ) );
				$s   = Db::t( 'stock' );
				$n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE session_id = %d AND storage_location_id = %d AND qty > 0", $sid, $loc ? (int) $loc['id'] : 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( 0 === $n, sprintf( '%d ligne(s) de stock restante(s) dans le magasin %s.', $n, $loc ? $loc['code'] : '?' ) );

			case 'storage_compliance':
				$bad = self::non_compliant( $ctx );
				return array( ! $bad, $bad ? 'Non conforme : ' . implode( ' ; ', array_slice( $bad, 0, 3 ) ) : 'Tous les articles sont stockés dans des emplacements conformes.' );

			case 'strategy_respected':
				$t = Db::t( 'transfer_order' );
				$i = Db::t( 'transfer_order_item' );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$i} i JOIN {$t} o ON o.id = i.to_id WHERE o.session_id = %d AND i.strategy_ok = 0", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$p2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND type = 'PICK' AND status = 'CONFIRME'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( 0 === $n && ( empty( $p['require_pick'] ) || $p2 > 0 ), sprintf( '%d prélèvement(s) hors stratégie.', $n ) );

			case 'no_expired_shipped':
				$d = Db::t( 'material_doc' );
				$i = Db::t( 'material_doc_item' );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$i} i JOIN {$d} d ON d.id = i.mdoc_id WHERE d.session_id = %d AND i.movement_type = '601' AND i.bbd IS NOT NULL AND i.bbd < d.posting_date", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( 0 === $n, sprintf( '%d ligne(s) périmée(s) expédiée(s).', $n ) );

			case 'pick_mode':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$want     = (string) ( $p['mode'] ?? 'SERIE' );
				$d        = Db::t( 'outbound_delivery' );
				$o        = Db::t( 'sales_order' );
				$modes    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT d.pick_mode FROM {$d} d JOIN {$o} o ON o.id = d.so_id WHERE d.session_id = %d AND o.so_no = %s ORDER BY d.id", $sid, $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $modes ) {
					return array( false, sprintf( 'Aucune livraison pour la commande %s.', $no ) );
				}
				$bad = array_filter( $modes, static fn( $m ) => $m !== $want );
				return array( ! $bad, sprintf( 'Commande %s : préparation %s (attendu : %s).', $no, implode( ', ', array_map( static fn( $m ) => 'PARALLELE' === $m ? 'en parallèle' : 'en série', array_unique( $modes ) ) ), 'PARALLELE' === $want ? 'en parallèle' : 'en série' ) );

			case 'consolidated':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$d        = Db::t( 'outbound_delivery' );
				$o        = Db::t( 'sales_order' );
				$dl       = $wpdb->get_row( $wpdb->prepare( "SELECT d.* FROM {$d} d JOIN {$o} o ON o.id = d.so_id WHERE d.session_id = %d AND o.so_no = %s AND d.status IN ('EMBALLEE','EXPEDIEE') ORDER BY d.id DESC LIMIT 1", $sid, $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $dl ) {
					return array( false, sprintf( 'Commande %s : aucune livraison emballée.', $no ) );
				}
				$units = \LogiERP\Domain\Picking::units_of( (int) $dl['id'] );
				if ( ! $units ) {
					return array( false, sprintf( 'Livraison %s emballée sans colisage en unités d\'expédition (HU02).', $dl['dlv_no'] ) );
				}
				$max   = (int) ( $p['max_units'] ?? 0 );
				$type  = (string) ( $p['type'] ?? '' );
				$ut    = Db::t( 'handling_unit_item' );
				$mixed = false;
				$types = array();
				foreach ( $units as $u ) {
					$types[] = $u['type'];
					$n       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT material_id) FROM {$ut} WHERE hu_id = %d", (int) $u['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$mixed   = $mixed || $n > 1;
				}
				$okc = ( ! $max || count( $units ) <= $max ) && ( '' === $type || ! array_diff( $types, array( $type ) ) ) && ( empty( $p['mixed'] ) || $mixed );
				return array( $okc, sprintf( 'Livraison %s : %d unité(s) %s%s (attendu : %s%s%s).', $dl['dlv_no'], count( $units ), implode( ', ', array_unique( $types ) ), $mixed ? ', palette mixte' : '', $max ? 'au plus ' . $max . ' unité(s)' : 'colisage complet', '' !== $type ? ' ' . $type : '', ! empty( $p['mixed'] ) ? ', articles regroupés sur une même unité' : '' ) );

			case 'carrier_used':
				[ , $no ] = self::ref_no( $ctx, (string) ( $p['ref'] ?? '' ) );
				$d        = Db::t( 'outbound_delivery' );
				$o        = Db::t( 'sales_order' );
				$c        = (string) $wpdb->get_var( $wpdb->prepare( "SELECT d.carrier_code FROM {$d} d JOIN {$o} o ON o.id = d.so_id WHERE d.session_id = %d AND o.so_no = %s AND d.status = 'EXPEDIEE' ORDER BY d.id DESC LIMIT 1", $sid, $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $c === (string) ( $p['carrier'] ?? '' ), sprintf( 'Transporteur utilisé : %s (attendu : %s).', $c ?: 'aucun', (string) ( $p['carrier'] ?? '' ) ) );

			case 'inventory_posted':
				$d    = Db::t( 'inventory_doc' );
				$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE session_id = %d AND status = 'COMPTABILISE'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$gaps = array_filter( (array) ( $ctx->doc_map()['gaps'] ?? array() ) );
				return array( $n > 0 && ! $gaps, sprintf( '%d inventaire(s) comptabilisé(s), %d écart(s) réel(s) non corrigé(s).', $n, count( $gaps ) ) );

			case 'replenished':
				$bin = Catalog::bin_by_code( (string) ( $p['bin_code'] ?? '' ) );
				if ( ! $bin ) {
					return array( false, 'Emplacement inconnu.' );
				}
				$q = self::session_qty( $ctx, (int) $bin['fixed_material_id'], 0, (int) $bin['id'], 'LIBRE' );
				return array( $q + 0.0005 >= (float) $bin['min_qty'] && $q > 0, sprintf( 'Stock à l\'emplacement %s : %s (minimum %s).', $bin['code'], Catalog::format_qty( $q ), Catalog::format_qty( (float) $bin['min_qty'] ) ) );

			case 'return_decided':
				$r     = Db::t( 'return_doc' );
				$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE session_id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$open  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE session_id = %d AND status <> 'TRAITE'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $total > 0 && 0 === $open, sprintf( '%d retour(s), %d non traité(s).', $total, $open ) );

			case 'shipped_by_transport':
				$d    = Db::t( 'outbound_delivery' );
				$sh   = Db::t( 'shipment' );
				$out  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} d JOIN {$sh} s ON s.id = d.shipment_id WHERE d.session_id = %d AND d.status = 'EXPEDIEE' AND s.status IN ('EN_ROUTE','TERMINE','REMIS')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$need = max( 1, (int) ( $p['min'] ?? 1 ) );
				return array( $out >= $need, sprintf( '%d livraison(s) partie(s) avec un transport planifié (tournée ou enlèvement) sur %d attendue(s).', $out, $need ) );

			case 'means_mix':
				$sh  = Db::t( 'shipment' );
				$t   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sh} WHERE session_id = %d AND shp_type = 'TOURNEE' AND status IN ('EN_ROUTE','TERMINE')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$e   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT carrier_code) FROM {$sh} WHERE session_id = %d AND shp_type = 'ENLEVEMENT' AND status = 'REMIS'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$min = (int) ( $p['carriers'] ?? 1 );
				return array( $t >= 1 && $e >= $min, sprintf( '%d tournée(s) de la flotte partie(s), %d transporteur(s) externe(s) remis (attendu : au moins 1 tournée et %d transporteur(s)).', $t, $e, $min ) );

			case 'notes_printed':
				$d   = Db::t( 'outbound_delivery' );
				$om  = Db::t( 'output_msg' );
				$tot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE session_id = %d AND status = 'EXPEDIEE'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ok  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} d WHERE d.session_id = %d AND d.status = 'EXPEDIEE' AND EXISTS (SELECT 1 FROM {$om} o WHERE o.session_id = d.session_id AND o.obj_type = 'DLV' AND o.obj_no = d.dlv_no AND o.output_type = 'ZPAL')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $tot > 0 && $ok >= $tot, sprintf( '%d bulletin(s) de livraison imprimé(s) pour %d livraison(s) expédiée(s).', $ok, $tot ) );

			case 'papers_printed':
				$sh  = Db::t( 'shipment' );
				$om  = Db::t( 'output_msg' );
				$tot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sh} WHERE session_id = %d AND status IN ('EN_ROUTE','TERMINE','REMIS')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ok  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sh} s WHERE s.session_id = %d AND s.status IN ('EN_ROUTE','TERMINE','REMIS') AND EXISTS (SELECT 1 FROM {$om} o WHERE o.session_id = s.session_id AND o.obj_type = 'SHP' AND o.obj_no = s.shp_no AND o.output_type = 'ZTRA')", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $tot > 0 && $ok >= $tot, sprintf( 'Papiers imprimés pour %d transport(s) sur %d.', $ok, $tot ) );

			case 'max_errors':
				$t = Db::t( 'action_log' );
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND result = 'error'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$max = (int) ( $p['max'] ?? 5 );
				return array( $n <= $max, sprintf( '%d erreur(s) de saisie (maximum %d).', $n, $max ) );
		}
		return array( false, 'Type de critère inconnu.' );
	}

	/** Lignes de stock d'une session qui ne respectent pas les règles de leur emplacement. */
	public static function non_compliant( Ctx $ctx ): array {
		return self::non_compliant_in( $ctx->id );
	}

	/** Idem pour un identifiant de session (0 = stock de base). */
	public static function non_compliant_in( int $session_id ): array {
		global $wpdb;
		$s    = Db::t( 'stock' );
		$m    = Db::t( 'material' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, m.gross_weight, m.volume_l, m.adr_class FROM {$s} s JOIN {$m} m ON m.id = s.material_id WHERE s.session_id = %d AND s.qty > 0", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$by[ (int) $r['storage_location_id'] . ':' . (int) $r['bin_id'] ][] = $r;
		}
		$bad = array();
		foreach ( $by as $list ) {
			foreach ( $list as $r ) {
				$mat = Catalog::material( (int) $r['material_id'] );
				if ( ! (int) $r['bin_id'] ) {
					$loc = Catalog::location( (int) $r['storage_location_id'] );
					$e   = $loc ? Rules::check_location( $mat, $loc ) : array( 'magasin inconnu' );
					$where = $loc ? $loc['code'] : '?';
				} else {
					$others = array_values( array_filter( $list, static fn( $o ) => (int) $o['id'] !== (int) $r['id'] ) );
					$bin    = Catalog::bin( (int) $r['bin_id'] );
					$e      = Rules::check_bin( $session_id, $mat, $bin, (float) $r['qty'], (string) $r['batch'], Rules::occupancy_from_rows( $others ), true );
					// Les dépassements de capacité hérités du jeu de base ne sont pas reprochés à l'apprenant.
					$e     = array_values( array_filter( $e, static fn( $x ) => ! str_starts_with( $x, 'Charge' ) && ! str_starts_with( $x, 'Volume' ) && ! str_starts_with( $x, 'Quantité maximale' ) ) );
					$where = $bin['code'];
				}
				if ( $e ) {
					$bad[] = $mat['material_no'] . ' en ' . $where;
				}
			}
		}
		return $bad;
	}
}
