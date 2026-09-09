<?php

declare(strict_types=1);

namespace GlpiPlugin\Nacresearch;

use AuthLDAP;
use CommonITILActor;
use Ticket;
use Ticket_User;
use Toolbox;
use User;
use UserEmail;

/**
 * Rattache au ticket les observateurs saisis sous forme d'adresse e-mail dans
 * un formulaire de catalogue.
 *
 * GLPI ajoute nativement chaque réponse d'une question « E-mail » comme
 * observateur « acteur e-mail » (users_id = 0, notifications seules). Cette
 * classe reprend ces acteurs juste après la création du ticket : si l'adresse
 * correspond à un compte GLPI (existant ou importable depuis l'annuaire LDAP),
 * l'acteur e-mail devient un observateur rattaché au compte réel — la personne
 * voit alors le ticket dans GLPI et sa future connexion CAS retombe sur ce
 * compte. Les adresses non résolues restent en simple acteur e-mail.
 */
final class ObserverSync
{
    public static function syncFromTicket(Ticket $ticket): void
    {
        try {
            $config = NacreData::loadConfig()['observers'] ?? [];
            if (($config['enabled'] ?? true) === false) {
                return;
            }

            $ticketId = (int) $ticket->getID();
            if ($ticketId <= 0) {
                return;
            }

            $serverId = self::resolveLdapServerId($config['ldap_server_id'] ?? null);
            if ($serverId <= 0) {
                return;
            }

            $domain = strtolower(trim((string) ($config['ldap_email_domain'] ?? '')));

            $link = new Ticket_User();
            $emailActors = $link->find([
                'tickets_id' => $ticketId,
                'type'       => CommonITILActor::OBSERVER,
                'users_id'   => 0,
            ]);

            foreach ($emailActors as $row) {
                $email = strtolower(trim((string) ($row['alternative_email'] ?? '')));
                if ($email === '' || !str_contains($email, '@')) {
                    continue;
                }
                if ($domain !== '' && !str_ends_with($email, '@' . $domain)) {
                    continue;
                }

                $userId = self::findUserIdByEmail($email);
                if ($userId === 0) {
                    $userId = self::importFromLdap($email, $serverId);
                }
                if ($userId <= 0) {
                    continue;
                }

                self::promoteToRealObserver($ticketId, (int) $row['id'], $userId);
            }
        } catch (\Throwable $exception) {
            Toolbox::logInFile(
                'nacresearch',
                'Synchronisation des observateurs (ticket ' . (int) $ticket->getID() . ') : '
                    . $exception->getMessage() . "\n"
            );
        }
    }

    /**
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

    private static function findUserIdByEmail(string $email): int
    {
        $userEmail = new UserEmail();
        foreach ($userEmail->find(['email' => $email]) as $match) {
            $user = new User();
            if ($user->getFromDB((int) $match['users_id']) && !$user->isDeleted()) {
                return (int) $user->getID();
            }
        }

        return 0;
    }

    private static function importFromLdap(string $email, int $serverId): int
    {
        $login = substr($email, 0, (int) strpos($email, '@'));
        if ($login === '') {
            return 0;
        }

        $result = AuthLDAP::ldapImportUserByServerId(
            ['method' => AuthLDAP::IDENTIFIER_LOGIN, 'value' => $login],
            AuthLDAP::ACTION_IMPORT,
            $serverId
        );

        if (is_array($result) && isset($result['id']) && (int) $result['id'] > 0) {
            return (int) $result['id'];
        }

        return 0;
    }

    /**
     * Transforme l'acteur e-mail en observateur rattaché au compte réel, sans
     * générer de doublon si la personne est déjà observatrice du ticket.
     *
     * Écriture directe en base : le hook s'exécute dans la session du demandeur
     * pendant la soumission du formulaire, qui n'a pas forcément le droit de
     * modifier les acteurs via Ticket_User::update(). La personne a déjà reçu la
     * notification « nouveau ticket » en tant qu'acteur e-mail ; le rattachement
     * au compte se fait donc silencieusement.
     */
    private static function promoteToRealObserver(int $ticketId, int $emailRowId, int $userId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $link = new Ticket_User();
        $existing = $link->find([
            'tickets_id' => $ticketId,
            'type'       => CommonITILActor::OBSERVER,
            'users_id'   => $userId,
        ]);

        if ($existing !== []) {
            $DB->delete('glpi_tickets_users', ['id' => $emailRowId]);
            return;
        }

        $DB->update(
            'glpi_tickets_users',
            [
                'users_id'          => $userId,
                'alternative_email' => '',
                'use_notification'  => 1,
            ],
            ['id' => $emailRowId]
        );
    }
}
