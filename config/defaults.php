<?php

return [
    'plugin' => [
        'name' => 'nacresearch',
        'version' => '1.4.0',
    ],
    'ui' => [
        'selector_hint' => 'nacre',
        'result_limit' => 100,
        'debounce_ms' => 120,
        'modal_title' => 'Recherche de code NACRE',
        'button_label' => 'Chercher un code NACRE',
    ],
    'data' => [
        'source' => 'public/data/nacre.json',
    ],
    // Synchronisation des observateurs saisis dans un formulaire de catalogue :
    // à la création du ticket, chaque observateur « acteur e-mail » dont
    // l'adresse appartient au domaine ci-dessous est résolu vers un compte GLPI
    // existant, ou importé depuis l'annuaire LDAP, puis rattaché au ticket.
    // Les adresses hors domaine restent en simple acteur e-mail (notifications).
    'observers' => [
        'enabled' => true,
        // ID de l'annuaire LDAP GLPI à interroger ; null => premier annuaire actif.
        'ldap_server_id' => null,
        // Domaine des adresses éligibles à l'import LDAP (vide => toutes).
        'ldap_email_domain' => 'universite-paris-saclay.fr',
    ],
];
