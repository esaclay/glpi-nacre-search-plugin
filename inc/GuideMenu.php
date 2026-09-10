<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use CommonGLPI;
use Plugin;
use Session;

/**
 * Entrée de menu latéral (section « Outils ») donnant accès au guide
 * d'utilisation de l'instance destiné à l'équipe financière.
 *
 * Gardée par le droit plugin Profile::RIGHT_GUIDE (lecture seule), accordé à
 * l'installation aux profils « Gestionnaire financier » et « Administratrice
 * financière ».
 */
class GuideMenu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return 'Guide équipe financière';
    }

    public static function getIcon(): string
    {
        return 'ti ti-book';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Profile::RIGHT_GUIDE, READ)
            || Session::haveRight('config', UPDATE);
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }

        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('nacresearch', false) . '/front/guide.php',
            'icon'  => self::getIcon(),
        ];
    }
}
