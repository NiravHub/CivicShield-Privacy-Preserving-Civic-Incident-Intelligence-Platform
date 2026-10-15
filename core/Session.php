<?php
declare(strict_types=1);

/**
 * CivicShield - Secure Session & CSRF Manager
 * Conforms to OWASP session management guidelines:
 * - Strict cookie parameters (HttpOnly, SameSite=Lax, dynamic Secure flag)
 * - Session ID regeneration upon privilege escalation
 * - Cryptographically random CSRF tokens with constant-time verification
 * - Inactivity session expiration
 * - Client login rate-limiting / throttling helpers
 */
class Session
{
    private static bool $started = false;

    /**
     * Start secure PHP session with strict cookie settings and inactivity checks.
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            self::checkInactivity();
            return;
        }

        $lifetime = (int) Env::get('SESSION_LIFETIME', 7200);

        // Auto-detect HTTPS or respect environment override
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || filter_var(Env::get('SESSION_SECURE_COOKIE', false), FILTER_VALIDATE_BOOLEAN);

        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        self::$started = true;

        // Auto-generate CSRF token if missing
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        self::checkInactivity();
    }

    /**
     * Enforce inactivity session expiration.
     */
    private static function checkInactivity(): void
    {
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['last_activity'])) {
            $lifetime = (int) Env::get('SESSION_LIFETIME', 7200);
            if ((time() - (int)$_SESSION['last_activity']) > $lifetime) {
                self::destroy();
                self::start();
                self::setFlash('error', 'Your session has expired due to inactivity. Please sign in again.');
                return;
            }
            $_SESSION['last_activity'] = time();
        }
    }

    /**
     * Regenerate session ID and CSRF token (called upon login or privilege changes).
     */
    public static function regenerate(): void
    {
        self::start();
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Retrieve CSRF token for forms and AJAX headers.
     */
    public static function getCsrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate submitted CSRF token using constant-time hash comparison.
     */
    public static function validateCsrf(?string $token): bool
    {
        self::start();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Store a flash message for the next page request.
     */
    public static function setFlash(string $type, string $message): void
    {
        self::start();
        $_SESSION['flash'][$type] = $message;
    }

    /**
     * Retrieve and remove a flash message.
     */
    public static function getFlash(string $type): ?string
    {
        self::start();
        if (isset($_SESSION['flash'][$type])) {
            $msg = $_SESSION['flash'][$type];
            unset($_SESSION['flash'][$type]);
            return $msg;
        }
        return null;
    }

    /**
     * Authenticate and set user session identity with minimum required claims.
     */
    public static function loginUser(array $user): void
    {
        self::start();
        // Prevent session fixation by issuing a new session ID and CSRF token
        self::regenerate();

        $_SESSION['user_id'] = (int)($user['id'] ?? 0);
        $_SESSION['username'] = (string)($user['username'] ?? '');
        $_SESSION['role'] = (string)($user['role'] ?? 'citizen'); // citizen | officer | admin
        $_SESSION['department'] = $user['department'] ?? null;
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Check if current visitor is authenticated.
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get user ID or null.
     */
    public static function getUserId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Get user role.
     */
    public static function getRole(): string
    {
        self::start();
        return $_SESSION['role'] ?? 'guest';
    }

    /**
     * Check if user matches required role.
     */
    public static function hasRole(string|array $roles): bool
    {
        $current = self::getRole();
        if (is_array($roles)) {
            return in_array($current, $roles, true);
        }
        return $current === $roles;
    }

    /**
     * Login Throttling Helper: Check if identifier has exceeded maximum failed attempts.
     *
     * @param string $identifier IP address or username hash
     * @param int $maxAttempts Maximum allowed failures (default 5)
     * @param int $decaySeconds Window duration (default 300s = 5 minutes)
     * @return array [bool $isThrottled, int $remainingSeconds]
     */
    public static function isLoginThrottled(string $identifier, int $maxAttempts = 5, int $decaySeconds = 300): array
    {
        self::start();
        $attemptsKey = 'login_attempts_' . md5($identifier);
        $attempts = $_SESSION[$attemptsKey] ?? [];

        $currentTime = time();
        // Filter attempts within decay window
        $recentAttempts = array_filter($attempts, fn($t) => ($currentTime - $t) < $decaySeconds);
        $_SESSION[$attemptsKey] = $recentAttempts;

        if (count($recentAttempts) >= $maxAttempts) {
            $oldestAttempt = min($recentAttempts);
            $remaining = $decaySeconds - ($currentTime - $oldestAttempt);
            return [true, max(1, $remaining)];
        }

        return [false, 0];
    }

    /**
     * Login Throttling Helper: Record a failed login attempt timestamp.
     */
    public static function recordFailedLogin(string $identifier): void
    {
        self::start();
        $attemptsKey = 'login_attempts_' . md5($identifier);
        $attempts = $_SESSION[$attemptsKey] ?? [];
        $attempts[] = time();
        $_SESSION[$attemptsKey] = $attempts;
    }

    /**
     * Login Throttling Helper: Clear failed login attempts upon successful login.
     */
    public static function clearFailedLogins(string $identifier): void
    {
        self::start();
        $attemptsKey = 'login_attempts_' . md5($identifier);
        unset($_SESSION[$attemptsKey]);
    }

    /**
     * Sanitize redirect target to strictly internal relative paths, preventing open redirects.
     */
    public static function sanitizeRedirect(?string $url, string $fallback = '/'): string
    {
        if (empty($url)) {
            return $fallback;
        }

        $url = trim($url);

        // Allow relative internal paths starting with /
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\')) {
            return $url;
        }

        // Allow internal paths matching BASE_URL
        if (defined('BASE_URL') && str_starts_with($url, BASE_URL)) {
            return $url;
        }

        return $fallback;
    }

    /**
     * Get home dashboard URL for a given role.
     */
    public static function getRoleHomeUrl(string $role): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        return match ($role) {
            'admin'   => $base . '/admin/',
            'officer' => $base . '/officer/',
            default   => $base . '/citizen/',
        };
    }

    /**
     * Destroy user session securely and clear session cookies.
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }
}
