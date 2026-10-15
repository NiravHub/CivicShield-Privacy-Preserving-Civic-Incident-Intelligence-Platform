<?php
declare(strict_types=1);

$pageTitle = 'Citizen Registration';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

// Redirect authenticated users to their corresponding workspace
if (Session::isLoggedIn()) {
    header('Location: ' . Session::getRoleHomeUrl(Session::getRole()));
    exit;
}

$errors = [];
$formUsername = '';
$formEmail = '';

// Handle Registration Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        $errors[] = 'Security token mismatch or expired session. Please refresh the page and try again.';
    }

    // 2. Retrieve and Sanitize Inputs
    $formUsername = trim((string)($_POST['username'] ?? ''));
    $formEmail    = trim((string)($_POST['email'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');
    $passwordConf = (string)($_POST['password_confirm'] ?? '');

    // 3. Username Validation
    if ($formUsername === '') {
        $errors[] = 'Username (pseudonym) is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $formUsername)) {
        $errors[] = 'Username must be between 3 and 30 characters and contain only letters, numbers, and underscores.';
    } else {
        $reserved = ['admin', 'root', 'officer', 'system', 'civicshield', 'moderator'];
        if (in_array(strtolower($formUsername), $reserved, true)) {
            $errors[] = 'This pseudonym is reserved. Please select a unique civic handle.';
        }
    }

    // 4. Optional Email Validation
    $emailNormalized = null;
    if ($formEmail !== '') {
        if (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address, or leave the field blank.';
        } elseif (strlen($formEmail) > 150) {
            $errors[] = 'Email address cannot exceed 150 characters.';
        } else {
            $emailNormalized = strtolower($formEmail);
        }
    }

    // 5. Password Validation
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (strlen($password) > 72) {
        // Bcrypt truncates after 72 bytes
        $errors[] = 'Password cannot exceed 72 characters.';
    }

    if ($password !== $passwordConf) {
        $errors[] = 'Password and password confirmation do not match.';
    }

    // 6. Database Uniqueness & Insertion
    if (empty($errors)) {
        try {
            $pdo = Database::getConnection();

            // Check username uniqueness
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $formUsername]);
            if ($stmt->fetch()) {
                $errors[] = 'The chosen pseudonym is already taken. Please choose another.';
            }

            // Check email uniqueness if provided
            if ($emailNormalized !== null && empty($errors)) {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $emailNormalized]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with this email address already exists.';
                }
            }

            // Insert new Citizen account
            if (empty($errors)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // STRICT ROLE ENFORCEMENT: Registration creates citizen accounts only!
                $assignedRole = 'citizen';
                $assignedStatus = 'active';

                $pdo->beginTransaction();

                $insertStmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, role, status, created_at) 
                     VALUES (:username, :email, :password_hash, :role, :status, NOW())'
                );

                $insertStmt->execute([
                    ':username'      => $formUsername,
                    ':email'         => $emailNormalized,
                    ':password_hash' => $passwordHash,
                    ':role'          => $assignedRole,
                    ':status'        => $assignedStatus
                ]);

                $newUserId = (int)$pdo->lastInsertId();
                $pdo->commit();

                // Authenticate newly registered citizen
                Session::loginUser([
                    'id'         => $newUserId,
                    'username'   => $formUsername,
                    'role'       => $assignedRole,
                    'department' => null
                ]);

                Session::setFlash('success', 'Registration successful! Welcome to your citizen workspace.');

                if (Response::isJsonRequest()) {
                    Response::created('Registration successful.', [
                        'redirect' => BASE_URL . '/citizen/'
                    ]);
                }

                header('Location: ' . BASE_URL . '/citizen/');
                exit;
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Log full exception internally, display user-friendly message
            @error_log('[Registration Error] ' . $e->getMessage());
            $errors[] = 'An error occurred during account creation. Please try again later.';
        }
    }

    if (Response::isJsonRequest() && !empty($errors)) {
        Response::unprocessable('Validation failed.', $errors);
    }
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card cs-card shadow-sm p-4 p-md-5 border-light-subtle">
                <div class="text-center mb-4">
                    <span class="cs-brand-icon mb-2" style="width:44px; height:44px; font-size:1.5rem;">
                        <i class="bi bi-person-badge-fill"></i>
                    </span>
                    <h3 class="fw-bold text-dark mt-2 mb-1">Create Citizen Identity</h3>
                    <p class="text-muted small">Choose an anonymous pseudonym to report issues without personal exposure</p>
                </div>

                <!-- Validation Errors Alert -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2 mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block mb-1">Please address the following:</strong>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/auth/register.php" id="registerForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">

                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold text-dark small">Anonymous Pseudonym / Handle <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-incognito"></i></span>
                            <input type="text" class="form-control border-start-0" id="username" name="username" 
                                   value="<?= htmlspecialchars($formUsername) ?>" 
                                   placeholder="e.g. adajan_watcher_99" required minlength="3" maxlength="30" autocomplete="username">
                        </div>
                        <div class="form-text small text-secondary">
                            <i class="bi bi-shield-check text-success me-1"></i>Use 3-30 letters, numbers, or underscores. Do not use your legal name.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold text-dark small">
                            Status Notification Email <span class="text-muted fw-normal">(Optional)</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control border-start-0" id="email" name="email" 
                                   value="<?= htmlspecialchars($formEmail) ?>" 
                                   placeholder="citizen@example.com (optional)" autocomplete="email">
                        </div>
                        <div class="form-text small text-secondary">
                            Used solely for private status alerts. Never published on public incident maps.
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="password" class="form-label fw-semibold text-dark small">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control border-end-0" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-password" data-target="password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text small text-secondary">Minimum 8 characters.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="password_confirm" class="form-label fw-semibold text-dark small">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control border-end-0" id="password_confirm" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-password" data-target="password_confirm" aria-label="Show confirm password" aria-pressed="false">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-cs-primary py-2 fw-semibold">
                            <i class="bi bi-person-check-fill me-1"></i> Register Pseudonymous Account
                        </button>
                    </div>
                </form>

                <div class="mt-4 pt-3 border-top border-light-subtle text-center small text-muted">
                    Already registered? 
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/auth/login.php" class="text-primary fw-semibold text-decoration-none">
                        Sign In Instead <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Whistleblower Protection Info -->
            <div class="d-flex align-items-center gap-2 p-3 mt-3 bg-white rounded-3 border border-light-subtle shadow-sm small text-muted">
                <i class="bi bi-lock-fill text-info fs-5 flex-shrink-0"></i>
                <div>
                    <strong>Pseudonym Security:</strong> Citizen registrations are isolated and cannot be assigned elevated officer or admin permissions.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
