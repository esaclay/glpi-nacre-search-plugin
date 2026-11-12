<?php

declare(strict_types=1);

/**
 * Reprise ponctuelle : copie les codes saisis dans l'ancien champ Fields
 * « Code NACRE » des tickets vers les lignes NACRES (1.6.0).
 *
 * À lancer AVANT de supprimer le champ dans Configuration > Champs
 * supplémentaires (la suppression efface ses valeurs).
 *
 *   runuser -u www-data -- php bin/migrate_legacy_nacre_codes.php --dry-run
 *   runuser -u www-data -- php bin/migrate_legacy_nacre_codes.php
 *
 * Options :
 *   --dry-run        affiche ce qui serait créé, n'écrit rien
 *   --field=NOM      nom technique du champ Fields (sinon : détection par libellé « nacre »)
 *   --glpi-root=DIR  racine GLPI (défaut : deux niveaux au-dessus du plugin)
 *
 * Une ligne par code valide (niveau 4, ex. NA.01) trouvé dans la valeur,
 * désignation « Reprise ancien champ Code NACRE », montant vide (à compléter).
 * Rejouable : les tickets ayant déjà des lignes sont ignorés.
 */

use GlpiPlugin\Nacresearch\OrderLines;

const LEGACY_DESIGNATION = 'Reprise ancien champ Code NACRE';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Ce script doit être exécuté en ligne de commande.');
    }

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('Exécutez ce script avec le compte du serveur web, par exemple www-data.');
    }

    $options = getopt('', ['dry-run', 'field:', 'glpi-root::']);
    $dryRun = array_key_exists('dry-run', $options);
    $pluginRoot = dirname(__DIR__);
    $glpiRoot = isset($options['glpi-root'])
        ? rtrim((string) $options['glpi-root'], DIRECTORY_SEPARATOR)
        : dirname($pluginRoot, 2);
    $autoload = $glpiRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException(sprintf(
            'Autoloader GLPI introuvable dans « %s ». Utilisez --glpi-root=/chemin/vers/glpi.',
            $glpiRoot
        ));
    }

    require_once $autoload;
    $kernel = new \Glpi\Kernel\Kernel();
    $kernel->boot();
    require_once $pluginRoot . '/inc/NacreData.php';
    require_once $pluginRoot . '/inc/Profile.php';
    require_once $pluginRoot . '/inc/OrderLines.php';

    /** @var \DBmysql $DB */
    global $DB;

    if (!$DB->tableExists(OrderLines::TABLE)) {
        throw new RuntimeException('Table des lignes NACRES absente : installez/mettez à jour le plugin en 1.6.0 d\'abord.');
    }
    if (!$DB->tableExists('glpi_plugin_fields_fields')) {
        throw new RuntimeException('Plugin Fields absent : rien à reprendre.');
    }

    // 1. Champ Fields à reprendre.
    $fieldName = isset($options['field']) ? trim((string) $options['field']) : '';
    if ($fieldName === '') {
        $candidates = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_fields']) as $row) {
            if (stripos((string) $row['label'], 'nacre') !== false || stripos((string) $row['name'], 'nacre') !== false) {
                $candidates[] = $row;
            }
        }
        if (count($candidates) !== 1) {
            $list = array_map(
                static fn (array $row): string => sprintf('  - %s (libellé « %s »)', $row['name'], $row['label']),
                $candidates
            );
            throw new RuntimeException(sprintf(
                "%d champ(s) Fields correspondant à « nacre » : précisez --field=NOM.\n%s",
                count($candidates),
                implode("\n", $list)
            ));
        }
        $fieldName = (string) $candidates[0]['name'];
    }
    if (preg_match('/^[a-z0-9_]+$/', $fieldName) !== 1) {
        throw new RuntimeException(sprintf('Nom de champ invalide : %s', $fieldName));
    }

    // 2. Table de valeurs Fields sur Ticket contenant cette colonne.
    $tables = [];
    foreach ($DB->request([
        'SELECT' => ['TABLE_NAME'],
        'FROM'   => 'information_schema.COLUMNS',
        'WHERE'  => [
            'TABLE_SCHEMA' => $DB->dbdefault,
            'COLUMN_NAME'  => $fieldName,
            'TABLE_NAME'   => ['LIKE', 'glpi_plugin_fields_ticket%'],
        ],
    ]) as $row) {
        $tables[] = (string) $row['TABLE_NAME'];
    }
    if (count($tables) !== 1) {
        throw new RuntimeException(sprintf(
            'Table de valeurs du champ « %s » sur Ticket introuvable ou ambiguë (%s).',
            $fieldName,
            $tables === [] ? 'aucune' : implode(', ', $tables)
        ));
    }
    $valueTable = $tables[0];
    // `<> ''` écarte aussi les NULL.
    $where = [$fieldName => ['<>', '']];
    if ($DB->fieldExists($valueTable, 'itemtype')) {
        $where['itemtype'] = 'Ticket';
    }

    printf("Champ « %s », table %s%s.\n\n", $fieldName, $valueTable, $dryRun ? ' — SIMULATION (--dry-run)' : '');

    // 3. Reprise.
    $created = 0;
    $skipped = 0;
    $unrecognized = [];
    foreach ($DB->request([
        'SELECT' => ['items_id', $fieldName],
        'FROM'   => $valueTable,
        'WHERE'  => $where,
        'ORDER'  => 'items_id ASC',
    ]) as $row) {
        $ticketId = (int) $row['items_id'];
        $value = (string) $row[$fieldName];

        if (countElementsInTable('glpi_tickets', ['id' => $ticketId]) === 0) {
            $unrecognized[] = sprintf('ticket %d introuvable (valeur « %s »)', $ticketId, $value);
            continue;
        }
        if (OrderLines::countForTicket($ticketId) > 0) {
            ++$skipped;
            continue;
        }

        // Tolère les saisies libres « GA 04 », « NA24 » (point manquant) ;
        // les catégories (« TA.0 », « SC ») restent non reprises.
        preg_match_all('/(?<![A-Z])([A-Z]{2})\s*\.?\s*(\d{2})(?!\d)/', strtoupper($value), $matches, PREG_SET_ORDER);
        $codes = array_values(array_unique(array_map(
            static fn (array $match): string => $match[1] . '.' . $match[2],
            $matches
        )));
        if ($codes === []) {
            $unrecognized[] = sprintf('ticket %d : « %s »', $ticketId, $value);
            continue;
        }

        $lines = array_map(static fn (string $code): array => [
            'code'        => $code,
            'designation' => LEGACY_DESIGNATION,
            'amount_ht'   => null,
        ], $codes);

        printf("Ticket %d : %s\n", $ticketId, implode(', ', $codes));
        if (!$dryRun) {
            OrderLines::replaceForTicket($ticketId, $lines, OrderLines::SOURCE_LEGACY);
            OrderLines::logHistory(
                $ticketId,
                sprintf('Lignes NACRES reprises de l\'ancien champ Code NACRE : %s', implode(', ', $codes))
            );
        }
        $created += count($lines);
    }

    printf(
        "\n%d ligne(s) %s, %d ticket(s) déjà pourvus ignorés.\n",
        $created,
        $dryRun ? 'à créer' : 'créée(s)',
        $skipped
    );
    if ($unrecognized !== []) {
        printf("\n%d valeur(s) non reprise(s), à traiter à la main :\n", count($unrecognized));
        foreach ($unrecognized as $line) {
            printf("  - %s\n", $line);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erreur : ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
