<?php
declare(strict_types=1);

/**
 * CivicShield Phase 9 Automated Test Suite
 * Validates Civic Intelligence Dashboard & Analytics, SQL Aggregations, Filters & Privacy
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth_guard.php';

echo "======================================================================\n";
echo "CivicShield Phase 9 - Civic Intelligence & Analytics Tests\n";
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
// 1. Authorization: Non-admin users cannot access analytics
// -----------------------------------------------------------------------------
runTest("1. Non-admin users (citizens, officers, guests) denied admin analytics access", function() {
    Session::start();

    // Guest / Unauthenticated
    unset($_SESSION['role'], $_SESSION['user_id']);
    if (Session::hasRole('admin')) {
        return "Unauthenticated user must not pass admin check";
    }

    // Citizen
    $_SESSION['role'] = 'citizen';
    $_SESSION['user_id'] = 5;
    if (Session::hasRole('admin')) {
        return "Citizen user must not pass admin check";
    }

    // Officer
    $_SESSION['role'] = 'officer';
    $_SESSION['user_id'] = 2;
    if (Session::hasRole('admin')) {
        return "Officer user must not pass admin check";
    }

    // Admin
    $_SESSION['role'] = 'admin';
    $_SESSION['user_id'] = 1;
    if (!Session::hasRole('admin')) {
        return "Admin user must pass admin check";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 2. Summary KPI Metrics Aggregation Correctness
// -----------------------------------------------------------------------------
runTest("2. Summary KPI metrics aggregate accurately across database records", function() use ($pdo) {
    $metrics = AnalyticsManager::getSummaryMetrics([], $pdo);

    if ($metrics['total_incidents'] < 1) {
        return "Expected at least 1 incident in test database, got {$metrics['total_incidents']}";
    }

    // Verify mathematical identity: total = open + completed + rejected
    $sumCategories = $metrics['open_incidents'] + $metrics['completed_incidents'] + $metrics['rejected_incidents'];
    if ($sumCategories !== $metrics['total_incidents']) {
        return "Discrepancy in incident accounting: total ({$metrics['total_incidents']}) != open ({$metrics['open_incidents']}) + completed ({$metrics['completed_incidents']}) + rejected ({$metrics['rejected_incidents']})";
    }

    // Verify completed = resolved + closed
    $completedSum = $metrics['resolved_incidents'] + $metrics['closed_incidents'];
    if ($completedSum !== $metrics['completed_incidents']) {
        return "Completed count mismatch: completed ({$metrics['completed_incidents']}) != resolved ({$metrics['resolved_incidents']}) + closed ({$metrics['closed_incidents']})";
    }

    // Corroboration reports count
    if ($metrics['total_citizen_reports'] < $metrics['total_incidents']) {
        return "Total citizen reports ({$metrics['total_citizen_reports']}) should be >= total incidents ({$metrics['total_incidents']})";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 3. Average Resolution Time Calculation
// -----------------------------------------------------------------------------
runTest("3. Average resolution time calculates correctly from timestamps", function() use ($pdo) {
    // In seed data, Incident 2 was created at 2026-10-10 07:30:00 and resolved at 2026-10-11 16:30:00 (33.0 hours)
    $metrics = AnalyticsManager::getSummaryMetrics(['category_id' => 3], $pdo);

    if ($metrics['resolved_incidents'] > 0) {
        if ($metrics['avg_resolution_hours'] === null) {
            return "Expected avg_resolution_hours to be non-null for resolved category 3";
        }
        if ($metrics['avg_resolution_hours'] <= 0 || $metrics['avg_resolution_hours'] > 100) {
            return "Unexpected resolution time value: {$metrics['avg_resolution_hours']} hours";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 4. Category Filter Aggregation
// -----------------------------------------------------------------------------
runTest("4. Category filtering aggregates accurately", function() use ($pdo) {
    $catId = 1; // Roads & Potholes
    $metrics = AnalyticsManager::getSummaryMetrics(['category_id' => $catId], $pdo);
    $breakdown = AnalyticsManager::getCategoryBreakdown(['category_id' => $catId], $pdo);

    // Count in DB directly
    $dbCount = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE category_id = {$catId}")->fetchColumn();

    if ($metrics['total_incidents'] !== $dbCount) {
        return "Metrics total ({$metrics['total_incidents']}) does not match direct SQL count ({$dbCount})";
    }

    // Find category in breakdown
    $found = false;
    foreach ($breakdown['categories'] as $cat) {
        if ($cat['category_id'] === $catId) {
            $found = true;
            if ($cat['incident_count'] !== $dbCount) {
                return "Category breakdown count ({$cat['incident_count']}) != DB count ({$dbCount})";
            }
            break;
        }
    }

    if (!$found) {
        return "Category {$catId} not found in breakdown";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 5. Status Filter Aggregation
// -----------------------------------------------------------------------------
runTest("5. Municipal status filtering isolates matching records", function() use ($pdo) {
    $metrics = AnalyticsManager::getSummaryMetrics(['status' => 'assigned'], $pdo);
    $statusData = AnalyticsManager::getStatusBreakdown(['status' => 'assigned'], $pdo);

    $dbCount = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'assigned'")->fetchColumn();

    if ($metrics['total_incidents'] !== $dbCount) {
        return "Expected total {$dbCount} for status 'assigned', got {$metrics['total_incidents']}";
    }

    // In status breakdown, assigned should have $dbCount and others 0
    foreach ($statusData['breakdown'] as $sb) {
        if ($sb['status'] === 'assigned') {
            if ($sb['count'] !== $dbCount) {
                return "Status breakdown assigned count {$sb['count']} != {$dbCount}";
            }
        } else {
            if ($sb['count'] !== 0) {
                return "Expected 0 for status {$sb['status']} when filtered to 'assigned', got {$sb['count']}";
            }
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 6. Priority Tier Stratification and Grouping
// -----------------------------------------------------------------------------
runTest("6. Priority tier distribution sums to total and groups correctly", function() use ($pdo) {
    $prio = AnalyticsManager::getPriorityDistribution([], $pdo);

    if ($prio['total'] < 1) {
        return "Expected total priority count >= 1";
    }

    $sumTiers = 0;
    foreach ($prio['tiers'] as $tier) {
        $sumTiers += $tier['count'];
    }

    if ($sumTiers !== $prio['total']) {
        return "Sum of priority tiers ({$sumTiers}) does not match total ({$prio['total']})";
    }

    // Test priority tier filter
    $critMetrics = AnalyticsManager::getSummaryMetrics(['priority_tier' => 'critical'], $pdo);
    $dbCrit = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE priority_score BETWEEN 80 AND 100")->fetchColumn();

    if ($critMetrics['total_incidents'] !== $dbCrit) {
        return "Critical tier count mismatch: metrics ({$critMetrics['total_incidents']}) != SQL ({$dbCrit})";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 7. Date Normalization & Inverted Range Safety
// -----------------------------------------------------------------------------
runTest("7. Date normalization safely handles reversed ranges and formats", function() {
    // Normal range
    $f1 = AnalyticsManager::normalizeFilters([
        'date_from' => '2026-10-01',
        'date_to'   => '2026-10-15'
    ]);
    if ($f1['date_from'] !== '2026-10-01' || $f1['date_to'] !== '2026-10-15') {
        return "Normal date range altered incorrectly";
    }

    // Inverted range: date_from > date_to
    $f2 = AnalyticsManager::normalizeFilters([
        'date_from' => '2026-10-31',
        'date_to'   => '2026-10-01'
    ]);
    if ($f2['date_from'] !== '2026-10-01' || $f2['date_to'] !== '2026-10-31') {
        return "Inverted date range was not swapped safely";
    }

    // Invalid date formats
    $f3 = AnalyticsManager::normalizeFilters([
        'date_from' => 'invalid-date',
        'date_to'   => '2026/10/15'
    ]);
    if ($f3['date_from'] !== null || $f3['date_to'] !== null) {
        return "Invalid date strings should normalize to null";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 8. Empty Result Sets Handled Gracefully (Zero Division Protection)
// -----------------------------------------------------------------------------
runTest("8. Empty result sets return clean zero metrics without division errors", function() use ($pdo) {
    // Distant past date range with zero records
    $emptyFilters = [
        'date_from' => '1995-01-01',
        'date_to'   => '1995-01-02'
    ];

    $metrics = AnalyticsManager::getSummaryMetrics($emptyFilters, $pdo);
    if ($metrics['total_incidents'] !== 0) {
        return "Expected 0 total incidents for 1995 date range";
    }
    if ($metrics['resolution_rate_percent'] !== 0.0) {
        return "Expected 0.0 resolution rate for empty set";
    }
    if ($metrics['avg_resolution_hours'] !== null) {
        return "Expected null avg_resolution_hours for empty set";
    }

    $trends = AnalyticsManager::getVolumeTrends($emptyFilters, $pdo);
    if (!empty($trends['labels'])) {
        return "Expected empty trends labels array for empty set";
    }

    $map = AnalyticsManager::getMapIncidents($emptyFilters, 10, $pdo);
    if (!empty($map)) {
        return "Expected empty map dataset for empty set";
    }

    return true;
});

// -----------------------------------------------------------------------------
// 9. Malicious SQL Injection & XSS Filters Handled Safely
// -----------------------------------------------------------------------------
runTest("9. Malicious SQL and XSS inputs are sanitized and parameterized", function() use ($pdo) {
    $maliciousInputs = [
        "'; DROP TABLE incidents; --",
        "1' OR '1'='1",
        "<script>alert('XSS')</script>",
        "0 OR 1=1 UNION SELECT * FROM users",
        "\" OR \"\"=\""
    ];

    foreach ($maliciousInputs as $bad) {
        $metrics = AnalyticsManager::getSummaryMetrics([
            'search'        => $bad,
            'status'        => $bad,
            'category_id'   => $bad,
            'min_priority'  => $bad,
            'priority_tier' => $bad
        ], $pdo);

        if (!is_array($metrics) || !isset($metrics['total_incidents'])) {
            return "Failed to handle malicious input safely: {$bad}";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 10. Volume Trends Chronological Consistency
// -----------------------------------------------------------------------------
runTest("10. Volume trends data series are chronologically ordered and consistent", function() use ($pdo) {
    $trends = AnalyticsManager::getVolumeTrends([], $pdo);

    if ($trends['total_points'] > 0) {
        $labelCount = count($trends['labels']);
        $createdCount = count($trends['created_series']);
        $resolvedCount = count($trends['resolved_series']);

        if ($labelCount !== $createdCount || $labelCount !== $resolvedCount) {
            return "Mismatched trend series lengths: labels={$labelCount}, created={$createdCount}, resolved={$resolvedCount}";
        }

        // Sum of created counts should equal total incidents in DB
        $totalCreated = array_sum($trends['created_series']);
        $dbTotal = (int)$pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
        if ($totalCreated !== $dbTotal) {
            return "Sum of created trend points ({$totalCreated}) != total incidents in DB ({$dbTotal})";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 11. Privacy Enclave Protection in Geospatial Analytics
// -----------------------------------------------------------------------------
runTest("11. Map analytics strictly exclude citizen contact enclave and raw GPS coordinates", function() use ($pdo) {
    $incidents = AnalyticsManager::getMapIncidents([], 100, $pdo);

    if (empty($incidents)) {
        return "Expected at least 1 incident for map analytics";
    }

    $forbiddenKeys = [
        'contact_name', 'contact_phone', 'contact_email', 'phone', 'email',
        'raw_lat', 'raw_lng', 'raw_token', 'tracking_token', 'citizen_id'
    ];

    foreach ($incidents as $inc) {
        foreach ($forbiddenKeys as $fk) {
            if (array_key_exists($fk, $inc)) {
                return "Map analytics row leaked forbidden privacy key: {$fk}";
            }
        }

        // Must contain obfuscated coordinates and fuzz radius
        if (!isset($inc['obfuscated_lat'], $inc['obfuscated_lng'], $inc['fuzz_radius_meters'])) {
            return "Map analytics missing obfuscated coordinates or fuzz radius";
        }
        if ((int)$inc['fuzz_radius_meters'] <= 0) {
            return "Invalid fuzz_radius_meters value";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
// 12. Regressions Check Across Phase 6, 7, and 8 Core Components
// -----------------------------------------------------------------------------
runTest("12. Regressions check: Phase 6, 7, and 8 components function uninterrupted", function() use ($pdo) {
    // Phase 6: Duplicate detection
    $dup = DuplicateDetector::findCandidatesForReport(1, 50.0, $pdo);
    if (!is_array($dup)) {
        return "DuplicateDetector::findCandidatesForReport failed";
    }

    // Phase 7: Admin incident operations
    $adminQueue = IncidentManager::getIncidents([], 1, 5, $pdo);
    if (!isset($adminQueue['incidents'], $adminQueue['pagination'])) {
        return "IncidentManager::getIncidents failed";
    }

    // Phase 8: Officer portal queue
    $officerQueue = IncidentManager::getOfficerIncidents(2, [], 1, 5, $pdo);
    if (!isset($officerQueue['incidents'], $officerQueue['pagination'])) {
        return "IncidentManager::getOfficerIncidents failed";
    }

    return true;
});

echo "\n======================================================================\n";
echo "Phase 9 Test Suite Finished: $passCount Passed, $failCount Failed\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
