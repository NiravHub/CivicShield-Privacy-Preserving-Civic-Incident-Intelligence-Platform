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
    /**
     * Sanitize a userâ€‘supplied redirect target.
     *
     * Accepts a raw string (from GET or POST) and returns a safe URL
     * that stays within the same application.  If the value cannot be
     * trusted, the method falls back to the application root (BASE_URL).
     *
     * The logic mirrors common openâ€‘redirect protections:
     *   â€¢ Reject empty strings and control characters.
     *   â€¢ Disallow protocolâ€‘relative URLs ("//evil.com").
     *   â€¢ Disallow backâ€‘slash starts ("\\evil.com").
     *   â€¢ Block dangerous schemes such as "javascript:".
     *   â€¢ For absolute URLs, require http/https and a host that matches
     *     the current BASE_URL host.
     *   â€¢ For relative paths, prepend BASE_URL so redirects work from
     *     installations that live under a subâ€‘directory.
     */
    public static function sanitizeRedirect(string $url): string
    {
        // Default fallback â€“ the root of the current installation.
        $fallback = rtrim(BASE_URL, '/') . '/';

        $trim = trim($url);
        if ($trim === '') {
            return $fallback;
        }

        // Reject control characters (0â€‘31, 127).
        if (preg_match('/[\x00-\x1F\x7F]/', $trim)) {
            return $fallback;
        }

        // Disallow protocolâ€‘relative URLs ("//example.com") and backâ€‘slash prefixes.
        if (strpos($trim, '//') === 0 || strpos($trim, '\\') === 0) {
            return $fallback;
        }

        // Disallow dangerous URI schemes.
        $lower = strtolower($trim);
        if (preg_match('#^(javascript|data|vbscript):#', $lower)) {
            return $fallback;
        }

        // If the string starts with '/' treat it as an internal path.
        if (strpos($trim, '/') === 0) {
            // Prevent "//" after the leading slash (e.g., "/\/\/evil.com").
            if (strpos($trim, '//', 1) !== false) {
                return $fallback;
            }
            // Build absolute URL based on BASE_URL (handles subâ€‘directory installs).
            return rtrim(BASE_URL, '/') . $trim;
        }

        // Otherwise attempt to parse as an absolute URL.
        $parts = @parse_url($trim);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $fallback;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $fallback;
        }
        // Host must match the current application's host.
        $baseHost = parse_url(BASE_URL, PHP_URL_HOST);
        if (isset($parts['host']) && strtolower($parts['host']) !== strtolower($baseHost)) {
            return $fallback;
        }
        // Reconstruct path, query and fragment.
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $path . $query . $fragment;
    }

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
     * Retrieve trusted client IP address from server environment.
     * Rejects untrusted forwarding headers to prevent IP spoofing in rate-limiting checks.
     * In proxy architectures, header rewriting must occur via trusted web server modules (e.g. Apache mod_remoteip).
     */
    public static function getClientIp(): string
    {
        $rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip = trim((string)$rawIp);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '127.0.0.1';
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
     * Login Throttling Helper: Determine if client identifier has exceeded rate limits.
     * Enforces persistent database-backed rate limiting surviving session clears and cookie resets.
     * Evaluates both targeted identifier attempts (default max 5 in 300s) and source IP abuse (max 20 in 900s).
     * Fails safely to session storage if database connectivity is unavailable.
     *
     * @param string $identifier Unique client or account key (e.g. "IP_username" or key hash)
     * @param int $maxAttempts Maximum allowed failures within window (default 5)
     * @param int $decaySeconds Rolling window duration in seconds (default 300s = 5 minutes)
     * @return array [bool $isThrottled, int $remainingSeconds]
     */
    public static function isLoginThrottled(string $identifier, int $maxAttempts = 5, int $decaySeconds = 300): array
    {
        self::start();
        $currentTime = time();
        $identifier = trim($identifier);
        $rateKey = hash('sha256', strtolower($identifier));

        // 1. Attempt Database-Backed Persistent Throttling Check
        try {
            $pdo = Database::getConnection();

            // Extract client IP if present in identifier, or fallback to validated client IP
            $ip = null;
            if (str_contains($identifier, '_')) {
                $parts = explode('_', $identifier, 2);
                if (filter_var($parts[0], FILTER_VALIDATE_IP)) {
                    $ip = $parts[0];
                }
            }
            if ($ip === null) {
                $ip = self::getClientIp();
            }

            // Check A: Source-wide IP abuse mitigation (max 20 failed attempts across all accounts in 15 minutes)
            if ($ip !== null) {
                $ipWindowSeconds = 900;
                $maxIpAttempts = 20;
                $ipWindowStart = $currentTime - $ipWindowSeconds;

                // Bounded count query leveraging idx_rate_ip_time
                $ipCountStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM login_rate_limits
                     WHERE ip_address = :ip AND attempted_at >= FROM_UNIXTIME(:window_start)'
                );
                $ipCountStmt->execute([
                    ':ip'           => $ip,
                    ':window_start' => $ipWindowStart,
                ]);
                $ipCount = (int)$ipCountStmt->fetchColumn();

                if ($ipCount >= $maxIpAttempts) {
                    // Fetch only the single threshold boundary attempt (O(1) memory)
                    $offset = (int)($maxIpAttempts - 1);
                    $ipTimeStmt = $pdo->prepare(
                        'SELECT UNIX_TIMESTAMP(attempted_at) AS attempt_time
                         FROM login_rate_limits
                         WHERE ip_address = :ip AND attempted_at >= FROM_UNIXTIME(:window_start)
                         ORDER BY attempted_at DESC
                         LIMIT 1 OFFSET ' . $offset
                    );
                    $ipTimeStmt->execute([
                        ':ip'           => $ip,
                        ':window_start' => $ipWindowStart,
                    ]);
                    $thresholdIp = (int)$ipTimeStmt->fetchColumn();
                    $ipRemaining = $thresholdIp > 0 ? ($ipWindowSeconds - ($currentTime - $thresholdIp)) : $ipWindowSeconds;
                    return [true, max(1, $ipRemaining)];
                }
            }

            // Check B: Identifier-specific persistent throttling (IP + Account pair)
            $windowStart = $currentTime - $decaySeconds;
            // Bounded count query leveraging idx_rate_key_time
            $rateCountStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_rate_limits
                 WHERE rate_key = :rate_key AND attempted_at >= FROM_UNIXTIME(:window_start)'
            );
            $rateCountStmt->execute([
                ':rate_key'     => $rateKey,
                ':window_start' => $windowStart,
            ]);
            $rateCount = (int)$rateCountStmt->fetchColumn();

            if ($rateCount >= $maxAttempts) {
                // Fetch only the single threshold boundary attempt (O(1) memory)
                $offset = (int)($maxAttempts - 1);
                $rateTimeStmt = $pdo->prepare(
                    'SELECT UNIX_TIMESTAMP(attempted_at) AS attempt_time
                     FROM login_rate_limits
                     WHERE rate_key = :rate_key AND attempted_at >= FROM_UNIXTIME(:window_start)
                     ORDER BY attempted_at DESC
                     LIMIT 1 OFFSET ' . $offset
                );
                $rateTimeStmt->execute([
                    ':rate_key'     => $rateKey,
                    ':window_start' => $windowStart,
                ]);
                $thresholdRate = (int)$rateTimeStmt->fetchColumn();
                $remaining = $thresholdRate > 0 ? ($decaySeconds - ($currentTime - $thresholdRate)) : $decaySeconds;
                return [true, max(1, $remaining)];
            }

            return [false, 0];
        } catch (Throwable $e) {
            // Fail-safe: Log error without exposing details and fallback to session-based throttling
            @error_log('[RateLimit DB Fallback] ' . $e->getMessage());
        }

        // 2. Fallback: Session-based in-memory throttling (used if database is unreachable)
        $attemptsKey = 'login_attempts_' . md5($identifier);
        $attempts = $_SESSION[$attemptsKey] ?? [];
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
     * Persists attempt to database to survive session clears, with automatic maintenance pruning.
     *
     * @param string $identifier Unique client or account key
     * @param string|null $ip Optional explicit client IP
     * @param string|null $username Optional target username
     */
    public static function recordFailedLogin(string $identifier, ?string $ip = null, ?string $username = null): void
    {
        self::start();
        $identifier = trim($identifier);
        $rateKey = hash('sha256', strtolower($identifier));

        // Auto-extract IP and username from identifier if not provided
        if ($ip === null && str_contains($identifier, '_')) {
            $parts = explode('_', $identifier, 2);
            if (filter_var($parts[0], FILTER_VALIDATE_IP)) {
                $ip = $parts[0];
                if ($username === null && !empty($parts[1])) {
                    $username = $parts[1];
                }
            }
        }
        $ip = $ip ?? self::getClientIp();
        $username = $username ? strtolower(trim($username)) : null;

        // 1. Record in Database
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO login_rate_limits (rate_key, ip_address, username, attempted_at)
                 VALUES (:rate_key, :ip_address, :username, NOW())'
            );
            $stmt->execute([
                ':rate_key'   => $rateKey,
                ':ip_address' => substr($ip, 0, 45),
                ':username'   => $username ? substr($username, 0, 60) : null,
            ]);

            // Periodic probabilistic pruning (5% of requests) for records older than 48 hours
            if (mt_rand(1, 20) === 1) {
                $pdo->query('DELETE FROM login_rate_limits WHERE attempted_at < NOW() - INTERVAL 2 DAY');
            }
        } catch (Throwable $e) {
            @error_log('[RateLimit DB Record Error] ' . $e->getMessage());
        }

        // 2. Also record in session as fallback cache
        $attemptsKey = 'login_attempts_' . md5($identifier);
        $attempts = $_SESSION[$attemptsKey] ?? [];
        $attempts[] = time();
        $_SESSION[$attemptsKey] = $attempts;
    }

    /**
     * Login Throttling Helper: Clear failed login attempts upon successful login.
     * Deletes persistent database records for the specific rate key and clears session state.
     *
     * @param string $identifier Unique client or account key
     */
    public static function clearFailedLogins(string $identifier): void
    {
        self::start();
        $identifier = trim($identifier);
        $rateKey = hash('sha256', strtolower($identifier));

        // 1. Clear from Database
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key = :rate_key');
            $stmt->execute([':rate_key' => $rateKey]);
        } catch (Throwable $e) {
            @error_log('[RateLimit DB Clear Error] ' . $e->getMessage());
        }

        // 2. Clear from Session
        $attemptsKey = 'login_attempts_' . md5($identifier);
        unset($_SESSION[$attemptsKey]);
    }

    /**
     * Sanitize redirect target to strictly internal relative paths, preventing open redirects.
     */


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
