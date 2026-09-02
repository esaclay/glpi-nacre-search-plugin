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
        'author'       => 'Université Paris-Saclay',
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
