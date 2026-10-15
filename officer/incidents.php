<?php
declare(strict_types=1);

$pageTitle = 'My Assigned Incidents';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict officer authorization
requireAuth('officer');

$pdo = Database::getConnection();
$officerId = (int)Session::getUserId();

// Handle Quick Status Update Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        Session::setFlash('error', 'Security token mismatch. Please refresh and try again.');
        header('Location: ' . BASE_URL . '/officer/incidents.php');
        exit;
    }

    $action = $_POST['form_action'] ?? '';
    if ($action === 'update_status') {
        $incidentId = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
        $newStatus  = trim((string)($_POST['new_status'] ?? ''));
        $notes      = trim((string)($_POST['notes'] ?? ''));

        if ($incidentId <= 0 || empty($newStatus)) {
            Session::setFlash('error', 'Invalid incident or status transition request.');
        } else {
            $result = IncidentManager::updateOfficerStatus($incidentId, $newStatus, $officerId, $notes, $pdo);
            if ($result['success']) {
                Session::setFlash('success', $result['message']);
            } else {
                Session::setFlash('error', $result['message']);
            }
        }

        // Preserve current query parameters on redirect
        $currentQuery = !empty($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : '';
        header('Location: ' . BASE_URL . '/officer/incidents.php' . $currentQuery);
        exit;
    }
}

// Sanitize and parse filter inputs
$filterView        = ($_GET['view'] ?? 'active') === 'history' ? 'history' : 'active';
$filterStatus      = trim((string)($_GET['status'] ?? ''));
$filterCategory    = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterMinPriority = isset($_GET['min_priority']) && is_numeric($_GET['min_priority']) ? (int)$_GET['min_priority'] : null;
$filterSearch      = trim((string)($_GET['search'] ?? ''));
$page              = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage           = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? max(5, min(50, (int)$_GET['per_page'])) : 10;

// Fetch categories for filter dropdown
$categoriesStmt = $pdo->query('SELECT id, name, icon FROM incident_categories WHERE is_active = 1 ORDER BY name ASC');
$categories = $categoriesStmt->fetchAll();

// Build filter payload
$filters = [
    'view'         => $filterView,
    'status'       => $filterStatus !== '' ? $filterStatus : null,
    'category_id'  => $filterCategory,
    'min_priority' => $filterMinPriority,
    'search'       => $filterSearch !== '' ? $filterSearch : null,
];

// Query officer's incidents
$result = IncidentManager::getOfficerIncidents($officerId, $filters, $page, $perPage, $pdo);
$incidents = $result['incidents'];
$pagination = $result['pagination'];

// Helper to preserve query strings in links
function buildQueryString(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/" class="text-secondary text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
                <span class="text-muted">/</span>
                <h3 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-clipboard2-check text-primary me-2"></i>My Assigned Incidents
                </h3>
            </div>
            <p class="text-secondary mb-0">
                Departmental work orders dispatched to your officer account.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-secondary border px-3 py-2">
                <i class="bi bi-person-badge text-primary me-1"></i> Officer ID: <strong><?= $officerId ?></strong>
            </span>
        </div>
    </div>

    <!-- View Mode Switch Tabs -->
    <ul class="nav nav-pills mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $filterView === 'active' ? 'active bg-primary' : 'text-dark' ?>" 
               href="?<?= htmlspecialchars(buildQueryString(['view' => 'active', 'page' => 1])) ?>">
                <i class="bi bi-tools me-1"></i> Active Work Orders
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $filterView === 'history' ? 'active bg-secondary' : 'text-dark' ?>" 
               href="?<?= htmlspecialchars(buildQueryString(['view' => 'history', 'page' => 1])) ?>">
                <i class="bi bi-clock-history me-1"></i> Work Order History (Completed / Reassigned)
            </a>
        </li>
    </ul>

    <!-- Filter & Search Toolbar -->
    <div class="card cs-card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php" class="row g-3 align-items-end">
                <input type="hidden" name="view" value="<?= htmlspecialchars($filterView) ?>">

                <!-- Search -->
                <div class="col-md-4">
                    <label for="filterSearch" class="form-label small fw-semibold">Search Reference or Title</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="filterSearch" name="search" 
                               value="<?= htmlspecialchars($filterSearch) ?>" placeholder="e.g. CS-SRT-2026 or Pothole">
                    </div>
                </div>

                <!-- Status Filter -->
                <div class="col-md-3">
                    <label for="filterStatus" class="form-label small fw-semibold">Municipal Status</label>
                    <select class="form-select form-select-sm" id="filterStatus" name="status">
                        <option value="">All Statuses</option>
                        <option value="assigned" <?= $filterStatus === 'assigned' ? 'selected' : '' ?>>Assigned (New)</option>
                        <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress (Remediation)</option>
                        <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="closed" <?= $filterStatus === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>

                <!-- Category Filter -->
                <div class="col-md-3">
                    <label for="filterCategory" class="form-label small fw-semibold">Civic Category</label>
                    <select class="form-select form-select-sm" id="filterCategory" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Min Priority Filter -->
                <div class="col-md-2">
                    <label for="filterPriority" class="form-label small fw-semibold">Min Priority</label>
                    <select class="form-select form-select-sm" id="filterPriority" name="min_priority">
                        <option value="">Any</option>
                        <option value="40" <?= $filterMinPriority === 40 ? 'selected' : '' ?>>Score &ge; 40</option>
                        <option value="70" <?= $filterMinPriority === 70 ? 'selected' : '' ?>>Score &ge; 70 (High)</option>
                        <option value="85" <?= $filterMinPriority === 85 ? 'selected' : '' ?>>Score &ge; 85 (Critical)</option>
                    </select>
                </div>

                <!-- Submit & Reset Buttons -->
                <div class="col-12 d-flex justify-content-end gap-2">
                    <a href="?view=<?= htmlspecialchars($filterView) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Clear Filters
                    </a>
                    <button type="submit" class="btn btn-cs-primary btn-sm px-3">
                        <i class="bi bi-funnel-fill me-1"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Incidents Table Card -->
    <div class="card cs-card shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <?= $filterView === 'history' ? 'Assignment History' : 'Active Work Orders' ?>
                <span class="badge bg-primary-subtle text-primary rounded-pill ms-2" style="font-size:0.75rem;">
                    <?= number_format($pagination['total_records']) ?> records
                </span>
            </h5>
            <div class="small text-muted">
                Showing <?= min($pagination['total_records'], ($pagination['current_page'] - 1) * $pagination['per_page'] + 1) ?>-<?= min($pagination['total_records'], $pagination['current_page'] * $pagination['per_page']) ?> of <?= number_format($pagination['total_records']) ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($incidents)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                    <h5 class="fw-bold text-dark">No work orders match the current criteria</h5>
                    <p class="small text-secondary mb-3">
                        <?= $filterView === 'history' 
                            ? 'You have no historical or completed assignments.' 
                            : 'All current dispatches have been resolved or filter criteria returned zero results.' ?>
                    </p>
                    <a href="?view=<?= htmlspecialchars($filterView) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Incident Reference</th>
                                <th>Category &amp; Location</th>
                                <th>Title / Description</th>
                                <th style="width: 140px;">Priority</th>
                                <th>Status</th>
                                <th>Assigned At</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incidents as $inc): ?>
                                <?php
                                $statusBadgeClass = match ($inc['status']) {
                                    'assigned'    => 'bg-danger text-white',
                                    'in_progress' => 'bg-warning text-dark',
                                    'resolved'    => 'bg-success text-white',
                                    'closed'      => 'bg-secondary text-white',
                                    default       => 'bg-light text-dark border'
                                };
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" 
                                           class="fw-bold text-decoration-none font-monospace text-primary">
                                            <?= htmlspecialchars($inc['incident_reference']) ?>
                                        </a>
                                        <div class="small text-muted" style="font-size:0.75rem;">
                                            Ref #<?= (int)$inc['id'] ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold small">
                                            <i class="bi <?= htmlspecialchars($inc['category_icon'] ?? 'bi-tag') ?> text-primary me-1"></i>
                                            <?= htmlspecialchars($inc['category_name']) ?>
                                        </div>
                                        <div class="small text-secondary">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inc['neighborhood']) ?>, Surat
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($inc['title']) ?>">
                                            <?= htmlspecialchars($inc['title']) ?>
                                        </div>
                                        <div class="text-muted small text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($inc['description']) ?>">
                                            <?= htmlspecialchars($inc['description']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px;">
                                                <div class="progress-bar <?= (int)$inc['priority_score'] >= 80 ? 'bg-danger' : ((int)$inc['priority_score'] >= 60 ? 'bg-warning' : 'bg-info') ?>" 
                                                     role="progressbar" 
                                                     style="width: <?= min(100, (int)$inc['priority_score']) ?>%">
                                                </div>
                                            </div>
                                            <span class="small fw-bold"><?= (int)$inc['priority_score'] ?></span>
                                        </div>
                                        <div class="small text-muted" style="font-size:0.7rem;">
                                            Severity: <strong class="text-uppercase"><?= htmlspecialchars($inc['severity']) ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $statusBadgeClass ?> text-uppercase px-2 py-1" style="font-size:0.75rem;">
                                            <?= str_replace('_', ' ', htmlspecialchars($inc['status'])) ?>
                                        </span>
                                        <?php if (!empty($inc['assignment_status']) && $inc['assignment_status'] !== 'assigned'): ?>
                                            <div class="small text-muted mt-1" style="font-size:0.7rem;">
                                                Work Order: <span class="badge bg-light text-secondary border"><?= htmlspecialchars($inc['assignment_status']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <div><?= date('M d, Y', strtotime($inc['assigned_at'])) ?></div>
                                        <div style="font-size:0.75rem;"><?= date('H:i', strtotime($inc['assigned_at'])) ?></div>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($inc['status'] === 'assigned'): ?>
                                                <button type="button" class="btn btn-outline-warning text-dark" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#statusModal"
                                                        data-incident-id="<?= (int)$inc['id'] ?>"
                                                        data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                        data-current-status="<?= htmlspecialchars($inc['status']) ?>"
                                                        data-target-status="in_progress">
                                                    <i class="bi bi-play-circle me-1"></i> Start Work
                                                </button>
                                            <?php elseif ($inc['status'] === 'in_progress'): ?>
                                                <button type="button" class="btn btn-outline-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#statusModal"
                                                        data-incident-id="<?= (int)$inc['id'] ?>"
                                                        data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                        data-current-status="<?= htmlspecialchars($inc['status']) ?>"
                                                        data-target-status="resolved">
                                                    <i class="bi bi-check2-circle me-1"></i> Mark Resolved
                                                </button>
                                            <?php endif; ?>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" 
                                               class="btn btn-outline-secondary" title="View Dossier">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination Footer -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
                <div class="small text-muted">
                    Page <?= $pagination['current_page'] ?> of <?= $pagination['total_pages'] ?>
                </div>
                <nav aria-label="Incident Pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= !$pagination['has_previous'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= htmlspecialchars(buildQueryString(['page' => $pagination['current_page'] - 1])) ?>">
                                &laquo; Prev
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                            <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(buildQueryString(['page' => $i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= htmlspecialchars(buildQueryString(['page' => $pagination['current_page'] + 1])) ?>">
                                Next &raquo;
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="incident_id" id="modalIncidentId" value="">
            <input type="hidden" name="new_status" id="modalNewStatus" value="">
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="statusModalLabel">Advance Incident Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    Incident: <strong id="modalIncidentRefDisplay" class="font-monospace"></strong> &bull;
                    Action: <strong id="modalActionDisplay" class="text-uppercase"></strong>
                </div>

                <div class="mb-3">
                    <label for="modalNotes" class="form-label fw-semibold small">Operational Notes / Work Log <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="modalNotes" name="notes" rows="3" required minlength="5" placeholder="Document on-site crew deployment, materials used, or resolution details..."></textarea>
                    <div class="form-text small">Minimum 5 characters required for operational audit logging.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">
                    <i class="bi bi-check-lg me-1"></i> Submit Status Update
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var statusModal = document.getElementById('statusModal');
    if (statusModal) {
        statusModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button) return;

            var incidentId = button.getAttribute('data-incident-id');
            var incidentRef = button.getAttribute('data-incident-ref');
            var currentStatus = button.getAttribute('data-current-status');
            var targetStatus = button.getAttribute('data-target-status');

            document.getElementById('modalIncidentId').value = incidentId;
            document.getElementById('modalNewStatus').value = targetStatus;
            document.getElementById('modalIncidentRefDisplay').textContent = incidentRef;

            var actionLabel = targetStatus === 'in_progress' ? 'Start Field Work (In Progress)' : 'Mark Resolved';
            document.getElementById('modalActionDisplay').textContent = actionLabel;
            
            var submitBtn = document.getElementById('modalSubmitBtn');
            if (targetStatus === 'resolved') {
                submitBtn.className = 'btn btn-success';
                submitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Incident Resolved';
            } else {
                submitBtn.className = 'btn btn-warning text-dark';
                submitBtn.innerHTML = '<i class="bi bi-play-circle me-1"></i> Deploy Crew (In Progress)';
            }
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
