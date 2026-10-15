<?php
declare(strict_types=1);

/**
 * CivicShield - Centralized Error and Exception Handling
 */
class ErrorHandler
{
    private static string $logFile = '';
    private static bool $debug = false;

    /**
     * Register global PHP error and exception handlers.
     */
    public static function register(string $logFilePath, bool $debugMode = false): void
    {
        self::$logFile = $logFilePath;
        self::$debug = $debugMode;

        // Ensure logs directory exists
        $dir = dirname($logFilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        error_reporting(E_ALL);
        ini_set('display_errors', $debugMode ? '1' : '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Convert PHP standard errors to ErrorException.
     */
    public static function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $level)) {
            // Error is suppressed with @ operator
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Centralized uncaught exception handler.
     */
    public static function handleException(Throwable $exception): void
    {
        $logMessage = sprintf(
            "[%s] [%s] %s in %s on line %d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        if (!empty(self::$logFile)) {
            @error_log($logMessage, 3, self::$logFile);
        }

        // Determine if response should be JSON or HTML
        if (class_exists('Response') && Response::isJsonRequest()) {
            $debugDetails = self::$debug ? [
                'type'  => get_class($exception),
                'error' => $exception->getMessage(),
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine()
            ] : [];

            Response::serverError(
                self::$debug ? $exception->getMessage() : 'An unexpected system error occurred.',
                $debugDetails
            );
            return;
        }

        // Render HTML error page
        http_response_code(500);
        if (self::$debug) {
            echo '<!DOCTYPE html><html><head><title>System Error - CivicShield</title>';
            echo '<style>body{font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;background:#f8fafc;color:#1e293b;padding:2rem;}';
            echo '.card{background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:2rem;max-width:900px;margin:auto;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);}';
            echo 'h1{color:#dc2626;font-size:1.5rem;margin-top:0;} pre{background:#0f172a;color:#f8fafc;padding:1rem;border-radius:6px;overflow:auto;font-size:0.875rem;}</style></head><body>';
            echo '<div class="card">';
            echo '<h1>CivicShield Debug Error</h1>';
            echo '<p><strong>Exception:</strong> ' . htmlspecialchars(get_class($exception)) . '</p>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
            echo '<p><strong>Location:</strong> ' . htmlspecialchars($exception->getFile()) . ':' . $exception->getLine() . '</p>';
            echo '<h3>Stack Trace</h3><pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
            echo '</div></body></html>';
        } else {
            echo '<!DOCTYPE html><html><head><title>500 Internal Error - CivicShield</title>';
            echo '<style>body{font-family:Segoe UI,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}';
            echo '.box{text-align:center;padding:2rem;} h1{font-size:3rem;margin:0 0 1rem;color:#38bdf8;} p{color:#94a3b8;font-size:1.1rem;}</style></head><body>';
            echo '<div class="box"><h1>500</h1><p>A server error occurred. Our team has been notified.</p></div></body></html>';
        }
        exit;
    }

    /**
     * Intercept fatal script termination.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $exception = new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            self::handleException($exception);
        }
    }
}
