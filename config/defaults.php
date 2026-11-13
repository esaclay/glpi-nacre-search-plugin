<?php

return [
    'plugin' => [
        'name' => 'nacresearch',
        'version' => '1.7.0',
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
    // Lignes de commande NACRES (code + désignation + montant HT) : question
    // texte court du formulaire catalogue dont le libellé contient l'indice
    // ci-dessous, transformée en tableau par nacre-orderlines.js ; à la
    // soumission, les lignes sont enregistrées sur le ticket (OrderLineSync).
    'order_lines' => [
        'enabled' => true,
        'field_label_hint' => 'codes nacre',
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
    // Recherche LDAP en direct (au fil de la frappe) proposée sur le champ
    // « Observateurs (adresses mail) » du formulaire catalogue, pour aider à
    // trouver l'adresse exacte d'une personne du labo. Recherche seule :
    // aucun compte n'est créé ici, voir la clé 'observers' ci-dessus (import
    // à la soumission, inchangé). Interroge le même annuaire LDAP
    // ('observers.ldap_server_id').
    'observer_search' => [
        'enabled' => true,
        'min_query_length' => 2,
        'result_limit' => 10,
        'debounce_ms' => 250,
        'field_label_hint' => 'observateur',
        'placeholder' => 'Rechercher par nom, prénom ou e-mail…',
        'empty_hint' => "Aucun résultat — vous pouvez saisir l'adresse e-mail directement.",
    ],
];
