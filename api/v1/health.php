<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

$dbHealth = Database::checkHealth();

$payload = [
    'status'       => $dbHealth['connected'] ? 'healthy' : 'degraded',
    'timestamp'    => date('c'),
    'environment'  => APP_ENV,
    'debug_mode'   => APP_DEBUG,
    'php_version'  => PHP_VERSION,
    'database'     => $dbHealth,
    'server_time'  => date('Y-m-d H:i:s T'),
    'capabilities' => [
        'pdo_mysql'        => extension_loaded('pdo_mysql'),
        'openssl'          => extension_loaded('openssl'),
        'session_active'   => session_status() === PHP_SESSION_ACTIVE,
        'uploads_writable' => is_writable(UPLOADS_PATH),
        'logs_writable'    => is_writable(LOGS_PATH)
    ]
];

if (!$dbHealth['connected']) {
    Response::json(
        false,
        'System health check reported database connectivity issues.',
        $payload,
        [$dbHealth['message']],
        503
    );
}

Response::ok('CivicShield platform is healthy and ready.', $payload);
