<?php

declare(strict_types=1);

include '../../../inc/includes.php';
require_once dirname(__DIR__) . '/inc/NacreData.php';
require_once dirname(__DIR__) . '/inc/Profile.php';
require_once dirname(__DIR__) . '/inc/OrderLines.php';

use GlpiPlugin\Nacresearch\OrderLines;

// Pas de Session::checkCSRF() : le noyau GLPI 11 l'a déjà fait (voir
// front/nacredata.form.php).

$ticketId = (int) ($_POST['tickets_id'] ?? 0);
$ticket = new Ticket();

if (
    !OrderLines::canEdit()
    || $ticketId <= 0
    || !$ticket->getFromDB($ticketId)
    || !$ticket->can($ticketId, READ)
) {
    Html::displayNotFoundError();
    exit;
}

$parsed = OrderLines::parse((string) ($_POST['lines'] ?? ''));
$before = OrderLines::forTicket($ticketId);
$after = $parsed['lines'];

if ($parsed['rejected'] !== []) {
    // Rien n'est enregistré tant qu'une ligne est illisible : pas de perte silencieuse.
    Session::addMessageAfterRedirect(
        'Lignes NACRES non enregistrées : ' . implode(' ', $parsed['rejected']),
        false,
        ERROR
    );
} elseif (OrderLines::format($before) !== OrderLines::format($after)) {
    OrderLines::replaceForTicket($ticketId, $after, OrderLines::SOURCE_MANUAL);
    OrderLines::logHistory(
        $ticketId,
        sprintf(
            'Lignes NACRES modifiées : %s → %s',
            OrderLines::summary($before),
            OrderLines::summary($after)
        )
    );
    Session::addMessageAfterRedirect('Lignes NACRES enregistrées.', false, INFO);
}

Html::back();
