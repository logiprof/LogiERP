<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\Dictionaries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Données de base : définition des champs, contrôles de cohérence logistique et enregistrement
 * des articles, fournisseurs et clients. Utilisé par la saisie manuelle et par l'import Excel,
 * pour que les deux appliquent exactement les mêmes règles.
 */
final class MasterData {

	public const KINDS = array( 'material', 'supplier', 'customer' );

	public const MAT_TYPES = array( 'MRCH' => 'Marchandise', 'MP' => 'Matière première', 'SF' => 'Semi-fini', 'PF' => 'Produit fini', 'EMB' => 'Emballage' );
	public const UOMS      = array( 'PCE' => 'Pièce', 'CRT' => 'Carton', 'PAL' => 'Palette', 'KG' => 'Kilogramme', 'L' => 'Litre', 'M' => 'Mètre', 'SET' => 'Lot / set', 'ROL' => 'Rouleau' );
	public const ADR       = array( '2' => 'Gaz (aérosols)', '3' => 'Liquides inflammables', '5.1' => 'Matières comburantes', '6.1' => 'Matières toxiques', '8' => 'Matières corrosives', '9' => 'Matières diverses (batteries lithium)' );
	public const INCOTERMS = array( 'DAP', 'DDP', 'EXW', 'FCA', 'CPT' );
	/** Pays admis pour les fournisseurs (les clients sont tous en Suisse). */
	public const COUNTRIES = array( 'CH' => 'Suisse', 'LI' => 'Liechtenstein', 'DE' => 'Allemagne', 'FR' => 'France', 'IT' => 'Italie', 'AT' => 'Autriche', 'NL' => 'Pays-Bas', 'BE' => 'Belgique', 'LU' => 'Luxembourg', 'ES' => 'Espagne', 'PL' => 'Pologne', 'CZ' => 'Tchéquie' );
	/** Format du code postal par pays : [expression, exemple]. */
	public const ZIP_FORMATS = array(
		'CH' => array( '/^\d{4}$/', '1008' ),
		'LI' => array( '/^94(8[5-9]|9[0-8])$/', '9490' ),
		'DE' => array( '/^\d{5}$/', '79539' ),
		'FR' => array( '/^\d{5}$/', '74000' ),
		'IT' => array( '/^\d{5}$/', '22100' ),
		'ES' => array( '/^\d{5}$/', '08001' ),
		'AT' => array( '/^\d{4}$/', '6900' ),
		'BE' => array( '/^\d{4}$/', '2000' ),
		'LU' => array( '/^\d{4}$/', '1611' ),
		'NL' => array( '/^\d{4} ?[A-Z]{2}$/', '3011 AD' ),
		'PL' => array( '/^\d{2}-\d{3}$/', '50-001' ),
		'CZ' => array( '/^\d{3} ?\d{2}$/', '110 00' ),
	);
	public const PHONE_CODES = array( 'CH' => '41', 'LI' => '423', 'DE' => '49', 'FR' => '33', 'IT' => '39', 'AT' => '43', 'NL' => '31', 'BE' => '32', 'LU' => '352', 'ES' => '34', 'PL' => '48', 'CZ' => '420' );
	public const STATUSES  = array( 'ACTIF' => 'Actif', 'BLOQUE' => 'Bloqué', 'FIN' => 'Fin de série' );

	/** Champs « structurels » d'un article : figés dès que l'article a du stock ou est utilisé dans un scénario. */
	public const STRUCTURAL = array( 'profile_code', 'base_uom', 'net_weight', 'gross_weight', 'length_mm', 'width_mm', 'height_mm', 'volume_l', 'batch_managed', 'shelf_life_days', 'adr_class', 'un_number', 'stackable' );

	/* ─────────────────────────── Définition des colonnes ─────────────────────────── */

	/**
	 * Colonnes par type : clé => [libellé, obligatoire (création), type, aide, valeurs autorisées].
	 * type : text | int | dec | bool | list
	 *
	 * @return array<string,array{0:string,1:bool,2:string,3:string,4:array<int|string,string>}>
	 */
	public static function columns( string $kind ): array {
		if ( 'material' === $kind ) {
			$profiles = array();
			foreach ( Dictionaries::profiles() as $code => $p ) {
				$profiles[ $code ] = $p['name'];
			}
			return array(
				'material_no'        => array( 'N° article', false, 'text', 'Vide = numéro attribué automatiquement selon le profil (8 chiffres). Un numéro existant met l\'article à jour.', array() ),
				'designation'        => array( 'Désignation', true, 'text', 'Texte libre, 190 caractères au maximum.', array() ),
				'profile_code'       => array( 'Profil de stockage', true, 'list', 'Détermine les zones de stockage autorisées.', $profiles ),
				'type'               => array( 'Type d\'article', false, 'list', 'Par défaut : MRCH.', self::MAT_TYPES ),
				'group'              => array( 'Groupe de marchandises', false, 'text', 'Nom du groupe ; créé s\'il n\'existe pas encore.', array() ),
				'base_uom'           => array( 'Unité de base', false, 'list', 'Par défaut : PCE (CRT pour le profil Palettes).', self::UOMS ),
				'net_weight'         => array( 'Poids net (kg)', true, 'dec', 'Par unité de base. Séparateur décimal : point ou virgule.', array() ),
				'gross_weight'       => array( 'Poids brut (kg)', false, 'dec', 'Vide = poids net + 5 %. Doit être ≥ au poids net.', array() ),
				'length_mm'          => array( 'Longueur (mm)', true, 'int', 'Plus grande dimension de l\'unité de base.', array() ),
				'width_mm'           => array( 'Largeur (mm)', true, 'int', '', array() ),
				'height_mm'          => array( 'Hauteur (mm)', true, 'int', '', array() ),
				'volume_l'           => array( 'Volume (l)', false, 'dec', 'Vide = longueur × largeur × hauteur.', array() ),
				'std_price'          => array( 'Prix standard (CHF)', true, 'dec', 'Prix d\'achat de référence.', array() ),
				'sale_price'         => array( 'Prix de vente (CHF)', false, 'dec', 'Vide = prix standard × 1,4.', array() ),
				'abc'                => array( 'Classe ABC', false, 'list', 'Par défaut : C.', array( 'A' => 'A', 'B' => 'B', 'C' => 'C' ) ),
				'batch_managed'      => array( 'Gestion par lot', false, 'bool', 'OUI / NON. Obligatoire (OUI) pour les profils froid, surgelé, tempéré et chimie, ou si une durée de vie est saisie.', array() ),
				'shelf_life_days'    => array( 'Durée de vie (jours)', false, 'int', 'Durée de conservation (DLC / DLUO). Obligatoire pour le froid et le surgelé.', array() ),
				'min_stock'          => array( 'Stock minimum', false, 'dec', '', array() ),
				'reorder_point'      => array( 'Point de commande', false, 'dec', 'Doit être ≥ au stock minimum.', array() ),
				'max_stock'          => array( 'Stock maximum', false, 'dec', 'Doit être ≥ au point de commande (0 = pas de maximum).', array() ),
				'lot_size'           => array( 'Taille de lot', false, 'dec', 'Quantité de commande habituelle. Par défaut : 1.', array() ),
				'lead_time_days'     => array( 'Délai d\'approvisionnement (jours)', false, 'int', 'Vide = délai du fournisseur principal.', array() ),
				'supplier_no'        => array( 'N° fournisseur principal', false, 'text', 'N° d\'un fournisseur existant (ex. F20001). Crée la fiche d\'info-achat.', array() ),
				'ean'                => array( 'EAN-13', false, 'text', '13 chiffres avec clé de contrôle. Vide : un EAN-13 interne (préfixe 2) est attribué automatiquement.', array() ),
				'adr_class'          => array( 'Classe ADR', false, 'list', 'Uniquement pour les profils Chimie (2, 3, 5.1, 6.1, 8) et Batteries lithium (9).', self::ADR ),
				'un_number'          => array( 'N° ONU', false, 'text', '4 chiffres, obligatoire si une classe ADR est indiquée.', array() ),
				'packing_group'      => array( 'Groupe d\'emballage', false, 'list', 'I, II ou III (vide pour les classes 2 et 9).', array( 'I' => 'I', 'II' => 'II', 'III' => 'III' ) ),
				'hazard_text'        => array( 'Désignation officielle de transport', false, 'text', 'Ex. « Liquide inflammable, n.s.a. ».', array() ),
				'stackable'          => array( 'Gerbable', false, 'bool', 'OUI / NON. Par défaut : NON pour les profils volumineux, fragile et lourd.', array() ),
				'units_per_carton'   => array( 'Unités par carton', false, 'int', 'Par défaut : 1.', array() ),
				'cartons_per_pallet' => array( 'Cartons par palette', false, 'int', 'Par défaut : 1.', array() ),
				'status'             => array( 'Statut', false, 'list', 'Par défaut : ACTIF.', self::STATUSES ),
			);
		}
		if ( 'supplier' === $kind ) {
			$spec = array();
			foreach ( Dictionaries::supplier_specialties() as $s ) {
				$spec[ $s[0] ] = $s[0];
			}
			return array(
				'supplier_no'    => array( 'N° fournisseur', false, 'text', 'Vide = numéro attribué automatiquement (F + 5 chiffres). Un numéro existant met le fournisseur à jour.', array() ),
				'name'           => array( 'Raison sociale', true, 'text', 'Avec la forme juridique (SA, Sàrl, AG, GmbH…).', array() ),
				'specialty'      => array( 'Spécialité', true, 'list', 'Famille de produits livrés.', $spec ),
				'street'         => array( 'Rue et n°', true, 'text', '', array() ),
				'zip'            => array( 'Code postal', true, 'text', 'Selon le pays : CH 4 chiffres (1000 à 9699), DE/FR/IT/ES 5 chiffres, AT/BE/LU 4 chiffres, NL « 3011 AD », PL « 50-001 », CZ « 110 00 ».', array() ),
				'city'           => array( 'Localité', true, 'text', '', array() ),
				'country'        => array( 'Pays', false, 'list', 'Code pays. Par défaut : CH. Un fournisseur hors de Suisse et du Liechtenstein livre avec une lettre de voiture CMR et facture en EUR.', self::COUNTRIES ),
				'contact'        => array( 'Personne de contact', false, 'text', '', array() ),
				'email'          => array( 'Courriel', false, 'text', '', array() ),
				'phone'          => array( 'Téléphone', false, 'text', 'Avec l\'indicatif du pays, ex. +41 21 555 12 34 ou +49 7621 555 12 34.', array() ),
				'payment_terms'  => array( 'Conditions de paiement', false, 'text', 'Par défaut : 30 jours net.', array() ),
				'incoterm'       => array( 'Incoterm', false, 'list', 'Par défaut : DAP.', array_combine( self::INCOTERMS, self::INCOTERMS ) ),
				'lead_time_days' => array( 'Délai de livraison (jours)', false, 'int', 'Par défaut : 3.', array() ),
				'reliability'    => array( 'Fiabilité (%)', false, 'int', '0 à 100. Par défaut : 95.', array() ),
				'is_problematic' => array( 'Fournisseur à problèmes', false, 'bool', 'OUI = livraisons partielles ou en retard plus fréquentes dans les scénarios.', array() ),
				'active'         => array( 'Actif', false, 'bool', 'Par défaut : OUI.', array() ),
			);
		}
		$types = array();
		foreach ( Dictionaries::customer_types() as $t ) {
			$types[ $t[0] ] = $t[0];
		}
		$carriers = array();
		foreach ( Dictionaries::carriers() as $c ) {
			$carriers[ $c[0] ] = $c[1];
		}
		return array(
			'customer_no'     => array( 'N° client', false, 'text', 'Vide = numéro attribué automatiquement (C + 5 chiffres). Un numéro existant met le client à jour.', array() ),
			'name'            => array( 'Nom / raison sociale', true, 'text', '', array() ),
			'type'            => array( 'Type de client', true, 'list', '', $types ),
			'street'          => array( 'Rue et n°', true, 'text', '', array() ),
			'zip'             => array( 'NPA', true, 'text', 'Numéro postal suisse à 4 chiffres (1000 à 9699).', array() ),
			'city'            => array( 'Localité', true, 'text', '', array() ),
			'email'           => array( 'Courriel', false, 'text', '', array() ),
			'phone'           => array( 'Téléphone', false, 'text', 'Format suisse, ex. +41 21 555 12 34.', array() ),
			'priority'        => array( 'Priorité', false, 'list', 'A = prioritaire. Par défaut : B.', array( 'A' => 'A', 'B' => 'B', 'C' => 'C' ) ),
			'payment_terms'   => array( 'Conditions de paiement', false, 'text', 'Par défaut : 30 jours net.', array() ),
			'carrier_code'    => array( 'Transporteur', false, 'list', 'Code du transporteur habituel.', $carriers ),
			'delivery_days'   => array( 'Jours de livraison', false, 'text', 'Numéros des jours séparés par des virgules : 1 = lundi … 6 = samedi. Par défaut : 1,2,3,4,5.', array() ),
			'window_from'     => array( 'Livraison dès (heure)', false, 'text', 'Format HH:MM, ex. 07:00. Vide = toute la journée.', array() ),
			'window_to'       => array( 'Livraison jusqu\'à (heure)', false, 'text', 'Format HH:MM, ex. 11:00.', array() ),
			'pallet_type'     => array( 'Support de charge exigé', false, 'list', 'Support imposé par le client pour ses livraisons.', array( 'EUR' => 'Palette EUR (échange)', 'CP3' => 'Palette chimique CP3', 'PERDUE' => 'Palette perdue', 'COLIS' => 'Colis' ) ),
			'vehicle_req'     => array( 'Contrainte véhicule', false, 'list', 'Accès du lieu de livraison.', array( 'PORTEUR' => 'Porteur uniquement', 'HAYON' => 'Hayon obligatoire', 'FOURGON' => 'Fourgon 3,5 t uniquement' ) ),
			'appointment'     => array( 'Rendez-vous obligatoire', false, 'bool', 'OUI = annoncer la livraison (prise de rendez-vous).', array() ),
			'packing_req'     => array( 'Exigences d\'emballage', false, 'text', '', array() ),
			'credit_limit'    => array( 'Limite de crédit (CHF)', false, 'dec', 'Par défaut : 10 000.', array() ),
			'blocked'         => array( 'Bloqué', false, 'bool', 'OUI = aucune nouvelle commande acceptée.', array() ),
			'active'          => array( 'Actif', false, 'bool', 'Par défaut : OUI.', array() ),
		);
	}

	public static function label( string $kind ): string {
		return array( 'material' => 'Articles', 'supplier' => 'Fournisseurs', 'customer' => 'Clients' )[ $kind ] ?? $kind;
	}

	public static function number_key( string $kind ): string {
		return array( 'material' => 'material_no', 'supplier' => 'supplier_no', 'customer' => 'customer_no' )[ $kind ];
	}

	/* ─────────────────────────── Lecture ─────────────────────────── */

	public static function find( string $kind, string $no ): ?array {
		global $wpdb;
		$t   = Db::t( $kind );
		$key = self::number_key( $kind );
		$del = 'material' === $kind ? ' AND deleted_at IS NULL' : '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d AND {$key} = %s{$del}", Catalog::dataset(), $no ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** Valeurs d'un enregistrement existant, au format des colonnes (pour les formulaires et l'export). */
	public static function to_values( string $kind, array $r ): array {
		$out = array();
		foreach ( self::columns( $kind ) as $key => $col ) {
			if ( 'group' === $key ) {
				$out[ $key ] = self::group_name( (int) $r['group_id'] );
				continue;
			}
			if ( 'supplier_no' === $key && 'material' === $kind ) {
				$out[ $key ] = self::supplier_no( (int) $r['main_supplier_id'] );
				continue;
			}
			$v = $r[ $key ] ?? '';
			if ( 'bool' === $col[2] ) {
				$v = (int) $v ? 'OUI' : 'NON';
			} elseif ( 'dec' === $col[2] ) {
				$v = Catalog::format_qty( (float) $v );
			} elseif ( 'int' === $col[2] ) {
				$v = (string) (int) $v;
			}
			$out[ $key ] = (string) $v;
		}
		return $out;
	}

	private static array $groups = array();

	private static function group_name( int $id ): string {
		global $wpdb;
		if ( ! $id ) {
			return '';
		}
		if ( ! isset( self::$groups[ $id ] ) ) {
			self::$groups[ $id ] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Db::t( 'material_group' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return self::$groups[ $id ];
	}

	private static array $sup_nos = array();

	private static function supplier_no( int $id ): string {
		global $wpdb;
		if ( ! $id ) {
			return '';
		}
		if ( ! isset( self::$sup_nos[ $id ] ) ) {
			self::$sup_nos[ $id ] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT supplier_no FROM ' . Db::t( 'supplier' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return self::$sup_nos[ $id ];
	}

	/** Un article est « utilisé » s'il a du stock (référence ou session) ou figure dans un scénario ou une commande. */
	public static function material_in_use( int $id ): bool {
		global $wpdb;
		foreach ( array( 'stock', 'scenario_initial_stock', 'purchase_order_item', 'sales_order_item' ) as $t ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Db::t( $t ) . ' WHERE material_id = %d LIMIT 1', $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return true;
			}
		}
		$like = '%"material_id":' . $id . ',%';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Db::t( 'scenario_document' ) . ' WHERE items LIKE %s OR items LIKE %s LIMIT 1', $like, '%"material_id":' . $id . '}%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ─────────────────────────── Contrôles ─────────────────────────── */

	public static function parse_bool( string $v ): ?bool {
		$v = mb_strtolower( trim( $v ) );
		if ( in_array( $v, array( 'oui', 'o', 'yes', 'y', 'ja', 'j', '1', 'vrai', 'true', 'x' ), true ) ) {
			return true;
		}
		if ( in_array( $v, array( 'non', 'n', 'no', 'nein', '0', 'faux', 'false' ), true ) ) {
			return false;
		}
		return null;
	}

	public static function parse_dec( string $v ): ?float {
		$v = str_replace( array( "'", '’', ' ', "\u{00A0}", "\u{202F}" ), '', trim( $v ) );
		if ( preg_match( '/^-?\d+,\d+$/', $v ) ) {
			$v = str_replace( ',', '.', $v );
		}
		return is_numeric( $v ) ? (float) $v : null;
	}

	public static function valid_ean( string $ean ): bool {
		if ( ! preg_match( '/^(\d{8}|\d{13})$/', $ean ) ) {
			return false;
		}
		$digits = array_map( 'intval', str_split( $ean ) );
		$check  = array_pop( $digits );
		$sum    = 0;
		foreach ( array_reverse( $digits ) as $i => $d ) {
			$sum += $d * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10 === $check;
	}

	/** Valeur d'une liste : accepte le code ou le libellé (sans tenir compte de la casse). */
	private static function list_value( string $v, array $allowed ): ?string {
		foreach ( $allowed as $code => $label ) {
			if ( 0 === strcasecmp( $v, (string) $code ) || 0 === strcasecmp( $v, (string) $label ) ) {
				return (string) $code;
			}
		}
		return null;
	}

	/**
	 * Contrôle et normalise un enregistrement.
	 *
	 * @param array<string,string> $in      Valeurs saisies (texte), indexées par clé de colonne.
	 * @param array|null           $current Enregistrement existant (mise à jour) ou null (création).
	 * @param array<string,bool>   $reserved Numéros déjà pris dans le même import.
	 * @return array{0:array<string,mixed>,1:string[]} [données prêtes pour la base, erreurs]
	 */
	public static function validate( string $kind, array $in, ?array $current, array $reserved = array() ): array {
		$cols   = self::columns( $kind );
		$errors = array();
		$base   = $current ? self::to_values( $kind, $current ) : array();
		$v      = array();
		foreach ( $cols as $key => $col ) {
			$raw = isset( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
			if ( '' === $raw && $current ) {
				$raw = (string) ( $base[ $key ] ?? '' ); // Mise à jour : cellule vide = valeur actuelle conservée.
			}
			if ( '' === $raw ) {
				if ( $col[1] ) {
					$errors[] = sprintf( '« %s » est obligatoire.', $col[0] );
				}
				$v[ $key ] = null;
				continue;
			}
			switch ( $col[2] ) {
				case 'int':
					$n = self::parse_dec( $raw );
					if ( null === $n || $n < 0 || abs( $n - round( $n ) ) > 1e-9 ) {
						$errors[] = sprintf( '« %s » : nombre entier positif attendu (reçu « %s »).', $col[0], $raw );
						$n = null;
					}
					$v[ $key ] = null === $n ? null : (int) round( $n );
					break;
				case 'dec':
					$n = self::parse_dec( $raw );
					if ( null === $n || $n < 0 ) {
						$errors[] = sprintf( '« %s » : nombre positif attendu (reçu « %s »).', $col[0], $raw );
						$n = null;
					}
					$v[ $key ] = $n;
					break;
				case 'bool':
					$b = self::parse_bool( $raw );
					if ( null === $b ) {
						$errors[] = sprintf( '« %s » : OUI ou NON attendu (reçu « %s »).', $col[0], $raw );
					}
					$v[ $key ] = $b;
					break;
				case 'list':
					$c = self::list_value( $raw, $col[4] );
					if ( null === $c ) {
						$errors[] = sprintf( '« %s » : valeur « %s » non autorisée (voir la feuille Aide).', $col[0], $raw );
					}
					$v[ $key ] = $c;
					break;
				default:
					$v[ $key ] = mb_substr( sanitize_text_field( $raw ), 0, 'designation' === $key || 'name' === $key || 'street' === $key || 'hazard_text' === $key || 'packing_req' === $key ? 190 : 120 );
			}
		}
		if ( $errors ) {
			return array( array(), $errors );
		}
		return 'material' === $kind ? self::check_material( $v, $current, $reserved ) : self::check_partner( $kind, $v, $current, $reserved );
	}

	private static function check_material( array $v, ?array $cur, array $reserved ): array {
		global $wpdb;
		$e        = array();
		$profiles = Dictionaries::profiles();
		$p        = (string) $v['profile_code'];

		// Numéro.
		$no = (string) ( $v['material_no'] ?? '' );
		if ( $cur ) {
			$no = $cur['material_no'];
		} elseif ( '' !== $no ) {
			if ( ! preg_match( '/^\d{6,12}$/', $no ) ) {
				$e[] = 'N° article : 6 à 12 chiffres attendus.';
			} elseif ( isset( $reserved[ 'material:' . $no ] ) || self::find( 'material', $no ) ) {
				$e[] = sprintf( 'Le n° article %s existe déjà.', $no );
			}
		}

		$net   = (float) $v['net_weight'];
		$gross = null === $v['gross_weight'] ? round( $net * 1.05, 3 ) : (float) $v['gross_weight'];
		$len   = (int) $v['length_mm'];
		$wid   = (int) $v['width_mm'];
		$hei   = (int) $v['height_mm'];
		$vol   = null === $v['volume_l'] ? round( $len * $wid * $hei / 1e6, 3 ) : (float) $v['volume_l'];
		if ( $net <= 0 ) {
			$e[] = 'Le poids net doit être supérieur à 0.';
		}
		if ( $gross + 1e-9 < $net ) {
			$e[] = 'Le poids brut doit être supérieur ou égal au poids net.';
		}
		if ( $len <= 0 || $wid <= 0 || $hei <= 0 ) {
			$e[] = 'Les trois dimensions doivent être supérieures à 0 mm.';
		}
		if ( $vol <= 0 ) {
			$e[] = 'Le volume doit être supérieur à 0.';
		}

		// Cohérence profil / caractéristiques (règles de stockage professionnelles).
		if ( $len > 2500 && ! in_array( $p, array( 'LONG' ), true ) ) {
			$e[] = 'Article de plus de 2,5 m : le profil « Marchandises longues » (LONG) est obligatoire.';
		}
		if ( 'LONG' === $p && $len <= 2500 ) {
			$e[] = 'Profil LONG : la longueur doit dépasser 2 500 mm.';
		}
		if ( $gross > 500 && 'HEAVY' !== $p ) {
			$e[] = 'Article de plus de 500 kg : le profil « Marchandises lourdes » (HEAVY) est obligatoire.';
		}
		if ( 'HEAVY' === $p && $gross <= 500 ) {
			$e[] = 'Profil HEAVY : le poids brut doit dépasser 500 kg.';
		}
		$adr = (string) ( $v['adr_class'] ?? '' );
		$un  = (string) ( $v['un_number'] ?? '' );
		if ( 'CHEM' === $p && ! in_array( $adr, array( '2', '3', '5.1', '6.1', '8' ), true ) ) {
			$e[] = 'Profil Chimie : indiquez la classe ADR (2, 3, 5.1, 6.1 ou 8).';
		}
		if ( 'BATT' === $p && '9' !== $adr ) {
			$e[] = 'Profil Batteries lithium : la classe ADR doit être 9.';
		}
		if ( '' !== $adr && ! in_array( $p, array( 'CHEM', 'BATT' ), true ) ) {
			$e[] = 'Une matière dangereuse (classe ADR) doit avoir le profil Chimie (CHEM) ou Batteries lithium (BATT).';
		}
		if ( '' !== $adr && ! preg_match( '/^\d{4}$/', $un ) ) {
			$e[] = 'Matière dangereuse : le n° ONU (4 chiffres) est obligatoire.';
		}
		if ( '' === $adr && '' !== $un ) {
			$e[] = 'N° ONU indiqué sans classe ADR.';
		}
		$pg = (string) ( $v['packing_group'] ?? '' );
		if ( '' !== $adr && ! in_array( $adr, array( '2', '9' ), true ) && '' === $pg ) {
			$e[] = sprintf( 'Classe ADR %s : le groupe d\'emballage (I, II ou III) est obligatoire.', $adr );
		}
		if ( in_array( $adr, array( '2', '9' ), true ) && '' !== $pg ) {
			$pg = '';
		}

		$shelf = (int) ( $v['shelf_life_days'] ?? 0 );
		$batch = $v['batch_managed'];
		if ( in_array( $p, array( 'COLD', 'FROZ' ), true ) && $shelf <= 0 ) {
			$e[] = 'Produit frais ou surgelé : la durée de vie (jours) est obligatoire pour gérer les DLC en FEFO.';
		}
		if ( in_array( $p, array( 'COLD', 'FROZ', 'TEMP', 'CHEM' ), true ) || $shelf > 0 ) {
			if ( false === $batch ) {
				$e[] = 'Ce profil (ou une durée de vie) impose la gestion par lot : mettez « Gestion par lot » à OUI.';
			}
			$batch = true;
		}
		if ( $shelf > 3650 ) {
			$e[] = 'Durée de vie : 3 650 jours au maximum.';
		}

		$ean = (string) ( $v['ean'] ?? '' );
		if ( '' !== $ean && ( 13 !== strlen( $ean ) || ! self::valid_ean( $ean ) ) ) {
			$e[] = sprintf( 'EAN-13 « %s » invalide : 13 chiffres, clé de contrôle comprise (laisser vide pour un code interne automatique).', $ean );
		} elseif ( '' !== $ean ) {
			global $wpdb;
			$mt    = Db::t( 'material' );
			$other = (string) $wpdb->get_var( $wpdb->prepare( "SELECT material_no FROM {$mt} WHERE dataset_id = %d AND ean = %s AND id <> %d AND deleted_at IS NULL LIMIT 1", Catalog::dataset(), $ean, $cur ? (int) $cur['id'] : 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( '' !== $other || isset( $reserved[ 'ean:' . $ean ] ) ) {
				$e[] = sprintf( 'EAN-13 %s déjà attribué à l\'article %s : chaque article a son propre code.', $ean, '' !== $other ? $other : 'précédent du fichier' );
			}
		}

		$min = (float) ( $v['min_stock'] ?? 0 );
		$rop = null === $v['reorder_point'] ? $min : (float) $v['reorder_point'];
		$max = (float) ( $v['max_stock'] ?? 0 );
		if ( $rop + 1e-9 < $min ) {
			$e[] = 'Le point de commande doit être supérieur ou égal au stock minimum.';
		}
		if ( $max > 0 && $max + 1e-9 < $rop ) {
			$e[] = 'Le stock maximum doit être supérieur ou égal au point de commande.';
		}

		$sup = null;
		if ( '' !== (string) ( $v['supplier_no'] ?? '' ) ) {
			$sup = self::find( 'supplier', (string) $v['supplier_no'] );
			if ( ! $sup && ! isset( $reserved[ 'supplier:' . $v['supplier_no'] ] ) ) {
				$e[] = sprintf( 'Fournisseur %s inconnu : créez-le d\'abord (ou importez les fournisseurs avant les articles).', $v['supplier_no'] );
			}
		}

		$data = array(
			'designation'        => (string) $v['designation'],
			'type'               => (string) ( $v['type'] ?? 'MRCH' ),
			'base_uom'           => (string) ( $v['base_uom'] ?? ( $profiles[ $p ]['uom'] ?? 'PCE' ) ),
			'profile_code'       => $p,
			'net_weight'         => round( $net, 3 ),
			'gross_weight'       => round( $gross, 3 ),
			'length_mm'          => $len,
			'width_mm'           => $wid,
			'height_mm'          => $hei,
			'volume_l'           => round( $vol, 3 ),
			'std_price'          => round( (float) $v['std_price'], 4 ),
			'sale_price'         => round( null === $v['sale_price'] ? (float) $v['std_price'] * 1.4 : (float) $v['sale_price'], 4 ),
			'abc'                => (string) ( $v['abc'] ?? 'C' ),
			'batch_managed'      => $batch ? 1 : 0,
			'shelf_life_days'    => $shelf,
			'min_stock'          => $min,
			'reorder_point'      => $rop,
			'max_stock'          => $max,
			'lot_size'           => max( 1.0, (float) ( $v['lot_size'] ?? 1 ) ),
			'lead_time_days'     => min( 365, (int) ( $v['lead_time_days'] ?? ( $sup ? (int) $sup['lead_time_days'] : 5 ) ) ),
			'ean'                => $ean,
			'adr_class'          => $adr,
			'un_number'          => $un,
			'packing_group'      => $pg,
			'hazard_text'        => '' !== $adr ? (string) ( $v['hazard_text'] ?? '' ) : '',
			'stackable'          => null === $v['stackable'] ? ( in_array( $p, array( 'BULKY', 'FRAG', 'HEAVY' ), true ) ? 0 : 1 ) : ( $v['stackable'] ? 1 : 0 ),
			'fragile'            => 'FRAG' === $p ? 1 : 0,
			'high_value'         => 'HV' === $p ? 1 : 0,
			'units_per_carton'   => max( 1, (int) ( $v['units_per_carton'] ?? 1 ) ),
			'cartons_per_pallet' => max( 1, (int) ( $v['cartons_per_pallet'] ?? 1 ) ),
			'status'             => (string) ( $v['status'] ?? 'ACTIF' ),
		);
		$temp = array( 'COLD' => array( 2, 8 ), 'FROZ' => array( -25, -18 ), 'TEMP' => array( 15, 25 ) )[ $p ] ?? null;
		$data['temp_min'] = $temp ? (float) $temp[0] : null;
		$data['temp_max'] = $temp ? (float) $temp[1] : null;

		// Article utilisé : caractéristiques physiques figées.
		if ( $cur && self::material_in_use( (int) $cur['id'] ) ) {
			foreach ( self::STRUCTURAL as $k ) {
				$a = $cur[ $k ];
				$b = $data[ $k ];
				$same = is_numeric( $a ) && is_numeric( $b ) ? abs( (float) $a - (float) $b ) < 0.0005 : (string) $a === (string) $b;
				if ( ! $same ) {
					$e[] = sprintf( 'Article déjà en stock ou utilisé dans un scénario : « %s » ne peut plus être modifié (actuel : %s).', self::columns( 'material' )[ $k ][0] ?? $k, (string) $a );
				}
			}
		}

		// Faisabilité : au moins un emplacement de l'entrepôt doit pouvoir accueillir l'article.
		if ( ! $e ) {
			$why = self::placement_problem( $data );
			if ( '' !== $why ) {
				$e[] = $why;
			}
		}
		if ( $e ) {
			return array( array(), $e );
		}

		$data['material_no']  = $no;
		$data['_group']       = (string) ( $v['group'] ?? '' );
		$data['_supplier_no'] = (string) ( $v['supplier_no'] ?? '' );
		return array( $data, array() );
	}

	/** Vérifie qu'une zone de stockage et un emplacement compatibles existent. Retourne le problème, ou ''. */
	public static function placement_problem( array $m ): string {
		global $wpdb;
		$locs = array_filter( Catalog::locations(), static fn( $l ) => empty( $l['is_transit'] ) );
		$ok   = array_filter( $locs, static fn( $l ) => ! Rules::check_location( $m, $l ) );
		if ( ! $ok ) {
			return sprintf( 'Aucune zone de stockage n\'accepte cet article (profil « %s »%s%s%s). Adaptez les critères d\'une zone dans Entrepôt, ou créez-en une.', Catalog::profile_label( $m['profile_code'] ), '' !== $m['adr_class'] ? ', ADR ' . $m['adr_class'] : '', null !== $m['temp_min'] ? ', ' . Catalog::format_qty( (float) $m['temp_min'] ) . ' à ' . Catalog::format_qty( (float) $m['temp_max'] ) . ' °C' : '', $m['high_value'] ? ', sous clé' : '' );
		}
		$ids = implode( ',', array_map( static fn( $l ) => (int) $l['id'], $ok ) );
		$bt  = Db::t( 'bin' );
		$fit = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$bt} WHERE storage_location_id IN ({$ids}) AND length_mm >= %d AND max_weight >= %f AND max_volume_l >= %f LIMIT 1", (int) $m['length_mm'], (float) $m['gross_weight'], (float) $m['volume_l'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiants entiers.
		if ( ! $fit ) {
			return sprintf( 'Aucun emplacement des zones autorisées ne peut recevoir une unité de %d mm, %s kg et %s l. Ajoutez des emplacements plus grands dans Entrepôt.', (int) $m['length_mm'], Catalog::format_qty( (float) $m['gross_weight'] ), Catalog::format_qty( (float) $m['volume_l'] ) );
		}
		return '';
	}

	private static function check_partner( string $kind, array $v, ?array $cur, array $reserved ): array {
		$e   = array();
		$key = self::number_key( $kind );
		$no  = (string) ( $v[ $key ] ?? '' );
		$pre = 'supplier' === $kind ? 'F' : 'C';
		if ( $cur ) {
			$no = $cur[ $key ];
		} elseif ( '' !== $no ) {
			$no = strtoupper( $no );
			if ( ! preg_match( '/^' . $pre . '\d{5}$/', $no ) ) {
				$e[] = sprintf( 'Numéro : %s suivi de 5 chiffres attendu (ex. %s).', $pre, 'supplier' === $kind ? 'F20101' : 'C10101' );
			} elseif ( isset( $reserved[ $kind . ':' . $no ] ) || self::find( $kind, $no ) ) {
				$e[] = sprintf( 'Le numéro %s existe déjà.', $no );
			}
		}
		if ( 'customer' === $kind ) {
			$days = (string) ( $v['delivery_days'] ?? '' );
			if ( '' !== $days && ! preg_match( '/^[1-7](,[1-7]){0,6}$/', str_replace( ' ', '', $days ) ) ) {
				$e[] = 'Jours de livraison : numéros de 1 (lundi) à 7 (dimanche) séparés par des virgules, ex. 1,3,5.';
			}
			$wf = (string) ( $v['window_from'] ?? '' );
			$wt = (string) ( $v['window_to'] ?? '' );
			$ok = static fn( $h ) => (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $h );
			if ( ( '' !== $wf || '' !== $wt ) && ( ! $ok( $wf ) || ! $ok( $wt ) || $wf >= $wt ) ) {
				$e[] = 'Plage de livraison : deux heures au format HH:MM, la première avant la seconde (ex. 07:00 et 11:00).';
			}
		}
		// Les clients sont en Suisse ; un fournisseur peut être situé à l'étranger.
		$country = 'supplier' === $kind ? strtoupper( (string) ( $v['country'] ?? '' ) ) : 'CH';
		$country = '' === $country ? 'CH' : $country;
		$zip     = strtoupper( trim( (string) $v['zip'] ) );
		if ( ! isset( self::ZIP_FORMATS[ $country ] ) ) {
			$e[] = sprintf( 'Pays « %s » non pris en charge.', $country );
		} elseif ( 'CH' === $country && ( ! preg_match( '/^\d{4}$/', $zip ) || (int) $zip < 1000 || (int) $zip > 9699 ) ) {
			$e[] = sprintf( 'NPA « %s » invalide : numéro postal suisse à 4 chiffres attendu (1000 à 9699).', $zip );
		} elseif ( ! preg_match( self::ZIP_FORMATS[ $country ][0], $zip ) ) {
			$e[] = sprintf( 'Code postal « %s » invalide pour %s (exemple : %s).', $zip, self::COUNTRIES[ $country ], self::ZIP_FORMATS[ $country ][1] );
		}
		$email = (string) ( $v['email'] ?? '' );
		if ( '' !== $email && ! is_email( $email ) ) {
			$e[] = sprintf( 'Courriel « %s » invalide.', $email );
		}
		$phone = (string) ( $v['phone'] ?? '' );
		if ( '' !== $phone && isset( self::PHONE_CODES[ $country ] ) ) {
			$cc    = self::PHONE_CODES[ $country ];
			$local = 'CH' === $country ? '|0' : '';
			if ( ! preg_match( '/^(\+' . $cc . '|00' . $cc . $local . ')[\s\d]{6,17}$/', $phone ) ) {
				$e[] = sprintf( 'Téléphone « %s » : numéro de %s attendu, avec l\'indicatif +%s.', $phone, self::COUNTRIES[ $country ], $cc );
			}
		}
		if ( $e ) {
			return array( array(), $e );
		}
		$data = array(
			$key            => $no,
			'name'          => (string) $v['name'],
			'street'        => (string) $v['street'],
			'zip'           => $zip,
			'city'          => (string) $v['city'],
			'country'       => $country,
			'email'         => $email,
			'phone'         => $phone,
			'payment_terms' => (string) ( $v['payment_terms'] ?? '30 jours net' ),
			'active'        => false === $v['active'] ? 0 : 1,
		);
		if ( 'supplier' === $kind ) {
			$data += array(
				'specialty'      => (string) $v['specialty'],
				'contact'        => (string) ( $v['contact'] ?? '' ),
				'incoterm'       => (string) ( $v['incoterm'] ?? 'DAP' ),
				'lead_time_days' => min( 365, (int) ( $v['lead_time_days'] ?? 3 ) ),
				'reliability'    => min( 100, (int) ( $v['reliability'] ?? 95 ) ),
				'currency'       => in_array( $country, array( 'CH', 'LI' ), true ) ? 'CHF' : 'EUR',
				'is_problematic' => $v['is_problematic'] ? 1 : 0,
			);
		} else {
			$data += array(
				'type'            => (string) $v['type'],
				'priority'        => (string) ( $v['priority'] ?? 'B' ),
				'carrier_code'    => (string) ( $v['carrier_code'] ?? '' ),
				'delivery_days'   => '' !== (string) ( $v['delivery_days'] ?? '' ) ? str_replace( ' ', '', (string) $v['delivery_days'] ) : '1,2,3,4,5',
				'window_from'     => (string) ( $v['window_from'] ?? '' ),
				'window_to'       => (string) ( $v['window_to'] ?? '' ),
				'delivery_window' => '' !== (string) ( $v['window_from'] ?? '' ) ? $v['window_from'] . '–' . $v['window_to'] : 'Toute la journée',
				'pallet_type'     => (string) ( $v['pallet_type'] ?? '' ),
				'vehicle_req'     => (string) ( $v['vehicle_req'] ?? '' ),
				'appointment'     => ! empty( $v['appointment'] ) ? 1 : 0,
				'lat'             => \LogiERP\Seeder\LogisticsSeeder::COORDS[ (string) ( $v['zip'] ?? '' ) ][0] ?? ( $cur['lat'] ?? 46.52 ),
				'lon'             => \LogiERP\Seeder\LogisticsSeeder::COORDS[ (string) ( $v['zip'] ?? '' ) ][1] ?? ( $cur['lon'] ?? 6.63 ),
				'packing_req'     => (string) ( $v['packing_req'] ?? '' ),
				'credit_limit'    => (float) ( $v['credit_limit'] ?? 10000 ),
				'blocked'         => $v['blocked'] ? 1 : 0,
			);
		}
		return array( $data, array() );
	}

	/* ─────────────────────────── Enregistrement ─────────────────────────── */

	/** Prochain numéro libre. */
	public static function next_number( string $kind, array $data, array &$reserved ): string {
		global $wpdb;
		$t = Db::t( $kind );
		if ( 'material' === $kind ) {
			$prefix = Dictionaries::profiles()[ $data['profile_code'] ]['prefix'] ?? '19';
			$max    = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(material_no) FROM {$t} WHERE dataset_id = %d AND material_no LIKE %s AND LENGTH(material_no) = 8", Catalog::dataset(), $prefix . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n      = $max ? (int) substr( $max, 2 ) + 1 : 1;
			do {
				$no = $prefix . str_pad( (string) $n, 6, '0', STR_PAD_LEFT );
				++$n;
			} while ( isset( $reserved[ 'material:' . $no ] ) );
		} else {
			$key  = self::number_key( $kind );
			$pre  = 'supplier' === $kind ? 'F' : 'C';
			$max  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX({$key}) FROM {$t} WHERE dataset_id = %d AND {$key} REGEXP %s", Catalog::dataset(), '^' . $pre . '[0-9]{5}$' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n    = $max ? (int) substr( $max, 1 ) + 1 : ( 'F' === $pre ? 20001 : 10001 );
			do {
				$no = $pre . $n;
				++$n;
			} while ( isset( $reserved[ $kind . ':' . $no ] ) );
		}
		$reserved[ $kind . ':' . $no ] = true;
		return $no;
	}

	/**
	 * Enregistre un enregistrement contrôlé (création ou mise à jour). Retourne l'identifiant.
	 */
	public static function save( string $kind, array $data, ?array $current, array &$reserved ): int {
		global $wpdb;
		$key = self::number_key( $kind );
		if ( 'material' === $kind ) {
			$group = (string) $data['_group'];
			$supno = (string) $data['_supplier_no'];
			unset( $data['_group'], $data['_supplier_no'] );
			$data['group_id'] = '' !== $group ? self::group_id( $group, (string) $data['profile_code'] ) : ( $current ? (int) $current['group_id'] : 0 );
			$sup              = '' !== $supno ? self::find( 'supplier', $supno ) : null;
			$data['main_supplier_id'] = $sup ? (int) $sup['id'] : ( $current ? (int) $current['main_supplier_id'] : 0 );
		}
		if ( ! $current && '' === (string) ( $data[ $key ] ?? '' ) ) {
			$data[ $key ] = self::next_number( $kind, $data, $reserved );
		}
		$reserved[ $kind . ':' . $data[ $key ] ] = true;
		if ( 'material' === $kind ) {
			// Chaque article porte un EAN-13 scannable : code interne attribué s'il n'en a pas.
			if ( '' === (string) ( $data['ean'] ?? '' ) || ! \LogiERP\Domain\Gs1::is_ean13( (string) $data['ean'] ) ) {
				$data['ean'] = $current && \LogiERP\Domain\Gs1::is_ean13( (string) $current['ean'] ) ? (string) $current['ean'] : \LogiERP\Domain\Gs1::internal_ean13( (string) ( $data[ $key ] ?? $current[ $key ] ?? '' ), Catalog::dataset(), $current ? (int) $current['id'] : 0 );
			}
			$reserved[ 'ean:' . $data['ean'] ] = true;
		}
		$nulls = array();
		foreach ( array( 'temp_min', 'temp_max' ) as $c ) {
			if ( array_key_exists( $c, $data ) && null === $data[ $c ] ) {
				$nulls[] = $c;
				unset( $data[ $c ] );
			}
		}
		if ( $current ) {
			$id = (int) $current['id'];
			unset( $data[ $key ] );
			if ( 'material' === $kind ) {
				$data['updated_at'] = Db::now();
			}
			Db::update( $kind, $data, array( 'id' => $id ) );
		} else {
			$data['dataset_id'] = Catalog::dataset();
			if ( 'material' === $kind ) {
				$data['created_at'] = Db::now();
				$data['updated_at'] = Db::now();
				$data['xyz']        = 'Y';
			}
			$id = Db::insert( $kind, $data );
		}
		if ( $nulls ) {
			$t = Db::t( $kind );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET temp_min = NULL, temp_max = NULL WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'material' === $kind && ! empty( $data['main_supplier_id'] ) ) {
			self::ensure_info_record( $id, (int) $data['main_supplier_id'], $data );
		}
		Catalog::reset_cache();
		return $id;
	}

	/** Fiche d'info-achat article / fournisseur principal (nécessaire pour commander l'article). */
	private static function ensure_info_record( int $material_id, int $supplier_id, array $m ): void {
		global $wpdb;
		$t = Db::t( 'supplier_material' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET is_main = 0 WHERE material_id = %d AND supplier_id <> %d", $material_id, $supplier_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE material_id = %d AND supplier_id = %d", $material_id, $supplier_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = array(
			'price'          => (float) $m['std_price'],
			'min_qty'        => max( 1.0, (float) ( $m['lot_size'] ?? 1 ) ),
			'lead_time_days' => (int) $m['lead_time_days'],
			'is_main'        => 1,
		);
		if ( $id ) {
			Db::update( 'supplier_material', array( 'is_main' => 1 ), array( 'id' => $id ) ); // Prix et délai négociés conservés.
		} else {
			Db::insert( 'supplier_material', $row + array( 'supplier_id' => $supplier_id, 'material_id' => $material_id, 'supplier_ref' => 'R-' . $material_id ) );
		}
	}

	private static function group_id( string $name, string $profile ): int {
		global $wpdb;
		$t  = Db::t( 'material_group' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND name = %s", Catalog::dataset(), $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			return $id;
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE dataset_id = %d AND code LIKE %s", Catalog::dataset(), $profile . '-%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return Db::insert( 'material_group', array( 'dataset_id' => Catalog::dataset(), 'code' => mb_substr( $profile . '-U' . ( $n + 1 ), 0, 20 ), 'name' => mb_substr( $name, 0, 190 ) ) );
	}

	/* ─────────────────────────── Import / export Excel ─────────────────────────── */

	private static function norm( string $s ): string {
		$s = remove_accents( mb_strtolower( $s ) );
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/**
	 * Importe des lignes lues dans un classeur.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @return array{created:int,updated:int,skipped:int,errors:string[],imported:bool}
	 */
	public static function import( string $kind, array $rows, bool $skip_errors ): array {
		$cols   = self::columns( $kind );
		$result = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'imported' => false );
		if ( count( $rows ) < 2 ) {
			$result['errors'][] = 'Le fichier ne contient aucune ligne de données sous l\'en-tête.';
			return $result;
		}
		// En-têtes : libellé du modèle ou clé technique.
		$lookup = array();
		foreach ( $cols as $key => $col ) {
			$lookup[ self::norm( $col[0] ) ] = $key;
			$lookup[ self::norm( $key ) ]    = $key;
		}
		$map = array();
		foreach ( (array) $rows[0] as $i => $h ) {
			$k = $lookup[ self::norm( (string) $h ) ] ?? null;
			if ( $k && ! in_array( $k, $map, true ) ) {
				$map[ $i ] = $k;
			}
		}
		$missing = array_diff( array_keys( array_filter( $cols, static fn( $c ) => $c[1] ) ), $map );
		if ( $missing ) {
			$result['errors'][] = 'Colonnes obligatoires absentes de la première ligne : ' . implode( ', ', array_map( static fn( $k ) => '« ' . $cols[ $k ][0] . ' »', $missing ) ) . '. Utilisez le modèle Excel.';
			return $result;
		}
		$key      = self::number_key( $kind );
		$reserved = array();
		$plan     = array();
		$seen     = array();
		foreach ( array_slice( $rows, 1, null, true ) as $r => $cells ) {
			$line = $r + 1;
			$in   = array();
			foreach ( $map as $i => $k ) {
				$in[ $k ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			if ( '' === implode( '', $in ) ) {
				continue;
			}
			$no      = strtoupper( trim( (string) ( $in[ $key ] ?? '' ) ) );
			$current = '' !== $no ? self::find( $kind, $no ) : null;
			if ( '' !== $no ) {
				if ( isset( $seen[ $no ] ) ) {
					$result['errors'][] = sprintf( 'Ligne %d : le numéro %s figure déjà à la ligne %d.', $line, $no, $seen[ $no ] );
					++$result['skipped'];
					continue;
				}
				$seen[ $no ] = $line;
				$in[ $key ]  = $no;
			}
			[ $data, $errs ] = self::validate( $kind, $in, $current, $reserved );
			if ( $errs ) {
				foreach ( $errs as $err ) {
					$result['errors'][] = sprintf( 'Ligne %d%s : %s', $line, '' !== $no ? ' (' . $no . ')' : '', $err );
				}
				++$result['skipped'];
				continue;
			}
			if ( ! $current && '' !== (string) ( $data[ $key ] ?? '' ) ) {
				$reserved[ $kind . ':' . $data[ $key ] ] = true;
			}
			$plan[] = array( $data, $current );
		}
		if ( $result['errors'] && ! $skip_errors ) {
			return $result; // Tout ou rien : aucune ligne n'est importée.
		}
		if ( ! $plan ) {
			return $result;
		}
		Db::transaction(
			static function () use ( $kind, $plan, &$reserved, &$result ) {
				foreach ( $plan as [ $data, $current ] ) {
					self::save( $kind, $data, $current, $reserved );
					++$result[ $current ? 'updated' : 'created' ];
				}
			}
		);
		$result['imported'] = true;
		return $result;
	}

	/**
	 * Classeur : feuille de données (en-tête + données éventuelles), feuille d'aide, feuille d'exemples.
	 *
	 * @param bool $with_data true = export des données actuelles ; false = modèle vide.
	 */
	public static function workbook( string $kind, bool $with_data ): string {
		global $wpdb;
		$cols   = self::columns( $kind );
		$keys   = array_keys( $cols );
		$header = array_map( static fn( $c ) => $c[0], array_values( $cols ) );
		$rows   = array( $header );
		if ( $with_data ) {
			$t     = Db::t( $kind );
			$order = self::number_key( $kind );
			$del   = 'material' === $kind ? ' AND deleted_at IS NULL' : '';
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dataset_id = %d{$del} ORDER BY {$order}", Catalog::dataset() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$vals   = self::to_values( $kind, $r );
				$rows[] = array_map( static function ( $k ) use ( $vals, $cols ) {
					$v = $vals[ $k ] ?? '';
					if ( '' !== $v && in_array( $cols[ $k ][2], array( 'int', 'dec' ), true ) && is_numeric( $v ) ) {
						return 'int' === $cols[ $k ][2] ? (int) $v : (float) $v;
					}
					return $v;
				}, $keys );
			}
		}
		$lists = array();
		$req   = array();
		foreach ( $keys as $i => $k ) {
			if ( 'list' === $cols[ $k ][2] ) {
				$lists[ $i ] = array_map( 'strval', array_keys( $cols[ $k ][4] ) );
			} elseif ( 'bool' === $cols[ $k ][2] ) {
				$lists[ $i ] = array( 'OUI', 'NON' );
			}
			if ( $cols[ $k ][1] ) {
				$req[] = $i;
			}
		}
		$help = array( array( 'Colonne', 'Obligatoire', 'Format', 'Description et règles', 'Valeurs autorisées' ) );
		foreach ( $cols as $k => $c ) {
			$allowed = '';
			if ( $c[4] ) {
				$allowed = implode( "\n", array_map( static fn( $code, $label ) => (string) $code === (string) $label ? (string) $code : $code . ' = ' . $label, array_keys( $c[4] ), $c[4] ) );
			} elseif ( 'bool' === $c[2] ) {
				$allowed = 'OUI / NON';
			}
			$help[] = array( $c[0], $c[1] ? 'Oui' : '', array( 'text' => 'Texte', 'int' => 'Nombre entier', 'dec' => 'Nombre', 'bool' => 'OUI / NON', 'list' => 'Liste' )[ $c[2] ], $c[3], $allowed );
		}
		$help[] = array();
		$help[] = array( 'Mode d\'emploi', '', '', '1. Remplissez la feuille « ' . self::label( $kind ) . ' » à partir de la ligne 2 (une ligne par enregistrement). 2. Les colonnes en jaune sont obligatoires. 3. Un numéro existant met l\'enregistrement à jour ; une cellule vide conserve alors la valeur actuelle. 4. Enregistrez au format .xlsx puis importez le fichier dans LogiERP (Données de base). 5. Par défaut, rien n\'est importé si une ligne contient une erreur : la liste des erreurs indique la ligne et la correction à faire.', '' );
		if ( 'material' === $kind ) {
			$help[] = array( 'Ordre d\'import', '', '', 'Importez les fournisseurs avant les articles : la colonne « N° fournisseur principal » doit désigner un fournisseur existant.', '' );
			$help[] = array( 'Règles de stockage', '', '', 'Plus de 2,5 m = profil LONG ; plus de 500 kg = profil HEAVY ; matière dangereuse = profil CHEM (ou BATT pour la classe 9) avec n° ONU ; frais et surgelé = gestion par lot et durée de vie. L\'article doit pouvoir être rangé dans au moins une zone de l\'entrepôt.', '' );
		}
		$sheets = array(
			array( 'name' => self::label( $kind ), 'rows' => $rows, 'header' => true, 'required' => $req, 'lists' => $lists, 'widths' => array_map( static fn( $h ) => max( 12, min( 40, mb_strlen( $h ) + 4 ) ), $header ) ),
			array( 'name' => 'Aide', 'rows' => $help, 'header' => true, 'wrap' => true, 'widths' => array( 30, 12, 14, 70, 45 ) ),
		);
		$examples = self::examples( $kind );
		if ( $examples ) {
			$ex = array( $header );
			foreach ( $examples as $e ) {
				$ex[] = array_map( static fn( $k ) => $e[ $k ] ?? '', $keys );
			}
			$sheets[] = array( 'name' => 'Exemples', 'rows' => $ex, 'header' => true, 'required' => $req, 'widths' => $sheets[0]['widths'] );
		}
		return \LogiERP\Support\Xlsx::build( $sheets );
	}

	/** Exemples conformes aux règles (affichés dans la feuille « Exemples », jamais importés). */
	private static function examples( string $kind ): array {
		if ( 'material' === $kind ) {
			return array(
				array( 'designation' => 'Gants de protection nitrile taille L', 'profile_code' => 'STD', 'type' => 'MRCH', 'group' => 'Équipement de protection', 'base_uom' => 'CRT', 'net_weight' => 1.2, 'gross_weight' => 1.3, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 120, 'std_price' => 18.5, 'sale_price' => 27.9, 'abc' => 'B', 'batch_managed' => 'NON', 'min_stock' => 20, 'reorder_point' => 40, 'max_stock' => 120, 'lot_size' => 50, 'supplier_no' => 'F20001', 'ean' => '7610000000011', 'stackable' => 'OUI', 'units_per_carton' => 10, 'cartons_per_pallet' => 60, 'status' => 'ACTIF' ),
				array( 'designation' => 'Yogourt nature 180 g', 'profile_code' => 'COLD', 'type' => 'MRCH', 'base_uom' => 'PCE', 'net_weight' => 0.18, 'gross_weight' => 0.2, 'length_mm' => 75, 'width_mm' => 75, 'height_mm' => 70, 'std_price' => 0.65, 'abc' => 'A', 'batch_managed' => 'OUI', 'shelf_life_days' => 28, 'min_stock' => 200, 'reorder_point' => 400, 'max_stock' => 1500, 'lot_size' => 240, 'units_per_carton' => 24, 'cartons_per_pallet' => 80 ),
				array( 'designation' => 'Diluant universel 5 l', 'profile_code' => 'CHEM', 'base_uom' => 'PCE', 'net_weight' => 4.4, 'gross_weight' => 4.9, 'length_mm' => 290, 'width_mm' => 190, 'height_mm' => 150, 'std_price' => 24, 'batch_managed' => 'OUI', 'shelf_life_days' => 730, 'adr_class' => '3', 'un_number' => '1263', 'packing_group' => 'II', 'hazard_text' => 'Matières apparentées aux peintures', 'units_per_carton' => 4, 'cartons_per_pallet' => 30 ),
			);
		}
		if ( 'supplier' === $kind ) {
			return array(
				array( 'name' => 'Romandie Outillage SA', 'specialty' => 'Quincaillerie et outillage', 'street' => 'Chemin du Viaduc 8', 'zip' => '1008', 'city' => 'Prilly', 'contact' => 'Martin Rochat', 'email' => 'commandes@romandie-outillage.example', 'phone' => '+41 21 555 12 34', 'payment_terms' => '30 jours net', 'incoterm' => 'DAP', 'lead_time_days' => 3, 'reliability' => 96, 'is_problematic' => 'NON', 'active' => 'OUI', 'country' => 'CH' ),
				array( 'name' => 'Markgräfler Verpackung GmbH', 'specialty' => 'Emballages et cartonnages', 'street' => 'Industriestraße 14', 'zip' => '79539', 'city' => 'Lörrach', 'country' => 'DE', 'contact' => 'Lena Hoffmann', 'email' => 'auftrag@markgraefler-verpackung.example', 'phone' => '+49 7621 555 12 34', 'payment_terms' => '30 jours net', 'incoterm' => 'FCA', 'lead_time_days' => 6, 'reliability' => 94, 'is_problematic' => 'NON', 'active' => 'OUI' ),
			);
		}
		return array(
			array( 'name' => 'Boulangerie du Lac Sàrl', 'type' => 'Restauration / hôtellerie', 'street' => 'Quai de la Navigation 4', 'zip' => '1400', 'city' => 'Yverdon-les-Bains', 'email' => 'logistique@boulangerie-du-lac.example', 'phone' => '+41 24 555 40 10', 'priority' => 'A', 'payment_terms' => '10 jours net', 'carrier_code' => 'FRIGO', 'delivery_window' => '06:00–09:00', 'packing_req' => 'Chaîne du froid', 'credit_limit' => 15000, 'blocked' => 'NON', 'active' => 'OUI' ),
		);
	}
}
