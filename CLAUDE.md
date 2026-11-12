# CLAUDE.md

Ce fichier fournit des instructions à Claude Code (claude.ai/code) pour travailler sur ce dépôt.

## Vue d'ensemble

Plugin **GLPI 12** (prod passée de 11 à 12.0.2 en novembre 2026) qui injecte une recherche de codes NACRE (nomenclature comptable française) directement dans les formulaires GLPI. Écrit en PHP 8.1+ et JavaScript vanilla, tout le code et la documentation sont en **français**.

- Nom technique du plugin (clé) : `nacresearch`
- Version actuelle : voir `plugin.xml` et `PLUGIN_NACRESEARCH_VERSION` dans `setup.php`
- Compatibilité : GLPI 12.0.0 → 12.0.99 depuis 1.6.0 (≤ 1.5.0 : GLPI 11). GLPI 12 exige PHP 8.3+. Les mécanismes Forms utilisés (AnswersSet, lien AnswersSet_FormDestinationItem, rendu des questions) sont identiques entre 11 et 12.0.2 (vérifié dans les sources).
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
  LdapObserverSearch.php           Recherche LDAP live (nom/prénom/e-mail) pour aider à saisir les observateurs — pas d'écriture, pas d'import
  OrderLines.php                   Lignes NACRES d'un ticket (table, format texte d'échange, parse/format, historique)
  OrderLineSync.php                Hook item_add AnswersSet_FormDestinationItem : crée les lignes depuis la question « Lignes NACRES »
  OrderLineTab.php                 Onglet « Lignes NACRES » sur Ticket (consultation + modification gardée par RIGHT_LINES)
front/
  config.php                       Interface admin (import / backup / restore)
  nacredata.form.php               Handler des actions d'import/backup/restore
  guide.php                        Sert docs/guide-equipe-financiere.html dans le chrome GLPI (iframe), gardé par RIGHT_GUIDE
  observer-search.php              Endpoint JSON (GET, utilisateur authentifié) pour la recherche live d'observateurs
  orderlines.form.php              Handler POST de l'onglet « Lignes NACRES » (RIGHT_LINES UPDATE)
config/
  defaults.php                     Config par défaut (versionnée)
  local.php                        Surcharge locale (non versionnée, générée à l'install)
public/
  js/nacre-search.js               Widget de recherche injecté côté client ; expose window.NacreSearch (modale réutilisable)
  css/nacre-search.css             Styles du widget
  js/nacre-orderlines.js           Éditeur de lignes NACRES (Form 3 + onglet ticket), s'appuie sur window.NacreSearch
  css/nacre-orderlines.css         Styles de l'éditeur
  js/observer-search.js            Widget de recherche live d'observateurs (dropdown, sur le champ e-mail du Form 3)
  css/observer-search.css          Styles du widget
  data/nacre.json                  Données NACRE servies au front (générées/importées)
bin/
  configure.php                    CLI : init des données NACRE depuis resources/nacre.example.json
  update_nacre_data.php            CLI : mise à jour des données depuis un JSON externe
  bootstrap_lps_ticket_workflow.php CLI : bootstrap entité/groupe/profils pour le workflow financier LPS
  migrate_legacy_nacre_codes.php   CLI ponctuel (1.6.0) : reprise de l'ancien champ Fields « Code NACRE » vers les lignes
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

### Hiérarchie des codes NACRES (depuis 1.4.0)

Les données NACRES sont hiérarchiques. Le niveau se déduit **uniquement de la forme du code** (`NacreData::codeLevel()`), les codes étant déjà validés par `isValidCode()` (`^[A-Z]{1,2}(?:\.\d{1,2})?$`) :

| Forme | Exemple | `level` | `selectable` | Sens |
|---|---|---|---|---|
| 1 lettre | `N` | 1 | `false` | catégorie principale |
| 2 lettres | `NA` | 2 | `false` | sous-catégorie |
| 2 lettres + `.` + 1 chiffre | `NA.0` | 3 | `false` | sous-sous-catégorie |
| 2 lettres + `.` + 2 chiffres | `NA.01` | 4 | `true` | **code NACRE commandable** |

`makeRecord()` ajoute `level` (int 1-4) et `selectable` (bool, `level === 4`) à chaque entrée de `nacre.json`. Seuls les codes de niveau 4 sont sélectionnables dans la modale ; les catégories s'affichent en contexte, colorées, non cliquables.

**Lignes « NE PLUS UTILISER »** (libellé contenant cette mention, souvent avec le code de remplacement — ex. `AD.44 NE PLUS UTILISER AD.32`) : **filtrées à l'import** par `isDeprecatedLabel()`, dans `normalizeRecords()` **et** `recordsFromRows()`. Jamais écrites dans `nacre.json`, jamais proposées. Le widget JS a en plus un garde-fou `isDeprecated()` (regex sur le libellé) au cas où un `nacre.json` non reprocessé en contiendrait encore. Prod : ~36 lignes retirées (2230 → 2194).

**Rendu de la modale** (`renderResults()` dans `nacre-search.js`) : le plafond `result_limit` ne s'applique qu'aux codes (toutes les catégories correspondant à la recherche restent visibles) ; liséré de couleur par **famille** (lettre de tête, teinte HSL générée) sur toutes les lignes ; fond teinté + indentation par **niveau** sur les catégories (`.nacresearch-modal__result--level-{1..4}` dans `nacre-search.css`) + badge « Catégorie ». Helpers JS (`codeLevel`/`isSelectable`) tolérants : repli sur la forme du code si `level`/`selectable` absents du JSON.

Reprocesser un `nacre.json` existant à travers la nouvelle logique : `php bin/update_nacre_data.php --source=<nacre.json> --target=<nacre.json>` (ou réimporter le classeur Excel).

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

### Lignes de commande NACRES (depuis 1.6.0)

Besoin : un devis / achat groupé couvre **plusieurs codes NACRES** ; l'ancien modèle « un champ Fields = un code » ne suffisait pas. Une demande porte désormais N lignes **code (niveau 4) + désignation + montant HT**.

- **Form 3** : question native **texte court** « Lignes NACRES » (obligatoire), libellé contenant `order_lines.field_label_hint` (`lignes nacre`). Pas « texte long » : GLPI 11 force TinyMCE/HTML sur ce type, sans option texte brut. `nacre-orderlines.js` masque le champ (visuellement, il reste focusable) et monte un tableau éditable (code via la modale de `nacre-search.js`, désignation, montant, total HT). Le champ reçoit le **format texte d'échange** `NA.01 | Pipettes | 120,00 € HT ; NB.12 | Gants | 45,50 € HT` — lisible tel quel dans la description du ticket, relu par `OrderLines::parse()`. Ligne incomplète ⇒ champ vidé ⇒ la question obligatoire bloque l'envoi (jamais de ligne perdue en silence). `|`/`;` neutralisés dans les désignations.
- **Création des lignes** : ⚠️ au `item_add` **Ticket**, GLPI 11 n'a pas encore lié le ticket à ses réponses (`AnswersHandler::createDestinations()` ajoute le lien `Glpi\Form\Destination\AnswersSet_FormDestinationItem` juste **après** le ticket, dans la même transaction que `saveAnswers()`). `OrderLineSync` est donc branché sur `item_add` de **ce lien** : `forms_answerssets_id` → `AnswersSet::getAnswers()` → question dont le libellé contient l'indice. **Ne jamais lever** (une exception annulerait toute la soumission) : erreurs dans `nacresearch.log`. Idempotent (rien si le ticket a déjà des lignes).
- **Stockage** : `glpi_plugin_nacresearch_orderlines` (`tickets_id`, `sort_order`, `code`, `designation`, `amount_ht` DECIMAL NULL, `source` = `form`/`manual`/`legacy`, dates). Purge du ticket ⇒ purge des lignes (hook `item_purge`). La description du ticket reste l'**instantané de la demande initiale** ; l'onglet porte la ventilation validée.
- **Onglet « Lignes NACRES »** (`OrderLineTab`, `addtabon` Ticket) : visible si le ticket a des lignes ou si l'utilisateur a `RIGHT_LINES`. Édition (même éditeur JS, montant facultatif, textarea brut sans JS) → `front/orderlines.form.php` : tout ou rien (une ligne illisible ⇒ rien n'est enregistré), trace `Log::HISTORY_LOG_SIMPLE_MESSAGE` dans l'historique du ticket.
- **Montant** : obligatoire à la saisie du formulaire (JS), `NULL` autorisé en base pour les lignes reprises (« à compléter »).
- **Cumuls par code/année** : pas d'UI (décision 2026-10-08), la table suffit le moment venu — à filtrer sur les tickets non refusés/annulés ; l'outil comptable reste la référence.
- **Reprise de l'ancien champ** : `bin/migrate_legacy_nacre_codes.php [--dry-run] [--field=NOM]` détecte le champ Fields par libellé `nacre`, retrouve sa table de valeurs via `information_schema`, crée une ligne par code `XX.NN` trouvé (désignation « Reprise ancien champ Code NACRE », montant vide), ignore les tickets déjà pourvus, liste les valeurs non reconnues. **À lancer avant de supprimer le champ Fields.**

### Observateurs depuis un formulaire de catalogue (`inc/ObserverSync.php`)

Sous-système **serveur** distinct du widget JS. Objectif : permettre au formulaire « Demande d'achat » (`Form/Render/3`) de désigner des observateurs qui seront ajoutés au ticket, y compris des personnes du labo **sans compte GLPI** (jamais connectées via CAS).

Montage :

1. Le formulaire a une question type **E-mail** « Observateurs (adresses mail) » (question unique, non obligatoire). La destination Ticket → Acteurs → Observateurs est réglée sur *« Réponse depuis une question spécifique » → « Observateurs (adresses mail) »* → GLPI ajoute nativement chaque adresse comme observateur **« acteur e-mail »** (`glpi_tickets_users` avec `users_id = 0`, `alternative_email` renseigné, notifications seules). Choix assumé : **un seul champ e-mail**, pas de 2e question « Acteurs » (autocomplétion des comptes existants uniquement — voir « Recherche LDAP live » ci-dessous pour pourquoi ce ne serait pas suffisant) — jugé trop lourd pour le gain.
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

### Recherche LDAP live pour le champ Observateurs (`inc/LdapObserverSearch.php`, depuis 1.5.0)

Problème résolu : une personne du labo qui ne s'est **jamais connectée via CAS** n'a pas encore de compte GLPI, donc introuvable par une recherche de compte GLPI existant — l'utilisateur devait deviner son adresse e-mail exacte pour la question « Observateurs (adresses mail) ». Deux pistes écartées : synchro LDAP quotidienne de tout le labo (comptes dormants, pas de garde-fou humain pour exclure les comptes techniques) ; 2e question native GLPI « Acteurs » (ne cherche que parmi les comptes déjà existants, ne couvre pas le cas réel, ferait doublon avec la recherche ci-dessous sans réduire le travail).

- `public/js/observer-search.js` : widget séparé de `nacre-search.js` (mêmes garde-fous de détection de formulaire, réutilisés à l'identique). Repère les `input[type="email"]` dont le nom/label contient `observateur` (config `observer_search.field_label_hint`), interroge `front/observer-search.php` à chaque frappe (débouncée, `AbortController` pour annuler une requête en vol), affiche un dropdown de candidats (nom/prénom/e-mail). Comportement de sélection adapté à `field.multiple` (attribut HTML natif) : si `true` (champ e-mail multi-valeurs), ajoute l'adresse au fragment en cours de saisie (texte après le dernier `, `), sinon **remplace** simplement la valeur du champ ciblé. **Vérifié en prod (2026-09-16) : le champ actuel n'a pas `multiple`** — validation navigateur « Veuillez saisir un e-mail valide » sur une valeur avec virgule. Le widget est donc en mode « remplace », un seul observateur à la fois.
- **Pas d'option native « plusieurs réponses » pour ce type de question** : vérifié dans le code source du cœur GLPI 11 (`Glpi\Form\QuestionType\QuestionTypeEmail` → `AbstractQuestionTypeShortAnswer`, `validateAnswer()` n'accepte qu'une seule adresse — aucune config multi-valeurs n'existe pour ce type). Le type « Acteurs » natif, lui, supporte bien un mode multi-valeurs (`is_multiple_actors`, voir `AbstractQuestionTypeActors`), mais uniquement pour des comptes GLPI déjà existants (`User`/`Group`/`Supplier`, pas d'e-mail libre) — ne résout donc pas le cas des personnes jamais connectées. **Seule voie native pour plusieurs observateurs : dupliquer la question e-mail** (ex. « Observateur supplémentaire 1/2 », non obligatoires) et, dans Destinations → Ticket → Acteurs → Observateurs, sélectionner **toutes** ces questions comme sources (le champ accepte un tableau de questions, pas une seule — `ObserverFieldConfig::SPECIFIC_QUESTION_IDS` est un array). Le widget s'attache déjà automatiquement à chaque question dupliquée (scan par label contenant `observateur`) et `ObserverSync` traite déjà toutes les lignes acteur-e-mail du ticket sans se soucier de la question d'origine — **aucun changement de code nécessaire** pour ce point, seulement une reconfiguration du Form 3.
- ⚠️ **Piège en dupliquant une question sur Form 3** : le champ Destinations → Ticket → **Contenu** (description) est en mode auto-généré par défaut et liste toutes les questions du formulaire (`Glpi\Form\Destination\CommonITILField\ContentField::getAutoGeneratedConfig()`, un tag `##answer_XX##` par question). Ajouter une question dupliquée l'y fait apparaître aussi, donc l'adresse observateur se retrouve en double dans la description du ticket. Fix : dans Destinations → Ticket → Contenu, retirer manuellement les tags des questions observateurs (garder le reste).
- `front/observer-search.php` : endpoint JSON en GET (`?q=`), accessible à **tout utilisateur authentifié** (`Session::getLoginUserID()` seul — `RIGHT_NACRE`/`RIGHT_GUIDE` sont réservés à un usage admin/finance et ne conviennent pas à un demandeur lambda soumettant le formulaire catalogue). Longueur minimale de requête vérifiée côté serveur (pas seulement JS).
- `LdapObserverSearch::search()` : recherche pure, **ne crée ni ne modifie aucun compte** — l'import reste exclusivement géré par `ObserverSync` à la soumission, inchangé. Réutilise l'annuaire configuré par `observers.ldap_server_id` (même clé que `ObserverSync`, pas de duplication). Connexion via `AuthLDAP::connect()` (méthode d'instance du cœur, gère TLS et déchiffrement du mot de passe de lecture). Filtre : `(&<condition-connexion-du-serveur>(sn=*)(!(uid=tel.*))(|(uid=*terme*)(sn=*terme*)(givenname=*terme*)(mail=*terme*)))`, terme échappé via `ldap_escape(…, LDAP_ESCAPE_FILTER)`. Le filtre de connexion du serveur (`(departmentNumber=100)` en prod) fait déjà le tri labo/hors-labo ; `sn=*` + exclusion `uid=tel.*` écartent en plus les comptes techniques (téléphones du LDAP université, sans nom de famille — confirmé sur un extrait LDIF réel en prod).
- Config (`config/defaults.php` → clé `observer_search`) : `enabled`, `min_query_length` (2), `result_limit` (10), `debounce_ms` (250), `field_label_hint` (`observateur`), `placeholder`, `empty_hint`.

## Droits et sécurité

- Droits plugin : `plugin_nacresearch_data` (`Profile::RIGHT_NACRE`, READ/UPDATE — import/backup/restore), `plugin_nacresearch_guide` (`Profile::RIGHT_GUIDE`, READ seul — accès au guide) et `plugin_nacresearch_lines` (`Profile::RIGHT_LINES`, UPDATE — modification des lignes NACRES d'un ticket ; accordé à l'install aux deux profils financiers, comme le guide). Les droits sont chargés en session à la connexion : reconnexion nécessaire après l'install. **Une seule clé par droit** : l'ancienne `plugin_nacresearch_data_management` (supprimée en 1.3.0, jamais lue au runtime) n'existe plus.
- **Ces droits n'ont AUCUN onglet dans l'UI Profil** (Administration > Profils). `inc/Profile.php` ne contient plus que les deux constantes ; le scaffolding d'onglet (`getTabNameForItem`/`displayTabContentForItem`/`getAllRights`) et le faux hook `$PLUGIN_HOOKS['rights_information']` (qui n'existe pas dans GLPI — 0 occurrence dans le cœur) ont été retirés en 1.3.0. Attribution :
  - à l'installation : `plugin_nacresearch_register_rights()` (setup.php) — **insertion gardée** clé par clé (`getProfileRights` puis `updateProfileRights` sur les seules clés manquantes ; **pas** `ProfileRight::addProfileRights()`, qui fait un INSERT sec et casse sur une clé déjà présente), puis `RIGHT_GUIDE` READ pour « Gestionnaire financier » et « Administratrice financière » ;
  - `RIGHT_NACRE` UPDATE pour l'administratrice : `bin/bootstrap_lps_ticket_workflow.php` ;
  - ponctuel : `bin/console` ou SQL direct sur `glpi_profilerights`.
  - Désinstallation : `deleteProfileRights()` sur les deux clés.
- Accès à `front/config.php` : deux chemins complémentaires — (1) icône engrenage sur **Configuration > Plugins** via `Hooks::CONFIG_PAGE` (nécessite le droit natif `config`), (2) entrée **Outils > NACRES** via `menu_toradd` + `inc/Menu.php`, gardée par `RIGHT_NACRE` seul (pour les profils type « administratrice financière » sans droit `config`). La page elle-même autorise `RIGHT_NACRE` UPDATE ou `config` UPDATE.
- Accès à `front/guide.php` : entrée **Outils > Guide équipe financière** via `menu_toradd` + `inc/GuideMenu.php`, gardée par `RIGHT_GUIDE` READ (ou `config` UPDATE). La page rend `Html::header()` + une `<iframe>` vers `guide.php?raw=1`, qui applique la **même** vérification de droit avant de servir `docs/guide-equipe-financiere.html` (jamais d'accès direct au fichier sans contrôle).
- Import Excel : validations anti zip-bomb, anti-injection de formules, CSRF, taille max 10 Mo upload / 50 Mo décompressé, max 100 entrées ZIP
- **CSRF sur GLPI 12 : aucun code CSRF dans le plugin.** GLPI 12 a supprimé les jetons (`_glpi_csrf_token`, `Session::getNewCSRFToken()`/`checkCSRF()`/`validateCSRF()`, hook `csrf_compliant`) : le noyau valide chaque POST par les en-têtes navigateur `Sec-Fetch-Site` / `Origin` (`Glpi\Http\CsrfChecker`). Ne pas réintroduire de champ jeton ni d'appel CSRF. (Historique GLPI 11 : le noyau consommait déjà le jeton, un `checkCSRF()` explicite échouait toujours — bug corrigé en 1.3.2.)
- **Contrôle d'accès dans `front/*.php`** : `throw new \Glpi\Exception\Http\AccessDeniedHttpException()` (`Html::displayNotFoundError()` n'existe plus en GLPI 12). Pas d'`include inc/includes.php` (obsolète), `$CFG_GLPI` à déclarer `global` (les fichiers front sont inclus dans une portée de fonction par le noyau). URL du plugin : `$CFG_GLPI['root_doc'] . '/plugins/nacresearch/...'` (`Plugin::getWebDir()` supprimé).
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

⚠️ **Mais un fichier JS/CSS modifié sans bump de version peut rester caché côté navigateur.** GLPI ajoute `?v=<version_du_plugin>` à l'URL du script/style (`Html::script()`/`Html::css()`, cœur GLPI — le paramètre vient de `PLUGIN_NACRESEARCH_VERSION`, pas d'un hash de fichier ni d'un mtime). Sans bump, l'URL ne change pas : un navigateur qui a déjà mis le fichier en cache continue de servir l'ancienne version après un `install.sh` + vidage cache serveur + restart Apache, jusqu'à un rechargement forcé (Ctrl+Shift+R) côté client. Vécu en 1.5.0 (fix JS non visible tant que le cache navigateur n'était pas vidé manuellement). Pour un correctif JS/CSS distribué à plusieurs utilisateurs (pas juste soi-même en dev), préférer quand même un bump de version (`PLUGIN_NACRESEARCH_VERSION` + `plugin.xml` + `config/defaults.php` clé `plugin.version`) + `plugin:install --force` + `plugin:activate`, pour forcer le rafraîchissement sans dépendre d'un Ctrl+Shift+R de chacun.

Il n'y a pas de suite de tests automatisés dans ce dépôt — la vérification se fait manuellement dans une instance GLPI (créer un ticket, tester le widget sur un formulaire, tester import/backup/restore).

## Conventions

- `declare(strict_types=1);` en tête de tous les fichiers PHP
- Espace de noms : `GlpiPlugin\Nacresearch\*`
- Commentaires et libellés utilisateur en français
- Commits git en anglais, style conventionnel (`fix:`, `feat:`, `cleanup:`, `refactor:`)
- Pas de PR habituelle : le flux de travail courant committe directement sur `main` (dépôt personnel, pas d'équipe de review)

## Contexte historique récent

Le plugin a traversé une phase de simplification : une approche par contrôleur Symfony pour la page de gestion NACRES a été abandonnée au profit d'une URL directe simple. `front/profile.form.php` (handler orphelin jamais appelé, référençant une méthode inexistante) a été supprimé. En 1.3.0, le reste du scaffolding d'onglet Profil (méthodes `getTabNameForItem`/`displayTabContentForItem`/`getAllRights` + faux hook `rights_information`) a lui aussi été retiré : il ne faisait rien (voir « Droits et sécurité »). `inc/Profile.php` = deux constantes.

**1.2.0** : ajout du sous-système serveur `ObserverSync` (hook `item_add` sur Ticket) — voir « Observateurs depuis un formulaire de catalogue » ci-dessus. Corrige au passage deux `use` non-composés dans `hook.php` (`use Session;` / `use Throwable;`) qui polluaient `php-errors.log` à chaque chargement.

**1.3.0 / 1.3.1** (même session de dev) : le guide de l'équipe financière est servi directement dans GLPI (`front/guide.php`, entrée **Outils > Guide équipe financière**), réservé aux gestionnaires + administratrice via le nouveau droit lecture `plugin_nacresearch_guide`. Les deux placeholders du guide (emplacement de sauvegarde, contact support) ont été retirés à la demande du labo — l'archivage des pièces reste mentionné comme principe, sans prescrire de procédure. Corrige aussi deux incohérences historiques : (1) le droit d'import existait sous deux clés (`plugin_nacresearch_data` lu au runtime vs `plugin_nacresearch_data_management` seedé/bootstrapé) → la seconde est supprimée, tout est unifié sur `Profile::RIGHT_NACRE` ; (2) le hook `rights_information` était censé afficher les droits dans Administration > Profils mais n'existe pas dans GLPI → retiré, les droits s'accordent uniquement par script/CLI (l'install accorde le guide aux deux profils finance automatiquement).

**1.4.0** : hiérarchie des codes NACRES (voir « Hiérarchie des codes NACRES » ci-dessus). `nacre.json` gagne `level`/`selectable` par entrée ; seuls les codes à 2 chiffres (`NA.01`) sont sélectionnables dans la modale, les catégories (`N`, `NA`, `NA.0`) s'affichent colorées et non cliquables (fond par niveau + liséré par famille) ; les lignes « NE PLUS UTILISER » sont filtrées à l'import. À déployer avec un reprocessing de `nacre.json` (réimport Excel ou `bin/update_nacre_data.php`).

**1.3.2** : correctif CSRF. « Outils > Gestion des données NACRES » → clic sur *Importer* / *Sauvegarder* / *Restaurer* renvoyait « L'action que vous avez réalisée n'est pas autorisée. » (constaté en prod par le Super-Admin le 2026-09-10). Cause : `front/nacredata.form.php` appelait `Session::checkCSRF($_POST)` alors que le noyau GLPI 11 l'a déjà fait et a consommé le jeton (usage unique). Fix : suppression de cet appel + alignement du contrôle de droit sur `front/config.php` (`RIGHT_NACRE` UPDATE ou `config` UPDATE, au lieu d'un test sur le nom de profil « Administratrice financière »). Voir « Droits et sécurité » → note CSRF.

**1.6.0** : compatibilité **GLPI 12** (la prod est passée en 12.0.2 avant le déploiement de cette version) — retrait des API supprimées (`Plugin::getWebDir()` qui cassait le menu sur toutes les pages, `Html::displayNotFoundError()`, jetons CSRF, `inc/includes.php`), bornes GLPI 12.0.x. ⚠️ Après une MAJ du cœur, GLPI **suspend tous les plugins** tant que `bin/console database:update` n'a pas été lancé (`plugin:install` échoue alors avec « Checking a plugin state is forbidden when plugins execution is suspended ») ; `database:update` les réactive, sinon `plugin:resume_execution`. Plugin Fields : version 1.25.x pour GLPI 12.

Lignes de commande NACRES (voir « Lignes de commande NACRES » ci-dessus) — plusieurs codes par demande, montant HT par ligne, onglet ticket modifiable par les gestionnaires, reprise de l'ancien champ Fields. Déploiement : MAJ plugin → `migrate_legacy_nacre_codes.php --dry-run` puis réel → Form 3 : ajouter la question texte court obligatoire « Lignes NACRES », retirer la question « CODE NACRE » et la condition du bouton d'envoi → supprimer le champ Fields. Les bandeaux du piège ci-dessus sur le champ Fields « Code NACRE » deviennent caducs une fois ce champ supprimé.

`docs/guide-equipe-financiere.html` : guide d'utilisation de l'instance de prod pour les profils financiers (accès, formulaire, cycle de vie des tickets, **règle d'archivage des pièces jointes**). Pas de tableau des droits par profil — retiré à la demande du labo (jugé « mal pris »). Fichier fragment (pas de `<!doctype>`/`<head>`) : le `<title>` en tête + `<style>` inline, rendu autonome par un wrapper minimal. **Seul canal de diffusion : GLPI** (`front/guide.php?raw=1`). Décision 2026-09-10 : plus d'export PDF (`docs/guide-equipe-financiere.pdf` supprimé du dépôt), plus d'artifact claude.ai (l'ancien est à supprimer / supprimé dans la galerie claude.ai — non recréé). Le `@media print` du HTML reste pour un Ctrl+P ponctuel.

**1.5.0** : recherche LDAP live pour le champ Observateurs (voir « Recherche LDAP live pour le champ Observateurs » ci-dessus). N'affecte pas `ObserverSync`/le hook `item_add` : recherche seule, aucune écriture. Testé en prod le 2026-09-16 : la recherche trouve bien les comptes LDAP du labo. Le champ « Observateurs (adresses mail) » s'est avéré **sans** attribut `multiple` (une seule adresse acceptée par la validation navigateur) — l'hypothèse initiale d'un champ délimité par virgule était fausse ; le widget a été corrigé pour se comporter en « remplace » sur un champ simple (`field.multiple` non défini) et ne repasser en mode « ajoute au fragment » que si le champ devient un jour multi-valeurs. Pour permettre plusieurs observateurs, il reste à activer côté GLPI une option « plusieurs réponses » sur la question (à vérifier si elle existe pour ce type).
