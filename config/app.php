<?php
declare(strict_types=1);

/**
 * CivicShield Platform Bootstrap & Core Configuration
 * Compatible with XAMPP on Windows and standard LAMP/LEMP stacks.
 */

// Define directory constants
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'config');
define('CORE_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'core');
define('INCLUDES_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'includes');
define('UPLOADS_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads');
define('LOGS_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'logs');

// Autoload core classes
spl_autoload_register(function (string $class): void {
    $file = CORE_PATH . DIRECTORY_SEPARATOR . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load environment variables if .env exists
$envFile = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

// App Settings
$appName  = Env::get('APP_NAME', 'CivicShield Platform');
$appEnv   = Env::get('APP_ENV', 'development');
$appDebug = filter_var(Env::get('APP_DEBUG', true), FILTER_VALIDATE_BOOLEAN);

define('APP_NAME', $appName);
define('APP_ENV', $appEnv);
define('APP_DEBUG', $appDebug);

// Register Global Error & Exception Handlers
ErrorHandler::register(LOGS_PATH . DIRECTORY_SEPARATOR . 'app.log', APP_DEBUG);

// Detect Dynamic Base URL (supports XAMPP subdirectories and standalone servers)
if (!defined('BASE_URL')) {
    $configuredUrl = Env::get('APP_URL');
    if (!empty($configuredUrl)) {
        define('BASE_URL', rtrim((string)$configuredUrl, '/'));
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        // Find project root relative to current script
        $rootSegment = trim(str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', str_replace('\\', '/', ROOT_PATH)), '/');
        $baseUrl = $protocol . $host . ($rootSegment ? '/' . $rootSegment : '');
        define('BASE_URL', rtrim($baseUrl, '/'));
    }
}

// Initialize session
Session::start();
