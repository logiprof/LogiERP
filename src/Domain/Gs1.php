<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normes GS1 utilisées en entrepôt : clé de contrôle modulo 10, GTIN (EAN) et SSCC
 * (Serial Shipping Container Code, 18 chiffres, une par unité logistique), étiquette
 * logistique GS1 (identifiants d'application 00, 02, 10, 15, 17, 37) et lecture des scans.
 */
final class Gs1 {

	/** Séparateur de groupe (FNC1) renvoyé par les lecteurs pour les champs de longueur variable. */
	public const GS = "\x1d";

	/** Préfixe pays GS1 utilisé pour composer le préfixe entreprise (fictif) du fournisseur. */
	private const COUNTRY_PREFIX = array( 'CH' => '761', 'LI' => '761', 'DE' => '401', 'FR' => '301', 'IT' => '801', 'AT' => '901', 'NL' => '871', 'BE' => '541', 'PL' => '590', 'CZ' => '859' );

	/** Unités logistiques au maximum par ligne de commande (le reste est regroupé sur la dernière). */
	public const MAX_UNITS = 60;

	/** Identifiants d'application de longueur fixe (longueur des données, sans l'IA). */
	private const FIXED = array( '00' => 18, '01' => 14, '02' => 14, '11' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6 );

	/** Identifiants de longueur variable acceptés (longueur maximale). */
	private const VARIABLE = array( '10' => 20, '21' => 20, '37' => 8, '400' => 30 );

	public static function check_digit( string $digits ): int {
		$sum = 0;
		$len = strlen( $digits );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += (int) $digits[ $len - 1 - $i ] * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10;
	}

	public static function valid( string $code ): bool {
		return (bool) preg_match( '/^\d{8,18}$/', $code ) && self::check_digit( substr( $code, 0, -1 ) ) === (int) substr( $code, -1 );
	}

	/** GTIN-14 à partir d'un EAN-8 / EAN-13 (complété par des zéros à gauche). */
	public static function gtin14( string $ean ): string {
		return preg_match( '/^\d{8,14}$/', $ean ) ? str_pad( $ean, 14, '0', STR_PAD_LEFT ) : '';
	}

	/** EAN-13 (ou EAN-8) correspondant à un GTIN-14 qui commence par des zéros. */
	public static function ean_from_gtin( string $gtin ): string {
		if ( ! preg_match( '/^\d{14}$/', $gtin ) ) {
			return $gtin;
		}
		if ( str_starts_with( $gtin, '000000' ) ) {
			return substr( $gtin, 6 );
		}
		return '0' === $gtin[0] ? substr( $gtin, 1 ) : $gtin;
	}

	public static function is_ean13( string $code ): bool {
		return (bool) preg_match( '/^\d{13}$/', $code ) && self::valid( $code );
	}

	/**
	 * EAN-13 interne (préfixe GS1 « 2 » : diffusion restreinte à l'entreprise) attribué à un article
	 * qui n'a pas de code fabricant : 2 + 9 + numéro d'article sur 10 chiffres + clé, unique dans le jeu de données.
	 */
	public static function internal_ean13( string $material_no, int $dataset_id, int $exclude_id = 0 ): string {
		global $wpdb;
		$t    = Db::t( 'material' );
		$num  = (string) preg_replace( '/\D/', '', $material_no );
		$base = '' !== $num ? (int) substr( $num, -10 ) : (int) ( crc32( $material_no ) % 10000000000 );
		for ( $k = 0; $k < 50; $k++ ) {
			$body = '29' . str_pad( (string) ( ( $base + $k * 7919 ) % 10000000000 ), 10, '0', STR_PAD_LEFT );
			$ean  = $body . self::check_digit( $body );
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE dataset_id = %d AND ean = %s AND id <> %d", $dataset_id, $ean, $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $used ) {
				return $ean;
			}
		}
		throw new DomainError( 'Impossible d\'attribuer un EAN-13 interne unique.' );
	}

	/**
	 * Référence série stable d'une ligne de commande : la même dans toutes les sessions d'un scénario
	 * (les étiquettes imprimées par l'enseignant sont valables pour toute la classe).
	 */
	public static function serial_base( int $scenario_id, string $po_no, int $line_no ): int {
		return (int) ( sprintf( '%u', crc32( $scenario_id . '|' . $po_no . '|' . $line_no ) ) % 1000000 );
	}

	/**
	 * SSCC d'une unité logistique : extension 3 + préfixe entreprise fictif du fournisseur
	 * (préfixe pays GS1 + 5 chiffres) + référence série (8 chiffres) + clé de contrôle.
	 */
	public static function sscc( string $country, int $supplier_id, int $serial_base, int $unit ): string {
		$prefix = self::COUNTRY_PREFIX[ $country ] ?? '761';
		$body   = '3' . $prefix . str_pad( (string) ( $supplier_id % 100000 ), 5, '0', STR_PAD_LEFT ) . str_pad( (string) ( ( $serial_base * 100 + $unit ) % 100000000 ), 8, '0', STR_PAD_LEFT );
		return $body . self::check_digit( $body );
	}

	/**
	 * Unités logistiques (palettes ou colis) d'une ligne livrée, avec leur contenu.
	 *
	 * @return array<int,array{sscc:string,n:int,of:int,qty:float,type:string,cartons:int}>
	 */
	public static function units( array $m, float $qty, string $country, int $supplier_id, int $serial_base ): array {
		if ( $qty <= 0 ) {
			return array();
		}
		$upc     = max( 1, (int) $m['units_per_carton'] );
		$cpp     = max( 1, (int) $m['cartons_per_pallet'] );
		$cartons = (int) ceil( $qty / $upc );
		$pallet  = $cpp > 1 && $cartons >= $cpp / 2;
		$per     = $pallet ? $cpp * $upc : $qty;
		$count   = $pallet ? (int) ceil( $qty / $per ) : 1;
		$count   = max( 1, min( self::MAX_UNITS, $count ) );
		$out     = array();
		$left    = $qty;
		for ( $n = 1; $n <= $count; $n++ ) {
			$q     = $n === $count ? $left : min( $left, $per );
			$left -= $q;
			$out[] = array(
				'sscc'    => self::sscc( $country, $supplier_id, $serial_base, $n ),
				'n'       => $n,
				'of'      => $count,
				'qty'     => round( $q, 3 ),
				'type'    => $pallet ? 'Palette EUR' : 'Colis',
				'cartons' => (int) ceil( $q / $upc ),
			);
		}
		return $out;
	}

	/**
	 * Décompose une chaîne GS1 (avec ou sans identifiant de symbologie ]C1, ]d2, ]Q3, parenthèses ou FNC1).
	 *
	 * @return array<string,string> IA => valeur ; tableau vide si la chaîne n'est pas une chaîne GS1.
	 */
	public static function parse( string $raw ): array {
		$s = trim( $raw );
		$s = (string) preg_replace( '/^\][A-Za-z]\d/', '', $s );
		if ( str_contains( $s, '(' ) ) {
			if ( ! preg_match_all( '/\((\d{2,4})\)([^()]*)/', $s, $mm, PREG_SET_ORDER ) ) {
				return array();
			}
			$out = array();
			foreach ( $mm as $x ) {
				$out[ $x[1] ] = trim( str_replace( self::GS, '', $x[2] ) );
			}
			return self::check_parsed( $out );
		}
		$s   = ltrim( $s, self::GS );
		$out = array();
		$len = strlen( $s );
		$i   = 0;
		while ( $i < $len ) {
			$ai = null;
			foreach ( array( 2, 3 ) as $l ) {
				$cand = substr( $s, $i, $l );
				if ( isset( self::FIXED[ $cand ] ) || isset( self::VARIABLE[ $cand ] ) ) {
					$ai = $cand;
					break;
				}
			}
			if ( null === $ai ) {
				return array();
			}
			$i += strlen( $ai );
			if ( isset( self::FIXED[ $ai ] ) ) {
				$out[ $ai ] = substr( $s, $i, self::FIXED[ $ai ] );
				$i         += self::FIXED[ $ai ];
				if ( $i < $len && self::GS === $s[ $i ] ) {
					++$i;
				}
				continue;
			}
			$end        = strpos( $s, self::GS, $i );
			$end        = false === $end ? $len : $end;
			$out[ $ai ] = substr( $s, $i, min( $end - $i, self::VARIABLE[ $ai ] ) );
			$i          = $end + 1;
		}
		return self::check_parsed( $out );
	}

	private static function check_parsed( array $out ): array {
		foreach ( $out as $ai => $v ) {
			if ( isset( self::FIXED[ $ai ] ) && ( strlen( $v ) !== self::FIXED[ $ai ] || ! ctype_digit( $v ) ) ) {
				return array();
			}
			if ( isset( self::VARIABLE[ $ai ] ) && ( '' === $v || strlen( $v ) > self::VARIABLE[ $ai ] ) ) {
				return array();
			}
		}
		return $out;
	}

	/** Date GS1 AAMMJJ → AAAA-MM-JJ (JJ = 00 : dernier jour du mois). */
	public static function date( string $yymmdd ): string {
		if ( ! preg_match( '/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m ) ) {
			return '';
		}
		$y = 2000 + (int) $m[1];
		$d = '00' === $m[3] ? (int) gmdate( 't', gmmktime( 0, 0, 0, (int) $m[2], 1, $y ) ) : (int) $m[3];
		return checkdate( (int) $m[2], $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, (int) $m[2], $d ) : '';
	}

	/** Article du jeu de données actif correspondant à un EAN / GTIN. */
	public static function material_by_gtin( string $code ): ?array {
		global $wpdb;
		$ean = self::ean_from_gtin( $code );
		if ( ! preg_match( '/^\d{8,13}$/', $ean ) ) {
			return null;
		}
		$t  = Db::t( 'material' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND ean = %s AND deleted_at IS NULL LIMIT 1", Catalog::dataset(), $ean ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? Catalog::material( $id ) : null;
	}

	/** Unité logistique (SSCC) attendue dans les commandes ouvertes de la session. */
	public static function find_unit( Ctx $ctx, string $sscc ): ?array {
		global $wpdb;
		$po   = Db::t( 'purchase_order' );
		$nos  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT po_no FROM {$po} WHERE session_id = %d AND status IN ('ENVOYEE','PARTIELLEMENT_RECUE') ORDER BY id LIMIT 60", $ctx->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $nos as $no ) {
			$d = Purchasing::detail( $ctx, (string) $no );
			foreach ( $d['items'] as $i ) {
				foreach ( $i['delivery_note']['units'] as $u ) {
					if ( $u['sscc'] === $sscc ) {
						return array( 'po' => $d, 'item' => $i, 'unit' => $u );
					}
				}
			}
		}
		return null;
	}

	/** Unité d'expédition de la session (SSCC ou n° d'unité). */
	public static function find_shipping_unit( Ctx $ctx, string $code ): ?array {
		global $wpdb;
		$h = \LogiERP\Core\Db::t( 'handling_unit' );
		$d = \LogiERP\Core\Db::t( 'outbound_delivery' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT u.hu_no, u.sscc, d.dlv_no FROM {$h} u JOIN {$d} d ON d.id = u.delivery_id WHERE u.session_id = %d AND (u.sscc = %s OR u.hu_no = %s) LIMIT 1", $ctx->id, $code, $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $r ?: null;
	}

	/**
	 * Identifie un code scanné : SSCC, GTIN (avec lot et date éventuels), emplacement,
	 * commande d'achat, ordre de transfert ou numéro d'article.
	 */
	public static function resolve( Ctx $ctx, string $raw ): array {
		$raw = mb_substr( trim( str_replace( array( "\r", "\n" ), '', $raw ) ), 0, 120 );
		if ( '' === $raw ) {
			throw new DomainError( 'Aucun code lu.' );
		}
		// Un SSCC saisi à la main (18 chiffres lus sous le code-barres) prime : sinon « 37… » serait lu comme l'IA 37 (nombre).
		if ( preg_match( '/^\d{18}$/', $raw ) && self::valid( $raw ) ) {
			$ai = array( '00' => $raw );
		} else {
			$ai = self::parse( $raw );
			if ( ! $ai && preg_match( '/^\d{18}$/', $raw ) ) {
				$ai = array( '00' => $raw );
			}
		}
		if ( isset( $ai['00'] ) ) {
			if ( ! self::valid( $ai['00'] ) ) {
				throw new DomainError( sprintf( 'SSCC %s : clé de contrôle incorrecte. Rescannez l\'étiquette.', $ai['00'] ) );
			}
			$u = self::find_unit( $ctx, $ai['00'] );
			if ( ! $u ) {
				$hu = self::find_shipping_unit( $ctx, $ai['00'] );
				if ( $hu ) {
					return array( 'kind' => 'hu', 'sscc' => $ai['00'], 'hu_no' => $hu['hu_no'], 'dlv_no' => $hu['dlv_no'] );
				}
				throw new DomainError( sprintf( 'SSCC %s : aucune unité logistique attendue avec ce numéro dans vos commandes ouvertes.', $ai['00'] ) );
			}
			return array(
				'kind'    => 'sscc',
				'sscc'    => $ai['00'],
				'po_no'   => $u['po']['po_no'],
				'item_id' => $u['item']['id'],
				'line_no' => $u['item']['line_no'],
				'unit'    => $u['unit'],
			);
		}
		// Les numéros d'article LogiERP comptent 8 chiffres, comme un EAN-8 : le numéro d'article prime.
		if ( preg_match( '/^\d{8}$/', $raw ) ) {
			$m = Catalog::material_by_no( $raw );
			if ( $m ) {
				return array( 'kind' => 'material', 'material' => Api::material_summary( $m ) );
			}
		}
		$gtin = $ai['01'] ?? $ai['02'] ?? '';
		if ( '' === $gtin && preg_match( '/^(\d{8}|\d{12,14})$/', $raw ) ) {
			$gtin = $raw;
		}
		if ( '' !== $gtin ) {
			if ( ! self::valid( $gtin ) ) {
				throw new DomainError( sprintf( 'Code %s : clé de contrôle EAN incorrecte.', $gtin ) );
			}
			$m = self::material_by_gtin( $gtin );
			if ( ! $m ) {
				throw new DomainError( sprintf( 'EAN %s : aucun article ne porte ce code.', self::ean_from_gtin( $gtin ) ) );
			}
			return array(
				'kind'     => 'gtin',
				'gtin'     => self::gtin14( self::ean_from_gtin( $gtin ) ),
				'ean'      => $m['ean'],
				'material' => Api::material_summary( $m ),
				'batch'    => (string) ( $ai['10'] ?? '' ),
				'bbd'      => self::date( (string) ( $ai['15'] ?? $ai['17'] ?? '' ) ),
				'count'    => isset( $ai['37'] ) ? (int) $ai['37'] : null,
			);
		}
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $raw ) );
		$bin  = Catalog::bin_by_code( $code );
		if ( $bin ) {
			return array( 'kind' => 'bin', 'bin' => Api::bin_summary( $bin ) );
		}
		if ( Purchasing::by_no( $ctx, $code ) ) {
			return array( 'kind' => 'po', 'no' => $code );
		}
		if ( Warehouse::by_no( $ctx, $code ) ) {
			return array( 'kind' => 'to', 'no' => $code );
		}
		if ( Sales::delivery_by_no( $ctx, $code ) ) {
			return array( 'kind' => 'dlv', 'no' => $code );
		}
		$hu = self::find_shipping_unit( $ctx, $code );
		if ( $hu ) {
			return array( 'kind' => 'hu', 'sscc' => $hu['sscc'], 'hu_no' => $hu['hu_no'], 'dlv_no' => $hu['dlv_no'] );
		}
		$m = Catalog::material_by_no( $code );
		if ( $m ) {
			return array( 'kind' => 'material', 'material' => Api::material_summary( $m ) );
		}
		throw new DomainError( sprintf( 'Code « %s » inconnu : ni SSCC, ni EAN, ni emplacement, ni document.', $code ) );
	}
}
