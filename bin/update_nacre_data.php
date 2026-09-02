#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/NacreData.php';

use GlpiPlugin\Nacresearch\NacreData;

$options = getopt('', ['source:', 'target::']);

if (!isset($options['source'])) {
    fwrite(STDERR, "Usage: php bin/update_nacre_data.php --source=/chemin/vers/nacre.json [--target=public/data/nacre.json]\n");
    exit(1);
}

$source = (string) $options['source'];
$target = $options['target'] ?? NacreData::getPublicDataPath();

try {
    $records = NacreData::loadSourceFile($source);
    NacreData::writeData($records, $target);

    fwrite(STDOUT, sprintf("Données NACRE mises à jour dans %s (%d entrées).\n", $target, count($records)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
