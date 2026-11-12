# LogiERP

Simulateur ERP/WMS pédagogique pour WordPress (formation logisticien·ne CFC) : achats, réception, entreposage, stock, ventes, préparation, expédition, production, inventaire, retours, avec un espace enseignant pour créer des scénarios et corriger automatiquement.

- WordPress 6.4 ou plus récent, PHP 8.1 ou plus récent, MySQL/MariaDB (InnoDB)
- Licence GPL-2.0-or-later

## Installation

1. Téléchargez `logierp-X.Y.Z.zip` dans la [dernière release](../../releases/latest). N'utilisez pas « Download ZIP » sur la page du dépôt : ce fichier ne contient pas le dossier `logierp/` attendu par WordPress.
2. WordPress › Extensions › Ajouter › Téléverser une extension, puis activez-la. Pour une mise à jour manuelle, choisissez « Remplacer la version installée ».

## Mises à jour

Dès la version 2.3.0, l'extension interroge les releases de ce dépôt. Chaque nouvelle version apparaît dans **Extensions** et dans **Tableau de bord › Mises à jour**. Elle s'installe en un clic, ou automatiquement si vous cliquez sur « Activer les mises à jour auto ». Le lien **Rechercher les mises à jour** (page Extensions ou LogiERP › Réglages) force une vérification immédiate.

Avant toute installation, l'extension vérifie :

- que le paquet vient de `github.com/logiprof/LogiERP`, téléchargé en HTTPS ;
- que son empreinte SHA-256 correspond au fichier `.sha256` publié avec lui ;
- que l'archive contient bien `logierp/logierp.php` avec le numéro de version annoncé.

Si une vérification échoue, la mise à jour est annulée et le site reste intact.

### Dépôt privé

Tant que ce dépôt est privé, chaque site doit disposer d'un jeton GitHub en **lecture seule** :

1. GitHub › Settings › Developer settings › Personal access tokens › **Fine-grained tokens** › Generate new token.
2. Repository access : *Only select repositories* › `logiprof/LogiERP`. Permissions › Repository › **Contents : Read-only**. Rien d'autre.
3. Ajoutez le jeton dans `wp-config.php`, au-dessus de la ligne `/* That's all, stop editing! */` :

   ```php
   define( 'LOGIERP_GITHUB_TOKEN', 'github_pat_...' );
   ```

Le jeton n'est jamais enregistré en base ni affiché. Il n'est envoyé qu'à `api.github.com`. Si le dépôt devient public, supprimez cette ligne.

## Publier une nouvelle version

1. Dans `logierp.php`, mettez à jour `Version:` et `LOGIERP_VERSION`. Dans `readme.txt`, mettez à jour `Stable tag:` et ajoutez une section `= X.Y.Z =` au changelog.
2. Committez, puis créez et poussez l'étiquette :

   ```sh
   git tag v2.3.1
   git push origin main v2.3.1
   ```

3. L'action **Release** contrôle que les numéros concordent et la syntaxe PHP, puis construit `logierp-X.Y.Z.zip` et son empreinte. Elle publie ensuite la release, avec les notes tirées du changelog. Les sites la proposent dans les heures qui suivent (ou tout de suite avec « Rechercher les mises à jour »).
