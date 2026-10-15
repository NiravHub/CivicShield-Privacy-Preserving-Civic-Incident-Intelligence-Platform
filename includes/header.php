<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
}
$pageTitle = $pageTitle ?? 'Privacy-Preserving Civic Incident Intelligence Platform';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
    <meta name="base-url" content="<?= htmlspecialchars(BASE_URL) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> | CivicShield</title>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://unpkg.com">

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Bootstrap Icons 1.11.3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Leaflet.js 1.9.4 CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <!-- CivicShield Design System -->
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- Accessible Skip to Main Content Link -->
    <a href="#mainContent" class="cs-skip-link">Skip to main content</a>

    <?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'navbar.php'; ?>

    <main id="mainContent" class="flex-grow-1" tabindex="-1">
        <!-- Flash Messages Display -->
        <?php if ($flashSuccess = Session::getFlash('success')): ?>
            <div class="container mt-3">
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
                    <div class="flex-grow-1"><?= htmlspecialchars($flashSuccess) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($flashError = Session::getFlash('error')): ?>
            <div class="container mt-3">
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                    <div class="flex-grow-1"><?= htmlspecialchars($flashError) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>
