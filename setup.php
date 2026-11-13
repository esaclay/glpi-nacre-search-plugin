<?php

declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Glpi\Plugin\Hooks;
use GlpiPlugin\Nacresearch\Profile as NacresearchProfile;

require_once __DIR__ . '/hook.php';
require_once __DIR__ . '/inc/Profile.php';
require_once __DIR__ . '/inc/Menu.php';
require_once __DIR__ . '/inc/GuideMenu.php';
require_once __DIR__ . '/inc/OrderLines.php';
require_once __DIR__ . '/inc/OrderLineSync.php';
require_once __DIR__ . '/inc/OrderLineTab.php';
require_once __DIR__ . '/inc/QuestionTypeNacreCodes.php';

define('PLUGIN_NACRESEARCH_VERSION', '1.7.0');
define('PLUGIN_NACRESEARCH_MIN_GLPI', '12.0.0');
define('PLUGIN_NACRESEARCH_MAX_GLPI', '12.0.99');

function plugin_init_nacresearch(): void
{
    global $PLUGIN_HOOKS;

    // GLPI 12 : plus de hook csrf_compliant ni de jeton CSRF (validation par
    // en-têtes Sec-Fetch-Site / Origin dans le noyau).

    // nacre-search.js d'abord : il expose window.NacreSearch (modale de
    // recherche) utilisé par l'éditeur de lignes nacre-orderlines.js.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['nacresearch'] = [
        'public/js/nacre-search.js',
        'public/js/nacre-orderlines.js',
        'public/js/observer-search.js',
    ];
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['nacresearch'] = [
        'public/css/nacre-search.css',
        'public/css/nacre-orderlines.css',
        'public/css/observer-search.css',
    ];
    $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['nacresearch'] = plugin_nacresearch_header_tags();

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['nacresearch'] = [
        // À la création d'un ticket (via formulaire de catalogue notamment),
        // résout vers un compte GLPI — importé du LDAP si nécessaire — les
        // observateurs saisis en adresse e-mail. Voir ObserverSync.
        'Ticket' => 'plugin_nacresearch_ticket_add',
        // Lien réponses du formulaire ↔ ticket créé : seul moment où les
        // réponses sont accessibles depuis le ticket. Voir OrderLineSync.
        \Glpi\Form\Destination\AnswersSet_FormDestinationItem::class => 'plugin_nacresearch_answers_link_add',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['nacresearch'] = [
        'Ticket' => 'plugin_nacresearch_ticket_purge',
    ];

    Plugin::registerClass(\GlpiPlugin\Nacresearch\OrderLineTab::class, ['addtabon' => ['Ticket']]);

    // Type de question « Codes NACRES » proposé dans l'éditeur de formulaires
    // (catégorie « Réponse courte »). Seulement plugin actif, comme le plugin
    // Fields : sinon GLPI ignore simplement les questions de ce type.
    if (Plugin::isPluginActive('nacresearch')) {
        \Glpi\Form\QuestionType\QuestionTypesManager::getInstance()->registerPluginQuestionType(
            new \GlpiPlugin\Nacresearch\QuestionTypeNacreCodes()
        );
    }

    if (plugin_nacresearch_can_manage_data()) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['nacresearch'] = 'front/config.php';
    }

    // Entrées dans le menu latéral « Outils » :
    //  - NACRES : gestion des données, gardée par RIGHT_NACRE ;
    //  - Guide équipe financière : gardé par RIGHT_GUIDE (lecture).
    // Aucune ne nécessite le droit natif `config`.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['nacresearch'] = [
        'tools' => [
            'GlpiPlugin\Nacresearch\Menu',
            'GlpiPlugin\Nacresearch\GuideMenu',
        ],
    ];
}

function plugin_version_nacresearch(): array
{
    return [
        'name'         => 'NACRE Search',
        'version'      => PLUGIN_NACRESEARCH_VERSION,
        'author'       => 'Jeremie Saen',
        'license'      => 'MIT',
        'homepage'     => 'https://github.com/esaclay/glpi-nacre-search-plugin',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_NACRESEARCH_MIN_GLPI,
                'max' => PLUGIN_NACRESEARCH_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_nacresearch_check_prerequisites(): bool
{
    return plugin_nacresearch_runtime_ready();
}

function plugin_nacresearch_check_config(bool $verbose = false): bool
{
    return plugin_nacresearch_configuration_ready($verbose);
}

/**
 * Enregistre les droits du plugin sur tous les profils (valeur 0 par défaut),
 * puis accorde aux profils financiers le guide en lecture et la modification
 * des lignes NACRES des tickets. Idempotent : rejoué à chaque mise à jour.
 *
 * Le droit d'import (RIGHT_NACRE) reste désactivé par défaut ; il est accordé
 * explicitement à l'administratrice par bin/bootstrap_lps_ticket_workflow.php
 * ou via Administration > Profils.
 */
function plugin_nacresearch_register_rights(): void
{
    $pluginRights = [
        NacresearchProfile::RIGHT_NACRE,
        NacresearchProfile::RIGHT_GUIDE,
        NacresearchProfile::RIGHT_LINES,
    ];

    // Insertion gardée : n'ajoute la clé qu'aux profils qui ne l'ont pas encore.
    // (ProfileRight::addProfileRights fait un INSERT sec qui casse sur un droit
    // déjà présent — ce qui arrive pour RIGHT_NACRE après une première install.)
    $profile = new \Profile();
    foreach (array_keys($profile->find()) as $profileId) {
        $profileId = (int) $profileId;
        $current = \ProfileRight::getProfileRights($profileId);
        $missing = [];
        foreach ($pluginRights as $right) {
            if (!array_key_exists($right, $current)) {
                $missing[$right] = 0;
            }
        }
        if ($missing !== []) {
            \ProfileRight::updateProfileRights($profileId, $missing);
        }
    }

    // Guide en lecture + modification des lignes NACRES pour les deux profils financiers.
    foreach (['Gestionnaire financier', 'Administratrice financière'] as $name) {
        foreach (array_keys($profile->find(['name' => $name])) as $profileId) {
            \ProfileRight::updateProfileRights((int) $profileId, [
                NacresearchProfile::RIGHT_GUIDE => READ,
                NacresearchProfile::RIGHT_LINES => READ | UPDATE,
            ]);
        }
    }
}

/**
 * Installation du plugin NACRE Search
 */
function plugin_nacresearch_install(): bool
{
    try {
        global $DB;

        // Utilisation de la classe Migration standard de GLPI
        $migration = new Migration(PLUGIN_NACRESEARCH_VERSION);

        if (!$DB->tableExists('glpi_plugin_nacresearch_profiles')) {
            $query = "CREATE TABLE `glpi_plugin_nacresearch_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `nacresearch` INT NOT NULL DEFAULT 0,
    KEY `profiles_id` (`profiles_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $migration->addPostQuery($query);
        }

        if (!$DB->tableExists(\GlpiPlugin\Nacresearch\OrderLines::TABLE)) {
            $migration->addPostQuery(\GlpiPlugin\Nacresearch\OrderLines::createTableSql());
        }

        $migration->executeMigration();

        plugin_nacresearch_register_rights();

        $plugin_dir = __DIR__;
        $data_dir = $plugin_dir . '/public/data';
        $config_dir = $plugin_dir . '/config';
        $backup_dir = $plugin_dir . '/config/nacre-backups';

        if (!is_dir($data_dir) && !mkdir($data_dir, 0755, true) && !is_dir($data_dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire des données : %s', $data_dir));
        }

        if (!is_dir($config_dir) && !mkdir($config_dir, 0755, true) && !is_dir($config_dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire de configuration : %s', $config_dir));
        }
        if (!is_dir($backup_dir) && !mkdir($backup_dir, 0750, true) && !is_dir($backup_dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire des sauvegardes : %s', $backup_dir));
        }
        $backupAccessFile = $backup_dir . '/.htaccess';
        if (!file_exists($backupAccessFile) && file_put_contents($backupAccessFile, "Require all denied\n") === false) {
            throw new RuntimeException(sprintf('Impossible de sécuriser le répertoire des sauvegardes : %s', $backup_dir));
        }

        $nacre_data_file = $plugin_dir . '/public/data/nacre.json';
        if (!file_exists($nacre_data_file)) {
            $example_file = $plugin_dir . '/resources/nacre.example.json';
            if (file_exists($example_file)) {
                if (!copy($example_file, $nacre_data_file)) {
                    throw new RuntimeException(sprintf('Impossible d\'initialiser les données NACRE : %s', $nacre_data_file));
                }
            } elseif (file_put_contents($nacre_data_file, "[]\n") === false) {
                throw new RuntimeException(sprintf('Impossible de créer le fichier de données NACRE : %s', $nacre_data_file));
            }
        }

        $local_config = $plugin_dir . '/config/local.php';
        if (!file_exists($local_config)) {
            $config_content = '<?php' . PHP_EOL . 'return [];' . PHP_EOL;
            if (file_put_contents($local_config, $config_content) === false) {
                throw new RuntimeException(sprintf('Impossible de créer la configuration locale : %s', $local_config));
            }
        }

        return true;
    } catch (Throwable $exception) {
        error_log('Erreur lors de l\'installation du plugin NACRE Search: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Désinstallation du plugin NACRE Search
 */
function plugin_nacresearch_uninstall(): bool
{
    global $DB;
    foreach (['glpi_plugin_nacresearch_profiles', \GlpiPlugin\Nacresearch\OrderLines::TABLE] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`;");
        }
    }
    \ProfileRight::deleteProfileRights([
        NacresearchProfile::RIGHT_NACRE,
        NacresearchProfile::RIGHT_GUIDE,
        NacresearchProfile::RIGHT_LINES,
    ]);
    return true;
}