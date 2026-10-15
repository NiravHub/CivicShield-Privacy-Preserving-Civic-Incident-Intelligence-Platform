<?php
declare(strict_types=1);

/**
 * CivicShield - Standardized JSON Response & API Helper
 */
class Response
{
    /**
     * Send a standardized JSON response and terminate execution.
     *
     * @param bool $success Operation status
     * @param string $message User-friendly status message
     * @param mixed $data Payload data
     * @param array $errors List of validation or operational errors
     * @param int $statusCode HTTP status code (default 200)
     */
    public static function json(
        bool $success,
        string $message = '',
        mixed $data = null,
        array $errors = [],
        int $statusCode = 200
    ): void {
        // Clear any previous output buffers if any
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');

        $response = [
            'success'   => $success,
            'message'   => $message,
            'data'      => $data,
            'errors'    => $errors,
            'timestamp' => date('c')
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Return 200 OK
     */
    public static function ok(string $message = 'Success', mixed $data = null): void
    {
        self::json(true, $message, $data, [], 200);
    }

    /**
     * Return 201 Created
     */
    public static function created(string $message = 'Resource created successfully', mixed $data = null): void
    {
        self::json(true, $message, $data, [], 201);
    }

    /**
     * Return 400 Bad Request
     */
    public static function badRequest(string $message = 'Bad Request', array $errors = []): void
    {
        self::json(false, $message, null, $errors, 400);
    }

    /**
     * Return 401 Unauthorized
     */
    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::json(false, $message, null, [], 401);
    }

    /**
     * Return 403 Forbidden
     */
    public static function forbidden(string $message = 'Access forbidden: Insufficient permissions'): void
    {
        self::json(false, $message, null, [], 403);
    }

    /**
     * Return 404 Not Found
     */
    public static function notFound(string $message = 'Requested resource not found'): void
    {
        self::json(false, $message, null, [], 404);
    }

    /**
     * Return 422 Unprocessable Entity (e.g. Validation Errors)
     */
    public static function unprocessable(string $message = 'Validation failed', array $errors = []): void
    {
        self::json(false, $message, null, $errors, 422);
    }

    /**
     * Return 500 Internal Server Error
     */
    public static function serverError(string $message = 'Internal server error occurred', array $errors = []): void
    {
        self::json(false, $message, null, $errors, 500);
    }

    /**
     * Determine if current request expects JSON or was dispatched via AJAX.
     */
    public static function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return str_contains($accept, 'application/json')
            || strtolower($requestedWith) === 'xmlhttprequest'
            || str_contains($uri, '/api/');
    }
}
