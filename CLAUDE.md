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
hook.php                           Fonctions runtime (config, droits, meta tags header, hook item_add Ticket)
inc/
  NacreData.php                    Cœur métier (~740 lignes) : config, import Excel, backups, recherche
  Profile.php                      Droits utilisateur (RIGHT_NACRE, RIGHT_GUIDE) — tab UI désactivé volontairement
  Menu.php                         Entrée « Outils › NACRES » (hook menu_toradd), gardée par RIGHT_NACRE
  GuideMenu.php                    Entrée « Outils › Guide équipe financière » (hook menu_toradd), gardée par RIGHT_GUIDE
  ObserverSync.php                 Hook item_add Ticket : résout/importe depuis LDAP les observateurs saisis en e-mail
front/
  config.php                       Interface admin (import / backup / restore)
  nacredata.form.php               Handler des actions d'import/backup/restore
  guide.php                        Sert docs/guide-equipe-financiere.html dans le chrome GLPI (iframe), gardé par RIGHT_GUIDE
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
docs/
  guide-equipe-financiere.html     Guide d'utilisation prod pour l'équipe financière — servi UNIQUEMENT dans GLPI (front/guide.php). Pas d'export PDF, pas d'artifact.
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

⚠️ **Piège connu (déjà survenu, corrigé et vérifié en prod le 2026-09-07)** : GLPI 11 rend la création de ticket via le **même moteur Form Renderer** que le catalogue de formulaires — l'URL de création de ticket peut contenir `/Form/Render/...` sans jamais contenir `/Ticket/` ni `/ticket.form.php`. Résultat : `isTicketForm()` seul ne suffit pas, `isInFormCatalog()` retourne `true` à tort, et le widget s'active sur un ticket. C'est exactement ce qui s'est produit en prod (champ "Code NACRE" du plugin Fields visible avec bouton de recherche à la création d'un ticket) malgré un premier fix basé uniquement sur l'URL. Le fix définitif ajoute `isInsideItilObjectForm()` (DOM, autoritaire) + `getFormRendererQuestion()` (confirmation positive) — **ne jamais se fier à l'URL seule pour distinguer un ticket d'un formulaire catalogue sur cette instance.** Vérifié en prod : sur `/front/ticket.form.php`, plus aucun `.nacresearch-trigger` ni `[data-nacresearch-enhanced]` injecté (confirmé via console navigateur).

⚠️ **Distinction importante — le CHAMP "Code NACRE" n'est pas le WIDGET** : le champ texte "Code NACRE" visible sur les tickets en production est un champ personnalisé ajouté par le plugin GLPI tiers **"Fields"** (Configurer > Fields), configuré indépendamment sur le type `Ticket` — **ce plugin `nacresearch` ne le crée pas et ne peut pas le supprimer par du code**. Notre widget ne fait qu'ajouter (ou, correctement, ne pas ajouter) un bouton de recherche à côté d'un champ existant dont le nom contient `nacre`.

**Ce champ Fields ne doit PAS être supprimé** : GLPI refuse d'ailleurs la suppression ("Le champ ... ne peut pas être supprimé car il est utilisé dans une question du formulaire : CODE NACRE"). Il est en réalité la **destination de mapping** de la question "Code NACRE" du formulaire de catalogue (`Form/Render/3`) — quand ce formulaire crée un ticket, la valeur saisie est stockée dans ce champ Fields. Le supprimer casserait cette liaison. Décision retenue (2026-09-07) : **laisser le champ tel quel** sur les tickets — il est vide et sans bouton de recherche (le fix ci-dessus suffit), et reste nécessaire au bon fonctionnement du formulaire de catalogue.

⚠️ **Rendre la question "CODE NACRE" obligatoire — limitation GLPI 11** : le bouton « Obligatoire » de l'éditeur de formulaire est **désactivé pour les questions de type « Champ »** (celles liées au plugin Fields), il ne fonctionne que pour les questions texte/liste natives. Contournement retenu (2026-09-09) sur le Form 3 : **condition sur le bouton d'envoi** (Propriétés du formulaire → « Conditions d'affichage du bouton d'envoi » → *Visible si → CODE NACRE → N'est pas vide*) + mention « Obligatoire : … » dans la description de la question. Ne PAS passer le champ Fields lui-même en obligatoire (Configuration > Champs supplémentaires) : ça l'imposerait aussi sur le formulaire ticket côté technicien.

### Observateurs depuis un formulaire de catalogue (`inc/ObserverSync.php`)

Sous-système **serveur** distinct du widget JS. Objectif : permettre au formulaire « Demande d'achat » (`Form/Render/3`) de désigner des observateurs qui seront ajoutés au ticket, y compris des personnes du labo **sans compte GLPI** (jamais connectées via CAS).

Montage :

1. Le formulaire a une question type **E-mail** « Observateurs (adresses mail) » (question unique, non obligatoire). La destination Ticket → Acteurs → Observateurs est réglée sur *« Réponse depuis une question spécifique » → « Observateurs (adresses mail) »* → GLPI ajoute nativement chaque adresse comme observateur **« acteur e-mail »** (`glpi_tickets_users` avec `users_id = 0`, `alternative_email` renseigné, notifications seules). Choix assumé : **un seul champ e-mail**, pas de 2e question « Acteurs » (autocomplétion des comptes existants) — jugé trop lourd pour le gain.
2. Hook `Hooks::ITEM_ADD` sur `Ticket` (`plugin_nacresearch_ticket_add` → `ObserverSync::syncFromTicket()`). Pour chaque observateur acteur-e-mail dont l'adresse appartient au domaine `observers.ldap_email_domain` :
   - compte GLPI trouvé par e-mail (`glpi_useremails`) → on rattache la ligne au compte ;
   - sinon `AuthLDAP::ldapImportUserByServerId(IDENTIFIER_LOGIN = partie locale de l'e-mail, ACTION_IMPORT, serveur)` → import du compte puis rattachement ;
   - introuvable dans l'annuaire (personne hors labo) → **on ne touche à rien**, l'acteur e-mail reste (notifications seules).
   - Le rattachement est une **écriture directe en base** (`$DB->update`/`delete` sur `glpi_tickets_users`) : le hook tourne dans la session du demandeur, qui n'a pas le droit `Ticket_User::update()`. Pas de doublon si la personne est déjà observatrice (question Acteurs native).
3. `ObserverSync::syncFromTicket()` **ne lève jamais** : toute erreur est journalisée via `Toolbox::logInFile('nacresearch', …)` (fichier `files/_log/nacresearch.log`) et la création du ticket se poursuit.

Config (`config/defaults.php` → clé `observers`, surchargeable dans `config/local.php`) :

| Clé | Défaut | Rôle |
|---|---|---|
| `enabled` | `true` | Coupe complètement le hook si `false` |
| `ldap_server_id` | `null` | ID annuaire LDAP GLPI ; `null` ⇒ premier annuaire actif (`is_default` d'abord) |
| `ldap_email_domain` | `universite-paris-saclay.fr` | Seules les adresses de ce domaine sont candidates à l'import ; vide ⇒ toutes |

Prod (vérifié 2026-09-09, cf mémoires `glpi-ldap-cas-config` / `glpi-prod-topology`) : annuaire « Adonis » ID 1, champ identifiant `uid` = `prenom.nom` = identifiant renvoyé par le CAS (donc un compte importé se lie sans doublon à la future connexion CAS). Filtre de connexion LDAP `(departmentNumber=100)` ⇒ seuls les personnels LPS sont importables ; c'est ce filtre qui distingue « collègue labo → compte créé » de « externe → notifications seules ». La règle d'habilitation « Root » (ruleright ID 76) attribue automatiquement le profil Self-Service aux comptes issus du LDAP.

## Droits et sécurité

- Droit plugin : `plugin_nacresearch_data` (constante `Profile::RIGHT_NACRE`), READ/UPDATE, **désactivé par défaut** pour tous les profils sauf attribution explicite. Une **seule** clé pour ce droit — pas d'ancienne `plugin_nacresearch_data_management` (supprimée en 1.3.0, elle n'était jamais lue au runtime).
- Droit plugin : `plugin_nacresearch_guide` (constante `Profile::RIGHT_GUIDE`), **READ seul** — accès au guide d'utilisation. `plugin_nacresearch_register_rights()` (setup.php) fait une **insertion gardée** clé par clé (`getProfileRights` puis `updateProfileRights` sur les seules clés manquantes — surtout pas `ProfileRight::addProfileRights()` qui fait un INSERT sec et casse sur `RIGHT_NACRE` déjà présent après une 1re install), puis accorde `RIGHT_GUIDE` READ aux profils « Gestionnaire financier » et « Administratrice financière ». `RIGHT_NACRE` UPDATE pour l'administratrice reste accordé par le bootstrap ou manuellement. Les deux droits sont retirés à la désinstallation via `deleteProfileRights()`.
- L'onglet de gestion des droits dans l'UI Profile est **désactivé intentionnellement** (`inc/Profile.php` → `getTabNameForItem()` retourne `''`) — les droits sont gérés via le système natif GLPI (Administration > Profils), pas via un tab custom
- Accès à `front/config.php` : deux chemins complémentaires — (1) icône engrenage sur **Configuration > Plugins** via `Hooks::CONFIG_PAGE` (nécessite le droit natif `config`), (2) entrée **Outils > NACRES** via `menu_toradd` + `inc/Menu.php`, gardée par `RIGHT_NACRE` seul (pour les profils type « administratrice financière » sans droit `config`). La page elle-même autorise `RIGHT_NACRE` UPDATE ou `config` UPDATE.
- Accès à `front/guide.php` : entrée **Outils > Guide équipe financière** via `menu_toradd` + `inc/GuideMenu.php`, gardée par `RIGHT_GUIDE` READ (ou `config` UPDATE). La page rend `Html::header()` + une `<iframe>` vers `guide.php?raw=1`, qui applique la **même** vérification de droit avant de servir `docs/guide-equipe-financiere.html` (jamais d'accès direct au fichier sans contrôle).
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

**Si `PLUGIN_NACRESEARCH_VERSION` a changé** : GLPI détecte le changement de version et **désactive le plugin** (warning `Plugin ... version changed` dans les logs) tant que l'update n'est pas lancé. Après `install.sh` :

```bash
runuser -u www-data -- php /var/www/commandes/bin/console plugin:install --force nacresearch
runuser -u www-data -- php /var/www/commandes/bin/console plugin:activate nacresearch
```

`plugin:install --force -vvv` affiche l'erreur si l'install échoue (elle est sinon avalée par le `try/catch` de `plugin_nacresearch_install()` → icône « à mettre à jour » bloquée). Une simple modif d'un fichier runtime (JS/CSS/`docs/*.html`) **sans** bump de version ne nécessite ni `plugin:install` ni `plugin:activate`.

Il n'y a pas de suite de tests automatisés dans ce dépôt — la vérification se fait manuellement dans une instance GLPI (créer un ticket, tester le widget sur un formulaire, tester import/backup/restore).

## Conventions

- `declare(strict_types=1);` en tête de tous les fichiers PHP
- Espace de noms : `GlpiPlugin\Nacresearch\*`
- Commentaires et libellés utilisateur en français
- Commits git en anglais, style conventionnel (`fix:`, `feat:`, `cleanup:`, `refactor:`)
- Pas de PR habituelle : le flux de travail courant committe directement sur `main` (dépôt personnel, pas d'équipe de review)

## Contexte historique récent

Le plugin a traversé une phase de simplification : une approche par contrôleur Symfony pour la page de gestion NACRES a été abandonnée au profit d'une URL directe simple. L'onglet Profile custom a été désactivé au profit des droits GLPI natifs. `front/profile.form.php` (handler orphelin jamais appelé, référençant une méthode inexistante) a été supprimé.

**1.2.0** : ajout du sous-système serveur `ObserverSync` (hook `item_add` sur Ticket) — voir « Observateurs depuis un formulaire de catalogue » ci-dessus. Corrige au passage deux `use` non-composés dans `hook.php` (`use Session;` / `use Throwable;`) qui polluaient `php-errors.log` à chaque chargement.

**1.3.0** : le guide de l'équipe financière est servi directement dans GLPI (`front/guide.php`, entrée **Outils > Guide équipe financière**), réservé aux gestionnaires + administratrice via le nouveau droit lecture `plugin_nacresearch_guide`. Les deux placeholders du guide (emplacement de sauvegarde, contact support) ont été retirés à la demande du labo — l'archivage des pièces reste mentionné comme principe, sans prescrire de procédure. Corrige aussi une incohérence historique : le droit d'import existait sous deux clés (`plugin_nacresearch_data` lu au runtime vs `plugin_nacresearch_data_management` seedé/bootstrapé) ; la seconde est supprimée, tout est unifié sur `Profile::RIGHT_NACRE`.

`docs/guide-equipe-financiere.html` : guide d'utilisation de l'instance de prod pour les profils financiers (accès, formulaire, cycle de vie des tickets, **règle d'archivage des pièces jointes**). Pas de tableau des droits par profil — retiré à la demande du labo (jugé « mal pris »). Fichier fragment (pas de `<!doctype>`/`<head>`) : le `<title>` en tête + `<style>` inline, rendu autonome par un wrapper minimal. **Seul canal de diffusion : GLPI** (`front/guide.php?raw=1`). Décision 2026-09-10 : plus d'export PDF (`docs/guide-equipe-financiere.pdf` supprimé du dépôt), plus d'artifact claude.ai (l'ancien est à supprimer / supprimé dans la galerie claude.ai — non recréé). Le `@media print` du HTML reste pour un Ctrl+P ponctuel.
