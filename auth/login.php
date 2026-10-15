<?php
declare(strict_types=1);

$pageTitle = 'Sign In';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

// If already authenticated, redirect immediately to role workspace
if (Session::isLoggedIn()) {
    header('Location: ' . Session::getRoleHomeUrl(Session::getRole()));
    exit;
}

$errors = [];
$formUsername = '';
$redirectTarget = isset($_GET['redirect']) ? Session::sanitizeRedirect((string)$_GET['redirect']) : '';

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        $errors[] = 'Security token mismatch or expired session. Please refresh the page and try again.';
    }

    $formUsername = trim((string)($_POST['username'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');
    $redirectTarget = isset($_POST['redirect']) ? Session::sanitizeRedirect((string)$_POST['redirect']) : '';

    // 2. Client Throttling / Rate-Limiting Check
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $throttleKey = $clientIp . '_' . strtolower($formUsername);
    [$isThrottled, $retryAfter] = Session::isLoginThrottled($throttleKey, 5, 300);

    if ($isThrottled) {
        $errors[] = sprintf('Too many failed login attempts. Please wait %d seconds before trying again.', $retryAfter);
    } elseif ($formUsername === '' || $password === '') {
        $errors[] = 'Please enter both your username and password.';
    }

    // 3. Authenticate User Credentials
    if (empty($errors)) {
        try {
            $pdo = Database::getConnection();

            // Retrieve account by username or email
            $stmt = $pdo->prepare(
                'SELECT id, username, email, password_hash, role, department, status 
                 FROM users 
                 WHERE username = :username OR email = :email 
                 LIMIT 1'
            );
            $stmt->execute([
                ':username' => $formUsername,
                ':email'    => $formUsername,
            ]);
            $user = $stmt->fetch();

            // Generic failure response (prevent account enumeration)
            if (!$user || !password_verify($password, $user['password_hash'])) {
                Session::recordFailedLogin($throttleKey);
                $errors[] = 'Invalid username or password.';
            } else {
                // Check account eligibility status
                if ($user['status'] === 'suspended') {
                    $errors[] = 'This account has been suspended. Please contact municipal administration.';
                } elseif ($user['status'] === 'pending') {
                    $errors[] = 'This account is pending administrative verification.';
                } elseif ($user['status'] !== 'active') {
                    $errors[] = 'Account is currently inactive.';
                } else {
                    // Successful Authentication: Clear failed attempts
                    Session::clearFailedLogins($throttleKey);

                    // Rehash password if algorithm parameters improved
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $rehashStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                        $rehashStmt->execute([':hash' => $newHash, ':id' => $user['id']]);
                    }

                    // Authenticate session (regenerates session ID and CSRF token)
                    Session::loginUser($user);

                    // Determine destination: sanitize redirect target or role home
                    $destination = Session::getRoleHomeUrl($user['role']);
                    if (!empty($redirectTarget) && $redirectTarget !== '/') {
                        // Ensure the authenticated role is authorized for the redirect destination
                        if ($user['role'] === 'citizen' && str_starts_with($redirectTarget, BASE_URL . '/citizen/')) {
                            $destination = $redirectTarget;
                        } elseif ($user['role'] === 'officer' && str_starts_with($redirectTarget, BASE_URL . '/officer/')) {
                            $destination = $redirectTarget;
                        } elseif ($user['role'] === 'admin' && str_starts_with($redirectTarget, BASE_URL . '/admin/')) {
                            $destination = $redirectTarget;
                        }
                    }

                    if (Response::isJsonRequest()) {
                        Response::ok('Authentication successful.', [
                            'redirect' => $destination,
                            'role'     => $user['role']
                        ]);
                    }

                    header('Location: ' . $destination);
                    exit;
                }
            }
        } catch (Throwable $e) {
            @error_log('[Login Error] ' . $e->getMessage());
            $errors[] = 'A system error occurred during authentication. Please try again.';
        }
    }

    if (Response::isJsonRequest() && !empty($errors)) {
        Response::unauthorized(implode(' ', $errors));
    }
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card cs-card shadow-sm p-4 p-md-5 border-light-subtle">
                <div class="text-center mb-4">
                    <span class="cs-brand-icon mb-2" style="width:44px; height:44px; font-size:1.5rem;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </span>
                    <h3 class="fw-bold text-dark mt-2 mb-1">Sign In to CivicShield</h3>
                    <p class="text-muted small">Access your privacy-preserving workspace</p>
                </div>

                <!-- Error Messages Alert -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                        <div class="flex-grow-1 small">
                            <?php foreach ($errors as $error): ?>
                                <div><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div id="authFeedback" class="mb-3"></div>

                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/auth/login.php" id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">
                    <?php if (!empty($redirectTarget)): ?>
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold text-dark small">Username or Citizen Pseudonym</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control border-start-0" id="username" name="username" 
                                   value="<?= htmlspecialchars($formUsername) ?>" 
                                   placeholder="e.g. vesu_watcher or officer_patel" required autocomplete="username">
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="password" class="form-label fw-semibold text-dark small mb-0">Password</label>
                            <span class="text-muted small" style="font-size: 0.75rem;">Local Demo Key</span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control border-start-0 border-end-0" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary toggle-password" data-target="password" aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-cs-primary py-2 fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Workspace
                        </button>
                    </div>
                </form>

                <!-- Quick-Fill Demonstration Credentials -->
                <div class="mt-4 pt-3 border-top border-light-subtle">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-secondary">
                            <i class="bi bi-lightning-charge text-warning me-1"></i>Quick Demo Autofill:
                        </span>
                        <span class="cs-demo-tag" style="font-size:0.65rem;">Local Only</span>
                    </div>
                    <div class="d-grid gap-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm text-start btn-demo-fill d-flex justify-content-between align-items-center" data-username="admin_surat" data-password="AdminSurat@2026">
                            <span><i class="bi bi-speedometer2 text-danger me-1"></i><strong>Admin:</strong> admin_surat</span>
                            <span class="badge bg-light text-dark border">Fill</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm text-start btn-demo-fill d-flex justify-content-between align-items-center" data-username="officer_patel" data-password="OfficerPatel@2026">
                            <span><i class="bi bi-clipboard2-check text-info me-1"></i><strong>Officer:</strong> officer_patel</span>
                            <span class="badge bg-light text-dark border">Fill</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm text-start btn-demo-fill d-flex justify-content-between align-items-center" data-username="vesu_watcher" data-password="VesuCivic@2026">
                            <span><i class="bi bi-person-workspace text-primary me-1"></i><strong>Citizen:</strong> vesu_watcher</span>
                            <span class="badge bg-light text-dark border">Fill</span>
                        </button>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top border-light-subtle text-center small text-muted">
                    New citizen whistleblower? 
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/auth/register.php" class="text-primary fw-semibold text-decoration-none">
                        Register with Pseudonym <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Privacy Guarantee Badge -->
            <div class="d-flex align-items-center gap-2 p-3 mt-3 bg-white rounded-3 border border-light-subtle shadow-sm small text-muted">
                <i class="bi bi-shield-check text-success fs-5 flex-shrink-0"></i>
                <div>
                    <strong>Zero-Doxxing Guarantee:</strong> Your client IP is never stored in plain text. Session tokens use strict HTTP-only cookies.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
