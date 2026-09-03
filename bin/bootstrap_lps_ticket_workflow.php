#!/usr/bin/env php
<?php

declare(strict_types=1);

const LPS_ENTITY_NAME = 'LPS';
const FINANCE_GROUP_NAME = 'Gestionnaires financiers';
const FINANCE_MANAGER_PROFILE_NAME = 'Gestionnaire financier';
const FINANCE_ADMIN_PROFILE_NAME = 'Administratrice financière';

/**
 * @param array<string, mixed> $criteria
 */
function findOne(string $class, array $criteria, string $label): ?object
{
    $item = new $class();
    $matches = $item->find($criteria);

    if (count($matches) > 1) {
        throw new RuntimeException(sprintf('%s est ambigu (%d résultats).', $label, count($matches)));
    }

    if ($matches === []) {
        return null;
    }

    $id = (int) array_key_first($matches);
    if (!$item->getFromDB($id)) {
        throw new RuntimeException(sprintf('Impossible de relire %s (ID %d).', $label, $id));
    }

    return $item;
}

/**
 * @param array<string, int> $requiredRights
 */
function applyProfileRights(Profile $profile, array $requiredRights): void
{
    $currentRights = ProfileRight::getProfileRights($profile->getID());
    $targetRights = array_fill_keys(array_keys($currentRights), 0);
    foreach ($requiredRights as $name => $rights) {
        $targetRights[$name] = $rights;
    }

    $changes = [];
    foreach ($targetRights as $name => $rights) {
        if ((int) ($currentRights[$name] ?? 0) !== $rights) {
            $changes[$name] = $rights;
        }
    }

    if ($changes === []) {
        printf("Profil « %s » : droits déjà conformes.\n", $profile->fields['name']);
        return;
    }

    ProfileRight::updateProfileRights($profile->getID(), $changes);
    printf("Profil « %s » : %d droit(s) mis à jour.\n", $profile->fields['name'], count($changes));
}

/**
 * @param array<string, int> $rights
 */
function ensureProfile(string $name, array $rights): Profile
{
    $profile = findOne(Profile::class, ['name' => $name], sprintf('le profil « %s »', $name));

    if ($profile === null) {
        $profile = new Profile();
        $id = $profile->add([
            'name'       => $name,
            'interface'  => 'central',
            'is_default' => 0,
        ]);
        if ($id === false) {
            throw new RuntimeException(sprintf('Création impossible du profil « %s ».', $name));
        }
        if (!$profile->getFromDB((int) $id)) {
            throw new RuntimeException(sprintf('Impossible de relire le profil « %s » créé.', $name));
        }
        printf("Profil « %s » : créé.\n", $name);
    } elseif ($profile->fields['interface'] !== 'central' || (int) $profile->fields['is_default'] !== 0) {
        throw new RuntimeException(sprintf(
            'Le profil existant « %s » doit être central et ne pas être le profil par défaut.',
            $name
        ));
    } else {
        printf("Profil « %s » : existant.\n", $name);
    }

    applyProfileRights($profile, $rights);
    return $profile;
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Ce script doit être exécuté en ligne de commande.');
    }

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('Exécutez ce script avec le compte du serveur web, par exemple www-data.');
    }

    $options = getopt('', ['glpi-root::']);
    $pluginRoot = dirname(__DIR__);
    $glpiRoot = isset($options['glpi-root'])
        ? rtrim((string) $options['glpi-root'], DIRECTORY_SEPARATOR)
        : dirname($pluginRoot, 2);
    $includes = $glpiRoot . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'includes.php';

    if (!is_file($includes)) {
        throw new RuntimeException(sprintf(
            'GLPI introuvable dans « %s ». Utilisez --glpi-root=/chemin/vers/glpi.',
            $glpiRoot
        ));
    }

    define('GLPI_ROOT', $glpiRoot);
    require_once $includes;

    foreach ([Entity::class, Group::class, Profile::class, ProfileRight::class, Ticket::class, ITILFollowup::class, \Glpi\Form\Form::class] as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('API GLPI 11 requise indisponible : %s.', $class));
        }
    }

    $entity = findOne(Entity::class, ['name' => LPS_ENTITY_NAME], sprintf('l’entité « %s »', LPS_ENTITY_NAME));
    if ($entity === null) {
        throw new RuntimeException(sprintf('Entité « %s » introuvable.', LPS_ENTITY_NAME));
    }
    printf("Entité « %s » : ID %d.\n", LPS_ENTITY_NAME, $entity->getID());

    $group = findOne(
        Group::class,
        ['name' => FINANCE_GROUP_NAME, 'entities_id' => $entity->getID()],
        sprintf('le groupe « %s » de l’entité « %s »', FINANCE_GROUP_NAME, LPS_ENTITY_NAME)
    );
    if ($group !== null && (int) $group->fields['entities_id'] !== $entity->getID()) {
        throw new RuntimeException(sprintf('Le groupe « %s » appartient à une autre entité.', FINANCE_GROUP_NAME));
    }

    $fieldsPluginEnabled = class_exists('PluginFieldsContainer');

    $managerRights = [
        Ticket::$rightname => UPDATE | Ticket::READGROUP | Ticket::READASSIGN | Ticket::OWN,
        ITILFollowup::$rightname => ITILFollowup::SEEPUBLIC | ITILFollowup::UPDATEMY
            | ITILFollowup::ADD_AS_TECHNICIAN,
    ];
    $administratorRights = [
        Ticket::$rightname => UPDATE | Ticket::READALL | Ticket::ASSIGN | Ticket::OWN,
        ITILFollowup::$rightname => ITILFollowup::SEEPUBLIC | ITILFollowup::SEEPRIVATE
            | ITILFollowup::UPDATEMY | ITILFollowup::UPDATEALL | ITILFollowup::ADD_AS_TECHNICIAN
            | ITILFollowup::ADD_AS_GROUP | ITILFollowup::ADDALLITEM,
        \Glpi\Form\Form::$rightname => CREATE | READ | UPDATE | PURGE,
    ];
    foreach ([FINANCE_MANAGER_PROFILE_NAME, FINANCE_ADMIN_PROFILE_NAME] as $profileName) {
        $profile = findOne(Profile::class, ['name' => $profileName], sprintf('le profil « %s »', $profileName));
        if (
            $profile !== null
            && ($profile->fields['interface'] !== 'central' || (int) $profile->fields['is_default'] !== 0)
        ) {
            throw new RuntimeException(sprintf(
                'Le profil existant « %s » doit être central et ne pas être le profil par défaut.',
                $profileName
            ));
        }
    }

    if ($group === null) {
        $group = new Group();
        $id = $group->add([
            'name'          => FINANCE_GROUP_NAME,
            'entities_id'   => $entity->getID(),
            'is_assign'     => 1,
            'is_task'       => 1,
            'is_requester'  => 0,
            'is_watcher'    => 0,
            'is_notify'     => 1,
            'is_itemgroup'  => 0,
            'is_usergroup'  => 0,
            'is_manager'    => 0,
        ]);
        if ($id === false) {
            throw new RuntimeException(sprintf('Création impossible du groupe « %s ».', FINANCE_GROUP_NAME));
        }
        if (!$group->getFromDB((int) $id)) {
            throw new RuntimeException(sprintf('Impossible de relire le groupe « %s » créé.', FINANCE_GROUP_NAME));
        }
        printf("Groupe « %s » : créé dans LPS.\n", FINANCE_GROUP_NAME);
    } else {
        $groupChanges = ['id' => $group->getID()];
        foreach (['is_assign' => 1, 'is_task' => 1, 'is_notify' => 1] as $field => $value) {
            if ((int) $group->fields[$field] !== $value) {
                $groupChanges[$field] = $value;
            }
        }
        if (count($groupChanges) > 1) {
            if (!$group->update($groupChanges)) {
                throw new RuntimeException(sprintf('Mise à jour impossible du groupe « %s ».', FINANCE_GROUP_NAME));
            }
            printf("Groupe « %s » : capacités techniques mises à jour.\n", FINANCE_GROUP_NAME);
        } else {
            printf("Groupe « %s » : déjà conforme.\n", FINANCE_GROUP_NAME);
        }
    }

    ensureProfile(FINANCE_MANAGER_PROFILE_NAME, $managerRights);
    ensureProfile(FINANCE_ADMIN_PROFILE_NAME, $administratorRights);

    if ($fieldsPluginEnabled) {
        print("Fields est actif : aucun droit Fields n’est accordé, car son éditeur exige Configurer, qui autorise aussi la gestion des plugins.\n");
    } else {
        print("Fields n’est pas actif : aucun droit de configuration global n’a été accordé.\n");
    }
    print("Terminé. Aucun compte, aucune appartenance utilisateur et aucune règle d’autorisation n’ont été créés.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERREUR : ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
