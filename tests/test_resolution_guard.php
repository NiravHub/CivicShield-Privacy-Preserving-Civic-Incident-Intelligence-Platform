<?php
declare(strict_types=1);

/**
 * Regression tests for the resolution‑evidence guard (hasApprovedEvidence).
 */

require_once dirname(__DIR__) . '/config/app.php';

$pdo = Database::getConnection();

function runTest(string $name, callable $fn): void {
    echo "[TEST] {$name}: ";
    try {
        $result = $fn();
        if ($result === true) {
            echo "PASSED\n";
        } else {
            echo "FAILED ({$result})\n";
        }
    } catch (Throwable $e) {
        echo "FAILED with Exception: " . $e->getMessage() . "\n";
    }
}

/**
 * Helper to create a synthetic incident and assign it to an officer.
 * Returns the incident ID.
 */
function createAndAssignIncident(PDO $pdo, int $officerId, int $adminId): int {
    // create incident in reported state
    $ref = 'TEST-INC-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare(
        "INSERT INTO incidents (incident_reference, category_id, title, description, severity, priority_score, status, obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at) VALUES (:ref, 1, :title, :desc, :sev, 50, :status, 21.196000, 72.795000, 150, 'Adajan', 'Surat', 1, NOW(), NOW())"
    );
    $stmt->execute([
        ':ref' => $ref,
        ':title' => 'Synthetic incident for guard test',
        ':desc' => 'Testing resolution guard',
        ':sev' => 'medium',
        ':status' => 'reported',
    ]);
    $incId = (int) $pdo->lastInsertId();

    // assign to officer (admin does the assignment)
    $assign = IncidentManager::assignIncident($incId, $officerId, $adminId, 'Assign for test', $pdo);
    if (!$assign['success']) {
        throw new RuntimeException('Assignment failed: ' . $assign['message']);
    }

    // move incident to in_progress so evidence can be submitted
    $upd = IncidentManager::updateStatus($incId, 'in_progress', $adminId, 'Start progress', $pdo);
    if (!$upd['success']) {
        throw new RuntimeException('Failed to set in_progress: ' . $upd['message']);
    }
    return $incId;
}

// -----------------------------------------------------------------------------
// 1. No evidence – cannot resolve (should be blocked by lifecycle validator)
// -----------------------------------------------------------------------------
runTest('No evidence – lifecycle blocks resolve', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createAndAssignIncident($pdo, 2, 1);
        $res = IncidentManager::updateStatus($incId, 'resolved', 1, 'Attempt resolve without evidence', $pdo);
        $pdo->rollBack();
        if ($res['success']) {
            return 'Resolution succeeded unexpectedly';
        }
        // Expect lifecycle rejection
        if (!str_contains($res['message'], 'Invalid transition')) {
            return 'Unexpected error message: ' . $res['message'];
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 2. Pending evidence – cannot resolve (guard blocks)
// -----------------------------------------------------------------------------
runTest('Pending evidence – guard blocks resolve', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createAndAssignIncident($pdo, 2, 1);
        // create temporary image
        $tmp = tempnam(sys_get_temp_dir(), 'cs_test_img_');
        $im = imagecreatetruecolor(10,10);
        $col = imagecolorallocate($im, 255,0,0);
        imagefill($im,0,0,$col);
        imagepng($im,$tmp);
        imagedestroy($im);
        $evidenceFile = [
            'name' => 'e.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
        $sub = IncidentManager::submitResolutionEvidence($incId, 2, $evidenceFile, 'Valid pending notes', null, null, $pdo);
        @unlink($tmp);
        if (!$sub['success']) {
            $pdo->rollBack();
            return 'Evidence submission failed: ' . $sub['message'];
        }
        // attempt resolution before approval
        $res = IncidentManager::updateStatus($incId, 'resolved', 1, 'Attempt resolve with pending evidence', $pdo);
        $pdo->rollBack();
        if ($res['success']) {
            return 'Resolution succeeded unexpectedly';
        }
        if (!str_contains($res['message'], "only be marked")) {
            return 'Unexpected error message: ' . $res['message'];
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 3. Rejected (request rework) evidence – cannot resolve
// -----------------------------------------------------------------------------
runTest('Rejected evidence – guard still blocks resolve', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createAndAssignIncident($pdo, 2, 1);
        $tmp = tempnam(sys_get_temp_dir(), 'cs_test_img_');
        $im = imagecreatetruecolor(10,10);
        $col = imagecolorallocate($im, 0,255,0);
        imagefill($im,0,0,$col);
        imagepng($im,$tmp);
        imagedestroy($im);
        $evidenceFile = [
            'name' => 'e2.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
        $sub = IncidentManager::submitResolutionEvidence($incId, 2, $evidenceFile, 'Valid notes for reject', null, null, $pdo);
        @unlink($tmp);
        if (!$sub['success']) {
            $pdo->rollBack();
            return 'Evidence submission failed: ' . $sub['message'];
        }
        // admin requests rework
        $rev = IncidentManager::reviewResolutionEvidence($incId, 1, 'request_rework', 'Needs rework', $pdo);
        if (!$rev['success']) {
            $pdo->rollBack();
            return 'Rework request failed: ' . $rev['message'];
        }
        // incident should now be back in_progress
        $check = IncidentManager::updateStatus($incId, 'resolved', 1, 'Attempt resolve after rework', $pdo);
        $pdo->rollBack();
        if ($check['success']) {
            return 'Resolution succeeded unexpectedly after rework';
        }
        // Expect lifecycle rejection (still in_progress)
        if (!str_contains($check['message'], 'Invalid transition')) {
            return 'Unexpected error after rework: ' . $check['message'];
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});

// -----------------------------------------------------------------------------
// 4. Approved evidence – successful resolution
// -----------------------------------------------------------------------------
runTest('Approved evidence – resolves successfully', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $incId = createAndAssignIncident($pdo, 2, 1);
        $tmp = tempnam(sys_get_temp_dir(), 'cs_test_img_');
        $im = imagecreatetruecolor(10,10);
        $col = imagecolorallocate($im, 0,0,255);
        imagefill($im,0,0,$col);
        imagepng($im,$tmp);
        imagedestroy($im);
        $evidenceFile = [
            'name' => 'e3.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];
        $sub = IncidentManager::submitResolutionEvidence($incId, 2, $evidenceFile, 'Valid notes for approve', null, null, $pdo);
        @unlink($tmp);
        if (!$sub['success']) {
            $pdo->rollBack();
            return 'Evidence submission failed: ' . $sub['message'];
        }
        $rev = IncidentManager::reviewResolutionEvidence($incId, 1, 'approve', 'All good', $pdo);
        if (!$rev['success']) {
            $pdo->rollBack();
            return 'Approval failed: ' . $rev['message'];
        }
        // After approval, status should be 'resolved'
        $stmt = $pdo->prepare('SELECT status FROM incidents WHERE id = :id');
        $stmt->execute([':id' => $incId]);
        $row = $stmt->fetch();
        $pdo->rollBack();
        if ($row['status'] !== 'resolved') {
            return 'Incident status not resolved after approval: ' . $row['status'];
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
?>