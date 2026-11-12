<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\ProcessSeeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emballages consignés suivis par numéro (articles X, conteneurs GRV loués) : ZVLGUT (retours, contrôle),
 * MB11 924 (reprise en stock propre), MB58 par n° de conteneur, portail emballages et portail transporteur
 * (simulations fictives des applications externes : aucune adresse ni identifiant réel).
 */
final class Containers {

	public const STATES = array(
		'PLEIN'       => 'Plein, chez le client',
		'VIDE'        => 'Vide, prêt à l\'emploi',
		'A_CONTROLER' => 'Retourné, à contrôler et laver',
		'REBUT'       => 'Rebuté (hors d\'usage)',
		'PROPRE'      => 'Repris en stock propre (924)',
	);

	/** Sessions ouvertes avant la version 2.0 : les conteneurs du fond de décor sont copiés au premier accès. */
	public static function ensure( Ctx $ctx ): void {
		global $wpdb;
		$ct = Db::t( 'container' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE session_id = %d", $ctx->id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$ct} (session_id, material_id, serial, holder, customer_id, state, ref_no, updated_at) SELECT %d, material_id, serial, holder, customer_id, state, ref_no, updated_at FROM {$ct} WHERE session_id = 0", $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Historique de départ : livraison des conteneurs pleins actuellement chez les clients.
		$out = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, customer_id FROM {$ct} WHERE session_id = %d AND holder = 'CLIENT' ORDER BY id", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $out as $k => $r ) {
			self::move( $ctx, (int) $r['id'], 'LIVRAISON', (int) $r['customer_id'], 'Livraison initiale', 'ERP', gmdate( 'Y-m-d', strtotime( $ctx->date() . ' -' . ( 6 + 3 * $k ) . ' days' ) ) );
		}
	}

	private static function container( Ctx $ctx, int $material_id, string $serial ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'container' ) . ' WHERE session_id = %d AND material_id = %d AND serial = %s', $ctx->id, $material_id, $serial ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ?: null;
	}

	private static function move( Ctx $ctx, int $container_id, string $kind, int $customer_id, string $ref, string $source = 'ERP', string $date = '' ): void {
		Db::insert( 'container_move', array( 'session_id' => $ctx->id, 'container_id' => $container_id, 'kind' => $kind, 'customer_id' => $customer_id, 'move_date' => $date ?: $ctx->date(), 'ref_no' => mb_substr( $ref, 0, 20 ), 'source' => $source, 'created_by' => get_current_user_id(), 'created_at' => Db::now() ) );
	}

	private static function serial( string $v ): string {
		return mb_substr( (string) preg_replace( '/[^0-9A-Za-z]/', '', strtoupper( $v ) ), 0, 20 );
	}

	/** Articles suivis par numéro de conteneur. */
	public static function serial_materials(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'material' ) . " WHERE dataset_id = %d AND type = 'VERP' AND serial_managed = 1 AND deleted_at IS NULL ORDER BY material_no", Catalog::dataset() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( static fn( $m ) => Api::material_summary( $m ) + array( 'own' => (int) $m['own_material_id'] ? ( Catalog::material( (int) $m['own_material_id'] )['material_no'] ?? '' ) : '' ), $rows );
	}

	/** Liste des conteneurs (filtres article, numéro, client, détenteur). */
	public static function list( Ctx $ctx, string $material, string $serial, int $customer_id ): array {
		global $wpdb;
		self::ensure( $ctx );
		$ct    = Db::t( 'container' );
		$where = array( 'c.session_id = %d' );
		$args  = array( $ctx->id );
		if ( '' !== $material ) {
			$m = Catalog::material_by_no( $material );
			if ( ! $m ) {
				throw new DomainError( sprintf( 'Article %s introuvable.', $material ) );
			}
			$where[] = 'c.material_id = %d';
			$args[]  = (int) $m['id'];
		}
		if ( '' !== $serial ) {
			$where[] = 'c.serial = %s';
			$args[]  = self::serial( $serial );
		}
		if ( $customer_id ) {
			$where[] = 'c.customer_id = %d';
			$args[]  = $customer_id;
		}
		$sqlw = implode( ' AND ', $where );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$ct} c WHERE {$sqlw} ORDER BY c.material_id, c.serial LIMIT 300", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$cust  = (int) $r['customer_id'] ? Sales::customer( (int) $r['customer_id'] ) : null;
			$out[] = array( 'id' => (int) $r['id'], 'material' => Api::material_summary( Catalog::material( (int) $r['material_id'] ) ), 'serial' => $r['serial'], 'holder' => $r['holder'], 'state' => $r['state'], 'state_label' => self::STATES[ $r['state'] ] ?? $r['state'], 'customer' => $cust ? array( 'id' => (int) $cust['id'], 'no' => $cust['customer_no'], 'name' => $cust['name'], 'city' => $cust['city'] ) : null, 'ref_no' => $r['ref_no'], 'updated_at' => $r['updated_at'] );
		}
		return $out;
	}

	public static function history( Ctx $ctx, int $container_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'container_move' ) . ' WHERE session_id = %d AND container_id = %d ORDER BY move_date, id', $ctx->id, $container_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			static function ( $r ) {
				$c = (int) $r['customer_id'] ? Sales::customer( (int) $r['customer_id'] ) : null;
				return array( 'id' => (int) $r['id'], 'kind' => $r['kind'], 'date' => $r['move_date'], 'customer' => $c ? $c['customer_no'] . ' ' . $c['name'] : '', 'ref_no' => $r['ref_no'], 'source' => $r['source'], 'cancelled' => (int) $r['cancelled'] );
			},
			$rows
		);
	}

	/**
	 * ZVLGUT « retours » : conteneurs loués rendus vides par un client (un numéro par conteneur) ;
	 * ils entrent au magasin des vides (950) à contrôler, le compte d'emballages du client diminue.
	 */
	public static function receive( Ctx $ctx, int $customer_id, int $material_id, array $serials, string $ref ): array {
		global $wpdb;
		self::ensure( $ctx );
		$m    = Catalog::material( $material_id );
		$cust = Sales::customer( $customer_id );
		if ( ! $m || empty( $m['serial_managed'] ) || 'VERP' !== $m['type'] ) {
			throw new DomainError( 'Choisissez un emballage loué suivi par numéro (article X).' );
		}
		if ( ! $cust ) {
			throw new DomainError( 'Client (réceptionnaire) introuvable.' );
		}
		$list = array_values( array_unique( array_filter( array_map( array( self::class, 'serial' ), $serials ) ) ) );
		if ( ! $list ) {
			throw new DomainError( 'Saisissez le numéro de chaque conteneur rendu.' );
		}
		$errors = array();
		$found  = array();
		foreach ( $list as $s ) {
			$c = self::container( $ctx, (int) $m['id'], $s );
			if ( ! $c ) {
				$errors[] = sprintf( 'Conteneur %s inconnu : vérifiez le numéro sur la plaque.', $s );
			} elseif ( 'CLIENT' !== $c['holder'] || (int) $c['customer_id'] !== $customer_id ) {
				$other    = (int) $c['customer_id'] ? Sales::customer( (int) $c['customer_id'] ) : null;
				$errors[] = sprintf( 'Conteneur %s : il n\'est pas chez %s (%s).', $s, $cust['name'], 'CLIENT' === $c['holder'] && $other ? 'il est enregistré chez ' . $other['name'] : 'il est déjà chez nous' );
			} else {
				$found[] = $c;
			}
		}
		if ( $errors ) {
			throw new DomainError( 'Retour refusé.', $errors );
		}
		$loc = Catalog::location_by_code( Packaging::EMPTIES_LOCATION );
		$bin = $ctx->simple() ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bin' ) . ' WHERE storage_location_id = %d ORDER BY code LIMIT 1', (int) $loc['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return Db::transaction(
			static function () use ( $ctx, $m, $cust, $found, $loc, $bin, $ref, $wpdb ) {
				$lines = array();
				foreach ( $found as $c ) {
					$lines[] = array( 'movement_type' => '622', 'material_id' => (int) $m['id'], 'location_id' => (int) $loc['id'], 'bin_id' => $bin, 'batch' => $c['serial'], 'bbd' => null, 'status' => 'BLOQUE', 'qty' => 1, 'reason' => 'Conteneur rendu, à contrôler' );
				}
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'ZVLGUT', 'ref_no' => mb_substr( $ref, 0, 20 ), 'text' => 'Retour de conteneurs ' . $cust['customer_no'], 'tcode' => 'ZVLGUT' ), $lines );
				foreach ( $found as $c ) {
					Db::update( 'container', array( 'holder' => 'NOUS', 'customer_id' => 0, 'state' => 'A_CONTROLER', 'ref_no' => $doc, 'updated_at' => Db::now() ), array( 'id' => (int) $c['id'] ) );
					self::move( $ctx, (int) $c['id'], 'RETOUR', (int) $cust['id'], $doc );
				}
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'packaging_account' ) . ' (session_id, customer_id, material_id, qty) VALUES (%d, %d, %d, %f) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', $ctx->id, (int) $cust['id'], (int) $m['id'], -count( $found ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Db::insert( 'packaging_move', array( 'session_id' => $ctx->id, 'customer_id' => (int) $cust['id'], 'material_id' => (int) $m['id'], 'movement_type' => '622', 'qty' => count( $found ), 'qty_damaged' => 0, 'ref_no' => $doc, 'text' => 'Conteneurs ' . implode( ', ', array_column( $found, 'serial' ) ), 'created_at' => Db::now() ) );
				return array( 'doc_no' => $doc, 'message' => sprintf( '%d conteneur%s rendu%s par %s, document %s : en stock bloqué au magasin %s, à contrôler et laver.', count( $found ), count( $found ) > 1 ? 's' : '', count( $found ) > 1 ? 's' : '', $cust['name'], $doc, Packaging::EMPTIES_LOCATION ) );
			}
		);
	}

	/** ZVLGUT « contrôle » : conteneur bon (vide, prêt) ou rebuté (551). */
	public static function check( Ctx $ctx, int $material_id, string $serial, string $result, string $reason ): array {
		self::ensure( $ctx );
		$c = self::container( $ctx, $material_id, self::serial( $serial ) );
		if ( ! $c || 'NOUS' !== $c['holder'] || 'A_CONTROLER' !== $c['state'] ) {
			throw new DomainError( 'Seul un conteneur rendu et à contrôler peut être contrôlé.' );
		}
		$row = self::stock_row( $ctx, $material_id, (string) $c['serial'] );
		if ( ! $row ) {
			throw new DomainError( sprintf( 'Le conteneur %s n\'est pas en stock (magasin des vides).', $c['serial'] ) );
		}
		$ok = 'OK' === strtoupper( $result );
		if ( ! $ok && '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif du rebut (fissure, déformation, vanne hors d\'usage…).' );
		}
		return Db::transaction(
			static function () use ( $ctx, $c, $row, $ok, $reason ) {
				if ( $ok ) {
					$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'ZVLGUT', 'text' => 'Conteneur ' . $c['serial'] . ' contrôlé conforme', 'tcode' => 'ZVLGUT' ), array(
						array( 'movement_type' => '343', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'status' => 'BLOQUE', 'qty' => -1 ),
						array( 'movement_type' => '343', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'status' => 'LIBRE', 'qty' => 1 ),
					) );
					Db::update( 'container', array( 'state' => 'VIDE', 'updated_at' => Db::now() ), array( 'id' => (int) $c['id'] ) );
					self::move( $ctx, (int) $c['id'], 'CONTROLE', 0, $doc );
					return array( 'doc_no' => $doc, 'message' => sprintf( 'Conteneur %s conforme et lavé : stock libre, prêt à l\'emploi.', $c['serial'] ) );
				}
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'ZVLGUT', 'text' => 'Conteneur ' . $c['serial'] . ' rebuté', 'tcode' => 'ZVLGUT' ), array( array( 'movement_type' => '551', 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'status' => 'BLOQUE', 'qty' => -1, 'reason' => mb_substr( $reason, 0, 120 ) ) ) );
				Db::update( 'container', array( 'state' => 'REBUT', 'updated_at' => Db::now() ), array( 'id' => (int) $c['id'] ) );
				self::move( $ctx, (int) $c['id'], 'REBUT', 0, $doc );
				return array( 'doc_no' => $doc, 'message' => sprintf( 'Conteneur %s rebuté (551), document %s. Signalez-le au loueur sur le portail emballages.', $c['serial'], $doc ) );
			}
		);
	}

	private static function stock_row( Ctx $ctx, int $material_id, string $serial ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'stock' ) . ' WHERE session_id = %d AND material_id = %d AND batch = %s AND qty > 0 ORDER BY id LIMIT 1', $ctx->id, $material_id, $serial ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ?: null;
	}

	/** MB11 924 : un conteneur loué chez le client est repris dans notre stock sous l'article propre (sans X). */
	public static function take_back( Ctx $ctx, array $in, string $text ): array {
		global $wpdb;
		self::ensure( $ctx );
		$x    = Catalog::material( (int) ( $in['material_id'] ?? 0 ) );
		$cust = Sales::customer( (int) ( $in['customer_id'] ?? 0 ) );
		$s    = self::serial( (string) ( $in['serial'] ?? $in['batch'] ?? '' ) );
		if ( ! $x || empty( $x['serial_managed'] ) || ! (int) $x['own_material_id'] ) {
			throw new DomainError( 'Article cédant : un conteneur loué (article X) lié à un article propre.' );
		}
		if ( ! $cust ) {
			throw new DomainError( 'Indiquez le n° du client.' );
		}
		if ( '' === $s ) {
			throw new DomainError( 'Lot : indiquez le n° du conteneur.' );
		}
		if ( 1.0 !== round( (float) ( $in['qty'] ?? 0 ), 3 ) ) {
			throw new DomainError( 'Un conteneur à la fois : quantité 1.' );
		}
		$c = self::container( $ctx, (int) $x['id'], $s );
		if ( ! $c || 'CLIENT' !== $c['holder'] || (int) $c['customer_id'] !== (int) $cust['id'] ) {
			throw new DomainError( sprintf( 'Le conteneur %s n\'est pas enregistré chez %s (contrôlez avec MB58).', $s, $cust['name'] ) );
		}
		$own = Catalog::material( (int) $x['own_material_id'] );
		if ( $ctx->simple() ) {
			$loc = Catalog::location_by_code( (string) ( $in['location'] ?? '' ) );
			if ( ! $loc ) {
				throw new DomainError( 'Indiquez le magasin.' );
			}
			$bin_id = 0;
		} else {
			[ $bin, $loc ] = Receiving::reception_bin( $ctx );
			$bin_id        = (int) $bin['id'];
		}
		return Db::transaction(
			static function () use ( $ctx, $x, $own, $cust, $c, $loc, $bin_id, $text, $wpdb ) {
				$doc = StockService::post( $ctx->id, array( 'posting_date' => $ctx->date(), 'ref_type' => 'MB11', 'ref_no' => $cust['customer_no'], 'text' => $text ?: 'Reprise du conteneur ' . $c['serial'], 'tcode' => 'MB11' ), array( array( 'movement_type' => '924', 'material_id' => (int) $own['id'], 'location_id' => (int) $loc['id'], 'bin_id' => $bin_id, 'batch' => ! empty( $own['batch_managed'] ) ? $c['serial'] : '', 'status' => 'LIBRE', 'qty' => 1, 'vendor_batch' => $c['serial'], 'reason' => 'Conteneur ' . $c['serial'] . ' repris de ' . $cust['customer_no'] ) ) );
				Db::update( 'container', array( 'holder' => 'NOUS', 'customer_id' => 0, 'state' => 'PROPRE', 'ref_no' => $doc, 'updated_at' => Db::now() ), array( 'id' => (int) $c['id'] ) );
				self::move( $ctx, (int) $c['id'], 'REPRISE', (int) $cust['id'], $doc );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'packaging_account' ) . ' (session_id, customer_id, material_id, qty) VALUES (%d, %d, %d, -1) ON DUPLICATE KEY UPDATE qty = qty - 1', $ctx->id, (int) $cust['id'], (int) $x['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$to_no = '';
				if ( ! $ctx->simple() ) {
					$res   = Warehouse::create_putaway( $ctx, 'MB11', 0, array( array( 'm' => $own, 'qty' => 1, 'batch' => ! empty( $own['batch_managed'] ) ? $c['serial'] : '', 'bbd' => null, 'status' => 'LIBRE', 'src_location_id' => (int) $loc['id'], 'src_bin_id' => $bin_id ) ) );
					$to_no = $res['to_no'];
				}
				return array( 'doc_no' => $doc, 'to_no' => $to_no, 'message' => sprintf( 'Mouvement 924 enregistré, document %s : le conteneur %s entre en stock sous l\'article %s.', $doc, $c['serial'], $own['material_no'] ) . ( $to_no ? sprintf( ' Quittancez l\'entreposage %s dans LS26.', $to_no ) : '' ) );
			}
		);
	}

	/** Annulation (MBST) d'un 924 : le conteneur redevient loué au client et son compte est rétabli. */
	public static function undo_take_back( Ctx $ctx, string $customer_no, string $serial, string $doc_no ): void {
		global $wpdb;
		$cust = Api::customer_by_no( $customer_no );
		$x    = Catalog::material_by_no( ProcessSeeder::RENTED_IBC );
		$c    = $x ? self::container( $ctx, (int) $x['id'], $serial ) : null;
		if ( ! $cust || ! $c || 'PROPRE' !== $c['state'] ) {
			return;
		}
		Db::update( 'container', array( 'holder' => 'CLIENT', 'customer_id' => (int) $cust['id'], 'state' => 'PLEIN', 'ref_no' => $doc_no, 'updated_at' => Db::now() ), array( 'id' => (int) $c['id'] ) );
		self::move( $ctx, (int) $c['id'], 'ANNUL_924', (int) $cust['id'], $doc_no );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'packaging_account' ) . ' (session_id, customer_id, material_id, qty) VALUES (%d, %d, %d, 1) ON DUPLICATE KEY UPDATE qty = qty + 1', $ctx->id, (int) $cust['id'], (int) $x['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ───────── Portail emballages (simulation du portail du loueur) ───────── */

	public static function portal( Ctx $ctx, string $serial ): array {
		self::ensure( $ctx );
		$x    = Catalog::material_by_no( ProcessSeeder::RENTED_IBC );
		$s    = self::serial( $serial );
		if ( ! $x ) {
			throw new DomainError( 'Aucun conteneur loué dans ce jeu de données.' );
		}
		$list = self::list( $ctx, ProcessSeeder::RENTED_IBC, $s, 0 );
		$rows = array();
		foreach ( $list as $c ) {
			$hist  = self::history( $ctx, $c['id'] );
			$moves = array_values( array_filter( $hist, static fn( $m ) => 'PORTAIL' === $m['source'] ) );
			// Sans déclaration, le portail connaît l'état de départ (livraison initiale), pas les mouvements faits dans l'ERP.
			$init = array_values( array_filter( $hist, static fn( $m ) => 'ERP' === $m['source'] && 'Livraison initiale' === $m['ref_no'] ) );
			$last  = null;
			foreach ( $moves as $mv ) {
				if ( ! $mv['cancelled'] ) {
					$last = $mv;
				}
			}
			$portal_at = $last ? ( 'LIVRAISON' === $last['kind'] ? $last['customer'] : 'Chez nous' ) : ( $init ? $init[0]['customer'] : 'Chez nous' );
			$erp_at    = 'CLIENT' === $c['holder'] && $c['customer'] ? $c['customer']['no'] . ' ' . $c['customer']['name'] : 'Chez nous';
			$rows[]    = array( 'id' => $c['id'], 'serial' => $c['serial'], 'erp' => $erp_at, 'portal' => $portal_at, 'match' => $portal_at === $erp_at ? 1 : 0, 'moves' => $moves, 'state' => $c['state_label'] );
		}
		return array( 'rows' => $rows );
	}

	/** Déclaration sur le portail : livraison ou retour d'un conteneur (registre externe, distinct de l'ERP). */
	public static function portal_declare( Ctx $ctx, string $serial, string $kind, int $customer_id, string $date ): array {
		self::ensure( $ctx );
		$x = Catalog::material_by_no( ProcessSeeder::RENTED_IBC );
		$c = $x ? self::container( $ctx, (int) $x['id'], self::serial( $serial ) ) : null;
		if ( ! $c ) {
			throw new DomainError( 'Numéro de conteneur inconnu du portail.' );
		}
		$kind = strtoupper( $kind );
		if ( ! in_array( $kind, array( 'LIVRAISON', 'RETOUR' ), true ) ) {
			throw new DomainError( 'Choisissez livraison ou retour.' );
		}
		$cust = Sales::customer( $customer_id );
		if ( ! $cust ) {
			throw new DomainError( 'Choisissez l\'entreprise concernée.' );
		}
		if ( ! Api::valid_date( $date ) || $date > $ctx->date() ) {
			throw new DomainError( 'Date du mouvement invalide (au plus tard aujourd\'hui).' );
		}
		self::move( $ctx, (int) $c['id'], $kind, (int) $cust['id'], 'PORTAIL', 'PORTAIL', $date );
		return array( 'no' => $c['serial'], 'message' => sprintf( '%s du conteneur %s déclaré%s sur le portail (%s, %s).', 'LIVRAISON' === $kind ? 'Livraison' : 'Retour', $c['serial'], 'LIVRAISON' === $kind ? 'e' : '', $cust['name'], Api::fr_date( $date ) ) );
	}

	/** Correction sur le portail : annulation d'une déclaration. */
	public static function portal_cancel( Ctx $ctx, int $move_id ): array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'container_move' ) . " WHERE id = %d AND session_id = %d AND source = 'PORTAIL'", $move_id, $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $r || (int) $r['cancelled'] ) {
			throw new DomainError( 'Déclaration introuvable ou déjà annulée.' );
		}
		$wpdb->update( Db::t( 'container_move' ), array( 'cancelled' => 1 ), array( 'id' => $move_id ) );
		return array( 'no' => (string) $move_id, 'message' => 'Déclaration annulée sur le portail.' );
	}

	/* ───────── Portail transporteur ferroviaire (simulation) ───────── */

	public const RAIL_STATION = '40817';

	/** Wagons attendus : commandes de vrac acheminées par wagon-citerne. */
	public static function wagons( Ctx $ctx ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT o.po_no, o.status, s.name supplier, s.city, i.line_no, i.qty, i.qty_received, i.delivery_date, m.material_no, m.designation, m.un_number, m.adr_class FROM ' . Db::t( 'purchase_order' ) . ' o JOIN ' . Db::t( 'purchase_order_item' ) . ' i ON i.po_id = o.id JOIN ' . Db::t( 'supplier' ) . ' s ON s.id = o.supplier_id JOIN ' . Db::t( 'material' ) . " m ON m.id = i.material_id WHERE o.session_id = %d AND o.transport = 'WAGON' AND i.deleted = 0 ORDER BY i.delivery_date, o.po_no", $ctx->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$h     = abs( crc32( $r['po_no'] . '|' . $r['line_no'] ) );
			$body  = '3385' . str_pad( (string) ( 7800000 + $h % 99999 ), 7, '0', STR_PAD_LEFT );
			$wagon = substr( $body, 0, 2 ) . ' ' . substr( $body, 2, 2 ) . ' ' . substr( $body, 4, 4 ) . ' ' . substr( $body, 8, 3 ) . '-' . self::uic_check( $body );
			$out[] = array( 'wagon' => $wagon, 'po_no' => $r['po_no'], 'supplier' => $r['supplier'], 'from' => $r['city'], 'material' => $r['material_no'] . ' ' . $r['designation'], 'un' => $r['un_number'], 'adr' => $r['adr_class'], 'weight' => (float) $r['qty'], 'eta' => $r['delivery_date'], 'status' => (float) $r['qty_received'] > 0 ? 'Dépoté' : ( (string) $r['delivery_date'] <= $ctx->date() ? 'Arrivé en gare' : 'En route' ), 'station' => self::RAIL_STATION );
		}
		return array( 'station' => self::RAIL_STATION, 'rows' => $out );
	}

	/** Chiffre de contrôle UIC (Luhn) d'un numéro de wagon à 11 chiffres. */
	private static function uic_check( string $digits ): int {
		$sum = 0;
		foreach ( str_split( strrev( $digits ) ) as $i => $d ) {
			$v    = (int) $d * ( 0 === $i % 2 ? 2 : 1 );
			$sum += intdiv( $v, 10 ) + $v % 10;
		}
		return ( 10 - $sum % 10 ) % 10;
	}
}
