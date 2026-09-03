<?php

declare(strict_types=1);

include '../../../inc/includes.php';
require_once dirname(__DIR__) . '/inc/NacreData.php';

use GlpiPlugin\Nacresearch\NacreData;

Session::checkRight(NacreData::RIGHT_DATA_MANAGEMENT, UPDATE);
Session::checkCSRF($_POST);

try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'import') {
        $records = NacreData::importUploadedWorkbook($_FILES['workbook'] ?? null);
        NacreData::replaceDataWithBackup($records);
        Session::addMessageAfterRedirect(sprintf('%d code(s) NACRES ont été importés.', count($records)), false, INFO);
    } elseif ($action === 'restore') {
        NacreData::restoreBackup((string) ($_POST['backup'] ?? ''));
        Session::addMessageAfterRedirect('La sauvegarde NACRES a été restaurée.', false, INFO);
    } else {
        throw new RuntimeException('Action de gestion des données inconnue.');
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
}

Html::redirect('config.php');
