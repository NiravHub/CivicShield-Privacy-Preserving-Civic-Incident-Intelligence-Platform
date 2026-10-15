<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

// Enforce POST method with valid CSRF token to prevent cross-site logout forgery
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (Response::isJsonRequest()) {
        Response::badRequest('Logout must be performed via a secure POST request.');
    }
    Session::setFlash('error', 'Logout must be performed via a secure POST request.');
    header('Location: ' . BASE_URL . '/');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!Session::validateCsrf($csrfToken)) {
    if (Response::isJsonRequest()) {
        Response::badRequest('Invalid or expired CSRF token.');
    }
    Session::setFlash('error', 'Security token mismatch. Please try signing out again.');
    header('Location: ' . BASE_URL . '/');
    exit;
}

// Invalidate session data and clear session cookies
Session::destroy();

// Start fresh guest session for user feedback
Session::start();
Session::setFlash('success', 'You have been safely signed out.');

if (Response::isJsonRequest()) {
    Response::ok('Signed out successfully.');
}

header('Location: ' . BASE_URL . '/');
exit;
