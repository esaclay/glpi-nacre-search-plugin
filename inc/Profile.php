<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use Session;

class Profile extends CommonDBTM
{
    // Clé du droit enregistrée dans la table glpi_profilerights de GLPI
    public const RIGHT_NACRE = 'plugin_nacresearch_data';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof GlpiProfile && $item->getID() > 0) {
            return self::createTabEntry('NACRES');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof GlpiProfile) {
            return false;
        }

        $prof = new self();
        $prof->showNacresRights($item);
        return true;
    }

    private function showNacresRights(GlpiProfile $profile): void
    {
        $canEdit = $profile->canUpdate();

        if ($canEdit) {
            // Formulaire ciblant le contrôleur natif des Profils GLPI
            echo '<form method="post" action="' . htmlspecialchars(GlpiProfile::getFormURL()) . '">';
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        }

        $profile->displayRightsChoiceMatrix(
            [[
                'itemtype' => self::class,
                'label'    => 'Gestion des données NACRES',
                'field'    => self::RIGHT_NACRE,
            ]],
            [
                'canedit' => $canEdit,
                'title'   => 'NACRES',
            ]
        );

        if ($canEdit) {
            echo '<div class="text-center mt-3 mb-3">';
            // name='update' déclenche la mise à jour native dans le profil GLPI
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
    }

    /**
     * Déclaration des droits auprès du noyau GLPI
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => 'Gestion des données NACRES',
                'field'    => self::RIGHT_NACRE,
                'rights'   => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                ],
            ],
        ];
    }
}