<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestion des stocks « comme dans SAP » : MB11 (mouvements par code), MBST (annulation d'un document d'article),
 * MB03 (affichage), LS26 (stock de la gestion des emplacements), LT13 (confirmation par unité de stock),
 * LT15 (annulation d'un ordre de transfert), LI21 (soldes de l'aire des écarts 999).
 */
final class StockTx {

	/** Codes MB11 disponibles et leur libellé. */
	public const MB11 = array(
		'201'  => 'Sortie pour centre de coûts (usage interne)',
		'309'  => 'Transfert d\'article à article',
		'311'  => 'Transfert de magasin à magasin (vers la production)',
		'312'  => 'Retour du magasin de production vers le stock',
		'343'  => 'Stock bloqué → stock libre',
		'344'  => 'Stock libre → stock bloqué',
		'411E' => 'Stock client (E) → stock libre (libération avant livraison)',
		'412E' => 'Stock libre → stock client (E) (réservation pour une commande)',
		'501'  => 'Entrée sans commande (imputation centre de coûts)',
		'551'  => 'Mise au rebut',
		'924'  => 'Conteneur loué repris en stock propre',
	);

	/** Code d'annulation (MBST) de chaque mouvement. */
	public const REVERSAL = array( '101' => '102', '201' => '202', '309' => '310', '311' => '312', '343' => '344', '344' => '343', '411' => '412', '412' => '411', '501' => '502', '551' => '552', '924' => '925' );

	/** Codes d'écart à la confirmation d'un OT (LT12). */
	public const DIFF_CODES = array(
		'0' => 'Perte de citerne (fond non dépotable)',
		'1' => 'Casse ou avarie constatée',
		'2' => 'Quantité manquante',
	);

	/** Aire des écarts (999). */
	public const DIFF_LOCATION = '999';

	public static function diff_bin( string $kind = 'E' ): array {
		global $wpdb;
		$loc = Catalog::location_by_code( self::DIFF_LOCATION );
		if ( ! $loc ) {
			throw new DomainError( 'L\'aire des écarts (999) n\'existe pas : mettez à jour les données (Outils et santé).' );
		}
		$like = self::DIFF_LOCATION . '-' . ( 'SCHROTT' === $kind ? 'SCHROTT' : 'E' ) . '-%';
		$id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bin' ) . ' WHERE storage_location_id = %d AND code LIKE %s ORDER BY code LIMIT 1', (int) $loc['id'], $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( $loc, $id ? Catalog::bin( $id ) : null );
	}

	/** Numéro d'unité de stock (SU) d'une ligne d'OT. */
	public static function su_no( int $to_item_id ): string {
		return (string) ( 1000000000 + $to_item_id );
	}

	private static function clean_cc( string $v ): string {
		return substr( (string) preg_replace( '/\D/', '', $v ), 0, 10 );
	}

	/** Ligne de stock de la session, contrôlée (quantité, magasin sous clé, emplacement bloqué). */
	private static function row( Ctx $ctx, int $stock_id, float $qty ): array {
		$row = StockService::row( $ctx->id, $stock_id );
		if ( ! $row ) {
			throw new DomainError( 'Ligne de stock introuvable : choisissez-la dans la liste.' );
		}
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité invalide : %s disponibles sur cette ligne de stock.', Catalog::format_qty( (float) $row['qty'] ) ) );
		}
		$loc = Catalog::location( (int) $row['storage_location_id'] );
		$ctx->require_key_for( $loc );
		if ( (int) $row['bin_id'] ) {
			$bin = Catalog::bin( (int) $row['bin_id'] );
			if ( $bin && '' !== Rules::bin_blocked( $ctx->id, $bin, 'out' ) ) {
				throw new DomainError( sprintf( 'L\'emplacement %s est bloqué en sortie.', $bin['code'] ) );
			}
		}
		return array( $row, Catalog::material( (int) $row['material_id'] ), $loc );
	}

	private static function line( string $mvt, array $row, float $qty, array $extra = array() ): array {
		return $extra + array(
			'movement_type' => $mvt,
			'material_id'   => (int) $row['material_id'],
			'location_id'   => (int) $row['storage_location_id'],
			'bin_id'        => (int) $row['bin_id'],
			'batch'         => (string) $row['batch'],
			'bbd'           => $row['bbd'],
			'status'        => (string) $row['status'],
			'special_ref'   => (string) ( $row['special_ref'] ?? '' ),
			'qty'           => $qty,
			'gr_date'       => $qty > 0 ? ( $row['gr_date'] ?? null ) : null,
		);
	}

	private static function header( Ctx $ctx, string $text, string $ref_type = 'MB11', string $ref_no = '', int $ref_id = 0 ): array {
		return array( 'posting_date' => $ctx->date(), 'ref_type' => $ref_type, 'ref_id' => $ref_id, 'ref_no' => $ref_no, 'text' => $text, 'tcode' => 'MB11' );
	}

	/** Lot interne attribué par le système (le lot du fournisseur va dans « lot fournisseur »). */
	public static function new_batch( Ctx $ctx ): string {
		return NumberRange::next( $ctx->id, 'BATCH' );
	}

	/**
	 * MB11 : mouvement de stock selon son code.
	 *
	 * @param array $in stock_id, qty, material_id, location, cost_center, gl_account, so_no, so_line, batch, reason, text, customer_id, serial
	 */
	public static function mb11( Ctx $ctx, string $mvt, array $in ): array {
		$mvt = strtoupper( $mvt );
		if ( ! isset( self::MB11[ $mvt ] ) ) {
			throw new DomainError( 'Code mouvement non prévu dans MB11. Codes possibles : ' . implode( ', ', array_keys( self::MB11 ) ) . '.' );
		}
		$qty    = round( (float) ( $in['qty'] ?? 0 ), 3 );
		$reason = mb_substr( trim( (string) ( $in['reason'] ?? '' ) ), 0, 120 );
		$text   = mb_substr( trim( (string) ( $in['text'] ?? '' ) ), 0, 255 );
		if ( $qty <= 0 || $qty > Purchasing::MAX_QTY ) {
			throw new DomainError( 'Indiquez une quantité positive.' );
		}
		switch ( $mvt ) {
			case '201':
				return self::mb11_201( $ctx, $in, $qty, $reason, $text );
			case '551':
				[ $row ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
				if ( '' === $reason ) {
					throw new DomainError( 'Indiquez le motif de la mise au rebut.' );
				}
				$doc = StockService::post( $ctx->id, self::header( $ctx, $text ?: 'Mise au rebut' ), array( self::line( '551', $row, -$qty, array( 'reason' => $reason ) ) ) );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Mise au rebut (551) enregistrée, document %s.', $doc ) );
			case '343':
			case '344':
				[ $row ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
				$from    = '344' === $mvt ? 'LIBRE' : 'BLOQUE';
				$to      = '344' === $mvt ? 'BLOQUE' : 'LIBRE';
				if ( $row['status'] !== $from ) {
					throw new DomainError( sprintf( 'Le mouvement %s part du stock %s : cette ligne est en %s.', $mvt, 'LIBRE' === $from ? 'libre' : 'bloqué', strtolower( str_replace( '_', ' ', $row['status'] ) ) ) );
				}
				if ( '343' === $mvt && $row['bbd'] && $row['bbd'] < $ctx->date() ) {
					throw new DomainError( 'Lot périmé : il ne peut pas être libéré. Mettez-le au rebut.' );
				}
				$doc = StockService::post( $ctx->id, self::header( $ctx, $text ?: ( '344' === $mvt ? 'Blocage de stock' : 'Déblocage de stock' ) ), array( self::line( $mvt, $row, -$qty, array( 'reason' => $reason ) ), self::line( $mvt, $row, $qty, array( 'status' => $to, 'reason' => $reason ) ) ) );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Mouvement %s enregistré, document %s : le stock est maintenant %s.', $mvt, $doc, 'BLOQUE' === $to ? 'bloqué' : 'libre' ) );
			case '309':
				return self::mb11_309( $ctx, $in, $qty, $reason, $text );
			case '311':
			case '312':
				return self::mb11_311( $ctx, $mvt, $in, $qty, $text );
			case '411E':
			case '412E':
				return self::mb11_e( $ctx, $mvt, $in, $qty, $text );
			case '501':
				return self::mb11_501( $ctx, $in, $qty, $reason, $text );
			case '924':
				return Containers::take_back( $ctx, $in, $text );
		}
		throw new DomainError( 'Code mouvement non prévu.' );
	}

	private static function mb11_201( Ctx $ctx, array $in, float $qty, string $reason, string $text ): array {
		[ $row, $m ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
		if ( 'LIBRE' !== $row['status'] ) {
			throw new DomainError( 'Seul le stock libre peut sortir pour un usage interne.' );
		}
		$cc  = self::clean_cc( (string) ( $in['cost_center'] ?? '' ) );
		$acc = self::clean_cc( (string) ( $in['gl_account'] ?? '' ) );
		$err = array();
		if ( ! preg_match( '/^\d{4,10}$/', $cc ) ) {
			$err[] = 'Centre de coûts obligatoire (4 chiffres ou plus, par exemple 6200).';
		}
		if ( ! preg_match( '/^\d{6}$/', $acc ) ) {
			$err[] = 'Compte général obligatoire (6 chiffres, par exemple 670000).';
		}
		if ( $err ) {
			throw new DomainError( 'Imputation incomplète.', $err );
		}
		$avail = StockService::available( $ctx->id, (int) $m['id'] );
		$doc   = StockService::post( $ctx->id, self::header( $ctx, $text ?: 'Usage interne' ), array( self::line( '201', $row, -$qty, array( 'cost_center' => $cc, 'gl_account' => $acc, 'reason' => $reason ) ) ) );
		$msg   = sprintf( 'Sortie 201 enregistrée, document %s (centre de coûts %s, compte %s).', $doc, $cc, $acc );
		if ( $qty > $avail + 0.0005 ) {
			$msg .= ' Attention : cette quantité était réservée pour des commandes, un besoin va apparaître (MD04).';
		}
		return array( 'doc_no' => $doc, 'message' => $msg );
	}

	private static function mb11_309( Ctx $ctx, array $in, float $qty, string $reason, string $text ): array {
		[ $row, $m ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
		$to          = Catalog::material( (int) ( $in['material_id'] ?? 0 ) );
		if ( ! $to ) {
			throw new DomainError( 'Indiquez l\'article prenant (celui qui reçoit la quantité).' );
		}
		if ( (int) $to['id'] === (int) $m['id'] ) {
			throw new DomainError( 'L\'article prenant doit être différent de l\'article cédant.' );
		}
		if ( $to['base_uom'] !== $m['base_uom'] ) {
			throw new DomainError( sprintf( 'Unités différentes (%s / %s) : un transfert d\'article à article se fait dans la même unité.', $m['base_uom'], $to['base_uom'] ) );
		}
		if ( 'LIBRE' !== $row['status'] ) {
			throw new DomainError( 'Le transfert d\'article à article part du stock libre.' );
		}
		if ( '' === $reason ) {
			throw new DomainError( 'Indiquez le motif (par exemple : conteneurs neufs utilisés comme lavés pour la production).' );
		}
		$batch = ! empty( $to['batch_managed'] ) ? ( '' !== (string) $row['batch'] ? (string) $row['batch'] : self::new_batch( $ctx ) ) : '';
		$dst   = array( 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'] );
		$moved = false;
		if ( (int) $row['bin_id'] ) {
			$bin = Catalog::bin( (int) $row['bin_id'] );
			if ( Rules::check_bin( $ctx->id, $to, $bin, $qty, $batch ) ) {
				// L'article prenant ne peut pas partager l'emplacement : il passe par la zone de transfert (quai 100) et un OT le range.
				[ $tbin, $tloc ] = Receiving::reception_bin( $ctx );
				$dst             = array( 'location_id' => (int) $tloc['id'], 'bin_id' => (int) $tbin['id'] );
				$moved           = true;
			}
		}
		return Db::transaction(
			static function () use ( $ctx, $row, $m, $to, $qty, $batch, $dst, $moved, $reason, $text ) {
				$bbd = ! empty( $to['shelf_life_days'] ) ? $row['bbd'] : null;
				$doc = StockService::post(
					$ctx->id,
					self::header( $ctx, $text ?: 'Transfert article à article ' . $m['material_no'] . ' → ' . $to['material_no'] ),
					array(
						self::line( '309', $row, -$qty, array( 'reason' => $reason ) ),
						self::line( '309', $row, $qty, array( 'material_id' => (int) $to['id'], 'batch' => $batch, 'bbd' => $bbd, 'reason' => $reason, 'location_id' => $dst['location_id'], 'bin_id' => $dst['bin_id'] ) ),
					)
				);
				$msg = sprintf( 'Transfert 309 enregistré, document %s : %s %s de %s passent sur l\'article %s.', $doc, Catalog::format_qty( $qty ), $m['base_uom'], $m['material_no'], $to['material_no'] );
				$to_no = '';
				if ( $moved ) {
					$res   = Warehouse::create_putaway( $ctx, 'MB11', 0, array( array( 'm' => $to, 'qty' => $qty, 'batch' => $batch, 'bbd' => $bbd, 'status' => 'LIBRE', 'src_location_id' => $dst['location_id'], 'src_bin_id' => $dst['bin_id'] ) ) );
					$to_no = $res['to_no'];
					$msg  .= sprintf( ' L\'article prenant ne peut pas rester à l\'emplacement d\'origine : il attend en zone de transfert, rangez-le avec l\'OT %s (LS26).', $to_no );
				}
				return array( 'doc_no' => $doc, 'to_no' => $to_no, 'message' => $msg );
			}
		);
	}

	/** 311 (stock → production) et 312 (production → stock) ; en gestion détaillée, un OT est à quittancer (LS26). */
	private static function mb11_311( Ctx $ctx, string $mvt, array $in, float $qty, string $text ): array {
		[ $row, $m, $src ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
		$dst               = Catalog::location_by_code( (string) ( $in['location'] ?? '' ) );
		if ( ! $dst ) {
			throw new DomainError( 'Indiquez le magasin prenant.' );
		}
		if ( ! empty( $dst['is_transit'] ) ) {
			throw new DomainError( sprintf( 'Le magasin %s est une zone de transit : choisissez un magasin de stockage ou de production.', $dst['code'] ) );
		}
		if ( (int) $dst['id'] === (int) $src['id'] ) {
			throw new DomainError( 'Le magasin prenant est identique au magasin cédant.' );
		}
		if ( '312' === $mvt && 'PROD' !== $src['zone_type'] ) {
			throw new DomainError( 'Le mouvement 312 ramène du stock d\'un magasin de production vers le stock : la ligne choisie n\'est pas dans un magasin de production.' );
		}
		if ( '312' === $mvt && 'PROD' === $dst['zone_type'] ) {
			throw new DomainError( 'Avec 312, le magasin prenant est un magasin de stockage (pas de production).' );
		}
		if ( 'LIBRE' !== $row['status'] ) {
			throw new DomainError( 'Seul le stock libre se transfère avec MB11 311 / 312.' );
		}
		$ctx->require_key_for( $dst );
		$le = 'PROD' === $dst['zone_type'] ? array() : Rules::check_location( $m, $dst );
		if ( $le ) {
			throw new DomainError( 'Magasin prenant non conforme.', $le );
		}
		$label = '311' === $mvt ? 'Transfert vers ' . $dst['code'] : 'Retour au stock ' . $dst['code'];
		if ( $ctx->simple() ) {
			$doc = StockService::post( $ctx->id, self::header( $ctx, $text ?: $label ), array( self::line( $mvt, $row, -$qty ), self::line( $mvt, $row, $qty, array( 'location_id' => (int) $dst['id'], 'bin_id' => 0 ) ) ) );
			return array( 'doc_no' => $doc, 'message' => sprintf( 'Transfert %s enregistré, document %s.', $mvt, $doc ) );
		}
		$exclude = 'PROD' === $dst['zone_type'] ? array( 'PICK', 'TRANSIT' ) : array( 'PICK', 'PROD', 'TRANSIT' );
		$prop    = 0;
		try {
			$p    = Strategies::propose_putaway( $ctx->id, $m, $qty, (string) $row['batch'], $ctx->rng(), array( 'location_ids' => array( (int) $dst['id'] ), 'exclude_zone_types' => $exclude ) );
			$prop = (int) $p['bin']['id'];
		} catch ( DomainError $e ) {
			throw new DomainError( sprintf( 'Aucun emplacement libre et conforme dans le magasin %s pour cette quantité.', $dst['code'] ) );
		}
		return Db::transaction(
			static function () use ( $ctx, $row, $m, $qty, $dst, $prop, $mvt ) {
				$no    = NumberRange::next( $ctx->id, 'TO' );
				$to_id = Db::insert( 'transfer_order', array( 'session_id' => $ctx->id, 'to_no' => $no, 'type' => 'MOVE', 'ref_type' => 'MB11', 'status' => 'OUVERT', 'created_at' => Db::now() ) );
				Db::insert( 'transfer_order_item', array( 'to_id' => $to_id, 'line_no' => 1, 'material_id' => (int) $m['id'], 'batch' => (string) $row['batch'], 'bbd' => $row['bbd'] ?: null, 'stock_status' => 'LIBRE', 'src_location_id' => (int) $row['storage_location_id'], 'src_bin_id' => (int) $row['bin_id'], 'dst_location_id' => (int) $dst['id'], 'proposed_bin_id' => $prop, 'qty' => $qty ) );
				$bin = Catalog::bin( $prop );
				return array( 'no' => $no, 'to_no' => $no, 'message' => sprintf( 'Transfert %s saisi : ordre de transfert %s créé vers %s. Quittancez-le dans LS26 (ou LT12) une fois la marchandise déplacée.', $mvt, $no, $bin ? $bin['code'] : $dst['code'] ) );
			}
		);
	}

	/** Référence d'un poste de commande client pour le stock E. */
	public static function so_ref( string $so_no, int $line ): string {
		return $so_no . '/' . $line;
	}

	private static function so_item( Ctx $ctx, string $so_no, int $line ): array {
		global $wpdb;
		$so = Sales::by_no( $ctx, $so_no );
		if ( ! $so ) {
			throw new DomainError( sprintf( 'Commande client %s introuvable.', $so_no ) );
		}
		$it = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'sales_order_item' ) . ' WHERE so_id = %d AND line_no = %d', (int) $so['id'], $line ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $it ) {
			throw new DomainError( sprintf( 'La commande %s n\'a pas de poste %d.', $so_no, $line ) );
		}
		return array( $so, $it );
	}

	public static function e_stock( Ctx $ctx, string $ref, int $material_id ): float {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(qty),0) FROM ' . Db::t( 'stock' ) . " WHERE session_id = %d AND material_id = %d AND status = 'STOCK_E' AND special_ref = %s", $ctx->id, $material_id, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** 412 E : réservation physique pour un poste de commande client ; 411 E : libération avant la livraison. */
	private static function mb11_e( Ctx $ctx, string $mvt, array $in, float $qty, string $text ): array {
		global $wpdb;
		$so_no = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['so_no'] ?? '' ) ) );
		$line  = (int) ( $in['so_line'] ?? 0 );
		if ( '' === $so_no || $line <= 0 ) {
			throw new DomainError( 'Stock spécial E : indiquez le n° de commande client et le n° de poste (ex. 10).' );
		}
		[ $so, $it ] = self::so_item( $ctx, $so_no, $line );
		if ( in_array( $so['status'], array( 'LIVREE', 'ANNULEE' ), true ) ) {
			throw new DomainError( sprintf( 'La commande %s est %s : plus de réservation possible.', $so_no, strtolower( $so['status'] ) ) );
		}
		[ $row, $m ] = self::row( $ctx, (int) ( $in['stock_id'] ?? 0 ), $qty );
		if ( (int) $m['id'] !== (int) $it['material_id'] ) {
			throw new DomainError( sprintf( 'Le poste %d de la commande %s concerne un autre article.', $line, $so_no ) );
		}
		$ref = self::so_ref( $so_no, $line );
		$si  = Db::t( 'sales_order_item' );
		if ( '412E' === $mvt ) {
			if ( 'LIBRE' !== $row['status'] ) {
				throw new DomainError( 'La réservation 412 E part du stock libre.' );
			}
			$open = (float) $it['qty'] - (float) $it['qty_delivered'] - self::e_stock( $ctx, $ref, (int) $m['id'] );
			if ( $qty > $open + 0.0005 ) {
				throw new DomainError( sprintf( 'Vous réservez plus que la quantité ouverte du poste (%s %s).', Catalog::format_qty( max( 0, $open ) ), $m['base_uom'] ) );
			}
			return Db::transaction(
				static function () use ( $ctx, $row, $qty, $ref, $text, $wpdb, $si, $it, $so_no, $line ) {
					$doc = StockService::post( $ctx->id, self::header( $ctx, $text ?: 'Réservation stock client ' . $ref, 'SO', $so_no ), array( self::line( '412', $row, -$qty ), self::line( '412', $row, $qty, array( 'status' => 'STOCK_E', 'special_ref' => $ref ) ) ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = GREATEST(0, qty_reserved - %f) WHERE id = %d", $qty, (int) $it['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					return array( 'doc_no' => $doc, 'message' => sprintf( 'Mouvement 412 E enregistré, document %s : la marchandise est réservée à la commande %s, poste %d (stock client E), et n\'est plus disponible pour les autres commandes.', $doc, $so_no, $line ) );
				}
			);
		}
		if ( 'STOCK_E' !== $row['status'] || $row['special_ref'] !== $ref ) {
			throw new DomainError( sprintf( 'Choisissez une ligne de stock client E de la commande %s, poste %d.', $so_no, $line ) );
		}
		return Db::transaction(
			static function () use ( $ctx, $row, $qty, $ref, $text, $wpdb, $si, $it, $so_no, $line ) {
				$doc = StockService::post( $ctx->id, self::header( $ctx, $text ?: 'Libération stock client ' . $ref, 'SO', $so_no ), array( self::line( '411', $row, -$qty ), self::line( '411', $row, $qty, array( 'status' => 'LIBRE', 'special_ref' => '' ) ) ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = LEAST(GREATEST(0, qty - qty_delivered), qty_reserved + %f) WHERE id = %d", $qty, (int) $it['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Mouvement 411 E enregistré, document %s : la quantité est libérée pour livrer la commande %s, poste %d.', $doc, $so_no, $line ) );
			}
		);
	}

	/** 501 : entrée sans commande, centre de coûts obligatoire ; en gestion détaillée, arrivée au quai + OT d'entreposage. */
	private static function mb11_501( Ctx $ctx, array $in, float $qty, string $reason, string $text ): array {
		$m = Catalog::material( (int) ( $in['material_id'] ?? 0 ) );
		if ( ! $m || 'ACTIF' !== $m['status'] ) {
			throw new DomainError( 'Article introuvable ou inactif.' );
		}
		$cc = self::clean_cc( (string) ( $in['cost_center'] ?? '' ) );
		if ( ! preg_match( '/^\d{4,10}$/', $cc ) ) {
			throw new DomainError( 'Centre de coûts obligatoire pour une entrée sans commande (par exemple 9942).' );
		}
		$batch = strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['batch'] ?? '' ) ) );
		$batch = ! empty( $m['batch_managed'] ) ? ( '' !== $batch ? mb_substr( $batch, 0, 20 ) : self::new_batch( $ctx ) ) : '';
		$bbd   = null;
		if ( (int) $m['shelf_life_days'] > 0 ) {
			$bbd = Api::valid_date( (string) ( $in['bbd'] ?? '' ) ) ? (string) $in['bbd'] : gmdate( 'Y-m-d', strtotime( $ctx->date() . ' +' . (int) $m['shelf_life_days'] . ' days' ) );
		}
		if ( $ctx->simple() ) {
			$loc = Catalog::location_by_code( (string) ( $in['location'] ?? '' ) );
			if ( ! $loc ) {
				throw new DomainError( 'Indiquez le magasin de destination.' );
			}
			$le = Rules::check_location( $m, $loc );
			if ( $le ) {
				throw new DomainError( 'Magasin non conforme.', $le );
			}
			$ctx->require_key_for( $loc );
			$bin_id = 0;
		} else {
			[ $bin, $loc ] = Receiving::reception_bin( $ctx );
			$bin_id        = (int) $bin['id'];
		}
		return Db::transaction(
			static function () use ( $ctx, $m, $qty, $batch, $bbd, $loc, $bin_id, $cc, $reason, $text ) {
				$doc   = StockService::post( $ctx->id, self::header( $ctx, $text ?: 'Entrée sans commande' ), array( array( 'movement_type' => '501', 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => $bin_id, 'batch' => $batch, 'bbd' => $bbd, 'status' => 'LIBRE', 'qty' => $qty, 'cost_center' => $cc, 'reason' => $reason ) ) );
				$to_no = '';
				if ( ! $ctx->simple() ) {
					$res   = Warehouse::create_putaway( $ctx, 'MB11', 0, array( array( 'm' => $m, 'qty' => $qty, 'batch' => $batch, 'bbd' => $bbd, 'status' => 'LIBRE', 'src_location_id' => (int) $loc['id'], 'src_bin_id' => $bin_id ) ) );
					$to_no = $res['to_no'];
				}
				return array( 'doc_no' => $doc, 'to_no' => $to_no, 'batch' => $batch, 'message' => sprintf( 'Entrée 501 enregistrée, document %s%s.', $doc, '' !== $batch ? ', lot ' . $batch : '' ) . ( $to_no ? sprintf( ' Ordre d\'entreposage %s à quittancer (LS26 ou LT12).', $to_no ) : '' ) );
			}
		);
	}

	/* ───────── Documents d'article : MB03 et MBST ───────── */

	public static function doc( Ctx $ctx, string $doc_no ): array {
		global $wpdb;
		$d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'material_doc' ) . ' WHERE session_id = %d AND doc_no = %s', $ctx->id, $doc_no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $d ) {
			throw new DomainError( sprintf( 'Document d\'article %s introuvable.', $doc_no ) );
		}
		return $d;
	}

	public static function doc_items( int $mdoc_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'material_doc_item' ) . ' WHERE mdoc_id = %d ORDER BY line_no', $mdoc_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** MB03 : affichage d'un document d'article. */
	public static function mb03( Ctx $ctx, string $doc_no ): array {
		global $wpdb;
		$d     = self::doc( $ctx, $doc_no );
		$items = array();
		foreach ( self::doc_items( (int) $d['id'] ) as $i ) {
			$m       = Catalog::material( (int) $i['material_id'] );
			$l       = Catalog::location( (int) $i['storage_location_id'] );
			$b       = (int) $i['bin_id'] ? Catalog::bin( (int) $i['bin_id'] ) : null;
			$items[] = array(
				'line_no'      => (int) $i['line_no'],
				'movement'     => $i['movement_type'],
				'movement_label' => StockService::MOVEMENTS[ $i['movement_type'] ] ?? '',
				'material'     => Api::material_summary( $m ),
				'location'     => $l ? $l['code'] : '',
				'bin'          => $b ? $b['code'] : '',
				'batch'        => $i['batch'],
				'bbd'          => $i['bbd'],
				'status'       => $i['stock_status'],
				'special_ref'  => $i['special_ref'],
				'qty'          => (float) $i['qty'],
				'value'        => (float) $i['value'],
				'reason'       => $i['reason'],
				'cost_center'  => $i['cost_center'],
				'gl_account'   => $i['gl_account'],
				'cert'         => $i['cert'],
				'mfg_date'     => $i['mfg_date'],
				'vendor_batch' => $i['vendor_batch'],
				'pack_count'   => (int) $i['pack_count'],
				'pack_qty'     => (float) $i['pack_qty'],
				'tus'          => $i['tus'],
				'reversed'     => (int) $i['reversed'],
				'reversible'   => self::reversible( $d, $i ) ? 1 : 0,
			);
		}
		$rev = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT doc_no FROM ' . Db::t( 'material_doc' ) . ' WHERE session_id = %d AND rev_of = %s ORDER BY id', $ctx->id, $doc_no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$user = get_userdata( (int) $d['created_by'] );
		return array(
			'doc_no'      => $d['doc_no'],
			'posting_date' => $d['posting_date'],
			'created_at'  => $d['created_at'],
			'created_by'  => $user ? $user->display_name : '',
			'ref_type'    => $d['ref_type'],
			'ref_no'      => $d['ref_no'],
			'text'        => $d['header_text'],
			'tcode'       => $d['tcode'],
			'rev_of'      => $d['rev_of'],
			'reversed_by' => $rev,
			'items'       => $items,
		);
	}

	private static function reversible( array $d, array $i ): bool {
		if ( (int) $i['reversed'] || '' !== (string) $d['rev_of'] || ! isset( self::REVERSAL[ $i['movement_type'] ] ) ) {
			return false;
		}
		if ( in_array( $d['ref_type'], array( 'TO', 'PROD', 'DLV', 'INV', 'RET', 'QL' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * MBST : annulation de postes d'un document d'article (mouvement inverse, référence au document d'origine).
	 *
	 * @param int[] $lines postes à annuler (vide = tous)
	 */
	public static function mbst( Ctx $ctx, string $doc_no, array $lines, string $reason ): array {
		global $wpdb;
		$d = self::doc( $ctx, $doc_no );
		if ( '' !== (string) $d['rev_of'] ) {
			throw new DomainError( sprintf( 'Le document %s est lui-même une annulation : il ne peut pas être annulé.', $doc_no ) );
		}
		$why = array( 'TO' => 'Un mouvement fait par un ordre de transfert se corrige par un nouvel OT (LT10, LT01).', 'PROD' => 'Une confirmation de production s\'annule avec CORS.', 'DLV' => 'Une sortie de marchandises s\'annule avec VL09.', 'INV' => 'Un écart d\'inventaire ne s\'annule pas : refaites un comptage.', 'RET' => 'Un retour client se traite dans ZRET.', 'QL' => 'Une décision d\'emploi ne s\'annule pas avec MBST.' );
		if ( isset( $why[ $d['ref_type'] ] ) ) {
			throw new DomainError( $why[ $d['ref_type'] ] );
		}
		$all   = self::doc_items( (int) $d['id'] );
		$want  = array_map( 'intval', $lines );
		$pick  = array();
		foreach ( $all as $i ) {
			if ( $want && ! in_array( (int) $i['line_no'], $want, true ) ) {
				continue;
			}
			if ( (int) $i['reversed'] ) {
				continue;
			}
			if ( ! isset( self::REVERSAL[ $i['movement_type'] ] ) ) {
				throw new DomainError( sprintf( 'Poste %d : le mouvement %s ne s\'annule pas avec MBST.', (int) $i['line_no'], $i['movement_type'] ) );
			}
			$pick[] = $i;
		}
		if ( ! $pick ) {
			throw new DomainError( 'Aucun poste à annuler (déjà annulé ou non sélectionné).' );
		}
		// Les mouvements en deux postes (transfert, changement de statut) s'annulent ensemble.
		$pairs = array( '309', '311', '312', '343', '344', '411', '412' );
		foreach ( $pick as $i ) {
			if ( in_array( $i['movement_type'], $pairs, true ) && count( $pick ) !== count( array_filter( $all, static fn( $x ) => ! (int) $x['reversed'] ) ) ) {
				throw new DomainError( 'Ce document est un transfert : ses postes cédant et prenant s\'annulent ensemble. Sélectionnez tous les postes.' );
			}
		}
		$po     = 'PO' === $d['ref_type'] ? Purchasing::by_no( $ctx, (string) $d['ref_no'] ) : null;
		$post   = array();
		$po_upd = array();
		$open_putaway = array();
		foreach ( $pick as $i ) {
			$m    = Catalog::material( (int) $i['material_id'] );
			$rev  = self::REVERSAL[ $i['movement_type'] ];
			$qty  = -(float) $i['qty'];
			$seg  = array( 'material_id' => (int) $i['material_id'], 'storage_location_id' => (int) $i['storage_location_id'], 'bin_id' => (int) $i['bin_id'], 'batch' => (string) $i['batch'], 'bbd' => $i['bbd'], 'status' => (string) $i['stock_status'], 'special_ref' => (string) $i['special_ref'], 'gr_date' => null );
			if ( $qty < 0 ) {
				// Sortie à refaire : le stock est-il encore là ? Entrée sur commande : il a peut-être été rangé (OT confirmé).
				$have = Warehouse::stock_at( $ctx, (int) $i['material_id'], (int) $i['storage_location_id'], (int) $i['bin_id'], (string) $i['batch'], (string) $i['stock_status'] );
				$have = $have && (string) ( $have['special_ref'] ?? '' ) === (string) $i['special_ref'] ? $have : null;
				if ( ! $have || (float) $have['qty'] + 0.0005 < -$qty ) {
					$moved = self::find_moved( $ctx, $i, -$qty );
					if ( ! $moved ) {
						throw new DomainError( sprintf( 'Poste %d : le stock de l\'article %s (lot %s) n\'est plus là où il a été entré (utilisé ou déplacé). Annulation impossible : remettez d\'abord la quantité en stock.', (int) $i['line_no'], $m['material_no'], '' !== (string) $i['batch'] ? $i['batch'] : '—' ) );
					}
					$seg = array_merge( $seg, array( 'storage_location_id' => (int) $moved['storage_location_id'], 'bin_id' => (int) $moved['bin_id'] ) );
				} else {
					$open_putaway[] = array( $have, -$qty );
				}
			}
			$post[] = array( 'movement_type' => $rev, 'material_id' => $seg['material_id'], 'location_id' => $seg['storage_location_id'], 'bin_id' => $seg['bin_id'], 'batch' => $seg['batch'], 'bbd' => $seg['bbd'], 'status' => $seg['status'], 'special_ref' => $seg['special_ref'], 'qty' => $qty, 'reason' => mb_substr( $reason, 0, 120 ), 'cost_center' => $i['cost_center'] );
			if ( $po && '101' === $i['movement_type'] ) {
				$po_upd[] = array( (int) $i['material_id'], (float) $i['qty'] );
			}
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif de l\'annulation.' );
		}
		return Db::transaction(
			static function () use ( $ctx, $d, $post, $pick, $po, $po_upd, $open_putaway, $wpdb ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => $d['ref_type'], 'ref_id' => (int) $d['ref_id'], 'ref_no' => $d['ref_no'], 'text' => 'Annulation du document ' . $d['doc_no'], 'tcode' => 'MBST', 'rev_of' => $d['doc_no'] ), $post );
				$it  = Db::t( 'material_doc_item' );
				foreach ( $pick as $i ) {
					$wpdb->update( $it, array( 'reversed' => 1 ), array( 'id' => (int) $i['id'] ) );
				}
				// Entrée sur commande annulée : la quantité redevient à recevoir, l'entreposage ouvert est réduit.
				if ( $po ) {
					$pi = Db::t( 'purchase_order_item' );
					foreach ( $po_upd as [ $mid, $q ] ) {
						$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$pi} WHERE po_id = %d AND material_id = %d AND qty_received > 0 ORDER BY line_no LIMIT 1", (int) $po['id'], $mid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( $row ) {
							$wpdb->query( $wpdb->prepare( "UPDATE {$pi} SET qty_received = GREATEST(0, qty_received - %f), final_delivery = 0 WHERE id = %d", $q, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						}
					}
					Purchasing::refresh_status( (int) $po['id'] );
				}
				foreach ( $open_putaway as [ $row, $q ] ) {
					Warehouse::cancel_open_putaway_for_row( $ctx, $row, $q );
				}
				// Reprise de conteneur annulée : le conteneur redevient loué au client.
				foreach ( $pick as $i ) {
					$serial = '' !== (string) $i['vendor_batch'] ? (string) $i['vendor_batch'] : (string) $i['batch'];
					if ( '924' === $i['movement_type'] && '' !== $serial ) {
						Containers::undo_take_back( $ctx, (string) $d['ref_no'], $serial, (string) $d['doc_no'] );
					}
				}
				// Stock client E annulé : la réservation de la commande suit.
				foreach ( $pick as $i ) {
					if ( in_array( $i['movement_type'], array( '411', '412' ), true ) && (float) $i['qty'] > 0 && '' !== (string) $i['special_ref'] ) {
						self::rebalance_e( $ctx, (string) $i['special_ref'], (int) $i['material_id'], '412' === $i['movement_type'] ? (float) $i['qty'] : -(float) $i['qty'] );
					}
					if ( in_array( $i['movement_type'], array( '411', '412' ), true ) && (float) $i['qty'] > 0 && '' === (string) $i['special_ref'] ) {
						// 411 : le poste prenant est libre ; la référence est sur le poste cédant.
						$src = array_values( array_filter( $pick, static fn( $x ) => (float) $x['qty'] < 0 ) );
						if ( $src && '' !== (string) $src[0]['special_ref'] ) {
							self::rebalance_e( $ctx, (string) $src[0]['special_ref'], (int) $i['material_id'], -(float) $i['qty'] );
						}
					}
				}
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Document %s annulé : document d\'annulation %s.', $d['doc_no'], $doc ) );
			}
		);
	}

	/** Annulation d'un 412 E (+q : la réservation de commande revient) ou d'un 411 E (−q). */
	private static function rebalance_e( Ctx $ctx, string $ref, int $material_id, float $delta ): void {
		global $wpdb;
		[ $so_no, $line ] = array_pad( explode( '/', $ref ), 2, '0' );
		$so               = Sales::by_no( $ctx, $so_no );
		if ( ! $so ) {
			return;
		}
		$si = Db::t( 'sales_order_item' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$si} SET qty_reserved = LEAST(GREATEST(0, qty - qty_delivered), GREATEST(0, qty_reserved + %f)) WHERE so_id = %d AND line_no = %d AND material_id = %d", $delta, (int) $so['id'], (int) $line, $material_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Stock d'une entrée déplacé par l'OT d'entreposage confirmé. */
	public static function find_moved( Ctx $ctx, array $i, float $need ): ?array {
		global $wpdb;
		$t  = Db::t( 'transfer_order' );
		$ti = Db::t( 'transfer_order_item' );
		$st = Db::t( 'stock' );
		$bins = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ti.dst_bin_id FROM {$ti} ti JOIN {$t} o ON o.id = ti.to_id WHERE o.session_id = %d AND o.status = 'CONFIRME' AND ti.material_id = %d AND ti.batch = %s AND ti.src_bin_id = %d AND ti.dst_bin_id > 0 ORDER BY ti.id DESC", $ctx->id, (int) $i['material_id'], (string) $i['batch'], (int) $i['bin_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $bins as $bin ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$st} WHERE session_id = %d AND material_id = %d AND bin_id = %d AND batch = %s AND status = %s AND special_ref = %s AND qty >= %f", $ctx->id, (int) $i['material_id'], (int) $bin, (string) $i['batch'], (string) $i['stock_status'], (string) $i['special_ref'], $need - 0.0005 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				return $row;
			}
		}
		return null;
	}

	/* ───────── LS26 : stock de la gestion des emplacements ───────── */

	public static function ls26( Ctx $ctx, string $material, string $location ): array {
		global $wpdb;
		$st    = Db::t( 'stock' );
		$where = array( 's.session_id = %d', 's.qty > 0' );
		$args  = array( $ctx->id );
		$m     = null;
		if ( '' !== $material ) {
			$m = Catalog::material_by_no( $material );
			if ( ! $m ) {
				throw new DomainError( sprintf( 'Article %s introuvable.', $material ) );
			}
			$where[] = 's.material_id = %d';
			$args[]  = (int) $m['id'];
		}
		$loc = null;
		if ( '' !== $location ) {
			$loc = Catalog::location_by_code( $location );
			if ( ! $loc ) {
				throw new DomainError( sprintf( 'Type de magasin %s inconnu.', $location ) );
			}
			$where[] = 's.storage_location_id = %d';
			$args[]  = (int) $loc['id'];
		}
		if ( ! $m && ! $loc ) {
			throw new DomainError( 'Indiquez un article ou un type de magasin (ex. 999 pour les écarts).' );
		}
		$sqlw  = implode( ' AND ', $where );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$st} s WHERE {$sqlw} ORDER BY s.storage_location_id, s.bin_id, s.batch LIMIT 300", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$quants = array();
		$totals = array();
		foreach ( $rows as $r ) {
			$l   = Catalog::location( (int) $r['storage_location_id'] );
			$b   = (int) $r['bin_id'] ? Catalog::bin( (int) $r['bin_id'] ) : null;
			$mm  = Catalog::material( (int) $r['material_id'] );
			$key = $l ? $l['code'] : '?';
			$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + (float) $r['qty'];
			$quants[] = array( 'stock_id' => (int) $r['id'], 'material' => Api::material_summary( $mm ), 'location' => $key, 'location_name' => $l ? $l['name'] : '', 'bin' => $b ? $b['code'] : '', 'batch' => $r['batch'], 'bbd' => $r['bbd'], 'status' => $r['status'], 'special_ref' => $r['special_ref'], 'qty' => (float) $r['qty'], 'gr_date' => $r['gr_date'], 'transit' => $l ? (int) $l['is_transit'] : 0, 'putaway_open' => $l && '100' === $l['code'] ? self::has_open_putaway( $ctx, $r ) : 0 );
		}
		// Quantités en transfert (OT ouverts) avec leur numéro d'unité de stock.
		$t    = Db::t( 'transfer_order' );
		$ti   = Db::t( 'transfer_order_item' );
		$tw   = array( "o.session_id = %d AND o.status = 'OUVERT'" );
		$targ = array( $ctx->id );
		if ( $m ) {
			$tw[]   = 'ti.material_id = %d';
			$targ[] = (int) $m['id'];
		}
		if ( $loc ) {
			$tw[]   = '(ti.src_location_id = %d OR ti.dst_location_id = %d)';
			$targ[] = (int) $loc['id'];
			$targ[] = (int) $loc['id'];
		}
		$tws  = implode( ' AND ', $tw );
		$open = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ti.*, o.to_no, o.type FROM {$ti} ti JOIN {$t} o ON o.id = ti.to_id WHERE {$tws} ORDER BY o.id, ti.line_no LIMIT 100", $targ ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$moves = array();
		foreach ( $open as $o ) {
			$sb = (int) $o['src_bin_id'] ? Catalog::bin( (int) $o['src_bin_id'] ) : null;
			$db = (int) $o['dst_bin_id'] ? Catalog::bin( (int) $o['dst_bin_id'] ) : ( (int) $o['proposed_bin_id'] ? Catalog::bin( (int) $o['proposed_bin_id'] ) : null );
			$sl = Catalog::location( (int) $o['src_location_id'] );
			$dl = Catalog::location( (int) $o['dst_location_id'] );
			$mm = Catalog::material( (int) $o['material_id'] );
			$moves[] = array( 'su' => self::su_no( (int) $o['id'] ), 'to_no' => $o['to_no'], 'type' => $o['type'], 'type_label' => Warehouse::TYPES[ $o['type'] ] ?? $o['type'], 'line_no' => (int) $o['line_no'], 'material' => Api::material_summary( $mm ), 'batch' => $o['batch'], 'qty' => (float) $o['qty'], 'src' => $sb ? $sb['code'] : ( $sl ? $sl['code'] : '' ), 'dst' => $db ? $db['code'] : ( $dl ? $dl['code'] : '' ), 'bulk' => (int) ( $mm['bulk'] ?? 0 ), 'su_confirm' => in_array( $o['type'], array( 'PUTAWAY', 'MOVE', 'REPL', 'STAGE' ), true ) && empty( $mm['bulk'] ) && ( (int) $o['proposed_bin_id'] || (int) $o['dst_bin_id'] ) ? 1 : 0 );
		}
		$tot = array();
		foreach ( $totals as $k => $q ) {
			$l     = Catalog::location_by_code( (string) $k );
			$tot[] = array( 'location' => (string) $k, 'name' => $l ? $l['name'] : '', 'qty' => $q );
		}
		return array( 'material' => $m ? Api::material_summary( $m ) + array( 'available' => StockService::available( $ctx->id, (int) $m['id'] ) ) : null, 'location' => $loc ? Api::location_summary( $loc ) : null, 'totals' => $tot, 'quants' => $quants, 'moves' => $moves, 'diff_codes' => self::DIFF_CODES );
	}

	private static function has_open_putaway( Ctx $ctx, array $row ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transfer_order_item' ) . ' ti JOIN ' . Db::t( 'transfer_order' ) . " o ON o.id = ti.to_id WHERE o.session_id = %d AND o.status = 'OUVERT' AND ti.material_id = %d AND ti.src_bin_id = %d AND ti.batch = %s", $ctx->id, (int) $row['material_id'], (int) $row['bin_id'], (string) $row['batch'] ) ) ? 1 : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Nouvel OT d'entreposage pour un stock resté au quai de réception (après un LT15, par exemple). */
	public static function putaway_from_dock( Ctx $ctx, int $stock_id ): array {
		[ $row, $m, $loc ] = self::row( $ctx, $stock_id, (float) ( StockService::row( $ctx->id, $stock_id )['qty'] ?? 0 ) );
		if ( '100' !== $loc['code'] ) {
			throw new DomainError( 'Seul le stock du quai de réception (100) attend un ordre d\'entreposage.' );
		}
		if ( self::has_open_putaway( $ctx, $row ) ) {
			throw new DomainError( 'Un ordre d\'entreposage est déjà ouvert pour ce stock : quittancez-le.' );
		}
		$res = Warehouse::create_putaway( $ctx, 'LS26', 0, array( array( 'm' => $m, 'qty' => (float) $row['qty'], 'batch' => (string) $row['batch'], 'bbd' => $row['bbd'], 'status' => (string) $row['status'], 'src_location_id' => (int) $row['storage_location_id'], 'src_bin_id' => (int) $row['bin_id'] ) ) );
		return array( 'to_no' => $res['to_no'], 'no' => $res['to_no'], 'message' => sprintf( 'Ordre d\'entreposage %s créé.', $res['to_no'] ) . ( $res['messages'] ? ' ' . implode( ' ', $res['messages'] ) : '' ) );
	}

	/** LT13 : confirmation d'un OT par son numéro d'unité de stock (destination proposée, sans saisie). */
	public static function lt13( Ctx $ctx, string $su ): array {
		global $wpdb;
		$id = (int) $su - 1000000000;
		if ( $id <= 0 ) {
			throw new DomainError( 'Numéro d\'unité de stock invalide (10 chiffres, voir LS26).' );
		}
		$it = $wpdb->get_row( $wpdb->prepare( 'SELECT ti.*, o.to_no, o.type, o.status FROM ' . Db::t( 'transfer_order_item' ) . ' ti JOIN ' . Db::t( 'transfer_order' ) . ' o ON o.id = ti.to_id WHERE ti.id = %d AND o.session_id = %d', $id, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $it ) {
			throw new DomainError( sprintf( 'Unité de stock %s introuvable.', $su ) );
		}
		if ( 'OUVERT' !== $it['status'] ) {
			throw new DomainError( sprintf( 'L\'ordre de transfert %s de cette unité de stock est déjà %s.', $it['to_no'], strtolower( $it['status'] ) ) );
		}
		if ( ! in_array( $it['type'], array( 'PUTAWAY', 'MOVE', 'REPL', 'STAGE' ), true ) ) {
			throw new DomainError( 'Un prélèvement se confirme dans LT12 (scan de l\'emplacement et de l\'article).' );
		}
		$to    = Warehouse::by_no( $ctx, $it['to_no'] );
		$input = array();
		foreach ( Warehouse::items( (int) $to['id'] ) as $x ) {
			$m = Catalog::material( (int) $x['material_id'] );
			if ( ! empty( $m['bulk'] ) ) {
				throw new DomainError( sprintf( 'Article en vrac (%s) : la confirmation se fait dans LT12 pour déduire la perte de citerne (code d\'écart 0).', $m['material_no'] ) );
			}
			$bin = (int) $x['proposed_bin_id'] ? Catalog::bin( (int) $x['proposed_bin_id'] ) : ( (int) $x['dst_bin_id'] ? Catalog::bin( (int) $x['dst_bin_id'] ) : null );
			if ( ! $bin ) {
				throw new DomainError( sprintf( 'Ligne %d de l\'OT %s : aucun emplacement proposé. Confirmez dans LT12 en choisissant l\'emplacement.', (int) $x['line_no'], $it['to_no'] ) );
			}
			$input[] = array( 'item_id' => (int) $x['id'], 'bin_code' => $bin['code'], 'qty' => (float) $x['qty'], 'scan' => $m['material_no'] );
		}
		$res            = Warehouse::confirm( $ctx, $it['to_no'], $input );
		$res['no']      = $it['to_no'];
		$res['message'] = sprintf( 'Ordre de transfert %s quittancé (unité de stock %s).', $it['to_no'], $su ) . ( $res['messages'] ? ' ' . implode( ' ', $res['messages'] ) : '' );
		return $res;
	}

	/** LT15 : annulation d'un ordre de transfert non confirmé ; le stock reste (ou revient) à la source. */
	public static function lt15( Ctx $ctx, string $to_no ): array {
		$to = Warehouse::by_no( $ctx, $to_no );
		if ( ! $to ) {
			throw new DomainError( sprintf( 'Ordre de transfert %s introuvable.', $to_no ) );
		}
		if ( 'OUVERT' !== $to['status'] ) {
			throw new DomainError( sprintf( 'L\'ordre de transfert %s est %s : seul un OT ouvert (non confirmé) s\'annule avec LT15.', $to_no, strtolower( $to['status'] ) ) );
		}
		if ( 'SAMPLE' === $to['type'] ) {
			throw new DomainError( 'Le prélèvement d\'échantillon est piloté par le lot de contrôle (QA32).' );
		}
		Db::update( 'transfer_order', array( 'status' => 'ANNULE', 'confirmed_at' => Db::now(), 'confirmed_by' => get_current_user_id() ), array( 'id' => (int) $to['id'] ) );
		$msg = sprintf( 'Ordre de transfert %s annulé.', $to_no );
		if ( 'PICK' === $to['type'] ) {
			$msg .= ' La marchandise reste à son emplacement : corrigez la livraison (VL02N : lot, quantité) puis créez un nouvel OT de prélèvement.';
			self::refresh_delivery_after_cancel( $ctx, (int) $to['ref_id'] );
		} elseif ( 'PUTAWAY' === $to['type'] ) {
			$msg .= ' La marchandise reste au quai de réception : créez un nouvel ordre d\'entreposage depuis LS26.';
		}
		return array( 'no' => $to_no, 'message' => $msg );
	}

	/** Livraison dont le prélèvement est annulé : retour au statut « à préparer » si plus rien n'est en cours. */
	public static function refresh_delivery_after_cancel( Ctx $ctx, int $delivery_id ): void {
		global $wpdb;
		$t    = Db::t( 'transfer_order' );
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND ref_type = 'DLV' AND ref_id = %d AND status IN ('OUVERT','CONFIRME')", $ctx->id, $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $open ) {
			Db::update( 'outbound_delivery', array( 'status' => 'A_PREPARER' ), array( 'id' => $delivery_id ) );
		}
	}

	/* ───────── LI21 : solde de l'aire des écarts ───────── */

	public static function li21_list( Ctx $ctx ): array {
		$data = self::ls26( $ctx, '', self::DIFF_LOCATION );
		return $data['quants'];
	}

	/** Comptabilise les écarts sélectionnés de l'aire 999 (sortie de stock, mouvement 711). */
	public static function li21( Ctx $ctx, array $stock_ids, string $text ): array {
		[ $loc ] = self::diff_bin();
		$lines   = array();
		$value   = 0.0;
		foreach ( array_unique( array_map( 'intval', $stock_ids ) ) as $sid ) {
			$row = StockService::row( $ctx->id, $sid );
			if ( ! $row || (int) $row['storage_location_id'] !== (int) $loc['id'] ) {
				throw new DomainError( 'Choisissez des quantités de l\'aire des écarts (999).' );
			}
			$m      = Catalog::material( (int) $row['material_id'] );
			$value += (float) $row['qty'] * (float) $m['std_price'];
			$lines[] = self::line( '711', $row, -(float) $row['qty'], array( 'reason' => 'Écart soldé (LI21)' ) );
		}
		if ( ! $lines ) {
			throw new DomainError( 'Sélectionnez au moins une position à solder.' );
		}
		$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'LI21', 'text' => $text ?: 'Solde des écarts de l\'aire 999', 'tcode' => 'LI21' ), $lines );
		return array( 'doc_no' => $doc, 'message' => sprintf( '%d écart%s soldé%s, document %s (valeur %s CHF).', count( $lines ), count( $lines ) > 1 ? 's' : '', count( $lines ) > 1 ? 's' : '', $doc, number_format( $value, 2, '.', '\'' ) ) );
	}

	/* ───────── Messages de sortie (impressions) ───────── */

	public const OUTPUTS = array(
		'ZWEZ' => 'Document d\'entrée de marchandises',
		'ZPAL' => 'Bulletin de livraison',
		'LT31' => 'Ordre de transfert et étiquettes',
		'VL74' => 'Étiquettes des unités d\'expédition',
		'ZCOA' => 'Certificat d\'analyse',
		'ZPOR' => 'Ordre de procédé et étiquettes',
		'ZTRA' => 'Papiers de transport (feuille de route, bordereau de remise)',
	);

	public static function output( Ctx $ctx, string $obj_type, string $obj_no, string $type, string $detail = '', string $printer = '', int $copies = 1 ): array {
		if ( ! isset( self::OUTPUTS[ $type ] ) ) {
			throw new DomainError( 'Type de message inconnu.' );
		}
		Db::insert( 'output_msg', array( 'session_id' => $ctx->id, 'obj_type' => mb_substr( $obj_type, 0, 6 ), 'obj_no' => mb_substr( $obj_no, 0, 20 ), 'output_type' => $type, 'detail' => mb_substr( $detail, 0, 60 ), 'printer' => mb_substr( $printer, 0, 20 ), 'copies' => max( 1, min( 20, $copies ) ), 'created_by' => get_current_user_id(), 'created_at' => Db::now() ) );
		return array( 'no' => $obj_no, 'message' => sprintf( 'Message %s (%s) envoyé à l\'impression%s.', $type, self::OUTPUTS[ $type ], '' !== $printer ? ' sur ' . $printer : '' ) );
	}

	public static function outputs_of( Ctx $ctx, string $obj_type, string $obj_no ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT output_type, detail, printer, copies, created_at FROM ' . Db::t( 'output_msg' ) . ' WHERE session_id = %d AND obj_type = %s AND obj_no = %s ORDER BY id', $ctx->id, $obj_type, $obj_no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$r ) {
			$r['label'] = self::OUTPUTS[ $r['output_type'] ] ?? $r['output_type'];
		}
		return $rows;
	}

	/* ───────── Journal des modifications (VA02, ME22N, COR2) ───────── */

	public static function log_change( Ctx $ctx, string $obj_type, string $obj_no, int $line, string $field, string $old, string $new, string $tcode ): void {
		if ( $old === $new ) {
			return;
		}
		Db::insert( 'change_log', array( 'session_id' => $ctx->id, 'obj_type' => $obj_type, 'obj_no' => $obj_no, 'line_no' => $line, 'field' => mb_substr( $field, 0, 40 ), 'old_value' => mb_substr( $old, 0, 190 ), 'new_value' => mb_substr( $new, 0, 190 ), 'tcode' => $tcode, 'user_id' => get_current_user_id(), 'created_at' => Db::now() ) );
	}

	public static function changes( Ctx $ctx, string $obj_type, string $obj_no ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT line_no, field, old_value, new_value, tcode, user_id, created_at FROM ' . Db::t( 'change_log' ) . ' WHERE session_id = %d AND obj_type = %s AND obj_no = %s ORDER BY id DESC LIMIT 200', $ctx->id, $obj_type, $obj_no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$r ) {
			$u        = get_userdata( (int) $r['user_id'] );
			$r['by']  = $u ? $u->display_name : '';
			unset( $r['user_id'] );
		}
		return $rows;
	}
}
