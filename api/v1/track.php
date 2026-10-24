<?php
declare(strict_types=1);

/**
 * CivicShield API - Incident Report Tracking by Private Token
 * Securely hashes the provided token and returns authorized public lifecycle status.
 * Never logs raw tokens, never leaks citizen contact details or raw coordinates.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    // Method Not Allowed – only POST is accepted for token lookup.
    http_response_code(405);
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    Response::json(false, 'Method Not Allowed. Use POST.', null, [], 405);
    exit;
}


// Rate limiting check by client IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$throttleKey = 'track_' . md5($clientIp);
[$isThrottled, $retryAfter] = Session::isLoginThrottled($throttleKey, 20, 60);
if ($isThrottled) {
    Response::json(false, sprintf('Too many lookup requests. Please wait %d seconds.', $retryAfter), null, [], 429);
}

// Retrieve token from JSON body, standard form POST, or GET query parameter
$rawToken = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $input = json_decode((string)file_get_contents('php://input'), true);
        $rawToken = trim((string)($input['token'] ?? ''));
    } else {
        $rawToken = trim((string)($_POST['token'] ?? ''));
    }
}
// GET token fallback removed – token must be provided via POST body only.


if ($rawToken === '') {
    Response::badRequest('Please provide a valid tracking token.');
}

// Compute one-way cryptographic SHA-256 digest
$tokenHash = hash('sha256', $rawToken);

try {
    $pdo = Database::getConnection();

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
        // Record failed lookup for rate limiting
        Session::recordFailedLogin($throttleKey);
        Response::notFound('No incident report was found matching this tracking token. Please verify the token string and try again.');
    }

    // Determine current effective public status and description
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

    if ($consolidatedStatus === 'resolved') {
        $statusLabel = 'Verified Resolved';
        $effectiveStatus = 'resolved';
    } elseif ($consolidatedStatus === 'closed') {
        $statusLabel = 'Closed (Remediation Complete)';
        $effectiveStatus = 'closed';
    } elseif ($consolidatedStatus === 'resolution_submitted') {
        $statusLabel = 'Field Remediation Completed — Pending Verification';
    }

    // Build strictly sanitized public payload (Zero PII, no coordinates, no token hashes)
    $safePayload = [
        'report_reference'      => $report['report_reference'],
        'title'                 => $report['title'],
        'category'              => $report['category_name'],
        'department'            => $report['department_code'],
        'submitted_severity'    => $report['submitted_severity'],
        'status'                => $effectiveStatus,
        'status_label'          => $statusLabel,
        'submission_date'       => date('d M Y, h:i A', strtotime($report['created_at'])),
        'neighborhood'          => $report['neighborhood'] ?? 'Municipal Jurisdiction (Surat)',
        'target_sla_hours'      => (int)$report['resolution_sla_hours'],
        'incident_reference'    => $report['incident_reference'] ?? null,
        'resolved_date'         => !empty($report['resolved_at']) ? date('d M Y, h:i A', strtotime($report['resolved_at'])) : null,
        'privacy_guarantee'     => 'Citizen identity, raw GPS points, and contact records are excluded from this public response.'
    ];

    Response::ok('Incident report status retrieved successfully.', $safePayload);

} catch (Throwable $e) {
    @error_log('[Tracking API Error] ' . $e->getMessage());
    Response::serverError('An unexpected database error occurred during status lookup.');
}
