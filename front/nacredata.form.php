<?php

declare(strict_types=1);

include '../../../inc/includes.php';
require_once dirname(__DIR__) . '/inc/NacreData.php';
require_once dirname(__DIR__) . '/inc/Profile.php';

use GlpiPlugin\Nacresearch\NacreData;
use GlpiPlugin\Nacresearch\Profile;

// Même contrôle que front/config.php : droit d'import NACRES ou config UPDATE.
if (!Session::haveRight(Profile::RIGHT_NACRE, UPDATE) && !Session::haveRight('config', UPDATE)) {
    Html::displayNotFoundError();
    exit;
}

// Pas de Session::checkCSRF() ici : sur GLPI 11 le noyau (CheckCsrfListener) valide
// déjà le jeton pour toute requête POST non-AJAX et le consomme (jeton à usage
// unique). Un second appel échouerait toujours → « L'action que vous avez
// réalisée n'est pas autorisée. » Le plugin déclare csrf_compliant = true.

try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'import') {
        $records = NacreData::importUploadedWorkbook($_FILES['workbook'] ?? null);
        NacreData::replaceDataWithBackup($records);
        Session::addMessageAfterRedirect(sprintf('%d code(s) NACRES ont été importés.', count($records)), false, INFO);
    } elseif ($action === 'backup') {
        NacreData::backupCurrentData();
        Session::addMessageAfterRedirect('Les données NACRES actuelles ont été sauvegardées.', false, INFO);
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