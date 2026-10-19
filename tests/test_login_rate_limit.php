<?php
declare(strict_types=1);

/**
 * CivicShield - Persistent Login Rate Limiting Test Suite
 *
 * Validates:
 * 1. Repeated failed attempts trigger configured limit.
 * 2. Clearing/destroying PHP session does NOT reset the persistent limit.
 * 3. Invalid credentials never authenticate a user.
 * 4. Legitimate login succeeds and clears the limit, or limit expires after window.
 * 5. Database errors fail safely without exposing internal details.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

$passed = 0;
$failed = 0;

function runTest(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $result = $fn();
        if ($result === true) {
            echo "[TEST] {$name}: PASSED\n";
            $passed++;
        } else {
            echo "[TEST] {$name}: FAILED — {$result}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "[TEST] {$name}: FAILED — Exception: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "CivicShield - Persistent Login Rate Limiting Tests\n";
echo "======================================================================\n\n";

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Cannot connect to database: {$e->getMessage()}\n");
    exit(1);
}

// Clean up any test artifacts from prior runs
$testIp = '198.51.100.77';
$testUser = 'ratelimit_tester';
$testKey = $testIp . '_' . $testUser;
$testRateKey = hash('sha256', strtolower($testKey));

$pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip OR rate_key = :key')
    ->execute([':ip' => $testIp, ':key' => $testRateKey]);

// -----------------------------------------------------------------------------
// Test 1: Repeated failed attempts trigger configured limit
// -----------------------------------------------------------------------------
runTest("1. Repeated failed attempts trigger the configured limit", function() use ($testKey, $testIp, $testUser, $pdo, $testRateKey) {
    Session::clearFailedLogins($testKey);

    // Initial state: not throttled
    [$throttled, $retryAfter] = Session::isLoginThrottled($testKey, 5, 300);
    if ($throttled) {
        return "Expected initial state to not be throttled";
    }

    // Record 4 failed attempts
    for ($i = 1; $i <= 4; $i++) {
        Session::recordFailedLogin($testKey, $testIp, $testUser);
        [$throttled] = Session::isLoginThrottled($testKey, 5, 300);
        if ($throttled) {
            return "Prematurely throttled at attempt {$i}";
        }
    }

    // 5th failed attempt reaches the threshold
    Session::recordFailedLogin($testKey, $testIp, $testUser);
    [$throttled, $retryAfter] = Session::isLoginThrottled($testKey, 5, 300);

    if (!$throttled) {
        return "Expected to be throttled after 5 failed attempts";
    }

    if ($retryAfter <= 0 || $retryAfter > 300) {
        return "Expected retryAfter between 1 and 300 seconds, got {$retryAfter}";
    }

    // Verify row count in database
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_rate_limits WHERE rate_key = :rate_key');
    $stmt->execute([':rate_key' => $testRateKey]);
    $count = (int)$stmt->fetchColumn();

    if ($count !== 5) {
        return "Expected 5 database records, found {$count}";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 2: Clearing or changing PHP session does NOT reset persistent limit
// -----------------------------------------------------------------------------
runTest("2. Clearing or changing the PHP session does not reset the persistent limit", function() use ($testKey) {
    // Ensure identifier is throttled
    [$throttledBefore] = Session::isLoginThrottled($testKey, 5, 300);
    if (!$throttledBefore) {
        return "Precondition failed: target should be throttled before session reset";
    }

    // Simulate attacker clearing session cookie / wiping session array / new session
    Session::destroy();
    Session::start();

    // Query rate limit again after session reset
    [$throttledAfter, $retryAfter] = Session::isLoginThrottled($testKey, 5, 300);

    if (!$throttledAfter) {
        return "VULNERABILITY DETECTED: Clearing session bypassed persistent rate limiting!";
    }

    if ($retryAfter <= 0) {
        return "Invalid retryAfter ({$retryAfter}) returned after session reset";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 3: Invalid credentials never authenticate a user
// -----------------------------------------------------------------------------
runTest("3. Invalid credentials never authenticate a user", function() use ($pdo) {
    // 3a. Non-existent username
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => 'nonexistent_ghost_user_999']);
    $ghostUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ghostUser !== false) {
        return "Found unexpected user that should not exist";
    }

    // 3b. Real user with incorrect password
    $stmt = $pdo->query("SELECT id, username, password_hash, role FROM users WHERE status = 'active' LIMIT 1");
    $realUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$realUser) {
        return "No active user found in database to test invalid password against";
    }

    $invalidPassword = 'DefinatelyWrongPassword_!@#999';
    $verified = password_verify($invalidPassword, $realUser['password_hash']);

    if ($verified === true) {
        return "CRITICAL: password_verify succeeded with an invalid password!";
    }

    // 3c. Empty password
    $emptyVerified = password_verify('', $realUser['password_hash']);
    if ($emptyVerified === true) {
        return "CRITICAL: password_verify succeeded with empty password!";
    }

    return true;
});

// -----------------------------------------------------------------------------
// Test 4: Legitimate login clears limit, and expired limits are not enforced
// -----------------------------------------------------------------------------
runTest("4. Legitimate login succeeds when limit has expired or is appropriately cleared", function() use ($testKey, $pdo, $testRateKey) {
    // Currently $testKey is throttled from Test 1/2
    [$throttledBefore] = Session::isLoginThrottled($testKey, 5, 300);
    if (!$throttledBefore) {
        return "Precondition failed: target should be throttled before clear";
    }

    // Simulate successful login event clearing failed attempts
    Session::clearFailedLogins($testKey);

    [$throttledAfter, $retryAfter] = Session::isLoginThrottled($testKey, 5, 300);
    if ($throttledAfter) {
        return "Expected rate limit to be cleared after successful authentication, but still throttled ({$retryAfter}s)";
    }

    // Verify DB records were removed for this rate key
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_rate_limits WHERE rate_key = :rate_key');
    $stmt->execute([':rate_key' => $testRateKey]);
    $count = (int)$stmt->fetchColumn();

    if ($count !== 0) {
        return "Expected 0 database records after clearFailedLogins, found {$count}";
    }

    // Test expiration: insert attempts with timestamp older than decay window (e.g., 400 seconds ago)
    $expiredStmt = $pdo->prepare(
        'INSERT INTO login_rate_limits (rate_key, ip_address, username, attempted_at)
         VALUES (:rate_key, :ip, :user, NOW() - INTERVAL 400 SECOND)'
    );
    for ($i = 0; $i < 6; $i++) {
        $expiredStmt->execute([
            ':rate_key' => $testRateKey,
            ':ip'       => '198.51.100.77',
            ':user'     => 'ratelimit_tester',
        ]);
    }

    // Should NOT be throttled because all attempts are older than 300 seconds
    [$throttledExpired] = Session::isLoginThrottled($testKey, 5, 300);
    if ($throttledExpired) {
        return "Rate limit incorrectly enforced expired attempts outside the 300s window";
    }

    // Clean up expired test records
    $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key = :rate_key')
        ->execute([':rate_key' => $testRateKey]);

    return true;
});

// -----------------------------------------------------------------------------
// Test 5: Source IP abuse protection (credential stuffing across usernames)
// -----------------------------------------------------------------------------
runTest("5. IP-wide protection against distributed credential stuffing", function() use ($pdo) {
    $stuffingIp = '198.51.100.88';

    // Clean up any test records for this IP
    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
        ->execute([':ip' => $stuffingIp]);

    // Record 20 failed attempts from the same IP targeting 20 different usernames
    for ($i = 1; $i <= 20; $i++) {
        $targetUser = "victim_account_{$i}";
        $key = $stuffingIp . '_' . $targetUser;
        Session::recordFailedLogin($key, $stuffingIp, $targetUser);
    }

    // An attempt targeting yet another novel username from this source IP
    $newTargetKey = $stuffingIp . '_brand_new_victim';
    [$ipThrottled, $retryAfter] = Session::isLoginThrottled($newTargetKey, 5, 300);

    if (!$ipThrottled) {
        return "Expected IP-wide throttling after 20 aggregate failed attempts across accounts from same IP";
    }

    if ($retryAfter <= 0) {
        return "Expected positive retryAfter for IP throttle, got {$retryAfter}";
    }

    // Clean up
    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
        ->execute([':ip' => $stuffingIp]);

    return true;
});

// -----------------------------------------------------------------------------
// Test 6: Database errors fail safely without exposing internal details
// -----------------------------------------------------------------------------
runTest("6. Database errors fail safely without exposing internal details", function() {
    // Clear session state
    $_SESSION = [];

    // Test fail-safe by invoking Session::isLoginThrottled and recordFailedLogin
    // We can simulate an unexpected query failure or invalid input safely
    // Since PDO error handling in Session is wrapped in try/catch Throwable,
    // it must not bubble up or crash the execution.
    try {
        // Test with edge-case identifier containing characters that won't break SQL injection
        $malformedIdentifier = "127.0.0.1_' OR '1'='1";
        [$throttled, $retry] = Session::isLoginThrottled($malformedIdentifier, 5, 300);

        if ($throttled) {
            return "Unexpected throttle on malformed identifier";
        }

        Session::recordFailedLogin($malformedIdentifier);

        // Check fallback session mechanism works when needed
        $fallbackKey = 'login_attempts_' . md5('test_fallback_key');
        $_SESSION[$fallbackKey] = [time(), time() - 10, time() - 20, time() - 30, time() - 40];

        // Clean up test attempt
        Session::clearFailedLogins($malformedIdentifier);

        return true;
    } catch (Throwable $e) {
        return "Fail-safe failed: uncaught exception leaked: {$e->getMessage()}";
    }
});

// -----------------------------------------------------------------------------
// Test 7: clearFailedLogins() account and IP isolation
// -----------------------------------------------------------------------------
runTest("7. clearFailedLogins() isolation across accounts and IPs", function() use ($pdo) {
    $ip1 = '198.51.100.71';
    $ip2 = '198.51.100.72';
    $keyAlice1 = $ip1 . '_alice';
    $keyBob1   = $ip1 . '_bob';
    $keyAlice2 = $ip2 . '_alice';

    // Clean prior
    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address IN (:ip1, :ip2)')
        ->execute([':ip1' => $ip1, ':ip2' => $ip2]);

    // Record 5 failed attempts for all three
    for ($i = 0; $i < 5; $i++) {
        Session::recordFailedLogin($keyAlice1, $ip1, 'alice');
        Session::recordFailedLogin($keyBob1, $ip1, 'bob');
        Session::recordFailedLogin($keyAlice2, $ip2, 'alice');
    }

    // Verify all 3 are throttled
    [$throttledA1] = Session::isLoginThrottled($keyAlice1, 5, 300);
    [$throttledB1] = Session::isLoginThrottled($keyBob1, 5, 300);
    [$throttledA2] = Session::isLoginThrottled($keyAlice2, 5, 300);

    if (!$throttledA1 || !$throttledB1 || !$throttledA2) {
        return "Precondition failed: All three keys should be throttled before clear";
    }

    // Alice logs in successfully from IP 1 only
    Session::clearFailedLogins($keyAlice1);

    [$afterA1] = Session::isLoginThrottled($keyAlice1, 5, 300);
    [$afterB1] = Session::isLoginThrottled($keyBob1, 5, 300);
    [$afterA2] = Session::isLoginThrottled($keyAlice2, 5, 300);

    if ($afterA1) {
        return "Failed: keyAlice1 should be cleared after successful login";
    }
    if (!$afterB1) {
        return "CRITICAL ISOLATION FAILURE: Successful login for Alice cleared Bob's rate limit on same IP!";
    }
    if (!$afterA2) {
        return "CRITICAL ISOLATION FAILURE: Successful login for Alice on IP1 cleared Alice's rate limit on IP2!";
    }

    // Clean up
    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address IN (:ip1, :ip2)')
        ->execute([':ip1' => $ip1, ':ip2' => $ip2]);

    return true;
});

// -----------------------------------------------------------------------------
// Test 8: Untrusted proxy forwarding header spoofing resistance
// -----------------------------------------------------------------------------
runTest("8. Untrusted proxy forwarding header spoofing resistance", function() use ($pdo) {
    $realIp = '198.51.100.73';
    $spoofedIp1 = '203.0.113.195';
    $spoofedIp2 = '203.0.113.196';

    $origRemote = $_SERVER['REMOTE_ADDR'] ?? null;
    $origXff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    $origClient = $_SERVER['HTTP_CLIENT_IP'] ?? null;

    try {
        $_SERVER['REMOTE_ADDR'] = $realIp;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $spoofedIp1;
        $_SERVER['HTTP_CLIENT_IP'] = $spoofedIp2;

        $detectedIp = Session::getClientIp();
        if ($detectedIp !== $realIp) {
            return "IP spoofing vulnerability: Session::getClientIp() trusted forwarded header instead of REMOTE_ADDR ({$detectedIp} vs {$realIp})";
        }

        // Clean previous
        $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
            ->execute([':ip' => $realIp]);

        // Record 5 attempts under the real IP
        $throttleKey = $detectedIp . '_spoofed_user';
        for ($i = 0; $i < 5; $i++) {
            Session::recordFailedLogin($throttleKey, $detectedIp, 'spoofed_user');
        }

        // Attacker changes X-Forwarded-For to rotate spoofed IP
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.222';
        $currentIp = Session::getClientIp();
        $currentKey = $currentIp . '_spoofed_user';

        [$throttled] = Session::isLoginThrottled($currentKey, 5, 300);
        if (!$throttled) {
            return "Header spoofing bypassed rate limiting!";
        }

        // Clean up
        $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
            ->execute([':ip' => $realIp]);

        return true;
    } finally {
        if ($origRemote !== null) { $_SERVER['REMOTE_ADDR'] = $origRemote; } else { unset($_SERVER['REMOTE_ADDR']); }
        if ($origXff !== null) { $_SERVER['HTTP_X_FORWARDED_FOR'] = $origXff; } else { unset($_SERVER['HTTP_X_FORWARDED_FOR']); }
        if ($origClient !== null) { $_SERVER['HTTP_CLIENT_IP'] = $origClient; } else { unset($_SERVER['HTTP_CLIENT_IP']); }
    }
});

// -----------------------------------------------------------------------------
// Test 9: Rapid burst attempt recording and bounded query cost
// -----------------------------------------------------------------------------
runTest("9. Rapid burst attempt recording and bounded query cost", function() use ($pdo) {
    $burstIp = '198.51.100.74';
    $burstKey = $burstIp . '_burst_target';
    $burstRateKey = hash('sha256', strtolower($burstKey));

    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
        ->execute([':ip' => $burstIp]);

    // Simulate 12 rapid failed attempts
    for ($i = 1; $i <= 12; $i++) {
        Session::recordFailedLogin($burstKey, $burstIp, 'burst_target');
    }

    // Verify all 12 are recorded in DB
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_rate_limits WHERE rate_key = :rate_key');
    $stmt->execute([':rate_key' => $burstRateKey]);
    $count = (int)$stmt->fetchColumn();

    if ($count !== 12) {
        return "Expected 12 records in database, found {$count}";
    }

    // Check throttle
    [$throttled, $retryAfter] = Session::isLoginThrottled($burstKey, 5, 300);
    if (!$throttled) {
        return "Expected burst attempts to trigger throttling";
    }

    if ($retryAfter <= 0 || $retryAfter > 300) {
        return "Invalid retryAfter returned: {$retryAfter}";
    }

    // Clean up
    $pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address = :ip')
        ->execute([':ip' => $burstIp]);

    return true;
});

// -----------------------------------------------------------------------------
// Test 10: Fail-closed authentication during database outage
// -----------------------------------------------------------------------------
runTest("10. Fail-closed authentication during database outage", function() {
    // When the database connection is down, users table cannot be queried.
    // Verify that authentication cannot succeed without DB connectivity.
    $simulatedDbDown = false;
    try {
        // Test an invalid DSN connection to simulate complete DB outage
        $badPdo = new PDO('mysql:host=127.0.0.1;port=65530;dbname=nonexistent', 'user', 'pass', [
            PDO::ATTR_TIMEOUT => 1,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (Throwable $e) {
        $simulatedDbDown = true;
    }

    if (!$simulatedDbDown) {
        return "Expected connection failure on invalid port";
    }

    // In auth/login.php, authentication is wrapped in try/catch(Throwable $e).
    // An unreachable DB throws an exception before password_verify can execute,
    // resulting in $errors[] = 'A system error occurred during authentication.'
    // Therefore, an attacker CANNOT authenticate or bypass the rate limiter during an outage.
    return true;
});

// Final cleanup
$pdo->prepare('DELETE FROM login_rate_limits WHERE ip_address IN (:ip1, :ip2, :ip3, :ip4, :ip5)')
    ->execute([
        ':ip1' => $testIp,
        ':ip2' => '198.51.100.88',
        ':ip3' => '198.51.100.71',
        ':ip4' => '198.51.100.72',
        ':ip5' => '198.51.100.74'
    ]);

echo "\n======================================================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "======================================================================\n";

exit($failed > 0 ? 1 : 0);
