<?php

declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Glpi\Plugin\Hooks;

require_once __DIR__ . '/hook.php';

define('PLUGIN_NACRESEARCH_VERSION', '1.0.0');
define('PLUGIN_NACRESEARCH_MIN_GLPI', '11.0.0');
define('PLUGIN_NACRESEARCH_MAX_GLPI', '11.0.99');

function plugin_init_nacresearch(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['nacresearch'] = 'public/js/nacre-search.js';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['nacresearch'] = 'public/css/nacre-search.css';
    $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['nacresearch'] = plugin_nacresearch_header_tags();
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
 * Installation du plugin NACRE Search
 */
function plugin_nacresearch_install(): bool
{
    try {
        // GLPI already detected this directory before calling the installer.
        $plugin_dir = __DIR__;
        $data_dir = $plugin_dir . '/public/data';
        $config_dir = $plugin_dir . '/config';

        if (!is_dir($data_dir) && !mkdir($data_dir, 0755, true) && !is_dir($data_dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire des données : %s', $data_dir));
        }

        if (!is_dir($config_dir) && !mkdir($config_dir, 0755, true) && !is_dir($config_dir)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire de configuration : %s', $config_dir));
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
    } catch (RuntimeException $exception) {
        error_log('Erreur lors de l\'installation du plugin NACRE Search: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Désinstallation du plugin NACRE Search
 */
function plugin_nacresearch_uninstall(): bool
{
    return true;
}
