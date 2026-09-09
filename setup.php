<?php

declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Glpi\Plugin\Hooks;
use GlpiPlugin\Nacresearch\NacreData;
use GlpiPlugin\Nacresearch\Profile as NacresearchProfile;

require_once __DIR__ . '/hook.php';
require_once __DIR__ . '/inc/Profile.php';
require_once __DIR__ . '/inc/Menu.php';

define('PLUGIN_NACRESEARCH_VERSION', '1.2.0');
define('PLUGIN_NACRESEARCH_MIN_GLPI', '11.0.0');
define('PLUGIN_NACRESEARCH_MAX_GLPI', '11.0.99');

function plugin_init_nacresearch(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['nacresearch'] = true;

    $PLUGIN_HOOKS['rights_information']['nacresearch'] = [
        [
            'itemtype' => 'GlpiPlugin\Nacresearch\Profile',
            'label'    => 'Gestion des données NACRES',
            'field'    => \GlpiPlugin\Nacresearch\Profile::RIGHT_NACRE,
        ]
    ];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['nacresearch'] = 'public/js/nacre-search.js';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['nacresearch'] = 'public/css/nacre-search.css';
    $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['nacresearch'] = plugin_nacresearch_header_tags();

    // À la création d'un ticket (via formulaire de catalogue notamment), résout
    // vers un compte GLPI — importé du LDAP si nécessaire — les observateurs
    // saisis en adresse e-mail. Voir GlpiPlugin\Nacresearch\ObserverSync.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['nacresearch'] = [
        'Ticket' => 'plugin_nacresearch_ticket_add',
    ];

    if (plugin_nacresearch_can_manage_data()) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['nacresearch'] = 'front/config.php';
    }

    // Entrée dans le menu latéral « Outils » : accessible avec le seul droit
    // plugin RIGHT_NACRE, sans le droit natif `config` (page Configuration > Plugins).
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['nacresearch'] = [
        'tools' => 'GlpiPlugin\Nacresearch\Menu',
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

function plugin_nacresearch_ensure_data_management_right(): void
{
    $profiles = new \Profile();
    foreach (array_keys($profiles->find()) as $profileId) {
        $profileId = (int) $profileId;
        $rights = \ProfileRight::getProfileRights($profileId);
        if (!array_key_exists(NacreData::RIGHT_DATA_MANAGEMENT, $rights)) {
            \ProfileRight::updateProfileRights($profileId, [
                NacreData::RIGHT_DATA_MANAGEMENT => 0,
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

        $migration->executeMigration();

        plugin_nacresearch_ensure_data_management_right();

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
    if ($DB->tableExists('glpi_plugin_nacresearch_profiles')) {
        $DB->query("DROP TABLE `glpi_plugin_nacresearch_profiles`;");
    }
    return true;
}