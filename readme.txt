=== LogiERP ===
Contributors: logiprof
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 2.3.1
License: GPLv2 or later

Simulateur ERP / WMS pédagogique pour la formation en logistique (CFC logisticien·ne et formations continues).

== Description ==

LogiERP reproduit le fonctionnement d'un ERP de type SAP (MM / WM / SD / PP) dans WordPress :
achats, réception avec contrôle et réserves, entreposage chaotique ou par emplacements fixes,
gestion de stock, ordres de transfert, ventes, préparation FIFO / FEFO, emballage et expédition
(ADR, froid), production, inventaire tournant, retours clients et fournisseurs.

* Jeu de base préparé automatiquement à l'activation : entrepôt cartographié de plus de 5 000 emplacements
  répartis en 24 zones de stockage, 3 000 articles tous en stock, 130 fournisseurs (100 en Suisse,
  30 en Europe) et 100 clients en Suisse, stock initial cohérent avec les critères de stockage (haute valeur sous clé, matières dangereuses par
  classe ADR, froid et surgelé, charges longues, lourdes, encombrantes, fragiles).
* Données de base : saisie manuelle, import et export Excel (.xlsx) avec modèle téléchargeable
  (feuilles Aide et Exemples), contrôles de cohérence logistique (profil, ADR, DLC, dimensions, NPA suisse).
* Entrepôt : création de zones, critères de stockage par zone (profils admis, température, classes ADR
  avec contrôle des incompatibilités, sécurisation, mélange), limites des emplacements, génération d'allées.
* Entreprise fictive Lémantis Logistique SA (modifiable) : présentée à l'apprenant·e au début de chaque session.
* Arrivages internationaux : lettre de voiture CMR numérique en 4 exemplaires de couleur, imprimable,
  avec signature et réserves en case 24.
* 19 scénarios modèles corrigés automatiquement (note suisse de 1 à 6).
* Espace enseignant : éditeur de scénarios, classes, codes d'invitation, import CSV, assignations
  avec dates et durée, suivi en direct avec imprévus, correction ajustable, publication, export CSV.

== Installation ==

1. Téléversez le fichier ZIP (Extensions › Ajouter › Téléverser), puis activez l'extension.
2. Vous arrivez sur LogiERP › Tableau de bord : les données se préparent seules (environ une minute).
3. La page « Simulateur LogiERP » (code court [logierp_eleve]) est créée automatiquement pour les apprenants.
4. Pour un espace enseignant sur le site, placez le code court [logierp_enseignant] dans une page.
5. Donnez le rôle « Enseignant LogiERP » aux enseignants ; les apprenants rejoignent une classe
   avec un code d'invitation ou sont inscrits par l'enseignant.

== Codes courts ==

* [logierp_eleve] : espace apprenant (scénarios assignés, simulateur, résultats publiés).
* [logierp_enseignant] : espace enseignant (tableau de bord, scénarios, classes, suivi en direct,
  résultats ; données de base et entrepôt pour les comptes autorisés). Les réglages restent dans l'administration.

== Sécurité ==

* Toutes les requêtes SQL sont préparées ; tables préfixées lw_ dans une liste blanche.
* API REST avec nonce et contrôle de propriété de chaque session ; actions d'administration
  protégées par nonce et capacités dédiées ; limitation de débit (sessions, imports, codes).
* Aucune donnée n'est insérée en HTML côté navigateur ; aucune ressource externe (police intégrée).
* Mises à jour depuis le dépôt GitHub officiel uniquement (HTTPS), paquet vérifié par empreinte SHA-256,
  dossier et numéro de version contrôlés avant installation.
* La suppression de l'extension conserve les données, sauf si l'option correspondante est cochée.

== Changelog ==

= 2.3.1 =
* Réglages › Mises à jour : la première vérification se lance d'elle-même à l'ouverture de la page (résultat ensuite gardé en cache plusieurs heures).
* Le lien « Activer les mises à jour auto » de la page Extensions reste affiché même quand GitHub ne répond pas (dépôt privé sans jeton, panne réseau).
* Première mise à jour distribuée automatiquement depuis GitHub.

= 2.3.0 =
* Mises à jour intégrées à WordPress : chaque nouvelle version publiée sur github.com/logiprof/LogiERP apparaît dans Extensions et dans Tableau de bord › Mises à jour, s'installe en un clic et peut être automatique (« Activer les mises à jour auto »).
* Sécurité des mises à jour : dépôt unique fixé dans le code, téléchargement HTTPS, empreinte SHA-256 obligatoire, contrôle du dossier et du numéro de version ; en cas d'écart, rien n'est modifié.
* Réglages › Mises à jour : version installée, dernière version publiée, bouton « Rechercher les mises à jour ». Lien identique sur la page Extensions.
* Dépôt privé : jeton GitHub en lecture seule dans wp-config.php (constante LOGIERP_GITHUB_TOKEN), jamais enregistré en base.

= 2.2.1 =
* Codes personnels en PDF : « Codes en PDF » produit directement un document avec une page A4 par apprenti (triée par groupe) : apprenti, groupe et coéquipiers, mission et conditions (mode de travail, ouverture, fermeture, durée, tentatives), grand cadre du code, marche à suivre et avertissement. « Fiche PDF » réimprime la fiche d'un seul apprenti. PDF généré par le plugin, sans service externe.

= 2.2.0 =
* Groupes de travail : chaque classe peut avoir ses sous-groupes (création, répartition élève par élève ou automatique au hasard, effectifs équilibrés). Un apprenti appartient à un seul groupe de la classe.
* Assignation individuelle ou en groupe. En groupe : une seule session partagée par groupe, une note commune, le journal indique qui a fait chaque saisie et la page de résultats montre la contribution de chaque membre. En individuel : toute la classe (les nouveaux inscrits suivent automatiquement) ou seulement les apprentis cochés.
* Codes de mission personnels : un code unique par apprenti, exigé au premier accès. Il ne fonctionne qu'avec le compte de son titulaire : le code d'un autre est refusé et la tentative est signalée à l'enseignant (« Codes refusés »). Fiches de codes à imprimer, nouveau code en un clic.
* Portail des apprentis : champ « Code de mission », groupe et coéquipiers affichés sur la mission. Suivi en direct, résultats, export CSV (une ligne par membre) et tableaux d'indicateurs par groupe.
* Sécurité : une seule écriture à la fois par session (verrou), une seule session par groupe même si deux membres commencent en même temps, codes limités en nombre d'essais ; l'effacement des données personnelles conserve le travail des autres membres du groupe.
* Les assignations existantes ne changent pas (toute la classe, sans code) ; un bouton permet de leur générer des codes.

= 2.1.1 =
* Fiches de procédure aux couleurs de LogiERP (vert, jaune, anthracite) et lisibles par les élèves daltoniens : étapes au bureau en cases blanches avec un écran, étapes dans l'entrepôt en cases foncées avec une bande hachurée et une palette ; contraste vérifié en simulation de daltonisme et en niveaux de gris.

= 2.1.0 =
* Planification des transports (lot 6) : livraisons à planifier (VL06T), création groupée des transports (VT04), plan des transports du jour (VT11), affichage d'un transport (VT03N), papiers de transport (VT70 : feuille de route, ordre de chargement, bordereau de remise, document ADR) et impression des bulletins de livraison en série (VL71).
* Flotte de l'entreprise et transporteurs externes cohabitent : tournées (véhicule, arrêts, horaires, chargement) et enlèvements au quai par un transporteur (colis, express, groupage, frigorifique, ADR) avec leurs règles (pas de palettes en colis, poids par colis, ADR, froid).
* Les livraisons se planifient avant l'emballage (poids et palettes estimés) ; l'emballage reprend le moyen de transport choisi et le départ recontrôle le chargement avec les poids réels.
* VT02N traite tous les transports : départ du camion, remise au transporteur, changement d'heure, suppression. Un véhicule de la flotte ne peut plus être engagé dans deux tournées en même temps.
* Le bulletin de livraison indique le transport et l'heure d'arrivée prévue.
* Nouveau scénario modèle « Organiser les transports du jour ».
* Manuels refaits ; fiches de procédure par processus (organigrammes bureau et entrepôt) ajoutées au manuel d'utilisation.

= 2.0.0 =
* Reprise des procédures SAP de l'entreprise : 35 nouvelles transactions, regroupées dans le domaine « P » du manuel d'utilisation.
* Stock et corrections : MB11 (mouvements 201, 309, 311/312, 343/344, 411 E/412 E, 501, 551, 924), MB03 et MBST (affichage et annulation d'un document d'article), LS26 (quants, unités de stock, OT en cours), LT13 (quittancer par unité de stock), LT15 (annuler un OT), LT31 (réimpression de l'OT et des étiquettes), LI21 (aire des écarts 999). MB51 filtre par lot et par période et ouvre MB03.
* Achat et réception : ME2L, Y_P01 (arrivages planifiés et retards), ME22N (quantité, livraison finale, suppression de poste, historique, document ZWEZ, modifications), MIGO_GR (transfert entre sites par n° de bulletin). MIGO : onglets de poste (certificat, date de fabrication, lot fournisseur, emballage, TUS, livraison finale), case « Poste OK », transport du vrac (camion ou wagon-citerne).
* Vente et expédition : VA02 (quantité, date, motif de refus Z1–Z4, blocage de livraison, ajout de poste, contrôle de disponibilité, historique), VA05, positions « cochées » (date confirmée reportée), VL02N (onglets postes : lot imposé, quantité, création d'OT, suppression ; messages : bulletin ZPAL et certificat d'analyse), VL06O (sortie collective), VL09 (annulation 602 et 652), VL74, reprise du n° de bulletin d'une livraison supprimée dans VL10.
* Production par procédé : ordres PI01 et SH08 (changement de lot en citerne), COR1, COR2, COR3, COR5, COR8, CORK (lots consommés, emballage de remplacement), CORS, clôture technique, recettes CS03 et cas d'emploi CS15, gestionnaires de production.
* Emballages et portails : conteneurs GRV loués suivis par numéro (ZVLGUT retours et contrôle, MB58 par n° de conteneur), portail emballages ZPEMB et portail ferroviaire ZRAIL simulés avec des données fictives, citernes de vrac (magasin 310) et pertes de citerne (codes d'écart LT12).
* Deux nouveaux scénarios modèles : « Conditionner de la soude : ordre de procédé » et « Wagon-citerne et conteneurs loués ».
* Entreposage : une quantité trop grande pour un seul emplacement est répartie automatiquement en plusieurs unités de stock.
* Corrections : les chiffres de contrôle saisis dans LT12 sont bien transmis ; le statut « partiellement confirmé » d'un ordre de fabrication est enregistré en entier.

= 1.9.4 =
* Tableaux des transactions (MIGO, VL10, LT10…) : en-têtes foncés à fort contraste, insensibles au thème du site ; les titres de colonnes passent à la ligne au lieu d'être coupés ; les champs gardent une largeur lisible ; si le tableau dépasse l'écran, il défile horizontalement avec une ombre qui signale les colonnes masquées.

= 1.9.3 =
* Espace enseignant : l'illustration du tableau de bord ne recouvre plus la navigation (la navigation reste dans la colonne du texte, l'illustration à droite, comme dans le simulateur).
* Même animation des cubes que dans le simulateur des apprentis.

= 1.9.2 =
* Espace enseignant du site redessiné sur le modèle du simulateur des apprentis : bandeau anthracite, « Bonjour … » sur le tableau de bord, titre de chaque page dans le bandeau, navigation en pastilles, cartes et boutons arrondis.
* Badge d'espace bien visible : « Espace enseignant » (enseignants, aussi dans l'administration) et « Espace apprenti » (simulateur).
* Menus du site : le lien vers l'espace enseignant n'apparaît qu'aux comptes enseignants (menus classiques, navigation des thèmes par blocs, listes de pages).
* Lien « Simulateur apprenti » dans l'espace enseignant.

= 1.9.1 =
* Manuels dans l'outil : nouvelle page LogiERP › Manuels (enseignants) avec le manuel de l'enseignant et le manuel d'utilisation, à lire ou à télécharger en PDF, et un sommaire qui ouvre chaque procédure à sa page.
* Apprentis : encart « Manuel d'utilisation » sur le portail (lire, télécharger) et bouton « Manuel » dans chaque mission, qui ouvre le PDF à la procédure de la transaction affichée.
* Les PDF sont copiés dans un dossier privé au nom aléatoire et servis après contrôle des droits : le manuel de l'enseignant n'est jamais accessible aux apprentis. Un administrateur peut déposer une version plus récente d'un manuel et revenir à la version livrée.

= 1.9.0 =
* Kit mini-entrepôt : pour chaque scénario, fiche de montage, étiquettes d'emplacements avec chiffre de contrôle, cartes d'articles (EAN-13, lot, date limite) en nombre égal au stock physique (écarts cachés compris), cartes des arrivages (avaries marquées) et étiquettes des bacs de préparation.
* Option de scénario « chiffre de contrôle » : chaque prélèvement est confirmé avec les deux chiffres imprimés sur l'étiquette de l'emplacement (écran, terminal et smartphone).
* Prélèvement : impossible de confirmer plus que la quantité physiquement présente (écart caché) ; l'apprenti doit déclarer le manque.
* Interventions en direct de l'enseignant (un apprenti ou toute la classe) : le client modifie sa commande (quantité, article ajouté ou supprimé, date ; refusé si la livraison existe déjà), rupture (stock bloqué par la qualité, prélevé par un autre service ou manquant caché), nouvelle commande urgente prioritaire. Notification à l'apprenti, avis de modification dans les documents logistiques, journal des interventions.
* Tableau d'indicateurs de fin de mission, par apprenti et par classe : temps actif, saisies réussies et refusées, productivité au prélèvement, commandes livrées, urgences et respect des priorités, réception, inventaires, rythme de travail et imprévus.
* Les étiquettes, documents et kits sont produits à partir d'une session de référence intacte (stock du départ du scénario).

= 1.8.0 =
* Groupes Sensei Pro : une classe se crée à partir d'un groupe Sensei (nom et année repris) ou s'y lie. Ses élèves sont inscrits et la classe suit le groupe : ajout et retrait à l'ouverture de la classe, à la connexion de l'élève et toutes les heures. Les inscriptions manuelles sont conservées ; LogiERP ne modifie jamais Sensei. Favoris partagés avec LogiProgress et LogiExam.
* Documents logistiques : chaque scénario produit ses documents papier imprimables (A4) — commande d'achat, bulletin de livraison du fournisseur avec écarts, lots, dates limites et SSCC, lettre de voiture CMR, commande du client avec tampon de saisie, ordre de fabrication — et une fiche de distribution. Accès enseignant (scénario, assignation) et apprenti (sa session).
* La lettre de voiture CMR est identique dans toutes les sessions d'un scénario.

= 1.7.0 =
* Document d'activité : chaque scénario a sa fiche de mission imprimable (mission, objectifs, cadre,
  documents, étapes à cocher avec la transaction et la procédure du manuel, barème, remarques de
  l'enseignant). Créée avec le scénario et mise à jour à chaque modification. Accessible à l'enseignant
  (scénario, classe) et aux apprentis (portail et accueil de la session, avec les vrais numéros de documents).
  Options du scénario : afficher ou non le barème, remarques de l'enseignant.
* Nouveau menu « Étiquettes » : planches A4 d'étiquettes code-barres lisibles par le terminal mobile –
  articles (EAN-13), emplacements (Code 128, flèche de niveau, emplacements fixes), palettes et colis
  des arrivages d'un scénario (étiquette logistique GS1 complète avec SSCC), n° de commandes d'achat et
  étiquettes libres. Formats 70×37, 105×74, A6 et A5 ; position de départ pour les planches entamées.
* Les SSCC, lots et numéros des documents d'un scénario sont identiques dans toutes les sessions :
  une impression d'étiquettes sert à toute la classe.
* Tous les articles portent un EAN-13 valide et unique : code interne (préfixe 29) attribué
  automatiquement à la création et aux articles existants sans code ; EAN-8 et doublons refusés.
  Fiche article : encadré « Code-barres et étiquette » avec impression directe.
* Éditeur de scénario : paramètres des imprévus et des critères affichés en clair (n° d'article, client…).

= 1.6.1 =
* Terminal mobile : un SSCC saisi à la main (18 chiffres) est toujours reconnu, même s'il commence par 37.
* Retours clients (ZRET) : la décision prend la bonne ligne de stock en quarantaine quand plusieurs
  retours du même article attendent ; après l'enregistrement d'un retour, le formulaire reste ouvert
  avec un lien pour le traiter.
* Inventaire (MI04 / MI07) et retours : le message de confirmation reste affiché après l'action.
* Tableaux de saisie (MIGO, LT12…) : listes déroulantes et champs assez larges pour lire leur contenu.

= 1.6.0 =
* Smartphone : toutes les transactions de l'application sont utilisables sur téléphone, avec une
  mise en page adaptée (activée automatiquement sur les petits écrans tactiles).
* Barre de navigation en bas de l'écran : Transactions (menu plein écran avec recherche),
  Consignes (avec avancement), Terminal (scan) et Remettre.
* Tableaux affichés en fiches, longues listes par pages de 25, formulaires sur une colonne,
  boutons et champs d'au moins 48 px, fenêtres et aides à la recherche (F4) en plein écran.
* Bouton caméra dans chaque champ de code (article, emplacement, commande, livraison, SSCC) :
  le code scanné est reconnu et le bon numéro est saisi.
* Plan de l'entrepôt : liste des magasins en fiches sur téléphone.
* Terminal mobile : le bouton « Toutes les transactions » ouvre l'application complète.

= 1.5.0 =
* Préparation des commandes (VL10) : liste des commandes à livrer avec secteurs de prélèvement,
  poids, volume et palettes estimées ; création groupée des livraisons en série (un préparateur
  par commande, dépôt au quai 700) ou en parallèle (un ordre de prélèvement par secteur et par
  préparateur, dépôt au même emplacement de la zone de regroupement 600).
* Colisage (HU02) : unités d'expédition (palette EUR, CP3, perdue, colis) avec numéro SSCC,
  palettes mixtes, contrôles de charge, de volume, de température et de chargement en commun ADR,
  support exigé par le client, étiquettes d'expédition GS1. Le colisage n'est possible qu'une fois
  tous les secteurs prélevés ; VL02N reprend le nombre d'unités, le support et le poids.
* Terminal mobile : prélèvement filtré par secteur avec dépôt au regroupement ; nouvelle fonction
  « Regroupement / colisage » (scan de la livraison, de l'article, fermeture de l'unité).
* LT12 : secteur, préparateur, livraison et client de chaque ordre.
* Critères de correction « Mode de préparation » et « Commande regroupée et colisée ».
* Nouveau scénario modèle « Préparation en parallèle et palette mixte » (20 au total).

= 1.4.0 =
* Codes de transaction SAP : MIGO, MM03, ME21N, VL01N, VL02N, LT12, MI04… (anciens codes LW-…
  encore acceptés ; saisie « /nCODE » comme dans SAP). Codes Z pour les écrans propres à LogiERP.
* Qualité : lots de contrôle (QA32), échantillon par ordre de transfert, rapport du laboratoire,
  décision d'emploi (QA11) : libération, prolongation après réanalyse, blocage, quarantaine,
  destruction, retour fournisseur. Blocage automatique des lots à date limite atteinte (option du
  scénario) ; liste des dates limites (MB5M) ; mise en quarantaine (LT10 et terminal mobile).
* Planification : liste stock/besoins (MD04), calcul des besoins (MD01, MD02) avec explosion des
  nomenclatures, date de début et contrôle de disponibilité des ordres de fabrication (stock et
  commandes en cours), lancement (CO02), mise à disposition au bord de ligne (LP10).
* Transport : flotte de l'entreprise et véhicules des prestataires, exigences des clients (jours,
  plages horaires, accès, hayon, support de charge), tournées (VT01N) avec itinéraire, contrôles et
  coût, départ et retour (VT02N).
* Emballages consignés : palettes EUR et CP3, cadres, couvercles, fûts, conteneurs IBC, caisses
  navettes ; comptes par client (MB58), mouvements 621 et 622, magasin des vides (950).
* Terminal mobile : déplacer, bloquer, mettre en quarantaine, détruire ; échantillons qualité ;
  mise à disposition production.
* 5 nouveaux scénarios modèles (19 au total).

= 1.3.0 =
* Terminal mobile (MDE) pour smartphone : réception par scan des étiquettes palettes SSCC,
  rangement et prélèvement (scan de l'emplacement puis de l'article EAN), consultation du stock,
  inventaire au rayon, photos des non-conformités. Lecture par la caméra (HTTPS requis),
  par douchette ou au clavier.
* Étiquettes logistiques GS1 (SSCC, GTIN, date limite, quantité) pour chaque palette livrée
  (LW-ETIQ) ; code EAN dans la fiche article, code-barres sur les emplacements.
* Photos ré-encodées sans métadonnées (position GPS supprimée), stockées dans un dossier privé,
  visibles seulement par l'apprenant et ses enseignants, supprimées avec la session.
* Nouveau critère de correction « Non-conformités photographiées » et scénario modèle
  « Réception au terminal mobile : SSCC et photo de l'avarie » (14 scénarios).
* Bibliothèque ZXing (Apache 2.0) incluse pour les téléphones sans lecteur intégré au navigateur.

= 1.2.0 =
* Entreprise fictive (logo, contexte, chiffres clés, sites, centre de distribution, organisation,
  historique, règles) : premier écran de chaque session et page « Entreprise » pour la modifier.
* 30 fournisseurs européens ; codes postaux et téléphones contrôlés selon le pays (saisie et Excel).
* Lettre de voiture CMR (LW-CMR) pour chaque commande d'un fournisseur étranger ; critère de correction
  « CMR signée » ; nouveau scénario « Arrivage international avec lettre de voiture CMR ».
* Chaque article a un stock de départ ; paramètres min. / point de commande / max. cohérents avec le stock.
* Mise à jour sans perte : un site en 1.1.0 complète automatiquement ses données.
* Corrections : listes vides sur les sites sans permaliens, contenus d'écrans manquants, fenêtre F4.

= 1.1.0 =
* Menu LogiERP indépendant dans l'administration.
* Codes courts [logierp_enseignant] et [logierp_eleve].
* Préparation automatique des données à l'activation ; fournisseurs et clients tous situés en Suisse.
* Saisie, import et export Excel des articles, fournisseurs et clients, avec modèle téléchargeable.
* Zones de stockage configurables (critères, limites, plan de l'entrepôt).

= 1.0.0 =
* Première version.
