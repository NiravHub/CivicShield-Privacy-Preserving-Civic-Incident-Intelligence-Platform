<?php
declare(strict_types=1);

/**
 * CivicShield Phase 8 Automated Test Suite
 * Validates Officer Portal, Field Operations, Role Access Control, and Status Lifecycle
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth_guard.php';

echo "======================================================================\n";
echo "CivicShield Phase 8 - Officer Portal & Field Operations Tests\n";
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
function createOfficerTestIncident(PDO $pdo, string $status = 'assigned', string $severity = 'high'): int {
    $ref = 'TEST-OFF-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare(
        'INSERT INTO incidents 
         (incident_reference, category_id, title, description, severity, priority_score, status, 
          obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at)
         VALUES (:ref, 1, :title, :desc, :sev, 75, :status, 21.196000, 72.795000, 150, \'Adajan\', \'Surat\', 1, NOW(), NOW())'
    );
    $stmt->execute([
        ':ref'    => $ref,
        ':title'  => 'Synthetic Officer Test Incident: ' . $ref,
        ':desc'   => 'Automated test suite fixture for officer operations.',
        ':sev'    => $severity,
        ':status' => $status
    ]);
    return (int)$pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// 1. Officer role can access officer operations
// -----------------------------------------------------------------------------
runTest("1. Officer role can access officer-only operations", function() use ($pdo) {
    Session::start();

    // Verify seeded officer accounts
    $officers = ['officer_patel', 'officer_desai', 'officer_shah'];
    foreach ($officers as $username) {
        $stmt = $pdo->prepare("SELECT id, role, status, department FROM users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        if (!$row || $row['role'] !== 'officer' || $row['status'] !== 'active') {
            return "Expected active officer account for {$username}";
        }
    }

    // Verify session role check for officer
    $_SESSION['role'] = 'officer';
    $_SESSION['user_id'] = 2; // officer_patel
    if (!Session::hasRole('officer')) {
        return "Session::hasRole('officer') should return true for officer";
    }

    $homeUrl = Session::getRoleHomeUrl('officer');
    if (!str_contains($homeUrl, '/officer/')) {
        return "Officer role home URL should point to /officer/, got {$homeUrl}";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 2. Citizen role is denied officer access
// -----------------------------------------------------------------------------
runTest("2. Citizen role is denied officer access", function() {
    Session::start();
    $_SESSION['role'] = 'citizen';
    $_SESSION['user_id'] = 5; // vesu_watcher

    if (Session::hasRole('officer')) {
        return "Citizen session must not pass officer role check";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 3. Admin-only pages and duplicate-review actions remain protected
// -----------------------------------------------------------------------------
runTest("3. Admin-only operations remain protected from officer role", function() {
    Session::start();
    $_SESSION['role'] = 'officer';
    $_SESSION['user_id'] = 2;

    if (Session::hasRole('admin')) {
        return "Officer session must not pass admin role check";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 4. Officer sees only their active assignments
// -----------------------------------------------------------------------------
runTest("4. Officer sees only their active assignments", function() use ($pdo) {
    $officerId = 2; // officer_patel
    $res = IncidentManager::getOfficerIncidents($officerId, ['view' => 'active'], 1, 20, $pdo);

    if (!isset($res['incidents'], $res['pagination'])) {
        return "Expected incidents and pagination keys";
    }

    // Verify every incident returned has an active assignment for officer 2
    foreach ($res['incidents'] as $inc) {
        $chk = $pdo->prepare(
            "SELECT officer_id, assignment_status 
             FROM incident_assignments 
             WHERE id = :aid"
        );
        $chk->execute([':aid' => $inc['assignment_id']]);
        $row = $chk->fetch();

        if (!$row || (int)$row['officer_id'] !== $officerId) {
            return "Returned incident contains assignment for officer {$row['officer_id']}, expected {$officerId}";
        }
        if (in_array($row['assignment_status'], ['completed', 'cancelled', 'reassigned'], true)) {
            return "Returned assignment status '{$row['assignment_status']}' is not active";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 5. Reassigned and completed work orders are not counted as active assignments
// -----------------------------------------------------------------------------
runTest("5. Reassigned and completed work orders are not counted as active assignments", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerId = 2;
        $adminId = 1;

        // Create completed assignment
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at, completed_at)
             VALUES (:inc_id, :off_id, :adm_id, 'completed', NOW(), NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);

        // Create reassigned assignment
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'reassigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);

        // Active incidents should NOT include this incident
        $activeList = IncidentManager::getOfficerIncidents($officerId, ['view' => 'active', 'search' => 'TEST-OFF-'], 1, 20, $pdo);
        foreach ($activeList['incidents'] as $inc) {
            if ($inc['id'] === $incId) {
                $pdo->rollBack();
                return "Completed/reassigned work order was incorrectly returned in active view";
            }
        }

        // History view SHOULD include these assignments
        $historyList = IncidentManager::getOfficerIncidents($officerId, ['view' => 'history', 'search' => 'TEST-OFF-'], 1, 20, $pdo);
        $foundInHistory = false;
        foreach ($historyList['incidents'] as $inc) {
            if ($inc['id'] === $incId) {
                $foundInHistory = true;
                break;
            }
        }

        if (!$foundInHistory) {
            $pdo->rollBack();
            return "Expected completed/reassigned assignment in history view";
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
// 6. Officer cannot access another officer's incident by ID
// -----------------------------------------------------------------------------
runTest("6. Officer cannot access unassigned or another officer's incident by changing ID", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerShah = 4; // Assign to Officer Shah
        $adminId = 1;

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerShah, ':adm_id' => $adminId]);

        // Attempt access as Officer Patel (ID 2)
        $detailPatel = IncidentManager::getOfficerIncidentDetail($incId, 2, $pdo);
        if ($detailPatel !== null) {
            $pdo->rollBack();
            return "Officer Patel should receive null when accessing Officer Shah's incident";
        }

        $isAssignedPatel = IncidentManager::isIncidentAssignedToOfficer($incId, 2, false, $pdo);
        if ($isAssignedPatel) {
            $pdo->rollBack();
            return "isIncidentAssignedToOfficer should return false for Officer Patel";
        }

        // Officer Shah (ID 4) SHOULD be allowed access
        $detailShah = IncidentManager::getOfficerIncidentDetail($incId, 4, $pdo);
        if ($detailShah === null) {
            $pdo->rollBack();
            return "Officer Shah should have access to his own assigned incident";
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
// 7. Valid officer status transitions succeed
// -----------------------------------------------------------------------------
runTest("7. Valid officer status transitions succeed", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerId = 2; // Officer Patel
        $adminId = 1;

        // Create active assignment
        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);
        $assignId = (int)$pdo->lastInsertId();

        // 1. Transition 'assigned' -> 'in_progress'
        $res1 = IncidentManager::updateOfficerStatus($incId, 'in_progress', $officerId, 'On-site inspection started with road paver crew', $pdo);
        if (!$res1['success']) {
            $pdo->rollBack();
            return "assigned -> in_progress failed: " . $res1['message'];
        }

        // Check incident and assignment status
        $chkInc1 = $pdo->prepare("SELECT status FROM incidents WHERE id = :id");
        $chkInc1->execute([':id' => $incId]);
        if ($chkInc1->fetchColumn() !== 'in_progress') {
            $pdo->rollBack();
            return "Incident status was not updated to in_progress";
        }

        $chkAssign1 = $pdo->prepare("SELECT assignment_status, acknowledged_at FROM incident_assignments WHERE id = :id");
        $chkAssign1->execute([':id' => $assignId]);
        $assignRow1 = $chkAssign1->fetch();
        if ($assignRow1['assignment_status'] !== 'in_progress' || empty($assignRow1['acknowledged_at'])) {
            $pdo->rollBack();
            return "Assignment was not updated to in_progress with acknowledged_at";
        }

        // 2. Transition 'in_progress' -> 'resolved'
        $res2 = IncidentManager::updateOfficerStatus($incId, 'resolved', $officerId, 'Crater patched with cold-mix asphalt; surface smoothed and road reopened', $pdo);
        if (!$res2['success']) {
            $pdo->rollBack();
            return "in_progress -> resolved failed: " . $res2['message'];
        }

        $chkInc2 = $pdo->prepare("SELECT status, resolved_at FROM incidents WHERE id = :id");
        $chkInc2->execute([':id' => $incId]);
        $incRow2 = $chkInc2->fetch();
        if ($incRow2['status'] !== 'resolved' || empty($incRow2['resolved_at'])) {
            $pdo->rollBack();
            return "Incident status was not updated to resolved with resolved_at";
        }

        $chkAssign2 = $pdo->prepare("SELECT assignment_status, completed_at FROM incident_assignments WHERE id = :id");
        $chkAssign2->execute([':id' => $assignId]);
        $assignRow2 = $chkAssign2->fetch();
        if ($assignRow2['assignment_status'] !== 'completed' || empty($assignRow2['completed_at'])) {
            $pdo->rollBack();
            return "Assignment was not marked completed with completed_at";
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
// 8. Invalid or unauthorized transitions are rejected
// -----------------------------------------------------------------------------
runTest("8. Disallowed or unauthorized officer transitions are rejected", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerId = 2;
        $adminId = 1;

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);

        // Attempt disallowed direct jump from 'assigned' to 'resolved' (skipping in_progress)
        $res1 = IncidentManager::updateOfficerStatus($incId, 'resolved', $officerId, 'Attempting direct resolve', $pdo);
        if ($res1['success']) {
            $pdo->rollBack();
            return "Direct jump assigned -> resolved should be rejected for officer";
        }

        // Attempt disallowed transition to 'closed' (admin-only)
        $res2 = IncidentManager::updateOfficerStatus($incId, 'closed', $officerId, 'Attempting close by officer', $pdo);
        if ($res2['success']) {
            $pdo->rollBack();
            return "Transition to closed should be rejected for officer";
        }

        // Attempt disallowed transition to 'rejected' (admin-only)
        $res3 = IncidentManager::updateOfficerStatus($incId, 'rejected', $officerId, 'Attempting reject by officer', $pdo);
        if ($res3['success']) {
            $pdo->rollBack();
            return "Transition to rejected should be rejected for officer";
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
// 9. Missing CSRF tokens and invalid CSRF tokens are rejected
// -----------------------------------------------------------------------------
runTest("9. Missing and invalid CSRF tokens are rejected", function() {
    Session::start();
    $validToken = Session::getCsrfToken();

    if (!Session::validateCsrf($validToken)) {
        return "Valid CSRF token should be accepted";
    }

    if (Session::validateCsrf('fake_token_12345')) {
        return "Invalid CSRF token should be rejected";
    }

    if (Session::validateCsrf('')) {
        return "Empty CSRF token should be rejected";
    }

    if (Session::validateCsrf(null)) {
        return "Null CSRF token should be rejected";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 10. Required notes are validated
// -----------------------------------------------------------------------------
runTest("10. Required operational notes are validated (>= 5 chars)", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerId = 2;
        $adminId = 1;

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);

        // Empty note
        $resEmpty = IncidentManager::updateOfficerStatus($incId, 'in_progress', $officerId, '', $pdo);
        if ($resEmpty['success']) {
            $pdo->rollBack();
            return "Status update with empty note should fail";
        }

        // Note too short (< 5 chars)
        $resShort = IncidentManager::updateOfficerStatus($incId, 'in_progress', $officerId, 'Go', $pdo);
        if ($resShort['success']) {
            $pdo->rollBack();
            return "Status update with note < 5 chars should fail";
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
// 11. Status history and audit events are recorded correctly
// -----------------------------------------------------------------------------
runTest("11. Status history and audit events record officer actions properly", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createOfficerTestIncident($pdo, 'assigned');
        $officerId = 2; // Officer Patel
        $adminId = 1;

        $pdo->prepare(
            "INSERT INTO incident_assignments (incident_id, officer_id, assigned_by_user_id, assignment_status, assigned_at)
             VALUES (:inc_id, :off_id, :adm_id, 'assigned', NOW())"
        )->execute([':inc_id' => $incId, ':off_id' => $officerId, ':adm_id' => $adminId]);

        $note = 'Deploying 3-man asphalt repair team on site';
        $res = IncidentManager::updateOfficerStatus($incId, 'in_progress', $officerId, $note, $pdo);
        if (!$res['success']) {
            $pdo->rollBack();
            return "Status update failed: " . $res['message'];
        }

        // Verify status history
        $histStmt = $pdo->prepare(
            "SELECT * FROM incident_status_history WHERE incident_id = :id ORDER BY id DESC LIMIT 1"
        );
        $histStmt->execute([':id' => $incId]);
        $hist = $histStmt->fetch();

        if (!$hist || (int)$hist['changed_by_user_id'] !== $officerId) {
            $pdo->rollBack();
            return "Status history entry missing or changed_by_user_id mismatch";
        }
        if ($hist['status_from'] !== 'assigned' || $hist['status_to'] !== 'in_progress') {
            $pdo->rollBack();
            return "Status history from/to mismatch: {$hist['status_from']} -> {$hist['status_to']}";
        }
        if ($hist['reason'] !== $note) {
            $pdo->rollBack();
            return "Status history reason mismatch: {$hist['reason']}";
        }

        // Verify audit log
        $auditStmt = $pdo->prepare(
            "SELECT * FROM audit_logs WHERE actor_user_id = :uid AND action = 'OFFICER_STATUS_UPDATE' ORDER BY id DESC LIMIT 1"
        );
        $auditStmt->execute([':uid' => $officerId]);
        $audit = $auditStmt->fetch();

        if (!$audit || $audit['actor_role'] !== 'officer') {
            $pdo->rollBack();
            return "Audit log missing or actor_role is not 'officer'";
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
// 12. SQL injection and XSS inputs are handled safely
// -----------------------------------------------------------------------------
runTest("12. SQL injection and XSS inputs in officer queries are handled safely", function() use ($pdo) {
    $maliciousInputs = [
        "'; DROP TABLE users; --",
        "1' OR '1'='1",
        "<script>alert('XSS')</script>",
        "\" OR 1=1 --",
        "admin'--"
    ];

    foreach ($maliciousInputs as $badInput) {
        $res = IncidentManager::getOfficerIncidents(2, ['search' => $badInput], 1, 10, $pdo);
        if (!is_array($res) || !isset($res['incidents'])) {
            return "Failed to handle malicious search input safely: {$badInput}";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 13. Private contact information and raw tracking tokens are not exposed
// -----------------------------------------------------------------------------
runTest("13. Private contact info and raw tracking tokens are never exposed in officer views", function() use ($pdo) {
    // Incident 1 is assigned to Officer Patel (ID 2)
    $detail = IncidentManager::getOfficerIncidentDetail(1, 2, $pdo);
    if (!$detail) {
        return "Incident 1 detail should be accessible to assigned officer 2";
    }

    $forbiddenKeys = ['contact_name', 'contact_phone', 'contact_email', 'raw_token', 'phone_number'];
    foreach ($forbiddenKeys as $fk) {
        if (array_key_exists($fk, $detail)) {
            return "Officer incident detail exposed forbidden key: {$fk}";
        }
    }

    // Check linked reports for Incident 1
    $reports = IncidentManager::getLinkedReports(1, $pdo);
    foreach ($reports as $rep) {
        foreach (['contact_phone', 'contact_email', 'raw_token'] as $fk) {
            if (array_key_exists($fk, $rep)) {
                return "Linked report exposed forbidden key: {$fk}";
            }
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 14. Existing Phase 6 and Phase 7 functionality continues to work
// -----------------------------------------------------------------------------
runTest("14. Admin incident operations and duplicate detection continue to work normally", function() use ($pdo) {
    // Phase 7 admin incident query
    $adminRes = IncidentManager::getIncidents([], 1, 5, $pdo);
    if (!isset($adminRes['incidents'], $adminRes['pagination'])) {
        return "IncidentManager::getIncidents failed";
    }

    // Phase 6 duplicate detection query
    $cand = DuplicateDetector::findCandidatesForReport(1, 50.0, $pdo);
    if (!is_array($cand)) {
        return "DuplicateDetector::findCandidatesForReport failed";
    }

    return true;
});

echo "\n======================================================================\n";
echo "Phase 8 Test Suite Finished: $passCount Passed, $failCount Failed\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
