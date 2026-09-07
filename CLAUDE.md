# CLAUDE.md

Ce fichier fournit des instructions à Claude Code (claude.ai/code) pour travailler sur ce dépôt.

## Vue d'ensemble

Plugin **GLPI 11** qui injecte une recherche de codes NACRE (nomenclature comptable française) directement dans les formulaires GLPI. Écrit en PHP 8.1+ et JavaScript vanilla, tout le code et la documentation sont en **français**.

- Nom technique du plugin (clé) : `nacresearch`
- Version actuelle : voir `plugin.xml` et `PLUGIN_NACRESEARCH_VERSION` dans `setup.php`
- Compatibilité : GLPI 11.0.0 → 11.0.99, PHP 8.1+
- Instance GLPI de production : https://commandes.lps.u-psud.fr (chemin serveur `/var/www/commandes`)

## Architecture

```
setup.php                          Point d'entrée : hooks, install/uninstall
hook.php                           Fonctions runtime (config, droits, meta tags header)
inc/
  NacreData.php                    Cœur métier (~740 lignes) : config, import Excel, backups, recherche
  Profile.php                      Droits utilisateur (RIGHT_NACRE) — tab UI désactivé volontairement
front/
  config.php                       Interface admin (import / backup / restore)
  nacredata.form.php               Handler des actions d'import/backup/restore
config/
  defaults.php                     Config par défaut (versionnée)
  local.php                        Surcharge locale (non versionnée, générée à l'install)
public/
  js/nacre-search.js               Widget de recherche injecté côté client
  css/nacre-search.css             Styles du widget
  data/nacre.json                  Données NACRE servies au front (générées/importées)
bin/
  configure.php                    CLI : init des données NACRE depuis resources/nacre.example.json
  update_nacre_data.php            CLI : mise à jour des données depuis un JSON externe
  bootstrap_lps_ticket_workflow.php CLI : bootstrap entité/groupe/profils pour le workflow financier LPS
resources/
  nacre.example.json               Exemple de données NACRE
install.sh                         Script de déploiement (copie vers GLPI_PLUGIN_DIR)
```

### Flux de données

1. Admin avec droit `plugin_nacresearch_data` importe un classeur Excel via `/front/config.php`
2. `front/nacredata.form.php` → `NacreData::importUploadedWorkbook()` valide, normalise, archive l'ancien fichier puis écrit `public/data/nacre.json`
3. `hook.php` injecte la config (limite résultats, debounce, libellés) en meta tags HTML via `plugin_nacresearch_header_tags()`
4. `public/js/nacre-search.js` scanne le DOM, détecte les champs pertinents, injecte un bouton, ouvre une modale de recherche filtrée en temps réel, et injecte le code choisi dans le champ cible (`input`/`change` events)

### Détection des champs par le widget JS (`nacre-search.js`)

Le widget cherche les champs `<input>`/`<textarea>` dont le nom/id/placeholder/aria-label/label contient `nacre` (normalisé, sans accents, insensible à la casse). Le scope réel en production : uniquement le formulaire dédié du catalogue de service, actuellement https://commandes.lps.u-psud.fr/Form/Render/3 (l'ID n'est pas codé en dur dans le JS — voir plus bas).

Garde-fous, dans l'ordre de vérification :

1. **`isTicketForm()`** : exclusion rapide par URL (`/Ticket/` nouvelle interface FormRenderer, `/ticket.form.php` ancienne interface). Gardée en défense en profondeur pour l'ancienne interface, mais **insuffisante seule** (voir piège ci-dessous).
2. **`isInsideItilObjectForm(field)`** : garde-fou **autoritaire**, basé sur le DOM et non l'URL. Exclut tout champ situé sous `.itil-object-fields`, `#itil-data`, ou `[id^="plugin_fields_container_"]` (conteneur du plugin GLPI "Fields", id à suffixe aléatoire — sélecteur par préfixe uniquement). Couvre Ticket/Problem/Change quel que soit le chemin d'URL emprunté.
3. **`isInFormCatalog()`** : nécessite `/Form/Render/` dans l'URL.
4. **`getFormRendererQuestion(field)`** : confirmation positive — exige que le champ soit enveloppé dans `[data-glpi-form-renderer-question]` (marqueur d'une vraie question du catalogue). Choix fail-closed : si un futur champ catalogue authentique n'a pas ce wrapper, il n'aura pas le bouton — préférable à l'inverse.

⚠️ **Piège connu (déjà survenu)** : GLPI 11 rend la création de ticket via le **même moteur Form Renderer** que le catalogue de formulaires — l'URL de création de ticket contient `/Form/Render/...` sans jamais contenir `/Ticket/` ni `/ticket.form.php`. Résultat : `isTicketForm()` seul ne suffit pas, `isInFormCatalog()` retourne `true` à tort, et le widget s'active sur un ticket. C'est exactement ce qui s'est produit en prod (champ "Code NACRE" du plugin Fields visible avec bouton de recherche à la création d'un ticket) malgré un premier fix basé uniquement sur l'URL. Le fix définitif ajoute `isInsideItilObjectForm()` (DOM, autoritaire) + `getFormRendererQuestion()` (confirmation positive) — **ne jamais se fier à l'URL seule pour distinguer un ticket d'un formulaire catalogue sur cette instance.**

## Droits et sécurité

- Droit plugin : `plugin_nacresearch_data` (constante `Profile::RIGHT_NACRE`), READ/UPDATE, **désactivé par défaut** pour tous les profils sauf attribution explicite
- L'onglet de gestion des droits dans l'UI Profile est **désactivé intentionnellement** (`inc/Profile.php` → `getTabNameForItem()` retourne `''`) — les droits sont gérés via le système natif GLPI (Administration > Profils), pas via un tab custom
- Import Excel : validations anti zip-bomb, anti-injection de formules, CSRF, taille max 10 Mo upload / 50 Mo décompressé, max 100 entrées ZIP
- Backups : les 5 dernières versions sont conservées dans `config/nacre-backups/` (protégé par `.htaccess`)

## Commandes utiles

```bash
# Initialiser les données NACRE localement (dev)
php bin/configure.php [--source=/path] [--target=/path]

# Mettre à jour les données depuis un JSON externe
php bin/update_nacre_data.php --source=/path/to/nacre.json [--target=/path]

# Bootstrap du workflow financier LPS (production, compte www-data)
runuser -u www-data -- php bin/bootstrap_lps_ticket_workflow.php [--glpi-root=/path]

# Déploiement vers une instance GLPI
GLPI_PLUGIN_DIR=/var/www/html/glpi/plugins ./install.sh
```

### Mise à jour en production

Procédure réelle utilisée sur le serveur de prod, depuis le clone source (`~/nacresearch-src`) :

```bash
cd ~/nacresearch-src
git pull --ff-only
GLPI_PLUGIN_DIR=/var/www/commandes/plugins bash ./install.sh
rm -rf /var/www/commandes/var/cache/*
systemctl restart apache2
```

Chemin GLPI de prod : `/var/www/commandes`. Le vidage du cache + restart Apache sont indispensables après un `install.sh` (sinon PHP/JS/CSS restent en cache).

Il n'y a pas de suite de tests automatisés dans ce dépôt — la vérification se fait manuellement dans une instance GLPI (créer un ticket, tester le widget sur un formulaire, tester import/backup/restore).

## Conventions

- `declare(strict_types=1);` en tête de tous les fichiers PHP
- Espace de noms : `GlpiPlugin\Nacresearch\*`
- Commentaires et libellés utilisateur en français
- Commits git en anglais, style conventionnel (`fix:`, `feat:`, `cleanup:`, `refactor:`)
- Pas de PR habituelle : le flux de travail courant committe directement sur `main` (dépôt personnel, pas d'équipe de review)

## Contexte historique récent

Le plugin a traversé une phase de simplification : une approche par contrôleur Symfony pour la page de gestion NACRES a été abandonnée au profit d'une URL directe simple. L'onglet Profile custom a été désactivé au profit des droits GLPI natifs. `front/profile.form.php` (handler orphelin jamais appelé, référençant une méthode inexistante) a été supprimé.
