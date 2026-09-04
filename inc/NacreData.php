<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class NacreData
{
    public const RIGHT_DATA_MANAGEMENT = 'plugin_nacresearch_data_management';
    private const MAX_UPLOAD_SIZE = 10485760;
    private const MAX_ZIP_FILES = 100;
    private const MAX_UNCOMPRESSED_SIZE = 52428800;
    private const MAX_ENTRY_SIZE = 31457280;
    private const BACKUP_LIMIT = 5;

    public static function getPluginRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function getDefaultsPath(): string
    {
        return self::getPluginRoot() . '/config/defaults.php';
    }

    public static function getLocalConfigPath(): string
    {
        return self::getPluginRoot() . '/config/local.php';
    }

    public static function getPublicDataPath(): string
    {
        return self::getPluginRoot() . '/public/data/nacre.json';
    }

    public static function getBackupDirectory(): string
    {
        return self::getPluginRoot() . '/config/nacre-backups';
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadConfig(): array
    {
        $defaults = require self::getDefaultsPath();
        $localPath = self::getLocalConfigPath();

        if (!is_array($defaults)) {
            throw new RuntimeException('Le fichier de configuration par défaut est invalide.');
        }

        if (!file_exists($localPath)) {
            return $defaults;
        }

        $local = require $localPath;
        if (!is_array($local)) {
            throw new RuntimeException('Le fichier de configuration locale est invalide.');
        }

        return array_replace_recursive($defaults, $local);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function loadSourceFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Le fichier JSON NACRE est introuvable ou illisible : %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Impossible de lire le fichier : %s', $path));
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Le contenu JSON est invalide : %s', $path));
        }

        return self::normalizeRecords($decoded);
    }

    /**
     * @param array<int, mixed> $records
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeRecords(array $records): array
    {
        $normalized = [];
        $codes = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException(sprintf('L\'entrée NACRE #%d doit être un objet JSON.', $index));
            }

            $code = self::normalizeCode((string) ($record['code'] ?? ''));
            $label = trim((string) ($record['label'] ?? ''));
            if ($code === '' || $label === '') {
                throw new InvalidArgumentException(sprintf('Chaque entrée NACRE doit contenir un code et un libellé (ligne %d).', $index + 1));
            }
            if (!self::isValidCode($code)) {
                throw new InvalidArgumentException(sprintf('Le code NACRE « %s » est invalide (ligne %d).', $code, $index + 1));
            }
            if (isset($codes[$code])) {
                throw new InvalidArgumentException(sprintf('Le code NACRE « %s » est présent plusieurs fois.', $code));
            }
            $codes[$code] = true;

            $keywords = self::uniqueValues(
                is_array($record['keywords'] ?? null) ? $record['keywords'] : []
            );
            $section = trim((string) ($record['section'] ?? self::sectionFromCode($code)));
            $division = trim((string) ($record['division'] ?? ''));
            $group = trim((string) ($record['group'] ?? ''));
            $class = trim((string) ($record['class'] ?? ''));

            $normalized[] = self::makeRecord($code, $label, $section, $division, $group, $class, $keywords);
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => strcmp((string) $left['code'], (string) $right['code'])
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $upload
     *
     * @return list<array<string, mixed>>
     */
    public static function importUploadedWorkbook(?array $upload): array
    {
        if ($upload === null || !isset($upload['error'], $upload['name'], $upload['tmp_name'], $upload['size'])) {
            throw new InvalidArgumentException('Aucun classeur n’a été envoyé.');
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('L’envoi du classeur Excel a échoué.');
        }

        $name = (string) $upload['name'];
        $temporaryPath = (string) $upload['tmp_name'];
        $size = (int) $upload['size'];
        if (
            !preg_match('/^[\pL\pN][\pL\pN ._-]{0,120}\.xlsx$/ui', $name)
            || $size < 1
            || $size > self::MAX_UPLOAD_SIZE
            || !is_uploaded_file($temporaryPath)
        ) {
            throw new InvalidArgumentException('Le fichier doit être un classeur .xlsx valide de 10 Mo maximum.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!in_array($mime, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ], true)) {
            throw new InvalidArgumentException('Le contenu envoyé n’est pas un classeur Excel .xlsx.');
        }

        return self::importWorkbook($temporaryPath);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function importWorkbook(string $path): array
    {
        $nativeRows = self::readNativeWorkbook($path);

        if (class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
            try {
                $nativeRows = self::readPhpSpreadsheetWorkbook($path);
            } catch (\Throwable $exception) {
                throw new RuntimeException('Le classeur Excel ne peut pas être lu : ' . $exception->getMessage());
            }
        }

        return self::recordsFromRows($nativeRows);
    }

    /**
     * @param array<int, array<int, string>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function recordsFromRows(array $rows): array
    {
        self::validateWorkbookHeaders($rows[4] ?? []);

        $records = [];
        $codes = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= 4) {
                continue;
            }

            $rawCode = trim($row[1] ?? '');
            $label = trim($row[3] ?? '');
            if ($rawCode === '' && $label === '') {
                continue;
            }

            $code = self::normalizeCode($rawCode);
            if (!self::isValidCode($code) || $label === '') {
                continue;
            }
            if (strtoupper(trim($row[13] ?? '')) === 'X') {
                continue;
            }
            if (isset($codes[$code])) {
                throw new InvalidArgumentException(sprintf('Le code NACRE « %s » est présent plusieurs fois (ligne %d).', $code, $rowNumber));
            }
            $codes[$code] = true;

            $division = trim($row[2] ?? '');
            $nature = trim($row[12] ?? '');
            $accounts = [];
            for ($column = 4; $column <= 11; ++$column) {
                $accounts[] = $row[$column] ?? '';
            }
            $keywords = self::uniqueValues(array_merge(
                $division === '' ? [] : [$division],
                $nature === '' ? [] : [$nature],
                $accounts
            ));
            $records[] = self::makeRecord(
                $code,
                $label,
                self::sectionFromCode($code),
                $division,
                $nature,
                '',
                $keywords
            );
        }

        if ($records === []) {
            throw new InvalidArgumentException('Le classeur ne contient aucun code NACRE exploitable.');
        }

        usort($records, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));
        return $records;
    }

    /**
     * @param array<int, string> $headers
     */
    private static function validateWorkbookHeaders(array $headers): void
    {
        $expectedHeaders = [
            1 => 'codes nacres',
            3 => 'intitul',
            13 => 'inactif',
        ];
        foreach ($expectedHeaders as $column => $expectedHeader) {
            $header = self::asciiSearch(trim($headers[$column] ?? ''));
            if (!str_contains($header, $expectedHeader)) {
                throw new InvalidArgumentException(sprintf(
                    'La structure du classeur est invalide : en-tête « %s » attendu en colonne %s, ligne 4.',
                    $expectedHeader,
                    self::columnName($column)
                ));
            }
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function readPhpSpreadsheetWorkbook(string $path): array
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['N']);
        $spreadsheet = $reader->load($path);
        if ($spreadsheet->getSheetCount() !== 1 || $spreadsheet->getActiveSheet()->getTitle() !== 'N') {
            throw new InvalidArgumentException('Le classeur doit contenir une unique feuille nommée « N ».');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = min($sheet->getHighestDataRow(), 100000);
        if ($highestRow < 4) {
            throw new InvalidArgumentException('La feuille « N » ne contient pas la ligne d’en-têtes 4.');
        }

        $rows = [];
        foreach ($sheet->rangeToArray('A1:N' . $highestRow, null, true, false, false) as $rowIndex => $values) {
            foreach ($values as $column => $value) {
                $rows[$rowIndex + 1][$column] = is_scalar($value) ? trim((string) $value) : '';
            }
        }
        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    /**
     * Native reader: it only obtains scalar cell text from a constrained XLSX zip/XML package.
     *
     * @return array<int, array<int, string>>
     */
    private static function readNativeWorkbook(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive est requis pour importer un classeur Excel.');
        }
        if (file_get_contents($path, false, null, 0, 4) !== "PK\x03\x04") {
            throw new InvalidArgumentException('Le contenu envoyé n’est pas une archive XLSX valide.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('L’archive XLSX est invalide.');
        }

        try {
            self::validateZip($zip);
            $workbook = self::readXml($zip, 'xl/workbook.xml');
            $relationships = self::readXml($zip, 'xl/_rels/workbook.xml.rels');
            $sheetPath = self::findNacreSheetPath($workbook, $relationships);
            $sharedStrings = self::readSharedStrings($zip);
            return self::readSheetRows(self::readXml($zip, $sheetPath), $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private static function validateZip(ZipArchive $zip): void
    {
        if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ZIP_FILES) {
            throw new InvalidArgumentException('La structure de l’archive XLSX est invalide.');
        }

        $totalSize = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                throw new InvalidArgumentException('La structure de l’archive XLSX est invalide.');
            }
            $name = (string) $stat['name'];
            $size = (int) $stat['size'];
            $compressedSize = (int) $stat['comp_size'];
            if (
                $name === '' || str_contains($name, '\\') || str_contains($name, '..') || str_contains($name, "\0")
                || $size < 0 || $size > self::MAX_ENTRY_SIZE
                || ($compressedSize > 0 && $size / $compressedSize > 100)
            ) {
                throw new InvalidArgumentException('L’archive XLSX contient une entrée non autorisée.');
            }
            $totalSize += $size;
            if ($totalSize > self::MAX_UNCOMPRESSED_SIZE) {
                throw new InvalidArgumentException('Le classeur Excel est trop volumineux après décompression.');
            }
        }
    }

    private static function findNacreSheetPath(SimpleXMLElement $workbook, SimpleXMLElement $relationships): string
    {
        $main = $workbook->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $main->sheets->sheet ?? null;
        if ($sheets === null || count($sheets) !== 1) {
            throw new InvalidArgumentException('Le classeur doit contenir une unique feuille nommée « N ».');
        }
        $sheet = $sheets[0];
        $relationshipsAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($relationshipsAttributes['id'] ?? '');
        if ((string) ($sheet['name'] ?? '') !== 'N' || $relationshipId === '') {
            throw new InvalidArgumentException('Le classeur doit contenir une unique feuille nommée « N ».');
        }

        $rels = $relationships->children('http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->Relationship as $relationship) {
            if (
                (string) ($relationship['Id'] ?? '') === $relationshipId
                && str_ends_with((string) ($relationship['Type'] ?? ''), '/worksheet')
            ) {
                $target = (string) ($relationship['Target'] ?? '');
                if ($target === '' || str_contains($target, '..') || str_contains($target, '\\') || str_starts_with($target, '/')) {
                    break;
                }
                return 'xl/' . ltrim($target, '/');
            }
        }
        throw new InvalidArgumentException('La feuille « N » ne peut pas être trouvée dans le classeur.');
    }

    /**
     * @return list<string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $xml = self::readXml($zip, 'xl/sharedStrings.xml');
        $main = $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($main->si as $item) {
            $strings[] = self::xmlText($item);
        }
        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return array<int, array<int, string>>
     */
    private static function readSheetRows(SimpleXMLElement $sheet, array $sharedStrings): array
    {
        $main = $sheet->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($main->sheetData->row as $rowNode) {
            $rowNumber = (int) ($rowNode['r'] ?? 0);
            if ($rowNumber < 1 || $rowNumber > 100000) {
                throw new InvalidArgumentException('Le classeur contient un numéro de ligne invalide.');
            }
            foreach ($rowNode->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                if (!preg_match('/^([A-Z]{1,3})\d+$/', $reference, $matches)) {
                    throw new InvalidArgumentException('Le classeur contient une référence de cellule invalide.');
                }
                $column = self::columnIndex($matches[1]);
                if ($column > 13) {
                    continue;
                }
                if (isset($cell->f)) {
                    throw new InvalidArgumentException('Les cellules de formule ne sont pas acceptées dans le classeur NACRES.');
                }
                $type = (string) ($cell['t'] ?? 'n');
                if ($type === 's') {
                    $sharedIndex = (int) $cell->v;
                    if (!array_key_exists($sharedIndex, $sharedStrings)) {
                        throw new InvalidArgumentException('Le classeur contient une chaîne partagée invalide.');
                    }
                    $value = $sharedStrings[$sharedIndex];
                } elseif ($type === 'inlineStr') {
                    $value = self::xmlText($cell->is);
                } elseif ($type === 'str' || $type === 'n' || $type === '') {
                    $value = (string) ($cell->v ?? '');
                } else {
                    throw new InvalidArgumentException('Le classeur contient un type de cellule non pris en charge.');
                }
                $rows[$rowNumber][$column] = trim($value);
            }
        }
        return $rows;
    }

    private static function readXml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false || str_contains($content, '<!DOCTYPE') || str_contains($content, '<!ENTITY')) {
            throw new InvalidArgumentException(sprintf('Le composant XLSX « %s » est invalide.', $name));
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                throw new InvalidArgumentException(sprintf('Le XML XLSX « %s » est invalide.', $name));
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function xmlText(?SimpleXMLElement $element): string
    {
        if ($element === null) {
            return '';
        }
        $namespaces = $element->getDocNamespaces(true);
        $element->registerXPathNamespace('main', $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $nodes = $element->xpath('.//main:t');
        if ($nodes === false || $nodes === []) {
            return (string) $element;
        }
        return implode('', array_map(static fn (SimpleXMLElement $node): string => (string) $node, $nodes));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function replaceDataWithBackup(array $records): void
    {
        self::replaceFileAtomically(self::normalizeRecords($records), self::getPublicDataPath(), true);
    }

    public static function backupCurrentData(): void
    {
        $path = self::getPublicDataPath();
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Les données NACRES actuelles sont introuvables ou illisibles.');
        }

        self::archiveCurrentData($path);
        self::pruneBackups();
    }

    public static function restoreBackup(string $backupName): void
    {
        if (!preg_match('/^nacre-\d{8}_\d{6}-[a-f0-9]{16}\.json$/', $backupName)) {
            throw new InvalidArgumentException('Sauvegarde NACRES invalide.');
        }
        $backupPath = self::getBackupDirectory() . DIRECTORY_SEPARATOR . $backupName;
        if (!is_file($backupPath) || !is_readable($backupPath)) {
            throw new InvalidArgumentException('La sauvegarde NACRES demandée est introuvable.');
        }
        self::replaceDataWithBackup(self::loadSourceFile($backupPath));
    }

    /**
     * @return list<array{name: string, date: string, size: string}>
     */
    public static function listBackups(): array
    {
        $directory = self::getBackupDirectory();
        if (!is_dir($directory)) {
            return [];
        }
        $backups = [];
        foreach (new \DirectoryIterator($directory) as $file) {
            if (
                !$file->isFile()
                || !preg_match('/^nacre-\d{8}_\d{6}-[a-f0-9]{16}\.json$/', $file->getFilename())
            ) {
                continue;
            }
            $backups[] = [
                'name' => $file->getFilename(),
                'date' => date('Y-m-d H:i:s', $file->getMTime()),
                'size' => number_format($file->getSize() / 1024, 1, ',', ' ') . ' Ko',
                'mtime' => $file->getMTime(),
            ];
        }
        usort($backups, static fn (array $left, array $right): int => $right['mtime'] <=> $left['mtime']);
        return array_map(static fn (array $backup): array => [
            'name' => $backup['name'],
            'date' => $backup['date'],
            'size' => $backup['size'],
        ], $backups);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function writeData(array $records, string $targetPath): void
    {
        self::replaceFileAtomically(self::normalizeRecords($records), $targetPath, false);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private static function replaceFileAtomically(array $records, string $targetPath, bool $archiveCurrent): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Impossible de créer le dossier cible : %s', $directory));
        }
        $payload = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporaryPath = tempnam($directory, '.nacre-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Impossible de préparer les données NACRES.');
        }

        try {
            if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Impossible de préparer les données NACRES.');
            }
            if ($archiveCurrent && is_file($targetPath)) {
                self::archiveCurrentData($targetPath);
            }
            if (!rename($temporaryPath, $targetPath)) {
                throw new RuntimeException('Impossible de remplacer les données NACRES.');
            }
            self::pruneBackups();
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private static function archiveCurrentData(string $sourcePath): void
    {
        self::loadSourceFile($sourcePath);
        $directory = self::getBackupDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier des sauvegardes NACRES.');
        }
        $name = sprintf('nacre-%s-%s.json', gmdate('Ymd_His'), bin2hex(random_bytes(8)));
        $destination = $directory . DIRECTORY_SEPARATOR . $name;
        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException('Impossible d’archiver les données NACRES actuelles.');
        }
        @chmod($destination, 0640);
    }

    private static function pruneBackups(): void
    {
        $backups = self::listBackups();
        foreach (array_slice($backups, self::BACKUP_LIMIT) as $backup) {
            $path = self::getBackupDirectory() . DIRECTORY_SEPARATOR . $backup['name'];
            if (!unlink($path)) {
                throw new RuntimeException('Impossible de supprimer une ancienne sauvegarde NACRES.');
            }
        }
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($code)));
    }

    private static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Z]{1,2}(?:\.\d{1,2})?$/', $code) === 1;
    }

    private static function sectionFromCode(string $code): string
    {
        return explode('.', $code, 2)[0];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !isset($unique[$value])) {
                $unique[$value] = true;
            }
        }
        return array_keys($unique);
    }

    /**
     * @param list<string> $keywords
     *
     * @return array<string, mixed>
     */
    private static function makeRecord(
        string $code,
        string $label,
        string $section,
        string $division,
        string $group,
        string $class,
        array $keywords
    ): array {
        $searchParts = array_merge([$code, $label, $section, $division, $group, $class], $keywords);
        return [
            'code' => $code,
            'label' => $label,
            'section' => $section,
            'division' => $division,
            'group' => $group,
            'class' => $class,
            'keywords' => $keywords,
            'search' => self::asciiSearch(implode(' ', array_filter($searchParts, static fn (string $value): bool => $value !== ''))),
        ];
    }

    private static function asciiSearch(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        }
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $ascii)));
    }

    private static function columnName(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $name;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return $index - 1;
    }

    public static function ensureLocalConfig(): string
    {
        $localPath = self::getLocalConfigPath();
        if (file_exists($localPath)) {
            return $localPath;
        }

        $config = [
            'plugin' => [
                'name' => 'nacresearch',
                'version' => '1.1.0',
            ],
            'ui' => [
                'selector_hint' => 'nacre',
                'result_limit' => 100,
                'debounce_ms' => 120,
            ],
            'data' => [
                'source' => 'public/data/nacre.json',
            ],
        ];

        $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($localPath, $export) === false) {
            throw new RuntimeException(sprintf('Impossible d\'écrire la configuration locale : %s', $localPath));
        }

        return $localPath;
    }
}
