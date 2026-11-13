<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionTypeShortAnswer;
use Glpi\Form\QuestionType\QuestionTypeValidationInterface;
use Glpi\Form\QuestionType\RawAnswerIsHtmlInterface;
use Glpi\Form\ValidationResult;
use Override;

/**
 * Type de question « Codes NACRES » pour les formulaires GLPI 12, enregistré
 * par le plugin (QuestionTypesManager::registerPluginQuestionType(), même
 * mécanisme que le plugin Fields).
 *
 * - Saisie : un champ texte (hérité du type « Texte » natif) que
 *   nacre-orderlines.js masque et remplace par l'éditeur de lignes ; il est
 *   repéré par l'attribut `data-nacresearch-codes`, plus par le titre.
 * - Réponse brute : le format texte d'échange d'OrderLines
 *   (`NB.03 | lignes 1 à 7 ; NA.12 | ordinateur portable`).
 * - Rendu (description du ticket, mail, affichage des réponses) : une ligne par
 *   code avec son libellé NACRE — c'est GLPI qui l'insère, d'où un résultat
 *   identique dans le ticket et dans la notification.
 * - Validation serveur : codes de niveau 4 uniquement, mention des articles
 *   obligatoire dès qu'il y a plusieurs codes (même règle que l'éditeur JS).
 *
 * Plugin désactivé : GLPI ignore les questions de type inconnu à l'affichage ;
 * repasser alors la question en type « Texte ».
 */
final class QuestionTypeNacreCodes extends AbstractQuestionTypeShortAnswer implements
    RawAnswerIsHtmlInterface,
    QuestionTypeValidationInterface
{
    #[Override]
    public function getInputType(): string
    {
        return 'text';
    }

    #[Override]
    public function getInputAttributes(): array
    {
        return ['data-nacresearch-codes' => '1'];
    }

    #[Override]
    public function getName(): string
    {
        return 'Codes NACRES';
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-list-search';
    }

    #[Override]
    public function getWeight(): int
    {
        // Juste après « Texte » (10) dans la catégorie « Réponse courte ».
        return 15;
    }

    #[Override]
    public function isAllowedForUnauthenticatedAccess(): bool
    {
        return false;
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        $raw = is_string($answer) ? $answer : '';
        $parsed = OrderLines::parse($raw);
        if ($parsed['lines'] === [] || $parsed['rejected'] !== []) {
            // Réponse inattendue : rendue telle quelle, échappée.
            return htmlescape($raw);
        }

        return implode('<br>', array_map(
            static function (array $line): string {
                $text = $line['code'];
                $label = OrderLines::labelFor($line['code']);
                if ($label !== '') {
                    $text .= ' (' . $label . ')';
                }
                if ($line['designation'] !== '') {
                    $text .= ' – ' . $line['designation'];
                }

                return htmlescape($text);
            },
            $parsed['lines']
        ));
    }

    #[Override]
    public function validateAnswer(Question $question, mixed $answer): ValidationResult
    {
        $result = new ValidationResult(true);
        if (!is_string($answer)) {
            $result->addError($question, 'Valeur inattendue.');
            return $result;
        }

        $parsed = OrderLines::parse($answer);
        if ($parsed['rejected'] !== [] || $parsed['lines'] === []) {
            $result->addError($question, 'Choisissez un code NACRE dans la liste.');
            return $result;
        }

        if (count($parsed['lines']) > 1) {
            foreach ($parsed['lines'] as $line) {
                if ($line['designation'] === '') {
                    $result->addError(
                        $question,
                        'Plusieurs codes : précisez pour chacun les articles concernés.'
                    );
                    break;
                }
            }
        }

        return $result;
    }
}
