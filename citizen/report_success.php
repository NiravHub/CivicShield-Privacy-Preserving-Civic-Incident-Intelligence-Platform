<?php
declare(strict_types=1);

$pageTitle = 'Report Submitted Successfully';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

requireAuth('citizen');

// Retrieve one-time report receipt from session
$reportInfo = $_SESSION['submitted_report'] ?? null;
if (!$reportInfo) {
    header('Location: ' . BASE_URL . '/citizen/');
    exit;
}

// Clear receipt from session so token cannot be re-retrieved from browser history
unset($_SESSION['submitted_report']);

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card cs-card shadow-sm p-4 p-md-5 border-success-subtle text-center">
                <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-3" style="width: 64px; height: 64px;">
                    <i class="bi bi-check-lg fs-1"></i>
                </div>

                <span class="cs-demo-tag mx-auto mb-2">Cryptographic Receipt Issued</span>
                <h2 class="fw-bold text-dark mb-1">Incident Report Submitted</h2>
                <p class="text-muted small mb-4">
                    Your report has been securely registered in the municipal intake ledger under triage review.
                </p>

                <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
                <style>
                    /* Suppress browser date-detector and smart-action outlines on report metadata values */
                    .cs-meta-box .cs-meta-value,
                    .cs-meta-box .cs-meta-value:focus,
                    .cs-meta-box .cs-meta-value:active,
                    .cs-meta-box [data-smart-action],
                    .cs-meta-box a[href*="calendar"],
                    .cs-meta-box a[href^="x-apple-data-detectors"] {
                        outline: none !important;
                        border: 0 !important;
                        box-shadow: none !important;
                        text-decoration: none !important;
                        color: inherit !important;
                    }
                </style>

                <!-- Report Metadata Summary -->
                <div class="p-3 bg-light rounded-3 border border-light-subtle text-start mb-3 small cs-meta-box">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <span class="text-muted d-block small mb-1">Public Report Reference</span>
                            <span class="fw-bold font-monospace text-primary text-break d-block">
                                <?= htmlspecialchars((string) $reportInfo['reference'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="col-12 col-md-6">
                            <span class="text-muted d-block small mb-1">Submission Time</span>
                            <span class="fw-semibold text-dark text-break d-block cs-meta-value"
                                style="outline: none !important; border: 0 !important; box-shadow: none !important; -webkit-tap-highlight-color: transparent;"
                                translate="no">
                                <?= htmlspecialchars((string) $reportInfo['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="col-12 pt-2 border-top">
                            <span class="text-muted d-block small mb-1">Incident Title</span>
                            <span class="fw-semibold text-dark text-break d-block">
                                <?= htmlspecialchars((string) $reportInfo['title'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- One-Time Private Tracking Token Box -->
                <div class="card border-warning border-opacity-75 bg-warning bg-opacity-10 p-4 text-start rounded-3 mb-4 shadow-xs">
                    <div class="d-flex align-items-center gap-2 text-warning-emphasis fw-bold mb-2">
                        <i class="bi bi-shield-lock-fill fs-5"></i>
                        <span>Your Private Single-Use Tracking Token</span>
                    </div>
                    <p class="small text-secondary mb-3">
                        <strong>Important Whistleblower Notice:</strong> This tracking token is displayed <strong>ONLY ONCE</strong>. The server stores only an irreversible SHA-256 hash. If lost, it cannot be recovered.
                    </p>

                    <div class="input-group input-group-lg">
                        <input type="text" id="privateTokenDisplay" class="form-control font-monospace text-dark bg-white fw-bold" 
                               value="<?= htmlspecialchars($reportInfo['raw_token']) ?>" readonly>
                        <button type="button" class="btn btn-warning px-4 fw-semibold text-dark" id="btnCopyToken">
                            <i class="bi bi-clipboard me-1"></i> <span id="copyBtnText">Copy Token</span>
                        </button>
                    </div>
                    <div id="copyAlert" class="small text-success fw-semibold mt-2 d-none">
                        <i class="bi bi-check-circle-fill me-1"></i> Token copied to clipboard! Save it in a safe place.
                    </div>
                </div>

                <!-- Navigation Action Buttons -->
                <div class="d-flex justify-content-center flex-wrap gap-2 pt-2">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/" class="btn btn-cs-primary">
                        <i class="bi bi-arrow-left me-1"></i> Return to Citizen Portal
                    </a>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/track.php?token=<?= urlencode($reportInfo['raw_token']) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-search me-1"></i> Test Token Tracking Now
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const copyBtn = document.getElementById('btnCopyToken');
    const tokenInput = document.getElementById('privateTokenDisplay');
    const copyAlert = document.getElementById('copyAlert');
    const copyBtnText = document.getElementById('copyBtnText');

    if (copyBtn && tokenInput) {
        copyBtn.addEventListener('click', function() {
            tokenInput.select();
            tokenInput.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(tokenInput.value).then(function() {
                copyBtnText.innerText = 'Copied!';
                copyAlert.classList.remove('d-none');
                setTimeout(() => {
                    copyBtnText.innerText = 'Copy Token';
                }, 3000);
            }).catch(function() {
                // Fallback for older browsers
                document.execCommand('copy');
                copyBtnText.innerText = 'Copied!';
                copyAlert.classList.remove('d-none');
            });
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>