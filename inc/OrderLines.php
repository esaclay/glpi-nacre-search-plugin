<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use InvalidArgumentException;
use Log;
use Session;

/**
 * Lignes de commande NACRES rattachées à un ticket : une ligne = un code NACRE
 * (niveau 4) + une désignation + un montant HT (facultatif en base : les lignes
 * reprises de l'ancien champ Fields n'en ont pas).
 *
 * Format texte d'échange, lisible tel quel dans la description du ticket et
 * relu côté serveur (question « Lignes NACRES » du formulaire, onglet ticket) :
 *
 *     NA.01 | Pipettes | 120,00 € HT ; NB.12 | Gants | 45,50 € HT
 *
 * Le widget JS (nacre-orderlines.js) produit exactement ce format et neutralise
 * les séparateurs `|` et `;` dans les désignations.
 */
final class OrderLines
{
    public const TABLE = 'glpi_plugin_nacresearch_orderlines';

    public const SOURCE_FORM = 'form';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LEGACY = 'legacy';

    private const DESIGNATION_MAX_LENGTH = 255;

    /** @var array<string, string>|null libellés NACRE indexés par code */
    private static ?array $labels = null;

    public static function createTableSql(): string
    {
        return "CREATE TABLE `" . self::TABLE . "` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `code` VARCHAR(10) NOT NULL DEFAULT '',
    `designation` VARCHAR(255) NOT NULL DEFAULT '',
    `amount_ht` DECIMAL(15,2) NULL DEFAULT NULL,
    `source` VARCHAR(20) NOT NULL DEFAULT '',
    `date_creation` TIMESTAMP NULL DEFAULT NULL,
    `date_mod` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    }

    /**
     * Code NACRE commandable (niveau 4 : deux lettres, point, deux chiffres).
     */
    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Z]{2}\.\d{2}$/', $code) === 1;
    }

    /**
     * Découpe le format texte d'échange en lignes. Les fragments vides sont
     * ignorés ; les fragments illisibles (code invalide, montant illisible) sont
     * renvoyés dans `rejected` avec la raison, sans interrompre la lecture.
     *
     * @return array{
     *     lines: list<array{code: string, designation: string, amount_ht: ?string}>,
     *     rejected: list<string>
     * }
     */
    public static function parse(string $text): array
    {
        $lines = [];
        $rejected = [];

        foreach (preg_split('/[;\r\n]+/u', $text) ?: [] as $fragment) {
            $fragment = trim($fragment);
            if ($fragment === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $fragment));
            if (count($parts) > 3) {
                $rejected[] = sprintf('« %s » : trop de séparateurs « | ».', $fragment);
                continue;
            }
            [$code, $designation, $amount] = array_pad($parts, 3, '');

            $code = strtoupper((string) preg_replace('/\s+/u', '', $code));
            if (!self::isValidCode($code)) {
                $rejected[] = sprintf('« %s » : code NACRE « %s » invalide.', $fragment, $code);
                continue;
            }

            try {
                $amountHt = self::parseAmount($amount);
            } catch (InvalidArgumentException $exception) {
                $rejected[] = sprintf('« %s » : %s', $fragment, $exception->getMessage());
                continue;
            }

            $lines[] = [
                'code'        => $code,
                'designation' => mb_substr($designation, 0, self::DESIGNATION_MAX_LENGTH),
                'amount_ht'   => $amountHt,
            ];
        }

        return ['lines' => $lines, 'rejected' => $rejected];
    }

    /**
     * Montant saisi à la française (« 1 234,56 € HT », « 1234.5 », …) → chaîne
     * décimale « 1234.56 », ou null si vide.
     *
     * @throws InvalidArgumentException montant illisible ou non strictement positif
     */
    public static function parseAmount(string $value): ?string
    {
        $clean = (string) preg_replace('/(€|eur(os?)?|ht)/iu', '', $value);
        $clean = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $clean);
        if ($clean === '') {
            return null;
        }

        if (str_contains($clean, ',')) {
            // Virgule décimale : les points éventuels sont des séparateurs de milliers.
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $clean) !== 1) {
            throw new InvalidArgumentException(sprintf('montant « %s » illisible.', trim($value)));
        }
        if ((float) $clean <= 0) {
            throw new InvalidArgumentException('le montant doit être strictement positif.');
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /**
     * @param list<array{code: string, designation: string, amount_ht: ?string}> $lines
     */
    public static function format(array $lines): string
    {
        $chunks = [];
        foreach ($lines as $line) {
            $designation = trim((string) preg_replace('/\s*[|;\r\n]+\s*/u', ', ', (string) $line['designation']));
            $chunk = $line['code'] . ' | ' . $designation;
            if ($line['amount_ht'] !== null && $line['amount_ht'] !== '') {
                $chunk .= ' | ' . number_format((float) $line['amount_ht'], 2, ',', '') . ' € HT';
            }
            $chunks[] = $chunk;
        }

        return implode(' ; ', $chunks);
    }

    public static function formatMoney(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float) $amount, 2, ',', "\u{202F}") . "\u{00A0}€";
    }

    /**
     * @param list<array{amount_ht: ?string}> $lines
     */
    public static function total(array $lines): string
    {
        $cents = 0;
        foreach ($lines as $line) {
            if ($line['amount_ht'] !== null && $line['amount_ht'] !== '') {
                $cents += (int) round((float) $line['amount_ht'] * 100);
            }
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @return list<array{id: int, code: string, designation: string, amount_ht: ?string, source: string}>
     */
    public static function forTicket(int $ticketId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($ticketId <= 0 || !$DB->tableExists(self::TABLE)) {
            return [];
        }

        $lines = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => ['sort_order ASC', 'id ASC'],
        ]) as $row) {
            $lines[] = [
                'id'          => (int) $row['id'],
                'code'        => (string) $row['code'],
                'designation' => (string) $row['designation'],
                'amount_ht'   => $row['amount_ht'] === null ? null : (string) $row['amount_ht'],
                'source'      => (string) $row['source'],
            ];
        }

        return $lines;
    }

    public static function countForTicket(int $ticketId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($ticketId <= 0 || !$DB->tableExists(self::TABLE)) {
            return 0;
        }

        return countElementsInTable(self::TABLE, ['tickets_id' => $ticketId]);
    }

    /**
     * Remplace toutes les lignes du ticket. Écriture directe en base : appelé
     * depuis le hook de soumission du formulaire (session du demandeur, sans
     * droit sur le ticket) comme depuis l'onglet (droit vérifié en amont).
     *
     * @param list<array{code: string, designation: string, amount_ht: ?string}> $lines
     */
    public static function replaceForTicket(int $ticketId, array $lines, string $source): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->delete(self::TABLE, ['tickets_id' => $ticketId]);
        foreach (array_values($lines) as $index => $line) {
            $DB->insert(self::TABLE, [
                'tickets_id'    => $ticketId,
                'sort_order'    => $index,
                'code'          => $line['code'],
                'designation'   => $line['designation'],
                'amount_ht'     => $line['amount_ht'],
                'source'        => $source,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    public static function deleteForTicket(int $ticketId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->delete(self::TABLE, ['tickets_id' => $ticketId]);
        }
    }

    /**
     * Trace dans l'historique du ticket (message simple, 255 caractères max).
     */
    public static function logHistory(int $ticketId, string $message): void
    {
        Log::history(
            $ticketId,
            'Ticket',
            [0, '', mb_substr($message, 0, 255)],
            '',
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }

    /**
     * @param list<array{amount_ht: ?string}> $lines
     */
    public static function summary(array $lines): string
    {
        return sprintf(
            '%d ligne(s), total %s HT',
            count($lines),
            self::formatMoney(self::total($lines))
        );
    }

    /**
     * Libellé NACRE d'un code (pour l'affichage), chaîne vide si inconnu.
     */
    public static function labelFor(string $code): string
    {
        if (self::$labels === null) {
            self::$labels = [];
            try {
                foreach (NacreData::loadSourceFile(NacreData::getPublicDataPath()) as $record) {
                    self::$labels[(string) $record['code']] = (string) ($record['label'] ?? '');
                }
            } catch (\Throwable) {
                // Données NACRE indisponibles : affichage sans libellé.
            }
        }

        return self::$labels[$code] ?? '';
    }

    public static function canEdit(): bool
    {
        return (bool) Session::haveRight(Profile::RIGHT_LINES, UPDATE);
    }
}
