<?php
declare(strict_types=1);

/**
 * CivicShield Phase 7 Automated Test Suite
 * Validates Admin Incident Operations, Departmental Dispatch, and Resolution Workflow
 */

require_once dirname(__DIR__) . '/config/app.php';

echo "======================================================================\n";
echo "CivicShield Phase 7 - Admin Incident Operations & Resolution Tests\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function runTest(string $name, callable $testFn): void {
    global $passCount, $failCount;
    echo "[TEST] $name: ";
    try {
        $result = $testFn();
        if ($result === true || $result === null) {
            echo "PASSED\n";
            $passCount++;
        } else {
            echo "FAILED ($result)\n";
            $failCount++;
        }
    } catch (Throwable $e) {
        echo "FAILED with Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failCount++;
    }
}

$pdo = Database::getConnection();

// -----------------------------------------------------------------------------
// Helper: Create a disposable synthetic incident for testing
// -----------------------------------------------------------------------------
function createSyntheticIncident(PDO $pdo, string $status = 'reported', string $severity = 'medium'): int {
    $ref = 'TEST-INC-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare(
        'INSERT INTO incidents 
         (incident_reference, category_id, title, description, severity, priority_score, status, 
          obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at)
         VALUES (:ref, 1, :title, :desc, :sev, 50, :status, 21.196000, 72.795000, 150, \'Adajan\', \'Surat\', 1, NOW(), NOW())'
    );
    $stmt->execute([
        ':ref'    => $ref,
        ':title'  => 'Synthetic Test Incident for Phase 7: ' . $ref,
        ':desc'   => 'Automated test suite fixture for incident management operations.',
        ':sev'    => $severity,
        ':status' => $status
    ]);
    return (int)$pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// 1. RBAC: Role validation for admin incident operations
// -----------------------------------------------------------------------------
runTest("1. RBAC: Role validation protects administrative operations", function() use ($pdo) {
    Session::start();

    // Check seeded admin user exists and has role 'admin'
    $admin = $pdo->query("SELECT id, role, status FROM users WHERE username = 'admin_surat'")->fetch();
    if (!$admin || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
        return "admin_surat must exist, be active, and have role 'admin'";
    }

    // Check citizen user exists and has role 'citizen'
    $citizen = $pdo->query("SELECT id, role FROM users WHERE username = 'vesu_watcher'")->fetch();
    if (!$citizen || $citizen['role'] !== 'citizen') {
        return "vesu_watcher must exist and have role 'citizen'";
    }

    // Verify Session role check logic
    $_SESSION['role'] = 'citizen';
    if (Session::hasRole('admin')) {
        return "Citizen session must not pass admin role check";
    }

    $_SESSION['role'] = 'officer';
    if (Session::hasRole('admin')) {
        return "Officer session must not pass admin role check";
    }

    $_SESSION['role'] = 'admin';
    if (!Session::hasRole('admin')) {
        return "Admin session must pass admin role check";
    }

    unset($_SESSION['role']);
    if (Session::hasRole('admin')) {
        return "Unauthenticated session must not pass admin role check";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 2. Incident Filtering, Clamping, and Pagination
// -----------------------------------------------------------------------------
runTest("2. Incident filtering, search, and pagination clamp safely", function() use ($pdo) {
    // Test pagination clamping
    $res1 = IncidentManager::getIncidents([], -5, 1000, $pdo);
    if (!isset($res1['pagination'], $res1['incidents'])) {
        return "Expected incidents and pagination keys";
    }
    if ($res1['pagination']['current_page'] !== 1) {
        return "Page -5 should be clamped to 1, got {$res1['pagination']['current_page']}";
    }
    if ($res1['pagination']['per_page'] !== 50) {
        return "PerPage 1000 should be clamped to 50, got {$res1['pagination']['per_page']}";
    }

    // Test filter by category
    $resCat = IncidentManager::getIncidents(['category_id' => 1], 1, 10, $pdo);
    foreach ($resCat['incidents'] as $inc) {
        if ((int)$inc['category_id'] !== 1) {
            return "Expected category_id 1, got {$inc['category_id']}";
        }
    }

    // Test search filter
    $resSearch = IncidentManager::getIncidents(['search' => 'Road'], 1, 10, $pdo);
    if ($resSearch['pagination']['total_records'] < 1) {
        return "Expected at least 1 result for search query 'Road'";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 3. Officer retrieval only returns active officers
// -----------------------------------------------------------------------------
runTest("3. IncidentManager::getActiveOfficers() returns only active field officers", function() use ($pdo) {
    $officers = IncidentManager::getActiveOfficers($pdo);
    if (empty($officers)) {
        return "Expected at least one active officer in seed data";
    }

    foreach ($officers as $off) {
        $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = :id");
        $stmt->execute([':id' => $off['id']]);
        $row = $stmt->fetch();
        if ($row['role'] !== 'officer' || $row['status'] !== 'active') {
            return "User {$off['id']} returned by getActiveOfficers() has role {$row['role']} and status {$row['status']}";
        }
    }
    return true;
});

// -----------------------------------------------------------------------------
// 4. Valid Incident Assignment to Field Officer
// -----------------------------------------------------------------------------
runTest("4. Assigning incident to active officer succeeds and updates status", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'reported');
        $adminId = 1; // admin_surat
        $officerId = 2; // officer_patel

        $res = IncidentManager::assignIncident($incId, $officerId, $adminId, 'Deploy field inspection team urgently', $pdo);
        if (!$res['success']) {
            $pdo->rollBack();
            return "assignIncident failed: " . $res['message'];
        }

        // Verify assignment record
        $stmt = $pdo->prepare("SELECT * FROM incident_assignments WHERE incident_id = :id AND officer_id = :off_id");
        $stmt->execute([':id' => $incId, ':off_id' => $officerId]);
        $assign = $stmt->fetch();
        if (!$assign) {
            $pdo->rollBack();
            return "No incident_assignments record found";
        }
        if ($assign['assignment_status'] !== 'assigned') {
            $pdo->rollBack();
            return "Expected assignment_status 'assigned', got {$assign['assignment_status']}";
        }
        if ($assign['instructions'] !== 'Deploy field inspection team urgently') {
            $pdo->rollBack();
            return "Instructions mismatch: " . $assign['instructions'];
        }

        // Verify incident transitioned to 'assigned'
        $incStmt = $pdo->prepare("SELECT status FROM incidents WHERE id = :id");
        $incStmt->execute([':id' => $incId]);
        $inc = $incStmt->fetch();
        if ($inc['status'] !== 'assigned') {
            $pdo->rollBack();
            return "Expected incident status 'assigned', got {$inc['status']}";
        }

        // Verify status history entry
        $histStmt = $pdo->prepare("SELECT * FROM incident_status_history WHERE incident_id = :id ORDER BY id DESC LIMIT 1");
        $histStmt->execute([':id' => $incId]);
        $hist = $histStmt->fetch();
        if (!$hist || $hist['status_to'] !== 'assigned' || $hist['status_from'] !== 'reported') {
            $pdo->rollBack();
            return "Status history entry missing or incorrect";
        }

        // Verify audit log entry
        $auditStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'INCIDENT_ASSIGNED' ORDER BY id DESC LIMIT 1");
        $auditStmt->execute();
        $audit = $auditStmt->fetch();
        if (!$audit) {
            $pdo->rollBack();
            return "Audit log for INCIDENT_ASSIGNED missing";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 5. Ineligible Officer Assignment Rejection
// -----------------------------------------------------------------------------
runTest("5. Assignment to citizen or invalid user is rejected", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'reported');
        $adminId = 1;

        // Attempt assignment to citizen (ID 5: vesu_watcher)
        $resCitizen = IncidentManager::assignIncident($incId, 5, $adminId, 'Invalid assignment to citizen', $pdo);
        if ($resCitizen['success']) {
            $pdo->rollBack();
            return "Assigning incident to citizen should fail";
        }
        if (!str_contains($resCitizen['message'], 'not a field officer')) {
            $pdo->rollBack();
            return "Unexpected error message for citizen assignment: " . $resCitizen['message'];
        }

        // Attempt assignment to nonexistent user
        $resNone = IncidentManager::assignIncident($incId, 999999, $adminId, 'Nonexistent officer', $pdo);
        if ($resNone['success']) {
            $pdo->rollBack();
            return "Assigning incident to nonexistent user should fail";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 6. Reassignment Workflow
// -----------------------------------------------------------------------------
runTest("6. Reassignment updates previous work order to 'reassigned' and creates new assignment", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'triaged');
        $adminId = 1;
        $officer1 = 2; // officer_patel
        $officer2 = 3; // officer_desai

        // Initial assignment
        $res1 = IncidentManager::assignIncident($incId, $officer1, $adminId, 'First work order', $pdo);
        if (!$res1['success']) {
            $pdo->rollBack();
            return "Initial assignment failed: " . $res1['message'];
        }

        // Reassign to officer 2
        $res2 = IncidentManager::assignIncident($incId, $officer2, $adminId, 'Reassigned to drainage specialist', $pdo);
        if (!$res2['success']) {
            $pdo->rollBack();
            return "Reassignment failed: " . $res2['message'];
        }

        // Check previous assignment status
        $stmt1 = $pdo->prepare("SELECT assignment_status FROM incident_assignments WHERE incident_id = :id AND officer_id = :off_id");
        $stmt1->execute([':id' => $incId, ':off_id' => $officer1]);
        $assign1 = $stmt1->fetch();
        if ($assign1['assignment_status'] !== 'reassigned') {
            $pdo->rollBack();
            return "Expected previous assignment status 'reassigned', got {$assign1['assignment_status']}";
        }

        // Check new assignment status
        $stmt2 = $pdo->prepare("SELECT assignment_status, instructions FROM incident_assignments WHERE incident_id = :id AND officer_id = :off_id");
        $stmt2->execute([':id' => $incId, ':off_id' => $officer2]);
        $assign2 = $stmt2->fetch();
        if ($assign2['assignment_status'] !== 'assigned') {
            $pdo->rollBack();
            return "Expected new assignment status 'assigned', got {$assign2['assignment_status']}";
        }
        if ($assign2['instructions'] !== 'Reassigned to drainage specialist') {
            $pdo->rollBack();
            return "Expected updated instructions on new assignment";
        }

        // Check audit log for INCIDENT_REASSIGNED
        $auditStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'INCIDENT_REASSIGNED' ORDER BY id DESC LIMIT 1");
        $auditStmt->execute();
        $audit = $auditStmt->fetch();
        if (!$audit) {
            $pdo->rollBack();
            return "Audit log entry for INCIDENT_REASSIGNED not found";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 7. Valid Status Lifecycle Transitions
// -----------------------------------------------------------------------------
runTest("7. Valid status lifecycle state-machine transitions succeed", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'reported');
        $adminId = 1;

        // 1. reported -> triaged
        $r1 = IncidentManager::updateStatus($incId, 'triaged', $adminId, 'Triage team reviewed severity', $pdo);
        if (!$r1['success']) {
            $pdo->rollBack();
            return "reported -> triaged failed: " . $r1['message'];
        }

        // 2. triaged -> assigned
        $r2 = IncidentManager::updateStatus($incId, 'assigned', $adminId, 'Assigned to ward unit', $pdo);
        if (!$r2['success']) {
            $pdo->rollBack();
            return "triaged -> assigned failed: " . $r2['message'];
        }

        // 3. assigned -> in_progress
        $r3 = IncidentManager::updateStatus($incId, 'in_progress', $adminId, 'Field crew deployed on site', $pdo);
        if (!$r3['success']) {
            $pdo->rollBack();
            return "assigned -> in_progress failed: " . $r3['message'];
        }

        // 4. in_progress -> resolved (with resolution note)
        $r4 = IncidentManager::updateStatus($incId, 'resolved', $adminId, 'Road surface repaved and bitumen sealed', $pdo);
        if (!$r4['success']) {
            $pdo->rollBack();
            return "in_progress -> resolved failed: " . $r4['message'];
        }

        // Check resolved_at timestamp
        $chk1 = $pdo->prepare("SELECT status, resolved_at FROM incidents WHERE id = :id");
        $chk1->execute([':id' => $incId]);
        $row1 = $chk1->fetch();
        if ($row1['status'] !== 'resolved' || empty($row1['resolved_at'])) {
            $pdo->rollBack();
            return "Incident resolved_at not set after transition to resolved";
        }

        // 5. resolved -> closed (with closure note)
        $r5 = IncidentManager::updateStatus($incId, 'closed', $adminId, 'Citizen verified repair and closed', $pdo);
        if (!$r5['success']) {
            $pdo->rollBack();
            return "resolved -> closed failed: " . $r5['message'];
        }

        // Check closed_at timestamp
        $chk2 = $pdo->prepare("SELECT status, closed_at FROM incidents WHERE id = :id");
        $chk2->execute([':id' => $incId]);
        $row2 = $chk2->fetch();
        if ($row2['status'] !== 'closed' || empty($row2['closed_at'])) {
            $pdo->rollBack();
            return "Incident closed_at not set after transition to closed";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 8. Invalid Status Lifecycle Transitions Rejected
// -----------------------------------------------------------------------------
runTest("8. Disallowed lifecycle state transitions are rejected", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'reported');
        $adminId = 1;

        // Disallowed: reported -> closed (cannot skip triage/resolution)
        $resInvalid1 = IncidentManager::updateStatus($incId, 'closed', $adminId, 'Attempting illegal jump to closed', $pdo);
        if ($resInvalid1['success']) {
            $pdo->rollBack();
            return "Illegal transition reported -> closed should be rejected";
        }
        if (!str_contains($resInvalid1['message'], 'Invalid transition')) {
            $pdo->rollBack();
            return "Unexpected rejection message: " . $resInvalid1['message'];
        }

        // Disallowed: reported -> in_progress (cannot skip triage or assignment)
        $resInvalid2 = IncidentManager::updateStatus($incId, 'in_progress', $adminId, 'Attempting jump to in_progress', $pdo);
        if ($resInvalid2['success']) {
            $pdo->rollBack();
            return "Illegal transition reported -> in_progress should be rejected";
        }

        // Same status transition rejected
        $resSame = IncidentManager::updateStatus($incId, 'reported', $adminId, 'Same status update', $pdo);
        if ($resSame['success']) {
            $pdo->rollBack();
            return "Transition to same status should be rejected";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 9. Mandatory Resolution / Closure Justifications
// -----------------------------------------------------------------------------
runTest("9. Mandatory resolution and closure notes are strictly enforced", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'in_progress');
        $adminId = 1;

        // Attempt resolved with empty reason
        $resEmpty = IncidentManager::updateStatus($incId, 'resolved', $adminId, '', $pdo);
        if ($resEmpty['success']) {
            $pdo->rollBack();
            return "Transition to resolved with empty note should fail";
        }
        if (!str_contains($resEmpty['message'], 'at least 5 characters')) {
            $pdo->rollBack();
            return "Expected >= 5 characters validation message";
        }

        // Attempt resolved with too short reason (3 chars)
        $resShort = IncidentManager::updateStatus($incId, 'resolved', $adminId, 'Done', $pdo);
        if ($resShort['success']) {
            $pdo->rollBack();
            return "Transition to resolved with note < 5 characters should fail";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 10. Automatic Assignment Completion on Resolution
// -----------------------------------------------------------------------------
runTest("10. Open officer assignments auto-complete when incident is resolved or closed", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'in_progress');
        $adminId = 1;
        $officerId = 2;

        // Manually create active assignment
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);
        $assignId = (int)$pdo->lastInsertId();

        // Resolve incident
        $res = IncidentManager::updateStatus($incId, 'resolved', $adminId, 'Repaired by engineering crew', $pdo);
        if (!$res['success']) {
            $pdo->rollBack();
            return "Status update to resolved failed: " . $res['message'];
        }

        // Check assignment was marked completed
        $chk = $pdo->prepare("SELECT assignment_status, completed_at FROM incident_assignments WHERE id = :id");
        $chk->execute([':id' => $assignId]);
        $row = $chk->fetch();
        if ($row['assignment_status'] !== 'completed' || empty($row['completed_at'])) {
            $pdo->rollBack();
            return "Assignment was not marked completed with completed_at timestamp";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 11. Priority score recalculation after status update
// -----------------------------------------------------------------------------
runTest("11. Priority score is updated upon status transition", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createSyntheticIncident($pdo, 'in_progress', 'critical');
        $adminId = 1;

        // Transition to resolved
        $res = IncidentManager::updateStatus($incId, 'resolved', $adminId, 'Resolved drainage blockage', $pdo);
        if (!$res['success']) {
            $pdo->rollBack();
            return "Transition to resolved failed";
        }

        // Status factor for resolved is 0.40, so priority_score should reflect resolved status
        $stmt = $pdo->prepare("SELECT priority_score FROM incidents WHERE id = :id");
        $stmt->execute([':id' => $incId]);
        $score = (int)$stmt->fetchColumn();

        // With status = 'resolved' (0.40 multiplier), score is scaled down significantly from initial 50+
        if ($score <= 0 || $score > 60) {
            $pdo->rollBack();
            return "Expected priority score to be discounted for resolved status, got {$score}";
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 12. Privacy Enclave: Citizen PII and tracking tokens are never leaked
// -----------------------------------------------------------------------------
runTest("12. Incident detail queries never expose citizen contacts enclave or raw tracking tokens", function() use ($pdo) {
    $incident = IncidentManager::getIncidentDetails(1, $pdo);
    if (!$incident) {
        return "Seed incident ID 1 not found";
    }

    // Inspect incident row
    $forbiddenKeys = ['phone', 'email', 'phone_number', 'citizen_email', 'raw_token', 'tracking_token'];
    foreach ($forbiddenKeys as $fk) {
        if (array_key_exists($fk, $incident)) {
            return "Incident details exposed forbidden key: {$fk}";
        }
    }

    // Inspect linked reports
    $reports = IncidentManager::getLinkedReports(1, $pdo);
    foreach ($reports as $rep) {
        foreach (['phone', 'email', 'phone_number', 'raw_token'] as $fk) {
            if (array_key_exists($fk, $rep)) {
                return "Linked report exposed forbidden key: {$fk}";
            }
        }
        if (isset($rep['tracking_token_hash']) && strlen($rep['tracking_token_hash']) !== 64) {
            return "tracking_token_hash must be a 64-char SHA-256 hash, got invalid length";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 13. SQL Injection and Special Characters Sanitization
// -----------------------------------------------------------------------------
runTest("13. Dangerous SQL and XSS strings in filter inputs are safely handled", function() use ($pdo) {
    $nastyInputs = [
        "'; DROP TABLE incidents; --",
        "1' OR '1'='1",
        "<script>alert('XSS')</script>",
        "\" OR \"\"=\"",
        "0 OR 1=1 UNION SELECT * FROM users"
    ];

    foreach ($nastyInputs as $input) {
        $res = IncidentManager::getIncidents(['search' => $input], 1, 10, $pdo);
        if (!is_array($res) || !isset($res['incidents'])) {
            return "Failed to handle nasty search input safely: {$input}";
        }
    }
    return true;
});

// -----------------------------------------------------------------------------
// 14. CSRF Protection Check
// -----------------------------------------------------------------------------
runTest("14. CSRF validation accepts valid token and rejects invalid/missing token", function() {
    Session::start();
    $token = Session::getCsrfToken();

    if (!Session::validateCsrf($token)) {
        return "Session::validateCsrf failed to validate valid session CSRF token";
    }

    if (Session::validateCsrf('invalid_token_12345')) {
        return "Session::validateCsrf falsely accepted an invalid token";
    }

    if (Session::validateCsrf('')) {
        return "Session::validateCsrf falsely accepted an empty token";
    }

    return true;
});

echo "\n======================================================================\n";
echo "Phase 7 Test Suite Finished: $passCount Passed, $failCount Failed\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
