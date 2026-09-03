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

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['nacresearch'] = 'js/nacre-search.js';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['nacresearch'] = 'css/nacre-search.css';
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
        // Créer les répertoires s'ils n'existent pas
        $plugin_dir = GLPI_PLUGIN_DIR . '/nacresearch';
        
        if (!is_dir($plugin_dir . '/public/data')) {
            mkdir($plugin_dir . '/public/data', 0755, true);
        }
        
        if (!is_dir($plugin_dir . '/config')) {
            mkdir($plugin_dir . '/config', 0755, true);
        }
        
        // Initialiser les données NACRE si le fichier n'existe pas
        $nacre_data_file = $plugin_dir . '/public/data/nacre.json';
        if (!file_exists($nacre_data_file)) {
            $example_file = $plugin_dir . '/resources/nacre.example.json';
            if (file_exists($example_file)) {
                copy($example_file, $nacre_data_file);
            } else {
                // Créer un fichier vide par défaut
                file_put_contents($nacre_data_file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
        
        // Créer la configuration locale si elle n'existe pas
        $local_config = $plugin_dir . '/config/local.php';
        if (!file_exists($local_config)) {
            $config_content = '<?php' . PHP_EOL . 'return [];' . PHP_EOL;
            file_put_contents($local_config, $config_content);
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
    try {
        // Le plugin ne supprime rien lors de la désinstallation
        // Les données NACRE restent intactes pour réinstallation ultérieure
        return true;
    } catch (Throwable $exception) {
        error_log('Erreur lors de la désinstallation du plugin NACRE Search: ' . $exception->getMessage());
        return false;
    }
}
