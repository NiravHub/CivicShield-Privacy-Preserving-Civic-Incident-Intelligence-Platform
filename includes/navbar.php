<?php
declare(strict_types=1);
$currentRole = Session::getRole();
$isLoggedIn = Session::isLoggedIn();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav class="navbar navbar-expand-lg navbar-dark cs-navbar sticky-top shadow-sm" aria-label="Main Navigation">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white text-decoration-none" href="<?= htmlspecialchars(BASE_URL) ?>/">
            <span class="cs-brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
            <span>Civic<span class="text-info">Shield</span></span>
            <span class="cs-demo-tag ms-1">Prototype</span>
        </a>
        <button class="navbar-toggler border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#civicNavbar" aria-controls="civicNavbar" aria-expanded="false" aria-label="Toggle navigation menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="civicNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?= $currentScript === 'index.php' && empty($_SERVER['QUERY_STRING']) ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/">
                        <i class="bi bi-house-door me-1"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(BASE_URL) ?>/#mapSection">
                        <i class="bi bi-map me-1"></i> Incident Map
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(BASE_URL) ?>/#categoriesSection">
                        <i class="bi bi-grid me-1"></i> Categories
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(BASE_URL) ?>/#howItWorksSection">
                        <i class="bi bi-shield-check me-1"></i> How It Works
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(BASE_URL) ?>/#trackerSection">
                        <i class="bi bi-search me-1"></i> Track Report
                    </a>
                </li>

                <?php if ($isLoggedIn && $currentRole === 'citizen'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars(BASE_URL) ?>/citizen/"><i class="bi bi-person-workspace me-1"></i> Citizen Portal</a>
                    </li>
                <?php endif; ?>
                <?php if ($isLoggedIn && $currentRole === 'officer'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/officer/') && $currentScript === 'index.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/officer/">
                            <i class="bi bi-speedometer2 me-1"></i> Officer Portal
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/officer/') && in_array($currentScript, ['incidents.php', 'incident_detail.php'], true) ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php">
                            <i class="bi bi-clipboard2-check me-1"></i> My Assignments
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($isLoggedIn && $currentRole === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'index.php' && str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/') ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/admin/"><i class="bi bi-speedometer2 me-1"></i> Admin Console</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($currentScript, ['incidents.php', 'incident_detail.php'], true) ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php"><i class="bi bi-shield-exclamation me-1"></i> Incidents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'duplicates.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php"><i class="bi bi-intersect me-1"></i> Duplicate Review</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'analytics.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(BASE_URL) ?>/admin/analytics.php"><i class="bi bi-graph-up-arrow me-1"></i> Analytics</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2 mt-3 mt-lg-0 ms-lg-3">
                <?php if ($isLoggedIn): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i>
                            <span><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                            <span class="cs-badge cs-badge-assigned text-uppercase" style="font-size:0.65rem;"><?= htmlspecialchars($currentRole) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><span class="dropdown-item-text text-muted small">Signed in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span></li>
                            <li>
                                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/auth/logout.php" class="m-0 p-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                                    <button type="submit" class="dropdown-item text-danger d-flex align-items-center">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/auth/login.php" class="btn btn-outline-light btn-sm px-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </a>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/auth/register.php" class="btn btn-cs-primary btn-sm px-3">
                        <i class="bi bi-person-plus me-1"></i> Register Citizen
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
