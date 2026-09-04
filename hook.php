<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/NacreData.php';

use GlpiPlugin\Nacresearch\NacreData;

function plugin_nacresearch_runtime_ready(): bool
{
    return version_compare(PHP_VERSION, '8.1.0', '>=');
}

function plugin_nacresearch_configuration_ready(bool $verbose = false): bool
{
    if (!plugin_nacresearch_runtime_ready()) {
        if ($verbose) {
            echo 'Le plugin NACRE Search nécessite PHP 8.1 ou supérieur.';
        }

        return false;
    }

    try {
        NacreData::loadConfig();
        NacreData::loadSourceFile(NacreData::getPublicDataPath());
    } catch (Throwable $exception) {
        if ($verbose) {
            echo $exception->getMessage();
        }

        return false;
    }

    return true;
}

function plugin_nacresearch_can_manage_data(): bool
{
    return Session::haveRight(NacreData::RIGHT_DATA_MANAGEMENT, UPDATE)
        || Session::haveRight('config', UPDATE);
}

function plugin_nacresearch_header_tags(): array
{
    $config = NacreData::loadConfig();

    return [
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:data-url',
                'content' => '/plugins/nacresearch/public/data/nacre.json?v=' . PLUGIN_NACRESEARCH_VERSION,
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:result-limit',
                'content' => (string) ($config['ui']['result_limit'] ?? 100),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:debounce-ms',
                'content' => (string) ($config['ui']['debounce_ms'] ?? 120),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:button-label',
                'content' => (string) ($config['ui']['button_label'] ?? 'Chercher un code NACRE'),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:modal-title',
                'content' => (string) ($config['ui']['modal_title'] ?? 'Recherche de code NACRE'),
            ],
        ],
    ];
}
