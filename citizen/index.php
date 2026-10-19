<?php
declare(strict_types=1);

$pageTitle = 'Citizen Portal';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict citizen authentication
requireAuth('citizen');

$userId = Session::getUserId();
$pdo = Database::getConnection();

// Retrieve citizen's submitted reports from database
$myReports = [];
$totalFiled = 0;
$inProgressCount = 0;
$resolvedCount = 0;

try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.report_reference, r.title, r.submitted_severity, r.status, r.created_at,
                c.name AS category_name, c.department_code,
                a.file_path, a.exif_scrubbed,
                i.incident_reference, i.status AS consolidated_status
         FROM incident_reports r
         JOIN incident_categories c ON r.category_id = c.id
         LEFT JOIN incident_attachments a ON a.report_id = r.id
         LEFT JOIN incidents i ON r.incident_id = i.id
         WHERE r.citizen_id = :uid
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([':uid' => $userId]);
    $myReports = $stmt->fetchAll();

    $totalFiled = count($myReports);
    foreach ($myReports as $rep) {
        $effective = $rep['status'];
        if (!empty($rep['consolidated_status'])) {
            if (in_array($rep['consolidated_status'], ['resolved', 'closed'], true)) {
                $effective = 'resolved';
            } elseif (in_array($rep['consolidated_status'], ['assigned', 'in_progress', 'triaged'], true)) {
                $effective = $rep['consolidated_status'];
            }
        }
        if ($effective === 'resolved') {
            $resolvedCount++;
        } elseif (!in_array($effective, ['rejected'], true)) {
            $inProgressCount++;
        }
    }
} catch (Throwable $e) {
    @error_log('[Citizen Portal Error] ' . $e->getMessage());
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-person-workspace text-info me-2"></i>Citizen Workspace</h2>
            <p class="text-muted mb-0">
                Pseudonym: <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Citizen') ?></strong> &bull; Anonymous reporting and resolution tracking
            </p>
        </div>
        <div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/report.php" class="btn btn-cs-primary">
                <i class="bi bi-plus-circle me-1"></i> Report New Incident
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card cs-card p-3 shadow-xs">
                <div class="text-muted small fw-semibold">FILED INCIDENTS</div>
                <div class="fs-3 fw-bold text-dark mt-1"><?= $totalFiled ?></div>
                <div class="text-secondary small mt-1">Total reports under pseudonym</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card cs-card p-3 shadow-xs">
                <div class="text-muted small fw-semibold">UNDER TRIAGE / IN-PROGRESS</div>
                <div class="fs-3 fw-bold text-info mt-1"><?= $inProgressCount ?></div>
                <div class="text-secondary small mt-1">Under review or field dispatch</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card cs-card p-3 shadow-xs">
                <div class="text-muted small fw-semibold">VERIFIED RESOLVED</div>
                <div class="fs-3 fw-bold text-success mt-1"><?= $resolvedCount ?></div>
                <div class="text-secondary small mt-1">Remedial actions confirmed</div>
            </div>
        </div>
    </div>

    <!-- Recent Incident Reports Ledger -->
    <div class="card cs-card shadow-sm p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-card-checklist me-2 text-info"></i>My Submitted Reports</h5>
            <span class="small text-muted">Showing all incidents filed under this identity</span>
        </div>

        <?php if (empty($myReports)): ?>
            <div class="p-5 text-center text-muted bg-light rounded-3 border border-dashed">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                <h6 class="fw-bold text-dark">No Incidents Reported Yet</h6>
                <p class="small text-secondary mb-3">Notice a road hazard, open drainage, or broken streetlight? Report it safely with differential geo-privacy.</p>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/report.php" class="btn btn-cs-primary btn-sm">
                    <i class="bi bi-megaphone-fill me-1"></i> File Your First Incident
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Title</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Evidence</th>
                            <th scope="col">Date Filed</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myReports as $report): ?>
                            <?php
                            $rawStatus = $report['status'];
                            $effectiveStatus = $rawStatus;
                            if (!empty($report['consolidated_status'])) {
                                if (in_array($report['consolidated_status'], ['resolved', 'closed'], true)) {
                                    $effectiveStatus = 'resolved';
                                } elseif (in_array($report['consolidated_status'], ['assigned', 'in_progress', 'triaged'], true)) {
                                    $effectiveStatus = $report['consolidated_status'];
                                }
                            }

                            $badgeClass = match ($effectiveStatus) {
                                'resolved'       => 'cs-badge-resolved',
                                'in_progress'    => 'cs-badge-in_progress',
                                'assigned'       => 'cs-badge-assigned',
                                'triaged'        => 'cs-badge-pending',
                                'reported'       => 'cs-badge-pending',
                                'consolidated'   => 'cs-badge-in_progress',
                                'duplicate'      => 'cs-badge-in_progress',
                                'standalone'     => 'cs-badge-in_progress',
                                'rejected'       => 'cs-badge-critical',
                                default          => 'cs-badge-pending'
                            };
                            $statusLabel = match ($effectiveStatus) {
                                'resolved'       => 'Verified Resolved',
                                'in_progress'    => 'Field Work In Progress',
                                'assigned'       => 'Assigned to Officer',
                                'triaged'        => 'Triage Verified',
                                'reported'       => 'Reported',
                                'pending_review' => 'Pending Triage',
                                'consolidated'   => 'Consolidated',
                                'duplicate'      => 'Duplicate Merged',
                                'standalone'     => 'Active Standalone',
                                'rejected'       => 'Rejected',
                                default          => ucfirst(str_replace('_', ' ', $effectiveStatus))
                            };
                            ?>
                            <tr>
                                <td class="font-monospace fw-semibold text-primary">
                                    <div><?= htmlspecialchars($report['report_reference']) ?></div>
                                    <?php if (!empty($report['incident_reference'])): ?>
                                        <div class="small"><span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size:0.7rem;"><?= htmlspecialchars($report['incident_reference']) ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-dark">
                                    <?= htmlspecialchars($report['title']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($report['category_name']) ?></span>
                                </td>
                                <td>
                                    <span class="cs-badge cs-badge-<?= htmlspecialchars($report['submitted_severity']) ?> text-capitalize">
                                        <?= htmlspecialchars($report['submitted_severity']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="cs-badge <?= $badgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($report['file_path'])): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="EXIF sanitized evidence attached">
                                            <i class="bi bi-image me-1"></i>Photo
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-secondary">
                                    <?= date('d M Y, h:i A', strtotime($report['created_at'])) ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/track.php?token=demo" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Track incident details" onclick="alert('Use your private single-use tracking token to inspect this report.'); return false;">
                                        <i class="bi bi-search"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
