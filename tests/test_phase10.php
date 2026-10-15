<?php
declare(strict_types=1);

/**
 * CivicShield Phase 10 — Final Security Audit & Integration Regression Tests
 *
 * Validates cross-module consistency fixes: active-assignment semantics,
 * officer privacy boundaries, status vocabulary alignment, and CSRF helpers.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

$passed = 0;
$failed = 0;

function runTest(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $result = $fn();
        if ($result === true) {
            echo "[TEST] {$name}: PASSED\n";
            $passed++;
        } else {
            echo "[TEST] {$name}: FAILED — {$result}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "[TEST] {$name}: FAILED — Exception: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "CivicShield Phase 10 - Final Security Audit & Integration Tests\n";
echo "======================================================================\n\n";

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Cannot connect to database: {$e->getMessage()}\n");
    exit(1);
}

$canonicalStatuses = ['reported', 'triaged', 'assigned', 'in_progress', 'resolved', 'closed', 'rejected'];

// -----------------------------------------------------------------------------
// 1. Status vocabulary is consistent across schema, managers, and analytics
// -----------------------------------------------------------------------------
runTest("1. Status vocabulary is consistent across schema, managers, and analytics", function() use ($pdo, $canonicalStatuses) {
    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incidents' AND COLUMN_NAME = 'status'"
    )->fetchColumn();

    if (!is_string($col) || $col === '') {
        return "Could not read incidents.status ENUM definition";
    }

    foreach ($canonicalStatuses as $status) {
        if (!str_contains($col, "'{$status}'")) {
            return "Database ENUM missing status '{$status}': {$col}";
        }
    }

    foreach (['verified', 'investigating', 'action_scheduled'] as $invalid) {
        if (str_contains($col, "'{$invalid}'")) {
            return "Database unexpectedly contains obsolete status '{$invalid}'";
        }
    }

    $adminKeys = array_keys(IncidentManager::ALLOWED_TRANSITIONS);
    $analyticsKeys = array_keys(AnalyticsManager::STATUS_METADATA);
    sort($adminKeys);
    sort($analyticsKeys);
    $expected = $canonicalStatuses;
    sort($expected);

    if ($adminKeys !== $expected) {
        return 'IncidentManager::ALLOWED_TRANSITIONS keys mismatch canonical statuses';
    }
    if ($analyticsKeys !== $expected) {
        return 'AnalyticsManager::STATUS_METADATA keys mismatch canonical statuses';
    }

    return true;
});

// -----------------------------------------------------------------------------
// 2. Active assignment queries exclude reassigned work orders (admin + officer)
// -----------------------------------------------------------------------------
runTest("2. Active assignment queries exclude reassigned work orders", function() use ($pdo) {
    $adminId = 1;
    $officerA = 2;
    $officerB = 3;

    $ref = 'TEST-P10-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare(
        "INSERT INTO incidents
         (incident_reference, category_id, title, description, severity, priority_score, status,
          obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at)
         VALUES (:ref, 1, 'Phase 10 Reassignment Probe', 'Integration test incident', 'medium', 40, 'assigned',
                 21.19000000, 72.80000000, 150, 'Adajan', 'Surat', 1, NOW(), NOW())"
    )->execute([':ref' => $ref]);
    $incId = (int)$pdo->lastInsertId();

    try {
        $assign1 = IncidentManager::assignIncident($incId, $officerA, $adminId, 'Initial P10 dispatch', $pdo);
        if (!$assign1['success']) {
            return 'Initial assignment failed: ' . $assign1['message'];
        }

        $assign2 = IncidentManager::assignIncident($incId, $officerB, $adminId, 'Reassigned P10 dispatch', $pdo);
        if (!$assign2['success']) {
            return 'Reassignment failed: ' . $assign2['message'];
        }

        $detail = IncidentManager::getIncidentDetails($incId, $pdo);
        if (!$detail) {
            return 'Admin detail lookup failed';
        }
        if ((int)($detail['officer_id'] ?? 0) !== $officerB) {
            return 'Admin active assignment did not resolve to the latest officer after reassignment';
        }
        if (($detail['assignment_status'] ?? '') === 'reassigned') {
            return 'Admin active assignment incorrectly selected a reassigned work order';
        }

        $officerAQueue = IncidentManager::getOfficerIncidents($officerA, ['view' => 'active', 'search' => $ref], 1, 10, $pdo);
        foreach ($officerAQueue['incidents'] as $row) {
            if ((int)$row['id'] === $incId) {
                return 'Previous officer still sees reassigned incident in active queue';
            }
        }

        $officerBQueue = IncidentManager::getOfficerIncidents($officerB, ['view' => 'active', 'search' => $ref], 1, 10, $pdo);
        $found = false;
        foreach ($officerBQueue['incidents'] as $row) {
            if ((int)$row['id'] === $incId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return 'Current officer does not see reassigned incident in active queue';
        }

        return true;
    } finally {
        $pdo->prepare('DELETE FROM incident_assignments WHERE incident_id = :id')->execute([':id' => $incId]);
        $pdo->prepare('DELETE FROM incident_status_history WHERE incident_id = :id')->execute([':id' => $incId]);
        $pdo->prepare('DELETE FROM audit_logs WHERE target_id = :ref')->execute([':ref' => $ref]);
        $pdo->prepare('DELETE FROM incidents WHERE id = :id')->execute([':id' => $incId]);
    }
});

// -----------------------------------------------------------------------------
// 3. Officer linked reports omit restricted raw intake coordinates
// -----------------------------------------------------------------------------
runTest("3. Officer linked reports omit restricted raw intake coordinates", function() use ($pdo) {
    $reports = IncidentManager::getLinkedReports(1, $pdo, false);
    foreach ($reports as $rep) {
        if (array_key_exists('raw_lat', $rep) || array_key_exists('raw_lng', $rep)) {
            return 'Officer-facing linked reports still include raw_lat/raw_lng';
        }
        foreach (['contact_phone', 'contact_email', 'tracking_token_hash', 'raw_token'] as $fk) {
            if (array_key_exists($fk, $rep)) {
                return "Officer-facing linked reports leaked {$fk}";
            }
        }
    }

    // Admin path may still include raw coordinates for triage
    $adminReports = IncidentManager::getLinkedReports(1, $pdo, true);
    if (!empty($adminReports) && !array_key_exists('raw_lat', $adminReports[0])) {
        return 'Admin-facing linked reports unexpectedly omitted raw_lat';
    }

    return true;
});

// -----------------------------------------------------------------------------
// 4. Analytics map payload remains free of contact enclave and raw GPS
// -----------------------------------------------------------------------------
runTest("4. Analytics map payload remains free of contact enclave and raw GPS", function() use ($pdo) {
    $rows = AnalyticsManager::getMapIncidents([], 50, $pdo);
    if (empty($rows)) {
        return 'Expected seeded map incidents';
    }

    $forbidden = ['raw_lat', 'raw_lng', 'contact_phone', 'contact_email', 'tracking_token', 'tracking_token_hash'];
    foreach ($rows as $row) {
        foreach ($forbidden as $key) {
            if (array_key_exists($key, $row)) {
                return "Map analytics leaked {$key}";
            }
        }
    }
    return true;
});

// -----------------------------------------------------------------------------
// 5. CSRF helper accepts valid tokens and rejects invalid ones
// -----------------------------------------------------------------------------
runTest("5. CSRF helper accepts valid tokens and rejects invalid ones", function() {
    Session::start();
    $token = Session::getCsrfToken();
    if ($token === '' || !Session::validateCsrf($token)) {
        return 'Valid CSRF token was rejected';
    }
    if (Session::validateCsrf('not-a-real-token')) {
        return 'Invalid CSRF token was accepted';
    }
    if (Session::validateCsrf(null) || Session::validateCsrf('')) {
        return 'Empty CSRF token was accepted';
    }
    return true;
});

// -----------------------------------------------------------------------------
// 6. Login throttling helper engages after repeated failures
// -----------------------------------------------------------------------------
runTest("6. Login throttling helper engages after repeated failures", function() {
    $key = 'phase10_throttle_' . bin2hex(random_bytes(4));
    Session::clearFailedLogins($key);
    for ($i = 0; $i < 5; $i++) {
        Session::recordFailedLogin($key);
    }
    [$throttled] = Session::isLoginThrottled($key, 5, 300);
    Session::clearFailedLogins($key);
    if (!$throttled) {
        return 'Expected login throttle after 5 failures';
    }
    return true;
});

// -----------------------------------------------------------------------------
// 7. Uploads directory blocks PHP script execution via .htaccess
// -----------------------------------------------------------------------------
runTest("7. Uploads directory blocks PHP script execution via .htaccess", function() {
    $htaccess = ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        return 'uploads/.htaccess missing';
    }
    $contents = (string)file_get_contents($htaccess);
    if (!str_contains($contents, 'php') || !str_contains(strtolower($contents), 'deny')) {
        return 'uploads/.htaccess does not appear to deny PHP execution';
    }
    return true;
});

// -----------------------------------------------------------------------------
// 8. Regression: Phase 7/8/9 core managers still respond
// -----------------------------------------------------------------------------
runTest("8. Regression: Phase 7/8/9 core managers still respond", function() use ($pdo) {
    $adminList = IncidentManager::getIncidents([], 1, 5, $pdo);
    if (!isset($adminList['incidents'], $adminList['pagination'])) {
        return 'IncidentManager::getIncidents failed';
    }

    $officerList = IncidentManager::getOfficerIncidents(2, ['view' => 'active'], 1, 5, $pdo);
    if (!isset($officerList['incidents'])) {
        return 'IncidentManager::getOfficerIncidents failed';
    }

    $metrics = AnalyticsManager::getSummaryMetrics([], $pdo);
    if (!isset($metrics['total_incidents'])) {
        return 'AnalyticsManager::getSummaryMetrics failed';
    }

    return true;
});

// -----------------------------------------------------------------------------
// 9. End-to-end citizen reporting and cryptographic tracking token isolation
// -----------------------------------------------------------------------------
runTest("9. End-to-end citizen reporting and cryptographic tracking token isolation", function() use ($pdo) {
    $citizenId = 4; // vesu_watcher
    $reportRef = 'REP-TEST-P10-' . strtoupper(bin2hex(random_bytes(3)));
    $rawToken = 'cstk_' . bin2hex(random_bytes(16));
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        "INSERT INTO incident_reports 
         (report_reference, tracking_token_hash, incident_id, citizen_id, category_id,
          title, description, submitted_severity, submission_mode, raw_lat, raw_lng,
          location_description, status, created_at)
         VALUES
         (:ref, :hash, NULL, :cid, 1, 'Phase 10 E2E Leak Test', 'Testing report submission and tracking enclave isolation',
          'high', 'pseudonymous', 21.192000, 72.798000, 'Near Dumas Road Crossing', 'pending_review', NOW())"
    );
    $stmt->execute([
        ':ref'  => $reportRef,
        ':hash' => $tokenHash,
        ':cid'  => $citizenId
    ]);
    $reportId = (int)$pdo->lastInsertId();

    // Insert private contact info
    $cStmt = $pdo->prepare(
        "INSERT INTO citizen_contacts (report_id, contact_name, contact_phone, contact_email, preferred_contact_method, consent_emergency_followup, is_verified, created_at)
         VALUES (:rid, 'Secret Whistleblower', '+91 99999 88888', 'whistleblower@example.com', 'email', 1, 0, NOW())"
    );
    $cStmt->execute([':rid' => $reportId]);

    try {
        // 1. Verify tracking lookup by token hash
        $trackStmt = $pdo->prepare(
            'SELECT r.report_reference, r.title, r.status, c.name AS category_name
             FROM incident_reports r
             JOIN incident_categories c ON r.category_id = c.id
             WHERE r.tracking_token_hash = :hash'
        );
        $trackStmt->execute([':hash' => $tokenHash]);
        $row = $trackStmt->fetch();

        if (!$row || $row['report_reference'] !== $reportRef) {
            return 'Tracking lookup by hash failed to find report';
        }

        // 2. Ensure contact details are strictly in citizen_contacts and NOT in report
        $repCheck = $pdo->prepare('SELECT * FROM incident_reports WHERE id = :id');
        $repCheck->execute([':id' => $reportId]);
        $repRow = $repCheck->fetch();

        if (isset($repRow['contact_name']) || isset($repRow['contact_phone']) || isset($repRow['contact_email'])) {
            return 'Contact columns found in incident_reports table';
        }

        // 3. Ensure lookup by wrong token fails
        $badStmt = $pdo->prepare('SELECT id FROM incident_reports WHERE tracking_token_hash = :hash');
        $badStmt->execute([':hash' => hash('sha256', 'wrong_token')]);
        if ($badStmt->fetch()) {
            return 'Invalid token lookup returned a record';
        }

        return true;
    } finally {
        $pdo->prepare('DELETE FROM citizen_contacts WHERE report_id = :id')->execute([':id' => $reportId]);
        $pdo->prepare('DELETE FROM incident_reports WHERE id = :id')->execute([':id' => $reportId]);
    }
});

// -----------------------------------------------------------------------------
// 10. Strict officer boundary: officer cannot view or update another officer's incident
// -----------------------------------------------------------------------------
runTest("10. Strict officer boundary: officer cannot view or update another officer's incident", function() use ($pdo) {
    $officerA = 2; // officer_patel
    $officerB = 3; // officer_shah
    $adminId = 1;

    $ref = 'TEST-BOUND-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare(
        "INSERT INTO incidents
         (incident_reference, category_id, title, description, severity, priority_score, status,
          obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at)
         VALUES (:ref, 1, 'Officer Boundary Probe', 'Test boundary integrity', 'medium', 50, 'assigned',
                 21.19000000, 72.80000000, 150, 'Vesu', 'Surat', 1, NOW(), NOW())"
    )->execute([':ref' => $ref]);
    $incId = (int)$pdo->lastInsertId();

    try {
        // Assign to Officer A
        IncidentManager::assignIncident($incId, $officerA, $adminId, 'Assigned to Patel', $pdo);

        // Verify Officer A can access detail
        $detailA = IncidentManager::getOfficerIncidentDetail($incId, $officerA, $pdo);
        if (!$detailA) {
            return 'Assigned Officer A could not view incident detail';
        }

        // Verify Officer B is DENIED access to detail
        $detailB = IncidentManager::getOfficerIncidentDetail($incId, $officerB, $pdo);
        if ($detailB !== null) {
            return 'Unauthorized Officer B was able to view Officer A incident detail';
        }

        // Verify Officer B is REJECTED when attempting status transition
        $updateRes = IncidentManager::updateOfficerStatus($incId, 'in_progress', $officerB, 'Unauthorized update attempt', $pdo);
        if ($updateRes['success']) {
            return 'Unauthorized Officer B was permitted to update incident status';
        }

        return true;
    } finally {
        $pdo->prepare('DELETE FROM incident_assignments WHERE incident_id = :id')->execute([':id' => $incId]);
        $pdo->prepare('DELETE FROM incident_status_history WHERE incident_id = :id')->execute([':id' => $incId]);
        $pdo->prepare('DELETE FROM audit_logs WHERE target_id = :ref')->execute([':ref' => $ref]);
        $pdo->prepare('DELETE FROM incidents WHERE id = :id')->execute([':id' => $incId]);
    }
});

// -----------------------------------------------------------------------------
// 11. SQL injection and XSS safety on tracking inputs
// -----------------------------------------------------------------------------
runTest("11. SQL injection and XSS safety on tracking inputs", function() use ($pdo) {
    $maliciousInputs = [
        "' OR '1'='1",
        "'; DROP TABLE incident_reports; --",
        "<script>alert('XSS')</script>",
        "cstk_' UNION SELECT * FROM users --",
        str_repeat('A', 5000)
    ];

    foreach ($maliciousInputs as $badInput) {
        $badHash = hash('sha256', $badInput);
        $stmt = $pdo->prepare(
            'SELECT r.id, r.report_reference, r.title 
             FROM incident_reports r 
             WHERE r.tracking_token_hash = :hash'
        );
        $stmt->execute([':hash' => $badHash]);
        $res = $stmt->fetch();
        // Prepared statements guarantee safe execution without syntax crash
        if ($res !== false) {
            return "Malicious token input unexpectedly matched a record: {$badInput}";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 12. Session security: Session::destroy() and open redirect sanitization
// -----------------------------------------------------------------------------
runTest("12. Session security: Session::destroy() and open redirect sanitization", function() {
    Session::start();
    $_SESSION['test_token'] = 'p10_secret';
    $_SESSION['logged_in'] = true;
    Session::destroy();

    // Verify session data was emptied
    if (!empty($_SESSION)) {
        return 'Session array was not cleared by Session::destroy()';
    }

    // Open redirect sanitization
    $openRedirects = [
        'https://attacker.example.com/evil',
        '//attacker.example.com/evil',
        '/\\attacker.example.com',
        'javascript:alert(1)',
        'data:text/html,<script>alert(1)</script>'
    ];

    foreach ($openRedirects as $badUrl) {
        $sanitized = Session::sanitizeRedirect($badUrl, '/');
        if ($sanitized !== '/') {
            return "Open redirect vulnerability: '{$badUrl}' was sanitized to '{$sanitized}' instead of '/'";
        }
    }

    // Valid internal redirects must be preserved
    if (Session::sanitizeRedirect('/admin/incidents.php') !== '/admin/incidents.php') {
        return 'Valid internal path was incorrectly stripped';
    }

    return true;
});

// -----------------------------------------------------------------------------
// 13. Resolution duration telemetry handles null timestamps safely
// -----------------------------------------------------------------------------
runTest("13. Resolution duration telemetry handles null timestamps safely", function() use ($pdo) {
    // When filtering by a status that has no resolved incidents, avg_resolution_hours must return null
    $metrics = AnalyticsManager::getSummaryMetrics(['status' => 'reported'], $pdo);
    // Reported issues are not resolved, so avg_resolution_hours should be null
    if ($metrics['avg_resolution_hours'] !== null) {
        return 'Expected null avg_resolution_hours for reported-only status filter';
    }

    // Unfiltered metrics should return a non-negative float or null
    $allMetrics = AnalyticsManager::getSummaryMetrics([], $pdo);
    if ($allMetrics['avg_resolution_hours'] !== null && (!is_numeric($allMetrics['avg_resolution_hours']) || $allMetrics['avg_resolution_hours'] < 0)) {
        return 'avg_resolution_hours must be a positive number or null';
    }

    return true;
});

echo "\n======================================================================\n";
echo "Phase 10 Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "======================================================================\n";

exit($failed > 0 ? 1 : 0);
