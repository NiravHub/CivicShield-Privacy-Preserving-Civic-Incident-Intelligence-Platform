<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

// Perform a lightweight database health check.
$dbHealth = Database::checkHealth();

// Minimal payload – always returned.
$payload = [
    'status'    => $dbHealth['connected'] ? 'healthy' : 'degraded',
    'timestamp' => date('c'),
];

// If the database is not reachable, log the error server‑side and respond with a 503.
if (!$dbHealth['connected']) {
    error_log('Health check DB connection failure: ' . ($dbHealth['message'] ?? 'unknown'));
    Response::json(
        false,
        'System health check reported database connectivity issues.',
        $payload,
        [],
        503
    );
    exit;
}

// Normal healthy response – no diagnostic details are exposed publicly.
Response::ok('CivicShield platform is healthy and ready.', $payload);
