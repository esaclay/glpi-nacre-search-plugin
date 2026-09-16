<?php

declare(strict_types=1);

include '../../../inc/includes.php';
require_once dirname(__DIR__) . '/inc/NacreData.php';
require_once dirname(__DIR__) . '/inc/LdapObserverSearch.php';

use GlpiPlugin\Nacresearch\LdapObserverSearch;
use GlpiPlugin\Nacresearch\NacreData;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Accessible à tout utilisateur authentifié soumettant le formulaire
// catalogue (pas un droit d'administration) : recherche pure, aucune écriture.
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentification requise.', 'results' => []]);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$config = NacreData::loadConfig()['observer_search'] ?? [];
$minLength = max(1, (int) ($config['min_query_length'] ?? 2));
$limit = max(1, min(25, (int) ($config['result_limit'] ?? 10)));

// Longueur minimale vérifiée côté serveur (pas seulement dans le JS) pour
// protéger l'annuaire LDAP contre un appel direct de cet endpoint.
if (($config['enabled'] ?? true) === false || mb_strlen($query) < $minLength) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    echo json_encode(['results' => LdapObserverSearch::search($query, $limit)]);
} catch (\Throwable $exception) {
    Toolbox::logInFile('nacresearch', 'Recherche LDAP observateurs : ' . $exception->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => 'La recherche est momentanément indisponible.', 'results' => []]);
}
