<?php
declare(strict_types=1);

namespace LogiERP\Seeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dictionnaires du générateur. Toutes les données sont fictives : aucune vraie marque,
 * raisons sociales inventées, e-mails sur le domaine réservé .example.
 */
final class Dictionaries {

	/**
	 * Profils de stockage : nombre d'articles, préfixe de numéro, plages physiques (par unité de base).
	 * w = poids kg, v = volume L, len = longueur mm, price = valeur standard CHF.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function profiles(): array {
		return array(
			'STD'   => array( 'name' => 'Standard (sec, ambiant)', 'count' => 900, 'prefix' => '10', 'w' => array( 0.1, 25 ), 'v' => array( 0.2, 40 ), 'len' => array( 80, 900 ), 'price' => array( 1.5, 400 ), 'upc' => array( 1, 24 ), 'uom' => 'PCE' ),
			'SMALL' => array( 'name' => 'Petites pièces', 'count' => 250, 'prefix' => '12', 'w' => array( 0.002, 0.4 ), 'v' => array( 0.001, 0.25 ), 'len' => array( 5, 120 ), 'price' => array( 0.05, 12 ), 'upc' => array( 50, 500 ), 'uom' => 'PCE' ),
			'PAL'   => array( 'name' => 'Palettes complètes', 'count' => 450, 'prefix' => '14', 'w' => array( 5, 30 ), 'v' => array( 8, 50 ), 'len' => array( 300, 700 ), 'price' => array( 3, 120 ), 'upc' => array( 1, 1 ), 'uom' => 'CRT' ),
			'HV'    => array( 'name' => 'Haute valeur', 'count' => 150, 'prefix' => '16', 'w' => array( 0.2, 15 ), 'v' => array( 0.5, 30 ), 'len' => array( 100, 700 ), 'price' => array( 300, 25000 ), 'upc' => array( 1, 4 ), 'uom' => 'PCE' ),
			'CHEM'  => array( 'name' => 'Chimie / matières dangereuses', 'count' => 250, 'prefix' => '31', 'w' => array( 1, 25 ), 'v' => array( 1, 28 ), 'len' => array( 150, 450 ), 'price' => array( 4, 180 ), 'upc' => array( 1, 12 ), 'uom' => 'PCE' ),
			'BATT'  => array( 'name' => 'Batteries lithium', 'count' => 90, 'prefix' => '33', 'w' => array( 0.3, 8 ), 'v' => array( 0.2, 6 ), 'len' => array( 80, 400 ), 'price' => array( 25, 900 ), 'upc' => array( 1, 10 ), 'uom' => 'PCE' ),
			'COLD'  => array( 'name' => 'Réfrigéré +2 / +8 °C', 'count' => 200, 'prefix' => '40', 'w' => array( 0.2, 6 ), 'v' => array( 0.25, 8 ), 'len' => array( 80, 350 ), 'price' => array( 1.5, 320 ), 'upc' => array( 6, 24 ), 'uom' => 'PCE' ),
			'FROZ'  => array( 'name' => 'Surgelé −25 / −18 °C', 'count' => 120, 'prefix' => '42', 'w' => array( 0.3, 10 ), 'v' => array( 0.4, 12 ), 'len' => array( 100, 450 ), 'price' => array( 2, 90 ), 'upc' => array( 4, 20 ), 'uom' => 'PCE' ),
			'TEMP'  => array( 'name' => 'Température contrôlée +15 / +25 °C', 'count' => 120, 'prefix' => '44', 'w' => array( 0.05, 2 ), 'v' => array( 0.05, 2.5 ), 'len' => array( 40, 250 ), 'price' => array( 3, 480 ), 'upc' => array( 10, 60 ), 'uom' => 'PCE' ),
			'LONG'  => array( 'name' => 'Marchandises longues (> 2,5 m)', 'count' => 150, 'prefix' => '50', 'w' => array( 3, 90 ), 'v' => array( 2, 80 ), 'len' => array( 2600, 6000 ), 'price' => array( 8, 650 ), 'upc' => array( 1, 1 ), 'uom' => 'PCE' ),
			'HEAVY' => array( 'name' => 'Marchandises lourdes (> 500 kg)', 'count' => 120, 'prefix' => '52', 'w' => array( 510, 2400 ), 'v' => array( 250, 1800 ), 'len' => array( 800, 2500 ), 'price' => array( 800, 24000 ), 'upc' => array( 1, 1 ), 'uom' => 'PCE' ),
			'BULKY' => array( 'name' => 'Volumineux / non gerbable', 'count' => 120, 'prefix' => '54', 'w' => array( 8, 80 ), 'v' => array( 180, 1100 ), 'len' => array( 800, 2200 ), 'price' => array( 40, 1400 ), 'upc' => array( 1, 1 ), 'uom' => 'PCE' ),
			'FRAG'  => array( 'name' => 'Fragile', 'count' => 80, 'prefix' => '56', 'w' => array( 0.3, 14 ), 'v' => array( 1, 40 ), 'len' => array( 100, 900 ), 'price' => array( 6, 600 ), 'upc' => array( 1, 12 ), 'uom' => 'PCE' ),
		);
	}

	/**
	 * Familles de désignations par profil : [groupe, noms, qualificatifs, formats].
	 *
	 * @return array<string,array<int,array{0:string,1:string[],2:string[],3:string[]}>>
	 */
	public static function families(): array {
		return array(
			'STD'   => array(
				array( 'Outillage à main', array( 'Tournevis', 'Clé plate', 'Clé à pipe', 'Pince universelle', 'Marteau', 'Scie à métaux', 'Cutter', 'Niveau à bulle', 'Mètre ruban', 'Serre-joint' ), array( 'professionnel', 'isolé 1000 V', 'ergonomique', 'acier chromé', 'compact', 'renforcé' ), array( '150 mm', '200 mm', '250 mm', '300 mm', 'taille M', 'set 6 pcs', 'set 12 pcs' ) ),
				array( 'Fournitures de bureau', array( 'Classeur', 'Ramette papier', 'Agrafeuse', 'Bloc-notes', 'Enveloppes', 'Chemises', 'Stylos', 'Surligneurs', 'Étiquettes adhésives', 'Rouleaux caisse' ), array( 'A4', 'A5', 'recyclé', 'couleurs assorties', 'grand format', 'pro' ), array( 'boîte de 10', 'paquet de 50', 'carton de 5', 'lot de 20', '500 feuilles' ) ),
				array( 'EPI et sécurité', array( 'Gants de manutention', 'Lunettes de protection', 'Casque de chantier', 'Gilet haute visibilité', 'Bouchons d\'oreilles', 'Chaussures de sécurité', 'Masque FFP2', 'Genouillères' ), array( 'S3', 'classe 2', 'anti-coupure', 'EN 388', 'ventilé', 'réglable' ), array( 'taille 8', 'taille 9', 'taille 10', 'taille 42', 'taille 44', 'boîte de 20', 'taille unique' ) ),
				array( 'Épicerie sèche', array( 'Pâtes', 'Riz', 'Café en grains', 'Thé noir', 'Biscuits', 'Farine', 'Sucre', 'Chocolat noir', 'Céréales', 'Huile d\'olive' ), array( 'bio', 'premium', 'équitable', 'complet', 'nature', 'familial' ), array( '500 g', '1 kg', '2 kg', '250 g', '1 L', 'carton de 12' ) ),
				array( 'Textile', array( 'T-shirt de travail', 'Pantalon multipoches', 'Veste softshell', 'Polo', 'Bonnet', 'Sweat zippé', 'Combinaison' ), array( 'coton', 'polyester recyclé', 'déperlant', 'anthracite', 'bleu marine', 'gris' ), array( 'taille S', 'taille M', 'taille L', 'taille XL', 'taille XXL' ) ),
				array( 'Entretien', array( 'Détergent sol', 'Papier ménage', 'Sacs poubelle', 'Lavette microfibre', 'Balai', 'Seau', 'Savon mains', 'Désinfectant surfaces' ), array( 'concentré', 'professionnel', 'sans parfum', 'écologique', 'industriel' ), array( '1 L', '5 L', 'rouleau de 50', 'lot de 10', '110 L', '35 L' ) ),
				array( 'Électricité', array( 'Rallonge', 'Multiprise', 'Ampoule LED', 'Projecteur LED', 'Câble souple', 'Interrupteur', 'Prise murale', 'Boîte de dérivation' ), array( 'IP44', 'IP65', '3 pôles', '230 V', 'blanc', 'noir' ), array( '5 m', '10 m', '25 m', '10 W', '30 W', '50 W', 'T13' ) ),
				array( 'Emballages', array( 'Carton américain', 'Ruban adhésif', 'Film étirable', 'Papier bulle', 'Sachets zip', 'Étiquettes colis', 'Feuillard', 'Coins de protection' ), array( 'simple cannelure', 'double cannelure', 'transparent', 'brun', 'renforcé', 'imprimé fragile' ), array( '300x200x150', '400x300x200', '600x400x400', '50 mm x 66 m', '500 mm x 300 m', 'lot de 100' ) ),
			),
			'SMALL' => array(
				array( 'Visserie', array( 'Vis à bois', 'Vis métrique', 'Écrou hexagonal', 'Rondelle plate', 'Boulon', 'Vis autoforeuse', 'Tige filetée', 'Écrou frein' ), array( 'inox A2', 'inox A4', 'zinguée', 'acier 8.8', 'laiton' ), array( 'M4', 'M5', 'M6', 'M8', 'M10', 'M12', '4x40', '5x60', '6x80' ) ),
				array( 'Joints et étanchéité', array( 'Joint torique', 'Joint plat', 'Bague d\'étanchéité', 'Joint fibre', 'Bouchon' ), array( 'NBR', 'EPDM', 'Viton', 'silicone', 'PTFE' ), array( '10x2', '16x2,5', '20x3', '25x3', '32x4', 'DN15', 'DN20' ) ),
				array( 'Connectique', array( 'Connecteur', 'Cosse', 'Borne de raccordement', 'Fusible', 'Domino', 'Serre-câble' ), array( 'à sertir', 'isolé', 'automobile', 'rapide', 'étamé' ), array( '1,5 mm²', '2,5 mm²', '4 mm²', '10 A', '16 A', '200 mm' ) ),
				array( 'Roulements', array( 'Roulement à billes', 'Roulement à aiguilles', 'Palier', 'Bague de serrage', 'Circlip' ), array( 'étanche 2RS', 'blindé ZZ', 'inox', 'standard' ), array( '6000', '6001', '6202', '6204', '608', '625', 'Ø10', 'Ø20' ) ),
				array( 'Fixations', array( 'Cheville', 'Rivet aveugle', 'Agrafe', 'Clou', 'Collier de serrage', 'Goupille' ), array( 'nylon', 'aluminium', 'acier', 'universelle', 'à frapper' ), array( '6 mm', '8 mm', '10 mm', '3,2x8', '4x10', '25 mm' ) ),
			),
			'PAL'   => array(
				array( 'Granulés et vrac conditionné', array( 'Granulés de bois', 'Sel de déneigement', 'Terreau', 'Gravier décoratif', 'Sable de quartz', 'Granulés plastique PE' ), array( 'premium', 'certifié', 'calibré', 'standard', 'industriel' ), array( 'sac 15 kg', 'sac 25 kg', 'sac 20 kg', 'sac 40 L', 'sac 30 kg' ) ),
				array( 'Boissons', array( 'Eau minérale', 'Jus de pomme', 'Limonade', 'Thé froid', 'Boisson isotonique', 'Sirop' ), array( 'gazeuse', 'plate', 'bio', 'sans sucre', 'citron' ), array( 'pack 6x1,5 L', 'caisse 12x1 L', 'caisse 24x0,5 L', 'carton 6x1 L' ) ),
				array( 'Cartonnages en lots', array( 'Cartons pliés', 'Intercalaires', 'Plateaux carton', 'Caisses-palettes pliées', 'Cornières carton' ), array( 'double cannelure', 'kraft', 'blanc', 'renforcé' ), array( 'paquet de 25', 'paquet de 50', 'paquet de 100', 'liasse de 20' ) ),
				array( 'Matériaux de construction', array( 'Ciment', 'Mortier', 'Colle carrelage', 'Enduit', 'Chape' ), array( 'Portland', 'rapide', 'flexible', 'intérieur', 'extérieur' ), array( 'sac 25 kg', 'sac 20 kg', 'sac 40 kg', 'seau 15 kg' ) ),
				array( 'Alimentation animale', array( 'Croquettes chien', 'Croquettes chat', 'Aliment volaille', 'Foin compressé', 'Litière' ), array( 'adulte', 'junior', 'premium', 'naturel' ), array( 'sac 10 kg', 'sac 15 kg', 'sac 20 kg', 'balle 18 kg' ) ),
			),
			'HV'    => array(
				array( 'Électronique', array( 'Ordinateur portable', 'Tablette durcie', 'Scanner code-barres', 'Terminal mobile', 'Imprimante d\'étiquettes', 'Smartphone pro', 'Écran 27 pouces', 'Station d\'accueil' ), array( 'industriel', 'Wi-Fi 6', '5G', 'IP65', 'pro', 'professionnel' ), array( '16 Go', '32 Go', '256 Go', '512 Go', '1 To', 'modèle X2', 'modèle X5' ) ),
				array( 'Instruments de mesure', array( 'Pied à coulisse numérique', 'Micromètre', 'Multimètre', 'Caméra thermique', 'Télémètre laser', 'Balance de précision', 'Oscilloscope' ), array( 'étalonné', 'haute précision', 'certifié ISO', 'portable', 'professionnel' ), array( '150 mm', '0-25 mm', '600 V', '100 m', '0,01 g', '2 voies', '4 voies' ) ),
				array( 'Outillage de précision', array( 'Visseuse dynamométrique', 'Clé dynamométrique', 'Fraise carbure', 'Jeu de forets', 'Perceuse sans fil' ), array( 'professionnelle', 'certifiée', 'carbure monobloc', '18 V', 'brushless' ), array( '5-25 Nm', '20-200 Nm', 'Ø6', 'Ø10', 'coffret 25 pcs' ) ),
			),
			'CHEM'  => array(
				array( 'Solvants inflammables', array( 'Acétone technique', 'Éthanol dénaturé', 'White-spirit', 'Diluant cellulosique', 'Isopropanol', 'Essence de nettoyage', 'Peinture solvantée', 'Vernis' ), array( 'qualité technique', 'pur 99 %', 'industriel', 'désaromatisé' ), array( 'bidon 1 L', 'bidon 5 L', 'bidon 10 L', 'bidon 20 L' ) ),
				array( 'Corrosifs', array( 'Acide chlorhydrique', 'Acide phosphorique', 'Soude caustique', 'Détartrant acide', 'Dégraissant alcalin', 'Acide sulfurique dilué' ), array( '10 %', '25 %', '33 %', 'concentré', 'industriel' ), array( 'bidon 1 L', 'bidon 5 L', 'bidon 10 L', 'bidon 25 kg' ) ),
				array( 'Comburants', array( 'Eau oxygénée', 'Chlorate de sodium', 'Percarbonate de sodium', 'Hypochlorite de calcium' ), array( '35 %', 'technique', 'granulé', 'stabilisé' ), array( 'bidon 5 L', 'seau 5 kg', 'seau 10 kg', 'fût 25 kg' ) ),
				array( 'Toxiques', array( 'Pesticide concentré', 'Produit de traitement du bois', 'Réactif de laboratoire', 'Biocide' ), array( 'concentré', 'professionnel', 'usage restreint' ), array( 'flacon 1 L', 'bidon 5 L', 'flacon 500 ml' ) ),
				array( 'Aérosols', array( 'Aérosol dégrippant', 'Aérosol peinture', 'Aérosol nettoyant contact', 'Mousse expansive', 'Spray lubrifiant', 'Air sec' ), array( 'multi-usage', 'technique', 'silicone', 'haute température' ), array( '400 ml', '500 ml', '750 ml', 'carton de 12' ) ),
			),
			'BATT'  => array(
				array( 'Batteries', array( 'Batterie outillage', 'Accu lithium-ion', 'Batterie de rechange scanner', 'Pack batterie vélo cargo', 'Batterie terminal mobile', 'Power bank' ), array( '18 V', '36 V', '12 V', '3,7 V', 'haute capacité' ), array( '2 Ah', '4 Ah', '5 Ah', '8 Ah', '20 000 mAh', '500 Wh' ) ),
			),
			'COLD'  => array(
				array( 'Produits laitiers', array( 'Yogourt nature', 'Lait pasteurisé', 'Crème entière', 'Beurre', 'Fromage à pâte mi-dure', 'Séré', 'Mozzarella' ), array( 'bio', 'entier', 'demi-écrémé', 'régional', 'premium' ), array( '180 g', '500 g', '1 L', '250 g', '2,5 kg', 'carton de 12' ) ),
				array( 'Réactifs et vaccins', array( 'Réactif de diagnostic', 'Vaccin vétérinaire', 'Kit de test rapide', 'Solution tampon', 'Milieu de culture' ), array( 'à conserver au froid', 'usage vétérinaire', 'laboratoire', 'stérile' ), array( 'kit 25 tests', 'flacon 10 ml', 'boîte de 10', 'flacon 100 ml' ) ),
				array( 'Frais traiteur', array( 'Salade composée', 'Sandwich', 'Jambon cuit', 'Saumon fumé', 'Pâte à tarte', 'Sauce fraîche' ), array( 'frais', 'artisanal', 'tranché', 'prêt à consommer' ), array( '200 g', '1 kg', 'barquette 300 g', 'pièce' ) ),
			),
			'FROZ'  => array(
				array( 'Surgelés', array( 'Légumes surgelés', 'Frites', 'Pizza', 'Filet de poisson', 'Glace', 'Croissants crus', 'Viande hachée' ), array( 'surgelé', 'précuit', 'bio', 'vanille', 'nature' ), array( '1 kg', '2,5 kg', '450 g', '5 L', 'carton de 60' ) ),
				array( 'Échantillons congelés', array( 'Échantillon biologique', 'Plasma de référence', 'Enzyme' ), array( 'à −20 °C', 'laboratoire', 'certifié' ), array( 'boîte de 10', 'tube 5 ml', 'kit' ) ),
			),
			'TEMP'  => array(
				array( 'Médicaments et santé', array( 'Comprimés antalgiques', 'Pommade', 'Pansements', 'Sirop', 'Désinfectant cutané', 'Vitamines' ), array( 'boîte', 'tube', 'stérile', 'adulte', 'enfant' ), array( '20 cp', '50 g', 'boîte de 100', '200 ml', '30 gélules' ) ),
				array( 'Cosmétiques', array( 'Crème hydratante', 'Shampooing', 'Gel douche', 'Crème solaire', 'Déodorant' ), array( 'peau sensible', 'sans parfum', 'IP 50', 'bio' ), array( '50 ml', '250 ml', '500 ml', '150 ml' ) ),
				array( 'Colles et mastics', array( 'Colle cyanoacrylate', 'Colle époxy', 'Mastic silicone', 'Colle à bois' ), array( 'rapide', 'bi-composant', 'transparent', 'blanc' ), array( '20 g', '50 ml', '310 ml', '750 g' ) ),
			),
			'LONG'  => array(
				array( 'Profilés et barres', array( 'Profilé aluminium', 'Tube acier', 'Barre ronde inox', 'Cornière acier', 'Fer plat', 'Rail de montage', 'Tube PVC' ), array( 'anodisé', 'galvanisé', 'brut', 'laqué blanc', 'pression' ), array( '3 m', '4 m', '5 m', '6 m' ) ),
				array( 'Bois et panneaux longs', array( 'Lame de terrasse', 'Liteau', 'Poutre lamellée', 'Planche rabotée', 'Moulure' ), array( 'mélèze', 'épicéa', 'chêne', 'autoclave' ), array( '3 m', '4 m', '4,5 m', '6 m' ) ),
			),
			'HEAVY' => array(
				array( 'Équipements lourds', array( 'Moteur électrique', 'Bobine d\'acier', 'Plaque d\'acier', 'Compresseur', 'Transformateur', 'Réducteur', 'Groupe hydraulique' ), array( 'industriel', 'triphasé', 'laminé à froid', 'S235', 'haute puissance' ), array( '15 kW', '30 kW', '600 kg', '1 t', '1,5 t', '2 t' ) ),
			),
			'BULKY' => array(
				array( 'Mobilier et volumineux', array( 'Bureau réglable', 'Armoire métallique', 'Fauteuil de bureau', 'Étagère modulaire', 'Panneau isolant', 'Cuve vide', 'Bac de rétention' ), array( 'électrique', 'anthracite', 'blanc', 'polyéthylène', 'laine de roche' ), array( '1200x800', '1600x800', '1000 L', '600 L', 'lot de 6' ) ),
			),
			'FRAG'  => array(
				array( 'Verrerie et céramique', array( 'Verre à vin', 'Assiette plate', 'Vase', 'Bocal', 'Carafe', 'Tasse', 'Plat à four' ), array( 'cristal', 'porcelaine', 'grès', 'verre trempé' ), array( 'lot de 6', 'Ø 27 cm', '1 L', '0,5 L', 'lot de 12' ) ),
				array( 'Écrans et optique', array( 'Dalle LED', 'Écran de rechange', 'Lentille optique', 'Miroir de sécurité' ), array( 'anti-reflet', 'convexe', 'standard' ), array( '15 pouces', '24 pouces', 'Ø 60 cm', 'Ø 80 cm' ) ),
			),
		);
	}

	/** Classes ADR pour les familles de chimie (même ordre que families()['CHEM']). */
	public static function chem_classes(): array {
		return array(
			array( 'class' => '3', 'count' => 100, 'un' => array( '1090', '1170', '1300', '1263', '1219', '1993' ), 'pg' => 'II', 'text' => 'Liquide inflammable' ),
			array( 'class' => '8', 'count' => 60, 'un' => array( '1789', '1805', '1823', '1760', '2796' ), 'pg' => 'II', 'text' => 'Matière corrosive' ),
			array( 'class' => '5.1', 'count' => 30, 'un' => array( '2014', '1495', '3378', '2880' ), 'pg' => 'II', 'text' => 'Matière comburante' ),
			array( 'class' => '6.1', 'count' => 30, 'un' => array( '2902', '2810', '3352' ), 'pg' => 'III', 'text' => 'Matière toxique' ),
			array( 'class' => '2', 'count' => 30, 'un' => array( '1950' ), 'pg' => '', 'text' => 'Gaz (aérosol)' ),
		);
	}

	/** Paires de classes incompatibles dans une même cellule (ségrégation simplifiée). */
	public static function incompatible_pairs(): array {
		return array( array( '3', '5.1' ), array( '2', '5.1' ), array( '8', '5.1' ), array( '3', '9' ), array( '2', '9' ), array( '6.1', '5.1' ) );
	}

	public static function company_roots(): array {
		return array( 'Alpina', 'Helvetec', 'Lémantec', 'Juralis', 'Valtrex', 'Rhodania', 'Sarinex', 'Broyeval', 'Moléson', 'Chasseral', 'Salève', 'Dentec', 'Orbelis', 'Vaudéa', 'Arvel', 'Lavaux', 'Morgex', 'Trevalis', 'Novaflex', 'Cristalux', 'Pragmatek', 'Simplon', 'Glaciel', 'Ferralis', 'Boréa', 'Neviant', 'Aubonis', 'Venoge', 'Grandson', 'Suchet', 'Chamossaire', 'Diablerets', 'Gruyéra', 'Riviera', 'Orvex', 'Tamaris', 'Opaleo', 'Metalia', 'Silvatek', 'Logidis', 'Promatek', 'Ventura', 'Nordica', 'Equinox', 'Zénithal', 'Sirocco', 'Altaïr', 'Polaris', 'Corvex', 'Linea' );
	}

	public static function company_suffixes(): array {
		return array( 'Industrie', 'Distribution', 'Technique', 'Services', 'Solutions', 'Logistique', 'Fournitures', 'Négoce', 'Production', 'Group' );
	}

	public static function legal_forms( string $country ): array {
		switch ( $country ) {
			case 'DE':
			case 'AT':
				return array( 'GmbH', 'AG' );
			case 'FR':
				return array( 'SAS', 'SARL' );
			case 'IT':
				return array( 'S.r.l.', 'S.p.A.' );
			case 'NL':
				return array( 'B.V.' );
			case 'BE':
				return array( 'NV', 'SRL' );
			case 'PL':
				return array( 'Sp. z o.o.' );
			case 'CZ':
				return array( 's.r.o.' );
			case 'CN':
				return array( 'Co. Ltd' );
			default:
				return array( 'SA', 'Sàrl', 'AG' );
		}
	}

	/** Localités suisses réelles (NPA + localité), majoritairement romandes. */
	public static function swiss_cities(): array {
		return array(
			array( '1003', 'Lausanne' ), array( '1020', 'Renens' ), array( '1110', 'Morges' ), array( '1260', 'Nyon' ), array( '1201', 'Genève' ),
			array( '1227', 'Carouge' ), array( '1400', 'Yverdon-les-Bains' ), array( '1800', 'Vevey' ), array( '1820', 'Montreux' ), array( '1860', 'Aigle' ),
			array( '1870', 'Monthey' ), array( '1920', 'Martigny' ), array( '1950', 'Sion' ), array( '3960', 'Sierre' ), array( '1700', 'Fribourg' ),
			array( '1630', 'Bulle' ), array( '1530', 'Payerne' ), array( '2000', 'Neuchâtel' ), array( '2300', 'La Chaux-de-Fonds' ), array( '2400', 'Le Locle' ),
			array( '2800', 'Delémont' ), array( '2900', 'Porrentruy' ), array( '2502', 'Bienne' ), array( '3000', 'Berne' ), array( '1510', 'Moudon' ),
			array( '1350', 'Orbe' ), array( '1305', 'Penthalaz' ), array( '1023', 'Crissier' ), array( '1030', 'Bussigny' ), array( '1196', 'Gland' ),
			array( '4000', 'Bâle' ), array( '8000', 'Zurich' ), array( '6000', 'Lucerne' ), array( '1580', 'Avenches' ), array( '1904', 'Vernayaz' ),
		);
	}

	public static function foreign_cities(): array {
		return array(
			'DE' => array( array( '70173', 'Stuttgart' ), array( '79098', 'Freiburg im Breisgau' ), array( '80331', 'München' ) ),
			'FR' => array( array( '74000', 'Annecy' ), array( '25000', 'Besançon' ), array( '69002', 'Lyon' ) ),
			'IT' => array( array( '20121', 'Milano' ), array( '10121', 'Torino' ) ),
			'AT' => array( array( '6020', 'Innsbruck' ) ),
			'NL' => array( array( '3011', 'Rotterdam' ) ),
			'CN' => array( array( '200000', 'Shanghai' ) ),
		);
	}

	/**
	 * Fournisseurs européens : [pays, nombre, [[code postal, localité], …], [rues], [suffixes], [contacts]].
	 * Pays voisins livrant par la route (lettre de voiture CMR).
	 */
	public static function foreign_supplier_countries(): array {
		return array(
			array( 'DE', 9, array( array( '79539', 'Lörrach' ), array( '78462', 'Konstanz' ), array( '70173', 'Stuttgart' ), array( '79098', 'Freiburg im Breisgau' ), array( '88045', 'Friedrichshafen' ), array( '68159', 'Mannheim' ), array( '80331', 'München' ), array( '90402', 'Nürnberg' ) ), array( 'Industriestraße', 'Gewerbepark Nord', 'Robert-Bosch-Straße', 'Am Güterbahnhof', 'Hafenstraße' ), array( 'Technik', 'Logistik', 'Industriebedarf', 'Handel', 'Verpackung', 'Werke' ), array( 'Lena Hoffmann', 'Jonas Becker', 'Markus Schulz', 'Sabine Wagner', 'Tobias Keller' ) ),
			array( 'FR', 7, array( array( '74000', 'Annecy' ), array( '25000', 'Besançon' ), array( '69007', 'Lyon' ), array( '68100', 'Mulhouse' ), array( '67000', 'Strasbourg' ), array( '38000', 'Grenoble' ), array( '01210', 'Ferney-Voltaire' ) ), array( 'rue de l\'Industrie', 'avenue de la Gare', 'rue des Entrepreneurs', 'rue du Port', 'boulevard de l\'Europe' ), array( 'Industrie', 'Distribution', 'Technique', 'Emballages' ), array( 'Claire Martin', 'Julien Bernard', 'Camille Durand', 'Nicolas Petit' ) ),
			array( 'IT', 6, array( array( '22100', 'Como' ), array( '21100', 'Varese' ), array( '20121', 'Milano' ), array( '10121', 'Torino' ), array( '24121', 'Bergamo' ), array( '37121', 'Verona' ) ), array( 'Via dell\'Industria', 'Via Leonardo da Vinci', 'Viale Europa', 'Via Roma', 'Via dei Mille' ), array( 'Industriale', 'Forniture', 'Tecnica', 'Distribuzione', 'Imballaggi' ), array( 'Marco Bianchi', 'Giulia Romano', 'Luca Ferrari', 'Chiara Colombo' ) ),
			array( 'AT', 3, array( array( '6900', 'Bregenz' ), array( '6020', 'Innsbruck' ), array( '5020', 'Salzburg' ) ), array( 'Industriestraße', 'Gewerbestraße', 'Bahnhofstraße' ), array( 'Technik', 'Handel', 'Logistik' ), array( 'Stefan Gruber', 'Anna Huber', 'Florian Moser' ) ),
			array( 'NL', 2, array( array( '3011 AD', 'Rotterdam' ), array( '5611 AZ', 'Eindhoven' ) ), array( 'Havenweg', 'Industrieweg' ), array( 'Trading', 'Industries', 'Packaging' ), array( 'Daan de Vries', 'Sanne Jansen' ) ),
			array( 'BE', 1, array( array( '2000', 'Antwerpen' ) ), array( 'Havenlaan', 'Industrieweg' ), array( 'Trading', 'Industries' ), array( 'Pieter Peeters' ) ),
			array( 'PL', 1, array( array( '50-001', 'Wrocław' ) ), array( 'ul. Przemysłowa', 'ul. Logistyczna' ), array( 'Industrial', 'Trade' ), array( 'Anna Nowak', 'Piotr Kowalski' ) ),
			array( 'CZ', 1, array( array( '110 00', 'Praha' ) ), array( 'Průmyslová', 'Skladová' ), array( 'Trade', 'Industry' ), array( 'Jan Novák', 'Petra Svobodová' ) ),
		);
	}

	/** Spécialités des 30 fournisseurs européens (même nomenclature que les fournisseurs suisses). */
	public static function foreign_specialties(): array {
		return array(
			'Quincaillerie et outillage'       => 6,
			'Emballages et cartonnages'        => 3,
			'Électronique et instruments'      => 4,
			'Chimie industrielle'              => 3,
			'Agroalimentaire frais et surgelé' => 3,
			'Pharma et cosmétique'             => 2,
			'Métallurgie et profilés'          => 4,
			'Mobilier et équipement'           => 2,
			'Verrerie et arts de la table'     => 2,
			'Négoce général'                   => 1,
		);
	}

	/** Transporteurs routiers internationaux (fictifs) : [nom, rue, code postal, localité, pays, plaque tracteur, plaque remorque]. */
	public static function foreign_hauliers(): array {
		return array(
			'DE' => array( array( 'Spedition Rheintal GmbH', 'Zollstraße 7', '79576', 'Weil am Rhein', 'DE', 'LÖ-SR 412', 'LÖ-SR 98' ), array( 'Bodensee Cargo GmbH', 'Hafenstraße 22', '78467', 'Konstanz', 'DE', 'KN-BC 2071', 'KN-BC 77' ) ),
			'FR' => array( array( 'Transports Savoie-Léman SAS', 'rue du Transit 3', '74160', 'Saint-Julien-en-Genevois', 'FR', 'GH-418-KT', 'GH-882-RA' ), array( 'Alsace Fret SARL', 'rue de la Douane 12', '68300', 'Saint-Louis', 'FR', 'FP-206-ZL', 'FP-207-ZL' ) ),
			'IT' => array( array( 'Autotrasporti Lario S.r.l.', 'Via della Dogana 5', '22100', 'Como', 'IT', 'GD 418 KP', 'XA 552 RT' ) ),
			'AT' => array( array( 'Vorarlberg Transit GmbH', 'Zollamtstraße 2', '6890', 'Lustenau', 'AT', 'DO-418 VT', 'DO-419 VT' ) ),
			'NL' => array( array( 'Maas Road Freight B.V.', 'Distributieweg 40', '3199 KD', 'Rotterdam', 'NL', '12-BXK-4', 'WR-88-TZ' ) ),
			'BE' => array( array( 'Scheldt Trucking NV', 'Transportlaan 9', '2030', 'Antwerpen', 'BE', '1-XKT-418', 'Q-AZT-218' ) ),
			'PL' => array( array( 'Trans-Odra Sp. z o.o.', 'ul. Transportowa 18', '50-516', 'Wrocław', 'PL', 'DW 4187K', 'DW 2201P' ) ),
			'CZ' => array( array( 'Vltava Freight s.r.o.', 'Dopravní 11', '190 00', 'Praha', 'CZ', '1AT 4182', '1AT 9930' ) ),
		);
	}

	/** Bureau de douane d'entrée en Suisse selon le pays d'expédition. */
	public static function border_office( string $country ): string {
		return array(
			'DE' => 'Bâle/Weil am Rhein-Autoroute',
			'FR' => 'Genève-Bardonnex',
			'IT' => 'Chiasso-Brogeda',
			'AT' => 'St. Margrethen-Autoroute',
			'NL' => 'Bâle/Weil am Rhein-Autoroute',
			'BE' => 'Bâle/Weil am Rhein-Autoroute',
			'LU' => 'Bâle/Weil am Rhein-Autoroute',
			'PL' => 'St. Margrethen-Autoroute',
			'CZ' => 'St. Margrethen-Autoroute',
			'ES' => 'Genève-Bardonnex',
		)[ $country ] ?? 'Bâle/Weil am Rhein-Autoroute';
	}

	public static function streets(): array {
		return array( 'Rue de l\'Industrie', 'Chemin des Ateliers', 'Route de la Gare', 'Avenue du Lac', 'Zone industrielle des Plans', 'Rue du Marché', 'Chemin du Verger', 'Route Cantonale', 'Rue des Artisans', 'Chemin de la Scie', 'Avenue de la Poste', 'Rue du Midi', 'Route du Moulin', 'Chemin des Peupliers', 'Rue de la Fabrique' );
	}

	public static function contacts(): array {
		return array( 'Martin Rochat', 'Sophie Berset', 'Luca Bianchi', 'Nadia Morand', 'Kevin Chappuis', 'Ana Ferreira', 'Thomas Gilliéron', 'Léa Pittet', 'Marc Dubois', 'Elif Kaya', 'Julien Bovet', 'Sara Cattin', 'David Monnier', 'Inès Moreira', 'Yann Jaquier' );
	}

	/** Spécialités fournisseurs et profils couverts. */
	public static function supplier_specialties(): array {
		return array(
			array( 'Quincaillerie et outillage', array( 'STD', 'SMALL' ), 22 ),
			array( 'Emballages et cartonnages', array( 'PAL', 'STD' ), 8 ),
			array( 'Électronique et instruments', array( 'HV', 'BATT' ), 10 ),
			array( 'Chimie industrielle', array( 'CHEM' ), 10 ),
			array( 'Agroalimentaire frais et surgelé', array( 'COLD', 'FROZ' ), 10 ),
			array( 'Pharma et cosmétique', array( 'TEMP' ), 6 ),
			array( 'Métallurgie et profilés', array( 'LONG', 'HEAVY' ), 12 ),
			array( 'Mobilier et équipement', array( 'BULKY' ), 6 ),
			array( 'Verrerie et arts de la table', array( 'FRAG' ), 4 ),
			array( 'Négoce général', array( 'STD', 'PAL' ), 12 ),
		);
	}

	/** Types de clients : [type, nombre, priorités possibles, exigences]. */
	public static function customer_types(): array {
		return array(
			array( 'Commerce de détail', 30, array( 'B', 'C' ), 'Colis, étiquetage client' ),
			array( 'Grossiste / distributeur', 15, array( 'A', 'B' ), 'Palettes EUR complètes, avis de livraison' ),
			array( 'Industrie / atelier', 25, array( 'A', 'B', 'C' ), 'Livraisons programmées' ),
			array( 'Construction / bâtiment', 10, array( 'B', 'C' ), 'Livraison chantier, marchandises longues' ),
			array( 'Santé / laboratoire', 8, array( 'A' ), 'Traçabilité lot, chaîne du froid' ),
			array( 'Restauration / hôtellerie', 7, array( 'A', 'B' ), 'Plage horaire stricte, froid' ),
			array( 'Particulier / e-commerce', 5, array( 'C' ), 'Unités, retours fréquents' ),
		);
	}

	public static function carriers(): array {
		return array(
			array( 'POST', 'Colis Express Suisse', 'colis', 30 ),
			array( 'LEMAN', 'Transports Léman SA', 'camion', 12000 ),
			array( 'HFRET', 'Helvetia Fret', 'camion', 24000 ),
			array( 'RAPID', 'Rapido Express', 'express', 70 ),
			array( 'FRIGO', 'Frigo Trans Romandie', 'camion frigorifique', 8000 ),
			array( 'ADR', 'Fret Dangereux Alpes', 'camion ADR', 10000 ),
			array( 'PROPRE', 'Camion de l\'entreprise', 'camion', 3500 ),
		);
	}
}
