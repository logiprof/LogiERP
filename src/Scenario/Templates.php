<?php
declare(strict_types=1);

namespace LogiERP\Scenario;

use LogiERP\Core\Db;
use LogiERP\Seeder\StockSeeder;
use LogiERP\Domain\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les scénarios modèles livrés, construits à partir du jeu de base réellement généré.
 */
final class Templates {

	/** Article du jeu de base selon des critères ; $offset choisit un autre article à critères égaux. */
	private static function mat( string $where, array $args = array(), int $offset = 0, string $origin = 'CH' ): ?array {
		global $wpdb;
		$m   = Db::t( 'material' );
		$s   = Db::t( 'stock' );
		$su  = Db::t( 'supplier' );
		// Fournisseur principal suisse par défaut ; « INT » = fournisseur étranger (arrivage avec CMR).
		$geo = 'INT' === $origin ? 'NOT IN' : 'IN';
		// Emballages et produits en contenant consigné : réservés aux scénarios qui les demandent explicitement.
		if ( ! str_contains( $where, 'deposit_material_id' ) ) {
			$where = "m.type <> 'VERP' AND m.deposit_material_id = 0 AND " . $where;
		}
		$sql = "SELECT m.*, COALESCE(st.q,0) base_free FROM {$m} m JOIN {$su} su ON su.id = m.main_supplier_id AND su.country {$geo} ('CH','LI') LEFT JOIN (SELECT material_id, SUM(qty) q FROM {$s} WHERE session_id = 0 AND status = 'LIBRE' AND (bbd IS NULL OR bbd >= %s) GROUP BY material_id) st ON st.material_id = m.id WHERE m.dataset_id = %d AND m.main_supplier_id > 0 AND {$where} ORDER BY m.material_no LIMIT 1 OFFSET %d";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( \LogiERP\Seeder\StockSeeder::base_date(), Catalog::dataset() ), $args, array( $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	private static function customer( string $where = '1=1', int $offset = 0 ): int {
		global $wpdb;
		$t = Db::t( 'customer' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dataset_id = %d AND blocked = 0 AND {$where} ORDER BY id LIMIT 1 OFFSET %d", Catalog::dataset(), $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Premier emplacement libre, non bloqué et non attribué d'un magasin (dans le jeu de base). */
	private static function free_bin( string $location_code, int $offset = 0 ): ?array {
		global $wpdb;
		$loc = Catalog::location_by_code( $location_code );
		$b   = Db::t( 'bin' );
		$s   = Db::t( 'stock' );
		$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT b.id FROM {$b} b WHERE b.storage_location_id = %d AND b.blocked_in = 0 AND b.blocked_out = 0 AND b.fixed_material_id = 0 AND NOT EXISTS (SELECT 1 FROM {$s} s WHERE s.bin_id = b.id AND s.session_id = 0) ORDER BY b.pick_sequence LIMIT 1 OFFSET %d", (int) $loc['id'], $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? Catalog::bin( $id ) : null;
	}

	private static function fixed_bin( int $material_id, string $zone_type ): ?array {
		foreach ( Catalog::fixed_bins_of( $material_id ) as $bid ) {
			$b = Catalog::bin( $bid );
			$l = Catalog::location( (int) $b['storage_location_id'] );
			if ( $l['zone_type'] === $zone_type ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * Crée un scénario complet.
	 * $s : title, objectives, instructions, level, duration, modules, mode, hv_key, override, initial, docs[], events[], tasks[], checks[], initial_stock[]
	 */
	public static function make( array $s, string $key = '', int $owner = 0, string $status = 'PUBLISHED' ): int {
		$sid = Db::insert(
			'scenario',
			array(
				'owner_id'                => $owner,
				'template_key'            => $key,
				'title'                   => $s['title'],
				'objectives'              => $s['objectives'],
				'instructions'            => $s['instructions'] ?? '',
				'level'                   => $s['level'],
				'duration_min'            => $s['duration'],
				'modules'                 => $s['modules'],
				'location_mode'           => $s['mode'] ?? 'DETAILED',
				'guided'                  => $s['guided'] ?? 1,
				'allow_strategy_override' => $s['override'] ?? 0,
				'hv_key'                  => $s['hv_key'] ?? 0,
				'sim_date'                => \LogiERP\Seeder\StockSeeder::base_date(),
				'initial_stock_mode'      => $s['initial'] ?? 'BASE',
				'expiry_mode'             => $s['expiry'] ?? 'AUTO',
				'dataset_id'              => Catalog::dataset(),
				'status'                  => $status,
				'shared'                  => 1,
				'created_at'              => Db::now(),
				'updated_at'              => Db::now(),
			)
		);
		foreach ( $s['initial_stock'] ?? array() as $r ) {
			Db::insert( 'scenario_initial_stock', array( 'scenario_id' => $sid ) + $r );
		}
		foreach ( $s['docs'] ?? array() as $d ) {
			Db::insert(
				'scenario_document',
				array(
					'scenario_id' => $sid,
					'ref'         => $d['ref'],
					'doc_type'    => $d['type'],
					'partner_id'  => (int) ( $d['partner_id'] ?? 0 ),
					'day_offset'  => (int) ( $d['day_offset'] ?? 0 ),
					'priority'    => $d['priority'] ?? 'B',
					'note'        => $d['note'] ?? '',
					'items'       => wp_json_encode( $d['items'] ),
				)
			);
		}
		foreach ( $s['events'] ?? array() as $e ) {
			Db::insert( 'scenario_event', array( 'scenario_id' => $sid, 'type' => $e['type'], 'trigger_type' => $e['trigger'], 'trigger_value' => (int) ( $e['value'] ?? 0 ), 'params' => wp_json_encode( $e['params'] ?? array() ), 'message' => $e['message'] ?? '' ) );
		}
		foreach ( $s['tasks'] ?? array() as $i => $t ) {
			Db::insert( 'scenario_task', array( 'scenario_id' => $sid, 'seq' => $i + 1, 'title' => $t[0], 'body' => $t[1], 'transaction_code' => $t[2] ?? '' ) );
		}
		foreach ( $s['checks'] ?? array() as $c ) {
			Db::insert( 'scenario_check', array( 'scenario_id' => $sid, 'type' => $c[0], 'label' => $c[1], 'params' => wp_json_encode( $c[2] ), 'points' => $c[3] ?? 1 ) );
		}
		return $sid;
	}

	public static function install_all(): int {
		global $wpdb;
		$t = Db::t( 'scenario' );
		// Chaque modèle n'est installé qu'une fois : une mise à jour n'ajoute que les nouveaux modèles.
		$have = array_flip( (array) $wpdb->get_col( "SELECT template_key FROM {$t} WHERE template_key <> ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Catalog::reset_cache();
		$n = 0;
		foreach ( self::definitions() as $key => $def ) {
			if ( $def && ! isset( $have[ $key ] ) ) {
				self::make( $def, $key );
				++$n;
			}
		}
		return $n;
	}

	/** @return array<string,array|null> */
	private static function definitions(): array {
		$defs  = array();
		$fresh = "m.profile_code = 'STD' AND m.type IN ('MRCH','PF','SF') AND m.batch_managed = 0 AND m.gross_weight BETWEEN 0.5 AND 15";

		// 1. Première réception en mode simple.
		$a = self::mat( $fresh );
		$b = self::mat( $fresh . ' AND m.main_supplier_id = %d AND m.id <> %d', array( (int) $a['main_supplier_id'], (int) $a['id'] ) ) ?: self::mat( $fresh, array(), 1 );
		$sameSupplier = (int) $b['main_supplier_id'] === (int) $a['main_supplier_id'];
		$items        = array( array( 'material_id' => (int) $a['id'], 'qty' => 24 ) );
		if ( $sameSupplier ) {
			$items[] = array( 'material_id' => (int) $b['id'], 'qty' => 12 );
		}
		$checks = array(
			array( 'doc_status', 'La commande est entièrement reçue', array( 'ref' => 'PO1', 'status' => array( 'RECUE' ) ), 2 ),
			array( 'stock_delta', sprintf( '%s : +24 en stock libre au magasin 110', $a['material_no'] ), array( 'material_id' => (int) $a['id'], 'location_code' => '110', 'status' => 'LIBRE', 'delta' => 24 ), 2 ),
			array( 'max_errors', 'Au maximum 3 erreurs de saisie', array( 'max' => 3 ), 1 ),
		);
		if ( $sameSupplier ) {
			$checks[] = array( 'stock_delta', sprintf( '%s : +12 en stock libre au magasin 110', $b['material_no'] ), array( 'material_id' => (int) $b['id'], 'location_code' => '110', 'status' => 'LIBRE', 'delta' => 12 ), 2 );
		}
		$defs['gr_simple'] = array(
			'title'        => 'Première réception en mode simple',
			'objectives'   => "Contrôler un bon de livraison par rapport à la commande.\nSaisir une entrée de marchandises (mouvement 101) dans le bon magasin.",
			'instructions' => 'Un fournisseur livre la commande ouverte. Contrôlez le bon de livraison, puis saisissez l\'entrée de marchandises dans le magasin 110 (stock général). En mode simple, le stock est géré au niveau du magasin.',
			'level'        => 'debutant',
			'duration'     => 20,
			'modules'      => 'MD,PUR,GR,INV',
			'mode'         => 'SIMPLE',
			'docs'         => array( array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $a['main_supplier_id'], 'items' => $items ) ),
			'tasks'        => array(
				array( 'Consulter la commande', 'Ouvrez la commande d\'achat livrée et repérez le fournisseur, les articles et les quantités commandées.', 'ME23N' ),
				array( 'Contrôler le bon de livraison', 'Dans l\'écran de réception, comparez le bon de livraison du fournisseur avec la commande.', 'MIGO' ),
				array( 'Saisir l\'entrée de marchandises', 'Saisissez les quantités reçues en stock libre, magasin 110, puis enregistrez.', 'MIGO' ),
				array( 'Vérifier le stock', 'Contrôlez le nouveau stock de chaque article.', 'MMBE' ),
			),
			'checks'       => $checks,
		);

		// 2. Réception avec écarts et réserves.
		$c1 = self::mat( $fresh, array(), 5 );
		$c2 = self::mat( $fresh . ' AND m.main_supplier_id = %d AND m.id <> %d', array( (int) $c1['main_supplier_id'], (int) $c1['id'] ) );
		if ( $c2 ) {
			$defs['gr_variances'] = array(
				'title'        => 'Réception avec écarts et réserves',
				'objectives'   => "Détecter un manquant et une avarie au déchargement.\nNe réceptionner que ce qui est réellement livré et conforme.\nFormuler une réserve et retourner la marchandise endommagée.",
				'instructions' => 'Le camion est au quai. Comparez attentivement le bon de livraison avec la commande : ne saisissez que les quantités réellement livrées. Les pièces endommagées ne doivent pas entrer en stock libre (réserve + stock bloqué ou retour fournisseur 122). Rangez ensuite la marchandise conforme.',
				'level'        => 'intermediaire',
				'duration'     => 35,
				'modules'      => 'MD,PUR,GR,PUT,INV',
				'docs'         => array(
					array(
						'ref'        => 'PO1',
						'type'       => 'PO',
						'partner_id' => (int) $c1['main_supplier_id'],
						'items'      => array(
							array( 'material_id' => (int) $c1['id'], 'qty' => 40, 'delivered_qty' => 36 ),
							array( 'material_id' => (int) $c2['id'], 'qty' => 10, 'delivered_qty' => 10, 'damage' => 'Carton écrasé : 2 pièces cassées' ),
						),
					),
				),
				'tasks'        => array(
					array( 'Comparer livraison et commande', 'Repérez le manquant et l\'avarie sur le bon de livraison.', 'MIGO' ),
					array( 'Réceptionner la quantité réelle', 'Ligne 10 : saisissez uniquement la quantité livrée. Ligne 20 : 8 pièces en stock libre, et les 2 pièces cassées en stock bloqué avec un motif (ou en retour fournisseur).', 'MIGO' ),
					array( 'Ranger la marchandise', 'Confirmez l\'ordre de transfert d\'entreposage proposé.', 'LT12' ),
				),
				'checks'       => array(
					array( 'po_received_qty', sprintf( 'Ligne 10 (%s) : 36 réceptionnés, pas 40', $c1['material_no'] ), array( 'ref' => 'PO1', 'material_id' => (int) $c1['id'], 'qty' => 36 ), 3 ),
					array( 'reserve_recorded', 'Réserve, blocage ou retour saisi pour l\'avarie', array( 'ref' => 'PO1' ), 3 ),
					array( 'stock_delta', sprintf( '%s : seulement 8 pièces en stock libre', $c2['material_no'] ), array( 'material_id' => (int) $c2['id'], 'status' => 'LIBRE', 'delta' => 8 ), 2 ),
					array( 'nothing_in_zone', 'Plus rien en stock libre au quai de réception', array( 'location_code' => '100' ), 1 ),
				),
			);
		}

		// 2 bis. Arrivage international avec lettre de voiture CMR.
		$i1 = self::mat( $fresh, array(), 0, 'INT' );
		$i2 = $i1 ? self::mat( $fresh . ' AND m.main_supplier_id = %d AND m.id <> %d', array( (int) $i1['main_supplier_id'], (int) $i1['id'] ), 0, 'INT' ) : null;
		if ( $i1 && $i2 ) {
			$isup = \LogiERP\Domain\Purchasing::supplier( (int) $i1['main_supplier_id'] );
			$q1   = max( 1, (int) $i1['units_per_carton'] ) * 6;
			$q2   = max( 6, max( 1, (int) $i2['units_per_carton'] ) * 4 );
			$defs['import_cmr'] = array(
				'title'        => 'Arrivage international avec lettre de voiture CMR',
				'objectives'   => "Contrôler une lettre de voiture CMR : expéditeur, nombre de colis, poids, marchandise.\nConstater une avarie au déchargement et la noter en réserve sur la CMR (case 24).\nRéceptionner la marchandise conforme, bloquer la marchandise endommagée, puis ranger.",
				'instructions' => sprintf( 'Un camion arrive de l\'étranger avec la commande passée chez %s (%s, %s). Le chauffeur vous remet la lettre de voiture CMR. Contrôlez-la avec la commande et le bon de livraison. Au déchargement, un carton de la position 20 est enfoncé : notez une réserve précise sur la CMR et signez la case 24. Saisissez ensuite l\'entrée de marchandises : les pièces cassées vont en stock bloqué avec un motif. Rangez enfin la marchandise conforme.', $isup['name'], $isup['city'], \LogiERP\Domain\Cmr::country_name( (string) $isup['country'] ) ),
				'level'        => 'intermediaire',
				'duration'     => 35,
				'modules'      => 'MD,PUR,GR,PUT,INV',
				'docs'         => array(
					array(
						'ref'        => 'PO1',
						'type'       => 'PO',
						'partner_id' => (int) $i1['main_supplier_id'],
						'day_offset' => -8,
						'items'      => array(
							array( 'material_id' => (int) $i1['id'], 'qty' => $q1 ),
							array( 'material_id' => (int) $i2['id'], 'qty' => $q2, 'damage' => 'Carton enfoncé : 2 pièces cassées' ),
						),
					),
				),
				'tasks'        => array(
					array( 'Contrôler la lettre de voiture CMR', 'Ouvrez la CMR de la commande : comparez l\'expéditeur, le nombre de colis, le poids et la marchandise avec la commande et le bon de livraison.', 'ZCMR' ),
					array( 'Signer la CMR avec réserves', 'En case 24, notez la position, la quantité et le dommage constaté, puis signez.', 'ZCMR' ),
					array( 'Saisir l\'entrée de marchandises', 'Position 10 : tout en stock libre. Position 20 : les pièces intactes en stock libre, les 2 pièces cassées en stock bloqué avec un motif.', 'MIGO' ),
					array( 'Ranger la marchandise', 'Confirmez les ordres de transfert d\'entreposage.', 'LT12' ),
				),
				'checks'       => array(
					array( 'cmr_signed', 'CMR signée en case 24 avec une réserve pour l\'avarie', array( 'ref' => 'PO1', 'reserves' => true ), 3 ),
					array( 'doc_status', 'La commande est entièrement réceptionnée', array( 'ref' => 'PO1', 'status' => array( 'RECUE' ) ), 2 ),
					array( 'reserve_recorded', 'Pièces cassées en stock bloqué (ou retournées) avec un motif', array( 'ref' => 'PO1' ), 2 ),
					array( 'stock_delta', sprintf( '%s : seulement %d pièces en stock libre', $i2['material_no'], $q2 - 2 ), array( 'material_id' => (int) $i2['id'], 'status' => 'LIBRE', 'delta' => $q2 - 2 ), 2 ),
					array( 'nothing_in_zone', 'Plus rien en stock libre au quai de réception', array( 'location_code' => '100' ), 1 ),
					array( 'max_errors', 'Au maximum 4 erreurs de saisie', array( 'max' => 4 ), 1 ),
				),
			);
		}

		// 2 ter. Réception au terminal mobile (MDE) : scan des étiquettes SSCC et photo de l'avarie.
		$mp = "m.profile_code = 'PAL' AND m.batch_managed = 0 AND m.ean <> '' AND m.cartons_per_pallet BETWEEN 4 AND 60 AND m.units_per_carton BETWEEN 1 AND 50";
		$t1 = self::mat( $mp );
		$t2 = $t1 ? self::mat( $mp . ' AND m.main_supplier_id = %d AND m.id <> %d', array( (int) $t1['main_supplier_id'], (int) $t1['id'] ) ) : null;
		if ( $t1 && $t2 ) {
			$tsup  = \LogiERP\Domain\Purchasing::supplier( (int) $t1['main_supplier_id'] );
			$pal1  = (int) $t1['cartons_per_pallet'] * max( 1, (int) $t1['units_per_carton'] );
			$pal2  = (int) $t2['cartons_per_pallet'] * max( 1, (int) $t2['units_per_carton'] );
			$crush = 2 * max( 1, (int) $t2['units_per_carton'] );
			$defs['mde_reception'] = array(
				'title'        => 'Réception au terminal mobile : SSCC et photo de l\'avarie',
				'objectives'   => "Réceptionner une livraison palettisée avec le terminal mobile (smartphone) en scannant les étiquettes logistiques SSCC.\nConstater une avarie, la documenter par une photo et bloquer la marchandise endommagée.\nRanger les palettes en scannant l'emplacement puis l'article (EAN).",
				'instructions' => sprintf( 'Un camion de %s (%s) livre 3 palettes. Ouvrez le terminal mobile sur votre smartphone (portail › Terminal mobile). Affichez les étiquettes palettes sur le poste (HU03) ou imprimez-les, puis scannez chaque code SSCC. La palette de la position 20 a été percutée par un chariot : %d %s sont écrasés. Déclarez la non-conformité, prenez une photo, mettez les pièces écrasées en stock bloqué avec un motif précis. Rangez ensuite les palettes conformes avec le terminal.', $tsup['name'], $tsup['city'], 2, 'cartons' ),
				'level'        => 'intermediaire',
				'duration'     => 35,
				'modules'      => 'MD,PUR,GR,PUT,INV',
				'docs'         => array(
					array(
						'ref'        => 'PO1',
						'type'       => 'PO',
						'partner_id' => (int) $t1['main_supplier_id'],
						'day_offset' => -5,
						'items'      => array(
							array( 'material_id' => (int) $t1['id'], 'qty' => 2 * $pal1 ),
							array( 'material_id' => (int) $t2['id'], 'qty' => $pal2, 'damage' => sprintf( 'Palette percutée par chariot : 2 cartons écrasés (%d pcs)', $crush ) ),
						),
					),
				),
				'tasks'        => array(
					array( 'Ouvrir le terminal mobile', 'Sur votre smartphone, ouvrez le portail LogiERP puis « Terminal mobile », et choisissez cette mission. Sur le poste, HU03 affiche les étiquettes à scanner.', 'HU03' ),
					array( 'Scanner les 3 palettes', 'Réception › scannez le code SSCC de chaque étiquette. Contrôlez l\'article, la quantité et l\'état de chaque palette.', 'MIGO' ),
					array( 'Traiter l\'avarie', sprintf( 'Position 20 : déclarez « Non conforme », %d pièces en stock bloqué avec le motif, et prenez une photo de la palette endommagée.', $crush ), 'MIGO' ),
					array( 'Ranger avec le terminal', 'Rangement › pour chaque ligne, scannez l\'emplacement puis l\'article (code EAN), confirmez la quantité et validez l\'ordre de transfert.', 'LT12' ),
				),
				'checks'       => array(
					array( 'doc_status', 'La commande est entièrement réceptionnée', array( 'ref' => 'PO1', 'status' => array( 'RECUE' ) ), 2 ),
					array( 'reserve_recorded', 'Pièces écrasées en stock bloqué avec un motif', array( 'ref' => 'PO1' ), 2 ),
					array( 'stock_delta', sprintf( '%s : %d pièces en stock libre (sans les pièces écrasées)', $t2['material_no'], $pal2 - $crush ), array( 'material_id' => (int) $t2['id'], 'status' => 'LIBRE', 'delta' => $pal2 - $crush ), 2 ),
					array( 'photo_documented', 'Avarie documentée par une photo (position 20)', array( 'ref' => 'PO1' ), 2 ),
					array( 'nothing_in_zone', 'Plus rien en stock libre au quai de réception', array( 'location_code' => '100' ), 1 ),
					array( 'max_errors', 'Au maximum 5 erreurs de saisie', array( 'max' => 5 ), 1 ),
				),
			);
		}

		// 3. Entreposage chaotique.
		$p1 = self::mat( "m.profile_code = 'PAL'" );
		$p2 = self::mat( "m.profile_code = 'PAL' AND m.main_supplier_id = %d AND m.id <> %d", array( (int) $p1['main_supplier_id'], (int) $p1['id'] ) ) ?: null;
		$lg = self::mat( "m.profile_code = 'LONG'" );
		$pitems = array( array( 'material_id' => (int) $p1['id'], 'qty' => max( 1, (int) $p1['cartons_per_pallet'] ) * max( 1, (int) $p1['units_per_carton'] ) ) );
		if ( $p2 ) {
			$pitems[] = array( 'material_id' => (int) $p2['id'], 'qty' => max( 1, (int) $p2['cartons_per_pallet'] ) * max( 1, (int) $p2['units_per_carton'] ) );
		}
		$defs['putaway_chaotic'] = array(
			'title'        => 'Entreposage chaotique en palettier',
			'objectives'   => "Comprendre le stockage chaotique : l'emplacement est tiré au hasard parmi les emplacements compatibles.\nConfirmer un ordre de transfert avec scan de l'emplacement et de l'article.\nVérifier les critères : profil, charge, volume, longueur.",
			'instructions' => 'Deux livraisons arrivent : des palettes complètes et des profilés longs. Réceptionnez-les, puis rangez chaque unité. Vous pouvez accepter l\'emplacement proposé ou en choisir un autre : le système contrôlera qu\'il respecte tous les critères.',
			'level'        => 'intermediaire',
			'duration'     => 40,
			'modules'      => 'MD,PUR,GR,PUT,INV,WH',
			'docs'         => array(
				array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $p1['main_supplier_id'], 'items' => $pitems ),
				array( 'ref' => 'PO2', 'type' => 'PO', 'partner_id' => (int) $lg['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $lg['id'], 'qty' => 6 ) ) ),
			),
			'tasks'        => array(
				array( 'Réceptionner les palettes', 'Saisissez l\'entrée de marchandises de la première commande.', 'MIGO' ),
				array( 'Réceptionner les profilés', 'Saisissez l\'entrée de la deuxième commande.', 'MIGO' ),
				array( 'Ranger', 'Confirmez les ordres d\'entreposage : scannez l\'emplacement et le code article.', 'LT12' ),
				array( 'Contrôler sur le plan', 'Retrouvez vos palettes sur le plan de l\'entrepôt.', 'ZMAP' ),
			),
			'checks'       => array(
				array( 'movement_exists', 'Entrée de la commande de palettes', array( 'movement' => '101', 'ref' => 'PO1' ), 1 ),
				array( 'movement_exists', 'Entrée de la commande de profilés', array( 'movement' => '101', 'ref' => 'PO2' ), 1 ),
				array( 'stock_delta', sprintf( 'Profilés %s rangés au cantilever (150)', $lg['material_no'] ), array( 'material_id' => (int) $lg['id'], 'location_code' => '150', 'delta' => 6 ), 2 ),
				array( 'nothing_in_zone', 'Le quai de réception est vide', array( 'location_code' => '100' ), 2 ),
				array( 'storage_compliance', 'Tous les articles respectent les critères de stockage', array(), 3 ),
			),
		);

		// 4. Lotissement par case et réapprovisionnement.
		$pick = self::mat( "m.profile_code = 'STD' AND m.abc = 'A' AND m.batch_managed = 0 AND EXISTS (SELECT 1 FROM " . Db::t( 'bin' ) . ' b JOIN ' . Db::t( 'storage_location' ) . " l ON l.id = b.storage_location_id WHERE b.fixed_material_id = m.id AND l.zone_type = 'PICK')" );
		$small = self::mat( "m.profile_code = 'SMALL'" );
		if ( $pick && $small ) {
			$pbin   = self::fixed_bin( (int) $pick['id'], 'PICK' );
			$sbin   = Catalog::bin( Catalog::fixed_bins_of( (int) $small['id'] )[0] );
			$res    = self::free_bin( '110' );
			$defs['fixed_bins'] = array(
				'title'        => 'Lotissement par case et réapprovisionnement',
				'objectives'   => "Comprendre les emplacements fixes : un emplacement = un seul article.\nRanger des petites pièces dans leur emplacement attribué.\nDéclencher un réapprovisionnement min/max de la zone de prélèvement.",
				'instructions' => sprintf( 'L\'emplacement de prélèvement %s de l\'article %s est presque vide. Réapprovisionnez-le depuis la réserve. Une livraison de petites pièces arrive aussi : elles ont leur emplacement fixe, rien d\'autre ne peut y être stocké.', $pbin['code'], $pick['material_no'] ),
				'level'        => 'intermediaire',
				'duration'     => 35,
				'modules'      => 'MD,PUR,GR,PUT,INV,WH',
				'initial'      => 'EMPTY',
				'initial_stock' => array(
					array( 'material_id' => (int) $pick['id'], 'storage_location_id' => (int) $pbin['storage_location_id'], 'bin_id' => (int) $pbin['id'], 'qty' => 1, 'status' => 'LIBRE' ),
					array( 'material_id' => (int) $pick['id'], 'storage_location_id' => (int) $res['storage_location_id'], 'bin_id' => (int) $res['id'], 'qty' => max( (float) $pbin['max_qty'] * 2, 20 ), 'status' => 'LIBRE' ),
				),
				'docs'         => array( array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $small['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $small['id'], 'qty' => max( 1, min( (float) $sbin['max_qty'], 10 * (int) $small['units_per_carton'] ) ) ) ) ) ),
				'tasks'        => array(
					array( 'Repérer le besoin', 'Affichez les emplacements de prélèvement sous leur minimum.', 'LT01' ),
					array( 'Réapprovisionner', 'Créez puis confirmez l\'ordre de réapprovisionnement.', 'LT12' ),
					array( 'Réceptionner les petites pièces', 'Saisissez l\'entrée, puis rangez dans l\'emplacement fixe proposé.', 'MIGO' ),
				),
				'checks'       => array(
					array( 'replenished', sprintf( 'Emplacement %s réapprovisionné au-dessus du minimum', $pbin['code'] ), array( 'bin_code' => $pbin['code'] ), 3 ),
					array( 'stock_qty', sprintf( 'Petites pièces rangées dans leur emplacement fixe %s', $sbin['code'] ), array( 'material_id' => (int) $small['id'], 'bin_code' => $sbin['code'], 'min' => 1 ), 3 ),
					array( 'storage_compliance', 'Aucun article hors de son emplacement autorisé', array(), 2 ),
				),
			);
		}

		// 5. Réception de produits chimiques.
		$k3 = self::mat( "m.adr_class = '3'" );
		$k8 = self::mat( "m.adr_class = '8' AND m.main_supplier_id = %d", array( (int) $k3['main_supplier_id'] ) ) ?: self::mat( "m.adr_class = '8'" );
		$k5 = self::mat( "m.adr_class = '5.1'" );
		$chem_docs = array( array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $k3['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $k3['id'], 'qty' => 12 ) ) ) );
		if ( (int) $k8['main_supplier_id'] === (int) $k3['main_supplier_id'] ) {
			$chem_docs[0]['items'][] = array( 'material_id' => (int) $k8['id'], 'qty' => 8 );
		} else {
			$chem_docs[] = array( 'ref' => 'PO3', 'type' => 'PO', 'partner_id' => (int) $k8['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $k8['id'], 'qty' => 8 ) ) );
		}
		$chem_docs[] = array( 'ref' => 'PO2', 'type' => 'PO', 'partner_id' => (int) $k5['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $k5['id'], 'qty' => 6 ) ) );
		$defs['chem_reception'] = array(
			'title'        => 'Réception de produits chimiques',
			'objectives'   => "Identifier la classe ADR d'un produit (fiche article, numéro ONU).\nRanger chaque classe dans sa cellule dédiée.\nRespecter la ségrégation : jamais un comburant (5.1) avec un inflammable (3).",
			'instructions' => 'Trois produits dangereux arrivent. Consultez la fiche de chaque article (classe ADR, numéro ONU), réceptionnez avec le lot et la date limite indiqués sur le bon de livraison, puis rangez chaque produit dans la bonne cellule chimique.',
			'level'        => 'avance',
			'duration'     => 45,
			'modules'      => 'MD,PUR,GR,PUT,INV,WH',
			'docs'         => $chem_docs,
			'tasks'        => array(
				array( 'Consulter les fiches', 'Notez la classe ADR et le magasin autorisé de chaque article.', 'MM03' ),
				array( 'Réceptionner', 'Saisissez les entrées avec lot et DLC.', 'MIGO' ),
				array( 'Ranger', 'Confirmez les ordres d\'entreposage dans les cellules 301, 302 et 303.', 'LT12' ),
			),
			'checks'       => array(
				array( 'stock_delta', sprintf( '%s (classe 3) en cellule 301', $k3['material_no'] ), array( 'material_id' => (int) $k3['id'], 'location_code' => '301', 'delta' => 12 ), 2 ),
				array( 'stock_delta', sprintf( '%s (classe 8) en cellule 302', $k8['material_no'] ), array( 'material_id' => (int) $k8['id'], 'location_code' => '302', 'delta' => 8 ), 2 ),
				array( 'stock_delta', sprintf( '%s (classe 5.1) en cellule 303', $k5['material_no'] ), array( 'material_id' => (int) $k5['id'], 'location_code' => '303', 'delta' => 6 ), 2 ),
				array( 'storage_compliance', 'Ségrégation et cellules respectées partout', array(), 2 ),
				array( 'nothing_in_zone', 'Plus aucun produit au quai de réception', array( 'location_code' => '100' ), 1 ),
			),
		);

		// 6. Chaîne du froid et FEFO.
		$cold = self::mat( "m.profile_code = 'COLD' AND m.type IN ('MRCH','PF') AND m.shelf_life_days >= 20" );
		$exp  = self::mat( "m.profile_code = 'COLD' AND m.id <> %d", array( (int) $cold['id'] ) );
		$k1   = self::free_bin( '200', 0 );
		$k2   = self::free_bin( '200', 7 );
		$k3b  = self::free_bin( '200', 15 );
		$base = \LogiERP\Seeder\StockSeeder::base_date();
		$defs['cold_fefo'] = array(
			'title'        => 'Chaîne du froid et FEFO',
			'objectives'   => "Appliquer FEFO : le lot qui périme en premier sort en premier.\nBloquer un lot périmé.\nExpédier en transport frigorifique.",
			'instructions' => 'Un laboratoire commande des produits frais. Préparez la commande en respectant FEFO, emballez avec un transport frigorifique et expédiez. Lors de votre tournée, vous découvrez aussi un lot périmé : bloquez-le pour qu\'il ne soit jamais livré.',
			'level'        => 'intermediaire',
			'duration'     => 40,
			'modules'      => 'MD,SD,PICK,SHIP,INV',
			'initial'      => 'EMPTY',
			'expiry'       => 'MANUEL',
			'initial_stock' => array(
				array( 'material_id' => (int) $cold['id'], 'storage_location_id' => (int) $k1['storage_location_id'], 'bin_id' => (int) $k1['id'], 'batch' => 'L260101A', 'bbd' => gmdate( 'Y-m-d', strtotime( $base . ' +18 days' ) ), 'qty' => 60, 'status' => 'LIBRE' ),
				array( 'material_id' => (int) $cold['id'], 'storage_location_id' => (int) $k2['storage_location_id'], 'bin_id' => (int) $k2['id'], 'batch' => 'L251220B', 'bbd' => gmdate( 'Y-m-d', strtotime( $base . ' +4 days' ) ), 'qty' => 30, 'status' => 'LIBRE' ),
				array( 'material_id' => (int) $exp['id'], 'storage_location_id' => (int) $k3b['storage_location_id'], 'bin_id' => (int) $k3b['id'], 'batch' => 'L251201C', 'bbd' => gmdate( 'Y-m-d', strtotime( $base . ' -2 days' ) ), 'qty' => 24, 'status' => 'LIBRE' ),
			),
			'docs'         => array( array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Santé / laboratoire'" ), 'priority' => 'A', 'day_offset' => 1, 'items' => array( array( 'material_id' => (int) $cold['id'], 'qty' => 40 ) ) ) ),
			'tasks'        => array(
				array( 'Créer la livraison', 'Créez la livraison de la commande client.', 'VL01N' ),
				array( 'Prélever en FEFO', 'Confirmez l\'ordre de prélèvement en respectant les lots proposés.', 'LT12' ),
				array( 'Emballer et expédier', 'Choisissez le bon transporteur, puis enregistrez la sortie de marchandises.', 'VL02N' ),
				array( 'Bloquer le lot périmé', sprintf( 'Repérez le lot périmé de l\'article %s et passez-le en stock bloqué.', $exp['material_no'] ), 'MIGO' ),
			),
			'checks'       => array(
				array( 'doc_status', 'Commande livrée', array( 'ref' => 'SO1', 'status' => array( 'LIVREE' ) ), 2 ),
				array( 'strategy_respected', 'FEFO respecté au prélèvement', array( 'require_pick' => 1 ), 3 ),
				array( 'carrier_used', 'Transport frigorifique utilisé', array( 'ref' => 'SO1', 'carrier' => 'FRIGO' ), 2 ),
				array( 'stock_qty', 'Lot périmé passé en stock bloqué', array( 'material_id' => (int) $exp['id'], 'status' => 'BLOQUE', 'min' => 24 ), 2 ),
				array( 'no_expired_shipped', 'Aucun lot périmé expédié', array(), 1 ),
			),
		);

		// 7. Préparation de commandes en série.
		$docs   = array();
		$checks = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$m1 = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 0 HAVING base_free >= 20", array(), $i * 3 );
			$m2 = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 0 HAVING base_free >= 20", array(), $i * 3 + 1 );
			if ( ! $m1 || ! $m2 ) {
				break;
			}
			$ref      = 'SO' . ( $i + 1 );
			$docs[]   = array( 'ref' => $ref, 'type' => 'SO', 'partner_id' => self::customer( "type IN ('Commerce de détail','Industrie / atelier')", $i ), 'priority' => 0 === $i % 3 ? 'A' : 'B', 'items' => array( array( 'material_id' => (int) $m1['id'], 'qty' => 2 + $i ), array( 'material_id' => (int) $m2['id'], 'qty' => 3 ) ) );
			$checks[] = array( 'doc_status', 'Commande ' . $ref . ' expédiée', array( 'ref' => $ref, 'status' => array( 'LIVREE' ) ), 1 );
		}
		$checks[] = array( 'strategy_respected', 'Stratégie de prélèvement respectée', array( 'require_pick' => 1 ), 2 );
		$checks[] = array( 'max_errors', 'Au maximum 6 erreurs de saisie', array( 'max' => 6 ), 1 );
		$defs['picking_series'] = array(
			'title'        => 'Préparation de 8 commandes clients',
			'objectives'   => "Organiser la préparation selon les priorités (A avant B).\nSuivre le chemin de prélèvement proposé.\nGérer un emplacement vide ou bloqué.",
			'instructions' => 'Huit commandes doivent partir aujourd\'hui. Traitez d\'abord les commandes prioritaires (A). Pour chaque commande : créez la livraison, prélevez, emballez, expédiez.',
			'level'        => 'avance',
			'duration'     => 60,
			'modules'      => 'SD,PICK,SHIP,INV,WH',
			'docs'         => $docs,
			'tasks'        => array(
				array( 'Lister les commandes', 'Affichez les commandes ouvertes et repérez les priorités.', 'VA03' ),
				array( 'Créer les livraisons', 'Créez une livraison par commande.', 'VL01N' ),
				array( 'Prélever', 'Confirmez les ordres de prélèvement.', 'LT12' ),
				array( 'Expédier', 'Emballez et enregistrez les sorties de marchandises.', 'VL02N' ),
			),
			'checks'       => $checks,
		);

		// 8b. Préparation en parallèle par secteur, regroupement et palette mixte (VL10, HU02).
		global $wpdb;
		$st  = Db::t( 'stock' );
		$sl  = Db::t( 'storage_location' );
		// Stock du secteur suffisant ; hors zone de picking (servie en premier), aucun stock ailleurs.
		$in  = static fn( string $code, int $min ) => "EXISTS (SELECT 1 FROM {$st} sx JOIN {$sl} lx ON lx.id = sx.storage_location_id WHERE sx.session_id = 0 AND sx.material_id = m.id AND sx.status = 'LIBRE' AND lx.code = '{$code}' AND sx.qty >= {$min})" . ( '400' === $code ? '' : " AND NOT EXISTS (SELECT 1 FROM {$st} sy JOIN {$sl} ly ON ly.id = sy.storage_location_id WHERE sy.session_id = 0 AND sy.material_id = m.id AND sy.qty > 0 AND ly.code <> '{$code}')" );
		$amb = "m.adr_class = '' AND m.temp_max IS NULL AND m.batch_managed = 0 AND m.high_value = 0 AND m.gross_weight BETWEEN 0.2 AND 12 AND m.volume_l BETWEEN 0.1 AND 25 AND m.ean <> ''";
		$p1  = self::mat( "{$amb} AND " . $in( '400', 20 ) );
		$p2  = self::mat( "{$amb} AND " . $in( '120', 20 ) );
		$p3  = self::mat( "{$amb} AND " . $in( '180', 10 ) );
		$p4  = self::mat( "{$amb} AND " . $in( '110', 20 ) );
		$p5  = self::mat( "{$amb} AND " . $in( '400', 30 ), array(), 1 );
		$p6  = self::mat( "{$amb} AND " . $in( '400', 30 ), array(), 2 );
		$pc  = self::customer( "pallet_type = 'EUR' AND type = 'Commerce de détail'" );
		$pc2 = self::customer( "pallet_type IN ('EUR','') AND type = 'Industrie / atelier'" );
		if ( $p1 && $p2 && $p3 && $p4 && $p5 && $p6 && $pc && $pc2 ) {
			$defs['picking_parallel'] = array(
				'title'        => 'Préparation en parallèle et palette mixte',
				'objectives'   => "Choisir le mode de préparation d'une commande (VL10) : en série ou en parallèle par secteur.\nPrélever par secteur avec le terminal mobile, puis regrouper la commande en zone de consolidation (600).\nColiser sur une palette mixte (HU02) : charge, volume, compatibilité, étiquette SSCC.",
				'instructions' => sprintf( 'Deux commandes partent aujourd\'hui. La commande du magasin %s contient des articles de quatre secteurs différents (picking, petites pièces, fragile, palettier), en petites quantités : aucune ligne ne remplit une palette à elle seule. Pour gagner du temps, faites-la préparer en parallèle, un préparateur par secteur, puis regroupez tout en zone de consolidation et montez une seule palette EUR mixte, comme l\'exige le client. La seconde commande ne concerne qu\'un seul secteur : préparez-la en série. Créez les livraisons avec VL10, prélevez au terminal mobile (ou LT12), colisez (HU02), emballez et expédiez (VL02N).', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Db::t( 'customer' ) . ' WHERE id = %d', $pc ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'level'        => 'avance',
				'duration'     => 50,
				'modules'      => 'SD,PICK,SHIP,WH,INV',
				'docs'         => array(
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => $pc, 'priority' => 'A', 'items' => array( array( 'material_id' => (int) $p1['id'], 'qty' => 6 ), array( 'material_id' => (int) $p2['id'], 'qty' => 10 ), array( 'material_id' => (int) $p3['id'], 'qty' => 4 ), array( 'material_id' => (int) $p4['id'], 'qty' => 5 ) ) ),
					array( 'ref' => 'SO2', 'type' => 'SO', 'partner_id' => $pc2, 'priority' => 'B', 'items' => array( array( 'material_id' => (int) $p5['id'], 'qty' => 8 ), array( 'material_id' => (int) $p6['id'], 'qty' => 6 ) ) ),
				),
				'tasks'        => array(
					array( 'Analyser les commandes à livrer', 'VL10 : secteurs concernés, poids, volume et nombre de palettes estimé de chaque commande.', 'VL10' ),
					array( 'Lancer la préparation', 'VL10 : commande multi-secteurs en parallèle, commande d\'un seul secteur en série.', 'VL10' ),
					array( 'Prélever par secteur', 'Terminal mobile › Prélèvement (ou LT12) : un ordre par secteur, déposé en zone de regroupement.', 'LT12' ),
					array( 'Regrouper et coliser', 'HU02 : une palette EUR, placez tous les articles, fermez l\'unité et imprimez l\'étiquette SSCC.', 'HU02' ),
					array( 'Emballer et expédier', 'VL02N : transporteur, puis sortie de marchandises (601) pour les deux livraisons.', 'VL02N' ),
				),
				'checks'       => array(
					array( 'pick_mode', 'Commande multi-secteurs préparée en parallèle', array( 'ref' => 'SO1', 'mode' => 'PARALLELE' ), 2 ),
					array( 'consolidated', 'Regroupée sur une seule palette EUR mixte', array( 'ref' => 'SO1', 'max_units' => 1, 'type' => 'EUR', 'mixed' => 1 ), 3 ),
					array( 'pick_mode', 'Commande d\'un seul secteur préparée en série', array( 'ref' => 'SO2', 'mode' => 'SERIE' ), 1 ),
					array( 'doc_status', 'Commande SO1 expédiée', array( 'ref' => 'SO1', 'status' => array( 'LIVREE' ) ), 1 ),
					array( 'doc_status', 'Commande SO2 expédiée', array( 'ref' => 'SO2', 'status' => array( 'LIVREE' ) ), 1 ),
					array( 'max_errors', 'Au maximum 6 erreurs de saisie', array( 'max' => 6 ), 1 ),
				),
			);
		}

		// 8. Expédition multi-transporteurs.
		$adr  = self::mat( "m.adr_class = '3' AND m.type IN ('MRCH','PF','SF') HAVING base_free >= 4" );
		$cld  = self::mat( "m.profile_code = 'COLD' AND m.type IN ('MRCH','PF','SF') HAVING base_free >= 6" );
		$std  = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF','SF') AND m.batch_managed = 0 HAVING base_free >= 5", array(), 30 );
		if ( $adr && $cld && $std ) {
			$defs['shipping'] = array(
				'title'        => 'Expédition multi-transporteurs',
				'objectives'   => "Choisir le transporteur selon la marchandise : ADR, froid, colis.\nContrôler le poids et le nombre de colis.\nComprendre les documents d'expédition.",
				'instructions' => 'Trois commandes de natures différentes partent aujourd\'hui. Pour chacune, choisissez le transporteur adapté : produits dangereux, produits frais, marchandise standard en colis.',
				'level'        => 'intermediaire',
				'duration'     => 45,
				'modules'      => 'MD,SD,PICK,SHIP,INV',
				'docs'         => array(
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Industrie / atelier'" ), 'items' => array( array( 'material_id' => (int) $adr['id'], 'qty' => 4 ) ) ),
					array( 'ref' => 'SO2', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Restauration / hôtellerie'" ), 'items' => array( array( 'material_id' => (int) $cld['id'], 'qty' => 6 ) ) ),
					array( 'ref' => 'SO3', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Particulier / e-commerce'" ), 'items' => array( array( 'material_id' => (int) $std['id'], 'qty' => 1 ) ) ),
				),
				'tasks'        => array(
					array( 'Préparer les trois commandes', 'Créez les livraisons et confirmez les prélèvements.', 'VL01N' ),
					array( 'Choisir les transporteurs', 'Emballez chaque livraison avec le transporteur adapté.', 'VL02N' ),
					array( 'Expédier', 'Enregistrez les sorties de marchandises.', 'VL02N' ),
				),
				'checks'       => array(
					array( 'carrier_used', 'Produit dangereux expédié par transporteur ADR', array( 'ref' => 'SO1', 'carrier' => 'ADR' ), 3 ),
					array( 'carrier_used', 'Produits frais expédiés en frigorifique', array( 'ref' => 'SO2', 'carrier' => 'FRIGO' ), 3 ),
					array( 'doc_status', 'Colis du particulier expédié', array( 'ref' => 'SO3', 'status' => array( 'LIVREE' ) ), 2 ),
				),
			);
		}

		// 9. Inventaire tournant.
		$g1 = self::mat( "m.profile_code = 'FRAG' HAVING base_free >= 4" );
		$g2 = self::mat( "m.profile_code = 'FRAG' HAVING base_free >= 4", array(), 3 );
		if ( $g1 && $g2 ) {
			$b1 = Catalog::bin( Catalog::fixed_bins_of( (int) $g1['id'] )[0] );
			$b2 = Catalog::bin( Catalog::fixed_bins_of( (int) $g2['id'] )[0] );
			$defs['cycle_count'] = array(
				'title'        => 'Inventaire tournant du magasin Fragile',
				'objectives'   => "Créer un document d'inventaire et geler les emplacements.\nCompter à l'aveugle, sans connaître le stock théorique.\nAnalyser et comptabiliser les écarts (701 / 702).",
				'instructions' => 'Le contrôle de gestion demande l\'inventaire tournant du magasin 180 (articles fragiles). Créez le document, comptez chaque emplacement, recomptez si demandé, analysez les écarts puis comptabilisez.',
				'level'        => 'intermediaire',
				'duration'     => 45,
				'modules'      => 'INV,CNT,WH',
				'events'       => array(
					array( 'type' => 'inventory_gap', 'trigger' => 'start', 'params' => array( 'material_id' => (int) $g1['id'], 'bin_code' => $b1['code'], 'delta' => -2 ) ),
					array( 'type' => 'inventory_gap', 'trigger' => 'start', 'params' => array( 'material_id' => (int) $g2['id'], 'bin_code' => $b2['code'], 'delta' => 3 ) ),
				),
				'tasks'        => array(
					array( 'Créer le document d\'inventaire', 'Périmètre : magasin 180.', 'MI01' ),
					array( 'Compter', 'Allez compter chaque emplacement et saisissez la quantité constatée.', 'MI04' ),
					array( 'Comptabiliser les écarts', 'Analysez puis comptabilisez.', 'MI07' ),
				),
				'checks'       => array(
					array( 'inventory_posted', 'Inventaire comptabilisé, écarts réels corrigés', array(), 4 ),
					array( 'movement_exists', 'Écart négatif comptabilisé (702)', array( 'movement' => '702', 'material_id' => (int) $g1['id'] ), 2 ),
					array( 'movement_exists', 'Écart positif comptabilisé (701)', array( 'movement' => '701', 'material_id' => (int) $g2['id'] ), 2 ),
				),
			);
		}

		// 10. Ordre de fabrication complet.
		global $wpdb;
		$bom  = Db::t( 'bom' );
		$bi   = Db::t( 'bom_item' );
		$st   = Db::t( 'stock' );
		$pf   = $wpdb->get_var( "SELECT b.material_id FROM {$bom} b WHERE NOT EXISTS (SELECT 1 FROM {$bi} i LEFT JOIN (SELECT material_id, SUM(qty) q FROM {$st} WHERE session_id = 0 AND status = 'LIBRE' GROUP BY material_id) s ON s.material_id = i.component_id WHERE i.bom_id = b.id AND COALESCE(s.q,0) < i.qty * 6) ORDER BY b.id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $pf ) {
			$pfm = Catalog::material( (int) $pf );
			$defs['production'] = array(
				'title'        => 'Ordre de fabrication complet',
				'objectives'   => "Lire une nomenclature.\nLancer un ordre après contrôle de disponibilité.\nConsommer les composants (261), déclarer la production (101) et ranger le produit fini.",
				'instructions' => sprintf( 'L\'atelier doit fabriquer 5 unités de %s (%s). Consultez la nomenclature, lancez l\'ordre, consommez les composants, déclarez la production puis rangez les produits finis.', $pfm['material_no'], $pfm['designation'] ),
				'level'        => 'avance',
				'duration'     => 40,
				'modules'      => 'MD,PP,INV,PUT,WH',
				'docs'         => array( array( 'ref' => 'PROD1', 'type' => 'PROD', 'items' => array( array( 'material_id' => (int) $pf, 'qty' => 5 ) ) ) ),
				'tasks'        => array(
					array( 'Consulter l\'ordre et la nomenclature', 'Vérifiez les composants et leur disponibilité.', 'CO01' ),
					array( 'Lancer et consommer', 'Lancez l\'ordre puis consommez les composants.', 'CO11N' ),
					array( 'Déclarer la production', 'Déclarez 5 unités bonnes.', 'CO11N' ),
					array( 'Ranger le produit fini', 'Confirmez l\'ordre d\'entreposage.', 'LT12' ),
				),
				'checks'       => array(
					array( 'movement_exists', 'Composants consommés (261)', array( 'movement' => '261', 'ref' => 'PROD1' ), 2 ),
					array( 'doc_status', 'Ordre de fabrication terminé', array( 'ref' => 'PROD1', 'status' => array( 'TERMINE' ) ), 2 ),
					array( 'nothing_in_zone', 'Produit fini rangé (quai vide)', array( 'location_code' => '100' ), 2 ),
					array( 'storage_compliance', 'Rangement conforme', array(), 1 ),
				),
			);
		}

		// 14. Contrôle qualité à la réception : échantillon, analyse, libération.
		$q1 = self::mat( "m.profile_code IN ('STD','PAL') AND m.batch_managed = 1 AND m.shelf_life_days >= 180 AND m.ean <> '' AND m.gross_weight BETWEEN 0.5 AND 30" );
		if ( $q1 ) {
			$upc1 = max( 1, (int) $q1['units_per_carton'] );
			$qty1 = $upc1 * max( 1, min( 6, (int) floor( 450 / max( 0.1, (float) $q1['gross_weight'] * $upc1 ) ) ) );
			$defs['qa_gr_release'] = array(
				'title'        => 'Contrôle qualité à la réception : échantillon et libération',
				'objectives'   => "Réceptionner une marchandise soumise à contrôle en stock « contrôle qualité ».\nPrélever un échantillon pour le laboratoire (ordre de transfert) et lire le rapport d'analyse.\nPrendre la décision d'emploi (QA11) et libérer le lot conforme.",
				'instructions' => sprintf( 'L\'article %s (%s) est soumis à un contrôle à réception : il ne peut pas être utilisé avant la décision de la qualité. Réceptionnez la commande en stock « contrôle qualité » et rangez-la. Ouvrez ensuite le lot de contrôle (QA32), demandez un échantillon de 1 %s, prélevez-le (LT12 ou terminal mobile), lisez le rapport du laboratoire et prenez la décision d\'emploi (QA11).', $q1['material_no'], $q1['designation'], $q1['base_uom'] ),
				'level'        => 'intermediaire',
				'duration'     => 35,
				'modules'      => 'MD,GR,PUT,WH,QM,INV',
				'expiry'       => 'MANUEL',
				'docs'         => array( array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $q1['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $q1['id'], 'qty' => $qty1, 'qa' => 'OK:Identité, aspect, teneur et certificat d\'analyse du fournisseur conformes à la spécification. Lot utilisable.' ) ) ) ),
				'tasks'        => array(
					array( 'Réceptionner en contrôle qualité', 'MIGO : saisissez l\'entrée avec le statut « contrôle qualité » (lot et date limite du bon de livraison).', 'MIGO' ),
					array( 'Ranger', 'Confirmez l\'ordre d\'entreposage : le stock reste en contrôle qualité.', 'LT12' ),
					array( 'Demander un échantillon', 'QA32 : ouvrez le lot de contrôle et demandez un échantillon de 1 unité.', 'QA32' ),
					array( 'Prélever l\'échantillon', 'Confirmez l\'ordre de prélèvement d\'échantillon à l\'emplacement du lot.', 'LT12' ),
					array( 'Décision d\'emploi', 'QA11 : lisez le rapport, saisissez le résultat et libérez le lot.', 'QA11' ),
				),
				'checks'       => array(
					array( 'movement_exists', 'Entrée en stock contrôle qualité puis échantillon prélevé (331)', array( 'movement' => '331', 'material_id' => (int) $q1['id'] ), 2 ),
					array( 'qa_decision', 'Résultat conforme et lot libéré', array( 'material_id' => (int) $q1['id'], 'decision' => array( 'LIBERER' ) ), 3 ),
					array( 'movement_exists', 'Libération comptabilisée (321)', array( 'movement' => '321', 'material_id' => (int) $q1['id'] ), 2 ),
					array( 'nothing_in_zone', 'Plus rien au quai de réception', array( 'location_code' => '100' ), 1 ),
					array( 'max_errors', 'Au maximum 4 erreurs de saisie', array( 'max' => 4 ), 1 ),
				),
			);
		}

		// 15. Lot non conforme : retour au fournisseur.
		$q2 = $q1 ? self::mat( "m.profile_code IN ('STD','PAL') AND m.batch_managed = 1 AND m.shelf_life_days >= 180 AND m.ean <> '' AND m.gross_weight BETWEEN 0.5 AND 30 AND m.id <> %d", array( (int) $q1['id'] ), 3 ) : null;
		if ( $q2 ) {
			$upc2 = max( 1, (int) $q2['units_per_carton'] );
			$qty2 = $upc2 * max( 1, min( 4, (int) floor( 450 / max( 0.1, (float) $q2['gross_weight'] * $upc2 ) ) ) );
			$defs['qa_supplier_return'] = array(
				'title'        => 'Lot non conforme : blocage et retour au fournisseur',
				'objectives'   => "Échantillonner un lot en contrôle qualité et interpréter un rapport d'analyse non conforme.\nRefuser le lot par une décision d'emploi motivée.\nRetourner la marchandise au fournisseur (mouvement 122).",
				'instructions' => sprintf( 'La livraison de %s (%s) doit être contrôlée avant utilisation. Réceptionnez-la en contrôle qualité, faites prélever un échantillon et consultez le rapport du laboratoire. Si le lot n\'est pas conforme, décidez son retour au fournisseur avec un motif précis.', $q2['designation'], $q2['material_no'] ),
				'level'        => 'avance',
				'duration'     => 35,
				'modules'      => 'MD,GR,PUT,WH,QM,INV',
				'expiry'       => 'MANUEL',
				'docs'         => array( array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $q2['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $q2['id'], 'qty' => $qty2, 'qa' => 'KO:Taux d\'humidité mesuré 14 % pour une spécification ≤ 8 %. Lot non conforme : ne pas utiliser.' ) ) ) ),
				'tasks'        => array(
					array( 'Réceptionner en contrôle qualité', 'MIGO : entrée avec le statut « contrôle qualité ».', 'MIGO' ),
					array( 'Échantillonner', 'QA32 : demandez un échantillon, puis prélevez-le (LT12 ou terminal mobile).', 'QA32' ),
					array( 'Décider', 'QA11 : résultat non conforme, retour au fournisseur avec le motif.', 'QA11' ),
				),
				'checks'       => array(
					array( 'qa_decision', 'Résultat non conforme et retour fournisseur décidé', array( 'material_id' => (int) $q2['id'], 'decision' => array( 'RETOUR' ) ), 3 ),
					array( 'movement_exists', 'Retour au fournisseur comptabilisé (122)', array( 'movement' => '122', 'material_id' => (int) $q2['id'] ), 2 ),
					array( 'nothing_in_zone', 'Plus rien au quai de réception', array( 'location_code' => '100' ), 1 ),
					array( 'max_errors', 'Au maximum 4 erreurs de saisie', array( 'max' => 4 ), 1 ),
				),
			);
		}

		// 16. Dates limites atteintes : blocage automatique, analyse, destruction ou prolongation.
		$food = self::mat( "m.profile_code = 'COLD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 1 AND m.shelf_life_days BETWEEN 10 AND 60" );
		$nf   = self::mat( "m.profile_code = 'STD' AND m.batch_managed = 1 AND m.shelf_life_days >= 365 AND m.gross_weight BETWEEN 0.5 AND 20" );
		$fb1  = self::free_bin( '200', 3 );
		$fb2  = self::free_bin( '200', 9 );
		$nb1  = self::free_bin( '110', 7 );
		if ( $food && $nf && $fb1 && $fb2 && $nb1 ) {
			$defs['qa_expiry'] = array(
				'title'        => 'Dates limites atteintes : analyse, destruction ou réanalyse',
				'objectives'   => "Repérer les lots dont la date limite est atteinte (MB5M) : ils sont bloqués automatiquement.\nFaire analyser chaque lot et appliquer la bonne décision d'emploi.\nDétruire un produit périssable ; prolonger après réanalyse un produit stable.",
				'instructions' => sprintf( 'Ce matin, le système a bloqué deux lots dont la date limite est dépassée : %s (%s, produit frais) et %s (%s). Pour chacun, demandez un échantillon (QA32), prélevez-le, lisez le rapport du laboratoire puis décidez (QA11) : un produit frais périmé est détruit ; un produit stable dont l\'analyse est conforme peut être libéré avec une nouvelle date de réanalyse (90 jours).', $food['designation'], $food['material_no'], $nf['designation'], $nf['material_no'] ),
				'level'        => 'avance',
				'duration'     => 40,
				'modules'      => 'MD,INV,WH,QM',
				'initial'      => 'EMPTY',
				'initial_stock' => array(
					array( 'material_id' => (int) $food['id'], 'storage_location_id' => (int) $fb1['storage_location_id'], 'bin_id' => (int) $fb1['id'], 'batch' => 'L251215F', 'bbd' => gmdate( 'Y-m-d', strtotime( StockSeeder::base_date() . ' -3 days' ) ), 'qty' => 24, 'status' => 'LIBRE' ),
					array( 'material_id' => (int) $food['id'], 'storage_location_id' => (int) $fb2['storage_location_id'], 'bin_id' => (int) $fb2['id'], 'batch' => 'L260102G', 'bbd' => gmdate( 'Y-m-d', strtotime( StockSeeder::base_date() . ' +12 days' ) ), 'qty' => 36, 'status' => 'LIBRE' ),
					array( 'material_id' => (int) $nf['id'], 'storage_location_id' => (int) $nb1['storage_location_id'], 'bin_id' => (int) $nb1['id'], 'batch' => 'L240910N', 'bbd' => gmdate( 'Y-m-d', strtotime( StockSeeder::base_date() . ' -10 days' ) ), 'qty' => 40, 'status' => 'LIBRE' ),
				),
				'tasks'        => array(
					array( 'Analyser les dates limites', 'MB5M : affichez les lots périmés et ceux qui périment dans les 30 jours.', 'MB5M' ),
					array( 'Échantillonner les deux lots', 'QA32 : demandez un échantillon de 1 unité par lot, puis prélevez-les.', 'QA32' ),
					array( 'Décider', 'QA11 : produit frais → destruction ; produit stable conforme → libération avec date de réanalyse.', 'QA11' ),
				),
				'checks'       => array(
					array( 'qa_decision', sprintf( '%s (frais, périmé) : destruction', $food['material_no'] ), array( 'material_id' => (int) $food['id'], 'decision' => array( 'DETRUIRE' ) ), 3 ),
					array( 'movement_exists', 'Destruction comptabilisée (551)', array( 'movement' => '551', 'material_id' => (int) $food['id'] ), 1 ),
					array( 'qa_decision', sprintf( '%s (stable, conforme) : libération après réanalyse', $nf['material_no'] ), array( 'material_id' => (int) $nf['id'], 'decision' => array( 'LIBERER' ) ), 3 ),
					array( 'expired_free', 'Aucun lot périmé en stock libre', array(), 1 ),
					array( 'max_errors', 'Au maximum 4 erreurs de saisie', array( 'max' => 4 ), 1 ),
				),
			);
		}

		// 17. Planification : besoins, composants en commande, mise à disposition au bord de ligne.
		$bom_t = Db::t( 'bom' );
		$bi_t  = Db::t( 'bom_item' );
		$mt    = Db::t( 'material' );
		$pfp   = (int) $wpdb->get_var( "SELECT b.material_id FROM {$bom_t} b JOIN {$mt} pm ON pm.id = b.material_id WHERE pm.type = 'PF' AND (SELECT COUNT(*) FROM {$bi_t} i WHERE i.bom_id = b.id) = 3 AND NOT EXISTS (SELECT 1 FROM {$bi_t} i JOIN {$mt} c ON c.id = i.component_id WHERE i.bom_id = b.id AND (c.profile_code NOT IN ('STD','SMALL') OR c.batch_managed = 1 OR c.main_supplier_id = 0 OR c.status <> 'ACTIF')) ORDER BY b.id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pbom  = $pfp ? \LogiERP\Domain\Production::bom( $pfp ) : null;
		if ( $pbom ) {
			$pm    = Catalog::material( $pfp );
			$cs    = array_map( static fn( $i ) => array( Catalog::material( (int) $i['component_id'] ), (float) $i['qty'] * ( 1 + (float) $i['scrap_pct'] / 100 ) ), $pbom['items'] );
			$c1    = $cs[0][0];
			$c2    = $cs[1][0];
			$info2 = \LogiERP\Domain\Purchasing::info_record( (int) $c2['main_supplier_id'], (int) $c2['id'] );
			$lead2 = $info2 ? max( 1, (int) $info2['lead_time_days'] ) : 5;
			$need  = static fn( $per, $n ) => (float) ceil( $per * $n );
			$istk  = array();
			foreach ( $cs as $k => [ $cm, $per ] ) {
				// Stock exact pour 5 unités ; le 3e composant suffit aussi pour la 2e commande.
				$q    = 2 === $k ? $need( $per, 14 ) + 2 : $need( $per, 5 );
				$fix  = self::fixed_bin( (int) $cm['id'], 'PICK' ) ?? self::fixed_bin( (int) $cm['id'], 'STD' );
				$bin  = $fix ?: self::free_bin( 'SMALL' === $cm['profile_code'] ? '110' : '110', 20 + $k );
				$istk[] = array( 'material_id' => (int) $cm['id'], 'storage_location_id' => (int) $bin['storage_location_id'], 'bin_id' => (int) $bin['id'], 'qty' => $q, 'status' => 'LIBRE' );
			}
			$cust = self::customer( "type = 'Grossiste / distributeur'", 2 );
			$from = max( 4, $lead2 );
			$defs['mrp_production'] = array(
				'title'        => 'Planifier la production : besoins, commandes et mise à disposition',
				'objectives'   => "Analyser la liste stock/besoins (MD04) et lancer le calcul des besoins (MD01).\nFabriquer ce qui est disponible : lancement (CO02), mise à disposition au bord de ligne (LP10), confirmation (CO11N).\nCommander le composant manquant et planifier l'ordre suivant à la date où tous les composants seront là.",
				'instructions' => sprintf( 'Deux commandes du client arrivent pour %s (%s) : 5 unités pour demain et 8 unités dans 10 jours. Le stock de composants permet d\'en fabriquer 5 tout de suite. Pour la 2e commande, le composant %s est déjà commandé (livraison dans 4 jours) mais le composant %s n\'est pas commandé du tout. Fabriquez les 5 premières unités (avec mise à disposition au bord de ligne), commandez ce qui manque, puis créez l\'ordre de fabrication des 8 unités à une date de début réaliste. Ne livrez pas : l\'expédition sera planifiée plus tard.', $pm['designation'], $pm['material_no'], $c1['material_no'], $c2['material_no'] ),
				'level'        => 'avance',
				'duration'     => 50,
				'modules'      => 'MD,PUR,PP,MRP,WH,PUT,INV',
				'initial'      => 'EMPTY',
				'initial_stock' => $istk,
				'docs'         => array(
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => $cust, 'day_offset' => 1, 'items' => array( array( 'material_id' => $pfp, 'qty' => 5 ) ) ),
					array( 'ref' => 'SO2', 'type' => 'SO', 'partner_id' => $cust, 'day_offset' => 10, 'items' => array( array( 'material_id' => $pfp, 'qty' => 8 ) ) ),
					array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $c1['main_supplier_id'], 'day_offset' => 4, 'items' => array( array( 'material_id' => (int) $c1['id'], 'qty' => $need( $cs[0][1], 8 ) ) ) ),
				),
				'tasks'        => array(
					array( 'Analyser les besoins', sprintf( 'MD04 pour %s et ses composants, puis MD01 : repérez les ruptures et les propositions.', $pm['material_no'] ), 'MD04' ),
					array( 'Fabriquer 5 unités', 'CO01 (5 unités, début aujourd\'hui), CO02 lancement, LP10 mise à disposition, LT12 confirmation, CO11N consommation et déclaration.', 'CO01' ),
					array( 'Mettre à disposition', 'LP10 : créez l\'ordre de mise à disposition et confirmez-le (LT12 ou terminal mobile).', 'LP10' ),
					array( 'Commander le composant manquant', sprintf( 'ME21N : commandez %s pour couvrir la 2e commande.', $c2['material_no'] ), 'ME21N' ),
					array( 'Planifier la 2e fabrication', 'CO01 : 8 unités, date de début quand tous les composants seront disponibles (contrôle de disponibilité).', 'CO01' ),
				),
				'checks'       => array(
					array( 'staged', 'Composants mis à disposition au bord de ligne', array( 'material_id' => $pfp ), 2 ),
					array( 'movement_exists', 'Composants consommés (261)', array( 'movement' => '261', 'material_id' => (int) $c1['id'] ), 1 ),
					array( 'stock_delta', sprintf( '%s : 5 unités fabriquées en stock', $pm['material_no'] ), array( 'material_id' => $pfp, 'status' => 'LIBRE', 'delta' => 5 ), 2 ),
					array( 'po_ordered', sprintf( 'Composant %s commandé (au moins %s)', $c2['material_no'], Catalog::format_qty( $need( $cs[1][1], 8 ) ) ), array( 'material_id' => (int) $c2['id'], 'min' => $need( $cs[1][1], 8 ) ), 2 ),
					array( 'prod_planned', sprintf( 'Ordre de 8 unités planifié au plus tôt dans %d jours', $from ), array( 'material_id' => $pfp, 'min' => 8, 'not_before' => $from ), 2 ),
				),
			);
		}

		// 18. Tournée de livraison : exigences des clients, flotte, emballages consignés.
		$ct    = Db::t( 'customer' );
		$box   = 'lat BETWEEN 46.30 AND 46.85 AND lon BETWEEN 6.20 AND 7.00 AND blocked = 0';
		$w1    = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE type = 'Grossiste / distributeur' AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$w2    = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE type = 'Commerce de détail' AND window_to <= '10:00' AND window_to <> '' AND vehicle_req IN ('PORTEUR','HAYON') AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$w3    = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE type = 'Industrie / atelier' AND pallet_type = 'CP3' AND window_from <> '' AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$w4    = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE type IN ('Restauration / hôtellerie','Santé / laboratoire') AND vehicle_req = 'HAYON' AND FIND_IN_SET('1', delivery_days) AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tp    = self::mat( "m.profile_code = 'PAL' AND m.batch_managed = 0 AND m.adr_class = '' AND m.gross_weight BETWEEN 5 AND 40 HAVING base_free >= 40" );
		$ts    = self::mat( "m.profile_code = 'STD' AND m.batch_managed = 0 AND m.adr_class = '' AND m.gross_weight BETWEEN 1 AND 10 HAVING base_free >= 40", array(), 5 );
		$oil   = Catalog::material_by_no( '19100001' );
		$tc    = self::mat( "m.profile_code = 'COLD' AND m.deposit_material_id > 0 AND m.batch_managed = 1 HAVING base_free >= 30" );
		if ( $w1 && $w2 && $w3 && $w4 && $tp && $ts && $oil && $tc ) {
			$defs['transport_tour'] = array(
				'title'        => 'Tournée de livraison : exigences des clients et emballages consignés',
				'objectives'   => "Emballer chaque livraison sur le support exigé par le client (palette EUR, chimique CP3…).\nPlanifier les tournées (VT01N) : véhicule adapté, ordre des arrêts, plages horaires, coût.\nAu retour du camion, comptabiliser les emballages consignés rendus (VT02N, MB58).",
				'instructions' => 'Quatre clients doivent être livrés aujourd\'hui. Préparez et emballez chaque livraison en respectant le support de charge exigé par le client (fiche client XD03). Planifiez ensuite les tournées avec la flotte de l\'entreprise ou un transporteur : véhicule adapté (froid, hayon, accès), ordre des arrêts respectant les plages de livraison. Validez le départ, puis, au retour du chauffeur, saisissez les emballages vides rendus selon son bon de retour (palettes, fûts, caisses, avec les pièces endommagées).',
				'level'        => 'avance',
				'duration'     => 60,
				'modules'      => 'MD,SD,PICK,SHIP,TRA,INV,WH',
				'docs'         => array(
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => $w1, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $tp['id'], 'qty' => max( 1, (int) $tp['units_per_carton'] ) * max( 1, min( 20, (int) $tp['cartons_per_pallet'] ) ) ) ) ),
					array( 'ref' => 'SO2', 'type' => 'SO', 'partner_id' => $w2, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $ts['id'], 'qty' => 12 ) ) ),
					array( 'ref' => 'SO3', 'type' => 'SO', 'partner_id' => $w3, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $oil['id'], 'qty' => 3 ) ) ),
					array( 'ref' => 'SO4', 'type' => 'SO', 'partner_id' => $w4, 'day_offset' => 0, 'priority' => 'A', 'items' => array( array( 'material_id' => (int) $tc['id'], 'qty' => max( 1, (int) $tc['units_per_carton'] ) * 4 ) ) ),
				),
				'tasks'        => array(
					array( 'Consulter les exigences des clients', 'XD03 : jours et plages de livraison, véhicule, support de charge exigé.', 'XD03' ),
					array( 'Livrer et préparer', 'VL01N pour chaque commande, puis confirmez les prélèvements (LT12).', 'VL01N' ),
					array( 'Emballer sur le bon support', 'VL02N : support exigé par le client, nombre de palettes ou de colis, transport « tournée planifiée ».', 'VL02N' ),
					array( 'Planifier les tournées', 'VT01N : véhicule, heure de départ et ordre des arrêts ; calculez, corrigez les non-conformités, enregistrez.', 'VT01N' ),
					array( 'Départ et retour', 'VT02N : départ du camion (sortie de marchandises), puis saisie des emballages rendus selon le bon du chauffeur.', 'VT02N' ),
				),
				'checks'       => array(
					array( 'support_ok', 'Chaque livraison sur le support exigé par le client', array(), 2 ),
					array( 'tours_ok', 'Les 4 livraisons expédiées par des tournées conformes et clôturées', array( 'min' => 4 ), 4 ),
					array( 'packaging_returns', 'Emballages rendus comptabilisés selon le bon du chauffeur', array(), 3 ),
					array( 'max_errors', 'Au maximum 8 erreurs de saisie', array( 'max' => 8 ), 1 ),
				),
			);
		}

		// 11. Retours clients.
		$rm = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 0 HAVING base_free >= 15", array(), 12 );
		if ( $rm ) {
			$defs['returns'] = array(
				'title'        => 'Gestion des retours clients',
				'objectives'   => "Livrer une commande, puis traiter un retour client.\nPlacer le retour en quarantaine (stock bloqué, magasin 800).\nDécider : remise en stock, rebut ou retour fournisseur.",
				'instructions' => 'Livrez la commande du client. Quelques jours plus tard, le client retourne une partie de la marchandise : 3 pièces intactes (erreur de commande) et 2 pièces abîmées. Enregistrez le retour, puis décidez du sort de chaque lot.',
				'level'        => 'intermediaire',
				'duration'     => 40,
				'modules'      => 'SD,PICK,SHIP,RET,INV',
				'docs'         => array( array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Commerce de détail'", 4 ), 'items' => array( array( 'material_id' => (int) $rm['id'], 'qty' => 10 ) ) ) ),
				'events'       => array( array( 'type' => 'message', 'trigger' => 'after_actions', 'value' => 4, 'message' => sprintf( 'Le client retourne 5 pièces de l\'article %s : 3 intactes (erreur de commande) et 2 abîmées pendant le transport.', $rm['material_no'] ) ) ),
				'tasks'        => array(
					array( 'Livrer la commande', 'Livraison, prélèvement, emballage, sortie de marchandises.', 'VL01N' ),
					array( 'Enregistrer le retour', 'Saisissez le retour client (mouvement 651) : deux lignes séparées, intactes et abîmées.', 'ZRET' ),
					array( 'Décider', 'Remettez en stock les pièces intactes (453) et mettez au rebut les pièces abîmées (551).', 'ZRET' ),
				),
				'checks'       => array(
					array( 'doc_status', 'Commande livrée', array( 'ref' => 'SO1', 'status' => array( 'LIVREE' ) ), 1 ),
					array( 'movement_exists', 'Retour client enregistré (651)', array( 'movement' => '651', 'material_id' => (int) $rm['id'] ), 2 ),
					array( 'movement_exists', 'Pièces intactes remises en stock (453)', array( 'movement' => '453', 'material_id' => (int) $rm['id'] ), 2 ),
					array( 'movement_exists', 'Pièces abîmées mises au rebut (551)', array( 'movement' => '551', 'material_id' => (int) $rm['id'] ), 2 ),
					array( 'return_decided', 'Tous les retours traités', array(), 1 ),
				),
			);
		}

		// 12. Journée complète (examen).
		$e1 = self::mat( $fresh, array(), 40 );
		$e2 = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 0 HAVING base_free >= 20", array(), 50 );
		$e3 = self::mat( "m.profile_code = 'STD' AND m.type IN ('MRCH','PF') AND m.batch_managed = 0 HAVING base_free >= 20", array(), 55 );
		$blk = self::free_bin( '110', 3 );
		if ( $e1 && $e2 && $e3 && $blk ) {
			$defs['full_day'] = array(
				'title'        => 'Journée complète d\'entrepôt (examen)',
				'objectives'   => "Enchaîner réception, entreposage, préparation et expédition.\nRéagir aux imprévus : emplacement bloqué, commande urgente.\nTravailler sans erreur et dans les règles.",
				'instructions' => 'Vous êtes seul responsable de l\'entrepôt aujourd\'hui. Réceptionnez la livraison du matin, rangez-la, préparez et expédiez les commandes du jour. Des imprévus vont arriver : lisez les messages.',
				'level'        => 'examen',
				'duration'     => 90,
				'modules'      => 'MD,PUR,GR,PUT,INV,SD,PICK,SHIP,WH',
				'guided'       => 0,
				'docs'         => array(
					array( 'ref' => 'PO1', 'type' => 'PO', 'partner_id' => (int) $e1['main_supplier_id'], 'items' => array( array( 'material_id' => (int) $e1['id'], 'qty' => 30, 'delivered_qty' => 28 ) ) ),
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Grossiste / distributeur'" ), 'items' => array( array( 'material_id' => (int) $e2['id'], 'qty' => 6 ) ) ),
				),
				'events'       => array(
					array( 'type' => 'block_bin', 'trigger' => 'start', 'params' => array( 'bin_code' => $blk['code'], 'reason' => 'Lisse endommagée par un chariot', 'out' => 1 ), 'message' => 'Un chariot a endommagé la lisse de l\'emplacement ' . $blk['code'] . ' : emplacement bloqué.' ),
					array( 'type' => 'urgent_order', 'trigger' => 'after_actions', 'value' => 3, 'params' => array( 'ref' => 'SO2', 'customer_id' => self::customer( "type = 'Industrie / atelier'", 2 ), 'items' => array( array( 'material_id' => (int) $e3['id'], 'qty' => 4 ) ) ), 'message' => 'Commande urgente d\'un client industriel : elle doit partir avec la tournée de ce matin.' ),
				),
				'tasks'        => array(
					array( 'Réception du matin', 'Contrôlez et réceptionnez la livraison.', 'MIGO' ),
					array( 'Entreposage', 'Rangez la marchandise reçue.', 'LT12' ),
					array( 'Commandes du jour', 'Préparez et expédiez les commandes ouvertes, la plus urgente d\'abord.', 'VL01N' ),
				),
				'checks'       => array(
					array( 'po_received_qty', 'Seulement la quantité réellement livrée réceptionnée', array( 'ref' => 'PO1', 'material_id' => (int) $e1['id'], 'qty' => 28 ), 3 ),
					array( 'nothing_in_zone', 'Marchandise rangée', array( 'location_code' => '100' ), 2 ),
					array( 'doc_status', 'Commande du jour expédiée', array( 'ref' => 'SO1', 'status' => array( 'LIVREE' ) ), 2 ),
					array( 'doc_status', 'Commande urgente expédiée', array( 'ref' => 'SO2', 'status' => array( 'LIVREE' ) ), 3 ),
					array( 'storage_compliance', 'Stockage conforme', array(), 2 ),
					array( 'strategy_respected', 'Stratégie de prélèvement respectée', array(), 1 ),
					array( 'max_errors', 'Au maximum 5 erreurs', array( 'max' => 5 ), 2 ),
				),
			);
		}
		// 15. Transports du jour : flotte de l'entreprise et transporteurs externes, papiers et bulletins.
		$ct   = Db::t( 'customer' );
		$box  = "lat BETWEEN 46.30 AND 46.85 AND lon BETWEEN 6.20 AND 7.00 AND blocked = 0 AND dataset_id = " . (int) Catalog::dataset();
		$days = "FIND_IN_SET('1', delivery_days) AND FIND_IN_SET('5', delivery_days)";
		$p1   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE carrier_code = 'POST' AND vehicle_req = '' AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p2   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE carrier_code = 'POST' AND id <> {$p1} AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$l1   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE carrier_code IN ('LEMAN','HFRET') AND pallet_type IN ('','EUR') AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$f1   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE carrier_code = 'PROPRE' AND vehicle_req IN ('','PORTEUR') AND pallet_type IN ('','EUR') AND {$days} AND (window_to = '' OR window_to >= '11:00') AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$f2   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE carrier_code = 'PROPRE' AND id <> {$f1} AND vehicle_req IN ('','PORTEUR') AND pallet_type IN ('','EUR') AND {$days} AND (window_to = '' OR window_to >= '11:00') AND {$box} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$a1   = (int) $wpdb->get_var( "SELECT id FROM {$ct} WHERE type = 'Industrie / atelier' AND {$box} ORDER BY id LIMIT 1 OFFSET 2" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$small = self::mat( "m.profile_code = 'STD' AND m.batch_managed = 0 AND m.adr_class = '' AND (m.temp_max IS NULL OR m.temp_max > 8) AND m.gross_weight BETWEEN 0.3 AND 3 HAVING base_free >= 30" );
		$small2 = self::mat( "m.profile_code = 'STD' AND m.batch_managed = 0 AND m.adr_class = '' AND (m.temp_max IS NULL OR m.temp_max > 8) AND m.gross_weight BETWEEN 0.3 AND 3 HAVING base_free >= 30", array(), 3 );
		$palg  = self::mat( "m.profile_code = 'PAL' AND m.batch_managed = 0 AND m.adr_class = '' AND (m.temp_max IS NULL OR m.temp_max > 8) AND m.gross_weight BETWEEN 5 AND 30 HAVING base_free >= 60" );
		$palg2 = self::mat( "m.profile_code = 'PAL' AND m.batch_managed = 0 AND m.adr_class = '' AND (m.temp_max IS NULL OR m.temp_max > 8) AND m.gross_weight BETWEEN 5 AND 30 HAVING base_free >= 60", array(), 2 );
		$adrm  = self::mat( "m.adr_class <> '' AND m.bulk = 0 AND m.batch_managed = 0 AND m.gross_weight BETWEEN 1 AND 40 HAVING base_free >= 10" );
		if ( $p1 && $p2 && $l1 && $f1 && $f2 && $a1 && $small && $small2 && $palg && $palg2 && $adrm ) {
			$defs['transport_day'] = array(
				'title'        => 'Organiser les transports du jour',
				'objectives'   => "Affecter les livraisons du jour aux moyens de transport : camions de l'entreprise et transporteurs externes (VT04, VT11).\nRespecter les exigences : marchandises dangereuses, colis ou palettes, capacité, plages de livraison.\nImprimer les bulletins de livraison (VL71) et les papiers de transport (VT70), puis expédier (VT02N).",
				'instructions' => 'Six commandes doivent partir aujourd\'hui. Créez les livraisons, puis répartissez-les sur les moyens de transport : la création groupée (VT04) propose une affectation que vous contrôlez et corrigez dans le plan du jour (VT11). Les petits envois partent par le service colis, les palettes en groupage, la marchandise dangereuse uniquement avec le transporteur ADR, et les clients livrés par l\'entreprise en tournée. Emballez les livraisons, imprimez tous les bulletins de livraison en une fois, puis les papiers de chaque transport, et validez les départs.',
				'level'        => 'avance',
				'duration'     => 60,
				'modules'      => 'MD,SD,PICK,SHIP,TRA',
				'mode'         => 'SIMPLE',
				'guided'       => 1,
				'docs'         => array(
					array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => $p1, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $small['id'], 'qty' => 6 ) ) ),
					array( 'ref' => 'SO2', 'type' => 'SO', 'partner_id' => $p2, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $small2['id'], 'qty' => 4 ) ) ),
					array( 'ref' => 'SO3', 'type' => 'SO', 'partner_id' => $l1, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $palg['id'], 'qty' => 40 ) ) ),
					array( 'ref' => 'SO4', 'type' => 'SO', 'partner_id' => $f1, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $palg2['id'], 'qty' => 30 ) ) ),
					array( 'ref' => 'SO5', 'type' => 'SO', 'partner_id' => $f2, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $palg['id'], 'qty' => 20 ) ) ),
					array( 'ref' => 'SO6', 'type' => 'SO', 'partner_id' => $a1, 'day_offset' => 0, 'items' => array( array( 'material_id' => (int) $adrm['id'], 'qty' => 4 ) ) ),
				),
				'tasks'        => array(
					array( 'Créer les livraisons', 'Livraisons de toutes les commandes du jour.', 'VL10' ),
					array( 'Voir les livraisons à planifier', 'Poids, palettes, ADR et transporteur habituel de chaque client.', 'VL06T' ),
					array( 'Créer les transports', 'Contrôlez la proposition (transporteur habituel, ADR, flotte), corrigez-la puis créez les transports.', 'VT04' ),
					array( 'Contrôler le plan du jour', 'Chargement de chaque moyen de transport, déplacez une livraison si nécessaire.', 'VT11' ),
					array( 'Emballer', 'Emballez chaque livraison : le transport est imposé par la planification.', 'VL02N' ),
					array( 'Imprimer les bulletins', 'Tous les bulletins de livraison du jour en une fois.', 'VL71' ),
					array( 'Papiers et départs', 'Feuille de route ou bordereau de remise de chaque transport, puis départ du camion ou remise au transporteur.', 'VT70' ),
				),
				'checks'       => array(
					array( 'shipped_by_transport', 'Les 6 livraisons parties avec un transport planifié', array( 'min' => 6 ), 3 ),
					array( 'means_mix', 'Flotte et transporteurs externes utilisés', array( 'carriers' => 2 ), 2 ),
					array( 'carrier_used', 'Marchandise dangereuse confiée au transporteur ADR', array( 'ref' => 'SO6', 'carrier' => 'ADR' ), 2 ),
					array( 'notes_printed', 'Un bulletin de livraison pour chaque livraison', array(), 1 ),
					array( 'papers_printed', 'Papiers de transport imprimés', array(), 1 ),
					array( 'max_errors', 'Au maximum 6 erreurs', array( 'max' => 6 ), 1 ),
				),
			);
		}

		// 13. Procédures de l'entreprise : production par procédé (ordres PI01, conditionnement).
		$naoh = Catalog::material_by_no( '39000102' );
		$drum = Catalog::material_by_no( '39000012' );
		if ( $naoh && $drum ) {
			$defs['process_order'] = array(
				'title'        => 'Conditionner de la soude : ordre de procédé',
				'objectives'   => "Lire une recette (CS03) et contrôler la disponibilité des composants.\nCréer, lancer et confirmer un ordre de procédé (COR1, COR5, CORK).\nRanger le produit fini et contrôler le stock (LS26).",
				'instructions' => sprintf( 'Le client industriel a commandé plus de %s (%s) que le stock disponible. Conditionnez 20 bidons depuis la citerne : consultez la recette, créez l\'ordre de procédé PI01, lancez-le, confirmez-le en contrôlant les lots consommés, puis quittancez l\'entreposage du produit fini.', $naoh['material_no'], $naoh['designation'] ),
				'level'        => 'avance',
				'duration'     => 45,
				'modules'      => 'MD,PP,MRP,INV,WH,PUT,SD',
				'guided'       => 1,
				'docs'         => array( array( 'ref' => 'SO1', 'type' => 'SO', 'partner_id' => self::customer( "type = 'Industrie / atelier'", 1 ), 'items' => array( array( 'material_id' => (int) $naoh['id'], 'qty' => 60 ) ) ) ),
				'tasks'        => array(
					array( 'Analyser la commande', 'Affichez la commande client : le poste est coché (date reportée) faute de stock.', 'VA02' ),
					array( 'Lire la recette', 'Contrôlez les composants : soude en citerne, bidons neufs, étiquettes.', 'CS03' ),
					array( 'Créer l\'ordre', 'Ordre de procédé PI01 de 20 bidons, fin prévue dans 2 jours.', 'COR1' ),
					array( 'Lancer et imprimer', 'Lancez l\'ordre (statut LANC).', 'COR5' ),
					array( 'Confirmer', 'Confirmez 20 bidons bons, en contrôlant le lot de soude consommé.', 'CORK' ),
					array( 'Ranger le produit fini', 'Quittancez l\'ordre d\'entreposage du produit fini.', 'LS26' ),
				),
				'checks'       => array(
					array( 'movement_exists', 'Bidons consommés (261)', array( 'movement' => '261', 'material_id' => (int) $drum['id'] ), 2 ),
					array( 'movement_exists', 'Produit fini entré en stock (101)', array( 'movement' => '101', 'material_id' => (int) $naoh['id'] ), 3 ),
					array( 'nothing_in_zone', 'Produit fini rangé (rien au quai de réception)', array( 'location_code' => '100' ), 2 ),
					array( 'max_errors', 'Au maximum 4 erreurs', array( 'max' => 4 ), 1 ),
				),
			);
		}

		// 14. Procédures de l'entreprise : vrac par wagon-citerne et conteneurs loués.
		$bulk = Catalog::material_by_no( '39000002' );
		$ibc  = Catalog::material_by_no( 'X39000013' );
		if ( $bulk && $ibc ) {
			$defs['bulk_containers'] = array(
				'title'        => 'Wagon-citerne et conteneurs loués',
				'objectives'   => "Préparer une réception en vrac à l'aide du portail ferroviaire (ZRAIL).\nRéceptionner un wagon-citerne et déduire la perte de citerne (LT12, code d'écart, LI21).\nEnregistrer le retour de conteneurs loués par numéro et le déclarer sur le portail du loueur.",
				'instructions' => 'Un wagon-citerne de soude caustique est annoncé à notre gare. Contrôlez-le sur le portail ferroviaire, réceptionnez-le sur la commande d\'achat, confirmez le dépotage en déclarant le fond non dépotable, puis soldez les écarts. Un client rend ensuite des conteneurs GRV vides : enregistrez le retour avec les numéros de conteneur, contrôlez-les et déclarez le retour sur le portail du loueur.',
				'level'        => 'avance',
				'duration'     => 50,
				'modules'      => 'MD,PUR,GR,WH,PUT,INV,TRA,RET',
				'guided'       => 1,
				'tasks'        => array(
					array( 'Wagon annoncé', 'Repérez le wagon et sa commande d\'achat sur le portail ferroviaire.', 'ZRAIL' ),
					array( 'Réceptionner le vrac', 'Entrée sur commande, transport « wagon-citerne ».', 'MIGO' ),
					array( 'Dépoter', 'Confirmez l\'OT vers la citerne avec le code d\'écart 0 (perte de citerne).', 'LT12' ),
					array( 'Solder les écarts', 'Comptabilisez la perte dans l\'aire 999.', 'LI21' ),
					array( 'Retour de conteneurs', 'Saisissez les conteneurs rendus, un numéro par ligne, puis contrôlez-les.', 'ZVLGUT' ),
					array( 'Déclarer au loueur', 'Déclarez le retour sur le portail emballages.', 'ZPEMB' ),
				),
				'checks'       => array(
					array( 'movement_exists', 'Wagon réceptionné (101)', array( 'movement' => '101', 'material_id' => (int) $bulk['id'] ), 3 ),
					array( 'movement_exists', 'Perte de citerne soldée (711)', array( 'movement' => '711', 'material_id' => (int) $bulk['id'] ), 2 ),
					array( 'movement_exists', 'Conteneurs loués repris (622)', array( 'movement' => '622', 'material_id' => (int) $ibc['id'] ), 2 ),
					array( 'max_errors', 'Au maximum 5 erreurs', array( 'max' => 5 ), 1 ),
				),
			);
		}
		return $defs;
	}
}
