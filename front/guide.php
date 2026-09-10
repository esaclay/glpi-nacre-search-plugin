<?php

declare(strict_types=1);

include '../../../inc/includes.php';
require_once dirname(__DIR__) . '/inc/Profile.php';

use GlpiPlugin\Nacresearch\Profile;

// Réservé aux profils financiers (droit lecture « Guide équipe financière »)
// ou aux super-administrateurs.
if (!Session::haveRight(Profile::RIGHT_GUIDE, READ) && !Session::haveRight('config', UPDATE)) {
    Html::displayNotFoundError();
    exit;
}

$guidePath = dirname(__DIR__) . '/docs/guide-equipe-financiere.html';

// Mode « raw » : sert le document autonome, affiché dans l'iframe ci-dessous.
// Passe par ce contrôleur (et non par un accès direct au fichier) pour que la
// vérification de droit s'applique aussi au contenu de l'iframe.
if (isset($_GET['raw'])) {
    $fragment = @file_get_contents($guidePath);
    if ($fragment === false) {
        http_response_code(500);
        echo 'Guide indisponible.';
        exit;
    }

    // Le guide est un document statique de confiance : CSP dédiée (et non celle,
    // plus stricte, de GLPI) pour autoriser le <style> inline et les polices.
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: SAMEORIGIN');
    header_remove('Content-Security-Policy');
    header(
        "Content-Security-Policy: default-src 'none'; "
        . "style-src 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; frame-ancestors 'self'"
    );
    echo "<!doctype html>\n<html lang=\"fr\">\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo $fragment;
    echo "\n</html>\n";
    exit;
}

Html::header('Guide équipe financière', $_SERVER['PHP_SELF'], 'tools', 'GlpiPlugin\Nacresearch\GuideMenu');

$frameSrc = Plugin::getWebDir('nacresearch') . '/front/guide.php?raw=1';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<iframe id="nacresearch-guide-frame"
        src="<?= $escape($frameSrc) ?>"
        title="Guide de l'équipe financière"
        style="width:100%;border:0;min-height:70vh;background:#fff"></iframe>
<?php
echo Html::scriptBlock(<<<'JS'
(function () {
    var frame = document.getElementById('nacresearch-guide-frame');
    if (!frame) {
        return;
    }
    function fit() {
        try {
            var doc = frame.contentWindow.document;
            frame.style.height = Math.max(
                doc.body.scrollHeight,
                doc.documentElement.scrollHeight
            ) + 'px';
        } catch (e) {
            // document inaccessible : on garde la hauteur minimale
        }
    }
    frame.addEventListener('load', fit);
    window.addEventListener('resize', fit);
})();
JS);
Html::footer();
