<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\ProcessSeeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordres de procédé « comme dans SAP » (PP-PI) : COR1 (créer), COR2 (modifier, clôture technique), COR3 (afficher),
 * COR5 (lancement collectif et impression), COR8 (liste par gestionnaire), CORK (confirmation avec mouvements),
 * CORS (annulation d'une confirmation), CS03 (nomenclature), CS15 (cas d'emploi).
 * Les ordres de fabrication CO01 / CO11N restent disponibles : les deux familles partagent les mêmes ordres.
 */
final class ProcessOrders {

	public const TYPES = array(
		'PI01' => 'Ordre de procédé (fabrication / conditionnement)',
		'SH08' => 'Changement de lot en citerne',
		'PP01' => 'Ordre de fabrication (CO01)',
	);

	/** Statuts système SAP correspondants. */
	public const SAP_STATUS = array( 'CREE' => 'CRTD', 'LANCE' => 'LANC', 'PARTIELLEMENT_CONFIRME' => 'LANC CNFP', 'TERMINE' => 'CNF', 'CLOTURE' => 'CLTE' );

	private static function order( Ctx $ctx, string $no ): array {
		$p = Production::by_no( $ctx, $no );
		if ( ! $p ) {
			throw new DomainError( sprintf( 'Ordre %s introuvable.', $no ) );
		}
		return $p;
	}

	private static function summary( array $p ): array {
		$m = Catalog::material( (int) $p['material_id'] );
		return array(
			'prod_no'    => $p['prod_no'],
			'order_type' => $p['order_type'],
			'type_label' => self::TYPES[ $p['order_type'] ] ?? $p['order_type'],
			'material'   => Api::material_summary( $m ),
			'qty'        => (float) $p['qty'],
			'qty_done'   => (float) $p['qty_done'],
			'qty_scrap'  => (float) $p['qty_scrap'],
			'status'     => $p['status'],
			'sap_status' => self::SAP_STATUS[ $p['status'] ] ?? $p['status'],
			'start_date' => $p['start_date'],
			'end_date'   => $p['end_date'] ?: $p['start_date'],
			'supervisor' => $p['supervisor'],
			'supervisor_label' => ProcessSeeder::SUPERVISORS[ $p['supervisor'] ] ?? '',
			'printed'    => (int) $p['printed'],
		);
	}

	/** COR1 : création d'un ordre de procédé (PI01) ou d'un changement de lot en citerne (SH08). */
	public static function create( Ctx $ctx, int $material_id, float $qty, string $end, string $type, string $supervisor, int $stock_id = 0 ): array {
		$m = Catalog::material( $material_id );
		if ( ! $m ) {
			throw new DomainError( 'Article introuvable.' );
		}
		$type = strtoupper( $type ) ?: 'PI01';
		if ( ! in_array( $type, array( 'PI01', 'SH08' ), true ) ) {
			throw new DomainError( 'Type d\'ordre : PI01 (ordre de procédé) ou SH08 (changement de lot en citerne).' );
		}
		$end = '' !== $end ? $end : $ctx->date();
		if ( ! Api::valid_date( $end ) || $end < $ctx->date() || $end > gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +120 days' ) ) ) {
			throw new DomainError( 'Date de fin invalide : entre aujourd\'hui et 120 jours.' );
		}
		$supervisor = (string) preg_replace( '/\D/', '', $supervisor );
		if ( '' === $supervisor ) {
			$supervisor = (string) $m['prod_supervisor'];
		}
		if ( '' !== $supervisor && ! isset( ProcessSeeder::SUPERVISORS[ $supervisor ] ) ) {
			throw new DomainError( 'Gestionnaire de production inconnu (250 à 259).' );
		}
		if ( 'SH08' === $type ) {
			return self::create_sh08( $ctx, $m, $qty, $end, $supervisor, $stock_id );
		}
		$start = max( $ctx->date(), gmdate( 'Y-m-d', strtotime( $end . ' -1 day' ) ) );
		$no    = Production::create( $ctx, $material_id, $qty, '', $start );
		Db::update( 'production_order', array( 'order_type' => 'PI01', 'supervisor' => $supervisor, 'end_date' => $end ), array( 'session_id' => $ctx->id, 'prod_no' => $no ) );
		return array( 'no' => $no, 'message' => sprintf( 'Ordre de procédé %s créé (statut CRTD) : %s × %s, fin prévue le %s. Lancez-le avec COR5 (ou COR2).', $no, Catalog::format_qty( $qty ), $m['material_no'], Api::fr_date( $end ) ) );
	}

	/** SH08 : le lot présent en citerne est consommé (261) et repris sous un nouveau lot (262), après analyse. */
	private static function create_sh08( Ctx $ctx, array $m, float $qty, string $end, string $supervisor, int $stock_id ): array {
		if ( empty( $m['bulk'] ) ) {
			throw new DomainError( 'Le changement de lot SH08 concerne un produit en vrac stocké en citerne.' );
		}
		$row = StockService::row( $ctx->id, $stock_id );
		if ( ! $row || (int) $row['material_id'] !== (int) $m['id'] || 'LIBRE' !== $row['status'] ) {
			throw new DomainError( 'Choisissez la ligne de stock libre de la citerne (lot à remplacer).' );
		}
		$bin = (int) $row['bin_id'] ? Catalog::bin( (int) $row['bin_id'] ) : null;
		if ( $bin && 'CITERNE' !== $bin['bin_type'] ) {
			throw new DomainError( 'Cette ligne de stock n\'est pas en citerne.' );
		}
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité : au plus %s kg dans la citerne pour ce lot.', Catalog::format_qty( (float) $row['qty'] ) ) );
		}
		return Db::transaction(
			static function () use ( $ctx, $m, $qty, $end, $supervisor, $row, $bin ) {
				$no  = NumberRange::next( $ctx->id, 'PROD' );
				$pid = Db::insert( 'production_order', array( 'session_id' => $ctx->id, 'prod_no' => $no, 'scenario_ref' => '', 'material_id' => (int) $m['id'], 'qty' => $qty, 'status' => 'CREE', 'start_date' => $ctx->date(), 'order_type' => 'SH08', 'supervisor' => $supervisor, 'end_date' => $end, 'created_at' => Db::now() ) );
				Db::insert( 'production_component', array( 'prod_id' => $pid, 'material_id' => (int) $m['id'], 'qty_required' => $qty ) );
				return array( 'no' => $no, 'message' => sprintf( 'Ordre SH08 %s créé pour le lot %s%s : prélevez un échantillon pour le laboratoire, lancez l\'ordre puis confirmez-le avec CORK (261 l\'ancien lot, 262 le nouveau). Clôturez-le ensuite (COR2).', $no, $row['batch'], $bin ? ' en ' . $bin['code'] : '' ) );
			}
		);
	}

	/** COR8 : liste des ordres (par gestionnaire, statut, période). */
	public static function list( Ctx $ctx, string $supervisor, string $status, string $from, string $to ): array {
		global $wpdb;
		$t     = Db::t( 'production_order' );
		$where = array( 'session_id = %d' );
		$args  = array( $ctx->id );
		if ( '' !== $supervisor ) {
			$where[] = 'supervisor = %s';
			$args[]  = $supervisor;
		}
		$map = array( 'CRTD' => array( 'CREE' ), 'LANC' => array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), 'CNF' => array( 'TERMINE' ), 'CLTE' => array( 'CLOTURE' ), 'OPEN' => array( 'CREE', 'LANCE', 'PARTIELLEMENT_CONFIRME' ) );
		if ( isset( $map[ $status ] ) ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $map[ $status ] ), '%s' ) ) . ')';
			$args    = array_merge( $args, $map[ $status ] );
		}
		if ( Api::valid_date( $from ) ) {
			$where[] = 'COALESCE(end_date, start_date) >= %s';
			$args[]  = $from;
		}
		if ( Api::valid_date( $to ) ) {
			$where[] = 'COALESCE(end_date, start_date) <= %s';
			$args[]  = $to;
		}
		$sqlw = implode( ' AND ', $where );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sqlw} ORDER BY COALESCE(end_date, start_date), id LIMIT 200", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'rows' => array_map( array( self::class, 'summary' ), $rows ), 'supervisors' => ProcessSeeder::SUPERVISORS );
	}

	/** COR3 : affichage complet (composants, confirmations, historique, impressions). */
	public static function detail( Ctx $ctx, string $no ): array {
		global $wpdb;
		$p     = self::order( $ctx, $no );
		$d     = Production::detail( $ctx, $no );
		$confs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT conf_no, qty_good, qty_scrap, doc_no, batch, cancelled, cancel_reason, cancel_doc, created_at FROM ' . Db::t( 'prod_confirmation' ) . ' WHERE session_id = %d AND prod_id = %d ORDER BY id', $ctx->id, (int) $p['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$comps = array();
		foreach ( Production::components( (int) $p['id'] ) as $c ) {
			$m       = Catalog::material( (int) $c['material_id'] );
			$comps[] = array( 'id' => (int) $c['id'], 'material' => Api::material_summary( $m ) + array( 'type' => $m['type'] ), 'qty_required' => (float) $c['qty_required'], 'qty_issued' => (float) $c['qty_issued'], 'available' => StockService::available( $ctx->id, (int) $c['material_id'] ) );
		}
		return self::summary( $p ) + array(
			'work_center'   => $d['work_center'],
			'components'    => $comps,
			'confirmations' => $confs,
			'changes'       => StockTx::changes( $ctx, 'PROD', $no ),
			'outputs'       => StockTx::outputs_of( $ctx, 'PROD', $no ),
			'staging'       => $d['staging'],
			'availability'  => $d['availability'],
			'supervisors'   => ProcessSeeder::SUPERVISORS,
		);
	}

	/**
	 * COR2 : modification (quantité, date de fin, gestionnaire, composants : remplacer, ajouter, retirer).
	 *
	 * @param array $comps [{id, material_id, qty, remove}] ; $add [{material_id, qty}]
	 */
	public static function change( Ctx $ctx, string $no, array $head, array $comps, array $add ): array {
		global $wpdb;
		$p = self::order( $ctx, $no );
		if ( ! in_array( $p['status'], array( 'CREE', 'LANCE' ), true ) ) {
			throw new DomainError( sprintf( 'Ordre %s au statut %s : seules les quantités d\'un ordre créé ou lancé non confirmé se modifient.', $no, self::SAP_STATUS[ $p['status'] ] ?? $p['status'] ) );
		}
		$errors = array();
		$plan   = array();
		$pc     = Db::t( 'production_component' );
		if ( isset( $head['qty'] ) && '' !== $head['qty'] && null !== $head['qty'] ) {
			$q = round( (float) $head['qty'], 3 );
			if ( $q <= 0 || $q > 100000 ) {
				$errors[] = 'Quantité de l\'ordre invalide.';
			} elseif ( abs( $q - (float) $p['qty'] ) > 0.0005 ) {
				$plan[] = array( 'qty', $q );
			}
		}
		if ( ! empty( $head['end'] ) && $head['end'] !== $p['end_date'] ) {
			if ( ! Api::valid_date( (string) $head['end'] ) || $head['end'] < $ctx->date() ) {
				$errors[] = 'Date de fin invalide.';
			} else {
				$plan[] = array( 'end', (string) $head['end'] );
			}
		}
		if ( isset( $head['supervisor'] ) && (string) $head['supervisor'] !== (string) $p['supervisor'] ) {
			$s = (string) preg_replace( '/\D/', '', (string) $head['supervisor'] );
			if ( '' !== $s && ! isset( ProcessSeeder::SUPERVISORS[ $s ] ) ) {
				$errors[] = 'Gestionnaire inconnu.';
			} else {
				$plan[] = array( 'sup', $s );
			}
		}
		$current = array();
		foreach ( Production::components( (int) $p['id'] ) as $c ) {
			$current[ (int) $c['id'] ] = $c;
		}
		foreach ( $comps as $c ) {
			$id = (int) ( $c['id'] ?? 0 );
			if ( ! isset( $current[ $id ] ) ) {
				continue;
			}
			$cur = $current[ $id ];
			if ( (float) $cur['qty_issued'] > 0.0005 ) {
				$errors[] = 'Un composant déjà consommé ne se modifie plus.';
				continue;
			}
			if ( ! empty( $c['remove'] ) ) {
				$plan[] = array( 'remove', $cur );
				continue;
			}
			$mid   = (int) ( $c['material_id'] ?? 0 ) ?: (int) $cur['material_id'];
			$ratio = 1.0;
			foreach ( $plan as $x ) {
				if ( 'qty' === $x[0] && (float) $p['qty'] > 0 ) {
					$ratio = $x[1] / (float) $p['qty'];
				}
			}
			$q = isset( $c['qty'] ) && '' !== $c['qty'] && null !== $c['qty'] ? round( (float) $c['qty'], 3 ) : round( (float) $cur['qty_required'] * $ratio, 3 );
			$m   = Catalog::material( $mid );
			if ( ! $m || 'ACTIF' !== $m['status'] ) {
				$errors[] = 'Composant de remplacement introuvable.';
				continue;
			}
			if ( $q <= 0 ) {
				$errors[] = sprintf( 'Composant %s : quantité invalide.', $m['material_no'] );
				continue;
			}
			if ( $mid !== (int) $cur['material_id'] || abs( $q - (float) $cur['qty_required'] ) > 0.0005 ) {
				$plan[] = array( 'comp', $cur, $m, $q );
			}
		}
		foreach ( array_slice( $add, 0, 10 ) as $a ) {
			$m = Catalog::material( (int) ( $a['material_id'] ?? 0 ) );
			$q = round( (float) ( $a['qty'] ?? 0 ), 3 );
			if ( ! $m || $q <= 0 ) {
				$errors[] = 'Nouveau composant : article ou quantité invalide.';
				continue;
			}
			$plan[] = array( 'add', $m, $q );
		}
		if ( $errors ) {
			throw new DomainError( 'La modification contient des erreurs.', $errors );
		}
		if ( ! $plan ) {
			throw new DomainError( 'Aucune modification à enregistrer.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $p, $plan, $pc, $wpdb ) {
				$msg = array();
				foreach ( $plan as $x ) {
					switch ( $x[0] ) {
						case 'qty':
							$ratio = (float) $p['qty'] > 0 ? $x[1] / (float) $p['qty'] : 1;
							Db::update( 'production_order', array( 'qty' => $x[1] ), array( 'id' => (int) $p['id'] ) );
							foreach ( Production::components( (int) $p['id'] ) as $c ) {
								$cm  = Catalog::material( (int) $c['material_id'] );
								$req = round( (float) $c['qty_required'] * $ratio, 3 );
								$wpdb->update( $pc, array( 'qty_required' => $cm && 'PCE' === $cm['base_uom'] ? ceil( $req - 0.0005 ) : $req ), array( 'id' => (int) $c['id'] ) );
							}
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Quantité de l\'ordre', Catalog::format_qty( (float) $p['qty'] ), Catalog::format_qty( $x[1] ), 'COR2' );
							$msg[] = 'quantité ' . Catalog::format_qty( $x[1] ) . ' (composants recalculés)';
							break;
						case 'end':
							Db::update( 'production_order', array( 'end_date' => $x[1] ), array( 'id' => (int) $p['id'] ) );
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Date de fin', Api::fr_date( (string) $p['end_date'] ), Api::fr_date( $x[1] ), 'COR2' );
							$msg[] = 'fin le ' . Api::fr_date( $x[1] );
							break;
						case 'sup':
							Db::update( 'production_order', array( 'supervisor' => $x[1] ), array( 'id' => (int) $p['id'] ) );
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Gestionnaire', (string) $p['supervisor'], $x[1], 'COR2' );
							$msg[] = 'gestionnaire ' . $x[1];
							break;
						case 'remove':
							$old = Catalog::material( (int) $x[1]['material_id'] );
							$wpdb->delete( $pc, array( 'id' => (int) $x[1]['id'] ) );
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Composant retiré', $old['material_no'], '—', 'COR2' );
							$msg[] = $old['material_no'] . ' retiré';
							break;
						case 'comp':
							$old = Catalog::material( (int) $x[1]['material_id'] );
							$wpdb->update( $pc, array( 'material_id' => (int) $x[2]['id'], 'qty_required' => $x[3] ), array( 'id' => (int) $x[1]['id'] ) );
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Composant', $old['material_no'] . ' · ' . Catalog::format_qty( (float) $x[1]['qty_required'] ), $x[2]['material_no'] . ' · ' . Catalog::format_qty( $x[3] ), 'COR2' );
							$msg[] = $old['material_no'] === $x[2]['material_no'] ? sprintf( '%s : %s', $x[2]['material_no'], Catalog::format_qty( $x[3] ) ) : sprintf( '%s remplacé par %s', $old['material_no'], $x[2]['material_no'] );
							break;
						case 'add':
							Db::insert( 'production_component', array( 'prod_id' => (int) $p['id'], 'material_id' => (int) $x[1]['id'], 'qty_required' => $x[2] ) );
							StockTx::log_change( $ctx, 'PROD', $p['prod_no'], 0, 'Composant ajouté', '—', $x[1]['material_no'] . ' · ' . Catalog::format_qty( $x[2] ), 'COR2' );
							$msg[] = $x[1]['material_no'] . ' ajouté';
							break;
					}
				}
				return array( 'no' => $p['prod_no'], 'message' => sprintf( 'Ordre %s modifié : %s.', $p['prod_no'], implode( ' ; ', $msg ) ) );
			}
		);
	}

	/** COR5 / COR2 : lancement (avec contrôle de disponibilité) et impression de l'ordre et des étiquettes. */
	public static function release( Ctx $ctx, array $nos ): array {
		$nos = array_slice( array_values( array_unique( array_filter( array_map( static fn( $v ) => (string) preg_replace( '/\D/', '', (string) $v ), $nos ) ) ) ), 0, 30 );
		if ( ! $nos ) {
			throw new DomainError( 'Sélectionnez au moins un ordre.' );
		}
		$done   = array();
		$errors = array();
		$warn   = array();
		foreach ( $nos as $no ) {
			try {
				$p = self::order( $ctx, $no );
				if ( 'PP01' === $p['order_type'] ) {
					Production::release( $ctx, $no ); // Ordre de fabrication : composants disponibles exigés (CO02).
				} else {
					if ( 'CREE' !== $p['status'] ) {
						throw new DomainError( sprintf( 'statut %s, seul un ordre CRTD se lance.', self::SAP_STATUS[ $p['status'] ] ?? $p['status'] ) );
					}
					// Ordre de procédé : le lancement signale les manques sans bloquer (contrôle de disponibilité).
					foreach ( Production::components( (int) $p['id'] ) as $c ) {
						$avail = StockService::available( $ctx->id, (int) $c['material_id'] );
						if ( 'SH08' !== $p['order_type'] && $avail + 0.0005 < (float) $c['qty_required'] ) {
							$cm     = Catalog::material( (int) $c['material_id'] );
							$warn[] = sprintf( '%s, %s : manque %s %s.', $no, $cm['material_no'], Catalog::format_qty( (float) $c['qty_required'] - $avail ), $cm['base_uom'] );
						}
					}
					Db::update( 'production_order', array( 'status' => 'LANCE' ), array( 'id' => (int) $p['id'] ) );
				}
				Db::update( 'production_order', array( 'printed' => (int) $p['printed'] + 1 ), array( 'id' => (int) $p['id'] ) );
				StockTx::output( $ctx, 'PROD', $no, 'ZPOR', 'Ordre et étiquettes' );
				$done[] = $no;
			} catch ( DomainError $e ) {
				$errors[] = $no . ' : ' . $e->getMessage() . ( $e->details() ? ' ' . implode( ' ', $e->details() ) : '' );
			}
		}
		if ( ! $done ) {
			throw new DomainError( 'Aucun ordre lancé.', $errors );
		}
		return array( 'done' => $done, 'errors' => $errors, 'warnings' => $warn, 'no' => $done[0], 'message' => sprintf( '%d ordre%s lancé%s (LANC), ordre et étiquettes imprimés : %s.', count( $done ), count( $done ) > 1 ? 's' : '', count( $done ) > 1 ? 's' : '', implode( ', ', $done ) ) . ( $warn ? ' Contrôle de disponibilité : ' . implode( ' ', $warn ) : '' ) . ( $errors ? ' ' . count( $errors ) . ' refusé(s) : ' . implode( ' ', $errors ) : '' ) );
	}

	/** COR2 : clôture technique (CLTE) et annulation de la clôture. */
	public static function tech_close( Ctx $ctx, string $no, bool $undo ): array {
		$p = self::order( $ctx, $no );
		if ( $undo ) {
			if ( 'CLOTURE' !== $p['status'] ) {
				throw new DomainError( 'Cet ordre n\'est pas clôturé techniquement.' );
			}
			$prev = '' !== (string) $p['prev_status'] ? (string) $p['prev_status'] : 'LANCE';
			Db::update( 'production_order', array( 'status' => $prev, 'prev_status' => '' ), array( 'id' => (int) $p['id'] ) );
			StockTx::log_change( $ctx, 'PROD', $no, 0, 'Statut', 'CLTE', self::SAP_STATUS[ $prev ] ?? $prev, 'COR2' );
			return array( 'no' => $no, 'message' => sprintf( 'Clôture technique de l\'ordre %s annulée : statut %s.', $no, self::SAP_STATUS[ $prev ] ?? $prev ) );
		}
		if ( 'CLOTURE' === $p['status'] ) {
			throw new DomainError( 'Cet ordre est déjà clôturé techniquement.' );
		}
		if ( 'CREE' === $p['status'] ) {
			throw new DomainError( 'Un ordre non lancé se supprime ou se lance ; la clôture technique concerne un ordre lancé ou confirmé.' );
		}
		$stg = Production::staging_to( $ctx, (int) $p['id'] );
		if ( $stg && 'OUVERT' === $stg['status'] ) {
			throw new DomainError( sprintf( 'La mise à disposition %s est encore ouverte : confirmez-la ou annulez-la (LT15).', $stg['to_no'] ) );
		}
		Db::update( 'production_order', array( 'status' => 'CLOTURE', 'prev_status' => $p['status'] ), array( 'id' => (int) $p['id'] ) );
		StockTx::log_change( $ctx, 'PROD', $no, 0, 'Statut', self::SAP_STATUS[ $p['status'] ] ?? $p['status'], 'CLTE', 'COR2' );
		return array( 'no' => $no, 'message' => sprintf( 'Ordre %s clôturé techniquement (CLTE) : il disparaît des besoins (MD04), plus aucun mouvement n\'est possible.', $no ) );
	}

	/** CORK : proposition des mouvements de stock (consommations 261, entrée 101 ou 262). */
	public static function proposal( Ctx $ctx, string $no ): array {
		$p = self::order( $ctx, $no );
		if ( ! in_array( $p['status'], array( 'LANCE', 'PARTIELLEMENT_CONFIRME' ), true ) ) {
			throw new DomainError( sprintf( 'Ordre %s au statut %s : seul un ordre lancé se confirme.', $no, self::SAP_STATUS[ $p['status'] ] ?? $p['status'] ) );
		}
		$open  = max( 0.0, (float) $p['qty'] - (float) $p['qty_done'] - (float) $p['qty_scrap'] );
		$share = (float) $p['qty'] > 0 ? $open / (float) $p['qty'] : 1;
		$prod  = array();
		foreach ( Catalog::locations() as $l ) {
			if ( 'PROD' === $l['zone_type'] ) {
				$prod[] = (int) $l['id'];
			}
		}
		$lines    = array();
		$missing  = array();
		$reserved = array();
		foreach ( Production::components( (int) $p['id'] ) as $c ) {
			$m    = Catalog::material( (int) $c['material_id'] );
			$need = max( 0.0, (float) $c['qty_required'] - (float) $c['qty_issued'] );
			if ( 'SH08' !== $p['order_type'] && $share < 1 ) {
				$need = min( $need, round( (float) $c['qty_required'] * $share, 3 ) );
			}
			if ( $need <= 0.0005 ) {
				continue;
			}
			$alloc = Strategies::allocate( $ctx->id, $m, $need, $ctx->date(), array( 'location_ids' => $prod, 'reserved' => $reserved ) );
			if ( $alloc['missing'] > 0.0005 ) {
				$more  = Strategies::allocate( $ctx->id, $m, $alloc['missing'], $ctx->date(), array( 'reserved' => $reserved + array_column( array_map( static fn( $a ) => array( (int) $a['row']['id'], $a['qty'] ), $alloc['alloc'] ), 1, 0 ) ) );
				$alloc = array( 'alloc' => array_merge( $alloc['alloc'], $more['alloc'] ), 'missing' => $more['missing'] );
			}
			foreach ( $alloc['alloc'] as $a ) {
				$reserved[ (int) $a['row']['id'] ] = ( $reserved[ (int) $a['row']['id'] ] ?? 0 ) + $a['qty'];
				$lines[] = array( 'component_id' => (int) $c['id'], 'material' => Api::material_summary( $m ) + array( 'type' => $m['type'] ), 'stock_id' => (int) $a['row']['id'], 'location' => (string) $a['row']['location_code'], 'bin' => (string) $a['row']['bin_code'], 'batch' => (string) $a['row']['batch'], 'available' => (float) $a['row']['qty'], 'qty' => round( $a['qty'], 3 ) );
			}
			if ( $alloc['missing'] > 0.0005 ) {
				$missing[] = array( 'component_id' => (int) $c['id'], 'material' => Api::material_summary( $m ) + array( 'type' => $m['type'] ), 'qty' => round( $alloc['missing'], 3 ), 'packaging' => in_array( $m['type'], array( 'EMB', 'VERP' ), true ) ? 1 : 0 );
			}
		}
		$m = Catalog::material( (int) $p['material_id'] );
		return array(
			'order'     => self::summary( $p ),
			'open'      => $open,
			'out_mvt'   => 'SH08' === $p['order_type'] ? '262' : '101',
			'batch_hint' => 'Nouveau lot attribué à la confirmation',
			'lines'     => $lines,
			'missing'   => $missing,
			'shelf_life' => (int) $m['shelf_life_days'],
		);
	}

	/**
	 * CORK : confirmation avec les mouvements réellement effectués.
	 *
	 * @param array $lines [{stock_id, qty}] consommations (261)
	 */
	public static function confirm( Ctx $ctx, string $no, float $good, float $scrap, array $lines, bool $final ): array {
		global $wpdb;
		$p   = self::order( $ctx, $no );
		$pro = self::proposal( $ctx, $no );
		$open = $pro['open'];
		if ( $good < 0 || $scrap < 0 || $good + $scrap <= 0 ) {
			throw new DomainError( 'Indiquez la quantité bonne (et le rebut éventuel).' );
		}
		if ( 'SH08' !== $p['order_type'] && $good + $scrap > $open + 0.0005 ) {
			throw new DomainError( sprintf( 'Il reste %s à confirmer sur cet ordre.', Catalog::format_qty( $open ) ) );
		}
		$comps = array();
		foreach ( Production::components( (int) $p['id'] ) as $c ) {
			$comps[ (int) $c['material_id'] ] = $c;
		}
		$post   = array();
		$issued = array();
		$errors = array();
		foreach ( $lines as $l ) {
			$q = round( (float) ( $l['qty'] ?? 0 ), 3 );
			if ( $q <= 0 ) {
				continue;
			}
			$row = StockService::row( $ctx->id, (int) ( $l['stock_id'] ?? 0 ) );
			if ( ! $row || 'LIBRE' !== $row['status'] ) {
				$errors[] = 'Une ligne de consommation ne correspond à aucun stock libre.';
				continue;
			}
			$m = Catalog::material( (int) $row['material_id'] );
			// Emballage réellement utilisé différent de l'ordre : il remplace le composant d'emballage prévu.
			$cid = self::component_for( $comps, $m );
			if ( ! $cid ) {
				$errors[] = sprintf( 'L\'article %s n\'est pas un composant de l\'ordre.', $m['material_no'] );
				continue;
			}
			if ( $q > (float) $row['qty'] + 0.0005 ) {
				$errors[] = sprintf( '%s lot %s : seulement %s disponibles à cet emplacement.', $m['material_no'], $row['batch'] ?: '—', Catalog::format_qty( (float) $row['qty'] ) );
				continue;
			}
			$ctx->require_key_for( Catalog::location( (int) $row['storage_location_id'] ) );
			$post[]          = array( 'movement_type' => '261', 'material_id' => (int) $m['id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => 'LIBRE', 'qty' => -$q );
			$issued[ $cid ]  = ( $issued[ $cid ] ?? 0 ) + $q;
		}
		if ( $errors ) {
			throw new DomainError( 'Mouvements de stock incorrects.', $errors );
		}
		// Contrôle des composants : un emballage manquant bloque la confirmation (procédure B.2.1).
		$share = $open > 0 ? min( 1.0, ( $good + $scrap ) / $open ) : 1.0;
		$short = array();
		foreach ( $comps as $mid => $c ) {
			$cm   = Catalog::material( (int) $mid );
			$need = ( (float) $c['qty_required'] - (float) $c['qty_issued'] ) * ( 'SH08' === $p['order_type'] ? 1 : $share );
			$got  = (float) ( $issued[ $mid ] ?? 0 );
			if ( $need > 0.0005 && $got + 0.0005 < $need * 0.95 ) {
				$short[] = sprintf( '%s %s : %s %s prévus, %s saisis.', in_array( $cm['type'], array( 'EMB', 'VERP' ), true ) ? 'Emballage' : 'Composant', $cm['material_no'], Catalog::format_qty( round( $need, 3 ) ), $cm['base_uom'], Catalog::format_qty( $got ) );
			}
		}
		if ( $short ) {
			throw new DomainError( 'Composants manquants pour cette confirmation. Si l\'emballage utilisé est bien celui de l\'ordre, le stock est faux : faites-le entrer (ZVLGUT, MIGO autres) ; si un autre emballage a été utilisé, saisissez-le à la place (ou transférez-le avec MB11 309).', $short );
		}
		$m     = Catalog::material( (int) $p['material_id'] );
		$sh08  = 'SH08' === $p['order_type'];
		if ( $sh08 ) {
			$src = $post ? $post[0] : null;
			if ( ! $src ) {
				throw new DomainError( 'Saisissez la consommation de l\'ancien lot (261).' );
			}
			$loc_id = (int) $src['location_id'];
			$bin_id = (int) $src['bin_id'];
		} elseif ( $ctx->simple() ) {
			$dst = null;
			foreach ( Catalog::locations() as $l ) {
				if ( empty( $l['is_transit'] ) && ! Rules::check_location( $m, $l ) ) {
					$dst = $l;
					break;
				}
			}
			if ( ! $dst ) {
				throw new DomainError( 'Aucun magasin n\'accepte le produit fini.' );
			}
			$loc_id = (int) $dst['id'];
			$bin_id = 0;
		} else {
			[ $bin, $dst ] = Receiving::reception_bin( $ctx );
			$loc_id        = (int) $dst['id'];
			$bin_id        = (int) $bin['id'];
		}
		return Db::transaction(
			static function () use ( $ctx, $p, $m, $good, $scrap, $post, $issued, $final, $sh08, $loc_id, $bin_id, $open, $wpdb ) {
				$batch = ! empty( $m['batch_managed'] ) ? StockTx::new_batch( $ctx ) : '';
				$bbd   = (int) $m['shelf_life_days'] > 0 ? gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . (int) $m['shelf_life_days'] . ' days' ) ) : null;
				if ( $good > 0 ) {
					$post[] = array( 'movement_type' => $sh08 ? '262' : '101', 'material_id' => (int) $m['id'], 'location_id' => $loc_id, 'bin_id' => $bin_id, 'batch' => $batch, 'bbd' => $bbd, 'status' => 'LIBRE', 'qty' => $good, 'reason' => $sh08 ? 'Nouveau lot en citerne' : 'Entrée de production' );
				}
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'ref_no' => $p['prod_no'], 'text' => 'Confirmation ' . $p['prod_no'], 'tcode' => 'CORK' ), $post );
				$pc  = Db::t( 'production_component' );
				foreach ( $issued as $mid => $q ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$pc} SET qty_issued = qty_issued + %f WHERE prod_id = %d AND material_id = %d", $q, (int) $p['id'], (int) $mid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				$done  = (float) $p['qty_done'] + $good;
				$scr   = (float) $p['qty_scrap'] + $scrap;
				$state = ( $final || ( ! $sh08 && $good + $scrap + 0.0005 >= $open ) ) ? 'TERMINE' : 'PARTIELLEMENT_CONFIRME';
				Db::update( 'production_order', array( 'qty_done' => $done, 'qty_scrap' => $scr, 'status' => $state ), array( 'id' => (int) $p['id'] ) );
				$conf = NumberRange::next( $ctx->id, 'CONF' );
				Db::insert( 'prod_confirmation', array( 'session_id' => $ctx->id, 'prod_id' => (int) $p['id'], 'conf_no' => $conf, 'qty_good' => $good, 'qty_scrap' => $scrap, 'doc_no' => $doc, 'batch' => $batch, 'created_at' => Db::now() ) );
				$to_no = '';
				if ( ! $sh08 && $good > 0 && ! $ctx->simple() ) {
					$res   = Warehouse::create_putaway( $ctx, 'PROD', (int) $p['id'], array( array( 'm' => $m, 'qty' => $good, 'batch' => $batch, 'bbd' => $bbd, 'status' => 'LIBRE', 'src_location_id' => $loc_id, 'src_bin_id' => $bin_id ) ) );
					$to_no = $res['to_no'];
				}
				$msg = sprintf( 'Confirmation %s enregistrée, document %s : %s %s %s', $conf, $doc, Catalog::format_qty( $good ), $m['base_uom'], $sh08 ? 'repris sous le nouveau lot ' . $batch . ' (262).' : 'entrés en stock' . ( '' !== $batch ? ', lot ' . $batch : '' ) . '.' );
				if ( $to_no ) {
					$msg .= sprintf( ' Ordre d\'entreposage %s à quittancer (LS26).', $to_no );
				}
				if ( $sh08 || 'TERMINE' === $state ) {
					$msg .= ' L\'ordre reste visible dans MD04 tant qu\'il n\'est pas clôturé techniquement (COR2).';
				}
				return array( 'doc_no' => $doc, 'no' => $p['prod_no'], 'conf_no' => $conf, 'batch' => $batch, 'to_no' => $to_no, 'status' => $state, 'message' => $msg );
			}
		);
	}

	/** CORS : annulation de la dernière confirmation (mouvements inverses 262 / 102, ou 261 pour un SH08). */
	public static function cancel( Ctx $ctx, string $no, string $reason ): array {
		global $wpdb;
		$p = self::order( $ctx, $no );
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif d\'annulation.' );
		}
		if ( 'CLOTURE' === $p['status'] ) {
			throw new DomainError( 'Ordre clôturé techniquement : annulez d\'abord la clôture (COR2).' );
		}
		$ct   = Db::t( 'prod_confirmation' );
		$conf = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE session_id = %d AND prod_id = %d AND cancelled = 0 ORDER BY id DESC LIMIT 1", $ctx->id, (int) $p['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $conf ) {
			throw new DomainError( 'Aucune confirmation CORK à annuler sur cet ordre (une déclaration faite avec CO11N ne s\'annule pas ici).' );
		}
		$d    = StockTx::doc( $ctx, (string) $conf['doc_no'] );
		$rev  = array( '261' => '262', '101' => '102', '262' => '261' );
		$post = array();
		$back = array();
		$out_rows = array();
		foreach ( StockTx::doc_items( (int) $d['id'] ) as $i ) {
			if ( (int) $i['reversed'] || ! isset( $rev[ $i['movement_type'] ] ) ) {
				continue;
			}
			$seg = array( (int) $i['storage_location_id'], (int) $i['bin_id'] );
			if ( (float) $i['qty'] > 0 ) {
				$have = Warehouse::stock_at( $ctx, (int) $i['material_id'], $seg[0], $seg[1], (string) $i['batch'], 'LIBRE' );
				if ( ! $have || (float) $have['qty'] + 0.0005 < (float) $i['qty'] ) {
					$moved = StockTx::find_moved( $ctx, $i, (float) $i['qty'] );
					if ( ! $moved ) {
						throw new DomainError( sprintf( 'Le produit fabriqué (lot %s) n\'est plus en stock à son emplacement : il a été utilisé ou livré. Annulation impossible.', $i['batch'] ) );
					}
					$seg = array( (int) $moved['storage_location_id'], (int) $moved['bin_id'] );
				} else {
					$out_rows[] = array( $have, (float) $i['qty'] );
				}
			} else {
				$back[ (int) $i['material_id'] ] = ( $back[ (int) $i['material_id'] ] ?? 0 ) - (float) $i['qty'];
			}
			$post[] = array( 'movement_type' => $rev[ $i['movement_type'] ], 'material_id' => (int) $i['material_id'], 'location_id' => $seg[0], 'bin_id' => $seg[1], 'batch' => $i['batch'], 'bbd' => $i['bbd'], 'status' => 'LIBRE', 'qty' => -(float) $i['qty'], 'reason' => mb_substr( $reason, 0, 120 ), '_id' => (int) $i['id'] );
		}
		if ( ! $post ) {
			throw new DomainError( 'Rien à annuler dans cette confirmation.' );
		}
		// Les entrées (positives) s'annulent avant que les composants reviennent : ordre de comptabilisation sans incidence.
		return Db::transaction(
			static function () use ( $ctx, $p, $conf, $d, $post, $back, $out_rows, $reason, $wpdb, $ct ) {
				$ids = array_column( $post, '_id' );
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'PROD', 'ref_id' => (int) $p['id'], 'ref_no' => $p['prod_no'], 'text' => 'Annulation confirmation ' . $conf['conf_no'], 'tcode' => 'CORS', 'rev_of' => $d['doc_no'] ), array_map( static function ( $l ) {
					unset( $l['_id'] );
					return $l;
				}, $post ) );
				foreach ( $ids as $id ) {
					$wpdb->update( Db::t( 'material_doc_item' ), array( 'reversed' => 1 ), array( 'id' => $id ) );
				}
				$pc    = Db::t( 'production_component' );
				$comps = array();
				foreach ( Production::components( (int) $p['id'] ) as $c ) {
					$comps[ (int) $c['material_id'] ] = $c;
				}
				foreach ( $back as $mid => $q ) {
					// Emballage de substitution : la quantité revient sur le composant d'emballage prévu.
					$cid = self::component_for( $comps, Catalog::material( (int) $mid ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$pc} SET qty_issued = GREATEST(0, qty_issued - %f) WHERE prod_id = %d AND material_id = %d", $q, (int) $p['id'], $cid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				foreach ( $out_rows as [ $row, $q ] ) {
					Warehouse::cancel_open_putaway_for_row( $ctx, $row, $q );
				}
				$done  = max( 0.0, (float) $p['qty_done'] - (float) $conf['qty_good'] );
				$scr   = max( 0.0, (float) $p['qty_scrap'] - (float) $conf['qty_scrap'] );
				$state = $done + $scr > 0.0005 ? 'PARTIELLEMENT_CONFIRME' : 'LANCE';
				Db::update( 'production_order', array( 'qty_done' => $done, 'qty_scrap' => $scr, 'status' => $state ), array( 'id' => (int) $p['id'] ) );
				$wpdb->update( $ct, array( 'cancelled' => 1, 'cancel_reason' => mb_substr( $reason, 0, 120 ), 'cancel_doc' => $doc ), array( 'id' => (int) $conf['id'] ) );
				return array( 'doc_no' => $doc, 'no' => $p['prod_no'], 'message' => sprintf( 'Confirmation %s annulée, document %s : le produit est sorti du stock, les composants y sont revenus. Contrôlez dans LS26.', $conf['conf_no'], $doc ) );
			}
		);
	}

	/** Composant de l'ordre couvert par un article consommé (lui-même, ou l'emballage prévu qu'il remplace). */
	private static function component_for( array $comps, ?array $m ): int {
		if ( ! $m ) {
			return 0;
		}
		if ( isset( $comps[ (int) $m['id'] ] ) ) {
			return (int) $m['id'];
		}
		if ( in_array( $m['type'], array( 'EMB', 'VERP' ), true ) ) {
			foreach ( array_keys( $comps ) as $mid ) {
				$cm = Catalog::material( (int) $mid );
				if ( $cm && in_array( $cm['type'], array( 'EMB', 'VERP' ), true ) && $cm['base_uom'] === $m['base_uom'] && self::same_family( $cm, $m ) ) {
					return (int) $mid;
				}
			}
		}
		return 0;
	}

	/** Emballages interchangeables : même famille de contenant (fût, bidon, GRV…), d'après la désignation. */
	private static function same_family( array $a, array $b ): bool {
		$fam = static function ( string $d ): string {
			$d = mb_strtolower( $d );
			foreach ( array( 'fût', 'bidon', 'grv', 'ibc', 'palette', 'étiquette', 'carton', 'sac' ) as $f ) {
				if ( str_contains( $d, $f ) ) {
					return $f;
				}
			}
			return $d;
		};
		return $fam( (string) $a['designation'] ) === $fam( (string) $b['designation'] );
	}

	/** CS03 : nomenclature (recette) d'un article. */
	public static function cs03( Ctx $ctx, string $material ): array {
		$m = Catalog::material_by_no( $material );
		if ( ! $m ) {
			throw new DomainError( sprintf( 'Article %s introuvable.', $material ) );
		}
		$bom = Production::bom( (int) $m['id'] );
		if ( ! $bom ) {
			throw new DomainError( sprintf( 'L\'article %s n\'a pas de nomenclature.', $m['material_no'] ) );
		}
		$items = array();
		foreach ( $bom['items'] as $k => $bi ) {
			$c       = Catalog::material( (int) $bi['component_id'] );
			$items[] = array( 'pos' => ( $k + 1 ) * 10, 'material' => Api::material_summary( $c ) + array( 'type' => $c['type'] ), 'qty' => (float) $bi['qty'], 'scrap_pct' => (float) $bi['scrap_pct'], 'available' => StockService::available( $ctx->id, (int) $c['id'] ) );
		}
		return array( 'material' => Api::material_summary( $m ), 'base_qty' => 1, 'version' => $bom['bom']['version'], 'work_center' => $bom['bom']['work_center'], 'minutes' => (float) $bom['bom']['minutes_per_unit'], 'items' => $items );
	}

	/** CS15 : cas d'emploi d'un composant (dans quels articles il entre). */
	public static function cs15( Ctx $ctx, string $material ): array {
		global $wpdb;
		$m = Catalog::material_by_no( $material );
		if ( ! $m ) {
			throw new DomainError( sprintf( 'Article %s introuvable.', $material ) );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT b.material_id, b.work_center, bi.qty FROM ' . Db::t( 'bom_item' ) . ' bi JOIN ' . Db::t( 'bom' ) . ' b ON b.id = bi.bom_id WHERE bi.component_id = %d ORDER BY b.material_id LIMIT 200', (int) $m['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$p = Catalog::material( (int) $r['material_id'] );
			if ( $p ) {
				$out[] = array( 'material' => Api::material_summary( $p ), 'qty' => (float) $r['qty'], 'work_center' => $r['work_center'] );
			}
		}
		return array( 'material' => Api::material_summary( $m ), 'used_in' => $out );
	}
}
