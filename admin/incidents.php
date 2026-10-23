<?php
declare(strict_types=1);

/**
 * CivicShield - Admin Incident Operations Center
 *
 * Provides incident operations, pagination, parameterized multi-field filtering,
 * search by reference/title/report, dispatch work order assignment, and validated status transitions.
 */

$pageTitle = 'Incident Operations';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Strict server-side administrator authorization guard
requireAuth('admin');

$pdo = Database::getConnection();
$adminUserId = Session::getUserId();
$errors = [];
$successMsg = '';

// Check for flash messages
if (isset($_SESSION['_flash_success'])) {
    $successMsg = (string)$_SESSION['_flash_success'];
    unset($_SESSION['_flash_success']);
}
if (isset($_SESSION['_flash_error'])) {
    $errors[] = (string)$_SESSION['_flash_error'];
    unset($_SESSION['_flash_error']);
}

// -----------------------------------------------------------------------------
// Handle POST Actions (Assign Officer / Update Status)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        $errors[] = 'Security token expired or invalid. Please refresh the page.';
    }

    $action = (string)($_POST['action'] ?? '');
    $incidentId = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
    $reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;

    if ($action !== 'promote_report' && $incidentId <= 0) {
        $errors[] = 'Invalid incident identifier.';
    } elseif ($action === 'promote_report' && $reportId <= 0) {
        $errors[] = 'Invalid report identifier for incident promotion.';
    }

    if (empty($errors)) {
        if ($action === 'promote_report') {
            $res = IncidentManager::createIncidentFromReport($reportId, $pdo, $adminUserId);
            if ($res['success']) {
                $msg = $res['message'];
                if (Response::isJsonRequest()) {
                    Response::ok($msg, $res);
                }
                $_SESSION['_flash_success'] = $msg;
                header('Location: ' . BASE_URL . '/admin/incidents.php');
                exit;
            } else {
                $errors[] = $res['message'];
            }
        } elseif ($action === 'assign') {
            $officerId = isset($_POST['officer_id']) ? (int)$_POST['officer_id'] : 0;
            $instructions = trim((string)($_POST['instructions'] ?? ''));

            if ($officerId <= 0) {
                $errors[] = 'Please select an eligible field officer for assignment.';
            } else {
                $res = IncidentManager::assignIncident($incidentId, $officerId, $adminUserId, $instructions, $pdo);
                if ($res['success']) {
                    $msg = $res['message'];
                    if (Response::isJsonRequest()) {
                        Response::ok($msg);
                    }
                    $_SESSION['_flash_success'] = $msg;
                    header('Location: ' . BASE_URL . '/admin/incidents.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
                    exit;
                } else {
                    $errors[] = $res['message'];
                }
            }
        } elseif ($action === 'update_status') {
            $newStatus = trim((string)($_POST['new_status'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));

            $res = IncidentManager::updateStatus($incidentId, $newStatus, $adminUserId, $reason, $pdo);
            if ($res['success']) {
                $msg = $res['message'];
                if (Response::isJsonRequest()) {
                    Response::ok($msg);
                }
                $_SESSION['_flash_success'] = $msg;
                header('Location: ' . BASE_URL . '/admin/incidents.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
                exit;
            } else {
                $errors[] = $res['message'];
            }
        } else {
            $errors[] = 'Unrecognized operation request.';
        }
    }

    if (Response::isJsonRequest() && !empty($errors)) {
        Response::badRequest(implode(' ', $errors));
    }
}

// -----------------------------------------------------------------------------
// Parse and Sanitize Filters & Pagination
// -----------------------------------------------------------------------------
$filters = [
    'status'       => trim((string)($_GET['status'] ?? '')),
    'category_id'  => isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : 0,
    'min_priority' => isset($_GET['min_priority']) && is_numeric($_GET['min_priority']) ? (int)$_GET['min_priority'] : 0,
    'date_from'    => trim((string)($_GET['date_from'] ?? '')),
    'date_to'      => trim((string)($_GET['date_to'] ?? '')),
    'search'       => trim((string)($_GET['search'] ?? ''))
];

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Query Incidents and Categories
$result = IncidentManager::getIncidents($filters, $page, $perPage, $pdo);
$incidents = $result['incidents'];
$pagination = $result['pagination'];

$categories = $pdo->query('SELECT id, name FROM incident_categories WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$officers = IncidentManager::getActiveOfficers($pdo);

// Summary Metrics for Header Cards
$statusTotals = [
    'reported'             => 0,
    'triaged'              => 0,
    'assigned'             => 0,
    'in_progress'          => 0,
    'resolution_submitted' => 0,
    'resolved'             => 0,
    'closed'               => 0,
    'total'                => 0
];
$statStmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM incidents GROUP BY status');
while ($row = $statStmt->fetch()) {
    $s = $row['status'];
    $c = (int)$row['cnt'];
    if (isset($statusTotals[$s])) {
        $statusTotals[$s] = $c;
    }
    $statusTotals['total'] += $c;
}

// Query Unlinked Citizen Intake Reports awaiting triage/promotion
$pendingReports = [];
try {
    $prepStmt = $pdo->query(
        "SELECT r.id, r.report_reference, r.title, r.submitted_severity, r.created_at, c.name AS category_name, c.icon AS category_icon
         FROM incident_reports r 
         JOIN incident_categories c ON c.id = r.category_id 
         WHERE r.incident_id IS NULL AND r.status IN ('pending_review', 'standalone') 
         ORDER BY r.created_at DESC 
         LIMIT 10"
    );
    $pendingReports = $prepStmt->fetchAll();
} catch (Throwable $e) {
    @error_log('[Admin Incidents Pending Reports Error] ' . $e->getMessage());
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb & Title -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="text-decoration-none">Admin Console</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Incident Operations</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark mb-0">
                <i class="bi bi-shield-exclamation text-primary me-2"></i>Municipal Incident Operations
            </h2>
            <p class="text-muted small mb-0">Operational triage ledger, field dispatch work orders, and validated resolution lifecycle</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-intersect me-1"></i> Duplicate Queue
            </a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i> Admin Dashboard
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-check-circle-fill flex-shrink-0 fs-5"></i>
            <div><?= htmlspecialchars($successMsg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0 fs-5"></i>
            <div>
                <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Overview Counters -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card cs-card p-3 border-start border-primary border-4">
                <div class="text-muted small fw-semibold text-uppercase">Total Issues</div>
                <div class="fs-3 fw-bold text-dark mt-1"><?= $statusTotals['total'] ?></div>
                <div class="small text-secondary">All consolidated records</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cs-card p-3 border-start border-warning border-4">
                <div class="text-muted small fw-semibold text-uppercase">Assigned / Dispatched</div>
                <div class="fs-3 fw-bold text-warning mt-1"><?= $statusTotals['assigned'] + $statusTotals['triaged'] ?></div>
                <div class="small text-secondary">Awaiting officer action</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cs-card p-3 border-start border-info border-4">
                <div class="text-muted small fw-semibold text-uppercase">Active In-Progress</div>
                <div class="fs-3 fw-bold text-info mt-1"><?= $statusTotals['in_progress'] ?></div>
                <div class="small text-secondary">Under active fieldwork</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cs-card p-3 border-start border-success border-4">
                <div class="text-muted small fw-semibold text-uppercase">Resolved / Closed</div>
                <div class="fs-3 fw-bold text-success mt-1"><?= $statusTotals['resolved'] + $statusTotals['closed'] ?></div>
                <div class="small text-secondary">Completed resolutions</div>
            </div>
        </div>
    </div>

    <?php if (!empty($pendingReports)): ?>
        <div class="card cs-card shadow-sm p-4 mb-4 border-warning border-start border-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-inbox-fill text-warning me-2"></i>Citizen Intake Triage Queue
                    </h5>
                    <div class="text-muted small">
                        <?= count($pendingReports) ?> unlinked citizen report(s) awaiting administrative triage and canonical incident registration
                    </div>
                </div>
                <span class="badge bg-warning text-dark fw-bold px-3 py-2">Requires Triage Action</span>
            </div>


            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col">Report Ref</th>
                            <th scope="col">Category</th>
                            <th scope="col">Title</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-end">Triage Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingReports as $prep): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary-subtle text-dark border font-monospace">
                                        <?= htmlspecialchars($prep['report_reference']) ?>
                                    </span>
                                </td>
                                <td>
                                    <i class="bi <?= htmlspecialchars($prep['category_icon'] ?? 'bi-tag') ?> text-info me-1"></i>
                                    <?= htmlspecialchars($prep['category_name']) ?>
                                </td>
                                <td class="fw-semibold text-dark">
                                    <?= htmlspecialchars($prep['title']) ?>
                                </td>
                                <td>
                                    <span class="badge cs-badge-<?= htmlspecialchars($prep['submitted_severity']) ?> text-uppercase">
                                        <?= htmlspecialchars($prep['submitted_severity']) ?>
                                    </span>
                                </td>
                                <td class="text-muted">
                                    <?= date('d M Y, h:i A', strtotime($prep['created_at'])) ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <form method="POST"
                                          action="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php"
                                          class="d-inline">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="promote_report">
                                        <input type="hidden" name="report_id" value="<?= (int)$prep['id'] ?>">
                                        <button type="submit" class="btn btn-warning btn-sm fw-semibold"
                                                title="Create Canonical Municipal Incident">
                                            <i class="bi bi-box-arrow-in-right me-1"></i>
                                            Promote to Incident
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>


    <!-- Filter & Search Panel -->
    <div class="card cs-card shadow-sm p-4 mb-4 border-light-subtle">
        <form method="GET" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="row g-3 align-items-end">
            <!-- Search -->
            <div class="col-md-3">
                <label for="search" class="form-label small fw-semibold text-muted mb-1">
                    <i class="bi bi-search me-1"></i>Search Reference or Title
                </label>
                <input type="text" name="search" id="search" class="form-control form-control-sm" 
                       placeholder="e.g. CS-SRT-2026 or Pothole" 
                       value="<?= htmlspecialchars($filters['search']) ?>">
            </div>

            <!-- Status -->
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold text-muted mb-1">Status</label>
                <select name="status" id="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="reported" <?= $filters['status'] === 'reported' ? 'selected' : '' ?>>Reported</option>
                    <option value="triaged" <?= $filters['status'] === 'triaged' ? 'selected' : '' ?>>Triaged</option>
                    <option value="assigned" <?= $filters['status'] === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                    <option value="in_progress" <?= $filters['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="resolution_submitted" <?= $filters['status'] === 'resolution_submitted' ? 'selected' : '' ?>>Resolution Submitted</option>
                    <option value="resolved" <?= $filters['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="closed" <?= $filters['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <!-- Category -->
            <div class="col-md-2">
                <label for="category_id" class="form-label small fw-semibold text-muted mb-1">Category</label>
                <select name="category_id" id="category_id" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Priority Minimum -->
            <div class="col-md-2">
                <label for="min_priority" class="form-label small fw-semibold text-muted mb-1">Min Priority Score</label>
                <select name="min_priority" id="min_priority" class="form-select form-select-sm">
                    <option value="0" <?= $filters['min_priority'] === 0 ? 'selected' : '' ?>>All Priorities</option>
                    <option value="80" <?= $filters['min_priority'] === 80 ? 'selected' : '' ?>>High / Urgent (80+)</option>
                    <option value="60" <?= $filters['min_priority'] === 60 ? 'selected' : '' ?>>Moderate (60+)</option>
                    <option value="40" <?= $filters['min_priority'] === 40 ? 'selected' : '' ?>>Elevated (40+)</option>
                </select>
            </div>

            <!-- Date Range From/To -->
            <div class="col-md-3 d-flex gap-2">
                <div class="flex-grow-1">
                    <label for="date_from" class="form-label small fw-semibold text-muted mb-1">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from']) ?>">
                </div>
                <div class="flex-grow-1">
                    <label for="date_to" class="form-label small fw-semibold text-muted mb-1">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to']) ?>">
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Reset Filters
                </a>
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-funnel me-1"></i>Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Incidents Table -->
    <div class="card cs-card shadow-sm border-light-subtle overflow-hidden mb-4">
        <div class="card-header bg-light py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-list-task me-2 text-primary"></i>Incident Ledger 
                <span class="badge bg-secondary-subtle text-dark ms-2"><?= $pagination['total_records'] ?> Records</span>
            </h5>
            <span class="small text-muted">Page <?= $pagination['current_page'] ?> of <?= $pagination['total_pages'] ?></span>
        </div>



        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="incidentsTable">
                <thead class="table-light small text-uppercase text-secondary">
                    <tr>
                        <th style="min-width: 140px;">Incident Ref</th>
                        <th style="min-width: 220px;">Category &amp; Title</th>
                        <th style="min-width: 140px;">Neighborhood</th>
                        <th style="min-width: 100px;">Severity</th>
                        <th style="min-width: 130px;">Priority Score</th>
                        <th style="min-width: 130px;">Status</th>
                        <th style="min-width: 160px;">Dispatched Officer</th>
                        <th style="min-width: 120px;">Reported</th>
                        <th class="text-end" style="min-width: 130px;">Actions</th>
                    </tr>
                </thead>

                <tbody class="small">
                    <?php if (empty($incidents)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                <strong>No incidents matched the selected criteria.</strong>
                                <p class="mb-0 small text-secondary">Try adjusting your search terms or clearing active filters.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($incidents as $inc): 
                            $priScore = (int)$inc['priority_score'];
                            $priClass = 'bg-secondary';
                            if ($priScore >= 80) $priClass = 'bg-danger';
                            elseif ($priScore >= 60) $priClass = 'bg-warning text-dark';
                            elseif ($priScore >= 40) $priClass = 'bg-info text-dark';
                        ?>
                            <tr>
                                <!-- Reference -->
                                <td>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="fw-bold text-decoration-none">
                                        <code><?= htmlspecialchars($inc['incident_reference']) ?></code>
                                    </a>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        <i class="bi bi-files me-1"></i><?= (int)$inc['reports_count'] ?> reports
                                    </div>
                                </td>

                                <!-- Category & Title -->
                                <td>
                                    <div class="fw-semibold text-dark text-truncate" style="max-width: 260px;" title="<?= htmlspecialchars($inc['title']) ?>">
                                        <?= htmlspecialchars($inc['title']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        <i class="bi <?= htmlspecialchars($inc['category_icon'] ?? 'bi-tag') ?> text-info me-1"></i>
                                        <?= htmlspecialchars($inc['category_name']) ?>
                                    </div>
                                </td>

                                <!-- Neighborhood -->
                                <td>
                                    <span class="text-dark"><i class="bi bi-geo-alt text-secondary me-1"></i><?= htmlspecialchars($inc['neighborhood']) ?></span>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($inc['city']) ?></div>
                                </td>

                                <!-- Severity -->
                                <td>
                                    <span class="badge cs-badge-<?= htmlspecialchars($inc['severity']) ?> text-uppercase">
                                        <?= htmlspecialchars($inc['severity']) ?>
                                    </span>
                                </td>

                                <!-- Priority Score -->
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge <?= $priClass ?> px-2 py-1">
                                            <?= $priScore ?>/100
                                        </span>
                                        <div class="progress flex-grow-1" style="height: 6px; width: 50px;">
                                            <div class="progress-bar <?= $priClass ?>" role="progressbar" style="width: <?= $priScore ?>%;" aria-valuenow="<?= $priScore ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td>
                                    <span class="badge cs-badge-<?= htmlspecialchars($inc['status']) ?> text-uppercase">
                                        <?= htmlspecialchars($inc['status']) ?>
                                    </span>
                                </td>

                                <!-- Officer Assignment -->
                                <td>
                                    <?php if (!empty($inc['officer_name'])): ?>
                                        <div class="fw-semibold text-dark">
                                            <i class="bi bi-person-badge text-primary me-1"></i><?= htmlspecialchars($inc['officer_name']) ?>
                                        </div>
                                        <div class="text-muted text-truncate" style="font-size: 0.72rem; max-width: 150px;">
                                            <?= htmlspecialchars($inc['officer_department'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Unassigned</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Date -->
                                <td class="text-muted" style="font-size: 0.75rem;">
                                    <?= date('d M Y', strtotime((string)$inc['created_at'])) ?><br>
                                    <span class="text-secondary"><?= date('h:i A', strtotime((string)$inc['created_at'])) ?></span>
                                </td>

                                <!-- Actions -->
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm flex-nowrap gap-2">
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="btn btn-outline-primary" title="View Incident Dossier">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (!in_array($inc['status'], ['closed', 'rejected'], true)): ?>
                                            <button type="button" class="btn btn-outline-warning" 
                                                    data-bs-toggle="modal" data-bs-target="#assignModal"
                                                    data-incident-id="<?= (int)$inc['id'] ?>"
                                                    data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                    data-current-officer="<?= (int)($inc['officer_id'] ?? 0) ?>"
                                                    title="Assign / Dispatch Officer">
                                                <i class="bi bi-person-plus"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-secondary" 
                                                data-bs-toggle="modal" data-bs-target="#statusModal"
                                                data-incident-id="<?= (int)$inc['id'] ?>"
                                                data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                data-current-status="<?= htmlspecialchars($inc['status']) ?>"
                                                title="Transition Status">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3 px-4">
                <span class="small text-muted">
                    Showing <?= count($incidents) ?> of <?= $pagination['total_records'] ?> records
                </span>
                <nav aria-label="Incident Table Pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Previous Page -->
                        <li class="page-item <?= !$pagination['has_previous'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $pagination['current_page'] - 1])) ?>">
                                &laquo; Previous
                            </a>
                        </li>

                        <!-- Page Numbers -->
                        <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                            <li class="page-item <?= $p === $pagination['current_page'] ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $p])) ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <!-- Next Page -->
                        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $pagination['current_page'] + 1])) ?>">
                                Next &raquo;
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal: Assign / Dispatch Field Officer -->
<!-- ========================================================================= -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="incident_id" id="assignModalIncidentId" value="">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="assignModalLabel">
                    <i class="bi bi-person-badge text-warning me-2"></i>Dispatch Field Officer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    Incident: <strong id="assignModalRef" class="font-monospace"></strong>
                </div>

                <div class="mb-3">
                    <label for="modalOfficerId" class="form-label small fw-semibold text-dark">
                        Select Field Officer <span class="text-danger">*</span>
                    </label>
                    <select name="officer_id" id="modalOfficerId" class="form-select" required>
                        <option value="">-- Choose Field Officer --</option>
                        <?php foreach ($officers as $off): ?>
                            <option value="<?= (int)$off['id'] ?>">
                                <?= htmlspecialchars($off['username']) ?> &bull; <?= htmlspecialchars($off['department']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text small text-secondary">
                        Only verified, active departmental officers can receive dispatch work orders.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="modalInstructions" class="form-label small fw-semibold text-dark">
                        Operational Instructions (Optional)
                    </label>
                    <textarea name="instructions" id="modalInstructions" class="form-control form-control-sm" rows="3" 
                              placeholder="e.g. Inspect road crater, deploy barricades, repair with cold-mix asphalt."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning btn-sm fw-semibold">
                    <i class="bi bi-send-check me-1"></i>Confirm Dispatch Assignment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal: Transition Incident Status -->
<!-- ========================================================================= -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="incident_id" id="statusModalIncidentId" value="">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="statusModalLabel">
                    <i class="bi bi-arrow-repeat text-primary me-2"></i>Transition Incident Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    Incident: <strong id="statusModalRef" class="font-monospace"></strong> &bull; Current: <span id="statusModalCurrentBadge" class="badge bg-secondary text-uppercase"></span>
                </div>

                <div class="mb-3">
                    <label for="modalNewStatus" class="form-label small fw-semibold text-dark">
                        New Lifecycle Status <span class="text-danger">*</span>
                    </label>
                    <select name="new_status" id="modalNewStatus" class="form-select" required>
                        <!-- Populated dynamically via JS based on ALLOWED_TRANSITIONS -->
                    </select>
                </div>

                <div class="mb-3">
                    <label for="modalStatusReason" class="form-label small fw-semibold text-dark">
                        Action / Resolution Notes <span id="reasonRequiredStar" class="text-danger d-none">*</span>
                    </label>
                    <textarea name="reason" id="modalStatusReason" class="form-control form-control-sm" rows="3" 
                              placeholder="Provide justification note. Required for resolved, closed, or rejected transitions."></textarea>
                    <div id="reasonHelp" class="form-text small text-secondary">
                        Resolution and closure notes are recorded in the immutable public timeline.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm fw-semibold">
                    <i class="bi bi-check2-circle me-1"></i>Save Status Transition
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const allowedTransitions = <?= json_encode(IncidentManager::ALLOWED_TRANSITIONS) ?>;

    // 1. Setup Assign Modal
    const assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const incId = button.getAttribute('data-incident-id');
            const incRef = button.getAttribute('data-incident-ref');
            const currentOfficer = button.getAttribute('data-current-officer');

            document.getElementById('assignModalIncidentId').value = incId;
            document.getElementById('assignModalRef').textContent = incRef;
            const select = document.getElementById('modalOfficerId');
            if (select && currentOfficer) {
                select.value = currentOfficer;
            }
        });
    }

    // 2. Setup Status Transition Modal with Allowed Paths
    const statusModal = document.getElementById('statusModal');
    if (statusModal) {
        statusModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const incId = button.getAttribute('data-incident-id');
            const incRef = button.getAttribute('data-incident-ref');
            const currentStatus = button.getAttribute('data-current-status');

            document.getElementById('statusModalIncidentId').value = incId;
            document.getElementById('statusModalRef').textContent = incRef;
            document.getElementById('statusModalCurrentBadge').textContent = currentStatus;

            const newStatusSelect = document.getElementById('modalNewStatus');
            newStatusSelect.innerHTML = '<option value="">-- Choose New Status --</option>';

            const options = allowedTransitions[currentStatus] || [];
            options.forEach(function (opt) {
                const optEl = document.createElement('option');
                optEl.value = opt;
                optEl.textContent = opt.replace('_', ' ').toUpperCase();
                newStatusSelect.appendChild(optEl);
            });

            // Adjust reason required label
            newStatusSelect.addEventListener('change', function () {
                const val = this.value;
                const star = document.getElementById('reasonRequiredStar');
                const reasonInput = document.getElementById('modalStatusReason');
                if (['resolved', 'closed', 'rejected'].includes(val)) {
                    star.classList.remove('d-none');
                    reasonInput.setAttribute('required', 'required');
                } else {
                    star.classList.add('d-none');
                    reasonInput.removeAttribute('required');
                }
            });
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
