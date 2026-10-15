<?php
declare(strict_types=1);

$pageTitle = 'Incident Work Order Dossier';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict officer authorization
requireAuth('officer');

$pdo = Database::getConnection();
$officerId = (int)Session::getUserId();
$incidentId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($incidentId <= 0) {
    Session::setFlash('error', 'Invalid incident identifier provided.');
    header('Location: ' . BASE_URL . '/officer/incidents.php');
    exit;
}

// Strictly verify that this incident is assigned to the authenticated officer
$incident = IncidentManager::getOfficerIncidentDetail($incidentId, $officerId, $pdo);

if (!$incident) {
    http_response_code(403);
    $pageTitle = '403 Forbidden — Access Denied';
    require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <div class="container py-5 text-center">
        <div class="card cs-card p-5 mx-auto shadow-sm" style="max-width: 650px;">
            <div class="display-1 text-danger fw-bold mb-2">
                <i class="bi bi-shield-slash-fill"></i>
            </div>
            <h2 class="fw-bold text-dark mb-2">403 — Unauthorized Incident Access</h2>
            <p class="text-secondary mb-3">
                Access to incident ID <strong>#<?= $incidentId ?></strong> is strictly restricted. This incident is not assigned to your officer account or does not exist under your jurisdiction.
            </p>
            <div class="d-flex justify-content-center gap-2">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php" class="btn btn-cs-primary">
                    <i class="bi bi-arrow-left me-1"></i> Return to My Assigned Queue
                </a>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/" class="btn btn-outline-secondary">
                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </div>
    <?php
    require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

// Handle Status Transition POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        Session::setFlash('error', 'Security token mismatch. Please refresh and try again.');
        header('Location: ' . BASE_URL . '/officer/incident_detail.php?id=' . $incidentId);
        exit;
    }

    $action = $_POST['form_action'] ?? '';
    if ($action === 'update_status') {
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        $notes     = trim((string)($_POST['notes'] ?? ''));

        $result = IncidentManager::updateOfficerStatus($incidentId, $newStatus, $officerId, $notes, $pdo);
        if ($result['success']) {
            Session::setFlash('success', $result['message']);
        } else {
            Session::setFlash('error', $result['message']);
        }

        header('Location: ' . BASE_URL . '/officer/incident_detail.php?id=' . $incidentId);
        exit;
    }
}

// Fetch linked citizen reports (privacy-preserving: no raw intake GPS)
$linkedReports = IncidentManager::getLinkedReports($incidentId, $pdo, false);

// Fetch status lifecycle history
$statusHistory = IncidentManager::getStatusHistory($incidentId, $pdo);

$assignment = $incident['current_assignment'];
$isActiveAssignment = $incident['is_active_assignment'];

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

<div class="container py-4">
    <!-- Breadcrumb & Top Controls -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/incidents.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Queue
            </a>
            <span class="text-muted">/</span>
            <span class="font-monospace fw-bold text-dark fs-5"><?= htmlspecialchars($incident['incident_reference']) ?></span>
            <span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1 small">
                <i class="bi bi-shield-check me-1"></i> Assigned Work Order
            </span>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/officer/" class="btn btn-light btn-sm border">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Header Incident Title & Status Banner -->
    <div class="card cs-card shadow-sm mb-4 border-top border-4 <?= (int)$incident['priority_score'] >= 80 ? 'border-danger' : 'border-primary' ?>">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="badge bg-light text-primary border fw-semibold">
                            <i class="bi <?= htmlspecialchars($incident['category_icon'] ?? 'bi-tag') ?> me-1"></i>
                            <?= htmlspecialchars($incident['category_name']) ?>
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary border">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($incident['neighborhood']) ?>, Surat
                        </span>
                        <span class="badge bg-light text-muted border">
                            SLA: <?= (int)$incident['resolution_sla_hours'] ?> Hours
                        </span>
                    </div>
                    <h3 class="fw-bold text-dark mb-2"><?= htmlspecialchars($incident['title']) ?></h3>
                    <p class="text-secondary mb-0" style="max-width: 850px;">
                        <?= nl2br(htmlspecialchars($incident['description'])) ?>
                    </p>
                </div>
                <div class="text-end">
                    <div class="mb-2">
                        <span class="text-muted small d-block">MUNICIPAL STATUS</span>
                        <span class="badge <?= match($incident['status']) {
                            'assigned'    => 'bg-danger text-white',
                            'in_progress' => 'bg-warning text-dark',
                            'resolved'    => 'bg-success text-white',
                            'closed'      => 'bg-secondary text-white',
                            default       => 'bg-light text-dark border'
                        } ?> fs-6 text-uppercase px-3 py-2">
                            <?= str_replace('_', ' ', htmlspecialchars($incident['status'])) ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-muted small d-block">PRIORITY SCORE</span>
                        <span class="badge <?= (int)$incident['priority_score'] >= 80 ? 'bg-danger' : ((int)$incident['priority_score'] >= 60 ? 'bg-warning' : 'bg-info') ?> fs-5 px-3 py-1">
                            <?= (int)$incident['priority_score'] ?> / 100
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main 2-Column Dossier Content -->
    <div class="row g-4">
        <!-- Left Column: Geospatial Centroid, Work Order Details & Action Panel -->
        <div class="col-lg-7">
            <!-- Work Order Action Panel -->
            <div class="card cs-card shadow-sm mb-4 border-start border-4 <?= $incident['status'] === 'assigned' ? 'border-warning' : ($incident['status'] === 'in_progress' ? 'border-success' : 'border-secondary') ?>">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-tools text-primary me-2"></i>Field Action Panel
                    </h5>
                    <span class="badge bg-light text-secondary border">
                        Assignment: <strong><?= htmlspecialchars($assignment['assignment_status']) ?></strong>
                    </span>
                </div>
                <div class="card-body p-4">
                    <?php if ($incident['status'] === 'assigned'): ?>
                        <div class="alert alert-warning py-2 mb-3 small d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                This work order is newly dispatched. Acknowledge and start field remediation once your crew is on site.
                            </div>
                        </div>
                        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= $incidentId ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                            <input type="hidden" name="form_action" value="update_status">
                            <input type="hidden" name="new_status" value="in_progress">

                            <div class="mb-3">
                                <label for="startWorkNotes" class="form-label fw-semibold small">Deployment Log &amp; Crew Notes <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="startWorkNotes" name="notes" rows="3" required minlength="5" placeholder="e.g. Field inspection team deployed on site; equipment and materials arrived..."></textarea>
                                <div class="form-text small">Minimum 5 characters required for municipal audit logging.</div>
                            </div>
                            <button type="submit" class="btn btn-warning text-dark fw-bold px-4">
                                <i class="bi bi-play-circle-fill me-1"></i> Start Field Remediation (In Progress)
                            </button>
                        </form>
                    <?php elseif ($incident['status'] === 'in_progress'): ?>
                        <div class="alert alert-info py-2 mb-3 small d-flex align-items-center gap-2">
                            <i class="bi bi-info-circle-fill fs-5"></i>
                            <div>
                                Field work is actively underway. When on-site repairs are completed and verified, submit resolution details below.
                            </div>
                        </div>
                        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/officer/incident_detail.php?id=<?= $incidentId ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                            <input type="hidden" name="form_action" value="update_status">
                            <input type="hidden" name="new_status" value="resolved">

                            <div class="mb-3">
                                <label for="resolvedNotes" class="form-label fw-semibold small">Resolution Summary &amp; Completion Notes <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="resolvedNotes" name="notes" rows="4" required minlength="5" placeholder="e.g. Asphalt patching completed using cold-mix bituminous compound; roadway reopened to two-wheeler and four-wheeler traffic..."></textarea>
                                <div class="form-text small">Detail the physical materials used, repairs conducted, and current site condition. Minimum 5 characters required.</div>
                            </div>
                            <button type="submit" class="btn btn-success fw-bold px-4">
                                <i class="bi bi-check-circle-fill me-1"></i> Mark Incident Resolved
                            </button>
                        </form>
                    <?php elseif ($incident['status'] === 'resolved'): ?>
                        <div class="p-4 text-center bg-light rounded border border-success">
                            <i class="bi bi-check-circle-fill text-success fs-1 d-block mb-2"></i>
                            <h5 class="fw-bold text-dark">Field Remediation Resolved</h5>
                            <p class="small text-secondary mb-2">
                                You marked this incident resolved on <?= !empty($incident['resolved_at']) ? date('M d, Y \a\t H:i', strtotime($incident['resolved_at'])) : 'recently' ?>.
                            </p>
                            <span class="badge bg-success-subtle text-success border border-success px-3 py-1 small">
                                Awaiting Central Command Verification &amp; Formal Closure
                            </span>
                        </div>
                    <?php elseif ($incident['status'] === 'closed'): ?>
                        <div class="p-4 text-center bg-light rounded border">
                            <i class="bi bi-lock-fill text-secondary fs-1 d-block mb-2"></i>
                            <h5 class="fw-bold text-dark">Incident Formally Closed</h5>
                            <p class="small text-secondary mb-0">
                                This civic issue was verified and closed by municipal administration on <?= !empty($incident['closed_at']) ? date('M d, Y \a\t H:i', strtotime($incident['closed_at'])) : 'record' ?>.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="p-3 bg-light rounded text-muted small">
                            Current status is <strong><?= htmlspecialchars($incident['status']) ?></strong>. No operational actions available at this stage.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Departmental Work Order Instructions -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>Work Order Details
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Assigned By</span>
                            <strong><?= htmlspecialchars($assignment['assigned_by_username'] ?? 'Municipal Admin') ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Assigned At</span>
                            <strong><?= date('M d, Y \a\t H:i', strtotime($assignment['assigned_at'])) ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Work Order Status</span>
                            <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($assignment['assignment_status']) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Acknowledged At</span>
                            <strong><?= !empty($assignment['acknowledged_at']) ? date('M d, Y \a\t H:i', strtotime($assignment['acknowledged_at'])) : 'Pending Start' ?></strong>
                        </div>
                        <div class="col-12 mt-2">
                            <span class="text-muted d-block mb-1">Administrative Dispatch Instructions:</span>
                            <div class="p-3 bg-light rounded border text-dark">
                                <?= !empty($assignment['instructions']) ? nl2br(htmlspecialchars($assignment['instructions'])) : '<span class="text-muted fst-italic">Standard departmental repair procedures apply.</span>' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Geospatial Map View with Differential Privacy -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-geo-alt-fill text-danger me-2"></i>Incident Approximate Centroid
                    </h6>
                    <span class="badge bg-info-subtle text-info border border-info small">
                        <i class="bi bi-shield-lock me-1"></i> Differential Privacy &bull; ~150m Fuzzing
                    </span>
                </div>
                <div class="card-body p-0">
                    <div id="incidentOfficerMap" style="height: 320px; width: 100%;"></div>
                </div>
                <div class="card-footer bg-light py-2 small text-muted">
                    <i class="bi bi-info-circle me-1"></i> Coordinates (<?= htmlspecialchars((string)$incident['obfuscated_lat']) ?>, <?= htmlspecialchars((string)$incident['obfuscated_lng']) ?>) represent the privacy-preserving centroid within <strong><?= htmlspecialchars($incident['neighborhood']) ?></strong>.
                </div>
            </div>
        </div>

        <!-- Right Column: Priority Breakdown, Linked Reports & Status Audit Trail -->
        <div class="col-lg-5">
            <!-- Priority Score Factor Breakdown -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-sliders text-primary me-2"></i>Priority Calculation Factors
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($incident['priority_breakdown']) && is_array($incident['priority_breakdown'])): ?>
                        <?php $bd = $incident['priority_breakdown']; ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Base Severity Weight (<?= htmlspecialchars($incident['severity']) ?>)</span>
                                <strong class="text-primary"><?= (int)($bd['severity_base'] ?? 0) ?> pts</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-primary" style="width: <?= min(100, (int)($bd['severity_base'] ?? 0)) ?>%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Corroboration Factor (<?= (int)$incident['reports_count'] ?> reports)</span>
                                <strong class="text-info"><?= (int)($bd['report_count_boost'] ?? 0) ?> pts</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-info" style="width: <?= min(100, (int)($bd['report_count_boost'] ?? 0) * 4) ?>%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Civic Weight (<?= htmlspecialchars($incident['category_name']) ?>)</span>
                                <strong class="text-warning"><?= (int)($bd['civic_weight_boost'] ?? 0) ?> pts</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" style="width: <?= min(100, (int)($bd['civic_weight_boost'] ?? 0) * 4) ?>%"></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted">
                            Composite Priority Score: <strong><?= (int)$incident['priority_score'] ?></strong> based on municipal severity factor and category SLA urgency.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Linked Citizen Reports (Privacy-Preserved) -->
            <div class="card cs-card shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-people-fill text-primary me-2"></i>Linked Citizen Reports (<?= count($linkedReports) ?>)
                    </h6>
                    <span class="badge bg-light text-secondary border small">Pseudonymous</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($linkedReports)): ?>
                        <div class="p-3 text-center text-muted small">No citizen reports linked.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Report Ref</th>
                                        <th>Severity</th>
                                        <th>Mode</th>
                                        <th>Filed At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($linkedReports as $rep): ?>
                                        <tr>
                                            <td class="font-monospace fw-bold text-primary">
                                                <?= htmlspecialchars($rep['report_reference']) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border text-uppercase" style="font-size:0.65rem;">
                                                    <?= htmlspecialchars($rep['submitted_severity']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.65rem;">
                                                    <?= htmlspecialchars($rep['submission_mode']) ?>
                                                </span>
                                            </td>
                                            <td class="text-muted">
                                                <?= date('M d, H:i', strtotime($rep['created_at'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light py-2 small text-muted" style="font-size:0.75rem;">
                    <i class="bi bi-shield-lock me-1"></i> Citizen identity, phone, and raw tracking tokens are strictly protected and isolated.
                </div>
            </div>

            <!-- Lifecycle Status Audit Trail -->
            <div class="card cs-card shadow-sm">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-clock-history text-secondary me-2"></i>Status Lifecycle Timeline
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($statusHistory)): ?>
                        <div class="p-3 text-center text-muted small">No status transitions recorded yet.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush small">
                            <?php foreach ($statusHistory as $hist): ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <span class="badge bg-secondary" style="font-size:0.65rem;"><?= htmlspecialchars($hist['status_from']) ?></span>
                                            &rarr;
                                            <span class="badge bg-primary" style="font-size:0.65rem;"><?= htmlspecialchars($hist['status_to']) ?></span>
                                        </div>
                                        <span class="badge bg-light text-secondary border" style="font-size:0.65rem;">
                                            <?= date('M d, H:i', strtotime($hist['created_at'])) ?>
                                        </span>
                                    </div>
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        By: <strong><?= htmlspecialchars($hist['actor_username'] ?? 'System / Admin') ?></strong>
                                    </div>
                                    <?php if (!empty($hist['reason'])): ?>
                                        <div class="mt-1 text-dark fst-italic" style="font-size:0.8rem;">
                                            &ldquo;<?= htmlspecialchars($hist['reason']) ?>&rdquo;
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

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var lat = <?= (float)$incident['obfuscated_lat'] ?>;
    var lng = <?= (float)$incident['obfuscated_lng'] ?>;
    var fuzzRadius = <?= (int)$incident['fuzz_radius_meters'] ?>;

    var map = L.map('incidentOfficerMap').setView([lat, lng], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    // Differential privacy fuzzing circle
    L.circle([lat, lng], {
        color: '#dc3545',
        fillColor: '#dc3545',
        fillOpacity: 0.15,
        radius: fuzzRadius
    }).addTo(map).bindPopup("<strong>Privacy Fuzz Boundary</strong><br>Reported radius: ~" + fuzzRadius + "m.");

    // Centroid Marker
    L.marker([lat, lng]).addTo(map)
        .bindPopup("<strong><?= htmlspecialchars($incident['incident_reference']) ?></strong><br><?= htmlspecialchars($incident['neighborhood']) ?> Centroid")
        .openPopup();
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
