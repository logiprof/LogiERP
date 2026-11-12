<?php
declare(strict_types=1);

namespace LogiERP\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entreprise fictive dans laquelle travaille l'apprenant·e.
 * Présentée au début de chaque session ; modifiable par l'enseignant (page « Entreprise »).
 * Les champs « liste » sont saisis une ligne par élément, colonnes séparées par « | ».
 */
final class Company {

	public const OPTION = 'logierp_company';

	/** Champs texte : clé => [libellé, longueur maximale, multiligne]. */
	public const TEXTS = array(
		'name'      => array( 'Nom de l\'entreprise', 80, false ),
		'tagline'   => array( 'Slogan', 120, false ),
		'address'   => array( 'Adresse du centre de distribution (ligne 1 : rue, ligne 2 : NPA localité)', 160, true ),
		'phone'     => array( 'Téléphone', 40, false ),
		'intro'     => array( 'Qui sommes-nous ?', 1200, true ),
		'context'   => array( 'Situation actuelle et enjeux', 1500, true ),
		'role'      => array( 'Votre rôle d\'apprenti·e', 1200, true ),
	);

	/** Champs liste : clé => [libellé, colonnes, nombre maximal de lignes]. */
	public const LISTS = array(
		'figures'  => array( 'Chiffres clés (libellé | valeur)', array( 'label', 'value' ), 12 ),
		'sites'    => array( 'Sites en Suisse (nom | canton | surface | collaborateurs | activité)', array( 'name', 'canton', 'surface', 'staff', 'activity' ), 8 ),
		'dc'       => array( 'Le centre de distribution en détail (libellé | valeur)', array( 'label', 'value' ), 14 ),
		'org'      => array( 'Organisation du site (service | responsabilités)', array( 'label', 'value' ), 10 ),
		'timeline' => array( 'Historique (année | événement)', array( 'year', 'text' ), 10 ),
		'rules'    => array( 'Règles de la maison (une par ligne)', array( 'text' ), 10 ),
	);

	public static function defaults(): array {
		return array(
			'name'     => 'Lémantis Logistique SA',
			'tagline'  => 'Votre marchandise, au bon endroit, au bon moment.',
			'address'  => "Route de l'Industrie 21\n1123 Aclens",
			'phone'    => '+41 21 555 40 00',
			'intro'    => 'Lémantis Logistique SA est une entreprise suisse de distribution et de logistique contractuelle. Elle achète des marchandises auprès de 130 fournisseurs en Suisse et en Europe, les stocke, les conditionne et les livre à une centaine de clients professionnels dans toute la Suisse : commerces, artisans, industrie, santé et restauration. Fondée en 1987 à Morges comme entreprise de transport, elle est restée une entreprise familiale indépendante.',
			'context'  => "Le volume de commandes augmente d'environ 6 % par an, surtout pour les livraisons urgentes en 24 heures et les produits sous température dirigée. Depuis 2021, toute l'activité est pilotée par l'ERP LogiERP et des terminaux de scan : chaque mouvement de marchandise doit être saisi au moment où il a lieu. La direction mise sur trois priorités : la fiabilité des stocks (objectif 99,5 % à l'inventaire), la sécurité au travail et la traçabilité complète (lots, dates limites, matières dangereuses).",
			'role'     => "Vous êtes apprenti·e logisticien·ne CFC au centre de distribution d'Aclens. Votre formateur·trice vous confie chaque jour des missions dans LogiERP : réceptionner et contrôler les livraisons, ranger, préparer les commandes, expédier, faire l'inventaire ou approvisionner l'atelier. Travaillez comme dans la réalité : contrôlez les documents, respectez les règles de stockage et signalez toute anomalie.",
			'figures'  => array(
				array( 'Collaborateurs', '385, dont 24 apprenti·e·s' ),
				array( 'Chiffre d\'affaires', 'CHF 142 millions' ),
				array( 'Commandes clients par an', '96 000' ),
				array( 'Lignes préparées par an', '1,15 million' ),
				array( 'Réceptions fournisseurs par an', '14 500' ),
				array( 'Palettes expédiées par an', '61 000' ),
				array( 'Articles actifs', '3 000' ),
				array( 'Fournisseurs', '130 (100 en Suisse, 30 en Europe)' ),
				array( 'Clients professionnels', '100' ),
				array( 'Flotte', '38 camions (9 frigorifiques, 4 ADR) et 12 camionnettes' ),
				array( 'Surfaces logistiques', '51 800 m²' ),
			),
			'sites'    => array(
				array( 'Centre de distribution d\'Aclens', 'VD', '19 800 m²', '145', 'Stock central, préparation, expédition, atelier de conditionnement. C\'est ici que vous travaillez.' ),
				array( 'Siège de Morges', 'VD', '1 200 m² de bureaux', '30', 'Direction, achats, service clients, finances, formation.' ),
				array( 'Plateforme d\'Oftringen', 'AG', '14 000 m²', '96', 'Distribution en Suisse alémanique, transbordement (cross-docking).' ),
				array( 'Entrepôt frigorifique de Conthey', 'VS', '7 500 m²', '45', 'Produits frais (+2 à +8 °C) et surgelés (-25 à -18 °C).' ),
				array( 'Plateforme de Cadenazzo', 'TI', '6 000 m²', '31', 'Distribution au Tessin, transit avec l\'Italie via Chiasso.' ),
				array( 'Plateforme urbaine de Satigny', 'GE', '4 500 m²', '38', 'Livraisons urbaines à Genève en véhicules électriques.' ),
			),
			'dc'       => array(
				array( 'Bâtiment', '180 m × 110 m, hauteur utile 12,5 m' ),
				array( 'Emplacements', '5 200, dont 2 160 places palettes en stockage chaotique' ),
				array( 'Zones de température', 'Ambiant, tempéré +15/+25 °C, frais +2/+8 °C, surgelé -25/-18 °C' ),
				array( 'Zones spéciales', 'Local chimie et ADR, magasin sous clé, charges longues et lourdes' ),
				array( 'Quais', '18 portes de quai, dont 2 frigorifiques, et 1 rampe latérale' ),
				array( 'Engins', '14 chariots élévateurs, 6 chariots à mât rétractable, 2 préparateurs verticaux, 20 transpalettes électriques' ),
				array( 'Horaires', '2 équipes : 05 h 30 – 14 h 00 et 13 h 30 – 22 h 00, du lundi au vendredi' ),
				array( 'Départs camions', 'Tournées à 06 h 00, 10 h 00 et 14 h 00 ; express jusqu\'à 16 h 30' ),
				array( 'Certifications', 'ISO 9001, ISO 14001, BPD pour les produits pharmaceutiques' ),
			),
			'org'      => array(
				array( 'Direction du site', 'Objectifs, budget, sécurité et qualité' ),
				array( 'Réception et contrôle qualité', 'Déchargement, contrôle des documents, entrées de marchandises, réserves' ),
				array( 'Entreposage et inventaire', 'Rangement, réapprovisionnement des emplacements fixes, inventaires tournants' ),
				array( 'Préparation de commandes', 'Prélèvement, contrôle, emballage' ),
				array( 'Expédition et transport', 'Chargement, documents de transport, tournées' ),
				array( 'Atelier de conditionnement', 'Kits, assemblage, ordres de fabrication' ),
				array( 'Service clients et ordonnancement', 'Commandes, priorités, retours' ),
				array( 'Formation', 'Suivi des apprenti·e·s logisticien·ne·s CFC et AFP' ),
			),
			'timeline' => array(
				array( '1987', 'Création à Morges : 3 camions et un dépôt de 800 m².' ),
				array( '1998', 'Ouverture du centre de distribution d\'Aclens.' ),
				array( '2009', 'Reprise de l\'entrepôt frigorifique de Conthey.' ),
				array( '2016', 'Ouverture de la plateforme d\'Oftringen.' ),
				array( '2021', 'Mise en service de l\'ERP LogiERP et des terminaux de scan.' ),
				array( '2024', 'Plateforme urbaine de Satigny et premiers camions électriques.' ),
			),
			'rules'    => array(
				array( 'Chaussures de sécurité et gilet haute visibilité obligatoires dans l\'entrepôt.' ),
				array( 'Piétons dans les passages marqués ; un chariot a toujours la priorité.' ),
				array( 'Engins de manutention uniquement avec le permis cariste et l\'autorisation interne.' ),
				array( 'Chaque mouvement est saisi dans LogiERP au moment où il a lieu.' ),
				array( 'Toute avarie ou tout écart est signalé et noté sur les documents de transport.' ),
				array( 'Matières dangereuses : uniquement dans le local ADR, avec les EPI prévus.' ),
			),
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$def   = self::defaults();
		if ( ! is_array( $saved ) ) {
			return $def;
		}
		foreach ( $def as $k => $v ) {
			if ( isset( $saved[ $k ] ) && gettype( $saved[ $k ] ) === gettype( $v ) ) {
				$def[ $k ] = $saved[ $k ];
			}
		}
		return $def;
	}

	public static function name(): string {
		return (string) self::get()['name'];
	}

	/** Adresse de livraison : [nom, rue, NPA localité, pays]. */
	public static function delivery_address(): array {
		$c     = self::get();
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $c['address'] ) ) ) );
		return array( (string) $c['name'], $lines[0] ?? '', $lines[1] ?? '', 'CH' );
	}

	/** Valide et enregistre les champs envoyés (tableau brut du formulaire). */
	public static function save( array $raw ): void {
		$out = self::defaults();
		foreach ( self::TEXTS as $k => $def ) {
			$v = (string) ( $raw[ $k ] ?? '' );
			$v = $def[2] ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			$v = trim( mb_substr( $v, 0, $def[1] ) );
			if ( '' !== $v ) {
				$out[ $k ] = $v;
			}
		}
		foreach ( self::LISTS as $k => $def ) {
			$rows = array();
			foreach ( preg_split( '/\R/', (string) ( $raw[ $k ] ?? '' ) ) as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( '' === $line ) {
					continue;
				}
				$cols = array_map( 'trim', explode( '|', $line ) );
				$cols = array_slice( array_pad( $cols, count( $def[1] ), '' ), 0, count( $def[1] ) );
				$rows[] = array_map( static fn( $c ) => mb_substr( $c, 0, 200 ), $cols );
				if ( count( $rows ) >= $def[2] ) {
					break;
				}
			}
			if ( $rows ) {
				$out[ $k ] = $rows;
			}
		}
		update_option( self::OPTION, $out, false );
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/** Texte d'une liste pour le formulaire (une ligne par élément). */
	public static function list_text( string $key ): string {
		return implode( "\n", array_map( static fn( $r ) => implode( ' | ', $r ), (array) ( self::get()[ $key ] ?? array() ) ) );
	}

	/** Données transmises à l'application de l'apprenant. */
	public static function for_app(): array {
		$c   = self::get();
		$map = static function ( array $rows, array $keys ): array {
			return array_map( static fn( $r ) => array_combine( $keys, array_slice( array_pad( $r, count( $keys ), '' ), 0, count( $keys ) ) ), $rows );
		};
		$out = array(
			'name'    => $c['name'],
			'tagline' => $c['tagline'],
			'address' => $c['address'],
			'phone'   => $c['phone'],
			'intro'   => $c['intro'],
			'context' => $c['context'],
			'role'    => $c['role'],
		);
		foreach ( self::LISTS as $k => $def ) {
			$out[ $k ] = $map( (array) $c[ $k ], $def[1] );
		}
		return $out;
	}

	/** Logo vectoriel (aucune donnée externe) : emblème + nom. */
	public static function logo_svg( int $height = 48 ): string {
		$name  = self::name();
		$parts = preg_split( '/\s+/', $name );
		$first = (string) ( $parts[0] ?? $name );
		$rest  = trim( mb_substr( $name, mb_strlen( $first ) ) );
		$w     = (int) round( $height * 5.2 );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 260 50" width="' . $w . '" height="' . $height . '" role="img" aria-label="' . esc_attr( $name ) . '">'
			. '<rect x="1" y="3" width="44" height="44" rx="10" fill="#0e3b5c"/>'
			. '<path d="M13 13v24h18" fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M9 31c5-4 9-4 14 0s9 4 14 0" fill="none" stroke="#3cc3b4" stroke-width="3.2" stroke-linecap="round"/>'
			. '<text x="54" y="27" font-family="Poppins, Arial, sans-serif" font-size="21" font-weight="700" fill="currentColor">' . esc_html( $first ) . '</text>'
			. '<text x="55" y="44" font-family="Poppins, Arial, sans-serif" font-size="11.5" font-weight="500" letter-spacing="1.2" fill="#2a9d8f">' . esc_html( mb_strtoupper( $rest ) ) . '</text>'
			. '</svg>';
	}
}
