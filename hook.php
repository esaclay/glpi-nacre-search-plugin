<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/NacreData.php';
require_once __DIR__ . '/inc/ObserverSync.php';

use GlpiPlugin\Nacresearch\NacreData;
use GlpiPlugin\Nacresearch\ObserverSync;
use GlpiPlugin\Nacresearch\OrderLines;
use GlpiPlugin\Nacresearch\OrderLineSync;
use GlpiPlugin\Nacresearch\Profile;

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
    } catch (\Throwable $exception) {
        if ($verbose) {
            echo $exception->getMessage();
        }

        return false;
    }

    return true;
}

/**
 * Hook item_add sur Ticket : rattache les observateurs saisis en adresse e-mail
 * dans un formulaire de catalogue (import LDAP à la volée si besoin).
 */
function plugin_nacresearch_ticket_add(\Ticket $ticket): void
{
    ObserverSync::syncFromTicket($ticket);
}

/**
 * Hook item_add sur le lien réponses de formulaire ↔ élément créé : crée les
 * lignes NACRES du ticket depuis la question « Lignes NACRES ».
 */
function plugin_nacresearch_answers_link_add(\Glpi\Form\Destination\AnswersSet_FormDestinationItem $link): void
{
    OrderLineSync::syncFromLink($link);
}

/**
 * Hook item_purge sur Ticket : supprime les lignes NACRES du ticket purgé.
 */
function plugin_nacresearch_ticket_purge(\Ticket $ticket): void
{
    OrderLines::deleteForTicket((int) $ticket->getID());
}

function plugin_nacresearch_can_manage_data(): bool
{
    if (!\Session::getLoginUserID()) {
        return false;
    }

    // Alignement sur le droit natif Profile::RIGHT_NACRE (Mettre à jour ou Créer)
    return \Session::haveRight(Profile::RIGHT_NACRE, UPDATE)
        || \Session::haveRight(Profile::RIGHT_NACRE, CREATE)
        || \Session::haveRight('config', UPDATE);
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
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:orderlines-hint',
                'content' => ($config['order_lines']['enabled'] ?? true) === false
                    ? ''
                    : (string) ($config['order_lines']['field_label_hint'] ?? 'lignes nacre'),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-url',
                'content' => '/plugins/nacresearch/front/observer-search.php',
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-min-length',
                'content' => (string) ($config['observer_search']['min_query_length'] ?? 2),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-result-limit',
                'content' => (string) ($config['observer_search']['result_limit'] ?? 10),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-debounce-ms',
                'content' => (string) ($config['observer_search']['debounce_ms'] ?? 250),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-field-hint',
                'content' => (string) ($config['observer_search']['field_label_hint'] ?? 'observateur'),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-placeholder',
                'content' => (string) ($config['observer_search']['placeholder'] ?? ''),
            ],
        ],
        [
            'tag'        => 'meta',
            'properties' => [
                'name'    => 'nacresearch:observer-search-empty-hint',
                'content' => (string) ($config['observer_search']['empty_hint'] ?? ''),
            ],
        ],
    ];
}