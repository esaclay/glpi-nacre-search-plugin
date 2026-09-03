<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use CommonGLPI;
use Html;
use Session;

final class Profile extends \Profile
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof \Profile && $item->getID() > 0) {
            return self::createTabEntry('NACRES');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof \Profile) {
            return false;
        }

        (new self())->showNacresRights($item->getID());
        return true;
    }

    private function showNacresRights(int $profileId): void
    {
        if (!$this->can($profileId, READ)) {
            return;
        }

        $canEdit = Session::haveRight(self::$rightname, UPDATE);
        if ($canEdit) {
            echo '<form method="post" action="' . htmlspecialchars(self::getFormURL()) . '">';
        }

        $this->displayRightsChoiceMatrix(
            [[
                'itemtype' => self::class,
                'label' => 'Gestion des données NACRES',
                'field' => NacreData::RIGHT_DATA_MANAGEMENT,
            ]],
            [
                'canedit' => $canEdit,
                'title' => 'NACRES',
            ]
        );

        if ($canEdit) {
            echo '<div class="text-center">';
            echo Html::hidden('id', ['value' => $profileId]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
            Html::closeForm();
        }
    }
}
