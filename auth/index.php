<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

if (Session::isLoggedIn()) {
    header('Location: ' . Session::getRoleHomeUrl(Session::getRole()));
} else {
    header('Location: ' . BASE_URL . '/auth/login.php');
}
exit;
