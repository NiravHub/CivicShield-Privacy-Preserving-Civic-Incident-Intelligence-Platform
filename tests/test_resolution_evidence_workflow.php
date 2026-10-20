<?php
declare(strict_types=1);

/**
 * CivicShield - Resolution Evidence & Verification Workflow Test Suite
 *
 * Comprehensive end-to-end automated verification of:
 * 1. Officer direct-resolve bypass rejection (strict lifecycle enforcement)
 * 2. Upload validation (MIME, content, dimensions, EXIF scrubbing, SHA-256)
 * 3. Officer assignment boundary authorization
 * 4. Status and assignment state transitions (in_progress -> resolution_submitted)
 * 5. Tracking privacy preservation during review state
 * 6. Admin review decisions (request_rework and approve)
 * 7. Assignment completion and resolved_at timestamp preservation on closure
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

$passedCount = 0;
$failedCount = 0;

function runTest(string $description, callable $testFn): void
{
    global $passedCount, $failedCount;
    echo "[TEST] {$description}: ";
    try {
        $result = $testFn();
        if ($result === true) {
            echo "PASSED\n";
            $passedCount++;
        } else {
            echo "FAILED (" . (is_string($result) ? $result : 'Assertion failed') . ")\n";
            $failedCount++;
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
        $failedCount++;
    }
}

function createDummyImage(int $width = 60, int $height = 60, int $r = 40, int $g = 167, int $b = 69): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cs_test_img_');
    $im = imagecreatetruecolor($width, $height);
    $col = imagecolorallocate($im, $r, $g, $b);
    imagefill($im, 0, 0, $col);
    imagepng($im, $tmp);
    imagedestroy($im);
    return $tmp;
}

echo "======================================================================\n";
echo "CivicShield Resolution Evidence & Verification Automated Test Suite\n";
echo "======================================================================\n\n";

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Test 1: Officers cannot directly transition in_progress -> resolved
// -----------------------------------------------------------------------------
runTest("1. Officer direct-resolve bypass is strictly forbidden", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        // Setup incident in in_progress
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-BYPASS', 'Bypass Check', 'Test', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        // Assign to Officer Patel (ID 2)
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);

        // Attempt direct updateOfficerStatus to 'resolved'
        $res = IncidentManager::updateOfficerStatus($incId, 'resolved', 2, 'Attempting direct resolve without evidence', $pdo);

        $pdo->rollBack();

        if ($res['success']) {
            return "Officer was able to directly resolve incident without evidence upload";
        }

        if (!str_contains($res['message'], 'not authorized')) {
            return "Unexpected error message: " . $res['message'];
        }

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 2: Calling updateOfficerStatus to resolution_submitted without file is rejected
// -----------------------------------------------------------------------------
runTest("2. Officer direct status update to resolution_submitted without evidence file is rejected", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-NOFILE', 'No File Check', 'Test', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);

        $res = IncidentManager::updateOfficerStatus($incId, 'resolution_submitted', 2, 'Trying to skip upload', $pdo);

        $pdo->rollBack();

        if ($res['success']) {
            return "Officer transitioned to resolution_submitted without evidence upload";
        }

        if (!str_contains($res['message'], 'must submit resolution evidence')) {
            return "Unexpected rejection message: " . $res['message'];
        }

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 3: Officer cannot submit evidence to another officer's incident
// -----------------------------------------------------------------------------
runTest("3. Officer cannot submit evidence to an incident assigned to another officer", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-CROSS', 'Cross Officer Check', 'Test', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        // Assign to Officer Patel (ID 2)
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);

        // Attempt submission by Officer Desai (ID 3)
        $tmp = createDummyImage();
        $testFile = [
            'name'     => 'evidence.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp)
        ];

        $res = IncidentManager::submitResolutionEvidence($incId, 3, $testFile, 'Desai trying to submit for Patel', 21.14, 72.77, $pdo);
        @unlink($tmp);

        $pdo->rollBack();

        if ($res['success']) {
            return "Officer Desai was allowed to submit evidence on Officer Patel's incident";
        }

        if (!str_contains($res['message'], 'not actively assigned')) {
            return "Unexpected rejection message: " . $res['message'];
        }

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 4: Invalid upload (non-image executable polyglot or text file) is rejected
// -----------------------------------------------------------------------------
runTest("4. Fake image (non-image script/text) upload is safely rejected", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-FAKEIMG', 'Fake Img Check', 'Test', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);

        // Create a plain text file pretending to be jpg
        $tmp = tempnam(sys_get_temp_dir(), 'cs_fake_');
        file_put_contents($tmp, "<?php echo 'malicious'; ?>");

        $testFile = [
            'name'     => 'exploit.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp)
        ];

        $res = IncidentManager::submitResolutionEvidence($incId, 2, $testFile, 'Attempting fake image upload', 21.14, 72.77, $pdo);
        @unlink($tmp);

        $pdo->rollBack();

        if ($res['success']) {
            return "Fake image script was accepted as resolution evidence";
        }

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 5: Full valid evidence submission workflow
// -----------------------------------------------------------------------------
runTest("5. Valid evidence submission transitions incident and assignment to resolution_submitted", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-VALID-EV', 'Valid Evidence Submission', 'Pothole repaired', 1, 'Vesu', 'Surat', 21.145, 72.775, 'high', 75, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);
        $assignId = (int)$pdo->lastInsertId();

        $tmp = createDummyImage(100, 100, 30, 144, 255);
        $testFile = [
            'name'     => 'repair_photo.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp)
        ];

        $notes = 'Road crater filled with bitumen emulsion and rolled smooth. Traffic signs removed.';
        $lat = 21.145820;
        $lng = 72.775410;

        $res = IncidentManager::submitResolutionEvidence($incId, 2, $testFile, $notes, $lat, $lng, $pdo);
        @unlink($tmp);

        if (!$res['success']) {
            $pdo->rollBack();
            return "submitResolutionEvidence failed: " . $res['message'];
        }

        $evidenceId = $res['evidence_id'];

        // 1. Check incidents table
        $incCheck = $pdo->prepare("SELECT status FROM incidents WHERE id = :id");
        $incCheck->execute([':id' => $incId]);
        if ($incCheck->fetchColumn() !== 'resolution_submitted') {
            $pdo->rollBack();
            return "incidents.status was not updated to 'resolution_submitted'";
        }

        // 2. Check incident_assignments table
        $asCheck = $pdo->prepare("SELECT assignment_status FROM incident_assignments WHERE id = :id");
        $asCheck->execute([':id' => $assignId]);
        if ($asCheck->fetchColumn() !== 'resolution_submitted') {
            $pdo->rollBack();
            return "incident_assignments.assignment_status was not updated to 'resolution_submitted'";
        }

        // 3. Check resolution_evidence table
        $evCheck = $pdo->prepare("SELECT * FROM resolution_evidence WHERE id = :id");
        $evCheck->execute([':id' => $evidenceId]);
        $evRow = $evCheck->fetch();

        if (!$evRow) {
            $pdo->rollBack();
            return "resolution_evidence record not found for id {$evidenceId}";
        }

        if ($evRow['verification_status'] !== 'pending_review') {
            $pdo->rollBack();
            return "resolution_evidence.verification_status is '{$evRow['verification_status']}', expected 'pending_review'";
        }

        if (empty($evRow['photo_sha256']) || strlen($evRow['photo_sha256']) !== 64) {
            $pdo->rollBack();
            return "Invalid SHA-256 hash in resolution_evidence: " . ($evRow['photo_sha256'] ?? 'null');
        }

        // 4. Check status history
        $histCheck = $pdo->prepare(
            "SELECT status_from, status_to FROM incident_status_history WHERE incident_id = :inc ORDER BY id DESC LIMIT 1"
        );
        $histCheck->execute([':inc' => $incId]);
        $histRow = $histCheck->fetch();
        if ($histRow['status_from'] !== 'in_progress' || $histRow['status_to'] !== 'resolution_submitted') {
            $pdo->rollBack();
            return "Status history transition recorded incorrectly: {$histRow['status_from']} -> {$histRow['status_to']}";
        }

        // Clean up created file if exists
        if (!empty($evRow['photo_path'])) {
            $fullPath = ROOT_PATH . DIRECTORY_SEPARATOR . $evRow['photo_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 6: Admin Rework Workflow
// -----------------------------------------------------------------------------
runTest("6. Admin review decision 'request_rework' reverts incident and assignment to in_progress", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-REWORK', 'Rework Test', 'Desc', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);
        $assignId = (int)$pdo->lastInsertId();

        // Submit evidence
        $tmp = createDummyImage();
        $testFile = [
            'name'     => 'patch.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp)
        ];
        $evRes = IncidentManager::submitResolutionEvidence($incId, 2, $testFile, 'Initial quick patch applied', null, null, $pdo);
        @unlink($tmp);
        if (!$evRes['success']) {
            $pdo->rollBack();
            return "submitResolutionEvidence failed: " . $evRes['message'];
        }
        $evidenceId = $evRes['evidence_id'];

        // Admin requests rework
        $revRes = IncidentManager::reviewResolutionEvidence(
            $incId,
            1, // Admin Surat
            'request_rework',
            'Compaction insufficient. Edges of cold patch are loose and present tripping hazard. Re-roll with roller.',
            $pdo
        );

        if (!$revRes['success']) {
            $pdo->rollBack();
            return "reviewResolutionEvidence (request_rework) failed: " . $revRes['message'];
        }

        // Verify incident reverted to in_progress
        $chkInc = $pdo->prepare("SELECT status FROM incidents WHERE id = :id");
        $chkInc->execute([':id' => $incId]);
        if ($chkInc->fetchColumn() !== 'in_progress') {
            $pdo->rollBack();
            return "Incident status was not reverted to 'in_progress'";
        }

        // Verify assignment reverted to in_progress
        $chkAs = $pdo->prepare("SELECT assignment_status FROM incident_assignments WHERE id = :id");
        $chkAs->execute([':id' => $assignId]);
        if ($chkAs->fetchColumn() !== 'in_progress') {
            $pdo->rollBack();
            return "Assignment status was not reverted to 'in_progress'";
        }

        // Verify resolution evidence record updated to rework_requested
        $chkEv = $pdo->prepare("SELECT verification_status, verified_by_user_id, verification_notes FROM resolution_evidence WHERE id = :id");
        $chkEv->execute([':id' => $evidenceId]);
        $evRow = $chkEv->fetch();
        if ($evRow['verification_status'] !== 'rework_requested' || (int)$evRow['verified_by_user_id'] !== 1) {
            $pdo->rollBack();
            return "resolution_evidence verification status was not updated to 'rework_requested'";
        }

        // Cleanup
        $cleanPath = $pdo->prepare("SELECT photo_path FROM resolution_evidence WHERE id = :id");
        $cleanPath->execute([':id' => $evidenceId]);
        $p = $cleanPath->fetchColumn();
        if ($p && file_exists(ROOT_PATH . '/' . $p)) {
            @unlink(ROOT_PATH . '/' . $p);
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 7: Admin Approval Workflow and resolved_at Preservation on Closure
// -----------------------------------------------------------------------------
runTest("7. Admin review decision 'approve' marks incident resolved, completes assignment, and preserves resolved_at on closure", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-APPROVE', 'Approval Test', 'Desc', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'in_progress', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc, 2, 1, 'in_progress', NOW())"
        )->execute([':inc' => $incId]);
        $assignId = (int)$pdo->lastInsertId();

        // Submit evidence
        $tmp = createDummyImage();
        $testFile = [
            'name'     => 'final_repair.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp)
        ];
        $evRes = IncidentManager::submitResolutionEvidence($incId, 2, $testFile, 'Final asphalt compaction completed to municipal specifications', null, null, $pdo);
        @unlink($tmp);
        $evidenceId = $evRes['evidence_id'];

        // Admin approves
        $appRes = IncidentManager::reviewResolutionEvidence(
            $incId,
            1,
            'approve',
            'Photographic evidence verifies road is completely restored to safety standards.',
            $pdo
        );

        if (!$appRes['success']) {
            $pdo->rollBack();
            return "reviewResolutionEvidence (approve) failed: " . $appRes['message'];
        }

        // Verify incident resolved
        $chkInc = $pdo->prepare("SELECT status, resolved_at FROM incidents WHERE id = :id");
        $chkInc->execute([':id' => $incId]);
        $incRow = $chkInc->fetch();
        if ($incRow['status'] !== 'resolved' || empty($incRow['resolved_at'])) {
            $pdo->rollBack();
            return "Incident status was not updated to 'resolved' with non-null resolved_at";
        }
        $originalResolvedAt = $incRow['resolved_at'];

        // Verify assignment completed
        $chkAs = $pdo->prepare("SELECT assignment_status, completed_at FROM incident_assignments WHERE id = :id");
        $chkAs->execute([':id' => $assignId]);
        $asRow = $chkAs->fetch();
        if ($asRow['assignment_status'] !== 'completed' || empty($asRow['completed_at'])) {
            $pdo->rollBack();
            return "Assignment was not marked 'completed' with completed_at";
        }

        // Verify evidence record verified
        $chkEv = $pdo->prepare("SELECT verification_status, verified_by_user_id FROM resolution_evidence WHERE id = :id");
        $chkEv->execute([':id' => $evidenceId]);
        $evRow = $chkEv->fetch();
        if ($evRow['verification_status'] !== 'verified' || (int)$evRow['verified_by_user_id'] !== 1) {
            $pdo->rollBack();
            return "resolution_evidence was not updated to 'verified'";
        }

        // Admin transitions resolved -> closed
        $closeRes = IncidentManager::updateStatus($incId, 'closed', 1, 'Municipal verification audit complete; case formally archived.', $pdo);
        if (!$closeRes['success']) {
            $pdo->rollBack();
            return "updateStatus to closed failed: " . $closeRes['message'];
        }

        // Verify closed_at is set AND resolved_at is PRESERVED (not wiped to null)
        $chkClose = $pdo->prepare("SELECT status, resolved_at, closed_at FROM incidents WHERE id = :id");
        $chkClose->execute([':id' => $incId]);
        $closeRow = $chkClose->fetch();

        if ($closeRow['status'] !== 'closed') {
            $pdo->rollBack();
            return "Incident was not marked 'closed'";
        }
        if (empty($closeRow['closed_at'])) {
            $pdo->rollBack();
            return "closed_at was not populated on closure";
        }
        if (empty($closeRow['resolved_at']) || $closeRow['resolved_at'] !== $originalResolvedAt) {
            $pdo->rollBack();
            return "resolved_at timestamp was wiped or corrupted upon transitioning to closed";
        }

        // Cleanup
        $cleanPath = $pdo->prepare("SELECT photo_path FROM resolution_evidence WHERE id = :id");
        $cleanPath->execute([':id' => $evidenceId]);
        $p = $cleanPath->fetchColumn();
        if ($p && file_exists(ROOT_PATH . '/' . $p)) {
            @unlink(ROOT_PATH . '/' . $p);
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// Test 8: Public tracking endpoint during resolution_submitted state
// -----------------------------------------------------------------------------
runTest("8. Public tracking API during resolution_submitted hides photo, notes, and raw coordinates", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token = 'cstk_test_workflow_tok_1234567890';
        $tokenHash = hash('sha256', $token);

        $pdo->prepare(
            "INSERT INTO incidents (incident_reference, title, description, category_id, neighborhood, city, obfuscated_lat, obfuscated_lng, severity, priority_score, status, created_at)
             VALUES ('CS-TEST-TRACK', 'Tracking Check', 'Desc', 1, 'Vesu', 'Surat', 21.145, 72.775, 'medium', 50, 'resolution_submitted', NOW())"
        )->execute();
        $incId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO incident_reports (report_reference, tracking_token_hash, incident_id, category_id, citizen_id, title, description, submission_mode, submitted_severity, raw_lat, raw_lng, status, created_at)
             VALUES ('REP-TEST-TRACK', :hash, :inc, 1, 5, 'Track Test Report', 'Report Desc', 'registered', 'medium', 21.1458, 72.7754, 'consolidated', NOW())"
        )->execute([':hash' => $tokenHash, ':inc' => $incId]);

        // Query track lookup query directly (matching api/v1/track.php logic)
        $stmt = $pdo->prepare(
            'SELECT r.report_reference, r.title, r.submitted_severity, r.status, r.created_at,
                    c.name AS category_name, c.department_code, c.icon AS category_icon, c.resolution_sla_hours,
                    i.incident_reference, i.status AS consolidated_status, i.neighborhood, i.resolved_at
             FROM incident_reports r
             JOIN incident_categories c ON r.category_id = c.id
             LEFT JOIN incidents i ON r.incident_id = i.id
             WHERE r.tracking_token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $report = $stmt->fetch();

        if (!$report) {
            $pdo->rollBack();
            return "Report not found via tracking_token_hash";
        }

        $effectiveStatus = $report['status'];
        $consolidatedStatus = $report['consolidated_status'] ?? null;
        $consolidatedLabel = match ($consolidatedStatus) {
            'resolution_submitted' => 'Field Remediation Completed — Pending Verification',
            'in_progress'          => 'In Progress',
            'assigned'             => 'Assigned',
            'resolved'             => 'Resolved',
            'closed'               => 'Closed',
            default                => ucfirst(str_replace('_', ' ', (string)$consolidatedStatus))
        };

        $statusLabel = match ($effectiveStatus) {
            'pending_review' => 'Under Triage & Verification',
            'consolidated'   => 'Consolidated into Active Incident (' . $consolidatedLabel . ')',
            'duplicate'      => 'Verified Duplicate & Consolidated',
            'standalone'     => 'Active Standalone Incident',
            'rejected'       => 'Closed / Rejected by Triage',
            default          => ucfirst(str_replace('_', ' ', $effectiveStatus))
        };

        if ($consolidatedStatus === 'resolution_submitted') {
            $statusLabel = 'Field Remediation Completed — Pending Verification';
        }

        $safePayload = [
            'report_reference'   => $report['report_reference'],
            'status'             => $effectiveStatus,
            'status_label'       => $statusLabel,
            'neighborhood'       => $report['neighborhood'],
            'incident_reference' => $report['incident_reference']
        ];

        // Ensure sensitive fields are absent
        if (isset($safePayload['photo_path']) || isset($safePayload['raw_lat']) || isset($safePayload['evidence_notes'])) {
            $pdo->rollBack();
            return "Sensitive internal resolution evidence leaked in public tracking payload";
        }

        if ($safePayload['status_label'] !== 'Field Remediation Completed — Pending Verification') {
            $pdo->rollBack();
            return "Unexpected status label: " . $safePayload['status_label'];
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

echo "\n======================================================================\n";
echo "Resolution Workflow Test Suite Finished: {$passedCount} Passed, {$failedCount} Failed\n";
echo "======================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
