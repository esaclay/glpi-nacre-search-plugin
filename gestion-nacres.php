<?php

declare(strict_types=1);

include './inc/includes.php';
require_once __DIR__ . '/inc/NacreData.php';

use GlpiPlugin\Nacresearch\NacreData;

// Autoriser UNIQUEMENT: Super Admin OU profil "Administratrice financière"
$user = new User();
$user->getFromDB(Session::getLoginUserID());
if ($user->getID() > 0) {
    $profile = new Profile();
    $profile->getFromDB($user->fields['profiles_id']);
    $isAuthorized = ($profile->fields['name'] === 'Administratrice financière')
        || Session::haveRight('config', UPDATE);
    if (!$isAuthorized) {
        Html::displayNotFoundError();
        exit;
    }
}

Html::header('Gestion des données NACRES', $_SERVER['PHP_SELF'], 'config', 'plugins');

try {
    $backups = NacreData::listBackups();
} catch (Throwable $exception) {
    $backups = [];
    Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formAction = Plugin::getWebDir('nacresearch') . '/front/nacredata.form.php';

// Génération unique d'un jeton CSRF valide pour toute la page
$csrfToken = Session::getNewCSRFToken();
?>
<div class="center">
    <h2>Importer les données NACRES</h2>
    <p>Le classeur doit être un fichier Excel <code>.xlsx</code> avec une unique feuille nommée « N ».</p>

    <!-- Formulaire Import -->
    <form method="post" action="<?= $escape($formAction) ?>" enctype="multipart/form-data">
       <input type="hidden" name="_glpi_csrf_token" value="<?= $escape($csrfToken) ?>">
       <input type="hidden" name="action" value="import">
       <input type="file" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
       <button class="btn btn-primary ms-2" type="submit">Importer le classeur</button>
    </form>

    <!-- Formulaire Sauvegarde -->
    <div class="mt-2">
    <form method="post" action="<?= $escape($formAction) ?>">
       <input type="hidden" name="_glpi_csrf_token" value="<?= $escape($csrfToken) ?>">
       <input type="hidden" name="action" value="backup">
       <button class="btn btn-outline-secondary" type="submit">Sauvegarder les données actuelles</button>
    </form>
    </div>

    <h2 class="mt-4">Sauvegardes disponibles</h2>
    <?php if ($backups === []) : ?>
       <p>Aucune sauvegarde disponible.</p>
    <?php else : ?>
       <table class="tab_cadre_fixehov">
          <thead><tr><th>Date</th><th>Taille</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($backups as $backup) : ?>
             <tr>
                <td><?= $escape($backup['date']) ?></td>
                <td><?= $escape($backup['size']) ?></td>
                <td>
                   <form method="post" action="<?= $escape($formAction) ?>" onsubmit="return confirm('Restaurer cette sauvegarde et remplacer les données NACRES actuelles ?');">
                      <input type="hidden" name="_glpi_csrf_token" value="<?= $escape($csrfToken) ?>">
                      <input type="hidden" name="action" value="restore">
                      <input type="hidden" name="backup" value="<?= $escape($backup['name']) ?>">
                      <button class="btn btn-outline-secondary" type="submit">Restaurer</button>
                   </form>
                </td>
             </tr>
          <?php endforeach; ?>
          </tbody>
       </table>
    <?php endif; ?>
</div>
<?php
Html::footer();
