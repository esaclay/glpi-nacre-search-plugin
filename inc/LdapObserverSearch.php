<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use AuthLDAP;

/**
 * Recherche live dans l'annuaire LDAP du labo, pour aider à trouver l'adresse
 * e-mail exacte d'un observateur sur le formulaire catalogue. Recherche pure :
 * ne crée ni ne modifie aucun compte GLPI (voir ObserverSync, qui reste seul
 * responsable de l'import, déclenché uniquement à la soumission du ticket).
 */
final class LdapObserverSearch
{
    /**
     * @return list<array{login: string, firstname: string, lastname: string, email: string, display: string}>
     */
    public static function search(string $query, int $limit): array
    {
        $term = trim($query);
        if ($term === '' || $limit <= 0) {
            return [];
        }

        $config = NacreData::loadConfig()['observers'] ?? [];
        $serverId = self::resolveLdapServerId($config['ldap_server_id'] ?? null);
        if ($serverId <= 0) {
            return [];
        }

        $ldapServer = new AuthLDAP();
        if (!$ldapServer->getFromDB($serverId) || (int) ($ldapServer->fields['is_active'] ?? 0) !== 1) {
            return [];
        }

        $connection = $ldapServer->connect();
        if ($connection === false) {
            return [];
        }

        $filter = self::buildFilter((string) ($ldapServer->fields['condition'] ?? ''), $term);
        $baseDn = (string) ($ldapServer->fields['basedn'] ?? '');

        $handle = @ldap_search($connection, $baseDn, $filter, ['uid', 'sn', 'givenname', 'mail'], 0, $limit);
        if ($handle === false) {
            @ldap_unbind($connection);
            return [];
        }

        $entries = ldap_get_entries($connection, $handle);
        @ldap_unbind($connection);

        return self::mapEntries($entries, $limit);
    }

    /**
     * Combine le filtre de connexion déjà configuré sur le serveur LDAP GLPI
     * (ex. « (departmentNumber=100) », restreint aux personnels du labo) avec
     * une recherche multi-champs et l'exclusion des comptes techniques
     * (téléphones : uid=tel.*, sans nom de famille).
     */
    private static function buildFilter(string $connectionCondition, string $term): string
    {
        $condition = trim($connectionCondition);
        if ($condition === '') {
            $condition = '(objectClass=*)';
        }

        $escaped = ldap_escape($term, '', LDAP_ESCAPE_FILTER);

        return '(&' . $condition . '(sn=*)(!(uid=tel.*))'
            . '(|(uid=*' . $escaped . '*)(sn=*' . $escaped . '*)(givenname=*' . $escaped . '*)(mail=*' . $escaped . '*)))';
    }

    /**
     * @param array<int|string, mixed> $entries
     * @return list<array{login: string, firstname: string, lastname: string, email: string, display: string}>
     */
    private static function mapEntries(array $entries, int $limit): array
    {
        $results = [];
        $count = (int) ($entries['count'] ?? 0);

        for ($i = 0; $i < $count && count($results) < $limit; $i++) {
            $entry = $entries[$i];
            $login = $entry['uid'][0] ?? '';
            $email = $entry['mail'][0] ?? '';
            if ($login === '' || $email === '') {
                continue;
            }

            $firstname = $entry['givenname'][0] ?? '';
            $lastname = $entry['sn'][0] ?? '';

            $results[] = [
                'login'     => $login,
                'firstname' => $firstname,
                'lastname'  => $lastname,
                'email'     => $email,
                'display'   => trim($firstname . ' ' . $lastname) . ' — ' . $email,
            ];
        }

        return $results;
    }

    /**
     * Dupliqué à l'identique depuis ObserverSync::resolveLdapServerId() —
     * duplication volontaire pour ne modifier aucune ligne du mécanisme
     * d'import existant.
     *
     * @param mixed $configured
     */
    private static function resolveLdapServerId($configured): int
    {
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $ldap = new AuthLDAP();
        $servers = $ldap->find(['is_active' => 1], ['is_default DESC', 'id ASC'], 1);
        $first = reset($servers);

        return is_array($first) ? (int) $first['id'] : 0;
    }
}
