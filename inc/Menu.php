<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use CommonGLPI;
use Plugin;
use Session;

/**
 * Entrée de menu latéral (section « Outils ») pour la gestion des données NACRES.
 *
 * Gardée par le droit plugin Profile::RIGHT_NACRE, ce qui permet à un profil
 * (ex. « administratrice financière ») d'atteindre front/config.php sans disposer
 * du droit natif `config` requis par la page Configuration > Plugins.
 */
class Menu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return 'NACRES';
    }

    public static function getIcon(): string
    {
        return 'ti ti-list-search';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Profile::RIGHT_NACRE, READ)
            || Session::haveRight(Profile::RIGHT_NACRE, UPDATE)
            || Session::haveRight('config', UPDATE);
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }

        return [
            'title' => 'Gestion des données NACRES',
            'page'  => Plugin::getWebDir('nacresearch', false) . '/front/config.php',
            'icon'  => self::getIcon(),
        ];
    }
}
