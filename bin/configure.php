#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/NacreData.php';

use GlpiPlugin\Nacresearch\NacreData;

$options = getopt('', ['source::', 'target::']);
$source = $options['source'] ?? dirname(__DIR__) . '/resources/nacre.example.json';
$target = $options['target'] ?? NacreData::getPublicDataPath();

try {
    $configPath = NacreData::ensureLocalConfig();
    $records = NacreData::loadSourceFile($source);
    NacreData::writeData($records, $target);

    fwrite(STDOUT, sprintf("Configuration initialisée.\n- Config : %s\n- Données : %s\n- Entrées : %d\n", $configPath, $target, count($records)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
