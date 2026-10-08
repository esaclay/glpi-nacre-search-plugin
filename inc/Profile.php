<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

/**
 * Clés des droits du plugin, telles qu'enregistrées dans `glpi_profilerights`.
 *
 * Ces droits n'ont pas d'onglet dans l'interface Profil (choix assumé). Ils sont
 * accordés :
 *   - automatiquement à l'installation par `plugin_nacresearch_register_rights()`
 *     (setup.php) : toutes les clés à 0 sur tous les profils, puis `RIGHT_GUIDE`
 *     en lecture et `RIGHT_LINES` en écriture pour « Gestionnaire financier » et
 *     « Administratrice financière » ;
 *   - par `bin/bootstrap_lps_ticket_workflow.php` (`RIGHT_NACRE` en écriture pour
 *     l'administratrice) ;
 *   - sinon en CLI (`bin/console`) ou en SQL direct sur `glpi_profilerights`.
 */
final class Profile
{
    // Gestion des données NACRES (import / backup / restore) — READ / UPDATE.
    public const RIGHT_NACRE = 'plugin_nacresearch_data';

    // Accès au guide d'utilisation de l'équipe financière — READ seul.
    public const RIGHT_GUIDE = 'plugin_nacresearch_guide';

    // Modification des lignes NACRES d'un ticket (onglet « Lignes NACRES ») —
    // UPDATE. La consultation suit le droit de voir le ticket.
    public const RIGHT_LINES = 'plugin_nacresearch_lines';
}
