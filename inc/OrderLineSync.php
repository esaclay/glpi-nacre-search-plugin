<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AnswersSet_FormDestinationItem;
use Ticket;
use Toolbox;

/**
 * Crée les lignes NACRES d'un ticket à partir de la réponse à la question
 * « Lignes NACRES » du formulaire de catalogue.
 *
 * Branché sur l'ajout du lien réponses ↔ ticket (AnswersSet_FormDestinationItem)
 * et non sur l'ajout du Ticket : au moment du item_add Ticket, GLPI 11 n'a pas
 * encore enregistré ce lien, les réponses sont donc introuvables depuis le
 * ticket. Le lien est ajouté juste après, dans la même transaction
 * (AnswersHandler::saveAnswers()) — d'où l'obligation de ne JAMAIS lever ici :
 * une exception annulerait la soumission entière.
 */
final class OrderLineSync
{
    public static function syncFromLink(AnswersSet_FormDestinationItem $link): void
    {
        $ticketId = 0;
        try {
            $config = NacreData::loadConfig()['order_lines'] ?? [];
            if (($config['enabled'] ?? true) === false) {
                return;
            }

            if (($link->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }
            $ticketId = (int) ($link->fields['items_id'] ?? 0);
            if ($ticketId <= 0) {
                return;
            }

            // Idempotence : ne jamais écraser des lignes déjà présentes.
            if (OrderLines::countForTicket($ticketId) > 0) {
                return;
            }

            $answersSet = new AnswersSet();
            if (!$answersSet->getFromDB((int) ($link->fields['forms_answerssets_id'] ?? 0))) {
                return;
            }

            $hint = self::normalize((string) ($config['field_label_hint'] ?? 'codes nacre'));
            $lines = [];
            $rejected = [];
            foreach ($answersSet->getAnswers() as $answer) {
                if ($hint === '' || !str_contains(self::normalize($answer->getQuestionLabel()), $hint)) {
                    continue;
                }
                $raw = $answer->getRawAnswer();
                if (!is_string($raw)) {
                    continue;
                }
                $parsed = OrderLines::parse($raw);
                $lines = array_merge($lines, $parsed['lines']);
                $rejected = array_merge($rejected, $parsed['rejected']);
            }

            foreach ($rejected as $reason) {
                Toolbox::logInFile('nacresearch', sprintf("Lignes NACRES (ticket %d) ignorée : %s\n", $ticketId, $reason));
            }
            if ($lines === []) {
                return;
            }

            OrderLines::replaceForTicket($ticketId, $lines, OrderLines::SOURCE_FORM);
            OrderLines::logHistory(
                $ticketId,
                'Lignes NACRES créées depuis le formulaire : ' . OrderLines::summary($lines)
            );
        } catch (\Throwable $exception) {
            Toolbox::logInFile(
                'nacresearch',
                sprintf("Lignes NACRES (ticket %d) : %s\n", $ticketId, $exception->getMessage())
            );
        }
    }

    private static function normalize(string $value): string
    {
        // Même normalisation que le widget JS : sans accents, minuscules.
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        $ascii = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $value : $decomposed);

        return mb_strtolower(trim((string) $ascii));
    }
}
