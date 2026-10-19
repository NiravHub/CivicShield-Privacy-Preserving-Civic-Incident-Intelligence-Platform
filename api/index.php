<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

Response::ok('CivicShield API Gateway v1', [
    'name'        => APP_NAME,
    'environment' => APP_ENV,
    'version'     => '1.0.0',
    'endpoints'   => [
        'health' => BASE_URL . '/api/v1/health.php',
        'track'  => BASE_URL . '/api/v1/track.php'
    ]
]);
