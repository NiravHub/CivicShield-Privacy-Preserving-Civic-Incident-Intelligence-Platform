<?php
declare(strict_types=1);

/**
 * CivicShield - Checkpoint C3 / Gap 2: Distinct-Report Auto-Promotion Test Suite
 *
 * Validates:
 * 1. Standalone report promotion into canonical incident.
 * 2. Report state updated to 'standalone' with incident_id linked.
 * 3. Exactly one incident created with initial status 'reported' and reports_count = 1.
 * 4. Canonical incident reference follows 'CS-SRT-YYYY-XXXX' format and is unique.
 * 5. Differential coordinate obfuscation adheres to the documented privacy policy (~150m bound).
 * 6. Initial status history recorded ('intake' -> 'reported').
 * 7. Audit log recorded without exposing citizen PII or raw coordinates.
 * 8. Transaction integrity: rollback leaves no partial records.
 * 9. Duplicate protection: reprocessing already consolidated report is rejected.
 * 10. Failure cases: invalid report IDs and null coordinates handled safely.
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

/**
 * Calculates Haversine distance in meters between two coordinate pairs.
 */
function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

echo "======================================================================\n";
echo "CivicShield C3 - Distinct-Report Auto-Promotion State Machine Tests\n";
echo "======================================================================\n\n";

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Cannot connect to database: {$e->getMessage()}\n");
    exit(1);
}

// Tracking arrays for clean fixture teardown
$trackedReportIds = [];
$trackedIncidentIds = [];

/**
 * Helper to insert an isolated synthetic report fixture.
 */
function createTestReport(PDO $pdo, array $overrides = []): int
{
    global $trackedReportIds;

    $suffix = bin2hex(random_bytes(4));
    $ref = $overrides['report_reference'] ?? ('TEST-REP-' . $suffix);
    $tokenHash = $overrides['tracking_token_hash'] ?? hash('sha256', 'token_' . $ref);
    $catId = $overrides['category_id'] ?? 1;
    $title = $overrides['title'] ?? ('Test Distinct Road Hazard ' . $suffix);
    $desc = $overrides['description'] ?? 'Isolated road surface disruption requiring municipal inspection.';
    $sev = $overrides['submitted_severity'] ?? 'high';
    $rawLat = array_key_exists('raw_lat', $overrides) ? $overrides['raw_lat'] : 21.19640000;
    $rawLng = array_key_exists('raw_lng', $overrides) ? $overrides['raw_lng'] : 72.79580000;
    $locDesc = $overrides['location_description'] ?? 'Near Prime Arcade, Adajan, Surat';

    $stmt = $pdo->prepare(
        'INSERT INTO incident_reports 
         (report_reference, tracking_token_hash, incident_id, citizen_id, category_id,
          title, description, submitted_severity, submission_mode, raw_lat, raw_lng,
          location_description, status, created_at)
         VALUES (:ref, :token, NULL, NULL, :cat_id, :title, :desc, :sev, \'pseudonymous\',
                 :lat, :lng, :loc_desc, \'pending_review\', NOW())'
    );
    $stmt->execute([
        ':ref'      => $ref,
        ':token'    => $tokenHash,
        ':cat_id'   => $catId,
        ':title'    => $title,
        ':desc'     => $desc,
        ':sev'      => $sev,
        ':lat'      => $rawLat,
        ':lng'      => $rawLng,
        ':loc_desc' => $locDesc
    ]);
    $reportId = (int)$pdo->lastInsertId();
    $trackedReportIds[] = $reportId;
    return $reportId;
}

// -----------------------------------------------------------------------------
// Test 1: Standalone report promotion
// -----------------------------------------------------------------------------
runTest("1. Standalone report promotion creates canonical incident", function() use ($pdo, &$trackedIncidentIds) {
    $reportId = createTestReport($pdo);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);

    if (empty($res['success']) || $res['success'] !== true) {
        return "Promotion failed: " . ($res['message'] ?? 'Unknown error');
    }

    if (empty($res['incident_id']) || (int)$res['incident_id'] <= 0) {
        return "Invalid or missing incident_id returned";
    }

    $trackedIncidentIds[] = (int)$res['incident_id'];
    return true;
});

// -----------------------------------------------------------------------------
// Test 2: Source report state updated to 'standalone'
// -----------------------------------------------------------------------------
runTest("2. Source report state updated to 'standalone' with incident link", function() use ($pdo, &$trackedIncidentIds) {
    $reportId = createTestReport($pdo);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);
    $trackedIncidentIds[] = (int)$res['incident_id'];

    $stmt = $pdo->prepare('SELECT id, incident_id, status FROM incident_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        return "Report not found after promotion";
    }

    if ($report['status'] !== 'standalone') {
        return "Expected report status 'standalone', got '{$report['status']}'";
    }

    if ((int)$report['incident_id'] !== (int)$res['incident_id']) {
        return "Expected report.incident_id {$res['incident_id']}, got {$report['incident_id']}";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 3: Exactly one incident created with initial status 'reported'
// -----------------------------------------------------------------------------
runTest("3. Canonical incident created with initial status 'reported' and reports_count 1", function() use ($pdo, &$trackedIncidentIds) {
    $reportId = createTestReport($pdo, ['title' => 'Pothole on Ring Road', 'submitted_severity' => 'critical']);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);
    $incidentId = (int)$res['incident_id'];
    $trackedIncidentIds[] = $incidentId;

    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$incident) {
        return "Incident record not found in database";
    }

    if ($incident['status'] !== 'reported') {
        return "Expected initial incident status 'reported', got '{$incident['status']}'";
    }

    if ((int)$incident['reports_count'] !== 1) {
        return "Expected reports_count = 1, got {$incident['reports_count']}";
    }

    if ($incident['severity'] !== 'critical') {
        return "Expected severity 'critical', got '{$incident['severity']}'";
    }

    if ((int)$incident['priority_score'] <= 0 || (int)$incident['priority_score'] > 100) {
        return "Expected priority_score between 1 and 100, got {$incident['priority_score']}";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 4: Canonical incident reference follows format and is unique
// -----------------------------------------------------------------------------
runTest("4. Canonical incident reference follows 'CS-SRT-YYYY-XXXXXX' format and is unique", function() use ($pdo, &$trackedIncidentIds) {
    $repId1 = createTestReport($pdo);
    $repId2 = createTestReport($pdo);

    $res1 = IncidentManager::createIncidentFromReport($repId1, $pdo, 1);
    $res2 = IncidentManager::createIncidentFromReport($repId2, $pdo, 1);

    $trackedIncidentIds[] = (int)$res1['incident_id'];
    $trackedIncidentIds[] = (int)$res2['incident_id'];

    $ref1 = $res1['incident_reference'];
    $ref2 = $res2['incident_reference'];

    $year = date('Y');
    $pattern = '/^CS-SRT-' . $year . '-[A-F0-9]{6}$/';

    if (!preg_match($pattern, $ref1)) {
        return "Reference 1 ('{$ref1}') does not match expected format CS-SRT-YYYY-XXXXXX";
    }

    if (!preg_match($pattern, $ref2)) {
        return "Reference 2 ('{$ref2}') does not match expected format CS-SRT-YYYY-XXXXXX";
    }

    if ($ref1 === $ref2) {
        return "Collision detected: two promoted incidents share identical reference '{$ref1}'";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 5: Differential coordinate privacy displacement within documented range
// -----------------------------------------------------------------------------
runTest("5. Coordinate privacy: obfuscated coordinates differ within documented policy", function() use ($pdo, &$trackedIncidentIds) {
    $rawLat = 21.19640000;
    $rawLng = 72.79580000;
    $reportId = createTestReport($pdo, ['raw_lat' => $rawLat, 'raw_lng' => $rawLng]);

    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);
    $incidentId = (int)$res['incident_id'];
    $trackedIncidentIds[] = $incidentId;

    $stmt = $pdo->prepare('SELECT obfuscated_lat, obfuscated_lng, fuzz_radius_meters FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $obfLat = (float)$row['obfuscated_lat'];
    $obfLng = (float)$row['obfuscated_lng'];
    $fuzzRadius = (int)$row['fuzz_radius_meters'];

    if ($fuzzRadius !== 150) {
        return "Expected fuzz_radius_meters 150, got {$fuzzRadius}";
    }

    // Coordinates must NOT be identical to raw input
    if ($obfLat === $rawLat && $obfLng === $rawLng) {
        return "PRIVACY VIOLATION: Obfuscated coordinates are identical to raw sensor coordinates!";
    }

    // Displacement distance in meters must fall within ~0.35 * 150m (52m) and 150m (+ tolerance)
    $distance = haversineMeters($rawLat, $rawLng, $obfLat, $obfLng);
    if ($distance < 40.0 || $distance > 165.0) {
        return "Displacement distance ({$distance}m) outside expected privacy bound [40m, 165m]";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 6: Initial status history entry recorded
// -----------------------------------------------------------------------------
runTest("6. Initial incident status history entry recorded ('intake' -> 'reported')", function() use ($pdo, &$trackedIncidentIds) {
    $actorAdminId = 1;
    $reportId = createTestReport($pdo);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, $actorAdminId);
    $incidentId = (int)$res['incident_id'];
    $trackedIncidentIds[] = $incidentId;

    $stmt = $pdo->prepare('SELECT * FROM incident_status_history WHERE incident_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $incidentId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($history) !== 1) {
        return "Expected exactly 1 status history record, found " . count($history);
    }

    $entry = $history[0];
    if ($entry['status_from'] !== 'intake' || $entry['status_to'] !== 'reported') {
        return "Expected status transition 'intake' -> 'reported', got '{$entry['status_from']}' -> '{$entry['status_to']}'";
    }

    if ((int)$entry['changed_by_user_id'] !== $actorAdminId) {
        return "Expected changed_by_user_id = {$actorAdminId}, got {$entry['changed_by_user_id']}";
    }

    if ((int)$entry['is_public'] !== 1) {
        return "Expected is_public = 1";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 7: Audit log recorded without exposing citizen PII or raw coordinates
// -----------------------------------------------------------------------------
runTest("7. Audit log recorded with action 'INCIDENT_CREATED_FROM_REPORT' without PII", function() use ($pdo, &$trackedIncidentIds) {
    $reportId = createTestReport($pdo, ['raw_lat' => 21.19640000, 'raw_lng' => 72.79580000]);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);
    $incidentId = (int)$res['incident_id'];
    $trackedIncidentIds[] = $incidentId;

    $stmt = $pdo->prepare(
        "SELECT * FROM audit_logs 
         WHERE action = 'INCIDENT_CREATED_FROM_REPORT' AND target_type = 'incident' AND target_id = :id"
    );
    $stmt->execute([':id' => (string)$incidentId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        return "Audit log entry not found for promoted incident {$incidentId}";
    }

    if ($log['actor_role'] !== 'admin') {
        return "Expected actor_role 'admin', got '{$log['actor_role']}'";
    }

    $meta = json_decode((string)$log['metadata_json'], true);
    if (!is_array($meta)) {
        return "Invalid metadata_json in audit log";
    }

    // Must contain reference data
    if (empty($meta['incident_reference']) || empty($meta['report_reference'])) {
        return "Audit metadata missing incident or report reference";
    }

    // Must NOT contain sensitive PII or raw coordinates
    $rawMetaStr = (string)$log['metadata_json'];
    if (str_contains($rawMetaStr, '21.1964') || str_contains($rawMetaStr, '72.7958')) {
        return "PRIVACY VIOLATION: Raw coordinates leaked in audit log metadata!";
    }
    if (str_contains($rawMetaStr, 'contact_phone') || str_contains($rawMetaStr, 'contact_email')) {
        return "PRIVACY VIOLATION: Citizen contact details leaked in audit log!";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 8: Transaction integrity on rollback
// -----------------------------------------------------------------------------
runTest("8. Transaction integrity: outer rollback leaves no orphan incident", function() use ($pdo, &$trackedReportIds) {
    $pdo->beginTransaction();

    $reportId = createTestReport($pdo);
    $res = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);

    if (!$res['success']) {
        $pdo->rollBack();
        return "Precondition failed: createIncidentFromReport failed inside transaction";
    }

    $tentativeIncidentId = (int)$res['incident_id'];

    // Rollback the transaction
    $pdo->rollBack();

    // Verify neither incident nor report status persisted
    $stmtInc = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE id = :id');
    $stmtInc->execute([':id' => $tentativeIncidentId]);
    if ((int)$stmtInc->fetchColumn() !== 0) {
        return "TRANSACTION LEAK: Incident record persisted after rollback!";
    }

    $stmtRep = $pdo->prepare('SELECT COUNT(*) FROM incident_reports WHERE id = :id');
    $stmtRep->execute([':id' => $reportId]);
    if ((int)$stmtRep->fetchColumn() !== 0) {
        return "TRANSACTION LEAK: Test report persisted after rollback!";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 9: Duplicate promotion protection (idempotency)
// -----------------------------------------------------------------------------
runTest("9. Duplicate promotion protection: re-promoting returns error", function() use ($pdo, &$trackedIncidentIds) {
    $reportId = createTestReport($pdo);
    $firstRes = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);

    if (!$firstRes['success']) {
        return "Initial promotion failed";
    }
    $incidentId = (int)$firstRes['incident_id'];
    $trackedIncidentIds[] = $incidentId;

    // Second attempt on the same report
    $secondRes = IncidentManager::createIncidentFromReport($reportId, $pdo, 1);

    if ($secondRes['success'] === true) {
        return "DUPLICATE VULNERABILITY: Reprocessing already consolidated report succeeded!";
    }

    if (empty($secondRes['message']) || !str_contains($secondRes['message'], 'already consolidated')) {
        return "Expected 'already consolidated' error message, got: " . ($secondRes['message'] ?? 'none');
    }

    // Verify incident count for this report remains strictly 1
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_reports WHERE incident_id = :inc_id');
    $stmt->execute([':inc_id' => $incidentId]);
    $count = (int)$stmt->fetchColumn();

    if ($count !== 1) {
        return "Expected exactly 1 report linked to incident, found {$count}";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 10: Failure cases and missing coordinates handling
// -----------------------------------------------------------------------------
runTest("10. Failure cases: invalid report ID and null coordinates handled safely", function() use ($pdo, &$trackedIncidentIds) {
    // 10a. Non-existent report ID
    $invalidId = 999999999;
    $resFail = IncidentManager::createIncidentFromReport($invalidId, $pdo, 1);

    if ($resFail['success'] === true) {
        return "Expected non-existent report ID to fail, but succeeded";
    }

    if (empty($resFail['message']) || !str_contains($resFail['message'], 'not found')) {
        return "Expected 'not found' message for invalid report ID";
    }

    // 10b. Missing/null coordinates fallback
    $nullCoordsReportId = createTestReport($pdo, [
        'raw_lat' => null,
        'raw_lng' => null,
        'location_description' => 'Unspecified Surat Location'
    ]);

    $resNull = IncidentManager::createIncidentFromReport($nullCoordsReportId, $pdo, 1);
    if (!$resNull['success']) {
        return "Promotion failed for report with null coordinates: " . ($resNull['message'] ?? '');
    }

    $incId = (int)$resNull['incident_id'];
    $trackedIncidentIds[] = $incId;

    $stmt = $pdo->prepare('SELECT obfuscated_lat, obfuscated_lng FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incId]);
    $coords = $stmt->fetch(PDO::FETCH_ASSOC);

    // Should default to Surat Municipal Center (21.1702, 72.8311)
    if (abs((float)$coords['obfuscated_lat'] - 21.17020000) > 0.0001 ||
        abs((float)$coords['obfuscated_lng'] - 72.83110000) > 0.0001) {
        return "Expected Surat center default coordinates (21.1702, 72.8311), got ({$coords['obfuscated_lat']}, {$coords['obfuscated_lng']})";
    }

    return true;
});

// =============================================================================
// Teardown / Cleanup: Delete only the records created by this test suite
// =============================================================================
try {
    if (!empty($trackedIncidentIds)) {
        $inInc = implode(',', array_map('intval', $trackedIncidentIds));
        $pdo->query("DELETE FROM audit_logs WHERE target_type = 'incident' AND target_id IN ({$inInc})");
        $pdo->query("DELETE FROM incident_status_history WHERE incident_id IN ({$inInc})");
        $pdo->query("UPDATE incident_reports SET incident_id = NULL WHERE incident_id IN ({$inInc})");
        $pdo->query("DELETE FROM incidents WHERE id IN ({$inInc})");
    }
    if (!empty($trackedReportIds)) {
        $inRep = implode(',', array_map('intval', $trackedReportIds));
        $pdo->query("DELETE FROM incident_reports WHERE id IN ({$inRep})");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup Warning: " . $e->getMessage() . "\n");
}

echo "\n======================================================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "======================================================================\n";

exit($failed > 0 ? 1 : 0);
