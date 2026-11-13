<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use CommonGLPI;
use Html;
use Session;
use Ticket;

/**
 * Onglet « Lignes NACRES » sur le ticket : consultation pour toute personne qui
 * voit le ticket, modification réservée à Profile::RIGHT_LINES (UPDATE).
 *
 * La description du ticket reste l'instantané de la demande initiale ; cet
 * onglet porte la ventilation validée par l'équipe financière.
 */
final class OrderLineTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Lignes NACRES';
    }

    public static function getIcon()
    {
        return 'ti ti-list-numbers';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem()) {
            return '';
        }

        $count = OrderLines::countForTicket((int) $item->getID());
        if ($count === 0 && !OrderLines::canEdit()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem()) {
            return false;
        }

        self::render($item);

        return true;
    }

    private static function render(Ticket $ticket): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $ticketId = (int) $ticket->getID();
        $lines = OrderLines::forTicket($ticketId);
        $canEdit = OrderLines::canEdit() && $ticket->can($ticketId, READ);
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        echo '<div class="nacresearch-tab p-2">';

        if ($lines === []) {
            echo '<p class="text-muted">Aucune ligne NACRES sur ce ticket.</p>';
        } else {
            // Montant facultatif : colonne affichée seulement si un gestionnaire
            // en a renseigné au moins un.
            $hasAmount = array_filter($lines, static fn (array $line): bool => $line['amount_ht'] !== null) !== [];

            echo '<table class="table table-sm table-hover nacresearch-tab__table">';
            echo '<thead><tr><th>Code</th><th>Libellé NACRE</th><th>Articles concernés</th>'
                . ($hasAmount ? '<th class="text-end">Montant HT</th>' : '')
                . '</tr></thead><tbody>';
            foreach ($lines as $line) {
                echo '<tr>'
                    . '<td class="fw-bold text-nowrap">' . $e($line['code']) . '</td>'
                    . '<td>' . $e(OrderLines::labelFor($line['code'])) . '</td>'
                    . '<td>' . $e($line['designation']) . '</td>'
                    . ($hasAmount
                        ? '<td class="text-end text-nowrap">' . $e(OrderLines::formatMoney($line['amount_ht'])) . '</td>'
                        : '')
                    . '</tr>';
            }
            echo '</tbody>';
            if ($hasAmount) {
                echo '<tfoot><tr><th colspan="3">Total HT renseigné</th>'
                    . '<th class="text-end text-nowrap">' . $e(OrderLines::formatMoney(OrderLines::total($lines))) . '</th>'
                    . '</tr></tfoot>';
            }
            echo '</table>';
        }

        if ($canEdit) {
            $action = $CFG_GLPI['root_doc'] . '/plugins/nacresearch/front/orderlines.form.php';
            echo '<details class="nacresearch-tab__edit mt-2"' . ($lines === [] ? ' open' : '') . '>';
            echo '<summary class="btn btn-sm btn-outline-secondary">Modifier les lignes</summary>';
            echo '<form method="post" action="' . $e($action) . '" class="mt-2">';
            echo '<input type="hidden" name="tickets_id" value="' . $ticketId . '">';
            // Sans JS, le textarea reste éditable au format texte ; avec JS,
            // nacre-orderlines.js le masque et monte l'éditeur de lignes.
            echo '<textarea class="form-control" name="lines" rows="4"'
                . ' data-nacresearch-orderlines-target data-nacresearch-orderlines-show-amount="1">'
                . $e(OrderLines::format($lines))
                . '</textarea>';
            echo '<button type="submit" class="btn btn-primary btn-sm mt-2">Enregistrer les lignes</button>';
            Html::closeForm();
            echo '</details>';
        }

        echo '</div>';
    }
}
