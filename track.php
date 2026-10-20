<?php
declare(strict_types=1);

$pageTitle = 'Track Incident Report';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

$tokenInput = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$reportData = null;
$lookupError = null;

if ($tokenInput !== '') {
    $tokenHash = hash('sha256', $tokenInput);
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT r.report_reference, r.title, r.submitted_severity, r.status, r.created_at,
                    c.name AS category_name, c.department_code, c.resolution_sla_hours,
                    i.incident_reference, i.status AS consolidated_status, i.neighborhood, i.resolved_at
             FROM incident_reports r
             JOIN incident_categories c ON r.category_id = c.id
             LEFT JOIN incidents i ON r.incident_id = i.id
             WHERE r.tracking_token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $reportData = $stmt->fetch();

        if (!$reportData) {
            $lookupError = 'No incident report found matching this tracking token. Please verify the 37-character token string and try again.';
        }
    } catch (Throwable $e) {
        @error_log('[Track Page Error] ' . $e->getMessage());
        $lookupError = 'A system error occurred while looking up the report. Please try again.';
    }
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card cs-card shadow-sm p-4 p-md-5 border-light-subtle mb-4">
                <div class="text-center mb-4">
                    <span class="cs-brand-icon mb-2" style="width:48px; height:48px; font-size:1.6rem;">
                        <i class="bi bi-search"></i>
                    </span>
                    <h2 class="fw-bold text-dark mt-2 mb-1">Track Civic Incident Report</h2>
                    <p class="text-muted small">
                        Verify real-time status and municipal dispatch updates using your private single-use tracking token.
                    </p>
                </div>

                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/track.php" id="directTrackForm" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">

                    <div class="input-group input-group-lg shadow-sm">
                        <span class="input-group-text bg-light text-muted border-end-0">
                            <i class="bi bi-key-fill"></i>
                        </span>
                        <input type="text" name="token" id="tokenField" class="form-control border-start-0" 
                               value="<?= htmlspecialchars($tokenInput) ?>" 
                               placeholder="e.g. cstk_vesu_waterleak_7a9f2b1c4e6d" 
                               aria-label="Enter your private tracking token" required autocomplete="off">
                        <button type="submit" class="btn btn-cs-primary px-4 fw-semibold">
                            <i class="bi bi-arrow-right me-1"></i> Track Report
                        </button>
                    </div>
                </form>

                <!-- Seed Data Quick Test Buttons -->
                <div class="p-3 bg-light rounded-3 border border-light-subtle small mb-4">
                    <strong class="d-block text-dark mb-2"><i class="bi bi-lightbulb text-warning me-1"></i>Test with Seed Demonstration Tokens:</strong>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('tokenField').value='cstk_vesu_waterleak_7a9f2b1c4e6d'; document.getElementById('directTrackForm').submit();">
                            Vesu Water Rupture (Resolved)
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('tokenField').value='cstk_adajan_pothole_dup_3e2d1c0b'; document.getElementById('directTrackForm').submit();">
                            Adajan Pothole (In Progress)
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('tokenField').value='cstk_rander_garbage_4f5e6d7c8b9a'; document.getElementById('directTrackForm').submit();">
                            Rander Waste (Assigned)
                        </button>
                    </div>
                </div>

                <?php if ($lookupError): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                        <div><?= htmlspecialchars($lookupError) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($reportData): ?>
                    <?php
                    $rawStatus = !empty($reportData['consolidated_status']) ? $reportData['consolidated_status'] : $reportData['status'];
                    $badgeClass = match ($rawStatus) {
                        'resolved'             => 'cs-badge-resolved',
                        'closed'               => 'cs-badge-resolved',
                        'resolution_submitted' => 'cs-badge-in_progress',
                        'in_progress'          => 'cs-badge-in_progress',
                        'assigned'             => 'cs-badge-assigned',
                        'duplicate'            => 'cs-badge-assigned',
                        default                => 'cs-badge-pending'
                    };
                    $statusTitle = match ($rawStatus) {
                        'resolved'             => 'Verified Resolved',
                        'closed'               => 'Closed (Remediation Complete)',
                        'resolution_submitted' => 'Field Remediation Completed — Pending Verification',
                        'in_progress'          => 'In Progress (Active Work Order)',
                        'assigned'             => 'Assigned to Municipal Department',
                        'duplicate'            => 'Verified Duplicate & Consolidated',
                        'pending_review'       => 'Pending Triage Review',
                        default                => ucfirst(str_replace('_', ' ', $rawStatus))
                    };
                    ?>
                    <div class="card border border-info-subtle bg-light-subtle p-4 rounded-3 shadow-sm">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 border-bottom pb-3 mb-3">
                            <div>
                                <span class="cs-badge <?= $badgeClass ?> mb-2"><?= htmlspecialchars($statusTitle) ?></span>
                                <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($reportData['title']) ?></h4>
                                <div class="text-secondary small">
                                    <span class="badge bg-secondary-subtle text-dark border me-1 font-monospace"><?= htmlspecialchars($reportData['report_reference']) ?></span>
                                    <span>&bull; Category: <strong><?= htmlspecialchars($reportData['category_name']) ?></strong> (<?= htmlspecialchars($reportData['department_code']) ?>)</span>
                                </div>
                            </div>
                            <?php if (!empty($reportData['incident_reference'])): ?>
                                <div class="text-end">
                                    <div class="small text-muted">Consolidated Incident:</div>
                                    <span class="badge bg-primary font-monospace fs-6"><?= htmlspecialchars($reportData['incident_reference']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3 small mb-3">
                            <div class="col-sm-6">
                                <span class="text-muted d-block">Submission Timestamp:</span>
                                <span class="fw-semibold text-dark"><?= date('d M Y, h:i A', strtotime($reportData['created_at'])) ?></span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block">Target SLA Window:</span>
                                <span class="fw-semibold text-dark"><?= (int)$reportData['resolution_sla_hours'] ?> Hours</span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block">Reported Severity:</span>
                                <span class="cs-badge cs-badge-<?= htmlspecialchars($reportData['submitted_severity']) ?> text-capitalize">
                                    <?= htmlspecialchars($reportData['submitted_severity']) ?>
                                </span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block">Approximate Neighborhood:</span>
                                <span class="fw-semibold text-dark"><i class="bi bi-geo-alt text-info me-1"></i><?= htmlspecialchars($reportData['neighborhood'] ?? 'Surat Municipal Jurisdiction') ?></span>
                            </div>
                        </div>

                        <?php if (!empty($reportData['resolved_at'])): ?>
                            <div class="p-3 bg-success bg-opacity-10 rounded border border-success-subtle text-success small">
                                <i class="bi bi-check-circle-fill me-1"></i> <strong>Resolution Confirmed:</strong> 
                                This civic issue was verified and closed on <?= date('d M Y, h:i A', strtotime($reportData['resolved_at'])) ?>.
                            </div>
                        <?php endif; ?>

                        <div class="mt-3 pt-3 border-top border-light-subtle d-flex align-items-center gap-2 small text-muted">
                            <i class="bi bi-shield-check text-success fs-5"></i>
                            <span>Whistleblower Identity Protected: Submitter pseudonym, raw sensor coordinates, and contact details are excluded from this tracking view.</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
