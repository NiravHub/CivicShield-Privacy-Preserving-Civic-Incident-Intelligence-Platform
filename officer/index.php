<?php
declare(strict_types=1);

$pageTitle = 'Officer Portal & Dispatch Queue';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict officer authorization
requireAuth('officer');

$pdo = Database::getConnection();
$officerId = (int)Session::getUserId();

// Retrieve officer profile details
$officerStmt = $pdo->prepare('SELECT id, username, email, department, role, status FROM users WHERE id = :id LIMIT 1');
$officerStmt->execute([':id' => $officerId]);
$officer = $officerStmt->fetch();

if (!$officer || $officer['status'] !== 'active') {
    Session::setFlash('error', 'Your officer account is inactive or not found.');
    header('Location: ' . BASE_URL . '/auth/logout.php');
    exit;
}

// Handle Status Update Submission from Quick Actions
$actionError = null;
$actionSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        Session::setFlash('error', 'Security token mismatch. Please refresh and try again.');
        header('Location: ' . BASE_URL . '/officer/');
        exit;
    }

    $action = $_POST['form_action'] ?? '';

    if ($action === 'update_status') {
        $incidentId = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
        $newStatus  = trim((string)($_POST['new_status'] ?? ''));
        $notes      = trim((string)($_POST['notes'] ?? ''));

        if ($incidentId <= 0 || empty($newStatus)) {
            Session::setFlash('error', 'Missing required incident or status identifier.');
        } else {
            $result = IncidentManager::updateOfficerStatus($incidentId, $newStatus, $officerId, $notes, $pdo);
            if ($result['success']) {
                Session::setFlash('success', $result['message']);
            } else {
                Session::setFlash('error', $result['message']);
            }
        }
        header('Location: ' . BASE_URL . '/officer/');
        exit;
    }
}

// Fetch Dashboard Metrics & Active Queue
$stats = IncidentManager::getOfficerDashboardStats($officerId, $pdo);

// Fetch New Dispatches requiring action (status = 'assigned')
$newDispatches = IncidentManager::getOfficerIncidents($officerId, ['status' => 'assigned'], 1, 5, $pdo);

// Fetch Current Active Operations (status = 'in_progress')
$activeOperations = IncidentManager::getOfficerIncidents($officerId, ['status' => 'in_progress'], 1, 5, $pdo);

// Fetch Officer Recent Activity Stream
$activities = IncidentManager::getOfficerActivity($officerId, 8, $pdo);

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h2 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-shield-shaded text-primary me-2"></i>Officer Field Command
                </h2>
                <span class="badge bg-primary text-uppercase px-2 py-1" style="font-size:0.75rem;">Officer</span>
            </div>
            <p class="text-secondary mb-0">
                Officer: <strong class="text-dark"><?= htmlspecialchars($officer['username']) ?></strong>
                &bull; Department: <strong class="text-dark"><?= htmlspecialchars($officer['department'] ?? 'Municipal Operations') ?></strong>
                &bull; Jurisdiction: <span class="text-primary fw-semibold">Surat Municipal Corporation</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php" class="btn btn-cs-primary shadow-sm">
                <i class="bi bi-clipboard2-check me-1"></i> View Full Work Queue
            </a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php?view=history" class="btn btn-outline-secondary">
                <i class="bi bi-clock-history me-1"></i> Assignment History
            </a>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <!-- Card 1: Total Active Assignments -->
        <div class="col-6 col-lg-3">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-primary">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Total Active Work Orders</span>
                    <span class="badge bg-primary-subtle text-primary p-2 rounded-circle">
                        <i class="bi bi-list-task fs-5"></i>
                    </span>
                </div>
                <div class="fs-2 fw-bold text-dark"><?= number_format($stats['total_assigned']) ?></div>
                <div class="text-secondary small mt-1">Currently open assignments</div>
            </div>
        </div>

        <!-- Card 2: Action Required -->
        <div class="col-6 col-lg-3">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-danger">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Action Required</span>
                    <span class="badge bg-danger-subtle text-danger p-2 rounded-circle">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    </span>
                </div>
                <div class="fs-2 fw-bold text-danger"><?= number_format($stats['action_required']) ?></div>
                <div class="text-secondary small mt-1">Newly dispatched &bull; Awaiting action</div>
            </div>
        </div>

        <!-- Card 3: In Progress -->
        <div class="col-6 col-lg-3">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-warning">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Field Remediation</span>
                    <span class="badge bg-warning-subtle text-warning p-2 rounded-circle">
                        <i class="bi bi-tools fs-5"></i>
                    </span>
                </div>
                <div class="fs-2 fw-bold text-warning"><?= number_format($stats['in_progress']) ?></div>
                <div class="text-secondary small mt-1">Crew deployed on site</div>
            </div>
        </div>

        <!-- Card 4: Recently Resolved -->
        <div class="col-6 col-lg-3">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-success">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Remediated / Resolved</span>
                    <span class="badge bg-success-subtle text-success p-2 rounded-circle">
                        <i class="bi bi-check2-circle fs-5"></i>
                    </span>
                </div>
                <div class="fs-2 fw-bold text-success"><?= number_format($stats['recently_resolved']) ?></div>
                <div class="text-secondary small mt-1">Successfully resolved issues</div>
            </div>
        </div>
    </div>

    <!-- Main 2-Column Operational Grid -->
    <div class="row g-4 mb-4">
        <!-- Left Column: Immediate Action & Active Work Orders -->
        <div class="col-lg-8">
            <!-- Section 1: New Dispatches Requiring Action -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger-subtle text-danger p-2 rounded">
                            <i class="bi bi-bell-fill"></i>
                        </span>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">New Dispatches Requiring Action</h5>
                            <small class="text-muted">Acknowledge dispatch and initiate field inspection</small>
                        </div>
                    </div>
                    <?php if ($stats['action_required'] > 0): ?>
                        <span class="badge bg-danger rounded-pill px-3 py-1"><?= $stats['action_required'] ?> Pending</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($newDispatches['incidents'])): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check2-all text-success fs-2 d-block mb-2"></i>
                            <p class="mb-0 fw-semibold">No pending dispatches.</p>
                            <small class="text-secondary">All assigned work orders have been acknowledged or are underway.</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Incident</th>
                                        <th>Category &amp; Area</th>
                                        <th>Priority</th>
                                        <th>Assigned At</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($newDispatches['incidents'] as $inc): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="fw-bold text-decoration-none font-monospace text-primary">
                                                    <?= htmlspecialchars($inc['incident_reference']) ?>
                                                </a>
                                                <div class="small text-truncate text-muted" style="max-width: 260px;" title="<?= htmlspecialchars($inc['title']) ?>">
                                                    <?= htmlspecialchars($inc['title']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold">
                                                    <i class="bi <?= htmlspecialchars($inc['category_icon'] ?? 'bi-tag') ?> text-primary me-1"></i>
                                                    <?= htmlspecialchars($inc['category_name']) ?>
                                                </div>
                                                <span class="badge bg-light text-secondary border" style="font-size:0.75rem;">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inc['neighborhood']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger-subtle text-danger fw-bold">
                                                    Score: <?= (int)$inc['priority_score'] ?>
                                                </span>
                                            </td>
                                            <td class="small text-muted">
                                                <?= date('M d, H:i', strtotime($inc['assigned_at'])) ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#statusModal"
                                                            data-incident-id="<?= (int)$inc['id'] ?>"
                                                            data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                            data-current-status="<?= htmlspecialchars($inc['status']) ?>"
                                                            data-target-status="in_progress">
                                                        <i class="bi bi-play-circle me-1"></i> Start Field Work
                                                    </button>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="btn btn-outline-secondary" title="View Dossier">
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
            </div>

            <!-- Section 2: Active Field Remediation (in_progress) -->
            <div class="card cs-card shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning-subtle text-warning p-2 rounded">
                            <i class="bi bi-tools"></i>
                        </span>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">Active Remediation Operations</h5>
                            <small class="text-muted">Field crews currently deployed on site</small>
                        </div>
                    </div>
                    <?php if ($stats['in_progress'] > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill px-3 py-1"><?= $stats['in_progress'] ?> In Progress</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($activeOperations['incidents'])): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-cone-striped text-secondary fs-2 d-block mb-2"></i>
                            <p class="mb-0 fw-semibold">No active field remediation underway.</p>
                            <small class="text-secondary">Start field work on dispatched assignments to advance their status.</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Incident</th>
                                        <th>Category &amp; Area</th>
                                        <th>Priority</th>
                                        <th>Instructions</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeOperations['incidents'] as $inc): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="fw-bold text-decoration-none font-monospace text-primary">
                                                    <?= htmlspecialchars($inc['incident_reference']) ?>
                                                </a>
                                                <div class="small text-truncate text-muted" style="max-width: 240px;" title="<?= htmlspecialchars($inc['title']) ?>">
                                                    <?= htmlspecialchars($inc['title']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold">
                                                    <?= htmlspecialchars($inc['category_name']) ?>
                                                </div>
                                                <span class="badge bg-light text-secondary border" style="font-size:0.75rem;">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inc['neighborhood']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 6px; width: 50px;">
                                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= min(100, (int)$inc['priority_score']) ?>%"></div>
                                                    </div>
                                                    <span class="small fw-bold"><?= (int)$inc['priority_score'] ?></span>
                                                </div>
                                            </td>
                                            <td class="small text-muted" style="max-width: 200px;">
                                                <div class="text-truncate" title="<?= htmlspecialchars($inc['assignment_instructions'] ?? 'No specific instructions') ?>">
                                                    <?= htmlspecialchars($inc['assignment_instructions'] ?? 'Standard departmental procedure') ?>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-success" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#statusModal"
                                                            data-incident-id="<?= (int)$inc['id'] ?>"
                                                            data-incident-ref="<?= htmlspecialchars($inc['incident_reference']) ?>"
                                                            data-current-status="<?= htmlspecialchars($inc['status']) ?>"
                                                            data-target-status="resolved">
                                                        <i class="bi bi-check2-circle me-1"></i> Mark Resolved
                                                    </button>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$inc['id'] ?>" class="btn btn-outline-secondary" title="View Dossier">
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
            </div>
        </div>

        <!-- Right Column: Officer Profile, Guidelines & Recent Activity Feed -->
        <div class="col-lg-4">
            <!-- Officer Profile Card -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person-badge text-primary me-2"></i>Officer Credentials</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Username</span>
                            <strong><?= htmlspecialchars($officer['username']) ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Official Email</span>
                            <span class="text-break"><?= htmlspecialchars($officer['email']) ?></span>
                        </li>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Department</span>
                            <strong><?= htmlspecialchars($officer['department'] ?? 'General Operations') ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-1">
                            <span class="text-muted">Status</span>
                            <span class="badge bg-success text-uppercase">Active</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Operational Guidelines Card -->
            <div class="card cs-card shadow-sm mb-4 bg-light border-0">
                <div class="card-body">
                    <h6 class="fw-bold text-dark mb-2">
                        <i class="bi bi-info-circle-fill text-info me-1"></i> Field Operating Protocol
                    </h6>
                    <ul class="small text-secondary ps-3 mb-0">
                        <li class="mb-1">Advance newly dispatched incidents to <strong>In Progress</strong> as soon as on-site deployment begins.</li>
                        <li class="mb-1">Mark incidents as <strong>Resolved</strong> only once physical remediation is complete and verified.</li>
                        <li class="mb-1">Every status transition requires descriptive operational notes (minimum 5 characters).</li>
                        <li>Citizen privacy is strictly enforced: direct citizen contacts are isolated in the contact enclave.</li>
                    </ul>
                </div>
            </div>

            <!-- Recent Activity Stream -->
            <div class="card cs-card shadow-sm">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-secondary me-2"></i>My Recent Actions</h6>
                    <span class="badge bg-secondary-subtle text-secondary small">Audit Trail</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($activities)): ?>
                        <div class="p-4 text-center text-muted small">
                            <i class="bi bi-journal-text fs-3 d-block mb-1 text-secondary"></i>
                            No recent operational actions recorded.
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush small">
                            <?php foreach ($activities as $act): ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= (int)$act['incident_id'] ?>" class="fw-bold text-decoration-none font-monospace">
                                            <?= htmlspecialchars($act['incident_reference']) ?>
                                        </a>
                                        <span class="badge bg-light text-secondary border" style="font-size:0.65rem;">
                                            <?= date('M d, H:i', strtotime($act['activity_time'])) ?>
                                        </span>
                                    </div>
                                    <div class="text-dark">
                                        Transitioned: <span class="badge bg-secondary" style="font-size:0.65rem;"><?= htmlspecialchars($act['status_from']) ?></span>
                                        &rarr; <span class="badge bg-primary" style="font-size:0.65rem;"><?= htmlspecialchars($act['status_to']) ?></span>
                                    </div>
                                    <?php if (!empty($act['notes'])): ?>
                                        <div class="text-muted text-truncate mt-1 fst-italic" style="font-size:0.75rem;" title="<?= htmlspecialchars($act['notes']) ?>">
                                            &ldquo;<?= htmlspecialchars($act['notes']) ?>&rdquo;
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Quick Status Update -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/officer/index.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="incident_id" id="modalIncidentId" value="">
            <input type="hidden" name="new_status" id="modalNewStatus" value="">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="statusModalLabel">Update Incident Status</h5>
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
