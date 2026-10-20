<?php
declare(strict_types=1);

/**
 * CivicShield - Admin Incident Dossier & Detail View
 *
 * Provides comprehensive inspection of an individual consolidated incident:
 * - Obfuscated spatial map with differential fuzzing circle
 * - Priority scoring decision-support breakdown
 * - Non-leaking linked citizen reports ledger
 * - Officer dispatch work order history & assignment action
 * - Immutable status transition audit timeline & resolution action
 */

$pageTitle = 'Incident Dossier';
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

$incidentId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($incidentId <= 0) {
    header('Location: ' . BASE_URL . '/admin/incidents.php');
    exit;
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

    if (empty($errors)) {
        if ($action === 'assign') {
            $officerId = isset($_POST['officer_id']) ? (int)$_POST['officer_id'] : 0;
            $instructions = trim((string)($_POST['instructions'] ?? ''));

            if ($officerId <= 0) {
                $errors[] = 'Please select an eligible field officer for assignment.';
            } else {
                $res = IncidentManager::assignIncident($incidentId, $officerId, $adminUserId, $instructions, $pdo);
                if ($res['success']) {
                    $_SESSION['_flash_success'] = $res['message'];
                    header('Location: ' . BASE_URL . '/admin/incident_detail.php?id=' . $incidentId);
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
                $_SESSION['_flash_success'] = $res['message'];
                header('Location: ' . BASE_URL . '/admin/incident_detail.php?id=' . $incidentId);
                exit;
            } else {
                $errors[] = $res['message'];
            }
        } elseif ($action === 'review_evidence') {
            $decision = trim((string)($_POST['decision'] ?? ''));
            $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

            $res = IncidentManager::reviewResolutionEvidence($incidentId, (int)$adminUserId, $decision, $reviewNotes, $pdo);
            if ($res['success']) {
                $_SESSION['_flash_success'] = $res['message'];
                header('Location: ' . BASE_URL . '/admin/incident_detail.php?id=' . $incidentId);
                exit;
            } else {
                $errors[] = $res['message'];
            }
        }
    }
}

// Fetch Incident and Associated Entities
$incident = IncidentManager::getIncidentDetails($incidentId, $pdo);

if (!$incident) {
    require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="container py-5 text-center"><div class="alert alert-danger">Incident record not found. <a href="' . htmlspecialchars(BASE_URL) . '/admin/incidents.php">Return to incidents</a></div></div>';
    require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$linkedReports = IncidentManager::getLinkedReports($incidentId, $pdo);
$statusHistory = IncidentManager::getStatusHistory($incidentId, $pdo);
$assignmentHistory = IncidentManager::getAssignmentHistory($incidentId, $pdo);
$officers = IncidentManager::getActiveOfficers($pdo);
$resolutionEvidence = IncidentManager::getResolutionEvidence($incidentId, $pdo);

$priBreakdown = $incident['priority_breakdown'] ?? [];

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb & Header Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="text-decoration-none">Admin Console</a></li>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="text-decoration-none">Incident Operations</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($incident['incident_reference']) ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h2 class="fw-bold text-dark mb-0 font-monospace">
                    <?= htmlspecialchars($incident['incident_reference']) ?>
                </h2>
                <span class="badge cs-badge-<?= htmlspecialchars($incident['status']) ?> text-uppercase fs-6">
                    <?= htmlspecialchars($incident['status']) ?>
                </span>
                <span class="badge cs-badge-<?= htmlspecialchars($incident['severity']) ?> text-uppercase fs-6">
                    <?= htmlspecialchars($incident['severity']) ?> Severity
                </span>
            </div>
        </div>

        <div class="d-flex gap-2">
            <?php if (!in_array($incident['status'], ['closed', 'rejected'], true)): ?>
                <button type="button" class="btn btn-warning btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#assignModal">
                    <i class="bi bi-person-plus me-1"></i> <?= !empty($incident['officer_name']) ? 'Reassign Officer' : 'Dispatch Officer' ?>
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#statusModal">
                <i class="bi bi-arrow-repeat me-1"></i> Update Status
            </button>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Ledger
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

    <div class="row g-4 mb-4">
        <!-- Left: Incident Overview & Map -->
        <div class="col-lg-7">
            <!-- Overview Card -->
            <div class="card cs-card shadow-sm p-4 mb-4 border-light-subtle">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-light text-primary border border-primary-subtle px-3 py-2">
                        <i class="bi <?= htmlspecialchars($incident['category_icon'] ?? 'bi-tag') ?> me-1"></i>
                        <?= htmlspecialchars($incident['category_name']) ?> (SLA: <?= (int)$incident['resolution_sla_hours'] ?>h)
                    </span>
                    <span class="text-muted small">
                        Reported: <?= date('d M Y, h:i A', strtotime((string)$incident['created_at'])) ?>
                    </span>
                </div>

                <h4 class="fw-bold text-dark mb-2"><?= htmlspecialchars($incident['title']) ?></h4>
                <p class="text-secondary mb-3 leading-relaxed"><?= nl2br(htmlspecialchars($incident['description'])) ?></p>

                <div class="row g-2 border-top pt-3 small text-secondary">
                    <div class="col-sm-6">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                        <strong>Neighborhood:</strong> <?= htmlspecialchars($incident['neighborhood']) ?>, <?= htmlspecialchars($incident['city']) ?>
                    </div>
                    <div class="col-sm-6">
                        <i class="bi bi-files text-primary me-1"></i>
                        <strong>Linked Citizen Reports:</strong> <?= (int)$incident['reports_count'] ?> independent submissions
                    </div>
                    <?php if (!empty($incident['resolved_at'])): ?>
                        <div class="col-sm-6 text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <strong>Resolved Date:</strong> <?= date('d M Y, h:i A', strtotime((string)$incident['resolved_at'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($incident['closed_at'])): ?>
                        <div class="col-sm-6 text-muted">
                            <i class="bi bi-lock-fill me-1"></i>
                            <strong>Closed Date:</strong> <?= date('d M Y, h:i A', strtotime((string)$incident['closed_at'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resolution Evidence & Verification Dossier Card -->
            <?php if (!empty($resolutionEvidence) || $incident['status'] === 'resolution_submitted'): ?>
                <div class="card cs-card shadow-sm border-light-subtle mb-4">
                    <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-camera-fill text-primary me-2"></i>Field Resolution Evidence &amp; Verification Dossier
                        </h6>
                        <?php if ($incident['status'] === 'resolution_submitted'): ?>
                            <span class="badge bg-warning text-dark px-3 py-1">
                                <i class="bi bi-hourglass-split me-1"></i>Awaiting Admin Verification
                            </span>
                        <?php elseif ($incident['status'] === 'resolved' || $incident['status'] === 'closed'): ?>
                            <span class="badge bg-success px-3 py-1">
                                <i class="bi bi-check-circle-fill me-1"></i>Evidence Verified
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if (empty($resolutionEvidence)): ?>
                            <div class="p-3 text-center text-muted small">
                                No photographic resolution evidence records on file.
                            </div>
                        <?php else: ?>
                            <?php foreach ($resolutionEvidence as $idx => $ev): ?>
                                <div class="<?= $idx > 0 ? 'border-top pt-3 mt-3' : '' ?>">
                                    <div class="row g-3 align-items-start">
                                        <div class="col-md-4 text-center">
                                            <a href="<?= htmlspecialchars(BASE_URL . '/' . $ev['photo_path']) ?>" target="_blank" rel="noopener">
                                                <img src="<?= htmlspecialchars(BASE_URL . '/' . $ev['photo_path']) ?>" alt="Resolution Evidence Photo" class="img-fluid rounded border shadow-sm" style="max-height: 180px; object-fit: cover;">
                                            </a>
                                            <div class="text-muted mt-1" style="font-size:0.72rem;">
                                                <i class="bi bi-zoom-in me-1"></i>Click to view full image
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                                                <div>
                                                    <span class="badge bg-secondary-subtle text-dark border me-1">
                                                        Officer: <?= htmlspecialchars($ev['officer_full_name'] ?? $ev['officer_username']) ?>
                                                    </span>
                                                    <span class="badge <?= $ev['verification_status'] === 'verified' ? 'bg-success' : ($ev['verification_status'] === 'rework_requested' ? 'bg-warning text-dark' : 'bg-info') ?> text-uppercase" style="font-size:0.68rem;">
                                                        <?= htmlspecialchars(str_replace('_', ' ', $ev['verification_status'])) ?>
                                                    </span>
                                                </div>
                                                <span class="text-muted small">
                                                    <?= date('d M Y, h:i A', strtotime((string)$ev['created_at'])) ?>
                                                </span>
                                            </div>

                                            <div class="mb-2 small">
                                                <strong class="text-dark">Field Officer Remediation Notes:</strong>
                                                <div class="p-2 bg-light rounded text-secondary mt-1">
                                                    <?= nl2br(htmlspecialchars((string)$ev['evidence_notes'])) ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($ev['inspection_lat']) && !empty($ev['inspection_lng'])): ?>
                                                <div class="mb-2 small text-secondary">
                                                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                                    <strong>Inspection Coordinates:</strong> <?= htmlspecialchars((string)$ev['inspection_lat']) ?>, <?= htmlspecialchars((string)$ev['inspection_lng']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="mb-2 text-muted" style="font-size: 0.72rem;">
                                                <strong>Cryptographic SHA-256 Digest:</strong>
                                                <code class="d-block text-break bg-light p-1 rounded mt-1"><?= htmlspecialchars((string)$ev['photo_sha256']) ?></code>
                                            </div>

                                            <?php if (!empty($ev['verified_at'])): ?>
                                                <div class="p-2 rounded border <?= $ev['verification_status'] === 'verified' ? 'bg-success-subtle border-success-subtle' : 'bg-warning-subtle border-warning-subtle' ?> small mt-2">
                                                    <div class="fw-semibold text-dark">
                                                        <?= $ev['verification_status'] === 'verified' ? 'Verified by' : 'Rework requested by' ?>:
                                                        <?= htmlspecialchars($ev['verifier_full_name'] ?? $ev['verifier_username'] ?? 'Municipal Admin') ?>
                                                        <span class="text-muted fw-normal">&bull; <?= date('d M Y, h:i A', strtotime((string)$ev['verified_at'])) ?></span>
                                                    </div>
                                                    <?php if (!empty($ev['verification_notes'])): ?>
                                                        <div class="mt-1 text-dark fst-italic">
                                                            &ldquo;<?= htmlspecialchars((string)$ev['verification_notes']) ?>&rdquo;
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Verification Action Form (When status is resolution_submitted) -->
                        <?php if ($incident['status'] === 'resolution_submitted'): ?>
                            <div class="border-top pt-3 mt-3">
                                <h6 class="fw-bold text-dark mb-2">
                                    <i class="bi bi-shield-check text-primary me-1"></i>Administrative Verification Decision
                                </h6>
                                <p class="small text-secondary mb-3">
                                    Inspect the submitted photo proof and field notes above. Approve to transition the incident to <strong>Resolved</strong> and complete the work order, or request rework to send it back to the assigned officer's in-progress queue.
                                </p>
                                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=<?= $incidentId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="review_evidence">

                                    <div class="mb-3">
                                        <label for="reviewNotes" class="form-label small fw-semibold text-dark">
                                            Verification / Review Notes <span class="text-danger">*</span>
                                        </label>
                                        <textarea name="review_notes" id="reviewNotes" class="form-control form-control-sm" rows="3" required minlength="5" 
                                                  placeholder="Detail your inspection findings, site verification remarks, or instructions for rework..."></textarea>
                                        <div class="form-text small">Minimum 5 characters required for administrative audit record.</div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm fw-bold px-3">
                                            <i class="bi bi-check-circle-fill me-1"></i> Approve &amp; Mark Resolved
                                        </button>
                                        <button type="submit" name="decision" value="request_rework" class="btn btn-warning btn-sm fw-bold text-dark px-3">
                                            <i class="bi bi-arrow-repeat me-1"></i> Request Rework
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Interactive Obfuscated Spatial Map -->
            <div class="card cs-card shadow-sm border-light-subtle overflow-hidden mb-4">
                <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold text-dark">
                        <i class="bi bi-map-fill text-primary me-1"></i>Centroid & Differential Fuzzing Zone
                    </span>
                    <span class="badge bg-secondary-subtle text-dark small">Fuzz Radius: <?= (int)$incident['fuzz_radius_meters'] ?>m</span>
                </div>
                <div id="incidentDetailMap" style="height: 320px; width: 100%;"></div>
                <div class="p-2 bg-light small text-muted text-center" style="font-size: 0.75rem;">
                    <i class="bi bi-shield-check text-success me-1"></i>
                    Spatial Coordinates: <?= number_format((float)$incident['obfuscated_lat'], 5) ?>, <?= number_format((float)$incident['obfuscated_lng'], 5) ?> (Protected from reverse geolocation)
                </div>
            </div>

            <!-- Linked Citizen Reports Table -->
            <div class="card cs-card shadow-sm border-light-subtle overflow-hidden">
                <div class="card-header bg-light py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-collection me-2 text-primary"></i>Consolidated Citizen Reports (<?= count($linkedReports) ?>)
                    </h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Privacy Enclave Active</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light text-secondary text-uppercase" style="font-size: 0.72rem;">
                            <tr>
                                <th>Report Ref</th>
                                <th>Pseudonym</th>
                                <th>Title / Description</th>
                                <th>Intake Coords</th>
                                <th>Filed At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($linkedReports)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No citizen reports linked to this consolidated incident.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($linkedReports as $rep): ?>
                                    <tr>
                                        <td>
                                            <code class="fw-bold"><?= htmlspecialchars($rep['report_reference']) ?></code>
                                        </td>
                                        <td>
                                            <i class="bi bi-person-circle text-muted me-1"></i>
                                            <?= htmlspecialchars($rep['citizen_pseudonym'] ?? 'Anonymous Citizen') ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($rep['title']) ?>">
                                                <?= htmlspecialchars($rep['title']) ?>
                                            </div>
                                            <div class="text-muted text-truncate" style="max-width: 180px; font-size: 0.75rem;">
                                                <?= htmlspecialchars($rep['description']) ?>
                                            </div>
                                        </td>
                                        <td class="font-monospace" style="font-size: 0.75rem;">
                                            <?php if ($rep['raw_lat'] !== null): ?>
                                                <?= number_format((float)$rep['raw_lat'], 4) ?>, <?= number_format((float)$rep['raw_lng'], 4) ?>
                                            <?php else: ?>
                                                <span class="text-muted">No GPS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted" style="font-size: 0.75rem;">
                                            <?= date('d M Y, h:i A', strtotime((string)$rep['created_at'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase" style="font-size: 0.65rem;">
                                                <?= htmlspecialchars($rep['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small text-muted py-2 px-3">
                    <i class="bi bi-lock-fill text-warning me-1"></i>
                    Private contact numbers and emails remain isolated in the restricted <code>citizen_contacts</code> enclave. Raw single-use tracking tokens are never displayed.
                </div>
            </div>
        </div>

        <!-- Right: Priority Breakdown & History Timeline -->
        <div class="col-lg-5">
            <!-- Transparent Priority Score Breakdown Card -->
            <div class="card cs-card shadow-sm p-4 mb-4 border-light-subtle">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="bi bi-bar-chart-steps text-info me-2"></i>Priority Score Intelligence
                    </h6>
                    <span class="badge bg-dark px-3 py-2 fs-6">
                        <?= (int)$incident['priority_score'] ?> / 100
                    </span>
                </div>
                <p class="text-muted small mb-3">
                    Rule-based deterministic decision-support indicator. Strictly transparent; not an AI model.
                </p>

                <?php if (!empty($priBreakdown)): ?>
                    <div class="d-flex flex-column gap-3 small">
                        <!-- 1. Severity -->
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="bi bi-exclamation-diamond text-danger me-1"></i>Severity (35%):</span>
                                <strong class="text-uppercase"><?= htmlspecialchars((string)($priBreakdown['severity']['input'] ?? 'medium')) ?> (Score: <?= $priBreakdown['severity']['subscore'] ?? 50 ?>)</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-danger" style="width: <?= ($priBreakdown['severity']['contribution'] ?? 17.5) / 35.0 * 100 ?>%;"></div>
                            </div>
                        </div>

                        <!-- 2. Report Volume -->
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="bi bi-people text-primary me-1"></i>Report Volume (25%):</span>
                                <strong><?= (int)($priBreakdown['report_volume']['input_count'] ?? 1) ?> Reports (Score: <?= $priBreakdown['report_volume']['subscore'] ?? 20 ?>)</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-primary" style="width: <?= ($priBreakdown['report_volume']['contribution'] ?? 5.0) / 25.0 * 100 ?>%;"></div>
                            </div>
                        </div>

                        <!-- 3. Elapsed Time -->
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="bi bi-clock-history text-warning me-1"></i>Elapsed SLA Time (20%):</span>
                                <strong><?= round((float)($priBreakdown['elapsed_time']['input_hours'] ?? 0.0), 1) ?>h Elapsed (Score: <?= $priBreakdown['elapsed_time']['subscore'] ?? 20 ?>)</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" style="width: <?= ($priBreakdown['elapsed_time']['contribution'] ?? 4.0) / 20.0 * 100 ?>%;"></div>
                            </div>
                        </div>

                        <!-- 4. Category Urgency -->
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="bi bi-lightning text-info me-1"></i>Category Urgency (20%):</span>
                                <strong>SLA <?= (int)($priBreakdown['category_urgency']['sla_hours'] ?? 24) ?>h (Score: <?= $priBreakdown['category_urgency']['subscore'] ?? 50 ?>)</strong>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-info" style="width: <?= ($priBreakdown['category_urgency']['contribution'] ?? 10.0) / 20.0 * 100 ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Active Officer Assignment Card -->
            <div class="card cs-card shadow-sm p-4 mb-4 border-light-subtle">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="bi bi-person-workspace text-warning me-2"></i>Field Work Orders
                    </h6>
                    <?php if (!empty($incident['officer_name'])): ?>
                        <span class="badge cs-badge-<?= htmlspecialchars($incident['assignment_status']) ?> text-uppercase">
                            <?= htmlspecialchars($incident['assignment_status']) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Unassigned</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($incident['officer_name'])): ?>
                    <div class="p-3 bg-light rounded mb-3 small">
                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($incident['officer_name']) ?></div>
                        <div class="text-muted mb-2"><?= htmlspecialchars($incident['officer_department']) ?></div>
                        <?php if (!empty($incident['assignment_instructions'])): ?>
                            <div class="border-top pt-2">
                                <strong>Instructions:</strong>
                                <p class="mb-0 text-secondary mt-1"><em>"<?= htmlspecialchars($incident['assignment_instructions']) ?>"</em></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        No field officer is currently assigned to this incident. Dispatch an officer to begin remediation.
                    </p>
                <?php endif; ?>

                <?php if (count($assignmentHistory) > 1): ?>
                    <div class="small text-muted border-top pt-2">
                        <strong>Previous Dispatches (<?= count($assignmentHistory) ?>):</strong>
                        <ul class="list-unstyled mb-0 mt-2">
                            <?php foreach ($assignmentHistory as $ah): ?>
                                <li class="mb-1">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($ah['assignment_status']) ?></span>
                                    <strong><?= htmlspecialchars($ah['officer_username']) ?></strong>
                                    <span class="text-muted">(<?= date('d M Y', strtotime((string)$ah['assigned_at'])) ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status Transition Audit Timeline -->
            <div class="card cs-card shadow-sm p-4 border-light-subtle">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="bi bi-clock-history text-secondary me-2"></i>Status Lifecycle Timeline
                </h6>

                <div class="timeline small">
                    <?php if (empty($statusHistory)): ?>
                        <p class="text-muted mb-0">No historical status transitions recorded.</p>
                    <?php else: ?>
                        <ul class="list-unstyled position-relative ps-3 border-start mb-0">
                            <?php foreach ($statusHistory as $sh): ?>
                                <li class="mb-3 position-relative">
                                    <div class="position-absolute top-0 start-0 translate-middle rounded-circle bg-primary" style="width: 10px; height: 10px; margin-left: -1px;"></div>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <span class="badge cs-badge-<?= htmlspecialchars($sh['status_from']) ?> text-uppercase" style="font-size:0.65rem;">
                                                <?= htmlspecialchars($sh['status_from']) ?>
                                            </span>
                                            <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                            <span class="badge cs-badge-<?= htmlspecialchars($sh['status_to']) ?> text-uppercase" style="font-size:0.65rem;">
                                                <?= htmlspecialchars($sh['status_to']) ?>
                                            </span>
                                        </div>
                                        <span class="text-muted" style="font-size: 0.72rem;">
                                            <?= date('d M, h:i A', strtotime((string)$sh['created_at'])) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($sh['reason'])): ?>
                                        <div class="text-secondary">
                                            <em>"<?= htmlspecialchars($sh['reason']) ?>"</em>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-muted" style="font-size: 0.72rem;">
                                        By: <strong><?= htmlspecialchars($sh['actor_username'] ?? 'System Automation') ?></strong>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal: Assign / Reassign Officer -->
<!-- ========================================================================= -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=<?= $incidentId ?>" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="action" value="assign">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="assignModalLabel">
                    <i class="bi bi-person-badge text-warning me-2"></i>Dispatch Field Officer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    Incident: <strong class="font-monospace"><?= htmlspecialchars($incident['incident_reference']) ?></strong>
                </div>

                <div class="mb-3">
                    <label for="detailOfficerId" class="form-label small fw-semibold text-dark">
                        Select Field Officer <span class="text-danger">*</span>
                    </label>
                    <select name="officer_id" id="detailOfficerId" class="form-select" required>
                        <option value="">-- Choose Field Officer --</option>
                        <?php foreach ($officers as $off): ?>
                            <option value="<?= (int)$off['id'] ?>" <?= ((int)($incident['officer_id'] ?? 0) === (int)$off['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($off['username']) ?> &bull; <?= htmlspecialchars($off['department']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="detailInstructions" class="form-label small fw-semibold text-dark">
                        Dispatch Instructions (Optional)
                    </label>
                    <textarea name="instructions" id="detailInstructions" class="form-control form-control-sm" rows="3" 
                              placeholder="e.g. Inspect damaged pavers, cordon off lane, deploy road repair team."><?= htmlspecialchars($incident['assignment_instructions'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning btn-sm fw-semibold">
                    <i class="bi bi-send-check me-1"></i>Confirm Dispatch
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal: Transition Status -->
<!-- ========================================================================= -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=<?= $incidentId ?>" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_status">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="statusModalLabel">
                    <i class="bi bi-arrow-repeat text-primary me-2"></i>Transition Incident Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    Current Status: <span class="badge cs-badge-<?= htmlspecialchars($incident['status']) ?> text-uppercase"><?= htmlspecialchars($incident['status']) ?></span>
                </div>

                <div class="mb-3">
                    <label for="detailNewStatus" class="form-label small fw-semibold text-dark">
                        Target Lifecycle Status <span class="text-danger">*</span>
                    </label>
                    <select name="new_status" id="detailNewStatus" class="form-select" required>
                        <option value="">-- Select Status --</option>
                        <?php 
                        $allowed = IncidentManager::ALLOWED_TRANSITIONS[$incident['status']] ?? [];
                        foreach ($allowed as $st): 
                        ?>
                            <option value="<?= htmlspecialchars($st) ?>">
                                <?= strtoupper(str_replace('_', ' ', $st)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="detailStatusReason" class="form-label small fw-semibold text-dark">
                        Resolution / Transition Notes <span id="detailReasonStar" class="text-danger d-none">*</span>
                    </label>
                    <textarea name="reason" id="detailStatusReason" class="form-control form-control-sm" rows="3" 
                              placeholder="Provide justification note. Required when setting to resolved, closed, or rejected."></textarea>
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
    // 1. Initialize Leaflet Map for Centroid & Fuzzing Circle
    const lat = <?= (float)$incident['obfuscated_lat'] ?>;
    const lng = <?= (float)$incident['obfuscated_lng'] ?>;
    const fuzzRadius = <?= (int)$incident['fuzz_radius_meters'] ?>;

    const map = L.map('incidentDetailMap', {
        center: [lat, lng],
        zoom: 15,
        zoomControl: true,
        scrollWheelZoom: false
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Marker
    L.marker([lat, lng]).addTo(map)
        .bindPopup('<strong><?= htmlspecialchars($incident['incident_reference']) ?></strong><br><?= htmlspecialchars($incident['neighborhood']) ?> Centroid')
        .openPopup();

    // Differential Fuzzing Circle
    L.circle([lat, lng], {
        color: '#0d6efd',
        fillColor: '#0d6efd',
        fillOpacity: 0.15,
        radius: fuzzRadius
    }).addTo(map);

    // 2. Dynamic Required Star on Status Change
    const statusSelect = document.getElementById('detailNewStatus');
    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            const val = this.value;
            const star = document.getElementById('detailReasonStar');
            const reasonInput = document.getElementById('detailStatusReason');
            if (['resolved', 'closed', 'rejected'].includes(val)) {
                star.classList.remove('d-none');
                reasonInput.setAttribute('required', 'required');
            } else {
                star.classList.add('d-none');
                reasonInput.removeAttribute('required');
            }
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
