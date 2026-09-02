<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use InvalidArgumentException;
use RuntimeException;

final class NacreData
{
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

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException(sprintf('L\'entrée NACRE #%d doit être un objet JSON.', $index));
            }

            $code = trim((string) ($record['code'] ?? ''));
            $label = trim((string) ($record['label'] ?? ''));

            if ($code == '' || $label == '') {
                throw new InvalidArgumentException(sprintf('Chaque entrée NACRE doit contenir un code et un libellé (ligne %d).', $index + 1));
            }

            $keywords = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($record['keywords'] ?? null) ? $record['keywords'] : []
            )));

            $searchParts = [
                mb_strtolower($code),
                mb_strtolower($label),
                mb_strtolower((string) ($record['section'] ?? '')),
                mb_strtolower((string) ($record['division'] ?? '')),
                mb_strtolower((string) ($record['group'] ?? '')),
                mb_strtolower((string) ($record['class'] ?? '')),
                ...array_map(static fn (string $value): string => mb_strtolower($value), $keywords),
            ];

            $normalized[] = [
                'code'     => $code,
                'label'    => $label,
                'section'  => trim((string) ($record['section'] ?? '')),
                'division' => trim((string) ($record['division'] ?? '')),
                'group'    => trim((string) ($record['group'] ?? '')),
                'class'    => trim((string) ($record['class'] ?? '')),
                'keywords' => $keywords,
                'search'   => implode(' ', array_filter($searchParts)),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => strcmp((string) $left['code'], (string) $right['code'])
        );

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function writeData(array $records, string $targetPath): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Impossible de créer le dossier cible : %s', $directory));
        }

        $payload = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($targetPath, $payload . PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Impossible d\'écrire le fichier cible : %s', $targetPath));
        }
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
                'version' => '1.0.0',
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
