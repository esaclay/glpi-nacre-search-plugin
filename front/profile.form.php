<?php

include ('../../../inc/includes.php');

// Vérification des droits et du jeton CSRF
Session::checkRight('profile', UPDATE);
Session::checkCSRF($_POST);

if (isset($_POST['update_nacre_rights']) && isset($_POST['profiles_id'])) {
    $profileId = (int) $_POST['profiles_id'];

    $rightValue = 0;
    if (isset($_POST['nacresearch'])) {
        $rightValue = (int) $_POST['nacresearch'];
    } elseif (isset($_POST['_glpi_simple_right']['nacresearch'])) {
        $rightValue = (int) $_POST['_glpi_simple_right']['nacresearch'];
    }

    \GlpiPlugin\Nacresearch\Profile::updateProfileRights($profileId, $rightValue);

    Html::back();
} else {
    Html::displayNotFoundError();
}