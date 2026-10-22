<?php
declare(strict_types=1);

$pageTitle = 'Duplicate Incident Review Queue';
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

// -----------------------------------------------------------------------------
// Handle Review Decision Submission (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        $errors[] = 'Security token expired or invalid. Please refresh the page.';
    }

    $candidateId = isset($_POST['candidate_id']) ? (int)$_POST['candidate_id'] : 0;
    $decision = (string)($_POST['decision'] ?? '');
    $decisionNotes = trim((string)($_POST['decision_notes'] ?? ''));

    if ($candidateId <= 0) {
        $errors[] = 'Invalid candidate identifier.';
    }

    if (!in_array($decision, ['confirmed_duplicate', 'marked_distinct'], true)) {
        $errors[] = 'Invalid review decision selected.';
    }

    if (empty($errors)) {
        try {
            // Retrieve candidate with report and incident references
            $stmt = $pdo->prepare(
                'SELECT c.*, r.report_reference, i.incident_reference 
                 FROM incident_duplicate_candidates c
                 JOIN incident_reports r ON r.id = c.report_id
                 JOIN incidents i ON i.id = c.candidate_incident_id
                 WHERE c.id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $candidateId]);
            $candidate = $stmt->fetch();

            if (!$candidate) {
                $errors[] = 'Candidate duplicate record not found.';
            } else {
                $pdo->beginTransaction();

                $now = date('Y-m-d H:i:s');
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $ipHash = hash('sha256', $clientIp . (string)Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));

                if ($decision === 'confirmed_duplicate') {
                    // 1. Update candidate record
                    $upCandidate = $pdo->prepare(
                        'UPDATE incident_duplicate_candidates 
                         SET review_status = :status,
                             reviewed_by_user_id = :reviewer_id,
                             decision_notes = :notes,
                             reviewed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $upCandidate->execute([
                        ':status'      => 'confirmed_duplicate',
                        ':reviewer_id' => $adminUserId,
                        ':notes'       => $decisionNotes !== '' ? $decisionNotes : 'Confirmed duplicate by municipal administrator.',
                        ':id'          => $candidateId
                    ]);

                    // 2. Link citizen report to the consolidated incident without deleting report
                    $upReport = $pdo->prepare(
                        'UPDATE incident_reports 
                         SET incident_id = :incident_id,
                             status = \'duplicate\'
                         WHERE id = :report_id'
                    );
                    $upReport->execute([
                        ':incident_id' => $candidate['candidate_incident_id'],
                        ':report_id'   => $candidate['report_id']
                    ]);

                    // 3. Recalculate priority score and distinct report count for the consolidated incident
                    PriorityScorer::updateIncidentPriority((int)$candidate['candidate_incident_id'], $pdo);

                    // 4. Audit Log entry
                    $auditStmt = $pdo->prepare(
                        'INSERT INTO audit_logs 
                         (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                         VALUES (:actor_id, \'admin\', \'DUPLICATE_CONFIRMED\', \'incident_duplicate_candidate\', :target_id, :ip_hash, :metadata, :now)'
                    );
                    $auditStmt->execute([
                        ':actor_id'  => $adminUserId,
                        ':target_id' => (string)$candidateId,
                        ':ip_hash'   => $ipHash,
                        ':metadata'  => json_encode([
                            'report_id'          => $candidate['report_id'],
                            'report_reference'   => $candidate['report_reference'],
                            'incident_id'        => $candidate['candidate_incident_id'],
                            'incident_reference' => $candidate['incident_reference'],
                            'match_score'        => $candidate['match_score'],
                            'notes'              => $decisionNotes
                        ]),
                        ':now'       => $now
                    ]);

                    $flashMessage = sprintf(
                        'Report %s successfully confirmed as duplicate of Incident %s. Priority score updated.',
                        htmlspecialchars($candidate['report_reference']),
                        htmlspecialchars($candidate['incident_reference'])
                    );
                } else {
                    // Marked Distinct
                    // 1. Update candidate record
                    $upCandidate = $pdo->prepare(
                        'UPDATE incident_duplicate_candidates 
                         SET review_status = :status,
                             reviewed_by_user_id = :reviewer_id,
                             decision_notes = :notes,
                             reviewed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $upCandidate->execute([
                        ':status'      => 'marked_distinct',
                        ':reviewer_id' => $adminUserId,
                        ':notes'       => $decisionNotes !== '' ? $decisionNotes : 'Marked as distinct incident upon human review.',
                        ':id'          => $candidateId
                    ]);

                    // 2. Promote report to standalone canonical incident if not already linked
                    $promoResult = IncidentManager::createIncidentFromReport((int)$candidate['report_id'], $pdo, $adminUserId);
                    if (empty($promoResult['success'])) {
                        throw new RuntimeException(
                            $promoResult['message'] ?? 'Failed to promote report into canonical incident.'
                        );
                    }

                    // 3. Audit Log entry
                    $auditStmt = $pdo->prepare(
                        'INSERT INTO audit_logs 
                         (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                         VALUES (:actor_id, \'admin\', \'DUPLICATE_MARKED_DISTINCT\', \'incident_duplicate_candidate\', :target_id, :ip_hash, :metadata, :now)'
                    );
                    $auditStmt->execute([
                        ':actor_id'  => $adminUserId,
                        ':target_id' => (string)$candidateId,
                        ':ip_hash'   => $ipHash,
                        ':metadata'  => json_encode([
                            'report_id'          => $candidate['report_id'],
                            'report_reference'   => $candidate['report_reference'],
                            'incident_id'        => $candidate['candidate_incident_id'],
                            'incident_reference' => $candidate['incident_reference'],
                            'promoted_incident'  => $promoResult['incident_reference'] ?? null,
                            'notes'              => $decisionNotes
                        ]),
                        ':now'       => $now
                    ]);

                    if (!empty($promoResult['success']) && !empty($promoResult['incident_reference'])) {
                        $flashMessage = sprintf(
                            'Report %s validated as distinct from Incident %s and promoted to Incident %s.',
                            htmlspecialchars($candidate['report_reference']),
                            htmlspecialchars($candidate['incident_reference']),
                            htmlspecialchars($promoResult['incident_reference'])
                        );
                    } else {
                        $flashMessage = sprintf(
                            'Report %s validated as distinct from Incident %s.',
                            htmlspecialchars($candidate['report_reference']),
                            htmlspecialchars($candidate['incident_reference'])
                        );
                    }
                }

                $pdo->commit();

                if (Response::isJsonRequest()) {
                    Response::ok($flashMessage);
                }

                $_SESSION['_flash_success'] = $flashMessage;
                header('Location: ' . BASE_URL . '/admin/duplicates.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[Review Decision Error] ' . $e->getMessage());
            $errors[] = 'A database error occurred while recording the decision. Please try again.';
        }
    }

    if (Response::isJsonRequest() && !empty($errors)) {
        Response::badRequest(implode(' ', $errors));
    }
}

// -----------------------------------------------------------------------------
// Query Statistics & Filtered Candidate List
// -----------------------------------------------------------------------------
$filterStatus = $_GET['status'] ?? 'pending_review';
if (!in_array($filterStatus, ['pending_review', 'confirmed_duplicate', 'marked_distinct', 'all'], true)) {
    $filterStatus = 'pending_review';
}

// Total Counts
$countStmt = $pdo->query(
    'SELECT review_status, COUNT(*) as count 
     FROM incident_duplicate_candidates 
     GROUP BY review_status'
);
$statusCounts = [
    'pending_review'      => 0,
    'confirmed_duplicate' => 0,
    'marked_distinct'     => 0,
    'all'                 => 0
];
while ($row = $countStmt->fetch()) {
    $s = $row['review_status'];
    $cnt = (int)$row['count'];
    if (isset($statusCounts[$s])) {
        $statusCounts[$s] = $cnt;
    }
    $statusCounts['all'] += $cnt;
}

// Query Candidates with Joined Data
$sql = 'SELECT c.*, 
               r.report_reference, r.title AS report_title, r.description AS report_desc,
               r.raw_lat, r.raw_lng, r.location_description AS report_loc, r.created_at AS report_time,
               rc.name AS report_category, rc.icon AS report_category_icon,
               i.incident_reference, i.title AS inc_title, i.severity AS inc_severity,
               i.priority_score AS inc_priority, i.status AS inc_status, i.neighborhood AS inc_neighborhood,
               ic.name AS inc_category, ic.icon AS inc_category_icon,
               u.username AS reviewer_username
        FROM incident_duplicate_candidates c
        JOIN incident_reports r ON r.id = c.report_id
        JOIN incident_categories rc ON rc.id = r.category_id
        JOIN incidents i ON i.id = c.candidate_incident_id
        JOIN incident_categories ic ON ic.id = i.category_id
        LEFT JOIN users u ON u.id = c.reviewed_by_user_id';

if ($filterStatus !== 'all') {
    $sql .= ' WHERE c.review_status = :status';
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 100';

$candStmt = $pdo->prepare($sql);
if ($filterStatus !== 'all') {
    $candStmt->execute([':status' => $filterStatus]);
} else {
    $candStmt->execute();
}
$candidatesList = $candStmt->fetchAll();

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb & Title -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="text-decoration-none">Admin Console</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Duplicate Candidate Queue</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark mb-0">
                <i class="bi bi-intersect text-warning me-2"></i>Duplicate Candidate Review
            </h2>
            <p class="text-muted small mb-0">Rule-based spatial, category, text & time matching with human-in-the-loop validation</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Admin Dashboard
            </a>
        </div>
    </div>

    <!-- Feedback Alerts -->
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

    <!-- Status Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="?status=pending_review" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-warning border-4 <?= $filterStatus === 'pending_review' ? 'shadow-sm bg-warning-subtle' : '' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">Pending Review</div>
                    <div class="fs-3 fw-bold text-dark mt-1"><?= $statusCounts['pending_review'] ?></div>
                    <div class="small text-secondary">Awaiting validation</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=confirmed_duplicate" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-success border-4 <?= $filterStatus === 'confirmed_duplicate' ? 'shadow-sm bg-success-subtle' : '' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">Confirmed Duplicates</div>
                    <div class="fs-3 fw-bold text-success mt-1"><?= $statusCounts['confirmed_duplicate'] ?></div>
                    <div class="small text-secondary">Merged & scored</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=marked_distinct" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-info border-4 <?= $filterStatus === 'marked_distinct' ? 'shadow-sm bg-info-subtle' : '' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">Marked Distinct</div>
                    <div class="fs-3 fw-bold text-info mt-1"><?= $statusCounts['marked_distinct'] ?></div>
                    <div class="small text-secondary">Standalone issues</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=all" class="text-decoration-none">
                <div class="card cs-card p-3 border-start border-secondary border-4 <?= $filterStatus === 'all' ? 'shadow-sm bg-light' : '' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">All Candidates</div>
                    <div class="fs-3 fw-bold text-secondary mt-1"><?= $statusCounts['all'] ?></div>
                    <div class="small text-secondary">Total evaluated pairs</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Filter Nav Tabs -->
    <ul class="nav nav-tabs mb-4 border-bottom">
        <li class="nav-item">
            <a class="nav-link <?= $filterStatus === 'pending_review' ? 'active fw-bold' : '' ?>" href="?status=pending_review">
                <i class="bi bi-hourglass-split me-1"></i> Pending Review (<?= $statusCounts['pending_review'] ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $filterStatus === 'confirmed_duplicate' ? 'active fw-bold' : '' ?>" href="?status=confirmed_duplicate">
                <i class="bi bi-check2-circle me-1"></i> Confirmed Duplicates (<?= $statusCounts['confirmed_duplicate'] ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $filterStatus === 'marked_distinct' ? 'active fw-bold' : '' ?>" href="?status=marked_distinct">
                <i class="bi bi-distribute-vertical me-1"></i> Distinct (<?= $statusCounts['marked_distinct'] ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $filterStatus === 'all' ? 'active fw-bold' : '' ?>" href="?status=all">
                <i class="bi bi-list-ul me-1"></i> All Candidates (<?= $statusCounts['all'] ?>)
            </a>
        </li>
    </ul>

    <!-- Candidate List -->
    <?php if (empty($candidatesList)): ?>
        <div class="card cs-card shadow-sm p-5 text-center text-muted">
            <i class="bi bi-shield-check fs-1 text-success mb-2"></i>
            <h5 class="fw-bold text-dark">Queue Clear</h5>
            <p class="mb-0">No candidate duplicate matches matching the current filter (<strong><?= htmlspecialchars($filterStatus) ?></strong>).</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-4">
            <?php foreach ($candidatesList as $cand): 
                $score = (float)$cand['match_score'];
                $dist = (int)$cand['spatial_distance_meters'];
                $timeDiff = (int)$cand['time_difference_minutes'];
                $timeFormatted = $timeDiff < 60 ? "{$timeDiff} mins" : round($timeDiff / 60, 1) . ' hours';
                $isCatMatched = (bool)$cand['category_matched'];

                $badgeClass = 'bg-secondary';
                $confidence = 'Low Confidence';
                if ($score >= 80.0) {
                    $badgeClass = 'bg-danger';
                    $confidence = 'High Match Confidence';
                } elseif ($score >= 50.0) {
                    $badgeClass = 'bg-warning text-dark';
                    $confidence = 'Moderate Match Confidence';
                }
            ?>
                <div class="card cs-card shadow-sm border-light-subtle overflow-hidden">
                    <!-- Top Match Metric Banner -->
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2 py-3 px-4">
                        <div class="d-flex align-items-center gap-3">
                            <div>
                                <span class="badge <?= $badgeClass ?> px-3 py-2 fs-6">
                                    <i class="bi bi-cpu me-1"></i> Match Score: <?= number_format($score, 1) ?>%
                                </span>
                            </div>
                            <span class="small fw-semibold text-muted d-none d-sm-inline"><?= $confidence ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($cand['review_status'] === 'pending_review'): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending Admin Review</span>
                            <?php elseif ($cand['review_status'] === 'confirmed_duplicate'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Confirmed Duplicate</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark"><i class="bi bi-slash-circle me-1"></i>Marked Distinct</span>
                            <?php endif; ?>
                            <span class="small text-muted">Evaluated: <?= date('d M Y, h:i A', strtotime((string)$cand['created_at'])) ?></span>
                        </div>
                    </div>

                    <!-- Comparison Body -->
                    <div class="card-body p-4">
                        <!-- Metric Summary Pills -->
                        <div class="row g-2 mb-3 bg-light rounded p-2 text-center small">
                            <div class="col-md-3">
                                <span class="text-muted">Geodesic Distance:</span>
                                <strong class="ms-1 text-dark"><i class="bi bi-geo-alt me-1 text-danger"></i><?= $dist ?> meters</strong>
                            </div>
                            <div class="col-md-3">
                                <span class="text-muted">Category Match:</span>
                                <?php if ($isCatMatched): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1"><i class="bi bi-check-lg me-1"></i>Same Category</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1"><i class="bi bi-x-lg me-1"></i>Different Category</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <span class="text-muted">Text Similarity:</span>
                                <strong class="ms-1 text-dark"><?= number_format((float)$cand['text_similarity_score'], 1) ?>%</strong>
                            </div>
                            <div class="col-md-3">
                                <span class="text-muted">Time Proximity:</span>
                                <strong class="ms-1 text-dark"><i class="bi bi-clock-history me-1 text-primary"></i><?= $timeFormatted ?> apart</strong>
                            </div>
                        </div>

                        <!-- Side-by-Side Comparison -->
                        <div class="row g-4">
                            <!-- Left: Submitted Citizen Report -->
                            <div class="col-lg-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                            <i class="bi bi-file-earmark-text me-1"></i>New Citizen Report
                                        </span>
                                        <code class="fw-bold"><?= htmlspecialchars($cand['report_reference']) ?></code>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($cand['report_title']) ?></h6>
                                    <p class="text-muted small mb-2"><?= nl2br(htmlspecialchars($cand['report_desc'])) ?></p>

                                    <div class="small text-secondary pt-2 border-top">
                                        <div class="mb-1">
                                            <i class="bi <?= htmlspecialchars($cand['report_category_icon'] ?? 'bi-tag') ?> me-1 text-info"></i>
                                            <strong>Category:</strong> <?= htmlspecialchars($cand['report_category']) ?>
                                        </div>
                                        <div>
                                            <i class="bi bi-pin-map me-1 text-danger"></i>
                                            <strong>Location:</strong> 
                                            <?= $cand['report_loc'] ? htmlspecialchars($cand['report_loc']) : 'GPS Pin (' . number_format((float)$cand['raw_lat'], 5) . ', ' . number_format((float)$cand['raw_lng'], 5) . ')' ?>
                                        </div>
                                        <div class="mt-1 text-muted" style="font-size: 0.75rem;">
                                            Filed: <?= date('d M Y, h:i A', strtotime((string)$cand['report_time'])) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Existing Consolidated Incident Candidate -->
                            <div class="col-lg-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-secondary-subtle text-dark border">
                                            <i class="bi bi-building me-1"></i>Existing Consolidated Incident
                                        </span>
                                        <code class="fw-bold text-dark"><?= htmlspecialchars($cand['incident_reference']) ?></code>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($cand['inc_title']) ?></h6>
                                    
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="badge cs-badge-<?= htmlspecialchars($cand['inc_severity']) ?> text-uppercase">
                                            <?= htmlspecialchars($cand['inc_severity']) ?>
                                        </span>
                                        <span class="badge bg-dark">
                                            Priority: <?= (int)$cand['inc_priority'] ?>/100
                                        </span>
                                        <span class="badge cs-badge-<?= htmlspecialchars($cand['inc_status']) ?> text-uppercase">
                                            <?= htmlspecialchars($cand['inc_status']) ?>
                                        </span>
                                    </div>

                                    <div class="small text-secondary pt-2 border-top">
                                        <div class="mb-1">
                                            <i class="bi <?= htmlspecialchars($cand['inc_category_icon'] ?? 'bi-tag') ?> me-1 text-info"></i>
                                            <strong>Category:</strong> <?= htmlspecialchars($cand['inc_category']) ?>
                                        </div>
                                        <div>
                                            <i class="bi bi-geo-alt me-1 text-secondary"></i>
                                            <strong>Neighborhood:</strong> <?= htmlspecialchars($cand['inc_neighborhood'] ?? 'Surat') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Review Action / History Section -->
                        <div class="mt-4 pt-3 border-top">
                            <?php if ($cand['review_status'] === 'pending_review'): ?>
                                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/admin/duplicates.php" class="row g-2 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                                    <input type="hidden" name="candidate_id" value="<?= (int)$cand['id'] ?>">

                                    <div class="col-md-7">
                                        <label class="form-label small fw-semibold text-muted mb-1">
                                            <i class="bi bi-journal-text me-1"></i>Decision Justification Notes (Optional)
                                        </label>
                                        <input type="text" name="decision_notes" class="form-control form-control-sm" placeholder="e.g. Visual confirmation of crater paver overlap, confirmed duplicate submission.">
                                    </div>

                                    <div class="col-md-5 d-flex gap-2">
                                        <button type="submit" name="decision" value="confirmed_duplicate" class="btn btn-success btn-sm flex-grow-1" onclick="return confirm('Confirm that Report <?= htmlspecialchars($cand['report_reference']) ?> is a duplicate of Incident <?= htmlspecialchars($cand['incident_reference']) ?>?');">
                                            <i class="bi bi-check-circle me-1"></i> Confirm Duplicate
                                        </button>
                                        <button type="submit" name="decision" value="marked_distinct" class="btn btn-outline-secondary btn-sm flex-grow-1" onclick="return confirm('Mark Report <?= htmlspecialchars($cand['report_reference']) ?> as distinct from Incident <?= htmlspecialchars($cand['incident_reference']) ?>?');">
                                            <i class="bi bi-slash-circle me-1"></i> Mark Distinct
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="bg-light rounded p-3 d-flex align-items-center justify-content-between flex-wrap gap-2 small">
                                    <div>
                                        <strong>Decision:</strong> 
                                        <?php if ($cand['review_status'] === 'confirmed_duplicate'): ?>
                                            <span class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i>Confirmed Duplicate</span>
                                        <?php else: ?>
                                            <span class="text-info fw-bold"><i class="bi bi-slash-circle me-1"></i>Marked Distinct</span>
                                        <?php endif; ?>
                                        <span class="text-muted ms-2">&bull; Reviewer: <strong><?= htmlspecialchars($cand['reviewer_username'] ?? 'Administrator') ?></strong></span>
                                        <span class="text-muted ms-2">&bull; Date: <?= date('d M Y, h:i A', strtotime((string)$cand['reviewed_at'])) ?></span>
                                    </div>
                                    <?php if (!empty($cand['decision_notes'])): ?>
                                        <div class="w-100 text-muted mt-1">
                                            <i class="bi bi-chat-left-quote me-1"></i><em>"<?= htmlspecialchars($cand['decision_notes']) ?>"</em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
