<?php
declare(strict_types=1);

/**
 * CivicShield Phase 6 Automated Test Suite
 * Validates Duplicate Detection, Priority Scoring, Admin Review & Regressions
 */

require_once dirname(__DIR__) . '/config/app.php';

echo "======================================================================\n";
echo "CivicShield Phase 6 - Duplicate Detection & Priority Scoring Tests\n";
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
// 1. Same category and very close coordinates produce a candidate
// -----------------------------------------------------------------------------
runTest("1. Same category and very close coordinates produce a candidate", function() use ($pdo) {
    // Incident 1 in seed is at (21.1963, 72.7958) category 1 (Roads)
    // Create synthetic report 15m away with category 1
    $pdo->beginTransaction();
    try {
        $ref = 'TEST-REP-' . bin2hex(random_bytes(4));
        $tokenHash = hash('sha256', 'token_' . $ref);
        // Approx 15 meters north: +0.000135 lat
        $lat = 21.196435;
        $lng = 72.795800;

        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, incident_id, citizen_id, category_id,
              title, description, submitted_severity, submission_mode, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, NULL, 5, 1, :title, :desc, \'high\', \'pseudonymous\', :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([
            ':ref'   => $ref,
            ':token' => $tokenHash,
            ':title' => 'Deep Road Pothole crater near arcade',
            ':desc'  => 'Severe road paver damage causing vehicle skid',
            ':lat'   => $lat,
            ':lng'   => $lng
        ]);
        $repId = (int)$pdo->lastInsertId();

        $candidates = DuplicateDetector::findCandidatesForReport($repId, 50.0, $pdo);
        $pdo->rollBack();

        if (empty($candidates)) {
            return "Expected at least 1 candidate, found 0";
        }
        $cand = $candidates[0];
        if ($cand['candidate_incident_id'] !== 1) {
            return "Expected candidate incident ID 1, got {$cand['candidate_incident_id']}";
        }
        if ($cand['category_matched'] !== 1) {
            return "Expected category_matched = 1";
        }
        if ($cand['match_score'] < 60.0) {
            return "Expected match_score >= 60.0, got {$cand['match_score']}";
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 2. Incidents outside the configured radius do not become geographic candidates
// -----------------------------------------------------------------------------
runTest("2. Incidents outside configured radius (50m) do not become candidates", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $ref = 'TEST-REP-' . bin2hex(random_bytes(4));
        $tokenHash = hash('sha256', 'token_' . $ref);
        // Approx 120 meters away: +0.00108 lat
        $lat = 21.197380;
        $lng = 72.795800;

        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, incident_id, citizen_id, category_id,
              title, description, submitted_severity, submission_mode, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, NULL, 5, 1, :title, :desc, \'high\', \'pseudonymous\', :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([
            ':ref'   => $ref,
            ':token' => $tokenHash,
            ':title' => 'Deep Road Pothole crater near arcade',
            ':desc'  => 'Severe road paver damage causing vehicle skid',
            ':lat'   => $lat,
            ':lng'   => $lng
        ]);
        $repId = (int)$pdo->lastInsertId();

        // Configured radius = 50.0m
        $candidates = DuplicateDetector::findCandidatesForReport($repId, 50.0, $pdo);
        $pdo->rollBack();

        // Must NOT match incident 1 because it is > 50m away
        foreach ($candidates as $c) {
            if ($c['candidate_incident_id'] === 1) {
                return "Incident 1 was returned despite being > 50m away ({$c['spatial_distance_meters']}m)";
            }
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 3. Different categories at the same location receive the intended lower similarity
// -----------------------------------------------------------------------------
runTest("3. Different categories at same location receive lower similarity", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $refSame = 'TEST-REP-' . bin2hex(random_bytes(4));
        $refDiff = 'TEST-REP-' . bin2hex(random_bytes(4));
        $lat = 21.196350;
        $lng = 72.795800;

        // Same category (1)
        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'Pothole damage\', \'Road surface crater\', :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([':ref' => $refSame, ':token' => hash('sha256', $refSame), ':lat' => $lat, ':lng' => $lng]);
        $repSameId = (int)$pdo->lastInsertId();

        // Different category (2: Solid Waste)
        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 2, \'Pothole damage\', \'Road surface crater\', :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([':ref' => $refDiff, ':token' => hash('sha256', $refDiff), ':lat' => $lat, ':lng' => $lng]);
        $repDiffId = (int)$pdo->lastInsertId();

        $candsSame = DuplicateDetector::findCandidatesForReport($repSameId, 50.0, $pdo);
        $candsDiff = DuplicateDetector::findCandidatesForReport($repDiffId, 50.0, $pdo);
        $pdo->rollBack();

        if (empty($candsSame) || empty($candsDiff)) {
            return "Expected candidates for both test reports";
        }

        $scoreSame = $candsSame[0]['match_score'];
        $scoreDiff = $candsDiff[0]['match_score'];

        // Because category weight is 30%, different category score must be exactly 30 points lower
        $scoreDelta = $scoreSame - $scoreDiff;
        if (abs($scoreDelta - 30.0) > 1.0) {
            return "Expected score difference ~30.0 (category weight 30%), got {$scoreDelta} ({$scoreSame} vs {$scoreDiff})";
        }
        if ($candsDiff[0]['category_matched'] !== 0) {
            return "Expected category_matched = 0 for different category";
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 4. Similar text at distant locations does not become a geographic duplicate solely because of text
// -----------------------------------------------------------------------------
runTest("4. Similar text at distant locations does not become a duplicate", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $ref = 'TEST-REP-' . bin2hex(random_bytes(4));
        // Coordinates in a completely different city or 10km away in Surat (e.g. 21.3000, 72.8500)
        $lat = 21.300000;
        $lng = 72.850000;

        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, 
                     \'Deep Road Excavation & Pothole Hazard near Prime Arcade\', 
                     \'Severe 4-foot asphalt crater caused by monsoon runoff; continuous vehicle undercarriage damage and two-wheeler skid hazards.\', 
                     :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([':ref' => $ref, ':token' => hash('sha256', $ref), ':lat' => $lat, ':lng' => $lng]);
        $repId = (int)$pdo->lastInsertId();

        $candidates = DuplicateDetector::findCandidatesForReport($repId, 50.0, $pdo);
        $pdo->rollBack();

        // Gating rule: 10km away must return 0 candidates despite identical text and category
        if (!empty($candidates)) {
            return "Expected 0 candidates due to geographic gating, got " . count($candidates);
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 5. Different text at nearby locations is scored appropriately
// -----------------------------------------------------------------------------
runTest("5. Different text at nearby locations is scored appropriately", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $ref = 'TEST-REP-' . bin2hex(random_bytes(4));
        // 10m away from Incident 1, same category, but completely disjoint wording
        $lat = 21.196350;
        $lng = 72.795800;

        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, 
                     \'Broken pavers and sunken street stones\', 
                     \'Pedestrians tripping over uneven tiles in shopping sector\', 
                     :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([':ref' => $ref, ':token' => hash('sha256', $ref), ':lat' => $lat, ':lng' => $lng]);
        $repId = (int)$pdo->lastInsertId();

        $candidates = DuplicateDetector::findCandidatesForReport($repId, 50.0, $pdo);
        $pdo->rollBack();

        if (empty($candidates)) {
            return "Expected candidate match due to proximity";
        }
        $cand = $candidates[0];
        // Spatial ~80%, Category = 100%, Time decay over days, Text low (< 20%)
        if ($cand['match_score'] < 65.0 || $cand['match_score'] > 95.0) {
            return "Expected score between 65.0 and 95.0, got {$cand['match_score']}";
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 6. Multiple candidate incidents are handled
// -----------------------------------------------------------------------------
runTest("6. Multiple candidate incidents within radius are identified and sorted", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        // Create 2 test incidents within 30m of each other
        $incRefA = 'CS-TEST-' . bin2hex(random_bytes(4));
        $incRefB = 'CS-TEST-' . bin2hex(random_bytes(4));

        $pdo->prepare(
            'INSERT INTO incidents (incident_reference, category_id, title, description, severity, obfuscated_lat, obfuscated_lng, neighborhood, status, created_at)
             VALUES (:ref, 1, \'Test Inc A\', \'Description A\', \'medium\', 21.180000, 72.800000, \'TestWard\', \'reported\', NOW())'
        )->execute([':ref' => $incRefA]);
        $incIdA = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO incidents (incident_reference, category_id, title, description, severity, obfuscated_lat, obfuscated_lng, neighborhood, status, created_at)
             VALUES (:ref, 1, \'Test Inc B\', \'Description B\', \'medium\', 21.180100, 72.800000, \'TestWard\', \'reported\', NOW())'
        )->execute([':ref' => $incRefB]);
        $incIdB = (int)$pdo->lastInsertId();

        // Create report located at 21.180020, 72.800000 (closer to A than B)
        $repRef = 'TEST-REP-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'Test Report\', \'Description\', 21.180020, 72.800000, \'pending_review\', NOW())'
        )->execute([':ref' => $repRef, ':token' => hash('sha256', $repRef)]);
        $repId = (int)$pdo->lastInsertId();

        $candidates = DuplicateDetector::findCandidatesForReport($repId, 50.0, $pdo);
        $pdo->rollBack();

        $matchedIds = array_column($candidates, 'candidate_incident_id');
        if (!in_array($incIdA, $matchedIds, true) || !in_array($incIdB, $matchedIds, true)) {
            return "Expected both incident {$incIdA} and {$incIdB} in candidates, got: " . implode(', ', $matchedIds);
        }

        // Verify sorted descending by match_score
        for ($i = 0; $i < count($candidates) - 1; $i++) {
            if ($candidates[$i]['match_score'] < $candidates[$i + 1]['match_score']) {
                return "Candidates are not sorted descending by match score";
            }
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 7. Missing or invalid coordinates do not crash processing
// -----------------------------------------------------------------------------
runTest("7. Missing or invalid coordinates do not crash processing", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        // Test null coordinates
        $refNull = 'TEST-REP-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incident_reports (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'No GPS\', \'Desc\', NULL, NULL, \'pending_review\', NOW())'
        )->execute([':ref' => $refNull, ':token' => hash('sha256', $refNull)]);
        $repNullId = (int)$pdo->lastInsertId();

        $candsNull = DuplicateDetector::findCandidatesForReport($repNullId, 50.0, $pdo);
        if ($candsNull !== []) {
            $pdo->rollBack();
            return "Expected empty array for NULL coordinates";
        }

        // Test out of bounds coordinates (lat = 95.0, lng = 200.0)
        $refBad = 'TEST-REP-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incident_reports (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'Bad GPS\', \'Desc\', 95.0, 200.0, \'pending_review\', NOW())'
        )->execute([':ref' => $refBad, ':token' => hash('sha256', $refBad)]);
        $repBadId = (int)$pdo->lastInsertId();

        $candsBad = DuplicateDetector::findCandidatesForReport($repBadId, 50.0, $pdo);
        $pdo->rollBack();

        if ($candsBad !== []) {
            return "Expected empty array for out-of-bounds coordinates";
        }

        // Test processReport with non-existent report ID
        $count = DuplicateDetector::processReport(999999999, 50.0, $pdo);
        if ($count !== 0) {
            return "Expected 0 candidates for non-existent report ID";
        }

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 8. Duplicate scores remain between 0 and 100
// -----------------------------------------------------------------------------
runTest("8. Duplicate scores remain strictly between 0 and 100", function() {
    $testCases = [
        [0.0, true, 100.0, 100.0],
        [0.0, false, 0.0, 0.0],
        [100.0, true, 100.0, 100.0],
        [50.0, false, 50.0, 50.0],
        [-10.0, false, -50.0, -10.0], // boundary clamping
        [150.0, true, 200.0, 200.0],  // boundary clamping
    ];

    foreach ($testCases as [$spatial, $cat, $text, $time]) {
        $score = DuplicateDetector::computeCompositeScore($spatial, $cat, $text, $time);
        if ($score < 0.0 || $score > 100.0) {
            return "Duplicate score {$score} out of bounds [0, 100]";
        }
    }

    // Test spatial score directly
    $sp1 = DuplicateDetector::calculateSpatialScore(0.0, 50.0);
    $sp2 = DuplicateDetector::calculateSpatialScore(50.0, 50.0);
    $sp3 = DuplicateDetector::calculateSpatialScore(75.0, 50.0);
    $sp4 = DuplicateDetector::calculateSpatialScore(25.0, 50.0);

    if ($sp1 !== 100.0 || $sp2 !== 0.0 || $sp3 !== 0.0 || $sp4 !== 50.0) {
        return "Spatial score calculation error ($sp1, $sp2, $sp3, $sp4)";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 9. Priority scores remain between 0 and 100
// -----------------------------------------------------------------------------
runTest("9. Priority scores remain strictly between 0 and 100", function() {
    $severities = ['low', 'medium', 'high', 'critical', 'unknown'];
    $reports = [0, 1, 2, 5, 20, 100];
    $hours = [0.0, 2.0, 24.0, 72.0, 1000.0];
    $slas = [12, 24, 48, null];

    foreach ($severities as $sev) {
        foreach ($reports as $rep) {
            foreach ($hours as $hr) {
                foreach ($slas as $sla) {
                    $res = PriorityScorer::computeScore($sev, $rep, $hr, $sla);
                    if ($res['score'] < 0 || $res['score'] > 100) {
                        return "Priority score {$res['score']} out of bounds for ({$sev}, {$rep}, {$hr}, {$sla})";
                    }
                    if (!isset($res['breakdown']['composite_priority_score'])) {
                        return "Breakdown missing composite_priority_score";
                    }
                }
            }
        }
    }
    return true;
});

// -----------------------------------------------------------------------------
// 10. Report counts do not double-count the same individual report
// -----------------------------------------------------------------------------
runTest("10. Report counts do not double-count the same individual report", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        // Create an incident
        $incRef = 'CS-TEST-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incidents (incident_reference, category_id, title, description, severity, priority_score, obfuscated_lat, obfuscated_lng, neighborhood, status, created_at)
             VALUES (:ref, 1, \'Test Inc\', \'Desc\', \'high\', 50, 21.19, 72.79, \'Adajan\', \'reported\', NOW())'
        )->execute([':ref' => $incRef]);
        $incId = (int)$pdo->lastInsertId();

        // Add 1 report linked to this incident
        $repRef1 = 'TEST-REP-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incident_reports (report_reference, tracking_token_hash, incident_id, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, :inc_id, 1, \'Title\', \'Desc\', 21.19, 72.79, \'duplicate\', NOW())'
        )->execute([':ref' => $repRef1, ':token' => hash('sha256', $repRef1), ':inc_id' => $incId]);

        // Calculate priority
        $res1 = PriorityScorer::updateIncidentPriority($incId, $pdo);
        $count1 = $res1['breakdown']['report_volume']['input_count'];

        // Calling updateIncidentPriority again must NOT double-count
        $res2 = PriorityScorer::updateIncidentPriority($incId, $pdo);
        $count2 = $res2['breakdown']['report_volume']['input_count'];

        $pdo->rollBack();

        if ($count1 !== 1 || $count2 !== 1) {
            return "Report count changed on recalculation ({$count1} vs {$count2})";
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 11. Administrators can review candidates and add notes
// -----------------------------------------------------------------------------
runTest("11. Administrators can review candidates and add notes", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        // Create candidate record
        $repRef = 'TEST-REP-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO incident_reports (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'Candidate Test\', \'Desc\', 21.1963, 72.7958, \'pending_review\', NOW())'
        )->execute([':ref' => $repRef, ':token' => hash('sha256', $repRef)]);
        $repId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO incident_duplicate_candidates 
             (report_id, candidate_incident_id, match_score, spatial_distance_meters, time_difference_minutes, category_matched, text_similarity_score, review_status, created_at)
             VALUES (:rep_id, 1, 88.50, 12, 10, 1, 75.0, \'pending_review\', NOW())'
        )->execute([':rep_id' => $repId]);
        $candId = (int)$pdo->lastInsertId();

        // Simulate admin review: mark as confirmed_duplicate with note
        $adminUserId = 1; // admin_surat
        $notes = 'Confirmed duplicate during automated integration test.';
        $now = date('Y-m-d H:i:s');

        // Update candidate
        $pdo->prepare(
            'UPDATE incident_duplicate_candidates 
             SET review_status = \'confirmed_duplicate\',
                 reviewed_by_user_id = :admin_id,
                 decision_notes = :notes,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([':admin_id' => $adminUserId, ':notes' => $notes, ':id' => $candId]);

        // Link report
        $pdo->prepare(
            'UPDATE incident_reports 
             SET incident_id = 1, status = \'duplicate\' 
             WHERE id = :rep_id'
        )->execute([':rep_id' => $repId]);

        // Insert audit log
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
             VALUES (:admin_id, \'admin\', \'DUPLICATE_CONFIRMED\', \'incident_duplicate_candidate\', :cand_id, :ip_hash, :meta, :now)'
        )->execute([
            ':admin_id' => $adminUserId,
            ':cand_id'  => (string)$candId,
            ':ip_hash'  => hash('sha256', '127.0.0.1_salt'),
            ':meta'     => json_encode(['test' => true]),
            ':now'      => $now
        ]);

        // Verify updated record
        $stmt = $pdo->prepare('SELECT * FROM incident_duplicate_candidates WHERE id = :id');
        $stmt->execute([':id' => $candId]);
        $candRow = $stmt->fetch();

        // Verify report was NOT deleted, but updated
        $stmt2 = $pdo->prepare('SELECT * FROM incident_reports WHERE id = :id');
        $stmt2->execute([':id' => $repId]);
        $repRow = $stmt2->fetch();

        $pdo->rollBack();

        if ($candRow['review_status'] !== 'confirmed_duplicate') {
            return "Expected review_status = 'confirmed_duplicate'";
        }
        if ($candRow['decision_notes'] !== $notes) {
            return "Decision notes mismatch";
        }
        if ((int)$candRow['reviewed_by_user_id'] !== $adminUserId) {
            return "Reviewer ID mismatch";
        }
        if ($repRow === false || (int)$repRow['incident_id'] !== 1 || $repRow['status'] !== 'duplicate') {
            return "Report was not linked correctly or was deleted";
        }

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 12. Citizens and unauthenticated users cannot review candidates
// -----------------------------------------------------------------------------
runTest("12. Citizens and unauthenticated users cannot review candidates", function() {
    // Test access via HTTP
    $baseUrl = (string)Env::get('APP_URL', 'http://localhost/OEP');
    
    // 1. Unauthenticated request to admin/duplicates.php
    $ch = curl_init($baseUrl . '/admin/duplicates.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $httpCodeUnauth = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUnauth = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    // Unauthenticated user must be redirected (302) to login.php
    if ($httpCodeUnauth !== 302 || !str_contains((string)$redirectUnauth, 'login.php')) {
        return "Unauthenticated user was not redirected to login.php (HTTP {$httpCodeUnauth}, redirect: {$redirectUnauth})";
    }

    // 2. Citizen authenticated request to admin/duplicates.php
    $jar = tempnam(sys_get_temp_dir(), 'cs_auth_');
    
    // Login as citizen
    $ch = curl_init($baseUrl . '/auth/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    $html = curl_exec($ch);
    preg_match("/name=[\"']csrf_token[\"']\s+value=[\"']([a-f0-9]{64})[\"']/i", (string)$html, $matches);
    $token = $matches[1] ?? '';

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token' => $token,
        'username'   => 'vesu_watcher',
        'password'   => 'VesuCivic@2026'
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    curl_close($ch);

    // Attempt to access admin/duplicates.php with citizen session
    $ch = curl_init($baseUrl . '/admin/duplicates.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $resBody = curl_exec($ch);
    $httpCodeCit = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($jar);

    // Citizen must receive HTTP 403 Forbidden
    if ($httpCodeCit !== 403) {
        return "Citizen was not denied access with HTTP 403 (Got HTTP {$httpCodeCit})";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 13. Repeated processing does not create redundant candidate rows
// -----------------------------------------------------------------------------
runTest("13. Repeated processing does not create redundant candidate rows", function() use ($pdo) {
    $pdo->beginTransaction();
    try {
        $ref = 'TEST-REP-' . bin2hex(random_bytes(4));
        $lat = 21.196350;
        $lng = 72.795800;

        $stmt = $pdo->prepare(
            'INSERT INTO incident_reports 
             (report_reference, tracking_token_hash, category_id, title, description, raw_lat, raw_lng, status, created_at)
             VALUES (:ref, :token, 1, \'Repeat check\', \'Repeat Desc\', :lat, :lng, \'pending_review\', NOW())'
        );
        $stmt->execute([':ref' => $ref, ':token' => hash('sha256', $ref), ':lat' => $lat, ':lng' => $lng]);
        $repId = (int)$pdo->lastInsertId();

        // Process once
        $count1 = DuplicateDetector::processReport($repId, 50.0, $pdo);
        // Process second time (simulate idempotent re-run)
        $count2 = DuplicateDetector::processReport($repId, 50.0, $pdo);

        // Count candidate rows in database for this report
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM incident_duplicate_candidates WHERE report_id = :id');
        $countStmt->execute([':id' => $repId]);
        $dbRows = (int)$countStmt->fetchColumn();

        $pdo->rollBack();

        if ($dbRows !== 1) {
            return "Expected exactly 1 candidate row after repeated processing, got {$dbRows}";
        }
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 14. Existing login, report submission, and tracking tests still pass
// -----------------------------------------------------------------------------
runTest("14. Existing login, report submission, and tracking tests still pass", function() use ($pdo) {
    $baseUrl = (string)Env::get('APP_URL', 'http://localhost/OEP');
    $jar = tempnam(sys_get_temp_dir(), 'cs_reg_');

    // 1. Test Login as Citizen
    $ch = curl_init($baseUrl . '/auth/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    $html = curl_exec($ch);
    preg_match("/name=[\"']csrf_token[\"']\s+value=[\"']([a-f0-9]{64})[\"']/i", (string)$html, $matches);
    $token = $matches[1] ?? '';

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token' => $token,
        'username'   => 'adajan_civic',
        'password'   => 'SuratCitizen@2026'
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loginLoc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($loginCode !== 302 || !str_contains((string)$loginLoc, 'citizen')) {
        @unlink($jar);
        return "Citizen login failed (HTTP {$loginCode}, Redirect: {$loginLoc})";
    }

    // 2. Test Report Submission with Citizen Session
    $ch = curl_init($baseUrl . '/citizen/report.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    $reportPageHtml = curl_exec($ch);
    preg_match("/name=[\"']csrf_token[\"']\s+value=[\"']([a-f0-9]{64})[\"']/i", (string)$reportPageHtml, $matches);
    $reportCsrf = $matches[1] ?? '';

    $repTitle = 'Regression Validation Pothole ' . bin2hex(random_bytes(3));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token'           => $reportCsrf,
        'category_id'          => 1,
        'title'                => $repTitle,
        'description'          => 'Automated regression test description for Phase 6 validation',
        'submitted_severity'   => 'medium',
        'latitude'             => '21.1963',
        'longitude'            => '72.7958',
        'location_description' => 'Adajan Main Road'
    ]));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    curl_exec($ch);
    $submitCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $submitLoc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($submitCode !== 302 || !str_contains((string)$submitLoc, 'report_success.php')) {
        @unlink($jar);
        return "Report submission failed (HTTP {$submitCode}, Redirect: {$submitLoc})";
    }

    // Retrieve report from DB to get tracking token hash and clean up
    $findStmt = $pdo->prepare('SELECT id, report_reference, tracking_token_hash FROM incident_reports WHERE title = :title LIMIT 1');
    $findStmt->execute([':title' => $repTitle]);
    $createdReport = $findStmt->fetch();

    if (!$createdReport) {
        @unlink($jar);
        return "Created report not found in database";
    }

    // Verify candidate duplicate was automatically processed and created
    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM incident_duplicate_candidates WHERE report_id = :id');
    $dupCheck->execute([':id' => $createdReport['id']]);
    $dupCount = (int)$dupCheck->fetchColumn();

    // 3. Test Public Tracking Endpoint using Seed Token
    $trackCh = curl_init($baseUrl . '/api/v1/track.php?token=cstk_vesu_waterleak_7a9f2b1c4e6d');
    curl_setopt($trackCh, CURLOPT_RETURNTRANSFER, true);
    $trackJson = curl_exec($trackCh);
    $trackCode = curl_getinfo($trackCh, CURLINFO_HTTP_CODE);
    curl_close($trackCh);

    $trackData = json_decode((string)$trackJson, true);

    // Clean up created synthetic report & duplicate candidate
    $pdo->prepare('DELETE FROM incident_duplicate_candidates WHERE report_id = :id')->execute([':id' => $createdReport['id']]);
    $pdo->prepare('DELETE FROM incident_reports WHERE id = :id')->execute([':id' => $createdReport['id']]);
    @unlink($jar);

    if ($dupCount < 1) {
        return "Automatic post-submission duplicate processing did not create candidate";
    }

    if ($trackCode !== 200 || !isset($trackData['success']) || $trackData['success'] !== true) {
        return "Public tracking API failed for seed token (HTTP {$trackCode})";
    }

    return true;
});

echo "\n======================================================================\n";
echo "Test Suite Finished: {$passCount} Passed, {$failCount} Failed.\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
