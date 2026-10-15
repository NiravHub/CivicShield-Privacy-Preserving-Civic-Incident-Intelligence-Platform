<?php
declare(strict_types=1);

$pageTitle = 'Admin Console';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict admin authentication
requireAuth('admin');

// Query dynamic operational metrics
$pdo = Database::getConnection();
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalIncidents = (int)$pdo->query('SELECT COUNT(*) FROM incidents')->fetchColumn();
$pendingDuplicates = (int)$pdo->query("SELECT COUNT(*) FROM incident_duplicate_candidates WHERE review_status = 'pending_review'")->fetchColumn();

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-speedometer2 text-danger me-2"></i>Platform Administration Console</h2>
            <p class="text-muted mb-0">System Control &bull; Privacy Budget Management &bull; Role-Based Governance</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/analytics.php" class="btn btn-info btn-sm fw-semibold text-dark">
                <i class="bi bi-graph-up-arrow me-1"></i> Civic Analytics
            </a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="btn btn-primary btn-sm fw-semibold">
                <i class="bi bi-shield-exclamation me-1"></i> Incident Operations
            </a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php" class="btn btn-warning btn-sm fw-semibold position-relative">
                <i class="bi bi-intersect me-1"></i> Duplicate Review Queue
                <?php if ($pendingDuplicates > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $pendingDuplicates ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/api/v1/health.php" target="_blank" class="btn btn-outline-info btn-sm">
                <i class="bi bi-activity me-1"></i> API Health Check
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card cs-card p-3">
                <div class="text-muted small fw-semibold">TOTAL USERS</div>
                <div class="fs-3 fw-bold text-dark mt-1"><?= $totalUsers ?></div>
                <div class="text-secondary small mt-1">Citizens, Officers, Admins</div>
            </div>
        </div>
        <div class="col-md-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-info border-4">
                    <div class="text-muted small fw-semibold">TOTAL INCIDENTS</div>
                    <div class="fs-3 fw-bold text-info mt-1"><?= $totalIncidents ?></div>
                    <div class="text-secondary small mt-1">Click to manage & dispatch</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-warning border-4">
                    <div class="text-muted small fw-semibold">PENDING DUPLICATES</div>
                    <div class="fs-3 fw-bold text-warning mt-1"><?= $pendingDuplicates ?></div>
                    <div class="text-secondary small mt-1">Requires human review</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <div class="card cs-card p-3">
                <div class="text-muted small fw-semibold">GEO-FUZZING RADIUS</div>
                <div class="fs-3 fw-bold text-primary mt-1"><?= htmlspecialchars((string)Env::get('GEO_FUZZ_RADIUS_METERS', 150)) ?>m</div>
                <div class="text-secondary small mt-1">Differential noise radius</div>
            </div>
        </div>
    </div>

    <!-- Active Management Modules -->
    <div class="row g-4">
        <div class="col-lg-6 col-xl-3">
            <div class="card cs-card shadow-sm p-4 h-100 border-start border-primary border-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-exclamation me-2 text-primary"></i>Incident Ledger</h5>
                    <span class="badge bg-primary"><?= $totalIncidents ?> Issues</span>
                </div>
                <p class="text-muted small mb-3">
                    Filter, search, review linked citizen reports, dispatch work orders to officers, and manage lifecycle transitions.
                </p>
                <div class="mt-auto">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-sliders me-1"></i> Open Ledger
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-6 col-xl-3">
            <div class="card cs-card shadow-sm p-4 h-100 border-start border-warning border-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-intersect me-2 text-warning"></i>Duplicate Review</h5>
                    <span class="badge bg-warning text-dark"><?= $pendingDuplicates ?> Pending</span>
                </div>
                <p class="text-muted small mb-3">
                    Haversine spatial proximity (50m default), category alignment, and text similarity detect candidate duplicate reports.
                </p>
                <div class="mt-auto">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php" class="btn btn-warning btn-sm fw-semibold">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Open Queue
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-6 col-xl-3">
            <div class="card cs-card shadow-sm p-4 h-100 border-start border-info border-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-steps me-2 text-info"></i>Priority Scoring</h5>
                    <span class="badge bg-success">Active Heuristic</span>
                </div>
                <p class="text-muted small mb-3">
                    Multi-factor scoring (0–100) weighting severity (35%), citizen corroboration (25%), SLA decay (20%), and civic urgency (20%).
                </p>
                <div class="mt-auto">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php?min_priority=60" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-fire me-1"></i> High Priority
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-6 col-xl-3">
            <div class="card cs-card shadow-sm p-4 h-100 border-start border-success border-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up-arrow me-2 text-success"></i>Civic Analytics</h5>
                    <span class="badge bg-success">Intelligence</span>
                </div>
                <p class="text-muted small mb-3">
                    Municipal performance analytics, resolution times, longitudinal trends, category distributions, and spatial cluster maps.
                </p>
                <div class="mt-auto">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/analytics.php" class="btn btn-success btn-sm">
                        <i class="bi bi-pie-chart-fill me-1"></i> Open Analytics
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
