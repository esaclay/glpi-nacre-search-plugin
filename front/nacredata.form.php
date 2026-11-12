<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/NacreData.php';
require_once dirname(__DIR__) . '/inc/Profile.php';

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Nacresearch\NacreData;
use GlpiPlugin\Nacresearch\Profile;

// Même contrôle que front/config.php : droit d'import NACRES ou config UPDATE.
if (!Session::haveRight(Profile::RIGHT_NACRE, UPDATE) && !Session::haveRight('config', UPDATE)) {
    throw new AccessDeniedHttpException();
}

// CSRF : validé par le noyau GLPI 12 (CheckCsrfListener, en-têtes
// Sec-Fetch-Site / Origin). Rien à faire ici.

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