<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
}

/**
 * Enforce server-side authentication and strict role-based access control (RBAC).
 *
 * @param array|string|null $allowedRoles Role or list of roles allowed to access.
 *                                        Null requires any authenticated user.
 */
function requireAuth(array|string|null $allowedRoles = null): void
{
    Session::start();

    // 1. Check if user is authenticated
    if (!Session::isLoggedIn()) {
        if (Response::isJsonRequest()) {
            Response::unauthorized('Authentication required to access this endpoint.');
        }

        // Store sanitized current URL as target redirect
        $targetUri = $_SERVER['REQUEST_URI'] ?? '';
        $safeRedirect = Session::sanitizeRedirect($targetUri, '/');

        Session::setFlash('error', 'Please sign in to access this area.');
        header('Location: ' . BASE_URL . '/auth/login.php?redirect=' . urlencode($safeRedirect));
        exit;
    }

    // 2. Check if specific roles are required
    if ($allowedRoles !== null) {
        $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        $currentRole = Session::getRole();

        if (!in_array($currentRole, $allowed, true)) {
            if (Response::isJsonRequest()) {
                Response::forbidden(sprintf(
                    'Access denied. Role "%s" is not permitted to access this resource.',
                    htmlspecialchars($currentRole)
                ));
            }

            http_response_code(403);
            $pageTitle = '403 Forbidden — Access Denied';
            require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
            ?>
            <div class="container py-5 text-center">
                <div class="card cs-card p-5 mx-auto shadow-sm" style="max-width: 650px;">
                    <div class="display-1 text-danger fw-bold mb-2">
                        <i class="bi bi-shield-slash-fill"></i>
                    </div>
                    <h2 class="fw-bold text-dark mb-2">403 — Access Denied</h2>
                    <p class="text-secondary mb-3">
                        Your account is signed in as <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($currentRole) ?></span>, but this workspace is strictly restricted to: 
                        <strong><?= htmlspecialchars(implode(', ', $allowed)) ?></strong>.
                    </p>
                    <div class="d-flex justify-content-center gap-2">
                        <a href="<?= htmlspecialchars(Session::getRoleHomeUrl($currentRole)) ?>" class="btn btn-cs-primary">
                            <i class="bi bi-arrow-left me-1"></i> Return to My Workspace
                        </a>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/" class="btn btn-outline-secondary">
                            <i class="bi bi-house-door me-1"></i> Home
                        </a>
                    </div>
                </div>
            </div>
            <?php
            require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php';
            exit;
        }
    }
}

/**
 * Enforce officer role access.
 */
function requireOfficer(): void
{
    requireAuth('officer');
}

/**
 * Enforce admin role access.
 */
function requireAdmin(): void
{
    requireAuth('admin');
}
